-- ============================================================================
-- Tarey Derma Clinic — SAFE MIGRATION FOR AN EXISTING INFINITYFREE DATABASE
-- ============================================================================
-- Purpose:
--   Repair the current production database so it matches the schema required by
--   the newer PHP application without deleting or replacing real operational data.
--
-- Safety rules:
--   * Preserve all existing records.
--   * Only add missing tables and columns.
--   * Only create missing indexes.
--   * Only backfill legacy values when the source information is already present.
--   * Do not drop, truncate, or recreate business tables.
--   * Do not rewrite historical financial totals unless a field is missing.
--
-- Compatibility:
--   * Designed for MariaDB/MySQL used by InfinityFree.
--   * Uses dynamic SQL via PREPARE/EXECUTE so the script remains safe and
--     idempotent when run more than once against an existing database.
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------------------
-- 1) Support tables required by the current RBAC / workflow / notifications stack
-- ------------------------------------------------------------------------

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
    PRIMARY KEY (`AttemptKey`),
    KEY `idx_login_attempts_blocked` (`BlockedUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `NotificationID` BIGINT NOT NULL AUTO_INCREMENT,
    `UserID` INT NULL,
    `RoleTarget` ENUM('superuser','receptionuser','doctoruser','pharmacyuser','labuser') NULL,
    `EventType` VARCHAR(50) NOT NULL,
    `Title` VARCHAR(150) NOT NULL,
    `Message` VARCHAR(500) NOT NULL,
    `Link` VARCHAR(255) NULL,
    `IsRead` TINYINT(1) NOT NULL DEFAULT 0,
    `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`NotificationID`),
    KEY `idx_notifications_user` (`UserID`,`IsRead`,`CreatedAt`),
    KEY `idx_notifications_role` (`RoleTarget`,`IsRead`,`CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------------------
-- 2) Safe helper pattern: add missing columns only when absent
-- ------------------------------------------------------------------------
-- The pattern below avoids blindly depending on ADD COLUMN IF NOT EXISTS,
-- which is not uniformly portable across older MySQL/MariaDB builds, while
-- still preventing destructive changes and preserving all existing data.

-- users: RBAC fields required by auth/auth.php and setup.php
SET @tdc_users_role_id_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role_id'
);
SET @tdc_sql_users_role_id := IF(@tdc_users_role_id_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `role_id` INT NULL AFTER `role`',
    'SELECT 1');
PREPARE tdc_stmt_users_role_id FROM @tdc_sql_users_role_id;
EXECUTE tdc_stmt_users_role_id;
DEALLOCATE PREPARE tdc_stmt_users_role_id;

SET @tdc_users_is_root_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_root'
);
SET @tdc_sql_users_is_root := IF(@tdc_users_is_root_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `is_root` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`',
    'SELECT 1');
PREPARE tdc_stmt_users_is_root FROM @tdc_sql_users_is_root;
EXECUTE tdc_stmt_users_is_root;
DEALLOCATE PREPARE tdc_stmt_users_is_root;

SET @tdc_users_last_login_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'last_login_at'
);
SET @tdc_sql_users_last_login := IF(@tdc_users_last_login_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL AFTER `is_root`',
    'SELECT 1');
PREPARE tdc_stmt_users_last_login FROM @tdc_sql_users_last_login;
EXECUTE tdc_stmt_users_last_login;
DEALLOCATE PREPARE tdc_stmt_users_last_login;

SET @tdc_users_role_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_role_id'
);
SET @tdc_sql_users_role_idx := IF(@tdc_users_role_idx_exists = 0,
    'ALTER TABLE `users` ADD KEY `idx_users_role_id` (`role_id`)',
    'SELECT 1');
PREPARE tdc_stmt_users_role_idx FROM @tdc_sql_users_role_idx;
EXECUTE tdc_stmt_users_role_idx;
DEALLOCATE PREPARE tdc_stmt_users_role_idx;

-- doctors: user linkage, scheduling, doctor workspace support
SET @tdc_doctors_userid_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'doctors'
      AND COLUMN_NAME = 'UserID'
);
SET @tdc_sql_doctors_userid := IF(@tdc_doctors_userid_exists = 0,
    'ALTER TABLE `doctors` ADD COLUMN `UserID` INT NULL AFTER `DoctorID`',
    'SELECT 1');
PREPARE tdc_stmt_doctors_userid FROM @tdc_sql_doctors_userid;
EXECUTE tdc_stmt_doctors_userid;
DEALLOCATE PREPARE tdc_stmt_doctors_userid;

SET @tdc_doctors_workdays_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'doctors'
      AND COLUMN_NAME = 'WorkingDays'
);
SET @tdc_sql_doctors_workdays := IF(@tdc_doctors_workdays_exists = 0,
    'ALTER TABLE `doctors` ADD COLUMN `WorkingDays` VARCHAR(20) NOT NULL DEFAULT "1,2,3,4,5" AFTER `JoinedDate`',
    'SELECT 1');
PREPARE tdc_stmt_doctors_workdays FROM @tdc_sql_doctors_workdays;
EXECUTE tdc_stmt_doctors_workdays;
DEALLOCATE PREPARE tdc_stmt_doctors_workdays;

