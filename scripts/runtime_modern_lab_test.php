<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth/includes/lab-results.php';

function tdc_print($label, $value): void
{
    echo $label . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

// --- ensure master data exists ---
$categoryId = (int) $pdo->query("SELECT CategoryID FROM lab_categories WHERE CategoryName = 'Biochemistry' LIMIT 1")->fetchColumn();
if ($categoryId <= 0) {
    $pdo->prepare('INSERT INTO lab_categories (CategoryName, Description, DisplayOrder, IsActive) VALUES (?, ?, ?, 1)')->execute(['Biochemistry', 'Runtime verification test', 1]);
    $categoryId = (int) $pdo->lastInsertId();
}

$typeId = (int) $pdo->query("SELECT TypeID FROM lab_types WHERE CategoryID = $categoryId AND TypeName = 'Clinical Chemistry' LIMIT 1")->fetchColumn();
if ($typeId <= 0) {
    $pdo->prepare('INSERT INTO lab_types (CategoryID, TypeName, Description, DisplayOrder, IsActive) VALUES (?, ?, ?, ?, 1)')->execute([$categoryId, 'Clinical Chemistry', 'Runtime verification test', 1]);
    $typeId = (int) $pdo->lastInsertId();
}

$unitId = (int) $pdo->query("SELECT UnitID FROM lab_units WHERE UnitSymbol = 'mg/dL' LIMIT 1")->fetchColumn();
if ($unitId <= 0) {
    $pdo->prepare('INSERT INTO lab_units (UnitName, UnitSymbol, Description, IsActive) VALUES (?, ?, ?, 1)')->execute(['Milligrams per deciliter', 'mg/dL', 'Runtime verification test']);
    $unitId = (int) $pdo->lastInsertId();
}

$testId = (int) $pdo->query("SELECT TestID FROM lab_tests WHERE TestName = 'Runtime Glucose' AND TypeID = $typeId LIMIT 1")->fetchColumn();
if ($testId <= 0) {
    $pdo->prepare('INSERT INTO lab_tests (TypeID, TestName, Description, Price, ResultMode, IsActive, DisplayOrder) VALUES (?, ?, ?, ?, ?, 1, 1)')->execute([$typeId, 'Runtime Glucose', 'Runtime verification test', 25.00, 'Structured Parameters']);
    $testId = (int) $pdo->lastInsertId();
}

$parameterId = (int) $pdo->query("SELECT ParameterID FROM lab_parameters WHERE TestID = $testId AND ParameterName = 'Blood Glucose' LIMIT 1")->fetchColumn();
if ($parameterId <= 0) {
    $pdo->prepare('INSERT INTO lab_parameters (TestID, ParameterName, ResultType, UnitID, ReferenceRange, NormalMinimum, NormalMaximum, DisplayOrder, IsRequired, IsActive) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)')->execute([
        $testId,
        'Blood Glucose',
        'Numeric',
        $unitId,
        '70-110 mg/dL',
        70.0,
        110.0,
        1,
    ]);
    $parameterId = (int) $pdo->lastInsertId();
}

$lowFlagId = (int) $pdo->query("SELECT FlagID FROM lab_flags WHERE FlagCode = 'L' AND IsActive = 1 LIMIT 1")->fetchColumn();
$normalFlagId = (int) $pdo->query("SELECT FlagID FROM lab_flags WHERE FlagCode = 'N' AND IsActive = 1 LIMIT 1")->fetchColumn();
$highFlagId = (int) $pdo->query("SELECT FlagID FROM lab_flags WHERE FlagCode = 'H' AND IsActive = 1 LIMIT 1")->fetchColumn();

$doctorId = (int) $pdo->query('SELECT DoctorID FROM doctors ORDER BY DoctorID LIMIT 1')->fetchColumn();
$patientId = (int) $pdo->query("SELECT PatientID FROM patients WHERE PatientName = 'E2E Controlled Patient' LIMIT 1")->fetchColumn();
if ($patientId <= 0) {
    $pdo->prepare('INSERT INTO patients (PatientName, Gender, DOB, PatientPhone, PatientAddress, IsActive) VALUES (?, ?, ?, ?, ?, 1)')->execute(['E2E Controlled Patient', 'Female', '1990-01-01', '610500125', 'Runtime verification address']);
    $patientId = (int) $pdo->lastInsertId();
}

$visitId = (int) $pdo->query("SELECT VisitID FROM visits WHERE PatientID = $patientId AND DoctorID = $doctorId ORDER BY VisitID DESC LIMIT 1")->fetchColumn();
if ($visitId <= 0) {
    $pdo->prepare('INSERT INTO visits (PatientID, DoctorID, VisitReference, VisitDate, ChiefComplaint, QueueStatus, PaymentStatus, ConsultationFee, AmountPaid, DueBalance) VALUES (?, ?, ?, NOW(), ?, ?, ?, 0.00, 0.00, 0.00)')->execute([
        $patientId,
        $doctorId,
        'VIS' . date('YmdHis'),
        'Runtime verification visit',
        'In Consultation',
        'Paid'
    ]);
    $visitId = (int) $pdo->lastInsertId();
}

$labRef = 'LAB' . date('YmdHis');
$pdo->prepare('INSERT INTO laboratory (LaboratoryID, PatientID, VisitID, DoctorID, RequestedByUserID, ServiceID, TestID, TestName, Description, TotalAmount, AmountPaid, DueBalance, PaymentStatus, WorkflowStatus, Result) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
    $labRef,
    $patientId,
    $visitId,
    $doctorId,
    1,
    null,
    $testId,
    'Runtime Glucose',
    'Modern lab verification',
    25.00,
    0.00,
    25.00,
    'Unpaid',
    'Awaiting Payment',
    'Pending'
]);

