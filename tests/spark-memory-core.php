<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require_once __DIR__.'/../app/conversation_archive.php';
function sparkCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
final class SparkCountingPdo extends PDO
{
    public int $reads=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->reads++;return parent::prepare($query,$options);}
}
function sparkFixtureEpisode(PDO $db,string $a,string $b,string $time,string $first,string $second,string $visibility='public',string $status='completed'):string
{
    $id=bin2hex(random_bytes(16));$run=bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,interaction_count) VALUES (?,?,?,'scripted',?,?,1)")->execute([$run,'spark-fixture:'.$run,$status,$time,$time]);
    $db->prepare("INSERT INTO conversations VALUES (?,?,'garden',?,?)")->execute([$id,$run,$visibility,$time]);
    foreach([$a,$b] as $who)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$who]);
    foreach([$first,$second] as $i=>$text)$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$id,$i,[$a,$b][$i],$text]);
    $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,?,'conversation','simulation','public',?,?)")->execute([$a,$b,$run,$time,'social:'.$run.':'.$id]);return $id;
}
final class PersonalSparkMock implements LittleSparks\AiTransport
{
    public array $calls=[];public array $contexts=[];public array $speech=[];private int $said=0;
    public function __construct(public PDO $db,public string $mode='good',public int $length=3,public ?string $invalidate=null){}
    public function post(string $endpoint,array $body,int $deadline):array
    {
        $this->calls[]=[$endpoint,$body];sparkCheck($deadline>time()&&$deadline<=time()+240,'Bounded request deadline');
        if($endpoint==='moderations'){
            if($this->invalidate!==null)$this->db->prepare("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$this->invalidate]);
            return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        }
        if($this->mode==='unknown')throw new RuntimeException('OFFLINE_UNKNOWN_CALL');
        $c=json_decode($body['input'],true);$this->contexts[]=$c;$self=$c['resident_id'];
        sparkCheck($body['instructions']===LittleSparks\sparkInstructions()&&$body['store']===false&&$body['tools']===[]&&!isset($body['previous_response_id']),'Personal instructions, no inherited pair session or enabled external tools');
        $p=['contract'=>'sanctuary.spark.reply.v1','resident_id'=>$self,'mode'=>'say','text'=>['I am still angry about the oven.','I disagree. I asked you before changing it.','Then let me finish.'][$this->said%3],
            'query'=>'','topic'=>'An unresolved disagreement','keep_talking'=>$this->said+1<$this->length,'remember'=>true];
        if($this->mode==='recall'&&count($this->contexts)===1){$p['mode']='recall';$p['text']='';$p['query']='scorched lasagna';$p['remember']=false;}
        elseif($this->mode==='quiet'){$p['mode']='stop';$p['text']='';$p['remember']=false;$p['keep_talking']=false;}
        else{$this->said++;$this->speech[]=['resident_id'=>$self,'text'=>$p['text']];}
        if($this->mode==='wrong_holder')$p['resident_id']=$c['listener']['id'];
        if($this->mode==='extra')$p['world_action']='plant_tree';
        if($this->mode==='expired')$this->db->exec('UPDATE ai_lease SET expires_at=0 WHERE owner IS NOT NULL');
        $r=['status'=>'completed','model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>1500,'output_tokens'=>150,'total_tokens'=>1650],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR)]]]]];
        if($this->mode==='usage')unset($r['usage']);if($this->mode==='incomplete')$r['status']='incomplete';return $r;
    }
}
try{
    $dir=$argv[1];$base=$argv[2];$a=str_repeat('a',32);$b=str_repeat('b',32);$c=str_repeat('c',32);$d=str_repeat('d',32);$now=time();$at=gmdate('Y-m-d\TH:i:s\Z',$now);
    foreach(['AI_ENABLED'=>'1','DIRECTOR_ENABLED'=>'1','SIMULATION_ENABLED'=>'1','DIRECTOR_RUNTIME'=>'responses','AI_PRICING_CONFIRMED'=>LittleSparks\AI_PRICING_VERSION,'AI_DAILY_REQUESTS'=>'10000','AI_MONTHLY_MICROUSD'=>'100000000','AI_MAX_OUTPUT_TOKENS'=>'13312','AI_MAX_REQUEST_BYTES'=>'32768','VILLAGE_SLEEP_ENABLED'=>'0','RESIDENT_DAILY_MAX'=>'1000','MEMORY_CONTEXT_BYTES'=>'8192'] as $key=>$value)putenv('LITTLE_SPARKS_'.$key.'='.$value);
    $fresh=function(string $tag,bool $migrate=true)use($dir,$base,$a,$b,$c,$d,$at):PDO{
        $file=$dir.'/'.$tag.'.sqlite';copy($base,$file);$db=LittleSparks\connect($file);
        foreach([$a,$b,$c,$d] as $i=>$id){$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,'Fixture '.chr(65+$i),'mint','orb','curious',$at,hash('sha256','owner'.$id),$id,hash('sha256','request'.$id),hash('sha256','token'.$id)]);LittleSparks\assignHome($db,$id,$at);LittleSparks\identityForNewResident($db,$id,'SELF_ONLY_'.chr(65+$i).' An independent fictional adult who can disagree.','mint',$at);}
        $export=['id'=>'agent_spark_offline','model'=>'gpt-6-luna','instructions'=>'DIRECTOR_ONLY_CANARY','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];$reg=LittleSparks\registerDirector($db,$export);putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$reg['agent_id']);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$reg['fingerprint']);
        if($migrate){$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateSparkLedger($db);$db->exec('COMMIT');}return $db;
    };
    $db=$fresh('migration',false);$hash=static function(PDO $db):array{$out=[];foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll() as $t)$out[$t['name']]=[$t['sql'],$db->query('SELECT * FROM "'.$t['name'].'" ORDER BY rowid')->fetchAll()];return $out;};$before=$hash($db);
    $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateSparkLedger($db);$db->exec('ROLLBACK');sparkCheck(!LittleSparks\sparkLedgerInstalled($db)&&$hash($db)===$before,'Migration rollback leaves all existing data/schema unchanged');
    $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateSparkLedger($db);$db->exec('COMMIT');$after=$hash($db);foreach($before as $name=>$rows)sparkCheck($after[$name]===$rows,'Additive migration preserves '.$name);
    LittleSparks\migrateSparkLedger($db);sparkCheck((int)$db->query('PRAGMA user_version')->fetchColumn()===6&&!$db->query('PRAGMA foreign_key_check')->fetch(),'Repeat migration preserves V6 and foreign keys');
    $old=sparkFixtureEpisode($db,$a,$b,'2026-10-20T12:00:00Z','We disagreed about scorched lasagna.','Ask before changing my oven.');
    for($i=0;$i<40;$i++)sparkFixtureEpisode($db,$a,$c,gmdate('Y-m-d\TH:i:s\Z',strtotime('2026-10-21')+$i*60),'THIRD_PARTY_GAG_CANARY '.$i,'A recent unrelated reply.');
    sparkFixtureEpisode($db,$a,$b,'2026-10-22T12:00:00Z','PRIVATE_CANARY','A private reply.','private');sparkFixtureEpisode($db,$a,$b,'2026-10-23T12:00:00Z','FAILED_CANARY','A failed reply.','public','failed');
    $db->prepare("INSERT INTO director_notes VALUES (?,?,?,?,0,?,'interpretation',?)")->execute([str_repeat('e',32),$b,$a,$old,'We disagreed about scorched lasagna.','2026-10-20T12:00:00Z']);
    $claim=['id'=>'spark-context','created_at'=>$at];$pair=[['id'=>$a],['id'=>$b]];$digest=$hash($db);$prepared=LittleSparks\prepareDirectorEncounter($db,$pair,['none'=>null],$claim,LittleSparks\directorConfiguration());
    sparkCheck($prepared['context']['shared_recent_conversations'][0]['id']===$old,'Shared history survives forty newer unrelated meetings');
    $note=$prepared['context']['participants'][1]['unverified_notes'][0];sparkCheck($note['holder_id']===$b&&$note['speaker_id']===$a,'Remembering another person\'s words never makes them one\'s own preference');
    $recall=LittleSparks\sparkRecall($db,$a,$at,null,'scorched lasagna',2);sparkCheck(count($recall)===1&&$recall[0]['id']===$old,'Recall searches older history beyond the newest candidate window');
    sparkCheck(LittleSparks\sparkRecall($db,$d,$at,null,'scorched lasagna',2)===[],'Public readership is not individual knowledge');
    $raw=json_encode($prepared);sparkCheck(!str_contains($raw,'PRIVATE_CANARY')&&!str_contains($raw,'FAILED_CANARY')&&$hash($db)===$digest,'Preparation is source-authorized and read-only');
    $holder=LittleSparks\sparkActorContext($prepared,1,[],[],true);$raw=json_encode($holder);sparkCheck(!str_contains($raw,'SELF_ONLY_A')&&!str_contains($raw,'THIRD_PARTY_GAG_CANARY')&&str_contains($raw,'SELF_ONLY_B'),'Only holder B\'s identity and experiences enter B\'s brain');
    putenv('LITTLE_SPARKS_DIALOGUE_MODE=independent');putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES=16');
    $slot=0;$generate=function(PDO $db,string $mode='good',int $length=3,?string $withdraw=null)use(&$slot,$now):array{
        $mock=new PersonalSparkMock($db,$mode,$length,$withdraw);$op=gmdate('Y-m-d\TH:i:00\Z',$now-7200+$slot++*60);$result=LittleSparks\simulateDirectorOpportunity($db,$op,true,$mock);return [$result,$mock,$op];
    };
    foreach([2,3,8,16] as $length){
        $db=$fresh('length-'.$length);[$r,$mock,$op]=$generate($db,'good',$length);sparkCheck($r['status']==='completed','Independent conversation saves '.$length.' turns: '.json_encode($r));
        $id=$db->query('SELECT id FROM conversations ORDER BY rowid DESC LIMIT 1')->fetchColumn();$saved=LittleSparks\conversationMessageBatch($db,[$id])[$id];sparkCheck(array_column($saved,'text')===array_column($mock->speech,'text'),'Original independent speech remains byte-exact');
        foreach($mock->contexts as $i=>$ctx){sparkCheck($ctx['resident_id']===$mock->speech[$i]['resident_id'],'Only the selected Spark authors each reply');sparkCheck($ctx['current_exchange']===array_slice($mock->speech,0,$i),'Each brain sees the counterpart\'s actual preceding words');$other=substr($ctx['listener']['id'],0,1);sparkCheck(!str_contains(json_encode($ctx),'SELF_ONLY_'.strtoupper($other)),'No counterpart identity or private state leak');}
        $audit=$db->query('SELECT * FROM spark_encounters')->fetch();sparkCheck((int)$audit['generation_limit']===48&&$audit['state']==='completed'&&(int)$audit['charged_adjustment']===$length-1,'Configured sixteen-message support without an eight-slot clamp; unused new headroom closes accurately');
        $calls=$db->query('SELECT * FROM spark_calls ORDER BY sequence')->fetchAll();sparkCheck(count($calls)===$length+1&&$calls[$length]['kind']==='moderation','One final full-exchange moderation after independent replies');
        sparkCheck(LittleSparks\sparkExtraRequests($db,gmdate('Y-m-d'))+2===$length+1,'Known-complete daily charge equals dispatched calls');
        $cost=(int)$db->query('SELECT estimated_microusd FROM ai_attempts')->fetchColumn();sparkCheck($cost===array_sum(array_column($calls,'cost_microusd'))&&LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))===$cost,'Actual cumulative token estimates settle accurately');
        sparkCheck((int)$db->query('SELECT interaction_count FROM simulation_runs')->fetchColumn()===1&&(int)$db->query('SELECT affinity FROM relationships')->fetchColumn()===2,'One encounter relationship/accounting increment despite more model calls');
        $count=count($mock->calls);$again=LittleSparks\simulateDirectorOpportunity($db,$op,true,$mock);sparkCheck($again['replayed']&&count($mock->calls)===$count,'Completed opportunity never repeats independent calls');
        $beforeCalls=$calls;try{$db->exec('DELETE FROM spark_calls');throw new LogicException('Audit deletion accepted');}catch(PDOException){}sparkCheck($db->query('SELECT * FROM spark_calls ORDER BY sequence')->fetchAll()===$beforeCalls,'Call history is immutable');
    }
    $db=$fresh('recall');$old=sparkFixtureEpisode($db,$a,$b,'2026-10-20T12:00:00Z','We disagreed about scorched lasagna.','Ask before changing my oven.');
    [$r,$mock]=$generate($db,'recall');sparkCheck($r['status']==='completed'&&$mock->contexts[1]['recalled_episodes'][0]['id']===$old,'A resident asks for and receives its older source-bound memory');
    sparkCheck((int)$db->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'spark_memory_context:%'")->fetchColumn()===2,'Each accepted participant has an exposure receipt, not shared omniscience');
    [$r,$next]=$generate($db);sparkCheck($r['status']==='completed'&&isset($next->contexts[0]['conversation_flow']),'Continuation preserves exact source speech across independent actors');
    $latest=$db->query('SELECT id FROM conversations ORDER BY rowid DESC LIMIT 1')->fetchColumn();$archive=LittleSparks\conversationArchive($db,['conversation'=>$latest]);sparkCheck(isset($archive['conversations'][0]['continuation']['source_id']),'Reader can follow the preserved public predecessor');
    $db->exec('VACUUM INTO '.$db->quote($dir.'/reader.sqlite'));
    $counting=new SparkCountingPdo('sqlite:'.$dir.'/reader.sqlite',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$counting->exec('PRAGMA query_only=ON');
    LittleSparks\conversationArchive($counting,['size'=>'100']);$batchReads=$counting->reads;$counting->reads=0;LittleSparks\conversationArchive($counting,['conversation'=>$latest]);
    sparkCheck($batchReads===10&&$counting->reads===10,'Eight existing multi-message archive reads plus two lineage batches stay constant for full page and one continuation: '.$batchReads.'/'.$counting->reads);$counting=null;
    $parent=$archive['conversations'][0]['continuation']['source_id'];$db->prepare("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$parent]);
    sparkCheck(LittleSparks\publicConversationLineage($db,[$latest])===[],'Unavailable predecessor identifiers never leak through lineage links');
    foreach(['quiet','flagged','wrong_holder','extra','incomplete','unknown','usage','expired'] as $mode){
        $db=$fresh('failure-'.$mode);[$r,$mock,$op]=$generate($db,$mode,2);sparkCheck($r['status']===($mode==='quiet'?'completed':'failed'),'Expected isolated-actor outcome '.$mode.': '.json_encode($r));
        sparkCheck(!(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()&&!(int)$db->query("SELECT COUNT(*) FROM settings WHERE name LIKE 'spark_memory_context:%'")->fetchColumn(),'No partial speech or accepted memory after '.$mode);
        $q=$db->query('SELECT state,reserved_extra_slots,charged_adjustment FROM spark_encounters')->fetch();
        if(in_array($mode,['unknown','usage','expired'],true))sparkCheck(in_array($q['state'],['reserved','uncertain'],true)&&$q['charged_adjustment']===null&&LittleSparks\sparkExtraRequests($db,gmdate('Y-m-d'))===47,'Unknown work retains all unspent/uncertain headroom '.$mode);
        else sparkCheck($q['state']===($mode==='quiet'?'completed':'rejected'),'Known rejected work is distinguished from uncertain dispatch '.$mode);
        $count=count($mock->calls);LittleSparks\simulateDirectorOpportunity($db,$op,true,$mock);sparkCheck(count($mock->calls)===$count,'Failed opportunities never retry '.$mode);
    }
    $db=$fresh('withdraw');$old=sparkFixtureEpisode($db,$a,$b,'2026-10-20T12:00:00Z','We disagreed about scorched lasagna.','Ask before changing my oven.');[$r,$mock]=$generate($db,'recall',3,$old);
    sparkCheck($r['status']==='failed'&&(int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===1&&!(int)$db->query('SELECT COUNT(*) FROM relationships')->fetchColumn(),'Source invalidation after moderation rolls back speech, memories and relationships');
    $db=$fresh('daily');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=48');[$r,$mock]=$generate($db);sparkCheck($r['status']==='failed'&&!$mock->calls,'All potential new calls are reserved atomically before dispatch');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=10000');
    $db=$fresh('uninstalled',false);try{$generate($db);throw new LogicException('Missing ledger accepted');}catch(LittleSparks\AiGuard $e){sparkCheck($e->reason==='configuration_invalid','Uninstalled capability cannot activate');}
    $db=$fresh('race');$cfg=LittleSparks\directorConfiguration();$pricing=LittleSparks\aiConfiguration(time());$pair=[['id'=>$a],['id'=>$b]];$claim=LittleSparks\claimActivity($db,gmdate('Y-m-d\TH:i:00\Z',$now-1800),time());
    $reg=LittleSparks\registeredDirector($db,$cfg);$session=LittleSparks\directorSession($db,$reg,$pair,'responses',time(),'sanctuary.director.v2');
    $pricing['director_session']=$session['id'];$pricing['director_runtime']='responses';$pricing['director_context']=str_repeat('0',64);$pricing['director_output_contract']='sanctuary.director.v2';$pricing['spark_generation_calls']=48;$pricing['max_output']=1024;
    LittleSparks\reserveAi($db,$claim['id'],$pair,$pricing,time());$db=null;
    echo "PASS additive Spark ledger preservation/rollback, stable holder memory and older recall, attribution/privacy, independent 2/3/8/16 replies, exact moderation/save, lineage, accurate usage/unused headroom, uncertain holds, replay, source rollback and activation/cap fences (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