SET @tdc_doctors_start_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'doctors'
      AND COLUMN_NAME = 'WorkStartTime'
);
SET @tdc_sql_doctors_start := IF(@tdc_doctors_start_exists = 0,
    'ALTER TABLE `doctors` ADD COLUMN `WorkStartTime` TIME NOT NULL DEFAULT "09:00:00" AFTER `WorkingDays`',
    'SELECT 1');
PREPARE tdc_stmt_doctors_start FROM @tdc_sql_doctors_start;
EXECUTE tdc_stmt_doctors_start;
DEALLOCATE PREPARE tdc_stmt_doctors_start;

SET @tdc_doctors_end_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'doctors'
      AND COLUMN_NAME = 'WorkEndTime'
);
SET @tdc_sql_doctors_end := IF(@tdc_doctors_end_exists = 0,
    'ALTER TABLE `doctors` ADD COLUMN `WorkEndTime` TIME NOT NULL DEFAULT "17:00:00" AFTER `WorkStartTime`',
    'SELECT 1');
PREPARE tdc_stmt_doctors_end FROM @tdc_sql_doctors_end;
EXECUTE tdc_stmt_doctors_end;
DEALLOCATE PREPARE tdc_stmt_doctors_end;

SET @tdc_doctors_user_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'doctors'
      AND INDEX_NAME = 'uq_doctors_user'
);
SET @tdc_sql_doctors_user_idx := IF(@tdc_doctors_user_idx_exists = 0,
    'ALTER TABLE `doctors` ADD UNIQUE KEY `uq_doctors_user` (`UserID`)',
    'SELECT 1');
PREPARE tdc_stmt_doctors_user_idx FROM @tdc_sql_doctors_user_idx;
EXECUTE tdc_stmt_doctors_user_idx;
DEALLOCATE PREPARE tdc_stmt_doctors_user_idx;

-- visits: required by appointment and payment workflows
SET @tdc_visits_table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'visits'
);
SET @tdc_sql_visits_table := IF(@tdc_visits_table_exists = 0,
    'CREATE TABLE `visits` (
        `VisitID` INT NOT NULL AUTO_INCREMENT,
        `VisitReference` VARCHAR(50) NOT NULL,
        `PatientID` INT NOT NULL,
        `DoctorID` INT NOT NULL,
        `ReceptionistUserID` INT NULL,
        `VisitDate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ConsultationFee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `AmountPaid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `DueBalance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `PaymentStatus` ENUM("Unpaid","Partial","Paid") NOT NULL DEFAULT "Unpaid",
        `QueueStatus` ENUM("Pending Payment","Waiting","In Consultation","Completed","Cancelled") NOT NULL DEFAULT "Pending Payment",
        `ChiefComplaint` TEXT NULL,
        `ClinicalNotes` TEXT NULL,
        `Diagnosis` TEXT NULL,
        `TreatmentPlan` TEXT NULL,
        `FollowUpPlan` TEXT NULL,
        `FollowUpDate` DATE NULL,
        `CompletedAt` DATETIME NULL,
        `CreatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`VisitID`),
        UNIQUE KEY `uq_visits_reference` (`VisitReference`),
        KEY `idx_visits_patient` (`PatientID`),
        KEY `idx_visits_doctor_queue` (`DoctorID`,`QueueStatus`,`VisitDate`),
        KEY `idx_visits_payment` (`PaymentStatus`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'SELECT 1');
PREPARE tdc_stmt_visits_table FROM @tdc_sql_visits_table;
EXECUTE tdc_stmt_visits_table;
DEALLOCATE PREPARE tdc_stmt_visits_table;

-- payments and the ledger fields required by accounting and reversal logic
SET @tdc_payments_pfreq_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'PrescriptionReference'
);
SET @tdc_sql_payments_pfreq := IF(@tdc_payments_pfreq_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `PrescriptionReference` VARCHAR(50) NULL AFTER `LaboratoryID`',
    'SELECT 1');
PREPARE tdc_stmt_payments_pfreq FROM @tdc_sql_payments_pfreq;
EXECUTE tdc_stmt_payments_pfreq;
DEALLOCATE PREPARE tdc_stmt_payments_pfreq;

SET @tdc_payments_saleref_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'SaleReference'
);
SET @tdc_sql_payments_saleref := IF(@tdc_payments_saleref_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `SaleReference` VARCHAR(50) NULL AFTER `PrescriptionReference`',
    'SELECT 1');
PREPARE tdc_stmt_payments_saleref FROM @tdc_sql_payments_saleref;
EXECUTE tdc_stmt_payments_saleref;
DEALLOCATE PREPARE tdc_stmt_payments_saleref;

SET @tdc_payments_purchref_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'PurchaseReference'
);
SET @tdc_sql_payments_purchref := IF(@tdc_payments_purchref_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `PurchaseReference` VARCHAR(50) NULL AFTER `SaleReference`',
    'SELECT 1');
PREPARE tdc_stmt_payments_purchref FROM @tdc_sql_payments_purchref;
EXECUTE tdc_stmt_payments_purchref;
DEALLOCATE PREPARE tdc_stmt_payments_purchref;

