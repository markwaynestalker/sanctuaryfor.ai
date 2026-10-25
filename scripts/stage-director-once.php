<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/../app/scheduler.php';
try{
    $o=getopt('',['database:','at:','permit:','plan','allow-paid','check','check-host']);$admission=LittleSparks\managedLiveAdmission();
    if(isset($o['check-host'])){LittleSparks\assertStagingHostIsolation();echo "{\"host_isolation_check\":\"passed\",\"paid_requests\":0,\"credential_loaded\":false,\"database_opened\":false}\n";exit;}
    if(isset($o['check'])){echo json_encode(['paid_requests'=>0,'admission'=>$admission,'maximum_encounters'=>1,'world_changes'=>false,'staging_permit_required'=>true,'manifest_sha256'=>LittleSparks\stagingCodeFingerprint()],JSON_THROW_ON_ERROR)."\n";exit;}
    if(isset($o['plan'])){
        $db=LittleSparks\connect(LittleSparks\databasePath((string)($o['database']??'')));
        echo json_encode(LittleSparks\stagingPlan($db,(string)($o['at']??''),time()+3600),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";exit;
    }
    // Owner approval is required separately; command flags do not grant it.
    if(getenv('LITTLE_SPARKS_ENVIRONMENT')!=='staging'||!isset($o['allow-paid'],$o['database'],$o['at'],$o['permit'])
        ||getenv('LITTLE_SPARKS_DIRECTOR_RUNTIME')!=='agents'||getenv('LITTLE_SPARKS_DIRECTOR_ENABLED')!=='1'
        ||getenv('LITTLE_SPARKS_AI_ENABLED')!=='1'||getenv('LITTLE_SPARKS_SIMULATION_ENABLED')!=='1'
        ||getenv('LITTLE_SPARKS_SCHEDULER_ENABLED')!=='0'||getenv('LITTLE_SPARKS_SCHEDULER_ALLOW_PAID')!=='0'
        ||getenv('LITTLE_SPARKS_AI_DAILY_REQUESTS')!=='8'||getenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD')!=='100000'
        ||getenv('LITTLE_SPARKS_AI_MODEL')!=='gpt-6-luna')throw new RuntimeException('Manual isolated staging gates required.');
    $path=LittleSparks\databasePath((string)$o['database']);
    if(str_replace('\\','/',$path)==='/var/lib/sanctuaryfor-ai/village.sqlite')throw new RuntimeException('Production path is forbidden.');
    $db=LittleSparks\connect($path);
    if((int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==2||(int)$db->query("SELECT COUNT(*) FROM director_attempts WHERE runtime='agents'")->fetchColumn()!==0)throw new RuntimeException('Exactly two staging residents and no earlier managed attempt required.');
    LittleSparks\assertStagingHostIsolation();
    $permitPath=LittleSparks\databasePath((string)$o['permit']);if(filesize($permitPath)>8192)throw new RuntimeException('Invalid permit.');
    $permit=json_decode((string)file_get_contents($permitPath),true,16,JSON_THROW_ON_ERROR);if(!is_array($permit)||($permit['opportunity']??null)!==$o['at'])throw new RuntimeException('Invalid permit.');
    $grant=LittleSparks\StagingPermit::consume($db,$permit);
    LittleSparks\loadScheduledCredential(); // Existing protected CLI mechanism; no key is printed or stored.
    $result=LittleSparks\simulateDirectorOpportunity($db,(string)$o['at'],true,null,new LittleSparks\OpenAiManagedTransport($grant),null,null,true,$grant);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Staging encounter refused or failed. Separate owner approval, isolated host and a bound one-shot permit are required. Inspect private accounting; do not retry generation.\n");exit(1);}
