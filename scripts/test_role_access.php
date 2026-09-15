<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

// All mutations run against a disposable schema, never clinic records.
if (($argv[1] ?? '') === 'worker') {
    $testDb = $argv[2];
    if (!preg_match('/^tdc_role_test_[a-f0-9]{12}$/', $testDb)) exit(1);
    $case = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$case['role']]);
    $user = $stmt->fetch();
    ini_set('session.save_path', sys_get_temp_dir());
    session_id('tdctest' . bin2hex(random_bytes(12)));
    session_start();
    $_SESSION = ['user_id' => $user['id'], 'role' => $case['session_role'] ?? $case['role'], 'csrf_token' => 'test-token'];
    session_write_close();
    $_GET = $case['get'] ?? [];
    $_POST = $case['post'] ?? [];
    if ($_POST) $_POST['csrf_token'] = $case['csrf'] ?? 'test-token';
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME'] = '/auth/pages/' . $case['page'];
    foreach ($case['before_sql'] ?? [] as $sql) $pdo->exec($sql);
    if (!empty($case['deleted'])) $pdo->exec('DELETE FROM users WHERE id = ' . (int) $user['id']);
    ob_start();
    register_shutdown_function(static function () use ($case, $pdo): void {
        $html = ob_get_clean();
        $failures = [];
        $status = http_response_code() ?: 200;
        if ($status !== ($case['status'] ?? 200)) $failures[] = 'Unexpected status ' . $status;
        foreach ($case['contains'] ?? [] as $text) if (strpos($html, $text) === false) $failures[] = 'Missing ' . $text;
        foreach ($case['absent'] ?? [] as $text) if (strpos($html, $text) !== false) $failures[] = 'Leaked ' . $text;
        foreach ($case['sql'] ?? [] as [$query, $expected]) if ((string) $pdo->query($query)->fetchColumn() !== (string) $expected) $failures[] = 'Database assertion failed';
        if (($last = error_get_last()) && in_array($last['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR])) $failures[] = $last['message'];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode(['case' => $case['name'], 'failures' => $failures]) . PHP_EOL;
        if ($failures) exit(1);
    });
    require __DIR__ . '/../auth/pages/' . $case['page'];
    exit;
}

$testDb = 'tdc_role_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $testDb . '`');
$failed = 0;
try {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('CREATE TABLE `' . $testDb . '`.`' . $table . '` LIKE `' . DB_NAME . '`.`' . $table . '`');
    }
    $test = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb, DB_USER, DB_PASS, $options);
    $roles = ['superuser', 'receptionuser', 'doctoruser', 'pharmacyuser', 'labuser'];
    $stmt = $test->prepare('INSERT INTO users (userlegalname, role, username, password) VALUES (?, ?, ?, ?)');
    foreach ($roles as $role) $stmt->execute(['Test ' . $role, $role, $role, password_hash('Test-only-123!', PASSWORD_DEFAULT)]);
    $stmt->execute(['Other Doctor User', 'doctoruser', 'otherdoctor', password_hash('Test-only-123!', PASSWORD_DEFAULT)]);
    $doctorUserId = (int) $test->query("SELECT id FROM users WHERE username='doctoruser'")->fetchColumn();
    $otherDoctorUserId = (int) $test->query("SELECT id FROM users WHERE username='otherdoctor'")->fetchColumn();
    $test->exec("INSERT INTO Doctors (UserID,DoctorName,ConsultationFee,Specialty,JoinedDate) VALUES ($doctorUserId,'Test Doctor',25,'Dermatology',CURDATE())");
    $test->exec("INSERT INTO Doctors (UserID,DoctorName,ConsultationFee,Specialty,JoinedDate) VALUES ($otherDoctorUserId,'Other Doctor',30,'Dermatology',CURDATE()), (NULL,'Unlinked Doctor',40,'Dermatology',CURDATE())");
    $test->exec("INSERT INTO Patients (PatientName, PatientPhone, RegisteredAt) VALUES ('Test Patient', '000', NOW()), ('Other Patient', '111', NOW())");
    $test->exec("INSERT INTO Laboratory (LaboratoryID, PatientID, TestID, TestName, TotalAmount, AmountPaid, DueBalance, Result, PaymentStatus, WorkflowStatus) VALUES ('TESTPAID', 1, 1, 'Paid test', 20, 20, 0, 'Pending', 'Paid', 'Ready'), ('TESTUNPAID', 1, 2, 'Unpaid test', 20, 0, 20, 'Pending', 'Unpaid', 'Awaiting Payment')");
    $test->exec("INSERT INTO LabServices (ServiceName,Category,Price,IsActive) VALUES ('CBC','Haematology',10,1),('Blood Sugar','Chemistry',5,1)");
    $test->exec("INSERT INTO Inventory (ItemID,Category,ItemName,QuantityInStock,SalesUnit,SellingPrice,ReorderLevel) VALUES ('ITM000001','Medicine','Test Cream',10,'Tube',10,2), ('ITM000002','Medicine','Test Wash',10,'Bottle',5,2)");
    $pages = ['home.php', 'reception.php', 'doctors.php', 'patients.php', 'laboratory.php', 'pharmacy.php', 'accounting.php', 'reports.php', 'settings.php'];
    $allowed = ['superuser' => $pages, 'receptionuser' => ['home.php', 'reception.php', 'patients.php'], 'doctoruser' => ['home.php', 'doctors.php'], 'pharmacyuser' => ['home.php', 'pharmacy.php'], 'labuser' => ['home.php', 'laboratory.php']];
    $cases = [];
    foreach ($roles as $role) foreach ($pages as $page) {
        $access = in_array($page, $allowed[$role], true);
        $cases[] = ['name' => "$role $page", 'role' => $role, 'page' => $page, 'status' => $access ? 200 : 403, 'contains' => [$access ? 'profile-trigger' : 'Access denied']];
    }
    foreach ($roles as $role) {
        $cases[] = ['name' => "$role dashboard navigation", 'role' => $role, 'page' => 'home.php', 'contains' => ['dashboard-metrics'], 'absent' => $role === 'superuser' ? [] : ['href="settings.php', 'href="reports.php', 'href="accounting.php']];
    }
    $cases[] = ['name' => 'Doctor has dedicated workspace', 'role' => 'doctoruser', 'page' => 'doctors.php', 'contains' => ['Doctor Workspace', 'Today’s consultation queue'], 'absent' => ['Add Doctor']];
    $cases[] = ['name' => 'Pharmacist has complete operations', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'contains' => ['href="pharmacy.php?section=prescriptions"', 'href="pharmacy.php?section=pos"', 'href="pharmacy.php?section=purchases"', 'href="pharmacy.php?section=inventory"']];
    $cases[] = ['name' => 'Only the five supported roles are exposed', 'role' => 'superuser', 'page' => 'settings.php', 'get' => ['section'=>'users'], 'contains' => ['SuperAdmin','Receptionist','Doctor','Pharmacist','Laboratory Staff'], 'absent' => ['>Accountant<'], 'sql'=>[["SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'","enum('superuser','receptionuser','doctoruser','pharmacyuser','labuser')"]]];
    $cases[] = ['name' => 'SuperAdmin configures authoritative lab service', 'role' => 'superuser', 'page' => 'laboratory.php', 'post' => ['form_action'=>'save_service','ServiceName'=>'Skin Biopsy','Category'=>'Dermatology','ServicePrice'=>'30.00','IsActive'=>'1'], 'status'=>302, 'sql'=>[["SELECT Price FROM LabServices WHERE ServiceName='Skin Biopsy'",'30.00']]];
    $cases[] = ['name' => 'SuperAdmin creates a supported user safely', 'role' => 'superuser', 'page' => 'settings.php', 'get' => ['section'=>'users'], 'post' => ['form_action'=>'save','userlegalname'=>'QA Laboratory User','role'=>'labuser','username'=>'qa_lab_user','password'=>'Test-only-123!','confirm_password'=>'Test-only-123!'], 'status'=>302, 'sql'=>[["SELECT role FROM users WHERE username='qa_lab_user'",'labuser']]];
    $cases[] = ['name' => 'Patient age is derived from date of birth', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'save','PatientName'=>'DOB Test Patient','PatientPhone'=>'615123456','Gender'=>'Male','Age'=>'99','DateOfBirth'=>'2000-01-01','PatientType'=>'New Patient','AllocatedDoctor'=>'','Remark'=>''], 'status'=>302, 'sql'=>[["SELECT Age FROM Patients WHERE PatientName='DOB Test Patient'",(string)((int)date('Y')-2000)]]];
    $cases[] = ['name' => 'Duplicate patient phone produces existing-record warning', 'role' => 'receptionuser', 'page' => 'reception.php', 'get'=>['section'=>'patients'], 'post' => ['form_action'=>'save_patient','PatientName'=>'Duplicate Attempt','PatientPhone'=>'615123456','Gender'=>'Male','Age'=>'26','DateOfBirth'=>'2000-01-01','PatientType'=>'Returning Patient','AllocatedDoctor'=>'','Remark'=>''], 'contains'=>['Possible existing patient found','create a new visit instead'], 'sql'=>[["SELECT COUNT(*) FROM Patients WHERE PatientPhone='615123456'",1]]];
    $cases[] = ['name' => 'Future patient date of birth is rejected', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'save','PatientName'=>'Future DOB Patient','PatientPhone'=>'615123457','Gender'=>'Female','Age'=>'1','DateOfBirth'=>date('Y-m-d',strtotime('+1 day')),'PatientType'=>'New Patient','AllocatedDoctor'=>'','Remark'=>''], 'contains'=>['cannot be in the future'], 'sql'=>[["SELECT COUNT(*) FROM Patients WHERE PatientName='Future DOB Patient'",0]]];
    $cases[] = ['name' => 'Reception cannot book an unlinked doctor', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'3','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'40','PaymentMethod'=>'Cash'], 'contains'=>['must be linked to a Doctor user account'], 'sql'=>[["SELECT COUNT(*) FROM Visits",0]]];
    $cases[] = ['name' => 'Reception books paid consultation into doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'1','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'25','PaymentMethod'=>'Cash','ChiefComplaint'=>'Skin rash'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM Visits WHERE VisitID=1",'Waiting'],["SELECT PaymentStatus FROM Visits WHERE VisitID=1",'Paid'],["SELECT COUNT(*) FROM Payments WHERE VisitID=1 AND PaymentType='Consultation'",1],["SELECT COUNT(*) FROM Accounting WHERE AccountID='REV-CONSULT'",1]]];
    $cases[] = ['name' => 'Opening a notification marks only that recipient read', 'role' => 'doctoruser', 'page' => 'doctors.php', 'get' => ['notification'=>'1'], 'sql'=>[["SELECT IsRead FROM Notifications WHERE NotificationID=1",1]]];
    $cases[] = ['name' => 'Reception books partial consultation outside doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'1','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'10','PaymentMethod'=>'Cash'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM Visits WHERE VisitID=2",'Pending Payment'],["SELECT DueBalance FROM Visits WHERE VisitID=2",'15.00'],["SELECT DueBalance FROM Patients WHERE PatientID=1",'15.00']]];
    $cases[] = ['name' => 'Doctor cannot work an unpaid consultation', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'save_notes','VisitID'=>'2','Diagnosis'=>'Must not save'], 'contains'=>['must record full consultation payment'], 'sql'=>[["SELECT Diagnosis FROM Visits WHERE VisitID=2",'']]];
    $cases[] = ['name' => 'Reception settles consultation into doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['form_action'=>'collect','VisitID'=>'2','PaymentAmount'=>'15','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM Visits WHERE VisitID=2",'Waiting'],["SELECT PaymentStatus FROM Visits WHERE VisitID=2",'Paid'],["SELECT DueBalance FROM Patients WHERE PatientID=1",'0.00'],["SELECT SUM(Amount) FROM Payments WHERE VisitID=2",'25.00']]];
    $cases[] = ['name' => 'Doctor starts own consultation', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'start','VisitID'=>'1'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM Visits WHERE VisitID=1",'In Consultation']]];
    $cases[] = ['name' => 'Doctor saves connected clinical record', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'save_notes','VisitID'=>'1','ClinicalNotes'=>'Observed erythematous plaque','Diagnosis'=>'Contact dermatitis','TreatmentPlan'=>'Topical treatment','FollowUpPlan'=>'Review in two weeks','FollowUpDate'=>date('Y-m-d',strtotime('+14 days'))], 'status'=>302, 'sql'=>[["SELECT Diagnosis FROM Visits WHERE VisitID=1",'Contact dermatitis']]];
    $cases[] = ['name' => 'Doctor prescription form uses live inventory choices', 'role' => 'doctoruser', 'page' => 'doctors.php', 'get' => ['visit'=>'1'], 'contains' => ['name="MedicationName[]"','Test Cream (10 available)','id="addPrescriptionLine"']];
    $cases[] = ['name' => 'Doctor cannot prescribe unknown inventory item', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>'Unknown Medicine','Quantity'=>'1'], 'contains'=>['not available in inventory'], 'sql'=>[["SELECT COUNT(*) FROM Prescriptions",0]]];
    $cases[] = ['name' => 'Doctor prescription enters pharmacy queue', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>'Test Cream','Quantity'=>'2','Dosage'=>'Apply thin layer','Frequency'=>'Twice daily','Duration'=>'7 days','Instructions'=>'External use'], 'status'=>302, 'sql'=>[["SELECT Status FROM Prescriptions WHERE VisitID=1",'Pending']]];
    $cases[] = ['name' => 'Doctor multi-test lab order uses catalogue totals', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'request_lab','VisitID'=>'1','ServiceID'=>['1','2'],'Price'=>'0.01','Description'=>'CBC and glucose requested'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM Laboratory WHERE VisitID=1",'Awaiting Payment'],["SELECT PaymentStatus FROM Laboratory WHERE VisitID=1",'Unpaid'],["SELECT TotalAmount FROM Laboratory WHERE VisitID=1",'15.00'],["SELECT COUNT(*) FROM LabOrderItems WHERE LaboratoryID='LAB000001'",2],["SELECT SUM(UnitPrice) FROM LabOrderItems WHERE LaboratoryID='LAB000001'",'15.00']]];
    $cases[] = ['name' => 'Pharmacist dispenses prescription and reduces stock', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'prescriptions'], 'post' => ['form_action'=>'dispense','PrescriptionReference'=>'RX000001','AmountPaid'=>'20','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql'=>[["SELECT Status FROM Prescriptions WHERE VisitID=1",'Dispensed'],["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'",8],["SELECT COUNT(*) FROM Accounting WHERE AccountID='REV-PHARM'",1]]];
    $cases[] = ['name' => 'Multi-item prescription groups lines sequentially', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>['Test Cream','Test Wash'],'Quantity'=>['100','1'],'Dosage'=>['Apply','Wash'],'Frequency'=>['Daily','Daily'],'Duration'=>['7 days','7 days'],'Instructions'=>['External','External']], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM Prescriptions WHERE PrescriptionID LIKE 'RX000002-%'",2],["SELECT MedicationName FROM Prescriptions WHERE PrescriptionID='RX000002-02'",'Test Wash']]];
    $cases[] = ['name' => 'Prescription shortage rolls back dispensing', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'prescriptions'], 'post' => ['form_action'=>'dispense','PrescriptionReference'=>'RX000002','AmountPaid'=>'1000','PaymentMethod'=>'Cash'], 'contains'=>['Insufficient stock'], 'sql'=>[["SELECT Status FROM Prescriptions WHERE PrescriptionID='RX000002-01'",'Pending'],["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'",8]]];
    $cases[] = ['name' => 'Standalone POS ignores tampered browser prices', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'pos'], 'post' => ['CustomerName'=>'Walk-in','CustomerPhone'=>'','AmountPaid'=>'15','ItemID'=>['ITM000001','ITM000002'],'Quantity'=>['1','1'],'UnitPrice'=>['0.01','0.01']], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM PharmacySales WHERE SaleID LIKE 'POS000002-%'",2],["SELECT TotalAmount FROM PharmacySales WHERE SaleID='POS000002-01'",'15.00'],["SELECT UnitPrice FROM PharmacySales WHERE SaleID='POS000002-01'",'10.00'],["SELECT COUNT(*) FROM Accounting WHERE AccountID='REV-PHARM' AND ReferenceID='POS000002'",1],["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'",7]]];
    $cases[] = ['name' => 'Voiding POS reverses stock and revenue', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'pos'], 'post' => ['form_action'=>'void','SaleRef'=>'POS000002'], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM PharmacySales WHERE SaleID LIKE 'POS000002-%'",0],["SELECT COUNT(*) FROM Accounting WHERE ReferenceID='POS000002'",0],["SELECT QuantityInStock FROM Inventory WHERE ItemID='ITM000001'",8]]];
    $cases[] = ['name' => 'Reception clears doctor lab request payment', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'laboratory'], 'post' => ['form_action'=>'collect','LaboratoryID'=>'LAB000001','PaymentAmount'=>'15','PaymentMethod'=>'Cash'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM Laboratory WHERE LaboratoryID='LAB000001'",'Ready'],["SELECT COUNT(*) FROM Accounting WHERE AccountID='REV-LAB'",1]]];
    $cases[] = ['name' => 'Laboratory starts only a paid ready order', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action'=>'start','LaboratoryID'=>'LAB000001'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM Laboratory WHERE LaboratoryID='LAB000001'",'In Progress']]];
    $cases[] = ['name' => 'Laboratory completes doctor-requested tests', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID'=>'LAB000001','LabOrderItemID'=>['1','2'],'ItemResult'=>['Negative','Negative'],'ItemClinicalResult'=>['Normal count','Normal glucose'],'IsAvailable'=>'1'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM Laboratory WHERE LaboratoryID='LAB000001'",'Completed'],["SELECT COUNT(*) FROM LabOrderItems WHERE LaboratoryID='LAB000001' AND Result='Negative'",2],["SELECT ClinicalResult FROM LabOrderItems WHERE LabOrderItemID=1",'Normal count']]];
    $cases[] = ['name' => 'Doctor reviews returned lab result', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'review_result','VisitID'=>'1','LaboratoryID'=>'LAB000001'], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM Laboratory WHERE LaboratoryID='LAB000001' AND ReviewedAt IS NOT NULL",1]]];
    $cases[] = ['name' => 'Doctor queue excludes another doctor patients', 'role' => 'doctoruser', 'page' => 'doctors.php', 'before_sql' => ["INSERT INTO Visits (VisitReference,PatientID,DoctorID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus) VALUES ('VISOTHER',2,2,NOW(),30,30,0,'Paid','Waiting')"], 'absent'=>['Other Patient']];
    $cases[] = ['name' => 'Patient with clinical history cannot be deleted', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'delete','PatientID'=>'1'], 'contains'=>['clinical or financial history'], 'sql'=>[["SELECT COUNT(*) FROM Patients WHERE PatientID=1",1]]];
    $cases[] = ['name' => 'Doctor with workflow history cannot be deleted', 'role' => 'superuser', 'page' => 'doctors.php', 'post' => ['form_action'=>'delete','DoctorID'=>'1'], 'contains'=>['consultation, prescription, or laboratory history'], 'sql'=>[["SELECT COUNT(*) FROM Doctors WHERE DoctorID=1",1]]];
    $cases[] = ['name' => 'SuperAdmin cannot create patient lab orders manually', 'role' => 'superuser', 'page' => 'laboratory.php', 'post' => ['PatientID'=>'1','TestName'=>'Administrative lab','Price'=>'12','AmountPaid'=>'5'], 'status'=>403, 'sql'=>[["SELECT COUNT(*) FROM Laboratory WHERE TestName='Administrative lab'",0]]];
    $cases[] = ['name' => 'Lab sees unpaid work but actions remain locked', 'role' => 'labuser', 'page' => 'laboratory.php', 'contains' => ['TESTPAID', 'TESTUNPAID', 'Start Test', 'Payment Locked'], 'absent' => ['id="addLabBtn"', 'value="delete"', 'href="patients.php']];
    $cases[] = ['name' => 'Stale SuperAdmin session loses access', 'role' => 'pharmacyuser', 'session_role' => 'superuser', 'page' => 'settings.php', 'status' => 403];
    $cases[] = ['name' => 'Laboratory starts paid fixture before result entry', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action'=>'start','LaboratoryID'=>'TESTPAID'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM Laboratory WHERE LaboratoryID='TESTPAID'",'In Progress']]];
    $cases[] = ['name' => 'Bad CSRF cannot change lab results', 'role' => 'labuser', 'page' => 'laboratory.php', 'csrf' => 'invalid', 'post' => ['LaboratoryID' => 'TESTPAID', 'Result' => 'Positive'], 'contains' => ['session has expired'], 'sql' => [["SELECT Result FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", 'Pending']]];
    $cases[] = ['name' => 'Lab cannot bypass payment', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID' => 'TESTUNPAID', 'Result' => 'Positive', 'PaymentStatus' => 'Paid'], 'contains' => ['Reception must record full payment'], 'sql' => [["SELECT Result FROM Laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Pending'], ["SELECT PaymentStatus FROM Laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Unpaid']]];
    $cases[] = ['name' => 'Lab cannot delete orders', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action' => 'delete', 'LaboratoryID' => 'TESTPAID'], 'status' => 403];
    $cases[] = ['name' => 'Lab saves results without changing bill', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID' => 'TESTPAID', 'Result' => 'Negative', 'Price' => '1', 'PaymentStatus' => 'Unpaid', 'PatientID' => '999'], 'sql' => [["SELECT Result FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", 'Negative'], ["SELECT TotalAmount FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", '20.00'], ["SELECT PaymentStatus FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", 'Paid'], ["SELECT PatientID FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", 1]]];
    $cases[] = ['name' => 'Reception bills cannot overwrite results', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section' => 'laboratory'], 'post' => ['LaboratoryID' => 'TESTPAID', 'PatientID' => '1', 'TestName' => 'Paid test', 'Price' => '20', 'AmountPaid' => '20', 'Result' => 'Positive'], 'status'=>403, 'sql' => [["SELECT Result FROM Laboratory WHERE LaboratoryID = 'TESTPAID'", 'Negative']]];
    $cases[] = ['name' => 'Reception pays order for lab handoff', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section' => 'laboratory'], 'post' => ['form_action'=>'collect','LaboratoryID' => 'TESTUNPAID','PaymentAmount' => '20','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql' => [["SELECT PaymentStatus FROM Laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Paid'],["SELECT WorkflowStatus FROM Laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Ready']]];
    $cases[] = ['name' => 'Lab now receives reception paid order', 'role' => 'labuser', 'page' => 'laboratory.php', 'contains' => ['TESTUNPAID']];
    $cases[] = ['name' => 'Deleted account loses access', 'role' => 'labuser', 'deleted' => true, 'page' => 'home.php', 'absent' => ['dashboard-metrics']];
    foreach (['reception.php' => ['patients', 'consultations', 'laboratory', 'pharmacy'], 'pharmacy.php' => ['prescriptions', 'pos', 'purchases', 'inventory'], 'accounting.php' => ['ledger', 'accounts'], 'reports.php' => ['income-statement', 'balance-sheet'], 'settings.php' => ['users'], 'patients.php' => [null], 'doctors.php' => [null], 'laboratory.php' => [null]] as $page => $sections) {
        foreach ($sections as $section) {
            $cases[] = ['name' => "SuperAdmin section and search $page $section", 'role' => 'superuser', 'page' => $page, 'get' => array_filter(['section' => $section, 'q' => 'Test']), 'contains' => ['profile-trigger'], 'absent' => ['Fatal error', 'Warning:']];
        }
    }
    foreach ($cases as $case) {
        if (in_array($case['name'], ['Lab saves results without changing bill', 'Deleted account loses access'], true)) $case['status'] = 302;
        $command = [PHP_BINARY, __FILE__, 'worker', $testDb, base64_encode(json_encode($case))];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode(trim($output), true);
        if ($code !== 0 || !$result || $result['failures'] || $error !== '') {
            $failed++;
            echo 'FAIL ' . $case['name'] . ': ' . $output . $error . PHP_EOL;
        } else {
            echo 'PASS ' . $case['name'] . PHP_EOL;
        }
    }
    echo count($cases) . ' checks; ' . $failed . ' failed.' . PHP_EOL;
} finally {
    $pdo->exec('DROP DATABASE `' . $testDb . '`');
}
exit($failed ? 1 : 0);
