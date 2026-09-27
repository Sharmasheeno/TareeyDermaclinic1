<?php
declare(strict_types=1);

/**
 * auth/pages/doctors.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Doctors Directory
 * ---------------------------------------------------------------------
 * Manages the Doctors table (name, specialty, consultation fee, join
 * date). Doctors are referenced elsewhere by Patients.AllocatedDoctor
 * and Prescriptions.DoctorID, so deletion is blocked while dependents
 * exist (same app-level referential guard used for Patients).
 *
 * Security controls (same posture as home.php / reception.php):
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
 * Schema alignment (tareydermaclinic.Doctors):
 *   DoctorID         INT AUTO_INCREMENT PK
 *   DoctorName       VARCHAR(150) NOT NULL
 *   ConsultationFee  DECIMAL(10,2) DEFAULT 0.00
 *   Specialty        VARCHAR(100)
 *   JoinedDate       DATE
 * ---------------------------------------------------------------------
 */
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
const ALLOWED_MANAGE_ROLES = ['superuser'];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / settings.php. Flat, single-link items only.
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
    $statusFlags = ['created', 'updated', 'deleted', 'imported'];
    $query = in_array($flag, $statusFlags, true) ? 'status=' . urlencode($flag) : $flag . '=1';
    header('Location: doctors.php?' . $query);
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

/** @param array{DoctorName:string,ConsultationFee:string,Specialty:string,JoinedDate:string,WorkingDays:string,WorkStartTime:string,WorkEndTime:string} $input */
function tdc_validate_doctor_form(array $input): array
{
    $errors = [];

    if ($input['DoctorName'] === '' || mb_strlen($input['DoctorName']) < 2 || mb_strlen($input['DoctorName']) > 150) {
        $errors[] = 'Doctor name is required (2-150 characters).';
    }
    if ($input['Specialty'] !== '' && mb_strlen($input['Specialty']) > 100) {
        $errors[] = 'Specialty must be 100 characters or fewer.';
    }
    if ($input['ConsultationFee'] !== '' && (!is_numeric($input['ConsultationFee']) || (float) $input['ConsultationFee'] < 0)) {
        $errors[] = 'Consultation fee must be a valid non-negative number.';
    }
    if ($input['JoinedDate'] !== '' && !tdc_is_valid_date($input['JoinedDate'])) {
        $errors[] = 'Joined date is not a valid date.';
    }
    $days = array_values(array_filter(explode(',', $input['WorkingDays']), static fn(string $day): bool => in_array($day, ['1','2','3','4','5','6','7'], true)));
    if (!$days) $errors[] = 'Select at least one working day.';
    foreach (['WorkStartTime', 'WorkEndTime'] as $timeField) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input[$timeField])) $errors[] = $timeField === 'WorkStartTime' ? 'Enter a valid work start time.' : 'Enter a valid work end time.';
    }
    if (!$errors && $input['WorkStartTime'] >= $input['WorkEndTime']) $errors[] = 'Work end time must be later than work start time.';

    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence
// =======================================================================

function tdc_save_doctor(PDO $pdo, array $input, bool $isEdit, int $editId): void
{
    $params = [
        'DoctorName'      => $input['DoctorName'],
        'ConsultationFee' => $input['ConsultationFee'] !== '' ? round((float) $input['ConsultationFee'], 2) : 0.00,
        'Specialty'       => $input['Specialty'] !== '' ? $input['Specialty'] : null,
        'JoinedDate'      => $input['JoinedDate'] !== '' ? $input['JoinedDate'] : null,
        'WorkingDays'     => $input['WorkingDays'],
        'WorkStartTime'   => $input['WorkStartTime'] . ':00',
        'WorkEndTime'     => $input['WorkEndTime'] . ':00',
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE doctors SET DoctorName = :DoctorName, ConsultationFee = :ConsultationFee,
                Specialty = :Specialty, JoinedDate = :JoinedDate, WorkingDays = :WorkingDays,
                WorkStartTime = :WorkStartTime, WorkEndTime = :WorkEndTime
             WHERE DoctorID = :id'
        );
        $stmt->execute($params);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO doctors (DoctorName, ConsultationFee, Specialty, JoinedDate, WorkingDays, WorkStartTime, WorkEndTime)
         VALUES (:DoctorName, :ConsultationFee, :Specialty, :JoinedDate, :WorkingDays, :WorkStartTime, :WorkEndTime)'
    );
    $stmt->execute($params);
}

