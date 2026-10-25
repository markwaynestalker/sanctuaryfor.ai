<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director_access.php';
try{
    $o=getopt('',['directory:','original-sha256:','authorized-sha256:']);
    $r=LittleSparks\reconcileDirectorAccess((string)($o['directory']??''),(string)($o['original-sha256']??''),(string)($o['authorized-sha256']??''));
    echo json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable){fwrite(STDERR,"Access reconciliation refused. Preserve both receipts; check the full hashes and safe audit structure. No key, network or database access was requested.\n");exit(1);}
