<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';
require_once __DIR__.'/../app/selection_accounting.php';
$transaction=false;
try{
    $o=getopt('',['database:']);$db=LittleSparks\connect((string)($o['database']??''));
    $db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateSelectionLedger($db);
    if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed');
    $db->exec('COMMIT');echo "Selection reservation ledger ready. No provider request.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Selection migration refused or rolled back; use a backed-up drained V6 database.\n");exit(1);}