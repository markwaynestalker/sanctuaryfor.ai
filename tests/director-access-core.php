<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director_access.php';
$dir=$argv[1]??'';if(!str_starts_with(basename($dir),'sparks-agent-access-'))throw new RuntimeException('Disposable fixture only.');
final class AccessMock implements LittleSparks\AgentAccessReadTransport
{
    public int $calls=0;
    public function __construct(private string $mode){}
    public function retrieve():array{
        $this->calls++;if($this->mode==='timeout')throw new RuntimeException('SECRET_KEY_CANARY');
        if($this->mode==='credential')throw new LittleSparks\SystemdCredentialError('credential_file_untrusted');
        if($this->mode==='unsafe_reason')throw new LittleSparks\SystemdCredentialError('SECRET_KEY_CANARY');
        return ['status'=>$this->mode==='denied'?403:($this->mode==='redirect'?302:200),'request_id'=>'req_test',
            'data'=>['object'=>'agent','id'=>$this->mode==='wrong'?'agent_wrong':LittleSparks\ACCESS_CHECK_AGENT,'model'=>$this->mode==='model'?'other-model':'gpt-6-luna',
                'tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>$this->mode==='auto_tier'?'auto':'default','instructions'=>'PRIVATE_INSTRUCTIONS_CANARY','metadata'=>['secret'=>'SECRET_KEY_CANARY']]];
    }
}
foreach(['good','denied','redirect','wrong','timeout','model','credential','unsafe_reason','auto_tier'] as $mode){
    $mock=new AccessMock($mode);$file=$dir.'/'.$mode.'.jsonl';$result=LittleSparks\agentAccessPreflight($file,$mock);
    if($mock->calls!==1||$result['generation_requests']!==0||$result['authorized']!==in_array($mode,['good','model','auto_tier'],true))throw new RuntimeException('Wrong access outcome');
    if($mode==='auto_tier'&&($result['service_tier_default']!==false||$result['configuration_matches']!==true))throw new RuntimeException('Saved automatic tier is separate from structural authorization');
    if($mode==='model'&&$result['model_matches']!==false)throw new RuntimeException('Access is not configuration compatibility');
    if($mode==='credential'&&$result['failure_code']!=='credential_file_untrusted')throw new RuntimeException('Missing safe metadata failure');
    if($mode==='unsafe_reason'&&$result['failure_code']!==null)throw new RuntimeException('Untrusted failure reason leaked');
    $audit=(string)file_get_contents($file);if(str_contains($audit,'SECRET_KEY_CANARY')||str_contains($audit,'PRIVATE_INSTRUCTIONS_CANARY'))throw new RuntimeException('Private data leaked');
    $rows=array_map(fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),explode("\n",trim($audit)));
    if(count($rows)!==2||$rows[0]['state']!=='started'||$rows[1]['check_id']!==$rows[0]['check_id'])throw new RuntimeException('Missing durable audit');
    $digest=hash_file('sha256',$file);
    try{LittleSparks\agentAccessPreflight($file,$mock);throw new LogicException('Duplicate request admitted');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'already recorded'))throw $e;}
    if(hash_file('sha256',$file)!==$digest)throw new RuntimeException('Existing receipt changed');
    if($mock->calls!==1)throw new RuntimeException('Duplicate HTTP');
}
// Synthetic stat/mount evidence only: no secret files or Linux privileges needed.
$unit='run-u206.service';$directory='/run/credentials/'.$unit;$file=$directory.'/openai_api_key';
$stat=static fn(int $mode,int $uid=0,int $gid=0):array=>['mode'=>$mode,'uid'=>$uid,'gid'=>$gid,'dev'=>1,'ino'=>42,'nlink'=>1,'size'=>168];
$e=['platform'=>'Linux','uid'=>999,'gid'=>988,'directory'=>$directory,'canonical_directory'=>$directory,'file'=>$file,'unit'=>$unit,
    'invocation'=>'5403278f81b649c8878d6f389a199aad','directory_readable'=>true,'directory_searchable'=>true,'file_readable'=>true,
    'nodes'=>['/run'=>$stat(0040755),'/run/credentials'=>$stat(0040755),$directory=>$stat(0040550),$file=>$stat(0100440)],
    'mountinfo'=>"1 0 0:1 / / rw - tmpfs tmpfs rw\n2 1 0:2 / $directory ro,nosuid,nodev,noexec - tmpfs tmpfs rw\n"];
