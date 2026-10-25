<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director_reconciliation.php';
try{
    // Parse explicitly: reject duplicate/unknown options and missing values instead of
    // letting getopt stop early or silently turn a requested resolution into a report.
    $flags=['fetch','resolve','confirm-final-billing'];$values=['database','attempt','inspection','inspection-sha256','review','microusd','evidence-file'];$o=[];
    foreach(array_slice($argv??[],1) as $arg){
        if(!preg_match('/\A--([a-z-]+)(?:=(.*))?\z/s',$arg,$m)||array_key_exists($m[1],$o))throw new LittleSparks\AiGuard('configuration_invalid');
        $name=$m[1];$value=$m[2]??null;
        if(in_array($name,$flags,true)&&$value===null)$o[$name]=true;
        elseif(in_array($name,$values,true)&&is_string($value)&&$value!=='')$o[$name]=$value;
        else throw new LittleSparks\AiGuard('configuration_invalid');
    }
    if(!isset($o['database'],$o['attempt'])||(isset($o['fetch'])&&isset($o['resolve'])))throw new LittleSparks\AiGuard('configuration_invalid');
    $allowed=isset($o['fetch'])?['database','attempt','fetch','inspection']:(isset($o['resolve'])?array_merge(['database','attempt','resolve','confirm-final-billing'],$values):['database','attempt']);
    if(array_diff(array_keys($o),$allowed))throw new LittleSparks\AiGuard('configuration_invalid');
    $db=LittleSparks\connect($o['database']);
    if(isset($o['fetch'])){
        if(!isset($o['inspection']))throw new LittleSparks\AiGuard('configuration_invalid');
        $result=LittleSparks\inspectDirectorProvider($db,$o['attempt'],$o['inspection']);$exit=$result['ready_for_billing_review']?0:1;
    }elseif(isset($o['resolve'])){
        foreach(['inspection','inspection-sha256','review','microusd','evidence-file','confirm-final-billing'] as $required)if(!isset($o[$required]))throw new LittleSparks\AiGuard('configuration_invalid');
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/',$o['microusd'])||(int)$o['microusd']>100000000)throw new LittleSparks\AiGuard('configuration_invalid');
        $file=LittleSparks\databasePath($o['evidence-file']);
        if(filesize($file)<1||filesize($file)>1048576)throw new LittleSparks\AiGuard('configuration_invalid');
        $result=LittleSparks\resolveDirectorInspection($db,$o['attempt'],$o['inspection'],$o['inspection-sha256'],$o['review'],(int)$o['microusd'],hash_file('sha256',$file));$exit=($result['current_state']??$result['state'])==='rejected'?0:1;
    }else{
        $db->exec('PRAGMA query_only=ON');$db->exec('BEGIN');$result=LittleSparks\directorAttemptInspection($db,$o['attempt']);$db->exec('COMMIT');$exit=0;
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";exit($exit);
}catch(Throwable $e){
    fwrite(STDERR,json_encode(['resolved'=>false,'reason'=>$e instanceof LittleSparks\AiGuard?$e->reason:'reconciliation_refused','generation_requests'=>0],JSON_THROW_ON_ERROR)."\n");exit(1);
}
