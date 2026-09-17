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
    $stmt = $pdo->prepare(
        'SELECT SUBSTRING_INDEX(SaleID, \'-\', 1) AS SaleReference,
                SUM(COALESCE(LineTotal, 0)) AS SaleRevenue,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN 1 ELSE 0 END) AS UnknownLines,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN COALESCE(LineTotal, 0) ELSE 0 END) AS UnknownRevenue,
                SUM(CASE WHEN CostPerUnitSnapshot IS NULL OR LineCost IS NULL THEN 0 ELSE LineCost END) AS KnownCost
         FROM pharmacysales
         WHERE SaleDate BETWEEN :from AND :to
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
    $complete = $unknownRevenue <= 0.00001;
    return [
        'drugSold' => round($drugSold, 2),
        'drugCost' => $complete ? round($knownCost, 2) : null,
        'grossProfit' => $complete ? round($drugSold - $knownCost, 2) : null,
        'unknownRevenue' => round($unknownRevenue, 2),
        'unknownSales' => $unknownSales,
        'complete' => $complete,
    ];
}
