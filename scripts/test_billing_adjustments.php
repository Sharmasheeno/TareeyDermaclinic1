<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/includes/billing-adjustments.php';

function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$a = tdc_calculate_bill_adjustment(100, 'None', 0, 0);
expect($a['final_amount'] === 100.0, 'Case A final');
$b = tdc_calculate_bill_adjustment(100, 'Fixed', 10, 0);
expect($b['final_amount'] === 90.0, 'Case B final');
$c = tdc_calculate_bill_adjustment(100, 'Percentage', 10, 5);
expect($c['discount_amount'] === 10.0 && $c['tax_amount'] === 4.5 && $c['final_amount'] === 94.5, 'Case C formula');
expect(tdc_calculate_bill_adjustment(94.5, 'None', 0, 0)['final_amount'] === 94.5, 'Case D final');
foreach ([['Fixed', 101, 0], ['Percentage', 101, 0]] as $bad) {
    try { tdc_calculate_bill_adjustment(100, $bad[0], $bad[1], $bad[2]); throw new RuntimeException('Invalid discount accepted'); }
    catch (InvalidArgumentException $e) { }
}
try { tdc_calculate_bill_adjustment(100, 'Fixed', 10, -1); throw new RuntimeException('Negative tax accepted'); }
catch (InvalidArgumentException $e) { }
echo "Billing adjustment formula cases passed.\n";
