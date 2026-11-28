<?php
declare(strict_types=1);
require __DIR__.'/../app/dedicated_resident_agent.php';
use function LittleSparks\dedicatedResidentAgent;
use function LittleSparks\dedicatedResidentAgentSettings;
use function LittleSparks\dedicatedResidentSessionRequest;
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Dedicated agent routing test failed.');}
$settings=dedicatedResidentAgentSettings();check(count($settings)===9&&count(array_unique($settings))===9);
foreach($settings as $id=>$setting){putenv($setting.'=agent_test_'.substr($id,0,8));check(dedicatedResidentAgent($id)==='agent_test_'.substr($id,0,8));}
check(dedicatedResidentAgent('00000000000000000000000000000000')===null);
putenv('LITTLE_SPARKS_AGENT_MAX=not-an-agent');try{dedicatedResidentAgent('15ebcd0b0da37bd5e00c1e2c32f2ee86');throw new RuntimeException('Accepted malformed setting.');}catch(RuntimeException $e){check($e->getMessage()==='Invalid dedicated agent configuration.');}
$context=['resident_id'=>'15ebcd0b0da37bd5e00c1e2c32f2ee86','input'=>'Actual words'];$body=dedicatedResidentSessionRequest('agent_test_max',$context);
check($body['agent_id']==='agent_test_max'&&!array_key_exists('agent',$body)&&json_decode($body['input'],true,512,JSON_THROW_ON_ERROR)===$context);
echo "PASS stable resident routing and saved-agent request construction (offline)\n";
