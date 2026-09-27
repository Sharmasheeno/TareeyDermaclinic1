<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
session_start();
require_once __DIR__.'/includes/access.php';
tdc_require_access();
if (!tdc_can('pharmacy.view')) tdc_forbidden();
require_once __DIR__.'/../db.php';

$source = (string)($_GET['source'] ?? '');
$reference = trim((string)($_GET['reference'] ?? ''));
if (!in_array($source, ['pos', 'prescription'], true) || !preg_match('/^[A-Za-z0-9]{1,47}$/', $reference)) {
    http_response_code(400);
    exit('A valid receipt source and reference are required.');
}

$rx = $source === 'prescription';
$table = $rx ? 'prescriptions' : 'pharmacysales';
$key = $rx ? 'PrescriptionID' : 'SaleID';

$s = $pdo->prepare("SELECT * FROM $table WHERE SUBSTRING_INDEX($key, '-', 1) = ? ORDER BY $key");
$s->execute([$reference]);
$lines = $s->fetchAll();

if (!$lines) {
    http_response_code(404);
    exit('Receipt not found for the selected source.');
}

$head = $lines[0];

if (!$rx) {
    $s = $pdo->prepare('SELECT PrescriptionID FROM prescriptions WHERE PharmacySaleReference = ? LIMIT 1');
    $s->execute([$reference]);
    if ($linked = $s->fetchColumn()) {
        header('Location: pharmacy_receipt.php?source=prescription&reference=' . rawurlencode(explode('-', (string)$linked)[0]));
        exit;
    }
}

$paymentKey = $rx ? 'PrescriptionReference' : 'SaleReference';
$s = $pdo->prepare("SELECT PaymentID,Amount,PaymentMethod,PaidAt FROM payments WHERE PaymentType=? AND $paymentKey=? AND PaymentStatus='Confirmed' ORDER BY PaidAt,PaymentID");
$s->execute([$rx ? 'Pharmacy' : 'POS', $reference]);
$payments = $s->fetchAll();

