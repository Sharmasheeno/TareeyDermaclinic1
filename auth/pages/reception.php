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
const ALLOWED_SECTIONS      = ['patients', 'appointments', 'consultations', 'laboratory', 'pharmacy', 'services'];
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
    $statusFlags = ['saved', 'updated', 'booked', 'booked_new', 'updated_booked'];
    $query = in_array($flag, $statusFlags, true) ? 'status=' . urlencode($flag) : $flag . '=1';
    $billingQuery = $section === 'consultations' ? '&billing=1' : '';
    header('Location: reception.php?section=' . urlencode($section) . '&' . $query . $billingQuery);
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
    if ($input['PatientPhone'] === '') {
        $errors[] = 'Mobile number is required.';
    } elseif (!preg_match('/^[0-9+\-\s()]{6,20}$/', $input['PatientPhone'])) {
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

// --- 5A. Patients -------------------------------------------------------

function tdc_save_patient(PDO $pdo, array $input, bool $isEdit, int $editId): int
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
        return $editId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO patients (PatientName, PatientPhone, PatientAddress, Gender, Age,
            DateOfBirth, PatientType, AllocatedDoctor, Remark, VisitNumber, DueBalance)
         VALUES (:PatientName, :PatientPhone, :PatientAddress, :Gender, :Age,
            :DateOfBirth, :PatientType, :AllocatedDoctor, :Remark, 1, 0.00)'
    );
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

/** @return string[] error messages; empty on success */
function tdc_delete_patient(PDO $pdo, int $id): array
{
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT VisitID,VisitReference FROM visits WHERE PatientID=?'); $q->execute([$id]); $visits = $q->fetchAll();
        $visitIds = array_map(static fn($r)=>(int)$r['VisitID'], $visits); $visitRefs = array_map(static fn($r)=>(string)$r['VisitReference'], $visits);
        $q = $pdo->prepare('SELECT LaboratoryID FROM laboratory WHERE PatientID=?'); $q->execute([$id]); $labIds = array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
        $q = $pdo->prepare('SELECT PrescriptionID,PharmacySaleReference FROM prescriptions WHERE PatientID=?'); $q->execute([$id]); $rxRows = $q->fetchAll();
        $rxRefs = array_map(static fn($r)=>(string)$r['PrescriptionID'],$rxRows); $saleRefs = array_values(array_filter(array_map(static fn($r)=>(string)($r['PharmacySaleReference']??''),$rxRows)));
        $q = $pdo->prepare('SELECT SaleID FROM pharmacysales WHERE PatientID=?'); $q->execute([$id]); $saleRefs = array_values(array_unique(array_merge($saleRefs,array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)))));
        $q = $pdo->prepare('SELECT PaymentReference FROM payments WHERE PatientID=?'); $q->execute([$id]); $paymentRefs = array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
        if ($labIds) {
            $in=implode(',',array_fill(0,count($labIds),'?')); $pdo->prepare("DELETE FROM laborderitems WHERE LaboratoryID IN ($in)")->execute($labIds);
            $q=$pdo->prepare("SELECT BridgeID FROM lab_order_catalog_bridge WHERE LaboratoryID IN ($in)");$q->execute($labIds);$bridges=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
            if($bridges){$bi=implode(',',array_fill(0,count($bridges),'?'));$q=$pdo->prepare("SELECT LabResultID FROM lab_results WHERE BridgeID IN ($bi)");$q->execute($bridges);$results=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));if($results){$ri=implode(',',array_fill(0,count($results),'?'));foreach(['lab_result_attachments','lab_result_parameters','lab_result_review'] as $table)$pdo->prepare("DELETE FROM $table WHERE LabResultID IN ($ri)")->execute($results);$pdo->prepare("DELETE FROM lab_results WHERE LabResultID IN ($ri)")->execute($results);}$pdo->prepare("DELETE FROM lab_order_catalog_bridge WHERE LaboratoryID IN ($in)")->execute($labIds);}
        }
        if($visitIds){$in=implode(',',array_fill(0,count($visitIds),'?'));$pdo->prepare("DELETE FROM laboratory WHERE VisitID IN ($in)")->execute($visitIds);}
        if($labIds){$in=implode(',',array_fill(0,count($labIds),'?'));$pdo->prepare("DELETE FROM laboratory WHERE LaboratoryID IN ($in)")->execute($labIds);}
        if($rxRefs){$in=implode(',',array_fill(0,count($rxRefs),'?'));$pdo->prepare("DELETE FROM prescriptionsheader WHERE PrescriptionID IN ($in)")->execute($rxRefs);}
        if($saleRefs){$in=implode(',',array_fill(0,count($saleRefs),'?'));$pdo->prepare("DELETE FROM pharmacysales WHERE SaleID IN ($in)")->execute($saleRefs);}
        $refs=array_values(array_unique(array_merge($visitRefs,$labIds,$rxRefs,$saleRefs,$paymentRefs)));if($refs){$in=implode(',',array_fill(0,count($refs),'?'));$pdo->prepare("DELETE FROM accounting WHERE ReferenceID IN ($in)")->execute($refs);}
        foreach(['payments','prescriptions','laboratory','visits'] as $table)$pdo->prepare("DELETE FROM $table WHERE PatientID=?")->execute([$id]);
        $pdo->prepare('DELETE FROM patients WHERE PatientID=?')->execute([$id]); $pdo->commit();
        return [];
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log('[RECEPTION PATIENT DELETE] '.$e->getMessage()); return ['The patient and linked history could not be cleared. No records were changed.']; }
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
            tdc_workflow_notify_permission($pdo,'laboratory.process','lab_ready','Paid laboratory request ready',$editId.' is cleared for processing','laboratory.php?result=Pending&payment=Paid','labuser');
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
        tdc_workflow_notify_permission($pdo,'laboratory.process','lab_ready','Paid laboratory request ready',$params['LaboratoryID'].' is cleared for processing','laboratory.php?result=Pending&payment=Paid','labuser');
    }
}

function tdc_delete_lab(PDO $pdo, string $id): void
{
    $stmt = $pdo->prepare('DELETE FROM laboratory WHERE LaboratoryID = :id');
    $stmt->execute(['id' => $id]);
}

// --- 5C. Pharmacy (Prescriptions) ----------------------------------------
//
// There is ONE authoritative prescription lifecycle. Doctors author the
// clinical prescription, its initial gross is calculated from authoritative
// inventory selling prices, Pharmacy dispenses (exactly one stock effect), and Reception may only record
// manual payments against the SAME pharmacy bill. Reception can never
// create prescriptions, set selling prices, or touch inventory.

function tdc_collect_pharmacy_payment(PDO $pdo, string $base, float $collection, string $paymentMethod): array
{
    if ($base === '') throw new RuntimeException('Invalid pharmacy bill selected.');
    if ($collection <= 0) throw new RuntimeException('Payment amount must be greater than zero.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM prescriptions WHERE PrescriptionID LIKE :pattern ORDER BY PrescriptionID ASC FOR UPDATE');
        $stmt->execute(['pattern' => $base . '-%']);
        $lines = $stmt->fetchAll();
        if (!$lines) throw new RuntimeException('This pharmacy bill no longer exists.');

        // Idempotency: the bill total is owned by the prescription billing state.
        $adjustment = tdc_load_bill_adjustment($pdo, 'prescription', $base);
        $total = $adjustment ? round((float)$adjustment['FinalAmount'], 2) : round((float) $lines[0]['TotalAmount'], 2);
        if ($total <= 0) throw new RuntimeException('Financial data is unavailable for this historical prescription. No payment was recorded.');

        $patientId = (int) $lines[0]['PatientID'];
        $paid = tdc_payments_confirmed_total($pdo, 'PrescriptionReference', $base);
        $due = round($total - $paid, 2);
        if ($due <= 0) throw new RuntimeException('This pharmacy bill has no outstanding balance.');
        if ($collection > $due) throw new RuntimeException('Payment cannot exceed the outstanding pharmacy balance of ' . number_format($due, 2) . '.');

        $saleBase = (string) ($lines[0]['PharmacySaleReference'] ?? '');
        $payRef = tdc_workflow_record_payment($pdo, $patientId, 'Pharmacy', $collection, (int) ($_SESSION['user_id'] ?? 0), [
            'PrescriptionReference' => $base,
            'SaleReference' => $saleBase !== '' ? $saleBase : null,
            'PaymentMethod' => $paymentMethod,
        ]);
        if ($payRef) {
            tdc_workflow_post_revenue($pdo, 'REV-PHARM', 'Pharmacy Revenue', $payRef, 'Pharmacy payment for ' . $base, $collection, $paymentMethod);
        }
        tdc_payments_sync_source($pdo, ['PaymentType' => 'Pharmacy', 'PrescriptionReference' => $base, 'PatientID' => $patientId]);
        tdc_audit($pdo, 'payment.recorded', 'payment', (string) $payRef,
            'Pharmacy payment of ' . number_format($collection, 2) . ' recorded against bill ' . $base . ' via ' . $paymentMethod,
            ['bill' => $base, 'amount' => $collection, 'method' => $paymentMethod]);
        $pdo->commit();
        return ['reference' => (string) $payRef, 'amount' => $collection, 'bill' => $base];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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
require_once __DIR__ . '/../includes/billing-adjustments.php';
$paymentMethods = tdc_payment_methods($pdo);
$paymentMethodNames = array_column($paymentMethods, 'MethodName');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$section = $_GET['section'] ?? 'patients';
if ($section !== null && !in_array($section, ALLOWED_SECTIONS, true)) {
    $section = 'patients';
}
$receptionSectionPermissions = ['patients'=>'patients.view','appointments'=>'patients.view','consultations'=>'consultations.view','laboratory'=>'lab_billing.view','pharmacy'=>'pharmacy_billing.view','services'=>'reception.view'];
if ($section !== null && !tdc_can($receptionSectionPermissions[$section])) tdc_forbidden();
// Appointment creation now lives in the patient registration modal. Keep the
// legacy URL as a compatibility redirect so old bookmarks never open a second
// booking screen.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $section === 'consultations' && (string) ($_GET['billing'] ?? '') !== '1') {
    header('Location: patients.php');
    exit;
}
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
    'AllocatedDoctor' => '', 'Remark' => '', 'BookAppointment' => '', 'AppointmentOnly' => '',
    'AppointmentDoctorID' => '', 'AppointmentDate' => '', 'AppointmentReason' => '',
    'AppointmentAmountPaid' => '0', 'AppointmentPaymentMethod' => '',
    'AppointmentFreeConsultation' => '',
    'AppointmentDiscountType' => 'None', 'AppointmentDiscountValue' => '0', 'AppointmentDiscountReason' => '', 'AppointmentTaxRate' => '0',
];

$oldLab = [
    'LaboratoryID' => '', 'PatientID' => '', 'PatientLabel' => '', 'TestName' => '',
    'Description' => '', 'Price' => '', 'IsAvailable' => '1', 'Result' => 'Pending',
    'ResultDate' => '', 'AmountPaid' => '0', 'PaymentStatus' => 'Unpaid',
];

$clinicTimezone = new DateTimeZone('Africa/Mogadishu');
$clinicNow = new DateTimeImmutable('now', $clinicTimezone);
$visitDateMinimum = $clinicNow->format('Y-m-d');
$oldVisit = [
    'PatientID' => '', 'DoctorID' => '', 'VisitDate' => $visitDateMinimum,
    'AmountPaid' => '0', 'PaymentMethod' => '', 'ChiefComplaint' => '',
];
if ($section === 'consultations' && isset($_GET['patient']) && ctype_digit((string) $_GET['patient'])) {
    $oldVisit['PatientID'] = (string) (int) $_GET['patient'];
}

