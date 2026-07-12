<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET    /api/logs?category=&level=&upload_id=&limit=   recent event-log rows
 *   DELETE /api/logs                                      clear the log
 */
final class LogController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $q     = $request->getQueryParams();
        $limit = min(1000, max(1, (int) ($q['limit'] ?? 300)));

        $where  = [];
        $params = [];
        foreach (['category', 'level'] as $f) {
            if (!empty($q[$f])) {
                $where[]  = "$f = ?";
                $params[] = $q[$f];
            }
        }
        if (!empty($q['upload_id'])) {
            $where[]  = 'upload_id = ?';
            $params[] = (int) $q['upload_id'];
        }

        $sql = 'SELECT id, ts, level, category, event, upload_id, message, context FROM event_log';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->json($response, ['logs' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function clear(Request $request, Response $response): Response
    {
        $this->pdo->exec('DELETE FROM event_log');

        return $this->json($response, ['cleared' => true]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
