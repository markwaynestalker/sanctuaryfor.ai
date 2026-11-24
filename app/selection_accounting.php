<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Match the Director's existing scope: participant IDs, identity revisions and context. */
function selectionDirectorScope(PDO $db,array $pair,string $runtime,string $contract,?array $contextScope,bool $strict=false):string
{
    $ids=array_column($pair,'id');sort($ids);$revisions=[];
    foreach($ids as $id){$q=$db->prepare('SELECT revision FROM resident_identities WHERE resident_id=?');$q->execute([$id]);$revision=$q->fetchColumn();if($revision===false&&$strict)throw new AiGuard('invalid_proposal');$revisions[$id]=(int)$revision;}
    $scopeParts=[$ids,$revisions,$runtime];if($contract!=='sanctuary.director.v1')$scopeParts[]=$contract;
    if(str_ends_with($contract,'.v2'))$scopeParts[]=conversationConfiguration();
    $scopeParts[]=$contextScope??['input_context'=>'sqlite-v6-6','published_reporting'=>null];
    return hash('sha256',json_encode($scopeParts,JSON_THROW_ON_ERROR));
}
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
/** Separate, immutable pending conversation capacity; no participant identity is invented. */
function selectionCapacityInstalled(PDO $db):bool
{
    if(!selectionLedgerInstalled($db))return false;
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='selection_capacity_meta'")->fetchColumn())return false;if((int)$db->query('SELECT revision FROM selection_capacity_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported selection capacity revision');$db->query('SELECT * FROM selection_conversation_capacity LIMIT 0');$db->query('SELECT * FROM selection_capacity_transfers LIMIT 0');return true;
}
function migrateSelectionCapacity(PDO $db):void
{
    if(!selectionLedgerInstalled($db))throw new \RuntimeException('Selection ledger required');
    assertIdentityIdle($db);
    if(selectionCapacityInstalled($db))return;
    $db->exec("CREATE TABLE selection_capacity_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1)); INSERT INTO selection_capacity_meta VALUES (1,1); CREATE TRIGGER selection_capacity_meta_no_update BEFORE UPDATE ON selection_capacity_meta BEGIN SELECT RAISE(ABORT,'immutable'); END; CREATE TRIGGER selection_capacity_meta_no_delete BEFORE DELETE ON selection_capacity_meta BEGIN SELECT RAISE(ABORT,'immutable'); END; CREATE TABLE selection_conversation_capacity (
        selection_id TEXT PRIMARY KEY REFERENCES selection_reservations(id) ON DELETE RESTRICT,
        reserved_microusd INTEGER NOT NULL CHECK(reserved_microusd BETWEEN 1 AND 1000000),
        request_slots INTEGER NOT NULL CHECK(request_slots BETWEEN 2 AND 8));
        CREATE TABLE selection_capacity_transfers (
        selection_id TEXT PRIMARY KEY REFERENCES selection_conversation_capacity(selection_id) ON DELETE RESTRICT,
        attempt_id TEXT NOT NULL UNIQUE REFERENCES ai_attempts(id) ON DELETE RESTRICT,
        created_at TEXT NOT NULL);
        CREATE TRIGGER selection_capacity_immutable_update BEFORE UPDATE ON selection_conversation_capacity BEGIN SELECT RAISE(ABORT,'immutable'); END;
        CREATE TRIGGER selection_capacity_immutable_delete BEFORE DELETE ON selection_conversation_capacity BEGIN SELECT RAISE(ABORT,'immutable'); END;
        CREATE TRIGGER selection_transfer_immutable_update BEFORE UPDATE ON selection_capacity_transfers BEGIN SELECT RAISE(ABORT,'immutable'); END;
        CREATE TRIGGER selection_transfer_immutable_delete BEFORE DELETE ON selection_capacity_transfers BEGIN SELECT RAISE(ABORT,'immutable'); END;
        CREATE TRIGGER selection_bound_pair_immutable BEFORE UPDATE OF resident_a,resident_b ON simulation_opportunities
        WHEN (NEW.resident_a IS NOT OLD.resident_a OR NEW.resident_b IS NOT OLD.resident_b) AND EXISTS(
        SELECT 1 FROM selection_capacity_transfers t JOIN selection_reservations s ON s.id=t.selection_id WHERE s.opportunity_id=OLD.id)
        BEGIN SELECT RAISE(ABORT,'selected participants immutable'); END;");
}
function selectionPendingCapacity(PDO $db,string $field,string $value):int
{
    if(!selectionCapacityInstalled($db))return 0;
    if(!in_array($field,['day','month'],true))throw new \RuntimeException('Invalid capacity window');
    $column=$field==='day'?'request_slots':'reserved_microusd';
    $q=$db->prepare("SELECT COALESCE(SUM(c.$column),0) FROM selection_conversation_capacity c JOIN selection_reservations s ON s.id=c.selection_id LEFT JOIN selection_capacity_transfers t ON t.selection_id=c.selection_id WHERE s.$field=? AND t.selection_id IS NULL");
    $q->execute([$value]);return (int)$q->fetchColumn();
}
/** Additive managed-selection accounting; legacy rows and one-request mode stay unchanged. */
function managedSelectionInstalled(PDO $db):bool
{
    if(!selectionCapacityInstalled($db))return false;
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='managed_selection_meta'")->fetchColumn())return false;
    if((int)$db->query('SELECT revision FROM managed_selection_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported managed selection revision');
    $db->query('SELECT * FROM managed_selection_reservations LIMIT 0');$db->query('SELECT * FROM managed_selection_sessions LIMIT 0');$db->query('SELECT * FROM managed_selection_http LIMIT 0');$db->query('SELECT * FROM managed_selection_usage LIMIT 0');return true;
}
function migrateManagedSelection(PDO $db):void
{
    if(!selectionCapacityInstalled($db))throw new \RuntimeException('Selection capacity ledger required');
    assertIdentityIdle($db);if(managedSelectionInstalled($db))return;
    $db->exec("CREATE TABLE managed_selection_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO managed_selection_meta VALUES(1,1);
        CREATE TABLE managed_selection_reservations(selection_id TEXT PRIMARY KEY REFERENCES selection_reservations(id) ON DELETE RESTRICT,
            extra_slots INTEGER NOT NULL CHECK(extra_slots=7), model TEXT NOT NULL, input_rate INTEGER NOT NULL CHECK(input_rate BETWEEN 1 AND 1000000000), output_rate INTEGER NOT NULL CHECK(output_rate BETWEEN 1 AND 1000000000));
        CREATE TABLE managed_selection_sessions(selection_id TEXT PRIMARY KEY REFERENCES managed_selection_reservations(selection_id) ON DELETE RESTRICT,session_id TEXT NOT NULL); CREATE TABLE managed_selection_http(selection_id TEXT NOT NULL REFERENCES managed_selection_reservations(selection_id) ON DELETE RESTRICT,
            sequence INTEGER NOT NULL CHECK(sequence BETWEEN 1 AND 8),kind TEXT NOT NULL,method TEXT NOT NULL,
            path_sha256 TEXT NOT NULL,request_identity TEXT NOT NULL UNIQUE,outcome TEXT NOT NULL CHECK(outcome IN ('started','succeeded','uncertain')),
            http_status INTEGER,provider_request_id TEXT,started_at TEXT NOT NULL,finished_at TEXT,
            PRIMARY KEY(selection_id,sequence),UNIQUE(selection_id,kind),
            CHECK((outcome='started' AND finished_at IS NULL AND http_status IS NULL AND provider_request_id IS NULL) OR
                  (outcome IN ('succeeded','uncertain') AND finished_at IS NOT NULL)));
        CREATE TABLE managed_selection_usage(id INTEGER PRIMARY KEY,selection_id TEXT NOT NULL REFERENCES managed_selection_reservations(selection_id) ON DELETE RESTRICT,
            turn_id TEXT NOT NULL,input_tokens INTEGER,output_tokens INTEGER,estimated_microusd INTEGER,status TEXT NOT NULL CHECK(status IN ('observed','unknown')),observed_at TEXT NOT NULL,
            CHECK((status='unknown' AND input_tokens IS NULL AND output_tokens IS NULL AND estimated_microusd IS NULL) OR
                  (status='observed' AND input_tokens>0 AND output_tokens>0 AND estimated_microusd>=0)));
        CREATE TRIGGER managed_selection_call_complete BEFORE UPDATE ON managed_selection_http WHEN
          OLD.outcome<>'started' OR NEW.outcome NOT IN ('succeeded','uncertain') OR NEW.selection_id<>OLD.selection_id OR
          NEW.sequence<>OLD.sequence OR NEW.kind<>OLD.kind OR NEW.method<>OLD.method OR NEW.path_sha256<>OLD.path_sha256 OR
          NEW.request_identity<>OLD.request_identity OR NEW.started_at<>OLD.started_at
          BEGIN SELECT RAISE(ABORT,'managed selection audit immutable'); END;");
    foreach(['managed_selection_meta','managed_selection_reservations','managed_selection_sessions','managed_selection_http','managed_selection_usage'] as $t)$db->exec("CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON $t BEGIN SELECT RAISE(ABORT,'managed selection audit immutable'); END;");
    foreach(['managed_selection_meta','managed_selection_reservations','managed_selection_sessions','managed_selection_usage'] as $t)$db->exec("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON $t BEGIN SELECT RAISE(ABORT,'managed selection reservation immutable'); END;");
}
function managedSelectionExtraSlots(PDO $db,string $day):int
{
    if(!managedSelectionInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM(m.extra_slots),0) FROM managed_selection_reservations m JOIN selection_reservations s ON s.id=m.selection_id WHERE s.day=?');$q->execute([$day]);return (int)$q->fetchColumn();
}
function managedSelectionExtraStarts(PDO $db,string $day):int
{
    if(!managedSelectionInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM(MAX(0,(SELECT COUNT(*) FROM managed_selection_http h WHERE h.selection_id=m.selection_id)-1)),0) FROM managed_selection_reservations m JOIN selection_reservations s ON s.id=m.selection_id WHERE s.day=?');$q->execute([$day]);return (int)$q->fetchColumn();
}
function selectionReservedRequests(PDO $db,string $day):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COALESCE(SUM(request_slots),0) FROM selection_reservations WHERE day=?');$q->execute([$day]);return (int)$q->fetchColumn()+selectionPendingCapacity($db,'day',$day)+managedSelectionExtraSlots($db,$day);
}
function selectionStartedRequests(PDO $db,string $day):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $q=$db->prepare('SELECT COUNT(*) FROM selection_reservations WHERE day=? AND request_id IS NOT NULL');$q->execute([$day]);return (int)$q->fetchColumn()+managedSelectionExtraStarts($db,$day);
}
function selectionCommitted(PDO $db,string $month):int
{
    if(!selectionLedgerInstalled($db))return 0;
    $sql=managedSelectionInstalled($db)
        ?'SELECT COALESCE(SUM(MAX(s.reserved_microusd,COALESCE((SELECT MAX(u.estimated_microusd) FROM managed_selection_usage u WHERE u.selection_id=s.id),0))),0) FROM selection_reservations s WHERE s.month=?'
        :'SELECT COALESCE(SUM(reserved_microusd),0) FROM selection_reservations WHERE month=?';
    $q=$db->prepare($sql);$q->execute([$month]);return (int)$q->fetchColumn()+selectionPendingCapacity($db,'month',$month);
}
function selectionLeaseActive(PDO $db,int $now):bool
{
    if(!selectionLedgerInstalled($db))return false;
    $q=$db->prepare("SELECT 1 FROM selection_reservations s JOIN simulation_opportunities o ON o.id=s.opportunity_id WHERE s.state IN ('reserved','dispatched') AND s.expires_at>? AND o.outcome='pending' LIMIT 1");$q->execute([$now]);if($q->fetchColumn())return true;if(!selectionCapacityInstalled($db))return false;$q=$db->prepare("SELECT 1 FROM selection_conversation_capacity c JOIN selection_reservations s ON s.id=c.selection_id LEFT JOIN selection_capacity_transfers t ON t.selection_id=c.selection_id JOIN simulation_opportunities o ON o.id=s.opportunity_id WHERE t.selection_id IS NULL AND s.state='completed' AND s.expires_at>? AND o.outcome='pending' LIMIT 1");$q->execute([$now]);return (bool)$q->fetchColumn();
}
/** Caller owns the SQLite write lock. Never retries or releases a commitment. */
function recoverSelectionReservations(PDO $db,int $now):void
{
    if(!selectionLedgerInstalled($db))return;
    $q=$db->prepare("UPDATE selection_reservations SET state='uncertain',finished_at=? WHERE state IN ('reserved','dispatched') AND expires_at<=?");
    $q->execute([gmdate('Y-m-d\TH:i:s\Z',$now),$now]);
}
function reserveSelection(PDO $db,string $opportunity,array $pricing,int $now,?array $conversation=null,bool $managedSelection=false):array
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
        $capacitySlots=0;$capacityAmount=0;
        if($conversation!==null){
            if(!selectionCapacityInstalled($db)||!in_array($conversation['director_runtime']??null,['agents','responses'],true)||isset($conversation['spark_generation_calls'])||isset($conversation['staging_allocation']))throw new AiGuard('configuration_invalid');
            $capacitySlots=$conversation['director_slots']??2;
            if(!is_int($capacitySlots)||$capacitySlots<2||$capacitySlots>8||!is_int($conversation['max_output']??null)||!is_int($conversation['input_rate']??null)||!is_int($conversation['output_rate']??null)||$conversation['max_output']<1||$conversation['input_rate']<1||$conversation['output_rate']<1)throw new AiGuard('configuration_invalid');
            $capacityAmount=estimatedAiCost(aiInputTokenLimit(),$conversation['max_output'],$conversation['input_rate'],$conversation['output_rate']);
            if($conversation['director_runtime']==='agents')$capacityAmount=max($capacityAmount,aiManagedMinimumCommitment());
            if($capacityAmount<1||$capacityAmount>1000000)throw new AiGuard('configuration_invalid');
        }
        if($managedSelection&&(!managedSelectionInstalled($db)||$conversation===null||($conversation['director_runtime']??null)!=='agents'||!is_string($pricing['model']??null)||!in_array($pricing['model'],['gpt-6-luna','gpt-6.1-sol'],true)||!is_int($pricing['input_rate']??null)||!is_int($pricing['output_rate']??null)||$pricing['input_rate']<1||$pricing['input_rate']>1000000000||$pricing['output_rate']<1||$pricing['output_rate']>1000000000))throw new AiGuard('configuration_invalid');if($used>$pricing['daily_requests']-1-$capacitySlots-($managedSelection?7:0))throw new AiGuard('daily_request_cap');
        $amount=estimatedAiCost(aiInputTokenLimit(),$pricing['max_output'],$pricing['input_rate'],$pricing['output_rate']);
        if($managedSelection)$amount=max($amount,aiManagedMinimumCommitment());if($amount<1||$amount>1000000)throw new AiGuard('configuration_invalid');
        if(aiCommittedTotal($db,$month)>$pricing['monthly_cap']-$amount-$capacityAmount)throw new AiGuard('monthly_budget_cap');
        $id=bin2hex(random_bytes(16));$expires=min((int)$opExpiry,$now+$pricing['lease_seconds']);
        $q=$db->prepare("INSERT INTO selection_reservations VALUES (?,?,?,?,?,1,?,'reserved',NULL,?,NULL)");
        $q->execute([$id,$opportunity,$day,$month,$amount,$expires,gmdate('Y-m-d\TH:i:s\Z',$now)]);if($conversation!==null)$db->prepare('INSERT INTO selection_conversation_capacity VALUES (?,?,?)')->execute([$id,$capacityAmount,$capacitySlots]);if($managedSelection)$db->prepare('INSERT INTO managed_selection_reservations VALUES (?,7,?,?,?)')->execute([$id,$pricing['model'],$pricing['input_rate'],$pricing['output_rate']]);
        $db->exec('COMMIT');return ['id'=>$id,'day'=>$day,'expires'=>$expires,'reserved'=>$amount];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** Unique durable dispatch intent; callers must commit it before provider I/O. */
