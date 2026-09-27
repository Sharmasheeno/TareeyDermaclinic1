<?php
require __DIR__ . '/../db.php';

$queries = [
    'doctors' => 'SELECT DoctorID, DoctorName, UserID FROM doctors ORDER BY DoctorID LIMIT 10',
    'patients' => 'SELECT PatientID, PatientName, PatientPhone FROM patients ORDER BY PatientID LIMIT 10',
    'visits' => 'SELECT VisitID, PatientID, DoctorID, VisitReference, QueueStatus FROM visits ORDER BY VisitID DESC LIMIT 10',
    'tests' => 'SELECT TestID, TestName, Price, ResultMode, IsActive FROM lab_tests WHERE IsActive = 1 ORDER BY TestID LIMIT 20',
    'parameters' => 'SELECT p.ParameterID, p.TestID, p.ParameterName, p.ResultType, p.NormalMinimum, p.NormalMaximum, p.IsRequired, u.UnitName, u.UnitSymbol FROM lab_parameters p LEFT JOIN lab_units u ON u.UnitID = p.UnitID WHERE p.IsActive = 1 ORDER BY p.TestID, p.ParameterID LIMIT 40',
    'flags' => 'SELECT FlagID, FlagCode, FlagName FROM lab_flags WHERE IsActive = 1 ORDER BY FlagCode',
];

foreach ($queries as $label => $sql) {
    echo "## $label\n";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "(no rows)\n\n";
        continue;
    }
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
    }
    echo "\n";
}
