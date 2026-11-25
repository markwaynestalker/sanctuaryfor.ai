<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/narrative_story.php';$transaction=false;
try{$options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));$db->exec('BEGIN IMMEDIATE');$transaction=true;LittleSparks\migrateNarrativeStories($db);
 if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed');$db->exec('COMMIT');echo "Narrative story ledger ready; no provider request.\n";
}catch(Throwable){if($transaction)$db->exec('ROLLBACK');fwrite(STDERR,"Narrative story migration refused or rolled back; use a backed-up drained V6 database.\n");exit(1);}
