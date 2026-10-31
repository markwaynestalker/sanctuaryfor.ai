<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/gazette_editor.php';
require_once __DIR__.'/gazette_editor_schema.php';

/** Trusted CLI callers only. Never hold this short lock across a network operation. */
function editorialTransaction(PDO $db,callable $work):mixed
{
    if(PHP_SAPI!=='cli'||!editorialLedgerInstalled($db)||(int)$db->query('SELECT revision FROM gazette_editor_meta')->fetchColumn()!==1)throw new \RuntimeException('Editorial ledger unavailable.');
    $busy=(int)$db->query('PRAGMA busy_timeout')->fetchColumn();$begun=false;
    try{$db->exec('PRAGMA busy_timeout=100');$db->exec('BEGIN IMMEDIATE');$begun=true;$result=$work();$db->exec('COMMIT');$begun=false;return $result;}
    catch(\Throwable $e){if($begun)$db->exec('ROLLBACK');throw $e;}
    finally{$db->exec('PRAGMA busy_timeout='.$busy);}
}
function editorialHash(mixed $value):string
{
    if(!is_string($value)||preg_match('/\A[a-f0-9]{64}\z/',$value)!==1)throw new \RuntimeException('Invalid editorial digest.');return $value;
}
function editorialTokenCost(int $input,int $output,int $inputRate,int $outputRate):int
{
    $cost=0;
    foreach([[$input,$inputRate],[$output,$outputRate]] as [$tokens,$rate]){
        if($tokens<0||$rate<1||$tokens>intdiv(PHP_INT_MAX,$rate))throw new \RuntimeException('Editorial cost overflow.');
        $product=$tokens*$rate;$part=intdiv($product,1000000)+(int)($product%1000000!==0);
        if($cost>PHP_INT_MAX-$part)throw new \RuntimeException('Editorial cost overflow.');$cost+=$part;
    }
    return $cost;
}
/** No model prices/default budgets selected here. Integration must verify the supplied pricing policy. */
function editorialReservationCost(array $policy):int
{
    editorialKeys($policy,['model','pricing_version','input_tokens','output_tokens','input_rate','output_rate','moderation_microusd','daily_microusd','monthly_microusd','daily_requests','lease_seconds']);
    foreach(['model','pricing_version'] as $key)if(!is_string($policy[$key])||preg_match('/\A[a-zA-Z0-9_.-]{1,80}\z/',$policy[$key])!==1)throw new \RuntimeException('Invalid editorial policy.');
    foreach(array_diff(array_keys($policy),['model','pricing_version']) as $key)if(!is_int($policy[$key])||$policy[$key]<0)throw new \RuntimeException('Explicit nonnegative editorial limits required.');
    if($policy['input_tokens']<1||$policy['output_tokens']<1||$policy['input_rate']<1||$policy['output_rate']<1||$policy['lease_seconds']<1||$policy['lease_seconds']>900)throw new \RuntimeException('Invalid editorial execution bounds.');
    $cost=editorialTokenCost($policy['input_tokens'],$policy['output_tokens'],$policy['input_rate'],$policy['output_rate']);
    if($cost>PHP_INT_MAX-$policy['moderation_microusd'])throw new \RuntimeException('Editorial cost overflow.');
    return $cost+$policy['moderation_microusd'];
}
/** Reauthorize original records, not a fresh reporting sample or changed day counts. */
function assertEditorialSources(PDO $db,array $snapshot,?array $aliases=null):void
{
    if(strlen(editorialJson($snapshot))>262144||($snapshot['pack']['contract']??null)!=='sanctuary.gazette.sources.v1'
        ||!hash_equals(editorialHash($snapshot['sha256']??null),hash('sha256',editorialJson($snapshot['pack']))))throw new \RuntimeException('Invalid editorial source snapshot.');
    $sources=$snapshot['pack']['sources'];$wanted=[];$notices=[];
    foreach($sources as $s)if($aliases===null||in_array($s['alias'],$aliases,true)){
        if($s['kind']==='conversation')$wanted[$s['id']]=$s;elseif($s['kind']==='notice')$notices[$s['id']]=$s;else throw new \RuntimeException('Invalid source kind.');
    }
    if($wanted){
        ['prefix'=>$prefix,'sql'=>$sql,'source'=>$provider]=gazetteSourceSetup($db);$ids=array_keys($wanted);$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare($prefix."SELECT c.id,c.created_at,$provider AS provider $sql AND c.id IN ($marks)");$q->execute($ids);$found=$q->fetchAll();
        if(count($found)!==count($wanted))throw new \RuntimeException('Editorial source unavailable.');
        $messages=conversationMessageBatch($db,$ids);
        foreach($found as $row){$s=$wanted[$row['id']];
            if($s['at']!==$row['created_at']||$s['provider']!==$row['provider']||editorialJson($s['messages'])!==editorialJson(array_map(static fn($m)=>['sequence'=>$m['sequence'],'resident_id'=>$m['resident_id'],'name'=>$m['name'],'text'=>$m['text']],$messages[$row['id']]??[])))throw new \RuntimeException('Editorial source changed.');}
    }
    if($notices){
        if(!bulletinInstalled($db))throw new \RuntimeException('Editorial notice unavailable.');$ids=array_keys($notices);$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare('SELECT n.id,n.resident_id,r.name,n.body,n.kind,n.created_at,n.expires_at'.bulletinPublicSql()." AND n.id IN ($marks)");$q->execute($ids);$found=$q->fetchAll();
        if(count($found)!==count($notices))throw new \RuntimeException('Editorial notice unavailable.');
        foreach($found as $n){$s=$notices[$n['id']];foreach(['resident_id'=>'resident_id','name'=>'name','text'=>'body','notice_kind'=>'kind','at'=>'created_at','expires_at'=>'expires_at'] as $a=>$b)if($s[$a]!==$n[$b])throw new \RuntimeException('Editorial notice changed.');}
    }
}
function expireEditorialLease(PDO $db,int $now):void
{
    $lease=$db->query('SELECT * FROM gazette_editor_lease WHERE singleton=1')->fetch();
    if($lease['attempt_id']===null||(int)$lease['expires_at']>$now)return;
    $db->prepare("INSERT OR IGNORE INTO gazette_editor_outcomes VALUES (?,'uncertain',?)")->execute([$lease['attempt_id'],$now]);
    $db->exec('UPDATE gazette_editor_lease SET attempt_id=NULL,owner=NULL,expires_at=0 WHERE singleton=1');
}
function activeEditorialAttempt(PDO $db,string $id,string $owner,int $now):array
{
    $q=$db->prepare('SELECT a.* FROM gazette_editor_attempts a JOIN gazette_editor_lease l ON l.attempt_id=a.id AND l.owner=a.owner WHERE a.id=? AND a.owner=? AND l.expires_at>? AND a.expires_at>?');$q->execute([$id,$owner,$now,$now]);$a=$q->fetch();
    if(!$a||$now<(int)$a['created_at']||gmdate('Y-m-d',$now)!==$a['day'])throw new \RuntimeException('Editorial worker fenced.');return $a;
}
/** Additional paid revisions require a separate exact-snapshot local owner permit. */
function permitEditorialRevision(PDO $db,string $date,int $ordinal,string $sourceHash,string $policyHash,int $maxCost,int $expires,int $now):string
{
    if($date>=gazetteDay($date,$now)['today']||$ordinal<2||$ordinal>8||$maxCost<0||$expires<=$now||$expires-$now>86400)throw new \RuntimeException('Invalid editorial revision permit.');
    editorialHash($sourceHash);editorialHash($policyHash);
    return editorialTransaction($db,function()use($db,$date,$ordinal,$sourceHash,$policyHash,$maxCost,$expires,$now){
        $id=bin2hex(random_bytes(16));$db->prepare('INSERT INTO gazette_editor_permits VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$date,$ordinal,$sourceHash,$policyHash,$maxCost,$expires,$now]);return $id;
    });
}
function reserveEditorialAttempt(PDO $db,array $snapshot,array $policy,int $now,?string $permit=null):array
{
    $cost=editorialReservationCost($policy);$policyJson=editorialJson($policy);$policyHash=hash('sha256',$policyJson);$date=$snapshot['pack']['date']??'';
    if($date>=gazetteDay($date,$now)['today'])throw new \RuntimeException('Finished edition required.');
    $day=gmdate('Y-m-d',$now);$month=gmdate('Y-m',$now);$expires=$now+$policy['lease_seconds'];
    if(gmdate('Y-m-d',$expires)!==$day)throw new \RuntimeException('Editorial UTC boundary guard.');
    return editorialTransaction($db,function()use($db,$snapshot,$policy,$policyJson,$policyHash,$date,$cost,$now,$day,$month,$expires,$permit){
        assertEditorialSources($db,$snapshot);
        if(!$snapshot['pack']['coverage']['conversations']&&!$snapshot['pack']['coverage']['notices'])throw new \RuntimeException('No edition-day material.');
        expireEditorialLease($db,$now);
        if($db->query('SELECT attempt_id FROM gazette_editor_lease')->fetchColumn()!==null)throw new \RuntimeException('Editorial worker busy.');
        $q=$db->prepare('SELECT COUNT(*) FROM gazette_editor_attempts WHERE edition=?');$q->execute([$date]);$ordinal=(int)$q->fetchColumn()+1;
        if($ordinal>1){
            $q=$db->prepare("SELECT 1 FROM gazette_editor_attempts a LEFT JOIN gazette_editor_outcomes o ON o.attempt_id=a.id WHERE a.edition=? AND (o.outcome IS NULL OR o.outcome='uncertain')");$q->execute([$date]);if($q->fetchColumn())throw new \RuntimeException('Uncertain edition cannot be regenerated.');
            $q=$db->prepare('SELECT * FROM gazette_editor_permits WHERE id=? AND edition=? AND ordinal=? AND source_sha256=? AND policy_sha256=? AND max_microusd>=? AND expires_at>?');$q->execute([$permit,$date,$ordinal,$snapshot['sha256'],$policyHash,$cost,$now]);if(!$q->fetch())throw new \RuntimeException('Separate revision approval required.');
        }elseif($permit!==null)throw new \RuntimeException('Unexpected revision permit.');
        // Keep every reservation. Observed overruns can increase commitments, never refund holds.
        $commit="MAX(a.reserved_microusd,COALESCE((SELECT SUM(r.estimated_microusd) FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id),0))";
        foreach(['day'=>[$day,'daily_microusd'],'month'=>[$month,'monthly_microusd']] as $field=>[$window,$key]){
            $q=$db->prepare("SELECT COALESCE(SUM($commit),0) FROM gazette_editor_attempts a WHERE a.$field=?");$q->execute([$window]);$used=$q->fetchColumn();
            if(!is_int($used)||$cost>$policy[$key]||$used>$policy[$key]-$cost)throw new \RuntimeException('Editorial spending limit.');
        }
        $q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM gazette_editor_attempts WHERE day=?');$q->execute([$day]);$used=$q->fetchColumn();
        if(!is_int($used)||$policy['daily_requests']<2||$used>$policy['daily_requests']-2)throw new \RuntimeException('Editorial request limit.');
        $id=bin2hex(random_bytes(16));$owner=bin2hex(random_bytes(16));
        $key=hash('sha256',editorialJson([$date,$snapshot['sha256'],$policyHash,$permit]));
        $db->prepare('INSERT INTO gazette_editor_attempts VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$date,$ordinal,$key,$snapshot['sha256'],editorialJson($snapshot),$policyHash,$policyJson,$day,$month,$cost,2,$owner,$now,$expires,$permit]);
        $db->prepare('UPDATE gazette_editor_lease SET attempt_id=?,owner=?,expires_at=? WHERE singleton=1')->execute([$id,$owner,$expires]);
        return ['id'=>$id,'owner'=>$owner,'reserved_microusd'=>$cost,'expires_at'=>$expires];
    });
}
/** Commit dispatch BEFORE sending. Unique kind means a timeout cannot be retried. */
function startEditorialRequest(PDO $db,string $id,string $owner,string $kind,int $now):string
{
    return editorialTransaction($db,function()use($db,$id,$owner,$kind,$now){
        activeEditorialAttempt($db,$id,$owner,$now);
        if(!in_array($kind,['generation','moderation'],true))throw new \RuntimeException('Unknown editorial request.');
        if($kind==='moderation'){$q=$db->prepare('SELECT 1 FROM gazette_editor_drafts WHERE attempt_id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new \RuntimeException('Draft required before moderation.');}
        $request=bin2hex(random_bytes(16));$db->prepare('INSERT INTO gazette_editor_requests VALUES (?,?,?,?)')->execute([$request,$id,$kind,$now]);return $request;
    });
}
function receiptEditorialRequest(PDO $db,string $id,string $owner,string $request,string $outcome,?string $remote,?int $input,?int $output,?int $estimate,int $now,?string $responseId=null):void
{
    if(!in_array($outcome,['completed','refused','uncertain'],true)||($remote!==null&&preg_match('/\Areq_[a-zA-Z0-9_-]{1,100}\z/',$remote)!==1))throw new \RuntimeException('Invalid editorial receipt.');
    if($responseId!==null&&preg_match('/\Aresp_[a-zA-Z0-9_-]{1,100}\z/',$responseId)!==1)throw new \RuntimeException('Invalid editorial response identifier.');
    foreach([$input,$output,$estimate] as $n)if($n!==null&&$n<0)throw new \RuntimeException('Invalid editorial usage.');
    editorialTransaction($db,function()use($db,$id,$owner,$request,$outcome,$remote,$input,$output,$estimate,$now,$responseId){
        $attempt=activeEditorialAttempt($db,$id,$owner,$now);$q=$db->prepare('SELECT kind FROM gazette_editor_requests WHERE id=? AND attempt_id=? AND created_at<=?');$q->execute([$request,$id,$now]);$kind=$q->fetchColumn();if(!$kind)throw new \RuntimeException('Unbound editorial receipt.');
        if($kind==='generation'&&$input!==null&&$output!==null){$policy=json_decode($attempt['policy_json'],true,16,JSON_THROW_ON_ERROR);$estimate=max($estimate??0,editorialTokenCost($input,$output,$policy['input_rate'],$policy['output_rate']));}
        if($responseId!==null&&$kind!=='generation')throw new \RuntimeException('Response identifier belongs to generation.');
        $db->prepare('INSERT INTO gazette_editor_receipts VALUES (?,?,?,?,?,?,?,?)')->execute([$request,$outcome,$remote,$responseId,$input,$output,$estimate,$now]);
    });
}
function saveEditorialDraft(PDO $db,string $id,string $owner,array $draft,int $now):string
{
    return editorialTransaction($db,function()use($db,$id,$owner,$draft,$now){$a=activeEditorialAttempt($db,$id,$owner,$now);
        $snapshot=json_decode($a['snapshot_json'],true,32,JSON_THROW_ON_ERROR);$validation=validateEditorialDraft($draft,$snapshot);assertEditorialSources($db,$snapshot,$validation['sources']);
        $db->prepare('INSERT INTO gazette_editor_drafts VALUES (?,?,?,?)')->execute([$id,$validation['draft_sha256'],editorialJson($draft),$now]);return $validation['draft_sha256'];
    });
}
function saveEditorialModeration(PDO $db,string $id,string $owner,string $draftHash,string $request,string $result,int $now):void
{
    editorialHash($draftHash);
    editorialTransaction($db,function()use($db,$id,$owner,$draftHash,$request,$result,$now){activeEditorialAttempt($db,$id,$owner,$now);
        $db->prepare('INSERT INTO gazette_editor_moderations VALUES (?,?,?,?,?)')->execute([$id,$draftHash,$request,$result,$now]);
    });
}
function finishEditorialAttempt(PDO $db,string $id,string $owner,string $outcome,int $now):void
{
    if(!in_array($outcome,['finished','rejected','uncertain'],true))throw new \RuntimeException('Invalid editorial outcome.');
    editorialTransaction($db,function()use($db,$id,$owner,$outcome,$now){activeEditorialAttempt($db,$id,$owner,$now);
        if($outcome!=='uncertain'){$q=$db->prepare("SELECT 1 FROM gazette_editor_requests q LEFT JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=? AND (r.outcome IS NULL OR r.outcome='uncertain')");$q->execute([$id]);if($q->fetchColumn())throw new \RuntimeException('Unknown request outcome retains uncertainty.');}
        if($outcome==='finished'){$q=$db->prepare("SELECT 1 FROM gazette_editor_moderations WHERE attempt_id=? AND result='passed'");$q->execute([$id]);if(!$q->fetchColumn())throw new \RuntimeException('Passed moderation required.');}
        $db->prepare('INSERT INTO gazette_editor_outcomes VALUES (?,?,?)')->execute([$id,$outcome,$now]);
        $db->exec('UPDATE gazette_editor_lease SET attempt_id=NULL,owner=NULL,expires_at=0 WHERE singleton=1');
    });
}
/** Evidence record only: local owner must inspect exact copy; no publication occurs. */
function reviewEditorialDraft(PDO $db,string $id,string $expectedHash,string $decision,int $now):void
{
    editorialHash($expectedHash);
    editorialTransaction($db,function()use($db,$id,$expectedHash,$decision,$now){
        $q=$db->prepare('SELECT d.body_json,a.snapshot_json FROM gazette_editor_drafts d JOIN gazette_editor_attempts a ON a.id=d.attempt_id WHERE d.attempt_id=? AND d.sha256=?');$q->execute([$id,$expectedHash]);$row=$q->fetch();if(!$row)throw new \RuntimeException('Review digest mismatch.');
        $draft=json_decode($row['body_json'],true,32,JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        $v=validateEditorialDraft($draft,$snapshot);
        if(!hash_equals($expectedHash,$v['draft_sha256']))throw new \RuntimeException('Stored draft digest mismatch.');
        assertEditorialSources($db,$snapshot,$v['sources']);
        $db->prepare("INSERT INTO gazette_editor_reviews VALUES (?,?,?,'local_owner',?)")->execute([$id,$expectedHash,$decision,$now]);
    });
}
