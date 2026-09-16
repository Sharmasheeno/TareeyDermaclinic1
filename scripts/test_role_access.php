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
        if (isset($case['snapshot'])) {
            $assetRoot = 'file:///' . str_replace('\\', '/', realpath(__DIR__ . '/../auth/assets')) . '/';
            $uploadRoot = 'file:///' . str_replace('\\', '/', realpath(__DIR__ . '/../auth/uploads')) . '/';
            file_put_contents(sys_get_temp_dir() . '/tdc-' . $case['snapshot'] . '.html', str_replace(['../assets/', '../uploads/'], [$assetRoot, $uploadRoot], $html));
        }
        $failures = [];
        $status = http_response_code() ?: 200;
        if (isset($case['session_role_expected']) && ($_SESSION['role'] ?? '') !== $case['session_role_expected']) $failures[] = 'Session role changed';
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
    foreach (['Roles','Permissions','RolePermissions','PaymentMethods'] as $seedTable) {
        $test->exec('INSERT INTO `' . $seedTable . '` SELECT * FROM `' . DB_NAME . '`.`' . $seedTable . '`');
    }
    require_once __DIR__ . '/../auth/includes/operational-role-defaults.php';
    tdc_apply_operational_role_defaults($test);
    // Explicit isolated baseline: live Setup grants may be customized by clinic administrators.
    $test->exec("DELETE rp FROM rolepermissions rp JOIN roles r ON r.RoleID=rp.RoleID JOIN permissions p ON p.PermissionID=rp.PermissionID WHERE r.RoleKey='receptionuser' AND p.PermissionKey IN ('doctors.view','doctors.export')");
    $roles = ['superuser', 'receptionuser', 'doctoruser', 'pharmacyuser', 'labuser'];
    $stmt = $test->prepare('INSERT INTO users (userlegalname,role,role_id,username,password,is_active,is_root) SELECT ?,r.RoleKey,r.RoleID,?,?,1,? FROM roles r WHERE r.RoleKey=?');
    foreach ($roles as $role) $stmt->execute(['Test ' . $role, $role, password_hash('Test-only-123!', PASSWORD_DEFAULT), $role === 'superuser' ? 1 : 0, $role]);
    $stmt->execute(['Other Doctor User', 'otherdoctor', password_hash('Test-only-123!', PASSWORD_DEFAULT), 0, 'doctoruser']);
    $stmt->execute(['Non-root SuperAdmin', 'superadmin_nonroot', password_hash('Test-only-123!', PASSWORD_DEFAULT), 0, 'superuser']);
    $test->exec("INSERT INTO roles (RoleKey,RoleName,Description,IsSystem,IsProtected,IsActive) VALUES ('accountant_test','Accountant','Accounting and reporting only',0,0,1)");
    $accountantRoleId=(int)$test->lastInsertId();
    $test->exec("INSERT INTO rolepermissions (RoleID,PermissionID) SELECT $accountantRoleId,PermissionID FROM permissions WHERE PermissionKey IN ('dashboard.view','accounting.view','accounting.transactions.view','reports.view')");
    $stmt->execute(['Test Accountant', 'accountantuser', password_hash('Test-only-123!', PASSWORD_DEFAULT), 0, 'accountant_test']);
    $doctorUserId = (int) $test->query("SELECT id FROM users WHERE username='doctoruser'")->fetchColumn();
    $otherDoctorUserId = (int) $test->query("SELECT id FROM users WHERE username='otherdoctor'")->fetchColumn();
    $test->exec("INSERT INTO doctors (UserID,DoctorName,ConsultationFee,Specialty,JoinedDate) VALUES ($doctorUserId,'Test Doctor',25,'Dermatology',CURDATE())");
    $test->exec("INSERT INTO doctors (UserID,DoctorName,ConsultationFee,Specialty,JoinedDate) VALUES ($otherDoctorUserId,'Other Doctor',30,'Dermatology',CURDATE()), (NULL,'Unlinked Doctor',40,'Dermatology',CURDATE())");
    $test->exec("INSERT INTO patients (PatientName, PatientPhone, RegisteredAt) VALUES ('Test Patient', '000', NOW()), ('Other Patient', '111', NOW())");
    $test->exec("INSERT INTO laboratory (LaboratoryID, PatientID, TestID, TestName, TotalAmount, AmountPaid, DueBalance, Result, PaymentStatus, WorkflowStatus) VALUES ('TESTPAID', 1, 1, 'Paid test', 20, 20, 0, 'Pending', 'Paid', 'Ready'), ('TESTUNPAID', 1, 2, 'Unpaid test', 20, 0, 20, 'Pending', 'Unpaid', 'Awaiting Payment')");
    $test->exec("INSERT INTO labservices (ServiceName,Category,Price,IsActive) VALUES ('CBC','Haematology',10,1),('Blood Sugar','Chemistry',5,1)");
    $test->exec("INSERT INTO inventory (ItemID,Category,ItemName,QuantityInStock,SalesUnit,SellingPrice,ReorderLevel) VALUES ('ITM000001','Medicine','Test Cream',10,'Tube',10,2), ('ITM000002','Medicine','Test Wash',10,'Bottle',5,2)");
    $pages = ['home.php', 'reception.php', 'doctors.php', 'patients.php', 'laboratory.php', 'pharmacy.php', 'accounting.php', 'reports.php', 'setup.php'];
    $allowed = ['superuser' => $pages, 'receptionuser' => ['home.php', 'reception.php', 'patients.php', 'pharmacy.php', 'laboratory.php', 'accounting.php', 'reports.php'], 'doctoruser' => ['home.php', 'doctors.php'], 'pharmacyuser' => ['home.php', 'pharmacy.php'], 'labuser' => ['home.php', 'laboratory.php']];
    $cases = [];
    foreach ($roles as $role) foreach ($pages as $page) {
        $access = in_array($page, $allowed[$role], true);
        $cases[] = ['name' => "$role $page", 'role' => $role, 'page' => $page, 'status' => $access ? 200 : 403, 'contains' => [$access ? 'profile-trigger' : 'Access denied']];
    }
    foreach ($roles as $role) {
        $cases[] = ['name' => "$role dashboard navigation", 'role' => $role, 'page' => 'home.php', 'contains' => ['dashboard-metrics'], 'absent' => $role === 'superuser' ? [] : ($role === 'receptionuser' ? ['href="settings.php','href="setup.php','href="doctors.php'] : ['href="settings.php', 'href="reports.php', 'href="accounting.php'])];
    }
    $cases[] = ['name' => 'Doctor has dedicated workspace', 'role' => 'doctoruser', 'page' => 'doctors.php', 'contains' => ['Doctor Workspace', 'Patient Waiting'], 'absent' => ['Add Doctor']];
    $cases[] = ['name' => 'Pharmacist sees only permitted operations', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'contains' => ['href="pharmacy.php?section=prescriptions"', 'href="pharmacy.php?section=pos"','href="pharmacy.php?section=purchases"','href="pharmacy.php?section=inventory"'], 'absent'=>['href="setup.php"','href="accounting.php"']];
    $cases[] = ['name' => 'Setup exposes system and custom role architecture', 'role' => 'superuser', 'page' => 'setup.php', 'get' => ['section'=>'roles'], 'contains' => ['SuperAdmin','Reception','Doctor','Pharmacy','Laboratory','Add Role'], 'sql'=>[["SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'",'varchar']]];
    $cases[] = ['name' => 'SuperAdmin configures authoritative lab service in Setup', 'role' => 'superuser', 'page' => 'setup.php', 'get'=>['section'=>'laboratory'], 'post' => ['setup_action'=>'save_lab_service','service_name'=>'Skin Biopsy','category'=>'Dermatology','description'=>'QA service','price'=>'30.00','is_available'=>'1','is_active'=>'1'], 'status'=>302, 'sql'=>[["SELECT Price FROM labservices WHERE ServiceName='Skin Biopsy'",'30.00']]];
    $labRoleId=(int)$test->query("SELECT RoleID FROM roles WHERE RoleKey='labuser'")->fetchColumn();
    $cases[] = ['name' => 'SuperAdmin creates user with database role assignment', 'role' => 'superuser', 'page' => 'setup.php', 'get' => ['section'=>'users'], 'post' => ['setup_action'=>'save_user','userlegalname'=>'QA Laboratory User','role_id'=>(string)$labRoleId,'username'=>'qa_lab_user','password'=>'Test-only-123!'], 'status'=>302, 'sql'=>[["SELECT role FROM users WHERE username='qa_lab_user'",'labuser']]];
    $accountingPermissionIds=$test->query("SELECT PermissionID FROM permissions WHERE PermissionKey IN ('dashboard.view','accounting.view','accounting.transactions.view','reports.view') ORDER BY PermissionID")->fetchAll(PDO::FETCH_COLUMN);
    $reportsExportId=(int)$test->query("SELECT PermissionID FROM permissions WHERE PermissionKey='reports.export'")->fetchColumn();
    $cases[]=['name'=>'Custom Accountant navigation follows permissions','role'=>'accountantuser','page'=>'home.php','contains'=>['href="accounting.php"','href="reports.php"'],'absent'=>['href="patients.php"','href="setup.php"','href="pharmacy.php"']];
    $cases[]=['name'=>'Custom Accountant can open accounting','role'=>'accountantuser','page'=>'accounting.php','status'=>200,'contains'=>['Accounting']];
    $cases[]=['name'=>'Custom Accountant cannot open patients','role'=>'accountantuser','page'=>'patients.php','status'=>403,'contains'=>['Access denied']];
    $cases[]=['name'=>'Export hidden before permission grant','role'=>'accountantuser','page'=>'reports.php','get'=>['section'=>'income-statement'],'absent'=>['Export CSV']];
    $cases[]=['name'=>'Permission dependency rejects broken configuration','role'=>'superuser','page'=>'setup.php','get'=>['section'=>'permissions','role_id'=>$accountantRoleId],'post'=>['setup_action'=>'save_permissions','role_id'=>$accountantRoleId,'permissions'=>[$test->query("SELECT PermissionID FROM permissions WHERE PermissionKey='lab_billing.payment'")->fetchColumn()]],'contains'=>['requires lab_billing.view']];
    $cases[]=['name'=>'SuperAdmin grants report export without source changes','role'=>'superuser','page'=>'setup.php','get'=>['section'=>'permissions','role_id'=>$accountantRoleId],'post'=>['setup_action'=>'save_permissions','role_id'=>$accountantRoleId,'permissions'=>array_merge($accountingPermissionIds,[$reportsExportId])],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM rolepermissions WHERE RoleID=$accountantRoleId AND PermissionID=$reportsExportId",1]]];
    $cases[]=['name'=>'Custom role gains Export action immediately','role'=>'accountantuser','page'=>'reports.php','get'=>['section'=>'income-statement'],'contains'=>['Export CSV']];
    $cases[]=['name'=>'SuperAdmin revokes report export','role'=>'superuser','page'=>'setup.php','get'=>['section'=>'permissions','role_id'=>$accountantRoleId],'post'=>['setup_action'=>'save_permissions','role_id'=>$accountantRoleId,'permissions'=>$accountingPermissionIds],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM rolepermissions WHERE RoleID=$accountantRoleId AND PermissionID=$reportsExportId",0]]];
    $cases[]=['name'=>'Revoked report export is hidden immediately','role'=>'accountantuser','page'=>'reports.php','get'=>['section'=>'income-statement'],'absent'=>['Export CSV']];
    $cases[]=['name'=>'Revoked report export backend is forbidden','role'=>'accountantuser','page'=>'reports.php','get'=>['section'=>'income-statement','export'=>'csv'],'status'=>403,'contains'=>['Access denied']];
    $rootUserId=(int)$test->query("SELECT id FROM users WHERE username='superuser'")->fetchColumn();
    $cases[]=['name'=>'Root SuperAdmin cannot be deactivated','role'=>'superuser','page'=>'setup.php','get'=>['section'=>'users'],'post'=>['setup_action'=>'user_status','user_id'=>$rootUserId,'is_active'=>0],'contains'=>['root SuperAdmin cannot be deactivated'],'sql'=>[["SELECT is_active FROM users WHERE id=$rootUserId",1]]];
    $cases[]=['name'=>'Active payment method is configured in Setup','role'=>'superuser','page'=>'setup.php','get'=>['section'=>'payment-methods'],'post'=>['setup_action'=>'save_payment_method','method_name'=>'EVC Plus','description'=>'Mobile wallet','is_active'=>'1'],'status'=>302,'sql'=>[["SELECT IsActive FROM paymentmethods WHERE MethodName='EVC Plus'",1]]];
    $cases[]=['name'=>'Reception loads configured payment methods','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'consultations'],'contains'=>['EVC Plus']];
    $cases[]=['name'=>'Legacy Settings URL redirects to Setup','role'=>'superuser','page'=>'settings.php','get'=>['section'=>'users'],'status'=>302];
    $cases[] = ['name' => 'Patient age is derived from date of birth', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'save','PatientName'=>'DOB Test Patient','PatientPhone'=>'615123456','Gender'=>'Male','Age'=>'99','DateOfBirth'=>'2000-01-01','PatientType'=>'New Patient','AllocatedDoctor'=>'','Remark'=>''], 'status'=>302, 'sql'=>[["SELECT Age FROM patients WHERE PatientName='DOB Test Patient'",(string)((int)date('Y')-2000)]]];
    $cases[] = ['name' => 'Duplicate patient phone produces existing-record warning', 'role' => 'receptionuser', 'page' => 'reception.php', 'get'=>['section'=>'patients'], 'post' => ['form_action'=>'save_patient','PatientName'=>'Duplicate Attempt','PatientPhone'=>'615123456','Gender'=>'Male','Age'=>'26','DateOfBirth'=>'2000-01-01','PatientType'=>'Returning Patient','AllocatedDoctor'=>'','Remark'=>''], 'contains'=>['Possible existing patient found','create a new visit instead'], 'sql'=>[["SELECT COUNT(*) FROM patients WHERE PatientPhone='615123456'",1]]];
    $cases[] = ['name' => 'Future patient date of birth is rejected', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'save','PatientName'=>'Future DOB Patient','PatientPhone'=>'615123457','Gender'=>'Female','Age'=>'1','DateOfBirth'=>date('Y-m-d',strtotime('+1 day')),'PatientType'=>'New Patient','AllocatedDoctor'=>'','Remark'=>''], 'contains'=>['cannot be in the future'], 'sql'=>[["SELECT COUNT(*) FROM patients WHERE PatientName='Future DOB Patient'",0]]];
    $cases[] = ['name' => 'Reception cannot book an unlinked doctor', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'3','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'40','PaymentMethod'=>'Cash'], 'contains'=>['must be linked to a Doctor user account'], 'sql'=>[["SELECT COUNT(*) FROM visits",0]]];
    $cases[] = ['name' => 'Reception books paid consultation into doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'1','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'25','PaymentMethod'=>'Cash','ChiefComplaint'=>'Skin rash'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'Waiting'],["SELECT PaymentStatus FROM visits WHERE VisitID=1",'Paid'],["SELECT COUNT(*) FROM payments WHERE VisitID=1 AND PaymentType='Consultation'",1],["SELECT COUNT(*) FROM accounting WHERE AccountID='REV-CONSULT'",1]]];
    $cases[] = ['name' => 'Opening a notification marks only that recipient read', 'role' => 'doctoruser', 'page' => 'doctors.php', 'get' => ['notification'=>'1'], 'sql'=>[["SELECT IsRead FROM notifications WHERE NotificationID=1",1]]];
    $cases[] = ['name' => 'Reception books partial consultation outside doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['PatientID'=>'1','DoctorID'=>'1','VisitDate'=>date('Y-m-d\TH:i'),'AmountPaid'=>'10','PaymentMethod'=>'Cash'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=2",'Pending Payment'],["SELECT DueBalance FROM visits WHERE VisitID=2",'15.00'],["SELECT DueBalance FROM patients WHERE PatientID=1",'15.00']]];
    $cases[] = ['name' => 'Doctor cannot work an unpaid consultation', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'save_notes','VisitID'=>'2','Diagnosis'=>'Must not save'], 'contains'=>['must record full consultation payment'], 'sql'=>[["SELECT Diagnosis FROM visits WHERE VisitID=2",'']]];
    $cases[] = ['name' => 'Reception settles consultation into doctor queue', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'consultations'], 'post' => ['form_action'=>'collect','VisitID'=>'2','PaymentAmount'=>'15','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=2",'Waiting'],["SELECT PaymentStatus FROM visits WHERE VisitID=2",'Paid'],["SELECT DueBalance FROM patients WHERE PatientID=1",'0.00'],["SELECT SUM(Amount) FROM payments WHERE VisitID=2",'25.00']]];
    $cases[] = ['name' => 'Doctor starts own consultation', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'start','VisitID'=>'1'], 'status'=>302, 'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'In Consultation']]];
    $cases[] = ['name' => 'Doctor saves connected clinical record', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'save_notes','VisitID'=>'1','ClinicalNotes'=>'Observed erythematous plaque','Diagnosis'=>'Contact dermatitis','TreatmentPlan'=>'Topical treatment','FollowUpPlan'=>'Review in two weeks','FollowUpDate'=>date('Y-m-d',strtotime('+14 days'))], 'status'=>302, 'sql'=>[["SELECT Diagnosis FROM visits WHERE VisitID=1",'Contact dermatitis']]];
    $cases[] = ['name' => 'Doctor prescription form uses live inventory choices', 'role' => 'doctoruser', 'page' => 'doctors.php', 'get' => ['visit'=>'1'], 'contains' => ['name="MedicationName[]"','Test Cream (10 available)','id="addPrescriptionLine"']];
    $cases[] = ['name' => 'Doctor cannot prescribe unknown inventory item', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>'Unknown Medicine','Quantity'=>'1'], 'contains'=>['not available in inventory'], 'sql'=>[["SELECT COUNT(*) FROM prescriptions",0]]];
    $cases[] = ['name' => 'Doctor prescription enters pharmacy queue', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>'Test Cream','Quantity'=>'2','Dosage'=>'Apply thin layer','Frequency'=>'Twice daily','Duration'=>'7 days','Instructions'=>'External use'], 'status'=>302, 'sql'=>[["SELECT Status FROM prescriptions WHERE VisitID=1",'Pending']]];
    $cases[] = ['name' => 'Doctor multi-test lab order uses catalogue totals', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'request_lab','VisitID'=>'1','ServiceID'=>['1','2'],'Price'=>'0.01','Description'=>'CBC and glucose requested'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE VisitID=1",'Awaiting Payment'],["SELECT PaymentStatus FROM laboratory WHERE VisitID=1",'Unpaid'],["SELECT TotalAmount FROM laboratory WHERE VisitID=1",'15.00'],["SELECT COUNT(*) FROM laborderitems WHERE LaboratoryID='LAB000001'",2],["SELECT SUM(UnitPrice) FROM laborderitems WHERE LaboratoryID='LAB000001'",'15.00']]];
    $cases[] = ['name' => 'Pharmacist dispenses prescription and reduces stock', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'prescriptions'], 'post' => ['form_action'=>'dispense','PrescriptionReference'=>'RX000001','AmountPaid'=>'20','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql'=>[["SELECT Status FROM prescriptions WHERE VisitID=1",'Dispensed'],["SELECT QuantityInStock FROM inventory WHERE ItemID='ITM000001'",8],["SELECT COUNT(*) FROM accounting WHERE AccountID='REV-PHARM'",1]]];
    $cases[] = ['name' => 'Multi-item prescription groups lines sequentially', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'prescribe','VisitID'=>'1','MedicationName'=>['Test Cream','Test Wash'],'Quantity'=>['100','1'],'Dosage'=>['Apply','Wash'],'Frequency'=>['Daily','Daily'],'Duration'=>['7 days','7 days'],'Instructions'=>['External','External']], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM prescriptions WHERE PrescriptionID LIKE 'RX000002-%'",2],["SELECT MedicationName FROM prescriptions WHERE PrescriptionID='RX000002-02'",'Test Wash']]];
    $cases[] = ['name' => 'Prescription shortage rolls back dispensing', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'prescriptions'], 'post' => ['form_action'=>'dispense','PrescriptionReference'=>'RX000002','AmountPaid'=>'1000','PaymentMethod'=>'Cash'], 'contains'=>['Insufficient stock'], 'sql'=>[["SELECT Status FROM prescriptions WHERE PrescriptionID='RX000002-01'",'Pending'],["SELECT QuantityInStock FROM inventory WHERE ItemID='ITM000001'",8]]];
    $cases[] = ['name' => 'Standalone POS ignores tampered browser prices', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'pos'], 'post' => ['CustomerName'=>'Walk-in','CustomerPhone'=>'','AmountPaid'=>'15','ItemID'=>['ITM000001','ITM000002'],'Quantity'=>['1','1'],'UnitPrice'=>['0.01','0.01']], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM pharmacysales WHERE SaleID LIKE 'POS000002-%'",2],["SELECT TotalAmount FROM pharmacysales WHERE SaleID='POS000002-01'",'15.00'],["SELECT UnitPrice FROM pharmacysales WHERE SaleID='POS000002-01'",'10.00'],["SELECT COUNT(*) FROM accounting WHERE AccountID='REV-PHARM' AND ReferenceID='POS000002'",1],["SELECT QuantityInStock FROM inventory WHERE ItemID='ITM000001'",7]]];
    $cases[] = ['name' => 'Voiding POS reverses stock and revenue', 'role' => 'pharmacyuser', 'page' => 'pharmacy.php', 'get' => ['section'=>'pos'], 'post' => ['form_action'=>'void','SaleRef'=>'POS000002'], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM pharmacysales WHERE SaleID LIKE 'POS000002-%'",0],["SELECT COUNT(*) FROM accounting WHERE ReferenceID='POS000002'",0],["SELECT QuantityInStock FROM inventory WHERE ItemID='ITM000001'",8]]];
    $cases[] = ['name' => 'Reception clears doctor lab request payment', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section'=>'laboratory'], 'post' => ['form_action'=>'collect','LaboratoryID'=>'LAB000001','PaymentAmount'=>'15','PaymentMethod'=>'Cash'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'Ready'],["SELECT COUNT(*) FROM accounting WHERE AccountID='REV-LAB'",1]]];
    $cases[] = ['name' => 'Laboratory starts only a paid ready order', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action'=>'start','LaboratoryID'=>'LAB000001'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'In Progress']]];
    $cases[] = ['name' => 'Laboratory completes doctor-requested tests', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID'=>'LAB000001','LabOrderItemID'=>['1','2'],'ItemResult'=>['Negative','Negative'],'ItemClinicalResult'=>['Normal count','Normal glucose'],'IsAvailable'=>'1'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'Completed'],["SELECT COUNT(*) FROM laborderitems WHERE LaboratoryID='LAB000001' AND Result='Negative'",2],["SELECT ClinicalResult FROM laborderitems WHERE LabOrderItemID=1",'Normal count']]];
    $cases[] = ['name' => 'Doctor reviews returned lab result', 'role' => 'doctoruser', 'page' => 'doctors.php', 'post' => ['portal_action'=>'review_result','VisitID'=>'1','LaboratoryID'=>'LAB000001'], 'status'=>302, 'sql'=>[["SELECT COUNT(*) FROM laboratory WHERE LaboratoryID='LAB000001' AND ReviewedAt IS NOT NULL",1]]];
    $cases[] = ['name' => 'Doctor queue excludes another doctor patients', 'role' => 'doctoruser', 'page' => 'doctors.php', 'before_sql' => ["INSERT INTO visits (VisitReference,PatientID,DoctorID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus) VALUES ('VISOTHER',2,2,NOW(),30,30,0,'Paid','Waiting')"], 'absent'=>['Other Patient']];
    $cases[] = ['name' => 'Patient with clinical history cannot be deleted', 'role' => 'superuser', 'page' => 'patients.php', 'post' => ['form_action'=>'delete','PatientID'=>'1'], 'contains'=>['clinical or financial history'], 'sql'=>[["SELECT COUNT(*) FROM patients WHERE PatientID=1",1]]];
    $cases[] = ['name' => 'Doctor with workflow history cannot be deleted', 'role' => 'superuser', 'page' => 'doctors.php', 'post' => ['form_action'=>'delete','DoctorID'=>'1'], 'contains'=>['consultation, prescription, or laboratory history'], 'sql'=>[["SELECT COUNT(*) FROM doctors WHERE DoctorID=1",1]]];
    $cases[] = ['name' => 'SuperAdmin cannot create patient lab orders manually', 'role' => 'superuser', 'page' => 'laboratory.php', 'post' => ['PatientID'=>'1','TestName'=>'Administrative lab','Price'=>'12','AmountPaid'=>'5'], 'status'=>403, 'sql'=>[["SELECT COUNT(*) FROM laboratory WHERE TestName='Administrative lab'",0]]];
    $cases[] = ['name' => 'Lab sees unpaid work but actions remain locked', 'role' => 'labuser', 'page' => 'laboratory.php', 'contains' => ['TESTPAID', 'TESTUNPAID', 'Start Test', 'Payment Locked'], 'absent' => ['id="addLabBtn"', 'value="delete"', 'href="patients.php']];
    $cases[] = ['name' => 'Stale SuperAdmin session loses Setup access', 'role' => 'pharmacyuser', 'session_role' => 'superuser', 'page' => 'setup.php', 'status' => 403];
    $cases[] = ['name' => 'Laboratory starts paid fixture before result entry', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action'=>'start','LaboratoryID'=>'TESTPAID'], 'status'=>302, 'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='TESTPAID'",'In Progress']]];
    $cases[] = ['name' => 'Bad CSRF cannot change lab results', 'role' => 'labuser', 'page' => 'laboratory.php', 'csrf' => 'invalid', 'post' => ['LaboratoryID' => 'TESTPAID', 'Result' => 'Positive'], 'contains' => ['session has expired'], 'sql' => [["SELECT Result FROM laboratory WHERE LaboratoryID = 'TESTPAID'", 'Pending']]];
    $cases[] = ['name' => 'Lab cannot bypass payment', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID' => 'TESTUNPAID', 'Result' => 'Positive', 'PaymentStatus' => 'Paid'], 'contains' => ['Reception must record full payment'], 'sql' => [["SELECT Result FROM laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Pending'], ["SELECT PaymentStatus FROM laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Unpaid']]];
    $cases[] = ['name' => 'Lab cannot delete orders', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['form_action' => 'delete', 'LaboratoryID' => 'TESTPAID'], 'status' => 403];
    $cases[] = ['name' => 'Lab saves results without changing bill', 'role' => 'labuser', 'page' => 'laboratory.php', 'post' => ['LaboratoryID' => 'TESTPAID', 'Result' => 'Negative', 'Price' => '1', 'PaymentStatus' => 'Unpaid', 'PatientID' => '999'], 'sql' => [["SELECT Result FROM laboratory WHERE LaboratoryID = 'TESTPAID'", 'Negative'], ["SELECT TotalAmount FROM laboratory WHERE LaboratoryID = 'TESTPAID'", '20.00'], ["SELECT PaymentStatus FROM laboratory WHERE LaboratoryID = 'TESTPAID'", 'Paid'], ["SELECT PatientID FROM laboratory WHERE LaboratoryID = 'TESTPAID'", 1]]];
    $cases[] = ['name' => 'Reception bills cannot overwrite results', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section' => 'laboratory'], 'post' => ['LaboratoryID' => 'TESTPAID', 'PatientID' => '1', 'TestName' => 'Paid test', 'Price' => '20', 'AmountPaid' => '20', 'Result' => 'Positive'], 'status'=>403, 'sql' => [["SELECT Result FROM laboratory WHERE LaboratoryID = 'TESTPAID'", 'Negative']]];
    $cases[] = ['name' => 'Reception pays order for lab handoff', 'role' => 'receptionuser', 'page' => 'reception.php', 'get' => ['section' => 'laboratory'], 'post' => ['form_action'=>'collect','LaboratoryID' => 'TESTUNPAID','PaymentAmount' => '20','PaymentMethod'=>'Mobile Money'], 'status'=>302, 'sql' => [["SELECT PaymentStatus FROM laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Paid'],["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID = 'TESTUNPAID'", 'Ready']]];
    $cases[] = ['name' => 'Lab now receives reception paid order', 'role' => 'labuser', 'page' => 'laboratory.php', 'contains' => ['TESTUNPAID']];
    $cases[] = ['name' => 'Deleted account loses access', 'role' => 'labuser', 'deleted' => true, 'page' => 'home.php', 'absent' => ['dashboard-metrics']];
    foreach (['reception.php' => ['patients', 'consultations', 'laboratory', 'pharmacy'], 'pharmacy.php' => ['prescriptions', 'pos', 'purchases', 'inventory'], 'accounting.php' => ['ledger', 'accounts'], 'reports.php' => ['income-statement', 'balance-sheet'], 'setup.php' => ['users','roles','permissions','clinical','laboratory','payment-methods','audit'], 'patients.php' => [null], 'doctors.php' => [null], 'laboratory.php' => [null]] as $page => $sections) {
        foreach ($sections as $section) {
            $cases[] = ['name' => "SuperAdmin section and search $page $section", 'role' => 'superuser', 'page' => $page, 'get' => array_filter(['section' => $section, 'q' => 'Test']), 'contains' => ['profile-trigger'], 'absent' => ['Fatal error', 'Warning:']];
        }
    }
    // Non-root SuperAdmin (role=superuser, is_root=0) must inherit full operational access.
    foreach ([
        ['Non-root SuperAdmin opens dashboard', 'home.php', [], 'dashboard-metrics'],
        ['Non-root SuperAdmin opens reception patients', 'reception.php', ['section' => 'patients'], 'profile-trigger'],
        ['Non-root SuperAdmin opens reception consultations', 'reception.php', ['section' => 'consultations'], 'profile-trigger'],
        ['Non-root SuperAdmin opens reception laboratory billing', 'reception.php', ['section' => 'laboratory'], 'profile-trigger'],
        ['Non-root SuperAdmin opens reception pharmacy billing', 'reception.php', ['section' => 'pharmacy'], 'profile-trigger'],
        ['Non-root SuperAdmin opens doctor directory', 'doctors.php', [], 'profile-trigger'],
        ['Non-root SuperAdmin opens laboratory workspace', 'laboratory.php', [], 'profile-trigger'],
        ['Non-root SuperAdmin opens pharmacy prescriptions', 'pharmacy.php', ['section' => 'prescriptions'], 'profile-trigger'],
        ['Non-root SuperAdmin opens pharmacy point of sale', 'pharmacy.php', ['section' => 'pos'], 'profile-trigger'],
        ['Non-root SuperAdmin opens pharmacy purchases', 'pharmacy.php', ['section' => 'purchases'], 'profile-trigger'],
        ['Non-root SuperAdmin opens pharmacy inventory', 'pharmacy.php', ['section' => 'inventory'], 'profile-trigger'],
        ['Non-root SuperAdmin opens patients module', 'patients.php', [], 'profile-trigger'],
        ['Non-root SuperAdmin opens accounting ledger', 'accounting.php', ['section' => 'ledger'], 'profile-trigger'],
        ['Non-root SuperAdmin opens reports income statement', 'reports.php', ['section' => 'income-statement'], 'profile-trigger'],
        ['Non-root SuperAdmin opens reports balance sheet', 'reports.php', ['section' => 'balance-sheet'], 'profile-trigger'],
        ['Non-root SuperAdmin opens setup users', 'setup.php', ['section' => 'users'], 'profile-trigger'],
        ['Non-root SuperAdmin opens setup permissions', 'setup.php', ['section' => 'permissions'], 'profile-trigger'],
        ['Non-root SuperAdmin opens setup audit', 'setup.php', ['section' => 'audit'], 'profile-trigger'],
    ] as [$caseName, $casePage, $caseGet, $caseMarker]) {
        $cases[] = ['name' => $caseName, 'role' => 'superadmin_nonroot', 'session_role' => 'superuser', 'page' => $casePage, 'get' => $caseGet, 'status' => 200, 'contains' => [$caseMarker], 'absent' => ['Access denied', 'Fatal error', 'Warning:']];
    }
    $cases[] = ['name' => 'Non-root SuperAdmin opens doctor workspace for assigned visit', 'role' => 'superadmin_nonroot', 'session_role' => 'superuser', 'page' => 'doctors.php', 'get' => ['workspace' => '1', 'doctor' => '1'], 'status' => 200, 'contains' => ['Doctor Workspace'], 'absent' => ['Access denied', 'Fatal error']];
    $cases[] = ['name' => 'Non-root SuperAdmin sees doctor workspace action in directory', 'role' => 'superadmin_nonroot', 'session_role' => 'superuser', 'page' => 'doctors.php', 'status' => 200, 'contains' => ['workspace=1'], 'absent' => ['Access denied']];
    require __DIR__ . '/operational_ui_cases.php';
    require __DIR__ . '/purchase_waiting_cases.php';
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
    if (in_array('--http', $argv, true)) {
        $env = getenv();
        $env['TDC_TEST_DB'] = $testDb;
        $env['TDC_TEST_SECRET'] = bin2hex(random_bytes(24));
        $env['TDC_TEST_PHP'] = PHP_BINARY;
        $process = proc_open(['node', __DIR__ . '/test_ui_http.mjs'], [1=>STDOUT, 2=>STDERR], $pipes, dirname(__DIR__), $env);
        if (proc_close($process) !== 0) $failed++;
    }
} finally {
    $pdo->exec('DROP DATABASE `' . $testDb . '`');
}
exit($failed ? 1 : 0);
