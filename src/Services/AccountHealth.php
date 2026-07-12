<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Does an account's ledger actually add up to its own statement?
 *
 * Two independent things go wrong, both silently, and they need different fixes:
 *
 *   opening_drift  `opening_balance` disagrees with what the oldest row implies.
 *                  Almost always: a statement OLDER than the rest was imported
 *                  after the opening balance had been derived from a later one,
 *                  so the back-filled flows are counted twice. (This is how HDFC
 *                  reached −₹1,09,733.95 with no liabilities.)
 *
 *   missing_net    The statement's own running balance moves in ways its amount
 *                  columns cannot explain: rows are absent from the file itself.
 *                  No opening balance can fix that — the rows have to come back.
 *
 * The two compose exactly:
 *
 *      balance_drift = missing_net + opening_drift
 *
 * where balance_drift is (statement's closing balance − the balance we show).
 * That identity is asserted on every run; if it fails, `verified` goes false
 * rather than the report quietly lying.
 *
 * ---------------------------------------------------------------------------
 * This class only ever REPORTS. It never writes, and never "corrects" an opening
 * balance. An opening balance that no statement implies is perfectly legitimate
 * (an account older than your oldest statement), and silently rewriting one
 * would paper over missing transactions — turning a visible ₹5.5L hole into a
 * confident, wrong net worth.
 * ---------------------------------------------------------------------------
 */
final class AccountHealth
{
    private const MAX_BREAKS_REPORTED = 5;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> one entry per non-derived account that has rows */
    public function all(): array
    {
        $ids = $this->pdo->query(
            'SELECT id FROM accounts WHERE is_archived = 0 AND is_derived = 0 ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        foreach ($ids as $id) {
            $r = $this->report((int) $id);
            if ($r !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null null when no row carries a balance to check against */
    public function report(int $accountId): ?array
    {
        $acct = $this->pdo->prepare(
            'SELECT id, name, opening_balance, current_balance, is_liability, is_derived
             FROM accounts WHERE id = ?'
        );
        $acct->execute([$accountId]);
        $a = $acct->fetch(PDO::FETCH_ASSOC);
        if ($a === false || (int) $a['is_derived'] === 1) {
            return null;
        }

        $isLiability = (int) $a['is_liability'] === 1;

        // Statements disagree about row order: HDFC writes oldest-first, Kotak
        // newest-first. Within one date the id sequence therefore runs forwards
        // for one bank and backwards for the other.
        //
        // Order affects only WHERE we say a break is, never WHETHER the ledger
        // adds up — that verdict comes from the order-independent sums below. So
        // it is safe to take whichever direction explains the data; a wrong guess
        // can only invent breaks, never hide a missing rupee.
        $forward = $this->ledger($accountId, 'txn_date, id');
        if ($forward === []) {
            return null;
        }
        $backward = $this->ledger($accountId, 'txn_date, id DESC');

        [$fBreaks] = $this->walk($forward, $isLiability);
        [$bBreaks] = $this->walk($backward, $isLiability);

        $chronological = count($bBreaks) < count($fBreaks) ? $backward : $forward;
        $breaks        = count($bBreaks) < count($fBreaks) ? $bBreaks : $fBreaks;
        $rowOrder      = count($bBreaks) < count($fBreaks) ? 'newest_first_file' : 'oldest_first_file';

        $first = $chronological[0];
        $last  = $chronological[count($chronological) - 1];

        $impliedOpening   = (int) $first['balance_after'] - $this->delta($first, $isLiability);
        $storedOpening    = (int) $a['opening_balance'];
        $statementBalance = (int) $last['balance_after'];
        $currentBalance   = (int) $a['current_balance'];

        // Order-independent. This, not the walk, decides whether anything is wrong.
        $flows      = $this->flows($accountId, $isLiability);
        $missingNet = $statementBalance - $impliedOpening - $flows;

        $openingDrift = $impliedOpening - $storedOpening;
        $balanceDrift = $statementBalance - $currentBalance;

        return [
            'account_id'        => (int) $a['id'],
            'name'              => $a['name'],
            'txns'              => count($chronological),
            'first_date'        => $first['txn_date'],
            'last_date'         => $last['txn_date'],
            'row_order'         => $rowOrder,

            'stored_opening'    => $storedOpening,
            'implied_opening'   => $impliedOpening,
            'opening_drift'     => $openingDrift,

            'statement_balance' => $statementBalance,
            'current_balance'   => $currentBalance,
            'balance_drift'     => $balanceDrift,

            // Net value of rows the statement's balance column implies but the
            // ledger does not hold. Positive => missing credits.
            'missing_net'       => $missingNet,
            'breaks'            => count($breaks),
            'first_breaks'      => array_slice($breaks, 0, self::MAX_BREAKS_REPORTED),

            // If this identity fails, something is wrong with the report itself
            // (unidentifiable endpoints, say) and none of it should be trusted.
            'verified'          => $balanceDrift === $missingNet + $openingDrift,

            'reconciled'        => $balanceDrift === 0 && $missingNet === 0,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function ledger(int $accountId, string $order): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT txn_date, cashflow, amount, balance_after
             FROM transactions
             WHERE account_id = ? AND balance_after IS NOT NULL
             ORDER BY ' . $order
        );
        $stmt->execute([$accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Σ of every row's signed effect. Includes rows with no balance column, which
     * the walk cannot see — so this is the honest total.
     */
    private function flows(int $accountId, bool $isLiability): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN cashflow = 'credit' THEN amount END), 0) cr,
                    COALESCE(SUM(CASE WHEN cashflow = 'debit'  THEN amount END), 0) dr
             FROM transactions WHERE account_id = ? AND balance_after IS NOT NULL"
        );
        $stmt->execute([$accountId]);
        ['cr' => $cr, 'dr' => $dr] = $stmt->fetch(PDO::FETCH_ASSOC);

        return $isLiability ? (int) $dr - (int) $cr : (int) $cr - (int) $dr;
    }

    /**
     * Each row's balance must equal the previous row's balance plus this row's
     * signed amount. Used only to LOCATE breaks, never to judge the account.
     *
     * @param  list<array<string,mixed>> $ledger
     * @return array{0:list<array<string,mixed>>, 1:int}
     */
    private function walk(array $ledger, bool $isLiability): array
    {
        $breaks = [];
        $net    = 0;
        $prev   = null;

        foreach ($ledger as $row) {
            if ($prev !== null) {
                $expected = (int) $prev['balance_after'] + $this->delta($row, $isLiability);
                $actual   = (int) $row['balance_after'];
                if ($expected !== $actual) {
                    $gap  = $actual - $expected;
                    $net += $gap;
                    $breaks[] = [
                        'after'    => $prev['txn_date'],
                        'before'   => $row['txn_date'],
                        'expected' => $expected,
                        'actual'   => $actual,
                        'gap'      => $gap,
                        'missing'  => $gap > 0 ? 'credit' : 'debit',
                    ];
                }
            }
            $prev = $row;
        }

        return [$breaks, $net];
    }

    /** Signed effect of one row, per the account's own convention. */
    private function delta(array $row, bool $isLiability): int
    {
        $amount = (int) $row['amount'];
        $credit = $row['cashflow'] === 'credit';

        // On a liability, a debit (a purchase) increases what you owe.
        return $isLiability
            ? ($credit ? -$amount : $amount)
            : ($credit ? $amount : -$amount);
    }
}
