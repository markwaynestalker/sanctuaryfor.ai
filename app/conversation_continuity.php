<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

const CONVERSATION_FLOW_SEGMENTS=3;
const CONVERSATION_FLOW_SECONDS=1800;

/** Read only exact saved speech; a continuation opportunity is not a new fact. */
function conversationFlowSource(PDO $db,string $id):?array
{
    $source=publicMemoryConversation($db,$id);if(!$source)return null;
    $q=$db->prepare('SELECT 1 FROM director_runs WHERE conversation_id=? AND run_id=?');$q->execute([$id,$source['run_id']]);if(!$q->fetchColumn())return null;
    $messages=conversationMessageBatch($db,[$id])[$id]??[];if(count($messages)<2)return null;
    return ['id'=>$id,'at'=>$source['created_at'],'messages'=>array_map(static fn($m)=>['resident_id'=>$m['resident_id'],'text'=>$m['text']],$messages)];
}

/** Called under the opportunity claim lock. Never skip a failed/quiet/pending slot. */
function conversationFlowCandidate(PDO $db,string $day,array $cfg,int $now):?array
{
    if(!directorEnabled($db)||!multiMessageInstalled($db))return null;
    $last=$db->query('SELECT * FROM simulation_opportunities ORDER BY rowid DESC LIMIT 1')->fetch();
    if(!$last||$last['outcome']!=='conversation'||$last['day']!==$day)return null;
    $q=$db->prepare("SELECT c.id,s.value FROM conversations c JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id LEFT JOIN settings s ON s.name='director_conversation_flow:'||c.run_id WHERE c.run_id=?");$q->execute([$last['run_id']]);$record=$q->fetch();if(!$record)return null;
    $source=conversationFlowSource($db,$record['id']);if(!$source)return null;
    $age=$now-strtotime($source['at']);if($age<0||$age>CONVERSATION_FLOW_SECONDS)return null;
    $root=$source['id'];$segment=2;
    if($record['value']!==null){
        if(strlen($record['value'])>2048)return null;
        $receipt=json_decode($record['value'],true,8);
        if(!is_array($receipt)||($receipt['version']??null)!==1||($receipt['conversation_id']??null)!==$source['id']||!is_int($receipt['segment']??null)||$receipt['segment']<2||$receipt['segment']>=CONVERSATION_FLOW_SEGMENTS)return null;
        $root=$receipt['root_id']??'';$segment=$receipt['segment']+1;
        // A third segment must retain the same still-public root and actual pair.
        if(!is_string($root))return null;
        $origin=conversationFlowSource($db,$root);if(!$origin)return null;
        $originIds=array_values(array_unique(array_column($origin['messages'],'resident_id')));sort($originIds);
        $sourceIds=array_values(array_unique(array_column($source['messages'],'resident_id')));sort($sourceIds);
        if($originIds!==$sourceIds)return null;
    }
    $ids=array_values(array_unique(array_column($source['messages'],'resident_id')));if(count($ids)!==2)return null;
    $expected=[$last['resident_a'],$last['resident_b']];sort($expected);$actual=$ids;sort($actual);if($actual!==$expected)return null;
    if(!conversationCooldownAllows($db,$ids[0],$ids[1],$cfg,$now)||!hasSocialRoom($db,$ids[0],$ids[1]))return null;
    $q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=? AND (resident_a=? OR resident_b=?)');
    foreach($ids as $id){$q->execute([$day,$id,$id]);if((int)$q->fetchColumn()>=$cfg['resident_daily'])return null;}
    // Continue alternation across odd as well as even saved segments.
    $lastSpeaker=$source['messages'][array_key_last($source['messages'])]['resident_id'];
    $ids=$lastSpeaker===$ids[0]?[$ids[1],$ids[0]]:$ids;
    $q=$db->prepare('SELECT id,personality FROM residents WHERE id=?');$pair=[];
    foreach($ids as $id){$q->execute([$id]);if(!$r=$q->fetch())return null;$pair[]=$r;}
    return ['pair'=>$pair,'flow'=>['version'=>1,'source_id'=>$source['id'],'root_id'=>$root,'segment'=>$segment]];
}

/** Validate claim metadata again; accepted lineage is committed with speech. */
function conversationFlowContext(PDO $db,array $flow,array $pair):array
{
    $source=conversationFlowSource($db,$flow['source_id']??'');
    $ids=array_column($pair,'id');sort($ids);$actual=$source?array_values(array_unique(array_column($source['messages'],'resident_id'))):[];sort($actual);
    if(!$source||$ids!==$actual||($flow['version']??null)!==1||!is_int($flow['segment']??null)||$flow['segment']<2||$flow['segment']>CONVERSATION_FLOW_SEGMENTS)throw new AiGuard('apply_rejected');
    $root=is_string($flow['root_id']??null)?conversationFlowSource($db,$flow['root_id']):null;
    $rootIds=$root?array_values(array_unique(array_column($root['messages'],'resident_id'))):[];sort($rootIds);
    if(!$root||$rootIds!==$ids||($flow['segment']===2&&$flow['root_id']!==$flow['source_id']))throw new AiGuard('apply_rejected');
    return $flow+['previous_exchange'=>$source,'guidance'=>'A chance to continue this actual exchange, not a requirement. Respond to the last words without restarting introductions or inventing intervening activity. Anger or disagreement may remain unresolved. If either would rather stop, choose quiet or none; do not manufacture another discussion.'];
}
function saveConversationFlow(PDO $db,array $claim,string $run,string $conversation):void
{
    if(!isset($claim['continuation']))return;
    conversationFlowContext($db,$claim['continuation'],$claim['pair']);
    $receipt=$claim['continuation']+['conversation_id'=>$conversation];
    $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['director_conversation_flow:'.$run,json_encode($receipt,JSON_THROW_ON_ERROR)]);
}
