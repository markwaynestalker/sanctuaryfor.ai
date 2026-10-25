<?php
declare(strict_types=1);
// Proposal text only, extracted from the owner-supplied 23:00 trace. No prompts,
// owner descriptions, credentials, private memory or raw trace are in this fixture.
function recordedItemPage(array $proposal,string $turn,string $mode='mixed'):array
{
    $raw=json_encode($proposal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $final=['id'=>'item_final','type'=>'message','role'=>'assistant','phase'=>'final_answer','status'=>'completed','turn_id'=>$turn,'content'=>[['type'=>'output_text','text'=>$raw]]];
    if($mode==='null_phase')$final['phase']=null;
    if($mode==='envelope')$final['status']='incomplete';
    if($mode==='multipart')$final['content']=[['type'=>'output_text','text'=>substr($raw,0,80)],['type'=>'output_text','text'=>substr($raw,80)]];
    if($mode==='json')$final['content'][0]['text']='{invalid JSON';
    if($mode==='wrong_id'){$proposal['messages'][0]['resident_id']=$proposal['messages'][1]['resident_id'];$final['content'][0]['text']=json_encode($proposal,JSON_THROW_ON_ERROR);}
    $input=['id'=>'item_input','type'=>'message','role'=>'user','phase'=>null,'status'=>'completed','turn_id'=>$turn,'content'=>[['type'=>'input_text','text'=>'INPUT_CANARY: not an answer, even if JSON-looking']]];
    $commentary=['id'=>'item_commentary','type'=>'message','role'=>'assistant','phase'=>'commentary','status'=>'completed','turn_id'=>$turn,'content'=>[['type'=>'output_text','text'=>'COMMENTARY_CANARY: not resident speech']]];
    $reasoning=['id'=>'item_reasoning','type'=>'reasoning','turn_id'=>$turn,'status'=>'completed','summary'=>[]];
    return ['object'=>'list','has_more'=>false,'first_id'=>'item_input','last_id'=>'item_final','data'=>[$input,$reasoning,$commentary,$final]];
}
final class RecordedItemTransport implements LittleSparks\DirectorTransport
{
    public StagingMock $inner;public array $moderations=[];private int $turnReads=0;
    public function __construct(public array $proposal,public string $mode){$this->inner=new StagingMock(in_array($mode,['flagged','usage_flagged'],true)?'flagged':'good');}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        $r=$this->inner->request($method,$path,$body,$deadline,$key);
        if(str_contains($path,'/turns?')){
            $this->turnReads++;
            if(in_array($this->mode,['usage_missing','usage_flagged','usage_wrong_id'],true)
                ||($this->mode==='usage_initial_missing'&&$this->turnReads===1)||($this->mode==='usage_final_missing'&&$this->turnReads>1))unset($r['data'][0]['usage']);
            if($this->mode==='usage_null')$r['data'][0]['usage']=null;
            if($this->mode==='usage_invalid')$r['data'][0]['usage']=['input_tokens'=>'invalid'];
        }
        if($path==='moderations')$this->moderations[]=$body;
        if(preg_match('~/turns/([^/]+)/items\?~',$path,$m))return recordedItemPage($this->proposal,$m[1],$this->mode==='usage_wrong_id'?'wrong_id':$this->mode);
        return $r;
    }
}
try{
    $recorded=json_decode(file_get_contents(__DIR__.'/fixtures/director-max-princess.json'),true,12,JSON_THROW_ON_ERROR);
    $pair=[['id'=>$recorded['messages'][0]['resident_id'],'name'=>'Max'],['id'=>$recorded['messages'][1]['resident_id'],'name'=>'Princess']];
    foreach($recorded['messages'] as $m)check(preg_match_all('/./us',$m['text'])===156,'Original messages are 156 Unicode characters, within the unchanged limit');
    $parsed=LittleSparks\parseDirectorProposal($recorded,$pair,['none'=>null]);check($parsed['messages']===$recorded['messages'],'Exact recorded proposal passes unchanged content validation');
    $turn='turn_04d458e7e90267d6006ade89f8a47c819784ede054828aa7c2';
    foreach(['mixed','null_phase','multipart'] as $mode){$stage=null;check(LittleSparks\stagingItems(recordedItemPage($recorded,$turn,$mode),$turn,$stage)===$recorded,'Realistic item envelopes preserve exact proposal: '.$mode);}
    $page=recordedItemPage($recorded,$turn);$bad=[];
    $r=$page;$r['has_more']=true;$bad[]=$r;
    $r=$page;$r['data'][0]['turn_id']='wrong_turn';$bad[]=$r;
    $r=$page;$r['data'][1]['turn_id']='wrong_turn';$bad[]=$r;
    $r=$page;$r['data'][3]['turn_id']='wrong_turn';$bad[]=$r;
    $r=$page;$r['data'][3]['status']='in_progress';$bad[]=$r;
    $r=$page;unset($r['data'][3]['phase']);$bad[]=$r;
    $r=$page;$r['data'][3]['phase']='unknown';$bad[]=$r;
    $r=$page;$r['data'][3]['role']='system';$bad[]=$r;
    $r=$page;$r['data'][]=$r['data'][3];$bad[]=$r;
    $r=$page;array_pop($r['data']);$r['data'][0]['content']=[['type'=>'input_text','text'=>json_encode($recorded)]];$r['data'][2]['content']=[['type'=>'output_text','text'=>json_encode($recorded)]];$bad[]=$r;
    $r=$page;$r['data'][3]['content']=[['type'=>'refusal','refusal'=>'no']];$bad[]=$r;
    $r=$page;$r['data'][3]['content'][0]['text']=str_repeat('x',4097);$bad[]=$r;
    $r=$page;$r['data'][]=['type'=>'function_call','turn_id'=>$turn];$bad[]=$r;
    foreach($bad as $r){$stage=null;try{LittleSparks\stagingItems($r,$turn,$stage);throw new LogicException('Unsafe envelope accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='invalid_proposal'&&$stage==='item_envelope','Envelope failures stay distinct from content validation');}}
    // Disposable V6 village with the exact speaker IDs, synthetic identities and no production data.
    putenv('LITTLE_SPARKS_AI_ENABLED=0');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=0');$itemsBase=$dir.'/recorded-items.sqlite';
    foreach(['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-director.php'] as $script)cli($script,$itemsBase);
    $d=LittleSparks\connect($itemsBase);$d->beginTransaction();$time=gmdate('Y-m-d\TH:i:s\Z',time()-300);
    foreach($pair as $i=>$actor){
        $d->prepare("INSERT INTO residents(id,name,color,antenna,personality,created_at,owner_hash,request_id,request_hash,token_hash) VALUES (?,?,'mint','orb','curious',?,?,?,?,?)")
            ->execute([$actor['id'],$actor['name'],$time,hash('sha256','fixture-owner-'.$i),str_repeat((string)($i+1),32),str_repeat('a',64),hash('sha256','fixture-token-'.$i)]);
        LittleSparks\assignHome($d,$actor['id'],$time);LittleSparks\identityForNewResident($d,$actor['id'],'Offline synthetic fixture personality.','mint',$time);
    }$d->commit();LittleSparks\registerDirector($d,$export);$d=null;
    putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    foreach(['mixed','null_phase','multipart','envelope','json','wrong_id','flagged','usage_missing','usage_null','usage_initial_missing','usage_final_missing','usage_flagged','usage_wrong_id','usage_invalid'] as $mode){
        $path=$dir.'/recorded-items-'.$mode.'.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);$mock=new RecordedItemTransport($recorded,$mode);
        $r=LittleSparks\simulateDirectorOpportunity($d,$at,true,null,$mock,null,null,false,null,true);
        $missing=in_array($mode,['usage_missing','usage_null','usage_initial_missing','usage_final_missing'],true);
        $success=in_array($mode,['mixed','null_phase','multipart'],true)||$missing;check(($r['status']==='completed')===$success,'Full mocked managed pipeline: '.$mode);
        $lines=$d->query('SELECT body FROM messages ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN);
        check($lines===($success?array_column($recorded['messages'],'text'):[]),'Only original moderated dialogue reaches SQLite: '.$mode);
        if($success){check(count($mock->moderations)===1,'Exactly one moderation before commit');$input=json_decode($mock->moderations[0]['input'],true);check($input[1]['messages']===$recorded['messages'],'Moderation sees the original words and ordered IDs');}
        if($missing){
            $a=$d->query('SELECT * FROM ai_attempts')->fetch();$da=$d->query('SELECT * FROM director_attempts')->fetch();
            check($a['state']==='uncertain'&&$da['state']==='uncertain'&&$a['error_code']==='usage_unknown','Successful world commit retains uncertain accounting');
            check($a['input_tokens']===null&&$a['output_tokens']===null&&$a['estimated_microusd']===null&&$da['estimated_microusd']===null,'Missing usage never becomes zero tokens or a settled estimate');
            $unknown=$d->query("SELECT * FROM director_usage_observations WHERE status='unknown'")->fetchAll();check(count($unknown)>=1,'Missing metadata remains durably unknown');
            foreach($unknown as $u)check($u['input_tokens']===null&&$u['output_tokens']===null&&$u['estimated_microusd']===null,'Unknown observation retains null usage/cost');
            check((int)$da['request_slots']===8&&(int)$da['started_requests']===6&&LittleSparks\aiCommittedTotal($d,gmdate('Y-m'))===(int)$a['reserved_microusd'],'All eight reserved HTTP slots and full cost commitment retained');
            check((int)$d->query("SELECT COUNT(*) FROM conversations c JOIN ai_conversations a ON a.conversation_id=c.id JOIN events e ON e.run_id=c.run_id AND e.kind='conversation' WHERE c.visibility='public'")->fetchColumn()===1,'Actual completed saved conversation is available to the journal');
        }
        if(in_array($mode,['envelope','json','wrong_id'],true)){$q=$d->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['director_proposal_rejection:'.$r['id']]);$receipt=json_decode($q->fetchColumn(),true);check($receipt===['stage'=>match($mode){'envelope'=>'item_envelope','json'=>'proposal_json','wrong_id'=>'proposal_content'}],'Safe failure receipt distinguishes envelope, JSON and proposal-content rejection');check(!$mock->moderations,'Invalid envelope/JSON/identity never reaches moderation or apply');}
        $n=count($mock->inner->calls);LittleSparks\simulateDirectorOpportunity($d,$at,true,null,$mock,null,null,false,null,true);check(count($mock->inner->calls)===$n,'No regeneration of a completed or failed opportunity');
    }
    echo "PASS exact Max/Princess trace proposal (156/156), mixed managed input/commentary/final items, nullable phase/multipart text, strict envelope/identity/JSON failures, moderation, byte-exact SQLite save and no replay (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
