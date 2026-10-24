<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
use RuntimeException;
require_once __DIR__.'/ai_schema.php';

function activityPreservationDigest(PDO $db): string
{
    $hash=hash_init('sha256');hash_update($hash,worldDigest($db));
    foreach(['ai_attempts','ai_lease','ai_conversations','resident_preferences'] as $table){
        foreach($db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }
    return hash_final($hash);
}

// Caller owns BEGIN IMMEDIATE with foreign_keys disabled before BEGIN, then checks FKs.
function migrateActivitySchema(PDO $db): void
{
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if($version===4){$db->query('SELECT * FROM simulation_opportunities LIMIT 0');secret($db);return;}
    if($version!==3)throw new RuntimeException('Activity migration requires V3.');
    if($db->query('SELECT 1 FROM ai_lease WHERE owner IS NOT NULL AND expires_at>=CAST(strftime(\'%s\',\'now\') AS INTEGER)')->fetchColumn())throw new RuntimeException('Pause and drain active simulation first.');
    secret($db);$before=activityPreservationDigest($db);
    // Widen only the period key. Original dates, IDs, constraints and every value survive.
    $db->exec("CREATE TABLE simulation_runs_v4 (
        id TEXT PRIMARY KEY, period TEXT NOT NULL UNIQUE CHECK(length(period) BETWEEN 10 AND 64),
        status TEXT NOT NULL CHECK(status IN ('completed','failed')), provider TEXT NOT NULL CHECK(provider IN ('scripted','openai')),
        started_at TEXT NOT NULL, finished_at TEXT NOT NULL, error_code TEXT CHECK(error_code IS NULL OR error_code='simulation_failed'),
        interaction_count INTEGER NOT NULL DEFAULT 0 CHECK(interaction_count BETWEEN 0 AND 32),
        input_tokens INTEGER NOT NULL DEFAULT 0 CHECK(input_tokens BETWEEN 0 AND 1000000),
        output_tokens INTEGER NOT NULL DEFAULT 0 CHECK(output_tokens BETWEEN 0 AND 1000000),
        cost_microusd INTEGER NOT NULL DEFAULT 0 CHECK(cost_microusd BETWEEN 0 AND 1000000));
        INSERT INTO simulation_runs_v4 SELECT * FROM simulation_runs;
        DROP TABLE simulation_runs;
        ALTER TABLE simulation_runs_v4 RENAME TO simulation_runs;
        CREATE TABLE simulation_opportunities (
            id TEXT PRIMARY KEY, scheduled_at TEXT NOT NULL UNIQUE, day TEXT NOT NULL,
            resident_a TEXT REFERENCES residents(id) ON DELETE RESTRICT,
            resident_b TEXT REFERENCES residents(id) ON DELETE RESTRICT,
            run_id TEXT UNIQUE REFERENCES simulation_runs(id) ON DELETE RESTRICT,
            outcome TEXT NOT NULL CHECK(outcome IN ('pending','conversation','action','quiet','none','failed')),
            expires_at INTEGER NOT NULL, created_at TEXT NOT NULL, finished_at TEXT,
            CHECK(resident_b IS NULL OR (resident_a IS NOT NULL AND resident_a<>resident_b)));
        CREATE INDEX opportunity_day ON simulation_opportunities(day,outcome);
        CREATE INDEX opportunity_a ON simulation_opportunities(resident_a,created_at);
        CREATE INDEX opportunity_b ON simulation_opportunities(resident_b,created_at);
        CREATE TRIGGER conversation_preserve_update BEFORE UPDATE ON conversations BEGIN SELECT RAISE(ABORT,'Conversation history is immutable'); END;
        CREATE TRIGGER conversation_preserve_delete BEFORE DELETE ON conversations BEGIN SELECT RAISE(ABORT,'Conversation history is immutable'); END;
        CREATE TRIGGER message_preserve_update BEFORE UPDATE ON messages BEGIN SELECT RAISE(ABORT,'Dialogue history is immutable'); END;
        CREATE TRIGGER message_preserve_delete BEFORE DELETE ON messages BEGIN SELECT RAISE(ABORT,'Dialogue history is immutable'); END;
        PRAGMA user_version=4;");
    if(!hash_equals($before,activityPreservationDigest($db)))throw new RuntimeException('V3 preservation verification failed.');
}
