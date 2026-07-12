<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Palette;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Minimal accounts API — enough for the upload portal (pick an account) and
 * the bank-profiles settings page (map profile -> account). Balances, debt
 * details and manual entries land in the accounts milestone.
 */
final class AccountController
{
    private const TYPES = ['savings', 'current', 'credit_card', 'loan', 'epfo',
                           'investment', 'cash', 'wallet', 'fd_rd', 'other'];

    public function __construct(private PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        // LEFT JOIN debt_details so liability accounts carry their interest/EMI.
        // is_derived flags a loan account: its balance is owned by the amortisation
        // engine, so it must not be offered as an import target.
        $rows = $this->pdo->query(
            'SELECT a.id, a.name, a.type, a.institution, a.is_liability, a.current_balance,
                    a.opening_balance, a.include_in_networth, a.is_derived, a.color,
                    d.interest_rate_apr, d.emi_amount, d.emi_day_of_month, d.credit_limit
             FROM accounts a LEFT JOIN debt_details d ON d.account_id = a.id
             WHERE a.is_archived = 0 ORDER BY a.is_liability, a.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['accounts' => $rows, 'palette' => Palette::ACCOUNTS]);
    }

    /**
     * GET /api/accounts/health — does each account's ledger add up to its statement?
     *
     * Reports only. `implied_opening` says what the oldest row implies the opening
     * balance should be, but nothing here changes it: an opening balance no
     * statement implies is perfectly legitimate.
     */
    public function health(Request $request, Response $response): Response
    {
        $all = (new \App\Services\AccountHealth($this->pdo))->all();

        return $this->json($response, [
            'accounts' => $all,
            'problems' => array_values(array_filter(
                $all,
                static fn ($h) => !$h['reconciled'] || $h['opening_drift'] !== 0
            )),
        ]);
    }

    /**
     * PATCH /api/accounts/{id} — edit account fields and (for liabilities)
     * upsert debt_details: interest_rate_apr, emi_amount (₹), emi_day_of_month,
     * credit_limit (₹).
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $body = (array) $request->getParsedBody();

        $acct = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $acct->execute([$id]);
        $account = $acct->fetch(PDO::FETCH_ASSOC);
        if ($account === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        $sets = [];
        $vals = [];
        if (array_key_exists('name', $body) && trim((string) $body['name']) !== '') {
            $sets[] = 'name = ?';
            $vals[] = trim((string) $body['name']);
        }
        if (array_key_exists('institution', $body)) {
            $sets[] = 'institution = ?';
            $vals[] = trim((string) $body['institution']) ?: null;
        }
        if (array_key_exists('include_in_networth', $body)) {
            $sets[] = 'include_in_networth = ?';
            $vals[] = !empty($body['include_in_networth']) ? 1 : 0;
        }
        if (array_key_exists('opening_balance', $body)) {
            $sets[] = 'opening_balance = ?';
            $vals[] = (int) round(((float) $body['opening_balance']) * 100);
        }
        // Every account has a colour: bin/migrate.php back-fills any NULL one, and
        // it runs on every request. "Clear the colour" is therefore not a state
        // that can exist — refuse it rather than accept a write the next page load
        // would silently undo.
        if (array_key_exists('color', $body)) {
            $color = trim((string) $body['color']);
            if (!Palette::isValid($color)) {
                return $this->json($response, ['error' => 'validation',
                    'message' => $color === ''
                        ? 'An account always has a colour — pick one.'
                        : 'color must be a #rrggbb hex value.'], 400);
            }
            $sets[] = 'color = ?';
            $vals[] = strtolower($color);
        }
        if ($sets !== []) {
            $sets[] = "updated_at = datetime('now')";
            $vals[] = $id;
            $this->pdo->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }

        // Debt details for liability accounts.
        if ($account['is_liability'] && array_intersect_key($body,
            array_flip(['interest_rate_apr', 'emi_amount', 'emi_day_of_month', 'credit_limit', 'principal_amount']))) {
            $this->upsertDebt($id, $body);
        }

        // Opening balance change shifts every derived balance — recompute.
        if (array_key_exists('opening_balance', $body)) {
            $this->recomputeBalance($id);
        }

        return $this->json($response, ['updated' => true]);
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare("UPDATE accounts SET is_archived = 1, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([(int) $args['id']]);

        return $this->json($response, ['archived' => $stmt->rowCount() > 0]);
    }

    public function unarchive(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare("UPDATE accounts SET is_archived = 0, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([(int) $args['id']]);

        return $this->json($response, ['unarchived' => $stmt->rowCount() > 0]);
    }

    /** GET /api/accounts/archived — for the "show archived" toggle in settings. */
    public function listArchived(Request $request, Response $response): Response
    {
        $rows = $this->pdo->query(
            'SELECT id, name, type, institution, is_liability, current_balance
             FROM accounts WHERE is_archived = 1 ORDER BY name'
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, ['accounts' => $rows, 'palette' => Palette::ACCOUNTS]);
    }

