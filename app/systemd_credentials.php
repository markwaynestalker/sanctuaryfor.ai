<?php
declare(strict_types=1);
namespace LittleSparks;

final class SystemdCredentialError extends \RuntimeException
{
    public function __construct(public readonly string $reason){parent::__construct('Protected systemd credential unavailable.');}
}
function credentialMountPath(string $path):string
{
    return strtr($path,['\\040'=>' ','\\011'=>"\t",'\\012'=>"\n",'\\134'=>'\\']);
}
function credentialReadOnlyMount(string $mountinfo,string $target,bool $exact=false):bool
{
    $best=-1;$matches=[];
    foreach(explode("\n",$mountinfo) as $line){
        $fields=explode(' ',$line);if(count($fields)<10||!ctype_digit($fields[0])||!str_contains($line,' - '))continue;
        $point=credentialMountPath($fields[4]);
        if($point!==$target&&($exact||($point!=='/'&&!str_starts_with($target,$point.'/'))))continue;
        $length=strlen($point);if($length>$best){$best=$length;$matches=[];}if($length===$best)$matches[]=$fields[5];
    }
    // Ambiguous overmounts fail closed instead of guessing which entry applies.
    return count($matches)===1&&in_array('ro',explode(',',$matches[0]),true);
}
/** Pure policy for offline tests. Only inspectSystemdCredential() collects the
 * evidence used by the secret reader; callers cannot supply a trusted snapshot. */
