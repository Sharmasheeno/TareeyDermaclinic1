<?php
declare(strict_types=1);

function tdc_csv_download(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) fputcsv($output, $row);
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
