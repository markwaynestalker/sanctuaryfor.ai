<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/director.php';

function reconciliationId(string $id):void
{
    if(!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new AiGuard('configuration_invalid');
}
function reconciliationIdle(PDO $db):void
{
    if($db->query('SELECT owner FROM ai_lease WHERE singleton=1')->fetchColumn()!==null
        ||$db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new AiGuard('simulation_busy');
}
function reconciliationValue(PDO $db,string $name):?array
{
    $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute([$name]);$value=$q->fetchColumn();
    return $value===false?null:json_decode($value,true,32,JSON_THROW_ON_ERROR);
}
function appendReconciliation(PDO $db,string $name,array $value):void
{
    $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute([$name,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function directorAttemptInspection(PDO $db,string $id):array
{
    reconciliationId($id);if(!directorEnabled($db))throw new AiGuard('migration_required');$rows=[];
    foreach(['ai_attempts'=>'id','director_attempts'=>'id','simulation_runs'=>'id','director_http_calls'=>'attempt_id','director_managed_outcomes'=>'attempt_id','director_usage_observations'=>'attempt_id','director_billing_reviews'=>'attempt_id'] as $table=>$column){
        $q=$db->prepare("SELECT * FROM $table WHERE $column=? ORDER BY rowid");$q->execute([$id]);$rows[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    if(count($rows['ai_attempts'])!==1||count($rows['director_attempts'])!==1||count($rows['simulation_runs'])!==1||$rows['director_attempts'][0]['runtime']!=='agents')throw new AiGuard('configuration_invalid');
    $q=$db->prepare('SELECT * FROM director_sessions WHERE id=?');$q->execute([$rows['director_attempts'][0]['session_id']]);$rows['session']=$q->fetch(PDO::FETCH_ASSOC);
    if(!$rows['session'])throw new AiGuard('configuration_invalid');
    return ['attempt_id'=>$id,'snapshot_sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),'records'=>$rows];
}
function reconciliationUsage(mixed $usage,array $a):?array
{
    if(!is_array($usage))return null;
    foreach(['input_tokens','output_tokens','total_tokens'] as $k)if(!is_int($usage[$k]??null)||$usage[$k]<0||$usage[$k]>1000000000)return null;
    if($usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens'])return null;
    $cached=$usage['input_tokens_details']['cached_tokens']??0;$reasoning=$usage['output_tokens_details']['reasoning_tokens']??0;
    if(!is_int($cached)||!is_int($reasoning)||$cached<0||$cached>$usage['input_tokens']||$reasoning<0||$reasoning>$usage['output_tokens'])return null;
    return ['input_tokens'=>$usage['input_tokens'],'output_tokens'=>$usage['output_tokens'],'cached_tokens'=>$cached,'reasoning_tokens'=>$reasoning,
        'estimated_microusd'=>estimatedAiCost($usage['input_tokens'],$usage['output_tokens'],(int)$a['input_rate'],(int)$a['output_rate'])];
}
/** Three bound metadata GETs. Never requests dialogue items or submits an input/cancel. */
function inspectDirectorProvider(PDO $db,string $id,string $inspection,?DirectorTransport $transport=null):array
{
    reconciliationId($inspection);$prefix='director_inspection:'.$inspection;$db->exec('BEGIN IMMEDIATE');
    try{
        reconciliationIdle($db);$local=directorAttemptInspection($db,$id);$a=$local['records']['ai_attempts'][0];$s=$local['records']['session'];$sid=$s['provider_session_id'];
        if($a['state']!=='uncertain'||$local['records']['simulation_runs'][0]['status']!=='failed'||!is_string($sid)||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$sid))throw new AiGuard('accounting_unresolved');
        if(reconciliationValue($db,$prefix.':started')!==null)throw new AiGuard('request_already_started');
        // A latest-turn lookup cannot safely identify an unrecorded old turn in a reused session.
        $q=$db->prepare('SELECT COUNT(*) FROM director_attempts d JOIN director_sessions s ON s.id=d.session_id WHERE s.provider_session_id=?');$q->execute([$sid]);
        if((int)$q->fetchColumn()!==1)throw new AiGuard('session_ambiguous');
        $day=gmdate('Y-m-d');$q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM ai_attempts WHERE day=?');$q->execute([$day]);$used=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COALESCE(SUM(request_slots-2),0) FROM director_attempts WHERE day=?');$q->execute([$day]);$used+=(int)$q->fetchColumn()+directorAccessReservedRequests($db,$day);
        if($used>aiDailyRequestLimit()-3)throw new AiGuard('daily_request_cap');
        for($n=1;$n<=3;$n++){$key=substr(hash('sha256',$inspection.':'.$n),0,32);appendReconciliation($db,'director_access_request:'.$key,
            ['check_id'=>$key,'day'=>$day,'http_requests_reserved'=>1,'purpose'=>'director_reconciliation','method'=>'GET','attempt_id'=>$id,'inspection_id'=>$inspection]);}
        appendReconciliation($db,$prefix.':started',['attempt_id'=>$id,'session_id'=>$sid,'snapshot_sha256'=>$local['snapshot_sha256'],'day'=>$day,'http_requests_reserved'=>3,'generation_requests'=>0,'at'=>time()]);
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
    $transport??=new OpenAiManagedTransport(null,$sid);$calls=[];$deadline=time()+24;
    $get=function(string $path,int $n)use($db,$transport,$prefix,$deadline,$day,&$calls):array{
        if(gmdate('Y-m-d')!==$day||time()>=$deadline)throw new AiGuard('day_boundary');
        appendReconciliation($db,$prefix.':http:'.$n.':started',['method'=>'GET','path_sha256'=>hash('sha256',$path),'at'=>time()]);
        try{$r=$transport->request('GET',$path,null,min(time()+7,$deadline));$meta=$r['_http']??[];unset($r['_http']);
            if(isset($meta['status'])&&$meta['status']!==200)throw new ManagedHttpFailure($meta['status'],$meta['request_id']??null);
            $info=['status'=>$meta['status']??200,'request_id'=>$meta['request_id']??null,'succeeded'=>true];
        }catch(\Throwable $e){$info=['status'=>$e instanceof ManagedHttpFailure?$e->status:null,'request_id'=>$e instanceof ManagedHttpFailure?$e->requestId:null,'succeeded'=>false];}
        if(!is_int($info['status'])||$info['status']<100||$info['status']>599)$info['status']=null;
        if(!is_string($info['request_id'])||!preg_match('/\Areq_[a-zA-Z0-9_-]{1,120}\z/',$info['request_id'])||str_contains($info['request_id'],'sk-'))$info['request_id']=null;
        $calls[]=$info;appendReconciliation($db,$prefix.':http:'.$n.':finished',$info+['at'=>time()]);
        if(!$info['succeeded'])throw new AiGuard('transport_uncertain');return $r;
    };
    $report=['attempt_id'=>$id,'inspection_id'=>$inspection,'snapshot_sha256'=>$local['snapshot_sha256'],'session_id'=>$sid,'ready_for_billing_review'=>false,'generation_requests'=>0,'usage_is_final_billing'=>false];
    try{
        $validateSession=function(array $r)use($s,$a,$sid,$id):void{
            if(($r['id']??null)!==$sid||($r['object']??null)!=='agent.session'||($r['agent']['id']??null)!==$s['agent_id']||($r['agent']['model']??null)!==$a['model']
                ||!in_array($r['agent']['service_tier']??null,['default','auto'],true)||($r['agent']['tools']??null)!==[]||($r['agent']['multi_agent']['enabled']??null)!==false
                ||($r['environment']['type']??null)!=='none'||($r['required_actions']??null)!==[]||($r['vault_ids']??null)!==[]||($r['metadata']['sanctuary_attempt_id']??null)!==$id)throw new AiGuard('session_mismatch');
            if(!in_array($r['status']??null,['idle','failed'],true))throw new AiGuard('provider_active');
        };
        $before=$get('agents/sessions/'.$sid,1);$validateSession($before);
        $turn=stagingTurn($get('agents/sessions/'.$sid.'/turns?limit=1&order=desc',2),$sid,['agent_id'=>$s['agent_id']],null);
        if(!in_array($turn['status'],['completed','failed','cancelled'],true)||!is_int($turn['created_at']??null)||$turn['created_at']<strtotime($a['created_at'])
            ||($turn['status']==='completed'&&($turn['error']??null)!==null))throw new AiGuard('turn_not_terminal');
        $after=$get('agents/sessions/'.$sid,3);$validateSession($after);
        if(!is_int($before['last_active_at']??null)||$before['last_active_at']!==($after['last_active_at']??null))throw new AiGuard('provider_changed');
        $beforeUsage=reconciliationUsage($before['usage']??null,$a);$sessionUsage=reconciliationUsage($after['usage']??null,$a);$turnUsage=reconciliationUsage($turn['usage']??null,$a);
        if($beforeUsage===null||$sessionUsage===null||$turnUsage===null)throw new AiGuard('usage_unknown');
        $report+=['turn_id'=>$turn['id'],'provider_status'=>$after['status'],'turn_status'=>$turn['status'],'before_session_usage'=>$beforeUsage,'session_usage'=>$sessionUsage,'turn_usage'=>$turnUsage,
            'observed_estimate_microusd'=>max($beforeUsage['estimated_microusd'],$sessionUsage['estimated_microusd'],$turnUsage['estimated_microusd'])];
        $report['ready_for_billing_review']=true;$report['reason']='terminal_usage_observed';
    }catch(\Throwable $e){$report['reason']=$e instanceof AiGuard?$e->reason:'provider_response_invalid';}
    $db->exec('BEGIN IMMEDIATE');try{
        if(directorAttemptInspection($db,$id)['snapshot_sha256']!==$local['snapshot_sha256']){$report['ready_for_billing_review']=false;$report['reason']='local_state_changed';}
        $report['http']=$calls;$report['inspected_at']=time();$report['inspection_sha256']=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        appendReconciliation($db,$prefix.':report',$report);$db->exec('COMMIT');return $report;
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}

/** Operator-attested final cost; no network, world changes, refund or replay. */
function resolveDirectorInspection(PDO $db,string $id,string $inspection,string $inspectionHash,string $review,int $amount,string $evidenceHash):array
{
    reconciliationId($id);reconciliationId($inspection);reconciliationId($review);
    if(!preg_match('/\A[a-f0-9]{64}\z/',$inspectionHash)||!preg_match('/\A[a-f0-9]{64}\z/',$evidenceHash)||$amount<0||$amount>100000000)throw new AiGuard('configuration_invalid');
    $decision=['attempt_id'=>$id,'inspection_id'=>$inspection,'inspection_sha256'=>$inspectionHash,'review_id'=>$review,'verified_microusd'=>$amount,'evidence_sha256'=>$evidenceHash];
    $db->exec('BEGIN IMMEDIATE');try{
        reconciliationIdle($db);$prior=reconciliationValue($db,'director_resolution:'.$review);
        if($prior!==null){foreach($decision as $k=>$v)if(($prior[$k]??null)!==$v)throw new AiGuard('configuration_invalid');$current=directorAttemptInspection($db,$id)['records']['ai_attempts'][0]['state'];$db->exec('COMMIT');return $prior+['replayed'=>true,'current_state'=>$current];}
        $report=reconciliationValue($db,'director_inspection:'.$inspection.':report');
        if($report===null||($report['attempt_id']??null)!==$id||($report['inspection_sha256']??null)!==$inspectionHash||($report['ready_for_billing_review']??null)!==true
            ||($report['inspected_at']??0)<time()-900||($report['inspected_at']??PHP_INT_MAX)>time())throw new AiGuard('inspection_not_current');
        $hashed=$report;unset($hashed['inspection_sha256']);
        if(!hash_equals($inspectionHash,hash('sha256',json_encode($hashed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))))throw new AiGuard('inspection_not_current');
        $local=directorAttemptInspection($db,$id);$a=$local['records']['ai_attempts'][0];
        if($local['snapshot_sha256']!==$report['snapshot_sha256']||$a['state']!=='uncertain'||$local['records']['simulation_runs'][0]['status']!=='failed'||$a['month']!==gmdate('Y-m'))throw new AiGuard('accounting_unresolved');
        $commitment=max((int)$a['reserved_microusd'],(int)($a['estimated_microusd']??0),$amount,(int)$report['observed_estimate_microusd']);
        foreach($local['records']['director_usage_observations'] as $o)$commitment=max($commitment,(int)($o['estimated_microusd']??0));
        foreach($local['records']['director_billing_reviews'] as $r)$commitment=max($commitment,(int)$r['commitment_microusd']);
        $other=aiCommittedTotal($db,$a['month'],$id);
        if($other>PHP_INT_MAX-$commitment)throw new AiGuard('configuration_invalid');
        $total=$other+$commitment;$within=$total<=aiMonthlyBudget();$state=$within?'rejected':'uncertain';
        recordManagedOutcome($db,['id'=>$id],$report['session_id'],$report['turn_id'],$report['turn_status']);
        $u=$report['session_usage'];$db->prepare("INSERT INTO director_usage_observations(attempt_id,turn_id,input_tokens,output_tokens,cached_tokens,reasoning_tokens,estimated_microusd,status,observed_at) VALUES (?,?,?,?,?,?,?,'observed',?)")
            ->execute([$id,$report['turn_id'],$u['input_tokens'],$u['output_tokens'],$u['cached_tokens'],$u['reasoning_tokens'],$report['observed_estimate_microusd'],gmdate('Y-m-d\TH:i:s\Z')]);
        $db->prepare('INSERT INTO director_billing_reviews VALUES (?,?,?,?,?,?,?)')->execute([$review,$id,$evidenceHash,$amount,$commitment,$within?'reviewed':'overrun',gmdate('Y-m-d\TH:i:s\Z')]);
        $db->prepare('UPDATE ai_attempts SET estimated_microusd=?,state=? WHERE id=?')->execute([$commitment,$state,$id]);
        $db->prepare('UPDATE director_attempts SET estimated_microusd=?,state=? WHERE id=?')->execute([$commitment,$state,$id]);
        // Do not reuse provider context whose generation was never committed to the world.
        $db->prepare("UPDATE director_sessions SET state='unavailable' WHERE id=?")->execute([$local['records']['director_attempts'][0]['session_id']]);
        $d=$local['records']['director_attempts'][0];
        $decision+=['original_snapshot_sha256'=>$local['snapshot_sha256'],'original_state'=>$a['state'],'original_error_code'=>$a['error_code'],
            'original_estimated_microusd'=>$a['estimated_microusd'],'original_director_state'=>$d['state'],'original_director_estimated_microusd'=>$d['estimated_microusd'],
            'original_session_state'=>$local['records']['session']['state'],'original_reservation_microusd'=>(int)$a['reserved_microusd'],
            'commitment_microusd'=>$commitment,'monthly_total_microusd'=>$total,'state'=>$state,'reason'=>$within?'failed_attempt_accounted':'monthly_budget_cap','resolved_at'=>time()];
        appendReconciliation($db,'director_resolution:'.$review,$decision);$db->exec('COMMIT');return $decision+['replayed'=>false];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
