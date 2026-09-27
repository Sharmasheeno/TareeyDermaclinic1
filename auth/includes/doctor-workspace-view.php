<?php
declare(strict_types=1);

$currentPage = 'doctors.php';
$waitingCount = (int) ($waitingSummary['waiting'] ?? 0);
$activeCount = (int) ($waitingSummary['consultation'] ?? 0);
$completedCount = (int) ($waitingSummary['completed'] ?? 0);
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
    <style>
        .clinical-action{border:0;border-radius:8px;padding:10px 14px;color:#fff;font-weight:700;cursor:pointer}.clinical-action.history{background:var(--neutral-soft);color:var(--text-primary);border:1px solid var(--border-ui)}.clinical-action.lab,.clinical-action.prescription{background:var(--success)}.clinical-action.results{background:var(--primary)}.clinical-action:hover{filter:brightness(.9)}
        .doctor-modal-panels{display:none}.doctor-modal-panels.is-open{display:block;position:fixed;z-index:1200;top:7.5vh;left:50%;width:min(900px,calc(100vw - 32px));max-height:85vh;transform:translateX(-50%);overflow:auto;padding:18px;border:1px solid var(--border-ui);border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.28)}
        .doctor-history-modal{display:none;position:fixed;z-index:1300;top:7.5vh;left:50%;width:min(1000px,calc(100vw - 32px));max-height:85vh;transform:translateX(-50%);overflow:auto;padding:20px;border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.28)}.doctor-history-modal.is-open{display:block}.doctor-history-close{float:right;border:0;background:transparent;font-size:24px;cursor:pointer}
        .doctor-history-modal h2{margin-top:0}.doctor-history-modal table{width:100%;border-collapse:collapse;min-width:760px}.doctor-history-modal th,.doctor-history-modal td{padding:10px 8px;border-bottom:1px solid var(--border-ui);text-align:left;font-size:12px;vertical-align:top;white-space:nowrap}.doctor-history-modal .data-table-wrap,.doctor-history-modal .table-wrap{overflow-x:auto}
        .lab-cascade-grid,.lab-editor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.lab-test-row{border:1px solid var(--border-ui);border-radius:8px;padding:12px;margin:12px 0;background:#fbfcff}.lab-test-row-heading{font-weight:700;color:var(--text-primary);margin-bottom:8px}.lab-test-row-action button{width:100%;margin-top:0}.doctor-modal-context{margin:-4px 0 14px;padding:10px 12px;border:1px solid var(--border-ui);border-radius:8px;background:#f7f8fc}.doctor-modal-context strong{display:block}.doctor-modal-context span{font-size:12px;color:var(--text-muted)}
        @media(max-width:760px){.lab-cascade-grid,.lab-editor-grid{grid-template-columns:1fr 1fr}.lab-test-row-action{grid-column:1/-1}.lab-test-row-action button{width:auto}}
        #doctorActionModal.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.38);z-index:1500;padding:24px 20px;overflow:hidden}#doctorActionModal.modal-overlay.is-open,#doctorActionModal.modal-overlay.show{display:block}.doctor-action-modal-shell{position:relative;display:flex;flex-direction:column;margin:0 auto;width:min(760px,calc(100vw - 40px));max-width:100%;max-height:calc(100vh - 48px);background:#fff;border:1px solid var(--border-ui);border-radius:18px;box-shadow:0 24px 60px rgba(15,23,42,.24);overflow:hidden}.doctor-action-modal-shell[data-modal-size="large"]{width:min(900px,calc(100vw - 40px))}.doctor-action-modal-shell[data-modal-size="wide"]{width:min(1180px,calc(100vw - 32px))}.doctor-modal-header{position:sticky;top:0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 20px 12px;background:#fff;border-bottom:1px solid var(--border-ui);z-index:2}.doctor-modal-header h2{margin:0;font-size:1.1rem;color:var(--primary)}.doctor-modal-header p{margin:6px 0 0;color:var(--text-muted);font-size:12px}.doctor-modal-header .doctor-history-close{margin-left:auto;border:0;background:transparent;color:var(--text-muted);font-size:26px;line-height:1;cursor:pointer;padding:2px 6px;border-radius:8px}.doctor-modal-header .doctor-history-close:hover{background:var(--bg-soft);color:var(--primary)}.doctor-modal-body{flex:1 1 auto;overflow-y:auto;padding:18px 20px 20px;background:#fff}.doctor-modal-body > *:first-child{margin-top:0}.doctor-modal-body .workflow-form,.doctor-modal-body .doctor-history-modal{display:block;width:100%}.doctor-modal-body table{width:100%;border-collapse:collapse;min-width:720px}.doctor-modal-body th,.doctor-modal-body td{padding:10px 8px;border-bottom:1px solid var(--border-ui);text-align:left;vertical-align:top}.doctor-modal-body .form-row,.doctor-modal-body .lab-cascade-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.doctor-modal-body .form-row .form-group,.doctor-modal-body .lab-cascade-grid .form-group{min-width:0}.doctor-modal-body .form-group textarea{min-height:110px}.doctor-modal-body .workflow-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:12px}.doctor-modal-footer{padding:12px 20px 16px;border-top:1px solid var(--border-ui);background:#fff;position:sticky;bottom:0;z-index:2}@media(max-width:700px){.lab-cascade-grid{grid-template-columns:1fr}.doctor-modal-panels.is-open,.doctor-history-modal{top:3vh;max-height:92vh;width:calc(100vw - 20px);padding:14px}.doctor-action-modal-shell{top:0;max-height:calc(100vh - 24px);width:calc(100vw - 24px);border-radius:16px}.doctor-modal-header{padding:14px 16px 10px}.doctor-modal-body{padding:14px 16px 16px}.doctor-modal-footer{padding:10px 16px 14px}.doctor-modal-body .form-row,.doctor-modal-body .lab-cascade-grid{grid-template-columns:1fr}.doctor-history-modal table,.doctor-modal-body table{min-width:860px}.body.modal-open{overflow:hidden}}            .prescription-workflow{display:flex!important;flex-direction:column;gap:16px}.rx-item-editor,.rx-added-items{padding:16px;border:1px solid var(--border-ui);border-radius:10px;background:#fff}.rx-section-heading{display:flex;justify-content:space-between;gap:12px;margin-bottom:12px}.rx-section-heading h3{margin:0;color:var(--primary);font-size:15px}.rx-section-heading p{margin:4px 0 0;color:var(--text-muted);font-size:12px}.rx-entry-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.rx-entry-grid .form-group{min-width:0}.rx-editor-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}.rx-item-count{color:var(--text-muted);font-weight:600}.rx-items-table-wrap{overflow-x:auto}.rx-items-table{width:100%;border-collapse:collapse}.rx-items-table th,.rx-items-table td{padding:9px 8px;border-bottom:1px solid var(--border-ui);text-align:left;vertical-align:top;font-size:12px}.rx-items-table th{background:var(--surface-secondary);color:var(--text-secondary);font-size:10px;text-transform:uppercase}.rx-items-table td:first-child,.rx-items-table th:first-child{width:34px;text-align:center}.rx-items-table .rx-medication-name{font-weight:700;color:var(--text-primary)}.rx-items-table .rx-instruction{display:block;margin-top:3px;color:var(--text-muted);font-size:11px}.rx-items-table .rx-item-actions{display:flex;gap:6px;white-space:nowrap}.rx-empty-row td{text-align:center;color:var(--text-muted);padding:18px}.prescription-submit-actions{position:sticky;bottom:0;z-index:3;margin:0 -24px -24px;padding:12px 24px;background:#fff;border-top:1px solid var(--border-ui)}@media(max-width:700px){.rx-entry-grid{grid-template-columns:1fr}.rx-editor-actions{justify-content:stretch;flex-wrap:wrap}.rx-editor-actions button{flex:1}.rx-items-table{min-width:680px}.prescription-submit-actions{margin:0 -16px -16px;padding:10px 16px}}.doctor-modal-panels.is-open .prescription-workflow>.workflow-heading{position:sticky;top:-18px;z-index:4;padding:4px 0 12px;background:#fff}</style>
    <style>
        #doctorActionModal .doctor-action-modal-shell{background:#f8f9ff;border-color:#cfd7eb}
        #doctorActionModal .doctor-modal-header{background:var(--primary);border-bottom:0;padding:20px 24px}
        #doctorActionModal .doctor-modal-header h2{color:#fff;font-size:20px}
        #doctorActionModal .doctor-modal-header p{color:rgba(255,255,255,.76)}
        #doctorActionModal .doctor-modal-header .doctor-history-close{background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:2px 9px}
        #doctorActionModal .doctor-modal-body{background:#f8f9ff;padding:24px}
    </style>
    <script src="../assets/clinic.js?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.js')) ?>" defer></script>
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
                        <?= tdc_navigation_icon($item['href']) ?>
                        <span><?= tdc_e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>

<main class="page-body doctor-workspace" data-doctor-workspace="1">
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
        <?php require __DIR__ . '/doctor-waiting-view.php'; if ($selectedVisit && !$waitingSchedule): ?>
        <div id="doctorSelectedEncounter">
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
                        <div class="patient-context-meta"><span><?= tdc_e((string)$selectedVisit['Gender']) ?> · <?= (int)$selectedVisit['Age'] ?> years</span><?= tdc_badge($selectedVisit['QueueStatus']) ?></div>
                    </header>

                    <?php $consultationReadyForOrders = tdc_doctor_consultation_ready_for_orders($pdo, (int) $selectedVisit['VisitID'], (int) $selectedVisit['DoctorID']); ?>
                        <div class="clinical-toolbar">
                            <div><strong>Clinical encounter</strong><span>Record findings and complete the consultation</span></div>
                            <?php if (in_array($selectedVisit['QueueStatus'], ['Waiting', 'Pending Payment'], true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="PatientID" value="<?= (int)$selectedVisit['PatientID'] ?>"><input type="hidden" name="DoctorID" value="<?= (int)$selectedVisit['DoctorID'] ?>"><input type="hidden" name="portal_action" value="start"><button class="btn-success btn ">Start Consultation</button></form><?php endif; ?>
                        </div>

                        <form method="post" class="clinical-record-form">
                            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                            <input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>">
                            <input type="hidden" name="PatientID" value="<?= (int)$selectedVisit['PatientID'] ?>">
                            <input type="hidden" name="DoctorID" value="<?= (int)$selectedVisit['DoctorID'] ?>">
                            <input type="hidden" name="portal_action" value="save_notes">
                            <div class="clinical-grid">
                                <div class="form-group full"><label>Clinical Notes</label><textarea name="ClinicalNotes" placeholder="Document history, examination and observations"><?= tdc_e($selectedVisit['ClinicalNotes']) ?></textarea></div>
                                <div class="form-group full"><label>Diagnosis</label><textarea name="Diagnosis" placeholder="Enter diagnosis"><?= tdc_e($selectedVisit['Diagnosis']) ?></textarea></div>
                            </div>
                            <?php if (in_array((string)$selectedVisit['QueueStatus'], ['In Consultation', 'Completed'], true) && !$consultationReadyForOrders): ?><div class="rx-meta">Save Clinical Notes and Diagnosis to enable Lab and Prescription.</div><?php endif; ?>
                            <div class="clinical-form-footer"><?php if ($selectedVisit['QueueStatus'] === 'In Consultation'): ?><label class="check-control"><input type="checkbox" name="complete" value="1"><span>Mark consultation complete</span></label><?php elseif (in_array($selectedVisit['QueueStatus'], ['Waiting', 'Pending Payment'], true)): ?><span class="rx-meta">Start Consultation above before completing this visit.</span><?php endif; ?><button class="btn-success btn ">Save Consultation</button></div>
                        </form>

                        <div class="clinical-actions-grid doctor-modal-panels"><?php require __DIR__.'/doctor-edit-forms.php'; ?>
                            <button type="button" class="doctor-history-close" data-close-doctor-modal aria-label="Close clinical modal">&times;</button>
                            <form method="post" class="workflow-form prescription-workflow" id="prescriptionForm">
    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="PatientID" value="<?= (int)$selectedVisit['PatientID'] ?>"><input type="hidden" name="DoctorID" value="<?= (int)$selectedVisit['DoctorID'] ?>"><input type="hidden" name="portal_action" value="prescribe">
    <div class="workflow-heading"><div><h2>Create Prescription</h2><p>Send prescribed items to the pharmacy queue</p></div></div>
    <div class="doctor-modal-context"><strong><?= tdc_e($selectedVisit['PatientName']) ?></strong><span><?= tdc_e($selectedVisit['VisitReference']) ?> · <?= tdc_e((string) $selectedVisit['Gender']) ?></span></div>
    <section class="rx-item-editor" aria-labelledby="rxEditorTitle"><div class="rx-section-heading"><div><h3 id="rxEditorTitle">Add Medication</h3><p>Enter one medication, then add it to the prescription list.</p></div></div><div class="form-group"><label for="rxMedication">Medication *</label><select id="rxMedication" class="rx-medicine"><option value="">Select available medicine</option><?php foreach ($medicineOptions as $medicine): ?><option value="<?= tdc_e($medicine['ItemName']) ?>" data-stock="<?= (int)$medicine['QuantityInStock'] ?>" data-unit="<?= tdc_e((string)$medicine['SalesUnit']) ?>" data-price="<?= tdc_e((string)$medicine['SellingPrice']) ?>"><?= tdc_e($medicine['ItemName']) ?> (<?= (int)$medicine['QuantityInStock'] ?> available)</option><?php endforeach; ?></select><div class="rx-meta" id="rxMedicationMeta" aria-live="polite"></div></div><div class="rx-entry-grid"><div class="form-group"><label for="rxQuantity">Quantity *</label><input id="rxQuantity" type="number" min="1" value="1"></div><div class="form-group"><label for="rxFrequency">Frequency</label><input id="rxFrequency" placeholder="e.g. BID"></div><div class="form-group"><label for="rxDuration">Duration</label><input id="rxDuration" placeholder="e.g. 5 days"></div></div><div class="rx-entry-grid"><div class="form-group"><label for="rxRoute">Route</label><select id="rxRoute"><option value="">Select route</option><option value="Oral">Oral</option><option value="Topical">Topical</option><option value="Intravenous (IV)">Intravenous (IV)</option><option value="Intramuscular (IM)">Intramuscular (IM)</option><option value="Subcutaneous (SC)">Subcutaneous (SC)</option><option value="Inhalation">Inhalation</option><option value="Sublingual">Sublingual</option><option value="Rectal">Rectal</option><option value="Ophthalmic">Ophthalmic</option><option value="Otic">Otic</option><option value="Nasal">Nasal</option><option value="Other">Other</option></select></div></div><div class="form-group"><label for="rxInstructions">Instructions</label><textarea id="rxInstructions" placeholder="Optional medication instructions"></textarea></div><div class="rx-editor-actions"><button type="button" class="btn-success btn" id="addPrescriptionItem" <?= !$medicineOptions ? 'disabled' : '' ?>>+ Add Item</button><button type="button" class="btn-secondary btn-sm" id="updatePrescriptionItem" hidden>Update Item</button><button type="button" class="btn-secondary btn-sm" id="cancelPrescriptionEdit" hidden>Cancel Edit</button></div><?php if (!$medicineOptions): ?><p id="prescriptionUnavailable" class="rx-meta">No medicines are available in stock. Pharmacy must configure or replenish medicines before a prescription can be sent.</p><?php endif; ?></section>
    <section class="rx-added-items" aria-labelledby="rxItemsTitle"><div class="rx-section-heading"><div><h3 id="rxItemsTitle">Added Prescription Items <span class="rx-item-count" id="rxItemCount">(0)</span></h3><p>Items staged here will be saved together under one prescription reference.</p></div></div><div class="rx-items-table-wrap"><table class="rx-items-table"><thead><tr><th>#</th><th>Medication</th><th>Qty</th><th>Frequency</th><th>Duration</th><th>Route</th><th>Actions</th></tr></thead><tbody id="prescriptionItemsBody"><tr class="rx-empty-row" id="rxEmptyItems"><td colspan="7">No medications added yet.</td></tr></tbody></table></div></section>
    <div id="prescriptionStagedInputs"></div><div id="prescriptionValidation" class="form-error" hidden></div><div class="workflow-actions prescription-submit-actions"><button type="submit" class="btn-primary btn" id="submitPrescription" <?= !$medicineOptions ? 'disabled' : '' ?>>Submit Prescription</button></div>
</form>

                            <form method="post" class="workflow-form" id="doctorLabRequestForm" data-lab-catalog='<?= tdc_e(json_encode($doctorLabCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'>
                                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="PatientID" value="<?= (int)$selectedVisit['PatientID'] ?>"><input type="hidden" name="DoctorID" value="<?= (int)$selectedVisit['DoctorID'] ?>"><input type="hidden" name="portal_action" value="request_lab">
                                <div class="workflow-heading"><div><h2>Request Lab Test</h2><p>Select available services from the catalogue</p></div></div><div class="doctor-modal-context"><strong><?= tdc_e($selectedVisit['PatientName']) ?></strong><span><?= tdc_e($selectedVisit['VisitReference']) ?> · <?= tdc_e((string) $selectedVisit['Gender']) ?></span></div>
                                <section class="rx-item-editor" data-lab-editor><div class="rx-section-heading"><div><h3>Stage laboratory test</h3><p>Add one catalogue test at a time, then review the complete request below.</p></div></div><div class="lab-editor-grid"><div class="form-group"><label>Category *</label><select data-lab-category required><option value="">Select category</option><?php foreach ($doctorLabCatalog['categories'] as $category): ?><option value="<?= (int) $category['CategoryID'] ?>"><?= tdc_e($category['CategoryName']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Lab Type *</label><select data-lab-type required disabled><option value="">Select type</option></select></div><div class="form-group"><label>Test *</label><select data-lab-test required disabled><option value="">Select test</option></select></div></div><div class="rx-editor-actions"><button type="button" class="btn-success btn" data-lab-stage>+ Add Test</button><button type="button" class="btn-secondary btn-sm" data-lab-update hidden>Update Test</button><button type="button" class="btn-secondary btn-sm" data-lab-cancel hidden>Cancel Edit</button></div><div class="form-error" data-lab-validation hidden></div></section><section class="rx-added-items"><div class="rx-section-heading"><div><h3>Added Laboratory Tests <span class="rx-item-count" data-lab-staged-count>(0)</span></h3></div></div><div class="rx-items-table-wrap"><table class="rx-items-table"><thead><tr><th>#</th><th>Test</th><th>Fee</th><th>Actions</th></tr></thead><tbody data-lab-staged-body></tbody></table></div></section><div data-lab-staged-inputs></div>
                                <div class="form-row"><div class="form-group"><label>Calculated Fee</label><input id="doctorLabPrice" data-lab-price value="0.00" readonly aria-label="Configured laboratory fee"></div><div class="form-group"><label>Clinical Request</label><textarea name="Description" placeholder="Reason for request or relevant clinical notes"></textarea></div></div>
                                <div class="workflow-actions"><button type="submit" class="btn-success btn" id="sendDoctorLabRequest" disabled>Submit Lab Request</button></div><?php if (!$doctorLabCatalog['tests']): ?><div class="rx-meta">A SuperAdmin must configure laboratory categories, types, and tests before orders can be created. <a href="laboratory.php?workspace=1&lab_tab=test-register">Open the Laboratory workspace</a></div><?php endif; ?>
                            </form>
                        </div>

                        <?php require __DIR__.'/doctor-activity-view.php'; ?>

                        <section class="doctor-history-modal" id="doctorHistoryModal" aria-labelledby="doctorHistoryTitle"><button type="button" class="doctor-history-close" data-close-doctor-modal aria-label="Close">&times;</button><h2 id="doctorHistoryTitle">Medical History · <?= tdc_e($selectedVisit['PatientName']) ?></h2><p><?= tdc_e($selectedVisit['VisitReference']) ?></p><h3>Previous Visits</h3><table><thead><tr><th>VisitReference</th><th>VisitDate</th><th>Doctor</th><th>ChiefComplaint</th><th>Diagnosis</th><th>ClinicalNotes</th><th>TreatmentPlan</th><th>FollowUpPlan</th></tr></thead><tbody><?php foreach($patientHistoryVisits as $history): ?><tr><td><?= tdc_e($history['VisitReference']) ?></td><td><?= tdc_e($history['VisitDate']) ?></td><td><?= tdc_e($history['DoctorName'] ?? '') ?></td><td><?= tdc_e($history['ChiefComplaint'] ?? '') ?></td><td><?= tdc_e($history['Diagnosis'] ?? '') ?></td><td><?= tdc_e($history['ClinicalNotes'] ?? '') ?></td><td><?= tdc_e($history['TreatmentPlan'] ?? '') ?></td><td><?= tdc_e($history['FollowUpPlan'] ?? '') ?></td></tr><?php endforeach; if (!$patientHistoryVisits): ?><tr><td colspan="8">No previous visits found.</td></tr><?php endif; ?></tbody></table><h3>Laboratory Orders and Results</h3><table><thead><tr><th>LaboratoryID</th><th>VisitID</th><th>TestName</th><th>Result</th><th>ClinicalResult</th><th>ResultDate</th></tr></thead><tbody><?php foreach($patientHistoryLabs as $historyLab): ?><tr><td><?= tdc_e($historyLab['LaboratoryID']) ?></td><td><?= tdc_e((string)($historyLab['VisitID'] ?? '')) ?></td><td><?= tdc_e($historyLab['TestName']) ?></td><td><?= tdc_e($historyLab['Result'] ?? '') ?></td><td><?= tdc_e($historyLab['ClinicalResult'] ?? '') ?></td><td><?= tdc_e($historyLab['ResultDate'] ?? '') ?></td></tr><?php endforeach; if (!$patientHistoryLabs): ?><tr><td colspan="6">No laboratory history found.</td></tr><?php endif; ?></tbody></table><h3>Prescriptions</h3><table><thead><tr><th>PrescriptionID</th><th>VisitID</th><th>Medication</th><th>Quantity</th><th>Frequency</th><th>Duration</th><th>Instructions</th><th>Status</th></tr></thead><tbody><?php foreach($patientHistoryPrescriptions as $historyPrescription): ?><tr><td><?= tdc_e($historyPrescription['PrescriptionID']) ?></td><td><?= tdc_e((string)($historyPrescription['VisitID'] ?? '')) ?></td><td><?= tdc_e($historyPrescription['MedicationName']) ?></td><td><?= tdc_e((string)$historyPrescription['Quantity']) ?></td><td><?= tdc_e($historyPrescription['Frequency'] ?? '') ?></td><td><?= tdc_e($historyPrescription['Duration'] ?? '') ?></td><td><?= tdc_e($historyPrescription['Instructions'] ?? '') ?></td><td><?= tdc_e($historyPrescription['Status'] ?? '') ?></td></tr><?php endforeach; if (!$patientHistoryPrescriptions): ?><tr><td colspan="8">No prescription history found.</td></tr><?php endif; ?></tbody></table></section>
                        <section class="doctor-history-modal" id="doctorResultsModal" aria-labelledby="doctorResultsTitle"><button type="button" class="doctor-history-close" data-close-doctor-modal aria-label="Close">&times;</button><h2 id="doctorResultsTitle">Lab Results · <?= tdc_e($selectedVisit['PatientName']) ?></h2><p><?= tdc_e($selectedVisit['VisitReference']) ?></p><?php if ($patientHistoryModernResults): ?><table><thead><tr><th>Test</th><th>Parameter</th><th>Result</th><th>Unit</th><th>Reference Range</th><th>Flag</th><th>Remark</th><th>Result Status</th><th>Latest Review</th></tr></thead><tbody><?php foreach($patientHistoryModernResults as $result): ?><tr><td><?= tdc_e($result['TestName']) ?></td><td><?= tdc_e($result['ParameterNameSnapshot'] ?? '') ?></td><td><?= tdc_e($result['DisplayResult'] ?? '') ?></td><td><?= tdc_e($result['UnitNameSnapshot'] ?? '') ?></td><td><?= tdc_e($result['ReferenceRangeSnapshot'] ?? '') ?></td><td><?= tdc_e($result['FlagNameSnapshot'] ?? '') ?></td><td><?= tdc_e($result['Remark'] ?? '') ?></td><td><?= tdc_badge($result['ResultStatus'] ?? '') ?></td><td><?= tdc_e(implode(' · ',array_filter([$result['ReviewAction']??null,$result['ReviewerName']??null,$result['PerformedAt']??null,$result['ReviewNotes']??null]))) ?></td></tr><?php endforeach; ?></tbody></table><?php elseif ($patientHistoryLegacyResults): ?><table><thead><tr><th>TestName</th><th>Result</th><th>ClinicalResult</th><th>ResultDate</th><th>Status</th></tr></thead><tbody><?php foreach($patientHistoryLegacyResults as $result): ?><tr><td><?= tdc_e($result['ItemTestName'] ?: $result['TestName']) ?></td><td><?= tdc_e($result['ItemResult'] ?: $result['Result']) ?></td><td><?= tdc_e($result['ItemClinicalResult'] ?: $result['ClinicalResult']) ?></td><td><?= tdc_e($result['ItemResultDate'] ?: $result['ResultDate']) ?></td><td><?= tdc_badge($result['WorkflowStatus'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>No laboratory results are available for this visit.</p><?php endif; ?></section>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    <?php endif; ?>
                        <div id="doctorActionModal" class="modal-overlay" aria-hidden="true">
                            <div class="doctor-action-modal-shell modal-box" role="dialog" aria-modal="true" aria-label="Doctor action modal">
                                <div class="doctor-modal-header">
                                    <div>
                                        <h2 data-doctor-modal-title>Doctor action</h2>
                                        <p data-doctor-modal-subtitle>Patient activity</p>
                                    </div>
                                    <button type="button" class="doctor-history-close" data-close-doctor-modal aria-label="Close">&times;</button>
                                </div>
                                <div class="doctor-modal-body">
                                    <div id="doctorActionModalContent"></div>
                                </div>
                                <div class="doctor-modal-footer" aria-hidden="true"></div>
                            </div>
                        </div>
<div id="doctorInlineEncounter" aria-live="polite"></div>
</main>
<script>
function initDoctorLabSelection(root = document) {
    const catalog = <?= json_encode($doctorLabCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const addLabTest = root.querySelector('#addDoctorLabTest');
    const labForm = root.querySelector('#doctorLabRequestForm');
    const rowsContainer = root.querySelector('[data-lab-selection-rows]');
    const labPrice = root.querySelector('#doctorLabPrice');
    const labSubmit = root.querySelector('#sendDoctorLabRequest');
    const labStatus = root.querySelector('[data-lab-selection-status]');

    function setLabStatus(message, tone = 'info') {
        if (!labStatus) return;
        labStatus.textContent = message || '';
        labStatus.style.color = tone === 'error' ? '#b42318' : '#475467';
        labStatus.style.display = message ? 'block' : 'none';
        labStatus.style.margin = '8px 0';
        labStatus.style.fontSize = '12px';
    }
    function resetSelect(select, label){ if (!select) return; select.innerHTML = ''; select.appendChild(new Option(label, '')); }
    function refreshTypes(row){
        const categorySelect=row?.querySelector('[data-category-select]');
        const typeSelect=row?.querySelector('[data-type-select]');
        const testSelect=row?.querySelector('[data-test-select]');
        if (!categorySelect || !typeSelect || !testSelect) return;
        resetSelect(typeSelect, 'Select type'); resetSelect(testSelect, 'Select test');
        testSelect.disabled = true;
        const types = catalog.types.filter((item) => String(item.CategoryID) === String(categorySelect.value));
        types.forEach((item) => typeSelect.appendChild(new Option(item.TypeName, item.TypeID)));
        typeSelect.disabled = types.length === 0;
    }
    function refreshTests(row){
        const typeSelect=row?.querySelector('[data-type-select]');
        const testSelect=row?.querySelector('[data-test-select]');
        if (!typeSelect || !testSelect) return;
        resetSelect(testSelect, 'Select test');
        const tests = catalog.tests.filter((item) => String(item.TypeID) === String(typeSelect.value));
        tests.forEach((item) => { const option = new Option(item.TestName + ' · ' + Number(item.Price).toFixed(2), item.TestID); option.dataset.price = item.Price; testSelect.appendChild(option); });
        testSelect.disabled = tests.length === 0;
    }
    function rows(){ return rowsContainer ? Array.from(rowsContainer.querySelectorAll('[data-lab-test-row]')) : []; }
    function selectedIds(){ return rows().map((row) => row.querySelector('[data-test-select]')?.value || '').filter(Boolean); }
    function createRow(index){
        const row=document.createElement('div'); row.className='lab-test-row'; row.dataset.labTestRow=''; row.dataset.rowIndex=String(index);
        row.innerHTML='<div class="lab-test-row-heading"></div><div class="lab-cascade-grid"><div class="form-group"><label>Category *</label><select data-category-select required><option value="">Select category</option></select></div><div class="form-group"><label>Lab Type *</label><select data-type-select required disabled><option value="">Select type</option></select></div><div class="form-group"><label>Test *</label><select data-test-select required disabled><option value="">Select test</option></select></div><div class="form-group lab-test-row-action"><label>&nbsp;</label><button type="button" class="btn-danger btn-sm danger" data-remove-test-row>Remove</button></div></div>';
        row.querySelector('.lab-test-row-heading').textContent='Test #'+(index+1);
        const category=row.querySelector('[data-category-select]');
        catalog.categories.forEach((item)=>category.appendChild(new Option(item.CategoryName,item.CategoryID)));
        return row;
    }
    function renumberRows(){ rows().forEach((row,index)=>{row.dataset.rowIndex=String(index);row.querySelector('.lab-test-row-heading').textContent='Test #'+(index+1);row.querySelector('[data-remove-test-row]').hidden=index===0;}); }
    function syncHiddenInputs() {
        if (!labForm) return;
        labForm.querySelectorAll('input[name="SelectedModernTestID[]"]').forEach((input) => input.remove());
        selectedIds().forEach((id) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'SelectedModernTestID[]';
            hidden.value = String(id);
            labForm.appendChild(hidden);
        });
    }
    function renderSelectedTests() {
        if (!labPrice || !labSubmit) return;
        let total=0; let complete=true; const ids=selectedIds();
        rows().forEach((row,index)=>{ const category=row.querySelector('[data-category-select]')?.value||''; const type=row.querySelector('[data-type-select]')?.value||''; const test=row.querySelector('[data-test-select]')?.value||''; if((category||type||test)&&!test) complete=false; if(test){const item=catalog.tests.find((candidate)=>String(candidate.TestID)===String(test)); if(item) total+=Number(item.Price||0);} row.querySelector('[data-remove-test-row]').hidden=index===0; });
        const duplicate=new Set(ids).size!==ids.length;
        labPrice.value=total.toFixed(2); labSubmit.disabled=ids.length===0||!complete||duplicate;
        syncHiddenInputs();
    }

    rowsContainer?.addEventListener('change', function(event){
        const row=event.target.closest('[data-lab-test-row]'); if(!row) return;
        if(event.target.matches('[data-category-select]')) refreshTypes(row);
        if(event.target.matches('[data-type-select]')) refreshTests(row);
        if(event.target.matches('[data-test-select]')) { const id=event.target.value; if(id && selectedIds().filter((value)=>value===id).length>1){event.target.value='';setLabStatus('This test is already selected in this request.','error');} else setLabStatus(''); }
        renderSelectedTests();
    });

    const delegatingTarget = root === document ? document : root;
    delegatingTarget.addEventListener('click', function (event) {
        const addButton = event.target.closest('[data-lab-add-test]');
        if (addButton) {
            const current=rows().at(-1); const test=current?.querySelector('[data-test-select]')?.value||'';
            if(!test){setLabStatus('Complete the current test row before adding another.','error');return;}
            rowsContainer.appendChild(createRow(rows().length)); renumberRows(); renderSelectedTests();
            return;
        }
        const removeButton = event.target.closest('[data-remove-test-row]');
        if (removeButton) {
            removeButton.closest('[data-lab-test-row]')?.remove(); renumberRows(); setLabStatus(''); renderSelectedTests();
        }
    });

    addLabTest?.setAttribute('data-lab-add-test', '1');
    renderSelectedTests();
    labForm?.addEventListener('submit', function(event){
        const currentRows=rows(); const ids=selectedIds();
        const incomplete=currentRows.findIndex((row)=>{const c=row.querySelector('[data-category-select]')?.value||'';const t=row.querySelector('[data-type-select]')?.value||'';const x=row.querySelector('[data-test-select]')?.value||'';return (c||t||x)&&!x;});
        if(incomplete>=0){event.preventDefault();setLabStatus('Complete or remove Test #'+(incomplete+1)+'.','error');return;}
        if(new Set(ids).size!==ids.length){event.preventDefault();setLabStatus('This test is already selected in this request.','error');return;}
        if(!ids.length){event.preventDefault();setLabStatus('Select at least one test before submitting.','error');return;}
        syncHiddenInputs();
    });

    const notification = root.querySelector('[data-menu="notifications"]');
    const button = notification?.querySelector('.icon-btn');
    button?.addEventListener('click', function(event){
        event.stopPropagation();
        notification.classList.toggle('open');
        button.setAttribute('aria-expanded', String(notification.classList.contains('open')));
        root.querySelector('#profile-panel')?.setAttribute('hidden', '');
        root.querySelector('.profile-trigger')?.setAttribute('aria-expanded', 'false');
    });
    root.addEventListener('click', function(event){
        if(notification && !notification.contains(event.target)) {
            notification.classList.remove('open');
            button?.setAttribute('aria-expanded', 'false');
        }
    });

    const prescriptionForm = root.querySelector('#prescriptionForm');
    const medication = root.querySelector('#rxMedication'), quantity = root.querySelector('#rxQuantity'), frequency = root.querySelector('#rxFrequency'), duration = root.querySelector('#rxDuration'), route = root.querySelector('#rxRoute'), instructions = root.querySelector('#rxInstructions'), addItem = root.querySelector('#addPrescriptionItem'), updateItem = root.querySelector('#updatePrescriptionItem'), cancelEdit = root.querySelector('#cancelPrescriptionEdit'), itemsBody = root.querySelector('#prescriptionItemsBody'), itemCount = root.querySelector('#rxItemCount'), emptyItems = root.querySelector('#rxEmptyItems'), stagedInputs = root.querySelector('#prescriptionStagedInputs'), validation = root.querySelector('#prescriptionValidation'), itemMeta = root.querySelector('#rxMedicationMeta');
    if (prescriptionForm && medication && quantity && addItem && itemsBody && prescriptionForm.dataset.prescriptionStagingReady !== 'true') {
        const staged = [];
        let editingIndex = -1;
        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        function clearEditor(focus=true){medication.value='';quantity.value='1';frequency.value='';duration.value='';route.value='';instructions.value='';editingIndex=-1;updateItem.hidden=true;cancelEdit.hidden=true;addItem.hidden=false;itemMeta.textContent='';if(focus)medication.focus();}
        function syncMeta(){const option=medication.options[medication.selectedIndex];if(!option||!option.value){itemMeta.textContent='';quantity.removeAttribute('max');return;}quantity.max=option.dataset.stock||'';itemMeta.textContent=(option.dataset.stock||'0')+' '+(option.dataset.unit||'units')+' available · '+Number(option.dataset.price||0).toFixed(2)+' each';}
        function render(){itemsBody.innerHTML='';if(!staged.length){itemsBody.appendChild(emptyItems);emptyItems.hidden=false;}else{staged.forEach((item,index)=>{const row=document.createElement('tr');row.innerHTML='<td>'+(index+1)+'</td><td><span class="rx-medication-name">'+esc(item.medication)+'</span>'+(item.instructions?'<span class="rx-instruction">'+esc(item.instructions)+'</span>':'')+'</td><td>'+esc(item.quantity)+'</td><td>'+esc(item.frequency||'—')+'</td><td>'+esc(item.duration||'—')+'</td><td>'+esc(item.route||'—')+'</td><td><div class="rx-item-actions"><button type="button" class="btn-secondary btn-sm" data-rx-edit="'+index+'">Edit</button><button type="button" class="btn-danger btn-sm" data-rx-remove="'+index+'">Remove</button></div></td>';itemsBody.appendChild(row);});}itemCount.textContent='('+staged.length+')';}
        function stageCurrent(){const option=medication.options[medication.selectedIndex],name=medication.value.trim(),qty=Number(quantity.value);if(!name||!option||!qty||qty<1){validation.hidden=false;validation.textContent='Select a medication and enter a positive quantity.';return false;}const duplicate=staged.some((item,index)=>index!==editingIndex&&item.medication.toLowerCase()===name.toLowerCase());if(duplicate){validation.hidden=false;validation.textContent='This medication has already been added.';return false;}const item={medication:name,quantity:Math.floor(qty),frequency:frequency.value.trim(),duration:duration.value.trim(),route:route.value,instructions:instructions.value.trim()};if(editingIndex>=0)staged[editingIndex]=item;else staged.push(item);validation.hidden=true;render();clearEditor();return true;}
        prescriptionForm._rxStageReady=true;prescriptionForm._rxStageCurrent=stageCurrent;function writeHiddenInputs(){stagedInputs.innerHTML='';staged.forEach((item)=>{Object.entries({MedicationName:item.medication,Quantity:item.quantity,Frequency:item.frequency,Duration:item.duration,Route:item.route,Instructions:item.instructions}).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name+'[]';input.value=value;stagedInputs.appendChild(input);});});}
        medication.addEventListener('change',syncMeta);addItem.addEventListener('click',stageCurrent);updateItem.addEventListener('click',stageCurrent);cancelEdit.addEventListener('click',clearEditor);itemsBody.addEventListener('click',(event)=>{const edit=event.target.closest('[data-rx-edit]'),remove=event.target.closest('[data-rx-remove]');if(edit){const item=staged[Number(edit.dataset.rxEdit)];if(!item)return;editingIndex=Number(edit.dataset.rxEdit);medication.value=item.medication;quantity.value=item.quantity;frequency.value=item.frequency;duration.value=item.duration;route.value=item.route;instructions.value=item.instructions;syncMeta();addItem.hidden=true;updateItem.hidden=false;cancelEdit.hidden=false;medication.focus();}if(remove){staged.splice(Number(remove.dataset.rxRemove),1);if(editingIndex===Number(remove.dataset.rxRemove))clearEditor();else if(editingIndex>Number(remove.dataset.rxRemove))editingIndex--;render();}});prescriptionForm.addEventListener('submit',(event)=>{if(editingIndex>=0){event.preventDefault();validation.hidden=false;validation.textContent='Update or cancel the current item before submitting.';return;}if(!staged.length){event.preventDefault();validation.hidden=false;validation.textContent='Add at least one medication before submitting the prescription.';return;}const currentFields=[medication.value,frequency.value,duration.value,route.value,instructions.value].some((value)=>String(value).trim()!=='')||Number(quantity.value)!==1;if(currentFields){event.preventDefault();validation.hidden=false;validation.textContent='Click Add Item to add the current medication before submitting.';return;}writeHiddenInputs();});render();clearEditor(false);
    }
    const editPrescriptionForm = root.querySelector('#doctorPrescriptionEditForm');
    if (editPrescriptionForm) { const editBox=editPrescriptionForm.querySelector('#prescriptionLines'), editAdd=editPrescriptionForm.querySelector('#addPrescriptionLine'); if(editBox&&editAdd){const wireEdit=(line)=>{const select=line.querySelector('.rx-medicine'),qty=line.querySelector('input[name="Quantity[]"]'),meta=line.querySelector('.rx-meta');const sync=()=>{const option=select.options[select.selectedIndex];if(!option||!option.value){meta.textContent='';qty.removeAttribute('max');return;}meta.textContent=option.dataset.stock+' '+(option.dataset.unit||'units')+' available · '+Number(option.dataset.price||0).toFixed(2)+' each';};select.addEventListener('change',sync);line.querySelector('.remove-rx-line')?.addEventListener('click',()=>{if(editBox.children.length>1){line.remove();}});sync();};editBox.querySelectorAll('.rx-line').forEach(wireEdit);editAdd.addEventListener('click',()=>{const line=editBox.firstElementChild.cloneNode(true);line.querySelectorAll('input,textarea').forEach(field=>{if(field.name!=='ExistingPrescriptionID[]')field.value=field.name==='Quantity[]'?'1':'';});line.querySelector('select').selectedIndex=0;editBox.appendChild(line);wireEdit(line);});}}
}
window.initDoctorLabSelection = initDoctorLabSelection;
</script>
</body>
</html>
