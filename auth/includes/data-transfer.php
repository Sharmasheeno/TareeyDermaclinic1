<?php
declare(strict_types=1);

/**
 * Normalize a phone number for COMPARISON ONLY (never for storage).
 *
 * Strips every non-digit character so that harmless formatting
 * differences resolve to the same key:
 *   0610508800  /  0610 508 8800  /  0610-508-800  /  +252 61 050 8800
 * become comparable keys. Genuinely different numbers (different country
 * code or length, e.g. +252 61... vs 061...) stay distinct, so a real
 * number is never rejected as a duplicate of a different one.
 */
function tdc_norm_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone);
}

/**
 * Return true when $iso is a real, strictly-formatted Y-m-d calendar date.
 * Rejects overflow dates such as 2026-02-31.
 */
function tdc_dob_is_real(string $iso): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt !== false && $dt->format('Y-m-d') === $iso;
}

/**
 * Normalize a user-supplied date of birth to canonical YYYY-MM-DD.
 *
 * Accepted, when unambiguous:
 *   YYYY-MM-DD   (canonical)          e.g. 2000-08-30
 *   YYYY/MM/DD                       e.g. 2000/08/30
 *   DD/MM/YYYY where DD > 12         e.g. 30/08/2000
 *   MM/DD/YYYY where DD > 12         e.g. 08/30/2000
 *
 * Returns '' for empty input or for any value that cannot be determined
 * unambiguously (e.g. 08/10/2000 where day and month are both <= 12),
 * so the caller can report a clear error instead of silently guessing.
 * A leading Excel text-marker apostrophe is tolerated and stripped.
 */
function tdc_normalize_dob(string $dob): string
{
    $dob = trim($dob);
    // Tolerate a leading Excel "treat as text" apostrophe (e.g. '2000-08-30).
    while ($dob !== '' && $dob[0] === "'") {
        $dob = substr($dob, 1);
    }
    $dob = trim($dob);
    if ($dob === '') {
        return '';
    }

    // Canonical YYYY-MM-DD or YYYY/MM/DD.
    if (preg_match('#^(\d{4})[-/](\d{2})[-/](\d{2})$#', $dob, $m)) {
        $iso = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        if (tdc_dob_is_real($iso)) {
            return $iso;
        }
        return '';
    }

    // Day/month-first: DD/MM/YYYY or MM/DD/YYYY (1-2 digit parts).
    if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $dob, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $y = (int) $m[3];
        if ($a < 1 || $b < 1 || $a > 31 || $b > 31) {
            return '';
        }
        // Unambiguous: the component that cannot be a month (>12) is the day.
        if ($a > 12 && $a <= 31) {
            $month = $b;
            $day = $a;
        } elseif ($b > 12 && $b <= 31) {
            $month = $a;
            $day = $b;
        } else {
            // Both components are <= 12 -> genuinely ambiguous; do not guess.
            return '';
        }
        $iso = sprintf('%04d-%02d-%02d', $y, $month, $day);
        if (tdc_dob_is_real($iso)) {
            return $iso;
        }
        return '';
    }

    return '';
}

/**
 * Neutralise CSV formula injection and preserve leading-zero numerics
 * for spreadsheet users.
 *
 * - Cells beginning with a formula operator (=, +, -, @) are prefixed with
 *   a single quote. Excel hides the leading quote on display and forces
 *   "treat as text", which defeats =CMD() style injection without changing
 *   the value the user sees.
 * - All-digit values that begin with 0 (clinic phones such as 0610508800)
 *   are likewise prefixed so Excel does not drop the leading zero on open.
 *
 * Ordinary phone numbers never begin with = + - @ and are therefore never
 * altered by the formula rule; the leading-zero rule only adds a hidden
 * text marker that is stripped again on CSV import.
 */
function tdc_csv_cell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $first = $value[0];
    if ($first === '=' || $first === '+' || $first === '-' || $first === '@') {
        return "'" . $value;
    }
    if ($first === '0' && strlen($value) > 1 && ctype_digit($value)) {
        return "'" . $value;
    }
    return $value;
}

function tdc_inventory_csv_headers(): array
{
    return [
        'item_id',
        'item_name',
        'quantity_in_stock',
        'sales_unit',
        'selling_price',
        'reorder_level',
        'expiry_date',
        'default_purchase_unit',
        'units_per_package',
    ];
}

function tdc_inventory_csv_row_from_db(array $item): array
{
    return [
        (string) ($item['ItemID'] ?? ''),
        (string) ($item['ItemName'] ?? ''),
        (string) ($item['QuantityInStock'] ?? '0'),
        (string) ($item['SalesUnit'] ?? ''),
        number_format((float) ($item['SellingPrice'] ?? 0), 2, '.', ''),
        (string) ($item['ReorderLevel'] ?? '0'),
        (string) ($item['ExpiryDate'] ?? ''),
        (string) ($item['DefaultPurchaseUnit'] ?? ''),
        (string) ($item['UnitsPerPackage'] ?? ''),
    ];
}

