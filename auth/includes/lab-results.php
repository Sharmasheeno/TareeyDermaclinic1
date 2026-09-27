<?php
declare(strict_types=1);

function tdc_review_lab_results(PDO $pdo, string $labId, array $visit, int $userId, string $notes): array
{
    try {
        if(mb_strlen($notes)>2000) throw new RuntimeException('Review notes are too long.');
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT * FROM laboratory WHERE LaboratoryID=? AND VisitID=? AND PatientID=? AND DoctorID=? FOR UPDATE');
        $s->execute([$labId,$visit['VisitID'],$visit['PatientID'],$visit['DoctorID']]);$order=$s->fetch();
        if(!$order || $order['WorkflowStatus']!=='Completed') throw new RuntimeException('Only a completed result belonging to this visit can be reviewed.');
        $s=$pdo->prepare('SELECT r.* FROM lab_order_catalog_bridge b LEFT JOIN lab_results r ON r.BridgeID=b.BridgeID WHERE b.LaboratoryID=? AND b.IsActive=1 ORDER BY b.BridgeID FOR UPDATE');$s->execute([$labId]);$results=$s->fetchAll();
        foreach($results as $result) {
            if(!$result['LabResultID'] || !in_array($result['ResultStatus'],['Completed','Reviewed'],true)) throw new RuntimeException('Every requested result must be completed before review.');
        }
        foreach($results as $result) {
            $pdo->prepare("INSERT INTO lab_result_review (LabResultID,Action,Notes,PerformedBy,PerformedAt) VALUES (?,'Reviewed',?,?,NOW())")->execute([$result['LabResultID'],$notes?:null,$userId]);
            $pdo->prepare("UPDATE lab_results SET ResultStatus='Reviewed',ReviewedBy=?,ReviewedAt=NOW() WHERE LabResultID=?")->execute([$userId,$result['LabResultID']]);
        }
        // Actual legacy orders retain their existing review marker without invented modern rows.
        $pdo->prepare('UPDATE laboratory SET ReviewedAt=NOW() WHERE LaboratoryID=?')->execute([$labId]);
        $pdo->commit();return [];
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException){error_log('[LAB REVIEW] '.$e->getMessage());return ['Review could not be recorded.'];}
        return [$e->getMessage()];
    }
}

