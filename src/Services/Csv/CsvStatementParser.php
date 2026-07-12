<?php

declare(strict_types=1);

namespace App\Services\Csv;

use App\Support\DateFormatGuesser;
use App\Support\Money;

/**
 * Applies a ColumnMapping to a CsvFile and produces normalized transactions
 * plus a report the UI shows BEFORE anything is written to the database.
 *
 * The report is the safety net for a user-defined mapping. It carries:
 *   - per-row errors and the reason each row was skipped
 *   - the detected row order (Kotak exports newest-first)
 *   - a balance-continuity check: balance[i] == balance[i-1] ± amount[i].
 *     This is free (banks always print a running balance) and catches a wrong
 *     column pick, a misparsed date format, a dropped row, and an inverted
 *     debit/credit assignment — all before they reach the ledger.
 */
final class CsvStatementParser
{
    /**
     * @param bool|null $isLiability the target account's kind, which fixes the
     *        direction the running balance must move in. Null when no account
     *        has been chosen yet — the balance check then cannot detect an
     *        inverted debit/credit mapping and says so.
     * @return array{rows: list<array<string,mixed>>, report: array<string,mixed>}
     */
    public function parse(CsvFile $csv, ColumnMapping $map, ?bool $isLiability = null): array
    {
        $headers  = $csv->headers();
        $width    = count($headers);
        $index    = array_flip($headers);
        $descCols = $map->descriptionColumns();

        $rows       = [];
        $errors     = [];
        $skipped    = [];
        $repaired   = 0;
        $openingHint = null;

        foreach ($csv->dataRows() as $i => $raw) {
            // +2: the header row is 1-based on screen, and data starts after it.
            $line = $csv->headerRowIndex() + $i + 2;

            if (count($raw) > $width) {
                $fixed = $this->repairOverWideRow($raw, $width, $index, $descCols, $csv->delimiter());
                if ($fixed === null) {
                    $errors[] = ['line' => $line, 'reason' => 'Row has ' . count($raw) . ' fields but the header has '
                        . $width . '. An unquoted "' . $csv->delimiter() . '" inside a multi-column description '
                        . 'cannot be repaired automatically.'];
                    continue;
                }
                $raw = $fixed;
                $repaired++;
            }

            $cell = static fn (?string $col): string => $col === null ? '' : (string) ($raw[$index[$col]] ?? '');

            // --- skip rules (opening-balance rows, subtotals, footers) ------
            $skip = false;
            foreach ($map->skipRows() as $rule) {
                if (preg_match('/' . str_replace('/', '\/', $rule['regex']) . '/i', $cell($rule['column'])) === 1) {
                    $balance = Money::parse($cell($map->balanceColumn()));
                    if ($openingHint === null && $balance !== null
                        && preg_match('/opening|b\/f|brought/i', $cell($rule['column'])) === 1) {
                        $openingHint = $balance;
                    }
                    $skipped[] = ['line' => $line, 'reason' => 'matched skip rule', 'text' => $cell($rule['column'])];
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // --- date -------------------------------------------------------
            $date = DateFormatGuesser::parse($cell($map->dateColumn()), $map->dateFormat());
            if ($date === null) {
                $errors[] = ['line' => $line, 'reason' => 'Date "' . $cell($map->dateColumn())
                    . '" does not match the format ' . $map->dateFormat()];
                continue;
            }

            // --- amount + direction -----------------------------------------
            $amount = $this->resolveAmount($map, $cell);
            if (isset($amount['error'])) {
                $errors[] = ['line' => $line, 'reason' => $amount['error']];
                continue;
            }
            if ($amount['amount'] === 0) {
                $skipped[] = ['line' => $line, 'reason' => 'zero amount', 'text' => $this->describe($cell, $descCols)];
                continue;
            }

            // --- text --------------------------------------------------------
            $rawDesc = $this->describe($cell, $descCols);
            if ($rawDesc === '') {
                $errors[] = ['line' => $line, 'reason' => 'Empty description'];
                continue;
            }

            $reference = trim($cell($map->referenceColumn()));
            // "0" is HDFC's placeholder for "no reference"; it is not a value.
            if ($reference === '0' || $reference === '') {
                $reference = null;
            }

            $rows[] = [
                'line'            => $line,
                'txn_date'        => $date,
                'raw_description' => $rawDesc,
                'description'     => $this->clean($rawDesc, $map->cleanOcr()),
                'amount'          => $amount['amount'],       // paise, always positive
                'cashflow'        => $amount['cashflow'],
                'balance_after'   => Money::parse($cell($map->balanceColumn())),
                'reference_id'    => $reference,
            ];
        }

        return [
            'rows'   => $rows,
            'report' => $this->report($rows, $errors, $skipped, $repaired, $openingHint, $map, $isLiability),
        ];
    }

    // ---------------------------------------------------------------- private

    /**
     * @param callable(?string):string $cell
     * @return array{amount?:int, cashflow?:string, error?:string}
     */
    private function resolveAmount(ColumnMapping $map, callable $cell): array
    {
        if ($map->amountMode() === 'debit_credit') {
            // null (blank cell, Kotak) and 0 (explicit zero, HDFC/Federal) both
            // mean "this side is unused"; exactly one side should carry a value.
            $debitRaw  = $cell($map->debitColumn());
            $creditRaw = $cell($map->creditColumn());

            // A REVERSAL is printed as a negative amount in its original column:
            // HDFC writes "-695.56" under Withdrawal when it gives a debit back.
            // It is a credit, and reading it as a debit gets the balance wrong by
            // twice the amount.
            //
            // Only an explicit leading '-' or '(' means that. Money::parse also
            // returns a negative for a "900 Dr" suffix, which is an ordinary
            // debit, so the sign alone cannot be trusted here.
            $reversal = static fn (?string $raw): bool =>
                $raw !== null && preg_match('/^\s*[-(]/', $raw) === 1;

            $debit  = Money::parse($debitRaw) ?? 0;
            $credit = Money::parse($creditRaw) ?? 0;
            if (!$reversal($debitRaw)) {
                $debit = abs($debit);
            }
            if (!$reversal($creditRaw)) {
                $credit = abs($credit);
            }

            if ($debit !== 0 && $credit !== 0) {
                return ['error' => "Both debit ({$debit}p) and credit ({$credit}p) are non-zero — "
                    . 'the debit/credit columns may be mapped to the wrong fields'];
            }
            if ($debit !== 0) {
                return $debit > 0
                    ? ['amount' => $debit, 'cashflow' => 'debit']
                    : ['amount' => -$debit, 'cashflow' => 'credit'];
            }
            if ($credit !== 0) {
                return $credit > 0
                    ? ['amount' => $credit, 'cashflow' => 'credit']
                    : ['amount' => -$credit, 'cashflow' => 'debit'];
            }

            return ['amount' => 0, 'cashflow' => 'credit'];
        }

        if ($map->amountMode() === 'signed') {
            $value = Money::parse($cell($map->amountColumn()));
            if ($value === null) {
                return ['amount' => 0, 'cashflow' => 'debit'];   // blank => skipped as zero
            }

            return ['amount' => abs($value), 'cashflow' => $value < 0 ? 'debit' : 'credit'];
        }

        // indicator
        $value = Money::parse($cell($map->amountColumn()));
        if ($value === null) {
            return ['amount' => 0, 'cashflow' => 'debit'];
        }
        $flag = strtoupper(trim($cell($map->indicatorColumn())));
        if ($flag === '') {
            return ['error' => 'Dr/Cr indicator column is empty'];
        }

        return [
            'amount'   => abs($value),
            'cashflow' => in_array($flag, $map->debitValues(), true) ? 'debit' : 'credit',
        ];
    }

    /**
     * Fold surplus fields back into the description. Only possible when the
     * description is a single column — with two description columns we cannot
     * tell which one swallowed the stray delimiter.
     *
     * @param list<string>          $raw
     * @param array<string,int>     $index
     * @param list<string>          $descCols
     * @return list<string>|null
     */
    private function repairOverWideRow(array $raw, int $width, array $index, array $descCols, string $delimiter): ?array
    {
        if (count($descCols) !== 1) {
            return null;
        }
        $at      = $index[$descCols[0]];
        $surplus = count($raw) - $width;

        $merged = implode($delimiter, array_slice($raw, $at, $surplus + 1));
        array_splice($raw, $at, $surplus + 1, [$merged]);

        return array_values($raw);
    }

    /** @param callable(?string):string $cell */
    private function describe(callable $cell, array $descCols): string
    {
        $parts = [];
        foreach ($descCols as $col) {
            $v = trim($cell($col));
            if ($v !== '') {
                $parts[] = $v;
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Collapse the whitespace every bank pads its fields with. When clean_ocr
     * is on, also heal tokens a PDF-to-CSV conversion split mid-word
     * ("akhil6- 169@okhdfcbank" -> "akhil6169@okhdfcbank").
     *
     * Only `description` is cleaned; `raw_description` stays untouched because
     * the idempotency hash keys on it. Toggling clean_ocr must never fork a row.
     */
    private function clean(string $text, bool $cleanOcr): string
    {
        if ($cleanOcr) {
            $text = (string) preg_replace('/([a-z0-9])-\s+([a-z0-9])/', '$1$2', $text);
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function report(array $rows, array $errors, array $skipped, int $repaired, ?int $openingHint, ColumnMapping $map, ?bool $isLiability): array
    {
        $order = $this->detectOrder($rows);
        $dates = array_column($rows, 'txn_date');

        return [
            'parsed'           => count($rows),
            'skipped'          => count($skipped),
            'errors'           => count($errors),
            'repaired_rows'    => $repaired,
            'error_rows'       => array_slice($errors, 0, 20),
            'skipped_rows'     => array_slice($skipped, 0, 20),
            'row_order'        => $order,
            'date_from'        => $dates === [] ? null : min($dates),
            'date_to'          => $dates === [] ? null : max($dates),
            'opening_balance'  => $openingHint,
            'balance_check'    => $this->checkBalances($rows, $order, $map, $isLiability),
            'totals'           => [
                'debit'  => array_sum(array_map(static fn ($r) => $r['cashflow'] === 'debit' ? $r['amount'] : 0, $rows)),
                'credit' => array_sum(array_map(static fn ($r) => $r['cashflow'] === 'credit' ? $r['amount'] : 0, $rows)),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function detectOrder(array $rows): string
    {
        if (count($rows) < 2) {
            return 'asc';
        }
        $first = $rows[0]['txn_date'];
        $last  = $rows[count($rows) - 1]['txn_date'];

        if ($first < $last) {
            return 'asc';
        }
        if ($first > $last) {
            return 'desc';
        }

        return 'asc';   // single-day statement
    }

    /**
     * Walk the rows chronologically and assert the running balance moves by
     * exactly the transaction amount.
     *
     * The direction is dictated by the account, NOT inferred from the data. On
     * an asset account a credit raises the balance; on a credit card a purchase
     * (debit) raises what you owe. Inferring it would defeat the check's most
     * valuable case: swap the debit and credit columns and every delta flips
     * sign, so the *opposite* convention fits perfectly and a self-tuning check
     * would report "all good" while inverting the entire ledger.
     *
     * So we test both, and treat "only the opposite convention fits" as the
     * specific, nameable failure it is: the debit/credit columns are reversed.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function checkBalances(array $rows, string $order, ColumnMapping $map, ?bool $isLiability): array
    {
        if ($map->balanceColumn() === null) {
            return ['performed' => false, 'reason' => 'no balance column mapped'];
        }

        $ordered = $order === 'desc' ? array_reverse($rows) : $rows;
        $ordered = array_values(array_filter($ordered, static fn ($r) => $r['balance_after'] !== null));
        if (count($ordered) < 2) {
            return ['performed' => false, 'reason' => 'not enough rows carry a balance'];
        }

        $run = function (int $sign) use ($ordered): array {
            $breaks = [];
            for ($i = 1, $n = count($ordered); $i < $n; $i++) {
                $delta    = $ordered[$i]['cashflow'] === 'credit' ? $ordered[$i]['amount'] : -$ordered[$i]['amount'];
                $expected = $ordered[$i - 1]['balance_after'] + $sign * $delta;
                if ($expected !== $ordered[$i]['balance_after']) {
                    $breaks[] = [
                        'line'     => $ordered[$i]['line'],
                        'expected' => $expected,
                        'actual'   => $ordered[$i]['balance_after'],
                        'drift'    => $ordered[$i]['balance_after'] - $expected,
                    ];
                }
            }

            return $breaks;
        };

        $checked   = count($ordered) - 1;
        $asset     = $run(1);
        $liability = $run(-1);

        // Without an account we cannot know which way the balance should move,
        // so accept either and say the direction was not verified.
        if ($isLiability === null) {
            $breaks = count($asset) <= count($liability) ? $asset : $liability;

            return [
                'performed'     => true,
                'convention'    => count($asset) <= count($liability) ? 'asset' : 'liability',
                'assumed'       => true,
                'inverted'      => false,
                'checked'       => $checked,
                'mismatches'    => count($breaks),
                'ok'            => $breaks === [],
                'breaks'        => array_slice($breaks, 0, 10),
            ];
        }

        $expected = $isLiability ? $liability : $asset;
        $opposite = $isLiability ? $asset : $liability;

        return [
            'performed'  => true,
            'convention' => $isLiability ? 'liability' : 'asset',
            'assumed'    => false,
            // Reconciles ONLY when the two columns are read the other way round.
            'inverted'   => $expected !== [] && $opposite === [],
            'checked'    => $checked,
            'mismatches' => count($expected),
            'ok'         => $expected === [],
            'breaks'     => array_slice($expected, 0, 10),
        ];
    }
}
