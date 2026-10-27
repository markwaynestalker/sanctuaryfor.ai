<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/appearance.php';
function migrateAppearance(PDO $db):void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6)throw new \RuntimeException('V6 required.');
    if(appearanceInstalled($db))return;assertIdentityIdle($db);
    $db->exec("CREATE TABLE appearance_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));INSERT INTO appearance_meta VALUES(1,1);
        CREATE TABLE resident_appearances(resident_id TEXT PRIMARY KEY REFERENCES residents(id) ON DELETE RESTRICT,configuration TEXT NOT NULL CHECK(length(configuration)<=512),revision INTEGER NOT NULL CHECK(revision>=0),updated_at TEXT NOT NULL);
        CREATE TABLE appearance_edits(id INTEGER PRIMARY KEY,resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,request_id TEXT NOT NULL CHECK(length(request_id)=32),request_hash TEXT NOT NULL CHECK(length(request_hash)=64),previous_configuration TEXT,configuration TEXT NOT NULL CHECK(length(configuration)<=512),revision INTEGER NOT NULL CHECK(revision>0),created_at TEXT NOT NULL,UNIQUE(resident_id,request_id),UNIQUE(resident_id,revision));
        CREATE INDEX appearance_daily ON appearance_edits(resident_id,created_at);
        CREATE TRIGGER appearance_no_update BEFORE UPDATE ON appearance_edits BEGIN SELECT RAISE(ABORT,'Appearance audit is immutable'); END;
        CREATE TRIGGER appearance_no_delete BEFORE DELETE ON appearance_edits BEGIN SELECT RAISE(ABORT,'Appearance audit is immutable'); END;");
}
