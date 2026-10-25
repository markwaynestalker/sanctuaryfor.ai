<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director_config.php';
try{
    $options=getopt('',['database:','agent-file:','agent-id:']);
    $export=isset($options['agent-file'])?LittleSparks\readDirectorExport((string)$options['agent-file']):[
        'id'=>$options['agent-id']??null,'model'=>getenv('LITTLE_SPARKS_AI_MODEL')?:'gpt-6-luna','tools'=>[],
        'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];
    if(($options['agent-id']??null)!==$export['id'])throw new RuntimeException('Mismatched external identity.');
    $result=LittleSparks\registerDirector(LittleSparks\connect((string)($options['database']??'')),$export);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}catch(Throwable){fwrite(STDERR,"Director registration refused. Check the private reviewed export, external ID, V6 path, bounded configuration and drained scheduler.\n");exit(1);}
