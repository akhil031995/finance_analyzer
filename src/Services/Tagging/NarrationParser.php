<?php

declare(strict_types=1);

namespace App\Services\Tagging;

/**
 * Pulls structure out of a bank narration: the channel, who was on the other
 * side, their UPI handle, and (on Federal) the merchant category code.
 *
 * Each bank stamps UPI transactions with its own grammar:
 *   HDFC     UPI-<NAME>-<VPA>-<IFSC>-<REF>-<REMARK>
 *   Kotak    UPI/<NAME>/<BANK>/<REF>/<REMARK>
 *   Federal  UPIOUT|UPI IN/<REF>/<VPA>/<REMARK>/<MCC>
 *
 * Extraction is best-effort and only feeds `counterparty` (a display field) and
 * self-transfer detection. Category rules deliberately match against the whole
 * narration instead, so a bank inventing a fourth grammar degrades the merchant
 * name but never the tagging.
 */
final class NarrationParser
{
    /** Ordered: the first pattern that matches the start of the narration wins. */
    private const MODE_PATTERNS = [
        '/^UPI\s?(OUT|IN)?[\/\-]/i'                       => 'UPI',
        '/^UPIRET/i'                                      => 'UPI',
        '/^(NEFT|NFT)[\s\-\/]/i'                          => 'NEFT',
        '/^RTGS[\s\-\/]/i'                                => 'RTGS',
        '/^IMPS[\s\-\/]/i'                                => 'IMPS',
        '/^(ATW|NWD|EAW|ATM)[\s\-\/]/i'                   => 'ATM',
        '/^(POS|EDC)[\s\-\/]/i'                           => 'POS',
        '/^(ACH\s?[DC]|NACH|ECS|SI\s)/i'                  => 'NACH_ECS',
        '/^IB\s?BILLPAY/i'                                => 'NETBANKING',
        '/^(CHQ|CHEQUE|CTS)[\s\-\/]/i'                    => 'CHEQUE',
        '/^(INT\.PD|INTEREST\s+PAID|SBINT|CREDIT\s+INTEREST)/i' => 'INTEREST',
        '/^(CHRG|CHARGES|FEE)/i'                          => 'CHARGES_FEES',
        '/^(CASH|CSH)[\s\-\/]/i'                          => 'CASH',
    ];

    /**
     * @return array{mode:string, counterparty:?string, vpa:?string, mcc:?string}
     */
    public static function parse(string $narration): array
    {
        $text          = trim($narration);
        [$party, $vpa] = self::party($text);

        return [
            'mode'         => self::mode($text),
            'counterparty' => $party,
            // Prefer the handle at its known position in the bank's grammar.
            // The regex fallback stops at the first character outside its class,
            // so an OCR'd "akhiló169@okhdfcbank" would yield "169@okhdfcbank" —
            // unrecognisable as the account owner.
            'vpa'          => $vpa ?? self::vpa($text),
            'mcc'          => self::mcc($text),
        ];
    }

    public static function mode(string $text): string
    {
        foreach (self::MODE_PATTERNS as $pattern => $mode) {
            if (preg_match($pattern, $text) === 1) {
                return $mode;
            }
        }

        // Interest and charges are often written mid-narration, not as a prefix.
        if (preg_match('/\bINTEREST\b/i', $text) === 1) {
            return 'INTEREST';
        }
        if (preg_match('/\b(CHARGES?|AMC|GST|PENALTY)\b/i', $text) === 1) {
            return 'CHARGES_FEES';
        }

        return 'OTHER';
    }

    /**
     * The UPI handle, if the narration carries one. Unicode-tolerant: OCR turns
     * "akhil6" into "akhiló", and an ASCII-only class would silently truncate
     * the handle to everything after the corrupted character.
     */
    public static function vpa(string $text): ?string
    {
        return preg_match('/[\p{L}\p{N}._\- ]{2,}@[\p{L}\p{N}.\-]{2,}/u', $text, $m) === 1 ? trim($m[0]) : null;
    }

    /**
     * Federal appends the 4-digit merchant category code as the last path
     * segment: ".../ Pay-/5411". A bare 4-digit tail elsewhere is not an MCC,
     * so require the UPI prefix.
     */
    public static function mcc(string $text): ?string
    {
        if (preg_match('/^UPI\s?(OUT|IN)?\//i', $text) !== 1) {
            return null;
        }

        return preg_match('#/(\d{4})\s*$#', $text, $m) === 1 ? $m[1] : null;
    }

