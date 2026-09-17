<?php
declare(strict_types=1);
/**
 * db.php — Tarey Derma Clinic database connection (THE ONE CONFIG FILE)
 * ----------------------------------------------------------------------
 * Configuration priority:
 *   1. db.local.php (gitignored, for local/server-specific credentials)
 *   2. Environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET)
 *   3. Safe placeholder defaults (will fail gracefully, never expose secrets)
 * ----------------------------------------------------------------------
 * LOCAL DEVELOPMENT:
 *   Create db.local.php with your XAMPP/MySQL credentials (see db.local.php.example).
 *   The file is gitignored so credentials never leave your machine.
 * ----------------------------------------------------------------------
 * PRODUCTION (InfinityFree):
 *   Set environment variables in the hosting panel, or create a db.local.php
 *   on the server (not in Git) with production credentials.
 * ----------------------------------------------------------------------
 * SECURITY:
 *   - Never display this file, its contents, or PDO errors to visitors.
 *   - Connection failures are logged server-side and return a generic 500.
 *   - utf8mb4 and secure PDO configuration (real prepared statements).
 * ----------------------------------------------------------------------
 */

// 1. Load local configuration file if it exists (gitignored)
$localConfig = __DIR__ . '/db.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// 2. Fall back to environment variables, then safe placeholders
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
