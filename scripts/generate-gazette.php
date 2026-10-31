<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_job.php';
try{
    $o=getopt('',['database:','date:','allow-paid:','permit:']);
    if(($o['allow-paid']??null)!=='yes'||!is_string($o['database']??null)||$o['database']===''||!is_string($o['date']??null))throw new RuntimeException('Explicit paid job review required.');
    $policy=LittleSparks\editorialConfiguration();$bytes=LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES');
    if(LittleSparks\inspectSystemdCredential()['reason']!==null)throw new RuntimeException('Protected systemd credential delivery required.');
    $db=LittleSparks\connect($o['database']);$read=LittleSparks\connect($o['database']);$read->exec('PRAGMA query_only=ON');
    $result=LittleSparks\runEditorialJob($db,$read,$o['date'],$policy,$bytes,new LittleSparks\EditorialHttp(),null,$o['permit']??null);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit(in_array($result['status'],['awaiting_owner_review','quiet_or_input_bound'],true)?0:1);
}catch(Throwable){fwrite(STDERR,"Editorial generation refused or failed. Check owner-reviewed configuration and retained ledger evidence; never automatically retry.\n");exit(1);}
