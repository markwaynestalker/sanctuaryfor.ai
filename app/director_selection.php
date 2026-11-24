<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Read a bounded, unranked candidate set; private memories are not projected. */
function directorSelectionCandidates(PDO $db,string $day,array $cfg,int $maximumBytes):array
{
    $limit=min((int)$cfg['candidates'],64);
    if($limit<2||$maximumBytes<1)throw new AiGuard('configuration_invalid');
    $q=$db->prepare("SELECT r.id,r.name,r.personality,i.description,i.revision FROM residents r
        JOIN resident_states s ON s.resident_id=r.id AND s.activity='available'
        JOIN resident_identities i ON i.resident_id=r.id
        WHERE (SELECT COUNT(*) FROM simulation_opportunities o WHERE o.day=? AND (o.resident_a=r.id OR o.resident_b=r.id))<?
        ORDER BY r.id LIMIT ?");
    $q->bindValue(1,$day);$q->bindValue(2,$cfg['resident_daily'],PDO::PARAM_INT);$q->bindValue(3,$limit+1,PDO::PARAM_INT);$q->execute();
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>$limit)throw new AiGuard('selection_context_too_large');
    $out=[];
    foreach($rows as $r)$out[]=['id'=>$r['id'],'name'=>$r['name'],'owner_description'=>['text'=>$r['description'],'revision'=>(int)$r['revision']],
        'legacy_category'=>trim($r['description'])===''?$r['personality']:null];
    if(strlen(json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>$maximumBytes)throw new AiGuard('selection_context_too_large');
    return $out;
}

/** No generated text, facts, action, or out-of-snapshot identity is accepted. */
function parseDirectorSelection(array $proposal,array $candidates):array
{
    if(count($proposal)!==3||array_diff(array_keys($proposal),['contract','outcome','participants'])
        ||($proposal['contract']??null)!=='sanctuary.selection.v1'
        ||!in_array($proposal['outcome']??null,['quiet','conversation'],true)
        ||!is_array($proposal['participants']??null)||!array_is_list($proposal['participants']))throw new AiGuard('invalid_proposal');
    $ids=$proposal['participants'];
    if($proposal['outcome']==='quiet'){if($ids!==[])throw new AiGuard('invalid_proposal');return [];}
    if(count($ids)!==2||!is_string($ids[0])||!is_string($ids[1])||$ids[0]===$ids[1])throw new AiGuard('invalid_proposal');
    $allowed=array_column($candidates,'id');
    foreach($ids as $id)if(!in_array($id,$allowed,true))throw new AiGuard('invalid_proposal');
    return $ids;
}