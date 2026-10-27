<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/ai_simulation.php';

function simulationInterval(): int
{
    $value=getenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES');
    if($value===false)return 15;
    if(!preg_match('/\A[1-9][0-9]{0,3}\z/',$value)||(int)$value>1440)throw new AiGuard('configuration_invalid');
    return (int)$value;
}

function dueOpportunity(int $now): string
{
    $interval=simulationInterval();$minute=(int)gmdate('H',$now)*60+(int)gmdate('i',$now);
    $slot=intdiv($minute,$interval)*$interval;
    return gmdate('Y-m-d',$now).'T'.sprintf('%02d:%02d:00Z',intdiv($slot,60),$slot%60);
}

function opportunityId(string $at): string
{
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$at,new \DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$at||substr($at,17,2)!=='00')throw new Problem(422,'Choose a valid UTC minute.');
    // Interval changes cannot create a second ID for the same UTC minute.
    return 'op:'.$at;
}

function activityProposalSchema(): array
{
    $schema=aiProposalSchema();
    $schema['properties']['outcome']=['type'=>'string','enum'=>['conversation','action','quiet','none']];
    $schema['properties']['first']['minLength']=0;$schema['properties']['second']['minLength']=0;
    $schema['required'][]='outcome';return $schema;
}

function activityRequestBody(array $config,array $context): array
{
    $body=aiRequestBody($config,$context);
    $body['instructions'].=' You may choose conversation, action, quiet or none. Never force interaction. Use preferences, friendship and memories to decide. For conversation provide two short natural lines and an optional action. For action choose one available action with both lines empty. For quiet or none use world_action none and both lines empty. Quiet is solitary rest, not a conversation. Return the outcome field too.';
    $body['text']['format']['schema']=activityProposalSchema();
    if(strlen(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>aiRequestByteLimit())throw new AiGuard('configuration_invalid');
    return $body;
}

function parseActivityProposal(array $response,array $choices): array
{
    $p=decodedAiProposal($response);
    if(count($p)!==5||array_diff(array_keys($p),['outcome','topic','first','second','world_action'])
        ||!in_array($p['outcome']??null,['conversation','action','quiet','none'],true)
        ||!is_string($p['topic']??null)||!array_key_exists($p['topic'],SOCIAL_TOPICS)
        ||!is_string($p['world_action']??null)||!array_key_exists($p['world_action'],$choices))throw new AiGuard('invalid_proposal');
    if($p['outcome']==='conversation'){
        $p['first']=safeAiDialogue($p['first']??null);$p['second']=safeAiDialogue($p['second']??null);
    }elseif(($p['first']??null)!==''||($p['second']??null)!=='')throw new AiGuard('invalid_proposal');
    if(($p['outcome']==='action'&&$p['world_action']==='none')
        ||(in_array($p['outcome'],['quiet','none'],true)&&$p['world_action']!=='none'))throw new AiGuard('invalid_proposal');
    return $p;
}

// Called under the claim write lock. Lifetime opportunity counts prevent yesterday's
// favorites from monopolizing today; same-day claims count even if they later fail.
function selectActivityResidents(PDO $db,string $day,?array $cfg=null,?int $now=null): array
{
    if(villageSleepingAt($now??time()))return [];
    $cfg??=activityConfiguration();$now??=time();
    $query=$db->prepare("SELECT r.id,r.personality,
        (SELECT COUNT(*) FROM simulation_opportunities o WHERE o.resident_a=r.id OR o.resident_b=r.id) AS turns,
        (SELECT MAX(created_at) FROM simulation_opportunities o WHERE o.resident_a=r.id OR o.resident_b=r.id) AS last_turn
        FROM residents r WHERE
        (SELECT COUNT(*) FROM simulation_opportunities o WHERE o.day=? AND (o.resident_a=r.id OR o.resident_b=r.id))<?
        ORDER BY turns,COALESCE(last_turn,''),r.created_at,r.id LIMIT ?");
    // COUNT has no column affinity: bind the ceiling as an integer, not PDO's
    // default string, or SQLite's storage-class ordering would bypass this bound.
    $query->bindValue(1,$day,PDO::PARAM_STR);$query->bindValue(2,$cfg['resident_daily'],PDO::PARAM_INT);$query->bindValue(3,$cfg['candidates'],PDO::PARAM_INT);$query->execute();$candidates=$query->fetchAll();
    if(!$candidates)return [];
    // The actor is always the least served. Prefer an equally underserved partner
    // not met recently; friendship bounds may leave a resident with solitary activity.
    $a=array_shift($candidates);
    $recent=$cfg['resident_cooldown']?latestResidentConversations($db,array_merge([$a['id']],array_column($candidates,'id'))):[];
    $residentCutoff=gmdate('Y-m-d\TH:i:s\Z',$now-$cfg['resident_cooldown']);
    if($cfg['resident_cooldown']&&($recent[$a['id']]??'')>$residentCutoff)return [$a];
    $pairs=$cfg['pair_cooldown']?latestPairConversations($db,$a['id']):[];$pairCutoff=gmdate('Y-m-d\TH:i:s\Z',$now-$cfg['pair_cooldown']);
    usort($candidates,static function(array $x,array $y)use($db,$a):int{
        $rank=($x['turns']<=>$y['turns']);if($rank)return $rank;
        return strcmp(pair($db,$a['id'],$x['id'])['last_interacted_at']??'',pair($db,$a['id'],$y['id'])['last_interacted_at']??'')?:strcmp($x['id'],$y['id']);
    });
    foreach($candidates as $b){
        if($cfg['resident_cooldown']&&($recent[$b['id']]??'')>$residentCutoff)continue;
        if($cfg['pair_cooldown']&&($pairs[$b['id']]??'')>$pairCutoff)continue;
        if(hasSocialRoom($db,$a['id'],$b['id']))return [$a,$b];
    }
    return [$a];
}

function activityResult(PDO $db,string $id): array
{
    $q=$db->prepare('SELECT o.id AS opportunity_id,o.outcome,o.scheduled_at,r.id,r.status,r.provider,r.interaction_count FROM simulation_opportunities o LEFT JOIN simulation_runs r ON r.id=o.run_id WHERE o.id=?');$q->execute([$id]);
    $result=$q->fetch()?:throw new Problem(503,'Opportunity unavailable.');
    $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['activity_failure:'.$id]);$reason=$q->fetchColumn();
    if(is_string($reason))$result['failure_category']=$reason;
    $q->execute(['activity_rest:'.$id]);if($q->fetchColumn()==='night_rest')$result['reason']='night_rest';
    return $result;
}

function recoverActivityClaims(PDO $db,int $now): void
{
    // Called inside BEGIN IMMEDIATE. Never replay a dispatched/abandoned paid attempt.
    recoverExpiredAiLease($db,$now);
    $q=$db->prepare("SELECT * FROM simulation_opportunities WHERE outcome='pending' AND expires_at<=?");$q->execute([$now]);
    foreach($q->fetchAll() as $op){
        $a=$db->prepare('SELECT * FROM ai_attempts WHERE period=?');$a->execute([$op['id']]);$attempt=$a->fetch();
        $run=$attempt['id']??bin2hex(random_bytes(16));$time=gmdate('Y-m-d\TH:i:s\Z',$now);
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,error_code,input_tokens,output_tokens,cost_microusd) VALUES (?,?,'failed',?,?,?,'simulation_failed',?,?,?)")
            ->execute([$run,$op['id'],($attempt||directorEnabled($db))?'openai':'scripted',$op['created_at'],$time,$attempt['input_tokens']??0,$attempt['output_tokens']??0,$attempt['estimated_microusd']??0]);
        if($attempt){
            // An expired worker's known estimate is not proof of final billing.
            $db->prepare("UPDATE ai_attempts SET state='uncertain',error_code=COALESCE(error_code,'lease_expired'),finished_at=? WHERE id=? AND state IN ('reserved','settled','uncertain')")->execute([$time,$run]);
            if(directorEnabled($db))$db->prepare("UPDATE director_attempts SET state='uncertain' WHERE id=? AND state='reserved'")->execute([$run]);
            $db->prepare('UPDATE ai_lease SET owner=NULL,expires_at=0 WHERE owner=?')->execute([$run]);
        }
        $db->prepare("UPDATE simulation_opportunities SET outcome='failed',run_id=?,finished_at=? WHERE id=?")->execute([$run,$time,$op['id']]);
        $db->prepare('INSERT OR IGNORE INTO settings(name,value) VALUES (?,?)')->execute(['activity_failure:'.$op['id'],'lease_expired']);
    }
}

