# Role access and connected workflow

The clinic keeps its existing horizontal navigation, color system, and in-body section menus. Access is enforced on the server for these five login roles only.

| Account role | Access |
| --- | --- |
| SuperAdmin (`superuser`) | Full access to every module and CRUD operation, including staff accounts, doctors, patients, laboratory, pharmacy purchases and inventory, accounting, reports, and settings. |
| Receptionist (`receptionuser`) | Reception dashboard, patient registration and updates, consultation booking, consultation payment, laboratory billing/payment, and reception pharmacy billing. |
| Doctor (`doctoruser`) | Assigned paid consultation queue, clinical notes, diagnosis, treatment and follow-up plans, prescriptions, laboratory requests, returned results, and result review. A Doctor account must be linked to one doctor directory profile. |
| Pharmacist (`pharmacyuser`) | Pharmacy dashboard, prescription queue, dispensing, point of sale, purchases, inventory, stock alerts, and pharmacy receipts. No accounting, reports, settings, or user management. |
| Laboratory Staff (`labuser`) | Paid laboratory work queue, result entry, completion, and test availability. Patient identity, test pricing, payment status, and order deletion are read-only. |

There is no Accountant login role. Accounting and financial reports are SuperAdmin-only.

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
```

The full `tareydermaclinic.sql` export also contains the connected workflow schema for a fresh installation.

## Local verification

Run the isolated role and workflow suite:

```powershell
C:\xampp\php\php.exe scripts\test_role_access.php
```

The suite creates a temporary database, tests all role boundaries and the connected patient journey, and drops that database afterward. It does not change clinic records or passwords.
