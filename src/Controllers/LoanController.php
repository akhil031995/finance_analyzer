<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Loan\LoanService;
use App\Support\Money;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 *   GET    /api/loans                          portfolio: every loan + blended stats
 *   POST   /api/loans                          create (also creates its liability account)
 *   GET    /api/loans/{id}                     full report: schedule, position, rollups
 *   PATCH  /api/loans/{id}                     edit terms
 *   DELETE /api/loans/{id}                     delete loan + its account + events + links
 *   POST   /api/loans/{id}/events              disbursement | rate_change | emi_change | prepayment
 *   PATCH  /api/loans/{id}/events/{eventId}  edit an event
 *   DELETE /api/loans/{id}/events/{eventId}
 *   POST   /api/loans/{id}/simulate            what-if; writes nothing
 *   POST   /api/loans/{id}/payments            link a ledger txn to an instalment
 *   DELETE /api/loans/{id}/payments/{periodNo} unlink
 *   GET    /api/loans/{id}/candidates          unlinked emi-tagged debits, for the picker
 *
 * Amounts cross this boundary in RUPEES and are stored in paise, as everywhere
 * else in the app. Rates cross as a percentage (8.5 means 8.5% a year).
 */
final class LoanController
{
    private const TYPES  = ['home', 'personal', 'auto', 'education', 'gold', 'business', 'other'];
    private const EVENTS = ['disbursement', 'rate_change', 'emi_change', 'prepayment'];
    private const PRE_EMI_MODES = ['pay', 'capitalise'];

