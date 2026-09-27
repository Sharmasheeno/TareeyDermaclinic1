<?php
require __DIR__ . '/../db.php';
$tables = ['laboratory','lab_order_catalog_bridge','lab_results','lab_result_parameters','lab_parameters','lab_tests','lab_units','lab_flags'];
foreach ($tables as $table) {
    $stmt = $pdo->query('SHOW COLUMNS FROM `'.$table.'`');
    echo "TABLE $table\n";
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($cols, JSON_PRETTY_PRINT), "\n\n";
}
