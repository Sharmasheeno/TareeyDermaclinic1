<?php
/**
 * auth/pages/accounting.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Accounting (General Ledger & Chart of Accounts)
 * ---------------------------------------------------------------------
 * Two related views over one ledger table, matching the "accounting.php
 * = Ledger, Chart of Accounts" grouping used across the app's navigation:
 *
 *   1. General Ledger  -> Accounting table (multi-line, one balanced
 *      journal entry per posting, grouped like Prescriptions /
 *      PharmacySales / Purchases: "JE000001-01", "-02", ... — one row
 *      per debit/credit line. All lines in a batch share TransactionDate,
 *      BookType, ReferenceID and Description).
 *   2. Chart of Accounts -> derived, not a separate table. There is no
 *      Accounts catalog in the schema, so an account "exists" the moment
 *      it has at least one ledger line; the list is a GROUP BY AccountID
 *      aggregate of the ledger (the same "no catalog table" tradeoff
 *      Purchases.SupplierID already accepts in pharmacy.php).
 *
 * Design decisions:
 *   - Every posting is double-entry: a batch must contain at least one
 *     debit line and one credit line, and total debits must equal total
 *     credits to the cent. Enforced server-side regardless of client JS.
 *   - AccountID is resolved exactly like Purchases resolves SupplierID:
 *     match AccountName case-insensitively against prior ledger rows;
 *     otherwise issue the next "ACC000001"-style id using the account
 *     type supplied on this posting. An existing account's stored type
 *     always wins over whatever the form submits, so one account can
 *     never end up split across two types.
 *   - Ledger entries are append-only; a financial audit trail is never
 *     hard-deleted. "Void" posts an automatic reversing entry (debits
 *     and credits swapped) instead of deleting the original, and is
 *     refused if the batch is itself a reversal or has already been
 *     reversed. The reversal link travels in ReferenceID — there is no
 *     spare column for it, so this reuses the existing free-form linking
 *     field rather than requiring a schema change.
 *   - Renaming an account (fixing a typo in AccountName) is allowed and
 *     updates every existing row for that AccountID. It never touches
 *     Debit/Credit/Balance, so it cannot alter reported financial history.
 *   - Every row carries a running Balance = Debit - Credit for its
 *     account. Display normalizes sign by normal-balance convention:
 *     Asset and Expense accounts show the raw balance; Liability, Equity
 *     and Revenue accounts show the negated balance (their normal side
 *     is credit).
 *
 * Security controls (same posture as home.php / pharmacy.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: only 'superuser' may open this page at all — the role
 *     enum has no dedicated accounting role, and ledger data is the most
 *     sensitive financial data in the app
 *   - CSRF-token-checked POST handlers, rotated on every submit
 *   - Prepared statements only — no string-built SQL from user input
 *   - Post/Redirect/Get on every successful write
 *   - All session/user-derived output escaped before hitting HTML
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
require_once __DIR__ . '/../includes/access.php';
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_SECTIONS         = ['ledger', 'accounts'];
const ALLOWED_ACCOUNTING_ROLES = ['superuser'];

const ACCOUNT_TYPE_OPTIONS = [
    'Asset'     => 'Asset',
    'Liability' => 'Liability',
    'Equity'    => 'Equity',
    'Revenue'   => 'Revenue',
    'Expense'   => 'Expense',
];

const BOOK_TYPE_OPTIONS = [
    'General Journal'      => 'General Journal',
    'Cash Book'            => 'Cash Book',
    'Accounts Receivable'  => 'Accounts Receivable',
    'Accounts Payable'     => 'Accounts Payable',
];

/** Account types whose normal balance sits on the credit side. */
const NORMAL_CREDIT_TYPES = ['Liability', 'Equity', 'Revenue'];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / doctors.php / patients.php / pharmacy.php /
 * settings.php.
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

