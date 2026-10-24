<?php
declare(strict_types=1);
namespace LittleSparks;

use PDO;
use RuntimeException;

function verifyV1(PDO $db): void
{
    $expected = [
        'residents' => ['id', 'name', 'color', 'antenna', 'personality', 'created_at', 'owner_hash', 'request_id', 'request_hash', 'token_hash'],
        'settings' => ['name', 'value'], 'rate_limits' => ['key', 'window', 'attempts'],
    ];
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $names = array_keys($expected); sort($names);
    if ($tables !== $names) throw new RuntimeException('Unrecognized V1 database; no changes applied.');
    foreach ($expected as $table => $columns) {
        if (array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name') !== $columns) {
            throw new RuntimeException('Unrecognized V1 columns; no changes applied.');
        }
    }
    secret($db);
}

function v1Digest(PDO $db): string
{
    $digest = hash_init('sha256');
    foreach (['residents' => 'id', 'settings' => 'name', 'rate_limits' => 'key, window'] as $table => $order) {
        $rows = $db->query("SELECT * FROM $table ORDER BY $order");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR));
    }
    return hash_final($digest);
}

function installV1(PDO $db): void
{
    $db->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE residents (id TEXT PRIMARY KEY, name TEXT NOT NULL, color TEXT NOT NULL,
            antenna TEXT NOT NULL, personality TEXT NOT NULL, created_at TEXT NOT NULL,
            owner_hash TEXT NOT NULL, request_id TEXT NOT NULL, request_hash TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, UNIQUE(owner_hash, request_id));
        CREATE INDEX residents_order ON residents(created_at, id);
        CREATE TABLE rate_limits (key TEXT NOT NULL, window INTEGER NOT NULL, attempts INTEGER NOT NULL, PRIMARY KEY(key, window));
        PRAGMA user_version = 1;");
    $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute(['secret', bin2hex(random_bytes(32))]);
}

