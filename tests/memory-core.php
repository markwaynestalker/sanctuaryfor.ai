<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/memory_schema.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function cli(string $script,string $path,bool $success=true):void{
    $p=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script,'--database='.$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($p),'CLI starts');stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check((proc_close($p)===0)===$success,'CLI migration outcome: '.$err);
}
function originalRows(PDO $db):array{
    $out=[];foreach(['settings','residents','rate_limits','home_plots','world_objects','relationships','conversations','conversation_participants','messages','memories','events','simulation_runs','resident_preferences','ai_attempts','ai_conversations','ai_lease','simulation_opportunities'] as $t)$out[$t]=$db->query("SELECT * FROM $t ORDER BY rowid")->fetchAll();return $out;
}
function memoryRows(PDO $db):array{
    $out=[];foreach(['friendship_identities','memory_stories','memory_receipts','friendship_memories','friendship_themes','memory_processed'] as $t)$out[$t]=$db->query("SELECT * FROM $t ORDER BY rowid")->fetchAll();return $out;
}
function receipt(PDO $db,string $resident,string $story):array{$q=$db->prepare('SELECT * FROM memory_receipts WHERE resident_id=? AND story_id=?');$q->execute([$resident,$story]);return $q->fetch()?:throw new RuntimeException('Missing receipt');}
function savedEncounter(PDO $db,string $a,string $b,string $time,string $first,string $second,?string $share=null,string $visibility='public'):string{
    $id=bin2hex(random_bytes(16));$db->exec('BEGIN IMMEDIATE');
    try{
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,interaction_count) VALUES (?,?,'completed','scripted',?,?,1)")->execute([$id,'memory-fixture:'.$id,$time,$time]);
        LittleSparks\strengthen($db,$a,$b,2,$time);
        $db->prepare('INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,?,?,?)')->execute([$id,$id,'stars',$visibility,$time]);
        foreach([$a,$b] as $seq=>$who){$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$who]);$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$id,$seq,$who,$seq===0?$first:$second]);}
        $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,?,'conversation','simulation',?,?,?)")->execute([$a,$b,$id,$visibility,$time,'social:'.$id.':'.$id]);
        if(LittleSparks\memoryEnabled($db)&&$visibility==='public')LittleSparks\conversationMemory($db,$id,$share);
        $db->exec('COMMIT');return $id;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
