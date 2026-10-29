<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/schema.php';require __DIR__.'/../app/ai_schema.php';require __DIR__.'/../app/activity_schema.php';require __DIR__.'/../app/commons.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function journalDigest(PDO $db):string{
    $hash=hash_init('sha256');hash_update($hash,(string)$db->query('PRAGMA user_version')->fetchColumn());
    foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type IN ('table','trigger','index') ORDER BY name")->fetchAll() as $entry){
        hash_update($hash,json_encode($entry,JSON_THROW_ON_ERROR));
        if($entry['sql']&&str_starts_with($entry['sql'],'CREATE TABLE'))foreach($db->query('SELECT * FROM "'.str_replace('"','""',$entry['name']).'" ORDER BY rowid')->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
    }
    return hash_final($hash);
}
final class JournalCountingPdo extends PDO{
    public int $reads=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{
        $this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->reads++;return parent::prepare($query,$options);}
}
try{
    $directory=$argv[1]??'';check(is_dir($directory)&&str_starts_with(basename($directory),'little-sparks-journal-test-'),'Disposable fixtures only');
    if(($argv[2]??'')==='verify'){
        foreach([2,3,4] as $version)check(trim(file_get_contents($directory.'/v'.$version.'.digest'))===journalDigest(LittleSparks\connect($directory.'/v'.$version.'.sqlite')),'HTTP reads preserve V'.$version.' schema and every record');
        echo "PASS HTTP journal reads preserve all fixture data and schemas\n";exit;
    }
    $manifest=[];
    foreach([2,3,4] as $version){
        $path=$directory.'/v'.$version.'.sqlite';$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateSchema($db);$db->exec('COMMIT');
        if($version>=3){$db->exec('PRAGMA foreign_keys=OFF');$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateAiSchema($db);check(!$db->query('PRAGMA foreign_key_check')->fetch(),'V3 fixture FKs');$db->exec('COMMIT');$db->exec('PRAGMA foreign_keys=ON');}
        if($version===4){$db->exec('PRAGMA foreign_keys=OFF');$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateActivitySchema($db);check(!$db->query('PRAGMA foreign_key_check')->fetch(),'V4 fixture FKs');$db->exec('COMMIT');$db->exec('PRAGMA foreign_keys=ON');}
        $ids=[];
        foreach(['Sparky','Skippy Jr','Moss','Orbit'] as $index=>$name){$r=LittleSparks\create($db,['name'=>$name,'color'=>'mint','antenna'=>'orb','personality'=>'curious','requestId'=>str_pad((string)$index,32,'0',STR_PAD_LEFT),'website'=>''],'browser-'.$index,'network-'.$index,time(),true);$ids[]=$r['resident']['id'];}
        $db->prepare('UPDATE residents SET name=? WHERE id=?')->execute(['Sparky <img src=x onerror=alert(1)>',$ids[0]]);
        $time='2026-10-24T12:00:00Z';
        $run=function(string $id)use($db,$time,$version):void{$db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,'completed',?,?,?)")->execute([$id,substr(hash('sha256',$id),0,10),$version>=3?'openai':'scripted',$time,$time]);};
        // A ten-character key is schema-valid on V2/V3; no runner or API calls occur.
        $run('shared-run');$run('older-run');$run('private-event-run');$run('quiet-run');
        $conversation=function(string $id,string $runId,array $pair,string $first,string $second,string $visibility='public',?string $createdAt=null)use($db,$time):void{
            $db->prepare('INSERT INTO conversations(id,run_id,topic,visibility,created_at) VALUES (?,?,?,?,?)')->execute([$id,$runId,'welcome',$visibility,$createdAt??$time]);
            foreach($pair as $sequence=>$resident){$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$id,$resident]);$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$id,$sequence,$resident,$sequence===0?$first:$second]);}
        };
        $olderConversation='old-page-conversation';$conversation($olderConversation,'older-run',[$ids[0],$ids[1]],'OLDER_PAGE_ONLY','Older reply');
        LittleSparks\event($db,$ids[0],$ids[1],'conversation','simulation',$time,'social:older-run:'.$olderConversation,'older-run');
        for($i=0;$i<35;$i++)LittleSparks\event($db,$ids[2],null,'plant_flowers','visitor',$time,'filler-'.$i);
        $conversation('first','shared-run',[$ids[0],$ids[1]],'Hello <script>alert("hello")</script> & "friends".','A little hello makes a big difference.','public','2026-10-24T13:00:00Z');
        $conversation('other-pair','shared-run',[$ids[2],$ids[3]],'OTHER_PAIR_ONLY','A separate garden chat.');
        $conversation('same-pair','shared-run',[$ids[0],$ids[1]],'SAME_PAIR_SECOND_EXCHANGE','The exact legacy conversation.');
        $conversation('hidden','shared-run',[$ids[0],$ids[1]],'PRIVATE_CONVERSATION_CANARY','Never public','private','2026-10-24T14:00:00Z');
        $conversation('hidden-event','private-event-run',[$ids[0],$ids[1]],'PRIVATE_EVENT_CANARY','Not on the journal','public','2026-10-24T15:00:00Z');
        $conversation('quiet','quiet-run',[$ids[0],$ids[1]],'A saved quiet exchange.','Room for quiet, too.');
        if($version>=3){
            $db->prepare("INSERT INTO ai_attempts(id,period,day,month,resident_a,resident_b,model,pricing_version,input_rate,output_rate,reserved_microusd,estimated_microusd,state,created_at) VALUES ('fixture-attempt','fixture-period','2026-10-24','2026-10',?,?,'gpt-6-luna','fixture',125000,500000,4352,200,'applied',?)")->execute([$ids[0],$ids[1],$time]);
            $db->exec("INSERT INTO ai_conversations VALUES ('first','fixture-attempt')");
        }
        $events=[];
        foreach([['first',0,1,'conversation','shared-run'],['other-pair',2,3,'conversation','shared-run'],['same-pair',0,1,'conversation','shared-run'],['hidden',0,1,'conversation','shared-run'],['missing',0,1,'conversation','shared-run'],['quiet',0,1,'quiet_time','quiet-run']] as [$id,$a,$b,$kind,$runId])$events[$id]=LittleSparks\event($db,$ids[$a],$ids[$b],$kind,'simulation',$time,'social:'.$runId.':'.$id,$runId);
        $events['unrelated']=LittleSparks\event($db,$ids[0],$ids[3],'conversation','simulation',$time,'unrelated-participants','shared-run');
        $events['visit']=LittleSparks\event($db,$ids[0],$ids[1],'visit_friend','simulation',$time,'visit-with-shared-run','shared-run');
        $events['solitary']=LittleSparks\event($db,$ids[0],null,'quiet_time','simulation',$time,'solitary-quiet','shared-run');
        $events['null-run']=LittleSparks\event($db,$ids[0],$ids[1],'conversation','simulation',$time,'no-run');
        // A non-legacy key must still select by run AND both residents, never by run alone.
        $events['modern']=LittleSparks\event($db,$ids[2],$ids[3],'conversation','simulation',$time,'activity:fixture:conversation','shared-run');
        $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,'private-event-run','conversation','simulation','private',?,'private-event')")->execute([$ids[0],$ids[1],$time]);
        $noHome=str_repeat('f',32);$db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$noHome,'No Home','mint','orb','shy',$time,'fixture-owner','fixture-request','fixture-hash',hash('sha256','fixture-token')]);
        $before=journalDigest($db);$page=LittleSparks\journal($db);$byId=array_column($page['events'],null,'id');
        check(count($page['events'])===30&&$page['nextBefore']!==null,'Event pagination stays at 30 regardless of joined messages');
        foreach(['first','other-pair','same-pair','quiet','modern'] as $key)check(count($byId[$events[$key]]['conversations'])===1,'One corresponding exchange for '.$key);
        check($byId[$events['first']]['conversations'][0]['id']==='first'&&$byId[$events['same-pair']]['conversations'][0]['id']==='same-pair','Exact conversation selected for multiple same-pair exchanges in one run');
        check($byId[$events['other-pair']]['conversations'][0]['messages'][0]['body']==='OTHER_PAIR_ONLY','No cross-pair dialogue bleed');
        check($byId[$events['modern']]['conversations'][0]['id']==='other-pair','Modern key selects correct pair without single-conversation assumption');
        check($byId[$events['first']]['conversations'][0]['provider']===($version>=3?'openai':'scripted'),'Exact AI provenance');
        check($byId[$events['other-pair']]['conversations'][0]['provider']==='scripted','Other scripted exchange in AI run remains scripted');
        foreach(['hidden','missing','unrelated','visit','solitary','null-run'] as $key)check($byId[$events[$key]]['conversations']===[],'Summary-only fallback for '.$key);
        $json=json_encode($page,JSON_THROW_ON_ERROR);check(!str_contains($json,'PRIVATE_')&&!str_contains($json,'OLDER_PAGE_ONLY'),'Visibility and page scope');
        $older=LittleSparks\journal($db,$page['nextBefore']);check(str_contains(json_encode($older),'OLDER_PAGE_ONLY'),'Older page loads only its own dialogue');
        $all=array_merge(array_column($page['events'],'id'),array_column($older['events'],'id'));check(count($all)===count(array_unique($all))&&count($all)===(int)$db->query("SELECT COUNT(*) FROM events WHERE visibility='public'")->fetchColumn(),'Pagination has no duplicates or skipped events');
        check(LittleSparks\journal($db,1)===['events'=>[],'nextBefore'=>null],'Empty page safe');
        $counting=new JournalCountingPdo('sqlite:'.$path,null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);LittleSparks\journal($counting);check($counting->reads===4,'Four reads including capability detection, no per-event/message queries');$counting=null;
        $saved=LittleSparks\commons($db,[...$ids,$noHome]);check(count($saved['homes'])===4&&!in_array($noHome,array_column($saved['homes'],'resident_id'),true),'Only actual assigned houses appear');
        check(count($saved['conversations'])===2,'Newest exchange per visible pair, without old/private event leakage');
        $first=$saved['conversations'][0];check($first['id']==='first'&&$first['messages'][0]['resident_id']===$ids[0]&&$first['provider']===($version>=3?'openai':'scripted'),'Latest saved conversation with exact speakers/source');
        check($first['messages'][0]['created_at']==='2026-10-24T13:00:00Z','Actual saved timestamp');
        check(!str_contains(json_encode($saved),'PRIVATE_')&&!str_contains(json_encode($saved),'token_hash')&&!str_contains(json_encode($saved),'owner_hash'),'Commons excludes private records/credentials');
        check(LittleSparks\commons($db,[$ids[0],$ids[2]])['conversations']===[],'No unrelated pair dialogue');
        $counting=new JournalCountingPdo('sqlite:'.$path,null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);LittleSparks\commons($counting,$ids);check($counting->reads===8,'Commons uses eight bounded reads including capability detection and one transcript batch, never one query per resident/exchange');$counting=null;
        check($before===journalDigest($db),'Journal preserves schema and every row');file_put_contents($directory.'/v'.$version.'.digest',$before);
        $manifest[]=['version'=>$version,'database'=>$path,'cursor'=>$page['nextBefore'],'events'=>$events,'residents'=>[...$ids,$noHome]];$db=null;
    }
    echo json_encode($manifest,JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
