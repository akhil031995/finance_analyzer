<?php

declare(strict_types=1);

namespace App\Services\Csv;

/**
 * Maps a statement's header cell to one of our canonical roles.
 *
 * This is what lets the mapping screen open pre-filled instead of blank: every
 * Indian bank invents its own header wording, but the vocabulary is small.
 * Synonyms are matched on the normalized header (lowercase, alphanumerics only),
 * longest first, so "closingbalance" beats "balance".
 */
final class HeaderSynonyms
{
    /**
     * role => synonyms (already normalized). Order within a role is irrelevant;
     * matching is longest-synonym-first across the whole table.
     *
     * @var array<string, list<string>>
     */
    private const MAP = [
        'date' => [
            'date', 'txndate', 'transactiondate', 'trandate', 'postingdate',
            'bookingdate', 'dateoftransaction', 'entrydate',
        ],
        'value_date' => ['valuedate', 'valuedat', 'valdate', 'effectivedate'],
        'description' => [
            'narration', 'particulars', 'description', 'remarks', 'details',
            'transactionremarks', 'transactiondetails', 'transactiondescription',
            'narrative', 'purpose',
        ],
        'debit' => [
            'debit', 'debitamount', 'withdrawal', 'withdrawals', 'withdrawalamt',
            'withdrawalamount', 'dr', 'dramount', 'debitamt', 'paidout', 'moneyout',
        ],
        'credit' => [
            'credit', 'creditamount', 'deposit', 'deposits', 'depositamt',
            'depositamount', 'cr', 'cramount', 'creditamt', 'paidin', 'moneyin',
        ],
        'amount' => ['amount', 'txnamount', 'transactionamount', 'amt', 'value'],
        'indicator' => ['drcr', 'crdr', 'type', 'trantype', 'transactiontype', 'debitcredit', 'indicator'],
        'balance' => [
            'balance', 'closingbalance', 'runningbalance', 'availablebalance',
            'balanceamt', 'balanceinr', 'accountbalance', 'closingbal',
        ],
        'reference' => [
            'chqrefnumber', 'chqrefno', 'refno', 'reference', 'referenceno',
            'referencenumber', 'chequedetails', 'chequeno', 'chqno', 'utr',
            'utrnumber', 'tranid', 'transactionid', 'txnid', 'refnochequeno',
        ],
    ];

    /** lowercase; keep only letters and digits */
    public static function normalize(string $header): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $header));
    }

    /**
     * The role this header names, or null if we don't recognise it.
     * Exact match first; then "contains" against the longest synonym, so
     * "Closing Balance (INR)" still resolves to `balance`.
     */
    public static function roleFor(string $header): ?string
    {
        $n = self::normalize($header);
        if ($n === '') {
            return null;
        }

        foreach (self::MAP as $role => $synonyms) {
            if (in_array($n, $synonyms, true)) {
                return $role;
            }
        }

        // Fall back to substring matching, longest synonym first so that
        // "closingbalance" is preferred over the bare "balance", and
        // "debitamount" over "amount".
        $ranked = [];
        foreach (self::MAP as $role => $synonyms) {
            foreach ($synonyms as $s) {
                $ranked[] = [$role, $s];
            }
        }
        usort($ranked, static fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));

        foreach ($ranked as [$role, $synonym]) {
            // Guard against 2-char synonyms ("dr", "cr") matching inside longer
            // words — "creditor", "address" — by requiring an exact hit for them.
            if (strlen($synonym) <= 2) {
                continue;
            }
            if (str_contains($n, $synonym)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * How header-like a row looks: the number of cells that resolve to a role.
     * Used to find the header row in files that carry account-info preamble.
     */
    public static function score(array $cells): int
    {
        $roles = [];
        foreach ($cells as $cell) {
            $role = self::roleFor((string) $cell);
            if ($role !== null) {
                $roles[$role] = true;
            }
        }

        return count($roles);
    }
}
