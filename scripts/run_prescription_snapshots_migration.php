<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../db.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('This runner is for the local clinic database. Apply the reviewed SQL explicitly in other environments.');
$sql=file_get_contents(__DIR__.'/../database/prescription_snapshots_migration.sql');
foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') { $query=$pdo->query($statement); $query->closeCursor(); }
echo "Prescription snapshot columns ready; no historical rows backfilled.\n";
