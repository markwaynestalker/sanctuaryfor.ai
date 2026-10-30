<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function archiveParameters(array $input):array
{
    if(array_diff(array_keys($input),['q','resident','conversation','source','from','until','order','size','page']))throw new Problem(422,'Unknown archive filter.');
    foreach($input as $value)if(!is_string($value))throw new Problem(422,'Use one text value for each archive filter.');
    $q=$input['q']??'';
    if(strlen($q)>512||!preg_match('//u',$q)||preg_match_all('/./us',$q)>128||preg_match('/[\x00-\x1F\x7F]/u',$q))throw new Problem(422,'Search with up to 128 ordinary text characters.');
    $q=trim($q);
    $resident=$input['resident']??'';
    if($resident!==''&&!preg_match('/\A[a-f0-9]{32}\z/',$resident))throw new Problem(422,'Choose a valid resident.');
    $conversation=$input['conversation']??'';
    if($conversation!==''&&!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$conversation))throw new Problem(422,'Choose a valid conversation.');
    $source=$input['source']??'all';$order=$input['order']??'newest';$size=$input['size']??'25';$page=$input['page']??'1';
    if(!in_array($source,['all','openai','scripted'],true)||!in_array($order,['newest','oldest'],true)||!in_array($size,['10','25','50','100'],true))throw new Problem(422,'Choose an available source, ordering and page size.');
    if(!preg_match('/\A[1-9][0-9]*\z/',$page)||filter_var($page,FILTER_VALIDATE_INT)===false||(int)$page>intdiv(PHP_INT_MAX,100))throw new Problem(422,'Choose a valid page number.');
    $dates=[];
    foreach(['from','until'] as $field){
        $value=$input[$field]??'';
        if($value!==''){
            if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$value)||substr($value,0,4)==='0000')throw new Problem(422,'Use a valid YYYY-MM-DD UTC date.');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
            if(!$date||$date->format('Y-m-d')!==$value)throw new Problem(422,'Use a valid YYYY-MM-DD UTC date.');
        }
        $dates[$field]=$value;
    }
    if($dates['from']!==''&&$dates['until']!==''&&$dates['from']>$dates['until'])throw new Problem(422,'The start date must not follow the end date.');
    return ['q'=>$q,'resident'=>$resident,'conversation'=>$conversation,'source'=>$source,'from'=>$dates['from'],'until'=>$dates['until'],'order'=>$order,'size'=>(int)$size,'page'=>(int)$page];
}

