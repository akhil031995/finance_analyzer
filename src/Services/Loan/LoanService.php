<?php

declare(strict_types=1);

namespace App\Services\Loan;

use App\Support\Palette;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * The database face of the loans module.
 *
 * Reads a loan and its events, runs the pure AmortisationEngine, overlays which
 * instalments have actually been paid (from `loan_payments`), and derives the
 * analysis the Loans page renders.
 *
 * sync() is the bridge to the rest of the app: it writes the loan's outstanding
 * PRINCIPAL into its own liability `accounts` row, so v_net_worth, the debt
 * ladder, snapshots and the dashboard all keep working with no knowledge of
 * loans at all.
 */
final class LoanService
{
    /** A ledger EMI within this much of the scheduled instalment is unremarkable. */
    private const VARIANCE_TOLERANCE_PCT = 2.0;

    public function __construct(
        private PDO $pdo,
        private AmortisationEngine $engine = new AmortisationEngine(),
    ) {
    }

    // -- reads ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listLoans(): array
    {
        $rows = $this->pdo->query(
            'SELECT l.*, a.current_balance AS outstanding
             FROM loans l LEFT JOIN accounts a ON a.id = l.account_id
             ORDER BY l.is_closed, l.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->castLoan(...), $rows);
    }

    /** @return array<string,mixed> */
    public function find(int $loanId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loan === false) {
            throw new RuntimeException("No loan with id {$loanId}.");
        }