SET @tdc_payments_reversal_of_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'ReversalOfPaymentID'
);
SET @tdc_sql_payments_reversal_of := IF(@tdc_payments_reversal_of_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `ReversalOfPaymentID` BIGINT NULL AFTER `PaymentStatus`',
    'SELECT 1');
PREPARE tdc_stmt_payments_reversal_of FROM @tdc_sql_payments_reversal_of;
EXECUTE tdc_stmt_payments_reversal_of;
DEALLOCATE PREPARE tdc_stmt_payments_reversal_of;

SET @tdc_payments_reversal_ref_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'ReversalReference'
);
SET @tdc_sql_payments_reversal_ref := IF(@tdc_payments_reversal_ref_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `ReversalReference` VARCHAR(50) NULL AFTER `ReversalOfPaymentID`',
    'SELECT 1');
PREPARE tdc_stmt_payments_reversal_ref FROM @tdc_sql_payments_reversal_ref;
EXECUTE tdc_stmt_payments_reversal_ref;
DEALLOCATE PREPARE tdc_stmt_payments_reversal_ref;

SET @tdc_payments_reversal_reason_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'ReversalReason'
);
SET @tdc_sql_payments_reversal_reason := IF(@tdc_payments_reversal_reason_exists = 0,
    'ALTER TABLE `payments` ADD COLUMN `ReversalReason` VARCHAR(500) NULL AFTER `ReversalReference`',
    'SELECT 1');
PREPARE tdc_stmt_payments_reversal_reason FROM @tdc_sql_payments_reversal_reason;
EXECUTE tdc_stmt_payments_reversal_reason;
DEALLOCATE PREPARE tdc_stmt_payments_reversal_reason;

SET @tdc_payments_type_enum := (
    SELECT DATA_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'PaymentType'
    LIMIT 1
);
SET @tdc_sql_payments_type_enum := IF(
    @tdc_payments_type_enum IS NOT NULL,
    'ALTER TABLE `payments` MODIFY COLUMN `PaymentType` ENUM("Consultation","Laboratory","Pharmacy","POS","Supplier") NOT NULL',
    'SELECT 1'
);
PREPARE tdc_stmt_payments_type_enum FROM @tdc_sql_payments_type_enum;
EXECUTE tdc_stmt_payments_type_enum;
DEALLOCATE PREPARE tdc_stmt_payments_type_enum;

SET @tdc_paymentmethod_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND COLUMN_NAME = 'PaymentMethod'
);
SET @tdc_sql_paymentmethod_column := IF(@tdc_paymentmethod_column_exists > 0,
    'ALTER TABLE `payments` MODIFY COLUMN `PaymentMethod` VARCHAR(80) NOT NULL DEFAULT "Cash"',
    'SELECT 1');
PREPARE tdc_stmt_paymentmethod_column FROM @tdc_sql_paymentmethod_column;
EXECUTE tdc_stmt_paymentmethod_column;
DEALLOCATE PREPARE tdc_stmt_paymentmethod_column;

SET @tdc_payments_sale_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'idx_payments_sale'
);
SET @tdc_sql_payments_sale_idx := IF(@tdc_payments_sale_idx_exists = 0,
    'ALTER TABLE `payments` ADD KEY `idx_payments_sale` (`SaleReference`)',
    'SELECT 1');
PREPARE tdc_stmt_payments_sale_idx FROM @tdc_sql_payments_sale_idx;
EXECUTE tdc_stmt_payments_sale_idx;
DEALLOCATE PREPARE tdc_stmt_payments_sale_idx;

SET @tdc_payments_purchase_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'idx_payments_purchase'
);
SET @tdc_sql_payments_purchase_idx := IF(@tdc_payments_purchase_idx_exists = 0,
    'ALTER TABLE `payments` ADD KEY `idx_payments_purchase` (`PurchaseReference`)',
    'SELECT 1');
PREPARE tdc_stmt_payments_purchase_idx FROM @tdc_sql_payments_purchase_idx;
EXECUTE tdc_stmt_payments_purchase_idx;
DEALLOCATE PREPARE tdc_stmt_payments_purchase_idx;

