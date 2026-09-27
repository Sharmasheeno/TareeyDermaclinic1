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
require_once __DIR__ . '/../includes/ui.php';

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
        'href'  => 'laboratory.php?workspace=1&lab_tab=test-result-entry',
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
        'href'  => 'setup.php',
        'label' => 'Setup',
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
    $statusFlags = ['request_saved', 'sample_started', 'result_saved', 'result_completed', 'deleted'];
    $query = in_array($flag, $statusFlags, true) ? 'status=' . urlencode($flag) : $flag . '=1';
    header('Location: laboratory.php?' . $query);
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
 * e.g. tdc_next_ref($pdo, 'laboratory', 'LaboratoryID', 'LAB') -> "LAB000042".
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
    } elseif ((int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM patients WHERE PatientID = :id', ['id' => (int) $input['PatientID']]) === 0) {
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
        $stmt = $pdo->prepare('SELECT AmountPaid,PaymentStatus,WorkflowStatus FROM laboratory WHERE LaboratoryID=?');
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
            'UPDATE laboratory SET PatientID = :PatientID, TestName = :TestName,
                Description = :Description, TotalAmount = :Price, AmountPaid = :AmountPaid,
                DueBalance = :DueBalance, IsAvailable = :IsAvailable, Result = :Result,
                ResultDate = :ResultDate, PaymentStatus = :PaymentStatus, WorkflowStatus = :WorkflowStatus
             WHERE LaboratoryID = :id'
        );
        $stmt->execute($params);
    } else {
        $params['LaboratoryID'] = tdc_next_ref($pdo, 'laboratory', 'LaboratoryID', 'LAB');
        $params['TestID']       = (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(TestID), 0) + 1 FROM laboratory') ?: 1;

        $stmt = $pdo->prepare(
            'INSERT INTO laboratory (LaboratoryID, PatientID, TestID, TestName, Description,
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
    $stmt = $pdo->prepare('DELETE FROM laboratory WHERE LaboratoryID = :id');
    $stmt->execute(['id' => $id]);
}

function tdc_lab_calculate_flag(?float $value, ?float $normalMinimum, ?float $normalMaximum): string
{
    if (!is_numeric((string) $value)) {
        return 'NORMAL';
    }
    $num = (float) $value;
    if ($normalMinimum !== null && $num < $normalMinimum) {
        return 'LOW';
    }
    if ($normalMaximum !== null && $num > $normalMaximum) {
        return 'HIGH';
    }
    return 'NORMAL';
}

function tdc_lab_flag_by_code(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT FlagID, FlagName, FlagCode FROM lab_flags WHERE FlagCode = ? AND IsActive = 1 LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tdc_lab_bridge_rows(PDO $pdo, string $labOrderId): array
{
    $stmt = $pdo->prepare(
        'SELECT b.*, lt.TestName, lt.Price, lt.ResultMode FROM lab_order_catalog_bridge b '
        . 'LEFT JOIN lab_tests lt ON lt.TestID = b.ModernTestID '
        . 'WHERE b.LaboratoryID = ? AND b.IsActive = 1 ORDER BY b.DisplayOrder, b.BridgeID'
    );
    $stmt->execute([$labOrderId]);
    return $stmt->fetchAll();
}

function tdc_lab_parameters_for_test(PDO $pdo, int $testId): array
{
    $stmt = $pdo->prepare(
        'SELECT lp.*, lu.UnitName, lu.UnitSymbol FROM lab_parameters lp '
        . 'LEFT JOIN lab_units lu ON lu.UnitID = lp.UnitID '
        . 'WHERE lp.TestID = ? AND lp.IsActive = 1 ORDER BY lp.DisplayOrder, lp.ParameterID'
    );
    $stmt->execute([$testId]);
    return $stmt->fetchAll();
}

function tdc_lab_upsert_bridge_result(PDO $pdo, int $bridgeId, int $labCenterId = 0): int
{
    $stmt = $pdo->prepare('SELECT LabResultID FROM lab_results WHERE BridgeID = ? LIMIT 1');
    $stmt->execute([$bridgeId]);
    $labResultId = (int) $stmt->fetchColumn();
    if ($labResultId > 0) {
        return $labResultId;
    }

    $stmt = $pdo->prepare('INSERT INTO lab_results (BridgeID, LabCenterID, ResultStatus, CreatedAt, UpdatedAt) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->execute([$bridgeId, $labCenterId > 0 ? $labCenterId : null, 'Draft']);
    return (int) $pdo->lastInsertId();
}

function tdc_lab_parameter_form_value(array $payload, int $bridgeId, int $parameterId): string
{
    $bridgeKey = (string) $bridgeId;
    $paramKey = (string) $parameterId;
    if (isset($payload['ResultValue'][$bridgeKey][$paramKey])) {
        return trim((string) $payload['ResultValue'][$bridgeKey][$paramKey]);
    }
    if (isset($payload['ResultValue'][$bridgeKey])) {
        $map = $payload['ResultValue'][$bridgeKey];
        if (is_array($map) && array_key_exists($paramKey, $map)) {
            return trim((string) $map[$paramKey]);
        }
    }
    $flat = $payload['ParameterID'] ?? [];
    foreach ($flat as $index => $candidateId) {
        if ((int) $candidateId === $parameterId) {
            return trim((string) ($payload['ResultValue'][$index] ?? ''));
        }
    }
    return '';
}

function tdc_lab_parameter_form_remark(array $payload, int $bridgeId, int $parameterId): string
{
    $bridgeKey = (string) $bridgeId;
    $paramKey = (string) $parameterId;
    if (isset($payload['Remark'][$bridgeKey][$paramKey])) {
        return trim((string) $payload['Remark'][$bridgeKey][$paramKey]);
    }
    if (isset($payload['Remark'][$bridgeKey])) {
        $map = $payload['Remark'][$bridgeKey];
        if (is_array($map) && array_key_exists($paramKey, $map)) {
            return trim((string) $map[$paramKey]);
        }
    }
    $flat = $payload['ParameterID'] ?? [];
    foreach ($flat as $index => $candidateId) {
        if ((int) $candidateId === $parameterId) {
            return trim((string) ($payload['Remark'][$index] ?? ''));
        }
    }
    return '';
}

function tdc_lab_result_flag_for_parameter(PDO $pdo, string $resultType, string $rawValue, ?float $normalMin, ?float $normalMax): array
{
    $raw = trim($rawValue);
    if ($resultType === 'Numeric' && $raw !== '' && is_numeric($raw)) {
        $value = (float) $raw;
        $code = 'NORMAL';
        if ($normalMin !== null && $value < $normalMin) { $code = 'LOW'; }
        if ($normalMax !== null && $value > $normalMax) { $code = 'HIGH'; }
        $flag = tdc_lab_flag_by_code($pdo, $code === 'LOW' ? 'L' : ($code === 'HIGH' ? 'H' : 'N'));
        return ['FlagCode' => $flag['FlagCode'] ?? strtoupper(substr($code, 0, 1)), 'FlagName' => $flag['FlagName'] ?? $code, 'FlagID' => $flag['FlagID'] ?? null];
    }
    if ($resultType === 'Positive/Negative') {
        $normalized = strtoupper($raw);
        $flag = null;
        if (in_array($normalized, ['POS','POSITIVE','P'], true)) { $flag = tdc_lab_flag_by_code($pdo, 'POS'); }
        if (in_array($normalized, ['NEG','NEGATIVE','N'], true)) { $flag = tdc_lab_flag_by_code($pdo, 'NEG'); }
        if ($flag) {
            return ['FlagCode' => $flag['FlagCode'], 'FlagName' => $flag['FlagName'], 'FlagID' => $flag['FlagID']];
        }
    }
    $flag = tdc_lab_flag_by_code($pdo, 'N');
    return ['FlagCode' => $flag['FlagCode'] ?? 'N', 'FlagName' => $flag['FlagName'] ?? 'Normal', 'FlagID' => $flag['FlagID'] ?? null];
}

function tdc_lab_order_summary(PDO $pdo, string $labId): ?array
{
    if ($labId === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT l.*, p.PatientName, p.PatientPhone, p.Gender, p.Age, d.DoctorName, v.VisitID, v.VisitReference '
        . 'FROM laboratory l '
        . 'LEFT JOIN patients p ON p.PatientID = l.PatientID '
        . 'LEFT JOIN doctors d ON d.DoctorID = l.DoctorID '
        . 'LEFT JOIN visits v ON v.VisitID = l.VisitID '
        . 'WHERE EXISTS (SELECT 1 FROM lab_order_catalog_bridge b WHERE b.LaboratoryID=l.LaboratoryID AND b.IsActive=1) '
        . 'AND l.LaboratoryID = ? LIMIT 1'
    );
    $stmt->execute([$labId]);
    $order = $stmt->fetch();
    return $order ?: null;
}

function tdc_lab_order_permissions(PDO $pdo, string $labId, int $patientId, ?int $visitId): bool
{
    if ($labId === '') {
        return false;
    }
    $order = tdc_lab_order_summary($pdo, $labId);
    if (!$order) {
        return false;
    }
    if ((int) $order['PatientID'] !== $patientId) {
        return false;
    }
    if ($visitId !== null && $visitId > 0 && (int) ($order['VisitID'] ?? 0) !== $visitId) {
        return false;
    }
    return true;
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

$canProcess = tdc_can('laboratory.process');
$canEnterResult = tdc_can('laboratory.result.create');
$canManage = $canProcess || $canEnterResult;
$isLabStaff = $canManage;
$isSuperAdmin = tdc_can('setup.laboratory.manage');
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['workspace_action']) && empty($_POST['action'])) {

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
            // Service catalogue management belongs in Setup → Laboratory Services.
            // Redirect to setup without processing, preserving CSRF safety.
            tdc_require_permission('setup.laboratory.manage');
            header('Location: setup.php?section=laboratory');
            exit;
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
                        $stmt=$pdo->prepare('UPDATE labservices SET ServiceName=?,Category=?,Description=?,Price=?,IsAvailable=?,IsActive=? WHERE ServiceID=?');
                        $stmt->execute([$serviceName,$category?:null,$serviceDescription?:null,round((float)$price,2),isset($_POST['IsAvailable'])?1:0,isset($_POST['IsActive'])?1:0,$serviceId]);
                    } else {
                        $stmt=$pdo->prepare('INSERT INTO labservices (ServiceName,Category,Description,Price,IsAvailable,IsActive) VALUES (?,?,?,?,1,1)');
                        $stmt->execute([$serviceName,$category?:null,$serviceDescription?:null,round((float)$price,2)]);
                    }
                    header('Location: laboratory.php?service_saved=1'); exit;
                } catch (PDOException $e) {
                    $errors[] = $e->getCode()==='23000' ? 'A laboratory service with this name already exists.' : 'The laboratory service could not be saved.';
                }
            }
        } elseif (in_array($formAction, ['collect_sample','save_lab_result_draft','complete_lab_result'], true)) {
            tdc_require_permission($formAction === 'collect_sample' ? 'laboratory.process' : 'laboratory.result.create');
            $labId = trim((string) ($_POST['LaboratoryID'] ?? ''));
            $errors = (($_POST['result_mode'] ?? 'edit') === 'view') ? ['Read-only result views cannot modify laboratory results.'] : tdc_save_modern_lab_result($pdo, $labId, $formAction, $_POST, (int)($_SESSION['user_id'] ?? 0));
            if (!$errors) {
                header('Location: laboratory.php?workspace=1&lab_tab=test-result-entry&result=' . urlencode($labId) . '&success=1');
                exit;
            }
            if (!empty($_POST['modal_request'])) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($isLabStaff) {
            if (!in_array($formAction, ['save','start'], true)) {
                tdc_forbidden();
            }
            if ($formAction === 'save' && trim((string)($_POST['LaboratoryID'] ?? '')) === '') tdc_forbidden();
            tdc_require_permission($formAction === 'start' ? 'laboratory.process' : 'laboratory.result.create');
            $errors = $formAction === 'start' ? tdc_start_lab_order($pdo, trim((string)($_POST['LaboratoryID'] ?? ''))) : tdc_record_lab_result($pdo, $_POST);
            if (!$errors) tdc_redirect($formAction === 'start' ? 'sample_started' : ($formAction === 'complete_lab_result' ? 'result_completed' : 'result_saved'));
        } else {
            tdc_forbidden();
        }
    }

    // Keep the session CSRF token stable for other open authenticated forms.
}

