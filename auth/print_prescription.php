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
    // Select * so the slip still renders when the optional Quantity/Route
    // migration has not been applied (columns detected below, never assumed).
    $stmt = $pdo->prepare(
        'SELECT *
         FROM prescriptions
         WHERE PrescriptionID = :exact OR PrescriptionID LIKE :pattern
         ORDER BY PrescriptionID ASC'
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
$companyLogo = (string) ($letterhead['CompanyLogo'] ?? '');

$totals = ['total' => 0.0, 'paid' => 0.0, 'due' => 0.0];
foreach ($lines as $l) {
    $totals['total'] = round($totals['total'] + (float) $l['TotalAmount'], 2);
    $totals['paid']  = round($totals['paid'] + (float) $l['AmountPaid'], 2);
    $totals['due']   = round($totals['due'] + (float) $l['DueBalance'], 2);
}

$printedOn = date('d M Y, H:i');
$hasQty    = array_key_exists('Quantity', $first) && $first['Quantity'] !== null && $first['Quantity'] !== '';
$hasRoute  = array_key_exists('Route', $first);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription <?= tdc_print_e($baseRef) ?> — <?= tdc_print_e($companyName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #1c2333; background: #f3f4f8; padding: 24px 12px; }
        .sheet { max-width: 780px; margin: 0 auto; background: #ffffff; padding: 34px 40px; border: 1px solid #d7dae4; }
        .letterhead { display: flex; align-items: center; gap: 18px; border-bottom: 3px solid #2E3192; padding-bottom: 16px; }
        .letterhead img { width: 84px; height: 84px; object-fit: contain; }
        .letterhead .lh-name { font-size: 24px; font-weight: 700; color: #2E3192; }
        .letterhead .lh-meta { font-size: 12px; color: #55607a; margin-top: 4px; }
        .slip-title { text-align: center; margin: 18px 0 14px; font-size: 15px; letter-spacing: .12em; text-transform: uppercase; color: #2E3192; font-weight: 700; }
        .parties { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 18px; font-size: 13px; }
        .parties strong { display: block; color: #55607a; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #d7dae4; padding: 7px 9px; text-align: left; vertical-align: top; }
        th { background: #eef0f7; color: #2E3192; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 16px; margin-left: auto; width: 280px; font-size: 13px; }
        .totals div { display: flex; justify-content: space-between; padding: 5px 2px; }
        .totals .grand { border-top: 2px solid #2E3192; font-weight: 700; color: #2E3192; }
        .sig { margin-top: 56px; display: flex; justify-content: space-between; font-size: 12px; color: #55607a; }
        .sig span { border-top: 1px solid #9aa2b8; padding-top: 5px; min-width: 170px; text-align: center; }
        .foot { margin-top: 22px; font-size: 11px; color: #7c859d; text-align: center; }
        .print-bar { max-width: 780px; margin: 0 auto 14px; text-align: right; }
        .print-bar button { background: #2E3192; color: #fff; border: 0; padding: 9px 20px; font-size: 13px; cursor: pointer; }
        .print-bar button:hover { background: #F15A24; }
        @media print {
            body { background: #ffffff; padding: 0; }
            .print-bar { display: none; }
            .sheet { border: 0; max-width: none; }
        }
    </style>
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

    <div class="parties">
        <div>
            <strong>Patient</strong>
            <?= tdc_print_e($first['PatientName']) ?><br>
            <?php if ((string) $first['PatientPhone'] !== ''): ?>Phone: <?= tdc_print_e((string) $first['PatientPhone']) ?><br><?php endif; ?>
            <?php $genderAge = trim(implode(' / ', array_filter([(string) $first['Gender'], (string) $first['Age']]))); ?>
            <?php if ($genderAge !== ''): ?><?= tdc_print_e($genderAge) ?><?php endif; ?>
        </div>
        <div style="text-align:right">
            <strong>Bill reference</strong>
            <?= tdc_print_e($baseRef) ?><br>
            <strong>Date</strong><br>
            <?= tdc_print_e(date('d M Y', strtotime((string) $first['PrescriptionDate']))) ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Medication</th>
                <?php if ($hasQtyRoute): ?><th class="num">Qty</th><th>Route</th><?php endif; ?>
                <th>Dosage</th>
                <th>Frequency</th>
                <th>Duration</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $i => $l): ?>
            <tr>
                <td class="num"><?= (int) $i + 1 ?></td>
                <td>
                    <?= tdc_print_e((string) $l['MedicationName']) ?>
                    <?php if ((string) ($l['Instructions'] ?? '') !== ''): ?>
                        <br><small style="color:#55607a"><?= tdc_print_e((string) $l['Instructions']) ?></small>
                    <?php endif; ?>
                </td>
                <?php if ($hasQty): ?>
                <td class="num"><?= (int) $l['Quantity'] ?></td>
                <?php endif; ?>
                <?php if ($hasRoute): ?>
                <td><?= tdc_print_e((string) ($l['Route'] ?? '')) ?></td>
                <?php endif; ?>
                <td><?= tdc_print_e((string) ($l['Dosage'] ?? '')) ?></td>
                <td><?= tdc_print_e((string) ($l['Frequency'] ?? '')) ?></td>
                <td><?= tdc_print_e((string) ($l['Duration'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Total</span><strong><?= number_format($totals['total'], 2) ?></strong></div>
        <div><span>Amount paid</span><span><?= number_format($totals['paid'], 2) ?></span></div>
        <div class="grand"><span>Balance due</span><span><?= number_format($totals['due'], 2) ?></span></div>
    </div>

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
