<?php

declare(strict_types=1);

/**
 * Offline validation harness: parse + tag every CSV in bank_statements/ and
 * print what would be imported. Touches no tables — it reads tagging_rules and
 * the self-identity settings, and writes nothing.
 *
 * This is the ground truth for "is the rule engine good enough yet". Watch two
 * numbers: the balance-continuity check (must be OK on every file) and the
 * share of rows landing in other_income/other_expense.
 *
 * Usage:
 *   php bin/parse_test.php                 summary for every file
 *   php bin/parse_test.php --untagged      list the narrations still untagged
 *   php bin/parse_test.php --self          list rows detected as self-transfers
 *   php bin/parse_test.php <glob>          restrict to matching files
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\Csv\ColumnMapping;
use App\Services\Csv\CsvFile;
use App\Services\Csv\CsvStatementParser;
use App\Services\Csv\MappingSuggester;
use App\Services\Tagging\TaggingEngine;
use App\Support\Money;

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}

/** @var PDO $pdo */
$pdo = require __DIR__ . '/migrate.php';

$argv         = $_SERVER['argv'];
$showUntagged = in_array('--untagged', $argv, true);
$showSelf     = in_array('--self', $argv, true);
$glob         = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        $glob = $arg;
    }
}

$files = glob($glob ?? dirname(__DIR__) . '/bank_statements/*.csv') ?: [];
if ($files === []) {
    fwrite(STDERR, "No CSV files found.\n");
    exit(1);
}

$parser   = new CsvStatementParser();
$engine   = new TaggingEngine($pdo);
$catTotal = [];
$untagged = [];
$selfRows = [];
$grand    = ['rows' => 0, 'untagged' => 0, 'self' => 0, 'files_ok' => 0];
$spend    = ['total' => 0, 'untagged' => 0];   // debit paise, self-transfers excluded

foreach ($files as $file) {
    $csv = CsvFile::open($file);
    $sug = MappingSuggester::suggest($csv);
    $map = ColumnMapping::fromArray($sug['mapping'], $csv->headers());

    // These files are all asset accounts; asserting that lets the balance check
    // catch an inverted debit/credit mapping instead of silently accommodating it.
    ['rows' => $rows, 'report' => $report] = $parser->parse($csv, $map, false);

    $counts = [];
    foreach ($rows as $row) {
        $tag = $engine->tag(['description' => $row['description'], 'cashflow' => $row['cashflow']]);
        $cat = $tag['category'];

        $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        $catTotal[$cat] = ($catTotal[$cat] ?? 0) + 1;
        $grand['rows']++;

        if (in_array($cat, ['other_income', 'other_expense'], true)) {
            $grand['untagged']++;
            $untagged[] = $row['description'];
        }
        if ($tag['is_self_transfer'] === 1) {
            $grand['self']++;
            $selfRows[] = $row['description'];
        }

        // How much real spending is unclassified? A finance app cares about
        // rupees, not row counts: 800 one-rupee UPI payments matter less than
        // one unclassified rent transfer.
        if ($row['cashflow'] === 'debit' && $tag['is_self_transfer'] === 0) {
            $spend['total'] += $row['amount'];
            $spend['untagged'] += $cat === 'other_expense' ? $row['amount'] : 0;
        }
    }

    $bc = $report['balance_check'];
    $balanceLine = $bc['performed']
        ? ($bc['ok'] ? "OK ({$bc['checked']} rows, {$bc['convention']})"
                     : "*** {$bc['mismatches']}/{$bc['checked']} MISMATCH ***")
        : "skipped: {$bc['reason']}";
    $grand['files_ok'] += ($bc['performed'] && $bc['ok']) ? 1 : 0;

    $other = ($counts['other_income'] ?? 0) + ($counts['other_expense'] ?? 0);
    $pct   = $report['parsed'] > 0 ? $other / $report['parsed'] * 100 : 0;

    printf("\n=== %s\n", basename($file));
    printf("    fingerprint  %s   %d rows   %s .. %s (%s)\n",
        substr($csv->fingerprint(), 0, 12), $report['parsed'], $report['date_from'], $report['date_to'], $report['row_order']);
    printf("    parsed %d   skipped %d   errors %d   repaired %d\n",
        $report['parsed'], $report['skipped'], $report['errors'], $report['repaired_rows']);
    printf("    debit %s   credit %s\n",
        Money::toDecimal($report['totals']['debit']), Money::toDecimal($report['totals']['credit']));
    printf("    balance      %s\n", $balanceLine);
    printf("    untagged     %d (%.1f%%)\n", $other, $pct);

    foreach ($report['error_rows'] as $e) {
        printf("    ! line %d: %s\n", $e['line'], $e['reason']);
    }
    foreach ($bc['breaks'] ?? [] as $b) {
        printf("    ! balance break at line %d: expected %s, got %s\n",
            $b['line'], Money::toDecimal($b['expected']), Money::toDecimal($b['actual']));
    }
}

arsort($catTotal);
printf("\n\n======= TOTAL: %d transactions across %d files =======\n", $grand['rows'], count($files));
printf("balance check passed on %d/%d files\n\n", $grand['files_ok'], count($files));
foreach ($catTotal as $cat => $n) {
    printf("  %-22s %5d  %5.1f%%  %s\n", $cat, $n, $n / max(1, $grand['rows']) * 100,
        str_repeat('#', (int) round($n / max(1, $grand['rows']) * 60)));
}
printf("\nuntagged by count: %d / %d = %.1f%%\n", $grand['untagged'], $grand['rows'],
    $grand['untagged'] / max(1, $grand['rows']) * 100);
printf("untagged by value: %s of %s spend = %.1f%%\n",
    Money::toDecimal($spend['untagged']), Money::toDecimal($spend['total']),
    $spend['untagged'] / max(1, $spend['total']) * 100);
printf("self-transfers:    %d\n", $grand['self']);

if ($showUntagged) {
    $freq = array_count_values($untagged);
    arsort($freq);
    printf("\n--- top untagged narrations (%d distinct) ---\n", count($freq));
    foreach (array_slice($freq, 0, 40, true) as $desc => $n) {
        printf("  %4d  %s\n", $n, mb_substr($desc, 0, 100));
    }
}

if ($showSelf) {
    $freq = array_count_values($selfRows);
    arsort($freq);
    printf("\n--- self-transfer matches (%d distinct) ---\n", count($freq));
    foreach (array_slice($freq, 0, 40, true) as $desc => $n) {
        printf("  %4d  %s\n", $n, mb_substr($desc, 0, 100));
    }
}
