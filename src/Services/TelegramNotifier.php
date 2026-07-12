<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client as HttpClient;
use PDO;
use Throwable;

/**
 * Sends markdown messages to a Telegram chat via the Bot API and records every
 * send in notification_log. Credentials come from the environment
 * (TELEGRAM_BOT_TOKEN) and settings/env (telegram_chat_id) — never hardcoded.
 *
 * When no token/chat is configured it logs a 'failed' row with a clear reason
 * instead of throwing, so the cron loop keeps running on a fresh install.
 */
final class TelegramNotifier
{
    private HttpClient $http;

    public function __construct(private PDO $pdo, ?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient(['timeout' => 15]);
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    /**
     * @param 'daily_summary'|'reminder'|'alert' $kind
     */
    public function send(string $markdown, string $kind = 'alert', ?int $reminderId = null): bool
    {
        $token  = $this->token();
        $chatId = $this->chatId();

        if ($token === '' || $chatId === '') {
            $this->log($kind, $reminderId, $markdown, 'failed',
                'Telegram not configured (set TELEGRAM_BOT_TOKEN and a chat id in Settings)');
            return false;
        }

        try {
            $res = $this->http->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $markdown,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ],
            ]);
            $ok = $res->getStatusCode() === 200;
            $this->log($kind, $reminderId, $markdown, $ok ? 'sent' : 'failed',
                $ok ? null : 'HTTP ' . $res->getStatusCode());
            return $ok;
        } catch (Throwable $e) {
            $this->log($kind, $reminderId, $markdown, 'failed', $e->getMessage());
            return false;
        }
    }

    private function token(): string
    {
        return (string) ($_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '');
    }

    /** Chat id: settings table wins (editable in the UI), env is the fallback. */
    private function chatId(): string
    {
        $fromSettings = (string) ($this->pdo->query("SELECT value FROM settings WHERE key = 'telegram_chat_id'")
            ->fetchColumn() ?: '');
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return (string) ($_ENV['TELEGRAM_CHAT_ID'] ?? getenv('TELEGRAM_CHAT_ID') ?: '');
    }

    private function log(string $kind, ?int $reminderId, string $payload, string $status, ?string $error): void
    {
        $this->pdo->prepare(
            'INSERT INTO notification_log (kind, reminder_id, payload, status, error)
             VALUES (?,?,?,?,?)'
        )->execute([$kind, $reminderId, $payload, $status, $error]);
    }
}