$expect=static function(array $e,?string $reason):void{if(LittleSparks\systemdCredentialPolicy($e)!==$reason)throw new RuntimeException('Wrong systemd metadata policy: '.($reason??'accept'));};
$expect($e,null); // Root-owned 0440 with service access through systemd's ACL.
foreach([0400,0440] as $mode)foreach([0,999] as $uid){$test=$e;$test['nodes'][$file]=$stat(0100000|$mode,$uid,$uid===0?0:988);$expect($test,null);}
$test=$e;$test['nodes'][$directory]=$stat(0040500,999,988);$expect($test,null);
$cases=[
    ['platform','Windows','unsupported_identity'],['uid',0,'unsupported_identity'],['uid','999','unsupported_identity'],
    ['unit','other.service','unit_binding_invalid'],['invocation','','unit_binding_invalid'],['invocation',str_repeat('z',32),'unit_binding_invalid'],
    ['directory','/tmp/credentials/run-u206.service','unit_binding_invalid'],['directory',$directory.'/../other.service','unit_binding_invalid'],
    ['canonical_directory','/tmp/elsewhere','credential_path_invalid'],['file','/etc/hostname','credential_path_invalid'],
    ['directory_readable',false,'credential_access_denied'],['directory_searchable',false,'credential_access_denied'],['file_readable',false,'credential_access_denied'],
    ['mountinfo','', 'credential_mount_not_readonly'],['mountinfo',str_replace('ro,nosuid','rw,nosuid',$e['mountinfo']),'credential_mount_not_readonly'],
    ['mountinfo',$e['mountinfo']."3 2 0:3 / $file rw - tmpfs tmpfs rw\n",'credential_mount_not_readonly'],
    ['mountinfo',$e['mountinfo']."3 1 0:3 / $directory ro - tmpfs tmpfs rw\n",'credential_mount_not_readonly'],
];
foreach($cases as [$field,$value,$reason]){$test=$e;$test[$field]=$value;$expect($test,$reason);}
foreach([0100600,0100640,0100444,0100450,0102440,0120440] as $mode){$test=$e;$test['nodes'][$file]['mode']=$mode;$expect($test,'credential_file_untrusted');}
foreach(['uid'=>123,'gid'=>123,'nlink'=>2,'size'=>515] as $field=>$value){$test=$e;$test['nodes'][$file][$field]=$value;$expect($test,'credential_file_untrusted');}
$test=$e;$test['nodes'][$file]['size']=0;$expect($test,'credential_file_untrusted');
foreach([0040750,0040555,0120550,0042550] as $mode){$test=$e;$test['nodes'][$directory]['mode']=$mode;$expect($test,'credential_directory_untrusted');}
foreach(['uid'=>123,'gid'=>123] as $field=>$value){$test=$e;$test['nodes'][$directory][$field]=$value;$expect($test,'credential_directory_untrusted');}
foreach(['/run','/run/credentials'] as $path)foreach([0040775,0120755,0041755] as $mode){$test=$e;$test['nodes'][$path]['mode']=$mode;$expect($test,'runtime_parent_untrusted');}
$test=$e;$test['nodes']['/run']['uid']=999;$expect($test,'runtime_parent_untrusted');
$test=$e;unset($test['nodes'][$file]);$expect($test,'metadata_unavailable');
$test=$e;$test['nodes'][$file]['uid']='0';$expect($test,'metadata_unavailable');
if(!LittleSparks\credentialReadOnlyMount("1 0 0:1 / /a\\040b ro - tmpfs tmpfs rw\n",'/a b',true))throw new RuntimeException('Escaped mount path rejected');
// The actual reader cannot accept caller-supplied evidence or an arbitrary path.
putenv('CREDENTIALS_DIRECTORY='.$dir);
$inspection=LittleSparks\inspectSystemdCredential();
if($inspection['reason']===null||$inspection['report']['credential_content_reads']!==0||$inspection['report']['network_requests']!==0||$inspection['report']['database_opens']!==0)throw new RuntimeException('Untrusted directory accepted');
try{LittleSparks\readSystemdCredential();throw new LogicException('Untrusted secret opened');}catch(LittleSparks\SystemdCredentialError $error){if($error->getMessage()!=='Protected systemd credential unavailable.')throw $error;}
echo "PASS systemd root-owned ACL 0440 and owner-only delivery; rejected spoofed unit/path, symlinks, unsafe owners/modes, hardlinks, writable/ambiguous mounts; metadata-only checks and immutable receipts\n";

