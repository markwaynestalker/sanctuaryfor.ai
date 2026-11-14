<?php
declare(strict_types=1);
require_once __DIR__.'/../app/director_billing_settlement.php';
final class Accounting96Mock implements LittleSparks\DirectorTransport
{
    public ManagedMock $inner;private int $reads=0;
    public function __construct(private string $mode,?PDO $db=null){$this->inner=new ManagedMock(in_array($mode,['flagged','apply_failure'],true)?$mode:'good',$db);}
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array
    {
        $r=$this->inner->request($method,$path,$body,$deadline,$idempotencyKey);
        if(!str_contains($path,'/turns?'))return $r;
        $this->reads++;$input=2300;$output=180;
        if($this->mode==='multi_call'){$input=40000;$output=900;}
        if($this->mode==='growing'&&$this->reads>1)$output=300;
        if($this->mode==='regressing'&&$this->reads===1)$output=300;
        if($this->mode==='overrun')$input=100000;
        $usage=['input_tokens'=>$input,'output_tokens'=>$output,'total_tokens'=>$input+$output,
            'input_tokens_details'=>['cached_tokens'=>1000],'output_tokens_details'=>['reasoning_tokens'=>100]];
        if($this->mode==='malformed')$usage['total_tokens']++;
        if($this->mode==='missing'||($this->mode==='final_missing'&&$this->reads>1))$usage=null;
        $r['data'][0]['usage']=$usage;return $r;
    }
}
(function()use($directorFresh,$at,$dir):void{try{
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_AI_ENABLED=1');putenv('LITTLE_SPARKS_DIRECTOR_ENABLED=1');putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');
    putenv('LITTLE_SPARKS_AI_DAILY_REQUESTS=200');putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=25000000');
    foreach(['good','multi_call','growing','regressing','missing','final_missing','malformed','overrun','flagged','apply_failure'] as $mode){
        $db=$directorFresh();$prior=LittleSparks\aiCommittedTotal($db,gmdate('Y-m'));$mock=new Accounting96Mock($mode,$db);
        $r=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock,null,null,false,null,true);
        $q=$db->prepare('SELECT * FROM ai_attempts WHERE id=?');$q->execute([$r['id']]);$a=$q->fetch();
        $success=!in_array($mode,['malformed','flagged','apply_failure'],true);$estimated=in_array($mode,['good','multi_call','growing'],true);
        check(($r['status']==='completed')===$success,'Usage availability and cumulative limits do not invent or discard completed speech: '.$mode);
        check($a['state']===($estimated?'applied':'uncertain'),'Only successfully saved fully observed routine turns release their provisional reservation: '.$mode);
        $expected=match($mode){'good'=>378,'multi_call'=>5450,'growing'=>438,default=>(int)$a['reserved_microusd']};
        if($mode==='overrun')$expected=12590;
        check(LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))===$prior+$expected,'Use maximum cumulative estimate once; cache/reasoning are subsets; overruns stay charged: '.$mode);
        check((int)$a['request_slots']===2&&(int)$db->query("SELECT request_slots FROM director_attempts WHERE id='".$r['id']."'")->fetchColumn()===8,'All original HTTP reservation slots remain');
        if($estimated)check((int)$a['estimated_microusd']===$expected&&(int)$a['reserved_microusd']===10000,'Preserve original reservation separately from committed estimate');
        $calls=count($mock->inner->calls);LittleSparks\simulateDirectorOpportunity($db,$at,true,null,$mock,null,null,false,null,true);check(count($mock->inner->calls)===$calls,'No replay: '.$mode);
    }
    $db=$directorFresh();$prior=LittleSparks\aiCommittedTotal($db,gmdate('Y-m'));putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD='.($prior+10400));
    foreach([1,2,3] as $n){$mock=new Accounting96Mock('good');$r=LittleSparks\simulateDirectorOpportunity($db,gmdate('Y-m-d\TH:i:00\Z',time()-$n*60),true,null,$mock,null,null,false,null,true);check(($r['status']==='completed')===($n<3),'Estimated settlement restores headroom and the unchanged cap still blocks before dispatch');if($n===3)check(!$mock->inner->calls,'No HTTP past cap');}
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=25000000');
    // An old experimental attempt has no automatic estimate settlement.
    $db=$directorFresh();$r=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,new ManagedMock());$id=$r['id'];
    $before=LittleSparks\directorAttemptInspection($db,$id);$held=LittleSparks\aiCommittedTotal($db,gmdate('Y-m'));
    $decision=LittleSparks\settleDirectorBilling($db,$id,$before['snapshot_sha256'],str_repeat('7',32),200,str_repeat('8',64));
    check($decision['commitment_microusd']===378&&LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))===$held-10000+378,'Explicit final-billing review supersedes the reservation and retains higher observed estimates');
    $after=LittleSparks\directorAttemptInspection($db,$id);
    foreach(['ai_attempts','director_attempts','simulation_runs','director_http_calls','director_usage_observations','director_managed_outcomes','session'] as $table)check($before['records'][$table]===$after['records'][$table],'Owner settlement preserves original rows, state, errors, history and slots: '.$table);
    check(LittleSparks\settleDirectorBilling($db,$id,$before['snapshot_sha256'],str_repeat('7',32),200,str_repeat('8',64))['replayed'],'Owner settlement is exactly once');
    try{LittleSparks\settleDirectorBilling($db,$id,$before['snapshot_sha256'],str_repeat('7',32),201,str_repeat('8',64));throw new LogicException('Conflicting settlement accepted');}catch(LittleSparks\AiGuard){}
    LittleSparks\observeManagedUsage($db,['id'=>$id,'reserved'=>10000],'turn_1',['input_tokens'=>4000,'output_tokens'=>400,'total_tokens'=>4400],LittleSparks\aiConfiguration(time()));
    check(LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))===$held-10000+700,'Late higher usage increases commitment even after owner settlement');
    LittleSparks\reviewManagedBilling($db,$id,str_repeat('5',32),11000,str_repeat('b',64));
    check(LittleSparks\aiCommittedTotal($db,gmdate('Y-m'))===$held+1000,'Later higher reviewed billing remains charged after a settlement');
    $path=$db->query('PRAGMA database_list')->fetch()['file'];
    $cli=static function(string $script,array $args)use($path):array{
        $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../scripts/'.$script,'--database='.$path],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    $frozen=LittleSparks\villageManifest($db);
    [$code,$out]=$cli('ai-status.php',[]);$status=json_decode($out,true);
    check($code===0&&$status['monthly_billing']['provider_billed_microusd']===null&&!$status['monthly_billing']['managed_observations_are_final_billing']&&$status['monthly_billing']['explicit_owner_settlements']===1,'CLI separates commitments and observed estimates from unknown provider billing');
    check($status['monthly_committed_microusd']===LittleSparks\aiCommittedTotal($db,gmdate('Y-m')),'Status and admission use the identical settlement projection');
    [$code,$out]=$cli('settle-director-billing.php',['--attempt='.$id]);check($code===0&&json_decode($out,true)['applied']===false,'Owner CLI defaults to read-only inspection');
    foreach([['--apply'],['--apply','--confirm-final-billing'],['--attempt='.$id],['--unknown']] as $bad){[$code,$out]=$cli('settle-director-billing.php',array_merge(['--attempt='.$id],$bad));check($code===1&&$out==='','Missing explicit approval/evidence, duplicate and unknown CLI options refuse');}
    check(LittleSparks\villageManifest($db)===$frozen,'Status, default inspection and refused CLI invocations preserve all database contents');
    // Exercise the complete explicit CLI with a private synthetic evidence file.
    $other=$directorFresh();$r=LittleSparks\simulateDirectorOpportunity($other,$at,true,null,new ManagedMock());$otherId=$r['id'];
    $otherPath=$other->query('PRAGMA database_list')->fetch()['file'];$snapshot=LittleSparks\directorAttemptInspection($other,$otherId)['snapshot_sha256'];
    $evidence=$dir.'/accounting-96-final-billing.txt';file_put_contents($evidence,'OFFLINE SYNTHETIC BILLING EVIDENCE');chmod($evidence,0600);
    $args=[PHP_BINARY,__DIR__.'/../scripts/settle-director-billing.php','--database='.$otherPath,'--attempt='.$otherId,'--apply','--snapshot-sha256='.$snapshot,
        '--review='.str_repeat('6',32),'--microusd=500','--evidence-file='.$evidence,'--confirm-final-billing','--allow-reservation-reduction'];
    $workers=[];foreach([1,2] as $_){$p=proc_open($args,[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
    $replays=[];foreach($workers as [$p,$pipes]){
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$result=json_decode($out,true);
        check(proc_close($p)===0&&$result['commitment_microusd']===500&&$result['http_requests']===0,'Concurrent explicit CLI appends evidence-bound settlement without network');$replays[]=$result['replayed'];
    }
    sort($replays);check($replays===[false,true]&&(int)$other->query('SELECT COUNT(*) FROM director_billing_reviews')->fetchColumn()===1,'Concurrent owner commands settle once and return an idempotent replay');
    foreach(['stale','cross_month','active','unresolved'] as $mode){
        $db=$directorFresh();$r=LittleSparks\simulateDirectorOpportunity($db,$at,true,null,new ManagedMock($mode==='unresolved'?'timeout':'good'));$id=$r['id'];
        $snapshot=LittleSparks\directorAttemptInspection($db,$id)['snapshot_sha256'];
        if($mode==='stale')$db->prepare('UPDATE ai_attempts SET finished_at=? WHERE id=?')->execute(['2000-01-01T00:00:00Z',$id]);
        if($mode==='cross_month'){$db->prepare('UPDATE ai_attempts SET month=? WHERE id=?')->execute(['2000-01',$id]);$snapshot=LittleSparks\directorAttemptInspection($db,$id)['snapshot_sha256'];}
        if($mode==='active')$db->prepare('UPDATE ai_lease SET owner=?,expires_at=?')->execute([$id,time()+60]);
        $before=LittleSparks\directorAttemptInspection($db,$id);
        try{LittleSparks\settleDirectorBilling($db,$id,$snapshot,str_repeat('9',32),0,str_repeat('a',64));throw new LogicException('Unsafe settlement accepted: '.$mode);}catch(LittleSparks\AiGuard){}
        check(LittleSparks\directorAttemptInspection($db,$id)===$before,'Refused settlement is read-only: '.$mode);
    }
    putenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD=1000000');
    echo "PASS #96 conservative routine estimates, cumulative usage, overruns/missing metadata, unchanged cap and HTTP slots, snapshot-bound explicit historical settlement, immutable history and late increases\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}})();
