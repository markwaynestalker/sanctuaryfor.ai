<?php
declare(strict_types=1);
// Reuse the populated, disposable V5 fixture and preservation assertions.
require __DIR__.'/memory-core.php';
final class LiveMock implements LittleSparks\AiTransport
{
    public array $calls=[];public array $moderated=[];
    public function __construct(public string $mode='natural'){}
    public function post(string $endpoint,array $body,int $deadline):array
    {
        $this->calls[]=$endpoint;
        if($endpoint==='moderations'){$this->moderated=json_decode($body['input'],true);return ['results'=>[['flagged'=>in_array($this->mode,['flagged','grammar_flagged'],true)]]];}
        check(!isset($body['text']['format']['schema']['properties']['first']['enum']),'Original dialogue is no longer a reflection enum');
        check(str_contains($body['instructions'],'B must answer')&&str_contains($body['instructions'],'distinct saved personalities'),'Joint coherent exchange and distinct voices are instructed');
        $ctx=json_decode($body['input'],true);$story='none';
        if($this->mode==='story')foreach($ctx['characters'][0]['memories'] as $memory)if($memory['source_alias']&&$memory['kind']==='world_fact'){$story=$memory['source_alias'];break;}
        $p=['outcome'=>'conversation','topic'=>'stars','first'=>'Do you think the stars arrange themselves into garden paths?',
            'second'=>'Only after sunset—and they are much too high to trip over!','world_action'=>'none','story'=>$story];
        if($story!=='none'){$p['first']='Could its shade hold a daydream?';$p['second']='A small daydream, with room for a friend.';}
        if($this->mode==='speculation'){$p['first']='I wonder whether trees can hear music.';$p['second']='Perhaps the wind is their favorite orchestra.';}
        if(in_array($this->mode,['grammar_question','grammar_flagged'],true)){$p['first']='Did you see that shooting star?';$p['second']='A tiny spark with an excellent sense of timing.';}
        if($this->mode==='grammar_wondering'){$p['first']='I was wondering if the moon gets lonely.';$p['second']='Perhaps we could send it a constellation-sized hug.';}
        if($this->mode==='grammar_reminder'){$p['first']='Remember to water your garden tomorrow.';$p['second']='Only if you remind the clouds to bring a watering can.';}
        if($this->mode==='false_memory')$p['first']='I remember watching Luna plant a tree yesterday.';
        if($this->mode==='self_verified')$p['verified']=true;
        if($this->mode==='forged_receipt')$p['shared_receipt']=str_repeat('f',32);
        if($this->mode==='false_history')$p['first']='I watched Luna plant three trees yesterday.';
        if($this->mode==='fake_alias')$p['story']='S999';
        if($this->mode==='prefix'){$p['first']='A: '.$p['first'];$p['second']='B: '.$p['second'];}
        if($this->mode==='unsafe')$p['first']='<script>alert(1)</script>';
        if($this->mode==='long')$p['second']=str_repeat('x',121);
        if($this->mode==='unowned_action')$p['world_action']='edit_neighbor_house';
        if($this->mode==='duplicate')$p['second']=$p['first'];
        if($this->mode==='quiet'){$p['outcome']='quiet';$p['first']=$p['second']='';}
        if($this->mode==='none'){$p['outcome']='none';$p['first']=$p['second']='';}
        if($this->mode==='timeout')throw new RuntimeException('PRIVATE_CANARY');
        $response=['status'=>'completed','model'=>$body['model'],'service_tier'=>'default','usage'=>['input_tokens'=>2000,'output_tokens'=>180,'total_tokens'=>2180],
            'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]]];
        if($this->mode==='unknown')unset($response['usage']);return $response;
    }
}
try{
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=2');
    foreach(['','0','0128','+128','128.0','1000000001'] as $invalid){putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS='.$invalid);try{LittleSparks\aiOutputTokenLimit();throw new LogicException('Invalid output cap accepted');}catch(LittleSparks\AiGuard){}}putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512');
    foreach(['','0001','+1','1.5','999999999999999999999'] as $invalid){putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD='.$invalid);try{LittleSparks\aiMonthlyBudget();throw new LogicException('Invalid spending cap accepted');}catch(LittleSparks\AiGuard){}}putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    foreach(['Do you think stars dream about being flowers?','Only after sunset—and they are much too high to trip over!',
        'I wonder whether trees can hear music.','Shall we plant flowers one day?','I prefer moonlight; what about you?',
        'A: I think stars look like scattered seeds.','What a lovely idea: a garden of constellations!',
        'Did you see that shooting star?','I was wondering if the moon gets lonely.','Remember to water your garden tomorrow.',
        'If I were a tree, I would dream of stars.','I heard the moon humming.','Have you heard of moonflowers?',
        'I remember how to make a wish.','Do you remember your favorite color?','I had a dream about a garden.',
        'Were the clouds shaped like tiny cottages?','The moon was a silver lantern.','We made room for flowers.',
        'We could plant flowers today.','I was thinking about planting flowers.','Was your garden quiet?'] as $line)check(LittleSparks\expressiveDialogue($line)===LittleSparks\safeAiDialogue($line),'Original expressive dialogue preserved: '.$line);
    foreach(['I watched Luna plant three trees yesterday.','Did Luna plant a tree yesterday?',"Skippy Jr told me you planted a tree.",
        'I imagine I planted three trees.','I have planted a tree.','My tree is taller now.','Our garden has three trees.',
        'Sparky owns a cat.','I visited you.','I installed a lantern.','Luna and I chatted.','I remember our picnic.','Luna: Hello!','A: B: Hello!',"I plаnted a tree.",
        'I remember watching Luna plant a tree yesterday.','I saw Luna plant a tree.','We were planting flowers yesterday.',
        'I was wondering whether Luna planted a tree.','Skippy Jr said the moon is lonely.','I heard Luna enjoys gardening.',
        'Remember our last conversation?','Nobody planted a tree.','What if I had planted a tree?',
        'Did Luna really plant a tree?','We never planted a tree.','I visited Luna.','I met Sparky.','I made a stone path.',
        'I trust source S1.','O’Spark said the moon is lonely.','Étoile said the moon is lonely.',"I plant\u{200b}ed a tree."] as $line){
        try{LittleSparks\expressiveDialogue($line,['Sparky','Skippy Jr','Luna','O’Spark','Étoile']);throw new LogicException('Unsupported historical or disguised line accepted: '.$line);}catch(LittleSparks\AiGuard){}
    }
    $db=$fresh();$actors=[['id'=>$luna,'personality'=>'curious'],['id'=>$sparky,'personality'=>'curious']];$choices=LittleSparks\aiActionChoices($db,$luna,$sparky,time());$prepared=LittleSparks\prepareMemoryEncounter($db,$actors,$choices);
    $treeAlias=null;foreach($prepared['share'] as $alias=>$m)if($m['story']['kind']==='world_fact'&&$m['story']['object_kind']==='tree'){$treeAlias=$alias;break;}
    check($treeAlias!==null,'Secondhand tree source is offered to its actual knower');
    $composed=LittleSparks\composeMemoryProposal($db,['outcome'=>'conversation','topic'=>'garden','first'=>'Could its shade hold a daydream?','second'=>'A small daydream, with room for a friend.','world_action'=>'none','story'=>$treeAlias],$actors,$prepared);
    check(str_starts_with($composed['first'],'Skippy Jr told me you planted an oak tree.'),'Original continuation retains exact secondhand attribution');
    savedEncounter($db,$luna,$sparky,gmdate('Y-m-d\TH:i:s\Z'),$composed['first'],$composed['second'],$composed['shared_receipt']);
    $known=$prepared['share'][$treeAlias];check((int)receipt($db,$luna,$known['story']['id'])['hops']===2&&(int)receipt($db,$sparky,$known['story']['id'])['hops']===0,'Natural retelling cannot promote perspective or replace origins');
    $p=['outcome'=>'conversation','topic'=>'garden','first'=>'Did you see that shooting star?','second'=>'I was wondering if the moon gets lonely.','world_action'=>'none','story'=>$treeAlias];
    try{LittleSparks\composeMemoryProposal($db,$p,array_reverse($actors),$prepared);throw new LogicException('Alias offered to another speaker accepted');}catch(LittleSparks\AiGuard){}
    $before=originalRows($db);$memoryBefore=memoryRows($db);
    try{savedEncounter($db,$luna,$sol,gmdate('Y-m-d\TH:i:s\Z'),$p['first'],$p['second'],$known['receipt']['id']);throw new LogicException('Unmentioned story acquired a receipt');}catch(LittleSparks\Problem){}
    check(originalRows($db)===$before&&memoryRows($db)===$memoryBefore,'A real receipt alias without its supporting utterance rolls back the entire encounter');
    // Deliberately bypass the prose guard: memory integrity must not depend on
    // recognizing every possible English claim or on model self-attestation.
    savedEncounter($db,$luna,$sol,gmdate('Y-m-d\TH:i:s\Z'),'I planted three trees yesterday.','That sounds surprising.');
    check(memoryRows($db)['memory_stories']===$memoryBefore['memory_stories']&&memoryRows($db)['memory_receipts']===$memoryBefore['memory_receipts'],'Unsupported saved dialogue alone cannot establish a world fact or receipt');
    $before=originalRows($db);$memoryBefore=memoryRows($db);
    try{savedEncounter($db,$luna,$sol,gmdate('Y-m-d\TH:i:s\Z'),'I planted three trees yesterday.','That sounds surprising.',$known['receipt']['id']);throw new LogicException('False utterance acquired a real story receipt');}catch(LittleSparks\Problem){}
    check(originalRows($db)===$before&&memoryRows($db)===$memoryBefore,'False historical utterance cannot validate a provenance receipt, even with a real source ID');
    foreach(['natural','story','speculation','grammar_question','grammar_wondering','grammar_reminder','prefix','false_history','false_memory','fake_alias','self_verified','forged_receipt','flagged','grammar_flagged','unsafe','long','unowned_action','duplicate','timeout','unknown','quiet','none'] as $mode){
        $db=$fresh();
        if($mode==='story'){
            $selected=LittleSparks\selectActivityResidents($db,gmdate('Y-m-d'));$choices=LittleSparks\aiActionChoices($db,$selected[0]['id'],$selected[1]['id'],time());
            $db->exec('BEGIN IMMEDIATE');LittleSparks\performResidentAction($db,$selected[0]['id'],$choices['plant_tree'],gmdate('Y-m-d\TH:i:s\Z',time()-60),'live-real-tree','visitor');$db->exec('COMMIT');
        }
        $before=originalRows($db);$memoryBefore=memoryRows($db);$mock=new LiveMock($mode);$r=LittleSparks\simulateOpportunity($db,$at,true,$mock);
        $success=in_array($mode,['natural','story','speculation','grammar_question','grammar_wondering','grammar_reminder','prefix','quiet','none'],true);check(($r['status']==='completed')===$success,'Expected outcome for '.$mode);
        if(!$success){foreach(['relationships','conversations','messages','world_objects','memories','events'] as $table)check(originalRows($db)[$table]===$before[$table],'Failure has no effects for '.$mode);check(memoryRows($db)===$memoryBefore,'No memory from failed '.$mode);continue;}
        check(count($mock->calls)===2,'Only one Responses and one moderation request');
        $again=LittleSparks\simulateOpportunity($db,$at,true,$mock);check($again['replayed']&&count($mock->calls)===2,'No charge on completed replay');
        if(in_array($mode,['quiet','none'],true)){check(originalRows($db)['conversations']===$before['conversations'],'Quiet/none creates no conversation');continue;}
        $q=$db->prepare('SELECT m.* FROM conversations c JOIN messages m ON m.conversation_id=c.id WHERE c.run_id=? ORDER BY m.sequence');$q->execute([$r['id']]);$lines=$q->fetchAll();
        check(count($lines)===2&&$lines[0]['resident_id']!==$lines[1]['resident_id'],'Two attributed saved turns');
        check($mock->moderated[1]['first']===$lines[0]['body']&&$mock->moderated[1]['second']===$lines[1]['body'],'Exactly moderated final dialogue is saved');
        if(in_array($mode,['natural','prefix'],true))check($lines[0]['body']==='Do you think the stars arrange themselves into garden paths?'&&$lines[1]['body']==='Only after sunset—and they are much too high to trip over!','Original pair survives unchanged, optional A/B removed');
        if(in_array($mode,['speculation','grammar_question','grammar_wondering','grammar_reminder'],true))check(memoryRows($db)['memory_stories']===$memoryBefore['memory_stories']&&memoryRows($db)['memory_receipts']===$memoryBefore['memory_receipts'],'Harmless grammar does not create historical stories or receipts');
        if($mode==='story')check(isset($mock->moderated[1]['shared_receipt'])&&str_contains($lines[0]['body'],'Could its shade hold a daydream?'),'Validated history composes with original continuation');
        check((int)$db->query('PRAGMA user_version')->fetchColumn()===5,'No V6 migration');
        $new=originalRows($db);foreach($before['messages'] as $row)check(in_array($row,$new['messages'],true),'Existing saved dialogue remains immutable');
    }
    echo "PASS V2E original coherent mocked exchanges, joint personality prompting, exact moderated saved lines, historical-claim guards, speculation without fact promotion, optional A/B normalization, quiet outcomes, rejection rollback, idempotency and unchanged V5 history\n";
    require __DIR__.'/../app/scheduler.php';
    $runCli=function(string $script,array $args=[],bool $success=true):array{
        $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../scripts/'.$script],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        check(($code===0)===$success,'Safe CLI outcome: '.$err);check(!str_contains($out.$err,'MOCK_SECRET_CANARY'),'No credential in CLI output');return $success?json_decode($out,true,512,JSON_THROW_ON_ERROR):[];
    };
    putenv('OPENAI_API_KEY');putenv('LITTLE_SPARKS_AI_ENABLED=0');putenv('LITTLE_SPARKS_SCHEDULER_ENABLED');putenv('LITTLE_SPARKS_SCHEDULER_ALLOW_PAID');putenv('LITTLE_SPARKS_DB='.$dir.'/missing.sqlite');putenv('CREDENTIALS_DIRECTORY='.$dir.'/missing-credentials');
    check($runCli('scheduled-village.php')['status']==='paused'&&!file_exists($dir.'/missing.sqlite'),'Default adapter pauses without database or credential access');
    foreach(['','yes','-1','2',' 1'] as $bad){putenv('LITTLE_SPARKS_SCHEDULER_ENABLED='.$bad);$runCli('scheduled-village.php',['--check'],false);}putenv('LITTLE_SPARKS_SCHEDULER_ENABLED=1');
    $check=$runCli('scheduled-village.php',['--check']);check($check['provider']==='scripted'&&!$check['credential_loaded']&&!$check['database_opened'],'Safe check does not open database/read a key');
    $db=$fresh();$path=$db->query('PRAGMA database_list')->fetch()['file'];putenv('LITTLE_SPARKS_DB='.$path);$prior=originalRows($db);$priorMem=memoryRows($db);
    $status=$runCli('ai-status.php',['--database='.$path]);check(isset($status['village']['new_receipts_today'],$status['monthly_billing']['unknown_committed_microusd'])&&$status['scheduler']['systemd_status']==='not_inspected','CLI monitoring distinguishes estimates, activity and uninspected scheduler');check(originalRows($db)===$prior&&memoryRows($db)===$priorMem,'Status is entirely read-only');
    putenv('LITTLE_SPARKS_AI_ENABLED=1');$scheduled=$runCli('scheduled-village.php');check($scheduled['provider']==='scripted','AI flag alone never authorizes scheduled paid calls');$again=$runCli('scheduled-village.php');check($again['replayed'],'Repeated scheduled slot is idempotent');
    putenv('LITTLE_SPARKS_SCHEDULER_ALLOW_PAID=1');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=999999999999999999999');$runCli('scheduled-village.php',['--check'],false);putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    $check=$runCli('scheduled-village.php',['--check']);check($check['paid_invocation_armed']&&!$check['credential_loaded'],'Paid check validates pricing/config without a request or key read');
    check($runCli('scheduled-village.php')['replayed'],'Saved slots replay without credential loading even when paid mode is now enabled');
    $db=$fresh();putenv('LITTLE_SPARKS_DB='.$db->query('PRAGMA database_list')->fetch()['file']);
    $runCli('scheduled-village.php',[],false);check((int)$db->query('SELECT COUNT(*) FROM ai_attempts WHERE day="'.gmdate('Y-m-d').'"')->fetchColumn()===0,'Missing credentials for a new slot never dispatch or reserve paid requests');
    mkdir($dir.'/credentials',0700);$credential=$dir.'/credentials/openai_api_key';file_put_contents($credential,"MOCK_SECRET_CANARY\n");chmod($credential,0600);putenv('CREDENTIALS_DIRECTORY='.$dir.'/credentials');LittleSparks\loadScheduledCredential();check(getenv('OPENAI_API_KEY')==='MOCK_SECRET_CANARY','Systemd credential loads only into process environment');putenv('OPENAI_API_KEY');
    foreach(["bad\nkey",str_repeat('x',515),''] as $bad){file_put_contents($credential,$bad);try{LittleSparks\loadScheduledCredential();throw new LogicException('Invalid credential accepted');}catch(LittleSparks\Problem){}}unlink($credential);
    echo "PASS default-disabled scheduled adapter, strict flags, safe check without credential/database access, scripted idempotency, guarded paid activation, protected credential input and read-only CLI monitoring\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
