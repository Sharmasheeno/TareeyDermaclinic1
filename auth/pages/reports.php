<?php
/**
 * auth/pages/reports.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Financial Reports (Income Statement, Balance Sheet)
 * ---------------------------------------------------------------------
 * Two read-only financial statements computed live from the Accounting
 * ledger (see accounting.php for the ledger itself — this page never
 * writes to it):
 *
 *   1. Income Statement (Profit & Loss) — Revenue and Expense activity
 *      for a chosen period, normalized to each account's normal balance
 *      side (see tdc_normal_side_amount()).
 *   2. Balance Sheet — Asset, Liability and Equity balances as of a
 *      chosen date, cumulative since inception (not period-bound).
 *
 * Design decisions:
 *   - This page is read-only by design: no POST handler, no persistence
 *     functions, no CSRF-checked writes. The only state on the page is
 *     which report and which date range/date to view, both carried in
 *     the query string.
 *   - "Retained Earnings (Current)" on the Balance Sheet is computed,
 *     not stored: it is the running Net Income across all Revenue/
 *     Expense activity up to the as-of date that has not been formally
 *     closed to an Equity account (this app has no period-close
 *     workflow, so nothing ever needs to be — the running total already
 *     represents undistributed earnings). Because every journal entry
 *     accounting.php accepts is balanced (debits = credits, enforced in
 *     tdc_validate_journal_form() there), the accounting identity
 *     Assets = Liabilities + Equity holds by construction. This page
 *     verifies that identity on every render and displays the result —
 *     it is a check, not an assumption, and would surface any ledger
 *     corrupted outside the app (e.g. a direct database edit).
 *   - The letterhead (company name/address/phone) printed at the top of
 *     each statement is read from PrescriptionsHeader, the clinic's
 *     existing single source of truth for that information, rather than
 *     hardcoded here a second time.
 *
 * Security controls (same posture as home.php / accounting.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: only 'superuser' may open this page — same reasoning
 *     as accounting.php (no dedicated finance role exists, and this is
 *     the most sensitive financial data in the app)
 *   - All session/user-derived and query-string-derived output escaped
 *     before hitting HTML
 * ---------------------------------------------------------------------
 */
declare(strict_types=1);

// =======================================================================
// SECTION 1 — Session bootstrap & defensive headers
// =======================================================================
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_SECTIONS      = ['income-statement', 'balance-sheet'];
const ALLOWED_REPORTS_ROLES = ['superuser'];

/** Account types whose normal balance sits on the credit side (matches accounting.php). */
const NORMAL_CREDIT_TYPES = ['Liability', 'Equity', 'Revenue'];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / doctors.php / patients.php / pharmacy.php /
 * accounting.php / settings.php.
 */
const NAV_ITEMS = [
    [
        'href'  => 'home.php',
        'label' => 'Dashboard',
        'icon'  => '<path d="M3 4a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>',
    ],
    [
        'href'  => 'reception.php',
        'label' => 'Reception',
        'icon'  => '<path d="M10 2a1 1 0 011 1v1.06A6.002 6.002 0 0116 10v3l1.3 2.6a1 1 0 01-.9 1.4H3.6a1 1 0 01-.9-1.4L4 13v-3a6.002 6.002 0 015-5.94V3a1 1 0 011-1zM8 18a2 2 0 004 0H8z"/>',
    ],
    [
        'href'  => 'doctors.php',
        'label' => 'Doctors',
        'icon'  => '<path d="M7 2a1 1 0 00-1 1v3a1 1 0 002 0V4h4v2a1 1 0 002 0V3a1 1 0 00-1-1H7zM6 8a1 1 0 00-1 1v3a5 5 0 0010 0V9a1 1 0 10-2 0v3a3 3 0 11-6 0V9a1 1 0 00-1-1zm8 8a2 2 0 11-4 0h4z"/>',
    ],
    [
        'href'  => 'patients.php',
        'label' => 'Patients',
        'icon'  => '<path d="M10 2a3 3 0 100 6 3 3 0 000-6zM4 17a6 6 0 1112 0v1H4v-1z"/>',
    ],
    [
        'href'  => 'laboratory.php',
        'label' => 'Laboratory',
        'icon'  => '<path d="M8 2a1 1 0 000 2v4.586l-4.243 4.243A2 2 0 005.172 16h9.656a2 2 0 001.415-3.171L12 8.586V4a1 1 0 100-2H8zm2 2h0v5a1 1 0 01-.293.707L7.4 12h5.2l-2.307-2.293A1 1 0 0110 9V4z"/>',
    ],
    [
        'href'  => 'pharmacy.php',
        'label' => 'Pharmacy',
        'icon'  => '<path d="M13.657 2.343a4 4 0 00-5.657 0L2.343 8a4 4 0 105.657 5.657l5.657-5.657a4 4 0 000-5.657zM8.5 6.5l5 5-1.5 1.5-5-5 1.5-1.5z"/>',
    ],
    [
        'href'  => 'accounting.php',
        'label' => 'Accounting',
        'icon'  => '<path fill-rule="evenodd" d="M4 3a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1H4zm2 3h8v2H6V6zm0 4h8v2H6v-2zm0 4h5v2H6v-2z" clip-rule="evenodd"/>',
    ],
    [
        'href'  => 'reports.php',
        'label' => 'Reports',
        'icon'  => '<path d="M4 13a1 1 0 011-1h1a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm5-5a1 1 0 011-1h1a1 1 0 011 1v9a1 1 0 01-1 1h-1a1 1 0 01-1-1V8zm5-4a1 1 0 011-1h1a1 1 0 011 1v13a1 1 0 01-1 1h-1a1 1 0 01-1-1V4z"/>',
    ],
    [
        'href'  => 'settings.php',
        'label' => 'Settings',
        'icon'  => '<path fill-rule="evenodd" d="M8.34 1.804A1 1 0 019.32 1h1.36a1 1 0 01.98.804l.331 1.652a6.993 6.993 0 011.929 1.115l1.598-.54a1 1 0 011.186.447l.68 1.178a1 1 0 01-.223 1.28l-1.281 1.05a7.05 7.05 0 010 2.228l1.28 1.05a1 1 0 01.224 1.28l-.68 1.178a1 1 0 01-1.187.447l-1.598-.54a6.993 6.993 0 01-1.929 1.115l-.33 1.652a1 1 0 01-.98.804H9.32a1 1 0 01-.98-.804l-.331-1.652a6.993 6.993 0 01-1.929-1.115l-1.598.54a1 1 0 01-1.186-.447l-.68-1.178a1 1 0 01.223-1.28l1.281-1.05a7.05 7.05 0 010-2.228l-1.28-1.05a1 1 0 01-.224-1.28l.68-1.178a1 1 0 011.187-.447l1.598.54A6.993 6.993 0 018.01 3.456l.33-1.652zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/>',
    ],
];

