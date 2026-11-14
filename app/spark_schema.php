<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/conversation_messages.php';

function sparkLedgerInstalled(PDO $db):bool
{
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='spark_memory_meta'")->fetchColumn())return false;
    if((int)$db->query('SELECT revision FROM spark_memory_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported Spark memory capability.');
    $db->query('SELECT * FROM spark_encounters LIMIT 0');$db->query('SELECT * FROM spark_calls LIMIT 0');return true;
}
function migrateSparkLedger(PDO $db):void
{
    if(!directorEnabled($db)||!multiMessageInstalled($db))throw new \RuntimeException('V6 multi-message database required.');
    assertIdentityIdle($db);if(sparkLedgerInstalled($db))return;
    $db->exec("CREATE TABLE spark_memory_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO spark_memory_meta VALUES(1,1);
        CREATE TABLE spark_encounters(
        attempt_id TEXT PRIMARY KEY REFERENCES director_attempts(id) ON DELETE RESTRICT,
        generation_limit INTEGER NOT NULL CHECK(generation_limit BETWEEN 2 AND 576),
        reserved_extra_slots INTEGER NOT NULL CHECK(reserved_extra_slots=generation_limit-1),
        state TEXT NOT NULL CHECK(state IN ('reserved','completed','rejected','uncertain')),
        charged_adjustment INTEGER CHECK(charged_adjustment BETWEEN -2 AND reserved_extra_slots),
        created_at TEXT NOT NULL,finished_at TEXT,
        CHECK((state IN ('reserved','uncertain') AND charged_adjustment IS NULL) OR (state IN ('completed','rejected') AND charged_adjustment IS NOT NULL)));
        CREATE TABLE spark_calls(
        attempt_id TEXT NOT NULL REFERENCES spark_encounters(attempt_id) ON DELETE RESTRICT,
        sequence INTEGER NOT NULL CHECK(sequence BETWEEN 0 AND 576),resident_id TEXT REFERENCES residents(id) ON DELETE RESTRICT,
        kind TEXT NOT NULL CHECK(kind IN ('reply','moderation')),state TEXT NOT NULL CHECK(state IN ('dispatched','completed')),
        input_tokens INTEGER,output_tokens INTEGER,cost_microusd INTEGER,response_id TEXT CHECK(response_id IS NULL OR length(response_id) BETWEEN 6 AND 128),
        created_at TEXT NOT NULL,finished_at TEXT,PRIMARY KEY(attempt_id,sequence),
        CHECK((kind='reply' AND resident_id IS NOT NULL) OR (kind='moderation' AND resident_id IS NULL)),
        CHECK((state='dispatched' AND input_tokens IS NULL AND output_tokens IS NULL AND cost_microusd IS NULL AND finished_at IS NULL) OR
        (state='completed' AND input_tokens IS NOT NULL AND output_tokens IS NOT NULL AND cost_microusd IS NOT NULL AND input_tokens>=0 AND output_tokens>=0 AND cost_microusd>=0 AND finished_at IS NOT NULL)));
        CREATE TRIGGER spark_reservation_binding BEFORE INSERT ON spark_encounters WHEN NOT EXISTS(SELECT 1 FROM director_attempts d WHERE d.id=NEW.attempt_id AND d.runtime='responses' AND d.request_slots=2 AND d.state='reserved')
        BEGIN SELECT RAISE(ABORT,'Spark reservation binding invalid'); END;
        CREATE TRIGGER spark_call_contiguous BEFORE INSERT ON spark_calls WHEN NEW.sequence<>(SELECT COUNT(*) FROM spark_calls WHERE attempt_id=NEW.attempt_id) OR
        NOT EXISTS(SELECT 1 FROM spark_encounters s JOIN director_attempts d ON d.id=s.attempt_id JOIN ai_attempts a ON a.id=s.attempt_id
          WHERE s.attempt_id=NEW.attempt_id AND s.state='reserved' AND d.runtime='responses' AND a.state IN ('reserved','settled') AND d.state='reserved') OR
        (NEW.kind='reply' AND (SELECT COUNT(*) FROM spark_calls WHERE attempt_id=NEW.attempt_id AND kind='reply')>=(SELECT generation_limit FROM spark_encounters WHERE attempt_id=NEW.attempt_id)) OR
        (NEW.kind='reply' AND NOT EXISTS(SELECT 1 FROM ai_attempts a WHERE a.id=NEW.attempt_id AND NEW.resident_id IN (a.resident_a,a.resident_b))) OR
        EXISTS(SELECT 1 FROM spark_calls WHERE attempt_id=NEW.attempt_id AND (state='dispatched' OR kind='moderation'))
        BEGIN SELECT RAISE(ABORT,'Spark dispatch refused'); END;
        CREATE TRIGGER spark_call_completion BEFORE UPDATE ON spark_calls WHEN OLD.state<>'dispatched' OR NEW.state<>'completed' OR
        NEW.attempt_id<>OLD.attempt_id OR NEW.sequence<>OLD.sequence OR NEW.kind<>OLD.kind OR NEW.resident_id IS NOT OLD.resident_id OR NEW.created_at<>OLD.created_at OR
        (NEW.kind='reply' AND (NEW.input_tokens<=0 OR NEW.output_tokens<=0)) OR (NEW.kind='moderation' AND (NEW.input_tokens<>0 OR NEW.output_tokens<>0 OR NEW.cost_microusd<>0))
        BEGIN SELECT RAISE(ABORT,'Spark call history is immutable'); END;
        CREATE TRIGGER spark_encounter_completion BEFORE UPDATE ON spark_encounters WHEN OLD.state<>'reserved' OR NEW.state='reserved' OR
        NEW.attempt_id<>OLD.attempt_id OR NEW.generation_limit<>OLD.generation_limit OR NEW.reserved_extra_slots<>OLD.reserved_extra_slots OR NEW.created_at<>OLD.created_at OR NEW.finished_at IS NULL OR
        (NEW.state IN ('completed','rejected') AND (NEW.charged_adjustment<>(SELECT COUNT(*)-2 FROM spark_calls WHERE attempt_id=NEW.attempt_id) OR EXISTS(SELECT 1 FROM spark_calls WHERE attempt_id=NEW.attempt_id AND state='dispatched'))) OR
        (NEW.state='completed' AND NOT EXISTS(SELECT 1 FROM spark_calls WHERE attempt_id=NEW.attempt_id AND kind='moderation' AND state='completed'))
        BEGIN SELECT RAISE(ABORT,'Spark accounting is immutable'); END;");
    foreach(['spark_memory_meta','spark_encounters','spark_calls'] as $table)$db->exec("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'Spark accounting is immutable'); END;");
    $db->exec("CREATE TRIGGER spark_memory_meta_no_update BEFORE UPDATE ON spark_memory_meta BEGIN SELECT RAISE(ABORT,'Spark capability is immutable'); END;");
}
