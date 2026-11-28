<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/conversation_messages_schema.php';
$db=LittleSparks\connect($argv[1]);$before=$db->query("SELECT sql FROM sqlite_master WHERE name='extended_alternation'")->fetchColumn();
if(!$before)throw new RuntimeException('Original trigger missing');$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateFlexibleSpeakerOrder($db);if(!LittleSparks\flexibleSpeakerOrderInstalled($db))throw new RuntimeException('Capability not installed');$db->exec('ROLLBACK');
if(LittleSparks\flexibleSpeakerOrderInstalled($db)||$db->query("SELECT sql FROM sqlite_master WHERE name='extended_alternation'")->fetchColumn()!==$before)throw new RuntimeException('Rollback did not preserve trigger');
