<?php
require_once __DIR__ . '/../auth/includes/access.php';
require_once __DIR__ . '/../auth/includes/ui.php';
require_once __DIR__ . '/../auth/includes/reports-center.php';

$catalog = tdc_rc_catalog();
$sectionsToTest = [
    'billing-services' => ['group' => 'services', 'name' => 'Service Billing Report', 'hasStatus' => true],
    'doctor-consultations' => ['group' => 'doctors', 'name' => 'Doctor Consultation Report', 'hasStatus' => false],
    'visits' => ['group' => 'reception', 'name' => 'Reception Visit Report', 'hasStatus' => true],
    'patients' => ['group' => 'patients', 'name' => 'Patient Report', 'hasStatus' => false],
    'billing-laboratory' => ['group' => 'laboratory', 'name' => 'Laboratory Billing Report', 'hasStatus' => true],
    'billing-pharmacy' => ['group' => 'pharmacy', 'name' => 'Pharmacy Billing Report', 'hasStatus' => true],
    'transactions' => ['group' => 'financial', 'name' => 'Accounting Transactions Report', 'hasStatus' => true],
];

$allPassed = true;

foreach ($sectionsToTest as $key => $info) {
    $meta = $catalog[$info['group']]['reports'][$key] ?? null;
    if (!$meta) {
        echo "FAIL {$key}: Metadata not found in catalog\n";
        $allPassed = false;
        continue;
    }

    $filters = [
        'from' => '2026-09-01',
        'to' => '2026-09-27',
        'search' => 'TestPatientQuery',
        'status' => 'Paid',
    ];

    $dummyData = [
        'columns' => [
            ['ID', 'ID', 'text'],
            ['Name', 'Name', 'text'],
            ['Amount', 'Amount', 'money'],
        ],
        'rows' => [
            ['ID' => '1', 'Name' => 'Alice', 'Amount' => '50.00'],
        ],
    ];

    $html = tdc_rc_render_report($key, $meta, $dummyData, $filters, ['csv' => 'reports.php?export=csv']);

    $checks = [
        'report-toolbar' => strpos($html, 'class="report-toolbar report-filter-toolbar') !== false,
        'report-search-field' => strpos($html, 'class="report-search-field report-filter-search"') !== false,
        'search-placeholder' => strpos($html, 'placeholder="Search this report..."') !== false,
        'search-value-preserved' => strpos($html, 'value="TestPatientQuery"') !== false,
        'from-date-preserved' => strpos($html, 'name="from_date" value="2026-09-01"') !== false,
        'to-date-preserved' => strpos($html, 'name="to_date" value="2026-09-27"') !== false,
        'filter-actions' => strpos($html, 'class="report-filter-actions"') !== false,
        'apply-button' => strpos($html, 'Apply Filters</span></button>') !== false,
        'reset-button' => strpos($html, 'Reset</span></a>') !== false,
        'export-actions' => strpos($html, 'class="report-export-actions"') !== false,
    ];

    if (!$info['hasStatus']) {
        // No status filter expected; search expands naturally
        $checks['no-status-filter'] = strpos($html, 'name="status"') === false;
    } else {
        // Status filter expected
        $checks['has-status-filter'] = strpos($html, 'name="status"') !== false;
    }

    $failedChecks = [];
    foreach ($checks as $chkName => $passed) {
        if (!$passed) $failedChecks[] = $chkName;
    }

    if (empty($failedChecks)) {
        echo "PASS {$info['name']} ({$key})\n";
    } else {
        echo "FAIL {$info['name']} ({$key}): Failed [" . implode(', ', $failedChecks) . "]\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\nALL REPORT SECTIONS VERIFIED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "\nSOME REPORT CHECKS FAILED.\n";
    exit(1);
}
