<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/director_reconciliation.php';

/** Explicit owner operation, never called by the scheduler or public readers. */
function settleDirectorBilling(PDO $db,string $id,string $snapshot,string $review,int $amount,string $evidenceHash):array
{
    reconciliationId($id);reconciliationId($review);
    if(PHP_SAPI!=='cli'||!preg_match('/\A[a-f0-9]{64}\z/',$snapshot)||!preg_match('/\A[a-f0-9]{64}\z/',$evidenceHash)||$amount<0||$amount>100000000)throw new AiGuard('configuration_invalid');
    $decision=['schema'=>'sanctuary.managed-billing-settlement.v1','attempt_id'=>$id,'snapshot_sha256'=>$snapshot,'review_id'=>$review,'amount_microusd'=>$amount,'evidence_sha256'=>$evidenceHash];
    beginReconciliation($db);try{
        reconciliationIdle($db);$key='director_billing_settlement:'.$id;$prior=reconciliationValue($db,$key);
        if($prior!==null){foreach($decision as $k=>$v)if(($prior[$k]??null)!==$v)throw new AiGuard('configuration_invalid');$db->exec('COMMIT');return $prior+['replayed'=>true];}
        $local=directorAttemptInspection($db,$id);$a=$local['records']['ai_attempts'][0];
        if(!hash_equals($snapshot,$local['snapshot_sha256'])||$a['month']!==gmdate('Y-m')||!in_array($a['state'],['uncertain','applied','rejected'],true))throw new AiGuard('accounting_unresolved');
        $outcomes=$local['records']['director_managed_outcomes'];$terminal=end($outcomes);
        if(!$terminal||!in_array($terminal['status'],['completed','failed','cancelled'],true)||!is_string($terminal['turn_id']))throw new AiGuard('accounting_unresolved');
        // Do not sum repeated/cumulative observations or count reasoning twice.
        // Even attested final billing never erases measurable higher usage.
        $commitment=$amount;
        foreach($local['records']['director_usage_observations'] as $o)$commitment=max($commitment,(int)($o['estimated_microusd']??0));
        foreach($local['records']['director_billing_reviews'] as $r)$commitment=max($commitment,(int)$r['amount_microusd']);
        $db->prepare("INSERT INTO director_billing_reviews VALUES (?,?,?,?,?,'reviewed',?)")->execute([$review,$id,$evidenceHash,$amount,$commitment,gmdate('Y-m-d\TH:i:s\Z')]);
        $decision+=['commitment_microusd'=>$commitment,'previous_reserved_microusd'=>(int)$a['reserved_microusd'],'month'=>$a['month'],'turn_id'=>$terminal['turn_id'],'reviewed_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        appendReconciliation($db,$key,$decision);$db->exec('COMMIT');return $decision+['replayed'=>false];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw reconciliationFailure($e);}
}
