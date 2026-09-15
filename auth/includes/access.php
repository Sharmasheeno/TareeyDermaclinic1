<?php
declare(strict_types=1);

const TDC_ROLES = [
    'superuser' => 'SuperAdmin',
    'receptionuser' => 'Receptionist',
    'doctoruser' => 'Doctor',
    'pharmacyuser' => 'Pharmacist',
    'labuser' => 'Laboratory Staff',
];

function tdc_role_name(?string $roleKey = null): string
{
    global $pdo;
    $roleKey = $roleKey ?? (string) ($_SESSION['role'] ?? '');
    if (isset(TDC_ROLES[$roleKey])) return TDC_ROLES[$roleKey];
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare('SELECT RoleName FROM Roles WHERE RoleKey=?');
            $stmt->execute([$roleKey]);
            $name = $stmt->fetchColumn();
            if ($name) return (string) $name;
        } catch (PDOException) {}
    }
    return $roleKey !== '' ? $roleKey : 'Unassigned';
}

function tdc_is_root_superadmin(): bool
{
    if (!empty($_SESSION['is_root'])) return true;
    return (string) ($_SESSION['role'] ?? '') === 'superuser';
}

/** Acquisition costs are role-exclusive, even for custom permission grants. */
function tdc_can_view_purchase_cost(): bool
{
    return ($_SESSION['role'] ?? '') === 'superuser';
}

function tdc_can(string $permissionKey): bool
{
    global $pdo;
    if (tdc_is_root_superadmin()) return true;
    if (empty($_SESSION['user_id']) || !isset($pdo)) return false;
    static $permissionCache = [];
    $userId = (int) $_SESSION['user_id'];
    if (!array_key_exists($userId, $permissionCache)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT p.PermissionKey FROM users u
                 JOIN Roles r ON r.RoleID=u.role_id AND r.IsActive=1
                 JOIN RolePermissions rp ON rp.RoleID=r.RoleID
                 JOIN Permissions p ON p.PermissionID=rp.PermissionID
                 WHERE u.id=? AND u.is_active=1'
            );
            $stmt->execute([$userId]);
            $permissionCache[$userId] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        } catch (PDOException) {
            $legacy = [
                'superuser' => ['*'],
                'receptionuser' => ['dashboard.view','reception.view','patients.view'],
                'doctoruser' => ['dashboard.view','doctors.view','doctor.workspace'],
                'pharmacyuser' => ['dashboard.view','pharmacy.view'],
                'labuser' => ['dashboard.view','laboratory.view'],
            ];
            $permissionCache[$userId] = array_fill_keys($legacy[$_SESSION['role'] ?? ''] ?? [], true);
        }
    }
    return isset($permissionCache[$userId]['*']) || isset($permissionCache[$userId][$permissionKey]);
}

function tdc_has_any_permission(array $permissionKeys): bool
{
    foreach ($permissionKeys as $permissionKey) if (tdc_can($permissionKey)) return true;
    return false;
}

function tdc_require_permission(string $permissionKey): void
{
    if (!tdc_can($permissionKey)) tdc_forbidden();
}

function tdc_audit(PDO $pdo, string $eventType, string $entityType, ?string $entityId, string $summary, ?array $changes = null): void
{
    $stmt = $pdo->prepare('INSERT INTO AuditLog (ActorUserID,EventType,EntityType,EntityID,Summary,ChangesJson) VALUES (?,?,?,?,?,?)');
    $stmt->execute([(int) ($_SESSION['user_id'] ?? 0) ?: null,$eventType,$entityType,$entityId,$summary,$changes ? json_encode($changes, JSON_UNESCAPED_SLASHES) : null]);
}

function tdc_payment_methods(PDO $pdo, bool $includeInactive = false): array
{
    try {
        $sql = 'SELECT PaymentMethodID,MethodName,Description,IsActive FROM PaymentMethods' . ($includeInactive ? '' : ' WHERE IsActive=1') . ' ORDER BY DisplayOrder,MethodName';
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException) {
        return array_map(static fn(string $name): array => ['PaymentMethodID'=>0,'MethodName'=>$name,'Description'=>null,'IsActive'=>1], ['Cash','Card','Mobile Money','Bank','Other']);
    }
}

function tdc_can_access(string $page, ?string $role = null): bool
{
    if (tdc_is_root_superadmin()) return true;
    $pagePermissions = [
        'home.php' => ['dashboard.view'],
        'reception.php' => ['reception.view'],
        'doctors.php' => ['doctors.view','doctor.workspace'],
        'patients.php' => ['patients.view'],
        'laboratory.php' => ['laboratory.view'],
        'pharmacy.php' => ['pharmacy.view'],
        'accounting.php' => ['accounting.view'],
        'reports.php' => ['reports.view'],
        'setup.php' => ['setup.view'],
        'settings.php' => ['setup.view'],
    ];
    return isset($pagePermissions[$page]) && tdc_has_any_permission($pagePermissions[$page]);
}

function tdc_navigation(array $items): array
{
    $items = array_values(array_filter($items, static function (array $item): bool {
        // Patient registration stays inside Reception for reception staff.
        return tdc_can_access($item['href'])
            && !(($_SESSION['role'] ?? '') === 'receptionuser' && $item['href'] === 'patients.php');
    }));
    if (($_SESSION['role'] ?? '') === 'doctoruser') {
        foreach ($items as &$item) {
            if ($item['href'] === 'doctors.php') $item['label'] = 'Doctor Workspace';
        }
        unset($item);
    }
    return $items;
}

function tdc_forbidden(): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access denied</title><body><h1>Access denied</h1><p>This area is not available for your role.</p><a href="home.php">Return to dashboard</a></body></html>';
    exit;
}

function tdc_require_access(): void
{
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        header('Location: ../auth.php');
        exit;
    }
    require_once __DIR__ . '/../../db.php';
    // Reload identity so deleted accounts and changed permissions apply immediately.
    $stmt = $pdo->prepare('SELECT id,username,userlegalname,role,role_id,is_active,is_root FROM users WHERE id=?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth.php');
        exit;
    }
    foreach (['username', 'userlegalname', 'role', 'role_id', 'is_root'] as $field) {
        $_SESSION[$field] = $user[$field];
    }
    $notificationId = (string) ($_GET['notification'] ?? '');
    if ($notificationId !== '' && ctype_digit($notificationId)) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE Notifications SET IsRead=1
                 WHERE NotificationID=? AND (UserID=? OR (UserID IS NULL AND RoleTarget=?))'
            );
            $stmt->execute([(int) $notificationId, (int) $_SESSION['user_id'], (string) $_SESSION['role']]);
        } catch (PDOException $exception) {
            error_log('[NOTIFICATIONS] '.$exception->getMessage());
        }
    }
    $page = basename($_SERVER['SCRIPT_NAME']);
    if (!tdc_can_access($page)) {
        tdc_forbidden();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
