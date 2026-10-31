<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_broker.php';
// Only the keyless, peer-authenticating adapter launches this fixed stdin command.
// Running it as the DB owner directly grants no privilege that owner lacks already.
try{
    if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0)throw new RuntimeException();
    $input=stream_get_contents(STDIN,524289);if(strlen($input)>524288)throw new RuntimeException();$m=json_decode($input,true,40,JSON_THROW_ON_ERROR);
    LittleSparks\editorialKeys($m,['peer_uid','release','database','approved_date','message']);
    if(!is_int($m['peer_uid'])||$m['peer_uid']<=0||$m['peer_uid']===posix_geteuid()||!preg_match('/\A[a-f0-9]{40}\z/',$m['release']))throw new RuntimeException();
    $path=LittleSparks\databasePath($m['database']);if(fileowner($path)!==posix_geteuid())throw new RuntimeException();
    $policy=LittleSparks\editorialConfiguration(false);$bytes=LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES');
    if(($m['message']['op']??'')==='ready'){
        $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);$db->exec('PRAGMA query_only=ON');
        $read=$db;
    }else{$db=LittleSparks\connect($path);$read=LittleSparks\connect($path);$read->exec('PRAGMA query_only=ON');}
    $result=LittleSparks\gazetteBrokerHandle($db,$read,$m['message'],$policy,$bytes,$m['release'],$m['peer_uid'],time(),getenv('LITTLE_SPARKS_GAZETTE_EDITOR_ENABLED')==='1',$m['approved_date']);
    echo LittleSparks\editorialJson($result);
}catch(Throwable){echo '{"status":"refused_or_uncertain","action":"inspect_ledger_do_not_retry"}';exit(1);}
