# Shared report filter verification — 27 September 2026

## Root cause

The shared toolbar is rendered by `tdc_rc_render_report` in `auth/includes/reports-center.php`. Its `.report-search-field` is a column flex container. Generic `.table-filter { flex: 1 1 240px }` applied to the nested search label, making it 240px tall despite a 40px height. Generic absolutely positioned search icons also collided with the report input's smaller padding. A shared 900px sidebar rule overrode the page's mobile stacking rule.

## Shared files changed

- `auth/assets/clinic.css`: explicitly size the nested search label to 40px, reset its generic minimum width, keep the icon in normal flex flow, retain a 260px desktop search minimum and full-width mobile behavior, and stack the live report sidebar below 760px.
- `auth/pages/reports.php`: remove the duplicate inline filter toolbar CSS so the shared stylesheet is authoritative.

No query, date calculation, search, status, export URL, print handler or database logic changed.

## Live verification results

| Check | Result |
| --- | --- |
| Service Billing search | PASS |
| Doctor Consultation search | PASS |
| Reception report search | PASS |
| Patient report search | PASS |
| Laboratory report search | PASS |
| Pharmacy report search | PASS |
| Accounting report search | PASS |
| Desktop search minimum width | PASS — measured 322–348px across module samples at 1440px |
| Search height | PASS — 40px in every sampled report |
| Focus state | PASS — indigo rgb(46,49,146), subtle 3px ring |
| Icon/text separation | PASS — 8px gap |
| Filter wrapping | PASS — no intersecting filter boxes or toolbar overflow |
| Tablet responsive | PASS — 768px and 1024px |
| Mobile responsive | PASS — 480px; sidebar stacks, search 366px wide |
| Query value preservation | PASS — E2E search, Partial status, both dates retained after Apply |
| Reset | PASS — search/status/dates cleared |
| Export regression | PASS — filtered CSV downloaded through the UI |
| Print regression | PASS for unchanged handler and print CSS inspection; native print preview not exercised |
| PHP syntax | PASS |
| JS syntax | PASS — clinic.js; no JavaScript changes |

Service Billing search widths at actual observed viewport widths: 1440 → 348px; 1200 → 300px; 1024 → 438px; 768 → 400px. Controls remained 40px high without overlap. Browser scaling required half-size viewport requests; actual `innerWidth` was checked separately. The browser minimum was 480px, so 390px is not certified. Temporary viewport settings were reset.

Screenshot capture on this browser is scaled/clipped; the attached focused toolbar crop shows the corrected search height and icon. Verification of full toolbar geometry used live DOM bounding boxes. All changes and checks were local only; no production upload or record mutation.
