<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director_config.php';
try{
    $options=getopt('',['agent-file:']);$export=LittleSparks\readDirectorExport((string)($options['agent-file']??''));
    $rates=['gpt-6-luna'=>[125000,500000],'gpt-6.1-sol'=>[2500000,10000000]][$export['model']];
    $prototype=LittleSparks\managedDirectorPrototype($export,['contract'=>'sanctuary.director.v1'],['none'=>null]);
    echo json_encode(['offline_only'=>true,'provider_requests'=>0,'account_access_verified'=>false,'agent_id'=>$export['id'],'model'=>$export['model'],
        'responses'=>['http_slots'=>2,'reserved_microusd'=>LittleSparks\estimatedAiCost(LittleSparks\AI_INPUT_CEILING,LittleSparks\aiOutputTokenLimit(),...$rates),'pricing_version'=>LittleSparks\AI_PRICING_VERSION],
        'managed'=>['admitted'=>false,'environment'=>$prototype['environment'],'session_spend_limit_cents'=>1,'http_slots_new_session'=>4,'http_slots_reused_session'=>5,
            'blockers'=>['Aggregate internal inference/output-token ceiling and cent-limit overshoot semantics not established','Current API account access and measured billing unverified','Best-effort usage is not final settlement; prototype holds reservations']],
        'maximum_monthly_microusd'=>LittleSparks\AI_MONTHLY_CAP],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable){fwrite(STDERR,"Offline compatibility check refused. Check the private agent export and configuration. No provider request was made.\n");exit(1);}