-- prescriptions: current app requires route, status, dispensing linkage, and sale reference
SET @tdc_prescriptions_visit_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'VisitID'
);
SET @tdc_sql_prescriptions_visit := IF(@tdc_prescriptions_visit_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `VisitID` INT NULL AFTER `PatientID`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_visit FROM @tdc_sql_prescriptions_visit;
EXECUTE tdc_stmt_prescriptions_visit;
DEALLOCATE PREPARE tdc_stmt_prescriptions_visit;

SET @tdc_prescriptions_qty_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'Quantity'
);
SET @tdc_sql_prescriptions_qty := IF(@tdc_prescriptions_qty_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `Quantity` INT NOT NULL DEFAULT 1 AFTER `MedicationName`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_qty FROM @tdc_sql_prescriptions_qty;
EXECUTE tdc_stmt_prescriptions_qty;
DEALLOCATE PREPARE tdc_stmt_prescriptions_qty;

SET @tdc_prescriptions_route_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'Route'
);
SET @tdc_sql_prescriptions_route := IF(@tdc_prescriptions_route_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `Route` VARCHAR(50) NULL AFTER `Quantity`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_route FROM @tdc_sql_prescriptions_route;
EXECUTE tdc_stmt_prescriptions_route;
DEALLOCATE PREPARE tdc_stmt_prescriptions_route;

SET @tdc_prescriptions_status_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'Status'
);
SET @tdc_sql_prescriptions_status := IF(@tdc_prescriptions_status_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `Status` ENUM("Pending","Dispensed","Cancelled") NOT NULL DEFAULT "Pending" AFTER `Instructions`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_status FROM @tdc_sql_prescriptions_status;
EXECUTE tdc_stmt_prescriptions_status;
DEALLOCATE PREPARE tdc_stmt_prescriptions_status;

SET @tdc_prescriptions_dispensed_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'DispensedAt'
);
SET @tdc_sql_prescriptions_dispensed_at := IF(@tdc_prescriptions_dispensed_at_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `DispensedAt` DATETIME NULL AFTER `Status`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_dispensed_at FROM @tdc_sql_prescriptions_dispensed_at;
EXECUTE tdc_stmt_prescriptions_dispensed_at;
DEALLOCATE PREPARE tdc_stmt_prescriptions_dispensed_at;

SET @tdc_prescriptions_dispensed_by_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'DispensedBy'
);
SET @tdc_sql_prescriptions_dispensed_by := IF(@tdc_prescriptions_dispensed_by_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `DispensedBy` INT NULL AFTER `DispensedAt`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_dispensed_by FROM @tdc_sql_prescriptions_dispensed_by;
EXECUTE tdc_stmt_prescriptions_dispensed_by;
DEALLOCATE PREPARE tdc_stmt_prescriptions_dispensed_by;

SET @tdc_prescriptions_sale_ref_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND COLUMN_NAME = 'PharmacySaleReference'
);
SET @tdc_sql_prescriptions_sale_ref := IF(@tdc_prescriptions_sale_ref_exists = 0,
    'ALTER TABLE `prescriptions` ADD COLUMN `PharmacySaleReference` VARCHAR(50) NULL AFTER `DispensedBy`',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_sale_ref FROM @tdc_sql_prescriptions_sale_ref;
EXECUTE tdc_stmt_prescriptions_sale_ref;
DEALLOCATE PREPARE tdc_stmt_prescriptions_sale_ref;

SET @tdc_prescriptions_visit_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND INDEX_NAME = 'idx_prescriptions_visit'
);
SET @tdc_sql_prescriptions_visit_idx := IF(@tdc_prescriptions_visit_idx_exists = 0,
    'ALTER TABLE `prescriptions` ADD KEY `idx_prescriptions_visit` (`VisitID`)',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_visit_idx FROM @tdc_sql_prescriptions_visit_idx;
EXECUTE tdc_stmt_prescriptions_visit_idx;
DEALLOCATE PREPARE tdc_stmt_prescriptions_visit_idx;

SET @tdc_prescriptions_status_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prescriptions'
      AND INDEX_NAME = 'idx_prescriptions_status'
);
SET @tdc_sql_prescriptions_status_idx := IF(@tdc_prescriptions_status_idx_exists = 0,
    'ALTER TABLE `prescriptions` ADD KEY `idx_prescriptions_status` (`Status`,`PrescriptionDate`)',
    'SELECT 1');
PREPARE tdc_stmt_prescriptions_status_idx FROM @tdc_sql_prescriptions_status_idx;
EXECUTE tdc_stmt_prescriptions_status_idx;
DEALLOCATE PREPARE tdc_stmt_prescriptions_status_idx;

-- legacy backfill: never invent Route values; keep NULL if historic value was absent
UPDATE `prescriptions`
SET `Status` = CASE
    WHEN `Status` IS NULL OR `Status` = '' THEN 'Pending'
    WHEN `Status` NOT IN ('Pending','Dispensed','Cancelled') THEN 'Pending'
    ELSE `Status`
END
WHERE `Status` IS NULL OR `Status` = '' OR `Status` NOT IN ('Pending','Dispensed','Cancelled');

UPDATE `prescriptions`
SET `Status` = 'Dispensed'
WHERE `Status` = 'Pending'
  AND (`PharmacySaleReference` IS NOT NULL AND `PharmacySaleReference` <> '')
  AND (`DispensedAt` IS NOT NULL OR `DispensedBy` IS NOT NULL);

