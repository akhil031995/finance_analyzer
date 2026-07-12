<?php

declare(strict_types=1);

namespace App\Services\Csv;

use PDO;

/**
 * Saved column mappings, keyed by the fingerprint of a statement's header row.
 *
 * This is what turns "map every upload" into "map each bank once". A returning
 * file matches its fingerprint and the mapping screen opens pre-filled and
 * confirmed; a bank that renames a column simply fails the lookup and lands in
 * the mapper, where re-confirming it costs one click and no code change.
 */
final class BankFormatRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_formats WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The best saved format that can still parse a file whose fingerprint no
     * longer matches — i.e. the bank added, removed or renamed some column we
     * never used. A format qualifies when every column its mapping references
     * is present in the file; among those, the most-used one wins.
     *
     * This is what makes "the bank changed its export" a non-event: only a
     * rename of a column we actually depend on sends the user back to the mapper.
     *
     * @param list<string> $headers
     * @return array<string,mixed>|null
     */
    public function findCompatible(array $headers): ?array
    {
        $rows = $this->pdo->query('SELECT * FROM bank_formats ORDER BY use_count DESC, id DESC')
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $format     = $this->hydrate($row);
            $referenced = ColumnMapping::referencedColumns($format['mapping']);
            if ($referenced === []) {
                continue;
            }
            if (array_diff($referenced, $headers) === []) {
                return $format;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bank_formats WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT f.*, a.name AS account_name,
                    (SELECT COUNT(*) FROM uploads u WHERE u.bank_format_id = f.id) AS upload_count
             FROM bank_formats f LEFT JOIN accounts a ON a.id = f.account_id
             ORDER BY f.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Upsert on fingerprint: re-importing a file whose layout we already know
     * updates that format rather than creating a near-duplicate.
     *
     * @param array<string,mixed> $mapping
     */
    public function save(
        string $name,
        string $fingerprint,
        string $headerLine,
        string $delimiter,
        array $mapping,
        ?int $accountId
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_formats (name, fingerprint, header_line, delimiter, account_id, mapping)
             VALUES (?,?,?,?,?,?)
             ON CONFLICT(fingerprint) DO UPDATE SET
                name       = excluded.name,
                mapping    = excluded.mapping,
                delimiter  = excluded.delimiter,
                account_id = COALESCE(excluded.account_id, bank_formats.account_id),
                updated_at = datetime(\'now\')'
        )->execute([
            $this->uniqueName($name, $fingerprint),
            $fingerprint,
            $headerLine,
            $delimiter,
            $accountId,
            json_encode($mapping, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $stmt = $this->pdo->prepare('SELECT id FROM bank_formats WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);

        return (int) $stmt->fetchColumn();
    }

    public function touch(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE bank_formats SET use_count = use_count + 1, last_used_at = datetime('now') WHERE id = ?"
        )->execute([$id]);
    }

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $vals = [];
        if (isset($fields['name'])) {
            $sets[] = 'name = ?';
            $vals[] = (string) $fields['name'];
        }
        if (array_key_exists('account_id', $fields)) {
            $sets[] = 'account_id = ?';
            $vals[] = $fields['account_id'] === null ? null : (int) $fields['account_id'];
        }
        if (isset($fields['mapping'])) {
            $sets[] = 'mapping = ?';
            $vals[] = json_encode($fields['mapping'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($sets === []) {
            return;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $id;
        $this->pdo->prepare('UPDATE bank_formats SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM bank_formats WHERE id = ?')->execute([$id]);
    }

    /** `name` is UNIQUE; disambiguate rather than reject a save. */
    private function uniqueName(string $name, string $fingerprint): string
    {
        $name = trim($name) !== '' ? trim($name) : 'Format ' . substr($fingerprint, 0, 6);
        $stmt = $this->pdo->prepare('SELECT 1 FROM bank_formats WHERE name = ? AND fingerprint != ?');

        $candidate = $name;
        for ($i = 2; $i < 100; $i++) {
            $stmt->execute([$candidate, $fingerprint]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
            $candidate = "{$name} ({$i})";
        }

        return $name . ' ' . substr($fingerprint, 0, 6);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        $row['mapping'] = json_decode((string) $row['mapping'], true) ?: [];

        return $row;
    }
}
