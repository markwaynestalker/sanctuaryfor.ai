<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director_schema.php';$transaction=false;
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateDirectorSchema($db);
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Integrity check failed.');
    $db->exec('COMMIT');$transaction=false;echo "V6 Director/identity foundation ready; V5 records preserved. No provider call or activation performed.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Director migration refused or rolled back. Pause/drain scheduling and check the private V5 path, configuration, backup and integrity.\n");exit(1);}
