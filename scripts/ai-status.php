<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/ai_budget.php';
require __DIR__.'/../app/activity.php';
require __DIR__.'/../app/scheduler.php';require __DIR__.'/../app/monitoring.php';
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if(!in_array($version,[3,4,5,6],true))throw new RuntimeException('V3 or later required.');
    $db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    $day=gmdate('Y-m-d');$month=gmdate('Y-m');
    $query=$db->prepare('SELECT COALESCE(SUM(request_slots),0) AS reserved_requests,COALESCE(SUM(responses_started+moderation_started),0) AS started_requests FROM ai_attempts WHERE day=?');$query->execute([$day]);$daily=$query->fetch();
    $query=$db->prepare('SELECT COALESCE(SUM(COALESCE(estimated_microusd,reserved_microusd)),0) FROM ai_attempts WHERE month=?');$query->execute([$month]);
    try{$configured=LittleSparks\aiMonthlyBudget();}catch(LittleSparks\AiGuard){$configured=null;}
    try{$dailyLimit=LittleSparks\aiDailyRequestLimit();}catch(LittleSparks\AiGuard){$dailyLimit=null;}
    $report=['day_utc'=>$day,'month_utc'=>$month,'enabled'=>getenv('LITTLE_SPARKS_AI_ENABLED')==='1','manual_paid_invocation_required'=>true,'daily'=>$daily,'configured_daily_requests'=>$dailyLimit,'monthly_committed_microusd'=>(int)$query->fetchColumn(),'maximum_monthly_microusd'=>1000000,'configured_monthly_microusd'=>$configured,
        'unresolved_attempts'=>(int)$db->query("SELECT COUNT(*) FROM ai_attempts WHERE state IN ('reserved','uncertain','settled')")->fetchColumn(),
        'lease'=>$db->query('SELECT owner IS NOT NULL AS held,expires_at FROM ai_lease WHERE singleton=1')->fetch(),
        'recent'=>$db->query('SELECT id,period,model,state,input_tokens,output_tokens,estimated_microusd,reserved_microusd,error_code FROM ai_attempts ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll()];
    if($version>=4){
        try{$interval=LittleSparks\simulationInterval();}catch(LittleSparks\AiGuard){$interval=null;}
        try{$activityLimits=LittleSparks\activityConfiguration();}catch(LittleSparks\Problem){$activityLimits=null;}
        $q=$db->prepare('SELECT outcome,COUNT(*) AS count FROM simulation_opportunities WHERE day=? GROUP BY outcome');$q->execute([$day]);
        $report['activity']=['enabled'=>getenv('LITTLE_SPARKS_SIMULATION_ENABLED')==='1','interval_minutes'=>$interval,'maximum_daily_opportunities'=>$activityLimits['daily_opportunities']??null,'maximum_daily_resident_turns'=>$activityLimits['resident_daily']??null,'limits'=>$activityLimits,'today'=>$q->fetchAll(),
            'pending'=>$db->query("SELECT id,scheduled_at,expires_at FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetch()?:null];
        $report['village']=LittleSparks\villageMonitoring($db,$day);
        try{$scheduler=LittleSparks\schedulerConfiguration();}catch(LittleSparks\Problem){$scheduler=null;}
        $report['scheduler']=['configuration'=>$scheduler,'systemd_status'=>'not_inspected','installed_or_running'=>null];
    }
    if($version>=5){
        try{$memoryConfig=LittleSparks\memoryConfiguration();}catch(LittleSparks\Problem){$memoryConfig=null;}
        $report['memory']=['configuration'=>$memoryConfig,'stories'=>(int)$db->query('SELECT COUNT(*) FROM memory_stories')->fetchColumn(),
            'receipts'=>(int)$db->query('SELECT COUNT(*) FROM memory_receipts')->fetchColumn(),'friendships'=>(int)$db->query('SELECT COUNT(*) FROM friendship_identities')->fetchColumn()];
    }
    $q=$db->prepare('SELECT COALESCE(SUM(estimated_microusd),0) AS known_estimated_microusd,COALESCE(SUM(CASE WHEN estimated_microusd IS NULL THEN reserved_microusd ELSE 0 END),0) AS unknown_committed_microusd FROM ai_attempts WHERE month=?');$q->execute([$month]);
    $report['monthly_billing']=['values_are_application_estimates'=>true]+$q->fetch();
    $report['recent_provider_failures']=$db->query("SELECT state,error_code,created_at FROM ai_attempts WHERE error_code IS NOT NULL OR state IN ('rejected','uncertain') ORDER BY created_at DESC,id DESC LIMIT 10")->fetchAll();
    if($version>=6){
        require_once __DIR__.'/../app/director.php';
        $q=$db->prepare("SELECT COALESCE(SUM(request_slots-2),0) AS extra_slots,COALESCE(SUM(CASE WHEN runtime='agents' THEN started_requests ELSE 0 END),0) AS managed_started FROM director_attempts WHERE day=?");$q->execute([$day]);$extra=$q->fetch();
        $report['daily']['reserved_requests']=(int)$report['daily']['reserved_requests']+(int)$extra['extra_slots'];
        $report['daily']['started_requests']=(int)$report['daily']['started_requests']+(int)$extra['managed_started'];
        $report['director']=['managed_live_admission'=>false,'reason'=>'managed_accounting_not_verified','admission'=>LittleSparks\managedLiveAdmission(),
            'http_audit'=>$db->query('SELECT attempt_id,sequence,kind,outcome,http_status,request_id,started_at,finished_at FROM director_http_calls ORDER BY rowid DESC LIMIT 20')->fetchAll(),
            'usage_is_final_billing'=>false,
            'staging_permit'=>$db->query('SELECT permit_id,expires_at,allocation_microusd,http_slots,consumed_at FROM director_staging_permits LIMIT 1')->fetch()?:null,
            'managed_outcomes'=>$db->query('SELECT attempt_id,session_id,turn_id,status,created_at FROM director_managed_outcomes ORDER BY id DESC LIMIT 10')->fetchAll(),
            'observations'=>$db->query('SELECT attempt_id,turn_id,input_tokens,output_tokens,cached_tokens,reasoning_tokens,estimated_microusd,status,observed_at FROM director_usage_observations ORDER BY id DESC LIMIT 10')->fetchAll(),
            'billing_reviews'=>$db->query('SELECT review_id,attempt_id,amount_microusd,commitment_microusd,outcome,reviewed_at FROM director_billing_reviews ORDER BY rowid DESC LIMIT 10')->fetchAll(),
            'recent'=>$db->query('SELECT id,period,session_id,runtime,contract_version,context_sha256,request_slots,started_requests,input_tokens,output_tokens,estimated_microusd,reserved_microusd,state FROM director_attempts ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll()];
    }
    $db->commit();echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable){fwrite(STDERR,"AI status unavailable. Check the approved private V3/V4 database path.\n");exit(1);}
