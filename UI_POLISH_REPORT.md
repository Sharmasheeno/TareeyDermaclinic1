# UI consistency and medicine usability report

Date: September 16, 2026

## Focused medicine correction

- `Inventory.SalesUnit` is an optional free-text unit label, saved on creation/editing and displayed in inventory and stock/expiry reports. Purchases retain this label; received stock is calculated separately as rounded purchase quantity times ConversionFactor. POS and prescription dispensing decrement numeric stock and use SellingPrice. The label does not itself change calculations.
- No database/schema migration was introduced by this correction. Existing inventory editing writes current QuantityInStock; that behavior is preserved and labelled **Quantity in Stock** during editing, versus **Opening Quantity** on creation.
- Removed the one-field Advanced Inventory Settings accordion. Unit now sits beside Category in the normal form. Standard unit choices include Tablet, Capsule, Bottle, Tube, Box, Sachet, Vial, Ampoule, Piece, Pack and Other. Existing custom unit strings are added to the dropdown when editing and remain intact.
- Category remains free text: Setup Pharmacy summarizes existing inventory categories and has no separately configured category catalogue. No second category system was created.
- Selling Price has a short “Price per selected unit” helper. Name, selling price and opening quantity remain required; numeric validation and non-negative constraints are preserved.
- Buttons now read Save Medicine and Update Medicine. Desktop modal max-width is 760px, with two-column fields collapsing on narrow screens.
- No purchase/acquisition cost field was added to medicine forms or payloads.

## Green button correction

The shared `.permission-toolbar span` and `.permission-save-bar span` rules could override the text color inside green buttons. Shared success-button rules now explicitly own the white foreground for nested spans/icons. This is scoped to success actions; other SVGs retain their semantic colors. Disabled buttons use a neutral background and foreground instead of translucent green. Permissions compares the current selections with the initially saved selections, disables Save when unchanged, and re-enables it for actual changes. Returning to the original selections disables Save again.

## Earlier consistency work retained

- Consolidated semantic action buttons, spacing tokens, form controls and table styling in clinic.css; removed conflicting legacy page rules.
- Reused the existing SVG helper for navigation, actions and modal headings.
- Shared confirmation dialogs replace native destructive confirmation prompts; keyboard dismissal, focus return and confirmation results are handled centrally.
- Shared field labels/modal titles gain accessible associations; icon controls reuse accessible labels as tooltips.
- Profile dropdown uses compact icon rows and an identity block, with outside click, Escape and keyboard entry behavior.
- Report Center uses subtle category accents and KPI icons. Visual review caught and fixed KPI icon/label overlap.
- Login uses shared form/button styles. Setup inactive badges are neutral; active badges use success styling.
- Reception, Doctors, Laboratory, Pharmacy, Accounting, reports and Setup retain their operational structure and business workflows.

## Verification

- 265 server role/workflow checks: passed, including medicine creation for SuperAdmin, Reception and Pharmacy; unit/price/stock persistence; rejection of negative price/stock/reorder values; permission grant/revoke persistence; purchase conversion/void and operational workflows.
- 120 real HTTP checks: passed. Download checks cover response status, CSV content type, attachment filename, non-empty content and parsed headers/rows. All 20 reports are included. Doctor/patient/user templates and exports, waiting exports, and role-specific purchase exports are included.
- Real multipart doctor/patient/user imports persist records; malformed CSVs are rejected. User exports exclude passwords. All three medicine roles update unit, price and stock through HTTP and values are verified in subsequent responses.
- 532 full browser checks passed, followed by 61 focused checks after the mobile toolbar correction. The focused checks include enabled/disabled permission Save states, white text/icons, hover, keyboard focus, compact mobile search, and medicine form population. Eight additional purchase/waiting browser checks passed.
- Browser test results are recorded in `scripts/ui-consistency-test-results.json`. The suite renders 1920, 1366, 1080, 820 and 480px across major screens, all 20 reports and Setup sections. It checks overflow, buttons, JavaScript, profile interaction, confirmations, Add/Edit Medicine, modal focus/reopening, permission Save states, and browser PDF output for reports.
- PHP syntax and shared JavaScript syntax checked; git diff whitespace checked.
- Test writes use disposable databases, dropped at completion. No clinic records were created/edited by this correction.

