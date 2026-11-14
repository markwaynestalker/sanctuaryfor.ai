<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function sparkDialogueMode():string
{
    $mode=getenv('LITTLE_SPARKS_DIALOGUE_MODE');$mode=$mode===false?'director':$mode;
    if(!in_array($mode,['director','independent'],true))throw new AiGuard('configuration_invalid');return $mode;
}

/** An episode is an exact public experience of this holder, not village omniscience. */
function sparkEpisode(PDO $db,string $holder,string $id):?array
{
    $source=publicMemoryConversation($db,$id);
    if(!$source||!in_array($holder,[$source['speaker_a'],$source['speaker_b']],true))return null;
    $q=$db->prepare('SELECT COALESCE(d.topic,c.topic) FROM conversations c LEFT JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id WHERE c.id=?');$q->execute([$id]);
    $messages=conversationMessageBatch($db,[$id])[$id]??[];if(count($messages)<2)return null;
    $names=[];foreach($messages as $m)$names[$m['resident_id']]=$m['name'];
    return ['id'=>$id,'at'=>$source['created_at'],'topic'=>$q->fetchColumn(),'speakers'=>$names,'messages'=>array_map(static fn($m)=>['resident_id'=>$m['resident_id'],'text'=>$m['text']],$messages)];
}

/** Search retained history, not just the newest N encounters. Never accept SQL or a caller-selected holder. */
function sparkRecall(PDO $db,string $holder,string $at,?string $partner=null,string $query='',?int $limit=null):array
{
    $timestamp=strtotime($at);if($timestamp===false)throw new AiGuard('configuration_invalid');$at=gmdate('Y-m-d\TH:i:s\Z',$timestamp);
    $cfg=memoryConfiguration();$bound=$partner!==null&&$query===''?$cfg['recent_conversations']:$cfg['select'];$limit??=$bound;$limit=min($limit,$bound);if($limit<=0)return [];
    if($query!==''){safeAiText($query,120);if(trim($query)!==$query)throw new AiGuard('invalid_proposal');}
    $table=messageRelation($db);$params=[$holder,$at];$where='';
    if($partner!==null){$where.=' AND EXISTS(SELECT 1 FROM conversation_participants b WHERE b.conversation_id=c.id AND b.resident_id=?)';$params[]=$partner;}
    if($query!==''){
        $db->sqliteCreateFunction('spark_recall_match',static fn($text,$needle)=>preg_match('~'.preg_quote((string)$needle,'~').'~iu',(string)$text)===1?1:0,2,PDO::SQLITE_DETERMINISTIC);
        $where.=" AND (spark_recall_match(COALESCE(d.topic,c.topic),?)=1 OR EXISTS(SELECT 1 FROM $table m WHERE m.conversation_id=c.id AND spark_recall_match(m.body,?)=1))";$params[]=$query;$params[]=$query;}
    $q=$db->prepare("SELECT c.id FROM conversations c JOIN conversation_participants p ON p.conversation_id=c.id LEFT JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id WHERE p.resident_id=? AND c.visibility='public' AND c.created_at<=?$where ORDER BY c.created_at DESC,c.id DESC LIMIT ?");
    foreach($params as $i=>$value)$q->bindValue($i+1,$value);$q->bindValue(count($params)+1,$cfg['candidates'],PDO::PARAM_INT);$q->execute();$ids=$q->fetchAll(PDO::FETCH_COLUMN);$out=[];
    foreach($ids as $id)if($episode=sparkEpisode($db,$holder,$id)){$out[]=$episode;if(count($out)>=$limit)break;}
    return $out;
}

/** Quote ownership and original speaker are distinct, even for one's own recollection. */
function sparkQuoteNotes(PDO $db,string $holder,string $other,?string $at=null):array
{
    $cfg=memoryConfiguration();if(!$cfg['select'])return [];$table=noteRelation($db);
    $q=$db->prepare("SELECT n.* FROM $table n WHERE n.resident_id=? ORDER BY (n.other_resident_id=?) DESC,n.created_at DESC,n.id LIMIT ?");$q->bindValue(1,$holder);$q->bindValue(2,$other);$q->bindValue(3,$cfg['candidates'],PDO::PARAM_INT);$q->execute();$out=[];
    foreach($q->fetchAll() as $note){$source=sparkEpisode($db,$holder,$note['conversation_id']);$line=$source['messages'][(int)$note['sequence']]??null;
        if(!$line||!str_contains($line['text'],$note['quote'])||($at!==null&&strtotime($source['at'])>strtotime($at)))continue;
        $out[]=['quote'=>$note['quote'],'speaker_id'=>$line['resident_id'],'holder_id'=>$holder,'classification'=>$note['classification'],'status'=>'unverified','source_conversation_id'=>$note['conversation_id'],'sequence'=>(int)$note['sequence'],'at'=>$note['created_at']];
        if(count($out)>=$cfg['select'])break;
    }return $out;
}

function sparkMemorySources(PDO $db,string $holder,array $context):array
{
    $ids=array_column($context['self']['recent_conversations']??[],'id');
    foreach($context['self']['unverified_notes']??[] as $n)$ids[]=$n['source_conversation_id'];
    foreach($context['shared_recent_conversations']??[] as $c)$ids[]=$c['id'];
    if(isset($context['conversation_flow']))$ids[]=$context['conversation_flow']['source_id'];
    foreach($context['recalled_episodes']??[] as $c)$ids[]=$c['id'];$out=[];
    foreach(array_unique($ids) as $id){$episode=sparkEpisode($db,$holder,$id);if(!$episode)throw new AiGuard('apply_rejected');$out[$id]=hash('sha256',editorialJson($episode));}return $out;
}

/** Atomic, identifiers-only receipt for supplied context; never asserts belief or true claims. */
function saveSparkMemoryContext(PDO $db,string $run,string $conversation,array $pair,array $receipts):void
{
    if(!$receipts)return;$ids=array_column($pair,'id');
    if(array_diff(array_keys($receipts),$ids)||count($receipts)>2)throw new AiGuard('apply_rejected');
    foreach($receipts as $holder=>$sources){
        if(!sparkEpisode($db,$holder,$conversation)||count($sources)>1024)throw new AiGuard('apply_rejected');
        foreach($sources as $id=>$sha){$source=sparkEpisode($db,$holder,$id);if(!$source||hash('sha256',editorialJson($source))!==$sha)throw new AiGuard('apply_rejected');}
        $json=editorialJson(['version'=>1,'resident_id'=>$holder,'conversation_id'=>$conversation,'sources'=>$sources,'influence_verified'=>false]);
        if(strlen($json)>131072)throw new AiGuard('apply_rejected');
        $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['spark_memory_context:'.$run.':'.$holder,$json]);
    }
}
