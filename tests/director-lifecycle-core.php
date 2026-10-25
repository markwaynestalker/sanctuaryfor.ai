<?php
declare(strict_types=1);
// Included after the existing V6 fixtures; transports and clocks are entirely offline.
final class LifecycleClock
{
    public int $now;
    public function __construct(){ $this->now=time(); }
    public function pause(int $seconds):void{check($seconds<=4,'Sleep stays bounded');$this->now+=$seconds;}
}
final class LifecycleTransport implements LittleSparks\DirectorTransport
{
    public array $calls=[];public int $inspections=0;public bool $cancelled=false;
    public ManagedMock $inner;
    public function __construct(public LifecycleClock $clock,public string $mode,public array $states,public bool $reusing=false){$this->inner=new ManagedMock(in_array($mode,['flagged','action_flagged'],true)?'flagged':'good');if($reusing)$this->inner->turn=1;}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        check($deadline>$this->clock->now&&$deadline<=$this->clock->now+8,'Each HTTP deadline is bounded');$this->calls[]=[$method,$path,$deadline];
        if(str_ends_with($path,'/events')&&($body['events'][0]['type']??null)==='agent.session.input.cancel'){
            $this->cancelled=true;if($this->mode==='cancel_uncertain')throw new LittleSparks\ManagedHttpFailure(202,'req_cancel_uncertain');return [];
        }
        if(str_contains($path,'/turns?')){
            $this->inspections++;
            if($this->mode==='http_error'&&$this->inspections===1)throw new LittleSparks\ManagedHttpFailure(503,'req_inspection_failed');
            if($this->mode==='deadline'){$this->clock->now=$deadline+20;throw new LittleSparks\ManagedHttpFailure();}
            $state=$this->cancelled?'cancelled':($this->states[min($this->inspections-1,count($this->states)-1)]);
            if($state==='empty')return ['object'=>'list','has_more'=>false,'data'=>[]];
            $r=$this->inner->request($method,$path,$body,$deadline,$key);$r['has_more']=$this->reusing;
            $r['data'][0]['created_at']=$this->clock->now;$r['data'][0]['error']=null;$r['data'][0]['status']=$state==='stale'?'completed':$state;
            if($state==='stale'){$r['data'][0]['id']='turn_1';$r['data'][0]['created_at']=$this->clock->now-100;}
            if($this->mode==='wrong_session')$r['data'][0]['session_id']='session_other';
            if($this->mode==='wrong_agent')$r['data'][0]['agent_id']='agent_other';
            if($this->mode==='missing_id')unset($r['data'][0]['id']);
            if($this->mode==='changed_turn'&&$this->inspections>1)$r['data'][0]['id']='turn_changed';
            if($this->mode==='old_created')$r['data'][0]['created_at']=$this->clock->now-100;
            if($this->mode==='missing_created')unset($r['data'][0]['created_at']);
            return $r;
        }
        $r=$this->inner->request($method,$path,$body,$deadline,$key);
        if(str_contains($path,'/items?')&&$this->mode==='malformed')$r['data'][0]['content'][0]['text']='not JSON';
        if(str_contains($path,'/items?')&&str_starts_with($this->mode,'action')){$p=json_decode($r['data'][0]['content'][0]['text'],true);$p['world_action']='plant_flowers';$r['data'][0]['content'][0]['text']=json_encode($p,JSON_THROW_ON_ERROR);}
        return $r;
    }
}
try{
    $settings=[];foreach(['LITTLE_SPARKS_AI_DAILY_REQUESTS','LITTLE_SPARKS_AI_MONTHLY_MICROUSD'] as $key)$settings[$key]=getenv($key);
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $cases=[
        'immediate'=>[['completed'],true,false], 'queued'=>[['queued','completed'],true,false],
        'running'=>[['queued','in_progress','completed'],true,false], 'empty'=>[['empty','completed'],true,false], 'waiting'=>[['waiting','completed'],true,false],
        'reused'=>[['stale','in_progress','completed'],true,true], 'stale'=>[['stale'],false,true],
        'never'=>[['in_progress'],false,false], 'deadline'=>[['in_progress'],false,false],
        'http_error'=>[['in_progress'],false,false], 'wrong_session'=>[['completed'],false,false],
        'wrong_agent'=>[['completed'],false,false], 'missing_id'=>[['completed'],false,false],
        'changed_turn'=>[['queued','completed'],false,false], 'old_created'=>[['completed'],false,false],
        'malformed'=>[['completed'],false,false], 'flagged'=>[['completed'],false,false],
        'cancel_uncertain'=>[['in_progress'],false,false], 'missing_created'=>[['completed'],false,true],
        'action'=>[['in_progress','completed'],true,false], 'action_flagged'=>[['in_progress','completed'],false,false],
    ];
    foreach($cases as $mode=>[$states,$success,$reusing]){
        $db=$stageFresh();$clock=new LifecycleClock();$now=$clock->now;$claim=LittleSparks\claimActivity($db,$at,$now);$cfg=LittleSparks\directorConfiguration();$registered=LittleSparks\registeredDirector($db,$cfg);
        $db->exec('BEGIN IMMEDIATE');$session=LittleSparks\directorSession($db,$registered,$claim['pair'],'agents',$now);$db->exec('COMMIT');
        if($reusing){$db->prepare("UPDATE director_sessions SET provider_session_id='session_mock',last_turn_id='turn_1',turns=1,state='ready' WHERE id=?")->execute([$session['id']]);$session['provider_session_id']='session_mock';$session['last_turn_id']='turn_1';}
        $action=str_starts_with($mode,'action');$choices=$action?LittleSparks\aiActionChoices($db,$claim['pair'][0]['id'],$claim['pair'][1]['id'],$now):['none'=>null];$prepared=LittleSparks\prepareDirectorEncounter($db,$claim['pair'],$choices,$claim,$cfg);
        $objects=(int)$db->query('SELECT COUNT(*) FROM world_objects')->fetchColumn();
        $pricing=LittleSparks\aiConfiguration($now)+['director_session'=>$session['id'],'director_runtime'=>'agents','director_slots'=>8,'director_context'=>hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR))];
        $attempt=LittleSparks\reserveAi($db,$claim['id'],$claim['pair'],$pricing,$now);$transport=new LifecycleTransport($clock,$mode,$states,$reusing);
        try{
            $r=LittleSparks\stagingManagedEncounter($db,$transport,$attempt,$session,$registered,$prepared,$choices,fn()=>$clock->now,fn($seconds)=>$clock->pause($seconds),!$action,true);
            $proposal=$r['proposal']+['director_session'=>$session['id'],'director_fingerprint'=>$registered['fingerprint']];
            $applied=LittleSparks\applyActivity($db,$claim,$proposal,$choices,$now,$attempt,null);
        }catch(Throwable $e){$applied=LittleSparks\failActivity($db,$claim,$attempt,null,$e instanceof LittleSparks\AiGuard?$e->reason:'apply_rejected');}
        check(($applied['status']==='completed')===$success,'Truthful lifecycle outcome: '.$mode);
        check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===($success?2:0),'Only completed, validated, moderated dialogue saved: '.$mode);
        check(count(array_filter($transport->calls,fn($c)=>$c[0]==='POST'&&($c[1]==='agents/sessions'||str_ends_with($c[1],'/events'))))<=($transport->cancelled?2:1),'No duplicate creation/input: '.$mode);
        check(count($transport->calls)<=8&&(int)$db->query('SELECT started_requests FROM director_attempts LIMIT 1')->fetchColumn()===count($transport->calls),'Polling/cleanup count inside eight reserved slots: '.$mode);
        check(max(array_column($transport->calls,2))<=$now+44,'All request deadlines leave process cleanup before 50 seconds: '.$mode);
        check((int)$db->query('SELECT COUNT(*) FROM world_objects')->fetchColumn()===$objects+($mode==='action'?1:0),'Approved world action is atomic with moderation/dialogue: '.$mode);
        check((int)$db->query('SELECT reserved_microusd FROM ai_attempts LIMIT 1')->fetchColumn()>=10000&&$db->query('SELECT estimated_microusd FROM ai_attempts LIMIT 1')->fetchColumn()===null,'No refund or fabricated billing: '.$mode);
        $n=count($transport->calls);$replay=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$transport);
        check($replay['replayed']&&count($transport->calls)===$n,'Completed and failed opportunities replay without generation: '.$mode);
        if($success)check(!$transport->cancelled&&$db->query('SELECT state FROM director_sessions LIMIT 1')->fetchColumn()==='ready','Continuity committed only with valid result: '.$mode);
        if(in_array($mode,['never','cancel_uncertain'],true))check($transport->inspections>=3&&$transport->cancelled,'Exhaust bounded read-only observations before one cancellation');
        if($mode==='cancel_uncertain')check($db->query('SELECT status FROM director_managed_outcomes ORDER BY id DESC LIMIT 1')->fetchColumn()==='cancel_uncertain','Cancellation acknowledgement/outcome never invented');
        if($mode==='running')check(count($transport->calls)===8,'Delayed success fits full eight-request envelope');
    }
    // An exhausted scheduler process budget must refuse before any HTTP dispatch.
    define('LITTLE_SPARKS_SCHEDULED_DEADLINE',time()-1);
    $db=$stageFresh();$clock=new LifecycleClock();$now=$clock->now;$claim=LittleSparks\claimActivity($db,$at,$now);$cfg=LittleSparks\directorConfiguration();$registered=LittleSparks\registeredDirector($db,$cfg);
    $db->exec('BEGIN IMMEDIATE');$session=LittleSparks\directorSession($db,$registered,$claim['pair'],'agents',$now);$db->exec('COMMIT');$choices=['none'=>null];$prepared=LittleSparks\prepareDirectorEncounter($db,$claim['pair'],$choices,$claim,$cfg);
    $pricing=LittleSparks\aiConfiguration($now)+['director_session'=>$session['id'],'director_runtime'=>'agents','director_slots'=>8,'director_context'=>hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR))];$attempt=LittleSparks\reserveAi($db,$claim['id'],$claim['pair'],$pricing,$now);$transport=new LifecycleTransport($clock,'immediate',['completed']);
    try{LittleSparks\stagingManagedEncounter($db,$transport,$attempt,$session,$registered,$prepared,$choices,fn()=>$clock->now,fn($seconds)=>$clock->pause($seconds));throw new LogicException('Expired scheduler budget dispatched');}catch(LittleSparks\AiGuard){}
    check(!$transport->calls,'Scheduler deadline stops dispatch even when encounter lease is still valid');
    foreach($settings as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    echo "PASS bounded asynchronous lifecycle: queued/running/empty then completed, reused/stale turns, deadline/HTTP/identity failures, moderation, exact-once save/replay, counted reads and uncertain cancellation (offline clocks)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
