<?php
declare(strict_types=1);

/**
 * auth/includes/finance.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Central finance service
 * ---------------------------------------------------------------------
 * One authoritative place for the two things every module used to invent
 * on its own:
 *
 *   1. The patient outstanding balance. Derived from the source records
 *      (consultation visits + laboratory orders + pharmacy bills), never
 *      manually mutated by individual pages. `patients.DueBalance` is a
 *      performance cache that is written ONLY through
 *      tdc_reconcile_patient_due_balance().
 *
 *   2. The payment ledger and its reversal workflow. Every manual
 *      payment (Consultation, Laboratory, Pharmacy, POS, Supplier) lives
 *      in `payments`. A correction NEVER deletes or edits the original
 *      row: a reversal row with a negative Amount and
 *      ReversalOfPaymentID = original PaymentID is appended, the
 *      accounting batch is mirrored with swapped Debit/Credit, and the
 *      source record's AmountPaid/DueBalance/statuses are recomputed
 *      from the ledger inside the same transaction.
 *
 * Ledger invariants enforced here:
 *   - SUM of a source's confirmed payments == its stored AmountPaid
 *     (PaymentStatus='Voided' rows are ignored; reversal rows are
 *     Confirmed with a negative amount, so plain SUM is always correct).
 *   - A payment may be reversed at most once per unit of amount:
 *     double reversal and over-reversal are refused.
 *   - Reversal batches satisfy SUM(Debit) = SUM(Credit).
 * ---------------------------------------------------------------------
 */

// ===========================================================================
// 1. Patient outstanding balance (single source of truth)
// ===========================================================================

/**
 * Derived patient outstanding balance:
 *   SUM consultation remaining (non-cancelled visits)
 * + SUM laboratory remaining (non-cancelled orders)
 * + SUM pharmacy/prescription remaining (one value per bill)
 */
function tdc_patient_outstanding_balance(PDO $pdo, int $patientId): float
{
    if ($patientId < 1) return 0.0;
    $stmt = $pdo->prepare(
        "SELECT ROUND(
            COALESCE((SELECT SUM(v.DueBalance) FROM visits v WHERE v.PatientID = :pid1 AND v.QueueStatus <> 'Cancelled'), 0)
          + COALESCE((SELECT SUM(l.DueBalance) FROM laboratory l WHERE l.PatientID = :pid2 AND l.WorkflowStatus <> 'Cancelled'), 0)
          + COALESCE((SELECT SUM(x.DueBalance) FROM (
                SELECT MIN(DueBalance) AS DueBalance FROM prescriptions
                WHERE PatientID = :pid3 AND Status <> 'Cancelled'
                GROUP BY SUBSTRING_INDEX(PrescriptionID, '-', 1)
            ) x), 0)
        , 2)"
    );
    $stmt->execute(['pid1' => $patientId, 'pid2' => $patientId, 'pid3' => $patientId]);
    return max(0.0, (float) $stmt->fetchColumn());
}

/** Recompute the outstanding balance and store it in patients.DueBalance. */
function tdc_reconcile_patient_due_balance(PDO $pdo, int $patientId): float
{
    if ($patientId < 1) return 0.0;
    $balance = tdc_patient_outstanding_balance($pdo, $patientId);
    $stmt = $pdo->prepare('UPDATE patients SET DueBalance = :balance WHERE PatientID = :pid');
    $stmt->execute(['balance' => $balance, 'pid' => $patientId]);
    return $balance;
}

// ===========================================================================
// 2. Payment ledger helpers
// ===========================================================================

/**
 * Return the human-readable business source for a payment row.
 *
 * Payment rows are deliberately self-linked through the source reference
 * columns; this helper does not infer relationships from payment type.
 */
function tdc_payment_source_label(array $payment): string
{
    $type = trim((string) ($payment['PaymentType'] ?? 'Payment'));
    $reference = '';
    if ($type === 'Consultation') {
        $reference = trim((string) ($payment['VisitReference'] ?? ''));
        if ($reference === '') $reference = trim((string) ($payment['VisitID'] ?? ''));
    } elseif ($type === 'Laboratory') {
        $reference = trim((string) ($payment['LaboratoryID'] ?? ''));
    } elseif ($type === 'Pharmacy') {
        $reference = trim((string) ($payment['PrescriptionReference'] ?? ''));
    } elseif ($type === 'POS') {
        $reference = trim((string) ($payment['SaleReference'] ?? ''));
    } elseif ($type === 'Supplier') {
        $reference = trim((string) ($payment['PurchaseReference'] ?? ''));
    }
    return $reference !== '' ? $type . ' - ' . $reference : ($type !== '' ? $type : 'Payment');
}

