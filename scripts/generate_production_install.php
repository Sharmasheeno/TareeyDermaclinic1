<?php
declare(strict_types=1);

/**
 * scripts/generate_production_install.php
 * ---------------------------------------------------------------------
 * Regenerates the static, schema-only production installer:
 *
 *     database/production_install.sql
 *
 * The generator reads the CURRENT migrated schema from the connected
 * database (SHOW CREATE TABLE) and writes:
 *
 *   1. CREATE TABLE for every application table (empty databases only)
 *      (columns, indexes, keys) — schema only, zero business rows.
 *   2. Configuration seed: roles, permissions, rolepermissions,
 *      default manual payment methods and clinic profile defaults.
 *
 * It NEVER writes patients, visits, prescriptions, laboratory orders,
 * payments, sales, purchases, users, audit rows or any known password.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts\generate_production_install.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

// A trusted CLI verifier can provide its isolated PDO connection explicitly.
if (!isset($pdo)) require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth/includes/operational-role-defaults.php';

/** Default manual payment methods. Labels only — no gateway integration. */
const DEFAULT_PAYMENT_METHODS = [
    ['Cash', 'Physical cash received at the cashier desk.', 1],
    ['EVC Plus', 'Manual EVC Plus mobile wallet transfer (recorded by hand).', 2],
    ['Mobile Money', 'Manual mobile money transfer (recorded by hand).', 3],
    ['Bank Transfer', 'Manual bank transfer (recorded by hand).', 4],
    ['Card', 'Manual card terminal settlement (recorded by hand).', 5],
    ['Cheque', 'Manual cheque deposit (recorded by hand).', 6],
    ['Other', 'Any other manual settlement method.', 7],
];

/** Permissions the Doctor role receives by default. */
const DOCTOR_ROLE_PERMISSIONS = [
    'dashboard.view', 'doctors.view', 'doctor.workspace', 'patients.history',
    'consultations.view', 'consultations.edit', 'consultations.complete',
    'laboratory.request', 'laboratory.results.view', 'pharmacy.prescription.create',
];

function gi_sql_string(?string $value): string
{
    if ($value === null) return 'NULL';
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

function gi_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

// ---------------------------------------------------------------------
// 1. Collect schema
// ---------------------------------------------------------------------
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables, SORT_STRING);
if (!$tables) {
    fwrite(STDERR, "No tables found. Run the migrations before generating the installer.\n");
    exit(1);
}

$createStatements = [];
foreach ($tables as $table) {
    $row = $pdo->query('SHOW CREATE TABLE ' . gi_identifier($table))->fetch(PDO::FETCH_NUM);
    if (!$row || !isset($row[1])) {
        fwrite(STDERR, "Unable to read the schema of {$table}.\n");
        exit(1);
    }
    $createStatements[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $row[1]);
}

// ---------------------------------------------------------------------
// 2. Collect configuration seed
// ---------------------------------------------------------------------
$roles = $pdo->query("SELECT RoleKey, RoleName, Description, IsSystem, IsProtected, IsActive FROM roles WHERE RoleKey IN ('superuser','receptionuser','doctoruser','labuser','pharmacyuser') ORDER BY RoleID")->fetchAll();
$permissions = $pdo->query('SELECT PermissionKey, ModuleName, ResourceName, ActionName, Description FROM permissions ORDER BY PermissionID')->fetchAll();

if (!$roles || !$permissions) {
    fwrite(STDERR, "Roles and permissions must exist before generating the installer.\n");
    exit(1);
}

$allPermissionKeys = array_column($permissions, 'PermissionKey');
$operationalDefaults = tdc_operational_role_defaults();

// Reception keeps its operational grant minus administrative and clinical authoring rights.
$receptionPermissions = array_values(array_filter(
    $operationalDefaults['receptionuser'],
    static fn(string $key): bool => !str_starts_with($key, 'setup.')
));
$receptionPermissions = array_values(array_diff(
    $receptionPermissions,
    ['doctor.workspace', 'doctors.manage', 'doctors.import', 'consultations.edit', 'consultations.complete', 'pharmacy.prescription.create', 'laboratory.request']
));

$roleGrants = [
    'superuser'     => $allPermissionKeys,
    'receptionuser' => $receptionPermissions,
    'doctoruser'    => DOCTOR_ROLE_PERMISSIONS,
    'labuser'       => $operationalDefaults['labuser'],
    'pharmacyuser'  => $operationalDefaults['pharmacyuser'],
];

$validPermissionKeys = array_flip($allPermissionKeys);
$validRoleKeys = array_flip(array_column($roles, 'RoleKey'));
foreach ($roleGrants as $roleKey => $keys) {
    if (!isset($validRoleKeys[$roleKey])) {
        fwrite(STDERR, "Role {$roleKey} is missing from the roles table.\n");
        exit(1);
    }
    foreach ($keys as $key) {
        if (!isset($validPermissionKeys[$key])) {
            fwrite(STDERR, "Permission {$key} is missing from the permissions table.\n");
            exit(1);
        }
    }
}

