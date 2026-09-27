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

/** Receipt-only person formatting; stored names remain unchanged. */
function tdc_receipt_person_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    foreach ($parts as &$part) {
        $clean = rtrim($part, '.');
        if (strcasecmp($clean, 'dr') === 0) {
            $part = 'Dr' . (str_ends_with($part, '.') ? '.' : '');
        } elseif (preg_match('/^[A-Z]{2,5}$/', $part) !== 1) {
            $part = ucfirst(strtolower($part));
        }
    }
    unset($part);
    return implode(' ', $parts);
}

function tdc_receipt_title_case(string $value): string
{
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    foreach ($parts as &$part) {
        if (preg_match('/^[A-Z]{2,5}$/', $part) !== 1) $part = ucfirst(strtolower($part));
    }
    unset($part);
    return implode(' ', $parts);
}

function tdc_receipt_route(string $route): string
{
    $route = trim($route);
    return in_array(strtoupper($route), ['PO', 'IM', 'IV'], true) ? strtoupper($route) : tdc_receipt_title_case($route);
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
    $statusFlags = ['inventory_created', 'inventory_updated', 'inventory_deleted', 'inventory_imported'];
    $query = in_array($flag, $statusFlags, true) ? 'status=' . urlencode($flag) : $flag . '=1';
    header('Location: pharmacy.php?section=' . urlencode($section) . '&' . $query);
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
 * e.g. tdc_next_ref($pdo, 'inventory', 'ItemID', 'ITM') -> "ITM000042".
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
        'SELECT SupplierID FROM purchases WHERE LOWER(SupplierName) = LOWER(:name) ORDER BY PurchaseDate DESC LIMIT 1',
        ['name' => $supplierName]
    );
    if ($existing !== false && $existing !== null) {
        return (int) $existing;
    }

    return (int) tdc_scalar($pdo, 'SELECT COALESCE(MAX(SupplierID), 0) FROM purchases') + 1;
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
    $stmt = $pdo->prepare("SELECT ItemID, ItemName, SellingPrice, QuantityInStock, LastAcquisitionCostPerUnit FROM inventory WHERE ItemID IN ({$placeholders})");
    $stmt->execute(array_values($itemIds));

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['ItemID']] = [
            'name'  => $row['ItemName'],
            'price' => (float) $row['SellingPrice'],
            'stock' => (int) $row['QuantityInStock'],
            'cost'  => tdc_sale_cost_snapshot($row),
        ];
    }
    return $map;
}

// =======================================================================
// SECTION 4 — Validation functions
// =======================================================================

// --- 4A. Inventory -------------------------------------------------------

/** @param array{ItemName:string,QuantityInStock:string,SalesUnit:string,SellingPrice:string,ReorderLevel:string,ExpiryDate:string,DefaultPurchaseUnit?:string,UnitsPerPackage?:string} $input */
function tdc_validate_inventory_form(array $input): array
{
    $errors = [];

    if ($input['ItemName'] === '' || mb_strlen($input['ItemName']) > 150) {
        $errors[] = 'Item name is required (max 150 characters).';
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

/**
 * Normalize a CSV row into the same inventory payload used by the manual form.
 *
 * Duplicate detection is intentionally strict: a CSV row is rejected if the item
 * name already exists in the database or was already seen earlier in the same file.
 */
function tdc_inventory_import_row_to_input(array $row, array $existingNames): array
{
    $itemName = trim((string) ($row['item_name'] ?? $row['medicine_name'] ?? $row['name'] ?? ''));
    // Spreadsheet exports often omit a unit; use the neutral base unit so
    // quantity remains usable while staff can refine it later in Edit.
    $salesUnit = trim((string) ($row['sales_unit'] ?? $row['unit'] ?? 'Piece')) ?: 'Piece';
    $stock = trim((string) ($row['quantity_in_stock'] ?? $row['stock'] ?? '0'));
    if ($stock === '' || (is_numeric($stock) && (float)$stock < 0)) $stock = '0';
    $price = trim((string) ($row['selling_price'] ?? $row['price'] ?? ''));
    $reorder = trim((string) ($row['reorder_level'] ?? $row['reorder'] ?? '10'));
    $expiry = trim((string) ($row['expiry_date'] ?? $row['expiry'] ?? ''));
    $purchaseUnit = trim((string) ($row['default_purchase_unit'] ?? $row['purchase_package'] ?? ''));
    $packSize = trim((string) ($row['units_per_package'] ?? $row['pack_size'] ?? ''));

    $normalized = [
        'ItemName' => $itemName,
        'QuantityInStock' => $stock,
        'SalesUnit' => $salesUnit,
        'SellingPrice' => $price,
        'ReorderLevel' => $reorder,
        'ExpiryDate' => $expiry,
        'DefaultPurchaseUnit' => $purchaseUnit,
        'UnitsPerPackage' => $packSize,
    ];
    $existingKey = mb_strtolower(trim($itemName));
    if ($existingKey !== '' && isset($existingNames[$existingKey]) && is_string($existingNames[$existingKey])) {
        $normalized['ItemID'] = $existingNames[$existingKey];
    }

    $errors = tdc_validate_inventory_form($normalized);
    $lookupName = mb_strtolower(trim($itemName));
    if ($itemName === '') {
        $errors[] = 'Item name is required.';
    }

    return ['errors' => $errors, 'input' => $normalized];
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
    if ($discountRaw !== '' && (!is_numeric($discountRaw) || !is_finite((float) $discountRaw) || (float) $discountRaw < 0)) {
        $errors[] = 'Discount must be a valid non-negative number.';
    }
    $vatRaw = trim((string) ($input['VATAmount'] ?? ''));
    if ($vatRaw !== '' && (!is_numeric($vatRaw) || !is_finite((float) $vatRaw) || (float) $vatRaw < 0)) {
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
        if ($unitPrice === '' || !is_numeric($unitPrice) || !is_finite((float) $unitPrice) || (float) $unitPrice <= 0) {
            $errors[] = "Unit price at line {$lineNo} must be greater than zero.";
        }

        $sellingPrice = trim((string) ($input['SellingPrice'][$i] ?? ''));
        if ($sellingPrice === '' || !is_numeric($sellingPrice) || !is_finite((float) $sellingPrice) || (float) $sellingPrice <= 0) {
            $errors[] = "Selling price at line {$lineNo} must be greater than zero.";
        }

        $conversion = trim((string) ($input['ConversionFactor'][$i] ?? ''));
        if ($conversion !== '' && (!is_numeric($conversion) || !is_finite((float) $conversion) || (float) $conversion < 1 || floor((float) $conversion) !== (float) $conversion)) {
            $errors[] = "Conversion factor at line {$lineNo} must be a positive whole number.";
        }

        $expiry = trim((string) ($input['ExpiryDate'][$i] ?? ''));
        if ($expiry !== '' && !tdc_is_valid_date($expiry)) {
            $errors[] = "Expiry date at line {$lineNo} is not a valid date.";
        } elseif ($expiry !== '' && $purchaseDate !== '' && $expiry < $purchaseDate) {
            $errors[] = "Expiry date at line {$lineNo} cannot be before the purchase date.";
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
    $discountType = (string) ($input['DiscountType'] ?? 'None');
    $discountValue = is_numeric($input['DiscountValue'] ?? null) ? (float) $input['DiscountValue'] : 0.0;
    try {
        $adjustment = tdc_calculate_bill_adjustment($authoritativeTotal, $discountType, $discountValue, 0.0);
        if ($adjustment['discount_amount'] > 0 && trim((string) ($input['DiscountReason'] ?? '')) === '') {
            $errors[] = 'A discount reason is required.';
        }
        if ($input['AmountPaid'] !== '' && is_numeric($input['AmountPaid']) && (float) $input['AmountPaid'] > $adjustment['final_amount']) {
            $errors[] = 'Amount paid cannot exceed the final sale total after discount.';
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
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
            'UPDATE inventory SET ItemName = :ItemName,
                QuantityInStock = :QuantityInStock, SalesUnit = :SalesUnit,
                SellingPrice = :SellingPrice, ReorderLevel = :ReorderLevel, ExpiryDate = :ExpiryDate, DefaultPurchaseUnit = :DefaultPurchaseUnit, UnitsPerPackage = :UnitsPerPackage
             WHERE ItemID = :id'
        );
        $stmt->execute($params);
        return;
    }

    $params['ItemID'] = tdc_next_ref($pdo, 'inventory', 'ItemID', 'ITM');
    $stmt = $pdo->prepare(
        'INSERT INTO inventory (ItemID, ItemName, QuantityInStock, SalesUnit, SellingPrice, ReorderLevel, ExpiryDate, DefaultPurchaseUnit, UnitsPerPackage)
         VALUES (:ItemID, :ItemName, :QuantityInStock, :SalesUnit, :SellingPrice, :ReorderLevel, :ExpiryDate, :DefaultPurchaseUnit, :UnitsPerPackage)'
    );
    $stmt->execute($params);
}

/** @return string[] error messages; empty on success */
function tdc_delete_inventory_item(PDO $pdo, string $id): array
{
    // Inventory has no database foreign keys, so permanent removal must clear
    // every dependent operational and financial row explicitly and atomically.
    $itemStmt = $pdo->prepare('SELECT ItemName FROM inventory WHERE ItemID = ? LIMIT 1');
    $itemStmt->execute([$id]);
    $itemName = $itemStmt->fetchColumn();
    if ($itemName === false) return ['The selected inventory item no longer exists.'];

    $oldInTx = $pdo->inTransaction();
    try {
        if (!$oldInTx) $pdo->beginTransaction();
        $name = trim((string) $itemName);
        $match = function (string $table, string $idColumn) use ($pdo, $id, $name): array {
            $sql = "SELECT {$idColumn} FROM {$table} WHERE ItemID = ? OR ( (ItemID IS NULL OR ItemID = '') AND LOWER(TRIM(ItemName)) = LOWER(TRIM(?)) )";
            $s = $pdo->prepare($sql); $s->execute([$id, $name]);
            return array_values(array_filter(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN)), static fn($v) => $v !== ''));
        };
        $saleIds = $match('pharmacysales', 'SaleID');
        $purchaseIds = $match('purchases', 'PurchaseID');
        $saleRefs = array_values(array_unique(array_merge($saleIds, array_map(static fn($v) => explode('-', $v, 2)[0], $saleIds))));
        $purchaseRefs = array_values(array_unique(array_merge($purchaseIds, array_map(static fn($v) => explode('-', $v, 2)[0], $purchaseIds))));

        // Prescription and payment references are collected before their parent rows disappear.
        $rxRefs = [];
        if ($saleRefs) {
            $m = implode(',', array_fill(0, count($saleRefs), '?'));
            $q = $pdo->prepare("SELECT DISTINCT PrescriptionID FROM prescriptions WHERE PharmacySaleReference IN ($m)");
            $q->execute($saleRefs);
            $rxIds = array_values(array_filter(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN))));
            $rxRefs = array_values(array_unique(array_merge($rxIds, array_map(static fn($v) => explode('-', $v, 2)[0], $rxIds))));
        }
        $allRefs = array_values(array_unique(array_merge($saleRefs, $purchaseRefs, $rxRefs)));
        if ($allRefs) {
            $m = implode(',', array_fill(0, count($allRefs), '?'));
            $pdo->prepare("DELETE FROM accounting WHERE ReferenceID IN ($m)")->execute($allRefs);
        }
        if ($saleRefs) {
            $m = implode(',', array_fill(0, count($saleRefs), '?'));
            $pdo->prepare("DELETE FROM payments WHERE SaleReference IN ($m)")->execute($saleRefs);
            $pdo->prepare("DELETE FROM prescriptions WHERE PharmacySaleReference IN ($m)")->execute($saleRefs);
        }
        if ($purchaseRefs) {
            $m = implode(',', array_fill(0, count($purchaseRefs), '?'));
            $pdo->prepare("DELETE FROM payments WHERE PurchaseReference IN ($m)")->execute($purchaseRefs);
        }
        if ($rxRefs) {
            $m = implode(',', array_fill(0, count($rxRefs), '?'));
            $pdo->prepare("DELETE FROM payments WHERE PrescriptionReference IN ($m)")->execute($rxRefs);
        }
        $pdo->prepare("DELETE FROM pharmacysales WHERE ItemID = ? OR ((ItemID IS NULL OR ItemID = '') AND LOWER(TRIM(ItemName)) = LOWER(TRIM(?)))")->execute([$id, $name]);
        $pdo->prepare("DELETE FROM purchases WHERE ItemID = ? OR ((ItemID IS NULL OR ItemID = '') AND LOWER(TRIM(ItemName)) = LOWER(TRIM(?)))")->execute([$id, $name]);
        $deleted = $pdo->prepare('DELETE FROM inventory WHERE ItemID = ?');
        $deleted->execute([$id]);
        if ($deleted->rowCount() !== 1) throw new RuntimeException('The inventory item could not be removed.');
        if (!$oldInTx) $pdo->commit();
        try { tdc_audit($pdo, 'inventory.deleted', 'inventory', $id, 'Inventory item and linked history permanently deleted.', ['item_name' => $name, 'sales' => count($saleIds), 'purchases' => count($purchaseIds)]); } catch (Throwable $auditError) { error_log('[PHARMACY] inventory deletion audit failed: ' . $auditError->getMessage()); }
        return [];
    } catch (Throwable $e) {
        if (!$oldInTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[PHARMACY] inventory deletion failed: ' . $e->getMessage());
        return ['The inventory item and linked history could not be cleared. No records were changed.'];
    }
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
        $byId = $pdo->prepare('SELECT ItemID FROM inventory WHERE ItemID = :id LIMIT 1');
        $byId->execute(['id' => $lineItemId]);
        $existingId = $byId->fetchColumn();
    }
    if ($existingId === false) {
        $stmt = $pdo->prepare('SELECT ItemID FROM inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name)) LIMIT 1');
        $stmt->execute(['name' => $line['ItemName']]);
        $existingId = $stmt->fetchColumn();
    }

    $addQty = (int) round($line['Quantity'] * $line['ConversionFactor']);
    $costPerUnit = tdc_purchase_cost_per_sales_unit((float) $line['UnitPrice'], (float) $line['ConversionFactor']);

    if ($existingId !== false) {
        $upd = $pdo->prepare(
            'UPDATE inventory SET QuantityInStock = QuantityInStock + :addQty, Category = COALESCE(:Category, Category),
                SalesUnit = COALESCE(:SalesUnit, SalesUnit), SellingPrice = :SellingPrice,
                LastAcquisitionCostPerUnit = :LastAcquisitionCostPerUnit,
                SupplierID = :SupplierID,
                ExpiryDate = CASE WHEN ExpiryDate IS NULL THEN :ExpiryDate ELSE ExpiryDate END
             WHERE ItemID = :id'
        );
        $upd->execute([
            'addQty'       => $addQty,
            'Category'     => $line['Category'] !== '' ? $line['Category'] : null,
            'SalesUnit'    => $line['SalesUnit'] !== '' ? $line['SalesUnit'] : null,
            'SellingPrice' => $line['SellingPrice'],
            'LastAcquisitionCostPerUnit' => $costPerUnit,
            'SupplierID'   => $supplierId,
            'ExpiryDate'   => $line['ExpiryDate'] !== '' ? $line['ExpiryDate'] : null,
            'id'           => $existingId,
        ]);
        return;
    }

    $itemId = tdc_next_ref($pdo, 'inventory', 'ItemID', 'ITM');
    $ins = $pdo->prepare(
        'INSERT INTO inventory (ItemID, Category, ItemName, QuantityInStock, SalesUnit, SellingPrice, LastAcquisitionCostPerUnit, ReorderLevel, ExpiryDate, SupplierID)
         VALUES (:ItemID, :Category, :ItemName, :QuantityInStock, :SalesUnit, :SellingPrice, :LastAcquisitionCostPerUnit, 10, :ExpiryDate, :SupplierID)'
    );
    $ins->execute([
        'ItemID'          => $itemId,
        'Category'        => $line['Category'] !== '' ? $line['Category'] : null,
        'ItemName'        => $line['ItemName'],
        'QuantityInStock' => $addQty,
        'SalesUnit'       => $line['SalesUnit'] !== '' ? $line['SalesUnit'] : null,
        'SellingPrice'    => $line['SellingPrice'],
        'LastAcquisitionCostPerUnit' => $costPerUnit,
        'ExpiryDate'      => $line['ExpiryDate'] !== '' ? $line['ExpiryDate'] : null,
        'SupplierID'      => $supplierId,
    ]);
}