    public static function counterparty(string $text): ?string
    {
        return self::party($text)[0];
    }

    /**
     * The narration with bank routing codes blanked out, for merchant matching.
     *
     * An IFSC is never a merchant name, but it looks like one to a substring
     * match: the rule "JIO" happily matches "JIOP0000001" (Jio Payments Bank),
     * which filed four transfers to the owner's own Axis handle as telecom
     * bills. Reference numbers and masked card digits are deliberately LEFT in
     * place — the credit-card rules match on "...XXXXXX1619".
     */
    public static function merchantText(string $text): string
    {
        return trim((string) preg_replace(
            ['/\b[A-Z]{4}0[A-Z0-9]{6}\b/i', '/\s+/'],
            [' ', ' '],
            $text
        ));
    }

    /**
     * Resolve the other side of the transaction from the bank's own grammar.
     *
     * @return array{0:?string, 1:?string} [counterparty, vpa]
     */
    private static function party(string $text): array
    {
        // --- Federal: UPIOUT|UPI IN /<ref>/<vpa>/<remark>/<mcc> ---------------
        if (preg_match('#^UPI\s?(OUT|IN)\s*/#i', $text) === 1) {
            $parts = explode('/', $text);
            $vpa   = trim($parts[2] ?? '');
            if (str_contains($vpa, '@')) {
                return [self::tidy(explode('@', $vpa)[0]), $vpa];
            }

            return [self::tidy($vpa), null];
        }

        // --- Kotak: UPI/<name>/<bank>/<ref>/<remark> --------------------------
        if (preg_match('#^UPI/#i', $text) === 1) {
            $parts = explode('/', $text);

            return [self::tidy($parts[1] ?? ''), null];
        }

        // --- HDFC: UPI-<name>-<vpa>-<ifsc>-<ref>-<remark> ---------------------
        // The name is field 1. Do NOT define it as "everything before the part
        // containing @": VPA local parts may themselves contain a hyphen
        // (varsha199528-1@okaxis, akhil6169-3@okaxis), which would fold the
        // VPA's first fragment into the name and split one person into two
        // counterparties. Conversely the VPA spans every field from 2 up to and
        // including the one carrying the @.
        if (preg_match('/^UPI(RET)?-/i', $text) === 1) {
            $parts = explode('-', $text);
            array_shift($parts);
            if ($parts === []) {
                return [null, null];
            }

            $at = null;
            foreach ($parts as $i => $part) {
                if (str_contains($part, '@')) {
                    $at = $i;
                    break;
                }
            }

            if ($at === null) {
                return [self::tidy($parts[0]), null];
            }
            if ($at === 0) {
                // No name field: "UPI-someone@ybl-..."
                return [self::tidy(explode('@', $parts[0])[0]), trim($parts[0])];
            }

            $vpa = implode('-', array_slice($parts, 1, $at));

            return [self::tidy($parts[0]), trim($vpa)];
        }

        // --- Anything else: drop the channel prefix and any masked card number,
        // then keep the first segment. "POS 416021XXXXXX9721 IOCL RAJLAXMI PE"
        // must yield "IOCL RAJLAXMI PE", not the card.
        $stripped = (string) preg_replace(
            '/^(?:NEFT|RTGS|IMPS|ACH\s?[DC]|IB\s?BILLPAY|POS|EDC|CC|ATW|NWD|EAW|CHQ|CHRG)\b[\s\-\/:]*(?:DR|CR)?[\s\-\/:]*/i',
            '',
            $text
        );
        $stripped = (string) preg_replace('/^[0-9X]{6,}\s*/i', '', $stripped);
        $first    = preg_split('/[\-\/:]/', $stripped)[0] ?? '';

        return [self::tidy($first), null];
    }

    /** Collapse whitespace; reject fragments too short or too numeric to be a name. */
    private static function tidy(string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 3) {
            return null;
        }
        if (preg_match('/[A-Za-z]/', $name) !== 1) {
            return null;   // a bare reference number
        }

        return mb_substr($name, 0, 80);
    }
}