    public function __construct(private PDO $pdo, private LoanService $loans)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, $this->loans->portfolio());
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->reported($response, (int) $args['id']);
    }

    /**
     * Every write returns the fresh report — and every one of them can leave the
     * loan unamortisable, either because it already was or because the user is
     * midway through repairing it. Answer 422 with the loan and its events (the
     * UI needs both to offer a way out) instead of a 500.
     */
    private function reported(Response $response, int $loanId, array $extra = [], int $status = 200): Response
    {
        try {
            return $this->json($response, $extra + $this->loans->report($loanId), $status);
        } catch (RuntimeException $e) {
            return $this->json($response, $extra + [
                'error'   => 'unamortisable',
                'message' => $e->getMessage(),
                'loan'    => $this->safeLoan($loanId),
                'events'  => $this->safeEvents($loanId),
            ], 422);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();

        $errors = [];
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Give the loan a name.';
        }
        $principal = $this->paise($b['principal'] ?? null);
        if ($principal === null || $principal <= 0) {
            $errors[] = 'Principal must be greater than zero.';
        }
        $tenure = (int) ($b['tenure_months'] ?? 0);
        if ($tenure < 1 || $tenure > 600) {
            $errors[] = 'Tenure must be between 1 and 600 months.';
        }
        $rate = (float) ($b['interest_rate_apr'] ?? -1);
        if ($rate < 0 || $rate >= 100) {
            $errors[] = 'Interest rate must be between 0% and 100% a year.';
        }
        $start = $this->date($b['start_date'] ?? null);
        $first = $this->date($b['first_emi_date'] ?? null);
        if ($start === null) {
            $errors[] = 'Disbursal date must be YYYY-MM-DD.';
        }
        if ($first === null) {
            $errors[] = 'First EMI date must be YYYY-MM-DD.';
        }
        if ($start !== null && $first !== null && $first < $start) {
            $errors[] = 'The first EMI cannot fall before the loan was disbursed.';
        }
        $type = (string) ($b['loan_type'] ?? 'other');
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Unknown loan type.';
        }
        $preEmiMode = (string) ($b['pre_emi_mode'] ?? 'pay');
        if (!in_array($preEmiMode, self::PRE_EMI_MODES, true)) {
            $errors[] = 'pre_emi_mode must be pay or capitalise.';
        }
        $possession = null;
        if (($b['possession_date'] ?? '') !== '') {
            $possession = $this->date($b['possession_date']);
            if ($possession === null) {
                $errors[] = 'Possession date must be YYYY-MM-DD.';
            } elseif ($start !== null && $possession < $start) {
                $errors[] = 'Possession cannot pre-date the loan.';
            }
        }
        if ($errors !== []) {
            return $this->json($response, ['error' => 'validation', 'message' => implode(' ', $errors)], 400);
        }

        // An EMI is optional: given a tenure, the annuity formula derives it.
        $emi = $this->paise($b['emi_amount'] ?? null);

        try {
            $id = $this->loans->create([
                'name'              => $name,
                'lender'            => trim((string) ($b['lender'] ?? '')) ?: null,
                'loan_type'         => $type,
                'principal'         => $principal,
                'start_date'        => $start,
                'first_emi_date'    => $first,
                'tenure_months'     => $tenure,
                'interest_rate_apr' => $rate,
                'emi_amount'        => $emi !== null && $emi > 0 ? $emi : null,
                'pre_emi_mode'      => $preEmiMode,
                'possession_date'   => $possession,
                'notes'             => trim((string) ($b['notes'] ?? '')) ?: null,
            ]);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id, ['id' => $id], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();

        $data = [];
        foreach (['name', 'lender', 'notes'] as $k) {
            if (array_key_exists($k, $b)) {
                $data[$k] = trim((string) $b[$k]) ?: null;
            }
        }
        if (array_key_exists('loan_type', $b)) {
            if (!in_array($b['loan_type'], self::TYPES, true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'Unknown loan type.'], 400);
            }
            $data['loan_type'] = $b['loan_type'];
        }
        foreach (['principal', 'emi_amount'] as $k) {
            if (array_key_exists($k, $b)) {
                $data[$k] = $this->paise($b[$k]);
            }
        }
        foreach (['start_date', 'first_emi_date'] as $k) {
            if (array_key_exists($k, $b)) {
                $d = $this->date($b[$k]);
                if ($d === null) {
                    return $this->json($response, ['error' => 'validation', 'message' => "{$k} must be YYYY-MM-DD."], 400);
                }
                $data[$k] = $d;
            }
        }
        if (array_key_exists('tenure_months', $b)) {
            $data['tenure_months'] = max(1, min(600, (int) $b['tenure_months']));
        }
        if (array_key_exists('interest_rate_apr', $b)) {
            $data['interest_rate_apr'] = (float) $b['interest_rate_apr'];
        }
        if (array_key_exists('pre_emi_mode', $b)) {
            if (!in_array($b['pre_emi_mode'], self::PRE_EMI_MODES, true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'pre_emi_mode must be pay or capitalise.'], 400);
            }
            $data['pre_emi_mode'] = $b['pre_emi_mode'];
        }
        if (array_key_exists('possession_date', $b)) {
            // Clearing it puts the tax view back to "deductible as paid".
            if (trim((string) $b['possession_date']) === '') {
                $data['possession_date'] = null;
            } else {
                $d = $this->date($b['possession_date']);
                if ($d === null) {
                    return $this->json($response, ['error' => 'validation', 'message' => 'possession_date must be YYYY-MM-DD.'], 400);
                }
                $data['possession_date'] = $d;
            }
        }
        if ($data === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Nothing to change.'], 400);
        }

        try {
            $this->loans->update($id, $data);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->loans->delete((int) $args['id']);

        return $this->json($response, ['deleted' => true]);
    }

    // -- events --------------------------------------------------------------

    public function addEvent(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $b    = (array) $request->getParsedBody();
        try {
            $data = $this->validateEvent($b);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        try {
            $eventId = $this->loans->addEvent($id, $data);
        } catch (RuntimeException $e) {
            // addEvent() re-runs the engine inside its transaction and rolls back,
            // so a rejected event never reaches the table.
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id, ['id' => $eventId], 201);
    }

    /** PATCH /api/loans/{id}/events/{eventId} — correct a mistyped rate, date or amount. */
    public function updateEvent(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();

        try {
            $data = $this->validateEvent($b);
            $this->loans->updateEvent($id, (int) $args['eventId'], $data);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        $this->loans->deleteEvent((int) $args['id'], (int) $args['eventId']);

        return $this->reported($response, (int) $args['id']);
    }

    /**
     * One rulebook for creating and for editing an event — they must not be able
     * to drift apart, or an edit could store something a create would reject.
     *
     * @throws RuntimeException with a message meant for the user
     * @return array<string,mixed>
     */
    private function validateEvent(array $b): array
    {
        $type = (string) ($b['event_type'] ?? '');
        if (!in_array($type, self::EVENTS, true)) {
            throw new RuntimeException('event_type must be disbursement, rate_change, emi_change or prepayment.');
        }
        $date = $this->date($b['effective_date'] ?? null);
        if ($date === null) {
            throw new RuntimeException('effective_date must be YYYY-MM-DD.');
        }

        $data = ['event_type' => $type, 'effective_date' => $date, 'note' => trim((string) ($b['note'] ?? '')) ?: null];

        switch ($type) {
            case 'disbursement':
                $amount = $this->paise($b['amount'] ?? null);
                if ($amount === null || $amount <= 0) {
                    throw new RuntimeException('A tranche must be greater than zero.');
                }
                $data['amount'] = $amount;
                // Only matters for a tranche landing after the EMI has begun.
                $data['mode'] = in_array($b['mode'] ?? '', ['keep_emi', 'keep_tenure'], true) ? $b['mode'] : 'keep_emi';
                break;

            case 'rate_change':
                $rate = (float) ($b['rate_apr'] ?? -1);
                if ($rate < 0 || $rate >= 100) {
                    throw new RuntimeException('rate_apr must be between 0 and 100.');
                }
                $data['rate_apr'] = $rate;
                $data['mode'] = in_array($b['mode'] ?? '', ['keep_emi', 'keep_tenure'], true) ? $b['mode'] : 'keep_emi';
                break;

            case 'emi_change':
                $emi = $this->paise($b['emi_amount'] ?? null);
                if ($emi === null || $emi <= 0) {
                    throw new RuntimeException('emi_amount must be greater than zero.');
                }
                $data['emi_amount'] = $emi;
                break;

            case 'prepayment':
                $amount = $this->paise($b['amount'] ?? null);
                if ($amount === null || $amount <= 0) {
                    throw new RuntimeException('amount must be greater than zero.');
                }
                $data['amount'] = $amount;
                $data['mode'] = in_array($b['mode'] ?? '', ['reduce_tenure', 'reduce_emi'], true) ? $b['mode'] : 'reduce_tenure';
                break;
        }

        return $data;
    }

    // -- simulation ----------------------------------------------------------

    /** A plan of more than this many prepayments is a mistake, not a question. */
    private const MAX_WHATIFS = 20;

    /**
     * A what-if plan is a list of prepayments considered together:
     *
     *   {whatifs: [
     *     {mode: "lumpsum", amount: 100000, on: "2026-08-05"},
     *     {mode: "monthly", amount: 5000, from: "2027-01-05", months: 24}
     *   ]}
     *
     * A bare {mode, amount, ...} body is still accepted as a plan of one.
     * Nothing is written; the engine runs twice and the difference is returned.
     */
    public function simulate(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $b    = (array) $request->getParsedBody();
        $plan = $b['whatifs'] ?? null;

        if (!is_array($plan) || $plan === []) {
            $plan = [$b];   // legacy single what-if
        }
        if (count($plan) > self::MAX_WHATIFS) {
            return $this->json($response, ['error' => 'validation',
                'message' => 'A plan can hold at most ' . self::MAX_WHATIFS . ' prepayments.'], 400);
        }

        try {
            $events = [];
            foreach (array_values($plan) as $i => $spec) {
                if (!is_array($spec)) {
                    throw new RuntimeException('Prepayment ' . ($i + 1) . ' is not readable.');
                }
                try {
                    $events = [...$events, ...$this->whatIfEvents($id, $spec)];
                } catch (RuntimeException $e) {
                    // Name the offending row: with a list, "enter an amount" is useless.
                    throw new RuntimeException(count($plan) > 1
                        ? 'Prepayment ' . ($i + 1) . ': ' . lcfirst($e->getMessage())
                        : $e->getMessage());
                }
            }

            return $this->json($response, $this->loans->simulate($id, $events));
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * One entry of a what-if plan, expanded into the prepayment events the
     * engine understands. A "monthly" entry becomes one event per month.
     *
     * @param  array<string,mixed> $spec
     * @return list<array<string,mixed>>
     */
    private function whatIfEvents(int $id, array $spec): array
    {
        $amount = $this->paise($spec['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            throw new RuntimeException('Enter an amount above zero.');
        }

        $mode = in_array($spec['prepay_mode'] ?? '', ['reduce_tenure', 'reduce_emi'], true)
            ? (string) $spec['prepay_mode']
            : 'reduce_tenure';

        if (($spec['mode'] ?? 'lumpsum') === 'monthly') {
            $months = max(1, min(600, (int) ($spec['months'] ?? 12)));
            $from   = $this->date($spec['from'] ?? null) ?? date('Y-m-d');

            return $this->loans->monthlyExtraEvents($id, $amount, $from, $months, $mode);
        }

        return [[
            'event_type'     => 'prepayment',
            'effective_date' => $this->date($spec['on'] ?? null) ?? date('Y-m-d'),
            'amount'         => $amount,
            'mode'           => $mode,
        ]];
    }

    // -- payments (the one seam with the ledger) -----------------------------

    public function linkPayment(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();

        $periodNo = (int) ($b['period_no'] ?? 0);
        $txnId    = (int) ($b['txn_id'] ?? 0);
        if ($periodNo < 1 || $txnId < 1) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Send both period_no and txn_id.'], 400);
        }

        try {
            $this->loans->linkPayment($id, $periodNo, $txnId);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function unlinkPayment(Request $request, Response $response, array $args): Response
    {
        $this->loans->unlinkPayment((int) $args['id'], (int) $args['periodNo']);

        return $this->reported($response, (int) $args['id']);
    }

    /**
     * Debits tagged `emi` that no loan has claimed yet, nearest the instalment's
     * due date first. Feeds the picker behind the Link button in the ledger.
     */
    public function candidates(Request $request, Response $response, array $args): Response
    {
        $q    = $request->getQueryParams();
        $near = $this->date($q['near'] ?? null);
        $days = max(1, min(120, (int) ($q['days'] ?? 45)));

        $sql = "SELECT t.id, t.txn_date, t.description, t.amount, a.name AS account_name
                FROM transactions t
                JOIN accounts a ON a.id = t.account_id
                WHERE t.cashflow = 'debit'
                  AND t.id NOT IN (SELECT txn_id FROM loan_payments WHERE txn_id IS NOT NULL)";
        $params = [];

        // Default to emi-tagged rows, but let the picker widen when a payment was
        // filed under some other tag.
        if (($q['any_tag'] ?? '') !== '1') {
            $sql .= " AND t.category = 'emi'";
        }
        if ($near !== null) {
            $sql .= ' AND t.txn_date BETWEEN date(?, ?) AND date(?, ?)';
            array_push($params, $near, "-{$days} days", $near, "+{$days} days");
        }
        $sql .= $near !== null
            ? ' ORDER BY abs(julianday(t.txn_date) - julianday(?)) LIMIT 50'
            : ' ORDER BY t.txn_date DESC LIMIT 50';
        if ($near !== null) {
            $params[] = $near;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->json($response, ['candidates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // -- helpers -------------------------------------------------------------

    /** Rupees in, paise out. Money::parse avoids the 84481.57*100 float trap. */
    private function paise(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return Money::parse((string) $v);
    }

    private function date(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));

        return checkdate($m, $d, $y) ? $s : null;
    }

    private function safeLoan(int $id): ?array
    {
        try {
            return $this->loans->find($id);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function safeEvents(int $id): array
    {
        try {
            return $this->loans->events($id);
        } catch (RuntimeException) {
            return [];
        }
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
