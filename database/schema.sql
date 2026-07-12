-- ============================================================================
-- Personal Finance Tracker — SQLite Schema
-- ----------------------------------------------------------------------------
-- Conventions:
--   * All monetary values are stored in PAISE (INTEGER). ₹1,234.56 => 123456.
--     This avoids floating-point drift entirely. Convert at the UI boundary.
--   * All dates are ISO-8601 TEXT: 'YYYY-MM-DD' (dates), 'YYYY-MM-DD HH:MM:SS' (timestamps, UTC).
--   * Booleans are INTEGER 0/1.
--   * Idempotency: transactions.txn_hash is UNIQUE. Hash formula (computed in PHP,
--     src/Support/TxnHash.php):
--       sha256(account_id | txn_date | amount_paise | cashflow | normalized(raw_description) | reference_id)
--     where normalized() = lowercase, collapse whitespace, strip punctuation.
--     Re-uploading the same statement therefore inserts zero duplicate rows.
-- ============================================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ----------------------------------------------------------------------------
-- ACCOUNTS: every asset or liability bucket (bank, EPFO, credit card, loan...)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT    NOT NULL UNIQUE,              -- "HDFC Salary", "Emergency Fund", "EPFO"
    type                TEXT    NOT NULL CHECK (type IN
                          ('savings','current','credit_card','loan','epfo',
                           'investment','cash','wallet','fd_rd','other')),
    institution         TEXT,                                 -- "HDFC Bank", "EPFO", "Zerodha"
    account_number_mask TEXT,                                 -- "XXXX1234" (never store full number)
    currency            TEXT    NOT NULL DEFAULT 'INR',
    opening_balance     INTEGER NOT NULL DEFAULT 0,           -- paise
    current_balance     INTEGER NOT NULL DEFAULT 0,           -- paise; maintained by app on commit
    -- '#rrggbb'. Identity only: it tints dots and rules, never text or numbers,
    -- so a dark pick can never make a balance unreadable on the dark theme.
    color               TEXT,
    is_liability        INTEGER NOT NULL DEFAULT 0,           -- 1 => credit card / loan (counts negative in net worth)
    include_in_networth INTEGER NOT NULL DEFAULT 1,
    is_archived         INTEGER NOT NULL DEFAULT 0,
    -- 1 => current_balance is computed by another module (the loans amortisation
    -- engine), not by summing this account's ledger rows. CommitService must not
    -- recompute it, and the import account picker must not offer it.
    is_derived          INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- DEBT DETAILS: extra fields for liability accounts.
--
-- Superseded for instalment debt by the `loans` module below; retained for
-- credit cards, whose only real extra field is `credit_limit`.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS debt_details (
    account_id          INTEGER PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
    principal_amount    INTEGER NOT NULL DEFAULT 0,           -- original principal, paise
    interest_rate_apr   REAL    NOT NULL DEFAULT 0,           -- annual %, e.g. 8.75
    emi_amount          INTEGER,                              -- paise
    emi_day_of_month    INTEGER CHECK (emi_day_of_month BETWEEN 1 AND 31),
    tenure_months       INTEGER,
    start_date          TEXT,                                 -- YYYY-MM-DD
    credit_limit        INTEGER                               -- paise; credit cards only
);

