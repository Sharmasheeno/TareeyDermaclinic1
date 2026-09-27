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
        $catalog = [
            'patients' => [
                'label' => 'Patients',
                'icon' => 'users',
                'reports' => [
                    'patients' => [
                        'title' => 'Patient Report',
                        'desc' => 'Registered patients with visit counts and outstanding balances.',
                        'icon' => 'users',
                        'status' => 'patient',
                    ],
                ],
            ],
            'doctors' => [
                'label' => 'Doctors',
                'icon' => 'stethoscope',
                'reports' => [
                    'doctor-consultations' => [
                        'title' => 'Doctor Consultation Report',
                        'desc' => 'Consultation volume and revenue grouped by doctor.',
                        'icon' => 'user',
                    ],
                ],
            ],
            'reception' => [
                'label' => 'Reception',
                'icon' => 'bell',
                'reports' => [
                    'visits' => [
                        'title' => 'Visit Report',
                        'desc' => 'Consultations by date with fees, payments and queue status.',
                        'icon' => 'calendar',
                        'status' => 'payment',
                    ],
                    'billing' => [
                        'title' => 'Billing Report',
                        'desc' => 'Consultation, laboratory, pharmacy and service charges in one ledger.',
                        'icon' => 'file-text',
                        'status' => 'payment',
                    ],
                    'billing-consultation' => [
                        'title' => 'Consultation Billing',
                        'desc' => 'Consultation fees, payments and outstanding balances.',
                        'icon' => 'stethoscope',
                        'status' => 'payment',
                    ],
                    'payments' => [
                        'title' => 'Payment Report',
                        'desc' => 'Amounts collected across every service.',
                        'icon' => 'wallet',
                        'status' => 'payment',
                    ],
                    'outstanding' => [
                        'title' => 'Outstanding Balances',
                        'desc' => 'Unpaid balances grouped by patient.',
                        'icon' => 'clock',
                    ],
                ],
            ],
            'services' => [
                'label' => 'Services',
                'icon' => 'grid',
                'reports' => [
                    'billing-services' => [
                        'title' => 'Service Billing',
                        'desc' => 'Assigned services, payments and outstanding balances.',
                        'icon' => 'grid',
                        'status' => 'payment',
                    ],
                ],
            ],
            'pharmacy' => [
                'label'   => 'Pharmacy',
                'icon'    => 'pill',
                'reports' => [
                    'billing-pharmacy' => [
                        'title' => 'Pharmacy Billing',
                        'desc' => 'Pharmacy sales, payments and outstanding balances.',
                        'icon' => 'pill',
                        'status' => 'payment',
                    ],
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
                    'billing-laboratory' => [
                        'title' => 'Laboratory Billing',
                        'desc' => 'Laboratory charges, payments and outstanding balances.',
                        'icon' => 'flask',
                        'status' => 'payment',
                    ],
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
                'label'   => 'Accounting',
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
        if (!tdc_can_view_purchase_cost()) unset($catalog['pharmacy']['reports']['pharmacy-purchases']);
        $ordered = [];
        foreach (['reception', 'services', 'doctors', 'patients', 'laboratory', 'pharmacy', 'financial'] as $groupKey) {
            if (isset($catalog[$groupKey])) $ordered[$groupKey] = $catalog[$groupKey];
        }
        return $ordered;
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
    function tdc_rc_money_union(string $from, string $to, string $search, string $status, bool $hasSaleStatus = true, bool $hasAdjustments = true, bool $hasServices = true): array
    {
        $params = [];
        $branches = [];
        $saleStatusFilter = $hasSaleStatus ? "SaleStatus <> 'Voided'" : '1=1';

        $where = tdc_rc_date('v.VisitDate', $from, $to, $params, 'mv');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'v.VisitReference'], $search, $params, 'mv'));
        $where[] = "v.QueueStatus <> 'Cancelled'";
        if ($status !== '') {
            $where[] = "CASE WHEN v.IsFreeConsultation=1 THEN 'Waived' ELSE v.PaymentStatus END = :mv_st";
            $params['mv_st'] = $status;
        }
        $consultAdjustJoin = $hasAdjustments ? " LEFT JOIN patient_bill_adjustments ba ON ba.BillType='consultation' AND ba.BillReference=v.VisitReference" : '';
        $consultGross = $hasAdjustments ? 'COALESCE(ba.GrossAmount,v.ConsultationFee)' : 'v.ConsultationFee';
        $consultDiscount = $hasAdjustments ? 'COALESCE(ba.DiscountAmount,0)' : '0';
        $consultTax = $hasAdjustments ? 'COALESCE(ba.TaxAmount,0)' : '0';
        $branches[] = "SELECT 'Consultation' AS Service, v.VisitReference AS Ref, p.PatientName AS Party,"
            . " v.VisitDate AS Dt, {$consultGross} AS Gross, {$consultDiscount} AS Discount, {$consultTax} AS Tax, v.ConsultationFee AS Total, v.AmountPaid AS Paid, v.DueBalance AS Due,"
            . " CASE WHEN v.IsFreeConsultation=1 THEN 'Waived' ELSE v.PaymentStatus END AS Status"
            . " FROM visits v LEFT JOIN patients p ON p.PatientID = v.PatientID{$consultAdjustJoin}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $where = tdc_rc_date('l.OrderDate', $from, $to, $params, 'ml');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'l.LaboratoryID', 'l.TestName'], $search, $params, 'ml'));
        $where[] = "l.WorkflowStatus <> 'Cancelled'";
        if ($status !== '') {
            $where[] = 'l.PaymentStatus = :ml_st';
            $params['ml_st'] = $status;
        }
        $labAdjustJoin = $hasAdjustments ? " LEFT JOIN patient_bill_adjustments ba ON ba.BillType='laboratory' AND ba.BillReference=l.LaboratoryID" : '';
        $labGross = $hasAdjustments ? 'COALESCE(ba.GrossAmount,l.TotalAmount)' : 'l.TotalAmount';
        $labDiscount = $hasAdjustments ? 'COALESCE(ba.DiscountAmount,0)' : '0';
        $labTax = $hasAdjustments ? 'COALESCE(ba.TaxAmount,0)' : '0';
        $branches[] = "SELECT 'Laboratory' AS Service, l.LaboratoryID AS Ref, p.PatientName AS Party,"
            . " l.OrderDate AS Dt, {$labGross} AS Gross, {$labDiscount} AS Discount, {$labTax} AS Tax, l.TotalAmount AS Total, l.AmountPaid AS Paid, l.DueBalance AS Due,"
            . " l.PaymentStatus AS Status"
            . " FROM laboratory l LEFT JOIN patients p ON p.PatientID = l.PatientID{$labAdjustJoin}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $where = tdc_rc_date('ps.SaleDate', $from, $to, $params, 'mp');
        $where = array_merge($where, tdc_rc_like(['ps.CustomerName', 'ps.SaleRef'], $search, $params, 'mp'));
        if ($status !== '') {
            $where[] = 'ps.Status = :mp_st';
            $params['mp_st'] = $status;
        }
        $branches[] = "SELECT 'Pharmacy' AS Service, ps.SaleRef AS Ref, ps.CustomerName AS Party,"
            . " ps.SaleDate AS Dt, ps.Total AS Gross, 0 AS Discount, 0 AS Tax, ps.Total AS Total, ps.Paid AS Paid, ps.Due AS Due, ps.Status AS Status"
            . " FROM (SELECT SUBSTRING_INDEX(SaleID,'-',1) AS SaleRef, MIN(CustomerName) AS CustomerName,"
            . " MIN(SaleDate) AS SaleDate, SUM(LineTotal) AS Total, MIN(AmountPaid) AS Paid,"
            . " MIN(DueBalance) AS Due, MIN(PaymentStatus) AS Status"
            . " FROM pharmacysales WHERE {$saleStatusFilter} GROUP BY SUBSTRING_INDEX(SaleID,'-',1)) ps"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        // Prescriptions are valid pharmacy bills before dispensing creates a
        // pharmacysales row. Include those bills once, while excluding any
        // reference already represented by a non-voided pharmacy sale.
        $where = tdc_rc_date('pr.Dt', $from, $to, $params, 'mpr');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'pr.Ref'], $search, $params, 'mpr'));
        if ($status !== '') {
            $where[] = "CASE WHEN pr.Paid <= 0 THEN 'Unpaid' WHEN pr.Due <= 0 THEN 'Paid' ELSE 'Partial' END = :mpr_st";
            $params['mpr_st'] = $status;
        }
        $rxAdjustJoin = $hasAdjustments ? " LEFT JOIN patient_bill_adjustments ba ON ba.BillType='prescription' AND ba.BillReference=pr.Ref" : '';
        $rxGross = $hasAdjustments ? 'COALESCE(ba.GrossAmount,pr.Gross)' : 'pr.Gross';
        $rxDiscount = $hasAdjustments ? 'COALESCE(ba.DiscountAmount,0)' : '0';
        $rxTax = $hasAdjustments ? 'COALESCE(ba.TaxAmount,0)' : '0';
        $rxTotal = $hasAdjustments ? 'COALESCE(ba.FinalAmount,pr.Gross)' : 'pr.Gross';
        $branches[] = "SELECT 'Pharmacy' AS Service, pr.Ref, p.PatientName AS Party, pr.Dt, {$rxGross} AS Gross, {$rxDiscount} AS Discount, {$rxTax} AS Tax, {$rxTotal} AS Total, pr.Paid, pr.Due, CASE WHEN pr.Paid <= 0 THEN 'Unpaid' WHEN pr.Due <= 0 THEN 'Paid' ELSE 'Partial' END AS Status"
            . " FROM (SELECT SUBSTRING_INDEX(PrescriptionID,'-',1) AS Ref, PatientID, MIN(PrescriptionDate) AS Dt, MAX(TotalAmount) AS Gross, MAX(AmountPaid) AS Paid, MAX(DueBalance) AS Due FROM prescriptions GROUP BY SUBSTRING_INDEX(PrescriptionID,'-',1), PatientID) pr"
            . " JOIN patients p ON p.PatientID=pr.PatientID{$rxAdjustJoin}"
            . " WHERE NOT EXISTS (SELECT 1 FROM pharmacysales psv WHERE psv.SaleID LIKE CONCAT(pr.Ref,'-%') AND {$saleStatusFilter})"
            . ($where ? ' AND ' . implode(' AND ', $where) : '');

        $where = tdc_rc_date('sa.AssignedAt', $from, $to, $params, 'ms');
        $where = array_merge($where, tdc_rc_like(['p.PatientName', 'sa.ServiceReference', 'ss.ServiceName', 'sc.CategoryName'], $search, $params, 'ms'));
        $where[] = "sa.AssignmentStatus <> 'Cancelled'";
        if ($status !== '') { $where[] = 'sa.PaymentStatus = :ms_st'; $params['ms_st'] = $status; }
        if ($hasServices) {
            $serviceAdjustJoin = $hasAdjustments ? " LEFT JOIN patient_bill_adjustments ba ON ba.BillType='Service' AND ba.BillReference=sa.ServiceReference" : '';
            $serviceGross = $hasAdjustments ? 'COALESCE(ba.GrossAmount,sa.ServiceAmount)' : 'sa.ServiceAmount';
            $serviceDiscount = $hasAdjustments ? 'COALESCE(ba.DiscountAmount,0)' : '0';
            $serviceTax = $hasAdjustments ? 'COALESCE(ba.TaxAmount,0)' : '0';
            $branches[] = "SELECT 'Service' AS Service, sa.ServiceReference AS Ref, p.PatientName AS Party, sa.AssignedAt AS Dt, {$serviceGross} AS Gross, {$serviceDiscount} AS Discount, {$serviceTax} AS Tax, sa.ServiceAmount AS Total, sa.AmountPaid AS Paid, sa.DueBalance AS Due, sa.PaymentStatus AS Status FROM service_assignments sa JOIN patients p ON p.PatientID=sa.PatientID JOIN service_subservices ss ON ss.ServiceID=sa.ServiceID JOIN service_categories sc ON sc.ServiceCategoryID=ss.ServiceCategoryID{$serviceAdjustJoin}" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        }

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
        $hasPaymentSaleReference = tdc_has_column($pdo, 'payments', 'SaleReference');
        $hasPaymentPurchaseReference = tdc_has_column($pdo, 'payments', 'PurchaseReference');
        $hasBillAdjustments = tdc_has_column($pdo, 'patient_bill_adjustments', 'BillType');
        $hasServices = tdc_has_column($pdo, 'service_assignments', 'AssignmentID')
            && tdc_has_column($pdo, 'service_subservices', 'ServiceID')
            && tdc_has_column($pdo, 'service_categories', 'ServiceCategoryID');

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
                $where = array_merge($where, tdc_rc_like(['p.PatientID', 'p.PatientName', 'p.PatientPhone'], $search, $params, 'pa'));
                $rows = $run(
                    "SELECT p.PatientID AS id, p.PatientName AS name, p.Gender AS gender, p.Age AS age,"
                    . " p.PatientPhone AS phone, p.PatientType AS ptype, p.RegisteredAt AS registered,"
                    . " p.DueBalance AS due,"
                    . " (SELECT COUNT(*) FROM visits v WHERE v.PatientID = p.PatientID) AS visits"
                    . " FROM patients p"
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
                    . " FROM visits v"
                    . " LEFT JOIN patients p ON p.PatientID = v.PatientID"
                    . " LEFT JOIN doctors d ON d.DoctorID = v.DoctorID"
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
                $on[] = "v.QueueStatus <> 'Cancelled'";
                $where = tdc_rc_like(['d.DoctorName', 'd.Specialty'], $search, $params, 'dc');
                $rows = $run(
                    "SELECT d.DoctorName AS doctor, d.Specialty AS specialty,"
                    . " COUNT(v.VisitID) AS consultations,"
                    . " COALESCE(SUM(v.ConsultationFee),0) AS billed,"
                    . " COALESCE(SUM(v.AmountPaid),0) AS collected,"
                    . " COALESCE(SUM(v.DueBalance),0) AS due"
                    . " FROM doctors d"
                    . " LEFT JOIN visits v ON v.DoctorID = d.DoctorID"
                    . ($on ? ' AND ' . implode(' AND ', $on) : '')
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . " GROUP BY d.DoctorID, d.DoctorName, d.Specialty"
                    . " ORDER BY consultations DESC, d.DoctorName ASC LIMIT 500",
                    $params
                );
                break;

            case 'payments':
                $cols = [
                    ['payment_ref', 'Payment Reference', 'text'],
                    ['service', 'Service', 'text'],
                    ['source', 'Source', 'text'],
                    ['party', 'Patient / Supplier', 'text'],
                    ['method', 'Payment Method', 'text'],
                    ['dt', 'Date', 'date'],
                    ['amount', 'Amount', 'money'],
                    ['status', 'Status', 'badge'],
                ];
                $where = ["p.PaymentStatus = 'Confirmed'"];
                $where = array_merge($where, tdc_rc_date('p.PaidAt', $from, $to, $params, 'py'));
                $where = array_merge($where, tdc_rc_like([
                    'p.PaymentReference', 'p.PaymentType', 'p.PaymentMethod',
                    'p.VisitID', 'p.LaboratoryID', 'p.PrescriptionReference',
                    ...($hasPaymentSaleReference ? ['p.SaleReference'] : []),
                    ...($hasPaymentPurchaseReference ? ['p.PurchaseReference'] : []),
                    'pt.PatientName',
                ], $search, $params, 'py'));
                if ($status !== '') {
                    $where = array_filter($where, static fn(string $clause): bool => $clause !== "p.PaymentStatus = 'Confirmed'");
                    $where[] = 'p.PaymentStatus = :py_st';
                    $params['py_st'] = $status;
                }
                $rows = $run(
                    "SELECT p.PaymentReference AS payment_ref, p.PaymentType AS service,
                            p.VisitID, v.VisitReference, p.LaboratoryID,
                                p.PrescriptionReference,
                                " . ($hasPaymentSaleReference ? 'p.SaleReference' : 'NULL AS SaleReference') . ",
                                " . ($hasPaymentPurchaseReference ? 'p.PurchaseReference' : 'NULL AS PurchaseReference') . ",
                            COALESCE(NULLIF(pt.PatientName, ''),
                                CASE
                                    " . ($hasPaymentSaleReference ? "WHEN p.PaymentType = 'POS' THEN (SELECT MIN(CustomerName) FROM pharmacysales ps WHERE ps.SaleID LIKE CONCAT(p.SaleReference, '-%'))" : '') . "
                                    " . ($hasPaymentPurchaseReference ? "WHEN p.PaymentType = 'Supplier' THEN (SELECT MIN(SupplierName) FROM purchases pu WHERE pu.PurchaseID LIKE CONCAT(p.PurchaseReference, '-%'))" : '') . "
                                    ELSE NULL
                                END) AS party,
                            p.PaymentMethod AS method, p.PaidAt AS dt,
                            p.Amount AS amount, p.PaymentStatus AS status
                     FROM payments p
                     LEFT JOIN patients pt ON pt.PatientID = p.PatientID
                     LEFT JOIN visits v ON v.VisitID = p.VisitID
                     WHERE " . implode(' AND ', $where) .
                    " ORDER BY p.PaidAt DESC, p.PaymentID DESC LIMIT 500",
                    $params
                );
                foreach ($rows as &$paymentRow) {
                    $paymentRow['source'] = tdc_payment_source_label($paymentRow);
                    unset($paymentRow['VisitID'], $paymentRow['VisitReference'], $paymentRow['LaboratoryID'], $paymentRow['PrescriptionReference'], $paymentRow['SaleReference'], $paymentRow['PurchaseReference']);
                }
                unset($paymentRow);
                break;

            case 'billing':
            case 'billing-consultation':
            case 'billing-laboratory':
            case 'billing-pharmacy':
            case 'billing-services':
            case 'outstanding':
                $union = tdc_rc_money_union($from, $to, $search, $status, tdc_has_column($pdo, 'pharmacysales', 'SaleStatus'), $hasBillAdjustments, $hasServices);
                $params = $union['params'];
                $inner = 'SELECT * FROM (' . $union['sql'] . ') t';
                if (in_array($key, ['billing-consultation', 'billing-laboratory', 'billing-pharmacy', 'billing-services'], true)) {
                    $billingServices = [
                        'billing-consultation' => 'Consultation',
                        'billing-laboratory' => 'Laboratory',
                        'billing-services' => 'Service',
                        'billing-pharmacy' => 'Pharmacy',
                    ];
                    $params['billing_service'] = $billingServices[$key] ?? 'Pharmacy';
                    $inner .= ' WHERE t.Service = :billing_service';
                }
                if ($key === 'outstanding') $inner .= ' WHERE t.Due > 0';
                if ($key === 'outstanding' && isset($params['billing_service'])) $inner = str_replace(' WHERE t.Due > 0', ' WHERE t.Service = :billing_service AND t.Due > 0', $inner);
                $inner .= ' ORDER BY t.Dt DESC LIMIT 500';
                $rows = $run($inner, $params);
                if ($key === 'outstanding') {
                    $cols = [
                        ['Service', 'Service', 'text'], ['Ref', 'Reference', 'text'],
                        ['Party', 'Patient / Customer', 'text'], ['Dt', 'Date', 'date'],
                        ['Gross', 'Gross', 'money'], ['Discount', 'Discount', 'money'], ['Tax', 'Tax', 'money'], ['Total', 'Final Billed', 'money'], ['Paid', 'Paid', 'money'],
                        ['Due', 'Outstanding', 'money'],
                    ];
                } else {
                    $cols = [
                        ['Service', 'Service', 'text'], ['Ref', 'Reference', 'text'],
                        ['Party', 'Patient / Customer', 'text'], ['Dt', 'Date', 'date'],
                        ['Gross', 'Gross', 'money'], ['Discount', 'Discount', 'money'], ['Tax', 'Tax', 'money'], ['Total', 'Final Billed', 'money'], ['Paid', 'Paid', 'money'],
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
                    . " FROM pharmacysales WHERE " . (tdc_has_column($pdo, 'pharmacysales', 'SaleStatus') ? "SaleStatus <> 'Voided'" : '1=1')
                    . ($where ? ' AND ' . implode(' AND ', $where) : '')
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
                    . " FROM purchases"
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
                    . " FROM inventory"
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
                    . " FROM inventory WHERE " . implode(' AND ', $where)
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
                    . " FROM laboratory l"
                    . " LEFT JOIN patients p ON p.PatientID = l.PatientID"
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
                    . " FROM laboratory l"
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
                    . " FROM accounting a WHERE " . implode(' AND ', $where)
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
                    . " FROM accounting a"
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
        switch ($key) {
            case 'visits':
            case 'billing':
            case 'billing-consultation':
            case 'billing-laboratory':
            case 'billing-pharmacy':
            case 'billing-services':
            case 'pharmacy-sales':
            case 'lab-orders':
            case 'lab-completed':
            case 'lab-pending':
                return ['Paid', 'Partial', 'Unpaid'];
            case 'payments':
                return ['Confirmed', 'Voided'];
            case 'transactions':
                return ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
            default:
                return [];
        }
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
    function tdc_rc_render_landing(array $hubSummary = [], array $landingFilters = []): string
    {
        $html = '<div class="welcome-eyebrow">Reports</div>'
            . '<div class="welcome-title">Report Center</div>'
            . '<div class="welcome-sub">Every operational and financial report, computed live from clinic records.</div>';

        if ($hubSummary) {
            $html .= '<div class="kpi-grid report-kpis">';
            foreach ($hubSummary as $label => $value) {
                $isMoney = str_contains(strtolower((string) $label), 'revenue')
                    || str_contains(strtolower((string) $label), 'balance')
                    || str_contains(strtolower((string) $label), 'income');
                $icon = str_contains(strtolower($label),'pharmacy') ? 'pill' : (str_contains(strtolower($label),'lab') ? 'flask' : ($isMoney ? 'wallet' : 'users'));
                $html .= '<div class="kpi-card"><span class="kpi-icon">'.tdc_icon($icon,20).'</span><div class="kpi-label">' . tdc_ui_h($label) . '</div>'
                    . '<div class="kpi-value">' . ($isMoney ? number_format((float) $value, 2) : number_format((float) $value, 0)) . '</div></div>';
            }
            $html .= '</div>';
        }

        $period = (string) ($landingFilters['period'] ?? 'this_month');
        $from = (string) ($landingFilters['from'] ?? '');
        $to = (string) ($landingFilters['to'] ?? '');
        $period = (string) ($landingFilters['period'] ?? 'this_month');
        $from = (string) ($landingFilters['from'] ?? '');
        $to = (string) ($landingFilters['to'] ?? '');
        $selectedModule = (string) ($landingFilters['module'] ?? 'reception');
        $catalog = tdc_rc_catalog();
        if (!isset($catalog[$selectedModule])) $selectedModule = 'reception';
        $periodQuery = '&period=' . urlencode($period) . '&from_date=' . urlencode($from) . '&to_date=' . urlencode($to);
        $html .= '<section class="report-period-panel no-print"><div><strong>Selected reporting period</strong><span>Use one period across the report workspace.</span></div><form method="get" action="reports.php" class="report-period-form"><label>Quick period<select name="period"><option value="today"'.($period === 'today' ? ' selected' : '').'>Today</option><option value="yesterday"'.($period === 'yesterday' ? ' selected' : '').'>Yesterday</option><option value="this_week"'.($period === 'this_week' ? ' selected' : '').'>This Week</option><option value="this_month"'.($period === 'this_month' ? ' selected' : '').'>This Month</option><option value="last_month"'.($period === 'last_month' ? ' selected' : '').'>Last Month</option><option value="this_year"'.($period === 'this_year' ? ' selected' : '').'>This Year</option><option value="custom"'.($period === 'custom' ? ' selected' : '').'>Custom</option></select></label><label>From<input type="date" name="from_date" value="'.tdc_ui_h($from).'" aria-label="From date"></label><label>To<input type="date" name="to_date" value="'.tdc_ui_h($to).'" aria-label="To date"></label><button class="btn-primary btn" type="submit">Apply</button><a class="btn-secondary btn" href="reports.php">Reset</a></form></section>';
        $html .= '<nav class="report-module-tabs no-print" aria-label="Report modules">';
        foreach ($catalog as $moduleKey => $module) {
            $active = $moduleKey === $selectedModule ? ' active' : '';
            $moduleFirst = (string) array_key_first($module['reports']);
            $html .= '<a class="report-module-tab'.$active.'" href="reports.php?section='.urlencode($moduleFirst).$periodQuery.'#report-workspace">'.tdc_icon($module['icon'], 15).'<span>'.tdc_ui_h($module['label']).'</span></a>';
        }
        $html .= '</nav>';
        $group = $catalog[$selectedModule];
        $html .= '<div id="report-workspace" class="report-workspace"><aside class="report-side-nav no-print"><div class="report-side-title">'.tdc_ui_h($group['label']).' reports</div>';
        $financialKeys = ['billing','billing-consultation','billing-services','billing-laboratory','billing-pharmacy','payments','outstanding','pharmacy-sales','pharmacy-purchases','lab-revenue','revenue-by-account','expenses','transactions','income-statement','balance-sheet'];
        foreach (['Operational' => false, 'Financial' => true] as $kindLabel => $isFinancial) {
            $html .= '<div class="report-side-heading">'.$kindLabel.'</div><div class="report-side-links">';
            $has = false;
            foreach ($group['reports'] as $key => $meta) {
                if (in_array($key, $financialKeys, true) !== $isFinancial) continue;
                $has = true;
                $href = 'reports.php?section='.urlencode($key).$periodQuery;
                $html .= '<a href="'.tdc_ui_h($href).'">'.tdc_ui_h($meta['title']).'</a>';
            }
            if (!$has) $html .= '<span class="report-side-empty">No reports</span>';
            $html .= '</div>';
        }
        $firstReport = (string) array_key_first($group['reports']);
        $html .= '</aside><section class="report-workspace-main"><div class="report-workspace-kicker">Reports / '.tdc_ui_h($group['label']).'</div><h2>'.tdc_ui_h($group['reports'][$firstReport]['title'] ?? $group['label']).'</h2><p>'.tdc_ui_h($group['reports'][$firstReport]['desc'] ?? 'Select a report from the menu to view its results.').'</p><a class="btn-primary btn" href="reports.php?section='.urlencode($firstReport).$periodQuery.'">View report</a></section></div>';
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
        $catalog = tdc_rc_catalog();
        $groupKey = (string) ($meta['group'] ?? 'reception');
        $group = $catalog[$groupKey] ?? reset($catalog);
        $periodQuery = '&from_date=' . urlencode((string) ($filters['from'] ?? '')) . '&to_date=' . urlencode((string) ($filters['to'] ?? ''));
        $financialKeys = ['billing','billing-consultation','billing-services','billing-laboratory','billing-pharmacy','payments','outstanding','pharmacy-sales','pharmacy-purchases','lab-revenue','revenue-by-account','expenses','transactions','income-statement','balance-sheet'];
        $html = '<nav class="report-module-tabs no-print" aria-label="Report modules">';
        foreach ($catalog as $moduleKey => $module) {
            $active = $moduleKey === $groupKey ? ' active' : '';
            $first = (string) array_key_first($module['reports']);
            $html .= '<a class="report-module-tab'.$active.'" href="reports.php?section='.urlencode($first).$periodQuery.'">'.tdc_icon($module['icon'], 15).'<span>'.tdc_ui_h($module['label']).'</span></a>';
        }
        $html .= '</nav><div class="report-workspace-live"><aside class="report-side-nav no-print"><div class="report-side-title">'.tdc_ui_h($group['label']).' reports</div>';
        foreach (['Operational' => false, 'Financial' => true] as $kindLabel => $isFinancial) {
            $html .= '<div class="report-side-heading">'.$kindLabel.'</div><div class="report-side-links">';
            $has = false;
            foreach ($group['reports'] as $reportKey => $reportMeta) {
                if (in_array($reportKey, $financialKeys, true) !== $isFinancial) continue;
                $has = true; $active = $reportKey === $key ? ' active' : '';
                $html .= '<a class="'.$active.'" href="reports.php?section='.urlencode($reportKey).$periodQuery.'">'.tdc_ui_h($reportMeta['title']).'</a>';
            }
            if (!$has) $html .= '<span class="report-side-empty">No reports</span>';
            $html .= '</div>';
        }
        $html .= '</aside><main class="report-workspace-main report-active-content"><div class="report-head">'
            . '<span class="report-head-icon">' . tdc_icon($meta['icon'] ?? 'grid', 20) . '</span>'
            . '<div><div class="report-breadcrumb">Reports / ' . tdc_ui_h($meta['groupLabel'] ?? 'Reports') . ' / ' . tdc_ui_h($meta['title']) . '</div><div class="welcome-title">' . tdc_ui_h($meta['title']) . '</div>'
            . '<div class="welcome-sub" style="margin-bottom:0">' . tdc_ui_h($meta['desc'] ?? '') . '</div><div class="selected-period-label">Selected Period: ' . tdc_ui_h(($filters['from'] ?? '') !== '' ? date('d M Y', strtotime((string)$filters['from'])) : 'All dates') . ' – ' . tdc_ui_h(($filters['to'] ?? '') !== '' ? date('d M Y', strtotime((string)$filters['to'])) : 'Present') . '</div></div>'
            . '</div>';

        $statusOptions = tdc_rc_status_options($key);
        $fromValue = (string) ($filters['from'] ?? '');
        $toValue = (string) ($filters['to'] ?? '');
        $searchValue = (string) ($filters['search'] ?? '');
        $statusValue = (string) ($filters['status'] ?? '');
        $isInventory = in_array($key, ['pharmacy-stock', 'pharmacy-low-stock', 'pharmacy-expiry'], true);
        $clearUrl = 'reports.php?section=' . urlencode($key);
        $html .= '<div class="report-toolbar report-filter-toolbar no-print">'
            . '<form class="report-filters report-filter-form" data-report-filter method="get" action="reports.php">'
            . '<input type="hidden" name="section" value="' . tdc_ui_h($key) . '">'
            . '<label class="quick-period-control report-filter-group"><span>Quick Period</span><select data-quick-period aria-label="Quick period">'
            . '<option value="custom">Custom</option><option value="today">Today</option><option value="yesterday">Yesterday</option><option value="this_week">This Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="this_year">This Year</option>'
            . '</select></label>';
        if (!$isInventory) {
            $html .= '<label class="date-range-field report-filter-group"><span>From Date</span><input type="date" name="from_date" value="' . tdc_ui_h($fromValue) . '"></label>'
                . '<label class="date-range-field report-filter-group"><span>To Date</span><input type="date" name="to_date" value="' . tdc_ui_h($toValue) . '"></label>';
        }
        $html .= '<div class="report-search-field report-filter-search">'
            . tdc_search_field('search', $searchValue, 'Search this report...')
            . '</div>';
        if ($statusOptions) {
            $filterLabel = $key === 'transactions' ? 'Filter by account type' : 'Filter by payment status';
            $allLabel = $key === 'transactions' ? 'All account types' : (in_array($key, $financialKeys, true) ? 'All Payment Statuses' : 'All statuses');
            $html .= '<label class="table-filter report-filter-status report-filter-group"><select name="status" aria-label="' . tdc_ui_h($filterLabel) . '">'
                . '<option value="">' . tdc_ui_h($allLabel) . '</option>';
            foreach ($statusOptions as $opt) {
                $html .= '<option value="' . tdc_ui_h($opt) . '"' . ($statusValue === $opt ? ' selected' : '') . '>' . tdc_ui_h($opt) . '</option>';
            }
            $html .= '</select></label>';
        }
        $html .= '<div class="report-filter-actions">'
            . '<button type="submit" class="btn btn-primary btn-sm">' . tdc_icon('filter', 14) . '<span>Apply Filters</span></button>'
            . '<a class="btn btn-secondary btn-sm" href="' . tdc_ui_h($clearUrl) . '">' . tdc_icon('refresh', 14) . '<span>Reset</span></a>'
            . '</div>'
            . '</form>';
        if ($isInventory) $html .= '<span class="report-context">Current inventory snapshot</span>';
        $exportMarkup = str_replace('data-print-page', 'data-print-page data-report-action="print" data-report-section="' . tdc_ui_h($key) . '"', tdc_export_buttons($exportLinks));
        $html .= '<div class="report-export-actions">' . $exportMarkup . '</div>';
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

        $html .= '<div class="data-table-wrap"><table class="data-table report-data-table" data-report-table><thead><tr>';
        foreach ($columns as $col) {
            $align = in_array(($col[2] ?? ''), ['money', 'number'], true) ? ' class="align-right"' : '';
            $html .= '<th' . $align . ' data-sort-index="' . (int) array_search($col, $columns, true) . '">' . tdc_ui_h($col[1]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$rows) {
            $html .= tdc_empty_state('inbox', 'No records found for the selected filters.', 'Adjust the date range or filters to widen the search.', '<a class="btn btn-secondary btn-sm" href="reports.php?section=' . urlencode($key) . '">Clear Filters</a>', count($columns));
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
        $moneyTotals = [];
        foreach ($columns as $col) {
            if (($col[2] ?? '') !== 'money') continue;
            $sum = 0.0;
            foreach ($rows as $row) $sum += (float) ($row[$col[0]] ?? 0);
            $moneyTotals[$col[0]] = $sum;
        }
        $html .= '</tbody>';
        if ($moneyTotals && $rows) {
            $html .= '<tfoot><tr><th>Total</th>';
            foreach (array_slice($columns, 1) as $col) {
                $align = in_array(($col[2] ?? ''), ['money', 'number'], true) ? ' class="align-right"' : '';
                $html .= '<th' . $align . '>' . (isset($moneyTotals[$col[0]]) ? number_format($moneyTotals[$col[0]], 2) : '') . '</th>';
            }
            $html .= '</tr></tfoot>';
        }
        $html .= '</table></div><div class="report-table-tools no-print"><label>Rows per page<select data-page-size aria-label="Rows per page"><option>10</option><option>25</option><option>50</option><option>100</option></select></label><span data-page-status></span><button type="button" class="btn btn-secondary btn-sm" data-page-prev>Previous</button><button type="button" class="btn btn-secondary btn-sm" data-page-next>Next</button></div>';
        $html .= <<<'REPORT_SCRIPT'
<script>(function(){
document.addEventListener('click',function(event){const button=event.target.closest('[data-report-action="print"]');if(!button)return;event.preventDefault();window.print();});
const table=document.querySelector('[data-report-table]');if(!table)return;
const body=table.tBodies[0],rows=[...body.querySelectorAll('tr:not(.empty-row)')],size=document.querySelector('[data-page-size]'),status=document.querySelector('[data-page-status]'),prev=document.querySelector('[data-page-prev]'),next=document.querySelector('[data-page-next]');let page=1,sortIndex=null,sortDirection=1;
function draw(){const n=+(size?.value||10),pages=Math.max(1,Math.ceil(rows.length/n));page=Math.min(page,pages);rows.forEach((r,i)=>r.hidden=i<((page-1)*n)||i>=page*n);if(status)status.textContent=rows.length?('Showing '+((page-1)*n+1)+'–'+Math.min(page*n,rows.length)+' of '+rows.length+' records'):'No records';if(prev)prev.disabled=page<=1;if(next)next.disabled=page>=pages;}
size?.addEventListener('change',()=>{page=1;draw();});prev?.addEventListener('click',()=>{page--;draw();});next?.addEventListener('click',()=>{page++;draw();});table.querySelectorAll('th[data-sort-index]').forEach(th=>th.addEventListener('click',()=>{const i=+th.dataset.sortIndex;if(sortIndex===i)sortDirection*=-1;else{sortIndex=i;sortDirection=1;}rows.sort((a,b)=>sortDirection*a.cells[i].textContent.trim().localeCompare(b.cells[i].textContent.trim(),undefined,{numeric:true,sensitivity:'base'}));table.querySelectorAll('th[data-sort-index]').forEach(h=>h.removeAttribute('aria-sort'));th.setAttribute('aria-sort',sortDirection===1?'ascending':'descending');page=1;draw();}));draw();
const q=document.querySelector('[data-quick-period]'),from=document.querySelector('[data-report-filter] input[name="from_date"]'),to=document.querySelector('[data-report-filter] input[name="to_date"]');q?.addEventListener('change',()=>{const d=new Date(),fmt=x=>x.toISOString().slice(0,10);let a='',b=fmt(d);if(q.value==='today')a=b;if(q.value==='yesterday'){d.setDate(d.getDate()-1);a=b=fmt(d);}if(q.value==='this_month')a=b.slice(0,8)+'01';if(q.value==='this_year')a=b.slice(0,4)+'-01-01';if(q.value==='this_week'){const w=new Date(d);w.setDate(w.getDate()-((w.getDay()+6)%7));a=fmt(w);}if(q.value==='last_month'){const m=new Date(d.getFullYear(),d.getMonth()-1,1);a=fmt(m);b=fmt(new Date(d.getFullYear(),d.getMonth(),0));}if(from&&a)from.value=a;if(to&&b)to.value=b;});
})();</script>
REPORT_SCRIPT;
        $html .= '</main></div>';
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