-- laboratory: workflow and result fields required by laboratory and reports
SET @tdc_laboratory_visit_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'VisitID'
);
SET @tdc_sql_laboratory_visit := IF(@tdc_laboratory_visit_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `VisitID` INT NULL AFTER `PatientID`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_visit FROM @tdc_sql_laboratory_visit;
EXECUTE tdc_stmt_laboratory_visit;
DEALLOCATE PREPARE tdc_stmt_laboratory_visit;

SET @tdc_laboratory_doctor_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'DoctorID'
);
SET @tdc_sql_laboratory_doctor := IF(@tdc_laboratory_doctor_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `DoctorID` INT NULL AFTER `VisitID`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_doctor FROM @tdc_sql_laboratory_doctor;
EXECUTE tdc_stmt_laboratory_doctor;
DEALLOCATE PREPARE tdc_stmt_laboratory_doctor;

SET @tdc_laboratory_req_user_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'RequestedByUserID'
);
SET @tdc_sql_laboratory_req_user := IF(@tdc_laboratory_req_user_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `RequestedByUserID` INT NULL AFTER `DoctorID`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_req_user FROM @tdc_sql_laboratory_req_user;
EXECUTE tdc_stmt_laboratory_req_user;
DEALLOCATE PREPARE tdc_stmt_laboratory_req_user;

SET @tdc_laboratory_service_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'ServiceID'
);
SET @tdc_sql_laboratory_service := IF(@tdc_laboratory_service_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `ServiceID` INT NULL AFTER `RequestedByUserID`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_service FROM @tdc_sql_laboratory_service;
EXECUTE tdc_stmt_laboratory_service;
DEALLOCATE PREPARE tdc_stmt_laboratory_service;

SET @tdc_laboratory_workflow_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'WorkflowStatus'
);
SET @tdc_sql_laboratory_workflow := IF(@tdc_laboratory_workflow_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `WorkflowStatus` ENUM("Requested","Awaiting Payment","Ready","In Progress","Completed","Cancelled") NOT NULL DEFAULT "Awaiting Payment" AFTER `PaymentStatus`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_workflow FROM @tdc_sql_laboratory_workflow;
EXECUTE tdc_stmt_laboratory_workflow;
DEALLOCATE PREPARE tdc_stmt_laboratory_workflow;

SET @tdc_laboratory_clinical_result_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'ClinicalResult'
);
SET @tdc_sql_laboratory_clinical_result := IF(@tdc_laboratory_clinical_result_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `ClinicalResult` TEXT NULL AFTER `Result`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_clinical_result FROM @tdc_sql_laboratory_clinical_result;
EXECUTE tdc_stmt_laboratory_clinical_result;
DEALLOCATE PREPARE tdc_stmt_laboratory_clinical_result;

SET @tdc_laboratory_reviewed_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND COLUMN_NAME = 'ReviewedAt'
);
SET @tdc_sql_laboratory_reviewed_at := IF(@tdc_laboratory_reviewed_at_exists = 0,
    'ALTER TABLE `laboratory` ADD COLUMN `ReviewedAt` DATETIME NULL AFTER `ResultDate`',
    'SELECT 1');
PREPARE tdc_stmt_laboratory_reviewed_at FROM @tdc_sql_laboratory_reviewed_at;
EXECUTE tdc_stmt_laboratory_reviewed_at;
DEALLOCATE PREPARE tdc_stmt_laboratory_reviewed_at;

SET @tdc_lab_visit_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND INDEX_NAME = 'idx_laboratory_visit'
);
SET @tdc_sql_lab_visit_idx := IF(@tdc_lab_visit_idx_exists = 0,
    'ALTER TABLE `laboratory` ADD KEY `idx_laboratory_visit` (`VisitID`)',
    'SELECT 1');
PREPARE tdc_stmt_lab_visit_idx FROM @tdc_sql_lab_visit_idx;
EXECUTE tdc_stmt_lab_visit_idx;
DEALLOCATE PREPARE tdc_stmt_lab_visit_idx;

SET @tdc_lab_workflow_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'laboratory'
      AND INDEX_NAME = 'idx_laboratory_workflow'
);
SET @tdc_sql_lab_workflow_idx := IF(@tdc_lab_workflow_idx_exists = 0,
    'ALTER TABLE `laboratory` ADD KEY `idx_laboratory_workflow` (`WorkflowStatus`,`PaymentStatus`,`OrderDate`)',
    'SELECT 1');
PREPARE tdc_stmt_lab_workflow_idx FROM @tdc_sql_lab_workflow_idx;
EXECUTE tdc_stmt_lab_workflow_idx;
DEALLOCATE PREPARE tdc_stmt_lab_workflow_idx;

UPDATE `laboratory`
SET `WorkflowStatus` = CASE
    WHEN `Result` <> 'Pending' THEN 'Completed'
    WHEN `PaymentStatus` = 'Paid' THEN 'Ready'
    WHEN `WorkflowStatus` IN ('','Requested') THEN 'Awaiting Payment'
    ELSE `WorkflowStatus`
END
WHERE `WorkflowStatus` IS NULL OR `WorkflowStatus` = ''
   OR (`Result` <> 'Pending' AND `WorkflowStatus` NOT IN ('Completed','Cancelled'))
   OR (`Result` = 'Pending' AND `PaymentStatus` = 'Paid' AND `WorkflowStatus` NOT IN ('Ready','Completed','Cancelled'))
   OR (`Result` = 'Pending' AND `PaymentStatus` <> 'Paid' AND `WorkflowStatus` NOT IN ('Awaiting Payment','Requested','Ready','Completed','Cancelled'));

