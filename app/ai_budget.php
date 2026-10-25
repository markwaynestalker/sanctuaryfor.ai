<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
use RuntimeException;

final class AiGuard extends RuntimeException
{
    public function __construct(public readonly string $reason){parent::__construct('AI request blocked: '.$reason);}
}

const AI_PRICING_VERSION='2026-10-24-standard-v1';
const AI_INPUT_CEILING=32768;
const AI_BODY_BYTES=16384;
const AI_MONTHLY_CAP=1000000; // integer micro-USD: exactly $1.00

function aiDailyRequestLimit(): int
{
    $value=getenv('LITTLE_SPARKS_AI_DAILY_REQUESTS');
    if($value===false)return 2;
    // Canonical unsigned decimal only; empty, signed and oversized values fail closed.
    if(!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/',$value) || (int)$value>200)throw new AiGuard('configuration_invalid');
    return (int)$value;
}

function aiConfiguration(int $now): array
{
    if(getenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED')!==AI_PRICING_VERSION
        || gmdate('Y-m-d',$now)<'2026-10-24' || gmdate('Y-m-d',$now)>'2026-11-23')throw new AiGuard('pricing_unverified');
    // Verified standard family rates, using the higher cache-write input rate conservatively.
    // https://developers.openai.com/api/docs/pricing (checked 2026-10-24).
    $models=['gpt-6-luna'=>[125000,500000,'none'],'gpt-6.1-sol'=>[2500000,10000000,'low']];
    $model=getenv('LITTLE_SPARKS_AI_MODEL');$model=$model===false?'gpt-6-luna':$model;
    if(!isset($models[$model]))throw new AiGuard('model_unpriced');
    $output=aiOutputTokenLimit();$monthly=aiMonthlyBudget();
    [$inputRate,$outputRate,$effort]=$models[$model];
    return ['model'=>$model,'input_rate'=>$inputRate,'output_rate'=>$outputRate,'effort'=>$effort,'max_output'=>(int)$output,'monthly_cap'=>(int)$monthly,'daily_requests'=>aiDailyRequestLimit()];
}

function aiOutputTokenLimit():int
{
    $value=getenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS');$value=$value===false?'512':$value;
    if(!preg_match('/\A[1-9][0-9]{2}\z/',$value)||(int)$value<128||(int)$value>512)throw new AiGuard('configuration_invalid');
    return (int)$value;
}
function aiMonthlyBudget():int
{
    $value=getenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD');$value=$value===false?'1000000':$value;
    if(!preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/',$value)||(int)$value>AI_MONTHLY_CAP)throw new AiGuard('configuration_invalid');
    return (int)$value;
}

function estimatedAiCost(int $input,int $output,int $inputRate,int $outputRate): int
{
    return intdiv($input*$inputRate+$output*$outputRate+999999,1000000);
}

function reserveAi(PDO $db,string $period,array $pair,array $config,int $now): array
{
    if(identityEnabled($db))throw new AiGuard('migration_required');
    if(!in_array((int)$db->query('PRAGMA user_version')->fetchColumn(),[3,4,5],true))throw new AiGuard('migration_required');
    if(86400-$now%86400<90)throw new AiGuard('day_boundary');
    $db->exec('BEGIN IMMEDIATE');
    try{
        if(identityEnabled($db))throw new AiGuard('migration_required');
        $lease=$db->query('SELECT owner FROM ai_lease WHERE singleton=1')->fetchColumn();
        if($lease!==null && $lease!==false)throw new AiGuard('simulation_busy');
        if($db->query("SELECT 1 FROM ai_attempts WHERE state IN ('reserved','uncertain','settled') LIMIT 1")->fetchColumn())throw new AiGuard('accounting_unresolved');
        $query=$db->prepare('SELECT 1 FROM simulation_runs WHERE period=? UNION ALL SELECT 1 FROM ai_attempts WHERE period=?');$query->execute([$period,$period]);
        if($query->fetchColumn())throw new AiGuard('period_recorded');
        $day=gmdate('Y-m-d',$now);$month=gmdate('Y-m',$now);
        $query=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM ai_attempts WHERE day=?');$query->execute([$day]);
        if((int)$query->fetchColumn()+2>$config['daily_requests'])throw new AiGuard('daily_request_cap');
        $reserved=estimatedAiCost(AI_INPUT_CEILING,$config['max_output'],$config['input_rate'],$config['output_rate']);
        $query=$db->prepare('SELECT COALESCE(SUM(COALESCE(estimated_microusd,reserved_microusd)),0) FROM ai_attempts WHERE month=?');$query->execute([$month]);
        if((int)$query->fetchColumn()+$reserved>min(AI_MONTHLY_CAP,$config['monthly_cap']))throw new AiGuard('monthly_budget_cap');
        if((int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()>=4096)throw new AiGuard('ledger_capacity');
        $id=bin2hex(random_bytes(16));$expires=$now+60;
        $db->prepare("INSERT INTO ai_attempts(id,period,day,month,resident_a,resident_b,model,pricing_version,input_rate,output_rate,reserved_microusd,state,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'reserved',?)")
            ->execute([$id,$period,$day,$month,$pair[0]['id'],$pair[1]['id'],$config['model'],AI_PRICING_VERSION,$config['input_rate'],$config['output_rate'],$reserved,gmdate('Y-m-d\TH:i:s\Z',$now)]);
        $db->prepare('UPDATE ai_lease SET owner=?,expires_at=? WHERE singleton=1')->execute([$id,$expires]);
        $db->exec('COMMIT');return ['id'=>$id,'day'=>$day,'month'=>$month,'expires'=>$expires,'reserved'=>$reserved];
    }catch(\Throwable $error){$db->exec('ROLLBACK');throw $error;}
}

function startAiRequest(PDO $db,array $attempt,string $stage): void
{
    if(!in_array($stage,['responses_started','moderation_started'],true))throw new AiGuard('configuration_invalid');
    $db->exec('BEGIN IMMEDIATE');
    try{
        assertSimulationLease($db,$attempt['id']);
        if(gmdate('Y-m-d')!==$attempt['day'])throw new AiGuard('day_boundary');
        $query=$db->prepare("UPDATE ai_attempts SET $stage=1 WHERE id=? AND $stage=0");$query->execute([$attempt['id']]);
        if($query->rowCount()!==1)throw new AiGuard('request_already_started');
        $db->exec('COMMIT');
    }catch(\Throwable $error){$db->exec('ROLLBACK');throw $error;}
}

function recordAiUsage(PDO $db,array $attempt,array $response,array $config): array
{
    $usage=$response['usage']??null;$model=$response['model']??'';
    $sameModel=is_string($model)&&($model===$config['model'] || preg_match('/\A'.preg_quote($config['model'],'/').'-\d{4}-\d{2}-\d{2}\z/',$model));
    if(!$sameModel || ($response['service_tier']??null)!=='default' || !is_array($usage)
        || !is_int($usage['input_tokens']??null) || !is_int($usage['output_tokens']??null)
        || $usage['input_tokens']<=0 || $usage['input_tokens']>AI_INPUT_CEILING || $usage['output_tokens']<=0 || $usage['output_tokens']>$config['max_output']
        || !is_int($usage['total_tokens']??null) || $usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens'])throw new AiGuard('usage_unknown');
    $cost=estimatedAiCost($usage['input_tokens'],$usage['output_tokens'],$config['input_rate'],$config['output_rate']);
    if($cost>$attempt['reserved'])throw new AiGuard('usage_unknown');
    $db->prepare("UPDATE ai_attempts SET input_tokens=?,output_tokens=?,estimated_microusd=?,state='settled' WHERE id=?")
        ->execute([$usage['input_tokens'],$usage['output_tokens'],$cost,$attempt['id']]);
    return ['input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'cost_microusd'=>$cost];
}

function finishAi(PDO $db,array $attempt,string $state,?string $error): void
{
    if(!in_array($state,['uncertain','applied','rejected'],true))throw new AiGuard('configuration_invalid');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $db->prepare('UPDATE ai_attempts SET state=?,error_code=?,finished_at=? WHERE id=?')->execute([$state,$error,gmdate('Y-m-d\TH:i:s\Z'),$attempt['id']]);
        $db->prepare('UPDATE ai_lease SET owner=NULL,expires_at=0 WHERE owner=?')->execute([$attempt['id']]);
        $db->exec('COMMIT');
    }catch(\Throwable $failure){$db->exec('ROLLBACK');throw $failure;}
}
