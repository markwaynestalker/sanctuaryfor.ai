<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
use RuntimeException;
require_once __DIR__.'/activity_schema.php';

function v4MemoryDigest(PDO $db):string
{
    $hash=hash_init('sha256');hash_update($hash,activityPreservationDigest($db));
    foreach($db->query('SELECT * FROM simulation_opportunities ORDER BY rowid')->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));return hash_final($hash);
}
// Additive migration. Caller owns BEGIN IMMEDIATE; foreign keys remain ON.
function migrateMemorySchema(PDO $db):void
{
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if($version===5){foreach(['friendship_identities','memory_stories','memory_receipts','friendship_memories','friendship_themes','memory_processed'] as $t)$db->query("SELECT * FROM $t LIMIT 0");return;}
    if($version!==4)throw new RuntimeException('Memory migration requires V4.');
    if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn()||$db->query("SELECT 1 FROM ai_lease WHERE owner IS NOT NULL AND expires_at>=CAST(strftime('%s','now') AS INTEGER)")->fetchColumn())throw new RuntimeException('Pause and drain simulation before migration.');
    $cfg=memoryConfiguration();$before=v4MemoryDigest($db);secret($db);
    $db->exec("CREATE TABLE friendship_identities (
        id TEXT PRIMARY KEY, resident_a TEXT NOT NULL,resident_b TEXT NOT NULL,UNIQUE(resident_a,resident_b),
        FOREIGN KEY(resident_a,resident_b) REFERENCES relationships(resident_a,resident_b) ON DELETE RESTRICT);
        CREATE TABLE memory_stories (
            id TEXT PRIMARY KEY,kind TEXT NOT NULL CHECK(kind IN ('world_fact','statement','interpretation','rumor')),
            origin_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            subject_id TEXT REFERENCES residents(id) ON DELETE RESTRICT,
            source_event_id INTEGER UNIQUE REFERENCES events(id) ON DELETE RESTRICT,
            source_conversation_id TEXT REFERENCES conversations(id) ON DELETE RESTRICT,source_sequence INTEGER,
            object_id TEXT REFERENCES world_objects(id) ON DELETE RESTRICT,object_kind TEXT,object_variant TEXT,
            topic TEXT NOT NULL CHECK(topic IN ('garden','stars','welcome','quiet')),
            summary TEXT NOT NULL CHECK(length(summary) BETWEEN 1 AND 120),significance INTEGER NOT NULL CHECK(significance BETWEEN 1 AND 5),created_at TEXT NOT NULL,
            FOREIGN KEY(source_conversation_id,source_sequence) REFERENCES messages(conversation_id,sequence) ON DELETE RESTRICT,
            CHECK((kind='world_fact' AND source_event_id IS NOT NULL AND source_conversation_id IS NULL AND source_sequence IS NULL)
               OR (kind<>'world_fact' AND source_event_id IS NULL AND source_conversation_id IS NOT NULL AND source_sequence IN (0,1) AND object_id IS NULL)));
        CREATE TABLE memory_receipts (
            id TEXT PRIMARY KEY,story_id TEXT NOT NULL REFERENCES memory_stories(id) ON DELETE RESTRICT,
            resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            heard_from_id TEXT REFERENCES residents(id) ON DELETE RESTRICT,parent_id TEXT REFERENCES memory_receipts(id) ON DELETE RESTRICT,
            via_conversation_id TEXT REFERENCES conversations(id) ON DELETE RESTRICT,via_sequence INTEGER,
            hops INTEGER NOT NULL CHECK(hops BETWEEN 0 AND 4),learned_at TEXT NOT NULL,
            FOREIGN KEY(via_conversation_id,via_sequence) REFERENCES messages(conversation_id,sequence) ON DELETE RESTRICT,
            UNIQUE(story_id,resident_id),CHECK((hops=0 AND heard_from_id IS NULL AND parent_id IS NULL AND via_conversation_id IS NULL AND via_sequence IS NULL)
                OR (hops>0 AND heard_from_id IS NOT NULL AND parent_id IS NOT NULL AND via_conversation_id IS NOT NULL AND via_sequence IN (0,1) AND heard_from_id<>resident_id)));
        CREATE INDEX receipt_resident ON memory_receipts(resident_id,learned_at);
        CREATE TABLE friendship_memories (
            friendship_id TEXT NOT NULL REFERENCES friendship_identities(id) ON DELETE RESTRICT,
            story_id TEXT NOT NULL REFERENCES memory_stories(id) ON DELETE RESTRICT,
            conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE RESTRICT,created_at TEXT NOT NULL,
            PRIMARY KEY(friendship_id,story_id));
        CREATE TABLE friendship_themes (
            friendship_id TEXT NOT NULL REFERENCES friendship_identities(id) ON DELETE RESTRICT,
            topic TEXT NOT NULL CHECK(topic IN ('garden','stars','welcome','quiet')),
            encounters INTEGER NOT NULL CHECK(encounters BETWEEN 1 AND 100000),last_at TEXT NOT NULL,PRIMARY KEY(friendship_id,topic));
        CREATE TABLE memory_processed(conversation_id TEXT PRIMARY KEY REFERENCES conversations(id) ON DELETE RESTRICT);
        PRAGMA user_version=5;");
    foreach(['friendship_identities','memory_stories','memory_receipts','friendship_memories','memory_processed'] as $table){
        $db->exec("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT,'Memory provenance is immutable'); END;
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'Memory provenance is immutable'); END;");
    }
    foreach($db->query('SELECT resident_a,resident_b FROM relationships ORDER BY resident_a,resident_b')->fetchAll() as $r)ensureFriendshipIdentity($db,$r['resident_a'],$r['resident_b']);
    $limit=$cfg['backfill'];
    foreach($db->query("SELECT id FROM events WHERE visibility='public' AND kind IN ('plant_tree','plant_flowers','plant_bush','build_path','place_decoration','adopt_pet') ORDER BY id LIMIT $limit")->fetchAll() as $e)recordWorldMemory($db,(int)$e['id']);
    $q=$db->query("SELECT c.id FROM conversations c JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        JOIN messages a ON a.conversation_id=c.id AND a.sequence=0 JOIN messages b ON b.conversation_id=c.id AND b.sequence=1
        JOIN relationships f ON f.resident_a=MIN(a.resident_id,b.resident_id) AND f.resident_b=MAX(a.resident_id,b.resident_id)
        WHERE c.visibility='public' ORDER BY c.created_at,c.id LIMIT $limit");
    foreach($q->fetchAll() as $c)if(publicMemoryConversation($db,$c['id']))conversationMemory($db,$c['id']);
    if($before!==v4MemoryDigest($db))throw new RuntimeException('V4 preservation failed.');
}
