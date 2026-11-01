<?php
declare(strict_types=1);
// Synthetic domain data and mock transport only. Included by owner/core fixtures.
$autoPolicy=['version'=>1,'mode'=>'initial_only','enabled_at'=>0];
$u=$freshOwner('automatic');$upgrade($u);$u->autoPolicy=$autoPolicy;$authorize($u);
[$automatic,$transport]=$execute($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];$day=LittleSparks\gazetteDay('2026-10-29',$u->now);
brokerCheck($automatic['status']==='published_automatically'&&$transport->calls===['responses','moderations'],'Automatic edition is generated, moderated and published without another request or login');
$paper=LittleSparks\readEditorialGazette($u->read,$path,$day);
brokerCheck($paper['publication_authority']==='automatic_policy'&&$paper['revision_count']===1,'Automatic public edition has truthful separate authority');
brokerCheck((int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_reviews')->fetchColumn()===0&&(int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_auto_publications')->fetchColumn()===1,'Automatic publication never fabricates human approval');
LittleSparks\autoPublishEditorialDraft($u->db,$u->read,$path,$automatic['attempt_id'],$automatic['draft_sha256'],$autoPolicy,$release,$u->now);
brokerCheck(LittleSparks\readEditorialGazette($u->read,$path,$day)===$paper,'Publication-only resumption is idempotent and does not rewrite history');
$authorize($u);[$revision]=$execute($u);
brokerCheck($revision['status']==='existing_publication_preserved'&&LittleSparks\readEditorialGazette($u->read,$path,$day)===$paper,'Automatic policy cannot silently replace a finalized edition');
brokerCheck((int)$u->db->query('SELECT SUM(reserved_microusd) FROM gazette_editor_attempts')->fetchColumn()===60000&&(int)$u->db->query('SELECT SUM(request_slots) FROM gazette_editor_attempts')->fetchColumn()===4,'Automatic publication leaves full cost and request holds charged');
brokerReject(fn()=>$u->db->exec("UPDATE gazette_editor_auto_publications SET authorized_at=0"),'Automatic authority mutable');
foreach(['flagged','lead_one','partial','timeout'] as $mode){
    $u=$freshOwner('automatic-'.$mode);$upgrade($u);$u->autoPolicy=$autoPolicy;$authorize($u);
    if($mode==='timeout')brokerReject(fn()=>$execute($u,$mode),'Unknown request must propagate');else $execute($u,$mode);
    brokerCheck((int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_auto_publications')->fetchColumn()===0,'Rejected/flagged/unknown work has no automatic publication authority');
    $path=$u->db->query('PRAGMA database_list')->fetch()['file'];brokerCheck(LittleSparks\editorialManifests($path,LittleSparks\gazetteDay('2026-10-29',$u->now))===[],'Rejected or uncertain work remains unpublished');
}
$u=$freshOwner('automatic-old');$upgrade($u);$authorize($u);[$old]=$execute($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];
$later=$autoPolicy;$later['enabled_at']=$u->now+1;
brokerReject(fn()=>LittleSparks\autoPublishEditorialDraft($u->db,$u->read,$path,$old['attempt_id'],$old['draft_sha256'],$later,$release,$u->now+2),'Old drafts published retroactively');
LittleSparks\reviewEditorialDraft($u->db,$old['attempt_id'],$old['draft_sha256'],'rejected',$u->now);
brokerReject(fn()=>LittleSparks\autoPublishEditorialDraft($u->db,$u->read,$path,$old['attempt_id'],$old['draft_sha256'],$autoPolicy,$release,$u->now),'Explicit owner rejection bypassed');
brokerCheck((int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_auto_publications')->fetchColumn()===0,'Refused automatic authorization rolls back atomically');
$u=$freshOwner('automatic-preview');$upgrade($u);$u->autoPolicy=$autoPolicy;$u->now=strtotime('2026-10-29T23:00:00Z');$authorize($u,true,'2026-10-29');
$base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($u->policy,90000,$release)];
$p=$u->call($base+['op'=>'begin_generation']);$mock=new BrokerProvider('good',$u->db);
foreach(['generation'=>'responses','moderation'=>'moderations'] as $stage=>$endpoint){$wire=$mock->post($endpoint,$p['body'],$p['deadline']);$p=$u->call($base+['op'=>'complete_'.$stage,'attempt_id'=>$p['attempt_id'],'request_id'=>$p['request_id'],'capability'=>$p['capability'],'wire'=>$wire]);}
brokerCheck($p['status']==='preview_private'&&(int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_auto_publications')->fetchColumn()===0,'Partial today previews remain private under automatic daily publication');
$u=$freshOwner('automatic-forgery');$upgrade($u);$authorize($u);
$base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($u->policy,90000,$release)];
brokerReject(fn()=>$u->call($base+['op'=>'begin_generation','auto_publish'=>$autoPolicy]),'Socket client enabled publication');
brokerCheck((int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_attempts')->fetchColumn()===0,'Client publication policy injection cannot dispatch');
$u=$freshOwner('automatic-pending');$upgrade($u);$u->autoPolicy=$autoPolicy;$authorize($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];
mkdir(LittleSparks\gazetteDirectory($path),0700);$block=LittleSparks\editorialPublicationDirectory($path,'2026-10-29');file_put_contents($block,'synthetic blocked directory');chmod($block,0600);
[$pending,$provider]=$execute($u);
brokerCheck($pending['status']==='publication_pending'&&$u->db->query('SELECT outcome FROM gazette_editor_outcomes')->fetchColumn()==='finished','Publication failure preserves a known-completed generation');
unlink($block);LittleSparks\autoPublishEditorialDraft($u->db,$u->read,$path,$pending['attempt_id'],$pending['draft_sha256'],$autoPolicy,$release,$u->now);
brokerCheck(count($provider->calls)===2&&(int)$u->db->query('SELECT COUNT(*) FROM gazette_editor_requests')->fetchColumn()===2&&(int)$u->db->query('SELECT SUM(reserved_microusd) FROM gazette_editor_attempts')->fetchColumn()===30000,'Publication-only recovery makes no provider calls or accounting adjustment');
$u=$freshOwner('automatic-withdrawn');$upgrade($u);$authorize($u);[$draft]=$execute($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];
$u->db->exec("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
brokerReject(fn()=>LittleSparks\autoPublishEditorialDraft($u->db,$u->read,$path,$draft['attempt_id'],$draft['draft_sha256'],$autoPolicy,$release,$u->now),'Withdrawn source automatically published');
$u=$freshOwner('automatic-audit');$upgrade($u);$u->autoPolicy=$autoPolicy;$authorize($u);[$draft]=$execute($u);$path=$u->db->query('PRAGMA database_list')->fetch()['file'];
$u->db->exec('DROP TRIGGER gazette_editor_auto_publications_no_update');$u->db->exec("UPDATE gazette_editor_auto_publications SET policy_sha256='".str_repeat('0',64)."'");
brokerReject(fn()=>LittleSparks\readEditorialGazette($u->read,$path,LittleSparks\gazetteDay('2026-10-29',$u->now)),'Corrupt automatic authority published');
echo "PASS default-off automatic publication after mandatory moderation, separate immutable authority, no human approval fiction, idempotent publication, unchanged holds, preview/finalized/history and unknown-request guards (mock only)\n";
