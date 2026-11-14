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
