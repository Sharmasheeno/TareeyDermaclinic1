<?php
/**
 * auth/includes/reports-center.php
 * ---------------------------------------------------------------------
 * Report catalogue, read-only query builders and shared renderer for the
 * Tarey Derma Clinic Reports center.
 *
 * This file never writes to the database and never echoes anything by
 * itself. reports.php owns the session, permission gate and layout; this
 * file only describes reports and turns them into markup strings.
 * ---------------------------------------------------------------------
 */

if (!function_exists('tdc_rc_catalog')) {
    /**
     * Grouped report catalogue. Each entry is metadata only; the actual
     * queries live in tdc_rc_build() so nothing here touches the database.
     */
    function tdc_rc_catalog(): array
    {
        return [
            'clinical' => [
                'label'   => 'Clinical & Patient',
                'icon'    => 'stethoscope',
                'reports' => [
                    'patients' => [
                        'title' => 'Patient Report',
                        'desc'  => 'Registered patients with visit counts and outstanding balances.',
                        'icon'  => 'users',
                        'status' => 'patient',
                    ],
                    'visits' => [
                        'title'  => 'Visit Report',
                        'desc'   => 'Consultations by date with fees, payments and queue status.',
                        'icon'   => 'calendar',
                        'status' => 'payment',
                    ],
                    'doctor-consultations' => [
                        'title' => 'Doctor Consultation Report',
                        'desc'  => 'Consultation volume and revenue grouped by doctor.',
                        'icon'  => 'user',
                    ],
                ],
            ],
            'billing' => [
                'label'   => 'Reception & Billing',
                'icon'    => 'credit-card',
                'reports' => [
                    'billing' => [
                        'title'  => 'Billing Report',
                        'desc'   => 'Consultation, laboratory and pharmacy charges in one ledger.',
                        'icon'   => 'file-text',
                        'status' => 'payment',
                    ],
                    'payments' => [
                        'title'  => 'Payment Report',
                        'desc'   => 'Amounts collected across every service.',
                        'icon'   => 'wallet',
                        'status' => 'payment',
                    ],
                    'outstanding' => [
                        'title' => 'Outstanding Balances',
                        'desc'  => 'Unpaid balances grouped by patient.',
                        'icon'  => 'clock',
                    ],
                ],
            ],
            'pharmacy' => [
                'label'   => 'Pharmacy',
                'icon'    => 'pill',
                'reports' => [
                    'pharmacy-sales' => [
                        'title'  => 'Pharmacy Sales Report',
                        'desc'   => 'Dispensing sales with totals, payments and dues.',
                        'icon'   => 'pill',
                        'status' => 'payment',
                    ],
                    'pharmacy-purchases' => [
                        'title' => 'Pharmacy Purchase Report',
                        'desc'  => 'Supplier purchases with reference, discount and VAT.',
                        'icon'  => 'inbox',
                    ],
                    'pharmacy-stock' => [
                        'title' => 'Stock Report',
                        'desc'  => 'Current inventory quantities and selling value.',
                        'icon'  => 'inbox',
                    ],
                    'pharmacy-low-stock' => [
                        'title'   => 'Low Stock Report',
                        'desc'    => 'Items at or below their configured reorder level.',
                        'icon'    => 'inbox',
                        'variant' => 'low',
                    ],
                    'pharmacy-expiry' => [
                        'title'   => 'Expiry Report',
                        'desc'    => 'Items expiring within 90 days, plus already expired stock.',
                        'icon'    => 'clock',
                        'variant' => 'expiring',
                    ],
                ],
            ],
            'laboratory' => [
                'label'   => 'Laboratory',
                'icon'    => 'flask',
                'reports' => [
                    'lab-orders' => [
                        'title'  => 'Laboratory Orders',
                        'desc'   => 'All laboratory requests with workflow and payment status.',
                        'icon'   => 'flask',
                        'status' => 'workflow',
                    ],
                    'lab-completed' => [
                        'title'   => 'Completed Tests',
                        'desc'    => 'Laboratory work that has been completed.',
                        'icon'    => 'check',
                        'variant' => 'completed',
                        'status'  => 'workflow',
                    ],
                    'lab-pending' => [
                        'title'   => 'Pending Tests',
                        'desc'    => 'Laboratory work still awaiting completion.',
                        'icon'    => 'clock',
                        'variant' => 'pending',
                        'status'  => 'workflow',
                    ],
                    'lab-revenue' => [
                        'title' => 'Laboratory Revenue',
                        'desc'  => 'Billed, collected and outstanding amounts per test.',
                        'icon'  => 'credit-card',
                    ],
                ],
            ],
            'financial' => [
                'label'   => 'Financial',
                'icon'    => 'wallet',
                'reports' => [
                    'revenue-by-account' => [
                        'title' => 'Revenue by Account',
                        'desc'  => 'Revenue accounts posted to the general ledger.',
                        'icon'  => 'scroll',
                    ],
                    'expenses' => [
                        'title' => 'Expense Report',
                        'desc'  => 'Expense entries posted to the general ledger.',
                        'icon'  => 'file-text',
                    ],
                    'transactions' => [
                        'title' => 'Transaction Ledger',
                        'desc'  => 'Every journal entry with debit and credit amounts.',
                        'icon'  => 'scroll',
                    ],
                    'income-statement' => [
                        'title'  => 'Income Statement',
                        'desc'   => 'Revenue and expenses for the selected period.',
                        'icon'   => 'grid',
                        'legacy' => true,
                    ],
                    'balance-sheet' => [
                        'title'  => 'Balance Sheet',
                        'desc'   => 'Assets, liabilities and equity as of a chosen date.',
                        'icon'   => 'grid',
                        'legacy' => true,
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists('tdc_rc_flat')) {
    /** Flattened key => metadata map, including the group each key belongs to. */
    function tdc_rc_flat(): array
    {
        static $flat = null;
        if ($flat !== null) return $flat;
        $flat = [];
        foreach (tdc_rc_catalog() as $groupKey => $group) {
            foreach ($group['reports'] as $key => $meta) {
                $meta['group']      = $groupKey;
                $meta['groupLabel'] = $group['label'];
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }
}

if (!function_exists('tdc_rc_keys')) {
    function tdc_rc_keys(): array
    {
        return array_keys(tdc_rc_flat());
    }
}

if (!function_exists('tdc_rc_meta')) {
    function tdc_rc_meta(string $key): ?array
    {
        $flat = tdc_rc_flat();
        return $flat[$key] ?? null;
    }
}

if (!function_exists('tdc_rc_date')) {
    /**
     * Builds a validated date-range fragment using unique named placeholders
     * so several fragments can coexist in one statement (e.g. UNION ALL).
     */
    function tdc_rc_date(string $column, string $from, string $to, array &$params, string $prefix): array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) return [];
        $clauses = [];
        if ($from !== '') {
            $clauses[] = $column . ' >= :' . $prefix . '_f';
            $params[$prefix . '_f'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $clauses[] = $column . ' <= :' . $prefix . '_t';
            $params[$prefix . '_t'] = $to . ' 23:59:59';
        }
        return $clauses;
    }
}

if (!function_exists('tdc_rc_like')) {
    /** Builds a LIKE fragment across several columns with one shared param. */
    function tdc_rc_like(array $columns, string $search, array &$params, string $prefix): array
    {
        $search = trim($search);
        if ($search === '') return [];
        $parts = [];
        $like  = '%' . $search . '%';
        foreach ($columns as $index => $column) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) continue;
            $key = $prefix . '_s' . $index;
            $params[$key] = $like;
            $parts[] = $column . ' LIKE :' . $key;
        }
        if (!$parts) return [];
        return ['(' . implode(' OR ', $parts) . ')'];
    }
}

if (!function_exists('tdc_rc_money_union')) {
    /**
     * Consultation + laboratory + pharmacy money rows in a single result set.
     * Each branch uses its own placeholder prefix because PDO runs with real
     * prepared statements, where a named placeholder may only appear once.
     */
    function tdc_rc_money_union(string $from, string $to, string $search, string $status): array
    {
        $params = [];
        $branches = [];

        $where = tdc_rc_date('v.VisitDate', $from, $to, $params, 'mv');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'v.VisitReference'], $search, $params, 'mv'));
        if ($status !== '') {
            $where[] = 'v.PaymentStatus = :mv_st';
            $params['mv_st'] = $status;
        }
        $branches[] = "SELECT 'Consultation' AS Service, v.VisitReference AS Ref, p.PatientName AS Party,"
            . " v.VisitDate AS Dt, v.ConsultationFee AS Total, v.AmountPaid AS Paid, v.DueBalance AS Due,"
            . " v.PaymentStatus AS Status"
            . " FROM Visits v LEFT JOIN Patients p ON p.PatientID = v.PatientID"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $where = tdc_rc_date('l.OrderDate', $from, $to, $params, 'ml');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'l.LaboratoryID', 'l.TestName'], $search, $params, 'ml'));
        if ($status !== '') {
            $where[] = 'l.PaymentStatus = :ml_st';
            $params['ml_st'] = $status;
        }
        $branches[] = "SELECT 'Laboratory' AS Service, l.LaboratoryID AS Ref, p.PatientName AS Party,"
            . " l.OrderDate AS Dt, l.TotalAmount AS Total, l.AmountPaid AS Paid, l.DueBalance AS Due,"
            . " l.PaymentStatus AS Status"
            . " FROM Laboratory l LEFT JOIN Patients p ON p.PatientID = l.PatientID"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $where = tdc_rc_date('ps.SaleDate', $from, $to, $params, 'mp');
        $where = array_merge($where, tdc_rc_like(['ps.CustomerName', 'ps.SaleRef'], $search, $params, 'mp'));
        if ($status !== '') {
            $where[] = 'ps.Status = :mp_st';
            $params['mp_st'] = $status;
        }
        $branches[] = "SELECT 'Pharmacy' AS Service, ps.SaleRef AS Ref, ps.CustomerName AS Party,"
            . " ps.SaleDate AS Dt, ps.Total AS Total, ps.Paid AS Paid, ps.Due AS Due, ps.Status AS Status"
            . " FROM (SELECT SUBSTRING_INDEX(SaleID,'-',1) AS SaleRef, MIN(CustomerName) AS CustomerName,"
            . " MIN(SaleDate) AS SaleDate, SUM(LineTotal) AS Total, MIN(AmountPaid) AS Paid,"
            . " MIN(DueBalance) AS Due, MIN(PaymentStatus) AS Status"
            . " FROM PharmacySales GROUP BY SUBSTRING_INDEX(SaleID,'-',1)) ps"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        return ['sql' => implode(' UNION ALL ', $branches), 'params' => $params];
    }
}
if (!function_exists('tdc_rc_build')) {
    /**
     * Runs one report and returns columns + rows + optional summary cards.
     * Every query is read-only and bounded with LIMIT.
     */
    function tdc_rc_build(PDO $pdo, string $key, array $f): array
    {
        $from    = (string) ($f['from'] ?? '');
        $to      = (string) ($f['to'] ?? '');
        $search  = trim((string) ($f['search'] ?? ''));
        $status  = trim((string) ($f['status'] ?? ''));
        $params  = [];
        $rows    = [];
        $summary = [];
        $note    = '';
        $cols    = [];

        $run = static function (string $sql, array $bind) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        switch ($key) {
            case 'patients':
                $cols = [
                    ['id', 'Patient ID', 'text'], ['name', 'Patient', 'text'],
                    ['gender', 'Gender', 'text'], ['age', 'Age', 'number'],
                    ['phone', 'Phone', 'text'], ['ptype', 'Type', 'text'],
                    ['visits', 'Visits', 'number'], ['due', 'Balance Due', 'money'],
                    ['registered', 'Registered', 'date'],
                ];
                $where = tdc_rc_date('p.RegisteredAt', $from, $to, $params, 'pa');
                $where = array_merge($where, tdc_rc_like(['p.PatientName', 'p.PatientPhone'], $search, $params, 'pa'));
                $rows = $run(
                    "SELECT p.PatientID AS id, p.PatientName AS name, p.Gender AS gender, p.Age AS age,"
                    . " p.PatientPhone AS phone, p.PatientType AS ptype, p.RegisteredAt AS registered,"
                    . " p.DueBalance AS due,"
                    . " (SELECT COUNT(*) FROM Visits v WHERE v.PatientID = p.PatientID) AS visits"
                    . " FROM Patients p"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " ORDER BY p.RegisteredAt DESC LIMIT 500",
                    $params
                );
                break;

            case 'visits':
                $cols = [
                    ['ref', 'Visit Ref', 'text'], ['patient', 'Patient', 'text'],
                    ['doctor', 'Doctor', 'text'], ['dt', 'Visit Date', 'date'],
                    ['fee', 'Fee', 'money'], ['paid', 'Paid', 'money'],
                    ['due', 'Due', 'money'], ['payment', 'Payment', 'badge'],
                    ['queue', 'Queue', 'badge'],
                ];
                $where = tdc_rc_date('v.VisitDate', $from, $to, $params, 'vi');
                $where = array_merge($where, tdc_rc_like(['v.VisitReference', 'p.PatientName'], $search, $params, 'vi'));
                if ($status !== '') { $where[] = 'v.PaymentStatus = :vi_st'; $params['vi_st'] = $status; }
                $rows = $run(
                    "SELECT v.VisitReference AS ref, p.PatientName AS patient, d.DoctorName AS doctor,"
                    . " v.VisitDate AS dt, v.ConsultationFee AS fee, v.AmountPaid AS paid,"
                    . " v.DueBalance AS due, v.PaymentStatus AS payment, v.QueueStatus AS queue"
                    . " FROM Visits v"
                    . " LEFT JOIN Patients p ON p.PatientID = v.PatientID"
                    . " LEFT JOIN Doctors d ON d.DoctorID = v.DoctorID"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " ORDER BY v.VisitDate DESC LIMIT 500",
                    $params
                );
                break;

            case 'doctor-consultations':
                $cols = [
                    ['doctor', 'Doctor', 'text'], ['specialty', 'Specialization', 'text'],
                    ['consultations', 'Consultations', 'number'], ['billed', 'Billed', 'money'],
                    ['collected', 'Collected', 'money'], ['due', 'Outstanding', 'money'],
                ];
                $on = tdc_rc_date('v.VisitDate', $from, $to, $params, 'dc');
                $where = tdc_rc_like(['d.DoctorName', 'd.Specialty'], $search, $params, 'dc');
                $rows = $run(
                    "SELECT d.DoctorName AS doctor, d.Specialty AS specialty,"
                    . " COUNT(v.VisitID) AS consultations,"
                    . " COALESCE(SUM(v.ConsultationFee),0) AS billed,"
                    . " COALESCE(SUM(v.AmountPaid),0) AS collected,"
                    . " COALESCE(SUM(v.DueBalance),0) AS due"
                    . " FROM Doctors d"
                    . " LEFT JOIN Visits v ON v.DoctorID = d.DoctorID"
                    . ($on ? ' AND ' . implode(' AND ', $on) : '')
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " GROUP BY d.DoctorID, d.DoctorName, d.Specialty"
                    . " ORDER BY consultations DESC, d.DoctorName ASC LIMIT 500",
                    $params
                );
                break;

            case 'billing':
            case 'payments':
            case 'outstanding':
                $union = tdc_rc_money_union($from, $to, $search, $status);
                $params = $union['params'];
                $inner = 'SELECT * FROM (' . $union['sql'] . ') t';
                if ($key === 'outstanding') $inner .= ' WHERE t.Due > 0';
                $inner .= ' ORDER BY t.Dt DESC LIMIT 500';
                $rows = $run($inner, $params);
                if ($key === 'payments') {
                    $cols = [
                        ['Service', 'Service', 'text'], ['Ref', 'Reference', 'text'],
                        ['Party', 'Patient / Customer', 'text'], ['Dt', 'Date', 'date'],
                        ['Paid', 'Amount Paid', 'money'], ['Status', 'Status', 'badge'],
                    ];
                } elseif ($key === 'outstanding') {
                    $cols = [
                        ['Service', 'Service', 'text'], ['Ref', 'Reference', 'text'],
                        ['Party', 'Patient / Customer', 'text'], ['Dt', 'Date', 'date'],
                        ['Total', 'Billed', 'money'], ['Paid', 'Paid', 'money'],
                        ['Due', 'Outstanding', 'money'],
                    ];
                } else {
                    $cols = [
                        ['Service', 'Service', 'text'], ['Ref', 'Reference', 'text'],
                        ['Party', 'Patient / Customer', 'text'], ['Dt', 'Date', 'date'],
                        ['Total', 'Billed', 'money'], ['Paid', 'Paid', 'money'],
                        ['Due', 'Due', 'money'], ['Status', 'Status', 'badge'],
                    ];
                }
                break;

            case 'pharmacy-sales':
                $cols = [
                    ['ref', 'Sale Ref', 'text'], ['customer', 'Customer', 'text'],
                    ['dt', 'Date', 'date'], ['items', 'Items', 'number'],
                    ['total', 'Total', 'money'], ['paid', 'Paid', 'money'],
                    ['due', 'Due', 'money'], ['status', 'Status', 'badge'],
                ];
                $where = tdc_rc_date('SaleDate', $from, $to, $params, 'ps');
                $where = array_merge($where, tdc_rc_like(['CustomerName', 'SaleID'], $search, $params, 'ps'));
                if ($status !== '') { $where[] = 'PaymentStatus = :ps_st'; $params['ps_st'] = $status; }
                $rows = $run(
                    "SELECT SUBSTRING_INDEX(SaleID,'-',1) AS ref, MIN(CustomerName) AS customer,"
                    . " MIN(SaleDate) AS dt, COUNT(*) AS items, SUM(LineTotal) AS total,"
                    . " MIN(AmountPaid) AS paid, MIN(DueBalance) AS due,"
                    . " MIN(PaymentStatus) AS status"
                    . " FROM PharmacySales"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " GROUP BY SUBSTRING_INDEX(SaleID,'-',1)"
                    . " ORDER BY dt DESC LIMIT 500",
                    $params
                );
                break;

            case 'pharmacy-purchases':
                $cols = [
                    ['ref', 'Purchase Ref', 'text'], ['supplier', 'Supplier', 'text'],
                    ['dt', 'Date', 'date'], ['items', 'Items', 'number'],
                    ['total', 'Subtotal', 'money'], ['discount', 'Discount', 'money'],
                    ['vat', 'VAT', 'money'], ['paid', 'Paid', 'money'],
                    ['due', 'Due', 'money'],
                ];
                $where = tdc_rc_date('PurchaseDate', $from, $to, $params, 'pp');
                $where = array_merge($where, tdc_rc_like(['SupplierName', 'ReferenceNumber', 'PurchaseID'], $search, $params, 'pp'));
                $rows = $run(
                    "SELECT SUBSTRING_INDEX(PurchaseID,'-',1) AS ref, MIN(SupplierName) AS supplier,"
                    . " MIN(PurchaseDate) AS dt, COUNT(*) AS items, SUM(TotalAmount) AS total,"
                    . " MIN(Discount) AS discount, MIN(VATAmount) AS vat,"
                    . " MIN(AmountPaid) AS paid, MIN(DueBalance) AS due"
                    . " FROM Purchases"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " GROUP BY SUBSTRING_INDEX(PurchaseID,'-',1)"
                    . " ORDER BY dt DESC LIMIT 500",
                    $params
                );
                break;

            case 'pharmacy-stock':
            case 'pharmacy-low-stock':
                $low = ($key === 'pharmacy-low-stock');
                $cols = [
                    ['item', 'Medicine', 'text'], ['category', 'Category', 'text'],
                    ['qty', 'In Stock', 'number'], ['unit', 'Unit', 'text'],
                    ['reorder', 'Reorder Level', 'number'],
                    ['shortfall', 'Shortfall', 'number'],
                    ['price', 'Selling Price', 'money'], ['value', 'Stock Value', 'money'],
                ];
                if (!$low) {
                    $cols = [
                        ['item', 'Medicine', 'text'], ['category', 'Category', 'text'],
                        ['qty', 'In Stock', 'number'], ['unit', 'Unit', 'text'],
                        ['price', 'Selling Price', 'money'], ['value', 'Stock Value', 'money'],
                        ['expiry', 'Expiry', 'date'],
                    ];
                }
                $where = tdc_rc_like(['ItemName', 'ItemID', 'Category'], $search, $params, 'sk');
                if ($low) $where[] = 'QuantityInStock <= ReorderLevel';
                $rows = $run(
                    "SELECT ItemName AS item, Category AS category, QuantityInStock AS qty,"
                    . " SalesUnit AS unit, ReorderLevel AS reorder,"
                    . " (ReorderLevel - QuantityInStock) AS shortfall,"
                    . " SellingPrice AS price, (QuantityInStock * SellingPrice) AS value,"
                    . " ExpiryDate AS expiry"
                    . " FROM Inventory"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " ORDER BY " . ($low ? 'shortfall DESC' : 'ItemName ASC')
                    . " LIMIT 500",
                    $params
                );
                break;

            case 'pharmacy-expiry':
                $cols = [
                    ['item', 'Medicine', 'text'], ['category', 'Category', 'text'],
                    ['qty', 'In Stock', 'number'], ['unit', 'Unit', 'text'],
                    ['expiry', 'Expiry Date', 'date'], ['days', 'Days Left', 'number'],
                    ['value', 'Stock Value', 'money'],
                ];
                $where = ["ExpiryDate IS NOT NULL", "ExpiryDate <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"];
                $where = array_merge($where, tdc_rc_like(['ItemName', 'Category'], $search, $params, 'ex'));
                $rows = $run(
                    "SELECT ItemName AS item, Category AS category, QuantityInStock AS qty,"
                    . " SalesUnit AS unit, ExpiryDate AS expiry,"
                    . " DATEDIFF(ExpiryDate, CURDATE()) AS days,"
                    . " (QuantityInStock * SellingPrice) AS value"
                    . " FROM Inventory WHERE " . implode(' AND ', $where)
                    . " ORDER BY ExpiryDate ASC LIMIT 500",
                    $params
                );
                break;

            case 'lab-orders':
            case 'lab-completed':
            case 'lab-pending':
                $cols = [
                    ['ref', 'Lab ID', 'text'], ['patient', 'Patient', 'text'],
                    ['test', 'Test', 'text'], ['dt', 'Order Date', 'date'],
                    ['total', 'Total', 'money'], ['paid', 'Paid', 'money'],
                    ['due', 'Due', 'money'], ['payment', 'Payment', 'badge'],
                    ['workflow', 'Workflow', 'badge'],
                ];
                $where = tdc_rc_date('l.OrderDate', $from, $to, $params, 'lb');
                $where = array_merge($where, tdc_rc_like(['l.LaboratoryID', 'l.TestName', 'p.PatientName'], $search, $params, 'lb'));
                if ($status !== '') { $where[] = 'l.PaymentStatus = :lb_st'; $params['lb_st'] = $status; }
                if ($key === 'lab-completed') $where[] = "l.WorkflowStatus = 'Completed'";
                if ($key === 'lab-pending') $where[] = "l.WorkflowStatus <> 'Completed' AND l.WorkflowStatus <> 'Cancelled'";
                $rows = $run(
                    "SELECT l.LaboratoryID AS ref, p.PatientName AS patient, l.TestName AS test,"
                    . " l.OrderDate AS dt, l.TotalAmount AS total, l.AmountPaid AS paid,"
                    . " l.DueBalance AS due, l.PaymentStatus AS payment, l.WorkflowStatus AS workflow"
                    . " FROM Laboratory l"
                    . " LEFT JOIN Patients p ON p.PatientID = l.PatientID"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " ORDER BY l.OrderDate DESC LIMIT 500",
                    $params
                );
                break;

            case 'lab-revenue':
                $cols = [
                    ['test', 'Test', 'text'], ['orders', 'Orders', 'number'],
                    ['billed', 'Billed', 'money'], ['collected', 'Collected', 'money'],
                    ['due', 'Outstanding', 'money'],
                ];
                $where = tdc_rc_date('l.OrderDate', $from, $to, $params, 'lr');
                $where = array_merge($where, tdc_rc_like(['l.TestName'], $search, $params, 'lr'));
                $rows = $run(
                    "SELECT l.TestName AS test, COUNT(*) AS orders,"
                    . " COALESCE(SUM(l.TotalAmount),0) AS billed,"
                    . " COALESCE(SUM(l.AmountPaid),0) AS collected,"
                    . " COALESCE(SUM(l.DueBalance),0) AS due"
                    . " FROM Laboratory l"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " GROUP BY l.TestName ORDER BY billed DESC LIMIT 500",
                    $params
                );
                break;

            case 'revenue-by-account':
            case 'expenses':
                $isExpense = ($key === 'expenses');
                $cols = [
                    ['account', 'Account', 'text'], ['entries', 'Entries', 'number'],
                    ['amount', 'Amount', 'money'],
                ];
                $where = ["a.AccountType = '" . ($isExpense ? 'Expense' : 'Revenue') . "'"];
                $where = array_merge($where, tdc_rc_date('a.TransactionDate', $from, $to, $params, 'ac'));
                $where = array_merge($where, tdc_rc_like(['a.AccountName', 'a.AccountID'], $search, $params, 'ac'));
                $rows = $run(
                    "SELECT a.AccountName AS account, COUNT(*) AS entries,"
                    . " COALESCE(SUM(" . ($isExpense ? 'a.Debit - a.Credit' : 'a.Credit - a.Debit') . "),0) AS amount"
                    . " FROM Accounting a WHERE " . implode(' AND ', $where)
                    . " GROUP BY a.AccountID, a.AccountName ORDER BY amount DESC LIMIT 500",
                    $params
                );
                break;

            case 'transactions':
                $cols = [
                    ['dt', 'Date', 'date'], ['entry', 'Entry ID', 'text'],
                    ['account', 'Account', 'text'], ['atype', 'Type', 'text'],
                    ['book', 'Book', 'text'], ['ref', 'Reference', 'text'],
                    ['debit', 'Debit', 'money'], ['credit', 'Credit', 'money'],
                ];
                $where = tdc_rc_date('a.TransactionDate', $from, $to, $params, 'tr');
                $where = array_merge($where, tdc_rc_like(['a.EntryID', 'a.AccountName', 'a.ReferenceID'], $search, $params, 'tr'));
                if ($status !== '') { $where[] = 'a.AccountType = :tr_st'; $params['tr_st'] = $status; }
                $rows = $run(
                    "SELECT a.TransactionDate AS dt, a.EntryID AS entry, a.AccountName AS account,"
                    . " a.AccountType AS atype, a.BookType AS book, a.ReferenceID AS ref,"
                    . " a.Debit AS debit, a.Credit AS credit"
                    . " FROM Accounting a"
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " ORDER BY a.TransactionDate DESC LIMIT 500",
                    $params
                );
                break;
        }

        return ['columns' => $cols, 'rows' => $rows, 'summary' => $summary, 'note' => $note];
    }
}
if (!function_exists('tdc_rc_status_options')) {
    /** Status dropdown choices per report; only values the schema actually has. */
    function tdc_rc_status_options(string $key): array
    {
        return match ($key) {
            'visits', 'billing', 'payments', 'pharmacy-sales' => ['Paid', 'Partial', 'Unpaid'],
            'lab-orders', 'lab-completed', 'lab-pending' => ['Paid', 'Partial', 'Unpaid'],
            'transactions' => ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'],
            default => [],
        };
    }
}

