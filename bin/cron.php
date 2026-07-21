<?php

declare(strict_types=1);

/**
 * Scheduler entry point. Run once (`php bin/cron.php`) or as a daemon
 * (`php bin/cron.php --loop`, used by the `cron` service in docker-compose).
 *
 * Responsibilities:
 *   1. At DAILY_SUMMARY_TIME: write per-account balance_snapshots for today,
 *      record any newly crossed ₹10,000 net-worth milestones, and send the
 *      markdown daily summary to Telegram.
 *   2. Every minute: fire due rows from `reminders` to Telegram and advance
 *      their next_run_at.
 *
 * There is no upload worker any more. It existed only because an AI parse took
 * tens of seconds and could not survive the HTTP request; CSV parsing is fast
 * and synchronous, so ImportService runs inline and uploads can never be
 * orphaned in a 'parsing' state.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

/** @var PDO $pdo */
$pdo = require __DIR__ . '/migrate.php';

$telegram = new App\Services\TelegramNotifier($pdo);
$reminders = new App\Services\ReminderService($pdo, $telegram);
$daily = new App\Services\DailySummaryService($pdo, new App\Services\SnapshotService($pdo), $telegram);
$loans = new App\Services\Loan\LoanService($pdo);

/**
 * Outstanding loan principal moves on its own as each month's instalment falls
 * due, even when nothing is edited — so it has to be re-derived daily, not only
 * on write. Guarded by a settings row so a container restart does not re-run it,
 * and so the once-a-minute tick does not amortise every loan sixty times an hour.
 */
$syncLoans = function (bool $force = false) use ($pdo, $loans): void {
    $today = date('Y-m-d');
    $last  = $pdo->query("SELECT value FROM settings WHERE key = 'loans_synced_on'")->fetchColumn();
    if (!$force && $last === $today) {
        return;
    }

    $r = $loans->syncAll();
    $pdo->prepare("INSERT INTO settings (key, value) VALUES ('loans_synced_on', ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$today]);

    if ($r['synced'] > 0 || $r['failed'] !== []) {
        fwrite(STDOUT, '[' . date('c') . "] loans synced={$r['synced']}"
            . ($r['failed'] !== [] ? ' failed=' . implode('; ', $r['failed']) : '') . "\n");
    }
};

/**
 * Notification tick (throttled to once per minute by the loop below):
 *   1. Re-derive loan balances if the calendar day has turned.
 *   2. Fire any due reminders.
 *   3. Once per day at/after DAILY_SUMMARY_TIME, run the snapshot + summary —
 *      guarded by notification_log so it sends exactly once per calendar day.
 *
 * Loans sync BEFORE the snapshot, so the day's net-worth snapshot records the
 * post-instalment debt rather than yesterday's.
 */
$tick = function () use ($pdo, $reminders, $daily, $syncLoans): void {
    try {
        $syncLoans();

        $fired = $reminders->dispatchDue();
        if ($fired > 0) {
            fwrite(STDOUT, '[' . date('c') . "] dispatched {$fired} reminder(s)\n");
        }

        $summaryTime = (string) ($pdo->query("SELECT value FROM settings WHERE key = 'daily_summary_time'")
            ->fetchColumn() ?: ($_ENV['DAILY_SUMMARY_TIME'] ?? '21:00'));

        if (date('H:i') >= $summaryTime && !summarySentToday($pdo)) {
            $r = $daily->sendDaily();
            fwrite(STDOUT, '[' . date('c') . "] daily summary sent=" . ($r['sent'] ? 'yes' : 'no(unconfigured)')
                . ', milestones=' . count($r['new_milestones']) . "\n");
        }

        // Fold the write-ahead log back into the database. WAL only checkpoints
        // automatically when a writer happens to cross the threshold, and this
        // app's writes are bursty (an import, then hours of reads), so it had
        // grown to 4 MB. Doing it here keeps it off the request path.
        $pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] cron error: ' . $e->getMessage() . "\n");
    }
};

/**
 * True if a daily_summary was already logged today. notification_log.sent_at is
 * UTC (SQLite datetime('now')), so compare against app-local midnight expressed
 * in UTC — correct regardless of the container's system timezone.
 */
function summarySentToday(PDO $pdo): bool
{
    $tz = new DateTimeZone($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
    $midnightUtc = (new DateTimeImmutable('today', $tz))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        "SELECT 1 FROM notification_log WHERE kind = 'daily_summary' AND sent_at >= ? LIMIT 1"
    );
    $stmt->execute([$midnightUtc]);

    return $stmt->fetchColumn() !== false;
}

$loop = in_array('--loop', $_SERVER['argv'] ?? [], true);

// The daily guard assumes the only thing that moves a balance is the calendar.
// It isn't: changing how the balance is DERIVED moves every loan at once, and
// the guard would then sit on the stale figures until tomorrow. This re-derives
// on demand — run it once after deploying a change to the amortisation rules.
if (in_array('--sync-loans', $_SERVER['argv'] ?? [], true)) {
    $syncLoans(true);
    fwrite(STDOUT, "loan balances re-derived\n");
    exit(0);
}

// Reminders and the daily summary run at most once per wall-clock minute.
$lastMinute = '';
do {
    $minute = date('Y-m-d H:i');
    if ($minute !== $lastMinute) {
        $lastMinute = $minute;
        $tick();
    }

    if ($loop) {
        sleep(20);
    }
} while ($loop);

// One-shot (no --loop): also run a notification tick so cron-style invocations work.
if (!$loop) {
    $tick();
}
