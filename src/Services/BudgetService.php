<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Exclusions;
use PDO;

/**
 * Monthly budgets and the derived daily allowance.
 *
 * The overall budget (category = '') drives the headline: monthly limit, the
 * flat daily budget (limit ÷ days-in-month), spend so far this CALENDAR month,
 * and — the useful part — how much you can still spend per remaining day to
 * stay within budget. Per-category budgets get the same spent/limit treatment.
 *
 * "Spend" uses the same exclusions as the analytics views (self-transfers,
 * investments, EPF, and credit-card bill payments don't count as spending).
 */
final class BudgetService
{
    // Which tags don't count as spending is a user setting, shared with the
    // analytics views. See src/Support/Exclusions.php.
    private const EXCLUDED = Exclusions::SQL;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null null when no overall budget is set */
    public function overallStatus(?string $month = null): ?array
    {
        $amount = $this->pdo->query("SELECT amount FROM budgets WHERE category = ''")->fetchColumn();
        if ($amount === false) {
            return null;
        }

        return $this->status('', (int) $amount, $month);
    }

    /** @return list<array<string,mixed>> per-category budgets with spend */
    public function categoryStatuses(?string $month = null): array
    {
        $rows = $this->pdo->query("SELECT category, amount FROM budgets WHERE category <> '' ORDER BY category")
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => $this->status($r['category'], (int) $r['amount'], $month), $rows);
    }

    /** @return list<array<string,mixed>> everything (overall first) for the settings UI */
    public function all(?string $month = null): array
    {
        $out = [];
        if (($o = $this->overallStatus($month)) !== null) {
            $out[] = $o;
        }

        return array_merge($out, $this->categoryStatuses($month));
    }

    /**
     * Full monthly breakdown for the Budget page: overall status, every
     * spending category's actual spend vs its budget (if any), and totals.
     *
     * @return array<string,mixed>
     */
    public function analysis(?string $month = null): array
    {
        $month = $month ?? date('Y-m');

        // Actual spend per category this month (same exclusions as "spend").
        $stmt = $this->pdo->prepare(
            "SELECT category, SUM(amount) AS spent, COUNT(*) AS txns
             FROM transactions
             WHERE cashflow = 'debit' AND is_self_transfer = 0
               AND " . self::EXCLUDED . "
               AND strftime('%Y-%m', txn_date) = ?
             GROUP BY category"
        );
        $stmt->execute([$month]);
        $spend = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $spend[$r['category']] = ['spent' => (int) $r['spent'], 'txns' => (int) $r['txns']];
        }

        // Per-category budgets.
        $budgets = [];
        foreach ($this->pdo->query("SELECT category, amount FROM budgets WHERE category <> ''") as $r) {
            $budgets[$r['category']] = (int) $r['amount'];
        }

        $totalSpent    = array_sum(array_column($spend, 'spent'));
        $totalBudgeted = array_sum($budgets);

        $categories = [];
        foreach (array_unique(array_merge(array_keys($spend), array_keys($budgets))) as $cat) {
            $spent  = $spend[$cat]['spent'] ?? 0;
            $budget = $budgets[$cat] ?? null;
            $categories[] = [
                'category'      => $cat,
                'spent'         => $spent,
                'txns'          => $spend[$cat]['txns'] ?? 0,
                'budget'        => $budget,
                'budget_pct'    => $budget ? round($spent / $budget * 100, 1) : null,
                'remaining'     => $budget !== null ? $budget - $spent : null,
                'over_budget'   => $budget !== null && $spent > $budget,
                'share_pct'     => $totalSpent > 0 ? round($spent / $totalSpent * 100, 1) : 0,
            ];
        }
        // Biggest spend first.
        usort($categories, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        return [
            'month'      => $month,
            'overall'    => $this->overallStatus($month),
            'categories' => $categories,
            'totals'     => [
                'spent'                => $totalSpent,
                'budgeted'             => $totalBudgeted,      // Σ per-category budgets
                'categories_tracked'   => count($budgets),
                'categories_spending'  => count($spend),
            ],
        ];
    }

    public function upsert(string $category, int $amountPaise): void
    {
        $this->pdo->prepare(
            "INSERT INTO budgets (category, amount) VALUES (?, ?)
             ON CONFLICT(category) DO UPDATE SET amount = excluded.amount, updated_at = datetime('now')"
        )->execute([$category, $amountPaise]);
    }

    public function delete(string $category): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM budgets WHERE category = ?');
        $stmt->execute([$category]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed> */
    private function status(string $category, int $amount, ?string $month): array
    {
        $month       = $month ?? date('Y-m');
        $isCurrent   = $month === date('Y-m');
        $daysInMonth = (int) date('t', strtotime($month . '-01'));
        $day         = $isCurrent ? (int) date('j') : $daysInMonth;   // past months are fully elapsed
        $daysLeft    = max(0, $daysInMonth - $day) + ($isCurrent ? 1 : 0);   // include today

        $spent     = $this->spent($category, $month);
        $remaining = $amount - $spent;
        $dailyBudget    = (int) round($amount / $daysInMonth);
        $dailyRemaining = $daysLeft > 0 ? (int) round(max(0, $remaining) / $daysLeft) : 0;
        // Pace: what you "should" have spent by end of today at a flat rate.
        $expectedByNow = (int) round($dailyBudget * $day);

        return [
            'category'        => $category,           // '' = overall
            'month'           => $month,
            'monthly'         => $amount,
            'daily'           => $dailyBudget,
            'spent'           => $spent,
            'remaining'       => $remaining,          // negative => over budget
            'daily_remaining' => $dailyRemaining,     // spendable per remaining day
            'spent_pct'       => $amount > 0 ? round($spent / $amount * 100, 1) : 0,
            'days_in_month'   => $daysInMonth,
            'day'             => $day,
            'days_remaining'  => $daysLeft,
            'on_track'        => $spent <= $expectedByNow,
            'over_budget'     => $spent > $amount,
        ];
    }

    private function spent(string $category, string $month): int
    {
        if ($category === '') {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM transactions
                 WHERE cashflow = 'debit' AND is_self_transfer = 0
                   AND " . self::EXCLUDED . "
                   AND strftime('%Y-%m', txn_date) = ?"
            );
            $stmt->execute([$month]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM transactions
                 WHERE cashflow = 'debit' AND is_self_transfer = 0
                   AND category = ? AND strftime('%Y-%m', txn_date) = ?"
            );
            $stmt->execute([$category, $month]);
        }

        return (int) $stmt->fetchColumn();
    }
}
