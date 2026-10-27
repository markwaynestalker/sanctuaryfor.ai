<?php
declare(strict_types=1);
require_once __DIR__.'/../app/bulletin_schema.php';require_once __DIR__.'/bulletin-test-support.php';
final class BulletinManaged implements LittleSparks\DirectorTransport
{
    public ManagedMock $inner;public array $calls=[];public array $schemas=[];public array $moderated=[];
    public function __construct(private string $mode){$this->inner=new ManagedMock($mode==='flagged'?'flagged':'good');}
    public function request(string $method,string $path,?array $body,int $deadline,?string $key=null):array
    {
        $this->calls[]=[$method,$path];if($path==='agents/sessions')$this->schemas[]=$body['agent']['text']['format']['schema'];
        if($path==='moderations')$this->moderated[]=json_decode($body['input'],true);
        $r=$this->inner->request($method,$path,$body,$deadline,$key);
        if(str_contains($path,'/turns?')&&$this->mode==='missing_usage')unset($r['data'][0]['usage']);
        if(str_contains($path,'/items?'))$r['data'][0]['content'][0]['text']=json_encode(bulletinProposal($this->inner->context,$this->mode),JSON_THROW_ON_ERROR);
        if(str_ends_with($path,'/events'))return LittleSparks\managedHttpResponse($method,$path,$body,202,'','req_bulletin_accepted');
        return $r;
    }
}
$bulletinEnv=[];foreach(['LITTLE_SPARKS_AI_DAILY_REQUESTS'=>'200','LITTLE_SPARKS_AI_MONTHLY_MICROUSD'=>'1000000','LITTLE_SPARKS_BULLETIN_ENABLED'=>'1','LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS'=>'0','LITTLE_SPARKS_BULLETIN_REPEAT_COOLDOWN_SECONDS'=>'0'] as $k=>$v){$bulletinEnv[$k]=getenv($k);putenv($k.'='.$v);}
foreach(['good','flagged','wrong_author','missing_usage','no_notice'] as $mode){
    $fixture=$stageFresh();$fixture->exec('BEGIN IMMEDIATE');LittleSparks\migrateBulletin($fixture);$fixture->exec('COMMIT');$mock=new BulletinManaged($mode);
    $op=gmdate('Y-m-d\TH:i:00\Z',time()-180);$r=LittleSparks\simulateDirectorOpportunity($fixture,$op,true,null,$mock);$success=!in_array($mode,['flagged','wrong_author'],true);$posted=$success&&$mode!=='no_notice';
    check(($r['status']==='completed')===$success&&(int)$fixture->query('SELECT COUNT(*) FROM bulletin_notices')->fetchColumn()===($posted?1:0),'Managed notice truthful outcome '.$mode);
    check(count($mock->calls)<=8&&count($mock->schemas)===1&&isset($mock->schemas[0]['properties']['notice']),'Managed extended schema uses existing bounded lifecycle '.$mode);
    if($posted){
        $words=$fixture->query('SELECT body FROM bulletin_notices')->fetchColumn();check($words===$mock->moderated[0][1]['notice']['text'],'Managed saved notice exactly matches moderated original');
        if($mode==='missing_usage')check($fixture->query('SELECT state FROM ai_attempts LIMIT 1')->fetchColumn()==='uncertain','Missing usage retains full uncertain commitment with accepted notice');
        $count=count($mock->calls);$again=LittleSparks\simulateDirectorOpportunity($fixture,gmdate('Y-m-d\TH:i:00\Z',time()-60),true,null,$mock);
        check($again['status']==='completed'&&count($mock->schemas)===1&&count($mock->calls)-$count<=8,'Compatible notice sessions reuse input-events without extra generation or recreation');
        $sub=$fixture->query("SELECT http_status,outcome FROM director_http_calls WHERE kind='submit' LIMIT 1")->fetch();check((int)$sub['http_status']===202&&$sub['outcome']==='succeeded','Notice reuse preserves repaired empty-202 acknowledgement handling');
        $previous=$fixture->query('SELECT id,provider_session_id,scope FROM director_sessions ORDER BY rowid')->fetchAll();
        putenv('LITTLE_SPARKS_BULLETIN_ENABLED=0');$legacy=new ManagedMock();$restored=LittleSparks\simulateDirectorOpportunity($fixture,gmdate('Y-m-d\TH:i:00\Z',time()-300),true,null,$legacy);
        check($restored['status']==='completed'&&$fixture->query('SELECT id,provider_session_id,scope FROM director_sessions ORDER BY rowid LIMIT '.count($previous))->fetchAll()===$previous,'Disabling notices preserves sessions and ordinary dialogue');putenv('LITTLE_SPARKS_BULLETIN_ENABLED=1');
    }
}
foreach($bulletinEnv as $k=>$v)putenv($v===false?$k:$k.'='.$v);
echo "PASS managed notice contract, exact moderation/persistence, missing usage holds, empty-202 reused sessions, author rejection and compatible disable without session resets (offline)\n";
