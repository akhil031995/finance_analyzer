<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * End-of-day bookkeeping the cron runs before the daily summary:
 *  - writes one balance_snapshots row per account for the given date, so
 *    v_net_worth_history has a data point (powers the trend + ladder growth);
 *  - persists any newly crossed ₹10,000 net-worth milestones so the ladder's
 *    "unlocked" badges survive later dips.
 *
 * Both are idempotent: snapshots UPSERT on (account_id, date) and milestones
 * INSERT OR IGNORE on (kind, amount).
 */
final class SnapshotService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return int number of accounts snapshotted */
    public function snapshot(?string $date = null): int
    {
        $date = $date ?? date('Y-m-d');
        $accounts = $this->pdo->query('SELECT id, current_balance FROM accounts WHERE is_archived = 0')
            ->fetchAll(PDO::FETCH_ASSOC);

        $up = $this->pdo->prepare(
            'INSERT INTO balance_snapshots (account_id, snapshot_date, balance)
             VALUES (?,?,?)
             ON CONFLICT(account_id, snapshot_date) DO UPDATE SET balance = excluded.balance'
        );
        foreach ($accounts as $a) {
            $up->execute([$a['id'], $date, (int) $a['current_balance']]);
        }

        return count($accounts);
    }

    /**
     * Persist every ₹10k net-worth milestone at or below current net worth that
     * isn't already recorded.
     * @return list<int> amounts (paise) of newly unlocked milestones
     */
    public function detectMilestones(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $step = (int) ($this->pdo->query("SELECT value FROM settings WHERE key = 'ladder_step_paise'")
            ->fetchColumn() ?: 1000000);

        $net = (int) ($this->pdo->query('SELECT net_worth FROM v_net_worth')->fetchColumn() ?: 0);
        if ($net < $step) {
            return [];
        }

        $highest = intdiv($net, $step) * $step;
        $existing = $this->pdo->query("SELECT amount FROM milestones WHERE kind = 'net_worth'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('intval', $existing);

        $insert = $this->pdo->prepare(
            "INSERT OR IGNORE INTO milestones (kind, amount, achieved_on) VALUES ('net_worth', ?, ?)"
        );
        $new = [];
        for ($amount = $step; $amount <= $highest; $amount += $step) {
            if (!in_array($amount, $existing, true)) {
                $insert->execute([$amount, $date]);
                if ($insert->rowCount() === 1) {
                    $new[] = $amount;
                }
            }
        }

        return $new;
    }
}
