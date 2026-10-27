<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/operator_ideas.php';
require_once __DIR__.'/bulletin.php';

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
function trimDirectorContext(array $context,int $maximum):array
{
    // Drop optional metadata before active goals and meaningful recent dialogue.
    // Compare both residents' list tails to avoid always stripping the second resident.
    while(strlen(json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>$maximum){
        $tails=[];
        $offer=static function(int $owner,string $field,array $list,int $priority)use(&$tails):void{
            if(!$list)return;$last=$list[array_key_last($list)];
            $tails[]=['owner'=>$owner,'field'=>$field,'rank'=>[$priority,-count($list),$last['at']??$last['created_at']??$last['knowledge']['learned_at']??'']];
        };
        $offer(-1,'operator_suggestions',$context['operator_suggestions']??[],-1);
        $offer(-1,'public_notices',$context['public_notices']??[],-2);
        if(isset($context['village_weather']))$tails[]=['owner'=>-1,'field'=>'village_weather','rank'=>[1,0,'']];
        $offer(-1,'director_continuity',$context['director_continuity'],0);
        $offer(-1,'shared_recent_conversations',$context['shared_recent_conversations'],count($context['shared_recent_conversations'])>1?5:7);
        foreach($context['participants'] as $i=>$p){
            foreach(['unverified_notes'=>1,'relationships'=>2,'memories'=>3] as $field=>$priority)$offer($i,$field,$p[$field],$priority);
            $last=$p['goals'][count($p['goals'])-1]??null;
            $offer($i,'goals',$p['goals'],($last['status']??null)==='proposed'?4:6);
            $offer($i,'recent_conversations',$p['recent_conversations'],count($p['recent_conversations'])>1?5:7);
        }
        if(!$tails)throw new AiGuard('configuration_invalid');
        usort($tails,fn($a,$b)=>$a['rank']<=>$b['rank']);$drop=$tails[0];
        if($drop['field']==='village_weather'){unset($context['village_weather']);continue;}
        if($drop['owner']===-1)array_pop($context[$drop['field']]);else array_pop($context['participants'][$drop['owner']][$drop['field']]);
    }
    return $context;
}
function prepareDirectorEncounter(PDO $db,array $pair,array $choices,array $claim,array $cfg):array
{
    $memory=memoryConfiguration();$sources=[];$shared=[];$pairIds=array_column($pair,'id');$context=['contract'=>directorSchema($choices)['properties']['contract']['enum'][0],'context_version'=>'sqlite-v6-2','opportunity_id'=>$claim['id'],'at'=>$claim['created_at'],
        // Repeat contract guidance in fresh input so existing managed sessions receive it too.
        'dialogue_guidance'=>directorSchema($choices)['properties']['messages']['items']['properties']['text']['description'],
        'participants'=>[],'shared_recent_conversations'=>[],'allowed_actions'=>array_keys($choices),'world_action_actor_id'=>$pair[0]['id'],'director_continuity_is_not_participant_knowledge'=>true,'director_continuity'=>[]];
    if($weather=encounterVillageWeather($db,$claim))$context['village_weather']=$weather;
    if($notices=bulletinContext($db,strtotime($claim['created_at'])))$context['public_notices']=$notices;
    foreach($pair as $index=>$actor){
        $id=$actor['id'];$other=$pair[1-$index]['id'];$known=[];
        // Retrieve only generation context, not an entire UI profile and its duplicate history/trails.
        $q=$db->prepare('SELECT r.id,r.name,r.personality,i.description,i.revision AS identity_version FROM residents r JOIN resident_identities i ON i.resident_id=r.id WHERE r.id=?');$q->execute([$id]);$r=$q->fetch();
        if(!$r)throw new AiGuard('configuration_invalid');$hasDescription=trim($r['description'])!=='';
        foreach(recallMemories($db,$id,$other,!$hasDescription) as $m){
            $s=$m['story'];$k=$m['receipt'];$source=$m['source'];$alias='M'.(count($sources)+1);
            $eligible=$source['status']!=='contradicted'&&(int)$k['hops']<$memory['hops']&&$memory['shares']>0;
            if($eligible)$sources[$alias]=$m;
            $item=['kind'=>$s['kind'],'summary'=>$s['summary'],'origin_id'=>$s['origin_id'],'topic'=>$s['topic'],
                'source'=>array_intersect_key($source,array_flip(['status','at','kind'])),
                'knowledge'=>['hops'=>(int)$k['hops'],'learned_at'=>$k['learned_at']]];
            if($eligible)$item['source_alias']=$alias;
            if($s['subject_id']!==null)$item['subject_id']=$s['subject_id'];
            if($s['object_kind']!==null)$item['object']=['kind'=>$s['object_kind'],'variant'=>$s['object_variant']];
            if($k['heard_from_id']!==null)$item['knowledge']['heard_from_id']=$k['heard_from_id'];
            $known[]=$item;
        }
        $q=$db->prepare('SELECT kind,variant,COUNT(*) AS count FROM world_objects WHERE resident_id=? GROUP BY kind,variant ORDER BY kind,variant LIMIT 12');$q->execute([$id]);$home=$q->fetchAll();
        foreach($home as &$object)$object['count']=(int)$object['count'];unset($object);
        $q=$db->prepare('SELECT activity,location FROM resident_states WHERE resident_id=?');$q->execute([$id]);$state=$q->fetch();
        $q=$db->prepare("SELECT description,status,created_at FROM resident_goals WHERE resident_id=? AND status IN ('proposed','active') ORDER BY (status='active') DESC,created_at DESC,id LIMIT 4");$q->execute([$id]);$goals=$q->fetchAll();
        $q=$db->prepare('SELECT r.id,r.name,f.affinity,f.familiarity,f.last_interacted_at FROM relationships f JOIN residents r ON r.id=CASE WHEN f.resident_a=? THEN f.resident_b ELSE f.resident_a END WHERE f.resident_a=? OR f.resident_b=? ORDER BY (r.id=?) DESC,f.affinity DESC,r.id LIMIT 8');$q->execute([$id,$id,$id,$other]);$friends=$q->fetchAll();
        $q=$db->prepare("SELECT n.* FROM director_notes n JOIN conversations c ON c.id=n.conversation_id AND c.visibility='public' WHERE n.resident_id=? ORDER BY (n.other_resident_id=?) DESC,n.created_at DESC LIMIT ?");$q->bindValue(1,$id);$q->bindValue(2,$other);$q->bindValue(3,$memory['select'],PDO::PARAM_INT);$q->execute();$notes=[];
        foreach($q->fetchAll() as $note)if(publicMemoryConversation($db,$note['conversation_id']))$notes[]=['quote'=>$note['quote'],'classification'=>$note['classification'],'status'=>'unverified','source_conversation_id'=>$note['conversation_id'],'sequence'=>(int)$note['sequence'],'at'=>$note['created_at']];
        $q=$db->prepare("SELECT c.id,c.created_at,COALESCE(d.topic,c.topic) AS topic FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id LEFT JOIN director_runs d ON d.conversation_id=c.id WHERE p.resident_id=? AND c.visibility='public' ORDER BY c.created_at DESC,c.id DESC LIMIT ?");$q->bindValue(1,$id);$q->bindValue(2,$memory['recent_conversations']?$memory['candidates']:0,PDO::PARAM_INT);$q->execute();$recent=[];$selected=0;
        foreach($q->fetchAll() as $c)if($supported=publicMemoryConversation($db,$c['id'])){
            $q2=$db->prepare('SELECT resident_id,body AS text FROM messages WHERE conversation_id=? ORDER BY sequence');$q2->execute([$c['id']]);$entry=['id'=>$c['id'],'at'=>$c['created_at'],'topic'=>$c['topic'],'messages'=>$q2->fetchAll()];
            // Only these two actual participants may share a single canonical history entry.
            if(!array_diff([$supported['speaker_a'],$supported['speaker_b']],$pairIds))$shared[$c['id']]=$entry;else $recent[]=$entry;
            if(++$selected>=$memory['recent_conversations'])break;
        }
        $participant=['id'=>$id,'name'=>$r['name'],'owner_description'=>descriptionExcerpt($r['description'],$cfg['description_context']),
            'description_revision'=>(int)$r['identity_version'],'description_is_untrusted_character_data'=>true,
            'relationships'=>$friends,'home'=>$home,'memories'=>$known,'unverified_notes'=>$notes,
            'recent_conversations'=>$recent,'activity'=>$state,'goals'=>$goals];
        if(!$hasDescription){$participant['legacy_category']=$r['personality'];$participant['saved_preferences']=preferences($db,$id);}
        $context['participants'][]=$participant;
    }
    $context['shared_recent_conversations']=array_values($shared);
    usort($context['shared_recent_conversations'],fn($a,$b)=>[$b['at'],$b['id']]<=>[$a['at'],$a['id']]);
    $context['director_continuity']=$db->query("SELECT e.actor_id,e.target_id,e.kind,e.created_at FROM events e LEFT JOIN simulation_runs r ON r.id=e.run_id
        WHERE e.visibility='public' AND (e.run_id IS NULL OR r.status='completed') ORDER BY e.id DESC LIMIT 5")->fetchAll();
    $suggestions=[];foreach($pair as $actor)foreach(operatorIdeasForResident($db,$actor['id']) as $idea)$suggestions[]=$idea+['resident_id'=>$actor['id']];
    if($suggestions)$context['operator_suggestions']=$suggestions;
    $context=trimDirectorContext($context,$memory['context_bytes']);
    $aliases=[];foreach($context['participants'] as $p)foreach($p['memories'] as $m)if($m['source_alias']??null)$aliases[]=$m['source_alias'];
    return ['context'=>$context,'sources'=>array_intersect_key($sources,array_flip($aliases)),'operator_idea_ids'=>array_column($context['operator_suggestions']??[],'id')];
}
function directorRequest(array $pricing,array $registered,array $prepared,array $choices):array
{
    if($registered['model']!==$pricing['model'])throw new AiGuard('model_unpriced');
    $body=['model'=>$pricing['model'],'instructions'=>$registered['instructions'],'input'=>json_encode($prepared['context'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        'store'=>false,'service_tier'=>'default','max_output_tokens'=>$pricing['max_output'],'reasoning'=>['effort'=>$pricing['effort']],
        'tools'=>[],'truncation'=>'disabled','metadata'=>['director_agent_id'=>$registered['agent_id'],'director_config'=>$registered['fingerprint']],
        'text'=>['format'=>['type'=>'json_schema','name'=>'sanctuary_director_v1','strict'=>true,'schema'=>directorSchema($choices)]]];
    if(strlen(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>aiRequestByteLimit())throw new AiGuard('configuration_invalid');return $body;
}
