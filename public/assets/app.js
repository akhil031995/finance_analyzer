/* Finance Analyzer — upload portal, review viewer, settings (vanilla JS). */
'use strict';

const $ = (sel) => document.querySelector(sel);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// The minus sign belongs outside the symbol: -₹4,69,210.43, never ₹-4,69,210.43.
const inr = (paise) => (paise < 0 ? '-' : '')
  + '₹' + Math.abs(paise / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 });
const inr0 = (paise) => {
  const r = Math.round(Math.abs(paise / 100));
  // −₹0 is not a number anyone wants to read.
  return (paise < 0 && r !== 0 ? '-' : '') + '₹' + r.toLocaleString('en-IN');
};
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTH_LABEL = (ym) => {
  const [y, m] = ym.split('-');
  return MONTHS[+m - 1] + " '" + y.slice(2);
};
const DATE_LABEL = (ymd) => {
  if (!ymd) return '';
  const [y, m, d] = ymd.split('-');
  return `${+d} ${MONTHS[+m - 1]} ${y}`;
};
// Compact Indian money: ₹4.23L, ₹5L, ₹6.7k, ₹1.2Cr. Trailing zeros stripped.
// Signed outside the symbol, as in inr(): -₹4.7L, never ₹-4.7L.
function inrCompact(paise, dec = 1) {
  const sign = paise < 0 ? '-' : '';
  const r = Math.abs(paise / 100);
  const fmt = (v, suf) => {
    let s = v.toFixed(dec);
    if (s.includes('.')) s = s.replace(/\.?0+$/, '');
    return sign + '₹' + s + suf;
  };
  const a = r;
  if (a >= 1e7) return fmt(r / 1e7, 'Cr');
  if (a >= 1e5) return fmt(r / 1e5, 'L');
  if (a >= 1e3) return fmt(r / 1e3, 'k');
  const w = Math.round(r);
  return (w === 0 ? '' : sign) + '₹' + w;
}

/* ---------------- global busy veil ----------------
 * Every request that isn't explicitly `quiet` raises a modal veil: the page
 * blurs, a spinner appears, and the rest of the document goes inert.
 *
 * Two timers keep it from being obnoxious. Nothing shows for the first
 * BUSY_DELAY ms, so the sub-100ms calls this app mostly makes never flash;
 * once shown it stays for at least BUSY_MIN ms, so a request that resolves
 * just after the veil appears doesn't produce a strobe.
 */
const BUSY_DELAY = 180;
const BUSY_MIN = 320;

let inflight = 0;
let busyShowTimer = null;
let busyHideTimer = null;
let busyShownAt = 0;

function showBusy() {
  const d = $('#busy');
  if (!d || d.open) return;
  clearTimeout(busyHideTimer);
  busyHideTimer = null;
  busyShownAt = Date.now();
  try { d.showModal(); } catch { /* already open, or no dialog support */ }
  // Anything focused stays typable behind an inert page in some browsers.
  document.activeElement?.blur?.();
}

function hideBusy() {
  const d = $('#busy');
  if (!d || !d.open) return;
  const remaining = Math.max(0, BUSY_MIN - (Date.now() - busyShownAt));
  busyHideTimer = setTimeout(() => {
    if (inflight === 0 && d.open) d.close();
  }, remaining);
}

// Esc must not dismiss the veil — the request is still running.
$('#busy')?.addEventListener('cancel', (e) => e.preventDefault());

/**
 * @param {{quiet?: boolean}} opts  `quiet: true` skips the veil — use it for
 *        search-as-you-type and other high-frequency calls, where blurring the
 *        page on every keystroke would be unusable.
 */
async function api(path, opts = {}) {
  const { quiet = false, ...init } = opts;

  if (!quiet && ++inflight === 1) {
    clearTimeout(busyHideTimer);
    busyShowTimer = setTimeout(showBusy, BUSY_DELAY);
  }

  try {
    const res = await fetch(path, init);
    let body = {};
    try { body = await res.json(); } catch { /* non-JSON error page */ }
    return { ok: res.ok, status: res.status, body };
  } catch (e) {
    // A network failure must still settle the veil, and callers already branch
    // on `ok`, so surface it as a failed response rather than throwing.
    return { ok: false, status: 0, body: { message: 'Network error — is the server running?' } };
  } finally {
    if (!quiet && --inflight === 0) {
      clearTimeout(busyShowTimer);
      hideBusy();
    }
  }
}

/* ---------------- tabs / nav / routing ---------------- */
const PAGES = { dashboard: 'Dashboard', analytics: 'Analytics', loans: 'Loans', investments: 'Investments', budget: 'Budget', upload: 'Upload', review: 'Review', ledger: 'Ledger', logs: 'Logs', settings: 'Settings' };
const TAB_LOADERS = {
  dashboard: () => loadDashboard(),
  analytics: () => loadAnalytics(),
  loans: () => loadLoans(),
  investments: () => loadInvestments(),
  budget: () => loadBudgetPage(),
  ledger: () => loadLedger(),
  logs: () => loadLogs(),
};
const tabFromPath = () => {
  const p = location.pathname.replace(/^\/+/, '').split('/')[0] || 'dashboard';
  return PAGES[p] ? p : 'dashboard';
};

// Each page is a real URL (History API). push=false when reacting to the URL
// (initial load, back/forward) so we don't re-push a duplicate entry.
function showTab(name, push = true) {
  if (!PAGES[name]) name = 'dashboard';
  document.querySelectorAll('.tab').forEach((el) => el.classList.add('hidden'));
  const sec = document.getElementById('tab-' + name);
  if (sec) sec.classList.remove('hidden');
  document.querySelectorAll('.nav-btn').forEach((b) => {
    const active = b.dataset.tab === name;
    b.classList.toggle('bg-ink-600', active);
    b.classList.toggle('text-mint-400', active);
  });

  // Shared title bar (desktop), mobile "app - page", and browser tab title.
  document.title = 'Finance Analyzer - ' + PAGES[name];
  const pt = document.getElementById('page-title');
  if (pt) pt.textContent = PAGES[name];
  const mt = document.getElementById('mobile-title');
  if (mt) mt.textContent = 'Finance Analyzer - ' + PAGES[name];

  if (push && location.pathname !== '/' + name) {
    history.pushState({ tab: name }, '', '/' + name);
  }
  window.scrollTo(0, 0);
  TAB_LOADERS[name]?.();
}

document.querySelectorAll('[data-nav]').forEach((nav) =>
  nav.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tab]');
    if (btn) showTab(btn.dataset.tab);
  }));

window.addEventListener('popstate', () => showTab(tabFromPath(), false));

/* ---------------- dashboard ---------------- */
/**
 * Two different failures live behind one symptom (a balance that is plainly
 * wrong), and they need different fixes — so name them separately.
 *
 *   opening_drift  the stored opening balance disagrees with the oldest row.
 *                  Almost always: a statement older than the rest was imported
 *                  after the opening balance had been set from a later one.
 *
 *   breaks         the statement's own Closing Balance column moves in ways its
 *                  amount columns cannot explain — rows are missing from the file.
 *
 * Never offer a one-click "fix": an opening balance that no statement implies is
 * legitimate, and silently rewriting it would paper over missing transactions.
 */
function renderHealth(problems) {
  const box = $('#dash-health');
  box.classList.toggle('hidden', problems.length === 0);
  if (problems.length === 0) return;

  $('#dash-health-list').innerHTML = problems.map((h) => {
    const notes = [];

    if (h.opening_drift !== 0) {
      notes.push(`<li>Its opening balance is set to <strong>${inr(h.stored_opening)}</strong>, but the oldest
        transaction (${DATE_LABEL(h.first_date)}) implies it should be
        <strong>${inr(h.implied_opening)}</strong> — a difference of ${inr(Math.abs(h.opening_drift))}.
        This is what happens when you import a statement older than the ones already loaded.
        Correct it in <button data-tab="settings" class="nav-btn underline">Settings → Accounts</button>.</li>`);
    }

    // missing_net is the order-independent verdict; `breaks` only locates it.
    if (h.missing_net !== 0) {
      const dir = h.missing_net > 0 ? 'credits' : 'debits';
      notes.push(`<li><strong>${inr(Math.abs(h.missing_net))} of ${dir} are missing.</strong>
        The statement's own running balance accounts for them across
        ${h.breaks} break${h.breaks === 1 ? '' : 's'}, but no matching transaction rows exist.
        The file you imported is incomplete — re-export it from your bank. No opening balance can fix this.</li>`);
    }

    if (h.balance_drift !== 0 && h.missing_net === 0 && h.opening_drift === 0) {
      notes.push(`<li>The balance is off by ${inr(Math.abs(h.balance_drift))} against the statement's
        closing balance, with no visible cause. Rows from another account may have been committed here.</li>`);
    }

    if (!h.verified) {
      notes.push(`<li class="text-amber-300">This check could not verify itself on this account, so treat
        the figures above as indicative only.</li>`);
    }

    return `<div class="bg-ink-900/40 border border-rose-500/20 rounded-xl p-3">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <span class="font-medium">${esc(h.name)}</span>
        <span class="text-xs text-slate-400">
          shows ${inr(h.current_balance)} · statement says ${inr(h.statement_balance)}
        </span>
      </div>
      <ul class="list-disc pl-5 mt-2 space-y-1 text-xs text-slate-300">${notes.join('')}</ul>
    </div>`;
  }).join('');
}

async function loadDashboard() {
  const d = (await api('/api/dashboard')).body;
  if (!d || !d.net_worth) return;

  $('#dash-empty').classList.toggle('hidden', d.has_data);
  $('#dash-body').classList.toggle('hidden', !d.has_data);
  renderHealth(d.health ?? []);
  if (!d.has_data) return;

  // Hero + assets/liabilities
  $('#nw-hero').textContent = inr(d.net_worth.net);
  $('#nw-assets').textContent = inr(d.net_worth.assets);
  $('#nw-liab').textContent = inr(d.net_worth.liabilities);

  // Stat tiles
  $('#stat-daily').textContent = inr(d.averages.daily_expense);
  $('#stat-monthly').textContent = inr(d.averages.monthly_expense);

  renderMonthlyAverage(d.averages);
  renderMoM(d.mom);

  renderBudget(d.budget);
  renderLadder(d.ladder);
  renderDebtLadder(d.debt_ladder);
  renderMonthChart(d.months);
  renderNetWorthTrend(d.net_worth_history);
  renderNetWorthDebtChart(d.net_worth_history);
  renderDashAccounts(d.accounts);
}

/* The average is dominated by EMI on a ledger carrying loans, which makes it
   useless as a spending signal. Split it: EMI is contractual, the remainder is
   discretionary + commitments you could actually change. */
function renderMonthlyAverage(a) {
  // "the last N" matters: this is a rolling window, not the whole ledger. Saying
  // "across 12 completed months" reads like the ledger only holds 12.
  const completed = a.complete_months ?? 0;
  $('#stat-monthly-sub').textContent = completed
    ? `across the last ${completed} complete month${completed === 1 ? '' : 's'}`
    : 'completed months only';

  $('#stat-monthly-ex-emi').textContent = inr(a.monthly_expense_ex_emi);
  $('#stat-monthly-emi').textContent = inr(a.monthly_emi);

  const total = a.monthly_expense || 1;
  const emiPct = Math.round((a.monthly_emi / total) * 100);
  $('#stat-monthly-split').innerHTML =
    `<div style="width:${100 - emiPct}%;background:#e11d48"></div>`
    + `<div style="width:${emiPct}%;background:#f59e0b"></div>`;
  $('#stat-monthly-share').textContent = a.monthly_emi > 0
    ? `EMI is ${emiPct}% of what you spend.`
    : 'No EMI in these months.';

  $('#stat-monthly-hint').textContent = a.monthly_expense
    ? `About ${inr0(a.monthly_expense * 12)} a year at this rate.`
    : '';
}

function renderMoM(m) {
  const el = $('#stat-mom');
  $('#stat-mom-title').textContent = m.skipped_partial
    ? 'Spend, last complete month'
    : 'Spend vs last month';

  if (m.pct_change === null) {
    el.textContent = '—';
    el.className = 'text-2xl font-semibold mt-1 text-slate-400';
    $('#stat-mom-sub').textContent = 'need two completed months to compare';
    return;
  }
  const down = m.pct_change < 0;                 // spending less = good = mint
  el.textContent = (down ? '▼ ' : '▲ ') + Math.abs(m.pct_change) + '%';
  el.className = 'text-2xl font-semibold mt-1 ' + (down ? 'text-mint-400' : 'text-rose-300');

  // Name both months. The running one is left out, so "vs last month" would
  // otherwise be read as "vs the month before this one".
  $('#stat-mom-sub').textContent =
    `${inr0(m.this_month_expense)} in ${MONTH_LABEL(m.this_month)} vs `
    + `${inr0(m.prev_month_expense)} in ${MONTH_LABEL(m.prev_month)}`;
}

// Horizontal step-based net-worth ladder: a node per ₹10k milestone, reached
// nodes filled cyan with their achieved date, a floating "you" pill at the
// live net worth, upcoming nodes hollow. Scrolls, auto-centered on current.
const LADDER_CELL = 88;   // px width per rung
const LADDER_RAIL_Y = 46; // px from top to the rail / node centers

function renderLadder(l) {
  $('#ladder-step-pill').textContent = 'Step: ' + inr0(l.step);
  $('#nw-ladder-caption').textContent =
    `— ${l.current_step} of ${l.total_steps} milestones reached, next at ${inr0(l.next_milestone)}`;

  const n = l.rungs.length;
  const totalW = n * LADDER_CELL;
  const p = (l.progress_pct || 0) / 100;
  // x of the live position: last reached node centre + progress into the step.
  const curX = (l.current_step - 1) * LADDER_CELL + LADDER_CELL / 2 + p * LADDER_CELL;
  const clampedCurX = Math.max(LADDER_CELL / 2, curX);

  const nodes = l.rungs.map((r, i) => {
    const border = r.reached ? '#22d3ee' : (r.is_next ? '#22d3ee' : '#334155');
    const bg = r.reached ? '#22d3ee' : '#0b1120';
    const size = r.reached ? 18 : 15;
    const labelCls = r.reached ? 'text-cyan-300' : (r.is_next ? 'text-slate-200' : 'text-slate-500');
    const sub = r.reached ? (r.achieved_on ? DATE_LABEL(r.achieved_on) : 'reached') : 'Upcoming';
    return `<div class="absolute flex flex-col items-center" style="left:${i * LADDER_CELL}px;width:${LADDER_CELL}px;top:${LADDER_RAIL_Y - size / 2}px">
      <div style="width:${size}px;height:${size}px;border-radius:9999px;border:2px solid ${border};background:${bg}"></div>
      <div class="mt-2 text-xs font-medium ${labelCls} whitespace-nowrap">${inrCompact(r.value, 1)}</div>
      <div class="text-[10px] text-slate-500 whitespace-nowrap">${sub}</div>
    </div>`;
  }).join('');

  $('#nw-ladder').innerHTML = `
    <div class="relative" style="width:${totalW}px;height:104px">
      <div class="absolute rounded-full" style="left:${LADDER_CELL / 2}px;right:${LADDER_CELL / 2}px;top:${LADDER_RAIL_Y}px;height:3px;background:#243354"></div>
      <div class="absolute rounded-full" style="left:${LADDER_CELL / 2}px;top:${LADDER_RAIL_Y}px;height:3px;background:#22d3ee;width:${Math.max(0, clampedCurX - LADDER_CELL / 2)}px"></div>
      <div class="absolute z-10 -translate-x-1/2 flex flex-col items-center" style="left:${clampedCurX}px;top:4px">
        <div class="bg-cyan-400 text-ink-900 font-bold text-xs px-2 py-0.5 rounded-full whitespace-nowrap">${inrCompact(l.net_worth, 2)}</div>
        <div class="w-2 h-2 bg-cyan-400 rotate-45 -mt-1"></div>
      </div>
      ${nodes}
    </div>`;

  const wrap = $('#nw-ladder');
  wrap.scrollLeft = Math.max(0, clampedCurX - wrap.clientWidth / 2);
}

// Debt paydown: a track from ₹0 borrowed to Debt-free, with quarter rungs, an
// amber fill to the % of the ORIGINAL principal repaid, and a marker pill.
// `original` is what was drawn, not what is outstanding — see debtLadder().
function renderDebtLadder(d) {
  const wrap = $('#debt-ladder-wrap');
  if (!d) { wrap.classList.add('hidden'); return; }
  wrap.classList.remove('hidden');
  $('#debt-ladder-caption').textContent = d.original > 0
    ? `— ${inr0(d.paid)} of ${inr0(d.original)} paid off · ${inr0(d.outstanding)} to go`
    : `— ${inr0(d.outstanding)} outstanding`;

  const pct = Math.min(100, Math.max(0, d.paid_pct || 0));
  const done = d.outstanding <= 0;

  // Quarter rungs of the original principal, plus the finish line.
  const rungs = [0, 25, 50, 75, 100].map((at) => {
    const reached = pct >= at || (at === 100 && done);
    const isEnd = at === 100;
    const size = isEnd ? 16 : 13;
    const label = isEnd ? 'Debt-free' : inrCompact(Math.round((d.original * at) / 100), 1);
    const sub = isEnd ? (done ? 'Reached' : 'Upcoming') : (reached ? 'Paid' : `${at}%`);
    return `
      <div data-rung="${at}" data-reached="${reached ? 1 : 0}"
           class="absolute flex flex-col items-center -translate-x-1/2" style="left:calc(16px + (100% - 32px) * ${at / 100});top:${36 - size / 2}px">
        <div style="width:${size}px;height:${size}px;border-radius:9999px;border:2px solid #f59e0b;background:${reached ? '#f59e0b' : '#0b1120'}"></div>
        <div class="mt-2 text-xs font-medium ${reached ? 'text-amber-300' : 'text-slate-400'} whitespace-nowrap">${label}</div>
        <div class="text-[10px] text-slate-500 whitespace-nowrap">${sub}</div>
      </div>`;
  }).join('');

  $('#debt-ladder').innerHTML = `
    <div class="relative" style="min-width:520px;height:84px">
      <div class="absolute rounded-full" style="left:16px;right:16px;top:36px;height:3px;background:#243354"></div>
      <div class="absolute rounded-full" style="left:16px;top:36px;height:3px;background:#f59e0b;width:calc((100% - 32px) * ${pct / 100})"></div>
      ${rungs}
      <div class="absolute z-10 -translate-x-1/2 flex flex-col items-center" style="left:calc(16px + (100% - 32px) * ${pct / 100});top:0">
        <div class="bg-amber-400 text-ink-900 font-bold text-xs px-2 py-0.5 rounded-full whitespace-nowrap">${inrCompact(d.paid, 1)} · ${pct}%</div>
        <div class="w-2 h-2 bg-amber-400 rotate-45 -mt-1"></div>
      </div>
    </div>`;
}

/* Spread points along a TIME axis, not their index. The series has one point per
   active ledger date, so 2019 (dense) would otherwise occupy far more width than
   2024 (sparse) and every slope would lie about how fast things moved. */
const timeScale = (history) => {
  const t = history.map((p) => Date.parse(p.date));
  const t0 = t[0], span = (t[t.length - 1] - t0) || 1;
  return (i) => (t[i] - t0) / span;         // 0 .. 1
};

// Net-worth trend sparkline. Stretches to fill the card: preserveAspectRatio
// "none" + non-scaling strokes, so the shape follows the box without the line
// fattening. No end dot — an ellipse is what a scaled circle becomes.
function renderNetWorthTrend(history) {
  const el = $('#nw-trend');
  const range = $('#nw-trend-range');
  if (!history || history.length < 2) { el.innerHTML = ''; range.textContent = ''; return; }

  const W = 1000, H = 120;
  const vals = history.map((p) => p.net_worth);
  const min = Math.min(...vals), max = Math.max(...vals), span = max - min || 1;
  const at = timeScale(history);
  const x = (i) => at(i) * W;
  const y = (v) => H - ((v - min) / span) * H;
  const pts = history.map((p, i) => `${x(i).toFixed(1)},${y(p.net_worth).toFixed(2)}`).join(' ');
  const first = history[0], last = history[history.length - 1];
  const stroke = last.net_worth >= first.net_worth ? '#34d399' : '#fb7185';

  const area = `${pts} ${W},${H} 0,${H}`;
  const zero = min < 0 && max > 0
    ? `<line x1="0" y1="${y(0).toFixed(2)}" x2="${W}" y2="${y(0).toFixed(2)}"
             stroke="#475569" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>` : '';

  range.textContent = `${MONTH_LABEL(first.date.slice(0, 7))} → ${MONTH_LABEL(last.date.slice(0, 7))}`;
  el.innerHTML = `
    <svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" class="w-full h-full" aria-hidden="true">
      <defs><linearGradient id="nw-spark" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${stroke}" stop-opacity="0.30"/>
        <stop offset="100%" stop-color="${stroke}" stop-opacity="0"/>
      </linearGradient></defs>
      <polygon points="${area}" fill="url(#nw-spark)"/>
      ${zero}
      <polyline points="${pts}" fill="none" stroke="${stroke}" stroke-width="2"
                stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
    </svg>`;
}

// Net worth vs Debt line chart (two series, one shared ₹ axis) with a hover
// crosshair + tooltip. Net worth #059669, debt #e11d48 (validated on dark).
function renderNetWorthDebtChart(history) {
  const card = $('#nwd-chart-card');
  if (!history || history.length < 2) { card.classList.add('hidden'); return; }
  card.classList.remove('hidden');

  const H = 300, padT = 16, padB = 46, AXIS_W = 64;
  const nws = history.map((p) => p.net_worth);
  const debts = history.map((p) => p.debt);

  // Round the axis outward to a whole step, so the labels read ₹40L / ₹20L / ₹0
  // rather than ₹37.9L / ₹18.9L / ₹3k — and so zero is always ON a gridline.
  const rawMin = Math.min(0, ...nws, ...debts);
  const rawMax = Math.max(0, ...nws, ...debts, 1);
  const rough = (rawMax - rawMin) / 4;
  const mag = 10 ** Math.floor(Math.log10(Math.max(1, rough)));
  const step = [1, 2, 2.5, 5, 10].map((f) => f * mag).find((sv) => sv >= rough) ?? 10 * mag;
  const min = Math.floor(rawMin / step) * step;
  const max = Math.ceil(rawMax / step) * step;

  // One month of history gets a fixed pitch, so the plot scrolls instead of
  // cramming seven years into the card. Never narrower than the card itself.
  const months = Math.max(1, Math.round((Date.parse(history.at(-1).date) - Date.parse(history[0].date)) / 2.6298e9));
  const W = Math.max($('#nwd-scroll').clientWidth || 640, months * 16);

  // Time axis, not index: 2019 has a transaction most days, 2024 has a handful.
  const at = timeScale(history);
  const x = (i) => 8 + at(i) * (W - 16);
  const y = (v) => H - padB - ((v - min) / (max - min)) * (H - padT - padB);
  const poly = (arr) => arr.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');

  // Pinned ₹ axis: its own SVG, the same vertical scale, outside the scroller.
  let ylabels = '', grid = '';
  for (let val = min; val <= max + 1; val += step) {
    const yy = y(val).toFixed(1);
    const isZero = Math.abs(val) < 1;
    ylabels += `<text x="${AXIS_W - 8}" y="${(+yy + 4).toFixed(1)}" text-anchor="end" fill="#64748b" font-size="12">${inrCompact(val, 1)}</text>`;
    grid += `<line x1="0" y1="${yy}" x2="${W}" y2="${yy}"
                   stroke="${isZero ? '#475569' : '#243354'}" stroke-width="1"${isZero ? ' stroke-dasharray="4 4"' : ''}/>`;
  }
  $('#nwd-axis').innerHTML =
    `<svg width="${AXIS_W}" height="${H}" viewBox="0 0 ${AXIS_W} ${H}">${ylabels}</svg>`;

  // A tick every year, and every quarter once there is room. Angled, like the bars.
  const everyQuarter = W / Math.max(1, months / 3) > 46;
  let xticks = '';
  history.forEach((p, i) => {
    if (i === 0) return;
    const prev = history[i - 1];
    const yearTurn = p.date.slice(0, 4) !== prev.date.slice(0, 4);
    const qTurn = everyQuarter && Math.floor((+p.date.slice(5, 7) - 1) / 3) !== Math.floor((+prev.date.slice(5, 7) - 1) / 3);
    if (!yearTurn && !qTurn) return;
    const xx = x(i).toFixed(1);
    xticks += `<line x1="${xx}" y1="${padT}" x2="${xx}" y2="${H - padB}" stroke="${yearTurn ? '#334155' : '#1e293b'}" stroke-width="1"/>`
      + `<text x="${xx}" y="${H - padB + 12}" transform="rotate(-45 ${xx} ${H - padB + 12})" text-anchor="end"
              fill="#64748b" font-size="11">${yearTurn ? p.date.slice(0, 4) : MONTH_LABEL(p.date.slice(0, 7))}</text>`;
  });

  const hasDebt = debts.some((v) => v > 0);
  // Close the area on ZERO, not on the bottom of the chart, so a negative net
  // worth fills downward from the axis instead of flooding the whole panel.
  const yZero = y(0).toFixed(1);
  const nwArea = `${x(0).toFixed(1)},${yZero} ${poly(nws)} ${x(history.length - 1).toFixed(1)},${yZero}`;

  $('#nwd-chart').innerHTML = `
    <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" id="nwd-svg">
      <defs>
        <linearGradient id="nwGradPos" x1="0" x2="0" y1="0" y2="1">
          <stop offset="0%" stop-color="#059669" stop-opacity="0.30"/><stop offset="100%" stop-color="#059669" stop-opacity="0.02"/>
        </linearGradient>
        <clipPath id="nwdClip"><rect x="0" y="${padT}" width="${W}" height="${H - padT - padB}"/></clipPath>
      </defs>
      ${grid}${xticks}
      <g clip-path="url(#nwdClip)">
        <polygon points="${nwArea}" fill="url(#nwGradPos)"/>
        ${hasDebt ? `<polyline points="${poly(debts)}" fill="none" stroke="#e11d48" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>` : ''}
        <polyline points="${poly(nws)}" fill="none" stroke="#059669" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="${x(history.length - 1).toFixed(1)}" cy="${y(nws[nws.length - 1]).toFixed(1)}" r="3.5" fill="#059669" stroke="#111a2e" stroke-width="2"/>
        ${hasDebt ? `<circle cx="${x(history.length - 1).toFixed(1)}" cy="${y(debts[debts.length - 1]).toFixed(1)}" r="3.5" fill="#e11d48" stroke="#111a2e" stroke-width="2"/>` : ''}
      </g>
      <line id="nwd-cross" x1="0" y1="${padT}" x2="0" y2="${H - padB}" stroke="#64748b" stroke-width="1" stroke-dasharray="3 3" opacity="0"/>
    </svg>
    <div id="nwd-tip" class="hidden absolute pointer-events-none bg-ink-900 border border-ink-600 rounded-lg px-2.5 py-1.5 text-xs shadow-lg z-10"></div>`;

  // Hover: map cursor → nearest point IN TIME, move crosshair + tooltip.
  const svg = $('#nwd-svg'), cross = $('#nwd-cross'), tip = $('#nwd-tip');
  const onMove = (ev) => {
    const rect = svg.getBoundingClientRect();
    const vx = (ev.clientX - rect.left) / rect.width * W;
    const frac = Math.max(0, Math.min(1, (vx - 8) / (W - 16)));
    let i = 0, best = Infinity;
    history.forEach((_, k) => { const dd = Math.abs(at(k) - frac); if (dd < best) { best = dd; i = k; } });
    const p = history[i];
    cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('opacity', '1');
    tip.classList.remove('hidden');
    tip.innerHTML = `<div class="text-slate-400 mb-0.5">${DATE_LABEL(p.date)}</div>`
      + `<div><span style="color:#10b981">●</span> Net worth ${inr(p.net_worth)}</div>`
      + (hasDebt ? `<div><span style="color:#fb7185">●</span> Debt ${inr(p.debt)}</div>` : '');
    tip.style.left = Math.min(W - 170, Math.max(0, x(i) + 8)) + 'px';
    tip.style.top = '6px';
  };
  svg.addEventListener('mousemove', onMove);
  svg.addEventListener('mouseleave', () => { cross.setAttribute('opacity', '0'); tip.classList.add('hidden'); });

  // Open on the most recent data, like the bar chart.
  const sc = $('#nwd-scroll');
  sc.scrollLeft = sc.scrollWidth;
}


