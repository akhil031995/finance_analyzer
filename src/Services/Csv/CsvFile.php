<?php

declare(strict_types=1);

namespace App\Services\Csv;

use RuntimeException;

/**
 * Reads a bank CSV into a raw grid, absorbing the transport-level differences
 * between banks: byte-order marks, Windows-1252 bytes in a nominally UTF-8
 * file, semicolon/tab delimiters, preamble rows above the real header, and
 * trailing empty columns produced by a stray comma at end of line.
 *
 * Nothing here knows what a transaction is — that is CsvStatementParser's job.
 * This class only answers: what is the header, and what are the data rows?
 */
final class CsvFile
{
    private const DELIMITERS = [',', ';', "\t", '|'];
    private const MAX_HEADER_PROBE = 25;

    /** @var list<list<string>> every row, including the header and any preamble */
    private array $grid;

    private string $delimiter;
    private string $encoding;
    private int $headerRow;
    /** @var list<string> */
    private array $headers;

    private function __construct(array $grid, string $delimiter, string $encoding, int $headerRow)
    {
        $this->grid      = $grid;
        $this->delimiter = $delimiter;
        $this->encoding  = $encoding;
        $this->headerRow = $headerRow;
        // Drop the header's trailing empty cells before naming the columns:
        // HDFC ends every line with a stray comma, and the resulting phantom
        // 8th column would otherwise change the fingerprint between exports
        // that do and don't happen to contain an over-wide row.
        $this->headers   = self::uniqueHeaders(self::dropTrailingEmpty($grid[$headerRow] ?? []));
    }