// =======================================================================
// SECTION 3 — Pure helper functions (no I/O beyond the given PDO)
// =======================================================================

/** Escapes a value for safe HTML output. Single source of truth. */
function tdc_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Strips a leading honorific (Dr., Mr., Mrs., Ms., Prof.) and returns
 * the first remaining token, for a friendlier greeting/avatar.
 */
function tdc_display_name(string $legalName): string
{
    $titles = ['dr', 'mr', 'mrs', 'ms', 'prof'];
    $parts  = preg_split('/\s+/', trim($legalName)) ?: [];

    while (!empty($parts) && in_array(strtolower(rtrim($parts[0], '.')), $titles, true)) {
        array_shift($parts);
    }

    $remaining = trim(implode(' ', $parts));

    return $remaining !== '' ? $remaining : ($legalName !== '' ? $legalName : 'User');
}

/** Strict Y-m-d date validator (rejects "2026-02-31" style overflow dates). */
function tdc_is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/**
 * Self-contained, CSRF-guarded logout (?logout=1&csrf=...).
 * Exits the script when a logout is processed; otherwise returns.
 */
function tdc_handle_logout(): void
{
    if (!isset($_GET['logout'])) {
        return;
    }

    $validLogoutToken = !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_GET['csrf'] ?? '');

    if ($validLogoutToken) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookieParams = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }
        session_destroy();
    }

    header('Location: ../auth.php');
    exit;
}

/** Runs a scalar query and returns the single value. */
function tdc_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Normalizes a Debit/Credit pair to the signed amount for an account's
 * normal balance side — Asset & Expense accounts read positive on the
 * debit side, Liability/Equity/Revenue on the credit side. Every report
 * on this page reads through this single function so the sign
 * convention can never drift between statements.
 */
function tdc_normal_side_amount(string $accountType, float $debitSum, float $creditSum): float
{
    return in_array($accountType, NORMAL_CREDIT_TYPES, true)
        ? $creditSum - $debitSum
        : $debitSum - $creditSum;
}

// =======================================================================
// SECTION 4 — Report data access (read-only; no persistence layer here)
// =======================================================================

/**
 * Income Statement (Profit & Loss) for the period [$from, $to]
 * (inclusive DATETIME bounds — callers pass the full-day range).
 *
 * @return array{revenue: array[], expense: array[], totalRevenue: float, totalExpense: float, netIncome: float}
 */