/**
 * Transactional cascade: the schema has no FK constraints, so linked
 * clinical, pharmacy, payment, and ledger rows are cleared explicitly.
 *
 * @return string[] error messages; empty on success
 */
function tdc_delete_doctor(PDO $pdo, int $id): array
{
    $pdo->beginTransaction();
    try {
        $v = $pdo->prepare('SELECT VisitID, VisitReference FROM visits WHERE DoctorID = ?');
        $v->execute([$id]); $visitRows = $v->fetchAll();
        $visitIds = array_map(static fn($r) => (int) $r['VisitID'], $visitRows);
        $refs = array_map(static fn($r) => (string) $r['VisitReference'], $visitRows);
        $l = $pdo->prepare('SELECT LaboratoryID FROM laboratory WHERE DoctorID = ?');
        $l->execute([$id]); $labIds = array_map('strval', $l->fetchAll(PDO::FETCH_COLUMN));
        $r = $pdo->prepare('SELECT PrescriptionID, PharmacySaleReference FROM prescriptions WHERE DoctorID = ?');
        $r->execute([$id]); $rxRows = $r->fetchAll();
        $rxRefs = array_map(static fn($row) => (string) $row['PrescriptionID'], $rxRows);
        $saleRefs = array_values(array_filter(array_map(static fn($row) => (string) ($row['PharmacySaleReference'] ?? ''), $rxRows)));
        if ($visitIds) { $in = implode(',', array_fill(0, count($visitIds), '?')); $s = $pdo->prepare("SELECT SaleID FROM pharmacysales WHERE VisitID IN ($in)"); $s->execute($visitIds); $saleRefs = array_values(array_unique(array_merge($saleRefs, array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))))); }
        $paymentRefs = [];
        if ($visitIds) { $in = implode(',', array_fill(0, count($visitIds), '?')); $p = $pdo->prepare("SELECT PaymentReference FROM payments WHERE VisitID IN ($in)"); $p->execute($visitIds); $paymentRefs = array_map('strval', $p->fetchAll(PDO::FETCH_COLUMN)); }
        if ($labIds) {
            $in = implode(',', array_fill(0, count($labIds), '?'));
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
            $pdo->prepare("DELETE FROM laboratory WHERE LaboratoryID IN ($in)")->execute($labIds);
        }
        if ($rxRefs) { $in = implode(',', array_fill(0, count($rxRefs), '?')); $pdo->prepare("DELETE FROM prescriptionsheader WHERE PrescriptionID IN ($in)")->execute($rxRefs); $pdo->prepare("DELETE FROM prescriptions WHERE PrescriptionID IN ($in)")->execute($rxRefs); }
        if ($saleRefs) { $in = implode(',', array_fill(0, count($saleRefs), '?')); $pdo->prepare("DELETE FROM pharmacysales WHERE SaleID IN ($in)")->execute($saleRefs); }
        $allRefs = array_values(array_unique(array_merge($refs, $labIds, $rxRefs, $saleRefs, $paymentRefs)));
        if ($allRefs) { $in = implode(',', array_fill(0, count($allRefs), '?')); $pdo->prepare("DELETE FROM accounting WHERE ReferenceID IN ($in)")->execute($allRefs); }
        if ($visitIds) { $in = implode(',', array_fill(0, count($visitIds), '?')); $pdo->prepare("DELETE FROM payments WHERE VisitID IN ($in)")->execute($visitIds); $pdo->prepare("DELETE FROM visits WHERE VisitID IN ($in)")->execute($visitIds); }
        $pdo->prepare('UPDATE patients SET AllocatedDoctor = NULL WHERE AllocatedDoctor = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM doctors WHERE DoctorID = ?')->execute([$id]);
        $pdo->commit();
        // Audit logging must never make an already-completed cleanup fail.
        try {
            if (function_exists('tdc_audit')) {
                tdc_audit($pdo, 'doctors.cascade_deleted', 'Doctors', (string) $id, 'Doctor and linked clinical, pharmacy, payment, and ledger history were permanently cleared.');
            }
        } catch (Throwable $auditError) {
            error_log('[DOCTOR DELETE AUDIT] ' . $auditError->getMessage());
        }
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[DOCTOR DELETE] ' . $e->getMessage());
        return ['The doctor and linked history could not be cleared. No records were changed.'];
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
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/data-transfer.php';
require_once __DIR__ . '/../includes/ui.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$doctorWorkspaceRequested = isset($_GET['visit']) || (string) ($_GET['workspace'] ?? '') === '1';
if (tdc_can('doctor.workspace') && (!tdc_can('doctors.manage') || $doctorWorkspaceRequested)) {
    require __DIR__ . '/../includes/doctor-portal.php';
    exit;
}

