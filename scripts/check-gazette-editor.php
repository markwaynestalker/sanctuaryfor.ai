<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_job.php';
try{
    $o=getopt('',['database:']);$path=$o['database']??null;
    if(!is_string($path)||$path===''||realpath($path)!==$path||!is_file($path)||is_link($path))throw new RuntimeException();
    $path=LittleSparks\databasePath($path); // Same private-file policy as actual generation; never bypass it in preflight.
    $policy=LittleSparks\editorialConfiguration(false);
    $bytes=LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES');
    if($bytes<8192||$bytes>262144||$bytes+4096>$policy['input_tokens'])throw new RuntimeException();
    if(LittleSparks\inspectSystemdCredential()['reason']!==null)throw new RuntimeException();
    // Real read-only open, never connect()'s writable connection or migrations.
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON');
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6||(int)$db->query('SELECT revision FROM gazette_editor_meta WHERE singleton=1')->fetchColumn()!==1)throw new RuntimeException();
    foreach(['permits','attempts','lease','requests','receipts','drafts','moderations','outcomes','reviews'] as $t)$db->query('SELECT * FROM gazette_editor_'.$t.' LIMIT 0');
    if(LittleSparks\editorialReservationCost($policy)>$policy['daily_microusd']||LittleSparks\editorialReservationCost($policy)>$policy['monthly_microusd']||$policy['daily_requests']<2)throw new RuntimeException();
    echo "{\"status\":\"preflight_ready\",\"credential_content_reads\":0,\"paid_requests\":0}\n";
}catch(Throwable){fwrite(STDERR,"Gazette preflight refused: review protected delivery, schema and explicit limits. No credential contents or API requests were read/sent.\n");exit(1);}
