<?php
/**
 * db.php
 * ---------------------------------------------------------------
 * Tarey Derma Clinic — Database Connection Layer (PHP only)
 * Returns a single shared PDO instance ($pdo). Real prepared
 * statements are forced (no emulation) to harden against SQLi.
 * ---------------------------------------------------------------
 */
declare(strict_types=1);

// TODO: move these off-web-root (.env / non-public config) before production.
const DB_HOST    = '127.0.0.1';
const DB_NAME    = 'tareydermaclinic';
const DB_USER    = 'root';   // <-- update to your MySQL user
const DB_PASS    = '';       // <-- update to your MySQL password
const DB_CHARSET = 'utf8mb4';

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
    http_response_code(500);
    die('A system error occurred. Please try again later.');
}