$canManage = tdc_can('doctors.manage');
$canWorkspace = tdc_can('doctor.workspace');
$canImport = tdc_can('doctors.import');
$canExport = tdc_can('doctors.export');

if (($_GET['download'] ?? '') === 'doctor-template') {
    tdc_require_permission('doctors.import');
    tdc_csv_download('doctor-import-example.csv',['doctor_name','specialization','consultation_fee','joined_date'],[['Example Doctor','Dermatology','25.00',date('Y-m-d')]]);
}
if (($_GET['download'] ?? '') === 'doctors') {
    tdc_require_permission('doctors.export');$rows=[];foreach($pdo->query('SELECT d.DoctorID,d.DoctorName,d.Specialty,d.ConsultationFee,d.JoinedDate,u.username FROM doctors d LEFT JOIN users u ON u.id=d.UserID ORDER BY d.DoctorID')->fetchAll() as $row)$rows[]=array_values($row);tdc_csv_download('doctors-'.date('Y-m-d').'.csv',['doctor_id','doctor_name','specialization','consultation_fee','joined_date','linked_username'],$rows);
}
if (($_GET['download'] ?? '') === 'doctors-xlsx') {
    tdc_require_permission('doctors.export');
    $rows = [];
    foreach ($pdo->query('SELECT d.DoctorID,d.DoctorName,d.Specialty,d.ConsultationFee,d.JoinedDate,u.username FROM doctors d LEFT JOIN users u ON u.id=d.UserID ORDER BY d.DoctorID')->fetchAll() as $row) {
        $rows[] = array_values($row);
    }
    tdc_xlsx_download('doctors-' . date('Y-m-d') . '.xlsx', ['doctor_id','doctor_name','specialization','consultation_fee','joined_date','linked_username'], $rows);
}

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$errors = [];
$importResult = $_SESSION['tdc_doctor_import_result'] ?? null;
unset($_SESSION['tdc_doctor_import_result']);
$old = [
    'DoctorID'        => '',
    'DoctorName'      => '',
    'ConsultationFee' => '',
    'Specialty'       => '',
    'JoinedDate'      => '',
    'WorkingDays'     => '1,2,3,4,5',
    'WorkStartTime'   => '09:00',
    'WorkEndTime'     => '17:00',
];

