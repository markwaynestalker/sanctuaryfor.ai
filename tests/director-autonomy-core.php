<?php
declare(strict_types=1);
require_once __DIR__.'/../app/scheduler.php';require_once __DIR__.'/../app/operational_status.php';
try{
    foreach(['LITTLE_SPARKS_AI_ENABLED','LITTLE_SPARKS_DIRECTOR_ENABLED','LITTLE_SPARKS_SIMULATION_ENABLED','LITTLE_SPARKS_SCHEDULER_ENABLED','LITTLE_SPARKS_SCHEDULER_ALLOW_PAID'] as $flag)putenv($flag.'=1');
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $op=static fn(int $offset)=>gmdate('Y-m-d\TH:i:00\Z',time()-$offset*60);
    $run=static fn(PDO $d,string $at,LittleSparks\DirectorTransport $t)=>LittleSparks\simulateDirectorOpportunity($d,$at,true,null,$t,null,null,false,null,true);
    foreach(['lost','unknown','invalid','flagged','validation_400'] as $mode){
        $d=$stageFresh();$first=new StagingMock($mode);$failed=$run($d,$op(8),$first);check($failed['status']==='failed','Individual failure recorded: '.$mode);
        $id=$failed['id'];$q=$d->prepare('SELECT * FROM ai_attempts WHERE id=?');$q->execute([$id]);$old=$q->fetch();$oldCalls=count($first->calls);
        $run($d,$op(8),$first);check(count($first->calls)===$oldCalls,'Failed opportunity never redispatches: '.$mode);
        $next=new StagingMock('good');$success=$run($d,$op(7),$next);check($success['status']==='completed'&&$next->calls,'Next distinct configured opportunity succeeds: '.$mode);
        $q->execute([$id]);check($q->fetch()===$old,'Original uncertainty, cost and dispatch fields unchanged: '.$mode);
        check((int)$d->query('SELECT SUM(request_slots) FROM director_attempts')->fetchColumn()===16,'Both full eight-slot reservations retained');
        check((int)$d->query('SELECT COUNT(*) FROM messages')->fetchColumn()===2,'Only actual accepted subsequent dialogue saved');
        check(LittleSparks\aiCommittedTotal($d,gmdate('Y-m'))>=20000,'Uncertain and successful commitments both counted');
        LittleSparks\recordSchedulerResult($d,$success);$d->exec('PRAGMA query_only=ON');$d->beginTransaction();$health=LittleSparks\operationalStatus($d,time());$d->commit();$d->exec('PRAGMA query_only=OFF');
        check($health['scheduler']['operational']&&$health['director_configured']&&$health['future_opportunities_eligible'],'Historical failure does not make health permanently broken');
        check($health['last_failed_opportunity']['id']===$failed['opportunity_id']&&$health['last_successful_ai_conversation']!==null,'Health distinguishes previous failure from successful saved conversation');
        if(in_array($mode,['lost','flagged','validation_400'],true))check($health['last_failed_opportunity']['failure_category']===match($mode){'lost'=>'transport_uncertain','flagged'=>'moderation_rejected','validation_400'=>'provider_api_error'},'Health reports a sanitized specific failure category');
        check(!$health['historical_uncertainty_requires_intervention']&&!$health['connection']['live_probe'],'No administrative or network dependency in health');
        putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=20000');$blocked=new StagingMock('good');$result=$run($d,$op(6),$blocked);
        check(!$blocked->calls&&$result['failure_category']==='monthly_budget_cap','Retained old cost exhausts configured allocation');
        putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=16');$blocked=new StagingMock('good');$result=$run($d,$op(5),$blocked);
        check(!$blocked->calls&&$result['failure_category']==='daily_request_cap','Retained old request slots exhaust ENV allowance');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    }
    // Simulate process death after an atomic claim/reservation, without contacting a provider.
    $d=$stageFresh();$now=time();$claim=LittleSparks\claimActivity($d,$op(10),$now);$cfg=LittleSparks\directorConfiguration();$reg=LittleSparks\registeredDirector($d,$cfg);
    $d->exec('BEGIN IMMEDIATE');$session=LittleSparks\directorSession($d,$reg,$claim['pair'],'agents',$now);$d->exec('COMMIT');
    $pricing=LittleSparks\aiConfiguration($now)+['director_session'=>$session['id'],'director_runtime'=>'agents','director_slots'=>8,'director_context'=>str_repeat('a',64)];
    $held=LittleSparks\reserveAi($d,$claim['id'],$claim['pair'],$pricing,$now);
    $d->exec("UPDATE ai_lease SET expires_at=1;UPDATE simulation_opportunities SET expires_at=1 WHERE outcome='pending'");
    $next=new StagingMock('good');$success=$run($d,$op(9),$next);check($success['status']==='completed'&&$next->calls,'Expired lease and pending claim recover automatically');
    check(LittleSparks\activityResult($d,$claim['id'])['status']==='failed','Abandoned opportunity accurately fails once');
    $q=$d->prepare('SELECT state,error_code,reserved_microusd FROM ai_attempts WHERE id=?');$q->execute([$held['id']]);$old=$q->fetch();
    check($old['state']==='uncertain'&&$old['error_code']==='lease_expired'&&(int)$old['reserved_microusd']===$held['reserved'],'Recovery preserves uncertainty and every reserved micro-dollar');
    try{LittleSparks\startManagedHttp($d,$held,'POST','agents/sessions');throw new LogicException('Expired worker generated again');}catch(LittleSparks\Problem){}
    $n=count($next->calls);$run($d,$op(10),$next);check(count($next->calls)===$n,'Recovered failed opportunity is never replayed');
    // A later observation larger than the original hold must still prevent overspend.
    LittleSparks\observeManagedUsage($d,$held,'turn_expired',['input_tokens'=>200000,'output_tokens'=>100000,'total_tokens'=>300000],$pricing);
    check(LittleSparks\aiCommittedTotal($d,gmdate('Y-m'))>=85000,'Higher uncertain observed cost remains charged without operator review');
    // Drive the real scheduler through its already-failed current slot with missing credentials.
    $d=$stageFresh();$due=LittleSparks\dueOpportunity(time());$failed=$run($d,$due,new StagingMock('lost'));$path=$d->query('PRAGMA database_list')->fetch()['file'];
    $before=$d->query('SELECT * FROM ai_attempts ORDER BY id')->fetchAll();$oldDir=getenv('CREDENTIALS_DIRECTORY');$oldDb=getenv('LITTLE_SPARKS_DB');
    putenv('LITTLE_SPARKS_DB='.$path);putenv('CREDENTIALS_DIRECTORY='.$dir.'/nonexistent-autonomy-credential');
    for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__DIR__.'/../scripts/scheduled-village.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);$r=json_decode($out,true,32,JSON_THROW_ON_ERROR);check($r['status']==='failed'&&$r['replayed'],'Every minute tick handles saved failure without service failure or credential read');}
    check($d->query('SELECT * FROM ai_attempts ORDER BY id')->fetchAll()===$before,'Scheduler replay never changes billing or dispatches');
    putenv($oldDir===false?'CREDENTIALS_DIRECTORY':'CREDENTIALS_DIRECTORY='.$oldDir);putenv($oldDb===false?'LITTLE_SPARKS_DB':'LITTLE_SPARKS_DB='.$oldDb);
    // Distinct concurrent workers still share one atomic capacity boundary after uncertainty.
    $d=$stageFresh();$run($d,$op(14),new StagingMock('lost'));$path=$d->query('PRAGMA database_list')->fetch()['file'];
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=10');$workers=[];
    foreach([13,12] as $offset){$p=proc_open([PHP_BINARY,__DIR__.'/director-worker.php',$path,$op($offset)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
    foreach($workers as [$p,$pipes]){$o=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$e);check(is_array(json_decode($o,true)),'Concurrent worker returns a bounded outcome');}
    check((int)$d->query("SELECT COUNT(*) FROM director_attempts WHERE runtime='responses'")->fetchColumn()===1,'Only one concurrent new encounter fits after eight uncertain reserved slots');
    check((int)$d->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===1,'Concurrent workers save exactly one new conversation');
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    echo "PASS autonomous continuity: uncertain/invalid/moderated/HTTP failures followed by real mocked saved dialogue; retained cost/slots and ENV caps; expired-worker fencing/recovery; no retry/fallback; read-only health and successful service ticks for failed replays\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
