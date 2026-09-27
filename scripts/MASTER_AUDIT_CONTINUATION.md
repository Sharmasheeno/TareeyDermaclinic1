# Tareey audit continuation — 20 September 2026

Continued from the existing dirty working tree. No reset, checkout, production deployment, production data mutation, new application table, or new status value was performed. The modified-file inventory below includes pre-existing work; it is not a claim that this audit authored every diff.

## Authoritative pharmacy policy

ALLOW UNPAID AND PARTIAL DISPENSING; RETAIN DEBT.

The user explicitly confirmed this policy during this pass. The former full-payment backend check and queue UI gate were removed. Pricing remains necessary to establish the charge. Stock availability remains necessary to dispense. The prescription schema stores AmountPaid and DueBalance; its payment label is derived from those amounts rather than a new PaymentStatus column.

| Payment state | Dispense with stock | Financial effect of dispense |
|---|---|---|
| Unpaid | Allowed | Paid remains 0; full debt remains |
| Partial | Allowed | Confirmed paid amount and remaining debt remain |
| Paid | Allowed | Due remains 0 |

Dispense creates neither a synthetic POS bill nor a payment merely because dispensing occurs. Its reference remains PrescriptionReference. Repeated dispense is rejected before another stock deduction. Later Reception payment works on dispensed prescriptions.

## Runtime evidence

- Baseline role suite: 290 checks, 21 failures (`master-audit-baseline.txt`).
- Current role suite: see `master-role-current.txt` for final count.
- Focused integration suite: 77 checks, 0 failures (`master-audit-pass.txt`, `master-audit-pass.json`).
- All test data was synthetic and isolated in disposable local databases. The harness clones actual table structures and indexes with CREATE TABLE LIKE; this does not reproduce foreign-key constraints. These tests therefore do not certify every production constraint or concurrent interleaving.
- PHP lint of modified PHP files and JavaScript parse succeeded. These are syntax evidence only.

Focused tests cover shared phone identity/search, rejected overpayment, partial consultation care, forged PatientID and CSRF rejection, safe completion, master add/activate/deactivate, selection duplicate rejection and updates, center-test mapping, modern multi-test collection/draft/reopen/completion, invalid and foreign parameters, Doctor result readback, prescription pricing/payment/dispense, debt aggregation, later settlement, repeat protection, and consultation reversal isolated from lab payments sharing VisitID.

The integration trace uses PatientID 1, VisitID 1, LaboratoryID LAB000001, bridge ModernTestIDs 1 and 2, and PrescriptionReferences RX000001/RX000002/RX000003. The amounts are assertions, not production balances:

- Consultation: fee 8, paid 4, due 4; completion retains Partial/due 4.
- Lab: total 12, unpaid completion retains due 12; later payment 5 leaves due 7 without reopening the order.
- Prescription RX000001: total 20; partial payment 5 survives repricing; payment 15 settles it; dispense moves stock 100 → 98 exactly once.
- Additional prescriptions exercise Unpaid and Partial dispense, then later settlement. No POS rows are created.
- Patient due is 11 after prescription settlement (consultation 4 + lab 7); reversing 1 of the consultation payment produces consultation due 5 and patient due 12. Lab paid remains 5.
- Accounting debit/credit totals remain balanced after payments and reversal.

### Browser evidence

Used the in-app browser against a separate local test server and synthetic database, with a real authenticated login.

- Doctor History, Lab Request, Prescription, and Results open in the persistent modal. Call and inline consultation save work. The queue remains one Patient Waiting workspace.
- Lab cascading category/type/test selection and submission created LAB000002 for Patient B / Visit 2.
- Prescription submission created RX000002 for that same patient and visit.
- Unpaid LAB000002 was collected, a numeric draft 11 and remark were saved/reopened, and completion retained Unpaid. Doctor Results displayed the modern parameter, value, unit, High flag, remark, and Completed state.
- Category Edit changed its description and retained CategoryID 6. Type, Test, Parameter, Center, Unit, and Flag edit modals were opened and saved. Test category prefill was repaired after browser validation blocked Save.
- Center-test mapping saved; selection saved, disabled, and re-enabled.
- Unpaid RX000002 showed READY TO DISPENSE with total 20/paid 0/due 20. After clicking Dispense Medicine it appeared in Dispensed Pharmacy Bills with the same amounts and no POS bill reference.
- No browser console errors were observed during Doctor result verification.

## Defect records

DEFECT: Doctor modal/context wiring and result rendering

