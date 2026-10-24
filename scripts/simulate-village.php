<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/ai_simulation.php';
try {
    $options=getopt('',['database:','period:','provider:','allow-paid']);
    if (!isset($options['database'],$options['period'])) throw new RuntimeException('Explicit --database and --period=YYYY-MM-DD are required.');
    $provider=$options['provider']??'scripted';
    if(!in_array($provider,['scripted','openai'],true))throw new RuntimeException('Unknown provider.');
    $db=LittleSparks\connect((string)$options['database']);
    $result=$provider==='openai'?LittleSparks\simulateOptionalAiDay($db,(string)$options['period'],array_key_exists('allow-paid',$options))
        :LittleSparks\simulateDay($db,(string)$options['period'],null,null,true);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if ($result['status']==='failed') exit(1);
} catch (Throwable) {
    fwrite(STDERR,"Simulation not applied. Check the approved database path, schema, UTC period, feature flag, and recorded run status.\n"); exit(1);
}
