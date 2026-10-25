<?php
declare(strict_types=1);
require __DIR__.'/memory-core.php';
require __DIR__.'/../app/director_schema.php';require __DIR__.'/../app/director.php';
final class DirectorMock implements LittleSparks\AiTransport
{
    public array $calls=[];public array $context=[];public array $lines=['Could lavender keep a tiny secret?','Only if the bees promise not to hum it.'];
    public function __construct(public string $mode='good'){}
    public function post(string $endpoint,array $body,int $deadline):array{
        $this->calls[]=$endpoint;
        if($endpoint==='moderations')return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        check($body['instructions']==='OWNER_EXPORT_CANARY'&&$body['tools']===[]&&!isset($body['previous_response_id']),'Only external owner instructions, no competing prompt or hidden context');
        $this->context=json_decode($body['input'],true);$actors=$this->context['participants'];
        $p=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'Lavender secrets','messages'=>[['resident_id'=>$actors[0]['id'],'text'=>$this->lines[0]],['resident_id'=>$actors[1]['id'],'text'=>$this->lines[1]]],'world_action'=>'none','transmissions'=>[],'notes'=>[['resident_id'=>$actors[1]['id'],'sequence'=>0,'quote'=>$this->lines[0],'classification'=>'interpretation']]];
        if($this->mode==='prefix')$p['messages'][0]['text']='A: '.$this->lines[0];
        if($this->mode==='html')$p['messages'][0]['text']='<img src=x onerror=alert(1)>';
        if($this->mode==='wrong_id')$p['messages'][0]['resident_id']=$actors[1]['id'];
        if($this->mode==='receipt')$p['transmissions']=[['source_alias'=>'M1','speaker_id'=>$actors[0]['id'],'listener_id'=>$actors[1]['id'],'sequence'=>0,'quote'=>'It really happened.']];
        if($this->mode==='false_quote')$p['notes'][0]['quote']='A fabricated memory.';
        if($this->mode==='action')$p['world_action']='edit_neighbor_house';
        if($this->mode==='unknown')throw new RuntimeException('SECRET_CANARY');
        if($this->mode==='quiet'||$this->mode==='none'){$p['outcome']=$this->mode;$p['messages']=$p['notes']=[];}
        return ['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2200,'output_tokens'=>180,'total_tokens'=>2380],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]]];
    }
}
final class ManagedMock implements LittleSparks\DirectorTransport
{
    public array $calls=[];public int $turn=0;public array $context=[];
    public function __construct(public string $mode='good',public ?PDO $database=null){}
    public function remote(string $status):array{return ['id'=>'session_mock','object'=>'agent.session','status'=>$status,
        'agent'=>['id'=>getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID'),'model'=>'gpt-6-luna','service_tier'=>'default','tools'=>[],'multi_agent'=>['enabled'=>false]],
        'environment'=>['type'=>'none'],'required_actions'=>[],'vault_ids'=>[]];}
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array{
        $this->calls[]=[$method,$path,$body,$idempotencyKey];
        if($this->mode==='timeout')throw new RuntimeException('PRIVATE_VENDOR_CANARY');
        if($path==='moderations'){
            if($this->mode==='apply_failure')$this->database->exec("UPDATE simulation_opportunities SET expires_at=0 WHERE outcome='pending'");
            return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        }
        if($path==='agents/sessions'){
            check($body['agent_id']===getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID')&&!isset($body['agent']['instructions']),'Saved agent ID; no PHP instructions');
            check($body['environment']===['type'=>'none']&&$body['agent']['tools']===[]&&$body['agent']['multi_agent']['enabled']===false,'No sandbox/tools/subagents');
            check(!array_key_exists('spend_control',$body)&&!isset($body['agent']['text']['format']['name']),'Live-compatible managed spend/schema contract');
            $this->context=json_decode($body['input'][0]['content'][0]['text'],true);$this->turn++;return $this->remote('in_progress');
        }
        if(str_ends_with($path,'/events')){if(($body['events'][0]['type']??null)==='agent.session.input.cancel')return [];$this->context=json_decode($body['events'][0]['input'][0]['content'][0]['text'],true);check($idempotencyKey!==null,'Stable followup idempotency key');$this->turn++;return [];}
        if(str_contains($path,'/turns?'))return ['has_more'=>false,'data'=>[['id'=>'turn_'.$this->turn,'created_at'=>time(),'session_id'=>'session_mock','agent_id'=>getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID'),'subagent_id'=>null,'status'=>$this->mode==='incomplete'?'in_progress':'completed','usage'=>$this->mode==='unknown'?null:['input_tokens'=>2300,'output_tokens'=>180,'total_tokens'=>2480]]]];
        if(str_contains($path,'/items?')){
            $a=$this->context['participants'];$p=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'Garden daydreams','messages'=>[['resident_id'=>$a[0]['id'],'text'=>'Could a daydream fit inside a lavender flower?'],['resident_id'=>$a[1]['id'],'text'=>'Perhaps, if it folds its wings.']],'world_action'=>'none','transmissions'=>[],'notes'=>[]];
            return ['has_more'=>false,'data'=>[['type'=>'message','role'=>'assistant','phase'=>'final_answer','status'=>'completed','turn_id'=>'turn_'.$this->turn,'content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]];
        }
        return $this->remote('idle');
    }
}
try{
    foreach(['LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS','LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY','LITTLE_SPARKS_DIRECTOR_DESCRIPTION_CONTEXT','LITTLE_SPARKS_DIRECTOR_INSTRUCTIONS_BYTES'] as $variable)putenv($variable);
    check(LittleSparks\identityConfiguration()===['description_max'=>2000,'daily_edits'=>6],'Identity defaults');
    foreach(['LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS'=>[1,4000],'LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY'=>[0,24],'LITTLE_SPARKS_DIRECTOR_DESCRIPTION_CONTEXT'=>[1,2000],'LITTLE_SPARKS_DIRECTOR_INSTRUCTIONS_BYTES'=>[1,8192]] as $variable=>$bounds){
        foreach($bounds as $value){putenv($variable.'='.$value);str_contains($variable,'IDENTITY')||str_contains($variable,'PERSONALITY')?LittleSparks\identityConfiguration():LittleSparks\directorConfiguration();}
        foreach(['','-1','01','1.5',(string)($bounds[1]+1)] as $invalid){putenv($variable.'='.$invalid);try{str_contains($variable,'IDENTITY')||str_contains($variable,'PERSONALITY')?LittleSparks\identityConfiguration():LittleSparks\directorConfiguration();throw new LogicException('Unsafe configuration accepted');}catch(LittleSparks\Problem){}}
        putenv($variable);
    }
    $db=$fresh();$before=originalRows($db);$memoryBefore=memoryRows($db);
    $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorSchema($db);$db->exec('ROLLBACK');check((int)$db->query('PRAGMA user_version')->fetchColumn()===5,'Transactional rollback restores V5');
    check(originalRows($db)===$before&&memoryRows($db)===$memoryBefore,'Rollback preserves all old data');
    $path=$db->query('PRAGMA database_list')->fetch()['file'];cli('migrate-director.php',$path);$db=LittleSparks\connect($path);
    check(originalRows($db)===$before&&memoryRows($db)===$memoryBefore,'Migration preserves all 23 V5 tables including private links, facts and ledgers');cli('migrate-director.php',$path);
    check(count(LittleSparks\COLORS)===24,'24 appearance colors');
    $identity=LittleSparks\editIdentity($db,['token'=>$tokens[0],'description'=>'Loves lavender, surprising questions, and careful listening.','color'=>'burgundy','requestId'=>str_repeat('a',32),'expectedRevision'=>0],'director-edit',time());
    check($identity['resident']['id']===$sparky&&$identity['resident']['identity_version']===1,'Private owner editing preserves stable ID');
    check(LittleSparks\revisit($db,$tokens[0],'director-revisit',time())['color']==='burgundy','Original private revisit token retained');
    $export=['id'=>'agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8','model'=>'gpt-6-luna','instructions'=>'OWNER_EXPORT_CANARY','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');$registration=LittleSparks\registerDirector($db,$export);
    putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$export['id']);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registration['fingerprint']);
    putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED=2026-10-24-standard-v1');putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');
    $base=$dir.'/director-template.sqlite';copy($path,$base);$n=0;
    $directorFresh=function()use($base,$dir,&$n){$p=$dir.'/director-'.(++$n).'.sqlite';copy($base,$p);return LittleSparks\connect($p);};
    $at=gmdate('Y-m-d\TH:i:00\Z',time()-60);
    $db=$directorFresh();$mock=new DirectorMock();$result=LittleSparks\simulateOpportunity($db,$at,true,$mock);
    check($result['status']==='completed'&&$mock->calls===['responses','moderations'],'Exact externally authored dialogue is moderated and committed');
    $run=$result['id'];$q=$db->prepare('SELECT body FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.run_id=? ORDER BY sequence');$q->execute([$run]);check($q->fetchAll(PDO::FETCH_COLUMN)===$mock->lines,'No PHP prefix, template or rewriting');
    check((int)$db->query('SELECT COUNT(*) FROM director_notes')->fetchColumn()===1,'Literal unverified memory persisted');
    $status=proc_open([PHP_BINARY,__DIR__.'/../scripts/ai-status.php','--database='.$db->query('PRAGMA database_list')->fetch()['file']],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$report=json_decode(stream_get_contents($pipes[1]),true);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($status)===0&&$report['daily']['reserved_requests']===2,'V6 CLI status preserves request accounting');
    check((int)$db->query('SELECT COUNT(*) FROM memory_stories')->fetchColumn()===count($memoryBefore['memory_stories']),'Dialogue cannot create confirmed facts');
    $count=count($mock->calls);LittleSparks\simulateOpportunity($db,$at,true,$mock);check(count($mock->calls)===$count,'Opportunity replay never redispatches');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');$secondAt=gmdate('Y-m-d\TH:i:00\Z',time()-120);$capMock=new DirectorMock();check(LittleSparks\simulateOpportunity($db,$secondAt,true,$capMock)['status']==='failed'&&!$capMock->calls,'Saved ledger enforces daily cap');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=4');$thirdAt=gmdate('Y-m-d\TH:i:00\Z',time()-180);check(LittleSparks\simulateOpportunity($db,$thirdAt,true,new DirectorMock())['status']==='completed','Changing limit admits another same-day encounter without code changes');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    $budgetDb=$directorFresh();putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=0');$budgetMock=new DirectorMock();check(LittleSparks\simulateOpportunity($budgetDb,$at,true,$budgetMock)['status']==='failed'&&!$budgetMock->calls,'Zero monthly allowance blocks before dispatch');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    foreach(['prefix','html','wrong_id','receipt','false_quote','action','flagged','unknown'] as $mode){$db=$directorFresh();$old=(int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();$result=LittleSparks\simulateOpportunity($db,$at,true,new DirectorMock($mode));check($result['status']==='failed','Rejected '.$mode);check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$old&&(int)$db->query('SELECT COUNT(*) FROM director_notes')->fetchColumn()===0,'Failure rollback without fabricated history: '.$mode);}
    foreach(['quiet','none'] as $mode){$db=$directorFresh();$old=(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();$result=LittleSparks\simulateOpportunity($db,$at,true,new DirectorMock($mode));check($result['outcome']===$mode&&(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===$old,'Silent '.$mode.' outcome');}
    putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=0');$db=$directorFresh();check(LittleSparks\simulateOpportunity($db,$at,true,new DirectorMock())['status']==='silent','No scripted fallback when disabled');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');$db=$directorFresh();check(LittleSparks\simulateOpportunity($db,$at,true)['reason']==='credentials_unavailable','Configured live routing refuses missing credentials before reservation');
    $mock=new ManagedMock();$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check(($result['status']??null)==='completed','Mocked saved agent encounter succeeds');check(count($mock->calls)===6,'All managed requests including inspections and moderation counted');
    check($db->query('SELECT state FROM ai_attempts ORDER BY rowid DESC LIMIT 1')->fetchColumn()==='uncertain','Best-effort managed usage never releases budget');
    check((int)$db->query('SELECT started_requests FROM director_attempts ORDER BY rowid DESC LIMIT 1')->fetchColumn()===6,'Managed ledger tracks every request');
    $registered=LittleSparks\registeredDirector($db,LittleSparks\directorConfiguration());$actors=$mock->context['participants'];$pair=array_map(fn($a)=>['id'=>$a['id'],'personality'=>'custom'],$actors);
    $session=LittleSparks\directorSession($db,$registered,$pair,'agents',time());check($session['provider_session_id']==='session_mock','Provider session survives reconnect by SQLite scope');
    $swapped=LittleSparks\directorSession($db,$registered,array_reverse($pair),'agents',time());check($swapped['id']===$session['id'],'Pair order does not change scope');
    $outsider=array_values(array_diff($ids,array_column($pair,'id')))[0];$pair[1]['id']=$outsider;$different=LittleSparks\directorSession($db,$registered,$pair,'agents',time());check($different['id']!==$session['id'],'Different residents use different sessions');
    // Fixture-only stand-in for external operator billing reconciliation. Retain the
    // entire reserved amount as cost, never reset spending or expose a runtime reset.
    $db->exec("UPDATE ai_attempts SET state='applied',estimated_microusd=reserved_microusd WHERE state='uncertain'; UPDATE director_attempts SET state='applied' WHERE state='uncertain'");
    $db=LittleSparks\connect($db->query('PRAGMA database_list')->fetch()['file']);$pair=array_map(fn($a)=>['id'=>$a['id'],'personality'=>'custom'],$actors);
    $session=LittleSparks\directorSession($db,$registered,$pair,'agents',time());$choices=LittleSparks\aiActionChoices($db,$pair[0]['id'],$pair[1]['id'],time());
    $claim=['id'=>'op:'.gmdate('Y-m-d\TH:i:00\Z',time()-300),'created_at'=>gmdate('Y-m-d\TH:i:s\Z')];$prepared=LittleSparks\prepareDirectorEncounter($db,$pair,$choices,$claim,LittleSparks\directorConfiguration());
    check((bool)$prepared['context']['participants'][0]['recent_conversations'],'Restart retrieves accepted resident history from SQLite');
    $pricing=LittleSparks\aiConfiguration(time())+['director_slots'=>8,'director_session'=>$session['id'],'director_runtime'=>'agents','director_context'=>hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR))];
    $attempt=LittleSparks\reserveAi($db,$claim['id'],$pair,$pricing,time());$beforeCalls=count($mock->calls);LittleSparks\managedDirectorEncounter($db,$mock,$attempt,$session,$registered,$prepared,$choices);
    check(count($mock->calls)-$beforeCalls===6&&$mock->turn===2&&$mock->context['opportunity_id']===$claim['id'],'Second canonical encounter reuses optional session with fresh SQLite context inside eight reserved slots');
    $db->prepare("UPDATE director_sessions SET state='unavailable' WHERE id=?")->execute([$session['id']]);
    check(LittleSparks\directorSession($db,$registered,$pair,'agents',time())['id']!==$session['id'],'Unavailable continuity is replaceable without altering resident records');
    foreach(['flagged','unknown','timeout','incomplete'] as $mode){$db=$directorFresh();$mock=new ManagedMock($mode);$old=(int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check($result['status']==='failed'&&(int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$old,'Managed failure produces no history: '.$mode);$calls=count($mock->calls);LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check(count($mock->calls)===$calls,'No automatic paid retry: '.$mode);}
    foreach(['','-1','201','02','1.5'] as $bad){putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.$bad);try{LittleSparks\aiDailyRequestLimit();throw new LogicException('Bad request ceiling accepted');}catch(LittleSparks\AiGuard){}}putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    $db=$directorFresh();$mock=new ManagedMock();$result=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock);check(!$mock->calls&&$result['status']==='failed','Default two-request cap blocks four-request managed encounter before dispatch');
    echo "PASS V3A migration/rollback, identities/private links, exact dialogue, moderation, unverified memory, session scope, request accounting, fail-closed live admission and offline adapters\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
require __DIR__.'/director-accounting-core.php';
require __DIR__.'/director-staging-core.php';
require __DIR__.'/director-reconciliation-core.php';
require __DIR__.'/director-autonomy-core.php';
require __DIR__.'/director-lifecycle-core.php';