/* Every month the ledger has, scrollable, opened at the most recent. A fixed
   pitch keeps the bars readable at any history length; the card scrolls rather
   than squeezing 91 months into its width. Labels sit in their own row, angled,
   so a long label can never widen its column or collide with its neighbour. */
const MONTH_PITCH = 42;   // px per month, including the gap

function renderMonthChart(months) {
  if (months.length === 0) { $('#month-chart').innerHTML = ''; return; }

  const max = Math.max(1, ...months.map((m) => Math.max(m.income, m.expense)));
  const col = (val, color, label) => `
    <div class="flex-1 rounded-t"
         style="height:${((val / max) * 100).toFixed(2)}%;min-height:${val > 0 ? '2px' : '0'};background:${color}"
         title="${label}: ${inr(val)}"></div>`;

  // Fill the card when there is little history, scroll when there is a lot.
  $('#month-inner').style.minWidth = `${months.length * MONTH_PITCH}px`;

  $('#month-chart').innerHTML = months.map((m) => `
    <div class="flex-1 h-full flex items-end gap-px" data-month="${m.month}" title="${MONTH_LABEL(m.month)}">
      ${col(m.income, '#059669', MONTH_LABEL(m.month) + ' income')}
      ${col(m.expense, '#e11d48', MONTH_LABEL(m.month) + ' expense')}
    </div>`).join('');

  // -45°, anchored by its right edge at the column's centre, so each label runs
  // back and up under its own bar instead of across the next one.
  $('#month-chart-axis').innerHTML = months.map((m) => `
    <div class="flex-1 relative" data-axis="${m.month}">
      <span class="absolute right-1/2 top-1 origin-top-right -rotate-45 whitespace-nowrap text-[10px] text-slate-500">${MONTH_LABEL(m.month)}</span>
    </div>`).join('');

  const range = $('#month-chart-range');
  if (range) range.textContent = `· ${MONTH_LABEL(months[0].month)} → ${MONTH_LABEL(months[months.length - 1].month)}`;

  // Open on the newest month; that is what you came to look at.
  const sc = $('#month-scroll');
  sc.scrollLeft = sc.scrollWidth;
}

function renderBudget(b) {
  const card = $('#budget-card');
  if (!b) { card.classList.add('hidden'); return; }
  card.classList.remove('hidden');
  $('#budget-month').textContent = '· ' + MONTH_LABEL(b.month);
  $('#budget-spent').textContent = inr(b.spent);
  $('#budget-monthly').textContent = inr(b.monthly);
  $('#budget-daily').textContent = inr(b.daily);
  $('#budget-remaining').textContent = inr(b.remaining);
  $('#budget-remaining').className = 'font-semibold text-sm mt-0.5 ' + (b.remaining < 0 ? 'text-rose-300' : 'text-slate-200');
  $('#budget-daily-remaining').textContent = inr(b.daily_remaining);
  $('#budget-days-left').textContent = `for ${b.days_remaining} day${b.days_remaining === 1 ? '' : 's'}`;

  const pct = Math.min(100, b.spent_pct);
  const bar = $('#budget-bar');
  bar.style.width = pct + '%';
  bar.style.background = b.over_budget ? '#e11d48' : (b.on_track ? '#10b981' : '#f59e0b');

  const pace = $('#budget-pace');
  if (b.over_budget) { pace.textContent = 'Over budget'; pace.className = 'chip bg-rose-500/20 text-rose-300'; }
  else if (b.on_track) { pace.textContent = 'On track'; pace.className = 'chip bg-mint-500/20 text-mint-400'; }
  else { pace.textContent = 'Ahead of pace'; pace.className = 'chip bg-amber-500/20 text-amber-300'; }
}

/* ---------------- budget page (deep analysis) ---------------- */
/* ---------------- budget page ----------------
   Every figure on this page is derived from the category list, so the
   include/exclude checkboxes are a pure view filter: no API call, nothing
   saved. The recomputation below mirrors BudgetService::status() exactly —
   with nothing excluded it reproduces the server's own numbers. */
const budgetState = { data: null, excluded: new Set(), month: null };   // month null = current

async function loadBudgetPage() {
  const qs = budgetState.month ? '?month=' + budgetState.month : '';
  const a = (await api('/api/budgets/analysis' + qs)).body;
  budgetState.data = a;
  budgetState.month = a?.month ?? budgetState.month;   // trust the server's resolution
  // A category that has vanished between loads must not stay excluded forever.
  const known = new Set((a?.categories ?? []).map((c) => c.category));
  budgetState.excluded = new Set([...budgetState.excluded].filter((c) => known.has(c)));
  renderBudgetPage();
}

function renderBudgetPage() {
  const a = budgetState.data;
  const hasContent = a && (a.overall || (a.categories && a.categories.length));
  $('#budget-page-empty').classList.toggle('hidden', !!hasContent);
  $('#budget-page-body').classList.toggle('hidden', !hasContent);
  if (!hasContent) return;

  const all = a.categories ?? [];
  const shown = all.filter((c) => !budgetState.excluded.has(c.category));
  const filtered = shown.length !== all.length;

  // --- totals over the ticked categories only ---
  const spent = shown.reduce((t, c) => t + c.spent, 0);
  const budgeted = shown.reduce((t, c) => t + (c.budget ?? 0), 0);
  const spending = shown.filter((c) => c.spent > 0).length;

  renderBudgetPeriod(a);
  $('#bp-month').textContent = '· ' + MONTH_LABEL(a.month);
  $('#bp-spent-label').textContent = a.month === new Date().toISOString().slice(0, 7)
    ? 'Spent this month' : 'Spent in';
  $('#bp-spent').textContent = inr(spent);
  $('#bp-total-budgeted').textContent = inr(budgeted);
  $('#bp-cat-count').textContent = spending;

  const note = $('#bp-filter-note');
  note.classList.toggle('hidden', !filtered);
  if (filtered) note.textContent = `${shown.length} of ${all.length} categories`;
  $('#bp-selected-count').textContent = `${shown.length} of ${all.length} selected`;

  const o = a.overall;

  // What you are actually burning per elapsed day, as opposed to the allowance.
  // Days elapsed comes from the server when there is a budget (so it matches
  // BudgetService exactly); otherwise it is derived the same way — today's date
  // for the running month, the whole month for one already finished.
  const [yy, mm] = a.month.split('-').map(Number);
  const today = new Date();
  const isCurrentMonth = today.getFullYear() === yy && today.getMonth() + 1 === mm;
  const daysInMonth = new Date(yy, mm, 0).getDate();
  const daysElapsed = o ? o.day : (isCurrentMonth ? today.getDate() : daysInMonth);
  const actualPerDay = daysElapsed > 0 ? Math.round(spent / daysElapsed) : 0;

  $('#bp-actual-daily').textContent = inr(actualPerDay);
  if (o && o.daily > 0) {
    const over = actualPerDay > o.daily;
    $('#bp-actual-daily').className = 'font-semibold text-sm mt-0.5 ' + (over ? 'text-rose-300' : 'text-mint-400');
    $('#bp-actual-daily-sub').textContent = over
      ? `${(actualPerDay / o.daily).toFixed(1)}× allowance`
      : `${Math.round((actualPerDay / o.daily) * 100)}% of allowance`;
  } else {
    $('#bp-actual-daily').className = 'font-semibold text-sm mt-0.5';
    $('#bp-actual-daily-sub').textContent = `over ${daysElapsed} day${daysElapsed === 1 ? '' : 's'}`;
  }

  if (o) {
    // Same formulas as BudgetService::status(), applied to the filtered spend.
    // `monthly` and `daily` are the budget itself, so filtering never moves them.
    const remaining = o.monthly - spent;
    const dailyRemaining = o.days_remaining > 0 ? Math.round(Math.max(0, remaining) / o.days_remaining) : 0;
    const spentPct = o.monthly > 0 ? Math.round((spent / o.monthly) * 1000) / 10 : 0;
    const onTrack = spent <= Math.round(o.daily * o.day);
    const overBudget = remaining < 0;

    $('#bp-monthly').textContent = inr(o.monthly);
    $('#bp-daily').textContent = inr(o.daily);
    $('#bp-remaining').textContent = inr(remaining);
    $('#bp-remaining').className = 'font-semibold text-sm mt-0.5 ' + (remaining < 0 ? 'text-rose-300' : 'text-slate-200');
    $('#bp-daily-remaining').textContent = inr(dailyRemaining);
    $('#bp-days-left').textContent = `for ${o.days_remaining} day${o.days_remaining === 1 ? '' : 's'}`;
    $('#bp-bar').style.width = Math.min(100, spentPct) + '%';
    $('#bp-bar').style.background = overBudget ? '#e11d48' : (onTrack ? '#10b981' : '#f59e0b');

    const pace = $('#bp-pace');
    if (overBudget) { pace.textContent = 'Over budget'; pace.className = 'chip bg-rose-500/20 text-rose-300'; }
    else if (onTrack) { pace.textContent = 'On track'; pace.className = 'chip bg-mint-500/20 text-mint-400'; }
    else { pace.textContent = 'Ahead of pace'; pace.className = 'chip bg-amber-500/20 text-amber-300'; }
  } else {
    // No overall budget: the spend is the headline, with nothing to measure against.
    $('#bp-monthly').textContent = 'no overall budget';
    ['#bp-daily', '#bp-remaining', '#bp-daily-remaining', '#bp-days-left'].forEach((s) => $(s).textContent = '—');
    $('#bp-bar').style.width = '0%';
    $('#bp-pace').textContent = 'No overall budget';
    $('#bp-pace').className = 'chip bg-ink-600 text-slate-300';
  }

  // --- rows ---
  // Bars scale to the largest ticked value, and each share is of the ticked
  // total, so the visible slices always add up to what the hero reports.
  const maxVal = Math.max(1, ...shown.map((c) => Math.max(c.spent, c.budget ?? 0)));
  $('#bp-categories').innerHTML = all.map((c) => {
    const on = !budgetState.excluded.has(c.category);
    const spentW = on ? (c.spent / maxVal) * 100 : 0;
    const budgetW = on && c.budget ? (c.budget / maxVal) * 100 : 0;
    const barColor = c.over_budget ? '#e11d48' : (c.budget ? '#10b981' : '#64748b');
    const share = on && spent > 0 ? Math.round((c.spent / spent) * 1000) / 10 : 0;
    const right = c.budget
      ? `<span class="${c.over_budget ? 'text-rose-300' : 'text-slate-400'}">${inr0(c.spent)} / ${inr0(c.budget)} · ${c.budget_pct}%</span>`
      : `<span class="text-slate-500">${inr0(c.spent)} · no budget</span>`;
    return `
      <div class="${on ? '' : 'opacity-40'}">
        <div class="flex items-center justify-between text-sm mb-1 gap-3">
          <label class="flex items-center gap-2 min-w-0 cursor-pointer">
            <input type="checkbox" data-bp-cat="${esc(c.category)}" ${on ? 'checked' : ''}
                   class="accent-mint-500 w-4 h-4 shrink-0">
            <span class="font-medium truncate">${esc(c.category)}
              <span class="text-slate-500 text-xs">· ${c.txns} txn${c.txns === 1 ? '' : 's'}${on ? ` · ${share}% of shown` : ' · excluded'}</span></span>
          </label>
          ${right}
        </div>
        <div class="relative h-3 rounded-full bg-ink-700 overflow-hidden">
          <div class="absolute inset-y-0 left-0 rounded-full" style="width:${Math.min(100, spentW)}%;background:${barColor}"></div>
          ${on && c.budget ? `<div class="absolute inset-y-0" style="left:${Math.min(100, budgetW)}%;width:2px;background:#e2e8f0" title="budget"></div>` : ''}
        </div>
        ${on && c.over_budget ? `<div class="text-[11px] text-rose-300 mt-0.5">Over by ${inr0(-c.remaining)}</div>` : ''}
      </div>`;
  }).join('') || '<div class="text-slate-500 text-sm">No spending recorded this month.</div>';
}

/* Year + month pickers, driven by the months the ledger actually has. */
function renderBudgetPeriod(a) {
  const months = a.available_months ?? [a.month];
  const years = [...new Set(months.map((m) => m.slice(0, 4)))];
  const year = a.month.slice(0, 4);

  $('#bp-year').innerHTML = years.map((y) =>
    `<option value="${y}"${y === year ? ' selected' : ''}>${y}</option>`).join('');
  $('#bp-month-pick').innerHTML = months.filter((m) => m.startsWith(year + '-')).map((m) =>
    `<option value="${m}"${m === a.month ? ' selected' : ''}>${MONTHS[+m.slice(5, 7) - 1]}</option>`).join('');

  const isCurrent = a.month === new Date().toISOString().slice(0, 7);
  $('#bp-this-month').classList.toggle('hidden', isCurrent);
}

const budgetMonthsIn = (year) =>
  (budgetState.data?.available_months ?? []).filter((m) => m.startsWith(year + '-'));

$('#bp-year').addEventListener('change', (e) => {
  // Keep the month you were on when the new year has it, so stepping through
  // years compares like with like; otherwise take that year's newest month.
  const inYear = budgetMonthsIn(e.target.value);
  if (inYear.length === 0) return;
  const mm = budgetState.month?.slice(5, 7);
  budgetState.month = inYear.find((m) => m.slice(5, 7) === mm) ?? inYear[0];
  loadBudgetPage();
});
$('#bp-month-pick').addEventListener('change', (e) => { budgetState.month = e.target.value; loadBudgetPage(); });
$('#bp-this-month').addEventListener('click', () => {
  budgetState.month = new Date().toISOString().slice(0, 7);
  loadBudgetPage();
});

$('#bp-categories').addEventListener('change', (e) => {
  const cat = e.target?.dataset?.bpCat;
  if (cat === undefined) return;
  if (e.target.checked) budgetState.excluded.delete(cat);
  else budgetState.excluded.add(cat);
  renderBudgetPage();
});
$('#bp-select-all').addEventListener('click', () => { budgetState.excluded.clear(); renderBudgetPage(); });
$('#bp-select-none').addEventListener('click', () => {
  budgetState.excluded = new Set((budgetState.data?.categories ?? []).map((c) => c.category));
  renderBudgetPage();
});

function renderDashAccounts(accounts) {
  const row = (a, cls) => `
    <li class="flex justify-between items-center bg-ink-700/60 rounded-lg px-3 py-2 border-l-4"
        style="border-left-color:${a.color || ACCOUNT_FALLBACK}">
      <span class="flex items-center gap-2 min-w-0">
        ${accountDot(a.color)}
        <span class="truncate">${esc(a.name)} <span class="text-slate-500 text-xs">· ${esc(a.type)}</span></span>
      </span>
      <span class="font-semibold ${cls} tabular-nums shrink-0 ml-3">${inr(a.current_balance)}</span>
    </li>`;
  const sum = (rows) => rows.reduce((t, a) => t + a.current_balance, 0);

  const assets = accounts.filter((a) => !a.is_liability);
  const debts = accounts.filter((a) => a.is_liability);

  $('#dash-accounts').innerHTML = assets.map((a) => row(a, 'text-mint-400')).join('')
    || '<li class="text-slate-500">No accounts.</li>';
  $('#dash-assets-total').textContent = assets.length ? inr(sum(assets)) : '';

  $('#dash-loans-card').classList.toggle('hidden', debts.length === 0);
  if (debts.length === 0) return;

  $('#dash-loans').innerHTML = debts.map((a) => row(a, 'text-rose-300')).join('');
  $('#dash-liab-total').textContent = inr(sum(debts));

  // A loan balance is derived from its schedule, not from a ledger of its own —
  // worth saying, because these rows have no transactions behind them.
  const derived = debts.filter((a) => a.is_derived).length;
  $('#dash-loans-note').textContent = derived
    ? 'Outstanding principal, worked out from each loan\'s schedule.'
    : '';
}

/* ---------------- accounts ---------------- */
let accounts = [];

/* Account identity colour. It tints a dot or a rule, never text and never a
   money figure — a balance stays mint (asset) or rose (liability) whatever
   colour its account wears, so no pick can make a number unreadable. */
const ACCOUNT_FALLBACK = '#64748b';
let accountPalette = [];
let accountColors = {};                                   // id -> '#rrggbb'
const accountColor = (id) => accountColors[id] ?? ACCOUNT_FALLBACK;

/** A colour dot. Pass an explicit colour when the row already carries one. */
const accountDot = (color, cls = '') =>
  `<span class="inline-block w-2.5 h-2.5 rounded-full shrink-0 ${cls}"
         style="background:${color || ACCOUNT_FALLBACK}"></span>`;

async function loadAccounts() {
  const body = (await api('/api/accounts')).body;
  accounts = body.accounts ?? [];
  accountPalette = body.palette ?? [];
  accountColors = Object.fromEntries(accounts.map((a) => [a.id, a.color]).filter(([, c]) => c));

  // A loan account's balance is derived from its amortisation schedule, not from
  // ledger rows. It can never be an import target nor take a manual transaction,
  // so it must not appear in either picker — the server refuses it either way.
  const postable = accounts.filter((a) => !a.is_derived);
  const options = postable.map((a) => `<option value="${a.id}">${esc(a.name)} (${esc(a.type)})</option>`).join('');
  const noAcct = '<option value="">— create an account in Settings first —</option>';
  if ($('#txn-account')) $('#txn-account').innerHTML = options || noAcct;

  // The import select keeps an empty placeholder selected: an account must be a
  // deliberate choice per statement, never whatever was picked last time.
  if ($('#upload-account')) {
    const keep = $('#upload-account').value;
    $('#upload-account').innerHTML = postable.length
      ? '<option value="">— choose the account this statement belongs to —</option>' + options
      : noAcct;
    $('#upload-account').value = keep;
    refreshFileLabel?.();
  }
  $('#accounts-list').innerHTML = accounts.map((a) => `
    <li data-account="${a.id}" class="flex justify-between items-center bg-ink-700/60 rounded-lg px-3 py-2 cursor-pointer hover:bg-ink-600/70 border-l-4"
        style="border-left-color:${a.color || ACCOUNT_FALLBACK}">
      <div>
        <div class="flex items-center gap-2">${accountDot(a.color)}<span>${esc(a.name)}</span> <span class="text-slate-500 text-xs">· ${esc(a.type)}</span></div>
        <div class="text-xs text-slate-500">${inr(a.current_balance)}${a.is_liability && a.interest_rate_apr ? ` · ${a.interest_rate_apr}% APR` : ''}</div>
      </div>
      <div class="flex items-center gap-2">
        ${a.is_liability ? '<span class="chip bg-rose-500/20 text-rose-300">liability</span>' : ''}
        <span class="text-xs text-slate-500 underline">edit</span>
      </div>
    </li>`).join('') || '<li class="text-slate-500">No accounts yet.</li>';
}

$('#account-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const r = await api('/api/accounts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: f.get('name'), type: f.get('type') }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Failed to create account');
  e.target.reset();
  loadAccounts();
});

/* ---------------- account edit modal ---------------- */
$('#accounts-list').addEventListener('click', (e) => {
  const li = e.target.closest('[data-account]');
  if (li) openAccountEdit(Number(li.dataset.account));
});

/** The palette, as clickable swatches. The chosen one is written to the hidden
    `color` input, so the form submits it like any other field. */
function renderSwatches(selected) {
  const chosen = (selected || '').toLowerCase();
  // Keep a colour the account already wears even if it is not in the palette.
  const hues = accountPalette.includes(chosen) || !chosen
    ? accountPalette
    : [chosen, ...accountPalette];

  $('#acct-swatches').innerHTML = hues.map((c) => `
    <button type="button" data-swatch="${c}" title="${c}"
            class="w-7 h-7 rounded-full border-2 transition-transform ${c === chosen ? 'scale-110' : 'hover:scale-105'}"
            style="background:${c};border-color:${c === chosen ? '#e2e8f0' : 'transparent'}"></button>`).join('');
  $('#account-edit-form').color.value = chosen;
}

$('#acct-swatches').addEventListener('click', (e) => {
  const b = e.target.closest('[data-swatch]');
  if (b) renderSwatches(b.dataset.swatch);
});

function openAccountEdit(id) {
  const a = accounts.find((x) => x.id === id);
  if (!a) return;
  const f = $('#account-edit-form');
  f.id.value = a.id;
  f.name.value = a.name;
  renderSwatches(a.color);
  f.institution.value = a.institution ?? '';
  f.opening_balance.value = (a.opening_balance / 100).toFixed(2);
  f.include_in_networth.checked = !!a.include_in_networth;
  $('#acct-type-badge').textContent = a.type;
  $('#acct-debt').classList.toggle('hidden', !a.is_liability);
  if (a.is_liability) {
    f.interest_rate_apr.value = a.interest_rate_apr ?? '';
    f.credit_limit.value = a.credit_limit != null ? (a.credit_limit / 100).toFixed(2) : '';
    f.emi_amount.value = a.emi_amount != null ? (a.emi_amount / 100).toFixed(2) : '';
    f.emi_day_of_month.value = a.emi_day_of_month ?? '';
  }
  $('#account-modal').showModal();
}

$('#acct-cancel').addEventListener('click', () => $('#account-modal').close());

$('#account-edit-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const id = f.get('id');
  const body = {
    name: f.get('name'),
    color: f.get('color'),
    institution: f.get('institution'),
    opening_balance: f.get('opening_balance'),
    include_in_networth: f.get('include_in_networth') ? 1 : 0,
    interest_rate_apr: f.get('interest_rate_apr'),
    credit_limit: f.get('credit_limit'),
    emi_amount: f.get('emi_amount'),
    emi_day_of_month: f.get('emi_day_of_month'),
  };
  const r = await api(`/api/accounts/${id}`, {
    method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!r.ok) return alert(r.body.message ?? 'Save failed');
  $('#account-modal').close();
  loadAccounts();
  loadDashboard();
});

$('#acct-archive').addEventListener('click', async () => {
  const id = $('#account-edit-form').id.value;
  if (!confirm('Archive this account? It will be hidden from lists and net worth.')) return;
  await api(`/api/accounts/${id}/archive`, { method: 'POST' });
  $('#account-modal').close();
  loadAccounts();
  loadDashboard();
});

/* ---------------- archived accounts ---------------- */
$('#toggle-archived').addEventListener('click', async () => {
  const list = $('#archived-list');
  if (!list.classList.contains('hidden')) { list.classList.add('hidden'); $('#toggle-archived').textContent = 'Show archived accounts'; return; }
  const rows = (await api('/api/accounts/archived')).body.accounts ?? [];
  list.innerHTML = rows.map((a) => `
    <li class="flex justify-between items-center bg-ink-700/40 rounded-lg px-3 py-2">
      <span class="flex items-center gap-2 text-slate-400">${accountDot(a.color)}${esc(a.name)} <span class="text-xs">· ${esc(a.type)}</span></span>
      <button data-unarchive="${a.id}" class="text-xs text-mint-400 underline">restore</button>
    </li>`).join('') || '<li class="text-slate-500">No archived accounts.</li>';
  list.classList.remove('hidden');
  $('#toggle-archived').textContent = 'Hide archived accounts';
});

$('#archived-list').addEventListener('click', async (e) => {
  const id = e.target.dataset.unarchive;
  if (!id) return;
  await api(`/api/accounts/${id}/unarchive`, { method: 'POST' });
  $('#toggle-archived').click(); $('#toggle-archived').click();   // refresh the list
  loadAccounts();
  loadDashboard();
});

/* ---------------- budgets ---------------- */
async function loadBudgets() {
  // category options: overall + spending categories
  if ($('#budget-category').options.length <= 1) {
    $('#budget-category').innerHTML = '<option value="">Overall (whole month)</option>'
      + CATEGORIES.map((c) => `<option value="${c}">${c}</option>`).join('');
  }
  const b = (await api('/api/budgets')).body;
  const all = [];
  if (b.overall) all.push(b.overall);
  (b.categories ?? []).forEach((c) => all.push(c));
  $('#budgets-list').innerHTML = all.map((x) => `
    <li class="flex justify-between items-center gap-3 bg-ink-700/60 rounded-lg px-3 py-2">
      <div>
        <div>${x.category === '' ? 'Overall' : esc(x.category)} <span class="text-slate-500 text-xs">· ${inr0(x.monthly)}/mo · ${inr0(x.daily)}/day</span></div>
        <div class="text-xs ${x.over_budget ? 'text-rose-300' : 'text-slate-500'}">${inr0(x.spent)} spent this month (${x.spent_pct}%)</div>
      </div>
      <div class="flex gap-2 shrink-0 text-xs">
        <button data-edit-budget="${x.category}" data-amount="${(x.monthly / 100).toFixed(2)}" class="text-slate-400 underline">edit</button>
        <button data-del-budget="${x.category === '' ? 'overall' : encodeURIComponent(x.category)}" class="text-rose-300 underline">delete</button>
      </div>
    </li>`).join('') || '<li class="text-slate-500">No budgets set.</li>';
}

$('#budgets-list').addEventListener('click', async (e) => {
  const edit = e.target.dataset.editBudget;
  if (edit !== undefined) {
    // Load into the form; re-saving upserts (same category = update).
    $('#budget-category').value = edit;
    $('#budget-form').amount.value = e.target.dataset.amount;
    $('#budget-form').amount.focus();
    $('#budget-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return;
  }
  const cat = e.target.dataset.delBudget;
  if (!cat || !confirm('Delete this budget?')) return;
  await api(`/api/budgets/${cat}`, { method: 'DELETE' });
  loadBudgets();
  loadDashboard();
});

$('#budget-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const r = await api('/api/budgets', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ category: f.get('category'), amount: f.get('amount') }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Failed to save budget');
  e.target.reset();
  loadBudgets();
  loadDashboard();
});

/* ---------------- ledger + manual entries ----------------
 * A monthly view. The server owns the filter semantics (and the export uses the
 * same WHERE clause), so this only tracks the selection and mirrors it back.
 */
let lastLedger = [];
let ledgerState = {};        // seeded from the server's `applied` on first load
let ledgerOptions = null;
/** ids ticked for a bulk action. Cleared whenever the filter changes, because
 *  acting on rows you can no longer see is how people retag the wrong month. */
