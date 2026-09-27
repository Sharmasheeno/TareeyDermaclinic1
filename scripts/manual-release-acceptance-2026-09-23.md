# Manual release acceptance — 2026-09-23

## Verified locally

- Role and authorization suite: 292/292 pass.
- Core workflow audit: 77/77 pass.
- Button audit baseline: 144/144 pass.
- Desktop baseline: Dashboard, Reception, Doctors, Patients, Laboratory, Pharmacy, Accounting, Reports, and Setup loaded successfully in the authenticated browser session.
- Report pagination and state: verified with 15 disposable records; 10-row page, next page, date sort, and reverse date sort worked.
- Database safety audit: negative stock 0, negative due 0, paid above final 0, orphan visits/labs/prescriptions/payments 0, duplicate bridges/adjustments 0, unbalanced journals 0.
- PHP lint and `git diff --check`: no code syntax errors; only existing end-of-file whitespace warnings.

## Responsive checks completed

- Tablet CSS viewport: effective 768px wide by 1024px high across Dashboard, Reception, Patients, Doctors, Laboratory, Pharmacy, Accounting, and Reports. No document overflow was detected and the main content remained rendered.
- Mobile probe: the browser enforced a 480px CSS minimum width, so the exact requested 390px viewport could not be applied. At 480px wide by 844px high, the tested pages remained rendered without document overflow; representative Reception and Doctor dialogs fit within the viewport.

## Not verified by the available browser surface

- Exact 390x844 mobile runtime check (browser minimum width was 480px).
- Mobile modal workflows and mobile Report Center.
- Operating-system Print Preview for every report, wide reports, multi-page reports, and receipts.

The browser control available in this session does not expose viewport emulation or the OS print-preview surface. Responsive CSS and print rules exist in the source, but source inspection is not a substitute for those visual acceptance tests.

## Supplier accounting scope

**OPERATIONAL ONLY FOR CURRENT RELEASE.** Supplier purchases maintain stock, purchase totals, VAT, paid, due, and purchase reports. They are not posted to the general ledger. Full accrual accounting requires a separate phase with Accounts Payable, Inventory/Purchases/COGS, and cash-settlement posting policy.

## Release verdict

The verified desktop, authorization, workflow, financial, clinical, inventory, and database checks are clean. Release acceptance remains open until the tablet/mobile and actual print-preview checks are executed in a browser that exposes those surfaces.

TAREEY DERMA CLINIC MANUAL RELEASE ACCEPTANCE: NO