CURRENT BEHAVIOR: At the start of this pass, missing modal roots and full-page fragment handling prevented dependable actions. History with integer IDs could crash result rendering.

EXPECTED BEHAVIOR: One waiting workspace, inline consultation, true contextual modals, authoritative patient/visit/doctor context.

ROOT CAUSE: Doctor view markup, inconsistent modal show classes, inappropriate DOM-root methods, and integer arguments to a string-typed escape helper.

FILES TO CHANGE: auth/assets/clinic.js; auth/includes/doctor-waiting-view.php; auth/includes/doctor-workspace-view.php; auth/includes/doctor-portal.php.

RISK: MEDIUM

FIX: Persistent modal shell; extract only the selected fragment; validate fetched patient/visit context; preserve selected visit after saves; fix selector and scalar casts.

TEST: Browser Call, inline save, four modal actions, actual lab/Rx submits and modern result display; forged patient and CSRF integration checks.

STATUS: RUNTIME VERIFIED for the critical action path. Structural edit controls remain a separate open defect.

DEFECT: Laboratory schema mismatch and legacy payment gate

CURRENT BEHAVIOR: Queue queries used nonexistent patient/laboratory columns. Main navigation exposed payment-locked modern work through legacy UI.

EXPECTED BEHAVIOR: Actual schema columns; unpaid/partial laboratory care allowed.

ROOT CAUSE: laboratory.php queried DOB and laboratory result-state columns that belong elsewhere; legacy processing was payment-gated.

FILES TO CHANGE: auth/pages/laboratory.php; auth/includes/lab-results.php.

RISK: MEDIUM

FIX: Use DateOfBirth and existing queue columns; show existing modern workspace by default; distinguish bridged orders from legacy rows; remove payment-only start/processing gates.

TEST: Actual-schema runtime queue, unpaid browser collection/completion, legacy role-suite processing.

STATUS: RUNTIME VERIFIED.

DEFECT: Modern lab result persistence

CURRENT BEHAVIOR: Previous handlers wrote nonexistent laboratory columns/statuses, handled only the first bridge, and searched modern parameters using laboratory.TestID.

EXPECTED BEHAVIOR: laboratory → bridge.ModernTestID → lab_results → lab_result_parameters; draft/reopen/completion must retain debt and identities.

ROOT CAUSE: Legacy and modern identities/status storage were mixed; result writes were not one order-level transaction.

FILES TO CHANGE: auth/includes/lab-results.php; auth/pages/laboratory.php.

RISK: HIGH

FIX: Lock the order and bridges, process all bridges, validate parameter ownership/type and required completion values, upsert results transactionally, store collection/completion in lab_results, keep laboratory workflow within existing values, leave finance untouched.

TEST: Multi-test unpaid order; invalid numeric and forged parameter rollback; draft reopen; completed-state write rejection; Doctor readback; browser end-to-end result.

STATUS: RUNTIME VERIFIED for the structured parameter path. Printing and review history remain open.

DEFECT: Laboratory master-data controls

CURRENT BEHAVIOR: Edit IDs were not populated correctly; posted 0 could reactivate records; Test Edit omitted category; selection offered schema-invalid null identities; center mapping lacked a control; opening Laboratory executed migration/seeding SQL.

EXPECTED BEHAVIOR: Real database-driven master data with working controls and existing schema identities.

ROOT CAUSE: Dataset/form field mismatch, checkbox parsing, nonexistent optionality in selection IDs, missing wiring, and migration execution in a page request.

FILES TO CHANGE: auth/pages/laboratory.php; auth/assets/clinic.js.

RISK: MEDIUM

FIX: Correct dataset identity/category mappings and boolean parsing; retain parameter settings during activation; require doctor and center selection; connect mapping/upsert and selection toggle controls; remove automatic page-load migration execution. Existing migration files remain available for explicit installation work.

TEST: CRUD/activation integration checks for seven masters; selection and mapping integration checks; browser edit saves, selection toggles, and mapping save.

STATUS: RUNTIME VERIFIED for these controls. This does not certify exhaustive concurrent edits or every malformed master-data input.

DEFECT: Payment-source leakage and uncollectable completed lab debt

CURRENT BEHAVIOR: Consultation confirmed totals could include lab payments with the same VisitID. Reception rejected lab collections once clinical processing had begun and could reset workflow from payment state.

EXPECTED BEHAVIOR: Each payment type uses its own authoritative reference; completed credit care retains collectible debt.

ROOT CAUSE: Payment aggregation omitted PaymentType; lab collection permitted only Requested/Awaiting Payment and always recomputed clinical state.

