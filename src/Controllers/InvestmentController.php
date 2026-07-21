<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Investment\InvestmentService;
use App\Support\Money;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 *   GET    /api/investments                        portfolio: every holding + blended stats
 *   POST   /api/investments                        create (also creates its asset account)
 *   GET    /api/investments/{id}                   full report: events, valuations, returns, projection
 *   PATCH  /api/investments/{id}                   edit
 *   DELETE /api/investments/{id}                   delete holding + account + events + valuations
 *   POST   /api/investments/{id}/events            buy | contribution | sell | withdrawal | dividend
 *   PATCH  /api/investments/{id}/events/{eventId}
 *   DELETE /api/investments/{id}/events/{eventId}
 *   POST   /api/investments/{id}/valuations        mark-to-market on a date
 *   DELETE /api/investments/{id}/valuations/{valuationId}
 *   POST   /api/investments/{id}/project           {months, monthly_sip} what-if; writes nothing
 *   GET    /api/investments/{id}/candidates        unlinked investment debits, for the picker
 *   POST   /api/investments/{id}/contributions     link a ledger txn as a contribution
 *   DELETE /api/investments/{id}/contributions/{txnId}
 *
 * Amounts cross in RUPEES, stored in paise. Rates cross as a percentage.
 */
final class InvestmentController
{
    public function __construct(private PDO $pdo, private InvestmentService $inv)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        // Build the list once and hand it to portfolio(), which would otherwise
        // recompute every holding's XIRR and valuations a second time.
        $investments = $this->inv->listInvestments();

        return $this->json($response, [
            'investments' => $investments,
            'portfolio'   => $this->inv->portfolio(null, $investments),
            'types'       => InvestmentService::TYPES,
            'default_rates' => InvestmentService::DEFAULT_RATES,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->reported($response, (int) $args['id']);
    }

    public function create(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'validation', 'message' => 'A holding needs a name.'], 400);
        }

        $id = $this->inv->create([
            'name'                => $name,
            'instrument_type'     => $b['instrument_type'] ?? 'other',
            'platform'            => trim((string) ($b['platform'] ?? '')) ?: null,
            'expected_return_apr' => $this->rate($b['expected_return_apr'] ?? null),
            'notes'               => trim((string) ($b['notes'] ?? '')) ?: null,
        ]);

        return $this->reported($response, $id, [], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();
        $patch = [];
        foreach (['name', 'instrument_type', 'platform', 'notes'] as $k) {
            if (array_key_exists($k, $b)) {
                $patch[$k] = trim((string) $b[$k]) ?: null;
            }
        }
        if (array_key_exists('expected_return_apr', $b)) {
            $patch['expected_return_apr'] = $this->rate($b['expected_return_apr']);
        }

        try {
            $this->inv->update($id, $patch);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->inv->delete((int) $args['id']);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }

        return $this->json($response, ['deleted' => true]);
    }

    // -- events --------------------------------------------------------------

    public function addEvent(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $this->inv->addEvent($id, $this->eventBody((array) $request->getParsedBody()));
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id, [], 201);
    }

    public function updateEvent(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $this->inv->updateEvent($id, (int) $args['eventId'], $this->eventBody((array) $request->getParsedBody()));
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        try {
            $this->inv->deleteEvent((int) $args['id'], (int) $args['eventId']);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, (int) $args['id']);
    }

    // -- valuations ----------------------------------------------------------

    public function addValuation(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();
        try {
            $this->inv->addValuation($id, [
                'value'     => $this->paise($b['value'] ?? null) ?? -1,
                'valued_on' => $b['valued_on'] ?? null,
                'note'      => trim((string) ($b['note'] ?? '')) ?: null,
            ]);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id, [], 201);
    }

    public function deleteValuation(Request $request, Response $response, array $args): Response
    {
        $this->inv->deleteValuation((int) $args['id'], (int) $args['valuationId']);

        return $this->reported($response, (int) $args['id']);
    }

    // -- projection (what-if, writes nothing) -------------------------------

    public function project(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();
        $months = max(1, min(600, (int) ($b['months'] ?? 120)));
        $sip    = max(0, $this->paise($b['monthly_sip'] ?? null) ?? 0);

        try {
            $report = $this->inv->report($id);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }

        return $this->json($response, [
            'projection' => $this->inv->project($report['investment'], $report['returns'], $sip, $months),
        ]);
    }

    // -- ledger seam ---------------------------------------------------------

    /** Debits tagged `investment` that no holding has claimed, newest first. */
    public function candidates(Request $request, Response $response, array $args): Response
    {
        $q = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 40)));

        $sql = "SELECT t.id, t.txn_date, t.description, t.amount, a.name AS account_name, a.color AS account_color
                FROM transactions t
                JOIN accounts a ON a.id = t.account_id
                WHERE t.cashflow = 'debit'
                  AND t.id NOT IN (SELECT txn_id FROM investment_contributions WHERE txn_id IS NOT NULL)";
        // Default to investment-tagged rows; widen on request.
        if (($q['any_tag'] ?? '') !== '1') {
            $sql .= " AND t.category = 'investment'";
        }
        $sql .= ' ORDER BY t.txn_date DESC, t.id DESC LIMIT ' . $limit;

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['amount'] = (int) $r['amount'];
        }

        return $this->json($response, ['candidates' => $rows]);
    }

    public function linkContribution(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $txnId = (int) (((array) $request->getParsedBody())['txn_id'] ?? 0);
        if ($txnId < 1) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Send a txn_id.'], 400);
        }
        try {
            $this->inv->linkContribution($id, $txnId);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'validation', 'message' => $e->getMessage()], 400);
        }

        return $this->reported($response, $id);
    }

    public function unlinkContribution(Request $request, Response $response, array $args): Response
    {
        $this->inv->unlinkContribution((int) $args['id'], (int) $args['txnId']);

        return $this->reported($response, (int) $args['id']);
    }

    // -- helpers -------------------------------------------------------------

    private function reported(Response $response, int $id, array $extra = [], int $status = 200): Response
    {
        try {
            return $this->json($response, $extra + $this->inv->report($id), $status);
        } catch (RuntimeException $e) {
            return $this->json($response, ['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    /** @param array<string,mixed> $b */
    private function eventBody(array $b): array
    {
        return [
            'event_type' => (string) ($b['event_type'] ?? ''),
            'event_date' => $b['event_date'] ?? null,
            'amount'     => $this->paise($b['amount'] ?? null) ?? 0,
            'units'      => $b['units'] ?? null,
            'price'      => $this->paise($b['price'] ?? null),
            'note'       => trim((string) ($b['note'] ?? '')) ?: null,
        ];
    }

    private function paise(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return Money::parse((string) $v);
    }

    private function rate(mixed $v): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
