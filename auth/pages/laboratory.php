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
require_once __DIR__ . '/../includes/access.php';
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_MANAGE_ROLES = ['superuser', 'labuser'];

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

/** @param array{PatientID:string,TestName:string,Description:string,Price:string,AmountPaid:string,IsAvailable:string,Result:string,ResultDate:string,PaymentStatus:string} $input */
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
    $paid = is_numeric($input['AmountPaid'] ?? null) ? (float) $input['AmountPaid'] : -1;
    $total = is_numeric($input['Price']) ? (float) $input['Price'] : 0;
    if ($paid < 0 || $paid > $total) {
        $errors[] = 'Amount paid must be between zero and the laboratory fee.';
    } elseif ($input['PaymentStatus'] !== tdc_workflow_payment_status($total, $paid)) {
        $errors[] = 'Payment status must match the amount collected.';
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
    $total = round((float) $input['Price'], 2);
    $paid = round((float) $input['AmountPaid'], 2);
    $workflow = $input['PaymentStatus'] === 'Paid' ? 'Ready' : 'Awaiting Payment';
    $previousPaid = 0.0;
    $previousStatus = 'Unpaid';
    $previousWorkflow = '';
    if ($isEdit) {
        $stmt = $pdo->prepare('SELECT AmountPaid,PaymentStatus,WorkflowStatus FROM Laboratory WHERE LaboratoryID=?');
        $stmt->execute([$editId]);
        $before = $stmt->fetch();
        if (!$before) throw new RuntimeException('Laboratory order no longer exists.');
        $previousPaid = (float) $before['AmountPaid'];
        $previousStatus = (string) $before['PaymentStatus'];
        $previousWorkflow = (string) $before['WorkflowStatus'];
        if ($paid < $previousPaid) throw new RuntimeException('Recorded payments cannot be reduced.');
    }
    if (in_array($previousWorkflow, ['In Progress','Completed','Cancelled'], true)) {
        $workflow = $previousWorkflow;
    }
    $params = [
        'PatientID'     => (int) $input['PatientID'],
        'TestName'      => $input['TestName'],
        'Description'   => $input['Description'] !== '' ? $input['Description'] : null,
        'Price'         => round((float) $input['Price'], 2),
        'AmountPaid'    => $paid,
        'DueBalance'    => max(0, $total - $paid),
        'WorkflowStatus'=> $workflow,
        'IsAvailable'   => ($input['IsAvailable'] ?? '') === '1' ? 1 : 0,
        'Result'        => $input['Result'] !== '' ? $input['Result'] : 'Pending',
        'ResultDate'    => $input['ResultDate'] !== '' ? str_replace('T', ' ', $input['ResultDate']) : null,
        'PaymentStatus' => $input['PaymentStatus'],
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE Laboratory SET PatientID = :PatientID, TestName = :TestName,
                Description = :Description, TotalAmount = :Price, AmountPaid = :AmountPaid,
                DueBalance = :DueBalance, IsAvailable = :IsAvailable, Result = :Result,
                ResultDate = :ResultDate, PaymentStatus = :PaymentStatus, WorkflowStatus = :WorkflowStatus
             WHERE LaboratoryID = :id'
        );
        $stmt->execute($params);
    } else {
        $params['LaboratoryID'] = tdc_next_ref($pdo, 'Laboratory', 'LaboratoryID', 'LAB');
        $params['TestID']       = (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(TestID), 0) + 1 FROM Laboratory') ?: 1;

        $stmt = $pdo->prepare(
            'INSERT INTO Laboratory (LaboratoryID, PatientID, TestID, TestName, Description,
                TotalAmount, AmountPaid, DueBalance, IsAvailable, Result, ResultDate, PaymentStatus, WorkflowStatus)
             VALUES (:LaboratoryID, :PatientID, :TestID, :TestName, :Description,
                :Price, :AmountPaid, :DueBalance, :IsAvailable, :Result, :ResultDate, :PaymentStatus, :WorkflowStatus)'
        );
        $stmt->execute($params);
    }
    $labId = $isEdit ? $editId : $params['LaboratoryID'];
    $collection = max(0, $paid - $previousPaid);
    if ($collection > 0) {
        $payRef = tdc_workflow_record_payment($pdo,(int)$input['PatientID'],'Laboratory',$collection,(int)$_SESSION['user_id'],['LaboratoryID'=>$labId]);
        if ($payRef) tdc_workflow_post_revenue($pdo,'REV-LAB','Laboratory Revenue',$payRef,'Laboratory payment for '.$labId,$collection);
    }
    if ($previousStatus !== 'Paid' && $input['PaymentStatus'] === 'Paid') {
        tdc_workflow_notify($pdo,null,'labuser','lab_ready','Paid laboratory request ready',$labId.' is cleared for processing','laboratory.php?result=Pending&payment=Paid');
    }
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
require_once __DIR__ . '/../includes/workflow.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canManage = in_array($_SESSION['role'] ?? '', ALLOWED_MANAGE_ROLES, true);
$isLabStaff = $_SESSION['role'] === 'labuser';
$isSuperAdmin = $_SESSION['role'] === 'superuser';
require_once __DIR__ . '/../includes/lab-results.php';

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$errors = [];
$old = [
    'LaboratoryID' => '', 'PatientID' => '', 'PatientLabel' => '', 'TestName' => '',
    'Description' => '', 'Price' => '', 'IsAvailable' => '1', 'Result' => 'Pending',
    'ResultDate' => '', 'AmountPaid' => '0', 'PaymentStatus' => 'Unpaid',
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

        if ($formAction === 'save_service') {
            if (!$isSuperAdmin) tdc_forbidden();
            $serviceId = ctype_digit((string)($_POST['ServiceID'] ?? '')) ? (int)$_POST['ServiceID'] : 0;
            $serviceName = trim((string)($_POST['ServiceName'] ?? ''));
            $category = trim((string)($_POST['Category'] ?? ''));
            $serviceDescription = trim((string)($_POST['ServiceDescription'] ?? ''));
            $price = trim((string)($_POST['ServicePrice'] ?? ''));
            if ($serviceName === '' || mb_strlen($serviceName) > 150) $errors[] = 'Service name is required (max 150 characters).';
            if ($category !== '' && mb_strlen($category) > 100) $errors[] = 'Service category must be 100 characters or fewer.';
            if (mb_strlen($serviceDescription) > 500) $errors[] = 'Service description must be 500 characters or fewer.';
            if ($price === '' || !is_numeric($price) || (float)$price < 0) $errors[] = 'Service price must be a valid non-negative number.';
            if (!$errors) {
                try {
                    if ($serviceId) {
                        $stmt=$pdo->prepare('UPDATE LabServices SET ServiceName=?,Category=?,Description=?,Price=?,IsAvailable=?,IsActive=? WHERE ServiceID=?');
                        $stmt->execute([$serviceName,$category?:null,$serviceDescription?:null,round((float)$price,2),isset($_POST['IsAvailable'])?1:0,isset($_POST['IsActive'])?1:0,$serviceId]);
                    } else {
                        $stmt=$pdo->prepare('INSERT INTO LabServices (ServiceName,Category,Description,Price,IsAvailable,IsActive) VALUES (?,?,?,?,1,1)');
                        $stmt->execute([$serviceName,$category?:null,$serviceDescription?:null,round((float)$price,2)]);
                    }
                    header('Location: laboratory.php?service_saved=1'); exit;
                } catch (PDOException $e) {
                    $errors[] = $e->getCode()==='23000' ? 'A laboratory service with this name already exists.' : 'The laboratory service could not be saved.';
                }
            }
        } elseif ($isLabStaff) {
            if (!in_array($formAction, ['save','start'], true)) {
                tdc_forbidden();
            }
            $errors = $formAction === 'start'
                ? tdc_start_lab_order($pdo, trim((string)($_POST['LaboratoryID'] ?? '')))
                : tdc_record_lab_result($pdo, $_POST);
            if (!$errors) tdc_redirect('success');
        } else {
            tdc_forbidden();
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
    $conditions[] = '(p.PatientName LIKE :q1 OR p.PatientPhone LIKE :q2 OR l.TestName LIKE :q3)';
    $params['q1'] = $params['q2'] = $params['q3']  = '%' . $search . '%';
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
$labItemsByOrder = [];
if ($labBills !== []) {
    $orderIds = array_column($labBills, 'LaboratoryID');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT LabOrderItemID,LaboratoryID,TestName,UnitPrice,Result,ClinicalResult FROM LabOrderItems WHERE LaboratoryID IN ($placeholders) ORDER BY LabOrderItemID");
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) $labItemsByOrder[$item['LaboratoryID']][] = $item;
}
$labServices = $isSuperAdmin ? $pdo->query('SELECT * FROM LabServices ORDER BY IsActive DESC,Category,ServiceName')->fetchAll() : [];

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
    .data-table input:not([type="checkbox"]):not([type="hidden"]){ width:100%; min-width:130px; padding:9px 10px; border:1px solid var(--navy-30); border-radius:4px; background:var(--white); color:var(--navy); font:inherit; }
    .data-table input:not([type="checkbox"]):not([type="hidden"]):focus{ outline:none; border-color:var(--navy); box-shadow:0 0 0 3px var(--navy-10); }
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

    <div class="welcome-eyebrow">Diagnostics</div>
    <div class="welcome-title">Laboratory Work Queue</div>
    <div class="welcome-sub">Process paid doctor requests and record clinical results.</div>

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

    <?php if ($isSuperAdmin): ?>
    <div class="subsection-title">Laboratory Service Catalogue</div>
    <div class="data-table-wrap" style="margin-bottom:20px"><table class="data-table"><thead><tr><th>Service</th><th>Category</th><th>Description</th><th>Price</th><th>Available</th><th>Active</th><th>Save</th></tr></thead><tbody>
    <?php foreach($labServices as $service): $serviceForm='service-form-'.(int)$service['ServiceID']; ?><tr><td><form id="<?= $serviceForm ?>" method="POST" action="laboratory.php"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="save_service"><input type="hidden" name="ServiceID" value="<?= (int)$service['ServiceID'] ?>"></form><input form="<?= $serviceForm ?>" name="ServiceName" value="<?= tdc_e($service['ServiceName']) ?>" required></td><td><input form="<?= $serviceForm ?>" name="Category" value="<?= tdc_e((string)$service['Category']) ?>"></td><td><input form="<?= $serviceForm ?>" name="ServiceDescription" value="<?= tdc_e((string)$service['Description']) ?>"></td><td><input form="<?= $serviceForm ?>" type="number" min="0" step=".01" name="ServicePrice" value="<?= tdc_e((string)$service['Price']) ?>" required></td><td><input form="<?= $serviceForm ?>" type="checkbox" name="IsAvailable" value="1" <?= $service['IsAvailable']?'checked':'' ?> aria-label="Service available"></td><td><input form="<?= $serviceForm ?>" type="checkbox" name="IsActive" value="1" <?= $service['IsActive']?'checked':'' ?> aria-label="Service active"></td><td><button form="<?= $serviceForm ?>" class="btn-sm">Save</button></td></tr><?php endforeach; ?>
    <tr><td><form id="new-service-form" method="POST" action="laboratory.php"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="save_service"></form><input form="new-service-form" name="ServiceName" placeholder="New service" required></td><td><input form="new-service-form" name="Category" placeholder="Category"></td><td><input form="new-service-form" name="ServiceDescription" placeholder="Description"></td><td><input form="new-service-form" type="number" min="0" step=".01" name="ServicePrice" placeholder="0.00" required></td><td><span class="status-badge">Available</span></td><td><span class="status-badge">Active</span></td><td><button form="new-service-form" class="btn-sm">Add</button></td></tr>
    </tbody></table></div>
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
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Bill ID</th><th>Patient</th><th>Test</th><th>Price</th>
                    <th>Availability</th><th>Result</th><th>Workflow</th><th>Payment</th><th>Ordered</th>
                    <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($labBills)): ?>
                <tr class="empty-row">
                    <td colspan="<?= $canManage ? 10 : 9 ?>">
                        No laboratory bills found<?= $hasActiveFilters ? ' for the current filters' : '' ?>.
                        <?= $isLabStaff ? ' Doctor requests will appear here, including orders awaiting payment.' : '' ?>
                    </td>
                </tr>
                <?php else: foreach ($labBills as $l): ?>
                <tr>
                    <td><?= tdc_e($l['LaboratoryID']) ?></td>
                    <td>
                        <?php if ($isLabStaff): ?><?= tdc_e($l['PatientName']) ?><?php else: ?><a class="patient-link" href="patients.php?view=<?= (int) $l['PatientID'] ?>"><?= tdc_e($l['PatientName']) ?></a><?php endif; ?><br>
                        <span class="cell-sub"><?= tdc_e((string) $l['PatientPhone']) ?></span>
                    </td>
                    <td><?= tdc_e($l['TestName']) ?></td>
                    <td><?= number_format((float) $l['TotalAmount'], 2) ?></td>
                    <td><span class="status-badge<?= (int) $l['IsAvailable'] === 1 ? '' : ' warn' ?>"><?= (int) $l['IsAvailable'] === 1 ? 'In-House' : 'Sent Out' ?></span></td>
                    <td><span class="status-badge<?= $l['Result'] === 'Positive' ? ' danger' : ($l['Result'] === 'Pending' ? ' warn' : '') ?>"><?= tdc_e($l['Result']) ?></span></td>
                    <td><span class="status-badge"><?= tdc_e($l['WorkflowStatus']) ?></span></td>
                    <td><span class="status-badge<?= $l['PaymentStatus'] === 'Unpaid' ? ' danger' : ($l['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($l['PaymentStatus']) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $l['OrderDate']))) ?></td>
                    <?php if ($canManage): ?>
                    <td>
                        <div class="row-actions">
                            <?php if ($isLabStaff && $l['WorkflowStatus'] === 'In Progress'): ?><button type="button" class="btn-sm edit-lab-btn"
                                data-id="<?= tdc_e($l['LaboratoryID']) ?>"
                                data-patientid="<?= (int) $l['PatientID'] ?>"
                                data-patientlabel="<?= tdc_e($l['PatientName'] . ($l['PatientPhone'] ? ' — ' . $l['PatientPhone'] : '')) ?>"
                                data-testname="<?= tdc_e($l['TestName']) ?>"
                                data-description="<?= tdc_e((string) ($isLabStaff ? $l['ClinicalResult'] : $l['Description'])) ?>"
                                data-price="<?= tdc_e((string) $l['TotalAmount']) ?>"
                                data-amountpaid="<?= tdc_e((string) $l['AmountPaid']) ?>"
                                data-isavailable="<?= (int) $l['IsAvailable'] ?>"
                                data-result="<?= tdc_e($l['Result']) ?>"
                                data-resultdate="<?= tdc_e($l['ResultDate'] ? date('Y-m-d\TH:i', strtotime((string) $l['ResultDate'])) : '') ?>"
                                data-paymentstatus="<?= tdc_e($l['PaymentStatus']) ?>"
                                data-items="<?= tdc_e(json_encode($labItemsByOrder[$l['LaboratoryID']] ?? [])) ?>">Record results</button><?php endif; ?>
                            <?php if ($isLabStaff && $l['WorkflowStatus'] === 'Ready'): ?><form method="POST" action="laboratory.php"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="start"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><button type="submit" class="btn-sm">Start Test</button></form><?php endif; ?>
                            <?php if ($isLabStaff && $l['WorkflowStatus'] === 'Awaiting Payment'): ?><span class="status-badge danger">Payment Locked</span><?php endif; ?>
                            <?php if ($isLabStaff && $l['WorkflowStatus'] === 'Completed'): ?><span class="status-badge">View Result</span><?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isLabStaff): ?>
    <div class="modal-overlay" id="labModalOverlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="labModalTitle">Record Laboratory Results</h3>
                <button type="button" class="modal-close" id="labModalCloseBtn" aria-label="Close">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <form id="labForm" method="POST" action="laboratory.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="LaboratoryID" id="lf_LaboratoryID" value="">

                    <div class="form-row">
                        <div class="form-group"><label>Patient</label><input id="lf_PatientSearch" readonly></div>
                        <div class="form-group"><label>Order</label><input id="lf_OrderSummary" readonly></div>
                    </div>
                    <div id="lf_ItemResults"></div>
                    <div id="lf_LegacyResult" hidden>
                        <div class="form-group"><label for="lf_Result">Result</label><select id="lf_Result" name="Result"><?php foreach (LAB_RESULT_OPTIONS as $v => $label): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label for="lf_Description">Clinical Result</label><textarea id="lf_Description" name="Description" placeholder="Enter laboratory findings"></textarea></div>
                    </div>
                    <input type="hidden" name="IsAvailable" value="1">

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="labModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save results</button>
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
    const overlay = document.getElementById('labModalOverlay');
    const modalTitle = document.getElementById('labModalTitle');
    const form = document.getElementById('labForm');
    const fId = document.getElementById('lf_LaboratoryID');
    const fPatientSearch = document.getElementById('lf_PatientSearch');
    const fOrderSummary = document.getElementById('lf_OrderSummary');
    const fItemResults = document.getElementById('lf_ItemResults');
    const fLegacyResult = document.getElementById('lf_LegacyResult');
    const fDescription = document.getElementById('lf_Description');
    const fResult = document.getElementById('lf_Result');

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    function resultOptions(selected){
        return ['Pending','Positive','Negative'].map(value => '<option value="'+value+'"'+(value===selected?' selected':'')+'>'+value+'</option>').join('');
    }

    document.querySelectorAll('.edit-lab-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fPatientSearch.value = btn.dataset.patientlabel;
            fOrderSummary.value = btn.dataset.id + ' · ' + btn.dataset.testname;
            const items = JSON.parse(btn.dataset.items || '[]');
            fItemResults.innerHTML = '';
            fLegacyResult.hidden = items.length > 0;
            if (items.length) {
                items.forEach(function(item){
                    const section = document.createElement('section');
                    section.className = 'result-item';
                    const title = document.createElement('div');
                    title.className = 'subsection-title';
                    title.textContent = item.TestName;
                    const id = document.createElement('input');
                    id.type = 'hidden'; id.name = 'LabOrderItemID[]'; id.value = item.LabOrderItemID;
                    const row = document.createElement('div'); row.className = 'form-row';
                    const statusGroup = document.createElement('div'); statusGroup.className = 'form-group';
                    statusGroup.innerHTML = '<label>Result</label><select name="ItemResult[]" required>'+resultOptions(item.Result || 'Pending')+'</select>';
                    const notesGroup = document.createElement('div'); notesGroup.className = 'form-group';
                    const label = document.createElement('label'); label.textContent = 'Clinical Findings';
                    const notes = document.createElement('textarea'); notes.name = 'ItemClinicalResult[]'; notes.maxLength = 2000; notes.placeholder = 'Enter findings for this test'; notes.value = item.ClinicalResult || '';
                    notesGroup.append(label, notes); row.append(statusGroup, notesGroup); section.append(title, id, row); fItemResults.appendChild(section);
                });
            } else {
                fResult.value = btn.dataset.result;
                fDescription.value = btn.dataset.description;
            }
            modalTitle.textContent = 'Record Laboratory Results';
            openModal();
        });
    });

    document.getElementById('labModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('labModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

})();
<?php endif; ?>
</script>

</body>
</html>
