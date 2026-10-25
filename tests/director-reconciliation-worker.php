<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director_reconciliation.php';require __DIR__.'/director-reconciliation-mock.php';
try{
    $db=LittleSparks\connect($argv[1]);$mock=new ReconciliationMock(LittleSparks\directorAttemptInspection($db,$argv[2]));
    $r=LittleSparks\inspectDirectorProvider($db,$argv[2],$argv[3],$mock);echo json_encode(['accepted'=>$r['ready_for_billing_review']]);
}catch(LittleSparks\AiGuard $e){echo json_encode(['accepted'=>false,'reason'=>$e->reason]);}
