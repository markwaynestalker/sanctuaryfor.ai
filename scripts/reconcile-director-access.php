<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director_access.php';
try{
    $o=getopt('',['directory:','original-sha256:','authorized-sha256:']);
    $r=LittleSparks\reconcileDirectorAccess((string)($o['directory']??''),(string)($o['original-sha256']??''),(string)($o['authorized-sha256']??''));
    echo json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){
    echo json_encode(['valid'=>false,'failure_code'=>$e instanceof LittleSparks\AccessReconciliationFailure?$e->stage:'internal_error',
        'receipt'=>$e instanceof LittleSparks\AccessReconciliationFailure?$e->receipt:null,'field'=>$e instanceof LittleSparks\AccessReconciliationFailure?$e->field:null,
        'row'=>$e instanceof LittleSparks\AccessReconciliationFailure?$e->row:null,'new_http_requests'=>0,'generation_requests'=>0,'credential_loaded'=>false,'database_opened'=>false],JSON_THROW_ON_ERROR)."\n";
    fwrite(STDERR,"Access reconciliation refused. Preserve both receipts; inspect the safe failure code. No key, network or database access was requested.\n");exit(1);
}
