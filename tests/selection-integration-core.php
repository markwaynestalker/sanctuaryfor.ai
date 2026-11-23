<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/activity.php';
use function LittleSparks\{connect,reserveSelection,startSelection,finishSelection,aiCommittedTotal,selectionReservedRequests,assertSimulationLease,reserveAi};
$db=connect($argv[1]);$now=time();if(86400-$now%86400<120)$now-=180;
$db->prepare("INSERT INTO simulation_opportunities(id,scheduled_at,day,resident_a,resident_b,outcome,expires_at,created_at) VALUES (?, ?, ?, NULL,NULL,'pending',?,?)")->execute(['op:test',gmdate('Y-m-d\TH:i:s\Z',$now),gmdate('Y-m-d',$now),$now+300,gmdate('Y-m-d\TH:i:s\Z',$now)]);
$cfg=['daily_requests'=>10,'monthly_cap'=>1000000,'max_output'=>512,'input_rate'=>125000,'output_rate'=>500000,'lease_seconds'=>90];
$r=reserveSelection($db,'op:test',$cfg,$now);
if(aiCommittedTotal($db,gmdate('Y-m',$now))!==$r['reserved']||selectionReservedRequests($db,gmdate('Y-m-d',$now))!==1)throw new RuntimeException('selection hold absent from actual accounting');
try{assertSimulationLease($db);throw new RuntimeException('selection lease bypassed');}catch(LittleSparks\Problem $e){}
try{reserveAi($db,'other',[],[],$now);throw new RuntimeException('AI reservation bypassed');}catch(LittleSparks\AiGuard $e){if($e->reason!=='simulation_busy')throw $e;}
startSelection($db,$r,$now);finishSelection($db,$r,true,$now);
if(aiCommittedTotal($db,gmdate('Y-m',$now))!==$r['reserved'])throw new RuntimeException('completed selection hold lost');
echo "PASS actual V6 selection hold and existing admission integration\n";