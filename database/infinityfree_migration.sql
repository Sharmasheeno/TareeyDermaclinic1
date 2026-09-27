-- ============================================================================
-- Tarey Derma Clinic — InfinityFree production migration
-- File: database/infinityfree_migration.sql
-- ============================================================================
-- WHEN TO RUN
--   Import ONCE through phpMyAdmin (InfinityFree) against the clinic database.
--   The file is IDEMPOTENT: every statement is guarded (IF NOT EXISTS /
--   INSERT IGNORE), so re-running it is safe and changes nothing.
--
-- WHAT IT DOES
--   1. Creates the Role-Based Access Control tables the application queries
--      on every page (roles, permissions, rolepermissions, auditlog) plus the
--      support tables (paymentmethods, clinicsettings, departments,
--      specializations). On XAMPP these were created by scripts/migrate_setup_rbac.php
--      run from the CLI, which cannot run on InfinityFree — hence this file.
--   2. Ensures every table/column the application SQL references exists with
--      the exact lowercase production table names, adding only what is
--      missing (columns the connected-workflow code requires: doctors.UserID,
--      prescriptions.Route, visit/prescription workflow columns, etc.).
--   3. Seeds the five system roles, the full permission catalogue, the
--      default role->permission grants (Super Admin = everything; Reception /
--      Doctor / Laboratory / Pharmacy operational grants identical to the
--      application's own migration scripts), the standard payment methods,
--      specializations from existing doctors, and backfills users.role_id.
--
--   Existing data is never modified or deleted (grants are additive only).
--
-- NOTES
--   - Written for MariaDB 10.x (InfinityFree). `ADD COLUMN IF NOT EXISTS`
--     and `CREATE INDEX IF NOT EXISTS` are MariaDB features; on MySQL 8 you
--     would run those few ALTER/CREATE statements only when the column or
--     index is actually missing.
--   - New installs: import the full dump `tareydermaclinic.sql` FIRST, then
--     this migration. Existing InfinityFree databases: just run this file.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. RBAC + support tables (same definitions as scripts/migrate_setup_rbac.php,
--    created here with lowercase table names to match production).
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
    `RoleID` INT NOT NULL AUTO_INCREMENT,
    `RoleKey` VARCHAR(100) NOT NULL,
    `RoleName` VARCHAR(100) NOT NULL,
    `Description` VARCHAR(500) NULL,
    `IsSystem` TINYINT(1) NOT NULL DEFAULT 0,
    `IsProtected` TINYINT(1) NOT NULL DEFAULT 0,
    `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
    `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`RoleID`),
    UNIQUE KEY `uq_roles_key` (`RoleKey`),
    UNIQUE KEY `uq_roles_name` (`RoleName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    `PermissionID` INT NOT NULL AUTO_INCREMENT,
    `PermissionKey` VARCHAR(150) NOT NULL,
    `ModuleName` VARCHAR(100) NOT NULL,
    `ResourceName` VARCHAR(120) NOT NULL,
    `ActionName` VARCHAR(80) NOT NULL,
    `Description` VARCHAR(500) NULL,
    PRIMARY KEY (`PermissionID`),
    UNIQUE KEY `uq_permissions_key` (`PermissionKey`),
    KEY `idx_permissions_module` (`ModuleName`,`ResourceName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rolepermissions` (
    `RoleID` INT NOT NULL,
    `PermissionID` INT NOT NULL,
    PRIMARY KEY (`RoleID`,`PermissionID`),
    KEY `idx_role_permissions_permission` (`PermissionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `auditlog` (
    `AuditID` BIGINT NOT NULL AUTO_INCREMENT,
    `ActorUserID` INT NULL,
    `EventType` VARCHAR(100) NOT NULL,
    `EntityType` VARCHAR(100) NOT NULL,
    `EntityID` VARCHAR(100) NULL,
    `Summary` VARCHAR(500) NOT NULL,
    `ChangesJson` LONGTEXT NULL,
    `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`AuditID`),
    KEY `idx_audit_created` (`CreatedAt`),
    KEY `idx_audit_actor` (`ActorUserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `paymentmethods` (
    `PaymentMethodID` INT NOT NULL AUTO_INCREMENT,
    `MethodName` VARCHAR(80) NOT NULL,
    `Description` VARCHAR(255) NULL,
    `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
    `DisplayOrder` INT NOT NULL DEFAULT 0,
    `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`PaymentMethodID`),
    UNIQUE KEY `uq_payment_methods_name` (`MethodName`),
    KEY `idx_payment_methods_active` (`IsActive`,`DisplayOrder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `clinicsettings` (
    `SettingKey` VARCHAR(100) NOT NULL,
    `SettingValue` TEXT NULL,
    `UpdatedBy` INT NULL,
    `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`SettingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `departments` (
    `DepartmentID` INT NOT NULL AUTO_INCREMENT,
    `DepartmentName` VARCHAR(120) NOT NULL,
    `Description` VARCHAR(500) NULL,
    `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`DepartmentID`),
    UNIQUE KEY `uq_departments_name` (`DepartmentName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `specializations` (
    `SpecializationID` INT NOT NULL AUTO_INCREMENT,
    `SpecializationName` VARCHAR(120) NOT NULL,
    `Description` VARCHAR(500) NULL,
    `IsActive` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`SpecializationID`),
    UNIQUE KEY `uq_specializations_name` (`SpecializationName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 2. Column / index safety net for the workflow schema the application code
--    requires. All statements are guarded: existing tables/columns untouched.
-- ----------------------------------------------------------------------------

-- Link doctor records to login accounts (Setup "Users", doctor workspace).
ALTER TABLE `doctors`
    ADD COLUMN IF NOT EXISTS `UserID` INT(11) DEFAULT NULL AFTER `DoctorID`;
ALTER TABLE `doctors`
    ADD COLUMN IF NOT EXISTS `WorkingDays` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5' AFTER `JoinedDate`,
    ADD COLUMN IF NOT EXISTS `WorkStartTime` TIME NOT NULL DEFAULT '09:00:00' AFTER `WorkingDays`,
    ADD COLUMN IF NOT EXISTS `WorkEndTime` TIME NOT NULL DEFAULT '17:00:00' AFTER `WorkStartTime`;
CREATE UNIQUE INDEX IF NOT EXISTS `uq_doctors_user` ON `doctors` (`UserID`);

-- Manual payment methods are configurable labels; this is not an online
-- payment integration. Preserve existing values while allowing methods such
-- as EVC Plus, Cheque, or Bank Transfer.
ALTER TABLE `payments`
    MODIFY COLUMN `PaymentMethod` VARCHAR(80) NOT NULL DEFAULT 'Cash';

CREATE TABLE IF NOT EXISTS `reference_sequences` (
    `SequenceKey` VARCHAR(80) NOT NULL,
    `NextValue` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`SequenceKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `AttemptKey` VARCHAR(191) NOT NULL,
    `FailedCount` INT NOT NULL DEFAULT 0,
    `FirstAttempt` DATETIME NOT NULL,
    `BlockedUntil` DATETIME NULL,
    `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`AttemptKey`), KEY `idx_login_attempts_blocked` (`BlockedUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX IF NOT EXISTS `idx_users_role_id` ON `users` (`role_id`);

-- Pharmacy bills: optional Route column used by the Reception pharmacy form
-- and print slip (detected at runtime, printed only when present).
-- (Quantity first: Route is declared AFTER `Quantity`.)
ALTER TABLE `prescriptions`
    ADD COLUMN IF NOT EXISTS `Quantity` INT(11) NOT NULL DEFAULT 1 AFTER `MedicationName`;
ALTER TABLE `prescriptions`
    ADD COLUMN IF NOT EXISTS `Route` VARCHAR(50) DEFAULT NULL AFTER `Quantity`;

-- Pharmacy inventory packaging (optional default purchase pack; additive —
-- existing medicines keep NULL packaging) and purchase financial detail
-- columns that the Pharmacy purchases workflow writes.
ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS `DefaultPurchaseUnit` VARCHAR(50) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `UnitsPerPackage` INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `LastAcquisitionCostPerUnit` DECIMAL(10,4) NULL DEFAULT NULL;

ALTER TABLE `purchases`
    ADD COLUMN IF NOT EXISTS `ItemID` VARCHAR(50) NULL AFTER `SupplierID`,
    ADD COLUMN IF NOT EXISTS `ReferenceNumber` VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `Discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `VATAmount` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `purchases`
    ADD COLUMN IF NOT EXISTS `ExpiryDate` DATE NULL DEFAULT NULL AFTER `SellingPrice`;
SET @tdc_idx_purchase_item_date = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchases' AND INDEX_NAME='idx_purchases_item_date');
SET @tdc_sql_purchase_item_date = IF(@tdc_idx_purchase_item_date=0, 'ALTER TABLE `purchases` ADD KEY `idx_purchases_item_date` (`ItemID`,`PurchaseDate`)', 'SELECT 1');
PREPARE tdc_stmt_purchase_item_date FROM @tdc_sql_purchase_item_date; EXECUTE tdc_stmt_purchase_item_date; DEALLOCATE PREPARE tdc_stmt_purchase_item_date;

-- Guarded core-workflow columns (no-ops on a database already imported from
-- the current tareydermaclinic.sql dump; they repair older databases).
ALTER TABLE `prescriptions`
    ADD COLUMN IF NOT EXISTS `VisitID` INT(11) DEFAULT NULL AFTER `PatientID`,
    ADD COLUMN IF NOT EXISTS `Status` ENUM('Pending','Dispensed','Cancelled') NOT NULL DEFAULT 'Pending' AFTER `Instructions`,
    ADD COLUMN IF NOT EXISTS `DispensedAt` DATETIME DEFAULT NULL AFTER `Status`,
    ADD COLUMN IF NOT EXISTS `DispensedBy` INT(11) DEFAULT NULL AFTER `DispensedAt`,
    ADD COLUMN IF NOT EXISTS `PharmacySaleReference` VARCHAR(50) DEFAULT NULL AFTER `DispensedBy`;

ALTER TABLE `laboratory`
    ADD COLUMN IF NOT EXISTS `VisitID` INT(11) DEFAULT NULL AFTER `PatientID`,
    ADD COLUMN IF NOT EXISTS `DoctorID` INT(11) DEFAULT NULL AFTER `VisitID`,
    ADD COLUMN IF NOT EXISTS `RequestedByUserID` INT(11) DEFAULT NULL AFTER `DoctorID`,
    ADD COLUMN IF NOT EXISTS `ServiceID` INT(11) DEFAULT NULL AFTER `RequestedByUserID`,
    ADD COLUMN IF NOT EXISTS `WorkflowStatus` ENUM('Requested','Awaiting Payment','Ready','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Awaiting Payment' AFTER `PaymentStatus`,
    ADD COLUMN IF NOT EXISTS `ClinicalResult` TEXT DEFAULT NULL AFTER `Result`,
    ADD COLUMN IF NOT EXISTS `ReviewedAt` DATETIME DEFAULT NULL AFTER `ResultDate`;

ALTER TABLE `pharmacysales`
    ADD COLUMN IF NOT EXISTS `SaleStatus` ENUM('Valid','Voided') NOT NULL DEFAULT 'Valid' AFTER `PaymentStatus`,
    ADD COLUMN IF NOT EXISTS `PatientID` INT(11) DEFAULT NULL AFTER `CustomerPhone`,
    ADD COLUMN IF NOT EXISTS `VisitID` INT(11) DEFAULT NULL AFTER `PatientID`,
    ADD COLUMN IF NOT EXISTS `CostPerUnitSnapshot` DECIMAL(10,4) NULL DEFAULT NULL AFTER `LineTotal`,
    ADD COLUMN IF NOT EXISTS `LineCost` DECIMAL(10,2) NULL DEFAULT NULL AFTER `CostPerUnitSnapshot`;
SET @tdc_idx_sales_patient = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pharmacysales' AND INDEX_NAME='idx_pharmacy_sales_patient');
SET @tdc_sql_sales_patient = IF(@tdc_idx_sales_patient=0, 'ALTER TABLE `pharmacysales` ADD KEY `idx_pharmacy_sales_patient` (`PatientID`)', 'SELECT 1');
PREPARE tdc_stmt_sales_patient FROM @tdc_sql_sales_patient; EXECUTE tdc_stmt_sales_patient; DEALLOCATE PREPARE tdc_stmt_sales_patient;
SET @tdc_idx_sales_visit = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pharmacysales' AND INDEX_NAME='idx_pharmacy_sales_visit');
SET @tdc_sql_sales_visit = IF(@tdc_idx_sales_visit=0, 'ALTER TABLE `pharmacysales` ADD KEY `idx_pharmacy_sales_visit` (`VisitID`)', 'SELECT 1');
PREPARE tdc_stmt_sales_visit FROM @tdc_sql_sales_visit; EXECUTE tdc_stmt_sales_visit; DEALLOCATE PREPARE tdc_stmt_sales_visit;

-- ----------------------------------------------------------------------------
-- 3. Seed data (idempotent; unique keys make INSERT IGNORE a no-op on rerun).
-- ----------------------------------------------------------------------------

-- 3a. The five system roles.
INSERT IGNORE INTO `roles` (`RoleKey`,`RoleName`,`Description`,`IsSystem`,`IsProtected`,`IsActive`) VALUES
('superuser','SuperAdmin','Full system administration and operational access.',1,1,1),
('receptionuser','Reception','Combined reception, pharmacy, laboratory and accounting operations. Acquisition cost and administration remain restricted.',1,1,1),
('doctoruser','Doctor','Assigned consultations, prescriptions and laboratory requests.',1,1,1),
('labuser','Laboratory','Laboratory order processing and clinical results.',1,1,1),
('pharmacyuser','Pharmacy','Prescription dispensing and point-of-sale operations.',1,1,1);

-- 3b. Full permission catalogue (identical to the application's catalogue).
INSERT IGNORE INTO `permissions` (`PermissionKey`,`ModuleName`,`ResourceName`,`ActionName`,`Description`) VALUES
('dashboard.view','Dashboard','Dashboard','view','View role dashboard'),
('reception.view','Reception','Reception workspace','view','Open reception workspace'),
('patients.view','Patients','Patient records','view','View patient records'),
('patients.create','Patients','Patient records','create','Register patients'),
('patients.edit','Patients','Patient records','edit','Edit patient registration'),
('patients.delete','Patients','Patient records','delete','Delete eligible patient records'),
('patients.history','Patients','Patient history','view','View patient clinical and financial history'),
('patients.import','Patients','Patient records','import','Import patient records from CSV'),
('patients.export','Patients','Patient records','export','Export patient records'),
('visits.view','Visits / Appointments','Visits','view','View visits'),
('visits.create','Visits / Appointments','Visits','create','Create visits'),
('visits.edit','Visits / Appointments','Visits','edit','Edit visits'),
('visits.assign','Visits / Appointments','Doctor assignment','assign','Assign visits to doctors'),
('doctors.view','Doctors','Doctor directory','view','View doctors'),
('doctors.manage','Doctors','Doctor directory','manage','Create and maintain doctor records'),
('doctors.import','Doctors','Doctor directory','import','Import doctor records from CSV'),
('doctors.export','Doctors','Doctor directory','export','Export doctor records'),
('doctor.workspace','Doctors','Doctor workspace','work','Use assigned clinical workspace'),
('consultations.view','Consultations','Consultations','view','View consultations'),
('consultations.create','Consultations','Consultations','create','Book consultations'),
('consultations.edit','Consultations','Clinical record','edit','Record clinical notes'),
('consultations.complete','Consultations','Clinical record','complete','Complete consultations'),
('laboratory.view','Laboratory','Laboratory orders','view','View laboratory orders'),
('laboratory.request','Laboratory','Laboratory orders','request','Request laboratory tests'),
('laboratory.process','Laboratory','Laboratory orders','process','Process paid laboratory orders'),
('laboratory.result.create','Laboratory','Laboratory results','create','Enter laboratory results'),
('laboratory.result.edit','Laboratory','Laboratory results','edit','Edit in-progress laboratory results'),
('laboratory.complete','Laboratory','Laboratory orders','complete','Complete laboratory tests'),
('laboratory.results.view','Laboratory','Laboratory results','view','View laboratory results'),
('lab_billing.view','Laboratory Billing','Laboratory bills','view','View laboratory bills'),
('lab_billing.payment','Laboratory Billing','Laboratory payments','receive','Receive laboratory payments'),
('pharmacy.view','Pharmacy','Pharmacy workspace','view','Open pharmacy workspace'),
('pharmacy.prescriptions.view','Pharmacy','Prescriptions','view','View prescription queue'),
('pharmacy.prescription.create','Pharmacy','Prescriptions','create','Create prescriptions'),
('pharmacy.dispense','Pharmacy','Prescriptions','dispense','Dispense prescriptions'),
('pharmacy.pos','Pharmacy','Point of sale','sell','Use point of sale'),
('pharmacy.purchases.manage','Pharmacy','Purchases','manage','Manage purchases'),
('pharmacy.purchase_cost.view','Pharmacy','Purchase costs','view','View confidential supplier acquisition costs'),
('pharmacy.inventory.view','Inventory','Inventory','view','View medicine inventory'),
('pharmacy.inventory.manage','Inventory','Inventory','manage','Manage medicine inventory'),
('pharmacy_billing.view','Pharmacy Billing','Pharmacy bills','view','View pharmacy billing'),
('pharmacy_billing.payment','Pharmacy Billing','Pharmacy payments','receive','Receive pharmacy payments'),
('accounting.view','Accounting','Accounting','view','View accounting'),
('accounting.transactions.view','Accounting','Transactions','view','View accounting transactions'),
('accounting.journal.post','Accounting','Advanced accounting','post','Post manual journal entries'),
('accounting.journal.reverse','Accounting','Advanced accounting','reverse','Reverse manual journal entries'),
('accounting.expenses.create','Accounting','Expenses','create','Create expense entries'),
('accounting.expenses.edit','Accounting','Expenses','edit','Reverse or correct expense entries'),
('reports.view','Reports','Reports','view','View reports'),
('reports.export','Reports','Reports','export','Export reports'),
('reports.income.cost.view','Reports','Income Statement','view cost','View aggregate pharmacy cost and profit in the Income Statement'),
('setup.view','Setup','Setup','view','Open system Setup'),
('setup.organization.manage','Setup','Organization','manage','Manage clinic organization'),
('setup.users.manage','Users','Users','manage','Manage user accounts'),
('setup.roles.manage','Roles & Permissions','Roles','manage','Manage roles'),
('setup.permissions.manage','Roles & Permissions','Permissions','manage','Manage role permissions'),
('setup.clinical.manage','Setup','Clinical setup','manage','Manage clinical master data'),
('setup.laboratory.manage','Setup','Laboratory setup','manage','Manage laboratory catalogue'),
('setup.pharmacy.manage','Setup','Pharmacy setup','manage','Manage pharmacy master data'),
('setup.financial.manage','Setup','Financial setup','manage','Manage payment methods'),
('setup.communication.manage','Setup','Communication','manage','Manage supported communications'),
('setup.system.manage','System','System setup','manage','Manage system settings and audit log');

-- 3c. Super Admin: every permission (full access, as designed).
INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`RoleKey` = 'superuser';

-- 3d. Default operational grants for the working roles.
INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
JOIN `permissions` p ON p.`PermissionKey` IN (
    'dashboard.view','reception.view','patients.view','patients.create','patients.edit','patients.history','patients.import','patients.export',
    'visits.view','visits.create','visits.edit','visits.assign','consultations.view','consultations.create',
    'lab_billing.view','lab_billing.payment','pharmacy_billing.view','pharmacy_billing.payment'
)
WHERE r.`RoleKey` = 'receptionuser';

-- Keep production SQL equivalent to auth/includes/operational-role-defaults.php.
INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r JOIN `permissions` p ON p.`PermissionKey` IN (
    'pharmacy.view','pharmacy.prescriptions.view','pharmacy.dispense','pharmacy.pos',
    'pharmacy.inventory.view','pharmacy.inventory.manage',
    'laboratory.view','laboratory.process','laboratory.result.create','laboratory.result.edit','laboratory.complete','laboratory.results.view'
)
WHERE r.`RoleKey` = 'receptionuser';

INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
JOIN `permissions` p ON p.`PermissionKey` IN (
    'dashboard.view','doctors.view','doctor.workspace','patients.history','consultations.view','consultations.edit','consultations.complete',
    'laboratory.request','laboratory.results.view','pharmacy.prescription.create'
)
WHERE r.`RoleKey` = 'doctoruser';

INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
JOIN `permissions` p ON p.`PermissionKey` IN (
    'dashboard.view','laboratory.view','laboratory.process','laboratory.result.create','laboratory.result.edit','laboratory.complete','laboratory.results.view'
)
WHERE r.`RoleKey` = 'labuser';

INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
JOIN `permissions` p ON p.`PermissionKey` IN (
    'dashboard.view','pharmacy.view','pharmacy.prescriptions.view','pharmacy.dispense','pharmacy.pos',
    'pharmacy.inventory.view','pharmacy.inventory.manage','pharmacy.purchases.manage'
)
WHERE r.`RoleKey` = 'pharmacyuser';

-- 3e. Extended reception operational grants (combined reception/pharmacy/
--     laboratory/accounting desk), then strip administration rights.
INSERT IGNORE INTO `rolepermissions` (`RoleID`,`PermissionID`)
SELECT r.`RoleID`, p.`PermissionID`
FROM `roles` r
JOIN `permissions` p ON p.`PermissionKey` IN (
    'pharmacy.inventory.view','pharmacy.inventory.manage',
    'laboratory.results.view','patients.delete',
    'accounting.view','accounting.transactions.view','accounting.expenses.create','accounting.expenses.edit',
    'reports.view','reports.export'
)
WHERE r.`RoleKey` = 'receptionuser';

DELETE rp
FROM `rolepermissions` rp
JOIN `roles` r ON r.`RoleID` = rp.`RoleID`
JOIN `permissions` p ON p.`PermissionID` = rp.`PermissionID`
WHERE r.`RoleKey` = 'receptionuser'
  AND (p.`PermissionKey` LIKE 'setup.%'
       OR p.`PermissionKey` IN ('doctor.workspace','doctors.manage','doctors.import',
            'consultations.edit','consultations.complete','pharmacy.prescription.create',
            'pharmacy.purchases.manage','pharmacy.purchase_cost.view','laboratory.request'));

-- 3f. Standard payment methods.
INSERT IGNORE INTO `paymentmethods` (`MethodName`,`Description`,`DisplayOrder`) VALUES
('Cash','Physical cash received at the cashier desk.',1),
('EVC Plus','Manual EVC Plus mobile wallet transfer (recorded by hand).',2),
('Mobile Money','Manual mobile money transfer (recorded by hand).',3),
('Bank Transfer','Manual bank transfer (recorded by hand).',4),
('Card','Manual card terminal settlement (recorded by hand).',5),
('Cheque','Manual cheque deposit (recorded by hand).',6),
('Other','Any other manual settlement method.',7);

-- 3g. Specializations from existing doctor records.
INSERT IGNORE INTO `specializations` (`SpecializationName`)
SELECT DISTINCT `Specialty`
FROM `doctors`
WHERE `Specialty` IS NOT NULL AND TRIM(`Specialty`) <> '';

-- 3h. Backfill user role links (users keep their role key in `users.role`;
--     `role_id` powers the permission join).
UPDATE `users` u
JOIN `roles` r ON r.`RoleKey` = u.`role`
SET u.`role_id` = r.`RoleID`
WHERE u.`role_id` IS NULL OR u.`role_id` <> r.`RoleID`;

-- ============================================================================
-- END OF MIGRATION — safe to run once; safe to re-run.
-- ============================================================================
-- Additive, rerunnable. NULL means historical cost/price unknown. No backfill.
SET @tdc_rx_cost_sql = (SELECT IF(COUNT(*)=0,'ALTER TABLE prescriptions ADD COLUMN CostPerUnitSnapshot DECIMAL(10,4) NULL DEFAULT NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prescriptions' AND COLUMN_NAME='CostPerUnitSnapshot');
PREPARE tdc_rx_stmt FROM @tdc_rx_cost_sql;
EXECUTE tdc_rx_stmt;
DEALLOCATE PREPARE tdc_rx_stmt;
SET @tdc_rx_price_sql = (SELECT IF(COUNT(*)=0,'ALTER TABLE prescriptions ADD COLUMN UnitPriceSnapshot DECIMAL(10,2) NULL DEFAULT NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prescriptions' AND COLUMN_NAME='UnitPriceSnapshot');
PREPARE tdc_rx_stmt FROM @tdc_rx_price_sql;
EXECUTE tdc_rx_stmt;
DEALLOCATE PREPARE tdc_rx_stmt;


-- ============================================================================
-- SERVICES WORKFLOW SCHEMA (categories, subservices, assignments, billing)
-- ============================================================================
-- Tarey Derma Clinic - Services workflow
-- Run once after the main schema/migrations. All statements are idempotent.
CREATE TABLE IF NOT EXISTS service_categories (
    ServiceCategoryID INT NOT NULL AUTO_INCREMENT,
    CategoryName VARCHAR(150) NOT NULL,
    Description VARCHAR(500) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ServiceCategoryID),
    UNIQUE KEY uq_service_category_name (CategoryName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS service_subservices (
    ServiceID INT NOT NULL AUTO_INCREMENT,
    ServiceCategoryID INT NOT NULL,
    ServiceName VARCHAR(180) NOT NULL,
    Description VARCHAR(500) NULL,
    DefaultAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ServiceID),
    UNIQUE KEY uq_service_name_category (ServiceCategoryID, ServiceName),
    KEY idx_services_category_active (ServiceCategoryID, IsActive),
    CONSTRAINT fk_services_category FOREIGN KEY (ServiceCategoryID) REFERENCES service_categories(ServiceCategoryID) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS service_assignments (
    AssignmentID BIGINT NOT NULL AUTO_INCREMENT,
    ServiceReference VARCHAR(50) NOT NULL,
    PatientID INT NOT NULL,
    DoctorID INT NULL,
    ServiceID INT NOT NULL,
    ServiceAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    AmountPaid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    DueBalance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PaymentStatus ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
    AssignmentStatus ENUM('Assigned','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Assigned',
    Notes TEXT NULL,
    AssignedBy INT NULL,
    AssignedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (AssignmentID),
    UNIQUE KEY uq_service_reference (ServiceReference),
    KEY idx_service_assignment_patient (PatientID, AssignmentStatus),
    KEY idx_service_assignment_service (ServiceID),
    CONSTRAINT fk_service_assignment_patient FOREIGN KEY (PatientID) REFERENCES patients(PatientID) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_service_assignment_doctor FOREIGN KEY (DoctorID) REFERENCES doctors(DoctorID) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_service_assignment_service FOREIGN KEY (ServiceID) REFERENCES service_subservices(ServiceID) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_service_assignment_user FOREIGN KEY (AssignedBy) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE payments
    MODIFY COLUMN PaymentType ENUM('Consultation','Laboratory','Pharmacy','POS','Supplier','Service') NOT NULL;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS ServiceAssignmentID BIGINT NULL AFTER PrescriptionReference;
ALTER TABLE payments ADD KEY IF NOT EXISTS idx_payments_service_assignment (ServiceAssignmentID);
ALTER TABLE payments ADD CONSTRAINT fk_payments_service_assignment FOREIGN KEY (ServiceAssignmentID) REFERENCES service_assignments(AssignmentID) ON UPDATE CASCADE ON DELETE SET NULL;


-- Additive, bill-level patient discount/tax support. Existing totals and payments are untouched.
CREATE TABLE IF NOT EXISTS patient_bill_adjustments (
  AdjustmentID BIGINT NOT NULL AUTO_INCREMENT,
  BillType VARCHAR(30) NOT NULL,
  BillReference VARCHAR(100) NOT NULL,
  GrossAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  DiscountType ENUM('None','Fixed','Percentage') NOT NULL DEFAULT 'None',
  DiscountValue DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  DiscountAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  DiscountReason VARCHAR(255) NULL,
  AdjustmentNote TEXT NULL,
  TaxRate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  TaxAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  FinalAmount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  AdjustedBy INT NULL,
  AdjustedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (AdjustmentID),
  UNIQUE KEY uq_patient_bill_adjustment (BillType, BillReference),
  KEY idx_patient_bill_adjustment_reference (BillReference),
  KEY idx_patient_bill_adjustment_user (AdjustedBy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