let ledgerSelection = new Set();

const ledgerQuery = () => {
  const q = new URLSearchParams();
  Object.entries(ledgerState).forEach(([k, v]) => { if (v !== '' && v != null) q.set(k, v); });
  return q;
};

async function loadLedger() {
  // Populated here, not at script-eval time: CATEGORIES is declared further down
  // the file, so touching it during evaluation hits the temporal dead zone.
  if ($('#txn-category').options.length === 0) {
    $('#txn-category').innerHTML = CATEGORIES.map((c) => `<option value="${c}">${c}</option>`).join('');
  }
  if ($('#ledger-bulk-category').options.length <= 1) {
    $('#ledger-bulk-category').innerHTML = '<option value="">Change tag to…</option>'
      + CATEGORIES.map((c) => `<option value="${c}">${c}</option>`).join('');
  }
  await renderLedger();
}

/* The summary strip. Extracted so an in-place edit (changing one row's tag)
   can refresh the totals without rebuilding every row of the table.

   These totals stay RAW — they are what actually moved through the account, so
   they reconcile with the balance. Blacklisted rows are counted here and called
   out separately, rather than quietly dropped from a ledger. */
function renderLedgerSummary(t) {
  $('#ledger-summary').innerHTML = t.txns === 0 ? '' : `
    <span class="text-slate-400">${t.txns} transaction${t.txns === 1 ? '' : 's'}</span>
    <span class="text-mint-400">In ${inr0(t.income)}</span>
    <span class="text-rose-300">Out ${inr0(t.expense)}</span>
    <span class="${t.net < 0 ? 'text-rose-300' : 'text-mint-400'}">Net ${t.net >= 0 ? '+' : '−'}${inr0(Math.abs(t.net))}</span>
    ${(t.excluded_txns || ledgerState.excluded) ? `<button id="ledger-excluded-toggle"
        class="text-xs ${ledgerState.excluded ? 'text-amber-300 underline' : 'text-amber-300/70 underline decoration-dotted'}">
        ⊘ ${ledgerState.excluded ? 'showing only excluded — show all' : `${t.excluded_txns} excluded from analysis`}</button>` : ''}`;

  $('#ledger-excluded-toggle')?.addEventListener('click', () => {
    ledgerState.excluded = ledgerState.excluded === '1' ? '' : '1';
    renderLedger();
  });
}

/** @param {boolean} quiet skip the busy veil (search-as-you-type) */
async function renderLedger(quiet = false) {
  // Deep-linking to /ledger runs this before boot fetches accounts.
  if (!accounts.length) await loadAccounts();

  const r = (await api('/api/transactions?' + ledgerQuery(), { quiet })).body;
  const rows = r.transactions ?? [];
  lastLedger = rows;

  // Drop any selection that scrolled out of the current filter.
  const visible = new Set(rows.map((t) => t.id));
  ledgerSelection = new Set([...ledgerSelection].filter((id) => visible.has(id)));

  // The server is the source of truth for what was actually applied — it
  // normalises a bad month to "all", and picks the default period on first load.
  ledgerState = { ...r.applied, account_id: r.applied.account_id ?? '' };
  ledgerOptions = r.filters;
  syncLedgerControls();

  // Half-typed amounts are ignored rather than applied. Say so, or the ledger
  // looks like it is showing everything for no reason.
  $('#ledger-amount-hint').classList.toggle('hidden', !r.amount_invalid);
  $('#ledger-amount').classList.toggle('border-amber-500/60', !!r.amount_invalid);
  $('#ledger-amount').classList.toggle('border-ink-600', !r.amount_invalid);

  $('#ledger-export').href = '/api/transactions/export?' + ledgerQuery();

  const t = r.totals;
  renderLedgerSummary(t);

  $('#ledger-truncated').classList.toggle('hidden', !r.truncated);
  if (r.truncated) {
    $('#ledger-truncated').textContent =
      `Showing the most recent ${rows.length} of ${t.txns} matching transactions. The totals above cover all ${t.txns}. Narrow the period to see the rest.`;
  }

  // A running balance only means anything within one account's own statement.
  const showBalance = !!ledgerState.account_id;
  $('#ledger-balance-head').classList.toggle('hidden', !showBalance);

  $('#ledger-empty').classList.toggle('hidden', rows.length > 0);
  if (rows.length === 0) $('#ledger-empty').textContent = `No transactions match this filter.`;

  const btn = 'inline-flex items-center justify-center w-8 h-8 rounded-lg border border-ink-600 '
    + 'bg-ink-700/60 hover:bg-ink-600 text-base leading-none transition-colors';

  $('#ledger-rows').innerHTML = rows.map((t) => {
    const off = t.is_excluded === 1;

    // The loans seam. A linked row wears its instalment; an unlinked EMI debit
    // offers to become one. Everything else shows neither.
    const linked = t.loan_id != null;
    const linkable = !linked && t.category === 'emi' && t.cashflow === 'debit';
    const chip = linked
      ? ` · <button data-unlink-txn="${t.id}" title="Paid instalment #${t.loan_period} of ${esc(t.loan_name)} — click to unlink"
             class="text-sky-300 hover:underline">🏦 ${esc(t.loan_name)} #${t.loan_period}</button>` : '';

    // The investments seam, the same idea on the asset side: an investment-tagged
    // debit that no holding claims offers to become a contribution.
    const invLinked = t.investment_id != null;
    const invLinkable = !invLinked && !linked && t.category === 'investment' && t.cashflow === 'debit';
    const invChip = invLinked
      ? ` · <button data-unlink-inv="${t.id}" title="Contribution to ${esc(t.investment_name)} — click to unlink"
             class="text-emerald-300 hover:underline">💹 ${esc(t.investment_name)}</button>` : '';

    return `
    <tr class="border-b border-ink-700 ${off ? 'opacity-45' : ''}">
      <td class="py-2 pr-2"><input type="checkbox" data-pick="${t.id}" class="accent-mint-500 w-4 h-4 align-middle"
        ${ledgerSelection.has(t.id) ? 'checked' : ''}></td>
      <td class="py-2 pr-3 whitespace-nowrap text-slate-400">${esc(t.txn_date)}</td>
      <td class="py-2 pr-3">
        <span class="${off ? 'line-through decoration-slate-600' : ''}">${esc(t.description)}</span>
        <div class="text-xs text-slate-500">
          ${[t.is_self_transfer ? 'self' : '', t.source === 'manual' ? 'manual' : '',
              off ? '<span class="text-amber-300/80">excluded from analysis</span>' : '']
              .filter(Boolean).join(' · ')}${chip}${invChip}
        </div></td>
      <td class="py-2 pr-3">
        <div class="flex items-center gap-1">
          <select data-tag-select="${t.id}" data-current="${esc(t.category)}" title="Change tag"
                  class="bg-ink-700 border border-ink-600 rounded-md px-1.5 py-1 text-xs max-w-[9.5rem]">
            <option value="${esc(t.category)}" selected>${esc(t.category)}</option>
          </select>
          <button data-tag="${esc(t.category)}" title="Filter the ledger by this tag"
                  class="text-slate-500 hover:text-slate-200 px-1 leading-none text-sm">&#8981;</button>
        </div>
      </td>
      <td class="py-2 pr-3 text-slate-500 text-xs">
        <span class="flex items-center gap-1.5">${accountDot(t.account_color)}<span class="truncate">${esc(t.account_name)}</span></span>
      </td>
      <td class="py-2 pr-3 text-slate-400 text-xs">${esc(t.mode)}</td>
      <td class="py-2 pr-3 text-right font-semibold tabular-nums ${off ? 'text-slate-500' : (t.cashflow === 'credit' ? 'text-mint-400' : 'text-rose-300')}">
        ${t.cashflow === 'credit' ? '+' : '−'}${inr(t.amount)}</td>
      ${showBalance ? `<td class="py-2 pr-3 text-right tabular-nums text-slate-500">${t.balance_after == null ? '—' : inr0(t.balance_after)}</td>` : ''}
      <td class="py-2 pr-1 text-right whitespace-nowrap">
        ${linkable ? `<button data-link-txn="${t.id}" class="${btn} text-sky-300 mr-1"
                title="Mark a loan instalment as paid by this debit">🏦</button>` : ''}
        ${invLinkable ? `<button data-link-inv="${t.id}" class="${btn} text-emerald-300 mr-1"
                title="Tag this debit as a contribution to a holding">💹</button>` : ''}
        <button data-exclude-txn="${t.id}" data-on="${off ? 1 : 0}" class="${btn} ${off ? 'text-amber-300 border-amber-500/40' : 'text-slate-400'} mr-1"
                title="${off ? 'Include in analysis again' : 'Exclude from all income/expense figures (balance unaffected)'}">⊘</button>
        <button data-edit-txn="${t.id}" class="${btn} text-slate-300 mr-1" title="Edit">✎</button>
        <button data-del-txn="${t.id}" class="${btn} text-rose-300 hover:border-rose-500/50" title="Delete">✕</button></td>
    </tr>`;
  }).join('');

  syncLedgerSelection();
}

/* ---- tag filter (multi-select) ----
   The wire format is a comma-joined list on the single `category` param, so a
   one-tag filter is byte-identical to what the old single select sent, and
   every deep link and saved CSV export URL keeps working. */
const selectedTags = () => (ledgerState.category || '').split(',').filter(Boolean);

function setTags(tags) {
  // Dedupe, and keep the click order — the server echoes the list back as-is.
  ledgerState.category = [...new Set(tags)].join(',');
  renderLedger();
}

function toggleTag(tag) {
  const tags = selectedTags();
  setTags(tags.includes(tag) ? tags.filter((t) => t !== tag) : [...tags, tag]);
}

function renderTagFilter() {
  const chosen = new Set(selectedTags());

  $('#ledger-category-label').textContent =
    chosen.size === 0 ? 'All tags'
    : chosen.size === 1 ? [...chosen][0]
    : `${chosen.size} tags`;

  // A tag you have selected must stay listed even if the current filter leaves
  // it with no rows — otherwise it vanishes from the menu and cannot be cleared.
  const known = ledgerOptions.categories.map((c) => c.category);
  const rows = [...ledgerOptions.categories, ...[...chosen].filter((t) => !known.includes(t)).map((t) => ({ category: t, n: 0 }))];

  $('#ledger-category-options').innerHTML = rows.map((c) => `
    <label class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-sm hover:bg-ink-700 cursor-pointer">
      <input type="checkbox" data-tag-option="${esc(c.category)}" ${chosen.has(c.category) ? 'checked' : ''}
             class="accent-mint-500 w-4 h-4 shrink-0">
      <span class="truncate">${esc(c.category)}</span>
      <span class="ml-auto text-xs text-slate-500 shrink-0">${c.n}</span>
    </label>`).join('');

  $('#ledger-category-btn').classList.toggle('border-mint-500/50', chosen.size > 0);
  $('#ledger-category-btn').classList.toggle('border-ink-600', chosen.size === 0);
}

function openTagMenu(open) {
  $('#ledger-category-menu').classList.toggle('hidden', !open);
  $('#ledger-category-btn').setAttribute('aria-expanded', open ? 'true' : 'false');
}

function syncLedgerControls() {
  const s = ledgerState;

  $('#ledger-year').innerHTML = '<option value="all">All years</option>'
    + ledgerOptions.years.map((y) => `<option value="${y}"${y === s.year ? ' selected' : ''}>${y}</option>`).join('');

  $('#ledger-month').innerHTML = '<option value="all"' + (s.month === 'all' ? ' selected' : '') + '>All months</option>'
    + MONTHS.map((m, i) => {
      const v = String(i + 1).padStart(2, '0');
      return `<option value="${v}"${v === s.month ? ' selected' : ''}>${m}</option>`;
    }).join('');
  $('#ledger-month').disabled = s.year === 'all';

  renderTagFilter();

  $('#ledger-account').innerHTML = '<option value="">All accounts</option>'
    + accounts.map((a) => `<option value="${a.id}"${String(a.id) === String(s.account_id) ? ' selected' : ''}>${esc(a.name)}</option>`).join('');

  $('#ledger-cashflow').value = s.cashflow ?? '';

  if ($('#ledger-search').value !== s.search) $('#ledger-search').value = s.search ?? '';
  if ($('#ledger-amount').value !== s.amount) $('#ledger-amount').value = s.amount ?? '';

  const label = s.year === 'all' ? 'All time'
    : s.month === 'all' ? s.year
    : `${MONTHS[+s.month - 1]} ${s.year}`;
  $('#ledger-title').textContent = label;

  // Stepping only makes sense while a single month is in view.
  const stepping = s.year !== 'all' && s.month !== 'all';
  ['#ledger-prev', '#ledger-next'].forEach((sel) => {
    $(sel).disabled = !stepping;
    $(sel).classList.toggle('opacity-30', !stepping);
  });
}

/** Move one month, rolling the year over at the boundaries. */
function stepLedgerMonth(delta) {
  // The buttons exist before the first render resolves; a click then would
  // read `years` off a null options object.
  if (!ledgerOptions || ledgerState.year === 'all' || ledgerState.month === 'all') return;

  let y = +ledgerState.year;
  let m = +ledgerState.month + delta;
  if (m < 1) { m = 12; y -= 1; }
  if (m > 12) { m = 1; y += 1; }
  if (!ledgerOptions.years.includes(String(y))) return;   // no data that far out
  ledgerState.year = String(y);
  ledgerState.month = String(m).padStart(2, '0');
  renderLedger();
}

$('#ledger-prev').addEventListener('click', () => stepLedgerMonth(-1));
$('#ledger-next').addEventListener('click', () => stepLedgerMonth(1));

$('#ledger-year').addEventListener('change', (e) => {
  ledgerState.year = e.target.value;
  if (e.target.value === 'all') ledgerState.month = 'all';
  renderLedger();
});
$('#ledger-month').addEventListener('change', (e) => { ledgerState.month = e.target.value; renderLedger(); });
$('#ledger-account').addEventListener('change', (e) => { ledgerState.account_id = e.target.value; renderLedger(); });
$('#ledger-cashflow').addEventListener('change', (e) => { ledgerState.cashflow = e.target.value; renderLedger(); });

$('#ledger-category-btn').addEventListener('click', (e) => {
  e.stopPropagation();
  openTagMenu($('#ledger-category-menu').classList.contains('hidden'));
});
$('#ledger-category-clear').addEventListener('click', () => { openTagMenu(false); setTags([]); });
// The menu stays open across ticks: choosing three tags should not cost three trips.
$('#ledger-category-menu').addEventListener('click', (e) => e.stopPropagation());
$('#ledger-category-options').addEventListener('change', (e) => {
  const tag = e.target.dataset?.tagOption;
  if (tag) toggleTag(tag);
});
document.addEventListener('click', (e) => {
  if (!e.target?.closest?.('#ledger-category-wrap')) openTagMenu(false);
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') openTagMenu(false); });

/* ---- inline tag editing ----
   Each row carries a real <select>, but it holds ONLY its current value until
   you touch it. Rendering all 35 categories into every one of up to 1000 rows
   would be 35,000 <option> nodes and ~1.6 MB of markup — measured at ~10x the
   render cost of the one-option version — for a list you only ever see one of
   at a time. They are filled in on first interaction instead.

   The save goes through /api/transactions/bulk rather than PATCH because that
   endpoint also pins tag_source='manual'. Without that pin a later retag pass
   would be free to overwrite the category you just chose. Neither route touches
   the amount, the hash or the balance, so nothing is recomputed. */
function fillTagOptions(sel) {
  if (sel.dataset.filled === '1') return;
  const current = sel.dataset.current;
  sel.innerHTML = CATEGORIES.map((c) =>
    `<option value="${c}"${c === current ? ' selected' : ''}>${c}</option>`).join('');
  // A category no longer in the enum (renamed, or seeded differently) would
  // otherwise silently vanish from the row and look like a blank tag.
  if (!CATEGORIES.includes(current)) {
    sel.insertAdjacentHTML('afterbegin', `<option value="${esc(current)}" selected>${esc(current)}</option>`);
  }
  sel.value = current;
  sel.dataset.filled = '1';
}

$('#ledger-rows').addEventListener('mousedown', (e) => {
  const sel = e.target.closest('[data-tag-select]');
  if (sel) fillTagOptions(sel);
}, true);
$('#ledger-rows').addEventListener('focusin', (e) => {
  const sel = e.target.closest('[data-tag-select]');
  if (sel) fillTagOptions(sel);
});

$('#ledger-rows').addEventListener('change', async (e) => {
  const sel = e.target.closest('[data-tag-select]');
  if (!sel) return;
  const id = +sel.dataset.tagSelect;
  const previous = sel.dataset.current;
  const chosen = sel.value;
  if (chosen === previous) return;

  sel.disabled = true;
  const r = await api('/api/transactions/bulk', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: [id], category: chosen }), quiet: true,
  });
  sel.disabled = false;

  if (!r.ok || (r.body.updated ?? 0) < 1) {
    sel.value = previous;                       // put it back; the row is unchanged
    alert(r.body?.message ?? 'Could not change the tag.');
    return;
  }

  // Keep the row's own state in step so the filter button and a later edit
  // both see the new value without a full re-render.
  sel.dataset.current = chosen;
  const row = lastLedger.find((t) => t.id === id);
  if (row) { row.category = chosen; row.tag_source = 'manual'; }
  const filterBtn = sel.parentElement.querySelector('[data-tag]');
  if (filterBtn) filterBtn.dataset.tag = chosen;

  // The summary totals and the tag filter counts are now stale. Refresh quietly
  // (no busy veil) unless the row would drop out of the current tag filter, in
  // which case a full re-render is what the user expects to see.
  const tags = selectedTags();
  if (tags.length && !tags.includes(chosen)) renderLedger(true);
  else refreshLedgerTotals();
});

/** Re-pull just the totals/filter counts after an in-place tag change. */
async function refreshLedgerTotals() {
  const r = await api('/api/transactions?' + ledgerQuery(), { quiet: true });
  if (!r.ok) return;
  ledgerOptions = r.body.filters;
  renderLedgerSummary(r.body.totals);
  renderTagFilter();
}

let ledgerSearchTimer;
$('#ledger-search').addEventListener('input', (e) => {
  clearTimeout(ledgerSearchTimer);
  ledgerSearchTimer = setTimeout(() => {
    ledgerState.search = e.target.value.trim();
    renderLedger(true);          // quiet: blurring the page per keystroke is unusable
  }, 300);
});

let ledgerAmountTimer;
$('#ledger-amount').addEventListener('input', (e) => {
  clearTimeout(ledgerAmountTimer);
  ledgerAmountTimer = setTimeout(() => {
    ledgerState.amount = e.target.value.trim();
    renderLedger(true);
  }, 300);
});

