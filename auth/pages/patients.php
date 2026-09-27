<?php
/**
 * auth/pages/patients.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Patient Records
 * ---------------------------------------------------------------------
 * This page is the read-oriented counterpart to Reception's patient
 * intake flow: any authenticated staff member can search patients and
 * open a full record (demographics + laboratory history + pharmacy
 * history in one place). Only 'superuser' and 'receptionuser' may
 * create, edit, delete, or record a new visit — the same manage roles
 * used for Reception's patient section — enforced server-side.
 *
 * Editing an individual lab or pharmacy bill still happens on
 * reception.php (single source of truth for that workflow); this page
 * links out to it rather than duplicating those forms.
 *
 * Security controls (same posture as home.php / reception.php / doctors.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: any authenticated role may VIEW; only 'superuser' and
 *     'receptionuser' may add/edit/delete/record-visit (checked
 *     server-side on every POST, independent of what the UI shows)
 *   - CSRF-token-checked POST handler, rotated on every submit
 *   - Prepared statements only — no string-built SQL from user input
 *   - Post/Redirect/Get on every successful write
 *   - All session/user-derived output escaped before hitting HTML
 *
 * Schema alignment: Patients, Doctors, Laboratory, Prescriptions
 * (see reception.php header for the full Prescriptions bill-grouping
 * convention — PrescriptionID = "RX000123-01", "-02", ... per bill).
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
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_MANAGE_ROLES = ['superuser', 'receptionuser'];

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

/**
 * Single authoritative CSV column contract for patients.
 *
 * These exact (lowercase) header names are written by the template
 * download AND consumed by the importer, so the two always agree.
 * Header matching tolerates surrounding whitespace, UTF-8 BOM and case
 * (see tdc_csv_upload_rows), but unrelated columns are never silently mapped.
 */
const PATIENT_CSV_HEADERS = [
    'patient_name', 'phone', 'address', 'gender',
    'date_of_birth', 'patient_type', 'doctor_id', 'remark',
];

/** Columns that MUST be present (and non-empty where relevant) for an import row. */
const PATIENT_CSV_REQUIRED_HEADERS = [
    'patient_name', 'phone', 'gender', 'date_of_birth', 'patient_type',
];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / doctors.php / settings.php.
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

/** Redirects (Post/Redirect/Get) back to the list with a one-shot flash flag. */
function tdc_redirect(string $flag): void
{
    $statusFlags = ['saved', 'updated', 'booked', 'booked_new', 'updated_booked', 'updated_appointment'];
    $query = in_array($flag, $statusFlags, true) ? 'status=' . urlencode($flag) : $flag . '=1';
    header('Location: patients.php?' . $query);
    exit;
}

/** Runs a scalar query and returns the single value. */
function tdc_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// =======================================================================
// SECTION 4 — Validation
// =======================================================================