-- ============================================================================
-- LOANS — an event-sourced instalment-debt module, independent of the ledger.
-- ----------------------------------------------------------------------------
-- The payment schedule is NEVER stored. It is recomputed from (loan + events)
-- on every read by src/Services/Loan/AmortisationEngine.php, exactly as account
-- balances are recomputed rather than incremented. A rate change in month 40 is
-- therefore one row, not a rewrite of 200 — and there is no stale schedule to
-- invalidate.
--
-- Each loan owns exactly one liability `accounts` row (accounts.is_derived = 1).
-- LoanService::sync() writes the outstanding PRINCIPAL into its current_balance,
-- so v_net_worth, v_net_worth_history, the debt ladder and the snapshot job all
-- keep working untouched, and double-counting is structurally impossible.
--
-- Outstanding principal = the closing balance after the last instalment due
-- BEFORE the 1st of the current month. Everything from this month on is owed.
-- Future interest is deliberately NOT a liability: you only owe it if you keep
-- the loan alive. It is reported separately as `remaining_interest`.
-- ============================================================================
CREATE TABLE IF NOT EXISTS loans (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id          INTEGER UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
    name                TEXT    NOT NULL,
    lender              TEXT,
    loan_type           TEXT    NOT NULL DEFAULT 'other' CHECK (loan_type IN
                          ('home','personal','auto','education','gold','business','other')),
    principal           INTEGER NOT NULL,                     -- paise, SANCTIONED (a ceiling)
    start_date          TEXT    NOT NULL,                     -- YYYY-MM-DD, first disbursal
    first_emi_date      TEXT    NOT NULL,                     -- YYYY-MM-DD, full EMI begins
    tenure_months       INTEGER NOT NULL,                     -- counted from first_emi_date
    interest_rate_apr   REAL    NOT NULL,                     -- opening rate, annual %
    emi_amount          INTEGER,                              -- paise; NULL => derive from tenure
    -- An under-construction property is released in tranches. Until the last one
    -- lands you pay interest on the drawn amount only, and no principal:
    --   'pay'        the bank debits it monthly (the usual)
    --   'capitalise' it is added to the outstanding instead
    pre_emi_mode        TEXT    NOT NULL DEFAULT 'pay' CHECK (pre_emi_mode IN ('pay','capitalise')),
    -- Section 24(b): interest paid before possession is NOT deductible in the
    -- year it is paid. It is aggregated and claimed in five equal annual
    -- instalments starting the financial year of possession. Without this date
    -- that split cannot be computed, so the tax view falls back to "as paid".
    possession_date     TEXT,                                 -- YYYY-MM-DD
    is_closed           INTEGER NOT NULL DEFAULT 0,
    notes               TEXT,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Everything that changes a loan's arithmetic partway through its life.
--   disbursement amount: a tranche the bank released. Interest accrues only on
--                money actually drawn. mode applies only to a tranche landing
--                AFTER the EMI has started: 'keep_emi' (tenure floats) or
--                'keep_tenure' (EMI is recomputed).
--   rate_change  rate_apr + mode: 'keep_emi' (default; tenure floats, as banks
--                do) or 'keep_tenure' (EMI is recomputed to close on time)
--   emi_change   emi_amount; from effective_date onward this is the instalment
--   prepayment   amount + mode: 'reduce_tenure' (default; EMI holds, loan ends
--                earlier) or 'reduce_emi' (end date holds, EMI drops)
--
-- A loan with NO disbursement event behaves exactly as before: the whole
-- `principal` is treated as drawn on `start_date`. Nothing to migrate.
--
-- A prepayment lands immediately AFTER the EMI of the month it falls in, because
-- a monthly-rest loan only recalculates at its rest date. A disbursement lands
-- BEFORE it, so that month's interest is charged on the new, larger balance.
CREATE TABLE IF NOT EXISTS loan_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id         INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
    event_type      TEXT    NOT NULL CHECK (event_type IN
                      ('disbursement','rate_change','emi_change','prepayment')),
    effective_date  TEXT    NOT NULL,                         -- YYYY-MM-DD
    rate_apr        REAL,                                     -- rate_change
    emi_amount      INTEGER,                                  -- emi_change, paise
    amount          INTEGER,                                  -- prepayment | disbursement, paise
    mode            TEXT,                                     -- see above
    note            TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_loan_events ON loan_events(loan_id, effective_date);

-- The ONE seam between loans and the ledger. Every instalment starts unpaid;
-- it becomes paid only when a real transaction is linked to it, so "paid" always
-- means "the money left your account", never "the calendar says so".
--
-- `transactions` gains no columns for this, so nothing is rehashed and no
-- balance moves. Deleting a transaction cascades its link away.
--
-- UNIQUE(txn_id) stops one debit from paying two loans; SQLite treats NULLs as
-- distinct in a UNIQUE index, which is exactly right here — it lets any number
-- of manual (non-ledger) payments coexist with txn_id IS NULL.
CREATE TABLE IF NOT EXISTS loan_payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id     INTEGER NOT NULL REFERENCES loans(id) ON DELETE CASCADE,
    period_no   INTEGER NOT NULL,                             -- 1-based instalment number
    txn_id      INTEGER REFERENCES transactions(id) ON DELETE CASCADE,
    paid_on     TEXT    NOT NULL,                             -- YYYY-MM-DD
    amount      INTEGER NOT NULL,                             -- paise actually paid
    source      TEXT    NOT NULL DEFAULT 'ledger' CHECK (source IN ('ledger','manual')),
    note        TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (loan_id, period_no),
    UNIQUE (txn_id)
);
CREATE INDEX IF NOT EXISTS idx_loan_payments_txn ON loan_payments(txn_id);

-- ----------------------------------------------------------------------------
-- INVESTMENTS. Same shape as loans, mirrored to the asset side:
--   * Each holding OWNS one derived asset account (accounts.is_derived = 1,
--     is_liability = 0). Its current_balance is the latest valuation, so
--     v_net_worth, the dashboard and the net-worth chart update with no change.
--   * A holding's value is NEVER derived from a live price — this app makes no
--     external calls. You record dated valuations by hand; the latest is "now".
--   * XIRR runs on the events: buys/contributions are money out, sells/
--     withdrawals/dividends are money in, and the current value is a final
--     inflow dated today. That is the true money-weighted annual return.
--
-- No double counting: buying a fund is a debit from a bank account (that balance
-- drops) and the holding's value is a SEPARATE derived asset (it rises). The
-- `investment` category is already excluded from expense analytics, so a tagged
-- contribution never lands in spending either.
CREATE TABLE IF NOT EXISTS investments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id          INTEGER UNIQUE REFERENCES accounts(id) ON DELETE CASCADE,
    name                TEXT    NOT NULL,
    instrument_type     TEXT    NOT NULL DEFAULT 'other' CHECK (instrument_type IN
                          ('equity','mutual_fund','gold','fd_rd','bond','ppf_epf',
                           'crypto','real_estate','nps','insurance','other')),
    platform            TEXT,                                 -- "Zerodha", "Groww", "SGB", ...
    -- Annual %, the rate projections use. NULL => fall back to the type default
    -- in InvestmentService::DEFAULT_RATES. Realised XIRR is offered in the UI as
    -- a one-click way to fill this in.
    expected_return_apr REAL,
    is_closed           INTEGER NOT NULL DEFAULT 0,           -- fully sold / redeemed
    notes               TEXT,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- The dated cashflows. `units`/`price` are optional bookkeeping; `amount` (paise)
