<?php
/**
 * auth/pages/reception.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Reception Desk
 * ---------------------------------------------------------------------
 * Handles three related entities from a single, self-contained page,
 * matching the "reception = patient registration, laboratory bills,
 * pharmacy bills" grouping used across the app's navigation:
 *
 *   1. Patient Registration  -> Patients table   (modal add/edit)
 *   2. Laboratory Bills       -> Laboratory table  (modal add/edit)
 *   3. Pharmacy Bills         -> Prescriptions table (full-page,
 *      multi-line form — one bill can contain several medication
 *      lines, so a modal doesn't fit; see SECTION 5C for the grouping
 *      convention used since Prescriptions has no bill/header column).
 *
 * Security controls (same posture as home.php / settings.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: only 'superuser' and 'receptionuser' may use this page
 *   - CSRF-token-checked POST handlers, rotated on every submit
 *   - Prepared statements only — no string-built SQL from user input
 *   - Post/Redirect/Get on every successful write
 *   - All session/user-derived output escaped before hitting HTML
 *
 * Schema notes / assumptions (see chat reply for full detail):
 *   - Laboratory.TestID has no catalog table in this codebase, so it
 *     is auto-generated as a simple running integer (SECTION 5B).
 *   - Prescriptions has no column to group multiple medication lines
 *     into one bill. This page encodes the grouping in the primary
 *     key itself: PrescriptionID = "RX000123-01", "RX000123-02", ...
 *     All lines sharing the "RX000123" prefix are one Pharmacy Bill.
 *   - The printable prescription slip (print_prescription.php) reads
 *     optional Quantity / Route columns on Prescriptions. This page
 *     writes them too, but only if you've run the small migration
 *     noted in my reply — everything else works without it.
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
require_once __DIR__ . '/../includes/patient-age.php';
require_once __DIR__ . '/../includes/ui.php';
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_SECTIONS      = ['patients', 'consultations', 'laboratory', 'pharmacy'];
const ALLOWED_RECEPTION_ROLES = ['superuser', 'receptionuser'];

const GENDER_OPTIONS = [
    'Male'   => 'Male',
    'Female' => 'Female',
];

const PATIENT_TYPE_OPTIONS = [
    'New Patient'       => 'New Patient',
    'Returning Patient' => 'Returning Patient',
    'Referral'          => 'Referral',
    'Emergency'         => 'Emergency',
];

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
 * home.php / settings.php. Flat, single-link items only.
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

/** Strict Y-m-d date validator (rejects "2026-02-31" style overflow dates). */
function tdc_is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function tdc_age_from_birth_date(string $date): int
{
    return (new DateTimeImmutable($date))->diff(new DateTimeImmutable('today'))->y;
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
    header('Location: reception.php?section=' . urlencode($section) . '&' . $flag . '=1');
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

/**
 * Generates the next Pharmacy Bill base reference, e.g. "RX000123".
 * Individual medication lines are stored as "RX000123-01", "-02", etc.
 * (see SECTION 5C for why this convention exists instead of a real
 * grouping column).
 */
function tdc_next_prescription_base(PDO $pdo): string
{
    $last = tdc_scalar(
        $pdo,
        "SELECT PrescriptionID FROM prescriptions WHERE PrescriptionID LIKE 'RX%' ORDER BY LENGTH(PrescriptionID) DESC, PrescriptionID DESC LIMIT 1"
    );

    $next = 1;
    if ($last !== false && $last !== null) {
        $base = strtok((string) $last, '-'); // "RXxxxxxx"
        $next = ((int) substr($base, 2)) + 1;
    }

    return 'RX' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/** True if a column exists on a table. Used to degrade gracefully before an optional migration is run. */
function tdc_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :col");
        $stmt->execute(['col' => $column]);
        $cache[$key] = $stmt->fetch() !== false;
    }
    return $cache[$key];
}

// =======================================================================
// SECTION 4 — Validation functions
// =======================================================================

/** @param array{PatientName:string,PatientPhone:string,Gender:string,Age:string,DateOfBirth:string,PatientType:string,AllocatedDoctor:string} $input */
function tdc_validate_patient_form(array $input): array
{
    $errors = [];

    if ($input['PatientName'] === '' || mb_strlen($input['PatientName']) < 2 || mb_strlen($input['PatientName']) > 150) {
        $errors[] = 'Patient name is required (2-150 characters).';
    }
    if ($input['PatientPhone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $input['PatientPhone'])) {
        $errors[] = 'Phone number format is invalid.';
    }
    if ($input['Gender'] !== '' && !array_key_exists($input['Gender'], GENDER_OPTIONS)) {
        $errors[] = 'Please select a valid gender.';
    }
    if ($input['Age'] !== '' && (!ctype_digit($input['Age']) || (int) $input['Age'] > 150)) {
        $errors[] = 'Age must be a whole number between 0 and 150.';
    }
    if ($input['DateOfBirth'] !== '' && !tdc_is_valid_date($input['DateOfBirth'])) {
        $errors[] = 'Date of birth is not a valid date.';
    } elseif ($input['DateOfBirth'] !== '' && $input['DateOfBirth'] > date('Y-m-d')) {
        $errors[] = 'Date of birth cannot be in the future.';
    }
    if ($input['PatientType'] !== '' && !array_key_exists($input['PatientType'], PATIENT_TYPE_OPTIONS)) {
        $errors[] = 'Please select a valid patient type.';
    }
    if ($input['AllocatedDoctor'] !== '' && !ctype_digit($input['AllocatedDoctor'])) {
        $errors[] = 'Please select a valid doctor.';
    }

    return $errors;
}

/** @param array{PatientID:string,TestName:string,Price:string,AmountPaid:string,PaymentStatus:string,Result:string} $input */
function tdc_validate_lab_form(array $input): array
{
    $errors = [];

    if ($input['PatientID'] === '' || !ctype_digit($input['PatientID'])) {
        $errors[] = 'Please select a valid patient.';
    }
    if ($input['TestName'] === '' || mb_strlen($input['TestName']) > 150) {
        $errors[] = 'Test name is required (max 150 characters).';
    }
    if ($input['Price'] === '' || !is_numeric($input['Price']) || (float) $input['Price'] < 0) {
        $errors[] = 'Price must be a valid non-negative number.';
    }
    if (!array_key_exists($input['PaymentStatus'], PAYMENT_STATUS_OPTIONS)) {
        $errors[] = 'Please select a valid payment status.';
    }
    $paid = is_numeric($input['AmountPaid'] ?? null) ? (float) $input['AmountPaid'] : null;
    $total = is_numeric($input['Price']) ? (float) $input['Price'] : 0.0;
    if ($paid === null || $paid < 0 || $paid > $total) {
        $errors[] = 'Amount paid must be between zero and the laboratory fee.';
    } elseif ($input['PaymentStatus'] === 'Partial' && ($paid <= 0 || $paid >= $total)) {
        $errors[] = 'A partial payment must be greater than zero and less than the laboratory fee.';
    }
    if ($input['Result'] !== '' && !array_key_exists($input['Result'], LAB_RESULT_OPTIONS)) {
        $errors[] = 'Please select a valid result.';
    }

    return $errors;
}

/** @param array{PatientID:string,DoctorID:string,TotalAmount:string,AmountPaid:string,MedicationName:array} $input */
function tdc_validate_pharmacy_form(array $input): array
{
    $errors = [];

    if ($input['PatientID'] === '' || !ctype_digit($input['PatientID'])) {
        $errors[] = 'Please select a valid patient.';
    }
    if ($input['DoctorID'] === '' || !ctype_digit($input['DoctorID'])) {
        $errors[] = 'Please select the prescribing doctor.';
    }
    if ($input['TotalAmount'] === '' || !is_numeric($input['TotalAmount']) || (float) $input['TotalAmount'] < 0) {
        $errors[] = 'Total amount must be a valid non-negative number.';
    }
    if ($input['AmountPaid'] === '' || !is_numeric($input['AmountPaid']) || (float) $input['AmountPaid'] < 0) {
        $errors[] = 'Amount paid must be a valid non-negative number.';
    }
    if (is_numeric($input['TotalAmount'] ?? null) && is_numeric($input['AmountPaid'] ?? null)
        && (float) $input['AmountPaid'] > (float) $input['TotalAmount']) {
        $errors[] = 'Amount paid cannot exceed the total amount.';
    }

    $medications = $input['MedicationName'] ?? [];
    $hasLine     = false;
    foreach ($medications as $i => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $hasLine = true;
        if (mb_strlen($name) > 150) {
            $errors[] = 'Medication name at line ' . ($i + 1) . ' is too long (max 150 characters).';
        }
        $qty = trim((string) ($input['Quantity'][$i] ?? ''));
        if ($qty !== '' && (!ctype_digit($qty) || (int) $qty < 1)) {
            $errors[] = 'Quantity at line ' . ($i + 1) . ' must be a positive whole number.';
        }
    }
    if (!$hasLine) {
        $errors[] = 'Add at least one medication line.';
    }

    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence functions
// =======================================================================

// --- 5A. Patients -------------------------------------------------------

function tdc_save_patient(PDO $pdo, array $input, bool $isEdit, int $editId): void
{
    $params = [
        'PatientName'     => $input['PatientName'],
        'PatientPhone'    => $input['PatientPhone'] !== '' ? $input['PatientPhone'] : null,
        'PatientAddress'  => $input['PatientAddress'] !== '' ? $input['PatientAddress'] : null,
        'Gender'          => $input['Gender'] !== '' ? $input['Gender'] : null,
        'Age'             => $input['Age'] !== '' ? (int) $input['Age'] : null,
        'DateOfBirth'     => $input['DateOfBirth'] !== '' ? $input['DateOfBirth'] : null,
        'PatientType'     => $input['PatientType'] !== '' ? $input['PatientType'] : null,
        'AllocatedDoctor' => $input['AllocatedDoctor'] !== '' ? (int) $input['AllocatedDoctor'] : null,
        'Remark'          => $input['Remark'] !== '' ? $input['Remark'] : null,
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE patients SET PatientName = :PatientName, PatientPhone = :PatientPhone,
                PatientAddress = :PatientAddress, Gender = :Gender, Age = :Age,
                DateOfBirth = :DateOfBirth, PatientType = :PatientType,
                AllocatedDoctor = :AllocatedDoctor, Remark = :Remark
             WHERE PatientID = :id'
        );
        $stmt->execute($params);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO patients (PatientName, PatientPhone, PatientAddress, Gender, Age,
            DateOfBirth, PatientType, AllocatedDoctor, Remark, VisitNumber, DueBalance)
         VALUES (:PatientName, :PatientPhone, :PatientAddress, :Gender, :Age,
            :DateOfBirth, :PatientType, :AllocatedDoctor, :Remark, 1, 0.00)'
    );
    $stmt->execute($params);
}

/** @return string[] error messages; empty on success */
function tdc_delete_patient(PDO $pdo, int $id): array
{
    // App-level referential guard: the schema has no FK constraints,
    // so we check dependents ourselves before allowing a delete.
    $labCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM laboratory WHERE PatientID = :id', ['id' => $id]);
    $rxCount  = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM prescriptions WHERE PatientID = :id', ['id' => $id]);
    $visitCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM visits WHERE PatientID = :id', ['id' => $id]);
    $paymentCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM payments WHERE PatientID = :id', ['id' => $id]);

    if ($labCount > 0 || $rxCount > 0 || $visitCount > 0 || $paymentCount > 0) {
        return ['This patient has clinical or financial history and cannot be deleted.'];
    }

    $stmt = $pdo->prepare('DELETE FROM patients WHERE PatientID = :id');
    $stmt->execute(['id' => $id]);
    return [];
}

// --- 5B. Laboratory ------------------------------------------------------

function tdc_save_lab(PDO $pdo, array $input, bool $isEdit, string $editId): void
{
    $total = round((float) $input['Price'], 2);
    $paid = round((float) ($input['AmountPaid'] ?? 0), 2);
    if ($input['PaymentStatus'] === 'Paid') $paid = $total;
    if ($input['PaymentStatus'] === 'Unpaid') $paid = 0.0;
    $workflow = $input['PaymentStatus'] === 'Paid' ? 'Ready' : 'Awaiting Payment';

    if (in_array($_SESSION['role'], ['receptionuser', 'superuser'], true) && $isEdit) {
        $stmt = $pdo->prepare('SELECT PatientID,PaymentStatus,AmountPaid,TotalAmount FROM laboratory WHERE LaboratoryID=?');
        $stmt->execute([$editId]);
        $before = $stmt->fetch();
        $stmt = $pdo->prepare('UPDATE laboratory SET PatientID=?,TestName=?,TotalAmount=?,AmountPaid=?,DueBalance=?,PaymentStatus=?,WorkflowStatus=? WHERE LaboratoryID=?');
        $stmt->execute([(int)$input['PatientID'],$input['TestName'],$total,$paid,max(0,$total-$paid),$input['PaymentStatus'],$workflow,$editId]);
        $newCollection = max(0, $paid - (float) ($before['AmountPaid'] ?? 0));
        if ($newCollection > 0) {
            $payRef=tdc_workflow_record_payment($pdo,(int)$input['PatientID'],'Laboratory',$newCollection,(int)$_SESSION['user_id'],['LaboratoryID'=>$editId]);
            if($payRef)tdc_workflow_post_revenue($pdo,'REV-LAB','Laboratory Revenue',$payRef,'Laboratory payment for '.$editId,$newCollection);
        }
        if($before && $before['PaymentStatus']!=='Paid' && $input['PaymentStatus']==='Paid'){
            tdc_workflow_notify($pdo,null,'labuser','lab_ready','Paid laboratory request ready',$editId.' is cleared for processing','laboratory.php?result=Pending&payment=Paid');
        }
        return;
    }

    if ($_SESSION['role'] === 'receptionuser') {
        $input['Result'] = 'Pending';
        $input['ResultDate'] = '';
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
        'ResultDate'    => $input['ResultDate'] !== '' ? $input['ResultDate'] : null,
        'PaymentStatus' => $input['PaymentStatus'],
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE laboratory SET PatientID = :PatientID, TestName = :TestName,
                Description = :Description, TotalAmount = :Price, IsAvailable = :IsAvailable,
                Result = :Result, ResultDate = :ResultDate, PaymentStatus = :PaymentStatus
             WHERE LaboratoryID = :id'
        );
        $stmt->execute($params);
        return;
    }

    // TestID has no catalog table in this codebase (see file header note),
    // so it is a simple running integer, unique enough for display purposes.
    $params['LaboratoryID'] = tdc_next_ref($pdo, 'laboratory', 'LaboratoryID', 'LAB');
    $params['TestID']       = (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(TestID), 0) + 1 FROM laboratory') ?: 1;

    $stmt = $pdo->prepare(
        'INSERT INTO laboratory (LaboratoryID, PatientID, TestID, TestName, Description,
            TotalAmount, AmountPaid, DueBalance, IsAvailable, Result, ResultDate, PaymentStatus, WorkflowStatus)
         VALUES (:LaboratoryID, :PatientID, :TestID, :TestName, :Description,
            :Price, :AmountPaid, :DueBalance, :IsAvailable, :Result, :ResultDate, :PaymentStatus, :WorkflowStatus)'
    );
    $stmt->execute($params);
    if ($paid > 0) {
        $payRef=tdc_workflow_record_payment($pdo,(int)$input['PatientID'],'Laboratory',$paid,(int)$_SESSION['user_id'],['LaboratoryID'=>$params['LaboratoryID']]);
        if($payRef)tdc_workflow_post_revenue($pdo,'REV-LAB','Laboratory Revenue',$payRef,'Laboratory payment for '.$params['LaboratoryID'],$paid);
    }
    if ($input['PaymentStatus'] === 'Paid') {
        tdc_workflow_notify($pdo,null,'labuser','lab_ready','Paid laboratory request ready',$params['LaboratoryID'].' is cleared for processing','laboratory.php?result=Pending&payment=Paid');
    }
}

