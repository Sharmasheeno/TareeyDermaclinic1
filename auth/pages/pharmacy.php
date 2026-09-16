<?php
/**
 * auth/pages/pharmacy.php
 * ---------------------------------------------------------------------
 * Tarey Derma Clinic — Pharmacy Operations (POS, Purchases, Inventory)
 * ---------------------------------------------------------------------
 * Three related subsystems, matching the "pharmacy.php = POS, Purchases,
 * Inventory" grouping used across the app's navigation:
 *
 *   1. Point of Sale  -> PharmacySales table (multi-line, one register
 *      sale per bill, grouped like Prescriptions: "POS000001-01", ...)
 *   2. Purchases       -> Purchases table (multi-line purchase orders
 *      from a supplier, grouped the same way: "PO000001-01", ...)
 *   3. Inventory       -> Inventory table (single-entity CRUD, plus the
 *      automatic stock movements driven by #1 and #2)
 *
 * Design decisions (see chat reply for full rationale):
 *   - PharmacySales has no catalog table in the original schema; it is
 *     introduced here (migration required — see chat reply) using the
 *     same bill-grouping-by-ID-prefix convention as Prescriptions.
 *   - Purchases.SupplierID has no Suppliers table to join against, so
 *     it is resolved by matching SupplierName against prior purchases
 *     (case-insensitive), falling back to the next integer.
 *   - Sales and Purchase Orders are append-only: create or void, never
 *     edit-in-place, because both move real Inventory stock. Voiding
 *     reverses the exact stock movement; a Purchase Order void is
 *     refused if reversing it would drive any linked item negative.
 *   - Inventory stock decrements on sale use an atomic, race-safe
 *     conditional UPDATE (`WHERE QuantityInStock >= :qty`) rather than
 *     a check-then-write, so two concurrent sales can never oversell
 *     the same item.
 *
 * Security controls (same posture as home.php / reception.php):
 *   - Secure, strict-mode session cookies (HttpOnly, SameSite=Strict,
 *     Secure when served over HTTPS)
 *   - Defensive headers: clickjacking, MIME-sniffing, referrer leakage
 *   - no-store caching so authenticated markup is never cached
 *   - Session gate: unauthenticated requests never reach the markup
 *   - Role gate: only 'superuser' and 'pharmacyuser' may access this
 *     page at all (financial + stock data)
 *   - CSRF-token-checked POST handlers, rotated on every submit
 *   - Prepared statements only — no string-built SQL from user input
 *   - Post/Redirect/Get on every successful write
 *   - All session/user-derived output escaped before hitting HTML
 * ---------------------------------------------------------------------
 */
declare(strict_types=1);

// =======================================================================
// SECTION 1 — Session bootstrap & defensive headers
// =======================================================================
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
require_once __DIR__ . '/../includes/access.php';
tdc_require_access();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate');

// =======================================================================
// SECTION 2 — Reference data & shared constants
// =======================================================================
const ALLOWED_SECTIONS       = ['prescriptions', 'pos', 'purchases', 'inventory'];
const ALLOWED_PHARMACY_ROLES = ['superuser', 'pharmacyuser'];

/**
 * Primary navigation — single source of truth, shared shape with
 * home.php / reception.php / doctors.php / patients.php / settings.php.
 */
const NAV_ITEMS = [
    [
        'href'  => 'home.php',
        'label' => 'Dashboard',
        'icon'  => '<path d="M3 4a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm8-8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm0 8a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>',
    ],
    [
        'href'  => 'reception.php',
        'label' => 'Reception',
        'icon'  => '<path d="M10 2a1 1 0 011 1v1.06A6.002 6.002 0 0116 10v3l1.3 2.6a1 1 0 01-.9 1.4H3.6a1 1 0 01-.9-1.4L4 13v-3a6.002 6.002 0 015-5.94V3a1 1 0 011-1zM8 18a2 2 0 004 0H8z"/>',
    ],
    [
        'href'  => 'doctors.php',
        'label' => 'Doctors',
        'icon'  => '<path d="M7 2a1 1 0 00-1 1v3a1 1 0 002 0V4h4v2a1 1 0 002 0V3a1 1 0 00-1-1H7zM6 8a1 1 0 00-1 1v3a5 5 0 0010 0V9a1 1 0 10-2 0v3a3 3 0 11-6 0V9a1 1 0 00-1-1zm8 8a2 2 0 11-4 0h4z"/>',
    ],
    [
        'href'  => 'patients.php',
        'label' => 'Patients',
        'icon'  => '<path d="M10 2a3 3 0 100 6 3 3 0 000-6zM4 17a6 6 0 1112 0v1H4v-1z"/>',
    ],
    [
        'href'  => 'laboratory.php',
        'label' => 'Laboratory',
        'icon'  => '<path d="M8 2a1 1 0 000 2v4.586l-4.243 4.243A2 2 0 005.172 16h9.656a2 2 0 001.415-3.171L12 8.586V4a1 1 0 100-2H8zm2 2h0v5a1 1 0 01-.293.707L7.4 12h5.2l-2.307-2.293A1 1 0 0110 9V4z"/>',
    ],
    [
        'href'  => 'pharmacy.php',
        'label' => 'Pharmacy',
        'icon'  => '<path d="M13.657 2.343a4 4 0 00-5.657 0L2.343 8a4 4 0 105.657 5.657l5.657-5.657a4 4 0 000-5.657zM8.5 6.5l5 5-1.5 1.5-5-5 1.5-1.5z"/>',
    ],
    [
        'href'  => 'accounting.php',
        'label' => 'Accounting',
        'icon'  => '<path fill-rule="evenodd" d="M4 3a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1H4zm2 3h8v2H6V6zm0 4h8v2H6v-2zm0 4h5v2H6v-2z" clip-rule="evenodd"/>',
    ],
    [
        'href'  => 'reports.php',
        'label' => 'Reports',
        'icon'  => '<path d="M4 13a1 1 0 011-1h1a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm5-5a1 1 0 011-1h1a1 1 0 011 1v9a1 1 0 01-1 1h-1a1 1 0 01-1-1V8zm5-4a1 1 0 011-1h1a1 1 0 011 1v13a1 1 0 01-1 1h-1a1 1 0 01-1-1V4z"/>',
    ],
    [
        'href'  => 'setup.php',
        'label' => 'Setup',
        'icon'  => '<path fill-rule="evenodd" d="M8.34 1.804A1 1 0 019.32 1h1.36a1 1 0 01.98.804l.331 1.652a6.993 6.993 0 011.929 1.115l1.598-.54a1 1 0 011.186.447l.68 1.178a1 1 0 01-.223 1.28l-1.281 1.05a7.05 7.05 0 010 2.228l1.28 1.05a1 1 0 01.224 1.28l-.68 1.178a1 1 0 01-1.187.447l-1.598-.54a6.993 6.993 0 01-1.929 1.115l-.33 1.652a1 1 0 01-.98.804H9.32a1 1 0 01-.98-.804l-.331-1.652a6.993 6.993 0 01-1.929-1.115l-1.598.54a1 1 0 01-1.186-.447l-.68-1.178a1 1 0 01.223-1.28l1.281-1.05a7.05 7.05 0 010-2.228l-1.28-1.05a1 1 0 01-.224-1.28l.68-1.178a1 1 0 011.187-.447l1.598.54A6.993 6.993 0 018.01 3.456l.33-1.652zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/>',
    ],
];

// =======================================================================
// SECTION 3 — Pure helper functions (no I/O beyond the given PDO)
// =======================================================================

/** Escapes a value for safe HTML output. Single source of truth. */
function tdc_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalizes a stored unit label for display.
 *
 * Legacy rows stored numeric conversion factors ("1"), placeholder text
 * ("(1s)", "per 1") or nothing at all in SalesUnit / DefaultPurchaseUnit.
 * Those are never valid unit names, so they collapse to the fallback.
 */
function tdc_norm_unit(?string $value, string $fallback = ''): string
{
    $unit = trim((string) $value);
    $unit = trim($unit, " \t\n\r\0\x0B()");
    if ($unit === '' || is_numeric($unit)) {
        return $fallback;
    }
    if (preg_match('/^(per|unit|units)\b/i', $unit) === 1) {
        return $fallback;
    }
    if (preg_match('/^[0-9]+s$/i', $unit) === 1) {
        return $fallback;
    }
    return $unit;
}

/** Naive English plural for a unit label (Tablet -> Tablets, Box -> Boxes). */
function tdc_plural_unit(string $unit): string
{
    if ($unit === '') {
        return '';
    }
    if (preg_match('/[^aeiou]y$/i', $unit) === 1) {
        return substr($unit, 0, -1) . 'ies';
    }
    if (preg_match('/(s|x|z|ch|sh)$/i', $unit) === 1) {
        return $unit . 'es';
    }
    return $unit . 's';
}

/**
 * Strips a leading honorific (Dr., Mr., Mrs., Ms., Prof.) and returns
 * the first remaining token, for a friendlier greeting/avatar.
 */
function tdc_display_name(string $legalName): string
{
    $titles = ['dr', 'mr', 'mrs', 'ms', 'prof'];
    $parts  = preg_split('/\s+/', trim($legalName)) ?: [];

    while (!empty($parts) && in_array(strtolower(rtrim($parts[0], '.')), $titles, true)) {
        array_shift($parts);
    }

    $remaining = trim(implode(' ', $parts));

    return $remaining !== '' ? $remaining : ($legalName !== '' ? $legalName : 'User');
}

/** Strict Y-m-d date validator (rejects "2026-02-31" style overflow dates). */
function tdc_is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/**
 * Self-contained, CSRF-guarded logout (?logout=1&csrf=...).
 * Exits the script when a logout is processed; otherwise returns.
 */
function tdc_handle_logout(): void
{
    if (!isset($_GET['logout'])) {
        return;
    }

    $validLogoutToken = !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_GET['csrf'] ?? '');

    if ($validLogoutToken) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookieParams = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['secure'],
                $cookieParams['httponly']
            );
        }
        session_destroy();
    }

    header('Location: ../auth.php');
    exit;
}

/** Redirects (Post/Redirect/Get) back to a section with a one-shot flash flag. */
function tdc_redirect(string $section, string $flag): void
{
    header('Location: pharmacy.php?section=' . urlencode($section) . '&' . $flag . '=1');
    exit;
}

/** Runs a scalar query and returns the single value. */
function tdc_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Generates the next zero-padded reference for a VARCHAR primary key,
 * e.g. tdc_next_ref($pdo, 'Inventory', 'ItemID', 'ITM') -> "ITM000042".
 * Table/column are always hardcoded call-site literals — never user input.
 */
function tdc_next_ref(PDO $pdo, string $table, string $column, string $prefix, int $pad = 6): string
{
    $sql  = "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY LENGTH({$column}) DESC, {$column} DESC LIMIT 1";
    $last = tdc_scalar($pdo, $sql, ['pattern' => $prefix . '%']);

    $next = 1;
    if ($last !== false && $last !== null) {
        $next = ((int) substr((string) $last, strlen($prefix))) + 1;
    }

    return $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

/**
 * Generates the next bill/PO base reference, e.g. "POS000042" or
 * "PO000042". Individual lines are stored as "{base}-01", "-02", etc.
 * — the same grouping-by-ID-prefix convention Prescriptions already
 * uses in reception.php, applied here to PharmacySales and Purchases.
 */
function tdc_next_bill_base(PDO $pdo, string $table, string $column, string $prefix): string
{
    $last = tdc_scalar(
        $pdo,
        "SELECT {$column} FROM {$table} WHERE {$column} LIKE :pattern ORDER BY LENGTH({$column}) DESC, {$column} DESC LIMIT 1",
        ['pattern' => $prefix . '%']
    );

    $next = 1;
    if ($last !== false && $last !== null) {
        $base = strtok((string) $last, '-');
        $next = ((int) substr($base, strlen($prefix))) + 1;
    }

    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/**
 * Resolves a SupplierID for a given supplier name. There is no
 * Suppliers catalog table in this schema, so an existing supplier is
 * reused by matching SupplierName (case-insensitive) against prior
 * purchases; otherwise the next available integer id is issued.
 */
function tdc_find_or_create_supplier_id(PDO $pdo, string $supplierName): int
{
    $existing = tdc_scalar(
        $pdo,
        'SELECT SupplierID FROM Purchases WHERE LOWER(SupplierName) = LOWER(:name) ORDER BY PurchaseDate DESC LIMIT 1',
        ['name' => $supplierName]
    );
    if ($existing !== false && $existing !== null) {
        return (int) $existing;
    }

    return (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(SupplierID), 0) FROM Purchases') + 1;
}

/**
 * Fetches a stock/price snapshot for a set of Inventory item ids, used
 * to authoritatively validate a POS cart server-side regardless of
 * what the client submitted.
 *
 * @param string[] $itemIds
 * @return array<string, array{name:string, price:float, stock:int}>
 */
function tdc_fetch_stock_map(PDO $pdo, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_filter($itemIds, static fn ($v) => trim((string) $v) !== '')));
    if (empty($itemIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT ItemID, ItemName, SellingPrice, QuantityInStock FROM Inventory WHERE ItemID IN ({$placeholders})");
    $stmt->execute(array_values($itemIds));

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['ItemID']] = [
            'name'  => $row['ItemName'],
            'price' => (float) $row['SellingPrice'],
            'stock' => (int) $row['QuantityInStock'],
        ];
    }
    return $map;
}

// =======================================================================
// SECTION 4 — Validation functions
// =======================================================================

// --- 4A. Inventory -------------------------------------------------------

/** @param array{ItemName:string,Category:string,QuantityInStock:string,SalesUnit:string,SellingPrice:string,ReorderLevel:string,ExpiryDate:string,DefaultPurchaseUnit?:string,UnitsPerPackage?:string} $input */
function tdc_validate_inventory_form(array $input): array
{
    $errors = [];

    if ($input['ItemName'] === '' || mb_strlen($input['ItemName']) > 150) {
        $errors[] = 'Item name is required (max 150 characters).';
    }
    if ($input['Category'] !== '' && mb_strlen($input['Category']) > 100) {
        $errors[] = 'Category must be 100 characters or fewer.';
    }
    $baseUnit = tdc_norm_unit((string) ($input['SalesUnit'] ?? ''));
    if ($baseUnit === '') {
        $errors[] = 'Base unit is required (for example Tablet, Capsule, Bottle).';
    } elseif (mb_strlen($baseUnit) > 50) {
        $errors[] = 'Base unit must be 50 characters or fewer.';
    }
    if ($input['QuantityInStock'] === '' || !ctype_digit($input['QuantityInStock'])) {
        $errors[] = 'Quantity in stock must be a whole number of 0 or more.';
    }
    if ($input['SellingPrice'] === '' || !is_numeric($input['SellingPrice']) || !is_finite((float)$input['SellingPrice']) || (float)$input['SellingPrice'] < 0) {
        $errors[] = 'Selling price must be a valid non-negative number.';
    }
    if ($input['ReorderLevel'] !== '' && !ctype_digit($input['ReorderLevel'])) {
        $errors[] = 'Reorder level must be a whole number of 0 or more.';
    }
    if ($input['ExpiryDate'] !== '' && !tdc_is_valid_date($input['ExpiryDate'])) {
        $errors[] = 'Expiry date is not a valid date.';
    }

    $packUnit = trim((string) ($input['DefaultPurchaseUnit'] ?? ''));
    $packSize = trim((string) ($input['UnitsPerPackage'] ?? ''));
    if ($packUnit !== '' && mb_strlen($packUnit) > 50) {
        $errors[] = 'Default purchase package must be 50 characters or fewer.';
    }
    if ($packSize !== '' && (!ctype_digit($packSize) || (int) $packSize < 1)) {
        $errors[] = 'Units per package must be a whole number of 1 or more.';
    }
    if ($packUnit !== '' && $packSize === '') {
        $errors[] = 'Enter how many ' . ($baseUnit !== '' ? $baseUnit . 's' : 'base units') . ' are in one ' . $packUnit . '.';
    }
    if ($packUnit === '' && $packSize !== '') {
        $errors[] = 'Choose a default purchase package for the units-per-package value.';
    }
    if ($baseUnit === '' && ($packUnit !== '' || $packSize !== '')) {
        $errors[] = 'Set a base unit before configuring a purchase package.';
    }

    return $errors;
}

// --- 4B. Purchases ---------------------------------------------------------

