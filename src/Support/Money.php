<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amount parsing for bank CSVs, which are wildly inconsistent about how they
 * render a number. Everything is converted to PAISE (integer) so no
 * floating-point drift ever reaches the ledger.
 *
 * Handles: "1,23,456.78" (Indian grouping), "₹ 900", "Rs.900.00", "900 Dr",
 * "(1234.00)" (parenthesised negative), "-900", "84481.57", "34756.6".
 *
 * Distinguishes ABSENT from ZERO: HDFC and Federal write `0` in the unused
 * amount column while Kotak leaves it blank. parse() returns null for absent,
 * 0 for a literal zero — the caller needs that difference to decide which of
 * the debit/credit pair carries the transaction.
 */
final class Money
{
    /** Values that mean "this cell is empty", beyond the empty string itself. */
    private const BLANKS = ['-', '--', 'NA', 'N/A', 'NIL', 'NULL'];

    /**
     * @return int|null paise, or null when the cell holds no number at all.
     *                  Sign is preserved: "(500)" and "-500" both give -50000.
     */
    public static function parse(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        // Normalise unicode spaces (OCR'd statements are full of them) first,
        // otherwise trim() leaves a non-breaking space behind and the cell
        // looks non-empty.
        $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"], ' ', $raw);
        $s = trim($s);
        if ($s === '' || in_array(strtoupper($s), self::BLANKS, true)) {
            return null;
        }

        $negative = false;

        // (1,234.00) — accounting style negative
        if (preg_match('/^\((.*)\)$/', $s, $m) === 1) {
            $negative = true;
            $s = $m[1];
        }

        // Trailing/leading Dr|Cr suffix on the amount itself. NOTE: a separate
        // Dr/Cr *column* usually describes the BALANCE, not the transaction —
        // that is handled (and warned about) in ColumnMapping, not here.
        if (preg_match('/^(.*?)\s*(DR|CR|D|C)\.?$/i', $s, $m) === 1 && preg_match('/\d/', $m[1]) === 1) {
            if (strtoupper($m[2][0]) === 'D') {
                $negative = true;
            }
            $s = $m[1];
        }

        // Currency symbols and grouping separators.
        $s = preg_replace('/(?:₹|\bRs\.?|\bINR\b|\$)/iu', '', $s) ?? $s;
        $s = str_replace([',', ' ', "'"], '', $s);

        if ($s === '' || preg_match('/^([+-]?)(\d*)(?:\.(\d+))?$/', $s, $m) !== 1) {
            return null;
        }
        if ($m[2] === '' && ($m[3] ?? '') === '') {
            return null;   // a lone "+", "-" or "."
        }

        if ($m[1] === '-') {
            $negative = !$negative;
        }

        // Build paise from the digit strings rather than (int) round($f * 100):
        // 84481.57 * 100 is 8448156.9999... in binary floating point.
        $rupees = $m[2] === '' ? '0' : $m[2];
        $frac   = $m[3] ?? '';
        $paise  = (int) $rupees * 100;

        if ($frac !== '') {
            $twoDp = substr(str_pad($frac, 2, '0'), 0, 2);
            $paise += (int) $twoDp;
            // Round the third decimal onwards (some banks print 3dp interest).
            if (strlen($frac) > 2 && (int) $frac[2] >= 5) {
                $paise++;
            }
        }

        return $negative ? -$paise : $paise;
    }

    /** paise -> "1234.56", for CSV export and debug output. */
    public static function toDecimal(int $paise): string
    {
        $sign  = $paise < 0 ? '-' : '';
        $paise = abs($paise);

        return $sign . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
