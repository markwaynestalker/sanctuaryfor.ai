<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director.php';require_once __DIR__.'/../app/director_access.php';
try{
    $o=getopt('',['check','check-delivery','audit:']);
    if(isset($o['check'])){echo "{\"http_requests\":0,\"generation_requests\":0,\"credential_loaded\":false,\"database_opened\":false,\"only_method\":\"GET\"}\n";exit;}
    if(isset($o['check-delivery'])){
        LittleSparks\assertStagingHostIsolation();$inspection=LittleSparks\inspectSystemdCredential();
        echo json_encode($inspection['report'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($inspection['reason']===null?0:1);
    }
    LittleSparks\assertStagingHostIsolation();
    if(getenv('LITTLE_SPARKS_ENVIRONMENT')!=='staging'||(posix_getpwuid(posix_geteuid())['name']??null)!=='sanctuarystaging')throw new RuntimeException('Staging identity required.');
    foreach(['LITTLE_SPARKS_AI_ENABLED','LITTLE_SPARKS_DIRECTOR_ENABLED','LITTLE_SPARKS_SIMULATION_ENABLED','LITTLE_SPARKS_SCHEDULER_ENABLED','LITTLE_SPARKS_SCHEDULER_ALLOW_PAID'] as $flag)
        if(!in_array(getenv($flag),[false,'0'],true))throw new RuntimeException('All paid/activity gates must be off.');
    $audit=LittleSparks\databasePath((string)($o['audit']??''),true);
    if(!str_starts_with(str_replace('\\','/',$audit),'/var/lib/sanctuaryfor-ai-staging/'))throw new RuntimeException('Private staging audit required.');
    $dir=getenv('CREDENTIALS_DIRECTORY');if(!is_string($dir)||$dir===''||!is_dir($dir)||is_link($dir))throw new RuntimeException('systemd credential delivery required.');
    $result=LittleSparks\agentAccessPreflight($audit,new LittleSparks\OpenAiAgentAccessRead($dir.'/openai_api_key'));
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['authorized']&&$result['configuration_matches']?0:1);
}catch(Throwable){fwrite(STDERR,"Read-only agent access not confirmed. Check isolated identity, private audit, LoadCredential and the saved agent's project/permissions. Do not retry automatically or start a paid encounter.\n");exit(1);}
