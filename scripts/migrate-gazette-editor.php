<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_schema.php';$transaction=false;
try{
    $options=getopt('',['database:','confirm-drained:']);
    if(($options['confirm-drained']??null)!=='yes'||!is_string($options['database']??null)||trim($options['database'])==='')throw new RuntimeException('Explicit database and drained-writer confirmation required.');
    $db=LittleSparks\connect((string)$options['database']);$db->exec('BEGIN IMMEDIATE');$transaction=true;
    LittleSparks\migrateEditorialLedger($db);
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Integrity check failed.');
    $db->exec('COMMIT');$transaction=false;
    echo "Editorial ledger revision 1 ready. V6 history preserved; generation remains inactive.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Editorial migration refused or rolled back. Require a verified private V6 backup and separately drained writers.\n");exit(1);}
