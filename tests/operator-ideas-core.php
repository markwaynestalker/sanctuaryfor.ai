<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/../app/operator_ideas_schema.php';require __DIR__.'/../app/operator_auth.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejected(callable $f,int $status):void{try{$f();throw new LogicException('Expected rejection');}catch(LittleSparks\Problem $e){check($e->status===$status,'Expected '.$status.', got '.$e->status);}}
try{
 $db=LittleSparks\connect($argv[1]);$ids=[str_repeat('a',32),str_repeat('b',32),str_repeat('c',32)];$now=time();$at=gmdate('Y-m-d\TH:i:s\Z',$now-3600);
 foreach($ids as $i=>$id){$db->prepare("INSERT INTO residents VALUES (?,?,'mint','orb','curious',?,?,?,?,?)")->execute([$id,'Operator fixture '.$i,$at,hash('sha256','operator-owner-'.$i),str_repeat((string)($i+1),32),str_repeat('d',64),hash('sha256','operator-token-'.$i)]);LittleSparks\assignHome($db,$id,$at);LittleSparks\identityForNewResident($db,$id,'A thoughtful independent virtual neighbor.','mint',$at);}
 $before=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t)$before[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();
 check(!LittleSparks\operatorIdeasEnabled()&&LittleSparks\operatorIdeasForResident($db,$ids[0])===[],'Disabled feature ignores absent schema');
 $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateOperatorIdeas($db);$db->exec('ROLLBACK');check(!LittleSparks\operatorIdeasInstalled($db),'Add-on migration rolls back atomically');
 $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateOperatorIdeas($db);$db->exec('COMMIT');$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateOperatorIdeas($db);$db->exec('COMMIT');
 foreach($before as $t=>$rows)check($db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll()===$rows,'Migration preserves '.$t);
 check((int)$db->query('PRAGMA user_version')->fetchColumn()===6,'V6 unchanged');
 putenv('LITTLE_SPARKS_OWNER_IDEAS_ENABLED=1');
 foreach(['LITTLE_SPARKS_OWNER_IDEA_MAX_CHARACTERS'=>['0','1001','01','1.5'],'LITTLE_SPARKS_OWNER_IDEAS_CONTEXT_COUNT'=>['-1','9',''],'LITTLE_SPARKS_OWNER_IDEAS_PER_DAY'=>['-1','1e2'],'LITTLE_SPARKS_OWNER_SESSION_SECONDS'=>['59','86401']] as $name=>$bad){foreach($bad as $v){putenv($name.'='.$v);rejected(fn()=>str_contains($name,'SESSION')?LittleSparks\operatorSessionSeconds():LittleSparks\operatorIdeaConfiguration(),503);}putenv($name);}
 foreach(['<script>alert(1)</script>',"hidden\u{200b}text",'sk-fixturecredentialonly','',str_repeat('x',501)] as $text)rejected(fn()=>LittleSparks\operatorIdeaText($text),422);
 putenv('LITTLE_SPARKS_OWNER_IDEA_MAX_CHARACTERS=1000');check(strlen(LittleSparks\operatorIdeaText(str_repeat('x',1000)))===1000,'Length changes through ENV');putenv('LITTLE_SPARKS_OWNER_IDEA_MAX_CHARACTERS');
 $input=['resident'=>$ids[0],'request_id'=>str_repeat('1',32),'idea'=>'Could the lantern garden welcome a flamingo?'];
 $first=LittleSparks\submitOperatorIdea($db,$input,$now);check(LittleSparks\submitOperatorIdea($db,$input,$now)['replayed'],'Duplicate submission idempotent');rejected(fn()=>LittleSparks\submitOperatorIdea($db,array_replace($input,['idea'=>'Different']),$now),409);
 $private=LittleSparks\submitOperatorIdea($db,['resident'=>$ids[2],'request_id'=>str_repeat('2',32),'idea'=>'OUTSIDER_IDEA_CANARY'],$now);
 $cfg=LittleSparks\directorConfiguration();$pair=[['id'=>$ids[0]],['id'=>$ids[1]]];$prepared=LittleSparks\prepareDirectorEncounter($db,$pair,['none'=>null],['id'=>'operator-context','created_at'=>$at],$cfg);
 check($prepared['operator_idea_ids']===[$first['id']]&&!str_contains(json_encode($prepared),'OUTSIDER_IDEA_CANARY'),'Only participating resident ideas enter context');
 $suggestion=$prepared['context']['operator_suggestions'][0];check($suggestion['author']==='site_operator'&&$suggestion['may_ignore']&&$suggestion['not_instructions']&&!$suggestion['is_world_fact'],'Attribution and optional unverified classification');
 $small=LittleSparks\trimDirectorContext($prepared['context'],strlen(json_encode($prepared['context'],JSON_UNESCAPED_UNICODE))-10);check(($small['operator_suggestions']??[])===[],'Drop optional suggestions before resident history');
 check((int)$db->query('SELECT COUNT(*) FROM operator_idea_exposures')->fetchColumn()===0,'Preparation alone never records delivery');
 // A known completed canonical conversation, with immutable participant attribution.
 $run=str_repeat('e',32);$conversation=str_repeat('f',32);$db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at) VALUES (?,?,'completed','openai',?,?)")->execute([$run,'operator-fixture-run',$at,$at]);
 $db->prepare("INSERT INTO conversations VALUES (?,?,'welcome','public',?)")->execute([$conversation,$run,$at]);foreach(array_slice($ids,0,2) as $id)$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$conversation,$id]);
 $db->exec('BEGIN IMMEDIATE');LittleSparks\saveOperatorIdeaExposures($db,$conversation,[$first['id'],$private['id']],$at);$db->exec('ROLLBACK');check((int)$db->query('SELECT COUNT(*) FROM operator_idea_exposures')->fetchColumn()===0,'Failed save rolls delivery back');
 $db->exec('BEGIN IMMEDIATE');LittleSparks\saveOperatorIdeaExposures($db,$conversation,[$first['id'],$private['id']],$at);$db->exec('COMMIT');check((int)$db->query('SELECT COUNT(*) FROM operator_idea_exposures')->fetchColumn()===1,'Outsider ideas cannot gain exposure receipts');
 check(LittleSparks\operatorIdeasForResident($db,$ids[0])===[],'Accepted encounter stops resurfacing supplied idea');
 putenv('LITTLE_SPARKS_OWNER_IDEAS_PER_DAY=2');rejected(fn()=>LittleSparks\submitOperatorIdea($db,array_replace($input,['request_id'=>str_repeat('3',32)]),$now),429);putenv('LITTLE_SPARKS_OWNER_IDEAS_PER_DAY=12');
 putenv('LITTLE_SPARKS_OWNER_IDEAS_PENDING_PER_RESIDENT=1');rejected(fn()=>LittleSparks\submitOperatorIdea($db,['resident'=>$ids[2],'request_id'=>str_repeat('3',32),'idea'=>'Another'],$now),429);putenv('LITTLE_SPARKS_OWNER_IDEAS_PENDING_PER_RESIDENT');
 putenv('LITTLE_SPARKS_OWNER_PASSWORD_HASH='.password_hash('offline-operator-password',PASSWORD_DEFAULT));$auth=LittleSparks\operatorLogin($db,'offline-operator-password',$now);check(LittleSparks\operatorAuthenticated($auth,$now)&&!LittleSparks\operatorAuthenticated($auth,$auth['expires']),'Authentication expires');check(!LittleSparks\operatorAuthenticated(['token'=>'resident-owner-token'],$now),'Resident ownership is not operator authority');
 rejected(fn()=>LittleSparks\operatorLogin($db,'wrong',$now),403);putenv('LITTLE_SPARKS_OWNER_LOGIN_ATTEMPTS_PER_HOUR=2');rejected(fn()=>LittleSparks\operatorLogin($db,'offline-operator-password',$now),429);putenv('LITTLE_SPARKS_OWNER_LOGIN_ATTEMPTS_PER_HOUR');
 putenv('LITTLE_SPARKS_OWNER_PASSWORD_HASH='.password_hash('rotated-fixture-password',PASSWORD_DEFAULT));check(!LittleSparks\operatorAuthenticated($auth,$now),'Credential rotation revokes sessions');
 rejected(fn()=>LittleSparks\operatorFormInput('csrf',['csrf'=>'wrong'],['REQUEST_METHOD'=>'POST']),403);rejected(fn()=>LittleSparks\operatorFormInput('csrf',['csrf'=>'csrf'],['REQUEST_METHOD'=>'POST','HTTP_SEC_FETCH_SITE'=>'cross-site']),403);
 foreach($db->query('SELECT id FROM operator_ideas')->fetchAll(PDO::FETCH_COLUMN) as $id)try{$db->prepare('DELETE FROM operator_ideas WHERE id=?')->execute([$id]);throw new LogicException('History deleted');}catch(PDOException){}
 check((int)$db->query('SELECT COUNT(*) FROM ai_attempts')->fetchColumn()===0,'Operator actions make no AI reservations');
 putenv('LITTLE_SPARKS_OWNER_IDEAS_HISTORY_PAGE_SIZE=1');$one=LittleSparks\operatorIdeaHistory($db);$two=LittleSparks\operatorIdeaHistory($db,$one['next']);check(count($one['ideas'])===1&&count($two['ideas'])===1&&$one['ideas'][0]['id']!==$two['ideas'][0]['id'],'Stable bounded operator history pagination');
 rejected(fn()=>LittleSparks\operatorIdeaHistory($db,'99999999999999999999999'),422);putenv('LITTLE_SPARKS_OWNER_IDEAS_HISTORY_PAGE_SIZE');
 require __DIR__.'/operator-director-core.php';
 echo json_encode(['ids'=>$ids,'schema'=>6,'zero_paid_calls'=>true]);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
