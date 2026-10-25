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
