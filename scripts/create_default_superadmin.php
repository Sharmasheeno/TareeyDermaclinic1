<?php
/**
 * One-time local setup script for creating the default Superadmin user.
 *
 * Run from the project root:
 *   C:\xampp\php\php.exe scripts\create_default_superadmin.php
 *
 * To reset the password for the same username:
 *   C:\xampp\php\php.exe scripts\create_default_superadmin.php --reset-password
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once __DIR__ . '/../db.php';

const DEFAULT_SUPERADMIN_NAME = 'Default Superadmin';
const DEFAULT_SUPERADMIN_ROLE = 'superuser';
const DEFAULT_SUPERADMIN_USERNAME = 'superadmin';

$resetPassword = in_array('--reset-password', $argv, true);
$suppliedPassword = getenv('TDC_BOOTSTRAP_PASSWORD');
$bootstrapPassword = (string) ($suppliedPassword ?: bin2hex(random_bytes(16)));
if ($suppliedPassword !== false && (
    mb_strlen($bootstrapPassword) < 12
    || !preg_match('/[A-Z]/', $bootstrapPassword)
    || !preg_match('/[a-z]/', $bootstrapPassword)
    || !preg_match('/[0-9]/', $bootstrapPassword)
    || !preg_match('/[^A-Za-z0-9]/', $bootstrapPassword)
)) {
    fwrite(STDERR, "TDC_BOOTSTRAP_PASSWORD must be at least 12 characters and include upper, lower, number, and symbol characters.\n");
    exit(1);
}

try {
    $roleId = (int) $pdo->query("SELECT RoleID FROM roles WHERE RoleKey='superuser' LIMIT 1")->fetchColumn();
    if ($roleId < 1) {
        throw new RuntimeException('Run scripts/migrate_setup_rbac.php before creating the root SuperAdmin.');
    }
    $stmt = $pdo->prepare(
        'SELECT id, username, role
         FROM users
         WHERE username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => DEFAULT_SUPERADMIN_USERNAME]);
    $existingUser = $stmt->fetch();

    $passwordHash = password_hash($bootstrapPassword, PASSWORD_DEFAULT);

    if ($existingUser) {
        if ($resetPassword) {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET userlegalname = :userlegalname,
                     role = :role,
                     role_id = :role_id,
                     is_active = 1,
                     is_root = 1,
                     password = :password
                 WHERE id = :id'
            );
            $stmt->execute([
                'userlegalname' => DEFAULT_SUPERADMIN_NAME,
                'role'          => DEFAULT_SUPERADMIN_ROLE,
                'role_id'       => $roleId,
                'password'      => $passwordHash,
                'id'            => $existingUser['id'],
            ]);

            echo "Default Superadmin password was reset.\n";
        } else {
            echo "Default Superadmin already exists. No changes made.\n";
            echo "Use --reset-password if you want to reset its password.\n";
        }
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (userlegalname,role,role_id,username,password,is_active,is_root)
             VALUES (:userlegalname,:role,:role_id,:username,:password,1,1)'
        );
        $stmt->execute([
            'userlegalname' => DEFAULT_SUPERADMIN_NAME,
            'role'          => DEFAULT_SUPERADMIN_ROLE,
            'role_id'       => $roleId,
            'username'      => DEFAULT_SUPERADMIN_USERNAME,
            'password'      => $passwordHash,
        ]);

        echo "Default Superadmin created successfully.\n";
    }

    echo "\nLogin username: " . DEFAULT_SUPERADMIN_USERNAME . "\n";
    if ($suppliedPassword === false) echo "Generated password (store securely now): " . $bootstrapPassword . "\n";
    else echo "Password supplied securely through TDC_BOOTSTRAP_PASSWORD.\n";
    echo "Role: Superadmin\n";
} catch (Throwable $e) {
    error_log('[CREATE DEFAULT SUPERADMIN ERROR] ' . $e->getMessage());
    fwrite(STDERR, "Failed to create default Superadmin. Check the database connection and users table.\n");
    exit(1);
}
