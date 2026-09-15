<?php
/**
 * auth/pages/settings.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Settings (Manage Users)
 * ---------------------------------------------------------------------
 * Security controls (same posture as home.php / patientregistration.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: only 'superuser' may view/manage this page
 *   - CSRF-token-checked POST handler, rotated on every submit
 *   - Prepared statements only — no string-built SQL
 *   - Post/Redirect/Get on success to prevent duplicate submissions
 *   - Passwords hashed with password_hash(), never echoed back
 *   - All session/user-derived output escaped before hitting HTML
 *
 * Schema alignment (tareydermaclinic.users):
 *   id INT AUTO_INCREMENT PK
 *   userlegalname VARCHAR(255) NOT NULL
 *   role ENUM('superuser','receptionuser','pharmacyuser','labuser')
 *   username VARCHAR(100) NOT NULL UNIQUE
 *   password VARCHAR(255) NOT NULL
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
// SECTION 2 — Reference data & shared helpers
// =======================================================================
const ROLE_OPTIONS = TDC_ROLES;

const ALLOWED_SECTIONS = ['users'];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php. Flat, single-link items only: no nested/dropdown menus.
 * Order and membership per spec: Dashboard, Reception, Doctors,
 * Patients, Laboratory, Pharmacy, Accounting, Reports, Settings.
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

/**
 * Validates an Add/Edit "Manage Users" submission.
 * Returns an array of human-readable error strings (empty = valid).
 *
 * @param array{userlegalname:string,role:string,username:string} $input
 */
function tdc_validate_user_form(
    PDO $pdo,
    array $input,
    string $password,
    string $confirmPassword,
    bool $isEdit,
    int $editId
): array {
    $errors = [];

    if ($input['userlegalname'] === '' || mb_strlen($input['userlegalname']) < 2 || mb_strlen($input['userlegalname']) > 255) {
        $errors[] = 'Full legal name is required (2-255 characters).';
    }

    if (!array_key_exists($input['role'], ROLE_OPTIONS)) {
        $errors[] = 'Please select a valid role.';
    }

    if (!preg_match('/^[a-zA-Z0-9_.]{3,100}$/', $input['username'])) {
        $errors[] = 'Username must be 3-100 characters (letters, numbers, dot, underscore only).';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username AND id != :id');
            $stmt->execute(['username' => $input['username'], 'id' => $editId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = 'This username is already taken.';
            }
        } catch (PDOException $e) {
            error_log('[SETUP][USERS] username check failed: ' . $e->getMessage());
            $errors[] = 'A system error occurred. Please try again.';
        }
    }

    if (!$isEdit && $password === '') {
        $errors[] = 'Password is required for a new user.';
    }
    if ($password !== '' && mb_strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== '' && $password !== $confirmPassword) {
        $errors[] = 'Password and confirmation do not match.';
    }

    return $errors;
}

/**
 * Persists a create or update for the users table.
 * On update with a blank password, the existing password is preserved.
 *
 * @param array{userlegalname:string,role:string,username:string} $input
 * @throws PDOException on failure (caller decides how to present it)
 */