// --- 5B. Purchases (append-only: create + void) ---------------------------

/** @return string the generated PO base reference, e.g. "PO000042" */
function tdc_save_purchase(PDO $pdo, array $input): string
{
    $supplierId = tdc_find_or_create_supplier_id($pdo, $input['SupplierName']);
    $base       = tdc_next_bill_base($pdo, 'purchases', 'PurchaseID', 'PO');

    $lines       = [];
    $totalAmount = 0.0;
    foreach ($input['ItemName'] as $i => $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        // Reuse the item's configured packaging when a purchase is submitted
        // from an older form/import that omits the optional unit fields.
        $itemIdInput = trim((string) ($input['ItemID'][$i] ?? ''));
        $defaults = null;
        try {
            if ($itemIdInput !== '') {
                $d = $pdo->prepare('SELECT SalesUnit, DefaultPurchaseUnit, UnitsPerPackage FROM inventory WHERE ItemID = :id LIMIT 1');
                $d->execute(['id' => $itemIdInput]);
                $defaults = $d->fetch() ?: null;
            }
            if (!$defaults) {
                $d = $pdo->prepare('SELECT SalesUnit, DefaultPurchaseUnit, UnitsPerPackage FROM inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name)) LIMIT 1');
                $d->execute(['name' => $name]);
                $defaults = $d->fetch() ?: null;
            }
        } catch (Throwable $e) {
            // Older installations do not have the optional inventory packaging columns.
            $defaults = null;
        }
        $postedFactor = trim((string) ($input['ConversionFactor'][$i] ?? ''));
        $postedPurchaseUnit = trim((string) ($input['PurchaseUnit'][$i] ?? ''));
        $postedSalesUnit = trim((string) ($input['SalesUnit'][$i] ?? ''));
        $configuredFactor = $defaults && (int) ($defaults['UnitsPerPackage'] ?? 0) > 0
            ? (int) $defaults['UnitsPerPackage'] : 0;
        $conversionFactor = $postedFactor !== '' ? (float) $postedFactor : ($configuredFactor > 0 ? (float) $configuredFactor : 1.00);
        $purchaseUnit = $postedPurchaseUnit !== '' ? $postedPurchaseUnit : (string) ($defaults['DefaultPurchaseUnit'] ?? '');
        $salesUnit = $postedSalesUnit !== '' ? $postedSalesUnit : (string) ($defaults['SalesUnit'] ?? '');
        $qty       = (int) ($input['Quantity'][$i] ?? 0);
        $unitPrice = round((float) ($input['UnitPrice'][$i] ?? 0), 2);
        $lineTotal = round($qty * $unitPrice, 2);
        $totalAmount += $lineTotal;

        $lines[] = [
            'ItemID'           => $itemIdInput,
            'ItemName'         => $name,
            'Category'         => trim((string) ($input['Category'][$i] ?? '')),
            'PurchaseUnit'     => $purchaseUnit,
            'ConversionFactor' => $conversionFactor,
            'SalesUnit'        => $salesUnit,
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
            'INSERT INTO purchases (PurchaseID, SupplierID, ItemID, SupplierName, SupplierPhone, ReferenceNumber, Category, ItemName,
                Quantity, MinimumQuantity, PurchaseUnit, ConversionFactor, SalesUnit, UnitPrice, SellingPrice, ExpiryDate,
                TotalAmount, AmountPaid, DueBalance, Discount, VATAmount, PurchaseDate)
             VALUES (:PurchaseID, :SupplierID, :ItemID, :SupplierName, :SupplierPhone, :ReferenceNumber, :Category, :ItemName,
                :Quantity, 10, :PurchaseUnit, :ConversionFactor, :SalesUnit, :UnitPrice, :SellingPrice, :ExpiryDate,
                :TotalAmount, :AmountPaid, :DueBalance, :Discount, :VATAmount, :PurchaseDate)'
        );

        $line = 0;
        foreach ($lines as $l) {
            $line++;
            $insert->execute([
                'PurchaseID'       => $base . '-' . str_pad((string) $line, 2, '0', STR_PAD_LEFT),
                'SupplierID'       => $supplierId,
                'ItemID'           => $l['ItemID'] !== '' ? $l['ItemID'] : null,
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
                'ExpiryDate'       => $l['ExpiryDate'] !== '' ? $l['ExpiryDate'] : null,
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
    $stmt = $pdo->prepare('SELECT ItemName, Quantity, ConversionFactor FROM purchases WHERE PurchaseID LIKE :pattern');
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
            'SELECT QuantityInStock FROM inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name)) LIMIT 1',
            ['name' => $l['ItemName']]
        );
        if ($current !== false && (int) $current < $reduceBy) {
            $name = $l['ItemName'];
            return ["Cannot void: \"{$name}\" only has {$current} in stock, less than the {$reduceBy} originally received. Adjust inventory manually first."];
        }
    }

    $pdo->beginTransaction();
    try {
        $reverse = $pdo->prepare('UPDATE inventory SET QuantityInStock = QuantityInStock - :qty WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(:name))');
        foreach ($lines as $l) {
            $reduceBy = (int) round($l['Quantity'] * $l['ConversionFactor']);
            $reverse->execute(['qty' => $reduceBy, 'name' => $l['ItemName']]);
        }

        $del = $pdo->prepare('DELETE FROM purchases WHERE PurchaseID LIKE :pattern');
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
    $patientId = null;
    $visitId = null;
    $customerName = trim((string) ($input['CustomerName'] ?? ''));
    $customerPhone = trim((string) ($input['CustomerPhone'] ?? ''));
    $selectedPatientId = (int) ($input['PatientID'] ?? 0);
    if ($selectedPatientId > 0) {
        $patientStmt = $pdo->prepare('SELECT PatientID, PatientName, PatientPhone FROM patients WHERE PatientID=? LIMIT 1');
        $patientStmt->execute([$selectedPatientId]);
        $patient = $patientStmt->fetch();
        if (!$patient) throw new RuntimeException('The selected registered patient was not found.');
        $patientId = (int) $patient['PatientID'];
        $customerName = (string) $patient['PatientName'];
        $customerPhone = (string) ($patient['PatientPhone'] ?? '');
        $selectedVisitId = (int) ($input['VisitID'] ?? 0);
        if ($selectedVisitId > 0) {
            $visitStmt = $pdo->prepare('SELECT VisitID FROM visits WHERE VisitID=? AND PatientID=? AND QueueStatus<>\'Cancelled\' LIMIT 1');
            $visitStmt->execute([$selectedVisitId, $patientId]);
            if (!$visitStmt->fetchColumn()) throw new RuntimeException('The selected visit does not belong to the registered patient.');
            $visitId = $selectedVisitId;
        }
    } elseif ((int) ($input['VisitID'] ?? 0) > 0) {
        throw new RuntimeException('Select a registered patient before linking a visit.');
    }
    $paymentMethod = trim((string) ($input['PaymentMethod'] ?? ''));
    if ($paymentMethod === '') {
        $paymentMethod = 'Cash';
    }

    $base = tdc_next_bill_base($pdo, 'pharmacysales', 'SaleID', 'POS');
    // Older voids deleted sale rows. Never reuse a reference still owned by the ledger.
    $lastLedger=(int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(SaleReference,4) AS UNSIGNED)),0) FROM payments WHERE PaymentType='POS' AND SaleReference REGEXP '^POS[0-9]+$'")->fetchColumn();
    $base='POS'.str_pad((string)max((int)substr($base,3),$lastLedger+1),6,'0',STR_PAD_LEFT);

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
            'CostPerUnitSnapshot' => $stockByItemId[$itemId]['cost'],
            'LineCost' => $stockByItemId[$itemId]['cost'] === null ? null : round($qty * $stockByItemId[$itemId]['cost'], 2),
        ];
    }

    $adjustment = tdc_calculate_bill_adjustment(
        $totalAmount,
        (string) ($input['DiscountType'] ?? 'None'),
        (float) ($input['DiscountValue'] ?? 0),
        0.0
    );
    $finalAmount   = $adjustment['final_amount'];
    $amountPaid    = round((float) ($input['AmountPaid'] !== '' ? $input['AmountPaid'] : $finalAmount), 2);
    $dueBalance    = round($finalAmount - $amountPaid, 2);
    $paymentStatus = $amountPaid <= 0 ? 'Unpaid' : ($amountPaid >= $finalAmount ? 'Paid' : 'Partial');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO pharmacysales (SaleID, ItemID, ItemName, Quantity, UnitPrice, LineTotal, CostPerUnitSnapshot, LineCost,
                TotalAmount, AmountPaid, DueBalance, PaymentStatus, CustomerName, CustomerPhone, SoldBy)
             VALUES (:SaleID, :ItemID, :ItemName, :Quantity, :UnitPrice, :LineTotal, :CostPerUnitSnapshot, :LineCost,
                :TotalAmount, :AmountPaid, :DueBalance, :PaymentStatus, :CustomerName, :CustomerPhone, :SoldBy)'
        );

        // Atomic, race-safe decrement: the WHERE clause makes this a
        // no-op (rowCount 0) if concurrent stock movement has already
        // dropped the item below what this line needs, so two
        // simultaneous sales can never oversell the same item.
        $decrement = $pdo->prepare(
            'UPDATE inventory SET QuantityInStock = QuantityInStock - :qty
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
                'CostPerUnitSnapshot' => $l['CostPerUnitSnapshot'],
                'LineCost'      => $l['LineCost'],
                'TotalAmount'   => $finalAmount,
                'AmountPaid'    => $amountPaid,
                'DueBalance'    => $dueBalance,
                'PaymentStatus' => $paymentStatus,
                'CustomerName'  => $customerName !== '' ? $customerName : null,
                'CustomerPhone' => $customerPhone !== '' ? $customerPhone : null,
                'SoldBy'        => $_SESSION['user_id'] ?? null,
            ]);

            $decrement->execute(['qty' => $l['Quantity'], 'id' => $l['ItemID'], 'qtyCheck' => $l['Quantity']]);
            if ($decrement->rowCount() === 0) {
                throw new RuntimeException("Insufficient stock for \"{$l['ItemName']}\" — please refresh and try again.");
            }
        }

        if ($patientId !== null) {
            $pdo->prepare('UPDATE pharmacysales SET PatientID=?, VisitID=? WHERE SaleID LIKE ?')->execute([$patientId, $visitId, $base . '-%']);
        }
        tdc_save_bill_adjustment(
            $pdo,
            'pos',
            $base,
            $totalAmount,
            $adjustment['discount_type'],
            $adjustment['discount_value'],
            0.0,
            $amountPaid,
            (string) ($input['DiscountReason'] ?? ''),
            (string) ($input['AdjustmentNote'] ?? '')
        );
        $paymentReference = tdc_workflow_record_payment($pdo, $patientId ?? 0, 'POS', $amountPaid, (int) ($_SESSION['user_id'] ?? 0), [
            'SaleReference' => $base,
            'VisitID' => $visitId,
            'PaymentMethod' => $paymentMethod,
        ]);

        if ($paymentReference) tdc_workflow_post_revenue($pdo, 'REV-PHARM', 'Pharmacy Revenue', $base, 'Point of sale collection ' . $base, $amountPaid, $paymentMethod);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $base;
}

function tdc_collect_pos_payment(PDO $pdo, string $base, float $collection, string $paymentMethod): array
{
    if ($base === '' || !preg_match('/^POS[0-9]+$/', $base)) throw new RuntimeException('Invalid POS sale selected.');
    if (!is_finite($collection) || $collection <= 0) throw new RuntimeException('Payment amount must be greater than zero.');
    if ($paymentMethod === '') throw new RuntimeException('Select a payment method.');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM pharmacysales WHERE SaleID LIKE ? ORDER BY SaleID FOR UPDATE');
        $stmt->execute([$base . '-%']);
        $lines = $stmt->fetchAll();
        if (!$lines) throw new RuntimeException('POS sale not found.');
        if (tdc_has_column($pdo, 'pharmacysales', 'SaleStatus') && in_array('Voided', array_column($lines, 'SaleStatus'), true)) throw new RuntimeException('Voided sales cannot receive payment.');

        $prescription = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE PharmacySaleReference=?');
        $prescription->execute([$base]);
        if ((int) $prescription->fetchColumn() > 0) throw new RuntimeException('Prescription pharmacy bills are paid at Reception.');

        $total = round((float) $lines[0]['TotalAmount'], 2);
        $paid = tdc_payments_confirmed_total($pdo, 'SaleReference', $base);
        $due = max(0.0, round($total - $paid, 2));
        if ($due <= 0) throw new RuntimeException('This POS sale has no outstanding balance.');
        if ($collection > $due + 0.00001) throw new RuntimeException('Payment cannot exceed the outstanding balance.');

        $paymentReference = tdc_workflow_record_payment($pdo, (int) ($lines[0]['PatientID'] ?? 0), 'POS', round($collection, 2), (int) ($_SESSION['user_id'] ?? 0), [
            'SaleReference' => $base,
            'VisitID' => (int) ($lines[0]['VisitID'] ?? 0) ?: null,
            'PaymentMethod' => $paymentMethod,
        ]);
        tdc_payments_sync_source($pdo, ['PaymentType' => 'POS', 'SaleReference' => $base, 'PatientID' => (int) ($lines[0]['PatientID'] ?? 0)]);
        if ($paymentReference) tdc_workflow_post_revenue($pdo, 'REV-PHARM', 'Pharmacy Revenue', $paymentReference, 'Point of sale collection ' . $base, round($collection, 2), $paymentMethod);
        $pdo->commit();
        return ['reference' => (string) $paymentReference, 'amount' => round($collection, 2), 'sale' => $base];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** @return string[] error messages; empty on success */
function tdc_void_sale(PDO $pdo, string $base): array
{
    $stage = 'SALE_LOCK';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT ItemID, Quantity, SaleStatus, AmountPaid FROM pharmacysales WHERE SaleID LIKE :pattern ORDER BY SaleID FOR UPDATE');
        $stmt->execute(['pattern' => $base . '-%']);
        $lines = $stmt->fetchAll();
        if (empty($lines)) throw new RuntimeException('Sale not found.');

        $linkedPrescription=$pdo->prepare('SELECT PrescriptionID FROM prescriptions WHERE PharmacySaleReference=? LIMIT 1 FOR UPDATE');
        $linkedPrescription->execute([$base]);
        if($linkedPrescription->fetchColumn()) { $pdo->rollBack(); return ['Prescription void requires supported pharmacy correction workflow.']; }
        foreach($lines as $line) if($line['SaleStatus']==='Voided') { $pdo->rollBack(); return ['This sale has already been voided.']; }

        $stage = 'INVENTORY_RESTORE';
        $restock = $pdo->prepare('UPDATE inventory SET QuantityInStock = QuantityInStock + :qty WHERE ItemID = :id');
        foreach ($lines as $l) {
            $restock->execute(['qty' => (int) $l['Quantity'], 'id' => $l['ItemID']]);
        }

        $stage = 'ACCOUNTING_REVERSAL';
        $paymentQuery=$pdo->prepare("SELECT p.*,p.Amount+COALESCE((SELECT SUM(r.Amount) FROM payments r WHERE r.ReversalOfPaymentID=p.PaymentID AND r.PaymentStatus='Confirmed'),0) AS Remaining FROM payments p WHERE p.PaymentType='POS' AND p.SaleReference=? AND p.PaymentStatus='Confirmed' AND p.Amount>0 AND p.ReversalOfPaymentID IS NULL FOR UPDATE");
        $paymentQuery->execute([$base]);
        $baseReversedByLedger=false;
        foreach($paymentQuery->fetchAll() as $payment) {
            if($payment['PaymentReference']===$base) $baseReversedByLedger=true;
            if((float)$payment['Remaining']>0) tdc_payments_reverse($pdo,(int)$payment['PaymentID'],'POS sale void '.$base,(float)$payment['Remaining'],false);
        }
        if (!$baseReversedByLedger && tdc_has_column($pdo, 'accounting', 'ReferenceID')) {
            $accountingColumns = ['EntryID', 'AccountID', 'AccountName', 'AccountType', 'BookType', 'ReferenceID', 'Description', 'Debit', 'Credit', 'Balance'];
            $missingAccountingColumns = array_values(array_filter($accountingColumns, static fn(string $column): bool => !tdc_has_column($pdo, 'accounting', $column)));
            if ($missingAccountingColumns !== []) {
                throw new RuntimeException('Accounting reversal schema unavailable: ' . implode(', ', $missingAccountingColumns));
            }
            $original = $pdo->prepare('SELECT EntryID, AccountID, AccountName, AccountType, BookType, Description, Debit, Credit, Balance FROM accounting WHERE ReferenceID = ? FOR UPDATE');
            $original->execute([$base]);
            $entries = $original->fetchAll();
            if ($entries !== []) {
                $reverse = $pdo->prepare('INSERT INTO accounting (EntryID, AccountID, AccountName, AccountType, BookType, ReferenceID, Description, Debit, Credit, Balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($entries as $entry) {
                    $entryId = tdc_workflow_next_reference($pdo, 'accounting', 'EntryID', 'JRN');
                    $reverse->execute([
                        $entryId,
                        $entry['AccountID'],
                        $entry['AccountName'],
                        $entry['AccountType'],
                        $entry['BookType'],
                        $base . '-VOID',
                        'Reversal of ' . $base,
                        (float) $entry['Credit'],
                        (float) $entry['Debit'],
                        ((float) $entry['Credit']) - ((float) $entry['Debit'])
                    ]);
                }
            }
        }

        $stage = 'SALE_VOID';
        $pdo->prepare("UPDATE pharmacysales SET SaleStatus='Voided',AmountPaid=0,DueBalance=0 WHERE SaleID LIKE ?")->execute([$base.'-%']);

        $stage = 'COMMIT';
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $sqlState = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : (string) $e->getCode();
        $diagnostic = [
            'sale' => $base,
            'stage' => $stage,
            'sqlstate' => $sqlState !== '' ? $sqlState : 'N/A',
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ];
        $GLOBALS['tdc_void_diagnostic'] = $diagnostic;
        error_log('[PHARMACY][POS][VOID] sale=' . $base . ' stage=' . $stage . ' exception=' . $diagnostic['exception'] . ' sqlstate=' . $diagnostic['sqlstate'] . ' message=' . $diagnostic['message']);
        throw $e;
    }

    return [];
}

