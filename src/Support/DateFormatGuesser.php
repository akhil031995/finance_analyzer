<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Infers the date format of a statement column from its values.
 *
 * The hard part is that `02/01/26` is either 2 Jan or 1 Feb, and guessing wrong
 * silently corrupts months of data. Two safeguards:
 *
 *  1. ROUND-TRIP validation. DateTimeImmutable::createFromFormat('d/m/Y', '02/01/26')
 *     happily returns year 0026 with no error, so a format is only accepted when
 *     re-formatting the parsed date reproduces the input byte for byte.
 *
 *  2. AMBIGUITY reporting. If a day-first and a month-first format both parse
 *     100% of the sampled values, no value in the column disambiguates them
 *     (every day is <= 12). guess() then returns ambiguous = true and the UI
 *     must ask the user rather than silently defaulting.
 */
final class DateFormatGuesser
{
    /**
     * Ordered by likelihood for Indian bank statements — the first format that
     * parses every sample wins.
     *
     * @var list<string>
     */
    public const CANDIDATES = [
        'd/m/Y', 'd-m-Y', 'd.m.Y',
        'd/m/y', 'd-m-y', 'd.m.y',
        'd-m-Y H:i:s', 'd/m/Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i',
        'Y-m-d', 'Y/m/d', 'Y-m-d H:i:s',
        'd M Y', 'd-M-Y', 'd M y', 'd-M-y', 'd-M-Y H:i:s',
        'j/n/Y', 'j-n-Y', 'j M Y',
        'm/d/Y', 'm-d-Y', 'm/d/y', 'm-d-y', 'n/j/Y',
        'M d, Y', 'd F Y',
    ];

    /** Day-first format => the month-first format it can be confused with. */
    private const SWAPPED = [
        'd/m/Y' => 'm/d/Y', 'd-m-Y' => 'm-d-Y',
        'd/m/y' => 'm/d/y', 'd-m-y' => 'm-d-y',
        'j/n/Y' => 'n/j/Y',
    ];

    /**
     * @param list<string> $samples raw cell values from the date column
     * @return array{format:?string, ambiguous:bool, alternative:?string,
     *               matched:int, total:int, failures:list<string>}
     */
    public static function guess(array $samples): array
    {
        $samples = array_values(array_filter(array_map('trim', $samples), static fn ($s) => $s !== ''));
        $total   = count($samples);
        if ($total === 0) {
            return ['format' => null, 'ambiguous' => false, 'alternative' => null,
                    'matched' => 0, 'total' => 0, 'failures' => []];
        }

        // Cap the work on huge files; 200 rows is plenty to pin a format and to
        // surface a day > 12 if the column contains one.
        $probe = array_slice($samples, 0, 200);

        $fullMatches = [];
        $best        = ['format' => null, 'matched' => 0];
        foreach (self::CANDIDATES as $format) {
            $matched = 0;
            foreach ($probe as $value) {
                if (self::parse($value, $format) !== null) {
                    $matched++;
                }
            }
            if ($matched === count($probe)) {
                $fullMatches[] = $format;
            }
            if ($matched > $best['matched']) {
                $best = ['format' => $format, 'matched' => $matched];
            }
        }

        $chosen = $fullMatches[0] ?? $best['format'];
        if ($chosen === null) {
            return ['format' => null, 'ambiguous' => false, 'alternative' => null,
                    'matched' => 0, 'total' => $total, 'failures' => array_slice($probe, 0, 5)];
        }

        // Ambiguous only when the swapped twin ALSO parses everything: that
        // means no sampled value has a day > 12 to settle it.
        $twin = self::SWAPPED[$chosen] ?? null;
        $ambiguous = $twin !== null && in_array($twin, $fullMatches, true);

        $failures = [];
        foreach ($probe as $value) {
            if (self::parse($value, $chosen) === null) {
                $failures[] = $value;
                if (count($failures) >= 5) {
                    break;
                }
            }
        }

        $matched = 0;
        foreach ($probe as $value) {
            $matched += self::parse($value, $chosen) !== null ? 1 : 0;
        }

        return [
            'format'      => $chosen,
            'ambiguous'   => $ambiguous,
            'alternative' => $ambiguous ? $twin : null,
            'matched'     => $matched,
            'total'       => count($probe),
            'failures'    => $failures,
        ];
    }

    /**
     * Strictly parse one cell. Returns 'YYYY-MM-DD' or null.
     *
     * Strict = the parsed date, re-rendered through the same format, must equal
     * the input. That rejects both silent 2-digit-year coercion ('26' as year
     * 0026 under 'Y') and overflow ('31/02/2026' rolling into March).
     */
    public static function parse(string $value, string $format): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($dt === false) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        // Case-insensitive: "02 JAN 2026" is a valid rendering of 'd M Y'.
        if (strcasecmp($dt->format($format), $value) !== 0) {
            return null;
        }

        return $dt->format('Y-m-d');
    }
}