/** @param array{SupplierName:string,SupplierPhone:string,AmountPaid:string,ItemName:array} $input */
function tdc_validate_purchase_form(array $input): array
{
    $errors = [];

    if ($input['SupplierName'] === '' || mb_strlen($input['SupplierName']) > 150) {
        $errors[] = 'Supplier name is required (max 150 characters).';
    }
    if ($input['SupplierPhone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $input['SupplierPhone'])) {
        $errors[] = 'Supplier phone number format is invalid.';
    }
    if ($input['AmountPaid'] !== '' && (!is_numeric($input['AmountPaid']) || (float) $input['AmountPaid'] < 0)) {
        $errors[] = 'Amount paid must be a valid non-negative number.';
    }
    $purchaseDate = trim((string) ($input['PurchaseDate'] ?? ''));
    if ($purchaseDate !== '' && !tdc_is_valid_date($purchaseDate)) {
        $errors[] = 'Purchase date is not a valid date.';
    }
    $reference = trim((string) ($input['ReferenceNumber'] ?? ''));
    if (mb_strlen($reference) > 60) {
        $errors[] = 'Reference number is too long (max 60 characters).';
    }
    $discountRaw = trim((string) ($input['Discount'] ?? ''));
    if ($discountRaw !== '' && (!is_numeric($discountRaw) || (float) $discountRaw < 0)) {
        $errors[] = 'Discount must be a valid non-negative number.';
    }
    $vatRaw = trim((string) ($input['VATAmount'] ?? ''));
    if ($vatRaw !== '' && (!is_numeric($vatRaw) || (float) $vatRaw < 0)) {
        $errors[] = 'VAT must be a valid non-negative number.';
    }

    $hasLine = false;
    foreach ($input['ItemName'] as $i => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $hasLine = true;
        $lineNo  = $i + 1;

        if (mb_strlen($name) > 150) {
            $errors[] = "Item name at line {$lineNo} is too long (max 150 characters).";
        }

        $qty = trim((string) ($input['Quantity'][$i] ?? ''));
        if ($qty === '' || !ctype_digit($qty) || (int) $qty < 1) {
            $errors[] = "Quantity at line {$lineNo} must be a positive whole number.";
        }

        $unitPrice = trim((string) ($input['UnitPrice'][$i] ?? ''));
        if ($unitPrice === '' || !is_numeric($unitPrice) || !is_finite((float) $unitPrice) || (float) $unitPrice < 0) {
            $errors[] = "Unit price at line {$lineNo} must be a valid non-negative number.";
        }

        $sellingPrice = trim((string) ($input['SellingPrice'][$i] ?? ''));
        if ($sellingPrice === '' || !is_numeric($sellingPrice) || !is_finite((float) $sellingPrice) || (float) $sellingPrice < 0) {
            $errors[] = "Selling price at line {$lineNo} must be a valid non-negative number.";
        }

        $conversion = trim((string) ($input['ConversionFactor'][$i] ?? ''));
        if ($conversion !== '' && (!is_numeric($conversion) || (float) $conversion <= 0)) {
            $errors[] = "Conversion factor at line {$lineNo} must be a positive number.";
        }

        $expiry = trim((string) ($input['ExpiryDate'][$i] ?? ''));
        if ($expiry !== '' && !tdc_is_valid_date($expiry)) {
            $errors[] = "Expiry date at line {$lineNo} is not a valid date.";
        }
    }
    if (!$hasLine) {
        $errors[] = 'Add at least one item line.';
    }

    $paidRaw = trim((string) ($input['AmountPaid'] ?? ''));
    if ($hasLine) {
        $orderTotal = 0.0;
        foreach ($input['ItemName'] as $i => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $orderTotal += (int) ($input['Quantity'][$i] ?? 0) * (float) ($input['UnitPrice'][$i] ?? 0);
        }
        $orderNet = round($orderTotal - (float) ($discountRaw !== '' ? $discountRaw : 0) + (float) ($vatRaw !== '' ? $vatRaw : 0), 2);
        if ((float) $discountRaw > $orderTotal) {
            $errors[] = 'Discount cannot exceed the purchase subtotal.';
            $orderNet = 0.0;
        }
        if (round((float) $paidRaw, 2) > $orderNet) {
            $errors[] = 'Amount paid cannot exceed the net amount of ' . number_format($orderNet, 2) . '.';
        }
    }

    return $errors;
}

// --- 4C. Point of Sale -----------------------------------------------------

/**
 * @param array{CustomerPhone:string,AmountPaid:string,ItemID:array,Quantity:array,UnitPrice:array} $input
 * @param array<string, array{name:string, price:float, stock:int}> $stockByItemId
 */
function tdc_validate_pos_form(array $input, array $stockByItemId): array
{
    $errors = [];

    if ($input['CustomerPhone'] !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $input['CustomerPhone'])) {
        $errors[] = 'Customer phone number format is invalid.';
    }
    if ($input['AmountPaid'] !== '' && (!is_numeric($input['AmountPaid']) || (float) $input['AmountPaid'] < 0)) {
        $errors[] = 'Amount paid must be a valid non-negative number.';
    }

    $hasLine            = false;
    $requestedQtyByItem = [];

    foreach ($input['ItemID'] as $i => $itemId) {
        $itemId = trim((string) $itemId);
        if ($itemId === '') {
            continue;
        }
        $hasLine = true;
        $lineNo  = $i + 1;

        if (!isset($stockByItemId[$itemId])) {
            $errors[] = "Line {$lineNo}: selected item no longer exists.";
            continue;
        }

        $qty = trim((string) ($input['Quantity'][$i] ?? ''));
        if ($qty === '' || !ctype_digit($qty) || (int) $qty < 1) {
            $errors[] = "Line {$lineNo}: quantity must be a positive whole number.";
            continue;
        }

        $requestedQtyByItem[$itemId] = ($requestedQtyByItem[$itemId] ?? 0) + (int) $qty;
    }
    if (!$hasLine) {
        $errors[] = 'Add at least one item to the sale.';
    }

    foreach ($requestedQtyByItem as $itemId => $qty) {
        if ($qty > $stockByItemId[$itemId]['stock']) {
            $name  = $stockByItemId[$itemId]['name'];
            $stock = $stockByItemId[$itemId]['stock'];
            $errors[] = "\"{$name}\" only has {$stock} in stock (requested {$qty}).";
        }
    }

    $authoritativeTotal = 0.0;
    foreach ($requestedQtyByItem as $itemId => $qty) {
        if (isset($stockByItemId[$itemId])) {
            $authoritativeTotal += $qty * $stockByItemId[$itemId]['price'];
        }
    }
    if ($input['AmountPaid'] !== '' && is_numeric($input['AmountPaid']) && (float)$input['AmountPaid'] > $authoritativeTotal) {
        $errors[] = 'Amount paid cannot exceed the sale total.';
    }

    return $errors;
}

// =======================================================================
// SECTION 5 — Persistence functions
// =======================================================================

// --- 5A. Inventory ---------------------------------------------------------

function tdc_save_inventory_item(PDO $pdo, array $input, bool $isEdit, string $editId): void
{
    $params = [
        'Category'        => $input['Category'] !== '' ? $input['Category'] : null,
        'ItemName'        => $input['ItemName'],
        'QuantityInStock' => (int) $input['QuantityInStock'],
        'SalesUnit'       => $input['SalesUnit'] !== '' ? $input['SalesUnit'] : null,
        'SellingPrice'    => $input['SellingPrice'] !== '' ? round((float) $input['SellingPrice'], 2) : 0.00,
        'ReorderLevel'    => $input['ReorderLevel'] !== '' ? (int) $input['ReorderLevel'] : 10,
        'ExpiryDate'      => $input['ExpiryDate'] !== '' ? $input['ExpiryDate'] : null,
        'DefaultPurchaseUnit' => ($input['DefaultPurchaseUnit'] ?? '') !== '' ? $input['DefaultPurchaseUnit'] : null,
        'UnitsPerPackage' => ($input['UnitsPerPackage'] ?? '') !== '' ? (int) $input['UnitsPerPackage'] : null,
    ];

    if ($isEdit) {
        $params['id'] = $editId;
        $stmt = $pdo->prepare(
            'UPDATE Inventory SET Category = :Category, ItemName = :ItemName,
                QuantityInStock = :QuantityInStock, SalesUnit = :SalesUnit,
                SellingPrice = :SellingPrice, ReorderLevel = :ReorderLevel, ExpiryDate = :ExpiryDate, DefaultPurchaseUnit = :DefaultPurchaseUnit, UnitsPerPackage = :UnitsPerPackage
             WHERE ItemID = :id'
        );
        $stmt->execute($params);
        return;
    }

    $params['ItemID'] = tdc_next_ref($pdo, 'Inventory', 'ItemID', 'ITM');
    $stmt = $pdo->prepare(
        'INSERT INTO Inventory (ItemID, Category, ItemName, QuantityInStock, SalesUnit, SellingPrice, ReorderLevel, ExpiryDate, DefaultPurchaseUnit, UnitsPerPackage)
         VALUES (:ItemID, :Category, :ItemName, :QuantityInStock, :SalesUnit, :SellingPrice, :ReorderLevel, :ExpiryDate, :DefaultPurchaseUnit, :UnitsPerPackage)'
    );
    $stmt->execute($params);
}

/** @return string[] error messages; empty on success */
function tdc_delete_inventory_item(PDO $pdo, string $id): array
{
    // App-level referential guard: no FK constraints in this schema, so
    // we check dependents ourselves before allowing a delete.
    $saleCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM PharmacySales WHERE ItemID = :id', ['id' => $id]);
    $purchaseCount = (int) tdc_scalar(
        $pdo,
        'SELECT COUNT(*) FROM Purchases WHERE LOWER(TRIM(ItemName)) = (SELECT LOWER(TRIM(ItemName)) FROM Inventory WHERE ItemID = :id)',
        ['id' => $id]
    );

    if ($saleCount > 0 || $purchaseCount > 0) {
        return ['This item has purchase or sale history and cannot be deleted. Set its stock to 0 instead.'];
    }

    $stmt = $pdo->prepare('DELETE FROM Inventory WHERE ItemID = :id');
    $stmt->execute(['id' => $id]);
    return [];
}

/**
 * Adds received stock into Inventory for one purchase-order line,
 * matching an existing item by name (case-insensitive) or creating a
 * new catalog entry. Quantity is normalized from purchase units into
 * sales units via the line's ConversionFactor.
 */
function tdc_upsert_inventory_from_purchase(PDO $pdo, array $line, int $supplierId): void
{
    $existingId = false;
    $lineItemId = trim((string) ($line['ItemID'] ?? ''));
    if ($lineItemId !== '') {
        $byId = $pdo->prepare('SELECT ItemID FROM Inventory WHERE ItemID = :id LIMIT 1');
        $byId->execute(['id' => $lineItemId]);
        $existingId = $byId->fetchColumn();
    }
    if ($existingId === false) {
        $stmt = $pdo->prepare('SELECT ItemID FROM Inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name)) LIMIT 1');
        $stmt->execute(['name' => $line['ItemName']]);
        $existingId = $stmt->fetchColumn();
    }

    $addQty = (int) round($line['Quantity'] * $line['ConversionFactor']);

    if ($existingId !== false) {
        $upd = $pdo->prepare(
            'UPDATE Inventory SET QuantityInStock = QuantityInStock + :addQty, Category = COALESCE(:Category, Category),
                SalesUnit = COALESCE(:SalesUnit, SalesUnit), SellingPrice = :SellingPrice,
                SupplierID = :SupplierID, ExpiryDate = COALESCE(:ExpiryDate, ExpiryDate)
             WHERE ItemID = :id'
        );
        $upd->execute([
            'addQty'       => $addQty,
            'Category'     => $line['Category'] !== '' ? $line['Category'] : null,
            'SalesUnit'    => $line['SalesUnit'] !== '' ? $line['SalesUnit'] : null,
            'SellingPrice' => $line['SellingPrice'],
            'SupplierID'   => $supplierId,
            'ExpiryDate'   => $line['ExpiryDate'] !== '' ? $line['ExpiryDate'] : null,
            'id'           => $existingId,
        ]);
        return;
    }

    $itemId = tdc_next_ref($pdo, 'Inventory', 'ItemID', 'ITM');
    $ins = $pdo->prepare(
        'INSERT INTO Inventory (ItemID, Category, ItemName, QuantityInStock, SalesUnit, SellingPrice, ReorderLevel, ExpiryDate, SupplierID)
         VALUES (:ItemID, :Category, :ItemName, :QuantityInStock, :SalesUnit, :SellingPrice, 10, :ExpiryDate, :SupplierID)'
    );
    $ins->execute([
        'ItemID'          => $itemId,
        'Category'        => $line['Category'] !== '' ? $line['Category'] : null,
        'ItemName'        => $line['ItemName'],
        'QuantityInStock' => $addQty,
        'SalesUnit'       => $line['SalesUnit'] !== '' ? $line['SalesUnit'] : null,
        'SellingPrice'    => $line['SellingPrice'],
        'ExpiryDate'      => $line['ExpiryDate'] !== '' ? $line['ExpiryDate'] : null,
        'SupplierID'      => $supplierId,
    ]);
}

// --- 5B. Purchases (append-only: create + void) ---------------------------