function tdc_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        $sanitized = [];
        foreach ($row as $value) {
            $sanitized[] = tdc_csv_cell($value === null ? '' : (string) $value);
        }
        fputcsv($output, $sanitized);
    }
    fclose($output);
    exit;
}

/**
 * Download a dependency-free XLSX workbook using inline string cells.
 * Keeping values as strings preserves patient IDs, phone numbers and dates
 * exactly as exported while remaining readable by Excel and the XLSX reader.
 */
function tdc_xlsx_download(string $filename, array $headers, array $rows): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'tdc-xlsx-');
    if ($tmp === false) {
        throw new RuntimeException('The XLSX export could not be prepared.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('The XLSX export could not be created.');
    }
    $xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    $sheetRows = array_merge([$headers], $rows);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $sheetXml .= '<row r="' . $excelRow . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $column = '';
            $n = $columnIndex + 1;
            while ($n > 0) { $remainder = ($n - 1) % 26; $column = chr(65 + $remainder) . $column; $n = (int) (($n - $remainder) / 26); }
            $sheetXml .= '<c r="' . $column . $excelRow . '" t="inlineStr"><is><t>' . $xml((string) ($value ?? '')) . '</t></is></c>';
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Patients" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function tdc_csv_upload_rows(array $file, array $requiredHeaders, ?array $allowedHeaders = null, int $maxRows = 2000, array $headerAliases = [], array $ignoredHeaders = []): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Select a valid CSV file.');
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('CSV files must be 2 MB or smaller.');
    if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Only CSV files are supported.');
    $handle = fopen((string) $file['tmp_name'], 'rb');
    if (!$handle) throw new RuntimeException('The uploaded CSV file could not be read.');
    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); throw new RuntimeException('The CSV file is empty.'); }
    $headers = array_map(static fn($value): string => strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);
    $originalHeaderCount = count($headers);
    $headerAliases = array_change_key_case($headerAliases, CASE_LOWER);
    $ignoredHeaders = array_map('strtolower', $ignoredHeaders);
    $normalizedHeaders = [];
    $keptIndexes = [];
    foreach ($headers as $index => $header) {
        $normalized = $headerAliases[$header] ?? $header;
        if (in_array($normalized, $ignoredHeaders, true)) continue;
        $normalizedHeaders[] = $normalized;
        $keptIndexes[] = $index;
    }
    $headers = $normalizedHeaders;
    if (count($headers) !== count(array_unique($headers))) {
        fclose($handle);
        throw new RuntimeException('The CSV header contains duplicate column names.');
    }
    $requiredHeaders = array_values(array_unique(array_map('strtolower', $requiredHeaders)));
    $allowedHeaders = $allowedHeaders === null ? null : array_values(array_unique(array_map('strtolower', $allowedHeaders)));
    $missingHeaders = array_values(array_diff($requiredHeaders, $headers));
    $unexpectedHeaders = $allowedHeaders === null ? [] : array_values(array_diff($headers, $allowedHeaders));
    if ($missingHeaders || $unexpectedHeaders) {
        fclose($handle);
        $details = [];
        if ($missingHeaders) $details[] = 'Missing columns: ' . implode(', ', $missingHeaders) . '.';
        if ($unexpectedHeaders) $details[] = 'Unexpected columns: ' . implode(', ', $unexpectedHeaders) . '.';
        if ($allowedHeaders !== null) $details[] = 'Expected columns: ' . implode(', ', $allowedHeaders) . '.';
        throw new RuntimeException('Import failed: the uploaded CSV does not match this module format. ' . implode(' ', $details));
    }
    $rows = [];
    while (($values = fgetcsv($handle)) !== false) {
        if (!array_filter($values, static fn($value): bool => trim((string) $value) !== '')) continue;
        $rowNumber = count($rows) + 2;
        if (count($values) !== count($keptIndexes) && count($values) !== $originalHeaderCount) {
            fclose($handle);
            throw new RuntimeException("CSV row $rowNumber has " . count($values) . ' fields; expected ' . count($keptIndexes) . '.');
        }
        if (count($rows) >= $maxRows) { fclose($handle); throw new RuntimeException("CSV import is limited to $maxRows rows."); }
        if (count($values) === count($keptIndexes) && count($keptIndexes) !== $originalHeaderCount) {
            $rows[] = array_combine($headers, $values);
        } else {
            $keptValues = array_map(static fn(int $index): string => (string)($values[$index] ?? ''), $keptIndexes);
            $rows[] = array_combine($headers, $keptValues);
        }
    }
    fclose($handle);
    return $rows;
}

/**
 * Read the first worksheet from an .xlsx upload using PHP's bundled XML/ZIP
 * extensions. The returned rows use the same normalized headers as the CSV
 * importer, so module validation and duplicate handling stay identical.
 */
