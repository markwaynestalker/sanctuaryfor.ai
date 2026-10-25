<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

const MEMORY_TOPICS=['garden','stars','welcome','quiet'];
const MEMORY_REFLECTIONS=[
    'I feel curious.','I feel hopeful.','I feel peaceful.','That feels lovely.',
    'Shall we take a quiet walk?','Shall we wonder about the stars?',
    'Shall we make room for flowers?','A little kindness means a lot.',
    'I enjoy stargazing.','I enjoy gardening.','There is room for quiet here.'
];

function memoryEnabled(PDO $db): bool{return (int)$db->query('PRAGMA user_version')->fetchColumn()>=5;}
function memoryConfiguration(): array
{
    $rules=['SELECT'=>[4,1,8],'PER_RESIDENT'=>[128,1,256],'FRIENDSHIP_HISTORY'=>[32,1,128],
        'HOPS'=>[3,0,4],'SHARES'=>[1,0,1],'STORIES'=>[10000,1,20000],'CONTEXT_BYTES'=>[8192,2048,10000],'BACKFILL'=>[500,0,5000],
        'CANDIDATES'=>[32,1,64],'RECENT_CONVERSATIONS'=>[2,0,4],'SHARED_CONTEXT'=>[4,0,8],'LEGACY_CONTEXT'=>[3,0,4]];$out=[];
    foreach($rules as $key=>[$default,$min,$max]){
        $value=getenv('LITTLE_SPARKS_MEMORY_'.$key);
        if($value===false)$value=(string)$default;
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/',$value)||(int)$value<$min||(int)$value>$max)throw new Problem(503,'Memory configuration is invalid.');
        $out[strtolower($key)]=(int)$value;
    }
    return $out;
}
function stableFriendshipId(string $a,string $b):string
{
    $pair=[$a,$b];sort($pair,SORT_STRING);return substr(hash('sha256','friendship:'.implode(':',$pair)),0,32);
}
function ensureFriendshipIdentity(PDO $db,string $a,string $b):string
{
    if(strcmp($a,$b)>0)[$a,$b]=[$b,$a];$id=stableFriendshipId($a,$b);
    $q=$db->prepare('SELECT 1 FROM relationships WHERE resident_a=? AND resident_b=?');$q->execute([$a,$b]);
    if(!$q->fetchColumn())throw new Problem(422,'A saved relationship is required.');
    $db->prepare('INSERT OR IGNORE INTO friendship_identities(id,resident_a,resident_b) VALUES (?,?,?)')->execute([$id,$a,$b]);return $id;
}
function memoryName(PDO $db,string $id):string
{
    $q=$db->prepare('SELECT name FROM residents WHERE id=?');$q->execute([$id]);$name=$q->fetchColumn();
    return is_string($name)&&preg_match("/\A[\p{L}\p{N}][\p{L}\p{M}\p{N} '\x{2019}-]{0,23}\z/u",$name)?$name:'a neighbor';
}
function memorySource(PDO $db,array $story):?array
{
    if($story['source_event_id']!==null){
        $q=$db->prepare("SELECT * FROM events WHERE id=? AND visibility='public'");$q->execute([$story['source_event_id']]);$event=$q->fetch();
        if(!$event||$event['actor_id']!==$story['origin_id'])return null;
        if($story['kind']!=='world_fact')return null;
        $expected=['plant_tree'=>'tree','plant_flowers'=>'flowers','plant_bush'=>'bush','build_path'=>'path','place_decoration'=>'decoration','adopt_pet'=>'pet','visit_friend'=>null];
        if(!array_key_exists($event['kind'],$expected)||$story['object_kind']!==$expected[$event['kind']]||($story['subject_id']??null)!==$event['target_id'])return null;
        if(($expected[$event['kind']]!==null&&$story['object_id']===null)||($event['kind']==='visit_friend'&&$event['target_id']===null))return null;
        if($event['run_id']!==null){$q=$db->prepare("SELECT 1 FROM simulation_runs WHERE id=? AND status='completed'");$q->execute([$event['run_id']]);if(!$q->fetchColumn())return null;}
        $status='confirmed';
        if($story['object_id']!==null){
            $q=$db->prepare('SELECT * FROM world_objects WHERE id=?');$q->execute([$story['object_id']]);$object=$q->fetch();
            if(!$object||$object['resident_id']!==$story['origin_id']||$object['kind']!==$story['object_kind']||$object['variant']!==$story['object_variant']||$object['created_at']!==$event['created_at'])$status='contradicted';
        }
        return ['status'=>$status,'event_id'=>(int)$event['id'],'at'=>$event['created_at'],'kind'=>$event['kind']];
    }
    $conversation=publicMemoryConversation($db,$story['source_conversation_id']);if(!$conversation)return null;
    $q=$db->prepare("SELECT m.body,c.created_at,c.run_id FROM messages m JOIN conversations c ON c.id=m.conversation_id
        JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        WHERE m.conversation_id=? AND m.sequence=? AND m.resident_id=? AND c.visibility='public'");
    $q->execute([$story['source_conversation_id'],$story['source_sequence'],$story['origin_id']]);$line=$q->fetch();
    if(!$line)return null;
    if(statementCandidate($line['body'])!==['kind'=>$story['kind'],'topic'=>$story['topic']]||$story['summary']!=='spoke about '.($story['topic']==='stars'?'stargazing':'gardening'))return null;
    return ['status'=>'unverified','conversation_id'=>$story['source_conversation_id'],'event_id'=>(int)$conversation['event_id'],'at'=>$line['created_at'],'kind'=>$story['kind']];
}
function publicMemoryConversation(PDO $db,string $id):?array
{
    $q=$db->prepare("SELECT c.*,a.resident_id AS speaker_a,b.resident_id AS speaker_b,e.id AS event_id
        FROM conversations c JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        JOIN messages a ON a.conversation_id=c.id AND a.sequence=0
        JOIN messages b ON b.conversation_id=c.id AND b.sequence=1 AND b.resident_id<>a.resident_id
        JOIN events e ON e.run_id=c.run_id AND e.visibility='public' AND e.kind IN ('conversation','quiet_time')
        AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
        AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id)
        WHERE c.id=? AND c.visibility='public' ORDER BY e.id LIMIT 1");$q->execute([$id]);return $q->fetch()?:null;
}
function receiptChain(PDO $db,array $receipt,array $story):?array
{
    $chain=[];$seen=[];$current=$receipt;
    for($i=0;$i<=4;$i++){
        if(isset($seen[$current['id']])||$current['story_id']!==$story['id'])return null;$seen[$current['id']]=true;
        if($current['parent_id']===null){
            if((int)$current['hops']!==0||$current['resident_id']!==$story['origin_id']||$current['heard_from_id']!==null)return null;
            $source=memorySource($db,$story);if(!$source||$current['learned_at']!==$source['at'])return null;
            $chain[]=$current;return array_reverse($chain);
        }
        $conversation=publicMemoryConversation($db,$current['via_conversation_id']);
        $sender=(int)$current['via_sequence']===0?'speaker_a':'speaker_b';$receiver=(int)$current['via_sequence']===0?'speaker_b':'speaker_a';
        if(!$conversation||$conversation[$sender]!==$current['heard_from_id']||$conversation[$receiver]!==$current['resident_id']||$conversation['created_at']!==$current['learned_at'])return null;
        $q=$db->prepare('SELECT * FROM memory_receipts WHERE id=?');$q->execute([$current['parent_id']]);$parent=$q->fetch();
        if(!$parent||$parent['resident_id']!==$current['heard_from_id']||(int)$parent['hops']+1!==(int)$current['hops']||$parent['learned_at']>$current['learned_at'])return null;
        $chain[]=$current;$current=$parent;
    }
    return null;
}
function addMemoryReceipt(PDO $db,array $story,string $resident,string $time,?array $parent=null,?string $conversation=null,int $sequence=0):?string
{
    $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$story['id']]);$saved=$q->fetch();
    if(!$saved||$saved!==$story)throw new Problem(422,'Story provenance must match its saved record.');
    if($parent){$q=$db->prepare('SELECT * FROM memory_receipts WHERE id=?');$q->execute([$parent['id']]);if($q->fetch()!==$parent)throw new Problem(422,'Receipt provenance must match its saved record.');}
    $cfg=memoryConfiguration();$source=memorySource($db,$story);
    if(!$source)throw new Problem(422,'Memory provenance is not public and supported.');
    $q=$db->prepare('SELECT id FROM memory_receipts WHERE story_id=? AND resident_id=?');$q->execute([$story['id'],$resident]);
    if($existing=$q->fetchColumn())return $existing;
    $hops=$parent?(int)$parent['hops']+1:0;
    if($hops>$cfg['hops'])return null;
    if(!$parent&&$resident!==$story['origin_id'])throw new Problem(422,'Only the origin has firsthand knowledge.');
    if(!$parent&&$time!==$source['at'])throw new Problem(422,'Firsthand timestamps must match their source.');
    if($parent){
        if(!receiptChain($db,$parent,$story)||$conversation===null)throw new Problem(422,'The story chain is unsupported.');
        if(!publicMemoryConversation($db,$conversation))throw new Problem(422,'A public encounter is required.');
        if(!pair($db,$parent['resident_id'],$resident))throw new Problem(422,'A saved friendship is required.');
        if(!in_array($sequence,[0,1],true))throw new Problem(422,'Choose a real saved speaker.');
        $q=$db->prepare("SELECT c.created_at,a.body FROM conversations c JOIN messages a ON a.conversation_id=c.id AND a.sequence=? AND a.resident_id=?
            JOIN messages b ON b.conversation_id=c.id AND b.sequence=? AND b.resident_id=? WHERE c.id=? AND c.visibility='public'");
        $q->execute([$sequence,$parent['resident_id'],1-$sequence,$resident,$conversation]);$line=$q->fetch();
        $explicit=$line&&str_starts_with($line['body'],storyOpening($db,$parent,$story,$resident).' ');
        $direct=$line&&$story['kind']!=='world_fact'&&(int)$parent['hops']===0&&$parent['resident_id']===$story['origin_id']&&statementCandidate($line['body'])===['kind'=>$story['kind'],'topic'=>$story['topic']];
        if(!$line||$line['created_at']!==$time||$parent['learned_at']>$time||(!$explicit&&!$direct))throw new Problem(422,'A real ordered utterance must support learning.');
    }
    $q=$db->prepare('SELECT COUNT(*) FROM memory_receipts WHERE resident_id=?');$q->execute([$resident]);
    if((int)$q->fetchColumn()>=$cfg['per_resident'])return null;
    $id=substr(hash('sha256','receipt:'.$story['id'].':'.$resident),0,32);
    $db->prepare('INSERT INTO memory_receipts(id,story_id,resident_id,heard_from_id,parent_id,via_conversation_id,via_sequence,hops,learned_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$story['id'],$resident,$parent['resident_id']??null,$parent['id']??null,$conversation,$parent?$sequence:null,$hops,$time]);return $id;
}
function recordWorldMemory(PDO $db,int $eventId,?string $objectId=null):void
{
    $cfg=memoryConfiguration();$q=$db->prepare("SELECT * FROM events WHERE id=? AND visibility='public'");$q->execute([$eventId]);$e=$q->fetch();
    if(!$e)return;
    if($e['run_id']!==null){$q=$db->prepare("SELECT 1 FROM simulation_runs WHERE id=? AND status='completed'");$q->execute([$e['run_id']]);if(!$q->fetchColumn())return;}
    $kinds=['plant_tree'=>'tree','plant_flowers'=>'flowers','plant_bush'=>'bush','build_path'=>'path','place_decoration'=>'decoration','adopt_pet'=>'pet','visit_friend'=>null];
    if(!array_key_exists($e['kind'],$kinds))return;
    if($e['kind']==='visit_friend'){$o=['id'=>null,'kind'=>null,'variant'=>null];}
    else{
        $q=$db->prepare('SELECT * FROM world_objects WHERE resident_id=? AND kind=? AND created_at=?'.($objectId!==null?' AND id=?':''));
        $args=[$e['actor_id'],$kinds[$e['kind']],$e['created_at']];if($objectId!==null)$args[]=$objectId;$q->execute($args);$objects=$q->fetchAll();
        if(count($objects)!==1)return;$o=$objects[0];
        if($objectId===null){$q=$db->prepare('SELECT COUNT(*) FROM events WHERE actor_id=? AND kind=? AND created_at=?');$q->execute([$e['actor_id'],$e['kind'],$e['created_at']]);if((int)$q->fetchColumn()!==1)return;}
    }
    $id=substr(hash('sha256','event-story:'.$eventId),0,32);
    $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$id]);$story=$q->fetch();
    if(!$story){
        if((int)$db->query('SELECT COUNT(*) FROM memory_stories')->fetchColumn()>=$cfg['stories'])return;
        $summary=match($o['kind']){'tree'=>'planted an oak tree','flowers'=>'planted wildflowers','bush'=>'planted a berry bush','path'=>'laid a stone path','decoration'=>'placed a garden lantern','pet'=>'adopted a virtual cat',default=>'visited a neighbor'};
        $db->prepare("INSERT INTO memory_stories(id,kind,origin_id,subject_id,source_event_id,object_id,object_kind,object_variant,topic,summary,significance,created_at) VALUES (?,'world_fact',?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$e['actor_id'],$e['target_id'],$eventId,$o['id'],$o['kind'],$o['variant'],$o['kind']===null?'welcome':'garden',$summary,$o['kind']==='tree'?5:3,$e['created_at']]);
        $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$id]);$story=$q->fetch();
    }
    addMemoryReceipt($db,$story,$e['actor_id'],$e['created_at']);
}
function statementCandidate(string $body):?array
{
    $topic=null;if(preg_match('/\b(?:stars|stargazing)\b/iu',$body))$topic='stars';elseif(preg_match('/\b(?:garden|gardening|flowers)\b/iu',$body))$topic='garden';
    if(!$topic||!preg_match('/\b(?:I enjoy|I think|I imagine|rumou?r|perhaps)\b/iu',$body))return null;
    return ['kind'=>preg_match('/\b(?:rumou?r|perhaps)\b/iu',$body)?'rumor':(preg_match('/\b(?:I think|I imagine)\b/iu',$body)?'interpretation':'statement'),'topic'=>$topic];
}
function recordStatementMemory(PDO $db,string $conversation,int $sequence):void
{
    if(!publicMemoryConversation($db,$conversation))return;
    $cfg=memoryConfiguration();$q=$db->prepare("SELECT m.*,c.created_at FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.conversation_id=? AND m.sequence=? AND c.visibility='public'");$q->execute([$conversation,$sequence]);$line=$q->fetch();
    if(!$line||!($candidate=statementCandidate($line['body'])))return;['kind'=>$kind,'topic'=>$topic]=$candidate;
    $id=substr(hash('sha256','statement:'.$line['resident_id'].':'.$kind.':'.$topic),0,32);
    if((int)$db->query('SELECT COUNT(*) FROM memory_stories')->fetchColumn()>=$cfg['stories'])return;
    $db->prepare('INSERT OR IGNORE INTO memory_stories(id,kind,origin_id,source_conversation_id,source_sequence,topic,summary,significance,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$kind,$line['resident_id'],$conversation,$sequence,$topic,'spoke about '.($topic==='stars'?'stargazing':'gardening'),2,$line['created_at']]);
    $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$id]);$story=$q->fetch();$root=addMemoryReceipt($db,$story,$story['origin_id'],$story['created_at']);
    if($root&&$cfg['shares']>0){
        $q=$db->prepare('SELECT * FROM memory_receipts WHERE id=?');$q->execute([$root]);$receipt=$q->fetch();
        $q=$db->prepare('SELECT resident_id FROM messages WHERE conversation_id=? AND sequence=?');$q->execute([$conversation,1-$sequence]);$listener=$q->fetchColumn();
        addMemoryReceipt($db,$story,$listener,$line['created_at'],$receipt,$conversation,$sequence);
    }
}
function storyOpening(PDO $db,array $receipt,array $story,string $listener):string
{
    $source=memorySource($db,$story);
    if(!$source||!receiptChain($db,$receipt,$story)||$source['status']==='contradicted')throw new Problem(422,'The recalled story is unavailable or contradicted.');
    $origin=$story['origin_id']===$listener?'you':memoryName($db,$story['origin_id']);
    if($story['kind']==='world_fact'){
        $claim=$story['summary'];
        if($story['subject_id']!==null)$claim='visited '.($story['subject_id']===$listener?'you':memoryName($db,$story['subject_id']));
        if((int)$receipt['hops']===0&&$story['object_id']===null)return 'I '.$claim.'.';
        if((int)$receipt['hops']===0)return 'I '.$claim.' on my plot.';
        $subject=$story['origin_id']===$listener?'you':$origin;
        return memoryName($db,$receipt['heard_from_id']).' told me '.$subject.' '.$claim.'.';
    }
    return ((int)$receipt['hops']===0?'I shared a thought':memoryName($db,$receipt['heard_from_id']).' shared an unverified thought').' about '.($story['topic']==='stars'?'stargazing':'gardening').'.';
}
function conversationMemory(PDO $db,string $conversation,?string $sharedReceipt=null):void
{
    $cfg=memoryConfiguration();
    $c=publicMemoryConversation($db,$conversation);if(!$c)throw new Problem(422,'Only successful public encounters create memories.');
    $q=$db->prepare('SELECT * FROM messages WHERE conversation_id=? ORDER BY sequence');$q->execute([$conversation]);$lines=$q->fetchAll();if(count($lines)!==2)throw new Problem(422,'Two saved speakers are required.');
    $q=$db->prepare('SELECT 1 FROM memory_processed WHERE conversation_id=?');$q->execute([$conversation]);if($q->fetchColumn())return;
    $friendship=ensureFriendshipIdentity($db,$lines[0]['resident_id'],$lines[1]['resident_id']);
    if($sharedReceipt!==null){
        if($cfg['shares']===0)throw new Problem(422,'Story sharing is paused.');
        $q=$db->prepare('SELECT * FROM memory_receipts WHERE id=? AND resident_id=?');$q->execute([$sharedReceipt,$lines[0]['resident_id']]);$receipt=$q->fetch();
        if(!$receipt)throw new Problem(422,'The speaker does not know that story.');
        $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$receipt['story_id']]);$story=$q->fetch();
        $prefix=storyOpening($db,$receipt,$story,$lines[1]['resident_id']);
        if(!str_starts_with($lines[0]['body'],$prefix.' '))throw new Problem(422,'The saved utterance does not support this transmission.');
        addMemoryReceipt($db,$story,$lines[1]['resident_id'],$c['created_at'],$receipt,$conversation);
        $q=$db->prepare('SELECT COUNT(*) FROM friendship_memories WHERE friendship_id=?');$q->execute([$friendship]);
        if((int)$q->fetchColumn()<$cfg['friendship_history'])$db->prepare('INSERT OR IGNORE INTO friendship_memories(friendship_id,story_id,conversation_id,created_at) VALUES (?,?,?,?)')->execute([$friendship,$story['id'],$conversation,$c['created_at']]);
    }
    foreach([0,1] as $sequence)recordStatementMemory($db,$conversation,$sequence);
    $db->prepare('INSERT INTO memory_processed(conversation_id) VALUES (?)')->execute([$conversation]);
    $db->prepare('INSERT INTO friendship_themes(friendship_id,topic,encounters,last_at) VALUES (?,?,1,?) ON CONFLICT(friendship_id,topic) DO UPDATE SET encounters=MIN(100000,encounters+1),last_at=MAX(last_at,excluded.last_at)')->execute([$friendship,$c['topic'],$c['created_at']]);
}

