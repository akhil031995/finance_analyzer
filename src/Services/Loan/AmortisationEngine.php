<?php

declare(strict_types=1);

namespace App\Services\Loan;

use DateTimeImmutable;
use RuntimeException;

/**
 * Monthly-reducing-balance amortisation, the convention every Indian bank uses
 * for home, personal, auto, education and gold loans.
 *
 *     r         = APR / 12 / 100
 *     interest  = round(outstanding * r)
 *     principal = emi - interest
 *     outstanding -= principal
 *
 * Pure: no database, no clock, no I/O. Give it a loan and its events and it
 * returns the schedule. That is what makes it testable, and what lets the
 * prepayment simulator run hypothetical event lists without writing anything.
 *
 * The schedule is regenerated on every read rather than stored, so a rate change
 * partway through the loan is a single event row and not a 200-row rewrite.
 *
 * Two phases, because an under-construction property is released in tranches:
 *
 *   PRE-EMI   From the first tranche until first_emi_date, interest accrues only
 *             on the money actually drawn, and no principal is repaid. The
 *             outstanding does not move (unless pre_emi_mode = 'capitalise',
 *             where the interest is rolled into the balance instead of billed).
 *   EMI       The ordinary loop above, on whatever has been drawn by then. The
 *             tenure counts these instalments only.
 *
 * A loan with no `disbursement` event drew its whole principal on start_date, so
 * the pre-EMI phase is empty and nothing changes.
 *
 * ---------------------------------------------------------------------------
 * The invariant that keeps the arithmetic honest:
 *
 *     SUM(period.principal) + SUM(prepayments) == SUM(disbursements) + SUM(capitalised)
 *
 * Interest is rounded to the paise each month, so principal components are too;
 * the closing stub absorbs whatever rounding left behind. assertSound() checks
 * this on every run, in paise, and throws rather than quietly drifting. It also
 * catches a tranche that never made it into the schedule.
 * ---------------------------------------------------------------------------
 */
final class AmortisationEngine
{
    /** A loan that never amortises would iterate forever; stop and explain instead. */
    private const MAX_PERIODS = 600;