-- is what XIRR uses, and its sign is DERIVED from event_type, never stored:
--   buy | contribution   -> money out of pocket (negative in the XIRR series)
--   sell | withdrawal | dividend -> money back (positive)
CREATE TABLE IF NOT EXISTS investment_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    investment_id   INTEGER NOT NULL REFERENCES investments(id) ON DELETE CASCADE,
    event_type      TEXT    NOT NULL CHECK (event_type IN
                      ('buy','contribution','sell','withdrawal','dividend')),
    event_date      TEXT    NOT NULL,                         -- YYYY-MM-DD
    amount          INTEGER NOT NULL,                         -- paise, always POSITIVE magnitude
    units           REAL,                                     -- optional
    price           INTEGER,                                  -- optional, paise per unit
    note            TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_investment_events ON investment_events(investment_id, event_date);

-- Mark-to-market. The row with the greatest valued_on is the current value, and
-- also what sync() writes into the derived account. Entered by hand (by total
-- value, or units x a price you type).
CREATE TABLE IF NOT EXISTS investment_valuations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    investment_id   INTEGER NOT NULL REFERENCES investments(id) ON DELETE CASCADE,
    valued_on       TEXT    NOT NULL,                         -- YYYY-MM-DD
    value           INTEGER NOT NULL,                         -- paise, total worth on that date
    note            TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (investment_id, valued_on)                         -- one mark per day; re-valuing replaces it
);
CREATE INDEX IF NOT EXISTS idx_investment_valuations ON investment_valuations(investment_id, valued_on);

-- The ONE seam with the ledger, identical in spirit to loan_payments: tag an
-- `investment` debit and link it here as a contribution. `transactions` gains no
-- column, so nothing rehashes and no balance moves. A linked contribution is
-- also mirrored into investment_events so it counts in XIRR; deleting the txn
-- cascades both away. UNIQUE(txn_id) stops one debit funding two holdings.
CREATE TABLE IF NOT EXISTS investment_contributions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    investment_id   INTEGER NOT NULL REFERENCES investments(id) ON DELETE CASCADE,
    txn_id          INTEGER REFERENCES transactions(id) ON DELETE CASCADE,
    event_id        INTEGER REFERENCES investment_events(id) ON DELETE CASCADE,
    contributed_on  TEXT    NOT NULL,                         -- YYYY-MM-DD
    amount          INTEGER NOT NULL,                         -- paise
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (txn_id)
);
CREATE INDEX IF NOT EXISTS idx_investment_contributions_txn ON investment_contributions(txn_id);

