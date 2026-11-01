<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_broker.php';
try{
    $db=LittleSparks\connect($argv[1]);$read=LittleSparks\connect($argv[1]);$read->exec('PRAGMA query_only=ON');
    $policy=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);$job=json_decode(file_get_contents($argv[3]),true,16,JSON_THROW_ON_ERROR);$release=$job['release'];
    $base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($policy,90000,$release),'op'=>'begin_generation'];
    $result=LittleSparks\gazetteBrokerHandle($db,$read,$base,$policy,90000,$release,64011,time(),true,null,$job);
    echo json_encode(['admitted'=>$result['status']==='dispatch_generation']);
}catch(Throwable){echo '{"admitted":false}';}
