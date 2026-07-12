<?php

declare(strict_types=1);

/**
 * Idempotent schema migration: applies database/schema.sql to the SQLite DB.
 * Safe to run on every boot — the schema uses IF NOT EXISTS / INSERT OR IGNORE.
 * Usable standalone (`php bin/migrate.php`) or via require from the app.
 */

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

$pdo->exec(file_get_contents($root . '/database/schema.sql'));

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

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    echo "Schema applied to {$dbPath}\n";
}

return $pdo;
