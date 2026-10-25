<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';
try{
    $options=getopt('',['database:','at:','due','provider:','allow-paid','conversation-only','original-access-sha256:','authorized-access-sha256:']);
    if(!isset($options['database'])||(isset($options['at'])===isset($options['due'])))throw new RuntimeException('Explicit database and exactly one of --at or --due required.');
    $provider=$options['provider']??'scripted';if(!in_array($provider,['scripted','openai'],true))throw new RuntimeException('Unknown provider.');
    if(getenv('LITTLE_SPARKS_SIMULATION_ENABLED')!=='1'){$result=['status'=>'paused'];}
    else{
        $at=isset($options['due'])?LittleSparks\dueOpportunity(time()):(string)$options['at'];
        $db=LittleSparks\connect((string)$options['database']);
        if(isset($options['original-access-sha256'],$options['authorized-access-sha256'])){
            require_once __DIR__.'/../app/director_access.php';
            LittleSparks\recordDirectorAccessReservations($db,LittleSparks\reconcileDirectorAccess('/var/lib/sanctuaryfor-ai-staging/preflight',
                (string)$options['original-access-sha256'],(string)$options['authorized-access-sha256']));
        }
        if(isset($options['conversation-only'])){
            if(!LittleSparks\directorEnabled($db)||$provider!=='openai')throw new RuntimeException('Conversation-only requires the registered Director.');
            require_once __DIR__.'/../app/director.php';
            $result=LittleSparks\simulateDirectorOpportunity($db,$at,array_key_exists('allow-paid',$options),null,null,null,null,true);
        }else $result=LittleSparks\simulateOpportunity($db,$at,$provider==='openai'&&array_key_exists('allow-paid',$options));
    }
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if(($result['status']??null)==='failed')exit(1);
}catch(Throwable){fwrite(STDERR,"Opportunity not applied. Check private database, V4 migration, interval, UTC minute, limits and active claims.\n");exit(1);}
