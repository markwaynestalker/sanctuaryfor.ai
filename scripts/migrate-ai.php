<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/ai_schema.php';
$transaction=false;
try{
    $options=getopt('',['database:']);
    $db=LittleSparks\connect(LittleSparks\databasePath((string)($options['database']??'')));
    $db->exec('PRAGMA foreign_keys=OFF');
    $db->exec('BEGIN IMMEDIATE');$transaction=true;
    LittleSparks\migrateAiSchema($db);
    if($db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Foreign key verification failed.');
    $db->exec('COMMIT');$transaction=false;$db->exec('PRAGMA foreign_keys=ON');
    echo "Little Sparks schema V3 ready. V2 records preserved; AI remains disabled.\n";
}catch(Throwable){
    if($transaction)$db->exec('ROLLBACK');
    if(isset($db))$db->exec('PRAGMA foreign_keys=ON');
    fwrite(STDERR,"AI migration refused or rolled back. Verify the private V2 path, schema, backup, and foreign keys.\n");exit(1);
}
