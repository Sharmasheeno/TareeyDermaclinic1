<?php
// Disposable integration-test server only. Never available through Apache/PHP-FPM.
declare(strict_types=1);
$schema = getenv('TDC_TEST_DB') ?: '';
$secret = getenv('TDC_TEST_SECRET') ?: '';
if (PHP_SAPI !== 'cli-server' || !preg_match('/^tdc_role_test_[a-f0-9]{12}$/', $schema)
    || strlen($secret) < 32 || !hash_equals($secret, $_SERVER['HTTP_X_TDC_TEST_SECRET'] ?? '')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/auth/auth.php' && !preg_match('#^/auth/pages/(home|reception|patients|doctors|laboratory|pharmacy|accounting|reports|setup)\.php$#', $path)) {
    http_response_code(404); exit;
}
require_once __DIR__ . '/../db.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $schema . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
$query = $pdo->prepare('SELECT id,role FROM users WHERE username=?');
$query->execute([$_SERVER['HTTP_X_TDC_TEST_USER'] ?? 'superuser']);
$user = $query->fetch();
ini_set('session.save_path', sys_get_temp_dir());
session_id('tdchttp' . bin2hex(random_bytes(12)));
session_start();
$_SESSION = $user ? ['user_id'=>$user['id'], 'role'=>$user['role'], 'csrf_token'=>'http-test-token'] : [];
session_write_close();
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
require __DIR__ . '/..' . $path;
