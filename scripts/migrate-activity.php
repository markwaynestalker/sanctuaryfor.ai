<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity_schema.php';
$transaction=false;
try{
    $options=getopt('',['database:']);
    $db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('PRAGMA foreign_keys=OFF');$db->exec('BEGIN IMMEDIATE');$transaction=true;
    LittleSparks\migrateActivitySchema($db);
    if($db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Foreign key check failed.');
    $db->exec('COMMIT');$transaction=false;$db->exec('PRAGMA foreign_keys=ON');
    echo "Little Sparks V4 ready. Existing data preserved; scheduler and paid AI remain disabled by default.\n";
}catch(Throwable){
    if($transaction)$db->exec('ROLLBACK');
    if(isset($db))$db->exec('PRAGMA foreign_keys=ON');
    fwrite(STDERR,"Activity migration refused or rolled back. Verify the private V3 path, backup and schema.\n");exit(1);
}
