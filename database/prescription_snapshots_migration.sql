-- Additive, rerunnable. NULL means historical cost/price unknown. No backfill.
SET @tdc_rx_cost_sql = (SELECT IF(COUNT(*)=0,'ALTER TABLE prescriptions ADD COLUMN CostPerUnitSnapshot DECIMAL(10,4) NULL DEFAULT NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prescriptions' AND COLUMN_NAME='CostPerUnitSnapshot');
PREPARE tdc_rx_stmt FROM @tdc_rx_cost_sql;
EXECUTE tdc_rx_stmt;
DEALLOCATE PREPARE tdc_rx_stmt;
SET @tdc_rx_price_sql = (SELECT IF(COUNT(*)=0,'ALTER TABLE prescriptions ADD COLUMN UnitPriceSnapshot DECIMAL(10,2) NULL DEFAULT NULL','SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prescriptions' AND COLUMN_NAME='UnitPriceSnapshot');
PREPARE tdc_rx_stmt FROM @tdc_rx_price_sql;
EXECUTE tdc_rx_stmt;
DEALLOCATE PREPARE tdc_rx_stmt;
