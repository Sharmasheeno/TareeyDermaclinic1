<?php
declare(strict_types=1);

$currentPage = 'doctors.php';
$waitingCount = count(array_filter($queue, static fn(array $visit): bool => $visit['QueueStatus'] === 'Waiting'));
$activeCount = count(array_filter($queue, static fn(array $visit): bool => $visit['QueueStatus'] === 'In Consultation'));
$completedCount = count(array_filter($queue, static fn(array $visit): bool => $visit['QueueStatus'] === 'Completed'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Doctor Workspace | Tarey Derma Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/clinic.css">
    <script src="../assets/clinic.js" defer></script>
</head>
<body>
<header class="app-header" id="topnav">
    <div class="utility-bar">
        <a class="brand-chip" href="home.php" aria-label="Tarey Derma Clinic dashboard">
            <img src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic">
        </a>
        <div class="utility-right">
            <div class="nav-item" data-menu="notifications">
                <button type="button" class="icon-btn" aria-label="Notifications">
                    <svg viewBox="0 0 24 24"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z"/><path d="M9.5 21a2.5 2.5 0 005 0"/></svg>
                    <span class="badge"></span>
                </button>
                <div class="dropdown-menu notif-menu"><?php require __DIR__ . '/notifications.php'; ?></div>
            </div>
            <?php require __DIR__ . '/profile.php'; ?>
        </div>
    </div>
    <nav class="menu-bar" aria-label="Primary navigation">
        <ul class="nav-items">
            <?php foreach (tdc_navigation(NAV_ITEMS) as $item): ?>
                <li class="nav-item<?= $item['href'] === $currentPage ? ' active' : '' ?>">
                    <a href="<?= tdc_e($item['href']) ?>" class="nav-link"<?= $item['href'] === $currentPage ? ' aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 20 20"><?= $item['icon'] ?></svg>
                        <span><?= tdc_e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>

<main class="page-body doctor-workspace">
    <header class="workspace-heading">
        <div>
            <div class="welcome-eyebrow">Doctor / Workspace</div>
            <h1 class="welcome-title">Doctor Workspace</h1>
            <p class="welcome-sub"><?= $doctorProfile ? tdc_e($doctorProfile['DoctorName']) . ' · Assigned consultations and clinical work' : 'Doctor account setup required' ?></p>
        </div>
        <time class="workspace-date" datetime="<?= date('Y-m-d') ?>"><?= date('l, d F Y') ?></time>
    </header>

    <?php if ($portalErrors): ?><div class="error-msg" role="alert"><?= tdc_e(implode(' ', $portalErrors)) ?></div><?php endif; ?>

    <?php if (!$doctorProfile): ?>
        <section class="workspace-empty workspace-empty-page">
            <span class="workspace-empty-icon"><svg viewBox="0 0 24 24"><path d="M8 3v4a4 4 0 008 0V3M6 3h4M14 3h4M12 11v3a5 5 0 005 5h1M18 16a2 2 0 100 4 2 2 0 000-4z"/></svg></span>
            <strong>Doctor profile not linked</strong>
            <span>Ask a SuperAdmin to link this account to its doctor directory record.</span>
        </section>
    <?php else: ?>
        <?php if (!$selectedVisit): require __DIR__ . '/doctor-waiting-view.php'; else: ?>
        <a class="btn btn-primary no-print" href="doctors.php?workspace=1">Patient Waiting</a>
        <section class="workspace-summary" aria-label="Workspace summary">
            <div><span class="workspace-summary-icon tone-blue"><svg viewBox="0 0 24 24"><path d="M8 7a4 4 0 108 0 4 4 0 00-8 0zM5 21a7 7 0 0114 0M4 3v4M2 5h4"/></svg></span><span class="workspace-summary-copy"><span>Waiting today</span><strong><?= $waitingCount ?></strong><small>Patients ready for consultation</small></span></div>
            <div><span class="workspace-summary-icon tone-purple"><svg viewBox="0 0 24 24"><path d="M8 7a4 4 0 108 0 4 4 0 00-8 0zM5 21a7 7 0 0114 0M19 8v6M16 11h6"/></svg></span><span class="workspace-summary-copy"><span>In consultation</span><strong><?= $activeCount ?></strong><small>Open clinical encounters</small></span></div>
            <div><span class="workspace-summary-icon tone-green"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span><span class="workspace-summary-copy"><span>Completed today</span><strong><?= $completedCount ?></strong><small>Consultations completed</small></span></div>
            <div><span class="workspace-summary-icon tone-orange"><svg viewBox="0 0 24 24"><path d="M9 3h6M10 3v6l-5 8a2 2 0 001.7 3h10.6a2 2 0 001.7-3l-5-8V3M8 15h8"/></svg></span><span class="workspace-summary-copy"><span>Lab results ready</span><strong><?= count($readyResults) ?></strong><small>Results awaiting review</small></span></div>
        </section>

        <div class="doctor-workspace-grid">
            <aside class="doctor-queue-panel" aria-label="Doctor work queues">
                <section class="queue-section">
                    <div class="workspace-panel-heading"><div><h2>Today’s consultation queue</h2><p>Paid visits assigned to you</p></div><span class="queue-count"><?= count($queue) ?></span></div>
                    <div class="queue-list">
                        <?php if (!$queue): ?>
                            <div class="workspace-empty compact"><span class="workspace-empty-icon"><svg viewBox="0 0 24 24"><path d="M8 7a4 4 0 108 0 4 4 0 00-8 0zM5 21a7 7 0 0114 0M4 3v4M2 5h4"/></svg></span><strong>No assigned consultations</strong><span>Fully paid bookings will appear here.</span></div>
                        <?php else: foreach ($queue as $q): ?>
                            <a class="queue-link<?= $selectedVisit && (int)$selectedVisit['VisitID'] === (int)$q['VisitID'] ? ' active' : '' ?>" href="doctors.php?visit=<?= (int)$q['VisitID'] ?>">
                                <span class="queue-avatar"><?= tdc_e(strtoupper(substr((string)$q['PatientName'], 0, 1))) ?></span>
                                <span class="queue-copy"><strong><?= tdc_e($q['PatientName']) ?></strong><small><?= tdc_e($q['VisitReference']) ?> · <?= tdc_e(date('H:i', strtotime($q['VisitDate']))) ?></small></span>
                                <span class="status-badge"><?= tdc_e($q['QueueStatus']) ?></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
                <section class="queue-section results-section">
                    <div class="workspace-panel-heading"><div><h2>Lab results ready</h2><p>Completed results awaiting review</p></div><span class="queue-count"><?= count($readyResults) ?></span></div>
                    <div class="queue-list">
                        <?php if (!$readyResults): ?>
                            <div class="workspace-empty compact"><strong>No results awaiting review</strong></div>
                        <?php else: foreach ($readyResults as $ready): ?>
                            <a class="queue-link" href="doctors.php?visit=<?= (int)$ready['VisitID'] ?>">
                                <span class="queue-avatar result"><svg viewBox="0 0 24 24"><path d="M9 3h6M10 3v6l-5 8a2 2 0 001.7 3h10.6a2 2 0 001.7-3l-5-8V3M8 15h8"/></svg></span>
                                <span class="queue-copy"><strong><?= tdc_e($ready['PatientName']) ?></strong><small><?= tdc_e($ready['LaboratoryID']) ?> · <?= tdc_e($ready['TestName']) ?></small></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
            </aside>

            <section class="clinical-work-panel">
                <?php if (!$selectedVisit): ?>
                    <div class="workspace-empty workspace-prompt">
                        <span class="workspace-empty-icon"><svg viewBox="0 0 24 24"><path d="M8 7a4 4 0 108 0 4 4 0 00-8 0zM5 21a7 7 0 0114 0M19 8l2 2-4 4"/></svg></span>
                        <strong>Select a patient to begin</strong>
                        <span>Choose an assigned consultation or completed lab result from the work queue.</span>
                    </div>
                <?php else: ?>
                    <header class="patient-context">
                        <div class="patient-context-avatar"><?= tdc_e(strtoupper(substr((string)$selectedVisit['PatientName'], 0, 1))) ?></div>
                        <div class="patient-context-name"><span>Current patient</span><h2><?= tdc_e($selectedVisit['PatientName']) ?></h2><p><?= tdc_e($selectedVisit['VisitReference']) ?> · <?= tdc_e((string)$selectedVisit['PatientPhone']) ?></p></div>
                        <div class="patient-context-meta"><span><?= tdc_e((string)$selectedVisit['Gender']) ?> · <?= (int)$selectedVisit['Age'] ?> years</span><span class="status-badge"><?= tdc_e($selectedVisit['QueueStatus']) ?></span></div>
                    </header>

                    <?php if ($selectedVisit['PaymentStatus'] !== 'Paid'): ?>
                        <div class="workspace-empty workspace-prompt"><strong>Payment required</strong><span>Reception must settle this consultation before clinical work can begin.</span></div>
                    <?php else: ?>
                        <div class="clinical-toolbar">
                            <div><strong>Clinical encounter</strong><span>Record findings and complete the consultation</span></div>
                            <?php if ($selectedVisit['QueueStatus'] === 'Waiting'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="start"><button class="btn btn-primary">Start Consultation</button></form><?php endif; ?>
                        </div>

                        <form method="post" class="clinical-record-form">
                            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="save_notes">
                            <div class="clinical-grid"><div class="form-group full"><label>Clinical Notes</label><textarea name="ClinicalNotes" placeholder="Document history, examination and observations"><?= tdc_e($selectedVisit['ClinicalNotes']) ?></textarea></div><div class="form-group"><label>Diagnosis</label><textarea name="Diagnosis" placeholder="Enter diagnosis"><?= tdc_e($selectedVisit['Diagnosis']) ?></textarea></div><div class="form-group"><label>Treatment Plan</label><textarea name="TreatmentPlan" placeholder="Enter treatment plan"><?= tdc_e($selectedVisit['TreatmentPlan']) ?></textarea></div><div class="form-group"><label>Follow-up Plan</label><textarea name="FollowUpPlan" placeholder="Optional follow-up instructions"><?= tdc_e($selectedVisit['FollowUpPlan']) ?></textarea></div><div class="form-group"><label>Follow-up Date</label><input type="date" name="FollowUpDate" value="<?= tdc_e($selectedVisit['FollowUpDate']) ?>"></div></div>
                            <div class="clinical-form-footer"><label class="check-control"><input type="checkbox" name="complete" value="1"><span>Mark consultation complete</span></label><button class="btn btn-primary">Save Consultation</button></div>
                        </form>

                        <div class="clinical-actions-grid">
                            <form method="post" class="workflow-form" id="prescriptionForm">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="prescribe">
                                <div class="workflow-heading"><div><h2>Create Prescription</h2><p>Send prescribed items to the pharmacy queue</p></div></div>
                                <div id="prescriptionLines"><div class="rx-line"><div class="form-group"><label>Medication</label><select name="MedicationName[]" class="rx-medicine" required><option value="">Select available medicine</option><?php foreach ($medicineOptions as $medicine): ?><option value="<?= tdc_e($medicine['ItemName']) ?>" data-stock="<?= (int)$medicine['QuantityInStock'] ?>" data-unit="<?= tdc_e((string)$medicine['SalesUnit']) ?>" data-price="<?= tdc_e((string)$medicine['SellingPrice']) ?>"><?= tdc_e($medicine['ItemName']) ?> (<?= (int)$medicine['QuantityInStock'] ?> available)</option><?php endforeach; ?></select><div class="rx-meta" aria-live="polite"></div></div><div class="form-row"><div class="form-group"><label>Quantity</label><input type="number" min="1" name="Quantity[]" value="1" required></div><div class="form-group"><label>Dosage</label><input name="Dosage[]"></div></div><div class="form-row"><div class="form-group"><label>Frequency</label><input name="Frequency[]"></div><div class="form-group"><label>Duration</label><input name="Duration[]"></div></div><div class="form-group"><label>Instructions</label><textarea name="Instructions[]"></textarea></div><button type="button" class="btn-sm danger remove-rx-line" hidden>Remove</button></div></div>
                                <div class="workflow-actions"><button type="button" class="btn btn-secondary" id="addPrescriptionLine">Add Item</button><button class="btn btn-primary">Send to Pharmacy</button></div>
                            </form>

                            <form method="post" class="workflow-form">
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="portal_action" value="request_lab">
                                <div class="workflow-heading"><div><h2>Request Lab Test</h2><p>Select available services from the catalogue</p></div></div>
                                <div class="form-group"><label>Laboratory Services</label><select name="ServiceID[]" id="labServiceSelect" required multiple size="5" onchange="document.getElementById('labServicePrice').value=Array.from(this.selectedOptions).reduce((total,option)=>total+Number(option.dataset.price||0),0).toFixed(2)"><?php foreach ($labServices as $service): ?><option value="<?= (int)$service['ServiceID'] ?>" data-price="<?= tdc_e((string)$service['Price']) ?>" title="<?= tdc_e((string)$service['Description']) ?>"><?= tdc_e(($service['Category'] ? $service['Category'].' · ' : '').$service['ServiceName']) ?> · <?= number_format((float)$service['Price'], 2) ?></option><?php endforeach; ?></select><div class="rx-meta">Use Ctrl or Command to select multiple tests.</div></div>
                                <div class="form-row"><div class="form-group"><label>Calculated Fee</label><input id="labServicePrice" value="0.00" readonly aria-label="Configured laboratory fee"></div><div class="form-group"><label>Clinical Request</label><textarea name="Description" placeholder="Reason for request or relevant clinical notes"></textarea></div></div>
                                <div class="workflow-actions"><button class="btn btn-primary" <?= !$labServices ? 'disabled' : '' ?>>Send to Reception</button></div><?php if (!$labServices): ?><div class="rx-meta">A SuperAdmin must configure laboratory services before orders can be created.</div><?php endif; ?>
                            </form>
                        </div>

                        <section class="visit-activity"><div class="workspace-panel-heading"><div><h2>Visit activity</h2><p>Prescriptions and laboratory requests for this encounter</p></div></div><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Item</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach ($prescriptions as $p): ?><tr><td>Prescription</td><td><?= tdc_e($p['MedicationName']) ?></td><td><span class="status-badge"><?= tdc_e($p['Status']) ?></span></td><td><?= tdc_e(date('d M Y', strtotime($p['PrescriptionDate']))) ?></td><td>—</td></tr><?php endforeach; foreach ($labOrders as $l): ?><tr><td>Laboratory</td><td><?= tdc_e($l['TestName']) ?><br><span class="cell-sub"><?= tdc_e($l['ClinicalResult'] ?: $l['Description']) ?></span></td><td><span class="status-badge"><?= tdc_e($l['WorkflowStatus']) ?></span></td><td><?= tdc_e(date('d M Y', strtotime($l['OrderDate']))) ?></td><td><?php if ($l['WorkflowStatus'] === 'Completed' && !$l['ReviewedAt']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><input type="hidden" name="portal_action" value="review_result"><button class="btn-sm">Mark Reviewed</button></form><?php elseif ($l['ReviewedAt']): ?><span class="status-badge">Reviewed</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; if (!$prescriptions && !$labOrders): ?><tr class="empty-row"><td colspan="5">No prescriptions or laboratory requests for this visit.</td></tr><?php endif; ?></tbody></table></div></section>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(function(){
    const notification = document.querySelector('[data-menu="notifications"]');
    const button = notification?.querySelector('.icon-btn');
    button?.addEventListener('click', function(event){
        event.stopPropagation();
        notification.classList.toggle('open');
        button.setAttribute('aria-expanded', String(notification.classList.contains('open')));
        document.getElementById('profile-panel')?.setAttribute('hidden', '');
        document.querySelector('.profile-trigger')?.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('click', function(event){
        if(notification && !notification.contains(event.target)) {
            notification.classList.remove('open');
            button?.setAttribute('aria-expanded', 'false');
        }
    });

    const box=document.getElementById('prescriptionLines');const add=document.getElementById('addPrescriptionLine');if(!box||!add)return;
    function wire(line){const select=line.querySelector('.rx-medicine');const qty=line.querySelector('input[name="Quantity[]"]');const meta=line.querySelector('.rx-meta');const remove=line.querySelector('.remove-rx-line');function sync(){const option=select.options[select.selectedIndex];if(!option||!option.value){meta.textContent='';qty.removeAttribute('max');return;}qty.max=option.dataset.stock;meta.textContent=option.dataset.stock+' '+(option.dataset.unit||'units')+' available · '+Number(option.dataset.price||0).toFixed(2)+' each';}select.addEventListener('change',sync);remove.addEventListener('click',function(){if(box.children.length>1){line.remove();syncRemovers();}});sync();}
    function syncRemovers(){box.querySelectorAll('.remove-rx-line').forEach(function(button){button.hidden=box.children.length===1;});}
    box.querySelectorAll('.rx-line').forEach(wire);add.addEventListener('click',function(){const line=box.firstElementChild.cloneNode(true);line.querySelectorAll('input,textarea').forEach(function(field){field.value=field.name==='Quantity[]'?'1':'';});line.querySelector('select').selectedIndex=0;box.appendChild(line);wire(line);syncRemovers();});syncRemovers();
})();
</script>
</body>
</html>
