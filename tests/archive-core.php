<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/commons.php';require __DIR__.'/../app/conversation_archive.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
final class ArchiveCountingPdo extends PDO{
 public int $reads=0;
 public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);}
 public function prepare(string $query,array $options=[]):PDOStatement|false{$this->reads++;return parent::prepare($query,$options);}
}
try{
 $dir=$argv[1];$manifest=[];
 foreach([2,3,4,6] as $version){
  $path=$dir.'/v'.$version.'.sqlite';$db=LittleSparks\connect($path);$ids=$db->query("SELECT id FROM residents WHERE name NOT IN ('No Home') ORDER BY created_at,id")->fetchAll(PDO::FETCH_COLUMN);
  // Use actual speaker IDs rather than relying on seed insertion/random ID order.
  $ids=$db->query("SELECT resident_id FROM messages WHERE conversation_id='first' ORDER BY sequence")->fetchAll(PDO::FETCH_COLUMN);
  $others=$db->query("SELECT resident_id FROM messages WHERE conversation_id='other-pair' ORDER BY sequence")->fetchAll(PDO::FETCH_COLUMN);$all=[...$ids,...$others];
  $db->prepare('UPDATE residents SET name=? WHERE id=?')->execute(['Blakie Élan',$others[0]]);
  $db->exec('BEGIN IMMEDIATE');
  $makeRun=function(string $run,string $status='completed')use($db,$version):void{$db->prepare('INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,?,?,?,?)')->execute([$run,substr(hash('sha256',$run),0,10),$status,$version>=3?'openai':'scripted','2020-01-01T12:00:00Z','2020-01-01T12:00:00Z']);};
  $makeConversation=function(string $id,string $run,array $pair,string $first,string $second,?string $eventKey=null,string $kind='conversation')use($db):void{
   $db->prepare("INSERT INTO conversations VALUES (?,?,'garden','public','2020-01-01T12:00:00Z')")->execute([$id,$run]);
   foreach(array_unique($pair) as $resident)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$resident]);
   foreach($pair as $sequence=>$resident)if($id!=='bad-partial'||$sequence===0)$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$id,$sequence,$resident,$sequence===0?$first:$second]);
   if($eventKey!==null)LittleSparks\event($db,$pair[0],$pair[1],$kind,'simulation','2020-01-01T12:00:00Z',$eventKey,$run);
  };
  for($i=0;$i<200;$i++){
   $id='archive-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$pair=$i%2?$others:array_reverse($ids);
   $makeConversation($id,'shared-run',$pair,$i===0?str_repeat('é',159).'🌳':'HISTORICAL_'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'Étoile, lanterne… “hello” 🌳 100%_literal','social:shared-run:'.$id);
  }
  if($version>=3)$db->exec("INSERT INTO ai_conversations VALUES ('archive-000','fixture-attempt')");
  foreach(['failed'=>'failed','no-event'=>'completed','wrong-key'=>'completed','visit'=>'completed','wrong-pair'=>'completed','duplicate-speaker'=>'completed','partial'=>'completed'] as $suffix=>$status)$makeRun('archive-'.$suffix,$status);
  $makeConversation('bad-failed','archive-failed',$ids,'PRIVATE_FAILED_CANARY','Never completed','social:archive-failed:bad-failed');
  $makeConversation('bad-no-event','archive-no-event',$ids,'PRIVATE_NO_EVENT_CANARY','No receipt');
  $makeConversation('bad-key','archive-wrong-key',$ids,'PRIVATE_WRONG_KEY_CANARY','Wrong conversation','social:archive-wrong-key:another');
  $makeConversation('bad-visit','archive-visit',$ids,'PRIVATE_VISIT_CANARY','Not a conversation','visit-only','visit_friend');
  $makeConversation('bad-pair','archive-wrong-pair',$others,'PRIVATE_WRONG_PAIR_CANARY','Wrong participants');LittleSparks\event($db,$ids[0],$ids[1],'conversation','simulation','2020-01-01T12:00:00Z','pair-mismatch','archive-wrong-pair');
  $makeConversation('bad-duplicate','archive-duplicate-speaker',[$ids[0],$ids[0]],'PRIVATE_DUPLICATE_CANARY','Same speaker','duplicate-speaker');
  $makeConversation('bad-partial','archive-partial',$ids,'PRIVATE_PARTIAL_CANARY','Missing reply','partial');
  if($version===6){
   $db->exec("INSERT INTO director_registry VALUES ('offline-fingerprint','agent_offline','gpt-6-luna','PRIVATE_PROMPT_CANARY','{}','2020-01-01T00:00:00Z');
    INSERT INTO director_sessions VALUES ('PRIVATE_INTERNAL_SESSION','offline-fingerprint','offline-scope','agent_offline','agents','PRIVATE_PROVIDER_SESSION','ready',1,'PRIVATE_TURN','2020-01-01T00:00:00Z','2020-01-01T00:00:00Z');
    INSERT INTO director_runs VALUES ('shared-run','PRIVATE_INTERNAL_SESSION','offline-fingerprint','archive-000','Flamingo party decorating','2020-01-01T12:00:00Z')");
  }
  $db->exec('COMMIT');$before=hash_file('sha256',$path);$db->exec('PRAGMA query_only=ON');
  $initial=LittleSparks\conversationArchive($db,[]);check($initial['pagination']===['page'=>1,'page_size'=>25,'total'=>205,'pages'=>9,'from'=>1,'until'=>25],'Default bounded historical projection');
  $whole=[];for($page=1;$page<=3;$page++){$r=LittleSparks\conversationArchive($db,['size'=>'100','page'=>(string)$page]);$whole=[...$whole,...$r['conversations']];}
  check(count(array_unique(array_column($whole,'id')))===205,'Every authorized exchange discoverable with stable timestamp/ID ties');
  $keys=array_map(fn($c)=>[$c['created_at'],$c['id']],$whole);$sorted=$keys;rsort($sorted);check($keys===$sorted,'Newest timestamp/ID ordering');
  $oldest=LittleSparks\conversationArchive($db,['order'=>'oldest','size'=>'100']);check(array_column($oldest['conversations'],'id')===array_slice(array_reverse(array_column($whole,'id')),0,100),'Oldest reverses both tie breakers');
  $unicode=LittleSparks\conversationArchive($db,['q'=>'éTOILE','size'=>'100']);check($unicode['pagination']['total']===200,'Unicode case-insensitive full dialogue search');
  check(LittleSparks\conversationArchive($db,['q'=>'bLAKIE éLAN'])['pagination']['total']===101,'Case-insensitive Unicode name search');
  check(LittleSparks\conversationArchive($db,['q'=>'hIsToRiCaL_199'])['pagination']['total']===1,'Search reaches beyond newest-per-pair commons');
  foreach(['%','100%_literal','“hello”','🌳'] as $needle)check(LittleSparks\conversationArchive($db,['q'=>$needle])['pagination']['total']===200,'Literal punctuation/emoji search '.$needle);
  foreach(["' OR 1=1 --",'.*','NO_MATCH_ANYWHERE'] as $needle)check(LittleSparks\conversationArchive($db,['q'=>$needle])['pagination']['total']===0,'No SQL/regex wildcard injection');
  $resident=LittleSparks\conversationArchive($db,['resident'=>$ids[1],'size'=>'100']);check($resident['pagination']['total']===104,'Either speaker resident filter');
  check(LittleSparks\conversationArchive($db,['source'=>'openai'])['pagination']['total']===($version>=3?2:0),'Source comes from each saved conversation, not run/model');
  check(LittleSparks\conversationArchive($db,['source'=>'scripted'])['pagination']['total']===($version>=3?203:205),'Scripted source filter');
  check(LittleSparks\conversationArchive($db,['from'=>'2020-01-01','until'=>'2020-01-01'])['pagination']['total']===200,'Inclusive UTC day boundaries');
  check(LittleSparks\conversationArchive($db,['from'=>'2020-01-02'])['pagination']['total']===5,'Date lower bound excludes history');
  check(LittleSparks\conversationArchive($db,['q'=>'Flamingo party'])['pagination']['total']===($version===6?1:0),'Only available public Director topic is searchable');
  $long=array_values(array_filter($whole,fn($c)=>$c['id']==='archive-000'))[0];check($long['messages'][0]['text']===str_repeat('é',159).'🌳','All 160 Unicode characters remain byte-exact');
  check(!str_contains(json_encode($whole,JSON_THROW_ON_ERROR),'PRIVATE_'),'No private/failed/orphan/misbound records or internal Director data');
  check(count(LittleSparks\commons($db,$all)['conversations'])===2,'Commons remains latest-per-pair');
  foreach([['size'=>'101'],['size'=>'0'],['size'=>'0010'],['page'=>'0'],['page'=>'-1'],['page'=>'999999999999999999999999'],['q'=>str_repeat('é',129)],['q'=>str_repeat(' ',129)],['q'=>"\0"],['q'=>"bad\0text"],['q'=>"\xff"],['q'=>[]],['resident'=>'javascript:bad'],['source'=>'model'],['order'=>'DESC;DROP TABLE residents'],['from'=>'2026-02-29'],['from'=>'0000-01-01'],['from'=>'2026-10-02','until'=>'2026-10-01'],['sort'=>'body']] as $invalid){
   try{LittleSparks\conversationArchive($db,$invalid);throw new LogicException('Invalid filter accepted');}catch(LittleSparks\Problem $e){check($e->status===422,'Invalid filter fails safely');}
  }
  $counting=new ArchiveCountingPdo('sqlite:'.$path,null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$counting->exec('PRAGMA query_only=ON');LittleSparks\conversationArchive($counting,['size'=>'100']);check($counting->reads===6,'Six bounded reads including capability detection and transcript batch, never one per exchange/speaker');$counting=null;
  check(hash_file('sha256',$path)===$before,'Every archive/commons read preserves file bytes and schema');
  $manifest[]=['version'=>$version,'database'=>$path,'sha256'=>$before,'ids'=>$all];$db=null;
 }
 echo json_encode($manifest,JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