    /**
     * @param array{principal:int, start_date:string, first_emi_date:string, tenure_months:int,
     *              interest_rate_apr:float, emi_amount:int|null, pre_emi_mode?:string} $loan
     *                `principal` is the SANCTIONED amount; what you owe is what has
     *                actually been disbursed.
     * @param list<array{event_type:string, effective_date:string, rate_apr?:float|null,
     *                   emi_amount?:int|null, amount?:int|null, mode?:string|null}> $events
     *
     * @return array{
     *   periods: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   warnings: list<string>
     * }
     */
    public function run(array $loan, array $events = []): array
    {
        $sanctioned = (int) $loan['principal'];
        $tenure     = (int) $loan['tenure_months'];
        $rate       = (float) $loan['interest_rate_apr'];
        $preEmiMode = $loan['pre_emi_mode'] ?? 'pay';

        if ($sanctioned <= 0) {
            throw new RuntimeException('Loan principal must be greater than zero.');
        }
        if ($tenure < 1 || $tenure > self::MAX_PERIODS) {
            throw new RuntimeException('Tenure must be between 1 and ' . self::MAX_PERIODS . ' months.');
        }
        if ($rate < 0 || $rate >= 100) {
            throw new RuntimeException('Interest rate must be between 0% and 100%.');
        }

        $firstDue = new DateTimeImmutable($loan['first_emi_date']);
        [$disbursements, $rateChanges, $emiChanges, $prepayments] = $this->bucketEvents($events);

        // A loan with no disbursement events drew its whole principal on day one.
        // That is the ordinary case, and it keeps every existing loan working.
        if ($disbursements === []) {
            $disbursements = [[
                'event_type' => 'disbursement', 'effective_date' => (string) $loan['start_date'],
                'amount' => $sanctioned, 'mode' => 'keep_emi', '_key' => 'implicit',
            ]];
        }

        $totalPlanned = array_sum(array_map(static fn ($d) => (int) $d['amount'], $disbursements));
        if ($totalPlanned > $sanctioned) {
            throw new RuntimeException(sprintf(
                'Disbursements total %s, more than the %s sanctioned.',
                self::rupees($totalPlanned),
                self::rupees($sanctioned)
            ));
        }

        $warnings = [];
        if ($totalPlanned < $sanctioned) {
            $warnings[] = self::rupees($sanctioned - $totalPlanned) . ' of the sanctioned '
                . self::rupees($sanctioned) . ' has not been disbursed yet. The schedule below '
                . 'covers only the ' . self::rupees($totalPlanned) . ' actually drawn.';
        }

        // An explicit EMI applies from the first instalment; otherwise it is
        // derived once the drawdown is known, at the start of the EMI phase.
        $emi = $loan['emi_amount'] !== null && (int) $loan['emi_amount'] > 0
            ? (int) $loan['emi_amount']
            : null;

        $periods      = [];
        $outstanding  = 0;
        $prepaidTotal = 0;
        $drawnTotal   = 0;
        $capitalised  = 0;
        $preEmiInterest = 0;
        $periodNo     = 0;   // every row, pre-EMI included
        $emiPeriod    = 0;   // instalments only; the tenure counts these
        /** @var array<string,bool> $applied — each event fires exactly once */
        $applied = [];

        // Pre-EMI interest is billed on the EMI's day of the month, in ARREARS —
        // the month a tranche is drawn is charged on the following anchor. So the
        // first row sits in the first month strictly after the first drawdown.
        // A loan drawn in full one month before its first EMI therefore has no
        // pre-EMI phase at all, which is what an ordinary loan looks like.
        $firstDrawn = min(array_map(static fn ($d) => (string) $d['effective_date'], $disbursements));
        $anchor     = $this->firstAnchorAfterMonth($firstDue, substr($firstDrawn, 0, 7));

        while (true) {
            if (++$periodNo > self::MAX_PERIODS) {
                throw new RuntimeException(
                    'This loan never closes: after ' . self::MAX_PERIODS
                    . ' instalments the balance is still ' . self::rupees($outstanding)
                    . '. Check the EMI and the interest rate.'
                );
            }

            // Always anchor + offset, never an incremental +1 month: stepping
            // month by month from 31 Jan clamps to 28 Feb and then stays there.
            $due      = self::addMonths($anchor, $periodNo - 1);
            $dueKey   = $due->format('Y-m-d');
            $dueMonth = $due->format('Y-m');
            $isPreEmi = $dueKey < $firstDue->format('Y-m-d');

            // --- Events take effect from the instalment of the month they fall in.
            // "Set the EMI in March" means the March instalment already uses it,
            // whichever day of March the change is dated.

            // A tranche lands BEFORE this month's interest, so the bank charges
            // interest on the larger balance from the month it was released.
            $drawnThisMonth = 0;
            foreach ($disbursements as $d) {
                if ($applied[$d['_key']] ?? false) {
                    continue;
                }
                if (substr((string) $d['effective_date'], 0, 7) > $dueMonth) {
                    continue;
                }
                $applied[$d['_key']] = true;
                $amount = (int) $d['amount'];
                $outstanding    += $amount;
                $drawnTotal     += $amount;
                $drawnThisMonth += $amount;

                // A tranche arriving after the EMI has begun raises the balance.
                // Banks hold the EMI and extend; keep_tenure re-solves instead.
                if (!$isPreEmi && $emi !== null && ($d['mode'] ?? 'keep_emi') === 'keep_tenure') {
                    $emi = self::annuity($outstanding, $rate, max(1, $tenure - $emiPeriod));
                }
            }

            foreach ($rateChanges as $rc) {
                if (($applied[$rc['_key']] ?? false) || substr((string) $rc['effective_date'], 0, 7) > $dueMonth) {
                    continue;
                }
                $applied[$rc['_key']] = true;
                $rate = (float) $rc['rate_apr'];
                // Banks hold the EMI and let the tenure float. keep_tenure
                // instead re-solves the EMI over whatever months remain.
                //
                // $emiPeriod is not yet incremented for this instalment, so the
                // months remaining INCLUDING it are `tenure - $emiPeriod`.
                if ($emi !== null && ($rc['mode'] ?? 'keep_emi') === 'keep_tenure') {
                    $emi = self::annuity($outstanding, $rate, max(1, $tenure - $emiPeriod));
                }
            }

            foreach ($emiChanges as $ec) {
                if (($applied[$ec['_key']] ?? false) || substr((string) $ec['effective_date'], 0, 7) > $dueMonth) {
                    continue;
                }
                $applied[$ec['_key']] = true;
                $emi = (int) $ec['emi_amount'];
            }

            $opening     = $outstanding;
            $monthlyRate = $rate / 12 / 100;
            $interest    = (int) round($outstanding * $monthlyRate);

            if ($isPreEmi) {
                // --- Pre-EMI: interest only. No principal is repaid, so the
                // outstanding does not move (unless the bank capitalises it).
                $capitalise = $preEmiMode === 'capitalise';
                $payment    = $capitalise ? 0 : $interest;
                if ($capitalise) {
                    $outstanding += $interest;
                    $capitalised += $interest;
                }
                $preEmiInterest += $interest;
                $princip = 0;
                $isStub  = false;
            } else {
                if ($outstanding <= 0) {
                    throw new RuntimeException(sprintf(
                        'Nothing has been disbursed by %s, when the first EMI falls, so there is '
                        . 'no loan to amortise. The earliest tranche is dated %s. Either move the '
                        . 'first EMI to after that date, or add the tranche that should come before it.',
                        $firstDue->format('j M Y'),
                        (new DateTimeImmutable($firstDrawn))->format('j M Y')
                    ));
                }
                // The drawdown is only known now, so this is where an unspecified
                // EMI is solved for.
                if ($emi === null) {
                    $emi = self::annuity($outstanding, $rate, $tenure);
                }
                $emiPeriod++;

                // Negative amortisation: the EMI does not even cover the interest,
                // so the balance grows every month. Refuse rather than loop.
                if ($emi <= $interest) {
                    throw new RuntimeException(sprintf(
                        'EMI of %s is below the interest of %s due in %s (at %.2f%%). '
                        . 'The balance would grow every month and the loan would never close.%s',
                        self::rupees($emi),
                        self::rupees($interest),
                        $due->format('M Y'),
                        $rate,
                        // The commonest cause: a tranche landed mid-loan and the EMI
                        // was held. Say so, rather than leaving the user to work it out.
                        $drawnThisMonth > 0
                            ? ' The ' . self::rupees($drawnThisMonth) . ' disbursed this month is too large'
                              . ' to absorb while holding the EMI — recalculate the EMI on this tranche instead.'
                            : ''
                    ));
                }

                // Closing instalment: pay off whatever is left, plus this month's
                // interest. Never charge a full EMI for a stub.
                $isStub  = ($outstanding + $interest) <= $emi;
                $payment = $isStub ? $outstanding + $interest : $emi;
                $princip = $payment - $interest;
                $outstanding -= $princip;
            }

            // --- Prepayments land after the EMI, at the monthly rest ----------
            // The per-prepayment dates are kept alongside the month's total: an
            // instalment is a month wide, and a prepayment made on the 11th is
            // money already gone even though the instalment is not due till the
            // 18th. position() needs the dates to tell those two apart.
            $prepaidThisMonth = 0;
            $prepaidDetail    = [];
            foreach ($prepayments as $pp) {
                if (($applied[$pp['_key']] ?? false) || $outstanding <= 0) {
                    continue;
                }
                // Anything dated on or before this instalment's month that has not
                // yet landed — so a prepayment during the pre-EMI phase still counts.
                if (substr((string) $pp['effective_date'], 0, 7) > $dueMonth) {
                    continue;
                }
                $applied[$pp['_key']] = true;

                $amount = min((int) $pp['amount'], $outstanding);   // never overpay
                if ($amount <= 0) {
                    continue;
                }
                $outstanding      -= $amount;
                $prepaidThisMonth += $amount;
                $prepaidTotal     += $amount;
                $prepaidDetail[]  = ['date' => (string) $pp['effective_date'], 'amount' => $amount];

                // reduce_emi holds the loan's ORIGINAL end date and drops the
                // instalment; reduce_tenure (the default, and the cheaper one)
                // holds the EMI and lets the loan finish early.
                if ($emi !== null && $outstanding > 0 && ($pp['mode'] ?? 'reduce_tenure') === 'reduce_emi') {
                    $emi = self::annuity($outstanding, $rate, max(1, $tenure - $emiPeriod));
                }
            }

            $periods[] = [
                'period_no'       => $periodNo,
                'due_date'        => $dueKey,
                'opening_balance' => $opening,
                'disbursement'    => $drawnThisMonth,
                'emi'             => $payment,
                'interest'        => $interest,
                'principal'       => $princip,
                'prepayment'        => $prepaidThisMonth,
                'prepayment_detail' => $prepaidDetail,
                'closing_balance' => $outstanding,
                'rate_apr'        => round($rate, 4),
                'is_stub'         => $isStub,
                'is_pre_emi'      => $isPreEmi,
            ];

            // A pre-EMI phase can leave the balance at zero only if every tranche
            // was prepaid away; otherwise keep going until the EMI phase clears it.
            if (!$isPreEmi && $outstanding <= 0) {
                break;
            }
            if ($isPreEmi && $outstanding <= 0 && $this->allApplied($disbursements, $applied)) {
                break;
            }
        }

        // Prepaying so hard that the loan closes early can strand a later event.
        // Say so rather than silently dropping the money.
        foreach ($prepayments as $pp) {
            if (!($applied[$pp['_key']] ?? false)) {
                $warnings[] = 'Prepayment of ' . self::rupees((int) $pp['amount']) . ' on '
                    . $pp['effective_date'] . ' falls after the loan is already paid off — ignored.';
            }
        }
        foreach ($disbursements as $d) {
            if (!($applied[$d['_key']] ?? false)) {
                $warnings[] = 'Disbursement of ' . self::rupees((int) $d['amount']) . ' on '
                    . $d['effective_date'] . ' falls after the loan is already paid off — ignored.';
            }
        }
        foreach ([...$rateChanges, ...$emiChanges] as $e) {
            if (!($applied[$e['_key']] ?? false)) {
                $warnings[] = ucfirst(str_replace('_', ' ', (string) $e['event_type'])) . ' dated '
                    . $e['effective_date'] . ' falls after the loan is paid off — ignored.';
            }
        }

        $this->assertSound($periods, $drawnTotal + $capitalised, $prepaidTotal);

        $summary = $this->summarise($periods, $sanctioned, $prepaidTotal, (int) $emi, $tenure);
        $summary['sanctioned']       = $sanctioned;
        $summary['disbursed']        = $drawnTotal;
        $summary['undisbursed']      = $sanctioned - $totalPlanned;
        $summary['tranches']         = count($disbursements);
        $summary['pre_emi_months']   = count(array_filter($periods, static fn ($p) => $p['is_pre_emi']));
        $summary['pre_emi_interest'] = $preEmiInterest;
        $summary['capitalised']      = $capitalised;
        // interest_pct is only meaningful against what you actually borrowed.
        $summary['interest_pct'] = $drawnTotal > 0
            ? round(($summary['total_interest'] / $drawnTotal) * 100, 1)
            : 0.0;

        return [
            'periods'  => $periods,
            'summary'  => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * The EMI falls on a fixed day of the month. Walk that anchor back from the
     * first EMI to the earliest month strictly after `$drawnMonth` (YYYY-MM).
     * Never earlier than the first EMI itself, so an ordinary loan starts there.
     */
    private function firstAnchorAfterMonth(DateTimeImmutable $firstEmi, string $drawnMonth): DateTimeImmutable
    {
        $anchor = $firstEmi;
        while (true) {
            $prev = self::addMonths($anchor, -1);
            if ($prev->format('Y-m') <= $drawnMonth) {
                return $anchor;
            }
            $anchor = $prev;
        }
    }

    /** @param list<array<string,mixed>> $events @param array<string,bool> $applied */
    private function allApplied(array $events, array $applied): bool
    {
        foreach ($events as $e) {
            if (!($applied[$e['_key']] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Standard annuity: EMI = P·r·(1+r)^n / ((1+r)^n − 1).
     * At 0% it degenerates to straight-line repayment.
     */
    public static function annuity(int $principal, float $apr, int $months): int
    {
        if ($months < 1) {
            throw new RuntimeException('Cannot compute an EMI over zero months.');
        }
        $r = $apr / 12 / 100;
        if ($r <= 0.0) {
            return (int) ceil($principal / $months);
        }
        $growth = (1 + $r) ** $months;

        // ceil, not round: a rounded-down EMI can leave the loan a paise short of
        // closing on its final scheduled instalment.
        return (int) ceil($principal * $r * $growth / ($growth - 1));
    }

    /**
     * Calendar-safe month arithmetic. DateTime's `+1 month` turns 31 Jan into
     * 3 March; an EMI due on the 31st must fall on the 28th in February.
     */
    public static function addMonths(DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        if ($months === 0) {
            return $from;
        }
        $day    = (int) $from->format('d');
        $anchor = $from->modify('first day of this month')->modify("+{$months} months");
        $last   = (int) $anchor->format('t');

        return $anchor->setDate(
            (int) $anchor->format('Y'),
            (int) $anchor->format('m'),
            min($day, $last)
        )->setTime(0, 0);
    }

    /**
     * Every rupee drawn must come back. Interest is rounded to the paise each
     * month, so this is the assertion that catches a drifting engine.
     *
     * With tranches the right-hand side is no longer the sanctioned amount:
     *
     *     Σ principal components + Σ prepayments == Σ disbursements + Σ capitalised
     *
     * which also catches a tranche that was never applied to the schedule.
     *
     * @param list<array<string,mixed>> $periods
     * @param int $drawn disbursed + capitalised interest
     */
    private function assertSound(array $periods, int $drawn, int $prepaid): void
    {
        $repaid = $prepaid;
        foreach ($periods as $p) {
            $repaid += (int) $p['principal'];
        }
        if ($repaid !== $drawn) {
            throw new RuntimeException(sprintf(
                'Amortisation drift: principal repaid (%d paise) != principal drawn (%d paise). '
                . 'Difference of %d paise.',
                $repaid,
                $drawn,
                $repaid - $drawn
            ));
        }
        if ($periods !== [] && (int) end($periods)['closing_balance'] !== 0) {
            throw new RuntimeException('Amortisation drift: the loan does not close at zero.');
        }
    }

    /**
     * Split events by type, oldest first, stamping each with a `_key` unique
     * within the run. run() latches on that key so an event fires exactly once —
     * on the first instalment whose month reaches it.
     *
     * @param list<array<string,mixed>> $events
     * @return array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>,
     *               2:list<array<string,mixed>>, 3:list<array<string,mixed>>}
     */
    private function bucketEvents(array $events): array
    {
        usort($events, static fn ($a, $b) => strcmp((string) $a['effective_date'], (string) $b['effective_date']));

        $disb = $rate = $emi = $prepay = [];
        foreach ($events as $i => $e) {
            // Simulated events carry no id, and two prepayments can share a date,
            // so the ordinal is the only reliable identity.
            $e['_key'] = (string) $i;
            match ($e['event_type']) {
                'disbursement' => $disb[]   = $e,
                'rate_change'  => $rate[]   = $e,
                'emi_change'   => $emi[]    = $e,
                'prepayment'   => $prepay[] = $e,
                default        => throw new RuntimeException("Unknown loan event '{$e['event_type']}'."),
            };
        }

        return [$disb, $rate, $emi, $prepay];
    }

    /**
     * @param list<array<string,mixed>> $periods
     * @return array<string,mixed>
     */
    private function summarise(array $periods, int $principal, int $prepaid, int $finalEmi, int $tenure): array
    {
        $totalInterest = 0;
        $totalPaid     = 0;
        $crossover     = null;

        foreach ($periods as $p) {
            $totalInterest += (int) $p['interest'];
            $totalPaid     += (int) $p['emi'] + (int) $p['prepayment'];
            // The month your principal component first overtakes your interest —
            // on a home loan, the moment the loan starts working for you.
            if ($crossover === null && (int) $p['principal'] > (int) $p['interest']) {
                $crossover = ['period_no' => $p['period_no'], 'due_date' => $p['due_date']];
            }
        }

        $last = end($periods) ?: null;

        return [
            'principal'        => $principal,
            'total_interest'   => $totalInterest,
            'total_paid'       => $totalPaid,
            'total_prepaid'    => $prepaid,
            'periods'          => count($periods),
            'scheduled_tenure' => $tenure,
            'payoff_date'      => $last['due_date'] ?? null,
            'final_emi'        => $finalEmi,
            'crossover'        => $crossover,
            // What the debt actually costs, as a share of what you borrowed.
            'interest_pct'     => $principal > 0 ? round(($totalInterest / $principal) * 100, 1) : 0.0,
        ];
    }

    private static function rupees(int $paise): string
    {
        return '₹' . number_format($paise / 100, 2);
    }
}
