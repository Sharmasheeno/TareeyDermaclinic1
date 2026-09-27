# InfinityFree deployment

## Existing deployed database

1. Back up the InfinityFree database in phpMyAdmin.
2. Select the existing clinic database.
3. Import `infinityfree_upgrade.sql`.
4. Keep all existing patients, visits, payments, inventory, purchases, and accounting history.

The upgrade file is additive and rerunnable. It creates missing tables, columns, indexes, roles, permissions, payment methods, clinic defaults, and the Services workflow tables (categories, subservices, assignments, and service billing) without dropping business data. Existing deployments must import the updated `infinityfree_upgrade.sql` so these new service tables and the `payments.ServiceAssignmentID` link are created.

## Fresh database

1. Create an empty MySQL/MariaDB database in InfinityFree.
2. Import `infinityfree_fresh.sql`.
3. Create the single production root account using the bootstrap process below.

The fresh file is destructive if imported into a non-empty database because it drops application tables first. It contains no demo users or business records.

## Application configuration

Configure the production database credentials on the server through environment variables, or create a server-only `db.local.php` with the InfinityFree SQL host (for example `sql###.infinityfree.com`), database name, database username, and password. Never use `127.0.0.1`, `localhost`, the website domain, or the local XAMPP database name on InfinityFree. Never commit those credentials.

The production installer creates the schema and roles but no password. Create the root account with:

```text
TDC_BOOTSTRAP_PASSWORD="A strong unique password" php scripts/create_default_superadmin.php
```

If InfinityFree does not provide shell access, run the same bootstrap logic once through a protected deployment-only method, then remove that method. Production should retain one active root SuperAdmin; the local `e2e_local_superuser` is excluded from production checks and should not be copied to the live database.

## Verification

After import, run `scripts/verify_production_readiness.php` from a trusted local environment connected to the production database. It checks role mappings, balanced accounting references, payment reference uniqueness, patient balance reconciliation, and the single-root-SuperAdmin rule.

### Patient billing adjustments
Run database/patient_billing_adjustments_migration.sql (or the appended equivalent in the deployment SQL) once. It adds bill-level discount/tax snapshots without changing existing totals or payment rows.

