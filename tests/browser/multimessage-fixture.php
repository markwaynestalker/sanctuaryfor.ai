<?php
// Synthetic local-only transcript; no credential or provider.
declare(strict_types=1);
require __DIR__.'/../../app/village.php';require __DIR__.'/../../app/activity.php';require __DIR__.'/../../app/conversation_messages_schema.php';
$db=LittleSparks\connect($argv[1]);
if((int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==0)throw new RuntimeException('Empty disposable database required.');
$db->beginTransaction();LittleSparks\migrateConversationMessages($db);$db->commit();$now=time();
foreach(['Synthetic Mira','Synthetic Rowan'] as $i=>$name)LittleSparks\create($db,['name'=>$name,'color'=>'mint','antenna'=>'orb','description'=>'Synthetic review character.','requestId'=>substr(hash('sha256','multi:'.$i),0,32),'website'=>''],'multi-browser-'.$i,'multi-network-'.$i,$now-10+$i,true);
$pair=$db->query('SELECT * FROM residents ORDER BY created_at,id')->fetchAll();$messages=[];
for($i=0;$i<16;$i++)$messages[]=['resident_id'=>$pair[$i%2]['id'],'text'=>'Saved turn '.($i+1).': '.($i%2?'I disagree about the blue gate. Could we try a different design?':'I like the blue gate, though your wooden arch might leave more room.')];
$time=gmdate('Y-m-d\TH:i:s\Z',$now);$id='synthetic-multi-74';$run='synthetic-run-74';
$db->beginTransaction();$db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,'completed','scripted',?,?)")->execute([$run,'2026-10-29',$time,$time]);
$db->prepare("INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,'welcome','public',?)")->execute([$id,$run,$time]);
foreach($pair as $p)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$p['id']]);
$db->prepare('INSERT INTO extended_conversations VALUES (?,16)')->execute([$id]);
foreach($messages as $i=>$m)$db->prepare('INSERT INTO extended_messages VALUES (?,?,?,?)')->execute([$id,$i,$m['resident_id'],$m['text']]);
LittleSparks\event($db,$pair[0]['id'],$pair[1]['id'],'conversation','simulation',$time,'social:'.$run.':'.$id,$run);$db->commit();
echo json_encode(['ids'=>array_column($pair,'id'),'conversation'=>$db->query('SELECT id FROM conversations')->fetchColumn()]);
