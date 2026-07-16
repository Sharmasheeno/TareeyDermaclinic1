<?php
/**
 * auth/auth.php
 * ---------------------------------------------------------------
 * Tarey Derma Clinic — Login (self-posting view + handler)
 * OWASP coverage: prepared statements (injection), password_verify
 * + session regeneration (broken auth / fixation), CSRF token,
 * generic error messages (no user enumeration), brute-force
 * throttling, output escaping (XSS), secure session cookies.
 * ---------------------------------------------------------------
 */
declare(strict_types=1);

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

require_once __DIR__ . '/../db.php';

// Already authenticated -> skip straight to Home
if (!empty($_SESSION['user_id'])) {
    header('Location: pages/home.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const MAX_ATTEMPTS    = 5;
const LOCKOUT_SECONDS = 60;

$error       = '';
$oldUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $lockoutUntil = $_SESSION['login_lockout_until'] ?? 0;

    if ($lockoutUntil > time()) {
        $error = 'Too many failed attempts. Please try again in ' . ($lockoutUntil - time()) . 's.';
    } elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Your session has expired. Please refresh and try again.';
    } else {
        $username    = trim((string)($_POST['username'] ?? ''));
        $password    = (string)($_POST['password'] ?? '');
        $oldUsername = $username;

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, userlegalname, role, username, password
                 FROM users WHERE username = :username LIMIT 1'
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true); // prevent session fixation
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['userlegalname'] = $user['userlegalname'];
                unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);

                header('Location: pages/home.php');
                exit;
            }

            // Generic message — never reveal whether username or password was wrong
            $error = 'Invalid username or password.';
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['login_lockout_until'] = time() + LOCKOUT_SECONDS;
                $_SESSION['login_attempts']      = 0;
                $error = 'Too many failed attempts. Please try again in ' . LOCKOUT_SECONDS . 's.';
            }
        }
    }
    // Rotate CSRF token after every POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Google Sans', sans-serif;
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #ffffff;
            border: 2px solid #2E3192;
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-header { text-align: center; margin-bottom: 28px; }
        .login-header .logo-icon img {
            width: 120px; height: 120px;
            object-fit: contain; display: block; margin: 0 auto;
            mix-blend-mode: multiply;
            filter: brightness(1.15) contrast(1.3);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: #2E3192; margin-bottom: 6px;
        }
        .input-wrapper { position: relative; }
        .input-wrapper > svg {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            width: 16px; height: 16px;
            color: rgba(46, 49, 146, 0.55); fill: rgba(46, 49, 146, 0.55); pointer-events: none;
        }
        .form-group input {
            width: 100%;
            padding: 11px 12px 11px 38px;
            border: 2px solid rgba(46, 49, 146, 0.3);
            font-size: 14px; font-family: 'Google Sans', sans-serif;
            color: #2E3192; background: #ffffff;
            outline: none; transition: border-color 0.15s;
        }
        .form-group input::placeholder { color: rgba(46, 49, 146, 0.45); }
        #password { padding-right: 44px; }
        .form-group input:focus { border-color: #F15A24; }
        .toggle-pw {
            position: absolute; right: 10px; top: 50%;
            transform: translateY(-50%);
            cursor: pointer; background: none; border: none;
            padding: 4px; margin: 0; color: rgba(46, 49, 146, 0.55);
            display: flex; align-items: center; justify-content: center;
        }
        .toggle-pw:hover { color: #2E3192; }
        .toggle-pw svg { width: 18px; height: 18px; display: block; }
        .error-msg {
            display: flex; align-items: center; gap: 8px;
            background: #ffffff; border: 2px solid #2E3192;
            color: #2E3192; font-size: 13px; font-weight: 500;
            padding: 10px 14px; margin-bottom: 20px;
        }
        .error-msg svg { width: 16px; height: 16px; flex-shrink: 0; }
        .btn-login {
            width: 100%; padding: 11px;
            background: #2E3192; color: #ffffff;
            font-size: 14px; font-weight: 600;
            border: 2px solid #2E3192; cursor: pointer;
            letter-spacing: 0.02em;
            transition: background 0.12s, color 0.12s, border-color 0.12s;
        }
        .btn-login:hover { background: #F15A24; color: #ffffff; border-color: #F15A24; }
        #js-toast {
            position: fixed; bottom: 28px; left: 50%;
            transform: translateX(-50%) translateY(20px);
            display: flex; align-items: center; gap: 8px;
            background: #ffffff; border: 2px solid #2E3192;
            color: #2E3192; font-size: 13px; font-weight: 500;
            padding: 10px 18px; white-space: nowrap;
            z-index: 9999; opacity: 0; pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        #js-toast svg { width: 16px; height: 16px; flex-shrink: 0; }
        #js-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="logo-icon">
            <img src="uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic Logo">
        </div>
    </div>

    <?php if ($error !== ''): ?>
    <div class="error-msg">
        <svg viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/>
        </svg>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form id="loginForm" method="POST" action="" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label for="username">Username</label>
            <div class="input-wrapper">
                <svg viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/>
                </svg>
                <input type="text" id="username" name="username"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($oldUsername, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username" required>
            </div>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <div class="input-wrapper">
                <svg viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/>
                </svg>
                <input type="password" id="password" name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password" required>
                <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password visibility">
                    <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg id="eyeClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
        </div>
        <button type="submit" class="btn-login">Sign In</button>
    </form>
</div>

<div id="js-toast" role="alert" aria-live="assertive">
    <svg viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/>
    </svg>
    <span id="js-toast-msg"></span>
</div>

<script>
    document.getElementById('togglePw').addEventListener('click', function () {
        const pwInput   = document.getElementById('password');
        const eyeOpen   = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');
        if (pwInput.type === 'password') {
            pwInput.type = 'text';
            eyeOpen.style.display = 'none';
            eyeClosed.style.display = 'block';
        } else {
            pwInput.type = 'password';
            eyeOpen.style.display = 'block';
            eyeClosed.style.display = 'none';
        }
    });

    let toastTimer = null;
    function showToast(message) {
        const toast = document.getElementById('js-toast');
        document.getElementById('js-toast-msg').textContent = message;
        clearTimeout(toastTimer);
        toast.classList.add('show');
        toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
    }

    // Client-side quick check only — server (PHP) is the source of truth
    document.getElementById('loginForm').addEventListener('submit', function (e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value.trim();
        if (!username || !password) {
            e.preventDefault();
            showToast('Please enter both username and password.');
        }
    });
</script>
</body>
</html>