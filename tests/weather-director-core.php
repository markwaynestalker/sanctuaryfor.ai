<?php
declare(strict_types=1);
// Mock provider speech exists only in this offline fixture, never application code.
final class WeatherMock implements LittleSparks\AiTransport
{
 public array $calls=[];public array $context=[];public array $moderated=[];public array $lines=[];
 public function __construct(public bool $flagged=false){}
 public function post(string $endpoint,array $body,int $deadline):array{
  $this->calls[]=$endpoint;
  if($endpoint==='moderations'){$this->moderated=json_decode($body['input'],true);return ['results'=>[['flagged'=>$this->flagged]]];}
  $this->context=json_decode($body['input'],true);$w=$this->context['village_weather'];
  $this->lines=[$w['kind']==='snowy'?'Could a snowman someday wear a tiny hat?':'Shall we enjoy this changing sky?','Perhaps a quiet moment together would be lovely.'];
  $p=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'The fictional village sky','messages'=>[
   ['resident_id'=>$this->context['participants'][0]['id'],'text'=>$this->lines[0]],['resident_id'=>$this->context['participants'][1]['id'],'text'=>$this->lines[1]]],'notes'=>[],'transmissions'=>[],'world_action'=>'none'];
  return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2200,'output_tokens'=>100,'total_tokens'=>2300],
   'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]];
 }
}
putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');
$registered=LittleSparks\registerDirector($db,['id'=>'agent_weather_offline','model'=>'gpt-6-luna','instructions'=>'OFFLINE_OWNER_EXPORT','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']]);
foreach(['DIRECTOR_AGENT_ID'=>$registered['agent_id'],'DIRECTOR_CONFIG_SHA256'=>$registered['fingerprint'],'AI_ENABLED'=>'1','DIRECTOR_ENABLED'=>'1','SIMULATION_ENABLED'=>'1','AI_PRICING_CONFIRMED'=>'2026-10-24-standard-v1','AI_DAILY_REQUESTS'=>'20','AI_MONTHLY_MICROUSD'=>'1000000','WEATHER_ENABLED'=>'1'] as $k=>$v)putenv('LITTLE_SPARKS_'.$k.'='.$v);
foreach(['sunny','rainy','foggy','snowy','rejected'] as $kind){
 $path=$dir.'/weather-'.$kind.'.sqlite';copy($argv[1],$path);chmod($path,0600);$fixture=LittleSparks\connect($path);
 $phrase=['sunny'=>'Fair','rainy'=>'Rain','foggy'=>'Fog','snowy'=>'Snow','rejected'=>'Snow'][$kind];$cfg=LittleSparks\villageWeatherConfiguration();$clean=LittleSparks\sanitizeFarmWeather(farmFixture(time(),$phrase),$cfg,time());$fixture->prepare("UPDATE settings SET value=? WHERE name='village_weather:latest'")->execute([json_encode(['source_id'=>$cfg['source_id'],'observation'=>$clean])]);$mock=new WeatherMock($kind==='rejected');$op=gmdate('Y-m-d\TH:i:00\Z',time()-120);
 $r=LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);check($r['status']===($kind==='rejected'?'failed':'completed'),'Mocked weather encounter '.$kind);
 check(count($mock->calls)===2,'Weather adds no HTTP/model/moderation requests');
 check($mock->context['village_weather']['kind']===($kind==='rejected'?'snowy':$kind),'Fresh weather supplied to Director');
 check($mock->moderated[0]['village_weather']===$mock->context['village_weather'],'Moderation receives the same frozen weather context');
 if($kind==='rejected')check((int)$fixture->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===0,'Moderation rejection fabricates no dialogue');
 else check($fixture->query('SELECT body FROM messages ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN)===$mock->lines,'Original weather-aware provider words saved unchanged');
 $started=count($mock->calls);LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);check(count($mock->calls)===$started,'Weather never replays generation');
 check($fixture->query('SELECT * FROM world_objects ORDER BY id')->fetchAll()===$world,'No snowman/object is invented');$fixture=null;
}
