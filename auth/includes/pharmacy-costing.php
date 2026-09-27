<?php
declare(strict_types=1);

/**
 * Pharmacy costing uses the purchase workflow's existing unit conversion:
 * purchase UnitPrice divided by ConversionFactor gives the acquisition cost
 * of one selling unit.  That last acquired cost is copied to the sale line;
 * it is never read back from inventory for historical sales.
 */
function tdc_purchase_cost_per_sales_unit(float $purchaseUnitPrice, float $conversionFactor): float
{
    if ($purchaseUnitPrice < 0 || $conversionFactor <= 0) {
        throw new InvalidArgumentException('Invalid purchase cost conversion.');
    }
    return round($purchaseUnitPrice / $conversionFactor, 4);
}

/**
 * Return the immutable cost snapshot for a new sale line, or NULL when the
 * item has never received a trustworthy acquisition cost.
 */
function tdc_sale_cost_snapshot(array $inventoryRow): ?float
{
    if (!array_key_exists('LastAcquisitionCostPerUnit', $inventoryRow)
        || $inventoryRow['LastAcquisitionCostPerUnit'] === null
        || $inventoryRow['LastAcquisitionCostPerUnit'] === '') {
        return null;
    }
    $cost = (float) $inventoryRow['LastAcquisitionCostPerUnit'];
    return is_finite($cost) && $cost >= 0 ? round($cost, 4) : null;
}

/**
 * Calculate pharmacy revenue and COGS coverage for a period from sale-line
 * snapshots.  A period is complete only when every sale line has a snapshot;
 * unknown legacy cost is never estimated from current inventory or purchases.
 *
 * @return array{drugSold:float, drugCost:?float, grossProfit:?float,
 *   unknownRevenue:float, unknownSales:int, complete:bool}
 */
function tdc_pharmacy_cogs(PDO $pdo, string $from, string $to): array
{
    $saleStatusFilter = tdc_has_column($pdo, 'pharmacysales', 'SaleStatus') ? "SaleStatus <> 'Voided'" : '1=1';
    $stmt = $pdo->prepare(
        'SELECT SUBSTRING_INDEX(SaleID, \'-\', 1) AS SaleReference,
                SUM(COALESCE(LineTotal, 0)) AS SaleRevenue,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN 1 ELSE 0 END) AS UnknownLines,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN COALESCE(LineTotal, 0) ELSE 0 END) AS UnknownRevenue,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN 0 ELSE LineCost END) AS KnownCost
         FROM pharmacysales
         WHERE ' . $saleStatusFilter . ' AND SaleDate BETWEEN :from AND :to
         GROUP BY SaleReference'
    );
    $stmt->execute(['from' => $from, 'to' => $to]);
    $drugSold = 0.0;
    $knownCost = 0.0;
    $unknownRevenue = 0.0;
    $unknownSales = 0;
    foreach ($stmt->fetchAll() as $row) {
        $drugSold += (float) $row['SaleRevenue'];
        $knownCost += (float) $row['KnownCost'];
        $unknownRevenue += (float) $row['UnknownRevenue'];
        if ((int) $row['UnknownLines'] > 0) {
            $unknownSales++;
        }
    }
    // Prescription dispensing is a separate real transaction, never a synthetic POS.
    // Historical NULL costs stay unknown; new dispensing captures immutable per-item costs.
    $costColumn=tdc_has_column($pdo,'prescriptions','CostPerUnitSnapshot')?'pr.CostPerUnitSnapshot':'NULL';
    $stmt = $pdo->prepare("SELECT SUBSTRING_INDEX(pr.PrescriptionID,'-',1) AS PrescriptionReference, MIN(pr.TotalAmount) AS BillTotal,
        SUM(CASE WHEN $costColumn IS NULL THEN 1 ELSE 0 END) AS UnknownLines,
        SUM(COALESCE($costColumn,0)*pr.Quantity) AS KnownCost
        FROM prescriptions pr
        WHERE pr.Status='Dispensed' AND pr.DispensedAt BETWEEN :from AND :to
          AND NOT EXISTS (SELECT 1 FROM pharmacysales ps WHERE SUBSTRING_INDEX(ps.SaleID,'-',1)=pr.PharmacySaleReference)
        GROUP BY PrescriptionReference");
    $stmt->execute(['from'=>$from,'to'=>$to]);
    foreach ($stmt->fetchAll() as $prescription) {
        $drugSold += (float)$prescription['BillTotal'];
        $knownCost += (float)$prescription['KnownCost'];
        if((int)$prescription['UnknownLines']>0) { $unknownRevenue += (float)$prescription['BillTotal']; $unknownSales++; }
    }
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM payments WHERE PaymentType IN ('POS','Pharmacy') AND PaymentStatus='Confirmed' AND PaidAt BETWEEN ? AND ?");
    $stmt->execute([$from,$to]);$cash=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,r.TotalAmount-COALESCE((SELECT SUM(p.Amount) FROM payments p WHERE p.PaymentType='Pharmacy' AND p.PrescriptionReference=r.Reference AND p.PaymentStatus='Confirmed' AND p.PaidAt<=?),0))),0) FROM (SELECT SUBSTRING_INDEX(PrescriptionID,'-',1) AS Reference,MIN(TotalAmount) AS TotalAmount FROM prescriptions WHERE Status='Dispensed' AND DispensedAt BETWEEN ? AND ? GROUP BY Reference) r");
    $stmt->execute([$to,$from,$to]);$rxDue=(float)$stmt->fetchColumn();
    $complete = $unknownSales === 0;
    return [
        'drugSold' => round($drugSold, 2),
        'cashCollected' => round($cash,2),
        'prescriptionReceivable' => round($rxDue,2),
        'drugCost' => $complete ? round($knownCost, 2) : null,
        'grossProfit' => $complete ? round($drugSold - $knownCost, 2) : null,
        'unknownRevenue' => round($unknownRevenue, 2),
        'unknownSales' => $unknownSales,
        'complete' => $complete,
    ];
}
