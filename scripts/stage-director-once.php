<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/../app/scheduler.php';
try{
    $o=getopt('',['database:','at:','allow-paid','check']);$admission=LittleSparks\managedLiveAdmission();
    if(isset($o['check'])){echo json_encode(['paid_requests'=>0,'admission'=>$admission,'maximum_encounters'=>1,'world_changes'=>false],JSON_THROW_ON_ERROR)."\n";exit;}
    // Owner approval is required separately; command flags do not grant it.
    if(!$admission['allowed'])throw new LittleSparks\AiGuard('managed_accounting_not_verified');
    if(getenv('LITTLE_SPARKS_ENVIRONMENT')!=='staging'||!isset($o['allow-paid'],$o['database'],$o['at'])
        ||getenv('LITTLE_SPARKS_DIRECTOR_RUNTIME')!=='agents'||getenv('LITTLE_SPARKS_DIRECTOR_ENABLED')!=='1'
        ||getenv('LITTLE_SPARKS_AI_ENABLED')!=='1'||getenv('LITTLE_SPARKS_SIMULATION_ENABLED')!=='1'
        ||getenv('LITTLE_SPARKS_SCHEDULER_ENABLED')!=='0'||getenv('LITTLE_SPARKS_SCHEDULER_ALLOW_PAID')!=='0'
        ||getenv('LITTLE_SPARKS_AI_DAILY_REQUESTS')!=='4'||getenv('LITTLE_SPARKS_AI_MONTHLY_MICROUSD')!=='10000'
        ||getenv('LITTLE_SPARKS_AI_MODEL')!=='gpt-6-luna')throw new RuntimeException('Manual isolated staging gates required.');
    $path=LittleSparks\databasePath((string)$o['database']);
    if(str_replace('\\','/',$path)==='/var/lib/sanctuaryfor-ai/village.sqlite')throw new RuntimeException('Production path is forbidden.');
    $db=LittleSparks\connect($path);
    if((int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==2||(int)$db->query("SELECT COUNT(*) FROM director_attempts WHERE runtime='agents'")->fetchColumn()!==0)throw new RuntimeException('Exactly two staging residents and no earlier managed attempt required.');
    LittleSparks\loadScheduledCredential(); // Existing secure CLI mechanism, only after every admission gate.
    $result=LittleSparks\simulateDirectorOpportunity($db,(string)$o['at'],true,null,new LittleSparks\OpenAiManagedTransport(),null,null,true);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Staging encounter refused or failed; paid admission requires verified provider limits and separate owner approval. Inspect private accounting before any further work.\n");exit(1);}
