<?php

declare(strict_types=1);

namespace App\Services\Csv;

use InvalidArgumentException;

/**
 * A validated description of which CSV column plays which role.
 *
 * Columns are addressed by NAME, never by index. That is the whole point: when
 * a bank inserts, removes or reorders a column, a name-keyed mapping keeps
 * working, and only a genuine rename forces a trip back to the mapping screen.
 *
 * Amount styles seen in the wild:
 *   debit_credit  two columns; the unused one holds 0 (HDFC, Federal) or is
 *                 blank (Kotak). Exactly one must carry a non-zero value.
 *   signed        one column; negative means money out.
 *   indicator     one magnitude column plus a DR/CR column.
 *
 * WARNING, and the reason `indicator` is never auto-selected: on Federal and
 * Kotak statements the trailing "Dr/ Cr" column describes the BALANCE, not the
 * transaction — it reads CR on every row, including withdrawals. Using it for
 * direction would invert every debit. Only pick `indicator` when the column
 * genuinely varies with the transaction.
 */
final class ColumnMapping
{
    public const MODES = ['debit_credit', 'signed', 'indicator'];

    private function __construct(private array $m)
    {
    }

    /**
     * Every column name a raw mapping array depends on. A file that contains
     * all of them can be parsed by that mapping, whatever else it carries —
     * which is how an added or reordered column stays a zero-click import.
     *
     * @param array<string,mixed> $mapping
     * @return list<string>
     */
    public static function referencedColumns(array $mapping): array
    {
        $cols = [
            $mapping['date']['column'] ?? null,
            $mapping['amount']['debit'] ?? null,
            $mapping['amount']['credit'] ?? null,
            $mapping['amount']['amount'] ?? null,
            $mapping['amount']['indicator'] ?? null,
            $mapping['balance']['column'] ?? null,
            $mapping['reference']['column'] ?? null,
            ...array_values((array) ($mapping['description']['columns'] ?? [])),
        ];
        foreach ((array) ($mapping['skip_rows'] ?? []) as $rule) {
            $cols[] = $rule['column'] ?? null;
        }

        return array_values(array_unique(array_filter($cols, static fn ($c) => is_string($c) && $c !== '')));
    }

    public static function defaults(): array
    {
        return [
            'header_row'  => 0,
            'date'        => ['column' => null, 'format' => null],
            'description' => ['columns' => []],
            'amount'      => [
                'mode'         => 'debit_credit',
                'debit'        => null,
                'credit'       => null,
                'amount'       => null,
                'indicator'    => null,
                'debit_values' => ['DR', 'D', 'DEBIT', 'W', 'WITHDRAWAL'],
            ],
            'balance'     => ['column' => null],
            'reference'   => ['column' => null],
            'skip_rows'   => [],
            'clean_ocr'   => false,
        ];
    }

