<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/selection_accounting.php';
require_once __DIR__.'/director_accounting.php';

/** Bind only a known session: reuse before inspection, or a successfully created session. */
function bindManagedSelectionSession(PDO $db,array $reservation,string $sessionId,int $now):void
{
    $now=max($now,time());
    if(!managedSelectionInstalled($db)||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$sessionId))throw new AiGuard('configuration_invalid');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT s.state FROM selection_reservations s JOIN managed_selection_reservations m ON m.selection_id=s.id WHERE s.id=? AND s.expires_at>? AND s.day=? AND s.state IN ('reserved','dispatched')");
        $q->execute([$reservation['id']??'',$now,gmdate('Y-m-d',$now)]);$state=$q->fetchColumn();if($state===false)throw new AiGuard('request_already_started');
        $q=$db->prepare("SELECT kind,outcome FROM managed_selection_http WHERE selection_id=? ORDER BY sequence");$q->execute([$reservation['id']]);$calls=$q->fetchAll();
        if(!(($state==='reserved'&&count($calls)===0)||($state==='dispatched'&&count($calls)===1&&$calls[0]['kind']==='create'&&$calls[0]['outcome']==='succeeded')))throw new AiGuard('configuration_invalid');
        $q=$db->prepare('INSERT INTO managed_selection_sessions(selection_id,session_id) VALUES (?,?)');$q->execute([$reservation['id'],$sessionId]);
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Persist the exact request identity and route hash before any provider I/O. */
function startManagedSelectionHttp(PDO $db,array $reservation,string $method,string $path,?string $auditKind,int $now,?array $body=null):array
{
    $now=max($now,time());if(!managedSelectionInstalled($db))throw new AiGuard('migration_required');
    $kind=managedRoute($method,$path);
    if($auditKind!==null){
        if(!(($kind==='turns'&&preg_match('/\A(?:turns_[1-3]|final_turn)\z/',$auditKind))||($kind==='submit'&&$auditKind==='cancel')))throw new AiGuard('configuration_invalid');
        $kind=$auditKind;
    }elseif($kind==='turns')throw new AiGuard('configuration_invalid');
    if(in_array($kind,['submit','cancel'],true)){
        $events=$body['events']??null;$expected=$kind==='cancel'?'agent.session.input.cancel':'agent.session.input.message';
        if(!is_array($events)||!array_is_list($events)||count($events)!==1||!is_array($events[0])||($events[0]['type']??null)!==$expected)throw new AiGuard('configuration_invalid');
    }
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT s.* FROM selection_reservations s JOIN managed_selection_reservations m ON m.selection_id=s.id JOIN simulation_opportunities o ON o.id=s.opportunity_id AND o.outcome='pending' WHERE s.id=? AND s.state IN ('reserved','dispatched') AND s.expires_at>?");
        $q->execute([$reservation['id']??'',$now]);$row=$q->fetch();if(!$row)throw new AiGuard('request_already_started');
        if($row['day']!==gmdate('Y-m-d',$now))throw new AiGuard('day_boundary');
        if(in_array($kind,['create','submit'],true)&&villageSleepingAt($now))throw new AiGuard('night_rest');
        $q=$db->prepare('SELECT kind,outcome FROM managed_selection_http WHERE selection_id=? ORDER BY sequence');$q->execute([$row['id']]);$calls=$q->fetchAll();$count=count($calls);
        if($count>=8||($count>=7&&!in_array($kind,['cancel','final_turn'],true)))throw new AiGuard('encounter_request_cap');
        $kinds=array_column($calls,'kind');if(in_array($kind,$kinds,true))throw new AiGuard('request_already_started');
        if($count===0&&!in_array($kind,['create','inspect_session'],true))throw new AiGuard('configuration_invalid');
        if(preg_match('~\Aagents/sessions/([a-zA-Z0-9_-]{1,128})(?:/|\?|$)~',$path,$sessionMatch)){
            $q=$db->prepare('SELECT session_id FROM managed_selection_sessions WHERE selection_id=?');$q->execute([$row['id']]);
            if($q->fetchColumn()!==$sessionMatch[1])throw new AiGuard('session_unavailable');
        }
        if($kind==='create'){
            if($count!==0)throw new AiGuard('request_already_started');
            $q=$db->prepare('SELECT 1 FROM managed_selection_sessions WHERE selection_id=?');$q->execute([$row['id']]);if($q->fetchColumn())throw new AiGuard('configuration_invalid');
        }
        if($kind==='submit'&&($count!==1||$calls[0]['kind']!=='inspect_session'||$calls[0]['outcome']!=='succeeded'))throw new AiGuard('configuration_invalid');
        if($kind==='inspect_session'&&$count!==0&&!in_array('create',$kinds,true))throw new AiGuard('configuration_invalid');
        $generation=null;foreach($calls as $call)if(in_array($call['kind'],['create','submit'],true))$generation=$call;
        if((str_starts_with($kind,'turns_')||$kind==='items'||$kind==='moderation')&&(!$generation||$generation['outcome']!=='succeeded'))throw new AiGuard('configuration_invalid');
        if($kind==='items'&&!array_intersect($kinds,['turns_1','turns_2','turns_3']))throw new AiGuard('configuration_invalid');
        if($kind==='moderation'&&!in_array('items',$kinds,true))throw new AiGuard('configuration_invalid');
        if($kind==='turns_2'&&!in_array('turns_1',$kinds,true))throw new AiGuard('configuration_invalid');
        if($kind==='turns_3'&&!in_array('turns_2',$kinds,true))throw new AiGuard('configuration_invalid');
        if(in_array('items',$kinds,true)&&(str_starts_with($kind,'turns_')||$kind==='cancel'))throw new AiGuard('configuration_invalid');
        if($kind==='final_turn'&&!in_array('items',$kinds,true)&&!in_array('cancel',$kinds,true))throw new AiGuard('configuration_invalid');
        if($kind==='cancel'&&!in_array('create',$kinds,true)&&!in_array('submit',$kinds,true))throw new AiGuard('configuration_invalid');
        foreach($calls as $call)if($call['outcome']!=='succeeded'&&$kind!=='cancel'&&!($kind==='final_turn'&&in_array('cancel',$kinds,true)))throw new AiGuard('transport_uncertain');
        if($count===0){
            $q=$db->prepare("UPDATE selection_reservations SET state='dispatched',request_id=? WHERE id=? AND state='reserved'");
            $q->execute([bin2hex(random_bytes(16)),$row['id']]);if($q->rowCount()!==1)throw new AiGuard('request_already_started');
        }elseif($row['state']!=='dispatched')throw new AiGuard('request_already_started');
        $identity=bin2hex(random_bytes(16));$sequence=$count+1;
        $q=$db->prepare("INSERT INTO managed_selection_http(selection_id,sequence,kind,method,path_sha256,request_identity,outcome,started_at) VALUES (?,?,?,?,?,?,'started',?)");
        $q->execute([$row['id'],$sequence,$kind,$method,hash('sha256',$path),$identity,gmdate('Y-m-d\TH:i:s\Z',$now)]);
        $db->exec('COMMIT');return ['sequence'=>$sequence,'request_identity'=>$identity];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Record transport metadata only; never retain provider bodies or release holds. */
function finishManagedSelectionHttp(PDO $db,array $reservation,int $sequence,?array $metadata,int $now):void
{
    $now=max($now,time());$status=$metadata['status']??null;$request=$metadata['request_id']??null;
    if($status!==null&&(!is_int($status)||$status<100||$status>599))throw new AiGuard('transport_uncertain');
    if($request!==null&&(!is_string($request)||!preg_match('/\Areq_[a-zA-Z0-9_-]{1,120}\z/',$request)||str_contains($request,'sk-')))$request=null;
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT 1 FROM selection_reservations WHERE id=? AND state='dispatched' AND expires_at>? AND day=?");
        $q->execute([$reservation['id']??'',$now,gmdate('Y-m-d',$now)]);if(!$q->fetchColumn())throw new AiGuard('request_already_started');
        $outcome=$metadata===null||($metadata['succeeded']??true)!==true||$status===null||$status<200||$status>=300?'uncertain':'succeeded';
        $q=$db->prepare("UPDATE managed_selection_http SET outcome=?,http_status=?,provider_request_id=?,finished_at=? WHERE selection_id=? AND sequence=? AND outcome='started'");
        $q->execute([$outcome,$status,$request,gmdate('Y-m-d\TH:i:s\Z',$now),$reservation['id'],$sequence]);if($q->rowCount()!==1)throw new AiGuard('request_already_started');
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Record bounded usage metadata; estimates can increase but never release a hold. */
function observeManagedSelectionUsage(PDO $db,array $reservation,string $turnId,mixed $usage,int $now):?array
{
    $now=max($now,time());if(!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$turnId)||str_contains($turnId,'sk-'))throw new AiGuard('configuration_invalid');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("SELECT m.input_rate,m.output_rate FROM managed_selection_reservations m JOIN selection_reservations s ON s.id=m.selection_id WHERE s.id=? AND s.state='dispatched' AND s.expires_at>? AND s.day=?");
        $q->execute([$reservation['id']??'',$now,gmdate('Y-m-d',$now)]);$pricing=$q->fetch();if(!$pricing)throw new AiGuard('request_already_started');
        $q=$db->prepare("SELECT (SELECT COUNT(*) FROM managed_selection_http WHERE selection_id=? AND kind IN ('turns_1','turns_2','turns_3','final_turn') AND outcome='succeeded')-(SELECT COUNT(*) FROM managed_selection_usage WHERE selection_id=?)");$q->execute([$reservation['id'],$reservation['id']]);if((int)$q->fetchColumn()<1)throw new AiGuard('configuration_invalid');
        $valid=is_array($usage)&&is_int($usage['input_tokens']??null)&&is_int($usage['output_tokens']??null)&&is_int($usage['total_tokens']??null)
            &&$usage['input_tokens']>0&&$usage['input_tokens']<=1000000000&&$usage['output_tokens']>0&&$usage['output_tokens']<=1000000000
            &&$usage['total_tokens']===$usage['input_tokens']+$usage['output_tokens'];
        $cached=is_array($usage)?($usage['input_tokens_details']['cached_tokens']??0):0;
        $reasoning=is_array($usage)?($usage['output_tokens_details']['reasoning_tokens']??0):0;
        $valid=$valid&&is_int($cached)&&is_int($reasoning)&&$cached>=0&&$cached<=$usage['input_tokens']&&$reasoning>=0&&$reasoning<=$usage['output_tokens'];
        $estimate=$valid?estimatedAiCost($usage['input_tokens'],$usage['output_tokens'],(int)$pricing['input_rate'],(int)$pricing['output_rate']):null;
        $q=$db->prepare('INSERT INTO managed_selection_usage(selection_id,turn_id,input_tokens,output_tokens,estimated_microusd,status,observed_at) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$reservation['id'],$turnId,$valid?$usage['input_tokens']:null,$valid?$usage['output_tokens']:null,$estimate,$valid?'observed':'unknown',gmdate('Y-m-d\TH:i:s\Z',$now)]);
        $db->exec('COMMIT');return $valid?['input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'estimated_microusd'=>$estimate]:null;
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}