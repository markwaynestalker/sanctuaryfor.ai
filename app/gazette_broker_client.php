<?php
declare(strict_types=1);
namespace LittleSparks;
require_once __DIR__.'/gazette_broker.php';

interface GazetteBrokerChannel{public function call(array $message):array;}
final class GazetteUnixBroker implements GazetteBrokerChannel
{
    public function call(array $message):array
    {
        if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()===0)throw new \RuntimeException('Protected Unix broker required.');
        $directory='/run/sanctuary-gazette-db';$path=$directory.'/editor.sock';
        foreach(['/run',$directory] as $node){clearstatcache(true,$node);$s=lstat($node);if(!$s||($s['mode']&0170000)!==0040000||$s['uid']!==0||($s['mode']&0022)!==0||is_link($node))throw new \RuntimeException('Untrusted socket directory.');}
        clearstatcache(true,$path);$s=lstat($path);
        if(!$s||($s['mode']&0170000)!==0140000||$s['uid']!==0||($s['mode']&0777)!==0660||$s['gid']!==posix_getegid()||is_link($path))throw new \RuntimeException('Untrusted socket.');
        $raw=editorialJson($message);if(strlen($raw)>524288)throw new \RuntimeException('Broker frame bound.');
        $stream=@stream_socket_client('unix://'.$path,$errno,$error,3,STREAM_CLIENT_CONNECT);if(!$stream)throw new \RuntimeException('Broker unavailable.');
        try{
            clearstatcache(true,$path);$after=lstat($path);if(!$after||$s['ino']!==$after['ino']||$s['dev']!==$after['dev'])throw new \RuntimeException('Socket changed.');
            stream_set_timeout($stream,12);$data=pack('N',strlen($raw)).$raw;$offset=0;
            while($offset<strlen($data)){$sent=fwrite($stream,substr($data,$offset));if(!$sent)throw new \RuntimeException('Broker write unknown.');$offset+=$sent;}
            stream_socket_shutdown($stream,STREAM_SHUT_WR);
            $read=static function(int $size)use($stream):string{$value='';while(strlen($value)<$size){$part=fread($stream,$size-strlen($value));if($part===false||$part==='')throw new \RuntimeException('Broker response unknown.');$value.=$part;}return $value;};
            $size=unpack('Nsize',$read(4))['size'];if($size<1||$size>524288)throw new \RuntimeException('Broker response bound.');
            $result=json_decode($read($size),true,40,JSON_THROW_ON_ERROR);if(!is_array($result)||array_is_list($result))throw new \RuntimeException('Broker object required.');
            return $result;
        }finally{fclose($stream);}
    }
}
/** Network worker: no PDO, filesystem database path, local-storage fallback or retry. */
function runBrokeredEditorialJob(GazetteBrokerChannel $channel,EditorialTransport $transport,array $policy,int $bytes,string $release,bool $check=false):array
{
    if(preg_match('/\A[a-f0-9]{40}\z/',$release)!==1)throw new \RuntimeException('Pinned release required.');
    $base=['version'=>GAZETTE_BROKER_VERSION,'release'=>$release,'policy_sha256'=>gazetteBrokerPolicyHash($policy,$bytes,$release)];
    $result=$channel->call($base+['op'=>$check?'ready':'begin_generation']);
    if($check){if(($result['status']??null)!=='preflight_ready'||($result['policy_sha256']??null)!==$base['policy_sha256']||($result['version']??null)!==GAZETTE_BROKER_VERSION)throw new \RuntimeException('Broker readiness mismatch.');return ['status'=>'preflight_ready'];}
    foreach(['generation'=>'responses','moderation'=>'moderations'] as $stage=>$endpoint){
        if(($result['status']??null)==='quiet_or_input_bound')return ['status'=>'quiet_or_input_bound','paid_requests'=>0];
        if(($result['status']??null)!=='dispatch_'.$stage)break;
        foreach(['attempt_id','request_id'] as $key)if(!is_string($result[$key]??null)||preg_match('/\A[a-f0-9]{32}\z/',$result[$key])!==1)throw new \RuntimeException('Invalid dispatch identity.');
        editorialHash($result['capability']??null);
        if(!is_int($result['deadline']??null)||$result['deadline']<=time()||$result['deadline']>time()+120||!is_array($result['body']??null)||strlen(editorialJson($result['body']))>$bytes)throw new \RuntimeException('Invalid broker dispatch.');
        $wire=$transport->post($endpoint,$result['body'],$result['deadline']);
        $result=$channel->call($base+['op'=>'complete_'.$stage,'attempt_id'=>$result['attempt_id'],'request_id'=>$result['request_id'],'capability'=>$result['capability'],'wire'=>$wire]);
    }
    if(!in_array($result['status']??null,['awaiting_owner_review','moderation_rejected','rejected_or_uncertain','refused_or_uncertain'],true))throw new \RuntimeException('Broker outcome unknown.');
    $safe=['status'=>$result['status']];foreach(['attempt_id'=>32,'draft_sha256'=>64] as $key=>$length)if(is_string($result[$key]??null)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/',$result[$key]))$safe[$key]=$result[$key];
    if($result['status']==='rejected_or_uncertain'&&in_array($result['rejection_code']??null,EDITORIAL_REJECTION_CODES,true))$safe['rejection_code']=$result['rejection_code'];
    return $safe;
}