// =======================================================================
// SECTION 9 — POST handler (save / delete)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        // Defense in depth: the UI already hides these controls for
        // non-manager roles, but never trust the client alone.
        header('Location: doctors.php');
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        if ($formAction === 'import_csv') {
            tdc_require_permission('doctors.import');
            try {
                $upload = $_FILES['csv_file'] ?? [];
                $uploadExtension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                $uploadReader = $uploadExtension === 'xlsx' ? 'tdc_xlsx_upload_rows' : 'tdc_csv_upload_rows';
                $rows = $uploadReader($upload, ['doctor_name', 'consultation_fee'], [
                    'doctor_id', 'doctor_name', 'specialization', 'consultation_fee', 'joined_date', 'linked_username',
                ], 2000, [
                    'id' => 'doctor_id',
                    'doctor id' => 'doctor_id',
                    'name' => 'doctor_name',
                    'doctor name' => 'doctor_name',
                    'specialty' => 'specialization',
                    'speciality' => 'specialization',
                    'consultation fee' => 'consultation_fee',
                    'joined' => 'joined_date',
                    'joined date' => 'joined_date',
                    'username' => 'linked_username',
                ]);
                if (!$rows) throw new RuntimeException('The uploaded CSV/XLSX file contains no doctor rows.');
                $pdo->beginTransaction();
                $created = 0;
                $updated = 0;
                foreach ($rows as $index => $row) {
                    $rowNumber = $index + 2;
                    $doctorId = (int) ($row['doctor_id'] ?? 0);
                    $input = [
                        'DoctorID'        => $doctorId > 0 ? (string) $doctorId : '',
                        'DoctorName'      => trim((string) ($row['doctor_name'] ?? '')),
                        'Specialty'       => trim((string) ($row['specialization'] ?? '')),
                        'ConsultationFee' => trim((string) ($row['consultation_fee'] ?? '')),
                        'JoinedDate'      => trim((string) ($row['joined_date'] ?? '')),
                        'WorkingDays'     => '1,2,3,4,5',
                        'WorkStartTime'   => '09:00',
                        'WorkEndTime'     => '17:00',
                    ];
                    $rowErrors = tdc_validate_doctor_form($input);
                    $isEdit = false;
                    if ($doctorId > 0) {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE DoctorID=?');
                        $stmt->execute([$doctorId]);
                        if (!(int) $stmt->fetchColumn()) $rowErrors[] = 'doctor_id does not exist';
                        else $isEdit = true;
                    } else {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM doctors WHERE LOWER(TRIM(DoctorName))=LOWER(TRIM(?))');
                        $stmt->execute([$input['DoctorName']]);
                        if ((int) $stmt->fetchColumn()) $rowErrors[] = 'doctor name already exists';
                    }
                    if ($rowErrors) throw new RuntimeException('Row ' . $rowNumber . ': ' . implode(' ', $rowErrors));
                    tdc_save_doctor($pdo, $input, $isEdit, $doctorId);
                    if ($isEdit) $updated++; else $created++;
                }
                tdc_audit($pdo, 'doctors.imported', 'Doctors', null, "Created {$created} and updated {$updated} doctor records.");
                $pdo->commit();
                $_SESSION['tdc_doctor_import_result'] = ['created' => $created, 'updated' => $updated];
                tdc_redirect('imported');
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        } elseif ($formAction === 'delete') {
            tdc_require_permission('doctors.manage');
            $deleteId = (int) ($_POST['DoctorID'] ?? 0);
            $errors   = $deleteId > 0 ? tdc_delete_doctor($pdo, $deleteId) : ['Invalid doctor selected.'];
            if (empty($errors)) {
                tdc_redirect('deleted');
            }
        } else {
            tdc_require_permission('doctors.manage');
            $old['DoctorID']        = trim((string) ($_POST['DoctorID'] ?? ''));
            $old['DoctorName']      = trim((string) ($_POST['DoctorName'] ?? ''));
            $old['ConsultationFee'] = trim((string) ($_POST['ConsultationFee'] ?? ''));
            $old['Specialty']       = trim((string) ($_POST['Specialty'] ?? ''));
            $old['JoinedDate']      = trim((string) ($_POST['JoinedDate'] ?? ''));
            $old['WorkingDays']     = implode(',', array_values(array_intersect((array) ($_POST['WorkingDay'] ?? []), ['1','2','3','4','5','6','7'])));
            $old['WorkStartTime']   = trim((string) ($_POST['WorkStartTime'] ?? ''));
            $old['WorkEndTime']     = trim((string) ($_POST['WorkEndTime'] ?? ''));

            $isEdit = $old['DoctorID'] !== '' && ctype_digit($old['DoctorID']);
            $errors = tdc_validate_doctor_form($old);

            if (empty($errors)) {
                try {
                    tdc_save_doctor($pdo, $old, $isEdit, (int) $old['DoctorID']);
                    tdc_redirect($isEdit ? 'updated' : 'created');
                } catch (PDOException $e) {
                    error_log('[DOCTORS] save failed: ' . $e->getMessage());
                    $errors[] = 'A system error occurred while saving the doctor. Please try again.';
                }
            }
        }
    }

    // Keep the session CSRF token stable for other open authenticated forms.
}

