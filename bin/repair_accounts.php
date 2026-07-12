<?php

declare(strict_types=1);

/**
 * One-off repair for statements committed into the wrong account, and for
 * accounts left with an opening balance of 0.
 *
 * Why this is needed: `current_balance = opening_balance + credits - debits`.
 * An account whose opening balance is 0 reports the imported period's NET FLOW,
 * not its balance — so a normal spending stretch shows a negative asset account
 * and drags net worth below zero. Separately, committing two banks' statements
 * into one account interleaves their balance chains and nothing downstream can
 * tell them apart.
 *
 * txn_hash includes account_id, so moving a transaction between accounts
 * REQUIRES recomputing its hash — otherwise re-importing that statement later
 * would not dedupe against the moved rows.
 *
 * Usage:
 *   php bin/repair_accounts.php                       dry run (default)
 *   php bin/repair_accounts.php --apply               write the changes
 *   DB_PATH=/path/to.sqlite php bin/repair_accounts.php --apply
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CommitService;
use App\Support\Money;
use App\Support\TxnHash;

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}

/** @var PDO $pdo */
$pdo   = require __DIR__ . '/migrate.php';
$apply = in_array('--apply', $_SERVER['argv'] ?? [], true);

// ---------------------------------------------------------------------------
// EDIT ME: uploads that landed in the wrong account, and the account they belong to.
// ---------------------------------------------------------------------------
$moves = [
    // upload_id => correct account_id
    5 => 2,   // HDFC_20230101_20231231.csv  -> HDFC
    6 => 2,   // HDFC_20240101_20241231.csv  -> HDFC
    7 => 2,   // HDFC_20250101_20251231.csv  -> HDFC
    8 => 2,   // HDFC_20260101_20260630.csv  -> HDFC
];

// Accounts whose opening balance should be derived from their earliest
// transaction's printed balance. Listed explicitly rather than inferred, so a
// re-run cannot silently rewrite a balance you set by hand.
$deriveOpeningFor = [2, 3, 4];

// ---------------------------------------------------------------------------

$say = static fn (string $line) => fwrite(STDOUT, $line . "\n");
$say($apply ? "=== APPLYING CHANGES ===\n" : "=== DRY RUN (pass --apply to write) ===\n");

$pdo->beginTransaction();

try {
    // --- 1. move mis-filed transactions, recomputing their identity hash -----
    $rows = $pdo->prepare(
        'SELECT id, account_id, txn_date, amount, cashflow, raw_description, description, reference_id
         FROM transactions WHERE upload_id = ?'
    );
    $update = $pdo->prepare('UPDATE transactions SET account_id = ?, txn_hash = ? WHERE id = ?');

    foreach ($moves as $uploadId => $targetAccount) {
        $name = $pdo->query('SELECT original_name FROM uploads WHERE id = ' . (int) $uploadId)->fetchColumn();
        if ($name === false) {
            $say("upload #{$uploadId}: not found, skipping");
            continue;
        }

        $rows->execute([$uploadId]);
        $moved = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['account_id'] === $targetAccount) {
                continue;
            }
            $hash = TxnHash::make(
                $targetAccount,
                (string) $r['txn_date'],
                (int) $r['amount'],
                (string) $r['cashflow'],
                (string) ($r['raw_description'] ?? $r['description'] ?? ''),
                (string) ($r['reference_id'] ?? '')
            );
            $update->execute([$targetAccount, $hash, $r['id']]);
            $moved++;
        }

        $pdo->prepare('UPDATE uploads SET account_id = ? WHERE id = ?')->execute([$targetAccount, $uploadId]);
        $pdo->prepare('UPDATE staged_transactions SET account_id = ? WHERE upload_id = ?')->execute([$targetAccount, $uploadId]);

        $say(sprintf('moved %5d txns of %-34s -> account %d', $moved, $name, $targetAccount));
    }

    // --- 2. derive opening balances from the earliest printed balance --------
    $say('');
    foreach ($deriveOpeningFor as $accountId) {
        $a = $pdo->query("SELECT name, is_liability, opening_balance FROM accounts WHERE id = {$accountId}")
            ->fetch(PDO::FETCH_ASSOC);
        if ($a === false) {
            continue;
        }

        $first = $pdo->query(
            "SELECT txn_date, amount, cashflow, balance_after FROM transactions
             WHERE account_id = {$accountId} AND balance_after IS NOT NULL
             ORDER BY txn_date, id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($first === false) {
            $say(sprintf('%-16s no transactions with a balance — leaving opening at %s',
                $a['name'], Money::toDecimal((int) $a['opening_balance'])));
            continue;
        }

        $delta   = $first['cashflow'] === 'credit' ? (int) $first['amount'] : -(int) $first['amount'];
        $implied = (int) $first['balance_after'] - ((int) $a['is_liability'] === 1 ? -$delta : $delta);

        $pdo->prepare("UPDATE accounts SET opening_balance = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$implied, $accountId]);

        $say(sprintf('%-16s opening %s -> %s   (from the %s row)',
            $a['name'], Money::toDecimal((int) $a['opening_balance']), Money::toDecimal($implied), $first['txn_date']));
    }

    // --- 3. recompute balances and reconcile against each statement ----------
    $say('');
    $commit = new CommitService($pdo);
    $allOk  = true;

    foreach ($pdo->query('SELECT id, name FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $balance = $commit->recomputeBalance((int) $a['id']);

        $stmt = $pdo->query(
            "SELECT balance_after FROM transactions
             WHERE account_id = {$a['id']} AND balance_after IS NOT NULL
             ORDER BY txn_date DESC, id DESC LIMIT 1"
        )->fetchColumn();

        if ($stmt === false) {
            $say(sprintf('%-16s balance %14s   (no statement rows)', $a['name'], Money::toDecimal($balance)));
            continue;
        }

        $ok = (int) $stmt === $balance;
        $allOk = $allOk && $ok;
        $say(sprintf('%-16s balance %14s   statement says %14s   %s',
            $a['name'], Money::toDecimal($balance), Money::toDecimal((int) $stmt), $ok ? 'RECONCILES' : '*** MISMATCH ***'));
    }

    $nw = $pdo->query('SELECT * FROM v_net_worth')->fetch(PDO::FETCH_ASSOC);
    $say(sprintf("\nnet worth %s  (assets %s, liabilities %s)",
        Money::toDecimal((int) $nw['net_worth']),
        Money::toDecimal((int) $nw['total_assets']),
        Money::toDecimal((int) $nw['total_liabilities'])));

    if (!$allOk) {
        $say("\nAt least one account does not reconcile. Nothing was written.");
        $pdo->rollBack();
        exit(1);
    }

    if ($apply) {
        $pdo->commit();
        $say("\nApplied.");
    } else {
        $pdo->rollBack();
        $say("\nDry run — rolled back. Re-run with --apply to keep these changes.");
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nFAILED, nothing written: " . $e->getMessage() . "\n");
    exit(1);
}