-- ----------------------------------------------------------------------------
-- BANK FORMATS: a saved CSV column mapping, keyed by a fingerprint of the
-- statement's header row. Recognising a fingerprint lets an upload skip the
-- mapping screen entirely; an unknown one opens the mapper and is saved here.
--
-- Columns are referenced by NAME, not position, so a bank adding, removing or
-- reordering columns does not break an existing format.
--
-- `mapping` is the JSON document validated by src/Services/Csv/ColumnMapping.php:
--   {
--     "header_row": 0,
--     "date":        {"column": "Date", "format": "d/m/y"},
--     "description": {"columns": ["Narration"]},
--     "amount":      {"mode": "debit_credit",     -- debit_credit | signed | indicator
--                     "debit": "Debit Amount", "credit": "Credit Amount",
--                     "amount": null, "indicator": null, "debit_values": ["DR"]},
--     "balance":     {"column": "Closing Balance"},
--     "reference":   {"column": "Chq/Ref Number"},
--     "skip_rows":   [{"column": "Narration", "regex": "^(opening|closing) balance"}],
--     "clean_ocr":   false
--   }
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bank_formats (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL UNIQUE,             -- "HDFC Savings", "Kotak 811"
    fingerprint  TEXT    NOT NULL UNIQUE,             -- sha256 of the normalized header cells
    header_line  TEXT    NOT NULL,                    -- the raw header, for display
    delimiter    TEXT    NOT NULL DEFAULT ',',
    account_id   INTEGER REFERENCES accounts(id) ON DELETE SET NULL,  -- default account for this format
    mapping      TEXT    NOT NULL,                    -- JSON, see above
    use_count    INTEGER NOT NULL DEFAULT 0,
    last_used_at TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- UPLOADS: one row per statement CSV.
--   mapping   -> awaiting column mapping / confirmation in the UI
--   review    -> rows are staged, awaiting human review
--   committed -> promoted into `transactions`
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uploads (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id      INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
    bank_format_id  INTEGER REFERENCES bank_formats(id) ON DELETE SET NULL,
    original_name   TEXT    NOT NULL,
    stored_path     TEXT    NOT NULL,
    mime_type       TEXT    NOT NULL,
    file_sha256     TEXT    NOT NULL UNIQUE,          -- file-level dedupe
    status          TEXT    NOT NULL DEFAULT 'mapping' CHECK (status IN
                      ('mapping','review','committed','failed','rejected')),
    column_mapping  TEXT,                             -- JSON mapping actually applied
    row_count       INTEGER,                          -- data rows parsed
    parse_warnings  TEXT,                             -- JSON array of strings
    error_message   TEXT,
    uploaded_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    parsed_at       TEXT,
    committed_at    TEXT
);

-- ----------------------------------------------------------------------------
-- STAGED TRANSACTIONS: parsed + auto-tagged rows land here first
-- (human-in-the-loop review). Every field is editable in the Review UI.
-- On approval rows are copied into `transactions`; UNIQUE txn_hash there
-- silently drops duplicates.
--
-- tag_source: how `category` was decided —
--   'rule'   matched a row in tagging_rules
--   'auto'   derived from narration mode / MCC / self-identity
--   'manual' a human set it; a re-tag pass must never overwrite this
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS staged_transactions (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    upload_id           INTEGER NOT NULL REFERENCES uploads(id) ON DELETE CASCADE,
    account_id          INTEGER REFERENCES accounts(id) ON DELETE SET NULL,
    txn_hash            TEXT    NOT NULL,                     -- precomputed; flags dupes in the UI
    txn_date            TEXT    NOT NULL,                     -- YYYY-MM-DD
    description         TEXT    NOT NULL,                     -- cleaned narration (editable)
    raw_description     TEXT,                                 -- untouched narration from the statement
    amount              INTEGER NOT NULL,                     -- paise, always positive
    cashflow            TEXT    NOT NULL CHECK (cashflow IN ('credit','debit')),
    mode                TEXT    NOT NULL DEFAULT 'OTHER',     -- UPI/NEFT/RTGS/IMPS/ATM/POS/...
    category            TEXT    NOT NULL DEFAULT 'other_expense',
    tag_source          TEXT    NOT NULL DEFAULT 'auto' CHECK (tag_source IN ('rule','auto','manual')),
    tag_rule_id         INTEGER REFERENCES tagging_rules(id) ON DELETE SET NULL,
    is_self_transfer    INTEGER NOT NULL DEFAULT 0,
    counterparty        TEXT,
    reference_id        TEXT,
    balance_after       INTEGER,                              -- paise; running balance from the statement
    is_duplicate        INTEGER NOT NULL DEFAULT 0,           -- txn_hash already exists in `transactions`
    review_status       TEXT    NOT NULL DEFAULT 'pending' CHECK (review_status IN
                          ('pending','approved','edited','rejected')),
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_staged_upload  ON staged_transactions(upload_id);
CREATE INDEX IF NOT EXISTS idx_staged_hash    ON staged_transactions(txn_hash);

-- ----------------------------------------------------------------------------
-- TRANSACTIONS: the committed, immutable-ish ledger
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id          INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    upload_id           INTEGER REFERENCES uploads(id) ON DELETE SET NULL,  -- NULL => manual entry
    txn_hash            TEXT    NOT NULL UNIQUE,              -- << idempotency guard
    txn_date            TEXT    NOT NULL,
    description         TEXT    NOT NULL,
    raw_description     TEXT,
    amount              INTEGER NOT NULL CHECK (amount >= 0), -- paise; sign comes from cashflow
    cashflow            TEXT    NOT NULL CHECK (cashflow IN ('credit','debit')),
    mode                TEXT    NOT NULL DEFAULT 'OTHER',
    category            TEXT    NOT NULL DEFAULT 'other_expense',
    tag_source          TEXT    NOT NULL DEFAULT 'auto' CHECK (tag_source IN ('rule','auto','manual')),
    is_self_transfer    INTEGER NOT NULL DEFAULT 0,           -- 1 => excluded from income/expense analytics
    is_excluded         INTEGER NOT NULL DEFAULT 0,           -- 1 => this ONE row doesn't count as income/expense
    transfer_group_id   TEXT,                                 -- links both legs of a self-transfer (uuid)
    counterparty        TEXT,
    reference_id        TEXT,
    balance_after       INTEGER,
    source              TEXT    NOT NULL DEFAULT 'import' CHECK (source IN ('import','manual')),
    notes               TEXT,
    created_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_txn_account_date ON transactions(account_id, txn_date);
CREATE INDEX IF NOT EXISTS idx_txn_date         ON transactions(txn_date);
CREATE INDEX IF NOT EXISTS idx_txn_category     ON transactions(category);
CREATE INDEX IF NOT EXISTS idx_txn_selftransfer ON transactions(is_self_transfer);
CREATE INDEX IF NOT EXISTS idx_txn_excluded     ON transactions(is_excluded);

-- ----------------------------------------------------------------------------
-- TAGGING RULES: the rule-based replacement for AI tagging.
-- Evaluated in order: priority DESC, then longest pattern first (so
-- "CRED CLUB" beats "CRED"). The first match wins.
--
--   field       which text to test: description (raw narration) or counterparty
--   match_type  contains | prefix | equals | regex   (all case-insensitive)
--   cashflow    '' = either; else only apply to credit or debit rows.
--               ('' rather than NULL: SQLite treats NULLs as distinct in a
--               UNIQUE index, which would let INSERT OR IGNORE re-seed dupes.)
--   category    the category to assign
--   set_mode    optional override for `mode`
--   is_self_transfer  1 => also flag the row as a self transfer
--   source      'seed' (shipped default) or 'user' (added from the Review UI)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tagging_rules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern          TEXT    NOT NULL,
    field            TEXT    NOT NULL DEFAULT 'description' CHECK (field IN ('description','counterparty')),
    match_type       TEXT    NOT NULL DEFAULT 'contains' CHECK (match_type IN ('contains','prefix','equals','regex')),
    cashflow         TEXT    NOT NULL DEFAULT '' CHECK (cashflow IN ('','credit','debit')),
    category         TEXT    NOT NULL,
    set_mode         TEXT,
    is_self_transfer INTEGER NOT NULL DEFAULT 0,
    priority         INTEGER NOT NULL DEFAULT 0,
    enabled          INTEGER NOT NULL DEFAULT 1,
    hits             INTEGER NOT NULL DEFAULT 0,          -- bumped each time the rule tags a row
    source           TEXT    NOT NULL DEFAULT 'user' CHECK (source IN ('seed','user')),
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (field, match_type, pattern, cashflow)
);
CREATE INDEX IF NOT EXISTS idx_rules_enabled ON tagging_rules(enabled, priority DESC);

-- ----------------------------------------------------------------------------
-- BALANCE SNAPSHOTS: per-account end-of-day balances.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS balance_snapshots (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id      INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    snapshot_date   TEXT    NOT NULL,                         -- YYYY-MM-DD
    balance         INTEGER NOT NULL,                         -- paise (positive even for liabilities)
    UNIQUE (account_id, snapshot_date)
);
CREATE INDEX IF NOT EXISTS idx_snapshot_date ON balance_snapshots(snapshot_date);

-- ----------------------------------------------------------------------------
-- MILESTONES: gamified ladder achievements (₹10,000 steps)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS milestones (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    kind            TEXT    NOT NULL CHECK (kind IN ('net_worth','debt_paydown')),
    amount          INTEGER NOT NULL,                         -- paise; e.g. 1000000 = ₹10,000 step
    achieved_on     TEXT    NOT NULL,                         -- YYYY-MM-DD
    UNIQUE (kind, amount)
);

-- ----------------------------------------------------------------------------
-- REMINDERS: user-configured recurring nudges pushed to Telegram
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT    NOT NULL,
    message         TEXT,
    schedule_type   TEXT    NOT NULL CHECK (schedule_type IN ('monthly','weekly','daily','once')),
    day_of_month    INTEGER CHECK (day_of_month BETWEEN 1 AND 31),
    day_of_week     INTEGER CHECK (day_of_week BETWEEN 0 AND 6),
    time_of_day     TEXT    NOT NULL DEFAULT '09:00',
    next_run_at     TEXT,
    last_run_at     TEXT,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- BUDGETS: monthly spending limits. One overall budget (category = '') plus
-- optional per-category budgets. The daily allowance is derived, not stored.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS budgets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    category    TEXT    NOT NULL DEFAULT '' UNIQUE,   -- '' = overall monthly budget
    amount      INTEGER NOT NULL,                     -- paise per month
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- EXCLUDED CATEGORIES: tags that must not count as income or expense anywhere
-- in the app — analytics, budgets, dashboard averages, MoM, insights.
--
-- Account balances are NOT affected, and cannot be: recomputeBalance() sums
-- every committed row regardless of category (opening + credits - debits), so a
-- statement always reconciles to its own closing balance. Excluding a tag hides
-- it from *analysis*, never from the ledger or from what the bank says you have.
--
-- Every query that measures spending or income joins against this table rather
-- than hardcoding a list, so a change here takes effect everywhere at once.
--
-- There are two levels of exclusion, both honoured by Exclusions::SQL:
--   * this table            -- a whole tag never counts
--   * transactions.is_excluded -- one specific row never counts
-- ----------------------------------------------------------------------------
-- The defaults are seeded ONCE (see the SEED DATA section). schema.sql runs on
-- every request, so an unconditional INSERT OR IGNORE here would silently
-- re-exclude a tag the moment the user un-excluded it.
CREATE TABLE IF NOT EXISTS excluded_categories (
    category   TEXT PRIMARY KEY,
    note       TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- EVENT LOG: application activity trail, surfaced on the Log page.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ts         TEXT    NOT NULL DEFAULT (datetime('now')),   -- UTC
    level      TEXT    NOT NULL DEFAULT 'info' CHECK (level IN ('info','success','warning','error')),
    category   TEXT    NOT NULL,                              -- upload | csv_parse | tagging | ingest | commit | cron | telegram
    event      TEXT    NOT NULL,
    upload_id  INTEGER,
    message    TEXT,
    context    TEXT                                           -- optional JSON detail
);
CREATE INDEX IF NOT EXISTS idx_event_log_id ON event_log(id DESC);

-- ----------------------------------------------------------------------------
-- NOTIFICATION LOG: audit of everything sent to Telegram
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    kind        TEXT    NOT NULL CHECK (kind IN ('daily_summary','reminder','alert')),
    reminder_id INTEGER REFERENCES reminders(id) ON DELETE SET NULL,
    payload     TEXT,
    status      TEXT    NOT NULL DEFAULT 'sent' CHECK (status IN ('sent','failed')),
    error       TEXT,
    sent_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ----------------------------------------------------------------------------
-- SETTINGS: simple key/value store
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT,
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ============================================================================
-- VIEWS — analytics primitives the API layer reads from
-- ============================================================================

-- Current net worth: assets minus liabilities (paise)
CREATE VIEW IF NOT EXISTS v_net_worth AS
SELECT
    SUM(CASE WHEN is_liability = 0 THEN current_balance ELSE 0 END)             AS total_assets,
    SUM(CASE WHEN is_liability = 1 THEN current_balance ELSE 0 END)             AS total_liabilities,
    SUM(CASE WHEN is_liability = 0 THEN current_balance ELSE -current_balance END) AS net_worth
FROM accounts
WHERE include_in_networth = 1 AND is_archived = 0;

-- Daily net worth history (from snapshots) — feeds the ladder + trend chart
CREATE VIEW IF NOT EXISTS v_net_worth_history AS
SELECT
    s.snapshot_date,
    SUM(CASE WHEN a.is_liability = 0 THEN s.balance ELSE -s.balance END) AS net_worth,
    SUM(CASE WHEN a.is_liability = 0 THEN s.balance ELSE 0 END)          AS total_assets,
    SUM(CASE WHEN a.is_liability = 1 THEN s.balance ELSE 0 END)          AS total_liabilities
FROM balance_snapshots s
JOIN accounts a ON a.id = s.account_id
WHERE a.include_in_networth = 1
GROUP BY s.snapshot_date;

-- Monthly cashflow, self-transfers and excluded tags removed — feeds MoM cards.
-- Recreated on every migration so an edit to excluded_categories, or to this
-- definition, takes effect without a manual DROP.
DROP VIEW IF EXISTS v_monthly_cashflow;
CREATE VIEW v_monthly_cashflow AS
SELECT
    strftime('%Y-%m', txn_date)                                        AS month,
    SUM(CASE WHEN cashflow = 'credit' THEN amount ELSE 0 END)          AS total_income,
    SUM(CASE WHEN cashflow = 'debit'  THEN amount ELSE 0 END)          AS total_expense,
    COUNT(*)                                                           AS txn_count
FROM transactions
WHERE is_self_transfer = 0
  AND is_excluded = 0
  AND category NOT IN (SELECT category FROM excluded_categories)
GROUP BY strftime('%Y-%m', txn_date);

-- Daily expense totals — feeds the "Daily Average Expense" card
DROP VIEW IF EXISTS v_daily_expense;
CREATE VIEW v_daily_expense AS
SELECT
    txn_date,
    SUM(amount) AS total_expense
FROM transactions
WHERE cashflow = 'debit'
  AND is_self_transfer = 0
  AND is_excluded = 0
  AND category NOT IN (SELECT category FROM excluded_categories)
GROUP BY txn_date;

-- ============================================================================
-- SEED DATA
-- ============================================================================
-- One-shot defaults for excluded_categories. Guarded by a settings flag rather
-- than INSERT OR IGNORE, so removing one of these in Settings makes it stay
-- removed across the next request (schema.sql is applied on every boot).
-- OR IGNORE as well as the flag: the flag stops a removed default from coming
-- back, and OR IGNORE keeps this safe on a database that already has the rows
-- but predates the flag.
INSERT OR IGNORE INTO excluded_categories (category, note)
SELECT c, n FROM (
    SELECT 'investment'          AS c, 'Money moved into investments is still yours, not spent' AS n
    UNION ALL SELECT 'epf_employee',        'Provident fund contributions are savings, not spending'
    UNION ALL SELECT 'epf_employer',        'Employer PF contribution is not take-home income'
    UNION ALL SELECT 'eps_pension',         'Pension contribution is not take-home income'
    UNION ALL SELECT 'credit_card_payment', 'The card statement carries the real spending; counting the bill too would double-count it'
    UNION ALL SELECT 'loan_disbursement',   'Borrowed money is a liability, not income'
)
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE key = 'exclusions_seeded');

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('exclusions_seeded',       '1'),
    ('telegram_chat_id',        ''),
    ('daily_summary_time',      '21:00'),
    ('app_timezone',            'Asia/Kolkata'),
    ('ladder_step_paise',       '1000000'),   -- ₹10,000 per milestone step
    -- Self-identity: names are PREFIX-matched (banks truncate at ~20 chars, so
    -- "NAIR AKHIL VEN" covers "NAIR AKHIL VENUGOPAL"). VPAs are fuzzy-matched
    -- (Levenshtein <= 2) because OCR'd statements corrupt characters.
    ('self_identity_names',     'NAIR AKHIL VEN|AKHIL NAIR'),
    ('self_identity_vpas',      'akhil6169@okhdfcbank|akhil6169-3@okaxis');