function claimActivity(PDO $db,string $at,int $now): array
{
    if(!in_array((int)$db->query('PRAGMA user_version')->fetchColumn(),[4,5,6],true))throw new Problem(503,'Explicit V4 migration required.');
    $id=opportunityId($at);$db->exec('BEGIN IMMEDIATE');
    try{
        if(identityEnabled($db)&&!directorEnabled($db)){$db->exec('COMMIT');return ['replay'=>['status'=>'paused','reason'=>'identity_runtime_pending']];}
        recoverActivityClaims($db,$now);
        $existing=activityResultOrNull($db,$id);
        if($existing){$db->exec('COMMIT');return ['replay'=>$existing];}
        $cfg=activityConfiguration();
        // Saved outcomes replay forever; only new work has the bounded catch-up window.
        if(strtotime($at)>$now||strtotime($at)<$now-7*86400)throw new Problem(422,'Choose an elapsed UTC minute within seven days.');
        assertSimulationLease($db);
        if($db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new Problem(409,'Another opportunity is in progress.');
        $day=gmdate('Y-m-d',$now);$q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=?');$q->execute([$day]);
        if((int)$q->fetchColumn()>=$cfg['daily_opportunities'])throw new Problem(429,'The village is resting at its configured daily ceiling.');
        $resting=villageSleepingAt($now)||villageSleepingAt(strtotime($at));
        $pair=$resting?[]:selectActivityResidents($db,$day,$cfg,$now);$time=gmdate('Y-m-d\TH:i:s\Z',$now);
        $db->prepare("INSERT INTO simulation_opportunities(id,scheduled_at,day,resident_a,resident_b,outcome,expires_at,created_at) VALUES (?,?,?,?,?,'pending',?,?)")
            ->execute([$id,$at,$day,$pair[0]['id']??null,$pair[1]['id']??null,$now+directorExecutionConfiguration()['lease_seconds'],$time]);
        if($resting)$db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['activity_rest:'.$id,'night_rest']);
        $db->exec('COMMIT');return ['id'=>$id,'pair'=>$pair,'created_at'=>$time];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function nightRestOpportunity(PDO $db,string $at,int $now):?array
{
    if(!villageSleepConfiguration()['enabled']||(identityEnabled($db)&&!directorEnabled($db)))return null;
    opportunityId($at);
    if(!villageSleepingAt($now)&&!villageSleepingAt(strtotime($at)))return null;
    $claim=claimActivity($db,$at,$now);
    if(isset($claim['replay']))return $claim['replay']+['replayed'=>true];
    return applyActivity($db,$claim,['outcome'=>'quiet','topic'=>'quiet','first'=>'','second'=>'','world_action'=>'none'],['none'=>null],$now);
}
function activityResultOrNull(PDO $db,string $id): ?array
{
    $q=$db->prepare('SELECT 1 FROM simulation_opportunities WHERE id=?');$q->execute([$id]);
    return $q->fetchColumn()?activityResult($db,$id):null;
}

function scriptedActivity(PDO $db,array $pair,string $id,array $choices): array
{
    $p=['outcome'=>'none','topic'=>'quiet','first'=>'','second'=>'','world_action'=>'none'];
    if(!$pair)return $p;
    $a=$pair[0];$interest=preferences($db,$a['id'])['interest']??'quiet_walks';
    $roll=hexdec(substr(hash('sha256',$id.$a['id'].$interest),0,4))%10;
    if($roll<($a['personality']==='shy'?4:2))return array_replace($p,['outcome'=>'quiet']);
    if($roll===4)return $p;
    $preferred=match($interest){'gardening'=>['plant_flowers','plant_tree','plant_bush'],'making_friends'=>['visit_friend','adopt_pet'],'stargazing'=>['place_decoration','decorate_house'],default=>['build_path','plant_bush']};
    if($roll<7)foreach($preferred as $action)if(array_key_exists($action,$choices))return array_replace($p,['outcome'=>'action','world_action'=>$action]);
    if(count($pair)<2)return array_replace($p,['outcome'=>'quiet']);
    $topic=(new ScriptedPlanner())->plan($a,$pair[1],$id)['topic'];
    $friend=pair($db,$a['id'],$pair[1]['id']);
    if($friend&&$friend['affinity']>60)$topic='welcome';
    $memory=$db->prepare("SELECT 1 FROM memories WHERE resident_id=? AND visibility='public' AND other_resident_id=? LIMIT 1");$memory->execute([$a['id'],$pair[1]['id']]);
    if($memory->fetchColumn()&&$roll===9)$topic='stars';
    return ['outcome'=>'conversation','topic'=>$topic,'first'=>SOCIAL_TOPICS[$topic][0],'second'=>SOCIAL_TOPICS[$topic][1],'world_action'=>'none'];
}

function applyActivity(PDO $db,array $claim,array $proposal,array $choices,int $now,?array $attempt=null,?array $usage=null): array
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        assertSimulationLease($db,$attempt['id']??null);
        $q=$db->prepare("SELECT expires_at FROM simulation_opportunities WHERE id=? AND outcome='pending'");$q->execute([$claim['id']]);
        $expiry=$q->fetchColumn();if($expiry===false||(int)$expiry<=time()||gmdate('Y-m-d',$now)!==gmdate('Y-m-d'))throw new AiGuard('apply_rejected');
        $run=$attempt['id']??bin2hex(random_bytes(16));$time=gmdate('Y-m-d\TH:i:s\Z',$now);$ai=$attempt!==null;$pair=$claim['pair'];$outcome=$proposal['outcome'];
        $limits=activityConfiguration();assertAppliedActivityLimits($db,$pair,gmdate('Y-m-d',$now),$limits);
        if($outcome==='conversation'&&(!isset($pair[1])||!conversationCooldownAllows($db,$pair[0]['id'],$pair[1]['id'],$limits,$now)))throw new Problem(429,'The neighbors are resting between conversations.');
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,interaction_count,input_tokens,output_tokens,cost_microusd) VALUES (?,?,'completed',?,?,?,?,?,?,?)")
            ->execute([$run,$claim['id'],$ai?'openai':'scripted',$claim['created_at'],$time,$outcome==='conversation'?1:0,$usage['input_tokens']??0,$usage['output_tokens']??0,$usage['cost_microusd']??0]);
        if($outcome==='conversation'){
            if(count($pair)!==2||!hasSocialRoom($db,$pair[0]['id'],$pair[1]['id']))throw new AiGuard('apply_rejected');
            $a=$pair[0]['id'];$b=$pair[1]['id'];$topic=isset($proposal['director'])?'welcome':$proposal['topic'];
            strengthen($db,$a,$b,$topic==='quiet'?-1:2,$time);$conversation=bin2hex(random_bytes(16));
            $db->prepare("INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,?,'public',?)")->execute([$conversation,$run,$topic,$time]);
            if($ai)$db->prepare('INSERT INTO ai_conversations VALUES (?,?)')->execute([$conversation,$run]);
            foreach([$a,$b] as $sequence=>$resident){
                $db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$conversation,$resident]);
                $body=$sequence===0?$proposal['first']:$proposal['second'];
                $db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$conversation,$sequence,$resident,$body]);
                if(!isset($proposal['director']))remember($db,$resident,$sequence===0?$b:$a,$run,'Heard a neighbor say: '.($sequence===0?$proposal['second']:$proposal['first']),2,$time);
            }
            event($db,$a,$b,'conversation','simulation',$time,'activity:'.$claim['id'].':conversation',$run);
        }
        if($proposal['world_action']!=='none'){
            if(!$pair||!isset($choices[$proposal['world_action']]))throw new AiGuard('invalid_proposal');
            performResidentAction($db,$pair[0]['id'],$choices[$proposal['world_action']],$time,'activity:'.$claim['id'].':world','simulation',$run);
        }
        if(isset($proposal['director'])){
            $db->prepare('INSERT INTO director_runs VALUES (?,?,?,?,?,?)')->execute([$run,$proposal['director_session'],$proposal['director_fingerprint'],$conversation??null,$proposal['topic'],$time]);
            if($outcome==='conversation'){verifyDirectorDialogue($db,$run,$conversation,$proposal);saveDirectorMemory($db,$conversation,$proposal,$pair,$time);}
            $db->prepare("UPDATE director_sessions SET state='ready' WHERE id=? AND runtime='agents'")->execute([$proposal['director_session']]);
        }elseif($outcome==='conversation'&&memoryEnabled($db))conversationMemory($db,$conversation,$proposal['shared_receipt']??null);
        if($outcome==='quiet'&&$pair)event($db,$pair[0]['id'],null,'quiet_time','simulation',$time,'activity:'.$claim['id'].':quiet',$run);
        $db->prepare('UPDATE simulation_opportunities SET run_id=?,outcome=?,finished_at=? WHERE id=?')->execute([$run,$outcome,$time,$claim['id']]);
        if($ai){
            $db->prepare("UPDATE ai_attempts SET state='applied',finished_at=? WHERE id=?")->execute([$time,$run]);
            if(directorEnabled($db)){
                if(isset($proposal['managed_commitment'])){
                    // Application commitment is retained in full; this is not final provider billing.
                    $db->prepare("UPDATE director_attempts SET state='applied',estimated_microusd=reserved_microusd WHERE id=? AND runtime='agents'")->execute([$run]);
                    $db->prepare("UPDATE ai_attempts SET estimated_microusd=reserved_microusd WHERE id=?")->execute([$run]);
                }
                $q=$db->prepare("UPDATE director_attempts SET state='uncertain' WHERE id=? AND runtime='agents' AND estimated_microusd IS NULL");$q->execute([$run]);
                if($q->rowCount())$db->prepare("UPDATE ai_attempts SET state='uncertain',error_code='usage_unknown' WHERE id=?")->execute([$run]);
                $db->prepare("UPDATE director_attempts SET state='applied' WHERE id=? AND runtime='responses'")->execute([$run]);
            }
            $db->prepare('UPDATE ai_lease SET owner=NULL,expires_at=0 WHERE owner=?')->execute([$run]);
        }
        $db->exec('COMMIT');return activityResult($db,$claim['id'])+['replayed'=>false];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function failActivity(PDO $db,array $claim,?array $attempt,?array $usage,string $reason): array
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        $current=activityResult($db,$claim['id']);
        if($current['outcome']!=='pending'){$db->exec('COMMIT');return $current+['replayed'=>true];}
        $run=$attempt['id']??bin2hex(random_bytes(16));$time=gmdate('Y-m-d\TH:i:s\Z');
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,error_code,input_tokens,output_tokens,cost_microusd) VALUES (?,?,'failed',?,?,?,'simulation_failed',?,?,?)")
            ->execute([$run,$claim['id'],($attempt||directorEnabled($db))?'openai':'scripted',$claim['created_at'],$time,$usage['input_tokens']??0,$usage['output_tokens']??0,$usage['cost_microusd']??0]);
        $db->prepare("UPDATE simulation_opportunities SET outcome='failed',run_id=?,finished_at=? WHERE id=?")->execute([$run,$time,$claim['id']]);
        $category=in_array($reason,['transport_uncertain','provider_api_error','usage_unknown','invalid_proposal','moderation_rejected','apply_rejected','credentials_unavailable','daily_request_cap','encounter_request_cap','execution_deadline','night_rest','monthly_budget_cap','simulation_busy','day_boundary','period_recorded','configuration_invalid','model_unpriced','pricing_unverified','session_unavailable','ledger_capacity'],true)?$reason:'apply_rejected';
        $db->prepare('INSERT OR IGNORE INTO settings(name,value) VALUES (?,?)')->execute(['activity_failure:'.$claim['id'],$category]);
        if($attempt){
            $code=$usage===null?($reason==='usage_unknown'?'usage_unknown':'transport_uncertain'):(in_array($reason,['invalid_proposal','moderation_rejected'],true)?$reason:'apply_rejected');
            $db->prepare('UPDATE ai_attempts SET state=?,error_code=?,finished_at=? WHERE id=?')->execute([$usage===null?'uncertain':'rejected',$code,$time,$run]);
            if(directorEnabled($db))$db->prepare('UPDATE director_attempts SET state=? WHERE id=?')->execute([$usage===null?'uncertain':'rejected',$run]);
            $db->prepare('UPDATE ai_lease SET owner=NULL,expires_at=0 WHERE owner=?')->execute([$run]);
        }
        $db->exec('COMMIT');return activityResult($db,$claim['id'])+['replayed'=>false,'error_code'=>'simulation_failed'];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

