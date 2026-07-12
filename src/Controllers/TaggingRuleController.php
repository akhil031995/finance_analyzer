<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Tagging\TaggingEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET    /api/tagging-rules            list, most-used first
 *   POST   /api/tagging-rules            create (and optionally retag an upload)
 *   PATCH  /api/tagging-rules/{id}       edit / enable / disable
 *   DELETE /api/tagging-rules/{id}       remove
 *   POST   /api/uploads/{id}/retag       re-run tagging over a staged upload
 *   GET    /api/settings/identity        self-transfer names + VPAs
 *   POST   /api/settings/identity        save them
 *
 * The loop this exists for: in Review you correct one row's category, tick
 * "apply to similar", and every future statement tags that merchant for you.
 */
final class TaggingRuleController
{
    /** Mirrors the category enum in staged_transactions/transactions. */
    private const CATEGORIES = [
        'salary', 'business_income', 'interest_income', 'dividend', 'refund_cashback', 'other_income',
        'investment', 'epf_employee', 'epf_employer', 'eps_pension', 'epf_interest',
        'emi', 'loan_disbursement', 'credit_card_payment',
        'rent', 'grocery', 'food_dining', 'utility', 'telecom_internet', 'transport_fuel',
        'shopping', 'healthcare', 'insurance', 'education', 'entertainment', 'travel',
        'subscription', 'personal_care', 'charity_gift', 'tax', 'fees_charges',
        'cash_withdrawal', 'self_transfer', 'other_expense',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $rows = $this->pdo->query(
            'SELECT id, pattern, field, match_type, cashflow, category, set_mode,
                    is_self_transfer, priority, enabled, hits, source
             FROM tagging_rules
             ORDER BY enabled DESC, hits DESC, priority DESC, pattern'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['rules' => $rows, 'categories' => self::CATEGORIES]);
    }

    public function create(Request $request, Response $response): Response
    {
        $b       = (array) $request->getParsedBody();
        $pattern = trim((string) ($b['pattern'] ?? ''));

        if ($pattern === '') {
            return $this->json($response, ['error' => 'validation', 'message' => 'Pattern is required'], 400);
        }
        if (!in_array($b['category'] ?? '', self::CATEGORIES, true)) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Unknown category'], 400);
        }

        $field     = in_array($b['field'] ?? '', ['description', 'counterparty'], true) ? $b['field'] : 'description';
        $matchType = in_array($b['match_type'] ?? '', ['contains', 'prefix', 'equals', 'regex'], true) ? $b['match_type'] : 'contains';
        $cashflow  = in_array($b['cashflow'] ?? '', ['credit', 'debit'], true) ? $b['cashflow'] : '';

        if ($matchType === 'regex' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/i', '') === false) {
            return $this->json($response, ['error' => 'validation', 'message' => 'That is not a valid regular expression'], 400);
        }

        // User rules outrank every shipped seed (max seed priority is 90).
        $stmt = $this->pdo->prepare(
            'INSERT INTO tagging_rules (pattern, field, match_type, cashflow, category, is_self_transfer, priority, source)
             VALUES (?,?,?,?,?,?,?, \'user\')
             ON CONFLICT(field, match_type, pattern, cashflow) DO UPDATE SET
                category = excluded.category,
                is_self_transfer = excluded.is_self_transfer,
                enabled = 1'
        );
        $stmt->execute([
            $pattern, $field, $matchType, $cashflow, $b['category'],
            !empty($b['is_self_transfer']) ? 1 : 0,
            (int) ($b['priority'] ?? 100),
        ]);

        $find = $this->pdo->prepare(
            'SELECT id FROM tagging_rules WHERE field = ? AND match_type = ? AND pattern = ? AND cashflow = ?'
        );
        $find->execute([$field, $matchType, $pattern, $cashflow]);
        $ruleId = (int) $find->fetchColumn();

        // "Apply to similar": immediately retag the upload under review, leaving
        // rows the user has already touched by hand alone.
        $retagged = null;
        if (!empty($b['retag_upload_id'])) {
            $retagged = (new TaggingEngine($this->pdo))->retagStaged((int) $b['retag_upload_id']);
        }

        return $this->json($response, ['id' => $ruleId, 'retagged' => $retagged], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $b    = (array) $request->getParsedBody();
        $sets = [];
        $vals = [];

        if (isset($b['category'])) {
            if (!in_array($b['category'], self::CATEGORIES, true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'Unknown category'], 400);
            }
            $sets[] = 'category = ?';
            $vals[] = $b['category'];
        }
        if (array_key_exists('enabled', $b)) {
            $sets[] = 'enabled = ?';
            $vals[] = !empty($b['enabled']) ? 1 : 0;
        }
        if (array_key_exists('priority', $b)) {
            $sets[] = 'priority = ?';
            $vals[] = (int) $b['priority'];
        }
        if (array_key_exists('is_self_transfer', $b)) {
            $sets[] = 'is_self_transfer = ?';
            $vals[] = !empty($b['is_self_transfer']) ? 1 : 0;
        }
        if ($sets === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Nothing to update'], 400);
        }

        $vals[] = (int) $args['id'];
        $this->pdo->prepare('UPDATE tagging_rules SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        return $this->json($response, ['updated' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->pdo->prepare('DELETE FROM tagging_rules WHERE id = ?')->execute([(int) $args['id']]);

        return $this->json($response, ['deleted' => true]);
    }

    /** POST /api/uploads/{id}/retag */
    public function retag(Request $request, Response $response, array $args): Response
    {
        $changed = (new TaggingEngine($this->pdo))->retagStaged((int) $args['id']);

        return $this->json($response, ['retagged' => $changed]);
    }

    public function getIdentity(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'names' => $this->splitSetting('self_identity_names'),
            'vpas'  => $this->splitSetting('self_identity_vpas'),
        ]);
    }

    public function saveIdentity(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();

        foreach (['names' => 'self_identity_names', 'vpas' => 'self_identity_vpas'] as $key => $setting) {
            $values = array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                (array) ($b[$key] ?? [])
            )));
            $this->pdo->prepare(
                "INSERT INTO settings (key, value) VALUES (?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')"
            )->execute([$setting, implode('|', $values)]);
        }

        return $this->getIdentity($request, $response);
    }

    /** @return list<string> */
    private function splitSetting(string $key): array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = (string) ($stmt->fetchColumn() ?: '');

        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
