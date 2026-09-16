<?php
declare(strict_types=1);

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
        if (!$order || $order['PaymentStatus'] !== 'Paid') {
            $pdo->rollBack();
            return ['Reception must record full payment before this order can be processed.'];
        }
        if ($order['WorkflowStatus'] !== 'In Progress') {
            $pdo->rollBack();
            return ['Start this paid laboratory order before entering results.'];
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
        if ($order['PaymentStatus'] !== 'Paid') {
            $pdo->rollBack();
            return ['Reception must record full payment before this order can be processed.'];
        }
        if ($order['WorkflowStatus'] !== 'Ready') {
            $pdo->rollBack();
            return ['Only an order that is Ready can be started.'];
        }
        $stmt = $pdo->prepare("UPDATE laboratory SET WorkflowStatus='In Progress' WHERE LaboratoryID=? AND WorkflowStatus='Ready'");
        $stmt->execute([$id]);
        $pdo->commit();
        return [];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[LABORATORY] start failed: '.$exception->getMessage());
        return ['The laboratory order could not be started. Please try again.'];
    }
}
