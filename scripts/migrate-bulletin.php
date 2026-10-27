<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/bulletin_schema.php';$transaction=false;
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateBulletin($db);
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Integrity check failed.');
    $db->exec('COMMIT');echo "Bulletin add-on revision 1 ready. Existing V6 data preserved; posting remains opt-in.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Bulletin migration refused or rolled back. Check private V6 backup and drained activity.\n");exit(1);}
