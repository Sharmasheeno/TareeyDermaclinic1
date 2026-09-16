<?php
declare(strict_types=1);

$stmt = $pdo->prepare('SELECT * FROM doctors WHERE UserID=?');
$stmt->execute([$_SESSION['user_id']]);
$doctorProfile = $stmt->fetch();
// SuperAdmin accounts have no linked Doctors row by design. Let a SuperAdmin
// act on behalf of a chosen doctor so the full clinical workflow stays
// reachable without a separate doctor login.
$superadminDoctorMode = false;
if (tdc_is_root_superadmin()) {
    $superadminDoctorMode = true;
    $requestedDoctorId = ctype_digit((string) ($_GET['doctor'] ?? '')) ? (int) $_GET['doctor'] : 0;
    $requestedVisit = $_POST['VisitID'] ?? $_GET['visit'] ?? '';
    if (ctype_digit((string) $requestedVisit)) {
        $doctorLookup = $pdo->prepare('SELECT DoctorID FROM visits WHERE VisitID=?');
        $doctorLookup->execute([(int) $requestedVisit]);
        $requestedDoctorId = (int) $doctorLookup->fetchColumn();
    }
    if ($requestedDoctorId <= 0) {
        $requestedDoctorId = (int) $pdo->query('SELECT DoctorID FROM doctors ORDER BY DoctorID LIMIT 1')->fetchColumn();
    }
    if ($requestedDoctorId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM doctors WHERE DoctorID=?');
        $stmt->execute([$requestedDoctorId]);
        $doctorProfile = $stmt->fetch();
    }
}$portalErrors = [];
$medicineOptions = [];
$medicineByName = [];
$labServices = [];
if ($doctorProfile) {
    $medicineOptions = $pdo->query("SELECT ItemID,ItemName,QuantityInStock,SalesUnit,SellingPrice FROM inventory WHERE QuantityInStock > 0 ORDER BY ItemName")->fetchAll();
    foreach ($medicineOptions as $medicineOption) {
        $medicineByName[mb_strtolower(trim((string)$medicineOption['ItemName']))] = $medicineOption;
    }
    $labServices = $pdo->query('SELECT ServiceID,ServiceName,Category,Description,Price FROM labservices WHERE IsActive=1 AND IsAvailable=1 ORDER BY Category,ServiceName')->fetchAll();
}