    /**
     * @param string|null $delimiter override the sniffed delimiter
     * @param int|null    $headerRow override the detected header row (0-based)
     */
    public static function open(string $path, ?string $delimiter = null, ?int $headerRow = null): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read CSV: ' . $path);
        }
        if (trim($bytes) === '') {
            throw new RuntimeException('The file is empty');
        }

        [$text, $encoding] = self::toUtf8($bytes);
        $delimiter ??= self::sniffDelimiter($text);
        $grid = self::readGrid($text, $delimiter);

        if ($grid === []) {
            throw new RuntimeException('No rows found in the file');
        }

        $headerRow ??= self::detectHeaderRow($grid);

        return new self($grid, $delimiter, $encoding, $headerRow);
    }

    /**
     * Rows carrying MORE fields than the header has columns. Caused by an
     * unquoted delimiter inside a free-text field — real example from an HDFC
     * export, where the narration itself contains a comma:
     *
     *     07/02/22, ACH C- CESC LIMITED,-160412, 07/02/22, 0, 270, ...
     *
     * Read positionally this shifts every later column by one (Debit would read
     * "07/02/22"). CsvStatementParser repairs them by folding the surplus back
     * into the description column, which is the only field a stray delimiter can
     * have come from.
     *
     * @return list<list<string>>
     */
    public function malformedRows(): array
    {
        $width = count($this->headers);
        $out   = [];
        foreach (array_slice($this->grid, $this->headerRow + 1) as $row) {
            if (trim(implode('', $row)) === '' ) {
                continue;
            }
            if (count(self::dropTrailingEmpty($row)) > $width) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    public function headerRowIndex(): int
    {
        return $this->headerRow;
    }

    /** @return list<string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * A stable identifier for this statement layout. Recognising it lets a
     * repeat upload skip the mapping screen.
     *
     * Keyed on the normalized header NAMES, SORTED — so neither whitespace,
     * casing, nor a bank REORDERING its columns invalidates a saved format
     * (mappings address columns by name, so order genuinely does not matter).
     * Adding or removing a column does change the fingerprint; that case is
     * caught by BankFormatRepository::findCompatible().
     */
    public function fingerprint(): string
    {
        $parts = array_map([HeaderSynonyms::class, 'normalize'], $this->headers);
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Data rows only, positionally indexed and normalized to the header width.
     *
     * Trailing empty cells are dropped first: HDFC terminates every data line
     * with a comma, and treating that phantom 8th field as content would make
     * every row look one column too wide. Genuinely over-wide rows (an unquoted
     * delimiter inside the narration) survive that trim and are returned intact
     * so CsvStatementParser can repair them.
     *
     * @return list<list<string>>
     */
    public function dataRows(int $limit = 0): array
    {
        $width = count($this->headers);
        $rows  = [];
        foreach (array_slice($this->grid, $this->headerRow + 1) as $row) {
            if (trim(implode('', $row)) === '') {
                continue;   // blank separator line
            }
            $row    = self::dropTrailingEmpty($row);
            $rows[] = count($row) < $width ? array_pad($row, $width, '') : $row;
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function dataRowCount(): int
    {
        return count($this->dataRows());
    }

    /**
     * Data rows keyed by header name, for mapping-driven access.
     * @return list<array<string,string>>
     */
    public function assocRows(int $limit = 0): array
    {
        $out = [];
        foreach ($this->dataRows($limit) as $row) {
            $assoc = [];
            foreach ($this->headers as $i => $name) {
                $assoc[$name] = $row[$i] ?? '';
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /**
     * Sample values from one column, for type sniffing and date-format guessing.
     * @return list<string>
     */
    public function column(string $header, int $limit = 200): array
    {
        $idx = array_search($header, $this->headers, true);
        if ($idx === false) {
            return [];
        }

        $out = [];
        foreach ($this->dataRows($limit) as $row) {
            $out[] = $row[$idx] ?? '';
        }

        return $out;
    }

    // ---------------------------------------------------------------- private

    /** @return array{0:string,1:string} [utf-8 text, detected source encoding] */
    private static function toUtf8(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return [substr($bytes, 3), 'UTF-8 (BOM)'];
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, 'UTF-8'];
        }
        // Practically always Windows-1252 for Indian bank exports (₹, smart
        // quotes). mb_convert_encoding never fails, so this is a safe default.
        return [mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    /**
     * The delimiter that yields the most columns AND the most consistent column
     * count across the first few lines. Consistency matters more than raw count:
     * a narration full of commas would otherwise beat a real semicolon file.
     */
    private static function sniffDelimiter(string $text): string
    {
        $lines = array_slice(array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            static fn ($l) => trim($l) !== ''
        )), 0, 10);

        if ($lines === []) {
            return ',';
        }

        $best      = ',';
        $bestScore = -1.0;
        foreach (self::DELIMITERS as $delim) {
            $counts = array_map(static fn ($l) => count(str_getcsv($l, $delim, '"', '\\')), $lines);
            $max    = max($counts);
            if ($max < 2) {
                continue;
            }
            $consistent = count(array_filter($counts, static fn ($c) => $c === $max));
            $score      = $consistent / count($counts) * 100 + $max;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $delim;
            }
        }

        return $best;
    }

    /** @return list<list<string>> */
    private static function readGrid(string $text, string $delimiter): array
    {
        // A memory stream (rather than str_getcsv per line) so that quoted
        // fields containing embedded newlines are handled correctly.
        $fh = fopen('php://memory', 'r+');
        if ($fh === false) {
            throw new RuntimeException('Cannot allocate a read buffer');
        }
        fwrite($fh, $text);
        rewind($fh);

        $grid = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;   // blank line
            }
            $grid[] = array_map(static fn ($c) => trim((string) $c), $row);
        }
        fclose($fh);

        return $grid;
    }

    /**
     * @param list<string> $cells
     * @return list<string>
     */
    private static function dropTrailingEmpty(array $cells): array
    {
        while ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells);
        }

        return array_values($cells);
    }

    /**
     * The header is the first row that names at least three of our roles.
     * Falls back to row 0 for headerless or exotic files (the user can override
     * it in the mapping UI either way).
     *
     * @param list<list<string>> $grid
     */
    private static function detectHeaderRow(array $grid): int
    {
        $probe = min(count($grid), self::MAX_HEADER_PROBE);
        for ($i = 0; $i < $probe; $i++) {
            if (HeaderSynonyms::score($grid[$i]) >= 3) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Header names become array keys and <select> values, so they must be
     * unique and non-empty. Blank or repeated cells get a positional suffix.
     *
     * @param list<string> $cells
     * @return list<string>
     */
    private static function uniqueHeaders(array $cells): array
    {
        $seen = [];
        $out  = [];
        foreach ($cells as $i => $cell) {
            $name = trim((string) $cell);
            if ($name === '') {
                $name = 'Column ' . ($i + 1);
            }
            if (isset($seen[$name])) {
                $name .= ' (' . (++$seen[$name]) . ')';
            } else {
                $seen[$name] = 1;
            }
            $out[] = $name;
        }

        return $out;
    }
}
