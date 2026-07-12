<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Csv\BankFormatRepository;
use App\Services\Csv\ColumnMapping;
use App\Services\Csv\CsvFile;
use App\Services\Csv\CsvStatementParser;
use App\Services\Csv\MappingSuggester;
use App\Services\Tagging\TaggingEngine;
use App\Support\DateFormatGuesser;
use App\Support\TxnHash;
use PDO;
use RuntimeException;

/**
 * The three-step CSV import: preview -> validate -> stage.
 *
 *   preview()   opens the file, recognises a saved format by header fingerprint
 *               (or suggests a mapping), and returns a grid for the UI. Reads only.
 *   validate()  applies a candidate mapping as a DRY RUN: parses, tags, checks
 *               balance continuity, counts duplicates. Writes nothing.
 *   stage()     re-parses with the confirmed mapping, tags, and inserts into
 *               staged_transactions for human review. Optionally remembers the
 *               mapping as a bank_format so the next upload skips the mapper.
 *
 * Nothing reaches `transactions` here — that is CommitService, after review.
 */
final class ImportService
{
    private const SAMPLE_ROWS   = 20;
    private const PREVIEW_ROWS  = 15;

    public function __construct(
        private PDO $pdo,
        private BankFormatRepository $formats,
        private CsvStatementParser $parser,
        private LogService $log,
    ) {
    }

    /**
     * @return array<string,mixed> everything the mapping screen needs
     */
    public function preview(int $uploadId): array
    {
        $upload = $this->fetchUpload($uploadId);
        $csv    = CsvFile::open($upload['stored_path']);

        $fingerprint = $csv->fingerprint();
        $notes       = [];
        $matchKind   = null;

        // Exact layout we have seen before (column order is not part of the key).
        $saved = $this->formats->findByFingerprint($fingerprint);
        if ($saved !== null) {
            $matchKind = 'exact';
        } else {
            // The bank changed something. If it did not touch a column we
            // actually use, the old mapping still parses this file perfectly.
            $saved = $this->formats->findCompatible($csv->headers());
            if ($saved !== null) {
                $matchKind = 'compatible';
            }
        }

        if ($saved !== null) {
            $mapping = $saved['mapping'];
            if ($matchKind === 'compatible') {
                $extra = array_diff($csv->headers(), ColumnMapping::referencedColumns($mapping));
                $notes[] = "This file's columns differ from when \"{$saved['name']}\" was saved, but every "
                    . 'column that format needs is still here, so the mapping was applied unchanged.'
                    . ($extra === [] ? '' : ' Unused columns: ' . implode(', ', $extra) . '.');
            }
            $this->log->info('csv_parse', 'format_matched',
                "Recognised the layout as \"{$saved['name']}\" ({$matchKind} match)", $uploadId);
        } else {
            $suggestion = MappingSuggester::suggest($csv);
            $mapping    = $suggestion['mapping'];
            $notes      = $suggestion['notes'];
            $this->log->info('csv_parse', 'format_unknown',
                'New statement layout — suggested a mapping for confirmation', $uploadId);
        }

        // A mapping saved against an older export may name a column this file no
        // longer has. Surface that instead of failing: the user re-picks it.
        $missing = $this->missingColumns($mapping, $csv->headers());
        if ($missing !== []) {
            $notes[] = 'This file no longer has ' . implode(', ', array_map(
                static fn ($c) => "\"{$c}\"", $missing
            )) . ' — re-pick ' . (count($missing) === 1 ? 'that column' : 'those columns') . ' below.';
        }

        return [
            'upload' => [
                'id'            => $uploadId,
                'original_name' => $upload['original_name'],
                'account_id'    => $upload['account_id'] === null ? null : (int) $upload['account_id'],
                'status'        => $upload['status'],
            ],
            'file' => [
                'fingerprint'    => $fingerprint,
                'delimiter'      => $csv->delimiter(),
                'encoding'       => $csv->encoding(),
                'header_row'     => $csv->headerRowIndex(),
                'headers'        => $csv->headers(),
                'total_rows'     => $csv->dataRowCount(),
                'malformed_rows' => count($csv->malformedRows()),
            ],
            'sample'         => $csv->dataRows(self::SAMPLE_ROWS),
            'mapping'        => $mapping,
            'matched_format' => $saved === null ? null : [
                'id'        => (int) $saved['id'],
                'name'      => $saved['name'],
                'use_count' => (int) $saved['use_count'],
                'match'     => $matchKind,   // exact | compatible
            ],
            'notes'        => $notes,
            'date_formats' => DateFormatGuesser::CANDIDATES,
        ];
    }

