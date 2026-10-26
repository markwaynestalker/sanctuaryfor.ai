<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$processStarted=time();
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/scheduler.php';
$previousKey=getenv('OPENAI_API_KEY');
try{
    // Configuration must fit the installed 50-second unit; retain cleanup fencing.
    define('LITTLE_SPARKS_SCHEDULED_DEADLINE',$processStarted+LittleSparks\directorExecutionConfiguration()['run_seconds']);
    $options=getopt('',['check']);$cfg=LittleSparks\schedulerConfiguration();
    // Reject invalid bounds before any credential access, claim or HTTP dispatch.
    $interval=LittleSparks\simulationInterval();LittleSparks\activityConfiguration();LittleSparks\memoryConfiguration();LittleSparks\aiDailyRequestLimit();LittleSparks\aiMonthlyBudget();LittleSparks\aiOutputTokenLimit();LittleSparks\replayCooldownSeconds();
    $enabled=$cfg['enabled']&&$cfg['simulation_enabled'];$paid=$enabled&&$cfg['ai_enabled']&&$cfg['allow_paid'];
    if(array_key_exists('check',$options)){
        if($paid)LittleSparks\aiConfiguration(time());
        echo json_encode(['status'=>$enabled?'ready':'paused','provider'=>$paid?'openai':'scripted','interval_minutes'=>$interval,
            'paid_invocation_armed'=>$paid,'check_only'=>true,'credential_loaded'=>false,'database_opened'=>false],JSON_THROW_ON_ERROR)."\n";
    }elseif(!$enabled){echo "{\"status\":\"paused\"}\n";}
    else{
        // Validate model/pricing/monthly configuration before loading a secret.
        $db=LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB'));
        $due=LittleSparks\dueOpportunity(time());$previous=LittleSparks\activityResultOrNull($db,LittleSparks\opportunityId($due));
        if($previous&&$previous['outcome']!=='pending')$result=$previous+['replayed'=>true];
        else{
            if($paid){LittleSparks\aiConfiguration(time());LittleSparks\loadScheduledCredential();}
            $result=LittleSparks\simulateOpportunity($db,$due,$paid);
        }
        LittleSparks\recordSchedulerResult($db,$result);
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
        // A recorded failed encounter is a handled outcome, not a failing service.
        // Future timer ticks evaluate only their current ENV-configured slot.
    }
}catch(Throwable){fwrite(STDERR,"Scheduled opportunity not applied. Check approved configuration, credentials, database and active claims.\n");exit(1);}
finally{putenv($previousKey===false?'OPENAI_API_KEY':'OPENAI_API_KEY='.$previousKey);}