function tdc_delete_lab(PDO $pdo, string $id): void
{
    $stmt = $pdo->prepare('DELETE FROM laboratory WHERE LaboratoryID = :id');
    $stmt->execute(['id' => $id]);
}

// --- 5C. Pharmacy (Prescriptions) ----------------------------------------
//
// Prescriptions has no column to group several medication lines into a
// single bill, so this page encodes the grouping in the primary key:
// one bill's lines are PrescriptionID = "{base}-01", "{base}-02", ...
// Editing a bill deletes and re-inserts its lines inside a transaction,
// which is safe here because a bill is always small (a handful of rows).

/** @throws RuntimeException if the patient no longer exists */
function tdc_fetch_patient_snapshot(PDO $pdo, int $patientId): array
{
    $stmt = $pdo->prepare('SELECT PatientID, PatientName, PatientPhone, PatientAddress, Gender, Age, VisitNumber FROM patients WHERE PatientID = :id');
    $stmt->execute(['id' => $patientId]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new RuntimeException('Selected patient no longer exists.');
    }
    return $row;
}

function tdc_save_pharmacy(PDO $pdo, array $input, bool $isEdit, string $editBase): void
{
    $patient = tdc_fetch_patient_snapshot($pdo, (int) $input['PatientID']);

    $totalAmount = round((float) $input['TotalAmount'], 2);
    $amountPaid  = round((float) $input['AmountPaid'], 2);
    $dueBalance  = round($totalAmount - $amountPaid, 2);

    $base = $isEdit ? $editBase : tdc_next_prescription_base($pdo);

    // Optional migration (see chat reply): ALTER TABLE prescriptions
    // ADD COLUMN Quantity INT DEFAULT 1, ADD COLUMN Route VARCHAR(50) NULL.
    $supportsQty   = tdc_table_has_column($pdo, 'prescriptions', 'Quantity');
    $supportsRoute = tdc_table_has_column($pdo, 'prescriptions', 'Route');

    $columns = ['PrescriptionID', 'PatientID', 'PatientName', 'PatientPhone', 'PatientAddress',
        'Gender', 'Age', 'VisitNumber', 'DoctorID', 'MedicationName', 'Dosage',
        'Frequency', 'Duration', 'Instructions', 'TotalAmount', 'AmountPaid', 'DueBalance'];
    if ($supportsQty) {
        $columns[] = 'Quantity';
    }
    if ($supportsRoute) {
        $columns[] = 'Route';
    }
    $placeholders = array_map(static fn (string $c) => ':' . $c, $columns);
    $insertSql    = 'INSERT INTO prescriptions (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

    $pdo->beginTransaction();
    try {
        if ($isEdit) {
            $del = $pdo->prepare('DELETE FROM prescriptions WHERE PrescriptionID LIKE :pattern');
            $del->execute(['pattern' => $base . '-%']);
        }

        $insert = $pdo->prepare($insertSql);

        $line = 0;
        foreach ($input['MedicationName'] as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue; // Skip blank rows the clerk left empty.
            }
            $line++;

            $row = [
                'PrescriptionID' => $base . '-' . str_pad((string) $line, 2, '0', STR_PAD_LEFT),
                'PatientID'      => $patient['PatientID'],
                'PatientName'    => $patient['PatientName'],
                'PatientPhone'   => $patient['PatientPhone'],
                'PatientAddress' => $patient['PatientAddress'],
                'Gender'         => $patient['Gender'],
                'Age'            => $patient['Age'],
                'VisitNumber'    => $patient['VisitNumber'],
                'DoctorID'       => (int) $input['DoctorID'],
                'MedicationName' => $name,
                'Dosage'         => trim((string) ($input['Dosage'][$i] ?? '')) ?: null,
                'Frequency'      => trim((string) ($input['Frequency'][$i] ?? '')) ?: null,
                'Duration'       => trim((string) ($input['Duration'][$i] ?? '')) ?: null,
                'Instructions'   => trim((string) ($input['Instructions'][$i] ?? '')) ?: null,
                'TotalAmount'    => $totalAmount,
                'AmountPaid'     => $amountPaid,
                'DueBalance'     => $dueBalance,
            ];
            if ($supportsQty) {
                $qty = trim((string) ($input['Quantity'][$i] ?? ''));
                $row['Quantity'] = $qty !== '' ? (int) $qty : 1;
            }
            if ($supportsRoute) {
                $row['Route'] = trim((string) ($input['Route'][$i] ?? '')) ?: null;
            }

            $insert->execute($row);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function tdc_delete_pharmacy_bill(PDO $pdo, string $base): void
{
    $stmt = $pdo->prepare('DELETE FROM prescriptions WHERE PrescriptionID LIKE :pattern');
    $stmt->execute(['pattern' => $base . '-%']);
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

tdc_require_permission('reception.view');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../includes/workflow.php';
$paymentMethods = tdc_payment_methods($pdo);
$paymentMethodNames = array_column($paymentMethods, 'MethodName');

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
$receptionSectionPermissions = ['patients'=>'patients.view','consultations'=>'consultations.view','laboratory'=>'lab_billing.view','pharmacy'=>'pharmacy_billing.view'];
if ($section !== null && !tdc_can($receptionSectionPermissions[$section])) tdc_forbidden();
$canPatientCreate = tdc_can('patients.create');
$canPatientEdit = tdc_can('patients.edit');
$canPatientDelete = tdc_can('patients.delete');
$canBookConsultation = tdc_can('consultations.create');
$canReceiveLabPayment = tdc_can('lab_billing.payment');
$canReceivePharmacyPayment = tdc_can('pharmacy_billing.payment');

$errors = [];

$oldPatient = [
    'PatientID' => '', 'PatientName' => '', 'PatientPhone' => '', 'PatientAddress' => '',
    'Gender' => '', 'Age' => '', 'DateOfBirth' => '', 'PatientType' => '',
    'AllocatedDoctor' => '', 'Remark' => '',
];

$oldLab = [
    'LaboratoryID' => '', 'PatientID' => '', 'PatientLabel' => '', 'TestName' => '',
    'Description' => '', 'Price' => '', 'IsAvailable' => '1', 'Result' => 'Pending',
    'ResultDate' => '', 'AmountPaid' => '0', 'PaymentStatus' => 'Unpaid',
];

$oldPharmacy = [
    'BillRef' => '', 'PatientID' => '', 'PatientLabel' => '', 'DoctorID' => '',
    'TotalAmount' => '', 'AmountPaid' => '',
    'MedicationName' => [], 'Dosage' => [], 'Frequency' => [], 'Duration' => [],
    'Instructions' => [], 'Quantity' => [], 'Route' => [],
];
$oldVisit = [
    'PatientID' => '', 'DoctorID' => '', 'VisitDate' => date('Y-m-d\TH:i'),
    'AmountPaid' => '0', 'PaymentMethod' => '', 'ChiefComplaint' => '',
];
if ($section === 'consultations' && isset($_GET['patient']) && ctype_digit((string) $_GET['patient'])) {
    $oldVisit['PatientID'] = (string) (int) $_GET['patient'];
}

$pharmacyShowForm = false; // true => render the pharmacy form instead of the bills list

// =======================================================================
// SECTION 9 — POST handler (dispatch by section)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($section, ALLOWED_SECTIONS, true)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        // --- Consultation booking ------------------------------------
        if ($section === 'consultations') {
            tdc_require_permission('consultations.create');
            if ($formAction === 'collect') {
                $visitId = ctype_digit((string) ($_POST['VisitID'] ?? '')) ? (int) $_POST['VisitID'] : 0;
                $collection = is_numeric($_POST['PaymentAmount'] ?? null) ? round((float) $_POST['PaymentAmount'], 2) : -1;
                $paymentMethod = (string) ($_POST['PaymentMethod'] ?? 'Cash');
                if ($visitId < 1) $errors[] = 'Please select a valid consultation.';
                if ($collection <= 0) $errors[] = 'Payment amount must be greater than zero.';
                if (!in_array($paymentMethod, $paymentMethodNames, true)) $errors[] = 'Please select a valid active payment method.';
                if (!$errors) {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare('SELECT v.*,d.UserID FROM visits v JOIN doctors d ON d.DoctorID=v.DoctorID WHERE v.VisitID=? FOR UPDATE');
                        $stmt->execute([$visitId]);
                        $visit = $stmt->fetch();
                        if (!$visit || $visit['QueueStatus'] !== 'Pending Payment' || (float) $visit['DueBalance'] <= 0) throw new RuntimeException('This consultation has no collectible balance.');
                        if ($collection > (float) $visit['DueBalance']) throw new RuntimeException('Payment cannot exceed the outstanding consultation balance.');
                        $newPaid = round((float) $visit['AmountPaid'] + $collection, 2);
                        $newDue = max(0, round((float) $visit['ConsultationFee'] - $newPaid, 2));
                        $paymentStatus = tdc_workflow_payment_status((float) $visit['ConsultationFee'], $newPaid);
                        $queueStatus = $paymentStatus === 'Paid' ? 'Waiting' : 'Pending Payment';
                        $stmt = $pdo->prepare('UPDATE visits SET AmountPaid=?,DueBalance=?,PaymentStatus=?,QueueStatus=? WHERE VisitID=?');
                        $stmt->execute([$newPaid,$newDue,$paymentStatus,$queueStatus,$visitId]);
                        $stmt = $pdo->prepare('UPDATE patients SET DueBalance=GREATEST(0,DueBalance-?) WHERE PatientID=?');
                        $stmt->execute([$collection,(int)$visit['PatientID']]);
                        $paymentRef = tdc_workflow_record_payment($pdo,(int)$visit['PatientID'],'Consultation',$collection,(int)$_SESSION['user_id'],['VisitID'=>$visitId,'PaymentMethod'=>$paymentMethod]);
                        if ($paymentRef) tdc_workflow_post_revenue($pdo,'REV-CONSULT','Consultation Revenue',$paymentRef,'Consultation payment for '.$visit['VisitReference'],$collection);
                        if ($paymentStatus === 'Paid') tdc_workflow_notify($pdo,(int)($visit['UserID'] ?? 0),'doctoruser','consultation_ready','Consultation ready',$visit['VisitReference'].' is fully paid and waiting','doctors.php?visit='.$visitId);
                        $pdo->commit();
                        tdc_redirect('consultations', 'payment');
                    } catch (RuntimeException $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = $e->getMessage();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('[RECEPTION][CONSULTATION PAYMENT] '.$e->getMessage());
                        $errors[] = 'The consultation payment could not be recorded. Please try again.';
                    }
                }
            } else {
            foreach (array_keys($oldVisit) as $field) $oldVisit[$field] = trim((string) ($_POST[$field] ?? $oldVisit[$field]));
            $patientId = ctype_digit($oldVisit['PatientID']) ? (int) $oldVisit['PatientID'] : 0;
            $doctorId = ctype_digit($oldVisit['DoctorID']) ? (int) $oldVisit['DoctorID'] : 0;
            $amountPaid = is_numeric($oldVisit['AmountPaid']) ? round((float) $oldVisit['AmountPaid'], 2) : -1;
            $visitDate = DateTime::createFromFormat('Y-m-d\TH:i', $oldVisit['VisitDate']);
            $stmt = $pdo->prepare('SELECT ConsultationFee,UserID FROM doctors WHERE DoctorID=?');
            $stmt->execute([$doctorId]);
            $doctor = $stmt->fetch();
            if ($patientId < 1 || !(int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM patients WHERE PatientID=:id', ['id'=>$patientId])) $errors[] = 'Please select a valid patient.';
            if (!$doctor) $errors[] = 'Please select a valid doctor.';
            elseif (empty($doctor['UserID'])) $errors[] = 'The selected doctor profile must be linked to a Doctor user account before booking.';
            if (!$visitDate || $visitDate->format('Y-m-d\TH:i') !== $oldVisit['VisitDate']) $errors[] = 'Please select a valid consultation date and time.';
            $fee = $doctor ? round((float) $doctor['ConsultationFee'], 2) : 0.0;
            if ($amountPaid < 0 || $amountPaid > $fee) $errors[] = 'Amount paid must be between zero and the consultation fee.';
            if ($amountPaid > 0 && !in_array($oldVisit['PaymentMethod'], $paymentMethodNames, true)) $errors[] = 'Please select an active payment method when receiving money.';
            if (!$errors) {
                $paymentStatus = tdc_workflow_payment_status($fee, $amountPaid);
                $queueStatus = $paymentStatus === 'Paid' ? 'Waiting' : 'Pending Payment';
                $reference = tdc_workflow_next_reference($pdo, 'visits', 'VisitReference', 'VIS');
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO visits (VisitReference,PatientID,DoctorID,ReceptionistUserID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus,ChiefComplaint) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$reference,$patientId,$doctorId,$_SESSION['user_id'],$visitDate->format('Y-m-d H:i:s'),$fee,$amountPaid,max(0,$fee-$amountPaid),$paymentStatus,$queueStatus,$oldVisit['ChiefComplaint'] ?: null]);
                    $visitId = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare('UPDATE patients SET AllocatedDoctor=?, VisitNumber=VisitNumber+1, DueBalance=DueBalance+? WHERE PatientID=?');
                    $stmt->execute([$doctorId,max(0,$fee-$amountPaid),$patientId]);
                    $paymentRef = tdc_workflow_record_payment($pdo,$patientId,'Consultation',$amountPaid,(int)$_SESSION['user_id'],['VisitID'=>$visitId,'PaymentMethod'=>$oldVisit['PaymentMethod'] ?: 'Cash']);
                    if ($paymentRef) tdc_workflow_post_revenue($pdo,'REV-CONSULT','Consultation Revenue',$paymentRef,'Consultation payment for '.$reference,$amountPaid);
                    if ($paymentStatus === 'Paid') tdc_workflow_notify($pdo,(int)($doctor['UserID'] ?? 0),'doctoruser','consultation_booked','New consultation booked',$reference.' is fully paid and waiting','doctors.php?visit='.$visitId);
                    $pdo->commit();
                    tdc_redirect('consultations', 'success');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('[RECEPTION][CONSULTATION] '.$e->getMessage());
                    $errors[] = 'The consultation could not be booked. Please try again.';
                }
            }
            }

        // --- Patients ------------------------------------------------
        } elseif ($section === 'patients') {
            if ($formAction === 'delete') {
                tdc_require_permission('patients.delete');
                $deleteId = (int) ($_POST['PatientID'] ?? 0);
                $errors   = $deleteId > 0 ? tdc_delete_patient($pdo, $deleteId) : ['Invalid patient selected.'];
                if (empty($errors)) {
                    tdc_redirect('patients', 'deleted');
                }
            } elseif ($formAction === 'visit') {
                tdc_require_permission('visits.create');
                $patientId = (int) ($_POST['PatientID'] ?? 0);
                header('Location: reception.php?section=consultations&patient=' . $patientId);
                exit;
            } else {
                $oldPatient['PatientID']       = trim((string) ($_POST['PatientID'] ?? ''));
                $oldPatient['PatientName']     = trim((string) ($_POST['PatientName'] ?? ''));
                $oldPatient['PatientPhone']    = trim((string) ($_POST['PatientPhone'] ?? ''));
                $oldPatient['PatientAddress']  = trim((string) ($_POST['PatientAddress'] ?? ''));
                $oldPatient['Gender']          = (string) ($_POST['Gender'] ?? '');
                $oldPatient['Age']             = trim((string) ($_POST['Age'] ?? ''));
                $oldPatient['DateOfBirth']     = trim((string) ($_POST['DateOfBirth'] ?? ''));
                [$oldPatient['Age'], $oldPatient['DateOfBirth']] = tdc_sync_age_dob($oldPatient['Age'], $oldPatient['DateOfBirth']);
                $oldPatient['PatientType']     = (string) ($_POST['PatientType'] ?? '');
                $oldPatient['AllocatedDoctor'] = trim((string) ($_POST['AllocatedDoctor'] ?? ''));
                $oldPatient['Remark']          = trim((string) ($_POST['Remark'] ?? ''));

                $isEdit = $oldPatient['PatientID'] !== '' && ctype_digit($oldPatient['PatientID']);
                tdc_require_permission($isEdit ? 'patients.edit' : 'patients.create');
                $errors = tdc_validate_patient_form($oldPatient);
                if (!$isEdit && $oldPatient['PatientPhone'] !== '') {
                    $stmt = $pdo->prepare('SELECT PatientID,PatientName FROM patients WHERE REPLACE(REPLACE(REPLACE(PatientPhone,\' \',\'\'),\'-\',\'\'),\'+\',\'\') = REPLACE(REPLACE(REPLACE(?,\' \',\'\'),\'-\',\'\'),\'+\',\'\') LIMIT 1');
                    $stmt->execute([$oldPatient['PatientPhone']]);
                    if ($duplicate = $stmt->fetch()) {
                        $errors[] = 'Possible existing patient found: '.$duplicate['PatientName'].' (#'.$duplicate['PatientID'].'). Open the existing record and create a new visit instead.';
                    }
                }

                if (empty($errors)) {
                    try {
                        tdc_save_patient($pdo, $oldPatient, $isEdit, (int) $oldPatient['PatientID']);
                        tdc_redirect('patients', 'success');
                    } catch (PDOException $e) {
                        error_log('[RECEPTION][PATIENTS] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the patient. Please try again.';
                    }
                }
            }

        // --- Laboratory ------------------------------------------------
        } elseif ($section === 'laboratory') {
            tdc_require_permission('lab_billing.payment');
            if ($formAction !== 'collect') tdc_forbidden();
            $labId = trim((string)($_POST['LaboratoryID'] ?? ''));
            $collection = is_numeric($_POST['PaymentAmount'] ?? null) ? round((float)$_POST['PaymentAmount'],2) : -1;
            $paymentMethod = (string)($_POST['PaymentMethod'] ?? '');
            if ($labId === '') $errors[] = 'Select a laboratory bill.';
            if ($collection <= 0) $errors[] = 'Amount received must be greater than zero.';
            if (!in_array($paymentMethod,$paymentMethodNames,true)) $errors[] = 'Select a valid active payment method.';
            if (!$errors) {
                $pdo->beginTransaction();
                try {
                    $stmt=$pdo->prepare('SELECT * FROM laboratory WHERE LaboratoryID=? FOR UPDATE');$stmt->execute([$labId]);$lab=$stmt->fetch();
                    if(!$lab || !in_array($lab['WorkflowStatus'],['Awaiting Payment','Requested'],true)) throw new RuntimeException('This laboratory order is not awaiting payment.');
                    if($collection>(float)$lab['DueBalance']) throw new RuntimeException('Payment cannot exceed the outstanding laboratory balance.');
                    $newPaid=round((float)$lab['AmountPaid']+$collection,2);$due=max(0,round((float)$lab['TotalAmount']-$newPaid,2));$status=tdc_workflow_payment_status((float)$lab['TotalAmount'],$newPaid);$workflow=$status==='Paid'?'Ready':'Awaiting Payment';
                    $stmt=$pdo->prepare('UPDATE laboratory SET AmountPaid=?,DueBalance=?,PaymentStatus=?,WorkflowStatus=? WHERE LaboratoryID=?');$stmt->execute([$newPaid,$due,$status,$workflow,$labId]);
                    $payRef=tdc_workflow_record_payment($pdo,(int)$lab['PatientID'],'Laboratory',$collection,(int)$_SESSION['user_id'],['LaboratoryID'=>$labId,'VisitID'=>$lab['VisitID'],'PaymentMethod'=>$paymentMethod]);
                    if($payRef)tdc_workflow_post_revenue($pdo,'REV-LAB','Laboratory Revenue',$payRef,'Laboratory payment for '.$labId,$collection);
                    if($status==='Paid')tdc_workflow_notify($pdo,null,'labuser','lab_ready','Paid laboratory request ready',$labId.' is cleared for processing','laboratory.php?result=Pending&payment=Paid');
                    $pdo->commit();tdc_redirect('laboratory','payment');
                } catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e->getMessage();}
                catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[RECEPTION][LAB PAYMENT] '.$e->getMessage());$errors[]='The laboratory payment could not be recorded.';}
            }

        // --- Pharmacy ----------------------------------------------------
        } elseif ($section === 'pharmacy') {
            tdc_require_permission('pharmacy_billing.payment');
            if ($formAction === 'delete') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['BillRef'] ?? ''));
                if ($base === '') {
                    $errors[] = 'Invalid pharmacy bill selected.';
                } else {
                    try {
                        tdc_delete_pharmacy_bill($pdo, $base);
                        tdc_redirect('pharmacy', 'deleted');
                    } catch (PDOException $e) {
                        error_log('[RECEPTION][PHARMACY] delete failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while deleting the bill. Please try again.';
                    }
                }
            } else {
                $oldPharmacy['BillRef']        = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['BillRef'] ?? ''));
                $oldPharmacy['PatientID']      = trim((string) ($_POST['PatientID'] ?? ''));
                $oldPharmacy['PatientLabel']   = trim((string) ($_POST['PatientLabel'] ?? ''));
                $oldPharmacy['DoctorID']       = trim((string) ($_POST['DoctorID'] ?? ''));
                $oldPharmacy['TotalAmount']    = trim((string) ($_POST['TotalAmount'] ?? ''));
                $oldPharmacy['AmountPaid']     = trim((string) ($_POST['AmountPaid'] ?? ''));
                $oldPharmacy['MedicationName'] = $_POST['MedicationName'] ?? [];
                $oldPharmacy['Dosage']         = $_POST['Dosage'] ?? [];
                $oldPharmacy['Frequency']      = $_POST['Frequency'] ?? [];
                $oldPharmacy['Duration']       = $_POST['Duration'] ?? [];
                $oldPharmacy['Instructions']   = $_POST['Instructions'] ?? [];
                $oldPharmacy['Quantity']       = $_POST['Quantity'] ?? [];
                $oldPharmacy['Route']          = $_POST['Route'] ?? [];

                $isEdit = $oldPharmacy['BillRef'] !== '';
                $errors = tdc_validate_pharmacy_form($oldPharmacy);
                $pharmacyShowForm = true; // stay on the form for both success (until redirect) and failure

                if (empty($errors)) {
                    try {
                        tdc_save_pharmacy($pdo, $oldPharmacy, $isEdit, $oldPharmacy['BillRef']);
                        tdc_redirect('pharmacy', 'success');
                    } catch (Throwable $e) {
                        error_log('[RECEPTION][PHARMACY] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the bill. Please try again.';
                    }
                }
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above via header()).
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 10 — GET data loading for display
// =======================================================================
$doctors = $pdo->query('SELECT DoctorID, DoctorName, Specialty, ConsultationFee, UserID FROM doctors ORDER BY DoctorName ASC')->fetchAll();
$doctorNameById = array_column($doctors, 'DoctorName', 'DoctorID');

