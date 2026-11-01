<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_schema.php';
$begun=false;$phase='admission';
try{
    if(PHP_SAPI!=='cli')throw new RuntimeException();
    $o=getopt('',['database:','confirm-drained:']);if(($o['confirm-drained']??null)!=='yes')throw new RuntimeException();
    $phase='open';$db=LittleSparks\connect((string)($o['database']??''));
    $phase='lock';$db->exec('BEGIN IMMEDIATE');$begun=true;
    $phase='schema';
    LittleSparks\upgradeEditorialOwnerWorkflow($db);
    $phase='integrity';if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException();
    $phase='commit';
    $db->exec('COMMIT');$begun=false;echo "Gazette owner workflow ready; history and reservations retained. No activation performed.\n";
}catch(Throwable $error){
    if($begun){try{$db->exec('ROLLBACK');}catch(Throwable){}}
    $sqlite=$error instanceof PDOException?(int)($error->errorInfo[1]??0):0;
    $code=in_array($sqlite&255,[5,6],true)?'database_busy':($phase==='open'?'database_open_failed':
        ($phase==='integrity'?'integrity_failure':($error instanceof PDOException?'schema_failure':'migration_refused')));
    echo json_encode(['status'=>'migration_failed','phase'=>$phase,'code'=>$code,'paid_calls'=>0])."\n";
    exit(1);
}