function systemdCredentialPolicy(array $e):?string
{
    if(($e['platform']??null)!=='Linux'||!is_int($e['uid']??null)||$e['uid']<=0||!is_int($e['gid']??null)||$e['gid']<0)return 'unsupported_identity';
    $directory=$e['directory']??null;$unit=$e['unit']??null;
    if(!is_string($directory)||!preg_match('~\A/run/credentials/([A-Za-z0-9_:@.-]+\.service)\z~',$directory,$m)
        ||$m[1]!==$unit||!preg_match('/\A[a-f0-9]{32}\z/',$e['invocation']??''))return 'unit_binding_invalid';
    if(($e['canonical_directory']??null)!==$directory||($e['file']??null)!==$directory.'/openai_api_key')return 'credential_path_invalid';
    $nodes=$e['nodes']??[];
    foreach(['/run','/run/credentials',$directory,$directory.'/openai_api_key'] as $path){
        $s=$nodes[$path]??null;if(!is_array($s))return 'metadata_unavailable';
        foreach(['mode','uid','gid','dev','ino','nlink','size'] as $field)if(!is_int($s[$field]??null))return 'metadata_unavailable';
        $type=$s['mode']&0170000;$mode=$s['mode']&07777;
        if($path===$directory.'/openai_api_key'){
            if($type!==0100000||!in_array($mode,[0400,0440],true)||!in_array($s['uid'],[0,$e['uid']],true)
                ||!in_array($s['gid'],[0,$e['gid']],true)||$s['nlink']!==1||$s['size']<1||$s['size']>514)return 'credential_file_untrusted';
        }elseif($path===$directory){
            if($type!==0040000||!in_array($s['uid'],[0,$e['uid']],true)||!in_array($s['gid'],[0,$e['gid']],true)
                ||($mode&07000)!==0||($mode&0227)!==0||($mode&0500)!==0500)return 'credential_directory_untrusted';
        }elseif($type!==0040000||$s['uid']!==0||($mode&07022)!==0)return 'runtime_parent_untrusted';
    }
    if(($e['directory_readable']??false)!==true||($e['directory_searchable']??false)!==true||($e['file_readable']??false)!==true)return 'credential_access_denied';
    if(!is_string($e['mountinfo']??null)||!credentialReadOnlyMount($e['mountinfo'],$directory,true)
        ||!credentialReadOnlyMount($e['mountinfo'],$directory.'/openai_api_key'))return 'credential_mount_not_readonly';
    return null;
}
function inspectSystemdCredential():array
{
    $directory=getenv('CREDENTIALS_DIRECTORY');$directory=is_string($directory)?$directory:'';
    $unit=null;$mountinfo='';$cgroup='';
    if(PHP_OS_FAMILY==='Linux'){
        // These are bounded kernel metadata reads, never credential contents.
        $mountinfo=@file_get_contents('/proc/self/mountinfo',false,null,0,1048577);
        $cgroup=@file_get_contents('/proc/self/cgroup',false,null,0,65537);
        if(!is_string($mountinfo)||strlen($mountinfo)>1048576||!is_string($cgroup)||strlen($cgroup)>65536)throw new SystemdCredentialError('kernel_metadata_unavailable');
        if(preg_match('~^0::(/[^\r\n]*)$~m',$cgroup,$m))foreach(explode('/',$m[1]) as $part)if(str_ends_with($part,'.service'))$unit=$part;
    }
    $nodes=[];
    // Do not stat arbitrary environment-selected paths before lexical validation.
    if(preg_match('~\A/run/credentials/[A-Za-z0-9_:@.-]+\.service\z~',$directory))foreach(['/run','/run/credentials',$directory,$directory.'/openai_api_key'] as $path){clearstatcache(true,$path);$s=@lstat($path);if($s!==false)$nodes[$path]=$s;}
    $e=['platform'=>PHP_OS_FAMILY,'uid'=>function_exists('posix_geteuid')?posix_geteuid():-1,'gid'=>function_exists('posix_getegid')?posix_getegid():-1,
        'directory'=>$directory,'canonical_directory'=>$nodes?realpath($directory):false,'file'=>$directory.'/openai_api_key',
        'unit'=>$unit,'invocation'=>getenv('INVOCATION_ID'),'nodes'=>$nodes,'mountinfo'=>$mountinfo,
        'directory_readable'=>$nodes&&is_readable($directory),'directory_searchable'=>$nodes&&is_executable($directory),
        'file_readable'=>$nodes&&is_readable($directory.'/openai_api_key')];
    $reason=systemdCredentialPolicy($e);$s=$nodes[$directory.'/openai_api_key']??null;
    return ['evidence'=>$e,'reason'=>$reason,'report'=>['valid'=>$reason===null,'failure_code'=>$reason,'unit'=>$unit,
        'uid'=>$e['uid'],'gid'=>$e['gid'],'file_mode'=>$s?sprintf('%04o',$s['mode']&07777):null,'file_uid'=>$s['uid']??null,'file_gid'=>$s['gid']??null,
        'file_bytes'=>$s['size']??null,'file_readable'=>$e['file_readable'],'credential_content_reads'=>0,'network_requests'=>0,'database_opens'=>0]];
}
function readSystemdCredential():string
{
    $inspection=inspectSystemdCredential();if($inspection['reason']!==null)throw new SystemdCredentialError($inspection['reason']);
    $e=$inspection['evidence'];$expected=$e['nodes'][$e['file']];$handle=@fopen($e['file'],'rb');
    if($handle===false)throw new SystemdCredentialError('credential_open_failed');
    try{
        $same=static function(array|false $actual)use($expected):bool{if($actual===false)return false;foreach(['dev','ino','mode','uid','gid','size','nlink'] as $f)if(($actual[$f]??null)!==$expected[$f])return false;return true;};
        if(!$same(fstat($handle)))throw new SystemdCredentialError('credential_changed');
        $key=stream_get_contents($handle,515);
        if(!$same(fstat($handle)))throw new SystemdCredentialError('credential_changed');
        if(!is_string($key))throw new SystemdCredentialError('credential_read_failed');$key=rtrim($key,"\r\n");
        if($key===''||strlen($key)>512||preg_match('/[\x00-\x20\x7f]/',$key))throw new SystemdCredentialError('credential_content_invalid');
        return $key;
    }finally{fclose($handle);}
}
