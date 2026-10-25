<?php
declare(strict_types=1);
namespace LittleSparks;
require_once __DIR__.'/systemd_credentials.php';

const ACCESS_CHECK_AGENT='agent_3221531b63ba4de2bb45396e701cd6ff369fbe51938d4cd3a8';

interface AgentAccessReadTransport
{
    /** Exactly one metadata GET; no session, response, mutation or retry. */
    public function retrieve():array;
}
final class OpenAiAgentAccessRead implements AgentAccessReadTransport
{
    public function __construct(private readonly string $credentialFile){}
    public function retrieve():array
    {
        if(!extension_loaded('curl'))throw new SystemdCredentialError('curl_unavailable');
        $dir=getenv('CREDENTIALS_DIRECTORY');if(!is_string($dir)||$this->credentialFile!==$dir.'/openai_api_key')throw new SystemdCredentialError('credential_path_invalid');
        $key=readSystemdCredential();
        $body='';$requestId=null;$curl=curl_init('https://api.openai.com/v1/agents/'.ACCESS_CHECK_AGENT);
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'OpenAI-Beta: agents=v1','Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_VERBOSE=>false,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$requestId):int{
                if(preg_match('/\Ax-request-id:\s*(req_[a-zA-Z0-9_-]{1,120})\s*\z/i',trim($header),$m)&&!str_contains($m[1],'sk-'))$requestId=$m[1];return strlen($header);
            },CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
        try{
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false)return ['status'=>$status?:null,'request_id'=>$requestId,'data'=>null];
            $data=null;if($status===200){try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){}}
            return ['status'=>$status,'request_id'=>$requestId,'data'=>$data];
        }finally{curl_close($curl);unset($key,$body);}
    }
}
function agentAccessPreflight(string $auditPath,AgentAccessReadTransport $transport):array
{
    umask(0077);$auditPath=databasePath($auditPath,true);
    $handle=@fopen($auditPath,'x');if($handle===false)throw new \RuntimeException('Access preflight already recorded or unavailable; do not retry automatically.');
    $write=static function(array $row)use($handle):void{
        $line=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        if(fwrite($handle,$line)!==strlen($line)||!fflush($handle)||(function_exists('fsync')&&!fsync($handle)))throw new \RuntimeException('Audit persistence failed.');
    };
    $base=['schema'=>'sanctuary.agent-access.v1','check_id'=>bin2hex(random_bytes(16)),'agent_id'=>ACCESS_CHECK_AGENT,
        'method'=>'GET','path'=>'/v1/agents/'.ACCESS_CHECK_AGENT,'http_requests_reserved'=>1,'generation_requests'=>0,'database_opened'=>false,'at'=>gmdate('Y-m-d\TH:i:s\Z')];
    try{
        // Durable intent before credentials/network. Existing receipt refuses even
        // after timeout or failure; neither a key nor vendor body is persisted.
        $write($base+['state'=>'started']);
        $failure=null;
        try{$response=$transport->retrieve();}catch(SystemdCredentialError $e){$failure=$e->reason;$response=['status'=>null,'data'=>null,'request_id'=>null];}
        catch(\Throwable){$failure='local_or_transport_failure';$response=['status'=>null,'data'=>null,'request_id'=>null];}
        $status=$response['status']??null;if(!is_int($status)||$status<100||$status>599)$status=null;
        $requestId=$response['request_id']??null;
        if(!is_string($requestId)||!preg_match('/\Areq_[a-zA-Z0-9_-]{1,120}\z/',$requestId)||str_contains($requestId,'sk-'))$requestId=null;
        $agent=$response['data']??null;
        $authorized=$status===200&&is_array($agent)&&($agent['object']??null)==='agent'&&($agent['id']??null)===ACCESS_CHECK_AGENT;
        $result=$base+['state'=>$authorized?'authorized':'not_confirmed','http_status'=>$status,'request_id'=>$requestId,
            'authorized'=>$authorized,'model_matches'=>$authorized&&($agent['model']??null)==='gpt-6-luna',
            'tools_disabled'=>$authorized&&($agent['tools']??null)===[],
            'subagents_disabled'=>$authorized&&($agent['multi_agent']['enabled']??null)===false,
            'service_tier_default'=>$authorized&&($agent['service_tier']??null)==='default'];
        $result['configuration_matches']=$result['model_matches']&&$result['tools_disabled']&&$result['subagents_disabled'];
        $safeFailures=['unsupported_identity','unit_binding_invalid','credential_path_invalid','metadata_unavailable','credential_file_untrusted','credential_directory_untrusted','runtime_parent_untrusted','credential_access_denied','credential_mount_not_readonly','kernel_metadata_unavailable','credential_open_failed','credential_changed','credential_read_failed','credential_content_invalid','curl_unavailable','local_or_transport_failure'];
        $result['failure_code']=in_array($failure,$safeFailures,true)?$failure:null;
        $write($result);return $result;
    }finally{fclose($handle);}
}