/** @return string the generated PO base reference, e.g. "PO000042" */
function tdc_save_purchase(PDO $pdo, array $input): string
{
    $supplierId = tdc_find_or_create_supplier_id($pdo, $input['SupplierName']);
    $base       = tdc_next_bill_base($pdo, 'Purchases', 'PurchaseID', 'PO');

    $lines       = [];
    $totalAmount = 0.0;
    foreach ($input['ItemName'] as $i => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $qty       = (int) ($input['Quantity'][$i] ?? 0);
        $unitPrice = round((float) ($input['UnitPrice'][$i] ?? 0), 2);
        $lineTotal = round($qty * $unitPrice, 2);
        $totalAmount += $lineTotal;

        $lines[] = [
            'ItemID'           => trim((string) ($input['ItemID'][$i] ?? '')),
            'ItemName'         => $name,
            'Category'         => trim((string) ($input['Category'][$i] ?? '')),
            'PurchaseUnit'     => trim((string) ($input['PurchaseUnit'][$i] ?? '')),
            'ConversionFactor' => trim((string) ($input['ConversionFactor'][$i] ?? '')) !== ''
                ? (float) $input['ConversionFactor'][$i] : 1.00,
            'SalesUnit'        => trim((string) ($input['SalesUnit'][$i] ?? '')),
            'Quantity'         => $qty,
            'UnitPrice'        => $unitPrice,
            'SellingPrice'     => trim((string) ($input['SellingPrice'][$i] ?? '')) !== ''
                ? round((float) $input['SellingPrice'][$i], 2) : $unitPrice,
            'ExpiryDate'       => trim((string) ($input['ExpiryDate'][$i] ?? '')),
        ];
    }

    $subtotal  = round($totalAmount, 2);
    $discount  = round((float) (trim((string) ($input['Discount'] ?? '')) !== '' ? $input['Discount'] : 0), 2);
    $vatAmount = round((float) (trim((string) ($input['VATAmount'] ?? '')) !== '' ? $input['VATAmount'] : 0), 2);
    $netAmount = round($subtotal - $discount + $vatAmount, 2);
    if ($netAmount < 0) {
        $netAmount = 0.0;
    }
    $amountPaid = round((float) ($input['AmountPaid'] !== '' ? $input['AmountPaid'] : 0), 2);
    if ($amountPaid > $netAmount) {
        $amountPaid = $netAmount;
    }
    $dueBalance = max(0.0, round($netAmount - $amountPaid, 2));
    $reference  = trim((string) ($input['ReferenceNumber'] ?? ''));
    $purchaseDate = trim((string) ($input['PurchaseDate'] ?? ''));
    $purchaseAt   = $purchaseDate !== '' ? $purchaseDate . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO Purchases (PurchaseID, SupplierID, SupplierName, SupplierPhone, ReferenceNumber, Category, ItemName,
                Quantity, MinimumQuantity, PurchaseUnit, ConversionFactor, SalesUnit, UnitPrice, SellingPrice,
                TotalAmount, AmountPaid, DueBalance, Discount, VATAmount, PurchaseDate)
             VALUES (:PurchaseID, :SupplierID, :SupplierName, :SupplierPhone, :ReferenceNumber, :Category, :ItemName,
                :Quantity, 10, :PurchaseUnit, :ConversionFactor, :SalesUnit, :UnitPrice, :SellingPrice,
                :TotalAmount, :AmountPaid, :DueBalance, :Discount, :VATAmount, :PurchaseDate)'
        );

        $line = 0;
        foreach ($lines as $l) {
            $line++;
            $insert->execute([
                'PurchaseID'       => $base . '-' . str_pad((string) $line, 2, '0', STR_PAD_LEFT),
                'SupplierID'       => $supplierId,
                'SupplierName'     => $input['SupplierName'],
                'SupplierPhone'    => $input['SupplierPhone'] !== '' ? $input['SupplierPhone'] : null,
                'ReferenceNumber'  => $reference !== '' ? $reference : null,
                'Category'         => $l['Category'] !== '' ? $l['Category'] : null,
                'ItemName'         => $l['ItemName'],
                'Quantity'         => $l['Quantity'],
                'PurchaseUnit'     => $l['PurchaseUnit'] !== '' ? $l['PurchaseUnit'] : null,
                'ConversionFactor' => $l['ConversionFactor'],
                'SalesUnit'        => $l['SalesUnit'] !== '' ? $l['SalesUnit'] : null,
                'UnitPrice'        => $l['UnitPrice'],
                'SellingPrice'     => $l['SellingPrice'],
                'TotalAmount'      => $netAmount,
                'AmountPaid'       => $amountPaid,
                'DueBalance'       => $dueBalance,
                'Discount'         => $discount,
                'VATAmount'        => $vatAmount,
                'PurchaseDate'     => $purchaseAt,
            ]);

            tdc_upsert_inventory_from_purchase($pdo, $l, $supplierId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $base;
}

/** @return string[] error messages; empty on success */
function tdc_void_purchase(PDO $pdo, string $base): array
{
    $stmt = $pdo->prepare('SELECT ItemName, Quantity, ConversionFactor FROM Purchases WHERE PurchaseID LIKE :pattern');
    $stmt->execute(['pattern' => $base . '-%']);
    $lines = $stmt->fetchAll();

    if (empty($lines)) {
        return ['Purchase order not found.'];
    }

    // Guard: refuse to void if reversing would drive any linked item's
    // stock negative (some of the received stock has already been sold
    // or adjusted downward elsewhere).
    foreach ($lines as $l) {
        $reduceBy = (int) round($l['Quantity'] * $l['ConversionFactor']);
        $current  = tdc_scalar(
            $pdo,
            'SELECT QuantityInStock FROM Inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name)) LIMIT 1',
            ['name' => $l['ItemName']]
        );
        if ($current !== false && (int) $current < $reduceBy) {
            $name = $l['ItemName'];
            return ["Cannot void: \"{$name}\" only has {$current} in stock, less than the {$reduceBy} originally received. Adjust inventory manually first."];
        }
    }

    $pdo->beginTransaction();
    try {
        $reverse = $pdo->prepare('UPDATE Inventory SET QuantityInStock = QuantityInStock - :qty WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name))');
        foreach ($lines as $l) {
            $reduceBy = (int) round($l['Quantity'] * $l['ConversionFactor']);
            $reverse->execute(['qty' => $reduceBy, 'name' => $l['ItemName']]);
        }

        $del = $pdo->prepare('DELETE FROM Purchases WHERE PurchaseID LIKE :pattern');
        $del->execute(['pattern' => $base . '-%']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [];
}

// --- 5C. Point of Sale (append-only: create + void) ------------------------

/**
 * @param array<string, array{name:string, price:float, stock:int}> $stockByItemId
 * @return string the generated sale base reference, e.g. "POS000042"
 * @throws RuntimeException if stock changed since validation (race)
 */
function tdc_save_sale(PDO $pdo, array $input, array $stockByItemId): string
{
    $base = tdc_next_bill_base($pdo, 'PharmacySales', 'SaleID', 'POS');

    $lines       = [];
    $totalAmount = 0.0;
    foreach ($input['ItemID'] as $i => $itemId) {
        $itemId = trim((string) $itemId);
        if ($itemId === '' || !isset($stockByItemId[$itemId])) {
            continue;
        }
        $qty = (int) ($input['Quantity'][$i] ?? 0);
        if ($qty < 1) {
            continue;
        }
        $unitPrice = round((float) $stockByItemId[$itemId]['price'], 2);
        $lineTotal = round($qty * $unitPrice, 2);
        $totalAmount += $lineTotal;

        $lines[] = [
            'ItemID'    => $itemId,
            'ItemName'  => $stockByItemId[$itemId]['name'],
            'Quantity'  => $qty,
            'UnitPrice' => $unitPrice,
            'LineTotal' => $lineTotal,
        ];
    }

    $amountPaid    = round((float) ($input['AmountPaid'] !== '' ? $input['AmountPaid'] : $totalAmount), 2);
    $dueBalance    = round($totalAmount - $amountPaid, 2);
    $paymentStatus = $amountPaid <= 0 ? 'Unpaid' : ($amountPaid >= $totalAmount ? 'Paid' : 'Partial');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO PharmacySales (SaleID, ItemID, ItemName, Quantity, UnitPrice, LineTotal,
                TotalAmount, AmountPaid, DueBalance, PaymentStatus, CustomerName, CustomerPhone, SoldBy)
             VALUES (:SaleID, :ItemID, :ItemName, :Quantity, :UnitPrice, :LineTotal,
                :TotalAmount, :AmountPaid, :DueBalance, :PaymentStatus, :CustomerName, :CustomerPhone, :SoldBy)'
        );

        // Atomic, race-safe decrement: the WHERE clause makes this a
        // no-op (rowCount 0) if concurrent stock movement has already
        // dropped the item below what this line needs, so two
        // simultaneous sales can never oversell the same item.
        $decrement = $pdo->prepare(
            'UPDATE Inventory SET QuantityInStock = QuantityInStock - :qty
             WHERE ItemID = :id AND QuantityInStock >= :qtyCheck'
        );

        $line = 0;
        foreach ($lines as $l) {
            $line++;
            $insert->execute([
                'SaleID'        => $base . '-' . str_pad((string) $line, 2, '0', STR_PAD_LEFT),
                'ItemID'        => $l['ItemID'],
                'ItemName'      => $l['ItemName'],
                'Quantity'      => $l['Quantity'],
                'UnitPrice'     => $l['UnitPrice'],
                'LineTotal'     => $l['LineTotal'],
                'TotalAmount'   => $totalAmount,
                'AmountPaid'    => $amountPaid,
                'DueBalance'    => $dueBalance,
                'PaymentStatus' => $paymentStatus,
                'CustomerName'  => $input['CustomerName'] !== '' ? $input['CustomerName'] : null,
                'CustomerPhone' => $input['CustomerPhone'] !== '' ? $input['CustomerPhone'] : null,
                'SoldBy'        => $_SESSION['user_id'] ?? null,
            ]);

            $decrement->execute(['qty' => $l['Quantity'], 'id' => $l['ItemID'], 'qtyCheck' => $l['Quantity']]);
            if ($decrement->rowCount() === 0) {
                throw new RuntimeException("Insufficient stock for \"{$l['ItemName']}\" — please refresh and try again.");
            }
        }

        tdc_workflow_post_revenue($pdo, 'REV-PHARM', 'Pharmacy Revenue', $base, 'Point of sale collection ' . $base, $amountPaid);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $base;
}

/** @return string[] error messages; empty on success */
function tdc_void_sale(PDO $pdo, string $base): array
{
    $stmt = $pdo->prepare('SELECT ItemID, Quantity FROM PharmacySales WHERE SaleID LIKE :pattern');
    $stmt->execute(['pattern' => $base . '-%']);
    $lines = $stmt->fetchAll();

    if (empty($lines)) {
        return ['Sale not found.'];
    }

    $pdo->beginTransaction();
    try {
        $restock = $pdo->prepare('UPDATE Inventory SET QuantityInStock = QuantityInStock + :qty WHERE ItemID = :id');
        foreach ($lines as $l) {
            $restock->execute(['qty' => $l['Quantity'], 'id' => $l['ItemID']]);
        }

        $del = $pdo->prepare('DELETE FROM PharmacySales WHERE SaleID LIKE :pattern');
        $del->execute(['pattern' => $base . '-%']);

        $delAccounting = $pdo->prepare("DELETE FROM Accounting WHERE ReferenceID=? AND AccountID='REV-PHARM'");
        $delAccounting->execute([$base]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [];
}

// =======================================================================
// SECTION 6 — Logout (may exit)
// =======================================================================
tdc_handle_logout();

// =======================================================================
// SECTION 7 — Auth gate & role gate
// =======================================================================
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth.php');
    exit;
}

tdc_require_permission('pharmacy.view');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/data-transfer.php';
$paymentMethods = tdc_payment_methods($pdo);
$paymentMethodNames = array_column($paymentMethods, 'MethodName');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 8 — Request-scoped state
// =======================================================================
$section = $_GET['section'] ?? null;
if ($section !== null && !in_array($section, ALLOWED_SECTIONS, true)) {
    $section = null;
}
$pharmacySectionPermissions = ['prescriptions'=>'pharmacy.prescriptions.view','pos'=>'pharmacy.pos','purchases'=>'pharmacy.purchases.manage','inventory'=>'pharmacy.inventory.view'];
if ($section !== null && !tdc_can($pharmacySectionPermissions[$section])) tdc_forbidden();

$canViewPurchaseCost = tdc_can_view_purchase_cost();
if ($section === 'purchases' && !$canViewPurchaseCost && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['new']))) tdc_forbidden();

$errors = [];

$oldSale = [
    'CustomerName' => '', 'CustomerPhone' => '', 'AmountPaid' => '',
    'ItemID' => [], 'Quantity' => [], 'UnitPrice' => [],
];

$oldPurchase = [
    'SupplierName' => '', 'SupplierPhone' => '', 'AmountPaid' => '', 'PurchaseDate' => date('Y-m-d'),
    'ReferenceNumber' => '', 'Discount' => '', 'VATAmount' => '',
    'ItemID' => [], 'ItemName' => [], 'Category' => [], 'PurchaseUnit' => [], 'ConversionFactor' => [],
    'SalesUnit' => [], 'Quantity' => [], 'UnitPrice' => [], 'SellingPrice' => [], 'ExpiryDate' => [],
];

$oldInventory = [
    'ItemID' => '', 'ItemName' => '', 'Category' => '', 'QuantityInStock' => '',
    'SalesUnit' => '', 'SellingPrice' => '', 'ReorderLevel' => '10', 'ExpiryDate' => '',
    'DefaultPurchaseUnit' => '', 'UnitsPerPackage' => '',
];

$posShowForm      = false;
$purchaseShowForm = false;

// =======================================================================
// SECTION 9 — POST handler (dispatch by section)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($section, ALLOWED_SECTIONS, true)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session has expired. Please refresh and try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? 'save');

        // --- Doctor prescriptions -----------------------------------
        if ($section === 'prescriptions') {
            tdc_require_permission('pharmacy.dispense');
            $base = preg_replace('/[^A-Za-z0-9]/','',(string)($_POST['PrescriptionReference'] ?? ''));
            $amountPaid = is_numeric($_POST['AmountPaid'] ?? null) ? round((float)$_POST['AmountPaid'],2) : -1;
            $paymentMethod = (string)($_POST['PaymentMethod'] ?? 'Cash');
            if (!in_array($paymentMethod, $paymentMethodNames, true)) tdc_forbidden();
            $pdo->beginTransaction();
            try {
                $stmt=$pdo->prepare("SELECT * FROM Prescriptions WHERE PrescriptionID LIKE ? AND Status='Pending' ORDER BY PrescriptionID FOR UPDATE");
                $stmt->execute([$base.'-%']);$lines=$stmt->fetchAll();
                if(!$lines) throw new RuntimeException('Prescription is no longer pending.');
                $saleLines=[];$total=0;
                foreach($lines as $line){
                    $stmt=$pdo->prepare('SELECT * FROM Inventory WHERE LOWER(TRIM(ItemName))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE');$stmt->execute([$line['MedicationName']]);$item=$stmt->fetch();
                    $qty=max(1,(int)$line['Quantity']);
                    if(!$item) throw new RuntimeException('No inventory item matches '.$line['MedicationName'].'.');
                    if((int)$item['QuantityInStock']<$qty) throw new RuntimeException('Insufficient stock for '.$line['MedicationName'].'.');
                    $lineTotal=round($qty*(float)$item['SellingPrice'],2);$total+=$lineTotal;$saleLines[]=[$line,$item,$qty,$lineTotal];
                }
                if($amountPaid<0||$amountPaid>$total) throw new RuntimeException('Amount paid must be between zero and the bill total.');
                $saleBase=tdc_next_bill_base($pdo,'PharmacySales','SaleID','POS');$status=tdc_workflow_payment_status($total,$amountPaid);$n=0;
                $insert=$pdo->prepare('INSERT INTO PharmacySales (SaleID,ItemID,ItemName,Quantity,UnitPrice,LineTotal,TotalAmount,AmountPaid,DueBalance,PaymentStatus,CustomerName,CustomerPhone,SoldBy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stock=$pdo->prepare('UPDATE Inventory SET QuantityInStock=QuantityInStock-? WHERE ItemID=? AND QuantityInStock>=?');
                foreach($saleLines as [$line,$item,$qty,$lineTotal]){$n++;$insert->execute([$saleBase.'-'.str_pad((string)$n,2,'0',STR_PAD_LEFT),$item['ItemID'],$item['ItemName'],$qty,$item['SellingPrice'],$lineTotal,$total,$amountPaid,max(0,$total-$amountPaid),$status,$line['PatientName'],$line['PatientPhone'],$_SESSION['user_id']]);$stock->execute([$qty,$item['ItemID'],$qty]);if(!$stock->rowCount())throw new RuntimeException('Stock changed while dispensing. Please retry.');}
                $stmt=$pdo->prepare("UPDATE Prescriptions SET Status='Dispensed',DispensedAt=NOW(),DispensedBy=?,PharmacySaleReference=?,TotalAmount=?,AmountPaid=?,DueBalance=? WHERE PrescriptionID LIKE ?");$stmt->execute([$_SESSION['user_id'],$saleBase,$total,$amountPaid,max(0,$total-$amountPaid),$base.'-%']);
                $patientId=(int)$lines[0]['PatientID'];$payRef=tdc_workflow_record_payment($pdo,$patientId,'Pharmacy',$amountPaid,(int)$_SESSION['user_id'],['PrescriptionReference'=>$base,'PaymentMethod'=>$paymentMethod]);if($payRef)tdc_workflow_post_revenue($pdo,'REV-PHARM','Pharmacy Revenue',$payRef,'Dispensing payment for '.$base,$amountPaid);
                $stmt=$pdo->prepare('SELECT UserID FROM Doctors WHERE DoctorID=?');$stmt->execute([$lines[0]['DoctorID']]);tdc_workflow_notify($pdo,(int)$stmt->fetchColumn(),'doctoruser','prescription_dispensed','Prescription dispensed',$base.' was dispensed as '.$saleBase,'doctors.php?visit='.(int)$lines[0]['VisitID']);
                $pdo->commit();tdc_redirect('prescriptions','success');
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e instanceof RuntimeException?$e->getMessage():'The prescription could not be dispensed.';}

        // --- Point of Sale ------------------------------------------
        } elseif ($section === 'pos') {
            tdc_require_permission('pharmacy.pos');
            if ($formAction === 'void') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['SaleRef'] ?? ''));
                if ($base === '') {
                    $errors[] = 'Invalid sale selected.';
                } else {
                    try {
                        $voidErrors = tdc_void_sale($pdo, $base);
                        if (empty($voidErrors)) {
                            tdc_redirect('pos', 'voided');
                        }
                        $errors = $voidErrors;
                    } catch (Throwable $e) {
                        error_log('[PHARMACY][POS] void failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while voiding the sale. Please try again.';
                    }
                }
            } else {
                $oldSale['CustomerName']  = trim((string) ($_POST['CustomerName'] ?? ''));
                $oldSale['CustomerPhone'] = trim((string) ($_POST['CustomerPhone'] ?? ''));
                $oldSale['AmountPaid']    = trim((string) ($_POST['AmountPaid'] ?? ''));
                $oldSale['ItemID']        = $_POST['ItemID'] ?? [];
                $oldSale['Quantity']      = $_POST['Quantity'] ?? [];
                $oldSale['UnitPrice']     = $_POST['UnitPrice'] ?? [];

                $stockByItemId = tdc_fetch_stock_map($pdo, $oldSale['ItemID']);
                $errors        = tdc_validate_pos_form($oldSale, $stockByItemId);
                $posShowForm   = true;

                if (empty($errors)) {
                    try {
                        $base = tdc_save_sale($pdo, $oldSale, $stockByItemId);
                        header('Location: pharmacy.php?section=pos&view=' . urlencode($base) . '&success=1');
                        exit;
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    } catch (Throwable $e) {
                        error_log('[PHARMACY][POS] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while completing the sale. Please try again.';
                    }
                }
            }

        // --- Purchases -------------------------------------------------
        } elseif ($section === 'purchases') {
            tdc_require_permission('pharmacy.purchases.manage');
            if ($formAction === 'void') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['PORef'] ?? ''));
                if ($base === '') {
                    $errors[] = 'Invalid purchase order selected.';
                } else {
                    try {
                        $voidErrors = tdc_void_purchase($pdo, $base);
                        if (empty($voidErrors)) {
                            tdc_redirect('purchases', 'voided');
                        }
                        $errors = $voidErrors;
                    } catch (Throwable $e) {
                        error_log('[PHARMACY][PURCHASES] void failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while voiding the purchase order. Please try again.';
                    }
                }
            } else {
                $oldPurchase['SupplierName']     = trim((string) ($_POST['SupplierName'] ?? ''));
                $oldPurchase['SupplierPhone']    = trim((string) ($_POST['SupplierPhone'] ?? ''));
                $oldPurchase['AmountPaid']       = trim((string) ($_POST['AmountPaid'] ?? ''));
                $oldPurchase['PurchaseDate']     = trim((string) ($_POST['PurchaseDate'] ?? ''));
                $oldPurchase['ReferenceNumber']  = trim((string) ($_POST['ReferenceNumber'] ?? ''));
                $oldPurchase['Discount']         = trim((string) ($_POST['Discount'] ?? ''));
                $oldPurchase['VATAmount']        = trim((string) ($_POST['VATAmount'] ?? ''));
                $oldPurchase['ItemID']           = $_POST['ItemID'] ?? [];
                $oldPurchase['ItemName']         = $_POST['ItemName'] ?? [];
                $oldPurchase['Category']         = $_POST['Category'] ?? [];
                $oldPurchase['PurchaseUnit']     = $_POST['PurchaseUnit'] ?? [];
                $oldPurchase['ConversionFactor'] = $_POST['ConversionFactor'] ?? [];
                $oldPurchase['SalesUnit']        = $_POST['SalesUnit'] ?? [];
                $oldPurchase['Quantity']         = $_POST['Quantity'] ?? [];
                $oldPurchase['UnitPrice']        = $_POST['UnitPrice'] ?? [];
                $oldPurchase['SellingPrice']     = $_POST['SellingPrice'] ?? [];
                $oldPurchase['ExpiryDate']       = $_POST['ExpiryDate'] ?? [];

                // A purchase replenishes existing stock: every line must reference a real inventory medicine.
                $purchaseIdMap = [];
                $purchaseIds   = array_values(array_filter(array_map('strval', $oldPurchase['ItemID']), static fn ($v) => trim($v) !== ''));
                if ($purchaseIds) {
                    $place  = implode(',', array_fill(0, count($purchaseIds), '?'));
                    $lookup = $pdo->prepare("SELECT ItemID, ItemName, Category, SalesUnit FROM Inventory WHERE ItemID IN ($place)");
                    $lookup->execute($purchaseIds);
                    foreach ($lookup->fetchAll() as $inventoryRow) {
                        $purchaseIdMap[(string) $inventoryRow['ItemID']] = $inventoryRow;
                    }
                }
                foreach ($oldPurchase['ItemName'] as $i => $itemName) {
                    if (trim((string) $itemName) === '') {
                        continue;
                    }
                    $lineItemId = trim((string) ($oldPurchase['ItemID'][$i] ?? ''));
                    if ($lineItemId === '' || !isset($purchaseIdMap[$lineItemId])) {
                        $errors[] = 'Line ' . ($i + 1) . ': select an existing medicine from the inventory list.';
                        continue;
                    }
                    $oldPurchase['ItemName'][$i] = (string) $purchaseIdMap[$lineItemId]['ItemName'];
                    if (trim((string) ($oldPurchase['Category'][$i] ?? '')) === '') {
                        $oldPurchase['Category'][$i] = (string) ($purchaseIdMap[$lineItemId]['Category'] ?? '');
                    }
                    if (trim((string) ($oldPurchase['SalesUnit'][$i] ?? '')) === '') {
                        $oldPurchase['SalesUnit'][$i] = (string) ($purchaseIdMap[$lineItemId]['SalesUnit'] ?? '');
                    }
                }

                // Reuse catalog units and the latest received pack configuration.
                foreach ($oldPurchase['ItemName'] as $i => $itemName) {
                    $unitStmt = $pdo->prepare('SELECT i.SalesUnit,i.Category,p.PurchaseUnit,p.ConversionFactor FROM Inventory i LEFT JOIN Purchases p ON p.PurchaseID=(SELECT p2.PurchaseID FROM Purchases p2 WHERE LOWER(TRIM(p2.ItemName))=LOWER(TRIM(i.ItemName)) ORDER BY p2.PurchaseDate DESC,p2.PurchaseID DESC LIMIT 1) WHERE LOWER(TRIM(i.ItemName))=LOWER(TRIM(?)) LIMIT 1');
                    $unitStmt->execute([(string)$itemName]);
                    $unitDefaults = $unitStmt->fetch() ?: [];
                    foreach (['SalesUnit','Category','PurchaseUnit','ConversionFactor'] as $field) {
                        if (trim((string)($oldPurchase[$field][$i] ?? '')) === '') $oldPurchase[$field][$i] = (string)($unitDefaults[$field] ?? ($field === 'ConversionFactor' ? '1' : ''));
                    }
                }
                $errors            = array_merge($errors, tdc_validate_purchase_form($oldPurchase));
                $purchaseShowForm  = true;

                if (empty($errors)) {
                    try {
                        $base = tdc_save_purchase($pdo, $oldPurchase);
                        header('Location: pharmacy.php?section=purchases&view=' . urlencode($base) . '&success=1');
                        exit;
                    } catch (Throwable $e) {
                        error_log('[PHARMACY][PURCHASES] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the purchase order. Please try again.';
                    }
                }
            }

        // --- Inventory ---------------------------------------------------
        } elseif ($section === 'inventory') {
            tdc_require_permission('pharmacy.inventory.manage');
            if ($formAction === 'delete') {
                $deleteId = trim((string) ($_POST['ItemID'] ?? ''));
                $errors   = $deleteId !== '' ? tdc_delete_inventory_item($pdo, $deleteId) : ['Invalid item selected.'];
                if (empty($errors)) {
                    tdc_redirect('inventory', 'deleted');
                }
            } else {
                $oldInventory['ItemID']          = trim((string) ($_POST['ItemID'] ?? ''));
                $oldInventory['ItemName']        = trim((string) ($_POST['ItemName'] ?? ''));
                $oldInventory['Category']        = trim((string) ($_POST['Category'] ?? ''));
                $oldInventory['QuantityInStock'] = trim((string) ($_POST['QuantityInStock'] ?? ''));
                $oldInventory['SalesUnit']       = trim((string) ($_POST['SalesUnit'] ?? ''));
                $oldInventory['SellingPrice']    = trim((string) ($_POST['SellingPrice'] ?? ''));
                $oldInventory['ReorderLevel']    = trim((string) ($_POST['ReorderLevel'] ?? ''));
                $oldInventory['ExpiryDate']      = trim((string) ($_POST['ExpiryDate'] ?? ''));
                $oldInventory['DefaultPurchaseUnit'] = trim((string) ($_POST['DefaultPurchaseUnit'] ?? ''));
                $oldInventory['UnitsPerPackage']     = trim((string) ($_POST['UnitsPerPackage'] ?? ''));

                $isEdit = $oldInventory['ItemID'] !== '';
                $errors = tdc_validate_inventory_form($oldInventory);

                if (empty($errors)) {
                    try {
                        tdc_save_inventory_item($pdo, $oldInventory, $isEdit, $oldInventory['ItemID']);
                        tdc_redirect('inventory', 'success');
                    } catch (PDOException $e) {
                        error_log('[PHARMACY][INVENTORY] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the item. Please try again.';
                    }
                }
            }
        }
    }

    // Rotate CSRF token after every POST (success paths already rotated + exited above via header()).
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =======================================================================
// SECTION 10 — GET data loading for display
// =======================================================================

