<?php
$waitingFilters = ['workspace'=>'1','tab'=>$waitingSchedule ? 'schedule' : 'waiting','q'=>$waitingSearch,'from_date'=>$waitingFrom,'to_date'=>$waitingTo,'per_page'=>$waitingPerPage];
?>
<section class="waiting-screen">
    <nav class="setup-section-nav no-print" aria-label="Clinical work"><a href="doctors.php?workspace=1" <?= !$waitingSchedule ? 'class="active" aria-current="page"' : '' ?>>Patient Waiting</a><a href="doctors.php?workspace=1&amp;tab=schedule" <?= $waitingSchedule ? 'class="active" aria-current="page"' : '' ?>>My Schedule</a></nav>
    <h2><?= $waitingSchedule ? 'Scheduled visits' : 'Doctor patient waiting' ?></h2>
    <?php if ($waitingSchedule): ?><p class="welcome-sub">Visits for the selected dates<?= $superadminDoctorMode ? ' across all doctors' : ' assigned to you' ?>.</p><?php endif; ?>
    <?php if ($waitingDateError): ?><div class="error-msg" role="alert"><?= tdc_e($waitingDateError) ?></div><?php endif; ?>
    <form method="get" class="waiting-toolbar no-print">
        <input type="hidden" name="workspace" value="1">
        <input type="hidden" name="tab" value="<?= $waitingSchedule ? 'schedule' : 'waiting' ?>">
        <label>Show <select name="per_page"><?php foreach ([10,25,50,100] as $size): ?><option <?= $size === $waitingPerPage ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select> entries</label>
        <label>From Date <input type="date" name="from_date" value="<?= tdc_e($waitingFrom) ?>"></label>
        <label>To Date <input type="date" name="to_date" value="<?= tdc_e($waitingTo) ?>"></label>
        <label class="waiting-search">Search <input type="search" name="q" value="<?= tdc_e($waitingSearch) ?>" placeholder="Search by appointment, patient, phone..."></label>
        <button class="btn btn-primary">Apply</button>
        <?= tdc_export_buttons(['csv'=>'doctors.php?'.http_build_query($waitingFilters + ['export'=>'csv'])]) ?>
    </form>
    <div class="data-table-wrap"><table class="data-table">
        <thead><tr><th>Visit ID</th><th>Patient</th><th>Gender</th><th>Age</th><th>Phone</th><th>Date Added</th><th>Doctor</th><th>Status</th><th class="no-print">Actions</th></tr></thead>
        <tbody><?php foreach ($waitingRows as $v): ?>
            <tr><td><?= tdc_e($v['VisitReference']) ?></td><td><?= tdc_e($v['PatientName']) ?></td><td><?= tdc_e($v['Gender']) ?></td><td><?= tdc_e((string)$v['Age']) ?></td><td><?= tdc_e($v['PatientPhone']) ?></td><td><?= tdc_e($v['VisitDate']) ?></td><td><?= tdc_e($v['DoctorName']) ?></td>
                <td><?= tdc_badge($v['QueueStatus']) ?></td>
                <td class="no-print"><div class="row-actions">
                    <a class="icon-action waiting-open" href="doctors.php?visit=<?= (int)$v['VisitID'] ?>" title="Open consultation" aria-label="Open consultation"><?= tdc_icon('eye',16) ?></a>
                    <?php if ($v['QueueStatus'] === 'Waiting' && $v['PaymentStatus'] === 'Paid' && tdc_can('consultations.edit')): ?>
                    <form method="post" action="doctors.php?visit=<?= (int)$v['VisitID'] ?>"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$v['VisitID'] ?>"><input type="hidden" name="portal_action" value="start"><button class="icon-action waiting-start" title="Start consultation" aria-label="Start consultation"><?= tdc_icon('stethoscope',16) ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$waitingRows): ?><tr><td colspan="9">No visits match these filters.</td></tr><?php endif; ?></tbody>
    </table></div>
    <?= tdc_pager($waitingPage,$waitingPerPage,$waitingTotal,$waitingFilters) ?>
</section>