/** @param array{PatientName:string,PatientPhone:string,Gender:string,Age:string,DateOfBirth:string,PatientType:string,AllocatedDoctor:string} $input */
function tdc_validate_patient_form(array $input, bool $requireCoreFields = true): array
{
    $errors = [];

    if ($input['PatientName'] === '' || mb_strlen($input['PatientName']) < 2 || mb_strlen($input['PatientName']) > 150) {
        $errors[] = 'Patient name is required (2-150 characters).';
    }
    if ($requireCoreFields && $input['PatientPhone'] === '') {
        $errors[] = 'Mobile number is required.';
    } elseif ($input['PatientPhone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $input['PatientPhone'])) {
        $errors[] = 'Phone number format is invalid.';
    }
    if (($requireCoreFields && $input['Gender'] === '') || ($input['Gender'] !== '' && !array_key_exists($input['Gender'], GENDER_OPTIONS))) {
        $errors[] = 'Gender is required.';
    }
    if ($requireCoreFields && $input['DateOfBirth'] === '' && $input['Age'] === '') {
        $errors[] = 'Age or Date of Birth is required.';
    }
    if ($input['Age'] !== '' && (!ctype_digit($input['Age']) || (int) $input['Age'] > 150)) {
        $errors[] = 'Age must be a whole number between 0 and 150.';
    }
    if ($input['DateOfBirth'] !== '' && !tdc_is_valid_date($input['DateOfBirth'])) {
        $errors[] = 'Date of birth is not a valid date.';
    } elseif ($input['DateOfBirth'] !== '' && $input['DateOfBirth'] > date('Y-m-d')) {
        $errors[] = 'Date of birth cannot be in the future.';
    }
    if (($requireCoreFields && $input['PatientType'] === '') || ($input['PatientType'] !== '' && !array_key_exists($input['PatientType'], PATIENT_TYPE_OPTIONS))) {
        $errors[] = 'Patient type is required.';
    }
    if ($input['AllocatedDoctor'] !== '' && !ctype_digit($input['AllocatedDoctor'])) {
        $errors[] = 'Please select a valid doctor.';
    }

    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence
// =======================================================================

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

/**
 * Read the patient list's filter state from the query string into a clean,
 * validated array. Centralised here so the list view and the CSV export
 * apply identical filtering (export honours the active filters).
 */
function tdc_patient_filter_state(): array
{
    $q      = trim((string) ($_GET['q'] ?? ''));
    $doctor = isset($_GET['doctor']) && ctype_digit((string) $_GET['doctor']) ? (int) $_GET['doctor'] : 0;
    $type   = (string) ($_GET['type'] ?? '');
    $type   = array_key_exists($type, PATIENT_TYPE_OPTIONS) ? $type : '';
    $reg    = trim((string) ($_GET['registered'] ?? ''));
    $reg    = $reg !== '' && tdc_is_valid_date($reg) ? $reg : '';

    return [
        'q'          => $q,
        'doctor'     => $doctor,
        'type'       => $type,
        'registered' => $reg,
    ];
}

/**
 * Build the WHERE clause + bound params for the patient filter state.
 *
 * @return array{0:string,1:array<string,mixed>} [whereClause, params]
 */
function tdc_patient_filter_where(array $state): array
{
    $conditions = [];
    $params     = [];

    if ($state['q'] !== '') {
        $conditions[] = '(p.PatientName LIKE :q1 OR p.PatientPhone LIKE :q2)';
        $params['q1'] = $params['q2'] = '%' . $state['q'] . '%';
    }
    if ($state['doctor'] > 0) {
        $conditions[] = 'p.AllocatedDoctor = :doctor';
        $params['doctor'] = $state['doctor'];
    }
    if ($state['type'] !== '') {
        $conditions[] = 'p.PatientType = :patient_type';
        $params['patient_type'] = $state['type'];
    }
    if ($state['registered'] !== '') {
        $conditions[] = 'DATE(p.RegisteredAt) = :registered';
        $params['registered'] = $state['registered'];
    }

    return [$conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
}

/**
 * Transactional cascade: the schema has no FK constraints, so linked
 * clinical, pharmacy, payment, and ledger rows are cleared explicitly.
 *
 * @return string[] error messages; empty on success
 */
function tdc_delete_patient(PDO $pdo, int $id): array
{
    $pdo->beginTransaction();
    try {
        $visits = $pdo->prepare('SELECT VisitID, VisitReference FROM visits WHERE PatientID = :id');
        $visits->execute(['id' => $id]);
        $visitRows = $visits->fetchAll();
        $visitIds = array_map(static fn($r) => (int) $r['VisitID'], $visitRows);
        $visitRefs = array_values(array_filter(array_map(static fn($r) => (string) $r['VisitReference'], $visitRows)));
        $labs = $pdo->prepare('SELECT LaboratoryID FROM laboratory WHERE PatientID = :id');
        $labs->execute(['id' => $id]);
        $labIds = array_map('strval', $labs->fetchAll(PDO::FETCH_COLUMN));
        $rx = $pdo->prepare('SELECT PrescriptionID, PharmacySaleReference FROM prescriptions WHERE PatientID = :id');
        $rx->execute(['id' => $id]);
        $rxRows = $rx->fetchAll();
        $rxRefs = array_map(static fn($r) => (string) $r['PrescriptionID'], $rxRows);
        $saleRefs = array_values(array_filter(array_map(static fn($r) => (string) ($r['PharmacySaleReference'] ?? ''), $rxRows)));
        $sales = $pdo->prepare('SELECT SaleID FROM pharmacysales WHERE PatientID = :id');
        $sales->execute(['id' => $id]);
        $saleRefs = array_values(array_unique(array_merge($saleRefs, array_map('strval', $sales->fetchAll(PDO::FETCH_COLUMN)))));
        $paymentRefs = [];
        $p = $pdo->prepare('SELECT PaymentReference FROM payments WHERE PatientID = :id');
        $p->execute(['id' => $id]);
        $paymentRefs = array_map('strval', $p->fetchAll(PDO::FETCH_COLUMN));

        // Remove dependent rows first because this installation uses app-level
        // relationships rather than database foreign keys.
        if ($labIds) {
            $in = implode(',', array_fill(0, count($labIds), '?'));
            // Both laboratory child tables must be cleared before laboratory
            // rows because the catalog bridge has a foreign-key constraint.
            $pdo->prepare("DELETE FROM laborderitems WHERE LaboratoryID IN ($in)")->execute($labIds);
            $bridge = $pdo->prepare("SELECT BridgeID FROM lab_order_catalog_bridge WHERE LaboratoryID IN ($in)");
            $bridge->execute($labIds);
            $bridgeIds = array_map('strval', $bridge->fetchAll(PDO::FETCH_COLUMN));
            if ($bridgeIds) {
                $bin = implode(',', array_fill(0, count($bridgeIds), '?'));
                $results = $pdo->prepare("SELECT LabResultID FROM lab_results WHERE BridgeID IN ($bin)");
                $results->execute($bridgeIds);
                $resultIds = array_map('strval', $results->fetchAll(PDO::FETCH_COLUMN));
                if ($resultIds) {
                    $rin = implode(',', array_fill(0, count($resultIds), '?'));
                    $pdo->prepare("DELETE FROM lab_result_attachments WHERE LabResultID IN ($rin)")->execute($resultIds);
                    $pdo->prepare("DELETE FROM lab_result_parameters WHERE LabResultID IN ($rin)")->execute($resultIds);
                    $pdo->prepare("DELETE FROM lab_result_review WHERE LabResultID IN ($rin)")->execute($resultIds);
                    $pdo->prepare("DELETE FROM lab_results WHERE LabResultID IN ($rin)")->execute($resultIds);
                }
            }
            $pdo->prepare("DELETE FROM lab_order_catalog_bridge WHERE LaboratoryID IN ($in)")->execute($labIds);
        }
        if ($visitIds) { $in = implode(',', array_fill(0, count($visitIds), '?')); $pdo->prepare("DELETE FROM laboratory WHERE VisitID IN ($in)")->execute($visitIds); }
        if ($labIds) { $in = implode(',', array_fill(0, count($labIds), '?')); $pdo->prepare("DELETE FROM laboratory WHERE LaboratoryID IN ($in)")->execute($labIds); }
        if ($rxRefs) { $in = implode(',', array_fill(0, count($rxRefs), '?')); $pdo->prepare("DELETE FROM prescriptionsheader WHERE PrescriptionID IN ($in)")->execute($rxRefs); }
        if ($saleRefs) { $in = implode(',', array_fill(0, count($saleRefs), '?')); $pdo->prepare("DELETE FROM pharmacysales WHERE SaleID IN ($in)")->execute($saleRefs); }
        if ($visitRefs || $labIds || $rxRefs || $saleRefs || $paymentRefs) {
            $refs = array_values(array_unique(array_merge($visitRefs, $labIds, $rxRefs, $saleRefs, $paymentRefs)));
            $in = implode(',', array_fill(0, count($refs), '?'));
            $pdo->prepare("DELETE FROM accounting WHERE ReferenceID IN ($in)")->execute($refs);
        }
        $pdo->prepare('DELETE FROM payments WHERE PatientID = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM prescriptions WHERE PatientID = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM laboratory WHERE PatientID = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM visits WHERE PatientID = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM patients WHERE PatientID = ?')->execute([$id]);
        $pdo->commit();
        // Audit logging must never make an already-completed cleanup fail.
        try {
            if (function_exists('tdc_audit')) {
                tdc_audit($pdo, 'patients.cascade_deleted', 'Patients', (string) $id, 'Patient and linked clinical, pharmacy, payment, and ledger history were permanently cleared.');
            }
        } catch (Throwable $auditError) {
            error_log('[PATIENT DELETE AUDIT] ' . $auditError->getMessage());
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PATIENT DELETE] ' . $e->getMessage());
        return ['The patient and linked history could not be cleared. No records were changed.'];
    }
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
require_once __DIR__ . '/../includes/data-transfer.php';
require_once __DIR__ . '/../includes/workflow.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$canCreate = tdc_can('patients.create');
$canEdit = tdc_can('patients.edit');
$canDelete = tdc_can('patients.delete');
$canCreateVisit = tdc_can('visits.create');
$canImport = tdc_can('patients.import');
$canExport = tdc_can('patients.export');
$canManage = $canCreate || $canEdit || $canDelete;

if (($_GET['download'] ?? '') === 'patient-template') {
    tdc_require_permission('patients.import');
    // One row example; date_of_birth deliberately shown as YYYY-MM-DD so the
    // template itself documents the required import date format.
    tdc_csv_download('patient-import-example.csv', PATIENT_CSV_HEADERS, [['Example Patient','615000000','Mogadishu','Male','2000-01-15','New Patient','','Example row - remove before importing']]);
}
if (($_GET['download'] ?? '') === 'patients') {
    tdc_require_permission('patients.export');
    // Export honours the active list filters (Search / Type / Doctor / Date).
    // With no filters active the query is identical to exporting every patient.
    $exportState = tdc_patient_filter_state();
    [$exportWhere, $exportParams] = tdc_patient_filter_where($exportState);
    $stmt = $pdo->prepare(
        'SELECT PatientID,PatientName,PatientPhone,PatientAddress,Gender,DateOfBirth,PatientType,AllocatedDoctor,VisitNumber,DueBalance,RegisteredAt '
        . 'FROM patients ' . $exportWhere . ' ORDER BY PatientID'
    );
    $stmt->execute($exportParams);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = array_values($row);
    }
    tdc_csv_download('patients-' . date('Y-m-d') . '.csv', ['patient_id','patient_name','phone','address','gender','date_of_birth','patient_type','doctor_id','visit_count','due_balance','registered_at'], $rows);
}
if (($_GET['download'] ?? '') === 'patients-xlsx') {
    tdc_require_permission('patients.export');
    $exportState = tdc_patient_filter_state();
    [$exportWhere, $exportParams] = tdc_patient_filter_where($exportState);
    $stmt = $pdo->prepare(
        'SELECT PatientID,PatientName,PatientPhone,PatientAddress,Gender,DateOfBirth,PatientType,AllocatedDoctor,VisitNumber,DueBalance,RegisteredAt '
        . 'FROM patients ' . $exportWhere . ' ORDER BY PatientID'
    );
    $stmt->execute($exportParams);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = array_values($row);
    }
    tdc_xlsx_download('patients-' . date('Y-m-d') . '.xlsx', ['patient_id','patient_name','phone','address','gender','date_of_birth','patient_type','doctor_id','visit_count','due_balance','registered_at'], $rows);
}

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$errors = [];
$importResult = $_SESSION['tdc_import_result'] ?? null;
unset($_SESSION['tdc_import_result']);
$old = [
    'PatientID' => '', 'PatientName' => '', 'PatientPhone' => '', 'PatientAddress' => '',
    'Gender' => '', 'Age' => '', 'DateOfBirth' => '', 'PatientType' => '',
    'AllocatedDoctor' => '', 'Remark' => '',
    'BookAppointment' => '', 'AppointmentOnly' => '', 'AppointmentDoctorID' => '', 'AppointmentDate' => '',
    'AppointmentFreeConsultation' => '', 'AppointmentDiscountType' => 'None', 'AppointmentDiscountValue' => '0', 'AppointmentDiscountReason' => '', 'AppointmentTaxRate' => '0',
    'AppointmentReason' => '', 'AppointmentAmountPaid' => '0', 'AppointmentPaymentMethod' => '', 'AppointmentVisitID' => '',
];

// =======================================================================
// SECTION 9 — POST handler (save / delete / record visit)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        // Defense in depth: the UI already hides these controls for
        // non-manager roles, but never trust the client alone.
        header('Location: patients.php');
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        if ($formAction === 'import_csv') {
            tdc_require_permission('patients.import');
            try {
                $upload = $_FILES['csv_file'] ?? [];
                $uploadExtension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                $uploadReader = $uploadExtension === 'xlsx' ? 'tdc_xlsx_upload_rows' : 'tdc_csv_upload_rows';
                $rows = $uploadReader($upload, ['patient_name'], [
                    'patient_id', 'patient_name', 'phone', 'address', 'gender', 'date_of_birth', 'patient_type',
                    'doctor_id', 'remark', 'visit_count', 'due_balance', 'registered_at',
                ]);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
                $rows = [];
            }

            if (empty($rows)) {
                if (empty($errors)) {
                    $errors[] = 'The uploaded CSV/XLSX file contains no patient rows.';
                }
            } else {
                // Resolve doctors once (by numeric ID or by name) for the doctor_id column.
                $doctorsStmt    = $pdo->query('SELECT DoctorID, DoctorName FROM doctors ORDER BY DoctorName ASC');
                $doctorById     = [];
                $doctorIdByName = [];
                while ($d = $doctorsStmt->fetch()) {
                    $doctorById[(string) $d['DoctorID']] = (int) $d['DoctorID'];
                    $doctorIdByName[mb_strtolower(trim((string) $d['DoctorName']))] = (int) $d['DoctorID'];
                }

                $imported       = 0;
                $created        = 0;
                $updated        = 0;
                $skipped        = [];
                $failed         = [];
                $validatedPatients = [];

                // Transactional: valid rows commit together; each row is a single
                // atomic INSERT, so no half-written record is ever created.
                $pdo->beginTransaction();
                try {
                    foreach ($rows as $index => $row) {
                        $rowNum = $index + 2; // header is row 1
                        $rowErrors = [];

                        $rawName   = trim((string) ($row['patient_name'] ?? ''));
                        $rawPhone  = trim((string) ($row['phone'] ?? ''));
                        $phone     = trim(ltrim($rawPhone, "'")); // tolerate Excel text marker
                        $address   = trim((string) ($row['address'] ?? ''));
                        $gender    = trim((string) ($row['gender'] ?? ''));
                        $rawDob    = trim((string) ($row['date_of_birth'] ?? ''));
                        $type      = trim((string) ($row['patient_type'] ?? ''));
                        $rawDoctor = trim((string) ($row['doctor_id'] ?? ''));
                        $remark    = trim((string) ($row['remark'] ?? ''));
                        $patientId = ctype_digit(trim((string) ($row['patient_id'] ?? ''))) ? (int) $row['patient_id'] : 0;
                        $existingPatient = null;
                        if ($patientId > 0) {
                            $existingStmt = $pdo->prepare('SELECT * FROM patients WHERE PatientID=? LIMIT 1');
                            $existingStmt->execute([$patientId]);
                            $existingPatient = $existingStmt->fetch() ?: null;
                            if (!$existingPatient) {
                                $rowErrors[] = 'patient_id does not exist';
                            }
                        }
                        // Preserve export/import round-trips even if a spreadsheet
                        // editor drops the patient_id cell. Name + phone must both
                        // match, so a shared phone number cannot update the wrong row.
                        if (!$existingPatient && $patientId === 0 && $rawName !== '' && $phone !== '') {
                            $existingStmt = $pdo->prepare('SELECT * FROM patients WHERE PatientName=? AND PatientPhone=? LIMIT 1');
                            $existingStmt->execute([$rawName, $phone]);
                            $existingPatient = $existingStmt->fetch() ?: null;
                            if ($existingPatient) {
                                $patientId = (int) $existingPatient['PatientID'];
                            }
                        }
                        if ($existingPatient) {
                            $rawName = $rawName !== '' ? $rawName : (string) ($existingPatient['PatientName'] ?? '');
                            $phone = $phone !== '' ? $phone : (string) ($existingPatient['PatientPhone'] ?? '');
                            $address = $address !== '' ? $address : (string) ($existingPatient['PatientAddress'] ?? '');
                            $gender = $gender !== '' ? $gender : (string) ($existingPatient['Gender'] ?? '');
                            $rawDob = $rawDob !== '' ? $rawDob : (string) ($existingPatient['DateOfBirth'] ?? '');
                            $type = $type !== '' ? $type : (string) ($existingPatient['PatientType'] ?? '');
                            $rawDoctor = $rawDoctor !== '' ? $rawDoctor : (string) ($existingPatient['AllocatedDoctor'] ?? '');
                            $remark = $remark !== '' ? $remark : (string) ($existingPatient['Remark'] ?? '');
                        }

                        // Case-insensitive normalisation to the canonical labels the
                        // rest of the app uses (the manual form always sends exact case).
                        if ($gender !== '') {
                            $lg = mb_strtolower($gender);
                            if ($lg === 'male') $gender = 'Male';
                            elseif ($lg === 'female') $gender = 'Female';
                        }
                        if ($type !== '') {
                            foreach (PATIENT_TYPE_OPTIONS as $v => $l) {
                                if (mb_strtolower($l) === mb_strtolower($type)) { $type = $l; break; }
                            }
                        }

                        // DOB -> canonical YYYY-MM-DD. Unparseable/ambiguous values
                        // are reported per row rather than silently guessed.
                        $normDob = $rawDob !== '' ? tdc_normalize_dob($rawDob) : '';
                        if ($rawDob !== '' && $normDob === '') {
                            $rowErrors[] = 'DateOfBirth must use YYYY-MM-DD.';
                        }
                        $age = ($normDob !== '' && tdc_dob_is_real($normDob))
                            ? (string) tdc_age_from_birth_date($normDob) : '';
                        if ($age === '' && $existingPatient) {
                            $age = (string) ($existingPatient['Age'] ?? '');
                        }

                        // Doctor resolution: numeric DoctorID or a doctor name.
                        $allocatedDoctor = '';
                        if ($rawDoctor !== '') {
                            if (ctype_digit($rawDoctor) && isset($doctorById[$rawDoctor])) {
                                $allocatedDoctor = $rawDoctor;
                            } elseif (isset($doctorIdByName[mb_strtolower($rawDoctor)])) {
                                $allocatedDoctor = (string) $doctorIdByName[mb_strtolower($rawDoctor)];
                            } else {
                                $rowErrors[] = 'Doctor "' . $rawDoctor . '" was not found.';
                            }
                        }

                        $patient = [
                            'PatientID'       => $patientId > 0 ? (string) $patientId : '',
                            'PatientName'     => $rawName,
                            'PatientPhone'    => $phone,
                            'PatientAddress'  => $address,
                            'Gender'          => $gender,
                            'Age'             => $age,
                            'DateOfBirth'     => $normDob,
                            'PatientType'     => $type,
                            'AllocatedDoctor' => $allocatedDoctor,
                            'Remark'          => $remark,
                        ];

                        // Row-level field validation (name length, phone format, gender,
                        // type, doctor shape, future DOB).
                        $rowErrors = array_merge($rowErrors, tdc_validate_patient_form($patient, !$existingPatient));
                        if (!$existingPatient) {
                            foreach (['Gender' => 'gender', 'DateOfBirth' => 'date_of_birth', 'PatientType' => 'patient_type'] as $field => $header) {
                                if ($patient[$field] === '') $rowErrors[] = $header . ' is required for new patients.';
                            }
                        }

                        if ($rowErrors) {
                            throw new RuntimeException('Import failed. Row ' . $rowNum . ': ' . implode(' ', $rowErrors));
                        }
                        $validatedPatients[] = [
                            'input' => $patient,
                            'is_edit' => $existingPatient !== null,
                            'id' => $patientId,
                        ];
                    }
                    foreach ($validatedPatients as $validatedPatient) {
                        tdc_save_patient(
                            $pdo,
                            $validatedPatient['input'],
                            $validatedPatient['is_edit'],
                            $validatedPatient['id']
                        );
                        $imported++;
                        if ($validatedPatient['is_edit']) $updated++; else $created++;
                    }
                    $pdo->commit();
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[PATIENTS CSV IMPORT] aborted: ' . $e->getMessage());
                    $errors[] = 'A system error occurred during import. No records were imported.';
                }

                if (empty($errors)) {
                    $_SESSION['tdc_import_result'] = [
                        'total'    => count($rows),
                        'imported' => $imported,
                        'created'  => $created,
                        'updated'  => $updated,
                        'skipped'  => $skipped,
                        'failed'   => $failed,
                    ];
                    try {
                            tdc_audit($pdo, 'patients.imported', 'Patients', null,
                            sprintf('%s import: %d imported, %d skipped, %d failed of %d rows.',
                                strtoupper($uploadExtension === 'xlsx' ? 'xlsx' : 'csv'),
                                $imported, count($skipped), count($failed), count($rows)));
                    } catch (Throwable $e) {
                        // Audit logging is best-effort and must not break the UX.
                    }
                    tdc_redirect('imported');
                }
            }
        } elseif ($formAction === 'delete') {


            tdc_require_permission('patients.delete');
            $deleteId = (int) ($_POST['PatientID'] ?? 0);
            $errors   = $deleteId > 0 ? tdc_delete_patient($pdo, $deleteId) : ['Invalid patient selected.'];
            if (empty($errors)) {
                tdc_redirect('deleted');
            }
        } elseif ($formAction === 'visit') {
            tdc_require_permission('visits.create');
            $patientId = (int) ($_POST['PatientID'] ?? 0);
            header('Location: reception.php?section=consultations&patient=' . $patientId);
            exit;
        } else {
            $old['PatientID']       = trim((string) ($_POST['PatientID'] ?? ''));
            $old['PatientName']     = trim((string) ($_POST['PatientName'] ?? ''));
            $old['PatientPhone']    = trim((string) ($_POST['PatientPhone'] ?? ''));
            $old['PatientAddress']  = trim((string) ($_POST['PatientAddress'] ?? ''));
            $old['Gender']          = (string) ($_POST['Gender'] ?? '');
            $old['Age']             = trim((string) ($_POST['Age'] ?? ''));
            $old['DateOfBirth']     = trim((string) ($_POST['DateOfBirth'] ?? ''));
            [$old['Age'], $old['DateOfBirth']] = tdc_sync_age_dob($old['Age'], $old['DateOfBirth']);
            $old['PatientType']     = (string) ($_POST['PatientType'] ?? '');
            $old['AllocatedDoctor'] = trim((string) ($_POST['AllocatedDoctor'] ?? ''));
            $old['Remark']          = trim((string) ($_POST['Remark'] ?? ''));
            $old['BookAppointment'] = (string) ($_POST['BookAppointment'] ?? '');
            $old['AppointmentOnly'] = (string) ($_POST['AppointmentOnly'] ?? '');
            $old['AppointmentDoctorID'] = trim((string) ($_POST['AppointmentDoctorID'] ?? ''));
            $old['AppointmentDate'] = trim((string) ($_POST['AppointmentDate'] ?? ''));
            $old['AppointmentReason'] = trim((string) ($_POST['AppointmentReason'] ?? ''));
            $old['AppointmentAmountPaid'] = trim((string) ($_POST['AppointmentAmountPaid'] ?? '0'));
            $old['AppointmentPaymentMethod'] = trim((string) ($_POST['AppointmentPaymentMethod'] ?? ''));
            $old['AppointmentVisitID'] = trim((string) ($_POST['AppointmentVisitID'] ?? ''));
                $old['AppointmentFreeConsultation'] = !empty($_POST['AppointmentFreeConsultation']) ? '1' : '';
                $old['AppointmentDiscountType'] = trim((string) ($_POST['AppointmentDiscountType'] ?? 'None'));
                $old['AppointmentDiscountValue'] = trim((string) ($_POST['AppointmentDiscountValue'] ?? '0'));
                $old['AppointmentDiscountReason'] = trim((string) ($_POST['AppointmentDiscountReason'] ?? ''));
                $old['AppointmentTaxRate'] = trim((string) ($_POST['AppointmentTaxRate'] ?? '0'));

            $isEdit = $old['PatientID'] !== '' && ctype_digit($old['PatientID']);
            tdc_require_permission($isEdit ? 'patients.edit' : 'patients.create');
            $errors = tdc_validate_patient_form($old);
            if (empty($errors)) {
                try {
                    if ($old['BookAppointment'] === '1' && empty($errors)) {
                        $pdo->beginTransaction();
                        $patientId = tdc_save_patient($pdo, $old, $isEdit, (int) $old['PatientID']);
                        if (($old['AppointmentOnly'] ?? '') === '1' && ctype_digit($old['AppointmentVisitID'] ?? '') && (int) $old['AppointmentVisitID'] > 0) {
                            tdc_update_patient_appointment($pdo, $patientId, (int) $old['AppointmentVisitID'], $old);
                        } else {
                            tdc_create_patient_appointment($pdo, $patientId, $old, (int) $_SESSION['user_id']);
                        }
                        $pdo->commit();
                    } elseif (empty($errors)) {
                        tdc_save_patient($pdo, $old, $isEdit, (int) $old['PatientID']);
                    }
                    if (!empty($errors)) throw new RuntimeException(implode(' ', $errors));
                    $successFlag = $old['BookAppointment'] === '1'
                        ? (($old['AppointmentOnly'] ?? '') === '1' ? ((ctype_digit($old['AppointmentVisitID'] ?? '') && (int) $old['AppointmentVisitID'] > 0) ? 'updated_appointment' : 'booked') : ($isEdit ? 'updated_booked' : 'booked_new'))
                        : ($isEdit ? 'updated' : 'saved');
                    tdc_redirect($successFlag);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[PATIENTS] save failed: ' . $e->getMessage());
                    if (empty($errors)) $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'A system error occurred while saving the patient. Please try again.';
                }
            }
        }
    }

    // Keep the session CSRF token stable for other open authenticated forms.
}

