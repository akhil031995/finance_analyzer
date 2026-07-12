<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TxnHash;
use PDO;
use RuntimeException;

/**
 * Promotes reviewed staged rows into the committed `transactions` ledger and
 * recomputes affected account balances.
 *
 * Idempotency: each row's hash is recomputed from its (possibly edited) values
 * through the canonical formula and inserted with INSERT OR IGNORE, so the
 * UNIQUE(txn_hash) constraint silently drops duplicates — committing twice, or
 * re-committing a row already in the ledger, is a no-op.
 *
 * Balances are recomputed from scratch (opening_balance + ledger sums), not
 * incremented, so they never drift regardless of how many times commit runs.
 * Self-transfers DO affect an account's own balance (a transfer out reduces it);
 * their exclusion applies only to income/expense analytics, not to balances.
 */
final class CommitService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{committed:int, skipped_duplicates:int, rejected:int,
     *               account_id:int, account_balance:int}
     */
    public function commit(int $uploadId, ?int $accountIdOverride = null): array
    {
        $upload = $this->fetchUpload($uploadId);
        if ($upload['status'] !== 'review') {
            throw new RuntimeException("Upload is '{$upload['status']}', not awaiting review");
        }

        $accountId = $accountIdOverride ?? (int) ($upload['account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new RuntimeException('Assign an account to this upload before committing');
        }
        $this->assertAccount($accountId);

        $this->pdo->beginTransaction();
        try {
            // Persist a late account assignment onto the upload + its rows.
            if ($accountId !== (int) ($upload['account_id'] ?? 0)) {
                $this->pdo->prepare('UPDATE uploads SET account_id = ? WHERE id = ?')->execute([$accountId, $uploadId]);
                $this->pdo->prepare('UPDATE staged_transactions SET account_id = ? WHERE upload_id = ?')
                    ->execute([$accountId, $uploadId]);
            }

            $rows = $this->pdo->prepare(
                "SELECT * FROM staged_transactions WHERE upload_id = ? AND review_status != 'rejected'"
            );
            $rows->execute([$uploadId]);

            $rejected = (int) $this->scalar(
                "SELECT COUNT(*) FROM staged_transactions WHERE upload_id = ? AND review_status = 'rejected'",
                [$uploadId]
            );

            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO transactions
                    (account_id, upload_id, txn_hash, txn_date, description, raw_description,
                     amount, cashflow, mode, category, tag_source, is_self_transfer, counterparty,
                     reference_id, balance_after, source)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, "import")'
            );

            $committed = 0;
            $duplicates = 0;
            $committedIds = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hash = TxnHash::make(
                    $accountId,
                    (string) $r['txn_date'],
                    (int) $r['amount'],
                    (string) $r['cashflow'],
                    (string) ($r['raw_description'] ?? $r['description'] ?? ''),
                    (string) ($r['reference_id'] ?? '')
                );

                $insert->execute([
                    $accountId, $uploadId, $hash, $r['txn_date'], $r['description'], $r['raw_description'],
                    (int) $r['amount'], $r['cashflow'], $r['mode'], $r['category'], $r['tag_source'],
                    (int) $r['is_self_transfer'], $r['counterparty'], $r['reference_id'], $r['balance_after'],
                ]);

                if ($insert->rowCount() === 1) {
                    $committed++;
                } else {
                    $duplicates++;   // UNIQUE(txn_hash) already had it
                }
                $committedIds[] = (int) $r['id'];
            }

            if ($committedIds !== []) {
                $ph = implode(',', array_fill(0, count($committedIds), '?'));
                $this->pdo->prepare(
                    "UPDATE staged_transactions SET review_status = 'approved', updated_at = datetime('now')
                     WHERE id IN ($ph)"
                )->execute($committedIds);
            }

            $balance = $this->recomputeBalance($accountId);

            $this->pdo->prepare(
                "UPDATE uploads SET status = 'committed', committed_at = datetime('now') WHERE id = ?"
            )->execute([$uploadId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Independent check: the account's recomputed balance must equal the
        // closing balance printed on the newest statement row. A mismatch means
        // the opening balance is unset/wrong, or rows from another bank's
        // statement were committed into this account — both silent otherwise.
        $statementBalance = $this->scalar(
            'SELECT balance_after FROM transactions
             WHERE account_id = ? AND balance_after IS NOT NULL
             ORDER BY txn_date DESC, id DESC LIMIT 1',
            [$accountId]
        );

        // Importing a statement OLDER than everything already in the account leaves
        // opening_balance describing a date that is no longer the start of the
        // ledger, so its flows are counted twice. Report it; never silently rewrite
        // an opening balance the user may have set deliberately.
        $health = (new AccountHealth($this->pdo))->report($accountId);

        return [
            'committed'          => $committed,
            'skipped_duplicates' => $duplicates,
            'rejected'           => $rejected,
            'account_id'         => $accountId,
            'account_balance'    => $balance,
            'statement_balance'  => $statementBalance === false ? null : (int) $statementBalance,
            'reconciled'         => $statementBalance === false ? null : ((int) $statementBalance === $balance),
            'health'             => $health,
        ];
    }

    /**
     * Recompute an account's current_balance from opening_balance + ledger.
     * Asset:     opening + credits − debits.
     * Liability: opening + debits − credits (purchases add to what's owed).
     * @return int new balance in paise
     */
    public function recomputeBalance(int $accountId): int
    {
        // A loan's balance is owned by the amortisation engine (LoanService::sync),
        // not by summing ledger rows — a loan account has none. Recomputing it
        // here would silently zero the debt.
        if ((int) $this->scalar('SELECT is_derived FROM accounts WHERE id = ?', [$accountId]) === 1) {
            return (int) $this->scalar('SELECT current_balance FROM accounts WHERE id = ?', [$accountId]);
        }

        $isLiability = (int) $this->scalar('SELECT is_liability FROM accounts WHERE id = ?', [$accountId]) === 1;
        $opening     = (int) $this->scalar('SELECT opening_balance FROM accounts WHERE id = ?', [$accountId]);

        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN cashflow = 'credit' THEN amount END), 0) AS cr,
                COALESCE(SUM(CASE WHEN cashflow = 'debit'  THEN amount END), 0) AS dr
             FROM transactions WHERE account_id = ?"
        );
        $stmt->execute([$accountId]);
        ['cr' => $cr, 'dr' => $dr] = $stmt->fetch(PDO::FETCH_ASSOC);

        $balance = $isLiability
            ? $opening + (int) $dr - (int) $cr
            : $opening + (int) $cr - (int) $dr;

        $this->pdo->prepare("UPDATE accounts SET current_balance = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$balance, $accountId]);

        return $balance;
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

        return $row;
    }

    private function assertAccount(int $accountId): void
    {
        $row = $this->scalar('SELECT is_derived FROM accounts WHERE id = ? AND is_archived = 0', [$accountId]);
        if ($row === false) {
            throw new RuntimeException("Account {$accountId} does not exist");
        }
        // A loan account tracks an amortisation schedule, not a bank statement.
        if ((int) $row === 1) {
            throw new RuntimeException(
                'That is a loan account — its balance comes from the loan schedule, not from a statement. '
                . 'Import the statement into the bank account the EMI is debited from.'
            );
        }
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