if (!function_exists('tdc_rc_format_cell')) {
    function tdc_rc_format_cell($value, string $type): string
    {
        if ($value === null || $value === '') return '<span class="cell-muted">&mdash;</span>';
        switch ($type) {
            case 'money':
                return tdc_ui_h(number_format((float) $value, 2));
            case 'number':
                return tdc_ui_h(number_format((float) $value, 0));
            case 'date':
                $ts = strtotime((string) $value);
                return $ts ? tdc_ui_h(date('M j, Y', $ts)) : tdc_ui_h((string) $value);
            case 'badge':
                return tdc_badge((string) $value);
            default:
                return tdc_ui_h((string) $value);
        }
    }
}

if (!function_exists('tdc_rc_summary_cards')) {
    /** Aggregates the visible rows into count / money summary cards. */
    function tdc_rc_summary_cards(array $columns, array $rows): array
    {
        $moneyCols = [];
        foreach ($columns as $col) {
            if (($col[2] ?? '') === 'money') $moneyCols[$col[0]] = $col[1];
        }
        if (!$moneyCols) return [];
        $totals = array_fill_keys(array_keys($moneyCols), 0.0);
        foreach ($rows as $row) {
            foreach ($moneyCols as $colKey => $label) {
                $totals[$colKey] += (float) ($row[$colKey] ?? 0);
            }
        }
        $cards = [];
        foreach ($moneyCols as $colKey => $label) {
            $cards[] = ['label' => 'Total ' . $label, 'value' => $totals[$colKey]];
        }
        return $cards;
    }
}

