# Role access and connected workflow

The clinic keeps its horizontal navigation, color system, and in-body section menus. Access is enforced through database-backed roles and business-action permissions at navigation, page, UI-action, and backend levels.

| Account role | Access |
| --- | --- |
| SuperAdmin (`superuser`) | Full access to every module and operation, including protected Setup administration. The root SuperAdmin cannot be deactivated, deleted, downgraded, or restricted. |
| Receptionist (`receptionuser`) | Combined Reception, Pharmacy, Laboratory and Accounting operations: patients, visits, payments, medicine inventory, POS, dispensing, lab processing/results and journal entries. Permitted reports and exports are available. No Setup, user/role administration, clinical authoring or purchase costs. |
| Doctor (`doctoruser`) | Assigned paid consultation queue, clinical notes, diagnosis, treatment and follow-up plans, prescriptions, laboratory requests, returned results, and result review. A Doctor account must be linked to one doctor directory profile. |
| Pharmacist (`pharmacyuser`) | Pharmacy dashboard, prescription queue, dispensing, point of sale, medicine inventory and sanitized purchase history. No acquisition costs or unrelated administration. |
| Laboratory Staff (`labuser`) | Paid laboratory work queue, result entry, completion, and test availability. Patient identity, test pricing, payment status, and order deletion are read-only. |

SuperAdmin can create custom roles, copy an existing permission preset, and grant only the required modules and actions. For example, an Accountant can receive `accounting.view`, `reports.view`, and `reports.export` without changing PHP source.

## Setup

`Setup` is the single configuration center. The former Settings URL redirects to Setup and is no longer shown in navigation. Setup contains only connected functionality: clinic profile and departments, users, roles, permissions, doctor configuration and account linking, specializations, laboratory catalogue, pharmacy master-data visibility, payment methods, and audit history.

## Workflow

1. Reception registers a patient, selects a doctor and consultation time, and records payment. Only fully paid consultations enter the doctor's waiting queue.
2. The assigned doctor starts the consultation and records clinical notes, diagnosis, treatment, follow-up, prescriptions, and laboratory requests.
3. Prescriptions enter the pharmacist queue directly. Dispensing validates stock, creates the sale, reduces inventory atomically, records revenue, and marks the prescription dispensed.
4. Laboratory requests return to reception for payment. Partial collections remain awaiting payment; full payment moves the request to the laboratory queue.
5. Laboratory staff record findings and complete the order without changing billing fields. The requesting doctor receives the returned result and can mark it reviewed.
6. Patient history combines consultations, prescriptions/pharmacy activity, laboratory orders/results, payments, and outstanding balances. SuperAdmin reports summarize operational and financial activity.

Notifications are generated for consultation assignment, new prescriptions, laboratory payment clearance, completed results, and dispensing events.

## Database migration

With XAMPP MySQL running, apply the idempotent workflow migration from the project directory:

```powershell
C:\xampp\php\php.exe scripts\migrate_connected_workflow.php
C:\xampp\php\php.exe scripts\migrate_setup_rbac.php
```

Both migrations are idempotent. Existing role strings and historical transactions are preserved while users are linked to database role records.

For an existing installation, apply only the operational permission update with `C:\xampp\php\php.exe scripts\migrate_operational_roles.php`. It updates role grants transactionally and checks that clinic-data table checksums remain unchanged. The full Setup migration also includes these defaults for new installations. Later custom permission changes continue to use the existing database authorization system.

Purchase `UnitPrice`, supplier totals, discounts, VAT, payments and dues are SuperAdmin-only. Other roles receive operational projections in purchase history and CSV; direct creation/void requests and purchase financial reports are denied. Cost-bearing purchase creation remains a SuperAdmin operation because inventory does not store an authoritative acquisition cost. Selling prices and patient charges are operational data.

## Local verification

Run the isolated role and workflow suite:

```powershell
C:\xampp\php\php.exe scripts\test_role_access.php
```

The suite creates a temporary database, tests all role boundaries and the connected patient journey, and drops that database afterward. It does not change clinic records or passwords.
