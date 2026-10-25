<?php
declare(strict_types=1);
require_once __DIR__.'/../app/director_reconciliation.php';require __DIR__.'/director-reconciliation-mock.php';
try{
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $uncertain=function()use($directorFresh,$at):array{
        $d=$directorFresh();$r=LittleSparks\simulateDirectorOpportunity($d,$at,true,null,new ManagedMock('flagged'));
        check($r['status']==='failed','Fixture is an actual failed mocked generation');
        return [$d,$d->query("SELECT id FROM ai_attempts WHERE state='uncertain'")->fetchColumn()];
    };
    $world=function(PDO $d):array{$out=[];foreach(['residents','resident_identities','resident_identity_audits','messages','conversations','events','memories','friendships','world_objects','simulation_runs','simulation_opportunities','director_http_calls'] as $t){
        if(!$d->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn())continue;
        $out[$t]=$d->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();}return $out;};
    [$d,$id]=$uncertain();$local=LittleSparks\directorAttemptInspection($d,$id);$before=$world($d);$a=$local['records']['ai_attempts'][0];
    $mock=new ReconciliationMock($local);$inspection=str_repeat('1',32);$r=LittleSparks\inspectDirectorProvider($d,$id,$inspection,$mock);
    check(count($mock->calls)===3&&$r['ready_for_billing_review']&&!$r['usage_is_final_billing'],'Only three metadata GETs; observed usage is not final billing');
    check(LittleSparks\directorAttemptInspection($d,$id)===$local&&$before===$world($d),'Inspection preserves every old attempt, run, world and HTTP audit row');
    check(LittleSparks\directorAccessReservedRequests($d,gmdate('Y-m-d'))===3&&LittleSparks\directorExternalCommitment($d,gmdate('Y-m'))===0,'Read-only slots counted without a fake generation charge');
    check(!str_contains(json_encode($d->query("SELECT value FROM settings WHERE name LIKE 'director_inspection:%'")->fetchAll()),'PRIVATE_INSPECTION_CANARY'),'No provider instructions/content/keys retained');
    try{LittleSparks\inspectDirectorProvider($d,$id,$inspection,$mock);throw new LogicException('Inspection retried');}catch(LittleSparks\AiGuard $e){check($e->reason==='request_already_started','Consumed inspection ID cannot retry');}
    $review=str_repeat('2',32);$resolved=LittleSparks\resolveDirectorInspection($d,$id,$inspection,$r['inspection_sha256'],$review,700,str_repeat('a',64));
    check($resolved['state']==='rejected'&&$resolved['commitment_microusd']>=$a['reserved_microusd'],'Explicit billing evidence releases only the unresolved state and retains full hold');
    check($world($d)===$before,'Resolution never imports dialogue, mutates memories or rewrites failed opportunity/history');
    check($d->query("SELECT error_code FROM ai_attempts WHERE id='$id'")->fetchColumn()===$a['error_code'],'Original error remains');
    check($d->query('SELECT state FROM director_sessions LIMIT 1')->fetchColumn()==='unavailable','Uncommitted provider context cannot reenter future encounters');
    $again=LittleSparks\resolveDirectorInspection($d,$id,$inspection,$r['inspection_sha256'],$review,700,str_repeat('a',64));check($again['replayed']&&(int)$d->query('SELECT COUNT(*) FROM director_billing_reviews')->fetchColumn()===1,'Resolution is idempotent');
    try{LittleSparks\resolveDirectorInspection($d,$id,$inspection,$r['inspection_sha256'],$review,701,str_repeat('a',64));throw new LogicException('Conflicting review accepted');}catch(LittleSparks\AiGuard){}
    $future=new ManagedMock();$next=LittleSparks\simulateDirectorOpportunity($d,gmdate('Y-m-d\TH:i:00\Z',time()-360),true,null,$future);
    check(count($future->calls)>0&&$next['status']==='completed','Unchanged accounting guard admits a distinct mocked encounter after safe reconciliation');
    [$d,$id]=$uncertain();$i=str_repeat('9',32);$r=LittleSparks\inspectDirectorProvider($d,$id,$i,new ReconciliationMock(LittleSparks\directorAttemptInspection($d,$id),'revised'));
    check($r['observed_estimate_microusd']>$r['session_usage']['estimated_microusd'],'Later lower provider usage cannot erase an earlier higher observation');
    $out=LittleSparks\resolveDirectorInspection($d,$id,$i,$r['inspection_sha256'],str_repeat('a',32),700,str_repeat('b',64));
    check($out['commitment_microusd']===$r['observed_estimate_microusd']&&$out['state']==='rejected','Verified review retains the highest observed estimate above the original reservation');
    foreach(['active','running','unknown','bad_usage','wrong_session','wrong_agent','wrong_attempt','history','old','changed','http'] as $mode){
        [$d,$id]=$uncertain();$local=LittleSparks\directorAttemptInspection($d,$id);$m=new ReconciliationMock($local,$mode);$r=LittleSparks\inspectDirectorProvider($d,$id,str_repeat('3',32),$m);
        check(!$r['ready_for_billing_review']&&LittleSparks\directorAttemptInspection($d,$id)===$local,'Invalid/active/ambiguous provider state retains hold: '.$mode);
        try{LittleSparks\resolveDirectorInspection($d,$id,str_repeat('3',32),$r['inspection_sha256'],str_repeat('4',32),0,str_repeat('b',64));throw new LogicException('Invalid report resolved');}catch(LittleSparks\AiGuard){}
        check(LittleSparks\directorAccessReservedRequests($d,gmdate('Y-m-d'))===3,'Failed inspection reservations persist');
    }
    foreach(['budget','snapshot','hash','stale','crossmonth','lease'] as $mode){
        [$d,$id]=$uncertain();$i=str_repeat('5',32);$r=LittleSparks\inspectDirectorProvider($d,$id,$i,new ReconciliationMock(LittleSparks\directorAttemptInspection($d,$id)));
        $hash=$r['inspection_sha256'];
        if($mode==='budget')putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1');
        if($mode==='snapshot')$d->exec("UPDATE ai_attempts SET estimated_microusd=20000 WHERE id='$id'");
        if($mode==='hash')$hash=str_repeat('0',64);
        if($mode==='stale'){$r['inspected_at']=time()-901;$r['inspection_sha256']='invalid';$d->prepare('UPDATE settings SET value=? WHERE name=?')->execute([json_encode($r),'director_inspection:'.$i.':report']);}
        if($mode==='crossmonth')$d->exec("UPDATE ai_attempts SET month='2000-01' WHERE id='$id'");
        if($mode==='lease')$d->exec("UPDATE ai_lease SET owner='$id' WHERE singleton=1");
        try{$out=LittleSparks\resolveDirectorInspection($d,$id,$i,$hash,str_repeat('6',32),10001,str_repeat('c',64));check($mode==='budget'&&$out['state']==='uncertain','Over-budget evidence retains quarantine');}
        catch(LittleSparks\AiGuard $e){check($mode!=='budget','Budget overrun must durably retain its actual commitment');}
        check($d->query("SELECT state FROM ai_attempts WHERE id='$id'")->fetchColumn()==='uncertain','Unsafe review never clears hold: '.$mode);putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    }
    [$d,$id]=$uncertain();$path=$d->query('PRAGMA database_list')->fetch()['file'];$slots=(int)$d->query('SELECT SUM(request_slots) FROM ai_attempts')->fetchColumn()+(int)$d->query('SELECT SUM(request_slots-2) FROM director_attempts')->fetchColumn();
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.($slots+3));$workers=[];
    foreach(['7','8'] as $digit){$p=proc_open([PHP_BINARY,__DIR__.'/director-reconciliation-worker.php',$path,$id,str_repeat($digit,32)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
    $accepted=0;foreach($workers as [$p,$pipes]){$o=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);$accepted+=(int)json_decode($o,true,16,JSON_THROW_ON_ERROR)['accepted'];}
    check($accepted===1&&LittleSparks\directorAccessReservedRequests($d,gmdate('Y-m-d'))===3,'Concurrent inspection cannot bypass remaining daily capacity');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    $transport=new LittleSparks\OpenAiManagedTransport(null,'session_bound');
    foreach([['POST','agents/sessions',[]],['POST','agents/sessions/session_bound/events',[]],['GET','agents/sessions/other',null],['GET','agents/sessions/session_bound/turns/t/items?limit=20&order=asc',null]] as [$method,$route,$body]){
        try{$transport->request($method,$route,$body,time()+1);throw new LogicException('Inspection egress escaped');}catch(LittleSparks\AiGuard){}
    }
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/reconcile-director-attempt.php','--database='.$path,'--attempt='.$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$report=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);check(json_decode($report,true)['attempt_id']===$id,'Default CLI is read-only local reporting with no credentials');
    $unchanged=LittleSparks\directorAttemptInspection($d,$id);
    foreach([['--resolve'],['--resolve','--confirm-final-billing','--microusd=0'],['--attempt='.$id],['--fetch','--fetch'],['--unknown']] as $badArgs){
        $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../scripts/reconcile-director-attempt.php','--database='.$path,'--attempt='.$id],$badArgs),[1=>['pipe','w'],2=>['pipe','w']],$pipes);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===1&&$stdout==='','CLI missing evidence/duplicate/unknown options fail closed');
        check(LittleSparks\directorAttemptInspection($d,$id)===$unchanged,'Malformed CLI cannot modify the held attempt');
    }
    echo "PASS uncertain Director reconciliation: GET-only inspection, retained slots/commitments, terminal identity/usage proof, explicit idempotent billing review, no fabricated history, budget/stale/active guards and concurrent reservations\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