$total = (float)($head['TotalAmount'] ?? 0);
$paid = round(array_sum(array_column($payments, 'Amount')), 2);
$due = max(0, round($total - $paid, 2));
$unpriced = $rx && $total <= 0;
$voided = !$rx && (($head['SaleStatus'] ?? '') === 'Voided');
$cancelled = $rx && (($head['Status'] ?? '') === 'Cancelled');
$status = $voided ? 'VOIDED' : ($cancelled ? 'CANCELLED' : ($unpriced ? 'UNPRICED' : ($due <= 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID'))));

$patient = [];
$visit = [];
if (!empty($head['PatientID'])) {
    $s = $pdo->prepare('SELECT PatientID,PatientName,PatientPhone,Gender,Age,VisitNumber FROM patients WHERE PatientID = ?');
    $s->execute([$head['PatientID']]);
    $patient = $s->fetch() ?: [];
}

function tdc_prescription_sequence_number(string $value): string
{
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return '—';
    }

    if (preg_match('/(?:^|[A-Z])([0-9]+)(?:$|-)/', $normalized, $match)) {
        return (string) (int) $match[1];
    }

    if (preg_match('/([0-9]+)/', $normalized, $match)) {
        return (string) (int) $match[1];
    }

    return '—';
}

function tdc_receipt_display_name(string $value): string
{
    $value = trim($value);
    return mb_strtoupper($value, 'UTF-8');
}

if (!empty($head['VisitID'])) {
    $s = $pdo->prepare('SELECT v.VisitReference, d.DoctorName, d.Specialty FROM visits v LEFT JOIN doctors d ON d.DoctorID = v.DoctorID WHERE v.VisitID = ?');
    $s->execute([$head['VisitID']]);
    $visit = $s->fetch() ?: [];
}

if ($rx && !isset($visit['DoctorName'])) {
    $s = $pdo->prepare('SELECT DoctorName, Specialty FROM doctors WHERE DoctorID = ?');
    $s->execute([$head['DoctorID'] ?? 0]);
    $doc = $s->fetch() ?: [];
    $visit['DoctorName'] = (string)($doc['DoctorName'] ?? '—');
    $visit['Specialty'] = (string)($doc['Specialty'] ?? '');
}

$cashier = '—';
if (!$rx && !empty($head['SoldBy'])) {
    $s = $pdo->prepare('SELECT userlegalname FROM users WHERE id = ?');
    $s->execute([$head['SoldBy']]);
    $cashier = $s->fetchColumn() ?: '—';
}

$clinic = $pdo->query('SELECT CompanyName,CompanyAddress,PhoneNumbers,CompanyLogo FROM prescriptionsheader LIMIT 1')->fetch() ?: [];
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => number_format((float)$v, 2);
$printable = !$unpriced && !$cancelled;
$doctorName = tdc_receipt_display_name((string)($visit['DoctorName'] ?? ($head['DoctorName'] ?? '—')));
$doctorSpecialty = (string)($visit['Specialty'] ?? ($head['Specialty'] ?? ($head['DoctorSpecialty'] ?? '')));
$doctorTitle = (string)($head['DoctorTitle'] ?? ($head['Qualification'] ?? ($head['DoctorQualification'] ?? '')));
$patientName = tdc_receipt_display_name((string)($patient['PatientName'] ?? ($head['PatientName'] ?? ($head['CustomerName'] ?? 'Walk-in'))));
$patientPhone = $patient['PatientPhone'] ?? ($head['PatientPhone'] ?? '');
$visitReference = $visit['VisitReference'] ?? ($head['VisitReference'] ?? '—');
$visitNumber = $visitReference !== '—' ? $visitReference : ($head['VisitNumber'] ?? '—');
$pNo = tdc_prescription_sequence_number((string) ($head['PrescriptionID'] ?? $head['PrescriptionReference'] ?? $head['PrescriptionNo'] ?? $reference ?? ''));
if ($pNo === '—') {
    $pNo = tdc_prescription_sequence_number((string) ($head['PNo'] ?? $head['PatientNo'] ?? $head['PatientNumber'] ?? $head['RegistrationNumber'] ?? ''));
}
$patientGender = (string)($patient['Gender'] ?? ($head['Gender'] ?? ($visit['Gender'] ?? '—')));
$patientAge = isset($patient['Age']) && $patient['Age'] !== '' && $patient['Age'] !== null ? (string) $patient['Age'] : ((string)($head['Age'] ?? ($visit['Age'] ?? '—')));
$prescriptionDate = (string)($head['PrescriptionDate'] ?? $head['CreatedAt'] ?? $head['SaleDate'] ?? date('Y-m-d'));
$companyName = (string)($clinic['CompanyName'] ?? 'TAREY DERMA CLINIC');
$companyAddress = (string)($clinic['CompanyAddress'] ?? 'Degmada Hodan, Isgoyska Al-barako');
$companyPhone = (string)($clinic['PhoneNumbers'] ?? 'TEL: 615019253');
$doctorPhone = preg_replace('/^\s*TEL\s*:\s*/i', '', $companyPhone) ?: $companyPhone;
$logo = (string)($clinic['CompanyLogo'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $e($reference) ?> · Pharmacy <?= $unpriced ? 'Details' : 'Receipt' ?></title>
    <link rel="stylesheet" href="assets/clinic.css">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            margin: 0;
            background: linear-gradient(180deg, #eef1f8 0%, #ebeff8 100%);
            color: var(--text-primary);
            font: 12px/1.35 'Google Sans', Arial, sans-serif;
        }
        .receipt-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 26px 18px 40px;
        }
        .receipt-page-card {
            width: min(860px, 100%);
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--border-ui);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        .receipt-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 72px;
            padding: 16px 22px;
            background: linear-gradient(90deg, #1d225d 0%, #2e3192 42%, #3942a8 100%);
            color: #fff;
        }
        .receipt-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            letter-spacing: .01em;
        }
        .receipt-brand-mark {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,.12);
            font-size: 18px;
            font-weight: 800;
        }
        .receipt-brand-name {
            font-size: 15px;
            line-height: 1.25;
        }
        .receipt-status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.2);
            background: rgba(255,255,255,.12);
            color: #fff;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .receipt-body {
            padding: 22px 24px 26px;
        }
        .receipt-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 18px;
            margin-top: 18px;
        }
        .receipt-meta {
            display: grid;
            gap: 8px;
        }
        .meta-row {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 8px;
            font-size: 12px;
            line-height: 1.4;
        }
        .meta-row .meta-label {
            color: var(--text-secondary);
            font-weight: 700;
        }
        .meta-row .meta-value {
            color: var(--primary);
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .meta-row .meta-value .doctor-phone {
            display: inline-block;
            margin-top: 6px;
            color: var(--primary);
            font-weight: 600;
        }
        .receipt-table-wrap {
            margin-top: 18px;
            border: 1px solid var(--border-ui);
            border-radius: 14px;
            overflow: hidden;
        }
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-table th,
        .receipt-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-ui);
            text-align: left;
            font-size: 12px;
        }
        .receipt-table th {
            background: var(--surface-secondary);
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text-secondary);
        }
        .receipt-table td.num,
        .receipt-table th.num {
            text-align: right;
            white-space: nowrap;
        }
        .receipt-table tr:last-child td {
            border-bottom: 0;
        }
        .receipt-totals {
            margin-top: 18px;
            padding-top: 10px;
            border-top: 1px solid var(--border-ui);
            display: grid;
            gap: 10px;
        }
        .receipt-total-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .receipt-total-row strong {
            color: var(--text-primary);
        }
        .receipt-total-row.balance {
            padding-top: 8px;
            border-top: 1px dashed var(--border-ui);
        }
        .receipt-notice {
            margin-top: 12px;
            padding: 8px 10px;
            border-radius: 10px;
            background: var(--warning-soft);
            color: var(--warning);
            font-size: 11.5px;
            font-weight: 600;
        }
        .receipt-foot {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px solid var(--border-ui);
            color: var(--text-muted);
            font-size: 11px;
        }
        .toolbar a, .toolbar button {
            color: var(--primary);
            font-size: 12px;
            text-decoration: none;
        }
        .toolbar button {
            border: 0;
            border-radius: 10px;
            padding: 9px 16px;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }
        .toolbar button:disabled {
            background: var(--neutral-soft);
            color: var(--text-muted);
            cursor: not-allowed;
            box-shadow: none;
        }
        @media (max-width: 780px) {
            .receipt-summary {
                grid-template-columns: 1fr;
            }
            .meta-row {
                grid-template-columns: 108px 1fr;
            }
            .receipt-topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        @media print {
            body {
                background: #fff;
            }
            .toolbar, .no-print {
                display: none !important;
            }
            .receipt-shell {
                padding: 0;
            }
            .receipt-page-card {
                width: 100%;
                border: none;
                box-shadow: none;
                border-radius: 0;
            }
            .receipt-body {
                padding: 16mm 18mm 18mm;
            }
        }
        .toolbar {
            max-width: 210mm;
            margin: 18px auto 12px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar a, .toolbar button {
            color: #1f3c88;
            font-size: 12px;
            text-decoration: none;
        }
        .toolbar button {
            border: 0;
            border-radius: 4px;
            padding: 9px 16px;
            background: #1f3c88;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
        }
        .toolbar button:disabled { background: #d7d9e0; color: #5f6677; cursor: not-allowed; }
        .prescription-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 28px;
            padding: 14mm 16mm 16mm;
            background: #ffffff;
            border: 1px solid #555;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.02);
            position: relative;
        }
        .rx-sheet-inner {
            display: block;
            width: 100%;
        }
        .clinic-header {
            display: flex;
            align-items: center;
            gap: 18px;
            padding-bottom: 10px;
            border-bottom: 1.5px solid #3a3a3a;
        }
        .clinic-logo {
            width: 118px;
            min-width: 118px;
            height: 118px;
            object-fit: contain;
            display: block;
        }
        .clinic-name-group {
            flex: 1;
            min-width: 0;
        }
        .clinic-name {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: 0.02em;
            line-height: 1.1;
            margin: 0;
            white-space: nowrap;
            text-transform: uppercase;
        }
        .brand-tarey { color: #1f3c88; }
        .brand-derma {
            background: linear-gradient(90deg, #f6c14e 0%, #f38b2a 30%, #e14d2c 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .brand-clinic { color: #1f3c88; }
        .clinic-address, .clinic-phone {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 14px;
            color: #1a1a1a;
            line-height: 1.5;
            margin-top: 2px;
        }
        .clinic-phone {
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .rx-meta-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 20px;
            margin-top: 14px;
            padding-top: 4px;
        }
        .rx-meta-column {
            display: grid;
            gap: 6px;
        }
        .rx-meta-row {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr);
            align-items: start;
            gap: 8px;
            min-height: 22px;
        }
        .rx-meta-row span {
            font-weight: 700;
            color: #1a1a1a;
            font-size: 12px;
        }
        .rx-meta-row strong {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 12px;
            display: block;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .rx-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            table-layout: fixed;
            border: 1px solid #555;
        }
        .rx-table th, .rx-table td {
            border: 1px solid #555;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
            color: #1a1a1a;
        }
        .rx-table th {
            background: #f4f4f4;
            font-size: 11px;
            font-weight: 700;
            text-transform: none;
            letter-spacing: .01em;
            color: #1a1a1a;
        }
        .rx-table td {
            font-size: 12px;
            height: 30px;
        }
        .rx-table th:nth-child(1), .rx-table td:nth-child(1) { width: 7%; text-align: center; }
        .rx-table th:nth-child(2), .rx-table td:nth-child(2) { width: 40%; }
        .rx-table th:nth-child(3), .rx-table td:nth-child(3) { width: 16%; text-align: center; }
        .rx-table th:nth-child(4), .rx-table td:nth-child(4) { width: 18%; text-align: center; }
        .rx-table th:nth-child(5), .rx-table td:nth-child(5) { width: 19%; text-align: center; }
        .rx-signature {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 26px;
            font-size: 12px;
            color: #1a1a1a;
            font-weight: 600;
        }
        .rx-signature .signature-line {
            display: inline-block;
            min-width: 220px;
            border-bottom: 1px solid #1a1a1a;
            height: 1.1em;
            flex: 1;
            max-width: 380px;
        }
        @media (max-width: 780px) {
            body { background: #f3f4f7; }
            .prescription-sheet {
                width: calc(100vw - 24px);
                min-height: 0;
                padding: 18px 16px 20px;
                margin: 0 auto 20px;
            }
            .clinic-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .clinic-logo {
                width: 84px;
                height: 84px;
            }
            .clinic-name {
                white-space: normal;
                font-size: 21px;
            }
            .rx-meta-wrap {
                grid-template-columns: 1fr;
            }
            .rx-meta-row { grid-template-columns: 100px 1fr; }
        }
        @media print {
            body { background: #fff; }
            .toolbar, .no-print { display: none !important; }
            .prescription-sheet {
                width: 100%;
                min-height: 0;
                padding: 18mm 16mm 16mm;
                border: 1px solid #555;
                box-shadow: none;
                margin: 0;
            }
            .clinic-name { font-size: 22px; }
            .rx-signature .signature-line { max-width: 290px; }
            .rx-table td, .rx-table th { font-size: 11px; }
        }
    </style>
    <link rel="stylesheet" href="assets/print-theme.css">
    <style>
        /* Keep patient and prescription details as a readable two-column ledger. */
        .rx-meta-wrap.receipt-summary {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
            gap: 18px !important;
            padding: 16px 18px !important;
            border: 1px solid var(--tdc-line) !important;
            border-radius: 12px !important;
            background: var(--tdc-indigo-soft) !important;
            color: var(--tdc-ink) !important;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif !important;
        }
        .rx-meta-wrap .rx-meta-column.receipt-meta {
            display: grid !important;
            gap: 0 !important;
            align-content: start;
        }
        .rx-meta-wrap .rx-meta-row.meta-row {
            display: grid !important;
            grid-template-columns: minmax(112px, auto) minmax(0, 1fr) !important;
            align-items: start;
            gap: 10px !important;
            min-height: 0 !important;
            padding: 7px 0 !important;
            border-bottom: 1px solid var(--tdc-line);
            font-family: "Segoe UI", Arial, Helvetica, sans-serif !important;
        }
        .rx-meta-wrap .rx-meta-row.meta-row:last-child { border-bottom: 0; }
        .rx-meta-wrap .meta-label {
            color: var(--tdc-muted) !important;
            font-size: 10px !important;
            font-weight: 750 !important;
            letter-spacing: .04em;
            text-transform: uppercase;
            text-align: left !important;
        }
        .rx-meta-wrap .meta-value {
            min-width: 0;
            color: var(--tdc-indigo) !important;
            font-size: 12px !important;
            font-weight: 650 !important;
            line-height: 1.4 !important;
            text-align: left !important;
            overflow-wrap: anywhere;
        }
        .rx-meta-wrap .meta-value br { line-height: 1.55; }
        .rx-meta-wrap .doctor-phone {
            margin-top: 4px !important;
            color: var(--tdc-indigo) !important;
        }
        @media (max-width: 700px) {
            .rx-meta-wrap.receipt-summary { grid-template-columns: 1fr !important; }
        }
    </style>
</head>
<body>
<div class="receipt-shell">
<?php if ($rx): ?>
    <div class="toolbar no-print">
        <a href="pages/pharmacy.php?section=pos&amp;view_mode=history">← Sales History</a>
        <button type="button" <?= $printable ? 'onclick="window.print()"' : 'disabled' ?>>Print A4</button>
    </div>
    <?php if (!$printable): ?><p class="print-unavailable" style="display:none"><?= $unpriced ? 'Receipt unavailable until Pharmacy pricing is completed.' : 'Cancelled prescription: financial receipt unavailable.' ?></p><?php endif; ?>
    <main class="prescription-sheet receipt-page-card">
        <div class="receipt-body">
            <header class="clinic-header" style="padding-bottom: 12px; border-bottom: 1px solid var(--border-ui);">
                <?php if ($logo !== ''): ?>
                    <img class="clinic-logo" src="<?= $e($logo) ?>" alt="Clinic logo">
                <?php elseif (is_file(__DIR__ . '/uploads/tareydermacliniclogo.png')): ?>
                    <img class="clinic-logo" src="uploads/tareydermacliniclogo.png" alt="Clinic logo">
                <?php endif; ?>
                <div class="clinic-name-group">
                    <div class="clinic-name">
                        <span class="brand-tarey">TAREY</span>
                        <span class="brand-derma">DERMA</span>
                        <span class="brand-clinic">CLINIC</span>
                    </div>
                    <div class="clinic-address"><?= $e($companyAddress) ?></div>
                    <div class="clinic-phone"><?= $e($companyPhone) ?></div>
                </div>
            </header>

            <section class="rx-meta-wrap receipt-summary">
                <div class="rx-meta-column receipt-meta">
                    <div class="rx-meta-row meta-row"><span class="meta-label">Patient ID:</span><strong class="meta-value"><?= $e((string)($patient['PatientID'] ?? $head['PatientID'] ?? '—')) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Patient Name:</span><strong class="meta-value"><?= $e((string)$patientName) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Phone:</span><strong class="meta-value"><?= $e((string)($patientPhone !== '' ? $patientPhone : 'Not recorded')) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Doctor:</span><strong class="meta-value"><?= $e((string)$doctorName) ?><?php if ($doctorTitle !== '' || $doctorSpecialty !== ''): ?><br><?= $e($doctorTitle !== '' ? $doctorTitle : $doctorSpecialty) ?><?php endif; ?><br><span class="doctor-phone">Telephone: <?= $e($doctorPhone) ?></span></strong></div>
                </div>
                <div class="rx-meta-column receipt-meta">
                    <div class="rx-meta-row meta-row"><span class="meta-label">Visit Number:</span><strong class="meta-value"><?= $e((string)($visitReference !== '—' ? $visitReference : ($visitNumber !== '—' ? $visitNumber : '—'))) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Prescription Number:</span><strong class="meta-value"><?= $e((string)($pNo !== '' ? $pNo : '—')) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Gender:</span><strong class="meta-value"><?= $e((string)($patientGender !== '' ? $patientGender : '—')) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Age:</span><strong class="meta-value"><?= $e((string)($patientAge !== '' ? $patientAge : '—')) ?></strong></div>
                    <div class="rx-meta-row meta-row"><span class="meta-label">Date:</span><strong class="meta-value"><?= $e(date('d/m/Y', strtotime((string)$prescriptionDate))) ?></strong></div>
                </div>
            </section>

            <div class="receipt-table-wrap">
                <table class="rx-table receipt-table" aria-label="Prescription medication list">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Drug</th>
                            <th class="num">Quantity</th>
                            <th class="num">Frequency</th>
                            <th class="num">Route</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $idx => $line): ?>
                            <tr>
                                <td><?= (int)$idx + 1 ?></td>
                                <td><?= $e((string)($line['MedicationName'] ?? $line['ItemName'] ?? '—')) ?><?php if (trim((string)($line['Instructions'] ?? '')) !== ''): ?><div style="font-size:11px;color:#555;margin-top:3px;word-break:break-word;"><?= $e((string)$line['Instructions']) ?></div><?php endif; ?></td>
                                <td class="num"><?= $e((string)($line['Quantity'] ?? '—')) ?></td>
                                <td class="num"><?= $e((string)($line['Frequency'] ?? '—')) ?></td>
                                <td class="num"><?= $e((string)($line['Route'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="rx-signature">
                <span>Signature:</span>
                <span class="signature-line" aria-hidden="true"></span>
            </div>
        </div>
    </main>
<?php else: ?>
    <div class="toolbar no-print">
        <a href="pages/pharmacy.php?section=pos&amp;view_mode=history">← Sales History</a>
        <button type="button" <?= $printable ? 'onclick="window.print()"' : 'disabled' ?>>Print A4</button>
        <?php if (!$voided && $due > 0): ?><a href="pages/pharmacy.php?section=pos&amp;view=<?= rawurlencode($reference) ?>">Record Payment</a><?php endif; ?>
    </div>
    <?php if (!$printable): ?><p class="print-unavailable" style="display:none"><?= $unpriced ? 'Receipt unavailable until Pharmacy pricing is completed.' : 'Cancelled prescription: financial receipt unavailable.' ?></p><?php endif; ?>
    <main class="prescription-sheet receipt-page-card receipt-paper">
        <div class="receipt-body">
            <header class="clinic-header" style="padding-bottom:12px; border-bottom:1px solid var(--border-ui);">
                <?php if ($logo !== ''): ?><img class="clinic-logo" src="<?= $e($logo) ?>" alt="Clinic logo">
                <?php elseif (is_file(__DIR__ . '/uploads/tareydermacliniclogo.png')): ?><img class="clinic-logo" src="uploads/tareydermacliniclogo.png" alt="Clinic logo"><?php endif; ?>
                <div class="clinic-name-group"><div class="clinic-name"><span class="brand-tarey">TAREY</span> <span class="brand-derma">DERMA</span> <span class="brand-clinic">CLINIC</span></div><div class="clinic-address"><?= $e($companyAddress) ?></div><div class="clinic-phone"><?= $e($companyPhone) ?></div></div>
            </header>
            <h2 style="margin:18px 0 12px; font-size:18px; color:var(--text-primary);"><?= $voided ? 'Void Details / Receipt' : 'POS Receipt' ?></h2>
            <section class="rx-meta-wrap receipt-summary">
                <div class="rx-meta-column receipt-meta">
                    <div class="meta-row"><span class="meta-label">Sale Reference:</span><strong class="meta-value"><?= $e($reference) ?></strong></div>
                    <div class="meta-row"><span class="meta-label">Customer:</span><strong class="meta-value"><?= $e($patientName) ?></strong></div>
                    <?php if ($patient): ?><div class="meta-row"><span class="meta-label">Patient ID:</span><strong class="meta-value"><?= (int)$patient['PatientID'] ?></strong></div><?php endif; ?>
                    <div class="meta-row"><span class="meta-label">Phone:</span><strong class="meta-value"><?= $e((string)($patientPhone !== '' ? $patientPhone : 'Not recorded')) ?></strong></div>
                </div>
                <div class="rx-meta-column receipt-meta">
                    <div class="meta-row"><span class="meta-label">Status:</span><strong class="meta-value"><?= $e($status) ?></strong></div>
                    <div class="meta-row"><span class="meta-label">Date:</span><strong class="meta-value"><?= $e(date('d/m/Y', strtotime((string)($head['SaleDate'] ?? date('Y-m-d'))))) ?></strong></div>
                    <?php if ($visit): ?><div class="meta-row"><span class="meta-label">Visit Number:</span><strong class="meta-value"><?= $e((string)$visitReference) ?></strong></div><?php endif; ?>
                    <div class="meta-row"><span class="meta-label">Cashier:</span><strong class="meta-value"><?= $e($cashier) ?></strong></div>
                </div>
            </section>
            <?php if ($unpriced): ?><p class="receipt-notice">Receipt unavailable until Pharmacy pricing is completed.</p><?php endif; ?>
            <div class="receipt-table-wrap">
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th class="num">Quantity</th>
                            <th class="num">Unit Price</th>
                            <th class="num">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <?php $qty = (int)($line['Quantity'] ?? 0); $unit = (float)($line['UnitPrice'] ?? 0); $sub = (float)($line['LineTotal'] ?? 0); ?>
                            <tr>
                                <td><?= $e((string)($line['ItemName'] ?? '—')) ?></td>
                                <td class="num"><?= $qty ?></td>
                                <td class="num"><?= $money($unit) ?></td>
                                <td class="num"><?= $money($sub) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <section class="receipt-totals">
                <div class="receipt-total-row"><span>Total</span><strong><?= $money($total) ?></strong></div>
                <div class="receipt-total-row"><span>Paid (confirmed, net)</span><strong><?= $money($paid) ?></strong></div>
                <div class="receipt-total-row balance"><span><?= $voided ? 'Balance after void' : ($due > 0 ? 'Outstanding Balance' : 'Due') ?></span><strong><?= $money($voided ? 0 : $due) ?></strong></div>
                <div class="receipt-total-row"><span>Payment Status</span><strong><?= $e($status) ?></strong></div>
            </section>
            <h2 style="margin:22px 0 12px; font-size:17px; color:var(--text-primary);">Payment History</h2>
            <?php if (!$payments): ?>
                <p class="muted" style="margin:0; color:var(--text-muted);">No confirmed payments recorded for this reference.</p>
            <?php else: ?>
                <div class="receipt-table-wrap">
                    <table class="receipt-table">
                        <thead>
                            <tr>
                                <th>Date / Payment ID</th>
                                <th>Method</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= $e((string)$payment['PaidAt']) ?><div style="margin-top:4px; color:var(--text-muted); font-size:11px;">#<?= (int)$payment['PaymentID'] ?></div></td>
                                    <td><?= $e((string)$payment['PaymentMethod']) ?></td>
                                    <td class="num"><?= $money($payment['Amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <footer class="receipt-foot">Keep this receipt for your records.</footer>
        </div>
    </main>
<?php endif; ?>
</div>
</body>
</html>
