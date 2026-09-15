<?php
declare(strict_types=1);

const TDC_ROLES = [
    'superuser' => 'SuperAdmin',
    'receptionuser' => 'Receptionist',
    'doctoruser' => 'Doctor',
    'pharmacyuser' => 'Pharmacist',
    'labuser' => 'Laboratory Staff',
];

function tdc_can_access(string $page, ?string $role = null): bool
{
    $role = $role ?? (string) ($_SESSION['role'] ?? '');
    $pages = [
        'superuser' => ['home.php', 'reception.php', 'doctors.php', 'patients.php', 'laboratory.php', 'pharmacy.php', 'accounting.php', 'reports.php', 'settings.php'],
        'receptionuser' => ['home.php', 'reception.php', 'patients.php'],
        'doctoruser' => ['home.php', 'doctors.php'],
        'pharmacyuser' => ['home.php', 'pharmacy.php'],
        'labuser' => ['home.php', 'laboratory.php'],
    ];
    return in_array($page, $pages[$role] ?? [], true);
}

function tdc_navigation(array $items): array
{
    return array_values(array_filter($items, static function (array $item): bool {
        // Patient registration stays inside Reception for reception staff.
        return tdc_can_access($item['href'])
            && !(($_SESSION['role'] ?? '') === 'receptionuser' && $item['href'] === 'patients.php');
    }));
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
    $stmt = $pdo->prepare('SELECT id, username, userlegalname, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../auth.php');
        exit;
    }
    foreach (['username', 'userlegalname', 'role'] as $field) {
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
