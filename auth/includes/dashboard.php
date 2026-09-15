<?php
$dashboardRole = (string) $_SESSION['role'];
$dashboardScalar = static function (string $sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
$dashboardEscape = static function ($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$dashboardIcon = static function (string $name): string {
    $paths = [
        'patients' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'lab' => '<path d="M9 3h6M10 3v6.4l-5.3 8.9A1.8 1.8 0 0 0 6.25 21h11.5a1.8 1.8 0 0 0 1.55-2.7L14 9.4V3"/><path d="M7.5 16h9"/>',
        'receipt' => '<path d="M6 2h9l4 4v16H6z"/><path d="M14 2v5h5M9 12h7M9 16h7"/>',
        'cart' => '<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.5 11h11l2-7H6"/>',
        'package' => '<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="m3 8 9 5 9-5v9l-9 5-9-5Z"/><path d="M12 13v9"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'pill' => '<path d="m10.5 20.5-7-7a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z"/><path d="m8 11 7 7"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['chart']) . '</svg>';
};

$metrics = [];
$workLinks = [];
$dashboardRows = [];
$activityValues = [];
$activityLabels = [];
$revenueRows = [];
$dashboardError = false;

try {
    if ($dashboardRole === 'superuser' || tdc_can('reception.view')) {
        $patientUrl = $dashboardRole === 'superuser' ? 'patients.php' : 'reception.php?section=patients';
        $metrics = [
            ['Registered today', $dashboardScalar('SELECT COUNT(*) FROM Patients WHERE DATE(RegisteredAt) = CURDATE()'), $patientUrl, 'Patient registrations', 'user-plus', 'blue'],
            ['Total patients', $dashboardScalar('SELECT COUNT(*) FROM Patients'), $patientUrl, 'Registered patients', 'patients', 'green'],
            ['Awaiting lab payment', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus <> 'Paid'"), 'reception.php?section=laboratory', 'Unpaid or partially paid', 'receipt', 'orange'],
            ['Ready for laboratory', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus = 'Paid' AND Result = 'Pending'"), $dashboardRole === 'superuser' ? 'laboratory.php?result=Pending&payment=Paid' : 'reception.php?section=laboratory', 'Paid, awaiting results', 'lab', 'purple'],
        ];
        $workLinks = [
            ['Patient Registration', 'Register new patient', 'reception.php?section=patients', 'user-plus', 'blue'],
            ['Laboratory Bills', 'Order and manage tests', 'reception.php?section=laboratory', 'lab', 'purple'],
            ['Pharmacy Bills', 'Create prescription bills', 'reception.php?section=pharmacy', 'pill', 'rose'],
        ];
        $dashboardTitle = 'Recent registrations';
        $dashboardColumns = ['Patient', 'Phone', 'Registered', 'Visits'];
        foreach ($pdo->query('SELECT PatientName, PatientPhone, RegisteredAt, VisitNumber FROM Patients ORDER BY RegisteredAt DESC LIMIT 8')->fetchAll() as $row) {
            $dashboardRows[] = [$row['PatientName'], $row['PatientPhone'], date('d M Y', strtotime($row['RegisteredAt'])), $row['VisitNumber']];
        }
        if ($dashboardRole === 'superuser') {
            $metrics[] = ['Sales today', $dashboardScalar("SELECT COUNT(DISTINCT SUBSTRING_INDEX(SaleID, '-', 1)) FROM PharmacySales WHERE DATE(SaleDate) = CURDATE()"), 'pharmacy.php?section=pos', 'Pharmacy transactions', 'cart', 'rose'];
            $metrics[] = ['Low stock', $dashboardScalar('SELECT COUNT(*) FROM Inventory WHERE QuantityInStock <= ReorderLevel'), 'pharmacy.php?section=inventory&low=1', 'At or below reorder level', 'package', 'orange'];
            $metrics[] = ['Users', $dashboardScalar('SELECT COUNT(*) FROM users'), 'setup.php?section=users', 'System accounts', 'users', 'teal'];
            $metrics[] = ['Net income this month', number_format((float) $dashboardScalar("SELECT COALESCE(SUM(Credit-Debit), 0) FROM Accounting WHERE AccountType IN ('Revenue','Expense') AND TransactionDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND TransactionDate < CURDATE() + INTERVAL 1 DAY"), 2), 'reports.php?section=income-statement', 'Posted revenue less expenses', 'chart', 'purple'];
            $workLinks[] = ['Point of Sale', 'Sell pharmacy products', 'pharmacy.php?section=pos', 'cart', 'green'];
            $workLinks[] = ['Manage Users', 'Maintain staff accounts', 'setup.php?section=users', 'users', 'blue'];
            $workLinks[] = ['Financial Reports', 'Review financial statements', 'reports.php', 'chart', 'teal'];
            $activityMap = [];
            foreach ($pdo->query("SELECT DATE(RegisteredAt) day, COUNT(*) total FROM Patients WHERE RegisteredAt >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(RegisteredAt)")->fetchAll() as $row) {
                $activityMap[$row['day']] = (int) $row['total'];
            }
            for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
                $day = date('Y-m-d', strtotime("-$daysAgo days"));
                $activityLabels[] = date('M j', strtotime($day));
                $activityValues[] = $activityMap[$day] ?? 0;
            }
            $revenueRows = $pdo->query("SELECT AccountName label, SUM(Credit-Debit) total FROM Accounting WHERE AccountType='Revenue' AND TransactionDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND TransactionDate < CURDATE() + INTERVAL 1 DAY GROUP BY AccountName HAVING total > 0 ORDER BY total DESC LIMIT 5")->fetchAll();
        }
    } elseif (tdc_can('doctor.workspace')) {
        $doctorStmt = $pdo->prepare('SELECT DoctorID FROM Doctors WHERE UserID = ?');
        $doctorStmt->execute([$_SESSION['user_id']]);
        $doctorId = (int) $doctorStmt->fetchColumn();
        $doctorFilter = $doctorId > 0 ? ' AND DoctorID = ' . $doctorId : ' AND 1 = 0';
        $metrics = [
            ['Waiting today', $dashboardScalar("SELECT COUNT(*) FROM Visits WHERE DATE(VisitDate)=CURDATE() AND QueueStatus='Waiting'{$doctorFilter}"), 'doctors.php?queue=waiting', 'Patients ready for consultation', 'patients', 'blue'],
            ['In consultation', $dashboardScalar("SELECT COUNT(*) FROM Visits WHERE QueueStatus='In Consultation'{$doctorFilter}"), 'doctors.php?queue=active', 'Open consultation records', 'user-plus', 'purple'],
            ['Completed today', $dashboardScalar("SELECT COUNT(*) FROM Visits WHERE DATE(CompletedAt)=CURDATE() AND QueueStatus='Completed'{$doctorFilter}"), 'doctors.php?queue=completed', 'Consultations completed', 'chart', 'green'],
            ['Lab results ready', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE WorkflowStatus='Completed' AND ReviewedAt IS NULL{$doctorFilter}"), 'doctors.php?queue=results', 'Results awaiting review', 'lab', 'orange'],
        ];
        $workLinks = [
            ['Today\'s Bookings', 'Open consultation queue', 'doctors.php', 'patients', 'blue'],
            ['Lab Results', 'Review completed results', 'doctors.php?queue=results', 'lab', 'purple'],
        ];
        $dashboardTitle = 'Today\'s consultation queue';
        $dashboardColumns = ['Visit', 'Patient', 'Time', 'Status'];
        if ($doctorId > 0) {
            $stmt = $pdo->prepare("SELECT v.VisitReference,p.PatientName,v.VisitDate,v.QueueStatus FROM Visits v JOIN Patients p ON p.PatientID=v.PatientID WHERE v.DoctorID=? AND DATE(v.VisitDate)=CURDATE() ORDER BY v.VisitDate");
            $stmt->execute([$doctorId]);
            foreach ($stmt->fetchAll() as $row) $dashboardRows[] = [$row['VisitReference'],$row['PatientName'],date('H:i',strtotime($row['VisitDate'])),$row['QueueStatus']];
        }
    } elseif (tdc_can('pharmacy.view')) {
        $sales = "SELECT MIN(TotalAmount) total, MIN(AmountPaid) paid, MIN(DueBalance) due, MIN(SaleDate) sold FROM PharmacySales GROUP BY SUBSTRING_INDEX(SaleID, '-', 1)";
        $metrics = [
            ['Sales today', $dashboardScalar("SELECT COUNT(*) FROM ($sales) s WHERE DATE(sold) = CURDATE()"), 'pharmacy.php?section=pos', 'Pharmacy transactions', 'cart', 'blue'],
            ['Sales value today', number_format((float) $dashboardScalar("SELECT COALESCE(SUM(total),0) FROM ($sales) s WHERE DATE(sold) = CURDATE()"), 2), 'pharmacy.php?section=pos', 'Total sales value', 'chart', 'green'],
            ['Collected today', number_format((float) $dashboardScalar("SELECT COALESCE(SUM(paid),0) FROM ($sales) s WHERE DATE(sold) = CURDATE()"), 2), 'pharmacy.php?section=pos', 'Payments collected', 'receipt', 'teal'],
            ['Outstanding sales', $dashboardScalar("SELECT COUNT(*) FROM ($sales) s WHERE due > 0"), 'pharmacy.php?section=pos', 'Sales with a balance', 'receipt', 'orange'],
            ['Pending prescriptions', $dashboardScalar("SELECT COUNT(DISTINCT SUBSTRING_INDEX(PrescriptionID, '-', 1)) FROM Prescriptions WHERE Status='Pending'"), 'pharmacy.php?section=prescriptions', 'Doctor prescriptions to dispense', 'pill', 'purple'],
            ['Low stock', $dashboardScalar('SELECT COUNT(*) FROM Inventory WHERE QuantityInStock <= ReorderLevel'), 'pharmacy.php?section=inventory&low=1', 'Items requiring attention', 'package', 'orange'],
        ];
        $workLinks = [['Pending Prescriptions', 'Dispense doctor orders', 'pharmacy.php?section=prescriptions', 'pill', 'purple'], ['New Sale', 'Open point of sale', 'pharmacy.php?section=pos&new=1', 'cart', 'green'], ['Inventory', 'Manage medicine stock', 'pharmacy.php?section=inventory', 'package', 'orange'], ['Purchases', 'Receive supplier stock', 'pharmacy.php?section=purchases', 'receipt', 'blue']];
        $dashboardTitle = 'Recent sales';
        $dashboardColumns = ['Receipt', 'Customer', 'Total', 'Payment'];
        foreach ($pdo->query("SELECT SUBSTRING_INDEX(SaleID, '-', 1) ref, MIN(CustomerName) customer, MIN(TotalAmount) total, MIN(PaymentStatus) payment FROM PharmacySales GROUP BY ref ORDER BY MIN(SaleDate) DESC LIMIT 8")->fetchAll() as $row) {
            $dashboardRows[] = [$row['ref'], $row['customer'], number_format((float) $row['total'], 2), $row['payment']];
        }
    } elseif (tdc_can('laboratory.view')) {
        $metrics = [
            ['Ready for testing', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus = 'Paid' AND Result = 'Pending'"), 'laboratory.php?result=Pending', 'Paid orders awaiting results', 'lab', 'purple'],
            ['Orders today', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus = 'Paid' AND DATE(OrderDate) = CURDATE()"), 'laboratory.php', 'Paid orders placed today', 'receipt', 'blue'],
            ['Completed today', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus = 'Paid' AND Result <> 'Pending' AND DATE(ResultDate) = CURDATE()"), 'laboratory.php', 'Results recorded today', 'chart', 'green'],
            ['Sent out', $dashboardScalar("SELECT COUNT(*) FROM Laboratory WHERE PaymentStatus = 'Paid' AND Result = 'Pending' AND IsAvailable = 0"), 'laboratory.php?result=Pending', 'Pending external tests', 'lab', 'orange'],
        ];
        $workLinks = [['Receive Lab Patients', 'Open paid pending orders', 'laboratory.php?result=Pending', 'lab', 'purple'], ['Laboratory Records', 'Review all laboratory work', 'laboratory.php', 'receipt', 'blue']];
        $dashboardTitle = 'Patients awaiting results';
        $dashboardColumns = ['Order', 'Patient', 'Test', 'Ordered'];
        foreach ($pdo->query("SELECT l.LaboratoryID, p.PatientName, l.TestName, l.OrderDate FROM Laboratory l JOIN Patients p ON p.PatientID = l.PatientID WHERE l.PaymentStatus = 'Paid' AND l.Result = 'Pending' ORDER BY l.OrderDate ASC LIMIT 8")->fetchAll() as $row) {
            $dashboardRows[] = [$row['LaboratoryID'], $row['PatientName'], $row['TestName'], date('d M Y', strtotime($row['OrderDate']))];
        }
    } else {
        $metrics = [
            ['Accounting', 'Open', $dashboardRole === 'superuser' || tdc_can('accounting.view') ? 'accounting.php' : 'home.php', 'Financial records', 'receipt', 'blue'],
            ['Reports', 'Open', tdc_can('reports.view') ? 'reports.php' : 'home.php', 'Authorized reporting', 'chart', 'green'],
        ];
        $workLinks = [];
        $dashboardTitle = 'Authorized workspace';
        $dashboardColumns = ['Access'];
    }
} catch (PDOException $exception) {
    error_log('[DASHBOARD] ' . $exception->getMessage());
    $dashboardError = true;
}
?>
<?php if ($dashboardError): ?>
<div class="empty-state" role="alert"><strong>Dashboard unavailable</strong><span>Please refresh to try again.</span></div>
<?php else: ?>
<div class="dashboard-metrics">
    <?php foreach ($metrics as [$label, $value, $href, $detail, $icon, $tone]): ?>
    <a class="dashboard-metric" href="<?= $dashboardEscape($href) ?>"><span class="metric-icon tone-<?= $tone ?>"><?= $dashboardIcon($icon) ?></span><span class="metric-content"><span class="metric-label"><?= $dashboardEscape($label) ?></span><strong><?= $dashboardEscape($value) ?></strong><small><?= $dashboardEscape($detail) ?></small></span></a>
    <?php endforeach; ?>
</div>
<?php if ($dashboardRole === 'superuser'): ?>
<div class="dashboard-charts">
    <section class="chart-card">
        <div class="panel-heading"><div><h2>Patient activity</h2><p>Registrations during the last 30 days</p></div><span class="panel-period">Last 30 days</span></div>
        <?php if (array_sum($activityValues) > 0): ?>
        <?php $maxActivity = max($activityValues) ?: 1; $points = []; foreach ($activityValues as $index => $value) { $points[] = round(28 + ($index * (544 / 29)), 2) . ',' . round(150 - (($value / $maxActivity) * 112), 2); } ?>
        <div class="line-chart" role="img" aria-label="Patient registrations over the last 30 days"><svg viewBox="0 0 600 190" preserveAspectRatio="none"><path class="chart-grid" d="M28 38H572M28 75H572M28 112H572M28 150H572"/><polyline class="chart-line" points="<?= implode(' ', $points) ?>"/><?php foreach ($points as $point): [$cx, $cy] = explode(',', $point); ?><circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="3"/><?php endforeach; ?></svg><div class="chart-axis"><span><?= $dashboardEscape($activityLabels[0]) ?></span><span><?= $dashboardEscape($activityLabels[14]) ?></span><span><?= $dashboardEscape($activityLabels[29]) ?></span></div></div>
        <?php else: ?><div class="empty-state compact"><strong>No activity data</strong><span>Patient registrations will appear here.</span></div><?php endif; ?>
    </section>
    <section class="chart-card">
        <div class="panel-heading"><div><h2>Revenue overview</h2><p>Posted revenue by account this month</p></div><span class="panel-period">This month</span></div>
        <?php if ($revenueRows): $maxRevenue = max(array_map(static fn($row) => (float) $row['total'], $revenueRows)); ?>
        <div class="bar-chart" role="img" aria-label="Revenue by account this month"><?php foreach ($revenueRows as $index => $row): ?><div class="bar-column"><strong><?= number_format((float) $row['total'], 0) ?></strong><span class="bar tone-<?= ['blue','green','purple','rose','teal'][$index] ?>" style="height:<?= max(12, round(((float) $row['total'] / $maxRevenue) * 112)) ?>px"></span><small><?= $dashboardEscape($row['label']) ?></small></div><?php endforeach; ?></div>
        <?php else: ?><div class="empty-state compact"><strong>No revenue data</strong><span>Posted revenue will appear here.</span></div><?php endif; ?>
    </section>
</div>
<?php endif; ?>
<section class="dashboard-section" aria-label="Quick actions">
    <div class="panel-heading"><div><h2>Quick actions</h2><p>Common tasks for your role</p></div></div>
    <div class="dashboard-links"><?php foreach ($workLinks as [$label, $detail, $href, $icon, $tone]): ?><a href="<?= $dashboardEscape($href) ?>"><span class="action-icon tone-<?= $tone ?>"><?= $dashboardIcon($icon) ?></span><span><strong><?= $dashboardEscape($label) ?></strong><small><?= $dashboardEscape($detail) ?></small></span></a><?php endforeach; ?></div>
</section>
<section class="dashboard-section recent-panel">
    <div class="panel-heading"><div><h2><?= $dashboardEscape($dashboardTitle) ?></h2><p>Latest activity in the system</p></div></div>
    <?php if (!$dashboardRows): ?><div class="empty-state compact"><strong>No records yet</strong><span>New activity will appear here.</span></div><?php else: ?><div class="data-table-wrap"><table class="dashboard-table"><thead><tr><?php foreach ($dashboardColumns as $column): ?><th scope="col"><?= $dashboardEscape($column) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($dashboardRows as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= $dashboardEscape($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
