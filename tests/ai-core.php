<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/schema.php';require __DIR__.'/../app/ai_schema.php';require __DIR__.'/../app/ai_simulation.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function cli(string $script,array $args):string{
    $process=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script,...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'CLI starts');$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,$error);return $out;
}
function stateDigest(PDO $db):string{
    $hash=hash_init('sha256');
    foreach(['residents','settings','resident_preferences','home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events'] as $table){
        foreach($db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }
    return hash_final($hash);
}
final class MockOpenAi implements LittleSparks\AiTransport{
    public array $calls=[];public ?string $afterRace=null;public string $selectedAction='none';
    public function __construct(public string $mode='good',public ?PDO $db=null){}
    public function post(string $endpoint,array $body,int $deadline):array{
        $this->calls[]=$endpoint;check(time()<$deadline,'Request has a bounded future deadline');
        check(!str_contains(json_encode($body),'SECRET VISITOR'),'Only public context is sent');
        check(!str_contains(json_encode($body),'owner_hash')&&!str_contains(json_encode($body),'token_hash'),'Private fields excluded from request');
        if($endpoint==='responses'){
            check($body['store']===false&&$body['tools']===[]&&$body['service_tier']==='default','No storage/tools/premium processing');
            check($body['max_output_tokens']<=512,'Output tokens bounded');
            check($body['text']['format']['strict']===true&&$body['text']['format']['type']==='json_schema','Strict Responses schema');
            $context=json_decode($body['input'],true);check(count($context['characters'])===2,'Two bounded character contexts');
            foreach($context['characters'] as $character){check(count($character['memories'])<=3&&count($character['recent_dialogue'])<=4,'Bounded memory/dialogue');check(isset($character['preferences']['voice']),'Persistent preference in context');}
            if($this->mode==='timeout')throw new RuntimeException('CANARY_KEY_MUST_NEVER_BE_LOGGED');
            if($this->mode==='overlap'){
                try{LittleSparks\simulateDay($this->db,gmdate('Y-m-d',time()-86400),null,null,true);throw new LogicException('Overlapping scripted job was accepted');}
                catch(LittleSparks\Problem $error){check($error->status===409,'Active lease blocks another simulation');}
            }
            $available=$context['available_actions'];
            $choice=in_array('plant_flowers',$available,true)?'plant_flowers':'none';
            $this->selectedAction=$choice;
            $proposal=['topic'=>'garden','first'=>'I remembered our stars and planted a small hope.','second'=>'I like the garden better with a thoughtful neighbor.','world_action'=>$choice];
            if($this->mode==='invalid_action')$proposal['world_action']='execute_shell';
            if($this->mode==='markup')$proposal['first']='<script>alert(1)</script>';
            if($this->mode==='extra')$proposal['sql']='DROP TABLE residents';
            if($this->mode==='key_text')$proposal['first']='sk-this-is-not-a-real-key';
            $response=['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>1200,'output_tokens'=>100,'total_tokens'=>1300],
                'output'=>[['type'=>'reasoning'],['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]]];
            if($this->mode==='missing_usage')unset($response['usage']);
            if($this->mode==='zero_usage')$response['usage']=['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
            if($this->mode==='over_usage')$response['usage']=['input_tokens'=>40000,'output_tokens'=>100,'total_tokens'=>40100];
            if($this->mode==='missing_tier')unset($response['service_tier']);
            if($this->mode==='wrong_model')$response['model']='unpriced-provider-model';
            if($this->mode==='incomplete')$response['status']='incomplete';
            if($this->mode==='refusal')$response['output']=[['type'=>'message','role'=>'assistant','content'=>[['type'=>'refusal','refusal'=>'No.']]]];
            if($this->mode==='lease_expired')$this->db->exec('UPDATE ai_lease SET expires_at=1');
            return $response;
        }
        check($endpoint==='moderations'&&$body['model']==='omni-moderation-latest','Fixed moderation model');
        if($this->mode==='moderation_timeout')throw new RuntimeException('CANARY_KEY_MUST_NEVER_BE_LOGGED');
        if($this->mode==='collision'){
            $attempt=$this->db->query('SELECT * FROM ai_attempts LIMIT 1')->fetch();
            $choices=LittleSparks\aiActionChoices($this->db,$attempt['resident_a'],$attempt['resident_b'],time());
            $this->db->exec('BEGIN IMMEDIATE');LittleSparks\performResidentAction($this->db,$attempt['resident_a'],$choices['plant_flowers'],gmdate('Y-m-d\TH:i:s\Z'),'race-fixture','visitor');$this->db->exec('COMMIT');
            $this->afterRace=stateDigest($this->db);
        }
        return $this->mode==='moderation_missing'?[]:['results'=>[['flagged'=>$this->mode==='flagged']]];
    }
}

try{
    $directory=$argv[1]??'';check(is_dir($directory)&&str_starts_with(basename($directory),'little-sparks-v2-test-'),'Only dedicated disposable fixture directories');
    putenv('OPENAI_API_KEY');putenv('LITTLE_SPARKS_AI_ENABLED=0');
    $base=$directory.'/world.sqlite';$db=LittleSparks\connect($base);$secret=LittleSparks\secret($db);
    // One additional fixture resident ensures an eligible actor with room in its home plot.
    $id=str_repeat('0',32);$owner=hash('sha256','ai-fixture-owner');$request=str_repeat('d',32);$token=hash_hmac('sha256',$owner.':'.$request,$secret);
    $db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Birch','mint','sprout','curious','1900-01-01T00:00:00Z',$owner,$request,hash('sha256','fixture'),hash('sha256',$token)]);
    LittleSparks\assignHome($db,$id,gmdate('Y-m-d\TH:i:s\Z'));
    $before=LittleSparks\worldDigest($db);$db=null;check(copy($base,$directory.'/v2-backup.sqlite'),'Quiescent V2 backup fixture');
    // A DDL failure after the table rebuild must restore the entire original V2 schema/data.
    $broken=$directory.'/migration-refusal.sqlite';check(copy($base,$broken),'Refusal fixture');
    $brokenDb=LittleSparks\connect($broken);$brokenDb->exec('CREATE TABLE ai_attempts(conflict TEXT)');$brokenBefore=LittleSparks\worldDigest($brokenDb);$brokenDb=null;
    try{cli('migrate-ai.php',['--database='.$broken]);throw new LogicException('Conflicting migration accepted');}catch(RuntimeException $error){check(!($error instanceof LogicException),'Conflicting migration refused');}
    $brokenDb=LittleSparks\connect($broken);check((int)$brokenDb->query('PRAGMA user_version')->fetchColumn()===2&&hash_equals($brokenBefore,LittleSparks\worldDigest($brokenDb)),'Failed DDL rolls back all V2 tables/rows/version');$brokenDb=null;
    cli('migrate-ai.php',['--database='.$base]);$db=LittleSparks\connect($base);
    check((int)$db->query('PRAGMA user_version')->fetchColumn()===3,'Separate V3 migration applied');
    check(hash_equals($before,LittleSparks\worldDigest($db)),'Every V2 record and value is preserved');
    check(!$db->query('PRAGMA foreign_key_check')->fetch(),'Foreign keys valid after table replacement');
    check((int)$db->query('SELECT COUNT(*) FROM resident_preferences')->fetchColumn()===(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn(),'Every resident has persistent preferences');
    $prefs=$db->query('SELECT * FROM resident_preferences ORDER BY resident_id')->fetchAll();$db=null;cli('migrate-ai.php',['--database='.$base]);$db=LittleSparks\connect($base);
    check($prefs===$db->query('SELECT * FROM resident_preferences ORDER BY resident_id')->fetchAll(),'Repeat migration does not change preferences');
    check(LittleSparks\revisit($db,$token,'migration-fixture',time())['id']===$id,'Private revisit token still identifies the same resident');
    $new=LittleSparks\create($db,['name'=>'New Spark','color'=>'peach','antenna'=>'bolt','personality'=>'playful','requestId'=>str_repeat('e',32),'website'=>''],'v3-new-browser','v3-new-network',time(),true);
    check(LittleSparks\preferences($db,$new['resident']['id'])['voice']==='bright','New V3 residents get persistent preferences');
    check(LittleSparks\revisit($db,$new['token'],'v3-new-network',time())['id']===$new['resident']['id'],'V3 creation and private revisits still work');
    $db=null;echo "PASS separate V2→V3 CLI migration preserves populated world, hashes, private links, history and preferences\n";
    $counter=0;
    $fresh=function(?string $template=null)use($base,$directory,&$counter):PDO{$path=$directory.'/case-'.(++$counter).'.sqlite';check(copy($template??$base,$path),'Copy quiescent disposable fixture');return LittleSparks\connect($path);};
    $today=gmdate('Y-m-d');$yesterday=gmdate('Y-m-d',time()-86400);
    putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED='.LittleSparks\AI_PRICING_VERSION);putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS');
    check(LittleSparks\aiConfiguration(time())['daily_requests']===2,'Unset daily ceiling defaults to two');
    $db=$fresh();$mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
    check(!$mock->calls&&$result['provider']==='scripted','Disabled provider falls back with zero calls');
    $historical=LittleSparks\simulateOptionalAiDay($db,'2000-01-01',true,$mock);check($historical['replayed']&&!$mock->calls,'Historical completed periods replay without spending');
    putenv('LITTLE_SPARKS_AI_ENABLED=1');
    $db=$fresh();$mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$today,false,$mock);check(!$mock->calls&&$result['provider']==='scripted','Explicit paid option required');
    $db=$fresh();$mock=new MockOpenAi('good',$db);$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
    check($result['status']==='completed'&&$result['provider']==='openai','Mocked provider applies a real AI encounter');
    check($mock->calls===['responses','moderations'],'At most two model requests including moderation');
    check((int)$db->query('SELECT COUNT(*) FROM ai_conversations')->fetchColumn()===1,'Exactly the generated exchange is labeled AI');
    $attempt=$db->query('SELECT * FROM ai_attempts')->fetch();check($attempt['state']==='applied'&&(int)$attempt['input_tokens']===1200&&(int)$attempt['output_tokens']===100,'Usage and applied outcome stored');
    check((int)$attempt['estimated_microusd']===200,'Integer conservative cost includes cache-write input reserve');
    if($mock->selectedAction!=='none'){
        $savedAction=$db->prepare('SELECT COUNT(*) FROM events WHERE run_id=? AND kind=?');$savedAction->execute([$attempt['id'],$mock->selectedAction]);check((int)$savedAction->fetchColumn()===1,'Exactly the validated proposed action is journaled');
    }
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    $eventCount=$db->query('SELECT COUNT(*) FROM events')->fetchColumn();$replay=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
    check($replay['replayed']&&count($mock->calls)===2&&$eventCount===$db->query('SELECT COUNT(*) FROM events')->fetchColumn(),'Rerun does not spend or duplicate events');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    $fallback=LittleSparks\simulateOptionalAiDay($db,$yesterday,true,$mock);
    check($fallback['provider']==='scripted'&&$fallback['ai_blocked']==='daily_request_cap'&&count($mock->calls)===2,'Daily ceiling survives reruns and blocks extra requests');
    $profile=LittleSparks\profile($db,$attempt['resident_a']);check(!str_contains(json_encode($profile),$token)&&!str_contains(json_encode($profile),'pricing_version'),'Public profile excludes credential and billing internals');
    check(in_array('openai',array_column($profile['conversations'],'provider'),true),'Public dialogue has accurate provenance');
    check(copy($directory.'/case-'.$counter.'.sqlite',$directory.'/http-fixture.sqlite'),'Quiescent successful mock fixture for HTTP checks');
    file_put_contents($directory.'/http-resident.txt',$attempt['resident_a']);
    $actionPath=$directory.'/action-only.sqlite';cli('migrate-village.php',['--database='.$actionPath]);cli('migrate-ai.php',['--database='.$actionPath]);$actionDb=LittleSparks\connect($actionPath);
    foreach(['Petal','Orbit'] as $index=>$name)LittleSparks\create($actionDb,['name'=>$name,'color'=>'mint','antenna'=>'orb','personality'=>'curious','website'=>'','requestId'=>str_pad((string)$index,32,'0',STR_PAD_LEFT)],'two-resident-fixture','two-resident-network',time(),true);
    check(copy($actionPath,$directory.'/action-template.sqlite'),'Quiescent two-resident action template');
    $actionMock=new MockOpenAi('good',$actionDb);$actionResult=LittleSparks\simulateOptionalAiDay($actionDb,$today,true,$actionMock);
    check($actionResult['status']==='completed'&&$actionMock->selectedAction==='plant_flowers','Eligible proposed action completes');
    check((int)$actionDb->query("SELECT COUNT(*) FROM world_objects WHERE kind='flowers'")->fetchColumn()===1,'AI action saves exactly one new flower object');
    echo "PASS disabled/manual activation, Responses contract, bounded context, moderated dialogue, memory/action persistence, accounting, replay and daily cap\n";
    foreach(['0','1','','-1','+2','2.5','1e2',' 2','2 ','201','999999999999999999999','unlimited','02'] as $value){
        putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.$value);
        $db=$fresh();$mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
        $reason=in_array($value,['0','1'],true)?'daily_request_cap':'configuration_invalid';
        check(!$mock->calls&&$result['ai_blocked']===$reason,'Daily configuration fails closed for '.json_encode($value));
        check((int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===0,'Blocked configuration reserves no slots');
        $status=json_decode(cli('ai-status.php',['--database='.$directory.'/case-'.$counter.'.sqlite']),true,512,JSON_THROW_ON_ERROR);
        check($status['configured_daily_requests']===(in_array($value,['0','1'],true)?(int)$value:null),'Status reports validated daily limit without secrets');
    }
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');check(LittleSparks\aiConfiguration(time())['daily_requests']===200,'Conservative maximum accepted');
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);
    $usage=['model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>100,'output_tokens'=>10,'total_tokens'=>110]];
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');$config=LittleSparks\aiConfiguration(time());
    $first=LittleSparks\reserveAi($db,$today,$pair,$config,time());
    LittleSparks\recordAiUsage($db,$first,$usage,$config);LittleSparks\finishAi($db,$first,'rejected','invalid_proposal');
    foreach([2,3,4] as $limit){
        putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS='.$limit);$config=LittleSparks\aiConfiguration(time());
        try{
            $second=LittleSparks\reserveAi($db,$yesterday,$pair,$config,time());
            check($limit===4,'Both HTTP slots must fit the configured ceiling');
            LittleSparks\recordAiUsage($db,$second,$usage,$config);LittleSparks\finishAi($db,$second,'rejected','invalid_proposal');
        }catch(LittleSparks\AiGuard $error){check($limit<4&&$error->reason==='daily_request_cap','Two slots, including moderation, count even after failure');}
    }
    check((int)$db->query('SELECT SUM(request_slots) FROM ai_attempts')->fetchColumn()===4,'Environment change permits four reserved HTTP slots without a code change');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    try{LittleSparks\reserveAi($db,gmdate('Y-m-d',time()-2*86400),$pair,LittleSparks\aiConfiguration(time()),time());throw new LogicException('Lowered ceiling ignored');}
    catch(LittleSparks\AiGuard $error){check($error->reason==='daily_request_cap','Lowering the ceiling preserves existing reservations');}
    echo "PASS configurable daily ceiling: unset/default, zero, invalid/oversized input, odd limits, environment changes, reservation retention, status and same-period replay\n";
    foreach(['invalid_action','markup','extra','key_text','incomplete','refusal','flagged','moderation_missing','moderation_timeout','lease_expired','missing_usage','zero_usage','over_usage','missing_tier','wrong_model','timeout','collision','overlap'] as $mode){
        $db=$fresh($mode==='collision'?$directory.'/action-template.sqlite':null);$mock=new MockOpenAi($mode,$db);$snapshot=stateDigest($db);$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
        if($mode==='overlap'){check($result['status']==='completed','Lease prevents overlap without blocking owning run');continue;}
        check($result['status']==='failed','Reject '.$mode);
        if($mode==='collision')check($mock->afterRace!==null,'A real concurrent visitor action occupied the proposed cell');
        check(($mock->afterRace??$snapshot)===stateDigest($db),'No partial world/social changes for '.$mode);
        check(count($mock->calls)<=2,'No automatic retry for '.$mode);
        check(!str_contains(json_encode($db->query('SELECT * FROM ai_attempts')->fetchAll()),'CANARY_KEY'),'No raw exception/secret in ledger');
        if(in_array($mode,['missing_usage','zero_usage','over_usage','missing_tier','wrong_model','timeout'],true)){
            check($db->query('SELECT state FROM ai_attempts')->fetchColumn()==='uncertain','Unknown usage retains reservation');
            $beforeCalls=count($mock->calls);$fallback=LittleSparks\simulateOptionalAiDay($db,$yesterday,true,$mock);
            check($fallback['provider']==='scripted'&&count($mock->calls)===$beforeCalls,'Uncertain accounting blocks all additional paid attempts');
        }
    }
    echo "PASS invalid/tool-like/HTML/refused/flagged output, missing usage/tier/model, timeouts, expired lease, collisions, overlap and rollback (no paid calls)\n";
    foreach([['LITTLE_SPARKS_AI_MONTHLY_MICROUSD','0','monthly_budget_cap'],['LITTLE_SPARKS_AI_MONTHLY_MICROUSD','1000001','configuration_invalid'],['LITTLE_SPARKS_AI_MODEL','unknown-model','model_unpriced'],['LITTLE_SPARKS_AI_PRICING_CONFIRMED','stale','pricing_unverified'],['LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS','513','configuration_invalid']] as [$setting,$value,$reason]){
        putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED='.LittleSparks\AI_PRICING_VERSION);putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512');putenv($setting.'='.$value);
        $db=$fresh();$mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);check(!$mock->calls&&$result['ai_blocked']===$reason,'Fail closed for '.$setting);
    }
    putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512');putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED='.LittleSparks\AI_PRICING_VERSION);putenv('LITTLE_SPARKS_AI_MODEL=gpt-6.1-sol');
    $db=$fresh();$mock=new MockOpenAi('good',$db);$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);check($result['status']==='completed','Configurable priced model works');putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');
    $config=LittleSparks\aiConfiguration(time());
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);
    $month=gmdate('Y-m');$otherDay=$month.(substr($today,-2)==='01'?'-02':'-01');
    $db->prepare("INSERT INTO ai_attempts(id,period,day,month,resident_a,resident_b,model,pricing_version,input_rate,output_rate,reserved_microusd,estimated_microusd,state,created_at) VALUES ('budget-fixture','2000-01-01',?,?,?,?,?,?,125000,500000,999999,999999,'applied','fixture')")
        ->execute([$otherDay,$month,$pair[0]['id'],$pair[1]['id'],'gpt-6-luna',LittleSparks\AI_PRICING_VERSION]);
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');
    $mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);check(!$mock->calls&&$result['ai_blocked']==='monthly_budget_cap','Higher daily ceiling cannot bypass the monthly ledger cap');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);$boundary=strtotime(gmdate('Y-m-d').' 23:59:50 UTC');
    try{LittleSparks\reserveAi($db,$today,$pair,$config,$boundary);throw new LogicException('Boundary request accepted');}catch(LittleSparks\AiGuard $error){check($error->reason==='day_boundary','Boundary blocks dispatch');}
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);$held=LittleSparks\reserveAi($db,$today,$pair,$config,time());
    $db->exec('UPDATE ai_lease SET expires_at=1');$mock=new MockOpenAi();$result=LittleSparks\simulateOptionalAiDay($db,$yesterday,true,$mock);check(!$mock->calls&&$result['provider']==='scripted','Crashed reservation cannot dispatch again; scripted fallback resumes after lease expiry');
    check(!$db->query('PRAGMA foreign_key_check')->fetch(),'V3 foreign keys remain valid');
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);$held=LittleSparks\reserveAi($db,$today,$pair,$config,time());
    LittleSparks\recordAiUsage($db,$held,['model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>100,'output_tokens'=>10,'total_tokens'=>110]],$config);
    $db->exec('UPDATE ai_lease SET expires_at=1');$mock=new MockOpenAi();$fallback=LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
    check($fallback['provider']==='scripted'&&!$mock->calls,'Known-usage abandoned job can resume as a separate scripted result');
    LittleSparks\simulateOptionalAiDay($db,$today,true,$mock);
    check($db->query('SELECT state FROM ai_attempts')->fetchColumn()==='rejected','Recovery never labels a scripted fallback as applied AI');
    $db=$fresh();$pair=LittleSparks\selectAiPair($db,$today);
    $old=LittleSparks\reserveAi($db,$today,$pair,$config,time());
    LittleSparks\recordAiUsage($db,$old,['model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>100,'output_tokens'=>10,'total_tokens'=>110]],$config);
    LittleSparks\finishAi($db,$old,'rejected','invalid_proposal');
    $nextMonth=(new DateTimeImmutable('first day of next month 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
    $next=LittleSparks\reserveAi($db,gmdate('Y-m-d',$nextMonth),$pair,$config,$nextMonth);
    check($next['month']!==$old['month']&&$next['day']===gmdate('Y-m-d',$nextMonth),'Known spend uses separate UTC month/day buckets');
    LittleSparks\finishAi($db,$next,'uncertain','usage_unknown');
    try{LittleSparks\reserveAi($db,gmdate('Y-m-d',$nextMonth+86400),$pair,$config,$nextMonth+86400);throw new LogicException('Uncertain prior cost ignored');}
    catch(LittleSparks\AiGuard $error){check($error->reason==='accounting_unresolved','Unknown costs remain blocked across month/day changes');}
    try{LittleSparks\aiConfiguration(strtotime('2026-11-24 12:00 UTC'));throw new LogicException('Expired pricing accepted');}
    catch(LittleSparks\AiGuard $error){check($error->reason==='pricing_unverified','Pricing validity window enforced');}
    echo "PASS monthly budget, configuration limits, priced-model allowlist, day boundary, crash reservation and safe fallback\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);}