if (!function_exists('tdc_rc_render_landing')) {
    /** Grouped, responsive report catalogue for the Reports landing page. */
    function tdc_rc_render_landing(array $hubSummary = []): string
    {
        $html = '<div class="welcome-eyebrow">Reports</div>'
            . '<div class="welcome-title">Report Center</div>'
            . '<div class="welcome-sub">Every operational and financial report, computed live from clinic records.</div>';

        if ($hubSummary) {
            $html .= '<div class="kpi-grid" style="margin:20px 0 28px">';
            foreach ($hubSummary as $label => $value) {
                $isMoney = str_contains(strtolower((string) $label), 'revenue')
                    || str_contains(strtolower((string) $label), 'balance')
                    || str_contains(strtolower((string) $label), 'income');
                $html .= '<div class="kpi-card"><div class="kpi-label">' . tdc_ui_h($label) . '</div>'
                    . '<div class="kpi-value">' . ($isMoney ? number_format((float) $value, 2) : number_format((float) $value, 0)) . '</div></div>';
            }
            $html .= '</div>';
        }

        foreach (tdc_rc_catalog() as $group) {
            $html .= '<div class="report-group">'
                . '<div class="report-group-head">' . tdc_icon($group['icon'], 16)
                . '<span>' . tdc_ui_h($group['label']) . '</span></div>'
                . '<div class="report-grid">';
            foreach ($group['reports'] as $key => $meta) {
                $href = 'reports.php?section=' . urlencode($key);
                $tag = !empty($meta['legacy']) ? '<span class="report-tag">Statement</span>' : '';
                $html .= '<a class="report-card" href="' . tdc_ui_h($href) . '">'
                    . '<span class="report-card-icon">' . tdc_icon($meta['icon'] ?? 'grid', 18) . '</span>'
                    . '<span class="report-card-body"><span class="report-card-title">' . tdc_ui_h($meta['title'])
                    . $tag . '</span><span class="report-card-desc">' . tdc_ui_h($meta['desc'] ?? '') . '</span></span>'
                    . '</a>';
            }
            $html .= '</div></div>';
        }
        return $html;
    }
}

