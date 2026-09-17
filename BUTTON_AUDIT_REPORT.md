# Button audit — 2026-09-17

## Scope and honest certification

This is a partial system-wide audit with representative runtime verification, not proof that EVERY action works. Inventory presence and CSS classes are not functional PASS criteria.

- 60 rendered fixture variants inventoried.
- 1,937 actionable instances, including repeated navigation, links, summaries and hidden modal controls; not unique buttons or individually executed actions.
- 455 native button elements; 496 button-like elements (overlapping populations).
- 0 empty/hash-only anchors in these fixtures.
- 0 confirmed broken routes/handlers repaired. This does not prove that none remain.
- 2 unavailable prescription controls corrected: Add Item and Send to Pharmacy now have native disabled attributes when the medicine catalogue is empty. That branch remains untested at runtime.
- 15 color/style assignments corrected at template/helper-definition level: 7 export/download definitions, report Filter, Activate, Save Historical Method, reception registration and booking submission, and 3 Clear links. Shared definitions expand into multiple instances. Excel/PDF helper variants were inspected only.

## Changes

Exports/downloads use gray, Filter uses navy, activation/save/registration/booking use green. Print helper explicitly uses primary; its former info alias already rendered navy. Reception's window.print action now says Print / Save PDF rather than Export PDF. Existing destructive deactivation remains red because it revokes account access.

No application POST handler, financial formula, patient/doctor link, inventory calculation, lab fee or report calculation was modified. Shared clinic.css was not modified or duplicated.

Financial previews previously fixed screen width at 210mm. A screen-only responsive rule now constrains both statements, expands their content area and wraps date labels. Print styling and all report values remain unchanged.

## Runtime evidence

- Backend/RBAC: 290 checks passed against disposable schemas.
- HTTP: 146 checks passed, including CSV headers/content, routes, imports and redirects.
- Dedicated button checks: 144 passed.
- PHP lint: 51 PHP files passed before the final report-layout edit; reports.php also passed after that edit.
- Focused browser regression: 64 passed.
- Full browser regression: 628/628 passed after the responsive report fix.

Real browser actions verified Setup stylesheet loading, red Deactivate, confirmation opening, persisted deactivation, green Activate and persisted activation. Journal tests verified Add/Remove Line and disabled-gray to valid-navy to invalid-gray transitions. Balanced posting was independently tested through PHP request handlers.

Shared primary/success/danger/secondary computed normal colors, hover color retention and disabled gray passed. Permission Save dirty state, keyboard focus and modal focus-return checks passed. These are representative state tests, not exhaustive control-family certification.

Backend tests exercise consultation save, prescription creation, lab request/results, sale/purchase completion and void, reversals and access denials. Report browser PDF generation is exercised. Not every named action was clicked through the browser.

## Module and semantic certification

Accounting, Reception, Doctors, Patients, Laboratory, Pharmacy, Reports and Setup: representative tests PASS; exhaustive audit INCOMPLETE.

Primary navy and CSV-export gray: PASS for classified fixture controls. Create green, danger red, other utilities, all disabled/hover/focus/active families and icons: INCOMPLETE beyond tested cases. RBAC: PASS within regression scope, not a full authorization proof. PHP lint: PASS. HTTP: PASS. Full UI regression: PASS (628 checks) after constraining the 210mm financial previews on small screens.

## Remaining gaps

- Finish individual route/handler/permission mapping and state-dependent fixtures; many inventory entries remain NOT INDIVIDUALLY TESTED.
- Runtime-test empty medicine/lab configuration, standalone prescription print, all row-action branches and all toolbar/icon dimensions.
- Warning aliases still render gray, mostly for Edit/Rename; migrating these and genuine amber semantics remains outstanding.
- Unreachable legacy doctor markup after an unconditional return was not treated as live buttons or changed.

The medicine-form browser assertion expected obsolete Unit text. Raw DOM confirmed Base Unit * across all three fixtures; only that test expectation changed. New harness navigation timing and duplicate-variable problems were resolved before its final passing run.

## Evidence and reproduction

Evidence under scripts: button-action-inventory.json, button-test-results.json, button-audit-run.txt, ui-http-test-results.json, ui-consistency-test-results.json and ui-focused-test-results.json.

Inventory contains per-page labels, computed colors, visibility, disabled state, href, form method/action and action discriminator. It explicitly does not mark individual actions functional merely because they exist.

From the project root, run XAMPP PHP with scripts/test_role_access.php --http --buttons, then Node with scripts/test_ui_consistency.mjs. The runner creates/drops disposable schemas. The local test router stays secret-protected, loopback-only and CLI-server-only, with an explicit fixed asset allowlist. Clinic records were not mutated by tests.