// =======================================================================
// SECTION 10 — GET data loading
// =======================================================================

// --- 10A. Doctors (used by the Add/Edit dropdown and the detail view) --
$doctors = $pdo->query('SELECT DoctorID,DoctorName,Specialty,UserID,ConsultationFee,WorkingDays,WorkStartTime,WorkEndTime FROM doctors ORDER BY DoctorName ASC')->fetchAll();
$appointmentBookingsByDoctor = [];
$bookingStmt = $pdo->query("SELECT DoctorID, DATE_FORMAT(VisitDate, '%Y-%m-%dT%H:%i') AS Slot FROM visits WHERE QueueStatus <> 'Cancelled' AND VisitDate >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
foreach ($bookingStmt->fetchAll() as $booking) $appointmentBookingsByDoctor[(int) $booking['DoctorID']][] = $booking['Slot'];
$paymentMethods = $pdo->query('SELECT MethodName FROM paymentmethods WHERE IsActive=1 ORDER BY DisplayOrder,MethodName')->fetchAll();
$doctorNameById = array_column($doctors, 'DoctorName', 'DoctorID');

// --- 10B. Detail view (?view=<PatientID>) -------------------------------
$viewId            = isset($_GET['view']) && ctype_digit((string) $_GET['view']) ? (int) $_GET['view'] : 0;
$viewPatient       = null;
$bookId            = isset($_GET['book']) && ctype_digit((string) $_GET['book']) ? (int) $_GET['book'] : 0;
$bookPatient       = null;
$bookVisit         = null;
$viewLabBills      = [];
$viewPharmacyBills = [];
$viewVisits        = [];
$viewPayments      = [];

if ($viewId > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*, d.DoctorName FROM patients p LEFT JOIN doctors d ON d.DoctorID = p.AllocatedDoctor
         WHERE p.PatientID = :id'
    );
    $stmt->execute(['id' => $viewId]);
    $viewPatient = $stmt->fetch() ?: null;

    if ($viewPatient !== null) {
        $stmt = $pdo->prepare('SELECT v.*,d.DoctorName FROM visits v JOIN doctors d ON d.DoctorID=v.DoctorID WHERE v.PatientID=:id ORDER BY v.VisitDate DESC');
        $stmt->execute(['id'=>$viewId]);
        $viewVisits = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM laboratory WHERE PatientID = :id ORDER BY OrderDate DESC');
        $stmt->execute(['id' => $viewId]);
        $viewLabBills = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT SUBSTRING_INDEX(PrescriptionID, '-', 1) AS BillRef, MIN(DoctorID) AS DoctorID,
                    COUNT(*) AS LineCount, MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                    MIN(DueBalance) AS DueBalance, MIN(PrescriptionDate) AS PrescriptionDate
             FROM prescriptions WHERE PatientID = :id
             GROUP BY BillRef ORDER BY PrescriptionDate DESC"
        );
        $stmt->execute(['id' => $viewId]);
        $viewPharmacyBills = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT PaymentReference,PaymentType,Amount,PaymentMethod,PaymentStatus,PaidAt FROM payments WHERE PatientID=:id ORDER BY PaidAt DESC');
        $stmt->execute(['id' => $viewId]);
        $viewPayments = $stmt->fetchAll();
    } else {
        $viewId = 0; // Unknown / stale id — fall back to the list view.
    }
}

