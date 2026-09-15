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
const DEFAULT_SUPERADMIN_PASSWORD = 'SuperAdmin@123';

$resetPassword = in_array('--reset-password', $argv, true);

try {
    $stmt = $pdo->prepare(
        'SELECT id, username, role
         FROM users
         WHERE username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => DEFAULT_SUPERADMIN_USERNAME]);
    $existingUser = $stmt->fetch();

    $passwordHash = password_hash(DEFAULT_SUPERADMIN_PASSWORD, PASSWORD_DEFAULT);

    if ($existingUser) {
        if ($resetPassword) {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET userlegalname = :userlegalname,
                     role = :role,
                     password = :password
                 WHERE id = :id'
            );
            $stmt->execute([
                'userlegalname' => DEFAULT_SUPERADMIN_NAME,
                'role'          => DEFAULT_SUPERADMIN_ROLE,
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
            'INSERT INTO users (userlegalname, role, username, password)
             VALUES (:userlegalname, :role, :username, :password)'
        );
        $stmt->execute([
            'userlegalname' => DEFAULT_SUPERADMIN_NAME,
            'role'          => DEFAULT_SUPERADMIN_ROLE,
            'username'      => DEFAULT_SUPERADMIN_USERNAME,
            'password'      => $passwordHash,
        ]);

        echo "Default Superadmin created successfully.\n";
    }

    echo "\nLogin details:\n";
    echo "Username: " . DEFAULT_SUPERADMIN_USERNAME . "\n";
    echo "Password: " . DEFAULT_SUPERADMIN_PASSWORD . "\n";
    echo "Role: Superadmin\n";
} catch (PDOException $e) {
    error_log('[CREATE DEFAULT SUPERADMIN ERROR] ' . $e->getMessage());
    fwrite(STDERR, "Failed to create default Superadmin. Check the database connection and users table.\n");
    exit(1);
}
