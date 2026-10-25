<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/identity_schema.php';
$transaction=false;
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateIdentitySchema($db);
    $db->exec('COMMIT');$transaction=false;echo "Identity add-on revision 1 ready on V5; original data preserved. New simulation stays paused. No Director activation or paid call.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Identity migration refused or rolled back. Check private V5 path, backup, pending activity, configuration and integrity.\n");exit(1);}
