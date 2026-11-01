<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function editorialLedgerInstalled(PDO $db):bool
{
    return $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='gazette_editor_meta'")->fetchColumn()!==false;
}
/** Explicit owner migration only; caller holds BEGIN IMMEDIATE with drained writers. */
function migrateEditorialLedger(PDO $db):void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6)throw new \RuntimeException('V6 required.');
    $tables=['meta','permits','attempts','lease','requests','receipts','drafts','moderations','outcomes','reviews'];
    if(editorialLedgerInstalled($db)){
        if(!in_array((int)$db->query('SELECT revision FROM gazette_editor_meta WHERE singleton=1')->fetchColumn(),[1,2],true))throw new \RuntimeException('Unknown editorial revision.');
        foreach($tables as $table)$db->query('SELECT * FROM gazette_editor_'.$table.' LIMIT 0');return;
    }
    foreach($tables as $table){$q=$db->prepare('SELECT 1 FROM sqlite_master WHERE name=?');$q->execute(['gazette_editor_'.$table]);if($q->fetchColumn())throw new \RuntimeException('Partial editorial schema refused.');}
    assertIdentityIdle($db);
    $db->exec("CREATE TABLE gazette_editor_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO gazette_editor_meta VALUES(1,1);
        CREATE TABLE gazette_editor_permits(
            id TEXT PRIMARY KEY,edition TEXT NOT NULL,ordinal INTEGER NOT NULL CHECK(ordinal BETWEEN 2 AND 8),
            source_sha256 TEXT NOT NULL CHECK(length(source_sha256)=64),policy_sha256 TEXT NOT NULL CHECK(length(policy_sha256)=64),
            max_microusd INTEGER NOT NULL CHECK(max_microusd>=0),expires_at INTEGER NOT NULL,created_at INTEGER NOT NULL,
            UNIQUE(edition,ordinal));
        CREATE TABLE gazette_editor_attempts(
            id TEXT PRIMARY KEY,edition TEXT NOT NULL,ordinal INTEGER NOT NULL CHECK(ordinal BETWEEN 1 AND 8),idempotency_sha256 TEXT NOT NULL UNIQUE CHECK(length(idempotency_sha256)=64),
            source_sha256 TEXT NOT NULL CHECK(length(source_sha256)=64),snapshot_json TEXT NOT NULL CHECK(length(CAST(snapshot_json AS BLOB))<=262144),
            policy_sha256 TEXT NOT NULL CHECK(length(policy_sha256)=64),policy_json TEXT NOT NULL CHECK(length(CAST(policy_json AS BLOB))<=4096),
            day TEXT NOT NULL,month TEXT NOT NULL,reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd>=0),
            request_slots INTEGER NOT NULL CHECK(request_slots=2),owner TEXT NOT NULL,created_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,
            permit_id TEXT UNIQUE REFERENCES gazette_editor_permits(id) ON DELETE RESTRICT,
            CHECK(expires_at>created_at),CHECK((ordinal=1 AND permit_id IS NULL) OR (ordinal>1 AND permit_id IS NOT NULL)),
            UNIQUE(edition,ordinal));
        CREATE INDEX gazette_editor_budget ON gazette_editor_attempts(day,month);
        CREATE TABLE gazette_editor_lease(singleton INTEGER PRIMARY KEY CHECK(singleton=1),attempt_id TEXT REFERENCES gazette_editor_attempts(id),owner TEXT,expires_at INTEGER NOT NULL);
        INSERT INTO gazette_editor_lease VALUES(1,NULL,NULL,0);
        CREATE TABLE gazette_editor_requests(
            id TEXT PRIMARY KEY,attempt_id TEXT NOT NULL REFERENCES gazette_editor_attempts(id) ON DELETE RESTRICT,
            kind TEXT NOT NULL CHECK(kind IN ('generation','moderation')),created_at INTEGER NOT NULL,UNIQUE(attempt_id,kind));
        CREATE TABLE gazette_editor_receipts(
            request_id TEXT PRIMARY KEY REFERENCES gazette_editor_requests(id) ON DELETE RESTRICT,
            outcome TEXT NOT NULL CHECK(outcome IN ('completed','refused','uncertain')),request_id_remote TEXT,response_id_remote TEXT,
            input_tokens INTEGER CHECK(input_tokens>=0),output_tokens INTEGER CHECK(output_tokens>=0),
            estimated_microusd INTEGER CHECK(estimated_microusd>=0),created_at INTEGER NOT NULL);
        CREATE TABLE gazette_editor_drafts(
            attempt_id TEXT PRIMARY KEY REFERENCES gazette_editor_attempts(id) ON DELETE RESTRICT,
            sha256 TEXT NOT NULL CHECK(length(sha256)=64),body_json TEXT NOT NULL CHECK(length(CAST(body_json AS BLOB))<=65536),
            created_at INTEGER NOT NULL,UNIQUE(attempt_id,sha256));
        CREATE TABLE gazette_editor_moderations(
            attempt_id TEXT PRIMARY KEY,draft_sha256 TEXT NOT NULL,request_id TEXT NOT NULL UNIQUE REFERENCES gazette_editor_receipts(request_id),
            result TEXT NOT NULL CHECK(result IN ('passed','flagged')),created_at INTEGER NOT NULL,
            FOREIGN KEY(attempt_id,draft_sha256) REFERENCES gazette_editor_drafts(attempt_id,sha256));
        CREATE TABLE gazette_editor_outcomes(
            attempt_id TEXT PRIMARY KEY REFERENCES gazette_editor_attempts(id) ON DELETE RESTRICT,
            outcome TEXT NOT NULL CHECK(outcome IN ('finished','rejected','uncertain')),created_at INTEGER NOT NULL);
        CREATE TABLE gazette_editor_reviews(
            attempt_id TEXT PRIMARY KEY REFERENCES gazette_editor_moderations(attempt_id) ON DELETE RESTRICT,
            draft_sha256 TEXT NOT NULL,decision TEXT NOT NULL CHECK(decision IN ('approved','rejected')),
            authority TEXT NOT NULL CHECK(authority='local_owner'),created_at INTEGER NOT NULL,
            FOREIGN KEY(attempt_id,draft_sha256) REFERENCES gazette_editor_drafts(attempt_id,sha256));
        CREATE TRIGGER gazette_editor_draft_gate BEFORE INSERT ON gazette_editor_drafts WHEN NOT EXISTS(
            SELECT 1 FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id
            WHERE q.attempt_id=NEW.attempt_id AND q.kind='generation' AND r.outcome='completed')
            BEGIN SELECT RAISE(ABORT,'Completed generation required'); END;
        CREATE TRIGGER gazette_editor_moderation_gate BEFORE INSERT ON gazette_editor_moderations WHEN NOT EXISTS(
            SELECT 1 FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id
            WHERE q.id=NEW.request_id AND q.attempt_id=NEW.attempt_id AND q.kind='moderation' AND r.outcome='completed')
            BEGIN SELECT RAISE(ABORT,'Completed moderation required'); END;
        CREATE TRIGGER gazette_editor_review_gate BEFORE INSERT ON gazette_editor_reviews WHEN NOT EXISTS(
            SELECT 1 FROM gazette_editor_moderations m JOIN gazette_editor_outcomes o ON o.attempt_id=m.attempt_id
            WHERE m.attempt_id=NEW.attempt_id AND m.draft_sha256=NEW.draft_sha256 AND m.result='passed' AND o.outcome='finished')
            BEGIN SELECT RAISE(ABORT,'Finished moderated draft required'); END;");
    foreach(array_diff($tables,['lease']) as $table){$name='gazette_editor_'.$table;
        $db->exec("CREATE TRIGGER {$name}_no_update BEFORE UPDATE ON $name BEGIN SELECT RAISE(ABORT,'Editorial history is immutable'); END;
            CREATE TRIGGER {$name}_no_delete BEFORE DELETE ON $name BEGIN SELECT RAISE(ABORT,'Editorial history is immutable'); END;");}
}

/** Explicit drained-writer upgrade. Rebuild only the ten editorial tables,
 * preserving all original columns/rows; no shared village table or hold changes. */
function upgradeEditorialOwnerWorkflow(PDO $db):void
{
    migrateEditorialLedger($db);
    if((int)$db->query('SELECT revision FROM gazette_editor_meta')->fetchColumn()===2){$db->query('SELECT is_preview FROM gazette_editor_attempts LIMIT 0');$db->query('SELECT authorization_json FROM gazette_editor_jobs LIMIT 0');return;}
    if($db->query('SELECT attempt_id FROM gazette_editor_lease')->fetchColumn()!==null)throw new \RuntimeException('Drain editorial work first.');
    $tables=['meta','permits','attempts','lease','requests','receipts','drafts','moderations','outcomes','reviews'];
    if($db->query("SELECT 1 FROM sqlite_master WHERE tbl_name NOT LIKE 'gazette_editor_%' AND sql LIKE '%gazette_editor_%' LIMIT 1")->fetchColumn())throw new \RuntimeException('Review external editorial schema dependencies first.');
    $schema=[];$columns=[];
    foreach($tables as $suffix){$name='gazette_editor_'.$suffix;
        $q=$db->prepare("SELECT sql FROM sqlite_master WHERE name=? AND type='table'");$q->execute([$name]);$schema[$name]=$q->fetchColumn();$q->closeCursor();
        $columns[$name]=array_column($db->query('PRAGMA table_info('.$name.')')->fetchAll(),'name');
    }
    $objects=$db->query("SELECT type,name,sql FROM sqlite_master WHERE tbl_name LIKE 'gazette_editor_%' AND type IN ('trigger','index') AND sql IS NOT NULL")->fetchAll();
    foreach($objects as $o)$db->exec('DROP '.strtoupper($o['type']).' "'.$o['name'].'"');
    foreach($tables as $suffix)$db->exec('ALTER TABLE gazette_editor_'.$suffix.' RENAME TO gazette_upgrade_'.$suffix);
    foreach($schema as $name=>$sql){
        $sql=str_replace(['ordinal BETWEEN 1 AND 8','ordinal BETWEEN 2 AND 8','revision=1'],['ordinal>=1','ordinal>=2','revision=2'],$sql);
        if($name==='gazette_editor_attempts')$sql=str_replace('CHECK(expires_at>created_at)','is_preview INTEGER NOT NULL DEFAULT 0 CHECK(is_preview IN (0,1)), CHECK(expires_at>created_at)',$sql);
        $db->exec($sql);
        $names=implode(',',array_map(static fn($c)=>'"'.str_replace('"','""',$c).'"',$columns[$name]));
        $select=$name==='gazette_editor_meta'?'singleton,2':$names;
        $db->exec('INSERT INTO '.$name.'('.$names.') SELECT '.$select.' FROM '.str_replace('gazette_editor_','gazette_upgrade_',$name).' ORDER BY rowid');
    }
    foreach(array_reverse($tables) as $suffix)$db->exec('DROP TABLE gazette_upgrade_'.$suffix);
    foreach($objects as $o)$db->exec($o['sql']);
    $db->exec("CREATE TABLE gazette_editor_jobs(id TEXT PRIMARY KEY CHECK(length(id)=32),attempt_id TEXT NOT NULL UNIQUE REFERENCES gazette_editor_attempts(id),approved_at INTEGER NOT NULL,authorization_json TEXT NOT NULL CHECK(length(authorization_json)<=1024));
        CREATE TRIGGER gazette_editor_jobs_no_update BEFORE UPDATE ON gazette_editor_jobs BEGIN SELECT RAISE(ABORT,'Owner history immutable'); END;
        CREATE TRIGGER gazette_editor_jobs_no_delete BEFORE DELETE ON gazette_editor_jobs BEGIN SELECT RAISE(ABORT,'Owner history immutable'); END;");
    if($db->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Editorial upgrade foreign keys failed.');
}
