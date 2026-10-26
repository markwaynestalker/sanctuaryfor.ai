<?php
declare(strict_types=1);
try{
    $saved=[];foreach(['LITTLE_SPARKS_AI_DAILY_REQUESTS','LITTLE_SPARKS_AI_MONTHLY_MICROUSD','LITTLE_SPARKS_ACTIVITY_DAILY_MAX','LITTLE_SPARKS_RESIDENT_DAILY_MAX','LITTLE_SPARKS_DIRECTOR_RUNTIME'] as $key)$saved[$key]=getenv($key);
    foreach(['LITTLE_SPARKS_AI_DAILY_REQUESTS'=>'aiDailyRequestLimit','LITTLE_SPARKS_AI_MONTHLY_MICROUSD'=>'aiMonthlyBudget'] as $key=>$function){
        $read='LittleSparks\\'.$function;
        foreach(['0','1','201','2000','25000000',(string)PHP_INT_MAX] as $value){putenv($key.'='.$value);check($read()===(int)$value,'Explicit integer accepted without a chosen PHP ceiling: '.$key);}
        foreach([false,'','-1','+1','02','1.0','1e3',' 1','1 ',(string)PHP_INT_MAX.'0',str_repeat('9',1000)] as $value){
            putenv($value===false?$key:$key.'='.$value);
            try{$read();throw new LogicException('Invalid/missing allowance accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Missing/malformed/overflow quota fails closed');}
        }
    }
    $executionSettings=['LITTLE_SPARKS_AI_MAX_INPUT_TOKENS'=>'65536','LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS'=>'1024',
        'LITTLE_SPARKS_AI_MAX_REQUEST_BYTES'=>'32768','LITTLE_SPARKS_DIRECTOR_MIN_COMMITMENT_MICROUSD'=>'20000',
        'LITTLE_SPARKS_DIRECTOR_RUN_SECONDS'=>'40','LITTLE_SPARKS_DIRECTOR_POLL_MAX_READS'=>'2',
        'LITTLE_SPARKS_DIRECTOR_POLL_INTERVAL_SECONDS'=>'2','LITTLE_SPARKS_DIRECTOR_SESSION_MAX_TURNS'=>'16',
        'LITTLE_SPARKS_AI_DAY_BOUNDARY_GUARD_SECONDS'=>'120'];
    $previousExecution=[];foreach($executionSettings as $key=>$value){$previousExecution[$key]=getenv($key);putenv($key.'='.$value);}
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2000');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=25000000');
    $changed=LittleSparks\aiConfiguration(time());$execution=LittleSparks\directorExecutionConfiguration();
    check($changed['max_input']===65536&&$changed['max_output']===1024&&LittleSparks\aiRequestByteLimit()===32768&&LittleSparks\aiManagedMinimumCommitment()===20000,'Explicit token/body/commitment budgets above old defaults are accepted unchanged');
    check($execution['run_seconds']===40&&$execution['poll_max_reads']===2&&$execution['poll_interval_seconds']===2&&$execution['session_max_turns']===16,'Execution and continuity settings use ENV');
    check(LittleSparks\aiDayBoundaryGuard()===120,'UTC boundary guard is explicit ENV policy with a lease safety minimum');
    foreach(['LITTLE_SPARKS_AI_MAX_INPUT_TOKENS'=>'1000000001','LITTLE_SPARKS_DIRECTOR_RUN_SECONDS'=>'46','LITTLE_SPARKS_DIRECTOR_POLL_MAX_READS'=>'4','LITTLE_SPARKS_AI_LEASE_SECONDS'=>'1'] as $key=>$value){
        $old=getenv($key);putenv($key.'='.$value);try{LittleSparks\aiConfiguration(time());throw new LogicException('Unsupported structural execution configuration accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Incompatible settings are rejected rather than clamped');}putenv($old===false?$key:$key.'='.$old);
    }
    foreach($previousExecution as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2000');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX=2000');putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX=1000');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');
    check(LittleSparks\activityConfiguration()['resident_daily']===1000&&LittleSparks\activityConfiguration()['daily_opportunities']===2000,'Owner-selected resident/global quotas are not clamped by previous PHP maxima');
    $d=$stageFresh();$actors=$d->query('SELECT id FROM residents ORDER BY id LIMIT 2')->fetchAll();$registered=LittleSparks\registeredDirector($d,LittleSparks\directorConfiguration());$session=LittleSparks\directorSession($d,$registered,$actors,'agents',time());
    $pricing=static fn()=>LittleSparks\aiConfiguration(time())+['director_slots'=>8,'director_session'=>$session['id'],'director_runtime'=>'agents','director_context'=>str_repeat('a',64)];
    $hold=static function(int $n)use($d,$actors,$pricing):void{$a=LittleSparks\reserveAi($d,'allowance-fixture:'.$n,$actors,$pricing(),time());LittleSparks\finishAi($d,$a,'uncertain','transport_uncertain');};
    // Low-level reservations only: no HTTP, generation, conversation or fake billing.
    for($i=0;$i<100;$i++)$hold($i);
    $before=$d->query('SELECT * FROM ai_attempts ORDER BY rowid')->fetchAll();
    try{$hold(100);throw new LogicException('Configured monthly budget bypassed');}catch(LittleSparks\AiGuard $e){check($e->reason==='monthly_budget_cap','The configured $1 test budget stops further holds');}
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=25000000');
    for($i=100;$i<249;$i++)$hold($i);
    check(LittleSparks\aiCommittedTotal($d,gmdate('Y-m'))===2490000,'Explicit $25 monthly setting accepts commitments beyond $1, without clearing prior holds');
    check(array_slice($d->query('SELECT * FROM ai_attempts ORDER BY rowid')->fetchAll(),0,100)===$before,'All original uncertain rows, costs and reservations remain unchanged');
    check((int)$d->query('SELECT SUM(request_slots) FROM director_attempts')->fetchColumn()===1992,'All 249 managed holds retain eight slots each');
    // Two independent workers compete for exactly two remaining Responses slots.
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=1994');$path=$d->query('PRAGMA database_list')->fetch()['file'];$workers=[];
    foreach([4,3] as $offset){$p=proc_open([PHP_BINARY,__DIR__.'/director-worker.php',$path,gmdate('Y-m-d\TH:i:00\Z',time()-$offset*60)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
    foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);check(is_array(json_decode($out,true)),'Offline worker returns a bounded result');}
    check((int)$d->query("SELECT COUNT(*) FROM director_attempts WHERE runtime='responses'")->fetchColumn()===1,'Concurrent reservations cannot exceed the ENV allowance above the former ceiling');
    $externalId=str_repeat('e',32);$external=json_encode(['check_id'=>$externalId,'day'=>gmdate('Y-m-d'),'http_requests_reserved'=>1],JSON_THROW_ON_ERROR);
    $d->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['director_access_request:'.$externalId,$external]);
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2000');
    try{$hold(249);throw new LogicException('Existing external slot bypassed');}catch(LittleSparks\AiGuard $e){check($e->reason==='daily_request_cap','Imported metadata reservation stays counted with managed/Responses holds');}
    $rows=$d->query('SELECT * FROM ai_attempts ORDER BY rowid')->fetchAll();$digest=narrativeDigest($d);
    $runStatus=static function()use($path):array{$p=proc_open([PHP_BINARY,__DIR__.'/../scripts/ai-status.php','--database='.$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);return json_decode($out,true,512,JSON_THROW_ON_ERROR);};
    $status=$runStatus();check($status['daily']['configured_slots']===2000&&$status['daily']['reserved_requests']===1995&&$status['daily']['remaining_slots']===5,'Status distinguishes configured, fully reserved and remaining capacity');
    check($status['daily']['recorded_http_starts']===2&&$status['daily']['managed_http_audit_rows']===0&&$status['daily']['external_reserved_slots']===1&&!$status['daily']['external_http_start_count_known'],'Status reports actual recorded starts without inventing external dispatch evidence');
    check($status['configured_monthly_microusd']===25000000&&$status['maximum_monthly_microusd']===25000000&&$status['monthly_committed_microusd']>=2490000,'Diagnostics use the configured monthly budget and retained commitments');
    check(in_array('daily_request_cap',$status['operational']['blocking_reasons'],true),'Status explains why another eight-slot encounter cannot fit');
    check(narrativeDigest($d)===$digest,'Diagnostics do not change any database table');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2003');$hold(249);
    check(array_slice($d->query('SELECT * FROM ai_attempts ORDER BY rowid')->fetchAll(),0,count($rows))===$rows,'Changing only ENV admits the next reservation while preserving prior rows');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS');$status=$runStatus();check($status['daily']['configured_slots']===null&&$status['daily']['remaining_slots']===null&&in_array('configuration_invalid',$status['operational']['blocking_reasons'],true),'Unset quota is explicitly invalid, never unlimited or an assumed default');
    foreach($saved as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    echo "PASS ENV-only daily/monthly quotas and 1000 resident setting, missing/invalid/overflow validation, retained uncertain holds beyond old ceilings, unchanged eight-slot lifecycle, concurrent ENV boundary and query-only truthful diagnostics (zero provider requests)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