/** Sum of confirmed (non-voided) payment amounts for one source link column. */
function tdc_payments_confirmed_total(PDO $pdo, string $column, $value): float
{
    $allowed = ['VisitID', 'LaboratoryID', 'PrescriptionReference', 'SaleReference', 'PurchaseReference'];
    if (!in_array($column, $allowed, true) || $value === null || $value === '') return 0.0;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM payments WHERE {$column} = :value AND PaymentStatus = 'Confirmed'");
    $stmt->execute(['value' => $value]);
    return round((float) $stmt->fetchColumn(), 2);
}

/**
 * Recompute the financial state of the record a payment belongs to.
 * Applies to consultations, laboratory orders, pharmacy bills,
 * pharmacy POS sales and supplier purchases.
 */
function tdc_payments_sync_source(PDO $pdo, array $payment): void
{
    $type = (string) $payment['PaymentType'];

    if ($type === 'Consultation' && !empty($payment['VisitID'])) {
        $visitId = (int) $payment['VisitID'];
        $stmt = $pdo->prepare('SELECT ConsultationFee, QueueStatus FROM visits WHERE VisitID = ?');
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();
        if (!$visit) return;
        $paid = tdc_payments_confirmed_total($pdo, 'VisitID', $visitId);
        $fee = (float) $visit['ConsultationFee'];
        $due = max(0.0, round($fee - $paid, 2));
        $status = tdc_workflow_payment_status($fee, $paid);
        // Workflow status is restored when a payment drops below the fee,
        // but an appointment already in progress or completed is untouched.
        if ($status === 'Paid' && $visit['QueueStatus'] === 'Pending Payment') $queue = 'Waiting';
        elseif ($status !== 'Paid' && $visit['QueueStatus'] === 'Waiting') $queue = 'Pending Payment';
        else $queue = (string) $visit['QueueStatus'];
        $update = $pdo->prepare('UPDATE visits SET AmountPaid = :paid, DueBalance = :due, PaymentStatus = :status, QueueStatus = :queue WHERE VisitID = :id');
        $update->execute(['paid' => $paid, 'due' => $due, 'status' => $status, 'queue' => $queue, 'id' => $visitId]);
        tdc_reconcile_patient_due_balance($pdo, (int) $payment['PatientID']);
        return;
    }

    if ($type === 'Laboratory' && !empty($payment['LaboratoryID'])) {
        $labId = (string) $payment['LaboratoryID'];
        $stmt = $pdo->prepare('SELECT TotalAmount, WorkflowStatus FROM laboratory WHERE LaboratoryID = ?');
        $stmt->execute([$labId]);
        $lab = $stmt->fetch();
        if (!$lab) return;
        $paid = tdc_payments_confirmed_total($pdo, 'LaboratoryID', $labId);
        $total = (float) $lab['TotalAmount'];
        $due = max(0.0, round($total - $paid, 2));
        $status = tdc_workflow_payment_status($total, $paid);
        if ($status === 'Paid' && $lab['WorkflowStatus'] === 'Awaiting Payment') $workflow = 'Ready';
        elseif ($status !== 'Paid' && $lab['WorkflowStatus'] === 'Ready') $workflow = 'Awaiting Payment';
        else $workflow = (string) $lab['WorkflowStatus'];
        $update = $pdo->prepare('UPDATE laboratory SET AmountPaid = :paid, DueBalance = :due, PaymentStatus = :status, WorkflowStatus = :workflow WHERE LaboratoryID = :id');
        $update->execute(['paid' => $paid, 'due' => $due, 'status' => $status, 'workflow' => $workflow, 'id' => $labId]);
        tdc_reconcile_patient_due_balance($pdo, (int) $payment['PatientID']);
        return;
    }

    if ($type === 'Pharmacy' && !empty($payment['PrescriptionReference'])) {
        $base = (string) $payment['PrescriptionReference'];
        $paid = tdc_payments_confirmed_total($pdo, 'PrescriptionReference', $base);
        $stmt = $pdo->prepare('SELECT TotalAmount, PharmacySaleReference FROM prescriptions WHERE PrescriptionID LIKE :pattern LIMIT 1');
        $stmt->execute(['pattern' => $base . '-%']);
        $bill = $stmt->fetch();
        if ($bill) {
            $total = (float) $bill['TotalAmount'];
            $due = max(0.0, round($total - $paid, 2));
            $update = $pdo->prepare('UPDATE prescriptions SET AmountPaid = :paid, DueBalance = :due WHERE PrescriptionID LIKE :pattern');
            $update->execute(['paid' => $paid, 'due' => $due, 'pattern' => $base . '-%']);
            if (!empty($bill['PharmacySaleReference'])) {
                tdc_payments_sync_sale_lines($pdo, (string) $bill['PharmacySaleReference'], $total);
            }
        }
        tdc_reconcile_patient_due_balance($pdo, (int) $payment['PatientID']);
        return;
    }

    if ($type === 'POS' && !empty($payment['SaleReference'])) {
        $stmt = $pdo->prepare('SELECT TotalAmount FROM pharmacysales WHERE SaleID LIKE :pattern LIMIT 1');
        $stmt->execute(['pattern' => $payment['SaleReference'] . '-%']);
        $total = (float) ($stmt->fetchColumn() ?: 0);
        tdc_payments_sync_sale_lines($pdo, (string) $payment['SaleReference'], $total);
        return;
    }

    if ($type === 'Supplier' && !empty($payment['PurchaseReference'])) {
        $base = (string) $payment['PurchaseReference'];
        $paid = tdc_payments_confirmed_total($pdo, 'PurchaseReference', $base);
        $stmt = $pdo->prepare('SELECT TotalAmount FROM purchases WHERE PurchaseID LIKE :pattern LIMIT 1');
        $stmt->execute(['pattern' => $base . '-%']);
        $total = (float) ($stmt->fetchColumn() ?: 0);
        $paid = min($paid, $total); // purchase rows clamp paid to the net amount
        $due = max(0.0, round($total - $paid, 2));
        $update = $pdo->prepare('UPDATE purchases SET AmountPaid = :paid, DueBalance = :due WHERE PurchaseID LIKE :pattern');
        $update->execute(['paid' => $paid, 'due' => $due, 'pattern' => $base . '-%']);
        return;
    }
}

