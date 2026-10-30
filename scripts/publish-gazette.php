<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette.php';
try{
    $options=getopt('',['database:','date:']);if(!isset($options['date']))throw new RuntimeException('Specify a finished Eastern date.');
    $database=(string)($options['database']??'');$now=time();$day=LittleSparks\gazetteDay($options['date'],$now);$db=LittleSparks\connect($database);$db->exec('PRAGMA query_only=ON');
    echo json_encode(LittleSparks\freezeGazette($db,$database,$day,$now),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Gazette publication refused. Require a private database, a finished Eastern date and valid public sources. No database or existing edition was overwritten.\n");exit(1);}
