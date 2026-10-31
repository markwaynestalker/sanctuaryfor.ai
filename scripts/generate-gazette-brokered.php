<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_broker_client.php';
try{
    $o=getopt('',['allow-paid:','check']);$check=array_key_exists('check',$o);
    if(!$check&&($o['allow-paid']??null)!=='yes')throw new RuntimeException();
    $policy=LittleSparks\editorialConfiguration(!$check);$bytes=LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES');
    if(LittleSparks\inspectSystemdCredential()['reason']!==null)throw new RuntimeException();
    $release=getenv('LITTLE_SPARKS_GAZETTE_EDITOR_RELEASE_SHA');
    $result=LittleSparks\runBrokeredEditorialJob(new LittleSparks\GazetteUnixBroker(),new LittleSparks\EditorialHttp(),$policy,$bytes,is_string($release)?$release:'',$check);
    echo LittleSparks\editorialJson($result)."\n";exit(in_array($result['status'],['preflight_ready','quiet_or_input_bound','awaiting_owner_review'],true)?0:1);
}catch(Throwable){echo "{\"status\":\"refused_or_uncertain\",\"action\":\"inspect_ledger_do_not_retry\"}\n";exit(1);}
