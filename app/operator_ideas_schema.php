<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/operator_ideas.php';

/** Explicit caller-owned transaction. This add-on never changes user_version. */
function migrateOperatorIdeas(PDO $db):void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6)throw new \RuntimeException('SQLite V6 required.');
    if(operatorIdeasInstalled($db)){foreach(['operator_ideas','operator_idea_exposures'] as $table)$db->query('SELECT * FROM '.$table.' LIMIT 0');return;}
    foreach(['operator_ideas_meta','operator_ideas','operator_idea_exposures'] as $table){$q=$db->prepare('SELECT 1 FROM sqlite_master WHERE name=?');$q->execute([$table]);if($q->fetchColumn())throw new \RuntimeException('Unrecognized partial operator schema.');}
    assertIdentityIdle($db);
    $db->exec("CREATE TABLE operator_ideas_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO operator_ideas_meta VALUES(1,1);
        CREATE TABLE operator_ideas(id TEXT PRIMARY KEY CHECK(length(id)=32),resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 1000),author TEXT NOT NULL CHECK(author='site_operator'),created_at TEXT NOT NULL,
        request_id TEXT NOT NULL UNIQUE CHECK(length(request_id)=32),request_hash TEXT NOT NULL CHECK(length(request_hash)=64));
        CREATE INDEX operator_ideas_resident ON operator_ideas(resident_id,created_at,id);
        CREATE TABLE operator_idea_exposures(idea_id TEXT NOT NULL REFERENCES operator_ideas(id) ON DELETE RESTRICT,
        conversation_id TEXT NOT NULL REFERENCES conversations(id) ON DELETE RESTRICT,created_at TEXT NOT NULL,PRIMARY KEY(idea_id,conversation_id));");
    foreach(['operator_ideas','operator_idea_exposures'] as $table)$db->exec("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT,'Operator history is immutable'); END;
        CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT,'Operator history is immutable'); END;");
}