function tdc_save_user(PDO $pdo, array $input, string $password, bool $isEdit, int $editId): int
{
    if ($isEdit) {
        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users SET userlegalname = :userlegalname, role = :role, username = :username, password = :password WHERE id = :id'
            );
            $stmt->execute([
                'userlegalname' => $input['userlegalname'],
                'role'          => $input['role'],
                'username'      => $input['username'],
                'password'      => password_hash($password, PASSWORD_DEFAULT),
                'id'            => $editId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET userlegalname = :userlegalname, role = :role, username = :username WHERE id = :id'
            );
            $stmt->execute([
                'userlegalname' => $input['userlegalname'],
                'role'          => $input['role'],
                'username'      => $input['username'],
                'id'            => $editId,
            ]);
        }
        return $editId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (userlegalname, role, username, password) VALUES (:userlegalname, :role, :username, :password)'
    );
    $stmt->execute([
        'userlegalname' => $input['userlegalname'],
        'role'          => $input['role'],
        'username'      => $input['username'],
        'password'      => password_hash($password, PASSWORD_DEFAULT),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Deletes a user by id.
 *
 * @throws PDOException on failure (caller decides how to present it)
 */
function tdc_delete_user(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE Doctors SET UserID=NULL WHERE UserID=:id');
    $stmt->execute(['id' => $id]);
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

// =======================================================================
// SECTION 3 — Logout (may exit)
// =======================================================================
tdc_handle_logout();

// =======================================================================
// SECTION 4 — Auth gate & role gate
// =======================================================================
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'superuser') {
    header('Location: home.php');
    exit;
}

require_once __DIR__ . '/../../db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 5 — Request-scoped state
// =======================================================================
$section = $_GET['section'] ?? null;
if ($section !== null && !in_array($section, ALLOWED_SECTIONS, true)) {
    $section = null;
}

$errors = [];
$old = [
    'user_id'       => '',
    'userlegalname' => '',
    'role'          => '',
    'username'      => '',
    'DoctorID'      => '',
];

// =======================================================================
// SECTION 6 — POST handler (Manage Users: save / delete)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'users') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        if ($formAction === 'delete') {
            $deleteId = (int) ($_POST['user_id'] ?? 0);

            if ($deleteId <= 0) {
                $errors[] = 'Invalid user selected for deletion.';
            } elseif ($deleteId === (int) $_SESSION['user_id']) {
                $errors[] = 'You cannot delete your own account while signed in.';
            } else {
                try {
                    tdc_delete_user($pdo, $deleteId);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: settings.php?section=users&deleted=1');
                    exit;
                } catch (PDOException $e) {
                    error_log('[SETUP][USERS] delete failed: ' . $e->getMessage());
                    $errors[] = 'A system error occurred while deleting the user. Please try again.';
                }
            }
        } else {
            $old['user_id']       = trim((string) ($_POST['user_id'] ?? ''));
            $old['userlegalname'] = trim((string) ($_POST['userlegalname'] ?? ''));
            $old['role']          = (string) ($_POST['role'] ?? '');
            $old['username']      = trim((string) ($_POST['username'] ?? ''));
            $old['DoctorID']      = trim((string) ($_POST['DoctorID'] ?? ''));
            $password             = (string) ($_POST['password'] ?? '');
            $confirmPassword      = (string) ($_POST['confirm_password'] ?? '');

            $isEdit = $old['user_id'] !== '' && ctype_digit($old['user_id']);
            $editId = $isEdit ? (int) $old['user_id'] : 0;

            $errors = tdc_validate_user_form($pdo, $old, $password, $confirmPassword, $isEdit, $editId);
            if ($isEdit && $editId === (int) $_SESSION['user_id'] && $old['role'] !== 'superuser') {
                $errors[] = 'Your signed-in account must keep the SuperAdmin role.';
            }
            if ($old['role'] === 'doctoruser') {
                if ($old['DoctorID'] === '' || !ctype_digit($old['DoctorID'])) {
                    $errors[] = 'Select the doctor profile linked to this account.';
                } else {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM Doctors WHERE DoctorID=? AND (UserID IS NULL OR UserID=?)');
                    $stmt->execute([(int)$old['DoctorID'],$editId]);
                    if (!(int)$stmt->fetchColumn()) $errors[] = 'That doctor profile is already linked to another user.';
                }
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    $savedUserId = tdc_save_user($pdo, $old, $password, $isEdit, $editId);
                    $stmt = $pdo->prepare('UPDATE Doctors SET UserID=NULL WHERE UserID=?');
                    $stmt->execute([$savedUserId]);
                    if ($old['role'] === 'doctoruser') {
                        $stmt = $pdo->prepare('UPDATE Doctors SET UserID=? WHERE DoctorID=?');
                        $stmt->execute([$savedUserId,(int)$old['DoctorID']]);
                    }
                    $pdo->commit();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: settings.php?section=users&success=1');
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if ((string) $e->getCode() === '23000') {
                        $errors[] = 'This username is already taken.';
                    } else {
                        error_log('[SETUP][USERS] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the user. Please try again.';
                    }
                }
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above)
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 7 — List data for the Manage Users table
// =======================================================================
$users = [];
if ($section === 'users') {
    try {
        $stmt  = $pdo->query('SELECT u.id,u.userlegalname,u.role,u.username,u.created_at,d.DoctorID FROM users u LEFT JOIN Doctors d ON d.UserID=u.id ORDER BY u.userlegalname ASC');
        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[SETUP][USERS] list fetch failed: ' . $e->getMessage());
        $users = [];
    }
}
$doctorProfiles = $section === 'users' ? $pdo->query('SELECT DoctorID,DoctorName,UserID FROM Doctors ORDER BY DoctorName')->fetchAll() : [];

// =======================================================================
// SECTION 8 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$justSaved     = isset($_GET['success']);
$justDeleted   = isset($_GET['deleted']);

// Resolve the active nav item from the actual requested script, so the
// component stays correct if it's ever reused outside its own page.
$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'settings.php'));
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
    body{
        font-family:'Google Sans', sans-serif;
        background:var(--white);
        color:var(--navy);
        min-height:100vh;
    }
    .app-header{ position:relative; z-index:100; }
    .nav-item{ position:relative; flex-shrink:0; }
    .utility-bar{
        display:flex; align-items:center; justify-content:space-between;
        background:var(--navy);
        padding:8px 24px;
    }
    .brand-chip{
        background:var(--white);
        display:flex; align-items:center;
        padding:5px 14px;
        flex-shrink:0;
    }
    .brand-chip img{ height:30px; width:auto; object-fit:contain; display:block; }
    .utility-right{ display:flex; align-items:center; gap:2px; }
    .icon-btn{
        appearance:none; background:none; border:2px solid transparent; cursor:pointer;
        display:flex; align-items:center; justify-content:center;
        width:38px; height:38px; position:relative;
        color:var(--on-navy-70);
        transition:color 0.12s;
    }
    .icon-btn:hover{ color:var(--white); }
    .icon-btn svg{ width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .icon-btn .badge{
        position:absolute; top:6px; right:7px;
        width:7px; height:7px; background:var(--orange); border:2px solid var(--navy);
    }
    .nav-item.open > .icon-btn{ color:var(--orange); }
    .profile-static{
        display:flex; align-items:center; gap:9px;
        padding:6px 8px;
        font-family:'Google Sans', sans-serif;
        color:var(--white);
    }
    .avatar{
        width:30px; height:30px; flex-shrink:0;
        background:var(--white); color:var(--navy);
        display:flex; align-items:center; justify-content:center;
        font-size:12px; font-weight:700; letter-spacing:0.02em;
    }
    .profile-name{ font-size:13.5px; font-weight:600; }
    .menu-bar{
        background:var(--white);
        padding:0 24px;
        display:flex;
        /* Center as a group when the row fits (matches the original
           design); "safe" makes it fall back to start-alignment the
           moment the row overflows, so scrolling can always reach
           every item — plain `center` on an overflowing flex line
           can strand the leading items off-screen to the left. */
        justify-content:safe center;
        overflow-x:auto;
        overflow-y:hidden;
        scrollbar-width:thin;
        scrollbar-color:var(--navy-30) transparent;
    }
    .menu-bar::-webkit-scrollbar{ height:4px; }
    .menu-bar::-webkit-scrollbar-track{ background:transparent; }
    .menu-bar::-webkit-scrollbar-thumb{ background:var(--navy-30); border-radius:2px; }
    .menu-bar::-webkit-scrollbar-thumb:hover{ background:var(--navy-55); }
    .nav-items{ list-style:none; display:flex; align-items:center; gap:4px; flex-wrap:nowrap; flex-shrink:0; }
    .nav-link{
        appearance:none; background:none; border:none; cursor:pointer;
        display:flex; align-items:center; gap:7px;
        font-family:'Google Sans', sans-serif;
        font-size:13.5px; font-weight:600; letter-spacing:0.01em;
        color:var(--navy);
        text-decoration:none;
        padding:12px;
        white-space:nowrap;
        flex-shrink:0;
        transition:color 0.12s;
    }
    .nav-link svg{ width:16px; height:16px; fill:var(--navy-55); flex-shrink:0; transition:fill 0.12s; }
    .nav-link:hover{ color:var(--orange); }
    .nav-link:hover svg{ fill:var(--orange); }
    .nav-item.active > .nav-link{
        color:var(--navy);
        box-shadow:inset 0 -2px 0 var(--orange);
    }
    .nav-item.active > .nav-link svg{ fill:var(--navy); }
    .chevron{ width:10px; height:10px; stroke:var(--navy-55); fill:none; stroke-width:2.4; stroke-linecap:round; stroke-linejoin:round; transition:transform 0.15s ease, stroke 0.12s; }
    .nav-item.open .chevron{ transform:rotate(180deg); }
    .dropdown-menu{
        position:absolute; top:calc(100% + 6px); left:0;
        min-width:220px;
        background:var(--white);
        border:var(--border);
        display:none;
        flex-direction:column;
        padding:6px 0;
    }
    .nav-item.open > .dropdown-menu{ display:flex; }
    .dropdown-menu a{
        display:block; text-decoration:none; color:var(--navy);
        font-size:13.5px; font-weight:500;
        padding:9px 16px;
        transition:background 0.12s, color 0.12s;
    }
    .dropdown-menu a:hover{ background:var(--navy-10); color:var(--orange); }
    .dropdown-menu a.current{ background:var(--navy-10); color:var(--orange); font-weight:700; }
    .notif-menu{ right:0; left:auto; min-width:260px; }
    .notif-menu .notif-title{
        padding:10px 16px 8px; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:var(--navy-55);
    }
    .notif-empty{ padding:20px 16px 22px; font-size:13px; color:var(--navy-55); text-align:center; }
    .page-body{ padding:40px 32px; }
    .welcome-eyebrow{ font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--navy-55); margin-bottom:8px; }
    .welcome-title{ font-size:26px; font-weight:700; color:var(--navy); }
    .welcome-sub{ font-size:14px; color:var(--navy-55); margin-top:6px; margin-bottom:28px; }

    .error-msg{
        display:flex; flex-direction:column; gap:4px;
        background:var(--white); border:2px solid var(--navy);
        color:var(--navy); font-size:13px; font-weight:500;
        padding:14px 16px; margin-bottom:24px; max-width:1040px;
    }
    .error-msg .error-title{ display:flex; align-items:center; gap:8px; font-weight:700; }
    .error-msg svg{ width:16px; height:16px; flex-shrink:0; }
    .error-msg ul{ list-style:none; padding-left:24px; }
    .error-msg li::before{ content:"— "; }

    .form-group{ display:flex; flex-direction:column; }
    .form-group label{
        font-size:11px; font-weight:600;
        letter-spacing:0.06em; text-transform:uppercase;
        color:var(--navy); margin-bottom:6px;
    }
    .form-group input,
    .form-group select{
        width:100%;
        padding:11px 12px;
        border:2px solid rgba(46,49,146,0.3);
        font-size:14px; font-family:'Google Sans', sans-serif;
        color:var(--navy); background:var(--white);
        outline:none; transition:border-color 0.15s;
    }
    .form-group input::placeholder{ color:rgba(46,49,146,0.45); }
    .form-group input:focus,
    .form-group select:focus{ border-color:var(--orange); }
    .form-group select{ cursor:pointer; }
    .btn{
        padding:11px 22px;
        font-size:14px; font-weight:600;
        border:2px solid var(--navy); cursor:pointer;
        letter-spacing:0.02em;
        transition:background 0.12s, color 0.12s, border-color 0.12s;
    }
    .btn-primary{ background:var(--navy); color:var(--white); }
    .btn-primary:hover{ background:var(--orange); border-color:var(--orange); }
    .btn-secondary{ background:var(--white); color:var(--navy); }
    .btn-secondary:hover{ color:var(--orange); border-color:var(--orange); }

    /* --- Settings grid ------------------------------------------------ */
    .setup-grid{ display:grid; grid-template-columns:repeat(3, minmax(220px,1fr)); gap:20px; max-width:920px; }
    .setup-card{
        display:flex; align-items:flex-start; gap:14px;
        padding:20px; border:2px solid var(--navy);
        text-decoration:none; color:var(--navy);
        transition:background 0.12s, border-color 0.12s;
    }
    .setup-card:hover{ background:var(--navy-10); border-color:var(--orange); }
    .setup-card .setup-icon{ width:32px; height:32px; flex-shrink:0; color:var(--navy-55); }
    .setup-card .setup-icon svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; }
    .setup-card-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-bottom:4px; }
    .setup-card-desc{ font-size:12.5px; color:var(--navy-55); }

    /* --- Manage Users section --------------------------------------- */
    .back-link{
        display:inline-flex; align-items:center; gap:6px;
        font-size:13px; font-weight:600; color:var(--navy-55);
        text-decoration:none; margin-bottom:16px;
    }
    .back-link:hover{ color:var(--orange); }
    .users-toolbar{
        display:flex; align-items:center; justify-content:space-between;
        max-width:1040px; margin-bottom:16px;
    }
    .users-table-wrap{ max-width:1040px; border:2px solid var(--navy); overflow-x:auto; }
    .users-table{ width:100%; border-collapse:collapse; }
    .users-table th, .users-table td{ padding:12px 14px; font-size:13px; text-align:left; border-bottom:1px solid var(--navy-30); white-space:nowrap; }
    .users-table th{ background:var(--navy-10); font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:.05em; color:var(--navy); }
    .users-table tbody tr:last-child td{ border-bottom:none; }
    .users-table tbody tr:hover{ background:var(--navy-10); }
    .role-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); }
    .row-actions{ display:flex; gap:8px; }
    .row-actions form{ display:inline; }
    .btn-sm{ padding:6px 12px; font-size:12px; font-weight:600; border:2px solid var(--navy); cursor:pointer; background:var(--white); color:var(--navy); }
    .btn-sm:hover{ background:var(--orange); border-color:var(--orange); color:var(--white); }
    .btn-sm.danger{ border-color:#c0392b; color:#c0392b; }
    .btn-sm.danger:hover{ background:#c0392b; border-color:#c0392b; color:var(--white); }
    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    /* --- Add/Edit User modal ---------------------------------------- */
    .modal-overlay{ position:fixed; inset:0; background:rgba(46,49,146,0.35); display:none; align-items:center; justify-content:center; z-index:1000; padding:20px; }
    .modal-overlay.show{ display:flex; }
    .modal-box{ background:var(--white); border:2px solid var(--navy); width:100%; max-width:520px; max-height:90vh; overflow-y:auto; }
    .modal-head{ display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:2px solid var(--navy); }
    .modal-head h3{ font-size:16px; font-weight:700; color:var(--navy); }
    .modal-close{ appearance:none; background:none; border:none; cursor:pointer; color:var(--navy-55); width:26px; height:26px; }
    .modal-close:hover{ color:var(--orange); }
    .modal-close svg{ width:100%; height:100%; }
    .modal-body{ padding:22px; }
    .modal-body .form-group{ margin-bottom:16px; }
    .modal-hint{ font-size:11.5px; color:var(--navy-55); margin-top:4px; }
    .modal-actions{ display:flex; justify-content:flex-end; gap:10px; margin-top:6px; }

    #js-toast{
        position:fixed; bottom:28px; left:50%;
        transform:translateX(-50%) translateY(20px);
        display:flex; align-items:center; gap:8px;
        background:var(--white); border:2px solid var(--navy);
        color:var(--navy); font-size:13px; font-weight:500;
        padding:10px 18px; white-space:nowrap;
        z-index:9999; opacity:0; pointer-events:none;
        transition:opacity 0.2s ease, transform 0.2s ease;
    }
    #js-toast svg{ width:16px; height:16px; flex-shrink:0; }
    #js-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    /* Standalone Logout button — fixed to the extreme bottom-right
       corner, always visible regardless of dropdown state or scroll
       position. Replaces the old profile-dropdown logout link. */
    .logout-fab{
        position:fixed;
        bottom:20px;
        right:20px;
        width:40px;
        height:40px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        background:var(--navy);
        border:2px solid var(--white);
        cursor:pointer;
        text-decoration:none;
        z-index:9999;
        box-shadow:0 2px 6px rgba(46,49,146,0.35);
        transition:transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }
    .logout-fab svg{
        width:18px; height:18px;
        stroke:var(--white); fill:none;
        stroke-width:2; stroke-linecap:round; stroke-linejoin:round;
        transition:stroke 0.15s ease;
    }
    .logout-fab:hover{
        background:var(--orange);
        transform:scale(1.08);
        box-shadow:0 4px 10px rgba(241,90,36,0.4);
    }
    .logout-fab:active{ transform:scale(0.96); }
    .logout-fab:focus-visible{ outline:2px solid var(--orange); outline-offset:3px; }
    .logout-fab::after{
        content:'Log Out';
        position:absolute;
        bottom:calc(100% + 8px);
        right:0;
        background:var(--navy);
        color:var(--white);
        font-family:'Google Sans', sans-serif;
        font-size:12px;
        font-weight:600;
        padding:6px 10px;
        white-space:nowrap;
        opacity:0;
        visibility:hidden;
        transform:translateY(4px);
        transition:opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease;
        pointer-events:none;
    }
    .logout-fab:hover::after,
    .logout-fab:focus-visible::after{
        opacity:1;
        visibility:visible;
        transform:translateY(0);
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

<?php if ($section !== 'users'): ?>

    <div class="welcome-eyebrow">Settings</div>
    <div class="welcome-title">System Settings</div>
    <div class="welcome-sub">Manage core configuration for Tarey Derma Clinic.</div>

    <div class="setup-grid">
        <a href="settings.php?section=users" class="setup-card">
            <div class="setup-icon">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            </div>
            <div>
                <div class="setup-card-title">Manage Users</div>
                <div class="setup-card-desc">Manage Users Here</div>
            </div>
        </a>
    </div>

<?php else: ?>

    <a href="settings.php" class="back-link">&larr; Back to Settings</a>
    <div class="welcome-eyebrow">Settings</div>
    <div class="welcome-title">Manage Users</div>
    <div class="welcome-sub">Add, edit, or remove staff accounts.</div>

    <?php if (!empty($errors)): ?>
    <div class="error-msg">
        <div class="error-title">
            <svg viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/>
            </svg>
            Please fix the following:
        </div>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= tdc_e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="users-toolbar">
        <div></div>
        <button type="button" id="addUserBtn" class="btn btn-primary">+ Add User</button>
    </div>

    <div class="users-table-wrap">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Legal Name</th>
                    <th>Role</th>
                    <th>Username</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr class="empty-row">
                    <td colspan="5">No users found yet. Click "Add User" to create one.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= tdc_e($u['userlegalname']) ?></td>
                        <td><span class="role-badge"><?= tdc_e(ROLE_OPTIONS[$u['role']] ?? $u['role']) ?></span></td>
                        <td><?= tdc_e($u['username']) ?></td>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $u['created_at']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <button type="button"
                                    class="btn-sm edit-user-btn"
                                    data-id="<?= (int) $u['id'] ?>"
                                    data-name="<?= tdc_e($u['userlegalname']) ?>"
                                    data-role="<?= tdc_e($u['role']) ?>"
                                    data-doctor="<?= tdc_e((string)$u['DoctorID']) ?>"
                                    data-username="<?= tdc_e($u['username']) ?>">
                                    Edit
                                </button>
                                <form method="POST" action="settings.php?section=users" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn-sm danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Add / Edit User Modal -->
    <div class="modal-overlay" id="userModalOverlay">
        <div class="modal-box">
            <div class="modal-head">
                <h3 id="userModalTitle">Add User</h3>
                <button type="button" class="modal-close" id="modalCloseBtn" aria-label="Close">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <form id="userForm" method="POST" action="settings.php?section=users">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="save">
                    <input type="hidden" name="user_id" id="f_user_id" value="">

                    <div class="form-group">
                        <label for="f_userlegalname">Legal Name</label>
                        <input type="text" id="f_userlegalname" name="userlegalname" placeholder="e.g. Dr. Amina Yusuf" required>
                    </div>

                    <div class="form-group">
                        <label for="f_role">Role</label>
                        <select id="f_role" name="role" required>
                            <option value="" disabled selected>Select role</option>
                            <?php foreach (ROLE_OPTIONS as $value => $label): ?>
                                <option value="<?= tdc_e($value) ?>">
                                    <?= tdc_e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="doctorProfileGroup" hidden>
                        <label for="f_doctor">Doctor Profile</label>
                        <select id="f_doctor" name="DoctorID">
                            <option value="">Select doctor profile</option>
                            <?php foreach ($doctorProfiles as $doctor): ?>
                                <option value="<?= (int)$doctor['DoctorID'] ?>"><?= tdc_e($doctor['DoctorName']) ?><?= $doctor['UserID'] ? ' (linked)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="f_username">Username</label>
                        <input type="text" id="f_username" name="username" placeholder="e.g. aminay" required>
                    </div>

                    <div class="form-group">
                        <label for="f_password">Password</label>
                        <input type="password" id="f_password" name="password" placeholder="••••••••" autocomplete="new-password">
                        <div class="modal-hint" id="passwordHint">Minimum 8 characters.</div>
                    </div>

                    <div class="form-group">
                        <label for="f_confirm_password">Confirm Password</label>
                        <input type="password" id="f_confirm_password" name="confirm_password" placeholder="••••••••" autocomplete="new-password">
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="modalCancelBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php endif; ?>

</main>

<div id="js-toast" role="alert" aria-live="assertive">
    <svg viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.03-9.78a.75.75 0 00-1.06-1.06L8.75 10.44l-1.72-1.72a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.75-4.75z" clip-rule="evenodd"/>
    </svg>
    <span id="js-toast-msg"></span>
</div>

<script>
(function(){
    const nav = document.getElementById('topnav');
    const items = Array.from(nav.querySelectorAll('.nav-item[data-menu]'));

    function closeAll(except){
        items.forEach(function(item){
            if(item !== except){ item.classList.remove('open'); }
        });
    }

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

    document.addEventListener('click', function(){
        closeAll(null);
    });

    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){ closeAll(null); }
    });
})();

