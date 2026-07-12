<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Money;
use App\Support\TxnHash;
use App\Services\CommitService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Committed ledger view + manual transaction entry.
 *
 *   GET    /api/transactions?year=&month=&account_id=&category=&search=&excluded=&limit=
 *   GET    /api/transactions/export?<same filters>   CSV of exactly what you see
 *   POST   /api/transactions                         manual entry (adjusts balance)
 *   POST   /api/transactions/bulk                    retag / exclude many rows at once
 *   DELETE /api/transactions/{id}                    remove + recompute balance
 *
 * The ledger is a MONTHLY view: `year` + `month` narrow it to one month, and
 * `month=all` widens it to the whole year. Both default to the month of the most
 * recent transaction, because statements are historical — anchoring to the wall
 * clock would usually open on an empty month.
 *
 * Manual entries get a random-salted hash so re-entering an identical cash
 * expense isn't silently swallowed by the idempotency constraint (that guard
 * exists to dedupe re-uploaded statements, not deliberate manual rows).
 */
final class TransactionController
{
    /** Mirrors the category enum in schema.sql and TaggingRuleController. */
    private const CATEGORIES = [
        'salary', 'business_income', 'interest_income', 'dividend', 'refund_cashback', 'other_income',
        'investment', 'epf_employee', 'epf_employer', 'eps_pension', 'epf_interest',
        'emi', 'loan_disbursement', 'credit_card_payment',
        'rent', 'grocery', 'food_dining', 'utility', 'telecom_internet', 'transport_fuel',
        'shopping', 'healthcare', 'insurance', 'education', 'entertainment', 'travel',
        'subscription', 'personal_care', 'charity_gift', 'tax', 'fees_charges',
        'cash_withdrawal', 'self_transfer', 'other_expense',
    ];

    /** @param callable():CommitService $commitFactory */
    public function __construct(private PDO $pdo, private $commitFactory)
    {
    }

