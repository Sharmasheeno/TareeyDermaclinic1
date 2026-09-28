<?php
/** Isolated integration test. Explicit test-server credentials only; never reads db.local.php. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
require __DIR__ . '/superadmin_bootstrap_lib.php';
$dsn = getenv('TDC_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "Set TDC_TEST_DSN to an isolated MySQL/MariaDB test server (no dbname).\n"); exit(1); }
$pdo = new PDO($dsn, getenv('TDC_TEST_USER') ?: 'root', getenv('TDC_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'tdc_install_test_' . bin2hex(random_bytes(8));
$created = false;
$sqlFile = sys_get_temp_dir() . '/tdc-bootstrap-test-' . bin2hex(random_bytes(8)) . '.sql';
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$import = static function (string $sql) use ($pdo): void {
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') {
            $query = $pdo->query($statement);
            $query->closeCursor();
        }
    }
};
try {
    $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
    $created = true;
    $pdo->exec("USE `{$db}`");
    echo 'Test server: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . PHP_EOL;
    $schema = file_get_contents(__DIR__ . '/../database/production_install.sql');
    $check($schema === file_get_contents(__DIR__ . '/../database/infinityfree_fresh.sql'), 'both fresh SQL files are identical');
    $check(!preg_match('/^\s*(DROP|DELETE|TRUNCATE|ALTER|UPDATE|USE|CREATE DATABASE)\b/im', $schema), 'fresh SQL has no destructive statements or follow-up ALTER migrations');
    $import($schema);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $check(count($tables) === 43, 'all 43 application tables import successfully');
    $seeded = ['roles','permissions','rolepermissions','paymentmethods','clinicsettings'];
    $empty = true;
    foreach (array_diff($tables, $seeded) as $table) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 0) $empty = false;
    }
    $check($empty, 'no business records, users or audit entries seeded');
    foreach (['visits'=>'IsFreeConsultation','prescriptions'=>'UnitPriceSnapshot','payments'=>'ServiceAssignmentID','doctors'=>'WorkingDays'] as $table=>$column) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);
        $check((int)$stmt->fetchColumn() === 1, "schema includes {$table}.{$column}");
    }
    $grants = (int)$pdo->query("SELECT COUNT(*) FROM rolepermissions rp JOIN roles r ON r.RoleID=rp.RoleID WHERE r.RoleKey='superuser'")->fetchColumn();
    $check($grants > 0 && $grants === (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn(), 'SuperAdmin has all configured permissions');
    $password = 'Test-Only!7' . bin2hex(random_bytes(12));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $check(tdc_bootstrap_create($pdo, $hash), 'first bootstrap creates administrator');
    $user = $pdo->query("SELECT * FROM users WHERE username='superadmin'")->fetch();
    $check(password_verify($password, $user['password']) && (int)$user['is_root']===1 && (int)$user['is_active']===1 && $user['role']==='superuser', 'stored password verifies and root role is active');
    $check(!tdc_bootstrap_create($pdo, password_hash('Different-Test!7', PASSWORD_DEFAULT)), 'second bootstrap is a no-op');
    $check($pdo->query("SELECT password FROM users WHERE username='superadmin'")->fetchColumn()===$hash, 'repeat preserves original password');
    $pdo->exec("UPDATE users SET username='existing_root', is_active=0");
    $check(!tdc_bootstrap_create($pdo,$hash), 'existing differently named or inactive root is not bypassed');
    $pdo->exec("UPDATE users SET username='superadmin', role='doctoruser', role_id=NULL, is_root=0");
    $check(!tdc_bootstrap_create($pdo,$hash), 'existing non-admin username is not promoted');
    $pdo->exec('DELETE FROM users'); // Only the uniquely named disposable database.
    $env = getenv();
    $env['TDC_BOOTSTRAP_PASSWORD'] = $password;
    $process = proc_open([PHP_BINARY, __DIR__.'/create_default_superadmin.php', '--sql-output='.$sqlFile], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $env);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $check(proc_close($process)===0 && is_file($sqlFile), 'offline CLI generates phpMyAdmin SQL without connecting to clinic DB');
    $check(!str_contains($output,$password), 'supplied password is not echoed');
    $privateSql = file_get_contents($sqlFile);
    $check(!str_contains($privateSql,$password), 'SQL contains a password hash, never plaintext');
    $import($privateSql);
    $check((int)$pdo->query('SELECT @tdc_root_created')->fetchColumn()===1, 'phpMyAdmin SQL creates account');
    $check(password_verify($password, $pdo->query("SELECT password FROM users WHERE username='superadmin'")->fetchColumn()), 'offline SQL password verifies');
    $import($privateSql);
    $check((int)$pdo->query('SELECT @tdc_root_created')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===1, 'reimport creates no duplicate and performs no reset');
    foreach (['short','onlylowercasepassword','ABCdef123!'.str_repeat('x',73)] as $weak) {
        try { tdc_bootstrap_validate_password($weak); $check(false,'weak/oversized password rejected'); }
        catch (InvalidArgumentException $e) { $check(true,'weak/oversized password rejected'); }
    }
} catch (Throwable $e) {
    $check(false,$e->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($created && preg_match('/^tdc_install_test_[a-f0-9]{16}$/D',$db)) {
        $pdo->exec("DROP DATABASE `{$db}`");
        echo "Removed disposable test database.\n";
    }
    if (is_file($sqlFile)) unlink($sqlFile);
}
echo "{$failures} failures.\n";
exit($failures ? 1 : 0);
