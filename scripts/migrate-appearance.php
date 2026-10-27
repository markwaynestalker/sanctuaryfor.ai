<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/appearance_schema.php';$transaction=false;
try{$o=getopt('',['database:']);$db=LittleSparks\connect((string)($o['database']??''));$db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateAppearance($db);if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed.');$db->exec('COMMIT');echo "Appearance add-on revision 1 ready; existing V6 data preserved. No provider call.\n";}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Appearance migration refused or rolled back. Require a backed-up, drained private V6 database.\n");exit(1);}
