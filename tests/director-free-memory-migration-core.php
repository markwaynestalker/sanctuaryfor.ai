<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/director_free_memory.php';
$db=LittleSparks\connect($argv[1]);$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorFreeMemory($db);if(!LittleSparks\directorFreeMemoryInstalled($db))throw new RuntimeException('Capability missing');$db->exec('ROLLBACK');if(LittleSparks\directorFreeMemoryInstalled($db))throw new RuntimeException('Rollback failed');
