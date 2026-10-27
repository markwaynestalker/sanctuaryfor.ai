<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/bulletin-test-support.php';
$db=LittleSparks\connect($argv[1]);$r=$db->query('SELECT * FROM director_registry LIMIT 1')->fetch();
foreach(['DIRECTOR_RUNTIME'=>'responses','DIRECTOR_AGENT_ID'=>$r['agent_id'],'DIRECTOR_CONFIG_SHA256'=>$r['fingerprint'],'AI_ENABLED'=>'1','DIRECTOR_ENABLED'=>'1','SIMULATION_ENABLED'=>'1','AI_PRICING_CONFIRMED'=>'2026-10-24-standard-v1','AI_DAILY_REQUESTS'=>'200','AI_MONTHLY_MICROUSD'=>'1000000','BULLETIN_ENABLED'=>'1','BULLETIN_ACTIVE_MAX'=>'1'] as $k=>$v)putenv('LITTLE_SPARKS_'.$k.'='.$v);
try{echo json_encode(LittleSparks\simulateDirectorOpportunity($db,gmdate('Y-m-d\TH:i:00\Z',time()-(int)$argv[2]),true,new BulletinResponses()),JSON_THROW_ON_ERROR);}
catch(LittleSparks\Problem $e){if($e->status!==409)throw $e;echo json_encode(['status'=>'busy','http_status'=>409],JSON_THROW_ON_ERROR);}
