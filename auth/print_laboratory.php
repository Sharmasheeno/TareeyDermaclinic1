<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_start();
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/ui.php';
tdc_require_access();

if (!tdc_can('lab_billing.view') && !tdc_can('laboratory.view') && !tdc_can('patients.view') && !tdc_can('patients.history') && !(isset($_GET['result']) && tdc_can('laboratory.results.view'))) {
    tdc_forbidden();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/billing-adjustments.php';
if (($_GET['result']??'')==='1') {
    require __DIR__.'/includes/lab-result-print.php';
    exit;
}

function tdc_lab_print_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$labId = trim((string) ($_GET['ref'] ?? ''));
if ($labId === '' || !preg_match('/^[A-Za-z0-9_-]{2,50}$/', $labId)) {
    http_response_code(404);
    exit('Laboratory receipt not found.');
}

try {
    $hasLabVisitId = tdc_has_column($pdo, 'laboratory', 'VisitID');
    $hasLabDoctorId = tdc_has_column($pdo, 'laboratory', 'DoctorID');
    $hasItemClinicalResult = tdc_has_column($pdo, 'laborderitems', 'ClinicalResult');
    $hasItemResultDate = tdc_has_column($pdo, 'laborderitems', 'ResultDate');
    $hasPaymentMethod = tdc_has_column($pdo, 'payments', 'PaymentMethod');
    $hasPaymentStatus = tdc_has_column($pdo, 'payments', 'PaymentStatus');
    $hasPaymentType = tdc_has_column($pdo, 'payments', 'PaymentType');
    $visitJoin = $hasLabVisitId ? ' LEFT JOIN visits v ON v.VisitID = l.VisitID' : '';
    $doctorJoin = $hasLabDoctorId ? ' LEFT JOIN doctors d ON d.DoctorID = l.DoctorID' : '';
    $visitSelect = $hasLabVisitId ? 'v.VisitReference' : "'' AS VisitReference";
    $doctorSelect = $hasLabDoctorId ? 'd.DoctorName' : "'' AS DoctorName";
    $stmt = $pdo->prepare(
        'SELECT l.*, p.PatientName, p.PatientPhone, p.Gender, p.Age, p.DateOfBirth,
                ' . $visitSelect . ', ' . $doctorSelect . '
         FROM laboratory l
         JOIN patients p ON p.PatientID = l.PatientID
         ' . $visitJoin . $doctorJoin . '
         WHERE l.LaboratoryID = ? LIMIT 1'
    );
    $stmt->execute([$labId]);
    $bill = $stmt->fetch();
    if (!$bill) {
        http_response_code(404);
        exit('Laboratory receipt not found.');
    }

    $itemClinicalSelect = $hasItemClinicalResult ? 'ClinicalResult' : "'' AS ClinicalResult";
    $itemDateSelect = $hasItemResultDate ? 'ResultDate' : 'NULL AS ResultDate';
    $itemsStmt = $pdo->prepare('SELECT TestName, UnitPrice, Result, ' . $itemClinicalSelect . ', ' . $itemDateSelect . ' FROM laborderitems WHERE LaboratoryID=? ORDER BY LabOrderItemID');
    $itemsStmt->execute([$labId]);
    $items = $itemsStmt->fetchAll();

    $paymentMethodSelect = $hasPaymentMethod ? 'PaymentMethod' : "'' AS PaymentMethod";
    $paymentTypeFilter = $hasPaymentType ? " AND PaymentType='Laboratory'" : '';
    $paymentStatusFilter = $hasPaymentStatus ? " AND PaymentStatus='Confirmed'" : '';
    $paymentsStmt = $pdo->prepare('SELECT PaymentReference, ' . $paymentMethodSelect . ', Amount, PaidAt FROM payments WHERE LaboratoryID=?' . $paymentTypeFilter . $paymentStatusFilter . ' ORDER BY PaidAt, PaymentID');
    $paymentsStmt->execute([$labId]);
    $payments = $paymentsStmt->fetchAll();

    $letterhead = $pdo->query('SELECT CompanyName, PhoneNumbers, CompanyAddress, CompanyLogo FROM prescriptionsheader LIMIT 1')->fetch() ?: [];
} catch (PDOException $e) {
    error_log('[PRINT LABORATORY] ' . $e->getMessage());
    http_response_code(500);
    exit('A system error occurred. Please try again later.');
}

$total = round((float) ($bill['TotalAmount'] ?? 0), 2);
$paid = round((float) ($bill['AmountPaid'] ?? 0), 2);
$due = round((float) ($bill['DueBalance'] ?? max(0, $total - $paid)), 2);
$adjustment = tdc_load_bill_adjustment($pdo, 'laboratory', $labId);
$gross = $adjustment ? (float)$adjustment['GrossAmount'] : $total;
$discount = $adjustment ? (float)$adjustment['DiscountAmount'] : 0.0;
$subtotal = max(0.0, $gross - $discount);
$tax = $adjustment ? (float)$adjustment['TaxAmount'] : 0.0;
$final = $adjustment ? (float)$adjustment['FinalAmount'] : $total;
$companyName = (string) ($letterhead['CompanyName'] ?? 'Tarey Derma Clinic');
$companyAddress = (string) ($letterhead['CompanyAddress'] ?? '');
$companyPhone = (string) ($letterhead['PhoneNumbers'] ?? '');
$companyLogo = (string) ($letterhead['CompanyLogo'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Receipt <?= tdc_lab_print_e($labId) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f3f8;color:#172033;font-family:Arial,Helvetica,sans-serif;padding:20px}.print-bar{max-width:210mm;margin:0 auto 12px;text-align:right}.print-bar button{background:#2e3192;color:#fff;border:0;padding:10px 18px;cursor:pointer}.sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:18mm;border:1px solid #d7dae4}.head{display:flex;gap:18px;align-items:center;border-bottom:3px solid #2e3192;padding-bottom:14px}.head img{width:72px;height:72px;object-fit:contain}.clinic{font-size:24px;font-weight:700;color:#2e3192}.sub{font-size:12px;color:#5c667b;margin-top:4px}.title{text-align:center;color:#2e3192;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin:22px 0 18px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;margin-bottom:20px;font-size:13px}.meta div{border-bottom:1px solid #e2e5ec;padding:6px 0}.meta strong{display:inline-block;min-width:130px;color:#59647a;font-size:11px;text-transform:uppercase}.table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}.table th,.table td{border:1px solid #cfd4df;padding:9px;vertical-align:top}.table th{background:#eef0f7;color:#2e3192;text-align:left;font-size:11px;text-transform:uppercase}.table th:nth-child(1),.table td:nth-child(1){width:7%;text-align:center}.table th:nth-child(2),.table td:nth-child(2){width:35%}.table th:nth-child(3),.table td:nth-child(3){width:16%;text-align:right}.table th:nth-child(4),.table td:nth-child(4){width:17%;text-align:center}.table th:nth-child(5),.table td:nth-child(5){width:25%}.financial{width:300px;margin:22px 0 0 auto;font-size:13px}.financial div{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #e0e3ea}.financial .due{font-weight:700;color:#2e3192;border-top:2px solid #2e3192}.note{margin-top:20px;padding:12px;background:#f7f8fb;border-left:3px solid #2e3192;font-size:12px}.sign{display:flex;justify-content:space-between;margin-top:70px;color:#5c667b;font-size:12px}.sign span{border-top:1px solid #9aa2b8;padding-top:6px;width:180px;text-align:center}.foot{text-align:center;color:#7b8497;font-size:11px;margin-top:28px}@media print{body{background:#fff;padding:0}.print-bar{display:none}.sheet{width:100%;min-height:auto;border:0;padding:12mm}}
</style>
<link rel="stylesheet" href="assets/print-theme.css">
</head>
<body>
<div class="print-bar"><button type="button" onclick="window.print()">Print this receipt</button></div>
<main class="sheet">
<header class="head">
<?php if ($companyLogo !== ''): ?><img src="<?= tdc_lab_print_e($companyLogo) ?>" alt="Clinic logo"><?php elseif (is_file(__DIR__ . '/uploads/tareydermacliniclogo.png')): ?><img src="uploads/tareydermacliniclogo.png" alt="Clinic logo"><?php endif; ?>
<div><div class="clinic"><?= tdc_lab_print_e($companyName) ?></div><div class="sub"><?= tdc_lab_print_e($companyAddress) ?><?= $companyAddress !== '' && $companyPhone !== '' ? ' · ' : '' ?><?= $companyPhone !== '' ? 'Tel: ' . tdc_lab_print_e($companyPhone) : '' ?></div></div>
</header>
<div class="title">Laboratory Bill &amp; Payment Receipt</div>
<section class="meta">
<div><strong>Laboratory ID</strong><?= tdc_lab_print_e($labId) ?></div>
<div><strong>Order date</strong><?= tdc_lab_print_e(date('d M Y, H:i', strtotime((string) $bill['OrderDate']))) ?></div>
<div><strong>Patient ID</strong><?= (int) $bill['PatientID'] ?></div>
<div><strong>Patient</strong><?= tdc_lab_print_e((string) $bill['PatientName']) ?></div>
<div><strong>Phone</strong><?= tdc_lab_print_e((string) ($bill['PatientPhone'] ?: 'Not recorded')) ?></div>
<div><strong>Doctor</strong><?= tdc_lab_print_e((string) ($bill['DoctorName'] ?: '—')) ?></div>
<div><strong>Visit</strong><?= tdc_lab_print_e((string) ($bill['VisitReference'] ?: '—')) ?></div>
<div><strong>Payment status</strong><?= tdc_lab_print_e((string) $bill['PaymentStatus']) ?></div>
</section>
<table class="table"><thead><tr><th>#</th><th>Laboratory test</th><th>Price</th><th>Result</th><th>Clinical result</th></tr></thead><tbody>
<?php if (!$items): ?><tr><td>1</td><td><?= tdc_lab_print_e((string) $bill['TestName']) ?></td><td><?= number_format($total, 2) ?></td><td><?= tdc_lab_print_e((string) $bill['Result']) ?></td><td><?= tdc_lab_print_e((string) ($bill['ClinicalResult'] ?: '—')) ?></td></tr>
<?php else: foreach ($items as $index => $item): ?><tr><td><?= $index + 1 ?></td><td><?= tdc_lab_print_e((string) $item['TestName']) ?></td><td><?= number_format((float) $item['UnitPrice'], 2) ?></td><td><?= tdc_lab_print_e((string) $item['Result']) ?></td><td><?= tdc_lab_print_e((string) ($item['ClinicalResult'] ?: '—')) ?></td></tr><?php endforeach; endif; ?>
</tbody></table>
<?php if ((string) ($bill['Description'] ?? '') !== ''): ?><div class="note"><strong>Clinical request:</strong> <?= tdc_lab_print_e((string) $bill['Description']) ?></div><?php endif; ?>
<section class="financial"><div><span>Gross Amount</span><strong><?= number_format($gross, 2) ?></strong></div><div><span>Discount</span><span><?= number_format($discount, 2) ?></span></div><div><span>Subtotal</span><span><?= number_format($subtotal, 2) ?></span></div><div><span>Tax</span><span><?= number_format($tax, 2) ?></span></div><div><span>Final Amount</span><strong><?= number_format($final, 2) ?></strong></div><div><span>Paid</span><span><?= number_format($paid, 2) ?></span></div><div class="due"><span>Balance Due</span><span><?= number_format($due, 2) ?></span></div></section>
<?php if ($payments): ?><div class="note"><strong>Payment references:</strong> <?php foreach ($payments as $index => $payment): ?><?= $index ? ', ' : '' ?><?= tdc_lab_print_e((string) $payment['PaymentReference']) ?> (<?= tdc_lab_print_e((string) $payment['PaymentMethod']) ?>, <?= number_format((float) $payment['Amount'], 2) ?>)<?php endforeach; ?></div><?php endif; ?>
<div class="sign"><span>Patient signature</span><span>Authorized signature</span></div>
<div class="foot">Printed <?= tdc_lab_print_e(date('d M Y, H:i')) ?> · <?= tdc_lab_print_e($companyName) ?></div>
</main>
</body>
</html>
