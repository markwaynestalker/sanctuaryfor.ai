<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/director_billing_settlement.php';
try{
    $values=['database','attempt','snapshot-sha256','review','microusd','evidence-file'];$flags=['apply','confirm-final-billing','allow-reservation-reduction'];$o=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/\A--([a-z0-9-]+)(?:=(.*))?\z/s',$arg,$m)||isset($o[$m[1]]))throw new LittleSparks\AiGuard('configuration_invalid');
        $name=$m[1];$value=$m[2]??null;
        if(in_array($name,$flags,true)&&$value===null)$o[$name]=true;
        elseif(in_array($name,$values,true)&&is_string($value)&&$value!=='')$o[$name]=$value;
        else throw new LittleSparks\AiGuard('configuration_invalid');
    }
    if(!isset($o['database'],$o['attempt']))throw new LittleSparks\AiGuard('configuration_invalid');
    $db=LittleSparks\connect($o['database']);
    if(!isset($o['apply'])){
        if(array_diff(array_keys($o),['database','attempt']))throw new LittleSparks\AiGuard('configuration_invalid');
        $db->exec('PRAGMA query_only=ON');$db->beginTransaction();$local=LittleSparks\directorAttemptInspection($db,$o['attempt']);
        $a=$local['records']['ai_attempts'][0];$observed=0;
        foreach($local['records']['director_usage_observations'] as $row)$observed=max($observed,(int)($row['estimated_microusd']??0));
        $result=['applied'=>false,'attempt_id'=>$o['attempt'],'snapshot_sha256'=>$local['snapshot_sha256'],'month'=>$a['month'],
            'reserved_microusd'=>(int)$a['reserved_microusd'],'maximum_observed_estimate_microusd'=>$observed,
            'requires_attempt_attributed_final_billing'=>true,'usage_estimate_is_final_billing'=>false,'http_requests'=>0];$db->commit();
    }else{
        foreach(array_merge($values,$flags) as $key)if(!isset($o[$key]))throw new LittleSparks\AiGuard('configuration_invalid');
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/',$o['microusd'])||(int)$o['microusd']>100000000)throw new LittleSparks\AiGuard('configuration_invalid');
        $file=LittleSparks\databasePath($o['evidence-file']);
        if(!is_file($file)||filesize($file)<1||filesize($file)>1048576)throw new LittleSparks\AiGuard('configuration_invalid');
        $result=LittleSparks\settleDirectorBilling($db,$o['attempt'],$o['snapshot-sha256'],$o['review'],(int)$o['microusd'],hash_file('sha256',$file))+['applied'=>true,'http_requests'=>0];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $e){fwrite(STDERR,json_encode(['applied'=>false,'reason'=>$e instanceof LittleSparks\AiGuard?$e->reason:'settlement_refused','http_requests'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);}
