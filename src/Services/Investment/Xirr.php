<?php

declare(strict_types=1);

namespace App\Services\Investment;

/**
 * XIRR — the internal rate of return of irregularly-timed cashflows.
 *
 * It is the annual rate r for which the net present value of the flows is zero:
 *
 *     Σ  cashflow_i / (1 + r) ^ (days_i / 365)  =  0
 *
 * Money you put in is negative, money you get back (including the holding's
 * current value, dated today) is positive. The result is money-weighted: a
 * return that rewards or punishes the TIMING of your contributions, which a
 * simple "gain / invested" percentage cannot see.
 *
 * Pure: no dependencies, no clock, no I/O. Solved by bisection rather than
 * Newton-Raphson — NPV(r) is monotonic decreasing in r wherever a solution
 * exists, so bisection cannot diverge or land on the wrong root the way Newton
 * can when the derivative is near zero.
 */
final class Xirr
{
    private const DAYS_PER_YEAR = 365.0;
    private const MAX_ITER      = 200;
    private const TOLERANCE     = 1e-7;   // NPV close enough to zero, in currency units

    /** Rates outside this band are treated as "no meaningful answer". */
    private const RATE_LOW  = -0.999999;   // −100% would divide by zero
    private const RATE_HIGH = 1000.0;      // 100,000% a year

    /**
     * @param list<array{date:string, amount:float|int}> $flows amount in any
     *        consistent unit (paise here); date as 'Y-m-d'. Sign matters.
     * @return float|null annual rate as a fraction (0.14 = 14%), or null when
     *         it is undefined (fewer than two flows, or no sign change — you
     *         cannot have a return on money that only ever went one way).
     */
    public static function rate(array $flows): ?float
    {
        if (count($flows) < 2) {
            return null;
        }

        $t0 = strtotime($flows[0]['date']);
        $days = [];
        $amts = [];
        $hasPos = false;
        $hasNeg = false;
        foreach ($flows as $f) {
            $days[] = ((int) strtotime($f['date']) - $t0) / 86400.0;
            $a = (float) $f['amount'];
            $amts[] = $a;
            if ($a > 0) { $hasPos = true; }
            if ($a < 0) { $hasNeg = true; }
        }
        if (!$hasPos || !$hasNeg) {
            return null;
        }

        $npv = static function (float $r) use ($days, $amts): float {
            $base = 1.0 + $r;
            $sum  = 0.0;
            foreach ($amts as $i => $a) {
                $sum += $a / ($base ** ($days[$i] / self::DAYS_PER_YEAR));
            }

            return $sum;
        };

        // NPV is decreasing in r: with net money invested it is positive at very
        // negative rates and negative at very high ones. Confirm the bracket
        // actually straddles zero before trusting bisection.
        $lo = self::RATE_LOW;
        $hi = self::RATE_HIGH;
        $fLo = $npv($lo);
        $fHi = $npv($hi);
        if ($fLo == 0.0) { return $lo; }
        if ($fHi == 0.0) { return $hi; }
        if (($fLo > 0) === ($fHi > 0)) {
            return null;   // no root in a sane range (e.g. a total, immediate loss)
        }

        for ($i = 0; $i < self::MAX_ITER; $i++) {
            $mid  = ($lo + $hi) / 2.0;
            $fMid = $npv($mid);
            if (abs($fMid) < self::TOLERANCE || ($hi - $lo) < 1e-9) {
                return $mid;
            }
            if (($fMid > 0) === ($fLo > 0)) {
                $lo  = $mid;
                $fLo = $fMid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2.0;
    }
}