$('#ledger-rows').addEventListener('click', async (e) => {
  // Click a row's tag to add it to the filter; click it again to drop it.
  const tag = e.target.dataset.tag;
  if (tag) return toggleTag(tag);

  // Blacklist / restore one transaction. The balance is recomputed from every
  // row regardless, so nothing here can move it.
  const excludeId = e.target.dataset.excludeTxn;
  if (excludeId) {
    const turningOn = e.target.dataset.on !== '1';
    const r = await api(`/api/transactions/${excludeId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ is_excluded: turningOn ? 1 : 0 }),
    });
    if (!r.ok) return alert(r.body.message ?? 'Could not update the transaction.');
    await renderLedger();
    loadDashboard();
    return;
  }

  // The loans seam. Neither of these touches the transaction itself — they only
  // add or remove a row in loan_payments, so no balance and no hash changes.
  const linkId = e.target.dataset.linkTxn;
  if (linkId) return openLinkModal(+linkId);
  const unlinkId = e.target.dataset.unlinkTxn;
  if (unlinkId) return unlinkFromLedger(+unlinkId);

  // The investments seam, symmetrical: link/unlink a contribution, txn untouched.
  const invLinkId = e.target.dataset.linkInv;
  if (invLinkId) return openInvestLink(+invLinkId);
  const invUnlinkId = e.target.dataset.unlinkInv;
  if (invUnlinkId) return unlinkInvestFromLedger(+invUnlinkId);

  const editId = e.target.dataset.editTxn;
  if (editId) { openTxnModal(lastLedger.find((t) => String(t.id) === editId)); return; }
  const id = e.target.dataset.delTxn;
  if (!id || !confirm('Delete this transaction? Balances will be recomputed.')) return;
  await api(`/api/transactions/${id}`, { method: 'DELETE' });
  renderLedger();
  loadAccounts();
  loadDashboard();
});


/* ---------------- ledger bulk actions ---------------- */

/** Reflect `ledgerSelection` into the bulk bar, the row boxes and select-all. */
function syncLedgerSelection() {
  const n = ledgerSelection.size;
  const total = lastLedger.length;

  $('#ledger-bulk').classList.toggle('hidden', n === 0);
  $('#ledger-bulk-count').textContent = `${n} selected`;
  $('#ledger-bulk-apply').disabled = !$('#ledger-bulk-category').value;

  const all = $('#ledger-select-all');
  all.checked = n > 0 && n === total;
  // Partial selection reads as neither on nor off.
  all.indeterminate = n > 0 && n < total;
}

$('#ledger-select-all').addEventListener('change', (e) => {
  ledgerSelection = e.target.checked ? new Set(lastLedger.map((t) => t.id)) : new Set();
  document.querySelectorAll('#ledger-rows [data-pick]').forEach((b) => { b.checked = e.target.checked; });
  syncLedgerSelection();
});

$('#ledger-rows').addEventListener('change', (e) => {
  const id = e.target.dataset.pick;
  if (!id) return;
  if (e.target.checked) ledgerSelection.add(Number(id));
  else ledgerSelection.delete(Number(id));
  syncLedgerSelection();
});

$('#ledger-bulk-category').addEventListener('change', syncLedgerSelection);
$('#ledger-bulk-clear').addEventListener('click', () => {
  ledgerSelection = new Set();
  document.querySelectorAll('#ledger-rows [data-pick]').forEach((b) => { b.checked = false; });
  syncLedgerSelection();
});

/** @param {object} payload  {category} or {is_excluded} */
async function ledgerBulk(payload, describe) {
  const ids = [...ledgerSelection];
  if (ids.length === 0) return;
  if (!confirm(`${describe} for ${ids.length} transaction${ids.length === 1 ? '' : 's'}?`)) return;

  const r = await api('/api/transactions/bulk', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids, ...payload }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Bulk update failed.');

  ledgerSelection = new Set();
  await renderLedger();
  loadDashboard();
}

$('#ledger-bulk-apply').addEventListener('click', () => {
  const category = $('#ledger-bulk-category').value;
  if (!category) return;
  ledgerBulk({ category }, `Set the tag to "${category}"`);
});
$('#ledger-bulk-exclude').addEventListener('click', () =>
  ledgerBulk({ is_excluded: 1 }, 'Exclude from all income/expense figures'));
$('#ledger-bulk-include').addEventListener('click', () =>
  ledgerBulk({ is_excluded: 0 }, 'Include in income/expense figures again'));

/* ---------------- add/edit transaction modal ---------------- */
function openTxnModal(txn = null) {
  const f = $('#txn-form');
  f.reset();
  if ($('#txn-category').options.length === 0) {
    $('#txn-category').innerHTML = CATEGORIES.map((c) => `<option value="${c}">${c}</option>`).join('');
  }
  if (txn) {
    $('#txn-modal-title').textContent = 'Edit transaction';
    f.id.value = txn.id;
    f.account_id.value = txn.account_id ?? '';
    f.txn_date.value = txn.txn_date;
    f.cashflow.value = txn.cashflow;
    f.description.value = txn.description;
    f.amount.value = (txn.amount / 100).toFixed(2);
    f.category.value = txn.category;
    f.is_self_transfer.checked = !!txn.is_self_transfer;
    f.account_id.disabled = true;   // moving a txn between accounts isn't supported
  } else {
    $('#txn-modal-title').textContent = 'Add transaction';
    f.id.value = '';
    f.txn_date.value = new Date().toISOString().slice(0, 10);
    f.account_id.disabled = false;
  }
  $('#txn-modal').showModal();
}

$('#txn-add-btn').addEventListener('click', () => {
  if (!accounts.length) return alert('Create an account first (Settings).');
  openTxnModal();
});
$('#txn-cancel').addEventListener('click', () => $('#txn-modal').close());

$('#txn-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const id = f.get('id');
  const payload = {
    txn_date: f.get('txn_date'),
    description: f.get('description'),
    amount: f.get('amount'),
    cashflow: f.get('cashflow'),
    category: f.get('category'),
    is_self_transfer: f.get('is_self_transfer') ? 1 : 0,
  };
  let r;
  if (id) {
    r = await api(`/api/transactions/${id}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
  } else {
    payload.account_id = Number(f.get('account_id'));
    r = await api('/api/transactions', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
  }
  if (!r.ok) return alert(r.body.message ?? 'Save failed');
  $('#txn-modal').close();
  renderLedger();
  loadAccounts();
  loadDashboard();
});

/* ---------------- CSV import wizard ----------------
 * Three steps, and nothing is written until the last one:
 *   choose -> POST /api/imports        (stores the file, returns a suggested mapping)
 *   map    -> POST /api/imports/:id/validate   (dry run: parse + tag + balance check)
 *   check  -> POST /api/imports/:id/stage      (fills the review queue)
 */
const fileInput = $('#file-input');
const dropzone = $('#dropzone');

// The live import being mapped. `mapping` is the document the server validates.
let imp = null;   // { uploadId, headers, sample, mapping, dateFormats, fileMeta }

const ROLES = [
  ['', '— ignore —'], ['date', 'Date'], ['description', 'Description'],
  ['debit', 'Debit'], ['credit', 'Credit'], ['amount', 'Amount'],
  ['indicator', 'Dr/Cr indicator'], ['balance', 'Balance'], ['reference', 'Reference'],
];

function refreshFileLabel() {
  const f = fileInput.files[0];
  $('#file-names').textContent = f ? f.name : '';
  // An account is mandatory: committing a statement into the wrong one silently
  // interleaves two banks' balance chains, and nothing downstream can tell.
  $('#upload-btn').disabled = !f || !$('#upload-account').value;
}
fileInput.addEventListener('change', refreshFileLabel);
$('#upload-account').addEventListener('change', refreshFileLabel);
['dragover', 'dragleave', 'drop'].forEach((ev) =>
  dropzone.addEventListener(ev, (e) => {
    e.preventDefault();
    dropzone.classList.toggle('border-mint-500', ev === 'dragover');
    if (ev === 'drop') { fileInput.files = e.dataTransfer.files; refreshFileLabel(); }
  }));

function showStep(step) {
  $('#imp-choose').classList.toggle('hidden', step !== 'choose');
  $('#imp-map').classList.toggle('hidden', step !== 'map');
  $('#imp-check').classList.toggle('hidden', step !== 'check');
}

$('#upload-btn').addEventListener('click', async () => {
  const f = fileInput.files[0];
  if (!f || !$('#upload-account').value) return;
  const fd = new FormData();
  fd.append('statement', f);
  fd.append('account_id', $('#upload-account').value);

  $('#upload-btn').disabled = true;
  $('#upload-progress').classList.remove('hidden');
  $('#upload-error').classList.add('hidden');

  const r = await api('/api/imports', { method: 'POST', body: fd });
  $('#upload-progress').classList.add('hidden');
  $('#upload-btn').disabled = false;

  if (!r.ok) {
    $('#upload-error').textContent = r.body.message ?? 'Could not read that file.';
    $('#upload-error').classList.remove('hidden');
    loadUploads();
    return;
  }
  openMapper(r.body);
  loadUploads();
});

/** Render the mapping screen from a /preview (or /remap) payload. */
function openMapper(pv) {
  imp = {
    uploadId: pv.upload.id,
    headers: pv.file.headers,
    sample: pv.sample,
    mapping: pv.mapping,
    dateFormats: pv.date_formats,
    accountId: pv.upload.account_id,
  };
  if (imp.accountId) $('#upload-account').value = String(imp.accountId);

  $('#imp-filename').textContent = pv.upload.original_name;
  $('#imp-filemeta').textContent =
    `${pv.file.total_rows} rows · ${pv.file.headers.length} columns · ${pv.file.encoding} · `
    + `delimiter "${pv.file.delimiter === '\t' ? 'tab' : pv.file.delimiter}"`
    + (pv.file.malformed_rows ? ` · ${pv.file.malformed_rows} row(s) will be repaired` : '');

  const m = $('#imp-matched');
  if (pv.matched_format) {
    const exact = pv.matched_format.match === 'exact';
    m.className = 'mb-3 text-sm rounded-lg p-3 border '
      + (exact ? 'bg-mint-500/10 border-mint-500/30 text-mint-400'
               : 'bg-sky-500/10 border-sky-500/30 text-sky-300');
    m.innerHTML = exact
      ? `✓ Recognised as <b>${esc(pv.matched_format.name)}</b> — mapping applied. Check it and continue.`
      : `↷ Columns changed, but <b>${esc(pv.matched_format.name)}</b> still fits. Mapping applied unchanged.`;
    m.classList.remove('hidden');
  } else {
    m.className = 'mb-3 text-sm rounded-lg p-3 border bg-ink-700/50 border-ink-600 text-slate-300';
    m.innerHTML = 'New layout. We guessed the columns below — confirm them and we\'ll remember it.';
    m.classList.remove('hidden');
  }

  const notes = $('#imp-notes');
  notes.innerHTML = (pv.notes ?? []).map((n) => `<li>${esc(n)}</li>`).join('');
  notes.classList.toggle('hidden', (pv.notes ?? []).length === 0);

  // Derive the layout name from THIS file. Leaving the previous import's name
  // in place is how an HDFC layout ends up saved as "FEDERAL (2)".
  $('#chk-formatname').value = pv.matched_format
    ? pv.matched_format.name
    : (pv.upload.original_name.replace(/\.[^.]+$/, '').split(/[_\-\s]+/)[0] || 'My bank');

  $('#imp-cleanocr').checked = !!pv.mapping.clean_ocr;
  $('#imp-debitvalues').value = (pv.mapping.amount.debit_values ?? []).join(', ');
  renderDateFormats();
  renderGrid();
  showStep('map');
  $('#imp-map').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** role currently assigned to a column name, derived from the mapping */
function roleOf(col) {
  const m = imp.mapping;
  if (m.date.column === col) return 'date';
  if ((m.description.columns ?? []).includes(col)) return 'description';
  if (m.amount.debit === col) return 'debit';
  if (m.amount.credit === col) return 'credit';
  if (m.amount.amount === col) return 'amount';
  if (m.amount.indicator === col) return 'indicator';
  if (m.balance.column === col) return 'balance';
  if (m.reference.column === col) return 'reference';
  return '';
}

/** Assign a role, clearing whoever held it before (description may repeat). */
function setRole(col, role) {
  const m = imp.mapping;
  const prev = roleOf(col);
  if (prev === 'description') m.description.columns = m.description.columns.filter((c) => c !== col);
  else if (prev === 'date') m.date.column = null;
  else if (prev === 'balance') m.balance.column = null;
  else if (prev === 'reference') m.reference.column = null;
  else if (prev) m.amount[prev] = null;

  const single = { date: () => (m.date.column = col), balance: () => (m.balance.column = col),
                   reference: () => (m.reference.column = col) };
  if (role === 'description') {
    if (!m.description.columns.includes(col)) m.description.columns.push(col);
  } else if (single[role]) {
    imp.headers.forEach((h) => { if (h !== col && roleOf(h) === role) setRole(h, ''); });
    single[role]();
  } else if (role) {
    imp.headers.forEach((h) => { if (h !== col && roleOf(h) === role) setRole(h, ''); });
    m.amount[role] = col;
  }

  // Amount style follows from which columns were tagged: two columns => the
  // usual debit/credit pair; one column + an indicator => Dr/Cr; one alone =>
  // the sign carries the direction.
  m.amount.mode = (m.amount.debit && m.amount.credit) ? 'debit_credit'
                : (m.amount.amount && m.amount.indicator) ? 'indicator'
                : m.amount.amount ? 'signed' : m.amount.mode;

  // A skip rule points at a description column; keep it pointing at a live one.
  const desc = m.description.columns[0] ?? null;
  m.skip_rows = desc ? (m.skip_rows ?? []).map((r) => ({ ...r, column: desc })) : [];

  renderGrid();
  renderDateFormats();
}

const MODE_LABEL = {
  debit_credit: 'Separate debit &amp; credit columns',
  signed: 'One amount column; a minus sign means money out',
  indicator: 'One amount column + a Dr/Cr column',
};

function renderGrid() {
  const m = imp.mapping;
  $('#imp-role-row').innerHTML = imp.headers.map((h, i) => {
    const role = roleOf(h);
    const tone = role ? 'border-mint-500/60 text-mint-400' : 'border-ink-600 text-slate-500';
    return `<th class="pr-2 pb-1 align-bottom">
      <select data-col="${i}" class="bg-ink-700 border ${tone} rounded px-1.5 py-1 text-xs w-full min-w-[7.5rem]">
        ${ROLES.map(([v, l]) => `<option value="${v}"${v === role ? ' selected' : ''}>${l}</option>`).join('')}
      </select></th>`;
  }).join('');

  $('#imp-head-row').innerHTML = imp.headers
    .map((h) => `<th class="pr-2 pb-1.5 font-normal whitespace-nowrap border-b border-ink-600">${esc(h)}</th>`).join('');

  $('#imp-sample-rows').innerHTML = imp.sample.slice(0, 6).map((row) =>
    `<tr>${imp.headers.map((_, i) =>
      `<td class="pr-2 py-1 whitespace-nowrap max-w-[16rem] truncate border-b border-ink-700/50">${esc(row[i] ?? '')}</td>`
    ).join('')}</tr>`).join('');

  $('#imp-amountmode').innerHTML = MODE_LABEL[m.amount.mode] ?? '—';
  $('#imp-indicator-wrap').classList.toggle('hidden', m.amount.mode !== 'indicator');

  const missing = !m.date.column || !(m.description.columns ?? []).length
    || (m.amount.mode === 'debit_credit' && !(m.amount.debit && m.amount.credit))
    || (m.amount.mode !== 'debit_credit' && !m.amount.amount);
  $('#imp-validate').disabled = missing;
}

$('#imp-role-row').addEventListener('change', (e) => {
  if (e.target.dataset.col === undefined) return;
  setRole(imp.headers[Number(e.target.dataset.col)], e.target.value);
});

/** Date-format picker, annotated with how each candidate reads the first date. */
function renderDateFormats() {
  const sel = $('#imp-dateformat');
  const col = imp.mapping.date.column;
  const idx = imp.headers.indexOf(col);
  const cur = imp.mapping.date.format;
  const list = imp.dateFormats.includes(cur) || !cur ? imp.dateFormats : [cur, ...imp.dateFormats];

  sel.innerHTML = list.map((f) => `<option value="${f}"${f === cur ? ' selected' : ''}>${f}</option>`).join('');
  const sample = idx >= 0 ? (imp.sample.find((r) => (r[idx] ?? '').trim())?.[idx] ?? '') : '';
  $('#imp-datesample').textContent = sample ? `First value in "${col}": ${sample.trim()}` : '';
}
$('#imp-dateformat').addEventListener('change', (e) => { imp.mapping.date.format = e.target.value; });
$('#imp-cleanocr').addEventListener('change', (e) => { imp.mapping.clean_ocr = e.target.checked; });
$('#imp-debitvalues').addEventListener('change', (e) => {
  imp.mapping.amount.debit_values = e.target.value.split(',').map((v) => v.trim()).filter(Boolean);
});

$('#imp-cancel').addEventListener('click', async () => {
  if (!imp || !confirm('Discard this upload?')) return;
  await api(`/api/imports/${imp.uploadId}`, { method: 'DELETE' });
  imp = null;
  fileInput.value = '';
  refreshFileLabel();
  showStep('choose');
  loadUploads();
});

/* ---- step 3: dry run ---- */
$('#imp-validate').addEventListener('click', async () => {
  $('#imp-map-error').classList.add('hidden');
  $('#imp-validate').disabled = true;
  $('#imp-validate').textContent = 'Checking…';

  const r = await api(`/api/imports/${imp.uploadId}/validate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ mapping: imp.mapping, account_id: $('#upload-account').value || null }),
  });
  $('#imp-validate').disabled = false;
  $('#imp-validate').textContent = 'Check this mapping';

  if (!r.ok) {
    $('#imp-map-error').textContent = r.body.message ?? 'That mapping did not work.';
    $('#imp-map-error').classList.remove('hidden');
    return;
  }
  renderCheck(r.body);
});

function renderCheck(v) {
  const rep = v.report;
  imp.mapping = v.mapping;   // server-normalised
  imp.account = v.account;

  // Which account is about to receive these rows, stated plainly.
  const acctBox = $('#chk-account');
  if (v.account) {
    const already = v.account.existing_transactions;
    acctBox.innerHTML = `Importing into <b class="text-mint-400">${esc(v.account.name)}</b>`
      + (already ? ` — which already holds ${already} transaction${already === 1 ? '' : 's'}.` : ' — currently empty.')
      + ' <span class="text-slate-500">Wrong account? Go back and change it.</span>';
  } else {
    acctBox.innerHTML = '<span class="text-rose-300">No account selected.</span>';
  }

  // Offer the opening balance the statement itself implies.
  const openBox = $('#chk-opening');
  const canSet = v.account && v.account.can_set_opening;
  openBox.classList.toggle('hidden', !canSet);
  if (canSet) {
    $('#chk-opening-amount').textContent = inr(v.account.implied_opening_balance);
    $('#chk-set-opening').checked = true;
  }

  $('#chk-parsed').textContent = rep.parsed;
  $('#chk-dupes').textContent = v.duplicates;
  $('#chk-skipped').textContent = `${rep.skipped + rep.errors}`;
  $('#chk-untagged').textContent = `${v.untagged}`;

  const bc = rep.balance_check;
  const box = $('#chk-balance');
  if (!bc.performed) {
    box.className = 'text-sm rounded-lg p-3 border mb-3 bg-ink-700/50 border-ink-600 text-slate-400';
    box.innerHTML = `Balance check skipped (${esc(bc.reason)}). Map the running-balance column to catch `
      + 'dropped or misparsed rows automatically.';
  } else if (bc.inverted) {
    // Reconciles perfectly, but only backwards — the surest sign of a swap.
    box.className = 'text-sm rounded-lg p-3 border mb-3 bg-rose-500/10 border-rose-500/30 text-rose-300';
    box.innerHTML = '⚠ The running balance reconciles only if <b>debit and credit are swapped</b>. '
      + 'Those two columns are almost certainly mapped the wrong way round — go back and switch them.';
  } else if (bc.ok) {
    box.className = 'text-sm rounded-lg p-3 border mb-3 bg-mint-500/10 border-mint-500/30 text-mint-400';
    box.innerHTML = `✓ Running balance reconciles across all ${bc.checked} rows`
      + (bc.assumed
        ? ', but no account is selected, so the debit/credit direction could not be verified.'
        : '. The dates, amounts and debit/credit directions all agree with the statement.');
  } else {
    box.className = 'text-sm rounded-lg p-3 border mb-3 bg-rose-500/10 border-rose-500/30 text-rose-300';
    box.innerHTML = `⚠ The running balance does not reconcile on <b>${bc.mismatches} of ${bc.checked}</b> rows. `
      + 'The date format, or the debit/credit columns, are probably wrong. Go back and check.';
  }

  const probs = [
    ...(rep.error_rows ?? []).map((e) => `Line ${e.line}: ${e.reason}`),
    ...(rep.skipped_rows ?? []).slice(0, 5).map((e) => `Line ${e.line} skipped (${e.reason}): ${e.text ?? ''}`),
  ];
  $('#chk-problems').innerHTML = probs.map((p) => `<div>${esc(p)}</div>`).join('');
  $('#chk-problems').classList.toggle('hidden', probs.length === 0);

  $('#chk-cats').innerHTML = Object.entries(v.categories).map(([c, n]) => {
    const dull = c === 'other_expense' || c === 'other_income';
    return `<span class="chip ${dull ? 'bg-ink-700 text-slate-400' : 'bg-mint-500/15 text-mint-400'}">${esc(c)} ${n}</span>`;
  }).join('');

  $('#chk-rows').innerHTML = v.preview.map((t) => `
    <tr class="border-b border-ink-700/60 ${t.is_duplicate ? 'opacity-50' : ''}">
      <td class="py-1.5 pr-2 whitespace-nowrap text-slate-400">${esc(t.txn_date)}</td>
      <td class="py-1.5 pr-2 max-w-[22rem] truncate" title="${esc(t.description)}">${esc(t.description)}</td>
      <td class="py-1.5 pr-2 text-slate-400">${esc(t.category)}${t.is_self_transfer ? ' ↔' : ''}</td>
      <td class="py-1.5 pr-2 ${t.cashflow === 'credit' ? 'text-mint-400' : 'text-rose-300'}">${esc(t.cashflow)}</td>
      <td class="py-1.5 pr-2 text-right font-semibold">${inr(t.amount)}</td>
      <td class="py-1.5 text-right text-slate-500">${t.balance_after == null ? '—' : inr(t.balance_after)}</td>
    </tr>`).join('');

  $('#chk-import').textContent = `Import ${rep.parsed - v.duplicates} transaction${rep.parsed - v.duplicates === 1 ? '' : 's'}`;
  $('#chk-error').classList.add('hidden');
  showStep('check');
  $('#imp-check').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

$('#chk-back').addEventListener('click', () => showStep('map'));
$('#chk-remember').addEventListener('change', (e) =>
  $('#chk-formatname').classList.toggle('opacity-40', !e.target.checked));

$('#chk-import').addEventListener('click', async () => {
  $('#chk-import').disabled = true;
  $('#chk-import').textContent = 'Importing…';
  $('#chk-error').classList.add('hidden');

  const body = { mapping: imp.mapping, account_id: $('#upload-account').value || null };
  if ($('#chk-remember').checked && $('#chk-formatname').value.trim()) {
    body.save_format = { name: $('#chk-formatname').value.trim() };
  }
  if (imp.account?.can_set_opening && $('#chk-set-opening').checked) {
    body.opening_balance = imp.account.implied_opening_balance;   // paise
  }
  const r = await api(`/api/imports/${imp.uploadId}/stage`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  $('#chk-import').disabled = false;

  if (!r.ok) {
    $('#chk-error').textContent = r.body.message ?? 'Import failed.';
    $('#chk-error').classList.remove('hidden');
    $('#chk-import').textContent = 'Import';
    return;
  }
  const uploadId = imp.uploadId;
  imp = null;
  fileInput.value = '';
  $('#chk-formatname').value = '';
  refreshFileLabel();
  showStep('choose');
  await loadUploads();
  loadFormats();
  loadAccounts();          // opening balance may have changed

  showTab('review');
  $('#review-upload').value = String(uploadId);
  loadStaged(uploadId);
});

/* ---------------- recent uploads ---------------- */
const statusChip = {
  review: 'bg-mint-500/20 text-mint-400', committed: 'bg-sky-500/20 text-sky-300',
  mapping: 'bg-amber-500/20 text-amber-300', failed: 'bg-rose-500/20 text-rose-300',
  rejected: 'bg-slate-500/20 text-slate-300',
};

let lastUploads = [];

function uploadWarnings(u) {
  try { return JSON.parse(u.parse_warnings ?? '[]') ?? []; } catch { return []; }
}

async function loadUploads() {
  const uploads = (await api('/api/uploads')).body.uploads ?? [];
  renderUploads(uploads);
}

function renderUploads(uploads) {
  lastUploads = uploads;

  $('#uploads-list').innerHTML = uploads.map((u) => {
    const hasDetails = ['failed', 'mapping'].includes(u.status) || u.error_message || uploadWarnings(u).length > 0;
    const meta = [
      u.account_name ?? 'no account',
      u.format_name ?? null,
      u.uploaded_at,
      u.staged_count ? `${u.staged_count} rows` : null,
    ].filter(Boolean).map(esc).join(' · ');
    return `
    <li class="flex items-center justify-between gap-3 border-b border-ink-700 pb-2" data-upload="${u.id}">
      <div class="min-w-0">
        <div class="font-medium truncate">${esc(u.original_name)}</div>
        <div class="text-xs text-slate-500">${meta}${hasDetails ? ' · <span class="underline cursor-pointer" data-details="' + u.id + '">details</span>' : ''}</div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        ${u.status === 'mapping' ? `<button data-resume="${u.id}" class="text-xs underline text-amber-300">map</button>` : ''}
        ${u.staged_count ? `<button data-review="${u.id}" class="text-xs underline text-mint-400">view</button>` : ''}
        <span class="chip ${statusChip[u.status] ?? 'bg-slate-500/20 text-slate-300'}">${esc(u.status)}</span>
      </div>
    </li>`;
  }).join('') || '<li class="text-slate-500">Nothing imported yet.</li>';

  $('#review-upload').innerHTML = uploads.filter((u) => u.staged_count > 0)
    .map((u) => `<option value="${u.id}">${esc(u.original_name)} — ${esc(u.status)} (${u.staged_count} rows)</option>`)
    .join('') || '<option value="">— nothing staged yet —</option>';
}

$('#uploads-list').addEventListener('click', async (e) => {
  const reviewId = e.target.dataset.review;
  if (reviewId) {
    showTab('review');
    $('#review-upload').value = reviewId;
    return loadStaged(reviewId);
  }
  const resumeId = e.target.dataset.resume;
  if (resumeId) return resumeMapping(Number(resumeId));

  const detailsId = e.target.dataset.details;
  if (detailsId) {
    const u = lastUploads.find((x) => String(x.id) === detailsId);
    if (u) openErrorModal(u);
  }
});

async function resumeMapping(uploadId) {
  const r = await api(`/api/imports/${uploadId}/preview`);
  if (!r.ok) return alert(r.body.message ?? 'Could not reopen this upload.');
  showTab('upload');
  openMapper(r.body);
}

/* ---- upload details modal ---- */
$('#err-close').addEventListener('click', () => $('#error-modal').close());
$('#err-remap').addEventListener('click', async (e) => {
  const id = Number(e.target.dataset.uploadId);
  $('#error-modal').close();
  const r = await api(`/api/imports/${id}/remap`, { method: 'POST' });
  if (!r.ok) return alert(r.body.message ?? 'Could not re-map.');
  showTab('upload');
  openMapper(r.body);
  loadUploads();
});
$('#err-discard').addEventListener('click', async (e) => {
  const id = Number(e.target.dataset.uploadId);
  if (!confirm('Delete this upload and its staged rows?')) return;
  await api(`/api/imports/${id}`, { method: 'DELETE' });
  $('#error-modal').close();
  loadUploads();
});

function openErrorModal(u) {
  const warnings = uploadWarnings(u);
  $('#err-status').className = 'chip ' + (statusChip[u.status] ?? 'bg-slate-500/20 text-slate-300');
  $('#err-status').textContent = u.status;
  $('#err-filename').textContent = u.original_name;
  $('#err-meta').textContent = [u.account_name ?? 'no account', u.format_name, u.uploaded_at]
    .filter(Boolean).join(' · ');

  $('#err-message-wrap').classList.toggle('hidden', !u.error_message);
  if (u.error_message) $('#err-message').textContent = u.error_message;

  $('#err-warnings-wrap').classList.toggle('hidden', warnings.length === 0);
  $('#err-warnings').innerHTML = warnings.map((w) => `<li>${esc(w)}</li>`).join('');

  const remappable = u.status !== 'committed';
  $('#err-hint').classList.toggle('hidden', !remappable);
  ['#err-remap', '#err-discard'].forEach((sel) => {
    $(sel).classList.toggle('hidden', !remappable);
    $(sel).dataset.uploadId = u.id;
  });
  $('#error-modal').showModal();
}

$('#review-upload').addEventListener('change', (e) => loadStaged(e.target.value));

const CATEGORIES = [
  'salary', 'business_income', 'interest_income', 'dividend', 'refund_cashback', 'other_income',
  'investment', 'epf_employee', 'epf_employer', 'eps_pension', 'epf_interest',
  'emi', 'loan_disbursement', 'credit_card_payment',
  'rent', 'grocery', 'food_dining', 'utility', 'telecom_internet', 'transport_fuel',
  'shopping', 'healthcare', 'insurance', 'education', 'entertainment', 'travel',
  'subscription', 'personal_care', 'charity_gift', 'tax', 'fees_charges',
  'cash_withdrawal', 'self_transfer', 'other_expense',
];
const MODES = ['UPI', 'NEFT', 'RTGS', 'IMPS', 'ATM', 'POS', 'NETBANKING', 'CHEQUE',
  'NACH_ECS', 'INTEREST', 'CHARGES_FEES', 'CASH', 'EPF_CONTRIBUTION', 'OTHER'];

const opts = (list, sel) => list.map((v) => `<option value="${v}"${v === sel ? ' selected' : ''}>${v}</option>`).join('');
const inputCls = 'bg-ink-700 border border-ink-600 rounded px-1.5 py-1 w-full';

let reviewEditable = false;

async function loadStaged(uploadId) {
  if (!uploadId) { $('#review-rows').innerHTML = ''; $('#commit-bar').classList.add('hidden'); return; }
  const upload = lastUploads.find((u) => String(u.id) === String(uploadId));
  reviewEditable = upload ? upload.status === 'review' : false;

  const rows = (await api(`/api/uploads/${uploadId}/staged`)).body.transactions ?? [];
  $('#review-rows').innerHTML = rows.map((t) =>
    reviewEditable ? editableRow(t) : readonlyRow(t)
  ).join('') || `<tr><td colspan="9" class="py-4 text-slate-500">No staged rows.</td></tr>`;

  // Commit bar (editable) vs committed note (already in ledger).
  $('#committed-note').classList.toggle('hidden', reviewEditable || rows.length === 0);
  if (!reviewEditable && rows.length) {
    $('#committed-note').textContent = `✓ This upload was committed to the ledger (${rows.length} rows).`;
  }
  $('#commit-bar').classList.toggle('hidden', !reviewEditable || rows.length === 0);
  $('#review-retag').classList.toggle('hidden', !reviewEditable || rows.length === 0);
  if (reviewEditable) updateCommitSummary();
}

function readonlyRow(t) {
  return `
    <tr class="border-b border-ink-700">
      <td class="py-2 pr-2 whitespace-nowrap text-slate-400">${esc(t.txn_date)}</td>
      <td class="py-2 pr-2">${esc(t.description)}<div class="text-xs text-slate-500">${esc(t.counterparty ?? '')}</div></td>
      <td class="py-2 pr-2 text-slate-400">${esc(t.category)}</td>
      <td class="py-2 pr-2">${tagSourceChip(t.tag_source)}</td>
      <td class="py-2 pr-2 text-slate-500">${esc(t.mode)}</td>
      <td class="py-2 pr-2 ${t.cashflow === 'credit' ? 'text-mint-400' : 'text-rose-300'}">${esc(t.cashflow)}</td>
      <td class="py-2 pr-2 text-right font-semibold">${inr(t.amount)}</td>
      <td class="py-2 pr-2 text-center">${t.is_self_transfer ? '↔' : ''}</td>
      <td class="py-2 text-center text-slate-500">${t.review_status === 'rejected' ? '✕' : '✓'}</td>
    </tr>`;
}

/** Where a row's category came from. `manual` rows survive a re-tag untouched. */
function tagSourceChip(src) {
  const tone = { rule: 'bg-sky-500/20 text-sky-300', manual: 'bg-mint-500/20 text-mint-400',
                 auto: 'bg-ink-700 text-slate-400' }[src] ?? 'bg-ink-700 text-slate-400';
  return `<span class="chip ${tone}">${esc(src ?? 'auto')}</span>`;
}

function editableRow(t) {
  const rejected = t.review_status === 'rejected';
  const flags = t.is_duplicate ? '<span class="chip bg-amber-500/20 text-amber-300">already in ledger</span>' : '';
  return `
    <tr class="border-b border-ink-700 align-top ${rejected ? 'opacity-40' : ''}" data-row="${t.id}">
      <td class="py-2 pr-2"><input type="date" value="${esc(t.txn_date)}" data-field="txn_date" class="${inputCls} min-w-[8rem]"></td>
      <td class="py-2 pr-2 min-w-[10rem]">
        <input value="${esc(t.description)}" data-field="description" class="${inputCls}">
        ${flags ? `<div class="mt-1 space-x-1">${flags}</div>` : ''}
      </td>
      <td class="py-2 pr-2"><select data-field="category" class="${inputCls} min-w-[9rem]">${opts(CATEGORIES, t.category)}</select></td>
      <td class="py-2 pr-2 whitespace-nowrap">
        ${tagSourceChip(t.tag_source)}
        <button data-rule="${t.id}" class="ml-1 text-[11px] text-mint-400 underline" title="Create a rule from this row">+rule</button>
      </td>
      <td class="py-2 pr-2"><select data-field="mode" class="${inputCls} min-w-[7rem]">${opts(MODES, t.mode)}</select></td>
      <td class="py-2 pr-2">
        <select data-field="cashflow" class="${inputCls} min-w-[6rem] ${t.cashflow === 'credit' ? 'text-mint-400' : 'text-rose-300'}">
          <option value="debit"${t.cashflow === 'debit' ? ' selected' : ''}>debit</option>
          <option value="credit"${t.cashflow === 'credit' ? ' selected' : ''}>credit</option>
        </select>
      </td>
      <td class="py-2 pr-2"><input type="number" step="0.01" min="0" value="${(t.amount / 100).toFixed(2)}" data-field="amount_rupees" class="${inputCls} text-right min-w-[6rem]"></td>
      <td class="py-2 pr-2 text-center"><input type="checkbox" data-field="is_self_transfer" ${t.is_self_transfer ? 'checked' : ''} class="accent-sky-500 mt-2"></td>
      <td class="py-2 text-center"><input type="checkbox" data-field="keep" ${rejected ? '' : 'checked'} class="accent-mint-500 mt-2"></td>
    </tr>`;
}

// Delegated change handler: every edit persists immediately via PATCH.
$('#review-rows').addEventListener('change', async (e) => {
  const field = e.target.dataset.field;
  const tr = e.target.closest('[data-row]');
  if (!field || !tr) return;
  const rowId = tr.dataset.row;

  let payload;
  if (field === 'keep') {
    payload = { review_status: e.target.checked ? 'edited' : 'rejected' };
    tr.classList.toggle('opacity-40', !e.target.checked);
  } else if (field === 'amount_rupees') {
    payload = { amount: Math.round(parseFloat(e.target.value || '0') * 100) };
  } else if (field === 'is_self_transfer') {
    payload = { is_self_transfer: e.target.checked ? 1 : 0 };
  } else {
    payload = { [field]: e.target.value };
  }
  if (field === 'cashflow') {
    e.target.className = inputCls + ' min-w-[6rem] ' + (e.target.value === 'credit' ? 'text-mint-400' : 'text-rose-300');
  }

  const r = await api(`/api/staged/${rowId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!r.ok) alert(r.body.message ?? 'Edit failed');
  // The server pins an edited category as `manual`; mirror that in the chip.
  if (field === 'category') {
    const cell = tr.children[3];
    if (cell) cell.innerHTML = tagSourceChip('manual')
      + `<button data-rule="${rowId}" class="ml-1 text-[11px] text-mint-400 underline" title="Create a rule from this row">+rule</button>`;
  }
  updateCommitSummary();
});

/* "+rule": teach the tagger this merchant, then retag the rest of the upload. */
$('#review-rows').addEventListener('click', async (e) => {
  const rowId = e.target.dataset.rule;
  if (!rowId) return;
  const tr = e.target.closest('[data-row]');
  const desc = tr.querySelector('[data-field="description"]').value;
  const category = tr.querySelector('[data-field="category"]').value;
  const cashflow = tr.querySelector('[data-field="cashflow"]').value;

  const guess = (desc.match(/[A-Za-z][A-Za-z0-9 &.]{3,24}/) ?? [desc])[0].trim();
  const pattern = prompt(
    `Tag every ${cashflow} whose description contains this text as "${category}":`, guess);
  if (!pattern) return;

  const uploadId = $('#review-upload').value;
  const r = await api('/api/tagging-rules', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ pattern, category, cashflow, match_type: 'contains', retag_upload_id: uploadId }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Could not create the rule.');
  alert(`Rule saved. ${r.body.retagged ?? 0} row(s) in this upload were re-tagged.\nManually-set rows were left alone.`);
  loadStaged(uploadId);
  loadRules();
});

$('#review-retag').addEventListener('click', async () => {
  const uploadId = $('#review-upload').value;
  if (!uploadId) return;
  const r = await api(`/api/uploads/${uploadId}/retag`, { method: 'POST' });
  if (!r.ok) return alert(r.body.message ?? 'Re-tag failed');
  alert(`${r.body.retagged} row(s) re-tagged. Manually-set rows were left alone.`);
  loadStaged(uploadId);
});

function updateCommitSummary() {
  const rows = [...document.querySelectorAll('#review-rows [data-row]')];
  const keep = rows.filter((tr) => tr.querySelector('[data-field="keep"]').checked).length;
  const rejected = rows.length - keep;
  $('#commit-summary').textContent =
    `${keep} row${keep === 1 ? '' : 's'} will be committed${rejected ? ` · ${rejected} rejected` : ''}.`;
  $('#commit-btn').disabled = keep === 0;
  $('#commit-btn').classList.toggle('opacity-40', keep === 0);
}

$('#commit-btn').addEventListener('click', async () => {
  const uploadId = $('#review-upload').value;
  if (!uploadId) return;
  $('#commit-btn').disabled = true;
  $('#commit-btn').textContent = 'Committing…';
  const r = await api(`/api/uploads/${uploadId}/commit`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({}),
  });
  $('#commit-btn').textContent = 'Commit to ledger';

  if (!r.ok) {
    $('#commit-btn').disabled = false;
    return alert(r.body.message ?? 'Commit failed');
  }
  const s = r.body;
  // The statement prints its own closing balance; if our recomputed balance
  // disagrees, the opening balance is wrong or foreign rows are in this account.
  const recon = s.reconciled === false
    ? `\n\n⚠ The account now reads ${inr(s.account_balance)}, but this statement's closing`
      + ` balance is ${inr(s.statement_balance)} (off by ${inr(Math.abs(s.statement_balance - s.account_balance))}).`
      + `\nCheck the account's opening balance, and that no other bank's statement was imported here.`
    : '';
  alert(`Committed ${s.committed} transaction${s.committed === 1 ? '' : 's'} to the ledger.`
    + (s.skipped_duplicates ? `\n${s.skipped_duplicates} duplicate(s) skipped.` : '')
    + (s.rejected ? `\n${s.rejected} row(s) rejected.` : '')
    + `\nNew account balance: ${inr(s.account_balance)}`
    + recon);

  await loadUploads();          // upload now shows 'committed'
  loadStaged(uploadId);         // re-render read-only
});

/* ---------------- saved CSV layouts ---------------- */
async function loadFormats() {
  const formats = (await api('/api/bank-formats')).body.formats ?? [];
  $('#formats-list').innerHTML = formats.map((f) => {
    const m = f.mapping ?? {};
    const cols = [m.date?.column, ...(m.description?.columns ?? []), m.amount?.debit, m.amount?.credit,
                  m.amount?.amount, m.balance?.column].filter(Boolean);
    return `
    <li class="border-b border-ink-700 pb-2" data-format="${f.id}">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="font-medium truncate">${esc(f.name)}</div>
          <div class="text-xs text-slate-500 truncate" title="${esc(cols.join(' · '))}">${esc(cols.join(' · '))}</div>
          <div class="text-xs text-slate-600 mt-0.5">
            ${esc(f.account_name ?? 'no default account')} · used ${f.use_count}× · ${f.upload_count} upload(s)
            · date ${esc(m.date?.format ?? '?')}
          </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <button data-rename="${f.id}" class="text-xs underline text-slate-400">rename</button>
          <button data-del-format="${f.id}" class="text-xs underline text-rose-300">forget</button>
        </div>
      </div>
    </li>`;
  }).join('') || '<li class="text-slate-500">No layouts saved yet. Import a CSV and tick “remember this layout”.</li>';
}

$('#formats-list').addEventListener('click', async (e) => {
  const renameId = e.target.dataset.rename;
  if (renameId) {
    const li = e.target.closest('[data-format]');
    const name = prompt('Name this layout:', li.querySelector('.font-medium').textContent);
    if (!name) return;
    await api(`/api/bank-formats/${renameId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }),
    });
    return loadFormats();
  }
  const delId = e.target.dataset.delFormat;
  if (delId) {
    if (!confirm('Forget this layout? The next statement from that bank will ask you to map it again.')) return;
    await api(`/api/bank-formats/${delId}`, { method: 'DELETE' });
    loadFormats();
  }
});


/* ---------------- excluded tags ----------------
 * A tag ticked here is dropped from every income/expense figure. Balances are
 * untouched — they are recomputed from the full ledger, category-blind.
 */
let exclusionsDirty = false;

async function loadExclusions() {
  const d = (await api('/api/settings/excluded-categories')).body;
  renderExclusions(d.categories ?? []);
  exclusionsDirty = false;
  $('#exclusions-status').classList.add('hidden');
  $('#exclusions-save').disabled = true;
}

function renderExclusions(categories) {
  $('#exclusions-list').innerHTML = categories.map((c) => {
    const flow = [
      c.out_amount ? `<span class="text-rose-300/70">${inrCompact(c.out_amount)} out</span>` : '',
      c.in_amount ? `<span class="text-mint-400/70">${inrCompact(c.in_amount)} in</span>` : '',
    ].filter(Boolean).join(' · ');
    return `
    <li class="flex items-start gap-2.5 py-1.5 border-b border-ink-700 last:border-0">
      <input type="checkbox" data-exclude="${esc(c.category)}" ${c.excluded ? 'checked' : ''}
             class="accent-mint-500 mt-1 shrink-0">
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
          <span class="${c.excluded ? 'text-slate-400 line-through decoration-slate-600' : ''}">${esc(c.category)}</span>
          <span class="text-xs text-slate-600 shrink-0">${c.txns ? `${c.txns}×` : 'unused'}</span>
        </div>
        ${flow ? `<div class="text-xs text-slate-600">${flow}</div>` : ''}
        ${c.note ? `<div class="text-[11px] text-slate-600 italic mt-0.5">${esc(c.note)}</div>` : ''}
      </div>
    </li>`;
  }).join('') || '<li class="text-slate-500">No transactions yet.</li>';
}

$('#exclusions-list').addEventListener('change', (e) => {
  if (!e.target.dataset.exclude) return;
  exclusionsDirty = true;
  $('#exclusions-save').disabled = false;
  // Reflect the strike-through immediately; the server is the final word.
  const label = e.target.closest('li').querySelector('span');
  label.className = e.target.checked ? 'text-slate-400 line-through decoration-slate-600' : '';
});

$('#exclusions-save').addEventListener('click', async () => {
  const categories = [...document.querySelectorAll('#exclusions-list [data-exclude]')]
    .filter((i) => i.checked).map((i) => i.dataset.exclude);

  const r = await api('/api/settings/excluded-categories', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ categories }),
  });

  const status = $('#exclusions-status');
  status.classList.remove('hidden');
  if (!r.ok) {
    status.className = 'text-xs mt-3 text-rose-300';
    status.textContent = r.body.message ?? 'Could not save.';
    return;
  }
  status.className = 'text-xs mt-3 text-mint-400';
  status.textContent = `Saved. ${categories.length} tag${categories.length === 1 ? '' : 's'} excluded from income and expense. `
    + 'Account balances are unchanged.';

  renderExclusions(r.body.categories ?? []);
  exclusionsDirty = false;
  $('#exclusions-save').disabled = true;

  // Every derived figure in the app just changed.
  loadDashboard();
  loadBudgets();
  if (typeof anAvailable !== 'undefined' && anAvailable) loadAnalytics();
});

/* ---------------- self-transfer identity ---------------- */
async function loadIdentity() {
  const r = (await api('/api/settings/identity')).body;
  const f = $('#identity-form');
  f.names.value = (r.names ?? []).join('\n');
  f.vpas.value = (r.vpas ?? []).join('\n');
}

$('#identity-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const split = (v) => v.split('\n').map((x) => x.trim()).filter(Boolean);
  const r = await api('/api/settings/identity', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ names: split(f.names.value), vpas: split(f.vpas.value) }),
  });
  if (!r.ok) return alert('Could not save identity');
  alert('Saved. Re-run tagging on an upload under review to apply it.');
  loadIdentity();
});

/* ---------------- tagging rules ---------------- */
let allRules = [];

async function loadRules() {
  const r = (await api('/api/tagging-rules')).body;
  allRules = r.rules ?? [];
  if (!$('#rule-category').options.length) {
    $('#rule-category').innerHTML = (r.categories ?? CATEGORIES)
      .map((c) => `<option value="${c}"${c === 'grocery' ? ' selected' : ''}>${c}</option>`).join('');
  }
  renderRules();
}

function renderRules() {
  const q = $('#rules-filter').value.trim().toLowerCase();
  const shown = q
    ? allRules.filter((r) => r.pattern.toLowerCase().includes(q) || r.category.includes(q))
    : allRules;

  $('#rules-count').textContent = `${allRules.filter((r) => r.enabled).length} active`;
  $('#rules-list').innerHTML = shown.map((r) => `
    <li class="flex items-center justify-between gap-2 ${r.enabled ? '' : 'opacity-40'}" data-rule-id="${r.id}">
      <div class="min-w-0 flex-1">
        <div class="truncate">
          <code class="text-xs bg-ink-900 rounded px-1 py-0.5">${esc(r.pattern)}</code>
          <span class="text-slate-500 text-xs">→</span> <span class="text-xs">${esc(r.category)}</span>
        </div>
        <div class="text-[11px] text-slate-600">
          ${esc(r.match_type)}${r.cashflow ? ' · ' + esc(r.cashflow) : ''} · p${r.priority}
          ${r.hits ? ` · ${r.hits} hits` : ''}${r.source === 'seed' ? ' · built-in' : ''}
        </div>
      </div>
      <div class="flex items-center gap-1.5 shrink-0">
        <input type="checkbox" data-toggle="${r.id}" ${r.enabled ? 'checked' : ''} class="accent-mint-500" title="Enable/disable">
        <button data-del-rule="${r.id}" class="text-xs text-rose-300">✕</button>
      </div>
    </li>`).join('') || '<li class="text-slate-500 text-sm">No rules match.</li>';
}

$('#rules-filter').addEventListener('input', renderRules);

$('#rules-list').addEventListener('change', async (e) => {
  const id = e.target.dataset.toggle;
  if (!id) return;
  await api(`/api/tagging-rules/${id}`, {
    method: 'PATCH', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ enabled: e.target.checked ? 1 : 0 }),
  });
  loadRules();
});

$('#rules-list').addEventListener('click', async (e) => {
  const id = e.target.dataset.delRule;
  if (!id || !confirm('Delete this rule?')) return;
  await api(`/api/tagging-rules/${id}`, { method: 'DELETE' });
  loadRules();
});

$('#rule-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const r = await api('/api/tagging-rules', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      pattern: f.get('pattern'), match_type: f.get('match_type'),
      cashflow: f.get('cashflow'), category: f.get('category'),
    }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Could not save the rule');
  e.target.reset();
  loadRules();
});

/* ---------------- event log ---------------- */
const LOG_TONE = {
  error: 'text-rose-300', warning: 'text-amber-300', success: 'text-mint-400', info: 'text-slate-400',
};

async function loadLogs() {
  const params = new URLSearchParams();
  if ($('#log-category').value) params.set('category', $('#log-category').value);
  if ($('#log-level').value) params.set('level', $('#log-level').value);

  const logs = (await api('/api/logs?' + params)).body.logs ?? [];
  $('#log-empty').classList.toggle('hidden', logs.length > 0);
  $('#log-rows').innerHTML = logs.map((l) => `
    <tr class="border-b border-ink-700/60 align-top">
      <td class="py-1.5 pr-3 whitespace-nowrap text-slate-500">${esc(l.ts)}</td>
      <td class="py-1.5 pr-3 ${LOG_TONE[l.level] ?? ''}">${esc(l.level)}</td>
      <td class="py-1.5 pr-3 text-slate-400">${esc(l.category)}</td>
      <td class="py-1.5 pr-3 text-slate-400">${esc(l.event)}</td>
      <td class="py-1.5 pr-3 text-slate-600">${l.upload_id ? '#' + l.upload_id : ''}</td>
      <td class="py-1.5 text-slate-300">${esc(l.message ?? '')}</td>
    </tr>`).join('');
}

$('#log-refresh').addEventListener('click', loadLogs);
$('#log-category').addEventListener('change', loadLogs);
$('#log-level').addEventListener('change', loadLogs);
$('#log-clear').addEventListener('click', async () => {
  if (!confirm('Clear the entire event log?')) return;
  await api('/api/logs', { method: 'DELETE' });
  loadLogs();
});

/* ---------------- Telegram notifications ---------------- */
async function loadNotifications() {
  const c = (await api('/api/settings/notifications')).body;
  if (!c) return;
  $('#tg-form').chat_id.value = c.chat_id ?? '';
  $('#tg-form').daily_summary_time.value = c.daily_summary_time ?? '21:00';
  $('#tg-status').innerHTML = c.configured
    ? '<span class="text-mint-400">● Configured</span> — daily summaries & reminders will send.'
    : `<span class="text-amber-400">● Not fully configured</span> — bot token ${c.bot_token_present ? 'present' : 'missing (set TELEGRAM_BOT_TOKEN in .env)'}, chat ID ${c.chat_id ? 'set' : 'needed below'}.`;
}

$('#tg-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const r = await api('/api/settings/notifications', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ chat_id: f.get('chat_id'), daily_summary_time: f.get('daily_summary_time') }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Save failed');
  loadNotifications();
});

$('#tg-test').addEventListener('click', async () => {
  const r = await api('/api/settings/notifications/test', { method: 'POST' });
  alert(r.body.sent ? 'Test message sent — check Telegram.' : (r.body.message ?? 'Send failed (check token & chat ID).'));
});

$('#tg-summary').addEventListener('click', async () => {
  const r = await api('/api/settings/notifications/summary', { method: 'POST' });
  const p = $('#tg-preview');
  p.classList.remove('hidden');
  p.textContent = (r.body.sent ? '✓ Sent to Telegram.\n\n' : '(not sent — Telegram not configured; preview only)\n\n') + (r.body.preview ?? '');
  loadDashboard();   // snapshot/milestones may have changed
});

/* ---------------- reminders ---------------- */
const REM_DESC = (r) => {
  const t = r.time_of_day;
  if (r.schedule_type === 'daily') return `Daily at ${t}`;
  if (r.schedule_type === 'weekly') return `Weekly on ${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][r.day_of_week] ?? '?'} at ${t}`;
  if (r.schedule_type === 'monthly') return `Monthly on day ${r.day_of_month} at ${t}`;
  return `Once at ${r.next_run_at ?? '?'}`;
};

let editingReminderId = null;
let lastReminders = [];

async function loadReminders() {
  const rows = (await api('/api/settings/reminders')).body.reminders ?? [];
  lastReminders = rows;
  $('#reminders-list').innerHTML = rows.map((r) => `
    <li class="flex justify-between items-center gap-3 bg-ink-700/60 rounded-lg px-3 py-2 ${r.is_active ? '' : 'opacity-50'}">
      <div>
        <div>${esc(r.title)}</div>
        <div class="text-xs text-slate-500">${REM_DESC(r)}${r.next_run_at ? ` · next ${esc(r.next_run_at)}` : ' · done'}</div>
      </div>
      <div class="flex gap-2 shrink-0 text-xs">
        <button data-edit-reminder="${r.id}" class="text-slate-400 underline">edit</button>
        <button data-del-reminder="${r.id}" class="text-rose-300 underline">delete</button>
      </div>
    </li>`).join('') || '<li class="text-slate-500">No reminders yet.</li>';
}

function setReminderSchedFields(type) {
  document.querySelectorAll('.rem-cond').forEach((el) => el.classList.add('hidden'));
  const show = { monthly: '#rem-monthly', weekly: '#rem-weekly', once: '#rem-once' }[type];
  if (show) $(show).classList.remove('hidden');
}

$('#reminders-list').addEventListener('click', async (e) => {
  const del = e.target.dataset.delReminder;
  if (del) {
    if (!confirm('Delete this reminder?')) return;
    await api(`/api/settings/reminders/${del}`, { method: 'DELETE' });
    if (String(editingReminderId) === del) resetReminderForm();
    return loadReminders();
  }
  const edit = e.target.dataset.editReminder;
  if (edit) {
    const r = lastReminders.find((x) => String(x.id) === edit);
    if (!r) return;
    const f = $('#reminder-form');
    editingReminderId = r.id;
    f.title.value = r.title;
    f.message.value = r.message ?? '';
    f.schedule_type.value = r.schedule_type;
    f.time_of_day.value = r.time_of_day;
    if (r.day_of_month) f.day_of_month.value = r.day_of_month;
    if (r.day_of_week != null) f.day_of_week.value = r.day_of_week;
    if (r.next_run_at) f.next_run_at.value = r.next_run_at.replace(' ', 'T').slice(0, 16);
    setReminderSchedFields(r.schedule_type);
    $('#reminder-submit').textContent = 'Update reminder';
    f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
});

function resetReminderForm() {
  editingReminderId = null;
  $('#reminder-form').reset();
  setReminderSchedFields('monthly');
  $('#reminder-submit').textContent = 'Add reminder';
}

document.querySelector('#reminder-form [name="schedule_type"]').addEventListener('change', (e) => setReminderSchedFields(e.target.value));

$('#reminder-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const body = {
    title: f.get('title'),
    message: f.get('message'),
    schedule_type: f.get('schedule_type'),
    time_of_day: f.get('time_of_day'),
    day_of_month: f.get('day_of_month'),
    day_of_week: f.get('day_of_week'),
    next_run_at: (f.get('next_run_at') || '').replace('T', ' '),
  };
  const r = editingReminderId
    ? await api(`/api/settings/reminders/${editingReminderId}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
    : await api('/api/settings/reminders', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!r.ok) return alert(r.body.message ?? 'Failed to save reminder');
  resetReminderForm();
  loadReminders();
});


/* ---------------- analytics ----------------
 * One report per period; the server does the arithmetic, this draws it.
 */
const AN_INCOME = '#059669', AN_EXPENSE = '#e11d48', AN_EMI = '#f59e0b';

/* Categorical palette for the donuts. Hues are spread around the wheel and held
 * at a similar lightness so no slice reads as "more important" than another on
 * the dark surface; `other_*` is deliberately the muted slate. */
const AN_PALETTE = ['#3b82f6', '#e11d48', '#f59e0b', '#8b5cf6', '#14b8a6', '#ec4899',
                    '#84cc16', '#f97316', '#06b6d4', '#a855f7', '#059669', '#eab308'];
const AN_MUTED = '#475569';

const anColor = (cat, i) => (cat === 'other_expense' || cat === 'other_income') ? AN_MUTED : AN_PALETTE[i % AN_PALETTE.length];
const anPretty = (c) => c.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

let anState = { type: 'month', anchor: null, account: '' };
let anAvailable = null;

function anTone(t) {
  return { good: 'text-mint-400', bad: 'text-rose-300', warn: 'text-amber-300', info: 'text-slate-400' }[t] ?? 'text-slate-400';
}
function anToneBorder(t) {
  return { good: 'border-l-mint-500', bad: 'border-l-rose-500', warn: 'border-l-amber-500', info: 'border-l-ink-600' }[t] ?? 'border-l-ink-600';
}

/** Signed delta chip: for expenses and EMI, up is bad. */
function anDelta(delta, invert = false) {
  if (!delta || (delta.abs === 0 && delta.pct === null)) return '<span class="text-slate-600">—</span>';
  const up = delta.abs > 0;
  const good = invert ? !up : up;
  const cls = delta.abs === 0 ? 'text-slate-500' : (good ? 'text-mint-400' : 'text-rose-300');
  const arrow = delta.abs === 0 ? '' : (up ? '▲' : '▼');
  const pct = delta.pct === null ? '' : ` ${Math.abs(delta.pct).toFixed(0)}%`;
  return `<span class="${cls}">${arrow}${pct} <span class="text-slate-500">${inrCompact(Math.abs(delta.abs))}</span></span>`;
}

$('#an-type').addEventListener('click', (e) => {
  const type = e.target.dataset.type;
  if (!type || type === anState.type) return;
  anState.type = type;
  anState.anchor = null;      // jump to the newest period of the new type
  loadAnalytics();
});
$('#an-period').addEventListener('change', (e) => { anState.anchor = e.target.value; loadAnalytics(); });
$('#an-month').addEventListener('change', (e) => { anState.anchor = e.target.value; loadAnalytics(); });

$('#an-year').addEventListener('change', (e) => {
  // Stay on the same month across a year change when that year has it — flicking
  // through Junes is the point. Otherwise fall to that year's newest month.
  const inYear = anMonthsIn(e.target.value);
  if (inYear.length === 0) return;
  const month = anState.anchor?.slice(5, 7);
  anState.anchor = (inYear.find((m) => m.anchor.slice(5, 7) === month) ?? inYear[0]).anchor;
  loadAnalytics();
});
$('#an-account').addEventListener('change', (e) => { anState.account = e.target.value; loadAnalytics(); });

async function loadAnalytics() {
  // Deep-linking straight to /analytics runs this before boot fetches accounts,
  // which would leave the account filter with nothing but "All accounts".
  if (!accounts.length) await loadAccounts();

  const q = new URLSearchParams({ type: anState.type });
  if (anState.anchor) q.set('anchor', anState.anchor);
  if (anState.account) q.set('account_id', anState.account);

  const d = (await api('/api/analytics?' + q)).body;

  document.querySelectorAll('.an-type-btn').forEach((b) =>
    b.classList.toggle('active', b.dataset.type === anState.type));

  if (!d.period) {
    $('#an-body').classList.add('hidden');
    $('#an-empty').classList.remove('hidden');
    $('#an-empty').textContent = 'No transactions yet. Import a statement to see your analysis.';
    return;
  }

  anAvailable = d.available;
  renderPeriodPicker(d.period);
  renderAccountPicker();

  $('#an-empty').classList.toggle('hidden', d.has_data);
  $('#an-body').classList.toggle('hidden', !d.has_data);
  if (!d.has_data) {
    $('#an-empty').textContent = `No transactions in ${d.period.label}. Pick another period above.`;
    return;
  }

  renderAnalytics(d);
}

/** Months available in one year, newest first — `available.months` is already sorted that way. */
const anMonthsIn = (year) => anAvailable.months.filter((m) => m.anchor.startsWith(`${year}-`));

function renderPeriodPicker(period) {
  // The monthly view gets Year + Month; a single list of 91 months is unusable.
  // Year and Financial Year are a handful of entries, so one list is right there.
  const byMonth = anState.type === 'month';
  $('#an-month-pair').classList.toggle('hidden', !byMonth);
  $('#an-month-pair').classList.toggle('flex', byMonth);
  $('#an-period').classList.toggle('hidden', byMonth);

  if (byMonth) {
    const year = period.anchor.slice(0, 4);
    const years = [...new Set(anAvailable.months.map((m) => m.anchor.slice(0, 4)))];

    $('#an-year').innerHTML = years.map((y) =>
      `<option value="${y}"${y === year ? ' selected' : ''}>${y}</option>`).join('');

    // Only the months that actually hold data — a ledger starting in Feb 2019
    // must not offer January 2019 and then report an empty period.
    $('#an-month').innerHTML = anMonthsIn(year).map((m) =>
      `<option value="${m.anchor}"${m.anchor === period.anchor ? ' selected' : ''}>${MONTHS[+m.anchor.slice(5, 7) - 1]}</option>`).join('');
  } else {
    const list = { year: anAvailable.years, fy: anAvailable.fys }[anState.type] ?? [];
    $('#an-period').innerHTML = list.map((p) =>
      `<option value="${p.anchor}"${p.anchor === period.anchor ? ' selected' : ''}>${esc(p.label)}</option>`).join('');
  }

  anState.anchor = period.anchor;

  $('#an-range').textContent = `${period.start} → ${period.end}`;

  const partial = $('#an-partial');
  partial.classList.toggle('hidden', !period.is_partial);
  if (period.is_partial) {
    partial.innerHTML = `This period is incomplete — your ledger stops on <b>${esc(period.data_to ?? period.effective_end)}</b>, `
      + `so it covers ${period.elapsed_days} of ${period.days} days. Averages use the ${period.elapsed_days} days with data, `
      + `and the comparison uses the same window of ${esc(period.prev.label)}.`;
  }
}

function renderAccountPicker() {
  const cur = $('#an-account').value;
  $('#an-account').innerHTML = '<option value="">All accounts</option>'
    + accounts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
  $('#an-account').value = cur;
}

function renderAnalytics(d) {
  const t = d.totals, dl = d.deltas;

  $('#an-income').textContent = inr0(t.income);
  $('#an-expense').textContent = inr0(t.expense);
  $('#an-emi').textContent = inr0(t.emi);
  $('#an-net').textContent = inr0(t.net);
  $('#an-net').className = 'text-2xl font-semibold mt-1 truncate ' + (t.net < 0 ? 'text-rose-300' : 'text-mint-400');

  $('#an-income-d').innerHTML = anDelta(dl.income);
  $('#an-expense-d').innerHTML = anDelta(dl.expense, true);
  $('#an-emi-d').innerHTML = anDelta(dl.emi, true);
  $('#an-net-d').innerHTML = `<span class="text-slate-500">${t.savings_rate}% of income kept</span>`;

  // insights
  $('#an-insights').innerHTML = d.insights.map((i) => `
    <li class="bg-ink-700/40 border border-ink-600 ${anToneBorder(i.tone)} border-l-4 rounded-lg px-3 py-2.5">
      <div class="font-medium ${anTone(i.tone)}">${esc(i.title)}</div>
      <div class="text-xs text-slate-400 mt-0.5 leading-relaxed">${esc(i.detail)}</div>
    </li>`).join('');

  renderDonut('#an-expense-donut', '#an-expense-legend', d.expense_breakdown, t.expense, 'spent');
  renderDonut('#an-income-donut', '#an-income-legend', d.income_breakdown, t.income, 'received');

  renderMonths(d);
  renderSalary(d.salary);
  renderCommitments(d);
  renderAverages(d);
  renderDayOfMonth(d.patterns.day_of_month);
  renderMovers(d);
  renderRecurring(d.recurring);
  renderMerchants(d.patterns.top_merchants, t.expense);
  renderAnomalies(d.patterns.anomalies);
}

/* A donut drawn with one <circle> per slice: r is chosen so the circumference
 * is exactly 100, which turns stroke-dasharray into "percent, remainder". */
function renderDonut(svgSel, legendSel, items, total, verb) {
  if (!items.length || total <= 0) {
    $(svgSel).innerHTML = '<div class="w-[160px] h-[160px] rounded-full border-4 border-dashed border-ink-600"></div>';
    $(legendSel).innerHTML = '<li class="text-slate-500 text-sm">Nothing in this period.</li>';
    return;
  }

  const R = 15.9155;
  let offset = 25;   // start at 12 o'clock
  const slices = items.map((c, i) => {
    const pct = c.amount / total * 100;
    const seg = `<circle cx="21" cy="21" r="${R}" fill="none" stroke="${anColor(c.category, i)}" stroke-width="6"
        stroke-dasharray="${pct.toFixed(3)} ${(100 - pct).toFixed(3)}" stroke-dashoffset="${offset.toFixed(3)}">
        <title>${esc(anPretty(c.category))}: ${inr0(c.amount)} (${c.pct}%)</title></circle>`;
    offset -= pct;   // SVG dashoffset advances clockwise as it decreases
    return seg;
  }).join('');

  $(svgSel).innerHTML = `
    <svg viewBox="0 0 42 42" width="160" height="160" class="-rotate-0">
      <circle cx="21" cy="21" r="${R}" fill="none" stroke="#1a2540" stroke-width="6"/>
      ${slices}
      <text x="21" y="20" text-anchor="middle" fill="#e2e8f0" font-size="4.2" font-weight="600">${inrCompact(total)}</text>
      <text x="21" y="24.6" text-anchor="middle" fill="#64748b" font-size="2.6">${verb}</text>
    </svg>`;

  $(legendSel).innerHTML = items.map((c, i) => `
    <li class="flex items-center gap-2 min-w-0">
      <i class="w-2.5 h-2.5 rounded-sm shrink-0" style="background:${anColor(c.category, i)}"></i>
      <span class="truncate flex-1">${esc(anPretty(c.category))}</span>
      <span class="text-slate-400 tabular-nums">${inr0(c.amount)}</span>
      <span class="text-slate-600 w-11 text-right tabular-nums">${c.pct}%</span>
    </li>`).join('');
}

/* Salary progression across the whole ledger — the year picker only says which
   slice to shade and total up, because "progression" is a story about years.

   Two things the data forces. A month with no salary credit is a GAP, so the
   line breaks rather than plunging to the axis and implying you were not paid.
   And every headline is a MEDIAN, never a mean: one ₹2L bonus month would drag
   an average across the whole year. */
const SAL_PITCH = 15;   // px per month; the chart scrolls once history is long

function renderSalary(sal) {
  const card = $('#an-salary-card');
  if (!sal || sal.series.length < 2) { card.classList.add('hidden'); return; }
  card.classList.remove('hidden');

  const s = sal.series;
  const paid = s.filter((m) => m.amount !== null);

  $('#an-salary-span').textContent =
    `· ${MONTH_LABEL(s[0].month)} → ${MONTH_LABEL(s[s.length - 1].month)}`;
  $('#an-sal-current').textContent = inr0(sal.current);

  const up = (sal.growth_pct ?? 0) >= 0;
  $('#an-sal-growth').textContent = `${up ? '+' : ''}${sal.growth_pct ?? 0}%`;
  $('#an-sal-growth').className = 'text-xl font-semibold mt-1 truncate ' + (up ? 'text-mint-400' : 'text-rose-300');
  $('#an-sal-growth-sub').textContent = `from ${inr0(sal.started)} over ${sal.span_years} years`;

  $('#an-sal-cagr').textContent = sal.cagr_pct === null ? '—' : `${sal.cagr_pct}%`;
  $('#an-sal-cagr-sub').textContent = sal.cagr_pct === null
    ? 'needs a year of payslips'
    : 'a year, compounded';

  $('#an-sal-period').textContent = inr0(sal.period.total);
  $('#an-sal-period-sub').textContent = sal.period.months_paid
    ? `${sal.period.months_paid} payslip${sal.period.months_paid === 1 ? '' : 's'} · median ${inr0(sal.period.median)}`
    : 'no salary in this period';

  // --- chart -------------------------------------------------------------
  const H = 220, padT = 14, padB = 40, AXIS_W = 64;
  const W = Math.max($('#an-sal-scroll').clientWidth || 640, s.length * SAL_PITCH);
  const amounts = paid.map((m) => m.amount);
  const rawMax = Math.max(...amounts);
  const rough = rawMax / 4;
  const mag = 10 ** Math.floor(Math.log10(Math.max(1, rough)));
  const step = [1, 2, 2.5, 5, 10].map((f) => f * mag).find((v) => v >= rough) ?? 10 * mag;
  const max = Math.ceil(rawMax / step) * step;

  const x = (i) => 8 + (i / (s.length - 1)) * (W - 16);   // months are uniform: index IS time
  const y = (v) => H - padB - (v / max) * (H - padT - padB);

  let ylabels = '', grid = '';
  for (let v = 0; v <= max + 1; v += step) {
    const yy = y(v).toFixed(1);
    ylabels += `<text x="${AXIS_W - 8}" y="${(+yy + 4).toFixed(1)}" text-anchor="end" fill="#64748b" font-size="12">${inrCompact(v, 1)}</text>`;
    grid += `<line x1="0" y1="${yy}" x2="${W}" y2="${yy}" stroke="#243354" stroke-width="1"/>`;
  }
  $('#an-sal-axis').innerHTML = `<svg width="${AXIS_W}" height="${H}" viewBox="0 0 ${AXIS_W} ${H}">${ylabels}</svg>`;

  // Break the line at every gap: one polyline per run of consecutive payslips.
  const runs = [];
  let run = [];
  s.forEach((m, i) => {
    if (m.amount === null) { if (run.length) runs.push(run); run = []; return; }
    run.push(`${x(i).toFixed(1)},${y(m.amount).toFixed(1)}`);
  });
  if (run.length) runs.push(run);
  const lines = runs.map((r) => r.length === 1
    ? `<circle cx="${r[0].split(',')[0]}" cy="${r[0].split(',')[1]}" r="2" fill="#34d399"/>`
    : `<polyline points="${r.join(' ')}" fill="none" stroke="#34d399" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`
  ).join('');

  // Shade the period the picker selected.
  const inP = s.map((m, i) => (m.in_period ? i : -1)).filter((i) => i >= 0);
  const band = inP.length
    ? `<rect x="${x(inP[0]).toFixed(1)}" y="${padT}" width="${(x(inP[inP.length - 1]) - x(inP[0])).toFixed(1)}"
             height="${H - padT - padB}" fill="#64748b" opacity="0.14"/>` : '';

  // Year ticks, angled like the dashboard charts.
  let xticks = '';
  s.forEach((m, i) => {
    if (i === 0 || m.month.slice(0, 4) === s[i - 1].month.slice(0, 4)) return;
    const xx = x(i).toFixed(1);
    xticks += `<line x1="${xx}" y1="${padT}" x2="${xx}" y2="${H - padB}" stroke="#334155" stroke-width="1"/>`
      + `<text x="${xx}" y="${H - padB + 12}" transform="rotate(-45 ${xx} ${H - padB + 12})" text-anchor="end"
              fill="#64748b" font-size="11">${m.month.slice(0, 4)}</text>`;
  });

  const hikeAt = new Set(sal.hikes.map((h) => h.month));
  const dots = s.map((m, i) => {
    if (m.amount === null || !hikeAt.has(m.month)) return '';
    const h = sal.hikes.find((z) => z.month === m.month);
    return `<circle cx="${x(i).toFixed(1)}" cy="${y(m.amount).toFixed(1)}" r="4.5" fill="#f59e0b" stroke="#111a2e" stroke-width="2">
              <title>${esc(m.long_label)}: ${inr0(h.from)} → ${inr0(h.to)} (+${h.pct}%)</title>
            </circle>`;
  }).join('');

  // A month with no payslip: mark it, so a break in the line is explained.
  const gaps = s.map((m, i) => m.amount === null
    ? `<line x1="${x(i).toFixed(1)}" y1="${padT}" x2="${x(i).toFixed(1)}" y2="${H - padB}"
             stroke="#475569" stroke-width="1" stroke-dasharray="2 3"><title>${esc(m.long_label)}: no salary credited</title></line>`
    : '').join('');

  $('#an-sal-chart').innerHTML = `
    <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" id="an-sal-svg">
      ${grid}${band}${gaps}${xticks}${lines}${dots}
    </svg>`;

  // --- notes -------------------------------------------------------------
  const bits = [`${paid.length} payslips`];
  if (sal.gap_months) bits.push(`${sal.gap_months} month${sal.gap_months === 1 ? '' : 's'} with none (dashed)`);
  const multi = s.filter((m) => m.credits > 1).length;
  if (multi) bits.push(`${multi} month${multi === 1 ? '' : 's'} paid twice`);
  $('#an-sal-note').textContent = bits.join(' · ')
    + '. A raise is only marked when the new level holds for six months, so a bonus is not mistaken for one.';

  $('#an-sal-hikes').innerHTML = sal.hikes.length === 0
    ? '<div class="text-slate-500">No sustained raise detected yet.</div>'
    : [...sal.hikes].reverse().map((h) => `
      <div class="bg-ink-700/40 border border-ink-600 rounded-xl px-3 py-2 flex items-center justify-between gap-2">
        <span class="truncate">${esc(h.long_label)}</span>
        <span class="shrink-0 text-mint-400 font-semibold tabular-nums">+${h.pct}%</span>
      </div>`).join('');

  const sc = $('#an-sal-scroll');
  sc.scrollLeft = sc.scrollWidth;
}

/* Full-width month comparison: one row per month, three bars on a shared scale. */
function renderMonths(d) {
  const card = $('#an-months-card');
  card.classList.toggle('hidden', !d.months.length);
  if (!d.months.length) return;

  const max = Math.max(1, ...d.months.flatMap((m) => [m.income, m.expense]));
  const bar = (v, color) => `<div class="h-2 rounded-sm" style="width:${(v / max * 100).toFixed(2)}%;background:${color};min-width:${v > 0 ? '2px' : '0'}"></div>`;

  $('#an-months').innerHTML = d.months.map((m) => {
    const dead = m.income === 0 && m.expense === 0;
    return `
    <div class="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 ${dead ? 'opacity-30' : ''}" title="${esc(m.long_label)}">
      <div class="text-xs text-slate-400 font-medium">${esc(m.label)}</div>
      <div class="space-y-1 min-w-0">
        ${bar(m.income, AN_INCOME)}
        ${bar(m.expense, AN_EXPENSE)}
        ${m.emi > 0 ? bar(m.emi, AN_EMI) : '<div class="h-2"></div>'}
      </div>
      <div class="text-xs tabular-nums text-right w-24 ${m.net < 0 ? 'text-rose-300' : 'text-mint-400'}">
        ${dead ? '<span class="text-slate-600">—</span>' : (m.net >= 0 ? '+' : '\u2212') + inrCompact(Math.abs(m.net))}
      </div>
    </div>`;
  }).join('');
}

function renderCommitments(d) {
  const t = d.totals;
  const total = Math.max(1, t.expense);
  const pct = (v) => (v / total * 100).toFixed(2) + '%';

  $('#an-commit-bar').innerHTML =
    `<div style="width:${pct(t.commitments)};background:${AN_EMI}"></div>`
    + `<div style="width:${pct(t.discretionary)};background:${AN_EXPENSE}"></div>`;

  $('#an-commit-legend').innerHTML = `
    <div><div class="flex items-center gap-1.5 text-xs text-slate-400"><i class="w-2.5 h-2.5 rounded-sm" style="background:${AN_EMI}"></i>Committed</div>
      <div class="font-semibold mt-0.5">${inr0(t.commitments)}</div>
      <div class="text-xs text-slate-500">${t.expense ? Math.round(t.commitments / t.expense * 100) : 0}% of spending</div></div>
    <div><div class="flex items-center gap-1.5 text-xs text-slate-400"><i class="w-2.5 h-2.5 rounded-sm" style="background:${AN_EXPENSE}"></i>Discretionary</div>
      <div class="font-semibold mt-0.5">${inr0(t.discretionary)}</div>
      <div class="text-xs text-slate-500">${t.expense ? Math.round(t.discretionary / t.expense * 100) : 0}% of spending</div></div>`;

  const row = (label, value, note) =>
    `<div class="flex items-baseline justify-between gap-2"><span class="text-slate-400">${label}</span>
       <span class="tabular-nums">${value}${note ? ` <span class="text-xs text-slate-500">${note}</span>` : ''}</span></div>`;

  // Show every excluded tag explicitly. Money that is left out of the totals
  // must still appear somewhere, or the page looks like it lost track of it.
  const excluded = (d.excluded_breakdown ?? []).map((e) => {
    const outward = e.out_amount >= e.in_amount;
    const moved = outward ? e.out_amount : e.in_amount;
    // A tag can be excluded wholesale, or individual rows can be blacklisted.
    const why = e.manual_txns === e.txns
      ? `${e.manual_txns} txn${e.manual_txns === 1 ? '' : 's'} excluded by hand`
      : (outward ? 'out, not counted as spending' : 'in, not counted as income')
        + (e.manual_txns ? ` · ${e.manual_txns} by hand` : '');
    return row(anPretty(e.category), inr0(moved), why);
  }).join('');

  $('#an-commit-detail').innerHTML =
      row('EMI', inr0(t.emi), t.income ? `${t.emi_burden}% of income` : '')
    + excluded
    + row('Moved between own accounts', inr0(t.self_transfers), 'not counted')
    + `<div class="text-[11px] text-slate-600 pt-2">Excluded tags still change your account balance — they just
        don't count as income or spending. Edit the list in Settings.</div>`;
}

function renderAverages(d) {
  const a = d.averages;
  $('#an-avg-de').textContent = inr0(a.daily_expense);
  $('#an-avg-we').textContent = inr0(a.weekly_expense);
  $('#an-avg-di').textContent = inr0(a.daily_income);
  $('#an-avg-pt').textContent = inr0(a.per_txn_expense);

  const w = d.patterns.weekday;
  const max = Math.max(1, ...w.map((x) => x.avg));
  $('#an-weekday').innerHTML = w.map((x) => `
    <div class="flex-1 flex flex-col items-center justify-end h-full gap-1" title="${x.label}: ${inr0(x.avg)} average, ${x.txns} txns">
      <div class="w-full rounded-t" style="height:${(x.avg / max * 100).toFixed(1)}%;background:${AN_EXPENSE};opacity:${0.45 + 0.55 * (x.avg / max)}"></div>
      <div class="text-[10px] text-slate-500">${x.label[0]}</div>
    </div>`).join('');

  $('#an-days-note').textContent =
    `Spent on ${a.active_days} of ${a.elapsed_days} days · ${a.no_spend_days} no-spend days · ${a.expense_txns} transactions`;
}

function renderDayOfMonth(days) {
  const max = Math.max(1, ...days.map((d) => d.total));
  $('#an-dom').innerHTML = days.map((d) => `
    <div class="flex-1 h-full flex items-end" title="${d.day}: ${inr0(d.total)}">
      <div class="w-full rounded-t" style="height:${(d.total / max * 100).toFixed(1)}%;background:${AN_EXPENSE};opacity:${d.total ? 0.8 : 0.15};min-height:2px"></div>
    </div>`).join('');
}

function renderMovers(d) {
  $('#an-movers-sub').textContent = `Categories that changed most against ${d.period.prev.label}`
    + (d.period.prev.aligned ? ' (same length of window)' : '');

  $('#an-movers').innerHTML = d.category_movers.map((m) => {
    const up = m.abs > 0;
    return `
    <li class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <div class="truncate">${esc(anPretty(m.category))}</div>
        <div class="text-xs text-slate-500">${inr0(m.prev)} → ${inr0(m.now)}</div>
      </div>
      <div class="text-right shrink-0 ${up ? 'text-rose-300' : 'text-mint-400'}">
        <div class="tabular-nums">${up ? '+' : '−'}${inrCompact(Math.abs(m.abs))}</div>
        <div class="text-xs text-slate-500">${m.pct === null ? 'new' : `${m.pct > 0 ? '+' : ''}${m.pct}%`}</div>
      </div>
    </li>`;
  }).join('') || '<li class="text-slate-500">Nothing to compare.</li>';
}

function renderRecurring(rec) {
  $('#an-recurring-total').textContent = rec.count ? `${inr0(rec.monthly_total)}/mo` : '';
  $('#an-recurring').innerHTML = rec.items.map((r) => `
    <li class="flex items-center justify-between gap-3 border-b border-ink-700 pb-2 last:border-0">
      <div class="min-w-0">
        <div class="truncate">${esc(r.name)}</div>
        <div class="text-xs text-slate-500">
          ${esc(r.cadence)} · ${esc(anPretty(r.category))} · ${r.count}×
          ${r.overdue ? '<span class="text-amber-300">· overdue</span>' : `· next ${esc(r.next_expected)}`}
        </div>
      </div>
      <div class="text-right shrink-0">
        <div class="tabular-nums">${inr0(r.amount)}</div>
        ${r.cadence !== 'monthly' ? `<div class="text-xs text-slate-500">${inrCompact(r.monthly_cost)}/mo</div>` : ''}
      </div>
    </li>`).join('') || '<li class="text-slate-500">No repeating payments detected.</li>';
}

function renderMerchants(list, total) {
  const max = Math.max(1, ...list.map((m) => m.amount));
  $('#an-merchants').innerHTML = list.map((m) => `
    <li>
      <div class="flex items-baseline justify-between gap-3">
        <span class="truncate">${esc(m.name)}</span>
        <span class="tabular-nums shrink-0">${inr0(m.amount)}</span>
      </div>
      <div class="flex items-center gap-2 mt-1">
        <div class="h-1.5 rounded-full flex-1 bg-ink-700 overflow-hidden">
          <div class="h-full rounded-full" style="width:${(m.amount / max * 100).toFixed(1)}%;background:${AN_EXPENSE}"></div>
        </div>
        <span class="text-xs text-slate-500 w-28 text-right truncate">${m.txns}× · ${esc(anPretty(m.category))}</span>
      </div>
    </li>`).join('') || '<li class="text-slate-500">No spending in this period.</li>';
}

function renderAnomalies(list) {
  $('#an-anomalies').innerHTML = list.map((a) => `
    <li class="flex items-center justify-between gap-3">
      <div class="min-w-0">
        <div class="truncate">${esc(a.name)}</div>
        <div class="text-xs text-slate-500">${esc(a.date)} · ${esc(anPretty(a.category))}</div>
      </div>
      <div class="text-right shrink-0">
        <div class="tabular-nums">${inr0(a.amount)}</div>
        <div class="text-xs text-amber-300">${a.times_median}× usual</div>
      </div>
    </li>`).join('') || '<li class="text-slate-500">Nothing out of the ordinary.</li>';
}

/* ================= LOANS =================
 * A loan's schedule is derived on the server from (loan + events) on every read,
 * so nothing here caches a schedule. `loanState.report` is simply the last
 * response; every mutation re-renders from a fresh one.
 *
 * The Loans page never touches the ledger. The single seam is the Link button on
 * an `emi`-tagged ledger row, which posts to /api/loans/{id}/payments.
 */
const LOAN_TYPES = { home: 'Home', personal: 'Personal', auto: 'Auto', education: 'Education', gold: 'Gold', business: 'Business', other: 'Other' };
const EVENT_LABEL = { disbursement: 'Disbursement', rate_change: 'Rate change', emi_change: 'EMI change', prepayment: 'Prepayment' };

const loanState = { list: [], selected: '', report: null, schedYear: 'all', schedFilter: 'all', schedLimit: 60 };

/**
 * A write can succeed and still leave the loan unamortisable — that is exactly
 * what happens midway through repairing one. The server answers 422 with the
 * loan and its events. Treat it as "saved", then re-render; alerting and bailing
 * would strand the user on a stale screen.
 */
const savedButBroken = (r) => r.status === 422 && r.body?.error === 'unamortisable';

async function loadLoans() {
  const r = await api('/api/loans');
  if (!r.ok) return;
  loanState.list = r.body.loans ?? [];

  const picker = $('#loan-picker');
  const keep = loanState.selected;
  picker.innerHTML = '<option value="">All loans — overview</option>'
    + loanState.list.map((l) => `<option value="${l.id}">${esc(l.name)}${l.is_closed ? ' (closed)' : ''}</option>`).join('');
  // Preserve the selection across a reload, but fall back if that loan is gone.
  picker.value = loanState.list.some((l) => String(l.id) === keep) ? keep : '';
  loanState.selected = picker.value;

  $('#loan-empty').classList.toggle('hidden', loanState.list.length > 0);
  await renderLoanView(r.body);
}

async function renderLoanView(portfolio) {
  const one = loanState.selected !== '';
  $('#loan-portfolio').classList.toggle('hidden', one || loanState.list.length === 0);
  $('#loan-detail').classList.toggle('hidden', !one);
  if (!one) $('#loan-broken').classList.add('hidden');
  $('#loan-edit').classList.toggle('hidden', !one);
  $('#loan-delete').classList.toggle('hidden', !one);

  if (one) return renderLoanDetail();
  if (portfolio) renderPortfolio(portfolio);
}

function renderPortfolio(p) {
  $('#lp-outstanding').textContent = inrCompact(p.total_outstanding);
  $('#lp-principal').textContent = `of ${inrCompact(p.total_principal)} borrowed`;
  $('#lp-emi').textContent = inrCompact(p.monthly_emi);
  $('#lp-rate').textContent = p.blended_rate ? p.blended_rate.toFixed(2) + '%' : '—';
  $('#lp-free').textContent = p.debt_free_date ? DATE_LABEL(p.debt_free_date) : '—';
  $('#lp-interest').textContent = p.remaining_interest > 0 ? `${inrCompact(p.remaining_interest)} interest still to pay` : '';

  const ratio = p.emi_to_income;
  $('#lp-ratio').innerHTML = ratio == null
    ? '<span class="text-slate-500">no income data</span>'
    // Lenders start baulking past 40% of take-home; 50% is genuinely stretched.
    : `<span class="${ratio > 50 ? 'text-rose-300' : ratio > 40 ? 'text-amber-300' : 'text-slate-500'}">`
      + `${ratio}% of your monthly income</span>`;

  $('#lp-rows').innerHTML = p.loans.map((l) => {
    if (l.error) {
      return `<tr class="border-b border-ink-700/50"><td class="py-2">${esc(l.name)}</td>
        <td colspan="6" class="text-rose-300 text-xs">${esc(l.error)}</td></tr>`;
    }
    return `<tr class="border-b border-ink-700/50 hover:bg-ink-700/30 cursor-pointer" data-loan="${l.id}">
      <td class="py-2.5">
        <div class="font-medium">${esc(l.name)}${l.is_closed ? ' <span class="text-xs text-mint-400">closed</span>' : ''}</div>
        <div class="text-xs text-slate-500">${esc(l.lender ?? '')}</div>
      </td>
      <td class="text-slate-400 text-xs">${esc(LOAN_TYPES[l.loan_type] ?? l.loan_type)}</td>
      <td class="text-right tabular-nums">${l.rate_apr.toFixed(2)}%</td>
      <td class="text-right tabular-nums">${inr0(l.emi)}${l.pre_emi ? '<div class="text-[10px] text-violet-300">pre-EMI</div>' : ''}</td>
      <td class="text-right tabular-nums text-rose-300">${inr0(l.outstanding)}${
        l.undisbursed > 0 ? `<div class="text-[10px] text-amber-300">${inr0(l.undisbursed)} undrawn</div>` : ''}</td>
      <td class="pl-4">
        <div class="h-1.5 bg-ink-700 rounded-full overflow-hidden">
          <div class="h-full bg-mint-500 rounded-full" style="width:${Math.max(0, Math.min(100, l.progress))}%"></div>
        </div>
        <div class="text-xs text-slate-500 mt-1">${l.progress}% of ${inrCompact(l.disbursed)} drawn · ${l.paid_count}/${l.periods} paid${l.overdue ? ` · <span class="text-amber-300">${l.overdue} overdue</span>` : ''}</div>
      </td>
      <td class="text-right text-xs text-slate-400">${l.payoff_date ? DATE_LABEL(l.payoff_date) : '—'}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="7" class="py-6 text-center text-slate-500">No loans yet.</td></tr>';
}

async function renderLoanDetail() {
  const r = await api('/api/loans/' + loanState.selected);
  const warn = $('#loan-warnings');

  if (!r.ok) {
    // 422: the terms no longer amortise. Keep the loan AND its events. Without
    // them "Edit terms" would open a blank Add-loan form, and the event that
    // broke the loan would be unreachable — the events list normally lives inside
    // the detail view we are about to hide. The server sends both for this reason.
    loanState.report = { loan: r.body.loan, events: r.body.events ?? [], broken: true };
    $('#loan-detail').classList.add('hidden');
    warn.classList.add('hidden');
    $('#loan-broken').classList.remove('hidden');
    $('#loan-broken-msg').textContent = r.body.message ?? 'This loan could not be calculated.';
    renderEventList($('#loan-broken-events'), loanState.report.events);
    return;
  }

  // renderLoanView owns these toggles on a tab switch, but a repair lands here
  // directly — so the detail must be restored from this path too.
  $('#loan-broken').classList.add('hidden');
  $('#loan-detail').classList.remove('hidden');
  const d = loanState.report = r.body;
  warn.classList.toggle('hidden', d.warnings.length === 0);
  warn.innerHTML = d.warnings.map((w) => `<div>${esc(w)}</div>`).join('');

  const pos = d.position, s = d.summary, loan = d.loan;

  $('#ln-outstanding').textContent = inr0(pos.outstanding);
  const repaid = loan.principal - pos.outstanding;
  $('#ln-progress').textContent = `${inrCompact(repaid)} of ${inrCompact(loan.principal)} repaid`
    + ` (${loan.principal > 0 ? Math.round((repaid / loan.principal) * 100) : 0}%)`;

  // During the pre-EMI phase what you pay is interest, not an instalment.
  $('#ln-next-label').textContent = pos.current_is_pre_emi ? 'Next pre-EMI interest' : 'Next instalment';
  $('#ln-next').textContent = pos.next_due ? inr0(pos.next_due.emi) : '—';
  $('#ln-next-date').textContent = pos.next_due
    ? (pos.current_is_pre_emi
        ? `interest only, due ${DATE_LABEL(pos.next_due.due_date)} — no principal yet`
        : `instalment #${pos.next_due.period_no}, due ${DATE_LABEL(pos.next_due.due_date)}`)
    : 'nothing left to pay';

  $('#ln-paid').textContent = `${pos.paid_count} / ${pos.total_periods}`;
  // "Overdue" here means the due date passed and no ledger transaction was ever
  // linked — it does not necessarily mean you missed a payment.
  $('#ln-overdue').innerHTML = pos.overdue_count > 0
    ? `<span class="text-amber-300">${pos.overdue_count} overdue — past due, not tagged in the ledger</span>`
    : '<span class="text-slate-500">nothing overdue</span>';

  $('#ln-payoff').textContent = s.payoff_date ? DATE_LABEL(s.payoff_date) : '—';
  $('#ln-payoff-sub').textContent = `${s.periods} instalments at ${loan.interest_rate_apr}% to start`;

  $('#ln-principal').textContent = inr0(s.disbursed);
  // Sanctioned vs drawn only differ on a tranched loan; say so only when they do.
  $('#ln-drawn').innerHTML = s.undisbursed > 0
    ? `<span class="text-amber-300">${inr0(s.undisbursed)} of ${inr0(s.sanctioned)} sanctioned not yet drawn</span>`
    : (s.tranches > 1 ? `<span class="text-slate-500">drawn in ${s.tranches} tranches</span>` : '');
  $('#ln-interest').textContent = inr0(s.total_interest);
  $('#ln-interest-pct').textContent = s.pre_emi_interest > 0
    ? `${s.interest_pct}% of what you drew · ${inr0(s.pre_emi_interest)} of it before the EMI began`
    : `${s.interest_pct}% of what you borrowed`;
  $('#ln-interest-paid').textContent = inr0(pos.interest_paid);
  $('#ln-principal-paid').textContent = `${inr0(pos.principal_paid)} principal, on tagged instalments`;
  $('#ln-remaining').textContent = inr0(pos.remaining_payments);

  // Savings card only means something once you have actually done something.
  const b = d.baseline;
  const savings = $('#ln-savings');
  savings.classList.toggle('hidden', !b || b.interest_saved <= 0);
  if (b && b.interest_saved > 0) {
    $('#ln-saved').textContent = inrCompact(b.interest_saved);
    $('#ln-months-saved').textContent = b.months_saved + (b.months_saved === 1 ? ' month' : ' months');
    $('#ln-savings-note').textContent =
      `Without your prepayments and EMI increases this loan would have run to ${DATE_LABEL(b.payoff_date)}`
      + ` and cost ${inrCompact(b.total_interest)} in interest. Rate changes are left in — those were not your choice.`;
  }

  $('#ln-crossover').textContent = s.crossover
    ? `Principal overtakes interest at instalment #${s.crossover.period_no}, ${DATE_LABEL(s.crossover.due_date)}`
    : '';

  renderLoanCurve(d.periods, pos.as_of);
  renderLoanYears(d.by_year);
  renderLoanEvents(d.events);
  renderLoanTax(d.tax);
  renderVariances(d.variances);
  syncSchedYear(d.periods);
  renderSchedule();
  resetSimulator();
}

/* Outstanding balance over time. Prepayments show as amber drops, today as a rule. */
function renderLoanCurve(periods, asOf) {
  const W = 800, H = 220, pad = 4;
  if (periods.length === 0) { $('#ln-curve').innerHTML = ''; return; }

  const max = Math.max(...periods.map((p) => p.opening_balance), 1);
  const x = (i) => pad + (i / Math.max(1, periods.length - 1)) * (W - pad * 2);
  const y = (v) => H - pad - (v / max) * (H - pad * 2);

  const pts = periods.map((p, i) => `${x(i).toFixed(1)},${y(p.closing_balance).toFixed(1)}`);
  const line = `M ${x(0).toFixed(1)},${y(periods[0].opening_balance).toFixed(1)} L ` + pts.join(' L ');
  const area = line + ` L ${x(periods.length - 1).toFixed(1)},${H - pad} L ${x(0).toFixed(1)},${H - pad} Z`;

  const prepays = periods.map((p, i) => (p.prepayment > 0
    ? `<circle cx="${x(i).toFixed(1)}" cy="${y(p.closing_balance).toFixed(1)}" r="3.5" fill="#f59e0b"/>` : '')).join('');

  const todayIdx = periods.findIndex((p) => p.due_date >= asOf);
  const today = todayIdx > 0
    ? `<line x1="${x(todayIdx).toFixed(1)}" y1="${pad}" x2="${x(todayIdx).toFixed(1)}" y2="${H - pad}"
             stroke="#334155" stroke-width="1.5" stroke-dasharray="3 3"/>` : '';

  $('#ln-curve').innerHTML = `
    <defs><linearGradient id="lnfill" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#e11d48" stop-opacity=".28"/>
      <stop offset="100%" stop-color="#e11d48" stop-opacity="0"/>
    </linearGradient></defs>
    <path d="${area}" fill="url(#lnfill)"/>
    <path d="${line}" fill="none" stroke="#e11d48" stroke-width="2" vector-effect="non-scaling-stroke"/>
    ${today}${prepays}`;
}

/* One row per year: interest / principal / prepayment on a shared scale, so the
 * bars are comparable across years rather than each filling its own row. */
function renderLoanYears(years) {
  const max = Math.max(...years.map((y) => y.interest + y.principal + y.prepayment), 1);
  $('#ln-years').innerHTML = years.map((y) => {
    const pct = (v) => (v / max) * 100;
    const total = y.interest + y.principal + y.prepayment;
    return `<div class="flex items-center gap-3">
      <div class="w-12 text-xs text-slate-400 tabular-nums shrink-0">${y.year}</div>
      <div class="flex-1 h-5 bg-ink-700/50 rounded overflow-hidden flex">
        <div style="width:${pct(y.interest)}%;background:#e11d48" title="Interest ${inr0(y.interest)}"></div>
        <div style="width:${pct(y.principal)}%;background:#10b981" title="Principal ${inr0(y.principal)}"></div>
        <div style="width:${pct(y.prepayment)}%;background:#f59e0b" title="Prepayment ${inr0(y.prepayment)}"></div>
      </div>
      <div class="w-24 text-right text-xs text-slate-400 tabular-nums shrink-0">${inrCompact(total)}</div>
    </div>`;
  }).join('');
}

function renderLoanEvents(events) {
  renderEventList($('#ln-events'), events);
}

/** Shared by the normal detail view and the "cannot be calculated" recovery card. */
function renderEventList(box, events) {
  if (events.length === 0) {
    box.innerHTML = '<div class="text-slate-500 text-sm">Nothing has changed since this loan started.</div>';
    return;
  }
  box.innerHTML = events.map((e) => {
    let detail = '';
    if (e.event_type === 'disbursement') {
      detail = `${inr0(e.amount)} released by the bank`;
    } else if (e.event_type === 'rate_change') {
      detail = `to ${e.rate_apr}%` + (e.mode === 'keep_tenure' ? ', EMI recalculated' : ', tenure extended');
    } else if (e.event_type === 'emi_change') {
      detail = `to ${inr0(e.emi_amount)} a month`;
    } else {
      detail = `${inr0(e.amount)} off the principal`
        + (e.mode === 'reduce_emi' ? ', EMI lowered' : ', loan shortened');
    }
    const colour = { disbursement: 'text-violet-300', rate_change: 'text-rose-300',
                     emi_change: 'text-sky-300', prepayment: 'text-mint-400' }[e.event_type];
    return `<div class="flex items-center justify-between gap-3 bg-ink-700/40 border border-ink-600 rounded-lg px-3 py-2">
      <div class="min-w-0">
        <span class="${colour} font-medium">${EVENT_LABEL[e.event_type]}</span>
        <span class="text-slate-400"> ${esc(detail)}</span>
        <span class="text-slate-500 text-xs"> · from ${DATE_LABEL(e.effective_date)}</span>
        ${e.note ? `<div class="text-xs text-slate-500 truncate">${esc(e.note)}</div>` : ''}
      </div>
      <div class="shrink-0 flex gap-1">
        <button data-edit-event="${e.id}" title="Edit this change"
          class="w-8 h-8 inline-flex items-center justify-center rounded-lg border border-ink-600 bg-ink-700/60 hover:bg-ink-600 text-slate-300">✎</button>
        <button data-del-event="${e.id}" title="Remove this change"
          class="w-8 h-8 inline-flex items-center justify-center rounded-lg border border-ink-600 bg-ink-700/60 hover:bg-ink-600 text-rose-300">✕</button>
      </div>
    </div>`;
  }).join('');
}

/**
 * Section 24(b). Interest paid before possession is NOT deductible in the year
 * you pay it — it is banked and returned in five equal annual instalments once
 * you have the keys, still inside the ₹2L cap. Without a possession date that
 * split cannot be computed, so the table falls back to "deductible as paid" and
 * says so rather than flattering the number.
 */
function renderLoanTax(tax) {
  const note = $('#ln-tax-note');
  const split = tax.split_applied;

  $('#ln-fy-carry-head').textContent = split ? 'Pre-construction 1/5' : '';
  note.classList.toggle('hidden', !split && tax.years.every((f) => f.pre_emi_interest === 0));

  if (split) {
    note.innerHTML = `Possession falls in <strong>${esc(tax.possession_fy)}</strong>.
      ${inr0(tax.pre_construction_total)} of interest was paid before that, so none of it was
      deductible when paid. It comes back as five yearly instalments of
      <strong>${inr0(tax.pre_construction_instalment)}</strong> from ${esc(tax.possession_fy)},
      and those instalments still count inside the ₹2,00,000 cap.`;
  } else if (!note.classList.contains('hidden')) {
    note.innerHTML = `This loan has pre-EMI interest but no possession date, so the table below shows
      interest as deductible in the year it was paid. That is <strong>not</strong> how Section 24(b)
      treats pre-construction interest. Add a possession date in “Edit terms” to see the real split.`;
  }

  $('#ln-fy').innerHTML = tax.years.map((f) => `
    <tr class="border-b border-ink-700/50 ${f.pre_possession ? 'opacity-60' : ''}">
      <td class="py-2">${esc(f.fy)}
        ${f.pre_possession ? '<span class="ml-1 text-[10px] text-slate-500">pre-possession</span>' : ''}</td>
      <td class="text-right tabular-nums">${inr0(f.interest)}</td>
      <td class="text-right tabular-nums ${f.pre_construction_carry > 0 ? 'text-sky-300' : 'text-slate-700'}">${split ? (f.pre_construction_carry > 0 ? inr0(f.pre_construction_carry) : '—') : ''}</td>
      <td class="text-right tabular-nums ${f.deductible_24b > 0 ? 'text-mint-400' : 'text-slate-600'}">${f.deductible_24b > 0 ? inr0(f.deductible_24b) : '—'}</td>
      <td class="text-right tabular-nums ${f.over_cap > 0 ? 'text-amber-300' : 'text-slate-600'}">${f.over_cap > 0 ? inr0(f.over_cap) : '—'}</td>
      <td class="text-right tabular-nums">${inr0(f.principal)}</td>
    </tr>`).join('');
}

function renderVariances(list) {
  const box = $('#ln-variance');
  box.classList.toggle('hidden', list.length === 0);
  if (list.length === 0) return;
  box.innerHTML = `<strong>${list.length} instalment${list.length === 1 ? '' : 's'} paid a different amount than scheduled.</strong>
    ${list.slice(0, 4).map((v) => `<div>#${v.period_no} ${DATE_LABEL(v.due_date)}: paid ${inr0(v.paid)},`
      + ` scheduled ${inr0(v.scheduled)} (${v.variance > 0 ? '+' : ''}${inr0(v.variance)})</div>`).join('')}
    <div class="text-slate-400 mt-1">The schedule is left alone. If you deliberately paid extra, add it as a prepayment.</div>`;
}

function syncSchedYear(periods) {
  const years = [...new Set(periods.map((p) => p.due_date.slice(0, 4)))];
  const sel = $('#ln-sched-year');
  const keep = loanState.schedYear;
  sel.innerHTML = '<option value="all">Every year</option>' + years.map((y) => `<option value="${y}">${y}</option>`).join('');
  sel.value = years.includes(keep) ? keep : 'all';
  loanState.schedYear = sel.value;
}

function renderSchedule() {
  const d = loanState.report;
  if (!d) return;

  const rows = d.periods.filter((p) => {
    if (loanState.schedYear !== 'all' && !p.due_date.startsWith(loanState.schedYear)) return false;
    if (loanState.schedFilter === 'unpaid') return p.status !== 'paid';
    if (loanState.schedFilter === 'paid') return p.status === 'paid';
    if (loanState.schedFilter === 'overdue') return p.status === 'overdue';
    return true;
  });

  const shown = rows.slice(0, loanState.schedLimit);
  const chip = {
    paid: '<span class="px-2 py-0.5 rounded-full text-xs bg-mint-500/15 text-mint-400 border border-mint-500/30">paid</span>',
    overdue: '<span class="px-2 py-0.5 rounded-full text-xs bg-amber-500/15 text-amber-300 border border-amber-500/30">overdue</span>',
    unpaid: '<span class="px-2 py-0.5 rounded-full text-xs bg-ink-700 text-slate-400 border border-ink-600">unpaid</span>',
  };

  $('#ln-schedule').innerHTML = shown.map((p) => `
    <tr class="border-b border-ink-700/50 ${p.status === 'paid' ? 'opacity-70' : ''} ${p.is_pre_emi ? 'bg-violet-500/5' : ''}">
      <td class="py-2 text-slate-500 tabular-nums">${p.period_no}</td>
      <td class="whitespace-nowrap">${DATE_LABEL(p.due_date)}
        ${p.is_pre_emi ? '<span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-violet-500/15 text-violet-300 border border-violet-500/30">pre-EMI</span>' : ''}</td>
      <td class="text-right tabular-nums text-slate-400">${inr0(p.opening_balance)}</td>
      <td class="text-right tabular-nums ${p.disbursement > 0 ? 'text-violet-300' : 'text-slate-700'}">${p.disbursement > 0 ? inr0(p.disbursement) : '—'}</td>
      <td class="text-right tabular-nums font-medium">${inr0(p.emi)}${p.is_stub ? ' <span class="text-xs text-slate-500">final</span>' : ''}</td>
      <td class="text-right tabular-nums text-rose-300/80">${inr0(p.interest)}</td>
      <td class="text-right tabular-nums ${p.principal > 0 ? 'text-mint-400/80' : 'text-slate-700'}">${p.principal > 0 ? inr0(p.principal) : '—'}</td>
      <td class="text-right tabular-nums ${p.prepayment > 0 ? 'text-amber-300' : 'text-slate-700'}">${p.prepayment > 0 ? inr0(p.prepayment) : '—'}</td>
      <td class="text-right tabular-nums text-slate-400">${inr0(p.closing_balance)}</td>
      <td class="text-right tabular-nums text-slate-500 text-xs">${p.rate_apr}%</td>
      <td class="text-center pl-3 whitespace-nowrap">
        ${chip[p.status]}
        ${p.status === 'paid' ? `<button data-unlink="${p.period_no}" title="Unlink this payment"
            class="ml-1 text-slate-500 hover:text-rose-300">✕</button>` : ''}
        ${p.variance_flag ? '<span class="ml-1 text-amber-300" title="Paid a different amount than scheduled">⚠</span>' : ''}
      </td>
    </tr>`).join('') || '<tr><td colspan="11" class="py-6 text-center text-slate-500">No instalments match this filter.</td></tr>';

  const more = rows.length - shown.length;
  $('#ln-sched-more').classList.toggle('hidden', more <= 0);
  $('#ln-sched-more-btn').textContent = `Show ${Math.min(more, 120)} more of ${more} remaining`;
}

/* ---- simulator ---- */
/* The plan is a list of hypothetical prepayments simulated together. It is
   purely client-side — the loan is never written to — and it is discarded
   whenever the selected loan changes, because a plan means nothing against a
   different loan's schedule. */
const SIM_MAX = 20;
let simPlan = [];
let simSeq = 0;
let simPlanLoan = null;

/* Called on every re-render of the detail view, not only on loan change. A plan
   is meaningless against another loan's schedule, so switching loans discards
   it — but an edit to *this* loan just moves the baseline, so the plan survives
   and is re-simulated against it. */
function resetSimulator() {
  const switched = String(loanState.selected) !== String(simPlanLoan);
  simPlanLoan = String(loanState.selected);
  if (switched) {
    simPlan = [];
    simSeq = 0;
  }

  const next = loanState.report?.position?.next_due?.due_date;
  if (next) $('#sim-date').value = next;

  renderSimPlan();
  if (simPlan.length) runSimulation();
}

function simMode() {
  return $('#sim-mode .sim-mode-btn.bg-ink-600')?.dataset.mode ?? 'lumpsum';
}

function setSimMode(mode) {
  document.querySelectorAll('#sim-mode .sim-mode-btn').forEach((b) => {
    b.classList.toggle('bg-ink-600', b.dataset.mode === mode);
    b.classList.toggle('text-slate-200', b.dataset.mode === mode);
    b.classList.toggle('text-slate-400', b.dataset.mode !== mode);
  });
  $('#sim-months-wrap').classList.toggle('hidden', mode !== 'monthly');
}

/** One plan entry as the API's what-if spec. */
function simSpec(e) {
  return e.kind === 'monthly'
    ? { mode: 'monthly', amount: e.amount, from: e.date, months: e.months, prepay_mode: e.prepay_mode }
    : { mode: 'lumpsum', amount: e.amount, on: e.date, prepay_mode: e.prepay_mode };
}

function simLabel(e) {
  const after = e.prepay_mode === 'reduce_emi' ? 'lower the EMI' : 'finish earlier';
  return e.kind === 'monthly'
    ? `${inr(Math.round(e.amount * 100))} every month for ${e.months} month${e.months === 1 ? '' : 's'}
       from ${DATE_LABEL(e.date)} — ${after}`
    : `${inr(Math.round(e.amount * 100))} on ${DATE_LABEL(e.date)} — ${after}`;
}

function renderSimPlan() {
  const empty = simPlan.length === 0;
  $('#sim-plan-wrap').classList.toggle('hidden', empty);
  $('#sim-empty').classList.toggle('hidden', !empty);
  if (empty) {
    $('#sim-result').classList.add('hidden');
    $('#sim-note').classList.add('hidden');
  }

  $('#sim-plan').innerHTML = simPlan.map((e) => `
    <div class="flex items-center justify-between gap-3 bg-ink-700/40 border border-ink-600 rounded-xl px-3 py-2">
      <div class="min-w-0">
        <span class="inline-block w-2 h-2 rounded-full align-middle mr-2" style="background:#f59e0b"></span>
        <span class="text-slate-200">${esc(simLabel(e))}</span>
      </div>
      <button data-sim-del="${e.id}" title="Remove from the plan"
              class="shrink-0 text-slate-400 hover:text-rose-300 px-2">&times;</button>
    </div>`).join('');
}

function addSimEntry() {
  if (simPlan.length >= SIM_MAX) return alert(`A plan can hold at most ${SIM_MAX} prepayments.`);

  const amount = Number($('#sim-amount').value);
  const date = $('#sim-date').value;
  if (!(amount > 0)) return alert('Enter an amount above zero.');
  if (!date) return alert('Pick a date for the prepayment.');

  const kind = simMode();
  const months = Math.max(1, Math.min(600, +$('#sim-months').value || 12));
  simPlan.push({ id: ++simSeq, kind, amount, date, months, prepay_mode: $('#sim-prepay-mode').value });
  renderSimPlan();
  runSimulation();
}

function removeSimEntry(id) {
  simPlan = simPlan.filter((e) => e.id !== id);
  renderSimPlan();
  if (simPlan.length) runSimulation();
}

async function runSimulation() {
  if (!simPlan.length) return renderSimPlan();

  const r = await api(`/api/loans/${loanState.selected}/simulate`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ whatifs: simPlan.map(simSpec) }),
  });
  if (!r.ok) return alert(r.body.message ?? 'Could not run that simulation.');

  const s = r.body;
  const card = (label, value, cls = '') =>
    `<div class="bg-ink-700/40 border border-ink-600 rounded-xl p-3">
       <div class="text-xs text-slate-400">${label}</div>
       <div class="text-xl font-semibold mt-1 truncate ${cls}">${value}</div>
     </div>`;

  $('#sim-result').innerHTML =
      card('Interest saved', inrCompact(s.interest_saved), 'text-mint-400')
    + card('Months saved', s.months_saved, 'text-mint-400')
    + card('Extra you put in', inrCompact(s.extra_outlay))
    + card('New payoff', s.simulated.payoff_date ? DATE_LABEL(s.simulated.payoff_date) : '—');
  $('#sim-result').classList.remove('hidden');

  const note = $('#sim-note');
  const many = simPlan.length > 1;
  note.classList.remove('hidden');
  // A ratio above 1 means the prepayment returns more than it costs — the
  // risk-free comparison people usually get wrong. Across a plan it is the
  // blended return on every rupee in it, not on any one prepayment.
  note.innerHTML = s.return_ratio
    ? `Every ₹1 prepaid saves <strong class="text-mint-400">₹${s.return_ratio}</strong> in interest you would otherwise have paid${many ? ', taking the whole plan together' : ''}.
       That is a guaranteed, tax-free return — worth comparing against what the same money would earn invested.
       ${s.warnings.map((w) => `<div class="text-amber-300 mt-1">${esc(w)}</div>`).join('')}`
    : `${many ? 'Those prepayments do' : 'That prepayment does'} not change this loan.`;
}

/* ---- create / edit / delete ---- */
function openLoanModal(loan) {
  const f = $('#loan-form');
  f.reset();
  $('#loan-form-error').classList.add('hidden');
  $('#loan-modal-title').textContent = loan ? 'Edit loan' : 'Add loan';
  if (loan) {
    f.elements.id.value = loan.id;
    f.elements.name.value = loan.name;
    f.elements.lender.value = loan.lender ?? '';
    f.elements.loan_type.value = loan.loan_type;
    f.elements.principal.value = (loan.principal / 100).toFixed(2);
    f.elements.interest_rate_apr.value = loan.interest_rate_apr;
    f.elements.start_date.value = loan.start_date;
    f.elements.first_emi_date.value = loan.first_emi_date;
    f.elements.tenure_months.value = loan.tenure_months;
    f.elements.emi_amount.value = loan.emi_amount ? (loan.emi_amount / 100).toFixed(2) : '';
    f.elements.possession_date.value = loan.possession_date ?? '';
    f.elements.pre_emi_mode.value = loan.pre_emi_mode ?? 'pay';
    f.elements.notes.value = loan.notes ?? '';
  } else {
    f.elements.id.value = '';
  }
  $('#loan-modal').showModal();
}

async function submitLoan(e) {
  e.preventDefault();
  const f = $('#loan-form');
  const id = f.elements.id.value;
  const body = {
    name: f.elements.name.value,
    lender: f.elements.lender.value,
    loan_type: f.elements.loan_type.value,
    principal: f.elements.principal.value,
    interest_rate_apr: f.elements.interest_rate_apr.value,
    start_date: f.elements.start_date.value,
    first_emi_date: f.elements.first_emi_date.value,
    tenure_months: f.elements.tenure_months.value,
    emi_amount: f.elements.emi_amount.value || null,
    possession_date: f.elements.possession_date.value,
    pre_emi_mode: f.elements.pre_emi_mode.value,
    notes: f.elements.notes.value,
  };

  const r = await api(id ? '/api/loans/' + id : '/api/loans', {
    method: id ? 'PATCH' : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  if (!r.ok && !savedButBroken(r)) {
    // Errors belong in the modal, not an alert: the user has to fix a field.
    const box = $('#loan-form-error');
    box.textContent = r.body.message ?? 'Could not save this loan.';
    box.classList.remove('hidden');
    return;
  }

  $('#loan-modal').close();
  loanState.selected = String(r.body.loan?.id ?? r.body.id ?? loanState.selected);
  await loadLoans();
  loadDashboard();
}

async function deleteLoan() {
  const loan = loanState.report?.loan;
  if (!loan) return;
  if (!confirm(`Delete “${loan.name}”? Its schedule, rate changes, prepayments and payment links all go with it.\n\n`
    + 'Your ledger transactions are NOT deleted.')) return;

  const r = await api('/api/loans/' + loan.id, { method: 'DELETE' });
  if (!r.ok) return alert(r.body.message ?? 'Could not delete this loan.');
  loanState.selected = '';
  await loadLoans();
  loadDashboard();
}

/* ---- events ---- */
/** @param {object|null} ev an existing event to edit, or null to add a new one */
function openEventModal(ev = null) {
  const f = $('#event-form');
  f.reset();
  $('#event-form-error').classList.add('hidden');
  eventState.editing = ev?.id ?? null;
  $('#event-modal-title').textContent = ev ? 'Edit a change' : 'Add a change';
  $('#event-save').textContent = ev ? 'Save' : 'Add';

  const type = ev?.event_type ?? 'prepayment';
  f.elements.event_type.value = type;
  syncEventFields(type);

  // A broken loan has no schedule, so there is no "next due" to default to.
  f.elements.effective_date.value = ev?.effective_date
    ?? loanState.report?.position?.next_due?.due_date
    ?? loanState.report?.loan?.start_date
    ?? new Date().toISOString().slice(0, 10);

  if (ev) {
    f.elements.note.value = ev.note ?? '';
    if (type === 'prepayment') {
      f.elements.amount.value = (ev.amount / 100).toFixed(2);
      f.elements.prepay_mode.value = ev.mode ?? 'reduce_tenure';
    } else if (type === 'disbursement') {
      f.elements.disb_amount.value = (ev.amount / 100).toFixed(2);
      f.elements.disb_mode.value = ev.mode ?? 'keep_emi';
    } else if (type === 'rate_change') {
      f.elements.rate_apr.value = ev.rate_apr;
      f.elements.rate_mode.value = ev.mode ?? 'keep_emi';
    } else if (type === 'emi_change') {
      f.elements.emi_amount.value = (ev.emi_amount / 100).toFixed(2);
    }
  }
  $('#event-modal').showModal();
}

/** Which event the modal is editing, if any. */
const eventState = { editing: null };

/** Look an event up in whichever report we currently hold, broken or not. */
function findEvent(id) {
  return (loanState.report?.events ?? []).find((e) => e.id === id) ?? null;
}

function syncEventFields(type) {
  document.querySelectorAll('#event-form [data-ev]').forEach((el) => {
    el.classList.toggle('hidden', el.dataset.ev !== type);
  });
}

async function submitEvent(e) {
  e.preventDefault();
  const f = $('#event-form');
  const type = f.elements.event_type.value;
  const body = { event_type: type, effective_date: f.elements.effective_date.value, note: f.elements.note.value };

  if (type === 'prepayment') { body.amount = f.elements.amount.value; body.mode = f.elements.prepay_mode.value; }
  if (type === 'disbursement') { body.amount = f.elements.disb_amount.value; body.mode = f.elements.disb_mode.value; }
  if (type === 'rate_change') { body.rate_apr = f.elements.rate_apr.value; body.mode = f.elements.rate_mode.value; }
  if (type === 'emi_change') { body.emi_amount = f.elements.emi_amount.value; }

  const editing = eventState.editing;
  const r = await api(
    editing ? `/api/loans/${loanState.selected}/events/${editing}` : `/api/loans/${loanState.selected}/events`,
    { method: editing ? 'PATCH' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) },
  );
  if (!r.ok && !savedButBroken(r)) {
    const box = $('#event-form-error');
    box.textContent = r.body.message ?? 'Could not save this change.';
    box.classList.remove('hidden');
    return;
  }
  $('#event-modal').close();
  await renderLoanDetail();
  loadDashboard();
}

async function deleteLoanEvent(eventId) {
  if (!confirm('Remove this change? The schedule will be recalculated without it.')) return;
  const r = await api(`/api/loans/${loanState.selected}/events/${eventId}`, { method: 'DELETE' });
  if (!r.ok && !savedButBroken(r)) return alert(r.body.message ?? 'Could not remove that change.');
  await renderLoanDetail();
  loadDashboard();
}

async function unlinkInstalment(periodNo) {
  if (!confirm(`Unlink instalment #${periodNo}? It goes back to unpaid. The transaction stays in your ledger.`)) return;
  const r = await api(`/api/loans/${loanState.selected}/payments/${periodNo}`, { method: 'DELETE' });
  if (!r.ok && !savedButBroken(r)) return alert(r.body.message ?? 'Could not unlink that payment.');
  await renderLoanDetail();
}

/* ---- the ledger seam: link one transaction to one instalment ---- */
const linkState = { txn: null };

async function openLinkModal(txnId) {
  const row = lastLedger?.find((t) => t.id === txnId);
  if (!row) return;
  linkState.txn = row;

  $('#link-error').classList.add('hidden');
  $('#link-txn').innerHTML = `<div class="text-slate-200">${esc(row.description)}</div>
    <div class="mt-1">${DATE_LABEL(row.txn_date)} · ${inr(row.amount)} · ${esc(row.account_name)}</div>`;

  const r = await api('/api/loans');
  const open = (r.body.loans ?? []).filter((l) => !l.is_closed && !l.error);
  if (open.length === 0) {
    $('#link-error').textContent = 'Add a loan first — there is nothing to link this payment to.';
    $('#link-error').classList.remove('hidden');
  }
  $('#link-loan').innerHTML = open.map((l) => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
  await syncLinkPeriods();
  $('#link-modal').showModal();
}

/* Unpaid instalments, nearest the transaction's date first — the one you want is
 * almost always the first option. */
async function syncLinkPeriods() {
  const loanId = $('#link-loan').value;
  const sel = $('#link-period');
  if (!loanId) { sel.innerHTML = ''; return; }

  const r = await api('/api/loans/' + loanId, { quiet: true });
  if (!r.ok) { sel.innerHTML = ''; return; }

  const txnDate = linkState.txn.txn_date;
  const unpaid = r.body.periods.filter((p) => p.status !== 'paid');
  const byNearest = [...unpaid].sort((a, b) =>
    Math.abs(Date.parse(a.due_date) - Date.parse(txnDate)) - Math.abs(Date.parse(b.due_date) - Date.parse(txnDate)));

  sel.innerHTML = byNearest.map((p) => {
    const diff = linkState.txn.amount - p.emi;
    const flag = Math.abs(diff) > Math.max(p.emi * 0.02, 100)
      ? ` — differs by ${diff > 0 ? '+' : ''}${inr0(diff)}` : '';
    return `<option value="${p.period_no}">#${p.period_no} · due ${DATE_LABEL(p.due_date)} · ${inr0(p.emi)}${flag}</option>`;
  }).join('') || '<option value="">Every instalment is already paid</option>';
}

async function saveLink() {
  const loanId = $('#link-loan').value;
  const periodNo = $('#link-period').value;
  if (!loanId || !periodNo) return;

  const r = await api(`/api/loans/${loanId}/payments`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ period_no: +periodNo, txn_id: linkState.txn.id }),
  });
  if (!r.ok) {
    const box = $('#link-error');
    box.textContent = r.body.message ?? 'Could not link that payment.';
    box.classList.remove('hidden');
    return;
  }
  $('#link-modal').close();
  await renderLedger();
}

async function unlinkFromLedger(txnId) {
  const row = lastLedger?.find((t) => t.id === txnId);
  if (!row?.loan_id) return;
  if (!confirm(`Unlink this payment from “${row.loan_name}” instalment #${row.loan_period}?\n\n`
    + 'The instalment goes back to unpaid. The transaction stays in your ledger.')) return;

  const r = await api(`/api/loans/${row.loan_id}/payments/${row.loan_period}`, { method: 'DELETE' });
  if (!r.ok) return alert(r.body.message ?? 'Could not unlink that payment.');
  await renderLedger();
}

/* ---- wiring ---- */
$('#loan-picker').addEventListener('change', (e) => {
  loanState.selected = e.target.value;
  loanState.schedYear = 'all';
  loanState.schedLimit = 60;
  loadLoans();
});
$('#loan-add').addEventListener('click', () => openLoanModal(null));
$('#loan-edit').addEventListener('click', () => openLoanModal(loanState.report?.loan));
$('#loan-delete').addEventListener('click', deleteLoan);
$('#loan-cancel').addEventListener('click', () => $('#loan-modal').close());
$('#loan-form').addEventListener('submit', submitLoan);

$('#ln-add-event').addEventListener('click', () => openEventModal(null));
$('#event-cancel').addEventListener('click', () => $('#event-modal').close());
$('#event-form').addEventListener('submit', submitEvent);
$('#event-type').addEventListener('change', (e) => syncEventFields(e.target.value));

const onEventListClick = (e) => {
  const edit = e.target.closest('[data-edit-event]')?.dataset.editEvent;
  if (edit) return openEventModal(findEvent(+edit));
  const del = e.target.closest('[data-del-event]')?.dataset.delEvent;
  if (del) deleteLoanEvent(+del);
};
$('#ln-events').addEventListener('click', onEventListClick);
// The recovery card is the only way out of an unamortisable loan, so its events
// must be editable and removable from here too.
$('#loan-broken-events').addEventListener('click', onEventListClick);
$('#loan-broken-edit').addEventListener('click', () => openLoanModal(loanState.report?.loan));
$('#loan-broken-add').addEventListener('click', () => openEventModal(null));
$('#ln-schedule').addEventListener('click', (e) => {
  const p = e.target.closest('[data-unlink]')?.dataset.unlink;
  if (p) unlinkInstalment(+p);
});
$('#lp-rows').addEventListener('click', (e) => {
  const id = e.target.closest('[data-loan]')?.dataset.loan;
  if (!id) return;
  loanState.selected = id;
  $('#loan-picker').value = id;
  loadLoans();
});

$('#ln-sched-filter').addEventListener('change', (e) => {
  loanState.schedFilter = e.target.value;
  loanState.schedLimit = 60;
  renderSchedule();
});
$('#ln-sched-year').addEventListener('change', (e) => {
  loanState.schedYear = e.target.value;
  loanState.schedLimit = 60;
  renderSchedule();
});
$('#ln-sched-more-btn').addEventListener('click', () => { loanState.schedLimit += 120; renderSchedule(); });

document.querySelectorAll('#sim-mode .sim-mode-btn').forEach((b) => {
  b.addEventListener('click', () => setSimMode(b.dataset.mode));
});
$('#sim-add').addEventListener('click', addSimEntry);
$('#sim-clear').addEventListener('click', () => { simPlan = []; renderSimPlan(); });
$('#sim-plan').addEventListener('click', (e) => {
  const del = e.target.closest('[data-sim-del]');
  if (del) removeSimEntry(+del.dataset.simDel);
});
setSimMode('lumpsum');

$('#link-cancel').addEventListener('click', () => $('#link-modal').close());
$('#link-save').addEventListener('click', saveLink);
$('#link-loan').addEventListener('change', syncLinkPeriods);

/* ================= INVESTMENTS =================
   The asset-side mirror of loans. A holding's value comes from its latest
   valuation (there is no live price feed — this app makes no external calls);
   returns are XIRR over the events; the projection compounds the current value
   plus an optional SIP forward. Nothing here is stored except through the API. */
const INV_TYPE_LABELS = {
  equity: 'Stocks / Equity', mutual_fund: 'Mutual fund', gold: 'Gold',
  fd_rd: 'Fixed deposit / RD', bond: 'Bonds / debt', ppf_epf: 'PPF / EPF',
  crypto: 'Crypto', real_estate: 'Real estate', nps: 'NPS',
  insurance: 'ULIP / insurance', other: 'Other',
};
const INV_EVENT_LABELS = {
  buy: 'Buy', contribution: 'Contribution', sell: 'Sell',
  withdrawal: 'Withdrawal', dividend: 'Dividend',
};
const INV_INFLOWS = ['sell', 'withdrawal', 'dividend'];

const invState = { list: [], portfolio: null, selected: '', report: null, editingEvent: null };

async function loadInvestments() {
  const r = await api('/api/investments');
  if (!r.ok) return;
  invState.list = r.body.investments ?? [];
  invState.portfolio = r.body.portfolio ?? null;

  const keep = invState.selected;
  const picker = $('#inv-picker');
  picker.innerHTML = '<option value="">Whole portfolio</option>'
    + invState.list.map((h) => `<option value="${h.id}">${esc(h.name)}${h.is_closed ? ' (closed)' : ''}</option>`).join('');
  picker.value = invState.list.some((h) => String(h.id) === keep) ? keep : '';
  invState.selected = picker.value;

  $('#inv-empty').classList.toggle('hidden', invState.list.length > 0);
  if (invState.selected) {
    await renderInvestmentDetail();
  } else {
    renderInvPortfolio();
  }
}

function renderInvPortfolio() {
  $('#inv-detail').classList.add('hidden');
  const show = invState.list.length > 0;
  $('#inv-portfolio').classList.toggle('hidden', !show);
  if (!show) return;

  const p = invState.portfolio;
  $('#ip-value').textContent = inr(p.current_value);
  $('#ip-value-sub').textContent = `${p.open_count} holding${p.open_count === 1 ? '' : 's'}`;
  $('#ip-invested').textContent = inr(p.net_invested);
  const gain = p.unrealised_gain;
  $('#ip-gain').textContent = (gain >= 0 ? '+' : '') + inr(gain);
  $('#ip-gain').className = 'text-2xl font-semibold mt-1 truncate ' + (gain >= 0 ? 'text-mint-400' : 'text-rose-300');
  $('#ip-gain-sub').textContent = p.net_invested > 0 ? `${(gain / p.net_invested * 100).toFixed(1)}% on invested` : '';
  $('#ip-xirr').textContent = p.xirr === null ? '—' : `${(p.xirr * 100).toFixed(1)}%`;
  $('#ip-xirr').className = 'text-2xl font-semibold mt-1 truncate ' + ((p.xirr ?? 0) >= 0 ? 'text-mint-400' : 'text-rose-300');

  $('#ip-holdings').innerHTML = invState.list.map((h) => {
    const r = h.returns;
    const g = r.absolute_gain;
    return `
      <button data-open-holding="${h.id}"
              class="w-full flex items-center justify-between gap-3 bg-ink-700/60 rounded-lg px-3 py-2 hover:bg-ink-600/70 border-l-4 text-left"
              style="border-left-color:${h.color || ACCOUNT_FALLBACK}">
        <span class="flex items-center gap-2 min-w-0">
          ${accountDot(h.color)}
          <span class="truncate">${esc(h.name)}
            <span class="text-slate-500 text-xs">· ${esc(INV_TYPE_LABELS[h.instrument_type] ?? h.instrument_type)}${h.is_closed ? ' · closed' : ''}</span></span>
        </span>
        <span class="shrink-0 text-right">
          <span class="font-semibold tabular-nums">${inr(r.current_value)}</span>
          <span class="block text-[11px] tabular-nums ${g >= 0 ? 'text-mint-400' : 'text-rose-300'}">
            ${g >= 0 ? '+' : ''}${inrCompact(g, 1)}${r.xirr !== null ? ` · ${(r.xirr * 100).toFixed(1)}% XIRR` : ''}</span>
        </span>
      </button>`;
  }).join('') || '<div class="text-slate-500">No holdings.</div>';

  const maxType = Math.max(1, ...p.by_type.map((t) => t.value));
  $('#ip-bytype').innerHTML = p.by_type.length === 0
    ? '<div class="text-slate-500">Nothing held yet.</div>'
    : p.by_type.map((t) => `
      <div>
        <div class="flex justify-between text-xs mb-0.5">
          <span>${esc(INV_TYPE_LABELS[t.type] ?? t.type)}</span>
          <span class="tabular-nums text-slate-400">${inrCompact(t.value, 1)} · ${Math.round(t.value / p.current_value * 100)}%</span>
        </div>
        <div class="h-2 rounded-full bg-ink-700 overflow-hidden">
          <div class="h-full rounded-full bg-mint-500" style="width:${(t.value / maxType * 100).toFixed(1)}%"></div>
        </div>
      </div>`).join('');
}

async function renderInvestmentDetail() {
  const r = await api('/api/investments/' + invState.selected);
  if (!r.ok) { renderInvPortfolio(); return; }
  const d = invState.report = r.body;
  const h = d.investment;
  const ret = d.returns;

  $('#inv-portfolio').classList.add('hidden');
  $('#inv-detail').classList.remove('hidden');

  $('#inv-name').innerHTML = `${accountDot(h.color)}<span>${esc(h.name)}</span>`;
  $('#inv-sub').textContent = [INV_TYPE_LABELS[h.instrument_type] ?? h.instrument_type, h.platform, h.is_closed ? 'closed' : null]
    .filter(Boolean).join(' · ');

  $('#inv-stale').classList.toggle('hidden', !ret.stale);
  if (ret.stale) {
    $('#inv-stale').textContent = `Last valued ${DATE_LABEL(ret.valued_on)} — over 45 days ago. Record a fresh value to keep XIRR and net worth accurate.`;
  }

  $('#id-value').textContent = inr(ret.current_value);
  $('#id-value-sub').textContent = ret.valued_on ? `as of ${DATE_LABEL(ret.valued_on)}` : 'no valuation yet';
  $('#id-invested').textContent = inr(ret.net_invested);
  const g = ret.absolute_gain;
  $('#id-gain').textContent = (g >= 0 ? '+' : '') + inr(g);
  $('#id-gain').className = 'text-2xl font-semibold mt-1 truncate ' + (g >= 0 ? 'text-mint-400' : 'text-rose-300');
  $('#id-gain-sub').textContent = ret.simple_return_pct !== null ? `${ret.simple_return_pct}% on invested` : '';
  $('#id-xirr').textContent = ret.xirr === null ? '—' : `${(ret.xirr * 100).toFixed(1)}%`;
  $('#id-xirr').className = 'text-2xl font-semibold mt-1 truncate ' + ((ret.xirr ?? 0) >= 0 ? 'text-mint-400' : 'text-rose-300');

  renderInvValuations(d.valuations);
  renderInvEvents(d.events);
  runProjection();
}

function renderInvValuations(vals) {
  $('#inv-valuations').innerHTML = vals.length === 0
    ? '<div class="text-slate-500">No valuations yet. Record what it is worth today.</div>'
    : vals.map((v, i) => `
      <div class="flex items-center justify-between gap-3 bg-ink-700/40 border border-ink-600 rounded-xl px-3 py-2">
        <span>${DATE_LABEL(v.valued_on)}${i === 0 ? ' <span class="text-[11px] text-mint-400">· current</span>' : ''}</span>
        <span class="flex items-center gap-3">
          <span class="font-semibold tabular-nums">${inr(v.value)}</span>
          <button data-del-valuation="${v.id}" title="Remove" class="text-slate-400 hover:text-rose-300">&times;</button>
        </span>
      </div>`).join('');
}

function renderInvEvents(events) {
  $('#inv-events').innerHTML = events.length === 0
    ? '<div class="text-slate-500">No cashflows yet. Add a buy or a contribution.</div>'
    : events.map((e) => {
      const inflow = INV_INFLOWS.includes(e.event_type);
      return `
      <div class="flex items-center justify-between gap-3 bg-ink-700/40 border border-ink-600 rounded-xl px-3 py-2">
        <span class="min-w-0">
          <span class="inline-block w-2 h-2 rounded-full align-middle mr-2" style="background:${inflow ? '#34d399' : '#fb7185'}"></span>
          <span class="text-slate-200">${INV_EVENT_LABELS[e.event_type]}</span>
          <span class="text-slate-500 text-xs">· ${DATE_LABEL(e.event_date)}${e.units ? ` · ${e.units} units` : ''}${e.from_ledger ? ' · from ledger' : ''}</span>
        </span>
        <span class="flex items-center gap-3 shrink-0">
          <span class="font-semibold tabular-nums ${inflow ? 'text-mint-400' : 'text-slate-200'}">${inflow ? '+' : '−'}${inr(e.amount)}</span>
          ${e.from_ledger
            ? '<span class="text-[11px] text-slate-500">linked</span>'
            : `<button data-edit-ievent="${e.id}" class="text-slate-400 hover:text-slate-200">✎</button>
               <button data-del-ievent="${e.id}" class="text-slate-400 hover:text-rose-300">&times;</button>`}
        </span>
      </div>`;
    }).join('');
}

async function runProjection() {
  if (!invState.selected) return;
  const months = +$('#inv-proj-years').value;
  const sip = $('#inv-proj-sip').value || '0';
  const r = await api(`/api/investments/${invState.selected}/project`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ months, monthly_sip: sip }), quiet: true,
  });
  if (!r.ok) return;
  const p = r.body.projection;

  const src = { expected: 'your expected rate', xirr: 'this holding\'s realised XIRR', type_default: 'a default for its type' }[p.rate_source];
  $('#inv-proj-rate').textContent = `at ${p.rate_apr}% a year (${src})`;

  const card = (label, value, cls = '') =>
    `<div class="bg-ink-700/40 border border-ink-600 rounded-xl p-3">
       <div class="text-xs text-slate-400">${label}</div>
       <div class="text-xl font-semibold mt-1 truncate ${cls}">${value}</div>
     </div>`;
  $('#inv-proj-result').innerHTML =
      card('Projected value', inrCompact(p.projected_value, 2), 'text-mint-400')
    + card('You will have put in', inrCompact(p.total_invested, 2))
    + card('Projected gain', '+' + inrCompact(p.projected_gain, 2), 'text-mint-400')
    + card('By', DATE_LABEL(p.target_date));
}