/** Keep one POS sale bill's paid/due/status in sync with the ledger. */
function tdc_payments_sync_sale_lines(PDO $pdo, string $saleBase, float $total): void
{
    $paid = tdc_payments_confirmed_total($pdo, 'SaleReference', $saleBase);
    $due = max(0.0, round($total - $paid, 2));
    $status = tdc_workflow_payment_status($total, $paid);
    $update = $pdo->prepare('UPDATE pharmacysales SET AmountPaid = :paid, DueBalance = :due, PaymentStatus = :status WHERE SaleID LIKE :pattern');
    $update->execute(['paid' => $paid, 'due' => $due, 'status' => $status, 'pattern' => $saleBase . '-%']);
}

// ===========================================================================
// 3. Payment reversal
// ===========================================================================

/**
 * Reverse (all or part of) a confirmed payment.
 *
 * $amount null => reverse the full unreversed remainder.
 * Returns ['reference', 'amount', 'payment'].
 *
 * @throws RuntimeException on double reversal, over-reversal, invalid
 *         input, or unknown payments.
 */
function tdc_payments_reverse(PDO $pdo, int $paymentId, string $reason, ?float $amount = null): array
{
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('A reversal reason is required.');
    if (mb_strlen($reason) > 500) throw new RuntimeException('The reversal reason is too long.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE PaymentID = :id FOR UPDATE');
        $stmt->execute(['id' => $paymentId]);
        $original = $stmt->fetch();
        if (!$original) throw new RuntimeException('Payment not found.');
        if ((string) $original['PaymentStatus'] !== 'Confirmed') throw new RuntimeException('This payment has already been voided.');
        if ($original['ReversalOfPaymentID'] !== null) throw new RuntimeException('A reversal record cannot be reversed again.');

        $originalAmount = round((float) $original['Amount'], 2);
        if ($originalAmount <= 0) throw new RuntimeException('Only positive receipts can be reversed.');

        $reversed = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM payments WHERE ReversalOfPaymentID = :id AND PaymentStatus = 'Confirmed'");
        $reversed->execute(['id' => $paymentId]);
        $alreadyReversed = round(-min(0.0, (float) $reversed->fetchColumn()), 2);
        $remaining = round($originalAmount - $alreadyReversed, 2);
        if ($remaining <= 0) throw new RuntimeException('This payment has already been fully reversed.');

        $amount = $amount === null ? $remaining : round((float) $amount, 2);
        if ($amount <= 0) throw new RuntimeException('The reversal amount must be greater than zero.');
        if ($amount > $remaining) throw new RuntimeException(sprintf('Only %s of this payment remains unreversed.', number_format($remaining, 2)));

        $reversalRef = tdc_workflow_next_reference($pdo, 'payments', 'PaymentReference', 'PAY');
        $insert = $pdo->prepare(
            'INSERT INTO payments
                (PaymentReference, PatientID, VisitID, LaboratoryID, PrescriptionReference, SaleReference, PurchaseReference,
                 PaymentType, Amount, PaymentMethod, PaymentStatus, ReceivedBy, PaidAt, Notes,
                 ReversalOfPaymentID, ReversalReference, ReversalReason)
             VALUES
                (:reference, :patient, :visit, :laboratory, :prescription, :sale, :purchase,
                 :type, :amount, :method, \'Confirmed\', :receivedBy, NOW(), :notes,
                 :reversalOf, :reversalRef, :reason)'
        );
        $insert->execute([
            'reference'     => $reversalRef,
            'patient'       => (int) $original['PatientID'],
            'visit'         => $original['VisitID'],
            'laboratory'    => $original['LaboratoryID'],
            'prescription'  => $original['PrescriptionReference'],
            'sale'          => $original['SaleReference'] ?? null,
            'purchase'      => $original['PurchaseReference'] ?? null,
            'type'          => $original['PaymentType'],
            'amount'        => -$amount,
            'method'        => $original['PaymentMethod'],
            'receivedBy'    => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            'notes'         => 'Reversal of ' . $original['PaymentReference'] . ': ' . $reason,
            'reversalOf'    => $paymentId,
            'reversalRef'   => $reversalRef,
            'reason'        => $reason,
        ]);

        tdc_payments_post_reversal_accounting($pdo, $original, $reversalRef, $amount);
        tdc_payments_sync_source($pdo, $original);
        tdc_audit($pdo, 'payment.reversed', 'payment', (string) $original['PaymentReference'],
            'Reversed ' . number_format($amount, 2) . ' of ' . $original['PaymentReference'] . ' (' . $original['PaymentType'] . '): ' . $reason,
            ['reversalReference' => $reversalRef, 'amount' => $amount, 'reason' => $reason]);
        $pdo->commit();
        return ['reference' => $reversalRef, 'amount' => $amount, 'payment' => $original];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mirror the original payment's accounting batch with swapped
 * Debit/Credit for the reversed amount. The original journal history is
 * preserved; the mirrored batch gets its own ReferenceID.
 */
function tdc_payments_post_reversal_accounting(PDO $pdo, array $original, string $reversalRef, float $amount): void
{
    $stmt = $pdo->prepare('SELECT EntryID, AccountID, AccountName, AccountType, BookType, Debit, Credit FROM accounting WHERE ReferenceID = :ref');
    $stmt->execute(['ref' => $original['PaymentReference']]);
    $entries = $stmt->fetchAll();
    if (!$entries) return;

    $batchDebit = (float) array_sum(array_map(static fn(array $e): float => (float) $e['Debit'], $entries));
    if ($batchDebit <= 0) $batchDebit = max(0.01, $amount);
    $scale = $amount / $batchDebit;

    $insert = $pdo->prepare(
        'INSERT INTO accounting (EntryID, AccountID, AccountName, AccountType, BookType, ReferenceID, Description, Debit, Credit, Balance)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mirrored = [];
    foreach ($entries as $entry) {
        $debit = round((float) $entry['Debit'] * $scale, 2);
        $credit = round((float) $entry['Credit'] * $scale, 2);
        $entryId = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
        $insert->execute([
            $entryId, $entry['AccountID'], $entry['AccountName'], $entry['AccountType'], $entry['BookType'],
            $reversalRef, 'Reversal of ' . $original['PaymentReference'] . ' (' . $reversalRef . ')', $credit, $debit, $credit - $debit,
        ]);
        $mirrored[] = ['id' => $entryId, 'debit' => $credit, 'credit' => $debit];
    }

    // Protect the balanced-batch invariant against per-row rounding.
    $diff = round(array_sum(array_map(static fn(array $m): float => $m['debit'] - $m['credit'], $mirrored)), 2);
    if ($diff !== 0.0) {
        $fixIndex = 0;
        foreach ($mirrored as $i => $m) {
            if ($m['debit'] + $m['credit'] > $mirrored[$fixIndex]['debit'] + $mirrored[$fixIndex]['credit']) $fixIndex = $i;
        }
        $fixDebit = $mirrored[$fixIndex]['debit'];
        $fixCredit = $mirrored[$fixIndex]['credit'];
        if ($fixDebit >= $fixCredit) $fixDebit = round($fixDebit - $diff, 2);
        else $fixCredit = round($fixCredit + $diff, 2);
        $update = $pdo->prepare('UPDATE accounting SET Debit = :debit, Credit = :credit, Balance = :balance WHERE EntryID = :id');
        $update->execute(['debit' => $fixDebit, 'credit' => $fixCredit, 'balance' => $fixCredit - $fixDebit, 'id' => $mirrored[$fixIndex]['id']]);
    }
}
