<?php
declare(strict_types=1);

// Adds optional default purchase-packaging to the medicine master record.
// Additive only: existing medicines keep NULL packaging (ConversionFactor = 1).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

function tdc_pkg_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tdc_pkg_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!tdc_pkg_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "added $table.$column" . PHP_EOL;
    } else {
        echo "exists $table.$column" . PHP_EOL;
    }
}

tdc_pkg_add_column($pdo, 'Inventory', 'DefaultPurchaseUnit', 'VARCHAR(50) NULL DEFAULT NULL');
tdc_pkg_add_column($pdo, 'Inventory', 'UnitsPerPackage', 'INT NULL DEFAULT NULL');

echo 'migration complete' . PHP_EOL;

