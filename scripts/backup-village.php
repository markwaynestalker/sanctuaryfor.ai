<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/maintenance.php';
try{
    $o=getopt('',['database:','output:']);$db=LittleSparks\connect((string)($o['database']??''));
    echo json_encode(LittleSparks\backupVillage($db,(string)($o['output']??'')),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable){fwrite(STDERR,"Backup refused or verification failed. Require private paths, a new destination and fully drained writers. Preserve any output for inspection; never overwrite it.\n");exit(1);}
