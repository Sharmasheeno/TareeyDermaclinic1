# Production Readiness Audit

## Scope

Audited the PHP/PDO clinic workflows, production migration, RBAC defaults, payments, references, accounting, patient balances, pharmacy, laboratory, authentication, and existing tests. No live InfinityFree database was contacted.

## Findings

| Severity | Issue | Source | Correction / status |
|---|---|---|---|
| HIGH | Production migration did not grant Reception the full laboratory/pharmacy operational matrix. | `database/infinityfree_migration.sql` vs `auth/includes/operational-role-defaults.php` | Added the missing operational grant block and verification coverage. |
| HIGH | `payments.PaymentMethod` was an ENUM in the connected migration, blocking custom manual labels such as EVC Plus. | `scripts/migrate_connected_workflow.php` | Migration now converts it to `VARCHAR(80)`; production SQL also does this. No online payment integration is added. |
| HIGH | The CLI workflow migration omitted inventory packaging and purchase financial columns used by Pharmacy. | `scripts/migrate_connected_workflow.php`, `auth/pages/pharmacy.php` | Added idempotent columns for packaging, reference, discount, and VAT. |
| HIGH | Automatic revenue posting created only a credit line, producing unbalanced accounting batches. | `auth/includes/workflow.php` | Revenue posting now creates a receiving-asset debit and revenue credit, with method-based internal account labels. |
| HIGH | Reference generation used `MAX()+1`, unsafe under concurrent requests. | `auth/includes/workflow.php` | Added `reference_sequences` with row locking; legacy fallback remains only when the table is absent. |
| HIGH | Known bootstrap password was committed in source. | `scripts/create_default_superadmin.php` | Removed static password. Bootstrap uses `TDC_BOOTSTRAP_PASSWORD` or a generated random password. |
| HIGH | Patient balance is manually incremented/decremented across modules and can drift from consultation, lab, and pharmacy source balances. | `auth/pages/reception.php`, `auth/pages/pharmacy.php`, `auth/pages/patients.php` | Current reconciliation verifier passes, but a centralized derived-balance service is still required. |
| HIGH | Reception pharmacy billing and Pharmacy dispensing remain competing lifecycle paths. | `auth/pages/reception.php`, `auth/pages/pharmacy.php` | Not fully unified in this pass. This remains a release blocker until one authoritative prescription-sale lifecycle is enforced. |
| HIGH | Booking checks working hours but had no server-side occupied-slot collision check inside the booking transaction. | `auth/pages/reception.php` | Fixed with a 30-minute overlap check and per-doctor MySQL advisory lock inside the booking transaction. |
| MEDIUM | Notifications fall back to `users.role`, which does not fully resolve custom RBAC roles. | `auth/includes/workflow.php` | Exact-user notifications work; permission-based group resolution remains to be implemented. |
| MEDIUM | Login throttling was session-only and could be bypassed by clearing cookies. | `auth/auth.php`, `login_attempts` migration | Fixed with MySQL username+IP throttling and a temporary block. |
| MEDIUM | Clean production installer without demo data is not yet separated from the historical dump. | `tareydermaclinic.sql` | Not fully repaired in this pass. A clean schema-only installer and safe seed must be produced before release. |
| MEDIUM | Existing role/workflow tests seed from the active database rather than proving the exact official production SQL path. | `scripts/test_role_access.php` | Existing tests remain useful, but exact clean-install verification is still required. |
| LOW | Two local project copies exist and can point at different databases. | OneDrive copy and Downloads copy | Operational limitation documented; use one canonical checkout before deployment. |

## Confirmed validation

- PHP lint passed for the changed workflow, migration, reception, and bootstrap files.
- Local migration completed successfully.
- Local `payments.PaymentMethod` is `VARCHAR(80)`.
- Local `reference_sequences` table exists.
- No live InfinityFree database was accessed.
- Existing disposable role/workflow suite: **265 checks, 0 failures**.
- Full PHP lint: **all PHP files passed**.

## Release decision

**NO-GO.** HIGH issues remain around payment reversal/correction, the duplicate pharmacy lifecycle, and the clean production installer. The local verifier also reports four existing active SuperAdmins; they are preserved for deliberate admin review rather than silently deleted.