// =======================================================================
// SECTION 9 — POST handler (dispatch by section)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($section, ALLOWED_SECTIONS, true)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        if ($formAction === 'unified_payment') {
            $billType = strtolower(trim((string)($_POST['BillType'] ?? '')));
            $reference = trim((string)($_POST['BillReference'] ?? ''));
            $targetSection = $billType === 'laboratory' ? 'laboratory' : ($billType === 'prescription' ? 'pharmacy' : ($billType === 'service' ? 'services' : 'consultations'));
            if ($billType === 'prescription') $reference = preg_replace('/[^A-Za-z0-9]/', '', $reference);
            try { tdc_unified_bill_payment($pdo,$billType,$reference,$_POST,(int)$_SESSION['user_id'],$paymentMethodNames); tdc_redirect($targetSection,'payment'); }
            catch(Throwable $e){ $errors[]=$e instanceof RuntimeException?$e->getMessage():'The payment update could not be completed. No changes were saved.'; }
        }

        // --- Appointment Info: edit/revisit/import existing visits ----
        if ($formAction === 'unified_payment') {
            // handled above; keep the request in this page so any safe error is rendered.
        } elseif ($section === 'appointments') {
            tdc_require_permission('patients.view');
            if ($formAction === 'edit_appointment' || $formAction === 'create_revisit') {
                $patientId = ctype_digit((string)($_POST['PatientID'] ?? '')) ? (int)$_POST['PatientID'] : 0;
                $visitId = ctype_digit((string)($_POST['VisitID'] ?? '')) ? (int)$_POST['VisitID'] : 0;
                $input = [
                    'AppointmentDoctorID' => trim((string)($_POST['DoctorID'] ?? '')),
                    'AppointmentDate' => trim((string)($_POST['VisitDate'] ?? '')),
                    'AppointmentReason' => trim((string)($_POST['ChiefComplaint'] ?? '')),
                    'AppointmentAmountPaid' => trim((string)($_POST['AmountPaid'] ?? '0')),
                    'AppointmentPaymentMethod' => trim((string)($_POST['PaymentMethod'] ?? '')),
                    'AppointmentFreeConsultation' => !empty($_POST['FreeConsultation']) ? '1' : '',
                    'AppointmentDiscountType' => trim((string)($_POST['DiscountType'] ?? 'None')),
                    'AppointmentDiscountValue' => trim((string)($_POST['DiscountValue'] ?? '0')),
                    'AppointmentDiscountReason' => trim((string)($_POST['DiscountReason'] ?? '')),
                    'AppointmentTaxRate' => '0',
                ];
                if ($patientId < 1 || !(int)tdc_scalar($pdo, 'SELECT COUNT(*) FROM patients WHERE PatientID=:id', ['id'=>$patientId])) $errors[] = 'Please select a valid patient record.';
                if ($formAction === 'edit_appointment' && $visitId < 1) $errors[] = 'Please select a valid appointment.';
                if (!$errors) {
                    try {
                        $pdo->beginTransaction();
                        if ($formAction === 'edit_appointment') {
                            tdc_update_patient_appointment($pdo, $patientId, $visitId, $input);
                        } else {
                            $visitId = tdc_create_patient_appointment($pdo, $patientId, $input, (int)$_SESSION['user_id']);
                        }
                        $pdo->commit();
                        tdc_redirect('appointments', 'saved');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The appointment could not be saved.';
                    }
                }
            } elseif ($formAction === 'import_confirm') {
                $rows = $_SESSION['appointment_import_preview']['rows'] ?? [];
                unset($_SESSION['appointment_import_preview']);
                if (!$rows) { $errors[] = 'The appointment import preview has expired. Please upload the file again.'; }
                if (!$errors) try {
                    $pdo->beginTransaction();
                    foreach ($rows as $rowNumber => $row) {
                        $id = (int)($row['visit_id'] ?? 0); $q=$pdo->prepare('SELECT PatientID FROM visits WHERE VisitID=? FOR UPDATE'); $q->execute([$id]); $existing=$q->fetch();
                        if (!$existing) throw new RuntimeException('Import row '.($rowNumber+2).': visit_id '.$id.' was not found.');
                        tdc_update_patient_appointment($pdo,(int)$existing['PatientID'],$id,['AppointmentDoctorID'=>(string)($row['doctor_id']??''),'AppointmentDate'=>(string)($row['visit_date']??''),'AppointmentReason'=>(string)($row['chief_complaint']??'')]);
                    }
                    $pdo->commit(); tdc_redirect('appointments','saved');
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $errors[]=$e instanceof RuntimeException?$e->getMessage():'The appointment import could not be completed.'; }
            } elseif ($formAction === 'import') {
                require_once __DIR__ . '/../includes/data-transfer.php';
                try {
                    $file = $_FILES['appointment_file'] ?? [];
                    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
                    $rows = $ext === 'xlsx'
                        ? tdc_xlsx_upload_rows($file, ['visit_id','doctor_id','visit_date'], ['visit_id','patient_id','visit_reference','doctor_id','visit_date','chief_complaint'])
                        : tdc_csv_upload_rows($file, ['visit_id','doctor_id','visit_date'], ['visit_id','patient_id','visit_reference','doctor_id','visit_date','chief_complaint']);
                    $seenVisitIds = [];
                    foreach ($rows as $rowNumber => $row) {
                        $id = ctype_digit(trim((string)($row['visit_id'] ?? ''))) ? (int)$row['visit_id'] : 0;
                        if ($id < 1) throw new RuntimeException('Import row '.($rowNumber + 2).': visit_id is required; imports never match patients by phone.');
                        if (!tdc_parse_appointment_datetime((string)($row['visit_date'] ?? ''))) throw new RuntimeException('Import row '.($rowNumber + 2).': visit_date must be a valid date or datetime.');
                        $q = $pdo->prepare('SELECT PatientID FROM visits WHERE VisitID=?'); $q->execute([$id]); $existing = $q->fetch();
                        if (!$existing) throw new RuntimeException('Import row '.($rowNumber + 2).': visit_id '.$id.' was not found.');
                        if (isset($seenVisitIds[$id])) throw new RuntimeException('Import row '.($rowNumber + 2).': visit_id '.$id.' is duplicated in the upload.');
                        $seenVisitIds[$id] = true;
                    }
                    $_SESSION['appointment_import_preview']=['rows'=>$rows,'created_at'=>time()];
                    header('Location: reception.php?section=appointments&preview=1'); exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The appointment import could not be completed.';
                }
            }

        // --- Consultation booking ------------------------------------
        } elseif ($section === 'consultations') {
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
                        $adjustment = tdc_load_bill_adjustment($pdo, 'consultation', (string)$visit['VisitReference']);
                        $finalAmount = $adjustment ? (float)$adjustment['FinalAmount'] : (float)$visit['ConsultationFee'];
                        $newDue = max(0, round($finalAmount - $newPaid, 2));
                        $paymentStatus = tdc_workflow_payment_status($finalAmount, $newPaid);
                        $queueStatus = $paymentStatus === 'Paid' ? 'Waiting' : 'Pending Payment';
                        $stmt = $pdo->prepare('UPDATE visits SET AmountPaid=?,DueBalance=?,PaymentStatus=?,QueueStatus=? WHERE VisitID=?');
                        $stmt->execute([$newPaid,$newDue,$paymentStatus,$queueStatus,$visitId]);
                        // The outstanding balance is derived centrally from the source records.
                        tdc_reconcile_patient_due_balance($pdo, (int) $visit['PatientID']);
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
            $visitDate = tdc_parse_appointment_datetime($oldVisit['VisitDate'], $clinicTimezone);
            $stmt = $pdo->prepare('SELECT ConsultationFee,UserID,WorkingDays,WorkStartTime,WorkEndTime FROM doctors WHERE DoctorID=?');
            $stmt->execute([$doctorId]);
            $doctor = $stmt->fetch();
            if ($visitDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldVisit['VisitDate']) && $doctor) {
                [$hour, $minute] = array_pad(explode(':', substr((string) $doctor['WorkStartTime'], 0, 5)), 2, 0);
                $visitDate->setTime((int) $hour, (int) $minute, 0);
            }
            if ($patientId < 1 || !(int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM patients WHERE PatientID=:id', ['id'=>$patientId])) $errors[] = 'Please select a valid patient.';
            if (!$doctor) $errors[] = 'Please select a valid doctor.';
            elseif (empty($doctor['UserID'])) $errors[] = 'The selected doctor profile must be linked to a Doctor user account before booking.';
            if (!$visitDate) $errors[] = 'Please select a valid consultation date.';
            elseif ($visitDate < new DateTime('today', $clinicTimezone)) $errors[] = 'Consultation date cannot be in the past.';
            elseif ($doctor && !tdc_doctor_is_available($doctor, $visitDate)) $errors[] = 'The selected doctor is not available on the selected date.';
            $fee = $doctor ? round((float) $doctor['ConsultationFee'], 2) : 0.0;
            $consultationAdjustment = null;
            try { $consultationAdjustment = tdc_calculate_bill_adjustment($fee, (string)($_POST['DiscountType'] ?? 'None'), (float)($_POST['DiscountValue'] ?? 0), (float)($_POST['TaxRate'] ?? 0)); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
            if ($consultationAdjustment && $consultationAdjustment['discount_amount'] > 0 && trim((string)($_POST['DiscountReason'] ?? '')) === '') $errors[] = 'A discount reason is required.';
            $finalFee = $consultationAdjustment['final_amount'] ?? $fee;
            if ($amountPaid < 0 || $amountPaid > $finalFee) $errors[] = 'Amount paid cannot exceed the final consultation amount.';
            if ($amountPaid > 0 && !in_array($oldVisit['PaymentMethod'], $paymentMethodNames, true)) $errors[] = 'Please select an active payment method when receiving money.';
            if (!$errors) {
                $paymentStatus = tdc_workflow_payment_status($finalFee, $amountPaid);
                $queueStatus = $paymentStatus === 'Paid' ? 'Waiting' : 'Pending Payment';
                $reference = tdc_workflow_next_reference($pdo, 'visits', 'VisitReference', 'VIS');
                $pdo->beginTransaction();
                $doctorLock = false;
                try {
                    $lock = $pdo->prepare("SELECT GET_LOCK(?, 5)");
                    $lock->execute(['tdc-doctor-' . $doctorId]);
                    $doctorLock = (int) $lock->fetchColumn() === 1;
                    if (!$doctorLock) throw new RuntimeException('The doctor booking calendar is busy. Please try again.');
                    if (tdc_doctor_has_booking_conflict($pdo, $doctorId, $visitDate)) throw new RuntimeException('The doctor already has an appointment during this time.');
                    $stmt = $pdo->prepare('INSERT INTO visits (VisitReference,PatientID,DoctorID,ReceptionistUserID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus,ChiefComplaint) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$reference,$patientId,$doctorId,$_SESSION['user_id'],$visitDate->format('Y-m-d H:i:s'),$finalFee,$amountPaid,max(0,$finalFee-$amountPaid),$paymentStatus,$queueStatus,$oldVisit['ChiefComplaint'] ?: null]);
                    $visitId = (int) $pdo->lastInsertId();
                    if ($consultationAdjustment) tdc_save_bill_adjustment($pdo, 'consultation', $reference, $fee, $consultationAdjustment['discount_type'], $consultationAdjustment['discount_value'], $consultationAdjustment['tax_rate'], $amountPaid, (string)($_POST['DiscountReason'] ?? ''), (string)($_POST['AdjustmentNote'] ?? ''));
                    $stmt = $pdo->prepare('UPDATE patients SET AllocatedDoctor=?, VisitNumber=VisitNumber+1 WHERE PatientID=?');
                    $stmt->execute([$doctorId,$patientId]);
                    // The outstanding balance is derived centrally from the source records.
                    tdc_reconcile_patient_due_balance($pdo, $patientId);
                    $paymentRef = tdc_workflow_record_payment($pdo,$patientId,'Consultation',$amountPaid,(int)$_SESSION['user_id'],['VisitID'=>$visitId,'PaymentMethod'=>$oldVisit['PaymentMethod'] ?: 'Cash']);
                    if ($paymentRef) tdc_workflow_post_revenue($pdo,'REV-CONSULT','Consultation Revenue',$paymentRef,'Consultation payment for '.$reference,$amountPaid);
                    if ($paymentStatus === 'Paid') tdc_workflow_notify($pdo,(int)($doctor['UserID'] ?? 0),'doctoruser','consultation_booked','New consultation booked',$reference.' is fully paid and waiting','doctors.php?visit='.$visitId);
                    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('tdc-doctor-' . $doctorId) . ")");
                    $doctorLock = false;
                    $pdo->commit();
                    tdc_redirect('consultations', 'success');
                } catch (Throwable $e) {
                    if ($doctorLock) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('tdc-doctor-' . $doctorId) . ")");
                    $pdo->rollBack();
                    error_log('[RECEPTION][CONSULTATION] '.$e->getMessage());
                    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The consultation could not be booked. Please try again.';
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
                $oldPatient['BookAppointment'] = (string) ($_POST['BookAppointment'] ?? '');
                $oldPatient['AppointmentOnly'] = (string) ($_POST['AppointmentOnly'] ?? '');
                $oldPatient['AppointmentDoctorID'] = trim((string) ($_POST['AppointmentDoctorID'] ?? ''));
                $oldPatient['AppointmentDate'] = trim((string) ($_POST['AppointmentDate'] ?? ''));
                $oldPatient['AppointmentReason'] = trim((string) ($_POST['AppointmentReason'] ?? ''));
                $oldPatient['AppointmentAmountPaid'] = trim((string) ($_POST['AppointmentAmountPaid'] ?? '0'));
                $oldPatient['AppointmentPaymentMethod'] = trim((string) ($_POST['AppointmentPaymentMethod'] ?? ''));
                $oldPatient['AppointmentFreeConsultation'] = !empty($_POST['AppointmentFreeConsultation']) ? '1' : '';
                $oldPatient['AppointmentDiscountType'] = trim((string) ($_POST['AppointmentDiscountType'] ?? 'None'));
                $oldPatient['AppointmentDiscountValue'] = trim((string) ($_POST['AppointmentDiscountValue'] ?? '0'));
                $oldPatient['AppointmentDiscountReason'] = trim((string) ($_POST['AppointmentDiscountReason'] ?? ''));
                $oldPatient['AppointmentTaxRate'] = trim((string) ($_POST['AppointmentTaxRate'] ?? '0'));

                $isEdit = $oldPatient['PatientID'] !== '' && ctype_digit($oldPatient['PatientID']);
                tdc_require_permission($isEdit ? 'patients.edit' : 'patients.create');
                $errors = tdc_validate_patient_form($oldPatient);
                if (empty($errors)) {
                    try {
                        if ($oldPatient['BookAppointment'] === '1') {
                            $pdo->beginTransaction();
                            $patientId = tdc_save_patient($pdo, $oldPatient, $isEdit, (int) $oldPatient['PatientID']);
                            tdc_create_patient_appointment($pdo, $patientId, $oldPatient, (int) $_SESSION['user_id']);
                            $pdo->commit();
                        } else {
                            tdc_save_patient($pdo, $oldPatient, $isEdit, (int) $oldPatient['PatientID']);
                        }
                        $successFlag = $oldPatient['BookAppointment'] === '1'
                            ? (($oldPatient['AppointmentOnly'] ?? '') === '1' ? 'booked' : ($isEdit ? 'updated_booked' : 'booked_new'))
                            : ($isEdit ? 'updated' : 'saved');
                        tdc_redirect('patients', $successFlag);
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('[RECEPTION][PATIENTS] save failed: ' . $e->getMessage());
                        $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'A system error occurred while saving the patient. Please try again.';
                    }
                }
            }

        // --- Laboratory ------------------------------------------------
        } elseif ($section === 'laboratory') {
            tdc_require_permission('lab_billing.payment');
            if ($formAction === 'adjust') {
                $labId = trim((string)($_POST['LaboratoryID'] ?? ''));
                try {
                    $pdo->beginTransaction();
                    $q=$pdo->prepare('SELECT PatientID,AmountPaid,TotalAmount FROM laboratory WHERE LaboratoryID=? FOR UPDATE'); $q->execute([$labId]); $lab=$q->fetch();
                    if (!$lab) throw new RuntimeException('Laboratory order not found.');
                    $adj=tdc_save_bill_adjustment($pdo,'laboratory',$labId,(float)$lab['TotalAmount'],(string)($_POST['DiscountType']??'None'),(float)($_POST['DiscountValue']??0),(float)($_POST['TaxRate']??0),(float)$lab['AmountPaid'],(string)($_POST['DiscountReason']??''),(string)($_POST['AdjustmentNote']??''));
                    $due=max(0,round($adj['final_amount']-(float)$lab['AmountPaid'],2)); $status=tdc_workflow_payment_status($adj['final_amount'],(float)$lab['AmountPaid']);
                    $pdo->prepare('UPDATE laboratory SET TotalAmount=?,DueBalance=?,PaymentStatus=? WHERE LaboratoryID=?')->execute([$adj['final_amount'],$due,$status,$labId]);
                    $pdo->commit(); tdc_redirect('laboratory','adjusted');
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $errors[]=$e->getMessage(); }
            } elseif ($formAction === 'collect') {
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
                    if(!$lab || (string) ($lab['WorkflowStatus'] ?? '') === 'Cancelled') throw new RuntimeException('This laboratory order is not eligible for payment.');
                    if((float) ($lab['DueBalance'] ?? 0) <= 0) throw new RuntimeException('This laboratory order has no outstanding balance.');
                    if($collection>(float)$lab['DueBalance']) throw new RuntimeException('Payment cannot exceed the outstanding laboratory balance.');
                    $newPaid=round((float)$lab['AmountPaid']+$collection,2);$due=max(0,round((float)$lab['TotalAmount']-$newPaid,2));$status=tdc_workflow_payment_status((float)$lab['TotalAmount'],$newPaid);
                    // Payment collection must not reopen or overwrite completed clinical work.
                    $workflow = (string) $lab['WorkflowStatus'];
                    if (in_array($workflow, ['Requested','Awaiting Payment'], true)) $workflow = $status === 'Paid' ? 'Ready' : 'Awaiting Payment';
                    $stmt=$pdo->prepare('UPDATE laboratory SET AmountPaid=?,DueBalance=?,PaymentStatus=?,WorkflowStatus=? WHERE LaboratoryID=?');$stmt->execute([$newPaid,$due,$status,$workflow,$labId]);
                    $payRef=tdc_workflow_record_payment($pdo,(int)$lab['PatientID'],'Laboratory',$collection,(int)$_SESSION['user_id'],['LaboratoryID'=>$labId,'VisitID'=>$lab['VisitID'],'PaymentMethod'=>$paymentMethod]);
                    if($payRef)tdc_workflow_post_revenue($pdo,'REV-LAB','Laboratory Revenue',$payRef,'Laboratory payment for '.$labId,$collection);
                    if($status==='Paid')tdc_workflow_notify_permission($pdo,'laboratory.process','lab_ready','Paid laboratory request ready',$labId.' is cleared for processing','laboratory.php?result=Pending&payment=Paid','labuser');
                    $pdo->commit();tdc_redirect('laboratory','payment');
                } catch(RuntimeException $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e->getMessage();}
                catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[RECEPTION][LAB PAYMENT] '.$e->getMessage());$errors[]='The laboratory payment could not be recorded.';}
            }
            } else {
                tdc_forbidden();
            }

        // --- Services ----------------------------------------------------
        } elseif ($section === 'services') {
            tdc_require_permission('reception.view');
            if ($formAction === 'adjust') {
                $aid=(int)($_POST['ServiceAssignmentID']??0);
                try {
                    $pdo->beginTransaction();
                    $q=$pdo->prepare('SELECT a.*,s.DefaultAmount FROM service_assignments a JOIN service_subservices s ON s.ServiceID=a.ServiceID WHERE a.AssignmentID=? FOR UPDATE'); $q->execute([$aid]); $a=$q->fetch();
                    if (!$a) throw new RuntimeException('Service bill not found.');
                    $existing=tdc_load_bill_adjustment($pdo,'Service',(string)$a['ServiceReference']);
                    $gross=(float)($existing['GrossAmount']??$a['DefaultAmount']);
                    $adj=tdc_save_bill_adjustment($pdo,'Service',(string)$a['ServiceReference'],$gross,(string)($_POST['DiscountType']??'None'),(float)($_POST['DiscountValue']??0),(float)($_POST['TaxRate']??0),(float)$a['AmountPaid'],(string)($_POST['DiscountReason']??''),(string)($_POST['AdjustmentNote']??''));
                    $due=max(0,round($adj['final_amount']-(float)$a['AmountPaid'],2)); $status=tdc_workflow_payment_status($adj['final_amount'],(float)$a['AmountPaid']);
                    $pdo->prepare('UPDATE service_assignments SET ServiceAmount=?,DueBalance=?,PaymentStatus=?,UpdatedAt=NOW() WHERE AssignmentID=?')->execute([$adj['final_amount'],$due,$status,$aid]);
                    tdc_reconcile_patient_due_balance($pdo,(int)$a['PatientID']); $pdo->commit(); tdc_redirect('services','adjusted');
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $errors[]=$e->getMessage(); }
            } elseif ($formAction === 'collect') {
                $aid=(int)($_POST['ServiceAssignmentID']??0); $collection=is_numeric($_POST['PaymentAmount']??null)?round((float)$_POST['PaymentAmount'],2):-1; $method=(string)($_POST['PaymentMethod']??'Cash');
                if($aid<1)$errors[]='Select a service bill.'; if($collection<=0)$errors[]='Amount received must be greater than zero.'; if(!in_array($method,$paymentMethodNames,true))$errors[]='Select a valid active payment method.';
                if(!$errors){$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM service_assignments WHERE AssignmentID=? FOR UPDATE');$q->execute([$aid]);$a=$q->fetch();if(!$a||$a['AssignmentStatus']==='Cancelled')throw new RuntimeException('This service bill is not eligible for payment.');$adj=tdc_load_bill_adjustment($pdo,'Service',(string)$a['ServiceReference']);$billTotal=$adj?(float)$adj['FinalAmount']:(float)$a['ServiceAmount'];$due=max(0,round($billTotal-(float)$a['AmountPaid'],2));if($due<=0)throw new RuntimeException('This service bill has no outstanding balance.');if($collection>$due)throw new RuntimeException('Payment cannot exceed the outstanding balance.');$newPaid=round((float)$a['AmountPaid']+$collection,2);$newDue=max(0,round($billTotal-$newPaid,2));$status=tdc_workflow_payment_status($billTotal,$newPaid);$pdo->prepare('UPDATE service_assignments SET AmountPaid=?,DueBalance=?,PaymentStatus=?,UpdatedAt=NOW() WHERE AssignmentID=?')->execute([$newPaid,$newDue,$status,$aid]);$pr=tdc_workflow_record_payment($pdo,(int)$a['PatientID'],'Service',$collection,(int)$_SESSION['user_id'],['ServiceAssignmentID'=>$aid,'PaymentMethod'=>$method]);tdc_workflow_post_revenue($pdo,'REV-SERVICE','Service Revenue',$pr,'Service payment for '.$a['ServiceReference'],$collection,$method);tdc_reconcile_patient_due_balance($pdo,(int)$a['PatientID']);$pdo->commit();tdc_redirect('services','payment');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e->getMessage();}}
            }

        // --- Pharmacy ----------------------------------------------------
        } elseif ($section === 'pharmacy') {
            tdc_require_permission('pharmacy_billing.payment');
            if ($formAction === 'adjust') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['BillRef'] ?? ''));
                try {
                    $pdo->beginTransaction();
                    $q=$pdo->prepare('SELECT PatientID,TotalAmount FROM prescriptions WHERE PrescriptionID LIKE ? ORDER BY PrescriptionID LIMIT 1 FOR UPDATE'); $q->execute([$base.'-%']); $bill=$q->fetch();
                    if (!$bill) throw new RuntimeException('Pharmacy bill not found.');
                    $paid=tdc_payments_confirmed_total($pdo,'PrescriptionReference',$base);
                    $adj=tdc_save_bill_adjustment($pdo,'prescription',$base,(float)$bill['TotalAmount'],(string)($_POST['DiscountType']??'None'),(float)($_POST['DiscountValue']??0),(float)($_POST['TaxRate']??0),$paid,(string)($_POST['DiscountReason']??''),(string)($_POST['AdjustmentNote']??''));
                    $due=max(0,round($adj['final_amount']-$paid,2)); $status=tdc_workflow_payment_status($adj['final_amount'],$paid);
                    // Prescription rows do not have a bill-level PaymentStatus column;
                    // the billing list derives status from AmountPaid/DueBalance.
                    $q=$pdo->prepare('UPDATE prescriptions SET TotalAmount=?,DueBalance=? WHERE PrescriptionID LIKE ?'); $q->execute([$adj['final_amount'],$due,$base.'-%']);
                    $pdo->commit(); tdc_redirect('pharmacy','adjusted');
                } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); $errors[]=$e->getMessage(); }
            } elseif ($formAction === 'collect') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['BillRef'] ?? ''));
                $collection = is_numeric($_POST['PaymentAmount'] ?? null) ? round((float) $_POST['PaymentAmount'], 2) : -1;
                $paymentMethod = (string) ($_POST['PaymentMethod'] ?? 'Cash');
                if (!in_array($paymentMethod, $paymentMethodNames, true)) $errors[] = 'Please select a valid active payment method.';
                if (!$errors) {
                    try {
                        tdc_collect_pharmacy_payment($pdo, $base, $collection, $paymentMethod);
                        tdc_redirect('pharmacy', 'payment');
                    } catch (RuntimeException $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = $e->getMessage();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('[RECEPTION][PHARMACY PAYMENT] ' . $e->getMessage());
                        $errors[] = 'The pharmacy payment could not be recorded. Please try again.';
                    }
                }
            } else {
                // One authoritative lifecycle: Reception never creates, edits
                // or deletes pharmacy bills — it only records payments.
                $errors[] = 'Pharmacy bills are authored by doctors. Reception manages bill adjustments and payments only.';
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above via header()).
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 10 — GET data loading for display
// =======================================================================
$doctors = $pdo->query('SELECT DoctorID, DoctorName, Specialty, ConsultationFee, UserID, WorkingDays, WorkStartTime, WorkEndTime FROM doctors ORDER BY DoctorName ASC')->fetchAll();
$appointmentBookingsByDoctor = [];
$bookingStmt = $pdo->query("SELECT DoctorID, DATE_FORMAT(VisitDate, '%Y-%m-%dT%H:%i') AS Slot FROM visits WHERE QueueStatus <> 'Cancelled' AND VisitDate >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
foreach ($bookingStmt->fetchAll() as $booking) $appointmentBookingsByDoctor[(int) $booking['DoctorID']][] = $booking['Slot'];
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

$appointmentSearch = trim((string)($_GET['q'] ?? ''));
$appointmentDoctor = ctype_digit((string)($_GET['doctor'] ?? '')) ? (int)$_GET['doctor'] : 0;
$appointmentType = array_key_exists((string)($_GET['type'] ?? ''), PATIENT_TYPE_OPTIONS) ? (string)$_GET['type'] : '';
$appointmentStatus = in_array((string)($_GET['status'] ?? ''), ['Pending Payment','Waiting','In Consultation','Completed','Cancelled'], true) ? (string)$_GET['status'] : '';
$appointmentPayment = in_array((string)($_GET['payment'] ?? ''), ['Unpaid','Partial','Paid'], true) ? (string)$_GET['payment'] : '';
$appointmentDate = tdc_is_valid_date((string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : '';
$appointmentVisits = [];
$appointmentExportUrl = '';
$appointmentImportPreview = ($section === 'appointments' && !empty($_GET['preview'])) ? ($_SESSION['appointment_import_preview']['rows'] ?? []) : [];
if ($section === 'appointments') {
    $where = [];
    $params = [];
    if ($appointmentSearch !== '') { $where[] = '(CAST(p.PatientID AS CHAR) LIKE ? OR v.VisitReference LIKE ? OR p.PatientName LIKE ? OR p.PatientPhone LIKE ?)'; $like='%'.$appointmentSearch.'%'; array_push($params,$like,$like,$like,$like); }
    if ($appointmentDoctor > 0) { $where[]='v.DoctorID=?'; $params[]=$appointmentDoctor; }
    if ($appointmentType !== '') { $where[]='p.PatientType=?'; $params[]=$appointmentType; }
    if ($appointmentStatus !== '') { $where[]='v.QueueStatus=?'; $params[]=$appointmentStatus; }
    if ($appointmentPayment !== '') { $where[]='v.PaymentStatus=?'; $params[]=$appointmentPayment; }
    if ($appointmentDate !== '') { $where[]='DATE(v.VisitDate)=?'; $params[]=$appointmentDate; }
    $sqlWhere = $where ? ' WHERE '.implode(' AND ', $where) : '';
    if (($_GET['download'] ?? '') === 'appointment-template') {
        require_once __DIR__ . '/../includes/data-transfer.php';
        tdc_xlsx_download('appointment-info-template.xlsx',['visit_id','patient_id','visit_reference','doctor_id','visit_date','chief_complaint'],[]);
    }
    if (($_GET['download'] ?? '') === 'appointments-csv' || ($_GET['download'] ?? '') === 'appointments-xlsx') {
        require_once __DIR__ . '/../includes/data-transfer.php';
        $q=$pdo->prepare('SELECT v.VisitID,v.VisitReference,v.PatientID,p.PatientName,p.PatientPhone,p.Gender,p.Age,p.PatientType,d.DoctorName,v.VisitDate,v.ConsultationFee,v.AmountPaid,v.DueBalance,v.PaymentStatus,v.QueueStatus,v.ChiefComplaint FROM visits v JOIN patients p ON p.PatientID=v.PatientID LEFT JOIN doctors d ON d.DoctorID=v.DoctorID'.$sqlWhere.' ORDER BY v.VisitDate DESC LIMIT 5000'); $q->execute($params); $rows=[];
        foreach($q->fetchAll() as $r) $rows[]=[(string)$r['VisitID'],(string)$r['VisitReference'],(string)$r['PatientID'],(string)$r['PatientName'],(string)$r['PatientPhone'],(string)$r['Gender'],(string)$r['Age'],(string)$r['PatientType'],(string)$r['DoctorName'],(string)$r['VisitDate'],number_format((float)$r['ConsultationFee'],2),number_format((float)$r['AmountPaid'],2),number_format((float)$r['DueBalance'],2),(string)$r['PaymentStatus'],(string)$r['QueueStatus'],(string)$r['ChiefComplaint']];
        $headers=['visit_id','visit_reference','patient_id','patient_name','phone','gender','age','patient_type','doctor_name','visit_date','consultation_fee','amount_paid','due_balance','payment_status','queue_status','chief_complaint'];
        if (($_GET['download'] ?? '') === 'appointments-xlsx') tdc_xlsx_download('appointments-'.date('Y-m-d').'.xlsx',$headers,$rows); else tdc_csv_download('appointments-'.date('Y-m-d').'.csv',$headers,$rows);
    }
    $q=$pdo->prepare('SELECT v.*,p.PatientName,p.PatientPhone,p.Gender,p.Age,p.PatientType,p.Remark,d.DoctorName FROM visits v JOIN patients p ON p.PatientID=v.PatientID LEFT JOIN doctors d ON d.DoctorID=v.DoctorID'.$sqlWhere.' ORDER BY v.VisitDate DESC,v.VisitID DESC LIMIT 500'); $q->execute($params); $appointmentVisits=$q->fetchAll();
    $appointmentExportUrl='reception.php?'.http_build_query(array_filter(['section'=>'appointments','download'=>'appointments-csv','q'=>$appointmentSearch,'doctor'=>$appointmentDoctor,'type'=>$appointmentType,'status'=>$appointmentStatus,'payment'=>$appointmentPayment,'date'=>$appointmentDate],static fn($v)=>$v!==''&&$v!==0));
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
    $consultationAdjustments = [];
    foreach ($consultationVisits as $consultationVisit) {
        $adj = tdc_load_bill_adjustment($pdo, 'consultation', (string) $consultationVisit['VisitReference']);
        if ($adj) $consultationAdjustments[(string) $consultationVisit['VisitReference']] = $adj;
    }

    $consultationExportUrl = 'reception.php?' . http_build_query(array_filter([
        'section' => 'consultations', 'export' => 'csv', 'q' => $consultationSearch,
        'status' => $consultationStatus, 'from_date' => $consultationFrom, 'to_date' => $consultationTo,
    ], static fn($value): bool => $value !== '' && $value !== null));
}

$billingPatientSearch = trim((string) ($_GET['patient_q'] ?? ''));
$labBills = [];
if ($section === 'laboratory') {
    $labSql =
        'SELECT l.*, p.PatientName, p.PatientPhone, v.VisitReference, d.DoctorName FROM laboratory l
         JOIN patients p ON p.PatientID = l.PatientID
         LEFT JOIN visits v ON v.VisitID = l.VisitID
         LEFT JOIN doctors d ON d.DoctorID = l.DoctorID
         ' . ($billingPatientSearch !== '' ? 'WHERE (p.PatientName LIKE :patient_q OR p.PatientPhone LIKE :patient_q_phone) ' : '') .
         'ORDER BY l.OrderDate DESC LIMIT 200';
    $stmt = $pdo->prepare($labSql);
    if ($billingPatientSearch !== '') {
        $like = '%' . $billingPatientSearch . '%';
        $stmt->execute(['patient_q' => $like, 'patient_q_phone' => $like]);
    } else {
        $stmt->execute();
    }
    $labBills = $stmt->fetchAll();
}
$labAdjustments = [];
foreach ($labBills as $labBill) { $adj = tdc_load_bill_adjustment($pdo, 'laboratory', (string)$labBill['LaboratoryID']); if ($adj) $labAdjustments[(string)$labBill['LaboratoryID']] = $adj; }

$serviceBills = [];
if ($section === 'services') {
    $serviceSql = 'SELECT a.*,p.PatientName,p.PatientPhone,d.DoctorName,s.ServiceName,c.CategoryName FROM service_assignments a JOIN patients p ON p.PatientID=a.PatientID LEFT JOIN doctors d ON d.DoctorID=a.DoctorID JOIN service_subservices s ON s.ServiceID=a.ServiceID JOIN service_categories c ON c.ServiceCategoryID=s.ServiceCategoryID' .
        ($billingPatientSearch !== '' ? ' WHERE (p.PatientName LIKE :patient_q OR p.PatientPhone LIKE :patient_q_phone)' : '') .
        ' ORDER BY a.AssignedAt DESC LIMIT 200';
    $stmt = $pdo->prepare($serviceSql);
    if ($billingPatientSearch !== '') {
        $like = '%' . $billingPatientSearch . '%';
        $stmt->execute(['patient_q' => $like, 'patient_q_phone' => $like]);
    } else {
        $stmt->execute();
    }
    $serviceBills = $stmt->fetchAll();
}
$serviceAdjustments = [];
foreach ($serviceBills as $serviceBill) { $adj = tdc_load_bill_adjustment($pdo, 'Service', (string)$serviceBill['ServiceReference']); if ($adj) $serviceAdjustments[(string)$serviceBill['ServiceReference']] = $adj; }

$pharmacyBills = [];
if ($section === 'pharmacy') {
    $stmt = $pdo->prepare(
        "SELECT SUBSTRING_INDEX(PrescriptionID, '-', 1) AS BillRef,
                MIN(PatientID) AS PatientID, MIN(PatientName) AS PatientName, MIN(PatientPhone) AS PatientPhone,
                MIN(DoctorID) AS DoctorID, COUNT(*) AS LineCount,
                MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                MIN(DueBalance) AS DueBalance, MIN(PrescriptionDate) AS PrescriptionDate
         FROM prescriptions
         " . ($billingPatientSearch !== '' ? "WHERE (PatientName LIKE :patient_q OR PatientPhone LIKE :patient_q_phone)" : '') . "
         GROUP BY BillRef
         ORDER BY PrescriptionDate DESC
         LIMIT 200"
    );
    if ($billingPatientSearch !== '') {
        $like = '%' . $billingPatientSearch . '%';
        $stmt->execute(['patient_q' => $like, 'patient_q_phone' => $like]);
    } else {
        $stmt->execute();
    }
    $pharmacyBills = $stmt->fetchAll();

    // Reception pharmacy billing is read-only: the bill list and payment
    // collection. Bills are never created, edited or deleted here.
}
$pharmacyAdjustments = [];
foreach ($pharmacyBills as $pharmacyBill) { $adj = tdc_load_bill_adjustment($pdo, 'prescription', (string)$pharmacyBill['BillRef']); if ($adj) $pharmacyAdjustments[(string)$pharmacyBill['BillRef']] = $adj; }

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'reception.php'));

$patientStatus = (string) ($_GET['status'] ?? '');
$justSaved   = $patientStatus === 'saved' || isset($_GET['success']);
$justDeleted = $patientStatus === 'deleted' || isset($_GET['deleted']);
$justVisited = isset($_GET['visited']);
$patientToast = match ($patientStatus) {
    'booked' => 'Appointment booked successfully.',
    'booked_new' => 'Patient and appointment booked successfully.',
    'updated_booked' => 'Patient updated and appointment booked successfully.',
    'updated' => 'Patient updated successfully.',
    'deleted' => 'Patient deleted successfully.',
    default => $justSaved ? 'Patient saved successfully.' : ($justDeleted ? 'Patient deleted successfully.' : ($justVisited ? 'Visit recorded successfully.' : '')),
};
$hasPatientToast = $patientToast !== '';
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
    .doctor-availability{display:flex;flex-direction:column;gap:3px;margin-top:8px;padding:9px 10px;border:1px solid #cfd5f3;border-left:4px solid var(--navy);border-radius:5px;background:#f5f6ff;color:var(--navy);font-size:12px;line-height:1.35}
    .doctor-availability strong{font-size:11px;text-transform:uppercase;letter-spacing:.03em}
    .appointment-preview{margin-top:8px;padding:8px 10px;border-radius:5px;background:#f5f6ff;color:var(--navy);font-size:12px;font-weight:600}
    .appointment-preview.invalid{background:#fff1f1;color:#a12626}
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
    .appointment-toolbar{display:grid;grid-template-columns:minmax(300px,1fr) repeat(4,minmax(145px,175px)) auto;gap:12px;align-items:center;padding:16px;border:1px solid #dce1f0;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(33,45,106,.06);margin-bottom:18px}.appointment-toolbar .tdc-search{min-width:0}.appointment-toolbar .tdc-search input{width:100%;min-width:0;height:44px}.appointment-toolbar select,.appointment-toolbar input[type=date]{height:44px;min-width:0;width:100%;border:1px solid #d5daf0;border-radius:8px;padding:0 11px;background:#fff;color:#27315f;font:inherit}.appointment-toolbar .btn{height:44px;white-space:nowrap}.appointment-toolbar .toolbar-spacer{display:none}.appointment-toolbar .export-menu{grid-column:1/-1;justify-self:end;margin-top:2px}.appointment-toolbar>[data-modal-open]{grid-column:auto}.appointment-toolbar .export-menu-panel{min-width:170px}.tdc-modal-body>label,.tdc-modal-body .appointment-modal-grid label{font-size:12px;font-weight:700;color:#3d466c}.tdc-modal-body input:not([type=checkbox]),.tdc-modal-body select{height:44px;min-height:44px;border:1px solid #d3d9ee;border-radius:8px;padding:0 12px;background:#fff;color:#26305e;font:inherit;transition:border-color .15s,box-shadow .15s}.tdc-modal-body textarea{min-height:92px;border:1px solid #d3d9ee;border-radius:8px;padding:11px 12px;background:#fff;color:#26305e;font:inherit;resize:vertical}.tdc-modal-body input:focus,.tdc-modal-body select:focus,.tdc-modal-body textarea:focus,.appointment-toolbar select:focus,.appointment-toolbar input:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(46,49,146,.12)}.tdc-modal-body input:disabled,.tdc-modal-body select:disabled{background:#eef0f8;color:#68718f;cursor:not-allowed}.tdc-modal-footer button{height:42px;min-width:118px;padding:0 20px;border:0;border-radius:8px;font:600 13px 'Google Sans',sans-serif;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.tdc-modal-footer .btn-secondary{background:#edf0f7;color:#39436b}.tdc-modal-footer .btn-primary{background:var(--navy);color:#fff}.tdc-modal-footer .btn-success{background:#159957;color:#fff}.tdc-modal-footer button:disabled{opacity:.6;cursor:not-allowed}.appointment-finance-card{border:1px solid #d5dbef;border-radius:12px;padding:18px;background:linear-gradient(180deg,#fafbff,#f4f6ff);box-shadow:inset 0 1px 0 #fff}.appointment-finance-card h3{font-size:15px;border-bottom:1px solid #dce1ef;padding-bottom:11px}.appointment-finance-card .finance-final strong,.appointment-finance-card .finance-due strong{font-size:19px;font-variant-numeric:tabular-nums}.appointment-finance-card .finance-final{padding-top:12px}.appointment-finance-card .finance-due{margin-top:3px;padding-top:12px}.free-consultation-toggle{cursor:pointer;transition:border-color .15s,background .15s,box-shadow .15s}.free-consultation-toggle:has(input:checked){border-color:#159957;background:#effaf4;box-shadow:0 0 0 3px rgba(21,153,87,.09)}.free-consultation-toggle strong{font-size:13px;color:#26305e}.free-consultation-toggle small{line-height:1.4}.appointment-summary-card{padding:18px}.appointment-summary-card>div{row-gap:10px}.data-table td:nth-child(6),.data-table td:nth-child(7),.data-table td:nth-child(8){text-align:right;font-variant-numeric:tabular-nums}.data-table th:nth-child(6),.data-table th:nth-child(7),.data-table th:nth-child(8){text-align:right}@media(max-width:1100px){.appointment-toolbar{grid-template-columns:minmax(280px,1fr) repeat(2,minmax(150px,1fr))}.appointment-toolbar .filter-select:nth-of-type(n+3),.appointment-toolbar input[type=date]{grid-column:span 1}.appointment-toolbar .export-menu{grid-column:auto;justify-self:start}}@media(max-width:760px){.appointment-toolbar{grid-template-columns:1fr 1fr}.appointment-toolbar .tdc-search{grid-column:1/-1}.appointment-toolbar .appointment-toolbar-actions,.appointment-toolbar .export-menu{grid-column:1/-1}.appointment-toolbar>[data-modal-open],.appointment-toolbar>a:last-child{width:100%}.appointment-modal-grid{grid-template-columns:1fr}.tdc-modal-footer{justify-content:stretch}.tdc-modal-footer button{flex:1}.appointment-summary-card>div{grid-template-columns:1fr 1fr}}
    body.modal-open{overflow:hidden}.tdc-modal{position:fixed;inset:0;z-index:2000;display:grid;place-items:center;padding:24px;background:rgba(24,29,76,.62)}.tdc-modal[hidden]{display:none}.tdc-modal-card{width:min(900px,100%);max-height:calc(100vh - 48px);overflow:auto;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(10,15,60,.3)}.tdc-modal-header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;padding:20px 24px;background:var(--navy);color:#fff}.tdc-modal-header h2{font-size:20px;margin:0}.tdc-modal-header p{margin:5px 0 0;color:rgba(255,255,255,.75);font-size:12px}.tdc-modal-close{border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:6px;font-size:22px;width:32px;height:32px;cursor:pointer}.tdc-modal-body{padding:24px;display:grid;gap:18px}.tdc-modal-body label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#39436b}.tdc-modal-body input,.tdc-modal-body select,.tdc-modal-body textarea{width:100%;min-height:40px;border:1px solid #d5daf0;border-radius:7px;padding:9px 11px;font:inherit;color:#25305f;background:#fff}.tdc-modal-body textarea{min-height:84px;resize:vertical}.appointment-summary-card,.appointment-finance-card{border:1px solid #dfe3f1;border-radius:10px;padding:16px;background:#f8f9ff}.appointment-summary-card h3,.appointment-finance-card h3{margin:0 0 12px;color:var(--navy);font-size:14px}.appointment-summary-card>div{display:grid;grid-template-columns:repeat(4,1fr);gap:6px 16px}.appointment-summary-card span{font-size:11px;color:#6b7393}.appointment-summary-card strong{font-size:13px;color:#202957}.appointment-modal-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:22px}.appointment-modal-grid>div{display:grid;gap:14px}.appointment-finance-card{display:grid;align-content:start;gap:10px}.appointment-finance-card>div{display:flex;justify-content:space-between;border-bottom:1px solid #e1e4f0;padding:7px 0;color:#59617e;font-size:12px}.appointment-finance-card strong{color:#1d2756}.appointment-finance-card .finance-final,.appointment-finance-card .finance-due{border-top:2px solid var(--navy);border-bottom:0;color:var(--navy);font-weight:800}.doctor-meta{padding:9px 11px;border-left:3px solid var(--navy);background:#f1f3ff;color:#59617e;font-size:12px}.free-consultation-toggle{display:flex!important;grid-template-columns:auto 1fr;align-items:flex-start;gap:9px;padding:12px;border:1px solid #cfd5f0;border-radius:8px;background:#f8f9ff}.free-consultation-toggle input{width:auto!important;min-height:auto!important;margin-top:2px}.free-consultation-toggle small{display:block;color:#727a97;font-weight:400;margin-top:3px}.tdc-modal-footer{display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e3e6f1;padding-top:16px}.appointment-dropzone{display:grid!important;place-items:center;gap:8px;padding:36px 20px;border:2px dashed #aeb7df;border-radius:12px;background:#f8f9ff;text-align:center;cursor:pointer}.appointment-dropzone strong{font-size:16px;color:var(--navy)}.appointment-dropzone span,.appointment-dropzone em{font-size:12px;color:#707897;font-style:normal;font-weight:400}.appointment-dropzone input{display:none}.appointment-requirements{padding:12px 14px;border-radius:8px;background:#f1f3ff;color:#59617e;font-size:12px;line-height:1.7}.appointment-import-preview{max-width:1200px;margin:0 0 18px;padding:18px;border:1px solid #d8ddef;border-radius:12px;background:#f8f9ff}.appointment-import-preview h3{margin:0 0 5px;color:var(--navy)}.export-menu{position:relative}.export-menu summary{list-style:none}.export-menu summary::-webkit-details-marker{display:none}.export-menu-panel{position:absolute;right:0;top:calc(100% + 5px);z-index:20;min-width:150px;padding:6px;background:#fff;border:1px solid #d5daf0;border-radius:8px;box-shadow:0 8px 24px rgba(20,30,80,.14)}.export-menu-panel a{display:block;padding:8px 10px;color:var(--navy);font-size:12px;text-decoration:none}.export-menu-panel a:hover{background:#f1f3ff}@media(max-width:760px){.tdc-modal{padding:10px}.tdc-modal-card{max-height:calc(100vh - 20px)}.appointment-modal-grid{grid-template-columns:1fr}.appointment-summary-card>div{grid-template-columns:1fr 1fr}.tdc-modal-body{padding:16px}}




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

    /* Appointment Info uses explicit toolbar rows so the controls remain readable
       at desktop widths and wrap cleanly on smaller screens. */
    .appointment-toolbar{display:block;padding:16px;margin-bottom:18px}
    .appointment-toolbar-search{display:grid;grid-template-columns:minmax(260px,1fr) auto auto;gap:10px;align-items:center}
    .appointment-toolbar-search .tdc-search{position:relative;min-width:0}
    .appointment-toolbar-search .tdc-search input{width:100%;height:44px;padding-right:42px}
    .appointment-search-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);width:26px;height:26px;border:0;border-radius:50%;background:transparent;color:#69718f;font-size:20px;line-height:1;cursor:pointer}
    .appointment-search-clear:hover:not(:disabled),.appointment-search-clear:focus-visible{background:#eef0f8;color:var(--navy);outline:2px solid rgba(46,49,146,.2);outline-offset:1px}
    .appointment-search-clear:disabled{visibility:hidden}
    .appointment-toolbar-filters{display:grid;grid-template-columns:repeat(4,minmax(145px,1fr)) minmax(170px,1.15fr);gap:10px;margin-top:12px}
    .appointment-toolbar-filters select,.appointment-date-filter input{height:44px;min-width:0;width:100%;border:1px solid #d5daf0;border-radius:8px;padding:0 11px;background:#fff;color:#27315f;font:inherit}
    .appointment-date-filter{display:grid;gap:4px;color:#69718f;font-size:11px;font-weight:600}
    .appointment-toolbar-actions{display:flex;justify-content:flex-end;gap:10px;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid #e4e7f2}
    .appointment-toolbar .btn,.appointment-toolbar summary{height:44px;min-height:44px;white-space:nowrap}
    .appointment-toolbar .export-menu summary{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:0 14px}
    .appointment-toolbar .export-menu-panel{min-width:190px}
    @media(max-width:900px){.appointment-toolbar-search{grid-template-columns:minmax(220px,1fr) auto auto}.appointment-toolbar-filters{grid-template-columns:repeat(2,minmax(150px,1fr))}.appointment-toolbar-actions{justify-content:flex-start;flex-wrap:wrap}}
    @media(max-width:560px){.appointment-toolbar-search{grid-template-columns:1fr 44px}.appointment-toolbar-search .btn{grid-column:span 1}.appointment-toolbar-search .btn-secondary{grid-column:span 1}.appointment-toolbar-filters{grid-template-columns:1fr}.appointment-toolbar-actions>*{width:100%}.appointment-toolbar-actions .export-menu summary{width:100%}}
</style>
<link rel="stylesheet" href="../assets/clinic.css?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.css')) ?>">
<script src="../assets/appointment-calendar.js?v=<?= filemtime(__DIR__ . '/../assets/appointment-calendar.js') ?>"></script>
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
    <?php if (tdc_can('patients.view')): ?><a href="reception.php?section=appointments"<?= $section === 'appointments' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('calendar', 16) ?><span>Appointment Info</span></a><?php endif; ?>
    <?php if (tdc_can('lab_billing.view')): ?><a href="reception.php?section=laboratory"<?= $section === 'laboratory' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('flask', 16) ?><span>Laboratory Billing</span></a><?php endif; ?>
    <?php if (tdc_can('pharmacy_billing.view')): ?><a href="reception.php?section=pharmacy"<?= $section === 'pharmacy' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('pill', 16) ?><span>Pharmacy Billing</span></a><?php endif; ?>
    <?php if (tdc_can('reception.view')): ?><a href="reception.php?section=services"<?= $section === 'services' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('grid', 16) ?><span>Service Billing</span></a><?php endif; ?>
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
        <?php if(tdc_can('reception.view')):?><a href="reception.php?section=services" class="setup-card">
            <div class="setup-icon"><?= tdc_icon('grid', 22) ?></div><div><div class="setup-card-title">Service Bills</div><div class="setup-card-desc">Collect payments for doctor-assigned services.</div><span class="setup-card-cta">Open service billing</span></div>
        </a><?php endif;?>
    </div>

    <section class="secondary-tools" aria-labelledby="reception-tools-title">
        <h2 id="reception-tools-title">Other reception tools</h2>
        <div class="secondary-tool-grid">
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
    <?php if ($section === 'appointments'): ?>

    <div class="welcome-title">Appointment Info</div>
    <div class="welcome-sub">Search patient appointments, edit eligible visits, and create a new visit for a returning patient. Each revisit keeps the same Patient ID and receives a new Visit ID.</div>

    <form class="table-command-bar appointment-toolbar" method="get" action="reception.php">
        <input type="hidden" name="section" value="appointments">
        <div class="appointment-toolbar-search">
            <?= tdc_search_field('q', $appointmentSearch, 'Search patient, phone, Patient ID or Visit Reference...') ?>
            <button class="appointment-search-clear" type="button" id="appointment-clear-search" aria-label="Clear appointment search" title="Clear search"<?= $appointmentSearch === '' ? ' disabled' : '' ?>>×</button>
            <button class="btn-primary btn btn-sm" type="submit"><?= tdc_icon('filter', 14) ?><span>Apply Filters</span></button>
            <a class="btn-secondary btn btn-sm" href="reception.php?section=appointments">Reset</a>
        </div>
        <div class="appointment-toolbar-filters">
            <label class="filter-select"><select name="doctor" aria-label="Doctor"><option value="">All doctors</option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['DoctorID'] ?>"<?= $appointmentDoctor===(int)$d['DoctorID']?' selected':'' ?>><?= tdc_e($d['DoctorName']) ?></option><?php endforeach; ?></select></label>
            <label class="filter-select"><select name="type" aria-label="Patient type"><option value="">All patient types</option><?php foreach(PATIENT_TYPE_OPTIONS as $k=>$v): ?><option value="<?= tdc_e($k) ?>"<?= $appointmentType===$k?' selected':'' ?>><?= tdc_e($v) ?></option><?php endforeach; ?></select></label>
            <label class="filter-select"><select name="status" aria-label="Queue status"><option value="">All queue states</option><?php foreach(['Pending Payment','Waiting','In Consultation','Completed','Cancelled'] as $v): ?><option value="<?= tdc_e($v) ?>"<?= $appointmentStatus===$v?' selected':'' ?>><?= tdc_e($v) ?></option><?php endforeach; ?></select></label>
            <label class="filter-select"><select name="payment" aria-label="Payment status"><option value="">All payment states</option><?php foreach(PAYMENT_STATUS_OPTIONS as $k=>$v): ?><option value="<?= tdc_e($k) ?>"<?= $appointmentPayment===$k?' selected':'' ?>><?= tdc_e($v) ?></option><?php endforeach; ?></select></label>
            <label class="appointment-date-filter"><span>Appointment date</span><input type="date" name="date" value="<?= tdc_e($appointmentDate) ?>" aria-label="Appointment date"></label>
        </div>
        <div class="appointment-toolbar-actions">
            <button class="btn-secondary btn btn-sm" type="button" data-modal-open="appointment-import-modal"><?= tdc_icon('upload', 14) ?><span>Import Appointments</span></button>
            <details class="export-menu"><summary class="btn-secondary btn btn-sm"><?= tdc_icon('download', 14) ?><span>Export</span><span aria-hidden="true">▾</span></summary><div class="export-menu-panel"><a href="<?= tdc_e($appointmentExportUrl) ?>">Export CSV</a><a href="<?= tdc_e(str_replace('appointments-csv','appointments-xlsx',$appointmentExportUrl)) ?>">Export Excel (.xlsx)</a></div></details>
            <a class="btn-secondary btn btn-sm" href="reception.php?section=appointments&download=appointment-template"><?= tdc_icon('download', 14) ?><span>Download Template</span></a>
        </div>
    </form>

    <?php if($appointmentImportPreview): ?><section class="appointment-import-preview"><h3>Import preview</h3><p><?= count($appointmentImportPreview) ?> row(s) validated. No records have changed yet.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="import_confirm"><button class="btn-primary btn-sm" type="submit">Confirm Import</button> <a class="btn-secondary btn-sm" href="reception.php?section=appointments">Cancel</a></form></section><?php endif; ?>

    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Visit</th><th>Patient</th><th>Patient ID / Phone</th><th>Doctor</th><th>Date</th><th>Fee</th><th>Paid</th><th>Due</th><th>Payment</th><th>Queue</th><th>Actions</th></tr></thead><tbody>
    <?php if (!$appointmentVisits): ?><?= tdc_empty_state('calendar','No appointment records found','Try a broader search or clear the filters.','',11) ?><?php else: foreach($appointmentVisits as $a): ?>
        <tr><td><strong><?= tdc_e((string)$a['VisitReference']) ?></strong><br><span class="cell-sub">Visit ID <?= (int)$a['VisitID'] ?></span></td><td><?= tdc_e((string)$a['PatientName']) ?><br><span class="cell-sub"><?= tdc_e((string)($a['PatientType']??'')) ?></span></td><td><?= (int)$a['PatientID'] ?><br><span class="cell-sub"><?= tdc_e((string)($a['PatientPhone']??'—')) ?></span></td><td><?= tdc_e((string)($a['DoctorName']??'Unassigned')) ?></td><td><?= tdc_e(date('d M Y',strtotime((string)$a['VisitDate']))) ?></td><td><?= number_format((float)$a['ConsultationFee'],2) ?></td><td><?= number_format((float)$a['AmountPaid'],2) ?></td><td><?= number_format((float)$a['DueBalance'],2) ?></td><td><?= tdc_badge((string)$a['PaymentStatus']) ?></td><td><?= tdc_badge((string)$a['QueueStatus']) ?></td><td><div class="row-actions"><button type="button" class="btn-secondary btn-sm" data-appointment-action="edit" data-visit-id="<?= (int)$a['VisitID'] ?>" data-patient-id="<?= (int)$a['PatientID'] ?>" data-doctor-id="<?= (int)$a['DoctorID'] ?>" data-date="<?= tdc_e(date('Y-m-d\TH:i',strtotime((string)$a['VisitDate']))) ?>" data-remark="<?= tdc_e((string)($a['ChiefComplaint']??'')) ?>" data-patient-name="<?= tdc_e((string)$a['PatientName']) ?>" data-reference="<?= tdc_e((string)$a['VisitReference']) ?>">Edit</button><button type="button" class="btn-success btn-sm" data-appointment-action="revisit" data-visit-id="<?= (int)$a['VisitID'] ?>" data-patient-id="<?= (int)$a['PatientID'] ?>" data-doctor-id="<?= (int)$a['DoctorID'] ?>" data-patient-name="<?= tdc_e((string)$a['PatientName']) ?>" data-phone="<?= tdc_e((string)($a['PatientPhone']??'')) ?>" data-patient-type="<?= tdc_e((string)($a['PatientType']??'')) ?>">Revisit</button></div></td></tr>
    <?php endforeach; endif; ?></tbody></table></div>

    <div class="tdc-modal" id="appointment-revisit-modal" role="dialog" aria-modal="true" aria-labelledby="revisit-modal-title" hidden><div class="tdc-modal-card appointment-modal-card"><header class="tdc-modal-header"><div><h2 id="revisit-modal-title">Revisit Patient</h2><p>Create a new consultation visit for this existing patient.</p></div><button type="button" class="tdc-modal-close" data-modal-close aria-label="Close">×</button></header><form method="post" class="tdc-modal-body" id="revisit-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="create_revisit"><input type="hidden" name="PatientID" id="revisit-patient-id"><section class="appointment-summary-card"><h3>Patient information</h3><div><span>Patient ID</span><strong id="revisit-patient-id-display">—</strong><span>Patient Name</span><strong id="revisit-patient-name">—</strong><span>Phone</span><strong id="revisit-patient-phone">—</strong><span>Patient Type</span><strong id="revisit-patient-type">—</strong></div></section><section class="appointment-modal-grid"><div><h3>Consultation details</h3><label>Doctor *<select name="DoctorID" id="revisit-doctor" required><option value="">Select Doctor</option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['DoctorID'] ?>" data-fee="<?= tdc_e((string)$d['ConsultationFee']) ?>" data-specialty="<?= tdc_e((string)$d['Specialty']) ?>" data-days="<?= tdc_e((string)$d['WorkingDays']) ?>" data-start="<?= tdc_e(substr((string)$d['WorkStartTime'],0,5)) ?>" data-end="<?= tdc_e(substr((string)$d['WorkEndTime'],0,5)) ?>"><?= tdc_e($d['DoctorName']) ?></option><?php endforeach; ?></select></label><div class="doctor-meta" id="revisit-doctor-meta">Select a doctor to load the authoritative fee and availability.</div><label>Visit date / time *<input type="datetime-local" name="VisitDate" id="revisit-date" min="<?= tdc_e($visitDateMinimum) ?>" required></label><label>Remark / Chief Complaint<textarea name="ChiefComplaint" id="revisit-remark" placeholder="Optional reason for revisit"></textarea></label><label class="free-consultation-toggle"><input type="checkbox" name="FreeConsultation" value="1" id="revisit-free"> <span><strong>Free Consultation</strong><small>Waive the consultation fee for this visit.</small></span></label></div><aside class="appointment-finance-card"><h3>Financial summary</h3><div><span>Consultation Fee</span><strong id="revisit-fee">0.00</strong></div><div><span>Discount / Waiver</span><strong id="revisit-discount">0.00</strong></div><div><span>Tax</span><strong>0.00</strong></div><div class="finance-final"><span>Final Amount</span><strong id="revisit-final">0.00</strong></div><label>Amount Paid<input type="number" name="AmountPaid" id="revisit-paid" min="0" step="0.01" value="0"></label><label>Payment Method<select name="PaymentMethod" id="revisit-method"><option value="">Not applicable</option><?php foreach($paymentMethods as $m): ?><option><?= tdc_e($m['MethodName']) ?></option><?php endforeach; ?></select></label><div class="finance-due"><span>Due</span><strong id="revisit-due">0.00</strong></div></aside></section><footer class="tdc-modal-footer"><button type="button" class="btn-secondary" data-modal-close>Cancel</button><button type="submit" class="btn-success">Create Revisit</button></footer></form></div></div>

    <div class="tdc-modal" id="appointment-edit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title" hidden><div class="tdc-modal-card appointment-modal-card"><header class="tdc-modal-header"><div><h2 id="edit-modal-title">Edit Appointment</h2><p>Update the eligible upcoming visit without changing patient identity.</p></div><button type="button" class="tdc-modal-close" data-modal-close aria-label="Close">×</button></header><form method="post" class="tdc-modal-body"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="edit_appointment"><input type="hidden" name="PatientID" id="edit-patient-id"><input type="hidden" name="VisitID" id="edit-visit-id"><div class="appointment-summary-card"><h3>Appointment</h3><p><strong id="edit-patient-name">—</strong> · <span id="edit-reference">—</span></p></div><label>Doctor *<select name="DoctorID" id="edit-doctor" required><?php foreach($doctors as $d): ?><option value="<?= (int)$d['DoctorID'] ?>"><?= tdc_e($d['DoctorName']) ?></option><?php endforeach; ?></select></label><label>Visit date / time *<input type="datetime-local" name="VisitDate" id="edit-date" required></label><label>Remark / Chief Complaint<textarea name="ChiefComplaint" id="edit-remark"></textarea></label><footer class="tdc-modal-footer"><button type="button" class="btn-secondary" data-modal-close>Cancel</button><button type="submit" class="btn-primary">Save Changes</button></footer></form></div></div>

    <div class="tdc-modal" id="appointment-import-modal" role="dialog" aria-modal="true" aria-labelledby="import-modal-title" hidden><div class="tdc-modal-card appointment-modal-card"><header class="tdc-modal-header"><div><h2 id="import-modal-title">Import Appointment Updates</h2><p>Upload CSV or XLSX files to update eligible visits by Visit ID.</p></div><button type="button" class="tdc-modal-close" data-modal-close aria-label="Close">×</button></header><form method="post" enctype="multipart/form-data" class="tdc-modal-body"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="import"><label class="appointment-dropzone" for="appointment_file"><strong>Upload CSV or XLSX</strong><span>Choose a file to validate and preview</span><em id="appointment-file-name">Accepted: .csv, .xlsx</em><input id="appointment_file" type="file" name="appointment_file" accept=".csv,.xlsx" required></label><div class="appointment-requirements"><strong>Required:</strong> Visit ID, Doctor ID, Visit Date<br><strong>Optional:</strong> Patient ID, Visit Reference, Chief Complaint</div><footer class="tdc-modal-footer"><button type="button" class="btn-secondary" data-modal-close>Cancel</button><button type="submit" class="btn-primary" id="appointment-import-submit" disabled>Upload &amp; Preview</button></footer></form></div></div>

    <script>document.addEventListener('DOMContentLoaded',function(){const root=document,modals={revisit:root.getElementById('appointment-revisit-modal'),edit:root.getElementById('appointment-edit-modal'),import:root.getElementById('appointment-import-modal')};let lastTrigger=null;function open(m,t){lastTrigger=t;m.hidden=false;document.body.classList.add('modal-open');const f=m.querySelector('input,select,button');if(f)f.focus();}function close(m){m.hidden=true;if(!Object.values(modals).some(x=>x&&!x.hidden))document.body.classList.remove('modal-open');if(lastTrigger)lastTrigger.focus();}root.querySelectorAll('[data-modal-close]').forEach(b=>b.addEventListener('click',()=>close(b.closest('.tdc-modal'))));root.querySelectorAll('[data-modal-open]').forEach(b=>b.addEventListener('click',()=>open(root.getElementById(b.dataset.modalOpen),b)));root.addEventListener('keydown',e=>{if(e.key==='Escape')Object.values(modals).forEach(m=>{if(m&&!m.hidden)close(m);});});const search=root.querySelector('.appointment-toolbar-search input[name="q"]'),clearSearch=root.getElementById('appointment-clear-search');search?.addEventListener('input',()=>{if(clearSearch)clearSearch.disabled=!search.value;});clearSearch?.addEventListener('click',()=>{search.value='';clearSearch.disabled=true;search.focus();});root.querySelectorAll('[data-appointment-action]').forEach(b=>b.addEventListener('click',function(){const d=this.dataset;if(d.appointmentAction==='revisit'){const patientField=root.querySelector('#revisit-form input[name="PatientID"]'),revisitForm=root.getElementById('revisit-form'),patientId=String(d.patientId||'');revisitForm.dataset.patientId=patientId;patientField.value=patientId;patientField.defaultValue=patientId;root.getElementById('revisit-patient-id-display').textContent=patientId||'—';root.getElementById('revisit-patient-name').textContent=d.patientName||'—';root.getElementById('revisit-patient-phone').textContent=d.phone||'—';root.getElementById('revisit-patient-type').textContent=d.patientType||'—';root.getElementById('revisit-doctor').value=d.doctorId||'';root.getElementById('revisit-date').value='';root.getElementById('revisit-remark').value='';root.getElementById('revisit-free').checked=false;root.getElementById('revisit-paid').value='0';syncFinance();patientField.value=patientId;patientField.defaultValue=patientId;open(modals.revisit,this);}else{const editForm=root.querySelector('#appointment-edit-modal form');editForm.dataset.patientId=String(d.patientId||'');editForm.dataset.visitId=String(d.visitId||'');root.getElementById('edit-patient-id').value=d.patientId;root.getElementById('edit-visit-id').value=d.visitId;root.getElementById('edit-patient-name').textContent=d.patientName||'—';root.getElementById('edit-reference').textContent=d.reference||'—';root.getElementById('edit-doctor').value=d.doctorId||'';root.getElementById('edit-date').value=d.date||'';root.getElementById('edit-remark').value=d.remark||'';open(modals.edit,this);}}));const doctor=root.getElementById('revisit-doctor'),free=root.getElementById('revisit-free'),paid=root.getElementById('revisit-paid'),method=root.getElementById('revisit-method');function syncFinance(){const o=doctor.options[doctor.selectedIndex],fee=o&&o.dataset.fee?Number(o.dataset.fee):0,isFree=free.checked,final=isFree?0:fee;root.getElementById('revisit-fee').textContent=fee.toFixed(2);root.getElementById('revisit-discount').textContent=(isFree?fee:0).toFixed(2);root.getElementById('revisit-final').textContent=final.toFixed(2);paid.max=final.toFixed(2);if(isFree){paid.value='0';paid.disabled=true;method.value='';method.disabled=true;method.required=false;}else{paid.disabled=false;method.disabled=(Number(paid.value)||0)<=0;method.required=!method.disabled;}root.getElementById('revisit-due').textContent=Math.max(0,final-(Number(paid.value)||0)).toFixed(2);root.getElementById('revisit-doctor-meta').textContent=o&&o.value?((o.dataset.specialty||'')+' · availability '+(o.dataset.start||'')+'–'+(o.dataset.end||'')):'Select a doctor to load the authoritative fee and availability.';}doctor.addEventListener('change',syncFinance);free.addEventListener('change',syncFinance);paid.addEventListener('input',syncFinance);const file=root.getElementById('appointment_file'),fileName=root.getElementById('appointment-file-name'),importSubmit=root.getElementById('appointment-import-submit');if(file)file.addEventListener('change',()=>{const has=!!file.files.length;fileName.textContent=has?file.files[0].name:'Accepted: .csv, .xlsx';if(importSubmit)importSubmit.disabled=!has;});});</script>

    <script>document.getElementById('revisit-form')?.addEventListener('submit',function(){const id=this.dataset.patientId||document.getElementById('revisit-patient-id-display')?.textContent.trim();const field=this.querySelector('input[name="PatientID"]');if(field&&id&&id!=='—'){field.value=id;field.setAttribute('value',id);}});</script>
    <script>document.querySelector('#appointment-edit-modal form')?.addEventListener('submit',function(){const patient=this.querySelector('input[name="PatientID"]'),visit=this.querySelector('input[name="VisitID"]');if(patient&&this.dataset.patientId){patient.value=this.dataset.patientId;patient.setAttribute('value',this.dataset.patientId);}if(visit&&this.dataset.visitId){visit.value=this.dataset.visitId;visit.setAttribute('value',this.dataset.visitId);}});</script>
    <script>document.getElementById('revisit-form')?.addEventListener('submit',function(){const button=this.querySelector('button[type="submit"]');if(button){button.disabled=true;button.dataset.originalLabel=button.textContent;button.textContent='Creating...';}});</script>

    <?php // ============================================================
          // CONSULTATION BOOKING
          // ============================================================ ?>
    <?php elseif ($section === 'consultations'): ?>

    <div class="welcome-title">Appointment Booking</div>
    <div class="welcome-sub">Schedule a patient, apply the doctor's fee, and send the appointment to the Doctor Workspace queue.</div>

    <?php if($canBookConsultation):?><form method="POST" action="reception.php?section=consultations" class="workflow-form" id="consultationForm">
        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
        <input type="hidden" name="form_action" value="save">
        <section class="form-section"><div class="form-section-heading"><span><strong>Patient</strong><span>Select an existing patient record.</span></span></div><div class="form-group"><label for="cv_patient_search">Patient ID, name or phone</label><div class="combo" data-combo><input type="hidden" id="cv_patient" name="PatientID" value="<?= tdc_e($oldVisit['PatientID']) ?>" required><input type="hidden" id="cv_patient_label"><input class="combo-input" id="cv_patient_search" autocomplete="off" placeholder="Search patient by name or phone" required><ul class="combo-list" id="cv_patient_list" hidden></ul></div></div><a class="btn-success btn-sm" href="reception.php?section=patients">+ Register New Patient</a></section>
        <section class="form-section"><div class="form-section-heading"><span><strong>Appointment details</strong><span>Choose a doctor and appointment time. Clinical consultation happens in the Doctor Workspace.</span></span></div><div class="form-row"><div class="form-group"><label for="cv_doctor">1. Choose Doctor</label><select id="cv_doctor" name="DoctorID" required><option value="">Choose a doctor</option><?php foreach ($doctors as $d): if(empty($d['UserID'])) continue; ?><option value="<?= (int)$d['DoctorID'] ?>" data-fee="<?= tdc_e((string)$d['ConsultationFee']) ?>" data-days="<?= tdc_e((string)$d['WorkingDays']) ?>" data-start="<?= tdc_e(substr((string)$d['WorkStartTime'], 0, 5)) ?>" data-end="<?= tdc_e(substr((string)$d['WorkEndTime'], 0, 5)) ?>" <?= $oldVisit['DoctorID']===(string)$d['DoctorID']?'selected':'' ?>><?= tdc_e($d['DoctorName']) ?> — <?= number_format((float)$d['ConsultationFee'],2) ?></option><?php endforeach; ?></select><div class="doctor-availability" id="cv_availability"><strong>Doctor availability</strong><span>Choose a doctor to see the exact available days and hours.</span></div></div><div class="form-group"><label for="cv_date">2. Appointment Date and Time</label><input id="cv_date" type="datetime-local" name="VisitDate" value="<?= tdc_e($oldVisit['VisitDate']) ?>" min="<?= tdc_e($visitDateMinimum) ?>" required><div class="appointment-preview" id="cv_selected_slot">Choose a date to see the selected weekday.</div><div class="rx-meta">Choose only a day and time shown in the doctor availability panel.</div></div></div><div class="form-group"><label for="cv_complaint">3. Reason for visit</label><textarea id="cv_complaint" name="ChiefComplaint" placeholder="Reason for visit"><?= tdc_e($oldVisit['ChiefComplaint']) ?></textarea></div></section>
        <section class="form-section"><div class="form-section-heading"><span><strong>Payment Summary</strong><span>Payment state is calculated automatically.</span></span></div><div class="form-row"><div class="form-group"><label for="cv_fee">Consultation Fee</label><input id="cv_fee" type="text" value="Select a doctor" readonly></div><div class="form-group"><label for="cv_discount_type">Discount type</label><select id="cv_discount_type" name="DiscountType"><option value="None">No discount</option><option value="Fixed">Fixed amount</option><option value="Percentage">Percentage</option></select></div><div class="form-group"><label for="cv_discount_value">Discount value</label><input id="cv_discount_value" name="DiscountValue" type="number" min="0" step="0.01" value="0" placeholder="Optional discount"></div><div class="form-group"><label for="cv_tax_rate">Tax rate (%)</label><input id="cv_tax_rate" name="TaxRate" type="number" min="0" max="100" step="0.01" value="0" placeholder="Optional tax"></div><div class="form-group"><label for="cv_discount_reason">Discount reason</label><input id="cv_discount_reason" name="DiscountReason" placeholder="Required when discount is used"></div><div class="form-group"><label for="cv_paid">Amount Paid</label><input id="cv_paid" type="number" min="0" step="0.01" name="AmountPaid" value="<?= tdc_e($oldVisit['AmountPaid']) ?>" required></div></div><div class="form-row"><div class="form-group"><label>Balance</label><div class="due-display" id="cv_balance">0.00</div></div><div class="form-group"><label>Payment Status</label><div><span class="status-badge danger" id="cv_status">Unpaid</span></div></div><div class="form-group"><label for="cv_method">Payment Method</label><select id="cv_method" name="PaymentMethod"><option value="">Not applicable until payment is entered</option><?php foreach ($paymentMethods as $method): ?><option value="<?=tdc_e($method['MethodName'])?>" <?= $oldVisit['PaymentMethod']===$method['MethodName']?'selected':'' ?>><?=tdc_e($method['MethodName'])?></option><?php endforeach; ?></select></div></div></section>
        <div class="modal-actions workflow-actions"><a href="reception.php" class="btn btn-secondary">Cancel</a><button class="btn btn-success" type="submit">Create Appointment</button></div>
    </form><?php endif;?>

    <div class="subsection-title"><span>Appointment queue</span></div>
    <p class="section-hint">Every booked appointment with its payment state and current position in the Doctor Workspace queue.</p>

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
        <button type="button" class="btn-primary btn btn-sm" onclick="window.print()"><?= tdc_icon('printer', 14) ?><span>Print / Save PDF</span></button>
    </form>

    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Appointment</th><th>Patient</th><th>Gender</th><th>Age</th><th>Phone</th><th>Visit date</th><th>Queue</th><th>Payment</th><th>Actions</th></tr></thead><tbody>
    <?php if (!$consultationVisits): ?>
        <?= tdc_empty_state('calendar', 'No consultations match these filters', 'Widen the date range or clear the filters to see more bookings.', '', 9) ?>
    <?php else: foreach ($consultationVisits as $v): ?>
        <?php $ca = $consultationAdjustments[(string) $v['VisitReference']] ?? null; ?>
        <tr data-unified-gross="<?= tdc_e((string) ($ca['GrossAmount'] ?? $v['ConsultationFee'])) ?>" data-unified-paid="<?= tdc_e((string) $v['AmountPaid']) ?>" data-unified-discount-type="<?= tdc_e((string) ($ca['DiscountType'] ?? 'None')) ?>" data-unified-discount-value="<?= tdc_e((string) ($ca['DiscountValue'] ?? 0)) ?>" data-unified-discount-reason="<?= tdc_e((string) ($ca['DiscountReason'] ?? '')) ?>" data-unified-tax-rate="<?= tdc_e((string) ($ca['TaxRate'] ?? 0)) ?>">
            <td><strong><?= tdc_e((string) $v['VisitReference']) ?></strong><br><span class="cell-sub"><?= tdc_e((string) $v['DoctorName']) ?></span></td>
            <td><?= tdc_e((string) $v['PatientName']) ?></td>
            <td><?= $v['Gender'] !== null && $v['Gender'] !== '' ? tdc_e((string) $v['Gender']) : '&mdash;' ?></td>
            <td><?= $v['Age'] !== null && $v['Age'] !== '' ? (int) $v['Age'] : '&mdash;' ?></td>
            <td><?= $v['PatientPhone'] !== null && $v['PatientPhone'] !== '' ? tdc_e((string) $v['PatientPhone']) : '&mdash;' ?></td>
            <td><?= tdc_e(date('d M Y', strtotime((string) $v['VisitDate']))) ?></td>
            <td><?= tdc_badge((string) $v['QueueStatus']) ?></td>
            <td><?= tdc_badge(!empty($v['IsFreeConsultation']) ? 'Waived' : (string) $v['PaymentStatus']) ?></td>
            <td><?php if (tdc_can('doctor.workspace')): ?><a class="btn-sm" href="doctors.php?visit=<?= (int) $v['VisitID'] ?>" title="Open consultation workspace">Open</a><?php endif; ?><a class="btn-primary btn-sm" href="../print_consultation.php?ref=<?= urlencode((string) $v['VisitReference']) ?>" target="_blank" rel="noopener">Receipt</a><?php if ((float) $v['DueBalance'] > 0 && $v['QueueStatus'] === 'Pending Payment'): ?><form method="post" action="reception.php?section=consultations" class="balance-form"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="collect"><input type="hidden" name="VisitID" value="<?= (int) $v['VisitID'] ?>"><input aria-label="Payment amount" type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string) $v['DueBalance']) ?>" step="0.01" value="<?= tdc_e((string) $v['DueBalance']) ?>" required><select aria-label="Payment method" name="PaymentMethod"><?php foreach ($paymentMethods as $method): ?><option><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select><button class="btn-success btn-sm" type="submit">Collect</button></form><?php elseif ((float) $v['DueBalance'] > 0): ?><span class="cell-sub">Balance <?= number_format((float) $v['DueBalance'], 2) ?></span><?php else: ?><span class="cell-sub">Settled</span><?php endif; ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
    <?= tdc_pager($consultationPage, $consultationPerPage, $consultationTotal, array_filter(['section' => 'consultations', 'q' => $consultationSearch, 'status' => $consultationStatus, 'from_date' => $consultationFrom, 'to_date' => $consultationTo, 'per_page' => $consultationPerPage], static fn($value): bool => $value !== '' && $value !== null)) ?>

    <script>document.addEventListener('DOMContentLoaded',function(){const patients=<?= json_encode($patientOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;const patientId=document.getElementById('cv_patient'),patientLabel=document.getElementById('cv_patient_label'),patientSearch=document.getElementById('cv_patient_search');const selected=patients.find(p=>String(p.id)===patientId.value);if(selected){patientLabel.value=selected.label;patientSearch.value=selected.label;}initPatientCombobox(patientId,patientLabel,patientSearch,document.getElementById('cv_patient_list'),patients);const doctor=document.getElementById('cv_doctor'),dateField=document.getElementById('cv_date'),availability=document.getElementById('cv_availability'),feeField=document.getElementById('cv_fee'),paidField=document.getElementById('cv_paid'),balance=document.getElementById('cv_balance'),status=document.getElementById('cv_status'),method=document.getElementById('cv_method'),form=document.getElementById('consultationForm');const labels={1:'Monday',2:'Tuesday',3:'Wednesday',4:'Thursday',5:'Friday',6:'Saturday',7:'Sunday'};function checkSchedule(){if(!doctor.value||!dateField.value){dateField.setCustomValidity('');return;}const chosen=new Date(dateField.value),day=String(chosen.getDay()||7),time=dateField.value.slice(11,16),days=(dateField.dataset.days||'').split(',').filter(Boolean),valid=days.includes(day)&&time>=dateField.dataset.start&&time<=dateField.dataset.end;dateField.setCustomValidity(valid?'':'Choose a date and time within this doctor\'s availability.');}function setNextAvailable(){const days=(dateField.dataset.days||'').split(',').filter(Boolean);if(!days.length)return;const current=new Date(),candidate=new Date(current.getFullYear(),current.getMonth(),current.getDate(),9,0);for(let offset=0;offset<8;offset++){candidate.setDate(current.getDate()+offset);const day=String(candidate.getDay()||7);if(days.includes(day)){const start=dateField.dataset.start.split(':');candidate.setHours(Number(start[0]),Number(start[1]),0,0);if(candidate>current){const pad=value=>String(value).padStart(2,'0');dateField.value=candidate.getFullYear()+'-'+pad(candidate.getMonth()+1)+'-'+pad(candidate.getDate())+'T'+pad(candidate.getHours())+':'+pad(candidate.getMinutes());return;}}}}function sync(){const option=doctor.options[doctor.selectedIndex],fee=option&&option.dataset.fee?Number(option.dataset.fee):0,paid=Math.max(0,Number(paidField.value)||0),due=Math.max(0,fee-paid),days=option&&option.dataset.days?option.dataset.days.split(',').filter(Boolean):[];feeField.value=option&&option.dataset.fee?fee.toFixed(2):'Select a doctor';paidField.max=fee.toFixed(2);balance.textContent=due.toFixed(2);const state=fee<=0||paid>=fee?'Paid':(paid>0?'Partial':'Unpaid');status.textContent=state;status.className='status-badge'+(state==='Unpaid'?' danger':(state==='Partial'?' warn':''));method.disabled=paid<=0;method.required=paid>0;if(paid<=0)method.value='';if(option&&option.value){availability.innerHTML='<strong>Available days: </strong>'+days.map(day=>labels[day]).join(', ')+'<span>Hours: '+option.dataset.start+'–'+option.dataset.end+'</span>';dateField.dataset.days=days.join(',');dateField.dataset.start=option.dataset.start;dateField.dataset.end=option.dataset.end;const selectedTime=dateField.value.slice(11,16);if(!dateField.value||!days.includes(String(new Date(dateField.value).getDay()||7))||selectedTime<dateField.dataset.start||selectedTime>dateField.dataset.end)setNextAvailable();}else{availability.innerHTML='<strong>Doctor availability</strong><span>Choose a doctor to see the exact available days and hours.</span>';delete dateField.dataset.days;}checkSchedule();}doctor.addEventListener('change',sync);dateField.addEventListener('input',checkSchedule);dateField.addEventListener('change',checkSchedule);paidField.addEventListener('input',sync);form.addEventListener('submit',checkSchedule);sync();});</script>

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
            <?php if ($patientSearch !== '' || $patientTypeFilter !== '' || $patientDoctorFilter > 0 || $patientDateFilter !== ''): ?><a href="reception.php?section=patients" class="btn btn-secondary clear-filters">Clear</a><?php endif; ?>
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
                            <?php if($canPatientEdit):?><button type="button" class="btn-secondary btn-sm edit-patient-btn"
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
                            <?php if($canBookConsultation):?><a class="btn-primary btn-sm" href="patients.php?book=<?= (int) $p['PatientID'] ?>">Book</a><?php endif;?>
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
                            <div class="form-group"><label for="pf_PatientPhone">Phone <span class="required-mark">*</span></label>
                                <input type="text" id="pf_PatientPhone" name="PatientPhone" placeholder="e.g. 615019253" required></div>
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
                            <div class="form-group patient-age-group"><label>Age</label>
                                <input type="hidden" id="pf_Age" name="Age">
                                <div class="patient-age-segments" id="pf_AgeSegments" aria-label="Patient age">
                                    <label><input type="text" id="pf_AgeYears" inputmode="numeric" autocomplete="off"><span>Years</span></label>
                                    <label><input type="text" id="pf_AgeMonths" inputmode="numeric" autocomplete="off"><span>Months</span></label>
                                    <label><input type="text" id="pf_AgeDays" inputmode="numeric" autocomplete="off"><span>Days</span></label>
                                </div>
                                <div class="patient-age-hint" id="pf_AgeHint">Select a date of birth to calculate age.</div>
                            </div>
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

                    <section class="form-section appointment-intake-section">
                        <button type="button" class="btn btn-secondary" id="toggleAppointmentFields">+ Add appointment now</button>
                        <input type="hidden" name="BookAppointment" id="pf_BookAppointment" value="">
                        <input type="hidden" name="AppointmentOnly" id="pf_AppointmentOnly" value="">
                        <div id="appointmentValidationMessage" class="form-error" hidden role="alert"></div>
                        <div id="appointmentIntakeFields" hidden style="margin-top:16px">
                            <div class="form-section-heading"><span class="form-section-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></span><span><strong>Appointment details</strong><span>Optional: create the appointment together with this patient.</span></span></div>
                            <div class="form-row">
                                <div class="form-group"><label for="pf_AppointmentDoctorID">Doctor *</label><select id="pf_AppointmentDoctorID" name="AppointmentDoctorID"><option value="">Choose a linked doctor</option><?php foreach ($doctors as $d): if (empty($d['UserID'])) continue; ?><option value="<?= (int) $d['DoctorID'] ?>" data-fee="<?= tdc_e((string) $d['ConsultationFee']) ?>" data-days="<?= tdc_e((string) $d['WorkingDays']) ?>" data-start="<?= tdc_e(substr((string) $d['WorkStartTime'],0,5)) ?>" data-end="<?= tdc_e(substr((string) $d['WorkEndTime'],0,5)) ?>" data-booked="<?= tdc_e(json_encode($appointmentBookingsByDoctor[(int) $d['DoctorID']] ?? [])) ?>"><?= tdc_e($d['DoctorName']) ?> — <?= number_format((float) $d['ConsultationFee'],2) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="pf_AppointmentDate">Date and time *</label><input type="datetime-local" id="pf_AppointmentDate" name="AppointmentDate" list="appointmentDateOptions" step="1800"><datalist id="appointmentDateOptions"></datalist><div id="appointmentAvailabilityHint" class="field-hint" role="status">Choose a doctor to see available days and hours.</div><div id="appointmentAvailabilityCalendar" class="appointment-availability-calendar" role="group" aria-label="Available appointment dates"></div></div>
                            </div>
                            <div class="form-row">
                                <div class="form-group"><label for="pf_AppointmentAmountPaid">Amount paid</label><input type="number" id="pf_AppointmentAmountPaid" name="AppointmentAmountPaid" min="0" step="0.01" value="0"></div><div class="form-group"><label for="pf_AppointmentDiscountType">Discount type</label><select id="pf_AppointmentDiscountType" name="AppointmentDiscountType"><option value="None">No discount</option><option value="Fixed">Fixed amount</option><option value="Percentage">Percentage</option></select></div><div class="form-group"><label for="pf_AppointmentDiscountValue">Discount value</label><input type="number" id="pf_AppointmentDiscountValue" name="AppointmentDiscountValue" min="0" step="0.01" value="0" placeholder="Optional discount"></div><div class="form-group"><label for="pf_AppointmentTaxRate">Tax rate (%)</label><input type="number" id="pf_AppointmentTaxRate" name="AppointmentTaxRate" min="0" max="100" step="0.01" value="0" placeholder="Optional tax"></div><div class="form-group"><label for="pf_AppointmentDiscountReason">Discount reason</label><input id="pf_AppointmentDiscountReason" name="AppointmentDiscountReason" placeholder="Required with discount"></div>
                                <div class="form-group"><label for="pf_AppointmentPaymentMethod">Payment method</label><select id="pf_AppointmentPaymentMethod" name="AppointmentPaymentMethod"><option value="">Select when receiving payment</option><?php foreach ($paymentMethods as $method): ?><option value="<?= tdc_e($method['MethodName']) ?>"><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select></div>
                            </div>
                            <div class="form-row appointment-summary" aria-live="polite"><div><span>Consultation fee</span><strong id="appointmentFeeDisplay">0.00</strong></div><div><span>Final amount</span><strong id="appointmentFinalDisplay">0.00</strong></div><div><span>Due</span><strong id="appointmentDueDisplay">0.00</strong></div><div><span>Status</span><strong id="appointmentStatusDisplay">Unpaid</strong></div></div>
                            <label class="free-consultation-toggle"><input type="checkbox" name="AppointmentFreeConsultation" value="1" id="pf_AppointmentFreeConsultation" <?= !empty($oldPatient['AppointmentFreeConsultation']) ? 'checked' : '' ?>> <span><strong>Free Consultation</strong><small>Waive the consultation fee for this visit.</small></span></label>
                            <div class="form-group"><label for="pf_AppointmentReason">Reason for visit (Optional)</label><textarea id="pf_AppointmentReason" name="AppointmentReason" placeholder="Reason for visit"></textarea></div>
                        </div>
                    </section>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="patientModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span id="patientSaveLabel">Save Patient</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php // ============================================================
          // LABORATORY BILLS
          // ============================================================ ?>
    <?php elseif ($section === 'services'): ?>
    <div class="welcome-title">Service Billing</div>
    <div class="welcome-sub">Collect payment for doctor-assigned services.</div>
    <form method="GET" action="reception.php" class="table-command-bar billing-patient-search tdc-search"><?= tdc_icon('search', 15) ?><input type="hidden" name="section" value="services"><input type="search" name="patient_q" value="<?= tdc_e($billingPatientSearch) ?>" placeholder="Search service, patient or phone..." aria-label="Search service bills by patient name or phone"><?php if ($billingPatientSearch !== ''): ?><button type="button" class="tdc-search__clear" data-search-clear aria-label="Clear search" title="Clear search">&times;</button><?php endif; ?><button class="btn-primary btn-sm" type="submit">Search</button></form>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Reference</th><th>Patient / Phone</th><th>Category / Service</th><th>Doctor</th><th>Gross / Final</th><th>Paid</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($serviceBills as $bill): $adj=$serviceAdjustments[(string)$bill['ServiceReference']]??null; $serviceGross=(float)($adj['GrossAmount']??$bill['ServiceAmount']); $serviceFinal=(float)($adj['FinalAmount']??$bill['ServiceAmount']); $serviceDiscountOn=$adj && (string)($adj['DiscountType']??'None')!=='None'; $serviceTaxOn=$adj && (float)($adj['TaxRate']??0)>0; ?>
<tr><td><?=tdc_e($bill['ServiceReference'])?></td><td><?=tdc_e($bill['PatientName'])?><br><small><?=tdc_e($bill['PatientPhone'])?></small></td><td><?=tdc_e($bill['CategoryName'].' / '.$bill['ServiceName'])?></td><td><?=tdc_e($bill['DoctorName']??'Unassigned')?></td><td><?=number_format($serviceGross,2)?><br><small>Final <?=number_format($serviceFinal,2)?></small></td><td><?=number_format((float)$bill['AmountPaid'],2)?></td><td><?=number_format((float)$bill['DueBalance'],2)?></td><td><?=tdc_e($bill['PaymentStatus'])?></td><td><div class="row-actions"><?php if((float)$bill['DueBalance']>0): ?><form method="post" class="inline-payment-form"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="collect"><input type="hidden" name="ServiceAssignmentID" value="<?=$bill['AssignmentID']?>"><input name="PaymentAmount" type="number" min="0.01" max="<?=tdc_e($bill['DueBalance'])?>" step="0.01" placeholder="Amount to pay" required><select name="PaymentMethod" required><?php foreach($paymentMethods as $pm):?><option><?=tdc_e($pm['MethodName'])?></option><?php endforeach;?></select><button class="btn-success btn-sm" type="submit">Record Payment</button></form><?php else: ?><span class="status-badge success">Paid</span><?php endif; ?><button type="button" class="btn-secondary btn-sm billing-adjustment-toggle" data-billing-adjustment-toggle="<?=tdc_e((string)$bill['ServiceReference'])?>" aria-expanded="false">Adjust Bill</button><a class="btn-primary btn-sm" href="../print_service_receipt.php?ref=<?=urlencode($bill['ServiceReference'])?>" target="_blank" rel="noopener">Receipt A4</a></div></td></tr>
<tr class="billing-adjustment-row" data-billing-adjustment-panel="<?=tdc_e((string)$bill['ServiceReference'])?>" hidden><td colspan="9"><section class="billing-adjustment-panel"><header><div><h3>Financial Adjustment</h3><p>Apply an optional discount or tax to this service bill.</p></div><strong>Current Final: <span data-current-final><?=number_format($serviceFinal,2)?></span></strong></header><form method="post" action="reception.php?section=services" class="adjustment-form" data-gross="<?=tdc_e((string)$serviceGross)?>" data-paid="<?=tdc_e((string)$bill['AmountPaid'])?>"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="adjust"><input type="hidden" name="ServiceAssignmentID" value="<?=tdc_e((string)$bill['AssignmentID'])?>"><div class="billing-adjustment-grid"><div class="adjustment-controls"><label class="adjustment-toggle"><input type="checkbox" class="discount-toggle" <?=$serviceDiscountOn?'checked':''?>> <span>Apply Discount</span></label><div class="adjustment-fields discount-fields" <?=$serviceDiscountOn?'':'hidden'?>><label>Discount Type<select name="DiscountType"><option value="None" <?=(!$adj||$adj['DiscountType']==='None')?'selected':''?>>No discount</option><option value="Fixed" <?=($adj&&$adj['DiscountType']==='Fixed')?'selected':''?>>Fixed amount</option><option value="Percentage" <?=($adj&&$adj['DiscountType']==='Percentage')?'selected':''?>>Percentage</option></select></label><label>Discount Value<div class="input-suffix"><input name="DiscountValue" type="number" min="0" step="0.01" value="<?=tdc_e((string)($adj['DiscountValue']??'0'))?>"><span data-discount-suffix>Amount</span></div></label><label>Discount Reason<input name="DiscountReason" value="<?=tdc_e((string)($adj['DiscountReason']??''))?>" placeholder="Reason for discount"></label></div><label class="adjustment-toggle"><input type="checkbox" class="tax-toggle" <?=$serviceTaxOn?'checked':''?>> <span>Apply Tax</span></label><div class="adjustment-fields tax-fields" <?=$serviceTaxOn?'':'hidden'?>><label>Tax Rate (%)<div class="input-suffix"><input name="TaxRate" type="number" min="0" max="100" step="0.01" value="<?=tdc_e((string)($adj['TaxRate']??'0'))?>"><span>%</span></div></label></div></div><aside class="adjustment-summary"><h4>Live Bill Summary</h4><div><span>Gross Amount</span><strong data-summary-gross>0.00</strong></div><div><span>Discount</span><strong data-summary-discount>-0.00</strong></div><div><span>Subtotal</span><strong data-summary-subtotal>0.00</strong></div><div><span>Tax</span><strong data-summary-tax>0.00</strong></div><div class="summary-final"><span>Final Amount</span><strong data-summary-final>0.00</strong></div><div><span>Paid</span><strong data-summary-paid>0.00</strong></div><div class="summary-due"><span>Balance Due</span><strong data-summary-due>0.00</strong></div></aside></div><div class="adjustment-actions"><button type="button" class="btn-secondary btn-sm billing-adjustment-cancel">Cancel</button><button type="submit" class="btn-primary btn-sm">Save Adjustment</button></div></form></section></td></tr>
<?php endforeach; ?><?php if(!$serviceBills): ?><tr><td colspan="9">No service bills have been assigned yet.</td></tr><?php endif; ?></tbody></table></div>
<?php elseif ($section === 'laboratory'): ?>

    <div class="welcome-title">Laboratory Payment Queue</div>
    <div class="welcome-sub">Collect payment for tests already ordered by doctors. Clinical order details are read-only.</div>
    <form method="GET" action="reception.php" class="table-command-bar billing-patient-search tdc-search"><?= tdc_icon('search', 15) ?><input type="hidden" name="section" value="laboratory"><input type="search" name="patient_q" value="<?= tdc_e($billingPatientSearch) ?>" placeholder="Search laboratory ID, patient or test..." aria-label="Search laboratory bills by patient name or phone"><?php if ($billingPatientSearch !== ''): ?><button type="button" class="tdc-search__clear" data-search-clear aria-label="Clear search" title="Clear search">&times;</button><?php endif; ?><button class="btn-primary btn-sm" type="submit">Search</button></form>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr><th>Bill ID</th><th>Patient</th><th>Visit / Doctor</th><th>Requested Test</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment</th><th>Cashier Action</th></tr></thead>
            <tbody>
                <?php if (empty($labBills)): ?>
                <tr class="empty-row"><td colspan="9">No laboratory bills found.</td></tr>
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
                        <div class="row-actions">
                            <?php if((float)$l['DueBalance'] > 0 && (string)$l['WorkflowStatus'] !== 'Cancelled'): ?>
                            <form method="POST" action="reception.php?section=laboratory" class="balance-form">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="collect">
                                <input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>">
                                <input aria-label="Amount received" type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string)$l['DueBalance']) ?>" step="0.01" value="<?= tdc_e((string)$l['DueBalance']) ?>" required>
                                <select aria-label="Payment method" name="PaymentMethod" required>
                                    <option value="">Select method</option>
                                    <?php foreach($paymentMethods as $method): ?><option><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn-success btn-sm" type="submit">Record Payment</button>
                            </form>
                            <?php else: ?><?= tdc_badge($l['WorkflowStatus']) ?><?php endif; ?>
                            <button type="button" class="btn-secondary btn-sm billing-adjustment-toggle" data-billing-adjustment-toggle="<?=tdc_e((string)$l['LaboratoryID'])?>" aria-expanded="false">Adjust Bill</button>
                            <a href="../print_laboratory.php?ref=<?= urlencode((string)$l['LaboratoryID']) ?>" class="btn-primary btn-sm" target="_blank" rel="noopener"><?= tdc_icon('printer',16) ?><span>Receipt A4</span></a>
                        </div>
                    </td>
                </tr>
                <?php $la=$labAdjustments[(string)$l['LaboratoryID']]??null; $labDiscountOn=$la && (string)($la['DiscountType']??'None')!=='None'; $labTaxOn=$la && (float)($la['TaxRate']??0)>0; ?>
                <tr class="billing-adjustment-row" data-billing-adjustment-panel="<?=tdc_e((string)$l['LaboratoryID'])?>" hidden><td colspan="9"><section class="billing-adjustment-panel"><header><div><h3>Financial Adjustment</h3><p>Apply an optional discount or tax to this bill.</p></div><strong>Current Final: <span data-current-final><?=number_format((float)($la['FinalAmount']??$l['TotalAmount']),2)?></span></strong></header><form method="post" action="reception.php?section=laboratory" class="adjustment-form" data-gross="<?=tdc_e((string)$l['TotalAmount'])?>" data-paid="<?=tdc_e((string)$l['AmountPaid'])?>"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="adjust"><input type="hidden" name="LaboratoryID" value="<?=tdc_e($l['LaboratoryID'])?>"><div class="billing-adjustment-grid"><div class="adjustment-controls"><label class="adjustment-toggle"><input type="checkbox" class="discount-toggle" <?=$labDiscountOn?'checked':''?>> <span>Apply Discount</span></label><div class="adjustment-fields discount-fields" <?=$labDiscountOn?'':'hidden'?>><label>Discount Type<select name="DiscountType"><option value="None" <?=(!$la||$la['DiscountType']==='None')?'selected':''?>>No discount</option><option value="Fixed" <?=($la&&$la['DiscountType']==='Fixed')?'selected':''?>>Fixed amount</option><option value="Percentage" <?=($la&&$la['DiscountType']==='Percentage')?'selected':''?>>Percentage</option></select></label><label>Discount Value<div class="input-suffix"><input name="DiscountValue" type="number" min="0" step="0.01" value="<?=tdc_e((string)($la['DiscountValue']??'0'))?>"><span data-discount-suffix>Amount</span></div></label><label>Discount Reason<input name="DiscountReason" value="<?=tdc_e((string)($la['DiscountReason']??''))?>" placeholder="Reason for discount"></label></div><label class="adjustment-toggle"><input type="checkbox" class="tax-toggle" <?=$labTaxOn?'checked':''?>> <span>Apply Tax</span></label><div class="adjustment-fields tax-fields" <?=$labTaxOn?'':'hidden'?>><label>Tax Rate (%)<div class="input-suffix"><input name="TaxRate" type="number" min="0" max="100" step="0.01" value="<?=tdc_e((string)($la['TaxRate']??'0'))?>"><span>%</span></div></label></div></div><aside class="adjustment-summary"><h4>Live Bill Summary</h4><div><span>Gross Amount</span><strong data-summary-gross>0.00</strong></div><div><span>Discount</span><strong data-summary-discount>-0.00</strong></div><div><span>Subtotal</span><strong data-summary-subtotal>0.00</strong></div><div><span>Tax</span><strong data-summary-tax>0.00</strong></div><div class="summary-final"><span>Final Amount</span><strong data-summary-final>0.00</strong></div><div><span>Paid</span><strong data-summary-paid>0.00</strong></div><div class="summary-due"><span>Balance Due</span><strong data-summary-due>0.00</strong></div></aside></div><div class="adjustment-actions"><button type="button" class="btn-secondary btn-sm billing-adjustment-cancel">Cancel</button><button type="submit" class="btn-primary btn-sm">Save Adjustment</button></div></form></section></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php // ============================================================
          // PHARMACY BILLS
          // ============================================================ ?>
    <?php elseif ($section === 'pharmacy'): ?>

    <div class="welcome-title">Pharmacy Bills</div>
    <div class="welcome-sub">Doctor prescriptions with authoritative medication totals. Reception manages bill adjustments, payments, balances, and receipts; Pharmacy only dispenses.</div>
    <form method="GET" action="reception.php" class="table-command-bar billing-patient-search tdc-search"><?= tdc_icon('search', 15) ?><input type="hidden" name="section" value="pharmacy"><input type="search" name="patient_q" value="<?= tdc_e($billingPatientSearch) ?>" placeholder="Search prescription, patient or phone..." aria-label="Search pharmacy bills by patient name or phone"><?php if ($billingPatientSearch !== ''): ?><button type="button" class="tdc-search__clear" data-search-clear aria-label="Clear search" title="Clear search">&times;</button><?php endif; ?><button class="btn-primary btn-sm" type="submit">Search</button></form>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr><th>Bill Ref</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Cashier Action</th></tr></thead>
            <tbody>
                <?php if (empty($pharmacyBills)): ?>
                <tr class="empty-row"><td colspan="9">No pharmacy bills found. Doctors create prescriptions and Pharmacy dispenses them.</td></tr>
                <?php else: foreach ($pharmacyBills as $b): $pa=$pharmacyAdjustments[(string)$b['BillRef']]??null; $displayTotal=(float)($pa['FinalAmount']??$b['TotalAmount']); $displayDue=max(0,round($displayTotal-(float)$b['AmountPaid'],2)); ?>
                <tr>
                    <td><?= tdc_e($b['BillRef']) ?></td>
                    <td><?= tdc_e($b['PatientName']) ?></td>
                    <td><?= tdc_e($doctorNameById[$b['DoctorID']] ?? 'Unknown') ?></td>
                    <td><?= (int) $b['LineCount'] ?></td>
                    <td><?= number_format($displayTotal, 2) ?></td>
                    <td><?= number_format((float) $b['AmountPaid'], 2) ?></td>
                    <td><span class="status-badge<?= $displayDue > 0 ? ' danger' : '' ?>"><?= number_format($displayDue, 2) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $b['PrescriptionDate']))) ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($canReceivePharmacyPayment && $displayDue > 0 && $displayTotal > 0): ?>
                            <form method="POST" action="reception.php?section=pharmacy" class="balance-form">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="collect">
                                <input type="hidden" name="BillRef" value="<?= tdc_e($b['BillRef']) ?>">
                                <input aria-label="Amount received" type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string) $displayDue) ?>" step="0.01" value="<?= tdc_e((string) $displayDue) ?>" required>
                                <select aria-label="Payment method" name="PaymentMethod" required>
                                    <option value="">Select method</option>
                                    <?php foreach ($paymentMethods as $method): ?><option><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn-success btn-sm" type="submit">Record Payment</button>
                            </form>
                            <?php elseif ($displayTotal <= 0): ?><span class="status-badge warn">Financial data unavailable</span><?php else: ?><span class="status-badge">Paid</span><?php endif; ?>
                            <button type="button" class="btn-secondary btn-sm billing-adjustment-toggle" data-billing-adjustment-toggle="<?=tdc_e((string)$b['BillRef'])?>" aria-expanded="false">Adjust Bill</button>
                            <a href="../print_prescription.php?ref=<?= urlencode($b['BillRef']) ?>" class="btn-primary btn-sm" target="_blank" rel="noopener"><?= tdc_icon('printer',16) ?><span>Receipt</span></a>
                        </div>
                    </td>
                </tr>
                <?php $pharmacyDiscountOn=$pa && (string)($pa['DiscountType']??'None')!=='None'; $pharmacyTaxOn=$pa && (float)($pa['TaxRate']??0)>0; ?>
                <tr class="billing-adjustment-row" data-billing-adjustment-panel="<?=tdc_e((string)$b['BillRef'])?>" hidden><td colspan="9"><section class="billing-adjustment-panel"><header><div><h3>Financial Adjustment</h3><p>Apply an optional discount or tax to this bill.</p></div><strong>Current Final: <span data-current-final><?=number_format((float)($pa['FinalAmount']??$b['TotalAmount']),2)?></span></strong></header><form method="post" action="reception.php?section=pharmacy" class="adjustment-form" data-gross="<?=tdc_e((string)$b['TotalAmount'])?>" data-paid="<?=tdc_e((string)$b['AmountPaid'])?>"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="adjust"><input type="hidden" name="BillRef" value="<?=tdc_e($b['BillRef'])?>"><div class="billing-adjustment-grid"><div class="adjustment-controls"><label class="adjustment-toggle"><input type="checkbox" class="discount-toggle" <?=$pharmacyDiscountOn?'checked':''?>> <span>Apply Discount</span></label><div class="adjustment-fields discount-fields" <?=$pharmacyDiscountOn?'':'hidden'?>><label>Discount Type<select name="DiscountType"><option value="None" <?=(!$pa||$pa['DiscountType']==='None')?'selected':''?>>No discount</option><option value="Fixed" <?=($pa&&$pa['DiscountType']==='Fixed')?'selected':''?>>Fixed amount</option><option value="Percentage" <?=($pa&&$pa['DiscountType']==='Percentage')?'selected':''?>>Percentage</option></select></label><label>Discount Value<div class="input-suffix"><input name="DiscountValue" type="number" min="0" step="0.01" value="<?=tdc_e((string)($pa['DiscountValue']??'0'))?>"><span data-discount-suffix>Amount</span></div></label><label>Discount Reason<input name="DiscountReason" value="<?=tdc_e((string)($pa['DiscountReason']??''))?>" placeholder="Reason for discount"></label></div><label class="adjustment-toggle"><input type="checkbox" class="tax-toggle" <?=$pharmacyTaxOn?'checked':''?>> <span>Apply Tax</span></label><div class="adjustment-fields tax-fields" <?=$pharmacyTaxOn?'':'hidden'?>><label>Tax Rate (%)<div class="input-suffix"><input name="TaxRate" type="number" min="0" max="100" step="0.01" value="<?=tdc_e((string)($pa['TaxRate']??'0'))?>"><span>%</span></div></label></div></div><aside class="adjustment-summary"><h4>Live Bill Summary</h4><div><span>Gross Amount</span><strong data-summary-gross>0.00</strong></div><div><span>Discount</span><strong data-summary-discount>-0.00</strong></div><div><span>Subtotal</span><strong data-summary-subtotal>0.00</strong></div><div><span>Tax</span><strong data-summary-tax>0.00</strong></div><div class="summary-final"><span>Final Amount</span><strong data-summary-final>0.00</strong></div><div><span>Paid</span><strong data-summary-paid>0.00</strong></div><div class="summary-due"><span>Balance Due</span><strong data-summary-due>0.00</strong></div></aside></div><div class="adjustment-actions"><button type="button" class="btn-secondary btn-sm billing-adjustment-cancel">Cancel</button><button type="submit" class="btn-primary btn-sm">Save Adjustment</button></div></form></section></td></tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
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
    const fAgeYears = document.getElementById('pf_AgeYears');
    const fAgeMonths = document.getElementById('pf_AgeMonths');
    const fAgeDays = document.getElementById('pf_AgeDays');
    const fAgeHint = document.getElementById('pf_AgeHint');
    const fDob = document.getElementById('pf_DateOfBirth');
    const fAddress = document.getElementById('pf_PatientAddress');
    const fType = document.getElementById('pf_PatientType');
    const fDoctor = document.getElementById('pf_AllocatedDoctor');
    const fRemark = document.getElementById('pf_Remark');
    const appointmentToggle = document.getElementById('toggleAppointmentFields');
    const appointmentFields = document.getElementById('appointmentIntakeFields');
    const fBookAppointment = document.getElementById('pf_BookAppointment');
    const fAppointmentOnly = document.getElementById('pf_AppointmentOnly');
    const fAppointmentDoctor = document.getElementById('pf_AppointmentDoctorID');
    const fAppointmentDate = document.getElementById('pf_AppointmentDate');
    const fAppointmentReason = document.getElementById('pf_AppointmentReason');
    const fAppointmentPaid = document.getElementById('pf_AppointmentAmountPaid');
    const fAppointmentMethod = document.getElementById('pf_AppointmentPaymentMethod');
    const fAppointmentFree = document.getElementById('pf_AppointmentFreeConsultation');
    const fAppointmentDiscountType = document.getElementById('pf_AppointmentDiscountType');
    const fAppointmentDiscountValue = document.getElementById('pf_AppointmentDiscountValue');
    const fAppointmentTaxRate = document.getElementById('pf_AppointmentTaxRate');
    const fAppointmentDiscountReason = document.getElementById('pf_AppointmentDiscountReason');
    const saveLabel = document.getElementById('patientSaveLabel');
    const appointmentValidationMessage = document.getElementById('appointmentValidationMessage');
    const appointmentAvailabilityHint = document.getElementById('appointmentAvailabilityHint');
    const appointmentAvailabilityCalendar = document.getElementById('appointmentAvailabilityCalendar');
    const appointmentDateOptions = document.getElementById('appointmentDateOptions');
    const appointmentFeeDisplay = document.getElementById('appointmentFeeDisplay');
    const appointmentFinalDisplay = document.getElementById('appointmentFinalDisplay');
    const appointmentDueDisplay = document.getElementById('appointmentDueDisplay');
    const appointmentStatusDisplay = document.getElementById('appointmentStatusDisplay');
    let appointmentOnly = false;

    const MAX_AGE = 150;
    function daysInMonth(year, month){ return new Date(year, month, 0).getDate(); }
    function parseDateValue(value){
        const parts = String(value || '').split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        return date.getFullYear() === parts[0] && date.getMonth() === parts[1] - 1 && date.getDate() === parts[2] ? date : null;
    }
    function addCalendarMonths(date, months){
        const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
        target.setDate(Math.min(date.getDate(), daysInMonth(target.getFullYear(), target.getMonth() + 1)));
        return target;
    }
    function agePartsFromDob(value, today = new Date()){
        const dob = parseDateValue(value); if (!dob) return null;
        const current = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (dob > current) return null;
        let years = current.getFullYear() - dob.getFullYear();
        const birthdayMonth = dob.getMonth(), birthdayDay = (dob.getMonth() === 1 && dob.getDate() === 29 && !((current.getFullYear() % 4 === 0 && current.getFullYear() % 100 !== 0) || current.getFullYear() % 400 === 0)) ? 28 : dob.getDate();
        if (current.getMonth() < birthdayMonth || (current.getMonth() === birthdayMonth && current.getDate() < birthdayDay)) years--;
        let anchor = new Date(dob.getFullYear() + years, dob.getMonth(), Math.min(dob.getDate(), daysInMonth(dob.getFullYear() + years, dob.getMonth() + 1)));
        let months = (current.getFullYear() - anchor.getFullYear()) * 12 + current.getMonth() - anchor.getMonth();
        if (addCalendarMonths(anchor, months) > current) months--;
        const monthAnchor = addCalendarMonths(anchor, Math.max(0, months));
        const days = Math.floor((current - monthAnchor) / 86400000);
        return {years, months: Math.max(0, months), days: Math.max(0, days)};
    }
    function dobFromManualAge(today = new Date()){
        if (fAgeYears.value.trim() === '' || fAgeMonths.value.trim() === '' || fAgeDays.value.trim() === '') return;
        const years = Number.parseInt(fAgeYears.value, 10), months = Number.parseInt(fAgeMonths.value, 10), days = Number.parseInt(fAgeDays.value, 10);
        if (!Number.isInteger(years) || !Number.isInteger(months) || !Number.isInteger(days) || years < 0 || years > MAX_AGE || months < 0 || months > 11 || days < 0 || days > 30) return;
        const dob = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        dob.setFullYear(dob.getFullYear() - years);
        const monthAnchor = addCalendarMonths(dob, -months);
        monthAnchor.setDate(monthAnchor.getDate() - days);
        fDob.value = [monthAnchor.getFullYear(), String(monthAnchor.getMonth() + 1).padStart(2, '0'), String(monthAnchor.getDate()).padStart(2, '0')].join('-');
        fDob.setCustomValidity('');
        if (fAgeHint) fAgeHint.textContent = 'Date of birth calculated from the entered age.';
    }
    function syncAgeDisplay(){
        const rawDob = fDob.value.trim(), parts = rawDob ? agePartsFromDob(rawDob) : null;
        if (rawDob && !parts) { fAge.value = ''; fAgeYears.value = ''; fAgeMonths.value = ''; fAgeDays.value = ''; fAgeYears.readOnly = true; fDob.setCustomValidity('Date of birth cannot be in the future.'); if (fAgeHint) fAgeHint.textContent = 'Date of birth cannot be in the future.'; return; }
        fDob.setCustomValidity('');
        if (parts) { fAge.value = String(parts.years); fAgeYears.value = parts.years; fAgeMonths.value = parts.months; fAgeDays.value = parts.days; [fAgeYears,fAgeMonths,fAgeDays].forEach(field => field.readOnly = true); if (fAgeHint) fAgeHint.textContent = 'Calculated from the date of birth.'; }
        else { [fAgeYears,fAgeMonths,fAgeDays].forEach(field => field.readOnly = false); if (fAgeHint) fAgeHint.textContent = fId.value && fAgeYears.value ? 'DOB not recorded; enter or retain the legacy age.' : 'Enter age or select a date of birth.'; }
    }
    function normalizeManualAge(field, max){ if (field.readOnly) return; if (field.value.trim() === '') { if (field === fAgeYears) fAge.value = ''; return; } const value = Number.parseInt(field.value, 10); field.value = String(Number.isFinite(value) ? Math.max(0, Math.min(max, value)) : ''); if (field === fAgeYears) fAge.value = field.value; }
    fAgeYears.addEventListener('input', function(){ normalizeManualAge(fAgeYears, MAX_AGE); dobFromManualAge(); });
    fAgeMonths.addEventListener('input', function(){ normalizeManualAge(fAgeMonths, 11); dobFromManualAge(); });
    fAgeDays.addEventListener('input', function(){ normalizeManualAge(fAgeDays, 30); dobFromManualAge(); });
    fAgeYears.addEventListener('change', dobFromManualAge);
    fAgeMonths.addEventListener('change', dobFromManualAge);
    fAgeDays.addEventListener('change', dobFromManualAge);
    fDob.addEventListener('input', syncAgeDisplay);
    fDob.addEventListener('change', syncAgeDisplay);

    function setAppointmentOpen(open){
        if (!appointmentFields || !appointmentToggle) return;
        appointmentFields.hidden = !open;
        fBookAppointment.value = open ? '1' : '';
        appointmentToggle.textContent = open ? '− Remove appointment' : '+ Add appointment now';
        [fAppointmentDoctor, fAppointmentDate].forEach(function(field){ if(field) field.required = open; });
        updateAppointmentSummary();
        if (open) updateAvailabilityHint();
        if (saveLabel) saveLabel.textContent = appointmentOnly ? 'Book Appointment' : (open ? 'Save Patient & Book Appointment' : 'Save Patient');
    }
    function updateAppointmentSummary(){
        const option = fAppointmentDoctor?.selectedOptions?.[0];
        const fee = option && option.dataset.fee !== undefined ? Number(option.dataset.fee) : 0;
        const free = Boolean(fAppointmentFree?.checked);
        if (free) {
            if (fAppointmentPaid) { fAppointmentPaid.value = '0'; fAppointmentPaid.max = '0'; fAppointmentPaid.disabled = true; }
            if (fAppointmentMethod) { fAppointmentMethod.value = ''; fAppointmentMethod.disabled = true; fAppointmentMethod.required = false; }
            [fAppointmentDiscountType,fAppointmentDiscountValue,fAppointmentTaxRate,fAppointmentDiscountReason].forEach(function(field){ if(field) field.disabled = true; });
            if (appointmentFeeDisplay) appointmentFeeDisplay.textContent = '0.00';
            if (appointmentFinalDisplay) appointmentFinalDisplay.textContent = '0.00';
            if (appointmentDueDisplay) appointmentDueDisplay.textContent = '0.00';
            if (appointmentStatusDisplay) appointmentStatusDisplay.textContent = 'Waived / Free';
            if (fAppointmentPaid) fAppointmentPaid.setCustomValidity('');
            return;
        }
        [fAppointmentDiscountType,fAppointmentDiscountValue,fAppointmentTaxRate,fAppointmentDiscountReason].forEach(function(field){ if(field) field.disabled = false; });
        if (fAppointmentPaid) fAppointmentPaid.disabled = false;
        if (fAppointmentMethod) fAppointmentMethod.disabled = false;
        const discountType = fAppointmentDiscountType?.value || 'None';
        const discountValue = Math.max(0, Number(fAppointmentDiscountValue?.value || 0));
        const taxRate = Math.min(100, Math.max(0, Number(fAppointmentTaxRate?.value || 0)));
        const discount = discountType === 'None' ? 0 : (discountType === 'Percentage' ? fee * discountValue / 100 : discountValue);
        const subtotal = Math.max(0, fee - Math.min(fee, discount));
        const final = Math.round((subtotal + subtotal * taxRate / 100) * 100) / 100;
        const paid = Number(fAppointmentPaid?.value || 0);
        const invalid = paid < 0 || !Number.isFinite(paid) || paid > final;
        if (appointmentFeeDisplay) appointmentFeeDisplay.textContent = fee.toFixed(2);
        if (appointmentFinalDisplay) appointmentFinalDisplay.textContent = final.toFixed(2);
        if (appointmentDueDisplay) appointmentDueDisplay.textContent = Math.max(0, final - Math.max(0, paid)).toFixed(2);
        if (appointmentStatusDisplay) appointmentStatusDisplay.textContent = invalid ? 'Invalid amount' : (final > 0 && paid >= final ? 'Paid' : (paid > 0 ? 'Partial' : 'Unpaid'));
        if (fAppointmentPaid) { fAppointmentPaid.max = final.toFixed(2); fAppointmentPaid.setCustomValidity(invalid ? 'Amount paid cannot exceed the final consultation amount (' + final.toFixed(2) + ').' : ''); }
        if (fAppointmentMethod) fAppointmentMethod.setCustomValidity(paid > 0 && !fAppointmentMethod.value ? 'Select a payment method for the amount received.' : '');
    }
    function updateAvailabilityHint(){
        if (!fAppointmentDate || !fAppointmentDoctor || !appointmentAvailabilityCalendar) return;
        const calendar = window.TdcAppointmentCalendar(fAppointmentDate, fAppointmentDoctor, appointmentAvailabilityCalendar, appointmentAvailabilityHint, <?= TDC_APPOINTMENT_MINUTES ?>);
        calendar.refresh();
    }
    function validateAppointmentDate(){ updateAvailabilityHint(); }
    function renderAppointmentAvailabilityCalendar(){ updateAvailabilityHint(); }
    fAppointmentDoctor?.addEventListener('change', updateAppointmentSummary);
    fDoctor?.addEventListener('change', function(){ fAppointmentDoctor.value=fDoctor.value; updateAppointmentSummary(); updateAvailabilityHint(); });
    fAppointmentDoctor?.addEventListener('change', updateAvailabilityHint);
    fAppointmentDate?.addEventListener('input', validateAppointmentDate);
    fAppointmentDate?.addEventListener('change', function(){ validateAppointmentDate(); renderAppointmentAvailabilityCalendar(fAppointmentDoctor?.selectedOptions?.[0]); });
    fAppointmentPaid?.addEventListener('input', updateAppointmentSummary);
    fAppointmentMethod?.addEventListener('change', updateAppointmentSummary);
    fAppointmentFree?.addEventListener('change', updateAppointmentSummary);
    [fAppointmentDiscountType,fAppointmentDiscountValue,fAppointmentTaxRate].forEach(function(field){ field?.addEventListener('input', updateAppointmentSummary); field?.addEventListener('change', updateAppointmentSummary); });
    form.noValidate = true;
    form.addEventListener('submit', function(event){
        updateAppointmentSummary();
        if (!form.checkValidity()) {
            event.preventDefault();
            const invalid = form.querySelector(':invalid');
            if (appointmentValidationMessage) {
                appointmentValidationMessage.textContent = invalid?.validationMessage || 'Please correct the highlighted fields before saving.';
                appointmentValidationMessage.hidden = false;
            }
            invalid?.scrollIntoView({behavior:'smooth', block:'center'});
            invalid?.focus({preventScroll:true});
        } else if (appointmentValidationMessage) {
            appointmentValidationMessage.hidden = true;
        }
    });
    appointmentToggle?.addEventListener('click', function(){ setAppointmentOpen(fBookAppointment.value !== '1'); });
    function openModal(){ setAppointmentOpen(false); overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.getElementById('addPatientBtn').addEventListener('click', function(){
        appointmentOnly = false;
        form.reset();
        if (fAppointmentOnly) fAppointmentOnly.value = '';
        fId.value = '';
        fAge.value = '';
        fDob.value = '';
        fAgeYears.value = '';
        syncAgeDisplay();
        modalTitle.textContent = 'Register Patient';
        openModal();
    });

    document.querySelectorAll('.edit-patient-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            appointmentOnly = false;
            form.reset();
            if (fAppointmentOnly) fAppointmentOnly.value = '';
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            fPhone.value = btn.dataset.phone;
            fAddress.value = btn.dataset.address;
            fGender.value = btn.dataset.gender;
            fAge.value = btn.dataset.age;
            fDob.value = btn.dataset.dob || '';
            fAgeYears.value = btn.dataset.age || '';
            syncAgeDisplay();
            fType.value = btn.dataset.type;
            fDoctor.value = btn.dataset.doctor;
        fRemark.value = btn.dataset.remark;
        if (fAppointmentPaid) fAppointmentPaid.value = '0';
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
    fAgeYears.value = <?= json_encode($oldPatient['Age']) ?>;
    syncAgeDisplay();
    fType.value = <?= json_encode($oldPatient['PatientType']) ?>;
    fDoctor.value = <?= json_encode($oldPatient['AllocatedDoctor']) ?>;
    fRemark.value = <?= json_encode($oldPatient['Remark']) ?>;
    fAppointmentDoctor.value = <?= json_encode($oldPatient['AppointmentDoctorID']) ?>;
    fAppointmentDate.value = <?= json_encode($oldPatient['AppointmentDate']) ?>;
    fAppointmentReason.value = <?= json_encode($oldPatient['AppointmentReason']) ?>;
    fAppointmentPaid.value = <?= json_encode($oldPatient['AppointmentAmountPaid']) ?>;
    fAppointmentMethod.value = <?= json_encode($oldPatient['AppointmentPaymentMethod']) ?>;
    modalTitle.textContent = fId.value ? 'Edit Patient' : 'Register Patient';
    openModal();
    setAppointmentOpen(<?= json_encode($oldPatient['BookAppointment'] === '1') ?>);
    if (<?= json_encode($oldPatient['BookAppointment'] === '1') ?>) updateAvailabilityHint();
    if (appointmentValidationMessage) { appointmentValidationMessage.textContent = <?= json_encode(implode(' ', $errors)) ?>; appointmentValidationMessage.hidden = false; }
    <?php endif; ?>

    <?php if ($hasPatientToast): ?>
    showToast(<?= json_encode($patientToast) ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'reception.php?section=patients'); }
    <?php endif; ?>
})();
<?php endif; ?>

