<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth/includes/operational-role-defaults.php';
$tables = 'Patients,Visits,Inventory,Purchases,PharmacySales,Prescriptions,Laboratory,LabOrderItems,Accounting,Payments,Doctors,users';
$before = $pdo->query('CHECKSUM TABLE '.$tables)->fetchAll(PDO::FETCH_KEY_PAIR);
tdc_apply_operational_role_defaults($pdo);
$after = $pdo->query('CHECKSUM TABLE '.$tables)->fetchAll(PDO::FETCH_KEY_PAIR);
if ($before !== $after) throw new RuntimeException('Clinic data checksum changed unexpectedly.');
echo "Operational role grants updated. No clinic records or tables changed.\n";
