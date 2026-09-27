<?php
declare(strict_types=1);

/**
 * Safely reconcile legacy POS rows without guessing identity or payment data.
 * The caller must explicitly request application of deterministic matches.
 *
 * @return array<int,array<string,mixed>>
 */
function tdc_reconcile_legacy_pos(PDO $pdo, bool $apply = false): array
{
    $sales = $pdo->query("SELECT SUBSTRING_INDEX(SaleID, '-', 1) AS SaleReference,
        MIN(CustomerName) AS CustomerName, MIN(CustomerPhone) AS CustomerPhone,
        MIN(SaleDate) AS SaleDate, COUNT(DISTINCT PatientID) AS PatientLinks,
        COUNT(DISTINCT VisitID) AS VisitLinks
        FROM pharmacysales WHERE PatientID IS NULL OR VisitID IS NULL
        GROUP BY SUBSTRING_INDEX(SaleID, '-', 1) ORDER BY SaleReference")->fetchAll();
    $patients = $pdo->query('SELECT PatientID, PatientName, PatientPhone FROM patients')->fetchAll();
    $normalizePhone = static fn(string $value): string => preg_replace('/\D+/', '', $value) ?? '';
    $normalizeName = static fn(string $value): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    $results = [];

    foreach ($sales as $sale) {
        $base = (string) $sale['SaleReference'];
        $candidatePatient = null;
        $candidateVisit = null;
        $evidence = 'none';
        $prescription = $pdo->prepare('SELECT DISTINCT PatientID, VisitID FROM prescriptions WHERE PharmacySaleReference=?');
        $prescription->execute([$base]);
        $prescriptionPairs = $prescription->fetchAll();
        if (count($prescriptionPairs) === 1 && (int) $prescriptionPairs[0]['PatientID'] > 0) {
            $candidatePatient = (int) $prescriptionPairs[0]['PatientID'];
            $candidateVisit = (int) ($prescriptionPairs[0]['VisitID'] ?? 0) ?: null;
            $evidence = 'prescription sale relationship';
        } else {
            $phone = $normalizePhone((string) ($sale['CustomerPhone'] ?? ''));
            $name = $normalizeName((string) ($sale['CustomerName'] ?? ''));
            $matches = array_values(array_filter($patients, static fn(array $patient): bool =>
                $phone !== '' && $phone === $normalizePhone((string) ($patient['PatientPhone'] ?? ''))
                && $name !== '' && $name === $normalizeName((string) $patient['PatientName'])));
            if (count($matches) === 1) {
                $candidatePatient = (int) $matches[0]['PatientID'];
                $evidence = 'exact normalized phone and name';
            } elseif (count($matches) > 1) {
                $evidence = 'ambiguous exact phone and name';
            }
        }

        if ($candidatePatient !== null && $candidateVisit === null) {
            $visit = $pdo->prepare("SELECT VisitID FROM visits WHERE PatientID=? AND DATE(VisitDate)=DATE(?) AND QueueStatus<>'Cancelled' ORDER BY VisitID");
            $visit->execute([$candidatePatient, $sale['SaleDate']]);
            $sameDayVisits = $visit->fetchAll(PDO::FETCH_COLUMN);
            if (count($sameDayVisits) === 1) {
                $candidateVisit = (int) $sameDayVisits[0];
                $evidence .= '; one non-cancelled visit on sale date';
            } elseif (count($sameDayVisits) > 1) {
                $evidence .= '; visit ambiguous';
            }
        }

        $canApply = $candidatePatient !== null && ($candidateVisit !== null || $evidence === 'prescription sale relationship');
        if ($apply && $canApply) {
            $stmt = $pdo->prepare('UPDATE pharmacysales SET PatientID=COALESCE(PatientID,?), VisitID=COALESCE(VisitID,?) WHERE SaleID LIKE ?');
            $stmt->execute([$candidatePatient, $candidateVisit, $base . '-%']);
        }
        $results[] = [
            'SaleReference' => $base,
            'status' => $candidatePatient === null ? (str_contains($evidence, 'ambiguous') ? 'ambiguous' : 'unmatched') : ($canApply ? ($apply ? 'reconciled' : 'deterministic') : 'ambiguous'),
            'PatientID' => $candidatePatient,
            'VisitID' => $candidateVisit,
            'evidence' => $evidence,
        ];
    }
    return $results;
}

/**
 * Record the method for a historical POS payment that was already included
 * in the sale. This deliberately bypasses the normal payment workflow: it
 * must not change balances, stock, or create another accounting batch.
 */
function tdc_reconcile_legacy_pos_payment(PDO $pdo, string $saleReference, string $paymentMethod, string $reason, int $receivedBy): string
{
    $saleReference = preg_replace('/[^A-Za-z0-9]/', '', $saleReference) ?? '';
    $paymentMethod = trim($paymentMethod);
    $reason = trim($reason);
    if ($saleReference === '' || $paymentMethod === '' || $reason === '') {
        throw new RuntimeException('Sale reference, payment method and reason are required.');
    }
    if (strlen($reason) > 500) {
        throw new RuntimeException('Reason must be 500 characters or fewer.');
    }
    if (!tdc_has_column($pdo, 'payments', 'SaleReference')) {
        throw new RuntimeException('Payment schema is missing SaleReference; legacy POS payment reconciliation requires an additive schema change.');
    }

    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $saleStmt = $pdo->prepare(
            'SELECT SaleID,PatientID,VisitID,TotalAmount,AmountPaid,DueBalance,PaymentStatus
             FROM pharmacysales WHERE SaleID LIKE ? ORDER BY SaleID FOR UPDATE'
        );
        $saleStmt->execute([$saleReference . '-%']);
        $saleRows = $saleStmt->fetchAll();
        if (!$saleRows) {
            throw new RuntimeException('The POS sale could not be found.');
        }

        $first = $saleRows[0];
        $amountPaid = round((float) $first['AmountPaid'], 2);
        if ($amountPaid <= 0) {
            throw new RuntimeException('This sale has no historical paid amount to reconcile.');
        }
        foreach ($saleRows as $row) {
            if (round((float) $row['AmountPaid'], 2) !== $amountPaid
                || round((float) $row['TotalAmount'], 2) !== round((float) $first['TotalAmount'], 2)) {
                throw new RuntimeException('The POS sale lines do not agree on their financial totals.');
            }
        }

        $existing = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE SaleReference=? AND PaymentStatus='Confirmed'");
        $existing->execute([$saleReference]);
        if ((int) $existing->fetchColumn() > 0) {
            throw new RuntimeException('This sale already has a recorded payment method.');
        }

        $method = $pdo->prepare('SELECT MethodName FROM paymentmethods WHERE MethodName=? AND IsActive=1 LIMIT 1');
        $method->execute([$paymentMethod]);
        if (!$method->fetchColumn()) {
            throw new RuntimeException('The selected payment method is not active.');
        }

        $accounting = $pdo->prepare('SELECT COUNT(*) FROM accounting WHERE ReferenceID=?');
        $accounting->execute([$saleReference]);
        if ((int) $accounting->fetchColumn() < 1) {
            throw new RuntimeException('Historical revenue for this sale is not posted; no payment-method repair was recorded.');
        }

        $paymentReference = tdc_workflow_next_reference($pdo, 'payments', 'PaymentReference', 'PAY');
        $insert = $pdo->prepare(
            'INSERT INTO payments
             (PaymentReference,PatientID,VisitID,SaleReference,PaymentType,Amount,PaymentMethod,PaymentStatus,ReceivedBy,PaidAt,Notes)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)'
        );
        $insert->execute([
            $paymentReference,
            (int) ($first['PatientID'] ?? 0),
            (int) ($first['VisitID'] ?? 0) ?: null,
            $saleReference,
            'POS',
            $amountPaid,
            $paymentMethod,
            'Confirmed',
            $receivedBy > 0 ? $receivedBy : null,
            'Legacy POS payment method reconciliation: ' . $reason,
        ]);

        tdc_audit(
            $pdo,
            'payment.legacy_method_reconciled',
            'pharmacysales',
            $saleReference,
            'Legacy POS payment method reconciled',
            [
                'SaleReference' => $saleReference,
                'Amount' => $amountPaid,
                'PaymentMethod' => $paymentMethod,
                'UserID' => $receivedBy,
                'Reason' => $reason,
            ]
        );

        if ($started) {
            $pdo->commit();
        }
        return $paymentReference;
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
