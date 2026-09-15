<?php
$notificationEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$notificationRows = [];
try {
    $stmt = $pdo->prepare('SELECT NotificationID,Title,Message,Link,IsRead,CreatedAt FROM Notifications WHERE UserID=? OR (UserID IS NULL AND RoleTarget=?) ORDER BY IsRead ASC,CreatedAt DESC LIMIT 8');
    $stmt->execute([$_SESSION['user_id'],$_SESSION['role']]);
    $notificationRows = $stmt->fetchAll();
} catch (PDOException $exception) {
    error_log('[NOTIFICATIONS] '.$exception->getMessage());
}
$notificationUnreadCount = count(array_filter($notificationRows, static fn(array $row): bool => !(bool) $row['IsRead']));
?>
<?php if ($notificationUnreadCount > 0): ?>
<script>document.currentScript.closest('.nav-item')?.classList.add('has-notifications');</script>
<?php endif; ?>
<div class="notif-title">Notifications</div>
<?php if (!$notificationRows): ?>
<div class="notif-empty">You're all caught up.</div>
<?php else: foreach ($notificationRows as $notification): ?>
<?php
$notificationLink = (string) ($notification['Link'] ?: 'home.php');
if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $notificationLink) || str_starts_with($notificationLink, '//')) $notificationLink = 'home.php';
$notificationLink .= (str_contains($notificationLink, '?') ? '&' : '?') . 'notification=' . (int) $notification['NotificationID'];
?>
<a class="notification-item<?= $notification['IsRead'] ? '' : ' unread' ?>" href="<?= $notificationEscape($notificationLink) ?>">
    <strong><?= $notificationEscape($notification['Title']) ?></strong>
    <span><?= $notificationEscape($notification['Message']) ?></span>
    <time><?= $notificationEscape(date('d M, H:i',strtotime($notification['CreatedAt']))) ?></time>
</a>
<?php endforeach; endif; ?>
