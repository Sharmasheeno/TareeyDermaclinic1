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

The fresh file is identical to `production_install.sql`. It creates 43 tables and seeds only default roles, permissions, manual payment methods and clinic settings. It contains no users or business records and no DROP statements. Import ONE of these files into an EMPTY database only; it is not an upgrade script. Stop on any import error instead of continuing in a partially populated database.

See [FRESH_DATABASE_SETUP.md](FRESH_DATABASE_SETUP.md) for the complete fresh-install and one-time SuperAdmin instructions, including the no-terminal phpMyAdmin option.

## Application configuration

Configure the production database credentials on the server through environment variables, or create a server-only `db.local.php` with the InfinityFree SQL host (for example `sql###.infinityfree.com`), database name, database username, and password. Never use `127.0.0.1`, `localhost`, the website domain, or the local XAMPP database name on InfinityFree. Never commit those credentials.

The production installer creates the schema and roles but no password. After configuring the NEW database, create the root account with:

```text
php scripts/create_default_superadmin.php
```

This prints the username `superadmin` and a unique generated password on successful creation. Save it privately. An existing administrator (including an inactive one) or an existing `superadmin` username is left unchanged; this is not a password reset tool. You can optionally supply a strong password through `TDC_BOOTSTRAP_PASSWORD`.

If InfinityFree does not provide shell access, run the script LOCALLY with `--sql-output=PRIVATE_FILE.sql`, then import that file through phpMyAdmin after importing the schema. This mode does not connect to any database. Verify the final result is `created=1`, then remove the private SQL file. Keep it outside public web folders. Do not create an unauthenticated web setup endpoint. Do not copy local test accounts into production.

## Verification

After import, run `scripts/verify_production_readiness.php` from a trusted local environment connected to the production database. It checks role mappings, balanced accounting references, payment reference uniqueness, patient balance reconciliation, and the single-root-SuperAdmin rule.

### Patient billing adjustments
Existing databases may require `database/patient_billing_adjustments_migration.sql`. Fresh installs already include this table, Services billing links, prescription cost/price snapshots, and the free-consultation flag; do not run legacy migrations over a fresh import.

