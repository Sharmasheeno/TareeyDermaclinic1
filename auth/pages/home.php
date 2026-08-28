<?php
/**
 * auth/pages/home.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Authenticated Dashboard
 * ---------------------------------------------------------------------
 * Security controls:
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Self-contained, CSRF-token-checked logout (no separate endpoint)
 *   - Session gate: unauthenticated requests never reach the markup
 *   - All session-derived output is escaped before hitting HTML
 * ---------------------------------------------------------------------
 */
declare(strict_types=1);

// ---------------------------------------------------------------------
// 1. Secure session bootstrap
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// 2. Logout — self-contained, CSRF-guarded (?logout=1&csrf=...)
// ---------------------------------------------------------------------
if (isset($_GET['logout'])) {
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

// ---------------------------------------------------------------------
// 3. Auth gate — anonymous visitors never see the dashboard markup
// ---------------------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

// require_once __DIR__ . '/../../db.php'; // uncomment once a widget needs live data

// ---------------------------------------------------------------------
// 4. View data — derived safely from the session, never from raw input
// ---------------------------------------------------------------------

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

$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');

// ---------------------------------------------------------------------
// 5. Primary navigation — single source of truth.
//    Flat, single-link items only: no nested/dropdown menus here.
//    Order and membership per spec: Dashboard, Reception, Doctors,
//    Patients, Laboratory, Pharmacy, Accounting, Reports, Settings.
// ---------------------------------------------------------------------
$navItems = [
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

// Resolve the active item from the actual requested script, so the
// component stays correct if it's ever reused outside home.php.
$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'home.php'));
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
    .notif-menu{ right:0; left:auto; min-width:260px; }
    .notif-menu .notif-title{
        padding:10px 16px 8px; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:var(--navy-55);
    }
    .notif-empty{ padding:20px 16px 22px; font-size:13px; color:var(--navy-55); text-align:center; }
    .page-body{ padding:40px 32px; }
    .welcome-eyebrow{ font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--navy-55); margin-bottom:8px; }
    .welcome-title{ font-size:26px; font-weight:700; color:var(--navy); }
    .welcome-sub{ font-size:14px; color:var(--navy-55); margin-top:6px; }

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
                <div class="avatar"><?= htmlspecialchars($avatarLetters, ENT_QUOTES, 'UTF-8') ?></div>
                <span class="profile-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>

    <nav class="menu-bar">
        <ul class="nav-items">
            <?php foreach ($navItems as $item): ?>
                <li class="nav-item<?= $item['href'] === $currentPage ? ' active' : '' ?>">
                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav-link">
                        <svg viewBox="0 0 20 20"><?= $item['icon'] ?></svg>
                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
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
    <div class="welcome-eyebrow">Dashboard</div>
    <div class="welcome-title">Welcome back, <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="welcome-sub">Here's what's happening at Tarey Derma Clinic today.</div>
</main>

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
</script>

</body>
</html>