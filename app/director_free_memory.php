<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
/** Append-only Director-authored private recollections, never verified facts. */
function directorFreeMemoryInstalled(PDO $db):bool
{
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='director_free_memory_meta'")->fetchColumn())return false;
    if((int)$db->query('SELECT revision FROM director_free_memory_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported Director memory revision.');
    $db->query('SELECT id,run_id,resident_id,body,created_at FROM director_free_memories LIMIT 0');return true;
}
function migrateDirectorFreeMemory(PDO $db):void
{
    if(!directorEnabled($db))throw new \RuntimeException('V6 required.');assertIdentityIdle($db);if(directorFreeMemoryInstalled($db))return;
    $db->exec("CREATE TABLE director_free_memory_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));INSERT INTO director_free_memory_meta VALUES(1,1);
        CREATE TABLE director_free_memories(id TEXT PRIMARY KEY,run_id TEXT NOT NULL REFERENCES director_runs(run_id) ON DELETE RESTRICT,resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,body TEXT NOT NULL CHECK(length(body)>0),created_at TEXT NOT NULL);
        CREATE INDEX director_free_memories_holder ON director_free_memories(resident_id,created_at DESC,id);");
    foreach(['director_free_memory_meta','director_free_memories'] as $table)foreach(['UPDATE','DELETE'] as $op)$db->exec("CREATE TRIGGER {$table}_no_{$op} BEFORE $op ON $table BEGIN SELECT RAISE(ABORT,'Memory is immutable'); END;");
}
function directorFreeMemories(PDO $db,string $holder,int $limit):array
{
    if(!directorFreeMemoryInstalled($db)||$limit<=0)return [];
    $q=$db->prepare('SELECT body,created_at FROM director_free_memories WHERE resident_id=? ORDER BY created_at DESC,id DESC LIMIT ?');$q->bindValue(1,$holder);$q->bindValue(2,$limit,PDO::PARAM_INT);$q->execute();
    return array_map(static fn($row)=>['text'=>$row['body'],'at'=>$row['created_at'],'status'=>'unverified'], $q->fetchAll());
}
function saveDirectorFreeMemories(PDO $db,string $run,array $proposal,array $pair,string $time):void
{
    if(!directorFreeMemoryInstalled($db))throw new AiGuard('configuration_invalid');$ids=array_column($pair,'id');
    foreach($proposal['notes'] as $note){if(!in_array($note['resident_id'],$ids,true))throw new AiGuard('apply_rejected');
        $db->prepare('INSERT INTO director_free_memories VALUES (?,?,?,?,?)')->execute([bin2hex(random_bytes(16)),$run,$note['resident_id'],$note['text'],$time]);}
}
