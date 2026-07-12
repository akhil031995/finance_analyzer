<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Exclusions;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;

/**
 * The Analytics page: one report per period, over three period types —
 * a month, a calendar year (Jan–Dec), or an Indian financial year (Apr–Mar).
 *
 * Every figure is paise. The report answers, in order: what came in and went
 * out, how that compares with the previous period, where the money went, when
 * it went, what repeats every month, and what is worth telling the user.
 *
 * Two definitions matter and are used consistently everywhere:
 *
 *   EXPENSE excludes self-transfers, investments/EPF (that money is still
 *   yours) and credit-card bill payments (the card's own statement carries the
 *   real spending — counting the bill too would double-count it). Card payments
 *   are reported separately so the money is never simply missing.
 *
 *   COMMITMENTS are the expenses you cannot skip next month: EMIs, rent,
 *   insurance, subscriptions, utilities, telecom. The rest is discretionary.
 *   The split is what makes "you spent ₹40k" actionable.
 */
final class AnalyticsService
{
    /** Expenses you are contractually or practically locked into. */
    private const COMMITMENTS = ['emi', 'rent', 'insurance', 'subscription', 'utility', 'telecom_internet'];

    // Which tags count is a user setting, not a constant — see Exclusions.
    /** A raise, not a bonus: the new level must clear the old by this much AND hold. */
    private const HIKE_PCT = 3.0;
    /** Months of median either side of a candidate raise. Six absorbs a bonus or a part-month. */
    private const HIKE_WINDOW = 6;

    private const EXPENSE = "cashflow = 'debit'  AND is_self_transfer = 0 AND " . Exclusions::SQL;
    private const INCOME  = "cashflow = 'credit' AND is_self_transfer = 0 AND " . Exclusions::SQL;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string      $type  month | year | fy
     * @param string|null $anchor 'YYYY-MM' for month, 'YYYY' for year, FY start year for fy
     * @return array<string,mixed>
     */
    public function report(string $type, ?string $anchor, ?int $accountId = null): array
    {
        $latest = $this->pdo->query('SELECT MAX(txn_date) FROM transactions')->fetchColumn();
        if ($latest === false || $latest === null) {
            return ['has_data' => false];
        }

        $period = $this->resolvePeriod($type, $anchor, (string) $latest);
        $period = $this->addCoverage($period, (string) $latest, $accountId);
        $prev   = $period['prev'];

        $totals     = $this->totals($period['start'], $period['end'], $accountId);
        // Compare like with like. Half of July against all of June would report
        // a 55% "fall" in spending that is really just a shorter window.
        $prevTotals = $this->totals($prev['compare_start'], $prev['compare_end'], $accountId);

        $expenseByCat = $this->breakdown(self::EXPENSE, $period['start'], $period['end'], $accountId);
        $incomeByCat  = $this->breakdown(self::INCOME, $period['start'], $period['end'], $accountId);
        $prevByCat    = $this->breakdown(self::EXPENSE, $prev['compare_start'], $prev['compare_end'], $accountId);

        $averages = $this->averages($period, $totals, $accountId);
        $patterns = $this->patterns($period, $accountId);
        // Anchor the recurring window to where the ledger actually stops. Using
        // the period's nominal end (31 Mar for a financial year) would make every
        // live subscription look months overdue, and all of them get dropped.
        $recurring = $this->recurring($period['effective_end'], $accountId);

        $months = in_array($type, ['year', 'fy'], true)
            ? $this->monthlySeries($period['start'], $period['end'], $accountId)
            : [];

        // Salary progression is a multi-year story; only the yearly views show it.
        $salary = in_array($type, ['year', 'fy'], true)
            ? $this->salary($accountId, $period['start'], $period['end'])
            : null;

        $movers = $this->movers($expenseByCat, $prevByCat);

        return [
            'has_data'          => $totals['txn_count'] > 0,
            'period'            => $period,
            'available'         => $this->availablePeriods(),
            'totals'            => $totals,
            'prev_totals'       => $prevTotals,
            'deltas'            => $this->deltas($totals, $prevTotals),
            'expense_breakdown' => $expenseByCat,
            'income_breakdown'  => $incomeByCat,
            'excluded_breakdown' => $this->excludedBreakdown($period['start'], $period['end'], $accountId),
            'category_movers'   => $movers,
            'averages'          => $averages,
            'patterns'          => $patterns,
            'recurring'         => $recurring,
            'months'            => $months,
            'salary'            => $salary,
            'insights'          => $this->insights($period, $totals, $prevTotals, $expenseByCat,
                $movers, $averages, $patterns, $recurring),
        ];
    }

    // ------------------------------------------------------------------ period

    /** @return array<string,mixed> */
    private function resolvePeriod(string $type, ?string $anchor, string $latest): array
    {
        $latestDate = new DateTimeImmutable($latest);

        if ($type === 'year') {
            $year  = (int) ($anchor ?: $latestDate->format('Y'));
            $start = "{$year}-01-01";
            $end   = "{$year}-12-31";
            $prevY = $year - 1;

            return $this->withPrev($type, (string) $year, (string) $year, $start, $end,
                (string) $prevY, (string) $prevY, "{$prevY}-01-01", "{$prevY}-12-31");
        }

        if ($type === 'fy') {
            // The Indian financial year runs 1 Apr – 31 Mar; it is named by its
            // starting year, so Jan 2026 belongs to FY 2025-26.
            $default = (int) $latestDate->format('m') >= 4
                ? (int) $latestDate->format('Y')
                : (int) $latestDate->format('Y') - 1;
            $y  = (int) ($anchor ?: $default);
            $py = $y - 1;

            return $this->withPrev($type, (string) $y, $this->fyLabel($y), "{$y}-04-01", ($y + 1) . '-03-31',
                (string) $py, $this->fyLabel($py), "{$py}-04-01", ($py + 1) . '-03-31');
        }

        // month — default to the last month that actually finished. Statements
        // are historical: with a ledger ending on 1 July, defaulting to "July"
        // shows a single day and every average collapses.
        if ($anchor === null) {
            $anchor = (int) $latestDate->format('d') >= 28
                ? $latestDate->format('Y-m')
                : $latestDate->modify('first day of last month')->format('Y-m');
        }
        $first  = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor . '-01') ?: $latestDate->modify('first day of this month');
        $prevM  = $first->modify('-1 month');