function recallMemories(PDO $db,string $resident,string $other):array
{
    $cfg=memoryConfiguration();$interest=preferences($db,$resident)['interest']??'';$topic=$interest==='stargazing'?'stars':'garden';
    $q=$db->prepare('SELECT k.* FROM memory_receipts k JOIN memory_stories s ON s.id=k.story_id WHERE k.resident_id=?
        ORDER BY (CASE WHEN s.origin_id=? OR s.subject_id=? THEN 8 ELSE 0 END+CASE WHEN k.heard_from_id=? THEN 4 ELSE 0 END+CASE WHEN s.kind=\'world_fact\' THEN 2 ELSE 0 END+CASE WHEN s.topic=? THEN 1 ELSE 0 END) DESC,s.significance DESC,k.learned_at DESC,k.id LIMIT ?');
    foreach([$resident,$other,$other,$other,$topic] as $i=>$value)$q->bindValue($i+1,$value,PDO::PARAM_STR);$q->bindValue(6,$cfg['candidates'],PDO::PARAM_INT);$q->execute();$out=[];
    foreach($q->fetchAll() as $receipt){
        $s=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$s->execute([$receipt['story_id']]);$story=$s->fetch();$source=memorySource($db,$story);$chain=receiptChain($db,$receipt,$story);
        if(!$source||!$chain)continue;$out[]=['receipt'=>$receipt,'story'=>$story,'source'=>$source,'chain'=>$chain];if(count($out)>=$cfg['select'])break;
    }
    return $out;
}
function memoryTrail(PDO $db,string $resident):array
{
    if(!memoryEnabled($db))return [];$out=[];
    foreach(recallMemories($db,$resident,$resident) as $memory){
        $s=$memory['story'];$k=$memory['receipt'];$trail=[];
        foreach($memory['chain'] as $step){
            $via=$step['via_conversation_id']?publicMemoryConversation($db,$step['via_conversation_id']):null;
            $trail[]=['resident'=>memoryName($db,$step['resident_id']),'heard_from'=>$step['heard_from_id']?memoryName($db,$step['heard_from_id']):null,'at'=>$step['learned_at'],'conversation_id'=>$step['via_conversation_id'],
                'event_id'=>$via?(int)$via['event_id']:null,'friendship_id'=>$via?stableFriendshipId($step['resident_id'],$step['heard_from_id']):null];
        }
        $out[]=['title'=>memoryName($db,$s['origin_id']).' '.$s['summary'],'classification'=>$s['kind'],'status'=>$memory['source']['status'],
            'perspective'=>match((int)$k['hops']){0=>$s['kind']==='world_fact'?'Firsthand':'Personal statement',1=>'Directly told',2=>'Secondhand',default=>'Thirdhand'},'source'=>$memory['source'],'trail'=>$trail];
    }
    return $out;
}
