<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Exclusions;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET  /api/settings/excluded-categories   every tag, its ledger volume, and
 *                                            whether it currently counts
 *   POST /api/settings/excluded-categories   {categories: [...]} replaces the list
 *
 * Excluding a tag removes it from every income/expense figure in the app —
 * analytics, budgets, dashboard averages, MoM. It does NOT touch account
 * balances: those are recomputed from every committed row regardless of
 * category, so an account always reconciles to its statement's closing balance.
 */
final class ExclusionController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $excluded = Exclusions::all($this->pdo);

        // Only offer tags that actually occur, plus whatever is already
        // excluded — a checkbox for a tag you have never used is noise.
        $rows = $this->pdo->query(
            "SELECT t.category,
                    COUNT(*) AS txns,
                    COALESCE(SUM(CASE WHEN t.cashflow = 'debit'  THEN t.amount END), 0) AS out_amount,
                    COALESCE(SUM(CASE WHEN t.cashflow = 'credit' THEN t.amount END), 0) AS in_amount
             FROM transactions t
             GROUP BY t.category
             ORDER BY (out_amount + in_amount) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $notes = $this->pdo->query('SELECT category, note FROM excluded_categories')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $categories = array_map(static fn ($r) => [
            'category'   => $r['category'],
            'txns'       => (int) $r['txns'],
            'out_amount' => (int) $r['out_amount'],
            'in_amount'  => (int) $r['in_amount'],
            'excluded'   => in_array($r['category'], $excluded, true),
            'note'       => $notes[$r['category']] ?? null,
        ], $rows);

        // An excluded tag with no transactions yet still belongs in the list.
        $seen = array_column($categories, 'category');
        foreach ($excluded as $c) {
            if (!in_array($c, $seen, true)) {
                $categories[] = ['category' => $c, 'txns' => 0, 'out_amount' => 0, 'in_amount' => 0,
                                 'excluded' => true, 'note' => $notes[$c] ?? null];
            }
        }

        return $this->json($response, ['excluded' => $excluded, 'categories' => $categories]);
    }

    public function save(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!is_array($body['categories'] ?? null)) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Send {"categories": [...]}'], 400);
        }

        $wanted = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $body['categories']
        ))));

        if (count($wanted) >= 25) {
            return $this->json($response, [
                'error'   => 'validation',
                'message' => 'That would exclude nearly every tag, leaving no income or expense to analyse.',
            ], 400);
        }

        $existingNotes = $this->pdo->query('SELECT category, note FROM excluded_categories')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM excluded_categories');
            $insert = $this->pdo->prepare('INSERT INTO excluded_categories (category, note) VALUES (?, ?)');
            foreach ($wanted as $category) {
                // Keep the shipped explanation when a default is re-selected.
                $insert->execute([$category, $existingNotes[$category] ?? null]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            return $this->json($response, ['error' => 'save_failed', 'message' => $e->getMessage()], 500);
        }

        return $this->list($request, $response);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