## Limits and remaining configuration issue

The live database currently grants Reception doctor permissions (`doctor.workspace`, `doctors.view`, `doctors.manage`, `doctors.import`, `doctors.export`). Those settings were preserved as instructed. The role regression suite establishes its stated restricted Reception baseline only in its disposable database. Purchase acquisition cost remains separately restricted to SuperAdmin. If the live Reception account should lack doctor access, that is an existing Setup configuration issue, not a change made by this pass.

HTTP endpoints and multipart operations were exercised directly. Native OS download/save/print dialogs, a physical printer, Excel desktop opening, and every individual on-screen download click were NOT TESTED. Browser-generated PDF output was tested; no standalone Excel/PDF generator was added. Browser checks run in headless Chrome on Windows, not Safari/Firefox or physical mobile devices. Not every possible user-entered string or configuration has been rendered. Login appearance and unauthenticated/logout redirects were tested; a full interactive password login was NOT TESTED in this pass.

The latest focused application correction modified `auth/pages/pharmacy.php`, `auth/pages/setup.php`, and `auth/assets/clinic.css`; test harnesses and this report were updated alongside it. Other files below include the prior authorized work.

## Files

The status snapshot below includes changes inherited from earlier passes as well as this correction. `M` denotes modified tracked files; `??` denotes newly created files. No tracked files were removed. The temporary codemod script was deleted after use. Changes are left in the working tree; this pass was not committed or pushed.

```text
 M ROLE_ACCESS.md
 M auth/assets/clinic.css
 M auth/assets/clinic.js
 M auth/auth.php
 M auth/includes/doctor-portal.php
 M auth/includes/doctor-waiting-view.php
 M auth/includes/doctor-workspace-view.php
 M auth/includes/profile.php
 M auth/includes/reports-center.php
 M auth/includes/ui.php
 M auth/pages/accounting.php
 M auth/pages/doctors.php
 M auth/pages/home.php
 M auth/pages/laboratory.php
 M auth/pages/patients.php
 M auth/pages/pharmacy.php
 M auth/pages/reception.php
 M auth/pages/reports.php
 M auth/pages/setup.php
 M scripts/migrate_setup_rbac.php
 M scripts/purchase-waiting-test-results.txt
 M scripts/test_role_access.php
?? UI_POLISH_REPORT.md
?? auth/includes/operational-role-defaults.php
?? scripts/migrate_operational_roles.php
?? scripts/operational_ui_cases.php
?? scripts/test_ui_consistency.mjs
?? scripts/test_ui_http.mjs
?? scripts/ui-consistency-test-results.json
?? scripts/ui-focused-test-results.json
?? scripts/ui-http-test-results.json
?? scripts/ui_http_router.php
```

## Purchase unit-label normalization (follow-up)

- Root cause of the invalid labels: Inventory.SalesUnit legitimately holds numeric strings for legacy rows (mx = 1, bx = 12). The purchase row used the raw SalesUnit as a unit name and pluralized it by appending s, producing "(1s)" and "per 1".
- Added tdc_norm_unit (PHP) and normUnit (JS): empty, numeric, "(n)"-style and per/unit/units/ns placeholder values collapse to a neutral fallback ("Unit" for purchase labels, an em dash for the inventory table).
- Added tdc_plural_unit / pluralUnit so labels pluralize sensibly (Tablet to Tablets, Box to Boxes).
- Normalization is applied at the data source: the purchaseUnitOptions dataset, the initial PHP row render, the row meta line, and the inventory list. It is not hidden with CSS.
- Purchase as now reads Individual tablets / Box (100 tablets) instead of database terminology. Selling Price shows a per-unit helper that always reflects the base inventory unit, regardless of purchase mode.
- Package mode hides the package option entirely for medicines with no package configuration and shows a small muted "No package configured" note instead of a large disabled control.
- Verified against live records: paractamol (Tablet, no package config, individual only), mx/bx (numeric SalesUnit, normalized), and tablet + Box + 100 (individual + full package). PHP lint and extracted-JS node --check both pass.
