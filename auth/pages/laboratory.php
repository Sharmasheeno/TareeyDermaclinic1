<?php
/**
 * auth/pages/laboratory.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Laboratory Bills
 * ---------------------------------------------------------------------
 * Dedicated management console for the Laboratory table: order tests
 * for a patient, and track results and payment status over time.
 *
 * Reception's "New Lab Bill" modal (reception.php?section=laboratory)
 * remains in place for fast entry during patient intake — both pages
 * write to the same table through the same shape of persistence logic
 * — but this page is the full console: search, filter by result and
 * payment status, see in-house vs. sent-out tests at a glance, and
 * jump straight to the patient record. This mirrors the relationship
 * already established between reception.php and patients.php for
 * patient records.
 *
 * Security controls (same posture as home.php / reception.php /
 * patients.php / doctors.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: any authenticated role may VIEW; only 'superuser' and
 *     'receptionuser' may add/edit/delete (checked server-side on
 *     every POST, independent of what the UI shows/hides)
 *   - CSRF-token-checked POST handler, rotated on every submit
 *   - Prepared statements only — no string-built SQL from user input
 *   - Post/Redirect/Get on every successful write
 *   - All session/user-derived output escaped before hitting HTML
 *
 * Schema alignment (tareydermaclinic.Laboratory):
 *   LaboratoryID   VARCHAR(50)  PK   — "LAB000042", generated here
 *   PatientID      INT          NOT NULL (no FK — app-level guard)
 *   TestID         INT          NOT NULL — running integer, no catalog
 *   TestName       VARCHAR(150) NOT NULL
 *   Description    TEXT
 *   Price          DECIMAL(10,2)
 *   IsAvailable    BOOLEAN      — in-house vs. sent out
 *   OrderDate      DATETIME
 *   Result         ENUM('Positive','Negative','Pending')
 *   ResultDate     DATETIME NULL
 *   PaymentStatus  ENUM('Paid','Unpaid','Partial')
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
const ALLOWED_MANAGE_ROLES = ['superuser', 'receptionuser'];

const LAB_RESULT_OPTIONS = [
    'Pending'  => 'Pending',
    'Positive' => 'Positive',
    'Negative' => 'Negative',
];

const PAYMENT_STATUS_OPTIONS = [
    'Unpaid'  => 'Unpaid',
    'Partial' => 'Partial',
    'Paid'    => 'Paid',
];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / patients.php / doctors.php / settings.php.
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

/**
 * Validates a <input type="datetime-local"> value ("Y-m-d\TH:i", with
 * or without seconds). Used for ResultDate so a malformed value never
 * reaches the database as a silently-truncated string.
 */
function tdc_is_valid_datetime_local(string $value): bool
{
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s'] as $format) {
        $d = DateTime::createFromFormat($format, $value);
        if ($d !== false && $d->format($format) === $value) {
            return true;
        }
    }
    return false;
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

