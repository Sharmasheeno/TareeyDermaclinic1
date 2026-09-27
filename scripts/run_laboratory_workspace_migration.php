<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';

$sql = file_get_contents(__DIR__ . '/../database/laboratory_workspace_migration.sql');
if ($sql === false) {
    fwrite(STDERR, "Missing migration file: database/laboratory_workspace_migration.sql\n");
    exit(1);
}

$statements = preg_split('/;\s*\r?\n/', $sql);
foreach ($statements as $statement) {
    $statement = trim((string) $statement);
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement . ';');
}

echo "Laboratory workspace schema migration applied.\n";
