<?php
declare(strict_types=1);
// Included by director-core.php; every transport here is synthetic/offline.
final class StagingMock implements LittleSparks\DirectorTransport
{
    public array $calls=[];private array $context=[];private int $polls=0;private bool $cancelled=false;
    public function __construct(public string $mode='good',private ?LittleSparks\StagingPermit $permit=null){}
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array
    {
        if($path==='agents/sessions'&&$this->mode==='tampered_tier')$body['agent']['service_tier']='priority';
        if($path==='agents/sessions'&&$this->mode==='omitted_tier')unset($body['agent']['service_tier']);
        if($path==='agents/sessions'&&$this->mode==='tampered_reasoning')$body['agent']['reasoning']=['effort'=>'high'];
        if($path==='agents/sessions'&&$this->mode==='streaming')$body['stream']=true;
        if($this->permit)$this->permit->authorize($method,$path,$body);
        $this->calls[]=[$method,$path,$body];check($deadline<=time()+60,'Deadline is bounded');
        if($path==='agents/sessions'){
            check(($body['agent']['service_tier']??null)==='default','Session override is explicitly default even if saved agent is auto/priority');
            if($this->mode==='validation_400')throw new LittleSparks\ManagedHttpFailure(400,'req_validation_mock',LittleSparks\managedValidationError(400,json_encode(['error'=>['code'=>'unknown_parameter','param'=>'agent.text.format.schema','message'=>'PRIVATE_PROVIDER_CANARY sk-not-a-key']],JSON_THROW_ON_ERROR)));
            if($this->mode==='lost')throw new LittleSparks\ManagedHttpFailure();
            $this->context=json_decode($body['input'][0]['content'][0]['text'],true);
            return $this->remote();
        }
        if(str_ends_with($path,'/events')){check($body===['events'=>[['type'=>'agent.session.input.cancel']]],'Recovery may only cancel, never submit dialogue');if($this->mode==='cancel_failure')throw new LittleSparks\ManagedHttpFailure();$this->cancelled=true;return [];}
        if(str_contains($path,'/turns?')){
            $this->polls++;$status=$this->cancelled?'cancelled':(in_array($this->mode,['incomplete','cancel_failure','cancel_still_running','deadline'],true)?'in_progress':($this->mode==='delayed'&&$this->polls<3?'in_progress':'completed'));
            if($this->mode==='cancel_still_running')$status='in_progress';
            return ['has_more'=>false,'data'=>[['id'=>'turn_stage','agent_id'=>getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID'),'session_id'=>$this->mode==='wrong_session'?'wrong_session':'session_stage','subagent_id'=>null,'status'=>$status,'error'=>null,
                'usage'=>$this->mode==='unknown'?null:['input_tokens'=>2200,'output_tokens'=>100,'total_tokens'=>2300]]]];
        }
        if(str_contains($path,'/items?')){
            $actors=$this->context['participants'];$p=['contract'=>'sanctuary.director.v1','outcome'=>'conversation','topic'=>'A welcome in the meadow','messages'=>[['resident_id'=>$actors[0]['id'],'text'=>'Could a welcome grow like a little garden?'],['resident_id'=>$actors[1]['id'],'text'=>'Perhaps kindness is its favorite sunlight.']],'world_action'=>'none','transmissions'=>[],'notes'=>[]];
            if($this->mode==='invalid')$p['messages'][0]['text']='<script>no</script>';
            if($this->mode==='quiet'){$p['outcome']='quiet';$p['messages']=[];}
            return ['has_more'=>false,'data'=>[['type'=>'message','role'=>'assistant','phase'=>'final_answer','status'=>'completed','turn_id'=>'turn_stage','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]];
        }
        if($path==='moderations'){if($this->mode==='moderation_timeout')throw new LittleSparks\ManagedHttpFailure();return ['results'=>[['flagged'=>$this->mode==='flagged']]];}
        $remote=$this->remote();if($this->mode==='unsafe')$remote['agent']['tools']=[['type'=>'shell']];
        if($this->mode==='inspect_tier')$remote['agent']['service_tier']='priority';return $remote;
    }
    private function remote():array{
        $r=['id'=>'session_stage','object'=>'agent.session','status'=>'in_progress','agent'=>['id'=>getenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID'),'model'=>'gpt-6-luna','service_tier'=>'default','tools'=>[],'multi_agent'=>['enabled'=>false]],'environment'=>['type'=>'none'],'spend_control'=>['limit'=>1,'consumed'=>0],'required_actions'=>[],'vault_ids'=>[]];
        if(in_array($this->mode,['auto_tier','priority_tier'],true))$r['agent']['service_tier']=$this->mode==='auto_tier'?'auto':'priority';
        if($this->mode==='missing_tier')unset($r['agent']['service_tier']);return $r;
    }
}
try{
    // Exercise actual deployed V5+identity state, including the owner's mayor text.
    putenv('LITTLE_SPARKS_SIMULATION_ENABLED=0');putenv('LITTLE_SPARKS_AI_ENABLED=0');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');
    $adopt=$fresh();$adopt->exec('BEGIN IMMEDIATE');LittleSparks\migrateIdentitySchema($adopt);$adopt->exec('COMMIT');
    $mayor='I am the first resident in the village and I love to talk to people and act as the town mayor';
    LittleSparks\editIdentity($adopt,['token'=>$tokens[0],'description'=>$mayor,'color'=>'gold','requestId'=>str_repeat('8',32),'expectedRevision'=>0],'adoption-owner',time());
    $rowsBefore=originalRows($adopt)+memoryRows($adopt);$identities=LittleSparks\identityAdoptionDigest($adopt);$manifest=LittleSparks\villageManifest($adopt);
    $adopt->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorSchema($adopt);$adopt->exec('ROLLBACK');check($identities===LittleSparks\identityAdoptionDigest($adopt)&&!LittleSparks\directorEnabled($adopt),'Rollback preserves installed identity add-on');
    $adopt->exec('BEGIN IMMEDIATE');LittleSparks\migrateDirectorSchema($adopt);$adopt->exec('COMMIT');
    check($identities===LittleSparks\identityAdoptionDigest($adopt)&&$rowsBefore===originalRows($adopt)+memoryRows($adopt),'All six residents, descriptions, audits, original credentials, V5 memories and accounting adopted without changes');
    check(LittleSparks\revisit($adopt,$tokens[0],'adoption-revisit',time())['description']===$mayor,'Mayor description and original token survive');
    $backup=$dir.'/identity-adopted-backup.sqlite';LittleSparks\backupVillage($adopt,$backup);check(LittleSparks\villageManifest(LittleSparks\connect($backup))['identity_sha256']===$manifest['identity_sha256'],'Backup manifest verifies identities and audit too');

    // Fresh staging is synthetic, never a production snapshot.
    $stageBase=$dir.'/synthetic-stage-template.sqlite';foreach(['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-identity.php'] as $script)cli($script,$stageBase);
    $stage=LittleSparks\connect($stageBase);
    foreach(['Test Mayor','Test Gardener'] as $i=>$name)LittleSparks\create($stage,['name'=>$name,'color'=>'mint','antenna'=>'orb','description'=>$i?'Enjoys peaceful gardens.':$mayor,'requestId'=>str_repeat((string)($i+1),32),'website'=>''],'stage-browser-'.$i,'stage-net-'.$i,time()-100,true);
    cli('migrate-director.php',$stageBase);$registeredStage=LittleSparks\registerDirector($stage,$export);$stage=null;
    putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registeredStage['fingerprint']);
    $stageN=0;$stageFresh=function()use($stageBase,$dir,&$stageN){$p=$dir.'/stage-'.(++$stageN).'.sqlite';copy($stageBase,$p);return LittleSparks\connect($p);};
    $approved=function(PDO $db)use($at){$p=LittleSparks\stagingPlan($db,$at,time()+300);$p['approved']=true;$p['production_isolation_attested']=true;$p['approval_reference']='OFFLINE-MOCK-ONLY';return $p;};
    $stage=$stageFresh();$notApproved=LittleSparks\stagingPlan($stage,$at,time()+300);try{LittleSparks\StagingPermit::consume($stage,$notApproved);throw new LogicException('Unapproved permit consumed');}catch(LittleSparks\AiGuard){}
    foreach(['manifest_sha256','database_sha256','installation_sha256','residents_sha256','agent_id','configuration_sha256','model','risk_acceptance','http_slots','allocation_microusd'] as $field){$bad=$approved($stage);$bad[$field]=is_int($bad[$field])?0:'tampered';try{LittleSparks\StagingPermit::consume($stage,$bad);throw new LogicException('Tampered permit admitted: '.$field);}catch(LittleSparks\AiGuard){}}
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=8');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=100000');putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');
    foreach(['good','delayed','incomplete','cancel_failure','cancel_still_running','lost','unknown','flagged','moderation_timeout','invalid','unsafe','wrong_session','auto_tier','priority_tier','missing_tier','inspect_tier'] as $mode){
        $stage=$stageFresh();$p=$approved($stage);$grant=LittleSparks\StagingPermit::consume($stage,$p);$mock=new StagingMock($mode,$grant);
        $result=LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);
        check(count($mock->calls)<=8&&count(array_filter($mock->calls,fn($c)=>$c[1]==='agents/sessions'))===1,'At most one generation/eight counted HTTP calls: '.$mode);
        $count=(int)$stage->query('SELECT COUNT(*) FROM messages')->fetchColumn();check($count===(in_array($mode,['good','delayed'],true)?2:0),'Only successful moderated canonical dialogue saved: '.$mode);
        $a=$stage->query('SELECT * FROM ai_attempts LIMIT 1')->fetch();check($a['state']==='uncertain'&&$a['estimated_microusd']===null&&(int)$a['reserved_microusd']===100000,'Full experimental allocation stays held pending billing: '.$mode);
        check((int)$stage->query('SELECT started_requests FROM director_attempts LIMIT 1')->fetchColumn()===count($mock->calls),'All requests counted including cancellation/moderation: '.$mode);
        try{LittleSparks\StagingPermit::consume($stage,$p);throw new LogicException('Permit reused after completion/failure');}catch(LittleSparks\AiGuard){}
        $calls=count($mock->calls);LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);check(count($mock->calls)===$calls,'Opportunity replay never generates twice: '.$mode);
        if(in_array($mode,['good','delayed'],true)){check($result['status']==='completed','Completed experiment');$review=LittleSparks\reviewManagedBilling($stage,$a['id'],str_repeat('1',32),1000,str_repeat('a',64));check($review['commitment_microusd']===100000,'Final bill review never restores experimental headroom');}
        if(in_array($mode,['lost','cancel_failure','cancel_still_running'],true)){try{LittleSparks\reviewManagedBilling($stage,$a['id'],str_repeat('2',32),0,str_repeat('b',64));throw new LogicException('Unfinished provider work cleared by billing alone');}catch(LittleSparks\AiGuard $e){check($e->reason==='accounting_unresolved','Terminal proof required before billing release');}}
        if($mode==='incomplete'){check((int)$stage->query("SELECT COUNT(*) FROM director_http_calls WHERE kind='cancel'")->fetchColumn()===1,'One cancellation and terminal confirmation');}
        if(in_array($mode,['auto_tier','priority_tier','missing_tier','inspect_tier'],true)){
            check(count(array_filter($mock->calls,fn($c)=>str_ends_with($c[1],'/events')))===1,'Unexpected tier attempts exactly one cancellation');
            check(!array_filter($mock->calls,fn($c)=>$c[1]==='moderations'||str_contains($c[1],'/items?')),'Unexpected tier never accepts or moderates dialogue');
        }
    }
    foreach(['tampered_tier','omitted_tier','tampered_reasoning','streaming'] as $mode){
        $stage=$stageFresh();$grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));$mock=new StagingMock($mode,$grant);
        LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);
        check(!$mock->calls&&(int)$stage->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'Permit rejects non-default/missing tier, effort or streaming before dispatch');
        check((int)$stage->query('SELECT reserved_microusd FROM ai_attempts LIMIT 1')->fetchColumn()===100000,'Rejected tier keeps allocation and permit consumed');
    }
    echo "PASS default session tier pinned before dispatch; missing/auto/priority returned tiers cancel once, stay silent and retain accounting; no saved-agent edits or live calls\n";
    $stage=$stageFresh();$grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));$stage->exec("UPDATE resident_identities SET description='Changed after approval' WHERE resident_id=(SELECT id FROM residents LIMIT 1)");$mock=new StagingMock('good');LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);check(!$mock->calls,'Identity changes after permit consumption block generation');
    foreach([['LITTLE_SPARKS_AI_DAILY_REQUESTS','7'],['LITTLE_SPARKS_AI_MONTHLY_MICROUSD','99999']] as [$variable,$value]){
        $stage=$stageFresh();$grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));putenv($variable.'='.$value);$mock=new StagingMock('good');LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);check(!$mock->calls,'Insufficient request/budget envelope fails before dispatch');putenv($variable.'='.($variable==='LITTLE_SPARKS_AI_DAILY_REQUESTS'?'8':'100000'));
    }
    // A deterministic clock exercises the cancellation margin without sleeping.
    $stage=$stageFresh();$grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));$cfg=LittleSparks\directorConfiguration();$reg=LittleSparks\registeredDirector($stage,$cfg);$now=time();$claim=LittleSparks\claimActivity($stage,$at,$now);
    $stage->exec('BEGIN IMMEDIATE');$s=LittleSparks\directorSession($stage,$reg,$claim['pair'],'agents',$now);$stage->exec('COMMIT');$choices=['none'=>null];$prepared=LittleSparks\prepareDirectorEncounter($stage,$claim['pair'],$choices,$claim,$cfg);
    $pricing=LittleSparks\aiConfiguration($now)+['director_session'=>$s['id'],'director_runtime'=>'agents','director_once'=>true,'director_context'=>hash('sha256',json_encode($prepared['context'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),'director_slots'=>8,'staging_allocation'=>100000];
    $attempt=LittleSparks\reserveAi($stage,$claim['id'],$claim['pair'],$pricing,$now);$mock=new StagingMock('deadline',$grant);$ticks=0;
    try{LittleSparks\stagingManagedEncounter($stage,$mock,$attempt,$s,$reg,$prepared,$choices,static function()use(&$ticks,$now){return $now+5*$ticks++;},static fn(int $seconds)=>null);throw new LogicException('Unfinished deadline outcome accepted');}catch(LittleSparks\AiGuard){}
    check(count(array_filter($mock->calls,fn($c)=>str_ends_with($c[1],'/events')))===1,'Observation cutoff retains a single cancellation attempt before deadline');
    $over=['input_tokens'=>2200,'output_tokens'=>700,'total_tokens'=>2900];check(LittleSparks\observeManagedUsage($stage,$attempt,'turn_stage',$over,$pricing)===null,'Excessive usage fails closed');$last=$stage->query('SELECT output_tokens,status FROM director_usage_observations ORDER BY id DESC LIMIT 1')->fetch();check((int)$last['output_tokens']===700&&$last['status']==='unknown','Retain measurable excessive usage without accepting billing');
    $stage=$stageFresh();$grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));$mock=new StagingMock('good',$grant);$r=LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);
    LittleSparks\recordManagedOutcome($stage,['id'=>$r['id']],'session_stage','turn_stage','unresolved');
    try{LittleSparks\reviewManagedBilling($stage,$r['id'],str_repeat('4',32),0,str_repeat('e',64));throw new LogicException('Stale terminal evidence accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='accounting_unresolved','Latest provider outcome must be terminal');}
    // Existing metadata requests are imported once, not discarded by a fresh budget ledger.
    $day=gmdate('Y-m-d');$evidence=['schema'=>'sanctuary.access-reconciliation.v1','total_requests_reserved'=>2,'receipts'=>[]];
    foreach(['1','2'] as $digit)$evidence['receipts'][]=['receipt'=>$digit==='1'?'agent-access.jsonl':'agent-access-after-validator.jsonl',
        'check_id'=>str_repeat($digit,32),'sha256'=>str_repeat($digit,64),'at'=>$day.'T00:00:00Z','day'=>$day,'http_requests_reserved'=>1];
    foreach([9=>false,10=>true,20=>true] as $limit=>$admitted){
        $stage=$stageFresh();$secret=LittleSparks\secret($stage);LittleSparks\recordDirectorAccessReservations($stage,$evidence);LittleSparks\recordDirectorAccessReservations($stage,$evidence);
        check(LittleSparks\directorAccessReservedRequests($stage,$day)===2&&LittleSparks\secret($stage)===$secret,'Idempotent metadata accounting preserves installation secret');
        putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.$limit);putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
        $grant=LittleSparks\StagingPermit::consume($stage,$approved($stage));$mock=new StagingMock('good',$grant);
        $result=LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,true,$grant);
        check(($result['status']==='completed')===$admitted&&(!$mock->calls)===!$admitted,'Configured ceiling counts two metadata slots plus encounter reservation, same UTC day');
        check((int)$stage->query('SELECT COUNT(*) FROM director_staging_permits')->fetchColumn()===1,'Permit stays consumed on success/failure');
        $changed=$evidence;$changed['receipts'][0]['sha256']=str_repeat('a',64);
        try{LittleSparks\recordDirectorAccessReservations($stage,$changed);throw new LogicException('Conflicting metadata evidence admitted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'Conflicting access'),'Conflicting evidence fails closed');}
        check(LittleSparks\directorAccessReservedRequests($stage,$day)===2,'No metadata refund or duplicate reservation');
    }
    // Routine mode uses the same bounded lifecycle, with no one-shot permit or per-encounter approval.
    $stage=$stageFresh();LittleSparks\recordDirectorAccessReservations($stage,$evidence);
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=18');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=20000');
    foreach([60,120] as $offset){
        $mock=new StagingMock('good');$op=gmdate('Y-m-d\TH:i:00\Z',time()-$offset);
        $r=LittleSparks\simulateDirectorOpportunity($stage,$op,true,null,$mock,null,null,false,null,true);
        check($r['status']==='completed'&&count($mock->calls)<=8,'Configured routine conversations proceed without manual permits/reconciliation');
        $n=count($mock->calls);LittleSparks\simulateDirectorOpportunity($stage,$op,true,null,$mock,null,null,false,null,true);check(count($mock->calls)===$n,'No duplicate generation on replay');
    }
    check((int)$stage->query('SELECT COUNT(*) FROM director_staging_permits')->fetchColumn()===0,'Routine mode does not create experimental permits');
    check((int)$stage->query('SELECT SUM(estimated_microusd) FROM ai_attempts')->fetchColumn()===20000,'Routine commitments retain full reservations; no refunds');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');$mock=new StagingMock('good');
    $r=LittleSparks\simulateDirectorOpportunity($stage,gmdate('Y-m-d\TH:i:00\Z',time()-180),true,null,$mock,null,null,false,null,true);
    check(!$mock->calls&&$r['status']==='failed','Configured monthly allocation blocks next routine encounter');
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    foreach(['unknown','flagged','validation_400'] as $mode){
        $stage=$stageFresh();$mock=new StagingMock($mode);$r=LittleSparks\simulateDirectorOpportunity($stage,$at,true,null,$mock,null,null,false,null,true);
        check($r['status']==='failed'&&(int)$stage->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'Unknown/moderation failure persists no conversation');
        if($mode==='validation_400'){
            $stored=$stage->query("SELECT value FROM settings WHERE name LIKE 'director_validation_error:%'")->fetchColumn();
            check(json_decode($stored,true)===['code'=>'unknown_parameter','param'=>'agent.text.format.schema'],'Private sanitized validation evidence is retained');
            check(!str_contains($stored,'PRIVATE_PROVIDER')&&!str_contains($stored,'sk-'),'No raw message or credential retained');
            $a=$stage->query('SELECT state,estimated_microusd,request_slots FROM ai_attempts LIMIT 1')->fetch();
            check($a['state']==='uncertain'&&$a['estimated_microusd']===null&&count($mock->calls)===1,'HTTP 400 retains uncertain billing and dispatches once');
            check((int)$stage->query('SELECT request_slots FROM director_attempts LIMIT 1')->fetchColumn()===8,'All eight reserved HTTP slots remain counted after HTTP 400');
        }
        $next=new StagingMock('good');LittleSparks\simulateDirectorOpportunity($stage,gmdate('Y-m-d\TH:i:00\Z',time()-180),true,null,$next,null,null,false,null,true);
        check(!$next->calls,'Uncertain billing blocks new paid work; no automatic retry');
        if($mode==='validation_400'){
            $before=$stage->query('SELECT reserved_microusd FROM ai_attempts LIMIT 1')->fetchColumn();
            $review=LittleSparks\reviewManagedBilling($stage,$r['id'],str_repeat('5',32),0,str_repeat('6',64));
            check((int)$review['commitment_microusd']===(int)$before&&$stage->query('SELECT state FROM ai_attempts LIMIT 1')->fetchColumn()==='rejected','Independent rejected-create review retains full commitment without retry/reset');
            check((int)$stage->query('SELECT request_slots FROM director_attempts LIMIT 1')->fetchColumn()===8,'Reviewed HTTP 400 still consumes all original request slots');
        }
    }
    foreach(['LITTLE_SPARKS_AI_ENABLED','LITTLE_SPARKS_DIRECTOR_ENABLED','LITTLE_SPARKS_SIMULATION_ENABLED'] as $flag){putenv($flag.'=0');check(!LittleSparks\managedLiveAdmission()['allowed'],'Each activation flag controls admission');putenv($flag.'=1');}
    check(LittleSparks\managedLiveAdmission()['allowed'],'Explicit configured activation admits routine provider');
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    echo "PASS environment-authoritative admission, same-day metadata accounting, changed daily/monthly limits, permit preservation, routine multiple encounters and no retries/credential access (mocked)\n";
    echo "PASS deployed six-resident identity/audit adoption and rollback, original mayor text/private links, synthetic-only bound permits, single generation/eight HTTP slots, delayed completion, cancellation/uncertainty, moderation/invalid output rollback, terminal billing evidence and retained experimental allocation (mocked only)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
