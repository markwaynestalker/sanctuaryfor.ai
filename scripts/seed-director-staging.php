<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director.php';
try{
    $o=getopt('',['database:','residents-file:']);
    if(getenv('LITTLE_SPARKS_ENVIRONMENT')!=='staging'||getenv('LITTLE_SPARKS_AI_ENABLED')==='1'||getenv('LITTLE_SPARKS_SIMULATION_ENABLED')==='1')throw new RuntimeException('Disabled staging environment required.');
    $path=LittleSparks\databasePath((string)($o['database']??''));
    if(str_starts_with(str_replace('\\','/',$path),'/var/lib/sanctuaryfor-ai/'))throw new RuntimeException('Production paths forbidden.');
    $db=LittleSparks\connect($path);if(!LittleSparks\directorEnabled($db)||(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==0||(int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()!==0)throw new RuntimeException('Empty V6 staging database required.');
    $file=LittleSparks\databasePath((string)($o['residents-file']??''));if(filesize($file)>49152)throw new RuntimeException('Oversized input.');
    $input=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||!array_is_list($input)||count($input)!==2)throw new RuntimeException('Exactly two owner-written synthetic characters required.');
    $prepared=[];foreach($input as $r){if(!is_array($r)||count($r)!==4||array_diff(array_keys($r),['name','color','antenna','description']))throw new RuntimeException('Invalid character fields.');$prepared[]=LittleSparks\validate($r+['website'=>'','requestId'=>bin2hex(random_bytes(16))],true);}
    $ids=[];foreach($prepared as $r){$saved=LittleSparks\create($db,$r+['website'=>'','requestId'=>bin2hex(random_bytes(16))],bin2hex(random_bytes(32)),bin2hex(random_bytes(32)),time()-10,true);$ids[]=$saved['resident']['id'];unset($saved);}
    echo json_encode(['synthetic_resident_ids'=>$ids,'paid_calls'=>0],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Synthetic staging seed refused. Use a fresh private empty V6 database, disabled gates and two valid owner-written characters. No paid call was made.\n");exit(1);}
