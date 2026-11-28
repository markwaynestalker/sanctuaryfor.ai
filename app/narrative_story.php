<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Optional additive ledger; individual conversations remain immutable. */
function narrativeStoryInstalled(PDO $db):bool
{
    $q=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='narrative_story_meta'");
    if(!$q->fetchColumn())return false;
    if((int)$db->query('SELECT revision FROM narrative_story_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported narrative story revision.');
    $db->query('SELECT id,created_at FROM narrative_stories LIMIT 0');$db->query('SELECT story_id,sequence,conversation_id,action,created_at FROM narrative_story_exchanges LIMIT 0');
    return true;
}
function migrateNarrativeStories(PDO $db):void
{
    if(!directorEnabled($db))throw new \RuntimeException('V6 required.');
    assertIdentityIdle($db);if(narrativeStoryInstalled($db))return;
    foreach(['narrative_story_meta','narrative_stories','narrative_story_exchanges'] as $table){$q=$db->prepare('SELECT 1 FROM sqlite_master WHERE name=?');$q->execute([$table]);if($q->fetchColumn())throw new \RuntimeException('Partial narrative schema.');}
    $db->exec("CREATE TABLE narrative_story_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));INSERT INTO narrative_story_meta VALUES(1,1);
        CREATE TABLE narrative_stories(id TEXT PRIMARY KEY CHECK(length(id)=32),created_at TEXT NOT NULL);
        CREATE TABLE narrative_story_exchanges(story_id TEXT NOT NULL REFERENCES narrative_stories(id) ON DELETE RESTRICT,
            sequence INTEGER NOT NULL CHECK(sequence>=1),conversation_id TEXT NOT NULL UNIQUE REFERENCES conversations(id) ON DELETE RESTRICT,
            action TEXT NOT NULL CHECK(action IN ('start','continue','conclude','revisit')),created_at TEXT NOT NULL,
            PRIMARY KEY(story_id,sequence));
        CREATE INDEX narrative_story_by_conversation ON narrative_story_exchanges(conversation_id);
        CREATE INDEX narrative_story_recent ON narrative_story_exchanges(created_at DESC,sequence DESC,story_id);
        CREATE TRIGGER narrative_story_append BEFORE INSERT ON narrative_story_exchanges WHEN NEW.sequence<>(SELECT COALESCE(MAX(sequence),0)+1 FROM narrative_story_exchanges WHERE story_id=NEW.story_id) OR (NEW.sequence=1 AND NEW.action<>'start') OR (NEW.sequence>1 AND NEW.action='start') OR (NEW.sequence>1 AND ((SELECT action FROM narrative_story_exchanges WHERE story_id=NEW.story_id ORDER BY sequence DESC LIMIT 1)='conclude')<>(NEW.action='revisit')) OR (NEW.sequence>1 AND NEW.created_at<(SELECT created_at FROM narrative_story_exchanges WHERE story_id=NEW.story_id ORDER BY sequence DESC LIMIT 1)) BEGIN SELECT RAISE(ABORT,'invalid story order'); END;");
    foreach(['narrative_story_meta','narrative_stories','narrative_story_exchanges'] as $table)foreach(['UPDATE','DELETE'] as $op)$db->exec("CREATE TRIGGER {$table}_no_{$op} BEFORE $op ON $table BEGIN SELECT RAISE(ABORT,'Narrative history is immutable'); END;");
}
/** Strict Director decision; no topic or pair heuristics determine membership. */
function parseNarrativeDecision(mixed $decision):array
{
    if(!is_array($decision)||count($decision)!==2||array_diff(array_keys($decision),['action','story_id'])||!in_array($decision['action']??null,['none','start','continue','conclude','revisit'],true))throw new AiGuard('invalid_proposal','narrative_shape_or_action');
    $id=$decision['story_id']??null;
    if(in_array($decision['action'],['none','start'],true)){if($id!==null)throw new AiGuard('invalid_proposal','narrative_unexpected_id');}
    elseif(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new AiGuard('invalid_proposal','narrative_missing_or_invalid_id');
    return $decision;
}
/** Require a completed Director conversation with its own public conversation event. */
function narrativePublicConversation(PDO $db,string $id):?array
{
    $conversation=publicMemoryConversation($db,$id);if(!$conversation)return null;
    $q=$db->prepare("SELECT 1 FROM director_runs d JOIN simulation_opportunities o ON o.run_id=d.run_id AND o.outcome='conversation'
        WHERE d.conversation_id=? AND d.run_id=? AND (SELECT COUNT(*) FROM conversation_participants p WHERE p.conversation_id=d.conversation_id)=2
        AND EXISTS(SELECT 1 FROM events e WHERE e.run_id=d.run_id AND e.visibility='public' AND e.kind='conversation'
            AND ((e.actor_id=? AND e.target_id=?) OR (e.actor_id=? AND e.target_id=?))
            AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||d.conversation_id))");
    $q->execute([$id,$conversation['run_id'],$conversation['speaker_a'],$conversation['speaker_b'],$conversation['speaker_b'],$conversation['speaker_a']]);return $q->fetchColumn()?$conversation:null;
}
/** Called only inside the accepted conversation transaction. The offered head is a snapshot fence. */
function saveNarrativeStory(PDO $db,array $decision,array $offered,string $conversation,string $time):?string
{
    $decision=parseNarrativeDecision($decision);$action=$decision['action'];if($action==='none')return null;
    $current=narrativePublicConversation($db,$conversation);if(!narrativeStoryInstalled($db)||!$current)throw new AiGuard('apply_rejected');
    if($action==='start'){$id=bin2hex(random_bytes(16));$db->prepare('INSERT INTO narrative_stories VALUES (?,?)')->execute([$id,$time]);$sequence=1;}
    else{
        $id=$decision['story_id'];$matches=array_values(array_filter($offered,static fn($row)=>($row['id']??null)===$id));if(count($matches)!==1)throw new AiGuard('invalid_proposal');
        $q=$db->prepare('SELECT sequence,conversation_id,action FROM narrative_story_exchanges WHERE story_id=? ORDER BY sequence DESC LIMIT 1');$q->execute([$id]);$head=$q->fetch();$expected=$matches[0];
        if(!$head||(int)$head['sequence']!==($expected['sequence']??null)||$head['conversation_id']!==($expected['head_id']??null)||!narrativePublicConversation($db,$head['conversation_id']))throw new AiGuard('apply_rejected');
        $holder=$expected['holder_id']??null;$witness=$expected['known_exchange']['id']??null;
        if(!is_string($witness)||($holder!==null&&(!is_string($holder)||!in_array($holder,[$current['speaker_a'],$current['speaker_b']],true))))throw new AiGuard('apply_rejected');
        $known=narrativePublicConversation($db,$witness);if(!$known||($holder!==null&&!in_array($holder,[$known['speaker_a'],$known['speaker_b']],true)))throw new AiGuard('apply_rejected');
        $q=$db->prepare('SELECT 1 FROM narrative_story_exchanges WHERE story_id=? AND conversation_id=?');$q->execute([$id,$witness]);if(!$q->fetchColumn())throw new AiGuard('apply_rejected');
        $closed=$head['action']==='conclude';if(($action==='revisit')!==$closed)throw new AiGuard('invalid_proposal');$sequence=(int)$head['sequence']+1;
    }
    $db->prepare('INSERT INTO narrative_story_exchanges VALUES (?,?,?,?,?)')->execute([$id,$sequence,$conversation,$action,$time]);return $id;
}

/** The optional capability is never enabled merely by installing its tables. */
function narrativeStoryEnabled(PDO $db):bool
{
    $flag=getenv('LITTLE_SPARKS_NARRATIVE_STORIES_ENABLED');if($flag===false||$flag==='0')return false;
    if($flag!=='1'||!narrativeStoryInstalled($db)||sparkDialogueMode()!=='director')throw new AiGuard('configuration_invalid');
    return true;
}
/** Offer verified public story heads; unattributed prior speech is Director context, not resident memory. */
function narrativeStoryCandidates(PDO $db,array $pair):array
{
    $ids=array_column($pair,'id');if(count($ids)!==2)return [];
    $q=$db->prepare("SELECT x.story_id,x.conversation_id AS witness_id,
        CASE WHEN EXISTS(SELECT 1 FROM conversation_participants p WHERE p.conversation_id=x.conversation_id AND p.resident_id=?) THEN ?
             WHEN EXISTS(SELECT 1 FROM conversation_participants p WHERE p.conversation_id=x.conversation_id AND p.resident_id=?) THEN ? ELSE NULL END AS holder_id
        FROM narrative_story_exchanges x WHERE x.sequence=(SELECT MAX(h.sequence) FROM narrative_story_exchanges h WHERE h.story_id=x.story_id)
        ORDER BY x.created_at DESC,x.story_id LIMIT 32");$q->execute([$ids[0],$ids[0],$ids[1],$ids[1]]);$out=[];
    foreach($q->fetchAll() as $row){
        $id=$row['story_id'];if(isset($out[$id])||count($out)>=6)continue;
        $known=narrativePublicConversation($db,$row['witness_id']);if(!$known||($row['holder_id']!==null&&!in_array($row['holder_id'],[$known['speaker_a'],$known['speaker_b']],true)))continue;
        $h=$db->prepare('SELECT sequence,conversation_id,action FROM narrative_story_exchanges WHERE story_id=? ORDER BY sequence DESC LIMIT 1');$h->execute([$id]);$head=$h->fetch();
        if(!$head||!narrativePublicConversation($db,$head['conversation_id']))continue;
        $messages=conversationMessageBatch($db,[$row['witness_id']])[$row['witness_id']]??[];if(count($messages)<2||count($messages)>64)continue;
        $out[$id]=['id'=>$id,'sequence'=>(int)$head['sequence'],'head_id'=>$head['conversation_id'],'state'=>$head['action']==='conclude'?'concluded':'open',
            'holder_id'=>$row['holder_id'],'known_exchange'=>['id'=>$row['witness_id'],'at'=>$known['created_at'],'messages'=>array_map(static fn($m)=>['resident_id'=>$m['resident_id'],'text'=>$m['text']],$messages)]];
    }return array_values($out);
}
function validateNarrativeProposal(array $proposal,array $context):void
{
    if(!array_key_exists('narrative_stories',$context))return;
    $decision=parseNarrativeDecision($proposal['narrative_story']??null);
    if($decision['action']!=='none'&&($proposal['outcome']??null)!=='conversation')throw new AiGuard('invalid_proposal','narrative_requires_conversation');
    if(!in_array($decision['action'],['none','start'],true)){
        $match=array_values(array_filter($context['narrative_stories'],static fn($c)=>$c['id']===$decision['story_id']));
        if(count($match)!==1)throw new AiGuard('invalid_proposal','narrative_not_offered');
        if(($decision['action']==='revisit')!==($match[0]['state']==='concluded'))throw new AiGuard('invalid_proposal','narrative_state_mismatch');
    }
}
