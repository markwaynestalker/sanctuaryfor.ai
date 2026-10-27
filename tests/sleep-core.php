<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/../app/scheduler.php';require __DIR__.'/../app/commons.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try{
    $dir=$argv[1];$db=LittleSparks\connect($dir.'/village.sqlite');
    foreach(['LITTLE_SPARKS_SLEEP_ENABLED','LITTLE_SPARKS_SLEEP_START_HOUR','LITTLE_SPARKS_SLEEP_END_HOUR'] as $key)putenv($key);
    check(!LittleSparks\villageSleepConfiguration()['enabled'],'Sleep activation defaults off');
    putenv('LITTLE_SPARKS_SLEEP_ENABLED=1');
    foreach(['2026-01-02T03:59:59Z'=>false,'2026-01-02T04:00:00Z'=>true,'2026-01-02T11:59:59Z'=>true,'2026-01-02T12:00:00Z'=>false,
        '2026-07-02T02:59:59Z'=>false,'2026-07-02T03:00:00Z'=>true,'2026-07-02T11:00:00Z'=>false,
        '2026-03-08T06:59:59Z'=>true,'2026-03-08T07:00:00Z'=>true,'2026-03-08T11:00:00Z'=>false,
        '2026-11-01T05:30:00Z'=>true,'2026-11-01T06:30:00Z'=>true,'2026-11-01T12:00:00Z'=>false] as $at=>$sleeping)
        check(LittleSparks\villageSleepingAt(strtotime($at))===$sleeping,'Exact New York boundary/DST: '.$at);
    check(LittleSparks\villageSleepState(strtotime('2026-03-08T04:00:00Z'))['next_transition_at']===strtotime('2026-03-08T11:00:00Z'),'Spring night wakes after seven real hours');
    check(LittleSparks\villageSleepState(strtotime('2026-11-01T03:00:00Z'))['next_transition_at']===strtotime('2026-11-01T12:00:00Z'),'Fall night wakes after nine real hours');
    foreach(['LITTLE_SPARKS_SLEEP_ENABLED'=>['','2','true','01'],'LITTLE_SPARKS_SLEEP_START_HOUR'=>['24','-1',' 1','1.5'],'LITTLE_SPARKS_SLEEP_END_HOUR'=>['23','99999999999999999999']] as $key=>$values){
        foreach($values as $v){putenv($key.'='.$v);try{LittleSparks\villageSleepConfiguration();throw new LogicException('Invalid sleep setting accepted');}catch(LittleSparks\Problem $e){check($e->status===503,'Invalid sleep ENV fails closed');}}
        putenv($key);if($key==='LITTLE_SPARKS_SLEEP_ENABLED')putenv($key.'=1');
    }
    putenv('LITTLE_SPARKS_SLEEP_START_HOUR=9');putenv('LITTLE_SPARKS_SLEEP_END_HOUR=17');
    check(LittleSparks\villageSleepingAt(strtotime('2026-01-02T14:00:00Z'))&&!LittleSparks\villageSleepingAt(strtotime('2026-01-02T22:00:00Z')),'Nonwrapping operator-configured schedule is authoritative');
    putenv('LITTLE_SPARKS_SLEEP_START_HOUR');putenv('LITTLE_SPARKS_SLEEP_END_HOUR');
    $time=gmdate('Y-m-d\TH:i:s\Z',time()-3600);$pair=[];
    foreach(['e','f'] as $i=>$letter){
        $id=str_repeat($letter,32);$pair[]=['id'=>$id,'name'=>'Sleep fixture '.$i];
        $db->prepare("INSERT INTO residents(id,name,color,antenna,personality,created_at,owner_hash,request_id,request_hash,token_hash) VALUES (?,?,'mint','orb','curious',?,?,?,?,?)")
            ->execute([$id,$pair[$i]['name'],$time,hash('sha256','sleep-owner-'.$i),str_repeat((string)($i+1),32),str_repeat('a',64),hash('sha256','sleep-token-'.$i)]);
        LittleSparks\assignHome($db,$id,$time);LittleSparks\identityForNewResident($db,$id,'Offline sleep fixture.','mint',$time);
    }
    $schema=$db->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll();
    $before=[];foreach(['residents','resident_identities','resident_states','world_objects','home_plots','director_sessions','ai_attempts'] as $table)$before[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
    copy($dir.'/village.sqlite',$dir.'/race.sqlite');chmod($dir.'/race.sqlite',0600);
    $night=(new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->setTime(23,0);
    if($night->getTimestamp()>time())$night=$night->modify('-1 day');
    $at=gmdate('Y-m-d\TH:i:00\Z',$night->getTimestamp());
    putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');
    $mock=new class implements LittleSparks\AiTransport{public int $calls=0;public function post(string $endpoint,array $body,int $deadline):array{$this->calls++;throw new LogicException('Sleeping residents must not invoke a provider');}};
    $r=LittleSparks\simulateOpportunity($db,$at,true,$mock);
    check($r['status']==='completed'&&$r['outcome']==='quiet'&&$r['reason']==='night_rest'&&$mock->calls===0,'Night opportunity records quiet, no speech/provider/cost');
    $replay=LittleSparks\simulateDirectorOpportunity($db,$at,true,$mock);
    check($replay['replayed']&&$replay['id']===$r['id']&&$mock->calls===0,'Missed/rest opportunities cannot be generated later');
    foreach($before as $table=>$rows)check($db->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll()===$rows,'Sleep leaves existing '.$table.' unchanged');
    check($db->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll()===$schema,'No migration needed');
    check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'No invented nighttime speech');
    $awake=strtotime('2026-07-02T11:00:00Z');
    check(count(LittleSparks\selectActivityResidents($db,gmdate('Y-m-d',$awake),null,$awake))===2,'Morning eligibility resumes from the schedule without state rewrites');
    check(LittleSparks\selectActivityResidents($db,'2026-07-02',null,strtotime('2026-07-02T04:00:00Z'))===[],'Sleeping residents excluded from activity selection');
    LittleSparks\recordSchedulerResult($db,$r);putenv('LITTLE_SPARKS_SLEEP_ENABLED=0');
    check(LittleSparks\villagePublicSleepState($db,strtotime('2026-07-02T04:00:00Z'))['sleeping'],'FPM uses published policy without CLI secrets or shared ENV');
    putenv('LITTLE_SPARKS_SLEEP_ENABLED=1');
    try{LittleSparks\reserveAi($db,'sleep-reserve',$pair,[],strtotime('2026-07-02T04:00:00Z'));throw new LogicException('Paid reservation while asleep');}catch(LittleSparks\AiGuard $e){check($e->reason==='night_rest','Low-level new reservation blocked before budget mutation');}
    // Reserve while awake, then cross into rest before any generation dispatch.
    putenv('LITTLE_SPARKS_SLEEP_ENABLED=0');
    $export=['id'=>'agent_sleep_fixture','model'=>'gpt-6-luna','instructions'=>'Offline fixture','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];
    $registered=LittleSparks\registerDirector($db,$export);
    putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=20');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $session=LittleSparks\directorSession($db,$registered,$pair,'agents',time());
    $pricing=LittleSparks\aiConfiguration(time())+['director_slots'=>8,'director_session'=>$session['id'],'director_runtime'=>'agents','director_context'=>str_repeat('a',64)];
    $attempt=LittleSparks\reserveAi($db,'sleep-dispatch-fixture',$pair,$pricing,time());
    $hour=(int)(new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->format('G');
    putenv('LITTLE_SPARKS_SLEEP_START_HOUR='.$hour);putenv('LITTLE_SPARKS_SLEEP_END_HOUR='.(($hour+2)%24));putenv('LITTLE_SPARKS_SLEEP_ENABLED=1');
    foreach(['create','submit','responses'] as $kind){
        try{if($kind==='responses')LittleSparks\startAiRequest($db,$attempt,'responses_started');else LittleSparks\startManagedHttp($db,$attempt,'POST',$kind==='create'?'agents/sessions':'agents/sessions/s/events');throw new LogicException('Generation dispatched during rest');}
        catch(LittleSparks\AiGuard $e){check($e->reason==='night_rest','Transactional bedtime dispatch fence: '.$kind);}
    }
    check((int)$db->query('SELECT COUNT(*) FROM director_http_calls')->fetchColumn()===0,'Rejected dispatches consume no new HTTP starts');
    LittleSparks\startManagedHttp($db,$attempt,'GET','agents/sessions/s');
    LittleSparks\startManagedHttp($db,$attempt,'POST','agents/sessions/s/events','cancel');
    LittleSparks\startManagedHttp($db,$attempt,'POST','moderations');
    $held=$db->query('SELECT state,reserved_microusd FROM ai_attempts')->fetch();
    check($held['state']==='reserved'&&(int)$held['reserved_microusd']===$attempt['reserved'],'Rest leaves full conservative hold intact while inspection/cleanup/moderation remain available');
    putenv('LITTLE_SPARKS_SLEEP_START_HOUR');putenv('LITTLE_SPARKS_SLEEP_END_HOUR');
    file_put_contents($dir.'/plan.json',json_encode(['at'=>$at],JSON_THROW_ON_ERROR));
    echo "PASS sleep defaults/config boundaries, New York/DST, persistent quiet idempotency, no model/ledger/history/state changes, wake eligibility, shared public policy and schema preservation\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