if ($bookId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM patients WHERE PatientID = :id');
    $stmt->execute(['id' => $bookId]);
    $bookPatient = $stmt->fetch() ?: null;
    if ($bookPatient) {
        $stmt = $pdo->prepare("SELECT v.*, (SELECT PaymentMethod FROM payments p WHERE p.VisitID=v.VisitID AND p.PaymentStatus='Confirmed' ORDER BY p.PaidAt DESC LIMIT 1) AS AppointmentPaymentMethod FROM visits v WHERE v.PatientID=? AND v.QueueStatus IN ('Pending Payment','Waiting','In Consultation') ORDER BY v.VisitDate DESC LIMIT 1");
        $stmt->execute([$bookId]);
        $bookVisit = $stmt->fetch() ?: null;
    }
}

// --- 10C. List view -------------------------------------------------------
$patients      = [];
$patientSearch = '';
$doctorFilter  = 0;
$patientTypeFilter = '';
$patientDateFilter = '';

if ($viewId === 0) {
    $patientSearch = trim((string) ($_GET['q'] ?? ''));
    $doctorFilter  = isset($_GET['doctor']) && ctype_digit((string) $_GET['doctor']) ? (int) $_GET['doctor'] : 0;
    $requestedType = (string) ($_GET['type'] ?? '');
    $patientTypeFilter = array_key_exists($requestedType, PATIENT_TYPE_OPTIONS) ? $requestedType : '';
    $requestedDate = trim((string) ($_GET['registered'] ?? ''));
    $patientDateFilter = $requestedDate !== '' && tdc_is_valid_date($requestedDate) ? $requestedDate : '';

    $conditions = [];
    $params     = [];

    if ($patientSearch !== '') {
        $conditions[] = '(p.PatientName LIKE :q1 OR p.PatientPhone LIKE :q2)';
        $params['q1'] = $params['q2']  = '%' . $patientSearch . '%';
    }
    if ($doctorFilter > 0) {
        $conditions[]       = 'p.AllocatedDoctor = :doctor';
        $params['doctor']   = $doctorFilter;
    }
    if ($patientTypeFilter !== '') {
        $conditions[] = 'p.PatientType = :patient_type';
        $params['patient_type'] = $patientTypeFilter;
    }
    if ($patientDateFilter !== '') {
        $conditions[] = 'DATE(p.RegisteredAt) = :registered';
        $params['registered'] = $patientDateFilter;
    }

    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt  = $pdo->prepare(
        "SELECT p.*, d.DoctorName,
                (SELECT v.VisitID FROM visits v WHERE v.PatientID=p.PatientID AND v.QueueStatus IN ('Pending Payment','Waiting','In Consultation') ORDER BY v.VisitDate DESC LIMIT 1) AS ActiveVisitID
         FROM patients p LEFT JOIN doctors d ON d.DoctorID = p.AllocatedDoctor
         {$where} ORDER BY p.RegisteredAt DESC LIMIT 200"
    );
    $stmt->execute($params);
    $patients = $stmt->fetchAll();
}

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'patients.php'));

