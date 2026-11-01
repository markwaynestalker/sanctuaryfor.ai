<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_job.php';require __DIR__.'/../app/gazette_editor_publication.php';
require __DIR__.'/../app/gazette_auto_publication.php';
try{
    // Production owner boundary, separate from the non-root generating service.
    if(PHP_OS_FAMILY!=='Linux'||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('Linux local owner required.');
    $o=getopt('',['database:','action:','attempt:','sha256:','decision:','confirm-reviewed:','expected-current:','date:','ordinal:','source-sha256:','policy-sha256:','max-microusd:','expires-at:','auto-policy:','release:']);
    if(!is_string($o['database']??null)||$o['database']==='')throw new RuntimeException('Explicit private database required.');
    // Authorize as root, then permanently use the database account so SQLite journals/files retain service ownership.
    $private=LittleSparks\databasePath($o['database']);$uid=fileowner($private);$gid=filegroup($private);
    if(!is_int($uid)||!is_int($gid))throw new RuntimeException('Private database ownership unavailable.');$account=posix_getpwuid($uid);
    if($uid===0||!$account||!posix_initgroups($account['name'],$gid)||!posix_setgid($gid)||!posix_setuid($uid))throw new RuntimeException('Cannot assume private database-owner identity.');
    $db=LittleSparks\connect($o['database']);$now=time();$action=$o['action']??'';
    if($action==='show'){$db->exec('PRAGMA query_only=ON');$s=LittleSparks\editorialStoredDraft($db,(string)($o['attempt']??''));$day=LittleSparks\gazetteDay($s['record']['edition'],$now);$chain=LittleSparks\editorialManifests($o['database'],$day);
        echo json_encode(['draft_sha256'=>$s['record']['sha256'],'current_publication_sha256'=>$chain?end($chain)['sha256']:null,'preview'=>$s['snapshot']['pack']['preview']??null,'draft'=>$s['draft'],'sources'=>$s['snapshot']['pack']['sources']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    }elseif($action==='plan'){$db->exec('PRAGMA query_only=ON');$p=LittleSparks\editorialJobPlan($db,(string)($o['date']??''),LittleSparks\editorialConfiguration(false),LittleSparks\unsignedEnvironmentInteger('LITTLE_SPARKS_GAZETTE_EDITOR_MAX_REQUEST_BYTES'),$now);
        $q=$db->prepare('SELECT MAX(ordinal) FROM (SELECT ordinal FROM gazette_editor_attempts WHERE edition=? UNION ALL SELECT ordinal FROM gazette_editor_permits WHERE edition=?)');$q->execute([$o['date'],$o['date']]);$next=max(2,(int)$q->fetchColumn()+1);
        echo json_encode(['source_sha256'=>$p['snapshot']['sha256'],'policy_sha256'=>hash('sha256',LittleSparks\editorialJson($p['policy'])),'reservation_microusd'=>LittleSparks\editorialReservationCost($p['policy']),'next_unused_revision_ordinal'=>$next<=8?$next:null,'coverage'=>$p['snapshot']['pack']['coverage']],JSON_THROW_ON_ERROR)."\n";
    }elseif($action==='auto-publish'){
        $policy=json_decode((string)($o['auto-policy']??''),true,8,JSON_THROW_ON_ERROR);
        $read=LittleSparks\connect($o['database']);$read->exec('PRAGMA query_only=ON');$id=(string)($o['attempt']??'');
        $s=LittleSparks\editorialStoredDraft($read,$id);
        echo json_encode(LittleSparks\autoPublishEditorialDraft($db,$read,$o['database'],$id,$s['record']['sha256'],$policy,(string)($o['release']??''),$now),JSON_THROW_ON_ERROR)."\n";
    }elseif($action==='review'){
        if(($o['confirm-reviewed']??null)!=='sources-and-claims')throw new RuntimeException('Factual review confirmation required.');
        LittleSparks\reviewEditorialDraft($db,(string)($o['attempt']??''),(string)($o['sha256']??''),(string)($o['decision']??''),$now);echo "Exact draft review recorded; no publication performed.\n";
    }elseif($action==='publish'){
        $current=$o['expected-current']??'';$current=$current==='none'?null:LittleSparks\editorialHash($current);
        $db->exec('PRAGMA query_only=ON');echo json_encode(LittleSparks\publishEditorialDraft($db,$o['database'],(string)($o['attempt']??''),(string)($o['sha256']??''),$current,$now),JSON_THROW_ON_ERROR)."\n";
    }elseif($action==='permit'){
        $number=static function(string $key)use($o):int{$v=$o[$key]??'';if(!is_string($v)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$v)||filter_var($v,FILTER_VALIDATE_INT)===false)throw new RuntimeException('Invalid permit integer.');return (int)$v;};
        if(($o['confirm-reviewed']??null)!=='sources-and-claims')throw new RuntimeException('Revision review confirmation required.');
        echo json_encode(['permit_id'=>LittleSparks\permitEditorialRevision($db,(string)($o['date']??''),$number('ordinal'),(string)($o['source-sha256']??''),(string)($o['policy-sha256']??''),$number('max-microusd'),$number('expires-at'),$now)],JSON_THROW_ON_ERROR)."\n";
    }else throw new RuntimeException('Unknown owner action.');
}catch(Throwable){fwrite(STDERR,"Owner editorial operation refused. Check the exact copy/current-edition digest, source authorization and completed moderation. No paid calls were made.\n");exit(1);}