/** Redirects (Post/Redirect/Get) back to the list with a one-shot flash flag. */
function tdc_redirect(string $flag): void
{
    header('Location: laboratory.php?' . $flag . '=1');
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
 * e.g. tdc_next_ref($pdo, 'Laboratory', 'LaboratoryID', 'LAB') -> "LAB000042".
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

// =======================================================================
// SECTION 4 — Validation
// =======================================================================

/** @param array{PatientID:string,TestName:string,Description:string,Price:string,IsAvailable:string,Result:string,ResultDate:string,PaymentStatus:string} $input */
function tdc_validate_lab_form(PDO $pdo, array $input): array
{
    $errors = [];

    if ($input['PatientID'] === '' || !ctype_digit($input['PatientID'])) {
        $errors[] = 'Please select a valid patient.';
    } elseif ((int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Patients WHERE PatientID = :id', ['id' => (int) $input['PatientID']]) === 0) {
        // App-level referential guard: the schema has no FK constraints,
        // so a stale or tampered PatientID is caught here rather than
        // at INSERT time.
        $errors[] = 'Selected patient no longer exists.';
    }

    if ($input['TestName'] === '' || mb_strlen($input['TestName']) > 150) {
        $errors[] = 'Test name is required (max 150 characters).';
    }
    if ($input['Description'] !== '' && mb_strlen($input['Description']) > 2000) {
        $errors[] = 'Description is too long (max 2000 characters).';
    }
    if ($input['Price'] === '' || !is_numeric($input['Price']) || (float) $input['Price'] < 0) {
        $errors[] = 'Price must be a valid non-negative number.';
    }
    if (!array_key_exists($input['PaymentStatus'], PAYMENT_STATUS_OPTIONS)) {
        $errors[] = 'Please select a valid payment status.';
    }
    if ($input['Result'] !== '' && !array_key_exists($input['Result'], LAB_RESULT_OPTIONS)) {
        $errors[] = 'Please select a valid result.';
    }
    if ($input['ResultDate'] !== '' && !tdc_is_valid_datetime_local($input['ResultDate'])) {
        $errors[] = 'Result date is not a valid date/time.';
    }

    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence
// =======================================================================

function tdc_save_lab(PDO $pdo, array $input, bool $isEdit, string $editId): void
{
    $params = [
        'PatientID'     => (int) $input['PatientID'],
        'TestName'      => $input['TestName'],
        'Description'   => $input['Description'] !== '' ? $input['Description'] : null,
        'Price'         => round((float) $input['Price'], 2),
        'IsAvailable'   => ($input['IsAvailable'] ?? '') === '1' ? 1 : 0,
        'Result'        => $input['Result'] !== '' ? $input['Result'] : 'Pending',
        'ResultDate'    => $input['ResultDate'] !== '' ? str_replace('T', ' ', $input['ResultDate']) : null,
        'PaymentStatus' => $input['PaymentStatus'],
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE Laboratory SET PatientID = :PatientID, TestName = :TestName,
                Description = :Description, Price = :Price, IsAvailable = :IsAvailable,
                Result = :Result, ResultDate = :ResultDate, PaymentStatus = :PaymentStatus
             WHERE LaboratoryID = :id'
        );
        $stmt->execute($params);
        return;
    }

    // TestID has no catalog table in this codebase, so it is a simple
    // running integer — unique enough for display/reference purposes.
    $params['LaboratoryID'] = tdc_next_ref($pdo, 'Laboratory', 'LaboratoryID', 'LAB');
    $params['TestID']       = (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(TestID), 0) + 1 FROM Laboratory') ?: 1;

    $stmt = $pdo->prepare(
        'INSERT INTO Laboratory (LaboratoryID, PatientID, TestID, TestName, Description,
            Price, IsAvailable, Result, ResultDate, PaymentStatus)
         VALUES (:LaboratoryID, :PatientID, :TestID, :TestName, :Description,
            :Price, :IsAvailable, :Result, :ResultDate, :PaymentStatus)'
    );
    $stmt->execute($params);
}

function tdc_delete_lab(PDO $pdo, string $id): void
{
    // Nothing else in the schema references LaboratoryID, so unlike
    // Patients/Doctors this delete needs no dependent-record guard.
    $stmt = $pdo->prepare('DELETE FROM Laboratory WHERE LaboratoryID = :id');
    $stmt->execute(['id' => $id]);
}

// =======================================================================
// SECTION 6 — Logout (may exit)
// =======================================================================
tdc_handle_logout();

// =======================================================================
// SECTION 7 — Auth gate (role gate for writes is enforced in SECTION 9)
// =======================================================================
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

require_once __DIR__ . '/../../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canManage = in_array($_SESSION['role'] ?? '', ALLOWED_MANAGE_ROLES, true);

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$errors = [];
$old = [
    'LaboratoryID' => '', 'PatientID' => '', 'PatientLabel' => '', 'TestName' => '',
    'Description' => '', 'Price' => '', 'IsAvailable' => '1', 'Result' => 'Pending',
    'ResultDate' => '', 'PaymentStatus' => 'Unpaid',
];

// =======================================================================
// SECTION 9 — POST handler (save / delete)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        // Defense in depth: the UI already hides these controls for
        // non-manager roles, but never trust the client alone.
        header('Location: laboratory.php');
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        if ($formAction === 'delete') {
            $deleteId = trim((string) ($_POST['LaboratoryID'] ?? ''));
            if ($deleteId === '') {
                $errors[] = 'Invalid laboratory bill selected.';
            } else {
                try {
                    tdc_delete_lab($pdo, $deleteId);
                    tdc_redirect('deleted');
                } catch (PDOException $e) {
                    error_log('[LABORATORY] delete failed: ' . $e->getMessage());
                    $errors[] = 'A system error occurred while deleting the bill. Please try again.';
                }
            }
        } else {
            $old['LaboratoryID']  = trim((string) ($_POST['LaboratoryID'] ?? ''));
            $old['PatientID']     = trim((string) ($_POST['PatientID'] ?? ''));
            $old['PatientLabel']  = trim((string) ($_POST['PatientLabel'] ?? ''));
            $old['TestName']      = trim((string) ($_POST['TestName'] ?? ''));
            $old['Description']   = trim((string) ($_POST['Description'] ?? ''));
            $old['Price']         = trim((string) ($_POST['Price'] ?? ''));
            $old['IsAvailable']   = (string) ($_POST['IsAvailable'] ?? '0');
            $old['Result']        = (string) ($_POST['Result'] ?? 'Pending');
            $old['ResultDate']    = trim((string) ($_POST['ResultDate'] ?? ''));
            $old['PaymentStatus'] = (string) ($_POST['PaymentStatus'] ?? 'Unpaid');

            $isEdit = $old['LaboratoryID'] !== '';
            $errors = tdc_validate_lab_form($pdo, $old);

            if (empty($errors)) {
                try {
                    tdc_save_lab($pdo, $old, $isEdit, $old['LaboratoryID']);
                    tdc_redirect('success');
                } catch (PDOException $e) {
                    error_log('[LABORATORY] save failed: ' . $e->getMessage());
                    $errors[] = 'A system error occurred while saving the bill. Please try again.';
                }
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above via header()).
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 10 — GET data loading
// =======================================================================

// --- 10A. Patient options for the search combobox (Add/Edit modal) -----
$stmt = $pdo->query('SELECT PatientID, PatientName, PatientPhone FROM Patients ORDER BY PatientName ASC LIMIT 500');
$patientOptions = [];
foreach ($stmt->fetchAll() as $p) {
    $patientOptions[] = [
        'id'    => (int) $p['PatientID'],
        'label' => $p['PatientName'] . ($p['PatientPhone'] ? ' — ' . $p['PatientPhone'] : ''),
    ];
}

// --- 10B. Search + filters ------------------------------------------------
$search       = trim((string) ($_GET['q'] ?? ''));
$resultFilter = (string) ($_GET['result'] ?? '');
if (!array_key_exists($resultFilter, LAB_RESULT_OPTIONS)) {
    $resultFilter = '';
}
$paymentFilter = (string) ($_GET['payment'] ?? '');
if (!array_key_exists($paymentFilter, PAYMENT_STATUS_OPTIONS)) {
    $paymentFilter = '';
}

$conditions = [];
$params     = [];

if ($search !== '') {
    $conditions[] = '(p.PatientName LIKE :q OR p.PatientPhone LIKE :q OR l.TestName LIKE :q)';
    $params['q']  = '%' . $search . '%';
}
if ($resultFilter !== '') {
    $conditions[]       = 'l.Result = :result';
    $params['result']   = $resultFilter;
}
if ($paymentFilter !== '') {
    $conditions[]        = 'l.PaymentStatus = :payment';
    $params['payment']   = $paymentFilter;
}

$where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
$stmt  = $pdo->prepare(
    "SELECT l.*, p.PatientName, p.PatientPhone FROM Laboratory l
     JOIN Patients p ON p.PatientID = l.PatientID
     {$where}
     ORDER BY l.OrderDate DESC
     LIMIT 200"
);
$stmt->execute($params);
$labBills = $stmt->fetchAll();

$hasActiveFilters = $search !== '' || $resultFilter !== '' || $paymentFilter !== '';

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'laboratory.php'));

