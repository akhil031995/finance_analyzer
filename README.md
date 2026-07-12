# Finance Analyzer

Self-hosted personal finance tracking & insights: CSV statement import with rule-based auto-tagging, a gamified net-worth ladder, debt tracking, and daily Telegram summaries.

**Stack:** PHP 8.3 (Slim 4) · SQLite · Vanilla JS + Tailwind · Docker. No AI, no external API calls — statements are parsed entirely on your machine.

## Layout

| Path | Purpose |
|---|---|
| `database/schema.sql` | Full SQLite schema (accounts, saved CSV layouts, tagging rules, staged + committed ledger, snapshots, milestones, reminders, analytics views) |
| `src/Services/Csv/` | The import pipeline: `CsvFile` (encoding/delimiter/header sniffing) → `MappingSuggester` → `ColumnMapping` → `CsvStatementParser` |
| `src/Services/Tagging/` | Rule-based tagger: `NarrationParser` (mode/counterparty/VPA/MCC) → `TaggingEngine` → `MccMap` |
| `src/Services/Loan/` | `AmortisationEngine` (pure: no DB, no clock) + `LoanService` (schedule, payments, sync, simulator) |
| `src/Services/ImportService.php` | Orchestrates preview → validate (dry run) → stage |
| `src/Support/` | `Money` (paise), `DateFormatGuesser` (strict, ambiguity-aware), `TxnHash` (idempotency) |
| `bin/migrate.php` | Idempotent schema migration, runs on every boot |
| `bin/parse_test.php` | Offline harness: parse + tag every CSV in `bank_statements/` and report balance checks and `% others`. Writes nothing. |
| `bin/reset.php` | One-shot wipe of the ledger (keeps accounts, budgets, settings, reminders) |
| `bin/cron.php` | Scheduler daemon for the `cron` container (snapshots, Telegram summary, reminders, nightly loan re-sync) |

## Run it

```sh
cp .env.example .env     # Telegram creds only; no API keys needed
./bin/deps.sh            # installs vendor/ via dockerized composer (see note below)
docker compose up --build -d
curl http://localhost:8899/health
```

> **Why `bin/deps.sh`?** This LAN's router answers DNS from the Docker bridge
> too slowly for Composer's 10s timeout, and BuildKit RUN steps can't override
> their read-only resolv.conf — so dependencies are installed in a `docker run`
> container (which accepts `--dns`) and the image build COPYs `vendor/` in,
> fully offline. Re-run it whenever `composer.json` changes.
> The SQLite DB is a local bind mount at `./data/finance.sqlite`; both
> containers run as uid 1000 so the files stay owned by your user.

**Dev mode is on by default:** the whole project is bind-mounted into the
containers, so PHP/HTML/JS edits apply on browser refresh — no rebuild or
restart. Rebuild only for Dockerfile/php.ini changes; restart the `cron`
container to pick up `bin/cron.php`.

## Importing a statement

Any bank, any column layout. Nothing is written until you confirm.

1. **`POST /api/imports`** (multipart `statement`, optional `account_id`) stores the file and
   returns a preview: the raw grid, plus either a **recognised layout** or a suggested mapping.
2. You tag each column in the UI — date, description, debit, credit, balance, reference.
3. **`POST /api/imports/{id}/validate`** is a dry run. It parses, tags, and reports what *would*
   be imported. It writes nothing.
4. **`POST /api/imports/{id}/stage`** fills the review queue and, optionally, remembers the layout.
5. Review and edit the rows, then **`POST /api/uploads/{id}/commit`** promotes them to the ledger.

### Layouts survive column changes

A saved layout is keyed by a fingerprint of the **sorted, normalized header names**, and its
mapping addresses columns **by name, never by position**. So:

