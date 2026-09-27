<?php
declare(strict_types=1);
require_once __DIR__ . '/billing-adjustments.php';

require_once __DIR__ . '/finance.php';

const TDC_APPOINTMENT_MINUTES = 30;

/** Parse date-only appointment input while accepting legacy datetime values. */
function tdc_parse_appointment_datetime(string $rawDate, ?DateTimeZone $timezone = null): ?DateTime
{
    $timezone ??= new DateTimeZone('Africa/Mogadishu');
    $rawDate = trim($rawDate);
    if ($rawDate === '') return null;
    $date = DateTime::createFromFormat('Y-m-d', $rawDate, $timezone);
    if ($date && $date->format('Y-m-d') === $rawDate) {
        $date->setTime(9, 0, 0);
        return $date;
    }
    $date = DateTime::createFromFormat('Y-m-d\\TH:i', $rawDate, $timezone);
    return $date && $date->format('Y-m-d\\TH:i') === $rawDate ? $date : null;
}

function tdc_doctor_has_booking_conflict(PDO $pdo, int $doctorId, DateTimeInterface $visitDate, ?int $excludeVisitId = null): bool
{
    $sql = "SELECT COUNT(*) FROM visits WHERE DoctorID=? AND QueueStatus<>'Cancelled' AND VisitDate < DATE_ADD(?, INTERVAL " . TDC_APPOINTMENT_MINUTES . " MINUTE) AND DATE_ADD(VisitDate, INTERVAL " . TDC_APPOINTMENT_MINUTES . " MINUTE) > ?";
    if ($excludeVisitId !== null) $sql .= ' AND VisitID <> ?';
    $stmt = $pdo->prepare($sql);
    $formatted = $visitDate->format('Y-m-d H:i:s');
    $params = [$doctorId, $formatted, $formatted];
    if ($excludeVisitId !== null) $params[] = $excludeVisitId;
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function tdc_doctor_is_available(array $doctor, DateTimeInterface $visitDate): bool
{
    $day = (string) $visitDate->format('N');
    $time = $visitDate->format('H:i');
    $days = array_filter(explode(',', (string) ($doctor['WorkingDays'] ?? '')));
    $start = substr((string) ($doctor['WorkStartTime'] ?? ''), 0, 5);
    $end = substr((string) ($doctor['WorkEndTime'] ?? ''), 0, 5);
    return in_array($day, $days, true) && $time >= $start && $time <= $end;
}

function tdc_workflow_next_reference(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'visits.VisitReference', 'payments.PaymentReference',
        'prescriptions.PrescriptionID', 'laboratory.LaboratoryID',
        'pharmacysales.SaleID', 'service_assignments.ServiceReference', 'accounting.EntryID',
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

    $duplicateCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM payments WHERE PaymentType = ? AND PatientID = ? AND Amount = ? AND PaymentMethod = ? AND ReceivedBy = ? AND PaymentStatus = "Confirmed" AND LaboratoryID IS NOT NULL AND LaboratoryID = ? LIMIT 1'
    );
    if ($type === 'Laboratory' && !empty($links['LaboratoryID'])) {
        $duplicateCheck->execute([$type, $patientId, round($amount, 2), $links['PaymentMethod'] ?? 'Cash', $receivedBy, (string) $links['LaboratoryID']]);
        if ((int) $duplicateCheck->fetchColumn() > 0) {
            return null;
        }
    }

    $columns = ['PaymentReference', 'PatientID', 'PaymentType', 'Amount', 'PaymentMethod', 'ReceivedBy'];
    $values = [$ref, $patientId, $type, $amount, $links['PaymentMethod'] ?? 'Cash', $receivedBy];
    $optionalLinks = [
        'VisitID' => $links['VisitID'] ?? null,
        'LaboratoryID' => $links['LaboratoryID'] ?? null,
        'PrescriptionReference' => $links['PrescriptionReference'] ?? null,
    ];
    if ($type === 'POS') {
        $optionalLinks['SaleReference'] = $links['SaleReference'] ?? null;
    } elseif ($type === 'Supplier') {
        $optionalLinks['PurchaseReference'] = $links['PurchaseReference'] ?? null;
    } elseif ($type === 'Service') {
        $optionalLinks['ServiceAssignmentID'] = $links['ServiceAssignmentID'] ?? null;
    }
    foreach ($optionalLinks as $column => $value) {
        if ($value === null || $value === '') continue;
        if (!tdc_has_column($pdo, 'payments', $column)) {
            throw new RuntimeException("Payment schema is missing {$column} for {$type} payment.");
        }
        $columns[] = $column;
        $values[] = $value;
    }
    $columnSql = implode(',', $columns);
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $stmt = $pdo->prepare("INSERT INTO payments ({$columnSql}) VALUES ({$placeholders})");
    $stmt->execute($values);
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

/** Create an optional appointment while the patient intake transaction is open. */
function tdc_create_patient_appointment(PDO $pdo, int $patientId, array $input, int $receptionistId): int
{
    $doctorId = ctype_digit((string) ($input['AppointmentDoctorID'] ?? '')) ? (int) $input['AppointmentDoctorID'] : 0;
    $rawDate = trim((string) ($input['AppointmentDate'] ?? ''));
    $date = tdc_parse_appointment_datetime($rawDate);
    $doctorStmt = $pdo->prepare('SELECT DoctorID,UserID,ConsultationFee,WorkingDays,WorkStartTime,WorkEndTime FROM doctors WHERE DoctorID=?');
    $doctorStmt->execute([$doctorId]);
    $doctor = $doctorStmt->fetch();
    if (!$doctor || empty($doctor['UserID'])) throw new RuntimeException('Select a doctor linked to a Doctor user account.');
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        [$hour, $minute] = array_pad(explode(':', substr((string) $doctor['WorkStartTime'], 0, 5)), 2, 0);
        $date->setTime((int) $hour, (int) $minute, 0);
    }
    if (!$date) throw new RuntimeException('Choose a valid appointment date.');
    if (strlen($rawDate) > 10 && $date < new DateTime('now', new DateTimeZone('Africa/Mogadishu'))) throw new RuntimeException('Appointment time cannot be in the past.');
    if ($date < new DateTime('today', new DateTimeZone('Africa/Mogadishu'))) throw new RuntimeException('Appointment date cannot be in the past.');
    if (!tdc_doctor_is_available($doctor, $date)) throw new RuntimeException('The selected doctor is not available on the selected date.');
    $isFreeConsultation = !empty($input['AppointmentFreeConsultation']);
    $amount = is_numeric($input['AppointmentAmountPaid'] ?? null) ? round((float) $input['AppointmentAmountPaid'], 2) : -1;
    $fee = round((float) $doctor['ConsultationFee'], 2);
    if ($isFreeConsultation) {
        // A waiver is visit-level state, not a fabricated 100% discount.
        $amount = 0.0;
        $finalFee = 0.0;
        $adjustment = null;
    } else {
        $adjustment = tdc_calculate_bill_adjustment($fee, (string)($input['AppointmentDiscountType'] ?? 'None'), (float)($input['AppointmentDiscountValue'] ?? 0), (float)($input['AppointmentTaxRate'] ?? 0));
        $finalFee = $adjustment['final_amount'];
    }
    if (!$isFreeConsultation && $adjustment['discount_amount'] > 0 && trim((string)($input['AppointmentDiscountReason'] ?? '')) === '') throw new RuntimeException('A discount reason is required.');
    if ($amount < 0 || $amount > $finalFee) throw new RuntimeException('Amount paid cannot exceed the final appointment amount.');
    $method = trim((string) ($input['AppointmentPaymentMethod'] ?? ''));
    $methods = $pdo->query('SELECT MethodName FROM paymentmethods WHERE IsActive=1 ORDER BY DisplayOrder,MethodName')->fetchAll(PDO::FETCH_COLUMN);
    if ($amount > 0 && !in_array($method, $methods, true)) throw new RuntimeException('Select an active payment method for the appointment payment.');
    if (tdc_doctor_has_booking_conflict($pdo, $doctorId, $date)) throw new RuntimeException('The doctor already has an appointment during this time.');
    $status = tdc_workflow_payment_status($finalFee, $amount);
    $queue = $status === 'Paid' ? 'Waiting' : 'Pending Payment';
    $reference = tdc_workflow_next_reference($pdo, 'visits', 'VisitReference', 'VIS');
    $stmt = $pdo->prepare('INSERT INTO visits (VisitReference,PatientID,DoctorID,ReceptionistUserID,VisitDate,ConsultationFee,AmountPaid,DueBalance,PaymentStatus,QueueStatus,ChiefComplaint,IsFreeConsultation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$reference,$patientId,$doctorId,$receptionistId,$date->format('Y-m-d H:i:s'),$finalFee,$amount,max(0,$finalFee-$amount),$status,$queue,trim((string) ($input['AppointmentReason'] ?? '')) ?: null,$isFreeConsultation ? 1 : 0]);
    $visitId = (int) $pdo->lastInsertId();
    if (!$isFreeConsultation) tdc_save_bill_adjustment($pdo, 'consultation', $reference, $fee, $adjustment['discount_type'], $adjustment['discount_value'], $adjustment['tax_rate'], $amount, (string)($input['AppointmentDiscountReason'] ?? ''), (string)($input['AppointmentAdjustmentNote'] ?? ''));
    $pdo->prepare('UPDATE patients SET AllocatedDoctor=?,VisitNumber=VisitNumber+1 WHERE PatientID=?')->execute([$doctorId,$patientId]);
    tdc_reconcile_patient_due_balance($pdo, $patientId);
    $paymentRef = tdc_workflow_record_payment($pdo, $patientId, 'Consultation', $amount, $receptionistId, ['VisitID'=>$visitId,'PaymentMethod'=>$method ?: 'Cash']);
    if ($paymentRef) tdc_workflow_post_revenue($pdo, 'REV-CONSULT', 'Consultation Revenue', $paymentRef, 'Appointment payment for '.$reference, $amount);
    if ($status === 'Paid') tdc_workflow_notify($pdo, (int) $doctor['UserID'], 'doctoruser', 'consultation_booked', 'New appointment booked', $reference.' is fully paid and waiting', 'doctors.php?visit='.$visitId);
    return $visitId;
}

/** Update an existing booked visit without creating a new visit or payment. */
function tdc_update_patient_appointment(PDO $pdo, int $patientId, int $visitId, array $input): void
{
    $stmt = $pdo->prepare('SELECT VisitID, PatientID, DoctorID, VisitDate, QueueStatus FROM visits WHERE VisitID=? AND PatientID=? LIMIT 1');
    $stmt->execute([$visitId, $patientId]);
    $visit = $stmt->fetch();
    if (!$visit || (string) $visit['QueueStatus'] === 'Cancelled') throw new RuntimeException('The selected appointment no longer exists or has been cancelled.');
    if (in_array((string) $visit['QueueStatus'], ['In Consultation', 'Completed'], true)) throw new RuntimeException('Only upcoming appointments can be edited.');
    $doctorId = ctype_digit((string) ($input['AppointmentDoctorID'] ?? '')) ? (int) $input['AppointmentDoctorID'] : 0;
    $rawDate = trim((string) ($input['AppointmentDate'] ?? ''));
    $date = tdc_parse_appointment_datetime($rawDate);
    $doctorStmt = $pdo->prepare('SELECT DoctorID,UserID,WorkingDays,WorkStartTime,WorkEndTime FROM doctors WHERE DoctorID=?');
    $doctorStmt->execute([$doctorId]);
    $doctor = $doctorStmt->fetch();
    if (!$doctor || empty($doctor['UserID'])) throw new RuntimeException('Select a doctor linked to a Doctor user account.');
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
        [$hour, $minute] = array_pad(explode(':', substr((string) $doctor['WorkStartTime'], 0, 5)), 2, 0);
        $date->setTime((int) $hour, (int) $minute, 0);
    }
    if (!$date) throw new RuntimeException('Choose a valid appointment date.');
    if ($date < new DateTime('today', new DateTimeZone('Africa/Mogadishu'))) throw new RuntimeException('Appointment date cannot be in the past.');
    if (!tdc_doctor_is_available($doctor, $date)) throw new RuntimeException('The selected doctor is not available on the selected date.');
    if (tdc_doctor_has_booking_conflict($pdo, $doctorId, $date, $visitId)) throw new RuntimeException('The doctor already has an appointment during this time.');
    if (strlen($rawDate) > 10 && $date < new DateTime('now', new DateTimeZone('Africa/Mogadishu'))) throw new RuntimeException('Appointment time cannot be in the past.');
    $pdo->prepare('UPDATE visits SET DoctorID=?, VisitDate=?, ChiefComplaint=? WHERE VisitID=? AND PatientID=?')
        ->execute([$doctorId, $date->format('Y-m-d H:i:s'), trim((string) ($input['AppointmentReason'] ?? '')) ?: null, $visitId, $patientId]);
    $pdo->prepare('UPDATE patients SET AllocatedDoctor=? WHERE PatientID=?')->execute([$doctorId, $patientId]);
}