/** Redirects (Post/Redirect/Get) back to a section with a one-shot flash flag. */
function tdc_redirect(string $section, string $flag): void
{
    header('Location: accounting.php?section=' . urlencode($section) . '&' . $flag . '=1');
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
 * Generates the next zero-padded reference for a VARCHAR primary key,
 * e.g. tdc_next_ref($pdo, 'Accounting', 'AccountID', 'ACC') -> "ACC000007".
 * Repeats of the same id across many ledger lines are expected and are
 * harmless here — we only ever need the current maximum.
 * Table/column are always hardcoded call-site literals — never user input.
 */
function tdc_next_ref(PDO $pdo, string $table, string $column, string $prefix, int $pad = 6): string
{
    $sql  = "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY LENGTH({$column}) DESC, {$column} DESC LIMIT 1";
    $last = tdc_scalar($pdo, $sql, ['pattern' => $prefix . '%']);

    $next = 1;
    if ($last !== false && $last !== null) {
        $next = ((int) substr((string) $last, strlen($prefix))) + 1;
    }

    return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

/**
 * Generates the next journal-entry batch reference, e.g. "JE000042".
 * Individual lines are stored as "{base}-01", "-02", etc. — the same
 * grouping-by-ID-prefix convention Prescriptions / PharmacySales /
 * Purchases already use elsewhere in this app.
 */
function tdc_next_bill_base(PDO $pdo, string $table, string $column, string $prefix): string
{
    $last = tdc_scalar(
        $pdo,
        "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY LENGTH({$column}) DESC, {$column} DESC LIMIT 1",
        ['pattern' => $prefix . '%']
    );

    $next = 1;
    if ($last !== false && $last !== null) {
        $base = strtok((string) $last, '-');
        $next = ((int) substr($base, strlen($prefix))) + 1;
    }

    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/**
 * Resolves an AccountID for a given account name. There is no Accounts
 * catalog table in this schema, so an existing account is reused by
 * matching AccountName (case-insensitive) against prior ledger rows;
 * otherwise a new sequential id is issued. When the account already
 * exists, its stored AccountType always wins over whatever the form
 * submitted, so one account name can never end up split across two
 * types.
 *
 * @return array{id:string, name:string, type:string, isNew:bool}
 */
function tdc_find_or_create_account(PDO $pdo, string $accountName, string $submittedType): array
{
    $stmt = $pdo->prepare(
        'SELECT AccountID, AccountName, AccountType FROM Accounting
         WHERE LOWER(AccountName) = LOWER(:name) ORDER BY TransactionDate DESC LIMIT 1'
    );
    $stmt->execute(['name' => $accountName]);
    $existing = $stmt->fetch();

    if ($existing !== false) {
        return [
            'id'    => $existing['AccountID'],
            'name'  => $existing['AccountName'],
            'type'  => $existing['AccountType'],
            'isNew' => false,
        ];
    }

    return [
        'id'    => tdc_next_ref($pdo, 'Accounting', 'AccountID', 'ACC'),
        'name'  => $accountName,
        'type'  => $submittedType,
        'isNew' => true,
    ];
}

/** Current running balance (Debit - Credit) for an account, before any pending posting. */
function tdc_account_raw_balance(PDO $pdo, string $accountId): float
{
    return (float) tdc_scalar(
        $pdo,
        'SELECT COALESCE(SUM(Debit) - SUM(Credit), 0) FROM Accounting WHERE AccountID = :id',
        ['id' => $accountId]
    );
}

/** Normalizes a raw (Debit - Credit) balance to the account's normal-balance side for display. */
function tdc_normalized_balance(string $accountType, float $rawBalance): float
{
    return in_array($accountType, NORMAL_CREDIT_TYPES, true) ? -$rawBalance : $rawBalance;
}

// =======================================================================
// SECTION 4 — Validation functions
// =======================================================================

/**
 * @param array{TransactionDate:string,BookType:string,ReferenceID:string,Description:string,
 *              AccountName:array,AccountType:array,Debit:array,Credit:array} $input
 * @return array{errors:string[], lines:array[], totalDebit:float, totalCredit:float}
 */
function tdc_validate_journal_form(array $input): array
{
    $errors = [];

    if ($input['TransactionDate'] !== '' && !tdc_is_valid_date($input['TransactionDate'])) {
        $errors[] = 'Transaction date is not a valid date.';
    }
    if (!array_key_exists($input['BookType'], BOOK_TYPE_OPTIONS)) {
        $errors[] = 'Please select a valid book.';
    }
    if (trim($input['Description']) === '') {
        $errors[] = 'Description is required.';
    } elseif (mb_strlen($input['Description']) > 2000) {
        $errors[] = 'Description is too long (max 2000 characters).';
    }
    if (mb_strlen($input['ReferenceID']) > 50) {
        $errors[] = 'Reference is too long (max 50 characters).';
    }

    $lines       = [];
    $totalDebit  = 0.0;
    $totalCredit = 0.0;
    $hasDebitLine  = false;
    $hasCreditLine = false;

    foreach ($input['AccountName'] as $i => $name) {
        $name = trim((string) $name);
        $type = (string) ($input['AccountType'][$i] ?? '');
        $debitRaw  = trim((string) ($input['Debit'][$i] ?? ''));
        $creditRaw = trim((string) ($input['Credit'][$i] ?? ''));

        if ($name === '' && $debitRaw === '' && $creditRaw === '') {
            continue; // Blank spare row — silently skipped, same as the other forms in this app.
        }

        $lineNo = $i + 1;

        if ($name === '') {
            $errors[] = "Line {$lineNo}: an account is required.";
            continue;
        }
        if (mb_strlen($name) > 150) {
            $errors[] = "Line {$lineNo}: account name is too long (max 150 characters).";
            continue;
        }
        if (!array_key_exists($type, ACCOUNT_TYPE_OPTIONS)) {
            $errors[] = "Line {$lineNo}: please select a valid account type.";
            continue;
        }

        $debitValid  = $debitRaw === '' || (is_numeric($debitRaw) && (float) $debitRaw >= 0);
        $creditValid = $creditRaw === '' || (is_numeric($creditRaw) && (float) $creditRaw >= 0);
        if (!$debitValid || !$creditValid) {
            $errors[] = "Line {$lineNo}: amounts must be valid non-negative numbers.";
            continue;
        }

        $debit  = $debitRaw !== '' ? round((float) $debitRaw, 2) : 0.0;
        $credit = $creditRaw !== '' ? round((float) $creditRaw, 2) : 0.0;

        if (($debit > 0) === ($credit > 0)) {
            $errors[] = "Line {$lineNo}: enter an amount in either Debit or Credit, not both or neither.";
            continue;
        }

        if ($debit > 0) {
            $hasDebitLine = true;
        } else {
            $hasCreditLine = true;
        }

        $totalDebit  += $debit;
        $totalCredit += $credit;

        $lines[] = [
            'AccountName' => $name,
            'AccountType' => $type,
            'Debit'       => $debit,
            'Credit'      => $credit,
        ];
    }

    if (empty($lines)) {
        $errors[] = 'Add at least two lines: one debit and one credit.';
    } elseif (!$hasDebitLine || !$hasCreditLine) {
        $errors[] = 'A journal entry needs at least one debit line and one credit line.';
    } elseif (abs($totalDebit - $totalCredit) >= 0.01) {
        $errors[] = sprintf(
            'Total debits (%s) must equal total credits (%s).',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2)
        );
    }

    return ['errors' => $errors, 'lines' => $lines, 'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit];
}

/** @param array{NewName:string} $input */
function tdc_validate_rename_form(array $input): array
{
    $errors = [];
    if ($input['NewName'] === '' || mb_strlen($input['NewName']) > 150) {
        $errors[] = 'Account name is required (max 150 characters).';
    }
    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence functions
// =======================================================================

/**
 * @param array{TransactionDate:string,BookType:string,ReferenceID:string,Description:string} $header
 * @param array[] $lines validated lines from tdc_validate_journal_form()
 * @return string the generated batch reference, e.g. "JE000042"
 */
function tdc_save_journal_entry(PDO $pdo, array $header, array $lines): string
{
    $base = tdc_next_bill_base($pdo, 'Accounting', 'EntryID', 'JE');
    $date = $header['TransactionDate'] !== '' ? $header['TransactionDate'] : date('Y-m-d');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO Accounting (EntryID, AccountID, AccountName, AccountType, BookType,
                TransactionDate, ReferenceID, Description, Debit, Credit, Balance)
             VALUES (:EntryID, :AccountID, :AccountName, :AccountType, :BookType,
                :TransactionDate, :ReferenceID, :Description, :Debit, :Credit, :Balance)'
        );

        $runningBalances = []; // AccountID => running raw balance, so a line-batch touching
                                // the same account twice still adds up correctly in order.
        $lineNo = 0;
        foreach ($lines as $l) {
            $lineNo++;
            $account = tdc_find_or_create_account($pdo, $l['AccountName'], $l['AccountType']);

            if (!isset($runningBalances[$account['id']])) {
                $runningBalances[$account['id']] = tdc_account_raw_balance($pdo, $account['id']);
            }
            $runningBalances[$account['id']] += $l['Debit'] - $l['Credit'];

            $insert->execute([
                'EntryID'         => $base . '-' . str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT),
                'AccountID'       => $account['id'],
                'AccountName'     => $account['name'],
                'AccountType'     => $account['type'],
                'BookType'        => $header['BookType'],
                'TransactionDate' => $date . ' ' . date('H:i:s'),
                'ReferenceID'     => $header['ReferenceID'] !== '' ? $header['ReferenceID'] : null,
                'Description'     => $header['Description'],
                'Debit'           => $l['Debit'],
                'Credit'          => $l['Credit'],
                'Balance'         => round($runningBalances[$account['id']], 2),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $base;
}

/**
 * Voids a batch by posting an automatic reversing entry (debits and
 * credits swapped) — the original rows are never deleted, preserving
 * the audit trail. Refused if the batch is itself a reversal, or has
 * already been reversed. Both checks — and the link back to the
 * original — ride on the existing ReferenceID column: a reversal's
 * ReferenceID is set to the base it reverses.
 *
 * @return string[] error messages; empty on success
 */
function tdc_void_journal_entry(PDO $pdo, string $base): array
{
    $stmt = $pdo->prepare('SELECT * FROM Accounting WHERE EntryID LIKE :pattern ORDER BY EntryID ASC');
    $stmt->execute(['pattern' => $base . '-%']);
    $lines = $stmt->fetchAll();

    if (empty($lines)) {
        return ['Journal entry not found.'];
    }

    $existingBase = tdc_scalar(
        $pdo,
        "SELECT DISTINCT SUBSTRING_INDEX(EntryID, '-', 1) FROM Accounting WHERE SUBSTRING_INDEX(EntryID, '-', 1) = :ref LIMIT 1",
        ['ref' => (string) $lines[0]['ReferenceID']]
    );
    if ($existingBase !== false && $existingBase !== null) {
        return ['This entry is itself a reversal and cannot be reversed again.'];
    }

    $alreadyReversed = (int) tdc_scalar(
        $pdo,
        'SELECT COUNT(*) FROM Accounting WHERE ReferenceID = :base',
        ['base' => $base]
    );
    if ($alreadyReversed > 0) {
        return ['This entry has already been reversed.'];
    }

    $reversalBase = tdc_next_bill_base($pdo, 'Accounting', 'EntryID', 'JE');
    $description  = 'Reversal of ' . $base . ': ' . $lines[0]['Description'];
    $now          = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO Accounting (EntryID, AccountID, AccountName, AccountType, BookType,
                TransactionDate, ReferenceID, Description, Debit, Credit, Balance)
             VALUES (:EntryID, :AccountID, :AccountName, :AccountType, :BookType,
                :TransactionDate, :ReferenceID, :Description, :Debit, :Credit, :Balance)'
        );

        $runningBalances = [];
        $lineNo = 0;
        foreach ($lines as $l) {
            $lineNo++;
            $accountId = $l['AccountID'];
            if (!isset($runningBalances[$accountId])) {
                $runningBalances[$accountId] = tdc_account_raw_balance($pdo, $accountId);
            }
            // Swap debit/credit to reverse the original posting's effect.
            $newDebit  = (float) $l['Credit'];
            $newCredit = (float) $l['Debit'];
            $runningBalances[$accountId] += $newDebit - $newCredit;

            $insert->execute([
                'EntryID'         => $reversalBase . '-' . str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT),
                'AccountID'       => $accountId,
                'AccountName'     => $l['AccountName'],
                'AccountType'     => $l['AccountType'],
                'BookType'        => $l['BookType'],
                'TransactionDate' => $now,
                'ReferenceID'     => $base,
                'Description'     => $description,
                'Debit'           => $newDebit,
                'Credit'          => $newCredit,
                'Balance'         => round($runningBalances[$accountId], 2),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [];
}

/** @return string[] error messages; empty on success */
function tdc_rename_account(PDO $pdo, string $accountId, string $newName): array
{
    $exists = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Accounting WHERE AccountID = :id', ['id' => $accountId]);
    if ($exists === 0) {
        return ['Account not found.'];
    }

    $stmt = $pdo->prepare('UPDATE Accounting SET AccountName = :name WHERE AccountID = :id');
    $stmt->execute(['name' => $newName, 'id' => $accountId]);
    return [];
}

// =======================================================================
// SECTION 6 — Logout (may exit)
// =======================================================================
tdc_handle_logout();

// =======================================================================
// SECTION 7 — Auth gate & role gate
// =======================================================================
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

if (!in_array($_SESSION['role'] ?? '', ALLOWED_ACCOUNTING_ROLES, true)) {
    header('Location: home.php');
    exit;
}

require_once __DIR__ . '/../../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$section = $_GET['section'] ?? null;
if ($section !== null && !in_array($section, ALLOWED_SECTIONS, true)) {
    $section = null;
}

$errors = [];

$oldEntry = [
    'TransactionDate' => date('Y-m-d'), 'BookType' => 'General Journal', 'ReferenceID' => '', 'Description' => '',
    'AccountName' => [], 'AccountType' => [], 'Debit' => [], 'Credit' => [],
];

$entryShowForm = false;

// =======================================================================
// SECTION 9 — POST handler (dispatch by section)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($section, ALLOWED_SECTIONS, true)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        // --- General Ledger --------------------------------------------
        if ($section === 'ledger') {
            if ($formAction === 'void') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['EntryRef'] ?? ''));
                if ($base === '') {
                    $errors[] = 'Invalid journal entry selected.';
                } else {
                    try {
                        $voidErrors = tdc_void_journal_entry($pdo, $base);
                        if (empty($voidErrors)) {
                            tdc_redirect('ledger', 'voided');
                        }
                        $errors = $voidErrors;
                    } catch (Throwable $e) {
                        error_log('[ACCOUNTING][LEDGER] void failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while reversing the entry. Please try again.';
                    }
                }
            } else {
                $oldEntry['TransactionDate'] = trim((string) ($_POST['TransactionDate'] ?? ''));
                $oldEntry['BookType']        = (string) ($_POST['BookType'] ?? '');
                $oldEntry['ReferenceID']     = trim((string) ($_POST['ReferenceID'] ?? ''));
                $oldEntry['Description']     = trim((string) ($_POST['Description'] ?? ''));
                $oldEntry['AccountName']     = $_POST['AccountName'] ?? [];
                $oldEntry['AccountType']     = $_POST['AccountType'] ?? [];
                $oldEntry['Debit']           = $_POST['Debit'] ?? [];
                $oldEntry['Credit']          = $_POST['Credit'] ?? [];

                $validated     = tdc_validate_journal_form($oldEntry);
                $errors        = $validated['errors'];
                $entryShowForm = true;

                if (empty($errors)) {
                    try {
                        $base = tdc_save_journal_entry($pdo, $oldEntry, $validated['lines']);
                        header('Location: accounting.php?section=ledger&view=' . urlencode($base) . '&success=1');
                        exit;
                    } catch (Throwable $e) {
                        error_log('[ACCOUNTING][LEDGER] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while posting the entry. Please try again.';
                    }
                }
            }

        // --- Chart of Accounts -----------------------------------------
        } elseif ($section === 'accounts') {
            if ($formAction === 'rename') {
                $accountId = trim((string) ($_POST['AccountID'] ?? ''));
                $newName   = trim((string) ($_POST['NewName'] ?? ''));
                $renameErrors = tdc_validate_rename_form(['NewName' => $newName]);

                if ($accountId === '') {
                    $renameErrors[] = 'Invalid account selected.';
                }

                if (empty($renameErrors)) {
                    try {
                        $renameErrors = tdc_rename_account($pdo, $accountId, $newName);
                        if (empty($renameErrors)) {
                            tdc_redirect('accounts', 'renamed');
                        }
                    } catch (Throwable $e) {
                        error_log('[ACCOUNTING][ACCOUNTS] rename failed: ' . $e->getMessage());
                        $renameErrors[] = 'A system error occurred while renaming the account. Please try again.';
                    }
                }
                $errors = $renameErrors;
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above via header()).
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 10 — GET data loading for display
// =======================================================================

// --- 10A. Ledger ---------------------------------------------------------
$existingAccountNames = [];
$ledgerSearch = '';
$ledgerBook   = '';
$ledgerFrom   = '';
$ledgerTo     = '';
$journalEntries = [];
$viewEntryRef   = '';
$viewEntryLines = [];
$viewIsReversal = false;
$viewIsReversed = false;
$viewReversalOfDescription = '';

if ($section === 'ledger') {
    $existingAccountsStmt = $pdo->query(
        "SELECT AccountName, MAX(AccountType) AS AccountType, MAX(TransactionDate) AS LastUsed
         FROM Accounting GROUP BY AccountName ORDER BY AccountName ASC LIMIT 500"
    );
    $existingAccountNames = $existingAccountsStmt->fetchAll();

    $ledgerSearch = trim((string) ($_GET['q'] ?? ''));
    $ledgerBook   = (string) ($_GET['book'] ?? '');
    $ledgerFrom   = trim((string) ($_GET['from'] ?? ''));
    $ledgerTo     = trim((string) ($_GET['to'] ?? ''));
    if (!array_key_exists($ledgerBook, BOOK_TYPE_OPTIONS)) {
        $ledgerBook = '';
    }
    if ($ledgerFrom !== '' && !tdc_is_valid_date($ledgerFrom)) {
        $ledgerFrom = '';
    }
    if ($ledgerTo !== '' && !tdc_is_valid_date($ledgerTo)) {
        $ledgerTo = '';
    }

    $conditions = [];
    $params     = [];
    if ($ledgerSearch !== '') {
        $conditions[] = '(Description LIKE :q1 OR ReferenceID LIKE :q2 OR EntryID LIKE :q3)';
        $params['q1'] = $params['q2'] = $params['q3']  = '%' . $ledgerSearch . '%';
    }
    if ($ledgerBook !== '') {
        $conditions[]    = 'BookType = :book';
        $params['book']  = $ledgerBook;
    }
    if ($ledgerFrom !== '') {
        $conditions[]    = 'TransactionDate >= :from';
        $params['from']  = $ledgerFrom . ' 00:00:00';
    }
    if ($ledgerTo !== '') {
        $conditions[]  = 'TransactionDate <= :to';
        $params['to']  = $ledgerTo . ' 23:59:59';
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare(
        "SELECT SUBSTRING_INDEX(EntryID, '-', 1) AS EntryRef, MIN(Description) AS Description,
                MIN(BookType) AS BookType, MIN(TransactionDate) AS TransactionDate,
                MIN(ReferenceID) AS ReferenceID, SUM(Debit) AS TotalDebit, SUM(Credit) AS TotalCredit,
                COUNT(*) AS LineCount
         FROM Accounting {$where}
         GROUP BY EntryRef ORDER BY TransactionDate DESC LIMIT 200"
    );
    $stmt->execute($params);
    $journalEntries = $stmt->fetchAll();

    // Reversal-status flags computed in PHP (see tdc_void_journal_entry doc
    // comment for why this rides on ReferenceID rather than a schema change).
    $allBases = $pdo->query("SELECT DISTINCT SUBSTRING_INDEX(EntryID, '-', 1) FROM Accounting")->fetchAll(PDO::FETCH_COLUMN);
    $referencedBases = $pdo->query('SELECT DISTINCT ReferenceID FROM Accounting WHERE ReferenceID IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($journalEntries as &$je) {
        $je['IsReversal'] = $je['ReferenceID'] !== null && in_array($je['ReferenceID'], $allBases, true);
        $je['IsReversed'] = in_array($je['EntryRef'], $referencedBases, true);
    }
    unset($je);

    if (empty($errors)) {
        if (isset($_GET['view'])) {
            $viewEntryRef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
            $stmt = $pdo->prepare('SELECT * FROM Accounting WHERE EntryID LIKE :pattern ORDER BY EntryID ASC');
            $stmt->execute(['pattern' => $viewEntryRef . '-%']);
            $viewEntryLines = $stmt->fetchAll();
            if (empty($viewEntryLines)) {
                $viewEntryRef = ''; // Unknown / stale ref — fall back to the list.
            } else {
                $head = $viewEntryLines[0];
                $viewIsReversal = $head['ReferenceID'] !== null && in_array($head['ReferenceID'], $allBases, true);
                $viewIsReversed = in_array($viewEntryRef, $referencedBases, true);
                if ($viewIsReversal) {
                    $viewReversalOfDescription = (string) tdc_scalar(
                        $pdo,
                        'SELECT Description FROM Accounting WHERE EntryID = :id',
                        ['id' => $head['ReferenceID'] . '-01']
                    );
                }
            }
        } elseif (isset($_GET['new'])) {
            $entryShowForm = true;
        }
    }
}

// --- 10B. Chart of Accounts ----------------------------------------------
$accountSearch  = '';
$chartOfAccounts = [];
$viewAccountId    = '';
$viewAccountHead  = null;
$viewAccountLines = [];

if ($section === 'accounts') {
    $accountSearch = trim((string) ($_GET['q'] ?? ''));

    $conditions = [];
    $params     = [];
    if ($accountSearch !== '') {
        $conditions[] = '(AccountName LIKE :q1 OR AccountID LIKE :q2)';
        $params['q1'] = $params['q2']  = '%' . $accountSearch . '%';
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare(
        "SELECT a.AccountID,
                (SELECT AccountName FROM Accounting WHERE AccountID = a.AccountID ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountName,
                (SELECT AccountType FROM Accounting WHERE AccountID = a.AccountID ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountType,
                SUM(a.Debit) AS TotalDebit, SUM(a.Credit) AS TotalCredit,
                COUNT(*) AS EntryCount, MAX(a.TransactionDate) AS LastActivity
         FROM Accounting a
         {$where}
         GROUP BY a.AccountID ORDER BY AccountName ASC LIMIT 500"
    );
    $stmt->execute($params);
    $chartOfAccounts = $stmt->fetchAll();

    foreach ($chartOfAccounts as &$acct) {
        $raw = (float) $acct['TotalDebit'] - (float) $acct['TotalCredit'];
        $acct['NormalizedBalance'] = tdc_normalized_balance($acct['AccountType'], $raw);
    }
    unset($acct);

    if (empty($errors) && isset($_GET['view'])) {
        $viewAccountId = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
        $stmt = $pdo->prepare(
            "SELECT (SELECT AccountName FROM Accounting WHERE AccountID = :id1 ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountName,
                    (SELECT AccountType FROM Accounting WHERE AccountID = :id2 ORDER BY TransactionDate DESC, EntryID DESC LIMIT 1) AS AccountType,
                    SUM(Debit) AS TotalDebit, SUM(Credit) AS TotalCredit, COUNT(*) AS EntryCount
             FROM Accounting WHERE AccountID = :id3"
        );
        $stmt->execute(['id1' => $viewAccountId, 'id2' => $viewAccountId, 'id3' => $viewAccountId]);
        $viewAccountHead = $stmt->fetch() ?: null;

        if ($viewAccountHead === null || $viewAccountHead['EntryCount'] == 0) {
            $viewAccountId   = ''; // Unknown / stale id — fall back to the list.
            $viewAccountHead = null;
        } else {
            $raw = (float) $viewAccountHead['TotalDebit'] - (float) $viewAccountHead['TotalCredit'];
            $viewAccountHead['NormalizedBalance'] = tdc_normalized_balance($viewAccountHead['AccountType'], $raw);

            $stmt = $pdo->prepare(
                "SELECT SUBSTRING_INDEX(EntryID, '-', 1) AS EntryRef, EntryID, Description, TransactionDate, Debit, Credit, Balance
                 FROM Accounting WHERE AccountID = :id ORDER BY TransactionDate DESC, EntryID DESC LIMIT 300"
            );
            $stmt->execute(['id' => $viewAccountId]);
            $viewAccountLines = $stmt->fetchAll();
        }
    }
}

// --- 10C. Hub summary (only computed on the landing page) -----------------
$hubNetIncomeMonth = 0.0;
$hubCashBalance    = 0.0;
$hubReceivableDue  = 0.0;
$hubPayableDue     = 0.0;
$hubEntryCountMonth = 0;
$hubAccountCount    = 0;

if ($section === null) {
    $monthStart = date('Y-m-01 00:00:00');

    $revenueMonth = (float) tdc_scalar(
        $pdo,
        'SELECT COALESCE(SUM(Credit) - SUM(Debit), 0) FROM Accounting WHERE AccountType = :t AND TransactionDate >= :m',
        ['t' => 'Revenue', 'm' => $monthStart]
    );
    $expenseMonth = (float) tdc_scalar(
        $pdo,
        'SELECT COALESCE(SUM(Debit) - SUM(Credit), 0) FROM Accounting WHERE AccountType = :t AND TransactionDate >= :m',
        ['t' => 'Expense', 'm' => $monthStart]
    );
    $hubNetIncomeMonth = $revenueMonth - $expenseMonth;

    $hubCashBalance   = (float) tdc_scalar($pdo, "SELECT COALESCE(SUM(Debit) - SUM(Credit), 0) FROM Accounting WHERE BookType = 'Cash Book'");
    $hubReceivableDue = (float) tdc_scalar($pdo, "SELECT COALESCE(SUM(Debit) - SUM(Credit), 0) FROM Accounting WHERE BookType = 'Accounts Receivable'");
    $hubPayableDue    = (float) tdc_scalar($pdo, "SELECT COALESCE(SUM(Credit) - SUM(Debit), 0) FROM Accounting WHERE BookType = 'Accounts Payable'");

    $hubEntryCountMonth = (int) tdc_scalar(
        $pdo,
        "SELECT COUNT(DISTINCT SUBSTRING_INDEX(EntryID, '-', 1)) FROM Accounting WHERE TransactionDate >= :m",
        ['m' => $monthStart]
    );
    $hubAccountCount = (int) tdc_scalar($pdo, 'SELECT COUNT(DISTINCT AccountID) FROM Accounting');
}

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'accounting.php'));

$justSaved    = isset($_GET['success']);
$justVoided   = isset($_GET['voided']);
$justRenamed  = isset($_GET['renamed']);
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

    .error-msg{ display:flex; flex-direction:column; gap:4px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:14px 16px; margin-bottom:24px; max-width:1200px; }
    .error-msg .error-title{ display:flex; align-items:center; gap:8px; font-weight:700; }
    .error-msg svg{ width:16px; height:16px; flex-shrink:0; }
    .error-msg ul{ list-style:none; padding-left:24px; }
    .error-msg li::before{ content:"— "; }

    .form-group{ display:flex; flex-direction:column; }
    .form-group label{ font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy); margin-bottom:6px; }
    .form-group input, .form-group select, .form-group textarea{ width:100%; padding:11px 12px; border:2px solid rgba(46,49,146,0.3); font-size:14px; font-family:'Google Sans', sans-serif; color:var(--navy); background:var(--white); outline:none; transition:border-color 0.15s; }
    .form-group textarea{ resize:vertical; min-height:70px; }
    .form-group input::placeholder, .form-group textarea::placeholder{ color:rgba(46,49,146,0.45); }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus{ border-color:var(--orange); }
    .form-group select{ cursor:pointer; }
    .form-row{ display:flex; gap:16px; flex-wrap:wrap; }
    .form-row .form-group{ flex:1; min-width:180px; }
    .btn{ padding:11px 22px; font-size:14px; font-weight:600; border:2px solid var(--navy); cursor:pointer; letter-spacing:0.02em; transition:background 0.12s, color 0.12s, border-color 0.12s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary{ background:var(--navy); color:var(--white); }
    .btn-primary:hover{ background:var(--orange); border-color:var(--orange); }
    .btn-primary:disabled{ opacity:0.5; cursor:not-allowed; }
    .btn-primary:disabled:hover{ background:var(--navy); border-color:var(--navy); }
    .btn-secondary{ background:var(--white); color:var(--navy); }
    .btn-secondary:hover{ color:var(--orange); border-color:var(--orange); }

    .setup-grid{ display:grid; grid-template-columns:repeat(2, minmax(220px,1fr)); gap:20px; max-width:640px; margin-bottom:32px; }
    .setup-card{ display:flex; align-items:flex-start; gap:14px; padding:20px; border:2px solid var(--navy); text-decoration:none; color:var(--navy); transition:background 0.12s, border-color 0.12s; }
    .setup-card:hover{ background:var(--navy-10); border-color:var(--orange); }
    .setup-card .setup-icon{ width:32px; height:32px; flex-shrink:0; color:var(--navy-55); }
    .setup-card .setup-icon svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; }
    .setup-card-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-bottom:4px; }
    .setup-card-desc{ font-size:12.5px; color:var(--navy-55); }

    .kpi-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; max-width:1200px; margin-bottom:32px; }
    .kpi-card{ padding:18px 20px; border:2px solid var(--navy); }
    .kpi-label{ font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy-55); margin-bottom:8px; }
    .kpi-value{ font-size:22px; font-weight:700; color:var(--navy); }
    .kpi-value.negative{ color:#c0392b; }

    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1200px; margin-bottom:16px; flex-wrap:wrap; }
    .filter-box{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .filter-box input, .filter-box select{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); background:var(--white); }
    .filter-box input{ min-width:200px; }
    .filter-box input:focus, .filter-box select:focus{ outline:none; border-color:var(--orange); }

    .data-table-wrap{ max-width:1200px; border:2px solid var(--navy); overflow-x:auto; }
    .data-table{ width:100%; border-collapse:collapse; }
    .data-table th, .data-table td{ padding:12px 14px; font-size:13px; text-align:left; border-bottom:1px solid var(--navy-30); white-space:nowrap; }
    .data-table th{ background:var(--navy-10); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.05em; color:var(--navy); }
    .data-table tbody tr:last-child td{ border-bottom:none; }
    .data-table tbody tr:hover{ background:var(--navy-10); }
    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    .status-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); white-space:nowrap; }
    .status-badge.warn{ border-color:var(--orange); color:var(--orange); }
    .status-badge.danger{ border-color:#c0392b; color:#c0392b; }
    .status-badge.muted{ border-color:var(--navy-30); color:var(--navy-55); }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .row-actions form{ display:inline; }
    .btn-sm{ padding:6px 12px; font-size:12px; font-weight:600; border:2px solid var(--navy); cursor:pointer; background:var(--white); color:var(--navy); text-decoration:none; display:inline-flex; align-items:center; }
    .btn-sm:hover{ background:var(--orange); border-color:var(--orange); color:var(--white); }
    .btn-sm.danger{ border-color:#c0392b; color:#c0392b; }
    .btn-sm.danger:hover{ background:#c0392b; border-color:#c0392b; color:var(--white); }
    .btn-sm:disabled{ opacity:0.4; cursor:not-allowed; }
    .btn-sm:disabled:hover{ background:var(--white); color:var(--navy); }

    .line-items-wrap{ max-width:1200px; border:2px solid var(--navy); overflow-x:auto; margin-bottom:16px; }
    .line-items{ width:100%; border-collapse:collapse; min-width:820px; }
    .line-items th, .line-items td{ padding:8px 10px; border-bottom:1px solid var(--navy-30); vertical-align:top; }
    .line-items th{ background:var(--navy-10); font-size:11px; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
    .line-items input, .line-items select{ width:100%; padding:7px 8px; border:1.5px solid rgba(46,49,146,0.3); font-size:13px; font-family:'Google Sans',sans-serif; color:var(--navy); }
    .line-items input:focus, .line-items select:focus{ outline:none; border-color:var(--orange); }
    .remove-line-btn{ background:none; border:none; color:#c0392b; cursor:pointer; font-size:20px; line-height:1; padding:4px; }
    .add-line-btn{ margin-bottom:20px; }
    .totals-row{ display:flex; gap:20px; flex-wrap:wrap; max-width:1200px; margin-bottom:20px; align-items:flex-end; }
    .totals-row .form-group{ min-width:160px; flex:0 1 200px; }
    .due-display{ font-weight:700; font-size:15px; color:var(--navy); padding:11px 0; }
    .due-display.balanced{ color:#1b7a3d; }
    .due-display.unbalanced{ color:#c0392b; }
    .form-actions{ display:flex; gap:10px; max-width:1200px; }

    .info-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:18px 24px; max-width:1200px; margin-bottom:32px; padding:24px; border:2px solid var(--navy); }
    .info-field .info-label{ font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy-55); margin-bottom:4px; }
    .info-field .info-value{ font-size:14.5px; font-weight:600; color:var(--navy); word-break:break-word; }
    .subsection-title{ font-size:16px; font-weight:700; color:var(--navy); margin-bottom:12px; display:flex; align-items:center; gap:10px; max-width:1200px; }

    .modal-overlay{ position:fixed; inset:0; background:rgba(46,49,146,0.35); display:none; align-items:center; justify-content:center; z-index:1000; padding:20px; }
    .modal-overlay.show{ display:flex; }
    .modal-box{ background:var(--white); border:2px solid var(--navy); width:100%; max-width:480px; max-height:90vh; overflow-y:auto; }
    .modal-head{ display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:2px solid var(--navy); }
    .modal-head h3{ font-size:16px; font-weight:700; color:var(--navy); }
    .modal-close{ appearance:none; background:none; border:none; cursor:pointer; color:var(--navy-55); width:26px; height:26px; }
    .modal-close:hover{ color:var(--orange); }
    .modal-close svg{ width:100%; height:100%; }
    .modal-body{ padding:22px; }
    .modal-body .form-group{ margin-bottom:16px; }
    .modal-actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:6px; }

    #js-toast{ position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(20px); display:flex; align-items:center; gap:8px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:10px 18px; white-space:nowrap; z-index:9999; opacity:0; pointer-events:none; transition:opacity 0.2s ease, transform 0.2s ease; }
    #js-toast svg{ width:16px; height:16px; flex-shrink:0; }
    #js-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    .logout-fab{ position:fixed; bottom:20px; right:20px; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--navy); border:2px solid var(--white); cursor:pointer; text-decoration:none; z-index:9999; box-shadow:0 2px 6px rgba(46,49,146,0.35); transition:transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease; }
    .logout-fab svg{ width:18px; height:18px; stroke:var(--white); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.15s ease; }
    .logout-fab:hover{ background:var(--orange); transform:scale(1.08); box-shadow:0 4px 10px rgba(241,90,36,0.4); }
    .logout-fab:active{ transform:scale(0.96); }
    .logout-fab:focus-visible{ outline:2px solid var(--orange); outline-offset:3px; }
    .logout-fab::after{ content:'Log Out'; position:absolute; bottom:calc(100% + 8px); right:0; background:var(--navy); color:var(--white); font-family:'Google Sans', sans-serif; font-size:12px; font-weight:600; padding:6px 10px; white-space:nowrap; opacity:0; visibility:hidden; transform:translateY(4px); transition:opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease; pointer-events:none; }
    .logout-fab:hover::after, .logout-fab:focus-visible::after{ opacity:1; visibility:visible; transform:translateY(0); }

    @media print{
        .app-header, .logout-fab, #js-toast, .no-print{ display:none !important; }
        .page-body{ padding:0; }
        .info-grid, .data-table-wrap{ max-width:100%; }
    }
</style>
<link rel="stylesheet" href="../assets/clinic.css">
<script src="../assets/clinic.js" defer></script>
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
                    <?php require __DIR__ . '/../includes/notifications.php'; ?>
                </div>
            </div>
            <?php require __DIR__ . '/../includes/profile.php'; ?>
        </div>
    </div>

    <nav class="menu-bar">
        <ul class="nav-items">
            <?php foreach (tdc_navigation(NAV_ITEMS) as $item): ?>
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

    <div class="welcome-eyebrow">Accounting</div>
    <div class="welcome-title">Accounting Overview</div>
    <div class="welcome-sub">General ledger, chart of accounts, and where the clinic's money stands.</div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Net Income (This Month)</div>
            <div class="kpi-value<?= $hubNetIncomeMonth < 0 ? ' negative' : '' ?>"><?= number_format($hubNetIncomeMonth, 2) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Cash Book Balance</div>
            <div class="kpi-value<?= $hubCashBalance < 0 ? ' negative' : '' ?>"><?= number_format($hubCashBalance, 2) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Accounts Receivable</div>
            <div class="kpi-value<?= $hubReceivableDue < 0 ? ' negative' : '' ?>"><?= number_format($hubReceivableDue, 2) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Accounts Payable</div>
            <div class="kpi-value<?= $hubPayableDue < 0 ? ' negative' : '' ?>"><?= number_format($hubPayableDue, 2) ?></div>
        </div>
    </div>

    <div class="setup-grid">
        <a href="accounting.php?section=ledger" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M6 4h9l5 5v11a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M14 4v5h5"/><path d="M8 13h8M8 17h5"/></svg></div>
            <div>
                <div class="setup-card-title">General Ledger</div>
                <div class="setup-card-desc"><?= $hubEntryCountMonth ?> entr<?= $hubEntryCountMonth === 1 ? 'y' : 'ies' ?> posted this month</div>
            </div>
        </a>
        <a href="accounting.php?section=accounts" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M4 19V5a1 1 0 011-1h4v16H5a1 1 0 01-1-1z"/><path d="M10 4h9a1 1 0 011 1v14a1 1 0 01-1 1h-9"/><path d="M14 9h3M14 13h3"/></svg></div>
            <div>
                <div class="setup-card-title">Chart of Accounts</div>
                <div class="setup-card-desc"><?= $hubAccountCount ?> account<?= $hubAccountCount === 1 ? '' : 's' ?> tracked</div>
            </div>
        </a>
    </div>

<?php else: ?>

    <a href="accounting.php" class="back-link no-print">&larr; Back to Accounting</a>

    <?php if (!empty($errors)): ?>
    <div class="error-msg">
        <div class="error-title">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/></svg>
            Please fix the following:
        </div>
        <ul>
            <?php foreach ($errors as $err): ?><li><?= tdc_e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php // ============================================================
          // GENERAL LEDGER
          // ============================================================ ?>
    <?php if ($section === 'ledger'): ?>

        <?php if ($viewEntryRef !== ''): ?>
        <?php $head = $viewEntryLines[0]; ?>
        <div class="welcome-title">Journal Entry — <?= tdc_e($viewEntryRef) ?></div>
        <div class="welcome-sub"><?= tdc_e(date('Y-m-d H:i', strtotime((string) $head['TransactionDate']))) ?> &middot; <?= tdc_e($head['BookType']) ?></div>

        <div class="info-grid">
            <div class="info-field"><div class="info-label">Description</div><div class="info-value"><?= tdc_e($head['Description']) ?></div></div>
            <div class="info-field"><div class="info-label">Reference</div><div class="info-value"><?= tdc_e((string) $head['ReferenceID'] ?: '—') ?></div></div>
            <div class="info-field"><div class="info-label">Status</div><div class="info-value">
                <?php if ($viewIsReversal): ?><span class="status-badge warn">Reversal</span>
                <?php elseif ($viewIsReversed): ?><span class="status-badge muted">Reversed</span>
                <?php else: ?><span class="status-badge">Posted</span><?php endif; ?>
            </div></div>
        </div>

        <?php if ($viewIsReversal): ?>
        <div class="error-msg" style="border-color:var(--orange);">
            <div class="error-title" style="color:var(--orange);">This is a reversal of <?= tdc_e((string) $head['ReferenceID']) ?></div>
            <div><?= tdc_e($viewReversalOfDescription) ?></div>
        </div>
        <?php endif; ?>

        <div class="subsection-title">Lines</div>
        <div class="data-table-wrap" style="margin-bottom:24px;">
            <table class="data-table">
                <thead><tr><th>Account</th><th>Type</th><th>Debit</th><th>Credit</th></tr></thead>
                <tbody>
                    <?php foreach ($viewEntryLines as $l): ?>
                    <tr>
                        <td><?= tdc_e($l['AccountName']) ?> <span style="color:var(--navy-55);">(<?= tdc_e($l['AccountID']) ?>)</span></td>
                        <td><?= tdc_e($l['AccountType']) ?></td>
                        <td><?= (float) $l['Debit'] > 0 ? number_format((float) $l['Debit'], 2) : '—' ?></td>
                        <td><?= (float) $l['Credit'] > 0 ? number_format((float) $l['Credit'], 2) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row-actions no-print">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
            <form method="POST" action="accounting.php?section=ledger" onsubmit="return confirm('Reverse this entry? A new offsetting entry will be posted — the original is never deleted. This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="void">
                <input type="hidden" name="EntryRef" value="<?= tdc_e($viewEntryRef) ?>">
                <button type="submit" class="btn-sm danger" <?= ($viewIsReversal || $viewIsReversed) ? 'disabled' : '' ?>>Reverse Entry</button>
            </form>
        </div>

        <?php elseif ($entryShowForm): ?>

        <div class="welcome-title">New Journal Entry</div>
        <div class="welcome-sub">Every entry must balance — total debits must equal total credits.</div>

        <datalist id="existingAccountNames">
            <?php foreach ($existingAccountNames as $acct): ?><option value="<?= tdc_e($acct['AccountName']) ?>"><?php endforeach; ?>
        </datalist>

        <form id="journalForm" method="POST" action="accounting.php?section=ledger">
            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
            <input type="hidden" name="form_action" value="save">

            <div class="form-row" style="max-width:1200px;margin-bottom:16px;">
                <div class="form-group"><label for="jf_TransactionDate">Date</label>
                    <input type="date" id="jf_TransactionDate" name="TransactionDate" value="<?= tdc_e($oldEntry['TransactionDate']) ?>"></div>
                <div class="form-group"><label for="jf_BookType">Book</label>
                    <select id="jf_BookType" name="BookType">
                        <?php foreach (BOOK_TYPE_OPTIONS as $v => $l): ?>
                            <option value="<?= tdc_e($v) ?>" <?= $oldEntry['BookType'] === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label for="jf_ReferenceID">Reference (optional)</label>
                    <input type="text" id="jf_ReferenceID" name="ReferenceID" value="<?= tdc_e($oldEntry['ReferenceID']) ?>" placeholder="e.g. POS000042"></div>
            </div>
            <div class="form-group" style="max-width:1200px;margin-bottom:20px;">
                <label for="jf_Description">Description</label>
                <textarea id="jf_Description" name="Description" required><?= tdc_e($oldEntry['Description']) ?></textarea>
            </div>

            <div class="line-items-wrap">
                <table class="line-items" id="lineItemsTable">
                    <thead><tr><th style="width:40px;">#</th><th>Account</th><th style="width:150px;">Type</th><th style="width:120px;">Debit</th><th style="width:120px;">Credit</th><th style="width:36px;"></th></tr></thead>
                    <tbody id="lineItemsBody">
                    <?php
                    $entryLineCount = max(2, count($oldEntry['AccountName']));
                    for ($i = 0; $i < $entryLineCount; $i++):
                    ?>
                        <tr class="line-item-row">
                            <td class="line-no"><?= $i + 1 ?></td>
                            <td><input type="text" list="existingAccountNames" name="AccountName[]" class="account-name-input" value="<?= tdc_e($oldEntry['AccountName'][$i] ?? '') ?>" placeholder="e.g. Cash on Hand"></td>
                            <td><select name="AccountType[]" class="account-type-select">
                                    <option value="">Select type</option>
                                    <?php foreach (ACCOUNT_TYPE_OPTIONS as $v => $l): ?>
                                        <option value="<?= tdc_e($v) ?>" <?= (($oldEntry['AccountType'][$i] ?? '') === $v) ? 'selected' : '' ?>><?= tdc_e($l) ?></option>
                                    <?php endforeach; ?>
                                </select></td>
                            <td><input type="number" step="0.01" min="0" name="Debit[]" class="debit-input" value="<?= tdc_e($oldEntry['Debit'][$i] ?? '') ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="Credit[]" class="credit-input" value="<?= tdc_e($oldEntry['Credit'][$i] ?? '') ?>"></td>
                            <td><button type="button" class="remove-line-btn" title="Remove line">&times;</button></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-secondary add-line-btn" id="addLineBtn">+ Add Line</button>

            <div class="totals-row">
                <div class="form-group"><label>Total Debit</label><div class="due-display" id="jf_TotalDebit">0.00</div></div>
                <div class="form-group"><label>Total Credit</label><div class="due-display" id="jf_TotalCredit">0.00</div></div>
                <div class="form-group"><label>Difference</label><div class="due-display" id="jf_Difference">0.00</div></div>
            </div>

            <div class="form-actions">
                <a href="accounting.php?section=ledger" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary" id="jf_SubmitBtn">Post Entry</button>
            </div>
        </form>

        <?php else: ?>

        <div class="welcome-title">General Ledger</div>
        <div class="welcome-sub">Every posted journal entry. Voiding posts a reversing entry — nothing is ever deleted.</div>

        <div class="section-toolbar">
            <form method="GET" action="accounting.php" class="filter-box">
                <input type="hidden" name="section" value="ledger">
                <input type="text" name="q" placeholder="Search description, reference, or ID..." value="<?= tdc_e($ledgerSearch) ?>">
                <select name="book">
                    <option value="">All Books</option>
                    <?php foreach (BOOK_TYPE_OPTIONS as $v => $l): ?>
                        <option value="<?= tdc_e($v) ?>" <?= $ledgerBook === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="from" value="<?= tdc_e($ledgerFrom) ?>">
                <input type="date" name="to" value="<?= tdc_e($ledgerTo) ?>">
                <button type="submit" class="btn btn-secondary">Filter</button>
            </form>
            <a href="accounting.php?section=ledger&new=1" class="btn btn-primary">+ New Entry</a>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Entry Ref</th><th>Description</th><th>Book</th><th>Debit</th><th>Credit</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($journalEntries)): ?>
                    <tr class="empty-row"><td colspan="8">No journal entries found<?= $ledgerSearch !== '' ? ' for "' . tdc_e($ledgerSearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($journalEntries as $je): ?>
                    <tr>
                        <td><?= tdc_e($je['EntryRef']) ?></td>
                        <td style="white-space:normal;max-width:320px;"><?= tdc_e($je['Description']) ?></td>
                        <td><?= tdc_e($je['BookType']) ?></td>
                        <td><?= number_format((float) $je['TotalDebit'], 2) ?></td>
                        <td><?= number_format((float) $je['TotalCredit'], 2) ?></td>
                        <td>
                            <?php if ($je['IsReversal']): ?><span class="status-badge warn">Reversal</span>
                            <?php elseif ($je['IsReversed']): ?><span class="status-badge muted">Reversed</span>
                            <?php else: ?><span class="status-badge">Posted</span><?php endif; ?>
                        </td>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $je['TransactionDate']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="accounting.php?section=ledger&view=<?= urlencode($je['EntryRef']) ?>" class="btn-sm">View</a>
                                <form method="POST" action="accounting.php?section=ledger" onsubmit="return confirm('Reverse this entry? A new offsetting entry will be posted — the original is never deleted. This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="void">
                                    <input type="hidden" name="EntryRef" value="<?= tdc_e($je['EntryRef']) ?>">
                                    <button type="submit" class="btn-sm danger" <?= ($je['IsReversal'] || $je['IsReversed']) ? 'disabled' : '' ?>>Reverse</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

    <?php // ============================================================
          // CHART OF ACCOUNTS
          // ============================================================ ?>
    <?php elseif ($section === 'accounts'): ?>

        <?php if ($viewAccountId !== '' && $viewAccountHead !== null): ?>

        <div class="welcome-title"><?= tdc_e($viewAccountHead['AccountName']) ?></div>
        <div class="welcome-sub"><?= tdc_e($viewAccountId) ?> &middot; <?= tdc_e($viewAccountHead['AccountType']) ?></div>

        <div class="info-grid">
            <div class="info-field"><div class="info-label">Total Debit</div><div class="info-value"><?= number_format((float) $viewAccountHead['TotalDebit'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Total Credit</div><div class="info-value"><?= number_format((float) $viewAccountHead['TotalCredit'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Balance</div><div class="info-value"><?= number_format((float) $viewAccountHead['NormalizedBalance'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Entries</div><div class="info-value"><?= (int) $viewAccountHead['EntryCount'] ?></div></div>
        </div>

        <div class="row-actions no-print" style="margin-bottom:20px;">
            <button type="button" class="btn btn-secondary rename-account-btn"
                data-id="<?= tdc_e($viewAccountId) ?>" data-name="<?= tdc_e($viewAccountHead['AccountName']) ?>">Rename Account</button>
        </div>

        <div class="subsection-title">Transaction History</div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Entry Ref</th><th>Description</th><th>Debit</th><th>Credit</th><th>Running Balance</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if (empty($viewAccountLines)): ?>
                    <tr class="empty-row"><td colspan="6">No transactions on record.</td></tr>
                    <?php else: foreach ($viewAccountLines as $l): ?>
                    <tr>
                        <td><a href="accounting.php?section=ledger&view=<?= urlencode($l['EntryRef']) ?>"><?= tdc_e($l['EntryRef']) ?></a></td>
                        <td style="white-space:normal;max-width:320px;"><?= tdc_e($l['Description']) ?></td>
                        <td><?= (float) $l['Debit'] > 0 ? number_format((float) $l['Debit'], 2) : '—' ?></td>
                        <td><?= (float) $l['Credit'] > 0 ? number_format((float) $l['Credit'], 2) : '—' ?></td>
                        <td><?= number_format((float) $l['Balance'], 2) ?></td>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $l['TransactionDate']))) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>

        <div class="welcome-title">Chart of Accounts</div>
        <div class="welcome-sub">Every account that has appeared on a journal entry, with its running balance.</div>

        <div class="section-toolbar">
            <form method="GET" action="accounting.php" class="filter-box">
                <input type="hidden" name="section" value="accounts">
                <input type="text" name="q" placeholder="Search by account name or ID..." value="<?= tdc_e($accountSearch) ?>">
                <button type="submit" class="btn btn-secondary">Search</button>
            </form>
            <span style="font-size:12.5px;color:var(--navy-55);">New accounts are created automatically the first time they're used on a journal entry.</span>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Account ID</th><th>Name</th><th>Type</th><th>Total Debit</th><th>Total Credit</th><th>Balance</th><th>Entries</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($chartOfAccounts)): ?>
                    <tr class="empty-row"><td colspan="8">No accounts found<?= $accountSearch !== '' ? ' for "' . tdc_e($accountSearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($chartOfAccounts as $acct): ?>
                    <tr>
                        <td><?= tdc_e($acct['AccountID']) ?></td>
                        <td><?= tdc_e($acct['AccountName']) ?></td>
                        <td><?= tdc_e($acct['AccountType']) ?></td>
                        <td><?= number_format((float) $acct['TotalDebit'], 2) ?></td>
                        <td><?= number_format((float) $acct['TotalCredit'], 2) ?></td>
                        <td><?= number_format((float) $acct['NormalizedBalance'], 2) ?></td>
                        <td><?= (int) $acct['EntryCount'] ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="accounting.php?section=accounts&view=<?= urlencode($acct['AccountID']) ?>" class="btn-sm">View</a>
                                <button type="button" class="btn-sm rename-account-btn"
                                    data-id="<?= tdc_e($acct['AccountID']) ?>" data-name="<?= tdc_e($acct['AccountName']) ?>">Rename</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

        <div class="modal-overlay" id="renameModalOverlay">
            <div class="modal-box">
                <div class="modal-head">
                    <h3>Rename Account</h3>
                    <button type="button" class="modal-close" id="renameModalCloseBtn" aria-label="Close">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
                <form id="renameForm" method="POST" action="accounting.php?section=accounts">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="form_action" value="rename">
                        <input type="hidden" name="AccountID" id="rf_AccountID" value="">
                        <div class="form-group"><label for="rf_NewName">Account Name</label>
                            <input type="text" id="rf_NewName" name="NewName" required></div>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" id="renameModalCancelBtn">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

<?php endif; ?>

</main>

<div id="js-toast" role="alert" aria-live="assertive">
    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.03-9.78a.75.75 0 00-1.06-1.06L8.75 10.44l-1.72-1.72a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.75-4.75z" clip-rule="evenodd"/></svg>
    <span id="js-toast-msg"></span>
</div>

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

let toastTimer = null;
function showToast(message){
    const toast = document.getElementById('js-toast');
    document.getElementById('js-toast-msg').textContent = message;
    clearTimeout(toastTimer);
    toast.classList.add('show');
    toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
}

<?php if ($section === 'ledger' && $entryShowForm): ?>
(function(){
    // AccountName -> AccountType lookup, so picking a known account
    // auto-selects (and locks) its type — one account can't end up
    // split across two types from the browser side either.
    const accountTypeMap = <?= json_encode(array_combine(
        array_map(static fn ($a) => mb_strtolower($a['AccountName']), $existingAccountNames),
        array_map(static fn ($a) => $a['AccountType'], $existingAccountNames)
    ), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const tbody = document.getElementById('lineItemsBody');

    function wireRow(row){
        const nameInput = row.querySelector('.account-name-input');
        const typeSelect = row.querySelector('.account-type-select');
        const debitInput = row.querySelector('.debit-input');
        const creditInput = row.querySelector('.credit-input');

        nameInput.addEventListener('input', function(){
            const match = accountTypeMap[nameInput.value.trim().toLowerCase()];
            if (match){
                typeSelect.value = match;
                typeSelect.disabled = true;
            } else {
                typeSelect.disabled = false;
            }
        });
        if (accountTypeMap[nameInput.value.trim().toLowerCase()]) {
            typeSelect.disabled = true;
        }

        debitInput.addEventListener('input', function(){
            if (parseFloat(debitInput.value) > 0) creditInput.value = '';
            recalcAll();
        });
        creditInput.addEventListener('input', function(){
            if (parseFloat(creditInput.value) > 0) debitInput.value = '';
            recalcAll();
        });

        row.querySelector('.remove-line-btn').addEventListener('click', function(){
            if (tbody.querySelectorAll('.line-item-row').length > 2){ row.remove(); renumber(); recalcAll(); }
        });
    }

    function renumber(){
        tbody.querySelectorAll('.line-item-row').forEach(function(row, i){ row.querySelector('.line-no').textContent = i + 1; });
    }

    function recalcAll(){
        let totalDebit = 0;
        let totalCredit = 0;
        tbody.querySelectorAll('.line-item-row').forEach(function(row){
            totalDebit += parseFloat(row.querySelector('.debit-input').value) || 0;
            totalCredit += parseFloat(row.querySelector('.credit-input').value) || 0;
        });
        document.getElementById('jf_TotalDebit').textContent = totalDebit.toFixed(2);
        document.getElementById('jf_TotalCredit').textContent = totalCredit.toFixed(2);
        const diff = totalDebit - totalCredit;
        const diffEl = document.getElementById('jf_Difference');
        diffEl.textContent = diff.toFixed(2);
        diffEl.classList.toggle('balanced', Math.abs(diff) < 0.01 && totalDebit > 0);
        diffEl.classList.toggle('unbalanced', Math.abs(diff) >= 0.01);
    }

    tbody.querySelectorAll('.line-item-row').forEach(wireRow);

    document.getElementById('addLineBtn').addEventListener('click', function(){
        const template = tbody.querySelector('.line-item-row').cloneNode(true);
        template.querySelector('.account-name-input').value = '';
        template.querySelector('.account-type-select').value = '';
        template.querySelector('.account-type-select').disabled = false;
        template.querySelector('.debit-input').value = '';
        template.querySelector('.credit-input').value = '';
        tbody.appendChild(template);
        wireRow(template);
        renumber();
    });

    recalcAll();
})();
<?php endif; ?>

<?php if ($section === 'accounts'): ?>
(function(){
    const overlay = document.getElementById('renameModalOverlay');
    const fId = document.getElementById('rf_AccountID');
    const fName = document.getElementById('rf_NewName');

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.querySelectorAll('.rename-account-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            openModal();
        });
    });

    document.getElementById('renameModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('renameModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
})();
<?php endif; ?>

<?php if ($justSaved || $justVoided || $justRenamed): ?>
(function(){
    let message = 'Saved successfully.';
    <?php if ($justVoided): ?>message = 'Reversing entry posted successfully.';<?php endif; ?>
    <?php if ($justRenamed): ?>message = 'Account renamed successfully.';<?php endif; ?>
    <?php if ($justSaved): ?>message = 'Journal entry posted successfully.';<?php endif; ?>
    showToast(message);
})();
<?php endif; ?>
</script>

</body>
</html>
