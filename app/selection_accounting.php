<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Additive ledger: no resident IDs or provider payloads are stored. */
function selectionLedgerInstalled(PDO $db):bool
{
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='selection_meta'")->fetchColumn())return false; if((int)$db->query('SELECT revision FROM selection_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported selection ledger revision');$db->query('SELECT * FROM selection_reservations LIMIT 0');return true;
}
function migrateSelectionLedger(PDO $db):void
{
    if(!directorEnabled($db))throw new \RuntimeException('V6 required');
    assertIdentityIdle($db);
    if(selectionLedgerInstalled($db))return;
    $db->exec("CREATE TABLE selection_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1)); INSERT INTO selection_meta VALUES(1,1); CREATE TRIGGER selection_meta_no_update BEFORE UPDATE ON selection_meta BEGIN SELECT RAISE(ABORT,'selection capability immutable'); END; CREATE TRIGGER selection_meta_no_delete BEFORE DELETE ON selection_meta BEGIN SELECT RAISE(ABORT,'selection capability immutable'); END; CREATE TABLE selection_reservations (
        id TEXT PRIMARY KEY, opportunity_id TEXT NOT NULL UNIQUE REFERENCES simulation_opportunities(id) ON DELETE RESTRICT,
        day TEXT NOT NULL, month TEXT NOT NULL, reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd BETWEEN 1 AND 1000000),
        request_slots INTEGER NOT NULL CHECK(request_slots=1), expires_at INTEGER NOT NULL,
        state TEXT NOT NULL CHECK(state IN ('reserved','dispatched','completed','uncertain')),
        request_id TEXT UNIQUE, created_at TEXT NOT NULL, finished_at TEXT,
        CHECK((state='reserved' AND request_id IS NULL AND finished_at IS NULL) OR
              (state='dispatched' AND request_id IS NOT NULL AND finished_at IS NULL) OR
              (state='completed' AND request_id IS NOT NULL AND finished_at IS NOT NULL) OR
              (state='uncertain' AND finished_at IS NOT NULL)));
        CREATE TRIGGER selection_no_delete BEFORE DELETE ON selection_reservations BEGIN SELECT RAISE(ABORT,'selection ledger immutable'); END;
        CREATE TRIGGER selection_transition BEFORE UPDATE ON selection_reservations WHEN
          NEW.id<>OLD.id OR NEW.opportunity_id<>OLD.opportunity_id OR NEW.day<>OLD.day OR NEW.month<>OLD.month OR
          NEW.reserved_microusd<>OLD.reserved_microusd OR NEW.request_slots<>OLD.request_slots OR NEW.expires_at<>OLD.expires_at OR NEW.created_at<>OLD.created_at OR
          NOT ((OLD.state='reserved' AND NEW.state IN ('dispatched','uncertain')) OR (OLD.state='dispatched' AND NEW.state IN ('completed','uncertain'))) OR
          (OLD.request_id IS NOT NULL AND NEW.request_id IS NOT OLD.request_id)
        BEGIN SELECT RAISE(ABORT,'selection transition refused'); END;");
}
function selectionReservedRequests(PDO $db,string $day):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM selection_reservations WHERE day=?');$q->execute([$day]);return (int)$q->fetchColumn();
}
function selectionStartedRequests(PDO $db,string $day):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COUNT(*) FROM selection_reservations WHERE day=? AND request_id IS NOT NULL');$q->execute([$day]);return (int)$q->fetchColumn();
}
function selectionCommitted(PDO $db,string $month):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM(reserved_microusd),0) FROM selection_reservations WHERE month=?');$q->execute([$month]);return (int)$q->fetchColumn();
}
function selectionLeaseActive(PDO $db,int $now):bool
{
    if(!selectionLedgerInstalled($db))return false;
    $q=$db->prepare("SELECT 1 FROM selection_reservations WHERE state IN ('reserved','dispatched') AND expires_at>? LIMIT 1");$q->execute([$now]);return (bool)$q->fetchColumn();
}
/** Caller owns the SQLite write lock. Never retries or releases a commitment. */
function recoverSelectionReservations(PDO $db,int $now):void
{
    if(!selectionLedgerInstalled($db))return;
    $q=$db->prepare("UPDATE selection_reservations SET state='uncertain',finished_at=? WHERE state IN ('reserved','dispatched') AND expires_at<=?");
    $q->execute([gmdate('Y-m-d\TH:i:s\Z',$now),$now]);
}
function reserveSelection(PDO $db,string $opportunity,array $pricing,int $now):array
{
    if(!selectionLedgerInstalled($db))throw new AiGuard('migration_required');
    if(villageSleepingAt($now))throw new AiGuard('night_rest');
    if(86400-$now%86400<aiDayBoundaryGuard())throw new AiGuard('day_boundary');
    $db->exec('BEGIN IMMEDIATE');
    try{
        recoverExpiredAiLease($db,$now);recoverSelectionReservations($db,$now);
        if(selectionLeaseActive($db,$now)||$db->query('SELECT owner FROM ai_lease WHERE singleton=1')->fetchColumn()!==null)throw new AiGuard('simulation_busy');
        $seen=$db->prepare('SELECT 1 FROM selection_reservations WHERE opportunity_id=?');$seen->execute([$opportunity]);if($seen->fetchColumn())throw new AiGuard('configuration_invalid');
        $q=$db->prepare("SELECT expires_at FROM simulation_opportunities WHERE id=? AND outcome='pending' AND resident_a IS NULL AND resident_b IS NULL AND expires_at>?");
        $q->execute([$opportunity,$now]);$opExpiry=$q->fetchColumn();if($opExpiry===false)throw new AiGuard('configuration_invalid');
        $day=gmdate('Y-m-d',$now);$month=gmdate('Y-m',$now);
        $q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM ai_attempts WHERE day=?');$q->execute([$day]);$used=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT COALESCE(SUM(request_slots-2),0) FROM director_attempts WHERE day=?');$q->execute([$day]);
        $used+=(int)$q->fetchColumn()+directorAccessReservedRequests($db,$day)+sparkExtraRequests($db,$day)+selectionReservedRequests($db,$day);
        if($used>=$pricing['daily_requests'])throw new AiGuard('daily_request_cap');
        $amount=estimatedAiCost(aiInputTokenLimit(),$pricing['max_output'],$pricing['input_rate'],$pricing['output_rate']);
        if($amount<1||$amount>1000000)throw new AiGuard('configuration_invalid');
        if(aiCommittedTotal($db,$month)>$pricing['monthly_cap']-$amount)throw new AiGuard('monthly_budget_cap');
        $id=bin2hex(random_bytes(16));$expires=min((int)$opExpiry,$now+$pricing['lease_seconds']);
        $q=$db->prepare("INSERT INTO selection_reservations VALUES (?,?,?,?,?,1,?,'reserved',NULL,?,NULL)");
        $q->execute([$id,$opportunity,$day,$month,$amount,$expires,gmdate('Y-m-d\TH:i:s\Z',$now)]);
        $db->exec('COMMIT');return ['id'=>$id,'day'=>$day,'expires'=>$expires,'reserved'=>$amount];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Unique durable dispatch intent; callers must commit it before provider I/O. */
function startSelection(PDO $db,array $reservation,int $now):string
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        if(villageSleepingAt($now)||gmdate('Y-m-d',$now)!==$reservation['day'])throw new AiGuard('day_boundary');
        $request=bin2hex(random_bytes(16));
        $q=$db->prepare("UPDATE selection_reservations SET state='dispatched',request_id=? WHERE id=? AND state='reserved' AND expires_at>?");
        $q->execute([$request,$reservation['id'],$now]);if($q->rowCount()!==1)throw new AiGuard('request_already_started');
        $db->exec('COMMIT');return $request;
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Completion records a known response only; the conservative reservation stays charged. */
function finishSelection(PDO $db,array $reservation,bool $known,int $now):void
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare("UPDATE selection_reservations SET state=?,finished_at=? WHERE id=? AND state='dispatched' AND expires_at>?");
        $q->execute([$known?'completed':'uncertain',gmdate('Y-m-d\TH:i:s\Z',$now),$reservation['id'],$now]);
        if($q->rowCount()!==1)throw new AiGuard('request_already_started');
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}