    /**
     * @param list<string> $headers the file's actual header names
     * @throws InvalidArgumentException with a message meant for the UI
     */
    public static function fromArray(array $raw, array $headers): self
    {
        $m = array_replace_recursive(self::defaults(), $raw);

        // array_replace_recursive merges LISTS index-by-index, so a caller that
        // sends debit_values: ["DR"] would get the four defaults back in slots
        // 1-4. Lists must be replaced wholesale, not merged.
        foreach ([['description', 'columns'], ['amount', 'debit_values']] as [$outer, $inner]) {
            if (isset($raw[$outer][$inner]) && is_array($raw[$outer][$inner])) {
                $m[$outer][$inner] = array_values($raw[$outer][$inner]);
            }
        }
        if (isset($raw['skip_rows']) && is_array($raw['skip_rows'])) {
            $m['skip_rows'] = array_values($raw['skip_rows']);
        }

        $inHeaders = static function (?string $col, string $label) use ($headers): ?string {
            if ($col === null || $col === '') {
                return null;
            }
            if (!in_array($col, $headers, true)) {
                throw new InvalidArgumentException("{$label} refers to column \"{$col}\", which is not in this file");
            }

            return $col;
        };

        // --- date ---
        $date = $inHeaders($m['date']['column'] ?? null, 'Date');
        if ($date === null) {
            throw new InvalidArgumentException('Pick the transaction date column');
        }
        if (empty($m['date']['format'])) {
            throw new InvalidArgumentException('Pick the date format');
        }

        // --- description (one or more columns, concatenated) ---
        $descCols = array_values(array_filter((array) ($m['description']['columns'] ?? [])));
        if ($descCols === []) {
            throw new InvalidArgumentException('Pick the description column');
        }
        foreach ($descCols as $c) {
            $inHeaders($c, 'Description');
        }
        $m['description']['columns'] = $descCols;

        // --- amount ---
        $mode = (string) ($m['amount']['mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Amount style must be one of: ' . implode(', ', self::MODES));
        }
        if ($mode === 'debit_credit') {
            if ($inHeaders($m['amount']['debit'] ?? null, 'Debit') === null
                || $inHeaders($m['amount']['credit'] ?? null, 'Credit') === null) {
                throw new InvalidArgumentException('Separate debit/credit columns: pick both');
            }
        } elseif ($mode === 'signed') {
            if ($inHeaders($m['amount']['amount'] ?? null, 'Amount') === null) {
                throw new InvalidArgumentException('Signed amount: pick the amount column');
            }
        } else {
            if ($inHeaders($m['amount']['amount'] ?? null, 'Amount') === null
                || $inHeaders($m['amount']['indicator'] ?? null, 'Dr/Cr indicator') === null) {
                throw new InvalidArgumentException('Amount + indicator: pick both columns');
            }
            if (array_filter((array) ($m['amount']['debit_values'] ?? [])) === []) {
                throw new InvalidArgumentException('List at least one value that means "debit" (e.g. DR)');
            }
        }

        // --- optional ---
        $m['balance']['column']   = $inHeaders($m['balance']['column'] ?? null, 'Balance');
        $m['reference']['column'] = $inHeaders($m['reference']['column'] ?? null, 'Reference');

        // --- skip rules ---
        $skips = [];
        foreach ((array) ($m['skip_rows'] ?? []) as $rule) {
            $col   = $inHeaders($rule['column'] ?? null, 'Skip rule');
            $regex = (string) ($rule['regex'] ?? '');
            if ($col === null || $regex === '') {
                continue;
            }
            if (@preg_match('/' . str_replace('/', '\/', $regex) . '/i', '') === false) {
                throw new InvalidArgumentException("Skip rule \"{$regex}\" is not a valid pattern");
            }
            $skips[] = ['column' => $col, 'regex' => $regex];
        }
        $m['skip_rows'] = $skips;
        $m['clean_ocr'] = !empty($m['clean_ocr']);
        $m['header_row'] = (int) ($m['header_row'] ?? 0);

        return new self($m);
    }

    public function toArray(): array
    {
        return $this->m;
    }

    public function headerRow(): int
    {
        return $this->m['header_row'];
    }

    public function dateColumn(): string
    {
        return $this->m['date']['column'];
    }

    public function dateFormat(): string
    {
        return $this->m['date']['format'];
    }

    /** @return list<string> */
    public function descriptionColumns(): array
    {
        return $this->m['description']['columns'];
    }

    public function amountMode(): string
    {
        return $this->m['amount']['mode'];
    }

    public function debitColumn(): ?string
    {
        return $this->m['amount']['debit'];
    }

    public function creditColumn(): ?string
    {
        return $this->m['amount']['credit'];
    }

    public function amountColumn(): ?string
    {
        return $this->m['amount']['amount'];
    }

    public function indicatorColumn(): ?string
    {
        return $this->m['amount']['indicator'];
    }

    /** @return list<string> uppercased for comparison */
    public function debitValues(): array
    {
        return array_map(
            static fn ($v) => strtoupper(trim((string) $v)),
            array_values(array_filter((array) $this->m['amount']['debit_values']))
        );
    }

    public function balanceColumn(): ?string
    {
        return $this->m['balance']['column'];
    }

    public function referenceColumn(): ?string
    {
        return $this->m['reference']['column'];
    }

    /** @return list<array{column:string,regex:string}> */
    public function skipRows(): array
    {
        return $this->m['skip_rows'];
    }

    public function cleanOcr(): bool
    {
        return $this->m['clean_ocr'];
    }
}