$patientOptions = [];
if (in_array($section, ['consultations', 'laboratory', 'pharmacy'], true)) {
    $stmt = $pdo->query('SELECT PatientID, PatientName, PatientPhone FROM patients ORDER BY PatientName ASC LIMIT 500');
    foreach ($stmt->fetchAll() as $p) {
        $patientOptions[] = [
            'id'    => (int) $p['PatientID'],
            'label' => $p['PatientName'] . ($p['PatientPhone'] ? ' — ' . $p['PatientPhone'] : ''),
        ];
    }
}

$patients            = [];
$patientSearch       = '';
$patientTypeFilter   = '';
$patientDoctorFilter = 0;
$patientDateFilter   = '';
if ($section === 'patients') {
    $patientSearch       = trim((string) ($_GET['q'] ?? ''));
    $requestedType       = (string) ($_GET['type'] ?? '');
    $patientTypeFilter   = array_key_exists($requestedType, PATIENT_TYPE_OPTIONS) ? $requestedType : '';
    $patientDoctorFilter = isset($_GET['doctor']) && ctype_digit((string) $_GET['doctor']) ? (int) $_GET['doctor'] : 0;
    $requestedDate       = trim((string) ($_GET['registered'] ?? ''));
    $patientDateFilter   = $requestedDate !== '' && tdc_is_valid_date($requestedDate) ? $requestedDate : '';

    $conditions = [];
    $params = [];
    if ($patientSearch !== '') {
        $conditions[] = '(p.PatientName LIKE :q1 OR p.PatientPhone LIKE :q2)';
        $params['q1'] = $params['q2'] = '%' . $patientSearch . '%';
    }
    if ($patientTypeFilter !== '') {
        $conditions[] = 'p.PatientType = :patient_type';
        $params['patient_type'] = $patientTypeFilter;
    }
    if ($patientDoctorFilter > 0) {
        $conditions[] = 'p.AllocatedDoctor = :doctor';
        $params['doctor'] = $patientDoctorFilter;
    }
    if ($patientDateFilter !== '') {
        $conditions[] = 'DATE(p.RegisteredAt) = :registered';
        $params['registered'] = $patientDateFilter;
    }

    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $pdo->prepare(
        "SELECT p.*, d.DoctorName FROM patients p LEFT JOIN doctors d ON d.DoctorID = p.AllocatedDoctor
         {$where} ORDER BY p.RegisteredAt DESC LIMIT 200"
    );
    $stmt->execute($params);
    $patients = $stmt->fetchAll();
}

