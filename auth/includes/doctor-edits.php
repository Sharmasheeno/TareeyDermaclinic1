<?php
declare(strict_types=1);

/** Structural edits share the same source-row locks as processing/dispensing/payment. */
function tdc_edit_doctor_order(PDO $pdo, string $action, array $input, array $visit): array
{
    try {
        if ((int)($input['PatientID']??0)!==(int)$visit['PatientID'] || (int)($input['DoctorID']??0)!==(int)$visit['DoctorID']) {
            throw new RuntimeException('The patient, visit and doctor context is required for editing.');
        }
        $pdo->beginTransaction();
        if ($action === 'edit_lab') {
            $id=trim((string)($input['LaboratoryID']??''));
            $stmt=$pdo->prepare('SELECT * FROM laboratory WHERE LaboratoryID=? AND VisitID=? AND PatientID=? AND DoctorID=? FOR UPDATE');
            $stmt->execute([$id,$visit['VisitID'],$visit['PatientID'],$visit['DoctorID']]);$order=$stmt->fetch();
            if (!$order) throw new RuntimeException('This laboratory request does not belong to the selected patient and visit.');
            $guard=tdc_doctor_lab_edit_guard($pdo,$id);
            if (!$guard['editable']) throw new RuntimeException($guard['message']);
            $stmt=$pdo->prepare('SELECT b.BridgeID,r.LabResultID FROM lab_order_catalog_bridge b LEFT JOIN lab_results r ON r.BridgeID=b.BridgeID WHERE b.LaboratoryID=? FOR UPDATE');
            $stmt->execute([$id]);
            // Even a draft is material once it contains a result. Do not orphan prior result data.
            foreach($stmt->fetchAll() as $bridge) if($bridge['LabResultID']) throw new RuntimeException('Result processing has already started; this request cannot be structurally edited.');
            $ids=array_values(array_unique(array_map('intval',(array)($input['SelectedModernTestID']??[]))));
            if (!$ids || min($ids)<1) throw new RuntimeException('Select at least one active lab test.');
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$pdo->prepare("SELECT t.TestID,t.TestName,t.Price FROM lab_tests t JOIN lab_types ty ON ty.TypeID=t.TypeID JOIN lab_categories c ON c.CategoryID=ty.CategoryID WHERE t.TestID IN ($marks) AND t.IsActive=1 AND ty.IsActive=1 AND c.IsActive=1 AND NOT EXISTS (SELECT 1 FROM lab_test_selection s WHERE s.TestID=t.TestID AND s.DoctorID=? AND s.IsEnabled=0) ORDER BY t.TestID");
            $stmt->execute(array_merge($ids,[$visit['DoctorID']]));$tests=$stmt->fetchAll();
            if(count($tests)!==count($ids)) throw new RuntimeException('One or more tests are not enabled for this doctor.');
            $total=round(array_sum(array_column($tests,'Price')),2);
            $paid=max((float)$order['AmountPaid'],tdc_payments_confirmed_total($pdo,'LaboratoryID',$id));
            if($paid>$total) throw new RuntimeException('The edited laboratory total cannot be lower than the amount already paid. Reverse the excess payment first.');
            $note=trim((string)($input['Description']??''));
            if(mb_strlen($note)>2000) throw new RuntimeException('Clinical request is too long (max 2000 characters).');
            $pdo->prepare("UPDATE lab_order_catalog_bridge SET IsActive=0 WHERE LaboratoryID=? AND ModernTestID NOT IN ($marks)")->execute(array_merge([$id],$ids));
            $upsert=$pdo->prepare("INSERT INTO lab_order_catalog_bridge (LaboratoryID,ModernTestID,ModernTestNameSnapshot,PriceSnapshot,SourceType,DisplayOrder,IsActive) VALUES (?,?,?,?,'modern',?,1) ON DUPLICATE KEY UPDATE ModernTestNameSnapshot=VALUES(ModernTestNameSnapshot),PriceSnapshot=VALUES(PriceSnapshot),DisplayOrder=VALUES(DisplayOrder),IsActive=1");
            foreach($tests as $index=>$test) $upsert->execute([$id,$test['TestID'],$test['TestName'],$test['Price'],$index+1]);
            $pdo->prepare('UPDATE laboratory SET TestName=?,Description=?,TotalAmount=?,AmountPaid=?,DueBalance=?,PaymentStatus=? WHERE LaboratoryID=?')->execute([implode(', ',array_column($tests,'TestName')),$note,$total,$paid,round($total-$paid,2),tdc_workflow_payment_status($total,$paid),$id]);
        } else {
            $ref=trim((string)($input['PrescriptionReference']??''));
            if(!preg_match('/^[A-Za-z0-9]+$/',$ref)) throw new RuntimeException('Invalid prescription reference.');
            $stmt=$pdo->prepare('SELECT * FROM prescriptions WHERE PrescriptionID LIKE ? ORDER BY PrescriptionID FOR UPDATE');
            $stmt->execute([$ref.'-%']);$rows=$stmt->fetchAll();
            if(!$rows) throw new RuntimeException('Prescription not found.');
            foreach($rows as $row) {
                if((int)$row['PatientID']!==(int)$visit['PatientID'] || (int)$row['VisitID']!==(int)$visit['VisitID'] || (int)$row['DoctorID']!==(int)$visit['DoctorID']) throw new RuntimeException('This prescription does not belong to the selected patient and visit.');
                if($row['Status']!=='Pending' || $row['DispensedAt']!==null || $row['PharmacySaleReference']!==null) throw new RuntimeException('This prescription has already been dispensed or cancelled and cannot be edited.');
            }
            $stored=array_column($rows,null,'PrescriptionID');$used=[];$items=[];$total=0.0;
            $next=1+max(array_map(static fn($row)=>(int)substr($row['PrescriptionID'],strrpos($row['PrescriptionID'],'-')+1),$rows));
            foreach((array)($input['MedicationName']??[]) as $index=>$name) {
                $name=trim((string)$name);if($name==='') continue;
                $qty=(string)($input['Quantity'][$index]??'');
                if(!ctype_digit($qty) || (int)$qty<1) throw new RuntimeException('Quantity must be a positive whole number.');
                $stmt=$pdo->prepare('SELECT ItemName,SellingPrice FROM inventory WHERE LOWER(TRIM(ItemName))=LOWER(TRIM(?)) FOR UPDATE');$stmt->execute([$name]);$matches=$stmt->fetchAll();
                if(count($matches)!==1) throw new RuntimeException('Medication must match exactly one inventory item.');
                $item=$matches[0];$id=trim((string)($input['ExistingPrescriptionID'][$index]??''));
                if($id!=='' && (!isset($stored[$id]) || isset($used[$id]))) throw new RuntimeException('Invalid or duplicate prescription item identity.');
                if($id==='') $id=$ref.'-'.str_pad((string)$next++,2,'0',STR_PAD_LEFT);
                $used[$id]=true;
                $details=[];
                foreach(['Route','Frequency','Duration','Instructions'] as $field) {
                    $value=trim((string)($input[$field][$index]??''));
                    if(mb_strlen($value)>($field==='Instructions'?2000:($field==='Route'?50:100))) throw new RuntimeException('Prescription instructions or schedule are too long.');
                    $details[]=$value;
                }
                $total+=round((float)$item['SellingPrice']*(int)$qty,2);
                $items[]=[$id,$item['ItemName'],(int)$qty,$details,(float)$item['SellingPrice']];
            }
            if(!$items) throw new RuntimeException('Keep at least one medication on the prescription.');
            $total=round($total,2);$paid=max((float)$rows[0]['AmountPaid'],tdc_payments_confirmed_total($pdo,'PrescriptionReference',$ref));
            if($paid>$total) throw new RuntimeException('The edited prescription total cannot be lower than the amount already paid. Reverse the excess payment first.');
            foreach($items as [$id,$name,$qty,$details,$unitPrice]) {
                if(isset($stored[$id])) $pdo->prepare('UPDATE prescriptions SET MedicationName=?,Quantity=?,Route=?,Frequency=?,Duration=?,Instructions=? WHERE PrescriptionID=?')->execute(array_merge([$name,$qty],$details,[$id]));
                else {
                    $head=$rows[0];
                    $pdo->prepare("INSERT INTO prescriptions (PrescriptionID,PatientID,VisitID,DoctorID,PatientName,PatientPhone,PatientAddress,Gender,Age,VisitNumber,MedicationName,Quantity,Route,Frequency,Duration,Instructions,Status,PrescriptionDate) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',?)")->execute(array_merge([$id,$head['PatientID'],$head['VisitID'],$head['DoctorID'],$head['PatientName'],$head['PatientPhone'],$head['PatientAddress'],$head['Gender'],$head['Age'],$head['VisitNumber'],$name,$qty],$details,[$head['PrescriptionDate']]));
                }
                if(tdc_has_column($pdo,'prescriptions','UnitPriceSnapshot')) $pdo->prepare('UPDATE prescriptions SET UnitPriceSnapshot=? WHERE PrescriptionID=?')->execute([$unitPrice,$id]);
            }
            $marks=implode(',',array_fill(0,count($used),'?'));
            $pdo->prepare("DELETE FROM prescriptions WHERE PrescriptionID LIKE ? AND PrescriptionID NOT IN ($marks)")->execute(array_merge([$ref.'-%'],array_keys($used)));
            $pdo->prepare('UPDATE prescriptions SET TotalAmount=?,AmountPaid=?,DueBalance=? WHERE PrescriptionID LIKE ?')->execute([$total,$paid,round($total-$paid,2),$ref.'-%']);
        }
        tdc_reconcile_patient_due_balance($pdo,(int)$visit['PatientID']);
        $pdo->commit();return [];
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        if($e instanceof PDOException) {error_log('[DOCTOR EDIT] '.$e->getMessage());return ['The edit could not be saved. Please retry.'];}
        return [$e->getMessage()];
    }
}
