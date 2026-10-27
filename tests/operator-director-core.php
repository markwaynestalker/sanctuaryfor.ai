<?php
declare(strict_types=1);
// Included only by the disposable operator fixture, with no live transport.
final class OperatorMock implements LittleSparks\AiTransport
{
 public array $calls=[];public array $context=[];public array $lines=['Could our tiny lanterns welcome a visiting bird?','Perhaps the garden has room for one more feather.'];
 public function __construct(public string $mode){}
 public function post(string $endpoint,array $body,int $deadline):array{
  $this->calls[]=$endpoint;
  if($endpoint==='moderations')return ['results'=>[['flagged'=>$this->mode==='flagged']]];
  $this->context=json_decode($body['input'],true);$participants=$this->context['participants'];
  $proposal=['contract'=>'sanctuary.director.v1','outcome'=>$this->mode==='quiet'?'quiet':'conversation','topic'=>'A welcome bird',
   'messages'=>$this->mode==='quiet'?[]:[['resident_id'=>$participants[0]['id'],'text'=>$this->lines[0]],['resident_id'=>$participants[1]['id'],'text'=>$this->lines[1]]],
   'world_action'=>'none','notes'=>[],'transmissions'=>[]];
  return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2000,'output_tokens'=>100,'total_tokens'=>2100],
   'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]]];
 }
}
putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');
$registration=LittleSparks\registerDirector($db,['id'=>'agent_operator_offline','model'=>'gpt-6-luna','instructions'=>'OFFLINE_OWNER_EXPORT','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']]);
putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$registration['agent_id']);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registration['fingerprint']);
putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');
putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=20');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
$pending=LittleSparks\submitOperatorIdea($db,['resident'=>$ids[1],'request_id'=>str_repeat('4',32),'idea'=>'Could a feathered guest visit?'],$now);
$baseline=(int)$db->query('SELECT COUNT(*) FROM operator_idea_exposures')->fetchColumn();
foreach(['good','flagged','quiet','disabled'] as $mode){
 $copy=dirname($argv[1]).'/operator-'.$mode.'.sqlite';copy($argv[1],$copy);chmod($copy,0600);$fixture=LittleSparks\connect($copy);
 if($mode==='disabled')putenv('LITTLE_SPARKS_OWNER_IDEAS_ENABLED=0');
 $mock=new OperatorMock($mode);$op=gmdate('Y-m-d\TH:i:00\Z',time()-60);$result=LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);
 check(($result['status']??null)===($mode==='flagged'?'failed':'completed'),'Mocked operator encounter '.$mode);
 $exposures=(int)$fixture->query('SELECT COUNT(*) FROM operator_idea_exposures')->fetchColumn();
 check($exposures===$baseline+($mode==='good'?1:0),'Only successfully accepted speech records context exposure: '.$mode);
 check(count($mock->calls)===2,'Suggestions add no HTTP requests');
 if($mode==='disabled')check(!isset($mock->context['operator_suggestions']),'Disabling suggestions preserves ordinary autonomous conversations');
 else check(in_array($pending['id'],array_column($mock->context['operator_suggestions'],'id'),true),'Fresh participating resident suggestion supplied');
 if(in_array($mode,['good','disabled'],true)){
  $texts=$fixture->query('SELECT body FROM messages ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN);check($texts===$mock->lines,'Provider dialogue saved unchanged without forced idea wording');
  $calls=count($mock->calls);LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);check(count($mock->calls)===$calls,'Encounter replay never regenerates');
 }
 $fixture=null;putenv('LITTLE_SPARKS_OWNER_IDEAS_ENABLED=1');
}