    /**
     * POST /api/transactions/bulk
     * Body: {ids: [1,2,3], category?: "grocery", is_excluded?: 0|1}
     *
     * Retagging many rows at once. Neither field is part of the idempotency hash
     * or the balance formula, so nothing has to be rehashed or recomputed —
     * a bulk retag cannot move an account balance.
     */
    public function bulk(Request $request, Response $response): Response
    {
        $b   = (array) $request->getParsedBody();
        $ids = array_values(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), static fn ($i) => $i > 0));

        if ($ids === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Select at least one transaction'], 400);
        }
        if (count($ids) > 2000) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Too many rows in one go (max 2000)'], 400);
        }

        $sets = [];
        $vals = [];

        if (array_key_exists('category', $b)) {
            if (!in_array($b['category'], self::CATEGORIES, true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'Unknown category'], 400);
            }
            $sets[] = 'category = ?';
            $vals[] = $b['category'];
            // A human chose this, so a later auto-retag must not overwrite it.
            $sets[] = "tag_source = 'manual'";
        }
        if (array_key_exists('is_excluded', $b)) {
            $sets[] = 'is_excluded = ?';
            $vals[] = !empty($b['is_excluded']) ? 1 : 0;
        }
        if ($sets === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Nothing to change'], 400);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . " WHERE id IN ({$ph})");
        $stmt->execute([...$vals, ...$ids]);

        return $this->json($response, ['updated' => $stmt->rowCount(), 'categories' => self::CATEGORIES]);
    }

    public function list(Request $request, Response $response): Response
    {
        $q     = $request->getQueryParams();
        $limit = min(2000, max(1, (int) ($q['limit'] ?? 1000)));

        [$where, $params, $applied, $amountInvalid] = $this->filters($q);

        $stmt = $this->pdo->prepare(
            // The loan join is the single seam between the ledger and the loans
            // module: it tells each row whether it already pays an instalment.
            'SELECT t.id, t.txn_date, t.description, t.amount, t.cashflow, t.mode,
                    t.category, t.tag_source, t.is_self_transfer, t.is_excluded, t.source,
                    t.balance_after, t.account_id, a.name AS account_name, a.color AS account_color,
                    lp.loan_id, lp.period_no AS loan_period, l.name AS loan_name,
                    ic.investment_id, iv.name AS investment_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN loan_payments lp ON lp.txn_id = t.id
             LEFT JOIN loans l ON l.id = lp.loan_id
             LEFT JOIN investment_contributions ic ON ic.txn_id = t.id
             LEFT JOIN investments iv ON iv.id = ic.investment_id
             ' . $where . '
             ORDER BY t.txn_date DESC, t.id DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Totals are computed over the WHOLE filtered set, not the page of rows
        // returned, so a truncated list still reports honest sums.
        $sums = $this->pdo->prepare(
            "SELECT COUNT(*) txns,
                    COALESCE(SUM(CASE WHEN t.cashflow = 'credit' THEN t.amount END), 0) income,
                    COALESCE(SUM(CASE WHEN t.cashflow = 'debit'  THEN t.amount END), 0) expense,
                    COALESCE(SUM(t.is_excluded), 0) excluded_txns
             FROM transactions t JOIN accounts a ON a.id = t.account_id " . $where
        );
        $sums->execute($params);
        $totals = array_map('intval', $sums->fetch(PDO::FETCH_ASSOC));
        $totals['net'] = $totals['income'] - $totals['expense'];

        return $this->json($response, [
            'transactions' => $rows,
            'truncated'    => count($rows) >= $limit,
            'totals'       => $totals,
            'applied'      => $applied,
            // Deliberately outside `applied`: the client echoes `applied` straight
            // back as the next query string, and this is a verdict, not a filter.
            'amount_invalid' => $amountInvalid,
            'filters'        => $this->filterOptions(),
        ]);
    }

    /**
     * Build the WHERE clause shared by list() and export(), so the CSV can never
     * contain rows the screen doesn't show.
     *
     * @param array<string,mixed> $q
     * @return array{0:string, 1:list<mixed>, 2:array<string,mixed>}
     */
    private function filters(array $q): array
    {
        $latest = (string) ($this->pdo->query('SELECT MAX(txn_date) FROM transactions')->fetchColumn() ?: date('Y-m-d'));

        $year  = (string) ($q['year'] ?? substr($latest, 0, 4));
        $month = (string) ($q['month'] ?? substr($latest, 5, 2));

        $clauses = [];
        $params  = [];

        if ($year !== 'all' && preg_match('/^\d{4}$/', $year) === 1) {
            // An out-of-range month must widen to the whole year, not silently
            // build "2026-13" and return an empty ledger the user cannot explain.
            $valid = preg_match('/^\d{1,2}$/', $month) === 1 && (int) $month >= 1 && (int) $month <= 12;
            if (!$valid) {
                $month = 'all';
                $clauses[] = "strftime('%Y', t.txn_date) = ?";
                $params[]  = $year;
            } else {
                $month = str_pad($month, 2, '0', STR_PAD_LEFT);
                $clauses[] = "strftime('%Y-%m', t.txn_date) = ?";
                $params[]  = "{$year}-{$month}";
            }
        } else {
            $year = 'all';
            $month = 'all';
        }

        $account = (int) ($q['account_id'] ?? 0);
        if ($account > 0) {
            $clauses[] = 't.account_id = ?';
            $params[]  = $account;
        }

        // One tag or many: `category=grocery` and `category=grocery,fuel` both work.
        // Duplicates and blanks are dropped, and the list is echoed back in the
        // order it was applied, so the client can re-send `applied` unchanged.
        $category = implode(',', array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($q['category'] ?? ''))),
            static fn (string $c): bool => $c !== '',
        ))));
        if ($category !== '') {
            $tags      = explode(',', $category);
            $clauses[] = 't.category IN (' . implode(',', array_fill(0, count($tags), '?')) . ')';
            $params    = [...$params, ...$tags];
        }

        // Anything else means "both", and must be echoed back as "both": `applied`
        // is what the query actually did, and the client re-sends it verbatim.
        $cashflow = (string) ($q['cashflow'] ?? '');
        if (!in_array($cashflow, ['credit', 'debit'], true)) {
            $cashflow = '';
        }
        if ($cashflow !== '') {
            $clauses[] = 't.cashflow = ?';
            $params[]  = $cashflow;
        }

        // `excluded=1` narrows to the rows you have blacklisted, so they stay
        // findable months after you hid them from the analysis.
        $excluded = (string) ($q['excluded'] ?? '');
        if ($excluded === '1') {
            $clauses[] = 't.is_excluded = 1';
        }

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $clauses[] = '(t.description LIKE ? OR t.counterparty LIKE ?)';
            $params[]  = '%' . $search . '%';
            $params[]  = '%' . $search . '%';
        }

        // A half-typed filter ("1000-") must not be applied as if it meant
        // something, and must not 400 a search-as-you-type box either. It is
        // reported back so the UI can say so, and no rows are hidden.
        $amount        = trim((string) ($q['amount'] ?? ''));
        $amountInvalid = false;
        if ($amount !== '') {
            $parsed = $this->amountFilter($amount);
            if ($parsed === null) {
                $amountInvalid = true;
            } else {
                $clauses[] = $parsed[0];
                $params    = [...$params, ...$parsed[1]];
            }
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [$where, $params, [
            'year' => $year, 'month' => $month, 'account_id' => $account ?: null,
            'category' => $category, 'cashflow' => $cashflow, 'search' => $search,
            'amount' => $amount,
            'excluded' => $excluded === '1' ? '1' : '',
        ], $amountInvalid];
    }

    /**
     * The amount box. Accepts an exact amount, a comparison, or a range:
     *
     *   4999        exactly ₹4,999.00        >1000   above ₹1,000
     *   =4999       the same                 >=1000  ₹1,000 or more
     *   1000-2000   between, inclusive       <500    under ₹500
     *
     * `transactions.amount` is a magnitude — the sign lives in `cashflow` — so
     * this compares magnitudes: `>1000` finds a ₹1,000 credit and a ₹1,000
     * debit alike. Narrow with the cashflow filter if you want only one.
     *
     * Rupees in, paise out, via Money::parse: `4999.57 * 100` is 499956.99…
     * in binary floating point, and would miss the row it was meant to find.
     *
     * @return array{0:string,1:list<int>}|null [sql, params], or null if unreadable
     */
    private function amountFilter(string $raw): ?array
    {
        // Indian digit grouping, a stray rupee sign, and spaces around the operator.
        $s = str_replace([',', ' ', '₹'], '', $raw);
        $n = '(\d+(?:\.\d{1,2})?)';

        if (preg_match("/^(>=|<=|>|<|=)?{$n}$/", $s, $m) === 1) {
            $p = Money::parse($m[2]);
            if ($p === null) {
                return null;
            }

            return match ($m[1]) {
                '>'     => ['t.amount > ?',  [$p]],
                '>='    => ['t.amount >= ?', [$p]],
                '<'     => ['t.amount < ?',  [$p]],
                '<='    => ['t.amount <= ?', [$p]],
                default => ['t.amount = ?',  [$p]],
            };
        }

        if (preg_match("/^{$n}-{$n}$/", $s, $m) === 1) {
            $lo = Money::parse($m[1]);
            $hi = Money::parse($m[2]);
            if ($lo === null || $hi === null) {
                return null;
            }
            if ($lo > $hi) {
                [$lo, $hi] = [$hi, $lo];   // "2000-1000" is a typo, not an empty ledger
            }

            return ['t.amount BETWEEN ? AND ?', [$lo, $hi]];
        }

        return null;
    }

    /** Only offer years and tags the ledger actually contains. */
    private function filterOptions(): array
    {
        return [
            'years' => $this->pdo->query(
                "SELECT DISTINCT strftime('%Y', txn_date) y FROM transactions ORDER BY y DESC"
            )->fetchAll(PDO::FETCH_COLUMN),
            'categories' => $this->pdo->query(
                'SELECT category, COUNT(*) n FROM transactions GROUP BY category ORDER BY n DESC'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function create(Request $request, Response $response): Response
    {
        $b         = (array) $request->getParsedBody();
        $accountId = (int) ($b['account_id'] ?? 0);
        $date      = (string) ($b['txn_date'] ?? '');
        $desc      = trim((string) ($b['description'] ?? ''));
        $cashflow  = (string) ($b['cashflow'] ?? '');
        $amount    = (float) ($b['amount'] ?? 0);

        if ($accountId <= 0 || $date === '' || $desc === ''
            || !in_array($cashflow, ['credit', 'debit'], true) || $amount <= 0) {
            return $this->json($response, [
                'error'   => 'validation',
                'message' => 'account_id, txn_date, description, cashflow (credit|debit) and a positive amount are required',
            ], 400);
        }
        if ($this->pdo->query("SELECT 1 FROM accounts WHERE id = {$accountId} AND is_archived = 0")->fetchColumn() === false) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Unknown account'], 400);
        }

        $amountPaise = (int) round($amount * 100);
        // Salt keeps deliberate duplicate manual rows distinct from each other.
        $hash = TxnHash::make(
            $accountId, $date, $amountPaise, $cashflow,
            $desc . '|manual|' . bin2hex(random_bytes(6))
        );

        $this->pdo->prepare(
            'INSERT INTO transactions
                (account_id, txn_hash, txn_date, description, amount, cashflow, mode,
                 category, is_self_transfer, source)
             VALUES (?,?,?,?,?,?,?,?,?, "manual")'
        )->execute([
            $accountId, $hash, $date, $desc, $amountPaise, $cashflow,
            (string) ($b['mode'] ?? 'OTHER'),
            (string) ($b['category'] ?? ($cashflow === 'credit' ? 'other_income' : 'other_expense')),
            !empty($b['is_self_transfer']) ? 1 : 0,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $balance = ($this->commitFactory)()->recomputeBalance($accountId);

        return $this->json($response, ['created' => true, 'id' => $id, 'account_balance' => $balance], 201);
    }

    /**
     * PATCH /api/transactions/{id} — edit a committed row. Recomputes the
     * account balance (and the txn_hash from the new values) afterwards.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id  = (int) $args['id'];
        $b   = (array) $request->getParsedBody();

        $row = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $row->execute([$id]);
        $txn = $row->fetch(PDO::FETCH_ASSOC);
        if ($txn === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        $sets = [];
        $vals = [];
        foreach (['txn_date', 'description', 'mode', 'category', 'counterparty'] as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $vals[] = $b[$f] === '' ? null : (string) $b[$f];
            }
        }
        if (array_key_exists('amount', $b)) {
            $amt = (float) $b['amount'];
            if ($amt <= 0) {
                return $this->json($response, ['error' => 'validation', 'message' => 'amount must be positive'], 400);
            }
            $sets[] = 'amount = ?';
            $vals[] = (int) round($amt * 100);
        }
        if (array_key_exists('cashflow', $b)) {
            if (!in_array($b['cashflow'], ['credit', 'debit'], true)) {
                return $this->json($response, ['error' => 'validation', 'message' => 'cashflow must be credit|debit'], 400);
            }
            $sets[] = 'cashflow = ?';
            $vals[] = $b['cashflow'];
        }
        if (array_key_exists('is_self_transfer', $b)) {
            $sets[] = 'is_self_transfer = ?';
            $vals[] = !empty($b['is_self_transfer']) ? 1 : 0;
        }
        // Blacklist one row: it stays in the ledger and in the balance, but
        // stops counting as income or expense anywhere in the app.
        if (array_key_exists('is_excluded', $b)) {
            $sets[] = 'is_excluded = ?';
            $vals[] = !empty($b['is_excluded']) ? 1 : 0;
        }
        if ($sets === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'No editable fields'], 400);
        }

        $vals[] = $id;
        $this->pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

        // Refresh the idempotency hash from current values (keeps re-upload dedupe honest).
        $t = array_merge($txn, $b);
        $hash = TxnHash::make(
            (int) $txn['account_id'],
            (string) $t['txn_date'],
            (int) round(((float) ($b['amount'] ?? $txn['amount'] / 100)) * 100),
            (string) $t['cashflow'],
            (string) ($txn['raw_description'] ?? $t['description'] ?? ''),
            (string) ($txn['reference_id'] ?? '')
        );
        $this->pdo->prepare('UPDATE transactions SET txn_hash = ? WHERE id = ?')->execute([$hash, $id]);

        $balance = ($this->commitFactory)()->recomputeBalance((int) $txn['account_id']);

        return $this->json($response, ['updated' => true, 'account_balance' => $balance]);
    }

    /**
     * GET /api/transactions/export?<the same filters as list()>
     * Exports exactly the rows currently on screen — same WHERE clause, no limit.
     */
    public function export(Request $request, Response $response): Response
    {
        [$where, $params, $applied] = $this->filters($request->getQueryParams());

        $stmt = $this->pdo->prepare(
            'SELECT t.txn_date, a.name AS account, t.description, t.category, t.mode,
                    t.cashflow, t.amount, t.is_self_transfer, t.is_excluded, t.source
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             ' . $where . ' ORDER BY t.txn_date, t.id'
        );
        $stmt->execute($params);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['date', 'account', 'description', 'category', 'mode', 'cashflow', 'amount_inr',
                       'self_transfer', 'excluded_from_analysis', 'source']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            fputcsv($out, [
                $r['txn_date'], $r['account'], $r['description'], $r['category'], $r['mode'],
                $r['cashflow'], number_format($r['amount'] / 100, 2, '.', ''),
                $r['is_self_transfer'] ? 'yes' : 'no', $r['is_excluded'] ? 'yes' : 'no', $r['source'],
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $scope = $applied['year'] === 'all' ? 'all'
            : ($applied['month'] === 'all' ? $applied['year'] : $applied['year'] . '-' . $applied['month']);
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="ledger_' . $scope . '.csv"');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $accountId = (int) ($this->pdo->query("SELECT account_id FROM transactions WHERE id = {$id}")->fetchColumn() ?: 0);
        if ($accountId === 0) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        $this->pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
        $balance = ($this->commitFactory)()->recomputeBalance($accountId);

        return $this->json($response, ['deleted' => true, 'account_balance' => $balance]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
