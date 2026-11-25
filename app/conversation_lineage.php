<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Two bounded batches, no private lineage IDs or per-row history queries. Caller owns read snapshot. */
function publicConversationLineage(PDO $db,array $ids,?int $version=null,?array $publicSource=null):array
{
    if(!$ids||($version??(int)$db->query('PRAGMA user_version')->fetchColumn())!==6)return [];
    $ids=array_values(array_unique($ids));if(count($ids)>100)throw new \RuntimeException('Lineage page bound.');
    $marks=implode(',',array_fill(0,count($ids),'?'));$q=$db->prepare("SELECT c.id,s.value FROM conversations c JOIN settings s ON s.name='director_conversation_flow:'||c.run_id WHERE c.id IN ($marks)");$q->execute($ids);$candidates=[];$needed=[];
    foreach($q->fetchAll() as $row){
        if(strlen($row['value'])>2048)continue;$r=json_decode($row['value'],true,8);
        if(!is_array($r)||($r['version']??null)!==1||($r['conversation_id']??null)!==$row['id']||!in_array($r['segment']??null,[2,3],true))continue;
        foreach(['source_id','root_id'] as $key)if(!is_string($r[$key]??null)||!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$r[$key])||$r[$key]===$row['id'])continue 2;
        if($r['segment']===2&&$r['root_id']!==$r['source_id'])continue;
        $candidates[$row['id']]=$r;array_push($needed,$row['id'],$r['source_id'],$r['root_id']);
    }
    if(!$candidates)return [];$needed=array_values(array_unique($needed));$source=$publicSource??conversationPublicSource($db);$marks=implode(',',array_fill(0,count($needed),'?'));
    $q=$db->prepare($source['prefix']."SELECT c.id,c.created_at,a.resident_id AS first,b.resident_id AS second".$source['sql']." AND c.id IN ($marks)");$q->execute($needed);$public=array_column($q->fetchAll(),null,'id');$out=[];
    foreach($candidates as $id=>$r){
        if(!isset($public[$id],$public[$r['source_id']],$public[$r['root_id']]))continue;
        $pair=[$public[$id]['first'],$public[$id]['second']];sort($pair);
        foreach([$r['source_id'],$r['root_id']] as $parent){$p=[$public[$parent]['first'],$public[$parent]['second']];sort($p);if($p!==$pair||$public[$parent]['created_at']>$public[$id]['created_at'])continue 2;}
        $out[$id]=['source_id'=>$r['source_id'],'root_id'=>$r['root_id'],'segment'=>$r['segment']];
    }return $out;
}

/** Shared source authorization; callers add bound filters inside a read snapshot. */
function conversationPublicSource(PDO $db):array
{
    $messageTable=messageRelation($db);
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    $source=$version>=3?"CASE WHEN ai.conversation_id IS NOT NULL THEN 'openai' ELSE 'scripted' END":"'scripted'";
    $joins=$version>=3?' LEFT JOIN ai_conversations ai ON ai.conversation_id=c.id':'';
    $topic=$version===6?'COALESCE(d.topic,c.topic)':'c.topic';
    if($version===6)$joins.=' LEFT JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id';
    $sql=" FROM conversations c
        JOIN simulation_runs run ON run.id=c.run_id AND run.status='completed'
        JOIN $messageTable a ON a.conversation_id=c.id AND a.sequence=0
        JOIN $messageTable b ON b.conversation_id=c.id AND b.sequence=1 AND b.resident_id<>a.resident_id
        JOIN conversation_participants pa ON pa.conversation_id=c.id AND pa.resident_id=a.resident_id
        JOIN conversation_participants pb ON pb.conversation_id=c.id AND pb.resident_id=b.resident_id
        JOIN residents ra ON ra.id=a.resident_id JOIN residents rb ON rb.id=b.resident_id $joins
        WHERE c.visibility='public' AND length(a.body) BETWEEN 1 AND 160 AND length(b.body) BETWEEN 1 AND 160
        AND EXISTS(SELECT 1 FROM archive_events e WHERE e.run_id=c.run_id
            AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
            AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id))";
    // Materialize public event authorization once per query. SQLite can build a
    // transient run-id lookup instead of rescanning the full event log per row.
    $prefix="WITH archive_events AS MATERIALIZED (SELECT run_id,actor_id,target_id,dedupe_key FROM events WHERE visibility='public' AND kind IN ('conversation','quiet_time') AND target_id IS NOT NULL) ";
    return compact('prefix','sql','source','topic','messageTable','version');
}
