<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/activity.php';
use function LittleSparks\{connect,reserveSelection,startSelection,finishSelection,reserveAi,aiCommittedTotal,selectionReservedRequests};
function check(bool $v,string $message):void {if(!$v)throw new RuntimeException($message);}
function blocked(callable $f,string $reason):void {try{$f();throw new RuntimeException('unexpected acceptance');}catch(LittleSparks\AiGuard $e){check($e->reason===$reason,'wrong rejection '.$e->reason);}}
$db=connect($argv[1]);$now=time();if(86400-$now%86400<120)$now-=180;$day=gmdate('Y-m-d',$now);$at=gmdate('Y-m-d\TH:i:s\Z',$now);$month=gmdate('Y-m',$now);
$db->exec("UPDATE simulation_opportunities SET outcome='quiet' WHERE id='op:test'");
$pair=[];foreach(['a','b'] as $ch){$id=str_repeat($ch,32);$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Test '.$ch,'mint','orb','curious',$at,hash('sha256','owner'.$id),$id,hash('sha256','request'.$id),hash('sha256','token'.$id)]);$db->prepare("INSERT INTO resident_states VALUES (?,'available','commons',?)")->execute([$id,$at]);$db->prepare('INSERT INTO resident_identities VALUES (?,?,?,?,?)')->execute([$id,'Identity','mint',1,$at]);$pair[]=['id'=>$id,'personality'=>'curious'];}
$db->prepare("INSERT INTO simulation_opportunities(id,scheduled_at,day,resident_a,resident_b,outcome,expires_at,created_at) VALUES (?, ?, ?, NULL,NULL,'pending',?,?)")->execute(['op:capacity',gmdate('Y-m-d\TH:i:s\Z',$now-60),$day,$now+300,$at]);
$fingerprint=str_repeat('f',64);$sid=str_repeat('c',32);
$db->prepare('INSERT INTO director_registry VALUES (?,?,?,?,?,?)')->execute([$fingerprint,'agent_test','gpt-6-luna','test','{}',$at]);
$db->prepare("INSERT INTO director_sessions(id,fingerprint,scope,agent_id,runtime,state,created_at,updated_at) VALUES (?,?,?,?,?,'new',?,?)")->execute([$sid,$fingerprint,LittleSparks\selectionDirectorScope($db,$pair,'responses','sanctuary.director.v1',null),'agent_test','responses',$at,$at]);
$cfg=['daily_requests'=>20,'monthly_cap'=>1000000,'max_output'=>512,'input_rate'=>125000,'output_rate'=>500000,'lease_seconds'=>60,'model'=>'gpt-6-luna','director_runtime'=>'responses','director_slots'=>2,'director_session'=>$sid,'director_context'=>str_repeat('a',64)];
$before=aiCommittedTotal($db,$month);$slots=selectionReservedRequests($db,$day);
$low=$cfg;$low['daily_requests']=$slots+2;blocked(fn()=>reserveSelection($db,'op:capacity',$low,$now,$cfg),'daily_request_cap');
$low=$cfg;$low['monthly_cap']=$before;blocked(fn()=>reserveSelection($db,'op:capacity',$low,$now,$cfg),'monthly_budget_cap');
check((int)$db->query("SELECT COUNT(*) FROM selection_reservations WHERE opportunity_id='op:capacity'")->fetchColumn()===0,'failed admission must roll back');
$r=reserveSelection($db,'op:capacity',$cfg,$now,$cfg);
check(selectionReservedRequests($db,$day)===$slots+3,'selection and conversation slots reserved');
$held=aiCommittedTotal($db,$month);check($held>$before+$r['reserved'],'both costs held');
startSelection($db,$r,$now);finishSelection($db,$r,true,$now);
$handoff=$cfg;$handoff['selection_handoff']=$r['id'];
$bad=$pair;$bad[1]['id']=str_repeat('e',32);blocked(fn()=>reserveAi($db,'op:capacity',$bad,$handoff,$now),'invalid_proposal');
check(aiCommittedTotal($db,$month)===$held&&$db->query("SELECT resident_a FROM simulation_opportunities WHERE id='op:capacity'")->fetchColumn()===null,'invalid handoff rolls back and retains capacity');
$wrongSession=$handoff;$wrongSession['director_session']=str_repeat('d',32);blocked(fn()=>reserveAi($db,'op:capacity',$pair,$wrongSession,$now),'session_unavailable');
$attempt=reserveAi($db,'op:capacity',$pair,$handoff,$now);
blocked(fn()=>reserveAi($db,'op:capacity',$pair,$handoff,$now),'apply_rejected');
check(aiCommittedTotal($db,$month)===$held,'handoff counts both stages exactly once');
check(selectionReservedRequests($db,$day)===$slots+1,'conversation slots transferred');
check((int)$db->query("SELECT COUNT(*) FROM ai_attempts WHERE id='".$attempt['id']."'")->fetchColumn()===1,'attempt persisted');
try{$db->exec("UPDATE simulation_opportunities SET resident_a=NULL WHERE id='op:capacity'");throw new RuntimeException('binding mutable');}catch(PDOException $e){}
check($db->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&!$db->query('PRAGMA foreign_key_check')->fetch(),'integrity');
echo "PASS atomic selection and conversation reservation, limits, invalid participant rollback, exact accounting and immutable handoff\n";