$patientStatus = (string) ($_GET['status'] ?? '');
$justSaved   = $patientStatus === 'saved' || isset($_GET['success']);
$justDeleted = $patientStatus === 'deleted' || isset($_GET['deleted']);
$justVisited = isset($_GET['visited']);
$patientToast = match ($patientStatus) {
    'booked' => 'Appointment booked successfully.',
    'updated_appointment' => 'Appointment updated successfully. Existing payment and financial records were preserved.',
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





    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1200px; margin-bottom:16px; flex-wrap:wrap; }
    .filter-box{ display:flex; gap:8px; flex-wrap:wrap; }
    .filter-box input, .filter-box select{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); background:var(--white); }
    .filter-box input{ min-width:220px; }
    .filter-box input:focus, .filter-box select:focus{ outline:none; border-color:var(--orange); }






    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    .status-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); white-space:nowrap; }
    .status-badge.warn{ border-color:var(--orange); color:var(--orange); }
    .status-badge.danger{ border-color:#c0392b; color:#c0392b; }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .row-actions form{ display:inline; }




    /* --- Patient detail view ------------------------------------------ */
    .info-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:18px 24px; max-width:1200px; margin-bottom:32px; padding:24px; border:2px solid var(--navy); }
    .info-field .info-label{ font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy-55); margin-bottom:4px; }
    .info-field .info-value{ font-size:14.5px; font-weight:600; color:var(--navy); word-break:break-word; }
    .subsection-title{ font-size:16px; font-weight:700; color:var(--navy); margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; max-width:1200px; }


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

<?php if (is_array($importResult)): ?>
<div class="setup-notice" role="status">Import completed. Created: <?= (int) ($importResult['created'] ?? 0) ?>. Updated: <?= (int) ($importResult['updated'] ?? 0) ?>. Skipped: <?= count((array) ($importResult['skipped'] ?? [])) ?>. Failed: <?= count((array) ($importResult['failed'] ?? [])) ?>.</div>
<?php endif; ?>

<?php // ====================================================================
      // DETAIL VIEW — single patient record
      // ==================================================================== ?>
