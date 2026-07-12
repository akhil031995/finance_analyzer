<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Writes application activity to event_log (surfaced on the Log page). Logging
 * must never break the pipeline it observes, so writes are best-effort — any
 * failure is swallowed.
 */
final class LogService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param 'upload'|'decrypt'|'ai_parse'|'ingest'|'commit'|'cron'|'telegram'|string $category
     * @param 'info'|'success'|'warning'|'error' $level
     * @param array<string,mixed>|null $context
     */
    public function log(
        string $category,
        string $event,
        ?string $message = null,
        string $level = 'info',
        ?int $uploadId = null,
        ?array $context = null,
    ): void {
        try {
            $this->pdo->prepare(
                'INSERT INTO event_log (level, category, event, upload_id, message, context)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $level, $category, $event, $uploadId, $message,
                $context !== null ? json_encode($context, JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable) {
            // Never let logging interrupt the work being logged.
        }
    }

    public function info(string $c, string $e, ?string $m = null, ?int $u = null, ?array $ctx = null): void
    {
        $this->log($c, $e, $m, 'info', $u, $ctx);
    }

    public function success(string $c, string $e, ?string $m = null, ?int $u = null, ?array $ctx = null): void
    {
        $this->log($c, $e, $m, 'success', $u, $ctx);
    }

    public function warning(string $c, string $e, ?string $m = null, ?int $u = null, ?array $ctx = null): void
    {
        $this->log($c, $e, $m, 'warning', $u, $ctx);
    }

    public function error(string $c, string $e, ?string $m = null, ?int $u = null, ?array $ctx = null): void
    {
        $this->log($c, $e, $m, 'error', $u, $ctx);
    }
}
