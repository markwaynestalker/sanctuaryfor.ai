<?php
declare(strict_types=1);
namespace LittleSparks;

final class ManagedHttpFailure extends \RuntimeException
{
    public function __construct(public readonly ?int $status=null,public readonly ?string $requestId=null,public readonly ?array $validation=null){parent::__construct('Managed request outcome requires reconciliation.');}
}
/** Retain structural validation only, never a provider message or echoed input. */
function managedValidationError(int $status,string $response):?array
{
    if($status!==400||strlen($response)>262144)return null;
    try{$error=json_decode($response,true,16,JSON_THROW_ON_ERROR)['error']??null;}catch(\Throwable){return null;}
    if(!is_array($error))return null;
    $codes=['unknown_parameter','invalid_value','invalid_type','invalid_json_schema','missing_required_parameter','unsupported_parameter','unsupported_value'];
    $code=in_array($error['code']??null,$codes,true)?$error['code']:null;
    $param=$error['param']??null;
    $paths=['agent_id','environment','environment.type','agent','agent.model','agent.reasoning','agent.reasoning.effort','agent.tools','agent.multi_agent','agent.multi_agent.enabled','agent.service_tier','agent.text','agent.text.format','agent.text.format.type','agent.text.format.schema','spend_control','spend_control.limit','input','metadata','stream','vault_ids'];
    if(!in_array($param,$paths,true))$param=null;
    return $code===null&&$param===null?null:['code'=>$code,'param'=>$param];
}
function managedLiveAdmission():array
{
    try{
        $cfg=aiConfiguration(time());$enabled=getenv('LITTLE_SPARKS_AI_ENABLED')==='1'
            &&getenv('LITTLE_SPARKS_DIRECTOR_ENABLED')==='1'&&getenv('LITTLE_SPARKS_SIMULATION_ENABLED')==='1';
        return ['allowed'=>$enabled&&$cfg['daily_requests']>0&&$cfg['monthly_cap']>0,
            'reason'=>$enabled?'configured_application_limits':'director_disabled','provider_charge_maximum_verified'=>false];
    }catch(\Throwable){return ['allowed'=>false,'reason'=>'configuration_invalid','provider_charge_maximum_verified'=>false];}
}
function managedWireBody(string $method,string $path,?array $body,?string $key):?string
{
    managedRoute($method,$path);
    if($key!==null&&!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new AiGuard('configuration_invalid');
    if($method==='GET'){if($body!==null||$key!==null)throw new AiGuard('configuration_invalid');return null;}
    if($body===null)throw new AiGuard('configuration_invalid');
    if($path==='agents/sessions'&&array_key_exists('spend_control',$body))throw new AiGuard('configuration_invalid');
    $encoded=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($encoded)>AI_BODY_BYTES)throw new AiGuard('configuration_invalid');return $encoded;
}
/** Fixed official HTTPS transport; explicit enable flags and reserved budgets govern egress. */
final class OpenAiManagedTransport implements DirectorTransport
{
    public function __construct(private readonly ?StagingPermit $staging=null,private readonly ?string $inspectionSession=null){}
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array
    {
        $encoded=managedWireBody($method,$path,$body,$idempotencyKey);
        if($this->inspectionSession!==null){
            if(PHP_SAPI!=='cli'||$this->staging!==null||!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/',$this->inspectionSession)
                ||$method!=='GET'||$body!==null||$idempotencyKey!==null
                ||!in_array($path,['agents/sessions/'.$this->inspectionSession,'agents/sessions/'.$this->inspectionSession.'/turns?limit=1&order=desc'],true))throw new AiGuard('configuration_invalid');
            if(getenv('LITTLE_SPARKS_ENVIRONMENT')==='staging')assertStagingHostIsolation();
        }
        elseif($this->staging!==null){assertStagingHostIsolation();$this->staging->authorize($method,$path,$body);}
        else{
            if(!managedLiveAdmission()['allowed'])throw new AiGuard('configuration_invalid');
            if(getenv('LITTLE_SPARKS_ENVIRONMENT')==='staging')assertStagingHostIsolation();
        }
        if(time()>=$deadline||!extension_loaded('curl'))throw new ManagedHttpFailure();
        $key=getenv('OPENAI_API_KEY');
        if(($key===false||$key==='')&&PHP_SAPI==='cli'){
            require_once __DIR__.'/systemd_credentials.php';$key=readSystemdCredential();
        }
        if(!is_string($key)||$key===''||strlen($key)>512||preg_match('/[\r\n]/',$key))throw new ManagedHttpFailure();
        $headers=['Content-Type: application/json','Authorization: Bearer '.$key];
        if(str_starts_with($path,'agents/'))$headers[]='OpenAI-Beta: agents=v1';
        if($idempotencyKey!==null)$headers[]='Idempotency-Key: '.$idempotencyKey;
        $response='';$requestId=null;$curl=curl_init('https://api.openai.com/v1/'.$path);
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(1,min(15,$deadline-time())),
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_VERBOSE=>false,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header)use(&$requestId):int{
                if(preg_match('/\Ax-request-id:\s*(req_[a-zA-Z0-9_-]{1,120})\s*\z/i',trim($header),$match)&&!str_contains($match[1],'sk-'))$requestId=$match[1];return strlen($header);
            },CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);
            }]);
        if($encoded!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$encoded);
        $status=null;try{
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false||$status<200||$status>299)throw new ManagedHttpFailure($status?:null,$requestId,$ok===false?null:managedValidationError($status,$response));
            $data=json_decode($response,true,32,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data))throw new ManagedHttpFailure($status,$requestId);
            $data['_http']=['status'=>$status,'request_id'=>$requestId];return $data;
        }catch(ManagedHttpFailure $e){throw $e;}catch(\Throwable){throw new ManagedHttpFailure($status?:null,$requestId);}
        finally{curl_close($curl);unset($key,$headers,$response);}
    }
}
