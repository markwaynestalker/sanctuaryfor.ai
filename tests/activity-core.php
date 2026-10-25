<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/activity_schema.php';require __DIR__.'/activity-mock.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function cli(string $script,array $args,bool $success=true):string{
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($p),'CLI starts');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);check(($code===0)===$success,'CLI exit: '.$err);return $out;
}
function snapshot(PDO $db):array{
    $tables=['settings','residents','rate_limits','home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events','simulation_runs','resident_preferences','ai_attempts','ai_conversations','ai_lease'];$result=[];
    foreach($tables as $table)$result[$table]=$db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll();return $result;
}
function preserved(array $before,PDO $db,array $skip=[]):void{
    foreach($before as $table=>$rows){
        if(in_array($table,$skip,true))continue;
        $after=$db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll();
        foreach($rows as $row)check(in_array($row,$after,true),'Original '.$table.' row survives');
    }
}
try{
    $dir=$argv[1]??'';check(is_dir($dir)&&str_starts_with(basename($dir),'little-sparks-v2-test-'),'Disposable fixtures only');
    putenv('OPENAI_API_KEY');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=0');putenv('LITTLE_SPARKS_AI_ENABLED=0');
    putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED='.LittleSparks\AI_PRICING_VERSION);putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512');
    $base=$dir.'/activity.sqlite';copy($dir.'/http-fixture.sqlite',$base);$db=LittleSparks\connect($base);$before=snapshot($db);$digest=LittleSparks\activityPreservationDigest($db);$db=null;
    copy($base,$dir.'/broken-activity.sqlite');$broken=LittleSparks\connect($dir.'/broken-activity.sqlite');$broken->exec('CREATE TABLE simulation_opportunities(conflict TEXT)');$broken=null;
    cli('migrate-activity.php',['--database='.$dir.'/broken-activity.sqlite'],false);$broken=LittleSparks\connect($dir.'/broken-activity.sqlite');
    check((int)$broken->query('PRAGMA user_version')->fetchColumn()===3&&$digest===LittleSparks\activityPreservationDigest($broken),'DDL failure rolls back schema and every V3 row');$broken=null;
    copy($base,$dir.'/active-migration.sqlite');$active=LittleSparks\connect($dir.'/active-migration.sqlite');
    $existingAttempt=$active->query('SELECT id FROM ai_attempts LIMIT 1')->fetchColumn();$active->prepare('UPDATE ai_lease SET owner=?,expires_at=?')->execute([$existingAttempt,time()+60]);$activeBefore=LittleSparks\activityPreservationDigest($active);$active=null;
    cli('migrate-activity.php',['--database='.$dir.'/active-migration.sqlite'],false);$active=LittleSparks\connect($dir.'/active-migration.sqlite');check((int)$active->query('PRAGMA user_version')->fetchColumn()===3&&$activeBefore===LittleSparks\activityPreservationDigest($active),'Active simulations prevent migration without modifying data');$active=null;
    cli('migrate-activity.php',['--database='.$base]);$db=LittleSparks\connect($base);check((int)$db->query('PRAGMA user_version')->fetchColumn()===4,'V4 migrated');preserved($before,$db);check(!$db->query('PRAGMA foreign_key_check')->fetch(),'Preserved foreign keys');$db=null;
    cli('migrate-activity.php',['--database='.$base]);$db=LittleSparks\connect($base);check($digest===LittleSparks\activityPreservationDigest($db),'Repeat migration preserves all V3 values');
    $oldToken=hash_hmac('sha256',hash('sha256','ai-fixture-owner').':'.str_repeat('d',32),LittleSparks\secret($db));check(LittleSparks\revisit($db,$oldToken,'v4-token-fixture',time())['id']===str_repeat('0',32),'Original private revisit token remains valid after V4 migration');
    $now=time();$at=static fn(int $index):string=>gmdate('Y-m-d\TH:i:00\Z',$now-$index*60);
    check(LittleSparks\simulateOpportunity($db,$at(1))['status']==='paused','Default runner disabled');
    putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES');check(LittleSparks\simulationInterval()===15,'Default 15 minute interval');
    foreach(['','0','-1','1.5','1441','huge'] as $invalid){putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES='.$invalid);try{LittleSparks\dueOpportunity($now);throw new LogicException('Invalid interval accepted');}catch(LittleSparks\AiGuard){}}
    putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES=1');check(LittleSparks\dueOpportunity($now)===$at(0),'One-minute due slot');
    putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES=15');
    $slot=LittleSparks\dueOpportunity($now);check(LittleSparks\dueOpportunity(strtotime($slot.' +30 seconds'))===$slot,'Stable interval slot');
    putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES=5');check(LittleSparks\dueOpportunity(strtotime($slot))===$slot&&LittleSparks\opportunityId($slot)==='op:'.$slot,'Interval changes preserve an existing minute identity');putenv('LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES=15');
    check(LittleSparks\safeAiDialogue('A: A quiet walk sounds lovely.')==='A quiet walk sounds lovely.','A prefix cleaned');
    check(LittleSparks\safeAiDialogue(' B: Curious—shall we explore?')==='Curious—shall we explore?','B prefix cleaned');
    check(LittleSparks\safeAiDialogue('The sign says A: and B: are welcome.')==='The sign says A: and B: are welcome.','Middle text untouched');
    foreach(['A: ','A: <b>unsafe</b>',"A:\nunsafe",'Assistant: Hello','A: B: Hello'] as $bad){try{LittleSparks\safeAiDialogue($bad);throw new LogicException('Bad dialogue accepted');}catch(LittleSparks\AiGuard){}}
    $legacy=$before['simulation_runs'][0]['period'];check(LittleSparks\simulateOptionalAiDay($db,$legacy)['replayed'],'Old dates replay');
    $db=null;$counter=0;$fresh=function()use($dir,$base,&$counter):PDO{$path=$dir.'/activity-case-'.(++$counter).'.sqlite';check(copy($base,$path),'Copy fixture');return LittleSparks\connect($path);};
    putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_AI_ENABLED=1');
    $db=$fresh();$mock=new ActivityMock('conversation',$db);$first=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);$second=LittleSparks\simulateOpportunity($db,$at(2),true,$mock);
    check($first['outcome']==='conversation'&&$second['outcome']==='conversation'&&$first['id']!==$second['id'],'Multiple same-day AI encounters with permanent distinct IDs');
    check(count($mock->calls)===4,'Both Responses and moderation counted per encounter');
    check((int)$db->query('SELECT SUM(request_slots) FROM ai_attempts WHERE period LIKE \'op:%\'')->fetchColumn()===4,'Four reserved HTTP slots');
    $replay=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check($replay['replayed']&&$replay['id']===$first['id']&&count($mock->calls)===4,'Same opportunity never spends twice');
    $db->exec("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES ('historical-op','op:2000-01-01T00:00:00Z','completed','scripted','2000-01-01T00:00:00Z','2000-01-01T00:00:00Z');
        INSERT INTO simulation_opportunities(id,scheduled_at,day,run_id,outcome,expires_at,created_at,finished_at) VALUES ('op:2000-01-01T00:00:00Z','2000-01-01T00:00:00Z','2000-01-01','historical-op','none',0,'2000-01-01T00:00:00Z','2000-01-01T00:00:00Z')");
    check(LittleSparks\simulateOpportunity($db,'2000-01-01T00:00:00Z',true,$mock)['replayed']&&count($mock->calls)===4,'Saved opportunities replay beyond the new-work window without charges');
    foreach(['2000-01-02T00:00:00Z',gmdate('Y-m-d\TH:i:00\Z',time()+86400)] as $invalidAt){try{LittleSparks\simulateOpportunity($db,$invalidAt,true,$mock);throw new LogicException('Out-of-window new opportunity accepted');}catch(LittleSparks\Problem $e){check($e->status===422,'Only new opportunities are time bounded');}}
    $q=$db->prepare('SELECT body FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.run_id=? ORDER BY sequence');$q->execute([$first['id']]);$lines=$q->fetchAll(PDO::FETCH_COLUMN);check($lines[0]==='A quiet walk sounds like a bright little adventure.'&&str_starts_with($lines[1],'Curious—'),'Clean natural dialogue is saved');
    $q=$db->prepare('SELECT resident_a FROM simulation_opportunities WHERE run_id=?');$q->execute([$first['id']]);$public=LittleSparks\profile($db,$q->fetchColumn());check(in_array('openai',array_column($public['conversations'],'provider'),true),'V4 public source labels remain accurate');
    preserved($before,$db,['relationships','ai_lease']);
    foreach(['conversations','messages'] as $table){try{$db->exec('DELETE FROM '.$table);throw new LogicException('History deletion accepted');}catch(PDOException){}}
    try{$db->exec("UPDATE messages SET body='rewritten'");throw new LogicException('History rewrite accepted');}catch(PDOException){}
    try{LittleSparks\simulateOptionalAiDay($db,gmdate('Y-m-d',time()-2*86400),true,$mock);throw new LogicException('Legacy runner bypassed V4 limits');}catch(LittleSparks\Problem $e){check($e->status===422,'Legacy new-day runs cannot bypass opportunity limits');}
    echo "PASS V3→V4 populated migration/repeat/rollback, immutable history, legacy replay, interval controls, multiple AI encounters, natural saved dialogue and idempotency\n";
    foreach(['quiet','none','action'] as $mode){
        $db=$fresh();$mock=new ActivityMock($mode,$db);$events=(int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();$convs=(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
        $result=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check($result['outcome']===$mode,'Optional '.$mode.' outcome');
        check((int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===$convs,'No fabricated conversation for '.$mode);
        $afterEvents=(int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn();check($afterEvents===$events+($mode==='none'?0:1),'Only actually applied event for '.$mode);
    }
    foreach(['invalid','html','label_only','extra','quiet_text','flagged','timeout','unknown','moderation_timeout','lease_expired','collision'] as $mode){
        $db=$fresh();$mock=new ActivityMock($mode,$db);$snapshot=snapshot($db);$result=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);
        check($result['status']==='failed','Reject '.$mode);$after=snapshot($db);
        foreach(['relationships','conversations','messages','memories'] as $table)check($snapshot[$table]===$after[$table],'No partial social change for '.$mode);
        if($mode!=='collision')check($snapshot['world_objects']===$after['world_objects']&&$snapshot['events']===$after['events'],'No fabricated world changes for '.$mode);
        check(!str_contains(json_encode($after),'PRIVATE_CANARY'),'No raw failures stored');
        $calls=count($mock->calls);LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check(count($mock->calls)===$calls,'Failed opportunity cannot retry paid calls');
        if(in_array($mode,['timeout','unknown'],true)){
            check($db->query("SELECT state FROM ai_attempts WHERE period LIKE 'op:%'")->fetchColumn()==='uncertain','Unknown billing freezes reservations');
            $result=LittleSparks\simulateOpportunity($db,$at(2),true,$mock);check(count($mock->calls)===$calls+1&&$result['provider']==='openai'&&$result['status']==='failed','A distinct opportunity attempts independently; repeated provider failure does not fabricate fallback speech');
        }
    }
    echo "PASS quiet/no-action, owned actions, moderation, hostile output, failed/uncertain calls, collision rollback and no automatic charge retries\n";
    $db=$fresh();$mock=new ActivityMock('conversation',$db);putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    // The fixture already contains today's V2B generation/moderation pair: new work shares its ledger.
    $result=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check(!$mock->calls&&$result['ai_blocked']==='daily_request_cap','Legacy requests count against the same daily ceiling');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=6');$result=LittleSparks\simulateOpportunity($db,$at(2),true,$mock);$result=LittleSparks\simulateOpportunity($db,$at(3),true,$mock);check(count($mock->calls)===4,'Increasing only env permits two more paid opportunities');
    $result=LittleSparks\simulateOpportunity($db,$at(4),true,$mock);check(count($mock->calls)===4&&$result['ai_blocked']==='daily_request_cap','Six total slots includes moderation and legacy attempts');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1');$db=$fresh();$mock=new ActivityMock();$r=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check(!$mock->calls&&$r['ai_blocked']==='monthly_budget_cap','Monthly cap independently enforced');
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000001');$db=$fresh();$mock=new ActivityMock();$r=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check(!$mock->calls&&$r['ai_blocked']==='configuration_invalid','Cannot raise hard $1 limit');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $db=$fresh();$db->exec('UPDATE ai_attempts SET estimated_microusd=999999');$mock=new ActivityMock();$r=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check(!$mock->calls&&$r['ai_blocked']==='monthly_budget_cap','Existing commitments cannot be reset by new scheduler');
    putenv('LITTLE_SPARKS_AI_ENABLED=0');$db=$fresh();
    for($i=1;$i<=12;$i++){ $r=LittleSparks\simulateOpportunity($db,$at($i));check($r['status']==='completed','Scripted opportunity completes'); }
    $turns=$db->query('SELECT resident_a FROM simulation_opportunities UNION ALL SELECT resident_b FROM simulation_opportunities WHERE resident_b IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);$counts=array_count_values(array_filter($turns));
    $population=(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn();check(count($counts)===$population&&max($counts)-min($counts)<=2,'Fair selection serves whole fixture rather than same pair');
    // A clean, larger village can use the entire 200-request ceiling without a new
    // encounter cap hidden in the scheduler. Existing fixture usage is not reset.
    $large=$dir.'/large-village.sqlite';cli('migrate-village.php',['--database='.$large]);cli('migrate-ai.php',['--database='.$large]);cli('migrate-activity.php',['--database='.$large]);$one=LittleSparks\connect($large);
    for($i=0;$i<20;$i++)LittleSparks\create($one,['name'=>'Spark '.$i,'color'=>'mint','antenna'=>'orb','personality'=>'curious','requestId'=>str_pad(dechex($i),32,'0',STR_PAD_LEFT),'website'=>''],'browser-'.$i,'network-'.$i,time(),true);
    putenv('LITTLE_SPARKS_AI_ENABLED=1');$hundred=new ActivityMock('conversation',$one);
    for($i=1;$i<=100;$i++){ $r=LittleSparks\simulateOpportunity($one,$at($i),true,$hundred);check($r['outcome']==='conversation'&&$r['provider']==='openai','100 AI encounters possible within explicit mocked budgets'); }
    check(count($hundred->calls)===200,'100 encounters reserve and dispatch exactly 200 HTTP requests including moderation');
    $q=$one->query('SELECT r.id,(SELECT COUNT(*) FROM simulation_opportunities o WHERE o.resident_a=r.id OR o.resident_b=r.id) AS n FROM residents r');foreach($q->fetchAll() as $row)check((int)$row['n']<=LittleSparks\activityConfiguration()['resident_daily'],'Per-resident daily activity cap');
    try{LittleSparks\simulateOpportunity($one,$at(101));throw new LogicException('Global cap ignored');}catch(LittleSparks\Problem $e){check($e->status===429,'100 daily opportunities cap');}
    $small=$dir.'/small-village.sqlite';cli('migrate-village.php',['--database='.$small]);cli('migrate-ai.php',['--database='.$small]);cli('migrate-activity.php',['--database='.$small]);$two=LittleSparks\connect($small);
    for($i=0;$i<2;$i++)LittleSparks\create($two,['name'=>'Restful '.$i,'color'=>'mint','antenna'=>'orb','personality'=>'shy','requestId'=>str_pad(dechex($i),32,'0',STR_PAD_LEFT),'website'=>''],'quiet-browser-'.$i,'quiet-network-'.$i,time(),true);
    $rest=new ActivityMock('quiet',$two);for($i=1;$i<=12;$i++)check(LittleSparks\simulateOpportunity($two,$at($i),true,$rest)['outcome']==='quiet','Quiet choices consume resident turns');
    $r=LittleSparks\simulateOpportunity($two,$at(13),true,$rest);check($r['outcome']==='none'&&count($rest->calls)===24,'Resident ceiling prevents further paid work even when daily request headroom remains');
    // Stale pending claims become failed once; unknown reserved cost is never refunded.
    putenv('LITTLE_SPARKS_AI_ENABLED=1');$db=$fresh();$claim=LittleSparks\claimActivity($db,$at(1),time());$config=LittleSparks\aiConfiguration(time());$held=LittleSparks\reserveAi($db,$claim['id'],$claim['pair'],$config,time());
    $db->exec('UPDATE simulation_opportunities SET expires_at=1');$db->exec('UPDATE ai_lease SET expires_at=1');$mock=new ActivityMock();$r=LittleSparks\simulateOpportunity($db,$at(1),true,$mock);check($r['replayed']&&$r['status']==='failed'&&!$mock->calls,'Expired paid claim fails without resend');check($db->query("SELECT state FROM ai_attempts WHERE id='".$held['id']."'")->fetchColumn()==='uncertain','Crash retains unknown reservation');
    $db=$fresh();$claim=LittleSparks\claimActivity($db,$at(1),time());$db->exec('UPDATE simulation_opportunities SET expires_at=1');$r=LittleSparks\simulateOpportunity($db,$at(1));check($r['status']==='failed'&&$r['replayed'],'Pre-dispatch crash does not fabricate activity');
    $db=null;copy($base,$dir.'/activity-concurrency.sqlite');
    echo "PASS shared V2B/V2C daily and monthly caps, fair selection, resident/global bounds, script fallback, crash recovery and preserved reservations\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
