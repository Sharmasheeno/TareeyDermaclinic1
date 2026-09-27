<?php
declare(strict_types=1);

function tdc_resolve_doctor_visit_context(PDO $pdo, int $visitId, int $patientId, int $doctorId): ?array
{
    if ($visitId <= 0 || $patientId <= 0 || $doctorId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT v.*, p.*, d.DoctorName FROM visits v '
        . 'JOIN patients p ON p.PatientID = v.PatientID '
        . 'LEFT JOIN doctors d ON d.DoctorID = v.DoctorID '
        . 'WHERE v.VisitID = ? AND p.PatientID = ? AND v.DoctorID = ? LIMIT 1'
    );
    $stmt->execute([$visitId, $patientId, $doctorId]);
    return $stmt->fetch() ?: null;
}

function tdc_doctor_lab_catalog(PDO $pdo): array
{
    return [
        'categories' => $pdo->query('SELECT CategoryID, CategoryName FROM lab_categories WHERE IsActive = 1 ORDER BY DisplayOrder, CategoryName')->fetchAll(),
        'types' => $pdo->query('SELECT TypeID, CategoryID, TypeName FROM lab_types WHERE IsActive = 1 ORDER BY DisplayOrder, TypeName')->fetchAll(),
        'tests' => $pdo->query(
            'SELECT lt.TestID, lt.TypeID, lt.TestName, lt.Price, lty.CategoryID '
            . 'FROM lab_tests lt JOIN lab_types lty ON lty.TypeID = lt.TypeID '
            . 'JOIN lab_categories lc ON lc.CategoryID = lty.CategoryID '
            . 'WHERE lt.IsActive = 1 AND lty.IsActive = 1 AND lc.IsActive = 1 '
            . 'ORDER BY lc.DisplayOrder, lty.DisplayOrder, lt.DisplayOrder, lt.TestName'
        )->fetchAll(),
    ];
}

function tdc_doctor_patient_history(PDO $pdo, int $patientId): array
{
    $visits = $pdo->prepare(
        'SELECT v.VisitReference, v.VisitDate, d.DoctorName, v.ChiefComplaint, v.Diagnosis, '
        . 'v.ClinicalNotes, v.TreatmentPlan, v.FollowUpPlan '
        . 'FROM visits v LEFT JOIN doctors d ON d.DoctorID = v.DoctorID '
        . 'WHERE v.PatientID = ? ORDER BY v.VisitDate DESC, v.VisitID DESC'
    );
    $visits->execute([$patientId]);

    $labs = $pdo->prepare(
        'SELECT LaboratoryID, VisitID, TestName, Result, ClinicalResult, ResultDate, OrderDate, WorkflowStatus '
        . 'FROM laboratory WHERE PatientID = ? ORDER BY OrderDate DESC, LaboratoryID DESC'
    );
    $labs->execute([$patientId]);

    $prescriptions = $pdo->prepare(
        'SELECT PrescriptionID, VisitID, MedicationName, Quantity, Frequency, Duration, Instructions, Status, PrescriptionDate '
        . 'FROM prescriptions WHERE PatientID = ? ORDER BY PrescriptionDate DESC, PrescriptionID DESC'
    );
    $prescriptions->execute([$patientId]);

    return [
        'visits' => $visits->fetchAll(),
        'labs' => $labs->fetchAll(),
        'prescriptions' => $prescriptions->fetchAll(),
    ];
}