| The bank… | What happens |
|---|---|
| sends the same file again | exact fingerprint match — zero clicks |
| reorders its columns | still an exact match (order isn't part of the key) |
| adds or removes a column you don't use | *compatible* match — the mapping still applies, zero clicks |
| renames a column you **do** use | falls back to the mapping screen, pre-filled by the suggester |

### The balance check

Every bank prints a running balance, which makes an integrity check free:
`balance[i] == balance[i-1] ± amount[i]`. Before you import, the dry run walks the rows
chronologically (Kotak exports newest-first; the order is detected) and asserts this holds.

The direction is taken from the **account**, not inferred from the data — on an asset account a
credit raises the balance, on a credit card a purchase does. That matters: if you swap the debit and
credit columns, every delta flips sign and the *opposite* convention fits perfectly. A self-tuning
check would report "all good" while inverting your entire ledger. Instead we test both and name the
failure: *"the running balance reconciles only if debit and credit are swapped."*

### Parsing gotchas handled

- **The `Dr/Cr` column usually describes the balance, not the transaction.** On Federal and Kotak
  statements it reads `CR` on *every* row, including withdrawals. Direction comes from the
  debit/credit column pair; the indicator style is never auto-selected.
- **Blank vs zero.** HDFC and Federal write `0` in the unused amount column; Kotak leaves it blank.
- **Reversals are a negative amount in their original column.** HDFC prints `-695.56` under
  *Withdrawal* when it refunds a debit. That is a **credit**; reading it as a debit gets the balance
  wrong by twice the amount. Only a leading `-` or `(` counts — `Money::parse("900 Dr")` also returns
  a negative, and that is an ordinary debit.
- **Ambiguous dates.** `02/01/26` is either 2 Jan or 1 Feb. Formats are validated by round-trip
  (`createFromFormat('d/m/Y', '02/01/26')` silently yields year 0026), and if no value in the column
  has a day > 12, the UI asks rather than guessing.
- **Unquoted delimiters.** A real HDFC row contains `ACH C- CESC LIMITED,-160412` — a comma inside
  the narration, which shifts every later column. Over-wide rows are repaired by folding the surplus
  back into the description.
- **OCR'd exports.** Federal's CSV is converted from a PDF and scars long tokens
  (`akhiló- 169@okhdfcbank`). Detected and repaired; UPI handles are matched fuzzily.
- **Trailing commas, BOMs, Windows-1252, preamble rows, opening-balance rows.**

## Analytics

`GET /api/analytics?type=month|year|fy&anchor=<period>&account_id=<id>` — one report per period.
`anchor` is `YYYY-MM` for a month, `YYYY` for a calendar year, and the **starting** year for a
financial year (`2025` ⇒ FY 2025-26, Apr 2025 – Mar 2026).

The **monthly** view picks its period with two dropdowns, Year then Month, because a ledger of any
age turns one combined list into a scroll (91 entries here). Year and Financial Year keep the single
list — there are only a handful. The month list offers **only months the ledger holds**, so a year
that starts in February never offers January and then reports an empty period. Changing the year
keeps the month you were on when that year has it (flicking through Junes is the point) and otherwise
falls back to that year's newest month, so the anchor sent to the API is always one that exists.

With no `anchor`, a month report defaults to the last month that **finished**, not the newest month
with a row: a ledger ending on 1 July would otherwise open on a one-day July and collapse every
average.

The **Salary progression** card (year and financial-year views only) plots every payslip the ledger
holds, shading the selected period and totalling it. Three things the real data forces:

- A month with **no salary credit is a gap, not a zero** — the line breaks rather than plunging to
  the axis and implying you were not paid. Gaps are marked with a dashed rule.
- **A bonus is not a raise.** A hike is recorded only when the new level is *sustained*: the median
  of the next six paid months must clear the median of the previous six by ≥3%. A three-month window
  is not enough — this ledger contains a ₹2,07,378 bonus month and a ₹65,998 part-month, and either
  one moves a three-month median. The part-month is the nastier case: it drags the *before* median
  down and invents a "+51% raise" out of the normal salary simply resuming.
- Every headline is a **median, never a mean**, for the same reason. "Salary now" is the median of
  the last three payslips.

Three definitions drive every number on the page:

- **Expense** excludes self-transfers, investments/EPF (that money is still yours) and credit-card
  bill payments (the card's own statement carries the real spending; counting the bill too would
  double-count it). Card payments are reported separately so the money is never simply missing.
- **Commitments** — EMI, rent, insurance, subscriptions, utilities, telecom — are what you cannot
  skip next month. The rest is discretionary. That split is what makes "you spent ₹1.3L" actionable.
- **EMI** is tracked as its own line everywhere, with an EMI-burden ratio against income (lenders
  treat >40% as stressed).

Two things the page gets right that are easy to get wrong:

- **Partial periods.** Statements are historical. Defaulting the month view to the calendar month of
  the newest transaction shows a one-day month and every average collapses, so the default is the
  last month that actually *finished*. Averages divide by days the ledger covers, not days until
  today. And when a period is partial, the previous period is truncated to the **same window** —
  otherwise 9 days of July always looks like a 90% collapse in spending against all of June.
- **Recurring detection** runs over the 12 months ending where the *ledger* ends, not where the
  period nominally ends. Anchoring it to 31 March of a financial year would make every live
  subscription look months overdue and drop all of them.

Recurring payments need 3+ payments to the same counterparty, a consistent gap (weekly →
yearly), and a stable amount (median absolute deviation under 20% of the median). Anomalies are
transactions ≥ 4× the **median** for their own category — the median, not the mean, because one
₹80,000 outlier drags a mean up until nothing looks unusual any more.

The year and FY views add a full-width month-by-month card: one row per month, income / expense /
EMI bars on a shared scale, with net on the right.

## Ledger

`GET /api/transactions?year=&month=&account_id=&category=&cashflow=&search=&amount=&limit=`

A **monthly** view. `year` + `month` scope it to one month; `month=all` widens to the year,
`year=all` to everything. Both default to the month of the most recent transaction — statements are
historical, so anchoring to the wall clock would usually open on an empty month. An out-of-range
month widens to the whole year rather than silently matching `2026-13` and returning nothing.

`category` takes one tag or several: `category=grocery` or `category=grocery,fuel`. Many tags are a
**union** (`IN (…)`), never an intersection — a row carries exactly one tag, so an intersection would
always be empty. Blanks and duplicates are dropped and the cleaned list is echoed back in `applied`,
so a one-tag filter is byte-identical to what the old single select sent and every saved deep link
and export URL keeps working. In the filter bar it is a checkbox menu; clicking a tag on a ledger row
adds it, and clicking it again removes it.

`amount` takes an exact figure, a comparison, or a range — `4999`, `>1000`, `>=1000`, `<500`,
`<=500`, `1000-2000` (inclusive; a reversed range is read as a typo, not as an empty ledger). A
rupee sign, Indian digit grouping and spaces around the operator are all tolerated. Rupees become
paise through `Money::parse`, never `× 100`: `4999.57 * 100` is `499956.99…` in binary floating
point and would miss the very row it was meant to find.

`transactions.amount` is a **magnitude** — the sign lives in `cashflow` — so `>1000` matches a
₹1,000 credit and a ₹1,000 debit alike. `cashflow=credit|debit` ("Money in" / "Money out" in the
filter bar) narrows to one side; anything else means both, and is echoed back as both. Totals follow
the filter, so a debit-only view reports zero income and a negative net.

Because the box is search-as-you-type, a half-finished filter like `1000-` must neither 400 nor
quietly match nothing. It is **ignored**, every row is shown, and the response carries
`amount_invalid: true` so the UI can say so. That flag sits *outside* `applied`, which the client
echoes straight back as the next query string: it is a verdict, not a filter.

`POST /api/transactions/bulk` `{ids:[…], category?, is_excluded?}` retags or blacklists many rows at
once. Neither field is part of the idempotency hash or the balance formula, so a bulk retag rehashes
nothing and cannot move an account balance. A bulk retag pins `tag_source = 'manual'`, so a later
auto-retag will not overwrite it.

`GET /api/transactions/export` accepts **the same filters** and reuses the same `WHERE` clause, so
the CSV can never contain rows the screen doesn't show.

Totals (in / out / net) are computed over the whole filtered set, not the page of rows returned, so
a truncated list still reports honest sums. The running-balance column only appears when a single
account is selected — a balance interleaved across accounts means nothing.

## Loans

A separate module from the ledger. **The payment schedule is never stored** — it is recomputed from
`loans` + `loan_events` on every read by `AmortisationEngine`, exactly as account balances are
recomputed rather than incremented. A rate change in month 40 is therefore one row, not a rewrite of
200, and there is no stale schedule to invalidate.

Monthly reducing balance, the convention every Indian bank uses:

```
r         = APR / 12 / 100
interest  = round(outstanding × r)
principal = emi − interest
outstanding -= principal
```

Four kinds of event change a loan's arithmetic partway through:

| Event | Default | Alternative |
|---|---|---|
| `disbursement` | a tranche the bank released; `keep_emi` if it lands mid-loan | `keep_tenure` — EMI is re-solved |
| `rate_change` | `keep_emi` — tenure floats (what banks actually do) | `keep_tenure` — EMI is re-solved |
| `emi_change` | new instalment from that month onward | — |
| `prepayment` | `reduce_tenure` — EMI holds, loan ends earlier | `reduce_emi` — end date holds |

Events fire from the instalment of the **month** they fall in: an EMI change dated the 14th already
applies to that month's instalment. A prepayment lands immediately *after* that month's EMI, because
a monthly-rest loan only recalculates at its rest date.

**The invariant that keeps the arithmetic honest:**

> `Σ period.principal + Σ prepayments == Σ disbursements + Σ capitalised interest`

exactly, in paise. Interest is rounded to the paise every month, so this is what catches a drifting
engine — and it also catches a tranche that never made it into the schedule. `assertSound()` checks
it on every run and throws rather than quietly losing rupees. An EMI below the first month's interest
is refused outright, with the numbers, instead of looping to the 600-month ceiling.

### Tranches and pre-EMI

An under-construction property is released in stages, and interest accrues only on what you have
actually drawn. `loans.principal` is therefore the amount **sanctioned** — a ceiling — and each
`disbursement` event adds to what you owe. Disbursing more than the sanction is refused; disbursing
less is warned about, not silently amortised.

Between the first tranche and `first_emi_date` the engine runs a **pre-EMI phase**: interest only, no
principal, so the outstanding does not move. Those rows are flagged `is_pre_emi` and never called an
EMI on screen, because they are not one. `pre_emi_mode = 'capitalise'` rolls the interest into the
balance instead of billing it (and costs noticeably more).

Interest is billed in arrears, so a tranche drawn in February is first charged on the March anchor.
A loan drawn in full one month before its first EMI therefore has **no** pre-EMI phase — which is
exactly what an ordinary loan looks like, so nothing changed for one.

The EMI is solved at the **first instalment**, on the balance and rate in force then — it cannot be
solved earlier, because until the last tranche lands the drawdown is unknown.

### When a loan stops adding up

Delete the wrong tranche and the terms can stop describing a loan at all — a first
EMI with nothing disbursed yet, an EMI below the interest. The schedule is derived, so **nothing is
corrupted**; the engine simply refuses to invent one, and says which date to move.

That refusal must never become a trap:

- Reads and writes return **422 with the loan and its events**, never a 500. The UI shows a recovery
  card with the message, an *Edit terms* button, and the event list — which normally lives inside the
  detail view it just hid.
- An edit that would break a **working** loan is rolled back. An edit to an **already broken** one is
  the repair, and is allowed. `add`, `update` and `updateEvent` all check this; `deleteEvent` never
  does, because deletion is the escape hatch.
- The derived account balance is left at its last good value rather than zeroed, so net worth does
  not lurch while you fix it.

Every event is editable in place (`PATCH /api/loans/{id}/events/{eventId}`) — it keeps its id, and
every type-specific column is rewritten so switching a rate change into a prepayment cannot leave a
stale rate behind. Creating and editing share one `validateEvent()`, so they cannot drift apart.

### Section 24(b): the tax trap

Interest paid before you take possession is **not** deductible in the year you pay it. It is
aggregated and returned in **five equal annual instalments starting the financial year of
possession**, and those instalments still sit inside the same ₹2,00,000 cap.

Set `possession_date` and the FY table splits it correctly, greying out the pre-possession years
(deduction: nil) and showing the carried instalment as its own column. Leave it unset and the table
falls back to "deductible as paid" **and says so** — an unset possession date must not quietly
overstate a deduction.

### Paid vs unpaid — the one seam with the ledger

Every instalment starts **unpaid**. It becomes paid only when a real transaction is linked to it, so
"paid" always means the money left your account, never that the calendar passed the due date.

Tag a debit as `emi` in the Ledger and it grows a 🏦 button; that opens a picker (which loan, which
instalment — unpaid ones only, nearest the transaction's date first) and writes one row to
`loan_payments`. `transactions` gains no columns, so nothing is rehashed and no balance moves.
Deleting a transaction cascades its link away.

`UNIQUE(txn_id)` stops one debit from paying two loans; `UNIQUE(loan_id, period_no)` stops one
instalment from being paid twice. SQLite treats NULLs as distinct in a UNIQUE index, which is exactly
right here — it lets any number of manual payments coexist with `txn_id IS NULL`.

If the amount you actually paid differs from the schedule by more than 2%, it is **flagged, not
re-amortised**: letting the real debit rewrite the projection would make it thrash on bank rounding.
Record a genuine overpayment as a prepayment instead.

### Loans and net worth

Each loan silently owns one liability `accounts` row with `is_derived = 1`. `LoanService::sync()`
writes the **outstanding principal** into its `current_balance`, so `v_net_worth`,
`v_net_worth_history`, the debt ladder and the snapshot job all keep working untouched, and
double-counting is structurally impossible. Cron re-syncs nightly, because the figure moves on its
own as each month's instalment falls due.

> **Outstanding principal** = the closing balance after the last instalment due *before* the 1st of
> the current month. Everything from this month onward is still owed.

Future interest is deliberately **not** a liability — you only owe it if you keep the loan alive.
Counting it would overstate the debt badly (a ₹10L home loan at 8.5% carries ₹10.8L of interest).
It is reported separately as `remaining_interest`.

`is_derived` also means `CommitService` skips `recomputeBalance()` for that account and refuses to
commit a statement into it, and the UI hides it from both account pickers. A loan account has no
ledger rows; recomputing it from them would silently zero the debt.

`is_closed` is derived the same way, and in **both** directions: `sync()` sets it when the
outstanding reaches zero and clears it when it does not. There is no setter — it is absent from
`update()`'s allowlist on purpose, because a value the next `sync()` silently contradicts is worse
than no value at all. Settle a loan by recording the prepayment that settled it.

Deriving it one-way once let the flag latch: a loan that momentarily amortised to zero mid-edit (a
small first tranche against a large EMI) stayed "closed" after a later tranche revived it, and
`portfolio()` drops closed loans from the monthly EMI outgo and the debt-free date. Note that a loan
whose first EMI has not yet fallen is *not* closed — its outstanding is the full drawn principal,
since the money is owed from the day it is disbursed.

### What the page shows

Outstanding, next instalment, paid/overdue counts, projected payoff. Then **interest you will never
pay** — the schedule re-run with your prepayments and EMI hikes stripped out, so the difference is
what your choices bought you. Rate changes stay in: those were not your choice.

Also: the balance curve with prepayment markers, per-year interest-vs-principal bars, and the
**crossover month** where principal first overtakes interest.

The **prepayment simulator** runs hypothetical events through the same engine and writes nothing.
It reports interest saved, months saved, and a return ratio — every ₹1 prepaid saves ₹X of interest,
a guaranteed tax-free return worth comparing against what that money would earn invested.

You build a **plan**: any number of lump sums and "extra every month" streams (capped at 20 entries),
each removable, re-simulated together on every add and delete. `POST /api/loans/{id}/simulate` takes
`{whatifs: [...]}`; a bare `{mode, amount, …}` body is still accepted as a plan of one. Each entry
picks its own `reduce_tenure` / `reduce_emi`, a `monthly` entry expands to one prepayment event per
month, and the engine sorts and keys events by ordinal — so plan order is irrelevant and two entries
may share a date. The plan lives only in the browser. It is discarded when you switch loans (it means
nothing against another schedule) but survives an edit to the *same* loan, re-running against the new
baseline.

This is why the simulator is not redundant with the prepayment events below it. An event records what
happened: it writes `loan_events`, moves the derived loan account and therefore net worth, and the
cron snapshot will record that. A what-if is a question, not a fact, and the comparison it reports
would be impossible once the event was saved — the baseline it measures against would be gone.

A **financial-year** table gives interest per FY against the ₹2,00,000 Section 24(b) cap, and
principal for 80C. The **All loans** view blends the rate by balance (not by count), totals the
monthly EMI outgo, computes EMI-to-income from your median income over the last six complete months,
and names the date the last loan clears.

## Investments

The asset-side mirror of loans. Each holding owns a **derived asset account** (`is_derived = 1`,
`is_liability = 0`) whose balance is the holding's latest valuation, so `v_net_worth`, the dashboard
and the net-worth chart update with no changes — the same trick loans use on the liability side.

`GET /api/investments` (portfolio + every holding), then per-holding `events`, `valuations`,
`project` and `contributions` routes. Instruments: equity, mutual fund, gold, FD/RD, bond, PPF/EPF,
crypto, real estate, NPS, insurance, other.

- **Value is entered by hand, never fetched.** This app makes no external calls, so there is no live
  price feed. You record dated *valuations*; the most recent is the current value and what `sync()`
  writes into the account. A valuation older than 45 days is flagged stale.
- **No double counting.** Buying a fund is a debit from a bank account (that balance drops); the
  holding's value is a *separate* derived asset (it rises). The `investment` category is already
  excluded from expense analytics, so a contribution never lands in spending either.
- **Returns are money-weighted (XIRR).** `src/Services/Investment/Xirr.php` solves the rate that
  zeroes the NPV of the dated flows — buys/contributions negative, sells/withdrawals/dividends
  positive, and the current value a final inflow dated today. Solved by **bisection**, not
  Newton-Raphson: NPV is monotonic in the rate where a solution exists, so bisection cannot diverge
  or pick the wrong root. XIRR is deliberately *below* a naive "gain ÷ invested" for a SIP — a
  ₹1.2 L invested ₹10 k/month then worth ₹1.45 L is +20.8% simple but ~9.6% XIRR, because most of
  that money was invested for far less than a year.
- **Projections** compound the current value forward to a date you pick, plus an optional monthly
  SIP compounded over the same window. Rate priority: the holding's `expected_return_apr` if set,
  else its realised XIRR (clamped to a sane band so a week-old holding does not project a fantasy),
  else a conservative default for its instrument type.
- **The ledger seam** is `investment_contributions`, identical in spirit to `loan_payments`: tag an
  `investment` debit and link it as a contribution. `transactions` gains no column, so nothing
  rehashes and no balance moves; the contribution is also mirrored into `investment_events` so it
  counts in XIRR, and deleting the txn cascades both away. `UNIQUE(txn_id)` stops one debit funding
  two holdings.

`is_closed` is derived both ways, like a loan's: on once everything is sold and the value is zero,
off again if you revalue it.

## Excluded tags

Some money moves without being income or spending. `excluded_categories` holds the tags that are
left out of **every** income/expense figure — analytics, budgets, dashboard averages, month-on-month,
insights. Editable in Settings; seeded with `investment`, `epf_employee`, `epf_employer`,
`eps_pension`, `credit_card_payment` and `loan_disbursement`.

Two levels: a whole **tag**, or one specific **transaction**.

**Account balances are never affected, and structurally cannot be.**
`CommitService::recomputeBalance()` sums every committed row regardless of category
(`opening_balance + credits − debits`), so an account always reconciles to the closing balance
printed on its statement. Excluding a tag hides it from *analysis*, never from the ledger.

### Blacklisting one transaction

`transactions.is_excluded` does the same thing for a single row: toggle `⊘` in the Ledger, or
`PATCH /api/transactions/{id} {"is_excluded": 1}`. The row stays in the ledger (dimmed and struck
through), stays in the CSV export as `excluded_from_analysis=yes`, and stays in the balance — it
simply stops counting as income or expense. `GET /api/transactions?excluded=1` lists them.

One definition, one place: `Exclusions::SQL` is the clause
`is_excluded = 0 AND category NOT IN (SELECT category FROM excluded_categories)`, appended by `AnalyticsService`,
`BudgetService`, and the `v_monthly_cashflow` / `v_daily_expense` views. Because it is a subquery
rather than an interpolated list, a change takes effect everywhere on the next query — nothing to
invalidate. (Fixing, along the way, an old inconsistency: `v_monthly_cashflow` used to count
credit-card bills as expense while `v_daily_expense` excluded them.)

The defaults are seeded **once**, guarded by a `settings` flag rather than a plain
`INSERT OR IGNORE` — `schema.sql` is applied on every request, so an unconditional insert would
silently re-exclude a tag the moment you un-excluded it.

Excluded money is still shown, never silently dropped: the Analytics page lists each excluded tag's
volume under "Committed vs discretionary", and an insight names the total.

## Auto-tagging (no AI)

`TaggingEngine` decides a category deterministically. First hit wins:

1. **Narration grammar** → channel (UPI/NEFT/ATM…), counterparty, UPI handle, MCC.
   Each bank has its own: HDFC `UPI-<name>-<vpa>-<ifsc>-<ref>-<remark>`, Kotak
   `UPI/<name>/<bank>/<ref>/<remark>`, Federal `UPIOUT/<ref>/<vpa>/<remark>/<mcc>`.
2. **Self-identity** → is this you paying yourself? Names match by prefix (banks truncate at ~20
   chars); UPI handles tolerate a Levenshtein distance of 2 (OCR damage). Configured in Settings.
3. **`tagging_rules`** → merchant and channel rules, ordered by priority then longest pattern, so
   `CRED CLUB` beats `CRED`. Editable in Settings; add one from the Review table with **+rule**.
4. **MCC map** → Federal embeds the merchant category code (`.../ Pay-/5411`).
5. **Channel fallback** → ATM ⇒ `cash_withdrawal`, interest ⇒ `interest_income`, etc.
6. **Fallback** → `other_income` / `other_expense`.

Rules match against the narration with **IFSC codes stripped**. A substring match otherwise reads
routing metadata as a merchant: the rule `JIO` matched `JIOP0000001` (Jio Payments Bank's IFSC) and
filed four transfers to the owner's own account as telecom bills. For the same reason, short
patterns are word-boundary regexes — plain `contains` made `OLA ` match `BH(OLA) JAISWAL` and `TDS`
match `BUNDLTECHNOLOGIESPVTL(TDS)WIGGY`, a Swiggy order tagged as tax.

**Tag provenance** is recorded per row (`tag_source`: `rule` / `auto` / `manual`). Setting a category
by hand pins it as `manual`, and re-tagging never overwrites a manual decision.

Measure the tagger against your own statements at any time:

```sh
php bin/parse_test.php              # balance checks + category distribution + % others
php bin/parse_test.php --untagged   # the narrations still landing in other_*
php bin/parse_test.php --self       # what was classed as a self-transfer
```

Expect a substantial `other_expense` share on personal accounts: peer-to-peer UPI payments to
individuals genuinely have no category until you name one. The **+rule** button in Review is how
that number comes down.

## Key design decisions

- **Money is stored in paise (INTEGER)** — no floating point in the ledger. `Money::parse()` builds
  paise from digit strings, because `84481.57 * 100` is `8448156.9999…` in binary floating point.
- **Idempotency:** `transactions.txn_hash` is `UNIQUE`:
  `sha256(account_id | txn_date | amount_paise | cashflow | normalized(raw_description) | reference_id)`.
  It keys on the *untouched* narration, so editing a description in Review never forks a ledger row.
  Re-importing a statement flags duplicates (`staged_transactions.is_duplicate`) and inserts none.
- **Human-in-the-loop:** parsed rows go to `staged_transactions` first. Every field is editable;
  only approved rows reach `transactions`.
- **Balances are recomputed, never incremented:** `opening_balance + Σ ledger`, so they cannot drift.
  A loan account is the one exception (`accounts.is_derived = 1`): its balance is the outstanding
  principal from the amortisation engine, and `recomputeBalance()` steps around it.
- **Loan schedules are derived, never stored:** `loans + loan_events → AmortisationEngine`, on every
  read. Same reasoning as balances — nothing to invalidate, nothing to migrate when a rate changes.
- **Self-transfers** (`is_self_transfer = 1`) affect their own account's balance but are excluded
  from income/expense analytics. Investment/EPF outflows are likewise excluded from *expense*
  analytics but count toward net worth.
- **Parsing is synchronous.** The async upload worker existed only because an AI call took tens of
  seconds and could not survive the HTTP request. CSV parsing is fast, so uploads can no longer be
  orphaned mid-parse.
- **Net-worth ladder:** daily `balance_snapshots` roll up in `v_net_worth_history`; crossed ₹10,000
  steps persist in `milestones` so badges stay unlocked even if net worth dips.
- **Account colours** (`accounts.color`, `#rrggbb`) tint a dot and a left rule wherever an account is
  named — the dashboard cards, the settings list, every ledger row. They never tint text and never a
  money figure: a balance stays mint (asset) or rose (liability) whatever colour its account wears, so
  no pick can make a number unreadable. `App\Support\Palette` holds ten hues; `bin/migrate.php`
  back-fills any account without one, walking the palette in id order so no two open alike. Because
  migrate runs on **every request**, an account can never be colourless — "clear the colour" is
  refused rather than accepted and silently undone on the next page load. A loan's account is
  coloured on creation like any other.
- **The dashboard's debt curve comes from the schedule, not the ledger.** A loan account has no
  transactions, so `netWorthHistory()` excludes derived accounts from its ledger walk and adds
  `LoanService::debtOn()` per point instead. That method uses `position()`'s exact rule — the closing
  balance after the last instalment due *before the 1st of that date's month* — so the last point of
  the curve reconciles with `v_net_worth`, and a loan taken in 2022 shows **zero** debt in 2019.
  Walking a loan account like any other account froze its opening balance across the whole curve.
- **Debt paydown is measured against what was drawn**, from `LoanService::drawnByAccount()`, not
  against `debt_details.principal_amount` (a loan account has no such row, so `original` used to fall
  back to `current_balance` and "paid" was structurally always ₹0) and not against `loans.principal`
  (the amount *sanctioned* — a half-drawn home loan would report a paydown it never made).
- **Month-over-month spend skips the running month.** Ten days of July against all of June reported a
  100% collapse in spending that never happened. The card compares the last two months that actually
  finished — the same "completed months" rule the monthly average already used — and names both.
