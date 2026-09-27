<?php
// Local disposable test server; uses the application's real login and authorization.
declare(strict_types=1);
$schema=getenv('TDC_MASTER_DB') ?: '';
if(PHP_SAPI!=='cli-server'||!preg_match('/^tdc_role_test_[a-f0-9]{12}$/',$schema)||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('#^/auth/(assets/[a-zA-Z0-9_.-]+|uploads/tareydermacliniclogo\.png)$#',$path))return false;
if(!preg_match('#^/auth/(auth\.php|logout\.php|pharmacy_receipt\.php|print_laboratory\.php|pages/(home|reception|patients|doctors|laboratory|pharmacy|accounting|reports|setup)\.php)$#',$path)){http_response_code(404);exit;}
require __DIR__.'/../db.local.php';
if(!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)){http_response_code(500);exit;}
require __DIR__.'/../db.php';
$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.$schema.';charset=utf8mb4',DB_USER,DB_PASS,$options);
require __DIR__.'/..'.$path;
