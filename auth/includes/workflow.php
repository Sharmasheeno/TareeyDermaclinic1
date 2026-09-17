<?php
declare(strict_types=1);

require_once __DIR__ . '/finance.php';

function tdc_workflow_next_reference(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'visits.VisitReference', 'payments.PaymentReference',
        'prescriptions.PrescriptionID', 'laboratory.LaboratoryID',
        'pharmacysales.SaleID', 'accounting.EntryID',
    ];
    if (!in_array("{$table}.{$column}", $allowed, true)) {
        throw new InvalidArgumentException('Unsupported reference source.');
    }
    $sequenceKey = $table . '.' . $column . ':' . $prefix;
    $hasSequenceTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reference_sequences'")->fetchColumn() > 0;
    if ($hasSequenceTable) {
        $insert = $pdo->prepare('INSERT IGNORE INTO reference_sequences (SequenceKey,NextValue) VALUES (?,0)');
        $insert->execute([$sequenceKey]);
        $lock = $pdo->prepare('SELECT NextValue FROM reference_sequences WHERE SequenceKey=? FOR UPDATE');
        $lock->execute([$sequenceKey]);
        $next = (int) $lock->fetchColumn();
        if ($next === 0) {
            $start = strlen($prefix) + 1;
            $legacy = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING({$column}, ?), '-', 1) AS UNSIGNED)), 0) FROM {$table} WHERE {$column} LIKE ?");
            $legacy->execute([$start, $prefix . '%']);
            $next = (int) $legacy->fetchColumn();
        }
        $next++;
        $update = $pdo->prepare('UPDATE reference_sequences SET NextValue=? WHERE SequenceKey=?');
        $update->execute([$next, $sequenceKey]);
    } else {
        $start = strlen($prefix) + 1;
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING({$column}, ?), '-', 1) AS UNSIGNED)), 0) + 1 FROM {$table} WHERE {$column} LIKE ?");
        $stmt->execute([$start, $prefix . '%']);
        $next = (int) $stmt->fetchColumn();
    }
    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

function tdc_workflow_payment_status(float $total, float $paid): string
{
    if ($total <= 0) return 'Paid';
    if ($paid <= 0) return 'Unpaid';
    return $paid + 0.00001 >= $total ? 'Paid' : 'Partial';
}

function tdc_workflow_notify(PDO $pdo, ?int $userId, ?string $role, string $event, string $title, string $message, string $link): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (UserID,RoleTarget,EventType,Title,Message,Link) VALUES (?,?,?,?,?,?)');
    if ($userId) {
        $stmt->execute([$userId, null, $event, $title, $message, $link]);
        return;
    }
    if ($role) {
        $users = $pdo->prepare('SELECT id FROM users WHERE role=?');
        $users->execute([$role]);
        $recipientIds = $users->fetchAll(PDO::FETCH_COLUMN);
        if ($recipientIds) {
            foreach ($recipientIds as $recipientId) $stmt->execute([(int) $recipientId, null, $event, $title, $message, $link]);
            return;
        }
    }
    $stmt->execute([null, $role ?: null, $event, $title, $message, $link]);
}

/**
 * Notify every active user whose RBAC role grants the functional
 * permission, regardless of whether the role is a built-in or a custom
 * one. Users matching several criteria receive exactly one copy. When
 * nobody holds the permission and a built-in fallback role is given,
 * the notification falls back to the legacy role-target row.
 */
function tdc_workflow_notify_permission(PDO $pdo, string $permissionKey, string $event, string $title, string $message, string $link, ?string $fallbackRole = null): void
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.id FROM users u
         JOIN roles r ON r.RoleID = u.role_id AND r.IsActive = 1
         JOIN rolepermissions rp ON rp.RoleID = r.RoleID
         JOIN permissions p ON p.PermissionID = rp.PermissionID
         WHERE p.PermissionKey = :permission AND u.is_active = 1'
    );
    $stmt->execute(['permission' => $permissionKey]);
    $recipientIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    if ($recipientIds) {
        $insert = $pdo->prepare('INSERT INTO notifications (UserID,RoleTarget,EventType,Title,Message,Link) VALUES (?,?,?,?,?,?)');
        foreach ($recipientIds as $recipientId) {
            $insert->execute([$recipientId, null, $event, $title, $message, $link]);
        }
        return;
    }
    if ($fallbackRole !== null) {
        tdc_workflow_notify($pdo, null, $fallbackRole, $event, $title, $message, $link);
    }
}

/**
 * Append one manual receipt to the central payment ledger.
 *
 * $links['PaymentReference'] may override the generated reference. POS and
 * supplier collections use the bill base (e.g. "POS000042") so the ledger
 * row, the accounting batch (ReferenceID) and the reversal mirror all agree.
 *
 * Walk-in POS sales and supplier payments have no patient record: they are
 * recorded with PatientID = 0, which the balance reconciler treats as "no
 * patient balance impact".
 */
