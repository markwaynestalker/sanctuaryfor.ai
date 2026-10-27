<?php
declare(strict_types=1);
function bulletinProposal(array $context,string $mode):array
{
    $actors=$context['participants'];$notice=['resident_id'=>$actors[0]['id'],'kind'=>'invitation','text'=>"Would anyone like to bring a question & a little curiosity to a moonlit picnic? It's only an idea for now."];
    if($mode==='wrong_author')$notice['resident_id']=$actors[1]['id'];
    if($mode==='markup')$notice['text']='<script>alert(1)</script>';
    if($mode==='claim')$notice['text']='I planted a tree in our garden yesterday.';
    if($mode==='impersonation')$notice['text']='Sparky: I promise everyone a new home.';
    if($mode==='alias')$notice['text']='M12 confirms the promise.';
    $p=['contract'=>'sanctuary.director.bulletin.v1','outcome'=>$mode==='quiet'?'quiet':($mode==='action'?'action':'conversation'),'topic'=>'A possible moonlit picnic',
        'messages'=>in_array($mode,['quiet','action'],true)?[]:[['resident_id'=>$actors[0]['id'],'text'=>'Could curiosity be a picnic guest?'],['resident_id'=>$actors[1]['id'],'text'=>'Perhaps it brings a basket of questions.']],
        'world_action'=>in_array($mode,['quiet','no_notice'],true)?'none':'post_notice','notice'=>in_array($mode,['quiet','no_notice'],true)?null:$notice,'transmissions'=>[],'notes'=>[]];
    return $p;
}
final class BulletinResponses implements LittleSparks\AiTransport
{
    public array $calls=[];public array $context=[];public ?array $moderated=null;
    public function __construct(public string $mode='good'){}
    public function post(string $endpoint,array $body,int $deadline):array
    {
        $this->calls[]=$endpoint;
        if($endpoint==='moderations'){$this->moderated=json_decode($body['input'],true);if($this->mode==='quota_changed')putenv('LITTLE_SPARKS_BULLETIN_ACTIVE_MAX=0');return ['results'=>[['flagged'=>$this->mode==='flagged']]];}
        $this->context=json_decode($body['input'],true);$proposal=bulletinProposal($this->context,$this->mode);
        if($this->mode==='legacy'){$proposal['contract']='sanctuary.director.v1';unset($proposal['notice']);$proposal['world_action']='none';}
        return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2000,'output_tokens'=>150,'total_tokens'=>2150],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]]];
    }
}