FILES TO CHANGE: auth/includes/finance.php; auth/pages/reception.php; auth/includes/doctor-portal.php.

RISK: HIGH

FIX: Scope totals by source column and PaymentType; allow collection on non-cancelled lab orders; preserve started/completed clinical state; reconcile patient outstanding debt after request and collection.

TEST: Later collection on completed unpaid lab; overpayment rollback; consultation reversal with a shared VisitID lab payment; debt and accounting assertions.

STATUS: RUNTIME VERIFIED for tested reference/payment paths.

DEFECT: Prescription payment reset and duplicate POS billing

CURRENT BEHAVIOR: Repricing reset paid amounts; dispense generated POS lines and required full payment.

EXPECTED BEHAVIOR: Preserve confirmed payment, reject repricing below paid, dispense independently of payment under the newly confirmed policy, retain PrescriptionReference and debt, deduct stock once.

ROOT CAUSE: Pricing UPDATE forced AmountPaid=0; dispensing contained a paid-only check and a synthetic pharmacysales INSERT loop.

FILES TO CHANGE: auth/pages/pharmacy.php.

RISK: HIGH

FIX: Reprice against confirmed prescription payments; dispense against the established bill total; remove paid-only backend/UI gate and synthetic POS insert; preserve paid/due, reconcile AccR, retain transaction and conditional stock updates.

TEST: Unpaid/Partial/Paid dispense; repricing after payment; rejected overpayment; no new payment/POS/SaleReference during credit dispense; repeated dispense; later Reception settlement; browser unpaid dispense.

STATUS: RUNTIME VERIFIED.

DEFECT: Prescription costs omitted from cost completeness reporting

CURRENT BEHAVIOR: POS-only costing omitted non-POS dispensed prescriptions and could report complete costs.

EXPECTED BEHAVIOR: Real prescription charges are included once; missing historical costs are not invented.

ROOT CAUSE: tdc_pharmacy_cogs reads POS cost snapshots only; prescriptions have no equivalent snapshot columns.

FILES TO CHANGE: auth/includes/pharmacy-costing.php; auth/pages/reports.php.

RISK: HIGH

FIX: Include unlinked dispensed prescription totals once and explicitly mark their cost coverage incomplete; withhold unavailable net profit.

TEST: Runtime report shows three prescription bills totaling 40 with unavailable cost snapshots and unavailable net profit. Legacy POS-linked prescriptions are excluded from this extra aggregation.

STATUS: RUNTIME VERIFIED for honest incompleteness reporting. Actual prescription cost capture remains unresolved; finance is not fully certified.

## Remaining real defects and unverified work

DEFECT: Doctor structural edit actions remain incomplete

CURRENT BEHAVIOR: Visit activity shows no Lab/Rx edit controls. Existing backend lab edit SQL contains an escaped Pending literal; edit handlers perform guard checks before their transaction and need concurrency review.

EXPECTED BEHAVIOR: Edit the same LaboratoryID before processing and same PrescriptionReference before dispense, with authoritative context and no stock movement.

ROOT CAUSE: Missing activity action wiring and incomplete existing edit handlers in doctor-portal.php.

FILES TO CHANGE: auth/includes/doctor-workspace-view.php; auth/includes/doctor-portal.php; auth/assets/clinic.js.

RISK: HIGH

FIX: Pending: repair handlers and wire existing forms only after locked-row validation; preserve identities. No new workspace is required.

TEST: Not certified in this pass; existing create/view/save paths are tested separately.

STATUS: FAIL.

DEFECT: Modern result printing/review history incomplete

CURRENT BEHAVIOR: Print Result is a placeholder summary; print_laboratory.php primarily renders a billing/legacy document. Doctor review updates laboratory.ReviewedAt without a completed modern review-history path.

EXPECTED BEHAVIOR: Print/read/review the completed structured result with its original identities, without historical rewrites.

ROOT CAUSE: Existing print and review handlers are not fully connected to modern result tables.

FILES TO CHANGE: auth/pages/laboratory.php; auth/print_laboratory.php; auth/includes/doctor-portal.php.

RISK: MEDIUM

FIX: Pending. The structured on-screen result path is complete, but print/review certification is not.

TEST: Source inspection only for these remaining paths.

STATUS: FAIL.

Other limitations: Single Result tests without configured parameters cannot yet complete through the structured editor; attachment handling has not been certified; the legacy empty-state text can misleadingly report no services while modern tests exist; master-data concurrency and Doctor KPI date semantics require further verification. No production-ready claim is made.

## Stale tests and failure classification