/* ---- create / edit / delete a holding ---- */
function openInvModal(h = null) {
  const f = $('#inv-form');
  f.reset();
  $('#inv-form-error').classList.add('hidden');
  $('#inv-modal-title').textContent = h ? 'Edit holding' : 'Add holding';
  f.id.value = h?.id ?? '';
  if (h) {
    f.name.value = h.name;
    f.instrument_type.value = h.instrument_type;
    f.platform.value = h.platform ?? '';
    f.expected_return_apr.value = h.expected_return_apr ?? '';
  }
  $('#inv-modal').showModal();
}

async function submitHolding(e) {
  e.preventDefault();
  const f = e.target;
  const id = f.id.value;
  const body = {
    name: f.name.value.trim(),
    instrument_type: f.instrument_type.value,
    platform: f.platform.value.trim(),
    expected_return_apr: f.expected_return_apr.value,
  };
  const r = await api(id ? `/api/investments/${id}` : '/api/investments', {
    method: id ? 'PATCH' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!r.ok) {
    $('#inv-form-error').textContent = r.body.message ?? 'Could not save.';
    $('#inv-form-error').classList.remove('hidden');
    return;
  }
  $('#inv-modal').close();
  if (!id) invState.selected = String(r.body.investment.id);
  await loadInvestments();
}

async function deleteHolding() {
  if (!invState.selected) return;
  const h = invState.report?.investment;
  if (!confirm(`Delete "${h?.name}"? Its valuations, cashflows and derived account go too. The tagged ledger transactions stay.`)) return;
  await api('/api/investments/' + invState.selected, { method: 'DELETE' });
  invState.selected = '';
  await loadInvestments();
}

/* ---- events ---- */
function openIEventModal(ev = null) {
  const f = $('#ievent-form');
  f.reset();
  $('#ievent-form-error').classList.add('hidden');
  invState.editingEvent = ev?.id ?? null;
  $('#ievent-modal-title').textContent = ev ? 'Edit cashflow' : 'Add a cashflow';
  $('#ievent-save').textContent = ev ? 'Save' : 'Add';
  f.id.value = ev?.id ?? '';
  if (ev) {
    f.event_type.value = ev.event_type;
    f.event_date.value = ev.event_date;
    f.amount.value = (ev.amount / 100).toFixed(2);
    f.units.value = ev.units ?? '';
    f.price.value = ev.price != null ? (ev.price / 100).toFixed(2) : '';
  } else {
    f.event_date.value = new Date().toISOString().slice(0, 10);
  }
  $('#ievent-modal').showModal();
}

async function submitIEvent(e) {
  e.preventDefault();
  const f = e.target;
  const id = f.id.value;
  const body = {
    event_type: f.event_type.value,
    event_date: f.event_date.value,
    amount: f.amount.value,
    units: f.units.value,
    price: f.price.value,
  };
  const r = await api(
    id ? `/api/investments/${invState.selected}/events/${id}` : `/api/investments/${invState.selected}/events`,
    { method: id ? 'PATCH' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!r.ok) {
    $('#ievent-form-error').textContent = r.body.message ?? 'Could not save.';
    $('#ievent-form-error').classList.remove('hidden');
    return;
  }
  $('#ievent-modal').close();
  await loadInvestments();
}

/* ---- valuations ---- */
function openIValModal() {
  const f = $('#ival-form');
  f.reset();
  $('#ival-form-error').classList.add('hidden');
  f.valued_on.value = new Date().toISOString().slice(0, 10);
  $('#ival-modal').showModal();
}

async function submitIValuation(e) {
  e.preventDefault();
  const f = e.target;
  const r = await api(`/api/investments/${invState.selected}/valuations`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ value: f.value.value, valued_on: f.valued_on.value }),
  });
  if (!r.ok) {
    $('#ival-form-error').textContent = r.body.message ?? 'Could not save.';
    $('#ival-form-error').classList.remove('hidden');
    return;
  }
  $('#ival-modal').close();
  await loadInvestments();
}

