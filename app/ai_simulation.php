<?php
declare(strict_types=1);
namespace LittleSparks;
require_once __DIR__.'/social.php';
require_once __DIR__.'/ai_budget.php';
require_once __DIR__.'/ai_provider.php';
use PDO;

function aiFallback(PDO $db,string $period,string $reason,int $now):array
{
    return simulateDay($db,$period,null,$now,true)+['provider'=>'scripted','ai_blocked'=>$reason];
}

function failedAiRun(PDO $db,array $attempt,?array $usage):void
{
    $time=gmdate('Y-m-d\TH:i:s\Z');
    $db->prepare("INSERT OR IGNORE INTO simulation_runs(id,period,status,provider,started_at,finished_at,error_code,input_tokens,output_tokens,cost_microusd)
        VALUES (?,?,'failed','openai',?,?,'simulation_failed',?,?,?)")
        ->execute([$attempt['id'],$attempt['period'],$time,$time,$usage['input_tokens']??0,$usage['output_tokens']??0,$usage['cost_microusd']??0]);
}

function simulateOptionalAiDay(PDO $db,string $period,bool $allowPaid=false,?AiTransport $transport=null,?int $now=null):array
{
    $now??=time();
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$period,new \DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d')!==$period)throw new Problem(422,'Choose a valid UTC date.');
    $query=$db->prepare('SELECT id,status,provider,interaction_count FROM simulation_runs WHERE period=?');$query->execute([$period]);
    if($existing=$query->fetch()){
        if((int)$db->query('PRAGMA user_version')->fetchColumn()>=3){
            $query=$db->prepare("SELECT id FROM ai_attempts WHERE period=? AND estimated_microusd IS NOT NULL AND state='settled'");$query->execute([$period]);
            if($id=$query->fetchColumn()){
                $applied=$existing['status']==='completed'&&$existing['provider']==='openai'&&$existing['id']===$id;
                finishAi($db,['id'=>$id],$applied?'applied':'rejected',$applied?null:'apply_rejected');
            }
        }
        return $existing+['replayed'=>true];
    }
    if((int)$db->query('PRAGMA user_version')->fetchColumn()>=4)throw new Problem(422,'Use the V4 opportunity runner for new activity.');
    if($period>gmdate('Y-m-d',$now)||$period<gmdate('Y-m-d',$now-6*86400))throw new Problem(422,'New periods must be today or one of the previous six UTC days.');
    if(getenv('LITTLE_SPARKS_AI_ENABLED')!=='1'||!$allowPaid)return aiFallback($db,$period,'disabled',$now);
    if($transport===null && (!extension_loaded('curl')||getenv('OPENAI_API_KEY')===false||getenv('OPENAI_API_KEY')===''))return aiFallback($db,$period,'credentials_unavailable',$now);
    $attempt=null;$usage=null;
    try{
        $config=aiConfiguration($now);
        if(!in_array((int)$db->query('PRAGMA user_version')->fetchColumn(),[3,4],true))throw new AiGuard('migration_required');
        $pair=selectAiPair($db,$period);if(!$pair)return aiFallback($db,$period,'no_eligible_pair',$now);
        $choices=aiActionChoices($db,$pair[0]['id'],$pair[1]['id'],$now);
        $context=aiContext($db,$pair,$choices);$body=aiRequestBody($config,$context);
        $attempt=reserveAi($db,$period,$pair,$config,$now)+['period'=>$period];
    }catch(AiGuard $guard){return aiFallback($db,$period,$guard->reason,$now);}
    $transport??=new OpenAiTransport();
    try{
        startAiRequest($db,$attempt,'responses_started');
        $response=$transport->post('responses',$body,$attempt['expires']);
        $usage=recordAiUsage($db,$attempt,$response,$config);
        $proposal=parseAiProposal($response,$choices);
        startAiRequest($db,$attempt,'moderation_started');
        // One batch classifies the bounded input context and proposed dialogue together.
        $moderation=$transport->post('moderations',['model'=>'omni-moderation-latest','input'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\nA: ".$proposal['first']."\nB: ".$proposal['second']],$attempt['expires']);
        if(!is_array($moderation['results']??null)||count($moderation['results'])!==1||($moderation['results'][0]['flagged']??null)!==false)throw new AiGuard('moderation_rejected');
        $planner=new PreparedAiPlanner($pair,$proposal,$choices,$attempt['id']);
        $result=simulateDay($db,$period,$planner,$now,true,$attempt['id'],$usage);
        finishAi($db,$attempt,'applied',null);
        return $result+['provider'=>'openai','input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'estimated_microusd'=>$usage['cost_microusd']];
    }catch(\Throwable $error){
        $query=$db->prepare("SELECT id,status,interaction_count FROM simulation_runs WHERE id=? AND status='completed'");$query->execute([$attempt['id']]);
        if($completed=$query->fetch())return $completed+['provider'=>'openai','replayed'=>false,'accounting_pending'=>true];
        $reason=$error instanceof AiGuard?$error->reason:'apply_rejected';
        $unknown=$usage===null;
        $code=$unknown?($reason==='usage_unknown'?'usage_unknown':'transport_uncertain'):(in_array($reason,['invalid_proposal','moderation_rejected'],true)?$reason:'apply_rejected');
        // Raw errors, vendor bodies, prompts and credentials are never persisted or logged.
        failedAiRun($db,$attempt,$usage);
        finishAi($db,$attempt,$unknown?'uncertain':'rejected',$code);
        return ['id'=>$attempt['id'],'status'=>'failed','provider'=>'openai','interaction_count'=>0,'replayed'=>false,'error_code'=>$code];
    }
}
