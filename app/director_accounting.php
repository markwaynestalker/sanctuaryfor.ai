<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function directorAccountingTables():array{return ['director_http_calls','director_usage_observations','director_billing_reviews','director_accounting_meta'];}
function upgradeDirectorAccounting(PDO $db):void
{
    $exists=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='director_accounting_meta'")->fetchColumn();
    $version=$exists?$db->query('SELECT version FROM director_accounting_meta WHERE singleton=1')->fetchColumn():false;
    if($version!==false){if((int)$version!==1)throw new \RuntimeException('Unsupported Director accounting revision.');foreach(directorAccountingTables() as $t)$db->query("SELECT * FROM $t LIMIT 0");upgradeDirectorStagingSchema($db);return;}
    foreach(directorAccountingTables() as $t){$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE name=?");$q->execute([$t]);if($q->fetchColumn())throw new \RuntimeException('Unrecognized partial accounting schema.');}
    $db->exec("CREATE TABLE director_http_calls (
        attempt_id TEXT NOT NULL REFERENCES director_attempts(id) ON DELETE RESTRICT,
        sequence INTEGER NOT NULL CHECK(sequence BETWEEN 1 AND 8),kind TEXT NOT NULL,
        method TEXT NOT NULL CHECK(method IN ('GET','POST')),path_sha256 TEXT NOT NULL,
        outcome TEXT NOT NULL CHECK(outcome IN ('started','succeeded','uncertain')),
        http_status INTEGER,request_id TEXT,started_at TEXT NOT NULL,finished_at TEXT,
        PRIMARY KEY(attempt_id,sequence),UNIQUE(attempt_id,kind));
        CREATE TABLE director_usage_observations (
        id INTEGER PRIMARY KEY,attempt_id TEXT NOT NULL REFERENCES director_attempts(id) ON DELETE RESTRICT,
        turn_id TEXT NOT NULL,input_tokens INTEGER,output_tokens INTEGER,cached_tokens INTEGER,reasoning_tokens INTEGER,
        estimated_microusd INTEGER,status TEXT NOT NULL CHECK(status IN ('observed','unknown')),observed_at TEXT NOT NULL);
        CREATE INDEX director_observed_attempt ON director_usage_observations(attempt_id,id);
        CREATE TABLE director_billing_reviews (
        review_id TEXT PRIMARY KEY,attempt_id TEXT NOT NULL REFERENCES director_attempts(id) ON DELETE RESTRICT,
        evidence_sha256 TEXT NOT NULL,amount_microusd INTEGER NOT NULL CHECK(amount_microusd BETWEEN 0 AND 100000000),
        commitment_microusd INTEGER NOT NULL CHECK(commitment_microusd>=0),
        outcome TEXT NOT NULL CHECK(outcome IN ('reviewed','overrun')),reviewed_at TEXT NOT NULL);
        CREATE INDEX director_review_attempt ON director_billing_reviews(attempt_id,reviewed_at);
        CREATE TRIGGER director_http_no_delete BEFORE DELETE ON director_http_calls BEGIN SELECT RAISE(ABORT,'HTTP audit is immutable'); END;
        CREATE TRIGGER director_http_final BEFORE UPDATE ON director_http_calls WHEN OLD.outcome<>'started' BEGIN SELECT RAISE(ABORT,'HTTP audit is final'); END;");
    foreach(['director_usage_observations','director_billing_reviews'] as $t)$db->exec("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON $t BEGIN SELECT RAISE(ABORT,'Accounting evidence is immutable'); END; CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON $t BEGIN SELECT RAISE(ABORT,'Accounting evidence is immutable'); END;");
    $db->exec('CREATE TABLE director_accounting_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),version INTEGER NOT NULL CHECK(version=1)); INSERT INTO director_accounting_meta VALUES(1,1)');
    upgradeDirectorStagingSchema($db);
}
function upgradeDirectorStagingSchema(PDO $db):void
{
    $exists=$db->query("SELECT 1 FROM sqlite_master WHERE name='director_staging_meta'")->fetchColumn();
    if($exists){if($db->query('SELECT revision FROM director_staging_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported staging revision.');foreach(['director_staging_permits','director_managed_outcomes'] as $t)$db->query("SELECT * FROM $t LIMIT 0");return;}
    foreach(['director_staging_permits','director_managed_outcomes'] as $table){$q=$db->prepare('SELECT 1 FROM sqlite_master WHERE name=?');$q->execute([$table]);if($q->fetchColumn())throw new \RuntimeException('Incomplete staging schema.');}
    $db->exec("CREATE TABLE director_staging_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));INSERT INTO director_staging_meta VALUES(1,1);
        CREATE TABLE director_staging_permits(singleton INTEGER PRIMARY KEY CHECK(singleton=1),permit_id TEXT NOT NULL UNIQUE,permit_sha256 TEXT NOT NULL,
            manifest_sha256 TEXT NOT NULL,residents_sha256 TEXT NOT NULL,approval_sha256 TEXT NOT NULL,expires_at INTEGER NOT NULL,
            allocation_microusd INTEGER NOT NULL CHECK(allocation_microusd=100000),http_slots INTEGER NOT NULL CHECK(http_slots=8),consumed_at TEXT NOT NULL);
        CREATE TABLE director_managed_outcomes(id INTEGER PRIMARY KEY,attempt_id TEXT NOT NULL REFERENCES director_attempts(id) ON DELETE RESTRICT,
            session_id TEXT,turn_id TEXT,status TEXT NOT NULL CHECK(status IN ('completed','failed','cancelled','unresolved','cancel_requested','cancel_uncertain','session_lost')),created_at TEXT NOT NULL);");
    foreach(['director_staging_permits','director_managed_outcomes'] as $t)$db->exec("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON $t BEGIN SELECT RAISE(ABORT,'Staging evidence is immutable'); END; CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON $t BEGIN SELECT RAISE(ABORT,'Staging evidence is immutable'); END;");
}
function recordManagedOutcome(PDO $db,array $attempt,?string $session,?string $turn,string $status):void
{
    $db->prepare('INSERT INTO director_managed_outcomes(attempt_id,session_id,turn_id,status,created_at) VALUES (?,?,?,?,?)')->execute([$attempt['id'],$session,$turn,$status,gmdate('Y-m-d\TH:i:s\Z')]);
}
function managedRoute(string $method,string $path):string
{
    $id='[a-zA-Z0-9_-]{1,128}';
    if($method==='POST'&&$path==='agents/sessions')return 'create';
    if($method==='POST'&&$path==='moderations')return 'moderation';
    if($method==='GET'&&preg_match('~\Aagents/sessions/'.$id.'\z~',$path))return 'inspect_session';
    if($method==='POST'&&preg_match('~\Aagents/sessions/'.$id.'/events\z~',$path))return 'submit';
    if($method==='GET'&&preg_match('~\Aagents/sessions/'.$id.'/turns\?limit=1&order=desc\z~',$path))return 'turns';
    if($method==='GET'&&preg_match('~\Aagents/sessions/'.$id.'/turns/'.$id.'/items\?limit=20&order=asc\z~',$path))return 'items';
    throw new AiGuard('configuration_invalid');
}
function startManagedHttp(PDO $db,array $attempt,string $method,string $path,?string $auditKind=null):int
{
    $kind=managedRoute($method,$path);
    if($auditKind!==null){if(!(($kind==='turns'&&preg_match('/\A(?:turns_[1-3]|final_turn)\z/',$auditKind))||($kind==='submit'&&$auditKind==='cancel')))throw new AiGuard('configuration_invalid');$kind=$auditKind;}
    $db->exec('BEGIN IMMEDIATE');
    try{
        assertSimulationLease($db,$attempt['id']);if(gmdate('Y-m-d')!==$attempt['day']||time()>=$attempt['expires'])throw new AiGuard('day_boundary');
        if(in_array($kind,['create','submit'],true)&&villageSleepingAt(time()))throw new AiGuard('night_rest');
        $q=$db->prepare("SELECT request_slots,started_requests,state FROM director_attempts WHERE id=? AND runtime='agents'");$q->execute([$attempt['id']]);$row=$q->fetch();
        if(!$row||$row['state']!=='reserved'||(int)$row['started_requests']>=(int)$row['request_slots'])throw new AiGuard('encounter_request_cap');
        $q=$db->prepare('SELECT 1 FROM director_http_calls WHERE attempt_id=? AND kind=?');$q->execute([$attempt['id'],$kind]);if($q->fetchColumn())throw new AiGuard('request_already_started');
        $sequence=(int)$row['started_requests']+1;
        $db->prepare("INSERT INTO director_http_calls(attempt_id,sequence,kind,method,path_sha256,outcome,started_at) VALUES (?,?,?,?,?,'started',?)")
            ->execute([$attempt['id'],$sequence,$kind,$method,hash('sha256',$path),gmdate('Y-m-d\TH:i:s\Z')]);
        $db->prepare('UPDATE director_attempts SET started_requests=? WHERE id=?')->execute([$sequence,$attempt['id']]);
        $db->exec('COMMIT');return $sequence;
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function finishManagedHttp(PDO $db,array $attempt,int $sequence,?array $metadata):void
{
    $status=$metadata['status']??null;$request=$metadata['request_id']??null;
    if($status!==null&&(!is_int($status)||$status<100||$status>599))throw new AiGuard('transport_uncertain');
    if($request!==null&&(!is_string($request)||!preg_match('/\Areq_[a-zA-Z0-9_-]{1,120}\z/',$request)||str_contains($request,'sk-')))$request=null;
    if($status===400&&isset($metadata['validation'])){
        // Re-sanitize even injected transports; private evidence never clears a hold.
        $safe=managedValidationError(400,json_encode(['error'=>$metadata['validation']],JSON_THROW_ON_ERROR));
        if($safe!==null)$db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['director_validation_error:'.$attempt['id'].':'.$sequence,json_encode($safe,JSON_THROW_ON_ERROR)]);
    }
    $db->prepare("UPDATE director_http_calls SET outcome=?,http_status=?,request_id=?,finished_at=? WHERE attempt_id=? AND sequence=? AND outcome='started'")
        ->execute([$metadata===null||($metadata['succeeded']??true)!==true?'uncertain':'succeeded',$status,$request,gmdate('Y-m-d\TH:i:s\Z'),$attempt['id'],$sequence]);
}
function observeManagedUsage(PDO $db,array $attempt,string $turn,mixed $usage,array $pricing):?array
{
    $measurable=is_array($usage)&&is_int($usage['input_tokens']??null)&&is_int($usage['output_tokens']??null)
        &&$usage['input_tokens']>=0&&$usage['input_tokens']<=1000000000&&$usage['output_tokens']>=0&&$usage['output_tokens']<=1000000000
        &&is_int($usage['total_tokens']??null)&&$usage['total_tokens']===$usage['input_tokens']+$usage['output_tokens'];
    $cached=is_array($usage)?($usage['input_tokens_details']['cached_tokens']??0):0;$reasoning=is_array($usage)?($usage['output_tokens_details']['reasoning_tokens']??0):0;
    $measurable=$measurable&&is_int($cached)&&is_int($reasoning)&&$cached>=0&&$cached<=$usage['input_tokens']&&$reasoning>=0&&$reasoning<=$usage['output_tokens'];
    $cost=$measurable?estimatedAiCost($usage['input_tokens'],$usage['output_tokens'],$pricing['input_rate'],$pricing['output_rate']):null;
    $valid=$measurable&&$usage['input_tokens']>0&&$usage['input_tokens']<=aiInputTokenLimit()&&$usage['output_tokens']>0&&$usage['output_tokens']<=$pricing['max_output'];
    if($cost!==null&&$cost>$attempt['reserved'])$valid=false;
    $db->prepare('INSERT INTO director_usage_observations(attempt_id,turn_id,input_tokens,output_tokens,cached_tokens,reasoning_tokens,estimated_microusd,status,observed_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$attempt['id'],$turn,$measurable?$usage['input_tokens']:null,$measurable?$usage['output_tokens']:null,$measurable?$cached:null,$measurable?$reasoning:null,$cost,$valid?'observed':'unknown',gmdate('Y-m-d\TH:i:s\Z')]);
    if(!$valid)return null;
    // Observations never settle billing or release the original reservation.
    $db->prepare('UPDATE director_attempts SET input_tokens=?,output_tokens=? WHERE id=?')->execute([$usage['input_tokens'],$usage['output_tokens'],$attempt['id']]);
    return ['input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'observed_estimated_microusd'=>$cost];
}
function reviewManagedBilling(PDO $db,string $attemptId,string $reviewId,int $amount,string $evidenceHash):array
{
    if(!preg_match('/\A[a-f0-9]{32}\z/',$attemptId)||!preg_match('/\A[a-f0-9]{32}\z/',$reviewId)||!preg_match('/\A[a-f0-9]{64}\z/',$evidenceHash)||$amount<0||$amount>100000000)throw new AiGuard('configuration_invalid');
    $db->exec('BEGIN IMMEDIATE');try{
        assertSimulationLease($db);
        if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new AiGuard('simulation_busy');
        $q=$db->prepare('SELECT * FROM director_billing_reviews WHERE review_id=?');$q->execute([$reviewId]);$prior=$q->fetch();
        if($prior){if($prior['attempt_id']!==$attemptId||(int)$prior['amount_microusd']!==$amount||$prior['evidence_sha256']!==$evidenceHash)throw new AiGuard('configuration_invalid');$db->exec('COMMIT');return $prior;}
        $q=$db->prepare("SELECT a.*,r.status AS run_status,d.state AS director_state FROM ai_attempts a JOIN director_attempts d ON d.id=a.id AND d.runtime='agents' JOIN simulation_runs r ON r.id=a.id WHERE a.id=?");$q->execute([$attemptId]);$a=$q->fetch();
        if(!$a||!in_array($a['state'],['uncertain','applied','rejected'],true))throw new AiGuard('accounting_unresolved');
        $terminal=$db->prepare('SELECT status FROM director_managed_outcomes WHERE attempt_id=? ORDER BY id DESC LIMIT 1');$terminal->execute([$attemptId]);
        $outcome=$terminal->fetchColumn();
        // A rejected create has no session/turn to retrieve. Independent owner
        // evidence can reconcile it through this existing review, without refunds.
        $q=$db->prepare("SELECT COUNT(*) AS calls,MAX(CASE WHEN kind='create' AND http_status=400 THEN 1 ELSE 0 END) AS rejected FROM director_http_calls WHERE attempt_id=?");$q->execute([$attemptId]);$http=$q->fetch();
        $q=$db->prepare('SELECT provider_session_id FROM director_sessions WHERE id=(SELECT session_id FROM director_attempts WHERE id=?)');$q->execute([$attemptId]);
        $rejectedCreate=$outcome==='session_lost'&&(int)$http['calls']===1&&(int)$http['rejected']===1&&$q->fetchColumn()===null;
        if(!in_array($outcome,['completed','failed','cancelled'],true)&&!$rejectedCreate)throw new AiGuard('accounting_unresolved');
        // A late async bill may belong to a different month. Do not clear a
        // cross-period hold without a separately reviewed allocation procedure.
        if($a['month']!==gmdate('Y-m'))throw new AiGuard('accounting_unresolved');
        // Never manufacture headroom from a low estimate, refund or revised usage.
        $commitment=max((int)$a['reserved_microusd'],(int)($a['estimated_microusd']??0),$amount);$overrun=$commitment>(int)$a['reserved_microusd'];
        $state=$overrun?'uncertain':($a['run_status']==='completed'?'applied':'rejected');
        $db->prepare('INSERT INTO director_billing_reviews VALUES (?,?,?,?,?,?,?)')->execute([$reviewId,$attemptId,$evidenceHash,$amount,$commitment,$overrun?'overrun':'reviewed',gmdate('Y-m-d\TH:i:s\Z')]);
        $db->prepare('UPDATE ai_attempts SET estimated_microusd=?,state=?,error_code=? WHERE id=?')->execute([$commitment,$state,$overrun?'usage_unknown':null,$attemptId]);
        $db->prepare('UPDATE director_attempts SET estimated_microusd=?,state=? WHERE id=?')->execute([$commitment,$state,$attemptId]);
        $db->exec('COMMIT');return ['review_id'=>$reviewId,'commitment_microusd'=>$commitment,'outcome'=>$overrun?'overrun':'reviewed'];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
