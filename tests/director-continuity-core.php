<?php
declare(strict_types=1);
// No live calls, owner data, clock changes or accounting resets.
final class FlowResponses implements LittleSparks\AiTransport
{
    public array $calls=[];public array $context=[];public array $proposal=[];
    public function __construct(public string $mode='argument',public int $length=3,public ?PDO $db=null){}
    public function post(string $endpoint,array $body,int $deadline):array
    {
        $this->calls[]=[$endpoint,$body];
        if($endpoint==='moderations'){
            if($this->mode==='withdraw')$this->db->exec("UPDATE conversations SET visibility='private' WHERE id=".$this->db->quote($this->context['conversation_flow']['source_id']));
            return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        }
        if($this->mode==='unknown')throw new RuntimeException('Offline uncertain dispatch');
        $this->context=json_decode($body['input'],true);$actors=$this->context['participants'];
        $lines=['Stop deciding for me. I am angry, and I do not want your advice.','I disagree. You dismissed what I said before I had finished.','Not now.'];$messages=[];
        for($i=0;$i<$this->length;$i++)$messages[]=['resident_id'=>$actors[$i%2]['id'],'text'=>$lines[$i%3]];
        $silent=in_array($this->mode,['quiet','none'],true);
        $this->proposal=['contract'=>'sanctuary.director.v2','outcome'=>$silent?$this->mode:'conversation','topic'=>'An unresolved disagreement','messages'=>$silent?[]:$messages,'world_action'=>'none','transmissions'=>[],'notes'=>[]];
        return ['status'=>'completed','model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>2200,'output_tokens'=>400,'total_tokens'=>2600],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($this->proposal,JSON_THROW_ON_ERROR)]]]]];
    }
}
$flowVariables=['AI_ENABLED','DIRECTOR_ENABLED','SIMULATION_ENABLED','DIRECTOR_RUNTIME','DIRECTOR_CONFIG_SHA256','DIRECTOR_AGENT_ID','AI_DAILY_REQUESTS','AI_MONTHLY_MICROUSD','AI_MAX_OUTPUT_TOKENS','AI_MAX_REQUEST_BYTES','CONVERSATION_MIN_MESSAGES','CONVERSATION_MAX_MESSAGES','RESIDENT_DAILY_MAX','MEMORY_CONTEXT_BYTES','RESIDENT_CONVERSATION_COOLDOWN_SECONDS','PAIR_CONVERSATION_COOLDOWN_SECONDS'];
$flowPrevious=[];foreach($flowVariables as $key)$flowPrevious[$key]=getenv('LITTLE_SPARKS_'.$key);
try{
    foreach(['AI_ENABLED'=>'1','DIRECTOR_ENABLED'=>'1','SIMULATION_ENABLED'=>'1','DIRECTOR_RUNTIME'=>'responses','AI_DAILY_REQUESTS'=>'1000','AI_MONTHLY_MICROUSD'=>'25000000','AI_MAX_OUTPUT_TOKENS'=>'14000','AI_MAX_REQUEST_BYTES'=>'32768','CONVERSATION_MIN_MESSAGES'=>'2','CONVERSATION_MAX_MESSAGES'=>'16','RESIDENT_DAILY_MAX'=>'100','MEMORY_CONTEXT_BYTES'=>'8192','RESIDENT_CONVERSATION_COOLDOWN_SECONDS'=>'0','PAIR_CONVERSATION_COOLDOWN_SECONDS'=>'0'] as $key=>$value)putenv('LITTLE_SPARKS_'.$key.'='.$value);
    $freshFlow=function(string $name)use($itemsBase,$dir):PDO{
        $path=$dir.'/flow-'.$name.'.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);$d->beginTransaction();LittleSparks\migrateConversationMessages($d);$d->commit();
        $reg=$d->query('SELECT fingerprint,agent_id FROM director_registry LIMIT 1')->fetch();putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$reg['fingerprint']);putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$reg['agent_id']);return $d;
    };
    $anchor=time()-3600;$slot=0;
    $generateFlow=function(PDO $d,string $mode='argument',int $length=3)use(&$slot,$anchor):array{
        $mock=new FlowResponses($mode,$length,$d);$at=gmdate('Y-m-d\TH:i:00\Z',$anchor+$slot++*60);
        $r=LittleSparks\simulateDirectorOpportunity($d,$at,true,$mock,null,null,null,true);return [$r,$mock,$at];
    };
    $addNeighbors=function(PDO $d):array{
        $out=[];foreach(['c','d'] as $i=>$ch){$id=str_repeat($ch,32);$at=gmdate('Y-m-d\TH:i:s\Z',time()-86400);
            $d->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Synthetic neighbor '.$ch,'mint','orb','curious',$at,hash('sha256','flow-owner-'.$ch),$id,hash('sha256','flow-request-'.$ch),hash('sha256','flow-token-'.$ch)]);
            LittleSparks\assignHome($d,$id,$at);LittleSparks\identityForNewResident($d,$id,'An independent adult who can disagree.','mint',$at);$out[]=$id;}
        return $out;
    };
    $d=$freshFlow('chain');[$r,$first]=$generateFlow($d);check($r['status']==='completed','Angry disagreement is accepted after moderation');
    $root=$d->query('SELECT id FROM conversations ORDER BY rowid DESC LIMIT 1')->fetchColumn();$rootPair=array_column($first->context['participants'],'id');$newcomers=$addNeighbors($d);
    [$r,$second,$at]=$generateFlow($d);check($r['status']==='completed','Same pair gets a continuation before fresh least-served neighbors');
    $f=$second->context['conversation_flow'];check($f['source_id']===$root&&$f['root_id']===$root&&$f['segment']===2,'Fresh input binds the exact saved source');
    check($f['previous_exchange']['messages']===$first->proposal['messages'],'Complete prior words survive in mandatory continuation context');
    check($second->context['participants'][0]['id']===$rootPair[1],'After odd segment, the other resident speaks first');
    check(!array_filter($second->context['shared_recent_conversations'],fn($c)=>$c['id']===$root),'Anchored exchange appears once, not as duplicated optional recall');
    $trim=LittleSparks\trimDirectorContext($second->context,strlen(json_encode($second->context,JSON_UNESCAPED_UNICODE))-50);check($trim['conversation_flow']===$f,'Byte trimming cannot remove the conversation being continued');
    try{LittleSparks\trimDirectorContext($second->context,50);throw new LogicException('Truncated mandatory conversation accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','An oversized mandatory core refuses rather than silently losing speech');}
    $saved=$d->query('SELECT id FROM conversations ORDER BY rowid DESC LIMIT 1')->fetchColumn();check(array_column(LittleSparks\conversationMessageBatch($d,[$saved])[$saved],'text')===array_column($second->proposal['messages'],'text'),'Anger stays word-exact, with no forced apology or sentiment rewrite');
    check(count($second->calls)===2&&json_decode($second->calls[1][1]['input'],true)[1]['messages']===$second->proposal['messages'],'Whole angry exchange is moderated once after one generation');
    $counts=[$d->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn(),$d->query('SELECT COUNT(*) FROM settings')->fetchColumn()];$replay=new FlowResponses();$again=LittleSparks\simulateDirectorOpportunity($d,$at,true,$replay);
    check($again['replayed']&&!$replay->calls&&$counts===[$d->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn(),$d->query('SELECT COUNT(*) FROM settings')->fetchColumn()],'Replay never creates another continuation, receipt or reservation');
    [$r,$third]=$generateFlow($d);check($r['status']==='completed'&&$third->context['conversation_flow']['segment']===3,'Third segment preserves the same public root despite reversed speaker order');
    [$r,$fourth]=$generateFlow($d);check($r['status']==='completed'&&!isset($fourth->context['conversation_flow'])&&!array_diff(array_column($fourth->context['participants'],'id'),$newcomers),'Bounded window returns service to excluded residents');
    $rel=LittleSparks\pair($d,...$rootPair);check((int)$rel['affinity']===6&&(int)$rel['familiarity']===3,'Three distinct accepted encounters retain existing accounting, not inferred sentiment scores');
    check((int)$d->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===4&&(int)$d->query('SELECT SUM(started_requests) FROM director_attempts')->fetchColumn()===8,'Four scheduled opportunities, four reservations, no extra continuation request');
    foreach(['quiet','none','flagged','unknown','withdraw'] as $mode){
        $d=$freshFlow($mode);$generateFlow($d);[$r,$mock]=$generateFlow($d,$mode);
        check($r['status']===(in_array($mode,['quiet','none'],true)?'completed':'failed'),'Expected safe continuation outcome '.$mode);
        $candidate=LittleSparks\conversationFlowCandidate($d,gmdate('Y-m-d'),LittleSparks\activityConfiguration(),time());check($candidate===null,'Silent/rejected/uncertain work breaks the chain '.$mode);
        check((int)$d->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'director_conversation_flow:%'")->fetchColumn()===0,'No accepted lineage for failed/quiet/rolled-back speech '.$mode);
        check((int)$d->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===1,'Failed or silent continuation adds no transcript '.$mode);
        check((int)$d->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===2&&!(int)$d->query('SELECT COUNT(*) FROM ai_attempts WHERE reserved_microusd<=0')->fetchColumn(),'Reservations remain charged for rejected and uncertain work '.$mode);
    }
    $d=$freshFlow('guards');$generateFlow($d);$cfg=LittleSparks\activityConfiguration();$day=gmdate('Y-m-d');
    check(LittleSparks\conversationFlowCandidate($d,$day,$cfg,time()+1801)===null,'Expired interaction is not resumed');
    check(LittleSparks\conversationFlowCandidate($d,gmdate('Y-m-d',time()+86400),$cfg,time()+86400)===null,'No cross-accounting-day continuation');
    foreach(['resident_cooldown'=>3600,'pair_cooldown'=>3600,'resident_daily'=>1] as $key=>$value)check(LittleSparks\conversationFlowCandidate($d,$day,array_replace($cfg,[$key=>$value]),time())===null,'Continuity never bypasses '.$key);
    foreach(['action','quiet','none','pending','failed'] as $outcome){
        $d->beginTransaction();$d->prepare('UPDATE simulation_opportunities SET outcome=?')->execute([$outcome]);
        check(LittleSparks\conversationFlowCandidate($d,$day,$cfg,time())===null,'Never scan backward past latest '.$outcome.' outcome');$d->rollBack();
    }
    $d->beginTransaction();$d->exec("UPDATE conversations SET visibility='private'");check(LittleSparks\conversationFlowCandidate($d,$day,$cfg,time())===null,'Private source cannot supply pair continuity');$d->rollBack();
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');[$r,$mock]=$generateFlow($d);check($r['status']==='failed'&&!$mock->calls&&$r['failure_category']==='daily_request_cap','Continuation never bypasses Director daily request cap');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=1000');
    $d=$freshFlow('managed');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');$m1=new MultiMessageMock(3);$at=gmdate('Y-m-d\TH:i:00\Z',$anchor);
    check(LittleSparks\simulateDirectorOpportunity($d,$at,true,null,$m1,null,null,false,null,true)['status']==='completed','Managed initial encounter succeeds');
    $m2=new MultiMessageMock(3);check(LittleSparks\simulateDirectorOpportunity($d,gmdate('Y-m-d\TH:i:00\Z',$anchor+60),true,null,$m2,null,null,false,null,true)['status']==='completed','Managed continuation succeeds');
    check($m2->context['conversation_flow']['previous_exchange']['messages']===$m1->proposal['messages']&&!array_filter($m2->inner->calls,fn($c)=>$c[0]==='POST'&&$c[1]==='agents/sessions'),'Managed adapter receives fresh anchored dialogue with one reused-session submission');
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');$d=$freshFlow('even');[, $even]=$generateFlow($d,'argument',2);[, $next]=$generateFlow($d,'argument',2);
    check($next->context['participants'][0]['id']===$even->context['participants'][0]['id'],'Even segments hand back to the first speaker');
    $d=$freshFlow('race');$generateFlow($d);$d=null; // Used by the real concurrent CLI claim workers.
    $guide=LittleSparks\directorEncounterGuidance();check(str_contains($guide,'get angry, argue')&&str_contains($guide,'Do not force conflict')&&str_contains($guide,'age-appropriate'),'Anger is permitted without mandatory conflict or relaxed child boundaries');
    echo "PASS bounded same-pair continuity, odd-turn handoff, complete anchored speech, fairness return, natural anger, mandatory moderation, replay, source rollback, uncertain holds, cooldown/daily guards and both adapters (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
finally{foreach($flowPrevious as $key=>$value)putenv($value===false?'LITTLE_SPARKS_'.$key:'LITTLE_SPARKS_'.$key.'='.$value);}
