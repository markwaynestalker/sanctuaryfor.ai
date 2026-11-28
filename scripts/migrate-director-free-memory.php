<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/director_free_memory.php';$transaction=false;
try{$o=getopt('',['database:']);$db=LittleSparks\connect((string)($o['database']??''));$db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateDirectorFreeMemory($db);if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed.');$db->exec('COMMIT');echo "Director memory capability ready. No provider call.\n";}
catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Director memory migration refused or rolled back. Require backed-up, drained V6 database.\n");exit(1);}