// =======================================================================
// SECTION 10 — GET data loading
// =======================================================================

// --- 10A. Patient options for the search combobox (Add/Edit modal) -----
$stmt = $pdo->query('SELECT PatientID, PatientName, PatientPhone FROM patients ORDER BY PatientName ASC LIMIT 500');
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

$conditions = ["EXISTS (SELECT 1 FROM lab_order_catalog_bridge b WHERE b.LaboratoryID=l.LaboratoryID AND b.IsActive=1)"];
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
    "SELECT l.*, p.PatientName, p.PatientPhone FROM laboratory l
     JOIN patients p ON p.PatientID = l.PatientID
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
    $itemsStmt = $pdo->prepare("SELECT LabOrderItemID,LaboratoryID,TestName,UnitPrice,Result,ClinicalResult FROM laborderitems WHERE LaboratoryID IN ($placeholders) ORDER BY LabOrderItemID");
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $item) $labItemsByOrder[$item['LaboratoryID']][] = $item;
}

$hasActiveFilters = $search !== '' || $resultFilter !== '' || $paymentFilter !== '';

// --- 10C. Active services count for empty-state context ------------------
// Used by the empty state to guide lab staff and SuperAdmins correctly.
$activeLabServiceCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM labservices WHERE IsActive=1');

$labWorkspaceTab = trim((string) ($_GET['lab_tab'] ?? 'test-result-entry'));
$labWorkspaceTabs = [
    'test-result-entry' => 'Test Result Entry',
    'category-header' => 'Category Header',
    'lab-type' => 'Lab Type',
    'test-register' => 'Test Register',
    'lab-parameter' => 'Lab Parameter',
    'lab-selection' => 'Lab Selection',
    'lab-center' => 'Lab Center',
    'units' => 'Units',
    'flags' => 'Flags',
    'print-result' => 'Print Result',
];
$labSectionDescriptions = [
    'test-result-entry' => 'Modern provider work queue and result entry for active clinical orders.',
    'category-header' => 'Manage laboratory categories used to organize the diagnostic catalogue.',
    'lab-type' => 'Manage lab types that group related tests under each category.',
    'test-register' => 'Register and maintain the lab tests available for doctor ordering.',
    'lab-parameter' => 'Define the structured result parameters for each test.',
    'lab-selection' => 'Create and review which doctor/test/center combinations are available for ordering.',
    'lab-center' => 'Manage active lab centers and center-to-test availability mappings.',
    'units' => 'Maintain unit definitions used in lab parameter reference ranges.',
    'flags' => 'Manage abnormality and alert flags for interpretation results.',
    'print-result' => 'Review completed and reviewed results that are ready to print or reprint.',
];
if (!isset($labWorkspaceTabs[$labWorkspaceTab])) {
    $labWorkspaceTab = 'test-result-entry';
}

$labCategories = [];
$labTypes = [];
$labTests = [];
$labParameters = [];
$labUnits = [];
$labFlags = [];
$labCenters = [];
$labSelectionRows = [];
$labLegacyOrders = [];
$labOperationalQueue = [];
$labPrintQueue = [];
$labSearchTerm = trim((string) ($_GET['q'] ?? ''));

try {
    $labCategories = $pdo->query('SELECT * FROM lab_categories ORDER BY DisplayOrder, CategoryName')->fetchAll();
    $labTypes = $pdo->query('SELECT lt.*, lc.CategoryName FROM lab_types lt LEFT JOIN lab_categories lc ON lc.CategoryID = lt.CategoryID ORDER BY lt.DisplayOrder, lt.TypeName')->fetchAll();
    $labTests = $pdo->query('SELECT lt.*, lty.CategoryID, lc.CategoryName, lty.TypeName FROM lab_tests lt LEFT JOIN lab_types lty ON lty.TypeID = lt.TypeID LEFT JOIN lab_categories lc ON lc.CategoryID = lty.CategoryID ORDER BY lt.DisplayOrder, lt.TestName')->fetchAll();
    $labParameters = $pdo->query('SELECT lp.*, lt.TestName, lu.UnitName, lu.UnitSymbol FROM lab_parameters lp LEFT JOIN lab_tests lt ON lt.TestID = lp.TestID LEFT JOIN lab_units lu ON lu.UnitID = lp.UnitID ORDER BY lp.DisplayOrder, lp.ParameterName')->fetchAll();
    $labUnits = $pdo->query('SELECT * FROM lab_units ORDER BY UnitName')->fetchAll();
    $labFlags = $pdo->query('SELECT * FROM lab_flags ORDER BY FlagCode, FlagName')->fetchAll();
    $labCenters = $pdo->query('SELECT * FROM lab_centers ORDER BY CenterName')->fetchAll();
    $labSelectionRows = $pdo->query('SELECT ls.*, lt.TestName, lc.CategoryName, lty.TypeName, d.DoctorName, cc.CenterName FROM lab_test_selection ls LEFT JOIN lab_tests lt ON lt.TestID = ls.TestID LEFT JOIN lab_types lty ON lty.TypeID = lt.TypeID LEFT JOIN lab_categories lc ON lc.CategoryID = lty.CategoryID LEFT JOIN doctors d ON d.DoctorID = ls.DoctorID LEFT JOIN lab_centers cc ON cc.LabCenterID = ls.LabCenterID ORDER BY lc.CategoryName, lty.TypeName, lt.TestName, d.DoctorName, cc.CenterName')->fetchAll();
    $labLegacyOrders = $pdo->query(
        'SELECT l.LaboratoryID, l.OrderDate, l.PaymentStatus, l.WorkflowStatus, l.Result, p.PatientName, p.PatientPhone, p.Gender, p.DateOfBirth, d.DoctorName, l.TestName, l.TotalAmount '
        . 'FROM laboratory l LEFT JOIN patients p ON p.PatientID = l.PatientID LEFT JOIN doctors d ON d.DoctorID = l.DoctorID '
        . 'WHERE NOT EXISTS (SELECT 1 FROM lab_order_catalog_bridge b WHERE b.LaboratoryID = l.LaboratoryID AND b.IsActive = 1) '
        . 'ORDER BY l.OrderDate DESC LIMIT 100'
    )->fetchAll();
    $labOperationalQueue = $pdo->query(
        'SELECT l.*, p.PatientName, p.PatientPhone, p.Gender, p.Age, d.DoctorName, v.VisitReference '
        . 'FROM laboratory l '
        . 'LEFT JOIN patients p ON p.PatientID = l.PatientID '
        . 'LEFT JOIN doctors d ON d.DoctorID = l.DoctorID '
        . 'LEFT JOIN visits v ON v.VisitID = l.VisitID '
        . 'WHERE EXISTS (SELECT 1 FROM lab_order_catalog_bridge b WHERE b.LaboratoryID=l.LaboratoryID AND b.IsActive=1) '
        . 'ORDER BY l.OrderDate DESC LIMIT 200'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[LAB WORKSPACE DATA] ' . $e->getMessage());
}

$resultOrder = null;
$resultParameters = [];
$resultDetails = [];
$resultReference = trim((string)($_POST['LaboratoryID'] ?? $_GET['result'] ?? ''));
if ($resultReference !== '') {
    $stmt = $pdo->prepare('SELECT l.*,p.PatientName,d.DoctorName,v.VisitReference FROM laboratory l JOIN patients p ON p.PatientID=l.PatientID LEFT JOIN doctors d ON d.DoctorID=l.DoctorID LEFT JOIN visits v ON v.VisitID=l.VisitID WHERE l.LaboratoryID=?');
    $stmt->execute([$resultReference]);
    $resultOrder = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT b.ModernTestNameSnapshot,p.*,u.UnitName,rp.RawResult,rp.Remark,rp.FlagCodeSnapshot FROM lab_order_catalog_bridge b JOIN lab_parameters p ON p.TestID=b.ModernTestID AND p.IsActive=1 LEFT JOIN lab_units u ON u.UnitID=p.UnitID LEFT JOIN lab_results r ON r.BridgeID=b.BridgeID LEFT JOIN lab_result_parameters rp ON rp.LabResultID=r.LabResultID AND rp.ParameterID=p.ParameterID WHERE b.LaboratoryID=? AND b.IsActive=1 ORDER BY b.DisplayOrder,b.BridgeID,p.DisplayOrder,p.ParameterID');
    $stmt->execute([$resultReference]);
    $resultParameters = $stmt->fetchAll();
    $resultDetails = tdc_lab_result_details($pdo,$resultReference);
}

// The queue loads this same editor body asynchronously inside the shared modal.
if (isset($_GET['modal']) && $_GET['modal'] === '1' && $resultOrder) {
    $csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
    $modalResultMode = in_array((string)($_GET['result_mode'] ?? 'edit'), ['edit','view'], true) ? (string)$_GET['result_mode'] : 'edit';
    require __DIR__ . '/../includes/lab-result-modal.php';
    exit;
}

$labCategoriesFiltered = $labCategories;
$labTypesFiltered = $labTypes;
$labTestsFiltered = $labTests;
$labParametersFiltered = $labParameters;
$labUnitsFiltered = $labUnits;
$labFlagsFiltered = $labFlags;
$labCentersFiltered = $labCenters;
$labSelectionFiltered = $labSelectionRows;

if ($labSearchTerm !== '') {
    $needle = strtolower($labSearchTerm);
    $labCategoriesFiltered = array_values(array_filter($labCategories, static fn(array $row): bool => stripos((string) ($row['CategoryName'] ?? ''), $needle) !== false || stripos((string) ($row['Description'] ?? ''), $needle) !== false));
    $labTypesFiltered = array_values(array_filter($labTypes, static fn(array $row): bool => stripos((string) ($row['CategoryName'] ?? ''), $needle) !== false || stripos((string) ($row['TypeName'] ?? ''), $needle) !== false || stripos((string) ($row['Description'] ?? ''), $needle) !== false));
    $labTestsFiltered = array_values(array_filter($labTests, static fn(array $row): bool => stripos((string) ($row['TestName'] ?? ''), $needle) !== false || stripos((string) ($row['CategoryName'] ?? ''), $needle) !== false || stripos((string) ($row['TypeName'] ?? ''), $needle) !== false || stripos((string) ($row['Description'] ?? ''), $needle) !== false));
    $labParametersFiltered = array_values(array_filter($labParameters, static fn(array $row): bool => stripos((string) ($row['TestName'] ?? ''), $needle) !== false || stripos((string) ($row['ParameterName'] ?? ''), $needle) !== false || stripos((string) ($row['ResultType'] ?? ''), $needle) !== false));
    $labUnitsFiltered = array_values(array_filter($labUnits, static fn(array $row): bool => stripos((string) ($row['UnitName'] ?? ''), $needle) !== false || stripos((string) ($row['UnitSymbol'] ?? ''), $needle) !== false));
    $labFlagsFiltered = array_values(array_filter($labFlags, static fn(array $row): bool => stripos((string) ($row['FlagName'] ?? ''), $needle) !== false || stripos((string) ($row['FlagCode'] ?? ''), $needle) !== false || stripos((string) ($row['Description'] ?? ''), $needle) !== false));
    $labCentersFiltered = array_values(array_filter($labCenters, static fn(array $row): bool => stripos((string) ($row['CenterName'] ?? ''), $needle) !== false || stripos((string) ($row['Location'] ?? ''), $needle) !== false || stripos((string) ($row['Phone'] ?? ''), $needle) !== false));
    $labSelectionFiltered = array_values(array_filter($labSelectionRows, static fn(array $row): bool => stripos((string) ($row['TestName'] ?? ''), $needle) !== false || stripos((string) ($row['CategoryName'] ?? ''), $needle) !== false || stripos((string) ($row['TypeName'] ?? ''), $needle) !== false));
}

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'laboratory.php'));