/** Void a pharmacy prescription safely, restoring dispensed stock and reversing receipts. */
function tdc_void_prescription(PDO $pdo, string $base): array
{
    if ($base === '' || !preg_match('/^RX[0-9]+$/', $base)) return ['Invalid prescription selected.'];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE PrescriptionID LIKE ? ORDER BY PrescriptionID FOR UPDATE");
        $stmt->execute([$base . '-%']);
        $lines = $stmt->fetchAll();
        if (!$lines) throw new RuntimeException('Prescription not found.');
        if ((string) ($lines[0]['Status'] ?? '') === 'Cancelled') throw new RuntimeException('This prescription has already been voided.');

        // Only dispensed prescriptions changed stock. Pending prescriptions are simply cancelled.
        if ((string) ($lines[0]['Status'] ?? '') === 'Dispensed') {
            $stock = $pdo->prepare('SELECT ItemID FROM inventory WHERE LOWER(TRIM(ItemName)) = LOWER(TRIM(?)) LIMIT 1 FOR UPDATE');
            $restore = $pdo->prepare('UPDATE inventory SET QuantityInStock = QuantityInStock + ? WHERE ItemID = ?');
            foreach ($lines as $line) {
                $stock->execute([(string) $line['MedicationName']]);
                $itemId = $stock->fetchColumn();
                if ($itemId === false) throw new RuntimeException('Inventory item for "' . (string) $line['MedicationName'] . '" no longer exists; restore it before voiding.');
                $restore->execute([max(1, (int) $line['Quantity']), $itemId]);
            }
        }

        $payments = $pdo->prepare("SELECT PaymentID, Amount FROM payments WHERE PaymentType='Pharmacy' AND PrescriptionReference=? AND PaymentStatus='Confirmed' AND Amount>0 AND ReversalOfPaymentID IS NULL FOR UPDATE");
        $payments->execute([$base]);
        foreach ($payments->fetchAll() as $payment) {
            tdc_payments_reverse($pdo, (int) $payment['PaymentID'], 'Prescription void ' . $base, null, false);
        }
        $update = $pdo->prepare("UPDATE prescriptions SET Status='Cancelled', AmountPaid=0, DueBalance=0 WHERE PrescriptionID LIKE ? AND Status <> 'Cancelled'");
        $update->execute([$base . '-%']);
        tdc_reconcile_patient_due_balance($pdo, (int) ($lines[0]['PatientID'] ?? 0));
        tdc_audit($pdo, 'prescription.voided', 'prescriptions', $base, 'Prescription voided; stock and receipts reversed.', ['status' => $lines[0]['Status'] ?? null]);
        $pdo->commit();
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PHARMACY][PRESCRIPTION] void failed: ' . $e->getMessage());
        return [$e instanceof RuntimeException ? $e->getMessage() : 'The prescription could not be voided. No records were changed.'];
    }
}

function tdc_void_sanitized_message(string $message): string
{
    $message = preg_replace('/(?i)(password|passwd|secret|token)\s*[=:]\s*[^\s,;]+/', '$1=[redacted]', $message);
    $message = preg_replace('/[A-Za-z]:\\\\[^\r\n]+|\/(?:home|var|srv|www|usr)\/[^\r\n]+/', '[path redacted]', (string) $message);
    return trim((string) $message);
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
$pharmacyHasSaleStatus = tdc_has_column($pdo, 'pharmacysales', 'SaleStatus');
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/pharmacy-costing.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/data-transfer.php';
require_once __DIR__ . '/../includes/legacy-pos.php';
$paymentMethods = tdc_payment_methods($pdo);
$paymentMethodNames = array_column($paymentMethods, 'MethodName');
$clinicSettings = [];
try {
    foreach ($pdo->query('SELECT SettingKey, SettingValue FROM clinicsettings')->fetchAll() as $setting) {
        $clinicSettings[(string) $setting['SettingKey']] = (string) ($setting['SettingValue'] ?? '');
    }
} catch (Throwable $e) {
    // The fallback identity keeps receipts usable on older schemas.
}
$receiptClinicName = trim($clinicSettings['clinic_name'] ?? ($clinicSettings['ClinicName'] ?? '')) ?: 'Tarey Derma Clinic';
$receiptClinicAddress = trim($clinicSettings['address'] ?? ($clinicSettings['ClinicAddress'] ?? '')) ?: 'Degmada Hodan, Isgoyska Al-barako';
$receiptClinicPhone = trim($clinicSettings['phone'] ?? ($clinicSettings['PhoneNumbers'] ?? '')) ?: '615019253';

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
    'CustomerName' => '', 'CustomerPhone' => '', 'PatientID' => 0, 'VisitID' => 0, 'DiscountType' => 'None', 'DiscountValue' => '', 'DiscountReason' => '', 'AdjustmentNote' => '', 'AmountPaid' => '', 'PaymentMethod' => 'Cash',
    'ItemID' => [], 'Quantity' => [], 'UnitPrice' => [],
];

$oldPurchase = [
    'SupplierName' => '', 'SupplierPhone' => '', 'AmountPaid' => '', 'PurchaseDate' => date('Y-m-d'),
    'ReferenceNumber' => '', 'Discount' => '', 'VATAmount' => '',
    'ItemID' => [], 'ItemName' => [], 'Category' => [], 'PurchaseUnit' => [], 'ConversionFactor' => [],
    'SalesUnit' => [], 'Quantity' => [], 'UnitPrice' => [], 'SellingPrice' => [], 'ExpiryDate' => [],
];

$oldInventory = [
    'ItemID' => '', 'ItemName' => '', 'QuantityInStock' => '',
    'SalesUnit' => '', 'SellingPrice' => '', 'ReorderLevel' => '10', 'ExpiryDate' => '',
    'DefaultPurchaseUnit' => '', 'UnitsPerPackage' => '',
];