$consultationSearch = trim((string) ($_GET['q'] ?? ''));
$consultationStatus = trim((string) ($_GET['status'] ?? ''));
$consultationPerPage = (int) ($_GET['per_page'] ?? 25);
if (!in_array($consultationPerPage, [10, 25, 50, 100], true)) $consultationPerPage = 25;
$consultationPage = max(1, (int) ($_GET['page'] ?? 1));
[$consultationFrom, $consultationTo, $consultationRangeError] = tdc_date_range_resolve();
$consultationStatuses = ['Pending Payment', 'Waiting', 'In Consultation', 'Completed', 'Cancelled'];
if ($consultationStatus !== '' && !in_array($consultationStatus, $consultationStatuses, true)) $consultationStatus = '';

$consultationVisits = [];
$consultationTotal = 0;
$consultationCounts = ['total' => 0, 'waiting' => 0, 'in_consultation' => 0, 'completed' => 0];
$consultationExportUrl = '';

if ($section === 'consultations') {
    $consultationWhere = [];
    $consultationParams = [];
    $scopeWhere = [];
    $scopeParams = [];
    if ($consultationSearch !== '') {
        $scopeWhere[] = '(v.VisitReference LIKE ? OR p.PatientName LIKE ? OR p.PatientPhone LIKE ?)';
        $like = '%' . $consultationSearch . '%';
        $scopeParams[] = $like;
        $scopeParams[] = $like;
        $scopeParams[] = $like;
    }
    foreach (tdc_sql_date_range('v.VisitDate', $consultationFrom, $consultationTo, $scopeParams) as $clause) {
        $scopeWhere[] = $clause;
    }
    $consultationWhere = $scopeWhere;
    $consultationParams = $scopeParams;
    if ($consultationStatus !== '') {
        $consultationWhere[] = 'v.QueueStatus = ?';
        $consultationParams[] = $consultationStatus;
    }
    $scopeSql = $scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '';
    $consultationSql = $consultationWhere ? ' WHERE ' . implode(' AND ', $consultationWhere) : '';

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM visits v JOIN patients p ON p.PatientID=v.PatientID' . $scopeSql);
    $countStmt->execute($scopeParams);
    $consultationCounts['total'] = (int) $countStmt->fetchColumn();
    $breakdown = $pdo->prepare('SELECT v.QueueStatus, COUNT(*) AS Total FROM visits v JOIN patients p ON p.PatientID=v.PatientID' . $scopeSql . ' GROUP BY v.QueueStatus');
    $breakdown->execute($scopeParams);
    foreach ($breakdown->fetchAll() as $row) {
        if ($row['QueueStatus'] === 'Waiting') $consultationCounts['waiting'] = (int) $row['Total'];
        if ($row['QueueStatus'] === 'In Consultation') $consultationCounts['in_consultation'] = (int) $row['Total'];
        if ($row['QueueStatus'] === 'Completed') $consultationCounts['completed'] = (int) $row['Total'];
    }

    if (($_GET['export'] ?? '') === 'csv') {
        require_once __DIR__ . '/../includes/data-transfer.php';
        $exportStmt = $pdo->prepare(
            'SELECT v.VisitReference, p.PatientName, p.Gender, p.Age, p.PatientPhone, d.DoctorName,
                    v.VisitDate, v.QueueStatus, v.PaymentStatus, v.ConsultationFee, v.AmountPaid, v.DueBalance
             FROM visits v JOIN patients p ON p.PatientID=v.PatientID JOIN doctors d ON d.DoctorID=v.DoctorID'
            . $consultationSql . ' ORDER BY v.VisitDate DESC LIMIT 5000'
        );
        $exportStmt->execute($consultationParams);
        $exportRows = [];
        foreach ($exportStmt->fetchAll() as $row) {
            $exportRows[] = [
                (string) $row['VisitReference'], (string) $row['PatientName'], (string) $row['Gender'],
                (string) $row['Age'], (string) $row['PatientPhone'], (string) $row['DoctorName'],
                (string) $row['VisitDate'], (string) $row['QueueStatus'], (string) $row['PaymentStatus'],
                number_format((float) $row['ConsultationFee'], 2), number_format((float) $row['AmountPaid'], 2),
                number_format((float) $row['DueBalance'], 2),
            ];
        }
        tdc_csv_download('doctor-waiting-' . date('Y-m-d') . '.csv', ['Appointment', 'Patient', 'Gender', 'Age', 'Phone', 'Doctor', 'Visit Date', 'Queue Status', 'Payment Status', 'Fee', 'Paid', 'Balance'], $exportRows);
    }

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM visits v JOIN patients p ON p.PatientID=v.PatientID' . $consultationSql);
    $totalStmt->execute($consultationParams);
    $consultationTotal = (int) $totalStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($consultationTotal / $consultationPerPage));
    if ($consultationPage > $totalPages) $consultationPage = $totalPages;
    $offset = ($consultationPage - 1) * $consultationPerPage;

    $listStmt = $pdo->prepare(
        'SELECT v.*, p.PatientName, p.PatientPhone, p.Gender, p.Age, d.DoctorName
         FROM visits v JOIN patients p ON p.PatientID=v.PatientID JOIN doctors d ON d.DoctorID=v.DoctorID'
        . $consultationSql
        . " ORDER BY FIELD(v.QueueStatus,'In Consultation','Waiting','Pending Payment','Completed','Cancelled'), v.VisitDate DESC LIMIT $consultationPerPage OFFSET $offset"
    );
    $listStmt->execute($consultationParams);
    $consultationVisits = $listStmt->fetchAll();

    $consultationExportUrl = 'reception.php?' . http_build_query(array_filter([
        'section' => 'consultations', 'export' => 'csv', 'q' => $consultationSearch,
        'status' => $consultationStatus, 'from_date' => $consultationFrom, 'to_date' => $consultationTo,
    ], static fn($value): bool => $value !== '' && $value !== null));
}