$bridgeId = (int) $pdo->query("SELECT BridgeID FROM lab_order_catalog_bridge WHERE LaboratoryID = '$labRef' AND ModernTestID = $testId LIMIT 1")->fetchColumn();
if ($bridgeId <= 0) {
    $pdo->prepare('INSERT INTO lab_order_catalog_bridge (LaboratoryID, ModernTestID, ModernTestNameSnapshot, PriceSnapshot, SourceType, DisplayOrder, IsActive) VALUES (?, ?, ?, ?, ?, 1, 1)')->execute([
        $labRef,
        $testId,
        'Runtime Glucose',
        25.00,
        'modern'
    ]);
    $bridgeId = (int) $pdo->lastInsertId();
}

$labResultIdA = tdc_lab_upsert_result_entry($pdo, $labRef, $patientId, $visitId, $doctorId, 0, $bridgeId);
$labResultIdB = tdc_lab_upsert_result_entry($pdo, $labRef, $patientId, $visitId, $doctorId, 0, $bridgeId);

$firstValue = '55';
$firstRemark = 'Below reference';
$pdo->prepare('INSERT INTO lab_result_parameters (LabResultID, ParameterID, ParameterNameSnapshot, ResultTypeSnapshot, UnitID, UnitNameSnapshot, ReferenceRangeSnapshot, NormalMinimumSnapshot, NormalMaximumSnapshot, RawResult, DisplayResult, FlagID, FlagCodeSnapshot, FlagNameSnapshot, Remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE RawResult=VALUES(RawResult), DisplayResult=VALUES(DisplayResult), FlagID=VALUES(FlagID), FlagCodeSnapshot=VALUES(FlagCodeSnapshot), FlagNameSnapshot=VALUES(FlagNameSnapshot), Remark=VALUES(Remark), UpdatedAt=NOW()')->execute([
    $labResultIdA,
    $parameterId,
    'Blood Glucose',
    'Numeric',
    $unitId,
    'mg/dL',
    '70-110 mg/dL',
    70.0,
    110.0,
    $firstValue,
    $firstValue,
    $lowFlagId,
    'L',
    'Low',
    $firstRemark,
]);

