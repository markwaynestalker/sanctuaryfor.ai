<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director_reconciliation.php';require __DIR__.'/director-reconciliation-mock.php';
// Test-only synchronization immediately before a real SQLite BEGIN. No provider
// transport, retry, timeout change in application code, or wall-clock race.
final class ReconciliationBarrierPDO extends PDO
{
    private int $begins=0;
    public function __construct(string $path,private int $pauseAt){
        parent::__construct('sqlite:'.LittleSparks\databasePath($path),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->exec('PRAGMA foreign_keys=ON');$this->exec('PRAGMA busy_timeout=50');
    }
    public function exec(string $statement):int|false{
        if($statement==='BEGIN IMMEDIATE'&&++$this->begins===$this->pauseAt){
            echo json_encode(['phase'=>'before_begin','number'=>$this->begins])."\n";flush();
            if(trim((string)fgets(STDIN))!=='continue')throw new RuntimeException('Contention barrier refused');
        }
        return parent::exec($statement);
    }
}
$mock=null;
try{
    $db=isset($argv[4])?new ReconciliationBarrierPDO($argv[1],(int)$argv[4]):LittleSparks\connect($argv[1]);$mock=new ReconciliationMock(LittleSparks\directorAttemptInspection($db,$argv[2]));
    $r=LittleSparks\inspectDirectorProvider($db,$argv[2],$argv[3],$mock);echo json_encode(['accepted'=>$r['ready_for_billing_review'],'calls'=>count($mock->calls),'reason'=>$r['reason']]);
}catch(LittleSparks\AiGuard $e){echo json_encode(['accepted'=>false,'reason'=>$e->reason,'calls'=>count($mock?->calls??[])]);}
