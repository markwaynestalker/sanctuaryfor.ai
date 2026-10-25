<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

// Includes schema SQL, settings, credentials, history, ledgers and every old row.
function identityPreservationDigest(PDO $db): string
{
    $hash=hash_init('sha256');
    foreach($db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND tbl_name NOT IN ('identity_schema','resident_identities','identity_edits') ORDER BY type,name")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT IN ('identity_schema','resident_identities','identity_edits') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/\A[a-z_][a-z0-9_]*\z/',$table))throw new \RuntimeException('Unrecognized schema.');
        foreach($db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }
    return hash_final($hash);
}
// Caller holds BEGIN IMMEDIATE. No change to PRAGMA user_version or any V5 table.
function migrateIdentitySchema(PDO $db): void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==5)throw new \RuntimeException('Identity add-on requires V5.');
    if(identityEnabled($db))return;
    $expected=['settings','residents','rate_limits','home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events','simulation_runs','resident_preferences','ai_attempts','ai_conversations','ai_lease','simulation_opportunities','friendship_identities','memory_stories','memory_receipts','friendship_memories','friendship_themes','memory_processed'];sort($expected);
    $actual=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    if($actual!==$expected)throw new \RuntimeException('Unrecognized V5 schema; no identity changes applied.');
    assertIdentityIdle($db);secret($db);identityConfiguration();$before=identityPreservationDigest($db);
    $db->exec("CREATE TABLE identity_schema(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO identity_schema VALUES(1,1);
        CREATE TABLE resident_identities(resident_id TEXT PRIMARY KEY REFERENCES residents(id) ON DELETE RESTRICT,
            description TEXT NOT NULL CHECK(length(description)<=4000),color TEXT NOT NULL,
            revision INTEGER NOT NULL CHECK(revision BETWEEN 0 AND 1000001),updated_at TEXT NOT NULL);
        CREATE TABLE identity_edits(id INTEGER PRIMARY KEY,resident_id TEXT NOT NULL REFERENCES resident_identities(resident_id) ON DELETE RESTRICT,
            request_id TEXT NOT NULL,request_hash TEXT NOT NULL,previous_description TEXT NOT NULL,description TEXT NOT NULL,
            previous_color TEXT NOT NULL,color TEXT NOT NULL,revision INTEGER NOT NULL CHECK(revision BETWEEN 1 AND 1000001),created_at TEXT NOT NULL,
            UNIQUE(resident_id,request_id),UNIQUE(resident_id,revision));
        CREATE INDEX identity_edits_daily ON identity_edits(resident_id,created_at);
        CREATE TRIGGER identity_edit_no_update BEFORE UPDATE ON identity_edits BEGIN SELECT RAISE(ABORT,'Identity audit is immutable'); END;
        CREATE TRIGGER identity_edit_no_delete BEFORE DELETE ON identity_edits BEGIN SELECT RAISE(ABORT,'Identity audit is immutable'); END;
        INSERT INTO resident_identities SELECT id,'',color,0,created_at FROM residents;");
    if(!hash_equals($before,identityPreservationDigest($db)))throw new \RuntimeException('V5 preservation check failed.');
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new \RuntimeException('Integrity check failed.');
}
