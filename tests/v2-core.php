<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/schema.php';
require __DIR__.'/../app/social.php';

function check(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function problem(int $status,callable $action): void {
    try { $action(); } catch(LittleSparks\Problem $e) { check($e->status===$status,'Expected '.$status.', got '.$e->status.': '.$e->getMessage()); return; }
    throw new RuntimeException('Expected rejection '.$status);
}
function constraint(callable $action): void {
    try { $action(); } catch(PDOException) { return; } throw new RuntimeException('Expected database constraint');
}
function upgrade(PDO $db): void {
    $db->exec('BEGIN IMMEDIATE');
    try { LittleSparks\migrateSchema($db); $db->exec('COMMIT'); }
    catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function migrateCli(string $path): void {
    $process=proc_open([PHP_BINARY,__DIR__.'/../scripts/migrate-village.php','--database='.$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Migration CLI started');
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0,'Migration CLI: '.$error.$output);
}
try {
    $directory=$argv[1]??'';
    check(is_dir($directory) && str_starts_with(basename($directory),'little-sparks-v2-test-'),'Only a dedicated temporary test directory is allowed.');
    $path=$directory.'/world.sqlite';
    check(!file_exists($path),'Test refuses to overwrite any file.');
    umask(0077);
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec(file_get_contents(__DIR__.'/fixtures/v1.sql'));
    $secret=bin2hex(random_bytes(32));$db->prepare('INSERT INTO settings VALUES (?,?)')->execute(['secret',$secret]);
    $ids=[];$tokens=[];
    foreach(['Moss','Pip','Nova','Clover'] as $i=>$name){
        $id=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);$ids[]=$id;
        $owner=hash_hmac('sha256','fixture-browser-'.$i,$secret);$request=str_repeat(dechex($i+1),32);
        $token=hash_hmac('sha256',$owner.':'.$request,$secret);$tokens[]=$token;
        $fields=['name'=>$name,'color'=>'mint','antenna'=>'sprout','personality'=>['curious','playful','philosophical','shy'][$i]];
        $db->prepare('INSERT INTO residents VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,...array_values($fields),'2026-01-01T00:00:00Z',$owner,$request,hash('sha256',json_encode($fields)),hash('sha256',$token)]);
    }
    $db->exec("INSERT INTO rate_limits VALUES ('preserve-this',123,7)");
    $snapshot=LittleSparks\v1Digest($db);
    $db->exec('PRAGMA foreign_keys=ON'); migrateCli($path);
    check((int)$db->query('PRAGMA user_version')->fetchColumn()===2,'Schema upgraded');
    check(hash_equals($snapshot,LittleSparks\v1Digest($db)),'Every V1 field, setting and rate limit survives');
    check((int)$db->query('SELECT COUNT(*) FROM home_plots')->fetchColumn()===4,'Plots assigned once');
    check((int)$db->query('SELECT COUNT(*) FROM world_objects')->fetchColumn()===12,'Saved house/path/bush per plot');
    $homeSnapshot=$db->query('SELECT * FROM world_objects ORDER BY id')->fetchAll();upgrade($db);
    check($homeSnapshot===$db->query('SELECT * FROM world_objects ORDER BY id')->fetchAll(),'Repeat migration does not reseed homes');
    foreach($tokens as $i=>$token) check(LittleSparks\revisit($db,$token,'fixture',time())['id']===$ids[$i],'Original V1 private link preserved');
    echo "PASS populated V1 migration: identities, owner/token hashes, settings.secret, rate limits, private links, repeatability\n";
    $now=time();$sequence=0;
    $apply=function(int $owner,string $kind,array $extra=[])use($db,$tokens,&$sequence,&$now){
        $input=['token'=>$tokens[$owner],'requestId'=>str_pad(dechex(++$sequence),32,'0',STR_PAD_LEFT),'action'=>$kind]+$extra;
        return LittleSparks\applyWorldAction($db,$input,'fixture-action-network',$now);
    };
    $request=['token'=>$tokens[0],'requestId'=>str_repeat('e',32),'action'=>'plant_tree','x'=>0,'y'=>0];
    $first=LittleSparks\applyWorldAction($db,$request,'fixture',$now);
    $replay=LittleSparks\applyWorldAction($db,$request,'fixture',$now);
    check($first['eventId']===$replay['eventId']&&$replay['replayed'],'Action replay is idempotent');
    problem(409,fn()=>LittleSparks\applyWorldAction($db,array_replace($request,['x'=>2]),'fixture',$now));
    problem(403,fn()=>$apply(0,'plant_tree',['x'=>4,'y'=>0]));
    problem(409,fn()=>$apply(0,'plant_tree',['x'=>1,'y'=>1]));
    problem(422,fn()=>$apply(0,'plant_tree',['x'=>-1,'y'=>0]));
    problem(422,fn()=>$apply(0,'<script>',['x'=>0,'y'=>0]));
    problem(403,fn()=>LittleSparks\applyWorldAction($db,['token'=>str_repeat('0',64)],'fixture',$now));
    $db->exec('DELETE FROM rate_limits');
    $apply(0,'plant_flowers',['x'=>2,'y'=>0]);$apply(0,'decorate_house');$apply(0,'build_path',['x'=>2,'y'=>1]);
    $apply(0,'adopt_pet',['x'=>3,'y'=>1]);$apply(0,'place_decoration',['x'=>0,'y'=>1]);
    problem(429,fn()=>$apply(0,'plant_bush',['x'=>2,'y'=>2]));
    $now+=86400;$db->exec('DELETE FROM rate_limits');
    problem(409,fn()=>$apply(0,'adopt_pet',['x'=>3,'y'=>2]));
    problem(409,fn()=>$apply(0,'decorate_house'));
    $apply(0,'plant_bush',['x'=>2,'y'=>2]);$apply(0,'plant_flowers',['x'=>3,'y'=>2]);$apply(0,'plant_tree',['x'=>0,'y'=>2]);$apply(0,'plant_flowers',['x'=>0,'y'=>3]);
    problem(409,fn()=>$apply(0,'plant_tree',['x'=>2,'y'=>3]));
    problem(422,fn()=>$apply(1,'visit_friend',['target'=>$ids[1]]));
    $apply(1,'visit_friend',['target'=>$ids[0]]);problem(429,fn()=>$apply(1,'visit_friend',['target'=>$ids[0]]));
    check(LittleSparks\pair($db,$ids[0],$ids[1])!==null,'Visits establish bounded relationships');
    constraint(fn()=>$db->exec("INSERT INTO world_objects VALUES ('orphan','missing','tree','oak',100,100,'now')"));
    constraint(fn()=>$db->exec("INSERT INTO world_objects VALUES ('foreign-plot','{$ids[2]}','tree','oak',300,300,'now')"));
    constraint(fn()=>$db->exec('UPDATE events SET kind=kind'));
    constraint(fn()=>$db->exec('DELETE FROM events'));
    constraint(fn()=>$db->exec('UPDATE relationships SET affinity=101'));
    constraint(fn()=>$db->exec('UPDATE home_plots SET slot=slot+100'));
    $objectCount=$db->query('SELECT COUNT(*) FROM world_objects')->fetchColumn();
    $residentCount=$db->query('SELECT COUNT(*) FROM residents')->fetchColumn();
    $db->exec("CREATE TRIGGER fixture_journal_failure BEFORE INSERT ON events BEGIN SELECT RAISE(ABORT,'Fixture journal failure'); END");
    constraint(fn()=>$apply(2,'plant_tree',['x'=>8,'y'=>0]));
    check($objectCount===$db->query('SELECT COUNT(*) FROM world_objects')->fetchColumn(),'Journal failure rolls back the world object');
    constraint(fn()=>LittleSparks\create($db,['name'=>'Fixture','color'=>'mint','antenna'=>'orb','personality'=>'shy','website'=>'','requestId'=>str_repeat('b',32)],'new-fixture-browser','fixture-new-network',time(),true));
    check($residentCount===$db->query('SELECT COUNT(*) FROM residents')->fetchColumn(),'Home/journal failure rolls back new resident identity');
    $db->exec('DROP TRIGGER fixture_journal_failure');
    echo "PASS structured actions: ownership, map bounds, collisions, object/pet/daily limits, visits, replay, database constraints\n";
    $now=time();$db->exec('DELETE FROM rate_limits');
    $topicPlanner=new class implements LittleSparks\SocialPlanner {
        public function plan(array $a,array $b,string $period):array{return ['action'=>'greet','topic'=>'garden'];}
    };
    $day=gmdate('Y-m-d',$now-6*86400);
    $run=LittleSparks\simulateDay($db,$day,$topicPlanner,$now);
    check($run['interaction_count']>0,'Daily simulation creates actual persisted interactions');
    $eventCount=$db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    check(LittleSparks\simulateDay($db,$day,$topicPlanner,$now)['replayed'],'Same period is replayed');
    check($eventCount===$db->query('SELECT COUNT(*) FROM events')->fetchColumn(),'Rerun adds no events');
    $public=LittleSparks\profile($db,$ids[2]);check(count($public['memories'])>0,'Individual memories are persisted');
    $badPlanner=new class implements LittleSparks\SocialPlanner {
        public function plan(array $a,array $b,string $period):array{return ['action'=>'execute_shell','topic'=>'<script>'];}
    };
    $failedPeriod=gmdate('Y-m-d',$now-5*86400);
    $state=serialize([$db->query('SELECT * FROM relationships')->fetchAll(),$db->query('SELECT * FROM memories')->fetchAll(),$eventCount]);
    problem(503,fn()=>LittleSparks\simulateDay($db,$failedPeriod,$badPlanner,$now));
    check($state===serialize([$db->query('SELECT * FROM relationships')->fetchAll(),$db->query('SELECT * FROM memories')->fetchAll(),$db->query('SELECT COUNT(*) FROM events')->fetchColumn()]),'Rejected provider cannot apply partial changes');
    $failed=LittleSparks\simulateDay($db,$failedPeriod,null,$now);check($failed['status']==='failed'&&$failed['replayed'],'Failed outcome is recorded without sensitive exception text');
    putenv('LITTLE_SPARKS_AI_ENABLED=1');problem(503,fn()=>LittleSparks\simulateDay($db,gmdate('Y-m-d',$now-4*86400),null,$now));putenv('LITTLE_SPARKS_AI_ENABLED=0');
    problem(422,fn()=>LittleSparks\simulateDay($db,gmdate('Y-m-d',$now+86400),null,$now));
    // Private fixture records exercise the public privacy boundary; there is no visitor chat UI.
    $privateId='private-fixture';
    $db->prepare("INSERT INTO conversations VALUES (?,?,'quiet','private','2020-01-01T00:00:00Z')")->execute([$privateId,$run['id']]);
    $db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$privateId,$ids[2]]);
    $db->prepare('INSERT INTO messages VALUES (?,0,?,?)')->execute([$privateId,$ids[2],'SECRET VISITOR MESSAGE']);
    $db->prepare("INSERT INTO memories(resident_id,other_resident_id,run_id,summary,significance,visibility,created_at) VALUES (?,?,?,'SECRET VISITOR MEMORY',5,'private','2020-01-01T00:00:00Z')")->execute([$ids[0],$ids[2],$failed['id']]);
    $db->prepare("INSERT INTO events(actor_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,'conversation','simulation','private','2020-01-01T00:00:00Z','private-fixture')")->execute([$ids[0]]);
    check(!str_contains(json_encode(LittleSparks\profile($db,$ids[0])),'SECRET'),'Private memories excluded');
    check(!str_contains(json_encode(LittleSparks\profile($db,$ids[2])),'SECRET'),'Private messages excluded');
    check(!in_array('private-fixture',array_column(LittleSparks\journal($db)['events'],'dedupe_key'),true),'Journal exposes no dedupe credentials');
    $publicJournal=LittleSparks\journal($db);check(!in_array($db->lastInsertId(),array_column($publicJournal['events'],'id')),'Private event excluded');
    LittleSparks\simulateDay($db,gmdate('Y-m-d',$now-4*86400),$topicPlanner,$now);
    check(!$db->query("SELECT 1 FROM conversations WHERE id='private-fixture'")->fetchColumn(),'Expired conversation pruned');
    $affinityBefore=(int)$db->query('SELECT SUM(affinity) FROM relationships')->fetchColumn();
    $quietPlanner=new class implements LittleSparks\SocialPlanner {
        public function plan(array $a,array $b,string $period):array{return ['action'=>'greet','topic'=>'quiet'];}
    };
    LittleSparks\simulateDay($db,gmdate('Y-m-d',$now-3*86400),$quietPlanner,$now);
    check((int)$db->query('SELECT SUM(affinity) FROM relationships')->fetchColumn()<$affinityBefore,'Respectful quiet time can reduce affinity without cruelty');
    for($i=0;$i<16;$i++){
        $fake='retention-'.$i;$date='2000-01-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,'completed','scripted','fixture','fixture')")->execute([$fake,$date]);
        LittleSparks\remember($db,$ids[0],$ids[3],$fake,'A curated fixture memory.',2,$date.'T00:00:00Z');
    }
    $query=$db->prepare('SELECT COUNT(*) FROM memories WHERE resident_id=?');$query->execute([$ids[0]]);check((int)$query->fetchColumn()===12,'Memory retention stays bounded');
    check(!$db->query('PRAGMA foreign_key_check')->fetch(),'All foreign keys valid');
    $query=$db->prepare('SELECT COUNT(*) FROM relationships WHERE resident_a=? OR resident_b=?');$query->execute([$ids[3],$ids[3]]);$existing=(int)$query->fetchColumn();
    for($i=0;$i<9;$i++){
        $neighbor=str_pad(dechex(100+$i),32,'0',STR_PAD_LEFT);
        $db->prepare('INSERT INTO residents SELECT ?,name,color,antenna,personality,created_at,?,?,request_hash,? FROM residents LIMIT 1')
            ->execute([$neighbor,'fixture-neighbor-'.$i,str_pad(dechex($i),32,'0',STR_PAD_LEFT),hash('sha256',$neighbor)]);
        LittleSparks\assignHome($db,$neighbor,gmdate('Y-m-d\TH:i:s\Z',$now));
        if($i<8-$existing)LittleSparks\strengthen($db,$ids[3],$neighbor,1,gmdate('Y-m-d\TH:i:s\Z',$now));
        else problem(409,fn()=>LittleSparks\strengthen($db,$ids[3],$neighbor,1,gmdate('Y-m-d\TH:i:s\Z',$now)));
    }
    $query->execute([$ids[3],$ids[3]]);check((int)$query->fetchColumn()===8,'Relationship degree is bounded at eight');
    $numericA=str_repeat('9',31).'1';$numericB=str_repeat('9',31).'2';
    foreach([$numericA,$numericB] as $numeric){
        $db->prepare('INSERT INTO residents SELECT ?,name,color,antenna,personality,created_at,?,?,request_hash,? FROM residents LIMIT 1')
            ->execute([$numeric,'fixture-'.$numeric,$numeric,hash('sha256',$numeric)]);
        LittleSparks\assignHome($db,$numeric,gmdate('Y-m-d\TH:i:s\Z',$now));
    }
    LittleSparks\strengthen($db,$numericB,$numericA,1,gmdate('Y-m-d\TH:i:s\Z',$now));
    check(LittleSparks\pair($db,$numericA,$numericB)!==null,'Resident pairs use lexical ordering even for numeric-looking IDs');
    echo "PASS social engine: saved dialogue/memories, day idempotency, failed-run rollback, mock proposal rejection, AI fail-closed, public/private separation, retention\n";
    // A V1-labelled unrelated schema must be refused without touching its data.
    $unrelated=new PDO('sqlite:'.$directory.'/unrelated.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $unrelated->exec('CREATE TABLE important(value TEXT); PRAGMA user_version=1');
    try{upgrade($unrelated);throw new LogicException('Unrelated schema accepted');}catch(RuntimeException $error){check(!($error instanceof LogicException),'Unrelated V1 refused');}
    check((int)$unrelated->query('PRAGMA user_version')->fetchColumn()===1,'Refused migration leaves version unchanged');
    $unrelated->exec('PRAGMA user_version=99');
    try{upgrade($unrelated);throw new LogicException('Unknown version accepted');}catch(RuntimeException $error){check(!($error instanceof LogicException),'Unknown version refused');}
    echo "PASS refused migrations leave unrelated/unknown schemas unchanged\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);}
