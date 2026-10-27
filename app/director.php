<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/director_config.php';
require_once __DIR__.'/director_context.php';
require_once __DIR__.'/director_accounting.php';

/** A provider protocol seam; tests supply this interface, never an API key. */
interface DirectorTransport
{
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array;
}
require_once __DIR__.'/director_transport.php';
require_once __DIR__.'/director_staging.php';
require_once __DIR__.'/director_access.php';

function directorSession(PDO $db,array $registered,array $pair,string $runtime,int $now):array
{
    $ids=array_column($pair,'id');sort($ids);$revisions=[];
    foreach($ids as $id){$q=$db->prepare('SELECT revision FROM resident_identities WHERE resident_id=?');$q->execute([$id]);$revisions[$id]=(int)$q->fetchColumn();}
    $scope=hash('sha256',json_encode([$ids,$revisions,$runtime],JSON_THROW_ON_ERROR));
    $execution=directorExecutionConfiguration();
    $q=$db->prepare("SELECT * FROM director_sessions WHERE fingerprint=? AND scope=? AND state IN ('new','ready') AND turns<? AND created_at>=? ORDER BY rowid DESC LIMIT 1");
    $q->execute([$registered['fingerprint'],$scope,$execution['session_max_turns'],gmdate('Y-m-d\TH:i:s\Z',$now-$execution['session_ttl_seconds'])]);if($row=$q->fetch())return $row;
    $id=bin2hex(random_bytes(16));$time=gmdate('Y-m-d\TH:i:s\Z',$now);
    $db->prepare("INSERT INTO director_sessions(id,fingerprint,scope,agent_id,runtime,state,created_at,updated_at) VALUES (?,?,?,?,?,'new',?,?)")
        ->execute([$id,$registered['fingerprint'],$scope,$registered['agent_id'],$runtime,$time,$time]);
    $q=$db->prepare('SELECT * FROM director_sessions WHERE id=?');$q->execute([$id]);return $q->fetch();
}

function directorKeys(array $value,array $keys):void
{
    if(count($value)!==count($keys)||array_diff(array_keys($value),$keys))throw new AiGuard('invalid_proposal');
}
function parseDirectorProposal(array $p,array $pair,array $choices):array
{
    directorKeys($p,['contract','outcome','topic','messages','world_action','transmissions','notes']);
    if(($p['contract']??null)!=='sanctuary.director.v1'||!in_array($p['outcome']??null,['conversation','action','quiet','none'],true)
        ||!is_string($p['topic']??null)||!is_string($p['world_action']??null)||!array_key_exists($p['world_action'],$choices)
        ||!is_array($p['messages'])||!array_is_list($p['messages'])||!is_array($p['notes'])||!array_is_list($p['notes'])||count($p['notes'])>2
        // V3A does not infer a fact entailment from free prose or model-selected aliases.
        // Existing V5 receipt chains remain intact. New text is retained as unverified notes.
        ||$p['transmissions']!==[])throw new AiGuard('invalid_proposal');
    safeAiText($p['topic'],64);$conversation=$p['outcome']==='conversation';
    if(count($p['messages'])!==($conversation?2:0)||(!$conversation&&$p['notes']!==[])
        ||($conversation&&count($pair)!==2)||($p['outcome']==='action'&&$p['world_action']==='none')
        ||(in_array($p['outcome'],['quiet','none'],true)&&$p['world_action']!=='none'))throw new AiGuard('invalid_proposal');
    foreach($p['messages'] as $i=>$m){
        if(!is_array($m))throw new AiGuard('invalid_proposal');directorKeys($m,['resident_id','text']);
        if(($m['resident_id']??null)!==$pair[$i]['id'])throw new AiGuard('invalid_proposal');
        $text=safeAiText($m['text']??null,160);
        if(trim($text)!==$text||preg_match('/\A(?:[AB]|assistant|user|system|resident|robot)\s*:/iu',$text))throw new AiGuard('invalid_proposal');
        foreach($pair as $actor)if(isset($actor['name'])&&preg_match('/\A'.preg_quote($actor['name'],'/').'\s*:/iu',$text))throw new AiGuard('invalid_proposal');
    }
    if($conversation&&$p['messages'][0]['text']===$p['messages'][1]['text'])throw new AiGuard('invalid_proposal');
    foreach($p['notes'] as $note){
        if(!is_array($note))throw new AiGuard('invalid_proposal');directorKeys($note,['resident_id','sequence','quote','classification']);
        if(!in_array($note['resident_id']??null,array_column($pair,'id'),true)||!is_int($note['sequence']??null)||!in_array($note['sequence'],[0,1],true)
            ||!in_array($note['classification']??null,['preference','interpretation','rumor'],true))throw new AiGuard('invalid_proposal');
        safeAiText($note['quote']??null,160);
        if(!str_contains($p['messages'][$note['sequence']]['text'],$note['quote']))throw new AiGuard('invalid_proposal');
    }
    return $p+['first'=>$p['messages'][0]['text']??'','second'=>$p['messages'][1]['text']??'','director'=>true];
}