/* ---- ledger seam: tag a debit as a contribution ---- */
let investLinkTxn = null;

async function openInvestLink(txnId) {
  const row = lastLedger.find((t) => t.id === txnId);
  if (!row) return;
  investLinkTxn = txnId;
  $('#ilink-error').classList.add('hidden');

  // Load holdings if the page has not been opened yet this session.
  if (invState.list.length === 0) {
    const r = await api('/api/investments', { quiet: true });
    if (r.ok) invState.list = r.body.investments ?? [];
  }
  $('#ilink-txn').innerHTML = `<div class="font-medium text-slate-200">${esc(row.description)}</div>`
    + `<div class="mt-1">${DATE_LABEL(row.txn_date)} · ${inr(row.amount)} · ${esc(row.account_name)}</div>`;

  const open = invState.list.filter((h) => !h.is_closed);
  $('#ilink-holding').innerHTML = open.length === 0
    ? '<option value="">No holdings yet — add one on the Investments page</option>'
    : open.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
  $('#ilink-save').disabled = open.length === 0;
  $('#ilink-modal').showModal();
}

async function saveInvestLink() {
  const id = $('#ilink-holding').value;
  if (!id || !investLinkTxn) return;
  const r = await api(`/api/investments/${id}/contributions`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ txn_id: investLinkTxn }),
  });
  if (!r.ok) {
    $('#ilink-error').textContent = r.body.message ?? 'Could not link.';
    $('#ilink-error').classList.remove('hidden');
    return;
  }
  $('#ilink-modal').close();
  renderLedger();
  loadAccounts();
}

