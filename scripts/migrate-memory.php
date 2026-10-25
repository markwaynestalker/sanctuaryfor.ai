<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/memory_schema.php';
$transaction=false;
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateMemorySchema($db);
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Integrity check failed.');
    $db->exec('COMMIT');$transaction=false;echo "Little Sparks V5 memory schema ready; V4 history preserved. No AI call or activation performed.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Memory migration refused or rolled back. Check private V4 path, backup, pending activity, configuration and integrity.\n");exit(1);}
