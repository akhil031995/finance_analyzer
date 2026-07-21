<?php

declare(strict_types=1);

namespace App\Services\Investment;

use App\Support\Palette;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Investments — the asset-side mirror of LoanService.
 *
 * Each holding owns a derived asset account whose balance is its latest
 * valuation, so net worth needs no changes. Returns are money-weighted (XIRR)
 * over the events; projections compound the current value (and, optionally, an
 * ongoing SIP) forward at a rate you set — falling back to the holding's own
 * realised XIRR, then to a conservative default for its instrument type.
 *
 * Nothing about a holding is derived on write except the account balance and the
 * is_closed flag; every figure (XIRR, gain, projection) is computed on read from
 * the events + valuations, exactly as loan schedules are.
 */
final class InvestmentService
{
    /** Money in (a cost) vs money back, for the XIRR sign. */
    private const OUTFLOWS = ['buy', 'contribution'];
    private const INFLOWS  = ['sell', 'withdrawal', 'dividend'];

    /** Projection fallback when a holding has neither an expected rate nor XIRR. */
    public const DEFAULT_RATES = [
        'equity'      => 11.0,
        'mutual_fund' => 11.0,
        'gold'        => 7.0,
        'fd_rd'       => 6.5,
        'bond'        => 7.0,
        'ppf_epf'     => 7.1,
        'crypto'      => 10.0,
        'real_estate' => 8.0,
        'nps'         => 9.0,
        'insurance'   => 6.0,
        'other'       => 8.0,
    ];

