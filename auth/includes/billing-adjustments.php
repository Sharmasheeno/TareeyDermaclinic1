<?php
declare(strict_types=1);

/**
 * Bill-level patient adjustments.  Existing bill columns remain the final
 * amount, so old payment and clinical workflows continue to work unchanged.
 */
function tdc_money(float $value): float { return round($value + 0.00000001, 2); }

function tdc_calculate_bill_adjustment(float $gross, string $discountType = 'None', float $discountValue = 0.0, float $taxRate = 0.0): array {
    $gross = tdc_money($gross);
    $discountValue = tdc_money($discountValue);
    $taxRate = tdc_money($taxRate);
    if (!is_finite($gross) || $gross < 0) throw new InvalidArgumentException('Gross amount cannot be negative.');
    if (!in_array($discountType, ['None', 'Fixed', 'Percentage'], true)) throw new InvalidArgumentException('Invalid discount type.');
    if ($discountValue < 0) throw new InvalidArgumentException('Discount cannot be negative.');
    if ($taxRate < 0 || $taxRate > 100) throw new InvalidArgumentException('Tax rate must be between 0 and 100 percent.');
    if ($discountType === 'None') $discountValue = 0.0;
    if ($discountType === 'Percentage' && $discountValue > 100) throw new InvalidArgumentException('Percentage discount cannot exceed 100 percent.');
    $discountAmount = $discountType === 'Percentage' ? tdc_money($gross * $discountValue / 100) : tdc_money($discountValue);
    if ($discountAmount > $gross) throw new InvalidArgumentException('Discount cannot exceed the gross amount.');
    $subtotal = tdc_money(max(0, $gross - $discountAmount));
    $taxAmount = tdc_money($subtotal * $taxRate / 100);
    return ['gross_amount'=>$gross, 'discount_type'=>$discountType, 'discount_value'=>$discountValue,
        'discount_amount'=>$discountAmount, 'subtotal'=>$subtotal, 'tax_rate'=>$taxRate,
        'tax_amount'=>$taxAmount, 'final_amount'=>tdc_money($subtotal + $taxAmount)];
}

function tdc_adjustment_table_available(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;
    try { $pdo->query('SELECT 1 FROM patient_bill_adjustments LIMIT 1'); return $available = true; }
    catch (Throwable $e) { return $available = false; }
}

