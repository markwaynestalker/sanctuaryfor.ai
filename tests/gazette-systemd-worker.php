<?php
declare(strict_types=1);
// CI only: actual broker client and credential metadata, synthetic provider, no credential reads/HTTP.
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_broker_client.php';
final class SystemdGazetteMock implements LittleSparks\EditorialTransport
{
    public int $calls=0;
    public function post(string $path,array $body,int $deadline):array
    {
        $this->calls++;file_put_contents('/var/lib/sanctuary-gazette-test/calls',(string)$this->calls);
        if($path==='moderations')return ['request_id'=>'req_systemd_mod','body'=>['results'=>[['flagged'=>false]]]];
        $snapshot=json_decode($body['input'][0]['content'][0]['text'],true,32,JSON_THROW_ON_ERROR);
        $source=array_values(array_filter($snapshot['pack']['sources'],fn($s)=>$s['id']==='integrity-good'))[0];
        $ref=['source'=>$source['alias'],'sequence'=>0];$claim=['text'=>'A lost-and-found was proposed','claim_type'=>'proposal','references'=>[$ref]];
        $draft=['contract'=>'sanctuary.gazette.draft.v1','date'=>$snapshot['pack']['date'],'source_sha256'=>$snapshot['sha256'],
            'stories'=>[['kind'=>'lead','connection'=>'standalone','headline'=>$claim,'paragraphs'=>[$claim,$claim],'quotes'=>[$ref]]]];
        if(getenv('SANCTUARY_CI_BARRIER')==='1'){
            file_put_contents('/var/lib/sanctuary-gazette-test/dispatched','1');$until=microtime(true)+25;
            while(!is_file('/var/lib/sanctuary-gazette-test/continue')){if(microtime(true)>$until)throw new RuntimeException('Test barrier timeout');usleep(50000);}
        }
        return ['request_id'=>'req_systemd_gen','body'=>['id'=>'resp_systemd','status'=>'completed','model'=>'gpt-6-luna','service_tier'=>'default',
            'usage'=>['input_tokens'=>1000,'output_tokens'=>500],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($draft)]]]]]];
    }
}
try{
    if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||getenv('SANCTUARY_CI_SYSTEMD_TEST')!=='1'||posix_geteuid()===0)throw new RuntimeException();
    $mode=getenv('SANCTUARY_CI_MODE');
    if(in_array($mode,['disabled','ready','pin'],true)){
        $command=['/usr/bin/python3','-I',__DIR__.'/../ops/gazette/run.py',$mode==='disabled'?'--once':'--check'];
        $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException();
        fclose($pipes[0]);$out=stream_get_contents($pipes[1],8192);fclose($pipes[1]);stream_get_contents($pipes[2],8192);fclose($pipes[2]);$exit=proc_close($p);
        echo $out;exit($exit);
    }
    if(is_readable('/var/lib/sanctuaryfor-ai/village.sqlite'))throw new RuntimeException('DB readable');
    try{$db=new PDO('sqlite:/var/lib/sanctuaryfor-ai/village.sqlite',null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);throw new RuntimeException('DB opened');}catch(PDOException){}
    $delivery=LittleSparks\inspectSystemdCredential();if($delivery['reason']!==null)throw new RuntimeException('Credential metadata failed');
    $mock=new SystemdGazetteMock();$policy=LittleSparks\editorialConfiguration();$bytes=LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES');
    $result=LittleSparks\runBrokeredEditorialJob(new LittleSparks\GazetteUnixBroker(),$mock,$policy,$bytes,getenv('LITTLE_SPARKS_GAZETTE_EDITOR_RELEASE_SHA'));
    echo json_encode(['status'=>$result['status'],'mock_calls'=>$mock->calls,'credential_content_reads'=>0,'paid_requests'=>0])."\n";
    exit($result['status']==='awaiting_owner_review'?0:1);
}catch(Throwable){echo "{\"status\":\"refused_or_uncertain\",\"paid_requests\":0}\n";exit(1);}
