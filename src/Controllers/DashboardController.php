<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Loan\LoanService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/dashboard — one call returning everything the dashboard renders:
 * net worth, per-account balances, daily/monthly average expense, the
 * month-over-month spend delta, the ₹10,000 net-worth ladder, and a monthly
 * income/expense series. All money is paise; the UI divides by 100.
 *
 * Reads the analytics views (v_net_worth, v_monthly_cashflow, v_daily_expense)
 * so self-transfers and investment/EPF flows are already excluded from
 * income/expense figures.
 */
final class DashboardController
{
    /** How many recent complete months the "monthly average" card averages over. */
    private const AVERAGE_MONTHS = 12;

    public function __construct(private PDO $pdo, private LoanService $loans)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $netWorth  = $this->netWorth();
        $months    = $this->monthlySeries();

        // Milestones are NOT detected here. This is a GET, and crossing one wrote
        // to the database on the request path — an fsync this box pays ~680 ms
        // for. DailySummaryService::sendDaily() calls detectMilestones() before
        // it composes or sends anything, so cron records them once a day
        // regardless of whether Telegram is configured. A rung still renders as
        // "reached" the instant net worth passes it (that is derived from the
        // live figure); only its achieved_on date waits for the nightly run.

        $payload = [
            'net_worth'   => $netWorth,
            'accounts'    => $this->accountBreakdown(),
            'averages'    => $this->averages($months),
            'mom'         => $this->monthOverMonth($months),
            'ladder'      => $this->ladder((int) $netWorth['net']),
            'debt_ladder' => $this->debtLadder(),
            'months'      => $months,
            'net_worth_history' => $this->netWorthHistory(),
            'budget'      => (new \App\Services\BudgetService($this->pdo))->overallStatus(),
            'has_data'    => $this->pdo->query('SELECT EXISTS(SELECT 1 FROM transactions)')->fetchColumn() === 1,
            // Accounts whose ledger does not add up to their statement. Reported,
            // never auto-corrected: a net worth built on a silently broken ledger
            // is worse than no net worth at all.
            'health'      => array_values(array_filter(
                (new \App\Services\AccountHealth($this->pdo))->all(),
                static fn ($h) => !$h['reconciled'] || $h['opening_drift'] !== 0
            )),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return array{assets:int, liabilities:int, net:int} */
    private function netWorth(): array
    {
        $r = $this->pdo->query('SELECT total_assets, total_liabilities, net_worth FROM v_net_worth')
            ->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'assets'      => (int) ($r['total_assets'] ?? 0),
            'liabilities' => (int) ($r['total_liabilities'] ?? 0),
            'net'         => (int) ($r['net_worth'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function accountBreakdown(): array
    {
        return $this->pdo->query(
            'SELECT id, name, type, is_liability, is_derived, current_balance, color
             FROM accounts WHERE is_archived = 0 AND include_in_networth = 1
             ORDER BY is_liability, current_balance DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Monthly income/expense series (oldest→newest) from v_monthly_cashflow.
     * @return list<array{month:string, income:int, expense:int}>
     */
    private function monthlySeries(): array
    {
        $rows = $this->pdo->query(
            'SELECT month, total_income, total_expense FROM v_monthly_cashflow ORDER BY month'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => [
            'month'   => $r['month'],
            'income'  => (int) $r['total_income'],
            'expense' => (int) $r['total_expense'],
        ], $rows);
    }

    /**
     * @param list<array{month:string,income:int,expense:int}> $months
     * @return array{daily_expense:int, monthly_expense:int, window_days:int}
     */
    private function averages(array $months): array
    {
        // Daily average over the most recent 30 days of activity. Anchored to
        // the latest transaction date, not wall-clock now, so a back-dated
        // statement still yields a meaningful figure.
        $latest = $this->pdo->query('SELECT MAX(txn_date) FROM transactions')->fetchColumn();
        $dailyExpense = 0;
        if ($latest) {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(total_expense), 0) FROM v_daily_expense
                 WHERE txn_date > date(?, "-30 days") AND txn_date <= ?'
            );
            $stmt->execute([$latest, $latest]);
            $dailyExpense = (int) round(((int) $stmt->fetchColumn()) / 30);
        }

        // Monthly average across complete months (exclude the latest, which is
        // usually partial) when we have more than one month of data.
        $complete = count($months) > 1 ? array_slice($months, 0, -1) : $months;

        // ...but only the most recent AVERAGE_MONTHS of them. Averaging the whole
        // ledger answers a question nobody asks: this one goes back to 2019, and
        // 25 of those months predate the first loan, which dragged the EMI share
        // down to 28% when it is really about two thirds of the outgo. A rolling
        // year is long enough to absorb an unusual month and short enough to
        // describe the life you are actually living.
        $complete = array_slice($complete, -self::AVERAGE_MONTHS);

        $monthlyExpense = $complete === []
            ? 0
            : (int) round(array_sum(array_column($complete, 'expense')) / count($complete));

        // EMI is an ordinary expense — it is not in `excluded_categories` — and on
        // a ledger carrying three loans it dominates the average. Split it out:
        // the EMI is contractual, the rest is the part you can actually steer.
        //
        // A lump-sum prepayment tagged `emi` is counted here as EMI rather than
        // separated out. It is a deliberate simplification: the month you pay one
        // off reads high, but every rupee is still a rupee that left.
        $emiByMonth = [];
        foreach ($this->pdo->query(
            "SELECT strftime('%Y-%m', txn_date) m, SUM(amount) s
             FROM transactions
             WHERE cashflow = 'debit' AND category = 'emi'
               AND is_self_transfer = 0 AND is_excluded = 0
             GROUP BY m"
        ) as $r) {
            $emiByMonth[(string) $r['m']] = (int) $r['s'];
        }

        $monthlyEmi = 0;
        if ($complete !== []) {
            $sum = 0;
            foreach ($complete as $m) {
                $sum += $emiByMonth[$m['month']] ?? 0;
            }
            $monthlyEmi = (int) round($sum / count($complete));
        }

        return [
            'daily_expense'          => $dailyExpense,
            'monthly_expense'        => $monthlyExpense,
            'monthly_emi'            => $monthlyEmi,
            'monthly_expense_ex_emi' => max(0, $monthlyExpense - $monthlyEmi),
            'complete_months'        => count($complete),
            'window_days'            => 30,
        ];
    }

    /**
     * Compare the two most recent months that actually have data (not calendar
     * now — the statement may be historical).
     * @param list<array{month:string,income:int,expense:int}> $months
     */
    private function monthOverMonth(array $months): array
    {
        $n = count($months);
        $this_  = $n >= 1 ? $months[$n - 1] : null;
        $prev   = $n >= 2 ? $months[$n - 2] : null;

        // A month still in progress is not a month's spending. Comparing 10 days
        // of July against all of June reported a 100% collapse that never
        // happened. Drop the running month and compare the last two months that
        // actually finished — the same "completed months" rule the average uses.
        $lastTxn   = (string) ($this->pdo->query('SELECT MAX(txn_date) FROM transactions')->fetchColumn() ?: date('Y-m-d'));
        $partial   = $this_ !== null
            && $this_['month'] === substr($lastTxn, 0, 7)
            && (int) date('d', strtotime($lastTxn)) < (int) date('t', strtotime($lastTxn));

        if ($partial) {
            $this_ = $n >= 2 ? $months[$n - 2] : null;
            $prev  = $n >= 3 ? $months[$n - 3] : null;
        }

        if ($this_ === null || $prev === null || (int) $prev['expense'] === 0) {
            return [
                'this_month'         => $this_['month'] ?? null,
                'this_month_expense' => (int) ($this_['expense'] ?? 0),
                'prev_month'         => $prev['month'] ?? null,
                'prev_month_expense' => (int) ($prev['expense'] ?? 0),
                'pct_change'         => null,
                'skipped_partial'    => $partial,
            ];
        }

        return [
            'this_month'         => $this_['month'],
            'this_month_expense' => (int) $this_['expense'],
            'prev_month'         => $prev['month'],
            'prev_month_expense' => (int) $prev['expense'],
            'pct_change'         => round(((int) $this_['expense'] - (int) $prev['expense']) / (int) $prev['expense'] * 100, 1),
            // True when the running month was left out, so the card can say which
            // two months it is actually comparing.
            'skipped_partial'    => $partial,
        ];
    }

    /**
     * The gamified ₹10,000-step milestone ladder — fixed step, fixed target of
     * N milestones (default 100 = ₹10L; grows if net worth exceeds it). Every
     * step is a rung; reached rungs carry the date they were first crossed
     * (from the persisted milestones table). Rendered as a horizontal track.
     */
    private function ladder(int $netWorthPaise): array
    {
        $step = (int) ($this->pdo->query("SELECT value FROM settings WHERE key = 'ladder_step_paise'")
            ->fetchColumn() ?: 1000000);
        $goalSteps = (int) ($this->pdo->query("SELECT value FROM settings WHERE key = 'ladder_total_steps'")
            ->fetchColumn() ?: 100);

        $currentStep = $netWorthPaise > 0 ? intdiv($netWorthPaise, $step) : 0;
        $totalSteps  = max($goalSteps, $currentStep + 10);   // always show headroom
        $nextValue   = ($currentStep + 1) * $step;
        $intoStep    = $netWorthPaise > 0 ? $netWorthPaise % $step : 0;
        $progressPct = round(($intoStep / $step) * 100, 1);

        // amount(paise) => date first reached, from the persisted milestones.
        $dates = [];
        foreach ($this->pdo->query("SELECT amount, achieved_on FROM milestones WHERE kind = 'net_worth'") as $m) {
            $dates[(int) $m['amount']] = $m['achieved_on'];
        }

        $rungs = [];
        for ($i = 1; $i <= $totalSteps; $i++) {
            $value = $i * $step;
            $rungs[] = [
                'index'       => $i,
                'value'       => $value,
                'reached'     => $netWorthPaise >= $value,
                'is_next'     => $i === $currentStep + 1,
                'achieved_on' => $dates[$value] ?? null,
            ];
        }

        return [
            'step'           => $step,
            'net_worth'      => $netWorthPaise,
            'current_step'   => $currentStep,        // milestones reached
            'total_steps'    => $totalSteps,         // the "of N" target
            'next_milestone' => $nextValue,
            'progress_pct'   => $progressPct,        // % into the current step
            'rungs'          => $rungs,
        ];
    }

    /**
     * Debt-paydown progress: how much of the original principal has been paid.
     * outstanding = Σ current balances. For `original`, a loan account takes the
     * amount actually **drawn** from its schedule; every other liability takes
     * `debt_details.principal_amount`, falling back to its current balance.
     *
     * The fallback is why this used to report zero progress forever: a loan
     * account is derived and has no `debt_details` row, so `original` collapsed
     * to `current_balance` and `paid` was structurally always 0.
     *
     * Returns null with no liabilities.
     */
    private function debtLadder(): ?array
    {
        $rows = $this->pdo->query(
            'SELECT a.id, a.current_balance, a.is_derived, d.principal_amount
             FROM accounts a LEFT JOIN debt_details d ON d.account_id = a.id
             WHERE a.is_liability = 1 AND a.is_archived = 0'
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $drawn = $this->loans->drawnByAccount();

        $original = 0;
        $outstanding = 0;
        foreach ($rows as $r) {
            $cb = (int) $r['current_balance'];
            $outstanding += $cb;

            if ((int) $r['is_derived'] === 1 && isset($drawn[(int) $r['id']])) {
                $original += $drawn[(int) $r['id']];
                continue;
            }
            $principal = (int) ($r['principal_amount'] ?? 0);
            $original += $principal > 0 ? $principal : $cb;
        }
        $paid = max(0, $original - $outstanding);

        return [
            'original'    => $original,
            'outstanding' => $outstanding,
            'paid'        => $paid,
            'paid_pct'    => $original > 0 ? round(($paid / $original) * 100, 1) : 0,
        ];
    }

    /**
     * Net worth AND debt over time, reconstructed from account opening balances
     * + the transaction ledger (one end-of-day point per active date). This
     * gives a full historical curve immediately, rather than waiting for daily
     * balance_snapshots to accumulate. The final point reconciles with the live
     * v_net_worth figures. Down-sampled to ~120 points.
     *
     * @return list<array{date:string, net_worth:int, debt:int}>
     */
    private function netWorthHistory(): array
    {
        // Derived (loan) accounts are excluded here on purpose. They carry no
        // ledger rows, so walking them out of `transactions` would freeze their
        // opening balance across the whole curve — showing a 2022 home loan as
        // debt in 2019 and never amortising it. Their debt is added per point
        // below, from the schedule.
        $accts = $this->pdo->query(
            'SELECT is_liability, opening_balance FROM accounts
             WHERE include_in_networth = 1 AND is_archived = 0 AND is_derived = 0'
        )->fetchAll(PDO::FETCH_ASSOC);

        $assets = 0;
        $liab   = 0;
        foreach ($accts as $a) {
            if ($a['is_liability']) {
                $liab += (int) $a['opening_balance'];
            } else {
                $assets += (int) $a['opening_balance'];
            }
        }

        $rows = $this->pdo->query(
            "SELECT t.txn_date, t.amount, t.cashflow, a.is_liability
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE a.include_in_networth = 1 AND a.is_archived = 0 AND a.is_derived = 0
             ORDER BY t.txn_date, t.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            $today = date('Y-m-d');
            $debt  = $liab + ($this->loans->debtOn([$today])[$today] ?? 0);

            return [['date' => $today, 'net_worth' => $assets - $debt, 'debt' => $debt]];
        }

        // Anchor point at the opening baseline, the day before the first txn.
        $byDate = [];
        $firstDate = $rows[0]['txn_date'];
        $byDate[date('Y-m-d', strtotime($firstDate . ' -1 day'))] = ['assets' => $assets, 'liab' => $liab];

        foreach ($rows as $r) {
            $amt = (int) $r['amount'];
            if ($r['is_liability']) {
                // Debit = borrow/spend (owe more); credit = payment (owe less).
                $liab += ($r['cashflow'] === 'debit') ? $amt : -$amt;
            } else {
                $assets += ($r['cashflow'] === 'credit') ? $amt : -$amt;
            }
            $byDate[$r['txn_date']] = ['assets' => $assets, 'liab' => $liab];
        }

        $series = [];
        foreach ($byDate as $date => $v) {
            $series[] = ['date' => $date, 'assets' => $v['assets'], 'liab' => $v['liab']];
        }

        // Down-sample first, then price the loans: one schedule lookup per point
        // we actually return, not per transaction date.
        $n = count($series);
        if ($n > 120) {
            $stride = (int) ceil($n / 120);
            $out = [];
            for ($i = 0; $i < $n; $i += $stride) {
                $out[] = $series[$i];
            }
            if (end($out)['date'] !== $series[$n - 1]['date']) {
                $out[] = $series[$n - 1];
            }
            $series = $out;
        }

        $loanDebt = $this->loans->debtOn(array_column($series, 'date'));

        return array_map(static function (array $p) use ($loanDebt): array {
            $debt = max(0, $p['liab']) + ($loanDebt[$p['date']] ?? 0);

            return ['date' => $p['date'], 'net_worth' => $p['assets'] - $debt, 'debt' => $debt];
        }, $series);
    }
}