        return $this->withPrev(
            'month',
            $first->format('Y-m'), $first->format('F Y'),
            $first->format('Y-m-01'), $first->format('Y-m-t'),
            $prevM->format('Y-m'), $prevM->format('F Y'),
            $prevM->format('Y-m-01'), $prevM->format('Y-m-t')
        );
    }

    /**
     * Annotate the period with the span the ledger actually covers, and build
     * the previous period's ALIGNED comparison window: the same number of days
     * from its start. Without that, a partial period always looks like a crash
     * in spending, and a partial year always looks like a great year.
     */
    private function addCoverage(array $period, string $latestTxn, ?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(txn_date), MAX(txn_date) FROM transactions WHERE txn_date BETWEEN ? AND ?'
            . $this->accountClause($accountId)
        );
        $stmt->execute($this->params([$period['start'], $period['end']], $accountId));
        [$from, $to] = $stmt->fetch(PDO::FETCH_NUM) ?: [null, null];

        // The ledger cannot know anything past its newest transaction, so that
        // — not the wall clock — bounds the elapsed window.
        $effectiveEnd = min($period['end'], $latestTxn);
        if ($effectiveEnd < $period['start']) {
            $effectiveEnd = $period['start'];
        }

        $span      = (new DateTimeImmutable($period['start']))->diff(new DateTimeImmutable($effectiveEnd))->days;
        $isPartial = $effectiveEnd < $period['end'];

        $period['data_from']     = $from;
        $period['data_to']       = $to;
        $period['effective_end'] = $effectiveEnd;
        $period['is_partial']    = $isPartial;
        $period['elapsed_days']  = $span + 1;

        // A COMPLETE period compares against the whole of the previous one —
        // that is what "vs May" means. Only a partial period needs truncating,
        // and then it must be truncated, or 9 days of July would read as a 90%
        // collapse in spending against all of June.
        $pStart = new DateTimeImmutable($period['prev']['start']);
        $pAlign = $pStart->modify("+{$span} days")->format('Y-m-d');

        $period['prev']['compare_start'] = $period['prev']['start'];
        $period['prev']['compare_end']   = $isPartial
            ? min($pAlign, $period['prev']['end'])
            : $period['prev']['end'];
        $period['prev']['aligned']       = $isPartial && $period['prev']['compare_end'] < $period['prev']['end'];

        return $period;
    }

    private function fyLabel(int $startYear): string
    {
        return 'FY ' . $startYear . '-' . substr((string) ($startYear + 1), 2);
    }

    /** @return array<string,mixed> */
    private function withPrev(string $type, string $anchor, string $label, string $start, string $end,
        string $pAnchor, string $pLabel, string $pStart, string $pEnd): array
    {
        return [
            'type'   => $type,
            'anchor' => $anchor,
            'label'  => $label,
            'start'  => $start,
            'end'    => $end,
            'days'   => (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1,
            'prev'   => ['anchor' => $pAnchor, 'label' => $pLabel, 'start' => $pStart, 'end' => $pEnd],
        ];
    }

    /** Periods the user actually has data for, newest first. */
    private function availablePeriods(): array
    {
        $months = $this->pdo->query(
            "SELECT DISTINCT strftime('%Y-%m', txn_date) m FROM transactions ORDER BY m DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $years = $this->pdo->query(
            "SELECT DISTINCT strftime('%Y', txn_date) y FROM transactions ORDER BY y DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        $fys = $this->pdo->query(
            "SELECT DISTINCT CASE WHEN CAST(strftime('%m', txn_date) AS INTEGER) >= 4
                        THEN CAST(strftime('%Y', txn_date) AS INTEGER)
                        ELSE CAST(strftime('%Y', txn_date) AS INTEGER) - 1 END fy
             FROM transactions ORDER BY fy DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        return [
            'months' => array_map(static fn ($m) => [
                'anchor' => $m,
                'label'  => (new DateTimeImmutable($m . '-01'))->format('F Y'),
            ], $months),
            'years' => array_map(static fn ($y) => ['anchor' => (string) $y, 'label' => (string) $y], $years),
            'fys'   => array_map(fn ($y) => ['anchor' => (string) $y, 'label' => $this->fyLabel((int) $y)], $fys),
        ];
    }

    // ------------------------------------------------------------------ totals

    /** @return array<string,int|float> */
    private function totals(string $start, string $end, ?int $accountId): array
    {
        $commitments = "'" . implode("','", self::COMMITMENTS) . "'";
        $sql = "SELECT
            COALESCE(SUM(CASE WHEN " . self::INCOME  . " THEN amount END), 0) AS income,
            COALESCE(SUM(CASE WHEN " . self::EXPENSE . " THEN amount END), 0) AS expense,
            COALESCE(SUM(CASE WHEN " . self::EXPENSE . " AND category = 'emi' THEN amount END), 0) AS emi,
            COALESCE(SUM(CASE WHEN " . self::EXPENSE . " AND category IN ({$commitments}) THEN amount END), 0) AS commitments,
            COALESCE(SUM(CASE WHEN cashflow = 'debit'  AND is_self_transfer = 0
                              AND " . Exclusions::INVERSE . " THEN amount END), 0) AS excluded_out,
            COALESCE(SUM(CASE WHEN cashflow = 'credit' AND is_self_transfer = 0
                              AND " . Exclusions::INVERSE . " THEN amount END), 0) AS excluded_in,
            COALESCE(SUM(CASE WHEN is_self_transfer = 1 AND cashflow = 'debit' THEN amount END), 0) AS self_transfers,
            COALESCE(SUM(CASE WHEN is_excluded = 1 THEN 1 END), 0) AS excluded_txns,
            COALESCE(SUM(CASE WHEN " . self::EXPENSE . " OR " . self::INCOME . " THEN 1 END), 0) AS txn_count
            FROM transactions WHERE txn_date BETWEEN ? AND ?" . $this->accountClause($accountId);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params([$start, $end], $accountId));
        $t = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));

        $t['discretionary'] = $t['expense'] - $t['commitments'];
        $t['net']           = $t['income'] - $t['expense'];
        // Excluded outflows (investments, PF, card bills) are not spending, so
        // whatever they consumed still counts as money kept.
        $t['savings_rate']  = $t['income'] > 0 ? round(($t['net'] / $t['income']) * 100, 1) : 0.0;
        $t['emi_burden']    = $t['income'] > 0 ? round(($t['emi'] / $t['income']) * 100, 1) : 0.0;

        return $t;
    }

    /** @return array<string,array{abs:int,pct:float|null}> */
    private function deltas(array $now, array $prev): array
    {
        $out = [];
        foreach (['income', 'expense', 'emi', 'commitments', 'discretionary', 'net', 'excluded_out'] as $k) {
            $abs = $now[$k] - $prev[$k];
            $out[$k] = [
                'abs' => $abs,
                // A percentage change from zero is undefined, not infinite.
                'pct' => $prev[$k] != 0 ? round($abs / abs($prev[$k]) * 100, 1) : null,
            ];
        }
        $out['savings_rate'] = ['abs' => round($now['savings_rate'] - $prev['savings_rate'], 1), 'pct' => null];

        return $out;
    }

    // --------------------------------------------------------------- breakdown

    /** @return list<array<string,mixed>> descending by amount, with share of total */
    private function breakdown(string $filter, string $start, string $end, ?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT category, SUM(amount) amount, COUNT(*) txns
             FROM transactions
             WHERE {$filter} AND txn_date BETWEEN ? AND ?" . $this->accountClause($accountId) . '
             GROUP BY category ORDER BY amount DESC'
        );
        $stmt->execute($this->params([$start, $end], $accountId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($rows, 'amount'));

        return array_map(static fn ($r) => [
            'category' => $r['category'],
            'amount'   => (int) $r['amount'],
            'txns'     => (int) $r['txns'],
            'pct'      => $total > 0 ? round((int) $r['amount'] / $total * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * What the excluded tags moved in this period, so the money is visible
     * somewhere rather than simply absent from every total.
     *
     * @return list<array<string,mixed>>
     */
    private function excludedBreakdown(string $start, string $end, ?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT category,
                    COALESCE(SUM(CASE WHEN cashflow = 'debit'  THEN amount END), 0) AS out_amount,
                    COALESCE(SUM(CASE WHEN cashflow = 'credit' THEN amount END), 0) AS in_amount,
                    COUNT(*) AS txns,
                    COALESCE(SUM(is_excluded), 0) AS manual_txns
             FROM transactions
             WHERE is_self_transfer = 0 AND " . Exclusions::INVERSE . '
               AND txn_date BETWEEN ? AND ?' . $this->accountClause($accountId) . '
             GROUP BY category ORDER BY (out_amount + in_amount) DESC'
        );
        $stmt->execute($this->params([$start, $end], $accountId));

        return array_map(static fn ($r) => [
            'category'    => $r['category'],
            'out_amount'  => (int) $r['out_amount'],
            'in_amount'   => (int) $r['in_amount'],
            'txns'        => (int) $r['txns'],
            // How many of these were blacklisted one-by-one rather than by tag.
            'manual_txns' => (int) $r['manual_txns'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Categories that moved most between the two periods, biggest swing first.
     * A category present in only one period still shows up (prev/now = 0).
     */
    private function movers(array $now, array $prev): array
    {
        $nowMap  = array_column($now, 'amount', 'category');
        $prevMap = array_column($prev, 'amount', 'category');

        $out = [];
        foreach (array_unique([...array_keys($nowMap), ...array_keys($prevMap)]) as $cat) {
            $a = $nowMap[$cat] ?? 0;
            $b = $prevMap[$cat] ?? 0;
            if ($a === 0 && $b === 0) {
                continue;
            }
            $out[] = [
                'category' => $cat,
                'now'      => $a,
                'prev'     => $b,
                'abs'      => $a - $b,
                'pct'      => $b !== 0 ? round(($a - $b) / $b * 100, 1) : null,
            ];
        }
        usort($out, static fn ($x, $y) => abs($y['abs']) <=> abs($x['abs']));

        return array_slice($out, 0, 8);
    }

    // ---------------------------------------------------------------- averages

    private function averages(array $period, array $totals, ?int $accountId): array
    {
        // Average over days the ledger actually covers. Dividing June's spend by
        // 30 when the statement stops on the 12th understates it by 60%; dividing
        // by days-until-today is just as wrong for a historical statement.
        $elapsed = max(1, (int) $period['elapsed_days']);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT txn_date) FROM transactions
             WHERE ' . self::EXPENSE . ' AND txn_date BETWEEN ? AND ?' . $this->accountClause($accountId)
        );
        $stmt->execute($this->params([$period['start'], $period['end']], $accountId));
        $activeDays = (int) $stmt->fetchColumn();

        $expenseTxns = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions
             WHERE ' . self::EXPENSE . ' AND txn_date BETWEEN ? AND ?' . $this->accountClause($accountId)
        );
        $expenseTxns->execute($this->params([$period['start'], $period['end']], $accountId));
        $n = (int) $expenseTxns->fetchColumn();

        return [
            'elapsed_days'    => $elapsed,
            'daily_expense'   => intdiv($totals['expense'], $elapsed),
            'daily_income'    => intdiv($totals['income'], $elapsed),
            'weekly_expense'  => (int) round($totals['expense'] / $elapsed * 7),
            'weekly_income'   => (int) round($totals['income'] / $elapsed * 7),
            'per_txn_expense' => $n > 0 ? intdiv($totals['expense'], $n) : 0,
            'active_days'     => $activeDays,
            'no_spend_days'   => max(0, $elapsed - $activeDays),
            'expense_txns'    => $n,
        ];
    }

    // ---------------------------------------------------------------- patterns

    private function patterns(array $period, ?int $accountId): array
    {
        $params = $this->params([$period['start'], $period['end']], $accountId);
        $acct   = $this->accountClause($accountId);

        // --- spend by weekday, averaged over how often that weekday occurred --
        $stmt = $this->pdo->prepare(
            "SELECT CAST(strftime('%w', txn_date) AS INTEGER) dow, SUM(amount) total, COUNT(*) txns
             FROM transactions WHERE " . self::EXPENSE . " AND txn_date BETWEEN ? AND ?{$acct}
             GROUP BY dow"
        );
        $stmt->execute($params);
        $byDow = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'dow');

        $occurrences = array_fill(0, 7, 0);
        $cursor = new DateTimeImmutable($period['start']);
        $stop   = (new DateTimeImmutable($period['end']))->modify('+1 day');
        foreach (new DatePeriod($cursor, new DateInterval('P1D'), $stop) as $d) {
            $occurrences[(int) $d->format('w')]++;
        }

        $labels  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $weekday = [];
        for ($i = 0; $i < 7; $i++) {
            $total = (int) ($byDow[$i]['total'] ?? 0);
            $weekday[] = [
                'dow'   => $i,
                'label' => $labels[$i],
                'total' => $total,
                'txns'  => (int) ($byDow[$i]['txns'] ?? 0),
                'avg'   => $occurrences[$i] > 0 ? intdiv($total, $occurrences[$i]) : 0,
            ];
        }

        // --- spend by day of month: is the month front- or back-loaded? -------
        $stmt = $this->pdo->prepare(
            "SELECT CAST(strftime('%d', txn_date) AS INTEGER) d, SUM(amount) total
             FROM transactions WHERE " . self::EXPENSE . " AND txn_date BETWEEN ? AND ?{$acct}
             GROUP BY d ORDER BY d"
        );
        $stmt->execute($params);
        $byDay = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total', 'd');
        $dayOfMonth = [];
        for ($d = 1; $d <= 31; $d++) {
            $dayOfMonth[] = ['day' => $d, 'total' => (int) ($byDay[$d] ?? 0)];
        }

        // --- who you actually pay ---------------------------------------------
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(counterparty), ''), description) name,
                    SUM(amount) amount, COUNT(*) txns, MIN(category) category
             FROM transactions WHERE " . self::EXPENSE . " AND txn_date BETWEEN ? AND ?{$acct}
             GROUP BY name ORDER BY amount DESC LIMIT 10"
        );
        $stmt->execute($params);
        $merchants = array_map(static fn ($r) => [
            'name' => mb_substr((string) $r['name'], 0, 40),
            'amount' => (int) $r['amount'], 'txns' => (int) $r['txns'], 'category' => $r['category'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // --- single biggest hits ----------------------------------------------
        $stmt = $this->pdo->prepare(
            'SELECT txn_date, description, counterparty, amount, category
             FROM transactions WHERE ' . self::EXPENSE . " AND txn_date BETWEEN ? AND ?{$acct}
             ORDER BY amount DESC LIMIT 8"
        );
        $stmt->execute($params);
        $largest = array_map(static fn ($r) => [
            'date' => $r['txn_date'],
            'name' => mb_substr((string) ($r['counterparty'] ?: $r['description']), 0, 44),
            'amount' => (int) $r['amount'], 'category' => $r['category'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'weekday'      => $weekday,
            'day_of_month' => $dayOfMonth,
            'top_merchants' => $merchants,
            'largest'      => $largest,
            'anomalies'    => $this->anomalies($period, $accountId),
        ];
    }

    /**
     * Transactions far larger than what that category normally costs.
     *
     * Uses the category's MEDIAN as the yardstick, not its mean: one ₹80,000
     * outlier drags a mean up until nothing looks unusual any more, which is
     * exactly the transaction we want to surface.
     */
    private function anomalies(array $period, ?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT txn_date, description, counterparty, amount, category
             FROM transactions WHERE ' . self::EXPENSE . ' AND txn_date BETWEEN ? AND ?'
             . $this->accountClause($accountId)
        );
        $stmt->execute($this->params([$period['start'], $period['end']], $accountId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byCat = [];
        foreach ($rows as $r) {
            $byCat[$r['category']][] = (int) $r['amount'];
        }
        $median = [];
        foreach ($byCat as $cat => $amounts) {
            sort($amounts);
            $n = count($amounts);
            $median[$cat] = $n % 2 ? $amounts[intdiv($n, 2)]
                : intdiv($amounts[$n / 2 - 1] + $amounts[$n / 2], 2);
        }

        $out = [];
        foreach ($rows as $r) {
            $m = $median[$r['category']] ?? 0;
            // Need a meaningful baseline (>= 5 txns) and a meaningful amount,
            // or every category with two rows reports an "anomaly".
            if ($m <= 0 || count($byCat[$r['category']]) < 5 || (int) $r['amount'] < 100000) {
                continue;
            }
            $ratio = (int) $r['amount'] / $m;
            if ($ratio >= 4) {
                $out[] = [
                    'date'   => $r['txn_date'],
                    'name'   => mb_substr((string) ($r['counterparty'] ?: $r['description']), 0, 44),
                    'amount' => (int) $r['amount'],
                    'category' => $r['category'],
                    'times_median' => round($ratio, 1),
                ];
            }
        }
        usort($out, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return array_slice($out, 0, 6);
    }

    // --------------------------------------------------------------- recurring

    /**
     * Payments that repeat on a cadence — subscriptions, EMIs, rent, bills.
     *
     * Detected over the 12 months ending where the ledger does, because you
     * cannot see a monthly rhythm inside a single month. Requires at least 3 payments
     * to the same counterparty, a consistent gap between them, and a stable
     * amount (the median absolute deviation stays under 20% of the median).
     */
    private function recurring(string $end, ?int $accountId): array
    {
        $from = (new DateTimeImmutable($end))->modify('-1 year')->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(counterparty), ''), description) name,
                    txn_date, amount, category
             FROM transactions WHERE " . self::EXPENSE . ' AND txn_date BETWEEN ? AND ?'
             . $this->accountClause($accountId) . ' ORDER BY name, txn_date'
        );
        $stmt->execute($this->params([$from, $end], $accountId));

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = strtoupper(trim((string) $r['name']));
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $r;
        }

        $items = [];
        foreach ($groups as $key => $rows) {
            if (count($rows) < 3) {
                continue;
            }

            $dates   = array_map(static fn ($r) => new DateTimeImmutable($r['txn_date']), $rows);
            $amounts = array_map(static fn ($r) => (int) $r['amount'], $rows);

            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = $dates[$i - 1]->diff($dates[$i])->days;
            }
            $gap = $this->median($gaps);

            $cadence = match (true) {
                $gap >= 6  && $gap <= 8   => 'weekly',
                $gap >= 13 && $gap <= 16  => 'fortnightly',
                $gap >= 26 && $gap <= 35  => 'monthly',
                $gap >= 85 && $gap <= 95  => 'quarterly',
                $gap >= 350 && $gap <= 380 => 'yearly',
                default => null,
            };
            if ($cadence === null) {
                continue;
            }

            // Amount stability: a variable UPI merchant is not a subscription.
            $medAmount = $this->median($amounts);
            if ($medAmount <= 0) {
                continue;
            }
            $spread = $this->median(array_map(static fn ($a) => abs($a - $medAmount), $amounts));
            if ($spread > $medAmount * 0.2) {
                continue;
            }

            $last = end($dates);

            // Still alive? A series whose last payment is long past its own
            // cadence has been cancelled — reporting "next expected" in the
            // past, and billing the user for it, is worse than omitting it.
            $overdueBy = $last->diff(new DateTimeImmutable($end))->days;
            if ($overdueBy > $gap * 2) {
                continue;
            }

            $items[] = [
                'name'          => mb_substr($key, 0, 40),
                'amount'        => $medAmount,
                'cadence'       => $cadence,
                'count'         => count($rows),
                'category'      => $rows[0]['category'],
                'last_seen'     => $last->format('Y-m-d'),
                'next_expected' => $last->modify('+' . $gap . ' days')->format('Y-m-d'),
                'overdue'       => $overdueBy > $gap,
                // Everything normalised to a per-month cost so they can be summed.
                'monthly_cost'  => (int) round($medAmount * match ($cadence) {
                    'weekly' => 52 / 12, 'fortnightly' => 26 / 12, 'monthly' => 1,
                    'quarterly' => 1 / 3, 'yearly' => 1 / 12, default => 0,
                }),
            ];
        }

        usort($items, static fn ($a, $b) => $b['monthly_cost'] <=> $a['monthly_cost']);

        return [
            'items'         => array_slice($items, 0, 15),
            'count'         => count($items),
            'monthly_total' => array_sum(array_column($items, 'monthly_cost')),
        ];
    }

    /** @param list<int> $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? (int) $values[intdiv($n, 2)] : (int) intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);
    }

    // ---------------------------------------------------------- monthly series

    /** One row per month in the period — the year view's full-width chart. */
    private function monthlySeries(string $start, string $end, ?int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT strftime('%Y-%m', txn_date) month,
                COALESCE(SUM(CASE WHEN " . self::INCOME  . " THEN amount END), 0) income,
                COALESCE(SUM(CASE WHEN " . self::EXPENSE . " THEN amount END), 0) expense,
                COALESCE(SUM(CASE WHEN " . self::EXPENSE . " AND category = 'emi' THEN amount END), 0) emi,
                COALESCE(SUM(CASE WHEN cashflow = 'debit' AND is_self_transfer = 0
                    AND " . Exclusions::INVERSE . " THEN amount END), 0) excluded
             FROM transactions WHERE txn_date BETWEEN ? AND ?" . $this->accountClause($accountId) . '
             GROUP BY month ORDER BY month'
        );
        $stmt->execute($this->params([$start, $end], $accountId));
        $found = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'month');

        // Emit every month of the period, so a zero month is a visible gap
        // rather than a missing row that silently compresses the chart.
        $out    = [];
        $cursor = new DateTimeImmutable($start);
        $stop   = new DateTimeImmutable($end);
        while ($cursor <= $stop) {
            $key = $cursor->format('Y-m');
            $r   = $found[$key] ?? [];
            $income  = (int) ($r['income'] ?? 0);
            $expense = (int) ($r['expense'] ?? 0);
            $out[] = [
                'month'      => $key,
                'label'      => $cursor->format('M'),
                'long_label' => $cursor->format('F Y'),
                'income'     => $income,
                'expense'    => $expense,
                'emi'        => (int) ($r['emi'] ?? 0),
                'excluded'   => (int) ($r['excluded'] ?? 0),
                'net'        => $income - $expense,
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return $out;
    }

    // ----------------------------------------------------------------- salary

    /**
     * Salary progression across the whole ledger, not just the selected period —
     * "progression" is a story about years, and the year picker only says which
     * slice to highlight and total up.
     *
     * Three things the real data forces:
     *
     *  - A month with no salary credit is a **gap**, not a zero. The bank
     *    occasionally pays on the 1st of the next month. Emitting 0 would draw a
     *    line plunging to the axis and imply you were not paid.
     *  - A bonus is not a raise. A hike is only recorded when the new level is
     *    *sustained*: the median of this month and the next two must clear the
     *    median of the previous three by the threshold. A one-month spike then
     *    falls back and never qualifies.
     *  - Every headline figure is a **median**, never a mean, for the same
     *    reason — one ₹2L bonus month would drag an average all year.
     *
     * @return array<string,mixed>|null null when the ledger has no salary at all
     */
    private function salary(?int $accountId, string $periodStart, string $periodEnd): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT strftime('%Y-%m', txn_date) month, SUM(amount) amount, COUNT(*) credits
             FROM transactions
             WHERE cashflow = 'credit' AND category = 'salary'
               AND is_self_transfer = 0 AND is_excluded = 0" . $this->accountClause($accountId) . '
             GROUP BY month ORDER BY month'
        );
        $stmt->execute($this->params([], $accountId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $paid = [];
        foreach ($rows as $r) {
            $paid[(string) $r['month']] = ['amount' => (int) $r['amount'], 'credits' => (int) $r['credits']];
        }

        // Every month between the first and last payslip, so a gap is visible.
        $series = [];
        $cursor = new DateTimeImmutable($rows[0]['month'] . '-01');
        $stop   = new DateTimeImmutable(end($rows)['month'] . '-01');
        while ($cursor <= $stop) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'month'      => $key,
                'long_label' => $cursor->format('F Y'),
                'amount'     => $paid[$key]['amount'] ?? null,     // null = not paid that month
                'credits'    => $paid[$key]['credits'] ?? 0,
                'in_period'  => $key >= substr($periodStart, 0, 7) && $key <= substr($periodEnd, 0, 7),
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        // Compare six PAID months either side, not three. The real ledger carries
        // one-off dips (a part-month of ₹65,998 between two ₹112,000 months) and
        // one-off spikes (a ₹2,07,378 bonus). A three-month median is moved by
        // either: the dip fabricates a "+51% raise" when the normal salary simply
        // resumes, and the spike fabricates one outright. Six months of median
        // absorbs both, and gaps are skipped rather than counted as zero.
        $paid = array_values(array_filter(
            array_map(static fn (array $s): ?array => $s['amount'] === null ? null : $s, $series)
        ));

        $hikes = [];
        $lastHikeAt = -PHP_INT_MAX;
        for ($k = self::HIKE_WINDOW, $n = count($paid); $k + self::HIKE_WINDOW <= $n; $k++) {
            if ($k - $lastHikeAt < self::HIKE_WINDOW) {
                continue;   // one raise, not six months of them
            }
            $before = $this->medianOrNull(array_column(array_slice($paid, $k - self::HIKE_WINDOW, self::HIKE_WINDOW), 'amount'));
            $after  = $this->medianOrNull(array_column(array_slice($paid, $k, self::HIKE_WINDOW), 'amount'));
            if ($before === null || $after === null || $before <= 0) {
                continue;
            }
            $pct = ($after - $before) / $before * 100;
            if ($pct >= self::HIKE_PCT) {
                $hikes[] = [
                    'month'      => $paid[$k]['month'],
                    'long_label' => $paid[$k]['long_label'],
                    'from'       => $before,
                    'to'         => $after,
                    'pct'        => round($pct, 1),
                ];
                $lastHikeAt = $k;
            }
        }

        // Calendar-year rollup, and the slice the period picker selected.
        $byYear = [];
        foreach ($series as $s) {
            if ($s['amount'] === null) {
                continue;
            }
            $y = substr($s['month'], 0, 4);
            $byYear[$y][] = $s['amount'];
        }
        $years = [];
        foreach ($byYear as $y => $vals) {
            $years[] = [
                'year'        => $y,
                'total'       => array_sum($vals),
                'months_paid' => count($vals),
                'median'      => $this->medianOrNull($vals),
            ];
        }

        $inPeriod = array_values(array_filter(array_map(
            static fn (array $s): ?int => $s['in_period'] ? $s['amount'] : null,
            $series
        ), static fn (?int $v): bool => $v !== null));

        $allPaid  = array_values(array_filter(array_column($series, 'amount'), static fn ($v) => $v !== null));
        $latest3  = array_slice($allPaid, -3);
        $first3   = array_slice($allPaid, 0, 3);
        $current  = $this->medianOrNull($latest3);
        $started  = $this->medianOrNull($first3);

        // Whole-ledger growth, and the compound annual rate that produced it.
        $spanYears = (strtotime(end($series)['month'] . '-01') - strtotime($series[0]['month'] . '-01')) / (365.25 * 86400);
        $cagr = ($started > 0 && $current > 0 && $spanYears >= 1)
            ? round(((($current / $started) ** (1 / $spanYears)) - 1) * 100, 1)
            : null;

        return [
            'series'  => $series,
            'hikes'   => $hikes,
            'by_year' => $years,
            'period'  => [
                'total'       => array_sum($inPeriod),
                'months_paid' => count($inPeriod),
                'median'      => $this->medianOrNull($inPeriod),
            ],
            'current'      => $current,          // median of the last three payslips
            'started'      => $started,
            'growth_pct'   => ($started > 0 && $current !== null) ? round(($current - $started) / $started * 100, 1) : null,
            'cagr_pct'     => $cagr,
            'span_years'   => round($spanYears, 1),
            'gap_months'   => count(array_filter($series, static fn (array $s): bool => $s['amount'] === null)),
        ];
    }

    /**
     * median() returns 0 for an empty set, which is a real salary of zero as far
     * as arithmetic is concerned. Salary needs "no data" to stay distinguishable
     * from "paid nothing", so it uses this.
     *
     * @param list<int> $values
     */
    private function medianOrNull(array $values): ?int
    {
        return $values === [] ? null : $this->median(array_values($values));
    }

    // ---------------------------------------------------------------- insights

    /**
     * Turn the numbers into sentences worth reading. Each insight names a
     * figure and, where it can, what to do about it. Ordered by how much it
     * should change the reader's behaviour.
     */
    private function insights(array $period, array $t, array $p, array $expenseByCat,
        array $movers, array $avg, array $patterns, array $recurring): array
    {
        $out  = [];
        $rs   = static fn (int $paise) => '₹' . number_format($paise / 100, 0, '.', ',');
        $prevLabel = $period['prev']['label'] . ($period['prev']['aligned'] ? ' (same window)' : '');

        // Nothing meaningful to say about a period with no spending in it, and
        // "spending is down 100%" would be actively misleading.
        if ($t['expense'] === 0 && $t['income'] === 0) {
            return [['tone' => 'info', 'title' => 'No transactions in ' . $period['label'],
                'detail' => 'Import a statement covering this period, or pick another period above.']];
        }

        // --- cashflow -------------------------------------------------------
        if ($t['income'] > 0 && $t['expense'] > 0) {
            if ($t['net'] < 0) {
                $out[] = ['tone' => 'bad', 'title' => 'You spent more than you earned',
                    'detail' => sprintf('%s went out against %s in. You are %s down over %s.',
                        $rs($t['expense']), $rs($t['income']), $rs(abs($t['net'])), $period['label'])];
            } else {
                $out[] = ['tone' => 'good', 'title' => sprintf('You kept %.0f%% of what you earned', $t['savings_rate']),
                    'detail' => sprintf('%s saved from %s of income.', $rs($t['net']), $rs($t['income']))];
            }
        }

        // --- spend vs the previous period ------------------------------------
        if ($p['expense'] > 0 && $t['expense'] > 0) {
            $d   = $t['expense'] - $p['expense'];
            $pct = round($d / $p['expense'] * 100, 1);
            if (abs($pct) >= 5) {
                $out[] = ['tone' => $d > 0 ? 'warn' : 'good',
                    'title' => sprintf('Spending is %s %.0f%% vs %s', $d > 0 ? 'up' : 'down', abs($pct), $prevLabel),
                    'detail' => sprintf('%s versus %s — a %s of %s.', $rs($t['expense']), $rs($p['expense']),
                        $d > 0 ? 'rise' : 'fall', $rs(abs($d)))];
            }
        }

        // --- the single biggest category move --------------------------------
        foreach ($movers as $m) {
            if ($m['abs'] > 0 && $m['prev'] > 0 && $m['abs'] >= 100000) {
                $out[] = ['tone' => 'warn', 'title' => sprintf('%s rose %s', self::pretty($m['category']), $rs($m['abs'])),
                    'detail' => $m['pct'] !== null
                        ? sprintf('Up %.0f%% on %s, now %s.', $m['pct'], $prevLabel, $rs($m['now']))
                        : sprintf('Now %s.', $rs($m['now']))];
                break;
            }
        }

        // --- EMI burden -------------------------------------------------------
        if ($t['emi'] > 0 && $t['income'] > 0) {
            $tone = $t['emi_burden'] >= 40 ? 'bad' : ($t['emi_burden'] >= 30 ? 'warn' : 'info');
            $out[] = ['tone' => $tone, 'title' => sprintf('EMIs take %.0f%% of your income', $t['emi_burden']),
                'detail' => sprintf('%s of %s. %s', $rs($t['emi']), $rs($t['income']),
                    $t['emi_burden'] >= 40 ? 'Lenders treat anything above 40% as stressed.'
                        : ($t['emi_burden'] >= 30 ? 'Above 30% leaves little room for a shock.'
                            : 'Comfortably inside the 30% guideline.'))];
        }

        // --- what is locked in vs what you choose -----------------------------
        if ($t['expense'] > 0 && $t['commitments'] > 0) {
            $share = round($t['commitments'] / $t['expense'] * 100);
            $out[] = ['tone' => $share >= 60 ? 'warn' : 'info',
                'title' => sprintf('%d%% of spending is committed', $share),
                'detail' => sprintf('%s in EMIs, rent, insurance, subscriptions and bills; %s discretionary. '
                    . 'Only the discretionary half can be cut this month.',
                    $rs($t['commitments']), $rs($t['discretionary']))];
        }

        // --- concentration ----------------------------------------------------
        if (count($expenseByCat) >= 3) {
            $top3 = array_sum(array_column(array_slice($expenseByCat, 0, 3), 'amount'));
            if ($t['expense'] > 0) {
                $out[] = ['tone' => 'info',
                    'title' => sprintf('Top 3 categories are %d%% of spending', (int) round($top3 / $t['expense'] * 100)),
                    'detail' => implode(', ', array_map(
                        fn ($c) => self::pretty($c['category']) . ' ' . $rs($c['amount']),
                        array_slice($expenseByCat, 0, 3)))];
            }
        }

        // --- recurring --------------------------------------------------------
        if ($recurring['count'] > 0) {
            $out[] = ['tone' => 'info',
                'title' => sprintf('%d recurring payments cost %s a month', $recurring['count'], $rs($recurring['monthly_total'])),
                'detail' => sprintf('That is %s a year, before you decide anything.', $rs($recurring['monthly_total'] * 12))];
        }

        // --- rhythm -----------------------------------------------------------
        $weekday = $patterns['weekday'];
        usort($weekday, static fn ($a, $b) => $b['avg'] <=> $a['avg']);
        if ($weekday[0]['avg'] > 0 && $weekday[6]['avg'] > 0 && $weekday[0]['avg'] >= $weekday[6]['avg'] * 1.5) {
            $out[] = ['tone' => 'info', 'title' => sprintf('%sdays cost you most', $weekday[0]['label']),
                'detail' => sprintf('%s on an average %sday against %s on a %sday.',
                    $rs($weekday[0]['avg']), $weekday[0]['label'], $rs($weekday[6]['avg']), $weekday[6]['label'])];
        }

        if ($avg['no_spend_days'] > 0 && $avg['active_days'] > 0) {
            $out[] = ['tone' => 'good', 'title' => sprintf('%d no-spend days', $avg['no_spend_days']),
                'detail' => sprintf('You spent on %d of %d days, averaging %s a day overall.',
                    $avg['active_days'], $avg['elapsed_days'], $rs($avg['daily_expense']))];
        }

        // --- anomalies --------------------------------------------------------
        if ($patterns['anomalies'] !== []) {
            $a = $patterns['anomalies'][0];
            $out[] = ['tone' => 'warn',
                'title' => sprintf('%d unusually large %s', count($patterns['anomalies']),
                    count($patterns['anomalies']) === 1 ? 'transaction' : 'transactions'),
                'detail' => sprintf('Biggest: %s of %s on %s — %.1f× the usual %s.',
                    $a['name'], $rs($a['amount']), $a['date'], $a['times_median'], self::pretty($a['category']))];
        }

        // --- the elephant: untagged spend -------------------------------------
        $other = array_values(array_filter($expenseByCat, static fn ($c) => $c['category'] === 'other_expense'));
        if ($other !== [] && $t['expense'] > 0 && $other[0]['pct'] >= 25) {
            $out[] = ['tone' => 'warn', 'title' => sprintf('%.0f%% of spending is uncategorised', $other[0]['pct']),
                'detail' => sprintf('%s across %d transactions sits in "other". Tag a few in Review and use '
                    . '+rule — every future statement then classifies them for you.',
                    $rs($other[0]['amount']), $other[0]['txns'])];
        }

        // --- excluded tags, so the money is never unaccounted for --------------
        if ($t['excluded_out'] > 0 || $t['excluded_in'] > 0) {
            $names = implode(', ', array_map([self::class, 'pretty'], Exclusions::all($this->pdo)));
            $parts = [];
            if ($t['excluded_out'] > 0) {
                $parts[] = $rs($t['excluded_out']) . ' out';
            }
            if ($t['excluded_in'] > 0) {
                $parts[] = $rs($t['excluded_in']) . ' in';
            }
            $out[] = ['tone' => 'info', 'title' => implode(' and ', $parts) . ' is excluded from these figures',
                'detail' => "Excluded tags ({$names}) still change your account balance, they just don't count "
                    . 'as income or spending. Change the list in Settings.'];
        }

        return $out;
    }

    public static function pretty(string $category): string
    {
        return ucwords(str_replace('_', ' ', $category));
    }

    // ------------------------------------------------------------------ helpers

    private function accountClause(?int $accountId): string
    {
        return $accountId === null ? '' : ' AND account_id = ?';
    }

    /** @param list<string> $base */
    private function params(array $base, ?int $accountId): array
    {
        return $accountId === null ? $base : [...$base, $accountId];
    }
}
