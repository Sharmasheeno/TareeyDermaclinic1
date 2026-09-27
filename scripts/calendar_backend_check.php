<?php
// Local regression: reject invalid scheduling input before any write; valid write rolled back.
require __DIR__.'/../db.php';
require __DIR__.'/../auth/includes/workflow.php';
$doctor=$pdo->query("SELECT * FROM doctors WHERE WorkingDays='1,2,3,4,5' AND UserID IS NOT NULL ORDER BY DoctorID LIMIT 1")->fetch();
$patient=$pdo->query("SELECT PatientID FROM patients WHERE PatientName='E2E AGE CONTROL' LIMIT 1")->fetchColumn();
if(!$doctor||!$patient) throw new RuntimeException('Local fixtures missing');
$before=(int)$pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn();
foreach(['2026-10-03T10:30','2026-10-02T07:00','2026-10-02T20:00','2020-10-02T10:30',''] as $date){
    $pdo->beginTransaction();
    try {tdc_create_patient_appointment($pdo,(int)$patient,['AppointmentDoctorID'=>(string)$doctor['DoctorID'],'AppointmentDate'=>$date,'AppointmentAmountPaid'=>'0'],(int)$doctor['UserID']);throw new LogicException('Invalid selection accepted: '.$date);}
    catch(RuntimeException $e){echo $date.' REJECTED: '.$e->getMessage().PHP_EOL;}
    finally {if($pdo->inTransaction())$pdo->rollBack();}
}
if((int)$pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn()!==$before)throw new LogicException('Visit count changed');
echo 'No appointments created by negative tests'.PHP_EOL;
$saved=$pdo->query("SELECT p.PatientID,v.VisitID,v.VisitReference,v.DoctorID,v.VisitDate,v.ConsultationFee,v.AmountPaid,v.DueBalance FROM patients p JOIN visits v ON v.PatientID=p.PatientID WHERE p.PatientName='E2E CALENDAR SELECTION' ORDER BY v.VisitID DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode($saved).PHP_EOL;