/** One read model for result screen and print, with append-only review history. */
function tdc_lab_result_details(PDO $pdo,string $labId): array
{
    $s=$pdo->prepare('SELECT b.BridgeID,b.ModernTestID,b.ModernTestNameSnapshot,b.DisplayOrder,mt.ResultMode,lt.TypeName,lc.CategoryName,r.*,cu.userlegalname AS CompletedByName FROM lab_order_catalog_bridge b LEFT JOIN lab_tests mt ON mt.TestID=b.ModernTestID LEFT JOIN lab_types lt ON lt.TypeID=mt.TypeID LEFT JOIN lab_categories lc ON lc.CategoryID=lt.CategoryID LEFT JOIN lab_results r ON r.BridgeID=b.BridgeID LEFT JOIN users cu ON cu.id=r.CompletedBy WHERE b.LaboratoryID=? AND b.IsActive=1 ORDER BY b.DisplayOrder,b.BridgeID');
    $s->execute([$labId]);$results=$s->fetchAll();
    foreach($results as &$result){
        if (($result['ResultMode'] ?? '') === 'Structured Parameters') {
            $s=$pdo->prepare('SELECT lp.ParameterID,lp.ParameterName,COALESCE(rp.ParameterNameSnapshot,lp.ParameterName) AS ParameterNameSnapshot,lp.ResultType,lp.IsRequired,lp.UnitID,lu.UnitName,COALESCE(rp.UnitNameSnapshot,lu.UnitName) AS UnitNameSnapshot,lp.ReferenceRange,COALESCE(rp.ReferenceRangeSnapshot,lp.ReferenceRange) AS ReferenceRangeSnapshot,lp.NormalMinimum,lp.NormalMaximum,lp.SelectChoices,rp.NormalMinimumSnapshot,rp.NormalMaximumSnapshot,rp.LabResultParameterID,rp.RawResult,rp.DisplayResult,rp.Remark,rp.FlagCodeSnapshot,rp.FlagNameSnapshot FROM lab_parameters lp LEFT JOIN lab_units lu ON lu.UnitID=lp.UnitID LEFT JOIN lab_result_parameters rp ON rp.LabResultID=? AND rp.ParameterID=lp.ParameterID WHERE lp.TestID=? AND lp.IsActive=1 ORDER BY lp.DisplayOrder,lp.ParameterID');
            $s->execute([(int)($result['LabResultID'] ?? 0),(int)$result['ModernTestID']]);
        } else {
            $s=$pdo->prepare('SELECT * FROM lab_result_parameters WHERE LabResultID=? ORDER BY LabResultParameterID');$s->execute([(int)($result['LabResultID'] ?? 0)]);
        }
        $result['parameters']=$s->fetchAll();
        foreach($result['parameters'] as &$parameter) {
            if(trim((string)$parameter['ReferenceRangeSnapshot'])==='') {
                $min=$parameter['NormalMinimumSnapshot'] ?? $parameter['NormalMinimum'] ?? null;$max=$parameter['NormalMaximumSnapshot'] ?? $parameter['NormalMaximum'] ?? null;
                $parameter['ReferenceRangeSnapshot']=$min!==null&&$max!==null?$min.' – '.$max:($min!==null?'≥ '.$min:($max!==null?'≤ '.$max:''));
            }
        }unset($parameter);
        $s=$pdo->prepare('SELECT rv.*,u.userlegalname AS ReviewerName FROM lab_result_review rv LEFT JOIN users u ON u.id=rv.PerformedBy WHERE rv.LabResultID=? ORDER BY rv.PerformedAt,rv.ReviewID');$s->execute([(int)($result['LabResultID'] ?? 0)]);$result['reviews']=$s->fetchAll();
    }unset($result);
    return $results;
}

