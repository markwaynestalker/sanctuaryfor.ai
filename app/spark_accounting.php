<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/spark_schema.php';

/** Pending/uncertain work holds all planned slots; only new known terminal work closes unused headroom. */
function sparkExtraRequests(PDO $db,string $day):int
{
    if(!sparkLedgerInstalled($db))return 0;
    $q=$db->prepare("SELECT COALESCE(SUM(CASE WHEN s.state IN ('reserved','uncertain') THEN s.reserved_extra_slots ELSE s.charged_adjustment END),0) FROM spark_encounters s JOIN ai_attempts a ON a.id=s.attempt_id WHERE a.day=?");$q->execute([$day]);return (int)$q->fetchColumn();
}
function sparkExtraStarts(PDO $db,string $day):int
{
    if(!sparkLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM((SELECT COUNT(*) FROM spark_calls c WHERE c.attempt_id=s.attempt_id)-a.responses_started-a.moderation_started),0) FROM spark_encounters s JOIN ai_attempts a ON a.id=s.attempt_id WHERE a.day=?');$q->execute([$day]);return (int)$q->fetchColumn();
}
function startSparkCall(PDO $db,array $attempt,?string $resident,string $kind,int $deadline):int
{
    $db->exec('BEGIN IMMEDIATE');try{
        assertSimulationLease($db,$attempt['id']);if(time()>=$deadline)throw new AiGuard('execution_deadline');
        if(gmdate('Y-m-d')!==$attempt['day'])throw new AiGuard('day_boundary');
        if($kind==='reply'&&villageSleepingAt(time()))throw new AiGuard('night_rest');
        if($resident!==null){$q=$db->prepare('SELECT 1 FROM ai_attempts WHERE id=? AND (resident_a=? OR resident_b=?)');$q->execute([$attempt['id'],$resident,$resident]);if(!$q->fetchColumn())throw new AiGuard('invalid_proposal');}
        $q=$db->prepare('SELECT COUNT(*) FROM spark_calls WHERE attempt_id=?');$q->execute([$attempt['id']]);$sequence=(int)$q->fetchColumn();
        $db->prepare("INSERT INTO spark_calls(attempt_id,sequence,resident_id,kind,state,created_at) VALUES (?,?,?,?,'dispatched',?)")->execute([$attempt['id'],$sequence,$resident,$kind,gmdate('Y-m-d\TH:i:s\Z')]);
        // Parent ledger retains its two established slots; the new ledger accounts for every additional call.
        if($sequence===0||$kind==='moderation'){
            $field=$kind==='moderation'?'moderation_started':'responses_started';
            $q=$db->prepare("UPDATE ai_attempts SET $field=1 WHERE id=? AND $field=0");$q->execute([$attempt['id']]);if($q->rowCount()!==1)throw new AiGuard('request_already_started');
            $q=$db->prepare('UPDATE director_attempts SET started_requests=started_requests+1 WHERE id=? AND started_requests<request_slots');$q->execute([$attempt['id']]);if($q->rowCount()!==1)throw new AiGuard('encounter_request_cap');
        }
        $db->exec('COMMIT');return $sequence;
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function completeSparkCall(PDO $db,array $attempt,int $sequence,array $usage,?string $responseId=null):void
{
    $db->exec('BEGIN IMMEDIATE');try{
        assertSimulationLease($db,$attempt['id']);
        if($responseId!==null&&!preg_match('/\Aresp_[A-Za-z0-9_-]{1,120}\z/',$responseId))throw new AiGuard('usage_unknown');
        $q=$db->prepare("UPDATE spark_calls SET state='completed',input_tokens=?,output_tokens=?,cost_microusd=?,response_id=?,finished_at=? WHERE attempt_id=? AND sequence=? AND state='dispatched'");
        $q->execute([$usage['input_tokens'],$usage['output_tokens'],$usage['cost_microusd'],$responseId,gmdate('Y-m-d\TH:i:s\Z'),$attempt['id'],$sequence]);if($q->rowCount()!==1)throw new AiGuard('request_already_started');$db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Finalize new-mode commitments, never edit old holds or infer completion after a timeout. */
function closeSparkAccounting(PDO $db,array $attempt,bool $accepted,?array &$usage):void
{
    $db->exec('BEGIN IMMEDIATE');try{
        assertSimulationLease($db,$attempt['id']);
        $q=$db->prepare("SELECT COUNT(*) AS calls,COALESCE(SUM(state='dispatched'),0) AS pending,COALESCE(SUM(input_tokens),0) AS inputs,COALESCE(SUM(output_tokens),0) AS outputs,COALESCE(SUM(cost_microusd),0) AS cost FROM spark_calls WHERE attempt_id=?");$q->execute([$attempt['id']]);$u=$q->fetch();
        $known=(int)$u['pending']===0;$state=$known?($accepted?'completed':'rejected'):'uncertain';
        if($known){
            $usage=['input_tokens'=>(int)$u['inputs'],'output_tokens'=>(int)$u['outputs'],'cost_microusd'=>(int)$u['cost']];
            if($usage['cost_microusd']>$attempt['reserved'])throw new AiGuard('usage_unknown');
            $db->prepare("UPDATE ai_attempts SET input_tokens=?,output_tokens=?,estimated_microusd=?,state='settled' WHERE id=?")->execute([$usage['input_tokens'],$usage['output_tokens'],$usage['cost_microusd'],$attempt['id']]);
            $db->prepare('UPDATE director_attempts SET input_tokens=?,output_tokens=?,estimated_microusd=? WHERE id=?')->execute([$usage['input_tokens'],$usage['output_tokens'],$usage['cost_microusd'],$attempt['id']]);
        }else $usage=null;
        $db->prepare("UPDATE spark_encounters SET state=?,charged_adjustment=?,finished_at=? WHERE attempt_id=? AND state='reserved'")->execute([$state,$known?(int)$u['calls']-2:null,gmdate('Y-m-d\TH:i:s\Z'),$attempt['id']]);
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