-- ---------------------------------------------------------------------------
-- SEED TAGGING RULES
-- INSERT OR IGNORE + UNIQUE(field,match_type,pattern,cashflow) makes this
-- idempotent: re-running migrate never resurrects a rule the user disabled or
-- re-tuned. Higher priority wins; within a priority, the longest pattern wins.
-- ---------------------------------------------------------------------------

-- Self-transfers are NOT a rule: TaggingEngine matches the narration's
-- counterparty/VPA against the self_identity_* settings before rules run, so
-- there is exactly one place that decides "this is me paying myself".

-- Retired seeds. Short `contains` patterns match inside unrelated words:
-- "OLA " hit "BHOLA JAISWAL" (a person, tagged as a cab ride) and "TDS" hit
-- "BUNDLTECHNOLOGIESPVTLTDSWIGGY" (a Swiggy order, tagged as tax). They are
-- re-seeded below as word-boundary regexes. Only `source='seed'` rows are
-- touched, so rules the user wrote are never removed.
DELETE FROM tagging_rules WHERE source = 'seed' AND match_type = 'contains'
  AND pattern IN ('RENT', 'OLA ', 'SIP ', 'GST', 'TDS', 'JIO', 'PVR', 'OYO', 'UBER');

-- priority 80: credit-card bill payments, EMIs, taxes — misclassifying these
-- as spending would double-count expenses already captured on the card ledger.
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    ('CRED CLUB',       'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('CREDCLUB',        'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('CRED.CLUB',       'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('CREDIT CARD',     'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('CC PAYMENT',      'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('EMI',             'description', 'prefix',   'debit',  'emi',                 80, 'seed'),
    ('LOAN EMI',        'description', 'contains', 'debit',  'emi',                 80, 'seed'),
    ('BAJAJ FINANCE',   'description', 'contains', 'debit',  'emi',                 80, 'seed'),
    ('HDFCLTD',         'description', 'contains', 'debit',  'emi',                 80, 'seed'),
    ('AUTOPAY SI-TAD',  'description', 'contains', 'debit',  'credit_card_payment', 80, 'seed'),
    ('INCOME TAX',      'description', 'contains', 'debit',  'tax',                 80, 'seed');

-- priority 80 (regex): a bill payment whose target is a masked card number is a
-- credit-card payment, whatever the biller code says. Matches HDFC's
-- "IB BILLPAY DR-HDFC9R-652925XXXXXX1619" and "CC 000463917XXXXXX1912 AUTOPAY".
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    ('^IB ?BILLPAY.*X{4,}', 'description', 'regex', 'debit', 'credit_card_payment', 80, 'seed'),
    ('^CC\s+[0-9X]{8,}',    'description', 'regex', 'debit', 'credit_card_payment', 80, 'seed');

-- priority 70: income
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    ('SALARY',          'description', 'contains', 'credit', 'salary',           70, 'seed'),
    ('SAL CREDIT',      'description', 'contains', 'credit', 'salary',           70, 'seed'),
    ('INTEREST PAID',   'description', 'contains', 'credit', 'interest_income',  70, 'seed'),
    ('INT.PD',          'description', 'contains', 'credit', 'interest_income',  70, 'seed'),
    ('SBINT',           'description', 'contains', 'credit', 'interest_income',  70, 'seed'),
    ('DIVIDEND',        'description', 'contains', 'credit', 'dividend',         70, 'seed'),
    ('CASHBACK',        'description', 'contains', 'credit', 'refund_cashback',  70, 'seed'),
    ('REFUND',          'description', 'contains', 'credit', 'refund_cashback',  70, 'seed'),
    ('UPIRET',          'description', 'prefix',   'credit', 'refund_cashback',  70, 'seed');

-- priority 60: bank charges / fees (debit only — "AMC" etc. never mean spending)
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    ('CHRG',            'description', 'contains', 'debit', 'fees_charges', 60, 'seed'),
    ('CHARGES',         'description', 'contains', 'debit', 'fees_charges', 60, 'seed'),
    ('DEBIT CARD AMC',  'description', 'contains', 'debit', 'fees_charges', 60, 'seed'),
    ('ANNUAL FEE',      'description', 'contains', 'debit', 'fees_charges', 60, 'seed'),
    ('PENALTY',         'description', 'contains', 'debit', 'fees_charges', 60, 'seed');

-- priority 50: merchants, grouped by category. Longest-match-first inside a
-- priority means e.g. "GOOGLE PLAY" beats "GOOGLE".
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    -- grocery
    ('KAMAL FRESH MART',   'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('BLINKIT',            'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('JIOMART',            'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('ZEPTO',              'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('BIGBASKET',          'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('DMART',              'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('RELIANCE FRESH',     'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('KAIRALI BAZAR',      'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('SUPERMARKET',        'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('KIRANA',             'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    ('INSTAMART',          'description', 'contains', 'debit', 'grocery', 50, 'seed'),
    -- food & dining
    ('SWIGGY',             'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('CREDPAYSWIGGY',      'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('ZOMATO',             'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('DOMINOS',            'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('MCDONALD',           'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('STARBUCKS',          'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('CAFE',               'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('RESTAURANT',        'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('ANTARA FAST FOOD',   'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('ROYAL SWEET',        'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('BAKERY',             'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    ('HOTEL',              'description', 'contains', 'debit', 'food_dining', 50, 'seed'),
    -- subscription / entertainment
    ('NETFLIX',            'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('GOOGLE PLAY',        'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('AMAZON PRIME',       'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('SPOTIFY',            'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('YOUTUBEPREMIUM',     'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('HOTSTAR',            'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('JIOCINEMA',          'description', 'contains', 'debit', 'subscription',  50, 'seed'),
    ('GOOGLE INDIA DIGITAL','description','contains', 'debit', 'subscription',  50, 'seed'),
    ('BIGTREE ENTERTAINMEN','description','contains', 'debit', 'entertainment', 50, 'seed'),
    ('BOOKMYSHOW',         'description', 'contains', 'debit', 'entertainment', 50, 'seed'),
    ('VALVE CORPORATION',  'description', 'contains', 'debit', 'entertainment', 50, 'seed'),
    ('STEAMGAMES',         'description', 'contains', 'debit', 'entertainment', 50, 'seed'),
    -- shopping
    ('AMAZON PAY',         'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('AMAZONPA',           'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('FLIPKART',           'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('MYNTRA',             'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('AJIO',               'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('MEESHO',             'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('DECATHLON',          'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    ('IKEA',               'description', 'contains', 'debit', 'shopping', 50, 'seed'),
    -- healthcare
    ('TATA 1MG',           'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('1MG',                'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('PHARMEASY',          'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('APOLLO',             'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('CHEMIST',            'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('PHARMACY',           'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('MEDICAL',            'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('HOSPITAL',           'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('DIAGNOSTIC',         'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    ('DR ANIRUDDHAS',      'description', 'contains', 'debit', 'healthcare', 50, 'seed'),
    -- transport & fuel
    ('RAPIDO',             'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('IRCTC',              'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('INDIAN OIL',         'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('BHARAT PETRO',       'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('HP PETROL',          'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('FASTAG',             'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('PETROL',             'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('IOCL',               'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('BPCL',               'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    ('HPCL',               'description', 'contains', 'debit', 'transport_fuel', 50, 'seed'),
    -- travel
    ('MAKEMYTRIP',         'description', 'contains', 'debit', 'travel', 50, 'seed'),
    ('GOIBIBO',            'description', 'contains', 'debit', 'travel', 50, 'seed'),
    ('CLEARTRIP',          'description', 'contains', 'debit', 'travel', 50, 'seed'),
    ('INDIGO',             'description', 'contains', 'debit', 'travel', 50, 'seed'),
    ('AIRBNB',             'description', 'contains', 'debit', 'travel', 50, 'seed'),
    -- utilities / telecom
    ('ELECTRICITY',        'description', 'contains', 'debit', 'utility',          50, 'seed'),
    ('MSEDCL',             'description', 'contains', 'debit', 'utility',          50, 'seed'),
    ('GAS BILL',           'description', 'contains', 'debit', 'utility',          50, 'seed'),
    ('WATER BILL',         'description', 'contains', 'debit', 'utility',          50, 'seed'),
    ('BILLDESKTEZ',        'description', 'contains', 'debit', 'utility',          50, 'seed'),
    ('AIRTEL',             'description', 'contains', 'debit', 'telecom_internet', 50, 'seed'),
    ('VODAFONE',           'description', 'contains', 'debit', 'telecom_internet', 50, 'seed'),
    ('BROADBAND',          'description', 'contains', 'debit', 'telecom_internet', 50, 'seed'),
    ('ACT FIBERNET',       'description', 'contains', 'debit', 'telecom_internet', 50, 'seed'),
    -- investment / insurance / rent
    ('ZERODHA',            'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('GROWW',              'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('UPSTOX',             'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('INDIAN CLEARING',    'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('FYERS',              'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('MUTUAL FUND',        'description', 'contains', 'debit', 'investment', 50, 'seed'),
    ('LIC OF INDIA',       'description', 'contains', 'debit', 'insurance',  50, 'seed'),
    ('INSURANCE',          'description', 'contains', 'debit', 'insurance',  50, 'seed'),
    ('POLICYBAZAAR',       'description', 'contains', 'debit', 'insurance',  50, 'seed');

-- Word-boundary regexes. These patterns are short enough to appear inside
-- unrelated words, so `contains` is unsafe for them:
--   OLA  -> "BH(OLA) JAISWAL"                 TDS -> "BUNDLTECHNOLOGIESPVTL(TDS)WIGGY"
--   RENT -> "CUR(RENT)"                       JIO -> "A(JIO)"
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, priority, source) VALUES
    ('\bTDS\b',        'description', 'regex', 'debit', 'tax',              80, 'seed'),
    ('\b[ICS]?GST\b',  'description', 'regex', 'debit', 'fees_charges',     80, 'seed'),
    ('\bRENT\b',       'description', 'regex', 'debit', 'rent',             50, 'seed'),
    ('\bSIP\b',        'description', 'regex', 'debit', 'investment',       50, 'seed'),
    ('\bOLA\b',        'description', 'regex', 'debit', 'transport_fuel',   50, 'seed'),
    ('\bUBER\b',       'description', 'regex', 'debit', 'transport_fuel',   50, 'seed'),
    ('\bJIO\b',        'description', 'regex', 'debit', 'telecom_internet', 50, 'seed'),
    ('\bPVR\b',        'description', 'regex', 'debit', 'entertainment',    50, 'seed'),
    ('\bOYO\b',        'description', 'regex', 'debit', 'travel',           50, 'seed');

-- priority 20: channel fallbacks from the narration prefix. These fire only if
-- no merchant rule matched, so a rent payment by NEFT still tags as `rent`.
INSERT OR IGNORE INTO tagging_rules (pattern, field, match_type, cashflow, category, set_mode, priority, source) VALUES
    ('ATW',   'description', 'prefix', 'debit', 'cash_withdrawal', 'ATM',  20, 'seed'),
    ('NWD',   'description', 'prefix', 'debit', 'cash_withdrawal', 'ATM',  20, 'seed'),
    ('ATM',   'description', 'prefix', 'debit', 'cash_withdrawal', 'ATM',  20, 'seed'),
    ('EAW',   'description', 'prefix', 'debit', 'cash_withdrawal', 'ATM',  20, 'seed'),
    ('CASH WITHDRAWAL', 'description', 'contains', 'debit', 'cash_withdrawal', 'ATM', 20, 'seed');
