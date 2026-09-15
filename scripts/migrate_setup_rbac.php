<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

function setup_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function setup_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function setup_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!setup_column_exists($pdo, $table, $column)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS Roles (
    RoleID INT NOT NULL AUTO_INCREMENT, RoleKey VARCHAR(100) NOT NULL, RoleName VARCHAR(100) NOT NULL,
    Description VARCHAR(500) NULL, IsSystem TINYINT(1) NOT NULL DEFAULT 0, IsProtected TINYINT(1) NOT NULL DEFAULT 0,
    IsActive TINYINT(1) NOT NULL DEFAULT 1, CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (RoleID), UNIQUE KEY uq_roles_key (RoleKey), UNIQUE KEY uq_roles_name (RoleName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS Permissions (
    PermissionID INT NOT NULL AUTO_INCREMENT, PermissionKey VARCHAR(150) NOT NULL, ModuleName VARCHAR(100) NOT NULL,
    ResourceName VARCHAR(120) NOT NULL, ActionName VARCHAR(80) NOT NULL, Description VARCHAR(500) NULL,
    PRIMARY KEY (PermissionID), UNIQUE KEY uq_permissions_key (PermissionKey), KEY idx_permissions_module (ModuleName,ResourceName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS RolePermissions (
    RoleID INT NOT NULL, PermissionID INT NOT NULL, PRIMARY KEY (RoleID,PermissionID),
    KEY idx_role_permissions_permission (PermissionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS AuditLog (
    AuditID BIGINT NOT NULL AUTO_INCREMENT, ActorUserID INT NULL, EventType VARCHAR(100) NOT NULL,
    EntityType VARCHAR(100) NOT NULL, EntityID VARCHAR(100) NULL, Summary VARCHAR(500) NOT NULL,
    ChangesJson LONGTEXT NULL, CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (AuditID), KEY idx_audit_created (CreatedAt), KEY idx_audit_actor (ActorUserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS PaymentMethods (
    PaymentMethodID INT NOT NULL AUTO_INCREMENT, MethodName VARCHAR(80) NOT NULL, Description VARCHAR(255) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1, DisplayOrder INT NOT NULL DEFAULT 0,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (PaymentMethodID), UNIQUE KEY uq_payment_methods_name (MethodName), KEY idx_payment_methods_active (IsActive,DisplayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS ClinicSettings (
    SettingKey VARCHAR(100) NOT NULL, SettingValue TEXT NULL, UpdatedBy INT NULL,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (SettingKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS Departments (
    DepartmentID INT NOT NULL AUTO_INCREMENT, DepartmentName VARCHAR(120) NOT NULL, Description VARCHAR(500) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (DepartmentID), UNIQUE KEY uq_departments_name (DepartmentName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS Specializations (
    SpecializationID INT NOT NULL AUTO_INCREMENT, SpecializationName VARCHAR(120) NOT NULL, Description VARCHAR(500) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (SpecializationID), UNIQUE KEY uq_specializations_name (SpecializationName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$pdo->exec('ALTER TABLE users MODIFY role VARCHAR(100) NOT NULL');
setup_add_column($pdo, 'users', 'role_id', 'INT NULL AFTER role');
setup_add_column($pdo, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER password');
setup_add_column($pdo, 'users', 'is_root', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
setup_add_column($pdo, 'users', 'last_login_at', 'DATETIME NULL AFTER is_root');
if (!setup_index_exists($pdo, 'users', 'idx_users_role_id')) $pdo->exec('ALTER TABLE users ADD KEY idx_users_role_id (role_id)');
$pdo->exec('ALTER TABLE Payments MODIFY PaymentMethod VARCHAR(80) NOT NULL DEFAULT \'Cash\'');

$roles = [
    ['superuser','SuperAdmin','Full system administration and operational access.',1,1],
    ['receptionuser','Reception','Front-desk patient, visit and payment operations.',1,1],
    ['doctoruser','Doctor','Assigned consultations, prescriptions and laboratory requests.',1,1],
    ['labuser','Laboratory','Laboratory order processing and clinical results.',1,1],
    ['pharmacyuser','Pharmacy','Prescription dispensing and point-of-sale operations.',1,1],
];
$roleStmt = $pdo->prepare('INSERT INTO Roles (RoleKey,RoleName,Description,IsSystem,IsProtected,IsActive) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE RoleName=VALUES(RoleName),Description=VALUES(Description),IsSystem=VALUES(IsSystem),IsProtected=VALUES(IsProtected)');
foreach ($roles as $role) $roleStmt->execute($role);

$permissionRows = [
 ['dashboard.view','Dashboard','Dashboard','view','View role dashboard'],
 ['reception.view','Reception','Reception workspace','view','Open reception workspace'],
 ['patients.view','Patients','Patient records','view','View patient records'], ['patients.create','Patients','Patient records','create','Register patients'], ['patients.edit','Patients','Patient records','edit','Edit patient registration'], ['patients.delete','Patients','Patient records','delete','Delete eligible patient records'], ['patients.history','Patients','Patient history','view','View patient clinical and financial history'], ['patients.import','Patients','Patient records','import','Import patient records from CSV'], ['patients.export','Patients','Patient records','export','Export patient records'],
 ['visits.view','Visits / Appointments','Visits','view','View visits'], ['visits.create','Visits / Appointments','Visits','create','Create visits'], ['visits.edit','Visits / Appointments','Visits','edit','Edit visits'], ['visits.assign','Visits / Appointments','Doctor assignment','assign','Assign visits to doctors'],
 ['doctors.view','Doctors','Doctor directory','view','View doctors'], ['doctors.manage','Doctors','Doctor directory','manage','Create and maintain doctor records'], ['doctors.import','Doctors','Doctor directory','import','Import doctor records from CSV'], ['doctors.export','Doctors','Doctor directory','export','Export doctor records'], ['doctor.workspace','Doctors','Doctor workspace','work','Use assigned clinical workspace'],
 ['consultations.view','Consultations','Consultations','view','View consultations'], ['consultations.create','Consultations','Consultations','create','Book consultations'], ['consultations.edit','Consultations','Clinical record','edit','Record clinical notes'], ['consultations.complete','Consultations','Clinical record','complete','Complete consultations'],
 ['laboratory.view','Laboratory','Laboratory orders','view','View laboratory orders'], ['laboratory.request','Laboratory','Laboratory orders','request','Request laboratory tests'], ['laboratory.process','Laboratory','Laboratory orders','process','Process paid laboratory orders'], ['laboratory.result.create','Laboratory','Laboratory results','create','Enter laboratory results'], ['laboratory.result.edit','Laboratory','Laboratory results','edit','Edit in-progress laboratory results'], ['laboratory.complete','Laboratory','Laboratory orders','complete','Complete laboratory tests'], ['laboratory.results.view','Laboratory','Laboratory results','view','View laboratory results'],
 ['lab_billing.view','Laboratory Billing','Laboratory bills','view','View laboratory bills'], ['lab_billing.payment','Laboratory Billing','Laboratory payments','receive','Receive laboratory payments'],
 ['pharmacy.view','Pharmacy','Pharmacy workspace','view','Open pharmacy workspace'], ['pharmacy.prescriptions.view','Pharmacy','Prescriptions','view','View prescription queue'], ['pharmacy.prescription.create','Pharmacy','Prescriptions','create','Create prescriptions'], ['pharmacy.dispense','Pharmacy','Prescriptions','dispense','Dispense prescriptions'], ['pharmacy.pos','Pharmacy','Point of sale','sell','Use point of sale'], ['pharmacy.purchases.manage','Pharmacy','Purchases','manage','Manage purchases'], ['pharmacy.inventory.view','Inventory','Inventory','view','View medicine inventory'], ['pharmacy.inventory.manage','Inventory','Inventory','manage','Manage medicine inventory'],
 ['pharmacy_billing.view','Pharmacy Billing','Pharmacy bills','view','View pharmacy billing'], ['pharmacy_billing.payment','Pharmacy Billing','Pharmacy payments','receive','Receive pharmacy payments'],
 ['accounting.view','Accounting','Accounting','view','View accounting'], ['accounting.transactions.view','Accounting','Transactions','view','View accounting transactions'], ['accounting.expenses.create','Accounting','Expenses','create','Create expense entries'], ['accounting.expenses.edit','Accounting','Expenses','edit','Reverse or correct expense entries'],
 ['reports.view','Reports','Reports','view','View reports'], ['reports.export','Reports','Reports','export','Export reports'],
 ['setup.view','Setup','Setup','view','Open system Setup'], ['setup.organization.manage','Setup','Organization','manage','Manage clinic organization'], ['setup.users.manage','Users','Users','manage','Manage user accounts'], ['setup.roles.manage','Roles & Permissions','Roles','manage','Manage roles'], ['setup.permissions.manage','Roles & Permissions','Permissions','manage','Manage role permissions'], ['setup.clinical.manage','Setup','Clinical setup','manage','Manage clinical master data'], ['setup.laboratory.manage','Setup','Laboratory setup','manage','Manage laboratory catalogue'], ['setup.pharmacy.manage','Setup','Pharmacy setup','manage','Manage pharmacy master data'], ['setup.financial.manage','Setup','Financial setup','manage','Manage payment methods'], ['setup.communication.manage','Setup','Communication','manage','Manage supported communications'], ['setup.system.manage','System','System setup','manage','Manage system settings and audit log'],
];
$permissionStmt = $pdo->prepare('INSERT INTO Permissions (PermissionKey,ModuleName,ResourceName,ActionName,Description) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ModuleName=VALUES(ModuleName),ResourceName=VALUES(ResourceName),ActionName=VALUES(ActionName),Description=VALUES(Description)');
foreach ($permissionRows as $permission) $permissionStmt->execute($permission);

$pdo->exec('UPDATE users u JOIN Roles r ON r.RoleKey=u.role SET u.role_id=r.RoleID WHERE u.role_id IS NULL OR u.role_id<>r.RoleID');
$pdo->exec("UPDATE users SET is_root=1 WHERE username='superadmin' AND role='superuser'");

$defaults = [
 'receptionuser'=>['dashboard.view','reception.view','patients.view','patients.create','patients.edit','patients.history','patients.import','patients.export','visits.view','visits.create','visits.edit','visits.assign','consultations.view','consultations.create','lab_billing.view','lab_billing.payment','pharmacy_billing.view','pharmacy_billing.payment'],
 'doctoruser'=>['dashboard.view','doctors.view','doctor.workspace','patients.history','consultations.view','consultations.edit','consultations.complete','laboratory.request','laboratory.results.view','pharmacy.prescription.create'],
 'labuser'=>['dashboard.view','laboratory.view','laboratory.process','laboratory.result.create','laboratory.result.edit','laboratory.complete'],
 'pharmacyuser'=>['dashboard.view','pharmacy.view','pharmacy.prescriptions.view','pharmacy.dispense','pharmacy.pos'],
];
$pdo->beginTransaction();
try {
    $superRoleId = (int) $pdo->query("SELECT RoleID FROM Roles WHERE RoleKey='superuser'")->fetchColumn();
    $pdo->prepare('INSERT IGNORE INTO RolePermissions (RoleID,PermissionID) SELECT ?,PermissionID FROM Permissions')->execute([$superRoleId]);
    $link = $pdo->prepare('INSERT IGNORE INTO RolePermissions (RoleID,PermissionID) SELECT r.RoleID,p.PermissionID FROM Roles r JOIN Permissions p ON p.PermissionKey=? WHERE r.RoleKey=?');
    foreach ($defaults as $roleKey => $keys) foreach ($keys as $key) $link->execute([$key,$roleKey]);
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

$methodStmt = $pdo->prepare('INSERT IGNORE INTO PaymentMethods (MethodName,DisplayOrder) VALUES (?,?)');
foreach (['Cash','Card','Mobile Money','Bank','Other'] as $order => $method) $methodStmt->execute([$method,$order + 1]);
$pdo->exec("INSERT IGNORE INTO Specializations (SpecializationName) SELECT DISTINCT Specialty FROM Doctors WHERE Specialty IS NOT NULL AND TRIM(Specialty)<>''");

echo "Setup and RBAC schema is ready.\n";
