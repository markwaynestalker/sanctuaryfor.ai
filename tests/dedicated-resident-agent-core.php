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
$actor='15ebcd0b0da37bd5e00c1e2c32f2ee86';$other='3da13508a7a04c46d8c11c3335539c04';
foreach(['join','decline','defer','continue','pause','leave','finish','invite'] as $decision){
    $p=['resident_id'=>$actor,'decision'=>$decision,'dialogue'=>"  Hello, Walter!\n",'invite_resident_id'=>$decision==='invite'?$other:null];
    check(\LittleSparks\parseDedicatedResidentDecision($p,$actor,[$actor,$other])===$p);
}
$p=['resident_id'=>$actor,'decision'=>'leave','dialogue'=>null,'invite_resident_id'=>null];
check(\LittleSparks\parseDedicatedResidentDecision($p,$actor,[$actor,$other])===$p);
foreach([['resident_id'=>$other,'decision'=>'join','dialogue'=>null,'invite_resident_id'=>null],['resident_id'=>$actor,'decision'=>'invite','dialogue'=>null,'invite_resident_id'=>'00000000000000000000000000000000']] as $bad){try{\LittleSparks\parseDedicatedResidentDecision($bad,$actor,[$actor,$other]);throw new RuntimeException('Accepted invalid resident decision.');}catch(InvalidArgumentException){}}
echo "PASS independent resident decisions and exact optional dialogue (offline)\n";
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/ai_budget.php';
require __DIR__.'/../app/director_staging.php';
$decision=['resident_id'=>$actor,'decision'=>'continue','dialogue'=>"  Exact words.\n",'invite_resident_id'=>null];
$item=['type'=>'message','role'=>'assistant','phase'=>'final_answer','status'=>'completed','turn_id'=>'turn_mock','content'=>[['type'=>'output_text','text'=>json_encode($decision,JSON_THROW_ON_ERROR)]]];
$page=['has_more'=>false,'data'=>[$item]];
check(\LittleSparks\parseDedicatedManagedItems($page,'turn_mock',$actor,[$actor,$other],4096)===$decision);
$wrong=$page;$wrong['data'][0]['turn_id']='other_turn';try{\LittleSparks\parseDedicatedManagedItems($wrong,'turn_mock',$actor,[$actor,$other],4096);throw new RuntimeException('Accepted wrong turn.');}catch(\LittleSparks\AiGuard){}
$duplicate=$page;$duplicate['data'][]=$item;try{\LittleSparks\parseDedicatedManagedItems($duplicate,'turn_mock',$actor,[$actor,$other],4096);throw new RuntimeException('Accepted ambiguous output.');}catch(\LittleSparks\AiGuard){}
$wrong=$decision;$wrong['resident_id']=$other;$page['data'][0]['content'][0]['text']=json_encode($wrong,JSON_THROW_ON_ERROR);try{\LittleSparks\parseDedicatedManagedItems($page,'turn_mock',$actor,[$actor,$other],4096);throw new RuntimeException('Accepted impersonation.');}catch(\LittleSparks\AiGuard){}
echo "PASS managed item verification and resident attribution (offline)\n";