/** Save one modern order atomically; clinical state never changes its payment state. */
function tdc_save_modern_lab_result(PDO $pdo, string $labId, string $action, array $input, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM laboratory WHERE LaboratoryID=? FOR UPDATE');
        $stmt->execute([$labId]);
        $order = $stmt->fetch();
        if (!$order || in_array($order['WorkflowStatus'], ['Cancelled', 'Completed'], true)) {
            throw new RuntimeException('Select an active laboratory order.');
        }
        $stmt = $pdo->prepare('SELECT b.*,mt.ResultMode FROM lab_order_catalog_bridge b LEFT JOIN lab_tests mt ON mt.TestID=b.ModernTestID WHERE b.LaboratoryID=? AND b.IsActive=1 ORDER BY b.BridgeID FOR UPDATE');
        $stmt->execute([$labId]);
        $bridges = $stmt->fetchAll();
        if (!$bridges) throw new RuntimeException('This order has no modern tests. Use the modern result form.');
        if ($action === 'collect_sample') {
            $pdo->prepare("UPDATE laboratory SET WorkflowStatus='In Progress' WHERE LaboratoryID=? AND WorkflowStatus IN ('Requested','Awaiting Payment','Ready')")->execute([$labId]);
        } elseif (!in_array($order['WorkflowStatus'], ['In Progress', 'Draft'], true)) {
            throw new RuntimeException('Collect the sample before entering results.');
        }
        $parameters = [];
        $singleResults = [];
        $resultIds = [];
        foreach ($bridges as $bridge) {
            $stmt = $pdo->prepare('SELECT * FROM lab_results WHERE BridgeID=? FOR UPDATE');
            $stmt->execute([$bridge['BridgeID']]);
            $result = $stmt->fetch();
            if ($result && in_array($result['ResultStatus'], ['Completed','Reviewed','Cancelled'], true)) {
                throw new RuntimeException('A finalized result cannot be changed.');
            }
            if (!$result) {
                $pdo->prepare("INSERT INTO lab_results (BridgeID,ResultStatus) VALUES (?,'Draft')")->execute([$bridge['BridgeID']]);
                $resultId = (int) $pdo->lastInsertId();
            } else $resultId = (int) $result['LabResultID'];
            $resultIds[] = $resultId;
            if ($action === 'collect_sample') {
                $pdo->prepare("UPDATE lab_results SET ResultStatus=IF(CollectedAt IS NULL,'Processing',ResultStatus),CollectedBy=COALESCE(CollectedBy,?),CollectedAt=COALESCE(CollectedAt,NOW()) WHERE LabResultID=?")->execute([$userId,$resultId]);
            }
            $stmt = $pdo->prepare('SELECT p.*,u.UnitName,rp.RawResult FROM lab_parameters p LEFT JOIN lab_units u ON u.UnitID=p.UnitID LEFT JOIN lab_result_parameters rp ON rp.ParameterID=p.ParameterID AND rp.LabResultID=? WHERE p.TestID=? AND p.IsActive=1 ORDER BY p.DisplayOrder,p.ParameterID');
            $stmt->execute([$resultId,$bridge['ModernTestID']]);
            $rows = $stmt->fetchAll();
            if (!$rows && (($bridge['ResultMode'] ?? null) === 'Structured Parameters')) {
                if ($action === 'complete_lab_result') throw new RuntimeException($bridge['ModernTestNameSnapshot'].': configure at least one result parameter before completion.');
            } elseif (!$rows) {
                $single = $pdo->prepare('SELECT * FROM lab_result_parameters WHERE LabResultID=? AND ParameterID IS NULL ORDER BY LabResultParameterID LIMIT 1');
                $single->execute([$resultId]);
                $singleResults[(int)$bridge['BridgeID']]=['LabResultID'=>$resultId,'BridgeID'=>(int)$bridge['BridgeID'],'ModernTestNameSnapshot'=>$bridge['ModernTestNameSnapshot'],'entry'=>$single->fetch() ?: null];
            } else {
                foreach ($rows as $row) $parameters[(int)$row['ParameterID']] = $row + ['LabResultID'=>$resultId];
            }
        }
        if ($action !== 'collect_sample') {
            $ids = (array)($input['ParameterID'] ?? []);
            if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('Duplicate result parameter.');
            foreach ($ids as $index => $id) {
                $id = (int)$id;
                if (!isset($parameters[$id])) throw new RuntimeException('A parameter does not belong to this laboratory order.');
                $parameter = $parameters[$id];
                $value = trim((string)($input['ResultValue'][$index] ?? ''));
                $remark = trim((string)($input['Remark'][$index] ?? ''));
                if (mb_strlen($value)>2000 || mb_strlen($remark)>2000) throw new RuntimeException('Result or remark is too long.');
                $type = $parameter['ResultType'];
                if ($value !== '' && $type === 'Numeric' && (!is_numeric($value) || !is_finite((float)$value))) throw new RuntimeException('Enter a valid numeric result.');
                if ($value !== '' && $type === 'Positive/Negative' && !in_array($value,['Positive','Negative'],true)) throw new RuntimeException('Select Positive or Negative.');
                if ($value !== '' && $type === 'Select') {
                    $choices = json_decode((string)$parameter['SelectChoices'],true);
                    if (!is_array($choices)) $choices = preg_split('/[\r\n,]+/',(string)$parameter['SelectChoices']);
                    if (!in_array($value,array_map('trim',$choices),true)) throw new RuntimeException('Select a configured result choice.');
                }
                $flag = null;
                if ($value !== '' && $type === 'Numeric') {
                    $code = $parameter['NormalMinimum'] !== null && (float)$value < (float)$parameter['NormalMinimum'] ? 'L' : ($parameter['NormalMaximum'] !== null && (float)$value > (float)$parameter['NormalMaximum'] ? 'H' : 'N');
                    $stmt=$pdo->prepare('SELECT * FROM lab_flags WHERE FlagCode=? AND IsActive=1 LIMIT 1');$stmt->execute([$code]);$flag=$stmt->fetch() ?: ['FlagCode'=>$code,'FlagName'=>$code];
                }
                $pdo->prepare('INSERT INTO lab_result_parameters (LabResultID,ParameterID,ParameterNameSnapshot,ResultTypeSnapshot,UnitID,UnitNameSnapshot,ReferenceRangeSnapshot,NormalMinimumSnapshot,NormalMaximumSnapshot,RawResult,DisplayResult,FlagID,FlagCodeSnapshot,FlagNameSnapshot,Remark) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE RawResult=VALUES(RawResult),DisplayResult=VALUES(DisplayResult),FlagID=VALUES(FlagID),FlagCodeSnapshot=VALUES(FlagCodeSnapshot),FlagNameSnapshot=VALUES(FlagNameSnapshot),Remark=VALUES(Remark)')->execute([$parameter['LabResultID'],$id,$parameter['ParameterName'],$type,$parameter['UnitID'],$parameter['UnitName'],$parameter['ReferenceRange'],$parameter['NormalMinimum'],$parameter['NormalMaximum'],$value,$value,$flag['FlagID']??null,$flag['FlagCode']??null,$flag['FlagName']??null,$remark?:null]);
                $parameters[$id]['RawResult']=$value;
            }
            foreach ($singleResults as $bridgeId => $single) {
                $resultValue = trim((string)(($input['SingleResult'][$single['LabResultID']] ?? $input['SingleResult'][$bridgeId] ?? '')));
                $remark = trim((string)(($input['SingleRemark'][$single['LabResultID']] ?? $input['SingleRemark'][$bridgeId] ?? '')));
                if (mb_strlen($resultValue)>2000 || mb_strlen($remark)>500) throw new RuntimeException('Result or remark is too long.');
                if ($action === 'complete_lab_result' && $resultValue === '') throw new RuntimeException($single['ModernTestNameSnapshot'].': enter a result before completing this laboratory order.');
                $existingId = (int)($single['entry']['LabResultParameterID'] ?? 0);
                if ($existingId > 0) {
                    $pdo->prepare('UPDATE lab_result_parameters SET RawResult=?,DisplayResult=?,Remark=? WHERE LabResultParameterID=?')->execute([$resultValue,$resultValue,$remark?:null,$existingId]);
                } else {
                    $pdo->prepare('INSERT INTO lab_result_parameters (LabResultID,ParameterID,ParameterNameSnapshot,ResultTypeSnapshot,RawResult,DisplayResult,Remark) VALUES (?,NULL,?,?, ?,?,?)')->execute([$single['LabResultID'],'Result','Single Result',$resultValue,$resultValue,$remark?:null]);
                }
            }
            if ($action === 'complete_lab_result') {
                foreach ($parameters as $parameter) if ($parameter['IsRequired'] && trim((string)$parameter['RawResult']) === '') throw new RuntimeException('All required parameters must be entered before completion.');
            }
            foreach ($resultIds as $resultId) {
                if ($action === 'complete_lab_result') $pdo->prepare("UPDATE lab_results SET ResultStatus='Completed',CompletedBy=?,CompletedAt=NOW() WHERE LabResultID=?")->execute([$userId,$resultId]);
                else $pdo->prepare("UPDATE lab_results SET ResultStatus='Draft' WHERE LabResultID=?")->execute([$resultId]);
            }
        }
        $nextStatus = match ($action) {
            'collect_sample' => 'In Progress',
            'save_lab_result_draft' => 'In Progress',
            'complete_lab_result' => 'Completed',
            default => 'In Progress',
        };
        $pdo->prepare('UPDATE laboratory SET WorkflowStatus=?,ResultDate=IF(?=1,NOW(),ResultDate) WHERE LaboratoryID=?')->execute([$nextStatus, $action === 'complete_lab_result' ? 1 : 0, $labId]);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException) { error_log('[MODERN LAB] '.$e->getMessage()); return ['The laboratory result could not be saved.']; }
        return [$e->getMessage()];
    }
}

