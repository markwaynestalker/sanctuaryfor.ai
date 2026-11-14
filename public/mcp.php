<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
require __DIR__.'/../app/mcp.php';
try{
    $reply=LittleSparks\mcpHttp($_SERVER,static fn(int $limit)=>file_get_contents('php://input',false,null,0,$limit),
        static fn(array $args)=>LittleSparks\searchPublicVillageConversations((string)getenv('LITTLE_SPARKS_DB'),$args));
    http_response_code($reply['status']);foreach($reply['headers'] as $name=>$value)header($name.': '.$value);echo $reply['body'];
}catch(Throwable){http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');echo '{"error":"Memory service unavailable"}';}
