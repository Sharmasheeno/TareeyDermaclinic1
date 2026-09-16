<?php
declare(strict_types=1);

/** Explicit operational grants; never used as an authorization bypass. */
function tdc_operational_role_defaults(): array
{
    $pharmacy = ['pharmacy.view','pharmacy.prescriptions.view','pharmacy.dispense','pharmacy.pos','pharmacy.inventory.view','pharmacy.inventory.manage','pharmacy.purchases.manage'];
    $laboratory = ['laboratory.view','laboratory.process','laboratory.result.create','laboratory.result.edit','laboratory.complete','laboratory.results.view'];
    return [
        'receptionuser' => array_merge($pharmacy, $laboratory, [
            'dashboard.view','reception.view','patients.view','patients.create','patients.edit','patients.delete','patients.history','patients.import','patients.export',
            'visits.view','visits.create','visits.edit','visits.assign','consultations.view','consultations.create',
            'lab_billing.view','lab_billing.payment','pharmacy_billing.view','pharmacy_billing.payment',
            'accounting.view','accounting.transactions.view','accounting.expenses.create','accounting.expenses.edit','reports.view','reports.export',
        ]),
        'pharmacyuser' => array_merge(['dashboard.view'], $pharmacy),
        'labuser' => array_merge(['dashboard.view'], $laboratory),
    ];
}

function tdc_apply_operational_role_defaults(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $grant = $pdo->prepare('INSERT IGNORE INTO RolePermissions (RoleID,PermissionID) SELECT r.RoleID,p.PermissionID FROM Roles r CROSS JOIN Permissions p WHERE r.RoleKey=? AND p.PermissionKey=?');
        foreach (tdc_operational_role_defaults() as $role => $permissions) {
            foreach ($permissions as $permission) $grant->execute([$role,$permission]);
        }
        // The combined account does not inherit administrative or clinical authoring rights.
        $pdo->exec("DELETE rp FROM RolePermissions rp JOIN Roles r ON r.RoleID=rp.RoleID JOIN Permissions p ON p.PermissionID=rp.PermissionID WHERE r.RoleKey='receptionuser' AND (p.PermissionKey LIKE 'setup.%' OR p.PermissionKey IN ('doctor.workspace','doctors.manage','doctors.import','consultations.edit','consultations.complete','pharmacy.prescription.create','laboratory.request'))");
        $pdo->prepare('UPDATE Roles SET Description=? WHERE RoleKey=?')->execute(['Combined reception, pharmacy, laboratory and accounting operations. Acquisition cost and administration remain restricted.','receptionuser']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