function tdc_load_bill_adjustment(PDO $pdo, string $billType, string $reference): ?array {
    if (!tdc_adjustment_table_available($pdo) || $reference === '') return null;
    $q = $pdo->prepare('SELECT * FROM patient_bill_adjustments WHERE BillType=? AND BillReference=? LIMIT 1');
    $q->execute([$billType, $reference]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tdc_save_bill_adjustment(PDO $pdo, string $billType, string $reference, float $gross, string $discountType, float $discountValue, float $taxRate, float $paid = 0.0, string $reason = '', string $note = ''): array {
    $calc = tdc_calculate_bill_adjustment($gross, $discountType, $discountValue, $taxRate);
    if ($calc['discount_amount'] > 0 && trim($reason) === '') throw new InvalidArgumentException('A discount reason is required.');
    if ($calc['final_amount'] + 0.001 < tdc_money($paid)) throw new InvalidArgumentException('Discount cannot reduce the bill below the amount already paid.');
    if (!tdc_adjustment_table_available($pdo)) return $calc;
    $sql = 'INSERT INTO patient_bill_adjustments (BillType,BillReference,GrossAmount,DiscountType,DiscountValue,DiscountAmount,DiscountReason,AdjustmentNote,TaxRate,TaxAmount,FinalAmount,AdjustedBy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE GrossAmount=VALUES(GrossAmount),DiscountType=VALUES(DiscountType),DiscountValue=VALUES(DiscountValue),DiscountAmount=VALUES(DiscountAmount),DiscountReason=VALUES(DiscountReason),AdjustmentNote=VALUES(AdjustmentNote),TaxRate=VALUES(TaxRate),TaxAmount=VALUES(TaxAmount),FinalAmount=VALUES(FinalAmount),AdjustedBy=VALUES(AdjustedBy),AdjustedAt=CURRENT_TIMESTAMP';
    $user = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $pdo->prepare($sql)->execute([$billType,$reference,$calc['gross_amount'],$calc['discount_type'],$calc['discount_value'],$calc['discount_amount'],trim($reason) ?: null,trim($note) ?: null,$calc['tax_rate'],$calc['tax_amount'],$calc['final_amount'],$user]);
    return $calc;
}

/**
 * Atomically save a bill adjustment and, when requested, one additive payment.
 * The source row is re-read under lock and the existing payment synchroniser
 * remains authoritative for Paid/Due/status and patient-balance reconciliation.
 */
function tdc_unified_bill_payment(PDO $pdo, string $billType, string $reference, array $input, int $receivedBy, array $paymentMethods): void {
    $map = [
        'consultation' => ['table'=>'visits','id'=>'VisitID','link'=>'VisitID','paymentType'=>'Consultation','adjustment'=>'consultation','account'=>'REV-CONSULT','accountName'=>'Consultation Revenue'],
        'laboratory' => ['table'=>'laboratory','id'=>'LaboratoryID','link'=>'LaboratoryID','paymentType'=>'Laboratory','adjustment'=>'laboratory','account'=>'REV-LAB','accountName'=>'Laboratory Revenue'],
        'prescription' => ['table'=>'prescriptions','id'=>'PrescriptionReference','link'=>'PrescriptionReference','paymentType'=>'Pharmacy','adjustment'=>'prescription','account'=>'REV-PHARMACY','accountName'=>'Pharmacy Revenue'],
        'service' => ['table'=>'service_assignments','id'=>'ServiceAssignmentID','link'=>'ServiceAssignmentID','paymentType'=>'Service','adjustment'=>'Service','account'=>'REV-SERVICE','accountName'=>'Service Revenue'],
    ];
    if (!isset($map[$billType]) || trim($reference) === '') throw new RuntimeException('Select a valid bill.');
    $m = $map[$billType];
    $pdo->beginTransaction();
    try {
        if ($billType === 'prescription') {
            $q=$pdo->prepare('SELECT PatientID,TotalAmount FROM prescriptions WHERE PrescriptionID LIKE ? ORDER BY PrescriptionID LIMIT 1 FOR UPDATE'); $q->execute([$reference.'-%']); $bill=$q->fetch();
            if (!$bill) throw new RuntimeException('Pharmacy bill not found.');
            $patientId=(int)$bill['PatientID']; $paid=tdc_payments_confirmed_total($pdo,'PrescriptionReference',$reference); $existing=tdc_load_bill_adjustment($pdo,'prescription',$reference); $gross=(float)($existing['GrossAmount']??$bill['TotalAmount']);
        } elseif ($billType === 'service') {
            $serviceAssignmentId = (int) $reference;
            $q=$pdo->prepare('SELECT a.AssignmentID,a.ServiceReference,a.PatientID,s.DefaultAmount FROM service_assignments a JOIN service_subservices s ON s.ServiceID=a.ServiceID WHERE a.AssignmentID=? FOR UPDATE'); $q->execute([$serviceAssignmentId]); $bill=$q->fetch();
            if (!$bill) throw new RuntimeException('Service bill not found.');
            $patientId=(int)$bill['PatientID']; $paid=tdc_payments_confirmed_total($pdo,'ServiceAssignmentID',$serviceAssignmentId); $adjustmentReference=(string)$bill['ServiceReference']; $existing=tdc_load_bill_adjustment($pdo,'Service',$adjustmentReference); $gross=(float)($existing['GrossAmount']??$bill['DefaultAmount']);
        } elseif ($billType === 'consultation') {
            $q=$pdo->prepare('SELECT * FROM visits WHERE VisitReference=? FOR UPDATE'); $q->execute([$reference]); $bill=$q->fetch();
            if (!$bill) throw new RuntimeException('Consultation bill not found.');
            $patientId=(int)$bill['PatientID'];
            $sourceReference=(string)$bill['VisitReference'];
            $paid=tdc_payments_confirmed_total($pdo,'VisitID',(int)$bill['VisitID']);
            $existing=tdc_load_bill_adjustment($pdo,'consultation',$sourceReference);
            $gross=(float)($existing['GrossAmount']??$bill['ConsultationFee']);
            $reference=$sourceReference;
            $m['link']='VisitID';
        } else {
            $q=$pdo->prepare("SELECT * FROM {$m['table']} WHERE {$m['id']}=? FOR UPDATE"); $q->execute([$reference]); $bill=$q->fetch();
            if (!$bill) throw new RuntimeException('Bill not found.');
            $patientId=(int)$bill['PatientID']; $paid=tdc_payments_confirmed_total($pdo,$m['link'],$reference); $existing=tdc_load_bill_adjustment($pdo,$m['adjustment'],$reference); $gross=(float)($existing['GrossAmount']??($billType==='consultation'?$bill['ConsultationFee']:$bill['TotalAmount']));
        }
        $discountType=(string)($input['DiscountType']??'None');
        $discountValue=(float)($input['DiscountValue']??0);
        $taxRate=(float)($input['TaxRate']??0);
        $paymentAmount=is_numeric($input['PaymentAmount']??null)?round((float)$input['PaymentAmount'],2):0.0;
        $hasAdjustment = $discountType !== 'None' && $discountValue > 0 || $taxRate > 0;
        if ($paymentAmount <= 0 && !$hasAdjustment) throw new RuntimeException('No payment or adjustment was submitted.');
        $adjustmentReference = $billType === 'service' ? $adjustmentReference : $reference;
        $adj=tdc_save_bill_adjustment($pdo,$m['adjustment'],$adjustmentReference,$gross,$discountType,$discountValue,$taxRate,$paid,(string)($input['DiscountReason']??''),(string)($input['AdjustmentNote']??''));
        // Service billing still uses ServiceAmount as its source-of-truth total;
        // keep it aligned before the synchroniser recalculates paid/due/status.
        if ($billType === 'service') {
            $pdo->prepare('UPDATE service_assignments SET ServiceAmount=? WHERE AssignmentID=?')->execute([$adj['final_amount'],$serviceAssignmentId]);
        }
        $due=max(0,round($adj['final_amount']-$paid,2));
        $amount=$paymentAmount;
        if ($amount<0 || $amount>$due) throw new RuntimeException('Payment cannot exceed the current outstanding balance.');
        $method=trim((string)($input['PaymentMethod']??'')); if($amount>0 && !in_array($method,$paymentMethods,true)) throw new RuntimeException('Select a valid active payment method.');
        $paymentLinkValue = $billType === 'consultation' ? (int)$bill['VisitID'] : ($billType === 'service' ? $serviceAssignmentId : $reference);
        if($amount>0){$links=[$m['link']=>$paymentLinkValue,'PaymentMethod'=>$method];$pr=tdc_workflow_record_payment($pdo,$patientId,$m['paymentType'],$amount,$receivedBy,$links);if($pr)tdc_workflow_post_revenue($pdo,$m['account'],$m['accountName'],$pr,$m['accountName'].' payment for '.$reference,$amount,$method);}
        tdc_payments_sync_source($pdo,['PaymentType'=>$m['paymentType'],'PatientID'=>$patientId,$m['link']=>$paymentLinkValue]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