function simulateOpportunity(PDO $db,string $at,bool $allowPaid=false,?AiTransport $transport=null,?int $now=null): array
{
    if(directorEnabled($db)){require_once __DIR__.'/director.php';return simulateDirectorOpportunity($db,$at,$allowPaid,$transport,null,$now);}
    if(identityEnabled($db))return ['status'=>'paused','reason'=>'identity_runtime_pending','replayed'=>false];
    $now??=time();
    if(getenv('LITTLE_SPARKS_SIMULATION_ENABLED')!=='1')return ['status'=>'paused','replayed'=>false];
    if($rest=nightRestOpportunity($db,$at,$now))return $rest;
    if(memoryEnabled($db))memoryConfiguration();
    simulationInterval(); // Reject invalid configuration even for explicit/manual opportunities.
    $claim=claimActivity($db,$at,$now);
    if(isset($claim['replay']))return $claim['replay']+['replayed'=>true];
    $attempt=null;$usage=null;$blocked=null;
    try{
        $pair=$claim['pair'];$choices=$pair?aiActionChoices($db,$pair[0]['id'],$pair[1]['id']??$pair[0]['id'],$now):['none'=>null];
        if(count($pair)<2)unset($choices['visit_friend']);
        $prepared=memoryEnabled($db)&&count($pair)===2?prepareMemoryEncounter($db,$pair,$choices):null;
        if(getenv('LITTLE_SPARKS_AI_ENABLED')==='1'&&$allowPaid&&count($pair)===2){
            try{
                if($transport===null&&(!extension_loaded('curl')||!is_string(getenv('OPENAI_API_KEY'))||getenv('OPENAI_API_KEY')===''))throw new AiGuard('credentials_unavailable');
                $config=aiConfiguration($now);$context=$prepared['context']??aiContext($db,$pair,$choices);$body=$prepared?memoryRequestBody($config,$prepared):activityRequestBody($config,$context);
                $attempt=reserveAi($db,$claim['id'],$pair,$config,$now);
            }catch(AiGuard $e){$blocked=$e->reason;}
        }
        if($attempt===null)return applyActivity($db,$claim,$prepared?scriptedMemoryProposal($db,$pair,$choices,$prepared,$claim['id']):scriptedActivity($db,$pair,$claim['id'],$choices),$choices,$now)+['ai_blocked'=>$blocked??'disabled_or_solitary'];
        $transport??=new OpenAiTransport();
        startAiRequest($db,$attempt,'responses_started');$response=$transport->post('responses',$body,$attempt['expires']);
        $usage=recordAiUsage($db,$attempt,$response,$config);$proposal=$prepared?parseMemoryProposal($db,$response,$choices,$pair,$prepared):parseActivityProposal($response,$choices);
        startAiRequest($db,$attempt,'moderation_started');
        $moderation=$transport->post('moderations',['model'=>'omni-moderation-latest','input'=>json_encode([$context,$proposal],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)],$attempt['expires']);
        if(!is_array($moderation['results']??null)||count($moderation['results'])!==1||($moderation['results'][0]['flagged']??null)!==false)throw new AiGuard('moderation_rejected');
        return applyActivity($db,$claim,$proposal,$choices,$now,$attempt,$usage);
    }catch(\Throwable $e){return failActivity($db,$claim,$attempt,$usage,$e instanceof AiGuard?$e->reason:'apply_rejected');}
}
