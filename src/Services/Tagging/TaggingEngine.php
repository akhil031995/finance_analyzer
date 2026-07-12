<?php

declare(strict_types=1);

namespace App\Services\Tagging;

use PDO;

/**
 * Assigns category / mode / counterparty / self-transfer to a parsed row,
 * without an LLM. Deterministic, inspectable, and editable from the UI.
 *
 * Pipeline, first hit wins:
 *   1. Narration grammar     -> mode, counterparty, VPA, MCC
 *   2. Self-identity         -> is this me paying myself?   (tag_source 'auto')
 *   3. tagging_rules table   -> merchant / channel rules     (tag_source 'rule')
 *   4. MCC map               -> Federal's embedded category  (tag_source 'auto')
 *   5. Channel fallback      -> ATM => cash_withdrawal, etc. (tag_source 'auto')
 *   6. Fallback              -> other_income / other_expense (tag_source 'auto')
 *
 * Nothing here ever overwrites a row whose tag_source is 'manual'; retagging
 * skips those (see retagUploaded()).
 */
final class TaggingEngine
{
    /** Levenshtein slack when comparing a statement VPA to a known one. */
    private const VPA_FUZZ = 2;

    /** @var list<array<string,mixed>> */
    private array $rules;
    /** @var list<string> uppercased */
    private array $selfNames;
    /** @var list<string> normalized */
    private array $selfVpas;
    /** @var array<int,int> rule id => times matched this run */
    private array $hits = [];

