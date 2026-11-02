<?php
declare(strict_types=1);
// Fixed-clock synthetic newspaper/SQLite fixtures. No provider or credentials.
require_once __DIR__.'/../app/director.php';
require_once __DIR__.'/../app/activity.php';
$fixtureLimits=['LITTLE_SPARKS_MEMORY_CONTEXT_BYTES'=>'8192','LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS'=>'13312','LITTLE_SPARKS_AI_MAX_REQUEST_BYTES'=>'32768'];$priorLimits=[];
foreach($fixtureLimits as $key=>$value){$priorLimits[$key]=getenv($key);putenv($key.'='.$value);}
try{
    $u=$freshOwner('reporting-context');$upgrade($u);$u->now=strtotime('2026-10-30T12:00:00Z');$u->autoPolicy=['version'=>1,'mode'=>'initial_only','enabled_at'=>0];$authorize($u);
    $base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($u->policy,90000,$release)];
    $p=$u->call($base+['op'=>'begin_generation']);$provider=new BrokerProvider('good',$u->db);
    foreach(['generation'=>'responses','moderation'=>'moderations'] as $stage=>$endpoint){$wire=$provider->post($endpoint,$p['body'],$p['deadline']);$p=$u->call($base+['op'=>'complete_'.$stage,'attempt_id'=>$p['attempt_id'],'request_id'=>$p['request_id'],'capability'=>$p['capability'],'wire'=>$wire]);}
    brokerCheck($p['status']==='published_automatically','Fixture is a completed, moderated, genuinely published edition');
    $db=$u->db;$path=$db->query('PRAGMA database_list')->fetch()['file'];
    $authors=$db->query("SELECT resident_id FROM messages WHERE conversation_id='integrity-good'")->fetchAll(PDO::FETCH_COLUMN);
    $people=$db->query('SELECT r.id,r.name FROM residents r JOIN home_plots h ON h.resident_id=r.id ORDER BY r.id')->fetchAll();
    $pair=array_slice(array_values(array_filter($people,fn($r)=>!in_array($r['id'],$authors,true))),0,2);brokerCheck(count($pair)===2,'Newspaper is available to two actual non-author residents');
    $digest=static function(PDO $db):string{$state=[];foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as $t)$state[$t['name']]=[$t['sql'],$db->query('SELECT * FROM "'.$t['name'].'" ORDER BY rowid')->fetchAll()];return hash('sha256',json_encode($state));};
    $claim=['id'=>'reporting-context-fixture','created_at'=>gmdate('Y-m-d\TH:i:s\Z',$u->now)];$cfg=LittleSparks\directorConfiguration();$before=$digest($db);
    $prepared=LittleSparks\prepareDirectorEncounter($db,$pair,['none'=>null],$claim,$cfg);$context=$prepared['context'];$articles=$context['published_gazette']??[];
    brokerCheck(count($articles)===1&&strlen(json_encode($articles,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=LittleSparks\DIRECTOR_GAZETTE_BYTES,'Published context uses the actual Director JSON byte bound and is available without claiming reading');
    $edition=LittleSparks\directorGazetteEdition($db,'2026-10-29',$u->now);$story=$edition['stored']['draft']['stories'][0];
    brokerCheck($articles[0]['paragraphs']===array_column($story['paragraphs'],'text')&&$articles[0]['headline']===$story['headline']['text'],'Whole published prose survives byte-exactly');
    brokerCheck(!str_contains(json_encode($articles),'source_alias')&&!str_contains(json_encode($articles),'PRIVATE_EDITORIAL_CANARY'),'Reporting offers no memory aliases or private material');
    foreach($context['participants'] as $resident)brokerCheck(!in_array('integrity-good',array_column($resident['recent_conversations'],'id'),true),'Third-party source is not firsthand participant history');
    brokerCheck($before===$digest($db),'Context preparation changes no history, memories, sessions, accounting or exposure records');
    brokerCheck(LittleSparks\directorGazetteContext($db,$pair,$u->now-1)===[]&&LittleSparks\directorGazetteContext($db,$pair,$u->now+4*86400)===[],'Future publications and stale editions are omitted');
    $core=$context;unset($core['published_gazette'],$core['gazette_reporting_policy']);$bytes=strlen(json_encode($core,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $trimmed=LittleSparks\trimDirectorContext($context,$bytes);
    brokerCheck($trimmed===$core&&LittleSparks\directorGazetteReceipt($trimmed['published_gazette']??[])===null,'Reporting is removed before any personal history or identity and produces no dropped exposure');
    $instructions=LittleSparks\directorEncounterGuidance();brokerCheck(str_contains($instructions,'no fresh subject is required')&&str_contains($instructions,'never an assigned agenda'),'Natural continuity replaces forced topic novelty');
    brokerCheck(str_contains(LittleSparks\directorSchema(['none'=>null])['properties']['topic']['description'],'descriptive label'),'Topic is descriptive metadata without a contract/storage change');
    $export=['id'=>'agent_reporting_fixture','model'=>'gpt-6-luna','instructions'=>'SYNTHETIC_SAVED_DIRECTOR_INSTRUCTIONS','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']];
    $reg=LittleSparks\registerDirector($db,$export);$registered=$export+['agent_id'=>$export['id'],'fingerprint'=>$reg['fingerprint']];
    $without=LittleSparks\directorSession($db,$registered,$pair,'agents',$u->now,$context['contract']);
    $session=LittleSparks\directorSession($db,$registered,$pair,'agents',$u->now,$context['contract'],$prepared['session_scope']);
    brokerCheck($session['id']!==$without['id']&&LittleSparks\directorSession($db,$registered,$pair,'agents',$u->now,$context['contract'],$prepared['session_scope'])['id']===$session['id'],'Exact retained newspaper scope reuses once, absence cannot reuse a newspaper-bearing session');
    $wire=LittleSparks\directorRequest(['model'=>'gpt-6-luna','max_output'=>13312,'effort'=>'none'],$registered,$prepared,['none'=>null]);
    brokerCheck(json_decode($wire['input'],true)['published_gazette']===$articles&&$wire['instructions']===$export['instructions'],'Responses receives fresh reporting without overriding registered instructions');
    $managed=LittleSparks\managedDirectorPrototype($export,$context,['none'=>null]);
    brokerCheck(json_decode($managed['input'][0]['content'][0]['text'],true)['published_gazette']===$articles&&!isset($managed['agent']['instructions']),'Managed sessions receive identical fresh reporting without hosted edits');
    $addCanonical=function(string $run,string $conversation)use($db,$pair,$session,$reg,$u):void{
        $time=gmdate('Y-m-d\TH:i:s\Z',$u->now);
        $db->prepare("INSERT INTO simulation_runs(id,period,status,provider,started_at,finished_at,interaction_count) VALUES (?,?,'completed','scripted',?,?,1)")->execute([$run,'reporting-fixture:'.$run,$time,$time]);
        $db->prepare("INSERT INTO conversations VALUES (?,?,'welcome','public',?)")->execute([$conversation,$run,$time]);
        foreach($pair as $i=>$resident){$db->prepare('INSERT INTO conversation_participants VALUES (?,?)')->execute([$conversation,$resident['id']]);$db->prepare('INSERT INTO messages VALUES (?,?,?,?)')->execute([$conversation,$i,$resident['id'],$i?'A suggestion can still be worth talking about.':'The Gazette reports a lost-and-found suggestion.']);}
        $db->prepare("INSERT INTO events(actor_id,target_id,run_id,kind,origin,visibility,created_at,dedupe_key) VALUES (?,?,?,'conversation','simulation','public',?,?)")->execute([$pair[0]['id'],$pair[1]['id'],$run,$time,'activity:'.$run.':conversation']);
        $db->prepare('INSERT INTO director_runs VALUES (?,?,?,?,?,?)')->execute([$run,$session['id'],$reg['fingerprint'],$conversation,'A newspaper suggestion',$time]);
    };
    $run=bin2hex(random_bytes(16));$conversation=bin2hex(random_bytes(16));$receipt=$prepared['gazette_context_receipt'];
    $db->exec('BEGIN IMMEDIATE');$addCanonical($run,$conversation);LittleSparks\saveDirectorGazetteExposure($db,$run,$conversation,$pair,$receipt,gmdate('Y-m-d\TH:i:s\Z',$u->now));$db->exec('ROLLBACK');
    brokerCheck(!$db->query("SELECT 1 FROM settings WHERE name='director_gazette_context:$run'")->fetchColumn(),'Rolled-back conversation never records exposure');
    $db->exec('BEGIN IMMEDIATE');$addCanonical($run,$conversation);LittleSparks\saveDirectorGazetteExposure($db,$run,$conversation,$pair,$receipt,gmdate('Y-m-d\TH:i:s\Z',$u->now));$db->exec('COMMIT');
    $audit=json_decode($db->query("SELECT value FROM settings WHERE name='director_gazette_context:$run'")->fetchColumn(),true);
    brokerCheck($audit['conversation_id']===$conversation&&$audit['influence_verified']===false&&$audit['articles'][0]['original_sources']===$articles[0]['citations'],'Accepted context records public source lineage, not reading, belief, agreement or verified fact');
    $pack=LittleSparks\editorialSourcePack($u->read,'2026-10-30',$u->now+86400);$source=array_values(array_filter($pack['pack']['sources'],fn($s)=>$s['id']===$conversation))[0];
    brokerCheck($source['prior_gazette_context']['status']==='optional_context_supplied'&&$source['prior_gazette_context']['influence_verified']===false&&!isset($source['prior_gazette_context']['participant_ids']),'Next newspaper sees possible reporting lineage without private receipt details or claiming influence');
    $newRun=bin2hex(random_bytes(16));$newConversation=bin2hex(random_bytes(16));$db->exec('BEGIN IMMEDIATE');$addCanonical($newRun,$newConversation);
    $db->exec("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
    brokerReject(fn()=>LittleSparks\saveDirectorGazetteExposure($db,$newRun,$newConversation,$pair,$receipt,gmdate('Y-m-d\TH:i:s\Z',$u->now)),'Withdrawn source accepted after moderation');$db->exec('ROLLBACK');
    brokerCheck(!$db->query("SELECT 1 FROM conversations WHERE id='$newConversation'")->fetchColumn(),'Recheck failure rolls back the whole accepted conversation');
    $db->exec("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
    brokerCheck(LittleSparks\directorGazetteContext($db,$pair,$u->now)===[],'Unavailable reporting fails optional retrieval closed');
    brokerCheck(str_contains(LittleSparks\editorialInstructions(),'ordinary reporting verbs')&&str_contains(LittleSparks\editorialInstructions(),'not independent confirmation')&&!str_contains(LittleSparks\editorialInstructions(),'All claims require owner factual review'),'Natural editorial guidance retains attribution and matches automatic-publication policy');
    $large=$freshOwner('oversized-reporting');$upgrade($large);$large->now=strtotime('2026-10-30T12:00:00Z');$large->autoPolicy=['version'=>1,'mode'=>'initial_only','enabled_at'=>0];$authorize($large);
    $base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($large->policy,90000,$release)];$p=$large->call($base+['op'=>'begin_generation']);$provider=new BrokerProvider('good',$large->db);
    foreach(['generation'=>'responses','moderation'=>'moderations'] as $stage=>$endpoint){$wire=$provider->post($endpoint,$p['body'],$p['deadline']);
        if($stage==='generation'){$draft=json_decode($wire['body']['output'][0]['content'][0]['text'],true);$paragraph=$draft['stories'][0]['paragraphs'][0];$paragraph['text']=str_repeat('é',1000);$draft['stories'][0]['paragraphs']=[$paragraph,$paragraph,$paragraph];$wire['body']['output'][0]['content'][0]['text']=json_encode($draft);}
        $p=$large->call($base+['op'=>'complete_'.$stage,'attempt_id'=>$p['attempt_id'],'request_id'=>$p['request_id'],'capability'=>$p['capability'],'wire'=>$wire]);}
    brokerCheck($p['status']==='published_automatically'&&LittleSparks\directorGazetteContext($large->db,$pair,$large->now)===[],'Oversized complete article is omitted rather than truncating words or qualifying paragraphs');
    echo "PASS optional published Gazette context: exact complete articles, bounds/privacy, third-party reporting boundaries, source recheck/rollback, exposure lineage, feedback caution, session scope and both adapters (offline)\n";
}finally{foreach($priorLimits as $key=>$value)putenv($value===false?$key:$key.'='.$value);}
