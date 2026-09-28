# Fresh database and one-time SuperAdmin

## 1. Import the schema

1. Create a NEW EMPTY MySQL/MariaDB database and select it in phpMyAdmin.
2. Choose Import and select `database/infinityfree_fresh.sql` (or `database/production_install.sql`; they are identical). Import only one.
3. Confirm the import succeeds without errors. Expect 43 application tables.

The file does not select a hardcoded database name, drop tables, copy patient records, or create users. It includes current Services billing, patient bill adjustments, prescription snapshots and the free-consultation flag. It is for fresh installation, NOT updating an existing clinic database. Back up existing databases before any deployment; do not import this into them.

## 2. Create the main user — choose ONE method

The username is **superadmin**. The user-requested local default password is **superadmin@2026**. Change this shared default before non-local use. Passwords are stored using PHP `password_hash`, compatible with the application's login. Existing root/admin accounts (even inactive ones) and an existing `superadmin` username block creation; nothing is reset or promoted. A `TDC_BOOTSTRAP_PASSWORD` environment variable overrides the local default and must meet the stronger password requirements below.

### A. InfinityFree / phpMyAdmin without server terminal access

Run this in PowerShell from the project folder on your computer:

```powershell
C:\xampp\php\php.exe scripts\create_default_superadmin.php "--sql-output=$env:USERPROFILE\Downloads\tareey-superadmin-once.sql"
```

This works even when the local database is stopped. It generates a private SQL file and displays the proposed login credentials. It does not change any database. Keep the password in a password manager.

In phpMyAdmin, select the NEW database from step 1 and import `tareey-superadmin-once.sql`. The final result must be `created = 1`. If it is `0`, the account was not created (an administrator/username already exists or the active system role is missing); the newly generated password does NOT replace the existing password. Stop and investigate rather than deleting existing users.

Delete the private SQL file after successful import. It contains a password hash, not plaintext, but is still sensitive. Do not upload it to `htdocs`, put it in a release archive, share it, or commit it. Reimporting is a no-op. The generator also refuses to overwrite an existing output file.

### B. Local XAMPP or a server with PHP CLI

First configure `db.local.php` for the NEW database (see `db.local.php.example`). This file overrides environment variables, so verify the target carefully. Do not leave it pointing to your existing clinic database.

Run from the project folder:

```powershell
C:\xampp\php\php.exe scripts\create_default_superadmin.php
```

On a host with PHP on PATH, use `php scripts/create_default_superadmin.php` instead.

The script prints the local default password after successful creation. A repeat run leaves the account/password unchanged and does not print a replacement password. `--reset-password` is deliberately unsupported. The script is CLI-only; opening it in a browser returns 404.

For an operator-supplied password, set `TDC_BOOTSTRAP_PASSWORD` before running and clear it afterward. It must contain uppercase, lowercase, a digit and a symbol and be 12–72 bytes. Supplied passwords are not printed. Avoid putting real passwords in source files or command history.

## 3. Connect and check the application

Configure the deployed application's private `db.local.php` with the new database host, name, user and password. On InfinityFree use the actual SQL hostname supplied by the panel, not localhost. Never commit credentials.

Log in through the normal application login with `superadmin` and the saved password. Confirm Setup access, configure clinic details, doctors/schedules and service/laboratory/inventory catalogs. A fresh install intentionally does not copy your old clinic configuration or business records.

No production database or real administrator account was changed while preparing these files.

## Verification performed — 28 September 2026

Isolated MariaDB 10.4.32 integration tests passed with zero failures:

- Both SQL files are identical; all 43 tables import without follow-up ALTER migrations.
- No business rows/users seeded; expected schema additions and SuperAdmin permission grants present.
- First bootstrap creates one active root; PHP password verification succeeds.
- Repeat setup preserves the password and does not create duplicates.
- Existing differently named/inactive administrators and non-admin username collisions are preserved.
- Offline phpMyAdmin SQL creation, password verification and repeat-import safety pass.
- Weak/oversized passwords rejected; no plaintext password in generated SQL.

Not tested: a separate MySQL server, your hosted import, or a browser login against the new hosted database. Those still need to be checked after you import and configure it.

Developers can rerun `scripts/test_fresh_install.php` against an explicitly selected isolated test server using `TDC_TEST_DSN`, `TDC_TEST_USER`, and `TDC_TEST_PASSWORD`. It never reads `db.local.php`; it creates and removes only its randomly named disposable database. `scripts/generate_production_install.php` regenerates both fresh SQL files from a trusted, fully migrated schema; review its output before release.