    public const TYPES = [
        'equity', 'mutual_fund', 'gold', 'fd_rd', 'bond', 'ppf_epf',
        'crypto', 'real_estate', 'nps', 'insurance', 'other',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    // -- reads ---------------------------------------------------------------

    /** @return list<array<string,mixed>> every holding with its headline figures */
    public function listInvestments(?DateTimeImmutable $today = null): array
    {
        $ids = $this->pdo->query('SELECT id FROM investments ORDER BY is_closed, name')
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map(fn ($id): array => $this->summary((int) $id, $today), $ids);
    }

    /** The full report for one holding: events, valuations, returns, projection. */
    public function report(int $id, ?DateTimeImmutable $today = null): array
    {
        $inv = $this->find($id);
        $events = $this->events($id);
        $valuations = $this->valuations($id);
        $today ??= new DateTimeImmutable('today');

        $returns = $this->returns($inv, $events, $valuations, $today);

        return [
            'investment' => $inv,
            'events'     => $events,
            'valuations' => $valuations,
            'returns'    => $returns,
            'projection' => $this->project($inv, $returns, 0, 120, $today),   // default 10y, no SIP
        ];
    }

    /**
     * Portfolio rollup across every holding.
     *
     * `$rows` lets a caller that has already built the list pass it in — the
     * index endpoint returns both, and recomputing meant running every
     * holding's XIRR and valuation queries twice per request.
     *
     * @param list<array<string,mixed>>|null $rows result of listInvestments()
     */
    public function portfolio(?DateTimeImmutable $today = null, ?array $rows = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $rows ??= $this->listInvestments($today);

        $invested = 0;
        $current  = 0;
        $realised = 0;
        $flows    = [];
        foreach ($rows as $r) {
            if ($r['is_closed']) {
                $realised += $r['returns']['realised_gain'];
                continue;
            }
            $invested += $r['returns']['net_invested'];
            $current  += $r['returns']['current_value'];
        }

        // A portfolio XIRR is the XIRR of ALL holdings' flows pooled together —
        // not an average of their rates, which would ignore how much sits in each.
        foreach ($rows as $r) {
            foreach ($r['returns']['flows'] as $f) {
                $flows[] = $f;
            }
        }
        usort($flows, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        $byType = [];
        foreach ($rows as $r) {
            if ($r['is_closed']) {
                continue;
            }
            $t = $r['instrument_type'];
            $byType[$t] = ($byType[$t] ?? 0) + $r['returns']['current_value'];
        }
        arsort($byType);

        return [
            'count'          => count($rows),
            'open_count'     => count(array_filter($rows, static fn ($r) => !$r['is_closed'])),
            'net_invested'   => $invested,
            'current_value'  => $current,
            'unrealised_gain' => $current - $invested,
            'realised_gain'  => $realised,
            'xirr'           => Xirr::rate($flows),
            'by_type'        => array_map(
                static fn ($t, $v) => ['type' => $t, 'value' => $v],
                array_keys($byType),
                array_values($byType)
            ),
        ];
    }

    // -- returns / XIRR ------------------------------------------------------

    /**
     * @param array<string,mixed> $inv
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $valuations
     */
    private function returns(array $inv, array $events, array $valuations, DateTimeImmutable $today): array
    {
        $invested = 0;   // buys + contributions
        $withdrawn = 0;  // sells + withdrawals + dividends
        $flows = [];
        foreach ($events as $e) {
            $amt = (int) $e['amount'];
            if (in_array($e['event_type'], self::OUTFLOWS, true)) {
                $invested += $amt;
                $flows[] = ['date' => $e['event_date'], 'amount' => -$amt];
            } else {
                $withdrawn += $amt;
                $flows[] = ['date' => $e['event_date'], 'amount' => $amt];
            }
        }

        $latest = $valuations[0] ?? null;                 // valuations() is newest-first
        $currentValue = $latest !== null ? (int) $latest['value'] : 0;
        $valuedOn = $latest['valued_on'] ?? null;

        // The current worth is a final inflow, dated today, so XIRR captures the
        // unrealised gain still sitting in the holding.
        $xirrFlows = $flows;
        if ($currentValue > 0) {
            $xirrFlows[] = ['date' => $today->format('Y-m-d'), 'amount' => $currentValue];
        }

        $netInvested = $invested - $withdrawn;            // money still at risk
        $totalValue  = $currentValue + $withdrawn;        // everything the holding has returned + is worth
        $absoluteGain = $totalValue - $invested;

        return [
            'invested'        => $invested,
            'withdrawn'       => $withdrawn,
            'net_invested'    => max(0, $netInvested),
            'current_value'   => $currentValue,
            'valued_on'       => $valuedOn,
            'absolute_gain'   => $absoluteGain,
            // Realised only once the holding is closed (fully sold); an open
            // holding's gain sits unrealised in current_value.
            'realised_gain'   => (bool) $inv['is_closed'] ? $absoluteGain : 0,
            'simple_return_pct' => $invested > 0 ? round($absoluteGain / $invested * 100, 1) : null,
            'xirr'            => Xirr::rate($xirrFlows),
            'flows'           => $xirrFlows,
            'stale'           => $valuedOn !== null
                && (new DateTimeImmutable($valuedOn))->diff($today)->days > 45,
        ];
    }

    // -- projection ----------------------------------------------------------

    /**
     * Compound the current value forward to `months` from today, plus an optional
     * monthly SIP compounded over the same window. Rate priority: the holding's
     * expected_return_apr, else its realised XIRR, else the type default.
     *
     * @param array<string,mixed> $inv
     * @param array<string,mixed> $returns
     */
    public function project(array $inv, array $returns, int $monthlySip, int $months, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $months = max(1, min(600, $months));

        $rate = $this->projectionRate($inv, $returns);
        $monthly = $rate / 100 / 12;
        $principal = (int) $returns['current_value'];

        // Corpus: principal * (1+i)^n
        $growth = (1 + $monthly) ** $months;
        $corpusFV = (int) round($principal * $growth);

        // SIP: an annuity-due-style future value of a fixed monthly contribution.
        $sipFV = 0;
        if ($monthlySip > 0 && $monthly > 0) {
            $sipFV = (int) round($monthlySip * (($growth - 1) / $monthly));
        } elseif ($monthlySip > 0) {
            $sipFV = $monthlySip * $months;    // zero rate: just the sum paid in
        }

        $sipTotalPaid = $monthlySip * $months;
        $target = $today->modify("+{$months} months");

        return [
            'rate_apr'       => $rate,
            'rate_source'    => $this->rateSource($inv, $returns),
            'months'         => $months,
            'target_date'    => $target->format('Y-m-d'),
            'monthly_sip'    => $monthlySip,
            'start_value'    => $principal,
            'corpus_fv'      => $corpusFV,
            'sip_fv'         => $sipFV,
            'projected_value' => $corpusFV + $sipFV,
            'total_invested' => $principal + $sipTotalPaid,
            'projected_gain' => ($corpusFV + $sipFV) - ($principal + $sipTotalPaid),
        ];
    }

    /** @param array<string,mixed> $inv @param array<string,mixed> $returns */
    private function projectionRate(array $inv, array $returns): float
    {
        if ($inv['expected_return_apr'] !== null) {
            return (float) $inv['expected_return_apr'];
        }
        if ($returns['xirr'] !== null) {
            // Clamp a wild early XIRR (a week-old holding up 3%) to something a
            // decade-long projection will not turn into a fantasy.
            return round(max(-20.0, min(30.0, $returns['xirr'] * 100)), 1);
        }

        return self::DEFAULT_RATES[$inv['instrument_type']] ?? self::DEFAULT_RATES['other'];
    }

    /** @param array<string,mixed> $inv @param array<string,mixed> $returns */
    private function rateSource(array $inv, array $returns): string
    {
        if ($inv['expected_return_apr'] !== null) {
            return 'expected';
        }
        if ($returns['xirr'] !== null) {
            return 'xirr';
        }

        return 'type_default';
    }

    // -- writes --------------------------------------------------------------

    public function create(array $data): int
    {
        $type = in_array($data['instrument_type'] ?? '', self::TYPES, true)
            ? (string) $data['instrument_type'] : 'other';

        $this->pdo->beginTransaction();
        try {
            $accountName = $this->uniqueAccountName((string) $data['name']);
            $taken = $this->pdo->query('SELECT color FROM accounts WHERE color IS NOT NULL')
                ->fetchAll(PDO::FETCH_COLUMN);
            // A holding owns an asset account: is_derived so CommitService leaves
            // its balance to sync(), is_liability = 0 so it lifts net worth.
            $this->pdo->prepare(
                "INSERT INTO accounts (name, type, institution, opening_balance, current_balance,
                                       is_liability, include_in_networth, is_derived, color)
                 VALUES (?, 'investment', ?, 0, 0, 0, 1, 1, ?)"
            )->execute([$accountName, $data['platform'] ?? null, Palette::next($taken)]);
            $accountId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO investments (account_id, name, instrument_type, platform,
                                          expected_return_apr, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId, (string) $data['name'], $type, $data['platform'] ?? null,
                $this->rateOrNull($data['expected_return_apr'] ?? null), $data['notes'] ?? null,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->sync($id);

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $this->find($id);
        $allowed = ['name', 'instrument_type', 'platform', 'expected_return_apr', 'notes'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if ($col === 'instrument_type' && !in_array($data[$col], self::TYPES, true)) {
                throw new RuntimeException('Unknown instrument type.');
            }
            $sets[] = "{$col} = ?";
            $vals[] = $col === 'expected_return_apr' ? $this->rateOrNull($data[$col]) : $data[$col];
        }
        if ($sets === []) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        $this->pdo->prepare('UPDATE investments SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    public function delete(int $id): void
    {
        $inv = $this->find($id);
        // ON DELETE CASCADE clears events/valuations/contributions and the account.
        $this->pdo->prepare('DELETE FROM investments WHERE id = ?')->execute([$id]);
        if ($inv['account_id'] !== null) {
            $this->pdo->prepare('DELETE FROM accounts WHERE id = ? AND is_derived = 1')
                ->execute([$inv['account_id']]);
        }
    }

    public function addEvent(int $id, array $data): int
    {
        $this->find($id);
        $type = (string) ($data['event_type'] ?? '');
        if (!in_array($type, [...self::OUTFLOWS, ...self::INFLOWS], true)) {
            throw new RuntimeException('Unknown event type.');
        }
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount above zero.');
        }
        $date = $this->validDate($data['event_date'] ?? null);

        $this->pdo->prepare(
            'INSERT INTO investment_events (investment_id, event_type, event_date, amount, units, price, note)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $id, $type, $date, $amount,
            isset($data['units']) && $data['units'] !== '' ? (float) $data['units'] : null,
            isset($data['price']) && $data['price'] !== '' ? (int) $data['price'] : null,
            $data['note'] ?? null,
        ]);
        $eventId = (int) $this->pdo->lastInsertId();
        $this->sync($id);

        return $eventId;
    }

    public function updateEvent(int $id, int $eventId, array $data): void
    {
        $this->assertEvent($id, $eventId);
        $type = (string) ($data['event_type'] ?? '');
        if (!in_array($type, [...self::OUTFLOWS, ...self::INFLOWS], true)) {
            throw new RuntimeException('Unknown event type.');
        }
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount above zero.');
        }
        $this->pdo->prepare(
            'UPDATE investment_events SET event_type = ?, event_date = ?, amount = ?, units = ?, price = ?, note = ?
             WHERE id = ? AND investment_id = ?'
        )->execute([
            $type, $this->validDate($data['event_date'] ?? null), $amount,
            isset($data['units']) && $data['units'] !== '' ? (float) $data['units'] : null,
            isset($data['price']) && $data['price'] !== '' ? (int) $data['price'] : null,
            $data['note'] ?? null, $eventId, $id,
        ]);
        $this->sync($id);
    }

    public function deleteEvent(int $id, int $eventId): void
    {
        $this->assertEvent($id, $eventId);
        // A contribution mirrored from the ledger owns this event; unlink it too.
        $this->pdo->prepare('DELETE FROM investment_events WHERE id = ? AND investment_id = ?')
            ->execute([$eventId, $id]);
        $this->sync($id);
    }

    public function addValuation(int $id, array $data): void
    {
        $this->find($id);
        $value = (int) ($data['value'] ?? 0);
        if ($value < 0) {
            throw new RuntimeException('A valuation cannot be negative.');
        }
        // UNIQUE(investment_id, valued_on): re-valuing a day replaces that mark.
        $this->pdo->prepare(
            "INSERT INTO investment_valuations (investment_id, valued_on, value, note)
             VALUES (?,?,?,?)
             ON CONFLICT(investment_id, valued_on) DO UPDATE SET value = excluded.value, note = excluded.note"
        )->execute([$id, $this->validDate($data['valued_on'] ?? null), $value, $data['note'] ?? null]);
        $this->sync($id);
    }

    public function deleteValuation(int $id, int $valuationId): void
    {
        $this->pdo->prepare('DELETE FROM investment_valuations WHERE id = ? AND investment_id = ?')
            ->execute([$valuationId, $id]);
        $this->sync($id);
    }

    // -- ledger seam ---------------------------------------------------------

    /**
     * Link an `investment` debit to a holding as a contribution, and mirror it
     * into the events so it counts in XIRR. `transactions` is untouched.
     */
    public function linkContribution(int $id, int $txnId): void
    {
        $this->find($id);
        $txn = $this->pdo->prepare('SELECT id, txn_date, amount, cashflow FROM transactions WHERE id = ?');
        $txn->execute([$txnId]);
        $row = $txn->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('That transaction no longer exists.');
        }
        if ($row['cashflow'] !== 'debit') {
            throw new RuntimeException('Only a debit can fund an investment.');
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM investment_contributions WHERE txn_id = ?');
        $exists->execute([$txnId]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('That transaction already funds a holding.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO investment_events (investment_id, event_type, event_date, amount, note)
                 VALUES (?, 'contribution', ?, ?, 'from ledger')"
            )->execute([$id, $row['txn_date'], (int) $row['amount']]);
            $eventId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO investment_contributions (investment_id, txn_id, event_id, contributed_on, amount)
                 VALUES (?,?,?,?,?)'
            )->execute([$id, $txnId, $eventId, $row['txn_date'], (int) $row['amount']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->sync($id);
    }

    public function unlinkContribution(int $id, int $txnId): void
    {
        $stmt = $this->pdo->prepare('SELECT event_id FROM investment_contributions WHERE investment_id = ? AND txn_id = ?');
        $stmt->execute([$id, $txnId]);
        $eventId = $stmt->fetchColumn();
        if ($eventId === false) {
            return;
        }
        // Deleting the event cascades the contribution row (FK ON DELETE CASCADE).
        $this->pdo->prepare('DELETE FROM investment_events WHERE id = ?')->execute([(int) $eventId]);
        $this->sync($id);
    }

    // -- sync to the derived account ----------------------------------------

    /** Write the latest valuation into the holding's account; derive is_closed. */
    public function sync(int $id): void
    {
        $inv = $this->find($id);
        if ($inv['account_id'] === null) {
            return;
        }
        $value = (int) ($this->pdo->query(
            "SELECT value FROM investment_valuations WHERE investment_id = {$id}
             ORDER BY valued_on DESC, id DESC LIMIT 1"
        )->fetchColumn() ?: 0);

        $this->pdo->prepare(
            "UPDATE accounts SET current_balance = ?, is_liability = 0, is_derived = 1,
                                 updated_at = datetime('now') WHERE id = ?"
        )->execute([$value, $inv['account_id']]);

        // Closed once everything is sold and nothing is held. Derived both ways,
        // like a loan's is_closed, so re-opening a holding clears it.
        $hasUnits = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM investment_events WHERE investment_id = {$id}"
        )->fetchColumn() > 0;
        $closed = $hasUnits && $value === 0
            && $this->netInvested($id) <= 0;
        if ($closed !== (bool) $inv['is_closed']) {
            $this->pdo->prepare('UPDATE investments SET is_closed = ? WHERE id = ?')
                ->execute([$closed ? 1 : 0, $id]);
        }
    }

    public function syncAll(): void
    {
        foreach ($this->pdo->query('SELECT id FROM investments')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->sync((int) $id);
        }
    }

    // -- helpers -------------------------------------------------------------

    private function summary(int $id, ?DateTimeImmutable $today): array
    {
        $inv = $this->find($id);
        $today ??= new DateTimeImmutable('today');
        $returns = $this->returns($inv, $this->events($id), $this->valuations($id), $today);

        return [
            'id'              => $inv['id'],
            'name'            => $inv['name'],
            'instrument_type' => $inv['instrument_type'],
            'platform'        => $inv['platform'],
            'color'           => $inv['color'],
            'is_closed'       => (bool) $inv['is_closed'],
            'returns'         => $returns,
        ];
    }

    private function find(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, a.color, a.current_balance
             FROM investments i LEFT JOIN accounts a ON a.id = i.account_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Investment not found.');
        }

        return $row;
    }

    /** @return list<array<string,mixed>> newest-first */
    private function events(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, (c.txn_id IS NOT NULL) AS from_ledger, c.txn_id
             FROM investment_events e
             LEFT JOIN investment_contributions c ON c.event_id = e.id
             WHERE e.investment_id = ? ORDER BY e.event_date DESC, e.id DESC'
        );
        $stmt->execute([$id]);

        return array_map(static function (array $r): array {
            $r['amount'] = (int) $r['amount'];
            $r['units']  = $r['units'] !== null ? (float) $r['units'] : null;
            $r['price']  = $r['price'] !== null ? (int) $r['price'] : null;
            $r['from_ledger'] = (bool) $r['from_ledger'];
            $r['txn_id'] = $r['txn_id'] !== null ? (int) $r['txn_id'] : null;

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> newest-first; [0] is the current value */
    private function valuations(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM investment_valuations WHERE investment_id = ? ORDER BY valued_on DESC, id DESC'
        );
        $stmt->execute([$id]);

        return array_map(static function (array $r): array {
            $r['value'] = (int) $r['value'];

            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function netInvested(int $id): int
    {
        $in = (int) $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM investment_events
             WHERE investment_id = {$id} AND event_type IN ('buy','contribution')"
        )->fetchColumn();
        $out = (int) $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM investment_events
             WHERE investment_id = {$id} AND event_type IN ('sell','withdrawal','dividend')"
        )->fetchColumn();

        return $in - $out;
    }

    private function assertEvent(int $id, int $eventId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM investment_events WHERE id = ? AND investment_id = ?');
        $stmt->execute([$eventId, $id]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('That event does not belong to this holding.');
        }
    }

    private function rateOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (float) $v;
    }

    private function validDate(mixed $raw): string
    {
        $s = trim((string) $raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            throw new RuntimeException('Enter a valid date (YYYY-MM-DD).');
        }

        return $s;
    }

    private function uniqueAccountName(string $base): string
    {
        $name = $base;
        $n    = 1;
        $stmt = $this->pdo->prepare('SELECT 1 FROM accounts WHERE name = ?');
        while (true) {
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() === false) {
                return $name;
            }
            $name = $base . ' (' . (++$n) . ')';
        }
    }
}