function tdc_workflow_record_payment(PDO $pdo, int $patientId, string $type, float $amount, int $receivedBy, array $links = []): ?string
{
    if ($amount <= 0) return null;
    $ref = (string) ($links['PaymentReference'] ?? '');
    if ($ref === '') {
        $ref = tdc_workflow_next_reference($pdo, 'payments', 'PaymentReference', 'PAY');
    }
    $stmt = $pdo->prepare('INSERT INTO payments (PaymentReference,PatientID,VisitID,LaboratoryID,PrescriptionReference,SaleReference,PurchaseReference,PaymentType,Amount,PaymentMethod,ReceivedBy) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $ref, $patientId, $links['VisitID'] ?? null, $links['LaboratoryID'] ?? null, $links['PrescriptionReference'] ?? null,
        $links['SaleReference'] ?? null, $links['PurchaseReference'] ?? null, $type, $amount, $links['PaymentMethod'] ?? 'Cash', $receivedBy,
    ]);
    // The stored patient balance is always reconciled from the source
    // records whenever the ledger changes.
    tdc_reconcile_patient_due_balance($pdo, $patientId);
    return $ref;
}

function tdc_workflow_post_revenue(PDO $pdo, string $accountId, string $accountName, string $reference, string $description, float $amount, ?string $paymentMethod = null): void
{
    if ($amount <= 0) return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM accounting WHERE ReferenceID=?');
    $check->execute([$reference]);
    if ((int) $check->fetchColumn() > 0) return;
    $method = $paymentMethod;
    if ($method === null || $method === '') {
        $payment = $pdo->prepare('SELECT PaymentMethod FROM payments WHERE PaymentReference=? LIMIT 1');
        $payment->execute([$reference]);
        $method = (string) ($payment->fetchColumn() ?: 'Cash');
    }
    $methodKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $method));
    $receivingId = 'PAY-' . strtoupper(substr($methodKey ?: 'cash', 0, 40));
    $receivingName = $method . ' Clearing';
    $debitEntry = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
    $creditEntry = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
    $stmt = $pdo->prepare("INSERT INTO accounting (EntryID,AccountID,AccountName,AccountType,BookType,ReferenceID,Description,Debit,Credit,Balance) VALUES (?,?,?,'Asset','Sales Book',?,?,?,0,?)");
    $stmt->execute([$debitEntry,$receivingId,$receivingName,$reference,$description,$amount,$amount]);
    $stmt = $pdo->prepare("INSERT INTO accounting (EntryID,AccountID,AccountName,AccountType,BookType,ReferenceID,Description,Debit,Credit,Balance) VALUES (?,?,?,'Revenue','Sales Book',?,?,0,?,?)");
    $stmt->execute([$creditEntry,$accountId,$accountName,$reference,$description,$amount,$amount]);
}

/**
 * Post the symmetric journal of a manual outbound payment (supplier
 * purchase settlement): debit the purchase account, credit the receiving
 * asset the money left. The batch is balanced, so the generic reversal
 * mirror in finance.php can undo it exactly, the same way it undoes
 * inbound receipts.
 */
function tdc_workflow_post_expense(PDO $pdo, string $accountId, string $accountName, string $reference, string $description, float $amount, ?string $paymentMethod = null): void
{
    if ($amount <= 0) return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM accounting WHERE ReferenceID=?');
    $check->execute([$reference]);
    if ((int) $check->fetchColumn() > 0) return;
    $method = $paymentMethod;
    if ($method === null || $method === '') {
        $payment = $pdo->prepare('SELECT PaymentMethod FROM payments WHERE PaymentReference=? LIMIT 1');
        $payment->execute([$reference]);
        $method = (string) ($payment->fetchColumn() ?: 'Cash');
    }
    $methodKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $method));
    $receivingId = 'PAY-' . strtoupper(substr($methodKey ?: 'cash', 0, 40));
    $receivingName = $method . ' Clearing';
    $expenseEntry = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
    $creditEntry  = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
    $stmt = $pdo->prepare("INSERT INTO accounting (EntryID,AccountID,AccountName,AccountType,BookType,ReferenceID,Description,Debit,Credit,Balance) VALUES (?,?,?,'Expense','Purchases Book',?,?,?,0,?)");
    $stmt->execute([$expenseEntry,$accountId,$accountName,$reference,$description,$amount,$amount]);
    $stmt = $pdo->prepare("INSERT INTO accounting (EntryID,AccountID,AccountName,AccountType,BookType,ReferenceID,Description,Debit,Credit,Balance) VALUES (?,?,?,'Asset','Purchases Book',?,?,0,?,?)");
    $stmt->execute([$creditEntry,$receivingId,$receivingName,$reference,$description,$amount,-$amount]);
}