    public function __construct(private PDO $pdo)
    {
        // Longest pattern first inside a priority band, so "CRED CLUB" (credit
        // card bill) is tested before "CRED", and "GOOGLE PLAY" before "GOOGLE".
        $this->rules = $pdo->query(
            'SELECT id, pattern, field, match_type, cashflow, category, set_mode, is_self_transfer
             FROM tagging_rules WHERE enabled = 1
             ORDER BY priority DESC, LENGTH(pattern) DESC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->selfNames = array_values(array_filter(array_map(
            static fn ($s) => strtoupper(trim($s)),
            explode('|', (string) $this->setting('self_identity_names'))
        )));
        $this->selfVpas = array_values(array_filter(array_map(
            [self::class, 'normalizeVpa'],
            explode('|', (string) $this->setting('self_identity_vpas'))
        )));
    }

    /**
     * @param array{description:string, cashflow:string} $row
     * @return array{category:string, mode:string, counterparty:?string,
     *               is_self_transfer:int, tag_source:string, tag_rule_id:?int}
     */
    public function tag(array $row): array
    {
        $text     = $row['description'];
        $cashflow = $row['cashflow'];
        $n        = NarrationParser::parse($text);

        $base = [
            'mode'             => $n['mode'],
            'counterparty'     => $n['counterparty'],
            'is_self_transfer' => 0,
            'tag_rule_id'      => null,
        ];

        // 2. Self transfer — decided before any merchant rule, because a
        // transfer to my own account must never be counted as spending.
        // (Spelled out rather than `$base + [...]`: array union keeps the LEFT
        // side's keys, which would silently preserve is_self_transfer => 0.)
        if ($this->isSelf($n['counterparty'], $n['vpa'])) {
            return [
                'category'         => 'self_transfer',
                'mode'             => $n['mode'],
                'counterparty'     => 'Self',
                'is_self_transfer' => 1,
                'tag_source'       => 'auto',
                'tag_rule_id'      => null,
            ];
        }

        // 3. Rule table. Description rules see the narration with IFSC codes
        // stripped, so a merchant pattern cannot match a bank routing code.
        $merchantText = NarrationParser::merchantText($text);
        foreach ($this->rules as $rule) {
            if ($rule['cashflow'] !== '' && $rule['cashflow'] !== $cashflow) {
                continue;
            }
            $subject = $rule['field'] === 'counterparty' ? ($n['counterparty'] ?? '') : $merchantText;
            if ($subject === '' || !self::matches($subject, (string) $rule['pattern'], (string) $rule['match_type'])) {
                continue;
            }

            $this->hits[(int) $rule['id']] = ($this->hits[(int) $rule['id']] ?? 0) + 1;

            return [
                'category'         => (string) $rule['category'],
                'mode'             => $rule['set_mode'] !== null && $rule['set_mode'] !== '' ? (string) $rule['set_mode'] : $n['mode'],
                'counterparty'     => $n['counterparty'],
                'is_self_transfer' => (int) $rule['is_self_transfer'],
                'tag_source'       => 'rule',
                'tag_rule_id'      => (int) $rule['id'],
            ];
        }

        // 4. MCC (Federal embeds it in the narration)
        $mccCategory = MccMap::category($n['mcc']);
        if ($mccCategory !== null && $cashflow === 'debit') {
            return $base + ['category' => $mccCategory, 'tag_source' => 'auto'];
        }

        // 5. Channel fallback
        $byMode = match ($n['mode']) {
            'ATM'          => 'cash_withdrawal',
            'INTEREST'     => $cashflow === 'credit' ? 'interest_income' : 'fees_charges',
            'CHARGES_FEES' => 'fees_charges',
            default        => null,
        };
        if ($byMode !== null) {
            return $base + ['category' => $byMode, 'tag_source' => 'auto'];
        }

        // 6. Untagged
        return $base + [
            'category'   => $cashflow === 'credit' ? 'other_income' : 'other_expense',
            'tag_source' => 'auto',
        ];
    }

    /**
     * Persist per-rule hit counts accumulated across a tagging run, so the
     * Settings page can show which rules actually earn their keep.
     */
    public function flushHits(): void
    {
        if ($this->hits === []) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE tagging_rules SET hits = hits + ? WHERE id = ?');
        foreach ($this->hits as $id => $count) {
            $stmt->execute([$count, $id]);
        }
        $this->hits = [];
    }

    /**
     * Re-run tagging over a set of staged rows, leaving human decisions alone.
     * Used after the user adds a rule and clicks "apply to similar".
     *
     * @return int rows whose category changed
     */
    public function retagStaged(int $uploadId): int
    {
        $rows = $this->pdo->prepare(
            "SELECT id, description, cashflow, category FROM staged_transactions
             WHERE upload_id = ? AND tag_source != 'manual'"
        );
        $rows->execute([$uploadId]);

        $update = $this->pdo->prepare(
            'UPDATE staged_transactions
                SET category = ?, mode = ?, counterparty = ?, is_self_transfer = ?,
                    tag_source = ?, tag_rule_id = ?, updated_at = datetime(\'now\')
              WHERE id = ?'
        );

        $changed = 0;
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = $this->tag(['description' => (string) $r['description'], 'cashflow' => (string) $r['cashflow']]);
            $update->execute([
                $t['category'], $t['mode'], $t['counterparty'], $t['is_self_transfer'],
                $t['tag_source'], $t['tag_rule_id'], $r['id'],
            ]);
            $changed += $t['category'] !== $r['category'] ? 1 : 0;
        }
        $this->flushHits();

        return $changed;
    }

    // ---------------------------------------------------------------- private

    private function setting(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private static function matches(string $subject, string $pattern, string $type): bool
    {
        $s = strtoupper($subject);
        $p = strtoupper($pattern);

        return match ($type) {
            'equals'   => $s === $p,
            'prefix'   => str_starts_with($s, $p),
            'contains' => str_contains($s, $p),
            'regex'    => @preg_match('/' . str_replace('/', '\/', $pattern) . '/i', $subject) === 1,
            default    => false,
        };
    }

    /**
     * Is this counterparty me?
     *
     * Names are prefix-matched in both directions because banks truncate them
     * at ~20 characters: the stored "NAIR AKHIL VEN" must match the statement's
     * "NAIR AKHIL VENUGOPAL", and vice versa. The reverse direction needs a
     * length floor, or the stored name would match every "NAIR" in the file.
     *
     * VPAs are matched fuzzily (Levenshtein <= 2) because OCR'd statements
     * corrupt them: "akhiló- 169@okhdfcbank" for "akhil6169@okhdfcbank".
     */
    private function isSelf(?string $counterparty, ?string $vpa): bool
    {
        if ($vpa !== null) {
            $needle = self::normalizeVpa($vpa);
            if (strlen($needle) >= 8) {
                foreach ($this->selfVpas as $mine) {
                    if ($needle === $mine || levenshtein($needle, $mine) <= self::VPA_FUZZ) {
                        return true;
                    }
                }
            }
        }

        if ($counterparty === null) {
            return false;
        }
        $name = strtoupper(trim($counterparty));
        foreach ($this->selfNames as $mine) {
            if ($mine === '' || $name === '') {
                continue;
            }
            if ($name === $mine || str_starts_with($name, $mine)) {
                return true;
            }
            // Statement name is a truncation of the stored one.
            if (strlen($name) >= 10 && str_starts_with($mine, $name)) {
                return true;
            }
        }

        return false;
    }

    /** lowercase, drop everything but a-z0-9 and the @ separator */
    private static function normalizeVpa(string $vpa): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9@]/i', '', $vpa));
    }
}
