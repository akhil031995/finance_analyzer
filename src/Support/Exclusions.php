<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * The single definition of "this tag doesn't count".
 *
 * Every query that measures income or spending appends Exclusions::SQL rather
 * than carrying its own hardcoded list, so the user's choice in Settings takes
 * effect across analytics, budgets, the dashboard and the daily/monthly views
 * at once. The list lives in the `excluded_categories` table; the SQL is a
 * subquery, not an interpolated list, so it is always current and never needs
 * a cache to be invalidated.
 *
 * Account balances deliberately do NOT use this. CommitService::recomputeBalance()
 * sums every committed row regardless of category, so an account always
 * reconciles to the closing balance printed on its statement. Excluding a tag
 * hides it from *analysis*, never from the ledger or from your actual balance.
 */
final class Exclusions
{
    /**
     * Rows that count toward income/expense analysis. Two levels of exclusion:
     * a whole tag (excluded_categories) or one specific row (is_excluded).
     */
    public const SQL = 'is_excluded = 0 AND category NOT IN (SELECT category FROM excluded_categories)';

    /** Rows deliberately left out of it, by either level. */
    public const INVERSE = '(is_excluded = 1 OR category IN (SELECT category FROM excluded_categories))';

    /** @return list<string> */
    public static function all(PDO $pdo): array
    {
        return $pdo->query('SELECT category FROM excluded_categories ORDER BY category')
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
