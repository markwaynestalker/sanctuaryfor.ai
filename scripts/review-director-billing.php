<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';
try{
    $o=getopt('',['database:','attempt:','review:','microusd:','evidence-file:','confirm-provider-evidence']);
    $amount=$o['microusd']??'';
    if(!isset($o['confirm-provider-evidence'])||!is_string($amount)||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/',$amount)||(int)$amount>100000000)throw new RuntimeException('Explicit evidence and canonical integer amount required.');
    $file=LittleSparks\databasePath((string)($o['evidence-file']??''));if(filesize($file)<1||filesize($file)>1048576)throw new RuntimeException('Bounded private evidence file required.');
    $evidence=hash_file('sha256',$file);$db=LittleSparks\connect((string)($o['database']??''));
    echo json_encode(LittleSparks\reviewManagedBilling($db,(string)($o['attempt']??''),(string)($o['review']??''),(int)$amount,$evidence),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Billing review refused. Require private V6 data, drained claims and independently verified provider evidence. No reservation was released.\n");exit(1);}