The initial 21 failures were not treated as permission to change business rules. Stale phone uniqueness and payment-before-care expectations were corrected. Later role failures were resolved by modern catalogue/parameter fixtures, a real pricing step, correct standalone POS sequence expectations after removing fake POS, removing the Payment Locked assertion, and checking configured booking methods on Patient Registration. Downstream stock failures disappeared when the prerequisite fixtures succeeded; expected stock deductions were not weakened.

New tests exposed real schema/control defects (modern result write/read, selection identity requirements, Test Edit category mapping), which were repaired rather than hidden. No remaining test failure may be classified solely from lint. Final suite counts appear in the attached text logs.

## Continuation status

LOCKED BUSINESS RULES PRESERVED: YES in the repaired and tested paths.

STALE TESTS UPDATED: YES.

PHONE NON-UNIQUE RULE PRESERVED: YES.

CREDIT CARE RULE PRESERVED: YES, including the explicitly confirmed credit dispensing policy.

OVERPAYMENT BLOCKED: YES in tested consultation, lab, and prescription collection paths.

DOCTOR: FAIL for complete module certification; critical call/inline consultation/four-modal path is runtime verified, structural editing remains open.

LABORATORY: FAIL for complete module certification; structured result and master-control paths are runtime verified, printing/review/other result modes remain open.

PHARMACY: PASS for the audited prescription pricing, credit dispensing, debt and stock workflow.

PAYMENTS: PASS for tested source linkage, collection, reversal and overpayment checks.

ACCR: PASS for tested consultation/lab/prescription aggregation and later settlement.

FINANCE: FAIL for complete certification; payments/journal balance checks pass, prescription cost capture remains unavailable and is reported honestly.

BUSINESS RULE CONFLICTS: NONE. The earlier dispensing question was resolved by the user's explicit credit-dispensing policy.

## Files currently modified

The machine-generated inventory below includes prior edits preserved by this audit. The audit's active changes are concentrated in the Doctor views/controller, clinic.js, Laboratory page/result service, finance helper, Pharmacy, Reception, pharmacy costing/report text, and runtime tests.

 M auth/assets/clinic.js
 M auth/includes/access.php
 M auth/includes/dashboard.php
 M auth/includes/data-transfer.php
 M auth/includes/doctor-portal.php
 M auth/includes/doctor-waiting-view.php
 M auth/includes/doctor-workspace-view.php
 M auth/includes/finance.php
 M auth/includes/lab-results.php
 M auth/includes/legacy-pos.php
 M auth/includes/pharmacy-costing.php
 M auth/includes/reports-center.php
 M auth/includes/workflow.php
 M auth/pages/accounting.php
 M auth/pages/doctors.php
 M auth/pages/laboratory.php
 M auth/pages/patients.php
 M auth/pages/pharmacy.php
 M auth/pages/reception.php
 M auth/pages/reports.php
 M auth/pages/setup.php
 M auth/print_prescription.php
 M database/infinityfree_migration.sql
 M database/production_install.sql
 M scripts/e2e_final_pass.mjs
 M scripts/operational_ui_cases.php
 M scripts/purchase_waiting_cases.php
 M scripts/test_role_access.php
?? Dr/
?? auth/print_laboratory.php
?? database/if0_42914892_tareydermaclinic_new.sql
?? database/laboratory_workspace_migration.sql
?? existing_db_safe_migration.sql
?? payment_ledger_safe_migration.sql
?? payment_ledger_verification.sql
?? scripts/MASTER_AUDIT_CONTINUATION.md
?? scripts/inspect_live_lab_schema.php
?? scripts/inspect_modern_lab_data.php
?? scripts/master-audit-baseline.txt
?? scripts/master-audit-pass.json
?? scripts/master-audit-pass.txt
?? scripts/master-audit-schema.json
?? scripts/master-role-current.txt
?? scripts/master_browser_router.php
?? scripts/master_workflow_test.php
?? scripts/price_controlled_e2e.mjs
?? scripts/run_laboratory_workspace_migration.php
?? scripts/runtime_modern_lab_test.php
?? scripts/schema_probe_lab.php
?? scripts/test_laboratory_workspace.php
?? tmp_lab_probe.php

Final counts: role/workflow suite 292 checks, 0 failures; focused suite 77 checks, 0 failures. Initial role failures: 21; current role failures: 0. The audit-only server on port 8142 was stopped; its synthetic browser database and temporary credentials were removed. Source changes and evidence files remain.

TAREEY A-TO-Z AUDIT CONTINUED FROM EXISTING STATE: YES
