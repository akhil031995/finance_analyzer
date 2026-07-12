<?php

declare(strict_types=1);

namespace App\Services\Csv;

use App\Support\DateFormatGuesser;
use App\Support\Money;

/**
 * Proposes a ColumnMapping for a file we have never seen.
 *
 * Two passes:
 *   1. HEADER NAMES via HeaderSynonyms — covers essentially every mainstream
 *      Indian bank export on the first try.
 *   2. VALUE SNIFFING for whatever pass 1 missed: which column parses as a
 *      date, which parse as money, which holds the longest free text.
 *
 * The result is a suggestion, never an action. The user confirms it on the
 * mapping screen, and only then is anything parsed or persisted.
 */
final class MappingSuggester
{
    /** A column is "of type X" when at least this share of sampled cells parse. */
    private const TYPE_THRESHOLD = 0.8;

    private const SAMPLE_ROWS = 100;

    /**
     * @return array{mapping:array, notes:list<string>, date_guess:array}
     */
    public static function suggest(CsvFile $csv): array
    {
        $headers = $csv->headers();
        $rows    = $csv->dataRows(self::SAMPLE_ROWS);
        $notes   = [];

        $byRole  = self::rolesFromHeaders($headers);
        $numeric = [];
        $dateish = [];
        $textLen = [];

        foreach ($headers as $i => $header) {
            $values = array_map(static fn ($r) => (string) ($r[$i] ?? ''), $rows);
            $filled = array_values(array_filter($values, static fn ($v) => trim($v) !== ''));
            if ($filled === []) {
                continue;
            }

            $moneyOk = count(array_filter($filled, static fn ($v) => Money::parse($v) !== null));
            if ($moneyOk / count($filled) >= self::TYPE_THRESHOLD) {
                $numeric[$header] = $filled;
            }

            $guess = DateFormatGuesser::guess($filled);
            if ($guess['format'] !== null && $guess['total'] > 0
                && $guess['matched'] / $guess['total'] >= self::TYPE_THRESHOLD) {
                $dateish[$header] = $guess;
            }

            $textLen[$header] = array_sum(array_map('strlen', $filled)) / count($filled);
        }

        // A pure-digit column parses as both money and a date under some
        // formats; money wins, otherwise "Tran id" gets proposed as the date.
        $dateish = array_diff_key($dateish, $numeric);

        $mapping = ColumnMapping::defaults();

        // --- date -------------------------------------------------------
        $dateCol = $byRole['date'] ?? array_key_first($dateish);
        if ($dateCol !== null && !isset($dateish[$dateCol])) {
            // Header said "date" but the values disagree — trust the values.
            $dateCol = array_key_first($dateish) ?? $dateCol;
        }
        $dateGuess = ['format' => null, 'ambiguous' => false, 'alternative' => null, 'matched' => 0, 'total' => 0, 'failures' => []];
        if ($dateCol !== null) {
            $dateGuess = $dateish[$dateCol] ?? DateFormatGuesser::guess($csv->column($dateCol));
            $mapping['date'] = ['column' => $dateCol, 'format' => $dateGuess['format']];
            if ($dateGuess['ambiguous']) {
                $notes[] = "Every day in \"{$dateCol}\" is 12 or less, so {$dateGuess['format']} and "
                    . "{$dateGuess['alternative']} both fit. Confirm the date format before importing.";
            }
        } else {
            $notes[] = 'No column looks like a date — pick one manually.';
        }

        // --- description ------------------------------------------------
        $descCol = $byRole['description'] ?? null;
        if ($descCol === null) {
            // Longest average free text that isn't the date or a number.
            $candidates = array_diff_key($textLen, $numeric, $dateish);
            arsort($candidates);
            $descCol = array_key_first($candidates);
        }
        if ($descCol !== null) {
            $mapping['description'] = ['columns' => [$descCol]];
        } else {
            $notes[] = 'No column looks like a description — pick one manually.';
        }

        // --- balance ----------------------------------------------------
        // Prefer the header; else the numeric column with the widest spread,
        // which a running balance always has relative to per-txn amounts.
        $balanceCol = $byRole['balance'] ?? null;
        if ($balanceCol === null && $numeric !== []) {
            $spread = [];
            foreach ($numeric as $header => $values) {
                $paise = array_map(static fn ($v) => Money::parse($v) ?? 0, $values);
                $spread[$header] = count(array_unique($paise));
            }
            arsort($spread);
            $balanceCol = array_key_first($spread);
        }

        // --- amount -----------------------------------------------------
        $debitCol  = $byRole['debit'] ?? null;
        $creditCol = $byRole['credit'] ?? null;
        $amountCol = $byRole['amount'] ?? null;

        if ($debitCol !== null && $creditCol !== null) {
            $mapping['amount']['mode']   = 'debit_credit';
            $mapping['amount']['debit']  = $debitCol;
            $mapping['amount']['credit'] = $creditCol;
        } elseif ($amountCol !== null) {
            $signed = self::hasNegatives($numeric[$amountCol] ?? []);
            $mapping['amount']['mode']   = $signed ? 'signed' : 'indicator';
            $mapping['amount']['amount'] = $amountCol;
            if (!$signed) {
                $mapping['amount']['indicator'] = $byRole['indicator'] ?? null;
                $notes[] = 'This file has a single amount column with no minus signs, so the direction '
                    . 'must come from a Dr/Cr column. Check that the column you pick changes per '
                    . 'transaction — on many statements the trailing Dr/Cr describes the BALANCE and '
                    . 'reads "CR" on every row, including withdrawals.';
            }
        } else {
            // No headers matched: fall back to the two numeric columns that
            // most often hold zero/blank (only one side of a txn is ever filled).
            $sparse = [];
            foreach ($numeric as $header => $values) {
                if ($header === $balanceCol) {
                    continue;
                }
                $zeros = count(array_filter($values, static fn ($v) => (Money::parse($v) ?? 0) === 0));
                $sparse[$header] = $zeros / count($values);
            }
            arsort($sparse);
            $pair = array_slice(array_keys($sparse), 0, 2);
            if (count($pair) === 2) {
                sort($pair);   // debit conventionally precedes credit
                $mapping['amount']['mode']   = 'debit_credit';
                $mapping['amount']['debit']  = $pair[0];
                $mapping['amount']['credit'] = $pair[1];
                $notes[] = 'Guessed the debit and credit columns from their values — double-check them.';
            } else {
                $notes[] = 'Could not identify the amount columns — set them manually.';
            }
        }

        if ($balanceCol !== null
            && !in_array($balanceCol, [$mapping['amount']['debit'], $mapping['amount']['credit'], $mapping['amount']['amount']], true)) {
            $mapping['balance'] = ['column' => $balanceCol];
        } else {
            $notes[] = 'No running-balance column found. Import still works, but the balance '
                . 'continuity check (which catches dropped or misparsed rows) will be skipped.';
        }

        // --- reference ----------------------------------------------------
        if (isset($byRole['reference'])) {
            $mapping['reference'] = ['column' => $byRole['reference']];
        }

        $mapping['header_row'] = $csv->headerRowIndex();

        // --- skip rules ---------------------------------------------------
        // Opening/closing-balance rows carry an amount and would otherwise be
        // staged as a ₹5,267 deposit. Federal writes "Opening Balance"/OPNBAL.
        if ($descCol !== null) {
            $mapping['skip_rows'] = [
                ['column' => $descCol, 'regex' => '^(opening|closing)\\s*balance'],
                ['column' => $descCol, 'regex' => '^(b/f|c/f|brought forward|carried forward)'],
            ];
        }

        // --- OCR cleanup ---------------------------------------------------
        if ($descCol !== null && self::looksOcrd($csv->column($descCol, self::SAMPLE_ROWS))) {
            $mapping['clean_ocr'] = true;
            $notes[] = 'Descriptions contain line-wrap artifacts ("akhil6- 169@bank"), typical of a CSV '
                . 'converted from a PDF. Enabled cleanup so merchant matching still works.';
        }

        return ['mapping' => $mapping, 'notes' => $notes, 'date_guess' => $dateGuess];
    }

