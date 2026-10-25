<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director_access.php';
$dir=$argv[1]??'';if(!str_starts_with(basename($dir),'sparks-agent-access-'))throw new RuntimeException('Disposable fixture only.');
final class AccessMock implements LittleSparks\AgentAccessReadTransport
{
    public int $calls=0;
    public function __construct(private string $mode){}
    public function retrieve():array{
        $this->calls++;if($this->mode==='timeout')throw new RuntimeException('SECRET_KEY_CANARY');
        return ['status'=>$this->mode==='denied'?403:($this->mode==='redirect'?302:200),'request_id'=>'req_test',
            'data'=>['object'=>'agent','id'=>$this->mode==='wrong'?'agent_wrong':LittleSparks\ACCESS_CHECK_AGENT,'model'=>$this->mode==='model'?'other-model':'gpt-6-luna',
                'tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','instructions'=>'PRIVATE_INSTRUCTIONS_CANARY','metadata'=>['secret'=>'SECRET_KEY_CANARY']]];
    }
}
foreach(['good','denied','redirect','wrong','timeout','model'] as $mode){
    $mock=new AccessMock($mode);$file=$dir.'/'.$mode.'.jsonl';$result=LittleSparks\agentAccessPreflight($file,$mock);
    if($mock->calls!==1||$result['generation_requests']!==0||$result['authorized']!==in_array($mode,['good','model'],true))throw new RuntimeException('Wrong access outcome');
    if($mode==='model'&&$result['model_matches']!==false)throw new RuntimeException('Access is not configuration compatibility');
    $audit=(string)file_get_contents($file);if(str_contains($audit,'SECRET_KEY_CANARY')||str_contains($audit,'PRIVATE_INSTRUCTIONS_CANARY'))throw new RuntimeException('Private data leaked');
    $rows=array_map(fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),explode("\n",trim($audit)));
    if(count($rows)!==2||$rows[0]['state']!=='started'||$rows[1]['check_id']!==$rows[0]['check_id'])throw new RuntimeException('Missing durable audit');
    try{LittleSparks\agentAccessPreflight($file,$mock);throw new LogicException('Duplicate request admitted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'already recorded'))throw $e;}
    if($mock->calls!==1)throw new RuntimeException('Duplicate HTTP');
}
echo "PASS one-read agent authorization, safe configuration booleans, durable request intent, no automatic repeats, denied/redirect/unknown outcomes and no key/instructions/body disclosure (mocked only)\n";
