<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/activity.php';require_once __DIR__.'/../app/managed_director_selection.php';
use function LittleSparks\{connect,reserveSelection,prepareManagedSelectionContext,managedSelectionAndHandoff};
function checkSelection(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);}
final class SelectionMock implements LittleSparks\DirectorTransport
{
    public array $calls=[];private array $context=[];private bool $cancelled=false;
    public function __construct(private string $mode,private int $clockValue){}
    private function remote():array{return ['id'=>'session_mock','object'=>'agent.session','status'=>'in_progress','agent'=>['id'=>'agent_test','model'=>'gpt-6-luna','service_tier'=>'default','tools'=>[],'multi_agent'=>['enabled'=>false]],'environment'=>['type'=>'none'],'required_actions'=>[],'vault_ids'=>[],'_http'=>['status'=>200]];}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        $this->calls[]=[$method,$path,$key];
        if($path==='agents/sessions'){
            checkSelection(is_string($key)&&strlen($key)===32,'generation idempotency identity');
            if($this->mode==='lost')throw new LittleSparks\ManagedHttpFailure();
            $this->context=json_decode($body['input'][0]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
            checkSelection(($body['agent']['text']['format']['schema']['properties']['contract']['enum'][0]??'')==='sanctuary.selection.v1','selection schema supplied');
            if($this->mode==='missing_http'){$r=$this->remote();unset($r['_http']);return $r;}return $this->remote();
        }
        if(str_ends_with($path,'/events')){$this->cancelled=true;return ['_http'=>['status'=>202]];}
        if(str_contains($path,'/turns?')){
            $status=$this->mode==='incomplete'?'in_progress':($this->mode==='failed'?'failed':'completed');
            return ['has_more'=>false,'data'=>[['id'=>'turn_mock','created_at'=>$this->clockValue,'agent_id'=>$this->mode==='wrong_agent'?'agent_wrong':'agent_test','session_id'=>$this->mode==='wrong_turn'?'bad_session':'session_mock','subagent_id'=>null,'status'=>$status,'error'=>null,'usage'=>['input_tokens'=>100,'output_tokens'=>20,'total_tokens'=>$this->mode==='bad_usage'?999:120]]],'_http'=>['status'=>200]];
        }
        if(str_contains($path,'/items?')){
            $ids=array_column($this->context['candidates'],'id');$proposal=['contract'=>'sanctuary.selection.v1','outcome'=>'conversation','participants'=>[$ids[0],$ids[1]]];
            if($this->mode==='quiet'){$proposal['outcome']='quiet';$proposal['participants']=[];}
            if($this->mode==='invalid')$proposal['participants'][1]=str_repeat('f',32);
            return ['has_more'=>false,'data'=>[['type'=>'message','role'=>'assistant','phase'=>$this->mode==='no_answer'?'commentary':'final_answer','status'=>'completed','turn_id'=>'turn_mock','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]],'_http'=>['status'=>200]];
        }
        if($this->mode==='missing_http'){$r=$this->remote();unset($r['_http']);return $r;}return $this->remote();
    }
}
putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_POLL_INTERVAL_SECONDS=0');
$export=['id'=>'agent_test','model'=>'gpt-6-luna','instructions'=>'','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];
$encoded=json_encode($export,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$registered=['agent_id'=>'agent_test','model'=>'gpt-6-luna','configuration'=>$encoded,'fingerprint'=>hash('sha256',$encoded)];
$now=time();if(86400-$now%86400<240)$now+=600;
foreach(['good','quiet','invalid','lost','missing_http','wrong_agent','wrong_turn','bad_usage','no_answer','failed','incomplete'] as $mode){
    $path=$argv[2].'/selection-adapter-'.$mode.'.sqlite';copy($argv[1],$path);$db=connect($path);$at=gmdate('Y-m-d\TH:i:s\Z',$now);$db->prepare('INSERT INTO director_registry VALUES (?,?,?,?,?,?)')->execute([$registered['fingerprint'],'agent_test','gpt-6-luna','',$encoded,$at]);
    foreach(['a','b','c'] as $ch){$id=str_repeat($ch,32);$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Test '.$ch,'mint','orb','curious',$at,hash('sha256','owner'.$id),$id,hash('sha256','request'.$id),hash('sha256','token'.$id)]);$db->prepare("INSERT INTO resident_states VALUES (?,'available','commons',?)")->execute([$id,$at]);$db->prepare('INSERT INTO resident_identities VALUES (?,?,?,?,?)')->execute([$id,'Owner personality '.$ch,'mint',1,$at]);}
        $a=str_repeat('a',32);$b=str_repeat('b',32);$earlier=gmdate('Y-m-d\TH:i:s\Z',$now-300);
    foreach(['public'=>'PUBLIC_HISTORY_CANARY','private'=>'PRIVATE_HISTORY_CANARY'] as $visibility=>$text){
        $id=bin2hex(random_bytes(16));$run=bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,interaction_count) VALUES (?,?,'completed','scripted',?,?,1)")->execute([$run,'fixture:'.$run,$earlier,$earlier]);
        $db->prepare("INSERT INTO conversations VALUES (?,?,'garden',?,?)")->execute([$id,$run,$visibility,$earlier]);
        foreach([$a,$b] as $who)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$who]);
        foreach([$text,'A reply'] as $i=>$line)$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$id,$i,[$a,$b][$i],$line]);
        $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,?,'conversation','simulation','public',?,?)")->execute([$a,$b,$run,$earlier,'social:'.$run.':'.$id]);
    }
    $db->prepare("INSERT INTO simulation_opportunities(id,scheduled_at,day,resident_a,resident_b,outcome,expires_at,created_at) VALUES (?, ?, ?, NULL,NULL,'pending',?,?)")->execute(['op:test',$at,gmdate('Y-m-d',$now),$now+90,$at]);
    $pricing=['daily_requests'=>16,'monthly_cap'=>1000000,'max_output'=>512,'input_rate'=>125000,'output_rate'=>500000,'lease_seconds'=>60,'model'=>'gpt-6-luna','director_runtime'=>'agents','director_slots'=>8];
    $context=prepareManagedSelectionContext($db,gmdate('Y-m-d',$now),$at,['candidates'=>64,'resident_daily'=>12]);
    checkSelection(count($context['candidates'])===3&&str_contains(json_encode($context,JSON_THROW_ON_ERROR),'Owner personality'),'candidate identities supplied'); $raw=json_encode($context,JSON_THROW_ON_ERROR);checkSelection(str_contains($raw,'PUBLIC_HISTORY_CANARY')&&!str_contains($raw,'PRIVATE_HISTORY_CANARY')&&count($context['personal_public_history'])===2,'history stays public and holder-attributed');
    $r=reserveSelection($db,'op:test',$pricing,$now,$pricing,true);$mock=new SelectionMock($mode,$now);$ok=false;$result=null;if($mode==='good'){        putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=0');
        try{managedSelectionAndHandoff($db,$mock,$r,$registered,$context,['id'=>'op:test','created_at'=>$at],$pricing,$now,static fn()=>$now,static fn(int $seconds)=>null);throw new RuntimeException('disabled selection accepted');}catch(LittleSparks\AiGuard $e){checkSelection($e->reason==='configuration_invalid','disabled selection refused');}
        putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=1');
        foreach(['LITTLE_SPARKS_DIRECTOR_RUNTIME'=>'responses','LITTLE_SPARKS_DIALOGUE_MODE'=>'independent'] as $key=>$value){
            putenv($key.'='.$value);try{managedSelectionAndHandoff($db,$mock,$r,$registered,$context,['id'=>'op:test','created_at'=>$at],$pricing,$now,static fn()=>$now,static fn(int $seconds)=>null);throw new RuntimeException('unsupported runtime accepted');}catch(LittleSparks\AiGuard $e){checkSelection($e->reason==='configuration_invalid','unsupported runtime refused');}
            putenv($key==='LITTLE_SPARKS_DIRECTOR_RUNTIME'?$key.'=agents':$key);
        }
        checkSelection(count($mock->calls)===0,'unsupported modes fail before dispatch');
    }
    try{$result=managedSelectionAndHandoff($db,$mock,$r,$registered,$context,['id'=>'op:test','created_at'=>$at],$pricing,$now,static fn()=>$now,static fn(int $seconds)=>null);$ok=true;}catch(Throwable $e){if(in_array($mode,['good','quiet'],true))throw $e;}
    checkSelection($ok===in_array($mode,['good','quiet'],true),'only valid decisions succeed: '.$mode);
    if($ok)checkSelection(count($result['decision']['participants'])===($mode==='quiet'?0:2),'pair or quiet preserved');
    $q=$db->prepare('SELECT state FROM selection_reservations WHERE id=?');$q->execute([$r['id']]);checkSelection($q->fetchColumn()===($ok?'completed':'uncertain'),'decision state fails closed');
    checkSelection((int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===2,'no fabricated conversation');if($mode==='good'){        putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=0');
        try{managedSelectionAndHandoff($db,$mock,$r,$registered,$context,['id'=>'op:test','created_at'=>$at],$pricing,$now,static fn()=>$now,static fn(int $seconds)=>null);throw new RuntimeException('disabled selection accepted');}catch(LittleSparks\AiGuard $e){checkSelection($e->reason==='configuration_invalid','disabled selection refused');}
        putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=1');$q=$db->query("SELECT resident_a,resident_b FROM simulation_opportunities WHERE id='op:test'");$bound=$q->fetch();checkSelection($bound['resident_a']===$result['decision']['participants'][0]&&$bound['resident_b']===$result['decision']['participants'][1]&&isset($result['attempt']),'validated pair atomically bound to existing attempt');}else checkSelection((int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===0,'no conversation attempt for quiet or failed selection');
    $q=$db->prepare('SELECT COUNT(*) FROM managed_selection_http WHERE selection_id=?');$q->execute([$r['id']]);checkSelection((int)$q->fetchColumn()===count($mock->calls),'every provider request audited');
    checkSelection(count(array_filter($mock->calls,fn($call)=>$call[1]==='agents/sessions'))===1,'no generation retry'); $seen=count($mock->calls);try{managedSelectionAndHandoff($db,$mock,$r,$registered,$context,['id'=>'op:test','created_at'=>$at],$pricing,$now,static fn()=>$now,static fn(int $seconds)=>null);}catch(Throwable){}checkSelection(count($mock->calls)===$seen,'replay never dispatches');
    if($mode==='incomplete')checkSelection(count(array_filter($mock->calls,fn($call)=>str_ends_with($call[1],'/events')))===1,'one bounded cancellation');
    checkSelection(!$db->query('PRAGMA foreign_key_check')->fetch()&&$db->query('PRAGMA integrity_check')->fetchColumn()==='ok','database integrity');$db=null;
}
echo "PASS mocked managed selection: pair, quiet, invalid choice, lost request, wrong turn, cancellation and durable accounting\n";