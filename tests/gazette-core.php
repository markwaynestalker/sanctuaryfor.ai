<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette.php';require __DIR__.'/../app/conversation_messages_schema.php';
function gazetteCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function addGazetteFixture(PDO $db,string $id,array $pair,array $lines,string $at,string $mode='good',bool $extended=false,string $topic='welcome'):void
{
    $run='gazette-run-'.$id;$db->prepare('INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,?,?,?,?)')->execute([$run,substr(hash('sha256',$id),0,10),$mode==='failed'?'failed':'completed','scripted',$at,$at]);
    $db->prepare('INSERT INTO conversations VALUES (?,?,?,?,?)')->execute([$id,$run,$topic,$mode==='private'?'private':'public',$at]);
    foreach($pair as $r)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$r]);
    if($extended)$db->prepare('INSERT INTO extended_conversations VALUES (?,?)')->execute([$id,count($lines)]);
    $table=$extended?'extended_messages':'messages';
    foreach($lines as $i=>$line)$db->prepare("INSERT INTO $table VALUES (?,?,?,?)")->execute([$id,$i,$pair[$i%2],$line]);
    $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,?,'conversation','simulation',?,?,?)")
        ->execute([$pair[0],$pair[1],$run,$mode==='private_event'?'private':'public',$at,'social:'.$run.':'.($mode==='wrong_key'?'some-other-conversation':$id)]);
}
try{
    $dir=$argv[1];$manifest=[];$now=strtotime('2026-11-03T12:00:00Z');
    gazetteCheck((strtotime(gazetteDayTest('2026-03-08',$now)['end'])-strtotime(gazetteDayTest('2026-03-08',$now)['start']))===23*3600,'Spring edition is 23 hours');
    gazetteCheck((strtotime(gazetteDayTest('2026-11-01',$now)['end'])-strtotime(gazetteDayTest('2026-11-01',$now)['start']))===25*3600,'Fall edition is 25 hours');
    foreach(['2026-02-29','2026-13-01','2026-11-04','1969-12-31','../secret',[], '2026-1-01'] as $bad){try{LittleSparks\gazetteDay($bad,$now);throw new LogicException('Invalid date accepted');}catch(LittleSparks\Problem){}}
    foreach([2,6] as $version){
        $path=$dir.'/v'.$version.'.sqlite';$db=LittleSparks\connect($path);$ids=$db->query('SELECT id FROM residents WHERE name<>\'No Home\' ORDER BY created_at,id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN);$pair=[$ids[0],$ids[1]];
        if($version===6){$db->beginTransaction();LittleSparks\migrateConversationMessages($db);$db->commit();}
        $db->beginTransaction();
        foreach(['spring-before'=>'2026-03-08T04:59:59Z','spring-first'=>'2026-03-08T05:00:00Z','spring-last'=>'2026-03-09T03:59:59Z','spring-after'=>'2026-03-09T04:00:00Z','fall-before'=>'2026-11-01T03:59:59Z','fall-first'=>'2026-11-01T04:00:00Z','fall-last'=>'2026-11-02T04:59:59Z','fall-after'=>'2026-11-02T05:00:00Z'] as $id=>$at)addGazetteFixture($db,$id,$pair,['A garden question.','Perhaps a tree would help.'],$at);
        foreach(['good','private','failed','private_event','wrong_key'] as $mode)addGazetteFixture($db,'integrity-'.$mode,$pair,[$mode==='good'?'We could build a lost-and-found.':'PRIVATE_EDITORIAL_CANARY','A village idea, not an opened building.'],'2026-10-29T12:00:00Z',$mode);
        addGazetteFixture($db,'sensitive-public',$pair,['Bearer PRIVATECREDENTIALCANARY','A separate thought.'],'2026-10-29T12:01:00Z');
        addGazetteFixture($db,'html-public',$pair,['A garden <script> sneaked into this old quote.','Flowers are the subject.'],'2026-10-29T12:02:00Z');
        addGazetteFixture($db,'topic-only',$pair,['The gate is blue.','The latch is plain.'],'2026-10-29T12:03:00Z','good',false,'garden');
        foreach([0,1,2,3] as $i)addGazetteFixture($db,'food-'.$i,[$ids[$i%4],$ids[($i+1)%4]],['Could we share a recipe for pie?','Coffee and cake sound like topics worth discussing.'],'2026-10-29T13:0'.$i.':00Z');
        foreach([0,1,2] as $i)addGazetteFixture($db,'play-'.$i,[$ids[$i%4],$ids[($i+1)%4]],['An imaginary dragon could guard the game.','We can propose a rule without starting a real gathering.'],'2026-10-29T14:0'.$i.':00Z');
        for($i=0;$i<270;$i++)addGazetteFixture($db,'large-day-'.$i,[$ids[$i%4],$ids[($i+1)%4]],['We could discuss a village game.','Rules are an interesting subject.'],'2026-10-27T12:00:00Z');
        if($version===6){$lines=['A different opening.','Another response.','Lasagna on turn three, still a proposal.'];for($i=3;$i<16;$i++)$lines[]='Original saved turn '.($i+1).': coffee is the subject.';addGazetteFixture($db,'extended-sixteen',$pair,$lines,'2026-10-29T15:00:00Z','good',true);}
        $db->commit();$db->exec('PRAGMA query_only=ON');$hash=hash_file('sha256',$path);
        foreach(['2026-03-08','2026-11-01'] as $date){$collected=LittleSparks\collectGazette($db,LittleSparks\gazetteDay($date,$now),$now);gazetteCheck($collected['counts']['conversations']===2,'Exact Eastern midnight inclusion on DST day');}
        $day=LittleSparks\gazetteDay('2026-10-29',$now);$collected=LittleSparks\collectGazette($db,$day,$now);$edition=LittleSparks\composeGazette($collected,$day,$now);$json=json_encode($edition);
        gazetteCheck(!str_contains($json,'PRIVATE_EDITORIAL_CANARY')&&!str_contains($json,'PRIVATECREDENTIALCANARY'),'Private/failed/misbound/sensitive sources excluded');
        gazetteCheck($edition['counts']['conversations']===($version===6?11:10)&&$edition['counts']['residents']===4,'Day counts include only eligible public exchanges and actual speakers');
        $voices=[];$quoted=[];foreach($edition['stories'] as $story){foreach($story['quotes'] as $quote){$voices[$quote['resident_id']]=($voices[$quote['resident_id']]??0)+1;$quoted[]=$quote;}
            if(in_array('topic-only',array_column($story['links'],'id'),true))gazetteCheck($story['section']==='other','Topic metadata cannot classify a transcript');}
        gazetteCheck(max($voices)<=2&&count($voices)===4,'Editorial quotes spread across residents, cap two');
        foreach($quoted as $quote){$messages=LittleSparks\conversationMessageBatch($db,[$quote['conversation_id']])[$quote['conversation_id']];gazetteCheck($messages[$quote['sequence']]['text']===$quote['text']&&$messages[$quote['sequence']]['resident_id']===$quote['resident_id'],'Exact quote/sequence/attribution');}
        if($version===6){$extended=array_values(array_filter($collected['conversations'],fn($c)=>$c['id']==='extended-sixteen'))[0];gazetteCheck(count($extended['messages'])===16&&$extended['messages'][15]['text']===$lines[15],'Complete extended transcript used');}
        gazetteCheck(LittleSparks\composeGazette($collected,$day,$now)===$edition,'Compilation is deterministic');
        $largeDay=LittleSparks\gazetteDay('2026-10-27',$now);$large=LittleSparks\composeGazette(LittleSparks\collectGazette($db,$largeDay,$now),$largeDay,$now);gazetteCheck($large['counts']['conversations']===270&&$large['covered']===LittleSparks\GAZETTE_SOURCE_LIMIT,'Full-day counts with bounded reporting sample');
        gazetteCheck(LittleSparks\freezeGazette($db,$path,$day,$now)['existing']===false,'Explicit first publication');$file=$path.'.gazette/'.$day['date'].'.json';$frozen=file_get_contents($file);
        gazetteCheck(LittleSparks\freezeGazette($db,$path,$day,$now+10)['existing']===true&&file_get_contents($file)===$frozen,'Repeat publication cannot overwrite edition');
        gazetteCheck(LittleSparks\readGazette($db,$path,$day)===$edition,'Frozen edition round-trips with exact source validation');
        $wrapper=json_decode($frozen,true);$wrapper['edition']['stories'][0]['quotes'][0]['text']='Invented quotation.';$wrapper['sha256']=hash('sha256',json_encode($wrapper['edition'],JSON_UNESCAPED_UNICODE));file_put_contents($file,json_encode($wrapper));
        try{LittleSparks\readGazette($db,$path,$day);throw new LogicException('Forged quote accepted');}catch(RuntimeException){}file_put_contents($file,$frozen);
        gazetteCheck(hash_file('sha256',$path)===$hash,'Publication and reads never modify database bytes');
        $writer=LittleSparks\connect($path);$writer->beginTransaction();$sourceId=$edition['sources'][0]['id'];$writer->prepare("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$sourceId]);$writer->commit();
        try{LittleSparks\readGazette($db,$path,$day);throw new LogicException('Withdrawn source remained public');}catch(RuntimeException){}
        $writer->prepare("UPDATE simulation_runs SET status='completed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$sourceId]);
        $writer->beginTransaction();addGazetteFixture($writer,'late-history',$pair,['A new village discussion imported later.','The published paper should stay fixed.'],'2026-10-29T16:00:00Z');$writer->commit();$writer=null;$hash=hash_file('sha256',$path);
        gazetteCheck(LittleSparks\readGazette($db,$path,$day)===$edition&&file_get_contents($file)===$frozen,'Late source additions do not regenerate or rewrite a published edition');
        $emptyDay=LittleSparks\gazetteDay('2026-10-28',$now);$empty=LittleSparks\composeGazette(LittleSparks\collectGazette($db,$emptyDay,$now),$emptyDay,$now);gazetteCheck($empty['counts']['conversations']===0&&$empty['stories']===[],'Quiet day has no invented stories');
        try{LittleSparks\freezeGazette($db,$path,LittleSparks\gazetteDay(null,$now),$now);throw new LogicException('Today published as final');}catch(RuntimeException){}
        gazetteCheck(hash_file('sha256',$path)===$hash,'Publication and reads never modify database bytes');
        $manifest[]=['database'=>$path,'version'=>$version,'sha256'=>$hash,'edition_sha256'=>hash('sha256',$frozen),'example'=>'integrity-good'];
    }
    echo json_encode($manifest,JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
function gazetteDayTest(string $date,int $now):array{return LittleSparks\gazetteDay($date,$now);}
