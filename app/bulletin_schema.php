<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/bulletin.php';
function migrateBulletin(PDO $db):void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6)throw new \RuntimeException('V6 required.');
    if(bulletinInstalled($db)){foreach(['bulletin_notices','bulletin_removals'] as $t)$db->query('SELECT * FROM '.$t.' LIMIT 0');return;}
    foreach(['bulletin_meta','bulletin_notices','bulletin_removals'] as $t){$q=$db->prepare('SELECT 1 FROM sqlite_master WHERE name=?');$q->execute([$t]);if($q->fetchColumn())throw new \RuntimeException('Partial bulletin schema refused.');}
    assertIdentityIdle($db);
    $db->exec("CREATE TABLE bulletin_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));INSERT INTO bulletin_meta VALUES(1,1);
        CREATE TABLE bulletin_notices(id TEXT PRIMARY KEY CHECK(length(id)=32),resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
        run_id TEXT NOT NULL UNIQUE REFERENCES director_runs(run_id) ON DELETE RESTRICT,kind TEXT NOT NULL CHECK(kind IN ('invitation','offer','question','availability','plan','whimsy')),
        body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 1000),text_sha256 TEXT NOT NULL CHECK(length(text_sha256)=64),created_at TEXT NOT NULL,expires_at TEXT,
        capability TEXT NOT NULL CHECK(capability='sanctuary.director.bulletin.v1'));
        CREATE INDEX bulletin_recent ON bulletin_notices(created_at DESC,id DESC);CREATE INDEX bulletin_author ON bulletin_notices(resident_id,created_at);
        CREATE TABLE bulletin_removals(notice_id TEXT PRIMARY KEY REFERENCES bulletin_notices(id) ON DELETE RESTRICT,created_at TEXT NOT NULL,reason TEXT NOT NULL CHECK(reason='operator_removed'));");
    foreach(['bulletin_notices','bulletin_removals'] as $t)$db->exec("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON $t BEGIN SELECT RAISE(ABORT,'Bulletin history is immutable'); END;CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON $t BEGIN SELECT RAISE(ABORT,'Bulletin history is immutable'); END;");
}