if ($doctorProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $portalErrors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $visitId = ctype_digit((string)($_POST['VisitID'] ?? '')) ? (int)$_POST['VisitID'] : 0;
        $stmt = $pdo->prepare('SELECT v.*,p.* FROM visits v JOIN patients p ON p.PatientID=v.PatientID WHERE v.VisitID=? AND v.DoctorID=?');
        $stmt->execute([$visitId,$doctorProfile['DoctorID']]);
        $visit = $stmt->fetch();
        if (!$visit) $portalErrors[] = 'This consultation is not assigned to your account.';
        $action = (string)($_POST['portal_action'] ?? '');
        $actionPermissions = ['start'=>'consultations.edit','save_notes'=>'consultations.edit','prescribe'=>'pharmacy.prescription.create','request_lab'=>'laboratory.request','review_result'=>'laboratory.results.view'];
        if (!isset($actionPermissions[$action])) tdc_forbidden();
        tdc_require_permission($actionPermissions[$action]);
        if (!$portalErrors && $action !== 'review_result' && $visit['PaymentStatus'] !== 'Paid') {
            $portalErrors[] = 'Reception must record full consultation payment before clinical work can begin.';
        }
        if (!$portalErrors && $action === 'start') {
            $stmt = $pdo->prepare("UPDATE visits SET QueueStatus='In Consultation' WHERE VisitID=? AND QueueStatus='Waiting'");
            $stmt->execute([$visitId]);
        } elseif (!$portalErrors && $action === 'save_notes') {
            $complete = isset($_POST['complete']);
            $stmt = $pdo->prepare("UPDATE visits SET ClinicalNotes=?,Diagnosis=?,TreatmentPlan=?,FollowUpPlan=?,FollowUpDate=?,QueueStatus=?,CompletedAt=? WHERE VisitID=? AND DoctorID=?");
            $stmt->execute([trim((string)($_POST['ClinicalNotes'] ?? '')),trim((string)($_POST['Diagnosis'] ?? '')),trim((string)($_POST['TreatmentPlan'] ?? '')),trim((string)($_POST['FollowUpPlan'] ?? '')),($_POST['FollowUpDate'] ?? '') ?: null,$complete?'Completed':'In Consultation',$complete?date('Y-m-d H:i:s'):null,$visitId,$doctorProfile['DoctorID']]);
        } elseif (!$portalErrors && $action === 'prescribe') {
            $medications = is_array($_POST['MedicationName'] ?? null) ? $_POST['MedicationName'] : [$_POST['MedicationName'] ?? ''];
            $quantities = is_array($_POST['Quantity'] ?? null) ? $_POST['Quantity'] : [$_POST['Quantity'] ?? ''];
            $dosages = is_array($_POST['Dosage'] ?? null) ? $_POST['Dosage'] : [$_POST['Dosage'] ?? ''];
            $frequencies = is_array($_POST['Frequency'] ?? null) ? $_POST['Frequency'] : [$_POST['Frequency'] ?? ''];
            $durations = is_array($_POST['Duration'] ?? null) ? $_POST['Duration'] : [$_POST['Duration'] ?? ''];
            $instructions = is_array($_POST['Instructions'] ?? null) ? $_POST['Instructions'] : [$_POST['Instructions'] ?? ''];
            $prescriptionLines = [];
            foreach ($medications as $index => $medicationValue) {
                $medication = trim((string)$medicationValue);
                if ($medication === '') continue;
                $inventoryItem = $medicineByName[mb_strtolower($medication)] ?? null;
                $quantityValue = trim((string)($quantities[$index] ?? ''));
                if (!$inventoryItem) {
                    $portalErrors[] = 'Medication on line '.($index + 1).' is not available in inventory.';
                } elseif (!ctype_digit($quantityValue) || (int)$quantityValue < 1) {
                    $portalErrors[] = 'Quantity on line '.($index + 1).' must be a positive whole number.';
                } else {
                    $prescriptionLines[] = ['name'=>(string)$inventoryItem['ItemName'],'quantity'=>(int)$quantityValue,'dosage'=>trim((string)($dosages[$index] ?? '')),'frequency'=>trim((string)($frequencies[$index] ?? '')),'duration'=>trim((string)($durations[$index] ?? '')),'instructions'=>trim((string)($instructions[$index] ?? ''))];
                }
            }
            if (!$prescriptionLines && !$portalErrors) $portalErrors[] = 'Add at least one medication.';
            if (!$portalErrors) {
                $base = tdc_workflow_next_reference($pdo,'prescriptions','PrescriptionID','RX');
                $stmt = $pdo->prepare('INSERT INTO prescriptions (PrescriptionID,PatientID,VisitID,PatientName,PatientPhone,PatientAddress,Gender,Age,VisitNumber,DoctorID,MedicationName,Quantity,Dosage,Frequency,Duration,Instructions,Status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Pending\')');
                $pdo->beginTransaction();
                try {
                    foreach ($prescriptionLines as $index => $line) {
                        $id = $base.'-'.str_pad((string)($index + 1),2,'0',STR_PAD_LEFT);
                        $stmt->execute([$id,$visit['PatientID'],$visitId,$visit['PatientName'],$visit['PatientPhone'],$visit['PatientAddress'],$visit['Gender'],$visit['Age'],$visit['VisitNumber'],$doctorProfile['DoctorID'],$line['name'],$line['quantity'],$line['dosage'],$line['frequency'],$line['duration'],$line['instructions']]);
                    }
                    tdc_workflow_notify($pdo,null,'pharmacyuser','prescription_created','Prescription ready to dispense',$base.' for '.$visit['PatientName'],'pharmacy.php?section=prescriptions');
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        } elseif (!$portalErrors && $action === 'request_lab') {
            $submittedServices = is_array($_POST['ServiceID'] ?? null) ? $_POST['ServiceID'] : [$_POST['ServiceID'] ?? ''];
            $serviceIds = array_values(array_unique(array_map('intval',array_filter($submittedServices,static fn($id)=>ctype_digit((string)$id) && (int)$id>0))));
            $services = [];
            if ($serviceIds) {
                $placeholders=implode(',',array_fill(0,count($serviceIds),'?'));
                $stmt=$pdo->prepare("SELECT ServiceID,ServiceName,Price FROM labservices WHERE IsActive=1 AND IsAvailable=1 AND ServiceID IN ($placeholders) ORDER BY ServiceID");
                $stmt->execute($serviceIds);$services=$stmt->fetchAll();
            }
            if (!$services || count($services)!==count($serviceIds)) $portalErrors[] = 'Select one or more active laboratory services.';
            if (!$portalErrors) {
                $test = implode(', ',array_column($services,'ServiceName'));
                $price = round(array_sum(array_map(static fn($service)=>(float)$service['Price'],$services)),2);
                $labRef = tdc_workflow_next_reference($pdo,'laboratory','LaboratoryID','LAB');
                $testId = (int)$pdo->query('SELECT COALESCE(MAX(TestID),0)+1 FROM laboratory')->fetchColumn();
                $stmt = $pdo->prepare("INSERT INTO laboratory (LaboratoryID,PatientID,VisitID,DoctorID,RequestedByUserID,ServiceID,TestID,TestName,Description,TotalAmount,DueBalance,PaymentStatus,WorkflowStatus,Result) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Unpaid','Awaiting Payment','Pending')");
                $pdo->beginTransaction();
                try {
                    $stmt->execute([$labRef,$visit['PatientID'],$visitId,$doctorProfile['DoctorID'],$_SESSION['user_id'],count($services)===1?(int)$services[0]['ServiceID']:null,$testId,$test,trim((string)($_POST['Description'] ?? '')),$price,$price]);
                    $itemStmt=$pdo->prepare('INSERT INTO laborderitems (LaboratoryID,ServiceID,TestName,UnitPrice) VALUES (?,?,?,?)');
                    foreach($services as $service)$itemStmt->execute([$labRef,(int)$service['ServiceID'],$service['ServiceName'],round((float)$service['Price'],2)]);
                    tdc_workflow_notify($pdo,null,'receptionuser','lab_payment_due','Laboratory payment required',$labRef.' for '.$visit['PatientName'],'reception.php?section=laboratory');
                    $pdo->commit();
                } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            }
        } elseif (!$portalErrors && $action === 'review_result') {
            $stmt = $pdo->prepare('UPDATE laboratory SET ReviewedAt=NOW() WHERE LaboratoryID=? AND DoctorID=? AND VisitID=? AND WorkflowStatus=\'Completed\'');
            $stmt->execute([trim((string)($_POST['LaboratoryID'] ?? '')),$doctorProfile['DoctorID'],$visitId]);
        }
        if (!$portalErrors) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: doctors.php'.($visitId ? '?visit='.$visitId.'&success=1' : '?success=1'));
            exit;
        }
    }
}