$posShowForm      = false;
$posHistory       = ($section === 'pos' && ((string) ($_GET['view_mode'] ?? '') === 'history' || (!isset($_GET['new']) && !isset($_GET['view']))));
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
            $prescriptionAction = (string) ($_POST['prescription_action'] ?? $formAction);
            if ($prescriptionAction !== 'dispense') {
                $errors[] = 'Unsupported prescription action.';
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE PrescriptionID LIKE ? AND Status='Pending' ORDER BY PrescriptionID FOR UPDATE");
                    $stmt->execute([$base . '-%']);
                    $lines = $stmt->fetchAll();
                    if (!$lines) throw new RuntimeException('Prescription is no longer pending or has already been dispensed.');

                    $inventoryLines = [];
                    foreach ($lines as $line) {
                        $itemStmt = $pdo->prepare('SELECT * FROM inventory WHERE LOWER(TRIM(ItemName))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE');
                        $itemStmt->execute([$line['MedicationName']]);
                        $item = $itemStmt->fetch();
                        $quantity = max(1, (int) $line['Quantity']);
                        if (!$item) throw new RuntimeException('No inventory item matches ' . $line['MedicationName'] . '.');
                        $inventoryLines[] = [$item, $quantity, $line['MedicationName']];
                    }
                    $total = (float)$lines[0]['TotalAmount'];
                    if ($total <= 0) throw new RuntimeException('Financial data is unavailable for this historical prescription. Reception must establish its bill before dispensing.');

                    foreach ($inventoryLines as [$item, $quantity, $medicine]) {
                        if ((int) $item['QuantityInStock'] < $quantity) throw new RuntimeException('Insufficient stock for ' . $medicine . '.');
                    }

                    // Dispensing never accepts money; Reception owns prescription collection.
                    $paid = tdc_payments_confirmed_total($pdo, 'PrescriptionReference', $base);
                    if ($paid > $total) throw new RuntimeException('Confirmed payment cannot exceed the prescription total.');
                    $due = round($total - $paid, 2);

                    $stock = $pdo->prepare('UPDATE inventory SET QuantityInStock=QuantityInStock-? WHERE ItemID=? AND QuantityInStock>=?');
                    foreach ($inventoryLines as $index=>[$item, $quantity, $medicine]) {
                        $stock->execute([$quantity, $item['ItemID'], $quantity]);
                        if ($stock->rowCount() !== 1) throw new RuntimeException('Stock changed while dispensing. Please retry.');
                        if(tdc_has_column($pdo,'prescriptions','CostPerUnitSnapshot')) {
                            $pdo->prepare('UPDATE prescriptions SET CostPerUnitSnapshot=? WHERE PrescriptionID=? AND Status=\'Pending\'')->execute([tdc_sale_cost_snapshot($item),$lines[$index]['PrescriptionID']]);
                        }
                    }
                    $update = $pdo->prepare("UPDATE prescriptions SET Status='Dispensed', DispensedAt=NOW(), DispensedBy=?, AmountPaid=?, DueBalance=?, PharmacySaleReference=? WHERE PrescriptionID LIKE ? AND Status='Pending'");
                    $update->execute([(int) $_SESSION['user_id'], $paid, $due, null, $base . '-%']);
                    if ($update->rowCount() !== count($lines)) throw new RuntimeException('Prescription state changed before dispensing completed.');
                    $doctorStmt = $pdo->prepare('SELECT UserID FROM doctors WHERE DoctorID=?');
                    $doctorStmt->execute([$lines[0]['DoctorID']]);
                    tdc_workflow_notify($pdo, (int) $doctorStmt->fetchColumn(), 'doctoruser', 'prescription_dispensed', 'Prescription dispensed', $base . ' was dispensed.', 'doctors.php?visit=' . (int) $lines[0]['VisitID']);
                    tdc_reconcile_patient_due_balance($pdo,(int)$lines[0]['PatientID']);
                    tdc_audit($pdo, 'prescription.dispensed', 'prescriptions', $base, 'Prescription dispensed; outstanding debt retained.', ['paid' => $paid, 'total' => $total, 'due'=>$due]);
                    $pdo->commit();
                    tdc_redirect('prescriptions', 'dispensed');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    if (!($e instanceof RuntimeException)) {
                        error_log('[PHARMACY][PRESCRIPTION] ' . $e->getMessage());
                    }
                    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The prescription could not be processed.';
                }
            }

        // --- Point of Sale ------------------------------------------
        } elseif ($section === 'pos') {
            tdc_require_permission('pharmacy.pos');
            if ($formAction === 'void_prescription') {
                if (!tdc_can('pharmacy.dispense')) tdc_forbidden();
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['PrescriptionReference'] ?? ''));
                $voidErrors = tdc_void_prescription($pdo, $base);
                if (empty($voidErrors)) {
                    tdc_redirect('pos', 'voided');
                }
                $errors = $voidErrors;
            } elseif ($formAction === 'collect_payment') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['SaleRef'] ?? ''));
                $collection = is_numeric($_POST['PaymentAmount'] ?? null) ? round((float) $_POST['PaymentAmount'], 2) : -1;
                $paymentMethod = trim((string) ($_POST['PaymentMethod'] ?? ''));
                if (!in_array($paymentMethod, $paymentMethodNames, true)) tdc_forbidden();
                try {
                    tdc_collect_pos_payment($pdo, $base, $collection, $paymentMethod);
                    header('Location: pharmacy.php?section=pos&view=' . urlencode($base) . '&payment_recorded=1');
                    exit;
                } catch (Throwable $e) {
                    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The POS payment could not be recorded.';
                }
            } elseif ($formAction === 'reconcile_legacy_payment_method') {
                if (($_SESSION['role'] ?? '') !== 'superuser') {
                    tdc_forbidden();
                }
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['SaleRef'] ?? ''));
                try {
                    tdc_reconcile_legacy_pos_payment(
                        $pdo,
                        $base,
                        (string) ($_POST['PaymentMethod'] ?? ''),
                        (string) ($_POST['Reason'] ?? ''),
                        (int) ($_SESSION['user_id'] ?? 0)
                    );
                    header('Location: pharmacy.php?section=pos&view=' . urlencode($base) . '&legacy_payment_reconciled=1');
                    exit;
                } catch (Throwable $e) {
                    error_log('[PHARMACY][POS] legacy payment reconciliation failed: ' . $e->getMessage());
                    $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'The historical payment method could not be recorded.';
                }
            } elseif ($formAction === 'void') {
                $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['SaleRef'] ?? ''));
                if ($base === '') {
                    $errors[] = 'Invalid sale selected.';
                } else {
                    try {
                        $GLOBALS['tdc_void_diagnostic'] = null;
                        $voidErrors = tdc_void_sale($pdo, $base);
                        if (empty($voidErrors)) {
                            tdc_redirect('pos', 'voided');
                        }
                        $errors = $voidErrors;
                    } catch (Throwable $e) {
                        $diagnostic = $GLOBALS['tdc_void_diagnostic'] ?? null;
                        error_log('[PHARMACY][POS] void failed: ' . $e->getMessage());
                        if (($_SESSION['role'] ?? '') === 'superuser' && is_array($diagnostic)) {
                            $errors[] = 'VOID FAILED | Stage: ' . (string) ($diagnostic['stage'] ?? 'UNKNOWN')
                                . ' | SQLSTATE: ' . (string) ($diagnostic['sqlstate'] ?? 'N/A')
                                . ' | Error: ' . tdc_void_sanitized_message((string) ($diagnostic['message'] ?? $e->getMessage()));
                        } else {
                            $errors[] = 'A system error occurred while voiding the sale. Please try again.';
                        }
                    }
                }
            } else {
                if (!in_array($oldSale['PaymentMethod'], $paymentMethodNames, true)) tdc_forbidden();
                $oldSale['CustomerName']  = trim((string) ($_POST['CustomerName'] ?? ''));
                $oldSale['CustomerPhone'] = trim((string) ($_POST['CustomerPhone'] ?? ''));
                $oldSale['PatientID']     = (int) ($_POST['PatientID'] ?? 0);
                $oldSale['VisitID']       = (int) ($_POST['VisitID'] ?? 0);
                $oldSale['DiscountType']  = trim((string) ($_POST['DiscountType'] ?? 'None'));
                $oldSale['DiscountValue'] = trim((string) ($_POST['DiscountValue'] ?? ''));
                $oldSale['DiscountReason'] = trim((string) ($_POST['DiscountReason'] ?? ''));
                $oldSale['AdjustmentNote'] = trim((string) ($_POST['AdjustmentNote'] ?? ''));
                $oldSale['AmountPaid']    = trim((string) ($_POST['AmountPaid'] ?? ''));
                $oldSale['PaymentMethod'] = trim((string) ($_POST['PaymentMethod'] ?? 'Cash'));
                $oldSale['ItemID']        = $_POST['ItemID'] ?? [];
                $oldSale['Quantity']      = $_POST['Quantity'] ?? [];
                $oldSale['UnitPrice']     = $_POST['UnitPrice'] ?? [];

                $stockByItemId = tdc_fetch_stock_map($pdo, $oldSale['ItemID']);
                $errors        = tdc_validate_pos_form($oldSale, $stockByItemId);
                $posShowForm   = true;

                if (empty($errors)) {
                    try {
                        $base = tdc_save_sale($pdo, $oldSale, $stockByItemId);
                        header('Location: ../pharmacy_receipt.php?source=pos&reference=' . urlencode($base));
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
                    $lookup = $pdo->prepare("SELECT ItemID, ItemName, Category, SalesUnit FROM inventory WHERE ItemID IN ($place)");
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
                    $unitStmt = $pdo->prepare('SELECT i.SalesUnit,i.Category,p.PurchaseUnit,p.ConversionFactor FROM inventory i LEFT JOIN purchases p ON p.PurchaseID=(SELECT p2.PurchaseID FROM purchases p2 WHERE LOWER(TRIM(p2.ItemName))=LOWER(TRIM(i.ItemName)) ORDER BY p2.PurchaseDate DESC,p2.PurchaseID DESC LIMIT 1) WHERE LOWER(TRIM(i.ItemName))=LOWER(TRIM(?)) LIMIT 1');
                    $unitStmt->execute([(string)$itemName]);
                    $unitDefaults = $unitStmt->fetch() ?: [];
                    foreach (['SalesUnit','Category','PurchaseUnit','ConversionFactor'] as $field) {
                        if (trim((string)($oldPurchase[$field][$i] ?? '')) === '') {
                            $fallback = $field === 'ConversionFactor' ? '1' : '';
                            if ($field === 'PurchaseUnit' && trim((string) ($unitDefaults['PurchaseUnit'] ?? '')) === '') {
                                $fallback = (string) ($oldPurchase['SalesUnit'][$i] ?? '');
                            }
                            $oldPurchase[$field][$i] = (string)($unitDefaults[$field] ?? $fallback);
                        }
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
                    tdc_redirect('inventory', 'inventory_deleted');
                }
            } elseif ($formAction === 'import_csv') {
                try {
                    $upload = $_FILES['csv_file'] ?? [];
                    $uploadExtension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                    $uploadReader = $uploadExtension === 'xlsx' ? 'tdc_xlsx_upload_rows' : 'tdc_csv_upload_rows';
                    $rows = $uploadReader(
                        $upload,
                        ['item_name', 'quantity_in_stock'],
                        tdc_inventory_csv_headers(),
                        2000,
                        [
                            'item id' => 'item_id',
                            'item name' => 'item_name',
                            'purchase price($)' => 'purchase_price',
                            'selling price($)' => 'selling_price',
                            're-order level' => 'reorder_level',
                            'quantity' => 'quantity_in_stock',
                        ],
                        ['purchase_price', 'barcode']
                    );
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                    $rows = [];
                }

                if (!empty($rows)) {
                    $existingNames = [];
                    foreach ($pdo->query('SELECT ItemID, LOWER(TRIM(ItemName)) AS item_name FROM inventory') as $row) {
                        $existingNames[(string) $row['item_name']] = (string) $row['ItemID'];
                    }

                    $prepared = [];
                    $seen = [];
                    foreach ($rows as $index => $row) {
                        $rowNum = $index + 2;
                        $normalized = tdc_inventory_import_row_to_input($row, $existingNames);
                        $rowErrors = $normalized['errors'];
                        $lookupName = mb_strtolower(trim((string) ($row['item_name'] ?? '')));

                        if ($lookupName !== '' && isset($seen[$lookupName])) {
                            $rowErrors[] = 'Duplicate item name in the uploaded file.';
                        }
                        if ($lookupName !== '') {
                            $seen[$lookupName] = true;
                        }

                        if ($rowErrors) {
                            $errors[] = 'Row ' . $rowNum . ': ' . implode(' ', $rowErrors);
                            continue;
                        }

                        $prepared[] = $normalized['input'];
                        if ($lookupName !== '' && !isset($existingNames[$lookupName])) {
                            $existingNames[$lookupName] = (string)($normalized['input']['ItemID'] ?? '');
                        }
                    }

                    if (!empty($prepared)) {
                        $pdo->beginTransaction();
                        try {
                            foreach ($prepared as $item) {
                                $importItemId = trim((string)($item['ItemID'] ?? ''));
                                tdc_save_inventory_item($pdo, $item, $importItemId !== '', $importItemId);
                            }
                            $pdo->commit();
                            tdc_redirect('inventory', 'inventory_imported');
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            error_log('[PHARMACY][INVENTORY] import failed: ' . $e->getMessage());
                            $errors[] = 'The CSV import failed and no changes were saved.';
                        }
                    }
                }
            } else {
                $oldInventory['ItemID']          = trim((string) ($_POST['ItemID'] ?? ''));
                $oldInventory['ItemName']        = trim((string) ($_POST['ItemName'] ?? ''));
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
                        tdc_redirect('inventory', $isEdit ? 'inventory_updated' : 'inventory_created');
                    } catch (PDOException $e) {
                        error_log('[PHARMACY][INVENTORY] save failed: ' . $e->getMessage());
                        $errors[] = 'A system error occurred while saving the item. Please try again.';
                    }
                }
            }
        }
    }

    // Keep the session CSRF token stable for other open authenticated forms.
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
$viewSalePaid       = 0.0;
$viewSaleHasLedgerPayment = false;
$viewSalePaymentMethods = [];
$viewSalePatient = null;
$viewSaleVisit = null;
$viewSalePrescriptionRows = [];
$viewSalePrescriptionReference = '';
$viewSaleHasAccounting = false;
$viewSaleLegacyRepairEligible = false;
$posPatients = [];
$posVisits = [];

if ($section === 'pos') {
    $posPatients = $pdo->query('SELECT PatientID, PatientName, PatientPhone, Gender, Age, DateOfBirth FROM patients ORDER BY PatientName ASC LIMIT 500')->fetchAll();
    $posVisits = $pdo->query("SELECT v.VisitID, v.VisitReference, v.PatientID, v.VisitDate, d.DoctorName, p.PatientName FROM visits v JOIN patients p ON p.PatientID=v.PatientID LEFT JOIN doctors d ON d.DoctorID=v.DoctorID WHERE v.QueueStatus <> 'Cancelled' ORDER BY v.VisitDate DESC LIMIT 500")->fetchAll();
    $inventoryForCombo = $pdo->query(
        'SELECT ItemID, ItemName, SellingPrice, QuantityInStock, SalesUnit FROM inventory
         ORDER BY ItemName ASC LIMIT 500'
    )->fetchAll();

    $saleSearch = trim((string) ($_GET['q'] ?? ''));
    $params     = [];
    $where      = '';
    if ($saleSearch !== '') {
        $where  = 'WHERE (CustomerName LIKE :q1 OR CustomerPhone LIKE :q2 OR SaleID LIKE :q3)';
        $params = ['q1' => '%' . $saleSearch . '%', 'q2' => '%' . $saleSearch . '%', 'q3' => '%' . $saleSearch . '%'];
    }
    $stmt = $pdo->prepare(
        "SELECT SUBSTRING_INDEX(SaleID, '-', 1) AS SaleRef, MIN(CustomerName) AS CustomerName,
                MIN(CustomerPhone) AS CustomerPhone, COUNT(*) AS LineCount,
                MIN(TotalAmount) AS TotalAmount, MIN(AmountPaid) AS AmountPaid,
                                MIN(DueBalance) AS DueBalance, MIN(PaymentStatus) AS PaymentStatus,
                                " . ($pharmacyHasSaleStatus ? "MIN(SaleStatus)" : "'Valid'") . " AS SaleStatus, MIN(SaleDate) AS SaleDate, 'Point of Sale' AS Source
          FROM pharmacysales " . ($where !== '' ? $where . ' AND ' : 'WHERE ') . "NOT EXISTS (SELECT 1 FROM prescriptions pr WHERE pr.PharmacySaleReference = SUBSTRING_INDEX(SaleID, '-', 1))
         GROUP BY SaleRef ORDER BY SaleDate DESC LIMIT 200"
    );
    $stmt->execute($params);
    $pharmacySales = $stmt->fetchAll();

    $rxSql = "SELECT SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) AS SaleRef,
                MIN(pr.PatientName) AS CustomerName, MIN(pr.PatientPhone) AS CustomerPhone,
                COUNT(*) AS LineCount, MIN(pr.TotalAmount) AS TotalAmount,
                COALESCE((SELECT SUM(py.Amount) FROM payments py WHERE py.PaymentType='Pharmacy' AND py.PrescriptionReference=SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) AND py.PaymentStatus='Confirmed'), 0) AS AmountPaid,
                GREATEST(0, MIN(pr.TotalAmount) - COALESCE((SELECT SUM(py.Amount) FROM payments py WHERE py.PaymentType='Pharmacy' AND py.PrescriptionReference=SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) AND py.PaymentStatus='Confirmed'), 0)) AS DueBalance,
                CASE WHEN COALESCE((SELECT SUM(py.Amount) FROM payments py WHERE py.PaymentType='Pharmacy' AND py.PrescriptionReference=SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) AND py.PaymentStatus='Confirmed'), 0) >= MIN(pr.TotalAmount) AND MIN(pr.TotalAmount) > 0 THEN 'Paid' WHEN COALESCE((SELECT SUM(py.Amount) FROM payments py WHERE py.PaymentType='Pharmacy' AND py.PrescriptionReference=SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) AND py.PaymentStatus='Confirmed'), 0) > 0 AND MIN(pr.TotalAmount) > 0 THEN 'Partial' ELSE 'Unpaid' END AS PaymentStatus,
                CASE WHEN MIN(pr.Status)='Cancelled' THEN 'Cancelled' ELSE 'Valid' END AS SaleStatus, MIN(pr.PrescriptionDate) AS SaleDate, 'Prescription' AS Source
         FROM prescriptions pr";
    $rxParams = [];
    if ($saleSearch !== '') {
        $rxSql .= " WHERE SUBSTRING_INDEX(pr.PrescriptionID, '-', 1) LIKE :rx_q1 OR pr.PatientName LIKE :rx_q2 OR pr.PatientPhone LIKE :rx_q3";
        $rxParams = ['rx_q1' => '%' . $saleSearch . '%', 'rx_q2' => '%' . $saleSearch . '%', 'rx_q3' => '%' . $saleSearch . '%'];
    }
    $rxSql .= ' GROUP BY SaleRef ORDER BY SaleDate DESC LIMIT 200';

    $rxHistoryStmt = $pdo->prepare($rxSql);
    $rxHistoryStmt->execute($rxParams);
    $pharmacySales = array_merge($pharmacySales, $rxHistoryStmt->fetchAll());

    if (empty($errors)) {
        if (isset($_GET['view'])) {
            $viewSaleRef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
            try {
                $saleStatusSelect = $pharmacyHasSaleStatus ? 'SaleStatus' : "'Valid' AS SaleStatus";
                $stmt = $pdo->prepare("SELECT * FROM pharmacysales WHERE SaleID LIKE :pattern ORDER BY SaleID ASC");
                $stmt->execute(['pattern' => $viewSaleRef . '-%']);
                $viewSaleLines = $stmt->fetchAll();
                if (empty($viewSaleLines)) {
                    $viewSaleRef = '';
                } else {
                    $paymentLedgerColumns = ['PaymentMethod', 'PaymentStatus', 'SaleReference', 'Amount', 'PaidAt', 'PaymentID'];
                    $missingPaymentColumns = array_values(array_filter(
                        $paymentLedgerColumns,
                        static fn(string $column): bool => !tdc_has_column($pdo, 'payments', $column)
                    ));
                    $hasPaymentLedger = $missingPaymentColumns === [];
                    if ($hasPaymentLedger) {
                        $paymentStmt = $pdo->prepare("SELECT PaymentMethod, COALESCE(SUM(Amount), 0) AS NetAmount
                            FROM payments WHERE SaleReference = ? AND PaymentStatus = 'Confirmed'
                            GROUP BY PaymentMethod HAVING COALESCE(SUM(Amount), 0) > 0 ORDER BY MIN(PaidAt), PaymentMethod");
                        $paymentStmt->execute([$viewSaleRef]);
                        $viewSalePaymentMethods = $paymentStmt->fetchAll();
                        $ledgerStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(Amount), 0) FROM payments WHERE SaleReference = ? AND PaymentStatus = 'Confirmed'");
                        $ledgerStmt->execute([$viewSaleRef]);
                        $ledgerRow = $ledgerStmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
                        $viewSaleHasLedgerPayment = (int) $ledgerRow[0] > 0;
                        $viewSalePaid = round((float) $ledgerRow[1], 2);
                    } else {
                        error_log('[PHARMACY][POS][RECEIPT] Missing payments columns [' . implode(', ', $missingPaymentColumns) . ']; using sale snapshot for ' . $viewSaleRef);
                    }

                    if (tdc_has_column($pdo, 'accounting', 'ReferenceID')) {
                        $accountingStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting WHERE ReferenceID=?');
                        $accountingStmt->execute([$viewSaleRef]);
                        $viewSaleHasAccounting = (int) $accountingStmt->fetchColumn() > 0;
                    }

                    $prescriptionStmt = $pdo->prepare('SELECT * FROM prescriptions WHERE PharmacySaleReference=? ORDER BY PrescriptionID ASC');
                    $prescriptionStmt->execute([$viewSaleRef]);
                    $viewSalePrescriptionRows = $prescriptionStmt->fetchAll();

                    $salePatientId = 0;
                    $saleVisitId = 0;
                    $viewSalePrescriptionReference = '';

                    if ($viewSalePrescriptionRows) {
                        $presc = $viewSalePrescriptionRows[0];
                        $salePatientId = (int) ($presc['PatientID'] ?? 0);
                        $saleVisitId = (int) ($presc['VisitID'] ?? 0);
                        $viewSalePrescriptionReference = (string) (preg_replace('/-[^-]+$/', '', (string) ($presc['PrescriptionID'] ?? '')) ?: '');
                    }

                    if ($salePatientId > 0) {
                        $patientStmt = $pdo->prepare('SELECT PatientID, PatientName, PatientPhone, Gender, Age, DateOfBirth FROM patients WHERE PatientID=? LIMIT 1');
                        $patientStmt->execute([$salePatientId]);
                        $viewSalePatient = $patientStmt->fetch() ?: null;
                    } else {
                        $custName = $viewSaleLines[0]['CustomerName'] ?? '';
                        $custPhone = $viewSaleLines[0]['CustomerPhone'] ?? '';
                        $patientStmt = $pdo->prepare('SELECT PatientID, PatientName, PatientPhone, Gender, Age, DateOfBirth FROM patients WHERE PatientName = ? OR PatientPhone = ? LIMIT 1');
                        $patientStmt->execute([$custName, $custPhone]);
                        $viewSalePatient = $patientStmt->fetch() ?: null;
                    }

                    if ($saleVisitId > 0) {
                        $visitStmt = $pdo->prepare('SELECT v.VisitID, v.VisitReference, d.DoctorName, d.Specialty FROM visits v LEFT JOIN doctors d ON d.DoctorID=v.DoctorID WHERE v.VisitID=? LIMIT 1');
                        $visitStmt->execute([$saleVisitId]);
                        $viewSaleVisit = $visitStmt->fetch() ?: null;
                    } elseif (isset($viewSalePatient) && $viewSalePatient) {
                        $visitStmt = $pdo->prepare('SELECT v.VisitID, v.VisitReference, d.DoctorName, d.Specialty FROM visits v LEFT JOIN doctors d ON d.DoctorID=v.DoctorID WHERE v.PatientID = ? ORDER BY v.VisitDate DESC LIMIT 1');
                        $visitStmt->execute([$viewSalePatient['PatientID']]);
                        $viewSaleVisit = $visitStmt->fetch() ?: null;
                    } else {
                        $viewSaleVisit = null;
                    }

                    $viewSaleLegacyRepairEligible = ($_SESSION['role'] ?? '') === 'superuser'
                        && !$viewSaleHasLedgerPayment
                        && (float) ($viewSaleLines[0]['AmountPaid'] ?? 0) > 0
                        && !$viewSalePaymentMethods;
                }
            } catch (Throwable $e) {
                error_log('[PHARMACY][POS][RECEIPT] view ' . $viewSaleRef . ' failed: ' . $e->getMessage());
                $errors[] = 'The sale receipt could not be loaded. Please try again or contact an administrator.';
                $viewSaleRef = '';
                $viewSaleLines = [];
            }
        } elseif (isset($_GET['new']) || !$posHistory) {
            $posShowForm = true;
        }
    }
}