function saveDirectorMemory(PDO $db,string $conversation,array $proposal,array $pair,string $time):void
{
    if(!publicMemoryConversation($db,$conversation))throw new AiGuard('apply_rejected');
    foreach($proposal['notes'] as $note){
        $q=$db->prepare('SELECT body FROM messages WHERE conversation_id=? AND sequence=?');$q->execute([$conversation,$note['sequence']]);
        if(!str_contains((string)$q->fetchColumn(),$note['quote']))throw new AiGuard('apply_rejected');
        $q=$db->prepare('SELECT COUNT(*) FROM director_notes WHERE resident_id=?');$q->execute([$note['resident_id']]);
        if((int)$q->fetchColumn()>=memoryConfiguration()['per_resident'])continue;
        $other=$pair[0]['id']===$note['resident_id']?$pair[1]['id']:$pair[0]['id'];
        $db->prepare('INSERT OR IGNORE INTO director_notes VALUES (?,?,?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)),$note['resident_id'],$other,$conversation,$note['sequence'],$note['quote'],$note['classification'],$time]);
    }
    // No dialogue-derived world facts, PHP factual openings, or manufactured receipts.
}

/** Called inside the conversation transaction: compare, never repair, speech. */
function verifyDirectorDialogue(PDO $db,string $run,string $conversation,array $proposal):void
{
    $q=$db->prepare('SELECT resident_id,body FROM messages WHERE conversation_id=? ORDER BY sequence');$q->execute([$conversation]);$saved=$q->fetchAll();
    if(count($saved)!==2||count($proposal['messages']??[])!==2)throw new AiGuard('apply_rejected');
    $lines=[];
    foreach($saved as $i=>$line){
        $original=$proposal['messages'][$i];
        if($line['resident_id']!==$original['resident_id']||$line['body']!==$original['text'])throw new AiGuard('apply_rejected');
        $count=preg_match_all('/./us',$original['text']);
        $lines[]=['sequence'=>$i,'resident_id'=>$line['resident_id'],'characters'=>$count,'utf8_bytes'=>strlen($original['text']),
            'at_ceiling'=>$count===160,'provider_text_sha256'=>hash('sha256',$original['text']),'saved_text_sha256'=>hash('sha256',$line['body'])];
    }
    // Private diagnostic only. No words, world facts, credentials or billing claims.
    $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['director_dialogue_integrity:'.$run,
        json_encode(['conversation_id'=>$conversation,'maximum_characters'=>160,'unchanged'=>true,'messages'=>$lines],JSON_THROW_ON_ERROR)]);
}

/** Validate the provider's effective session settings independently of its dialogue. */
function assertManagedSession(array $remote,array $registered,string $id,bool $reusing):void
{
    $agent=$remote['agent']??[];
    if(($remote['id']??null)!==$id||($remote['object']??null)!=='agent.session'||($agent['id']??null)!==$registered['agent_id']
        ||($agent['model']??null)!==$registered['model']||!in_array($agent['service_tier']??null,['default','auto'],true)||($agent['tools']??null)!==[]||($agent['multi_agent']['enabled']??null)!==false
        ||($remote['environment']['type']??null)!=='none'||($remote['required_actions']??null)!==[]||($remote['vault_ids']??null)!==[]
        ||!in_array($remote['status']??null,$reusing?['idle']:['idle','in_progress'],true))throw new AiGuard('session_unavailable');
    // "auto" is a supported session policy, not proof of a billed tier or cost.
    // Usage/accounting stays provisional and never releases the full commitment.
}
function managedDirectorEncounter(PDO $db,DirectorTransport $transport,array $attempt,array $session,array $registered,array $prepared,array $choices):array
{
    // Both managed adapters share the same audited wait and cancellation bounds.
    return stagingManagedEncounter($db,$transport,$attempt,$session,$registered,$prepared,$choices,null,null,false,true);
}

