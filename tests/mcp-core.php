<?php
declare(strict_types=1);
require __DIR__.'/../app/mcp.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
    $dir=$argv[1]??'';check(is_dir($dir)&&str_starts_with(basename($dir),'little-sparks-journal-test-'),'Disposable fixtures only');
    $token=str_repeat('a',64);$file=$dir.'/mcp-token';file_put_contents($file,$token."\n");chmod($file,0600);putenv('LITTLE_SPARKS_MCP_TOKEN_FILE='.$file);
    $headers=['SANCTUARY_MCP_ROUTE'=>'1','HTTPS'=>'on','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json, text/event-stream','HTTP_AUTHORIZATION'=>'Bearer '.$token,'HTTP_MCP_PROTOCOL_VERSION'=>'2025-11-25'];
    $reads=0;$calls=0;$body=LittleSparks\mcpJson(['jsonrpc'=>'2.0','id'=>0,'method'=>'ping']);
    $read=static function(int $max)use(&$reads,&$body):string{$reads++;return substr($body,0,$max);};
    $search=static function(array $args)use(&$calls):array{$calls++;return ['conversations'=>[]];};
    $reply=LittleSparks\mcpHttp($headers,$read,$search);check($reply['status']===200&&json_decode($reply['body'],true)['result']===[],'Zero numeric ID and empty result object');
    foreach([
        ['HTTP_AUTHORIZATION'=>'', 'expected'=>401],['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('b',64),'expected'=>401],
        ['HTTP_AUTHORIZATION'=>'Basic '.$token,'expected'=>401],['HTTP_AUTHORIZATION'=>'Bearer '.$token.', Bearer '.$token,'expected'=>401],
        ['HTTP_ORIGIN'=>'https://evil.example','expected'=>403],['HTTP_ORIGIN'=>'null','expected'=>403],
        ['HTTPS'=>'off','expected'=>426],['SANCTUARY_MCP_ROUTE'=>'0','expected'=>404],
        ['HTTP_SANCTUARY_MCP_ROUTE'=>'1','SANCTUARY_MCP_ROUTE'=>'','expected'=>404],
        ['QUERY_STRING'=>'token='.$token,'expected'=>400],['REQUEST_METHOD'=>'GET','expected'=>405],['REQUEST_METHOD'=>'DELETE','expected'=>405],
        ['CONTENT_TYPE'=>'text/plain','expected'=>415],['HTTP_ACCEPT'=>'application/json','expected'=>406],
        ['HTTP_ACCEPT'=>'application/json;q=0, text/event-stream','expected'=>406],['HTTP_MCP_PROTOCOL_VERSION'=>'2099-01-01','expected'=>400],
        ['CONTENT_LENGTH'=>'8193','expected'=>413]
    ] as $case){$expected=$case['expected'];unset($case['expected']);$before=$reads;$reply=LittleSparks\mcpHttp(array_replace($headers,$case),$read,$search);check($reply['status']===$expected&&$reads===$before&&$calls===0,'Reject unauthorized/invalid transport before body and database access');}
    putenv('LITTLE_SPARKS_MCP_TOKEN_FILE');check(LittleSparks\mcpHttp($headers,$read,$search)['status']===404,'Disabled by default');
    putenv('LITTLE_SPARKS_MCP_TOKEN_FILE='.$file);check(LittleSparks\mcpToken($file)===$token,'Protected external token');
    if(PHP_OS_FAMILY!=='Windows'){chmod($file,0644);check(LittleSparks\mcpHttp($headers,$read,$search)['status']===503,'World-readable token refuses');chmod($file,0600);}
    try{LittleSparks\mcpToken(__FILE__);throw new LogicException('Code-tree secret accepted');}catch(RuntimeException){}
    foreach(LittleSparks\MCP_VERSIONS as $version){$body=LittleSparks\mcpJson(['jsonrpc'=>'2.0','id'=>'init','method'=>'initialize','params'=>['protocolVersion'=>$version,'capabilities'=>new stdClass(),'clientInfo'=>['name'=>'offline','version'=>'1']]]);$reply=LittleSparks\mcpHttp(array_replace($headers,['HTTP_MCP_PROTOCOL_VERSION'=>$version]),$read,$search);check(json_decode($reply['body'],true)['result']['protocolVersion']===$version,'Negotiate each supported version without database');}
    $bad=['null','[]','[{}]','{"jsonrpc":"2.0","id":null,"method":"ping"}','{"jsonrpc":"1.0","id":1,"method":"ping"}','{"jsonrpc":"2.0","id":1,"method":"ping","params":null}','{"jsonrpc":"2.0","id":1,"result":{}}'];
    foreach($bad as $body){$reply=LittleSparks\mcpHttp($headers,$read,$search);check($reply['status']===400&&isset(json_decode($reply['body'],true)['error']),'Reject invalid JSON-RPC shape/batch/client response');}
    $body='{';$reply=LittleSparks\mcpHttp($headers,$read,$search);check(json_decode($reply['body'],true)['error']['code']===-32700,'Parse error');
    $body=str_repeat(' ',8193);check(LittleSparks\mcpHttp($headers,$read,$search)['status']===413,'Bound even missing/chunked length');
    $body=LittleSparks\mcpJson(['jsonrpc'=>'2.0','method'=>'tools/call','params'=>['name'=>'search_village_conversations']]);$reply=LittleSparks\mcpHttp($headers,$read,$search);check($reply['status']===202&&$reply['body']===''&&$calls===0,'Notifications never execute tools');
    foreach([['limit'=>null],['limit'=>0],['limit'=>21],['limit'=>'5'],['limit'=>1.5],['query'=>null],['query'=>['x']],['query'=>str_repeat('x',129)],['query'=>"\0"],['resident_id'=>''],['resident_id'=>'../private'],['sql'=>'SELECT * FROM spark_calls']] as $args){$message=json_decode(LittleSparks\mcpJson(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'search_village_conversations','arguments'=>$args]]));$r=LittleSparks\mcpDispatch($message,'2025-11-25',$search);check($r['result']['isError']&&$calls===0,'Validate arguments without opening database');}
    $message=json_decode('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"search_village_conversations"}}');
    $r=LittleSparks\mcpDispatch($message,'2025-11-25',static function(){throw new RuntimeException('PRIVATE_SQLSTATE_CANARY');});check($r['result']['isError']&&!str_contains(LittleSparks\mcpJson($r),'PRIVATE_'),'Safe tool error');
    foreach([2,3,4,6] as $version){$path=$dir.'/v'.$version.'.sqlite';$before=hash_file('sha256',$path);
        $readDb=LittleSparks\publicMemoryDatabase($path);check((int)$readDb->query('PRAGMA query_only')->fetchColumn()===1,'Query-only readonly connection');
        try{$readDb->exec("INSERT INTO settings VALUES ('mcp_forbidden_write','no')");throw new LogicException('Village write accepted');}catch(PDOException){}$readDb=null;
        $page=LittleSparks\searchPublicVillageConversations($path,[]);check($page['returned_count']===5&&$page['total_matches']===205,'Default bounded shared public projection');
        foreach(['PRIVATE_',"' OR 1=1 --",'.*'] as $q)check(LittleSparks\searchPublicVillageConversations($path,['query'=>$q])['total_matches']===0,'No private data, SQL or regex interpretation');
        $result=LittleSparks\searchPublicVillageConversations($path,['query'=>'éTOILE','limit'=>20]);check($result['returned_count']===20&&$result['total_matches']===200,'Literal Unicode search and hard result limit');
        check(hash_file('sha256',$path)===$before,'Every public read preserves exact database bytes');
    }
    // Exercise real 64-turn storage and private Spark quote notes separately.
    require_once __DIR__.'/../app/conversation_messages_schema.php';
    $multi=$dir.'/mcp-multi.sqlite';copy($dir.'/v6.sqlite',$multi);chmod($multi,0600);$db=LittleSparks\connect($multi);$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateConversationMessages($db);
    $pair=$db->query("SELECT resident_id FROM messages WHERE conversation_id='first' ORDER BY sequence")->fetchAll(PDO::FETCH_COLUMN);
    $db->exec("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES ('mcp-multi-run','mcpmultird','completed','openai','2026-11-01T12:00:00Z','2026-11-01T12:00:00Z')");
    for($i=0;$i<4;$i++){$id='mcp-multi-'.$i;$db->prepare("INSERT INTO conversations VALUES (?,'mcp-multi-run','garden','public','2026-11-01T12:00:00Z')")->execute([$id]);
        foreach($pair as $resident)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$resident]);
        $db->prepare('INSERT INTO extended_conversations VALUES (?,64)')->execute([$id]);
        for($sequence=0;$sequence<64;$sequence++)$db->prepare('INSERT INTO extended_messages VALUES (?,?,?,?)')->execute([$id,$sequence,$pair[$sequence%2],'MCP_LONG_'.$sequence.' '.str_repeat('é',140)]);
        LittleSparks\event($db,$pair[0],$pair[1],'conversation','simulation','2026-11-01T12:00:00Z','social:mcp-multi-run:'.$id,'mcp-multi-run');
    }
    $db->prepare("INSERT INTO director_notes VALUES ('mcp-private-note',?,?, 'first',0,'PRIVATE_SPARK_NOTE_CANARY','interpretation','2026-11-01T12:00:00Z')")->execute($pair);
    $db->exec('COMMIT');$db=null;$before=hash_file('sha256',$multi);
    check(LittleSparks\searchPublicVillageConversations($multi,['query'=>'PRIVATE_SPARK_NOTE_CANARY'])['total_matches']===0,'Private Spark notes are not searchable or returned');
    $result=LittleSparks\searchPublicVillageConversations($multi,['query'=>'MCP_LONG_','limit'=>20]);
    check($result['total_matches']===4&&$result['returned_count']>0&&$result['returned_count']<4&&$result['has_more'],'Byte cap omits complete conversations instead of clipping speech');
    foreach($result['conversations'] as $conversation){check(count($conversation['messages'])===64,'All 64 original messages retained');foreach($conversation['messages'] as $sequence=>$message)check($message['text']==='MCP_LONG_'.$sequence.' '.str_repeat('é',140),'Full byte-exact source speech and order');}
    check(strlen(LittleSparks\mcpJson($result))<=LittleSparks\PUBLIC_MEMORY_PAYLOAD_BYTES&&hash_file('sha256',$multi)===$before,'Bounded complete result and unchanged private/history bytes');
    echo "PASS MCP transport/authentication, protected token, version negotiation, JSON-RPC, bounded validation, shared V2/V3/V4/V6 public history, private Spark-note exclusion, complete 64-turn transcripts and immutable readonly SQLite\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
