<?php
declare(strict_types=1);
namespace LittleSparks;

use PDO;
use RuntimeException;

function worldDigest(PDO $db): string
{
    $hash=hash_init('sha256');
    foreach (['settings','residents','rate_limits','home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events','simulation_runs'] as $table) {
        $columns=$db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) throw new RuntimeException('Unrecognized V2 database.');
        $order=implode(',',array_map(fn($column)=>'"'.str_replace('"','""',$column['name']).'"',$columns));
        $rows=$db->query("SELECT * FROM $table ORDER BY $order");
        while($row=$rows->fetch(PDO::FETCH_ASSOC))hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }
    return hash_final($hash);
}

// Caller disables FK enforcement BEFORE BEGIN, checks all FKs, then reenables after COMMIT.
// The replacement keeps the original table name/column order and every existing row intact.
function migrateAiSchema(PDO $db): void
{
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if($version===3){foreach(['ai_attempts','resident_preferences','ai_lease','ai_conversations'] as $table)$db->query("SELECT * FROM $table LIMIT 0");secret($db);return;}
    if($version!==2)throw new RuntimeException('AI migration requires an existing V2 database.');
    $before=worldDigest($db);secret($db);
    $db->exec("CREATE TABLE simulation_runs_v3 (
        id TEXT PRIMARY KEY, period TEXT NOT NULL UNIQUE CHECK(length(period)=10),
        status TEXT NOT NULL CHECK(status IN ('completed','failed')), provider TEXT NOT NULL CHECK(provider IN ('scripted','openai')),
        started_at TEXT NOT NULL, finished_at TEXT NOT NULL, error_code TEXT CHECK(error_code IS NULL OR error_code='simulation_failed'),
        interaction_count INTEGER NOT NULL DEFAULT 0 CHECK(interaction_count BETWEEN 0 AND 32),
        input_tokens INTEGER NOT NULL DEFAULT 0 CHECK(input_tokens BETWEEN 0 AND 1000000),
        output_tokens INTEGER NOT NULL DEFAULT 0 CHECK(output_tokens BETWEEN 0 AND 1000000),
        cost_microusd INTEGER NOT NULL DEFAULT 0 CHECK(cost_microusd BETWEEN 0 AND 1000000));
        INSERT INTO simulation_runs_v3 SELECT * FROM simulation_runs;
        DROP TABLE simulation_runs;
        ALTER TABLE simulation_runs_v3 RENAME TO simulation_runs;
        CREATE TABLE resident_preferences (
            resident_id TEXT PRIMARY KEY REFERENCES residents(id) ON DELETE RESTRICT,
            interest TEXT NOT NULL CHECK(interest IN ('gardening','stargazing','making_friends','quiet_walks')),
            voice TEXT NOT NULL CHECK(voice IN ('gentle','bright','thoughtful','inquisitive')));
        CREATE TABLE ai_attempts (
            id TEXT PRIMARY KEY, period TEXT NOT NULL UNIQUE, day TEXT NOT NULL, month TEXT NOT NULL,
            resident_a TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            resident_b TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            model TEXT NOT NULL, pricing_version TEXT NOT NULL,
            input_rate INTEGER NOT NULL CHECK(input_rate>0), output_rate INTEGER NOT NULL CHECK(output_rate>0),
            reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd BETWEEN 1 AND 1000000),
            estimated_microusd INTEGER CHECK(estimated_microusd>=0),
            input_tokens INTEGER CHECK(input_tokens>=0), output_tokens INTEGER CHECK(output_tokens>=0),
            request_slots INTEGER NOT NULL DEFAULT 2 CHECK(request_slots=2),
            responses_started INTEGER NOT NULL DEFAULT 0 CHECK(responses_started IN (0,1)),
            moderation_started INTEGER NOT NULL DEFAULT 0 CHECK(moderation_started IN (0,1)),
            state TEXT NOT NULL CHECK(state IN ('reserved','uncertain','settled','applied','rejected')),
            error_code TEXT CHECK(error_code IN ('transport_uncertain','usage_unknown','invalid_proposal','moderation_rejected','apply_rejected','lease_expired')),
            created_at TEXT NOT NULL, finished_at TEXT);
        CREATE INDEX ai_month ON ai_attempts(month,state);
        CREATE INDEX ai_day ON ai_attempts(day);
        CREATE TABLE ai_conversations (
            conversation_id TEXT PRIMARY KEY REFERENCES conversations(id) ON DELETE CASCADE,
            attempt_id TEXT NOT NULL REFERENCES ai_attempts(id) ON DELETE RESTRICT);
        CREATE TABLE ai_lease (singleton INTEGER PRIMARY KEY CHECK(singleton=1),
            owner TEXT REFERENCES ai_attempts(id) ON DELETE RESTRICT, expires_at INTEGER NOT NULL DEFAULT 0);
        INSERT INTO ai_lease(singleton) VALUES (1);
        PRAGMA user_version=3;");
    foreach($db->query('SELECT id,personality FROM residents')->fetchAll(PDO::FETCH_ASSOC) as $resident)ensurePreferences($db,$resident['id'],$resident['personality']);
    if(!hash_equals($before,worldDigest($db)))throw new RuntimeException('V2 preservation verification failed.');
}