async function unlinkInvestFromLedger(txnId) {
  const row = lastLedger.find((t) => t.id === txnId);
  if (!row || !confirm(`Unlink this contribution from “${row.investment_name}”?\n\nThe transaction stays; only the investment link is removed.`)) return;
  await api(`/api/investments/${row.investment_id}/contributions/${txnId}`, { method: 'DELETE' });
  renderLedger();
  loadAccounts();
}

$('#ilink-cancel').addEventListener('click', () => $('#ilink-modal').close());
$('#ilink-save').addEventListener('click', saveInvestLink);

/* ---- wiring ---- */
$('#inv-picker').addEventListener('change', (e) => { invState.selected = e.target.value; loadInvestments(); });
$('#inv-add').addEventListener('click', () => openInvModal());
$('#inv-cancel').addEventListener('click', () => $('#inv-modal').close());
$('#inv-form').addEventListener('submit', submitHolding);
$('#inv-edit').addEventListener('click', () => openInvModal(invState.report?.investment));
$('#inv-delete').addEventListener('click', deleteHolding);
$('#inv-add-event').addEventListener('click', () => openIEventModal());
$('#ievent-cancel').addEventListener('click', () => $('#ievent-modal').close());
$('#ievent-form').addEventListener('submit', submitIEvent);
$('#inv-add-valuation').addEventListener('click', () => openIValModal());
$('#ival-cancel').addEventListener('click', () => $('#ival-modal').close());
$('#ival-form').addEventListener('submit', submitIValuation);
$('#inv-proj-years').addEventListener('change', runProjection);
$('#inv-proj-sip').addEventListener('input', () => { clearTimeout(invState._sipTimer); invState._sipTimer = setTimeout(runProjection, 300); });

