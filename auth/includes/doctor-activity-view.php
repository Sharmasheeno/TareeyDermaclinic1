<section class="visit-activity"><h2>Visit activity</h2><p>Prescriptions and laboratory requests for this encounter</p>
<table class="data-table"><thead><tr><th>Type</th><th>Item</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php $shownRx=[]; foreach($prescriptions as $p): $ref=explode('-',$p['PrescriptionID'])[0]; ?>
<tr><td>Prescription <?= tdc_e($ref) ?></td><td><?= tdc_e($p['MedicationName']) ?></td><td><?= tdc_badge($p['Status']) ?></td><td>
<?php if(!isset($shownRx[$ref]) && tdc_doctor_prescription_edit_guard($pdo,$ref)['editable']): ?>
<button type="button" class="btn-sm" data-doctor-action="edit_prescription" data-reference="<?= tdc_e($ref) ?>" data-visit-id="<?= (int)$selectedVisit['VisitID'] ?>" data-patient-id="<?= (int)$selectedVisit['PatientID'] ?>">Edit Prescription</button>
<?php endif; $shownRx[$ref]=true; ?></td></tr><?php endforeach; ?>
<?php foreach($labOrders as $l): ?><tr><td>Laboratory <?= tdc_e($l['LaboratoryID']) ?></td><td><?= tdc_e($l['TestName']) ?><br><?= tdc_e((string)$l['Description']) ?></td><td><?= tdc_badge($l['WorkflowStatus']) ?></td><td>
<?php if(tdc_doctor_lab_edit_guard($pdo,$l['LaboratoryID'])['editable']): ?><button type="button" class="btn-sm" data-doctor-action="edit_lab" data-reference="<?= tdc_e($l['LaboratoryID']) ?>" data-visit-id="<?= (int)$selectedVisit['VisitID'] ?>" data-patient-id="<?= (int)$selectedVisit['PatientID'] ?>">Edit Lab</button><?php endif; ?>
<?php if($l['WorkflowStatus']==='Completed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="VisitID" value="<?= (int)$selectedVisit['VisitID'] ?>"><input type="hidden" name="PatientID" value="<?= (int)$selectedVisit['PatientID'] ?>"><input type="hidden" name="DoctorID" value="<?= (int)$selectedVisit['DoctorID'] ?>"><input type="hidden" name="LaboratoryID" value="<?= tdc_e($l['LaboratoryID']) ?>"><input type="hidden" name="portal_action" value="review_result"><label>Review notes<input name="ReviewNotes" maxlength="2000"></label><button class="btn-sm">Record Review</button></form><a target="_blank" rel="noopener" href="../print_laboratory.php?result=1&amp;ref=<?= urlencode($l['LaboratoryID']) ?>">Print Result</a><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if(!$labOrders && !$prescriptions): ?><tr><td colspan="4">No prescriptions or laboratory requests for this visit.</td></tr><?php endif; ?>
</tbody></table></section>
