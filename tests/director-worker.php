<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';
final class ConcurrentDirectorMock implements LittleSparks\AiTransport
{
    public function post(string $endpoint,array $body,int $deadline):array{
        if($endpoint==='moderations')return ['results'=>[['flagged'=>false]]];
        usleep(150000);$p=json_decode($body['input'],true)['participants'];
        $proposal=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'A small question','messages'=>[['resident_id'=>$p[0]['id'],'text'=>'Could a cloud keep a secret?'],['resident_id'=>$p[1]['id'],'text'=>'Perhaps it would whisper in rain.']],'world_action'=>'none','transmissions'=>[],'notes'=>[]];
        return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2200,'output_tokens'=>180,'total_tokens'=>2380],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]]];
    }
}
try{
    $db=LittleSparks\connect($argv[1]);$r=$db->query('SELECT fingerprint,agent_id FROM director_registry LIMIT 1')->fetch();
    putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$r['fingerprint']);putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$r['agent_id']);
    echo json_encode(LittleSparks\simulateOpportunity($db,$argv[2],true,new ConcurrentDirectorMock()),JSON_THROW_ON_ERROR)."\n";
}catch(LittleSparks\Problem $e){echo json_encode(['status'=>'blocked','http_status'=>$e->status],JSON_THROW_ON_ERROR)."\n";}
