<?php
declare(strict_types=1);
// Readiness only: no session, SQLite open, model call, configuration or secret output.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$marker=__DIR__.'/../.release-commit';$commit=null;
if(is_file($marker)&&filesize($marker)===41){$value=file_get_contents($marker);if(is_string($value)&&preg_match('/\A[a-f0-9]{40}\n\z/',$value))$commit=trim($value);}
$ok=PHP_VERSION_ID>=80300&&extension_loaded('pdo_sqlite')&&extension_loaded('curl')&&(!file_exists($marker)||$commit!==null);
http_response_code($ok?200:503);
echo json_encode(['status'=>$ok?'ok':'unavailable','commit'=>$commit],JSON_THROW_ON_ERROR);