// =======================================================================
// SECTION 10 — GET data loading
// =======================================================================
$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT * FROM doctors WHERE DoctorName LIKE :q1 OR Specialty LIKE :q2 ORDER BY DoctorName ASC'
    );
    $stmt->execute(['q1' => '%' . $search . '%', 'q2' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query('SELECT * FROM doctors ORDER BY DoctorName ASC');
}
$doctors = $stmt->fetchAll();

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'doctors.php'));

$doctorStatus = (string) ($_GET['status'] ?? '');
$justSaved   = $doctorStatus === 'created' || $doctorStatus === 'updated' || isset($_GET['success']);
$justDeleted = $doctorStatus === 'deleted' || isset($_GET['deleted']);
$doctorToast = match ($doctorStatus) {
    'created' => 'Doctor created successfully.',
    'updated' => 'Doctor updated successfully.',
    'deleted' => 'Doctor deleted successfully.',
    'imported' => 'Doctor records imported successfully.',
    default => $justSaved ? 'Doctor saved successfully.' : ($justDeleted ? 'Doctor deleted successfully.' : ''),
};
$hasDoctorToast = $doctorToast !== '';

$workspaceDoctorId = ctype_digit((string) ($_GET['doctor'] ?? '')) ? (int) $_GET['doctor'] : 0;
$workspaceUrl      = 'doctors.php?workspace=1' . ($workspaceDoctorId > 0 ? '&doctor=' . $workspaceDoctorId : '');
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

    <div class="welcome-eyebrow">Directory</div>
    <div class="welcome-title">Doctors</div>
    <div class="welcome-sub">Consultation fees, specialties, and join dates for clinic doctors.</div>

    <nav class="setup-section-nav" aria-label="Doctors sections">
        <a href="doctors.php" class="active" aria-current="page"><?= tdc_icon('users', 16) ?><span>Directory</span></a>
        <?php if ($canWorkspace): ?><a href="<?= tdc_e($workspaceUrl) ?>"><?= tdc_icon('stethoscope', 16) ?><span>Clinical Workspace</span></a><?php endif; ?>
    </nav>

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
    <div class="setup-notice" role="status">Import completed. Created: <?= (int) ($importResult['created'] ?? 0) ?>. Updated: <?= (int) ($importResult['updated'] ?? 0) ?>. Skipped: 0. Failed: 0.</div>
    <?php endif; ?>

    <div class="section-toolbar">
        <form method="GET" action="doctors.php" class="search-box">
            <input type="text" name="q" placeholder="Search by name or specialty..." value="<?= tdc_e($search) ?>">
            <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Search</span></button>
        </form>
        <div class="table-command-bar"><?php if($canImport):?><button type="button" id="importDoctorBtn" class="btn-success btn "><?= tdc_icon('upload', 14) ?><span>Import CSV / XLSX</span></button><a class="btn-secondary btn " href="doctors.php?download=doctor-template"><?= tdc_icon('download', 14) ?><span>Download CSV Template</span></a><?php endif;?><?php if($canExport):?><a class="btn-secondary btn " href="doctors.php?download=doctors"><?= tdc_icon('download', 14) ?><span>Export CSV</span></a><a class="btn-secondary btn " href="doctors.php?download=doctors-xlsx"><?= tdc_icon('download', 14) ?><span>Export XLSX</span></a><button type="button" class="btn-primary btn " onclick="window.print()"><?= tdc_icon('printer', 14) ?><span>Print</span></button><?php endif;?><?php if ($canManage): ?><button type="button" id="addDoctorBtn" class="btn-success btn "><?= tdc_icon('plus', 14) ?><span>Add Doctor</span></button><?php endif; ?></div>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Specialty</th><th>Consultation Fee</th><th>Joined</th>
                    <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                                <?php if (empty($doctors)): ?>
                <?= tdc_empty_state('stethoscope', 'No doctors found', $search !== '' ? 'No directory records match "' . $search . '".' : 'Doctors added to the directory will appear here.', $canManage ? '<button type="button" class="btn btn-success" data-open-add-doctor>' . tdc_icon('plus', 14) . '<span>Add Doctor</span></button>' : '', $canManage ? 6 : 5) ?>
                <?php else: foreach ($doctors as $d): ?>
                <tr>
                    <td>#<?= (int) $d['DoctorID'] ?></td>
                    <td><?= tdc_e($d['DoctorName']) ?></td>
                    <td><?= tdc_e($d['Specialty'] ?: "\u{2014}") ?></td>
                    <td><?= number_format((float) $d['ConsultationFee'], 2) ?></td>
                    <td><?= tdc_e($d['JoinedDate'] ? date('Y-m-d', strtotime((string) $d['JoinedDate'])) : "\u{2014}") ?></td>
                    <?php if ($canManage): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="icon-action edit-doctor-btn" title="Edit doctor" aria-label="Edit doctor"
                                data-id="<?= (int) $d['DoctorID'] ?>"
                                data-name="<?= tdc_e($d['DoctorName']) ?>"
                                data-specialty="<?= tdc_e((string) $d['Specialty']) ?>"
                                data-fee="<?= tdc_e((string) $d['ConsultationFee']) ?>"
                                data-joined="<?= tdc_e((string) $d['JoinedDate']) ?>"
                                data-working-days="<?= tdc_e((string) ($d['WorkingDays'] ?? '1,2,3,4,5')) ?>"
                                data-work-start="<?= tdc_e(substr((string) ($d['WorkStartTime'] ?? '09:00'), 0, 5)) ?>"
                                data-work-end="<?= tdc_e(substr((string) ($d['WorkEndTime'] ?? '17:00'), 0, 5)) ?>"><?= tdc_icon('pencil', 15) ?></button>
                            <form method="POST" action="doctors.php" data-confirm="Permanently delete this doctor and clear linked consultations, laboratory work, prescriptions, pharmacy sales, payments, and ledger history? Patient records will remain but lose this doctor assignment. This cannot be undone.">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="DoctorID" value="<?= (int) $d['DoctorID'] ?>">
                                <button type="submit" class="icon-action danger" title="Delete doctor" aria-label="Delete doctor"><?= tdc_icon('trash', 15) ?></button>
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
    <?php if($canImport):?><div class="modal-overlay" id="importDoctorModal"><div class="modal-box"><div class="modal-head"><h3><?= tdc_icon('upload',20) ?><span>Import Doctors</span></h3><button type="button" class="modal-close" data-close-doctor-import aria-label="Close">×</button></div><form method="post" enctype="multipart/form-data"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=tdc_e($csrfToken)?>"><input type="hidden" name="form_action" value="import_csv"><div class="form-section"><div class="form-section-heading"><span><strong>CSV or XLSX File</strong><span>Doctor accounts are linked separately in Setup after import.</span></span></div><div class="form-group"><label>Select CSV or XLSX</label><input type="file" name="csv_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><p class="form-hint">Required columns: doctor_name and consultation_fee. Existing doctor IDs are updated; new names are added.</p></div><div class="modal-actions"><button type="button" class="btn btn-secondary" data-close-doctor-import>Cancel</button><button class="btn-success btn "><?= tdc_icon('upload',16) ?><span>Import Doctors</span></button></div></div></form></div></div><?php endif;?>
    <div class="modal-overlay" id="doctorModalOverlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="doctorModalTitle">Add Doctor</h3>
                <button type="button" class="modal-close" id="doctorModalCloseBtn" aria-label="Close">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <form id="doctorForm" method="POST" action="doctors.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="DoctorID" id="df_DoctorID" value="">

                    <div class="form-group"><label for="df_DoctorName">Doctor Name</label>
                        <input type="text" id="df_DoctorName" name="DoctorName" placeholder="e.g. Dr. Amina Yusuf" required></div>

                    <div class="form-group"><label for="df_Specialty">Specialty</label>
                        <input type="text" id="df_Specialty" name="Specialty" placeholder="e.g. Dermatology"></div>

                    <div class="form-row">
                        <div class="form-group"><label for="df_ConsultationFee">Consultation Fee</label>
                            <input type="number" step="0.01" min="0" id="df_ConsultationFee" name="ConsultationFee" placeholder="0.00"></div>
                        <div class="form-group"><label for="df_JoinedDate">Joined Date</label>
                            <input type="date" id="df_JoinedDate" name="JoinedDate"></div>
                    </div>

                    <div class="form-group"><label>Working Days</label><div class="check-control-group"><?php foreach (['1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat','7'=>'Sun'] as $dayValue => $dayLabel): ?><label class="check-control"><input type="checkbox" name="WorkingDay[]" value="<?= $dayValue ?>" <?= in_array($dayValue, explode(',', $old['WorkingDays']), true) ? 'checked' : '' ?>><span><?= $dayLabel ?></span></label><?php endforeach; ?></div></div>
                    <div class="form-row"><div class="form-group"><label for="df_WorkStartTime">Available From</label><input type="time" id="df_WorkStartTime" name="WorkStartTime" value="<?= tdc_e($old['WorkStartTime']) ?>" required></div><div class="form-group"><label for="df_WorkEndTime">Available Until</label><input type="time" id="df_WorkEndTime" name="WorkEndTime" value="<?= tdc_e($old['WorkEndTime']) ?>" required></div></div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="doctorModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Save Doctor</span></button>
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

