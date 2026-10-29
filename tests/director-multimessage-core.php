<?php
declare(strict_types=1);
require_once __DIR__.'/../app/conversation_messages_schema.php';
require_once __DIR__.'/../app/conversation_archive.php';
final class MultiMessageMock implements LittleSparks\DirectorTransport
{
    public StagingMock $inner;public array $proposal=[];public array $moderated=[];public array $context=[];
    public function __construct(public int $length,public string $mode='good'){$this->inner=new StagingMock($mode==='flagged'?'flagged':'good');}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        if($path==='agents/sessions')$this->context=json_decode($body['input'][0]['content'][0]['text'],true);
        if(($body['events'][0]['type']??null)==='agent.session.input.message')$this->context=json_decode($body['events'][0]['input'][0]['content'][0]['text'],true);
        $r=$this->inner->request($method,$path,$body,$deadline,$key);
        if($path==='moderations')$this->moderated[]=json_decode($body['input'],true);
        if(preg_match('~/turns/([^/]+)/items\?~',$path,$match)){
            $actors=$this->context['participants'];$messages=[];
            for($i=0;$i<$this->length;$i++)$messages[]=['resident_id'=>$actors[$i%2]['id'],'text'=>'Turn '.($i+1).': '.($i%2?'I disagree, but I am listening.':'Could we consider a different approach?')];
            if($this->mode==='late_wrong')$messages[count($messages)-1]['resident_id']=$actors[$this->length%2]['id'];
            $this->proposal=['contract'=>'sanctuary.director.v2','outcome'=>'conversation','topic'=>'A practical disagreement','messages'=>$messages,'world_action'=>'none','transmissions'=>[],
                'notes'=>[['resident_id'=>$actors[0]['id'],'sequence'=>$this->length-1,'quote'=>$messages[$this->length-1]['text'],'classification'=>'interpretation']]];
            return recordedItemPage($this->proposal,$match[1],$this->mode==='incomplete'?'envelope':'mixed');
        }
        return $r;
    }
}
final class MultiMessageResponses implements LittleSparks\AiTransport
{
    public array $calls=[];public array $proposal=[];
    public function post(string $endpoint,array $body,int $deadline):array{
        $this->calls[]=[$endpoint,$body];if($endpoint==='moderations')return ['results'=>[['flagged'=>false]]];
        $context=json_decode($body['input'],true);$messages=[];for($i=0;$i<16;$i++)$messages[]=['resident_id'=>$context['participants'][$i%2]['id'],'text'=>'Unicode '.str_repeat('🌻',140).($i+1)];
        $this->proposal=['contract'=>'sanctuary.director.v2','outcome'=>'conversation','topic'=>'A synthetic disagreement','messages'=>$messages,'world_action'=>'none','transmissions'=>[],'notes'=>[]];
        return ['id'=>'resp_synthetic','status'=>'completed','model'=>'gpt-6-luna','service_tier'=>'default','usage'=>['input_tokens'=>2200,'output_tokens'=>3000,'total_tokens'=>5200],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($this->proposal,JSON_UNESCAPED_UNICODE)]]]]];
    }
}
$multiVariables=['LITTLE_SPARKS_CONVERSATION_MIN_MESSAGES','LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES','LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS','LITTLE_SPARKS_AI_DAILY_REQUESTS','LITTLE_SPARKS_AI_MONTHLY_MICROUSD','LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256','LITTLE_SPARKS_DIRECTOR_AGENT_ID','LITTLE_SPARKS_DIRECTOR_RUNTIME','LITTLE_SPARKS_AI_ENABLED','LITTLE_SPARKS_SIMULATION_ENABLED','LITTLE_SPARKS_DIRECTOR_ENABLED'];
$multiPrevious=[];foreach($multiVariables as $name)$multiPrevious[$name]=getenv($name);
try{
    foreach(['MIN','MAX'] as $setting){
        $name='LITTLE_SPARKS_CONVERSATION_'.$setting.'_MESSAGES';
        foreach(['','0','1','-1','02','2.5','65'] as $invalid){putenv($name.'='.$invalid);try{LittleSparks\conversationConfiguration();throw new LogicException('Invalid message configuration accepted');}catch(LittleSparks\Problem|LittleSparks\AiGuard){}}
        putenv($name);
    }
    putenv('LITTLE_SPARKS_CONVERSATION_MIN_MESSAGES=8');putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES=4');
    try{LittleSparks\conversationConfiguration();throw new LogicException('Inverted message range accepted');}catch(LittleSparks\AiGuard){}
    putenv('LITTLE_SPARKS_CONVERSATION_MIN_MESSAGES');putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES');
    putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=14000');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=1000');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=25000000');
    putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');
    foreach([['sanctuary.director.v1','sanctuary.director.v2'],['sanctuary.director.v2','sanctuary.director.v1'],['sanctuary.director.bulletin.v2','sanctuary.director.v2']] as [$actual,$expected]){
        try{LittleSparks\assertDirectorOutputContract($actual,$expected);throw new LogicException('Unrequested output contract accepted');}catch(LittleSparks\AiGuard){}
    }
    $proposal=$recorded;$proposal['contract']='sanctuary.director.v2';$proposal['notes']=[];
    $baseMessages=$proposal['messages'];
    foreach([2,3,16,64] as $length){
        putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES='.$length);$proposal['messages']=[];
        for($i=0;$i<$length;$i++)$proposal['messages'][]=['resident_id'=>$pair[$i%2]['id'],'text'=>'Original Unicode '.str_repeat('🌻',140).($i+1)];
        check(LittleSparks\parseDirectorProposal($proposal,$pair,['none'=>null])['messages']===$proposal['messages'],'Configured ceiling preserves Unicode exactly');
        $response=['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($proposal,JSON_UNESCAPED_UNICODE)]]]]];
        check(LittleSparks\decodedAiProposal($response,$length*768+4096)===$proposal,'Responses decoder supports complete large Unicode exchange');
        $response['output'][0]['content'][0]['text']=json_encode($proposal);
        check(LittleSparks\decodedAiProposal($response,LittleSparks\directorProposalByteLimit($proposal['contract']))===$proposal,'Escaped astral Unicode remains complete within the byte bound');
        $items=recordedItemPage($proposal,'turn_unicode');$items['data'][3]['content'][0]['text']=json_encode($proposal);$stage=null;
        check(LittleSparks\stagingItems($items,'turn_unicode',$stage,LittleSparks\directorProposalByteLimit($proposal['contract']))===$proposal,'Managed decoder accepts the same escaped Unicode envelope');
        try{LittleSparks\decodedAiProposal($response,10);throw new LogicException('Oversized envelope accepted');}catch(LittleSparks\AiGuard){}
    }
    putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES');$proposal['messages']=$baseMessages;
    foreach(['quiet','none','action'] as $outcome){$p=$proposal;$p['outcome']=$outcome;$p['messages']=[];$p['world_action']=$outcome==='action'?'plant_tree':'none';check(LittleSparks\parseDirectorProposal($p,$pair,['none'=>null,'plant_tree'=>null])['messages']===[],'V2 preserves silent outcomes and independently validated actions');}
    $p=$proposal;$p['contract']='sanctuary.director.bulletin.v2';$p['notice']=null;
    check(LittleSparks\parseDirectorProposal($p,$pair,['none'=>null,'post_notice'=>null])['messages']===$baseMessages,'Bulletin v2 retains ordinary conversations');
    foreach(['under','over','outsider','nonalternating','note_sequence','note_quote','long'] as $mode){
        $p=$proposal;
        if($mode==='under')array_pop($p['messages']);
        if($mode==='over')$p['messages']=array_merge(...array_fill(0,9,$baseMessages));
        if($mode==='outsider')$p['messages'][1]['resident_id']=str_repeat('f',32);
        if($mode==='nonalternating')$p['messages'][1]['resident_id']=$pair[0]['id'];
        if($mode==='note_sequence')$p['notes']=[['resident_id'=>$pair[0]['id'],'sequence'=>2,'quote'=>'Missing','classification'=>'rumor']];
        if($mode==='note_quote')$p['notes']=[['resident_id'=>$pair[0]['id'],'sequence'=>1,'quote'=>'Not original speech','classification'=>'rumor']];
        if($mode==='long')$p['messages'][1]['text']=str_repeat('x',161);
        try{LittleSparks\parseDirectorProposal($p,$pair,['none'=>null]);throw new LogicException('Invalid proposal accepted: '.$mode);}catch(LittleSparks\AiGuard){}
    }
    putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=512');try{LittleSparks\directorSchema(['none'=>null],true);throw new LogicException('Insufficient output budget accepted');}catch(LittleSparks\AiGuard){}putenv('LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS=14000');
    foreach([2,3,8,16] as $length){
        $path=$dir.'/multi-'.$length.'.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);
        $old=[];foreach($d->query("SELECT name,sql FROM sqlite_master WHERE type='table'")->fetchAll() as $table)$old[$table['name']]=[$table['sql'],$d->query('SELECT * FROM "'.$table['name'].'" ORDER BY rowid')->fetchAll()];
        $d->beginTransaction();LittleSparks\migrateConversationMessages($d);$d->rollBack();check(!LittleSparks\multiMessageInstalled($d),'Rollback removes additive capability');
        $d->beginTransaction();LittleSparks\migrateConversationMessages($d);$d->commit();
        foreach($old as $name=>[$sql,$rows]){check($d->query("SELECT sql FROM sqlite_master WHERE name=".$d->quote($name))->fetchColumn()===$sql,'Migration preserves old table definitions');check($d->query('SELECT * FROM "'.$name.'" ORDER BY rowid')->fetchAll()===$rows,'Migration preserves every old row');}
        check((int)$d->query('PRAGMA user_version')->fetchColumn()===6&&!$d->query('PRAGMA foreign_key_check')->fetch(),'Add-on retains V6 and valid foreign keys');
        $registered=$d->query('SELECT fingerprint,agent_id FROM director_registry LIMIT 1')->fetch();putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registered['fingerprint']);putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$registered['agent_id']);
        $op=gmdate('Y-m-d\TH:i:00\Z',time()-120);$mock=new MultiMessageMock($length);
        $r=LittleSparks\simulateDirectorOpportunity($d,$op,true,null,$mock,null,null,false,null,true);
        check($r['status']==='completed','Multi-message generation saves '.$length.' messages: '.json_encode($r));
        $id=$d->query('SELECT id FROM conversations ORDER BY rowid DESC LIMIT 1')->fetchColumn();$actors=$mock->context['participants'];$ids=array_column($actors,'id');
        $saved=LittleSparks\conversationMessageBatch($d,[$id])[$id];check(array_column($saved,'text')===array_column($mock->proposal['messages'],'text'),'Every original message survives exactly');
        check(array_map('intval',array_column($saved,'sequence'))===range(0,$length-1),'All message sequences are contiguous');
        check(count($mock->moderated)===1&&$mock->moderated[0][1]['messages']===$mock->proposal['messages'],'Moderation covers the complete exchange');
        $relationship=$d->query('SELECT affinity,familiarity FROM relationships')->fetch();check((int)$relationship['affinity']===2&&(int)$relationship['familiarity']===1,'One relationship increment per encounter');
        check((int)$d->query('SELECT interaction_count FROM simulation_runs ORDER BY rowid DESC LIMIT 1')->fetchColumn()===1,'One simulation encounter regardless of message count');
        check((int)$d->query('SELECT sequence FROM extended_director_notes')->fetchColumn()===$length-1,'Later-message note references its actual sequence');
        foreach(["UPDATE extended_messages SET body='changed'","DELETE FROM extended_messages","UPDATE extended_conversations SET message_count=2","DELETE FROM extended_director_notes"] as $sql){try{$d->exec($sql);throw new LogicException('Extended history mutation accepted');}catch(PDOException){}}
        check(count(LittleSparks\commons($d,$ids)['conversations'][0]['messages'])===$length,'Commons returns complete transcript');
        check(count(LittleSparks\conversationArchive($d,[])['conversations'][0]['messages'])===$length,'Archive returns complete transcript');
        check(LittleSparks\conversationArchive($d,['q'=>'Turn '.$length.':'])['pagination']['total']===1,'Search finds the last message');
        check(count(LittleSparks\profile($d,$ids[0])['conversations'][0]['messages'])===$length,'Profile returns complete transcript');
        $journal=LittleSparks\journal($d);check(count($journal['events'][0]['conversations'][0]['messages'])===$length,'Journal returns complete transcript');
        $count=count($mock->inner->calls);LittleSparks\simulateDirectorOpportunity($d,$op,true,null,$mock,null,null,false,null,true);check(count($mock->inner->calls)===$count,'Replay creates no messages or requests');
        $context=LittleSparks\prepareDirectorEncounter($d,$actors,['none'=>null],['id'=>'next','created_at'=>gmdate('c')],LittleSparks\directorConfiguration())['context'];
        check(count($context['shared_recent_conversations'][0]['messages'])===$length,'Recall returns all messages, not an opening fragment');
        $session=LittleSparks\directorSession($d,$registered,$actors,'agents',time(),'sanctuary.director.v2');
        check(LittleSparks\directorSession($d,$registered,array_reverse($actors),'agents',time(),'sanctuary.director.v2')['id']===$session['id'],'Reversed pair reuses v2 scope');
        putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES=8');check(LittleSparks\directorSession($d,$registered,$actors,'agents',time(),'sanctuary.director.v2')['id']!==$session['id'],'Changed message bounds select new session scope');putenv('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES');
        if($length===3){
            $second=new MultiMessageMock(3);$r=LittleSparks\simulateDirectorOpportunity($d,gmdate('Y-m-d\TH:i:00\Z',time()-60),true,null,$second,null,null,false,null,true);
            check($r['status']==='completed','Second v2 encounter succeeds');
            check(!array_filter($second->inner->calls,fn($c)=>$c[0]==='POST'&&$c[1]==='agents/sessions'),'V2 reuses the actual hosted session with one fresh encounter submission');
            check($second->context['shared_recent_conversations'][0]['messages']===$mock->proposal['messages'],'Reused v2 input contains complete preceding dialogue');
        }
    }
    $path=$dir.'/multi-responses.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);$d->beginTransaction();LittleSparks\migrateConversationMessages($d);$d->commit();
    $configuration=json_decode($d->query('SELECT configuration FROM director_registry LIMIT 1')->fetchColumn(),true);$configuration['instructions']='Synthetic offline Director instructions.';
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');$registered=LittleSparks\registerDirector($d,$configuration);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registered['fingerprint']);
    $responseMock=new MultiMessageResponses();$r=LittleSparks\simulateDirectorOpportunity($d,gmdate('Y-m-d\TH:i:00\Z',time()-120),true,$responseMock,null,null,null,true);
    check($r['status']==='completed'&&count($responseMock->calls)===2,'Responses saves large Unicode exchange with one generation and one moderation: '.json_encode($r));
    check($responseMock->calls[0][1]['max_output_tokens']===14000&&$responseMock->calls[0][1]['text']['format']['schema']['properties']['messages']['maxItems']===16,'Responses uses reviewed hard output allowance and v2 schema');
    $conversation=$d->query('SELECT id FROM conversations')->fetchColumn();
    check(array_column(LittleSparks\conversationMessageBatch($d,[$conversation])[$conversation],'text')===array_column($responseMock->proposal['messages'],'text'),'Responses keeps all Unicode words');
    $d->beginTransaction();$run=$d->query('SELECT run_id FROM conversations LIMIT 1')->fetchColumn();$actors=$d->query('SELECT * FROM residents ORDER BY id')->fetchAll();
    $d->prepare("INSERT INTO conversations VALUES ('partial-transcript',?,'welcome','public',?)")->execute([$run,gmdate('Y-m-d\TH:i:s\Z')]);
    foreach($actors as $actor)$d->prepare("INSERT INTO conversation_participants VALUES ('partial-transcript',?)")->execute([$actor['id']]);
    $d->exec("INSERT INTO extended_conversations VALUES ('partial-transcript',3)");
    foreach([0,1] as $i)$d->prepare("INSERT INTO extended_messages VALUES ('partial-transcript',?,?,?)")->execute([$i,$actors[$i]['id'],'Incomplete synthetic turn '.$i]);
    check(LittleSparks\conversationMessageBatch($d,['partial-transcript'])===[],'Public projection never exposes a partial extended transcript');$d->rollBack();
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');
    foreach(['flagged','late_wrong','incomplete'] as $mode){
        $path=$dir.'/multi-'.$mode.'.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);$d->beginTransaction();LittleSparks\migrateConversationMessages($d);$d->commit();
        $registered=$d->query('SELECT fingerprint,agent_id FROM director_registry LIMIT 1')->fetch();putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registered['fingerprint']);putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$registered['agent_id']);
        $mock=new MultiMessageMock(16,$mode);$r=LittleSparks\simulateDirectorOpportunity($d,gmdate('Y-m-d\TH:i:00\Z',time()-60),true,null,$mock,null,null,false,null,true);
        check($r['status']==='failed'&&(int)$d->query('SELECT count(*) FROM extended_messages')->fetchColumn()===0,'Late rejection leaves no partial exchange: '.$mode);
    }
    echo "PASS additive multi-message migration/rollback, 2/3/8/16 exact ordered turns, last-message search/notes/recall, full moderation, one-encounter accounting, session-bound settings, replay and late-failure rollback (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
finally{foreach($multiPrevious as $name=>$value)putenv($value===false?$name:$name.'='.$value);}