if (!function_exists('tdc_rc_render_report')) {
    /**
     * Renders one report: header, toolbar (date range, search, status, exports)
     * and the shared data table with summary cards and an empty state.
     */
    function tdc_rc_render_report(string $key, array $meta, array $data, array $filters, array $exportLinks): string
    {
        $columns = $data['columns'] ?? [];
        $rows    = $data['rows'] ?? [];
        $html = '<div class="report-head">'
            . '<span class="report-head-icon">' . tdc_icon($meta['icon'] ?? 'grid', 20) . '</span>'
            . '<div><div class="welcome-title">' . tdc_ui_h($meta['title']) . '</div>'
            . '<div class="welcome-sub" style="margin-bottom:0">' . tdc_ui_h($meta['desc'] ?? '') . '</div></div>'
            . '</div>';

        $statusOptions = tdc_rc_status_options($key);
        $html .= '<div class="report-toolbar no-print">';
        $html .= tdc_date_range([
            'from'     => (string) ($filters['from'] ?? ''),
            'to'       => (string) ($filters['to'] ?? ''),
            'error'    => (string) ($filters['error'] ?? ''),
            'preserve' => ['section' => $key, 'search' => (string) ($filters['search'] ?? ''), 'status' => (string) ($filters['status'] ?? '')],
            'action'   => 'reports.php',
            'id'       => 'rr_' . preg_replace('/[^a-z0-9_]/i', '_', $key),
        ]);
        $html .= '<form class="report-filters" method="get" action="reports.php">'
            . '<input type="hidden" name="section" value="' . tdc_ui_h($key) . '">'
            . '<input type="hidden" name="from_date" value="' . tdc_ui_h((string) ($filters['from'] ?? '')) . '">'
            . '<input type="hidden" name="to_date" value="' . tdc_ui_h((string) ($filters['to'] ?? '')) . '">'
            . tdc_search_field('search', (string) ($filters['search'] ?? ''), 'Search this report...');
        if ($statusOptions) {
            $html .= '<label class="table-filter"><select name="status" aria-label="Filter by status">'
                . '<option value="">All statuses</option>';
            foreach ($statusOptions as $opt) {
                $html .= '<option value="' . tdc_ui_h($opt) . '"'
                    . ((string) ($filters['status'] ?? '') === $opt ? ' selected' : '') . '>' . tdc_ui_h($opt) . '</option>';
            }
            $html .= '</select></label>';
        }
        $html .= '<button type="submit" class="btn btn-secondary btn-sm">' . tdc_icon('filter', 14) . '<span>Filter</span></button>'
            . '</form>';
        $html .= tdc_export_buttons($exportLinks);
        $html .= '</div>';

        $summary = tdc_rc_summary_cards($columns, $rows);
        if ($summary) {
            $html .= '<div class="report-summary">';
            $html .= '<div class="report-summary-card"><span class="report-summary-label">Records</span><span class="report-summary-value">' . count($rows) . '</span></div>';
            foreach ($summary as $card) {
                $html .= '<div class="report-summary-card"><span class="report-summary-label">' . tdc_ui_h($card['label']) . '</span>'
                    . '<span class="report-summary-value">' . number_format((float) $card['value'], 2) . '</span></div>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="data-table-wrap"><table class="data-table"><thead><tr>';
        foreach ($columns as $col) {
            $align = in_array(($col[2] ?? ''), ['money', 'number'], true) ? ' class="align-right"' : '';
            $html .= '<th' . $align . '>' . tdc_ui_h($col[1]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$rows) {
            $html .= tdc_empty_state('inbox', 'No records match this report', 'Adjust the date range or filters to widen the search.', '', count($columns));
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($columns as $col) {
                    $align = in_array(($col[2] ?? ''), ['money', 'number'], true) ? ' class="align-right"' : '';
                    $html .= '<td' . $align . '>' . tdc_rc_format_cell($row[$col[0]] ?? null, (string) ($col[2] ?? 'text')) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}

if (!function_exists('tdc_rc_csv_rows')) {
    /** Header + data rows for CSV export of one report. */
    function tdc_rc_csv_rows(array $data): array
    {
        $out = [];
        $header = [];
        foreach ($data['columns'] ?? [] as $col) $header[] = $col[1];
        $out[] = $header;
        foreach ($data['rows'] ?? [] as $row) {
            $line = [];
            foreach ($data['columns'] ?? [] as $col) $line[] = $row[$col[0]] ?? '';
            $out[] = $line;
        }
        return $out;
    }
}