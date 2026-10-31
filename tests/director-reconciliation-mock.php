<?php
declare(strict_types=1);
final class ReconciliationMock implements LittleSparks\DirectorTransport
{
    public array $calls=[];
    public function __construct(public array $local,public string $mode='good',public ?Closure $onRequest=null){}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        if($method!=='GET'||$body!==null||$key!==null)throw new LogicException('Reconciliation must never generate or cancel');
        $this->calls[]=$path;$a=$this->local['records']['ai_attempts'][0];$s=$this->local['records']['session'];$sid=$s['provider_session_id'];
        if($this->onRequest!==null)($this->onRequest)(count($this->calls));
        if($this->mode==='http')throw new LittleSparks\ManagedHttpFailure(503,'req_reconciliation');
        $usage=['input_tokens'=>2200,'output_tokens'=>100,'total_tokens'=>2300];
        if($this->mode==='unknown')$usage=null;
        if($this->mode==='bad_usage')$usage['total_tokens']=99;
        if($this->mode==='revised'&&count($this->calls)===1)$usage=['input_tokens'=>200000,'output_tokens'=>100000,'total_tokens'=>300000];
        if(str_contains($path,'/turns?'))return ['has_more'=>$this->mode==='history','data'=>[[
            'id'=>'turn_reconciled','session_id'=>$this->mode==='wrong_session'?'other':$sid,'agent_id'=>$s['agent_id'],'subagent_id'=>null,
            'status'=>$this->mode==='running'?'in_progress':($this->mode==='cancelled'?'cancelled':'completed'),
            'created_at'=>strtotime($a['created_at'])+($this->mode==='old'?-100:0),'error'=>null,'usage'=>$usage]]];
        if($path!=='agents/sessions/'.$sid)throw new LogicException('Unapproved inspection route');
        return ['id'=>$sid,'object'=>'agent.session','status'=>$this->mode==='active'?'in_progress':'idle',
            'agent'=>['id'=>$this->mode==='wrong_agent'?'other':$s['agent_id'],'model'=>$a['model'],'service_tier'=>'auto','tools'=>[],'multi_agent'=>['enabled'=>false]],
            'environment'=>['type'=>'none'],'required_actions'=>[],'vault_ids'=>[],
            'metadata'=>['sanctuary_attempt_id'=>$this->mode==='wrong_attempt'?'other':$a['id']],
            'last_active_at'=>strtotime($a['created_at'])+($this->mode==='changed'&&count($this->calls)===3?1:0),
            'usage'=>$usage,'instructions'=>'PRIVATE_INSPECTION_CANARY','other_private_context'=>'sk-PRIVATE_INSPECTION_CANARY'];
    }
}
