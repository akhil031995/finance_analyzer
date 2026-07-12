<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require dirname(__DIR__) . '/vendor/autoload.php';

// Mutable: the bind-mounted .env is re-read on every request, so key changes
// apply on refresh without recreating the container (dev-mode convenience).
if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Kolkata');

// Ensure the SQLite schema exists on first boot.
/** @var PDO $pdo */
$pdo = require dirname(__DIR__) . '/bin/migrate.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware((bool) ($_ENV['APP_DEBUG'] ?? false), true, true);

// Serve the SPA shell for the root and every nav page, so each tab has a real,
// bookmarkable/refreshable URL (client-side History API routing takes over).
$serveApp = function (Request $request, Response $response): Response {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/app.html'));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
};
$app->get('/', $serveApp);
$app->get('/{page:dashboard|analytics|loans|investments|budget|upload|review|ledger|logs|settings}', $serveApp);

$app->get('/health', function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['status' => 'ok', 'time' => date('c')]));
    return $response->withHeader('Content-Type', 'application/json');
});

$logService    = new App\Services\LogService($pdo);
$commitFactory = fn (): App\Services\CommitService => new App\Services\CommitService($pdo);
$formats       = new App\Services\Csv\BankFormatRepository($pdo);
$uploadDir     = dirname(__DIR__) . '/storage/uploads';

// --- CSV import: upload -> preview -> map -> validate -> stage ---
$importService = new App\Services\ImportService(
    $pdo,
    $formats,
    new App\Services\Csv\CsvStatementParser(),
    $logService,
);
$imports = new App\Controllers\ImportController($pdo, $importService, $formats, $uploadDir, $logService);

$app->post('/api/imports', [$imports, 'create']);
$app->get('/api/imports/{id:[0-9]+}/preview', [$imports, 'preview']);
$app->post('/api/imports/{id:[0-9]+}/validate', [$imports, 'validate']);
$app->post('/api/imports/{id:[0-9]+}/stage', [$imports, 'stage']);
$app->post('/api/imports/{id:[0-9]+}/remap', [$imports, 'remap']);
$app->delete('/api/imports/{id:[0-9]+}', [$imports, 'delete']);

// --- Review + commit ---
$uploads = new App\Controllers\UploadController($pdo, $commitFactory);
$app->get('/api/uploads', [$uploads, 'list']);
$app->get('/api/uploads/{id:[0-9]+}/staged', [$uploads, 'staged']);
$app->post('/api/uploads/{id:[0-9]+}/commit', [$uploads, 'commit']);
$app->patch('/api/staged/{rowId:[0-9]+}', [$uploads, 'updateStaged']);

// --- Saved CSV layouts (one mapping per bank, matched by header fingerprint) ---
$bankFormats = new App\Controllers\BankFormatController($formats);
$app->get('/api/bank-formats', [$bankFormats, 'list']);
$app->patch('/api/bank-formats/{id:[0-9]+}', [$bankFormats, 'update']);
$app->delete('/api/bank-formats/{id:[0-9]+}', [$bankFormats, 'delete']);

// --- Rule-based auto-tagging (replaces the AI tagger) ---
$rules = new App\Controllers\TaggingRuleController($pdo);
$app->get('/api/tagging-rules', [$rules, 'list']);
$app->post('/api/tagging-rules', [$rules, 'create']);
$app->patch('/api/tagging-rules/{id:[0-9]+}', [$rules, 'update']);
$app->delete('/api/tagging-rules/{id:[0-9]+}', [$rules, 'delete']);
$app->post('/api/uploads/{id:[0-9]+}/retag', [$rules, 'retag']);
$app->get('/api/settings/identity', [$rules, 'getIdentity']);
$app->post('/api/settings/identity', [$rules, 'saveIdentity']);

// --- Excluded tags: what does NOT count as income or expense (balances unaffected) ---
$exclusions = new App\Controllers\ExclusionController($pdo);
$app->get('/api/settings/excluded-categories', [$exclusions, 'list']);
$app->post('/api/settings/excluded-categories', [$exclusions, 'save']);

// --- Accounts (list/create/edit/archive + debt details) ---
$accounts = new App\Controllers\AccountController($pdo);
$app->get('/api/accounts', [$accounts, 'list']);
$app->get('/api/accounts/archived', [$accounts, 'listArchived']);
$app->post('/api/accounts', [$accounts, 'create']);
$app->patch('/api/accounts/{id:[0-9]+}', [$accounts, 'update']);
$app->get('/api/accounts/health', [$accounts, 'health']);
$app->post('/api/accounts/{id:[0-9]+}/archive', [$accounts, 'archive']);
$app->post('/api/accounts/{id:[0-9]+}/unarchive', [$accounts, 'unarchive']);

// --- Ledger: committed transactions view + manual entry + edit + CSV export ---
$transactions = new App\Controllers\TransactionController($pdo, $commitFactory);
$app->get('/api/transactions', [$transactions, 'list']);
$app->get('/api/transactions/export', [$transactions, 'export']);
$app->post('/api/transactions', [$transactions, 'create']);
$app->post('/api/transactions/bulk', [$transactions, 'bulk']);
$app->patch('/api/transactions/{id:[0-9]+}', [$transactions, 'update']);
$app->delete('/api/transactions/{id:[0-9]+}', [$transactions, 'delete']);

