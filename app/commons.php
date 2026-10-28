<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/bulletin.php';

function commonsResidentIds(mixed $value): array
{
    if(!is_string($value)||strlen($value)>791||!preg_match('/\A[a-f0-9]{32}(?:,[a-f0-9]{32}){0,23}\z/',$value))throw new Problem(422,'Choose up to 24 visible resident IDs.');
    return array_values(array_unique(explode(',',$value)));
}

function commons(PDO $db,array $ids,?int $now=null): array
{
    $ids=commonsResidentIds(implode(',',$ids));$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT h.resident_id,h.slot,w.variant AS house_variant,o.id,o.kind,o.variant,o.x,o.y FROM home_plots h
        JOIN world_objects w ON w.resident_id=h.resident_id AND w.kind='house'
        JOIN world_objects o ON o.resident_id=h.resident_id
        WHERE h.resident_id IN ($marks) ORDER BY h.slot");$q->execute($ids);$homes=[];
    foreach($q->fetchAll() as $home){
        $id=$home['resident_id'];$homes[$id]??=['resident_id'=>$id,'plot'=>plotCoordinates((int)$home['slot']),'variant'=>$home['house_variant'],'objects'=>[]];
        $homes[$id]['objects'][]=array_intersect_key($home,array_flip(['id','kind','variant','x','y']));
    }
    $homes=array_values($homes);
    $hasAi=(int)$db->query('PRAGMA user_version')->fetchColumn()>=3;
    $source=$hasAi?"CASE WHEN ai.conversation_id IS NOT NULL THEN 'openai' ELSE 'scripted' END":"'scripted'";
    $join=$hasAi?'LEFT JOIN ai_conversations ai ON ai.conversation_id=c.id':'';
    // One newest exchange per unordered pair, capped to 64 recent pairs. Both
    // speakers must be on screen; no visitor prompts, private memories or tokens.
    $q=$db->prepare("WITH ranked AS (
        SELECT c.id,c.created_at,$source AS provider,
            a.resident_id AS first_id,b.resident_id AS second_id,
            substr(ra.name,1,64) AS first_name,substr(rb.name,1,64) AS second_name,
            a.body AS first_text,b.body AS second_text,
            ROW_NUMBER() OVER(PARTITION BY MIN(a.resident_id,b.resident_id),MAX(a.resident_id,b.resident_id)
                ORDER BY c.created_at DESC,c.id DESC) AS rank
        FROM conversation_participants visible
        JOIN conversations c ON c.id=visible.conversation_id
        JOIN simulation_runs run ON run.id=c.run_id AND run.status='completed'
        JOIN messages a ON a.conversation_id=c.id AND a.sequence=0 AND a.resident_id=visible.resident_id
        JOIN messages b ON b.conversation_id=c.id AND b.sequence=1 AND b.resident_id<>a.resident_id
        JOIN residents ra ON ra.id=a.resident_id JOIN residents rb ON rb.id=b.resident_id $join
        WHERE c.visibility='public' AND visible.resident_id IN ($marks) AND b.resident_id IN ($marks)
            AND length(a.body) BETWEEN 1 AND 160 AND length(b.body) BETWEEN 1 AND 160
            AND EXISTS(SELECT 1 FROM events e WHERE e.run_id=c.run_id AND e.visibility='public'
                AND e.kind IN ('conversation','quiet_time')
                AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
                AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id))
        ) SELECT * FROM ranked WHERE rank=1 ORDER BY created_at DESC,id DESC LIMIT 64");
    $q->execute([...$ids,...$ids]);$conversations=[];
    foreach($q->fetchAll() as $c)$conversations[]=['id'=>$c['id'],'created_at'=>$c['created_at'],'provider'=>$c['provider'],'messages'=>[
        ['resident_id'=>$c['first_id'],'name'=>$c['first_name'],'text'=>$c['first_text'],'created_at'=>$c['created_at']],
        ['resident_id'=>$c['second_id'],'name'=>$c['second_name'],'text'=>$c['second_text'],'created_at'=>$c['created_at']]
    ]];
    $now??=time();$tick=publishedVillageTick($db);$settings=['replay_cooldown_seconds'=>replayCooldownSeconds(),'sleep'=>villagePublicSleepState($db,$now,$tick)];
    if($weather=publicVillageWeather($tick,$now))$settings['weather']=$weather;
    // Saved authorship only, never an inferred reading or a live activity claim.
    // Reuse the board's public provenance/removal rules without exposing body,
    // provider sessions, private credentials or internal run identifiers.
    $posts=[];
    if(bulletinInstalled($db)){
        $q=$db->prepare("SELECT id,resident_id,name,created_at FROM (
            SELECT n.id,n.resident_id,r.name,n.created_at,
                ROW_NUMBER() OVER(PARTITION BY n.resident_id ORDER BY n.created_at DESC,n.id DESC) AS rank"
            .bulletinPublicSql()." AND n.resident_id IN ($marks) AND (n.expires_at IS NULL OR n.expires_at>?)
            ) WHERE rank=1 ORDER BY created_at DESC,id DESC LIMIT 24");
        $q->execute([...$ids,gmdate('Y-m-d\TH:i:s\Z',$now)]);$posts=$q->fetchAll();
    }
    return ['homes'=>$homes,'conversations'=>$conversations,'bulletin_posts'=>$posts,'settings'=>$settings];
}
