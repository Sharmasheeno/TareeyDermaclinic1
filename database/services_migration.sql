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
