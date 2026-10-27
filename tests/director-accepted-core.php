<?php
declare(strict_types=1);
// Exercise the production wire decoder, not a pre-decoded mock acknowledgement.
final class AcceptedWireTransport implements LittleSparks\DirectorTransport
{
    public array $calls=[];
    public function __construct(public LifecycleTransport $inner,private string $mode,private PDO $db){}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        $this->calls[]=[$method,$path,$body];$requestId='req_wire_'.count($this->calls);
        $r=$this->inner->request($method,$path,$body,$deadline,$key);
        $event=$body['events'][0]['type']??null;
        if($event!==null){
            check((int)$this->db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'Acceptance alone never persists dialogue');
            $status=$this->mode==='cancel409'&&$event==='agent.session.input.cancel'?409:202;
            return LittleSparks\managedHttpResponse($method,$path,$body,$status,'',$requestId);
        }
        $wire=json_encode($r,JSON_THROW_ON_ERROR);
        if(str_contains($path,'/turns?')){
            if($this->mode==='malformed')$wire='{invalid';
            if($this->mode==='empty_response')$wire='';
        }
        return LittleSparks\managedHttpResponse($method,$path,$body,200,$wire,$requestId);
    }
}
$eventBody=['events'=>[['type'=>'agent.session.input.message']]];
$ack=LittleSparks\managedHttpResponse('POST','agents/sessions/session_mock/events',$eventBody,202,'','req_empty_accepted');
check($ack===['_http'=>['status'=>202,'request_id'=>'req_empty_accepted']],'Empty 202 acknowledgement contains only actual HTTP metadata');
foreach([
    ['POST','agents/sessions',null,202,''],['GET','agents/sessions/session_mock',null,202,''],
    ['POST','moderations',null,202,''],['POST','agents/sessions/session_mock/events',$eventBody,200,''],
    ['POST','agents/sessions/session_mock/events',$eventBody,202,' '],
    ['POST','agents/sessions/session_mock/events',['events'=>[['type'=>'unsupported']]],202,''],
    ['POST','agents/sessions/session_mock/events',$eventBody,202,'{bad'],
    ['POST','agents/sessions/session_mock/events',$eventBody,409,''],
] as [$method,$path,$body,$status,$raw]){
    try{LittleSparks\managedHttpResponse($method,$path,$body,$status,$raw,'req_rejected');throw new LogicException('Invalid wire response accepted');}
    catch(LittleSparks\ManagedHttpFailure $e){check($e->status===$status&&$e->requestId==='req_rejected','Rejected envelopes preserve actual HTTP evidence');}
}
$wireSettings=[];foreach(['LITTLE_SPARKS_AI_DAILY_REQUESTS','LITTLE_SPARKS_AI_MONTHLY_MICROUSD'] as $key)$wireSettings[$key]=getenv($key);
putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
foreach([
    'completed'=>['good',['in_progress','completed'],true],
    'usage_missing'=>['usage_missing',['completed'],true],
    'failed'=>['good',['failed'],false], 'missing'=>['good',['empty'],false],
    'malformed'=>['good',['completed'],false], 'empty_response'=>['good',['completed'],false],
    'wrong_session'=>['wrong_session',['completed'],false], 'timeout'=>['http_error',['in_progress'],false],
    'deadline'=>['deadline',['in_progress'],false], 'cancel409'=>['good',['in_progress'],false],
    'flagged'=>['flagged',['completed'],false],
] as $mode=>[$innerMode,$states,$success]){
    $db=$stageFresh();$clock=new LifecycleClock();$now=$clock->now;
    $claim=LittleSparks\claimActivity($db,$at,$now);$cfg=LittleSparks\directorConfiguration();$registered=LittleSparks\registeredDirector($db,$cfg);
    $db->exec('BEGIN IMMEDIATE');$session=LittleSparks\directorSession($db,$registered,$claim['pair'],'agents',$now);$db->exec('COMMIT');
    $db->prepare("UPDATE director_sessions SET provider_session_id='session_mock',last_turn_id='turn_1',turns=1,state='ready' WHERE id=?")->execute([$session['id']]);
    $session['provider_session_id']='session_mock';$session['last_turn_id']='turn_1';
    $choices=['none'=>null];$prepared=LittleSparks\prepareDirectorEncounter($db,$claim['pair'],$choices,$claim,$cfg);
    $pricing=LittleSparks\aiConfiguration($now)+['director_session'=>$session['id'],'director_runtime'=>'agents','director_slots'=>8,'director_context'=>hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR))];
    $attempt=LittleSparks\reserveAi($db,$claim['id'],$claim['pair'],$pricing,$now);
    $transport=new AcceptedWireTransport(new LifecycleTransport($clock,$innerMode,$states,true),$mode,$db);
    try{
        $r=LittleSparks\stagingManagedEncounter($db,$transport,$attempt,$session,$registered,$prepared,$choices,fn()=>$clock->now,fn($seconds)=>$clock->pause($seconds),true,true);
        $proposal=$r['proposal']+['director_session'=>$session['id'],'director_fingerprint'=>$registered['fingerprint']];
        $applied=LittleSparks\applyActivity($db,$claim,$proposal,$choices,$now,$attempt,null);
    }catch(Throwable $e){$applied=LittleSparks\failActivity($db,$claim,$attempt,null,$e instanceof LittleSparks\AiGuard?$e->reason:'apply_rejected');}
    check(($applied['status']==='completed')===$success,'Wire acknowledgement preserves lifecycle outcome: '.$mode);
    check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===($success?2:0),'Only independently validated and moderated completed dialogue saves: '.$mode);
    $submit=$db->query("SELECT * FROM director_http_calls WHERE kind='submit'")->fetch(PDO::FETCH_ASSOC);
    check($submit['outcome']==='succeeded'&&(int)$submit['http_status']===202&&$submit['request_id']==='req_wire_2','Accepted submit retains status/request ID: '.$mode);
    $inputs=array_filter($transport->calls,fn($c)=>($c[2]['events'][0]['type']??null)==='agent.session.input.message');
    $cancels=array_filter($transport->calls,fn($c)=>($c[2]['events'][0]['type']??null)==='agent.session.input.cancel');
    check(count($inputs)===1&&count($cancels)<=1&&count($transport->calls)<=8,'One submission, bounded cleanup, eight reserved requests: '.$mode);
    check((int)$db->query('SELECT started_requests FROM director_attempts LIMIT 1')->fetchColumn()===count($transport->calls),'Every inspection/moderation/cleanup remains counted: '.$mode);
    check((int)$db->query('SELECT reserved_microusd FROM ai_attempts LIMIT 1')->fetchColumn()>=10000&&$db->query('SELECT estimated_microusd FROM ai_attempts LIMIT 1')->fetchColumn()===null,'Acceptance and failure never refund or invent billing: '.$mode);
    if($success){
        check(!$cancels,'Accepted completed input never triggers cancellation');
        $texts=$db->query('SELECT body FROM messages ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
        check($texts===["Could a daydream fit inside a lavender flower?","Perhaps, if it folds its wings."],'Original approved dialogue is unchanged');
    }
    if($mode==='cancel409'){
        $cancel=$db->query("SELECT * FROM director_http_calls WHERE kind='cancel'")->fetch(PDO::FETCH_ASSOC);
        check((int)$cancel['http_status']===409&&$cancel['outcome']==='uncertain','Cancellation conflict preserves uncertainty');
    }
    $before=$db->query('SELECT * FROM ai_attempts')->fetchAll(PDO::FETCH_ASSOC);$calls=count($transport->calls);
    $replay=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$transport);
    check($replay['replayed']&&count($transport->calls)===$calls&&$before===$db->query('SELECT * FROM ai_attempts')->fetchAll(PDO::FETCH_ASSOC),'Replay preserves historical holds without HTTP: '.$mode);
}
foreach($wireSettings as $key=>$value)putenv($value===false?$key:$key.'='.$value);
echo "PASS actual empty-202 wire decoding, asynchronous completion/failure, unchanged dialogue, moderation, missing usage, bounded cleanup/409, accounting and exact-once replay\n";