final class AccessReconciliationFailure extends \RuntimeException
{
    public function __construct(public readonly string $stage,public readonly ?string $receipt=null,public readonly ?string $field=null,public readonly ?int $row=null)
    {parent::__construct('Invalid access evidence.');}
}
/** Read existing metadata receipts only. No key, HTTP, SQLite or receipt writes. */
function reconcileDirectorAccess(string $directory,string $originalHash,string $authorizedHash):array
{
    $records=[];
    foreach(['agent-access.jsonl'=>$originalHash,'agent-access-after-validator.jsonl'=>$authorizedHash] as $name=>$expected){
        $fail=static fn(string $stage,?string $field=null,?int $row=null)=>throw new AccessReconciliationFailure($stage,$name,$field,$row);
        if(!preg_match('/\A[a-f0-9]{64}\z/',$expected))$fail('hash_format_invalid','sha256');
        try{$path=databasePath($directory.'/'.$name);}catch(\Throwable){$fail('private_path_or_permissions');}
        if(is_link($path)||!is_file($path)||filesize($path)>8192)$fail('file_safety');
        $raw=@file_get_contents($path,false,null,0,8193);
        if(!is_string($raw)||strlen($raw)>8192)$fail('file_read');
        if(!hash_equals($expected,hash('sha256',$raw)))$fail('hash_mismatch','sha256');
        $lines=explode("\n",rtrim($raw,"\r\n"));if(count($lines)!==2)$fail('line_count');
        try{$start=json_decode($lines[0],true,16,JSON_THROW_ON_ERROR);$end=json_decode($lines[1],true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){$fail('json_invalid');}
        if(!is_array($start)||!is_array($end))$fail('row_shape');
        if(($start['state']??null)!=='started')$fail('intent_invalid','state',0);
        if(!is_string($start['check_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/',$start['check_id']))$fail('intent_invalid','check_id',0);
        if(!is_string($start['at']??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/',$start['at'])
            ||gmdate('Y-m-d\TH:i:s\Z',strtotime($start['at'])?:0)!==$start['at'])$fail('intent_invalid','at',0);
        foreach(['schema'=>'sanctuary.agent-access.v1','check_id'=>$start['check_id'],'agent_id'=>ACCESS_CHECK_AGENT,
            'method'=>'GET','path'=>'/v1/agents/'.ACCESS_CHECK_AGENT,'http_requests_reserved'=>1,'generation_requests'=>0,'database_opened'=>false,'at'=>$start['at']] as $key=>$value)
            foreach([$start,$end] as $index=>$row)if(($row[$key]??null)!==$value)$fail('shared_field_mismatch',$key,$index);
        $successful=$name==='agent-access-after-validator.jsonl';
        if(($end['state']??null)!==($successful?'authorized':'not_confirmed'))$fail('outcome_invalid','state',1);
        if(($end['authorized']??null)!==$successful)$fail('outcome_invalid','authorized',1);
        if($successful){
            foreach(['model_matches','tools_disabled','subagents_disabled','configuration_matches'] as $field)
                if(($end[$field]??null)!==true)$fail('authorization_field_invalid',$field,1);
            if(($end['http_status']??null)!==200)$fail('authorization_field_invalid','http_status',1);
        }
        $records[]=['receipt'=>$name,'sha256'=>$expected,'check_id'=>$start['check_id'],'at'=>$start['at'],
            'day'=>substr($start['at'],0,10),'state'=>$end['state'],'http_requests_reserved'=>1];
    }
    if($records[0]['check_id']===$records[1]['check_id'])throw new AccessReconciliationFailure('duplicate_checks',null,'check_id');
    if($records[1]['at']<$records[0]['at'])throw new AccessReconciliationFailure('chronology_invalid',null,'at');
    $days=[];foreach($records as $r)$days[$r['day']]=($days[$r['day']]??0)+1;
    return ['schema'=>'sanctuary.access-reconciliation.v1','receipts'=>$records,'reserved_by_utc_day'=>$days,
        'total_requests_reserved'=>2,'new_http_requests'=>0,'generation_requests'=>0,'credential_loaded'=>false,'database_opened'=>false];
}
/** Append-only imported request evidence, using existing settings storage. */
function recordDirectorAccessReservations(\PDO $db,array $reconciliation):void
{
    if(($reconciliation['schema']??null)!=='sanctuary.access-reconciliation.v1'||($reconciliation['total_requests_reserved']??null)!==2
        ||count($reconciliation['receipts']??[])!==2)throw new \RuntimeException('Invalid access reconciliation.');
    $db->exec('BEGIN IMMEDIATE');try{
        foreach($reconciliation['receipts'] as $r){
            if(!preg_match('/\A[a-f0-9]{32}\z/',$r['check_id']??'')||!preg_match('/\A[a-f0-9]{64}\z/',$r['sha256']??'')
                ||($r['http_requests_reserved']??null)!==1||($r['day']??null)!==substr($r['at']??'',0,10))throw new \RuntimeException('Invalid access reconciliation.');
            $name='director_access_request:'.$r['check_id'];$encoded=json_encode($r,JSON_THROW_ON_ERROR);
            $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute([$name]);$prior=$q->fetchColumn();
            if($prior!==false&&$prior!==$encoded)throw new \RuntimeException('Conflicting access accounting.');
            if($prior===false)$db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute([$name,$encoded]);
        }$db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function directorAccessReservedRequests(\PDO $db,string $day):int
{
    $used=0;
    foreach($db->query("SELECT name,value FROM settings WHERE name LIKE 'director_access_request:%'") as $row){
        $r=json_decode($row['value'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['http_requests_reserved']??null)!==1||$row['name']!=='director_access_request:'.($r['check_id']??'')
            ||!is_string($r['day']??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$r['day']))throw new \RuntimeException('Invalid access accounting.');
        if($r['day']===$day)$used++;
    }return $used;
}
