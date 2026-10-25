<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function assertStagingHostIsolation():void
{
    // Live staging must run as a separate non-root Linux identity which cannot
    // read the known production database. Operator filesystem/credential review
    // is still required; a different filename or environment flag is not isolation.
    if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0
        ||is_readable('/var/lib/sanctuaryfor-ai/village.sqlite')||is_readable('/var/lib/sanctuaryfor-ai'))throw new AiGuard('configuration_invalid');
}

function stagingCodeFingerprint():string
{
    $files=array_merge(glob(__DIR__.'/*.php'),glob(__DIR__.'/../contracts/*.json'),[__DIR__.'/../scripts/stage-director-once.php']);sort($files);$hash=hash_init('sha256');
    foreach($files as $file){hash_update($hash,basename($file));hash_update($hash,(string)file_get_contents($file));}return hash_final($hash);
}
function stagingResidents(PDO $db):array
{
    return $db->query('SELECT r.id,r.name,r.antenna,r.created_at,i.description,i.color,i.revision FROM residents r JOIN resident_identities i ON i.resident_id=r.id ORDER BY r.id')->fetchAll();
}
function stagingPlan(PDO $db,string $at,int $expiry):array
{
    if(!directorEnabled($db)||$expiry<=time()||$expiry>time()+3600)throw new AiGuard('configuration_invalid');
    opportunityId($at);assertIdentityIdle($db);
    foreach(['simulation_runs','conversations','messages','memories','memory_stories','relationships','ai_attempts','director_attempts','identity_edits','director_staging_permits'] as $table)
        if((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()!==0)throw new AiGuard('configuration_invalid');
    $rows=stagingResidents($db);
    if(count($rows)!==2||(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==2)throw new AiGuard('configuration_invalid');foreach($rows as $r)validateDescription($r['description']);
    $cfg=directorConfiguration();$registered=registeredDirector($db,$cfg);
    if($cfg['runtime']!=='agents'||$registered['model']!=='gpt-6-luna')throw new AiGuard('configuration_invalid');
    $path=$db->query('PRAGMA database_list')->fetch()['file'];
    if(str_starts_with(str_replace('\\','/',databasePath($path)),'/var/lib/sanctuaryfor-ai/'))throw new AiGuard('configuration_invalid');
    return ['schema'=>'sanctuary.staging.v1','permit_id'=>bin2hex(random_bytes(16)),'approved'=>false,'approval_reference'=>'',
        'risk_acceptance'=>'one-time-research-exception-no-guaranteed-provider-maximum','production_isolation_attested'=>false,
        'manifest_sha256'=>stagingCodeFingerprint(),'database_sha256'=>hash('sha256',databasePath($path)),
        'installation_sha256'=>hash('sha256',secret($db)),'residents_sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),
        'resident_ids'=>array_column($rows,'id'),'agent_id'=>$registered['agent_id'],'configuration_sha256'=>$registered['fingerprint'],
        'model'=>'gpt-6-luna','opportunity'=>$at,'expires_at'=>$expiry,'allocation_microusd'=>100000,'http_slots'=>8];
}
final class StagingPermit
{
    private function __construct(private readonly PDO $db,private readonly array $permit){}
    public static function consume(PDO $db,array $permit):self
    {
        if(PHP_SAPI!=='cli'||($permit['approved']??null)!==true||($permit['production_isolation_attested']??null)!==true
            ||!is_string($permit['approval_reference']??null)||!preg_match('/\A[a-zA-Z0-9 .:_-]{1,160}\z/',$permit['approval_reference'])
            ||!is_int($permit['expires_at']??null)||$permit['expires_at']<time()+90||!is_string($permit['opportunity']??null)||!preg_match('/\A[a-f0-9]{32}\z/',$permit['permit_id']??''))throw new AiGuard('configuration_invalid');
        $db->exec('BEGIN IMMEDIATE');try{
            $expected=stagingPlan($db,$permit['opportunity'],$permit['expires_at']);
            $expected['permit_id']=$permit['permit_id'];$expected['approved']=true;$expected['approval_reference']=$permit['approval_reference'];$expected['production_isolation_attested']=true;
            if(count($expected)!==count($permit)||array_diff_key($expected,$permit))throw new AiGuard('configuration_invalid');
            foreach($expected as $k=>$v)if($permit[$k]!==$v)throw new AiGuard('configuration_invalid');
            $encoded=json_encode($permit,JSON_THROW_ON_ERROR);
            $db->prepare('INSERT INTO director_staging_permits VALUES (1,?,?,?,?,?,?,100000,8,?)')->execute([$permit['permit_id'],hash('sha256',$encoded),$permit['manifest_sha256'],$permit['residents_sha256'],hash('sha256',$permit['approval_reference']),$permit['expires_at'],gmdate('Y-m-d\TH:i:s\Z')]);
            $db->exec('COMMIT');return new self($db,$permit);
        }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    public function assertEncounter(PDO $db,string $at,array $registered,array $prepared):void
    {
        $ids=array_column($prepared['context']['participants'],'id');sort($ids);$expected=$this->permit['resident_ids'];sort($expected);
        if($db!==$this->db||$at!==$this->permit['opportunity']||$ids!==$expected||$registered['fingerprint']!==$this->permit['configuration_sha256']||time()>=$this->permit['expires_at']
            ||hash('sha256',json_encode(stagingResidents($db),JSON_THROW_ON_ERROR))!==$this->permit['residents_sha256'])throw new AiGuard('configuration_invalid');
    }
    public function authorize(string $method,string $path,?array $body):void
    {
        if(PHP_SAPI!=='cli'||time()>=$this->permit['expires_at']||stagingCodeFingerprint()!==$this->permit['manifest_sha256'])throw new AiGuard('configuration_invalid');
        $a=$this->db->query("SELECT a.*,s.provider_session_id FROM director_attempts a JOIN director_sessions s ON s.id=a.session_id ORDER BY a.rowid DESC LIMIT 1")->fetch();
        if(!$a||$a['state']!=='reserved'||(int)$a['request_slots']!==8||(int)$a['reserved_microusd']!==100000)throw new AiGuard('configuration_invalid');
        $call=$this->db->query('SELECT * FROM director_http_calls ORDER BY rowid DESC LIMIT 1')->fetch();
        if(!$call||$call['attempt_id']!==$a['id']||$call['outcome']!=='started'||$call['method']!==$method||$call['path_sha256']!==hash('sha256',$path))throw new AiGuard('configuration_invalid');
        if($method==='POST'&&$path==='agents/sessions'){
            if(($body['agent_id']??null)!==$this->permit['agent_id']||isset($body['agent']['instructions'])||($body['agent']['tools']??null)!==[]
                ||($body['agent']['multi_agent']['enabled']??null)!==false||($body['environment']??null)!==['type'=>'none']||($body['spend_control']??null)!==['limit'=>1]
                ||($body['agent']['model']??null)!=='gpt-6-luna'||($body['agent']['service_tier']??null)!=='default'
                ||($body['agent']['reasoning']??null)!==['effort'=>'none']||($body['stream']??null)!==false
                ||hash('sha256',$body['input'][0]['content'][0]['text']??'')!==$a['context_sha256'])throw new AiGuard('configuration_invalid');return;
        }
        $sid=$a['provider_session_id'];if(!is_string($sid)||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$sid))throw new AiGuard('configuration_invalid');
        if($method==='POST'&&$path==='agents/sessions/'.$sid.'/events'&&$body===['events'=>[['type'=>'agent.session.input.cancel']]])return;
        if($method==='GET'&&($path==='agents/sessions/'.$sid||$path==='agents/sessions/'.$sid.'/turns?limit=1&order=desc'))return;
        $q=$this->db->prepare("SELECT turn_id FROM director_managed_outcomes WHERE attempt_id=? AND status='completed' ORDER BY id DESC LIMIT 1");$q->execute([$a['id']]);$turn=$q->fetchColumn();
        if($turn&&$method==='GET'&&$path==='agents/sessions/'.$sid.'/turns/'.$turn.'/items?limit=20&order=asc')return;
        if($turn&&$method==='POST'&&$path==='moderations'&&($body['model']??null)==='omni-moderation-latest')return;
        throw new AiGuard('configuration_invalid');
    }
}
function stagingTurn(array $page,string $sid,array $registered,?string $prior):array
{
    $turn=$page['data'][0]??null;
    if(($page['has_more']??null)!==false||count($page['data']??[])!==1||!is_array($turn)
        ||($turn['session_id']??null)!==$sid||($turn['agent_id']??null)!==$registered['agent_id']||($turn['subagent_id']??null)!==null
        ||!is_string($turn['id']??null)||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$turn['id'])||($prior!==null&&$turn['id']!==$prior)
        ||!in_array($turn['status']??null,['queued','in_progress','waiting','completed','failed','cancelled'],true))throw new AiGuard('usage_unknown');
    return $turn;
}
function stagingItems(array $items,string $turn):array
{
    if(($items['has_more']??true)!==false||!is_array($items['data']??null))throw new AiGuard('invalid_proposal');$texts=[];
    foreach($items['data'] as $item){
        if(($item['type']??null)==='reasoning')continue;
        if(($item['type']??null)!=='message'||($item['role']??null)!=='assistant'||($item['phase']??null)!=='final_answer'||($item['status']??null)!=='completed'||($item['turn_id']??null)!==$turn)throw new AiGuard('invalid_proposal');
        foreach($item['content']??[] as $c){if(($c['type']??null)!=='output_text'||!is_string($c['text']??null))throw new AiGuard('invalid_proposal');$texts[]=$c['text'];}
    }
    if(count($texts)!==1||strlen($texts[0])>4096)throw new AiGuard('invalid_proposal');$p=json_decode($texts[0],true,12,JSON_THROW_ON_ERROR);if(!is_array($p))throw new AiGuard('invalid_proposal');return $p;
}
// At most three progress observations, one cancellation, no generation retry.
function stagingManagedEncounter(PDO $db,DirectorTransport $transport,array $attempt,array $session,array $registered,array $prepared,array $choices,?callable $clock=null,?callable $pause=null,bool $conversationOnly=true):array
{
    $clock??=static fn()=>time();$pause??=static fn(int $seconds)=>usleep($seconds*1000000);$sid=null;$turnId=null;$terminal=false;$calls=0;
    $request=function(string $method,string $path,?array $body=null,?string $key=null,?string $kind=null,bool $recovery=false)use($db,$transport,$attempt,$clock,&$calls):array{
        $deadline=$recovery?$attempt['expires']:$attempt['expires']-25;
        if($clock()>=$deadline||$calls>=8||(!$recovery&&$calls>=7))throw new AiGuard('daily_request_cap');$calls++;
        $n=startManagedHttp($db,$attempt,$method,$path,$kind);
        try{$r=$transport->request($method,$path,$body,$deadline,$key);finishManagedHttp($db,$attempt,$n,$r['_http']??[]);unset($r['_http']);return $r;}
        catch(\Throwable $e){finishManagedHttp($db,$attempt,$n,$e instanceof ManagedHttpFailure?['status'=>$e->status,'request_id'=>$e->requestId,'succeeded'=>false,'validation'=>$e->validation]:null);throw $e;}
    };
    try{
        if($session['provider_session_id']!==null)throw new AiGuard('configuration_invalid');
        $body=managedDirectorPrototype(json_decode($registered['configuration'],true,16,JSON_THROW_ON_ERROR),$prepared['context'],$choices);
        if($conversationOnly)$body['agent']['text']['format']['schema']['properties']['outcome']['enum']=['conversation'];
        $body['metadata']=['sanctuary_attempt_id'=>$attempt['id'],'contract'=>'sanctuary.director.v1'];$remote=$request('POST','agents/sessions',$body,$attempt['id']);
        $sid=$remote['id']??null;if(!is_string($sid)||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$sid)){$sid=null;throw new AiGuard('transport_uncertain');}
        $db->prepare("UPDATE director_sessions SET provider_session_id=?,state='uncertain' WHERE id=?")->execute([$sid,$session['id']]);assertManagedSession($remote,$registered,$sid,false);
        $remote=$request('GET','agents/sessions/'.$sid);assertManagedSession($remote,$registered,$sid,false);
        for($i=1;$i<=3;$i++){
            if($i>1){if($clock()>=$attempt['expires']-30)break;$pause(2);}
            $turn=stagingTurn($request('GET','agents/sessions/'.$sid.'/turns?limit=1&order=desc',null,null,'turns_'.$i),$sid,$registered,$turnId);$turnId=$turn['id'];
            $observed=observeManagedUsage($db,$attempt,$turnId,$turn['usage']??null,aiConfiguration(time()));
            if(in_array($turn['status'],['completed','failed','cancelled'],true)){$terminal=true;recordManagedOutcome($db,$attempt,$sid,$turnId,$turn['status']);break;}
        }
        if(!$terminal)throw new AiGuard('usage_unknown');
        if($turn['status']!=='completed'||($turn['error']??null)!==null)throw new AiGuard('invalid_proposal');
        if($observed===null)throw new AiGuard('usage_unknown');
        $decoded=stagingItems($request('GET','agents/sessions/'.$sid.'/turns/'.$turnId.'/items?limit=20&order=asc'),$turnId);
        $proposal=parseDirectorProposal($decoded,$prepared['context']['participants'],$choices);
        if($conversationOnly&&($proposal['outcome']!=='conversation'||$proposal['world_action']!=='none'))throw new AiGuard('invalid_proposal');
        $moderated=$request('POST','moderations',['model'=>'omni-moderation-latest','input'=>json_encode([$prepared['context'],$decoded],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        if(count($moderated['results']??[])!==1||($moderated['results'][0]['flagged']??null)!==false)throw new AiGuard('moderation_rejected');
        if($calls<8&&$clock()<$attempt['expires']-25){$final=stagingTurn($request('GET','agents/sessions/'.$sid.'/turns?limit=1&order=desc',null,null,'final_turn',true),$sid,$registered,$turnId);
            if($final['status']!=='completed'){recordManagedOutcome($db,$attempt,$sid,$turnId,'unresolved');throw new AiGuard('usage_unknown');}
            if(observeManagedUsage($db,$attempt,$turnId,$final['usage']??null,aiConfiguration(time()))===null)throw new AiGuard('usage_unknown');}
        $db->prepare('UPDATE director_sessions SET turns=1,last_turn_id=? WHERE id=?')->execute([$turnId,$session['id']]);return ['proposal'=>$proposal];
    }catch(\Throwable $e){
        if(!$terminal&&$sid!==null&&$calls<8){
            try{$request('POST','agents/sessions/'.$sid.'/events',['events'=>[['type'=>'agent.session.input.cancel']]],$attempt['id'],'cancel',true);recordManagedOutcome($db,$attempt,$sid,$turnId,'cancel_requested');
                if($calls<8&&$clock()<$attempt['expires']){$after=stagingTurn($request('GET','agents/sessions/'.$sid.'/turns?limit=1&order=desc',null,null,'final_turn',true),$sid,$registered,$turnId);observeManagedUsage($db,$attempt,$after['id'],$after['usage']??null,aiConfiguration(time()));recordManagedOutcome($db,$attempt,$sid,$after['id'],in_array($after['status'],['completed','failed','cancelled'],true)?$after['status']:'unresolved');}
            }catch(\Throwable){recordManagedOutcome($db,$attempt,$sid,$turnId,'cancel_uncertain');}
        }elseif(!$terminal)recordManagedOutcome($db,$attempt,$sid,$turnId,$sid===null?'session_lost':'unresolved');
        throw $e;
    }
}
