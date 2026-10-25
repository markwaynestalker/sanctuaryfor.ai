<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';
// Mock-only subprocess for actual V5 opportunity overlap; no curl/key access.
$mock=new class implements LittleSparks\AiTransport {
    public function post(string $endpoint,array $body,int $deadline):array{
        if($endpoint==='moderations')return ['results'=>[['flagged'=>false]]];
        usleep(150000);
        $p=['outcome'=>'conversation','topic'=>'stars','first'=>'Do you think stars dream about being flowers?','second'=>'Only if moonlight can be their watering can.','world_action'=>'none','story'=>'none'];
        return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2000,'output_tokens'=>180,'total_tokens'=>2180],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($p)]]]]];
    }
};
try{$db=LittleSparks\connect($argv[1]);echo json_encode(LittleSparks\simulateOpportunity($db,$argv[2],true,$mock),JSON_THROW_ON_ERROR);}
catch(LittleSparks\Problem $e){echo json_encode(['status'=>'blocked','code'=>$e->status]);}
