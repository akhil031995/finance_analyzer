<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Account identity colours.
 *
 * These tint dots, rules and chips — never text and never a money figure. A
 * balance stays mint (asset) or rose (liability) whatever colour the account
 * wears, so no pick can make a number unreadable on the dark theme, and the
 * colour never competes with the one signal that carries meaning.
 *
 * Ten hues, each legible against #0b1120 and distinguishable from its
 * neighbours. Any `#rrggbb` is accepted from the API; the UI offers these.
 */
final class Palette
{
    public const ACCOUNTS = [
        '#38bdf8',  // sky
        '#34d399',  // emerald
        '#f59e0b',  // amber
        '#a78bfa',  // violet
        '#fb7185',  // rose
        '#22d3ee',  // cyan
        '#a3e635',  // lime
        '#f472b6',  // pink
        '#fb923c',  // orange
        '#94a3b8',  // slate
    ];

    /** The colour shown when an account has none — never a palette entry. */
    public const FALLBACK = '#64748b';

    public static function isValid(string $hex): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /** The first palette colour not already taken, wrapping when they run out. */
    public static function next(array $taken): string
    {
        foreach (self::ACCOUNTS as $c) {
            if (!in_array($c, $taken, true)) {
                return $c;
            }
        }

        return self::ACCOUNTS[count($taken) % count(self::ACCOUNTS)];
    }
}
