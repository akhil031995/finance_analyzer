<?php

declare(strict_types=1);

/**
 * Housekeeping: drop staging rows that have already been promoted to the ledger,
 * and truncate the write-ahead log.
 *
 *   php bin/prune.php            dry run — reports, changes nothing
 *   php bin/prune.php --apply    actually delete + checkpoint
 *
 * Only `staged_transactions` belonging to an upload whose status is 'committed'
 * are removed: those rows were copied into `transactions` and are dead weight.
 * Anything still in 'review' is a pending queue the user has not finished with,
 * and is never touched — nor is a row whose upload is missing, which would be a
 * symptom worth investigating rather than quietly deleting.
 *
 * The ledger itself is never modified.
 */

$root = dirname(__DIR__);
$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/migrate.php';

$fmt = static fn (int $n): string => number_format($n);

echo $apply ? "Pruning (--apply)\n\n" : "Dry run — nothing will change. Re-run with --apply.\n\n";

// --- what is there -----------------------------------------------------------
$byStatus = $pdo->query(
    "SELECT COALESCE(u.status, '(orphan)') AS status, COUNT(st.id) AS n
     FROM staged_transactions st
     LEFT JOIN uploads u ON u.id = st.upload_id
     GROUP BY status ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "staged_transactions by upload status:\n";
foreach ($byStatus as $r) {
    $keep = $r['status'] === 'committed' ? 'prune' : 'KEEP';
    printf("  %-12s %8s   %s\n", $r['status'], $fmt((int) $r['n']), $keep);
}

$prunable = (int) $pdo->query(
    "SELECT COUNT(st.id) FROM staged_transactions st
     JOIN uploads u ON u.id = st.upload_id
     WHERE u.status = 'committed'"
)->fetchColumn();

$walBytes = @filesize(($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '') . '-wal') ?: 0;

printf("\nprunable rows : %s\n", $fmt($prunable));
printf("WAL size      : %.2f MB\n", $walBytes / 1048576);

if (!$apply) {
    echo "\nNothing changed.\n";

    return;
}

// --- do it -------------------------------------------------------------------
$before = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();

$pdo->beginTransaction();
$deleted = $pdo->exec(
    "DELETE FROM staged_transactions
     WHERE upload_id IN (SELECT id FROM uploads WHERE status = 'committed')"
);
$pdo->commit();

$after = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
if ($before !== $after) {   // paranoia: the ledger must be untouched
    fwrite(STDERR, "ABORT: transactions changed {$before} -> {$after}\n");
    exit(1);
}

// Fold the WAL back into the database file and truncate it.
$pdo->query('PRAGMA wal_checkpoint(TRUNCATE)');

printf("\ndeleted %s staged row(s); ledger unchanged at %s transactions.\n", $fmt((int) $deleted), $fmt($after));
printf("WAL now       : %.2f MB\n", (@filesize(($pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] ?? '') . '-wal') ?: 0) / 1048576);
echo "\nTip: `VACUUM` would reclaim the freed pages, but it needs exclusive access —\n"
   . "run it while the app is stopped if you want the file itself to shrink.\n";
