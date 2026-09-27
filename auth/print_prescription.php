<?php
declare(strict_types=1);
/**
 * auth/print_prescription.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Printable Pharmacy Bill / Prescription Slip
 * ---------------------------------------------------------------------
 * Read-only print view targeted by the "Print" buttons on
 * auth/pages/reception.php and auth/pages/patients.php:
 *
 *     auth/print_prescription.php?ref=RX000123
 *
 * One pharmacy bill groups several medication lines by sharing the
 * same PrescriptionID base ("RX000123-01", "-02", ... — the convention
 * documented in reception.php). The slip prints the clinic letterhead
 * from PrescriptionsHeader and every line whose PrescriptionID starts
 * with that base. Optional Quantity / Route columns are printed only
 * when the migration that adds them has been run (they are detected,
 * never assumed).
 *
 * No writes happen here: authentication, role authorization and output
 * escaping follow the same posture as the rest of the app.
 * ---------------------------------------------------------------------
 */

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/ui.php';
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

/** Escapes a value for safe HTML output. */
function tdc_print_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// View permission: pharmacy billing staff, the pharmacy itself, or
// clinical history viewers (mirrors where the Print buttons appear).
if (!tdc_can('pharmacy_billing.view')
    && !tdc_can('pharmacy.prescriptions.view')
    && !tdc_can('patients.view')
    && !tdc_can('patients.history')) {
    tdc_forbidden();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/billing-adjustments.php';

$rawRef = trim((string) ($_GET['ref'] ?? ''));
if ($rawRef === '' || !preg_match('/^[A-Za-z0-9]{2,20}(-[A-Za-z0-9]{1,10})?$/', $rawRef)) {
    http_response_code(404);
    exit('Print reference not found.');
}

// Normalize a full line reference ("RX000123-01") to its bill base.
$baseRef = strtok($rawRef, '-');
if ($baseRef === false || $baseRef === '') {
    http_response_code(404);
    exit('Print reference not found.');
}

try {
    $hasRouteColumn = tdc_has_column($pdo, 'prescriptions', 'Route');
    $routeSelect = $hasRouteColumn ? ', p.Route AS Route' : '';
    $stmt = $pdo->prepare(
        'SELECT p.*' . $routeSelect . ',
                pt.PatientName AS CurrentPatientName,
                pt.PatientPhone AS CurrentPatientPhone,
                pt.Gender AS CurrentGender,
                pt.Age AS CurrentAge,
                d.DoctorName AS DoctorName
         FROM prescriptions p
         LEFT JOIN patients pt ON pt.PatientID = p.PatientID
         LEFT JOIN doctors d ON d.DoctorID = p.DoctorID
         WHERE p.PrescriptionID = :exact OR p.PrescriptionID LIKE :pattern
         ORDER BY p.PrescriptionID ASC'
    );
    $stmt->execute(['exact' => $baseRef, 'pattern' => $baseRef . '-%']);
    $lines = $stmt->fetchAll();

    if (!$lines) {
        http_response_code(404);
        exit('Print reference not found.');
    }

    $letterheadStmt = $pdo->query(
        'SELECT CompanyName, PhoneNumbers, CompanyAddress, CompanyLogo
         FROM prescriptionsheader LIMIT 1'
    );
    $letterhead = $letterheadStmt->fetch() ?: [];
} catch (PDOException $e) {
    error_log('[PRINT PRESCRIPTION] ' . $e->getMessage());
    http_response_code(500);
    exit('A system error occurred. Please try again later.');
}

$first       = $lines[0];
$companyName = (string) ($letterhead['CompanyName'] ?? 'Tarey Derma Clinic');
$companyAddr = (string) ($letterhead['CompanyAddress'] ?? '');
$companyTel  = (string) ($letterhead['PhoneNumbers'] ?? '');
$doctorPhone = preg_replace('/^\s*TEL\s*:\s*/i', '', $companyTel) ?: $companyTel;
$companyLogo = (string) ($letterhead['CompanyLogo'] ?? '');

$displayName = static function (string $value): string {
    $value = trim($value);
    return mb_strtoupper($value, 'UTF-8');
};
$displayPatientName = $displayName((string) ($first['CurrentPatientName'] ?? $first['PatientName'] ?? '—'));
$displayDoctorName = $displayName((string) ($first['DoctorName'] ?? ''));
$displayPhone = trim((string) ($first['CurrentPatientPhone'] ?? ''));
$displayGender = trim((string) ($first['CurrentGender'] ?? $first['Gender'] ?? ''));
$displayAge = trim((string) ($first['CurrentAge'] ?? $first['Age'] ?? ''));

$totals = [
    'total' => round((float) ($first['TotalAmount'] ?? 0), 2),
    'paid'  => round((float) ($first['AmountPaid'] ?? 0), 2),
    'due'   => round((float) ($first['DueBalance'] ?? 0), 2),
];
$adjustment = tdc_load_bill_adjustment($pdo, 'prescription', $baseRef);
$gross = $adjustment ? (float)$adjustment['GrossAmount'] : $totals['total'];
$discount = $adjustment ? (float)$adjustment['DiscountAmount'] : 0.0;
$subtotal = max(0.0, $gross - $discount);
$tax = $adjustment ? (float)$adjustment['TaxAmount'] : 0.0;
$final = $adjustment ? (float)$adjustment['FinalAmount'] : $totals['total'];

if ($totals['total'] <= 0.00001) {
    http_response_code(403);
    exit('Prescription billing is not finalized until Pharmacy records the pricing.');
}

$printedOn = date('d M Y, H:i');
$hasQty    = array_key_exists('Quantity', $first);
$hasRoute  = $hasRouteColumn && array_key_exists('Route', $first);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription <?= tdc_print_e($baseRef) ?> — <?= tdc_print_e($companyName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { margin: 0; background: #f1f3f8; color: #172033; font-family: Arial, Helvetica, sans-serif; padding: 20px; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; background: #fff; padding: 18mm; border: 1px solid #d7dae4; }
        .letterhead { display: flex; gap: 18px; align-items: center; border-bottom: 3px solid #2e3192; padding-bottom: 14px; }
        .letterhead img { width: 72px; height: 72px; object-fit: contain; }
        .letterhead .lh-name { font-size: 24px; font-weight: 700; color: #2e3192; }
        .letterhead .lh-meta { font-size: 12px; color: #5c667b; margin-top: 4px; }
        .slip-title { text-align: center; color: #2e3192; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; margin: 22px 0 18px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 30px; margin-bottom: 20px; font-size: 13px; }
        .meta div { border-bottom: 1px solid #e2e5ec; padding: 6px 0; line-height: 1.4; }
        .meta strong { display: inline-block; min-width: 130px; color: #59647a; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .table th, .table td { border: 1px solid #cfd4df; padding: 9px; vertical-align: top; }
        .table th { background: #eef0f7; color: #2e3192; text-align: left; font-size: 11px; text-transform: uppercase; }
        .table th:nth-child(1), .table td:nth-child(1) { width: 7%; text-align: center; }
        .table th:nth-child(2), .table td:nth-child(2) { width: 31%; }
        .table th:nth-child(3), .table td:nth-child(3) { width: 15%; text-align: right; }
        .table th:nth-child(4), .table td:nth-child(4) { width: 17%; }
        .table th:nth-child(5), .table td:nth-child(5) { width: 17%; }
        .table th:nth-child(6), .table td:nth-child(6) { width: 13%; }
        .financial { width: 300px; margin: 22px 0 0 auto; font-size: 13px; }
        .financial div { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #e0e3ea; }
        .financial .due { font-weight: 700; color: #2e3192; border-top: 2px solid #2e3192; }
        .sign { display: flex; justify-content: space-between; margin-top: 70px; color: #5c667b; font-size: 12px; }
        .sign span { border-top: 1px solid #9aa2b8; padding-top: 6px; width: 180px; text-align: center; }
        .foot { text-align: center; color: #7b8497; font-size: 11px; margin-top: 28px; }
        .print-bar { max-width: 210mm; margin: 0 auto 12px; text-align: right; }
        .print-bar button { background: #2e3192; color: #fff; border: 0; padding: 10px 18px; cursor: pointer; }
        @media print { body { background: #fff; padding: 0; } .print-bar { display: none; } .sheet { width: 100%; min-height: auto; border: 0; padding: 12mm; } }
    </style>
    <link rel="stylesheet" href="assets/print-theme.css">
</head>
<body>
<div class="print-bar">
    <button type="button" onclick="window.print()">Print this slip</button>
</div>
<div class="sheet">
    <div class="letterhead">
        <?php if ($companyLogo !== ''): ?>
            <img src="<?= tdc_print_e($companyLogo) ?>" alt="Clinic logo">
        <?php elseif (is_file(__DIR__ . '/uploads/tareydermacliniclogo.png')): ?>
            <img src="uploads/tareydermacliniclogo.png" alt="Clinic logo">
        <?php endif; ?>
        <div>
            <div class="lh-name"><?= tdc_print_e($companyName) ?></div>
            <div class="lh-meta">
                <?= tdc_print_e($companyAddr) ?><?php if ($companyAddr !== '' && $companyTel !== ''): ?> &middot; <?php endif; ?>
                <?php if ($companyTel !== ''): ?>Tel: <?= tdc_print_e($companyTel) ?><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="slip-title">Prescription &amp; Payment Slip</div>

    <?php $genderAge = trim(implode(' / ', array_filter([$displayGender, $displayAge]))); ?>
    <section class="meta" aria-label="Patient and prescription information">
        <div><strong>Patient ID</strong><?= tdc_print_e((string) ($first['PatientID'] ?? '—')) ?></div>
        <div><strong>Prescription Number</strong><?= tdc_print_e($baseRef) ?></div>
        <div><strong>Patient</strong><?= tdc_print_e($displayPatientName) ?></div>
        <div><strong>Date</strong><?= tdc_print_e(date('d M Y', strtotime((string) $first['PrescriptionDate']))) ?></div>
        <div><strong>Phone</strong><?= tdc_print_e($displayPhone !== '' ? $displayPhone : 'Not recorded') ?></div>
        <div><strong>Visit Number</strong><?= tdc_print_e((string) (($first['VisitNumber'] ?? '') !== '' ? $first['VisitNumber'] : '—')) ?></div>
        <div><strong>Gender / Age</strong><?= tdc_print_e($genderAge !== '' ? $genderAge : '—') ?></div>
        <div><strong>Doctor</strong><?= tdc_print_e($displayDoctorName !== '' ? $displayDoctorName : '—') ?></div>
    </section>

    <table class="table">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Medication</th>
                <th class="num">Quantity</th>
                <th>Frequency</th>
                <th>Duration</th>
                <th>Route</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $i => $l): ?>
            <tr>
                <td class="num"><?= (int) $i + 1 ?></td>
                <td><?= tdc_print_e((string) $l['MedicationName']) ?></td>
                <td class="num"><?= $hasQty ? (int) $l['Quantity'] : '&mdash;' ?></td>
                <td><?= tdc_print_e((string) ($l['Frequency'] ?? '')) ?></td>
                <td><?= tdc_print_e((string) ($l['Duration'] ?? '')) ?></td>
                <td><?= $hasRoute && trim((string) ($l['Route'] ?? '')) !== '' ? tdc_print_e((string) $l['Route']) : '&mdash;' ?></td>
            </tr>
            <?php if (trim((string) ($l['Instructions'] ?? '')) !== ''): ?>
            <tr><td colspan="6"><strong>Instructions:</strong> <?= tdc_print_e((string) $l['Instructions']) ?></td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <section class="financial" aria-label="Payment summary">
        <div><span>Gross Amount</span><strong><?= number_format($gross, 2) ?></strong></div>
        <div><span>Discount</span><span><?= number_format($discount, 2) ?></span></div>
        <div><span>Subtotal</span><span><?= number_format($subtotal, 2) ?></span></div>
        <div><span>Tax</span><span><?= number_format($tax, 2) ?></span></div>
        <div><span>Final Amount</span><strong><?= number_format($final, 2) ?></strong></div>
        <div><span>Paid</span><span><?= number_format($totals['paid'], 2) ?></span></div>
        <div class="due"><span>Balance Due</span><span><?= number_format($totals['due'], 2) ?></span></div>
    </section>

    <div class="sig">
        <span>Patient signature</span>
        <span>Authorized signature</span>
    </div>

    <div class="foot">
        Printed <?= tdc_print_e($printedOn) ?> &middot; <?= tdc_print_e($companyName) ?>
    </div>
</div>
</body>
</html>
