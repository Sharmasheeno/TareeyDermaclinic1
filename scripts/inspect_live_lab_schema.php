<?php
require __DIR__ . '/../db.php';

$tables = ['lab_results', 'lab_result_parameters', 'lab_order_catalog_bridge', 'lab_tests', 'lab_parameters', 'lab_flags', 'lab_units'];
foreach ($tables as $table) {
    echo "TABLE $table\n";
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo json_encode($col, JSON_UNESCAPED_SLASHES), "\n";
    }
    echo "\n";
}
