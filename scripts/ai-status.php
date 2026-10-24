<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/ai_budget.php';
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==3)throw new RuntimeException('V3 required.');
    $day=gmdate('Y-m-d');$month=gmdate('Y-m');
    $query=$db->prepare('SELECT COALESCE(SUM(request_slots),0) AS reserved_requests,COALESCE(SUM(responses_started+moderation_started),0) AS started_requests FROM ai_attempts WHERE day=?');$query->execute([$day]);$daily=$query->fetch();
    $query=$db->prepare('SELECT COALESCE(SUM(COALESCE(estimated_microusd,reserved_microusd)),0) FROM ai_attempts WHERE month=?');$query->execute([$month]);
    $configured=getenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD');$configured=$configured===false?'1000000':$configured;
    $configured=ctype_digit($configured)&&strlen($configured)<=7&&(int)$configured<=1000000?(int)$configured:null;
    try{$dailyLimit=LittleSparks\aiDailyRequestLimit();}catch(LittleSparks\AiGuard){$dailyLimit=null;}
    $report=['day_utc'=>$day,'month_utc'=>$month,'enabled'=>getenv('LITTLE_SPARKS_AI_ENABLED')==='1','manual_paid_invocation_required'=>true,'daily'=>$daily,'configured_daily_requests'=>$dailyLimit,'monthly_committed_microusd'=>(int)$query->fetchColumn(),'maximum_monthly_microusd'=>1000000,'configured_monthly_microusd'=>$configured,
        'unresolved_attempts'=>(int)$db->query("SELECT COUNT(*) FROM ai_attempts WHERE state IN ('reserved','uncertain','settled')")->fetchColumn(),
        'lease'=>$db->query('SELECT owner IS NOT NULL AS held,expires_at FROM ai_lease WHERE singleton=1')->fetch(),
        'recent'=>$db->query('SELECT id,period,model,state,input_tokens,output_tokens,estimated_microusd,reserved_microusd,error_code FROM ai_attempts ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll()];
    echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable){fwrite(STDERR,"AI status unavailable. Check the approved private V3 database path.\n");exit(1);}
