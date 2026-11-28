<?php
 declare(strict_types=1);
 if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
 require __DIR__.'/../app/village.php';require __DIR__.'/../app/conversation_messages_schema.php';$transaction=false;
 try{$o=getopt('',['database:']);$db=LittleSparks\connect((string)($o['database']??''));$db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateFlexibleSpeakerOrder($db);
     if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed.');
     $db->exec('COMMIT');echo "Flexible speaker-order capability ready. No provider call.\n";
 }catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Speaker-order migration refused or rolled back. Require backed-up, drained V6 database with multi-message storage.\n");exit(1);}
