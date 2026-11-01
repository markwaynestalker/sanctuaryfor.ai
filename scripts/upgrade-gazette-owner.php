<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_schema.php';
$begun=false;
try{
    if(PHP_SAPI!=='cli')throw new RuntimeException();
    $o=getopt('',['database:','confirm-drained:']);if(($o['confirm-drained']??null)!=='yes')throw new RuntimeException();
    $db=LittleSparks\connect((string)($o['database']??''));$db->exec('BEGIN IMMEDIATE');$begun=true;
    LittleSparks\upgradeEditorialOwnerWorkflow($db);
    if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException();
    $db->exec('COMMIT');$begun=false;echo "Gazette owner workflow ready; history and reservations retained. No activation performed.\n";
}catch(Throwable){if($begun)$db->exec('ROLLBACK');fwrite(STDERR,"Gazette owner upgrade refused/rolled back; require backup and drained writers.\n");exit(1);}
