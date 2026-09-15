# TareeyDermaclinic

Clinic management system for reception, doctors, laboratory, pharmacy, accounting, reporting, and role-based administration.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Import `tareydermaclinic.sql` into MySQL.
3. Run the workflow migration:

   ```powershell
   C:\xampp\php\php.exe scripts\migrate_connected_workflow.php
   ```

4. Create the default SuperAdmin account when needed:

   ```powershell
   C:\xampp\php\php.exe scripts\create_default_superadmin.php
   ```

5. Open `http://127.0.0.1:8000/auth/auth.php` when serving the project on port 8000.

## Verification

Run the role and connected-workflow checks:

```powershell
C:\xampp\php\php.exe scripts\test_role_access.php
```
