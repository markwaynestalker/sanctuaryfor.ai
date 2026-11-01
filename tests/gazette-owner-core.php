<?php
declare(strict_types=1);
// Included from the existing Gazette suite: synthetic public records, mock HTTP only.
require_once __DIR__.'/../app/gazette_editor_publication.php';
$freshOwner=function(string $name)use($dir,$release,$policy):FixtureBroker{
    $path=$dir.'/owner-'.$name.'.sqlite';copy($dir.'/v6.sqlite',$path);chmod($path,0600);
    $db=LittleSparks\connect($path);$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($db);$db->exec('COMMIT');
    $read=LittleSparks\connect($path);$read->exec('PRAGMA query_only=ON');
    $p=$policy;$p['daily_microusd']=1000000;$p['monthly_microusd']=20000000;$p['daily_requests']=20;
    return new FixtureBroker($db,$read,$p,$release);
};
$upgrade=function(FixtureBroker $b):void{$b->db->exec('BEGIN IMMEDIATE');LittleSparks\upgradeEditorialOwnerWorkflow($b->db);$b->db->exec('COMMIT');};
$authorize=function(FixtureBroker $b,bool $preview=false,?string $date=null)use($release):void{
    $b->authorization=['id'=>bin2hex(random_bytes(16)),'date'=>$date??'2026-10-29','preview'=>$preview,'expires_at'=>$b->now+300,'max_microusd'=>50000,'release'=>$release];
};
$execute=function(FixtureBroker $b,string $mode='good')use($release):array{$provider=new BrokerProvider($mode,$b->db);$r=LittleSparks\runBrokeredEditorialJob($b,$provider,$b->policy,90000,$release);return [$r,$provider];};
$world=function(PDO $db):array{$out=[];foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'gazette_editor_%' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as $row)$out[$row['name']]=[$row['sql'],$db->query('SELECT * FROM "'.$row['name'].'" ORDER BY rowid')->fetchAll()];return $out;};
$b=$freshOwner('history');[$failed]=$execute($b,'lead_one');
$oldWorld=$world($b->db);
$old=$b->db->query('SELECT * FROM gazette_editor_attempts')->fetchAll();$receipt=$b->db->query('SELECT * FROM gazette_editor_receipts')->fetchAll();
$b->db->exec('BEGIN IMMEDIATE');LittleSparks\upgradeEditorialOwnerWorkflow($b->db);$b->db->exec('ROLLBACK');
brokerCheck((int)$b->db->query('SELECT revision FROM gazette_editor_meta')->fetchColumn()===1&&$b->db->query('SELECT * FROM gazette_editor_attempts')->fetchAll()===$old,'Upgrade rollback preserves complete v1 ledger');
$upgrade($b);$upgrade($b);
$saved=$b->db->query('SELECT * FROM gazette_editor_attempts')->fetchAll();unset($saved[0]['is_preview']);
brokerCheck($saved===$old&&$b->db->query('SELECT * FROM gazette_editor_receipts')->fetchAll()===$receipt,'Reviewed upgrade preserves rejected attempt and exact observed accounting');
brokerCheck($world($b->db)===$oldWorld,'Upgrade preserves every shared village table/schema/row');
$authorize($b);[$new,$provider]=$execute($b);
brokerCheck($new['status']==='awaiting_owner_review'&&$provider->calls===['responses','moderations'],'Owner-authorized rejected revision travels through same secure broker');
brokerCheck((int)$b->db->query('SELECT MAX(ordinal) FROM gazette_editor_attempts')->fetchColumn()===2&&(int)$b->db->query('SELECT SUM(reserved_microusd) FROM gazette_editor_attempts')->fetchColumn()===60000,'Revision preserves earlier hold and adds its own');
$used=$b->authorization;$calls=$provider->calls;
brokerReject(fn()=>LittleSparks\runBrokeredEditorialJob($b,$provider,$b->policy,90000,$release),'Consumed authorization reused');brokerCheck($calls===$provider->calls,'Consumed job never redispatches');
$authorize($b);brokerReject(fn()=>$execute($b),'Unreviewed finished edition silently regenerated');
LittleSparks\reviewEditorialDraft($b->db,$new['attempt_id'],$new['draft_sha256'],'rejected',$b->now);
$authorize($b);[$third]=$execute($b);brokerCheck($third['status']==='awaiting_owner_review','Owner-rejected journalism can be regenerated without manual permit math');
for($n=0;$n<7;$n++){
    LittleSparks\reviewEditorialDraft($b->db,$third['attempt_id'],$third['draft_sha256'],'rejected',$b->now);$authorize($b);[$third]=$execute($b);
}
brokerCheck((int)$b->db->query('SELECT MAX(ordinal) FROM gazette_editor_attempts')->fetchColumn()===10,'Reviewed upgrade removes lifetime eight-attempt execution cap');
brokerCheck((int)$b->db->query('SELECT SUM(request_slots) FROM gazette_editor_attempts')->fetchColumn()===20,'All successful/rejected attempt slots remain charged');
LittleSparks\reviewEditorialDraft($b->db,$third['attempt_id'],$third['draft_sha256'],'rejected',$b->now);$authorize($b);brokerReject(fn()=>$execute($b),'Twenty request cap bypassed');
foreach(['partial','timeout'] as $mode){
    $u=$freshOwner($mode);$upgrade($u);$authorize($u);
    if($mode==='timeout')brokerReject(fn()=>$execute($u,$mode),'Unknown network not propagated');else $execute($u,$mode);
    $before=$u->db->query('SELECT * FROM gazette_editor_attempts')->fetchAll();$authorize($u);brokerReject(fn()=>$execute($u),'Unknown request regenerated');
    brokerCheck($u->db->query('SELECT * FROM gazette_editor_attempts')->fetchAll()===$before,'Uncertain reservation unchanged');
}
foreach(['daily_microusd','monthly_microusd','daily_requests'] as $limit){
    $u=$freshOwner($limit);$upgrade($u);$authorize($u);$execute($u,'lead_one');
    $u->policy[$limit]=$limit==='daily_requests'?2:59999;$authorize($u);brokerReject(fn()=>$execute($u),'Configured budget bypassed');
    brokerCheck((int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_jobs')->fetchColumn()===1,'Refused admission rolls back permit/job atomically');
}
$u=$freshOwner('attempt-cap');$upgrade($u);$authorize($u);$u->authorization['max_microusd']=29999;brokerReject(fn()=>$execute($u),'Per-attempt cap bypassed');
$u=$freshOwner('concurrent');$upgrade($u);$authorize($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];
$policyFile=$dir.'/owner-race-policy.json';$jobFile=$dir.'/owner-race-job.json';file_put_contents($policyFile,json_encode($u->policy));file_put_contents($jobFile,json_encode($u->authorization));chmod($policyFile,0600);chmod($jobFile,0600);
$workers=[];for($n=0;$n<2;$n++){$process=proc_open([PHP_BINARY,__DIR__.'/gazette-owner-worker.php',$path,$policyFile,$jobFile],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$process,$pipes];}
$admitted=0;foreach($workers as [$process,$pipes]){$result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);brokerCheck(proc_close($process)===0&&$error==='','Concurrent owner admission has no fatal errors');$admitted+=(int)json_decode($result,true,16,JSON_THROW_ON_ERROR)['admitted'];}
brokerCheck($admitted===1&&(int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_jobs')->fetchColumn()===1&&(int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_requests')->fetchColumn()===1&&(int)$u->db->query('SELECT SUM(request_slots) FROM gazette_editor_attempts')->fetchColumn()===2,'Concurrent identical owner authorization commits exactly one dispatch and full hold');
$u=$freshOwner('unused-permit');$execute($u,'lead_one');$upgrade($u);
LittleSparks\permitEditorialRevision($u->db,'2026-10-29',2,str_repeat('0',64),str_repeat('1',64),30000,$u->now+60,$u->now);
$authorize($u);[$skipped]=$execute($u);brokerCheck($skipped['status']==='awaiting_owner_review'&&(int)$u->db->query('SELECT MAX(ordinal) FROM gazette_editor_attempts')->fetchColumn()===3,'Owner command skips unused historical permit ordinals without deleting them');
$u=$freshOwner('published-history');[$published]=$execute($u);LittleSparks\reviewEditorialDraft($u->db,$published['attempt_id'],$published['draft_sha256'],'approved',$u->now);
$path=$u->db->query('PRAGMA database_list')->fetch()['file'];$day=LittleSparks\gazetteDay('2026-10-29',$u->now);
$manifest=LittleSparks\publishEditorialDraft($u->read,$path,$published['attempt_id'],$published['draft_sha256'],null,$u->now);
$paper=LittleSparks\readEditorialGazette($u->read,$path,$day);$upgrade($u);
brokerCheck(LittleSparks\readEditorialGazette($u->read,$path,$day)===$paper&&LittleSparks\editorialManifests($path,$day)[0]===$manifest,'Upgrade retains approved moderated drafts and byte-exact historical publication bindings');
$u=$freshOwner('preview');$upgrade($u);$u->now=strtotime('2026-10-29T23:00:00Z');$today='2026-10-29';$authorize($u,true,$today);
// Domain clock fixture: exercise the exact three bound broker operations without
// rewriting immutable history or changing the client's real wall-clock guard.
$base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($u->policy,90000,$release)];
$p=$u->call($base+['op'=>'begin_generation']);$mock=new BrokerProvider('good',$u->db);
foreach(['generation'=>'responses','moderation'=>'moderations'] as $stage=>$endpoint){
    $wire=$mock->post($endpoint,$p['body'],$p['deadline']);$p=$u->call($base+['op'=>'complete_'.$stage,'attempt_id'=>$p['attempt_id'],'request_id'=>$p['request_id'],'capability'=>$p['capability'],'wire'=>$wire]);
}
brokerCheck($p['status']==='awaiting_owner_review','Today preview generated and moderated');
$q=$u->db->prepare('SELECT snapshot_json FROM gazette_editor_attempts WHERE id=?');$q->execute([$p['attempt_id']]);$snapshot=json_decode($q->fetchColumn(),true);
brokerCheck($snapshot['pack']['preview']['partial']===true&&$snapshot['pack']['window']['end']===gmdate('Y-m-d\TH:i:s\Z',$u->now),'Preview freezes honest as-of coverage');
LittleSparks\reviewEditorialDraft($u->db,$p['attempt_id'],$p['draft_sha256'],'approved',$u->now);
require_once __DIR__.'/../app/gazette_editor_publication.php';
$path=$u->db->query('PRAGMA database_list')->fetch()['file'];brokerReject(fn()=>LittleSparks\publishEditorialDraft($u->db,$path,$p['attempt_id'],$p['draft_sha256'],null,$u->now+86400),'Preview promoted silently into final edition');
$u->now+=86400;$authorize($u,false,$today);
$base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($u->policy,90000,$release)];
$final=$u->call($base+['op'=>'begin_generation']);brokerCheck($final['status']==='dispatch_generation','Final edition admission independent of previous partial preview');
brokerCheck($u->db->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&!$u->db->query('PRAGMA foreign_key_check')->fetch(),'Upgrade and owner workflow preserve integrity');
echo "PASS owner workflow upgrade/history/rollback, automatic authorized rejected revisions beyond eight, consumed jobs, uncertain fencing, budgets/attempt caps and separately moderated partial previews (offline)\n";
