<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/memory_schema.php';
require_once __DIR__.'/director_accounting.php';
require_once __DIR__.'/identity_schema.php';

function identityAdoptionDigest(PDO $db):string
{
    $hash=hash_init('sha256');
    foreach(['identity_schema','resident_identities','identity_edits'] as $table){
        foreach($db->query("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='$table' ORDER BY type,name")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
        foreach($db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }return hash_final($hash);
}

function v5DirectorDigest(PDO $db):string
{
    $hash=hash_init('sha256');hash_update($hash,v4MemoryDigest($db));
    foreach(['friendship_identities','memory_stories','memory_receipts','friendship_memories','friendship_themes','memory_processed'] as $table){
        foreach($db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }return hash_final($hash);
}
function migrateDirectorSchema(PDO $db):void
{
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if($version===6){
        foreach(['resident_identities','identity_edits','resident_states','resident_goals','director_registry','director_sessions','director_attempts','director_runs','director_transmissions','director_notes'] as $table)$db->query("SELECT * FROM $table LIMIT 0");secret($db);
        if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn()||$db->query('SELECT 1 FROM ai_lease WHERE owner IS NOT NULL LIMIT 1')->fetchColumn())throw new \RuntimeException('Drain claims before accounting upgrade.');
        upgradeDirectorAccounting($db);return;
    }
    if($version!==5)throw new \RuntimeException('Director migration requires V5.');
    if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn()||$db->query("SELECT 1 FROM ai_lease WHERE owner IS NOT NULL AND expires_at>=CAST(strftime('%s','now') AS INTEGER)")->fetchColumn())throw new \RuntimeException('Pause and drain the scheduler before migration.');
    assertIdentityIdle($db);identityConfiguration();$before=v5DirectorDigest($db);secret($db);
    // Bare V5 remains migratable for old offline fixtures; installed add-ons are
    // adopted byte-for-byte, including original constraints, indexes and audits.
    if(!identityEnabled($db))migrateIdentitySchema($db);
    $identityBefore=identityAdoptionDigest($db);
    if($db->query('SELECT 1 FROM residents r LEFT JOIN resident_identities i ON i.resident_id=r.id WHERE i.resident_id IS NULL LIMIT 1')->fetchColumn())throw new \RuntimeException('Incomplete identities; migration refused.');
    $db->exec("CREATE TABLE resident_states (
            resident_id TEXT PRIMARY KEY REFERENCES residents(id) ON DELETE RESTRICT,
            activity TEXT NOT NULL CHECK(activity='available'),location TEXT NOT NULL CHECK(location='commons'),since TEXT NOT NULL);
        CREATE TABLE resident_goals (
            id TEXT PRIMARY KEY,resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            description TEXT NOT NULL CHECK(length(description) BETWEEN 1 AND 160),status TEXT NOT NULL CHECK(status IN ('proposed','active','completed','cancelled')),
            source_event_id INTEGER REFERENCES events(id) ON DELETE RESTRICT,created_at TEXT NOT NULL);
        CREATE INDEX resident_goal_status ON resident_goals(resident_id,status,created_at);
        CREATE TABLE director_registry (
            fingerprint TEXT PRIMARY KEY,agent_id TEXT NOT NULL,model TEXT NOT NULL,instructions TEXT NOT NULL,
            configuration TEXT NOT NULL,registered_at TEXT NOT NULL);
        CREATE TABLE director_sessions (
            id TEXT PRIMARY KEY,fingerprint TEXT NOT NULL REFERENCES director_registry(fingerprint) ON DELETE RESTRICT,
            scope TEXT NOT NULL,agent_id TEXT NOT NULL,runtime TEXT NOT NULL CHECK(runtime IN ('agents','responses')),
            provider_session_id TEXT, state TEXT NOT NULL CHECK(state IN ('new','ready','unavailable','uncertain')),
            turns INTEGER NOT NULL DEFAULT 0 CHECK(turns BETWEEN 0 AND 8),last_turn_id TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
        CREATE INDEX director_session_scope ON director_sessions(fingerprint,scope,created_at);
        CREATE TABLE director_attempts (
            id TEXT PRIMARY KEY,period TEXT NOT NULL UNIQUE,session_id TEXT NOT NULL REFERENCES director_sessions(id),
            day TEXT NOT NULL,month TEXT NOT NULL,runtime TEXT NOT NULL CHECK(runtime IN ('agents','responses')),
            context_sha256 TEXT NOT NULL,contract_version TEXT NOT NULL CHECK(contract_version='sanctuary.director.v1'),
            request_slots INTEGER NOT NULL CHECK(request_slots BETWEEN 2 AND 8),
            started_requests INTEGER NOT NULL DEFAULT 0 CHECK(started_requests BETWEEN 0 AND request_slots),
            reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd BETWEEN 1 AND 1000000),
            input_tokens INTEGER,output_tokens INTEGER,estimated_microusd INTEGER,
            state TEXT NOT NULL CHECK(state IN ('reserved','applied','rejected','uncertain')),created_at TEXT NOT NULL);
        CREATE INDEX director_attempt_day ON director_attempts(day);
        CREATE INDEX director_attempt_month ON director_attempts(month);
        CREATE TABLE director_runs (
            run_id TEXT PRIMARY KEY REFERENCES simulation_runs(id) ON DELETE RESTRICT,
            session_id TEXT NOT NULL REFERENCES director_sessions(id) ON DELETE RESTRICT,
            fingerprint TEXT NOT NULL REFERENCES director_registry(fingerprint) ON DELETE RESTRICT,
            conversation_id TEXT UNIQUE REFERENCES conversations(id) ON DELETE RESTRICT,
            topic TEXT NOT NULL CHECK(length(topic) BETWEEN 1 AND 64),created_at TEXT NOT NULL);
        CREATE TABLE director_transmissions (
            conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE RESTRICT,
            sequence INTEGER NOT NULL CHECK(sequence IN (0,1)),receipt_id TEXT NOT NULL REFERENCES memory_receipts(id) ON DELETE RESTRICT,
            listener_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,quote TEXT NOT NULL CHECK(length(quote) BETWEEN 1 AND 160),
            PRIMARY KEY(conversation_id,sequence),FOREIGN KEY(conversation_id,sequence) REFERENCES messages(conversation_id,sequence) ON DELETE RESTRICT);
        CREATE TABLE director_notes (
            id TEXT PRIMARY KEY,resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            other_resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE RESTRICT,sequence INTEGER NOT NULL CHECK(sequence IN (0,1)),
            quote TEXT NOT NULL CHECK(length(quote) BETWEEN 1 AND 160),classification TEXT NOT NULL CHECK(classification IN ('preference','interpretation','rumor')),
            created_at TEXT NOT NULL,FOREIGN KEY(conversation_id,sequence) REFERENCES messages(conversation_id,sequence) ON DELETE RESTRICT,
            UNIQUE(resident_id,conversation_id,sequence,quote));
        CREATE INDEX director_note_recall ON director_notes(resident_id,other_resident_id,created_at);
        PRAGMA user_version=6;");
    foreach(['director_registry','director_runs','director_transmissions','director_notes'] as $table)$db->exec("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT,'Director history is immutable'); END; CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'Director history is immutable'); END;");
    $db->exec("INSERT INTO resident_states SELECT id,'available','commons',created_at FROM residents;");
    upgradeDirectorAccounting($db);
    if($before!==v5DirectorDigest($db))throw new \RuntimeException('V5 preservation failed.');
    if($identityBefore!==identityAdoptionDigest($db))throw new \RuntimeException('Identity adoption preservation failed.');
}