<?php if ($section === 'pharmacy'): ?>
(function(){
    <?php if (isset($_GET['payment'])): ?>
    showToast('Pharmacy payment recorded successfully.');
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'reception.php?section=pharmacy'); }
    <?php endif; ?>
})();
<?php endif; ?>
</script>
<script>
(function(){
    const dateField = document.getElementById('cv_date');
    const slotPreview = document.getElementById('cv_selected_slot');
    if (!dateField || !slotPreview) return;
    const weekdays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    function updateAppointmentPreview(){
        if (!dateField.value) { slotPreview.textContent = 'Choose a date to see the selected weekday.'; return; }
        const selected = new Date(dateField.value);
        const dayName = weekdays[selected.getDay()];
        const dateText = selected.toLocaleDateString(undefined, {day:'numeric', month:'long', year:'numeric'});
        const timeText = selected.toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit'});
        const days = (dateField.dataset.days || '').split(',').filter(Boolean);
        const valid = days.includes(String(selected.getDay() || 7)) && dateField.value.slice(11, 16) >= (dateField.dataset.start || '') && dateField.value.slice(11, 16) <= (dateField.dataset.end || '');
        slotPreview.textContent = (valid ? 'Selected: ' : 'Unavailable: ') + dayName + ', ' + dateText + ' at ' + timeText;
        slotPreview.classList.toggle('invalid', !valid && Boolean(dateField.dataset.days));
    }
    dateField.addEventListener('input', updateAppointmentPreview);
    dateField.addEventListener('change', updateAppointmentPreview);
    document.getElementById('cv_doctor')?.addEventListener('change', function(){ window.setTimeout(updateAppointmentPreview, 0); });
    updateAppointmentPreview();
})();
</script>