function startSelection(PDO $db,array $reservation,int $now):string
{
    $db->exec('BEGIN IMMEDIATE');
    try{
        if(villageSleepingAt($now)||gmdate('Y-m-d',$now)!==$reservation['day'])throw new AiGuard('day_boundary');if(managedSelectionInstalled($db)){$q=$db->prepare('SELECT 1 FROM managed_selection_reservations WHERE selection_id=?');$q->execute([$reservation['id']]);if($q->fetchColumn())throw new AiGuard('configuration_invalid');}
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
        if(managedSelectionInstalled($db)){
            $q=$db->prepare('SELECT 1 FROM managed_selection_reservations WHERE selection_id=?');$q->execute([$reservation['id']]);
            $isManaged=(bool)$q->fetchColumn();if($isManaged)$now=max($now,time());if($isManaged&&$known){
                $q=$db->prepare("SELECT kind,outcome FROM managed_selection_http WHERE selection_id=?");$q->execute([$reservation['id']]);$calls=$q->fetchAll();
                if(!in_array('items',array_column($calls,'kind'),true)||in_array('cancel',array_column($calls,'kind'),true)||array_filter($calls,static fn($c)=>$c['outcome']!=='succeeded'))throw new AiGuard('transport_uncertain');
            }
        }
        $q=$db->prepare("UPDATE selection_reservations SET state=?,finished_at=? WHERE id=? AND state='dispatched' AND expires_at>?");
        $q->execute([$known?'completed':'uncertain',gmdate('Y-m-d\TH:i:s\Z',$now),$reservation['id'],$now]);
        if($q->rowCount()!==1)throw new AiGuard('request_already_started');
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}