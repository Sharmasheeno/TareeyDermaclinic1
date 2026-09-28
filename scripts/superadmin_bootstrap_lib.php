<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }

function tdc_bootstrap_validate_password(string $password): void
{
    // PASSWORD_DEFAULT currently uses bcrypt: reject silent truncation beyond 72 bytes.
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
        || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        throw new InvalidArgumentException('Password must be 12–72 bytes with uppercase, lowercase, a number and a symbol.');
    }
}

function tdc_bootstrap_lock_sql(): string
{
    // Serialize setup attempts through the existing system role.
    return "SELECT RoleID FROM roles WHERE RoleKey='superuser' AND IsActive=1 FOR UPDATE";
}

function tdc_bootstrap_insert_sql(string $passwordExpression): string
{
    return "INSERT INTO users (userlegalname, role, role_id, username, password, is_active, is_root)
SELECT 'System SuperAdmin', 'superuser', r.RoleID, 'superadmin', {$passwordExpression}, 1, 1
FROM roles r WHERE r.RoleKey='superuser' AND r.IsActive=1
AND NOT EXISTS (
    SELECT 1 FROM users u LEFT JOIN roles ur ON ur.RoleID=u.role_id
    WHERE u.username='superadmin' OR u.is_root=1 OR u.role='superuser' OR ur.RoleKey='superuser'
)";
}

function tdc_bootstrap_create(PDO $connection, string $hash): bool
{
    $connection->beginTransaction();
    try {
        $role = $connection->query(tdc_bootstrap_lock_sql());
        $roleId = $role->fetchColumn();
        $role->closeCursor();
        if (!$roleId) throw new RuntimeException('Import the fresh schema first; the active SuperAdmin role is missing.');
        $insert = $connection->prepare(tdc_bootstrap_insert_sql(':password'));
        $insert->execute(['password' => $hash]);
        $created = $insert->rowCount() === 1;
        $connection->commit();
        return $created;
    } catch (Throwable $e) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $e;
    }
}