$justSaved   = isset($_GET['success']);
$justDeleted = isset($_GET['deleted']);
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

    .error-msg{ display:flex; flex-direction:column; gap:4px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:14px 16px; margin-bottom:24px; max-width:1240px; }
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
    .checkbox-row{ display:flex; align-items:center; gap:8px; }
    .checkbox-row input{ width:auto; }
    .btn{ padding:11px 22px; font-size:14px; font-weight:600; border:2px solid var(--navy); cursor:pointer; letter-spacing:0.02em; transition:background 0.12s, color 0.12s, border-color 0.12s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary{ background:var(--navy); color:var(--white); }
    .btn-primary:hover{ background:var(--orange); border-color:var(--orange); }
    .btn-secondary{ background:var(--white); color:var(--navy); }
    .btn-secondary:hover{ color:var(--orange); border-color:var(--orange); }

    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1240px; margin-bottom:16px; flex-wrap:wrap; }
    .filter-box{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .filter-box input, .filter-box select{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); background:var(--white); }
    .filter-box input{ min-width:240px; }
    .filter-box input:focus, .filter-box select:focus{ outline:none; border-color:var(--orange); }
    .clear-filters{ font-size:12.5px; font-weight:600; color:var(--navy-55); text-decoration:none; white-space:nowrap; }
    .clear-filters:hover{ color:var(--orange); }

    .data-table-wrap{ max-width:1240px; border:2px solid var(--navy); overflow-x:auto; }
    .data-table{ width:100%; border-collapse:collapse; }
    .data-table th, .data-table td{ padding:12px 14px; font-size:13px; text-align:left; border-bottom:1px solid var(--navy-30); white-space:nowrap; }
    .data-table th{ background:var(--navy-10); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.05em; color:var(--navy); }
    .data-table tbody tr:last-child td{ border-bottom:none; }
    .data-table tbody tr:hover{ background:var(--navy-10); }
    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }
    .patient-link{ color:var(--navy); text-decoration:none; font-weight:600; }
    .patient-link:hover{ color:var(--orange); text-decoration:underline; }
    .cell-sub{ color:var(--navy-55); font-size:11.5px; }

    .status-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); white-space:nowrap; }
    .status-badge.warn{ border-color:var(--orange); color:var(--orange); }
    .status-badge.danger{ border-color:#c0392b; color:#c0392b; }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .row-actions form{ display:inline; }
    .btn-sm{ padding:6px 12px; font-size:12px; font-weight:600; border:2px solid var(--navy); cursor:pointer; background:var(--white); color:var(--navy); text-decoration:none; display:inline-flex; align-items:center; }
    .btn-sm:hover{ background:var(--orange); border-color:var(--orange); color:var(--white); }
    .btn-sm.danger{ border-color:#c0392b; color:#c0392b; }
    .btn-sm.danger:hover{ background:#c0392b; border-color:#c0392b; color:var(--white); }

    .combo{ position:relative; }
    .combo-list{ position:absolute; top:calc(100% + 4px); left:0; right:0; max-height:220px; overflow-y:auto; background:var(--white); border:2px solid var(--navy); list-style:none; z-index:50; }
    .combo-list li{ padding:9px 12px; font-size:13px; cursor:pointer; }
    .combo-list li:hover, .combo-list li.active{ background:var(--navy-10); color:var(--orange); }
    .combo-empty{ padding:9px 12px; font-size:12.5px; color:var(--navy-55); }

    .modal-overlay{ position:fixed; inset:0; background:rgba(46,49,146,0.35); display:none; align-items:center; justify-content:center; z-index:1000; padding:20px; }
    .modal-overlay.show{ display:flex; }
    .modal-box{ background:var(--white); border:2px solid var(--navy); width:100%; max-width:560px; max-height:90vh; overflow-y:auto; }
    .modal-head{ display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:2px solid var(--navy); }
    .modal-head h3{ font-size:16px; font-weight:700; color:var(--navy); }
    .modal-close{ appearance:none; background:none; border:none; cursor:pointer; color:var(--navy-55); width:26px; height:26px; }
    .modal-close:hover{ color:var(--orange); }
    .modal-close svg{ width:100%; height:100%; }
    .modal-body{ padding:22px; }
    .modal-body .form-group{ margin-bottom:16px; }
    .modal-hint{ font-size:11.5px; color:var(--navy-55); margin-top:4px; }
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

    <div class="welcome-eyebrow">Diagnostics</div>
    <div class="welcome-title">Laboratory Bills</div>
    <div class="welcome-sub">Order tests for a patient and track results &amp; payment status.</div>

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

    <div class="section-toolbar">
        <form method="GET" action="laboratory.php" class="filter-box">
            <input type="text" name="q" placeholder="Search by patient, phone, or test..." value="<?= tdc_e($search) ?>">
            <select name="result" onchange="this.form.submit()">
                <option value="">All Results</option>
                <?php foreach (LAB_RESULT_OPTIONS as $v => $l): ?>
                    <option value="<?= tdc_e($v) ?>" <?= $resultFilter === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="payment" onchange="this.form.submit()">
                <option value="">All Payment Statuses</option>
                <?php foreach (PAYMENT_STATUS_OPTIONS as $v => $l): ?>
                    <option value="<?= tdc_e($v) ?>" <?= $paymentFilter === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">Search</button>
            <?php if ($hasActiveFilters): ?>
                <a href="laboratory.php" class="clear-filters">Clear filters</a>
            <?php endif; ?>
        </form>
        <?php if ($canManage): ?>
        <button type="button" id="addLabBtn" class="btn btn-primary">+ New Lab Bill</button>
        <?php endif; ?>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill ID</th><th>Patient</th><th>Test</th><th>Price</th>
                    <th>Availability</th><th>Result</th><th>Payment</th><th>Ordered</th>
                    <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($labBills)): ?>
                <tr class="empty-row">
                    <td colspan="<?= $canManage ? 9 : 8 ?>">
                        No laboratory bills found<?= $hasActiveFilters ? ' for the current filters' : '' ?>.
                        <?= $canManage && !$hasActiveFilters ? ' Click "New Lab Bill" to add one.' : '' ?>
                    </td>
                </tr>
                <?php else: foreach ($labBills as $l): ?>
                <tr>
                    <td><?= tdc_e($l['LaboratoryID']) ?></td>
                    <td>
                        <a class="patient-link" href="patients.php?view=<?= (int) $l['PatientID'] ?>"><?= tdc_e($l['PatientName']) ?></a><br>
                        <span class="cell-sub"><?= tdc_e((string) $l['PatientPhone']) ?></span>
                    </td>
                    <td><?= tdc_e($l['TestName']) ?></td>
                    <td><?= number_format((float) $l['Price'], 2) ?></td>
                    <td><span class="status-badge<?= (int) $l['IsAvailable'] === 1 ? '' : ' warn' ?>"><?= (int) $l['IsAvailable'] === 1 ? 'In-House' : 'Sent Out' ?></span></td>
                    <td><span class="status-badge<?= $l['Result'] === 'Positive' ? ' danger' : ($l['Result'] === 'Pending' ? ' warn' : '') ?>"><?= tdc_e($l['Result']) ?></span></td>
                    <td><span class="status-badge<?= $l['PaymentStatus'] === 'Unpaid' ? ' danger' : ($l['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($l['PaymentStatus']) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $l['OrderDate']))) ?></td>
                    <?php if ($canManage): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn-sm edit-lab-btn"
                                data-id="<?= tdc_e($l['LaboratoryID']) ?>"
                                data-patientid="<?= (int) $l['PatientID'] ?>"
                                data-patientlabel="<?= tdc_e($l['PatientName'] . ($l['PatientPhone'] ? ' — ' . $l['PatientPhone'] : '')) ?>"
                                data-testname="<?= tdc_e($l['TestName']) ?>"
                                data-description="<?= tdc_e((string) $l['Description']) ?>"
                                data-price="<?= tdc_e((string) $l['Price']) ?>"
                                data-isavailable="<?= (int) $l['IsAvailable'] ?>"
                                data-result="<?= tdc_e($l['Result']) ?>"
                                data-resultdate="<?= tdc_e($l['ResultDate'] ? date('Y-m-d\TH:i', strtotime((string) $l['ResultDate'])) : '') ?>"
                                data-paymentstatus="<?= tdc_e($l['PaymentStatus']) ?>">Edit</button>
                            <form method="POST" action="laboratory.php" onsubmit="return confirm('Delete this lab bill? This cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>">
                                <button type="submit" class="btn-sm danger">Delete</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canManage): ?>
    <div class="modal-overlay" id="labModalOverlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="labModalTitle">New Lab Bill</h3>
                <button type="button" class="modal-close" id="labModalCloseBtn" aria-label="Close">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <form id="labForm" method="POST" action="laboratory.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="LaboratoryID" id="lf_LaboratoryID" value="">

                    <div class="form-group">
                        <label for="lf_PatientSearch">Patient</label>
                        <div class="combo" data-combo>
                            <input type="hidden" name="PatientID" id="lf_PatientID" required>
                            <input type="hidden" name="PatientLabel" id="lf_PatientLabel">
                            <input type="text" class="combo-input" id="lf_PatientSearch" placeholder="Search patient by name or phone..." autocomplete="off" required>
                            <ul class="combo-list" id="lf_PatientList" hidden></ul>
                        </div>
                    </div>

                    <div class="form-group"><label for="lf_TestName">Test Name</label>
                        <input type="text" id="lf_TestName" name="TestName" placeholder="e.g. Skin Biopsy" required></div>

                    <div class="form-group"><label for="lf_Description">Description</label>
                        <textarea id="lf_Description" name="Description" placeholder="Optional notes"></textarea></div>

                    <div class="form-row">
                        <div class="form-group"><label for="lf_Price">Price</label>
                            <input type="number" step="0.01" min="0" id="lf_Price" name="Price" required></div>
                        <div class="form-group"><label for="lf_PaymentStatus">Payment Status</label>
                            <select id="lf_PaymentStatus" name="PaymentStatus" required>
                                <?php foreach (PAYMENT_STATUS_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($l) ?></option><?php endforeach; ?>
                            </select></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group"><label for="lf_Result">Result</label>
                            <select id="lf_Result" name="Result">
                                <?php foreach (LAB_RESULT_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($l) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label for="lf_ResultDate">Result Date</label>
                            <input type="datetime-local" id="lf_ResultDate" name="ResultDate"></div>
                    </div>

                    <div class="form-group checkbox-row">
                        <input type="checkbox" id="lf_IsAvailable" name="IsAvailable" value="1" checked>
                        <label for="lf_IsAvailable" style="margin:0;text-transform:none;letter-spacing:normal;font-weight:500;">Test is currently available in-house</label>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="labModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Bill</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
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

<?php if ($justSaved || $justDeleted): ?>
showToast(<?= $justSaved ? json_encode('Lab bill saved successfully.') : json_encode('Lab bill deleted successfully.') ?>);
if (window.history.replaceState) {
    const params = new URLSearchParams(window.location.search);
    params.delete('success');
    params.delete('deleted');
    const qs = params.toString();
    window.history.replaceState({}, document.title, 'laboratory.php' + (qs ? '?' + qs : ''));
}
<?php endif; ?>

<?php if ($canManage): ?>
/**
 * Lightweight, dependency-free search combobox: filters a small
 * in-memory patient list as the user types, with click + keyboard
 * (Up/Down/Enter/Escape) selection. Mirrors the pattern already used
 * on reception.php's Laboratory and Pharmacy forms.
 */
function initPatientCombobox(hiddenIdInput, hiddenLabelInput, searchInput, listEl, patients) {
    let activeIndex = -1;
    let currentMatches = [];

    function render(matches) {
        currentMatches = matches;
        activeIndex = -1;
        listEl.innerHTML = '';
        if (matches.length === 0) {
            const li = document.createElement('li');
            li.className = 'combo-empty';
            li.textContent = 'No matching patients.';
            listEl.appendChild(li);
        } else {
            matches.slice(0, 30).forEach(function(p) {
                const li = document.createElement('li');
                li.textContent = p.label;
                li.dataset.id = p.id;
                li.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    select(p);
                });
                listEl.appendChild(li);
            });
        }
        listEl.hidden = false;
    }

    function select(p) {
        hiddenIdInput.value = p.id;
        hiddenLabelInput.value = p.label;
        searchInput.value = p.label;
        listEl.hidden = true;
    }

    function highlight(delta) {
        const liList = Array.from(listEl.querySelectorAll('li[data-id]'));
        if (liList.length === 0) return;
        activeIndex = (activeIndex + delta + liList.length) % liList.length;
        liList.forEach(function(li, i){ li.classList.toggle('active', i === activeIndex); });
        liList[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    searchInput.addEventListener('input', function() {
        hiddenIdInput.value = '';
        const term = searchInput.value.trim().toLowerCase();
        if (term === '') { listEl.hidden = true; return; }
        render(patients.filter(function(p){ return p.label.toLowerCase().indexOf(term) !== -1; }));
    });

    searchInput.addEventListener('focus', function() {
        if (searchInput.value.trim() !== '' && hiddenIdInput.value === '') {
            render(patients.filter(function(p){ return p.label.toLowerCase().indexOf(searchInput.value.trim().toLowerCase()) !== -1; }));
        }
    });

    searchInput.addEventListener('keydown', function(e) {
        if (listEl.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); highlight(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(-1); }
        else if (e.key === 'Enter') {
            if (activeIndex >= 0 && currentMatches[activeIndex]) { e.preventDefault(); select(currentMatches[activeIndex]); }
        } else if (e.key === 'Escape') { listEl.hidden = true; }
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !listEl.contains(e.target)) { listEl.hidden = true; }
    });
}

(function(){
    const patients = <?= json_encode($patientOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const overlay = document.getElementById('labModalOverlay');
    const modalTitle = document.getElementById('labModalTitle');
    const form = document.getElementById('labForm');
    const fId = document.getElementById('lf_LaboratoryID');
    const fPatientId = document.getElementById('lf_PatientID');
    const fPatientLabel = document.getElementById('lf_PatientLabel');
    const fPatientSearch = document.getElementById('lf_PatientSearch');
    const fPatientList = document.getElementById('lf_PatientList');
    const fTestName = document.getElementById('lf_TestName');
    const fDescription = document.getElementById('lf_Description');
    const fPrice = document.getElementById('lf_Price');
    const fIsAvailable = document.getElementById('lf_IsAvailable');
    const fResult = document.getElementById('lf_Result');
    const fResultDate = document.getElementById('lf_ResultDate');
    const fPaymentStatus = document.getElementById('lf_PaymentStatus');

    initPatientCombobox(fPatientId, fPatientLabel, fPatientSearch, fPatientList, patients);

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.getElementById('addLabBtn').addEventListener('click', function(){
        form.reset();
        fId.value = ''; fPatientId.value = ''; fPatientLabel.value = ''; fPatientSearch.value = '';
        fIsAvailable.checked = true;
        modalTitle.textContent = 'New Lab Bill';
        openModal();
    });

    document.querySelectorAll('.edit-lab-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fPatientId.value = btn.dataset.patientid;
            fPatientLabel.value = btn.dataset.patientlabel;
            fPatientSearch.value = btn.dataset.patientlabel;
            fTestName.value = btn.dataset.testname;
            fDescription.value = btn.dataset.description;
            fPrice.value = btn.dataset.price;
            fIsAvailable.checked = btn.dataset.isavailable === '1';
            fResult.value = btn.dataset.result;
            fResultDate.value = btn.dataset.resultdate;
            fPaymentStatus.value = btn.dataset.paymentstatus;
            modalTitle.textContent = 'Edit Lab Bill';
            openModal();
        });
    });

    document.getElementById('labModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('labModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') === 'save'): ?>
    fId.value = <?= json_encode($old['LaboratoryID']) ?>;
    fPatientId.value = <?= json_encode($old['PatientID']) ?>;
    fPatientLabel.value = <?= json_encode($old['PatientLabel']) ?>;
    fPatientSearch.value = <?= json_encode($old['PatientLabel']) ?>;
    fTestName.value = <?= json_encode($old['TestName']) ?>;
    fDescription.value = <?= json_encode($old['Description']) ?>;
    fPrice.value = <?= json_encode($old['Price']) ?>;
    fIsAvailable.checked = <?= json_encode($old['IsAvailable'] === '1') ?>;
    fResult.value = <?= json_encode($old['Result']) ?>;
    fResultDate.value = <?= json_encode($old['ResultDate']) ?>;
    fPaymentStatus.value = <?= json_encode($old['PaymentStatus']) ?>;
    modalTitle.textContent = fId.value ? 'Edit Lab Bill' : 'New Lab Bill';
    openModal();
    <?php endif; ?>
})();
<?php endif; ?>
</script>

</body>
</html>