function tdc_income_statement(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare(
        "SELECT a.AccountID,
                (SELECT AccountName FROM Accounting WHERE AccountID = a.AccountID ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountName,
                a.AccountType, SUM(a.Debit) AS SumDebit, SUM(a.Credit) AS SumCredit
         FROM Accounting a
         WHERE a.AccountType IN ('Revenue', 'Expense') AND a.TransactionDate BETWEEN :from AND :to
         GROUP BY a.AccountID, a.AccountType
         ORDER BY AccountName ASC"
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $rows = $stmt->fetchAll();

    $revenue      = [];
    $expense      = [];
    $totalRevenue = 0.0;
    $totalExpense = 0.0;

    foreach ($rows as $r) {
        $amount = tdc_normal_side_amount($r['AccountType'], (float) $r['SumDebit'], (float) $r['SumCredit']);
        $line   = ['AccountID' => $r['AccountID'], 'AccountName' => $r['AccountName'], 'Amount' => $amount];

        if ($r['AccountType'] === 'Revenue') {
            $revenue[] = $line;
            $totalRevenue += $amount;
        } else {
            $expense[] = $line;
            $totalExpense += $amount;
        }
    }

    return [
        'revenue'      => $revenue,
        'expense'      => $expense,
        'totalRevenue' => $totalRevenue,
        'totalExpense' => $totalExpense,
        'netIncome'    => $totalRevenue - $totalExpense,
    ];
}

/**
 * Balance Sheet as of $asOf (a DATETIME upper bound — callers pass end
 * of the chosen day), cumulative since inception. See the file-level
 * doc comment for how "Retained Earnings (Current)" and the balance
 * check are derived.
 *
 * @return array{assets: array[], liabilities: array[], equity: array[],
 *               totalAssets: float, totalLiabilities: float,
 *               totalEquityPosted: float, retainedEarnings: float,
 *               totalEquity: float, balances: bool}
 */
function tdc_balance_sheet(PDO $pdo, string $asOf): array
{
    $stmt = $pdo->prepare(
        "SELECT a.AccountID,
                (SELECT AccountName FROM Accounting WHERE AccountID = a.AccountID ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountName,
                a.AccountType, SUM(a.Debit) AS SumDebit, SUM(a.Credit) AS SumCredit
         FROM Accounting a
         WHERE a.AccountType IN ('Asset', 'Liability', 'Equity') AND a.TransactionDate <= :asOf
         GROUP BY a.AccountID, a.AccountType
         ORDER BY AccountName ASC"
    );
    $stmt->execute(['asOf' => $asOf]);
    $rows = $stmt->fetchAll();

    $assets            = [];
    $liabilities       = [];
    $equity            = [];
    $totalAssets       = 0.0;
    $totalLiabilities  = 0.0;
    $totalEquityPosted = 0.0;

    foreach ($rows as $r) {
        $amount = tdc_normal_side_amount($r['AccountType'], (float) $r['SumDebit'], (float) $r['SumCredit']);
        $line   = ['AccountID' => $r['AccountID'], 'AccountName' => $r['AccountName'], 'Amount' => $amount];

        if ($r['AccountType'] === 'Asset') {
            $assets[] = $line;
            $totalAssets += $amount;
        } elseif ($r['AccountType'] === 'Liability') {
            $liabilities[] = $line;
            $totalLiabilities += $amount;
        } else {
            $equity[] = $line;
            $totalEquityPosted += $amount;
        }
    }

    $revenueToDate = (float) tdc_scalar(
        $pdo,
        "SELECT COALESCE(SUM(Credit) - SUM(Debit), 0) FROM Accounting WHERE AccountType = 'Revenue' AND TransactionDate <= :asOf",
        ['asOf' => $asOf]
    );
    $expenseToDate = (float) tdc_scalar(
        $pdo,
        "SELECT COALESCE(SUM(Debit) - SUM(Credit), 0) FROM Accounting WHERE AccountType = 'Expense' AND TransactionDate <= :asOf",
        ['asOf' => $asOf]
    );
    $retainedEarnings = $revenueToDate - $expenseToDate;
    $totalEquity      = $totalEquityPosted + $retainedEarnings;

    return [
        'assets'            => $assets,
        'liabilities'       => $liabilities,
        'equity'            => $equity,
        'totalAssets'       => $totalAssets,
        'totalLiabilities'  => $totalLiabilities,
        'totalEquityPosted' => $totalEquityPosted,
        'retainedEarnings'  => $retainedEarnings,
        'totalEquity'       => $totalEquity,
        'balances'          => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
    ];
}

// =======================================================================
// SECTION 5 — Logout (may exit)
// =======================================================================
tdc_handle_logout();

// =======================================================================
// SECTION 6 — Auth gate & role gate
// =======================================================================
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

if (!in_array($_SESSION['role'] ?? '', ALLOWED_REPORTS_ROLES, true)) {
    header('Location: home.php');
    exit;
}

require_once __DIR__ . '/../../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 7 — Request-scoped filter state & report computation
// =======================================================================
$section = $_GET['section'] ?? null;
if ($section !== null && !in_array($section, ALLOWED_SECTIONS, true)) {
    $section = null;
}

$today          = date('Y-m-d');
$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd   = date('Y-m-d', strtotime('last day of last month'));
$quarterMonth   = ((int) floor(((int) date('n') - 1) / 3) * 3) + 1;
$thisQuarterStart = date('Y') . '-' . str_pad((string) $quarterMonth, 2, '0', STR_PAD_LEFT) . '-01';
$thisYearStart  = date('Y-01-01');

/** Quick period presets shown as links above the Income Statement. */
$periodPresets = [
    'This Month'   => [$thisMonthStart, $today],
    'Last Month'   => [$lastMonthStart, $lastMonthEnd],
    'This Quarter' => [$thisQuarterStart, $today],
    'This Year'    => [$thisYearStart, $today],
];

// --- Income Statement filter state ---------------------------------------
$isFrom = trim((string) ($_GET['from'] ?? $thisMonthStart));
$isTo   = trim((string) ($_GET['to'] ?? $today));
if (!tdc_is_valid_date($isFrom)) {
    $isFrom = $thisMonthStart;
}
if (!tdc_is_valid_date($isTo)) {
    $isTo = $today;
}
if ($isFrom > $isTo) {
    [$isFrom, $isTo] = [$isTo, $isFrom];
}

// --- Balance Sheet filter state -------------------------------------------
$bsAsOf = trim((string) ($_GET['as_of'] ?? $today));
if (!tdc_is_valid_date($bsAsOf)) {
    $bsAsOf = $today;
}

$incomeStatement = null;
$balanceSheet    = null;

if ($section === 'income-statement') {
    $incomeStatement = tdc_income_statement($pdo, $isFrom . ' 00:00:00', $isTo . ' 23:59:59');
} elseif ($section === 'balance-sheet') {
    $balanceSheet = tdc_balance_sheet($pdo, $bsAsOf . ' 23:59:59');
}

// --- Hub teaser stats (only computed on the landing page) -----------------
$hubNetIncomeMonth = 0.0;
$hubBalanceSheetOk = true;

if ($section === null) {
    $hubIncome = tdc_income_statement($pdo, $thisMonthStart . ' 00:00:00', $today . ' 23:59:59');
    $hubNetIncomeMonth = $hubIncome['netIncome'];

    $hubBalance = tdc_balance_sheet($pdo, $today . ' 23:59:59');
    $hubBalanceSheetOk = $hubBalance['balances'];
}

// --- Letterhead, read from the clinic's existing single source of truth --
$letterhead     = $pdo->query('SELECT CompanyName, PhoneNumbers, CompanyAddress FROM PrescriptionsHeader LIMIT 1')->fetch();
$companyName    = $letterhead['CompanyName'] ?? 'Tarey Derma Clinic';
$companyAddress = $letterhead['CompanyAddress'] ?? '';
$companyPhone   = $letterhead['PhoneNumbers'] ?? '';

// =======================================================================
// SECTION 8 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'reports.php'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tarey Derma Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
<style>
    :root{
        --navy: #2E3192;
        --navy-30: rgba(46,49,146,0.3);
        --navy-10: rgba(46,49,146,0.08);
        --navy-55: rgba(46,49,146,0.55);
        --orange: #F15A24;
        --white: #ffffff;
        --border: 2px solid var(--navy);
        --on-navy-70: rgba(255,255,255,0.7);
    }
    *, *::before, *::after{ box-sizing:border-box; margin:0; padding:0; }
    body{ font-family:'Google Sans', sans-serif; background:var(--white); color:var(--navy); min-height:100vh; }
    .app-header{ position:relative; z-index:100; }
    .nav-item{ position:relative; flex-shrink:0; }
    .utility-bar{ display:flex; align-items:center; justify-content:space-between; background:var(--navy); padding:8px 24px; }
    .brand-chip{ background:var(--white); display:flex; align-items:center; padding:5px 14px; flex-shrink:0; }
    .brand-chip img{ height:30px; width:auto; object-fit:contain; display:block; }
    .utility-right{ display:flex; align-items:center; gap:2px; }
    .icon-btn{ appearance:none; background:none; border:2px solid transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; width:38px; height:38px; position:relative; color:var(--on-navy-70); transition:color 0.12s; }
    .icon-btn:hover{ color:var(--white); }
    .icon-btn svg{ width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .icon-btn .badge{ position:absolute; top:6px; right:7px; width:7px; height:7px; background:var(--orange); border:2px solid var(--navy); }
    .nav-item.open > .icon-btn{ color:var(--orange); }
    .profile-static{ display:flex; align-items:center; gap:9px; padding:6px 8px; font-family:'Google Sans', sans-serif; color:var(--white); }
    .avatar{ width:30px; height:30px; flex-shrink:0; background:var(--white); color:var(--navy); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; letter-spacing:0.02em; }
    .profile-name{ font-size:13.5px; font-weight:600; }
    .menu-bar{ background:var(--white); padding:0 24px; display:flex; justify-content:safe center; overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; scrollbar-color:var(--navy-30) transparent; }
    .menu-bar::-webkit-scrollbar{ height:4px; }
    .menu-bar::-webkit-scrollbar-track{ background:transparent; }
    .menu-bar::-webkit-scrollbar-thumb{ background:var(--navy-30); border-radius:2px; }
    .menu-bar::-webkit-scrollbar-thumb:hover{ background:var(--navy-55); }
    .nav-items{ list-style:none; display:flex; align-items:center; gap:4px; flex-wrap:nowrap; flex-shrink:0; }
    .nav-link{ appearance:none; background:none; border:none; cursor:pointer; display:flex; align-items:center; gap:7px; font-family:'Google Sans', sans-serif; font-size:13.5px; font-weight:600; letter-spacing:0.01em; color:var(--navy); text-decoration:none; padding:12px; white-space:nowrap; flex-shrink:0; transition:color 0.12s; }
    .nav-link svg{ width:16px; height:16px; fill:var(--navy-55); flex-shrink:0; transition:fill 0.12s; }
    .nav-link:hover{ color:var(--orange); }
    .nav-link:hover svg{ fill:var(--orange); }
    .nav-item.active > .nav-link{ color:var(--navy); box-shadow:inset 0 -2px 0 var(--orange); }
    .nav-item.active > .nav-link svg{ fill:var(--navy); }
    .dropdown-menu{ position:absolute; top:calc(100% + 6px); left:0; min-width:220px; background:var(--white); border:var(--border); display:none; flex-direction:column; padding:6px 0; }
    .nav-item.open > .dropdown-menu{ display:flex; }
    .dropdown-menu a{ display:block; text-decoration:none; color:var(--navy); font-size:13.5px; font-weight:500; padding:9px 16px; transition:background 0.12s, color 0.12s; }
    .dropdown-menu a:hover{ background:var(--navy-10); color:var(--orange); }
    .notif-menu{ right:0; left:auto; min-width:260px; }
    .notif-menu .notif-title{ padding:10px 16px 8px; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:var(--navy-55); }
    .notif-empty{ padding:20px 16px 22px; font-size:13px; color:var(--navy-55); text-align:center; }
    .page-body{ padding:40px 32px; }
    .welcome-eyebrow{ font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--navy-55); margin-bottom:8px; }
    .welcome-title{ font-size:26px; font-weight:700; color:var(--navy); }
    .welcome-sub{ font-size:14px; color:var(--navy-55); margin-top:6px; margin-bottom:28px; }

    .btn{ padding:11px 22px; font-size:14px; font-weight:600; border:2px solid var(--navy); cursor:pointer; letter-spacing:0.02em; transition:background 0.12s, color 0.12s, border-color 0.12s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary{ background:var(--navy); color:var(--white); }
    .btn-primary:hover{ background:var(--orange); border-color:var(--orange); }
    .btn-secondary{ background:var(--white); color:var(--navy); }
    .btn-secondary:hover{ color:var(--orange); border-color:var(--orange); }

    .setup-grid{ display:grid; grid-template-columns:repeat(2, minmax(240px,1fr)); gap:20px; max-width:640px; }
    .setup-card{ display:flex; align-items:flex-start; gap:14px; padding:20px; border:2px solid var(--navy); text-decoration:none; color:var(--navy); transition:background 0.12s, border-color 0.12s; }
    .setup-card:hover{ background:var(--navy-10); border-color:var(--orange); }
    .setup-card .setup-icon{ width:32px; height:32px; flex-shrink:0; color:var(--navy-55); }
    .setup-card .setup-icon svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; }
    .setup-card-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-bottom:4px; }
    .setup-card-desc{ font-size:12.5px; color:var(--navy-55); }

    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:720px; margin-bottom:20px; flex-wrap:wrap; }
    .filter-box{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .filter-box input{ padding:9px 11px; border:2px solid rgba(46,49,146,0.3); font-size:13px; font-family:'Google Sans',sans-serif; color:var(--navy); }
    .filter-box input:focus{ outline:none; border-color:var(--orange); }
    .filter-box label{ font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:var(--navy-55); }
    .preset-links{ display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; max-width:720px; }
    .preset-link{ font-size:12px; font-weight:600; color:var(--navy); text-decoration:none; padding:6px 12px; border:1.5px solid var(--navy-30); }
    .preset-link:hover, .preset-link.active{ border-color:var(--orange); color:var(--orange); }

    /* --- Financial statement layout --------------------------------- */
    .statement-wrap{ max-width:720px; border:2px solid var(--navy); background:var(--white); }
    .statement-header{ padding:22px 28px 16px; border-bottom:2px solid var(--navy); text-align:center; }
    .statement-header .company-name{ font-size:17px; font-weight:700; color:var(--navy); }
    .statement-header .company-meta{ font-size:12px; color:var(--navy-55); margin-top:2px; }
    .statement-header .statement-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-top:12px; letter-spacing:.02em; }
    .statement-header .statement-period{ font-size:12.5px; color:var(--navy-55); margin-top:2px; }
    .statement-body{ padding:24px 28px; }
    .statement-section-title{ font-size:11.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--navy); margin:20px 0 8px; padding-bottom:6px; border-bottom:1.5px solid var(--navy-30); }
    .statement-section-title:first-child{ margin-top:0; }
    .statement-row{ display:flex; justify-content:space-between; gap:12px; padding:5px 0; font-size:13.5px; }
    .statement-row.indent{ padding-left:14px; }
    .statement-row .amount{ font-variant-numeric:tabular-nums; white-space:nowrap; }
    .statement-subtotal{ display:flex; justify-content:space-between; gap:12px; padding:8px 0; margin-top:2px; border-top:1.5px solid var(--navy-30); font-weight:700; font-size:13.5px; }
    .statement-grandtotal{ display:flex; justify-content:space-between; gap:12px; padding:12px 0; margin-top:14px; border-top:3px double var(--navy); border-bottom:3px double var(--navy); font-weight:700; font-size:15.5px; }
    .statement-empty{ text-align:center; color:var(--navy-55); font-size:13px; padding:16px 0; }
    .statement-footnote{ font-size:11.5px; color:var(--navy-55); margin-top:6px; font-style:italic; }
    .balance-check{ margin-top:18px; padding:12px 16px; border:2px solid; font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px; }
    .balance-check.ok{ border-color:#1b7a3d; color:#1b7a3d; }
    .balance-check.warn{ border-color:#c0392b; color:#c0392b; }
    .balance-check svg{ width:16px; height:16px; flex-shrink:0; }
    .statement-actions{ display:flex; gap:10px; max-width:720px; margin-top:18px; }

    .logout-fab{ position:fixed; bottom:20px; right:20px; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--navy); border:2px solid var(--white); cursor:pointer; text-decoration:none; z-index:9999; box-shadow:0 2px 6px rgba(46,49,146,0.35); transition:transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease; }
    .logout-fab svg{ width:18px; height:18px; stroke:var(--white); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.15s ease; }
    .logout-fab:hover{ background:var(--orange); transform:scale(1.08); box-shadow:0 4px 10px rgba(241,90,36,0.4); }
    .logout-fab:active{ transform:scale(0.96); }
    .logout-fab:focus-visible{ outline:2px solid var(--orange); outline-offset:3px; }
    .logout-fab::after{ content:'Log Out'; position:absolute; bottom:calc(100% + 8px); right:0; background:var(--navy); color:var(--white); font-family:'Google Sans', sans-serif; font-size:12px; font-weight:600; padding:6px 10px; white-space:nowrap; opacity:0; visibility:hidden; transform:translateY(4px); transition:opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease; pointer-events:none; }
    .logout-fab:hover::after, .logout-fab:focus-visible::after{ opacity:1; visibility:visible; transform:translateY(0); }

    @media print{
        .app-header, .logout-fab, .back-link, .section-toolbar, .preset-links, .statement-actions{ display:none !important; }
        .page-body{ padding:0; }
        .statement-wrap{ max-width:100%; border:none; }
    }
</style>
</head>
<body>

<header class="app-header" id="topnav">
    <div class="utility-bar">
        <div class="brand-chip">
            <img src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic Logo">
        </div>
        <div class="utility-right">
            <div class="nav-item" data-menu="notifications">
                <button type="button" class="icon-btn" aria-label="Notifications">
                    <svg viewBox="0 0 24 24"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z"/><path d="M9.5 21a2.5 2.5 0 005 0"/></svg>
                    <span class="badge"></span>
                </button>
                <div class="dropdown-menu notif-menu">
                    <div class="notif-title">Notifications</div>
                    <div class="notif-empty">You're all caught up.</div>
                </div>
            </div>
            <div class="profile-static">
                <div class="avatar"><?= tdc_e($avatarLetters) ?></div>
                <span class="profile-name"><?= tdc_e($displayName) ?></span>
            </div>
        </div>
    </div>

    <nav class="menu-bar">
        <ul class="nav-items">
            <?php foreach (NAV_ITEMS as $item): ?>
                <li class="nav-item<?= $item['href'] === $currentPage ? ' active' : '' ?>">
                    <a href="<?= tdc_e($item['href']) ?>" class="nav-link">
                        <svg viewBox="0 0 20 20"><?= $item['icon'] ?></svg>
                        <span><?= tdc_e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <a href="?logout=1&csrf=<?= urlencode($csrfToken) ?>" class="logout-fab" aria-label="Log Out">
        <svg viewBox="0 0 20 20"><path d="M8 3H5a2 2 0 00-2 2v10a2 2 0 002 2h3"/><path d="M13 6l4 4-4 4"/><path d="M7 10h10"/></svg>
    </a>
</header>

<main class="page-body">

<?php if ($section === null): ?>

    <div class="welcome-eyebrow">Reports</div>
    <div class="welcome-title">Financial Reports</div>
    <div class="welcome-sub">Income Statement and Balance Sheet, computed live from the general ledger.</div>

    <div class="setup-grid">
        <a href="reports.php?section=income-statement" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M20 20V13"/></svg></div>
            <div>
                <div class="setup-card-title">Income Statement</div>
                <div class="setup-card-desc">Net income this month: <?= number_format($hubNetIncomeMonth, 2) ?></div>
            </div>
        </a>
        <a href="reports.php?section=balance-sheet" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M4 4h16v4H4zM4 10h7v10H4zM13 10h7v10h-7z"/></svg></div>
            <div>
                <div class="setup-card-title">Balance Sheet</div>
                <div class="setup-card-desc">As of today — <?= $hubBalanceSheetOk ? 'Balanced ✓' : 'Out of balance ⚠' ?></div>
            </div>
        </a>
    </div>

<?php else: ?>

    <a href="reports.php" class="back-link no-print">&larr; Back to Reports</a>

    <?php // ============================================================
          // INCOME STATEMENT
          // ============================================================ ?>
    <?php if ($section === 'income-statement'): ?>

        <div class="welcome-title">Income Statement</div>
        <div class="welcome-sub">Revenue and expenses for the selected period.</div>

        <div class="preset-links no-print">
            <?php foreach ($periodPresets as $label => [$pFrom, $pTo]): ?>
                <a href="reports.php?section=income-statement&from=<?= urlencode($pFrom) ?>&to=<?= urlencode($pTo) ?>"
                   class="preset-link<?= ($isFrom === $pFrom && $isTo === $pTo) ? ' active' : '' ?>"><?= tdc_e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="section-toolbar no-print">
            <form method="GET" action="reports.php" class="filter-box">
                <input type="hidden" name="section" value="income-statement">
                <label for="rf_From">From</label>
                <input type="date" id="rf_From" name="from" value="<?= tdc_e($isFrom) ?>">
                <label for="rf_To">To</label>
                <input type="date" id="rf_To" name="to" value="<?= tdc_e($isTo) ?>">
                <button type="submit" class="btn btn-secondary">Apply</button>
            </form>
        </div>

        <div class="statement-wrap">
            <div class="statement-header">
                <div class="company-name"><?= tdc_e($companyName) ?></div>
                <?php if ($companyAddress !== '' || $companyPhone !== ''): ?>
                <div class="company-meta"><?= tdc_e(trim($companyAddress . ($companyAddress !== '' && $companyPhone !== '' ? ' · ' : '') . $companyPhone)) ?></div>
                <?php endif; ?>
                <div class="statement-title">Income Statement</div>
                <div class="statement-period">For the period <?= tdc_e(date('M j, Y', strtotime($isFrom))) ?> &ndash; <?= tdc_e(date('M j, Y', strtotime($isTo))) ?></div>
            </div>
            <div class="statement-body">

                <div class="statement-section-title">Revenue</div>
                <?php if (empty($incomeStatement['revenue'])): ?>
                <div class="statement-empty">No revenue posted in this period.</div>
                <?php else: foreach ($incomeStatement['revenue'] as $line): ?>
                <div class="statement-row indent">
                    <span><?= tdc_e($line['AccountName']) ?></span>
                    <span class="amount"><?= number_format($line['Amount'], 2) ?></span>
                </div>
                <?php endforeach; endif; ?>
                <div class="statement-subtotal">
                    <span>Total Revenue</span>
                    <span class="amount"><?= number_format($incomeStatement['totalRevenue'], 2) ?></span>
                </div>

                <div class="statement-section-title">Expenses</div>
                <?php if (empty($incomeStatement['expense'])): ?>
                <div class="statement-empty">No expenses posted in this period.</div>
                <?php else: foreach ($incomeStatement['expense'] as $line): ?>
                <div class="statement-row indent">
                    <span><?= tdc_e($line['AccountName']) ?></span>
                    <span class="amount"><?= number_format($line['Amount'], 2) ?></span>
                </div>
                <?php endforeach; endif; ?>
                <div class="statement-subtotal">
                    <span>Total Expenses</span>
                    <span class="amount"><?= number_format($incomeStatement['totalExpense'], 2) ?></span>
                </div>

                <div class="statement-grandtotal">
                    <span>Net Income</span>
                    <span class="amount"><?= number_format($incomeStatement['netIncome'], 2) ?></span>
                </div>
            </div>
        </div>

        <div class="statement-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        </div>

    <?php // ============================================================
          // BALANCE SHEET
          // ============================================================ ?>
    <?php elseif ($section === 'balance-sheet'): ?>

        <div class="welcome-title">Balance Sheet</div>
        <div class="welcome-sub">Assets, liabilities, and equity as of a chosen date.</div>

        <div class="section-toolbar no-print">
            <form method="GET" action="reports.php" class="filter-box">
                <input type="hidden" name="section" value="balance-sheet">
                <label for="rf_AsOf">As of</label>
                <input type="date" id="rf_AsOf" name="as_of" value="<?= tdc_e($bsAsOf) ?>">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <a href="reports.php?section=balance-sheet" class="preset-link">Today</a>
            </form>
        </div>

        <div class="statement-wrap">
            <div class="statement-header">
                <div class="company-name"><?= tdc_e($companyName) ?></div>
                <?php if ($companyAddress !== '' || $companyPhone !== ''): ?>
                <div class="company-meta"><?= tdc_e(trim($companyAddress . ($companyAddress !== '' && $companyPhone !== '' ? ' · ' : '') . $companyPhone)) ?></div>
                <?php endif; ?>
                <div class="statement-title">Balance Sheet</div>
                <div class="statement-period">As of <?= tdc_e(date('M j, Y', strtotime($bsAsOf))) ?></div>
            </div>
            <div class="statement-body">

                <div class="statement-section-title">Assets</div>
                <?php if (empty($balanceSheet['assets'])): ?>
                <div class="statement-empty">No asset activity on record.</div>
                <?php else: foreach ($balanceSheet['assets'] as $line): ?>
                <div class="statement-row indent">
                    <span><?= tdc_e($line['AccountName']) ?></span>
                    <span class="amount"><?= number_format($line['Amount'], 2) ?></span>
                </div>
                <?php endforeach; endif; ?>
                <div class="statement-subtotal">
                    <span>Total Assets</span>
                    <span class="amount"><?= number_format($balanceSheet['totalAssets'], 2) ?></span>
                </div>

                <div class="statement-section-title">Liabilities</div>
                <?php if (empty($balanceSheet['liabilities'])): ?>
                <div class="statement-empty">No liability activity on record.</div>
                <?php else: foreach ($balanceSheet['liabilities'] as $line): ?>
                <div class="statement-row indent">
                    <span><?= tdc_e($line['AccountName']) ?></span>
                    <span class="amount"><?= number_format($line['Amount'], 2) ?></span>
                </div>
                <?php endforeach; endif; ?>
                <div class="statement-subtotal">
                    <span>Total Liabilities</span>
                    <span class="amount"><?= number_format($balanceSheet['totalLiabilities'], 2) ?></span>
                </div>

                <div class="statement-section-title">Equity</div>
                <?php if (empty($balanceSheet['equity'])): ?>
                <div class="statement-empty">No equity activity on record.</div>
                <?php else: foreach ($balanceSheet['equity'] as $line): ?>
                <div class="statement-row indent">
                    <span><?= tdc_e($line['AccountName']) ?></span>
                    <span class="amount"><?= number_format($line['Amount'], 2) ?></span>
                </div>
                <?php endforeach; endif; ?>
                <div class="statement-row indent">
                    <span>Retained Earnings (Current)</span>
                    <span class="amount"><?= number_format($balanceSheet['retainedEarnings'], 2) ?></span>
                </div>
                <div class="statement-footnote">Retained Earnings is the running total of Revenue less Expenses to date that hasn't been posted to an Equity account.</div>
                <div class="statement-subtotal">
                    <span>Total Equity</span>
                    <span class="amount"><?= number_format($balanceSheet['totalEquity'], 2) ?></span>
                </div>

                <div class="statement-grandtotal">
                    <span>Total Liabilities &amp; Equity</span>
                    <span class="amount"><?= number_format($balanceSheet['totalLiabilities'] + $balanceSheet['totalEquity'], 2) ?></span>
                </div>
            </div>
        </div>

        <?php if ($balanceSheet['balances']): ?>
        <div class="balance-check ok">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.03-9.78a.75.75 0 00-1.06-1.06L8.75 10.44l-1.72-1.72a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.75-4.75z" clip-rule="evenodd"/></svg>
            Balanced — Total Assets equal Total Liabilities plus Equity.
        </div>
        <?php else: ?>
        <div class="balance-check warn">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/></svg>
            Out of balance — check the ledger for entries posted outside the normal journal flow.
        </div>
        <?php endif; ?>

        <div class="statement-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
        </div>

    <?php endif; ?>

<?php endif; ?>

</main>

<script>
(function(){
    const nav = document.getElementById('topnav');
    const items = Array.from(nav.querySelectorAll('.nav-item[data-menu]'));
    function closeAll(except){ items.forEach(function(item){ if(item !== except){ item.classList.remove('open'); } }); }
    items.forEach(function(item){
        const trigger = item.querySelector('.icon-btn');
        if(!trigger) return;
        trigger.addEventListener('click', function(e){
            e.stopPropagation();
            const isOpen = item.classList.contains('open');
            closeAll(item);
            item.classList.toggle('open', !isOpen);
        });
    });
    document.addEventListener('click', function(){ closeAll(null); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ closeAll(null); } });
})();
</script>

</body>
</html>