    /**
     * Dry run: what WOULD be imported under this mapping.
     *
     * @param array<string,mixed> $rawMapping
     */
    public function validate(int $uploadId, array $rawMapping, ?int $accountId = null): array
    {
        $upload  = $this->fetchUpload($uploadId);
        $csv     = CsvFile::open($upload['stored_path']);
        $mapping = ColumnMapping::fromArray($rawMapping, $csv->headers());

        $accountId ??= $upload['account_id'] === null ? null : (int) $upload['account_id'];

        ['rows' => $rows, 'report' => $report] = $this->parser->parse($csv, $mapping, $this->isLiability($accountId));

        $engine = new TaggingEngine($this->pdo);
        $hashSeen  = $this->pdo->prepare('SELECT 1 FROM transactions WHERE txn_hash = ?');

        $categories = [];
        $preview    = [];
        $duplicates = 0;

        foreach ($rows as $i => $row) {
            $tag = $engine->tag(['description' => $row['description'], 'cashflow' => $row['cashflow']]);
            $categories[$tag['category']] = ($categories[$tag['category']] ?? 0) + 1;

            $isDupe = false;
            if ($accountId !== null) {
                $hashSeen->execute([$this->hashOf($accountId, $row)]);
                $isDupe = $hashSeen->fetchColumn() !== false;
                $duplicates += $isDupe ? 1 : 0;
            }

            if ($i < self::PREVIEW_ROWS) {
                $preview[] = $row + $tag + ['is_duplicate' => $isDupe ? 1 : 0];
            }
        }

        arsort($categories);
        $untagged = ($categories['other_income'] ?? 0) + ($categories['other_expense'] ?? 0);

        return [
            'report'     => $report,
            'preview'    => $preview,
            'categories' => $categories,
            'untagged'   => $untagged,
            'duplicates' => $duplicates,
            'mapping'    => $mapping->toArray(),
            'account'    => $this->accountContext($accountId, $rows, $report['row_order']),
        ];
    }

