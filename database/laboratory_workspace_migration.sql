-- Additive laboratory workspace migration for Tareey Derma Clinic.
-- Production tables are left untouched: laboratory, laborderitems, labservices, payments.
-- This migration creates only modern additive catalog, selection, bridge, and result tables.

CREATE TABLE IF NOT EXISTS lab_categories (
    CategoryID INT NOT NULL AUTO_INCREMENT,
    CategoryName VARCHAR(120) NOT NULL,
    Description TEXT NULL,
    DisplayOrder INT NOT NULL DEFAULT 0,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (CategoryID),
    UNIQUE KEY uq_lab_categories_name (CategoryName),
    KEY idx_lab_categories_active (IsActive, DisplayOrder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_types (
    TypeID INT NOT NULL AUTO_INCREMENT,
    CategoryID INT NOT NULL,
    TypeName VARCHAR(150) NOT NULL,
    Description TEXT NULL,
    DisplayOrder INT NOT NULL DEFAULT 0,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (TypeID),
    UNIQUE KEY uq_lab_types_name (CategoryID, TypeName),
    KEY idx_lab_types_category (CategoryID, IsActive),
    CONSTRAINT fk_lab_types_category FOREIGN KEY (CategoryID) REFERENCES lab_categories(CategoryID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_tests (
    TestID INT NOT NULL AUTO_INCREMENT,
    TypeID INT NOT NULL,
    TestName VARCHAR(180) NOT NULL,
    Description TEXT NULL,
    Price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ResultMode ENUM('Structured Parameters','Single Result') NOT NULL DEFAULT 'Structured Parameters',
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    DisplayOrder INT NOT NULL DEFAULT 0,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (TestID),
    UNIQUE KEY uq_lab_tests_name (TypeID, TestName),
    KEY idx_lab_tests_type (TypeID, IsActive),
    CONSTRAINT fk_lab_tests_type FOREIGN KEY (TypeID) REFERENCES lab_types(TypeID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_units (
    UnitID INT NOT NULL AUTO_INCREMENT,
    UnitName VARCHAR(80) NOT NULL,
    UnitSymbol VARCHAR(40) NOT NULL,
    Description TEXT NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (UnitID),
    UNIQUE KEY uq_lab_units_symbol (UnitSymbol),
    UNIQUE KEY uq_lab_units_name (UnitName),
    KEY idx_lab_units_active (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_flags (
    FlagID INT NOT NULL AUTO_INCREMENT,
    FlagName VARCHAR(60) NOT NULL,
    FlagCode VARCHAR(20) NOT NULL,
    Description VARCHAR(200) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (FlagID),
    UNIQUE KEY uq_lab_flags_code (FlagCode),
    UNIQUE KEY uq_lab_flags_name (FlagName),
    KEY idx_lab_flags_active (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_parameters (
    ParameterID INT NOT NULL AUTO_INCREMENT,
    TestID INT NOT NULL,
    ParameterName VARCHAR(150) NOT NULL,
    ResultType ENUM('Numeric','Text','Positive/Negative','Select') NOT NULL DEFAULT 'Numeric',
    UnitID INT NULL,
    ReferenceRange VARCHAR(150) NULL,
    NormalMinimum DECIMAL(18,6) NULL,
    NormalMaximum DECIMAL(18,6) NULL,
    DisplayOrder INT NOT NULL DEFAULT 0,
    IsRequired TINYINT(1) NOT NULL DEFAULT 1,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    SelectChoices TEXT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ParameterID),
    UNIQUE KEY uq_lab_parameters_test_name (TestID, ParameterName),
    KEY idx_lab_parameters_test (TestID, IsActive),
    KEY idx_lab_parameters_unit (UnitID),
    CONSTRAINT fk_lab_parameters_test FOREIGN KEY (TestID) REFERENCES lab_tests(TestID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_parameters_unit FOREIGN KEY (UnitID) REFERENCES lab_units(UnitID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_centers (
    LabCenterID INT NOT NULL AUTO_INCREMENT,
    CenterName VARCHAR(150) NOT NULL,
    Location VARCHAR(200) NULL,
    Phone VARCHAR(50) NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (LabCenterID),
    UNIQUE KEY uq_lab_centers_name (CenterName),
    KEY idx_lab_centers_active (IsActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_center_tests (
    CenterTestID INT NOT NULL AUTO_INCREMENT,
    LabCenterID INT NOT NULL,
    TestID INT NOT NULL,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (CenterTestID),
    UNIQUE KEY uq_lab_center_test (LabCenterID, TestID),
    KEY idx_lab_center_tests_center (LabCenterID, IsActive),
    KEY idx_lab_center_tests_test (TestID, IsActive),
    CONSTRAINT fk_lab_center_tests_center FOREIGN KEY (LabCenterID) REFERENCES lab_centers(LabCenterID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_center_tests_test FOREIGN KEY (TestID) REFERENCES lab_tests(TestID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_test_selection (
    SelectionID INT NOT NULL AUTO_INCREMENT,
    TestID INT NOT NULL,
    DoctorID INT NOT NULL,
    LabCenterID INT NOT NULL,
    IsEnabled TINYINT(1) NOT NULL DEFAULT 1,
    Notes TEXT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (SelectionID),
    UNIQUE KEY uq_lab_test_selection (TestID, DoctorID, LabCenterID),
    KEY idx_lab_test_selection_enabled (IsEnabled, TestID),
    KEY idx_lab_test_selection_doctor (DoctorID, IsEnabled),
    KEY idx_lab_test_selection_center (LabCenterID, IsEnabled),
    CONSTRAINT fk_lab_test_selection_test FOREIGN KEY (TestID) REFERENCES lab_tests(TestID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_test_selection_doctor FOREIGN KEY (DoctorID) REFERENCES doctors(DoctorID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_test_selection_center FOREIGN KEY (LabCenterID) REFERENCES lab_centers(LabCenterID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_order_catalog_bridge (
    BridgeID BIGINT NOT NULL AUTO_INCREMENT,
    LaboratoryID VARCHAR(50) NOT NULL,
    ModernTestID INT NULL,
    LegacyServiceID INT NULL,
    LegacyTestID INT NULL,
    LegacyTestNameSnapshot VARCHAR(150) NULL,
    ModernTestNameSnapshot VARCHAR(180) NULL,
    PriceSnapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    SourceType ENUM('legacy','modern','mixed') NOT NULL DEFAULT 'modern',
    DisplayOrder INT NOT NULL DEFAULT 0,
    IsActive TINYINT(1) NOT NULL DEFAULT 1,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (BridgeID),
    UNIQUE KEY uq_lab_bridge_modern_test (LaboratoryID, ModernTestID),
    UNIQUE KEY uq_lab_bridge_legacy_service (LaboratoryID, LegacyServiceID),
    KEY idx_lab_bridge_laboratory (LaboratoryID),
    KEY idx_lab_bridge_modern_test_lookup (ModernTestID),
    KEY idx_lab_bridge_service (LegacyServiceID),
    KEY idx_lab_bridge_active (IsActive),
    CONSTRAINT fk_lab_bridge_laboratory FOREIGN KEY (LaboratoryID) REFERENCES laboratory(LaboratoryID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_bridge_modern_test FOREIGN KEY (ModernTestID) REFERENCES lab_tests(TestID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_bridge_service FOREIGN KEY (LegacyServiceID) REFERENCES labservices(ServiceID) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_results (
    LabResultID BIGINT NOT NULL AUTO_INCREMENT,
    BridgeID BIGINT NOT NULL,
    LabCenterID INT NULL,
    ResultStatus ENUM('Draft','Processing','Completed','Reviewed','Cancelled') NOT NULL DEFAULT 'Draft',
    CollectedBy INT NULL,
    CollectedAt DATETIME NULL,
    CompletedBy INT NULL,
    CompletedAt DATETIME NULL,
    ReviewedBy INT NULL,
    ReviewedAt DATETIME NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (LabResultID),
    UNIQUE KEY uq_lab_results_bridge (BridgeID),
    KEY idx_lab_results_center (LabCenterID),
    KEY idx_lab_results_status (ResultStatus),
    CONSTRAINT fk_lab_results_bridge FOREIGN KEY (BridgeID) REFERENCES lab_order_catalog_bridge(BridgeID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_results_center FOREIGN KEY (LabCenterID) REFERENCES lab_centers(LabCenterID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lab_results_collected_by FOREIGN KEY (CollectedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lab_results_completed_by FOREIGN KEY (CompletedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lab_results_reviewed_by FOREIGN KEY (ReviewedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_result_parameters (
    LabResultParameterID BIGINT NOT NULL AUTO_INCREMENT,
    LabResultID BIGINT NOT NULL,
    ParameterID INT NULL,
    ParameterNameSnapshot VARCHAR(180) NOT NULL,
    ResultTypeSnapshot VARCHAR(50) NULL,
    UnitID INT NULL,
    UnitNameSnapshot VARCHAR(100) NULL,
    ReferenceRangeSnapshot VARCHAR(255) NULL,
    NormalMinimumSnapshot DECIMAL(18,6) NULL,
    NormalMaximumSnapshot DECIMAL(18,6) NULL,
    RawResult TEXT NULL,
    DisplayResult TEXT NULL,
    FlagID INT NULL,
    FlagCodeSnapshot VARCHAR(30) NULL,
    FlagNameSnapshot VARCHAR(100) NULL,
    Remark VARCHAR(500) NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (LabResultParameterID),
    UNIQUE KEY uq_lab_result_parameter_once (LabResultID, ParameterID),
    KEY idx_lab_result_parameters_result (LabResultID),
    KEY idx_lab_result_parameters_parameter (ParameterID),
    KEY idx_lab_result_parameters_unit (UnitID),
    KEY idx_lab_result_parameters_flag (FlagID),
    CONSTRAINT fk_lab_result_parameters_result FOREIGN KEY (LabResultID) REFERENCES lab_results(LabResultID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_result_parameters_parameter FOREIGN KEY (ParameterID) REFERENCES lab_parameters(ParameterID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_result_parameters_unit FOREIGN KEY (UnitID) REFERENCES lab_units(UnitID) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_lab_result_parameters_flag FOREIGN KEY (FlagID) REFERENCES lab_flags(FlagID) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_result_attachments (
    AttachmentID BIGINT NOT NULL AUTO_INCREMENT,
    LabResultID BIGINT NOT NULL,
    OriginalFileName VARCHAR(255) NOT NULL,
    StoredFileName VARCHAR(255) NOT NULL,
    MimeType VARCHAR(100) NULL,
    FileSize INT NOT NULL DEFAULT 0,
    UploadedBy INT NULL,
    UploadedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (AttachmentID),
    KEY idx_lab_result_attachments_result (LabResultID),
    CONSTRAINT fk_lab_result_attachments_result FOREIGN KEY (LabResultID) REFERENCES lab_results(LabResultID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_result_attachments_user FOREIGN KEY (UploadedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_result_review (
    ReviewID BIGINT NOT NULL AUTO_INCREMENT,
    LabResultID BIGINT NOT NULL,
    Action VARCHAR(80) NOT NULL,
    Notes TEXT NULL,
    PerformedBy INT NULL,
    PerformedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ReviewID),
    KEY idx_lab_result_review_result (LabResultID, PerformedAt),
    CONSTRAINT fk_lab_result_review_result FOREIGN KEY (LabResultID) REFERENCES lab_results(LabResultID) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lab_result_review_user FOREIGN KEY (PerformedBy) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive)
SELECT 'Hematology', 'Blood and blood-cell analyses', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM lab_categories WHERE CategoryName = 'Hematology');

INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive)
SELECT 'Biochemistry', 'Chemical and metabolic analyses', 2, 1
WHERE NOT EXISTS (SELECT 1 FROM lab_categories WHERE CategoryName = 'Biochemistry');

INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive)
SELECT 'Microbiology', 'Culture and infectious disease tests', 3, 1
WHERE NOT EXISTS (SELECT 1 FROM lab_categories WHERE CategoryName = 'Microbiology');

INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive)
SELECT 'Urinalysis', 'Urine and urinary screening tests', 4, 1
WHERE NOT EXISTS (SELECT 1 FROM lab_categories WHERE CategoryName = 'Urinalysis');

INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive)
SELECT 'Serology', 'Immune and antibody screening', 5, 1
WHERE NOT EXISTS (SELECT 1 FROM lab_categories WHERE CategoryName = 'Serology');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT 'Grams per deciliter', 'g/dL', 'Standard blood chemistry unit', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = 'g/dL');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT 'Milligrams per deciliter', 'mg/dL', 'Standard chemistry value', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = 'mg/dL');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT 'Millimoles per litre', 'mmol/L', 'Biochemistry unit', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = 'mmol/L');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT 'Percent', '%', 'Percentage', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = '%');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT 'International units per litre', 'IU/L', 'Clinical laboratory unit', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = 'IU/L');

INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive)
SELECT '10^9 per litre', '10^9/L', 'Cell concentration', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_units WHERE UnitSymbol = '10^9/L');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Normal', 'N', 'Within expected range', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'N');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Low', 'L', 'Below expected reference', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'L');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'High', 'H', 'Above expected reference', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'H');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Abnormal', 'A', 'Outside expected range', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'A');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Critical', 'C', 'Urgent clinical concern', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'C');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Positive', 'POS', 'Positive laboratory finding', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'POS');

INSERT INTO lab_flags (FlagName, FlagCode, Description, IsActive)
SELECT 'Negative', 'NEG', 'Negative laboratory finding', 1
WHERE NOT EXISTS (SELECT 1 FROM lab_flags WHERE FlagCode = 'NEG');