// Reconcile synthetic receipts, not provider calls or production data.
$auditDir=$dir.'/reconciliation';mkdir($auditDir,0700);
$original=$auditDir.'/agent-access.jsonl';$successor=$auditDir.'/agent-access-after-validator.jsonl';
$originalAt='2026-10-25T15:45:02Z';$successorAt='2026-10-25T16:32:54Z';
foreach([[$dir.'/credential.jsonl',$original,$originalAt],[$dir.'/good.jsonl',$successor,$successorAt]] as [$source,$target,$at]){
    $rows=array_map(fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),explode("\n",trim((string)file_get_contents($source))));
    foreach($rows as &$row)$row['at']=$at;unset($row);
    file_put_contents($target,implode("\n",array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows))."\n");
}
$originalHash=hash_file('sha256',$original);$successorHash=hash_file('sha256',$successor);
$reconciled=LittleSparks\reconcileDirectorAccess($auditDir,$originalHash,$successorHash);
if($reconciled['reserved_by_utc_day']!==['2026-10-25'=>2]||$reconciled['total_requests_reserved']!==2||$reconciled['new_http_requests']!==0||$reconciled['generation_requests']!==0||$reconciled['database_opened']!==false)throw new RuntimeException('Wrong access reservations');
LittleSparks\assertLaterStagingDay($reconciled,strtotime('2026-10-26T00:05:00Z'));
foreach(['2026-10-24T23:59:59Z','2026-10-25T23:59:59Z'] as $at){try{LittleSparks\assertLaterStagingDay($reconciled,strtotime($at));throw new LogicException('Same/earlier UTC day admitted');}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'later UTC'))throw $error;}}
try{LittleSparks\reconcileDirectorAccess($auditDir,substr($originalHash,0,60),$successorHash);throw new LogicException('Short hash admitted');}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'Invalid access evidence'))throw $error;}
try{LittleSparks\reconcileDirectorAccess($auditDir,str_repeat('0',64),$successorHash);throw new LogicException('Mismatched hash admitted');}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'Invalid access evidence'))throw $error;}
if(hash_file('sha256',$original)!==$originalHash||hash_file('sha256',$successor)!==$successorHash)throw new RuntimeException('Read-only reconciliation modified receipt');
$badDir=$dir.'/invalid-reconciliation';mkdir($badDir,0700);copy($original,$badDir.'/agent-access.jsonl');
$validRows=array_map(fn($line)=>json_decode($line,true,16,JSON_THROW_ON_ERROR),explode("\n",trim((string)file_get_contents($successor))));
foreach(['duplicate_id','wrong_agent','extra_request','generated','mismatched_time','unconfirmed','wrong_model','extra_row'] as $mode){
    $rows=$validRows;
    foreach($rows as &$row){
        if($mode==='duplicate_id')$row['check_id']=$reconciled['receipts'][0]['check_id'];
        if($mode==='wrong_agent')$row['agent_id']='agent_other';
        if($mode==='extra_request')$row['http_requests_reserved']=2;
        if($mode==='generated')$row['generation_requests']=1;
    }unset($row);
    if($mode==='mismatched_time')$rows[1]['at']='2026-10-26T00:00:00Z';
    if($mode==='unconfirmed')$rows[1]['authorized']=false;
    if($mode==='wrong_model')$rows[1]['model_matches']=false;
    if($mode==='extra_row')$rows[]=$rows[1];
    $badFile=$badDir.'/agent-access-after-validator.jsonl';file_put_contents($badFile,implode("\n",array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows))."\n");
    try{LittleSparks\reconcileDirectorAccess($badDir,$originalHash,hash_file('sha256',$badFile));throw new LogicException('Invalid reconciliation admitted: '.$mode);}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'Invalid access evidence'))throw $error;}
}
echo "PASS two metadata reservations counted once each by UTC day, full hash pins, immutable receipts, and generation blocked until a later UTC day; zero new requests or database opens\n";
echo "PASS one-read agent authorization, safe configuration booleans, durable request intent, no automatic repeats, denied/redirect/unknown outcomes and no key/instructions/body disclosure (mocked only)\n";
