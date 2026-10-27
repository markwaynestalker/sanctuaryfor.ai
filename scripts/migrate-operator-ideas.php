<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/operator_ideas_schema.php';
$transaction=false;
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateOperatorIdeas($db);$db->exec('COMMIT');$transaction=false;
    echo "Operator idea add-on revision 1 ready; V6 and existing data preserved. No activation or provider calls.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Operator idea migration refused or rolled back. Check backup, private V6 path and drained activity.\n");exit(1);}
