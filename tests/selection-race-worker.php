<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
class AiGuard extends \RuntimeException { public function __construct(public string $reason){parent::__construct($reason);} }
function directorEnabled(PDO $db):bool{return true;} function assertIdentityIdle(PDO $db):void{}
function villageSleepingAt(int $now):bool{return false;} function aiDayBoundaryGuard():int{return 60;}
function recoverExpiredAiLease(PDO $db,int $now):void{} function directorAccessReservedRequests(PDO $db,string $day):int{return 0;}
function sparkExtraRequests(PDO $db,string $day):int{return 0;} function aiInputTokenLimit():int{return 1000;}
function estimatedAiCost(int $i,int $o,int $ir,int $or):int{return 100;} function aiCommittedTotal(PDO $db,string $month):int{return selectionCommitted($db,$month);}
require __DIR__.'/../app/selection_accounting.php';
$db=new PDO('sqlite:'.$argv[1]);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=5000');
$now=(int)$argv[3];
if($argv[2]==='setup'){
 $db->exec("CREATE TABLE simulation_opportunities(id TEXT PRIMARY KEY,outcome TEXT,resident_a TEXT,resident_b TEXT,expires_at INTEGER);CREATE TABLE ai_lease(singleton INTEGER,owner TEXT);INSERT INTO ai_lease VALUES(1,NULL);CREATE TABLE ai_attempts(day TEXT,request_slots INTEGER);CREATE TABLE director_attempts(day TEXT,request_slots INTEGER)");
 $q=$db->prepare("INSERT INTO simulation_opportunities VALUES (?,'pending',NULL,NULL,?)");foreach(['one','two'] as $id)$q->execute([$id,$now+300]);
 $db->exec('BEGIN IMMEDIATE');migrateSelectionLedger($db);$db->exec('COMMIT');exit;
}
$cfg=['daily_requests'=>10,'monthly_cap'=>1000,'max_output'=>100,'input_rate'=>1,'output_rate'=>1,'lease_seconds'=>120];
try{reserveSelection($db,$argv[2],$cfg,$now);echo 'reserved';}catch(AiGuard $e){echo $e->reason;}