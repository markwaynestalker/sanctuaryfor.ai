<?php
declare(strict_types=1);
namespace LittleSparks;

final class ManagedHttpFailure extends \RuntimeException
{
    public function __construct(public readonly ?int $status=null,public readonly ?string $requestId=null){parent::__construct('Managed request outcome requires reconciliation.');}
}
function managedLiveAdmission():array
{
    // No flag may assert an undocumented upper bound or promote best-effort usage
    // to final billing. This gate changes only in reviewed code after evidence.
    return ['allowed'=>false,'reason'=>'managed_accounting_not_verified',
        'missing'=>['provider_enforced_total_inference_bound','documented_maximum_spend_overshoot','approved_staging_evidence']];
}
function managedWireBody(string $method,string $path,?array $body,?string $key):?string
{
    managedRoute($method,$path);
    if($key!==null&&!preg_match('/\A[a-f0-9]{32}\z/',$key))throw new AiGuard('configuration_invalid');
    if($method==='GET'){if($body!==null||$key!==null)throw new AiGuard('configuration_invalid');return null;}
    if($body===null)throw new AiGuard('configuration_invalid');
    $encoded=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($encoded)>AI_BODY_BYTES)throw new AiGuard('configuration_invalid');return $encoded;
}
/** Fixed official HTTPS transport; production egress stays blocked by admission. */
final class OpenAiManagedTransport implements DirectorTransport
{
    public function request(string $method,string $path,?array $body,int $deadline,?string $idempotencyKey=null):array
    {
        $encoded=managedWireBody($method,$path,$body,$idempotencyKey);
        if(!managedLiveAdmission()['allowed'])throw new AiGuard('managed_accounting_not_verified');
        if(time()>=$deadline||!extension_loaded('curl'))throw new ManagedHttpFailure();
        $key=getenv('OPENAI_API_KEY');
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
        try{
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false||$status<200||$status>299)throw new ManagedHttpFailure($status?:null,$requestId);
            $data=json_decode($response,true,32,JSON_THROW_ON_ERROR);if(!is_array($data)||array_is_list($data))throw new ManagedHttpFailure($status,$requestId);
            $data['_http']=['status'=>$status,'request_id'=>$requestId];return $data;
        }catch(ManagedHttpFailure $e){throw $e;}catch(\Throwable){throw new ManagedHttpFailure(null,$requestId);}
        finally{curl_close($curl);unset($key,$headers,$response);}
    }
}