let toastTimer = null;
function showToast(message){
    const toast = document.getElementById('js-toast');
    document.getElementById('js-toast-msg').textContent = message;
    clearTimeout(toastTimer);
    toast.classList.add('show');
    toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
}

<?php if ($section === 'users'): ?>
(function(){
    const overlay    = document.getElementById('userModalOverlay');
    const modalTitle = document.getElementById('userModalTitle');
    const form       = document.getElementById('userForm');
    const fUserId    = document.getElementById('f_user_id');
    const fName      = document.getElementById('f_userlegalname');
    const fRole      = document.getElementById('f_role');
    const fUsername  = document.getElementById('f_username');
    const fDoctor    = document.getElementById('f_doctor');
    const doctorGroup = document.getElementById('doctorProfileGroup');
    const fPassword  = document.getElementById('f_password');
    const fConfirm   = document.getElementById('f_confirm_password');
    const passHint   = document.getElementById('passwordHint');

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }
    function syncDoctorField(){ const show=fRole.value==='doctoruser'; doctorGroup.hidden=!show; fDoctor.required=show; if(!show) fDoctor.value=''; }
    fRole.addEventListener('change', syncDoctorField);

    document.getElementById('addUserBtn').addEventListener('click', function(){
        form.reset();
        fUserId.value = '';
        modalTitle.textContent = 'Add User';
        passHint.textContent = 'Minimum 8 characters.';
        fPassword.required = true;
        fConfirm.required = true;
        syncDoctorField();
        openModal();
    });

    document.querySelectorAll('.edit-user-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fUserId.value   = btn.dataset.id;
            fName.value     = btn.dataset.name;
            fRole.value     = btn.dataset.role;
            fUsername.value = btn.dataset.username;
            fDoctor.value   = btn.dataset.doctor || '';
            syncDoctorField();
            modalTitle.textContent = 'Edit User';
            passHint.textContent = 'Leave blank to keep the current password.';
            fPassword.required = false;
            fConfirm.required = false;
            openModal();
        });
    });

    document.getElementById('modalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('modalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeModal();
    });

    form.addEventListener('submit', function(e){
        if (fPassword.value !== '' && fPassword.value !== fConfirm.value) {
            e.preventDefault();
            showToast('Password and confirmation do not match.');
        }
    });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') !== 'delete'): ?>
    // Reopen the modal with the previously entered values after a failed save
    fUserId.value   = <?= json_encode($old['user_id']) ?>;
    fName.value     = <?= json_encode($old['userlegalname']) ?>;
    fRole.value     = <?= json_encode($old['role']) ?>;
    fUsername.value = <?= json_encode($old['username']) ?>;
    fDoctor.value   = <?= json_encode($old['DoctorID']) ?>;
    syncDoctorField();
    modalTitle.textContent = fUserId.value ? 'Edit User' : 'Add User';
    passHint.textContent = fUserId.value ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.';
    fPassword.required = fUserId.value === '';
    fConfirm.required  = fUserId.value === '';
    openModal();
    <?php endif; ?>

    <?php if ($justSaved || $justDeleted): ?>
    showToast(<?= $justSaved ? json_encode('User saved successfully.') : json_encode('User deleted successfully.') ?>);
    if (window.history.replaceState) {
        window.history.replaceState({}, document.title, 'settings.php?section=users');
    }
    <?php endif; ?>
})();
<?php endif; ?>
</script>

</body>
</html>
