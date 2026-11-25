<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/narrative_story.php';
require_once __DIR__.'/conversation_lineage.php';

/** Read-only, bounded pages; only complete, publicly authorized exchanges count. */
function narrativeArchive(PDO $db,string $story='',int $storyPage=1,int $listPage=1):array
{
    if($story!==''&&!preg_match('/\A[a-f0-9]{32}\z/',$story))throw new Problem(422,'Choose a valid story.');
    if($storyPage<1||$storyPage>1000000||$listPage<1||$listPage>1000000)throw new Problem(422,'Choose a valid story page.');
    if(!narrativeStoryInstalled($db))return ['stories'=>[],'story'=>null,'list_page'=>1,'list_pages'=>1];
    $src=conversationPublicSource($db);$visible=str_replace(" WHERE c.visibility=", " JOIN narrative_story_exchanges n ON n.conversation_id=c.id WHERE c.visibility=",$src['sql'])." AND (SELECT COUNT(*) FROM conversation_participants cp WHERE cp.conversation_id=c.id)=2
        AND EXISTS(SELECT 1 FROM director_runs dr WHERE dr.run_id=c.run_id AND dr.conversation_id=c.id)
        AND EXISTS(SELECT 1 FROM events e WHERE e.run_id=c.run_id AND e.visibility='public' AND e.kind='conversation'
          AND ((e.actor_id=a.resident_id AND e.target_id=b.resident_id) OR (e.actor_id=b.resident_id AND e.target_id=a.resident_id))
          AND (substr(e.dedupe_key,1,length('social:'||e.run_id||':'))<>'social:'||e.run_id||':' OR e.dedupe_key='social:'||e.run_id||':'||c.id))
        AND EXISTS(SELECT 1 FROM simulation_opportunities o WHERE o.run_id=c.run_id AND o.outcome='conversation')";
    $join='';
    $storyKey='n.story_id';
    $db->beginTransaction();try{
        $total=(int)$db->query($src['prefix']."SELECT COUNT(DISTINCT $storyKey)".$visible.$join)->fetchColumn();
        $pages=max(1,(int)ceil($total/10));$listPage=min($listPage,$pages);
        $q=$db->prepare($src['prefix']."SELECT $storyKey AS story_key,COUNT(*) AS exchanges,MAX(c.created_at) AS latest".$visible.$join." GROUP BY $storyKey ORDER BY latest DESC,story_key DESC LIMIT 10 OFFSET ?");$q->bindValue(1,($listPage-1)*10,PDO::PARAM_INT);$q->execute();$stories=$q->fetchAll();foreach($stories as &$entry){$entry['id']=$entry['story_key'];unset($entry['story_key']);$entry['exchanges']=(int)$entry['exchanges'];}unset($entry);
        if($stories){
            $keys=array_column($stories,'id');$marks=implode(',',array_fill(0,count($keys),'?'));
            $q=$db->prepare($src['prefix']."SELECT * FROM (SELECT $storyKey AS story_key,substr(".$src['topic'].",1,160) AS topic,substr(ra.name,1,64) AS first_name,substr(rb.name,1,64) AS second_name,ROW_NUMBER() OVER(PARTITION BY $storyKey ORDER BY c.created_at DESC,c.id DESC) AS position".$visible." AND $storyKey IN ($marks)) WHERE position=1");$q->execute($keys);$previews=array_column($q->fetchAll(),null,'story_key');
            foreach($stories as &$entry){$preview=$previews[$entry['id']]??null;$entry['topic']=$preview['topic']??'';$entry['names']=$preview?[$preview['first_name'],$preview['second_name']]:[];}unset($entry);
        }
        $selected=null;if($story!==''){
            $predicate=" AND n.story_id=?";
            $q=$db->prepare($src['prefix'].'SELECT COUNT(*)'.$visible.$predicate);$q->execute([$story]);$count=(int)$q->fetchColumn();
            if($count){$storyPages=max(1,(int)ceil($count/10));$storyPage=min($storyPage,$storyPages);
                $q=$db->prepare($src['prefix']."SELECT c.id,c.created_at,".$src['source']." AS provider,substr(".$src['topic'].",1,160) AS topic,n.sequence AS sequence".$visible.$predicate.' ORDER BY sequence ASC LIMIT 10 OFFSET ?');
                $q->bindValue(1,$story);$q->bindValue(2,($storyPage-1)*10,PDO::PARAM_INT);$q->execute();$exchanges=$q->fetchAll();$messages=conversationMessageBatch($db,array_column($exchanges,'id'));
                foreach($exchanges as $index=>&$exchange){$exchange['position']=($storyPage-1)*10+$index+1;unset($exchange['sequence']);$exchange['messages']=$messages[$exchange['id']]??[];foreach($exchange['messages'] as &$line)unset($line['sequence']);unset($line);}unset($exchange);
                $selected=['id'=>$story,'exchanges'=>$exchanges,'total'=>$count,'page'=>$storyPage,'pages'=>$storyPages];
            }
        }
        $db->commit();return ['stories'=>$stories,'story'=>$selected,'list_page'=>$listPage,'list_pages'=>$pages];
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
