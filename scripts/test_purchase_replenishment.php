<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

// Disposable-schema purchase integration test. Never touches clinic records.
if (($argv[1] ?? '') === 'worker') {
    $testDb = $argv[2];
    if (!preg_match('/^tdc_purchase_test_[a-f0-9]{12}$/', $testDb)) exit(1);
    $case = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$case['role']]);
    $user = $stmt->fetch();
    ini_set('session.save_path', sys_get_temp_dir());
    session_id('tdcpur' . bin2hex(random_bytes(12)));
    session_start();
    $_SESSION = ['user_id' => $user['id'], 'role' => $case['role'], 'csrf_token' => 'test-token'];
    session_write_close();
    $_GET = $case['get'] ?? [];
    $_POST = $case['post'] ?? [];
    if ($_POST) $_POST['csrf_token'] = 'test-token';
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME'] = '/auth/pages/pharmacy.php';
    ob_start();
    register_shutdown_function(static function () use ($case, $pdo): void {
        $html = ob_get_clean();
        $failures = [];
        $status = http_response_code() ?: 200;
        if ($status !== ($case['status'] ?? 200)) $failures[] = 'status expected ' . ($case['status'] ?? 200) . ' got ' . $status;
        foreach ($case['sql'] ?? [] as [$query, $expected]) {
            try {
                $actual = (string) $pdo->query($query)->fetchColumn();
            } catch (Throwable $e) {
                $failures[] = 'SQL error: ' . $e->getMessage();
                continue;
            }
            if ($actual !== (string) $expected) $failures[] = 'SQL expected ' . $expected . ' got ' . $actual . ' :: ' . $query;
        }
        foreach ($case['contains'] ?? [] as $text) if (strpos($html, $text) === false) $failures[] = 'missing ' . $text;
        if (($last = error_get_last()) && in_array($last['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR])) $failures[] = $last['message'];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode(['case' => $case['name'], 'failures' => $failures]) . PHP_EOL;
        if ($failures) exit(1);
    });
    require __DIR__ . '/../auth/pages/pharmacy.php';
    exit;
}

function baseCase(string $name, string $role, array $post, int $status, array $sql, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'role' => $role,
        'get' => ['section' => 'purchases', 'new' => '1'],
        'post' => $post,
        'status' => $status,
        'sql' => $sql,
    ], $extra);
}

function savePost(array $overrides): array
{
    return array_merge([
        'form_action' => 'save',
        'SupplierName' => 'QA Supplier',
        'PurchaseDate' => '2026-01-15',
        'ItemID' => ['ITM000001'],
        'ItemName' => ['Paracetamol'],
        'Category' => ['Analgesic'],
        'SalesUnit' => ['Tablet'],
        'Quantity' => ['100'],
        'UnitPrice' => ['0.05'],
        'SellingPrice' => ['5.00'],
        'ExpiryDate' => ['2027-12-30'],
        'PurchaseUnit' => [''],
        'ConversionFactor' => ['1'],
        'Discount' => '',
        'VATAmount' => '',
        'AmountPaid' => '5.00',
    ], $overrides);
}

$cases = [];

// 1. Normal replenishment: existing medicine, stock += quantity, no duplicate.
$cases[] = baseCase('normal purchase replenishes existing medicine', 'superuser', savePost([]), 302, [
    ["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'", '150'],
    ["SELECT COUNT(*) FROM Inventory WHERE ItemID='ITM000001'", '1'],
    ["SELECT COUNT(*) FROM Inventory WHERE LOWER(TRIM(ItemName))=LOWER(TRIM('Paracetamol'))", '1'],
    ["SELECT ItemName FROM Purchases LIMIT 1", 'Paracetamol'],
    ["SELECT Quantity FROM Purchases LIMIT 1", '100'],
    ["SELECT PurchaseUnit FROM Purchases LIMIT 1", ''],
    ["SELECT ConversionFactor FROM Purchases LIMIT 1", '1.00'],
    ["SELECT Category FROM Inventory WHERE ItemID='ITM000001'", 'Analgesic'],
    ["SELECT SalesUnit FROM Inventory WHERE ItemID='ITM000001'", 'Tablet'],
]);

// 2. Optional package conversion: 2 boxes x 100 tablets = 200 units, exactly once.
$cases[] = baseCase('package purchase converts once into inventory units', 'superuser', savePost([
    'Quantity' => ['2'],
    'UnitPrice' => ['2.50'],
    'PurchaseUnit' => ['Box'],
    'ConversionFactor' => ['100'],
    'AmountPaid' => '5.00',
]), 302, [
    ["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'", '250'],
    ["SELECT COUNT(*) FROM Inventory WHERE ItemID='ITM000001'", '1'],
    ["SELECT PurchaseUnit FROM Purchases LIMIT 1", 'Box'],
    ["SELECT ConversionFactor FROM Purchases LIMIT 1", '100.00'],
    ["SELECT Quantity FROM Purchases LIMIT 1", '2'],
    ["SELECT UnitPrice FROM Purchases LIMIT 1", '2.50'],
]);