/** Shared source authorization; callers add bound filters inside a read snapshot. */
function conversationPublicSource(PDO $db):array
{
    $messageTable=messageRelation($db);
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    $source=$version>=3?"CASE WHEN ai.conversation_id IS NOT NULL THEN 'openai' ELSE 'scripted' END":"'scripted'";
    $joins=$version>=3?' LEFT JOIN ai_conversations ai ON ai.conversation_id=c.id':'';
    $topic=$version===6?'COALESCE(d.topic,c.topic)':'c.topic';
    if($version===6)$joins.=' LEFT JOIN director_runs d ON d.conversation_id=c.id AND d.run_id=c.run_id';
    $sql=" FROM conversations c
        JOIN simulation_runs run ON run.id=c.run_id AND run.status='completed'
        JOIN $messageTable a ON a.conversation_id=c.id AND a.sequence=0
        JOIN $messageTable b ON b.conversation_id=c.id AND b.sequence=1 AND b.resident_id<>a.resident_id
        JOIN conversation_participants pa ON pa.conversation_id=c.id AND pa.resident_id=a.resident_id
        JOIN conversation_participants pb ON pb.conversation_id=c.id AND pb.resident_id=b.resident_id
        JOIN residents ra ON ra.id=a.resident_id JOIN residents rb ON rb.id=b.resident_id $joins
        WHERE c.visibility='public' AND length(a.body) BETWEEN 1 AND 160 AND length(b.body) BETWEEN 1 AND 160
        AND EXISTS(SELECT 1 FROM archive_events e WHERE e.run_id=c.run_id
            AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
            AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id))";
    // Materialize public event authorization once per query. SQLite can build a
    // transient run-id lookup instead of rescanning the full event log per row.
    $prefix="WITH archive_events AS MATERIALIZED (SELECT run_id,actor_id,target_id,dedupe_key FROM events WHERE visibility='public' AND kind IN ('conversation','quiet_time') AND target_id IS NOT NULL) ";
    return compact('prefix','sql','source','topic','messageTable');
}
/** Bounded public projection only. Never call a provider, session, writer or migration. */
function conversationArchive(PDO $db,array $input):array
{
    $filters=archiveParameters($input);
    ['prefix'=>$prefix,'sql'=>$sql,'source'=>$source,'topic'=>$topic,'messageTable'=>$messageTable]=conversationPublicSource($db);
    $values=[];
    if($filters['conversation']!==''){$sql.=' AND c.id=:conversation';$values[':conversation']=$filters['conversation'];}
    if($filters['resident']!==''){$sql.=' AND (a.resident_id=:resident OR b.resident_id=:resident)';$values[':resident']=$filters['resident'];}
    if($filters['source']!=='all'){$sql.=" AND ($source)=:source";$values[':source']=$filters['source'];}
    if($filters['from']!==''){$sql.=' AND c.created_at>=:from';$values[':from']=$filters['from'].'T00:00:00';}
    // Stored UTC timestamps sort lexically; this exclusive bound includes the whole
    // final day (also fractional seconds), without overflowing year 9999.
    if($filters['until']!==''){$sql.=' AND c.created_at<:until';$values[':until']=$filters['until'].'T24:00:00';}
    if($filters['q']!==''){
        // A quoted literal PCRE supplies Unicode caseless substring matching,
        // unlike SQLite's ASCII-only LIKE/lower, with no optional mbstring/ICU.
        $pattern='~'.preg_quote($filters['q'],'~').'~iu';
        $db->sqliteCreateFunction('sparks_archive_match',static function(...$fields)use($pattern):int{
            foreach($fields as $text)if(preg_match($pattern,(string)$text)===1)return 1;return 0;
        },5,PDO::SQLITE_DETERMINISTIC);
        $sql.=" AND (sparks_archive_match(ra.name,rb.name,a.body,b.body,$topic)=1 OR EXISTS(SELECT 1 FROM $messageTable searched WHERE searched.conversation_id=c.id AND sparks_archive_match('','',searched.body,'','')=1))";
    }
    $bind=static function(\PDOStatement $query)use($values):void{foreach($values as $key=>$value)$query->bindValue($key,$value,PDO::PARAM_STR);};
    $db->beginTransaction();
    try{
        $q=$db->prepare($prefix.'SELECT COUNT(*)'.$sql);$bind($q);$q->execute();$total=(int)$q->fetchColumn();
        $pages=max(1,intdiv(max(0,$total-1),$filters['size'])+1);$page=min($filters['page'],$pages);$offset=($page-1)*$filters['size'];
        $direction=$filters['order']==='oldest'?'ASC':'DESC';
        $q=$db->prepare($prefix."SELECT c.id,c.created_at,$source AS provider,substr($topic,1,160) AS topic,
            a.resident_id AS first_id,b.resident_id AS second_id,substr(ra.name,1,64) AS first_name,substr(rb.name,1,64) AS second_name,
            a.body AS first_text,b.body AS second_text".$sql." ORDER BY c.created_at $direction,c.id $direction LIMIT :limit OFFSET :offset");
        $bind($q);$q->bindValue(':limit',$filters['size'],PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();$conversations=[];$residents=[];
        foreach($q->fetchAll() as $row){
            $messages=[];
            foreach(['first','second'] as $which){$id=$row[$which.'_id'];$name=$row[$which.'_name'];$residents[$id]=['id'=>$id,'name'=>$name];$messages[]=['resident_id'=>$id,'name'=>$name,'text'=>$row[$which.'_text']];}
            $conversations[]=['id'=>$row['id'],'created_at'=>$row['created_at'],'provider'=>$row['provider'],'topic'=>$row['topic'],'messages'=>$messages];
        }
        $transcripts=conversationMessageBatch($db,array_column($conversations,'id'));
        foreach($conversations as &$conversation){$conversation['messages']=array_map(static function($m){unset($m['sequence']);return $m;},$transcripts[$conversation['id']]??[]);}unset($conversation);
        if($filters['resident']!==''&&!isset($residents[$filters['resident']])){
            $q=$db->prepare('SELECT id,substr(name,1,64) AS name FROM residents WHERE id=?');$q->execute([$filters['resident']]);$selected=$q->fetch();
            if($selected)$residents[$selected['id']]=$selected;
        }
        $db->commit();
        return ['conversations'=>$conversations,'residents'=>array_values($residents),'filters'=>array_replace($filters,['page'=>$page]),
            'pagination'=>['page'=>$page,'page_size'=>$filters['size'],'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'until'=>$total?$offset+count($conversations):0]];
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