<?php if ($canManage): ?>
(function(){
    const overlay = document.getElementById('doctorModalOverlay');
    const modalTitle = document.getElementById('doctorModalTitle');
    const form = document.getElementById('doctorForm');
    const fId = document.getElementById('df_DoctorID');
    const fName = document.getElementById('df_DoctorName');
    const fSpecialty = document.getElementById('df_Specialty');
    const fFee = document.getElementById('df_ConsultationFee');
    const fJoined = document.getElementById('df_JoinedDate');
    const fStart = document.getElementById('df_WorkStartTime');
    const fEnd = document.getElementById('df_WorkEndTime');
    const fDays = Array.from(form.querySelectorAll('input[name="WorkingDay[]"]'));

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    function openAddModal(){
        form.reset();
        fId.value = '';
        modalTitle.textContent = 'Add Doctor';
        openModal();
    }
    document.getElementById('addDoctorBtn')?.addEventListener('click', openAddModal);
    document.querySelectorAll('[data-open-add-doctor]').forEach(function(btn){ btn.addEventListener('click', openAddModal); });

    document.querySelectorAll('.edit-doctor-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            fSpecialty.value = btn.dataset.specialty;
            fFee.value = btn.dataset.fee;
            fJoined.value = btn.dataset.joined;
            fDays.forEach(function(day){ day.checked = (btn.dataset.workingDays || '1,2,3,4,5').split(',').includes(day.value); });
            fStart.value = btn.dataset.workStart || '09:00';
            fEnd.value = btn.dataset.workEnd || '17:00';
            modalTitle.textContent = 'Edit Doctor';
            openModal();
        });
    });

    document.getElementById('doctorModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('doctorModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') === 'save'): ?>
    fId.value = <?= json_encode($old['DoctorID']) ?>;
    fName.value = <?= json_encode($old['DoctorName']) ?>;
    fSpecialty.value = <?= json_encode($old['Specialty']) ?>;
    fFee.value = <?= json_encode($old['ConsultationFee']) ?>;
    fJoined.value = <?= json_encode($old['JoinedDate']) ?>;
    fDays.forEach(function(day){ day.checked = <?= json_encode(explode(',', $old['WorkingDays'])) ?>.includes(day.value); });
    fStart.value = <?= json_encode($old['WorkStartTime']) ?>;
    fEnd.value = <?= json_encode($old['WorkEndTime']) ?>;
    modalTitle.textContent = fId.value ? 'Edit Doctor' : 'Add Doctor';
    openModal();
    <?php endif; ?>

    <?php if ($hasDoctorToast): ?>
    showToast(<?= json_encode($doctorToast) ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'doctors.php'); }
    <?php endif; ?>
})();
<?php endif; ?>
</script>
<?php if($canImport):?><script>(()=>{const modal=document.getElementById('importDoctorModal'),open=document.getElementById('importDoctorBtn');const close=()=>modal?.classList.remove('show');open?.addEventListener('click',()=>modal?.classList.add('show'));document.querySelectorAll('[data-close-doctor-import]').forEach(button=>button.addEventListener('click',close));modal?.addEventListener('click',event=>{if(event.target===modal)close()});})();</script><?php endif;?>

</body>
</html>