function tdc_lab_upsert_result_entry(PDO $pdo, string $labId, int $patientId = 0, int $visitId = 0, int $doctorId = 0, int $labCenterId = 0, ?int $bridgeId = null): int
{
    $bridgeId = (int) ($bridgeId ?? 0);
    if ($bridgeId <= 0 && trim($labId) !== '') {
        $stmt = $pdo->prepare(
            'SELECT BridgeID FROM lab_order_catalog_bridge WHERE LaboratoryID = ? AND IsActive = 1 ORDER BY DisplayOrder, BridgeID LIMIT 1'
        );
        $stmt->execute([$labId]);
        $bridgeId = (int) $stmt->fetchColumn();
    }

    if ($bridgeId <= 0) {
        throw new RuntimeException('No active bridge exists for the laboratory order.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT LabResultID FROM lab_results WHERE BridgeID = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$bridgeId]);
        $labResultId = (int) $stmt->fetchColumn();
        if ($labResultId > 0) {
            $pdo->commit();
            return $labResultId;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO lab_results (BridgeID, LabCenterID, ResultStatus, CreatedAt, UpdatedAt) '
            . 'VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$bridgeId, $labCenterId > 0 ? $labCenterId : null, 'Draft']);
        $labResultId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $labResultId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function tdc_record_lab_result(PDO $pdo, array $input): array
{
    $id = trim((string) ($input['LaboratoryID'] ?? ''));
    $itemIds = array_values(array_map('strval', (array) ($input['LabOrderItemID'] ?? [])));
    $itemResults = array_values(array_map('strval', (array) ($input['ItemResult'] ?? [])));
    $itemNotes = array_values(array_map(static fn($value): string => trim((string) $value), (array) ($input['ItemClinicalResult'] ?? [])));
    $result = (string) ($input['Result'] ?? 'Pending');
    $date = trim((string) ($input['ResultDate'] ?? ''));
    $description = trim((string) ($input['Description'] ?? ''));
    if ($id === '') {
        return ['Select an existing paid laboratory order.'];
    }
    if ($itemIds === [] && !in_array($result, ['Pending', 'Positive', 'Negative'], true)) {
        return ['Select an existing paid laboratory order and a valid result.'];
    }
    if (mb_strlen($description) > 2000) {
        return ['Description is too long (max 2000 characters).'];
    }
    if ($date !== '' && !tdc_is_valid_datetime_local($date)) {
        return ['Result date is not a valid date/time.'];
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT PaymentStatus,WorkflowStatus,DoctorID,VisitID,PatientID FROM laboratory WHERE LaboratoryID = ? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            return ['Laboratory order not found.'];
        }
        if ($order['WorkflowStatus'] !== 'In Progress') {
            $pdo->rollBack();
            return ['Start this laboratory order before entering results.'];
        }
        $itemsStmt = $pdo->prepare('SELECT LabOrderItemID,TestName FROM laborderitems WHERE LaboratoryID=? ORDER BY LabOrderItemID FOR UPDATE');
        $itemsStmt->execute([$id]);
        $storedItems = $itemsStmt->fetchAll();
        if ($storedItems !== []) {
            if (count($itemIds) !== count($storedItems) || count($itemResults) !== count($storedItems) || count($itemNotes) !== count($storedItems)) {
                $pdo->rollBack();
                return ['Enter a result for every requested laboratory test.'];
            }
            $storedById = [];
            foreach ($storedItems as $item) $storedById[(string) $item['LabOrderItemID']] = $item;
            $completed = true;
            $hasPositive = false;
            $summary = [];
            $updateItem = $pdo->prepare('UPDATE laborderitems SET Result=?,ClinicalResult=?,ResultDate=? WHERE LabOrderItemID=? AND LaboratoryID=?');
            foreach ($itemIds as $index => $itemId) {
                $itemResult = $itemResults[$index] ?? '';
                $note = $itemNotes[$index] ?? '';
                if (!isset($storedById[$itemId]) || !in_array($itemResult, ['Pending', 'Positive', 'Negative'], true)) {
                    $pdo->rollBack();
                    return ['A requested test or its result is invalid.'];
                }
                if (mb_strlen($note) > 2000) {
                    $pdo->rollBack();
                    return ['A clinical result is too long (max 2000 characters).'];
                }
                if ($itemResult === 'Pending') $completed = false;
                if ($itemResult === 'Positive') $hasPositive = true;
                if ($note !== '') $summary[] = $storedById[$itemId]['TestName'] . ': ' . $note;
                $updateItem->execute([$itemResult, $note !== '' ? $note : null, $itemResult === 'Pending' ? null : date('Y-m-d H:i:s'), (int) $itemId, $id]);
            }
            $workflow = $completed ? 'Completed' : 'In Progress';
            $result = $completed ? ($hasPositive ? 'Positive' : 'Negative') : 'Pending';
            $description = implode("\n", $summary);
            $date = $completed ? date('Y-m-d H:i:s') : '';
        } else {
            $workflow = $result === 'Pending' ? 'In Progress' : 'Completed';
        }
        $stmt = $pdo->prepare('UPDATE laboratory SET Result = ?, ResultDate = ?, ClinicalResult = ?, IsAvailable = ?, WorkflowStatus = ? WHERE LaboratoryID = ?');
        $stmt->execute([
            $result,
            $result === 'Pending' ? null : ($date !== '' ? str_replace('T', ' ', $date) : date('Y-m-d H:i:s')),
            $description !== '' ? $description : null,
            ($input['IsAvailable'] ?? '') === '1' ? 1 : 0,
            $workflow,
            $id,
        ]);
        if ($workflow === 'Completed') {
            $stmt = $pdo->prepare('SELECT UserID FROM doctors WHERE DoctorID=?');
            $stmt->execute([(int)$order['DoctorID']]);
            tdc_workflow_notify($pdo,(int)$stmt->fetchColumn(),'doctoruser','lab_result_ready','Laboratory result ready',$id.' has a completed result','doctors.php?visit='.(int)$order['VisitID']);
        }
        $pdo->commit();
        return [];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[LABORATORY] result save failed: ' . $exception->getMessage());
        return ['The result could not be saved. Please try again.'];
    }
}

function tdc_start_lab_order(PDO $pdo, string $id): array
{
    if ($id === '') return ['Select a laboratory order to start.'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT PaymentStatus,WorkflowStatus FROM laboratory WHERE LaboratoryID=? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            $pdo->rollBack();
            return ['Laboratory order not found.'];
        }
        if (!in_array($order['WorkflowStatus'], ['Requested', 'Awaiting Payment', 'Ready'], true)) {
            $pdo->rollBack();
            return ['Only an order that is Ready can be started.'];
        }
        $stmt = $pdo->prepare("UPDATE laboratory SET WorkflowStatus='In Progress' WHERE LaboratoryID=? AND WorkflowStatus IN ('Requested','Awaiting Payment','Ready')");
        $stmt->execute([$id]);
        $pdo->commit();
        return [];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[LABORATORY] start failed: '.$exception->getMessage());
        return ['The laboratory order could not be started. Please try again.'];
    }
}