final class MemoryMock implements LittleSparks\AiTransport{
    public array $calls=[];public function __construct(public string $mode='good'){}
    public function post(string $endpoint,array $body,int $deadline):array{
        $this->calls[]=$endpoint;
        if($endpoint==='moderations')return ['results'=>[['flagged'=>$this->mode==='flagged']]];
        if($this->mode==='timeout')throw new RuntimeException('PRIVATE_CANARY');
        check($body['store']===false&&$body['tools']===[]&&$body['service_tier']==='default','No persistent provider history/tools/premium tier');
        $context=json_decode($body['input'],true);check(isset($context['characters'][0]['memories']),'Memory context included');check(!str_contains($body['input'],'PRIVATE_CANARY'),'No private context');
        foreach($context['characters'] as $actor)foreach($actor['recent_dialogue'] as $line)check(isset($line['speaker'],$line['at'],$line['text']),'Historical speaker and timestamp');
        $aliases=$body['text']['format']['schema']['properties']['story']['enum'];
        $proposal=['outcome'=>'conversation','topic'=>'garden','first'=>'I feel curious.','second'=>'Shall we take a quiet walk?','world_action'=>$this->mode==='action'?'plant_tree':'none','story'=>$aliases[1]??'none'];
        if($this->mode==='fake_source')$proposal['story']='EVENT:999999';
        if($this->mode==='false_claim')$proposal['first']='I planted a nonexistent tree yesterday.';
        $response=['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>1800,'output_tokens'=>100,'total_tokens'=>1900],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_THROW_ON_ERROR)]]]]];
        if($this->mode==='unknown')unset($response['usage']);return $response;
    }
}
try{
    $dir=$argv[1]??'';check(is_dir($dir)&&str_starts_with(basename($dir),'little-sparks-memory-test-'),'Disposable fixture directory only');
    putenv('OPENAI_API_KEY');putenv('LITTLE_SPARKS_AI_ENABLED=0');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=0');
    $base=$dir.'/v4.sqlite';cli('migrate-village.php',$base);cli('migrate-ai.php',$base);cli('migrate-activity.php',$base);$db=LittleSparks\connect($base);
    $ids=[];$tokens=[];$old=time()-40*86400;$names=['Sparky','Skippy Jr','Luna','Pip','Clover','Sol'];
    foreach($names as $i=>$name){$r=LittleSparks\create($db,['name'=>$name,'color'=>$i%2?'lavender':'mint','antenna'=>'sprout','personality'=>'curious','requestId'=>str_pad((string)$i,32,'0',STR_PAD_LEFT),'website'=>''],'memory-browser-'.$i,'memory-network-'.$i,$old,true);$ids[]=$r['resident']['id'];$tokens[]=$r['token'];}
    [$sparky,$skippy,$luna,$pip,$clover,$sol]=$ids;$then=gmdate('Y-m-d\TH:i:s\Z',$old+60);
    $db->prepare("UPDATE resident_preferences SET interest='gardening' WHERE resident_id=?")->execute([$skippy]);
    $p=LittleSparks\profile($db,$sparky)['plot'];$db->exec('BEGIN IMMEDIATE');$treeEvent=LittleSparks\performResidentAction($db,$sparky,LittleSparks\validateAction(['action'=>'plant_tree','x'=>$p['x'],'y'=>$p['y'],'requestId'=>str_repeat('d',32)]),$then,'memory-tree','visitor');$db->exec('COMMIT');
    foreach([['plant_flowers',$p['x'],$p['y']+2],['place_decoration',$p['x']+2,$p['y']]] as $i=>[$kind,$x,$y]){
        $db->exec('BEGIN IMMEDIATE');LittleSparks\performResidentAction($db,$sparky,LittleSparks\validateAction(['action'=>$kind,'x'=>$x,'y'=>$y,'requestId'=>str_repeat((string)($i+1),32)]),gmdate('Y-m-d\TH:i:s\Z',$old+62+$i),'saved-yard-'.$i,'visitor');$db->exec('COMMIT');
    }
    $legacy=savedEncounter($db,$sparky,$skippy,gmdate('Y-m-d\TH:i:s\Z',$old+120),'I enjoy stargazing.','A little kindness means a lot.');
    LittleSparks\remember($db,$skippy,$sparky,$legacy,'Heard a neighbor say: I enjoy stargazing.',4,gmdate('Y-m-d\TH:i:s\Z',$old+120));
    $private=savedEncounter($db,$luna,$pip,gmdate('Y-m-d\TH:i:s\Z',$old+180),'PRIVATE_CANARY I enjoy stargazing.','PRIVATE_CANARY',null,'private');
    $db->prepare("INSERT INTO memories(resident_id,other_resident_id,run_id,summary,significance,visibility,created_at) VALUES (?,?,?,'PRIVATE_CANARY',5,'private',?)")->execute([$luna,$pip,$private,$then]);
    // Include real existing paid accounting metadata without making any request.
    $db->prepare("INSERT INTO ai_attempts(id,period,day,month,resident_a,resident_b,model,pricing_version,input_rate,output_rate,reserved_microusd,estimated_microusd,state,created_at) VALUES ('existing-paid','existing-paid-period',?,?,?,?, 'gpt-6-luna',?,125000,500000,4352,200,'applied',?)")
        ->execute([gmdate('Y-m-d',$old),gmdate('Y-m',$old),$sparky,$skippy,LittleSparks\AI_PRICING_VERSION,$then]);
    $before=originalRows($db);$db=null;
    copy($base,$dir.'/broken.sqlite');$broken=LittleSparks\connect($dir.'/broken.sqlite');$broken->exec('CREATE TABLE memory_receipts(conflict TEXT)');$broken=null;cli('migrate-memory.php',$dir.'/broken.sqlite',false);$broken=LittleSparks\connect($dir.'/broken.sqlite');check((int)$broken->query('PRAGMA user_version')->fetchColumn()===4&&originalRows($broken)===$before,'Transactional DDL rollback preserves all V4 rows');$broken=null;
    copy($base,$dir.'/active.sqlite');$active=LittleSparks\connect($dir.'/active.sqlite');$active->prepare("UPDATE ai_lease SET owner='existing-paid',expires_at=?")->execute([time()+60]);$active=null;cli('migrate-memory.php',$dir.'/active.sqlite',false);
    cli('migrate-memory.php',$base);$db=LittleSparks\connect($base);check((int)$db->query('PRAGMA user_version')->fetchColumn()===5&&originalRows($db)===$before,'Every V4 record preserved');check(!$db->query('PRAGMA foreign_key_check')->fetch(),'V5 foreign keys valid');
    $structured=memoryRows($db);$db=null;cli('migrate-memory.php',$base);$db=LittleSparks\connect($base);check(memoryRows($db)===$structured,'Repeated migration is idempotent');
    check(LittleSparks\revisit($db,$tokens[0],'memory-revisit',time())['id']===$sparky,'Original private link preserved');
    savedEncounter($db,$sparky,$skippy,gmdate('Y-m-d\TH:i:s\Z',$old+240),'I feel curious.','I enjoy gardening.');
    $statement=$db->prepare("SELECT * FROM memory_stories WHERE origin_id=? AND kind='statement' AND topic='garden'");$statement->execute([$skippy]);$statement=$statement->fetch();check((int)receipt($db,$sparky,$statement['id'])['via_sequence']===1,'A resident remembers what the second speaker told them');
    $fid=LittleSparks\stableFriendshipId($sparky,$skippy);check($db->query("SELECT id FROM friendship_identities WHERE id='$fid'")->fetchColumn()===$fid,'Stable friendship identity persisted');
    $story=$db->query("SELECT * FROM memory_stories WHERE source_event_id=$treeEvent")->fetch();check($story&&LittleSparks\memorySource($db,$story)['status']==='confirmed','Actual tree is confirmed by saved event and object');$root=receipt($db,$sparky,$story['id']);
    check((int)$root['hops']===0,'Personal firsthand memory');
    $time1=gmdate('Y-m-d\TH:i:s\Z',$old+86400);$prefix=LittleSparks\storyOpening($db,$root,$story,$skippy);
    $c1=savedEncounter($db,$sparky,$skippy,$time1,$prefix.' I enjoy stargazing.','I feel hopeful.',$root['id']);$r1=receipt($db,$skippy,$story['id']);
    check($r1['heard_from_id']===$sparky&&(int)$r1['hops']===1,'Directly told, not witnessed');
    $time2=gmdate('Y-m-d\TH:i:s\Z',$old+2*86400);$c2=savedEncounter($db,$skippy,$luna,$time2,LittleSparks\storyOpening($db,$r1,$story,$luna).' I feel curious.','I feel peaceful.',$r1['id']);$r2=receipt($db,$luna,$story['id']);
    $time3=gmdate('Y-m-d\TH:i:s\Z',$old+3*86400);savedEncounter($db,$luna,$pip,$time3,LittleSparks\storyOpening($db,$r2,$story,$pip).' I feel hopeful.','That feels lovely.',$r2['id']);$r3=receipt($db,$pip,$story['id']);
    check((int)$r2['hops']===2&&(int)$r3['hops']===3&&$r2['parent_id']===$r1['id'],'Secondhand/thirdhand provenance chain');
    $count=(int)$db->query('SELECT COUNT(*) FROM memory_receipts')->fetchColumn();LittleSparks\conversationMemory($db,$c2,$r1['id']);check((int)$db->query('SELECT COUNT(*) FROM memory_receipts')->fetchColumn()===$count,'Duplicate encounter/stories suppressed');
    savedEncounter($db,$pip,$sparky,gmdate('Y-m-d\TH:i:s\Z',$old+4*86400),LittleSparks\storyOpening($db,$r3,$story,$sparky).' I feel curious.','I feel peaceful.',$r3['id']);check((int)receipt($db,$sparky,$story['id'])['hops']===0,'Circular retelling cannot replace firsthand provenance');
    for($i=0;$i<24;$i++)savedEncounter($db,$luna,$clover,gmdate('Y-m-d\TH:i:s\Z',$old+(10+$i)*86400),'I feel curious.','That feels lovely.');
    $choices=LittleSparks\aiActionChoices($db,$luna,$sparky,time());$actors=[['id'=>$luna,'personality'=>'curious'],['id'=>$sparky,'personality'=>'curious']];$prepared=LittleSparks\prepareMemoryEncounter($db,$actors,$choices);
    $chosen=array_values(array_filter($prepared['share'],fn($m)=>$m['story']['id']===$story['id']));check(count($chosen)===1,'Weeks-old relevant tree survives many newer conversations');
    check(str_contains(LittleSparks\storyOpening($db,$r2,$story,$sparky),'Skippy Jr told me you planted an oak tree.'),'Luna recognizes source without claiming to witness planting');
    $recognition=savedEncounter($db,$luna,$sparky,gmdate('Y-m-d\TH:i:s\Z',$old+36*86400),LittleSparks\storyOpening($db,$r2,$story,$sparky).' Shall we take a quiet walk?','That feels lovely.',$r2['id']);
    $q=$db->prepare('SELECT body FROM messages WHERE conversation_id=? AND sequence=0');$q->execute([$recognition]);check(str_contains($q->fetchColumn(),'Skippy Jr told me you planted an oak tree.'),'The weeks-later recognition is saved, not just transient context');
    check(strlen(json_encode($prepared['context'],JSON_UNESCAPED_UNICODE))<=LittleSparks\memoryConfiguration()['context_bytes'],'Hard context byte limit');check(!str_contains(json_encode($prepared),'PRIVATE_CANARY'),'Private memories/dialogue excluded');
    check($db->query("SELECT id FROM friendship_identities WHERE id='$fid'")->fetchColumn()===$fid,'Friendship identity survives new encounters');
    $gardenActors=[['id'=>$luna,'personality'=>'curious'],['id'=>$skippy,'personality'=>'curious']];$gardenChoices=LittleSparks\aiActionChoices($db,$luna,$skippy,time());$gardenContext=LittleSparks\prepareMemoryEncounter($db,$gardenActors,$gardenChoices);$guided=false;
    for($i=0;$i<30;$i++){$p=LittleSparks\scriptedMemoryProposal($db,$gardenActors,$gardenChoices,$gardenContext,'garden-test-'.$i);if($p['outcome']==='action'){check($p['world_action']==='plant_flowers','Documented friend preference influences a permitted gardening choice');$guided=true;break;}}check($guided,'An action opportunity is available');
    $lunaPlot=LittleSparks\profile($db,$luna)['plot'];$worldBefore=originalRows($db);$memBefore=memoryRows($db);
    foreach([[0,0],[$lunaPlot['x']+1,$lunaPlot['y']+1]] as $index=>$point){
        if($index===0)$point=[LittleSparks\profile($db,$sparky)['plot']['x'],LittleSparks\profile($db,$sparky)['plot']['y']];
        $db->exec('BEGIN IMMEDIATE');try{LittleSparks\performResidentAction($db,$luna,LittleSparks\validateAction(['action'=>'plant_tree','x'=>$point[0],'y'=>$point[1],'requestId'=>str_repeat('e',32)]),gmdate('Y-m-d\TH:i:s\Z'),'invalid-world-'.$index,'simulation');throw new LogicException('Unauthorized/colliding placement accepted');}catch(LittleSparks\Problem){$db->exec('ROLLBACK');}
    }check(originalRows($db)===$worldBefore&&memoryRows($db)===$memBefore,'Ownership/collision checks remain authoritative and atomic');
    $db->exec('BEGIN IMMEDIATE');$visit=LittleSparks\performResidentAction($db,$luna,LittleSparks\validateAction(['action'=>'visit_friend','target'=>$sparky,'requestId'=>str_repeat('9',32)]),gmdate('Y-m-d\TH:i:s\Z'),'real-visit','visitor');$db->exec('COMMIT');
    $q=$db->prepare('SELECT * FROM memory_stories WHERE source_event_id=?');$q->execute([$visit]);$visitStory=$q->fetch();check($visitStory&&$visitStory['subject_id']===$sparky&&(int)receipt($db,$luna,$visitStory['id'])['hops']===0,'A real visit creates personal memory with the actual target');
    $trail=LittleSparks\profile($db,$luna)['memory_trail'];check(in_array('Secondhand',array_column($trail,'perspective'),true),'Public-safe trail exposes perspective');
    $rumor=savedEncounter($db,$sol,$clover,gmdate('Y-m-d\TH:i:s\Z',$old+34*86400),'Perhaps the stars enjoy gardening.','That feels lovely.');
    $s=$db->query("SELECT * FROM memory_stories WHERE kind='rumor'")->fetch();$rr=receipt($db,$clover,$s['id']);savedEncounter($db,$clover,$luna,gmdate('Y-m-d\TH:i:s\Z',$old+35*86400),LittleSparks\storyOpening($db,$rr,$s,$luna).' I feel curious.','I feel peaceful.',$rr['id']);
    check(LittleSparks\memorySource($db,$s)['status']==='unverified','Repeated rumor never becomes a world fact');
    $snapshot=memoryRows($db);try{$forged=$story;$forged['source_event_id']=999999;LittleSparks\addMemoryReceipt($db,$forged,$sol,$time3);throw new LogicException('Fabricated source accepted');}catch(LittleSparks\Problem){}check(memoryRows($db)===$snapshot,'Unsupported provenance cannot create receipts');
    $originalSnapshot=originalRows($db);try{savedEncounter($db,$skippy,$sol,gmdate('Y-m-d\TH:i:s\Z',time()-60),'I invented a different tree.','That feels lovely.',$r1['id']);throw new LogicException('Unspoken story transmitted');}catch(LittleSparks\Problem){}check(memoryRows($db)===$snapshot&&originalRows($db)===$originalSnapshot,'Unsupported memory extraction rolls back all new records');
    $db->exec("UPDATE world_objects SET variant='stars' WHERE id='".$story['object_id']."'");check(LittleSparks\memorySource($db,$story)['status']==='contradicted','Current world mismatch is marked contradicted');$db->exec("UPDATE world_objects SET variant='oak' WHERE id='".$story['object_id']."'");
    putenv('LITTLE_SPARKS_MEMORY_SELECT=1');check(count(LittleSparks\recallMemories($db,$luna,$sparky))===1,'Configured selection bound');putenv('LITTLE_SPARKS_MEMORY_SELECT');
    putenv('LITTLE_SPARKS_MEMORY_HOPS=1');$short=LittleSparks\prepareMemoryEncounter($db,$actors,$choices);check(!array_filter($short['share'],fn($m)=>(int)$m['receipt']['hops']>=1),'Propagation ceiling enforced');putenv('LITTLE_SPARKS_MEMORY_HOPS');
    echo "PASS populated V4 migration/rollback, original identities/private links/ledgers, stable friendships, real tree→direct→secondhand→thirdhand, cycles, long-term relevance, rumor confidence, source validation and context bounds\n";
    // Keep a quiescent populated V5 template for actual opportunity/provider tests.
    $db=null;copy($base,$dir.'/memory-template.sqlite');$case=0;$fresh=function()use($base,$dir,&$case):PDO{$path=$dir.'/memory-case-'.(++$case).'.sqlite';copy($base,$path);return LittleSparks\connect($path);};
    putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_MODEL=gpt-6-luna');putenv('LITTLE_SPARKS_AI_PRICING_CONFIRMED='.LittleSparks\AI_PRICING_VERSION);
    $at=gmdate('Y-m-d\TH:i:00\Z',time()-60);
    $db=$fresh();$claim=LittleSparks\claimActivity($db,$at,time());$choices=LittleSparks\aiActionChoices($db,$claim['pair'][0]['id'],$claim['pair'][1]['id'],time());
    check(isset($choices['plant_tree']),'Rollback fixture has a permitted tree action');$oldData=originalRows($db);$beforeMem=memoryRows($db);
    try{LittleSparks\applyActivity($db,$claim,['outcome'=>'conversation','topic'=>'garden','first'=>'I feel curious.','second'=>'That feels lovely.','world_action'=>'plant_tree','shared_receipt'=>str_repeat('f',32)],$choices,time());throw new LogicException('Unsupported post-action memory accepted');}catch(LittleSparks\Problem){}
    check(originalRows($db)===$oldData&&memoryRows($db)===$beforeMem,'Late memory failure rolls back conversation, planted object, event and derived firsthand memory together');
    putenv('LITTLE_SPARKS_MEMORY_PER_RESIDENT=1');$oldMem=memoryRows($db);savedEncounter($db,$sparky,$luna,gmdate('Y-m-d\TH:i:s\Z'),'I think gardening feels peaceful.','That feels lovely.');
    foreach($oldMem['memory_receipts'] as $row)check(in_array($row,memoryRows($db)['memory_receipts'],true),'Lowered archive bound retains important old memories');putenv('LITTLE_SPARKS_MEMORY_PER_RESIDENT');
    foreach(['good','action','fake_source','false_claim','flagged','timeout','unknown'] as $mode){
        $db=$fresh();$beforeMem=memoryRows($db);$oldData=originalRows($db);$mock=new MemoryMock($mode);$result=LittleSparks\simulateOpportunity($db,$at,true,$mock);
        if(in_array($mode,['good','action'],true)){check($result['status']==='completed'&&count($mock->calls)===2,'V5 generation plus moderation, no extraction request');$again=LittleSparks\simulateOpportunity($db,$at,true,$mock);check($again['replayed']&&count($mock->calls)===2,'Opportunity remains idempotent');continue;}
        check($result['status']==='failed'&&memoryRows($db)===$beforeMem,'Rejected/failed proposal creates no memory for '.$mode);
        foreach(['relationships','conversations','messages','world_objects','memories','events'] as $t)check(originalRows($db)[$t]===$oldData[$t],'No partial effects for '.$mode);
    }
    foreach(['','-1','999999','1.5'] as $invalid){putenv('LITTLE_SPARKS_MEMORY_SELECT='.$invalid);$db=$fresh();$mock=new MemoryMock();try{LittleSparks\simulateOpportunity($db,$at,true,$mock);throw new LogicException('Invalid memory setting accepted');}catch(LittleSparks\Problem){}check(!$mock->calls,'Invalid settings block before dispatch');}putenv('LITTLE_SPARKS_MEMORY_SELECT');
    foreach([['LITTLE_SPARKS_AI_DAILY_REQUESTS','0','daily_request_cap'],['LITTLE_SPARKS_AI_MONTHLY_MICROUSD','0','monthly_budget_cap'],['LITTLE_SPARKS_AI_MONTHLY_MICROUSD','1000001','configuration_invalid']] as [$key,$value,$reason]){
        putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv($key.'='.$value);$db=$fresh();$mock=new MemoryMock();$result=LittleSparks\simulateOpportunity($db,$at,true,$mock);check(!$mock->calls&&$result['ai_blocked']===$reason,'Existing spending protection remains '.$reason);
    }
    echo "PASS V5 mocked provider, safe factual grammar, fake/failed/moderated claims, atomic rollback, idempotency, invalid settings and unchanged paid ceilings (zero live calls)\n";
    file_put_contents($dir.'/memory-ids.json',json_encode(['luna'=>$luna,'sparky'=>$sparky],JSON_THROW_ON_ERROR));
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
