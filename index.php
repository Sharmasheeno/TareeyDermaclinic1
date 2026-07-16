<?php
/**
 * index.php
 * ---------------------------------------------------------------
 * Tarey Derma Clinic — Front Controller / Session Gate
 * Routes to the login page or the authenticated Home page based
 * on session state. No markup here — pure redirect logic.
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

if (!empty($_SESSION['user_id'])) {
    header('Location: auth/pages/home.php');
} else {
    header('Location: auth/auth.php');
}
exit;