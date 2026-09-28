<?php
/** Create the first root account once; never reset or promote an existing user. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require_once __DIR__ . '/superadmin_bootstrap_lib.php';

$output = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: php scripts/create_default_superadmin.php [--sql-output=PRIVATE_FILE.sql]\n";
        echo "No option: create once in the database configured by db.php.\n";
        echo "--sql-output: generate a private phpMyAdmin import file WITHOUT connecting to a database.\n";
        echo "Optional password: TDC_BOOTSTRAP_PASSWORD environment variable; otherwise securely generated.\n";
        exit(0);
    }
    if (str_starts_with($argument, '--sql-output=') && $output === null) {
        $output = substr($argument, strlen('--sql-output='));
    } else {
        fwrite(STDERR, "Unknown option. Use --help. Password resets are not supported.\n"); exit(1);
    }
}

try {
    $supplied = getenv('TDC_BOOTSTRAP_PASSWORD');
    $password = $supplied === false ? 'Aa1!' . bin2hex(random_bytes(18)) : $supplied;
    tdc_bootstrap_validate_password($password);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($output !== null) {
        if ($output === '' || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'sql') {
            throw new RuntimeException('Choose a new private .sql file path.');
        }
        $file = @fopen($output, 'x');
        if (!$file) throw new RuntimeException('Cannot create output file (path missing or file already exists).');
        $sql = "-- PRIVATE: import once in phpMyAdmin AFTER the fresh schema. Never upload to public web space.\n"
            . "-- Final result: created=1 means success; created=0 means an existing account or missing role.\n"
            . "START TRANSACTION;\n" . tdc_bootstrap_lock_sql() . ";\n"
            . tdc_bootstrap_insert_sql("'" . $hash . "'") . ";\n"
            . "SET @tdc_root_created = ROW_COUNT();\nCOMMIT;\nSELECT @tdc_root_created AS created;\n";
        try {
            if (fwrite($file, $sql) !== strlen($sql)) throw new RuntimeException('Could not write complete SQL file. Do not import it.');
        } finally { fclose($file); }
        echo "Private SQL file prepared. No database was changed.\n";
        echo "Import it into the fresh database; verify created=1, then delete the private SQL file.\n";
        echo "Credentials below work only if that import creates the account.\n";
    } else {
        require __DIR__ . '/../db.php';
        if (!tdc_bootstrap_create($pdo, $hash)) {
            echo "An administrator or the superadmin username already exists. No changes made; no password reset.\n";
            exit(0);
        }
        echo "SuperAdmin created successfully.\n";
    }
    echo "Username: superadmin\n";
    echo $supplied === false
        ? "Generated password (save privately now): {$password}\n"
        : "Password: the value supplied through TDC_BOOTSTRAP_PASSWORD (not displayed).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Setup failed: ' . ($e instanceof PDOException ? 'Database operation failed; check the fresh schema and database configuration.' : $e->getMessage()) . "\n");
    exit(1);
}
