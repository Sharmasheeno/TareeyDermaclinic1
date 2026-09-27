<?php
require __DIR__ . '/../db.php';
$prefix = 'E2E PAGINATION ';
if (($argv[1] ?? '') === 'cleanup') {
    $pdo->beginTransaction();
    $ids = $pdo->prepare('SELECT PatientID FROM patients WHERE PatientName LIKE ?');
    $ids->execute([$prefix . '%']);
    $patientIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    if ($patientIds) {
        $marks = implode(',', array_fill(0, count($patientIds), '?'));
        $pdo->prepare("DELETE FROM visits WHERE PatientID IN ($marks)")->execute($patientIds);
        $pdo->prepare("DELETE FROM patients WHERE PatientID IN ($marks)")->execute($patientIds);
    }
    $pdo->commit();
    echo 'cleaned=' . count($patientIds) . PHP_EOL;
    exit;
}
$pdo->beginTransaction();
$patient = $pdo->prepare("INSERT INTO patients (PatientName,PatientPhone,RegisteredAt) VALUES (?, ?, NOW())");
$visit = $pdo->prepare("INSERT INTO visits (VisitReference,PatientID,DoctorID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus,ChiefComplaint) VALUES (?, ?, 1, NOW(), 10, 10, 0, 'Paid', 'Completed', 'E2E pagination fixture')");
for ($i = 1; $i <= 15; $i++) {
    $patient->execute([$prefix . str_pad((string)$i, 2, '0', STR_PAD_LEFT), '9009' . str_pad((string)$i, 6, '0', STR_PAD_LEFT)]);
    $visit->execute(['E2E-PAG-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), (int)$pdo->lastInsertId()]);
}
$pdo->commit();
echo 'created=15' . PHP_EOL;
