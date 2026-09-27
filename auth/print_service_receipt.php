<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/ui.php';
tdc_require_access();
tdc_require_permission('reception.view');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/billing-adjustments.php';

$ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['ref'] ?? ''));
$q = $pdo->prepare('SELECT a.*, p.PatientName, p.PatientPhone, p.PatientAddress, p.Gender, p.Age, d.DoctorName, s.ServiceName, c.CategoryName FROM service_assignments a JOIN patients p ON p.PatientID = a.PatientID LEFT JOIN doctors d ON d.DoctorID = a.DoctorID JOIN service_subservices s ON s.ServiceID = a.ServiceID JOIN service_categories c ON c.ServiceCategoryID = s.ServiceCategoryID WHERE a.ServiceReference = ?');
$q->execute([$ref]);
$a = $q->fetch();
if (!$a) {
    http_response_code(404);
    exit('Service receipt not found.');
}

$paymentsQuery = $pdo->prepare("SELECT PaymentReference, Amount, PaymentMethod, PaidAt FROM payments WHERE PaymentType = 'Service' AND ServiceAssignmentID = ? AND PaymentStatus = 'Confirmed' ORDER BY PaidAt");
$paymentsQuery->execute([(int)$a['AssignmentID']]);
$payments = $paymentsQuery->fetchAll();
$h = $pdo->query('SELECT CompanyName, PhoneNumbers, CompanyAddress, CompanyLogo FROM prescriptionsheader LIMIT 1')->fetch() ?: [];
$name = $h['CompanyName'] ?? 'Tarey Derma Clinic';
$logo = trim((string)($h['CompanyLogo'] ?? ''));
$logo = $logo !== '' ? $logo : 'uploads/tareydermacliniclogo.png';
$patientName = ucwords(strtolower((string)$a['PatientName']));
$doctorName = ucwords(strtolower((string)($a['DoctorName'] ?? 'Unassigned')));
$total = (float)$a['ServiceAmount'];
$paid = (float)$a['AmountPaid'];
$due = (float)$a['DueBalance'];
$adjustment = tdc_load_bill_adjustment($pdo, 'Service', (string)$a['ServiceReference']);
$gross = $adjustment ? (float)$adjustment['GrossAmount'] : $total;
$discount = $adjustment ? (float)$adjustment['DiscountAmount'] : 0.0;
$subtotal = $adjustment ? max(0.0, $gross - $discount) : $total;
$tax = $adjustment ? (float)$adjustment['TaxAmount'] : 0.0;
$final = $adjustment ? (float)$adjustment['FinalAmount'] : $total;
$status = ucfirst(strtolower((string)$a['PaymentStatus']));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Service Receipt <?= htmlspecialchars($ref) ?></title>
<style>
@page { size: A4; margin: 0; }
:root { --navy:#252a8f; --orange:#f36b2b; --ink:#10236d; --muted:#5e6b8f; --line:#d6dcef; --soft:#f5f7fc; }
* { box-sizing:border-box; }
body { margin:0; background:#f1f3f8; color:var(--ink); font-family:Arial,Helvetica,sans-serif; font-size:13px; }
.toolbar { text-align:center; padding:14px 0; }
.btn { border:0; border-radius:7px; background:var(--navy); color:#fff; font-weight:700; font-size:14px; padding:12px 24px; cursor:pointer; }
.sheet { width:210mm; min-height:297mm; margin:12px auto 30px; padding:18mm; background:#fff; border:1px solid #d4daea; border-radius:18px; box-shadow:0 12px 30px rgba(26,38,95,.08); }
.head { display:flex; align-items:center; gap:18px; padding:0 0 18px; border-bottom:4px solid var(--navy); }
.head img { width:68px; height:68px; object-fit:contain; }
.clinic { font-family:Georgia,serif; font-size:28px; font-weight:700; letter-spacing:.2px; color:var(--navy); }
.clinic::first-letter { color:var(--navy); }
.sub { margin-top:5px; color:#26365f; font-size:13px; }
.receipt-label { margin-left:auto; text-align:right; color:var(--navy); font-weight:700; letter-spacing:1px; font-size:14px; }
.receipt-label span { display:block; color:var(--muted); font-size:11px; font-weight:400; letter-spacing:1.4px; margin-bottom:4px; }
.title { margin:22px 0 16px; font-size:21px; font-weight:700; color:#14265f; }
.meta { display:grid; grid-template-columns:1fr 1fr; gap:0 34px; padding:16px 18px; background:var(--soft); border:1px solid var(--line); border-radius:12px; }
.meta div { display:flex; gap:8px; padding:6px 0; line-height:1.35; }
.meta b { min-width:92px; color:var(--navy); }
.section-title { margin:22px 0 8px; font-size:16px; color:#14265f; }
table { width:100%; border-collapse:collapse; }
th { background:#eef1f8; color:var(--navy); text-align:left; font-weight:700; }
th, td { border:1px solid #bfc8df; padding:11px 12px; }
.amount { width:150px; text-align:right; }
.financial { margin-top:18px; display:grid; grid-template-columns:repeat(4,1fr); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
.financial div { padding:13px 14px; border-right:1px solid var(--line); background:#fff; }
.financial div:last-child { border-right:0; }
.financial small { display:block; color:var(--muted); margin-bottom:5px; }
.financial strong { font-size:17px; color:var(--navy); }
.status { color:#16844b !important; }
.history td, .history th { padding:9px 10px; }
.sign { margin-top:42px; padding-top:14px; border-top:1px solid var(--line); width:55%; color:#192a61; }
.footer { margin-top:34px; padding-top:12px; border-top:1px solid var(--line); color:var(--muted); font-size:11px; text-align:center; }
@media print { body { background:#fff; } .toolbar { display:none; } .sheet { margin:0; width:210mm; min-height:297mm; border:0; border-radius:0; box-shadow:none; } }
</style>
</head>
<body>
<div class="toolbar"><button class="btn" type="button" onclick="window.print()">Print A4</button></div>
<main class="sheet">
<header class="head">
    <img src="<?= htmlspecialchars($logo) ?>" alt="Clinic logo">
    <div>
        <div class="clinic"><?= htmlspecialchars($name) ?></div>
        <div class="sub"><?= htmlspecialchars((string)($h['CompanyAddress'] ?? '')) ?> · Telephone: <?= htmlspecialchars((string)($h['PhoneNumbers'] ?? '')) ?></div>
    </div>
    <div class="receipt-label"><span>SERVICE</span>PAYMENT RECEIPT</div>
</header>
<h1 class="title">Service Billing Receipt</h1>
<section class="meta">
    <div><b>Reference:</b><span><?= htmlspecialchars((string)$a['ServiceReference']) ?></span></div>
    <div><b>Date:</b><span><?= htmlspecialchars(date('d/m/Y', strtotime((string)$a['AssignedAt']))) ?></span></div>
    <div><b>Patient:</b><span><?= htmlspecialchars($patientName) ?></span></div>
    <div><b>Patient ID:</b><span><?= htmlspecialchars((string)$a['PatientID']) ?></span></div>
    <div><b>Phone:</b><span><?= htmlspecialchars((string)($a['PatientPhone'] ?: 'Not recorded')) ?></span></div>
    <div><b>Doctor:</b><span><?= htmlspecialchars($doctorName) ?></span></div>
    <div><b>Category:</b><span><?= htmlspecialchars((string)$a['CategoryName']) ?></span></div>
    <div><b>Service:</b><span><?= htmlspecialchars((string)$a['ServiceName']) ?></span></div>
</section>
<h2 class="section-title">Service details</h2>
<table>
    <thead><tr><th>Description</th><th class="amount">Amount</th></tr></thead>
    <tbody><tr><td><?= htmlspecialchars((string)$a['CategoryName'] . ' / ' . (string)$a['ServiceName']) ?></td><td class="amount"><?= number_format($gross, 2) ?></td></tr></tbody>
</table>
<section class="financial">
    <div><small>Gross Amount</small><strong><?= number_format($gross, 2) ?></strong></div>
    <div><small>Discount</small><strong><?= number_format($discount, 2) ?></strong></div>
    <div><small>Subtotal</small><strong><?= number_format($subtotal, 2) ?></strong></div>
    <div><small>Tax</small><strong><?= number_format($tax, 2) ?></strong></div>
    <div><small>Final Amount</small><strong><?= number_format($final, 2) ?></strong></div>
    <div><small>Paid</small><strong><?= number_format($paid, 2) ?></strong></div>
    <div><small>Balance Due</small><strong><?= number_format($due, 2) ?></strong></div>
    <div><small>Status</small><strong class="status"><?= htmlspecialchars($status) ?></strong></div>
</section>
<?php if ($payments): ?>
<h2 class="section-title">Payment history</h2>
<table class="history">
    <thead><tr><th>Reference</th><th>Method</th><th>Date</th><th class="amount">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
    <tr><td><?= htmlspecialchars((string)$p['PaymentReference']) ?></td><td><?= htmlspecialchars((string)($p['PaymentMethod'] ?? '')) ?></td><td><?= htmlspecialchars((string)$p['PaidAt']) ?></td><td class="amount"><?= number_format((float)$p['Amount'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<div class="sign">Authorized signature: ______________________________</div>
<div class="footer">Thank you for choosing <?= htmlspecialchars($name) ?>.</div>
</main>
</body>
</html>
