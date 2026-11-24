<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require_once __DIR__.'/../app/activity.php';require_once __DIR__.'/../app/managed_director_selection.php';
function ck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
final class ScheduledSelectionMock implements LittleSparks\DirectorTransport {
 public array $calls=[];private array $context=[];private string $stage='';
 public function __construct(private string $mode){}
 public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array {
  $this->calls[]=[$method,$path];$http=['_http'=>['status'=>200]];
  if($path==='agents/sessions'){
   $this->context=json_decode($body['input'][0]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);$this->stage=($this->context['contract']==='sanctuary.selection.v1')?'selection':'conversation';
   if($this->stage==='selection'&&$this->mode==='lost')throw new LittleSparks\ManagedHttpFailure();
   return $this->remote()+$http;
  }
  if($path==='moderations')return ['results'=>[['flagged'=>$this->mode==='flagged']]]+$http;
  if(str_ends_with($path,'/events'))return $http;
  if(str_contains($path,'/turns?'))return ['has_more'=>false,'data'=>[['id'=>'turn_'.$this->stage,'created_at'=>time(),'agent_id'=>'agent_test','session_id'=>'session_'.$this->stage,'subagent_id'=>null,'status'=>'completed','error'=>null,'usage'=>['input_tokens'=>100,'output_tokens'=>20,'total_tokens'=>120]]]]+$http;
  if(str_contains($path,'/items?')){
   if($this->stage==='selection'){$ids=array_column($this->context['candidates'],'id');$p=['contract'=>'sanctuary.selection.v1','outcome'=>$this->mode==='quiet'?'quiet':'conversation','participants'=>$this->mode==='quiet'?[]:[$ids[1],$ids[0]]];if($this->mode==='invalid')$p['participants'][0]=str_repeat('f',32);}
   else{$a=$this->context['participants'];$p=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'A welcome','messages'=>[['resident_id'=>$a[0]['id'],'text'=>'Hello, neighbor.'],['resident_id'=>$a[1]['id'],'text'=>'A pleasant day to you.']],'world_action'=>'none','transmissions'=>[],'notes'=>[]];}
   return ['has_more'=>false,'data'=>[['type'=>'message','role'=>'assistant','phase'=>'final_answer','status'=>'completed','turn_id'=>'turn_'.$this->stage,'content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]]+$http;
  }
  return $this->remote()+$http;
 }
 private function remote():array{return ['id'=>'session_'.$this->stage,'object'=>'agent.session','status'=>'in_progress','agent'=>['id'=>'agent_test','model'=>'gpt-6-luna','service_tier'=>'default','tools'=>[],'multi_agent'=>['enabled'=>false]],'environment'=>['type'=>'none'],'required_actions'=>[],'vault_ids'=>[]];}
}
foreach(['LITTLE_SPARKS_AI_ENABLED','LITTLE_SPARKS_DIRECTOR_ENABLED','LITTLE_SPARKS_SIMULATION_ENABLED'] as $flag)putenv($flag.'=1');
putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID=agent_test');putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED=1');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=16');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_DIRECTOR_POLL_INTERVAL_SECONDS=0');
$export=['id'=>'agent_test','model'=>'gpt-6-luna','instructions'=>'','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];$encoded=json_encode($export,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$fingerprint=hash('sha256',$encoded);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$fingerprint);
foreach(['good','quiet','invalid','lost','flagged','budget','monthly','disabled','interrupted','unsupported'] as $mode){
 $path=$argv[2].'/scheduled-'.$mode.'.sqlite';copy($argv[1],$path);$db=LittleSparks\connect($path);$now=time();$at=gmdate('Y-m-d\TH:i:00\Z',$now-60);$stamp=gmdate('Y-m-d\TH:i:s\Z',$now);
 $db->prepare('INSERT INTO director_registry VALUES (?,?,?,?,?,?)')->execute([$fingerprint,'agent_test','gpt-6-luna','',$encoded,$stamp]);
 foreach(['a','b','c'] as $ch){$id=str_repeat($ch,32);$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Test '.$ch,'mint','orb','curious',$stamp,hash('sha256','owner'.$id),$id,hash('sha256','request'.$id),hash('sha256','token'.$id)]);$db->prepare("INSERT INTO resident_states VALUES (?,'available','commons',?)")->execute([$id,$stamp]);$db->prepare('INSERT INTO resident_identities VALUES (?,?,?,?,?)')->execute([$id,'Owner personality '.$ch,'mint',1,$stamp]);}
 putenv('LITTLE_SPARKS_AI_SELECTION_ENABLED='.($mode==='disabled'?'0':'1'));putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.($mode==='budget'?'15':'16'));putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD='.($mode==='monthly'?'1':'1000000'));
 if($mode==='interrupted'){
  $claim=LittleSparks\claimActivity($db,$at,$now,true);$pricing=LittleSparks\aiConfiguration($now);$capacity=$pricing+['director_runtime'=>'agents','director_slots'=>8];$reservation=LittleSparks\reserveSelection($db,$claim['id'],$pricing,$now,$capacity,true);
  ck(LittleSparks\selectionLeaseActive($db,time()),'pending reservation holds lease');LittleSparks\failActivity($db,$claim,null,null,'execution_deadline');ck(!LittleSparks\selectionLeaseActive($db,time()),'terminal opportunity releases only active lease');
  try{LittleSparks\startManagedSelectionHttp($db,$reservation,'POST','agents/sessions',null,time());throw new RuntimeException('interrupted worker dispatched');}catch(LittleSparks\AiGuard){}
  ck((int)$db->query('SELECT COUNT(*) FROM managed_selection_http')->fetchColumn()===0&&LittleSparks\selectionCommitted($db,gmdate('Y-m'))>0,'stale worker fenced and commitments retained');continue;
 }
 if($mode==='unsupported'){putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');$mock=new ScheduledSelectionMock($mode);try{LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);throw new RuntimeException('unsupported mode accepted');}catch(LittleSparks\AiGuard){}ck(!$mock->calls,'unsupported mode has no dispatch');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');continue;}
 $mock=new ScheduledSelectionMock($mode);$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock,null,null,false,null,true);
 $expected=in_array($mode,['good','quiet','disabled'],true)?'completed':'failed';ck(($result['status']??'')===$expected,$mode.' status '.json_encode($result));
 $n=(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();ck($n===(in_array($mode,['good','disabled'],true)?1:0),$mode.' conversation count');
 $count=count($mock->calls);$again=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock,null,null,false,null,true);ck(!empty($again['replayed'])&&count($mock->calls)===$count,$mode.' replay');
 if(in_array($mode,['budget','monthly'],true))ck($count===0,'budget before dispatch');
 if($mode==='good'){$o=$db->query('SELECT resident_a,resident_b FROM simulation_opportunities')->fetch();ck($o['resident_a']===str_repeat('b',32)&&$o['resident_b']===str_repeat('a',32),'Director pair bound');ck((int)$db->query('SELECT COUNT(*) FROM selection_capacity_transfers')->fetchColumn()===1,'capacity transferred');ck((int)$db->query("SELECT COUNT(*) FROM director_http_calls WHERE kind='moderation'")->fetchColumn()===1,'moderation performed');}
 if($mode==='quiet')ck($result['outcome']==='quiet'&&(int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===0&&!LittleSparks\selectionLeaseActive($db,time()),'quiet finalized and lease released');
 if(in_array($mode,['good','quiet','invalid','lost','flagged'],true)){
  $selectionSlots=LittleSparks\selectionReservedRequests($db,gmdate('Y-m-d'));$conversationSlots=(int)$db->query('SELECT COALESCE(SUM(request_slots),0) FROM director_attempts')->fetchColumn();
  ck($selectionSlots+$conversationSlots===16,'both stages counted exactly once');ck(LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))>=($mode==='good'?10000:20000),'selection hold and conversation accounting retained');
 }
 if($mode==='disabled')ck((int)$db->query('SELECT COUNT(*) FROM selection_reservations')->fetchColumn()===0,'disabled uses old path');
 ck(!$db->query('PRAGMA foreign_key_check')->fetch()&&$db->query('PRAGMA integrity_check')->fetchColumn()==='ok','integrity');
}
echo "PASS scheduled selection, handoff, moderation, quiet, failures, replay, budget and disabled path (offline)\n";
