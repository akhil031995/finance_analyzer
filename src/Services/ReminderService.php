<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Recurring reminder scheduling + dispatch. next_run_at is stored as an app-
 * timezone 'Y-m-d H:i:00' string; the cron compares it against "now" each tick
 * and fires anything due, then advances it to the following occurrence.
 */
final class ReminderService
{
    public function __construct(private PDO $pdo, private TelegramNotifier $telegram)
    {
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
    }

    /**
     * Fire all reminders whose next_run_at is due. Returns the count sent.
     */
    public function dispatchDue(?DateTimeImmutable $now = null): int
    {
        $now    = $now ?? new DateTimeImmutable('now', $this->tz());
        $nowStr = $now->format('Y-m-d H:i:s');

        $due = $this->pdo->prepare(
            'SELECT * FROM reminders
             WHERE is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?'
        );
        $due->execute([$nowStr]);

        $sent = 0;
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $text = "*⏰ Reminder:* {$r['title']}";
            if (!empty($r['message'])) {
                $text .= "\n{$r['message']}";
            }
            $this->telegram->send($text, 'reminder', (int) $r['id']);
            $sent++;

            if ($r['schedule_type'] === 'once') {
                $this->pdo->prepare(
                    "UPDATE reminders SET is_active = 0, last_run_at = ?, next_run_at = NULL WHERE id = ?"
                )->execute([$nowStr, $r['id']]);
            } else {
                $next = $this->computeNextRun($r, $now->modify('+1 minute'));
                $this->pdo->prepare('UPDATE reminders SET last_run_at = ?, next_run_at = ? WHERE id = ?')
                    ->execute([$nowStr, $next, $r['id']]);
            }
        }

        return $sent;
    }

    /**
     * Next fire time strictly at/after $from for a recurring reminder.
     * For 'once', next_run_at is set explicitly at create time and not recomputed.
     *
     * @param array<string,mixed> $r reminder row
     */
    public function computeNextRun(array $r, ?DateTimeImmutable $from = null): ?string
    {
        $from = $from ?? new DateTimeImmutable('now', $this->tz());
        [$h, $m] = array_map('intval', explode(':', (string) ($r['time_of_day'] ?? '09:00')) + [1 => 0]);

        switch ($r['schedule_type']) {
            case 'daily':
                $cand = $from->setTime($h, $m);
                if ($cand < $from) {
                    $cand = $cand->modify('+1 day');
                }
                return $cand->format('Y-m-d H:i:00');

            case 'weekly':
                $target = (int) ($r['day_of_week'] ?? 1);           // 0=Sun..6=Sat
                $cand = $from->setTime($h, $m);
                $delta = ($target - (int) $cand->format('w') + 7) % 7;
                $cand = $cand->modify("+{$delta} day");
                if ($cand < $from) {
                    $cand = $cand->modify('+7 day');
                }
                return $cand->format('Y-m-d H:i:00');

            case 'monthly':
                $dom = max(1, min(31, (int) ($r['day_of_month'] ?? 1)));
                return $this->nextMonthly($from, $dom, $h, $m);

            case 'once':
                // Preserve an already-set explicit datetime; otherwise none.
                return $r['next_run_at'] ?? null;
        }

        return null;
    }

    /** Next monthly occurrence, clamping day-of-month to the month's length. */
    private function nextMonthly(DateTimeImmutable $from, int $dom, int $h, int $m): string
    {
        for ($i = 0; $i < 13; $i++) {
            $base = $from->modify("first day of +{$i} month")->setTime($h, $m);
            $days = (int) $base->format('t');
            $cand = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), min($dom, $days));
            if ($cand >= $from) {
                return $cand->format('Y-m-d H:i:00');
            }
        }

        // Unreachable in practice; satisfy the return contract.
        return $from->modify('+1 month')->format('Y-m-d H:i:00');
    }
}