$labBills = [];
if ($section === 'laboratory') {
    $stmt = $pdo->query(
        'SELECT l.*, p.PatientName, p.PatientPhone, v.VisitReference, d.DoctorName FROM laboratory l
         JOIN patients p ON p.PatientID = l.PatientID
         LEFT JOIN visits v ON v.VisitID = l.VisitID
         LEFT JOIN doctors d ON d.DoctorID = l.DoctorID
         ORDER BY l.OrderDate DESC LIMIT 200'
    );
    $labBills = $stmt->fetchAll();
}

$pharmacyBills = [];
if ($section === 'pharmacy') {
    $stmt = $pdo->query(
        "SELECT SUBSTRING_INDEX(PrescriptionID, '-', 1) AS BillRef,
                MIN(PatientID) AS PatientID, MIN(PatientName) AS PatientName,
                MIN(DoctorID) AS DoctorID, COUNT(*) AS LineCount,
                MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                MIN(DueBalance) AS DueBalance, MIN(PrescriptionDate) AS PrescriptionDate
         FROM prescriptions
         GROUP BY BillRef
         ORDER BY PrescriptionDate DESC
         LIMIT 200"
    );
    $pharmacyBills = $stmt->fetchAll();

    // A failed POST already forces the form back open (SECTION 9). On a
    // plain GET, ?new=1 or ?edit=REF opens the create/edit form instead.
    if (empty($errors)) {
        if (isset($_GET['new'])) {
            $pharmacyShowForm = true;
        } elseif (isset($_GET['edit'])) {
            $editRef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['edit']);
            $stmt = $pdo->prepare('SELECT * FROM prescriptions WHERE PrescriptionID LIKE :pattern ORDER BY PrescriptionID ASC');
            $stmt->execute(['pattern' => $editRef . '-%']);
            $rows = $stmt->fetchAll();

            if (!empty($rows)) {
                $pharmacyShowForm            = true;
                $oldPharmacy['BillRef']      = $editRef;
                $oldPharmacy['PatientID']    = (string) $rows[0]['PatientID'];
                $oldPharmacy['PatientLabel'] = $rows[0]['PatientName'] . ($rows[0]['PatientPhone'] ? ' — ' . $rows[0]['PatientPhone'] : '');
                $oldPharmacy['DoctorID']     = (string) $rows[0]['DoctorID'];
                $oldPharmacy['TotalAmount']  = (string) $rows[0]['TotalAmount'];
                $oldPharmacy['AmountPaid']   = (string) $rows[0]['AmountPaid'];
                foreach ($rows as $r) {
                    $oldPharmacy['MedicationName'][] = (string) $r['MedicationName'];
                    $oldPharmacy['Dosage'][]          = (string) ($r['Dosage'] ?? '');
                    $oldPharmacy['Frequency'][]       = (string) ($r['Frequency'] ?? '');
                    $oldPharmacy['Duration'][]        = (string) ($r['Duration'] ?? '');
                    $oldPharmacy['Instructions'][]    = (string) ($r['Instructions'] ?? '');
                    $oldPharmacy['Quantity'][]        = (string) ($r['Quantity'] ?? '');
                    $oldPharmacy['Route'][]           = (string) ($r['Route'] ?? '');
                }
            }
        }
    }
}

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'reception.php'));