function simulateDirectorOpportunity(PDO $db,string $at,bool $allowPaid,?AiTransport $responses=null,?DirectorTransport $managed=null,?int $now=null,?DirectorContextProvider $contextProvider=null,bool $conversationOnly=false,?StagingPermit $staging=null,bool $configuredManaged=false,?string $stagingContinueAfter=null):array
{
    $now??=time();
    if(getenv('LITTLE_SPARKS_SIMULATION_ENABLED')!=='1')return ['status'=>'paused'];
    if($rest=nightRestOpportunity($db,$at,$now))return $rest;
    $cfg=directorConfiguration();
    if(!$cfg['enabled']||getenv('LITTLE_SPARKS_AI_ENABLED')!=='1'||!$allowPaid)return ['status'=>'silent','reason'=>'director_disabled'];
    // The live API rejects spend_control. Atomic application commitments remain
    // enforced, but neither these nor best-effort usage prove a provider bill cap.
    $liveManaged=$cfg['runtime']==='agents'&&$managed===null;
    if($liveManaged){
        if(!managedLiveAdmission()['allowed'])return ['status'=>'silent','reason'=>'configuration_invalid'];
        $key=getenv('OPENAI_API_KEY');
        if($key===false||$key===''){
            require_once __DIR__.'/systemd_credentials.php';
            try{if(inspectSystemdCredential()['reason']!==null)return ['status'=>'silent','reason'=>'credentials_unavailable'];}
            catch(\Throwable){return ['status'=>'silent','reason'=>'credentials_unavailable'];}
        }
        $managed=new OpenAiManagedTransport();
        // The prepared one-shot database cannot become a second test through the routine route.
        if($db->query('SELECT 1 FROM director_staging_permits LIMIT 1')->fetchColumn())throw new AiGuard('period_recorded');
        $file=$db->query('PRAGMA database_list')->fetch()['file'];
        if(getenv('LITTLE_SPARKS_ENVIRONMENT')==='staging'&&is_file(dirname($file).'/approved-director-once.json'))throw new AiGuard('period_recorded');
        if(getenv('LITTLE_SPARKS_ENVIRONMENT')==='staging'&&is_file('/var/lib/sanctuaryfor-ai-staging/preflight/agent-access.jsonl')
            &&(int)$db->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'director_access_request:%'")->fetchColumn()!==2)throw new AiGuard('accounting_unresolved');
    }
    // Explicit injected transports can exercise the same configured lifecycle offline.
    $configuredManaged=$configuredManaged||$liveManaged;
    if($stagingContinueAfter!==null&&(!$configuredManaged||!$conversationOnly||$staging!==null))throw new AiGuard('configuration_invalid');
    $registered=registeredDirector($db,$cfg);$pricing=aiConfiguration($now);if($registered['model']!==$pricing['model'])throw new AiGuard('model_unpriced');
    simulationInterval();memoryConfiguration();
    $claim=claimActivity($db,$at,$now);if(isset($claim['replay']))return $claim['replay']+['replayed'=>true];
    $attempt=null;$usage=null;
    try{
        if(count($claim['pair'])!==2)return applyActivity($db,$claim,['outcome'=>'none','topic'=>'quiet','first'=>'','second'=>'','world_action'=>'none'],['none'=>null],$now);
        $choices=aiActionChoices($db,$claim['pair'][0]['id'],$claim['pair'][1]['id'],$now);
        if($conversationOnly)$choices=['none'=>null];
        $prepared=($contextProvider??new SqliteDirectorContextProvider())->prepare($db,$claim['pair'],$choices,$claim,$cfg);
        $db->exec('BEGIN IMMEDIATE');try{$session=directorSession($db,$registered,$claim['pair'],$cfg['runtime'],$now);$db->exec('COMMIT');}catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
        $body=$cfg['runtime']==='responses'?directorRequest($pricing,$registered,$prepared,$choices):null;
        $pricing['director_session']=$session['id'];$pricing['director_runtime']=$cfg['runtime'];
        $pricing['director_once']=$staging!==null;
        $pricing['director_context']=hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        if($managed!==null)$pricing['director_slots']=8; // Creation/submission, bounded reads, items, moderation, cleanup.
        if($configuredManaged)$pricing['director_slots']=8; // Bounded wire lifecycle, not a daily ceiling.
        if($stagingContinueAfter!==null)$pricing['staging_continue_after']=$stagingContinueAfter;
        if($staging!==null){$staging->assertEncounter($db,$at,$registered,$prepared);$pricing['director_slots']=8;$pricing['staging_allocation']=100000;}
        $attempt=reserveAi($db,$claim['id'],$claim['pair'],$pricing,$now);
        if($managed!==null){
            $result=($staging!==null||$configuredManaged)?stagingManagedEncounter($db,$managed,$attempt,$session,$registered,$prepared,$choices,null,null,$conversationOnly,$staging===null):managedDirectorEncounter($db,$managed,$attempt,$session,$registered,$prepared,$choices);$proposal=$result['proposal'];
            if($configuredManaged&&$staging===null&&!$result['usage_uncertain'])$proposal['managed_commitment']=$attempt['reserved'];
            // Best-effort usage is not final billing. Successful configured calls
            // retain their full commitment; experimental/failed outcomes keep holds.
            $usage=null; // Best-effort observations must remain uncertain even if apply later fails.
        }else{
            $responses??=new OpenAiTransport();startAiRequest($db,$attempt,'responses_started');$result=$responses->post('responses',$body,$attempt['expires']);
            $usage=recordAiUsage($db,$attempt,$result,$pricing);$proposal=parseDirectorProposal(decodedAiProposal($result),$prepared['context']['participants'],$choices);
            startAiRequest($db,$attempt,'moderation_started');$moderation=$responses->post('moderations',['model'=>'omni-moderation-latest','input'=>json_encode([$prepared['context'],$proposal],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)],$attempt['expires']);
            if(count($moderation['results']??[])!==1||($moderation['results'][0]['flagged']??null)!==false)throw new AiGuard('moderation_rejected');
        }
        $proposal['director_session']=$session['id'];$proposal['director_fingerprint']=$registered['fingerprint'];
        $result=applyActivity($db,$claim,$proposal,$choices,$now,$attempt,$usage);
        return $result;
    }catch(\Throwable $e){
        if(isset($session)&&$managed!==null)$db->prepare("UPDATE director_sessions SET state='uncertain' WHERE id=? AND state<>'unavailable'")->execute([$session['id']]);
        if($attempt!==null&&$managed!==null){
            $q=$db->prepare("SELECT 1 FROM director_http_calls WHERE attempt_id=? AND kind IN ('create','submit','moderation') LIMIT 1");$q->execute([$attempt['id']]);
            if(!$q->fetchColumn()){
                // A failed read before any generation was dispatched may abandon
                // continuity on the next opportunity. Keep all reserved headroom
                // consumed conservatively; never retry this opportunity's input.
                $db->prepare('UPDATE ai_attempts SET estimated_microusd=reserved_microusd WHERE id=?')->execute([$attempt['id']]);
                $db->prepare('UPDATE director_attempts SET estimated_microusd=reserved_microusd WHERE id=?')->execute([$attempt['id']]);
                $usage=['input_tokens'=>0,'output_tokens'=>0,'cost_microusd'=>0];
            }
        }
        $reason=$e instanceof AiGuard?$e->reason:($e instanceof ManagedHttpFailure?($e->status===null?'transport_uncertain':'provider_api_error'):($e instanceof \JsonException?'invalid_proposal':'apply_rejected'));
        return failActivity($db,$claim,$attempt,$usage,$reason);
    }
}
