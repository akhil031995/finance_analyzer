<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CommitService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The review half of the import flow (the mapping half lives in ImportController).
 *
 *   GET   /api/uploads                recent uploads + their staged counts
 *   GET   /api/uploads/{id}/staged    rows awaiting review
 *   PATCH /api/staged/{rowId}         edit one row
 *   POST  /api/uploads/{id}/commit    promote reviewed rows into the ledger
 */
final class UploadController
{
    /** @param callable():CommitService $commitFactory */
    public function __construct(private PDO $pdo, private $commitFactory)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $rows = $this->pdo->query(
            'SELECT u.id, u.original_name, u.status, u.row_count, u.error_message, u.parse_warnings,
                    u.uploaded_at, a.name AS account_name, f.name AS format_name,
                    (SELECT COUNT(*) FROM staged_transactions s WHERE s.upload_id = u.id) AS staged_count,
                    (SELECT COUNT(*) FROM staged_transactions s WHERE s.upload_id = u.id AND s.is_duplicate = 1) AS duplicate_count
             FROM uploads u
             LEFT JOIN accounts a     ON a.id = u.account_id
             LEFT JOIN bank_formats f ON f.id = u.bank_format_id
             ORDER BY u.id DESC LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['uploads' => $rows]);
    }

    public function staged(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, txn_date, description, raw_description, amount, cashflow, mode, category,
                    tag_source, tag_rule_id, is_self_transfer, counterparty, reference_id,
                    balance_after, is_duplicate, review_status
             FROM staged_transactions WHERE upload_id = ? ORDER BY txn_date, id'
        );
        $stmt->execute([(int) $args['id']]);

        return $this->json($response, ['transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * PATCH /api/staged/{rowId} — edit one staged row. `amount` is in PAISE.
     *
     * Setting `category` marks the row tag_source='manual', which pins it: a
     * later retag pass (after the user adds a rule) will not overwrite it.
     */
    public function updateStaged(Request $request, Response $response, array $args): Response
    {
        $rowId = (int) $args['rowId'];
        $body  = (array) $request->getParsedBody();

        $stmt = $this->pdo->prepare(
            'SELECT s.id, u.status AS upload_status FROM staged_transactions s
             JOIN uploads u ON u.id = s.upload_id WHERE s.id = ?'
        );
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        if ($row['upload_status'] !== 'review') {
            return $this->json($response, [
                'error'   => 'invalid_state',
                'message' => 'This upload is no longer editable',
            ], 409);
        }

        $sets = [];
        $vals = [];
        foreach (['txn_date', 'description', 'mode', 'counterparty'] as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $vals[] = $body[$f] === '' ? null : (string) $body[$f];
            }
        }
        if (array_key_exists('category', $body)) {
            $sets[] = 'category = ?';
            $vals[] = (string) $body['category'];
            $sets[] = "tag_source = 'manual'";
        }
        if (array_key_exists('amount', $body)) {
            $amt = (int) $body['amount'];
            if ($amt < 0) {
                return $this->json($response, ['error' => 'validation', 'message' => 'amount (paise) must be >= 0'], 400);
            }
            $sets[] = 'amount = ?';
            $vals[] = $amt;
        }
        if (array_key_exists('cashflow', $body)) {
            if (!in_array($body['cashflow'], ['credit', 'debit'], true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'cashflow must be credit|debit'], 400);
            }
            $sets[] = 'cashflow = ?';
            $vals[] = $body['cashflow'];
        }
        if (array_key_exists('is_self_transfer', $body)) {
            $sets[] = 'is_self_transfer = ?';
            $vals[] = !empty($body['is_self_transfer']) ? 1 : 0;
        }

        // Explicit status (reject/unreject) wins; otherwise a content edit marks 'edited'.
        if (array_key_exists('review_status', $body)
            && in_array($body['review_status'], ['pending', 'edited', 'rejected'], true)) {
            $sets[] = 'review_status = ?';
            $vals[] = $body['review_status'];
        } elseif ($sets !== []) {
            $sets[] = "review_status = 'edited'";
        }

        if ($sets === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'No editable fields provided'], 400);
        }

        $sets[] = "updated_at = datetime('now')";
        $vals[] = $rowId;
        $this->pdo->prepare('UPDATE staged_transactions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        return $this->json($response, ['updated' => true, 'id' => $rowId]);
    }

    /**
     * POST /api/uploads/{id}/commit — promote reviewed rows into the ledger.
     * Optional body { account_id } assigns the account for uploads pushed
     * without one.
     */
    public function commit(Request $request, Response $response, array $args): Response
    {
        $uploadId  = (int) $args['id'];
        $accountId = (int) (((array) $request->getParsedBody())['account_id'] ?? 0) ?: null;

        try {
            $summary = ($this->commitFactory)()->commit($uploadId, $accountId);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'commit_failed', 'message' => $e->getMessage()], 400);
        }

        return $this->json($response, ['status' => 'committed'] + $summary);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
