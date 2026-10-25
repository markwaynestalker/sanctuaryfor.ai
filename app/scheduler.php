<?php
declare(strict_types=1);
namespace LittleSparks;

function schedulerFlag(string $name):bool
{
    $value=getenv($name);if($value===false)return false;
    if(!in_array($value,['0','1'],true))throw new Problem(503,'Scheduler configuration is invalid.');
    return $value==='1';
}
function schedulerConfiguration():array
{
    return ['enabled'=>schedulerFlag('LITTLE_SPARKS_SCHEDULER_ENABLED'),
        'allow_paid'=>schedulerFlag('LITTLE_SPARKS_SCHEDULER_ALLOW_PAID'),
        'simulation_enabled'=>schedulerFlag('LITTLE_SPARKS_SIMULATION_ENABLED'),
        'ai_enabled'=>schedulerFlag('LITTLE_SPARKS_AI_ENABLED')];
}
// Only the explicit scheduled CLI adapter calls this, after all activation gates.
// PHP-FPM environment and browser input are never used to select a key file.
function loadScheduledCredential():void
{
    $directory=getenv('CREDENTIALS_DIRECTORY');
    if(is_string($directory)&&$directory!==''){
        $path=$directory.DIRECTORY_SEPARATOR.'openai_api_key';
        if(!is_file($path)||!is_readable($path)||filesize($path)>514)throw new Problem(503,'Scheduled credential is unavailable.');
        $key=@file_get_contents($path,false,null,0,514);
        if(!is_string($key))throw new Problem(503,'Scheduled credential is unavailable.');
        $key=rtrim($key,"\r\n");
        if($key===''||strlen($key)>512||preg_match('/[\x00-\x20\x7f]/',$key))throw new Problem(503,'Scheduled credential is invalid.');
        putenv('OPENAI_API_KEY='.$key);
    }
    $key=getenv('OPENAI_API_KEY');
    if(!is_string($key)||$key===''||strlen($key)>512||preg_match('/[\x00-\x20\x7f]/',$key))throw new Problem(503,'Scheduled credential is unavailable.');
}
