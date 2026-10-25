<?php
declare(strict_types=1);
require_once __DIR__.'/../app/maintenance.php';
try{
    check(LittleSparks\managedValidationError(400,'{"error":{"code":"invalid_value","param":"agent.model","message":"PRIVATE_CANARY"}}')===['code'=>'invalid_value','param'=>'agent.model'],'Validation retains only fixed structural fields');
    foreach(['not json','{"error":{"code":"sk-SECRET","param":"PRIVATE_CANARY","message":"SECRET"}}','{"error":"PRIVATE_CANARY"}'] as $raw)check(LittleSparks\managedValidationError(400,$raw)===null,'Reject malformed/unknown validation fields');
    check(LittleSparks\managedValidationError(500,'{"error":{"code":"invalid_value","param":"agent.model"}}')===null,'Only HTTP 400 validation is extracted');
    foreach([null,['limit'=>1]] as $unsupported){
        try{LittleSparks\managedWireBody('POST','agents/sessions',['spend_control'=>$unsupported],null);throw new LogicException('Unsupported request field reached transport');}
        catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Reject unsupported spend_control locally, even null');}
    }
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');
    $db=$directorFresh();$mock=new ManagedMock();$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);$attemptId=$result['id'];
    $calls=$db->query('SELECT * FROM director_http_calls ORDER BY sequence')->fetchAll();
    check(count($calls)===6&&array_column($calls,'kind')===['create','inspect_session','turns_1','items','moderation','final_turn'],'All HTTP steps have durable started-before-dispatch records');
    check(array_unique(array_column($calls,'outcome'))===['succeeded'],'Successful outcomes logged without response bodies');
    check((int)$db->query('SELECT COUNT(*) FROM director_usage_observations')->fetchColumn()===2,'Completion and final usage are separate append-only observations');
    $a=$db->query('SELECT * FROM ai_attempts ORDER BY rowid DESC LIMIT 1')->fetch();check($a['state']==='uncertain'&&$a['estimated_microusd']===null,'Best-effort usage is never a bill');
    $n=count($mock->calls);LittleSparks\simulateDirectorOpportunity($db,gmdate('Y-m-d\TH:i:00\Z',time()-240),true,null,$mock);check(count($mock->calls)>$n,'Unresolved billing retains commitments without blocking a distinct eligible opportunity');
    $review=LittleSparks\reviewManagedBilling($db,$attemptId,str_repeat('c',32),0,str_repeat('a',64));
    check($review['commitment_microusd']===(int)$a['reserved_microusd'],'Zero provider amount cannot restore budget headroom');
    $onceConfig=LittleSparks\aiConfiguration(time())+['director_once'=>true];
    try{LittleSparks\reserveAi($db,'second-once-attempt',[['id'=>$a['resident_a']],['id'=>$a['resident_b']]],$onceConfig,time());throw new LogicException('Second one-shot admitted after reconciliation');}catch(LittleSparks\AiGuard $e){check($e->reason==='period_recorded','One-shot limit survives billing review');}
    $rows=(int)$db->query('SELECT COUNT(*) FROM director_billing_reviews')->fetchColumn();LittleSparks\reviewManagedBilling($db,$attemptId,str_repeat('c',32),0,str_repeat('a',64));check((int)$db->query('SELECT COUNT(*) FROM director_billing_reviews')->fetchColumn()===$rows,'Evidence review is idempotent');
    LittleSparks\reviewManagedBilling($db,$attemptId,str_repeat('d',32),(int)$a['reserved_microusd']+1,str_repeat('b',64));check($db->query('SELECT state FROM ai_attempts WHERE id="'.$attemptId.'"')->fetchColumn()==='uncertain','Overrun quarantines accounting');
    LittleSparks\reviewManagedBilling($db,$attemptId,str_repeat('e',32),0,str_repeat('b',64));$q=$db->prepare('SELECT estimated_microusd,state FROM ai_attempts WHERE id=?');$q->execute([$attemptId]);$cost=$q->fetch();check((int)$cost['estimated_microusd']===(int)$a['reserved_microusd']+1&&$cost['state']==='uncertain','Later low observations cannot erase an overrun');
    foreach(['UPDATE director_usage_observations SET input_tokens=0','DELETE FROM director_billing_reviews','UPDATE director_http_calls SET outcome=\'started\''] as $sql){try{$db->exec($sql);throw new LogicException('Audit rewrite accepted');}catch(PDOException){}}
    $observed=['id'=>$attemptId,'reserved'=>(int)$a['reserved_microusd']];$pricing=LittleSparks\aiConfiguration(time());
    foreach([['input_tokens'=>10,'output_tokens'=>2,'total_tokens'=>12,'input_tokens_details'=>['cached_tokens'=>11]],['input_tokens'=>10,'output_tokens'=>2,'total_tokens'=>99],['input_tokens'=>10,'output_tokens'=>2,'total_tokens'=>12,'output_tokens_details'=>['reasoning_tokens'=>3]],null] as $invalidUsage)check(LittleSparks\observeManagedUsage($db,$observed,'turn_invalid',$invalidUsage,$pricing)===null,'Malformed/revised usage cannot be accepted as billing');
    check((int)$db->query('SELECT estimated_microusd FROM ai_attempts WHERE id="'.$attemptId.'"')->fetchColumn()===(int)$a['reserved_microusd']+1,'Usage observations never lower committed spending');
    $db=$directorFresh();$mock=new ManagedMock('apply_failure',$db);$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check($result['status']==='failed'&&$db->query('SELECT state FROM ai_attempts ORDER BY rowid DESC LIMIT 1')->fetchColumn()==='uncertain','Apply rollback cannot clear managed billing uncertainty');
    $db=$directorFresh();$mock=new ManagedMock('timeout');LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check($db->query('SELECT outcome FROM director_http_calls LIMIT 1')->fetchColumn()==='uncertain','Disconnect after reservation is durable and never automatically retried');
    $crossId=$db->query("SELECT id FROM ai_attempts WHERE state='uncertain' ORDER BY rowid DESC LIMIT 1")->fetchColumn();$db->prepare('UPDATE ai_attempts SET month=? WHERE id=?')->execute(['2000-01',$crossId]);
    try{LittleSparks\reviewManagedBilling($db,$crossId,str_repeat('9',32),0,str_repeat('f',64));throw new LogicException('Cross-month uncertainty cleared');}catch(LittleSparks\AiGuard $e){check($e->reason==='accounting_unresolved','Cross-month costs cannot regain headroom');}
    foreach([['GET','https://example.com/'],['GET','agents/sessions/a/../b'],['POST','agents'],['POST','agents/sessions/a?x=1'],['GET','moderations']] as [$method,$path]){
        try{LittleSparks\managedWireBody($method,$path,null,null);throw new LogicException('Unrestricted managed route accepted');}catch(LittleSparks\AiGuard){}
    }
    putenv('LITTLE_SPARKS_AI_ENABLED=0');
    try{(new LittleSparks\OpenAiManagedTransport())->request('POST','agents/sessions',[],time()+20,str_repeat('a',32));throw new LogicException('Disabled live admission opened');}catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Disabled configuration blocks transport before credentials/network');}
    putenv('LITTLE_SPARKS_AI_ENABLED=1');
    $budget=$directorFresh();$reg=LittleSparks\registeredDirector($budget,LittleSparks\directorConfiguration());$pair=[['id'=>$ids[0]],['id'=>$ids[1]]];$session=LittleSparks\directorSession($budget,$reg,$pair,'agents',time());
    $pricing=LittleSparks\aiConfiguration(time())+['director_slots'=>4,'director_session'=>$session['id'],'director_runtime'=>'agents','director_context'=>str_repeat('a',64)];
    $attempt=LittleSparks\reserveAi($budget,'audit-fixture',$pair,$pricing,time());LittleSparks\startManagedHttp($budget,$attempt,'POST','agents/sessions');
    try{LittleSparks\startManagedHttp($budget,$attempt,'POST','agents/sessions');throw new LogicException('Duplicate generation admitted');}catch(LittleSparks\AiGuard $e){check($e->reason==='request_already_started','Creation is durably at most once');}
    LittleSparks\startManagedHttp($budget,$attempt,'GET','agents/sessions/s/turns?limit=1&order=desc');LittleSparks\startManagedHttp($budget,$attempt,'GET','agents/sessions/s/turns/t/items?limit=20&order=asc');LittleSparks\startManagedHttp($budget,$attempt,'POST','moderations');
    try{LittleSparks\startManagedHttp($budget,$attempt,'GET','agents/sessions/s');throw new LogicException('Extra HTTP admitted');}catch(LittleSparks\AiGuard $e){check($e->reason==='daily_request_cap','No request beyond reserved slots');}
    $db=$directorFresh();$before=LittleSparks\villageManifest($db);$backup=$dir.'/verified-backup.sqlite';$manifest=LittleSparks\backupVillage($db,$backup);check($manifest['data_sha256']===$before['data_sha256'],'Consistent private SQLite backup preserves all table rows/schema');
    try{LittleSparks\backupVillage($db,$backup);throw new LogicException('Backup overwrite accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'must not exist'),'No backup overwrite');}
    $restored=LittleSparks\connect($backup);check(LittleSparks\villageManifest($restored)===$before,'Restored copy passes complete preservation manifest');
    $wal=$directorFresh();$wal->exec('PRAGMA journal_mode=WAL');$wal->exec("INSERT INTO settings VALUES ('fixture_wal_marker','committed frame')");$walManifest=LittleSparks\backupVillage($wal,$dir.'/wal-backup.sqlite');$walCopy=LittleSparks\connect($dir.'/wal-backup.sqlite');check($walCopy->query("SELECT value FROM settings WHERE name='fixture_wal_marker'")->fetchColumn()==='committed frame','Snapshot includes committed WAL data');
    $old=$directorFresh();foreach(LittleSparks\directorAccountingTables() as $table)$old->exec('DROP TABLE '.$table);$oldBefore=originalRows($old)+memoryRows($old);$old->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorSchema($old);$old->exec('ROLLBACK');check(!$old->query("SELECT 1 FROM sqlite_master WHERE name='director_http_calls'")->fetchColumn(),'Draft V6 accounting upgrade rolls back');
    $old->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorSchema($old);$old->exec('COMMIT');check(originalRows($old)+memoryRows($old)===$oldBefore,'Existing draft V6 upgrades additively without touching V5 records');
    echo "PASS durable HTTP audit, append-only observations/reviews, conservative reconciliation, uncertain apply rollback, route/egress guards, verified backup/restore and additive draft-V6 accounting upgrade\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
