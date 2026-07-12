<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DailySummaryService;
use App\Services\ReminderService;
use App\Services\SnapshotService;
use App\Services\TelegramNotifier;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Settings → Notifications: Telegram config, reminders CRUD, and manual test
 * triggers (send a summary / fire a test message now, without waiting for cron).
 *
 *   GET    /api/settings/notifications          telegram config + status
 *   POST   /api/settings/notifications          save chat id + summary time
 *   POST   /api/settings/notifications/test     send a test Telegram message now
 *   POST   /api/settings/notifications/summary  run the daily summary pipeline now
 *   GET    /api/settings/reminders              list reminders
 *   POST   /api/settings/reminders              create a reminder
 *   DELETE /api/settings/reminders/{id}         delete a reminder
 */
final class NotificationController
{
    public function __construct(private PDO $pdo)
    {
    }

    // ---- Telegram config -------------------------------------------------

    public function getConfig(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'chat_id'            => $this->setting('telegram_chat_id'),
            'daily_summary_time' => $this->setting('daily_summary_time') ?: '21:00',
            'bot_token_present'  => ($_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '') !== '',
            'configured'         => (new TelegramNotifier($this->pdo))->isConfigured(),
        ]);
    }

    public function saveConfig(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (array_key_exists('chat_id', $body)) {
            $this->putSetting('telegram_chat_id', trim((string) $body['chat_id']));
        }
        if (array_key_exists('daily_summary_time', $body)) {
            $t = (string) $body['daily_summary_time'];
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'time must be HH:MM'], 400);
            }
            $this->putSetting('daily_summary_time', $t);
        }

        return $this->getConfig($request, $response);
    }

    public function test(Request $request, Response $response): Response
    {
        $tg = new TelegramNotifier($this->pdo);
        if (!$tg->isConfigured()) {
            return $this->json($response, [
                'sent' => false,
                'message' => 'Set TELEGRAM_BOT_TOKEN in .env and a chat id here first.',
            ], 400);
        }
        $sent = $tg->send('*✅ Test message* from your Finance Analyzer.', 'alert');

        return $this->json($response, ['sent' => $sent]);
    }

    public function runSummary(Request $request, Response $response): Response
    {
        $tg    = new TelegramNotifier($this->pdo);
        $daily = new DailySummaryService($this->pdo, new SnapshotService($this->pdo), $tg);
        $r     = $daily->sendDaily();

        return $this->json($response, [
            'sent'           => $r['sent'],
            'accounts'       => $r['accounts'],
            'new_milestones' => $r['new_milestones'],
            'preview'        => $r['message'],   // shown in the UI even if unsent
            'configured'     => $tg->isConfigured(),
        ]);
    }

    // ---- Reminders -------------------------------------------------------

    public function listReminders(Request $request, Response $response): Response
    {
        $rows = $this->pdo->query(
            'SELECT id, title, message, schedule_type, day_of_month, day_of_week,
                    time_of_day, next_run_at, last_run_at, is_active
             FROM reminders ORDER BY is_active DESC, next_run_at'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['reminders' => $rows]);
    }

    public function createReminder(Request $request, Response $response): Response
    {
        $b     = (array) $request->getParsedBody();
        $title = trim((string) ($b['title'] ?? ''));
        $type  = (string) ($b['schedule_type'] ?? '');

        if ($title === '' || !in_array($type, ['monthly', 'weekly', 'daily', 'once'], true)) {
            return $this->json($response, [
                'error'   => 'validation',
                'message' => 'title and schedule_type (monthly|weekly|daily|once) required',
            ], 400);
        }

        $time = (string) ($b['time_of_day'] ?? '09:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return $this->json($response, ['error' => 'validation', 'message' => 'time_of_day must be HH:MM'], 400);
        }

        $row = [
            'title'         => $title,
            'message'       => trim((string) ($b['message'] ?? '')) ?: null,
            'schedule_type' => $type,
            'day_of_month'  => $type === 'monthly' ? max(1, min(31, (int) ($b['day_of_month'] ?? 1))) : null,
            'day_of_week'   => $type === 'weekly' ? max(0, min(6, (int) ($b['day_of_week'] ?? 1))) : null,
            'time_of_day'   => $time,
            // 'once' takes an explicit datetime; recurring types are computed.
            'next_run_at'   => $type === 'once' ? (string) ($b['next_run_at'] ?? '') : null,
        ];

        $svc = new ReminderService($this->pdo, new TelegramNotifier($this->pdo));
        if ($type !== 'once') {
            $row['next_run_at'] = $svc->computeNextRun($row);
        } elseif ($row['next_run_at'] === '') {
            return $this->json($response, ['error' => 'validation', 'message' => 'once reminders need next_run_at (YYYY-MM-DD HH:MM)'], 400);
        }

        $this->pdo->prepare(
            'INSERT INTO reminders (title, message, schedule_type, day_of_month, day_of_week, time_of_day, next_run_at)
             VALUES (:title,:message,:schedule_type,:day_of_month,:day_of_week,:time_of_day,:next_run_at)'
        )->execute($row);

        return $this->json($response, ['created' => true, 'id' => (int) $this->pdo->lastInsertId(),
            'next_run_at' => $row['next_run_at']], 201);
    }

    public function updateReminder(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $b  = (array) $request->getParsedBody();

        $cur = $this->pdo->prepare('SELECT * FROM reminders WHERE id = ?');
        $cur->execute([$id]);
        $reminder = $cur->fetch(PDO::FETCH_ASSOC);
        if ($reminder === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        // Merge incoming edits over the current row, then recompute next_run_at.
        $type = (string) ($b['schedule_type'] ?? $reminder['schedule_type']);
        if (!in_array($type, ['monthly', 'weekly', 'daily', 'once'], true)) {
            return $this->json($response, ['error' => 'validation', 'message' => 'bad schedule_type'], 400);
        }
        $time = (string) ($b['time_of_day'] ?? $reminder['time_of_day']);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return $this->json($response, ['error' => 'validation', 'message' => 'time_of_day must be HH:MM'], 400);
        }

        $merged = [
            'title'         => array_key_exists('title', $b) ? trim((string) $b['title']) : $reminder['title'],
            'message'       => array_key_exists('message', $b) ? (trim((string) $b['message']) ?: null) : $reminder['message'],
            'schedule_type' => $type,
            'day_of_month'  => $type === 'monthly' ? max(1, min(31, (int) ($b['day_of_month'] ?? $reminder['day_of_month'] ?? 1))) : null,
            'day_of_week'   => $type === 'weekly' ? max(0, min(6, (int) ($b['day_of_week'] ?? $reminder['day_of_week'] ?? 1))) : null,
            'time_of_day'   => $time,
            'is_active'     => array_key_exists('is_active', $b) ? (!empty($b['is_active']) ? 1 : 0) : (int) $reminder['is_active'],
            'next_run_at'   => $type === 'once' ? (string) ($b['next_run_at'] ?? $reminder['next_run_at']) : null,
        ];

        $svc = new ReminderService($this->pdo, new TelegramNotifier($this->pdo));
        if ($type !== 'once') {
            $merged['next_run_at'] = $svc->computeNextRun($merged);
        }

        $merged['id'] = $id;
        $this->pdo->prepare(
            'UPDATE reminders SET title=:title, message=:message, schedule_type=:schedule_type,
                    day_of_month=:day_of_month, day_of_week=:day_of_week, time_of_day=:time_of_day,
                    is_active=:is_active, next_run_at=:next_run_at WHERE id=:id'
        )->execute($merged);

        return $this->json($response, ['updated' => true, 'next_run_at' => $merged['next_run_at']]);
    }

    public function deleteReminder(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM reminders WHERE id = ?');
        $stmt->execute([(int) $args['id']]);

        return $this->json($response, ['deleted' => $stmt->rowCount() > 0]);
    }

    // ---- helpers ---------------------------------------------------------

    private function setting(string $key): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function putSetting(string $key, string $value): void
    {
        $this->pdo->prepare(
            "INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')"
        )->execute([$key, $value]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
