<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/bulletin.php';
require_once __DIR__.'/conversation_lineage.php';

function commonsResidentIds(mixed $value): array
{
    if(!is_string($value)||strlen($value)>791||!preg_match('/\A[a-f0-9]{32}(?:,[a-f0-9]{32}){0,23}\z/',$value))throw new Problem(422,'Choose up to 24 visible resident IDs.');
    return array_values(array_unique(explode(',',$value)));
}

function commons(PDO $db,array $ids,?int $now=null): array
{
    $messageTable=messageRelation($db);
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
        JOIN $messageTable a ON a.conversation_id=c.id AND a.sequence=0 AND a.resident_id=visible.resident_id
        JOIN $messageTable b ON b.conversation_id=c.id AND b.sequence=1 AND b.resident_id<>a.resident_id
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
    $transcripts=conversationMessageBatch($db,array_column($conversations,'id'));
    foreach($conversations as &$conversation){$conversation['messages']=array_map(static function($m)use($conversation){unset($m['sequence']);$m['created_at']=$conversation['created_at'];return $m;},$transcripts[$conversation['id']]??[]);}unset($conversation);
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
    return ['homes'=>$homes,'conversations'=>$conversations,'story_threads'=>commonsStoryThreads($db,$ids,array_column($conversations,'id')),'bulletin_posts'=>$posts,'settings'=>$settings];
}

/** Bounded public stories. A three-segment chain must contain both verified links. */
function commonsStoryThreads(PDO $db,array $visible,array $latestIds=[]):array
{
    $source=conversationPublicSource($db);$marks=implode(',',array_fill(0,count($visible),'?'));
    $select="SELECT c.id,c.created_at,".$source['source']." AS provider,a.resident_id AS first_id,b.resident_id AS second_id";
    $valid=" AND a.resident_id IN ($marks) AND b.resident_id IN ($marks) AND (SELECT COUNT(*) FROM conversation_participants cp WHERE cp.conversation_id=c.id)=2
        AND EXISTS (SELECT 1 FROM events e WHERE e.run_id=c.run_id AND e.visibility='public' AND e.kind='conversation'
            AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
            AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id))";
    if($source['version']>=4)$valid.=" AND NOT EXISTS (SELECT 1 FROM simulation_opportunities o WHERE o.run_id=c.run_id AND o.outcome<>'conversation')";
    $q=$db->prepare($source['prefix'].$select.$source['sql'].$valid.' ORDER BY c.created_at DESC,c.id DESC LIMIT 64');$q->execute([...$visible,...$visible]);$recent=$q->fetchAll();if(!$recent)return [];
    $recentIds=array_column($recent,'id');
    // Keep the latest public story for each already-selected visible pair even
    // if many more recent records belong to just one other pair.
    $anchorIds=array_values(array_unique(array_slice($latestIds,0,64)));$recentIds=array_values(array_unique([...$anchorIds,...$recentIds]));
    $edges=[];foreach(array_chunk($recentIds,100) as $chunk)$edges+=publicConversationLineage($db,$chunk,$source['version'],$source);$needed=$recentIds;
    foreach($edges as $edge){$needed[]=$edge['source_id'];$needed[]=$edge['root_id'];}$needed=array_values(array_unique($needed));
    $marksNeeded=implode(',',array_fill(0,count($needed),'?'));$q=$db->prepare($source['prefix'].$select.$source['sql'].$valid." AND c.id IN ($marksNeeded)");$q->execute([...$visible,...$visible,...$needed]);$rows=array_column($q->fetchAll(),null,'id');
    $edges=[];foreach(array_chunk(array_keys($rows),100) as $chunk)$edges+=publicConversationLineage($db,$chunk,$source['version'],$source);
    $hasReceipt=[];if($source['version']===6){$q=$db->prepare("SELECT c.id FROM conversations c JOIN settings s ON s.name='director_conversation_flow:'||c.run_id WHERE c.id IN ($marksNeeded)");$q->execute($needed);$hasReceipt=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);}
    $directorProof=[];if($source['version']===6){$q=$db->prepare("SELECT c.id FROM conversations c JOIN director_runs d ON d.run_id=c.run_id AND d.conversation_id=c.id WHERE c.id IN ($marksNeeded)");$q->execute($needed);$directorProof=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);}
    foreach($edges as $id=>$edge)if(!isset($directorProof[$id],$directorProof[$edge['source_id']],$directorProof[$edge['root_id']]))unset($edges[$id]);
    $texts=conversationMessageBatch($db,array_keys($rows));$segments=[];
    foreach($rows as $id=>$row){
        $messages=$texts[$id]??[];$pair=[$row['first_id'],$row['second_id']];
        if(count($messages)<2||count($messages)>64)continue;
        foreach($messages as $i=>$message){if((int)$message['sequence']!==$i||$message['resident_id']!==$pair[$i%2]||!is_string($message['text'])||preg_match_all('/./us',$message['text'])<1||preg_match_all('/./us',$message['text'])>160)continue 2;}
        foreach($messages as &$message){unset($message['sequence']);$message['created_at']=$row['created_at'];}unset($message);
        $segments[$id]=['id'=>$id,'created_at'=>$row['created_at'],'provider'=>$row['provider'],'messages'=>$messages];
    }
    $groups=[];
    foreach($recentIds as $id){
        if(!isset($segments[$id]))continue;$chain=[$id];$edge=$edges[$id]??null;
        if($edge&&$edge['segment']===2&&isset($segments[$edge['root_id']])&&!isset($hasReceipt[$edge['root_id']]))$chain=[$edge['root_id'],$id];
        if($edge&&$edge['segment']===3&&isset($segments[$edge['root_id']],$segments[$edge['source_id']])&&!isset($hasReceipt[$edge['root_id']])){
            $parent=$edges[$edge['source_id']]??null;
            if($parent&&$parent['segment']===2&&$parent['source_id']===$edge['root_id']&&$parent['root_id']===$edge['root_id'])$chain=[$edge['root_id'],$edge['source_id'],$id];
        }
        $root=$chain[0];$covered=false;
        foreach($groups as $key=>$existing){$old=$existing['chain'];if(count($old)>=count($chain)&&array_slice($old,0,count($chain))===$chain){$covered=true;break;}if(count($chain)>count($old)&&array_slice($chain,0,count($old))===$old)unset($groups[$key]);}
        if($covered)continue;
        $list=array_map(static fn($segment)=>$segments[$segment],$chain);$last=$list[count($list)-1];
        $groups[]=['id'=>$root,'created_at'=>$last['created_at'],'provider'=>$last['provider'],'segments'=>$list,'chain'=>$chain,'priority'=>(bool)array_intersect($chain,$anchorIds)];
    }
    $out=array_values($groups);usort($out,static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($b['created_at'],$a['created_at'])?:strcmp($b['id'],$a['id']));$out=array_slice($out,0,64);foreach($out as &$story)unset($story['priority'],$story['chain']);unset($story);usort($out,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at'])?:strcmp($b['id'],$a['id']));return $out;
}