$pendingPrescriptions=[];
$dispensedPrescriptions=[];
if($section==='prescriptions'){
    $pendingPrescriptions=$pdo->query("SELECT SUBSTRING_INDEX(pr.PrescriptionID,'-',1) PrescriptionReference,MIN(pr.PatientName) PatientName,MIN(pr.PatientPhone) PatientPhone,MIN(d.DoctorName) DoctorName,COUNT(*) ItemCount,MIN(pr.PrescriptionDate) PrescriptionDate,MIN(pr.TotalAmount) TotalAmount,COALESCE((SELECT SUM(py.Amount) FROM payments py WHERE py.PaymentType='Pharmacy' AND py.PrescriptionReference=SUBSTRING_INDEX(pr.PrescriptionID,'-',1) AND py.PaymentStatus='Confirmed'),0) PaidAmount FROM prescriptions pr JOIN doctors d ON d.DoctorID=pr.DoctorID WHERE pr.Status='Pending' GROUP BY PrescriptionReference ORDER BY PrescriptionDate")->fetchAll();
    $dispensedPrescriptions=$pdo->query("SELECT SUBSTRING_INDEX(pr.PrescriptionID,'-',1) PrescriptionReference,MIN(pr.PatientName) PatientName,MIN(d.DoctorName) DoctorName,COUNT(*) ItemCount,MIN(pr.TotalAmount) TotalAmount,MIN(pr.AmountPaid) AmountPaid,MIN(pr.DueBalance) DueBalance,MIN(pr.PharmacySaleReference) SaleReference,MAX(pr.DispensedAt) DispensedAt FROM prescriptions pr JOIN doctors d ON d.DoctorID=pr.DoctorID WHERE pr.Status='Dispensed' GROUP BY PrescriptionReference ORDER BY DispensedAt DESC LIMIT 100")->fetchAll();
    $pharmacyPrescriptionAdjustments=[];
    foreach (array_merge($pendingPrescriptions,$dispensedPrescriptions) as $prescriptionBill) {
        $adjustment=tdc_load_bill_adjustment($pdo,'prescription',(string)$prescriptionBill['PrescriptionReference']);
        if ($adjustment) $pharmacyPrescriptionAdjustments[(string)$prescriptionBill['PrescriptionReference']]=$adjustment;
    }
}

// --- 10B. Purchases -----------------------------------------------------
$itemNamesForDatalist = [];
$purchaseSearch       = '';
$purchaseOrders       = [];
$viewPORef            = '';
$viewPOLines          = [];

