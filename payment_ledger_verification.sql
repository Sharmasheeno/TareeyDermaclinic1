-- Tarey Derma Clinic - READ-ONLY payment ledger verification
-- Target: if0_42914892_tareydermaclinic_new
-- Run manually before importing payment_ledger_safe_migration.sql.
-- SELECT-only / information_schema only. No ALTER, INSERT, UPDATE, DELETE,
-- DROP, TRUNCATE, CREATE, or REPLACE statements.

SELECT DATABASE() AS current_database;

SELECT
    c.TABLE_NAME,
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_DEFAULT,
    c.ORDINAL_POSITION
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = 'if0_42914892_tareydermaclinic_new'
  AND c.TABLE_NAME = 'payments'
  AND c.COLUMN_NAME IN (
      'PaymentID', 'PaymentReference', 'PatientID', 'VisitID', 'LaboratoryID',
      'PrescriptionReference', 'SaleReference', 'PurchaseReference',
      'PaymentType', 'Amount', 'PaymentMethod', 'PaymentStatus', 'ReceivedBy',
      'PaidAt', 'Notes', 'ReversalOfPaymentID', 'ReversalReference',
      'ReversalReason'
  )
ORDER BY c.ORDINAL_POSITION;

SELECT
    s.TABLE_NAME,
    s.INDEX_NAME,
    s.COLUMN_NAME,
    s.NON_UNIQUE,
    s.SEQ_IN_INDEX
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = 'if0_42914892_tareydermaclinic_new'
  AND s.TABLE_NAME = 'payments'
  AND s.INDEX_NAME IN ('idx_payments_sale', 'idx_payments_purchase', 'idx_payments_reversal')
ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX;

SELECT
    'RX000001' AS prescription_reference,
    CASE WHEN c.COLUMN_NAME IS NULL THEN 0 ELSE 1 END AS prescription_reference_column_exists,
    c.COLUMN_TYPE AS prescription_reference_type
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = 'if0_42914892_tareydermaclinic_new'
  AND c.TABLE_NAME = 'payments'
  AND c.COLUMN_NAME = 'PrescriptionReference';

SELECT
    'POS000001' AS sale_reference,
    CASE WHEN c.COLUMN_NAME IS NULL THEN 0 ELSE 1 END AS sale_reference_column_exists,
    c.COLUMN_TYPE AS sale_reference_type
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = 'if0_42914892_tareydermaclinic_new'
  AND c.TABLE_NAME = 'payments'
  AND c.COLUMN_NAME = 'SaleReference';
