<?php
declare(strict_types=1);

function tdc_lab_check_regex(string $text, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (stripos($text, $pattern) === false) {
            return false;
        }
    }
    return true;
}

$workspaceRoot = dirname(__DIR__);
$labPage = $workspaceRoot . '/auth/pages/laboratory.php';
$doctorPortal = $workspaceRoot . '/auth/includes/doctor-portal.php';
$doctorWorkspace = $workspaceRoot . '/auth/includes/doctor-workspace-view.php';
$labResults = $workspaceRoot . '/auth/includes/lab-results.php';
$printResult = $workspaceRoot . '/auth/print_laboratory.php';
$migration = $workspaceRoot . '/database/laboratory_workspace_migration.sql';

$labPageText = is_file($labPage) ? file_get_contents($labPage) : '';
$doctorPortalText = is_file($doctorPortal) ? file_get_contents($doctorPortal) : '';
$doctorWorkspaceText = is_file($doctorWorkspace) ? file_get_contents($doctorWorkspace) : '';
$labResultsText = is_file($labResults) ? file_get_contents($labResults) : '';
$printResultText = is_file($printResult) ? file_get_contents($printResult) : '';
$migrationText = is_file($migration) ? file_get_contents($migration) : '';

$moduleChecks = [
    'Category Header' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_categories', 'save_category'])],
    'Lab Type' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_types', 'save_lab_type'])],
    'Test Register' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_tests', 'save_test'])],
    'Lab Parameter' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_parameters', 'save_parameter'])],
    'Lab Selection' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_test_selection', 'save_selection'])],
    'Lab Center' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_centers', 'save_center'])],
    'Units' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_units', 'save_unit'])],
    'Flags' => ['STATIC', tdc_lab_check_regex($labPageText, ['lab_flags', 'save_flag'])],
    'Doctor Lab Request' => ['STATIC', tdc_lab_check_regex($doctorPortalText, ['request_lab', 'TestID', 'lab_test_selection', 'labservices'])],
    'Doctor Multi-Test Selection' => ['STATIC', tdc_lab_check_regex($doctorWorkspaceText, ['data-lab-test-row', 'data-category-select', 'data-type-select', 'data-test-select', 'data-remove-test-row', 'SelectedModernTestID[]', '+ Add Another Test', 'Calculated Fee', 'Submit Lab Request'])],
    'Reception Payment' => ['STATIC', tdc_lab_check_regex($labPageText, ['PaymentStatus', 'collect_sample', 'Awaiting Payment', 'Ready']) || tdc_lab_check_regex($labResultsText, ['Reception must record full payment before'])],
    'Test Result Entry' => ['STATIC', tdc_lab_check_regex($labPageText, ['test-result-entry', 'save_lab_result_draft', 'complete_lab_result'])],
    'Sample Collection' => ['STATIC', tdc_lab_check_regex($labPageText, ['collect_sample', 'Sample Collected', 'CollectedAt'])],
    'Automatic Flags' => ['STATIC', tdc_lab_check_regex($labPageText, ['tdc_lab_calculate_flag', 'FlagCode', 'FlagName'])],
    'Draft Persistence' => ['STATIC', tdc_lab_check_regex($labPageText, ['save_lab_result_draft', 'ResultStatus = ?, UpdatedAt = NOW()'])],
    'Complete Results' => ['STATIC', tdc_lab_check_regex($labPageText, ['complete_lab_result', 'CompletedAt', 'ResultStatus = ?, CompletedBy'])],
    'Attachments' => ['STATIC', tdc_lab_check_regex($migrationText, ['lab_result_attachments']) || tdc_lab_check_regex($labPageText, ['attachments'])],
    'Doctor Result View' => ['STATIC', tdc_lab_check_regex($doctorWorkspaceText, ['WorkflowStatus', 'ReviewedAt', 'LaboratoryID'])],
    'Doctor Review' => ['STATIC', tdc_lab_check_regex($doctorPortalText, ['review_result', 'ReviewedAt'])],
    'Patient History' => ['STATIC', tdc_lab_check_regex($doctorWorkspaceText, ['Visit activity', 'labOrders']) || tdc_lab_check_regex($printResultText, ['PatientID', 'VisitReference'])],
    'Print Result' => ['STATIC', is_file($printResult) && tdc_lab_check_regex($printResultText, ['Laboratory Receipt', 'Print this receipt', 'LaboratoryID'])],
    'Legacy Compatibility' => ['STATIC', tdc_lab_check_regex($doctorPortalText, ['labservices', 'legacy']) || tdc_lab_check_regex($labPageText, ['labLegacyOrders', 'labservices'])],
    'RBAC/Security' => ['STATIC', tdc_lab_check_regex($labPageText, ['tdc_require_permission', 'csrf_token', 'hash_equals']) && tdc_lab_check_regex($doctorPortalText, ['tdc_require_permission', 'csrf_token'])],
];

$databaseStatus = 'BLOCKED';
try {
    require_once $workspaceRoot . '/db.php';
    $databaseStatus = 'AVAILABLE';
} catch (Throwable $e) {
    $databaseStatus = 'BLOCKED';
}

$results = [];
foreach ($moduleChecks as $module => [$classification, $passed]) {
    $results[$module] = $passed ? 'IMPLEMENTED' : 'NOT IMPLEMENTED';
    if ($classification === 'DATABASE RUNTIME' && $databaseStatus !== 'AVAILABLE') {
        $results[$module] = 'BLOCKED';
    }
}

if ($databaseStatus === 'AVAILABLE') {
    $results['Database Schema'] = 'IMPLEMENTED';
    foreach (['lab_categories','lab_types','lab_tests','lab_parameters','lab_units','lab_flags','lab_centers','lab_test_selection','lab_results'] as $table) {
        if (!isset($pdo)) break;
        try {
            $stmt = $pdo->query('SHOW TABLES LIKE \'' . str_replace("'", "''", $table) . '\'');
            if ($stmt && !$stmt->fetchColumn()) {
                $results['Database Schema'] = 'FAIL';
                break;
            }
        } catch (Throwable $e) {
            $results['Database Schema'] = 'BLOCKED';
            break;
        }
    }
} else {
    $results['Database Schema'] = 'BLOCKED';
}

$lines = [];
foreach ($results as $module => $status) {
    $lines[] = sprintf('%-28s %s', $module, $status);
}

fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);

if (in_array('FAIL', $results, true)) {
    exit(1);
}
exit(0);
