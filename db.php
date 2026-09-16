<?php
declare(strict_types=1);
/**
 * db.php — Tarey Derma Clinic database connection (THE ONE CONFIG FILE)
 * ---------------------------------------------------------------------
 * EDIT THE FIVE CONSTANTS BELOW AFTER UPLOADING TO INFINITYFREE.
 *
 * Where to find the values (InfinityFree client area):
 *   DB_HOST -> "MySQL Host Name"      (e.g. sql123.infinityfree.com)
 *   DB_NAME -> "MySQL Database Name"  (e.g. if0_12345678_tareydermaclinic)
 *   DB_USER -> "MySQL User Name"      (e.g. if0_12345678)
 *   DB_PASS -> "MySQL Password"       (the password you set for the DB)
 *
 * Each constant may also be supplied through an environment variable of
 * the same name (getenv), so credentials can live outside this file if
 * your host supports it. Values defined here are used as fallbacks.
 *
 * Never display this file, its contents, or PDO errors to visitors:
 * connection failures are logged server-side and return a generic 500.
 * ---------------------------------------------------------------------
 */

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'sqlXXX.infinityfree.com');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'if0_XXXXXXX_tareydermaclinic');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'if0_XXXXXXX');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', (string) (getenv('DB_PASS') ?: ''));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // force real prepared statements
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Never leak DSN/credentials/stack traces to the client.
    error_log('[DB CONNECTION ERROR] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    die('A system error occurred. Please try again later.');
}
