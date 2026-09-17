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

/**
 * Fetch the set of existing patient phone keys (normalized) once, for batch
 * duplicate detection during CSV import.
 *
 * @return array<string,true>
 */
function tdc_existing_phone_keys(PDO $pdo): array
{
    $keys = [];
    foreach ($pdo->query('SELECT PatientPhone FROM patients WHERE PatientPhone IS NOT NULL AND PatientPhone <> ""') as $row) {
        $norm = tdc_norm_phone((string) $row['PatientPhone']);
        if ($norm !== '') {
            $keys[$norm] = true;
        }
    }
    return $keys;
}

/**
 * Find an existing patient matching a (normalized) phone, used by the manual
 * registration form's duplicate warning. Returns the matched row or null.
 */
function tdc_existing_patient_by_phone(PDO $pdo, string $phone): ?array
{
    $norm = tdc_norm_phone($phone);
    if ($norm === '') {
        return null;
    }
    foreach ($pdo->query('SELECT PatientID, PatientName, PatientPhone FROM patients WHERE PatientPhone IS NOT NULL AND PatientPhone <> ""') as $row) {
        if (tdc_norm_phone((string) $row['PatientPhone']) === $norm) {
            return $row;
        }
    }
    return null;
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

function tdc_csv_upload_rows(array $file, array $requiredHeaders, int $maxRows = 2000): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Select a valid CSV file.');
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('CSV files must be 2 MB or smaller.');
    if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Only CSV files are supported.');
    $handle = fopen((string) $file['tmp_name'], 'rb');
    if (!$handle) throw new RuntimeException('The uploaded CSV file could not be read.');
    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); throw new RuntimeException('The CSV file is empty.'); }
    $headers = array_map(static fn($value): string => strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);
    foreach ($requiredHeaders as $required) if (!in_array(strtolower($required), $headers, true)) { fclose($handle); throw new RuntimeException("CSV column '$required' is required."); }
    $rows = [];
    while (($values = fgetcsv($handle)) !== false) {
        if (count($rows) >= $maxRows) { fclose($handle); throw new RuntimeException("CSV import is limited to $maxRows rows."); }
        if (!array_filter($values, static fn($value): bool => trim((string) $value) !== '')) continue;
        $values = array_pad($values, count($headers), '');
        $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
    }
    fclose($handle);
    return $rows;
}
