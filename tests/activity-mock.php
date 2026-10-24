<?php
declare(strict_types=1);
// Test-only transport. Never sends HTTP and never reads credentials.
final class ActivityMock implements LittleSparks\AiTransport
{
    public array $calls=[];
    public function __construct(public string $mode='conversation',public ?PDO $db=null){}
    public function post(string $endpoint,array $body,int $deadline): array
    {
        $this->calls[]=$endpoint;
        if(time()>=$deadline)throw new RuntimeException('Test deadline expired.');
        if($endpoint==='moderations'){
            if($this->mode==='moderation_timeout')throw new RuntimeException('PRIVATE_CANARY_DO_NOT_LOG');
            if($this->mode==='collision'){
                $row=$this->db->query('SELECT resident_a,resident_b FROM ai_attempts ORDER BY rowid DESC LIMIT 1')->fetch();
                $choices=LittleSparks\aiActionChoices($this->db,$row['resident_a'],$row['resident_b'],time());
                $this->db->exec('BEGIN IMMEDIATE');LittleSparks\performResidentAction($this->db,$row['resident_a'],$choices['plant_flowers'],gmdate('Y-m-d\TH:i:s\Z'),'race','visitor');$this->db->exec('COMMIT');
            }
            return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        }
        if($endpoint!=='responses')throw new RuntimeException('Unexpected endpoint.');
        if($this->mode==='timeout')throw new RuntimeException('PRIVATE_CANARY_DO_NOT_LOG');
        if($body['tools']!==[]||$body['store']!==false||$body['service_tier']!=='default'||$body['max_output_tokens']>512)throw new RuntimeException('Unsafe request.');
        if(!str_contains($body['instructions'],'no speaker labels'))throw new RuntimeException('Missing natural dialogue instruction.');
        $context=json_decode($body['input'],true,512,JSON_THROW_ON_ERROR);
        if(count($context['characters'])!==2||str_contains($body['input'],'token_hash'))throw new RuntimeException('Unsafe context.');
        $outcome=in_array($this->mode,['action','quiet','none'],true)?$this->mode:'conversation';
        $p=['outcome'=>$outcome,'topic'=>'garden','first'=>$outcome==='conversation'?'A: A quiet walk sounds like a bright little adventure.':'',
            'second'=>$outcome==='conversation'?'B: Curious—shall we leave room for a new friend?':'','world_action'=>'none'];
        if(in_array($this->mode,['action','collision'],true))$p['world_action']=in_array('plant_flowers',$context['available_actions'],true)?'plant_flowers':'none';
        if($this->mode==='invalid')$p['world_action']='execute_shell';
        if($this->mode==='html')$p['first']='A: <script>bad</script>';
        if($this->mode==='label_only')$p['first']='A: ';
        if($this->mode==='extra')$p['sql']='DELETE FROM residents';
        if($this->mode==='quiet_text'){$p['outcome']='quiet';$p['first']='Unapproved text';}
        if($this->mode==='lease_expired')$this->db->exec('UPDATE ai_lease SET expires_at=1');
        $response=['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>1200,'output_tokens'=>100,'total_tokens'=>1300],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]];
        if($this->mode==='unknown')unset($response['usage']);
        return $response;
    }
}
