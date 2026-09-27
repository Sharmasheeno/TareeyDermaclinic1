-- Tarey Derma Clinic - additive payment ledger migration
-- Target: if0_42914892_tareydermaclinic_new
-- MANUAL REVIEW ONLY. Do not execute automatically.
-- No DROP, TRUNCATE, DELETE, table recreation, or data updates.
-- Definitions are derived from database/production_install.sql.

ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `SaleReference` VARCHAR(50) NULL DEFAULT NULL AFTER `PrescriptionReference`,
    ADD COLUMN IF NOT EXISTS `PurchaseReference` VARCHAR(50) NULL DEFAULT NULL AFTER `SaleReference`,
    ADD COLUMN IF NOT EXISTS `ReversalOfPaymentID` BIGINT(20) NULL DEFAULT NULL AFTER `PaymentStatus`,
    ADD COLUMN IF NOT EXISTS `ReversalReference` VARCHAR(50) NULL DEFAULT NULL AFTER `ReversalOfPaymentID`,
    ADD COLUMN IF NOT EXISTS `ReversalReason` VARCHAR(500) NULL DEFAULT NULL AFTER `ReversalReference`;

CREATE INDEX IF NOT EXISTS `idx_payments_sale` ON `payments` (`SaleReference`);
CREATE INDEX IF NOT EXISTS `idx_payments_purchase` ON `payments` (`PurchaseReference`);
CREATE INDEX IF NOT EXISTS `idx_payments_reversal` ON `payments` (`ReversalOfPaymentID`);