// --- Budgets: monthly limits + derived daily allowance ---
$budgets = new App\Controllers\BudgetController($pdo);
$app->get('/api/budgets', [$budgets, 'list']);
$app->get('/api/budgets/analysis', [$budgets, 'analysis']);
$app->post('/api/budgets', [$budgets, 'upsert']);
$app->delete('/api/budgets/{category}', [$budgets, 'delete']);

// --- Dashboard analytics (net worth, ladder, averages, MoM, monthly series) ---
// A loan account has no ledger rows, so its debt over time comes from the
// amortisation schedule, not from transactions.
$dashboard = new App\Controllers\DashboardController($pdo, new App\Services\Loan\LoanService($pdo));
$app->get('/api/dashboard', [$dashboard, 'index']);

// --- Analytics page: month / calendar year / financial year deep-dive ---
$analytics = new App\Controllers\AnalyticsController(new App\Services\AnalyticsService($pdo));
$app->get('/api/analytics', [$analytics, 'index']);

// --- Loans: event-sourced amortisation, independent of the ledger ---
// Each loan owns a derived liability account, so net worth needs no changes.
$loanService = new App\Services\Loan\LoanService($pdo);
$loans       = new App\Controllers\LoanController($pdo, $loanService);
$app->get('/api/loans', [$loans, 'index']);
$app->post('/api/loans', [$loans, 'create']);
$app->get('/api/loans/{id:[0-9]+}', [$loans, 'show']);
$app->patch('/api/loans/{id:[0-9]+}', [$loans, 'update']);
$app->delete('/api/loans/{id:[0-9]+}', [$loans, 'destroy']);
$app->post('/api/loans/{id:[0-9]+}/events', [$loans, 'addEvent']);
$app->patch('/api/loans/{id:[0-9]+}/events/{eventId:[0-9]+}', [$loans, 'updateEvent']);
$app->delete('/api/loans/{id:[0-9]+}/events/{eventId:[0-9]+}', [$loans, 'deleteEvent']);
$app->post('/api/loans/{id:[0-9]+}/simulate', [$loans, 'simulate']);
$app->get('/api/loans/{id:[0-9]+}/candidates', [$loans, 'candidates']);
$app->post('/api/loans/{id:[0-9]+}/payments', [$loans, 'linkPayment']);
$app->delete('/api/loans/{id:[0-9]+}/payments/{periodNo:[0-9]+}', [$loans, 'unlinkPayment']);

// --- Investments: the asset-side mirror of loans. Each holding owns a derived
// asset account, so net worth needs no changes. Returns are XIRR over the events. ---
$investmentService = new App\Services\Investment\InvestmentService($pdo);
$investments = new App\Controllers\InvestmentController($pdo, $investmentService);
$app->get('/api/investments', [$investments, 'index']);
$app->post('/api/investments', [$investments, 'create']);
$app->get('/api/investments/{id:[0-9]+}', [$investments, 'show']);
$app->patch('/api/investments/{id:[0-9]+}', [$investments, 'update']);
$app->delete('/api/investments/{id:[0-9]+}', [$investments, 'destroy']);
$app->post('/api/investments/{id:[0-9]+}/events', [$investments, 'addEvent']);
$app->patch('/api/investments/{id:[0-9]+}/events/{eventId:[0-9]+}', [$investments, 'updateEvent']);
$app->delete('/api/investments/{id:[0-9]+}/events/{eventId:[0-9]+}', [$investments, 'deleteEvent']);
$app->post('/api/investments/{id:[0-9]+}/valuations', [$investments, 'addValuation']);
$app->delete('/api/investments/{id:[0-9]+}/valuations/{valuationId:[0-9]+}', [$investments, 'deleteValuation']);
$app->post('/api/investments/{id:[0-9]+}/project', [$investments, 'project']);
$app->get('/api/investments/{id:[0-9]+}/candidates', [$investments, 'candidates']);
$app->post('/api/investments/{id:[0-9]+}/contributions', [$investments, 'linkContribution']);
$app->delete('/api/investments/{id:[0-9]+}/contributions/{txnId:[0-9]+}', [$investments, 'unlinkContribution']);

// --- Event log (upload / CSV parsing / commit activity trail) ---
$logs = new App\Controllers\LogController($pdo);
$app->get('/api/logs', [$logs, 'list']);
$app->delete('/api/logs', [$logs, 'clear']);

// --- Notifications: Telegram config, reminders, manual test triggers ---
$notify = new App\Controllers\NotificationController($pdo);
$app->get('/api/settings/notifications', [$notify, 'getConfig']);
$app->post('/api/settings/notifications', [$notify, 'saveConfig']);
$app->post('/api/settings/notifications/test', [$notify, 'test']);
$app->post('/api/settings/notifications/summary', [$notify, 'runSummary']);
$app->get('/api/settings/reminders', [$notify, 'listReminders']);
$app->post('/api/settings/reminders', [$notify, 'createReminder']);
$app->patch('/api/settings/reminders/{id:[0-9]+}', [$notify, 'updateReminder']);
$app->delete('/api/settings/reminders/{id:[0-9]+}', [$notify, 'deleteReminder']);

$app->run();
