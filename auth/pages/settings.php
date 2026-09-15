<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/../includes/access.php';
tdc_require_access();
tdc_require_permission('setup.view');

$target = (string) ($_GET['section'] ?? '') === 'users'
    ? 'setup.php?section=users'
    : 'setup.php';
header('Location: ' . $target, true, 302);
exit;