$pdo->prepare('UPDATE lab_results SET ResultStatus = ?, UpdatedAt = NOW() WHERE LabResultID = ?')->execute(['Draft', $labResultIdA]);
$pdo->prepare('UPDATE laboratory SET WorkflowStatus = ? WHERE LaboratoryID = ?')->execute(['Draft', $labRef]);

$labResultRows = (int) $pdo->query("SELECT COUNT(*) FROM lab_results WHERE BridgeID = $bridgeId")->fetchColumn();
$paramRows = (int) $pdo->query("SELECT COUNT(*) FROM lab_result_parameters WHERE LabResultID = $labResultIdA AND ParameterID = $parameterId")->fetchColumn();

$paramRow = $pdo->prepare('SELECT * FROM lab_result_parameters WHERE LabResultID = ? AND ParameterID = ? LIMIT 1');
$paramRow->execute([$labResultIdA, $parameterId]);
$paramData = $paramRow->fetch(PDO::FETCH_ASSOC);

$bridgeData = $pdo->prepare('SELECT * FROM lab_order_catalog_bridge WHERE BridgeID = ? LIMIT 1');
$bridgeData->execute([$bridgeId]);
$bridge = $bridgeData->fetch(PDO::FETCH_ASSOC);

$labData = $pdo->prepare('SELECT * FROM laboratory WHERE LaboratoryID = ? LIMIT 1');
$labData->execute([$labRef]);
$lab = $labData->fetch(PDO::FETCH_ASSOC);

$modernRow = $pdo->prepare('SELECT * FROM lab_order_catalog_bridge WHERE LaboratoryID = ? AND IsActive = 1 ORDER BY BridgeID DESC LIMIT 1');
$modernRow->execute([$labRef]);
$modern = $modernRow->fetch(PDO::FETCH_ASSOC);

$doctorReadback = $pdo->prepare('SELECT b.LaboratoryID, b.ModernTestNameSnapshot AS TestName, r.ResultStatus, p.ParameterNameSnapshot, p.DisplayResult, p.UnitNameSnapshot, p.ReferenceRangeSnapshot, p.FlagNameSnapshot, p.Remark FROM lab_order_catalog_bridge b JOIN laboratory l ON l.LaboratoryID = b.LaboratoryID JOIN lab_results r ON r.BridgeID = b.BridgeID LEFT JOIN lab_result_parameters p ON p.LabResultID = r.LabResultID WHERE l.PatientID = ? AND b.IsActive = 1 ORDER BY b.CreatedAt DESC, p.ParameterNameSnapshot');
$doctorReadback->execute([$patientId]);
$readback = $doctorReadback->fetchAll(PDO::FETCH_ASSOC);

// output concise evidence
$evidence = [
    'ModernTestID' => $testId,
    'ParameterID' => $parameterId,
    'DoctorID' => $doctorId,
    'PatientID' => $patientId,
    'VisitID' => $visitId,
    'LaboratoryID' => $labRef,
    'BridgeID' => $bridgeId,
    'LabResultID_A' => $labResultIdA,
    'LabResultID_B' => $labResultIdB,
    'lab_results_rows_for_bridge' => $labResultRows,
    'lab_result_parameters_rows_for_result_param' => $paramRows,
    'bridge_source' => $modern['SourceType'] ?? null,
    'lab_workflow_status' => $lab['WorkflowStatus'] ?? null,
    'lab_payment_status' => $lab['PaymentStatus'] ?? null,
    'lab_due_balance' => $lab['DueBalance'] ?? null,
    'saved_parameter' => $paramData,
    'doctor_readback' => $readback,
];

foreach ($evidence as $key => $value) {
    tdc_print($key, $value);
}
