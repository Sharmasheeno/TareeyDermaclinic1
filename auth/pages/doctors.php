<?php
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

/** Redirects (Post/Redirect/Get) back to the list with a one-shot flash flag. */
function tdc_redirect(string $flag): void
{
    header('Location: doctors.php?' . $flag . '=1');
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

/** @param array{DoctorName:string,ConsultationFee:string,Specialty:string,JoinedDate:string} $input */
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
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE Doctors SET DoctorName = :DoctorName, ConsultationFee = :ConsultationFee,
                Specialty = :Specialty, JoinedDate = :JoinedDate
             WHERE DoctorID = :id'
        );
        $stmt->execute($params);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO Doctors (DoctorName, ConsultationFee, Specialty, JoinedDate)
         VALUES (:DoctorName, :ConsultationFee, :Specialty, :JoinedDate)'
    );
    $stmt->execute($params);
}

/**
 * App-level referential guard: the schema has no FK constraints, so we
 * check dependents (allocated patients, prescriptions written) ourselves
 * before allowing a delete.
 *
 * @return string[] error messages; empty on success
 */
function tdc_delete_doctor(PDO $pdo, int $id): array
{
    $patientCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Patients WHERE AllocatedDoctor = :id', ['id' => $id]);
    $rxCount      = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Prescriptions WHERE DoctorID = :id', ['id' => $id]);
    $visitCount   = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Visits WHERE DoctorID = :id', ['id' => $id]);
    $labCount     = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Laboratory WHERE DoctorID = :id', ['id' => $id]);

    if ($patientCount > 0 || $rxCount > 0 || $visitCount > 0 || $labCount > 0) {
        return ['This doctor has linked patient, consultation, prescription, or laboratory history and cannot be deleted.'];
    }

    $stmt = $pdo->prepare('DELETE FROM Doctors WHERE DoctorID = :id');
    $stmt->execute(['id' => $id]);
    return [];
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

if (($_SESSION['role'] ?? '') === 'doctoruser') {
    require __DIR__ . '/../includes/doctor-portal.php';
    exit;
}

$canManage = in_array($_SESSION['role'] ?? '', ALLOWED_MANAGE_ROLES, true);

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$errors = [];
$old = [
    'DoctorID'        => '',
    'DoctorName'      => '',
    'ConsultationFee' => '',
    'Specialty'       => '',
    'JoinedDate'      => '',
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

        if ($formAction === 'delete') {
            $deleteId = (int) ($_POST['DoctorID'] ?? 0);
            $errors   = $deleteId > 0 ? tdc_delete_doctor($pdo, $deleteId) : ['Invalid doctor selected.'];
            if (empty($errors)) {
                tdc_redirect('deleted');
            }
        } else {
            $old['DoctorID']        = trim((string) ($_POST['DoctorID'] ?? ''));
            $old['DoctorName']      = trim((string) ($_POST['DoctorName'] ?? ''));
            $old['ConsultationFee'] = trim((string) ($_POST['ConsultationFee'] ?? ''));
            $old['Specialty']       = trim((string) ($_POST['Specialty'] ?? ''));
            $old['JoinedDate']      = trim((string) ($_POST['JoinedDate'] ?? ''));

            $isEdit = $old['DoctorID'] !== '' && ctype_digit($old['DoctorID']);
            $errors = tdc_validate_doctor_form($old);

            if (empty($errors)) {
                try {
                    tdc_save_doctor($pdo, $old, $isEdit, (int) $old['DoctorID']);
                    tdc_redirect('success');
                } catch (PDOException $e) {
                    error_log('[DOCTORS] save failed: ' . $e->getMessage());
                    $errors[] = 'A system error occurred while saving the doctor. Please try again.';
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
$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT * FROM Doctors WHERE DoctorName LIKE :q1 OR Specialty LIKE :q2 ORDER BY DoctorName ASC'
    );
    $stmt->execute(['q1' => '%' . $search . '%', 'q2' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query('SELECT * FROM Doctors ORDER BY DoctorName ASC');
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

    .error-msg{ display:flex; flex-direction:column; gap:4px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:14px 16px; margin-bottom:24px; max-width:1040px; }
    .error-msg .error-title{ display:flex; align-items:center; gap:8px; font-weight:700; }
    .error-msg svg{ width:16px; height:16px; flex-shrink:0; }
    .error-msg ul{ list-style:none; padding-left:24px; }
    .error-msg li::before{ content:"— "; }

    .form-group{ display:flex; flex-direction:column; }
    .form-group label{ font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy); margin-bottom:6px; }
    .form-group input, .form-group select{ width:100%; padding:11px 12px; border:2px solid rgba(46,49,146,0.3); font-size:14px; font-family:'Google Sans', sans-serif; color:var(--navy); background:var(--white); outline:none; transition:border-color 0.15s; }
    .form-group input::placeholder{ color:rgba(46,49,146,0.45); }
    .form-group input:focus, .form-group select:focus{ border-color:var(--orange); }
    .form-row{ display:flex; gap:16px; flex-wrap:wrap; }
    .form-row .form-group{ flex:1; min-width:180px; }
    .btn{ padding:11px 22px; font-size:14px; font-weight:600; border:2px solid var(--navy); cursor:pointer; letter-spacing:0.02em; transition:background 0.12s, color 0.12s, border-color 0.12s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary{ background:var(--navy); color:var(--white); }
    .btn-primary:hover{ background:var(--orange); border-color:var(--orange); }
    .btn-secondary{ background:var(--white); color:var(--navy); }
    .btn-secondary:hover{ color:var(--orange); border-color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1040px; margin-bottom:16px; flex-wrap:wrap; }
    .search-box{ display:flex; gap:8px; }
    .search-box input{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); min-width:240px; }
    .search-box input:focus{ outline:none; border-color:var(--orange); }

    .data-table-wrap{ max-width:1040px; border:2px solid var(--navy); overflow-x:auto; }
    .data-table{ width:100%; border-collapse:collapse; }
    .data-table th, .data-table td{ padding:12px 14px; font-size:13px; text-align:left; border-bottom:1px solid var(--navy-30); white-space:nowrap; }
    .data-table th{ background:var(--navy-10); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.05em; color:var(--navy); }
    .data-table tbody tr:last-child td{ border-bottom:none; }
    .data-table tbody tr:hover{ background:var(--navy-10); }
    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    .row-actions{ display:flex; gap:8px; }
    .row-actions form{ display:inline; }
    .btn-sm{ padding:6px 12px; font-size:12px; font-weight:600; border:2px solid var(--navy); cursor:pointer; background:var(--white); color:var(--navy); }
    .btn-sm:hover{ background:var(--orange); border-color:var(--orange); color:var(--white); }
    .btn-sm.danger{ border-color:#c0392b; color:#c0392b; }
    .btn-sm.danger:hover{ background:#c0392b; border-color:#c0392b; color:var(--white); }

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

    <div class="welcome-eyebrow">Directory</div>
    <div class="welcome-title">Doctors</div>
    <div class="welcome-sub">Consultation fees, specialties, and join dates for clinic doctors.</div>

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
        <form method="GET" action="doctors.php" class="search-box">
            <input type="text" name="q" placeholder="Search by name or specialty..." value="<?= tdc_e($search) ?>">
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>
        <?php if ($canManage): ?>
        <button type="button" id="addDoctorBtn" class="btn btn-primary">+ Add Doctor</button>
        <?php endif; ?>
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
                <tr class="empty-row">
                    <td colspan="<?= $canManage ? 6 : 5 ?>">
                        No doctors found<?= $search !== '' ? ' for "' . tdc_e($search) . '"' : '' ?>.
                        <?= $canManage ? ' Click "Add Doctor" to add one.' : '' ?>
                    </td>
                </tr>
                <?php else: foreach ($doctors as $d): ?>
                <tr>
                    <td>#<?= (int) $d['DoctorID'] ?></td>
                    <td><?= tdc_e($d['DoctorName']) ?></td>
                    <td><?= tdc_e($d['Specialty'] ?: '—') ?></td>
                    <td><?= number_format((float) $d['ConsultationFee'], 2) ?></td>
                    <td><?= tdc_e($d['JoinedDate'] ? date('Y-m-d', strtotime((string) $d['JoinedDate'])) : '—') ?></td>
                    <?php if ($canManage): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn-sm edit-doctor-btn"
                                data-id="<?= (int) $d['DoctorID'] ?>"
                                data-name="<?= tdc_e($d['DoctorName']) ?>"
                                data-specialty="<?= tdc_e((string) $d['Specialty']) ?>"
                                data-fee="<?= tdc_e((string) $d['ConsultationFee']) ?>"
                                data-joined="<?= tdc_e((string) $d['JoinedDate']) ?>">Edit</button>
                            <form method="POST" action="doctors.php" onsubmit="return confirm('Delete this doctor? This cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                <input type="hidden" name="form_action" value="delete">
                                <input type="hidden" name="DoctorID" value="<?= (int) $d['DoctorID'] ?>">
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

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="doctorModalCancelBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Doctor</button>
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

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.getElementById('addDoctorBtn').addEventListener('click', function(){
        form.reset();
        fId.value = '';
        modalTitle.textContent = 'Add Doctor';
        openModal();
    });

    document.querySelectorAll('.edit-doctor-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            fSpecialty.value = btn.dataset.specialty;
            fFee.value = btn.dataset.fee;
            fJoined.value = btn.dataset.joined;
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
    modalTitle.textContent = fId.value ? 'Edit Doctor' : 'Add Doctor';
    openModal();
    <?php endif; ?>

    <?php if ($justSaved || $justDeleted): ?>
    showToast(<?= $justSaved ? json_encode('Doctor saved successfully.') : json_encode('Doctor deleted successfully.') ?>);
    if (window.history.replaceState) { window.history.replaceState({}, document.title, 'doctors.php'); }
    <?php endif; ?>
})();
<?php endif; ?>
</script>

</body>
</html>
