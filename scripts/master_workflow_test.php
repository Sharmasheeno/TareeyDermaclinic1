<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../db.local.php';
if (!in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) exit('Local database required.');
require __DIR__ . '/../db.php';
$db = 'tdc_role_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db`");
$test = new PDO('mysql:host='.DB_HOST.';dbname='.$db.';charset=utf8mb4',DB_USER,DB_PASS,$options);
$fail = 0; $checks = [];
function check_case(array $case): void {
    global $db, $fail, $checks;
    $case += ['role'=>'superuser','absent'=>['Fatal error','Warning:']];
    $process=proc_open([PHP_BINARY,__DIR__.'/test_role_access.php','worker',$db,base64_encode(json_encode($case))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    $result=json_decode(trim($out),true);$ok=$code===0 && $result && !$result['failures'] && $err==='';
    $checks[]=['name'=>$case['name'],'ok'=>$ok,'detail'=>$ok?'':$out.$err];
    if(!$ok)$fail++;
    echo ($ok?'PASS ':'FAIL ').$case['name'].($ok?'':' '.$out.$err).PHP_EOL;
}
try {
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec("CREATE TABLE `$db`.`$table` LIKE `".DB_NAME."`.`$table`");
    foreach(['roles','permissions','rolepermissions','paymentmethods'] as $table) $pdo->exec("INSERT INTO `$db`.`$table` SELECT * FROM `".DB_NAME."`.`$table`");
    require __DIR__.'/../auth/includes/operational-role-defaults.php';tdc_apply_operational_role_defaults($test);
    $password=bin2hex(random_bytes(20));
    $stmt=$test->prepare('INSERT INTO users (userlegalname,role,role_id,username,password,is_active,is_root) SELECT ?,RoleKey,RoleID,?,?,1,? FROM roles WHERE RoleKey=?');
    foreach(['superuser','doctoruser','receptionuser','labuser','pharmacyuser'] as $role)$stmt->execute(['E2E MASTER '.$role,$role,password_hash($password,PASSWORD_DEFAULT),$role==='superuser'?1:0,$role]);
    $test->exec("INSERT INTO doctors (UserID,DoctorName,ConsultationFee,WorkingDays,WorkStartTime,WorkEndTime) SELECT id,'E2E MASTER Doctor',8,'1,2,3,4,5,6,7','08:00:00','18:00:00' FROM users WHERE username='doctoruser'");
    $test->exec("INSERT INTO inventory (ItemID,ItemName,Category,QuantityInStock,SellingPrice,SalesUnit) VALUES ('MASTER01','E2E MASTER Cream','Medicine',100,10,'Tube')");
    foreach(['A','B'] as $letter) check_case(['name'=>'Register patient '.$letter.' sharing mobile','page'=>'reception.php','get'=>['section'=>'patients'],'post'=>['form_action'=>'save_patient','PatientName'=>'E2E MASTER Patient '.$letter,'PatientPhone'=>'610500123','Gender'=>'Female','Age'=>'30','PatientType'=>'New Patient','Remark'=>'MASTER controlled test'],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM visits",0],["SELECT COUNT(*) FROM payments",0]]]);
    check_case(['name'=>'Shared mobile returns both patients','page'=>'reception.php','get'=>['section'=>'patients','q'=>'610500123'],'contains'=>['E2E MASTER Patient A','E2E MASTER Patient B'],'sql'=>[["SELECT COUNT(*) FROM patients WHERE PatientPhone='610500123'",2]]]);
    $date=(new DateTimeImmutable('tomorrow'))->setTime(10,0)->format('Y-m-d\TH:i');
    $book=['PatientID'=>'1','DoctorID'=>'1','VisitDate'=>$date,'AmountPaid'=>'4','PaymentMethod'=>'Cash'];
    check_case(['name'=>'Overpayment rejected with no visit or payment','page'=>'reception.php','get'=>['section'=>'consultations'],'post'=>array_replace($book,['AmountPaid'=>'10']),'contains'=>['Amount paid cannot exceed the final consultation amount.'],'sql'=>[["SELECT COUNT(*) FROM visits",0],["SELECT COUNT(*) FROM payments",0]]]);
    check_case(['name'=>'Book partial credit consultation','page'=>'reception.php','get'=>['section'=>'consultations'],'post'=>$book,'status'=>302,'sql'=>[["SELECT ConsultationFee FROM visits WHERE VisitID=1",'8.00'],["SELECT DueBalance FROM visits WHERE VisitID=1",'4.00']]]);
    $ctx=['VisitID'=>'1','PatientID'=>'1','DoctorID'=>'1'];
    check_case(['name'=>'Pending Payment cannot complete','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'complete'],'contains'=>['Only a visit currently in consultation'],'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'Pending Payment']]]);
    check_case(['name'=>'Forged PatientID rejected','role'=>'doctoruser','page'=>'doctors.php','post'=>array_replace($ctx,['PatientID'=>'2','portal_action'=>'start']),'contains'=>['not assigned'],'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'Pending Payment']]]);
    check_case(['name'=>'Bad CSRF rejected','role'=>'doctoruser','page'=>'doctors.php','csrf'=>'invalid','post'=>$ctx+['portal_action'=>'start'],'contains'=>['session has expired'],'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'Pending Payment']]]);
    check_case(['name'=>'Call partial visit','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'start'],'status'=>302,'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'In Consultation'],["SELECT PaymentStatus FROM visits WHERE VisitID=1",'Partial']]]);
    check_case(['name'=>'Save clinical notes while partial','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'save_notes','ClinicalNotes'=>'E2E MASTER notes','Diagnosis'=>'E2E MASTER diagnosis'],'status'=>302,'sql'=>[["SELECT ClinicalNotes FROM visits WHERE VisitID=1",'E2E MASTER notes']]]);
    $masters=[
      ['category-header','category','CategoryID',['CategoryName'=>'E2E MASTER Category','DisplayOrder'=>'1']],
      ['lab-type','type','TypeID',['CategoryID'=>'1','TypeName'=>'E2E MASTER Type']],
      ['units','unit','UnitID',['UnitName'=>'E2E MASTER Unit','UnitSymbol'=>'EMU']],
      ['flags','flag','FlagID',['FlagName'=>'E2E MASTER Flag','FlagCode'=>'MASTER']],
      ['test-register','test','TestID',['CategoryID'=>'1','TypeID'=>'1','TestName'=>'E2E MASTER Test','Price'=>'12','ResultMode'=>'Structured Parameters']],
      ['lab-parameter','parameter','ParameterID',['TestID'=>'1','ParameterName'=>'E2E MASTER Parameter','ResultType'=>'Numeric','UnitID'=>'1','NormalMinimum'=>'5','NormalMaximum'=>'10','IsRequired'=>'1']],
      ['lab-center','center','LabCenterID',['CenterName'=>'E2E MASTER Center','Location'=>'Local']]
    ];
    $tables=['category'=>'lab_categories','type'=>'lab_types','unit'=>'lab_units','flag'=>'lab_flags','test'=>'lab_tests','parameter'=>'lab_parameters','center'=>'lab_centers'];
    foreach($masters as [$tab,$kind,$id,$fields]){
        $nameColumn=array_key_first($fields); if($kind==='type')$nameColumn='TypeName'; if($kind==='test')$nameColumn='TestName'; if($kind==='parameter')$nameColumn='ParameterName';
        $name=$test->quote($fields[$nameColumn]);
        $where="$nameColumn=$name";
        check_case(['name'=>'Lab add '.$kind,'page'=>'laboratory.php','get'=>['lab_tab'=>$tab],'post'=>['workspace_action'=>'lab_add_'.$kind,'IsActive'=>'1']+$fields,'status'=>302,'sql'=>[["SELECT COUNT(*) FROM {$tables[$kind]} WHERE $where",1]]]);
        $masterId=(int)$test->query("SELECT $id FROM {$tables[$kind]} WHERE $where")->fetchColumn();
        check_case(['name'=>'Lab deactivate '.$kind,'page'=>'laboratory.php','get'=>['lab_tab'=>$tab],'post'=>['workspace_action'=>'lab_update_'.$kind,$id=>(string)$masterId,'IsActive'=>'0']+$fields,'status'=>302,'sql'=>[["SELECT IsActive FROM {$tables[$kind]} WHERE $id=$masterId",0]]]);
        check_case(['name'=>'Lab activate '.$kind,'page'=>'laboratory.php','get'=>['lab_tab'=>$tab],'post'=>['workspace_action'=>'lab_update_'.$kind,$id=>(string)$masterId,'IsActive'=>'1']+$fields,'status'=>302,'sql'=>[["SELECT IsActive FROM {$tables[$kind]} WHERE $id=$masterId",1]]]);
    }
    check_case(['name'=>'Modern request authoritative price','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'request_lab','SelectedModernTestID'=>['1'],'Price'=>'0.01','Description'=>'E2E MASTER lab request'],'status'=>302,'sql'=>[["SELECT TotalAmount FROM laboratory WHERE LaboratoryID='LAB000001'",'12.00'],["SELECT ModernTestID FROM lab_order_catalog_bridge WHERE LaboratoryID='LAB000001'",1]]]);
    check_case(['name'=>'Lab queue reads actual local schema','role'=>'labuser','page'=>'laboratory.php','contains'=>['LAB000001','E2E MASTER Patient A','E2E MASTER Test']]);
    check_case(['name'=>'Create doctor test selection','page'=>'laboratory.php','get'=>['lab_tab'=>'lab-selection'],'post'=>['workspace_action'=>'lab_add_selection','TestID'=>'1','DoctorID'=>'1','LabCenterID'=>'1','IsEnabled'=>'1'],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM lab_test_selection",1]]]);
    check_case(['name'=>'Duplicate selection rejected','page'=>'laboratory.php','post'=>['workspace_action'=>'lab_add_selection','TestID'=>'1','DoctorID'=>'1','LabCenterID'=>'1','IsEnabled'=>'1'],'contains'=>['already exists'],'sql'=>[["SELECT COUNT(*) FROM lab_test_selection",1]]]);
    foreach ([0,1] as $enabled) check_case(['name'=>'Update selection availability '.$enabled,'page'=>'laboratory.php','get'=>['lab_tab'=>'lab-selection'],'post'=>['workspace_action'=>'lab_update_selection','SelectionID'=>'1','TestID'=>'1','DoctorID'=>'1','LabCenterID'=>'1','IsEnabled'=>(string)$enabled],'status'=>302,'sql'=>[["SELECT IsEnabled FROM lab_test_selection WHERE SelectionID=1",$enabled]]]);
    foreach ([1,0,1] as $enabled) check_case(['name'=>'Upsert center test mapping '.$enabled,'page'=>'laboratory.php','get'=>['lab_tab'=>'lab-center'],'post'=>['workspace_action'=>'lab_add_center_test','LabCenterID'=>'1','TestID'=>'1','IsActive'=>(string)$enabled],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM lab_center_tests",1],["SELECT IsActive FROM lab_center_tests LIMIT 1",$enabled]]]);
    $test->exec("INSERT INTO lab_tests (TypeID,TestName,Price) VALUES (1,'E2E MASTER second test',0)");
    $test->exec("INSERT INTO lab_parameters (TestID,ParameterName,ResultType,IsRequired) VALUES (2,'E2E MASTER second parameter','Text',1)");
    $test->exec("INSERT INTO lab_order_catalog_bridge (LaboratoryID,ModernTestID,ModernTestNameSnapshot,PriceSnapshot,SourceType) VALUES ('LAB000001',2,'E2E MASTER second test',0,'modern')");
    $labPost=['LaboratoryID'=>'LAB000001'];
    check_case(['name'=>'Collect unpaid modern multi-test order','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'collect_sample'],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM lab_results WHERE CollectedAt IS NOT NULL",2],["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'In Progress'],["SELECT DueBalance FROM laboratory WHERE LaboratoryID='LAB000001'",'12.00']]]);
    check_case(['name'=>'Required modern results block completion atomically','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'complete_lab_result'],'contains'=>['All required parameters'],'sql'=>[["SELECT COUNT(*) FROM lab_results WHERE ResultStatus='Completed'",0]]]);
    check_case(['name'=>'Forged parameter rejected atomically','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'save_lab_result_draft','ParameterID'=>['1','99999'],'ResultValue'=>['8','bad']],'contains'=>['does not belong'],'sql'=>[["SELECT COUNT(*) FROM lab_result_parameters",0]]]);
    check_case(['name'=>'Invalid numeric rejected','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'save_lab_result_draft','ParameterID'=>['1'],'ResultValue'=>['invalid']],'contains'=>['valid numeric result'],'sql'=>[["SELECT COUNT(*) FROM lab_result_parameters",0]]]);
    check_case(['name'=>'Save both modern result drafts','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'save_lab_result_draft','ParameterID'=>['1','2'],'ResultValue'=>['11','Observed'],'Remark'=>['E2E MASTER remark','']],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM lab_result_parameters",2],["SELECT FlagCodeSnapshot FROM lab_result_parameters WHERE ParameterID=1",'H']]]);
    check_case(['name'=>'Reopen modern draft values','role'=>'labuser','page'=>'laboratory.php','get'=>['workspace'=>'1','result'=>'LAB000001'],'contains'=>['value="11"','value="Observed"','E2E MASTER remark']]);
    check_case(['name'=>'Complete modern results preserves debt and identities','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'complete_lab_result'],'status'=>302,'sql'=>[["SELECT COUNT(*) FROM lab_results WHERE ResultStatus='Completed'",2],["SELECT COUNT(*) FROM lab_result_parameters",2],["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'Completed'],["SELECT PaymentStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'Unpaid'],["SELECT DueBalance FROM laboratory WHERE LaboratoryID='LAB000001'",'12.00']]]);
    check_case(['name'=>'Finalized modern result cannot be overwritten','role'=>'labuser','page'=>'laboratory.php','post'=>$labPost+['form_action'=>'save_lab_result_draft','ParameterID'=>['1'],'ResultValue'=>['99']],'contains'=>['Select an active laboratory order'],'sql'=>[["SELECT RawResult FROM lab_result_parameters WHERE ParameterID=1",'11']]]);
    check_case(['name'=>'Doctor reads completed modern result via bridge','role'=>'doctoruser','page'=>'doctors.php','get'=>['workspace'=>'1','visit'=>'1'],'contains'=>['Observed','E2E MASTER remark']]);
    check_case(['name'=>'Prescription reaches pharmacy with no stock change','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'prescribe','MedicationName'=>['E2E MASTER Cream'],'Quantity'=>['2'],'Instructions'=>['E2E MASTER instruction']],'status'=>302,'sql'=>[["SELECT VisitID FROM prescriptions WHERE PrescriptionID='RX000001-01'",1],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",100]]]);
    check_case(['name'=>'Pharmacy receives authoritative prescription','role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'contains'=>['RX000001','E2E MASTER Patient A']]);
    check_case(['name'=>'Prescription bill uses authoritative price without stock movement','role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'sql'=>[["SELECT TotalAmount FROM prescriptions LIMIT 1",'20.00'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",100],["SELECT DueBalance FROM patients WHERE PatientID=1",'36.00']]]);
    check_case(['name'=>'Collect partial prescription payment without stock movement','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'collect','BillRef'=>'RX000001','PaymentAmount'=>'5','PaymentMethod'=>'Cash'],'status'=>302,'sql'=>[["SELECT DueBalance FROM prescriptions LIMIT 1",'15.00'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",100]]]);
    check_case(['name'=>'Bill adjustment preserves confirmed payment and debt','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'adjust','BillRef'=>'RX000001','DiscountType'=>'None','DiscountValue'=>'0','TaxRate'=>'0'],'status'=>302,'sql'=>[["SELECT AmountPaid FROM prescriptions LIMIT 1",'5.00'],["SELECT DueBalance FROM prescriptions LIMIT 1",'15.00']]]);
    check_case(['name'=>'Prescription overpayment rejected','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'collect','BillRef'=>'RX000001','PaymentAmount'=>'16','PaymentMethod'=>'Cash'],'contains'=>['cannot exceed'],'sql'=>[["SELECT AmountPaid FROM prescriptions LIMIT 1",'5.00']]]);
    check_case(['name'=>'Settle prescription payment without stock movement','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'collect','BillRef'=>'RX000001','PaymentAmount'=>'15','PaymentMethod'=>'Cash'],'status'=>302,'sql'=>[["SELECT DueBalance FROM prescriptions LIMIT 1",'0.00'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",100]]]);
    check_case(['name'=>'Dispense exactly once without synthetic POS','role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'post'=>['prescription_action'=>'dispense','PrescriptionReference'=>'RX000001'],'status'=>302,'sql'=>[["SELECT Status FROM prescriptions LIMIT 1",'Dispensed'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",98],["SELECT COUNT(*) FROM pharmacysales",0]]]);
    check_case(['name'=>'Repeated dispense does not move stock','role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'post'=>['prescription_action'=>'dispense','PrescriptionReference'=>'RX000001'],'contains'=>['already been dispensed'],'sql'=>[["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",98]]]);
    check_case(['name'=>'Collect completed lab debt without reopening clinical work','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'laboratory'],'post'=>['form_action'=>'collect','LaboratoryID'=>'LAB000001','PaymentAmount'=>'5','PaymentMethod'=>'Cash'],'status'=>302,'sql'=>[["SELECT WorkflowStatus FROM laboratory WHERE LaboratoryID='LAB000001'",'Completed'],["SELECT DueBalance FROM laboratory WHERE LaboratoryID='LAB000001'",'7.00'],["SELECT DueBalance FROM patients WHERE PatientID=1",'11.00']]]);
    check_case(['name'=>'Lab overpayment rejected','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'laboratory'],'post'=>['form_action'=>'collect','LaboratoryID'=>'LAB000001','PaymentAmount'=>'8','PaymentMethod'=>'Cash'],'contains'=>['cannot exceed'],'sql'=>[["SELECT DueBalance FROM laboratory WHERE LaboratoryID='LAB000001'",'7.00']]]);
    check_case(['name'=>'Complete consultation preserves credit debt','role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'save_notes','ClinicalNotes'=>'E2E MASTER notes','Diagnosis'=>'E2E MASTER diagnosis','complete'=>'1'],'status'=>302,'sql'=>[["SELECT QueueStatus FROM visits WHERE VisitID=1",'Completed'],["SELECT DueBalance FROM visits WHERE VisitID=1",'4.00'],["SELECT PaymentStatus FROM visits WHERE VisitID=1",'Partial']]]);
    foreach ([0,5] as $idx=>$initialPaid) {
        $ref='RX'.str_pad((string)($idx+2),6,'0',STR_PAD_LEFT);
        $state=$initialPaid===0?'Unpaid':'Partial';
        $stockBefore=98-$idx;
        $stock=97-$idx;
        // Use a separate active visit to keep completed-care assertions independent.
        $test->exec("UPDATE visits SET QueueStatus='In Consultation' WHERE VisitID=1");
        check_case(['name'=>"Create $state credit prescription",'role'=>'doctoruser','page'=>'doctors.php','post'=>$ctx+['portal_action'=>'prescribe','MedicationName'=>['E2E MASTER Cream'],'Quantity'=>['1']],'status'=>302]);
        check_case(['name'=>"$state prescription uses authoritative inventory price",'role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'sql'=>[["SELECT TotalAmount FROM prescriptions WHERE PrescriptionID='$ref-01'",'10.00'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",$stockBefore]]]);
        if ($initialPaid) check_case(['name'=>'Collect before partial dispense','role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'collect','BillRef'=>$ref,'PaymentAmount'=>(string)$initialPaid,'PaymentMethod'=>'Cash'],'status'=>302]);
        $beforeCount=(int)$test->query('SELECT COUNT(*) FROM payments')->fetchColumn();
        $beforeDue=$test->query('SELECT DueBalance FROM patients WHERE PatientID=1')->fetchColumn();
        check_case(['name'=>"$state queue exposes Dispense",'role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'contains'=>[$ref,'Dispense'],'absent'=>['Reception payment required','WAITING FOR PAYMENT']]);
        check_case(['name'=>"$state dispensing retains debt without fake payment or POS",'role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'post'=>['prescription_action'=>'dispense','PrescriptionReference'=>$ref],'status'=>302,'sql'=>[["SELECT Status FROM prescriptions WHERE PrescriptionID='$ref-01'",'Dispensed'],["SELECT AmountPaid FROM prescriptions WHERE PrescriptionID='$ref-01'",number_format($initialPaid,2,'.','')],["SELECT DueBalance FROM prescriptions WHERE PrescriptionID='$ref-01'",number_format(10-$initialPaid,2,'.','')],["SELECT DueBalance FROM patients WHERE PatientID=1",$beforeDue],["SELECT COUNT(*) FROM payments",$beforeCount],["SELECT COUNT(*) FROM pharmacysales",0],["SELECT COUNT(*) FROM prescriptions WHERE PrescriptionID='$ref-01' AND PharmacySaleReference IS NOT NULL",0],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",$stock]]]);
        check_case(['name'=>"Repeated $state dispense cannot deduct twice",'role'=>'pharmacyuser','page'=>'pharmacy.php','get'=>['section'=>'prescriptions'],'post'=>['prescription_action'=>'dispense','PrescriptionReference'=>$ref],'contains'=>['already been dispensed'],'sql'=>[["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",$stock]]]);
        check_case(['name'=>"Later payment settles dispensed $state prescription and AccR",'role'=>'receptionuser','page'=>'reception.php','get'=>['section'=>'pharmacy'],'post'=>['form_action'=>'collect','BillRef'=>$ref,'PaymentAmount'=>(string)(10-$initialPaid),'PaymentMethod'=>'Cash'],'status'=>302,'sql'=>[["SELECT DueBalance FROM prescriptions WHERE PrescriptionID='$ref-01'",'0.00'],["SELECT DueBalance FROM patients WHERE PatientID=1",'11.00'],["SELECT QuantityInStock FROM inventory WHERE ItemID='MASTER01'",$stock]]]);
    }
    $test->exec("UPDATE visits SET QueueStatus='Completed' WHERE VisitID=1");
    $consultationPayment=(int)$test->query("SELECT PaymentID FROM payments WHERE PaymentType='Consultation' AND VisitID=1 ORDER BY PaymentID LIMIT 1")->fetchColumn();
    check_case(['name'=>'Consultation reversal excludes lab payments sharing VisitID','page'=>'accounting.php','get'=>['section'=>'payments'],'post'=>['form_action'=>'reverse','PaymentID'=>(string)$consultationPayment,'ReversalAmount'=>'1','ReversalReason'=>'E2E MASTER source isolation'],'status'=>302,'sql'=>[["SELECT AmountPaid FROM visits WHERE VisitID=1",'3.00'],["SELECT DueBalance FROM visits WHERE VisitID=1",'5.00'],["SELECT QueueStatus FROM visits WHERE VisitID=1",'Completed'],["SELECT AmountPaid FROM laboratory WHERE LaboratoryID='LAB000001'",'5.00'],["SELECT DueBalance FROM patients WHERE PatientID=1",'12.00']]]);
    check_case(['name'=>'Accounting entries balance after linked payments and reversal','page'=>'accounting.php','get'=>['section'=>'payments'],'contains'=>['Prescription','Laboratory','Consultation'],'sql'=>[["SELECT ROUND(SUM(Debit)-SUM(Credit),2) FROM accounting",'0.00'],["SELECT COUNT(*) FROM payments WHERE PaymentType='Pharmacy' AND (PrescriptionReference IS NULL OR SaleReference IS NOT NULL)",0]]]);
    check_case(['name'=>'Prescription finance separates billed revenue, cash, COGS and receivables','page'=>'reports.php','get'=>['section'=>'income-statement'],'contains'=>['Pharmacy Gross Profit','Drug Sold (billed revenue)','cash collected','outstanding prescription balances']]);
    if(in_array('--final',$argv,true)) require __DIR__.'/final_remaining_cases.php';
    // A second active controlled visit is reserved for visible browser button tests.
    $test->exec("INSERT INTO visits (VisitReference,PatientID,DoctorID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus) VALUES ('MASTERUI',2,1,NOW(),8,0,8,'Unpaid','Waiting')");
    file_put_contents(__DIR__.'/master-audit-pass.json',json_encode(['database'=>$db,'checks'=>$checks,'failures'=>$fail,'trace'=>['patient'=>1,'visit'=>1,'laboratory'=>'LAB000001','prescription'=>'RX000001']],JSON_PRETTY_PRINT));
    if(in_array('--keep',$argv,true)) {
        file_put_contents(sys_get_temp_dir().'/tdc-master-browser.json',json_encode(['database'=>$db,'username'=>'superuser','password'=>$password]));
        echo 'Disposable database retained for browser verification: '.$db.PHP_EOL;
    }
} finally {
    if(!in_array('--keep',$argv,true))$pdo->exec("DROP DATABASE `$db`");
}
echo count($checks).' checks; '.$fail.' failed.'.PHP_EOL;
exit($fail?1:0);
