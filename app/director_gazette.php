<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/gazette_editor_publication.php';

const DIRECTOR_GAZETTE_BYTES=4096;

/** Exact immutable editorial edition; usable under an existing SQLite lock too. */
function directorGazetteEdition(PDO $db,string $date,int $now,?string $expected=null):?array
{
    if(!editorialLedgerInstalled($db))return null;
    $database=$db->query('PRAGMA database_list')->fetch()['file'];$day=gazetteDay($date,$now);
    if($date>=$day['today'])return null;
    $chain=editorialManifests($database,$day);$selected=null;
    foreach($chain as $entry)if($entry['manifest']['published_at']<=$now&&($expected===null||$entry['sha256']===$expected))$selected=$entry;
    if(!$selected)return null;
    $m=$selected['manifest'];$stored=editorialStoredDraft($db,$m['attempt_id'],true,true);
    if($stored['record']['edition']!==$date||$stored['record']['sha256']!==$m['draft_sha256']||($stored['snapshot']['pack']['preview']['partial']??false)===true)throw new \RuntimeException('Published reporting binding invalid.');
    return ['date'=>$date,'publication_sha256'=>$selected['sha256'],'published_at'=>$m['published_at'],'stored'=>$stored];
}

/** Complete article with exact prose/quotes; no raw third-party transcript or aliases. */
function directorGazetteArticle(array $edition,int $index):array
{
    $stored=$edition['stored'];$story=$stored['draft']['stories'][$index]??null;if(!$story)throw new \RuntimeException('Published article unavailable.');
    $sources=array_column($stored['snapshot']['pack']['sources'],null,'alias');$citations=[];$speakers=[];
    foreach([$story['headline'],...$story['paragraphs']] as $claim)foreach($claim['references'] as $ref){
        $s=$sources[$ref['source']];$citations[$s['kind'].':'.$s['id']]=['kind'=>$s['kind'],'id'=>$s['id']];
        $speakers[]=$s['kind']==='conversation'?$s['messages'][$ref['sequence']]['resident_id']:$s['resident_id'];
    }
    $quotes=[];foreach($story['quotes'] as $ref){$s=$sources[$ref['source']];$m=$s['kind']==='conversation'?$s['messages'][$ref['sequence']]:$s;
        $quotes[]=['resident_id'=>$m['resident_id'],'name'=>$m['name'],'text'=>$m['text']];$speakers[]=$m['resident_id'];
        $citations[$s['kind'].':'.$s['id']]=['kind'=>$s['kind'],'id'=>$s['id']];}
    $article=['edition_date'=>$edition['date'],'published_at'=>gmdate('Y-m-d\TH:i:s\Z',$edition['published_at']),
        'publication_sha256'=>$edition['publication_sha256'],'article_index'=>$index,'headline'=>$story['headline']['text'],
        'paragraphs'=>array_column($story['paragraphs'],'text'),'quotes'=>$quotes,'citations'=>array_values($citations)];
    return ['article'=>$article,'speakers'=>array_unique($speakers),'sha256'=>hash('sha256',editorialJson($article))];
}

/** Two completed Eastern dates, at most two complete articles and 4096 bytes. */
function directorGazetteContext(PDO $db,array $pair,int $now):array
{
    $local=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'));
    $edition=null;
    try{
        foreach([1,2] as $age){$date=$local->modify('-'.$age.' days')->format('Y-m-d');if($date<'1970-01-01')break;
            if($edition=directorGazetteEdition($db,$date,$now))break;}
        if(!$edition)return [];
        $candidates=[];$ids=array_column($pair,'id');
        foreach($edition['stored']['draft']['stories'] as $i=>$story){$candidate=directorGazetteArticle($edition,$i);$candidate['relevance']=count(array_intersect($ids,$candidate['speakers']));$candidates[]=$candidate;}
        usort($candidates,static fn($a,$b)=>($b['relevance']<=>$a['relevance'])?:($a['article']['article_index']<=>$b['article']['article_index']));
        $articles=[];foreach($candidates as $candidate){$trial=[...$articles,$candidate['article']];
            if(strlen(json_encode($trial,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=DIRECTOR_GAZETTE_BYTES)$articles=$trial;if(count($articles)===2)break;}
        return $articles;
    }catch(\Throwable){return [];}// Optional reporting failure never invents a paper or blocks unrelated conversation.
}

/** Receipt only for retained input; it is not proof of reading, belief or influence. */
function directorGazetteReceipt(array $articles):?array
{
    if(!$articles)return null;
    return ['version'=>1,'edition_date'=>$articles[0]['edition_date'],'publication_sha256'=>$articles[0]['publication_sha256'],
        'articles'=>array_map(static fn($a)=>['index'=>$a['article_index'],'sha256'=>hash('sha256',editorialJson($a)),'original_sources'=>$a['citations']],$articles)];
}

/** Runs inside the canonical accepted-conversation transaction, before commit. */
function saveDirectorGazetteExposure(PDO $db,string $run,string $conversation,array $pair,?array $receipt,string $time):void
{
    if($receipt===null)return;
    try{
        editorialKeys($receipt,['version','edition_date','publication_sha256','articles']);
        if($receipt['version']!==1||count($receipt['articles'])<1||count($receipt['articles'])>2)throw new \RuntimeException();
        $now=strtotime($time);$edition=directorGazetteEdition($db,$receipt['edition_date'],$now,$receipt['publication_sha256']);if(!$edition)throw new \RuntimeException();
        $seen=[];foreach($receipt['articles'] as $a){editorialKeys($a,['index','sha256','original_sources']);if(!is_int($a['index'])||isset($seen[$a['index']]))throw new \RuntimeException();$seen[$a['index']]=true;
            $current=directorGazetteArticle($edition,$a['index']);if($current['sha256']!==$a['sha256']||$current['article']['citations']!==$a['original_sources'])throw new \RuntimeException();}
        $q=$db->prepare("SELECT 1 FROM conversations c JOIN director_runs r ON r.conversation_id=c.id AND r.run_id=c.run_id WHERE c.id=? AND c.run_id=? AND c.visibility='public'");$q->execute([$conversation,$run]);if(!$q->fetchColumn())throw new \RuntimeException();
        $q=$db->prepare('SELECT resident_id FROM conversation_participants WHERE conversation_id=? ORDER BY resident_id');$q->execute([$conversation]);$ids=array_column($pair,'id');sort($ids);
        if(count($ids)!==2||$q->fetchAll(PDO::FETCH_COLUMN)!==$ids)throw new \RuntimeException();
        $audit=$receipt+['conversation_id'=>$conversation,'participant_ids'=>$ids,'recorded_at'=>$time,'influence_verified'=>false];
        $json=editorialJson($audit);if(strlen($json)>8192)throw new \RuntimeException();
        $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['director_gazette_context:'.$run,$json]);
    }catch(\Throwable){throw new AiGuard('apply_rejected');}
}
