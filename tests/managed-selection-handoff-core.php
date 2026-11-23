<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/activity.php';require_once __DIR__.'/../app/managed_selection_accounting.php';
use function LittleSparks\{connect,reserveSelection,startManagedSelectionHttp,finishManagedSelectionHttp,bindManagedSelectionSession,finishSelection,reserveAi,aiCommittedTotal,selectionReservedRequests};
function ck(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);}
$db=connect($argv[1]);$now=time();if(86400-$now%86400<120)$now+=600;$day=gmdate('Y-m-d',$now);$month=gmdate('Y-m',$now);$at=gmdate('Y-m-d\TH:i:s\Z',$now);$pair=[];
foreach(['a','b'] as $ch){$id=str_repeat($ch,32);$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Test '.$ch,'mint','orb','curious',$at,hash('sha256','owner'.$id),$id,hash('sha256','request'.$id),hash('sha256','token'.$id)]);$db->prepare("INSERT INTO resident_states VALUES (?,'available','commons',?)")->execute([$id,$at]);$db->prepare('INSERT INTO resident_identities VALUES (?,?,?,?,?)')->execute([$id,'Identity','mint',1,$at]);$pair[]=['id'=>$id,'personality'=>'curious'];}
$db->prepare("INSERT INTO simulation_opportunities(id,scheduled_at,day,resident_a,resident_b,outcome,expires_at,created_at) VALUES (?, ?, ?, NULL,NULL,'pending',?,?)")->execute(['op:managed-handoff',$at,$day,$now+300,$at]);
$fingerprint=str_repeat('f',64);$sid=str_repeat('c',32);$db->prepare('INSERT INTO director_registry VALUES (?,?,?,?,?,?)')->execute([$fingerprint,'agent_test','gpt-6-luna','test','{}',$at]);
$scope=LittleSparks\selectionDirectorScope($db,$pair,'agents','sanctuary.director.v1',null);
$db->prepare("INSERT INTO director_sessions(id,fingerprint,scope,agent_id,runtime,state,created_at,updated_at) VALUES (?,?,?,?,?,'new',?,?)")->execute([$sid,$fingerprint,$scope,'agent_test','agents',$at,$at]);
$cfg=['daily_requests'=>16,'monthly_cap'=>1000000,'max_output'=>512,'input_rate'=>125000,'output_rate'=>500000,'lease_seconds'=>60,'model'=>'gpt-6-luna','director_runtime'=>'agents','director_slots'=>8,'director_session'=>$sid,'director_context'=>str_repeat('a',64)];
$r=reserveSelection($db,'op:managed-handoff',$cfg,$now,$cfg,true);$held=aiCommittedTotal($db,$month);
$dispatch=function(string $method,string $path,?string $kind=null)use($db,$r,$now):void{$i=startManagedSelectionHttp($db,$r,$method,$path,$kind,$now);finishManagedSelectionHttp($db,$r,$i['sequence'],['status'=>200,'succeeded'=>true],$now);};
$dispatch('POST','agents/sessions');bindManagedSelectionSession($db,$r,'provider_s1',$now);$dispatch('GET','agents/sessions/provider_s1');$dispatch('GET','agents/sessions/provider_s1/turns?limit=1&order=desc','turns_1');$dispatch('GET','agents/sessions/provider_s1/turns/turn1/items?limit=20&order=asc');finishSelection($db,$r,true,$now);
$cfg['selection_handoff']=$r['id'];$a=reserveAi($db,'op:managed-handoff',$pair,$cfg,$now);
ck(aiCommittedTotal($db,$month)===$held,'managed handoff must count each commitment once');
ck(selectionReservedRequests($db,$day)===8,'selection keeps eight slots after conversation transfer');
$q=$db->prepare('SELECT request_slots FROM director_attempts WHERE id=?');$q->execute([$a['id']]);ck((int)$q->fetchColumn()===8,'existing managed conversation retains eight slots');
ck(!$db->query('PRAGMA foreign_key_check')->fetch()&&$db->query('PRAGMA integrity_check')->fetchColumn()==='ok','integrity');
echo "PASS managed selection-to-conversation handoff retains both commitments without double-counting\n";