$('#ip-holdings').addEventListener('click', (e) => {
  const b = e.target.closest('[data-open-holding]');
  if (b) { invState.selected = b.dataset.openHolding; $('#inv-picker').value = invState.selected; loadInvestments(); }
});
$('#inv-events').addEventListener('click', (e) => {
  const ed = e.target.closest('[data-edit-ievent]');
  const del = e.target.closest('[data-del-ievent]');
  if (ed) openIEventModal((invState.report?.events ?? []).find((x) => x.id === +ed.dataset.editIevent));
  if (del && confirm('Delete this cashflow?')) {
    api(`/api/investments/${invState.selected}/events/${del.dataset.delIevent}`, { method: 'DELETE' }).then(loadInvestments);
  }
});
$('#inv-valuations').addEventListener('click', (e) => {
  const del = e.target.closest('[data-del-valuation]');
  if (del && confirm('Remove this valuation?')) {
    api(`/api/investments/${invState.selected}/valuations/${del.dataset.delValuation}`, { method: 'DELETE' }).then(loadInvestments);
  }
});

/* ---------------- boot ---------------- */
history.replaceState({ tab: tabFromPath() }, '', '/' + tabFromPath());
showTab(tabFromPath(), false);   // open the tab named by the URL; triggers its loader
loadAccounts();
loadUploads();
loadFormats();
loadRules();
loadIdentity();
loadExclusions();
loadNotifications();
loadReminders();
loadBudgets();
