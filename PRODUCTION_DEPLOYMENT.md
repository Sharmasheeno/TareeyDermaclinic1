# Production Deployment

## Important

Do not upload the local `db.php` with XAMPP credentials. Do not run deployment commands against the live database until a backup exists. This application records manual payments only and makes no external payment requests.

## Files

Upload the PHP application, `auth/`, `database/`, and required assets. Do not upload local test output, screenshots, temporary databases, or development-only credentials.

## Database

1. Create or select the InfinityFree MySQL database.
2. For a new database, import `database/production_install.sql`. For an existing clinic database, run `database/production_migration.sql` in phpMyAdmin after confirming the data must be preserved.
3. Do not import development seed data into production.
4. Verify that `payments.PaymentMethod` is `VARCHAR(80)`.
5. Verify that `reference_sequences` exists.
6. Verify the Reception role grants against `auth/includes/operational-role-defaults.php`.

The migration is intended to be idempotent, but take a database backup before running it.

## Bootstrap SuperAdmin

Never commit a password. From a protected CLI or private deployment shell, set a temporary secret through the environment and run:

```powershell
$env:TDC_BOOTSTRAP_PASSWORD = '<private temporary password>'
C:\xampp\php\php.exe scripts\create_default_superadmin.php --reset-password
Remove-Item Env:TDC_BOOTSTRAP_PASSWORD
```

On hosting where CLI is unavailable, use a temporary protected bootstrap mechanism supplied by the hosting panel, then remove it after the first login. Change the password immediately after login and remove any bootstrap tooling exposed through HTTP.

## Post-deployment checks

- Login as SuperAdmin.
- Create one Reception, Doctor, Laboratory, and Pharmacy account.
- Verify Reception does not see Setup administration or acquisition costs.
- Add a custom manual payment method such as EVC Plus.
- Record a test payment and verify the persisted method and accounting batch.
- Verify doctor working days/hours and booking collision behavior.
- Review audit history and remove all test records before opening the clinic.
- Review the active SuperAdmin list deliberately on existing installations; migrations do not delete or deactivate existing privileged users automatically.

## Release warning

The current repository remains NO-GO until payment reversal/correction, pharmacy lifecycle consolidation, and a clean schema-only production installer are completed and executed. Booking collision protection and the 265-check integration suite currently pass.
