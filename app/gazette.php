<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/conversation_archive.php';
require_once __DIR__.'/bulletin.php';

const GAZETTE_SOURCE_LIMIT=256;
const GAZETTE_FILE_LIMIT=262144;
function gazetteDay(mixed $value,?int $now=null):array
{
    $zone=new \DateTimeZone('America/New_York');$today=(new \DateTimeImmutable('@'.($now??time())))->setTimezone($zone)->format('Y-m-d');
    $value??=$today;
    if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$value)||$value<'1970-01-01'||$value>$today)throw new Problem(422,'Choose an Eastern date from 1970 through today.');
    $start=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
    if(!$start||$start->format('Y-m-d')!==$value)throw new Problem(422,'Choose a valid Eastern calendar date.');
    $end=$start->modify('+1 day');$utc=new \DateTimeZone('UTC');
    return ['date'=>$value,'today'=>$today,'start'=>$start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),'end'=>$end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        'label'=>$start->format('l, F j, Y'),'previous'=>$value==='1970-01-01'?null:$start->modify('-1 day')->format('Y-m-d'),'next'=>$value===$today?null:$end->format('Y-m-d')];
}
function gazetteSections():array
{
    // Transparent word grouping over full original transcripts, never topic-only inference.
    return [
        'table'=>['title'=>'Food for thought','subject'=>'Food and kitchen talk','pattern'=>'~\b(?:food|recipe|pie|coffee|tea|lasagna|supper|dinner|cake|jam|biscuit|cook|cooking|snack)\b~iu'],
        'play'=>['title'=>'Rules of play','subject'=>'Games and make-believe','pattern'=>'~\b(?:game|games|play|playing|dragon|imaginary|pretend|pokemon|pokémon|karaoke|puzzle)\b~iu'],
        'ideas'=>['title'=>'Bright ideas, open questions','subject'=>'Design and invention','pattern'=>'~\b(?:invent|invention|gadget|website|app|button|design|computer|robot|toolbox|repair)\b~iu'],
        'garden'=>['title'=>'Outside interests','subject'=>'Gardens and the changing skies','pattern'=>'~\b(?:garden|flowers|tree|plant|snow|rain|weather|stars|cloud|clouds|leaves|puddle)\b~iu'],
        'commons'=>['title'=>'The things we share','subject'=>'Community and customs','pattern'=>'~\b(?:village|committee|council|tradition|neighbor|neighbour|friend|friendship|invitation|party|gathering|scrapbook)\b~iu'],
        'other'=>['title'=>'Around the conversation','subject'=>'Other village conversation','pattern'=>null]
    ];
}
function gazetteSafeText(mixed $text):bool
{
    return is_string($text)&&preg_match('/\A[^\p{C}]{1,160}\z/u',$text)===1
        &&!preg_match('/\bsk-[a-zA-Z0-9_-]{8,}|Bearer\s+[a-zA-Z0-9_-]{8,}/i',$text);
}
function gazetteSourceHash(array $c):string
{
    return hash('sha256',json_encode([$c['id'],$c['created_at'],$c['provider'],array_map(static fn($m)=>[$m['resident_id'],$m['text']],$c['messages'])],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function gazetteSourceSetup(PDO $db):array
{
    $source=conversationPublicSource($db);$table=$source['messageTable'];
    $db->sqliteCreateFunction('gazette_safe_text',static fn($text)=>(int)gazetteSafeText($text),1,PDO::SQLITE_DETERMINISTIC);
    $db->sqliteCreateFunction('gazette_safe_name',static fn($text)=>(int)(gazetteSafeText($text)&&preg_match_all('/./us',$text)<=64),1,PDO::SQLITE_DETERMINISTIC);
    $source['sql'].=" AND gazette_safe_name(ra.name)=1 AND gazette_safe_name(rb.name)=1 AND NOT EXISTS(SELECT 1 FROM $table bad WHERE bad.conversation_id=c.id AND gazette_safe_text(bad.body)=0)";
    return $source;
}
/** Read-only source snapshot. Returns complete bounded transcripts, not private context. */
function collectGazette(PDO $db,array $day,int $now):array
{
    $db->beginTransaction();
    try{
        ['prefix'=>$prefix,'sql'=>$sql,'source'=>$provider,'topic'=>$topic]=gazetteSourceSetup($db);
        $sql.=' AND c.created_at>=:start AND c.created_at<:end AND c.created_at<=:asof';
        $args=[':start'=>$day['start'],':end'=>$day['end'],':asof'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
        $q=$db->prepare(rtrim($prefix).", eligible AS MATERIALIZED (SELECT c.id,a.resident_id AS first_id,b.resident_id AS second_id $sql)
            SELECT COUNT(*) AS conversations,(SELECT COUNT(*) FROM (SELECT first_id AS id FROM eligible UNION SELECT second_id FROM eligible)) AS residents FROM eligible");$q->execute($args);$counts=array_map('intval',$q->fetch());
        // Spread the bounded reporting sample over first speakers before taking
        // another turn from the same first speaker. Statistics still span the day.
        $q=$db->prepare($prefix."SELECT c.id,c.created_at,$provider AS provider,substr($topic,1,160) AS topic,
            ROW_NUMBER() OVER(PARTITION BY a.resident_id ORDER BY c.created_at,c.id) AS voice_rank $sql ORDER BY voice_rank,c.created_at,c.id LIMIT ".GAZETTE_SOURCE_LIMIT);$q->execute($args);$conversations=$q->fetchAll();
        $messages=conversationMessageBatch($db,array_column($conversations,'id'));
        foreach($conversations as &$c){$c['messages']=$messages[$c['id']]??[];unset($c['voice_rank']);}unset($c);
        $counts['notices']=0;
        if(bulletinInstalled($db)){
            $q=$db->prepare('SELECT COUNT(*)'.bulletinPublicSql().' AND n.created_at>=? AND n.created_at<? AND n.created_at<=?');$q->execute([$day['start'],$day['end'],$args[':asof']]);$counts['notices']=(int)$q->fetchColumn();
        }
        $db->commit();return ['counts'=>$counts,'conversations'=>$conversations];
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
function composeGazette(array $collected,array $day,int $now):array
{
    $sections=gazetteSections();$groups=[];
    foreach($collected['conversations'] as $c){
        $text=implode(' ',array_column($c['messages'],'text'));$best='other';$score=0;
        foreach($sections as $key=>$rule)if($rule['pattern']&&($hits=preg_match_all($rule['pattern'],$text))>$score){$best=$key;$score=$hits;}
        $groups[$best]['sources'][]=$c;
        foreach($c['messages'] as $m)$groups[$best]['residents'][$m['resident_id']]=true;
    }
    uksort($groups,static function($a,$b)use($groups){return count($groups[$b]['residents'])<=>count($groups[$a]['residents'])?:count($groups[$b]['sources'])<=>count($groups[$a]['sources'])?:strcmp($a,$b);});
    $groups=array_slice($groups,0,5,true);$stories=[];$used=[];$sources=[];$prepared=[];$availability=[];
    foreach($groups as $group)foreach($group['residents'] as $id=>$unused)$availability[$id]=($availability[$id]??0)+1;
    foreach($groups as $key=>$group){
        $options=[];
        foreach($group['sources'] as $c)foreach($c['messages'] as $sequence=>$m){
            $hits=$sections[$key]['pattern']?preg_match_all($sections[$key]['pattern'],$m['text']):0;
            $options[]=['conversation'=>$c,'sequence'=>$sequence,'message'=>$m,'hits'=>$hits];
        }
        $prepared[$key]=['options'=>$options,'quotes'=>[],'chosen'=>[]];
    }
    // Give every section a turn before adding another quote to the lead. Prefer
    // voices available in fewer sections when exposure counts tie.
    for($round=0;$round<3;$round++)foreach($prepared as &$part){
            usort($part['options'],static fn($a,$b)=>($used[$a['message']['resident_id']]??0)<=>($used[$b['message']['resident_id']]??0)?:$availability[$a['message']['resident_id']]<=>$availability[$b['message']['resident_id']]?:$b['hits']<=>$a['hits']?:strcmp($a['conversation']['created_at'],$b['conversation']['created_at'])?:strcmp($a['conversation']['id'],$b['conversation']['id'])?:$a['sequence']<=>$b['sequence']);
            $pick=null;
            foreach($part['options'] as $i=>$option)if(!isset($part['chosen'][$option['conversation']['id']])&&($used[$option['message']['resident_id']]??0)<2){$pick=$option;unset($part['options'][$i]);break;}
            if(!$pick)continue;
            $c=$pick['conversation'];$m=$pick['message'];$part['chosen'][$c['id']]=true;$used[$m['resident_id']]=($used[$m['resident_id']]??0)+1;
            $part['quotes'][]=['conversation_id'=>$c['id'],'sequence'=>$pick['sequence'],'resident_id'=>$m['resident_id'],'name'=>$m['name'],'text'=>$m['text'],'at'=>$c['created_at'],'provider'=>$c['provider']];
            $sources[$c['id']]=['id'=>$c['id'],'at'=>$c['created_at'],'sha256'=>gazetteSourceHash($c)];
    }unset($part);
    foreach($groups as $key=>$group){
        $quotes=$prepared[$key]['quotes'];
        // Even if the global quote cap excludes a dominant voice, retain a
        // bounded source trail for each reported thread.
        $links=[];foreach(array_slice($group['sources'],0,3) as $c){$links[]=['id'=>$c['id'],'at'=>$c['created_at']];$sources[$c['id']]=['id'=>$c['id'],'at'=>$c['created_at'],'sha256'=>gazetteSourceHash($c)];}
        foreach($quotes as $quote)if(!in_array($quote['conversation_id'],array_column($links,'id'),true))$links[]=['id'=>$quote['conversation_id'],'at'=>$quote['at']];
        $n=count($group['sources']);$people=count($group['residents']);$rule=$sections[$key];
        $summary=$rule['subject'].' '.($n===1?'featured in one saved exchange':'came up across '.$n.' saved exchanges').' in our reporting sample. '.$people.' residents took part in those conversations.';
        $stories[]=['section'=>$key,'title'=>$rule['title'],'subject'=>$rule['subject'],'summary'=>$summary,'conversations'=>$n,'residents'=>$people,'quotes'=>$quotes,'links'=>$links];
    }
    return ['version'=>1,'date'=>$day['date'],'as_of'=>gmdate('Y-m-d\TH:i:s\Z',$now),'counts'=>$collected['counts'],'covered'=>count($collected['conversations']),'stories'=>$stories,'sources'=>array_values($sources)];
}
function gazetteDirectory(string $database):string
{
    $database=databasePath($database);$directory=$database.'.gazette';
    if(is_link($directory))throw new \RuntimeException('Gazette storage must not be a symlink.');
    if(file_exists($directory)&&(!is_dir($directory)||(PHP_OS_FAMILY!=='Windows'&&(fileperms($directory)&0077)!==0)))throw new \RuntimeException('Gazette storage must be a private directory.');
    return $directory;
}
/** Validate a frozen edition and recheck current public authorization/quotes. */
function readGazette(PDO $db,string $database,array $day):?array
{
    $file=gazetteDirectory($database).'/'.$day['date'].'.json';
    if(is_link($file))throw new \RuntimeException('Invalid edition file.');
    if(!file_exists($file))return null;
    if(!is_file($file)||filesize($file)>GAZETTE_FILE_LIMIT||(PHP_OS_FAMILY!=='Windows'&&(fileperms($file)&0077)!==0))throw new \RuntimeException('Invalid edition file.');
    $wrapper=json_decode((string)file_get_contents($file),true,20,JSON_THROW_ON_ERROR);$edition=$wrapper['edition']??null;
    if(!is_array($edition)||($edition['version']??null)!==1||($edition['date']??null)!==$day['date']||$day['date']>=$day['today']
        ||!is_string($wrapper['sha256']??null)||!hash_equals($wrapper['sha256'],hash('sha256',json_encode($edition,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)))
        ||!is_array($edition['sources']??null)||count($edition['sources'])>30||!is_array($edition['stories']??null)||count($edition['stories'])>5)throw new \RuntimeException('Invalid edition content.');
    if(!is_array($edition['counts']??null)||!is_int($edition['covered']??null)||$edition['covered']<0||$edition['covered']>GAZETTE_SOURCE_LIMIT
        ||!is_string($edition['as_of']??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/',$edition['as_of']))throw new \RuntimeException('Invalid edition metadata.');
    foreach(['conversations','residents','notices'] as $field)if(!is_int($edition['counts'][$field]??null)||$edition['counts'][$field]<0)throw new \RuntimeException('Invalid edition counts.');
    foreach($edition['sources'] as $source)if(!is_string($source['id']??null)||!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$source['id'])||!is_string($source['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$source['sha256']))throw new \RuntimeException('Invalid edition source.');
    $ids=array_column($edition['sources'],'id');if(count($ids)!==count(array_unique($ids)))throw new \RuntimeException('Invalid edition sources.');
    $db->beginTransaction();
    try{
        $records=[];
        if($ids){
            ['prefix'=>$prefix,'sql'=>$sql,'source'=>$provider]=gazetteSourceSetup($db);$marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$db->prepare($prefix."SELECT c.id,c.created_at,$provider AS provider $sql AND c.id IN ($marks) AND c.created_at>=? AND c.created_at<?");$q->execute([...$ids,$day['start'],$day['end']]);
            $messages=conversationMessageBatch($db,$ids);
            foreach($q->fetchAll() as $c){$c['messages']=$messages[$c['id']]??[];$records[$c['id']]=$c;}
        }
        foreach($edition['sources'] as $source)if(!isset($records[$source['id']])||gazetteSourceHash($records[$source['id']])!==$source['sha256'])throw new \RuntimeException('An edition source is no longer available.');
        foreach($edition['stories'] as $story){
            if(!isset(gazetteSections()[$story['section']])||count($story['quotes'])>3||count($story['links'])>6)throw new \RuntimeException('Invalid edition story.');
            foreach(['title'=>120,'subject'=>120,'summary'=>500] as $field=>$limit)if(!is_string($story[$field]??null)||preg_match('/\A[^\p{C}]{1,'.$limit.'}\z/u',$story[$field])!==1)throw new \RuntimeException('Invalid edition copy.');
            foreach($story['links'] as $link)if(!isset($records[$link['id']]))throw new \RuntimeException('Invalid story source.');
            foreach($story['quotes'] as $quote){
                if(!is_int($quote['sequence']??null)||$quote['sequence']<0||$quote['sequence']>=64||!gazetteSafeText($quote['name']??null)||preg_match_all('/./us',$quote['name'])>64)throw new \RuntimeException('Invalid quotation metadata.');
                $c=$records[$quote['conversation_id']]??null;$m=$c['messages'][$quote['sequence']]??null;
                if(!$m||$m['resident_id']!==$quote['resident_id']||$m['text']!==$quote['text']||$c['created_at']!==$quote['at']||$c['provider']!==$quote['provider'])throw new \RuntimeException('Invalid saved quotation.');
            }
        }
        $db->commit();return $edition;
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
/** Explicit CLI operation only. Database remains query-only; no model or scheduler. */
function freezeGazette(PDO $db,string $database,array $day,int $now):array
{
    if(PHP_SAPI!=='cli'||$day['date']>=$day['today'])throw new \RuntimeException('Only a finished Eastern day can be published.');
    if($existing=readGazette($db,$database,$day))return ['date'=>$day['date'],'existing'=>true];
    $edition=composeGazette(collectGazette($db,$day,$now),$day,$now);$directory=gazetteDirectory($database);
    $encoded=json_encode(['edition'=>$edition,'sha256'=>hash('sha256',json_encode($edition,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($encoded)>GAZETTE_FILE_LIMIT)throw new \RuntimeException('Edition exceeds its bound.');
    if(!is_dir($directory)&&!mkdir($directory,0700)&&!is_dir($directory))throw new \RuntimeException('Cannot create private edition storage.');
    $temporary=tempnam($directory,'.edition-');if($temporary===false)throw new \RuntimeException('Cannot stage edition.');
    try{
        if(file_put_contents($temporary,$encoded)!==strlen($encoded))throw new \RuntimeException('Incomplete edition write.');
        $file=$directory.'/'.$day['date'].'.json';
        // Hard-link publication is atomic and refuses an existing destination.
        // Readers never see partial JSON; concurrent publishers cannot overwrite.
        if(!@link($temporary,$file)){if($existing=readGazette($db,$database,$day))return ['date'=>$day['date'],'existing'=>true];throw new \RuntimeException('Edition publication refused.');}
        return ['date'=>$day['date'],'existing'=>false];
    }finally{if(is_file($temporary))unlink($temporary);}
}
