<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';require __DIR__.'/../../app/conversation_archive.php';
sparksHeaders();ini_set('display_errors','0');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){header('Allow: GET');throw new LittleSparks\Problem(405,'Use GET to read the conversation archive.');}
    LittleSparks\archiveParameters($_GET);
    $db=LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB'));$db->exec('PRAGMA query_only=ON');
    sparksReply(LittleSparks\conversationArchive($db,$_GET));
}catch(LittleSparks\Problem $e){sparksReply(['error'=>$e->getMessage()],$e->status);}
catch(Throwable){sparksReply(['error'=>'The conversation archive is resting. Please try again later.'],503);}
