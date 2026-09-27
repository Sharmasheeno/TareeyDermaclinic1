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
