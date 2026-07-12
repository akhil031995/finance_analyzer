<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single source of truth for the idempotency hash documented in
 * database/schema.sql. Both staging and commit recompute the hash through
 * here, so an edited staged row and a re-uploaded statement dedupe
 * consistently.
 *
 * Identity keys on the UNTOUCHED narration, so cosmetic description edits in
 * the Review UI never fork a row into a second ledger entry.
 */
final class TxnHash
{
    public static function make(
        int $accountId,
        string $txnDate,
        int $amountPaise,
        string $cashflow,
        string $narration,
        string $reference = ''
    ): string {
        return hash('sha256', implode('|', [
            $accountId,
            $txnDate,
            $amountPaise,
            $cashflow,
            self::normalize($narration),
            $reference,
        ]));
    }

    /** lowercase, strip punctuation, collapse whitespace */
    public static function normalize(string $text): string
    {
        return strtolower(trim((string) preg_replace(
            ['/[^\pL\pN\s]/u', '/\s+/'],
            ['', ' '],
            $text
        )));
    }
}
