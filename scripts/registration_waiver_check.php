<?php
// Local-only regression. All fixture writes are rolled back.
require __DIR__.'/../db.php';
require __DIR__.'/../auth/includes/workflow.php';
$doctor = $pdo->query("SELECT * FROM doctors WHERE DoctorName='E2E Local Doctor' AND UserID IS NOT NULL")->fetch();
$patient = $pdo->query("SELECT PatientID FROM patients WHERE PatientName='E2E AGE CONTROL' LIMIT 1")->fetchColumn();
if (!$doctor || !$patient) throw new RuntimeException('Local test fixtures are required.');
$counts = fn() => $pdo->query('SELECT (SELECT COUNT(*) FROM visits) AS visits, (SELECT COUNT(*) FROM payments) AS payments')->fetch(PDO::FETCH_ASSOC);
$before = $counts();
$date = new DateTime('+60 days', new DateTimeZone('Africa/Mogadishu'));
$date->setTime(10, 0);
while (!tdc_doctor_is_available($doctor, $date) || tdc_doctor_has_booking_conflict($pdo, (int)$doctor['DoctorID'], $date)) $date->modify('+1 day');
foreach ([true, false] as $free) {
    $pdo->beginTransaction();
    try {
        $id = tdc_create_patient_appointment($pdo, (int)$patient, [
            'AppointmentDoctorID'=>(string)$doctor['DoctorID'], 'AppointmentDate'=>$date->format('Y-m-d\TH:i'),
            'AppointmentFreeConsultation'=>$free ? '1' : '', 'AppointmentAmountPaid'=>$free ? '999' : '0',
            'AppointmentDiscountType'=>$free ? 'Percentage' : 'None', 'AppointmentDiscountValue'=>$free ? '99' : '0',
            'AppointmentTaxRate'=>$free ? '99' : '0', 'consultation_fee'=>'999', 'due'=>'999'
        ], (int)$doctor['UserID']);
        $visit = $pdo->query('SELECT * FROM visits WHERE VisitID='.(int)$id)->fetch();
        $expected = $free ? 0.0 : (float)$doctor['ConsultationFee'];
        if ((float)$visit['ConsultationFee'] !== $expected || (float)$visit['DueBalance'] !== $expected || (float)$visit['AmountPaid'] !== 0.0 || (bool)$visit['IsFreeConsultation'] !== $free) throw new RuntimeException('Incorrect saved financial state');
        if ($counts()['payments'] !== $before['payments']) throw new RuntimeException('Unexpected payment');
        $fee = $pdo->query('SELECT ConsultationFee FROM doctors WHERE DoctorID='.(int)$doctor['DoctorID'])->fetchColumn();
        if ((float)$fee !== (float)$doctor['ConsultationFee']) throw new RuntimeException('Doctor fee changed');
        echo ($free ? 'Waiver overrides tampered amounts, no payment, zero receivable' : 'Normal consultation retains configured fee after waiver').": PASS\n";
    } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
}
if ($counts() !== $before) throw new RuntimeException('Fixture records were retained');
echo "All fixture writes rolled back: PASS\n";