<?php if ($viewId > 0 && $viewPatient !== null): ?>

    <a href="patients.php" class="back-link">&larr; Back to Patients</a>
    <div class="welcome-eyebrow">Patient Record</div>
    <div class="welcome-title"><?= tdc_e($viewPatient['PatientName']) ?></div>
    <div class="welcome-sub">Patient #<?= (int) $viewPatient['PatientID'] ?> &middot; Registered <?= tdc_e(date('Y-m-d', strtotime((string) $viewPatient['RegisteredAt']))) ?></div>

    <div class="info-grid">
        <div class="info-field"><div class="info-label">Phone</div><div class="info-value"><?= tdc_e($viewPatient['PatientPhone'] ?: '—') ?></div></div>
        <div class="info-field"><div class="info-label">Gender / Age</div><div class="info-value"><?= tdc_e(($viewPatient['Gender'] ?: '—') . ' / ' . ($viewPatient['Age'] !== null ? $viewPatient['Age'] : '—')) ?></div></div>
        <div class="info-field"><div class="info-label">Date of Birth</div><div class="info-value"><?= tdc_e($viewPatient['DateOfBirth'] ? date('Y-m-d', strtotime((string) $viewPatient['DateOfBirth'])) : '—') ?></div></div>
        <div class="info-field"><div class="info-label">Patient Type</div><div class="info-value"><?= tdc_e($viewPatient['PatientType'] ?: '—') ?></div></div>
        <div class="info-field"><div class="info-label">Allocated Doctor</div><div class="info-value"><?= tdc_e($viewPatient['DoctorName'] ?: 'Unassigned') ?></div></div>
        <div class="info-field"><div class="info-label">Visit Number</div><div class="info-value"><?= (int) $viewPatient['VisitNumber'] ?></div></div>
        <div class="info-field"><div class="info-label">Due Balance</div><div class="info-value"><?= number_format((float) $viewPatient['DueBalance'], 2) ?></div></div>
        <div class="info-field" style="grid-column:1/-1;"><div class="info-label">Address</div><div class="info-value"><?= tdc_e($viewPatient['PatientAddress'] ?: '—') ?></div></div>
        <?php if (!empty($viewPatient['Remark'])): ?>
        <div class="info-field" style="grid-column:1/-1;"><div class="info-label">Remark</div><div class="info-value"><?= tdc_e($viewPatient['Remark']) ?></div></div>
        <?php endif; ?>
    </div>

    <?php if ($canEdit || $canCreateVisit): ?>
    <div class="section-toolbar" style="margin-top:-20px;">
        <div></div>
        <div class="row-actions">
            <?php if($canEdit): ?><button type="button" class="btn btn-secondary" id="editFromViewBtn"
                data-id="<?= (int) $viewPatient['PatientID'] ?>"
                data-name="<?= tdc_e($viewPatient['PatientName']) ?>"
                data-phone="<?= tdc_e((string) $viewPatient['PatientPhone']) ?>"
                data-address="<?= tdc_e((string) $viewPatient['PatientAddress']) ?>"
                data-gender="<?= tdc_e((string) $viewPatient['Gender']) ?>"
                data-age="<?= tdc_e((string) $viewPatient['Age']) ?>"
                data-dob="<?= tdc_e((string) $viewPatient['DateOfBirth']) ?>"
                data-type="<?= tdc_e((string) $viewPatient['PatientType']) ?>"
                data-doctor="<?= tdc_e((string) $viewPatient['AllocatedDoctor']) ?>"
                data-remark="<?= tdc_e((string) $viewPatient['Remark']) ?>">Edit Patient</button><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="subsection-title"><span>Consultation History</span></div>
    <div class="data-table-wrap" style="margin-bottom:32px"><table class="data-table"><thead><tr><th>Visit</th><th>Doctor</th><th>Date</th><th>Payment</th><th>Status</th><th>Diagnosis</th><th>Treatment / Follow-up</th></tr></thead><tbody>
    <?php if(!$viewVisits): ?><tr class="empty-row"><td colspan="7">No consultation history for this patient.</td></tr><?php else:foreach($viewVisits as $visit): ?><tr><td><?= tdc_e($visit['VisitReference']) ?></td><td><?= tdc_e($visit['DoctorName']) ?></td><td><?= tdc_e(date('d M Y H:i',strtotime($visit['VisitDate']))) ?></td><td><span class="status-badge<?= $visit['PaymentStatus']==='Unpaid'?' danger':($visit['PaymentStatus']==='Partial'?' warn':'') ?>"><?= tdc_e($visit['PaymentStatus']) ?></span></td><td><span class="status-badge"><?= tdc_e($visit['QueueStatus']) ?></span></td><td><?= nl2br(tdc_e($visit['Diagnosis'] ?: '—')) ?></td><td><?= nl2br(tdc_e(trim(($visit['TreatmentPlan'] ?: '')."\n".($visit['FollowUpPlan'] ?: '')) ?: '—')) ?></td></tr><?php endforeach;endif; ?>
    </tbody></table></div>

    <div class="subsection-title">
        <span>Laboratory History</span>
        <a href="reception.php?section=laboratory" class="btn-sm">Manage in Reception</a>
    </div>
    <div class="data-table-wrap" style="margin-bottom:32px;">
        <table class="data-table">
            <thead><tr><th>Bill ID</th><th>Test</th><th>Price</th><th>Result</th><th>Clinical Findings</th><th>Payment</th><th>Ordered</th></tr></thead>
            <tbody>
                <?php if (empty($viewLabBills)): ?>
                <tr class="empty-row"><td colspan="7">No laboratory bills on record for this patient.</td></tr>
                <?php else: foreach ($viewLabBills as $l): ?>
                <tr>
                    <td><?= tdc_e($l['LaboratoryID']) ?></td>
                    <td><?= tdc_e($l['TestName']) ?></td>
                    <td><?= number_format((float) $l['TotalAmount'], 2) ?></td>
                    <td><span class="status-badge<?= $l['Result'] === 'Positive' ? ' danger' : ($l['Result'] === 'Pending' ? ' warn' : '') ?>"><?= tdc_e($l['Result']) ?></span></td>
                    <td><?= nl2br(tdc_e($l['ClinicalResult'] ?: '—')) ?></td>
                    <td><span class="status-badge<?= $l['PaymentStatus'] === 'Unpaid' ? ' danger' : ($l['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($l['PaymentStatus']) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $l['OrderDate']))) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="subsection-title"><span>Payment History</span></div>
    <div class="data-table-wrap" style="margin-top:0">
        <table class="data-table"><thead><tr><th>Reference</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php if(!$viewPayments): ?><tr class="empty-row"><td colspan="6">No payments recorded for this patient.</td></tr><?php else:foreach($viewPayments as $payment): ?><tr><td><?= tdc_e($payment['PaymentReference']) ?></td><td><?= tdc_e($payment['PaymentType']) ?></td><td><?= number_format((float)$payment['Amount'],2) ?></td><td><?= tdc_e($payment['PaymentMethod']) ?></td><td><span class="status-badge<?= $payment['PaymentStatus']==='Voided'?' danger':'' ?>"><?= tdc_e($payment['PaymentStatus']) ?></span></td><td><?= tdc_e(date('d M Y H:i',strtotime($payment['PaidAt']))) ?></td></tr><?php endforeach;endif; ?>
        </tbody></table>
    </div>

    <div class="subsection-title">
        <span>Pharmacy History</span>
        <a href="reception.php?section=pharmacy" class="btn-sm">Manage in Reception</a>
    </div>
    <div class="data-table-wrap">
        <table class="data-table">
            <thead><tr><th>Bill Ref</th><th>Doctor</th><th>Items</th><th>Total</th><th>Paid</th><th>Due</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($viewPharmacyBills)): ?>
                <tr class="empty-row"><td colspan="8">No pharmacy bills on record for this patient.</td></tr>
                <?php else: foreach ($viewPharmacyBills as $b): ?>
                <tr>
                    <td><?= tdc_e($b['BillRef']) ?></td>
                    <td><?= tdc_e($doctorNameById[$b['DoctorID']] ?? 'Unknown') ?></td>
                    <td><?= (int) $b['LineCount'] ?></td>
                    <td><?= number_format((float) $b['TotalAmount'], 2) ?></td>
                    <td><?= number_format((float) $b['AmountPaid'], 2) ?></td>
                    <td><span class="status-badge<?= (float) $b['DueBalance'] > 0 ? ' danger' : '' ?>"><?= number_format((float) $b['DueBalance'], 2) ?></span></td>
                    <td><?= tdc_e(date('Y-m-d', strtotime((string) $b['PrescriptionDate']))) ?></td>
                    <td>
                        <div class="row-actions">
                            <a href="reception.php?section=pharmacy&edit=<?= urlencode($b['BillRef']) ?>" class="btn-secondary btn-sm"><?= tdc_icon('pencil',16) ?><span>Edit</span></a>
                            <a href="../print_prescription.php?ref=<?= urlencode($b['BillRef']) ?>" class="btn-primary btn-sm" target="_blank" rel="noopener"><?= tdc_icon('printer',16) ?><span>Print</span></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <?php // ================================================================
          // LIST VIEW — search, filter, table
          // ================================================================ ?>

    <div class="welcome-eyebrow">Records</div>
    <div class="welcome-title">Patients</div>
    <div class="welcome-sub">Search patient records and review visit, laboratory, and pharmacy history.</div>

    <div class="section-toolbar">
        <form method="GET" action="patients.php" class="filter-box">
            <input type="text" name="q" placeholder="Search by name or phone..." value="<?= tdc_e($patientSearch) ?>">
            <select name="type" aria-label="Patient type">
                <option value="">All Types</option>
                <?php foreach (PATIENT_TYPE_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>" <?= $patientTypeFilter === $v ? 'selected' : '' ?>><?= tdc_e($l) ?></option><?php endforeach; ?>
            </select>
            <select name="doctor" aria-label="Allocated doctor">
                <option value="0">All Doctors</option>
                <?php foreach ($doctors as $d): ?>
                    <option value="<?= (int) $d['DoctorID'] ?>" <?= $doctorFilter === (int) $d['DoctorID'] ? 'selected' : '' ?>><?= tdc_e($d['DoctorName']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="registered" value="<?= tdc_e($patientDateFilter) ?>" aria-label="Registration date">
            <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Filter</span></button>
            <?php if ($patientSearch !== '' || $patientTypeFilter !== '' || $doctorFilter > 0 || $patientDateFilter !== ''): ?><a href="patients.php" class="btn btn-secondary clear-filters">Clear</a><?php endif; ?>
        </form>
        <div class="table-command-bar"><?php if($canImport):?><button type="button" id="importPatientBtn" class="btn-success btn "><?= tdc_icon('upload',16) ?><span>Import CSV / XLSX</span></button><a class="btn-secondary btn " href="patients.php?download=patient-template"><?= tdc_icon('download',16) ?><span>Download CSV Template</span></a><?php endif;?><?php if($canExport):?><a class="btn-secondary btn " href="patients.php?download=patients"><?= tdc_icon('download',16) ?><span>Export CSV</span></a><a class="btn-secondary btn " href="patients.php?download=patients-xlsx"><?= tdc_icon('download',16) ?><span>Export XLSX</span></a><button type="button" class="btn-primary btn " onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print / Save PDF</span></button><?php endif;?><?php if ($canCreate): ?><button type="button" id="addPatientBtn" class="btn-success btn "><?= tdc_icon('plus',16) ?><span>Register Patient</span></button><?php endif; ?></div>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Phone</th><th>Gender / Age</th><th>Type</th>
                    <th>Visit #</th><th>Doctor</th><th>Due Balance</th><th>Registered</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($patients)): ?>
                <tr class="empty-row"><td colspan="10">No patients found<?= $patientSearch !== '' ? ' for "' . tdc_e($patientSearch) . '"' : '' ?>.</td></tr>
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
                            <a href="patients.php?view=<?= (int) $p['PatientID'] ?>" class="btn-sm">View</a>
                            <?php if ($canCreateVisit): ?><a href="patients.php?book=<?= (int) $p['PatientID'] ?>" class="btn-primary btn-sm"><?= !empty($p['ActiveVisitID']) ? 'Edit Appointment' : 'Book' ?></a><?php endif; ?>
                            <?php if ($canEdit): ?>
                            <button type="button" class="btn-secondary btn-sm edit-patient-btn"
                                data-id="<?= (int) $p['PatientID'] ?>"
                                data-name="<?= tdc_e($p['PatientName']) ?>"
                                data-phone="<?= tdc_e((string) $p['PatientPhone']) ?>"
                                data-address="<?= tdc_e((string) $p['PatientAddress']) ?>"
                                data-gender="<?= tdc_e((string) $p['Gender']) ?>"
                                data-age="<?= tdc_e((string) $p['Age']) ?>"
                                data-dob="<?= tdc_e((string) $p['DateOfBirth']) ?>"
                                data-type="<?= tdc_e((string) $p['PatientType']) ?>"
                                data-doctor="<?= tdc_e((string) $p['AllocatedDoctor']) ?>"
                                data-remark="<?= tdc_e((string) $p['Remark']) ?>"><?= tdc_icon('pencil',16) ?><span>Edit</span></button>
                            <?php endif; ?>
                            <?php if($canDelete): ?><form method="POST" action="patients.php" data-confirm="Permanently delete this patient and clear all linked visits, laboratory results, prescriptions, pharmacy sales, payments, and ledger history? This cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="PatientID" value="<?= (int) $p['PatientID'] ?>">
                                <button type="submit" class="btn-danger btn-sm danger">Delete</button>
                            </form><?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php // ====================================================================
      // Add / Edit Patient modal — shared by list view and detail view
      // ==================================================================== ?>
<?php if ($canManage): ?>
<div class="modal-overlay" id="patientModalOverlay">
    <div class="modal-box patient-modal">
        <div class="modal-head">
            <h3 id="patientModalTitle">Register Patient</h3>
            <button type="button" class="modal-close" id="patientModalCloseBtn" aria-label="Close">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <form id="patientForm" method="POST" action="patients.php">
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
                            <div class="form-group"><label for="pf_Gender">Gender *</label>
                                <select id="pf_Gender" name="Gender" required>
                                    <option value="">Select gender</option>
                                    <?php foreach (GENDER_OPTIONS as $v => $l): ?><option value="<?= tdc_e($v) ?>"><?= tdc_e($l) ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="form-group"><label for="pf_DateOfBirth">Date of Birth *</label>
                                <input type="date" id="pf_DateOfBirth" name="DateOfBirth" required></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group patient-age-group"><label>Age *</label>
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
                            <div class="form-group"><label for="pf_PatientType">Patient Type *</label>
                                <select id="pf_PatientType" name="PatientType" required>
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
                        <input type="hidden" name="AppointmentVisitID" id="pf_AppointmentVisitID" value="">
                        <div id="appointmentValidationMessage" class="form-error" hidden role="alert"></div>
                        <div id="appointmentIntakeFields" hidden style="margin-top:16px">
                            <div class="form-section-heading"><span class="form-section-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg></span><span><strong>Appointment details</strong><span>Optional: create the appointment together with this new patient.</span></span></div>
                            <div class="form-row">
                                <div class="form-group"><label for="pf_AppointmentDoctorID">Doctor *</label><select id="pf_AppointmentDoctorID" name="AppointmentDoctorID"><option value="">Choose a linked doctor</option><?php foreach ($doctors as $d): if (empty($d['UserID'])) continue; ?><option value="<?= (int) $d['DoctorID'] ?>" data-fee="<?= tdc_e((string) $d['ConsultationFee']) ?>" data-days="<?= tdc_e((string) $d['WorkingDays']) ?>" data-start="<?= tdc_e(substr((string) $d['WorkStartTime'],0,5)) ?>" data-end="<?= tdc_e(substr((string) $d['WorkEndTime'],0,5)) ?>" data-booked="<?= tdc_e(json_encode($appointmentBookingsByDoctor[(int) $d['DoctorID']] ?? [])) ?>"><?= tdc_e($d['DoctorName']) ?> — <?= number_format((float) $d['ConsultationFee'],2) ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label for="pf_AppointmentDate">Date and time *</label><input type="datetime-local" id="pf_AppointmentDate" name="AppointmentDate" list="appointmentDateOptions" step="1800"><datalist id="appointmentDateOptions"></datalist><div id="appointmentAvailabilityHint" class="field-hint" role="status">Choose a doctor to see available days and hours.</div><div id="appointmentAvailabilityCalendar" class="appointment-availability-calendar" role="group" aria-label="Available appointment dates"></div></div>
                            </div>
                            <div class="form-row">
                                <div class="form-group"><label for="pf_AppointmentAmountPaid">Amount paid</label><input type="number" id="pf_AppointmentAmountPaid" name="AppointmentAmountPaid" min="0" step="0.01" value="0"></div><div class="form-group"><label for="pf_AppointmentDiscountType">Discount type</label><select id="pf_AppointmentDiscountType" name="AppointmentDiscountType"><option value="None">No discount</option><option value="Fixed">Fixed amount</option><option value="Percentage">Percentage</option></select></div><div class="form-group"><label for="pf_AppointmentDiscountValue">Discount value</label><input type="number" id="pf_AppointmentDiscountValue" name="AppointmentDiscountValue" min="0" step="0.01" value="0" placeholder="Optional discount"></div><div class="form-group"><label for="pf_AppointmentTaxRate">Tax rate (%)</label><input type="number" id="pf_AppointmentTaxRate" name="AppointmentTaxRate" min="0" max="100" step="0.01" value="0" placeholder="Optional tax"></div><div class="form-group"><label for="pf_AppointmentDiscountReason">Discount reason</label><input id="pf_AppointmentDiscountReason" name="AppointmentDiscountReason" placeholder="Required with discount"></div>
                                <div class="form-group"><label for="pf_AppointmentPaymentMethod">Payment method</label><select id="pf_AppointmentPaymentMethod" name="AppointmentPaymentMethod"><option value="">Select when receiving payment</option><?php foreach ($paymentMethods as $method): ?><option value="<?= tdc_e($method['MethodName']) ?>"><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select></div>
                            </div>
                            <div class="form-row appointment-summary" aria-live="polite"><div><span>Consultation fee</span><strong id="appointmentFeeDisplay">0.00</strong></div><div><span>Final amount</span><strong id="appointmentFinalDisplay">0.00</strong></div><div><span>Due</span><strong id="appointmentDueDisplay">0.00</strong></div><div><span>Status</span><strong id="appointmentStatusDisplay">Unpaid</strong></div></div>
                            <label class="free-consultation-toggle"><input type="checkbox" name="AppointmentFreeConsultation" value="1" id="pf_AppointmentFreeConsultation" <?= !empty($old['AppointmentFreeConsultation']) ? 'checked' : '' ?>> <span><strong>Free Consultation</strong><small>Waive the consultation fee for this visit.</small></span></label>
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
<?php endif; ?>

<?php if($canImport):?><div class="modal-overlay" id="importPatientModal"><div class="modal-box"><div class="modal-head"><h3><?= tdc_icon('upload',20) ?><span>Import Patients</span></h3><button type="button" class="modal-close" data-close-import aria-label="Close">×</button></div><form method="post" enctype="multipart/form-data"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="import_csv"><div class="form-section"><div class="form-section-heading"><span><strong>CSV or XLSX File</strong><span>Use the required patient template. The import is transactional.</span></span></div><div class="form-group"><label>Select CSV or XLSX</label><input type="file" name="csv_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><p class="form-hint">Required column: patient_name. CSV and XLSX files use the same patient columns and validation rules; the first worksheet is imported.</p></div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-import>Cancel</button><button class="btn-success btn "><?= tdc_icon('upload',16) ?><span>Import Patients</span></button></div></div></form></div></div><?php endif;?>

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

<?php if ($canManage): ?>
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
    const fAppointmentVisitId = document.getElementById('pf_AppointmentVisitID');
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
    const appointmentFinalDisplay = document.getElementById('appointmentFinalDisplay');
    const saveLabel = document.getElementById('patientSaveLabel');
    const appointmentValidationMessage = document.getElementById('appointmentValidationMessage');
    const appointmentAvailabilityHint = document.getElementById('appointmentAvailabilityHint');
    const appointmentAvailabilityCalendar = document.getElementById('appointmentAvailabilityCalendar');
    const appointmentDateOptions = document.getElementById('appointmentDateOptions');
    const appointmentFeeDisplay = document.getElementById('appointmentFeeDisplay');
    const appointmentDueDisplay = document.getElementById('appointmentDueDisplay');
    const appointmentStatusDisplay = document.getElementById('appointmentStatusDisplay');
    let appointmentOnly = false;
    const existingAppointmentSlot = <?= json_encode($bookVisit ? date('Y-m-d\\TH:i', strtotime((string) $bookVisit['VisitDate'])) : '') ?>;
    const existingAppointmentDoctor = <?= json_encode((string) ($bookVisit['DoctorID'] ?? '')) ?>;

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
        const calendar = window.TdcAppointmentCalendar(fAppointmentDate, fAppointmentDoctor, appointmentAvailabilityCalendar, appointmentAvailabilityHint, <?= TDC_APPOINTMENT_MINUTES ?>, existingAppointmentSlot, existingAppointmentDoctor);
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

    function fillFromDataset(ds){
        fId.value = ds.id;
        fName.value = ds.name;
        fPhone.value = ds.phone;
        fAddress.value = ds.address;
        fGender.value = ds.gender;
        fAge.value = ds.age;
        fDob.value = ds.dob || '';
        fAgeYears.value = ds.age || '';
        syncAgeDisplay();
        fType.value = ds.type;
        fDoctor.value = ds.doctor;
        fRemark.value = ds.remark;
    }

    const addBtn = document.getElementById('addPatientBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function(){
            appointmentOnly = false;
            form.reset();
            if (fAppointmentOnly) fAppointmentOnly.value = '';
            fId.value = '';
            fAppointmentPaid.value = '0';
            fAge.value = '';
            fDob.value = '';
            fAgeYears.value = '';
            syncAgeDisplay();
            modalTitle.textContent = 'Register Patient';
            openModal();
        });
    }

    document.querySelectorAll('.edit-patient-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            appointmentOnly = false;
            form.reset();
            if (fAppointmentOnly) fAppointmentOnly.value = '';
            fillFromDataset(btn.dataset);
            modalTitle.textContent = 'Edit Patient';
            openModal();
        });
    });

    const editFromViewBtn = document.getElementById('editFromViewBtn');
    if (editFromViewBtn) {
        editFromViewBtn.addEventListener('click', function(){
            appointmentOnly = false;
            form.reset();
            if (fAppointmentOnly) fAppointmentOnly.value = '';
            fillFromDataset(editFromViewBtn.dataset);
            modalTitle.textContent = 'Edit Patient';
            openModal();
        });
    }

    <?php if ($bookPatient !== null && $canCreateVisit): ?>
    appointmentOnly = true;
    form.reset();
    if (fAppointmentOnly) fAppointmentOnly.value = '1';
    if (fAppointmentVisitId) fAppointmentVisitId.value = <?= json_encode((string) ($bookVisit['VisitID'] ?? '')) ?>;
    if (fAppointmentVisitId?.value) {
        fAppointmentPaid.readOnly = true;
        fAppointmentMethod.disabled = true;
    }
    fillFromDataset(<?= json_encode([
        'id' => (string) $bookPatient['PatientID'],
        'name' => (string) $bookPatient['PatientName'],
        'phone' => (string) ($bookPatient['PatientPhone'] ?? ''),
        'address' => (string) ($bookPatient['PatientAddress'] ?? ''),
        'gender' => (string) ($bookPatient['Gender'] ?? ''),
        'age' => (string) ($bookPatient['Age'] ?? ''),
        'dob' => (string) ($bookPatient['DateOfBirth'] ?? ''),
        'type' => (string) ($bookPatient['PatientType'] ?? ''),
        'doctor' => (string) ($bookPatient['AllocatedDoctor'] ?? ''),
        'remark' => (string) ($bookPatient['Remark'] ?? ''),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    fAppointmentDoctor.value = <?= json_encode((string) ($bookPatient['AllocatedDoctor'] ?? '')) ?>;
    fAppointmentDate.value = <?= json_encode($bookVisit ? date('Y-m-d\\TH:i', strtotime((string) $bookVisit['VisitDate'])) : '') ?>;
    fAppointmentReason.value = <?= json_encode((string) ($bookVisit['ChiefComplaint'] ?? '')) ?>;
    fAppointmentPaid.value = <?= json_encode((string) ($bookVisit['AmountPaid'] ?? '0')) ?>;
    fAppointmentMethod.value = <?= json_encode((string) ($bookVisit['AppointmentPaymentMethod'] ?? '')) ?>;
    if (!fAppointmentVisitId?.value) fAppointmentPaid.value = '0';
    modalTitle.textContent = 'Book Appointment — ' + fName.value;
    if (saveLabel) saveLabel.textContent = 'Book Appointment';
    openModal();
    setAppointmentOpen(true);
    updateAvailabilityHint();
    <?php endif; ?>

    document.getElementById('patientModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('patientModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') === 'save'): ?>
    fId.value = <?= json_encode($old['PatientID']) ?>;
    fName.value = <?= json_encode($old['PatientName']) ?>;
    fPhone.value = <?= json_encode($old['PatientPhone']) ?>;
    fAddress.value = <?= json_encode($old['PatientAddress']) ?>;
    fGender.value = <?= json_encode($old['Gender']) ?>;
    fAge.value = <?= json_encode($old['Age']) ?>;
    fDob.value = <?= json_encode($old['DateOfBirth']) ?>;
    fAgeYears.value = <?= json_encode($old['Age']) ?>;
    syncAgeDisplay();
    fType.value = <?= json_encode($old['PatientType']) ?>;
    fDoctor.value = <?= json_encode($old['AllocatedDoctor']) ?>;
    fRemark.value = <?= json_encode($old['Remark']) ?>;
    fAppointmentDoctor.value = <?= json_encode($old['AppointmentDoctorID']) ?>;
    fAppointmentDate.value = <?= json_encode($old['AppointmentDate']) ?>;
    fAppointmentFree.checked = <?= json_encode(!empty($old['AppointmentFreeConsultation'])) ?>;
    fAppointmentDiscountType.value = <?= json_encode($old['AppointmentDiscountType']) ?>;
    fAppointmentDiscountValue.value = <?= json_encode($old['AppointmentDiscountValue']) ?>;
    fAppointmentDiscountReason.value = <?= json_encode($old['AppointmentDiscountReason']) ?>;
    fAppointmentTaxRate.value = <?= json_encode($old['AppointmentTaxRate']) ?>;
    fAppointmentReason.value = <?= json_encode($old['AppointmentReason']) ?>;
    fAppointmentPaid.value = <?= json_encode($old['AppointmentAmountPaid']) ?>;
    fAppointmentMethod.value = <?= json_encode($old['AppointmentPaymentMethod']) ?>;
    modalTitle.textContent = fId.value ? 'Edit Patient' : 'Register Patient';
    openModal();
    setAppointmentOpen(<?= json_encode($old['BookAppointment'] === '1') ?>);
    if (<?= json_encode($old['BookAppointment'] === '1') ?>) updateAvailabilityHint();
    if (appointmentValidationMessage) { appointmentValidationMessage.textContent = <?= json_encode(implode(' ', $errors)) ?>; appointmentValidationMessage.hidden = false; }
    <?php endif; ?>

    <?php if ($hasPatientToast): ?>
    showToast(<?= json_encode($patientToast) ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'patients.php'); }
    <?php endif; ?>
})();
<?php endif; ?>
</script>
<?php if($canImport):?><script>(()=>{const modal=document.getElementById('importPatientModal'),open=document.getElementById('importPatientBtn');const close=()=>modal?.classList.remove('show');open?.addEventListener('click',()=>modal?.classList.add('show'));document.querySelectorAll('[data-close-import]').forEach(button=>button.addEventListener('click',close));modal?.addEventListener('click',event=>{if(event.target===modal)close()});})();</script><?php endif;?>

</body>
</html>