function tdc_xlsx_upload_rows(array $file, array $requiredHeaders, ?array $allowedHeaders = null, int $maxRows = 2000, array $headerAliases = [], array $ignoredHeaders = []): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Select a valid CSV or XLSX file.');
    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('XLSX files must be 10 MB or smaller.');
    if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Only XLSX files are supported by this reader.');
    $zip = new ZipArchive();
    if ($zip->open((string) $file['tmp_name']) !== true) throw new RuntimeException('The uploaded XLSX file could not be opened.');
    try {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) throw new RuntimeException('The XLSX file is missing its workbook structure.');
        $workbook = simplexml_load_string($workbookXml, SimpleXMLElement::class, LIBXML_NONET);
        $rels = simplexml_load_string($relsXml, SimpleXMLElement::class, LIBXML_NONET);
        if (!$workbook || !$rels) throw new RuntimeException('The XLSX workbook structure is invalid.');
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook->registerXPathNamespace('m', $mainNs);
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $sheet = $workbook->xpath('//m:sheets/m:sheet[1]')[0] ?? null;
        $relationshipId = $sheet ? (string) $sheet->attributes($relNs)->id : '';
        $target = '';
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] === $relationshipId) { $target = (string) $relationship['Target']; break; }
        }
        if ($target === '') throw new RuntimeException('The XLSX file does not contain a readable worksheet.');
        $target = ltrim(str_replace('\\', '/', $target), '/');
        if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
        $sheetXml = $zip->getFromName($target);
        if ($sheetXml === false) throw new RuntimeException('The first XLSX worksheet could not be read.');
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false && ($shared = simplexml_load_string($sharedXml, SimpleXMLElement::class, LIBXML_NONET))) {
            $shared->registerXPathNamespace('m', $mainNs);
            foreach ($shared->children($mainNs)->si ?: [] as $item) { $text = ''; foreach ($item->children($mainNs)->t ?: [] as $t) $text .= (string) $t; $sharedStrings[] = trim($text); }
        }
        $sheet = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NONET);
        if (!$sheet) throw new RuntimeException('The XLSX worksheet is invalid.');
        $sheet->registerXPathNamespace('m', $mainNs);
        $allRows = [];
        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $xmlRow) {
            $row = [];
            foreach ($xmlRow->c as $cell) {
                $reference = (string) $cell['r'];
                $column = preg_replace('/\d+/', '', $reference);
                $value = '';
                $type = (string) $cell['t'];
                if ($type === 'inlineStr') { $text = ''; foreach ($cell->is->children($mainNs)->t ?: [] as $t) $text .= (string) $t; $value = trim($text); }
                else {
                    $value = (string) $cell->v;
                    if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) $value = $sharedStrings[(int) $value];
                }
                $row[$column] = $value;
            }
            if ($row) $allRows[] = $row;
        }
        if (!$allRows) throw new RuntimeException('The XLSX worksheet is empty.');
        $headerAliases = array_change_key_case($headerAliases, CASE_LOWER);
        $ignoredHeaders = array_map('strtolower', $ignoredHeaders);
        $requiredHeaders = array_values(array_unique(array_map('strtolower', $requiredHeaders)));
        $allowedHeaders = $allowedHeaders === null ? null : array_values(array_unique(array_map('strtolower', $allowedHeaders)));
        $headerIndex = -1; $headers = []; $keptColumns = [];
        foreach ($allRows as $index => $candidate) {
            $normalized = [];
            foreach ($candidate as $column => $header) {
                $key = strtolower(trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF"));
                $key = $headerAliases[$key] ?? $key;
                if (!in_array($key, $ignoredHeaders, true)) { $normalized[$column] = $key; }
            }
            if (!array_diff($requiredHeaders, array_values($normalized))) { $headerIndex = $index; $headers = array_values($normalized); $keptColumns = array_keys($normalized); break; }
        }
        if ($headerIndex < 0) throw new RuntimeException('Import failed: the XLSX worksheet does not contain the required columns: ' . implode(', ', $requiredHeaders) . '.');
        if (count($headers) !== count(array_unique($headers))) throw new RuntimeException('The XLSX header contains duplicate column names.');
        $unexpected = $allowedHeaders === null ? [] : array_values(array_diff($headers, $allowedHeaders));
        if ($unexpected) throw new RuntimeException('Import failed: unexpected columns: ' . implode(', ', $unexpected) . '.');
        $rows = [];
        foreach (array_slice($allRows, $headerIndex + 1) as $xmlRow) {
            $values = []; foreach ($keptColumns as $column) $values[] = (string) ($xmlRow[$column] ?? '');
            if (!array_filter($values, static fn($value): bool => trim((string) $value) !== '')) continue;
            if (count($rows) >= $maxRows) throw new RuntimeException("XLSX import is limited to $maxRows rows.");
            $rows[] = array_combine($headers, $values);
        }
        return $rows;
    } finally { $zip->close(); }
}