function tdc_doctor_modern_results(PDO $pdo, int $patientId, int $visitId = 0): array
{
    $visitFilter = $visitId > 0 ? ' AND l.VisitID = ?' : '';
    $stmt = $pdo->prepare(
        'SELECT b.LaboratoryID, b.BridgeID, r.LabResultID, p.ParameterID, '
        . 'b.ModernTestNameSnapshot AS TestName, r.ResultStatus, '
        . 'p.ParameterNameSnapshot, p.DisplayResult, p.UnitNameSnapshot, '
        . 'p.ReferenceRangeSnapshot, p.FlagNameSnapshot, p.Remark, rv.Action AS ReviewAction,rv.Notes AS ReviewNotes,rv.PerformedAt,ru.userlegalname AS ReviewerName '
        . 'FROM lab_order_catalog_bridge b '
        . 'JOIN laboratory l ON l.LaboratoryID = b.LaboratoryID '
        . 'LEFT JOIN lab_results r ON r.BridgeID = b.BridgeID '
        . 'LEFT JOIN lab_result_parameters p ON p.LabResultID = r.LabResultID '
        . 'LEFT JOIN lab_result_review rv ON rv.ReviewID=(SELECT rr.ReviewID FROM lab_result_review rr WHERE rr.LabResultID=r.LabResultID ORDER BY rr.PerformedAt DESC,rr.ReviewID DESC LIMIT 1) '
        . 'LEFT JOIN users ru ON ru.id=rv.PerformedBy '
        . 'WHERE l.PatientID = ? AND b.IsActive = 1 ' . $visitFilter . ' '
        . 'ORDER BY b.CreatedAt DESC, b.BridgeID, r.LabResultID, p.ParameterID'
    );
    $params = [$patientId];
    if ($visitId > 0) $params[] = $visitId;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function tdc_doctor_legacy_results(PDO $pdo, int $patientId, int $visitId = 0): array
{
    $visitFilter = $visitId > 0 ? ' AND l.VisitID = ?' : '';
    $stmt = $pdo->prepare(
        'SELECT l.LaboratoryID, i.TestName AS ItemTestName, l.TestName, '
        . 'i.Result AS ItemResult, i.ClinicalResult AS ItemClinicalResult, i.ResultDate AS ItemResultDate, '
        . 'l.Result, l.ClinicalResult, l.ResultDate, l.WorkflowStatus '
        . 'FROM laboratory l LEFT JOIN lab_order_catalog_bridge b '
        . 'ON b.LaboratoryID = l.LaboratoryID AND b.IsActive = 1 '
        . 'LEFT JOIN laborderitems i ON i.LaboratoryID = l.LaboratoryID '
        . 'WHERE l.PatientID = ? AND b.BridgeID IS NULL ' . $visitFilter . ' '
        . 'ORDER BY l.OrderDate DESC, i.LabOrderItemID'
    );
    $params = [$patientId];
    if ($visitId > 0) $params[] = $visitId;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function tdc_doctor_lab_edit_guard(PDO $pdo, string $labId): array
{
    $stmt = $pdo->prepare(
        'SELECT l.LaboratoryID, l.WorkflowStatus, '
        . 'EXISTS (SELECT 1 FROM lab_order_catalog_bridge b LEFT JOIN lab_results r ON r.BridgeID = b.BridgeID WHERE b.LaboratoryID = l.LaboratoryID AND (r.LabResultID IS NOT NULL OR l.WorkflowStatus IN (\'Completed\',\'Cancelled\'))) AS has_started '
        . 'FROM laboratory l WHERE l.LaboratoryID = ? LIMIT 1'
    );
    $stmt->execute([$labId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['editable' => false, 'message' => 'This laboratory request could not be found.'];
    }
    if ((int) ($row['has_started'] ?? 0) === 1 || in_array((string) ($row['WorkflowStatus'] ?? ''), ['Completed', 'Cancelled'], true)) {
        return ['editable' => false, 'message' => 'This laboratory request can no longer be changed because result processing has already started.'];
    }
    if (!in_array((string) ($row['WorkflowStatus'] ?? ''), ['Requested', 'Awaiting Payment', 'Ready'], true)) {
        return ['editable' => false, 'message' => 'This laboratory request can no longer be changed because result processing has already started.'];
    }
    return ['editable' => true, 'message' => ''];
}

function tdc_doctor_prescription_edit_guard(PDO $pdo, string $reference): array
{
    $ref = preg_replace('/[^A-Za-z0-9]/', '', $reference) ?: '';
    if ($ref === '') {
        return ['editable' => false, 'message' => 'This prescription reference is missing.'];
    }
    $stmt = $pdo->prepare("SELECT MIN(Status) AS min_status, MAX(CASE WHEN Status='Dispensed' OR DispensedAt IS NOT NULL OR PharmacySaleReference IS NOT NULL THEN 1 ELSE 0 END) AS dispensed_flag FROM prescriptions WHERE PrescriptionID LIKE ?");
    $stmt->execute([$ref . '-%']);
    $row = $stmt->fetch();
    if (!$row || !$stmt->rowCount()) {
        return ['editable' => false, 'message' => 'This prescription could not be found.'];
    }
    if ((int) ($row['dispensed_flag'] ?? 0) === 1 || (string) ($row['min_status'] ?? '') !== 'Pending') {
        return ['editable' => false, 'message' => 'This prescription can no longer be changed because it has already been dispensed.'];
    }
    return ['editable' => true, 'message' => ''];
}

function tdc_doctor_consultation_ready_for_orders(PDO $pdo, int $visitId, int $doctorId): bool
{
    if ($visitId <= 0 || $doctorId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT QueueStatus, ClinicalNotes, Diagnosis FROM visits WHERE VisitID = ? AND DoctorID = ? LIMIT 1');
    $stmt->execute([$visitId, $doctorId]);
    $visit = $stmt->fetch();
    if (!$visit) {
        return false;
    }
    // Orders may be added while the consultation is open or after it has
    // been completed. Requiring both clinical notes and a diagnosis keeps
    // the workflow safe while allowing the doctor to finish lab and
    // prescription work after closing the consultation.
    if (!in_array((string) ($visit['QueueStatus'] ?? ''), ['In Consultation', 'Completed'], true)) {
        return false;
    }
    return trim((string) ($visit['ClinicalNotes'] ?? '')) !== '' && trim((string) ($visit['Diagnosis'] ?? '')) !== '';
}

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
$doctorLabCatalog = tdc_doctor_lab_catalog($pdo);
if ($doctorProfile) {
    $medicineOptions = $pdo->query("SELECT ItemID,ItemName,QuantityInStock,SalesUnit,SellingPrice FROM inventory WHERE QuantityInStock > 0 ORDER BY ItemName")->fetchAll();
    foreach ($medicineOptions as $medicineOption) {
        $medicineByName[mb_strtolower(trim((string)$medicineOption['ItemName']))] = $medicineOption;
    }
    $labServicesStmt = $pdo->prepare(
        'SELECT lt.TestID, lt.TestName, lt.Price, lty.CategoryID, lt.TypeID, lc.CategoryName, lty.TypeName, ls.IsEnabled, ls.LabCenterID '
        . 'FROM lab_tests lt '
        . 'LEFT JOIN lab_types lty ON lty.TypeID = lt.TypeID '
        . 'LEFT JOIN lab_categories lc ON lc.CategoryID = lty.CategoryID '
        . 'LEFT JOIN lab_test_selection ls ON ls.TestID = lt.TestID AND (ls.DoctorID IS NULL OR ls.DoctorID = ?) '
        . 'WHERE lt.IsActive = 1 AND (ls.IsEnabled IS NULL OR ls.IsEnabled = 1) '
        . 'ORDER BY lc.CategoryName, lty.TypeName, lt.TestName'
    );
    $labServicesStmt->execute([$doctorProfile['DoctorID']]);
    $labServices = $labServicesStmt->fetchAll();
}

if ($doctorProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $portalErrors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $visitId = ctype_digit((string)($_POST['VisitID'] ?? '')) ? (int)$_POST['VisitID'] : 0;
        $patientId = ctype_digit((string)($_POST['PatientID'] ?? '')) ? (int)$_POST['PatientID'] : 0;
        $postedDoctorId = ctype_digit((string)($_POST['DoctorID'] ?? '')) ? (int)$_POST['DoctorID'] : 0;
        if ($postedDoctorId > 0 && $postedDoctorId !== (int) $doctorProfile['DoctorID']) {
            $portalErrors[] = 'This consultation is not assigned to your doctor profile.';
        }
        $visit = $visitId > 0 && $patientId > 0
            ? tdc_resolve_doctor_visit_context($pdo, $visitId, $patientId, (int) $doctorProfile['DoctorID'])
            : null;
        if (!$visit && $patientId === 0) {
            $stmt = $pdo->prepare('SELECT v.*,p.* FROM visits v JOIN patients p ON p.PatientID=v.PatientID WHERE v.VisitID=? AND v.DoctorID=?');
            $stmt->execute([$visitId,$doctorProfile['DoctorID']]);
            $visit = $stmt->fetch();
        }
        if (!$visit) $portalErrors[] = 'This consultation is not assigned to your account.';
        $action = (string)($_POST['portal_action'] ?? '');
        $actionPermissions = ['start'=>'consultations.edit','complete'=>'consultations.edit','save_notes'=>'consultations.edit','prescribe'=>'pharmacy.prescription.create','request_lab'=>'laboratory.request','review_result'=>'laboratory.results.view','edit_lab'=>'laboratory.request','edit_prescription'=>'pharmacy.prescription.create'];
        if (!isset($actionPermissions[$action])) tdc_forbidden();
        tdc_require_permission($actionPermissions[$action]);
        if (!$portalErrors && $action === 'start') {
            $stmt = $pdo->prepare("UPDATE visits SET QueueStatus='In Consultation' WHERE VisitID=? AND QueueStatus IN ('Waiting','Pending Payment')");
            $stmt->execute([$visitId]);
        } elseif (!$portalErrors && $action === 'complete') {
            if (($visit['QueueStatus'] ?? '') !== 'In Consultation') {
                $portalErrors[] = 'Only a visit currently in consultation can be completed.';
            } else {
                $stmt = $pdo->prepare("UPDATE visits SET QueueStatus='Completed', CompletedAt=NOW() WHERE VisitID=? AND DoctorID=? AND QueueStatus='In Consultation'");
                $stmt->execute([$visitId, $doctorProfile['DoctorID']]);
            }
        } elseif (!$portalErrors && $action === 'save_notes') {
            $clinicalNotes = trim((string)($_POST['ClinicalNotes'] ?? ''));
            $diagnosis = trim((string)($_POST['Diagnosis'] ?? ''));
            $complete = isset($_POST['complete']);
            if ($clinicalNotes === '' || $diagnosis === '') {
                $portalErrors[] = 'Clinical Notes and Diagnosis are required before the consultation can be saved.';
            }
            if ($complete && (($visit['QueueStatus'] ?? '') !== 'In Consultation')) {
                $portalErrors[] = 'Start Consultation first, then save the clinical notes and mark the visit complete.';
            }
            if (!$portalErrors) {
                $stmt = $pdo->prepare("UPDATE visits SET ClinicalNotes=?,Diagnosis=?,QueueStatus=?,CompletedAt=? WHERE VisitID=? AND DoctorID=? AND QueueStatus='In Consultation'");
                $stmt->execute([
                    $clinicalNotes,
                    $diagnosis,
                    $complete ? 'Completed' : 'In Consultation',
                    $complete ? date('Y-m-d H:i:s') : null,
                    $visitId,
                    $doctorProfile['DoctorID'],
                ]);
            }
        } elseif (!$portalErrors && $action === 'prescribe') {
            if (!tdc_doctor_consultation_ready_for_orders($pdo, $visitId, (int) $doctorProfile['DoctorID'])) {
                $portalErrors[] = 'Save the initial consultation before creating a prescription.';
            }
            if (!$portalErrors) {
                $medications = is_array($_POST['MedicationName'] ?? null) ? $_POST['MedicationName'] : [$_POST['MedicationName'] ?? ''];
                $quantities = is_array($_POST['Quantity'] ?? null) ? $_POST['Quantity'] : [$_POST['Quantity'] ?? ''];
                $frequencies = is_array($_POST['Frequency'] ?? null) ? $_POST['Frequency'] : [$_POST['Frequency'] ?? ''];
                $durations = is_array($_POST['Duration'] ?? null) ? $_POST['Duration'] : [$_POST['Duration'] ?? ''];
                $instructions = is_array($_POST['Instructions'] ?? null) ? $_POST['Instructions'] : [$_POST['Instructions'] ?? ''];
                $routes = is_array($_POST['Route'] ?? null) ? $_POST['Route'] : [(string) ($_POST['Route'] ?? '')];
                $prescriptionLines = [];
                $seenMedication = [];
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
                        $medicationKey = mb_strtolower((string) $inventoryItem['ItemName']);
                        if (isset($seenMedication[$medicationKey])) {
                            $portalErrors[] = 'Medication on line '.($index + 1).' has already been added.';
                            continue;
                        }
                        $seenMedication[$medicationKey] = true;
                        $routeValue = trim((string) ($routes[$index] ?? ''));
                        if ($routeValue === '') {
                            $routeValue = 'Oral';
                        }
                        $prescriptionLines[] = ['name'=>(string)$inventoryItem['ItemName'],'quantity'=>(int)$quantityValue,'sellingPrice'=>(float)($inventoryItem['SellingPrice'] ?? 0),'frequency'=>trim((string)($frequencies[$index] ?? '')),'duration'=>trim((string)($durations[$index] ?? '')),'route'=>$routeValue,'instructions'=>trim((string)($instructions[$index] ?? ''))];
                    }
                }
                if (!$prescriptionLines && !$portalErrors) $portalErrors[] = 'Add at least one medication.';
                if (!$portalErrors) {
                    $grossAmount = round(array_sum(array_map(static fn(array $line): float => (float) $line['quantity'] * (float) ($line['sellingPrice'] ?? 0), $prescriptionLines)), 2);
                    $base = tdc_workflow_next_reference($pdo,'prescriptions','PrescriptionID','RX');
                    $stmt = $pdo->prepare('INSERT INTO prescriptions (PrescriptionID,PatientID,VisitID,PatientName,PatientPhone,PatientAddress,Gender,Age,VisitNumber,DoctorID,MedicationName,Quantity,Route,Frequency,Duration,Instructions,Status,TotalAmount,AmountPaid,DueBalance) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $pdo->beginTransaction();
                    try {
                        foreach ($prescriptionLines as $index => $line) {
                            $id = $base.'-'.str_pad((string)($index + 1),2,'0',STR_PAD_LEFT);
                            $stmt->execute([$id,$visit['PatientID'],$visitId,$visit['PatientName'],$visit['PatientPhone'],$visit['PatientAddress'],$visit['Gender'],$visit['Age'],$visit['VisitNumber'],$doctorProfile['DoctorID'],$line['name'],$line['quantity'],$line['route'],$line['frequency'],$line['duration'],$line['instructions'],'Pending',$grossAmount,0,$grossAmount]);
                        }
                        tdc_reconcile_patient_due_balance($pdo, (int) $visit['PatientID']);
                        tdc_workflow_notify_permission($pdo,'pharmacy.prescriptions.view','prescription_created','Prescription ready to dispense',$base.' for '.$visit['PatientName'],'pharmacy.php?section=prescriptions','pharmacyuser');
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            }
        } elseif (!$portalErrors && $action === 'request_lab') {
            if (!tdc_doctor_consultation_ready_for_orders($pdo, $visitId, (int) $doctorProfile['DoctorID'])) {
                $portalErrors[] = 'Save the initial consultation before requesting laboratory tests.';
            }
            if (!$portalErrors) {
                $submittedModern = is_array($_POST['SelectedModernTestID'] ?? null) ? $_POST['SelectedModernTestID'] : [];
                $testIds = [];
                foreach ($submittedModern as $candidate) {
                    if (ctype_digit((string) $candidate) && (int) $candidate > 0) {
                        $testIds[(int) $candidate] = (int) $candidate;
                    }
                }
                $testIds = array_values($testIds);
                if (!$testIds) { $portalErrors[] = 'Select at least one active lab test.'; }
                $allowedTests = [];
                if (!$portalErrors) {
                    $placeholders = implode(',', array_fill(0, count($testIds), '?'));
                    $stmt = $pdo->prepare(
                        "SELECT lt.TestID, lt.TestName, lt.Price, lc.CategoryName, lty.TypeName
                         FROM lab_tests lt
                         LEFT JOIN lab_types lty ON lty.TypeID = lt.TypeID
                         LEFT JOIN lab_categories lc ON lc.CategoryID = lty.CategoryID
                         LEFT JOIN lab_test_selection ls ON ls.TestID = lt.TestID AND (ls.DoctorID IS NULL OR ls.DoctorID = ?)
                         WHERE lt.TestID IN ($placeholders) AND lt.IsActive = 1 AND (ls.IsEnabled IS NULL OR ls.IsEnabled = 1)"
                    );
                    $params = array_merge([$doctorProfile['DoctorID']], $testIds);
                    $stmt->execute($params);
                    $allowedTests = $stmt->fetchAll();
                    $allowedById = [];
                    foreach ($allowedTests as $test) { $allowedById[(string) $test['TestID']] = $test; }
                    if (count($allowedById) !== count($testIds)) {
                        $portalErrors[] = 'One or more selected tests are not enabled for this doctor.';
                    }
                }
                if (!$portalErrors) {
                    $testNames = array_column($allowedTests, 'TestName');
                    $price = round(array_sum(array_map(static fn($test) => (float) ($test['Price'] ?? 0), $allowedTests)), 2);
                    $sortedSelected = $testIds;
                    sort($sortedSelected, SORT_NUMERIC);
                    $duplicateStmt = $pdo->prepare(
                        'SELECT l.LaboratoryID FROM laboratory l '
                        . 'LEFT JOIN lab_order_catalog_bridge b ON b.LaboratoryID = l.LaboratoryID AND b.IsActive = 1 '
                        . 'WHERE l.PatientID = ? AND l.VisitID = ? AND l.DoctorID = ? '
                        . "AND l.WorkflowStatus IN ('Requested','Awaiting Payment','Ready','In Progress') "
                        . 'GROUP BY l.LaboratoryID HAVING COUNT(DISTINCT b.ModernTestID) = ? AND SUM(CASE WHEN b.ModernTestID IN (' . implode(',', array_fill(0, count($sortedSelected), '?')) . ') THEN 1 ELSE 0 END) = ?'
                    );
                    $duplicateParams = array_merge([(int)$visit['PatientID'], $visitId, (int)$doctorProfile['DoctorID'], count($sortedSelected)], $sortedSelected, [count($sortedSelected)]);
                    $duplicateStmt->execute($duplicateParams);
                    $duplicateLabId = $duplicateStmt->fetchColumn();
                    if ($duplicateLabId) {
                        $portalErrors[] = 'This exact lab request was already submitted for this visit. Please use the existing order instead.';
                    }
                    if (!$portalErrors) {
                        $labRef = tdc_workflow_next_reference($pdo,'laboratory','LaboratoryID','LAB');
                        $pdo->beginTransaction();
                        try {
                            $legacyTestId = 0;
                            $stmt = $pdo->prepare("INSERT INTO laboratory (LaboratoryID,PatientID,VisitID,DoctorID,RequestedByUserID,ServiceID,TestID,TestName,Description,TotalAmount,DueBalance,PaymentStatus,WorkflowStatus,Result) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Unpaid','Requested','Pending')");
                            $stmt->execute([$labRef,(int) $visit['PatientID'],$visitId,(int) $doctorProfile['DoctorID'],(int) $_SESSION['user_id'],null,$legacyTestId,implode(', ', $testNames),trim((string)($_POST['Description'] ?? '')),$price,$price]);
                            $bridge = $pdo->prepare('INSERT INTO lab_order_catalog_bridge (LaboratoryID,ModernTestID,ModernTestNameSnapshot,PriceSnapshot,SourceType,DisplayOrder,IsActive) VALUES (?,?,?,?,?,?,1)');
                            foreach ($allowedTests as $index => $test) {
                                $bridge->execute([$labRef,(int) $test['TestID'],(string) $test['TestName'],round((float) $test['Price'],2),'modern',$index + 1]);
                            }
                            tdc_workflow_notify_permission($pdo,'lab_billing.view','lab_payment_due','Laboratory payment required',$labRef.' for '.$visit['PatientName'],'reception.php?section=laboratory','receptionuser');
                            tdc_reconcile_patient_due_balance($pdo,(int)$visit['PatientID']);
                            $pdo->commit();
                        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                    }
                }
            }
        } elseif (!$portalErrors && $action === 'review_result') {
            require_once __DIR__.'/lab-results.php';
            $portalErrors=tdc_review_lab_results($pdo,trim((string)($_POST['LaboratoryID']??'')),$visit,(int)$_SESSION['user_id'],trim((string)($_POST['ReviewNotes']??'')));
        } elseif (!$portalErrors && in_array($action,['edit_lab','edit_prescription'],true)) {
            require_once __DIR__.'/doctor-edits.php';
            $portalErrors=tdc_edit_doctor_order($pdo,$action,$_POST,$visit);
        }
        if (!$portalErrors) {
            header('Location: doctors.php?workspace=1&success=1' . (in_array($action,['edit_lab','edit_prescription','review_result'],true)?'&visit='.(int)$visitId:''));
            exit;
        }
    }
}

$selectedVisit = null;
$prescriptions = [];
$labOrders = [];
$patientHistoryVisits = [];
$patientHistoryLabs = [];
$patientHistoryPrescriptions = [];
$patientHistoryModernResults = [];
$patientHistoryLegacyResults = [];
if ($doctorProfile && ctype_digit((string)($_GET['visit'] ?? ''))) {
    $stmt = $pdo->prepare('SELECT v.*,p.PatientName,p.PatientPhone,p.Gender,p.Age,p.PatientAddress FROM visits v JOIN patients p ON p.PatientID=v.PatientID WHERE v.VisitID=? AND v.DoctorID=?');
    $stmt->execute([(int)$_GET['visit'],$doctorProfile['DoctorID']]);
    $selectedVisit = $stmt->fetch();
    if ($selectedVisit) {
        $stmt=$pdo->prepare('SELECT * FROM prescriptions WHERE VisitID=? ORDER BY PrescriptionDate DESC');$stmt->execute([$selectedVisit['VisitID']]);$prescriptions=$stmt->fetchAll();
        $stmt=$pdo->prepare('SELECT * FROM laboratory WHERE VisitID=? ORDER BY OrderDate DESC');$stmt->execute([$selectedVisit['VisitID']]);$labOrders=$stmt->fetchAll();
        $patientHistory = tdc_doctor_patient_history($pdo, (int) $selectedVisit['PatientID']);
        $patientHistoryVisits = $patientHistory['visits'];
        $patientHistoryLabs = $patientHistory['labs'];
        $patientHistoryPrescriptions = $patientHistory['prescriptions'];
        $patientHistoryModernResults = tdc_doctor_modern_results($pdo, (int) $selectedVisit['PatientID'], (int) $selectedVisit['VisitID']);
        $patientHistoryLegacyResults = tdc_doctor_legacy_results($pdo, (int) $selectedVisit['PatientID'], (int) $selectedVisit['VisitID']);
    }
}
[$waitingFrom, $waitingTo, $waitingDateError] = tdc_date_range_resolve();
$waitingSchedule = ($_GET['tab'] ?? '') === 'schedule';
if ($waitingSchedule && !isset($_GET['from_date']) && !isset($_GET['to_date'])) {
    $waitingFrom = date('Y-m-d');
    $waitingTo = date('Y-m-d', strtotime('+7 days'));
}
$waitingSearch = trim((string) ($_GET['q'] ?? ''));
$waitingStatus = trim((string) ($_GET['status'] ?? ''));
$allowedWaitingStatuses = ['Waiting', 'In Consultation', 'Completed', 'Cancelled'];
if (!in_array($waitingStatus, $allowedWaitingStatuses, true)) $waitingStatus = '';
$waitingPerPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($waitingPerPage, [10,25,50,100], true)) $waitingPerPage = 10;
$waitingPage = max(1, (int) ($_GET['page'] ?? 1));
$queue=[];
$readyResults=[];
$waitingSummary = ['waiting' => 0, 'consultation' => 0, 'completed' => 0, 'due' => 0.0];
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
    if ($waitingSchedule) {
        if ($waitingStatus !== '') { $queueWhere[] = 'v.QueueStatus=?'; $queueParams[] = $waitingStatus; }
    } else {
        // Keep completed visits in Patient Waiting so their clinical record
        // remains available for follow-up orders and review.
        $queueWhere[] = "v.QueueStatus IN ('Waiting','Pending Payment','In Consultation','Completed')";
    }
    $stmt=$pdo->prepare("SELECT v.*,p.PatientName,p.PatientPhone,p.Gender,p.Age,d.DoctorName FROM visits v JOIN patients p ON p.PatientID=v.PatientID JOIN doctors d ON d.DoctorID=v.DoctorID WHERE ".implode(' AND ', $queueWhere)." ORDER BY v.VisitDate DESC,v.VisitID DESC");
    $stmt->execute($queueParams);$queue=$stmt->fetchAll();
    // Expose the existing editable orders on each waiting row so the doctor
    // can correct an order instead of accidentally creating a duplicate.
    foreach ($queue as &$queueVisit) {
        $queueVisit['EditablePrescriptionReference'] = '';
        $rxStmt = $pdo->prepare("SELECT SUBSTRING_INDEX(PrescriptionID,'-',1) AS Reference FROM prescriptions WHERE VisitID=? AND PatientID=? AND DoctorID=? AND Status='Pending' AND DispensedAt IS NULL AND PharmacySaleReference IS NULL GROUP BY SUBSTRING_INDEX(PrescriptionID,'-',1) ORDER BY MIN(PrescriptionDate), MIN(PrescriptionID) LIMIT 1");
        $rxStmt->execute([(int)$queueVisit['VisitID'], (int)$queueVisit['PatientID'], (int)$queueVisit['DoctorID']]);
        $queueVisit['EditablePrescriptionReference'] = (string)($rxStmt->fetchColumn() ?: '');
        $labStmt = $pdo->prepare("SELECT l.LaboratoryID FROM laboratory l WHERE l.VisitID=? AND l.PatientID=? AND l.DoctorID=? AND l.WorkflowStatus IN ('Requested','Awaiting Payment','Ready') AND NOT EXISTS (SELECT 1 FROM lab_order_catalog_bridge b JOIN lab_results r ON r.BridgeID=b.BridgeID WHERE b.LaboratoryID=l.LaboratoryID AND b.IsActive=1) ORDER BY l.OrderDate, l.LaboratoryID LIMIT 1");
        $labStmt->execute([(int)$queueVisit['VisitID'], (int)$queueVisit['PatientID'], (int)$queueVisit['DoctorID']]);
        $queueVisit['EditableLaboratoryID'] = (string)($labStmt->fetchColumn() ?: '');
    }
    unset($queueVisit);
    $summaryWhere = $superadminDoctorMode ? ['1=1'] : ['v.DoctorID=?'];
    $summaryParams = $superadminDoctorMode ? [] : [$doctorProfile['DoctorID']];
    if ($waitingDateError) $summaryWhere[] = '1=0';
    if ($waitingFrom !== '' && tdc_ui_is_date($waitingFrom)) { $summaryWhere[] = 'v.VisitDate>=?'; $summaryParams[] = $waitingFrom.' 00:00:00'; }
    if ($waitingTo !== '' && tdc_ui_is_date($waitingTo)) { $summaryWhere[] = 'v.VisitDate<=?'; $summaryParams[] = $waitingTo.' 23:59:59'; }
    $summaryStmt = $pdo->prepare('SELECT SUM(v.QueueStatus=\'Waiting\') AS waiting_count, SUM(v.QueueStatus=\'In Consultation\') AS consultation_count, SUM(v.QueueStatus=\'Completed\') AS completed_count, COALESCE(SUM(CASE WHEN v.DueBalance > 0 THEN v.DueBalance ELSE 0 END),0) AS due_total FROM visits v WHERE ' . implode(' AND ', $summaryWhere));
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch() ?: [];
    $waitingSummary = ['waiting' => (int) ($summary['waiting_count'] ?? 0), 'consultation' => (int) ($summary['consultation_count'] ?? 0), 'completed' => (int) ($summary['completed_count'] ?? 0), 'due' => (float) ($summary['due_total'] ?? 0)];
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
<div class="clinical-grid" style="margin-top:24px"><form method="post" class="workflow-form" id="prescriptionForm"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="prescribe"><h2>Create Prescription</h2><div id="prescriptionLines"><div class="rx-line"><div class="form-group"><label>Medication</label><select name="MedicationName[]" class="rx-medicine" required><option value="">Select available medicine</option><?php foreach($medicineOptions as $medicine): ?><option value="<?= tdc_e($medicine['ItemName']) ?>" data-stock="<?= (int)$medicine['QuantityInStock'] ?>" data-unit="<?= tdc_e((string)$medicine['SalesUnit']) ?>" data-price="<?= tdc_e((string)$medicine['SellingPrice']) ?>"><?= tdc_e($medicine['ItemName']) ?> (<?= (int)$medicine['QuantityInStock'] ?> available)</option><?php endforeach; ?></select><div class="rx-meta" aria-live="polite"></div></div><div class="form-row"><div class="form-group"><label>Quantity</label><input type="number" min="1" name="Quantity[]" value="1" required></div></div><div class="form-row"><div class="form-group"><label>Frequency</label><input name="Frequency[]"></div><div class="form-group"><label>Duration</label><input name="Duration[]"></div></div><div class="form-group"><label>Route</label><select name="Route[]"><option value="">Select route</option><option value="Oral">Oral</option><option value="Topical">Topical</option><option value="Intravenous (IV)">Intravenous (IV)</option><option value="Intramuscular (IM)">Intramuscular (IM)</option><option value="Subcutaneous (SC)">Subcutaneous (SC)</option><option value="Inhalation">Inhalation</option><option value="Sublingual">Sublingual</option><option value="Rectal">Rectal</option><option value="Ophthalmic">Ophthalmic</option><option value="Otic">Otic</option><option value="Nasal">Nasal</option><option value="Other">Other</option></select></div><div class="form-group"><label>Instructions</label><textarea name="Instructions[]"></textarea></div><button type="button" class="btn-sm danger remove-rx-line" hidden>Remove</button></div></div><div class="row-actions"><button type="button" class="btn btn-secondary" id="addPrescriptionLine">+ Add Item</button><button class="btn btn-primary">Send to Pharmacy</button></div></form>
<form method="post" class="workflow-form">
<input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="request_lab"><h2>Request Lab Test</h2>
<div class="form-group"><label>Available Tests</label><select name="TestID[]" id="labServiceSelect" required multiple size="5" onchange="document.getElementById('labServicePrice').value=Array.from(this.selectedOptions).reduce((total,option)=>total+Number(option.dataset.price||0),0).toFixed(2)"><?php foreach($labServices as $service): ?><option value="<?= (int)$service['TestID'] ?>" data-price="<?= tdc_e((string)($service['Price'] ?? 0)) ?>" title="<?= tdc_e((string)($service['TypeName'] ?? '')) ?>"><?= tdc_e((($service['CategoryName'] ?? '') ? $service['CategoryName'].' · ' : '').($service['TestName'] ?? '')) ?> — <?= number_format((float)($service['Price'] ?? 0),2) ?></option><?php endforeach; ?></select><div class="rx-meta">Only enabled tests remain available for ordering.</div></div>
<div class="form-group"><label>Fee</label><input id="labServicePrice" value="0.00" readonly aria-label="Configured laboratory fee"></div><div class="form-group"><label>Clinical Request</label><textarea name="Description"></textarea></div>
<button class="btn btn-primary" <?= !$labServices?'disabled':'' ?>>Submit Lab Request</button><?php if(!$labServices): ?><div class="rx-meta">A SuperAdmin must configure laboratory categories, types, and test selection before orders can be created. <a href="laboratory.php?workspace=1&lab_tab=test-register">Open the Laboratory workspace</a></div><?php endif; ?></form></div>
<h2 style="margin-top:24px">Visit activity</h2><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Item</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach($prescriptions as $p): ?><tr><td>Prescription</td><td><?= tdc_e($p['MedicationName']) ?></td><td><span class="status-badge"><?= tdc_e($p['Status']) ?></span></td><td><?= tdc_e(date('d M Y',strtotime($p['PrescriptionDate']))) ?></td><td>—</td></tr><?php endforeach;foreach($labOrders as $l): ?><tr><td>Laboratory</td><td><?= tdc_e($l['TestName']) ?><br><span class="cell-sub"><?= tdc_e($l['ClinicalResult'] ?: $l['Description']) ?></span></td><td><span class="status-badge"><?= tdc_e($l['WorkflowStatus']) ?></span></td><td><?= tdc_e(date('d M Y',strtotime($l['OrderDate']))) ?></td><td><?php if($l['WorkflowStatus']==='Completed' && !$l['ReviewedAt']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><input type="hidden" name="portal_action" value="review_result"><button class="btn-sm">Mark Reviewed</button></form><?php elseif($l['ReviewedAt']): ?><span class="status-badge">Reviewed</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach;if(!$prescriptions&&!$labOrders): ?><tr class="empty-row"><td colspan="5">No prescriptions or laboratory requests for this visit.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?><?php endif; ?></section></div><?php endif; ?></main><script>(function(){const box=document.getElementById('prescriptionLines');const add=document.getElementById('addPrescriptionLine');if(!box||!add)return;function wire(line){const select=line.querySelector('.rx-medicine');const qty=line.querySelector('input[name="Quantity[]"]');const meta=line.querySelector('.rx-meta');const remove=line.querySelector('.remove-rx-line');function sync(){const option=select.options[select.selectedIndex];if(!option||!option.value){meta.textContent='';qty.removeAttribute('max');return;}qty.max=option.dataset.stock;meta.textContent=option.dataset.stock+' '+(option.dataset.unit||'units')+' available · '+Number(option.dataset.price||0).toFixed(2)+' each';}select.addEventListener('change',sync);remove.addEventListener('click',function(){if(box.children.length>1){line.remove();syncRemovers();}});sync();}function syncRemovers(){box.querySelectorAll('.remove-rx-line').forEach(function(button){button.hidden=box.children.length===1;});}box.querySelectorAll('.rx-line').forEach(wire);add.addEventListener('click',function(){const line=box.firstElementChild.cloneNode(true);line.querySelectorAll('input,textarea').forEach(function(field){field.value=field.name==='Quantity[]'?'1':'';});line.querySelector('select').selectedIndex=0;box.appendChild(line);wire(line);syncRemovers();});syncRemovers();})();</script></body></html>