$justSaved   = isset($_GET['success']);
$justDeleted = isset($_GET['deleted']);
$justVisited = isset($_GET['visited']);
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







    .form-row{ display:flex; gap:16px; flex-wrap:wrap; }
    .form-row .form-group{ flex:1; min-width:180px; }
    .checkbox-row{ display:flex; align-items:center; gap:8px; }
    .checkbox-row input{ width:auto; }





    .setup-grid{ display:grid; grid-template-columns:repeat(3, minmax(220px,1fr)); gap:20px; max-width:920px; }
    .setup-card{ display:flex; align-items:flex-start; gap:14px; padding:20px; border:2px solid var(--navy); text-decoration:none; color:var(--navy); transition:background 0.12s, border-color 0.12s; }
    .setup-card:hover{ background:var(--navy-10); border-color:var(--orange); }
    .setup-card .setup-icon{ width:32px; height:32px; flex-shrink:0; color:var(--navy-55); }
    .setup-card .setup-icon svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; }
    .setup-card-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-bottom:4px; }
    .setup-card-desc{ font-size:12.5px; color:var(--navy-55); }

    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1200px; margin-bottom:16px; flex-wrap:wrap; }
    .search-box{ display:flex; gap:8px; }
    .search-box input{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); min-width:240px; }
    .search-box input:focus{ outline:none; border-color:var(--orange); }






    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    .status-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); white-space:nowrap; }
    .status-badge.warn{ border-color:var(--orange); color:var(--orange); }
    .status-badge.danger{ border-color:#c0392b; color:#c0392b; }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .row-actions form{ display:inline; }




    .combo{ position:relative; }
    .combo-list{ position:absolute; top:calc(100% + 4px); left:0; right:0; max-height:220px; overflow-y:auto; background:var(--white); border:2px solid var(--navy); list-style:none; z-index:50; }
    .combo-list li{ padding:9px 12px; font-size:13px; cursor:pointer; }
    .combo-list li:hover, .combo-list li.active{ background:var(--navy-10); color:var(--orange); }
    .combo-empty{ padding:9px 12px; font-size:12.5px; color:var(--navy-55); }

    .line-items-wrap{ max-width:1200px; border:2px solid var(--navy); overflow-x:auto; margin-bottom:16px; }
    .line-items{ width:100%; border-collapse:collapse; min-width:960px; }
    .line-items th, .line-items td{ padding:8px 10px; border-bottom:1px solid var(--navy-30); vertical-align:top; }
    .line-items th{ background:var(--navy-10); font-size:11px; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
    .line-items input{ width:100%; padding:7px 8px; border:1.5px solid rgba(46,49,146,0.3); font-size:13px; font-family:'Google Sans',sans-serif; color:var(--navy); }
    .line-items input:focus{ outline:none; border-color:var(--orange); }
    .remove-line-btn{ background:none; border:none; color:#c0392b; cursor:pointer; font-size:20px; line-height:1; padding:4px; }
    .add-line-btn{ margin-bottom:20px; }
    .totals-row{ display:flex; gap:20px; flex-wrap:wrap; max-width:1200px; margin-bottom:20px; }
    .totals-row .form-group{ min-width:180px; flex:0 1 200px; }
    .due-display{ font-weight:700; font-size:15px; color:var(--navy); padding:11px 0; }
    .form-actions{ display:flex; gap:10px; max-width:1200px; }

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

<nav class="setup-section-nav" aria-label="Reception sections">
    <?php if (tdc_can('patients.view')): ?><a href="reception.php?section=patients"<?= $section === 'patients' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('users', 16) ?><span>Patients</span></a><?php endif; ?>
    <?php if (tdc_can('consultations.view')): ?><a href="reception.php?section=consultations"<?= $section === 'consultations' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('calendar', 16) ?><span>Consultations</span></a><?php endif; ?>
    <?php if (tdc_can('lab_billing.view')): ?><a href="reception.php?section=laboratory"<?= $section === 'laboratory' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('flask', 16) ?><span>Laboratory Billing</span></a><?php endif; ?>
    <?php if (tdc_can('pharmacy_billing.view')): ?><a href="reception.php?section=pharmacy"<?= $section === 'pharmacy' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('pill', 16) ?><span>Pharmacy Billing</span></a><?php endif; ?>
</nav>

<?php if ($section === null): ?>

    <div class="welcome-eyebrow">Reception</div>
    <div class="welcome-title">Reception Desk</div>
    <div class="welcome-sub">Register patients and manage laboratory and pharmacy billing.</div>

    <div class="setup-grid">
        <?php if(tdc_can('patients.view')):?><a href="reception.php?section=patients" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
            <div>
                <div class="setup-card-title">Patient Registration</div>
                <div class="setup-card-desc">Create patient records, update details, and review visit history.</div>
                <span class="setup-card-cta">Manage patients</span>
            </div>
        </a><?php endif;?>
        <?php if(tdc_can('lab_billing.view')):?><a href="reception.php?section=laboratory" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M9 2h6M10 2v6.5L4.5 18a2 2 0 001.7 3h11.6a2 2 0 001.7-3L14 8.5V2"/></svg></div>
            <div>
                <div class="setup-card-title">Laboratory Bills</div>
                <div class="setup-card-desc">Order tests, record payments, and track laboratory billing.</div>
                <span class="setup-card-cta">Open laboratory</span>
            </div>
        </a><?php endif;?>
        <?php if(tdc_can('pharmacy_billing.view')):?><a href="reception.php?section=pharmacy" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M13.657 2.343a4 4 0 00-5.657 0L2.343 8a4 4 0 105.657 5.657l5.657-5.657a4 4 0 000-5.657zM8.5 6.5l5 5"/></svg></div>
            <div>
                <div class="setup-card-title">Pharmacy Bills</div>
                <div class="setup-card-desc">Create prescription bills and manage pharmacy payments.</div>
                <span class="setup-card-cta">Open pharmacy</span>
            </div>
        </a><?php endif;?>
    </div>

    <section class="secondary-tools" aria-labelledby="reception-tools-title">
        <h2 id="reception-tools-title">Other reception tools</h2>
        <div class="secondary-tool-grid">
            <a href="reception.php?section=consultations" class="secondary-tool">
                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
                <span><strong>Book consultation</strong><small>Select a doctor and record payment</small></span>
            </a>
            <a href="reception.php?section=patients" class="secondary-tool">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <span><strong>Search patients</strong><small>Find an existing patient record</small></span>
            </a>
            <a href="reception.php?section=laboratory" class="secondary-tool">
                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 15h4"/></svg>
                <span><strong>Pending payments</strong><small>Review laboratory bills</small></span>
            </a>
        </div>
    </section>

<?php else: ?>

    <a href="reception.php" class="back-link">&larr; Back to Reception</a>

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
          // CONSULTATION BOOKING
          // ============================================================ ?>
    <?php if ($section === 'consultations'): ?>

    <div class="welcome-title">Consultation Booking</div>
    <div class="welcome-sub">Schedule a patient, apply the doctor's fee, and send fully paid visits to the consultation queue.</div>

    <?php if($canBookConsultation):?><form method="POST" action="reception.php?section=consultations" class="workflow-form" id="consultationForm">
        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
        <input type="hidden" name="form_action" value="save">
        <section class="form-section"><div class="form-section-heading"><span><strong>Patient</strong><span>Select an existing patient record.</span></span></div><div class="form-group"><label for="cv_patient_search">Patient ID, name or phone</label><div class="combo" data-combo><input type="hidden" id="cv_patient" name="PatientID" value="<?= tdc_e($oldVisit['PatientID']) ?>" required><input type="hidden" id="cv_patient_label"><input class="combo-input" id="cv_patient_search" autocomplete="off" placeholder="Search patient by name or phone" required><ul class="combo-list" id="cv_patient_list" hidden></ul></div></div><a class="btn-sm" href="reception.php?section=patients">+ Register New Patient</a></section>
        <section class="form-section"><div class="form-section-heading"><span><strong>Consultation</strong><span>Assign an available doctor and appointment time.</span></span></div><div class="form-row"><div class="form-group"><label for="cv_doctor">Doctor</label><select id="cv_doctor" name="DoctorID" required><option value="">Select assignable doctor</option><?php foreach ($doctors as $d): if(empty($d['UserID'])) continue; ?><option value="<?= (int)$d['DoctorID'] ?>" data-fee="<?= tdc_e((string)$d['ConsultationFee']) ?>" <?= $oldVisit['DoctorID']===(string)$d['DoctorID']?'selected':'' ?>><?= tdc_e($d['DoctorName']) ?> — <?= number_format((float)$d['ConsultationFee'],2) ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="cv_date">Consultation Date</label><input id="cv_date" type="datetime-local" name="VisitDate" value="<?= tdc_e($oldVisit['VisitDate']) ?>" required></div></div><div class="form-group"><label for="cv_complaint">Chief Complaint</label><textarea id="cv_complaint" name="ChiefComplaint" placeholder="Reason for consultation"><?= tdc_e($oldVisit['ChiefComplaint']) ?></textarea></div></section>
        <section class="form-section"><div class="form-section-heading"><span><strong>Payment Summary</strong><span>Payment state is calculated automatically.</span></span></div><div class="form-row"><div class="form-group"><label for="cv_fee">Consultation Fee</label><input id="cv_fee" type="text" value="Select a doctor" readonly></div><div class="form-group"><label for="cv_paid">Amount Paid</label><input id="cv_paid" type="number" min="0" step="0.01" name="AmountPaid" value="<?= tdc_e($oldVisit['AmountPaid']) ?>" required></div></div><div class="form-row"><div class="form-group"><label>Balance</label><div class="due-display" id="cv_balance">0.00</div></div><div class="form-group"><label>Payment Status</label><div><span class="status-badge danger" id="cv_status">Unpaid</span></div></div><div class="form-group"><label for="cv_method">Payment Method</label><select id="cv_method" name="PaymentMethod"><option value="">Not applicable until payment is entered</option><?php foreach ($paymentMethods as $method): ?><option value="<?=tdc_e($method['MethodName'])?>" <?= $oldVisit['PaymentMethod']===$method['MethodName']?'selected':'' ?>><?=tdc_e($method['MethodName'])?></option><?php endforeach; ?></select></div></div></section>
        <div class="modal-actions workflow-actions"><a href="reception.php" class="btn btn-secondary">Cancel</a><button class="btn btn-primary" type="submit">Book Consultation</button></div>
    </form><?php endif;?>

    <div class="subsection-title"><span>Doctor patient waiting</span></div>
    <p class="section-hint">Every booked consultation with its payment state and current position in the doctor queue.</p>

    <div class="queue-stats">
        <div class="queue-stat"><span class="queue-stat-icon info"><?= tdc_icon('calendar', 18) ?></span><div><strong><?= (int) $consultationCounts['total'] ?></strong><span>Matching visits</span></div></div>
        <div class="queue-stat"><span class="queue-stat-icon warn"><?= tdc_icon('clock', 18) ?></span><div><strong><?= (int) $consultationCounts['waiting'] ?></strong><span>Waiting</span></div></div>
        <div class="queue-stat"><span class="queue-stat-icon primary"><?= tdc_icon('stethoscope', 18) ?></span><div><strong><?= (int) $consultationCounts['in_consultation'] ?></strong><span>In consultation</span></div></div>
        <div class="queue-stat"><span class="queue-stat-icon success"><?= tdc_icon('check', 18) ?></span><div><strong><?= (int) $consultationCounts['completed'] ?></strong><span>Completed</span></div></div>
    </div>

    <?= tdc_date_range([
        'action'   => 'reception.php',
        'from'     => $consultationFrom,
        'to'       => $consultationTo,
        'error'    => $consultationRangeError,
        'preserve' => ['section' => 'consultations', 'q' => $consultationSearch, 'status' => $consultationStatus, 'per_page' => $consultationPerPage],
    ]) ?>

    <form class="table-command-bar" method="get" action="reception.php">
        <input type="hidden" name="section" value="consultations">
        <input type="hidden" name="from_date" value="<?= tdc_e($consultationFrom) ?>">
        <input type="hidden" name="to_date" value="<?= tdc_e($consultationTo) ?>">
        <?= tdc_search_field('q', $consultationSearch, 'Search appointment, patient or phone') ?>
        <label class="filter-select"><select name="status" aria-label="Queue status">
            <option value="">All queue states</option>
            <?php foreach ($consultationStatuses as $statusOption): ?>
                <option value="<?= tdc_e($statusOption) ?>"<?= $consultationStatus === $statusOption ? ' selected' : '' ?>><?= tdc_e($statusOption) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label class="filter-select"><select name="per_page" aria-label="Rows per page">
            <?php foreach ([10, 25, 50, 100] as $sizeOption): ?>
                <option value="<?= (int) $sizeOption ?>"<?= $consultationPerPage === $sizeOption ? ' selected' : '' ?>>Show <?= (int) $sizeOption ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="btn-primary btn  btn-sm" type="submit"><?= tdc_icon('filter', 14) ?><span>Apply</span></button>
        <span class="toolbar-spacer"></span>
        <?= tdc_export_buttons(['csv' => $consultationExportUrl]) ?>
        <button type="button" class="btn-info btn  btn-sm" onclick="window.print()"><?= tdc_icon('printer', 14) ?><span>Export PDF</span></button>
    </form>

    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Appointment</th><th>Patient</th><th>Gender</th><th>Age</th><th>Phone</th><th>Visit date</th><th>Queue</th><th>Payment</th><th>Actions</th></tr></thead><tbody>
    <?php if (!$consultationVisits): ?>
        <?= tdc_empty_state('calendar', 'No consultations match these filters', 'Widen the date range or clear the filters to see more bookings.', '', 9) ?>
    <?php else: foreach ($consultationVisits as $v): ?>
        <tr>
            <td><strong><?= tdc_e((string) $v['VisitReference']) ?></strong><br><span class="cell-sub"><?= tdc_e((string) $v['DoctorName']) ?></span></td>
            <td><?= tdc_e((string) $v['PatientName']) ?></td>
            <td><?= $v['Gender'] !== null && $v['Gender'] !== '' ? tdc_e((string) $v['Gender']) : '&mdash;' ?></td>
            <td><?= $v['Age'] !== null && $v['Age'] !== '' ? (int) $v['Age'] : '&mdash;' ?></td>
            <td><?= $v['PatientPhone'] !== null && $v['PatientPhone'] !== '' ? tdc_e((string) $v['PatientPhone']) : '&mdash;' ?></td>
            <td><?= tdc_e(date('d M Y H:i', strtotime((string) $v['VisitDate']))) ?></td>
            <td><?= tdc_badge((string) $v['QueueStatus']) ?></td>
            <td><?= tdc_badge((string) $v['PaymentStatus']) ?></td>
            <td><?php if (tdc_can('doctor.workspace')): ?><a class="btn-sm" href="doctors.php?visit=<?= (int) $v['VisitID'] ?>" title="Open consultation workspace">Open</a><?php endif; ?><?php if ((float) $v['DueBalance'] > 0 && $v['QueueStatus'] === 'Pending Payment'): ?><form method="post" action="reception.php?section=consultations" class="balance-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="collect"><input type="hidden" name="VisitID" value="<?= (int) $v['VisitID'] ?>"><input aria-label="Payment amount" type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string) $v['DueBalance']) ?>" step="0.01" value="<?= tdc_e((string) $v['DueBalance']) ?>" required><select aria-label="Payment method" name="PaymentMethod"><?php foreach ($paymentMethods as $method): ?><option><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select><button class="btn-success btn-sm" type="submit">Collect</button></form><?php elseif ((float) $v['DueBalance'] > 0): ?><span class="cell-sub">Balance <?= number_format((float) $v['DueBalance'], 2) ?></span><?php else: ?><span class="cell-sub">Settled</span><?php endif; ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
    <?= tdc_pager($consultationPage, $consultationPerPage, $consultationTotal, array_filter(['section' => 'consultations', 'q' => $consultationSearch, 'status' => $consultationStatus, 'from_date' => $consultationFrom, 'to_date' => $consultationTo, 'per_page' => $consultationPerPage], static fn($value): bool => $value !== '' && $value !== null)) ?>

    <script>document.addEventListener('DOMContentLoaded',function(){const patients=<?= json_encode($patientOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;const patientId=document.getElementById('cv_patient'),patientLabel=document.getElementById('cv_patient_label'),patientSearch=document.getElementById('cv_patient_search');const selected=patients.find(p=>String(p.id)===patientId.value);if(selected){patientLabel.value=selected.label;patientSearch.value=selected.label;}initPatientCombobox(patientId,patientLabel,patientSearch,document.getElementById('cv_patient_list'),patients);const doctor=document.getElementById('cv_doctor'),feeField=document.getElementById('cv_fee'),paidField=document.getElementById('cv_paid'),balance=document.getElementById('cv_balance'),status=document.getElementById('cv_status'),method=document.getElementById('cv_method');function sync(){const option=doctor.options[doctor.selectedIndex],fee=option&&option.dataset.fee?Number(option.dataset.fee):0,paid=Math.max(0,Number(paidField.value)||0),due=Math.max(0,fee-paid);feeField.value=option&&option.dataset.fee?fee.toFixed(2):'Select a doctor';paidField.max=fee.toFixed(2);balance.textContent=due.toFixed(2);const state=fee<=0||paid>=fee?'Paid':(paid>0?'Partial':'Unpaid');status.textContent=state;status.className='status-badge'+(state==='Unpaid'?' danger':(state==='Partial'?' warn':''));method.disabled=paid<=0;method.required=paid>0;if(paid<=0)method.value='';}doctor.addEventListener('change',sync);paidField.addEventListener('input',sync);sync();});</script>

    <?php // ============================================================
          // PATIENT REGISTRATION
          // ============================================================ ?>
    <?php elseif ($section === 'patients'): ?>

    <div class="welcome-title">Patient Registration</div>
    <div class="welcome-sub">Register new patients and manage existing records.</div>

    <div class="section-toolbar">
        <form method="GET" action="reception.php" class="filter-box">
            <input type="hidden" name="section" value="patients">
            <input type="text" name="q" placeholder="Search by name or phone..." value="<?= tdc_e($patientSearch) ?>">
            <select name="type" aria-label="Patient type">
                <option value="">All Types</option>
                <?php foreach (PATIENT_TYPE_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>" <?= $patientTypeFilter === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option><?php endforeach; ?>
            </select>
            <select name="doctor" aria-label="Allocated doctor">
                <option value="0">All Doctors</option>
                <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['DoctorID'] ?>" <?= $patientDoctorFilter === (int) $d['DoctorID'] ? 'selected' : '' ?>><?= tdc_e($d['DoctorName']) ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="registered" value="<?= tdc_e($patientDateFilter) ?>" aria-label="Registration date">
            <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Filter</span></button>
            <?php if ($patientSearch !== '' || $patientTypeFilter !== '' || $patientDoctorFilter > 0 || $patientDateFilter !== ''): ?><a href="reception.php?section=patients" class="clear-filters">Clear</a><?php endif; ?>
        </form>
        <?php if($canPatientCreate):?><button type="button" id="addPatientBtn" class="btn btn-success">+ Register Patient</button><?php endif;?>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Phone</th><th>Gender / Age</th><th>Type</th><th>Visit #</th><th>Doctor</th><th>Due Balance</th><th>Registered</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($patients)): ?>
                <tr class="empty-row"><td colspan="10">No patients found. Click "Register Patient" to add one.</td></tr>
                <?php else: foreach ($patients as $p): ?>
                <tr>
                    <td>#<?= (int) $p['PatientID'] ?></td>
                    <td><?= tdc_e($p['PatientName']) ?></td>
                    <td><?= tdc_e($p['PatientPhone'] ?: '—') ?></td>
                    <td><?= tdc_e(($p['Gender'] ?: '—') . ' / ' . ($p['Age'] !== null ? $p['Age'] : '—')) ?></td>
                    <td><span class="status-badge<?= $p['PatientType'] ? '' : ' muted' ?>"><?= tdc_e($p['PatientType'] ?: 'Unspecified') ?></span></td>
                    <td><?= (int) $p['VisitNumber'] ?></td>
                    <td><?= tdc_e($p['DoctorName'] ?: 'Unassigned') ?></td>
                    <td><?= number_format((float) $p['DueBalance'], 2) ?></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $p['RegisteredAt']))) ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if($canPatientEdit):?><button type="button" class="btn-warning btn-sm edit-patient-btn"
                                data-id="<?= (int) $p['PatientID'] ?>"
                                data-name="<?= tdc_e($p['PatientName']) ?>"
                                data-phone="<?= tdc_e((string) $p['PatientPhone']) ?>"
                                data-address="<?= tdc_e((string) $p['PatientAddress']) ?>"
                                data-gender="<?= tdc_e((string) $p['Gender']) ?>"
                                data-age="<?= tdc_e((string) $p['Age']) ?>"
                                data-dob="<?= tdc_e((string) $p['DateOfBirth']) ?>"
                                data-type="<?= tdc_e((string) $p['PatientType']) ?>"
                                data-doctor="<?= tdc_e((string) $p['AllocatedDoctor']) ?>"
                                data-remark="<?= tdc_e((string) $p['Remark']) ?>"><?= tdc_icon('pencil',16) ?><span>Edit</span></button><?php endif;?>
                            <?php if($canBookConsultation):?><a class="btn-sm" href="reception.php?section=consultations&amp;patient=<?= (int) $p['PatientID'] ?>">Book</a><?php endif;?>
                            <?php if($canPatientDelete):?><form method="POST" action="reception.php?section=patients" data-confirm="Delete this patient? This cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="PatientID" value="<?= (int) $p['PatientID'] ?>">
                                <button type="submit" class="btn-danger btn-sm danger">Delete</button>
                            </form><?php endif;?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="modal-overlay" id="patientModalOverlay">
        <div class="modal-box patient-modal">
            <div class="modal-head">
                <h3 id="patientModalTitle">Register Patient</h3>
                <button type="button" class="modal-close" id="patientModalCloseBtn" aria-label="Close">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <form id="patientForm" method="POST" action="reception.php?section=patients">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="PatientID" id="pf_PatientID" value="">

                    <section class="form-section">
                        <div class="form-section-heading">
                            <span class="form-section-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                            <span><strong>Personal Information</strong><span>Enter the patient's basic details.</span></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="pf_PatientName">Patient Name</label>
                                <input type="text" id="pf_PatientName" name="PatientName" placeholder="e.g. Mahamed Omar Madobe" required></div>
                            <div class="form-group"><label for="pf_PatientPhone">Phone</label>
                                <input type="text" id="pf_PatientPhone" name="PatientPhone" placeholder="e.g. 615019253"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="pf_Gender">Gender</label>
                                <select id="pf_Gender" name="Gender">
                                    <option value="">Select gender</option>
                                    <?php foreach (GENDER_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($l) ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label for="pf_DateOfBirth">Date of Birth</label>
                                <input type="date" id="pf_DateOfBirth" name="DateOfBirth"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="pf_Age">Age</label>
                                <input type="number" id="pf_Age" name="Age" min="0" max="150" placeholder="e.g. 34"></div>
                            <div class="form-group"><label for="pf_PatientAddress">Address</label>
                                <input type="text" id="pf_PatientAddress" name="PatientAddress" placeholder="e.g. Degmada Hodan, Isgoyska Al-barako"></div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="form-section-heading">
                            <span class="form-section-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></span>
                            <span><strong>Visit Information</strong><span>Set patient type and allocated doctor.</span></span>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="pf_PatientType">Patient Type</label>
                                <select id="pf_PatientType" name="PatientType">
                                    <option value="">Select type</option>
                                    <?php foreach (PATIENT_TYPE_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($l) ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label for="pf_AllocatedDoctor">Allocated Doctor</label>
                                <select id="pf_AllocatedDoctor" name="AllocatedDoctor">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['DoctorID'] ?>"><?= tdc_e($d['DoctorName']) ?></option><?php endforeach; ?>
                                </select></div>
                        </div>
                        <div class="form-group"><label for="pf_Remark">Remark (Optional)</label>
                            <textarea id="pf_Remark" name="Remark" placeholder="Optional notes about the patient"></textarea></div>
                    </section>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="patientModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Save Patient</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php // ============================================================
          // LABORATORY BILLS
          // ============================================================ ?>
    <?php elseif ($section === 'laboratory'): ?>

    <div class="welcome-title">Laboratory Payment Queue</div>
    <div class="welcome-sub">Collect payment for tests already ordered by doctors. Clinical order details are read-only.</div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr><th>Bill ID</th><th>Patient</th><th>Visit / Doctor</th><th>Requested Test</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Cashier Action</th></tr></thead>
            <tbody>
                <?php if (empty($labBills)): ?>
                <tr class="empty-row"><td colspan="9">No doctor-requested laboratory bills are awaiting action.</td></tr>
                <?php else: foreach ($labBills as $l): ?>
                <tr>
                    <td><?= tdc_e($l['LaboratoryID']) ?></td>
                    <td><?= tdc_e($l['PatientName']) ?><br><span style="color:var(--navy-55);font-size:11.5px;"><?= tdc_e((string) $l['PatientPhone']) ?></span></td>
                    <td><?= tdc_e($l['VisitReference'] ?: '—') ?><br><span class="cell-sub"><?= tdc_e($l['DoctorName'] ?: '—') ?></span></td>
                    <td><?= tdc_e($l['TestName']) ?></td>
                    <td><?= number_format((float) $l['TotalAmount'], 2) ?></td>
                    <td><?= number_format((float)$l['AmountPaid'],2) ?></td><td><?= number_format((float)$l['DueBalance'],2) ?></td>
                    <td><span class="status-badge<?= $l['PaymentStatus'] === 'Unpaid' ? ' danger' : ($l['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($l['PaymentStatus']) ?></span></td>
                    <td>
                        <?php if((float)$l['DueBalance']>0 && in_array($l['WorkflowStatus'],['Requested','Awaiting Payment'],true)): ?><form method="POST" action="reception.php?section=laboratory" class="balance-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="collect"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><input aria-label="Amount received" type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string)$l['DueBalance']) ?>" step="0.01" value="<?= tdc_e((string)$l['DueBalance']) ?>" required><select aria-label="Payment method" name="PaymentMethod" required><option value="">Select method</option><?php foreach($paymentMethods as $method):?><option><?=tdc_e($method['MethodName'])?></option><?php endforeach;?></select><button class="btn-success btn-sm" type="submit">Record Payment</button></form><?php else: ?><?= tdc_badge($l['WorkflowStatus']) ?><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php // ============================================================
          // PHARMACY BILLS
          // ============================================================ ?>
    <?php elseif ($section === 'pharmacy'): ?>

    <?php if (!$pharmacyShowForm): ?>

    <div class="welcome-title">Pharmacy Bills</div>
    <div class="welcome-sub">Create multi-item prescription bills for a patient visit.</div>

    <div class="section-toolbar">
        <div></div>
        <a href="reception.php?section=pharmacy&new=1" class="btn-success btn ">+ New Pharmacy Bill</a>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr><th>Bill Ref</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($pharmacyBills)): ?>
                <tr class="empty-row"><td colspan="9">No pharmacy bills found. Click "New Pharmacy Bill" to add one.</td></tr>
                <?php else: foreach ($pharmacyBills as $b): ?>
                <tr>
                    <td><?= tdc_e($b['BillRef']) ?></td>
                    <td><?= tdc_e($b['PatientName']) ?></td>
                    <td><?= tdc_e($doctorNameById[$b['DoctorID']] ?? 'Unknown') ?></td>
                    <td><?= (int) $b['LineCount'] ?></td>
                    <td><?= number_format((float) $b['TotalAmount'], 2) ?></td>
                    <td><?= number_format((float) $b['AmountPaid'], 2) ?></td>
                    <td><span class="status-badge<?= (float) $b['DueBalance'] > 0 ? ' danger' : '' ?>"><?= number_format((float) $b['DueBalance'], 2) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $b['PrescriptionDate']))) ?></td>
                    <td>
                        <div class="row-actions">
                            <a href="reception.php?section=pharmacy&edit=<?= urlencode($b['BillRef']) ?>" class="btn-warning btn-sm"><?= tdc_icon('pencil',16) ?><span>Edit</span></a>
                            <a href="../print_prescription.php?ref=<?= urlencode($b['BillRef']) ?>" class="btn-info btn-sm" target="_blank" rel="noopener"><?= tdc_icon('printer',16) ?><span>Print</span></a>
                            <form method="POST" action="reception.php?section=pharmacy" data-confirm="Delete this entire pharmacy bill? This cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="BillRef" value="<?= tdc_e($b['BillRef']) ?>">
                                <button type="submit" class="btn-danger btn-sm danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>

    <div class="welcome-title"><?= $oldPharmacy['BillRef'] !== '' ? 'Edit Pharmacy Bill' : 'New Pharmacy Bill' ?></div>
    <div class="welcome-sub">Add one row per medication. Totals apply to the whole bill.</div>

    <form id="pharmacyForm" method="POST" action="reception.php?section=pharmacy">
        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="BillRef" value="<?= tdc_e($oldPharmacy['BillRef']) ?>">

        <div class="form-row" style="max-width:1200px;margin-bottom:16px;">
            <div class="form-group">
                <label for="rf_PatientSearch">Patient</label>
                <div class="combo" data-combo>
                    <input type="hidden" name="PatientID" id="rf_PatientID" value="<?= tdc_e($oldPharmacy['PatientID']) ?>" required>
                    <input type="hidden" name="PatientLabel" id="rf_PatientLabel" value="<?= tdc_e($oldPharmacy['PatientLabel']) ?>">
                    <input type="text" class="combo-input" id="rf_PatientSearch" placeholder="Search patient by name or phone..." autocomplete="off" value="<?= tdc_e($oldPharmacy['PatientLabel']) ?>" required>
                    <ul class="combo-list" id="rf_PatientList" hidden></ul>
                </div>
            </div>
            <div class="form-group">
                <label for="rf_DoctorID">Prescribing Doctor</label>
                <select id="rf_DoctorID" name="DoctorID" required>
                    <option value="">Select doctor</option>
                    <?php foreach ($doctors as $d): ?>
                        <option value="<?= (int) $d['DoctorID'] ?>" <?= (string) $d['DoctorID'] === $oldPharmacy['DoctorID'] ? 'selected' : '' ?>><?= tdc_e($d['DoctorName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="line-items-wrap">
            <table class="line-items" id="lineItemsTable">
                <thead>
                    <tr><th style="width:40px;">#</th><th>Medication</th><th>Dosage</th><th>Quantity</th><th>Frequency</th><th>Duration</th><th>Route</th><th>Instructions</th><th style="width:36px;"></th></tr>
                </thead>
                <tbody id="lineItemsBody">
                <?php
                $lineCount = max(1, count($oldPharmacy['MedicationName']));
                for ($i = 0; $i < $lineCount; $i++):
                ?>
                    <tr class="line-item-row">
                        <td class="line-no"><?= $i + 1 ?></td>
                        <td><input type="text" name="MedicationName[]" value="<?= tdc_e($oldPharmacy['MedicationName'][$i] ?? '') ?>" placeholder="e.g. lomefen cream"></td>
                        <td><input type="text" name="Dosage[]" value="<?= tdc_e($oldPharmacy['Dosage'][$i] ?? '') ?>" placeholder="e.g. 250mg"></td>
                        <td><input type="number" min="1" name="Quantity[]" value="<?= tdc_e($oldPharmacy['Quantity'][$i] ?? '1') ?>"></td>
                        <td><input type="text" name="Frequency[]" value="<?= tdc_e($oldPharmacy['Frequency'][$i] ?? '') ?>" placeholder="e.g. bid"></td>
                        <td><input type="text" name="Duration[]" value="<?= tdc_e($oldPharmacy['Duration'][$i] ?? '') ?>" placeholder="e.g. 7 days"></td>
                        <td><input type="text" name="Route[]" value="<?= tdc_e($oldPharmacy['Route'][$i] ?? '') ?>" placeholder="e.g. Oral"></td>
                        <td><input type="text" name="Instructions[]" value="<?= tdc_e($oldPharmacy['Instructions'][$i] ?? '') ?>" placeholder="Optional"></td>
                        <td><button type="button" class="remove-line-btn" title="Remove line">&times;</button></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn-success  btn  add-line-btn" id="addLineBtn"><?= tdc_icon('plus',16) ?><span>+ Add Medication Line</span></button>

        <div class="totals-row">
            <div class="form-group"><label for="rf_TotalAmount">Total Amount</label>
                <input type="number" step="0.01" min="0" id="rf_TotalAmount" name="TotalAmount" value="<?= tdc_e($oldPharmacy['TotalAmount']) ?>" required></div>
            <div class="form-group"><label for="rf_AmountPaid">Amount Paid</label>
                <input type="number" step="0.01" min="0" id="rf_AmountPaid" name="AmountPaid" value="<?= tdc_e($oldPharmacy['AmountPaid']) ?>" required></div>
            <div class="form-group"><label>Due Balance</label>
                <div class="due-display" id="rf_DueDisplay">0.00</div></div>
        </div>

        <div class="form-actions">
            <a href="reception.php?section=pharmacy" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Save Pharmacy Bill</span></button>
        </div>
    </form>

    <?php endif; ?>
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

/**
 * Lightweight, dependency-free search combobox: filters a small
 * in-memory patient list as the user types, with click + keyboard
 * (Up/Down/Enter/Escape) selection. Mirrors the "custom combobox with
 * real-time filtering and keyboard navigation" pattern already used
 * elsewhere in this app's order-entry screens.
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
            matches.slice(0, 30).forEach(function(p, idx) {
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

<?php if ($section === 'patients'): ?>
(function(){
    const overlay = document.getElementById('patientModalOverlay');
    const modalTitle = document.getElementById('patientModalTitle');
    const form = document.getElementById('patientForm');
    const fId = document.getElementById('pf_PatientID');
    const fName = document.getElementById('pf_PatientName');
    const fPhone = document.getElementById('pf_PatientPhone');
    const fGender = document.getElementById('pf_Gender');
    const fAge = document.getElementById('pf_Age');
    const fDob = document.getElementById('pf_DateOfBirth');
    const fAddress = document.getElementById('pf_PatientAddress');
    const fType = document.getElementById('pf_PatientType');
    const fDoctor = document.getElementById('pf_AllocatedDoctor');
    const fRemark = document.getElementById('pf_Remark');

    const MAX_AGE = 150;
    let ageDobSyncing = false;

    function pad2(n){ return String(n).padStart(2, '0'); }

    function dobFromAge(years){
        if (!Number.isInteger(years) || years < 0 || years > MAX_AGE) return '';
        const t = new Date();
        const d = new Date(t.getFullYear() - years, t.getMonth(), t.getDate());
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function ageFromDob(value){
        if (!value) return null;
        const dob = new Date(value + 'T00:00:00');
        if (isNaN(dob.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        if (today.getMonth() < dob.getMonth() || (today.getMonth() === dob.getMonth() && today.getDate() < dob.getDate())) age--;
        return (age >= 0 && age <= MAX_AGE) ? age : null;
    }

    fAge.addEventListener('input', function(){
        if (ageDobSyncing) return;
        const raw = fAge.value.trim();
        if (raw === '') { fDob.value = ''; fDob.setCustomValidity(''); return; }
        if (!/^\d+$/.test(raw)) { fDob.value = ''; return; }
        const years = parseInt(raw, 10);
        if (years < 0 || years > MAX_AGE) { fDob.value = ''; return; }
        ageDobSyncing = true;
        fDob.value = dobFromAge(years);
        fDob.setCustomValidity('');
        ageDobSyncing = false;
    });

    fDob.addEventListener('change', function(){
        if (ageDobSyncing) return;
        const rawDob = fDob.value.trim();
        if (rawDob === '') { fAge.value = ''; fDob.setCustomValidity(''); return; }
        if (!/^\d{4}-\d{2}-\d{2}$/.test(rawDob)) { fAge.value = ''; return; }
        const today = new Date();
        const todayKey = today.getFullYear() + '-' + pad2(today.getMonth() + 1) + '-' + pad2(today.getDate());
        if (rawDob > todayKey) {
            fAge.value = '';
            fDob.setCustomValidity('Date of birth cannot be in the future.');
            fDob.reportValidity();
            return;
        }
        const age = ageFromDob(rawDob);
        if (age === null) { fAge.value = ''; return; }
        fDob.setCustomValidity('');
        ageDobSyncing = true;
        fAge.value = age;
        ageDobSyncing = false;
    });

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.getElementById('addPatientBtn').addEventListener('click', function(){
        form.reset();
        fId.value = '';
        modalTitle.textContent = 'Register Patient';
        openModal();
    });

    document.querySelectorAll('.edit-patient-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            fPhone.value = btn.dataset.phone;
            fAddress.value = btn.dataset.address;
            fGender.value = btn.dataset.gender;
            fAge.value = btn.dataset.age;
            fDob.value = btn.dataset.dob || dobFromAge(parseInt(btn.dataset.age, 10));
            fType.value = btn.dataset.type;
            fDoctor.value = btn.dataset.doctor;
            fRemark.value = btn.dataset.remark;
            modalTitle.textContent = 'Edit Patient';
            openModal();
        });
    });

    document.getElementById('patientModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('patientModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') === 'save'): ?>
    fId.value = <?= json_encode($oldPatient['PatientID']) ?>;
    fName.value = <?= json_encode($oldPatient['PatientName']) ?>;
    fPhone.value = <?= json_encode($oldPatient['PatientPhone']) ?>;
    fAddress.value = <?= json_encode($oldPatient['PatientAddress']) ?>;
    fGender.value = <?= json_encode($oldPatient['Gender']) ?>;
    fAge.value = <?= json_encode($oldPatient['Age']) ?>;
    fDob.value = <?= json_encode($oldPatient['DateOfBirth']) ?>;
    fType.value = <?= json_encode($oldPatient['PatientType']) ?>;
    fDoctor.value = <?= json_encode($oldPatient['AllocatedDoctor']) ?>;
    fRemark.value = <?= json_encode($oldPatient['Remark']) ?>;
    modalTitle.textContent = fId.value ? 'Edit Patient' : 'Register Patient';
    openModal();
    <?php endif; ?>

    <?php if ($justSaved || $justDeleted || $justVisited): ?>
    showToast(<?= $justSaved ? json_encode('Patient saved successfully.') : ($justDeleted ? json_encode('Patient deleted successfully.') : json_encode('Visit recorded successfully.')) ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'reception.php?section=patients'); }
    <?php endif; ?>
})();
<?php endif; ?>

<?php if ($section === 'pharmacy'): ?>
(function(){
    <?php if ($justSaved || $justDeleted): ?>
    showToast(<?= $justSaved ? json_encode('Pharmacy bill saved successfully.') : json_encode('Pharmacy bill deleted successfully.') ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'reception.php?section=pharmacy'); }
    <?php endif; ?>

    <?php if ($pharmacyShowForm): ?>
    const patients = <?= json_encode($patientOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    initPatientCombobox(
        document.getElementById('rf_PatientID'),
        document.getElementById('rf_PatientLabel'),
        document.getElementById('rf_PatientSearch'),
        document.getElementById('rf_PatientList'),
        patients
    );

    const tbody = document.getElementById('lineItemsBody');
    const rowTemplate = tbody.querySelector('.line-item-row').cloneNode(true);
    rowTemplate.querySelectorAll('input').forEach(function(i){ i.value = i.type === 'number' && i.name === 'Quantity[]' ? '1' : ''; });

    function renumber(){
        tbody.querySelectorAll('.line-item-row').forEach(function(row, i){
            row.querySelector('.line-no').textContent = i + 1;
        });
    }
    function bindRemove(row){
        row.querySelector('.remove-line-btn').addEventListener('click', function(){
            if (tbody.querySelectorAll('.line-item-row').length > 1) {
                row.remove();
                renumber();
            }
        });
    }
    tbody.querySelectorAll('.line-item-row').forEach(bindRemove);

    document.getElementById('addLineBtn').addEventListener('click', function(){
        const row = rowTemplate.cloneNode(true);
        bindRemove(row);
        tbody.appendChild(row);
        renumber();
    });

    const totalInput = document.getElementById('rf_TotalAmount');
    const paidInput = document.getElementById('rf_AmountPaid');
    const dueDisplay = document.getElementById('rf_DueDisplay');
    function recalcDue(){
        const total = parseFloat(totalInput.value) || 0;
        const paid = parseFloat(paidInput.value) || 0;
        dueDisplay.textContent = (total - paid).toFixed(2);
    }
    totalInput.addEventListener('input', recalcDue);
    paidInput.addEventListener('input', recalcDue);
    recalcDue();
    <?php endif; ?>
})();
<?php endif; ?>
</script>

</body>
</html>
