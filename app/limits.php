<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function unsignedEnvironmentInteger(string $name,?int $default=null):int
{
    $value=getenv($name);if($value===false&&$default!==null)return $default;
    $maximum=(string)PHP_INT_MAX;
    if($value===false||strlen($value)>strlen($maximum)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$value)
        ||(strlen($value)===strlen($maximum)&&strcmp($value,$maximum)>0))throw new Problem(503,'Operational limits are invalid.');
    return (int)$value;
}
function boundedEnvironmentInteger(string $name,int $default,int $minimum,int $maximum):int
{
    $value=unsignedEnvironmentInteger($name,$default);
    if($value<$minimum||$value>$maximum)throw new Problem(503,'Operational limits are invalid.');
    return $value;
}
function activityConfiguration():array
{
    residentFriendshipLimit();
    return ['daily_opportunities'=>unsignedEnvironmentInteger('LITTLE_SPARKS_ACTIVITY_DAILY_MAX',100),
        'resident_daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_RESIDENT_DAILY_MAX',12),
        'candidates'=>boundedEnvironmentInteger('LITTLE_SPARKS_ACTIVITY_CANDIDATES',64,2,10000),
        'resident_cooldown'=>boundedEnvironmentInteger('LITTLE_SPARKS_RESIDENT_CONVERSATION_COOLDOWN_SECONDS',0,0,86400),
        'pair_cooldown'=>boundedEnvironmentInteger('LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS',0,0,604800)];
}
function residentFriendshipLimit():int{return unsignedEnvironmentInteger('LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX',8);}
function directorExecutionConfiguration():array
{
    // These must fit the existing 50-second systemd unit and eight-slot V6 ledger.
    // Unsupported values are rejected, never silently clamped to these bounds.
    $run=boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_RUN_SECONDS',45,20,45);
    $finish=boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_FINISH_RESERVE_SECONDS',15,5,$run-1);
    $lease=boundedEnvironmentInteger('LITTLE_SPARKS_AI_LEASE_SECONDS',60,$run+$finish,PHP_INT_MAX-time());
    return ['run_seconds'=>$run,'finish_reserve_seconds'=>$finish,'lease_seconds'=>$lease,
        'http_timeout_seconds'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_HTTP_TIMEOUT_SECONDS',8,1,$run),
        'poll_timeout_seconds'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_POLL_TIMEOUT_SECONDS',4,1,$run-$finish),
        'cleanup_timeout_seconds'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_CLEANUP_TIMEOUT_SECONDS',3,1,$finish),
        'poll_interval_seconds'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_POLL_INTERVAL_SECONDS',4,0,$run-$finish),
        'poll_max_reads'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_POLL_MAX_READS',3,1,3),
        'session_max_turns'=>unsignedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_SESSION_MAX_TURNS',8),
        'session_ttl_seconds'=>boundedEnvironmentInteger('LITTLE_SPARKS_DIRECTOR_SESSION_TTL_SECONDS',86400,1,time())];
}
function replayCooldownSeconds():int{return boundedEnvironmentInteger('LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS',60,0,3600);}

// All callers selecting/applying real activity hold BEGIN IMMEDIATE. These read
// only successful saved conversations, never browser replays or mere visits.
function latestResidentConversations(PDO $db,array $ids):array
{
    if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT p.resident_id,MAX(c.created_at) AS latest FROM conversation_participants p
        JOIN conversations c ON c.id=p.conversation_id JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        WHERE p.resident_id IN ($marks) GROUP BY p.resident_id");$q->execute($ids);return array_column($q->fetchAll(),'latest','resident_id');
}
function latestPairConversations(PDO $db,string $actor):array
{
    $q=$db->prepare("SELECT b.resident_id,MAX(c.created_at) AS latest FROM conversation_participants a
        JOIN conversations c ON c.id=a.conversation_id JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        JOIN conversation_participants b ON b.conversation_id=c.id AND b.resident_id<>a.resident_id
        WHERE a.resident_id=? GROUP BY b.resident_id");$q->execute([$actor]);return array_column($q->fetchAll(),'latest','resident_id');
}
function conversationCooldownAllows(PDO $db,string $a,string $b,array $cfg,int $now):bool
{
    if($cfg['resident_cooldown']){
        $cutoff=gmdate('Y-m-d\TH:i:s\Z',$now-$cfg['resident_cooldown']);
        foreach(latestResidentConversations($db,[$a,$b]) as $time)if($time>$cutoff)return false;
    }
    return !$cfg['pair_cooldown']||(latestPairConversations($db,$a)[$b]??'')<=gmdate('Y-m-d\TH:i:s\Z',$now-$cfg['pair_cooldown']);
}
function assertAppliedActivityLimits(PDO $db,array $pair,string $day,array $cfg):void
{
    $q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=?');$q->execute([$day]);
    if((int)$q->fetchColumn()>$cfg['daily_opportunities'])throw new Problem(429,'Village activity is paused at its configured ceiling.');
    $q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=? AND (resident_a=? OR resident_b=?)');
    foreach($pair as $r){$q->execute([$day,$r['id'],$r['id']]);if((int)$q->fetchColumn()>$cfg['resident_daily'])throw new Problem(429,'This resident has reached the configured activity ceiling.');}
}
