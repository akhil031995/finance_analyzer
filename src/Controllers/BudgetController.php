<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\BudgetService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET    /api/budgets            overall + per-category budgets with live spend
 *   POST   /api/budgets            upsert { category?, amount }  (amount in rupees; '' / omitted = overall)
 *   DELETE /api/budgets/{category} remove one ('overall' targets the '' row)
 */
final class BudgetController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $svc = new BudgetService($this->pdo);

        return $this->json($response, [
            'overall'    => $svc->overallStatus(),
            'categories' => $svc->categoryStatuses(),
        ]);
    }

    public function analysis(Request $request, Response $response): Response
    {
        // A malformed month used to reach strtotime() and 500. Anything that is
        // not a real YYYY-MM falls back to the current month, the same way the
        // ledger widens an out-of-range month rather than erroring.
        $raw   = (string) ($request->getQueryParams()['month'] ?? '');
        $month = null;
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $month = $raw;
        }

        return $this->json($response, (new BudgetService($this->pdo))->analysis($month));
    }

    public function upsert(Request $request, Response $response): Response
    {
        $b        = (array) $request->getParsedBody();
        $category = trim((string) ($b['category'] ?? ''));   // '' = overall
        $amount   = (float) ($b['amount'] ?? 0);

        if ($amount <= 0) {
            return $this->json($response, ['error' => 'validation', 'message' => 'amount must be a positive number'], 400);
        }

        (new BudgetService($this->pdo))->upsert($category, (int) round($amount * 100));

        return $this->json($response, ['saved' => true, 'category' => $category === '' ? 'overall' : $category]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $category = (string) $args['category'];
        if ($category === 'overall') {
            $category = '';
        }
        $deleted = (new BudgetService($this->pdo))->delete($category);

        return $this->json($response, ['deleted' => $deleted]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
