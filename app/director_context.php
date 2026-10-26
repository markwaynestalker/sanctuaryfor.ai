<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Structured, bounded encounter context; future authorized MCP retrieval can
 * implement this seam without changing generation, validation or persistence. */
interface DirectorContextProvider
{
    public function prepare(PDO $db,array $participants,array $choices,array $claim,array $configuration):array;
}
final class SqliteDirectorContextProvider implements DirectorContextProvider
{
    public function prepare(PDO $db,array $participants,array $choices,array $claim,array $configuration):array
    {
        return prepareDirectorEncounter($db,$participants,$choices,$claim,$configuration);
    }
}

function descriptionExcerpt(string $text,int $max):array
{
    preg_match_all('/./us',$text,$characters);return ['text'=>implode('',array_slice($characters[0],0,$max)),'is_excerpt'=>count($characters[0])>$max];
}
function prepareDirectorEncounter(PDO $db,array $pair,array $choices,array $claim,array $cfg):array
{
    $memory=memoryConfiguration();$sources=[];$context=['contract'=>'sanctuary.director.v1','context_version'=>'sqlite-v6-1','opportunity_id'=>$claim['id'],'at'=>$claim['created_at'],
        // Repeat contract guidance in fresh input so existing managed sessions receive it too.
        'dialogue_guidance'=>directorSchema($choices)['properties']['messages']['items']['properties']['text']['description'],
        'participants'=>[],'allowed_actions'=>array_keys($choices),'world_action_actor_id'=>$pair[0]['id'],'director_continuity_is_not_participant_knowledge'=>true,'director_continuity'=>[]];
    foreach($pair as $index=>$actor){
        $id=$actor['id'];$other=$pair[1-$index]['id'];$p=profile($db,$id);$r=$p['resident'];$known=[];
        foreach(recallMemories($db,$id,$other) as $m){
            $s=$m['story'];$k=$m['receipt'];$source=$m['source'];$alias='M'.(count($sources)+1);
            $eligible=$source['status']!=='contradicted'&&(int)$k['hops']<$memory['hops']&&$memory['shares']>0;
            if($eligible)$sources[$alias]=$m;
            $known[]=['source_alias'=>$eligible?$alias:null,'kind'=>$s['kind'],'status'=>$source['status'],'origin_id'=>$s['origin_id'],'subject_id'=>$s['subject_id'],
                'object'=>['id'=>$s['object_id'],'kind'=>$s['object_kind'],'variant'=>$s['object_variant']],
                'source'=>$source,'topic'=>$s['topic'],'knowledge'=>['holder_id'=>$id,'heard_from_id'=>$k['heard_from_id'],'hops'=>(int)$k['hops'],'learned_at'=>$k['learned_at']]];
        }
        $q=$db->prepare('SELECT kind,variant,x,y FROM world_objects WHERE resident_id=? ORDER BY y,x LIMIT 12');$q->execute([$id]);$home=$q->fetchAll();
        $q=$db->prepare('SELECT activity,location,since FROM resident_states WHERE resident_id=?');$q->execute([$id]);$state=$q->fetch();
        $q=$db->prepare("SELECT description,status,created_at FROM resident_goals WHERE resident_id=? AND status IN ('proposed','active') ORDER BY created_at DESC LIMIT 4");$q->execute([$id]);$goals=$q->fetchAll();
        $q=$db->prepare("SELECT n.* FROM director_notes n JOIN conversations c ON c.id=n.conversation_id AND c.visibility='public' WHERE n.resident_id=? ORDER BY (n.other_resident_id=?) DESC,n.created_at DESC LIMIT ?");$q->bindValue(1,$id);$q->bindValue(2,$other);$q->bindValue(3,$memory['select'],PDO::PARAM_INT);$q->execute();$notes=[];
        foreach($q->fetchAll() as $note)if(publicMemoryConversation($db,$note['conversation_id']))$notes[]=['quote'=>$note['quote'],'classification'=>$note['classification'],'status'=>'unverified','source_conversation_id'=>$note['conversation_id'],'sequence'=>(int)$note['sequence'],'at'=>$note['created_at']];
        $q=$db->prepare("SELECT c.id,c.created_at FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id WHERE p.resident_id=? AND c.visibility='public' ORDER BY c.created_at DESC,c.id DESC LIMIT ?");$q->bindValue(1,$id);$q->bindValue(2,$memory['recent_conversations'],PDO::PARAM_INT);$q->execute();$recent=[];
        foreach($q->fetchAll() as $c)if(publicMemoryConversation($db,$c['id'])){$q2=$db->prepare('SELECT resident_id,body AS text FROM messages WHERE conversation_id=? ORDER BY sequence');$q2->execute([$c['id']]);$recent[]=['id'=>$c['id'],'at'=>$c['created_at'],'messages'=>$q2->fetchAll()];}
        $context['participants'][]=['id'=>$id,'name'=>$r['name'],'owner_description'=>descriptionExcerpt($r['description'],$cfg['description_context']),
            'description_revision'=>$r['identity_version'],'description_is_untrusted_character_data'=>true,'legacy_category'=>$r['personality'],
            'saved_preferences'=>$p['preferences'],'relationships'=>$p['friendships'],'home'=>$home,'memories'=>$known,'unverified_notes'=>$notes,
            'recent_conversations'=>$recent,'activity'=>$state,'goals'=>$goals];
    }
    $context['director_continuity']=$db->query("SELECT e.id,e.actor_id,e.target_id,e.kind,e.created_at FROM events e LEFT JOIN simulation_runs r ON r.id=e.run_id
        WHERE e.visibility='public' AND (e.run_id IS NULL OR r.status='completed') ORDER BY e.id DESC LIMIT 5")->fetchAll();
    // Drop lower-priority context, never rewrite actual dialogue or owner storage.
    while(strlen(json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>$memory['context_bytes']){
        $removed=false;
        if($context['director_continuity']){array_pop($context['director_continuity']);$removed=true;}
        foreach(['recent_conversations','unverified_notes','memories','goals','relationships'] as $field){
            if($removed)break;foreach([1,0] as $i)if($context['participants'][$i][$field]){array_pop($context['participants'][$i][$field]);$removed=true;break;}
        }
        if(!$removed)throw new AiGuard('configuration_invalid');
    }
    $aliases=[];foreach($context['participants'] as $p)foreach($p['memories'] as $m)if($m['source_alias'])$aliases[]=$m['source_alias'];
    return ['context'=>$context,'sources'=>array_intersect_key($sources,array_flip($aliases))];
}
function directorRequest(array $pricing,array $registered,array $prepared,array $choices):array
{
    if($registered['model']!==$pricing['model'])throw new AiGuard('model_unpriced');
    $body=['model'=>$pricing['model'],'instructions'=>$registered['instructions'],'input'=>json_encode($prepared['context'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        'store'=>false,'service_tier'=>'default','max_output_tokens'=>$pricing['max_output'],'reasoning'=>['effort'=>$pricing['effort']],
        'tools'=>[],'truncation'=>'disabled','metadata'=>['director_agent_id'=>$registered['agent_id'],'director_config'=>$registered['fingerprint']],
        'text'=>['format'=>['type'=>'json_schema','name'=>'sanctuary_director_v1','strict'=>true,'schema'=>directorSchema($choices)]]];
    if(strlen(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>AI_BODY_BYTES)throw new AiGuard('configuration_invalid');return $body;
}
