<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
function operatorSessionSeconds():int{return boundedEnvironmentInteger('LITTLE_SPARKS_OWNER_SESSION_SECONDS',3600,60,86400);}
function operatorPasswordHash():string
{
    $hash=getenv('LITTLE_SPARKS_OWNER_PASSWORD_HASH');
    if(!is_string($hash)||strlen($hash)>255||(password_get_info($hash)['algoName']??'unknown')==='unknown')throw new Problem(503,'Operator authentication is not configured.');
    return $hash;
}
function operatorAuthenticated(mixed $auth,int $now):bool
{
    return operatorIdeasEnabled()&&is_array($auth)&&is_int($auth['expires']??null)&&$auth['expires']>$now
        &&is_string($auth['credential']??null)&&hash_equals(hash('sha256',operatorPasswordHash()),$auth['credential']);
}
function operatorLogin(PDO $db,mixed $password,int $now):array
{
    if(!operatorIdeasEnabled()||!operatorIdeasInstalled($db))throw new Problem(503,'Operator ideas are unavailable.');
    $hash=operatorPasswordHash();$limit=unsignedEnvironmentInteger('LITTLE_SPARKS_OWNER_LOGIN_ATTEMPTS_PER_HOUR',10);$ttl=operatorSessionSeconds();
    $db->exec('BEGIN IMMEDIATE');
    try{rateLimit($db,'operator-login:global',$limit,$now);$db->exec('COMMIT');}
    catch(Problem $e){if($e->status===429)$db->exec('COMMIT');else $db->exec('ROLLBACK');throw $e;}
    catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
    if(!is_string($password)||strlen($password)>256||!password_verify($password,$hash))throw new Problem(403,'Operator sign-in was not accepted.');
    return ['expires'=>$now+$ttl,'credential'=>hash('sha256',$hash)];
}
function operatorFormInput(string $csrf,array $input,array $server):void
{
    if(($server['REQUEST_METHOD']??'')!=='POST')throw new Problem(405,'Use POST.');
    if(($server['HTTP_SEC_FETCH_SITE']??'')==='cross-site'||!is_string($input['csrf']??null)||!hash_equals($csrf,$input['csrf']))throw new Problem(403,'Refresh this page before submitting.');
    if((int)($server['CONTENT_LENGTH']??0)>8192||strlen(http_build_query($input))>8192||strtolower(explode(';',$server['CONTENT_TYPE']??'')[0])!=='application/x-www-form-urlencoded')throw new Problem(413,'Invalid or oversized form.');
}

function storeOperatorSession(?array $auth):void
{
    if(!session_start())throw new \RuntimeException('Private session storage unavailable.');
    if(!session_regenerate_id(true)){session_write_close();throw new \RuntimeException('Private session rotation failed.');}
    if($auth===null)unset($_SESSION['site_operator']);else $_SESSION['site_operator']=$auth;
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    if(!session_write_close())throw new \RuntimeException('Private session storage unavailable.');
}