if ($section === 'purchases') {
    $purchaseUnitOptions = $pdo->query('SELECT ItemID, ItemName, Category, SalesUnit, SellingPrice, QuantityInStock, ExpiryDate, DefaultPurchaseUnit, UnitsPerPackage FROM inventory ORDER BY ItemName')->fetchAll();
    foreach ($purchaseUnitOptions as $key => $row) {
        $purchaseUnitOptions[$key]['SalesUnit']          = tdc_norm_unit($row['SalesUnit'] ?? null);
        $purchaseUnitOptions[$key]['DefaultPurchaseUnit'] = tdc_norm_unit($row['DefaultPurchaseUnit'] ?? null);
    }
    $itemNamesForDatalist = $pdo->query('SELECT DISTINCT ItemName FROM inventory ORDER BY ItemName ASC LIMIT 500')
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
         FROM purchases {$where}
         GROUP BY PORef";

    if (!$canViewPurchaseCost) {
        $purchaseSelectSql = "SELECT SUBSTRING_INDEX(PurchaseID, '-', 1) AS PORef, MIN(SupplierName) AS SupplierName,
            MIN(SupplierPhone) AS SupplierPhone, MIN(ReferenceNumber) AS ReferenceNumber, COUNT(*) AS LineCount,
            MIN(PurchaseDate) AS PurchaseDate FROM purchases {$where} GROUP BY PORef";
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

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT SUBSTRING_INDEX(PurchaseID, \'-\', 1) AS PORef FROM purchases ' . $where . ' GROUP BY PORef) AS grouped');
    $countStmt->execute($params);
    $purchaseTotal = (int) $countStmt->fetchColumn();

    $purchaseOffset = ($purchasePage - 1) * $purchasePerPage;
    $stmt = $pdo->prepare($purchaseSelectSql . ' ORDER BY PurchaseDate DESC LIMIT ' . (int) $purchasePerPage . ' OFFSET ' . (int) $purchaseOffset);
    $stmt->execute($params);
    $purchaseOrders = $stmt->fetchAll();

    if (empty($errors)) {
        if (isset($_GET['view'])) {
            $viewPORef = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['view']);
            $stmt = $pdo->prepare('SELECT ' . ($canViewPurchaseCost ? '*' : 'PurchaseID,SupplierName,SupplierPhone,ReferenceNumber,ItemName,Category,Quantity,PurchaseUnit,SalesUnit,SellingPrice,ExpiryDate,PurchaseDate') . ' FROM purchases WHERE PurchaseID LIKE :pattern ORDER BY PurchaseID ASC');
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

if ($section === 'inventory' && in_array((string) ($_GET['export'] ?? ''), ['csv', 'xlsx'], true)) {
    tdc_require_permission('pharmacy.inventory.manage');
    $exportStmt = $pdo->query('SELECT * FROM inventory ORDER BY ItemName ASC');
    $exportRows = [];
    foreach ($exportStmt->fetchAll() as $row) {
        $exportRows[] = tdc_inventory_csv_row_from_db($row);
    }
    if ((string) $_GET['export'] === 'xlsx') {
        tdc_xlsx_download('tarey_inventory_' . date('Y-m-d') . '.xlsx', tdc_inventory_csv_headers(), $exportRows);
    }
    tdc_csv_download('tarey_inventory_' . date('Y-m-d') . '.csv', tdc_inventory_csv_headers(), $exportRows);
}

if ($section === 'inventory') {
    $inventorySearch = trim((string) ($_GET['q'] ?? ''));
    $lowStockOnly    = isset($_GET['low']);

    $conditions = [];
    $params     = [];
    if ($inventorySearch !== '') {
        $conditions[] = 'ItemName LIKE :q1';
        $params['q1'] = '%' . $inventorySearch . '%';
    }
    if ($lowStockOnly) {
        $conditions[] = 'QuantityInStock <= ReorderLevel';
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare("SELECT * FROM inventory {$where} ORDER BY ItemName ASC LIMIT 500");
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
    $hubTodaySales    = (int) tdc_scalar($pdo, "SELECT COUNT(DISTINCT SUBSTRING_INDEX(SaleID, '-', 1)) FROM pharmacysales WHERE DATE(SaleDate) = CURDATE()");
    if (tdc_can('pharmacy.inventory.view')) {
        $hubLowStockCount = (int) tdc_scalar($pdo, 'SELECT COUNT(*) FROM inventory WHERE QuantityInStock <= ReorderLevel');
        $hubOpenPOCount = $canViewPurchaseCost ? (int) tdc_scalar($pdo, "SELECT COUNT(DISTINCT SUBSTRING_INDEX(PurchaseID, '-', 1)) FROM purchases WHERE DueBalance > 0") : 0;
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

$pharmacyStatus = (string) ($_GET['status'] ?? '');
$justSaved   = isset($_GET['success']) || in_array($pharmacyStatus, ['inventory_created', 'inventory_updated'], true);
$justDeleted = isset($_GET['deleted']) || $pharmacyStatus === 'inventory_deleted';
$justVoided  = isset($_GET['voided']);
$legacyPaymentReconciled = isset($_GET['legacy_payment_reconciled']);
$pharmacyToast = match ($pharmacyStatus) {
    'inventory_created' => 'Inventory item created successfully.',
    'inventory_updated' => 'Inventory item updated successfully.',
    'inventory_deleted' => 'Inventory item deleted successfully.',
    'inventory_imported' => 'Inventory items imported successfully.',
    default => '',
};
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
    .purchase-qty-hint, .purchase-cost-hint, .purchase-selling-hint, .purchase-expiry-hint{ display:block; margin-top:4px; font-size:11.5px; color:var(--navy-55); }
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

    .receipt-preview-shell{ background:#eef0f4; margin:0 -24px -40px; padding:24px; overflow-x:auto; }
    .receipt-toolbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; max-width:210mm; margin:0 auto 16px; }
    .receipt-paper{ box-sizing:border-box; width:210mm; min-height:297mm; margin:0 auto; padding:12mm 11mm; background:#fff; border:1px solid #222; box-shadow:0 8px 24px rgba(0,0,0,.12); color:#111; font-family:"Times New Roman", Times, Georgia, serif; }
    .receipt-paper{ display:flex; flex-direction:column; }
    .receipt-letterhead{ order:1; }
    .prescription-meta{ order:2; }
    .prescription-table{ order:3; }
    .receipt-payment-note,.receipt-signature{ order:4; }
    .receipt-paper > .receipt-table,.receipt-paper > .receipt-financials,.receipt-paper > .receipt-legacy-content{ display:none; }
    .receipt-letterhead{ display:grid; grid-template-columns:155px 1fr 155px; align-items:center; gap:10px; padding-bottom:8px; border-bottom:1px solid #333; }
    .receipt-letterhead::after{ content:""; display:block; }
    .receipt-logo{ display:block; width:155px; height:100px; object-fit:contain; object-position:left center; }
    .receipt-clinic-identity{ text-align:center; }
    .receipt-clinic-name{ font-size:24px; line-height:1.1; font-weight:700; letter-spacing:.3px; text-transform:uppercase; }
    .receipt-clinic-address{ margin-top:3px; font-size:15px; font-weight:600; line-height:1.15; }
    .receipt-clinic-phone{ margin-top:2px; font-size:14px; font-weight:600; }
    .receipt-meta{ display:grid; grid-template-columns:1fr 1fr; gap:3px 38px; margin:13px 0 16px; font-size:14px; line-height:1.35; }
    .receipt-meta-column{ display:grid; gap:3px; }
    .receipt-meta-line{ display:grid; grid-template-columns:105px 1fr; min-width:0; }
    .receipt-meta-label{ font-weight:700; }
    .receipt-meta-value{ overflow-wrap:anywhere; }
    .receipt-paper > .receipt-meta:not(.prescription-meta){ display:none; }
    .prescription-meta{ display:grid; grid-template-columns:60% 40%; gap:3px 28px; margin:7px 0 8px; font-size:13px; line-height:1.2; }
    .prescription-meta .receipt-meta-column{ gap:2px; }
    .prescription-meta .receipt-meta-line{ grid-template-columns:105px 1fr; min-height:18px; }
    .receipt-doctor-detail{ font-size:12px; }
    .prescription-table{ width:100%; border-collapse:collapse; table-layout:fixed; font-size:12px; }
    .prescription-table th,.prescription-table td{ border:1px solid #444; padding:3px 6px; color:#111; line-height:1.15; }
    .prescription-table th{ background:#fff; font-weight:700; text-align:center; }
    .prescription-table th:nth-child(1),.prescription-table td:nth-child(1){ width:5%; text-align:center; }
    .prescription-table th:nth-child(2),.prescription-table td:nth-child(2){ width:29%; text-align:left; overflow-wrap:anywhere; }
    .prescription-table th:nth-child(3),.prescription-table td:nth-child(3){ width:9%; text-align:center; }
    .prescription-table th:nth-child(4),.prescription-table td:nth-child(4){ width:16%; text-align:center; overflow-wrap:anywhere; }
    .prescription-table th:nth-child(5),.prescription-table td:nth-child(5){ width:15%; text-align:center; overflow-wrap:anywhere; }
    .prescription-table th:nth-child(6),.prescription-table td:nth-child(6){ width:12%; text-align:center; overflow-wrap:anywhere; }
    .prescription-table th:nth-child(7),.prescription-table td:nth-child(7){ width:14%; text-align:center; overflow-wrap:anywhere; }
    .receipt-payment-note{ margin-top:8px; font-size:12px; }
    .receipt-table th,.receipt-table td{ border:1px solid #222; padding:6px 7px; color:#111; }
    .receipt-table th{ font-weight:700; text-align:left; }
    .receipt-table th:first-child,.receipt-table td:first-child,.receipt-table th:nth-child(3),.receipt-table td:nth-child(3){ text-align:center; }
    .receipt-table th:nth-child(n+4),.receipt-table td:nth-child(n+4){ text-align:right; }
    .receipt-financials{ display:grid; grid-template-columns:1fr 190px; gap:22px; margin-top:15px; font-size:14px; line-height:1.5; }
    .receipt-finance-left{ align-self:start; }
    .receipt-totals{ display:grid; grid-template-columns:1fr auto; gap:2px 12px; text-align:right; }
    .receipt-totals .receipt-total-label{ font-weight:700; }
    .receipt-signature{ margin-top:14px; font-size:13px; }
    .receipt-signature-line{ display:inline-block; min-width:290px; border-bottom:1px solid #222; height:1em; vertical-align:bottom; }
    .receipt-legacy-content{ display:none; }
    .receipt-paper > .receipt-meta:not(.prescription-meta){ display:none; }


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
        body{ background:#fff !important; }
        .receipt-preview-shell{ margin:0; padding:0; overflow:visible; background:#fff; }
        .receipt-toolbar{ display:none !important; }
        .receipt-paper{ width:100%; min-height:auto; margin:0; padding:12mm 11mm; border:1px solid #222; box-shadow:none; }
        .prescription-table tr{ break-inside:avoid; page-break-inside:avoid; }
    }
    @media (max-width:700px){ .receipt-preview-shell{ margin:0 -16px -32px; padding:16px; } .receipt-paper{ padding:12mm 10mm 14mm; } .receipt-letterhead{ grid-template-columns:105px 1fr; gap:10px; } .receipt-letterhead::after{ display:none; } .receipt-logo{ width:105px; height:82px; } .receipt-clinic-name{ font-size:21px; } .receipt-meta{ gap:3px 16px; } .receipt-meta-line{ grid-template-columns:88px 1fr; } }
</style>
<link rel="stylesheet" href="../assets/clinic.css?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.css')) ?>">
<script src="../assets/clinic.js?v=<?= rawurlencode((string) @filemtime(__DIR__ . '/../assets/clinic.js')) ?>" defer></script>
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
        <div class="welcome-title">Prescription Dispensing Queue</div><div class="welcome-sub">Review and dispense doctor prescriptions. Prescription payments and bill adjustments are managed in Reception → Pharmacy Billing.</div>
        <div class="data-table-wrap" style="margin-top:24px"><table class="data-table"><thead><tr><th>Prescription</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Final</th><th>Paid</th><th>Due</th><th>Payment</th><th>Dispensing</th><th>Action</th></tr></thead><tbody><?php if(!$pendingPrescriptions): ?><tr class="empty-row"><td colspan="10">No pending prescriptions.</td></tr><?php else:foreach($pendingPrescriptions as $rx): ?><?php $rxTotal=(float)($pharmacyPrescriptionAdjustments[(string)$rx['PrescriptionReference']]['FinalAmount']??$rx['TotalAmount']);$rxPaid=(float)$rx['PaidAmount'];$rxDue=max(0,round($rxTotal-$rxPaid,2)); ?><?php $rxPayment=$rxTotal<=0?'FINANCIAL DATA UNAVAILABLE':($rxDue<=0?'PAID':($rxPaid>0?'PARTIAL':'UNPAID')); ?><tr><td><?= tdc_e($rx['PrescriptionReference']) ?></td><td><?= tdc_e($rx['PatientName']) ?><br><span class="cell-sub"><?= tdc_e($rx['PatientPhone']) ?></span></td><td><?= tdc_e($rx['DoctorName']) ?></td><td><?= (int)$rx['ItemCount'] ?></td><td><?= number_format($rxTotal,2) ?></td><td><?= number_format($rxPaid,2) ?></td><td><?= number_format($rxDue,2) ?></td><td><span class="status-badge<?= $rxPayment==='PAID'?'':' warn' ?>"><?= $rxPayment ?></span></td><td><span class="status-badge warn">PENDING</span></td><td><div class="row-actions"><a class="btn-secondary btn-sm" href="../pharmacy_receipt.php?source=prescription&amp;reference=<?= urlencode($rx['PrescriptionReference']) ?>">View Details</a><form method="post"><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="prescription_action" value="dispense"><input type="hidden" name="PrescriptionReference" value="<?= tdc_e($rx['PrescriptionReference']) ?>"><button class="btn-success btn-sm"<?= $rxTotal<=0?' disabled':'' ?>>Dispense</button></form></div></td></tr><?php endforeach;endif; ?></tbody></table></div>
        <div class="subsection-title" style="margin-top:28px">Dispensed Pharmacy Bills</div>
        <p class="section-hint">These prescriptions were dispensed by Pharmacy. Payment is recorded in Reception → Pharmacy Billing.</p>
        <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Prescription</th><th>Patient</th><th>Doctor</th><th>Items</th><th>Final</th><th>Paid</th><th>Due</th><th>Payment</th><th>Dispensing</th><th>Actions</th></tr></thead><tbody><?php if(!$dispensedPrescriptions): ?><tr class="empty-row"><td colspan="10">No dispensed prescriptions yet.</td></tr><?php else:foreach($dispensedPrescriptions as $bill): ?><?php $billTotal=(float)($pharmacyPrescriptionAdjustments[(string)$bill['PrescriptionReference']]['FinalAmount']??$bill['TotalAmount']);$billPaid=(float)$bill['AmountPaid'];$billDue=max(0,round($billTotal-$billPaid,2));$billPayment=$billTotal<=0?'FINANCIAL DATA UNAVAILABLE':($billDue<=0?'PAID':($billPaid>0?'PARTIAL':'UNPAID')); ?><tr><td><?= tdc_e($bill['PrescriptionReference']) ?></td><td><?= tdc_e($bill['PatientName']) ?></td><td><?= tdc_e($bill['DoctorName']) ?></td><td><?= (int)$bill['ItemCount'] ?></td><td><?= number_format($billTotal,2) ?></td><td><?= number_format($billPaid,2) ?></td><td><?= number_format($billDue,2) ?></td><td><span class="status-badge<?= $billPayment==='PAID'?'':' warn' ?>"><?= $billPayment ?></span></td><td><span class="status-badge">DISPENSED</span></td><td><a class="btn-primary btn-sm" href="../pharmacy_receipt.php?source=prescription&amp;reference=<?= urlencode($bill['PrescriptionReference']) ?>">View Receipt</a></td></tr><?php endforeach;endif; ?></tbody></table></div>

    <?php elseif ($section === 'pos'): ?>

        <?php if ($viewSaleRef !== ''): ?>
        <?php $receiptAge = $viewSalePatient ? (($viewSalePatient['DateOfBirth'] ?? '') !== '' ? (string) tdc_age_from_birth_date((string) $viewSalePatient['DateOfBirth']) : (string) ($viewSalePatient['Age'] ?? '')) : ''; $receiptPatientId = $viewSalePatient ? (string) $viewSalePatient['PatientID'] : ''; $receiptPatientName = $viewSalePatient ? (string) $viewSalePatient['PatientName'] : (string) ($viewSaleLines[0]['CustomerName'] ?? ''); $receiptPhone = $viewSalePatient ? (string) ($viewSalePatient['PatientPhone'] ?? '') : (string) ($viewSaleLines[0]['CustomerPhone'] ?? ''); $receiptGender = $viewSalePatient ? (string) ($viewSalePatient['Gender'] ?? '') : ''; $receiptDoctor = $viewSaleVisit ? (string) ($viewSaleVisit['DoctorName'] ?? '') : ''; $receiptVisitNumber = $viewSaleVisit ? (string) ($viewSaleVisit['VisitReference'] ?? ($viewSaleVisit['VisitNumber'] ?? '')) : ''; ?>
        <?php $head = $viewSaleLines[0]; $receiptTotal = (float) $head['TotalAmount']; $receiptPaid = $viewSalePaymentMethods ? $viewSalePaid : (float) $head['AmountPaid']; $receiptDue = max(0, round($receiptTotal - $receiptPaid, 2)); $receiptMethods = $viewSalePaymentMethods ? implode(' / ', array_map(static fn(array $m): string => (string) $m['PaymentMethod'], $viewSalePaymentMethods)) : '—'; ?>
        <?php $receiptPaid = $viewSaleHasLedgerPayment ? $viewSalePaid : (float) $head['AmountPaid']; $receiptDue = max(0, round((float) $head['TotalAmount'] - $receiptPaid, 2)); $receiptMethods = $viewSalePaymentMethods ? implode(' / ', array_map(static fn(array $m): string => (string) $m['PaymentMethod'], $viewSalePaymentMethods)) : '—'; ?>
        <?php $receiptSpecialty = $viewSaleVisit ? (string) ($viewSaleVisit['Specialty'] ?? '') : ''; $receiptDate = $viewSalePrescriptionRows ? (string) ($viewSalePrescriptionRows[0]['PrescriptionDate'] ?? $head['SaleDate']) : (string) $head['SaleDate']; $medicalRows = $viewSalePrescriptionRows ?: array_map(static fn(array $line): array => ['MedicationName' => $line['ItemName'] ?? '', 'Quantity' => $line['Quantity'] ?? '', 'Frequency' => '', 'Route' => ''], $viewSaleLines); ?>
        <?php
        $receiptPatientName = $receiptPatientName !== '' ? strtoupper($receiptPatientName) : '';
        $receiptDoctor = $receiptDoctor !== '' ? tdc_receipt_person_name($receiptDoctor) : '';
        $receiptSpecialty = $receiptSpecialty !== '' ? tdc_receipt_title_case($receiptSpecialty) : '';
        $receiptGender = $receiptGender !== '' ? tdc_receipt_title_case($receiptGender) : '';
        $medicalRows = array_map(static function (array $medicine): array {
            $medicine['MedicationName'] = tdc_receipt_title_case((string) ($medicine['MedicationName'] ?? ''));
            $medicine['Route'] = tdc_receipt_route((string) ($medicine['Route'] ?? ''));
            return $medicine;
        }, $medicalRows);
        ?>
        <div class="receipt-preview-shell">
            <div class="receipt-toolbar no-print"><a href="pharmacy.php?section=pos" class="back-link">&larr; Back to Point of Sale</a><div class="row-actions"><button type="button" class="btn-primary btn" onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print Receipt</span></button><?php if ($head['SaleStatus'] !== 'Voided'): ?><form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this sale? Stock will be restored. This cannot be undone."><input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>"><input type="hidden" name="form_action" value="void"><input type="hidden" name="SaleRef" value="<?= tdc_e($viewSaleRef) ?>"><button type="submit" class="btn-danger btn-sm danger">Void Sale</button></form><?php endif; ?></div></div>
            <?php if (!$viewSalePrescriptionRows && $head['SaleStatus'] !== 'Voided' && $receiptDue > 0): ?>
            <form method="POST" action="pharmacy.php?section=pos&amp;view=<?= urlencode($viewSaleRef) ?>" class="no-print" style="margin:12px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="collect_payment">
                <input type="hidden" name="SaleRef" value="<?= tdc_e($viewSaleRef) ?>">
                <label>Payment amount<input type="number" name="PaymentAmount" min="0.01" max="<?= tdc_e((string) $receiptDue) ?>" step="0.01" value="<?= tdc_e((string) $receiptDue) ?>" required></label>
                <label>Payment method<select name="PaymentMethod" required><?php foreach ($paymentMethods as $method): ?><option value="<?= tdc_e($method['MethodName']) ?>"><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select></label>
                <button type="submit" class="btn-success btn-sm">Record Payment</button>
            </form>
            <?php endif; ?>
            <article class="receipt-paper" aria-label="Medical prescription <?= tdc_e($viewSaleRef) ?>">
                <section class="receipt-meta prescription-meta" aria-label="Patient and visit information"><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Patient ID:</span><span class="receipt-meta-value"><?= tdc_e($receiptPatientId !== '' ? $receiptPatientId : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Patient Name:</span><span class="receipt-meta-value"><?= tdc_e($receiptPatientName !== '' ? $receiptPatientName : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Doctor:</span><span class="receipt-meta-value"><?= tdc_e($receiptDoctor !== '' ? $receiptDoctor : '—') ?><?php if ($receiptSpecialty !== ''): ?><br><span class="receipt-doctor-detail"><?= tdc_e($receiptSpecialty) ?></span><?php endif; ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Phone:</span><span class="receipt-meta-value"><?= tdc_e($receiptPhone !== '' ? $receiptPhone : '—') ?></span></div></div><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Visit Number:</span><span class="receipt-meta-value"><?= tdc_e($receiptVisitNumber !== '' ? $receiptVisitNumber : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">PNo:</span><span class="receipt-meta-value"><?= tdc_e($viewSalePrescriptionReference !== '' ? $viewSalePrescriptionReference : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Gender:</span><span class="receipt-meta-value"><?= tdc_e($receiptGender !== '' ? $receiptGender : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Age:</span><span class="receipt-meta-value"><?= tdc_e($receiptAge !== '' ? $receiptAge : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Date:</span><span class="receipt-meta-value"><?= tdc_e(date('d/m/Y', strtotime($receiptDate))) ?></span></div></div></section>
                <header class="receipt-letterhead"><img class="receipt-logo" src="../uploads/tareydermacliniclogo.png" alt="Tarey Derma Clinic"><div class="receipt-clinic-identity"><div class="receipt-clinic-name"><?= tdc_e($receiptClinicName) ?></div><div class="receipt-clinic-address"><?= tdc_e($receiptClinicAddress) ?></div><div class="receipt-clinic-phone">TEL: <?= tdc_e($receiptClinicPhone) ?></div></div></header>
                <section class="receipt-meta" aria-label="Customer and receipt information"><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Patient ID:</span><span class="receipt-meta-value">—</span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Patient Name:</span><span class="receipt-meta-value"><?= tdc_e($head['CustomerName'] ?: 'Walk-in') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Doctor:</span><span class="receipt-meta-value">—</span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Phone:</span><span class="receipt-meta-value"><?= tdc_e($head['CustomerPhone'] ?: '—') ?></span></div></div><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Receipt No:</span><span class="receipt-meta-value"><?= tdc_e($viewSaleRef) ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Visit Number:</span><span class="receipt-meta-value">—</span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Gender:</span><span class="receipt-meta-value">—</span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Age:</span><span class="receipt-meta-value">—</span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Date:</span><span class="receipt-meta-value"><?= tdc_e(date('d/m/Y', strtotime((string) $head['SaleDate']))) ?></span></div></div></section>
                <section class="receipt-meta receipt-linked-meta" aria-label="Customer and receipt information"><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Patient ID:</span><span class="receipt-meta-value"><?= tdc_e($receiptPatientId !== '' ? $receiptPatientId : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Patient Name:</span><span class="receipt-meta-value"><?= tdc_e($receiptPatientName !== '' ? $receiptPatientName : 'Walk-in') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Doctor:</span><span class="receipt-meta-value"><?= tdc_e($receiptDoctor !== '' ? $receiptDoctor : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Phone:</span><span class="receipt-meta-value"><?= tdc_e($receiptPhone !== '' ? $receiptPhone : '—') ?></span></div></div><div class="receipt-meta-column"><div class="receipt-meta-line"><span class="receipt-meta-label">Receipt No:</span><span class="receipt-meta-value"><?= tdc_e($viewSaleRef) ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Visit Number:</span><span class="receipt-meta-value"><?= tdc_e($receiptVisitNumber !== '' ? $receiptVisitNumber : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Gender:</span><span class="receipt-meta-value"><?= tdc_e($receiptGender !== '' ? $receiptGender : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Age:</span><span class="receipt-meta-value"><?= tdc_e($receiptAge !== '' ? $receiptAge : '—') ?></span></div><div class="receipt-meta-line"><span class="receipt-meta-label">Date:</span><span class="receipt-meta-value"><?= tdc_e(date('d/m/Y', strtotime((string) $head['SaleDate']))) ?></span></div></div></section>
                <table class="receipt-table"><thead><tr><th>No</th><th>Drug</th><th>Quantity</th><th>Unit Price</th><th>Amount</th></tr></thead><tbody><?php foreach ($viewSaleLines as $index => $l): ?><tr><td><?= $index + 1 ?></td><td><?= tdc_e($l['ItemName']) ?></td><td><?= (int) $l['Quantity'] ?></td><td><?= number_format((float) $l['UnitPrice'], 2) ?></td><td><?= number_format((float) $l['LineTotal'], 2) ?></td></tr><?php endforeach; ?></tbody></table>
                <section class="receipt-financials" aria-label="Payment summary"><div class="receipt-finance-left"><div><strong>Payment Method:</strong> <?= tdc_e($receiptMethods) ?></div><div><strong>Payment Status:</strong> <?= tdc_e((string) $head['PaymentStatus']) ?></div></div><div class="receipt-totals"><span class="receipt-total-label">Total:</span><span><?= number_format($receiptTotal, 2) ?></span><span class="receipt-total-label">Paid:</span><span><?= number_format($receiptPaid, 2) ?></span><span class="receipt-total-label">Due:</span><span><?= number_format($receiptDue, 2) ?></span></div></section>
                <table class="prescription-table"><thead><tr><th>No</th><th>Medication</th><th>Quantity</th><th>Frequency</th><th>Duration</th><th>Route</th></tr></thead><tbody><?php foreach ($medicalRows as $index => $medicine): ?><tr><td><?= $index + 1 ?></td><td><?= tdc_e((string) (($medicine['MedicationName'] ?? '') !== '' ? $medicine['MedicationName'] : '—')) ?></td><td><?= tdc_e((string) (($medicine['Quantity'] ?? '') !== '' ? $medicine['Quantity'] : '—')) ?></td><td><?= tdc_e((string) (($medicine['Frequency'] ?? '') !== '' ? $medicine['Frequency'] : '—')) ?></td><td><?= tdc_e((string) (($medicine['Duration'] ?? '') !== '' ? $medicine['Duration'] : '—')) ?></td><td><?= tdc_e((string) (($medicine['Route'] ?? '') !== '' ? $medicine['Route'] : '—')) ?></td></tr><?php if (trim((string) ($medicine['Instructions'] ?? '')) !== ''): ?><tr><td colspan="6"><strong>Instructions:</strong> <?= tdc_e((string) $medicine['Instructions']) ?></td></tr><?php endif; ?><?php endforeach; ?></tbody></table><?php if ($receiptMethods !== '—'): ?><div class="receipt-payment-note">Payment Method: <?= tdc_e($receiptMethods) ?></div><?php endif; ?>
                <div class="receipt-signature">Signature: <span class="receipt-signature-line"></span></div>
                <div class="receipt-legacy-content">
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
            <button type="button" class="btn-primary  btn " onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print Receipt</span></button>
            <form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this sale? Stock will be restored. This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="void">
                <input type="hidden" name="SaleRef" value="<?= tdc_e($viewSaleRef) ?>">
                <button type="submit" class="btn-danger btn-sm danger">Void Sale</button>
            </form>
        </div>

                </div>
            </article>
            <?php if ($viewSaleLegacyRepairEligible): ?>
            <details class="no-print" style="margin-top:16px;max-width:720px;background:#fff;border:1px solid #cfd4dc;padding:12px;">
                <summary style="cursor:pointer;font-weight:600;">Set Historical Payment Method</summary>
                <form method="post" action="pharmacy.php?section=pos&amp;view=<?= urlencode($viewSaleRef) ?>" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="reconcile_legacy_payment_method">
                    <input type="hidden" name="SaleRef" value="<?= tdc_e($viewSaleRef) ?>">
                    <div class="form-row">
                        <div class="form-group"><label>Sale Reference</label><div><?= tdc_e($viewSaleRef) ?></div></div>
                        <div class="form-group"><label>Existing Total</label><div><?= number_format($receiptTotal, 2) ?></div></div>
                        <div class="form-group"><label>Existing Paid</label><div><?= number_format($receiptPaid, 2) ?></div></div>
                        <div class="form-group"><label>Existing Due</label><div><?= number_format($receiptDue, 2) ?></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="legacyPaymentMethod">Payment Method</label><select id="legacyPaymentMethod" name="PaymentMethod" required><?php foreach ($paymentMethods as $method): ?><option value="<?= tdc_e($method['MethodName']) ?>"><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group" style="flex:2"><label for="legacyPaymentReason">Reason / Note</label><textarea id="legacyPaymentReason" name="Reason" maxlength="500" required></textarea></div>
                    </div>
                    <button type="submit" class="btn-success btn">Save Historical Method</button>
                </form>
            </details>
            <?php endif; ?>
        </div>

        <?php elseif ($posShowForm): ?>

        <div class="modal-overlay is-open" role="presentation">
        <div class="modal-box modal-wide" role="dialog" aria-modal="true" aria-labelledby="pos-sale-title">
        <div class="modal-head"><div><h3 id="pos-sale-title">New Point-of-Sale Sale</h3><p>Record a pharmacy sale with stock and payment validation.</p></div><a class="modal-close" href="pharmacy.php?section=pos" aria-label="Close new sale" title="Close">&times;</a></div>
        <div class="modal-body">

        <form id="saleForm" method="POST" action="pharmacy.php?section=pos">
            <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
            <input type="hidden" name="form_action" value="save">

            <div class="form-row" style="max-width:1200px;margin-bottom:16px;">
                <div class="form-group"><label for="sf_CustomerName">Customer Name (optional)</label>
                    <input type="text" id="sf_CustomerName" name="CustomerName" value="<?= tdc_e($oldSale['CustomerName']) ?>" placeholder="Walk-in"></div>
                <div class="form-group"><label for="sf_CustomerPhone">Customer Phone (optional)</label>
                    <input type="text" id="sf_CustomerPhone" name="CustomerPhone" value="<?= tdc_e($oldSale['CustomerPhone']) ?>"></div>
                <div class="form-group"><label for="sf_PatientID">Registered Patient (optional)</label>
                    <select id="sf_PatientID" name="PatientID"><option value="0">Walk-in / no patient</option><?php foreach ($posPatients as $patient): ?><option value="<?= (int) $patient['PatientID'] ?>" data-name="<?= tdc_e($patient['PatientName']) ?>" data-phone="<?= tdc_e((string) ($patient['PatientPhone'] ?? '')) ?>"<?= (int) $oldSale['PatientID'] === (int) $patient['PatientID'] ? ' selected' : '' ?>><?= tdc_e($patient['PatientID'] . ' — ' . $patient['PatientName'] . ($patient['PatientPhone'] ? ' — ' . $patient['PatientPhone'] : '')) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="sf_VisitID">Linked Visit (optional)</label>
                    <select id="sf_VisitID" name="VisitID"><option value="0">No linked visit</option><?php foreach ($posVisits as $visit): ?><option value="<?= (int) $visit['VisitID'] ?>" data-patient-id="<?= (int) $visit['PatientID'] ?>"<?= (int) $oldSale['VisitID'] === (int) $visit['VisitID'] ? ' selected' : '' ?>><?= tdc_e($visit['VisitReference'] . ' — ' . date('d/m/Y', strtotime((string) $visit['VisitDate'])) . ($visit['DoctorName'] ? ' — ' . $visit['DoctorName'] : '')) ?></option><?php endforeach; ?></select></div>
            </div>

            <div class="line-items-wrap">
                <table class="line-items" id="lineItemsTable">
                    <thead><tr><th style="width:40px;">#</th><th>Item</th><th style="width:110px;">Qty</th><th style="width:130px;">Unit Price</th><th style="width:130px;">Line Total</th><th style="width:36px;"></th></tr></thead>
                    <tbody id="lineItemsBody">
                    <?php
                    $saleLineCount = max(1, count($oldSale['ItemID']));
                    for ($i = 0; $i < $saleLineCount; $i++):
                        $itemId = $oldSale['ItemID'][$i] ?? '';
                    ?>
                        <tr class="line-item-row">
                            <td class="line-no"><?= $i + 1 ?></td>
                            <td>
                                <select name="ItemID[]" class="item-select" required aria-label="Inventory item">
                                    <option value="">Select an inventory item...</option>
                                    <?php foreach ($inventoryForCombo as $it): ?>
                                        <option value="<?= tdc_e((string) $it['ItemID']) ?>" data-price="<?= tdc_e((string) $it['SellingPrice']) ?>" data-stock="<?= (int) $it['QuantityInStock'] ?>"<?= (int) $it['QuantityInStock'] <= 0 ? ' disabled' : '' ?><?= (string) $itemId === (string) $it['ItemID'] ? ' selected' : '' ?>><?= tdc_e($it['ItemName'] . ' — ' . (int) $it['QuantityInStock'] . ' in stock') ?></option>
                                    <?php endforeach; ?>
                                </select>
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

            <div class="form-row" style="margin-top:16px;">
                <div class="form-group"><label for="sf_DiscountType">Discount</label><select id="sf_DiscountType" name="DiscountType"><option value="None"<?= $oldSale['DiscountType'] === 'None' ? ' selected' : '' ?>>No discount</option><option value="Fixed"<?= $oldSale['DiscountType'] === 'Fixed' ? ' selected' : '' ?>>Fixed amount</option><option value="Percentage"<?= $oldSale['DiscountType'] === 'Percentage' ? ' selected' : '' ?>>Percentage</option></select></div>
                <div class="form-group"><label for="sf_DiscountValue">Discount value</label><input type="number" step="0.01" min="0" id="sf_DiscountValue" name="DiscountValue" value="<?= tdc_e($oldSale['DiscountValue']) ?>" placeholder="0.00"><small id="sf_DiscountHint">Enter a fixed amount.</small></div>
                <div class="form-group"><label for="sf_DiscountReason">Discount reason</label><input type="text" id="sf_DiscountReason" name="DiscountReason" value="<?= tdc_e($oldSale['DiscountReason']) ?>" maxlength="255" placeholder="Required when discount is used"></div>
            </div>
            <div class="form-group"><label for="sf_AdjustmentNote">Sale note</label><textarea id="sf_AdjustmentNote" name="AdjustmentNote" rows="2" maxlength="1000" placeholder="Optional note about this sale or adjustment"><?= tdc_e($oldSale['AdjustmentNote']) ?></textarea></div>

            <div class="totals-row">
                <div class="form-group"><label>Subtotal</label><div class="due-display" id="sf_TotalDisplay">0.00</div></div>
                <div class="form-group"><label>Final Total</label><div class="due-display" id="sf_FinalTotalDisplay">0.00</div></div>
                <div class="form-group"><label for="sf_AmountPaid">Amount Paid</label>
                    <input type="number" step="0.01" min="0" id="sf_AmountPaid" name="AmountPaid" value="<?= tdc_e($oldSale['AmountPaid']) ?>" placeholder="Defaults to full total"></div>
                <div class="form-group"><label for="sf_PaymentMethod">Payment Method</label><select id="sf_PaymentMethod" name="PaymentMethod" required><?php foreach ($paymentMethods as $method): ?><option value="<?= tdc_e($method['MethodName']) ?>"<?= $oldSale['PaymentMethod'] === $method['MethodName'] ? ' selected' : '' ?>><?= tdc_e($method['MethodName']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Due</label><div class="due-display" id="sf_DueDisplay">0.00</div></div>
            </div>

            <div class="form-actions">
                <a href="pharmacy.php?section=pos" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn-success btn ">Complete Sale</button>
            </div>
        </form>
        <script>
        (function(){const patient=document.getElementById('sf_PatientID'),visit=document.getElementById('sf_VisitID'),name=document.getElementById('sf_CustomerName'),phone=document.getElementById('sf_CustomerPhone');if(!patient||!visit)return;function sync(){const id=patient.value;Array.from(visit.options).forEach(function(o){if(!o.value)return;o.hidden=id==='0'||o.dataset.patientId!==id;o.disabled=o.hidden;});const selected=visit.options[visit.selectedIndex];if(selected&&selected.disabled)visit.value='0';const p=patient.options[patient.selectedIndex];if(id!=='0'&&p){name.value=p.dataset.name||'';phone.value=p.dataset.phone||'';name.readOnly=true;phone.readOnly=true;}else{name.readOnly=false;phone.readOnly=false;}}patient.addEventListener('change',sync);sync();})();
        </script>

        </div></div></div>

        <?php else: ?>

        <nav class="setup-section-nav" aria-label="Point of Sale views"><a href="pharmacy.php?section=pos&amp;new=1" class="pos-new-sale-link"<?= !$posHistory ? ' aria-current="page"' : '' ?>>New Sale</a><a href="pharmacy.php?section=pos&amp;view_mode=history"<?= $posHistory ? ' class="active" aria-current="page"' : '' ?>>Sales History</a></nav>

        <div class="welcome-title">Sales History</div>
        <div class="welcome-sub">Review previous pharmacy sales, balances and receipts.</div>

        <div class="section-toolbar">
            <form method="GET" action="pharmacy.php" class="filter-box">
                <input type="hidden" name="section" value="pos">
                <input type="text" name="q" placeholder="Search by customer or ref..." value="<?= tdc_e($saleSearch) ?>">
                <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Search</span></button>
            </form>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Reference</th><th>Source</th><th>Customer / Patient</th><th>Items</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($pharmacySales)): ?>
                    <tr class="empty-row"><td colspan="10">No sales found<?= $saleSearch !== '' ? ' for "' . tdc_e($saleSearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($pharmacySales as $s): ?>
                    <tr>
                        <td><?= tdc_e($s['SaleRef']) ?></td>
                        <td><?= $s['Source']==='Prescription'?'Prescription':'POS' ?></td>
                        <td><?= tdc_e($s['CustomerName'] ?: 'Walk-in') ?></td>
                        <td><?= (int) $s['LineCount'] ?></td>
                        <td><?= number_format((float) $s['TotalAmount'], 2) ?></td>
                        <td><?= number_format((float) $s['AmountPaid'], 2) ?></td>
                        <td><span class="status-badge<?= (float) $s['DueBalance'] > 0 ? ' danger' : '' ?>"><?= number_format((float) $s['DueBalance'], 2) ?></span></td>
                        <td><span class="status-badge<?= in_array($s['SaleStatus'], ['Voided','Cancelled'], true) ? ' danger' : ($s['PaymentStatus'] === 'Unpaid' ? ' danger' : ($s['PaymentStatus'] === 'Partial' ? ' warn' : '')) ?>"><?= tdc_e(in_array($s['SaleStatus'], ['Voided','Cancelled'], true) ? 'VOIDED' : $s['PaymentStatus']) ?></span></td>
                        <td><?= tdc_e(date('Y-m-d', strtotime((string) $s['SaleDate']))) ?></td>
                        <td>
                            <div class="row-actions">
                                <a href="../pharmacy_receipt.php?source=<?= $s['Source']==='Prescription'?'prescription':'pos' ?>&amp;reference=<?= urlencode($s['SaleRef']) ?>" class="btn-primary btn-sm"><?= $s['SaleStatus']==='Voided'?'View Void Details / Receipt':($s['Source']==='Prescription'&&(float)$s['TotalAmount']<=0?'View Details':'View Receipt') ?></a>
                                <?php if($s['Source']==='Prescription' && !in_array($s['SaleStatus'], ['Voided','Cancelled'], true)): ?><form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this prescription? Dispensed stock and confirmed payments will be reversed, and the prescription will be marked voided. This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="void_prescription">
                                    <input type="hidden" name="PrescriptionReference" value="<?= tdc_e($s['SaleRef']) ?>">
                                    <button type="submit" class="btn-danger btn-sm danger">Void Prescription</button>
                                </form><?php elseif ($s['Source']==='Prescription'): ?><span class="status-badge danger">VOIDED</span><?php elseif ($s['SaleStatus'] !== 'Voided'): ?><form method="POST" action="pharmacy.php?section=pos" data-confirm="Void this sale? Stock will be restored. This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                                    <input type="hidden" name="form_action" value="void">
                                    <input type="hidden" name="SaleRef" value="<?= tdc_e($s['SaleRef']) ?>">
                                    <button type="submit" class="btn-danger btn-sm danger">Void</button>
                                </form><?php endif; ?>
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
            <button type="button" class="btn-primary  btn " onclick="window.print()"><?= tdc_icon('printer',16) ?><span>Print</span></button>
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
        <div class="form-section-label">Packaging &amp; units</div>

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
                            <td><input type="date" name="ExpiryDate[]" value="<?= tdc_e($oldPurchase['ExpiryDate'][$i] ?? '') ?>" aria-label="Expiry"><small class="purchase-expiry-hint"></small></td>
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
                                if ($selectedPack === '') $selectedPack = tdc_norm_unit((string) ($oldPurchase['SalesUnit'][$i] ?? ''));
                                $selectedSize = (string) ($oldPurchase['ConversionFactor'][$i] ?? '1');
                                $isCustomPack = $selectedPack !== '' && !in_array($selectedPack, $commonPacks, true);
                                $hasPack      = $selectedPack !== '' && (float) $selectedSize > 1;
                                $sellUnitName = tdc_norm_unit((string) ($oldPurchase['SalesUnit'][$i] ?? ''), '');
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
                                <div class="purchase-pkg-unavailable"<?= $hasPack ? ' hidden' : '' ?>><?= $sellUnitName !== '' ? 'Purchased in base unit: ' . tdc_e($sellUnitName) : 'Select a medicine to see its purchase unit.' ?></div>
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
                    <div class="purchase-summary-row"><label for="pof_Discount">Discount Amount</label>
                        <input type="number" step="0.01" min="0" id="pof_Discount" name="Discount" value="<?= tdc_e($oldPurchase['Discount']) ?>" placeholder="0.00"></div>
                    <div class="purchase-summary-row"><label for="pof_VATAmount">VAT Amount</label>
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
                <input type="text" name="q" placeholder="Search by medicine name..." value="<?= tdc_e($inventorySearch) ?>">
                <label><input type="checkbox" name="low" value="1" <?= $lowStockOnly ? 'checked' : '' ?> onchange="this.form.submit()"> Low stock only</label>
                <button type="submit" class="btn-primary btn "><?= tdc_icon('search',16) ?><span>Search</span></button>
            </form>
            <div class="row-actions">
                <a href="pharmacy.php?section=inventory&export=csv" class="btn btn-secondary"><?= tdc_icon('download',16) ?><span>Export CSV</span></a>
                <a href="pharmacy.php?section=inventory&export=xlsx" class="btn btn-secondary"><?= tdc_icon('download',16) ?><span>Export XLSX</span></a>
                <button type="button" id="importInventoryBtn" class="btn btn-success"><?= tdc_icon('upload',16) ?><span>Import CSV / XLSX</span></button>
                <button type="button" id="addItemBtn" class="btn-success  btn "><?= tdc_icon('plus',16) ?><span>+ Add Medicine</span></button>
            </div>
        </div>

        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr><th>Item ID</th><th>Name</th><th>Stock</th><th>Unit</th><th>Selling Price</th><th>Reorder Level</th><th>Expiry</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($inventoryItems)): ?>
                    <tr class="empty-row"><td colspan="8">No inventory items found<?= $inventorySearch !== '' ? ' for "' . tdc_e($inventorySearch) . '"' : '' ?>.</td></tr>
                    <?php else: foreach ($inventoryItems as $item):
                        $isLow = (int) $item['QuantityInStock'] <= (int) $item['ReorderLevel'];
                        $expiryTs = $item['ExpiryDate'] ? strtotime((string) $item['ExpiryDate']) : null;
                        $isExpired = $expiryTs !== null && $expiryTs < strtotime('today');
                        $isExpiringSoon = $expiryTs !== null && !$isExpired && $expiryTs <= strtotime('+30 days');
                    ?>
                    <tr>
                        <td><?= tdc_e($item['ItemID']) ?></td>
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
                                <button type="button" class="btn-secondary btn-sm edit-item-btn"
                                    data-id="<?= tdc_e($item['ItemID']) ?>"
                                    data-name="<?= tdc_e($item['ItemName']) ?>"
                                    data-stock="<?= tdc_e((string) $item['QuantityInStock']) ?>"
                                    data-unit="<?= tdc_e((string) $item['SalesUnit']) ?>"
                                    data-price="<?= tdc_e((string) $item['SellingPrice']) ?>"
                                    data-reorder="<?= tdc_e((string) $item['ReorderLevel']) ?>"
                                    data-packunit="<?= tdc_e((string) $item['DefaultPurchaseUnit']) ?>"
                                    data-packsize="<?= tdc_e((string) $item['UnitsPerPackage']) ?>"
                                    data-expiry="<?= tdc_e((string) $item['ExpiryDate']) ?>"><?= tdc_icon('pencil',16) ?><span>Edit</span></button>
                                <form method="POST" action="pharmacy.php?section=inventory" data-confirm="Permanently delete this item and all linked purchase, sale, payment, prescription, and ledger history? This cannot be undone.">
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
                        <div class="form-section-label">Packaging &amp; units</div>
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

        <div class="modal-overlay medicine-modal" id="inventoryImportOverlay">
            <div class="modal-box">
                <div class="modal-head">
                    <h3>Import Inventory File</h3>
                    <button type="button" class="modal-close" id="inventoryImportCloseBtn" aria-label="Close">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
                <form id="inventoryImportForm" method="POST" action="pharmacy.php?section=inventory" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= tdc_e($csrfToken) ?>">
                        <input type="hidden" name="form_action" value="import_csv">
                        <div class="form-group">
                            <label for="inventoryCsvFile">CSV or XLSX file</label>
                            <input type="file" id="inventoryCsvFile" name="csv_file" accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                        </div>
                        <p class="form-hint">Required columns: item_name, quantity_in_stock. Optional: sales_unit, selling_price, reorder_level, expiry_date, default_purchase_unit, units_per_package.</p>
                        <p class="form-hint">CSV and XLSX spreadsheet headers such as Item Name, Quantity, Selling Price($), and Re-order Level are accepted. The first worksheet is imported. If no unit is supplied, Piece is used.</p>
                        <p class="form-hint">Existing medicine names are updated by name; new names are added. Duplicate names within the same file are rejected. Blank or negative stock values are imported as 0.</p>
                        <div class="modal-actions">
                            <button type="button" class="btn btn-secondary" id="inventoryImportCancelBtn">Cancel</button>
                            <button type="submit" class="btn-success  btn "><?= tdc_icon('check',16) ?><span>Import Items</span></button>
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
        render(term === '' ? options : options.filter(function(o){ return o.label.toLowerCase().indexOf(term) !== -1; }));
    });

    searchInput.addEventListener('focus', function(){
        if (hiddenIdInput.value === ''){
            const term = searchInput.value.trim().toLowerCase();
            render(term === '' ? options : options.filter(function(o){ return o.label.toLowerCase().indexOf(term) !== -1; }));
        }
    });

    // Selecting by typing the exact medicine name is equivalent to clicking it.
    searchInput.addEventListener('blur', function(){
        window.setTimeout(function(){
            if (hiddenIdInput.value !== '') return;
            const term = searchInput.value.trim().toLowerCase();
            if (!term) return;
            const match = options.find(function(o){
                const label = o.label.toLowerCase();
                return label === term || label.split(' (')[0] === term;
            });
            if (match) select(match);
        }, 0);
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
    const tbody = document.getElementById('lineItemsBody');

    function wireRow(row){
        const itemSelect = row.querySelector('.item-select');
        const qtyInput  = row.querySelector('.qty-input');
        const priceInput = row.querySelector('.price-input');

        function syncItem(){
            const option = itemSelect.options[itemSelect.selectedIndex];
            const price = option && option.dataset.price ? parseFloat(option.dataset.price) : 0;
            const stock = option && option.dataset.stock ? parseInt(option.dataset.stock, 10) : 0;
            priceInput.value = itemSelect.value && Number.isFinite(price) ? price.toFixed(2) : '';
            if (itemSelect.value && stock > 0) {
                qtyInput.max = String(stock);
                if (parseInt(qtyInput.value || '1', 10) > stock) qtyInput.value = String(stock);
            } else {
                qtyInput.removeAttribute('max');
            }
            recalcAll();
        }

        itemSelect.addEventListener('change', syncItem);

        [qtyInput, priceInput].forEach(function(el){ el.addEventListener('input', recalcAll); });

        row.querySelector('.remove-line-btn').addEventListener('click', function(){
            if (tbody.querySelectorAll('.line-item-row').length > 1){ row.remove(); renumber(); recalcAll(); }
        });

        syncItem();
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
        const discountType = document.getElementById('sf_DiscountType').value;
        const discountInput = document.getElementById('sf_DiscountValue');
        const reasonInput = document.getElementById('sf_DiscountReason');
        const discountValue = parseFloat(discountInput.value) || 0;
        const discountAmount = discountType === 'Percentage' ? total * discountValue / 100 : discountValue;
        const finalTotal = Math.max(0, total - Math.min(total, discountAmount));
        discountInput.max = discountType === 'Percentage' ? '100' : total.toFixed(2);
        document.getElementById('sf_DiscountHint').textContent = discountType === 'Percentage' ? 'Enter a percentage from 0 to 100.' : 'Enter a fixed amount.';
        reasonInput.required = discountAmount > 0;
        reasonInput.setCustomValidity(discountAmount > 0 && !reasonInput.value.trim() ? 'Enter a reason for the discount.' : '');
        document.getElementById('sf_FinalTotalDisplay').textContent = finalTotal.toFixed(2);
        const paidInput = document.getElementById('sf_AmountPaid');
        paidInput.max = finalTotal.toFixed(2);
        let paid = paidInput.value !== '' ? (parseFloat(paidInput.value) || 0) : finalTotal;
        paidInput.setCustomValidity(paid > finalTotal ? 'Amount paid cannot exceed the final sale total after discount.' : '');
        if (paid < 0) {
            paid = 0;
        }
        document.getElementById('sf_DueDisplay').textContent = Math.max(0, finalTotal - paid).toFixed(2);
    }

    tbody.querySelectorAll('.line-item-row').forEach(wireRow);
    document.getElementById('sf_AmountPaid').addEventListener('input', recalcAll);
    document.getElementById('sf_DiscountType').addEventListener('change', recalcAll);
    document.getElementById('sf_DiscountValue').addEventListener('input', recalcAll);
    document.getElementById('sf_DiscountReason').addEventListener('input', recalcAll);
    document.getElementById('saleForm').addEventListener('submit', function(){ recalcAll(); });

    document.getElementById('addLineBtn').addEventListener('click', function(){
        const template = tbody.querySelector('.line-item-row').cloneNode(true);
        template.querySelector('.item-select').selectedIndex = 0;
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
            ExpiryDate: it.ExpiryDate,
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
        const hiddenPurchaseUnit = pkgQuery(row, '.purchase-pack-unit');
        const unavailable = pkgQuery(row, '.purchase-pkg-unavailable');
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
    function formatExpiry(value){
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return match ? match[3] + '/' + match[2] + '/' + match[1] : '';
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
        const unitName = normUnit(salesUnit, '');
        const displayUnit = unitName || 'unit';
        const plural = pluralUnit(unitName);
        const packPlural = pluralUnit(packUnit);
        const valueEl = pkgQuery(row, '.purchase-pkg-value');
        const packRadio = pkgQuery(row, '.purchase-mode-package');
        const packLabel = pkgQuery(row, '.purchase-mode-pack-label');
        const packOpt = pkgQuery(row, '.purchase-mode-opt-package');
        const unitNameEl = pkgQuery(row, '.purchase-pkg-unit-name');
        const hiddenPurchaseUnit = pkgQuery(row, '.purchase-pack-unit');
        const unavailable = pkgQuery(row, '.purchase-pkg-unavailable');
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
        if (unitNameEl) unitNameEl.textContent = displayUnit;
        if (costHint) costHint.textContent = 'per ' + displayUnit;
        if (sellingHint) sellingHint.textContent = 'current per ' + displayUnit;
        const mode = hasPack ? currentMode(row) : 'unit';
        if (mode === 'package' && hasPack){
            if (hiddenPurchaseUnit) hiddenPurchaseUnit.value = packUnit;
            if (sizeInput) sizeInput.value = packSize;
            if (valueEl) valueEl.textContent = packUnit + ' \u00d7 ' + packSize + ' ' + plural;
            if (qtyHint) qtyHint.textContent = packPlural;
            if (costHint) costHint.textContent = 'per ' + packUnit;
            if (hint) hint.textContent = qty + ' ' + packPlural + ' \u00d7 ' + packSize + ' ' + plural + ' = ' + Math.round(qty * packSize) + ' ' + plural + ' added to inventory';
        } else {
            if (hiddenPurchaseUnit) hiddenPurchaseUnit.value = unitName;
            if (sizeInput) sizeInput.value = '1';
            if (valueEl) valueEl.textContent = '';
            if (qtyHint) qtyHint.textContent = plural;
            if (hint) hint.textContent = hasPack ? ('Package available: 1 ' + packUnit + ' = ' + packSize + ' ' + plural) : '';
            if (hasPack) setMode(row, 'unit');
        }
        if (unavailable) {
            unavailable.textContent = hasPack ? '' : (unitName === '' ? 'Select a medicine to see its purchase unit.' : 'Purchased in base unit: ' + unitName);
            unavailable.hidden = hasPack;
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
            const expiryInput = row.querySelector('input[name="ExpiryDate[]"]');
            if (expiryInput) expiryInput.value = opt.ExpiryDate || '';
            const expiryHint = row.querySelector('.purchase-expiry-hint');
            if (expiryHint) {
                const formattedExpiry = formatExpiry(opt.ExpiryDate);
                expiryHint.textContent = formattedExpiry
                    ? 'Current stock expiry: ' + formattedExpiry + ' — change this if the received batch has a different expiry.'
                    : 'Enter the expiry date shown on the received medicine batch.';
            }
            row.querySelector('.purchase-selling-price').value = (opt.SellingPrice !== null && opt.SellingPrice !== undefined && opt.SellingPrice !== '') ? parseFloat(opt.SellingPrice).toFixed(2) : '';
            const defUnit = opt.DefaultPurchaseUnit || opt.SalesUnit || '';
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
    const importOverlay = document.getElementById('inventoryImportOverlay');
    const modalTitle = document.getElementById('itemModalTitle');
    const form = document.getElementById('itemForm');
    const fId = document.getElementById('if_ItemID');
    const fName = document.getElementById('if_ItemName');
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

    const importBtn = document.getElementById('importInventoryBtn');
    const importCloseBtn = document.getElementById('inventoryImportCloseBtn');
    const importCancelBtn = document.getElementById('inventoryImportCancelBtn');
    if (importBtn && importOverlay) {
        importBtn.addEventListener('click', function(){ importOverlay.classList.add('show'); });
    }
    if (importCloseBtn && importOverlay) { importCloseBtn.addEventListener('click', function(){ importOverlay.classList.remove('show'); }); }
    if (importCancelBtn && importOverlay) { importCancelBtn.addEventListener('click', function(){ importOverlay.classList.remove('show'); }); }
    if (importOverlay) {
        importOverlay.addEventListener('click', function(e){ if (e.target === importOverlay) importOverlay.classList.remove('show'); });
    }

    document.querySelectorAll('.edit-item-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            form.reset();
            fId.value = btn.dataset.id;
            fName.value = btn.dataset.name;
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

<?php if ($justSaved || $justDeleted || $justVoided || $pharmacyToast !== ''): ?>
(function(){
    let message = 'Saved successfully.';
    <?php if ($pharmacyToast !== ''): ?>message = <?= json_encode($pharmacyToast) ?>;<?php endif; ?>
    <?php if ($justDeleted): ?>message = 'Item deleted successfully.';<?php endif; ?>
    <?php if ($justVoided): ?>message = <?= $section === 'purchases' ? json_encode('Purchase order voided successfully.') : json_encode('Sale voided successfully.') ?>;<?php endif; ?>
    <?php if ($justSaved): ?>message = <?= $section === 'pos' ? json_encode('Sale completed successfully.') : ($section === 'purchases' ? json_encode('Purchase order saved successfully.') : json_encode('Item saved successfully.')) ?>;<?php endif; ?>
    <?php if ($legacyPaymentReconciled): ?>message = 'Historical POS payment method reconciled successfully.';<?php endif; ?>
    showToast(message);
})();
<?php endif; ?>
</script>

</body>
</html>