$selectedVisit = null;
$prescriptions = [];
$labOrders = [];
if ($doctorProfile && ctype_digit((string)($_GET['visit'] ?? ''))) {
    $stmt = $pdo->prepare('SELECT v.*,p.PatientName,p.PatientPhone,p.Gender,p.Age,p.PatientAddress FROM visits v JOIN patients p ON p.PatientID=v.PatientID WHERE v.VisitID=? AND v.DoctorID=?');
    $stmt->execute([(int)$_GET['visit'],$doctorProfile['DoctorID']]);
    $selectedVisit = $stmt->fetch();
    if ($selectedVisit) {
        $stmt=$pdo->prepare('SELECT * FROM prescriptions WHERE VisitID=? ORDER BY PrescriptionDate DESC');$stmt->execute([$selectedVisit['VisitID']]);$prescriptions=$stmt->fetchAll();
        $stmt=$pdo->prepare('SELECT * FROM laboratory WHERE VisitID=? ORDER BY OrderDate DESC');$stmt->execute([$selectedVisit['VisitID']]);$labOrders=$stmt->fetchAll();
    }
}
[$waitingFrom, $waitingTo, $waitingDateError] = tdc_date_range_resolve();
$waitingSchedule = ($_GET['tab'] ?? '') === 'schedule';
if ($waitingSchedule && !isset($_GET['from_date']) && !isset($_GET['to_date'])) {
    $waitingFrom = date('Y-m-d');
    $waitingTo = date('Y-m-d', strtotime('+7 days'));
}
$waitingSearch = trim((string) ($_GET['q'] ?? ''));
$waitingPerPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($waitingPerPage, [10,25,50,100], true)) $waitingPerPage = 10;
$waitingPage = max(1, (int) ($_GET['page'] ?? 1));
$queue=[];
$readyResults=[];
if ($doctorProfile) {
    $queueWhere = $superadminDoctorMode ? ['1=1'] : ['v.DoctorID=?'];
    $queueParams = $superadminDoctorMode ? [] : [$doctorProfile['DoctorID']];
    if ($waitingDateError) $queueWhere[] = '1=0';
    if ($waitingFrom !== '' && tdc_ui_is_date($waitingFrom)) { $queueWhere[] = 'v.VisitDate>=?'; $queueParams[] = $waitingFrom.' 00:00:00'; }
    if ($waitingTo !== '' && tdc_ui_is_date($waitingTo)) { $queueWhere[] = 'v.VisitDate<=?'; $queueParams[] = $waitingTo.' 23:59:59'; }
    if ($waitingSearch !== '') {
        $queueWhere[] = '(v.VisitReference LIKE ? OR p.PatientName LIKE ? OR p.PatientPhone LIKE ?)';
        array_push($queueParams, '%'.$waitingSearch.'%', '%'.$waitingSearch.'%', '%'.$waitingSearch.'%');
    }
    $stmt=$pdo->prepare("SELECT v.*,p.PatientName,p.PatientPhone,p.Gender,p.Age,d.DoctorName FROM visits v JOIN patients p ON p.PatientID=v.PatientID JOIN doctors d ON d.DoctorID=v.DoctorID WHERE ".implode(' AND ', $queueWhere)." ORDER BY v.VisitDate DESC,v.VisitID DESC");
    $stmt->execute($queueParams);$queue=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT l.LaboratoryID,l.VisitID,l.TestName,l.ResultDate,p.PatientName FROM laboratory l JOIN patients p ON p.PatientID=l.PatientID WHERE l.DoctorID=? AND l.WorkflowStatus='Completed' AND l.ReviewedAt IS NULL ORDER BY l.ResultDate DESC,l.OrderDate DESC");
    $stmt->execute([$doctorProfile['DoctorID']]);$readyResults=$stmt->fetchAll();
}
if (($_GET['export'] ?? '') === 'csv' && !$waitingDateError) {
    tdc_require_permission('doctor.workspace');
    $rows = array_map(static fn(array $v): array => [$v['VisitReference'],$v['PatientName'],$v['Gender'],$v['Age'],$v['PatientPhone'],$v['VisitDate'],$v['DoctorName'],$v['QueueStatus']], $queue);
    tdc_csv_download('doctor-waiting.csv', ['Visit','Patient','Gender','Age','Phone','Date Added','Doctor','Status'], $rows);
}
$waitingTotal = count($queue);
$waitingPage = min($waitingPage, max(1, (int) ceil($waitingTotal / $waitingPerPage)));
$waitingRows = array_slice($queue, ($waitingPage - 1) * $waitingPerPage, $waitingPerPage);
$legalName=(string)$_SESSION['userlegalname'];$displayName=tdc_display_name($legalName);$avatarLetters=strtoupper(substr($displayName,0,2));$csrfToken=(string)$_SESSION['csrf_token'];
require __DIR__ . '/doctor-workspace-view.php';
return;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Doctor Portal | Tarey Derma Clinic</title><link rel="stylesheet" href="../assets/clinic.css"><style>body{font-family:Arial,sans-serif}.portal-header{background:var(--primary);padding:8px 30px;display:flex;align-items:center;justify-content:space-between;min-height:58px}.portal-brand{background:#fff;padding:7px 12px}.portal-brand img{height:28px}.portal-nav{display:flex;gap:8px;background:#fff;border-bottom:1px solid var(--border-ui);padding:0 30px}.portal-nav a{padding:15px;color:var(--primary);text-decoration:none;font-weight:700;font-size:13px}.portal-grid{display:grid;grid-template-columns:minmax(320px,.8fr) minmax(0,1.7fr);gap:16px}.portal-panel{padding:18px;border:1px solid var(--border-ui);border-radius:8px;background:#fff}.portal-panel h2{font-size:16px;margin-bottom:14px}.queue-link{display:block;padding:12px;border-bottom:1px solid var(--border-ui);color:var(--text-primary);text-decoration:none}.queue-link:hover{background:var(--primary-soft)}.queue-link span{display:block;color:var(--text-muted);font-size:11px;margin-top:4px}.clinical-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.clinical-grid .full{grid-column:1/-1}.rx-line{border-bottom:1px solid var(--border-ui);padding-bottom:12px;margin-bottom:12px}.rx-line:last-child{border-bottom:0}.rx-meta{font-size:11px;color:var(--text-muted);margin-top:5px;min-height:14px}@media(max-width:850px){.portal-grid,.clinical-grid{grid-template-columns:1fr}.clinical-grid .full{grid-column:auto}}</style><script src="../assets/clinic.js" defer></script></head><body>
<header><div class="portal-header"><a class="portal-brand" href="home.php"><img src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic"></a><?php require __DIR__.'/profile.php'; ?></div><nav class="portal-nav"><a href="home.php">Dashboard</a><a href="doctors.php">Doctor Workspace</a></nav></header>
<main class="page-body"><div class="welcome-eyebrow">Doctor Portal</div><div class="welcome-title"><?= $doctorProfile?tdc_e($doctorProfile['DoctorName']):'Doctor profile not linked' ?></div><div class="welcome-sub"><?= $doctorProfile?'Your assigned consultations, clinical records, prescriptions, and laboratory requests.':'Ask a SuperAdmin to link this user account to a doctor directory profile.' ?></div>
<?php if($portalErrors): ?><div class="error-msg"><?= tdc_e(implode(' ',$portalErrors)) ?></div><?php endif; ?>
<?php if($doctorProfile): ?><div class="portal-grid" style="margin-top:24px"><section class="portal-panel"><h2>Today’s consultation queue</h2><?php if(!$queue): ?><div class="empty-state"><strong>No assigned consultations</strong><span>Paid bookings will appear here.</span></div><?php else:foreach($queue as $q): ?><a class="queue-link" href="doctors.php?visit=<?= (int)$q['VisitID'] ?>"><strong><?= tdc_e($q['PatientName']) ?></strong><span><?= tdc_e($q['VisitReference']) ?> · <?= tdc_e(date('H:i',strtotime($q['VisitDate']))) ?> · <?= tdc_e($q['QueueStatus']) ?></span></a><?php endforeach;endif; ?><h2 style="margin-top:22px">Lab results ready (<?= count($readyResults) ?>)</h2><?php if(!$readyResults): ?><div class="empty-state compact"><strong>No results awaiting review</strong></div><?php else:foreach($readyResults as $ready): ?><a class="queue-link" href="doctors.php?visit=<?= (int)$ready['VisitID'] ?>"><strong><?= tdc_e($ready['PatientName']) ?></strong><span><?= tdc_e($ready['LaboratoryID']) ?> · <?= tdc_e($ready['TestName']) ?></span></a><?php endforeach;endif; ?></section>
<section class="portal-panel"><?php if(!$selectedVisit): ?><div class="empty-state"><strong>Select a patient</strong><span>Open an assigned consultation from the queue.</span></div><?php else: ?><div class="panel-heading"><div><h2><?= tdc_e($selectedVisit['PatientName']) ?></h2><p><?= tdc_e($selectedVisit['VisitReference']) ?> · <?= tdc_e($selectedVisit['PaymentStatus']) ?> · <?= tdc_e($selectedVisit['QueueStatus']) ?></p></div></div>
<?php if($selectedVisit['PaymentStatus']!=='Paid'): ?><div class="empty-state compact"><strong>Payment required</strong><span>Reception must settle this consultation before clinical work can begin.</span></div><?php else: ?>
<?php if($selectedVisit['QueueStatus']==='Waiting'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="start"><button class="btn btn-primary">Start Consultation</button></form><?php endif; ?>
<form method="post" style="margin-top:16px"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="save_notes"><div class="clinical-grid"><div class="form-group full"><label>Clinical Notes</label><textarea name="ClinicalNotes"><?= tdc_e($selectedVisit['ClinicalNotes']) ?></textarea></div><div class="form-group"><label>Diagnosis</label><textarea name="Diagnosis"><?= tdc_e($selectedVisit['Diagnosis']) ?></textarea></div><div class="form-group"><label>Treatment Plan</label><textarea name="TreatmentPlan"><?= tdc_e($selectedVisit['TreatmentPlan']) ?></textarea></div><div class="form-group"><label>Follow-up Plan</label><textarea name="FollowUpPlan"><?= tdc_e($selectedVisit['FollowUpPlan']) ?></textarea></div><div class="form-group"><label>Follow-up Date</label><input type="date" name="FollowUpDate" value="<?= tdc_e($selectedVisit['FollowUpDate']) ?>"></div></div><label><input type="checkbox" name="complete" value="1"> Mark consultation complete</label><div style="margin-top:12px"><button class="btn btn-primary">Save Consultation</button></div></form>
<div class="clinical-grid" style="margin-top:24px"><form method="post" class="workflow-form" id="prescriptionForm"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="prescribe"><h2>Create Prescription</h2><div id="prescriptionLines"><div class="rx-line"><div class="form-group"><label>Medication</label><select name="MedicationName[]" class="rx-medicine" required><option value="">Select available medicine</option><?php foreach($medicineOptions as $medicine): ?><option value="<?= tdc_e($medicine['ItemName']) ?>" data-stock="<?= (int)$medicine['QuantityInStock'] ?>" data-unit="<?= tdc_e((string)$medicine['SalesUnit']) ?>" data-price="<?= tdc_e((string)$medicine['SellingPrice']) ?>"><?= tdc_e($medicine['ItemName']) ?> (<?= (int)$medicine['QuantityInStock'] ?> available)</option><?php endforeach; ?></select><div class="rx-meta" aria-live="polite"></div></div><div class="form-row"><div class="form-group"><label>Quantity</label><input type="number" min="1" name="Quantity[]" value="1" required></div><div class="form-group"><label>Dosage</label><input name="Dosage[]"></div></div><div class="form-row"><div class="form-group"><label>Frequency</label><input name="Frequency[]"></div><div class="form-group"><label>Duration</label><input name="Duration[]"></div></div><div class="form-group"><label>Instructions</label><textarea name="Instructions[]"></textarea></div><button type="button" class="btn-sm danger remove-rx-line" hidden>Remove</button></div></div><div class="row-actions"><button type="button" class="btn btn-secondary" id="addPrescriptionLine">+ Add Item</button><button class="btn btn-primary">Send to Pharmacy</button></div></form>
<form method="post" class="workflow-form">
<input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="request_lab"><h2>Request Lab Test</h2>
<div class="form-group"><label>Laboratory Services</label><select name="ServiceID[]" id="labServiceSelect" required multiple size="5" onchange="document.getElementById('labServicePrice').value=Array.from(this.selectedOptions).reduce((total,option)=>total+Number(option.dataset.price||0),0).toFixed(2)"><?php foreach($labServices as $service): ?><option value="<?= (int)$service['ServiceID'] ?>" data-price="<?= tdc_e((string)$service['Price']) ?>" title="<?= tdc_e((string)$service['Description']) ?>"><?= tdc_e(($service['Category'] ? $service['Category'].' · ' : '').$service['ServiceName']) ?> — <?= number_format((float)$service['Price'],2) ?></option><?php endforeach; ?></select><div class="rx-meta">Select all tests required for this order.</div></div>
<div class="form-group"><label>Fee</label><input id="labServicePrice" value="0.00" readonly aria-label="Configured laboratory fee"></div><div class="form-group"><label>Clinical Request</label><textarea name="Description"></textarea></div>
<button class="btn btn-primary" <?= !$labServices?'disabled':'' ?>>Send to Reception</button><?php if(!$labServices): ?><div class="rx-meta">A SuperAdmin must configure laboratory services before orders can be created.</div><?php endif; ?></form></div>
<h2 style="margin-top:24px">Visit activity</h2><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Item</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach($prescriptions as $p): ?><tr><td>Prescription</td><td><?= tdc_e($p['MedicationName']) ?></td><td><span class="status-badge"><?= tdc_e($p['Status']) ?></span></td><td><?= tdc_e(date('d M Y',strtotime($p['PrescriptionDate']))) ?></td><td>—</td></tr><?php endforeach;foreach($labOrders as $l): ?><tr><td>Laboratory</td><td><?= tdc_e($l['TestName']) ?><br><span class="cell-sub"><?= tdc_e($l['ClinicalResult'] ?: $l['Description']) ?></span></td><td><span class="status-badge"><?= tdc_e($l['WorkflowStatus']) ?></span></td><td><?= tdc_e(date('d M Y',strtotime($l['OrderDate']))) ?></td><td><?php if($l['WorkflowStatus']==='Completed' && !$l['ReviewedAt']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><input type="hidden" name="portal_action" value="review_result"><button class="btn-sm">Mark Reviewed</button></form><?php elseif($l['ReviewedAt']): ?><span class="status-badge">Reviewed</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach;if(!$prescriptions&&!$labOrders): ?><tr class="empty-row"><td colspan="5">No prescriptions or laboratory requests for this visit.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?><?php endif; ?></section></div><?php endif; ?></main><script>(function(){const box=document.getElementById('prescriptionLines');const add=document.getElementById('addPrescriptionLine');if(!box||!add)return;function wire(line){const select=line.querySelector('.rx-medicine');const qty=line.querySelector('input[name="Quantity[]"]');const meta=line.querySelector('.rx-meta');const remove=line.querySelector('.remove-rx-line');function sync(){const option=select.options[select.selectedIndex];if(!option||!option.value){meta.textContent='';qty.removeAttribute('max');return;}qty.max=option.dataset.stock;meta.textContent=option.dataset.stock+' '+(option.dataset.unit||'units')+' available · '+Number(option.dataset.price||0).toFixed(2)+' each';}select.addEventListener('change',sync);remove.addEventListener('click',function(){if(box.children.length>1){line.remove();syncRemovers();}});sync();}function syncRemovers(){box.querySelectorAll('.remove-rx-line').forEach(function(button){button.hidden=box.children.length===1;});}box.querySelectorAll('.rx-line').forEach(wire);add.addEventListener('click',function(){const line=box.firstElementChild.cloneNode(true);line.querySelectorAll('input,textarea').forEach(function(field){field.value=field.name==='Quantity[]'?'1':'';});line.querySelector('select').selectedIndex=0;box.appendChild(line);wire(line);syncRemovers();});syncRemovers();})();</script></body></html>
