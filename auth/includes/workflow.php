<?php
declare(strict_types=1);

function tdc_workflow_next_reference(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'Visits.VisitReference', 'Payments.PaymentReference',
        'Prescriptions.PrescriptionID', 'Laboratory.LaboratoryID',
        'PharmacySales.SaleID', 'Accounting.EntryID',
    ];
    if (!in_array("{$table}.{$column}", $allowed, true)) {
        throw new InvalidArgumentException('Unsupported reference source.');
    }
    $start = strlen($prefix) + 1;
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(SUBSTRING({$column}, ?), '-', 1) AS UNSIGNED)), 0) + 1 FROM {$table} WHERE {$column} LIKE ?");
    $stmt->execute([$start, $prefix . '%']);
    $next = (int) $stmt->fetchColumn();
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
    $stmt = $pdo->prepare('INSERT INTO Notifications (UserID,RoleTarget,EventType,Title,Message,Link) VALUES (?,?,?,?,?,?)');
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

function tdc_workflow_record_payment(PDO $pdo, int $patientId, string $type, float $amount, int $receivedBy, array $links = []): ?string
{
    if ($amount <= 0) return null;
    $ref = tdc_workflow_next_reference($pdo, 'Payments', 'PaymentReference', 'PAY');
    $stmt = $pdo->prepare('INSERT INTO Payments (PaymentReference,PatientID,VisitID,LaboratoryID,PrescriptionReference,PaymentType,Amount,PaymentMethod,ReceivedBy) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$ref,$patientId,$links['VisitID'] ?? null,$links['LaboratoryID'] ?? null,$links['PrescriptionReference'] ?? null,$type,$amount,$links['PaymentMethod'] ?? 'Cash',$receivedBy]);
    return $ref;
}

function tdc_workflow_post_revenue(PDO $pdo, string $accountId, string $accountName, string $reference, string $description, float $amount): void
{
    if ($amount <= 0) return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM Accounting WHERE ReferenceID=? AND AccountID=?');
    $check->execute([$reference,$accountId]);
    if ((int) $check->fetchColumn() > 0) return;
    $entry = tdc_workflow_next_reference($pdo, 'Accounting', 'EntryID', 'JRN');
    $stmt = $pdo->prepare("INSERT INTO Accounting (EntryID,AccountID,AccountName,AccountType,BookType,ReferenceID,Description,Debit,Credit,Balance) VALUES (?,?,?,'Revenue','Sales Book',?,?,0,?,?)");
    $stmt->execute([$entry,$accountId,$accountName,$reference,$description,$amount,$amount]);
}
