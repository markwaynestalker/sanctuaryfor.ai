<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Caller holds a query-only snapshot. No probes, credentials or recovery writes. */
function operationalStatus(PDO $db,int $now):array
{
    $q=$db->query("SELECT value FROM settings WHERE name='scheduler:last_tick'");$tick=$q->fetchColumn();$tick=is_string($tick)?json_decode($tick,true):null;
    $out=['scheduler'=>['configured'=>false,'operational'=>null,'last_tick'=>$tick,'systemd_state'=>'not_inspected'],
        'director_configured'=>false,'connection'=>['state'=>'unknown','observed_at'=>null,'live_probe'=>false],
        'future_opportunities_eligible'=>false,'blocking_reasons'=>[],'historical_uncertainty_requires_intervention'=>false];
    $out['last_successful_ai_conversation']=$db->query("SELECT c.id,c.created_at FROM conversations c JOIN ai_conversations a ON a.conversation_id=c.id JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed' ORDER BY c.created_at DESC,c.id DESC LIMIT 1")->fetch()?:null;
    $out['last_failed_opportunity']=$db->query("SELECT o.id,o.finished_at,COALESCE(s.value,a.error_code,'simulation_failed') AS failure_category
        FROM simulation_opportunities o LEFT JOIN ai_attempts a ON a.period=o.id LEFT JOIN settings s ON s.name='activity_failure:'||o.id
        WHERE o.outcome='failed' ORDER BY o.finished_at DESC,o.id DESC LIMIT 1")->fetch()?:null;
    $out['unresolved_attempts']=(int)$db->query("SELECT COUNT(*) FROM ai_attempts WHERE state IN ('reserved','uncertain','settled')")->fetchColumn();
    try{
        $cfg=schedulerConfiguration();$out['scheduler']['configured']=$cfg['enabled']&&$cfg['simulation_enabled'];
        $out['scheduler']['operational']=$out['scheduler']['configured']&&is_array($tick)&&is_int($tick['at']??null)&&$tick['at']<=$now&&$tick['at']>=$now-180;
        if(!$cfg['enabled']||!$cfg['simulation_enabled'])$out['blocking_reasons'][]='scheduler_paused';
        if(!$cfg['ai_enabled']||!$cfg['allow_paid'])$out['blocking_reasons'][]='paid_ai_disabled';
        $pricing=aiConfiguration($now);$interval=simulationInterval();$limits=activityConfiguration();memoryConfiguration();
        $slots=2;$reserve=estimatedAiCost(AI_INPUT_CEILING,$pricing['max_output'],$pricing['input_rate'],$pricing['output_rate']);
        if(directorEnabled($db)){
            require_once __DIR__.'/director.php';$director=directorConfiguration();$registered=registeredDirector($db,$director);
            $out['director_configured']=$registered['model']===$pricing['model'];
            if(!$director['enabled'])$out['blocking_reasons'][]='director_disabled';
            if(!$out['director_configured'])$out['blocking_reasons'][]='model_unpriced';
            if($director['runtime']==='agents'){$slots=8;$reserve=max($reserve,10000);}
            if($db->query('SELECT 1 FROM director_staging_permits LIMIT 1')->fetchColumn())$out['blocking_reasons'][]='experimental_permit_consumed';
            $http=$db->query('SELECT http_status,outcome,finished_at FROM director_http_calls ORDER BY rowid DESC LIMIT 1')->fetch();
            if($http&&$http['finished_at']!==null){
                $out['connection']['observed_at']=$http['finished_at'];
                if(strtotime($http['finished_at'])>=$now-max(180,$interval*120))$out['connection']['state']=$http['outcome']==='succeeded'&&$http['http_status']>=200&&$http['http_status']<300?'available':'unavailable';
            }
        }
        $day=gmdate('Y-m-d',$now);$month=gmdate('Y-m',$now);
        $q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM ai_attempts WHERE day=?');$q->execute([$day]);$used=(int)$q->fetchColumn()+directorAccessReservedRequests($db,$day);
        if(directorEnabled($db)){$q=$db->prepare('SELECT COALESCE(SUM(request_slots-2),0) FROM director_attempts WHERE day=?');$q->execute([$day]);$used+=(int)$q->fetchColumn();}
        $committed=aiCommittedTotal($db,$month);
        $out['allowance']=['day_utc'=>$day,'month_utc'=>$month,'daily_limit'=>$pricing['daily_requests'],'reserved_requests'=>$used,'remaining_requests'=>max(0,$pricing['daily_requests']-$used),
            'monthly_limit_microusd'=>$pricing['monthly_cap'],'committed_microusd'=>$committed,'remaining_microusd'=>max(0,$pricing['monthly_cap']-$committed),'next_encounter_slots'=>$slots,'next_encounter_commitment_microusd'=>$reserve];
        if($used+$slots>$pricing['daily_requests'])$out['blocking_reasons'][]='daily_request_cap';
        if($committed+$reserve>$pricing['monthly_cap'])$out['blocking_reasons'][]='monthly_budget_cap';
        $lease=$db->query('SELECT owner,expires_at FROM ai_lease WHERE singleton=1')->fetch();
        if($lease['owner']!==null&&(int)$lease['expires_at']>$now)$out['blocking_reasons'][]='active_encounter';
        $q=$db->prepare("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' AND expires_at>? LIMIT 1");$q->execute([$now]);if($q->fetchColumn())$out['blocking_reasons'][]='active_opportunity';
        $q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=?');$q->execute([$day]);if((int)$q->fetchColumn()>=$limits['daily_opportunities'])$out['blocking_reasons'][]='activity_daily_cap';
        $pair=selectActivityResidents($db,$day,$limits,$now);$out['eligible_resident_count']=count($pair);
        if(count($pair)<2)$out['blocking_reasons'][]='residents_resting_or_unavailable';
        if(86400-$now%86400<90)$out['blocking_reasons'][]='day_boundary';
        $out['next_check_utc']=dueOpportunity($now);if(activityResultOrNull($db,opportunityId($out['next_check_utc'])))$out['next_check_utc']=gmdate('Y-m-d\TH:i:00\Z',strtotime($out['next_check_utc'])+$interval*60);
        $out['future_opportunities_eligible']=!$out['blocking_reasons'];
    }catch(\Throwable){$out['blocking_reasons'][]='configuration_invalid';}
    $out['eligibility_scope']='Current configuration and retained commitments; provider availability and future capacity are not guaranteed.';
    return $out;
}