// 3. Unknown medicine id is rejected; nothing is created or incremented.
$cases[] = baseCase('purchase rejects unknown medicine id', 'superuser', savePost([
    'ItemID' => ['ITM999999'],
    'ItemName' => ['Brand New Medicine'],
    'Quantity' => ['10'],
    'UnitPrice' => ['1.00'],
    'SellingPrice' => ['2.00'],
    'ExpiryDate' => [''],
    'AmountPaid' => '',
]), 200, [
    ["SELECT COUNT(*) FROM Inventory WHERE ItemID='ITM000001'", '1'],
    ["SELECT COUNT(*) FROM Purchases", '0'],
    ["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'", '50'],
    ["SELECT COUNT(*) FROM Inventory WHERE LOWER(TRIM(ItemName))=LOWER(TRIM('Brand New Medicine'))", '0'],
], ['contains' => ['select an existing medicine']]);

// 3b. Two medicines in one purchase: each row saves independently.
$cases[] = baseCase('two medicine rows save independently', 'superuser', savePost([
    'ItemID' => ['ITM000001', 'ITM000002'],
    'ItemName' => ['Paracetamol', 'Ibuprofen'],
    'Category' => ['Analgesic', 'Analgesic'],
    'SalesUnit' => ['Tablet', 'Tablet'],
    'Quantity' => ['10', '3'],
    'UnitPrice' => ['0.50', '2.00'],
    'SellingPrice' => ['5.00', '7.00'],
    'ExpiryDate' => ['2027-12-30', '2028-01-31'],
    'PurchaseUnit' => ['', 'Box'],
    'ConversionFactor' => ['1', '100'],
    'Discount' => '',
    'VATAmount' => '',
    'AmountPaid' => '5.05',
]), 302, [
    ["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'", '60'],
    ["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000002'", '350'],
    ["SELECT COUNT(*) FROM Inventory", '2'],
    ["SELECT COUNT(*) FROM Purchases", '2'],
    ["SELECT ConversionFactor FROM Purchases WHERE ItemName='Ibuprofen'", '100.00'],
    ["SELECT PurchaseUnit FROM Purchases WHERE ItemName='Ibuprofen'", 'Box'],
    ["SELECT ConversionFactor FROM Purchases WHERE ItemName='Paracetamol'", '1.00'],
    ["SELECT PurchaseUnit FROM Purchases WHERE ItemName='Paracetamol'", ''],
]);
// 4. Non-superuser never reaches the purchase-cost surface.
$cases[] = [
    'name' => 'pharmacist cannot open purchase form',
    'role' => 'pharmacyuser',
    'get' => ['section' => 'purchases', 'new' => '1'],
    'status' => 403,
];

// 5. SuperAdmin still sees the simplified form with its new controls.
$cases[] = [
    'name' => 'superadmin sees simplified purchase form',
    'role' => 'superuser',
    'get' => ['section' => 'purchases', 'new' => '1'],
    'status' => 200,
    'contains' => ['purchase-item-search', 'Purchase by package', 'Units per package', 'Add Item'],
    'absent' => ['Packaging &amp; units', 'Unit sold / dispensed', 'Units per purchase unit', '+ Add Item'],
];

$failed = 0;
foreach ($cases as $case) {
    $testDb = 'tdc_purchase_test_' . bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE `' . $testDb . '`');
    try {
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('CREATE TABLE `' . $testDb . '`.`' . $table . '` LIKE `' . DB_NAME . '`.`' . $table . '`');
        }
        $test = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb, DB_USER, DB_PASS, $options);
        foreach (['Roles','Permissions','RolePermissions','PaymentMethods'] as $seedTable) {
            $test->exec('INSERT INTO `' . $seedTable . '` SELECT * FROM `' . DB_NAME . '`.`' . $seedTable . '`');
        }
        require_once __DIR__ . '/../auth/includes/operational-role-defaults.php';
        tdc_apply_operational_role_defaults($test);
        $ins = $test->prepare('INSERT INTO users (userlegalname,role,role_id,username,password,is_active,is_root) SELECT ?,r.RoleKey,r.RoleID,?,?,1,? FROM Roles r WHERE r.RoleKey=?');
        foreach (['superuser','pharmacyuser'] as $role) {
            $ins->execute(['Test ' . $role, $role, password_hash('Test-only-123!', PASSWORD_DEFAULT), $role === 'superuser' ? 1 : 0, $role]);
        }
        $test->exec("INSERT INTO Inventory (ItemID,Category,ItemName,QuantityInStock,SalesUnit,SellingPrice,ReorderLevel) VALUES ('ITM000001','Analgesic','Paracetamol',50,'Tablet',5.00,10), ('ITM000002','Analgesic','Ibuprofen',50,'Tablet',7.00,10)");

        $payload = base64_encode(json_encode($case, JSON_THROW_ON_ERROR));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($testDb) . ' ' . escapeshellarg($payload);
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $line = '';
        foreach ($out as $candidate) { if (strpos($candidate, '{"case"') === 0) { $line = $candidate; break; } }
        $result = $line !== '' ? json_decode($line, true) : null;
        $ok = $code === 0 && $result && empty($result['failures']);
        if (!$ok) {
            $failed++;
            echo 'FAIL ' . $case['name'] . ': ' . ($result ? implode('; ', $result['failures']) : trim(implode(' ', $out))) . PHP_EOL;
        } else {
            echo 'PASS ' . $case['name'] . PHP_EOL;
        }
    } finally {
        $pdo->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
    }
}
echo $failed === 0 ? 'ALL PURCHASE CASES PASSED' : ($failed . ' CASE(S) FAILED');
echo PHP_EOL;
exit($failed === 0 ? 0 : 1);

