<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "Added {$table}.{$column}\n";
    }
}

$pdo->exec('ALTER TABLE users MODIFY role VARCHAR(100) NOT NULL');

add_column($pdo, 'Doctors', 'UserID', 'INT NULL AFTER DoctorID');
if (!index_exists($pdo, 'Doctors', 'uq_doctors_user')) {
    $pdo->exec('ALTER TABLE Doctors ADD UNIQUE KEY uq_doctors_user (UserID)');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS LabServices (
        ServiceID INT NOT NULL AUTO_INCREMENT,
        ServiceName VARCHAR(150) NOT NULL,
        Category VARCHAR(100) NULL,
        Description VARCHAR(500) NULL,
        Price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        IsAvailable TINYINT(1) NOT NULL DEFAULT 1,
        IsActive TINYINT(1) NOT NULL DEFAULT 1,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (ServiceID),
        UNIQUE KEY uq_lab_services_name (ServiceName),
        KEY idx_lab_services_active (IsActive, ServiceName)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
add_column($pdo, 'LabServices', 'Description', 'VARCHAR(500) NULL AFTER Category');
add_column($pdo, 'LabServices', 'IsAvailable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER Price');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS Visits (
        VisitID INT NOT NULL AUTO_INCREMENT,
        VisitReference VARCHAR(50) NOT NULL,
        PatientID INT NOT NULL,
        DoctorID INT NOT NULL,
        ReceptionistUserID INT NULL,
        VisitDate DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ConsultationFee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        AmountPaid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        DueBalance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PaymentStatus ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
        QueueStatus ENUM('Pending Payment','Waiting','In Consultation','Completed','Cancelled') NOT NULL DEFAULT 'Pending Payment',
        ChiefComplaint TEXT NULL,
        ClinicalNotes TEXT NULL,
        Diagnosis TEXT NULL,
        TreatmentPlan TEXT NULL,
        FollowUpPlan TEXT NULL,
        FollowUpDate DATE NULL,
        CompletedAt DATETIME NULL,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (VisitID),
        UNIQUE KEY uq_visits_reference (VisitReference),
        KEY idx_visits_patient (PatientID),
        KEY idx_visits_doctor_queue (DoctorID, QueueStatus, VisitDate),
        KEY idx_visits_payment (PaymentStatus)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS Payments (
        PaymentID BIGINT NOT NULL AUTO_INCREMENT,
        PaymentReference VARCHAR(50) NOT NULL,
        PatientID INT NOT NULL,
        VisitID INT NULL,
        LaboratoryID VARCHAR(50) NULL,
        PrescriptionReference VARCHAR(50) NULL,
        PaymentType ENUM('Consultation','Laboratory','Pharmacy') NOT NULL,
        Amount DECIMAL(10,2) NOT NULL,
        PaymentMethod ENUM('Cash','Card','Mobile Money','Bank','Other') NOT NULL DEFAULT 'Cash',
        PaymentStatus ENUM('Confirmed','Voided') NOT NULL DEFAULT 'Confirmed',
        ReceivedBy INT NULL,
        PaidAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Notes VARCHAR(500) NULL,
        PRIMARY KEY (PaymentID),
        UNIQUE KEY uq_payments_reference (PaymentReference),
        KEY idx_payments_patient (PatientID),
        KEY idx_payments_visit (VisitID),
        KEY idx_payments_type_date (PaymentType, PaidAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

add_column($pdo, 'Prescriptions', 'VisitID', 'INT NULL AFTER PatientID');
add_column($pdo, 'Prescriptions', 'Quantity', 'INT NOT NULL DEFAULT 1 AFTER MedicationName');
add_column($pdo, 'Prescriptions', 'Status', "ENUM('Pending','Dispensed','Cancelled') NOT NULL DEFAULT 'Pending' AFTER Instructions");
add_column($pdo, 'Prescriptions', 'DispensedAt', 'DATETIME NULL AFTER Status');
add_column($pdo, 'Prescriptions', 'DispensedBy', 'INT NULL AFTER DispensedAt');
add_column($pdo, 'Prescriptions', 'PharmacySaleReference', 'VARCHAR(50) NULL AFTER DispensedBy');
if (!index_exists($pdo, 'Prescriptions', 'idx_prescriptions_visit')) {
    $pdo->exec('ALTER TABLE Prescriptions ADD KEY idx_prescriptions_visit (VisitID)');
}
if (!index_exists($pdo, 'Prescriptions', 'idx_prescriptions_status')) {
    $pdo->exec('ALTER TABLE Prescriptions ADD KEY idx_prescriptions_status (Status, PrescriptionDate)');
}

add_column($pdo, 'Laboratory', 'VisitID', 'INT NULL AFTER PatientID');
add_column($pdo, 'Laboratory', 'DoctorID', 'INT NULL AFTER VisitID');
add_column($pdo, 'Laboratory', 'RequestedByUserID', 'INT NULL AFTER DoctorID');
add_column($pdo, 'Laboratory', 'ServiceID', 'INT NULL AFTER RequestedByUserID');
add_column($pdo, 'Laboratory', 'WorkflowStatus', "ENUM('Requested','Awaiting Payment','Ready','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Awaiting Payment' AFTER PaymentStatus");
add_column($pdo, 'Laboratory', 'ClinicalResult', 'TEXT NULL AFTER Result');
add_column($pdo, 'Laboratory', 'ReviewedAt', 'DATETIME NULL AFTER ResultDate');
if (!index_exists($pdo, 'Laboratory', 'idx_laboratory_visit')) {
    $pdo->exec('ALTER TABLE Laboratory ADD KEY idx_laboratory_visit (VisitID)');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS LabOrderItems (
        LabOrderItemID BIGINT NOT NULL AUTO_INCREMENT,
        LaboratoryID VARCHAR(50) NOT NULL,
        ServiceID INT NOT NULL,
        TestName VARCHAR(150) NOT NULL,
        UnitPrice DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        Result ENUM('Positive','Negative','Pending') NOT NULL DEFAULT 'Pending',
        ClinicalResult TEXT NULL,
        ResultDate DATETIME NULL,
        PRIMARY KEY (LabOrderItemID),
        UNIQUE KEY uq_lab_order_service (LaboratoryID,ServiceID),
        KEY idx_lab_order_items_order (LaboratoryID),
        KEY idx_lab_order_items_service (ServiceID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);
if (!index_exists($pdo, 'Laboratory', 'idx_laboratory_workflow')) {
    $pdo->exec('ALTER TABLE Laboratory ADD KEY idx_laboratory_workflow (WorkflowStatus, PaymentStatus, OrderDate)');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS Notifications (
        NotificationID BIGINT NOT NULL AUTO_INCREMENT,
        UserID INT NULL,
        RoleTarget ENUM('superuser','receptionuser','doctoruser','pharmacyuser','labuser') NULL,
        EventType VARCHAR(50) NOT NULL,
        Title VARCHAR(150) NOT NULL,
        Message VARCHAR(500) NOT NULL,
        Link VARCHAR(255) NULL,
        IsRead TINYINT(1) NOT NULL DEFAULT 0,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (NotificationID),
        KEY idx_notifications_user (UserID, IsRead, CreatedAt),
        KEY idx_notifications_role (RoleTarget, IsRead, CreatedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$pdo->exec("UPDATE Laboratory SET WorkflowStatus='Completed' WHERE Result <> 'Pending' AND WorkflowStatus NOT IN ('Completed','Cancelled')");
$pdo->exec("UPDATE Laboratory SET WorkflowStatus='Ready' WHERE Result='Pending' AND PaymentStatus='Paid' AND WorkflowStatus IN ('Requested','Awaiting Payment')");
$pdo->exec("UPDATE Laboratory SET WorkflowStatus='Awaiting Payment' WHERE Result='Pending' AND PaymentStatus <> 'Paid' AND WorkflowStatus='Requested'");

echo "Connected clinic workflow schema is ready.\n";