// --- 10A. POS ---------------------------------------------------------
$inventoryForCombo = [];
$saleSearch         = '';
$pharmacySales      = [];
$viewSaleRef        = '';
$viewSaleLines      = [];

if ($section === 'pos') {
    $inventoryForCombo = $pdo->query(
        'SELECT ItemID, ItemName, SellingPrice, QuantityInStock, SalesUnit FROM Inventory
         WHERE QuantityInStock > 0 ORDER BY ItemName ASC LIMIT 500'
    )->fetchAll();

    $saleSearch = trim((string) ($_GET['q'] ?? ''));
    $params     = [];
    $where      = '';
    if ($saleSearch !== '') {
        $where  = 'WHERE CustomerName LIKE :q1 OR CustomerPhone LIKE :q2 OR SaleID LIKE :q3';
        $params = ['q1' => '%' . $saleSearch . '%', 'q2' => '%' . $saleSearch . '%', 'q3' => '%' . $saleSearch . '%'];
    }
    $stmt = $pdo->prepare(
        "SELECT SUBSTRING_INDEX(SaleID, '-', 1) AS SaleRef, MIN(CustomerName) AS CustomerName,
                MIN(CustomerPhone) AS CustomerPhone, COUNT(*) AS LineCount,
                MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                MIN(DueBalance) AS DueBalance, MIN(PaymentStatus) AS PaymentStatus, MIN(SaleDate) AS SaleDate
         FROM PharmacySales {$where}
         GROUP BY SaleRef ORDER BY SaleDate DESC LIMIT 200"
    );
    $stmt->execute($params);
    $pharmacySales = $stmt->fetchAll();

    if (empty($errors)) {
        if (isset($_GET['view'])) {
            $viewSaleRef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
            $stmt = $pdo->prepare('SELECT * FROM PharmacySales WHERE SaleID LIKE :pattern ORDER BY SaleID ASC');
            $stmt->execute(['pattern' => $viewSaleRef . '-%']);
            $viewSaleLines = $stmt->fetchAll();
            if (empty($viewSaleLines)) {
                $viewSaleRef = ''; // Unknown / stale ref — fall back to the list.
            }
        } elseif (isset($_GET['new'])) {
            $posShowForm = true;
        }
    }
}

$pendingPrescriptions=[];
if($section==='prescriptions'){
    $pendingPrescriptions=$pdo->query("SELECT SUBSTRING_INDEX(pr.PrescriptionID,'-',1) PrescriptionReference,MIN(pr.PatientName) PatientName,MIN(pr.PatientPhone) PatientPhone,MIN(d.DoctorName) DoctorName,COUNT(*) ItemCount,MIN(pr.PrescriptionDate) PrescriptionDate FROM Prescriptions pr JOIN Doctors d ON d.DoctorID=pr.DoctorID WHERE pr.Status='Pending' GROUP BY PrescriptionReference ORDER BY PrescriptionDate")->fetchAll();
}

// --- 10B. Purchases -----------------------------------------------------
$itemNamesForDatalist = [];
$purchaseSearch       = '';
$purchaseOrders       = [];
$viewPORef            = '';
$viewPOLines          = [];

if ($section === 'purchases') {
    $purchaseUnitOptions = $pdo->query('SELECT ItemID, ItemName, Category, SalesUnit, SellingPrice, QuantityInStock, DefaultPurchaseUnit, UnitsPerPackage FROM Inventory ORDER BY ItemName')->fetchAll();
    foreach ($purchaseUnitOptions as $key => $row) {
        $purchaseUnitOptions[$key]['SalesUnit']          = tdc_norm_unit($row['SalesUnit'] ?? null);
        $purchaseUnitOptions[$key]['DefaultPurchaseUnit'] = tdc_norm_unit($row['DefaultPurchaseUnit'] ?? null);
    }
    $itemNamesForDatalist = $pdo->query('SELECT DISTINCT ItemName FROM Inventory ORDER BY ItemName ASC LIMIT 500')
        ->fetchAll(PDO::FETCH_COLUMN);

    $purchaseSearch = trim((string) ($_GET['q'] ?? ''));
    $purchaseStatus = $canViewPurchaseCost ? trim((string) ($_GET['status'] ?? '')) : '';
    if (!in_array($purchaseStatus, ['', 'paid', 'due'], true)) {
        $purchaseStatus = '';
    }
    [$purchaseFrom, $purchaseTo, $purchaseDateError, $purchaseDateActive] = tdc_date_range_resolve();

    $purchasePage = max(1, (int) ($_GET['page'] ?? 1));
    $purchasePerPage = (int) ($_GET['per_page'] ?? 25);
    if (!in_array($purchasePerPage, [10, 25, 50, 100], true)) {
        $purchasePerPage = 25;
    }

    $params = [];
    $where  = '';
    if ($purchaseSearch !== '') {
        $where .= ($where === '' ? 'WHERE ' : ' AND ')
            . '(SupplierName LIKE :q1 OR PurchaseID LIKE :q2 OR ReferenceNumber LIKE :q3)';
        $params['q1'] = '%' . $purchaseSearch . '%';
        $params['q2'] = '%' . $purchaseSearch . '%';
        $params['q3'] = '%' . $purchaseSearch . '%';
    }
    if ($purchaseFrom !== '' && tdc_ui_is_date($purchaseFrom)) {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'PurchaseDate >= :fromDate';
        $params['fromDate'] = $purchaseFrom . ' 00:00:00';
    }
    if ($purchaseTo !== '' && tdc_ui_is_date($purchaseTo)) {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'PurchaseDate <= :toDate';
        $params['toDate'] = $purchaseTo . ' 23:59:59';
    }
    if ($purchaseStatus === 'paid') {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'DueBalance <= 0';
    } elseif ($purchaseStatus === 'due') {
        $where .= ($where === '' ? 'WHERE ' : ' AND ') . 'DueBalance > 0';
    }

    $purchaseExportUrl = 'pharmacy.php?' . http_build_query(array_filter([
        'section' => 'purchases',
        'q' => $purchaseSearch,
        'status' => $purchaseStatus,
        'from_date' => $purchaseFrom,
        'to_date' => $purchaseTo,
        'export' => 'csv',
    ], static fn($value): bool => $value !== '' && $value !== null));

    $purchaseSelectSql = "SELECT SUBSTRING_INDEX(PurchaseID, '-', 1) AS PORef, MIN(SupplierName) AS SupplierName,
                MIN(SupplierPhone) AS SupplierPhone, MIN(ReferenceNumber) AS ReferenceNumber, COUNT(*) AS LineCount,
                MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                MIN(DueBalance) AS DueBalance, MIN(PurchaseDate) AS PurchaseDate
         FROM Purchases {$where}
         GROUP BY PORef";

    if (!$canViewPurchaseCost) {
        $purchaseSelectSql = "SELECT SUBSTRING_INDEX(PurchaseID, '-', 1) AS PORef, MIN(SupplierName) AS SupplierName,
            MIN(SupplierPhone) AS SupplierPhone, MIN(ReferenceNumber) AS ReferenceNumber, COUNT(*) AS LineCount,
            MIN(PurchaseDate) AS PurchaseDate FROM Purchases {$where} GROUP BY PORef";
    }
    if (($_GET['export'] ?? '') === 'csv' && empty($purchaseDateError)) {
        if (!$canViewPurchaseCost) {
            $stmt = $pdo->prepare($purchaseSelectSql . ' ORDER BY PurchaseDate DESC');
            $stmt->execute($params);
            tdc_csv_download('purchase-orders.csv', ['PO Ref','Supplier','Phone','Reference','Items','Date'], $stmt->fetchAll(PDO::FETCH_NUM));
        }
        tdc_require_permission('pharmacy.purchases.manage');
        $exportStmt = $pdo->prepare($purchaseSelectSql . ' ORDER BY PurchaseDate DESC');
        $exportStmt->execute($params);
        $exportRows = [];
        foreach ($exportStmt->fetchAll() as $po) {
            $exportRows[] = [
                $po['PORef'],
                (string) ($po['ReferenceNumber'] ?? ''),
                $po['SupplierName'],
                (string) $po['SupplierPhone'],
                (int) $po['LineCount'],
                number_format((float) $po['TotalAmount'], 2, '.', ''),
                number_format((float) $po['AmountPaid'], 2, '.', ''),
                number_format((float) $po['DueBalance'], 2, '.', ''),
                (float) $po['DueBalance'] > 0 ? 'Due' : 'Paid',
                date('Y-m-d', strtotime((string) $po['PurchaseDate'])),
            ];
        }
        tdc_csv_download('purchase-orders-' . date('Y-m-d') . '.csv', ['PO Ref', 'Reference', 'Supplier', 'Phone', 'Items', 'Total', 'Paid', 'Due', 'Status', 'Purchase Date'], $exportRows);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT SUBSTRING_INDEX(PurchaseID, \'-\', 1) AS PORef FROM Purchases ' . $where . ' GROUP BY PORef) AS grouped');
    $countStmt->execute($params);
    $purchaseTotal = (int) $countStmt->fetchColumn();

    $purchaseOffset = ($purchasePage - 1) * $purchasePerPage;
    $stmt = $pdo->prepare($purchaseSelectSql . ' ORDER BY PurchaseDate DESC LIMIT ' . (int) $purchasePerPage . ' OFFSET ' . (int) $purchaseOffset);
    $stmt->execute($params);
    $purchaseOrders = $stmt->fetchAll();

    if (empty($errors)) {
        if (isset($_GET['view'])) {
            $viewPORef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
            $stmt = $pdo->prepare('SELECT ' . ($canViewPurchaseCost ? '*' : 'PurchaseID,SupplierName,SupplierPhone,ReferenceNumber,ItemName,Category,Quantity,PurchaseUnit,SalesUnit,SellingPrice,PurchaseDate') . ' FROM Purchases WHERE PurchaseID LIKE :pattern ORDER BY PurchaseID ASC');
            $stmt->execute(['pattern' => $viewPORef . '-%']);
            $viewPOLines = $stmt->fetchAll();
            if (empty($viewPOLines)) {
                $viewPORef = ''; // Unknown / stale ref — fall back to the list.
            }
        } elseif (isset($_GET['new'])) {
            $purchaseShowForm = true;
        }
    }
}

// --- 10C. Inventory -------------------------------------------------------
$inventorySearch = '';
$lowStockOnly    = false;
$inventoryItems  = [];

if ($section === 'inventory') {
    $inventorySearch = trim((string) ($_GET['q'] ?? ''));
    $lowStockOnly    = isset($_GET['low']);

    $conditions = [];
    $params     = [];
    if ($inventorySearch !== '') {
        $conditions[] = '(ItemName LIKE :q1 OR Category LIKE :q2)';
        $params['q1'] = $params['q2']  = '%' . $inventorySearch . '%';
    }
    if ($lowStockOnly) {
        $conditions[] = 'QuantityInStock <= ReorderLevel';
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("SELECT * FROM Inventory {$where} ORDER BY ItemName ASC LIMIT 500");
    $stmt->execute($params);
    $inventoryItems = $stmt->fetchAll();
    foreach ($inventoryItems as $key => $item) {
        $inventoryItems[$key]['SalesUnit'] = tdc_norm_unit($item['SalesUnit'] ?? null);
    }
}

// --- 10D. Hub summary (only computed on the landing page) -----------------
$hubLowStockCount = 0;
$hubTodaySales    = 0;
$hubOpenPOCount   = 0;

if ($section === null) {
    $hubTodaySales    = (int) tdc_scalar($pdo, "SELECT COUNT(DISTINCT SUBSTRING_INDEX(SaleID, '-', 1)) FROM PharmacySales WHERE DATE(SaleDate) = CURDATE()");
    if (tdc_can('pharmacy.inventory.view')) {
        $hubLowStockCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM Inventory WHERE QuantityInStock <= ReorderLevel');
        $hubOpenPOCount = $canViewPurchaseCost ? (int) tdc_scalar($pdo, "SELECT COUNT(DISTINCT SUBSTRING_INDEX(PurchaseID, '-', 1)) FROM Purchases WHERE DueBalance > 0") : 0;
    }
}

// =======================================================================
// SECTION 11 — View data
// =======================================================================
$legalName     = (string) ($_SESSION['userlegalname'] ?? 'User');
$displayName   = tdc_display_name($legalName);
$avatarLetters = strtoupper(substr($displayName, 0, 2));
$csrfToken     = (string) ($_SESSION['csrf_token'] ?? '');
$currentPage   = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'pharmacy.php'));

