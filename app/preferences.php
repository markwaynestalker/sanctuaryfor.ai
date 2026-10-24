<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function ensurePreferences(PDO $db,string $id,string $personality): void
{
    $interests=['gardening','stargazing','making_friends','quiet_walks'];
    $voice=match($personality){'playful'=>'bright','shy'=>'gentle','philosophical'=>'thoughtful',default=>'inquisitive'};
    $interest=$interests[hexdec(substr(hash('sha256',$id),0,2))%4];
    $db->prepare('INSERT OR IGNORE INTO resident_preferences(resident_id,interest,voice) VALUES (?,?,?)')->execute([$id,$interest,$voice]);
}

function preferences(PDO $db,string $id): ?array
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()<3)return null;
    $query=$db->prepare('SELECT interest,voice FROM resident_preferences WHERE resident_id=?');$query->execute([$id]);
    return $query->fetch()?:null;
}

function assertSimulationLease(PDO $db,?string $owner=null): void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()<3)return;
    $lease=$db->query('SELECT owner,expires_at FROM ai_lease WHERE singleton=1')->fetch();
    if($owner!==null){
        if($lease['owner']!==$owner || (int)$lease['expires_at']<time())throw new Problem(409,'The AI run no longer holds a valid simulation lease.');
    }elseif($lease['owner']!==null && (int)$lease['expires_at']>=time())throw new Problem(409,'A bounded AI simulation is in progress. Try again shortly.');
}