-- inventory and purchases: required by pharmacy costing + purchase flow
SET @tdc_inventory_lac_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'LastAcquisitionCostPerUnit'
);
SET @tdc_sql_inventory_lac := IF(@tdc_inventory_lac_exists = 0,
    'ALTER TABLE `inventory` ADD COLUMN `LastAcquisitionCostPerUnit` DECIMAL(10,4) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE tdc_stmt_inventory_lac FROM @tdc_sql_inventory_lac;
EXECUTE tdc_stmt_inventory_lac;
DEALLOCATE PREPARE tdc_stmt_inventory_lac;

SET @tdc_inventory_dpu_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'DefaultPurchaseUnit'
);
SET @tdc_sql_inventory_dpu := IF(@tdc_inventory_dpu_exists = 0,
    'ALTER TABLE `inventory` ADD COLUMN `DefaultPurchaseUnit` VARCHAR(50) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE tdc_stmt_inventory_dpu FROM @tdc_sql_inventory_dpu;
EXECUTE tdc_stmt_inventory_dpu;
DEALLOCATE PREPARE tdc_stmt_inventory_dpu;

SET @tdc_inventory_units_pkg_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'UnitsPerPackage'
);
SET @tdc_sql_inventory_units_pkg := IF(@tdc_inventory_units_pkg_exists = 0,
    'ALTER TABLE `inventory` ADD COLUMN `UnitsPerPackage` INT NULL DEFAULT NULL',
    'SELECT 1');
PREPARE tdc_stmt_inventory_units_pkg FROM @tdc_sql_inventory_units_pkg;
EXECUTE tdc_stmt_inventory_units_pkg;
DEALLOCATE PREPARE tdc_stmt_inventory_units_pkg;

SET @tdc_purchases_itemid_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'ItemID'
);
SET @tdc_sql_purchases_itemid := IF(@tdc_purchases_itemid_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `ItemID` VARCHAR(50) NULL AFTER `SupplierID`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_itemid FROM @tdc_sql_purchases_itemid;
EXECUTE tdc_stmt_purchases_itemid;
DEALLOCATE PREPARE tdc_stmt_purchases_itemid;

SET @tdc_purchases_refnum_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'ReferenceNumber'
);
SET @tdc_sql_purchases_refnum := IF(@tdc_purchases_refnum_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `ReferenceNumber` VARCHAR(100) NULL DEFAULT NULL AFTER `PurchaseDate`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_refnum FROM @tdc_sql_purchases_refnum;
EXECUTE tdc_stmt_purchases_refnum;
DEALLOCATE PREPARE tdc_stmt_purchases_refnum;

SET @tdc_purchases_discount_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'Discount'
);
SET @tdc_sql_purchases_discount := IF(@tdc_purchases_discount_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `Discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `ReferenceNumber`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_discount FROM @tdc_sql_purchases_discount;
EXECUTE tdc_stmt_purchases_discount;
DEALLOCATE PREPARE tdc_stmt_purchases_discount;

SET @tdc_purchases_vat_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'VATAmount'
);
SET @tdc_sql_purchases_vat := IF(@tdc_purchases_vat_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `VATAmount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `Discount`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_vat FROM @tdc_sql_purchases_vat;
EXECUTE tdc_stmt_purchases_vat;
DEALLOCATE PREPARE tdc_stmt_purchases_vat;

SET @tdc_purchases_expiry_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'ExpiryDate'
);
SET @tdc_sql_purchases_expiry := IF(@tdc_purchases_expiry_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `ExpiryDate` DATE NULL DEFAULT NULL AFTER `SellingPrice`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_expiry FROM @tdc_sql_purchases_expiry;
EXECUTE tdc_stmt_purchases_expiry;
DEALLOCATE PREPARE tdc_stmt_purchases_expiry;

SET @tdc_purchases_purchaseunit_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'PurchaseUnit'
);
SET @tdc_sql_purchases_purchaseunit := IF(@tdc_purchases_purchaseunit_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `PurchaseUnit` VARCHAR(50) NULL AFTER `MinimumQuantity`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_purchaseunit FROM @tdc_sql_purchases_purchaseunit;
EXECUTE tdc_stmt_purchases_purchaseunit;
DEALLOCATE PREPARE tdc_stmt_purchases_purchaseunit;

SET @tdc_purchases_conversion_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'ConversionFactor'
);
SET @tdc_sql_purchases_conversion := IF(@tdc_purchases_conversion_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `ConversionFactor` DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER `PurchaseUnit`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_conversion FROM @tdc_sql_purchases_conversion;
EXECUTE tdc_stmt_purchases_conversion;
DEALLOCATE PREPARE tdc_stmt_purchases_conversion;

SET @tdc_purchases_salesunit_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND COLUMN_NAME = 'SalesUnit'
);
SET @tdc_sql_purchases_salesunit := IF(@tdc_purchases_salesunit_exists = 0,
    'ALTER TABLE `purchases` ADD COLUMN `SalesUnit` VARCHAR(50) NULL AFTER `ConversionFactor`',
    'SELECT 1');
PREPARE tdc_stmt_purchases_salesunit FROM @tdc_sql_purchases_salesunit;
EXECUTE tdc_stmt_purchases_salesunit;
DEALLOCATE PREPARE tdc_stmt_purchases_salesunit;

SET @tdc_purchases_item_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchases'
      AND INDEX_NAME = 'idx_purchases_item_date'
);
SET @tdc_sql_purchases_item_idx := IF(@tdc_purchases_item_idx_exists = 0,
    'ALTER TABLE `purchases` ADD KEY `idx_purchases_item_date` (`ItemID`,`PurchaseDate`)',
    'SELECT 1');
