<?php
declare(strict_types=1);
require __DIR__.'/../app/dedicated_resident_agent.php';
use function LittleSparks\dedicatedResidentAgent;
use function LittleSparks\dedicatedResidentAgentSettings;
use function LittleSparks\dedicatedResidentSessionRequest;
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Dedicated agent routing test failed.');}
$settings=dedicatedResidentAgentSettings();check($settings===['15ebcd0b0da37bd5e00c1e2c32f2ee86'=>'LITTLE_SPARKS_AGENT_MAX','202deea1ccb5e92de581c90791aa4aad'=>'LITTLE_SPARKS_AGENT_SPARKY','28ad1522315b3a591bdc07923aa79bb5'=>'LITTLE_SPARKS_AGENT_PENELOPE','3da13508a7a04c46d8c11c3335539c04'=>'LITTLE_SPARKS_AGENT_WALTER','472d0da03b6c84681ad54cf775930c2f'=>'LITTLE_SPARKS_AGENT_LUNA','4cb65a7499928a9c1d007f0146ee7af9'=>'LITTLE_SPARKS_AGENT_PRINCESS','8ea38aa406bed321215ea78241e9bdb2'=>'LITTLE_SPARKS_AGENT_WANDA','8f51e90ed616a4c0ee9c7cc2e810ef7a'=>'LITTLE_SPARKS_AGENT_SKIPPY_JR','a9bd4cb87a828e8c365cdf94efe2820b'=>'LITTLE_SPARKS_AGENT_BLAKIE']);
foreach($settings as $id=>$setting){putenv($setting.'=agent_test_'.substr($id,0,8));check(dedicatedResidentAgent($id)==='agent_test_'.substr($id,0,8));}
check(dedicatedResidentAgent('00000000000000000000000000000000')===null);
putenv('LITTLE_SPARKS_AGENT_MAX=');try{dedicatedResidentAgent('15ebcd0b0da37bd5e00c1e2c32f2ee86');throw new RuntimeException('Accepted empty setting.');}catch(RuntimeException $e){check($e->getMessage()==='Invalid dedicated agent configuration.');}
putenv('LITTLE_SPARKS_AGENT_MAX=not-an-agent');try{dedicatedResidentAgent('15ebcd0b0da37bd5e00c1e2c32f2ee86');throw new RuntimeException('Accepted malformed setting.');}catch(RuntimeException $e){check($e->getMessage()==='Invalid dedicated agent configuration.');}
$context=['resident_id'=>'15ebcd0b0da37bd5e00c1e2c32f2ee86','input'=>'Actual words'];$body=dedicatedResidentSessionRequest('agent_test_max',$context);
check($body['agent_id']==='agent_test_max'&&!array_key_exists('agent',$body)&&json_decode($body['input'],true,512,JSON_THROW_ON_ERROR)===$context);
echo "PASS stable resident routing and saved-agent request construction (offline)\n";