    /**
     * What the user needs to see before committing rows to an account: which
     * account, whether it already holds transactions, and — when it is empty —
     * the opening balance this statement implies.
     *
     * Balances are `opening_balance + credits - debits`. Import a statement into
     * an account whose opening balance is still 0 and the account reports the
     * period's NET FLOW, not its balance: a normal spending month leaves an
     * asset account looking overdrawn, and net worth goes negative with no
     * liability in sight. The statement already carries the answer, so offer it.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function accountContext(?int $accountId, array $rows, string $rowOrder): ?array
    {
        if ($accountId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name, opening_balance, is_liability FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account === false) {
            return null;
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE account_id = ?');
        $count->execute([$accountId]);
        $existing = (int) $count->fetchColumn();

        // The chronologically first row's balance, rewound past its own amount.
        $implied = null;
        $first   = $rowOrder === 'desc' ? ($rows[count($rows) - 1] ?? null) : ($rows[0] ?? null);
        if ($first !== null && $first['balance_after'] !== null) {
            $delta   = $first['cashflow'] === 'credit' ? $first['amount'] : -$first['amount'];
            $implied = $first['balance_after'] - ((bool) $account['is_liability'] ? -$delta : $delta);
        }

        return [
            'id'                      => (int) $account['id'],
            'name'                    => $account['name'],
            'opening_balance'         => (int) $account['opening_balance'],
            'existing_transactions'   => $existing,
            'implied_opening_balance' => $implied,
            // Only safe to offer on a fresh account: a later statement covering
            // an EARLIER period would otherwise overwrite a correct value.
            'can_set_opening'         => $existing === 0 && $implied !== null && (int) $account['opening_balance'] === 0,
        ];
    }

    /**
     * Commit the mapping: parse everything, tag it, and fill the review queue.
     *
     * @param array<string,mixed> $rawMapping
     * @param array{name:string}|null $saveFormat remember this layout for next time
     * @param int|null $openingBalance paise; seed the account's opening balance
     *        from this statement (only honoured on an account with no ledger yet)
     */
    public function stage(int $uploadId, array $rawMapping, ?int $accountId, ?array $saveFormat, ?int $openingBalance = null): array
    {
        $upload = $this->fetchUpload($uploadId);
        if ($upload['status'] !== 'mapping') {
            throw new RuntimeException("Upload is '{$upload['status']}', not awaiting a column mapping");
        }

        $csv     = CsvFile::open($upload['stored_path']);
        $mapping = ColumnMapping::fromArray($rawMapping, $csv->headers());
        $accountId ??= $upload['account_id'] === null ? null : (int) $upload['account_id'];

        $this->log->info('csv_parse', 'start', "Parsing '{$upload['original_name']}'", $uploadId);
        ['rows' => $rows, 'report' => $report] = $this->parser->parse($csv, $mapping, $this->isLiability($accountId));

        if ($rows === []) {
            $this->fail($uploadId, 'No transactions could be parsed with this mapping.');
            throw new RuntimeException('No transactions could be parsed with this mapping');
        }
        $this->log->success('csv_parse', 'complete',
            "{$report['parsed']} rows parsed, {$report['skipped']} skipped, {$report['errors']} errors", $uploadId, $report);

        $formatId = null;
        if ($saveFormat !== null) {
            $formatId = $this->formats->save(
                (string) $saveFormat['name'],
                $csv->fingerprint(),
                implode($csv->delimiter(), $csv->headers()),
                $csv->delimiter(),
                $mapping->toArray(),
                $accountId
            );
        } else {
            $existing = $this->formats->findByFingerprint($csv->fingerprint());
            $formatId = $existing === null ? null : (int) $existing['id'];
        }
        if ($formatId !== null) {
            $this->formats->touch($formatId);
        }

        // Seed the opening balance before any rows land, so the balance the
        // commit recomputes is the account's real one and not the period's net flow.
        if ($openingBalance !== null && $accountId !== null) {
            $ctx = $this->accountContext($accountId, $rows, $report['row_order']);
            if ($ctx !== null && $ctx['can_set_opening']) {
                $this->pdo->prepare(
                    "UPDATE accounts SET opening_balance = ?, current_balance = ?, updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([$openingBalance, $openingBalance, $accountId]);
                $this->log->info('ingest', 'opening_balance_set',
                    'Opening balance seeded from the statement', $uploadId, ['paise' => $openingBalance]);
            }
        }

        $engine = new TaggingEngine($this->pdo);
        $insert = $this->pdo->prepare(
            'INSERT INTO staged_transactions
                (upload_id, account_id, txn_hash, txn_date, description, raw_description,
                 amount, cashflow, mode, category, tag_source, tag_rule_id,
                 is_self_transfer, counterparty, reference_id, balance_after, is_duplicate)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $hashSeen = $this->pdo->prepare('SELECT 1 FROM transactions WHERE txn_hash = ?');

        $staged = 0;
        $dupes  = 0;

        $this->pdo->beginTransaction();
        try {
            // A re-stage after "back to mapping" must not double the queue.
            $this->pdo->prepare('DELETE FROM staged_transactions WHERE upload_id = ?')->execute([$uploadId]);

            foreach ($rows as $row) {
                $tag  = $engine->tag(['description' => $row['description'], 'cashflow' => $row['cashflow']]);
                $hash = $this->hashOf($accountId ?? 0, $row);

                $hashSeen->execute([$hash]);
                $isDupe = $hashSeen->fetchColumn() !== false;
                $dupes += $isDupe ? 1 : 0;

                $insert->execute([
                    $uploadId, $accountId, $hash, $row['txn_date'],
                    $row['description'], $row['raw_description'],
                    $row['amount'], $row['cashflow'],
                    $tag['mode'], $tag['category'], $tag['tag_source'], $tag['tag_rule_id'],
                    $tag['is_self_transfer'], $tag['counterparty'],
                    $row['reference_id'], $row['balance_after'], $isDupe ? 1 : 0,
                ]);
                $staged++;
            }

            $this->pdo->prepare(
                "UPDATE uploads SET status = 'review', account_id = ?, bank_format_id = ?,
                        column_mapping = ?, row_count = ?, parse_warnings = ?,
                        error_message = NULL, parsed_at = datetime('now')
                 WHERE id = ?"
            )->execute([
                $accountId, $formatId,
                json_encode($mapping->toArray(), JSON_UNESCAPED_SLASHES),
                $staged,
                json_encode($this->warnings($report), JSON_UNESCAPED_SLASHES),
                $uploadId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fail($uploadId, $e->getMessage());
            throw $e;
        }

        $engine->flushHits();
        $this->log->success('ingest', 'staged',
            "{$staged} rows staged" . ($dupes > 0 ? ", {$dupes} already in the ledger" : ''), $uploadId);

        return [
            'upload_id'  => $uploadId,
            'status'     => 'review',
            'staged'     => $staged,
            'duplicates' => $dupes,
            'format_id'  => $formatId,
            'report'     => $report,
        ];
    }

    // ---------------------------------------------------------------- private

    /**
     * Which way the running balance must move for this account. Null when no
     * account is chosen: the balance check then cannot spot a debit/credit swap.
     */
    private function isLiability(?int $accountId): ?bool
    {
        if ($accountId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT is_liability FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (bool) $value;
    }

    /** @param array<string,mixed> $row */
    private function hashOf(int $accountId, array $row): string
    {
        // Keyed on raw_description, not description: toggling clean_ocr or
        // editing the text in Review must never fork a row into a duplicate.
        return TxnHash::make(
            $accountId,
            $row['txn_date'],
            $row['amount'],
            $row['cashflow'],
            $row['raw_description'],
            (string) ($row['reference_id'] ?? '')
        );
    }

    /** @return list<string> */
    private function warnings(array $report): array
    {
        $out = [];
        if ($report['errors'] > 0) {
            $out[] = "{$report['errors']} row(s) could not be parsed and were skipped.";
        }
        if ($report['repaired_rows'] > 0) {
            $out[] = "{$report['repaired_rows']} row(s) contained an unquoted delimiter inside the "
                . 'description and were repaired.';
        }
        $bc = $report['balance_check'];
        if (($bc['performed'] ?? false) && !$bc['ok']) {
            $out[] = !empty($bc['inverted'])
                ? 'The running balance only reconciles if debit and credit are swapped — those two columns '
                    . 'are almost certainly mapped the wrong way round.'
                : "The running balance does not reconcile on {$bc['mismatches']} of {$bc['checked']} rows. "
                    . 'The mapping or date format may be wrong.';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $mapping
     * @param list<string>        $headers
     * @return list<string>
     */
    private function missingColumns(array $mapping, array $headers): array
    {
        return array_values(array_diff(ColumnMapping::referencedColumns($mapping), $headers));
    }

    private function fail(int $uploadId, string $message): void
    {
        $this->pdo->prepare("UPDATE uploads SET status = 'failed', error_message = ? WHERE id = ?")
            ->execute([$message, $uploadId]);
        $this->log->error('csv_parse', 'error', $message, $uploadId);
    }

    /** @return array<string,mixed> */
    private function fetchUpload(int $uploadId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM uploads WHERE id = ?');
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException("Upload {$uploadId} not found");
        }
        if (!is_file($row['stored_path'])) {
            throw new RuntimeException('The stored file no longer exists — re-upload it');
        }

        return $row;
    }
}
