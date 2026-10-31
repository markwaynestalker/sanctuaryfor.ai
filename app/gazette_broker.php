<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/gazette_editor_job.php';

const GAZETTE_BROKER_VERSION=1;
/** Policy is supplied by the root-configured helper, never by a socket client. */
function gazetteBrokerPolicyHash(array $policy,int $bytes,string $release):string
{
    return hash('sha256',editorialJson([$policy,$bytes,$release]));
}
/** Derive a stage capability from the existing private random lease-owner secret.
 * No extra secret/table, and the actual owner token never leaves the helper. */
function gazetteBrokerCapability(array $a,string $request,string $kind,int $uid,string $release):string
{
    return hash_hmac('sha256',editorialJson([GAZETTE_BROKER_VERSION,$uid,$release,$a['id'],$request,$kind,(int)$a['expires_at']]),$a['owner']);
}
function gazetteBrokerEnvelope(array $message,string $release,string $policyHash):void
{
    if(($message['version']??null)!==GAZETTE_BROKER_VERSION||($message['release']??null)!==$release||($message['policy_sha256']??null)!==$policyHash)throw new \RuntimeException('Broker protocol/policy mismatch.');
}
/** Trusted CLI domain entry: authenticated peer UID/clock/config come from the keyless adapter.
 * Provider JSON is an unverified worker observation, not authenticated billing evidence. */