PREPARE tdc_stmt_purchases_item_idx FROM @tdc_sql_purchases_item_idx;
EXECUTE tdc_stmt_purchases_item_idx;
DEALLOCATE PREPARE tdc_stmt_purchases_item_idx;

-- pharmacy sales: current app expects sale audit + patient/visit linkage + cost snapshot
SET @tdc_pharmacysales_patient_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND COLUMN_NAME = 'PatientID'
);
SET @tdc_sql_pharmacysales_patient := IF(@tdc_pharmacysales_patient_exists = 0,
    'ALTER TABLE `pharmacysales` ADD COLUMN `PatientID` INT NULL AFTER `CustomerPhone`',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_patient FROM @tdc_sql_pharmacysales_patient;
EXECUTE tdc_stmt_pharmacysales_patient;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_patient;

SET @tdc_pharmacysales_visit_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND COLUMN_NAME = 'VisitID'
);
SET @tdc_sql_pharmacysales_visit := IF(@tdc_pharmacysales_visit_exists = 0,
    'ALTER TABLE `pharmacysales` ADD COLUMN `VisitID` INT NULL AFTER `PatientID`',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_visit FROM @tdc_sql_pharmacysales_visit;
EXECUTE tdc_stmt_pharmacysales_visit;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_visit;

SET @tdc_pharmacysales_sale_status_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND COLUMN_NAME = 'SaleStatus'
);
SET @tdc_sql_pharmacysales_sale_status := IF(@tdc_pharmacysales_sale_status_exists = 0,
    'ALTER TABLE `pharmacysales` ADD COLUMN `SaleStatus` ENUM("Valid","Voided") NOT NULL DEFAULT "Valid" AFTER `PaymentStatus`',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_sale_status FROM @tdc_sql_pharmacysales_sale_status;
EXECUTE tdc_stmt_pharmacysales_sale_status;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_sale_status;

SET @tdc_pharmacysales_cost_snapshot_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND COLUMN_NAME = 'CostPerUnitSnapshot'
);
SET @tdc_sql_pharmacysales_cost_snapshot := IF(@tdc_pharmacysales_cost_snapshot_exists = 0,
    'ALTER TABLE `pharmacysales` ADD COLUMN `CostPerUnitSnapshot` DECIMAL(10,4) NULL DEFAULT NULL AFTER `LineTotal`',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_cost_snapshot FROM @tdc_sql_pharmacysales_cost_snapshot;
EXECUTE tdc_stmt_pharmacysales_cost_snapshot;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_cost_snapshot;

SET @tdc_pharmacysales_linecost_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND COLUMN_NAME = 'LineCost'
);
SET @tdc_sql_pharmacysales_linecost := IF(@tdc_pharmacysales_linecost_exists = 0,
    'ALTER TABLE `pharmacysales` ADD COLUMN `LineCost` DECIMAL(10,2) NULL DEFAULT NULL AFTER `CostPerUnitSnapshot`',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_linecost FROM @tdc_sql_pharmacysales_linecost;
EXECUTE tdc_stmt_pharmacysales_linecost;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_linecost;

SET @tdc_pharmacysales_patient_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND INDEX_NAME = 'idx_pharmacy_sales_patient'
);
SET @tdc_sql_pharmacysales_patient_idx := IF(@tdc_pharmacysales_patient_idx_exists = 0,
    'ALTER TABLE `pharmacysales` ADD KEY `idx_pharmacy_sales_patient` (`PatientID`)',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_patient_idx FROM @tdc_sql_pharmacysales_patient_idx;
EXECUTE tdc_stmt_pharmacysales_patient_idx;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_patient_idx;

SET @tdc_pharmacysales_visit_idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pharmacysales'
      AND INDEX_NAME = 'idx_pharmacy_sales_visit'
);
SET @tdc_sql_pharmacysales_visit_idx := IF(@tdc_pharmacysales_visit_idx_exists = 0,
    'ALTER TABLE `pharmacysales` ADD KEY `idx_pharmacy_sales_visit` (`VisitID`)',
    'SELECT 1');
PREPARE tdc_stmt_pharmacysales_visit_idx FROM @tdc_sql_pharmacysales_visit_idx;
EXECUTE tdc_stmt_pharmacysales_visit_idx;
DEALLOCATE PREPARE tdc_stmt_pharmacysales_visit_idx;

UPDATE `pharmacysales`
SET `SaleStatus` = 'Valid'
WHERE `SaleStatus` IS NULL OR `SaleStatus` = '' OR `SaleStatus` NOT IN ('Valid','Voided');