$labStatus = (string) ($_GET['status'] ?? '');
$justSaved   = isset($_GET['success']) || isset($_GET['saved']) || in_array($labStatus, ['request_saved', 'sample_started', 'result_saved', 'result_completed'], true);
$justDeleted = isset($_GET['deleted']) || $labStatus === 'deleted';
$labToast = match ($labStatus) {
    'request_saved' => 'Laboratory request saved successfully.',
    'sample_started' => 'Laboratory sample started successfully.',
    'result_saved' => 'Laboratory result saved successfully.',
    'result_completed' => 'Laboratory result completed successfully.',
    'deleted' => 'Laboratory bill deleted successfully.',
    default => $justSaved ? 'Laboratory request submitted successfully.' : ($justDeleted ? 'Laboratory bill deleted successfully.' : ''),
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['workspace_action']) || !empty($_POST['action']))) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $rawWorkspaceAction = trim((string) ($_POST['workspace_action'] ?? $_POST['action'] ?? ''));
        $workspaceActionAliases = [
            'save_category' => 'lab_add_category',
            'save_lab_type' => 'lab_add_type',
            'save_test' => 'lab_add_test',
            'save_parameter' => 'lab_add_parameter',
            'save_unit' => 'lab_add_unit',
            'save_flag' => 'lab_add_flag',
            'save_center' => 'lab_add_center',
            'save_selection' => 'lab_add_selection',
            'save_center_test' => 'lab_add_center_test',
            'lab_add_category' => 'lab_add_category',
            'lab_update_category' => 'lab_update_category',
            'lab_add_type' => 'lab_add_type',
            'lab_update_type' => 'lab_update_type',
            'lab_add_test' => 'lab_add_test',
            'lab_update_test' => 'lab_update_test',
            'lab_add_parameter' => 'lab_add_parameter',
            'lab_update_parameter' => 'lab_update_parameter',
            'lab_add_unit' => 'lab_add_unit',
            'lab_update_unit' => 'lab_update_unit',
            'lab_add_flag' => 'lab_add_flag',
            'lab_update_flag' => 'lab_update_flag',
            'lab_add_center' => 'lab_add_center',
            'lab_update_center' => 'lab_update_center',
            'lab_add_selection' => 'lab_add_selection',
            'lab_update_selection' => 'lab_update_selection',
            'lab_add_center_test' => 'lab_add_center_test',
            'lab_update_center_test' => 'lab_update_center_test',
        ];
        $workspaceAction = $workspaceActionAliases[$rawWorkspaceAction] ?? $rawWorkspaceAction;
        $allowedWorkspaceActions = [
            'lab_add_category','lab_update_category','lab_add_type','lab_update_type','lab_add_test','lab_update_test','lab_add_parameter','lab_update_parameter','lab_add_unit','lab_update_unit','lab_add_flag','lab_update_flag','lab_add_center','lab_update_center','lab_add_selection','lab_update_selection','lab_add_center_test','lab_update_center_test','save_category','save_lab_type','save_test','save_parameter','save_unit','save_flag','save_center','save_selection','save_center_test'
        ];
        if (!in_array($workspaceAction, $allowedWorkspaceActions, true)) {
            $errors[] = 'Unsupported laboratory workspace action.';
        } else {
            try {
                switch ($workspaceAction) {
                    case 'lab_add_category':
                    case 'lab_update_category':
                    case 'save_category':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['CategoryID'] ?? 0);
                        $name = trim((string) ($_POST['CategoryName'] ?? ''));
                        $desc = trim((string) ($_POST['Description'] ?? ''));
                        $order = (int) ($_POST['DisplayOrder'] ?? 0);
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Category name is required and must be 120 characters or fewer.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_categories SET CategoryName=?, Description=?, DisplayOrder=?, IsActive=? WHERE CategoryID=?');
                            $stmt->execute([$name, $desc !== '' ? $desc : null, $order, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive) VALUES (?,?,?,?)');
                            $stmt->execute([$name, $desc !== '' ? $desc : null, $order, $active]);
                        }
                        break;
                    case 'lab_add_type':
                    case 'lab_update_type':
                    case 'save_lab_type':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['TypeID'] ?? 0);
                        $categoryId = (int) ($_POST['CategoryID'] ?? 0);
                        $name = trim((string) ($_POST['TypeName'] ?? ''));
                        $desc = trim((string) ($_POST['Description'] ?? ''));
                        $order = (int) ($_POST['DisplayOrder'] ?? 0);
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        if ($categoryId <= 0) throw new RuntimeException('Select a valid category.');
                        if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Lab type is required and must be 150 characters or fewer.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_types SET CategoryID=?, TypeName=?, Description=?, DisplayOrder=?, IsActive=? WHERE TypeID=?');
                            $stmt->execute([$categoryId, $name, $desc !== '' ? $desc : null, $order, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_types (CategoryID, TypeName, Description, DisplayOrder, IsActive) VALUES (?,?,?,?,?)');
                            $stmt->execute([$categoryId, $name, $desc !== '' ? $desc : null, $order, $active]);
                        }
                        break;
                    case 'lab_add_test':
                    case 'lab_update_test':
                    case 'save_test':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['TestID'] ?? 0);
                        $categoryId = (int) ($_POST['CategoryID'] ?? 0);
                        $typeId = (int) ($_POST['TypeID'] ?? 0);
                        $name = trim((string) ($_POST['TestName'] ?? ''));
                        $desc = trim((string) ($_POST['Description'] ?? ''));
                        $price = (float) ($_POST['Price'] ?? 0);
                        $mode = in_array((string) ($_POST['ResultMode'] ?? 'Structured Parameters'), ['Structured Parameters','Single Result'], true) ? (string) $_POST['ResultMode'] : 'Structured Parameters';
                        $order = (int) ($_POST['DisplayOrder'] ?? 0);
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        $typeCheck = $pdo->prepare('SELECT CategoryID FROM lab_types WHERE TypeID=? AND IsActive=1 LIMIT 1');
                        $typeCheck->execute([$typeId]);
                        if ($typeId <= 0 || (int) $typeCheck->fetchColumn() !== $categoryId) throw new RuntimeException('Select a valid category and lab type.');
                        if ($name === '' || mb_strlen($name) > 180) throw new RuntimeException('Test name is required and must be 180 characters or fewer.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_tests SET TypeID=?, TestName=?, Description=?, Price=?, ResultMode=?, DisplayOrder=?, IsActive=? WHERE TestID=?');
                            $stmt->execute([$typeId, $name, $desc !== '' ? $desc : null, round($price, 2), $mode, $order, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_tests (TypeID, TestName, Description, Price, ResultMode, DisplayOrder, IsActive) VALUES (?,?,?,?,?,?,?)');
                            $stmt->execute([$typeId, $name, $desc !== '' ? $desc : null, round($price, 2), $mode, $order, $active]);
                        }
                        break;
                    case 'lab_add_parameter':
                    case 'lab_update_parameter':
                    case 'save_parameter':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['ParameterID'] ?? 0);
                        $testId = (int) ($_POST['TestID'] ?? 0);
                        $name = trim((string) ($_POST['ParameterName'] ?? ''));
                        $resultType = in_array((string) ($_POST['ResultType'] ?? ''), ['Numeric','Text','Positive/Negative','Select'], true) ? (string) $_POST['ResultType'] : 'Numeric';
                        $unitId = (int) ($_POST['UnitID'] ?? 0);
                        $range = trim((string) ($_POST['ReferenceRange'] ?? ''));
                        $min = $_POST['NormalMinimum'] ?? null;
                        $max = $_POST['NormalMaximum'] ?? null;
                        $order = (int) ($_POST['DisplayOrder'] ?? 0);
                        $required = in_array((string)($_POST['IsRequired'] ?? ''), ['1','on'], true) ? 1 : 0;
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        $choices = trim((string) ($_POST['SelectChoices'] ?? ''));
                        if ($testId <= 0) throw new RuntimeException('Select the test that owns this parameter.');
                        if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Parameter name is required and must be 150 characters or fewer.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_parameters SET TestID=?, ParameterName=?, ResultType=?, UnitID=?, ReferenceRange=?, NormalMinimum=?, NormalMaximum=?, DisplayOrder=?, IsRequired=?, IsActive=?, SelectChoices=? WHERE ParameterID=?');
                            $stmt->execute([$testId, $name, $resultType, $unitId > 0 ? $unitId : null, $range !== '' ? $range : null, ($min !== '' && $min !== null) ? (float) $min : null, ($max !== '' && $max !== null) ? (float) $max : null, $order, $required, $active, $choices !== '' ? $choices : null, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_parameters (TestID, ParameterName, ResultType, UnitID, ReferenceRange, NormalMinimum, NormalMaximum, DisplayOrder, IsRequired, IsActive, SelectChoices) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                            $stmt->execute([$testId, $name, $resultType, $unitId > 0 ? $unitId : null, $range !== '' ? $range : null, ($min !== '' && $min !== null) ? (float) $min : null, ($max !== '' && $max !== null) ? (float) $max : null, $order, $required, $active, $choices !== '' ? $choices : null]);
                        }
                        break;
                    case 'lab_add_unit':
                    case 'lab_update_unit':
                    case 'save_unit':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['UnitID'] ?? 0);
                        $name = trim((string) ($_POST['UnitName'] ?? ''));
                        $symbol = trim((string) ($_POST['UnitSymbol'] ?? ''));
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        if ($name === '' || $symbol === '') throw new RuntimeException('Unit name and symbol are required.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_units SET UnitName=?, UnitSymbol=?, IsActive=? WHERE UnitID=?');
                            $stmt->execute([$name, $symbol, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_units (UnitName, UnitSymbol, IsActive) VALUES (?,?,?)');
                            $stmt->execute([$name, $symbol, $active]);
                        }
                        break;
                    case 'lab_add_flag':
                    case 'lab_update_flag':
                    case 'save_flag':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['FlagID'] ?? 0);
                        $flag = trim((string) ($_POST['FlagName'] ?? ''));
                        $code = trim((string) ($_POST['FlagCode'] ?? ''));
                        $desc = trim((string) ($_POST['Description'] ?? ''));
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        if ($flag === '' || $code === '') throw new RuntimeException('Flag name and code are required.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_flags SET FlagName=?, FlagCode=?, Description=?, IsActive=? WHERE FlagID=?');
                            $stmt->execute([$flag, $code, $desc !== '' ? $desc : null, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive) VALUES (?,?,?,?)');
                            $stmt->execute([$flag, $code, $desc !== '' ? $desc : null, $active]);
                        }
                        break;
                    case 'lab_add_center':
                    case 'lab_update_center':
                    case 'save_center':
                        tdc_require_permission('setup.laboratory.manage');
                        $id = (int) ($_POST['LabCenterID'] ?? 0);
                        $name = trim((string) ($_POST['CenterName'] ?? ''));
                        $location = trim((string) ($_POST['Location'] ?? ''));
                        $phone = trim((string) ($_POST['Phone'] ?? ''));
                        $active = in_array((string) ($_POST['IsActive'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        if ($name === '') throw new RuntimeException('Center name is required.');
                        if ($id > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_centers SET CenterName=?, Location=?, Phone=?, IsActive=? WHERE LabCenterID=?');
                            $stmt->execute([$name, $location !== '' ? $location : null, $phone !== '' ? $phone : null, $active, $id]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_centers (CenterName, Location, Phone, IsActive) VALUES (?,?,?,?)');
                            $stmt->execute([$name, $location !== '' ? $location : null, $phone !== '' ? $phone : null, $active]);
                        }
                        break;
                    case 'lab_add_selection':
                    case 'lab_update_selection':
                    case 'save_selection':
                        tdc_require_permission('setup.laboratory.manage');
                        $selectionId = (int) ($_POST['SelectionID'] ?? 0);
                        $testId = (int) ($_POST['TestID'] ?? 0);
                        $doctorId = (int) ($_POST['DoctorID'] ?? 0);
                        $enabled = in_array((string) ($_POST['IsEnabled'] ?? ''), ['1', 'on'], true) ? 1 : 0;
                        $labCenterId = (int) ($_POST['LabCenterID'] ?? 0);
                        if ($testId <= 0) throw new RuntimeException('Select a test to enable or disable.');
                        if ($doctorId <= 0 || $labCenterId <= 0) throw new RuntimeException('Select both a doctor and a lab center for this selection.');
                        $duplicateCheck = $pdo->prepare('SELECT SelectionID FROM lab_test_selection WHERE TestID=? AND DoctorID <=> ? AND LabCenterID <=> ? AND SelectionID <> ? LIMIT 1');
                        $duplicateCheck->execute([$testId, $doctorId > 0 ? $doctorId : null, $labCenterId > 0 ? $labCenterId : null, $selectionId]);
                        if ((int) $duplicateCheck->fetchColumn() > 0) { throw new RuntimeException('This test selection already exists.'); }
                        if ($selectionId > 0) {
                            $stmt = $pdo->prepare('UPDATE lab_test_selection SET TestID=?, DoctorID=?, IsEnabled=?, LabCenterID=? WHERE SelectionID=?');
                            $stmt->execute([$testId, $doctorId > 0 ? $doctorId : null, $enabled, $labCenterId > 0 ? $labCenterId : null, $selectionId]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO lab_test_selection (TestID, DoctorID, IsEnabled, LabCenterID) VALUES (?,?,?,?)');
                            $stmt->execute([$testId, $doctorId > 0 ? $doctorId : null, $enabled, $labCenterId > 0 ? $labCenterId : null]);
                        }
                        break;
                    case 'lab_add_center_test':
                    case 'lab_update_center_test':
                    case 'save_center_test':
                        tdc_require_permission('setup.laboratory.manage');
                        $centerId = (int) ($_POST['LabCenterID'] ?? 0);
                        $testId = (int) ($_POST['TestID'] ?? 0);
                        if ($centerId <= 0 || $testId <= 0) throw new RuntimeException('Select both a center and a test.');
                        $active = in_array((string)($_POST['IsActive'] ?? '1'),['1','on'],true)?1:0;
                        $stmt = $pdo->prepare('INSERT INTO lab_center_tests (LabCenterID,TestID,IsActive) VALUES (?,?,?) ON DUPLICATE KEY UPDATE IsActive=VALUES(IsActive)');
                        $stmt->execute([$centerId,$testId,$active]);
                        break;
                }
                header('Location: laboratory.php?workspace=1&lab_tab=' . rawurlencode($labWorkspaceTab) . '&saved=1');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
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
    #js-toast{ position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(20px); display:flex; align-items:center; gap:8px; background:var(--surface); border:1px solid var(--border-ui); border-radius:var(--radius); color:var(--text-primary); font-size:13px; font-weight:600; padding:11px 18px; white-space:nowrap; z-index:9999; opacity:0; pointer-events:none; box-shadow:var(--shadow-card); transition:opacity 0.2s ease, transform 0.2s ease; }
    #js-toast svg{ width:16px; height:16px; flex-shrink:0; color:var(--success); }
    #js-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    .setup-table-panel .workspace-panel-heading { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 22px 10px; border-bottom:1px solid var(--border-ui); }
    .setup-table-panel .workspace-panel-heading h2 { margin:0; font-size:1.2rem; letter-spacing:-0.02em; }
    .setup-table-panel .workspace-panel-heading p { margin:6px 0 0; color:var(--text-secondary); font-size:12.5px; }
    .section-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 16px; flex-wrap:wrap; }
    .section-toolbar .filter-box { display:flex; align-items:center; gap:10px; flex:1; min-width:280px; flex-wrap:wrap; }
    .filter-box input, .filter-box select, .setup-inline-form input, .setup-inline-form select, .setup-inline-form textarea { height:42px; padding:10px 12px; border:1px solid var(--border-ui); border-radius:10px; background:#fff; color:var(--text-primary); font:inherit; font-size:14px; }
    .filter-box input, .filter-box select { min-width:150px; }
    .filter-box textarea, .setup-inline-form textarea { min-height:100px; }
    .filter-box input:focus, .filter-box select:focus, .setup-inline-form input:focus, .setup-inline-form select:focus, .setup-inline-form textarea:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(46,49,146,.12); }
    .section-toolbar .btn, .filter-box .btn, .setup-inline-form .btn { height:42px; border-radius:10px; font-size:13px; }
    .setup-inline-form { display:flex; flex-wrap:wrap; align-items:end; gap:14px; padding:18px; margin:0 0 18px; border:1px solid var(--border-ui); border-radius:14px; background:#fafbff; }
    .setup-inline-form .form-group { flex:1 1 180px; margin:0; }
    .setup-inline-form label { display:block; margin-bottom:6px; color:var(--text-secondary); font-size:12px; font-weight:700; }
    .setup-inline-form .btn { align-self:flex-end; }
    .form-row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .form-group { display:flex; flex-direction:column; min-width:0; margin:0 0 15px; }
    .form-group label { margin-bottom:6px; color:var(--text-secondary); font-size:12.5px; font-weight:700; }
    .form-group input, .form-group select, .form-group textarea { width:100%; min-height:42px; padding:10px 12px; border:1px solid var(--border-ui); border-radius:10px; background:#fff; color:var(--text-primary); font:inherit; font-size:14px; }
    .form-group textarea { min-height:96px; resize:vertical; }
    .check-control { display:inline-flex; align-items:center; gap:8px; margin:6px 0 14px; color:var(--text-primary); font-size:13px; font-weight:600; }
    .check-control input { width:16px; height:16px; }
    .modal-box { width:min(760px, calc(100vw - 26px)); max-width:760px; max-height:calc(100vh - 48px); overflow:hidden; border-radius:16px; }
    .modal-box.modal-wide { width:min(980px, calc(100vw - 26px)); max-width:980px; }
    .modal-overlay .modal-head { padding:18px 20px 14px; border-bottom:1px solid var(--border-ui); }
    .modal-overlay .modal-head h3 { font-size:1.05rem; }
    .modal-overlay .modal-body { padding:18px 20px; overflow-y:auto; }
    .modal-overlay .modal-actions { padding:14px 20px 18px; border-top:1px solid var(--border-ui); background:#fafbff; }
    .modal-overlay .modal-actions .btn { min-width:120px; }
    .empty-state-box { padding:22px; text-align:center; border:1px dashed var(--border-ui); border-radius:16px; background:#fafbff; color:var(--text-secondary); }
    .empty-state-box h3 { margin:0 0 8px; font-size:1.05rem; color:var(--text-primary); }
    .empty-state-box p { margin:0; }
    @media (max-width: 768px) { .form-row { grid-template-columns:1fr; } .modal-overlay { padding:12px; } .modal-box { max-height:calc(100vh - 24px); } }
 .modern-result-workspace{background:#fff;border:1px solid #dfe5f2;border-radius:18px;padding:24px;box-shadow:0 12px 30px rgba(29,48,94,.08)}
 #labResultModal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,25,65,.58);align-items:center;justify-content:center;padding:16px}#labResultModal.show{display:flex}body.modal-open{overflow:hidden}
 .modern-result-heading{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:1px solid #edf0f7;padding-bottom:18px}.modern-result-kicker{margin:14px 0 3px;color:#6b78a0;font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:700}.modern-result-heading h3{margin:0;color:#1f2b68;font-size:25px}.result-status-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#3446a3;padding:5px 10px;font-size:12px;font-weight:700;margin-left:8px}.modern-result-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0}.modern-result-summary>div{background:#f7f9fd;border-radius:12px;padding:12px}.modern-result-summary small,.modern-result-summary span{display:block;color:#7380a2;font-size:12px}.modern-result-summary strong{display:block;color:#202d65;margin:4px 0;font-size:14px}.modern-test-card{border:1px solid #dfe5f2;border-radius:15px;padding:18px;margin:14px 0;background:#fff}.modern-test-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.modern-test-card-head h4{margin:3px 0;color:#202d65;font-size:19px}.modern-test-card-head p{margin:0;color:#7180a5;font-size:13px}.test-number{color:#6979b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}.result-parameter-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;border-top:1px solid #edf0f7;padding-top:14px;margin-top:12px}.result-parameter-grid label{display:block;color:#34446f;font-weight:700;font-size:13px;margin-bottom:6px}.result-parameter-grid input,.result-parameter-grid textarea{width:100%;box-sizing:border-box;border:1px solid #ccd5e8;border-radius:9px;padding:10px;font:inherit}.result-parameter-grid small{display:block;color:#7180a5;margin-top:6px}.modern-result-actions{display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #edf0f7;margin-top:20px;padding-top:18px}@media(max-width:800px){.modern-result-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.modern-result-heading{flex-direction:column}.result-parameter-grid{grid-template-columns:1fr}}@media(max-width:520px){.modern-result-workspace{padding:15px}.modern-result-summary{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="../assets/clinic.css?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.css')) ?>">
<script src="../assets/clinic.js?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.js')) ?>" defer></script>
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
                <li class="nav-item<?= basename((string) strtok((string) $item['href'], '?')) === $currentPage ? ' active' : '' ?>">
                    <a href="<?= tdc_e($item['href']) ?>" class="nav-link">
                        <?= tdc_navigation_icon($item['href']) ?>
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
    <div class="welcome-sub">Process doctor requests and record clinical results.</div>

    <?php if (true): ?>
    <nav class="setup-section-nav" aria-label="Laboratory sections">
        <?php foreach ($labWorkspaceTabs as $key => $label): ?>
            <a href="laboratory.php?workspace=1&lab_tab=<?= rawurlencode($key) ?>" class="<?= $labWorkspaceTab === $key ? 'active' : '' ?>"><?= tdc_e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="setup-table-panel">
        <div class="workspace-panel-heading">
            <div>
                <h2><?= $labWorkspaceTab === 'print-result' ? 'Lab Results & Printing' : tdc_e($labWorkspaceTabs[$labWorkspaceTab] ?? 'Laboratory Workspace') ?></h2>
                <p><?= $labWorkspaceTab === 'print-result' ? 'Review completed laboratory results and print or reprint official reports.' : tdc_e($labSectionDescriptions[$labWorkspaceTab] ?? 'Laboratory configuration and workflow management.') ?></p>
            </div>
        </div>

        <?php if ($labWorkspaceTab === 'test-result-entry'): ?>
            <?php if ($resultOrder): ?>
            <section class="modern-result-workspace" id="modern-result-editor">
                <div class="modern-result-heading"><div><a class="btn btn-secondary btn-sm" href="laboratory.php?workspace=1&amp;lab_tab=test-result-entry">← Back to Work Queue</a><p class="modern-result-kicker">Modern laboratory result entry</p><h3><?= tdc_e($resultReference) ?> <span class="result-status-badge"><?= tdc_e((string)$resultOrder['WorkflowStatus']) ?></span></h3></div><?php if ($resultOrder['WorkflowStatus']==='Completed'): ?><a class="btn btn-primary" target="_blank" rel="noopener" href="../print_laboratory.php?result=1&amp;ref=<?= urlencode($resultReference) ?>">Print A4 Result</a><?php endif; ?></div>
                <div class="modern-result-summary"><div><small>Patient</small><strong><?= tdc_e($resultOrder['PatientName']) ?></strong><span>Patient ID <?= (int)$resultOrder['PatientID'] ?></span></div><div><small>Visit</small><strong><?= tdc_e((string)($resultOrder['VisitReference'] ?: $resultOrder['VisitID'])) ?></strong></div><div><small>Doctor</small><strong><?= tdc_e((string)($resultOrder['DoctorName'] ?: '—')) ?></strong></div><div><small>Payment</small><strong><?= tdc_e((string)$resultOrder['PaymentStatus']) ?></strong></div><div><small>Requested Tests</small><strong><?= count($resultDetails) ?></strong></div></div>
                <form method="post" class="modern-result-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($resultReference) ?>">
                <?php foreach ($resultDetails as $cardIndex => $detail): $isStructured = (($detail['ResultMode'] ?? '') === 'Structured Parameters') && !empty($detail['parameters']) && (int)($detail['parameters'][0]['ParameterID'] ?? 0) > 0; $singleRow = (!$isStructured && !empty($detail['parameters'])) ? $detail['parameters'][0] : null; ?>
                    <article class="modern-test-card"><div class="modern-test-card-head"><div><span class="test-number">Test <?= $cardIndex+1 ?></span><h4><?= tdc_e((string)$detail['ModernTestNameSnapshot']) ?></h4><p><?= tdc_e((string)($detail['ResultMode'] ?? 'Single Result')) ?> · <?= tdc_e((string)($detail['TypeName'] ?? '')) ?></p></div><span class="result-status-badge"><?= tdc_e((string)($detail['ResultStatus'] ?: 'Processing')) ?></span></div>
                    <?php if ($isStructured): foreach ($detail['parameters'] as $parameterIndex => $parameter): $fieldIndex = $cardIndex.'_'.$parameterIndex; ?>
                        <div class="result-parameter-grid"><input type="hidden" name="ParameterID[]" value="<?= (int)($parameter['ParameterID'] ?? 0) ?>"><div><label for="result-value-<?= $fieldIndex ?>"><?= tdc_e((string)($parameter['ParameterName'] ?? 'Result')) ?><?= !empty($parameter['IsRequired']) ? ' *' : '' ?></label><input id="result-value-<?= $fieldIndex ?>" name="ResultValue[]" type="<?= (($parameter['ResultType'] ?? '')==='Numeric')?'number':'text' ?>" <?= (($parameter['ResultType'] ?? '')==='Numeric')?'step="any"':'' ?> value="<?= tdc_e((string)($parameter['RawResult']??'')) ?>" <?= $resultOrder['WorkflowStatus']==='Completed'?'readonly':'' ?>><small><?= tdc_e(implode(' · ',array_filter([$parameter['UnitName'] ?? '',$parameter['ReferenceRange'] ?? '',$parameter['FlagCodeSnapshot'] ?? '']))) ?></small></div><div><label for="result-remark-<?= $fieldIndex ?>">Remark</label><textarea id="result-remark-<?= $fieldIndex ?>" name="Remark[]" rows="2" <?= $resultOrder['WorkflowStatus']==='Completed'?'readonly':'' ?>><?= tdc_e((string)($parameter['Remark']??'')) ?></textarea></div></div>
                    <?php endforeach; else: $singleLabResultId = (int)($detail['LabResultID'] ?? 0); $singleValue = (string)($singleRow['RawResult'] ?? ''); ?>
                        <div class="result-parameter-grid"><div><label for="single-result-<?= $cardIndex ?>">Result *</label><input id="single-result-<?= $cardIndex ?>" name="SingleResult[<?= $singleLabResultId ?>]" value="<?= tdc_e($singleValue) ?>" <?= $resultOrder['WorkflowStatus']==='Completed'?'readonly':'' ?>></div><div><label for="single-remark-<?= $cardIndex ?>">Remark</label><textarea id="single-remark-<?= $cardIndex ?>" name="SingleRemark[<?= $singleLabResultId ?>]" rows="2" <?= $resultOrder['WorkflowStatus']==='Completed'?'readonly':'' ?>><?= tdc_e((string)($singleRow['Remark'] ?? '')) ?></textarea></div></div>
                    <?php endif; ?></article>
                <?php endforeach; ?>
                <?php if ($resultOrder['WorkflowStatus'] !== 'Completed'): ?><div class="modern-result-actions"><button class="btn btn-secondary" name="form_action" value="save_lab_result_draft">Save Draft</button><button class="btn btn-primary" name="form_action" value="complete_lab_result">Complete Result</button></div><?php endif; ?></form>
            </section>
            <?php else: ?>
            <div class="setup-card-grid">
                <div class="setup-module-card"><strong><?= count($labOperationalQueue) ?></strong><small>Open lab orders</small></div>
                <div class="setup-module-card"><strong><?= count($labCategories) ?></strong><small>Categories</small></div>
                <div class="setup-module-card"><strong><?= count($labTypes) ?></strong><small>Lab Types</small></div>
                <div class="setup-module-card"><strong><?= count($labTests) ?></strong><small>Registered tests</small></div>
            </div>
            <?php endif; ?>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Lab ID</th><th>Patient</th><th>Patient ID</th><th>Age/Gender</th><th>Doctor</th><th>Requested Tests</th><th>Request Date</th><th>Payment</th><th>Lab Status</th><th>Lab Center</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labOperationalQueue as $order): ?>
                        <tr>
                            <td><?= tdc_e((string) ($order['LaboratoryID'] ?? '')) ?></td>
                            <td><?= tdc_e((string) ($order['PatientName'] ?? '')) ?></td>
                            <td><?= tdc_e((string) ($order['PatientID'] ?? '')) ?></td>
                            <td><?= tdc_e((string) (($order['Gender'] ?? '') !== '' ? (($order['Age'] ?? '') !== '' ? ((string) $order['Age']) . ' / ' . (string) $order['Gender'] : (string) $order['Gender']) : ((string) ($order['Age'] ?? '')))) ?></td>
                            <td><?= tdc_e((string) ($order['DoctorName'] ?? '')) ?></td>
                            <td><?= tdc_e((string) ($order['TestName'] ?? '')) ?></td>
                            <td><?= tdc_e((string) ($order['OrderDate'] ?? '')) ?></td>
                            <td><?= tdc_e((string) ($order['PaymentStatus'] ?? '')) ?></td>
                            <td><?= tdc_e((string) (($order['ResultStatus'] ?? $order['WorkflowStatus']) ?? 'Awaiting Payment')) ?></td>
                            <td><?= tdc_e((string) (($order['LabCenterID'] ?? $order['Location'] ?? '') ?: '—')) ?></td>
                            <td>
                                <?php $labState = (string)$order['WorkflowStatus']; ?>
                                <?php if (in_array($labState, ['Requested','Awaiting Payment','Ready'], true)): ?>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="collect_sample">
                                    <input type="hidden" name="LaboratoryID" value="<?= tdc_e($order['LaboratoryID']) ?>">
                                    <button class="btn btn-primary btn-sm">Collect Sample</button>
                                </form>
                                <?php elseif (in_array($labState, ['In Progress', 'Draft', 'Processing'], true)): ?>
                                <button type="button" class="btn btn-secondary btn-sm" data-lab-result-open data-result-mode="edit" data-laboratory-id="<?= tdc_e((string)$order['LaboratoryID']) ?>">Edit Result</button>
                                <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm" data-lab-result-open data-result-mode="view" data-laboratory-id="<?= tdc_e((string)$order['LaboratoryID']) ?>">View Result</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($labWorkspaceTab === 'category-header'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="category-header">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search categories">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="category-modal">+ Add Category</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Category ID</th><th>Category Name</th><th>Description</th><th>Display Order</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labCategoriesFiltered as $category): ?><tr><td><?= (int) $category['CategoryID'] ?></td><td><?= tdc_e((string) $category['CategoryName']) ?></td><td><?= tdc_e((string) ($category['Description'] ?? '')) ?></td><td><?= (int) ($category['DisplayOrder'] ?? 0) ?></td><td><?= tdc_e((int) $category['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-category="<?= (int) $category['CategoryID'] ?>" data-category-name="<?= tdc_e((string) $category['CategoryName']) ?>" data-category-description="<?= tdc_e((string) ($category['Description'] ?? '')) ?>" data-category-order="<?= (int) ($category['DisplayOrder'] ?? 0) ?>" data-category-active="<?= (int) ($category['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_category"><input type="hidden" name="CategoryID" value="<?= (int) $category['CategoryID'] ?>"><input type="hidden" name="CategoryName" value="<?= tdc_e((string) $category['CategoryName']) ?>"><input type="hidden" name="Description" value="<?= tdc_e((string) ($category['Description'] ?? '')) ?>"><input type="hidden" name="DisplayOrder" value="<?= (int) ($category['DisplayOrder'] ?? 0) ?>"><input type="hidden" name="IsActive" value="<?= (int) $category['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $category['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $category['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="category-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Category</h3><button type="button" class="modal-close" data-close-modal="category-modal">×</button></div>
                    <form method="post" id="category-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_category">
                        <input type="hidden" name="CategoryID" id="category-id" value="0">
                        <div class="form-row"><div class="form-group"><label>Category Name</label><input type="text" name="CategoryName" id="category-name" required></div><div class="form-group"><label>Display Order</label><input type="number" name="DisplayOrder" id="category-order" value="0"></div></div>
                        <div class="form-group"><label>Description</label><textarea name="Description" id="category-description"></textarea></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="category-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="category-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'lab-type'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="lab-type">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search lab types">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="type-modal">+ Add Lab Type</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Category</th><th>Lab Type</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labTypesFiltered as $type): ?><tr><td><?= (int) $type['TypeID'] ?></td><td><?= tdc_e((string) ($type['CategoryName'] ?? '')) ?></td><td><?= tdc_e((string) $type['TypeName']) ?></td><td><?= tdc_e((string) ($type['Description'] ?? '')) ?></td><td><?= tdc_e((int) $type['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-type="<?= (int)$type['TypeID'] ?>" data-type-category="<?= (int) ($type['CategoryID'] ?? 0) ?>" data-type-name="<?= tdc_e((string) $type['TypeName']) ?>" data-type-description="<?= tdc_e((string) ($type['Description'] ?? '')) ?>" data-type-order="<?= (int) ($type['DisplayOrder'] ?? 0) ?>" data-type-active="<?= (int) ($type['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_type"><input type="hidden" name="TypeID" value="<?= (int) $type['TypeID'] ?>"><input type="hidden" name="CategoryID" value="<?= (int) ($type['CategoryID'] ?? 0) ?>"><input type="hidden" name="TypeName" value="<?= tdc_e((string) $type['TypeName']) ?>"><input type="hidden" name="Description" value="<?= tdc_e((string) ($type['Description'] ?? '')) ?>"><input type="hidden" name="DisplayOrder" value="<?= (int) ($type['DisplayOrder'] ?? 0) ?>"><input type="hidden" name="IsActive" value="<?= (int) $type['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $type['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $type['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="type-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Lab Type</h3><button type="button" class="modal-close" data-close-modal="type-modal">×</button></div>
                    <form method="post" id="type-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_type">
                        <input type="hidden" name="TypeID" id="type-id" value="0">
                        <div class="form-row"><div class="form-group"><label for="type-category">Category</label><select name="CategoryID" id="type-category" required><option value="">Choose a category</option><?php foreach ($labCategories as $category): ?><option value="<?= (int) $category['CategoryID'] ?>"><?= tdc_e((string) $category['CategoryName']) ?></option><?php endforeach; ?></select><div id="type-category-hint" class="field-hint">Choose one of the five laboratory categories.</div></div><div class="form-group"><label for="type-order">Display Order</label><input type="number" name="DisplayOrder" id="type-order" value="0" min="0"></div></div>
                        <div class="form-group"><label for="type-name">Lab Type Name</label><input type="text" name="TypeName" id="type-name" list="type-name-options" placeholder="e.g. Complete Blood Count" maxlength="150" required><datalist id="type-name-options"><?php foreach ($labTypes as $type): ?><option value="<?= tdc_e((string) $type['TypeName']) ?>" data-category="<?= (int) ($type['CategoryID'] ?? 0) ?>"></option><?php endforeach; ?></datalist><div class="field-hint">Select a category, then enter the type name. Existing names for that category appear as suggestions.</div></div>
                        <div class="form-group"><label>Description</label><textarea name="Description" id="type-description"></textarea></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="type-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="type-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'test-register'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="test-register">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search tests">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="test-modal">+ Register Test</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Test Name</th><th>Category</th><th>Type</th><th>Price</th><th>Result Mode</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labTestsFiltered as $test): ?><tr><td><?= (int) $test['TestID'] ?></td><td><?= tdc_e((string) $test['TestName']) ?></td><td><?= tdc_e((string) ($test['CategoryName'] ?? '')) ?></td><td><?= tdc_e((string) ($test['TypeName'] ?? '')) ?></td><td><?= number_format((float) ($test['Price'] ?? 0), 2) ?></td><td><?= tdc_e((string) ($test['ResultMode'] ?? 'Structured Parameters')) ?></td><td><?= tdc_e((int) $test['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-test="<?= (int)$test['TestID'] ?>" data-test-category="<?= (int) ($test['CategoryID'] ?? 0) ?>" data-test-type="<?= (int) ($test['TypeID'] ?? 0) ?>" data-test-name="<?= tdc_e((string) $test['TestName']) ?>" data-test-description="<?= tdc_e((string) ($test['Description'] ?? '')) ?>" data-test-price="<?= number_format((float) ($test['Price'] ?? 0), 2, '.', '') ?>" data-test-mode="<?= tdc_e((string) ($test['ResultMode'] ?? 'Structured Parameters')) ?>" data-test-order="<?= (int) ($test['DisplayOrder'] ?? 0) ?>" data-test-active="<?= (int) ($test['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_test"><input type="hidden" name="TestID" value="<?= (int) $test['TestID'] ?>"><input type="hidden" name="CategoryID" value="<?= (int) ($test['CategoryID'] ?? 0) ?>"><input type="hidden" name="TypeID" value="<?= (int) ($test['TypeID'] ?? 0) ?>"><input type="hidden" name="TestName" value="<?= tdc_e((string) $test['TestName']) ?>"><input type="hidden" name="Description" value="<?= tdc_e((string) ($test['Description'] ?? '')) ?>"><input type="hidden" name="Price" value="<?= number_format((float) ($test['Price'] ?? 0), 2, '.', '') ?>"><input type="hidden" name="ResultMode" value="<?= tdc_e((string) ($test['ResultMode'] ?? 'Structured Parameters')) ?>"><input type="hidden" name="DisplayOrder" value="<?= (int) ($test['DisplayOrder'] ?? 0) ?>"><input type="hidden" name="IsActive" value="<?= (int) $test['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $test['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $test['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="test-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Register Test</h3><button type="button" class="modal-close" data-close-modal="test-modal">×</button></div>
                    <form method="post" id="test-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_test">
                        <input type="hidden" name="TestID" id="test-id" value="0">
                        <div class="form-row"><div class="form-group"><label>Category</label><select name="CategoryID" id="test-category" required><?php foreach ($labCategories as $category): ?><option value="<?= (int) $category['CategoryID'] ?>"><?= tdc_e((string) $category['CategoryName']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Lab Type</label><select name="TypeID" id="test-type" required><?php foreach ($labTypes as $type): ?><option value="<?= (int) $type['TypeID'] ?>" data-category="<?= (int) ($type['CategoryID'] ?? 0) ?>"><?= tdc_e((string) $type['TypeName']) ?></option><?php endforeach; ?></select></div></div>
                        <div class="form-row"><div class="form-group"><label>Test Name</label><input type="text" name="TestName" id="test-name" required></div><div class="form-group"><label>Price</label><input type="number" step="0.01" name="Price" id="test-price" value="0.00" required></div></div>
                        <div class="form-row"><div class="form-group"><label>Result Mode</label><select name="ResultMode" id="test-mode"><option value="Structured Parameters">Structured Parameters</option><option value="Single Result">Single Result</option></select></div><div class="form-group"><label>Display Order</label><input type="number" name="DisplayOrder" id="test-order" value="0"></div></div>
                        <div class="form-group"><label>Description</label><textarea name="Description" id="test-description"></textarea></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="test-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="test-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'lab-parameter'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="lab-parameter">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search parameters">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="parameter-modal">+ Add Parameter</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Test</th><th>Parameter</th><th>Type</th><th>Reference Range</th><th>Unit</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labParametersFiltered as $param): ?><tr><td><?= (int) $param['ParameterID'] ?></td><td><?= tdc_e((string) ($param['TestName'] ?? '')) ?></td><td><?= tdc_e((string) $param['ParameterName']) ?></td><td><?= tdc_e((string) $param['ResultType']) ?></td><td><?= tdc_e((string) ($param['ReferenceRange'] ?? '')) ?></td><td><?= tdc_e((string) (($param['UnitSymbol'] ?: $param['UnitName']) ?? '')) ?></td><td><?= tdc_e((int) $param['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-parameter="<?= (int) $param['ParameterID'] ?>" data-parameter-test="<?= (int) ($param['TestID'] ?? 0) ?>" data-parameter-name="<?= tdc_e((string) $param['ParameterName']) ?>" data-parameter-type="<?= tdc_e((string) $param['ResultType']) ?>" data-parameter-unit="<?= (int) ($param['UnitID'] ?? 0) ?>" data-parameter-range="<?= tdc_e((string) ($param['ReferenceRange'] ?? '')) ?>" data-parameter-min="<?= $param['NormalMinimum'] !== null && $param['NormalMinimum'] !== '' ? number_format((float) $param['NormalMinimum'], 2, '.', '') : '' ?>" data-parameter-max="<?= $param['NormalMaximum'] !== null && $param['NormalMaximum'] !== '' ? number_format((float) $param['NormalMaximum'], 2, '.', '') : '' ?>" data-parameter-order="<?= (int) ($param['DisplayOrder'] ?? 0) ?>" data-parameter-required="<?= (int) ($param['IsRequired'] ?? 0) ?>" data-parameter-active="<?= (int) ($param['IsActive'] ?? 0) ?>" data-parameter-choices="<?= tdc_e((string) ($param['SelectChoices'] ?? '')) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_parameter"><input type="hidden" name="ParameterID" value="<?= (int) $param['ParameterID'] ?>"><input type="hidden" name="TestID" value="<?= (int) ($param['TestID'] ?? 0) ?>"><input type="hidden" name="ParameterName" value="<?= tdc_e((string) $param['ParameterName']) ?>"><input type="hidden" name="ResultType" value="<?= tdc_e((string) $param['ResultType']) ?>"><input type="hidden" name="UnitID" value="<?= (int) ($param['UnitID'] ?? 0) ?>"><input type="hidden" name="ReferenceRange" value="<?= tdc_e((string) ($param['ReferenceRange'] ?? '')) ?>"><input type="hidden" name="NormalMinimum" value="<?= $param['NormalMinimum'] !== null && $param['NormalMinimum'] !== '' ? number_format((float) $param['NormalMinimum'], 2, '.', '') : '' ?>"><input type="hidden" name="NormalMaximum" value="<?= $param['NormalMaximum'] !== null && $param['NormalMaximum'] !== '' ? number_format((float) $param['NormalMaximum'], 2, '.', '') : '' ?>"><input type="hidden" name="DisplayOrder" value="<?= (int) ($param['DisplayOrder'] ?? 0) ?>"><input type="hidden" name="SelectChoices" value="<?= tdc_e((string)($param['SelectChoices'] ?? '')) ?>"><input type="hidden" name="IsRequired" value="<?= (int) ($param['IsRequired'] ?? 0) ?>"><input type="hidden" name="IsActive" value="<?= (int) $param['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $param['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $param['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="parameter-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Parameter</h3><button type="button" class="modal-close" data-close-modal="parameter-modal">×</button></div>
                    <form method="post" id="parameter-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_parameter">
                        <input type="hidden" name="ParameterID" id="parameter-id" value="0">
                        <div class="form-row"><div class="form-group"><label>Test</label><select name="TestID" id="parameter-test" required><?php foreach ($labTests as $test): ?><option value="<?= (int) $test['TestID'] ?>"><?= tdc_e((string) $test['TestName']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Display Order</label><input type="number" name="DisplayOrder" id="parameter-order" value="0"></div></div>
                        <div class="form-row"><div class="form-group"><label>Parameter</label><input type="text" name="ParameterName" id="parameter-name" required></div><div class="form-group"><label>Result Type</label><select name="ResultType" id="parameter-type"><option value="Numeric">Numeric</option><option value="Text">Text</option><option value="Positive/Negative">Positive/Negative</option><option value="Select">Select</option></select></div></div>
                        <div class="form-row"><div class="form-group"><label>Unit</label><select name="UnitID" id="parameter-unit"><option value="0">None</option><?php foreach ($labUnits as $unit): ?><option value="<?= (int) $unit['UnitID'] ?>"><?= tdc_e((string) $unit['UnitName']) ?> (<?= tdc_e((string) $unit['UnitSymbol']) ?>)</option><?php endforeach; ?></select></div><div class="form-group"><label>Reference Range</label><input type="text" name="ReferenceRange" id="parameter-range" placeholder="4-11"></div></div>
                        <div class="form-row"><div class="form-group"><label>Normal Minimum</label><input type="number" step="0.01" name="NormalMinimum" id="parameter-min"></div><div class="form-group"><label>Normal Maximum</label><input type="number" step="0.01" name="NormalMaximum" id="parameter-max"></div></div>
                        <div class="form-row"><div class="form-group"><label>Choices (for Select)</label><textarea name="SelectChoices" id="parameter-choices" placeholder="Normal,Abnormal"></textarea></div><div class="form-group"><label>Flag Rule</label><input type="text" name="FlagRule" id="parameter-flag-rule" placeholder="LOW/HIGH/NORMAL" value="NORMAL"></div></div>
                        <label class="check-control"><input type="checkbox" name="IsRequired" id="parameter-required" checked> Required</label><br>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="parameter-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="parameter-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'lab-selection'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="lab-selection">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search selection">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Test</th><th>Category</th><th>Type</th><th>Doctor</th><th>Enabled</th><th>Center</th></tr></thead><tbody><?php foreach ($labSelectionFiltered as $row): ?><tr><td><?= tdc_e((string) ($row['TestName'] ?? '')) ?></td><td><?= tdc_e((string) ($row['CategoryName'] ?? '')) ?></td><td><?= tdc_e((string) ($row['TypeName'] ?? '')) ?></td><td><?= tdc_e((string) ($row['DoctorName'] ?? (string) ($row['DoctorID'] ?? '')) ) ?></td><td><?= tdc_e((int) $row['IsEnabled'] === 1 ? 'Enabled' : 'Disabled') ?></td><td><?= tdc_e((string) ($row['CenterName'] ?? (string) ($row['LabCenterID'] ?? '')) ) ?></td></tr><?php endforeach; ?></tbody></table></div>
            <form method="post" class="setup-inline-form" id="selection-form">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="workspace_action" value="lab_add_selection">
                <input type="hidden" name="SelectionID" id="selection-id" value="0">
                <div class="form-row"><div class="form-group"><label>Test</label><select name="TestID" id="selection-test" required><?php foreach ($labTests as $test): ?><option value="<?= (int) $test['TestID'] ?>"><?= tdc_e((string) $test['TestName']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Doctor</label><select name="DoctorID" id="selection-doctor" required><option value="">Select doctor</option><?php foreach ($pdo->query('SELECT DoctorID, DoctorName FROM doctors ORDER BY DoctorName')->fetchAll() as $doctor): ?><option value="<?= (int) $doctor['DoctorID'] ?>"><?= tdc_e((string) $doctor['DoctorName']) ?></option><?php endforeach; ?></select></div></div>
                <div class="form-row"><div class="form-group"><label>Lab Center</label><select name="LabCenterID" id="selection-center" required><option value="">Select center</option><?php foreach ($labCenters as $center): ?><option value="<?= (int) $center['LabCenterID'] ?>"><?= tdc_e((string) $center['CenterName']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Availability</label><select name="IsEnabled" id="selection-enabled"><option value="1">Enabled for ordering</option><option value="0">Disabled for ordering</option></select></div></div>
                <button type="submit" class="btn btn-primary">Save selection</button>
            </form>
            <?php foreach ($labSelectionFiltered as $selection): ?>
            <form method="post" class="setup-inline-form">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="workspace_action" value="lab_update_selection">
                <input type="hidden" name="SelectionID" value="<?= (int)$selection['SelectionID'] ?>">
                <input type="hidden" name="TestID" value="<?= (int)$selection['TestID'] ?>">
                <input type="hidden" name="DoctorID" value="<?= (int)$selection['DoctorID'] ?>">
                <input type="hidden" name="LabCenterID" value="<?= (int)$selection['LabCenterID'] ?>">
                <input type="hidden" name="IsEnabled" value="<?= $selection['IsEnabled']?0:1 ?>">
                <span><?= tdc_e($selection['TestName']) ?> · Doctor <?= (int)$selection['DoctorID'] ?> · Center <?= (int)$selection['LabCenterID'] ?></span>
                <button class="btn btn-secondary"><?= $selection['IsEnabled']?'Disable selection':'Enable selection' ?></button>
            </form>
            <?php endforeach; ?>
        <?php elseif ($labWorkspaceTab === 'lab-center'): ?>
            <?php if ($labCenters === []): ?>
                <div class="empty-state-box">
                    <h3>No lab centers configured</h3>
                    <p>Create at least one active lab center before managing test-to-center mappings.</p>
                </div>
            <?php else: ?>
                <form method="post" class="setup-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="workspace_action" value="lab_add_center_test">
                    <label for="mapping-center">Lab center</label><select id="mapping-center" name="LabCenterID" required><?php foreach($labCenters as $center): ?><option value="<?= (int)$center['LabCenterID'] ?>"><?= tdc_e($center['CenterName']) ?></option><?php endforeach; ?></select>
                    <label for="mapping-test">Test to map</label><select id="mapping-test" name="TestID" required><?php foreach($labTests as $test): ?><option value="<?= (int)$test['TestID'] ?>"><?= tdc_e($test['TestName']) ?></option><?php endforeach; ?></select>
                    <label for="mapping-active">Mapping status</label><select id="mapping-active" name="IsActive"><option value="1">Active</option><option value="0">Inactive</option></select>
                    <button class="btn btn-primary">Save center-test mapping</button>
                </form>
                <ul><?php foreach($pdo->query('SELECT ct.IsActive,c.CenterName,t.TestName FROM lab_center_tests ct JOIN lab_centers c ON c.LabCenterID=ct.LabCenterID JOIN lab_tests t ON t.TestID=ct.TestID ORDER BY c.CenterName,t.TestName')->fetchAll() as $mapping): ?><li><?= tdc_e($mapping['CenterName'].' / '.$mapping['TestName'].' / '.($mapping['IsActive']?'Active':'Inactive')) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="lab-center">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search centers">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="center-modal">+ Add Lab Center</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Center Name</th><th>Location</th><th>Phone</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labCentersFiltered as $center): ?><tr><td><?= (int) $center['LabCenterID'] ?></td><td><?= tdc_e((string) $center['CenterName']) ?></td><td><?= tdc_e((string) ($center['Location'] ?? '')) ?></td><td><?= tdc_e((string) ($center['Phone'] ?? '')) ?></td><td><?= tdc_e((int) $center['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-center="<?= (int) $center['LabCenterID'] ?>" data-center-name="<?= tdc_e((string) $center['CenterName']) ?>" data-center-location="<?= tdc_e((string) ($center['Location'] ?? '')) ?>" data-center-phone="<?= tdc_e((string) ($center['Phone'] ?? '')) ?>" data-center-active="<?= (int) ($center['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_center"><input type="hidden" name="LabCenterID" value="<?= (int) $center['LabCenterID'] ?>"><input type="hidden" name="CenterName" value="<?= tdc_e((string) $center['CenterName']) ?>"><input type="hidden" name="Location" value="<?= tdc_e((string) ($center['Location'] ?? '')) ?>"><input type="hidden" name="Phone" value="<?= tdc_e((string) ($center['Phone'] ?? '')) ?>"><input type="hidden" name="IsActive" value="<?= (int) $center['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $center['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $center['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="center-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Lab Center</h3><button type="button" class="modal-close" data-close-modal="center-modal">×</button></div>
                    <form method="post" id="center-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_center">
                        <input type="hidden" name="LabCenterID" id="center-id" value="0">
                        <div class="form-group"><label>Center Name</label><input type="text" name="CenterName" id="center-name" required></div>
                        <div class="form-row"><div class="form-group"><label>Location</label><input type="text" name="Location" id="center-location"></div><div class="form-group"><label>Phone</label><input type="text" name="Phone" id="center-phone"></div></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="center-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="center-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'units'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="units">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search units">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="unit-modal">+ Add Unit</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Unit Name</th><th>Symbol</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labUnitsFiltered as $unit): ?><tr><td><?= (int) $unit['UnitID'] ?></td><td><?= tdc_e((string) $unit['UnitName']) ?></td><td><?= tdc_e((string) $unit['UnitSymbol']) ?></td><td><?= tdc_e((int) $unit['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-unit="<?= (int) $unit['UnitID'] ?>" data-unit-name="<?= tdc_e((string) $unit['UnitName']) ?>" data-unit-symbol="<?= tdc_e((string) $unit['UnitSymbol']) ?>" data-unit-active="<?= (int) ($unit['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_unit"><input type="hidden" name="UnitID" value="<?= (int) $unit['UnitID'] ?>"><input type="hidden" name="UnitName" value="<?= tdc_e((string) $unit['UnitName']) ?>"><input type="hidden" name="UnitSymbol" value="<?= tdc_e((string) $unit['UnitSymbol']) ?>"><input type="hidden" name="IsActive" value="<?= (int) $unit['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $unit['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $unit['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="unit-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Unit</h3><button type="button" class="modal-close" data-close-modal="unit-modal">×</button></div>
                    <form method="post" id="unit-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_unit">
                        <input type="hidden" name="UnitID" id="unit-id" value="0">
                        <div class="form-row"><div class="form-group"><label>Unit Name</label><input type="text" name="UnitName" id="unit-name" required></div><div class="form-group"><label>Symbol</label><input type="text" name="UnitSymbol" id="unit-symbol" required></div></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="unit-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="unit-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'flags'): ?>
            <div class="section-toolbar">
                <form method="get" class="filter-box">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="flags">
                    <input type="text" name="q" value="<?= tdc_e($labSearchTerm) ?>" placeholder="Search flags">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
                <button type="button" class="btn btn-primary" data-open-modal="flag-modal">+ Add Flag</button>
            </div>
            <div class="data-table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Flag</th><th>Code</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($labFlagsFiltered as $flag): ?><tr><td><?= (int) $flag['FlagID'] ?></td><td><?= tdc_e((string) $flag['FlagName']) ?></td><td><?= tdc_e((string) $flag['FlagCode']) ?></td><td><?= tdc_e((string) ($flag['Description'] ?? '')) ?></td><td><?= tdc_e((int) $flag['IsActive'] === 1 ? 'Active' : 'Inactive') ?></td><td><div class="row-actions"><button type="button" class="btn btn-secondary btn-sm" data-fill-flag="<?= (int) $flag['FlagID'] ?>" data-flag-name="<?= tdc_e((string) $flag['FlagName']) ?>" data-flag-code="<?= tdc_e((string) $flag['FlagCode']) ?>" data-flag-description="<?= tdc_e((string) ($flag['Description'] ?? '')) ?>" data-flag-active="<?= (int) ($flag['IsActive'] ?? 0) ?>">Edit</button><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="workspace_action" value="lab_update_flag"><input type="hidden" name="FlagID" value="<?= (int) $flag['FlagID'] ?>"><input type="hidden" name="FlagName" value="<?= tdc_e((string) $flag['FlagName']) ?>"><input type="hidden" name="FlagCode" value="<?= tdc_e((string) $flag['FlagCode']) ?>"><input type="hidden" name="Description" value="<?= tdc_e((string) ($flag['Description'] ?? '')) ?>"><input type="hidden" name="IsActive" value="<?= (int) $flag['IsActive'] === 1 ? 0 : 1 ?>"><button type="submit" class="btn <?= (int) $flag['IsActive'] === 1 ? 'btn-danger' : 'btn-success' ?> btn-sm"><?= (int) $flag['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?></tbody></table></div>
            <div class="modal-overlay" id="flag-modal">
                <div class="modal-box">
                    <div class="modal-head"><h3>Add Flag</h3><button type="button" class="modal-close" data-close-modal="flag-modal">×</button></div>
                    <form method="post" id="flag-form">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="workspace_action" value="lab_add_flag">
                        <input type="hidden" name="FlagID" id="flag-id" value="0">
                        <div class="form-row"><div class="form-group"><label>Flag Name</label><input type="text" name="FlagName" id="flag-name" required></div><div class="form-group"><label>Code</label><input type="text" name="FlagCode" id="flag-code" required></div></div>
                        <div class="form-group"><label>Description</label><textarea name="Description" id="flag-description"></textarea></div>
                        <label class="check-control"><input type="checkbox" name="IsActive" id="flag-active" checked> Active</label>
                        <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-modal="flag-modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>
        <?php elseif ($labWorkspaceTab === 'print-result'): ?>
            <?php
                $printResultQ = trim((string) ($_GET['q'] ?? ''));
                $printResultStatus = (string) ($_GET['print_result_status'] ?? 'all');
                $printResultFrom = trim((string) ($_GET['from_date'] ?? ''));
                $printResultTo = trim((string) ($_GET['to_date'] ?? ''));
                $allowedPrintableStatuses = ['Completed', 'Reviewed'];
                $printResultConditions = [
                    'EXISTS (SELECT 1 FROM lab_order_catalog_bridge b JOIN lab_results r ON r.BridgeID = b.BridgeID WHERE b.LaboratoryID = l.LaboratoryID AND b.IsActive = 1 AND r.ResultStatus IN (\'Completed\', \'Reviewed\'))'
                ];
                $printResultParams = [];

                if ($printResultQ !== '') {
                    $printResultConditions[] = '(l.LaboratoryID LIKE :print_q_lab OR p.PatientName LIKE :print_q_patient OR b.ModernTestNameSnapshot LIKE :print_q_test)';
                    $printResultParams['print_q_lab'] = '%' . $printResultQ . '%';
                    $printResultParams['print_q_patient'] = '%' . $printResultQ . '%';
                    $printResultParams['print_q_test'] = '%' . $printResultQ . '%';
                }
                if ($printResultStatus !== '' && $printResultStatus !== 'all') {
                    $printResultConditions[] = 'EXISTS (SELECT 1 FROM lab_order_catalog_bridge b2 JOIN lab_results r2 ON r2.BridgeID = b2.BridgeID WHERE b2.LaboratoryID = l.LaboratoryID AND b2.IsActive = 1 AND r2.ResultStatus = :print_status_filter)';
                    $printResultParams['print_status_filter'] = $printResultStatus;
                }
                if ($printResultFrom !== '') {
                    $printResultConditions[] = 'EXISTS (SELECT 1 FROM lab_order_catalog_bridge b3 JOIN lab_results r3 ON r3.BridgeID = b3.BridgeID WHERE b3.LaboratoryID = l.LaboratoryID AND b3.IsActive = 1 AND DATE(r3.CompletedAt) >= :print_from_date)';
                    $printResultParams['print_from_date'] = $printResultFrom;
                }
                if ($printResultTo !== '') {
                    $printResultConditions[] = 'EXISTS (SELECT 1 FROM lab_order_catalog_bridge b4 JOIN lab_results r4 ON r4.BridgeID = b4.BridgeID WHERE b4.LaboratoryID = l.LaboratoryID AND b4.IsActive = 1 AND DATE(r4.CompletedAt) <= :print_to_date)';
                    $printResultParams['print_to_date'] = $printResultTo;
                }

                $printResultWhere = 'WHERE ' . implode(' AND ', $printResultConditions);
                $printResultSql =
                    'SELECT l.LaboratoryID, p.PatientName, d.DoctorName, '
                    . 'GROUP_CONCAT(DISTINCT b.ModernTestNameSnapshot ORDER BY b.DisplayOrder SEPARATOR ", ") AS TestNames, '
                    . 'MAX(CASE WHEN r.ResultStatus = \'Reviewed\' THEN \'Reviewed\' WHEN r.ResultStatus = \'Completed\' THEN \'Completed\' ELSE NULL END) AS ResultStatus, '
                    . 'MAX(COALESCE(r.CompletedAt, l.ResultDate)) AS CompletedAt '
                    . 'FROM laboratory l '
                    . 'LEFT JOIN patients p ON p.PatientID = l.PatientID '
                    . 'LEFT JOIN doctors d ON d.DoctorID = l.DoctorID '
                    . 'LEFT JOIN lab_order_catalog_bridge b ON b.LaboratoryID = l.LaboratoryID AND b.IsActive = 1 '
                    . 'LEFT JOIN lab_results r ON r.BridgeID = b.BridgeID '
                    . $printResultWhere . ' '
                    . 'GROUP BY l.LaboratoryID, p.PatientName, d.DoctorName '
                    . 'ORDER BY CompletedAt DESC, l.LaboratoryID DESC';
                $printResultStmt = $pdo->prepare($printResultSql);
                $printResultStmt->execute($printResultParams);
                $printResultRows = $printResultStmt->fetchAll();

                $readyToPrintCount = (int) $pdo->query("SELECT COUNT(*) FROM lab_results r JOIN lab_order_catalog_bridge b ON b.BridgeID = r.BridgeID WHERE b.IsActive = 1 AND r.ResultStatus IN ('Completed', 'Reviewed')")->fetchColumn();
                $reviewedResultsCount = (int) $pdo->query("SELECT COUNT(*) FROM lab_results WHERE ResultStatus = 'Reviewed'")->fetchColumn();
                $printedResultsLabel = 'Not available';
            ?>

            <div class="setup-card-grid">
                <div class="setup-module-card"><strong><?= (int) $readyToPrintCount ?></strong><small>Ready to Print</small></div>
                <div class="setup-module-card"><strong><?= (int) $reviewedResultsCount ?></strong><small>Reviewed Results</small></div>
                <div class="setup-module-card"><strong><?= tdc_e($printedResultsLabel) ?></strong><small>Printed Results</small></div>
            </div>

            <div class="section-toolbar" style="margin-top:16px;">
                <form method="get" class="filter-box" style="max-width:760px;">
                    <input type="hidden" name="workspace" value="1">
                    <input type="hidden" name="lab_tab" value="print-result">
                    <input type="text" name="q" value="<?= tdc_e($printResultQ) ?>" placeholder="Search Lab ID, patient, or test">
                    <select name="print_result_status">
                        <option value="all" <?= $printResultStatus === 'all' ? 'selected' : '' ?>>All Printable</option>
                        <option value="Completed" <?= $printResultStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Reviewed" <?= $printResultStatus === 'Reviewed' ? 'selected' : '' ?>>Reviewed</option>
                    </select>
                    <input type="date" name="from_date" value="<?= tdc_e($printResultFrom) ?>" aria-label="From date">
                    <input type="date" name="to_date" value="<?= tdc_e($printResultTo) ?>" aria-label="To date">
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                    <?php if ($printResultQ !== '' || $printResultStatus !== 'all' || $printResultFrom !== '' || $printResultTo !== ''): ?>
                        <a class="btn btn-secondary btn-sm" href="laboratory.php?workspace=1&amp;lab_tab=print-result">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($printResultRows === []): ?>
                <div class="empty-state-box">
                    <h3>No completed or reviewed laboratory results are ready to print.</h3>
                    <p>Completed and reviewed results appear here when a valid modern result record is available.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Lab ID</th><th>Patient</th><th>Doctor</th><th>Test(s)</th><th>Result Status</th><th>Completed Date</th><th>Print Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($printResultRows as $row): ?>
                                <?php
                                    $printLabId = (string) ($row['LaboratoryID'] ?? '');
                                    $printResultStatusValue = (string) ($row['ResultStatus'] ?? 'Completed');
                                    $printCompletedAt = $row['CompletedAt'] ?? '';
                                    $printStatusText = 'Available';
                                ?>
                                <tr>
                                    <td><?= tdc_e($printLabId) ?></td>
                                    <td><?= tdc_e((string) ($row['PatientName'] ?? 'Unknown')) ?></td>
                                    <td><?= tdc_e((string) ($row['DoctorName'] ?? '—')) ?></td>
                                    <td><?= tdc_e((string) ($row['TestNames'] ?? '—')) ?></td>
                                    <td><span class="status-badge <?= $printResultStatusValue === 'Reviewed' ? 'info' : 'success' ?>"><?= tdc_e($printResultStatusValue) ?></span></td>
                                    <td><?= tdc_e($printCompletedAt !== '' ? date('Y-m-d', strtotime((string) $printCompletedAt)) : '—') ?></td>
                                    <td><span class="status-badge neutral"><?= tdc_e($printStatusText) ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn btn-secondary btn-sm" target="_blank" rel="noopener" href="../print_laboratory.php?result=1&amp;ref=<?= urlencode($printLabId) ?>">View Result</a>
                                            <a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="../print_laboratory.php?result=1&amp;ref=<?= urlencode($printLabId) ?>">Print A4</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <table class="data-table"><thead><tr><th>Laboratory ID</th><th>Patient</th><th>Tests</th><th>Result</th></tr></thead><tbody>
            <?php foreach($labLegacyOrders as $printOrder): if($printOrder['WorkflowStatus']!=='Completed')continue; ?>
            <tr><td><?= tdc_e($printOrder['LaboratoryID']) ?></td><td><?= tdc_e($printOrder['PatientName']) ?></td><td><?= tdc_e($printOrder['TestName']) ?></td><td><a target="_blank" rel="noopener" href="../print_laboratory.php?result=1&amp;ref=<?= urlencode($printOrder['LaboratoryID']) ?>">Print Result</a></td></tr>
            <?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

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

    <?php if ($labWorkspaceTab === 'test-result-entry' && !empty($labLegacyOrders)): ?>
    <div class="workspace-panel-heading" style="margin-top:24px;">
        <div>
            <h2>Historical Legacy Results</h2>
            <p>Archived legacy records are kept for reference; the active laboratory workflow uses the modern queue only.</p>
        </div>
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Legacy Lab ID</th><th>Patient</th><th>Test</th><th>Result</th><th>Workflow</th><th>Payment</th><th>Order Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($labLegacyOrders as $legacy): ?>
                <tr>
                    <td><?= tdc_e((string) ($legacy['LaboratoryID'] ?? '')) ?></td>
                    <td><?= tdc_e((string) ($legacy['PatientName'] ?? '')) ?></td>
                    <td><?= tdc_e((string) ($legacy['TestName'] ?? '')) ?></td>
                    <td><?= tdc_e((string) ($legacy['Result'] ?? 'Pending')) ?></td>
                    <td><?= tdc_badge((string) ($legacy['WorkflowStatus'] ?? 'Unknown')) ?></td>
                    <td><?= tdc_badge((string) ($legacy['PaymentStatus'] ?? 'Unknown')) ?></td>
                    <td><?= tdc_e((string) ($legacy['OrderDate'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

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
                        <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Save results</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</main>

<div class="modal-overlay" id="labResultModal" aria-hidden="true"><div id="labResultModalContent"></div></div>

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

<?php if ($labToast !== ''): ?>
showToast(<?= json_encode($labToast) ?>);
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

const modernResultModal = document.getElementById('labResultModal');
const modernResultContent = document.getElementById('labResultModalContent');
function closeModernResultModal(){ if(!modernResultModal)return; modernResultModal.classList.remove('show'); modernResultModal.setAttribute('aria-hidden','true'); modernResultContent.innerHTML=''; document.body.classList.remove('modal-open'); }
document.addEventListener('click', async function(event){
    const openButton = event.target.closest('[data-lab-result-open]');
    if(openButton){
        const id = openButton.dataset.laboratoryId;
        modernResultContent.innerHTML='<div class="lab-result-modal-card"><div class="lab-result-modal-body">Loading laboratory result…</div></div>';
        modernResultModal.classList.add('show'); modernResultModal.setAttribute('aria-hidden','false'); document.body.classList.add('modal-open');
        try { const mode=openButton.dataset.resultMode || 'edit'; const response=await fetch('laboratory.php?workspace=1&lab_tab=test-result-entry&result='+encodeURIComponent(id)+'&modal=1&result_mode='+encodeURIComponent(mode),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}); if(!response.ok)throw new Error('Unable to load result'); modernResultContent.innerHTML=await response.text(); if(mode==='view'){ modernResultContent.querySelectorAll('input:not([type="hidden"]),textarea').forEach(function(field){ const value=document.createElement('div'); value.className='lab-readonly-value'; value.textContent=field.value || field.textContent || '—'; field.replaceWith(value); }); } }
        catch(error){ modernResultContent.innerHTML='<div class="lab-result-modal-card"><div class="lab-result-modal-body">The laboratory result could not be loaded. Please try again.</div></div>'; }
        return;
    }
    if(event.target.closest('[data-lab-result-close]') || event.target===modernResultModal) closeModernResultModal();
});
document.addEventListener('submit', async function(event){
    const form = event.target.closest('.lab-modal-form');
    if(!form) return;
    event.preventDefault();
    const submitter = event.submitter;
    if(submitter && submitter.name) { const hidden=document.createElement('input'); hidden.type='hidden'; hidden.name=submitter.name; hidden.value=submitter.value; form.appendChild(hidden); }
    let response;
    try { response=await fetch('laboratory.php',{method:'POST',body:new FormData(form),credentials:'same-origin'}); if(!response.ok){ const payload=await response.json(); throw new Error((payload.errors||[]).join(' ')); } closeModernResultModal(); window.location.reload(); }
    catch(error){ const notice=document.createElement('p'); notice.className='form-error'; notice.textContent=error.message || 'The result could not be saved. Please try again.'; form.prepend(notice); }
});
document.addEventListener('submit', function(event){
    const form = event.target.closest('form.inline-form');
    const activeField = form && form.querySelector('input[name="IsActive"][value="0"]');
    if(!activeField) return;
    const label = form.closest('tr')?.querySelector('td:nth-child(2)')?.textContent.trim() || 'this laboratory item';
    if(!window.confirm('Deactivate '+label+'?\n\nIt will no longer be available for new ordering. Historical orders and results will be preserved.')) event.preventDefault();
}, true);
document.addEventListener('keydown', function(event){ if(event.key==='Escape' && modernResultModal && modernResultModal.classList.contains('show')) closeModernResultModal(); });
</script>

</body>
</html>
