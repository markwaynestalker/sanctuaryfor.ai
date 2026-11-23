<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
class AiGuard extends \RuntimeException { public function __construct(public string $reason){parent::__construct($reason);} }
function directorEnabled(PDO $db):bool{return true;}
function assertIdentityIdle(PDO $db):void{}
function villageSleepingAt(int $now):bool{return false;}
function aiDayBoundaryGuard():int{return 60;}
function recoverExpiredAiLease(PDO $db,int $now):void{}
function directorAccessReservedRequests(PDO $db,string $day):int{return 0;}
function sparkExtraRequests(PDO $db,string $day):int{return 0;}
function aiInputTokenLimit():int{return 1000;}
function estimatedAiCost(int $i,int $o,int $ir,int $or):int{return 100;}
function aiCommittedTotal(PDO $db,string $month):int{return selectionCommitted($db,$month);}
require __DIR__.'/../app/selection_accounting.php';
function check(bool $v,string $s):void {if(!$v)throw new \RuntimeException($s);}
function refuse(callable $f,string $reason):void {try{$f();throw new \RuntimeException('unexpected acceptance');}catch(AiGuard $e){check($e->reason===$reason,'wrong failure: '.$e->reason);}}
$path=tempnam(sys_get_temp_dir(),'selection');$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');
try{
    $db->exec("CREATE TABLE simulation_opportunities(id TEXT PRIMARY KEY,outcome TEXT,resident_a TEXT,resident_b TEXT,expires_at INTEGER); CREATE TABLE ai_lease(singleton INTEGER,owner TEXT); INSERT INTO ai_lease VALUES(1,NULL); CREATE TABLE ai_attempts(id TEXT PRIMARY KEY,day TEXT,request_slots INTEGER); CREATE TABLE director_attempts(day TEXT,request_slots INTEGER)");
    $now=time();if(86400-$now%86400<120)$now-=180;
    $q=$db->prepare("INSERT INTO simulation_opportunities VALUES (?,'pending',NULL,NULL,?)");foreach(['one','two','three','four'] as $id)$q->execute([$id,$now+300]);
    $db->exec('BEGIN IMMEDIATE');migrateSelectionLedger($db);$db->exec('ROLLBACK');check(!selectionLedgerInstalled($db),'migration rollback');
    $db->exec('BEGIN IMMEDIATE');migrateSelectionLedger($db);$db->exec('COMMIT');$db->exec('BEGIN IMMEDIATE');migrateSelectionCapacity($db);$db->exec('ROLLBACK');check(!selectionCapacityInstalled($db),'capacity migration rollback');$db->exec('BEGIN IMMEDIATE');migrateSelectionCapacity($db);$db->exec('COMMIT');
    $cfg=['daily_requests'=>10,'monthly_cap'=>1000,'max_output'=>100,'input_rate'=>1,'output_rate'=>1,'lease_seconds'=>120,'director_runtime'=>'responses','director_slots'=>2];
    $r=reserveSelection($db,'one',$cfg,$now);check(selectionCommitted($db,gmdate('Y-m',$now))===100,'monthly commitment');
    check(selectionReservedRequests($db,gmdate('Y-m-d',$now))===1,'daily capacity');
    $second=new PDO('sqlite:'.$path);$second->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    refuse(fn()=>reserveSelection($second,'two',$cfg,$now),'simulation_busy');
    $request=startSelection($db,$r,$now);check(strlen($request)===32,'unique request identity');
    refuse(fn()=>startSelection($db,$r,$now),'request_already_started');
    finishSelection($db,$r,true,$now);check(selectionCommitted($db,gmdate('Y-m',$now))===100,'completed selection stays conservatively charged');
    refuse(fn()=>reserveSelection($db,'one',$cfg,$now),'configuration_invalid');
    $low=$cfg;$low['daily_requests']=1;refuse(fn()=>reserveSelection($db,'two',$low,$now),'daily_request_cap');
    $low=$cfg;$low['monthly_cap']=100;refuse(fn()=>reserveSelection($db,'two',$low,$now),'monthly_budget_cap');
    $r2=reserveSelection($db,'two',$cfg,$now,$cfg);startSelection($db,$r2,$now);
    $db->exec('BEGIN IMMEDIATE');recoverSelectionReservations($db,$now+121);$db->exec('COMMIT');
    check($db->query("SELECT state FROM selection_reservations WHERE opportunity_id='two'")->fetchColumn()==='uncertain','expired dispatch held uncertain');
    refuse(fn()=>finishSelection($db,$r2,true,$now+121),'request_already_started');
    check(selectionCommitted($db,gmdate('Y-m',$now))===300,'uncertain selection and conversation holds retained');check(selectionReservedRequests($db,gmdate('Y-m-d',$now))===4,'expired selection and conversation slots retained');
    $db->exec('BEGIN IMMEDIATE');$db->exec("UPDATE selection_reservations SET state='uncertain',finished_at='x' WHERE 0");$db->exec('ROLLBACK');
    check($db->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&!$db->query('PRAGMA foreign_key_check')->fetch(),'integrity');
    echo "PASS selection reservation admission, serialized lease, dispatch identity, expiry, conservative accounting and migration rollback\n";
}finally{$db=null;@unlink($path);}