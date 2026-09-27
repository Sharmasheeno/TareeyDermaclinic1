<?php
declare(strict_types=1);

/**
 * Repair legacy dispensed prescription lines whose immutable cost snapshot
 * was not written. A repair is allowed only when an exact medicine name has
 * a purchase recorded before dispensing; otherwise the line remains unknown.
 */
require_once __DIR__ . '/../db.php';

$pdo->beginTransaction();
try {
    $find = $pdo->query(
        "SELECT pr.PrescriptionID, pr.MedicationName, pr.DispensedAt,
                pu.UnitPrice, pu.ConversionFactor
         FROM prescriptions pr
         JOIN purchases pu
           ON LOWER(TRIM(pu.ItemName)) = LOWER(TRIM(pr.MedicationName))
          AND pu.PurchaseDate <= pr.DispensedAt
         WHERE pr.Status = 'Dispensed'
           AND pr.CostPerUnitSnapshot IS NULL
         ORDER BY pr.PrescriptionID, pu.PurchaseDate DESC, pu.PurchaseID DESC"
    );
    $update = $pdo->prepare(
        'UPDATE prescriptions
            SET CostPerUnitSnapshot = :cost
          WHERE PrescriptionID = :id
            AND Status = \'Dispensed\'
            AND CostPerUnitSnapshot IS NULL'
    );
    $repaired = [];
    $seen = [];
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string) $row['PrescriptionID'];
        if (isset($seen[$id])) continue;
        $factor = (float) $row['ConversionFactor'];
        $unitPrice = (float) $row['UnitPrice'];
        if ($factor <= 0 || $unitPrice < 0) continue;
        $cost = round($unitPrice / $factor, 4);
        $update->execute(['cost' => $cost, 'id' => $id]);
        if ($update->rowCount() === 1) {
            $repaired[] = ['prescription' => $id, 'medicine' => (string) $row['MedicationName'], 'cost' => $cost];
            $seen[$id] = true;
        }
    }
    $pdo->commit();
    echo json_encode(['repaired' => $repaired, 'count' => count($repaired)], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