-- accounting: ensure the ledger needed by revenue/expense reporting exists
CREATE TABLE IF NOT EXISTS `accounting` (
    `EntryID` VARCHAR(50) NOT NULL,
    `AccountID` VARCHAR(50) NOT NULL,
    `AccountName` VARCHAR(150) NOT NULL,
    `AccountType` ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
    `BookType` ENUM('General Journal','Cash Book','Sales Book','Purchases Book') NOT NULL,
    `TransactionDate` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ReferenceID` VARCHAR(50) DEFAULT NULL,
    `Description` TEXT NOT NULL,
    `Debit` DECIMAL(12,2) DEFAULT 0.00,
    `Credit` DECIMAL(12,2) DEFAULT 0.00,
    `Balance` DECIMAL(12,2) DEFAULT 0.00,
    `CreatedAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`EntryID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- add any missing accounting columns while preserving existing rows
SET @tdc_accounting_booktype_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounting'
      AND COLUMN_NAME = 'BookType'
);
SET @tdc_sql_accounting_booktype := IF(@tdc_accounting_booktype_exists = 0,
    'ALTER TABLE `accounting` ADD COLUMN `BookType` ENUM("General Journal","Cash Book","Sales Book","Purchases Book") NOT NULL DEFAULT "General Journal" AFTER `AccountType`',
    'SELECT 1');
PREPARE tdc_stmt_accounting_booktype FROM @tdc_sql_accounting_booktype;
EXECUTE tdc_stmt_accounting_booktype;
DEALLOCATE PREPARE tdc_stmt_accounting_booktype;

SET @tdc_accounting_reference_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounting'
      AND COLUMN_NAME = 'ReferenceID'
);
SET @tdc_sql_accounting_reference := IF(@tdc_accounting_reference_exists = 0,
    'ALTER TABLE `accounting` ADD COLUMN `ReferenceID` VARCHAR(50) NULL AFTER `TransactionDate`',
    'SELECT 1');
PREPARE tdc_stmt_accounting_reference FROM @tdc_sql_accounting_reference;
EXECUTE tdc_stmt_accounting_reference;
DEALLOCATE PREPARE tdc_stmt_accounting_reference;

SET @tdc_accounting_description_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounting'
      AND COLUMN_NAME = 'Description'
);
SET @tdc_sql_accounting_description := IF(@tdc_accounting_description_exists = 0,
    'ALTER TABLE `accounting` ADD COLUMN `Description` TEXT NOT NULL AFTER `ReferenceID`',
    'SELECT 1');
PREPARE tdc_stmt_accounting_description FROM @tdc_sql_accounting_description;
EXECUTE tdc_stmt_accounting_description;
DEALLOCATE PREPARE tdc_stmt_accounting_description;

-- payment methods: seed defaults only where they do not already exist
INSERT IGNORE INTO `paymentmethods` (`MethodName`,`Description`,`IsActive`,`DisplayOrder`) VALUES
('Cash','Physical cash received at the cashier desk.',1,1),
('EVC Plus','Manual EVC Plus mobile wallet transfer (recorded by hand).',1,2),
('Mobile Money','Manual mobile money transfer (recorded by hand).',1,3),
('Bank Transfer','Manual bank transfer (recorded by hand).',1,4),
('Card','Manual card terminal settlement (recorded by hand).',1,5),
('Cheque','Manual cheque deposit (recorded by hand).',1,6),
('Other','Any other manual settlement method.',1,7);

-- clinic settings and system roles are safe to seed without touching live records
INSERT IGNORE INTO `clinicsettings` (`SettingKey`,`SettingValue`) VALUES
('ClinicName','Tarey Derma Clinic'),
('Currency','USD');

INSERT IGNORE INTO `roles` (`RoleKey`,`RoleName`,`Description`,`IsSystem`,`IsProtected`,`IsActive`) VALUES
('superuser','SuperAdmin','Full system administration and operational access.',1,1,1),
('receptionuser','Reception','Combined reception, pharmacy, laboratory and accounting operations. Acquisition cost and administration remain restricted.',1,1,1),
('doctoruser','Doctor','Assigned consultations, prescriptions and laboratory requests.',1,1,1),
('labuser','Laboratory','Laboratory order processing and clinical results.',1,1,1),
('pharmacyuser','Pharmacy','Prescription dispensing and point-of-sale operations.',1,1,1);

-- ------------------------------------------------------------------------
-- 3) Permission catalogue compatibility check (safe additive insert only)
-- ------------------------------------------------------------------------
-- The app checks for specific permission keys such as:
--    reports.income.cost.view
--    setup.users.manage
--    pharmacy.purchase_cost.view
--    doctor.workspace
--    etc.
-- This is intentionally additive and does not alter or remove live grants.

INSERT IGNORE INTO `permissions` (`PermissionKey`, `ModuleName`, `ResourceName`, `ActionName`, `Description`) VALUES
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
('setup.system.manage','System','System setup','manage','Manage system settings and audit log'),
('payments.reverse','Accounting','Payments','reverse','Reverse or void recorded manual payments'),
('pharmacy.purchase_cost.view','Pharmacy','Purchase costs','view','View confidential supplier acquisition costs');

-- ------------------------------------------------------------------------
-- 4) Final safety note
-- ------------------------------------------------------------------------
-- This script intentionally does not:
--   * delete rows from any operational table
--   * drop existing tables
--   * truncate live data
--   * reset historical accounting records
--   * rewrite existing money totals for already-recorded transactions
--
-- It only adds missing schema objects and safe legacy defaults where the
-- information is already present in the data itself.
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