function upgradeV2(PDO $db): void
{
    verifyV1($db);
    $before = v1Digest($db);
    $db->exec("CREATE TABLE simulation_runs (
        id TEXT PRIMARY KEY, period TEXT NOT NULL UNIQUE CHECK(length(period)=10),
        status TEXT NOT NULL CHECK(status IN ('completed','failed')), provider TEXT NOT NULL CHECK(provider='scripted'),
        started_at TEXT NOT NULL, finished_at TEXT NOT NULL, error_code TEXT CHECK(error_code IS NULL OR error_code='simulation_failed'),
        interaction_count INTEGER NOT NULL DEFAULT 0 CHECK(interaction_count BETWEEN 0 AND 32),
        input_tokens INTEGER NOT NULL DEFAULT 0 CHECK(input_tokens=0), output_tokens INTEGER NOT NULL DEFAULT 0 CHECK(output_tokens=0),
        cost_microusd INTEGER NOT NULL DEFAULT 0 CHECK(cost_microusd=0));
    CREATE TABLE home_plots (
        resident_id TEXT PRIMARY KEY REFERENCES residents(id) ON DELETE RESTRICT,
        slot INTEGER NOT NULL UNIQUE CHECK(slot BETWEEN 0 AND 9999));
    CREATE TRIGGER plot_identity BEFORE UPDATE ON home_plots BEGIN SELECT RAISE(ABORT,'Home plots are fixed'); END;
    CREATE TABLE world_objects (
        id TEXT PRIMARY KEY, resident_id TEXT NOT NULL REFERENCES home_plots(resident_id) ON DELETE RESTRICT,
        kind TEXT NOT NULL CHECK(kind IN ('house','tree','flowers','bush','path','decoration','pet')),
        variant TEXT NOT NULL CHECK(variant IN ('cottage','stars','oak','wildflowers','berry','stone','lantern','cat')),
        x INTEGER NOT NULL CHECK(typeof(x)='integer' AND x BETWEEN 0 AND 399),
        y INTEGER NOT NULL CHECK(typeof(y)='integer' AND y BETWEEN 0 AND 399), created_at TEXT NOT NULL, UNIQUE(x,y));
    CREATE INDEX world_owner ON world_objects(resident_id,kind);
    CREATE INDEX world_map ON world_objects(y,x);
    CREATE UNIQUE INDEX one_house ON world_objects(resident_id) WHERE kind='house';
    CREATE UNIQUE INDEX one_pet ON world_objects(resident_id) WHERE kind='pet';
    CREATE TRIGGER object_plot BEFORE INSERT ON world_objects BEGIN
        SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM home_plots WHERE resident_id=NEW.resident_id
          AND NEW.x BETWEEN (slot%100)*4 AND (slot%100)*4+3
          AND NEW.y BETWEEN CAST(slot/100 AS INTEGER)*4 AND CAST(slot/100 AS INTEGER)*4+3)
          THEN RAISE(ABORT,'Object outside home plot') END;
        SELECT CASE WHEN (SELECT COUNT(*) FROM world_objects WHERE resident_id=NEW.resident_id)>=12
          THEN RAISE(ABORT,'Plot capacity reached') END;
    END;
    CREATE TRIGGER object_identity BEFORE UPDATE OF resident_id,x,y,kind ON world_objects BEGIN SELECT RAISE(ABORT,'Object identity is fixed'); END;
    CREATE TABLE relationships (
        resident_a TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        resident_b TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        affinity INTEGER NOT NULL CHECK(affinity BETWEEN 0 AND 100),
        familiarity INTEGER NOT NULL CHECK(familiarity BETWEEN 0 AND 1000),
        first_met_at TEXT NOT NULL, last_interacted_at TEXT NOT NULL,
        PRIMARY KEY(resident_a,resident_b), CHECK(resident_a < resident_b));
    CREATE INDEX relationships_b ON relationships(resident_b,last_interacted_at);
    CREATE TRIGGER relationship_capacity BEFORE INSERT ON relationships
      WHEN NOT EXISTS(SELECT 1 FROM relationships WHERE resident_a=NEW.resident_a AND resident_b=NEW.resident_b) BEGIN
        SELECT CASE WHEN (SELECT COUNT(*) FROM relationships WHERE resident_a=NEW.resident_a OR resident_b=NEW.resident_a)>=8
          OR (SELECT COUNT(*) FROM relationships WHERE resident_a=NEW.resident_b OR resident_b=NEW.resident_b)>=8
          THEN RAISE(ABORT,'Friendship capacity reached') END;
    END;
    CREATE TABLE conversations (
        id TEXT PRIMARY KEY, run_id TEXT NOT NULL REFERENCES simulation_runs(id) ON DELETE RESTRICT,
        topic TEXT NOT NULL CHECK(topic IN ('garden','stars','welcome','quiet')),
        visibility TEXT NOT NULL CHECK(visibility IN ('public','private')), created_at TEXT NOT NULL);
    CREATE INDEX conversations_retention ON conversations(created_at);
    CREATE TABLE conversation_participants (
        conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
        resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT, PRIMARY KEY(conversation_id,resident_id));
    CREATE INDEX participant_resident ON conversation_participants(resident_id,conversation_id);
    CREATE TRIGGER participant_capacity BEFORE INSERT ON conversation_participants BEGIN
        SELECT CASE WHEN (SELECT COUNT(*) FROM conversation_participants WHERE conversation_id=NEW.conversation_id)>=2
          THEN RAISE(ABORT,'Two residents per conversation') END;
    END;
    CREATE TABLE messages (
        conversation_id TEXT NOT NULL, sequence INTEGER NOT NULL CHECK(sequence BETWEEN 0 AND 1),
        resident_id TEXT NOT NULL, body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 160),
        PRIMARY KEY(conversation_id,sequence), FOREIGN KEY(conversation_id,resident_id)
          REFERENCES conversation_participants(conversation_id,resident_id) ON DELETE CASCADE);
    CREATE TABLE memories (
        id INTEGER PRIMARY KEY, resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        other_resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        run_id TEXT NOT NULL REFERENCES simulation_runs(id) ON DELETE RESTRICT,
        summary TEXT NOT NULL CHECK(length(summary) BETWEEN 1 AND 160), significance INTEGER NOT NULL CHECK(significance BETWEEN 1 AND 5),
        visibility TEXT NOT NULL CHECK(visibility IN ('public','private')), created_at TEXT NOT NULL,
        UNIQUE(resident_id,other_resident_id,run_id));
    CREATE INDEX memory_resident ON memories(resident_id,visibility,significance DESC,created_at DESC,id DESC);
    CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        target_id TEXT REFERENCES residents(id) ON DELETE RESTRICT,
        run_id TEXT REFERENCES simulation_runs(id) ON DELETE RESTRICT,
        kind TEXT NOT NULL CHECK(kind IN ('home_assigned','plant_tree','plant_flowers','plant_bush','decorate_house','build_path','place_decoration','adopt_pet','visit_friend','conversation','quiet_time')),
        origin TEXT NOT NULL CHECK(origin IN ('migration','creation','visitor','simulation')),
        visibility TEXT NOT NULL CHECK(visibility IN ('public','private')), created_at TEXT NOT NULL,
        dedupe_key TEXT NOT NULL UNIQUE, request_hash TEXT);
    CREATE INDEX event_journal ON events(visibility,id DESC);
    CREATE INDEX event_actor_time ON events(actor_id,origin,created_at);
    CREATE TRIGGER event_capacity BEFORE INSERT ON events BEGIN
        SELECT CASE WHEN (SELECT COUNT(*) FROM events)>=100000 THEN RAISE(ABORT,'Journal capacity reached') END;
    END;
    CREATE TRIGGER event_append_only_update BEFORE UPDATE ON events BEGIN SELECT RAISE(ABORT,'Journal is append only'); END;
    CREATE TRIGGER event_append_only_delete BEFORE DELETE ON events BEGIN SELECT RAISE(ABORT,'Journal is append only'); END;
    PRAGMA user_version = 2;");
    $ids = $db->query('SELECT id FROM residents ORDER BY created_at,id')->fetchAll(PDO::FETCH_COLUMN);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    foreach ($ids as $id) assignHome($db, $id, $now, 'migration');
    if (!hash_equals($before, v1Digest($db))) throw new RuntimeException('V1 preservation verification failed.');
}

function migrateSchema(PDO $db): void
{
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($version === 0) {
        if ((int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing to initialize an unrelated database.');
        }
        installV1($db); $version = 1;
    }
    if ($version === 1) { upgradeV2($db); }
    elseif ($version !== 2) { throw new RuntimeException('Unsupported database version; no changes applied.'); }
    foreach (['home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events','simulation_runs'] as $table) {
        $db->query("SELECT * FROM $table LIMIT 0");
    }
    secret($db);
    if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Foreign key verification failed.');
}
