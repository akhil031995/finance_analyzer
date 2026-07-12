<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Composes the daily Telegram markdown: net worth, per-account closing
 * balances, month-over-month spend, daily/monthly average expense, and any
 * milestones unlocked today. Money is paise; formatted to ₹ with Indian grouping.
 *
 * compose() is pure (returns the string) so it can be previewed/tested without
 * sending; sendDaily() runs the snapshot → milestones → compose → send pipeline.
 */
final class DailySummaryService
{
    public function __construct(
        private PDO $pdo,
        private SnapshotService $snapshots,
        private TelegramNotifier $telegram,
    ) {
    }

    /**
     * Full daily pipeline: snapshot balances, detect milestones, send summary.
     * @return array{sent:bool, accounts:int, new_milestones:list<int>, message:string}
     */
    public function sendDaily(?string $date = null): array
    {
        $date  = $date ?? date('Y-m-d');
        $count = $this->snapshots->snapshot($date);
        $new   = $this->snapshots->detectMilestones($date);
        $msg   = $this->compose($date, $new);
        $sent  = $this->telegram->send($msg, 'daily_summary');

        return ['sent' => $sent, 'accounts' => $count, 'new_milestones' => $new, 'message' => $msg];
    }

    /**
     * @param list<int> $newMilestones amounts (paise) unlocked today
     */
    public function compose(?string $date = null, array $newMilestones = []): string
    {
        $date = $date ?? date('Y-m-d');
        $nw   = $this->pdo->query('SELECT total_assets, total_liabilities, net_worth FROM v_net_worth')
            ->fetch(PDO::FETCH_ASSOC) ?: ['total_assets' => 0, 'total_liabilities' => 0, 'net_worth' => 0];

        $lines = [];
        $lines[] = '*💰 Daily Finance Summary*';
        $lines[] = '_' . date('D, d M Y', strtotime($date)) . '_';
        $lines[] = '';
        $lines[] = '*Net worth:* ₹' . $this->fmt((int) $nw['net_worth']);
        $lines[] = 'Assets ₹' . $this->fmt((int) $nw['total_assets'])
                 . '  ·  Liabilities ₹' . $this->fmt((int) $nw['total_liabilities']);

        // Per-account closing balances.
        $accounts = $this->pdo->query(
            'SELECT name, is_liability, current_balance FROM accounts
             WHERE is_archived = 0 ORDER BY is_liability, current_balance DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($accounts !== []) {
            $lines[] = '';
            $lines[] = '*Closing balances*';
            foreach ($accounts as $a) {
                $sign = $a['is_liability'] ? '−' : '';
                $lines[] = '• ' . $a['name'] . ': ' . $sign . '₹' . $this->fmt((int) $a['current_balance']);
            }
        }

        // Spend snapshot (self-transfers/investments already excluded by the views).
        $mom = $this->momLine();
        if ($mom !== null) {
            $lines[] = '';
            $lines[] = $mom;
        }

        if ($newMilestones !== []) {
            $lines[] = '';
            foreach ($newMilestones as $amount) {
                $lines[] = '🏆 *Milestone unlocked:* ₹' . $this->fmt($amount) . ' net worth!';
            }
        }

        return implode("\n", $lines);
    }

    private function momLine(): ?string
    {
        $rows = $this->pdo->query('SELECT month, total_expense FROM v_monthly_cashflow ORDER BY month')
            ->fetchAll(PDO::FETCH_ASSOC);
        $n = count($rows);
        if ($n === 0) {
            return null;
        }

        $this_ = $rows[$n - 1];
        $line  = '*Spend (' . $this_['month'] . '):* ₹' . $this->fmt((int) $this_['total_expense']);

        if ($n >= 2 && (int) $rows[$n - 2]['total_expense'] > 0) {
            $prev = (int) $rows[$n - 2]['total_expense'];
            $pct  = round((((int) $this_['total_expense'] - $prev) / $prev) * 100, 1);
            $arrow = $pct < 0 ? '🟢 down' : ($pct > 0 ? '🔴 up' : 'flat');
            $line .= "  ({$arrow} " . abs($pct) . '% vs ' . $rows[$n - 2]['month'] . ')';
        }

        return $line;
    }

    private function fmt(int $paise): string
    {
        return number_format($paise / 100, 2);
    }
}
