<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_ledger.php';
try{$job=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$db=LittleSparks\connect($job['database']);
    if(($argv[2]??null)==='audit'){$db->exec('PRAGMA query_only=ON');echo json_encode($db->query('SELECT COUNT(*) AS attempts,SUM(reserved_microusd) AS commitment,SUM(request_slots) AS slots FROM gazette_editor_attempts')->fetch(),JSON_THROW_ON_ERROR);exit;}
    LittleSparks\reserveEditorialAttempt($db,$job['snapshot'],$job['policy'],$job['now']);echo "reserved\n";
}catch(Throwable){echo "refused\n";exit(42);}
