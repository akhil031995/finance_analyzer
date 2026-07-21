<?php

declare(strict_types=1);

/**
 * Schema migration: applies database/schema.sql to the SQLite DB.
 * Usable standalone (`php bin/migrate.php`) or via require from the app.
 *
 * This file is `require`d on EVERY HTTP request (public/index.php), so it is
 * split into two halves:
 *
 *   1. Connection setup — always runs. SQLite PRAGMAs like foreign_keys and
 *      synchronous are per-CONNECTION, not stored in the file, so they have to
 *      be re-applied every time or cascades silently stop working.
 *
 *   2. DDL — gated behind a fingerprint of schema.sql. Re-running the DDL was
 *      costing ~3.2 s per request: `CREATE TABLE IF NOT EXISTS` still parses,
 *      the seed `INSERT OR IGNORE`s still open write transactions, and the two
 *      analytics views were DROPped and recreated unconditionally. Every one of
 *      those writes fsyncs, and an fsync on this box's 5400rpm disk costs
 *      ~680 ms. Now the DDL runs only when schema.sql (or MIGRATION_REVISION)
 *      actually changes, which is what "idempotent" was always meant to mean.
 *
 * Pass --force to re-apply the DDL regardless.
 */

/** Bump when the PHP-side migration steps below change without schema.sql changing. */
const MIGRATION_REVISION = 1;

$root   = dirname(__DIR__);
$dbPath = getenv('DB_PATH') ?: ($_ENV['DB_PATH'] ?? $root . '/data/finance.sqlite');

// Run standalone as well as via require from index.php, which has already
// autoloaded. require_once is a no-op the second time.
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

$dir = dirname($dbPath);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$pdo = new PDO('sqlite:' . $dbPath, options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// ---------------------------------------------------------------- connection
// Always applied. These are per-connection and cost nothing (no disk I/O).
//
// foreign_keys: OFF is SQLite's default. Every ON DELETE CASCADE in this app
//   (loan events, loan_payments, investment_*) depends on this being ON.
// synchronous NORMAL: safe under WAL — a crashed process cannot corrupt the
//   database, only a power loss / OS crash can cost the last commit(s). FULL
//   fsyncs on every single commit, which is ruinous on a spinning disk.
// busy_timeout: wait rather than throw when the cron container holds the lock.
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA synchronous = NORMAL');
$pdo->exec('PRAGMA busy_timeout = 60000');

// ----------------------------------------------------------------- DDL gate
$schemaSql = (string) file_get_contents($root . '/database/schema.sql');
$stamp     = sha1($schemaSql) . '.' . MIGRATION_REVISION;
$force     = in_array('--force', $_SERVER['argv'] ?? [], true);

$applied = null;
try {
    $applied = $pdo->query("SELECT value FROM settings WHERE key = 'schema_stamp'")->fetchColumn();
} catch (PDOException) {
    // Fresh database: no settings table yet. Fall through and build everything.
}

if (!$force && $applied === $stamp) {
    return $pdo;   // schema already current — the hot path, zero writes
}

/**
 * Additive column migrations. These run BEFORE schema.sql, because the analytics
 * views it (re)creates read the new columns — CREATE VIEW would succeed anyway
 * (SQLite resolves view bodies lazily) but the first query against them would not.
 * A fresh database has no tables yet, so each guard simply skips.
 */
$addColumn = static function (PDO $pdo, string $table, string $column, string $ddl): void {
    $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $exists->execute([$table]);
    if ($exists->fetchColumn() === false) {
        return;
    }
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $cols, true)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    }
};
// Per-row blacklist: keep the row in the ledger and in the balance, drop it
// from every income/expense figure.
$addColumn($pdo, 'transactions', 'is_excluded', 'is_excluded INTEGER NOT NULL DEFAULT 0');
// Loan accounts: balance owned by the amortisation engine, not by the ledger.
$addColumn($pdo, 'accounts', 'is_derived', 'is_derived INTEGER NOT NULL DEFAULT 0');
// Per-account identity colour, shown wherever the account is named.
$addColumn($pdo, 'accounts', 'color', 'color TEXT');
// Tranched (under-construction) loans. No CHECK on the added column: SQLite's
// ALTER TABLE ADD COLUMN is fussy, and LoanController validates the enum anyway.
$addColumn($pdo, 'loans', 'pre_emi_mode', "pre_emi_mode TEXT NOT NULL DEFAULT 'pay'");
$addColumn($pdo, 'loans', 'possession_date', 'possession_date TEXT');

/**
 * `CREATE TABLE IF NOT EXISTS` cannot widen a CHECK constraint, so an existing
 * loan_events still rejects 'disbursement'. Rebuild it when the old constraint
 * is detected. Rows are copied across; the table is tiny and rarely populated.
 */
$sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'loan_events'")->fetchColumn();
if (is_string($sql) && !str_contains($sql, 'disbursement')) {
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    $pdo->exec('ALTER TABLE loan_events RENAME TO loan_events_old');
    $pdo->exec("CREATE TABLE loan_events (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        loan_id         INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
        event_type      TEXT    NOT NULL CHECK (event_type IN
                          ('disbursement','rate_change','emi_change','prepayment')),
        effective_date  TEXT    NOT NULL,
        rate_apr        REAL,
        emi_amount      INTEGER,
        amount          INTEGER,
        mode            TEXT,
        note            TEXT,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec('INSERT INTO loan_events (id, loan_id, event_type, effective_date, rate_apr,
                                         emi_amount, amount, mode, note, created_at)
                SELECT id, loan_id, event_type, effective_date, rate_apr,
                       emi_amount, amount, mode, note, created_at FROM loan_events_old');
    $pdo->exec('DROP TABLE loan_events_old');
    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
}

// The views are still DROPped and recreated here rather than made
// CREATE VIEW IF NOT EXISTS: a changed view body MUST replace the stored one,
// and IF NOT EXISTS would silently keep the stale definition. The gate above is
// what makes this cheap — it now happens only when schema.sql actually changes.
$pdo->exec($schemaSql);

// Give every uncoloured account a palette colour, in id order, so no two open
// with the same swatch. Runs after schema.sql so a fresh database has the table.
// Idempotent: a NULL colour is the only thing it touches.
if (class_exists(App\Support\Palette::class)) {
    $ids = $pdo->query('SELECT id FROM accounts WHERE color IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $taken = $pdo->query('SELECT color FROM accounts WHERE color IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $set   = $pdo->prepare('UPDATE accounts SET color = ? WHERE id = ?');
        foreach ($ids as $id) {
            $c = App\Support\Palette::next($taken);
            $taken[] = $c;
            $set->execute([$c, (int) $id]);
        }
        if (PHP_SAPI === 'cli') {
            echo '  coloured ' . count($ids) . " account(s)\n";
        }
    }
}

// Record the fingerprint last, so a migration that dies part-way is retried
// rather than being marked done.
$pdo->prepare("INSERT INTO settings (key, value) VALUES ('schema_stamp', ?)
               ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$stamp]);

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    echo "Schema applied to {$dbPath}\n";
}

return $pdo;