$justSaved   = isset($_GET['success']);
$justDeleted = isset($_GET['deleted']);
$justVoided  = isset($_GET['voided']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tarey Derma Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap" rel="stylesheet">
<style>
    :root{
        --navy: #2E3192;
        --navy-30: rgba(46,49,146,0.3);
        --navy-10: rgba(46,49,146,0.08);
        --navy-55: rgba(46,49,146,0.55);
        --orange: #F15A24;
        --white: #ffffff;
        --border: 2px solid var(--navy);
        --on-navy-70: rgba(255,255,255,0.7);
    }
    *, *::before, *::after{ box-sizing:border-box; margin:0; padding:0; }
    body{ font-family:'Google Sans', sans-serif; background:var(--white); color:var(--navy); min-height:100vh; }
    .app-header{ position:relative; z-index:100; }
    .nav-item{ position:relative; flex-shrink:0; }
    .utility-bar{ display:flex; align-items:center; justify-content:space-between; background:var(--navy); padding:8px 24px; }
    .brand-chip{ background:var(--white); display:flex; align-items:center; padding:5px 14px; flex-shrink:0; }
    .brand-chip img{ height:30px; width:auto; object-fit:contain; display:block; }
    .utility-right{ display:flex; align-items:center; gap:2px; }
    .icon-btn{ appearance:none; background:none; border:2px solid transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; width:38px; height:38px; position:relative; color:var(--on-navy-70); transition:color 0.12s; }
    .icon-btn:hover{ color:var(--white); }
    .icon-btn svg{ width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .icon-btn .badge{ position:absolute; top:6px; right:7px; width:7px; height:7px; background:var(--orange); border:2px solid var(--navy); }
    .nav-item.open > .icon-btn{ color:var(--orange); }
    .profile-static{ display:flex; align-items:center; gap:9px; padding:6px 8px; font-family:'Google Sans', sans-serif; color:var(--white); }
    .avatar{ width:30px; height:30px; flex-shrink:0; background:var(--white); color:var(--navy); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; letter-spacing:0.02em; }
    .profile-name{ font-size:13.5px; font-weight:600; }
    .menu-bar{ background:var(--white); padding:0 24px; display:flex; justify-content:safe center; overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; scrollbar-color:var(--navy-30) transparent; }
    .menu-bar::-webkit-scrollbar{ height:4px; }
    .menu-bar::-webkit-scrollbar-track{ background:transparent; }
    .menu-bar::-webkit-scrollbar-thumb{ background:var(--navy-30); border-radius:2px; }
    .menu-bar::-webkit-scrollbar-thumb:hover{ background:var(--navy-55); }
    .nav-items{ list-style:none; display:flex; align-items:center; gap:4px; flex-wrap:nowrap; flex-shrink:0; }
    .nav-link{ appearance:none; background:none; border:none; cursor:pointer; display:flex; align-items:center; gap:7px; font-family:'Google Sans', sans-serif; font-size:13.5px; font-weight:600; letter-spacing:0.01em; color:var(--navy); text-decoration:none; padding:12px; white-space:nowrap; flex-shrink:0; transition:color 0.12s; }
    .nav-link svg{ width:16px; height:16px; fill:var(--navy-55); flex-shrink:0; transition:fill 0.12s; }
    .nav-link:hover{ color:var(--orange); }
    .nav-link:hover svg{ fill:var(--orange); }
    .nav-item.active > .nav-link{ color:var(--navy); box-shadow:inset 0 -2px 0 var(--orange); }
    .nav-item.active > .nav-link svg{ fill:var(--navy); }
    .dropdown-menu{ position:absolute; top:calc(100% + 6px); left:0; min-width:220px; background:var(--white); border:var(--border); display:none; flex-direction:column; padding:6px 0; }
    .nav-item.open > .dropdown-menu{ display:flex; }
    .dropdown-menu a{ display:block; text-decoration:none; color:var(--navy); font-size:13.5px; font-weight:500; padding:9px 16px; transition:background 0.12s, color 0.12s; }
    .dropdown-menu a:hover{ background:var(--navy-10); color:var(--orange); }
    .notif-menu{ right:0; left:auto; min-width:260px; }
    .notif-menu .notif-title{ padding:10px 16px 8px; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:var(--navy-55); }
    .notif-empty{ padding:20px 16px 22px; font-size:13px; color:var(--navy-55); text-align:center; }
    .page-body{ padding:40px 32px; }
    .welcome-eyebrow{ font-size:11px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:var(--navy-55); margin-bottom:8px; }
    .welcome-title{ font-size:26px; font-weight:700; color:var(--navy); }
    .welcome-sub{ font-size:14px; color:var(--navy-55); margin-top:6px; margin-bottom:28px; }

    .error-msg{ display:flex; flex-direction:column; gap:4px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:14px 16px; margin-bottom:24px; max-width:1200px; }
    .error-msg .error-title{ display:flex; align-items:center; gap:8px; font-weight:700; }
    .error-msg svg{ width:16px; height:16px; flex-shrink:0; }
    .error-msg ul{ list-style:none; padding-left:24px; }
    .error-msg li::before{ content:"— "; }






    .form-row{ display:flex; gap:16px; flex-wrap:wrap; }
    .form-row .form-group{ flex:1; min-width:180px; }
    .checkbox-row{ display:flex; align-items:center; gap:8px; }
    .checkbox-row input{ width:auto; }





    .setup-grid{ display:grid; grid-template-columns:repeat(3, minmax(220px,1fr)); gap:20px; max-width:920px; }
    .setup-card{ display:flex; align-items:flex-start; gap:14px; padding:20px; border:2px solid var(--navy); text-decoration:none; color:var(--navy); transition:background 0.12s, border-color 0.12s; }
    .setup-card:hover{ background:var(--navy-10); border-color:var(--orange); }
    .setup-card .setup-icon{ width:32px; height:32px; flex-shrink:0; color:var(--navy-55); }
    .setup-card .setup-icon svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; }
    .setup-card-title{ font-size:14.5px; font-weight:700; color:var(--navy); margin-bottom:4px; }
    .setup-card-desc{ font-size:12.5px; color:var(--navy-55); }

    .back-link{ display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--navy-55); text-decoration:none; margin-bottom:16px; }
    .back-link:hover{ color:var(--orange); }

    .section-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1200px; margin-bottom:16px; flex-wrap:wrap; }
    .filter-box{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .filter-box input{ padding:10px 12px; border:2px solid rgba(46,49,146,0.3); font-size:13.5px; font-family:'Google Sans',sans-serif; color:var(--navy); min-width:220px; }
    .filter-box input:focus{ outline:none; border-color:var(--orange); }
    .filter-box label{ font-size:12.5px; font-weight:600; color:var(--navy-55); display:flex; align-items:center; gap:6px; white-space:nowrap; }






    .empty-row td{ text-align:center; padding:28px; color:var(--navy-55); }

    .status-badge{ display:inline-block; padding:3px 9px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; border:1.5px solid var(--navy); color:var(--navy); white-space:nowrap; }
    .status-badge.warn{ border-color:var(--orange); color:var(--orange); }
    .status-badge.danger{ border-color:#c0392b; color:#c0392b; }

    .row-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .row-actions form{ display:inline; }




    .combo{ position:relative; }
    .combo-list{ position:absolute; top:calc(100% + 4px); left:0; right:0; max-height:220px; overflow-y:auto; background:var(--white); border:2px solid var(--navy); list-style:none; z-index:50; }
    .combo-list li{ padding:9px 12px; font-size:13px; cursor:pointer; }
    .combo-list li:hover, .combo-list li.active{ background:var(--navy-10); color:var(--orange); }
    .combo-empty{ padding:9px 12px; font-size:12.5px; color:var(--navy-55); }

    .line-items-wrap{ max-width:1200px; border:2px solid var(--navy); overflow-x:auto; margin-bottom:12px; }
    .line-items{ width:100%; border-collapse:collapse; min-width:960px; }
    .line-items th, .line-items td{ padding:8px 10px; border-bottom:1px solid var(--navy-30); vertical-align:top; }
    .line-items th{ background:var(--navy-10); font-size:11px; text-transform:uppercase; letter-spacing:.04em; text-align:left; }
    .line-items input{ width:100%; padding:7px 8px; border:1.5px solid rgba(46,49,146,0.3); font-size:13px; font-family:'Google Sans',sans-serif; color:var(--navy); }
    .line-items input:focus{ outline:none; border-color:var(--orange); }
    .remove-line-btn{ background:none; border:none; color:#c0392b; cursor:pointer; font-size:20px; line-height:1; padding:4px; }
    .purchase-qty-hint, .purchase-cost-hint, .purchase-selling-hint{ display:block; margin-top:4px; font-size:11.5px; color:var(--navy-55); }
    .col-medicine{ min-width:220px; width:26%; }
    .col-actions{ width:56px; min-width:56px; text-align:center; }
    .purchase-pkg-unavailable{ display:block; margin-top:6px; font-size:11.5px; color:var(--navy-55); }
    .purchase-pack-hint{ margin-top:6px; font-size:12px; color:var(--navy-55); }
    .add-line-btn{ margin-bottom:8px; }
    .totals-row{ display:flex; gap:20px; flex-wrap:wrap; max-width:1200px; margin-bottom:12px; }
    .totals-row .form-group{ min-width:180px; flex:0 1 200px; }
    .due-display{ font-weight:700; font-size:15px; color:var(--navy); padding:11px 0; }
    .form-actions{ display:flex; gap:10px; max-width:1200px; }

    .info-grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:18px 24px; max-width:1200px; margin-bottom:32px; padding:24px; border:2px solid var(--navy); }
    .info-field .info-label{ font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:var(--navy-55); margin-bottom:4px; }
    .info-field .info-value{ font-size:14.5px; font-weight:600; color:var(--navy); word-break:break-word; }
    .subsection-title{ font-size:16px; font-weight:700; color:var(--navy); margin-bottom:12px; }


    #js-toast{ position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(20px); display:flex; align-items:center; gap:8px; background:var(--white); border:2px solid var(--navy); color:var(--navy); font-size:13px; font-weight:500; padding:10px 18px; white-space:nowrap; z-index:9999; opacity:0; pointer-events:none; transition:opacity 0.2s ease, transform 0.2s ease; }
    #js-toast svg{ width:16px; height:16px; flex-shrink:0; }
    #js-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

    .logout-fab{ position:fixed; bottom:20px; right:20px; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:var(--navy); border:2px solid var(--white); cursor:pointer; text-decoration:none; z-index:9999; box-shadow:0 2px 6px rgba(46,49,146,0.35); transition:transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease; }
    .logout-fab svg{ width:18px; height:18px; stroke:var(--white); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.15s ease; }
    .logout-fab:hover{ background:var(--orange); transform:scale(1.08); box-shadow:0 4px 10px rgba(241,90,36,0.4); }
    .logout-fab:active{ transform:scale(0.96); }
    .logout-fab:focus-visible{ outline:2px solid var(--orange); outline-offset:3px; }
    .logout-fab::after{ content:'Log Out'; position:absolute; bottom:calc(100% + 8px); right:0; background:var(--navy); color:var(--white); font-family:'Google Sans', sans-serif; font-size:12px; font-weight:600; padding:6px 10px; white-space:nowrap; opacity:0; visibility:hidden; transform:translateY(4px); transition:opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease; pointer-events:none; }
    .logout-fab:hover::after, .logout-fab:focus-visible::after{ opacity:1; visibility:visible; transform:translateY(0); }

    @media print{
        .app-header, .logout-fab, #js-toast, .no-print{ display:none !important; }
        .page-body{ padding:0; }
        .info-grid, .data-table-wrap{ max-width:100%; }
    }
</style>
<link rel="stylesheet" href="../assets/clinic.css">
<script src="../assets/clinic.js" defer></script>
</head>
<body>

<header class="app-header" id="topnav">
    <div class="utility-bar">
        <div class="brand-chip">
            <img src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic Logo">
        </div>
        <div class="utility-right">
            <div class="nav-item" data-menu="notifications">
                <button type="button" class="icon-btn" aria-label="Notifications">
                    <svg viewBox="0 0 24 24"><path d="M18 16v-5a6 6 0 10-12 0v5l-2 2v1h16v-1l-2-2z"/><path d="M9.5 21a2.5 2.5 0 005 0"/></svg>
                    <span class="badge"></span>
                </button>
                <div class="dropdown-menu notif-menu">
                    <?php require __DIR__ . '/../includes/notifications.php'; ?>
                </div>
            </div>
            <?php require __DIR__ . '/../includes/profile.php'; ?>
        </div>
    </div>

    <nav class="menu-bar">
        <ul class="nav-items">
            <?php foreach (tdc_navigation(NAV_ITEMS) as $item): ?>
                <li class="nav-item<?= $item['href'] === $currentPage ? ' active' : '' ?>">
                    <a href="<?= tdc_e($item['href']) ?>" class="nav-link">
                        <?= tdc_navigation_icon($item['href']) ?>
                        <span><?= tdc_e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <a href="?logout=1&csrf=<?= urlencode($csrfToken) ?>" class="logout-fab" aria-label="Log Out">
        <svg viewBox="0 0 20 20"><path d="M8 3H5a2 2 0 00-2 2v10a2 2 0 002 2h3"/><path d="M13 6l4 4-4 4"/><path d="M7 10h10"/></svg>
    </a>
</header>

<main class="page-body">

<nav class="setup-section-nav" aria-label="Pharmacy sections">
    <?php if (tdc_can('pharmacy.prescriptions.view')): ?><a href="pharmacy.php?section=prescriptions"<?= $section === 'prescriptions' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('pill', 16) ?><span>Prescriptions</span></a><?php endif; ?>
    <?php if (tdc_can('pharmacy.pos')): ?><a href="pharmacy.php?section=pos"<?= $section === 'pos' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('wallet', 16) ?><span>Point of Sale</span></a><?php endif; ?>
    <?php if (tdc_can('pharmacy.purchases.manage')): ?><a href="pharmacy.php?section=purchases"<?= $section === 'purchases' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('inbox', 16) ?><span>Purchases</span></a><?php endif; ?>
    <?php if (tdc_can('pharmacy.inventory.view')): ?><a href="pharmacy.php?section=inventory"<?= $section === 'inventory' ? ' class="active" aria-current="page"' : '' ?>><?= tdc_icon('inbox', 16) ?><span>Inventory</span></a><?php endif; ?>
</nav>


<?php if ($section === null): ?>

    <div class="welcome-eyebrow">Pharmacy</div>
    <div class="welcome-title">Pharmacy Operations</div>

    <div class="setup-grid">
        <?php if(tdc_can('pharmacy.prescriptions.view')):?><a href="pharmacy.php?section=prescriptions" class="setup-card"><div class="setup-icon"><svg viewBox="0 0 24 24"><path d="m10.5 20.5-7-7a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z"/><path d="m8 11 7 7"/></svg></div><div><div class="setup-card-title">Pending Prescriptions</div><div class="setup-card-desc">Doctor prescriptions ready for dispensing</div></div></a><?php endif;?>
        <?php if(tdc_can('pharmacy.pos')):?><a href="pharmacy.php?section=pos" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293A1 1 0 005.414 17H17"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg></div>
            <div>
                <div class="setup-card-title">Point of Sale</div>
                <div class="setup-card-desc"><?= $hubTodaySales ?> sale<?= $hubTodaySales === 1 ? '' : 's' ?> today</div>
            </div>
        </a><?php endif;?>
        <?php if (tdc_can('pharmacy.purchases.manage')): ?>
        <a href="pharmacy.php?section=purchases" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M20 7h-3V6a4 4 0 00-8 0v1H6a1 1 0 00-1 1v11a2 2 0 002 2h10a2 2 0 002-2V8a1 1 0 00-1-1zM9 6a3 3 0 016 0v1H9V6z"/></svg></div>
            <div>
                <div class="setup-card-title">Purchases</div>
                <div class="setup-card-desc"><?php if ($canViewPurchaseCost): ?><?= $hubOpenPOCount ?> order<?= $hubOpenPOCount === 1 ? '' : 's' ?> with a balance due<?php else: ?>Received medicines and supplier references<?php endif; ?></div>
            </div>
        </a>
        <?php endif; ?><?php if (tdc_can('pharmacy.inventory.view')): ?><a href="pharmacy.php?section=inventory" class="setup-card">
            <div class="setup-icon"><svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7"/><path d="M12 11v10"/></svg></div>
            <div>
                <div class="setup-card-title">Inventory</div>
                <div class="setup-card-desc"><?= $hubLowStockCount ?> item<?= $hubLowStockCount === 1 ? '' : 's' ?> low on stock</div>
            </div>
        </a>
        <?php endif; ?>
    </div>

<?php else: ?>

    <a href="pharmacy.php" class="back-link no-print">&larr; Back to Pharmacy</a>

    <?php if (!empty($errors)): ?>
    <div class="error-msg">
        <div class="error-title">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-4.75a.75.75 0 001.5 0v-4.5a.75.75 0 00-1.5 0v4.5zm.75-7a.75.75 0 100 1.5.75.75 0 000-1.5z" clip-rule="evenodd"/></svg>
            Please fix the following:
        </div>
        <ul>
            <?php foreach ($errors as $err): ?><li><?= tdc_e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php // ============================================================
          // POINT OF SALE
          // ============================================================ ?>
    <?php if ($section === 'prescriptions'): ?>
        <div class="welcome-title">Pending Prescriptions</div><div class="welcome-sub">Dispense doctor orders directly from available inventory.</div>
        <div class="data-table-wrap" style="margin-top:24px"><table class="data-table"><thead><tr><th>Prescription</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Created</th><th>Dispense</th></tr></thead><tbody><?php if(!$pendingPrescriptions): ?><tr class="empty-row"><td colspan="6">No pending prescriptions.</td></tr><?php else:foreach($pendingPrescriptions as $rx): ?><tr><td><?= tdc_e($rx['PrescriptionReference']) ?></td><td><?= tdc_e($rx['PatientName']) ?><br><span class="cell-sub"><?= tdc_e($rx['PatientPhone']) ?></span></td><td><?= tdc_e($rx['DoctorName']) ?></td><td><?= (int)$rx['ItemCount'] ?></td><td><?= tdc_e(date('d M Y H:i',strtotime($rx['PrescriptionDate']))) ?></td><td><form method="post" class="row-actions"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="dispense"><input type="hidden" name="PrescriptionReference" value="<?= tdc_e($rx['PrescriptionReference']) ?>"><input class="compact-input" type="number" min="0" step=".01" name="AmountPaid" placeholder="Amount paid" required><select class="compact-input" name="PaymentMethod"><?php foreach($paymentMethods as $method):?><option><?=tdc_e($method['MethodName'])?></option><?php endforeach;?></select><button class="btn-success btn-sm">Dispense</button></form></td></tr><?php endforeach;endif; ?></tbody></table></div>

    <?php elseif ($section === 'pos'): ?>

        <?php if ($viewSaleRef !== ''): ?>
        <?php $head = $viewSaleLines[0]; ?>
        <div class="welcome-title">Sale Receipt — <?= tdc_e($viewSaleRef) ?></div>
        <div class="welcome-sub"><?= tdc_e(date('Y-m-d H:i', strtotime((string) $head['SaleDate']))) ?></div>

        <div class="info-grid">
            <div class="info-field"><div class="info-label">Customer</div><div class="info-value"><?= tdc_e($head['CustomerName'] ?: 'Walk-in') ?></div></div>
            <div class="info-field"><div class="info-label">Phone</div><div class="info-value"><?= tdc_e($head['CustomerPhone'] ?: '—') ?></div></div>
            <div class="info-field"><div class="info-label">Payment Status</div><div class="info-value"><span class="status-badge<?= $head['PaymentStatus'] === 'Unpaid' ? ' danger' : ($head['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($head['PaymentStatus']) ?></span></div></div>
            <div class="info-field"><div class="info-label">Total</div><div class="info-value"><?= number_format((float) $head['TotalAmount'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Paid</div><div class="info-value"><?= number_format((float) $head['AmountPaid'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Due</div><div class="info-value"><?= number_format((float) $head['DueBalance'], 2) ?></div></div>
        </div>

        <div class="subsection-title">Items</div>
        <div class="data-table-wrap" style="margin-bottom:24px;">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Quantity</th><th>Unit Price</th><th>Line Total</th></tr></thead>
                <tbody>
                    <?php foreach ($viewSaleLines as $l): ?>
                    <tr>
                        <td><?= tdc_e($l['ItemName']) ?></td>
                        <td><?= (int) $l['Quantity'] ?></td>
                        <td><?= number_format((float) $l['UnitPrice'], 2) ?></td>
                        <td><?= number_format((float) $l['LineTotal'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row-actions no-print">
            <button type="button" class="btn-info  btn " onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print Receipt</span></button>
            <form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this sale? Stock will be restored. This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="void">
                <input type="hidden" name="SaleRef" value="<?= tdc_e($viewSaleRef) ?>">
                <button type="submit" class="btn-danger btn-sm danger">Void Sale</button>
            </form>
        </div>

        <?php elseif ($posShowForm): ?>

        <div class="welcome-title">New Sale</div>
        <div class="welcome-sub">Search for items, set quantities, and take payment.</div>

        <form id="saleForm" method="POST" action="pharmacy.php?section=pos">
            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
            <input type="hidden" name="form_action" value="save">

            <div class="form-row" style="max-width:1200px;margin-bottom:16px;">
                <div class="form-group"><label for="sf_CustomerName">Customer Name (optional)</label>
                    <input type="text" id="sf_CustomerName" name="CustomerName" value="<?= tdc_e($oldSale['CustomerName']) ?>" placeholder="Walk-in"></div>
                <div class="form-group"><label for="sf_CustomerPhone">Customer Phone (optional)</label>
                    <input type="text" id="sf_CustomerPhone" name="CustomerPhone" value="<?= tdc_e($oldSale['CustomerPhone']) ?>"></div>
            </div>

            <div class="line-items-wrap">
                <table class="line-items" id="lineItemsTable">
                    <thead><tr><th style="width:40px;">#</th><th>Item</th><th style="width:110px;">Qty</th><th style="width:130px;">Unit Price</th><th style="width:130px;">Line Total</th><th style="width:36px;"></th></tr></thead>
                    <tbody id="lineItemsBody">
                    <?php
                    $saleLineCount = max(1, count($oldSale['ItemID']));
                    for ($i = 0; $i < $saleLineCount; $i++):
                        $itemId = $oldSale['ItemID'][$i] ?? '';
                        $label  = '';
                        foreach ($inventoryForCombo as $it) {
                            if ((string) $it['ItemID'] === (string) $itemId) {
                                $label = $it['ItemName'] . ' (' . (int) $it['QuantityInStock'] . ' in stock)';
                                break;
                            }
                        }
                    ?>
                        <tr class="line-item-row">
                            <td class="line-no"><?= $i + 1 ?></td>
                            <td>
                                <div class="combo" data-combo>
                                    <input type="hidden" name="ItemID[]" class="item-id-input" value="<?= tdc_e((string) $itemId) ?>">
                                    <input type="text" class="combo-input item-search-input" placeholder="Search item..." autocomplete="off" value="<?= tdc_e($label) ?>">
                                    <ul class="combo-list item-combo-list" hidden></ul>
                                </div>
                            </td>
                            <td><input type="number" min="1" name="Quantity[]" class="qty-input" value="<?= tdc_e($oldSale['Quantity'][$i] ?? '1') ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="UnitPrice[]" class="price-input" value="<?= tdc_e($oldSale['UnitPrice'][$i] ?? '') ?>" readonly aria-label="Inventory unit price"></td>
                            <td class="line-total-display">0.00</td>
                            <td><button type="button" class="remove-line-btn" title="Remove line">&times;</button></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn-success btn add-line-btn" id="addLineBtn"><?= tdc_icon('plus',16) ?><span>Add Item</span></button>

            <div class="totals-row">
                <div class="form-group"><label>Total</label><div class="due-display" id="sf_TotalDisplay">0.00</div></div>
                <div class="form-group"><label for="sf_AmountPaid">Amount Paid</label>
                    <input type="number" step="0.01" min="0" id="sf_AmountPaid" name="AmountPaid" value="<?= tdc_e($oldSale['AmountPaid']) ?>" placeholder="Defaults to full total"></div>
                <div class="form-group"><label>Due</label><div class="due-display" id="sf_DueDisplay">0.00</div></div>
            </div>

            <div class="form-actions">
                <a href="pharmacy.php?section=pos" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn-success btn ">Complete Sale</button>
            </div>
        </form>

        <?php else: ?>

        <div class="welcome-title">Point of Sale</div>
        <div class="welcome-sub">Over-the-counter sales, drawn directly from Inventory stock.</div>

        <div class="section-toolbar">
            <form method="GET" action="pharmacy.php" class="filter-box">
                <input type="hidden" name="section" value="pos">
                <input type="text" name="q" placeholder="Search by customer or ref..." value="<?= tdc_e($saleSearch) ?>">
                <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Search</span></button>
            </form>
            <a href="pharmacy.php?section=pos&new=1" class="btn-success btn ">+ New Sale</a>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Sale Ref</th><th>Customer</th><th>Items</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($pharmacySales)): ?>
                    <tr class="empty-row"><td colspan="9">No sales found<?= $saleSearch !== '' ? ' for "' . tdc_e($saleSearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($pharmacySales as $s): ?>
                    <tr>
                        <td><?= tdc_e($s['SaleRef']) ?></td>
                        <td><?= tdc_e($s['CustomerName'] ?: 'Walk-in') ?></td>
                        <td><?= (int) $s['LineCount'] ?></td>
                        <td><?= number_format((float) $s['TotalAmount'], 2) ?></td>
                        <td><?= number_format((float) $s['AmountPaid'], 2) ?></td>
                        <td><span class="status-badge<?= (float) $s['DueBalance'] > 0 ? ' danger' : '' ?>"><?= number_format((float) $s['DueBalance'], 2) ?></span></td>
                        <td><span class="status-badge<?= $s['PaymentStatus'] === 'Unpaid' ? ' danger' : ($s['PaymentStatus'] === 'Partial' ? ' warn' : '') ?>"><?= tdc_e($s['PaymentStatus']) ?></span></td>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $s['SaleDate']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="pharmacy.php?section=pos&view=<?= urlencode($s['SaleRef']) ?>" class="btn-sm">View</a>
                                <form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this sale? Stock will be restored. This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="void">
                                    <input type="hidden" name="SaleRef" value="<?= tdc_e($s['SaleRef']) ?>">
                                    <button type="submit" class="btn-danger btn-sm danger">Void</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>

    <?php // ============================================================
          // PURCHASES
          // ============================================================ ?>
    <?php elseif ($section === 'purchases'): ?>

        <?php if ($viewPORef !== ''): ?>
        <?php $head = $viewPOLines[0]; ?>
        <div class="welcome-title">Purchase Order — <?= tdc_e($viewPORef) ?></div>
        <div class="welcome-sub"><?= tdc_e(date('Y-m-d H:i', strtotime((string) $head['PurchaseDate']))) ?></div>

        <div class="info-grid">
            <div class="info-field"><div class="info-label">Supplier</div><div class="info-value"><?= tdc_e($head['SupplierName']) ?></div></div>
            <div class="info-field"><div class="info-label">Phone</div><div class="info-value"><?= tdc_e($head['SupplierPhone'] ?: '—') ?></div></div>
            <div class="info-field"><div class="info-label">Reference</div><div class="info-value"><?= tdc_e((string) ($head['ReferenceNumber'] ?? '')) ?: '-' ?></div></div>
            <?php if ($canViewPurchaseCost): ?><div class="info-field"><div class="info-label">Discount</div><div class="info-value"><?= number_format((float) ($head['Discount'] ?? 0), 2) ?></div></div>
            <div class="info-field"><div class="info-label">VAT</div><div class="info-value"><?= number_format((float) ($head['VATAmount'] ?? 0), 2) ?></div></div>
            <div class="info-field"><div class="info-label">Net Amount</div><div class="info-value"><?= number_format((float) $head['TotalAmount'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Paid</div><div class="info-value"><?= number_format((float) $head['AmountPaid'], 2) ?></div></div>
            <div class="info-field"><div class="info-label">Due</div><div class="info-value"><?= number_format((float) $head['DueBalance'], 2) ?></div></div><?php endif; ?>
        </div>

        <div class="subsection-title">Items Received</div>
        <div class="data-table-wrap" style="margin-bottom:24px;">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Category</th><th>Qty</th><th>Unit</th><?php if ($canViewPurchaseCost): ?><th>Purchase Price</th><?php endif; ?><th>Selling Price</th><th>Expiry</th></tr></thead>
                <tbody>
                    <?php foreach ($viewPOLines as $l): ?>
                    <tr>
                        <td><?= tdc_e($l['ItemName']) ?></td>
                        <td><?= tdc_e($l['Category'] ?: '—') ?></td>
                        <td><?= (int) $l['Quantity'] ?></td>
                        <td><?= tdc_e($l['PurchaseUnit'] ?: '—') ?></td>
                        <?php if ($canViewPurchaseCost): ?><td><?= number_format((float) $l['UnitPrice'], 2) ?></td><?php endif; ?>
                        <td><?= number_format((float) $l['SellingPrice'], 2) ?></td>
                        <td><?= tdc_e(!empty($l['ExpiryDate']) ? date('Y-m-d', strtotime((string) $l['ExpiryDate'])) : '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row-actions no-print">
            <button type="button" class="btn-info  btn " onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print</span></button>
            <?php if ($canViewPurchaseCost): ?><form method="POST" action="pharmacy.php?section=purchases" data-confirm="Void this purchase order? Stock added by it will be reversed. This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="void">
                <input type="hidden" name="PORef" value="<?= tdc_e($viewPORef) ?>">
                <button type="submit" class="btn-danger btn-sm danger">Void Purchase Order</button>
            </form><?php endif; ?>
        </div>

        <?php elseif ($purchaseShowForm): ?>

        <a href="pharmacy.php?section=purchases" class="btn btn-secondary">Back to purchases</a>
        <button type="button" class="btn-success btn " id="openPurchaseModal">+ New Purchase</button>
        <div class="modal-overlay" id="purchaseModal"><div class="modal-box modal-wide purchase-modal" aria-labelledby="purchaseTitle">
        <div class="modal-head"><h3 id="purchaseTitle">New Purchase</h3><button type="button" class="modal-close" data-close-purchase aria-label="Close">&times;</button></div><div class="modal-body">
        <?php if ($errors): ?><div class="error-msg" role="alert"><?= tdc_e(implode(' ', $errors)) ?></div><?php endif; ?>
        <div class="welcome-sub">Receiving stock from a supplier updates Inventory automatically.</div>

        <datalist id="purchasePackUnits">
            <?php foreach (['Box', 'Pack', 'Carton', 'Strip', 'Bottle', 'Vial', 'Tube', 'Sachet', 'Roll', 'Bundle'] as $packUnitOption): ?><option value="<?= tdc_e($packUnitOption) ?>"><?php endforeach; ?>
        </datalist>

        <form id="purchaseForm" method="POST" action="pharmacy.php?section=purchases">
            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
            <input type="hidden" name="form_action" value="save">

            <div class="form-row" style="margin-bottom:16px;">
                <div class="form-group"><label for="pof_SupplierName">Supplier Name *</label>
                    <input type="text" id="pof_SupplierName" name="SupplierName" value="<?= tdc_e($oldPurchase['SupplierName']) ?>" required></div>
                <div class="form-group"><label for="pof_SupplierPhone">Supplier Phone</label>
                    <input type="text" id="pof_SupplierPhone" name="SupplierPhone" value="<?= tdc_e($oldPurchase['SupplierPhone']) ?>"></div>
                <div class="form-group"><label for="pof_ReferenceNumber">Reference Number</label>
                    <input type="text" id="pof_ReferenceNumber" name="ReferenceNumber" maxlength="60" value="<?= tdc_e($oldPurchase['ReferenceNumber']) ?>" placeholder="e.g. INV-1024"></div>
                <div class="form-group"><label for="pof_PurchaseDate">Purchase Date</label>
                    <input type="date" id="pof_PurchaseDate" name="PurchaseDate" value="<?= tdc_e($oldPurchase['PurchaseDate']) ?>"></div>
            </div>

            <div class="line-items-wrap">
                <table class="line-items" id="lineItemsTable">
<thead><tr><th class="col-medicine">Medicine *</th><th class="col-expiry">Expiry</th><th class="col-qty">Quantity *</th><th class="col-cost">Purchase Price *</th><th class="col-price">Selling Price *</th><th class="col-amount">Amount</th><th class="col-actions">Actions</th></tr></thead>
                    <tbody id="lineItemsBody">
                    <?php
                    $poLineCount = max(1, count($oldPurchase['ItemName']));
                    for ($i = 0; $i < $poLineCount; $i++):
                    ?>
                        <tr class="line-item-row">
                            <td class="medicine-cell">
                                <div class="combo">
                                    <input type="hidden" name="ItemID[]" class="purchase-item-id" value="<?= tdc_e((string) ($oldPurchase['ItemID'][$i] ?? '')) ?>">
                                    <input type="hidden" name="ItemName[]" class="purchase-item-name" value="<?= tdc_e($oldPurchase['ItemName'][$i] ?? '') ?>">
                                    <input type="hidden" name="Category[]" class="purchase-item-category" value="<?= tdc_e($oldPurchase['Category'][$i] ?? '') ?>">
                                    <input type="hidden" name="SalesUnit[]" class="purchase-item-salesunit" value="<?= tdc_e($oldPurchase['SalesUnit'][$i] ?? '') ?>">
                                    <input type="text" class="combo-input purchase-item-search" placeholder="Search medicine..." autocomplete="off" required value="<?= tdc_e($oldPurchase['ItemName'][$i] ?? '') ?>" aria-label="Medicine">
                                    <ul class="combo-list purchase-combo-list" hidden></ul>
                                </div>
                                <div class="purchase-item-meta"><?php $metaCat = (string) ($oldPurchase['Category'][$i] ?? ''); $metaUnit = (string) ($oldPurchase['SalesUnit'][$i] ?? ''); ?><?php if ($metaCat !== ''): ?><span><?= tdc_e($metaCat) ?></span><?php endif; ?><?php if ($metaCat !== '' && $metaUnit !== ''): ?><span class="purchase-meta-sep">&bull;</span><?php endif; ?><?php if ($metaUnit !== ''): ?><span><?= tdc_e($metaUnit) ?></span><?php endif; ?></div>
                            </td>
                            <td><input type="date" name="ExpiryDate[]" value="<?= tdc_e($oldPurchase['ExpiryDate'][$i] ?? '') ?>" aria-label="Expiry"></td>
                            <td><input type="number" min="1" required name="Quantity[]" class="purchase-qty" value="<?= tdc_e($oldPurchase['Quantity'][$i] ?? '') ?>" aria-label="Quantity"><small class="purchase-qty-hint"></small></td>
                            <td><input type="number" step="0.01" min="0" required name="UnitPrice[]" class="purchase-unit-price" value="<?= tdc_e($oldPurchase['UnitPrice'][$i] ?? '') ?>" aria-label="Purchase Price"><small class="purchase-cost-hint"></small></td>
                            <td><input type="number" step="0.01" min="0" required name="SellingPrice[]" class="purchase-selling-price" value="<?= tdc_e($oldPurchase['SellingPrice'][$i] ?? '') ?>" aria-label="Selling Price"><small class="purchase-selling-hint"></small></td>
                            <td class="purchase-line-amount">0.00</td>
                            <td><button type="button" class="remove-line-btn" title="Remove item" aria-label="Remove item">&times;</button></td>
                        </tr>
                        <tr class="purchase-pkg-row">
                            <td colspan="7">
                                <?php
                                $commonPacks  = ['Box', 'Pack', 'Bottle', 'Carton', 'Strip', 'Bag'];
                                $selectedPack = tdc_norm_unit((string) ($oldPurchase['PurchaseUnit'][$i] ?? ''));
                                $selectedSize = (string) ($oldPurchase['ConversionFactor'][$i] ?? '1');
                                $isCustomPack = $selectedPack !== '' && !in_array($selectedPack, $commonPacks, true);
                                $hasPack      = $selectedPack !== '' && (float) $selectedSize > 1;
                                $sellUnitName = tdc_norm_unit((string) ($oldPurchase['SalesUnit'][$i] ?? ''), 'Unit');
                                $sellUnitPlural = tdc_plural_unit($sellUnitName);
                                $packValue    = $hasPack ? $selectedPack . ' (' . (int) $selectedSize . ' ' . $sellUnitPlural . ')' : '';
                                ?>
                                <div class="purchase-mode-row"<?= $hasPack ? '' : ' hidden' ?>>
                                    <span class="purchase-mode-label">Purchase as</span>
                                    <span class="purchase-mode-seg">
                                        <label class="purchase-mode-opt purchase-mode-opt-unit<?= $hasPack ? '' : ' is-active' ?>">
                                            <input type="radio" class="purchase-mode-radio purchase-mode-unit" value="unit"<?= $hasPack ? '' : ' checked' ?>>
                                            <span class="purchase-mode-unit-label">Individual <?= tdc_e(strtolower($sellUnitPlural)) ?></span>
                                        </label>
                                        <label class="purchase-mode-opt purchase-mode-opt-package<?= $hasPack ? ' is-active' : ' is-disabled' ?>"<?= $hasPack ? '' : ' hidden' ?>>
                                            <input type="radio" class="purchase-mode-radio purchase-mode-package" value="package"<?= $hasPack ? ' checked' : ' disabled' ?>>
                                            <span class="purchase-mode-pack-label"><?= $hasPack ? tdc_e($packValue) : '' ?></span>
                                        </label>
                                    </span>
                                    <span class="purchase-pkg-value"></span>
                                </div>
                                <div class="purchase-pkg-unavailable"<?= $hasPack ? ' hidden' : '' ?>>No package configured</div>
                                <div class="purchase-pkg-editor" hidden>
                                    <label class="purchase-pkg-field">Package
                                        <select class="purchase-pack-unit-select" aria-label="Purchase package">
                                            <option value="">Select package</option>
                                            <?php foreach ($commonPacks as $packOption): ?>
                                            <option value="<?= tdc_e($packOption) ?>" <?= $selectedPack === $packOption ? 'selected' : '' ?>><?= tdc_e($packOption) ?></option>
                                            <?php endforeach; ?>
                                            <option value="Other" <?= $isCustomPack ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </label>
                                    <label class="purchase-pack-other" <?= $isCustomPack ? '' : 'hidden' ?>>Package name
                                        <input type="text" class="purchase-pack-unit-other" placeholder="e.g. Sachet" value="<?= $isCustomPack ? tdc_e($selectedPack) : '' ?>">
                                    </label>
                                    <label class="purchase-pkg-field">Units per package
                                        <input type="number" step="1" min="1" name="ConversionFactor[]" class="purchase-pack-size" value="<?= tdc_e($selectedSize) ?>">
                                    </label>
                                    <input type="hidden" name="PurchaseUnit[]" class="purchase-pack-unit" value="<?= tdc_e($selectedPack) ?>">
                                    <div class="purchase-pkg-editor-mode">
                                        <span class="purchase-pkg-editor-mode-label">Inventory unit</span>
                                        <span class="purchase-pkg-unit-name"></span>
                                    </div>
                                </div>
                                <div class="purchase-pack-hint"></div>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn-success btn add-line-btn" id="addLineBtn"><?= tdc_icon('plus',16) ?><span>Add Item</span></button>

            <div class="totals-row">
                <div class="purchase-summary">
                    <div class="purchase-summary-row"><span>Subtotal</span><span id="pof_SubtotalDisplay">0.00</span></div>
                    <div class="purchase-summary-row"><label for="pof_Discount">Discount</label>
                        <input type="number" step="0.01" min="0" id="pof_Discount" name="Discount" value="<?= tdc_e($oldPurchase['Discount']) ?>" placeholder="0.00"></div>
                    <div class="purchase-summary-row"><label for="pof_VATAmount">VAT</label>
                        <input type="number" step="0.01" min="0" id="pof_VATAmount" name="VATAmount" value="<?= tdc_e($oldPurchase['VATAmount']) ?>" placeholder="0.00"></div>
                    <div class="purchase-summary-row is-divider"><span>Net Amount</span><span id="pof_NetDisplay">0.00</span></div>
                    <div class="purchase-summary-row"><label for="pof_AmountPaid">Amount Paid</label>
                        <input type="number" step="0.01" min="0" id="pof_AmountPaid" name="AmountPaid" value="<?= tdc_e($oldPurchase['AmountPaid']) ?>"></div>
                    <div class="purchase-summary-row is-total"><span>Due to Supplier</span><span id="pof_DueDisplay">0.00</span></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-purchase>Cancel</button>
                <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Save Purchase</span></button>
            </div>
        </form></div></div></div>

        <?php else: ?>

        <div class="welcome-title">Purchases</div>
        <div class="welcome-sub">Purchase orders from suppliers. Saving one adds the received stock to Inventory.</div>

        <?= tdc_toolbar_start() ?>
            <?= tdc_date_range([
                'from' => $purchaseFrom,
                'to' => $purchaseTo,
                'error' => $purchaseDateError,
                'id' => 'purchaseDateRange',
                'preserve' => ['section' => 'purchases', 'q' => $purchaseSearch, 'status' => $purchaseStatus, 'per_page' => $purchasePerPage],
            ]) ?>
            <form method="GET" action="pharmacy.php" class="filter-box">
                <input type="hidden" name="section" value="purchases">
                <input type="hidden" name="from_date" value="<?= tdc_e($purchaseFrom) ?>">
                <input type="hidden" name="to_date" value="<?= tdc_e($purchaseTo) ?>">
                <input type="hidden" name="per_page" value="<?= (int) $purchasePerPage ?>">
                <?= tdc_search_field('q', $purchaseSearch, 'Search supplier or reference...') ?>
                <?php if ($canViewPurchaseCost): ?><label class="table-filter">
                    <select name="status" aria-label="Payment status">
                        <option value="">All statuses</option>
                        <option value="paid" <?= $purchaseStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="due" <?= $purchaseStatus === 'due' ? 'selected' : '' ?>>Due</option>
                    </select>
                </label><?php endif; ?>
                <button type="submit" class="btn-primary btn  btn-sm"><?= tdc_icon('filter', 14) ?><span>Apply</span></button>
            </form>
            <?= tdc_toolbar_spacer() ?>
            <?= tdc_export_buttons(['csv' => $purchaseExportUrl]) ?>
            <?php if ($canViewPurchaseCost): ?><a href="pharmacy.php?section=purchases&new=1" class="btn btn-success"><?= tdc_icon('plus', 15) ?><span>New Purchase</span></a><?php endif; ?>
        <?= tdc_toolbar_end() ?>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>PO Ref</th><th>Supplier</th><th>Items</th><?php if ($canViewPurchaseCost): ?><th>Total</th><th>Paid</th><th>Due</th><?php endif; ?><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($purchaseOrders)): ?>
                    <?= tdc_empty_state('inbox', 'No purchase orders yet', $purchaseSearch !== '' || $purchaseDateActive || $purchaseStatus !== '' ? 'No purchase orders match the current filters.' : 'Record stock received from suppliers to build your purchase history.', $canViewPurchaseCost ? '<a class="btn-success btn " href="pharmacy.php?section=purchases&new=1">New Purchase</a>' : '', $canViewPurchaseCost ? 8 : 5) ?>
                    <?php else: foreach ($purchaseOrders as $po): ?>
                    <tr>
                        <td><?= tdc_e($po['PORef']) ?></td>
                        <td><?= tdc_e($po['SupplierName']) ?></td>
                        <td><?= (int) $po['LineCount'] ?></td>
                        <?php if ($canViewPurchaseCost): ?><td><?= number_format((float) $po['TotalAmount'], 2) ?></td>
                        <td><?= number_format((float) $po['AmountPaid'], 2) ?></td>
                        <td><span class="status-badge<?= (float) $po['DueBalance'] > 0 ? ' danger' : '' ?>"><?= number_format((float) $po['DueBalance'], 2) ?></span></td><?php endif; ?>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $po['PurchaseDate']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-action waiting-open" href="pharmacy.php?section=purchases&view=<?= urlencode($po['PORef']) ?>" title="View purchase order" aria-label="View purchase order"><?= tdc_icon('eye', 15) ?></a>
                                <?php if ($canViewPurchaseCost): ?><form method="POST" action="pharmacy.php?section=purchases" data-confirm="Void this purchase order? Stock added by it will be reversed. This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="void">
                                    <input type="hidden" name="PORef" value="<?= tdc_e($po['PORef']) ?>">
                                    <button type="submit" class="icon-action danger" title="Void purchase order" aria-label="Void purchase order"><?= tdc_icon('ban', 15) ?></button>
                                </form><?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?= tdc_pager($purchasePage, $purchasePerPage, $purchaseTotal, array_filter(['section' => 'purchases', 'q' => $purchaseSearch, 'status' => $purchaseStatus, 'from_date' => $purchaseFrom, 'to_date' => $purchaseTo, 'per_page' => $purchasePerPage], static fn($value): bool => $value !== '' && $value !== null)) ?>

        <?php endif; ?>

    <?php // ============================================================
          // INVENTORY
          // ============================================================ ?>
    <?php elseif ($section === 'inventory'): ?>

        <div class="welcome-title">Inventory</div>
        <div class="welcome-sub">Stock levels, pricing, and reorder thresholds for pharmacy items.</div>

        <div class="section-toolbar">
            <form method="GET" action="pharmacy.php" class="filter-box">
                <input type="hidden" name="section" value="inventory">
                <input type="text" name="q" placeholder="Search by name or category..." value="<?= tdc_e($inventorySearch) ?>">
                <label><input type="checkbox" name="low" value="1" <?= $lowStockOnly ? 'checked' : '' ?> onchange="this.form.submit()"> Low stock only</label>
                <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Search</span></button>
            </form>
            <button type="button" id="addItemBtn" class="btn-success  btn "><?= tdc_icon('plus',16) ?><span>+ Add Medicine</span></button>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Item ID</th><th>Category</th><th>Name</th><th>Stock</th><th>Unit</th><th>Selling Price</th><th>Reorder Level</th><th>Expiry</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($inventoryItems)): ?>
                    <tr class="empty-row"><td colspan="9">No inventory items found<?= $inventorySearch !== '' ? ' for "' . tdc_e($inventorySearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($inventoryItems as $item):
                        $isLow = (int) $item['QuantityInStock'] <= (int) $item['ReorderLevel'];
                        $expiryTs = $item['ExpiryDate'] ? strtotime((string) $item['ExpiryDate']) : null;
                        $isExpired = $expiryTs !== null && $expiryTs < strtotime('today');
                        $isExpiringSoon = $expiryTs !== null && !$isExpired && $expiryTs <= strtotime('+30 days');
                    ?>
                    <tr>
                        <td><?= tdc_e($item['ItemID']) ?></td>
                        <td><?= tdc_e($item['Category'] ?: '—') ?></td>
                        <td><?= tdc_e($item['ItemName']) ?></td>
                        <td><?= (int) $item['QuantityInStock'] ?> <?php if ($isLow): ?><span class="status-badge danger">Low</span><?php endif; ?></td>
                        <td><?= tdc_e(tdc_norm_unit($item['SalesUnit'] ?? null, '—')) ?></td>
                        <td><?= number_format((float) $item['SellingPrice'], 2) ?></td>
                        <td><?= (int) $item['ReorderLevel'] ?></td>
                        <td>
                            <?= $item['ExpiryDate'] ? tdc_e(date('Y-m-d', $expiryTs)) : '—' ?>
                            <?php if ($isExpired): ?><span class="status-badge danger">Expired</span>
                            <?php elseif ($isExpiringSoon): ?><span class="status-badge warn">Expiring Soon</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn-warning btn-sm edit-item-btn"
                                    data-id="<?= tdc_e($item['ItemID']) ?>"
                                    data-name="<?= tdc_e($item['ItemName']) ?>"
                                    data-category="<?= tdc_e((string) $item['Category']) ?>"
                                    data-stock="<?= tdc_e((string) $item['QuantityInStock']) ?>"
                                    data-unit="<?= tdc_e((string) $item['SalesUnit']) ?>"
                                    data-price="<?= tdc_e((string) $item['SellingPrice']) ?>"
                                    data-reorder="<?= tdc_e((string) $item['ReorderLevel']) ?>"
                                    data-packunit="<?= tdc_e((string) $item['DefaultPurchaseUnit']) ?>"
                                    data-packsize="<?= tdc_e((string) $item['UnitsPerPackage']) ?>"
                                    data-expiry="<?= tdc_e((string) $item['ExpiryDate']) ?>"><?= tdc_icon('pencil',16) ?><span>Edit</span></button>
                                <form method="POST" action="pharmacy.php?section=inventory" data-confirm="Delete this item? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="ItemID" value="<?= tdc_e($item['ItemID']) ?>">
                                    <button type="submit" class="btn-danger btn-sm danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <datalist id="packUnitOptions">
            <?php foreach (['Box','Pack','Bottle','Carton','Strip','Bag','Sachet','Vial','Tube','Roll','Bundle'] as $packOpt): ?><option value="<?= tdc_e($packOpt) ?>"><?php endforeach; ?>
        </datalist>
        <div class="modal-overlay medicine-modal" id="itemModalOverlay">
            <div class="modal-box">
                <div class="modal-head">
                    <h3 id="itemModalTitle">Add Medicine</h3>
                    <button type="button" class="modal-close" id="itemModalCloseBtn" aria-label="Close">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
                <form id="itemForm" method="POST" action="pharmacy.php?section=inventory">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="form_action" value="save">
                        <input type="hidden" name="ItemID" id="if_ItemID" value="">

                        <div class="form-group"><label for="if_ItemName">Medicine Name</label>
                            <input type="text" id="if_ItemName" name="ItemName" required></div>

                        <div class="form-row">
                            <div class="form-group"><label for="if_Category">Category</label><input type="text" id="if_Category" name="Category"></div>
                            <div class="form-group"><label for="if_SalesUnit">Base Unit *</label>
                                <select id="if_SalesUnit" name="SalesUnit" required><option value="">Select unit</option>
                                <?php foreach (['Tablet','Capsule','Bottle','Tube','Box','Sachet','Vial','Ampoule','Piece','Pack','Other'] as $unit): ?><option value="<?= tdc_e($unit) ?>"><?= tdc_e($unit) ?></option><?php endforeach; ?>
                                </select></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="if_SellingPrice">Selling Price</label><input type="number" step="0.01" min="0" id="if_SellingPrice" name="SellingPrice" required aria-describedby="medicinePriceHelp"><small id="medicinePriceHelp">Price per selected unit</small></div>
                            <div class="form-group"><label for="if_QuantityInStock" id="stockQuantityLabel">Opening Quantity</label><input type="number" min="0" step="1" id="if_QuantityInStock" name="QuantityInStock" value="0" required></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label for="if_ExpiryDate">Expiry Date</label><input type="date" id="if_ExpiryDate" name="ExpiryDate"></div>
                            <div class="form-group"><label for="if_ReorderLevel">Reorder Level</label><input type="number" min="0" step="1" id="if_ReorderLevel" name="ReorderLevel" value="10"></div>
                        </div>
                        <div class="form-section-label">Purchase Packaging</div>
                        <p class="form-hint">Optional, but requires a Base Unit first. Set this once if the medicine is normally bought in a larger pack.</p>
                        <div class="form-row">
                            <div class="form-group"><label for="if_DefaultPurchaseUnit">Default Purchase Package</label>
                                <input type="text" id="if_DefaultPurchaseUnit" name="DefaultPurchaseUnit" list="packUnitOptions" placeholder="e.g. Box" disabled></div>
                            <div class="form-group"><label for="if_UnitsPerPackage">Units per Package</label>
                                <input type="number" min="1" step="1" id="if_UnitsPerPackage" name="UnitsPerPackage" placeholder="e.g. 100" disabled></div>
                        </div>
                        <p class="form-hint" id="packagingHint"></p>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" id="itemModalCancelBtn">Cancel</button>
                            <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span id="medicineSaveLabel">Save Medicine</span></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

<?php endif; ?>

</main>

<div id="js-toast" role="alert" aria-live="assertive">
    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.03-9.78a.75.75 0 00-1.06-1.06L8.75 10.44l-1.72-1.72a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.06 0l4.75-4.75z" clip-rule="evenodd"/></svg>
    <span id="js-toast-msg"></span>
</div>

<script>
(function(){
    const nav = document.getElementById('topnav');
    const items = Array.from(nav.querySelectorAll('.nav-item[data-menu]'));
    function closeAll(except){ items.forEach(function(item){ if(item !== except){ item.classList.remove('open'); } }); }
    items.forEach(function(item){
        const trigger = item.querySelector('.icon-btn');
        if(!trigger) return;
        trigger.addEventListener('click', function(e){
            e.stopPropagation();
            const isOpen = item.classList.contains('open');
            closeAll(item);
            item.classList.toggle('open', !isOpen);
        });
    });
    document.addEventListener('click', function(){ closeAll(null); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ closeAll(null); } });
})();

let toastTimer = null;
function showToast(message){
    const toast = document.getElementById('js-toast');
    document.getElementById('js-toast-msg').textContent = message;
    clearTimeout(toastTimer);
    toast.classList.add('show');
    toastTimer = setTimeout(() => { toast.classList.remove('show'); }, 3000);
}

/**
 * Generic search combobox: filters an in-memory option list as the
 * user types, with click + keyboard (Up/Down/Enter/Escape) selection.
 * onSelect receives the matched option object for callers that need
 * to auto-fill related fields (e.g. POS auto-filling price/stock).
 */
function initComboBox(hiddenIdInput, searchInput, listEl, options, onSelect){
    let activeIndex = -1;
    let currentMatches = [];

    function render(matches){
        currentMatches = matches;
        activeIndex = -1;
        listEl.innerHTML = '';
        if (matches.length === 0){
            const li = document.createElement('li');
            li.className = 'combo-empty';
            li.textContent = 'No matching items.';
            listEl.appendChild(li);
        } else {
            matches.slice(0, 30).forEach(function(opt){
                const li = document.createElement('li');
                li.textContent = opt.label;
                li.addEventListener('mousedown', function(e){ e.preventDefault(); select(opt); });
                listEl.appendChild(li);
            });
        }
        listEl.hidden = false;
    }

    function select(opt){
        hiddenIdInput.value = opt.id;
        searchInput.value = opt.label;
        listEl.hidden = true;
        if (typeof onSelect === 'function') onSelect(opt);
    }

    function highlight(delta){
        const liList = Array.from(listEl.querySelectorAll('li:not(.combo-empty)'));
        if (liList.length === 0) return;
        activeIndex = (activeIndex + delta + liList.length) % liList.length;
        liList.forEach(function(li, i){ li.classList.toggle('active', i === activeIndex); });
        liList[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    searchInput.addEventListener('input', function(){
        hiddenIdInput.value = '';
        const term = searchInput.value.trim().toLowerCase();
        if (term === ''){ listEl.hidden = true; return; }
        render(options.filter(function(o){ return o.label.toLowerCase().indexOf(term) !== -1; }));
    });

    searchInput.addEventListener('focus', function(){
        if (searchInput.value.trim() !== '' && hiddenIdInput.value === ''){
            render(options.filter(function(o){ return o.label.toLowerCase().indexOf(searchInput.value.trim().toLowerCase()) !== -1; }));
        }
    });

    searchInput.addEventListener('keydown', function(e){
        if (listEl.hidden) return;
        if (e.key === 'ArrowDown'){ e.preventDefault(); highlight(1); }
        else if (e.key === 'ArrowUp'){ e.preventDefault(); highlight(-1); }
        else if (e.key === 'Enter'){
            if (activeIndex >= 0 && currentMatches[activeIndex]){ e.preventDefault(); select(currentMatches[activeIndex]); }
        } else if (e.key === 'Escape'){ listEl.hidden = true; }
    });

    document.addEventListener('click', function(e){
        if (!searchInput.contains(e.target) && !listEl.contains(e.target)) listEl.hidden = true;
    });
}

<?php if ($section === 'pos' && $posShowForm): ?>
(function(){
    const items = <?= json_encode(array_map(static function ($it) {
        return [
            'id'    => $it['ItemID'],
            'label' => $it['ItemName'] . ' (' . (int) $it['QuantityInStock'] . ' in stock)',
            'price' => (float) $it['SellingPrice'],
            'stock' => (int) $it['QuantityInStock'],
        ];
    }, $inventoryForCombo), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const tbody = document.getElementById('lineItemsBody');

    function wireRow(row){
        const hiddenId  = row.querySelector('.item-id-input');
        const search    = row.querySelector('.item-search-input');
        const list      = row.querySelector('.item-combo-list');
        const qtyInput  = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');

        initComboBox(hiddenId, search, list, items, function(opt){
            priceInput.value = opt.price.toFixed(2);
            qtyInput.max = opt.stock;
            if (parseInt(qtyInput.value || '1', 10) > opt.stock) qtyInput.value = opt.stock;
            recalcAll();
        });

        [qtyInput, priceInput].forEach(function(el){ el.addEventListener('input', recalcAll); });

        row.querySelector('.remove-line-btn').addEventListener('click', function(){
            if (tbody.querySelectorAll('.line-item-row').length > 1){ row.remove(); renumber(); recalcAll(); }
        });
    }

    function renumber(){
        tbody.querySelectorAll('.line-item-row').forEach(function(row, i){ const numberCell = row.querySelector('.line-no'); if (numberCell) numberCell.textContent = i + 1; });
    }

    function recalcAll(){
        let total = 0;
        tbody.querySelectorAll('.line-item-row').forEach(function(row){
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const lineTotal = qty * price;
            row.querySelector('.line-total-display').textContent = lineTotal.toFixed(2);
            total += lineTotal;
        });
        document.getElementById('sf_TotalDisplay').textContent = total.toFixed(2);
        const paidInput = document.getElementById('sf_AmountPaid');
        const paid = paidInput.value !== '' ? (parseFloat(paidInput.value) || 0) : total;
        document.getElementById('sf_DueDisplay').textContent = (total - paid).toFixed(2);
    }

    tbody.querySelectorAll('.line-item-row').forEach(wireRow);
    document.getElementById('sf_AmountPaid').addEventListener('input', recalcAll);

    document.getElementById('addLineBtn').addEventListener('click', function(){
        const template = tbody.querySelector('.line-item-row').cloneNode(true);
        template.querySelector('.item-id-input').value = '';
        template.querySelector('.item-search-input').value = '';
        template.querySelector('.qty-input').value = '1';
        template.querySelector('.qty-input').removeAttribute('max');
        template.querySelector('.price-input').value = '';
        template.querySelector('.line-total-display').textContent = '0.00';
        tbody.appendChild(template);
        wireRow(template);
        renumber();
    });

    recalcAll();
})();
<?php endif; ?>

<?php if ($section === 'purchases' && $purchaseShowForm): ?>
(function(){
    const tbody = document.getElementById('lineItemsBody');
    const modal = document.getElementById('purchaseModal');
    const unitOptions = <?= json_encode($purchaseUnitOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const opener = document.getElementById('openPurchaseModal');
    const open = () => { modal.querySelector('.modal-box').removeAttribute('aria-hidden'); modal.removeAttribute('aria-hidden'); modal.classList.add('show'); };
    opener.addEventListener('click', open);
    document.querySelectorAll('[data-close-purchase]').forEach(button => button.addEventListener('click', () => { modal.classList.remove('show'); opener.focus(); }));
    window.addEventListener('DOMContentLoaded', () => { opener.focus(); open(); });

    function renumber(){
        tbody.querySelectorAll('.line-item-row').forEach(function(row, i){ const numberCell = row.querySelector('.line-no'); if (numberCell) numberCell.textContent = i + 1; });
    }

    function recalcAll(){
        let total = 0;
        tbody.querySelectorAll('.line-item-row').forEach(function(row){
            const qty = parseFloat(row.querySelector('input[name="Quantity[]"]').value) || 0;
            const price = parseFloat(row.querySelector('input[name="UnitPrice[]"]').value) || 0;
            total += qty * price;
            const amount = row.querySelector('.purchase-line-amount');
            if (amount) amount.textContent = (qty * price).toFixed(2);
        });
        const discount = parseFloat(document.getElementById('pof_Discount').value) || 0;
        const vat = parseFloat(document.getElementById('pof_VATAmount').value) || 0;
        const net = Math.max(0, total - discount + vat);
        document.getElementById('pof_SubtotalDisplay').textContent = total.toFixed(2);
        document.getElementById('pof_NetDisplay').textContent = net.toFixed(2);
        const paidInput = document.getElementById('pof_AmountPaid');
        const paid = parseFloat(paidInput.value) || 0;
        paidInput.max = net.toFixed(2);
        document.getElementById('pof_Discount').max = total.toFixed(2);
        document.getElementById('pof_DueDisplay').textContent = Math.max(0, net - paid).toFixed(2);
    }

    function pkgRowFor(row){
        const next = row.nextElementSibling;
        return (next && next.classList.contains('purchase-pkg-row')) ? next : null;
    }
    function pkgQuery(row, selector){
        const pkgRow = pkgRowFor(row);
        return pkgRow ? pkgRow.querySelector(selector) : null;
    }
    function bindRemove(row){
        row.querySelector('.remove-line-btn').addEventListener('click', function(){
            if (tbody.querySelectorAll('.line-item-row').length > 1){
                const pkgRow = pkgRowFor(row);
                if (pkgRow) pkgRow.remove();
                row.remove();
                renumber();
                recalcAll();
            }
        });
    }
    const purchaseOptions = unitOptions.map(function(it){
        return {
            id: it.ItemID,
            label: it.ItemName,
            ItemName: it.ItemName,
            Category: it.Category,
            SalesUnit: it.SalesUnit,
            SellingPrice: it.SellingPrice,
            DefaultPurchaseUnit: it.DefaultPurchaseUnit,
            UnitsPerPackage: it.UnitsPerPackage
        };
    });

    function rowMeta(row){
        const category = row.querySelector('.purchase-item-category').value || '';
        const salesUnit = row.querySelector('.purchase-item-salesunit').value || '';
        const metaUnit = salesUnit.trim() === '' ? '' : normUnit(salesUnit, '');
        row.querySelector('.purchase-item-meta').textContent = [category, metaUnit].filter(Boolean).join(' \u2022 ');
    }

    function packUnitValue(row){
        const select = pkgQuery(row, '.purchase-pack-unit-select');
        if (!select) return '';
        if (select.value === 'Other'){
            const other = pkgQuery(row, '.purchase-pack-unit-other');
            return other ? other.value.trim() : '';
        }
        return select.value;
    }

    function setMode(row, mode){
        const unitRadio = pkgQuery(row, '.purchase-mode-unit');
        const packRadio = pkgQuery(row, '.purchase-mode-package');
        const unitOpt = pkgQuery(row, '.purchase-mode-opt-unit');
        const packOpt = pkgQuery(row, '.purchase-mode-opt-package');
        const editor = pkgQuery(row, '.purchase-pkg-editor');
        const hasPack = packRadio && !packRadio.disabled;
        if (mode === 'package' && !hasPack) mode = 'unit';
        if (unitRadio) unitRadio.checked = (mode === 'unit');
        if (packRadio) packRadio.checked = (mode === 'package');
        if (unitOpt) unitOpt.classList.toggle('is-active', mode === 'unit');
        if (packOpt) packOpt.classList.toggle('is-active', mode === 'package');
        if (editor) editor.hidden = (mode !== 'package');
    }
    function currentMode(row){
        const packRadio = pkgQuery(row, '.purchase-mode-package');
        return (packRadio && packRadio.checked) ? 'package' : 'unit';
    }
    function loadPackage(row, unit, size){
        const select = pkgQuery(row, '.purchase-pack-unit-select');
        const other = pkgQuery(row, '.purchase-pack-unit-other');
        const otherWrap = pkgQuery(row, '.purchase-pack-other');
        const sizeInput = pkgQuery(row, '.purchase-pack-size');
        const hidden = pkgQuery(row, '.purchase-pack-unit');
        const common = ['Box','Pack','Bottle','Carton','Strip','Bag'];
        if (select){
            if (unit === '') select.value = '';
            else if (common.indexOf(unit) !== -1) select.value = unit;
            else select.value = 'Other';
        }
        if (other) other.value = (unit !== '' && common.indexOf(unit) === -1) ? unit : '';
        if (otherWrap) otherWrap.hidden = !select || select.value !== 'Other';
        if (sizeInput) sizeInput.value = (unit !== '' && size > 1) ? size : 1;
        if (hidden) hidden.value = unit !== '' ? unit : '';
    }

    function normUnit(value, fallback){
        let unit = String(value === null || value === undefined ? '' : value).trim();
        unit = unit.replace(/^[()\s]+|[()\s]+$/g, '');
        if (unit === '' || !isNaN(Number(unit))) return fallback;
        if (/^(per|unit|units)\b/i.test(unit)) return fallback;
        if (/^[0-9]+s$/i.test(unit)) return fallback;
        return unit;
    }
    function pluralUnit(unit){
        if (unit === '') return '';
        if (/[^aeiou]y$/i.test(unit)) return unit.slice(0, -1) + 'ies';
        if (/(s|x|z|ch|sh)$/i.test(unit)) return unit + 'es';
        return unit + 's';
    }
    function rowPackInfo(row){
        const qty = parseFloat(row.querySelector('.purchase-qty').value) || 0;
        const packUnit = packUnitValue(row);
        const sizeInput = pkgQuery(row, '.purchase-pack-size');
        const packSize = sizeInput ? (parseFloat(sizeInput.value) || 1) : 1;
        const salesUnit = row.querySelector('.purchase-item-salesunit').value || '';
        const unitName = normUnit(salesUnit, 'Unit');
        const plural = pluralUnit(unitName);
        const packPlural = pluralUnit(packUnit);
        const valueEl = pkgQuery(row, '.purchase-pkg-value');
        const packRadio = pkgQuery(row, '.purchase-mode-package');
        const packLabel = pkgQuery(row, '.purchase-mode-pack-label');
        const packOpt = pkgQuery(row, '.purchase-mode-opt-package');
        const unitNameEl = pkgQuery(row, '.purchase-pkg-unit-name');
        const qtyHint = row.querySelector('.purchase-qty-hint');
        const costHint = row.querySelector('.purchase-cost-hint');
        const hint = pkgQuery(row, '.purchase-pack-hint');
        const hasPack = packUnit !== '' && packSize > 1;
        const sellingHint = row.querySelector('.purchase-selling-hint');
        const modeRow = pkgQuery(row, '.purchase-mode-row');
        if (packRadio) packRadio.disabled = !hasPack;
        if (packOpt) packOpt.classList.toggle('is-disabled', !hasPack);
        if (packOpt) packOpt.hidden = !hasPack;
        if (modeRow) modeRow.hidden = !hasPack;
        if (packLabel) packLabel.textContent = hasPack ? (packUnit + ' (' + packSize + ' ' + plural + ')') : '';
        if (unitNameEl) unitNameEl.textContent = unitName;
        if (costHint) costHint.textContent = 'per ' + unitName;
        if (sellingHint) sellingHint.textContent = 'current per ' + unitName;
        const mode = hasPack ? currentMode(row) : 'unit';
        if (mode === 'package' && hasPack){
            if (valueEl) valueEl.textContent = packUnit + ' \u00d7 ' + packSize + ' ' + plural;
            if (qtyHint) qtyHint.textContent = packPlural;
            if (costHint) costHint.textContent = 'per ' + packUnit;
            if (hint) hint.textContent = qty + ' ' + packPlural + ' \u00d7 ' + packSize + ' ' + plural + ' = ' + Math.round(qty * packSize) + ' ' + plural + ' added to inventory';
        } else {
            if (valueEl) valueEl.textContent = '';
            if (qtyHint) qtyHint.textContent = plural;
            if (hint) hint.textContent = hasPack ? ('Package available: 1 ' + packUnit + ' = ' + packSize + ' ' + plural) : '';
            if (hasPack) setMode(row, 'unit');
        }
    }

    function bindRecalc(row){
        const labels = ['Medicine','Expiry Date','Quantity','Purchase Price','Selling Price','Amount','Actions'];
        row.querySelectorAll('td').forEach((cell,index) => { cell.dataset.label = labels[index]; });
        const search = row.querySelector('.purchase-item-search');
        const list = row.querySelector('.purchase-combo-list');
        const idInput = row.querySelector('.purchase-item-id');
        const nameInput = row.querySelector('.purchase-item-name');
        initComboBox(idInput, search, list, purchaseOptions, function(opt){
            nameInput.value = opt.ItemName;
            row.querySelector('.purchase-item-category').value = opt.Category || '';
            row.querySelector('.purchase-item-salesunit').value = opt.SalesUnit || '';
            row.querySelector('.purchase-selling-price').value = (opt.SellingPrice !== null && opt.SellingPrice !== undefined && opt.SellingPrice !== '') ? parseFloat(opt.SellingPrice).toFixed(2) : '';
            const defUnit = opt.DefaultPurchaseUnit || '';
            const defSize = parseFloat(opt.UnitsPerPackage) > 1 ? parseFloat(opt.UnitsPerPackage) : 1;
            loadPackage(row, defUnit, defSize);
            setMode(row, 'unit');
            rowMeta(row);
            rowPackInfo(row);
            recalcAll();
        });
        search.addEventListener('input', function(){ nameInput.value = search.value; });
        row.querySelectorAll('.purchase-qty, .purchase-unit-price, .purchase-selling-price').forEach(function(el){
            el.addEventListener('input', function(){ rowPackInfo(row); recalcAll(); });
        });
        const pkgRow = pkgRowFor(row);
        if (pkgRow){
            const select = pkgRow.querySelector('.purchase-pack-unit-select');
            const other = pkgRow.querySelector('.purchase-pack-unit-other');
            const size = pkgRow.querySelector('.purchase-pack-size');
            const hidden = pkgRow.querySelector('.purchase-pack-unit');
            const otherWrap = pkgRow.querySelector('.purchase-pack-other');
            const editor = pkgRow.querySelector('.purchase-pkg-editor');
            const toggle = pkgRow.querySelector('.purchase-pkg-toggle');
            if (select) select.addEventListener('change', function(){
                if (otherWrap) otherWrap.hidden = select.value !== 'Other';
                if (hidden) hidden.value = packUnitValue(row);
                rowPackInfo(row);
            });
            if (other) other.addEventListener('input', function(){ if (hidden) hidden.value = packUnitValue(row); rowPackInfo(row); });
            if (size) size.addEventListener('input', function(){ rowPackInfo(row); });
            const unitMode = pkgRow.querySelector('.purchase-mode-unit');
            const packMode = pkgRow.querySelector('.purchase-mode-package');
            if (unitMode) unitMode.addEventListener('change', function(){ setMode(row, 'unit'); rowPackInfo(row); recalcAll(); });
            if (packMode) packMode.addEventListener('change', function(){ setMode(row, 'package'); rowPackInfo(row); recalcAll(); });
        }
        rowMeta(row);
        rowPackInfo(row);
    }

    tbody.querySelectorAll('.line-item-row').forEach(function(row){ bindRemove(row); bindRecalc(row); });
    document.getElementById('pof_AmountPaid').addEventListener('input', recalcAll);
    document.getElementById('pof_Discount').addEventListener('input', recalcAll);
    document.getElementById('pof_VATAmount').addEventListener('input', recalcAll);

    document.getElementById('addLineBtn').addEventListener('click', function(){
        const sourceRow = tbody.querySelector('.line-item-row');
        const sourcePkg = pkgRowFor(sourceRow);
        const template = sourceRow.cloneNode(true);
        const pkgTemplate = sourcePkg ? sourcePkg.cloneNode(true) : null;
        template.querySelectorAll('input').forEach(function(i){ i.value = ''; });
        const meta = template.querySelector('.purchase-item-meta');
        if (meta) meta.textContent = '';
        tbody.appendChild(template);
        if (pkgTemplate){
            pkgTemplate.querySelectorAll('input').forEach(function(i){
                if (i.classList.contains('purchase-mode-radio')) return;
                i.value = i.classList.contains('purchase-pack-size') ? '1' : '';
            });
            const sel = pkgTemplate.querySelector('.purchase-pack-unit-select');
            if (sel) sel.value = '';
            const ow = pkgTemplate.querySelector('.purchase-pack-other');
            if (ow) ow.hidden = true;
            const ed = pkgTemplate.querySelector('.purchase-pkg-editor');
            if (ed) ed.hidden = true;
            const pk = pkgTemplate.querySelector('.purchase-mode-package');
            if (pk) pk.disabled = true;
            const pkOpt = pkgTemplate.querySelector('.purchase-mode-opt-package');
            if (pkOpt) pkOpt.classList.add('is-disabled');
            if (pkOpt) pkOpt.hidden = true;
            const mr = pkgTemplate.querySelector('.purchase-mode-row');
            if (mr) mr.hidden = true;
            const pkLabel = pkgTemplate.querySelector('.purchase-mode-pack-label');
            if (pkLabel) pkLabel.textContent = '';
            const un = pkgTemplate.querySelector('.purchase-pkg-unit-name');
            if (un) un.textContent = '';
            const qh = pkgTemplate.querySelector('.purchase-qty-hint');
            if (qh) qh.textContent = '';
            const ch = pkgTemplate.querySelector('.purchase-cost-hint');
            if (ch) ch.textContent = '';
            const sh = pkgTemplate.querySelector('.purchase-selling-hint');
            if (sh) sh.textContent = '';
            const ph = pkgTemplate.querySelector('.purchase-pack-hint');
            if (ph) ph.textContent = '';
            const pv = pkgTemplate.querySelector('.purchase-pkg-value');
            if (pv) pv.textContent = '';
            const pu = pkgTemplate.querySelector('.purchase-pkg-unavailable');
            if (pu) pu.hidden = false;
            tbody.appendChild(pkgTemplate);
        }
        bindRemove(template);
        bindRecalc(template);
        renumber();
        recalcAll();
    });

    recalcAll();
})();
<?php endif; ?>

<?php if ($section === 'inventory'): ?>
(function(){
    const overlay = document.getElementById('itemModalOverlay');
    const modalTitle = document.getElementById('itemModalTitle');
    const form = document.getElementById('itemForm');
    const fId = document.getElementById('if_ItemID');
    const fName = document.getElementById('if_ItemName');
    const fCategory = document.getElementById('if_Category');
    const fStock = document.getElementById('if_QuantityInStock');
    const fUnit = document.getElementById('if_SalesUnit');
    function setUnit(value) {
        if (value && !Array.from(fUnit.options).some(option => option.value === value)) fUnit.add(new Option(value, value));
        fUnit.value = value;
    }
    const saveLabel = document.getElementById('medicineSaveLabel');
    const fPrice = document.getElementById('if_SellingPrice');
    const fReorder = document.getElementById('if_ReorderLevel');
    const fExpiry = document.getElementById('if_ExpiryDate');
    const fPackUnit = document.getElementById('if_DefaultPurchaseUnit');
    const fPackSize = document.getElementById('if_UnitsPerPackage');
    const packHint = document.getElementById('packagingHint');

    function updatePackHint(){
        const unit = fUnit.value || 'unit';
        const pack = (fPackUnit.value || '').trim();
        const size = parseInt(fPackSize.value || '0', 10);
        if (pack === '' || !size || size < 1){ packHint.textContent = ''; return; }
        packHint.textContent = '1 ' + pack + ' = ' + size + ' ' + unit + (size === 1 ? '' : 's');
    }
    [fPackUnit, fPackSize, fUnit].forEach(function(el){ el.addEventListener('input', updatePackHint); el.addEventListener('change', updatePackHint); });

    function openModal(){ overlay.classList.add('show'); }
    function closeModal(){ overlay.classList.remove('show'); }

    document.getElementById('addItemBtn').addEventListener('click', function(){
        form.reset();
        fId.value = '';
        fReorder.value = '10';
        fPackUnit.value = ''; fPackSize.value = ''; packHint.textContent = '';
        modalTitle.textContent = 'Add Medicine';
        saveLabel.textContent = 'Save Medicine';
        document.getElementById('stockQuantityLabel').textContent = 'Opening Quantity';
        openModal();
    });

    document.querySelectorAll('.edit-item-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
            fCategory.value = btn.dataset.category;
            fStock.value = btn.dataset.stock;
            setUnit(btn.dataset.unit);
            fPrice.value = btn.dataset.price;
            fReorder.value = btn.dataset.reorder;
            fExpiry.value = btn.dataset.expiry;
            fPackUnit.value = btn.dataset.packunit || '';
            fPackSize.value = btn.dataset.packsize || '';
            updatePackHint();
            modalTitle.textContent = 'Edit Medicine';
            saveLabel.textContent = 'Update Medicine';
            document.getElementById('stockQuantityLabel').textContent = 'Quantity in Stock';
            openModal();
        });
    });

    document.getElementById('itemModalCloseBtn').addEventListener('click', closeModal);
    document.getElementById('itemModalCancelBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? 'save') === 'save'): ?>
    fId.value = <?= json_encode($oldInventory['ItemID']) ?>;
    fName.value = <?= json_encode($oldInventory['ItemName']) ?>;
    fCategory.value = <?= json_encode($oldInventory['Category']) ?>;
    fStock.value = <?= json_encode($oldInventory['QuantityInStock']) ?>;
    setUnit(<?= json_encode($oldInventory['SalesUnit']) ?>);
    fPrice.value = <?= json_encode($oldInventory['SellingPrice']) ?>;
    fReorder.value = <?= json_encode($oldInventory['ReorderLevel']) ?>;
    fExpiry.value = <?= json_encode($oldInventory['ExpiryDate']) ?>;
    fPackUnit.value = <?= json_encode($oldInventory['DefaultPurchaseUnit']) ?>;
    fPackSize.value = <?= json_encode($oldInventory['UnitsPerPackage']) ?>;
    updatePackHint();
    modalTitle.textContent = fId.value ? 'Edit Medicine' : 'Add Medicine';
    saveLabel.textContent = fId.value ? 'Update Medicine' : 'Save Medicine';
    document.getElementById('stockQuantityLabel').textContent = fId.value ? 'Quantity in Stock' : 'Opening Quantity';
    openModal();
    <?php endif; ?>
})();
<?php endif; ?>

<?php if ($justSaved || $justDeleted || $justVoided): ?>
(function(){
    let message = 'Saved successfully.';
    <?php if ($justDeleted): ?>message = 'Item deleted successfully.';<?php endif; ?>
    <?php if ($justVoided): ?>message = <?= $section === 'purchases' ? json_encode('Purchase order voided successfully.') : json_encode('Sale voided successfully.') ?>;<?php endif; ?>
    <?php if ($justSaved): ?>message = <?= $section === 'pos' ? json_encode('Sale completed successfully.') : ($section === 'purchases' ? json_encode('Purchase order saved successfully.') : json_encode('Item saved successfully.')) ?>;<?php endif; ?>
    showToast(message);
})();
<?php endif; ?>
</script>

</body>
</html>