<style>
.billing-adjustment-row>td{padding:0!important;background:#f7f8fc;border-bottom:1px solid var(--border-ui)}.billing-adjustment-panel{width:100%;box-sizing:border-box;padding:20px 22px;border:1px solid var(--border-ui);border-radius:12px;background:#fff;box-shadow:0 8px 20px rgba(29,48,94,.08)}.billing-adjustment-panel>header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding-bottom:14px;margin-bottom:16px;border-bottom:1px solid var(--border-ui)}.billing-adjustment-panel h3{margin:0;color:var(--primary);font-size:16px}.billing-adjustment-panel header p{margin:4px 0 0;color:var(--text-muted);font-size:12px}.billing-adjustment-panel header>strong{color:var(--text-secondary);font-size:13px;white-space:nowrap}.billing-adjustment-panel header>strong span{color:var(--primary);font-size:16px}.billing-adjustment-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:24px;align-items:start}.adjustment-controls{display:grid;gap:12px}.adjustment-toggle{display:flex;align-items:center;gap:8px;font-weight:700;color:var(--text-primary);font-size:13px}.adjustment-toggle input{accent-color:var(--primary)}.adjustment-fields{display:grid;grid-template-columns:minmax(170px,.8fr) minmax(140px,.7fr);gap:12px;padding:12px;border:1px solid var(--border-ui);border-radius:10px;background:#fafbff}.adjustment-fields label{display:grid;gap:6px;color:var(--text-secondary);font-size:12px;font-weight:700}.adjustment-fields label:last-child{grid-column:1/-1}.adjustment-fields input,.adjustment-fields select{width:100%;min-height:38px;padding:8px 10px;border:1px solid var(--border-ui);border-radius:8px;background:#fff;color:var(--text-primary);font:inherit}.input-suffix{display:flex;align-items:center}.input-suffix input{border-radius:8px 0 0 8px}.input-suffix span{min-height:38px;display:grid;place-items:center;padding:0 10px;border:1px solid var(--border-ui);border-left:0;border-radius:0 8px 8px 0;background:var(--surface-secondary);color:var(--text-muted);font-size:11px}.adjustment-summary{padding:14px 16px;border:1px solid var(--border-ui);border-radius:10px;background:#fafbff}.adjustment-summary h4{margin:0 0 8px;color:var(--primary);font-size:13px}.adjustment-summary>div{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #e4e7ef;color:var(--text-secondary);font-size:12px}.adjustment-summary strong{color:var(--text-primary);font-variant-numeric:tabular-nums}.adjustment-summary .summary-final,.adjustment-summary .summary-due{margin-top:4px;border-top:2px solid var(--primary);border-bottom:0;font-weight:800;color:var(--primary)}.adjustment-summary .summary-due{border-top:1px solid var(--border-ui)}.adjustment-summary .summary-final strong,.adjustment-summary .summary-due strong{color:var(--primary);font-size:14px}.adjustment-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:16px;margin-top:16px;border-top:1px solid var(--border-ui)}@media(max-width:760px){.billing-adjustment-panel{padding:16px}.billing-adjustment-grid{grid-template-columns:1fr;gap:16px}.adjustment-fields{grid-template-columns:1fr}.adjustment-fields label:last-child{grid-column:auto}.billing-adjustment-panel>header{flex-direction:column}.adjustment-actions{justify-content:stretch}.adjustment-actions button{flex:1}}
</style>
<style>form.inline-payment-form,form.balance-form{display:none!important}.billing-adjustment-row{display:none!important}.unified-payment-card{width:min(820px,100%)}.unified-payment-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,.9fr);gap:22px}.unified-payment-grid>section{display:grid;gap:14px}.unified-payment-grid label,.unified-payment-section label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#3d466c}.unified-payment-grid input,.unified-payment-grid select,.unified-payment-section input,.unified-payment-section select{height:42px;min-height:42px;border:1px solid #d3d9ee;border-radius:8px;padding:0 11px;font:inherit}.unified-summary{padding:16px;border:1px solid #d5dbef;border-radius:12px;background:#f7f8ff}.unified-summary h3,.unified-payment-grid h3,.unified-payment-section h3{margin:0 0 10px;color:var(--navy);font-size:15px}.unified-summary>div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #e0e4f0;font-size:12px}.unified-summary strong{font-variant-numeric:tabular-nums}.unified-summary .unified-final{margin-top:6px;border-top:2px solid var(--navy);border-bottom:0;font-weight:800}.unified-summary .unified-final strong{font-size:18px;color:var(--navy)}.unified-payment-section{border-top:1px solid #e0e4f0;padding-top:16px}.unified-payment-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:700px){.unified-payment-grid,.unified-payment-fields{grid-template-columns:1fr}}
</style>
<script>(function(){function money(n){return(Math.round((Number(n)||0)*100)/100).toFixed(2)}function setup(form){if(form.dataset.adjustmentReady==='1')return;form.dataset.adjustmentReady='1';const dt=form.querySelector('.discount-toggle'),tt=form.querySelector('.tax-toggle'),df=form.querySelector('.discount-fields'),tf=form.querySelector('.tax-fields'),type=form.querySelector('[name="DiscountType"]'),value=form.querySelector('[name="DiscountValue"]'),reason=form.querySelector('[name="DiscountReason"]'),rate=form.querySelector('[name="TaxRate"]');const sync=()=>{df.hidden=!dt.checked;tf.hidden=!tt.checked;type.disabled=!dt.checked;value.disabled=!dt.checked;reason.disabled=!dt.checked;reason.required=dt.checked;rate.disabled=!tt.checked;const suffix=form.querySelector('[data-discount-suffix]');if(suffix)suffix.textContent=type.value==='Percentage'?'%':'Amount';const gross=Number(form.dataset.gross)||0,paid=Number(form.dataset.paid)||0,disc=dt.checked?(type.value==='Percentage'?gross*(Number(value.value)||0)/100:Number(value.value)||0):0,sub=Math.max(0,gross-Math.min(gross,disc)),tax=tt.checked?sub*(Number(rate.value)||0)/100:0,final=sub+tax;[['gross',gross],['discount',-disc],['subtotal',sub],['tax',tax],['final',final],['paid',paid],['due',Math.max(0,final-paid)]].forEach(function(pair){const node=form.querySelector('[data-summary-'+pair[0]+']');if(node)node.textContent=(pair[1]<0?'-':'')+money(Math.abs(pair[1]))});const current=form.querySelector('[data-current-final]');if(current)current.textContent=money(final)};[dt,tt,type,value,reason,rate].forEach(function(el){el&&el.addEventListener('input',sync);el&&el.addEventListener('change',sync)});sync()}document.addEventListener('click',function(event){const toggle=event.target.closest('[data-billing-adjustment-toggle]');if(toggle){const ref=toggle.dataset.billingAdjustmentToggle,panel=[...document.querySelectorAll('.billing-adjustment-row')].find(row=>row.dataset.billingAdjustmentPanel===ref);document.querySelectorAll('.billing-adjustment-row').forEach(function(row){if(row!==panel){row.hidden=true;const other=[...document.querySelectorAll('[data-billing-adjustment-toggle]')].find(btn=>btn.dataset.billingAdjustmentToggle===row.dataset.billingAdjustmentPanel);other&&other.setAttribute('aria-expanded','false')}});if(panel){panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden){const form=panel.querySelector('.adjustment-form');if(form)setup(form)}}return}const cancel=event.target.closest('.billing-adjustment-cancel');if(cancel){const row=cancel.closest('.billing-adjustment-row');if(row){row.hidden=true;const button=[...document.querySelectorAll('[data-billing-adjustment-toggle]')].find(btn=>btn.dataset.billingAdjustmentToggle===row.dataset.billingAdjustmentPanel);button&&button.setAttribute('aria-expanded','false')}}});document.querySelectorAll('.adjustment-form').forEach(setup)})();</script>
<script>(function(){const section=<?=json_encode($section)?>,csrf=<?=json_encode($csrfToken)?>;function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}function money(n){return(Math.round(((Number(n)||0)+1e-9)*100)/100).toFixed(2)}function openPayment(trigger){const row=trigger.closest('tr'),panel=[...document.querySelectorAll('.billing-adjustment-row')].find(x=>x.dataset.billingAdjustmentPanel===trigger.dataset.billingAdjustmentToggle),adjust=panel?.querySelector('.adjustment-form'),pay=row?.querySelector('form.balance-form,form.inline-payment-form');const ref=adjust?.querySelector('input[name="VisitID"],input[name="LaboratoryID"],input[name="BillRef"],input[name="ServiceAssignmentID"]')?.value||pay?.querySelector('input[name="VisitID"],input[name="LaboratoryID"],input[name="BillRef"],input[name="ServiceAssignmentID"]')?.value||trigger.dataset.billingAdjustmentToggle;const type=section==='laboratory'?'laboratory':section==='pharmacy'?'prescription':section==='services'?'service':'consultation';const gross=Number(adjust?.dataset.gross||row?.children[4]?.textContent||0),paid=Number(adjust?.dataset.paid||row?.children[5]?.textContent||0);const old=(name)=>adjust?.querySelector('[name="'+name+'"]')?.value||'';const modal=document.createElement('div');modal.className='tdc-modal';modal.id='unified-payment-modal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.innerHTML='<div class="tdc-modal-card unified-payment-card"><header class="tdc-modal-header"><div><h2>Manage Payment</h2><p>Adjust the bill and record payment for this transaction.</p></div><button type="button" class="tdc-modal-close" data-unified-close>×</button></header><form method="post" action="reception.php?section='+esc(section)+'" class="tdc-modal-body unified-payment-form"><input type="hidden" name="csrf_token" value="'+esc(csrf)+'"><input type="hidden" name="form_action" value="unified_payment"><input type="hidden" name="BillType" value="'+esc(type)+'"><input type="hidden" name="BillReference" value="'+esc(ref)+'"><div class="unified-payment-grid"><section><h3>Bill Adjustment</h3><label>Discount Type<select name="DiscountType"><option value="None">None</option><option value="Percentage">Percentage</option><option value="Fixed">Fixed Amount</option></select></label><label>Discount Value<input name="DiscountValue" type="number" min="0" step="0.01" value="'+esc(old('DiscountValue')||'0')+'"></label><label>Discount Reason<input name="DiscountReason" value="'+esc(old('DiscountReason'))+'" placeholder="Required when discount is used"></label><label>Tax Rate %<input name="TaxRate" type="number" min="0" max="100" step="0.01" value="'+esc(old('TaxRate')||'0')+'"></label></section><aside class="unified-summary"><h3>Financial Summary</h3><div><span>Gross</span><strong data-u="gross">'+money(gross)+'</strong></div><div><span>Discount</span><strong data-u="discount">0.00</strong></div><div><span>Subtotal</span><strong data-u="subtotal">'+money(gross)+'</strong></div><div><span>Tax</span><strong data-u="tax">0.00</strong></div><div class="unified-final"><span>Final</span><strong data-u="final">'+money(gross)+'</strong></div><div><span>Already Paid</span><strong data-u="paid">'+money(paid)+'</strong></div><div><span>Current Due</span><strong data-u="due">'+money(Math.max(0,gross-paid))+'</strong></div></aside></div><section class="unified-payment-section"><h3>Payment</h3><div class="unified-payment-fields"><label>Amount to Pay<input name="PaymentAmount" type="number" min="0" step="0.01" value="0"></label><label>Payment Method<select name="PaymentMethod"><option value="">Not applicable</option><?php foreach($paymentMethods as $m): ?><option><?=tdc_e($m['MethodName'])?></option><?php endforeach; ?></select></label></div></section><footer class="tdc-modal-footer"><button type="button" class="btn-secondary" data-unified-close>Cancel</button><button type="submit" class="btn-success unified-submit">Save Adjustment</button></footer></form></div>';document.body.appendChild(modal);document.body.classList.add('modal-open');const form=modal.querySelector('form'),dt=form.DiscountType,dv=form.DiscountValue,dr=form.DiscountReason,tax=form.TaxRate,amount=form.PaymentAmount,method=form.PaymentMethod,submit=modal.querySelector('.unified-submit');dt.value=old('DiscountType')||'None';function sync(){const d=dt.value==='Percentage'?gross*(Number(dv.value)||0)/100:Number(dv.value)||0,sub=Math.max(0,gross-Math.min(gross,d)),tx=sub*(Math.min(100,Math.max(0,Number(tax.value)||0))/100),fin=sub+tx,due=Math.max(0,fin-paid);modal.querySelector('[data-u="discount"]').textContent=money(d);modal.querySelector('[data-u="subtotal"]').textContent=money(sub);modal.querySelector('[data-u="tax"]').textContent=money(tx);modal.querySelector('[data-u="final"]').textContent=money(fin);modal.querySelector('[data-u="due"]').textContent=money(due);amount.max=money(due);method.disabled=(Number(amount.value)||0)<=0;method.required=!method.disabled;dr.required=d>0;submit.textContent=Number(amount.value)>0?'Record Payment':'Save Adjustment';} [dt,dv,dr,tax,amount,method].forEach(x=>x.addEventListener('input',sync));[dt,method].forEach(x=>x.addEventListener('change',sync));form.addEventListener('submit',()=>{submit.disabled=true;submit.textContent='Processing...'});modal.querySelectorAll('[data-unified-close]').forEach(x=>x.addEventListener('click',()=>{modal.remove();document.body.classList.remove('modal-open')}));modal.addEventListener('click',e=>{if(e.target===modal){modal.remove();document.body.classList.remove('modal-open')}});sync();}document.addEventListener('click',function(e){const t=e.target.closest('[data-billing-adjustment-toggle]');if(t){e.preventDefault();e.stopImmediatePropagation();openPayment(t)}},true);document.querySelectorAll('form.balance-form,form.inline-payment-form').forEach(f=>{f.style.display='none';const row=f.closest('tr'),ref=f.querySelector('input[name="VisitID"],input[name="LaboratoryID"],input[name="BillRef"],input[name="ServiceAssignmentID"]')?.value;if(row&&!row.querySelector('[data-billing-adjustment-toggle]')){const b=document.createElement('button');b.type='button';b.className='btn-secondary btn-sm';b.dataset.billingAdjustmentToggle=ref;b.textContent='Manage Payment';row.querySelector('.row-actions')?.prepend(b)}});document.querySelectorAll('[data-billing-adjustment-toggle]').forEach(b=>{b.textContent='Manage Payment'});})();</script>

<script>(function(){document.querySelectorAll('tr[data-unified-gross]').forEach(function(row){const reference=row.querySelector('strong')?.textContent.trim(),button=row.querySelector('[data-billing-adjustment-toggle]'),source=row.querySelector('form.balance-form');if(!reference||!button)return;button.dataset.billingAdjustmentToggle=reference;if(source){const visit=source.querySelector('[name="VisitID"]');if(visit)visit.value=reference;}if(!document.querySelector('.billing-adjustment-row[data-billing-adjustment-panel="'+CSS.escape(reference)+'"]')){const panel=document.createElement('div');panel.className='billing-adjustment-row';panel.dataset.billingAdjustmentPanel=reference;panel.hidden=true;panel.innerHTML='<form class="adjustment-form" data-gross="'+row.dataset.unifiedGross+'" data-paid="'+row.dataset.unifiedPaid+'"><input name="VisitID" value="'+reference+'"><input name="DiscountType" value="'+(row.dataset.unifiedDiscountType||'None')+'"><input name="DiscountValue" value="'+(row.dataset.unifiedDiscountValue||'0')+'"><input name="DiscountReason" value="'+(row.dataset.unifiedDiscountReason||'')+'"><input name="TaxRate" value="'+(row.dataset.unifiedTaxRate||'0')+'"></form>';document.body.appendChild(panel);}});})();</script>
<script>(function(){document.querySelectorAll('tr[data-unified-gross]').forEach(function(row){let button=row.querySelector('[data-billing-adjustment-toggle]');const reference=row.querySelector('strong')?.textContent.trim();if(!reference)return;if(!button){button=document.createElement('button');button.type='button';button.className='btn-secondary btn-sm';button.textContent='Manage Payment';button.dataset.billingAdjustmentToggle=reference;row.lastElementChild?.prepend(button);}else{button.dataset.billingAdjustmentToggle=reference;button.textContent='Manage Payment';}});})();</script>
<script>(function(){document.querySelectorAll('tr[data-unified-gross] [data-billing-adjustment-toggle]').forEach(function(button){const row=button.closest('tr'),reference=button.dataset.billingAdjustmentToggle;if(!document.querySelector('.billing-adjustment-row[data-billing-adjustment-panel="'+CSS.escape(reference)+'"]')){const panel=document.createElement('div');panel.className='billing-adjustment-row';panel.dataset.billingAdjustmentPanel=reference;panel.hidden=true;panel.innerHTML='<form class="adjustment-form" data-gross="'+row.dataset.unifiedGross+'" data-paid="'+row.dataset.unifiedPaid+'"><input name="VisitID" value="'+reference+'"><input name="DiscountType" value="'+(row.dataset.unifiedDiscountType||'None')+'"><input name="DiscountValue" value="'+(row.dataset.unifiedDiscountValue||'0')+'"><input name="DiscountReason" value="'+(row.dataset.unifiedDiscountReason||'')+'"><input name="TaxRate" value="'+(row.dataset.unifiedTaxRate||'0')+'"></form>';document.body.appendChild(panel);}});})();</script>
<script>(function(){document.querySelectorAll('form.inline-payment-form,form.balance-form').forEach(function(form){form.remove();});})();</script>
<script>
(function(){
    const availability=document.getElementById('cv_availability');
    if(availability) availability.querySelectorAll('span').forEach(function(node){ node.textContent=node.textContent.replace(/ exact available days and hours\.?/i,' available days.').replace(/^Hours:.*$/i,''); });
    const cvSection=document.getElementById('cv_date')?.closest('.form-section');
    if(cvSection) cvSection.querySelectorAll('.form-section-heading span span,.rx-meta').forEach(function(node){ node.textContent=node.textContent.replace(/appointment time/gi,'appointment date').replace(/a day and time/gi,'a day'); });
})();
</script>
<script>
(function(){
    const doctor=document.getElementById('revisit-doctor'), meta=document.getElementById('revisit-doctor-meta');
    if(!doctor || !meta) return;
    const dayNames={1:'Mon',2:'Tue',3:'Wed',4:'Thu',5:'Fri',6:'Sat',7:'Sun'};
    function render(){
        const option=doctor.options[doctor.selectedIndex];
        if(!option || !option.value){ meta.textContent='Select a doctor to load the authoritative fee and availability.'; return; }
        const days=(option.dataset.days||'').split(',').map(function(day){ return dayNames[String(day).trim()]; }).filter(Boolean);
        const start=(option.dataset.start||'').trim(), end=(option.dataset.end||'').trim();
        if(!days.length || !start || !end){ meta.textContent='Availability not configured'; return; }
        meta.textContent=(option.dataset.specialty||'')+' · '+days.join(', ')+' · '+start+'–'+end;
    }
    doctor.addEventListener('change',function(){ window.setTimeout(render,0); },true);
    doctor.addEventListener('click',function(){ window.setTimeout(render,0); });
    document.addEventListener('click',function(event){ if(event.target.closest('[data-appointment-action="revisit"]')) window.setTimeout(render,0); });
    render();
})();
</script>
<style>
.tdc-native-date-source{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important}
.tdc-availability-calendar{margin-top:8px;padding:10px;border:1px solid var(--border-ui,#d5dbef);border-radius:10px;background:#fff;max-width:320px}
.tdc-availability-calendar.is-invalid{border-color:#b42332;box-shadow:0 0 0 2px rgba(180,35,50,.08)}
.tdc-field-error{margin-top:6px;color:var(--text-muted,#78819c);font-size:11px;line-height:1.35;font-weight:600}
.tdc-field-error.field-hint-error{color:#b42332;font-weight:700}
.tdc-availability-calendar[aria-disabled="true"]{opacity:.62}
.tdc-calendar-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;color:var(--primary,#30339a);font-weight:800;font-size:12px}
.tdc-calendar-head button{width:28px;height:28px;border:1px solid var(--border-ui,#d5dbef);border-radius:7px;background:#fff;color:var(--primary,#30339a);cursor:pointer;font-weight:800}
.tdc-calendar-head button:disabled{opacity:.4;cursor:not-allowed}
.tdc-calendar-selected{display:grid;gap:2px;padding:7px 9px;margin-bottom:8px;border:1px solid #e2e5f0;border-radius:7px;background:#fafbff;color:var(--text-muted,#78819c);font-size:10px}
.tdc-calendar-selected strong{color:var(--primary,#30339a);font-size:12px}
.tdc-calendar-week,.tdc-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center}
.tdc-calendar-week{margin-bottom:3px;color:var(--text-muted,#78819c);font-size:10px;font-weight:800}
.tdc-calendar-grid button{min-height:28px;border:1px solid transparent;border-radius:6px;background:#fff;color:var(--text-primary,#29345f);font:inherit;font-size:11px;cursor:pointer}
.tdc-calendar-grid button:hover:not(:disabled){border-color:var(--primary,#30339a);background:#f1f2ff}
.tdc-calendar-grid button.is-other-month{color:#b5bbcb}
.tdc-calendar-grid button.is-unavailable,.tdc-calendar-grid button:disabled{background:#f0f1f5;color:#b6bcc9;cursor:not-allowed;text-decoration:line-through}
.tdc-calendar-grid button.is-today{box-shadow:inset 0 0 0 1px #8d93b4}
.tdc-calendar-grid button.is-selected{background:var(--primary,#30339a);color:#fff;border-color:var(--primary,#30339a);font-weight:800;text-decoration:none}
.tdc-calendar-message{margin:8px 0 0;color:var(--text-muted,#78819c);font-size:11px}
.tdc-calendar-message.is-error{color:#b42332;font-weight:700}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const bookings=<?= json_encode($appointmentBookingsByDoctor) ?>;
    const calendars={};
    ['revisit','edit'].forEach(function(kind){
        const field=document.getElementById(kind+'-date'),doctor=document.getElementById(kind+'-doctor');
        if(!field||!doctor)return;
        [...doctor.options].forEach(function(option){
            const source=[...document.getElementById('revisit-doctor').options].find(o=>o.value===option.value);
            if(source) ['days','start','end'].forEach(key=>option.dataset[key]=source.dataset[key]||'');
            option.dataset.booked=JSON.stringify(bookings[option.value]||[]);
        });
        const root=document.createElement('div');root.className='appointment-availability-calendar';root.setAttribute('role','group');root.setAttribute('aria-label','Available appointment dates');
        const hint=document.createElement('div');hint.className='field-hint';hint.setAttribute('role','status');
        field.insertAdjacentElement('afterend',root);root.insertAdjacentElement('beforebegin',hint);
        calendars[kind]=window.TdcAppointmentCalendar(field,doctor,root,hint,<?= TDC_APPOINTMENT_MINUTES ?>);
    });
    document.addEventListener('click',function(event){
        const trigger=event.target.closest('[data-appointment-action]');
        if(trigger) setTimeout(function(){const kind=trigger.dataset.appointmentAction;calendars[kind]?.load(kind==='edit');},0);
    });
});
</script>
</body>
</html>