function gazetteBrokerHandle(PDO $db,PDO $read,array $m,array $policy,int $bytes,string $release,int $peer,int $now,bool $enabled,?string $approvedDate=null):array
{
    $ph=gazetteBrokerPolicyHash($policy,$bytes,$release);gazetteBrokerEnvelope($m,$release,$ph);
    $op=$m['op']??'';
    if($op==='ready'){
        editorialKeys($m,['version','release','policy_sha256','op']);
        if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6||!editorialLedgerInstalled($db)||(int)$db->query('SELECT revision FROM gazette_editor_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Broker schema unavailable.');
        return ['status'=>'preflight_ready','version'=>GAZETTE_BROKER_VERSION,'policy_sha256'=>$ph];
    }
    if(!$enabled)throw new \RuntimeException('Broker disabled.');
    if($op==='begin_generation'){
        editorialKeys($m,['version','release','policy_sha256','op']);
        $local=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'));
        if($approvedDate===null){
            if($local->format('H')!=='05'||(int)$local->format('i')<15||(int)$local->format('i')>=20)throw new \RuntimeException('Broker admission window closed.');
            $date=$local->modify('-1 day')->format('Y-m-d');
        }else{$date=$approvedDate;if($date>=gazetteDay($date,$now)['today'])throw new \RuntimeException('Finished date required.');}
        ['snapshot'=>$snapshot,'policy'=>$storedPolicy,'request'=>$body]=editorialJobPlan($read,$date,$policy,$bytes,$now);
        if(!$snapshot['pack']['coverage']['conversations']&&!$snapshot['pack']['coverage']['notices'])return ['status'=>'quiet_or_input_bound'];
        $a=reserveEditorialAttempt($db,$snapshot,$storedPolicy,$now);
        $request=startEditorialRequest($db,$a['id'],$a['owner'],'generation',$now);
        return ['status'=>'dispatch_generation','attempt_id'=>$a['id'],'request_id'=>$request,'deadline'=>$a['expires_at'],
            'capability'=>gazetteBrokerCapability($a,$request,'generation',$peer,$release),'body'=>$body];
    }
    if(!in_array($op,['complete_generation','complete_moderation'],true))throw new \RuntimeException('Unknown broker operation.');
    editorialKeys($m,['version','release','policy_sha256','op','attempt_id','request_id','capability','wire']);
    foreach(['attempt_id','request_id'] as $key)if(!is_string($m[$key])||preg_match('/\A[a-f0-9]{32}\z/',$m[$key])!==1)throw new \RuntimeException('Invalid broker binding.');
    editorialHash($m['capability']);$kind=$op==='complete_generation'?'generation':'moderation';
    $q=$db->prepare('SELECT * FROM gazette_editor_attempts WHERE id=?');$q->execute([$m['attempt_id']]);$a=$q->fetch();
    if(!$a||!hash_equals(gazetteBrokerCapability($a,$m['request_id'],$kind,$peer,$release),$m['capability']))throw new \RuntimeException('Invalid broker capability.');
    activeEditorialAttempt($db,$a['id'],$a['owner'],$now);
    $q=$db->prepare('SELECT 1 FROM gazette_editor_requests q LEFT JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.id=? AND q.attempt_id=? AND q.kind=? AND r.request_id IS NULL');$q->execute([$m['request_id'],$a['id'],$kind]);if(!$q->fetchColumn())throw new \RuntimeException('Stage already consumed or unavailable.');
    $stored=json_decode($a['policy_json'],true,16,JSON_THROW_ON_ERROR);
    // Envelope hash includes the effective source-independent request contract.
    $snapshot=json_decode($a['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
    $expected=editorialRequest($snapshot,$policy);unset($expected['input']);$bound=$policy;$bound['pricing_version'].='-'.substr(hash('sha256',editorialJson([$expected,$bytes])),0,16);
    if(editorialJson($bound)!==editorialJson($stored))throw new \RuntimeException('Stored policy differs.');
    $request=$m['request_id'];$id=$a['id'];$owner=$a['owner'];$uncertain=true;
    try{
        $wire=$m['wire'];if(!is_array($wire))throw new \RuntimeException('Invalid observation.');editorialKeys($wire,['body','request_id']);
        if(strlen(editorialJson($wire['body']))>262144||!is_array($wire['body'])||array_is_list($wire['body']))throw new \RuntimeException('Invalid observation bound.');
        $r=$wire['body'];$remote=$wire['request_id'];
        if($kind==='generation'){
            $usage=$r['usage']??null;$input=null;$output=null;
            if($usage!==null){foreach(['input_tokens','output_tokens'] as $key)if(!is_int($usage[$key]??null)||$usage[$key]<0)throw new \RuntimeException('Invalid usage.');$input=$usage['input_tokens'];$output=$usage['output_tokens'];}
            $known=($r['status']??null)==='completed'&&($r['model']??null)===$policy['model']&&($r['service_tier']??null)==='default'&&($r['error']??null)===null&&($r['incomplete_details']??null)===null&&is_string($r['id']??null)&&preg_match('/\Aresp_[a-zA-Z0-9_-]{1,100}\z/',$r['id'])===1;
            receiptEditorialRequest($db,$id,$owner,$request,$known?'completed':'uncertain',$remote,$input,$output,null,$now,$known?$r['id']:null);$request=null;$uncertain=!$known;
            if(!$known||($input!==null&&$input>$policy['input_tokens'])||($output!==null&&$output>$policy['output_tokens']))throw new \RuntimeException('Unverified or overrun generation.');
            $draft=editorialResponseDraft($r,$policy);$sha=saveEditorialDraft($db,$id,$owner,$draft,$now);$v=validateEditorialDraft($draft,$snapshot);
            $body=['model'=>'omni-moderation-latest','input'=>$v['moderation_text']];if(strlen(editorialJson($body))>$bytes)throw new \RuntimeException('Moderation bound.');
            $next=startEditorialRequest($db,$id,$owner,'moderation',$now);
            return ['status'=>'dispatch_moderation','attempt_id'=>$id,'request_id'=>$next,'deadline'=>(int)$a['expires_at'],'draft_sha256'=>$sha,
                'capability'=>gazetteBrokerCapability($a,$next,'moderation',$peer,$release),'body'=>$body];
        }
        $results=$r['results']??null;if(!is_array($results)||count($results)!==1||!is_bool($results[0]['flagged']??null))throw new \RuntimeException('Unverified moderation.');
        $mod=$request;receiptEditorialRequest($db,$id,$owner,$request,'completed',$remote,null,null,$policy['moderation_microusd'],$now);$request=null;$uncertain=false;
        $q=$db->prepare('SELECT sha256,body_json FROM gazette_editor_drafts WHERE attempt_id=?');$q->execute([$id]);$saved=$q->fetch();$sha=$saved['sha256'];
        assertEditorialSources($db,$snapshot,validateEditorialDraft(json_decode($saved['body_json'],true,32,JSON_THROW_ON_ERROR),$snapshot)['sources']);
        $passed=!$results[0]['flagged'];saveEditorialModeration($db,$id,$owner,$sha,$mod,$passed?'passed':'flagged',$now);finishEditorialAttempt($db,$id,$owner,$passed?'finished':'rejected',$now);
        return ['status'=>$passed?'awaiting_owner_review':'moderation_rejected','attempt_id'=>$id,'draft_sha256'=>$sha];
    }catch(\Throwable){
        try{if($request!==null)receiptEditorialRequest($db,$id,$owner,$request,'uncertain',null,null,null,null,$now);finishEditorialAttempt($db,$id,$owner,$uncertain?'uncertain':'rejected',$now);}catch(\Throwable){}
        return ['status'=>'rejected_or_uncertain','attempt_id'=>$id];
    }
}
