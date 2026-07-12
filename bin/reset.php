<?php

declare(strict_types=1);

/**
 * Wipe the ledger and every artifact of the old AI/PDF pipeline, then rebuild
 * the schema from database/schema.sql.
 *
 * KEPT:    accounts (balances reset to opening_balance), debt_details, budgets,
 *          settings, reminders.
 * DROPPED: transactions, staged_transactions, uploads, balance_snapshots,
 *          milestones, event_log, notification_log, bank_profiles,
 *          encryption_keys, and every stored upload file.
 *
 * Usage: php bin/reset.php --yes
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (!in_array('--yes', $_SERVER['argv'] ?? [], true)) {
    fwrite(STDERR, "This destroys the ledger. Re-run with --yes to confirm.\n");
    exit(1);
}

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}

$dbPath = dirname(__DIR__) . '/data/finance.sqlite';
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA foreign_keys = OFF');

// Drop in dependency order. bank_profiles/encryption_keys belonged to the PDF
// password vault, which no longer exists. The rest are rebuilt by schema.sql
// with their new column definitions (transactions.source lost its 'ai' value,
// uploads.status lost 'pending'/'parsing'/'needs_password').
$drop = [
    'staged_transactions', 'transactions', 'uploads',
    'balance_snapshots', 'milestones', 'event_log', 'notification_log',
    'bank_profiles', 'encryption_keys',
];
foreach ($drop as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
    fwrite(STDOUT, "dropped {$table}\n");
}

// Every account's balance is derived from opening_balance + ledger; with an
// empty ledger that is just the opening balance.
$pdo->exec("UPDATE accounts SET current_balance = opening_balance, updated_at = datetime('now')");
fwrite(STDOUT, "reset current_balance on " . $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() . " account(s)\n");

$pdo->exec('PRAGMA foreign_keys = ON');
unset($pdo);

// Rebuild everything (migrate.php is idempotent and re-seeds tagging rules).
/** @var PDO $pdo */
$pdo = require __DIR__ . '/migrate.php';

$rules = $pdo->query('SELECT COUNT(*) FROM tagging_rules')->fetchColumn();
fwrite(STDOUT, "schema rebuilt; {$rules} tagging rules seeded\n");

// Stored statement files are orphaned now that `uploads` is empty.
$uploadDir = dirname(__DIR__) . '/storage/uploads';
$removed = 0;
foreach (glob($uploadDir . '/*') ?: [] as $file) {
    if (is_file($file) && unlink($file)) {
        $removed++;
    }
}
fwrite(STDOUT, "removed {$removed} orphaned upload file(s)\n");
fwrite(STDOUT, "\nDone. Import your statements from the Upload page.\n");