// ---------------------------------------------------------------------
// 3. Compose the installer
// ---------------------------------------------------------------------
$lines = [];
$lines[] = '-- =====================================================================';
$lines[] = '-- Tarey Derma Clinic — PRODUCTION INSTALLER (schema + configuration only)';
$lines[] = '-- =====================================================================';
$lines[] = '-- Generated by scripts/generate_production_install.php';
$lines[] = '-- Generated at: ' . gmdate('Y-m-d H:i:s') . ' UTC';
$lines[] = '--';
$lines[] = '-- Contains: complete tables, columns, indexes, keys, roles, permissions,';
$lines[] = '--           role permissions, default MANUAL payment methods, clinic';
$lines[] = '--           profile defaults and the login/reference infrastructure.';
$lines[] = '--';
$lines[] = '-- Deliberately ABSENT (never shipped in a production install):';
$lines[] = '--   * demo patients / visits / prescriptions / laboratory orders';
$lines[] = '--   * demo payments, sales or purchases';
$lines[] = '--   * developer accounts, test users and known SuperAdmin passwords';
$lines[] = '';
$lines[] = '-- The single root SuperAdmin is created AFTER this file by:';
$lines[] = '--   php scripts/create_default_superadmin.php';
$lines[] = '--';
$lines[] = '-- Payment methods are MANUAL LABELS ONLY. There is no payment gateway';
$lines[] = '-- integration anywhere in this product.';
$lines[] = '--';
$lines[] = '-- Import into a NEW EMPTY database only. Not an upgrade or a backup.';
$lines[] = '-- No DROP statements, business records, users or database credentials.';
$lines[] = '-- MySQL / MariaDB schema; application requires PHP 8.2.';
$lines[] = '-- =====================================================================';
$lines[] = '';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = '';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$lines[] = '';
$lines[] = '-- ---------------------------------------------------------------------';
$lines[] = '-- 1. Empty database required; existing tables are never dropped.';
$lines[] = '-- ---------------------------------------------------------------------';
$lines[] = '';
$lines[] = '-- ---------------------------------------------------------------------';
$lines[] = '-- 2. Schema';
$lines[] = '-- ---------------------------------------------------------------------';
foreach ($createStatements as $table => $statement) {
    $lines[] = '';
    $lines[] = '-- TABLE ' . $table;
    $lines[] = $statement . ';';
}
$lines[] = '';
$lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
$lines[] = '';

// ---------------------------------------------------------------------
// 4. Configuration seed (never business data)
// ---------------------------------------------------------------------
$lines[] = '-- ---------------------------------------------------------------------';
$lines[] = '-- 3. Configuration seed';
$lines[] = '-- ---------------------------------------------------------------------';

$lines[] = 'INSERT IGNORE INTO `roles` (`RoleKey`,`RoleName`,`Description`,`IsSystem`,`IsProtected`,`IsActive`) VALUES';
$roleValues = [];
foreach ($roles as $role) {
    $roleValues[] = '(' . implode(',', [
        gi_sql_string((string) $role['RoleKey']), gi_sql_string((string) $role['RoleName']),
        gi_sql_string($role['Description'] !== null ? (string) $role['Description'] : null),
        (int) $role['IsSystem'], (int) $role['IsProtected'], (int) $role['IsActive'],
    ]) . ')';
}
$lines[] = implode(",\n", $roleValues) . ';';

$lines[] = 'INSERT IGNORE INTO `permissions` (`PermissionKey`,`ModuleName`,`ResourceName`,`ActionName`,`Description`) VALUES';
$permissionValues = [];
foreach ($permissions as $permission) {
    $permissionValues[] = '(' . implode(',', [
        gi_sql_string((string) $permission['PermissionKey']), gi_sql_string((string) $permission['ModuleName']),
        gi_sql_string((string) $permission['ResourceName']), gi_sql_string((string) $permission['ActionName']),
        gi_sql_string($permission['Description'] !== null ? (string) $permission['Description'] : null),
    ]) . ')';
}
$lines[] = implode(",\n", $permissionValues) . ';';

foreach ($roleGrants as $roleKey => $permissionKeys) {
    $quotedKeys = implode(',', array_map(static fn(string $key): string => gi_sql_string($key), $permissionKeys));
    $lines[] = 'INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)'
        . ' SELECT r.`RoleID`, p.`PermissionID` FROM `roles` r CROSS JOIN `permissions` p'
        . ' WHERE r.`RoleKey`=' . gi_sql_string($roleKey) . ' AND p.`PermissionKey` IN (' . $quotedKeys . ');';
}

$lines[] = 'INSERT IGNORE INTO `paymentmethods` (`MethodName`,`Description`,`IsActive`,`DisplayOrder`) VALUES';
$methodValues = [];
foreach (DEFAULT_PAYMENT_METHODS as [$name, $description, $order]) {
    $methodValues[] = '(' . implode(',', [gi_sql_string($name), gi_sql_string($description), '1', (int) $order]) . ')';
}
$lines[] = implode(",\n", $methodValues) . ';';
$lines[] = 'INSERT IGNORE INTO `clinicsettings` (`SettingKey`,`SettingValue`) VALUES'
    . "\n('ClinicName','Tarey Derma Clinic'),\n('Currency','USD');";
$lines[] = '';
$lines[] = '-- No users are seeded. Run the password-supplied bootstrap command next.';
$lines[] = '-- No patients, clinical records, payments, sales, purchases or audit rows are seeded.';
$lines[] = '';
$lines[] = '-- =====================================================================';
$lines[] = '-- END PRODUCTION INSTALLER';
$lines[] = '-- =====================================================================';

foreach (['production_install.sql', 'infinityfree_fresh.sql'] as $outputName) {
$outputPath = __DIR__ . '/../database/' . $outputName;
if (file_put_contents($outputPath, implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, "Unable to write {$outputPath}.\n");
    exit(1);
}
echo "Wrote {$outputPath}\n";
}