        return $this->castLoan($loan);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $loanId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM loan_events WHERE loan_id = ? ORDER BY effective_date, id'
        );
        $stmt->execute([$loanId]);

        return array_map(static fn ($e) => [
            'id'             => (int) $e['id'],
            'event_type'     => $e['event_type'],
            'effective_date' => $e['effective_date'],
            'rate_apr'       => $e['rate_apr'] !== null ? (float) $e['rate_apr'] : null,
            'emi_amount'     => $e['emi_amount'] !== null ? (int) $e['emi_amount'] : null,
            'amount'         => $e['amount'] !== null ? (int) $e['amount'] : null,
            'mode'           => $e['mode'],
            'note'           => $e['note'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The whole picture for one loan: schedule with paid/unpaid/overdue state,
     * summary, payoff comparison, tax view, per-year rollup.
     *
     * @return array<string,mixed>
     */
    public function report(int $loanId, ?DateTimeImmutable $today = null): array
    {
        $today  = $today ?? new DateTimeImmutable('today');
        $loan   = $this->find($loanId);
        $events = $this->events($loanId);

        $run     = $this->engine->run($loan, $events);
        $periods = $run['periods'];

        $payments = $this->payments($loanId);
        $periods  = $this->overlayPayments($periods, $payments, $today);

        return [
            'loan'        => $loan,
            'events'      => $events,
            'periods'     => $periods,
            'summary'     => $run['summary'],
            'warnings'    => $run['warnings'],
            'position'    => $this->position($periods, $today),
            'baseline'    => $this->baseline($loan, $events, $run['summary']),
            'by_year'     => $this->byYear($periods),
            'tax'         => $this->byFinancialYear($periods, $loan['possession_date']),
            'variances'   => $this->variances($periods),
        ];
    }

    /**
     * Where the loan stands right now.
     *
     * `outstanding` is the closing balance after the last instalment due BEFORE
     * the 1st of the current month — i.e. everything from this month onward is
     * still owed. This is the number that becomes the liability in net worth.
     *
     * `remaining_payments` (principal + all future interest) is deliberately a
     * separate figure. You only owe that interest if you keep the loan alive, so
     * it is never treated as debt on the balance sheet.
     *
     * @param list<array<string,mixed>> $periods
     * @return array<string,mixed>
     */
    public function position(array $periods, ?DateTimeImmutable $today = null): array
    {
        $today      = $today ?? new DateTimeImmutable('today');
        $monthStart = $today->format('Y-m-01');
        $todayKey   = $today->format('Y-m-d');

        $outstanding       = 0;
        $remainingPayments = 0;
        $remainingInterest = 0;
        $paidCount = $unpaidCount = $overdueCount = 0;
        $interestPaid = $principalPaid = 0;
        $next = null;
        $currentEmi = 0;
        $currentIsPreEmi = false;
        $seenCurrent = false;

        foreach ($periods as $p) {
            // The instalment in force right now, whether or not it has been paid.
            // next_due skips paid months; the monthly outgo must not.
            if (!$seenCurrent && $p['due_date'] >= $todayKey) {
                $seenCurrent     = true;
                $currentEmi      = (int) $p['emi'];
                $currentIsPreEmi = (bool) ($p['is_pre_emi'] ?? false);
            }
            // Last instalment that fell entirely before this month sets the
            // balance we carry into it.
            if ($p['due_date'] < $monthStart) {
                $outstanding = (int) $p['closing_balance'];
            } else {
                $remainingPayments += (int) $p['emi'] + (int) $p['prepayment'];
                $remainingInterest += (int) $p['interest'];
            }

            if ($p['status'] === 'paid') {
                $paidCount++;
                $interestPaid  += (int) $p['interest'];
                $principalPaid += (int) $p['principal'];
            } else {
                $unpaidCount++;
                if ($p['status'] === 'overdue') {
                    $overdueCount++;
                }
                if ($next === null && $p['due_date'] >= $todayKey) {
                    $next = ['period_no' => $p['period_no'], 'due_date' => $p['due_date'], 'emi' => (int) $p['emi']];
                }
            }
        }

        // The very first instalment is not yet due: nothing has been repaid.
        if ($periods !== [] && $periods[0]['due_date'] >= $monthStart) {
            $outstanding = (int) $periods[0]['opening_balance'];
        }

        return [
            'as_of'              => $todayKey,
            'outstanding'        => $outstanding,
            'remaining_payments' => $remainingPayments,
            'remaining_interest' => $remainingInterest,
            'paid_count'         => $paidCount,
            'unpaid_count'       => $unpaidCount,
            'overdue_count'      => $overdueCount,
            'total_periods'      => count($periods),
            'interest_paid'      => $interestPaid,
            'principal_paid'     => $principalPaid,
            'next_due'           => $next,
            'current_emi'        => $currentEmi,
            // During pre-EMI what you pay is interest, not an instalment. Naming
            // it "EMI" on screen would be a lie.
            'current_is_pre_emi' => $currentIsPreEmi,
        ];
    }

    /**
     * What the loan would have cost had you never prepaid or raised the EMI.
     * The difference is the interest your prepayments actually bought you —
     * the single most motivating number on the page.
     *
     * Rate changes stay in: they happened to you, they were not a choice.
     *
     * @param array<string,mixed>       $loan
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed>       $summary
     * @return array<string,mixed>|null
     */
    private function baseline(array $loan, array $events, array $summary): ?array
    {
        // A rate change and a tranche both happened TO you. Only a prepayment or
        // an EMI increase was a choice, so only those are stripped to build the
        // "what would this have cost otherwise" comparison.
        $involuntary = array_values(array_filter(
            $events,
            static fn ($e) => in_array($e['event_type'], ['rate_change', 'disbursement'], true)
        ));
        if (count($involuntary) === count($events)) {
            return null;   // nothing voluntary to compare against
        }

        try {
            $base = $this->engine->run($loan, $involuntary);
        } catch (RuntimeException) {
            return null;   // e.g. the original EMI no longer amortises at today's rate
        }

        return [
            'payoff_date'     => $base['summary']['payoff_date'],
            'periods'         => $base['summary']['periods'],
            'total_interest'  => $base['summary']['total_interest'],
            'months_saved'    => $base['summary']['periods'] - $summary['periods'],
            'interest_saved'  => $base['summary']['total_interest'] - $summary['total_interest'],
        ];
    }

    /**
     * Hypothetical events, nothing written. Powers the "what if I prepay?" card.
     *
     * @param list<array<string,mixed>> $extraEvents
     * @return array<string,mixed>
     */
    public function simulate(int $loanId, array $extraEvents): array
    {
        $loan   = $this->find($loanId);
        $events = $this->events($loanId);
        $actual = $this->engine->run($loan, $events);
        $what   = $this->engine->run($loan, [...$events, ...$extraEvents]);

        $interestSaved = $actual['summary']['total_interest'] - $what['summary']['total_interest'];
        $extraOutlay   = $what['summary']['total_prepaid'] - $actual['summary']['total_prepaid'];

        return [
            'actual'         => $actual['summary'],
            'simulated'      => $what['summary'],
            'months_saved'   => $actual['summary']['periods'] - $what['summary']['periods'],
            'interest_saved' => $interestSaved,
            'extra_outlay'   => $extraOutlay,
            // Every ₹1 prepaid returns this much in interest never charged. Above
            // 1.0 the prepayment more than pays for itself over the loan's life.
            'return_ratio'   => $extraOutlay > 0 ? round($interestSaved / $extraOutlay, 3) : null,
            'warnings'       => $what['warnings'],
        ];
    }

    /**
     * Expand a recurring "pay ₹X extra every month for N months" into the
     * discrete prepayment events the engine understands.
     *
     * @return list<array<string,mixed>>
     */
    public function monthlyExtraEvents(
        int $loanId,
        int $amount,
        string $from,
        int $months,
        string $mode = 'reduce_tenure',
    ): array {
        $start  = new DateTimeImmutable($from);
        $events = [];
        for ($i = 0; $i < $months; $i++) {
            $events[] = [
                'event_type'     => 'prepayment',
                'effective_date' => AmortisationEngine::addMonths($start, $i)->format('Y-m-d'),
                'amount'         => $amount,
                'mode'           => $mode,
            ];
        }

        return $events;
    }

    // -- paid / unpaid -------------------------------------------------------

    /** @return array<int,array<string,mixed>> keyed by period_no */
    public function payments(int $loanId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, t.description, t.txn_date, t.account_id
             FROM loan_payments p
             LEFT JOIN transactions t ON t.id = p.txn_id
             WHERE p.loan_id = ?'
        );
        $stmt->execute([$loanId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['period_no']] = [
                'id'          => (int) $r['id'],
                'txn_id'      => $r['txn_id'] !== null ? (int) $r['txn_id'] : null,
                'paid_on'     => $r['paid_on'],
                'amount'      => (int) $r['amount'],
                'source'      => $r['source'],
                'note'        => $r['note'],
                'description' => $r['description'],
            ];
        }

        return $out;
    }

    /**
     * Every instalment starts unpaid. It is paid only when a real transaction is
     * linked to it — "paid" means the money left your account, never that the
     * calendar passed the due date.
     *
     * @param list<array<string,mixed>>  $periods
     * @param array<int,array<string,mixed>> $payments
     * @return list<array<string,mixed>>
     */
    private function overlayPayments(array $periods, array $payments, DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');

        foreach ($periods as $i => $p) {
            $pay = $payments[$p['period_no']] ?? null;
            if ($pay !== null) {
                $variance  = $pay['amount'] - (int) $p['emi'];
                $tolerance = (int) round((int) $p['emi'] * self::VARIANCE_TOLERANCE_PCT / 100);

                $periods[$i]['status']   = 'paid';
                $periods[$i]['payment']  = $pay;
                $periods[$i]['variance'] = $variance;
                // Flagged, but never re-amortised from: letting the actual debit
                // rewrite the projection would make it thrash on bank rounding.
                $periods[$i]['variance_flag'] = abs($variance) > max($tolerance, 100);
                continue;
            }

            $periods[$i]['status']        = $p['due_date'] < $todayKey ? 'overdue' : 'unpaid';
            $periods[$i]['payment']       = null;
            $periods[$i]['variance']      = 0;
            $periods[$i]['variance_flag'] = false;
        }

        return $periods;
    }

    /**
     * Link a ledger transaction to one instalment.
     *
     * The UNIQUE constraints do the real enforcement: one debit cannot pay two
     * loans, and one instalment cannot be paid twice.
     */
    public function linkPayment(int $loanId, int $periodNo, int $txnId): array
    {
        $txn = $this->pdo->prepare('SELECT id, txn_date, amount, cashflow, description FROM transactions WHERE id = ?');
        $txn->execute([$txnId]);
        $t = $txn->fetch(PDO::FETCH_ASSOC);
        if ($t === false) {
            throw new RuntimeException("No transaction with id {$txnId}.");
        }
        if ($t['cashflow'] !== 'debit') {
            throw new RuntimeException('An EMI has to be money leaving an account, but this transaction is a credit.');
        }

        $periods = $this->engine->run($this->find($loanId), $this->events($loanId))['periods'];
        if ($periodNo < 1 || $periodNo > count($periods)) {
            throw new RuntimeException("This loan has no instalment #{$periodNo}.");
        }

        try {
            $this->pdo->prepare(
                "INSERT INTO loan_payments (loan_id, period_no, txn_id, paid_on, amount, source)
                 VALUES (?, ?, ?, ?, ?, 'ledger')"
            )->execute([$loanId, $periodNo, $txnId, $t['txn_date'], (int) $t['amount']]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException(
                    'Already linked: either that instalment is marked paid, or that transaction pays another instalment.'
                );
            }
            throw $e;
        }

        $this->sync($loanId);

        return ['linked' => true, 'period_no' => $periodNo, 'txn_id' => $txnId];
    }

    public function unlinkPayment(int $loanId, int $periodNo): void
    {
        $this->pdo->prepare('DELETE FROM loan_payments WHERE loan_id = ? AND period_no = ?')
            ->execute([$loanId, $periodNo]);
        $this->sync($loanId);
    }

    /**
     * Instalments whose actual debit differs from the schedule by more than the
     * tolerance. Usually means a part-prepayment went out with the EMI.
     *
     * @param list<array<string,mixed>> $periods
     * @return list<array<string,mixed>>
     */
    private function variances(array $periods): array
    {
        return array_values(array_map(
            static fn ($p) => [
                'period_no' => $p['period_no'],
                'due_date'  => $p['due_date'],
                'scheduled' => $p['emi'],
                'paid'      => $p['payment']['amount'] ?? 0,
                'variance'  => $p['variance'],
            ],
            array_filter($periods, static fn ($p) => $p['variance_flag'] === true)
        ));
    }

    // -- rollups -------------------------------------------------------------

    /**
     * Interest vs principal per calendar year — the stacked bar, and the shape
     * that shows how long a long loan stays almost pure interest.
     *
     * @param list<array<string,mixed>> $periods
     * @return list<array<string,mixed>>
     */
    private function byYear(array $periods): array
    {
        $years = [];
        foreach ($periods as $p) {
            $y = substr((string) $p['due_date'], 0, 4);
            $years[$y] ??= ['year' => $y, 'interest' => 0, 'principal' => 0, 'prepayment' => 0, 'paid' => 0, 'periods' => 0];
            $years[$y]['interest']   += (int) $p['interest'];
            $years[$y]['principal']  += (int) $p['principal'];
            $years[$y]['prepayment'] += (int) $p['prepayment'];
            $years[$y]['paid']       += (int) $p['emi'] + (int) $p['prepayment'];
            $years[$y]['periods']++;
        }

        return array_values($years);
    }

    /** Section 24(b) caps deductible self-occupied home-loan interest per FY. */
    private const SECTION_24B_CAP = 20000000;          // ₹2,00,000 in paise
    /** Pre-construction interest is claimed in five equal annual instalments. */
    private const PRE_CONSTRUCTION_INSTALMENTS = 5;

    /**
     * Indian financial year (Apr–Mar) rollup. Home-loan interest is deductible
     * under Section 24(b) up to ₹2,00,000 a year; principal counts toward 80C.
     * Both caps are per financial year, which is why this is not the calendar view.
     *
     * **Pre-construction interest is not deductible in the year you pay it.**
     * Everything paid before the 1st of April preceding possession is aggregated
     * and claimed in five equal annual instalments starting the FY of possession —
     * and those instalments still sit inside the same ₹2L cap. Without a
     * possession date that split cannot be computed, so we report interest as
     * paid and say so, rather than quietly overstating the deduction.
     *
     * @param list<array<string,mixed>> $periods
     * @return array<string,mixed>
     */
    private function byFinancialYear(array $periods, ?string $possessionDate): array
    {
        $fys = [];
        foreach ($periods as $p) {
            $label = self::financialYear((string) $p['due_date']);
            $start = (int) substr($label, 2, 4);

            $fys[$label] ??= ['fy' => $label, 'start_year' => $start, 'interest' => 0,
                              'principal' => 0, 'prepayment' => 0, 'pre_emi_interest' => 0];
            $fys[$label]['interest']   += (int) $p['interest'];
            $fys[$label]['principal']  += (int) $p['principal'];
            $fys[$label]['prepayment'] += (int) $p['prepayment'];
            if ($p['is_pre_emi']) {
                $fys[$label]['pre_emi_interest'] += (int) $p['interest'];
            }
        }
        ksort($fys);

        // No possession date: behave exactly as before — interest is deductible
        // in the year it is paid, capped at ₹2L.
        if ($possessionDate === null) {
            $rows = array_values(array_map(static function ($f) {
                $f['pre_construction_carry'] = 0;
                $f['deductible_24b'] = min($f['interest'], self::SECTION_24B_CAP);
                $f['over_cap']       = max(0, $f['interest'] - self::SECTION_24B_CAP);
                $f['interest_capped'] = $f['deductible_24b'];   // kept for the old UI key

                return $f;
            }, $fys));

            return ['years' => $rows, 'possession_fy' => null, 'pre_construction_total' => 0,
                    'pre_construction_instalment' => 0, 'split_applied' => false];
        }

        $possessionFy = self::financialYear($possessionDate);

        // Interest paid in any FY BEFORE the one in which possession falls.
        $preConstruction = 0;
        foreach ($fys as $label => $f) {
            if ($label < $possessionFy) {
                $preConstruction += $f['interest'];
            }
        }
        $instalment = intdiv($preConstruction, self::PRE_CONSTRUCTION_INSTALMENTS);

        $claimFrom = (int) substr($possessionFy, 2, 4);
        $rows = [];
        foreach ($fys as $label => $f) {
            $isPre = $label < $possessionFy;
            $withinClaimWindow = !$isPre
                && $f['start_year'] >= $claimFrom
                && $f['start_year'] < $claimFrom + self::PRE_CONSTRUCTION_INSTALMENTS;

            $f['pre_construction_carry'] = $withinClaimWindow ? $instalment : 0;

            // Before possession nothing is deductible; the interest is banked and
            // returned in instalments once you have the keys.
            $claimable = $isPre ? 0 : $f['interest'] + $f['pre_construction_carry'];
            $f['deductible_24b']  = min($claimable, self::SECTION_24B_CAP);
            $f['over_cap']        = max(0, $claimable - self::SECTION_24B_CAP);
            $f['interest_capped'] = $f['deductible_24b'];
            $f['pre_possession']  = $isPre;
            $rows[] = $f;
        }

        return [
            'years'                       => $rows,
            'possession_fy'               => $possessionFy,
            'pre_construction_total'      => $preConstruction,
            'pre_construction_instalment' => $instalment,
            'split_applied'               => true,
        ];
    }

    /** 'FY2025-26' for any date in Apr-2025 .. Mar-2026. Sorts lexicographically. */
    private static function financialYear(string $date): string
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $start = $m >= 4 ? $y : $y - 1;

        return sprintf('FY%d-%02d', $start, ($start + 1) % 100);
    }

    // -- writes --------------------------------------------------------------

    /**
     * A loan owns exactly one liability account. Creating it here (rather than
     * expecting the user to) is what keeps net worth correct without touching
     * v_net_worth, the debt ladder, snapshots or the dashboard.
     *
     * is_derived = 1 tells CommitService to leave current_balance alone: it is
     * owned by the amortisation engine, not by summing ledger rows.
     */
    public function create(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $accountName = $this->uniqueAccountName((string) $data['name']);
            // A loan owns an account like any other, so it wears a colour like any
            // other — the Loans card and the ledger both key off it.
            $taken = $this->pdo->query('SELECT color FROM accounts WHERE color IS NOT NULL')
                ->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->prepare(
                "INSERT INTO accounts (name, type, institution, opening_balance, current_balance,
                                       is_liability, include_in_networth, is_derived, color)
                 VALUES (?, 'loan', ?, ?, ?, 1, 1, 1, ?)"
            )->execute([$accountName, $data['lender'] ?? null, (int) $data['principal'], (int) $data['principal'],
                        Palette::next($taken)]);
            $accountId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO loans (account_id, name, lender, loan_type, principal, start_date,
                                    first_emi_date, tenure_months, interest_rate_apr, emi_amount,
                                    pre_emi_mode, possession_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId,
                $data['name'],
                $data['lender'] ?? null,
                $data['loan_type'] ?? 'other',
                (int) $data['principal'],
                $data['start_date'],
                $data['first_emi_date'],
                (int) $data['tenure_months'],
                (float) $data['interest_rate_apr'],
                $data['emi_amount'] ?? null,
                $data['pre_emi_mode'] ?? 'pay',
                $data['possession_date'] ?? null,
                $data['notes'] ?? null,
            ]);
            $loanId = (int) $this->pdo->lastInsertId();

            // Reject an impossible loan before it is committed, not on first view.
            $this->engine->run($this->find($loanId), []);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->sync($loanId);

        return $loanId;
    }

    public function update(int $loanId, array $data): void
    {
        // `is_closed` is absent on purpose: sync() derives it from the schedule, so
        // anything written here would be silently overwritten on the next recompute.
        // Settle a loan by recording the prepayment that actually settled it.
        $allowed = ['name', 'lender', 'loan_type', 'principal', 'start_date',
                    'first_emi_date', 'tenure_months', 'interest_rate_apr', 'emi_amount',
                    'pre_emi_mode', 'possession_date', 'notes'];
        $sets = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "{$col} = ?";
                $vals[] = $data[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $loanId;

        // If the loan already does not amortise, refusing every edit would trap the
        // user: the change they need to make to REPAIR it would itself be rejected.
        // So only a change that BREAKS a working loan is rolled back.
        $wasBroken = !$this->amortises($loanId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE loans SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            $this->engine->run($this->find($loanId), $this->events($loanId));   // still amortises?
            $this->pdo->commit();
        } catch (RuntimeException $e) {
            if (!$wasBroken) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->pdo->commit();   // still broken, but no worse — let the repair proceed
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->sync($loanId);
    }

    /** Does this loan currently produce a schedule at all? */
    private function amortises(int $loanId): bool
    {
        try {
            $this->engine->run($this->find($loanId), $this->events($loanId));

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function delete(int $loanId): void
    {
        // The account cascades from loans.account_id; payments and events cascade
        // from loan_id. Deleting the account row would take the loan with it.
        $loan = $this->find($loanId);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM loans WHERE id = ?')->execute([$loanId]);
            if ($loan['account_id'] !== null) {
                $this->pdo->prepare('DELETE FROM accounts WHERE id = ? AND is_derived = 1')->execute([$loan['account_id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function addEvent(int $loanId, array $data): int
    {
        // Same reasoning as update(): if the loan is already unamortisable, the
        // event the user is adding is probably the tranche that repairs it.
        $wasBroken = !$this->amortises($loanId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO loan_events (loan_id, event_type, effective_date, rate_apr, emi_amount, amount, mode, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $loanId,
                $data['event_type'],
                $data['effective_date'],
                $data['rate_apr']   ?? null,
                $data['emi_amount'] ?? null,
                $data['amount']     ?? null,
                $data['mode']       ?? null,
                $data['note']       ?? null,
            ]);
            $id = (int) $this->pdo->lastInsertId();

            // An event that breaks amortisation (an EMI below the interest, say)
            // must be refused now, not discovered on the next page load.
            $this->engine->run($this->find($loanId), $this->events($loanId));

            $this->pdo->commit();
        } catch (RuntimeException $e) {
            if (!$wasBroken) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->pdo->commit();   // already broken; this event did not cause it
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->sync($loanId);

        return $id;
    }

    /**
     * Correct an event in place. Editing is not the same as delete-then-add: the
     * event keeps its id, so a payment link or a note survives, and the schedule
     * simply re-derives.
     *
     * Same guard as addEvent(): an edit that breaks a WORKING loan is rolled back,
     * but an edit to an already-broken one is the repair and must be allowed.
     */
    public function updateEvent(int $loanId, int $eventId, array $data): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM loan_events WHERE id = ? AND loan_id = ?');
        $exists->execute([$eventId, $loanId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException("No such change on this loan.");
        }

        $wasBroken = !$this->amortises($loanId);

        $this->pdo->beginTransaction();
        try {
            // Every type-specific column is rewritten, so switching a rate change
            // into a prepayment cannot leave a stale rate behind.
            $this->pdo->prepare(
                'UPDATE loan_events SET event_type = ?, effective_date = ?, rate_apr = ?,
                                        emi_amount = ?, amount = ?, mode = ?, note = ?
                 WHERE id = ? AND loan_id = ?'
            )->execute([
                $data['event_type'],
                $data['effective_date'],
                $data['rate_apr']   ?? null,
                $data['emi_amount'] ?? null,
                $data['amount']     ?? null,
                $data['mode']       ?? null,
                $data['note']       ?? null,
                $eventId,
                $loanId,
            ]);

            $this->engine->run($this->find($loanId), $this->events($loanId));
            $this->pdo->commit();
        } catch (RuntimeException $e) {
            if (!$wasBroken) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->pdo->commit();   // already broken; this edit did not cause it
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->sync($loanId);
    }

    public function deleteEvent(int $loanId, int $eventId): void
    {
        $this->pdo->prepare('DELETE FROM loan_events WHERE id = ? AND loan_id = ?')->execute([$eventId, $loanId]);
        $this->sync($loanId);
    }

    /**
     * Push outstanding principal into the loan's liability account, so net worth
     * and the debt ladder reflect it without either of them knowing about loans.
     *
     * Called after every write, and nightly by cron — the figure moves on its own
     * as each month's instalment falls due, even if nothing was edited.
     */
    public function sync(int $loanId, ?DateTimeImmutable $today = null): int
    {
        $loan = $this->find($loanId);
        if ($loan['account_id'] === null) {
            return 0;
        }

        try {
            $run      = $this->engine->run($loan, $this->events($loanId));
            $position = $this->position(
                $this->overlayPayments($run['periods'], $this->payments($loanId), $today ?? new DateTimeImmutable('today')),
                $today
            );
            $outstanding = $position['outstanding'];
        } catch (RuntimeException) {
            // A loan that will not amortise cannot state a balance. Leave the last
            // good figure in place rather than writing a lie.
            return 0;
        }

        $this->pdo->prepare(
            "UPDATE accounts SET current_balance = ?, is_liability = 1, is_derived = 1,
                                 updated_at = datetime('now')
             WHERE id = ?"
        )->execute([$outstanding, $loan['account_id']]);

        // `is_closed` is DERIVED from the schedule, exactly like the balance above,
        // so it is recomputed in both directions. Only ever setting it made the flag
        // latch: a loan that momentarily amortised to zero (a small tranche against
        // a large EMI, say) stayed "closed" once a later tranche revived it — and a
        // closed loan is dropped from the monthly EMI outgo and the debt-free date.
        $closed = $outstanding === 0;
        if ($closed !== (bool) $loan['is_closed']) {
            $this->pdo->prepare("UPDATE loans SET is_closed = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$closed ? 1 : 0, $loanId]);
        }

        return $outstanding;
    }

    /**
     * Total loan principal outstanding on each of the given dates.
     *
     * A loan account carries no ledger rows, so its debt cannot be walked out of
     * `transactions` like every other account — it has to come from the schedule.
     * The rule is exactly `position()`'s, so the point for today equals the
     * `current_balance` that `sync()` writes, and the net-worth curve's last
     * point reconciles with `v_net_worth`: the closing balance after the last
     * instalment that fell due **before the 1st of that date's month**.
     *
     * Zero before the first rupee was drawn — a loan taken in 2022 must not
     * appear as debt in 2019. A loan that will not amortise contributes its last
     * known balance, flat, rather than silently vanishing from net worth.
     *
     * @param  list<string> $dates 'Y-m-d', any order
     * @return array<string,int>   date => total outstanding paise
     */
    public function debtOn(array $dates): array
    {
        $curves = [];   // one closure per loan
        $rows = $this->pdo->query(
            'SELECT l.id, a.current_balance
             FROM loans l JOIN accounts a ON a.id = l.account_id
             WHERE a.include_in_networth = 1 AND a.is_archived = 0'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $loanId = (int) $row['id'];
            $loan   = $this->find($loanId);
            $events = $this->events($loanId);

            try {
                $periods = $this->engine->run($loan, $events)['periods'];
            } catch (RuntimeException) {
                $flat = (int) $row['current_balance'];
                $curves[] = static fn (string $d): int => $flat;
                continue;
            }
            if ($periods === []) {
                continue;
            }

            // The day the first rupee landed — an event's effective_date, not a
            // period due_date: interest is billed in arrears, so the first period
            // falls a month *after* the drawdown. With no explicit tranche the
            // engine draws the whole sanction on start_date.
            $drawn = array_column(
                array_filter($events, static fn (array $e): bool => $e['event_type'] === 'disbursement'),
                'effective_date'
            );
            $drawnFrom = $drawn === [] ? (string) $loan['start_date'] : min($drawn);
            $opening   = (int) $periods[0]['opening_balance'];

            $curves[] = static function (string $date) use ($periods, $drawnFrom, $opening): int {
                $monthStart = substr($date, 0, 7) . '-01';
                if ($date < $drawnFrom) {
                    return 0;               // the loan did not exist yet
                }
                $out = null;
                foreach ($periods as $p) {
                    if ($p['due_date'] < $monthStart) {
                        $out = (int) $p['closing_balance'];
                    } else {
                        break;              // periods are chronological
                    }
                }

                return $out ?? $opening;    // drawn, but nothing has fallen due yet
            };
        }

        $out = [];
        foreach ($dates as $d) {
            $total = 0;
            foreach ($curves as $curve) {
                $total += $curve($d);
            }
            $out[$d] = $total;
        }

        return $out;
    }

    /**
     * What was actually borrowed, across every loan: the sum of the tranches
     * drawn, falling back to the sanctioned principal when a loan records no
     * explicit disbursement (the engine treats that as one full tranche).
     *
     * NOT `Σ loans.principal` — that is the amount *sanctioned*, a ceiling, and
     * a half-drawn home loan would report a paydown it has not made.
     *
     * @return array<int,int> account_id => drawn paise
     */
    public function drawnByAccount(): array
    {
        $rows = $this->pdo->query(
            "SELECT l.account_id,
                    COALESCE((SELECT SUM(e.amount) FROM loan_events e
                              WHERE e.loan_id = l.id AND e.event_type = 'disbursement'),
                             l.principal) AS drawn
             FROM loans l JOIN accounts a ON a.id = l.account_id
             WHERE a.is_archived = 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['account_id']] = ($out[(int) $r['account_id']] ?? 0) + (int) $r['drawn'];
        }

        return $out;
    }

    /** @return array{synced:int, failed:list<string>} */
    public function syncAll(?DateTimeImmutable $today = null): array
    {
        $ids    = $this->pdo->query('SELECT id FROM loans')->fetchAll(PDO::FETCH_COLUMN);
        $failed = [];
        foreach ($ids as $id) {
            try {
                $this->sync((int) $id, $today);
            } catch (\Throwable $e) {
                $failed[] = "loan {$id}: " . $e->getMessage();
            }
        }

        return ['synced' => count($ids) - count($failed), 'failed' => $failed];
    }

    // -- portfolio -----------------------------------------------------------

    /**
     * Across every open loan: total debt, blended rate, monthly outgo, the date
     * the last one clears, and what share of income the EMIs eat.
     *
     * @return array<string,mixed>
     */
    public function portfolio(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');

        $totalOutstanding = $totalPrincipal = $totalInterest = $monthlyEmi = $remainingInterest = 0;
        $weighted   = 0.0;
        $debtFree   = null;
        $overdue    = 0;
        $loans      = [];

        foreach ($this->listLoans() as $loan) {
            try {
                $run     = $this->engine->run($loan, $this->events($loan['id']));
                $periods = $this->overlayPayments($run['periods'], $this->payments($loan['id']), $today);
                $pos     = $this->position($periods, $today);
            } catch (RuntimeException $e) {
                $loans[] = ['id' => $loan['id'], 'name' => $loan['name'], 'error' => $e->getMessage()];
                continue;
            }

            $totalOutstanding  += $pos['outstanding'];
            $remainingInterest += $pos['remaining_interest'];
            // What you have actually borrowed, not what was sanctioned. On a
            // part-disbursed loan those differ, and only the drawn amount is debt.
            $totalPrincipal    += $run['summary']['disbursed'];
            $totalInterest     += $run['summary']['total_interest'];
            $overdue           += $pos['overdue_count'];
            $weighted          += $pos['outstanding'] * $loan['interest_rate_apr'];

            if (!$loan['is_closed']) {
                $monthlyEmi += $pos['current_emi'];
            }
            $payoff = $run['summary']['payoff_date'];
            if ($payoff !== null && ($debtFree === null || $payoff > $debtFree) && !$loan['is_closed']) {
                $debtFree = $payoff;
            }

            $loans[] = [
                'id'          => $loan['id'],
                'name'        => $loan['name'],
                'lender'      => $loan['lender'],
                'loan_type'   => $loan['loan_type'],
                'is_closed'   => $loan['is_closed'],
                'rate_apr'    => $loan['interest_rate_apr'],
                'principal'   => $loan['principal'],                  // sanctioned
                'disbursed'   => $run['summary']['disbursed'],
                'undisbursed' => $run['summary']['undisbursed'],
                'pre_emi'     => $pos['current_is_pre_emi'],
                'outstanding' => $pos['outstanding'],
                'emi'         => $pos['current_emi'],
                'payoff_date' => $payoff,
                'paid_count'  => $pos['paid_count'],
                'periods'     => $pos['total_periods'],
                'overdue'     => $pos['overdue_count'],
                // Progress is against what you drew, not what was sanctioned:
                // an undrawn tranche is not a repaid one.
                'progress'    => $run['summary']['disbursed'] > 0
                    ? round((($run['summary']['disbursed'] - $pos['outstanding']) / $run['summary']['disbursed']) * 100, 1)
                    : 0.0,
            ];
        }

        $income = $this->monthlyIncome($today);

        return [
            'loans'              => $loans,
            'count'              => count($loans),
            'total_principal'    => $totalPrincipal,
            'total_outstanding'  => $totalOutstanding,
            'total_interest'     => $totalInterest,
            'remaining_interest' => $remainingInterest,
            'monthly_emi'        => $monthlyEmi,
            'overdue_count'      => $overdue,
            // Blended by balance, not by count: a ₹50L home loan at 8.5% and a
            // ₹50k gold loan at 14% do not average to 11.25%.
            'blended_rate'       => $totalOutstanding > 0 ? round($weighted / $totalOutstanding, 2) : 0.0,
            'debt_free_date'     => $debtFree,
            'monthly_income'     => $income,
            'emi_to_income'      => $income > 0 ? round(($monthlyEmi / $income) * 100, 1) : null,
        ];
    }

    /** Median monthly income over the last 6 complete months; 0 if we cannot tell. */
    private function monthlyIncome(DateTimeImmutable $today): int
    {
        $from = $today->modify('first day of this month')->modify('-6 months')->format('Y-m-d');
        $to   = $today->modify('first day of this month')->format('Y-m-d');

        $rows = $this->pdo->prepare(
            "SELECT strftime('%Y-%m', txn_date) AS m, SUM(amount) AS total
             FROM transactions
             WHERE cashflow = 'credit' AND is_self_transfer = 0 AND is_excluded = 0
               AND category NOT IN (SELECT category FROM excluded_categories)
               AND txn_date >= ? AND txn_date < ?
             GROUP BY m"
        );
        $rows->execute([$from, $to]);
        $totals = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN, 1));
        if ($totals === []) {
            return 0;
        }

        sort($totals);
        $mid = intdiv(count($totals), 2);

        // Median, not mean: one bonus month should not flatter the ratio.
        return count($totals) % 2 === 0
            ? intdiv($totals[$mid - 1] + $totals[$mid], 2)
            : $totals[$mid];
    }

    // -- helpers -------------------------------------------------------------

    /** @return array<string,mixed> */
    private function castLoan(array $r): array
    {
        return [
            'id'                => (int) $r['id'],
            'account_id'        => $r['account_id'] !== null ? (int) $r['account_id'] : null,
            'name'              => $r['name'],
            'lender'            => $r['lender'],
            'loan_type'         => $r['loan_type'],
            'principal'         => (int) $r['principal'],
            'start_date'        => $r['start_date'],
            'first_emi_date'    => $r['first_emi_date'],
            'tenure_months'     => (int) $r['tenure_months'],
            'interest_rate_apr' => (float) $r['interest_rate_apr'],
            'emi_amount'        => $r['emi_amount'] !== null ? (int) $r['emi_amount'] : null,
            'pre_emi_mode'      => $r['pre_emi_mode'] ?? 'pay',
            'possession_date'   => $r['possession_date'] ?? null,
            'is_closed'         => (bool) $r['is_closed'],
            'notes'             => $r['notes'] ?? null,
        ];
    }

    /** accounts.name is UNIQUE; a second "Car Loan" must not blow up the insert. */
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