    /**
     * First header claiming each role wins. Guards against a "Value Date"
     * column stealing the `date` role from "Transaction Date".
     *
     * @param list<string> $headers
     * @return array<string,string> role => header name
     */
    private static function rolesFromHeaders(array $headers): array
    {
        $byRole = [];
        foreach ($headers as $header) {
            $role = HeaderSynonyms::roleFor($header);
            if ($role !== null && !isset($byRole[$role])) {
                $byRole[$role] = $header;
            }
        }

        // Transaction date beats value date; if only a value date exists, use it.
        if (!isset($byRole['date']) && isset($byRole['value_date'])) {
            $byRole['date'] = $byRole['value_date'];
        }

        return $byRole;
    }

    /** @param list<string> $values */
    private static function hasNegatives(array $values): bool
    {
        foreach ($values as $v) {
            if ((Money::parse($v) ?? 0) < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * PDF-to-CSV conversions break long tokens across lines and leave a
     * "hyphen + space" scar mid-word: "akhil6- 169@okhdfcbank".
     *
     * Deliberately case-SENSITIVE and restricted to lowercase/digits. Real
     * scars land inside lowercase VPAs and digit runs, whereas HDFC's genuine
     * "ACH D- HDFCLTD" prefix is all caps — matching that would enable OCR
     * cleanup on a file that has no OCR damage at all.
     *
     * @param list<string> $values
     */
    private static function looksOcrd(array $values): bool
    {
        $hits = 0;
        foreach ($values as $v) {
            if (preg_match('/[a-z0-9]-\s+[a-z0-9]/', $v) === 1) {
                $hits++;
            }
        }

        return $values !== [] && $hits / count($values) > 0.02;
    }
}
