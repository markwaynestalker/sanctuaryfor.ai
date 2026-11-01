<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_job.php';require __DIR__.'/../app/gazette_editor_publication.php';
require_once __DIR__.'/gazette-contract-fixtures.php';
function integrationCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function integrationReject(callable $f,string $why):void{try{$f();}catch(RuntimeException){return;}throw new LogicException($why);}
final class EditorialMock implements LittleSparks\EditorialTransport
{
    public array $calls=[];
    public function __construct(public string $mode='good',public ?PDO $db=null){}
    public function post(string $path,array $body,int $deadline):array
    {
        $this->calls[]=$path;if($this->mode==='timeout')throw new RuntimeException('Synthetic failure.');
        if($path==='moderations')return ['body'=>['results'=>[['flagged'=>$this->mode==='flagged']]],'request_id'=>'req_moderation_fixture'];
        integrationCheck($body['store']===false&&$body['service_tier']==='default'&&!isset($body['tools'])&&!isset($body['previous_response_id']),'Stateless tool-free request');
        $snapshot=json_decode($body['input'][0]['content'][0]['text'],true);$pack=$snapshot['pack'];$sources=array_column($pack['sources'],null,'id');$s=$sources['integrity-good'];$ref=['source'=>$s['alias'],'sequence'=>0];
        $claim=['text'=>'A lost-and-found remains an idea','claim_type'=>'proposal','references'=>[$ref]];
        $p=$claim;$p['text']='A saved conversation proposed a village lost-and-found. The suggestion remains a possibility, rather than evidence that a building opened.';
        $q=$claim;$q['text']='The reply kept the distinction clear: discussing an idea does not establish that anyone carried it out.';$q['references']=[['source'=>$s['alias'],'sequence'=>1]];
        $draft=['contract'=>'sanctuary.gazette.draft.v1','date'=>$pack['date'],'source_sha256'=>$snapshot['sha256'],'stories'=>[['kind'=>'lead','connection'=>'standalone','headline'=>$claim,'paragraphs'=>[$p,$q],'quotes'=>[$ref]]]];
        $bg=array_values(array_filter($pack['sources'],fn($s)=>$s['scope']==='background'));
        if($bg){$p=$claim;$p['text']='Earlier saved exchanges also discussed village games. That is background from another day, not evidence of a shared event.';$p['claim_type']='theme';$p['references']=[['source'=>$bg[0]['alias'],'sequence'=>0]];$draft['stories'][0]['paragraphs'][]=$p;}
        if($this->mode==='badquote')$draft['stories'][0]['quotes'][0]['text']='Invented quote.';
        if($this->mode==='invented')$draft['stories'][0]['headline']['text']='A new building opened yesterday';
        $item=['type'=>'message','role'=>'assistant','status'=>'completed','phase'=>'final_answer','content'=>[['type'=>'output_text','text'=>json_encode($draft)]]];
        $r=['id'=>'resp_editor_fixture','status'=>$this->mode==='partial'?'in_progress':'completed','error'=>null,'incomplete_details'=>null,'model'=>$this->mode==='wrongmodel'?'unknown':$body['model'],
            'service_tier'=>$this->mode==='wrongtier'?'priority':'default','output'=>[$item],'usage'=>['input_tokens'=>$this->mode==='overrun'?100000:1000,'output_tokens'=>500]];
        if($this->mode==='missingusage')unset($r['usage']);if($this->mode==='ambiguous')$r['output'][]=$item;
        return ['body'=>fixtureGazetteMutation($r,$this->mode,$body['text']['format']['schema'],$this->db),'request_id'=>'req_editor_fixture'];
    }
}
try{
    $dir=$argv[1];$now=strtotime('2026-11-03T12:00:00Z');$manifest=[];
    $policy=['model'=>'gpt-6-luna','pricing_version'=>'fixture-only','input_tokens'=>65536,'output_tokens'=>8192,'input_rate'=>250000,'output_rate'=>500000,'moderation_microusd'=>100,'daily_microusd'=>1000000,'monthly_microusd'=>1000000,'daily_requests'=>8,'lease_seconds'=>120];
    foreach([...['good','flagged','timeout','partial','wrongmodel','wrongtier','badquote','missingusage','overrun','ambiguous','invented'],...array_keys(GAZETTE_CONTRACT_CASES)] as $mode){
        $path=$dir.'/integration-'.$mode.'.sqlite';copy($dir.'/v6.sqlite',$path);chmod($path,0600);$db=LittleSparks\connect($path);$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($db);$db->exec('COMMIT');
        $read=LittleSparks\connect($path);$read->exec('PRAGMA query_only=ON');$mock=new EditorialMock($mode,$db);$result=LittleSparks\runEditorialJob($db,$read,'2026-10-29',$policy,131072,$mock,static fn()=>$now);
        integrationCheck(count($mock->calls)<=2&&$mock->calls[0]==='responses','Bounded request count');
        if(in_array($mode,['good','missingusage','invented'],true))integrationCheck($result['status']==='awaiting_owner_review'&&count($mock->calls)===2,'Completed draft always waits for owner');
        elseif($mode==='flagged')integrationCheck($result['status']==='moderation_rejected','Flagged draft rejected');
        else integrationCheck($result['status']==='rejected_or_uncertain','Invalid/uncertain result rejected');
        if(isset(GAZETTE_CONTRACT_CASES[$mode])){
            integrationCheck(($result['rejection_code']??null)===GAZETTE_CONTRACT_CASES[$mode]&&$mock->calls===['responses'],'Direct adapter shares safe refusal code and never moderates rejected content');
            integrationCheck((int)$db->query('SELECT COUNT(*) FROM gazette_editor_drafts')->fetchColumn()===0&&$db->query('SELECT outcome FROM gazette_editor_receipts')->fetchColumn()==='completed','Completed receipt survives invalid candidate without draft');
            integrationCheck(!str_contains(json_encode($result),'PRIVATE_SYNTHETIC_REJECTION_CANARY'),'Direct adapter diagnostics omit content/errors');
        }
        $day=LittleSparks\gazetteDay('2026-10-29',$now);integrationCheck(LittleSparks\readEditorialGazette($read,$path,$day)===null,'Generation never publishes');
        integrationReject(fn()=>LittleSparks\runEditorialJob($db,$read,'2026-10-29',$policy,131072,$mock,static fn()=>$now),'Routine edition replayed');
        if($mode!=='good')continue;$id=$result['attempt_id'];$sha=$result['draft_sha256'];
        integrationReject(fn()=>LittleSparks\publishEditorialDraft($read,$path,$id,$sha,null,$now),'Unapproved copy published');
        LittleSparks\reviewEditorialDraft($db,$id,$sha,'approved',$now);$pub=LittleSparks\publishEditorialDraft($read,$path,$id,$sha,null,$now);$edition=LittleSparks\readEditorialGazette($read,$path,$day);
        integrationCheck($edition['version']===2&&count($edition['stories'][0]['paragraphs'])===3&&$edition['revision']===1,'Narrative with cross-day evidence');
        integrationCheck(LittleSparks\publishEditorialDraft($read,$path,$id,$sha,null,$now)===$pub,'Repeat publication idempotent');
        $plan=LittleSparks\editorialJobPlan($read,'2026-10-29',$policy,131072,$now);$permit=LittleSparks\permitEditorialRevision($db,'2026-10-29',2,$plan['snapshot']['sha256'],hash('sha256',LittleSparks\editorialJson($plan['policy'])),100000,$now+60,$now);
        $c=LittleSparks\runEditorialJob($db,$read,'2026-10-29',$policy,131072,new EditorialMock(),static fn()=>$now,$permit);LittleSparks\reviewEditorialDraft($db,$c['attempt_id'],$c['draft_sha256'],'approved',$now);
        integrationReject(fn()=>LittleSparks\publishEditorialDraft($read,$path,$c['attempt_id'],$c['draft_sha256'],null,$now),'Stale current version overwritten');
        LittleSparks\publishEditorialDraft($read,$path,$c['attempt_id'],$c['draft_sha256'],$pub['sha256'],$now);
        integrationCheck(LittleSparks\readEditorialGazette($read,$path,$day)['revision']===2&&LittleSparks\readEditorialGazette($read,$path,$day,1)['revision']===1,'Original revision preserved after correction');
        $oldQuote=$edition['stories'][0]['quotes'][0];$db->prepare('UPDATE residents SET name=? WHERE id=?')->execute(['Renamed fixture',$oldQuote['resident_id']]);
        integrationCheck(LittleSparks\readEditorialGazette($read,$path,$day)['stories'][0]['quotes'][0]['name']===$oldQuote['name'],'Historical publication keeps its original name snapshot after rename');
        $db->prepare('UPDATE residents SET name=? WHERE id=?')->execute([$oldQuote['name'],$oldQuote['resident_id']]);
        $hash=hash_file('sha256',$path);LittleSparks\readEditorialGazette($read,$path,$day);integrationCheck(hash_file('sha256',$path)===$hash,'Read leaves SQLite unchanged');
        $db->exec("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");integrationReject(fn()=>LittleSparks\readEditorialGazette($read,$path,$day),'Withdrawn source remained public');
        $db->exec("UPDATE simulation_runs SET status='completed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
        $file=$path.'.gazette/2026-10-29.editor/002.json';$valid=file_get_contents($file);file_put_contents($file,'{}');integrationReject(fn()=>LittleSparks\readEditorialGazette($read,$path,$day),'Corrupt publication accepted');file_put_contents($file,$valid);
        $race=$dir.'/publication-race.sqlite';copy($path,$race);chmod($race,0600);mkdir($race.'.gazette',0700);mkdir($race.'.gazette/2026-10-29.editor',0700);
        foreach(['001.json','002.json'] as $name){copy($path.'.gazette/2026-10-29.editor/'.$name,$race.'.gazette/2026-10-29.editor/'.$name);chmod($race.'.gazette/2026-10-29.editor/'.$name,0600);}
        $chain=LittleSparks\editorialManifests($path,$day);$job=$dir.'/publication-job.json';file_put_contents($job,LittleSparks\editorialJson(['database'=>$race,'attempt'=>$id,'sha'=>$sha,'expected'=>end($chain)['sha256'],'now'=>$now]));chmod($job,0600);
        $manifest=['database'=>$path,'sha256'=>hash_file('sha256',$path),'publication_job'=>$job,'publication_database'=>$race];
    }
    $p=$policy;$p['input_tokens']=14000;$read=LittleSparks\connect($dir.'/v6.sqlite');$read->exec('PRAGMA query_only=ON');$s=LittleSparks\editorialSourcePack($read,'2026-10-29',$now);$small=LittleSparks\boundEditorialSnapshot($s,$p,14000);
    integrationCheck(strlen(LittleSparks\editorialJson(LittleSparks\editorialRequest($small,$p)))+4096<=14000&&count($small['pack']['sources'])<count($s['pack']['sources']),'Drop whole records under input pressure');
    integrationReject(fn()=>LittleSparks\editorialConfiguration(),'Default-off live configuration accepted');echo LittleSparks\editorialJson($manifest);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