    /** @param array<string,mixed> $b */
    private function upsertDebt(int $accountId, array $b): void
    {
        $existing = $this->pdo->prepare('SELECT 1 FROM debt_details WHERE account_id = ?');
        $existing->execute([$accountId]);
        if ($existing->fetchColumn() === false) {
            $this->pdo->prepare('INSERT INTO debt_details (account_id) VALUES (?)')->execute([$accountId]);
        }

        $map = [
            'interest_rate_apr' => fn ($v) => (float) $v,
            'emi_amount'        => fn ($v) => (int) round(((float) $v) * 100),
            'emi_day_of_month'  => fn ($v) => max(1, min(31, (int) $v)),
            'credit_limit'      => fn ($v) => (int) round(((float) $v) * 100),
            'principal_amount'  => fn ($v) => (int) round(((float) $v) * 100),
        ];
        $sets = [];
        $vals = [];
        foreach ($map as $field => $cast) {
            if (array_key_exists($field, $b) && $b[$field] !== '') {
                $sets[] = "$field = ?";
                $vals[] = $cast($b[$field]);
            }
        }
        if ($sets !== []) {
            $vals[] = $accountId;
            $this->pdo->prepare('UPDATE debt_details SET ' . implode(', ', $sets) . ' WHERE account_id = ?')->execute($vals);
        }
    }

    /** Mirror of CommitService::recomputeBalance for opening-balance edits. */
    private function recomputeBalance(int $accountId): void
    {
        $liab = (int) $this->pdo->query("SELECT is_liability FROM accounts WHERE id = {$accountId}")->fetchColumn() === 1;
        $open = (int) $this->pdo->query("SELECT opening_balance FROM accounts WHERE id = {$accountId}")->fetchColumn();
        $r = $this->pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN cashflow='credit' THEN amount END),0) cr,
                    COALESCE(SUM(CASE WHEN cashflow='debit'  THEN amount END),0) dr
             FROM transactions WHERE account_id = {$accountId}"
        )->fetch(PDO::FETCH_ASSOC);
        $bal = $liab ? $open + (int) $r['dr'] - (int) $r['cr'] : $open + (int) $r['cr'] - (int) $r['dr'];
        $this->pdo->prepare('UPDATE accounts SET current_balance = ? WHERE id = ?')->execute([$bal, $accountId]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['type'] ?? '');

        if ($name === '' || !in_array($type, self::TYPES, true)) {
            return $this->json($response, [
                'error'   => 'validation',
                'message' => 'name and a valid type (' . implode('|', self::TYPES) . ') are required',
            ], 400);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM accounts WHERE name = ?');
        $exists->execute([$name]);
        if ($exists->fetchColumn() !== false) {
            return $this->json($response, ['error' => 'duplicate', 'message' => "Account '{$name}' already exists"], 409);
        }

        // An explicit colour, else the first palette hue nobody is wearing.
        $color = trim((string) ($body['color'] ?? ''));
        if ($color !== '' && !Palette::isValid($color)) {
            return $this->json($response, ['error' => 'validation',
                'message' => 'color must be a #rrggbb hex value.'], 400);
        }
        if ($color === '') {
            $taken = $this->pdo->query('SELECT color FROM accounts WHERE color IS NOT NULL')
                ->fetchAll(PDO::FETCH_COLUMN);
            $color = Palette::next($taken);
        }

        $this->pdo->prepare(
            'INSERT INTO accounts (name, type, institution, is_liability, color) VALUES (?,?,?,?,?)'
        )->execute([
            $name,
            $type,
            trim((string) ($body['institution'] ?? '')) ?: null,
            in_array($type, ['credit_card', 'loan'], true) ? 1 : (int) !empty($body['is_liability']),
            strtolower($color),
        ]);

        return $this->json($response, ['created' => true, 'id' => (int) $this->pdo->lastInsertId()], 201);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
