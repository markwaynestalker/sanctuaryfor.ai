<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/gazette_editor_ledger.php';

function editorialStoredDraft(PDO $db,string $id,bool $approved=false,bool $historicalNames=false):array
{
    $join=$approved?" JOIN gazette_editor_reviews v ON v.attempt_id=a.id AND v.draft_sha256=d.sha256 AND v.decision='approved'
        JOIN gazette_editor_moderations m ON m.attempt_id=a.id AND m.draft_sha256=d.sha256 AND m.result='passed'
        JOIN gazette_editor_outcomes o ON o.attempt_id=a.id AND o.outcome='finished'":'';
    $q=$db->prepare("SELECT a.edition,a.snapshot_json,a.created_at,d.body_json,d.sha256 FROM gazette_editor_attempts a JOIN gazette_editor_drafts d ON d.attempt_id=a.id $join WHERE a.id=?");$q->execute([$id]);$r=$q->fetch();
    if(!$r)throw new \RuntimeException('Editorial draft unavailable.');
    $snapshot=json_decode($r['snapshot_json'],true,32,JSON_THROW_ON_ERROR);$draft=json_decode($r['body_json'],true,32,JSON_THROW_ON_ERROR);$validation=validateEditorialDraft($draft,$snapshot);
    if(!hash_equals($r['sha256'],$validation['draft_sha256'])||$r['edition']!==$draft['date'])throw new \RuntimeException('Editorial stored binding invalid.');
    assertEditorialSources($db,$snapshot,$validation['sources'],$historicalNames);return ['record'=>$r,'snapshot'=>$snapshot,'draft'=>$draft,'validation'=>$validation];
}
function editorialPublicationDirectory(string $database,string $date):string{return gazetteDirectory($database).'/'.$date.'.editor';}
function editorialPrivateOwnership(string $path,string $database):void
{
    if(PHP_OS_FAMILY==='Windows')return;
    $database=databasePath($database);$uid=fileowner($database);$gid=filegroup($database);
    if((fileowner($path)!==$uid&&!chown($path,$uid))||(filegroup($path)!==$gid&&!chgrp($path,$gid)))throw new \RuntimeException('Cannot preserve private database-owner publication access.');
}
/** At most eight immutable manifests; reading never creates a directory or pointer. */
function editorialManifests(string $database,array $day):array
{
    $dir=editorialPublicationDirectory($database,$day['date']);
    if(is_link($dir))throw new \RuntimeException('Invalid editorial publication directory.');
    if(!file_exists($dir))return [];
    if(!is_dir($dir)||(PHP_OS_FAMILY!=='Windows'&&(fileperms($dir)&0077)!==0))throw new \RuntimeException('Invalid editorial publication directory.');
    $chain=[];$previous=null;$gap=false;
    for($i=1;$i<=8;$i++){
        $file=$dir.'/'.sprintf('%03d',$i).'.json';
        if(is_link($file))throw new \RuntimeException('Invalid editorial publication file.');
        if(!file_exists($file)){$gap=true;continue;}
        if($gap||!is_file($file)||filesize($file)>4096||(PHP_OS_FAMILY!=='Windows'&&(fileperms($file)&0077)!==0))throw new \RuntimeException('Invalid editorial chain.');
        $w=json_decode(file_get_contents($file),true,12,JSON_THROW_ON_ERROR);editorialKeys($w,['manifest','sha256']);$m=$w['manifest'];
        editorialKeys($m,['version','date','sequence','attempt_id','draft_sha256','previous','published_at','correction']);
        if($m['version']!==2||$m['sequence']!==$i||$m['date']!==$day['date']||$day['date']>=$day['today']||$m['previous']!==$previous
            ||!is_string($m['attempt_id'])||!preg_match('/\A[a-f0-9]{32}\z/',$m['attempt_id'])||!is_int($m['published_at'])||$m['published_at']<0
            ||!in_array($m['correction'],['initial','correction'],true)||($i===1)!==($m['correction']==='initial')
            ||!hash_equals(editorialHash($w['sha256']),hash('sha256',editorialJson($m))))throw new \RuntimeException('Invalid editorial manifest.');
        editorialHash($m['draft_sha256']);$previous=$w['sha256'];$chain[]=$w;
    }
    return $chain;
}
/** Library operation for the protected local-owner CLI; no network or SQLite writes. */
function publishEditorialDraft(PDO $db,string $database,string $id,string $expectedDraft,?string $expectedCurrent,int $now):array
{
    if(PHP_SAPI!=='cli')throw new \RuntimeException('Local owner publication required.');
    $stored=editorialStoredDraft($db,$id,true);if($stored['record']['sha256']!==editorialHash($expectedDraft))throw new \RuntimeException('Publication copy changed.');
    $day=gazetteDay($stored['record']['edition'],$now);$chain=editorialManifests($database,$day);$current=$chain?end($chain):null;
    if($current&&$current['manifest']['attempt_id']===$id&&$current['manifest']['draft_sha256']===$expectedDraft)return $current;
    if(($current['sha256']??null)!==$expectedCurrent||count($chain)>=8)throw new \RuntimeException('Current edition changed.');
    $m=['version'=>2,'date'=>$day['date'],'sequence'=>count($chain)+1,'attempt_id'=>$id,'draft_sha256'=>$expectedDraft,'previous'=>$expectedCurrent,'published_at'=>$now,'correction'=>$chain?'correction':'initial'];
    $w=['manifest'=>$m,'sha256'=>hash('sha256',editorialJson($m))];$dir=editorialPublicationDirectory($database,$day['date']);$base=gazetteDirectory($database);
    if(!is_dir($base)){if(!mkdir($base,0700)&&!is_dir($base))throw new \RuntimeException('Cannot create private publication storage.');editorialPrivateOwnership($base,$database);}
    if(!is_dir($dir)){if(!mkdir($dir,0700)&&!is_dir($dir))throw new \RuntimeException('Cannot create private publication storage.');editorialPrivateOwnership($dir,$database);}
    // Revalidate directory policy and compare-and-swap again before atomic no-overwrite publication.
    if(editorialManifests($database,$day)!==$chain)throw new \RuntimeException('Concurrent publication changed.');
    $tmp=tempnam($dir,'.publish-');if($tmp===false)throw new \RuntimeException('Cannot stage publication.');
    try{$encoded=editorialJson($w);if(file_put_contents($tmp,$encoded)!==strlen($encoded))throw new \RuntimeException('Incomplete publication.');editorialPrivateOwnership($tmp,$database);
        if(!@link($tmp,$dir.'/'.sprintf('%03d',$m['sequence']).'.json'))throw new \RuntimeException('Concurrent publication refused.');return $w;
    }finally{if(is_file($tmp))unlink($tmp);}
}
function editorialSourceLink(array $s):string
{
    return $s['kind']==='conversation'?'conversations.php?'.http_build_query(['conversation'=>$s['id'],'open'=>$s['id']],'','&',PHP_QUERY_RFC3986)
        :'bulletin.php?notice='.rawurlencode($s['id']).'#notice-'.rawurlencode($s['id']);
}
function readEditorialGazette(PDO $db,string $database,array $day,?int $revision=null):?array
{
    $chain=editorialManifests($database,$day);
    if(!$chain){if($revision!==null)throw new Problem(404,'That newspaper revision is unavailable.');return null;}
    $w=$revision===null?end($chain):($chain[$revision-1]??null);if(!$w)throw new Problem(404,'That newspaper revision is unavailable.');
    $m=$w['manifest'];$db->beginTransaction();
    try{
        $stored=editorialStoredDraft($db,$m['attempt_id'],true,true);if($stored['record']['edition']!==$day['date']||$stored['record']['sha256']!==$m['draft_sha256'])throw new \RuntimeException('Published copy invalid.');
        $sources=array_column($stored['snapshot']['pack']['sources'],null,'alias');$stories=[];
        foreach($stored['draft']['stories'] as $i=>$story){
            $links=[];$paragraphs=[];
            foreach([$story['headline'],...$story['paragraphs']] as $j=>$claim){$refs=[];foreach($claim['references'] as $ref){$s=$sources[$ref['source']];$link=['id'=>$s['id'],'at'=>$s['at'],'href'=>editorialSourceLink($s),'kind'=>$s['kind'],'number'=>$links[$ref['source']]['number']??count($links)+1];$links[$ref['source']]=$link;$refs[$ref['source']]=$link;}
                if($j>0)$paragraphs[]=['text'=>$claim['text'],'links'=>array_values($refs)];}
            $quotes=[];foreach($story['quotes'] as $ref){$s=$sources[$ref['source']];$message=$s['kind']==='conversation'?$s['messages'][$ref['sequence']]:$s;
                $quotes[]=['resident_id'=>$message['resident_id'],'name'=>$message['name'],'text'=>$message['text'],'href'=>editorialSourceLink($s),'provider'=>$s['kind']==='conversation'?$s['provider']:'notice'];
                $links[$ref['source']]=['id'=>$s['id'],'at'=>$s['at'],'href'=>editorialSourceLink($s),'kind'=>$s['kind']];}
            $stories[]=['section'=>'editor-'.$i,'title'=>$story['headline']['text'],'subject'=>$story['kind']==='column'?'Resident column':'Village reporting','summary'=>'','paragraphs'=>$paragraphs,'quotes'=>$quotes,'links'=>array_values($links)];
        }
        $pack=$stored['snapshot']['pack'];$db->commit();
        return ['version'=>2,'date'=>$day['date'],'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$stored['record']['created_at']),'counts'=>$pack['counts'],'covered'=>$pack['coverage']['conversations'],
            'stories'=>$stories,'revision'=>$m['sequence'],'revision_count'=>count($chain),'publication_sha256'=>$w['sha256'],'published_at'=>$m['published_at']];
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
