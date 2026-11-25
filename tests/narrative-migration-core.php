<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/ai_budget.php';require_once __DIR__.'/../app/narrative_story.php';
$db=LittleSparks\connect($argv[1]);$before=$db->query("SELECT type,name,sql FROM sqlite_master ORDER BY type,name")->fetchAll();$version=(int)$db->query('PRAGMA user_version')->fetchColumn();$settings=$db->query('SELECT * FROM settings ORDER BY name')->fetchAll();
$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateNarrativeStories($db);if(!LittleSparks\narrativeStoryInstalled($db))throw new RuntimeException('migration not installed');$db->exec('ROLLBACK');
if(LittleSparks\narrativeStoryInstalled($db)||$before!==$db->query("SELECT type,name,sql FROM sqlite_master ORDER BY type,name")->fetchAll()||(int)$db->query('PRAGMA user_version')->fetchColumn()!==$version||$settings!==$db->query('SELECT * FROM settings ORDER BY name')->fetchAll())throw new RuntimeException('rollback did not preserve schema');
putenv('LITTLE_SPARKS_NARRATIVE_STORIES_ENABLED=1');try{LittleSparks\narrativeStoryEnabled($db);throw new RuntimeException('missing migration accepted');}catch(LittleSparks\AiGuard $e){if($e->reason!=='configuration_invalid')throw $e;}putenv('LITTLE_SPARKS_NARRATIVE_STORIES_ENABLED');if(LittleSparks\narrativeStoryEnabled($db))throw new RuntimeException('default enabled');
echo "PASS additive migration rollback, unchanged V6 and default-off missing-schema fence\n";
