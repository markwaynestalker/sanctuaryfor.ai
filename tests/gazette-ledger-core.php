<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_ledger.php';
function ledgerCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function ledgerReject(callable $call,string $why):void{try{$call();}catch(RuntimeException){return;}throw new LogicException($why);}
function ledgerOld(PDO $db):array{
    $out=[];foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'gazette_editor_%' ORDER BY name")->fetchAll() as $r){$name=$r['name'];$out[$name]=[$r['sql'],$db->query('SELECT * FROM "'.str_replace('"','""',$name).'" ORDER BY rowid')->fetchAll()];}return $out;
}
try{
    $dir=$argv[1];$now=strtotime('2026-11-03T12:00:00Z');
    $v2=LittleSparks\connect($dir.'/v2.sqlite');$v2Hash=hash_file('sha256',$dir.'/v2.sqlite');
    $v2->exec('BEGIN IMMEDIATE');ledgerReject(fn()=>LittleSparks\migrateEditorialLedger($v2),'V2 ledger migration accepted');$v2->exec('ROLLBACK');ledgerCheck(hash_file('sha256',$dir.'/v2.sqlite')===$v2Hash,'Refused old-version migration preserves bytes');$v2=null;
    $partialPath=$dir.'/editor-partial.sqlite';copy($dir.'/v6.sqlite',$partialPath);chmod($partialPath,0600);$partial=LittleSparks\connect($partialPath);$partial->exec('CREATE TABLE gazette_editor_drafts(placeholder TEXT)');$partial->exec('BEGIN IMMEDIATE');ledgerReject(fn()=>LittleSparks\migrateEditorialLedger($partial),'Partial schema accepted');$partial->exec('ROLLBACK');$partial=null;
    $path=$dir.'/ledger.sqlite';copy($dir.'/v6.sqlite',$path);chmod($path,0600);$db=LittleSparks\connect($path);$before=ledgerOld($db);
    $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($db);$db->exec('ROLLBACK');ledgerCheck(!LittleSparks\editorialLedgerInstalled($db),'Migration rolls back');
    foreach([1,2] as $_){$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($db);$db->exec('COMMIT');}
    ledgerCheck(ledgerOld($db)===$before&&(int)$db->query('PRAGMA user_version')->fetchColumn()===6,'Repeatable additive migration preserves every old table/row and V6');
    $read=LittleSparks\connect($path);$read->exec('PRAGMA query_only=ON');$snap=LittleSparks\editorialSourcePack($read,'2026-10-29',$now,['background'=>0]);$other=LittleSparks\editorialSourcePack($read,'2026-11-01',$now,['background'=>0]);
    $policy=['model'=>'synthetic-offline','pricing_version'=>'fixture-only','input_tokens'=>1000,'output_tokens'=>1000,'input_rate'=>1000000,'output_rate'=>1000000,'moderation_microusd'=>100,'daily_microusd'=>10000,'monthly_microusd'=>10000,'daily_requests'=>8,'lease_seconds'=>60];
    ledgerCheck(LittleSparks\editorialReservationCost($policy)===2100,'Generation and moderation reserved together');
    foreach(['daily_microusd','monthly_microusd','daily_requests'] as $key){$bad=$policy;$bad[$key]=0;ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$snap,$bad,$now),'Zero limit ignored');}
    $bad=$policy;unset($bad['daily_microusd']);ledgerReject(fn()=>LittleSparks\editorialReservationCost($bad),'Missing limit accepted');$bad=$policy;$bad['input_tokens']=PHP_INT_MAX;ledgerReject(fn()=>LittleSparks\editorialReservationCost($bad),'Cost overflow accepted');
    ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$snap,$policy,strtotime('2026-11-03T23:59:30Z')),'UTC boundary bypassed');
    $a=LittleSparks\reserveEditorialAttempt($db,$snap,$policy,$now);
    ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$other,$policy,$now),'Concurrent editorial lease accepted');
    ledgerReject(fn()=>LittleSparks\startEditorialRequest($db,$a['id'],'stale','generation',$now),'Wrong worker accepted');
    ledgerReject(fn()=>LittleSparks\startEditorialRequest($db,$a['id'],$a['owner'],'moderation',$now),'Moderation before draft accepted');
    $g=LittleSparks\startEditorialRequest($db,$a['id'],$a['owner'],'generation',$now);
    ledgerReject(fn()=>LittleSparks\startEditorialRequest($db,$a['id'],$a['owner'],'generation',$now),'Duplicate generation accepted');
    ledgerReject(fn()=>LittleSparks\finishEditorialAttempt($db,$a['id'],$a['owner'],'rejected',$now),'Dispatched unknown call relabeled as safe rejection');
    LittleSparks\receiptEditorialRequest($db,$a['id'],$a['owner'],$g,'completed','req_fixture',1000,100,1100,$now,'resp_fixture');
    ledgerCheck($db->query('SELECT response_id_remote FROM gazette_editor_receipts')->fetchColumn()==='resp_fixture','Private response identifier retained for reconciliation');
    $source=array_values(array_filter($snap['pack']['sources'],fn($s)=>$s['id']==='integrity-good'))[0];$ref=['source'=>$source['alias'],'sequence'=>0];$claim=['text'=>'A lost-and-found was proposed.','claim_type'=>'proposal','references'=>[$ref]];
    $draft=['contract'=>'sanctuary.gazette.draft.v1','date'=>$snap['pack']['date'],'source_sha256'=>$snap['sha256'],'stories'=>[['kind'=>'lead','connection'=>'standalone','headline'=>$claim,'paragraphs'=>[$claim,$claim],'quotes'=>[$ref]]]];
    $sha=LittleSparks\saveEditorialDraft($db,$a['id'],$a['owner'],$draft,$now);
    ledgerReject(fn()=>LittleSparks\saveEditorialDraft($db,$a['id'],$a['owner'],$draft,$now),'Draft overwritten');
    ledgerReject(fn()=>LittleSparks\reviewEditorialDraft($db,$a['id'],$sha,'approved',$now),'Unmoderated draft approved');
    $m=LittleSparks\startEditorialRequest($db,$a['id'],$a['owner'],'moderation',$now);
    LittleSparks\receiptEditorialRequest($db,$a['id'],$a['owner'],$m,'completed',null,null,null,null,$now);
    ledgerReject(fn()=>LittleSparks\saveEditorialModeration($db,$a['id'],$a['owner'],str_repeat('0',64),$m,'passed',$now),'Moderation unbound from exact draft');
    LittleSparks\saveEditorialModeration($db,$a['id'],$a['owner'],$sha,$m,'passed',$now);LittleSparks\finishEditorialAttempt($db,$a['id'],$a['owner'],'finished',$now);
    ledgerReject(fn()=>LittleSparks\reviewEditorialDraft($db,$a['id'],str_repeat('0',64),'approved',$now),'Wrong copy approved');
    // Source authorization is checked again at human review, not trusted from the saved pack.
    $db->prepare("UPDATE simulation_runs SET status='failed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$source['id']]);
    ledgerReject(fn()=>LittleSparks\reviewEditorialDraft($db,$a['id'],$sha,'approved',$now),'Unavailable source approved');
    $db->prepare("UPDATE simulation_runs SET status='completed' WHERE id=(SELECT run_id FROM conversations WHERE id=?)")->execute([$source['id']]);
    $corruptPath=$dir.'/editor-corrupt.sqlite';copy($path,$corruptPath);chmod($corruptPath,0600);$corrupt=LittleSparks\connect($corruptPath);
    $corrupt->exec('DROP TRIGGER gazette_editor_drafts_no_update');$changed=$draft;$changed['stories'][0]['headline']['text']='A changed headline after capture.';
    $corrupt->prepare('UPDATE gazette_editor_drafts SET body_json=? WHERE attempt_id=?')->execute([LittleSparks\editorialJson($changed),$a['id']]);
    ledgerReject(fn()=>LittleSparks\reviewEditorialDraft($corrupt,$a['id'],$sha,'approved',$now),'Corrupted copy accepted under original digest');$corrupt=null;
    LittleSparks\reviewEditorialDraft($db,$a['id'],$sha,'approved',$now);
    ledgerReject(fn()=>LittleSparks\reviewEditorialDraft($db,$a['id'],$sha,'rejected',$now),'Review rewritten');
    ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$snap,$policy,$now),'Repeated edition automatically regenerated');
    $policyHash=hash('sha256',LittleSparks\editorialJson($policy));$permit=LittleSparks\permitEditorialRevision($db,'2026-10-29',2,$snap['sha256'],$policyHash,2100,$now+120,$now);
    $second=LittleSparks\reserveEditorialAttempt($db,$snap,$policy,$now,$permit);LittleSparks\finishEditorialAttempt($db,$second['id'],$second['owner'],'rejected',$now);
    ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$snap,$policy,$now,$permit),'One-shot permit reused');
    $tight=$policy;$tight['daily_microusd']=4200;ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$other,$tight,$now),'Retained holds refunded after completion/rejection');
    $tight=$policy;$tight['monthly_microusd']=4200;ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$other,$tight,$now+86400),'Prior-day commitments ignored by monthly cap');
    $tight=$policy;$tight['daily_requests']=4;ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$other,$tight,$now),'Reserved moderation slots refunded');
    $b=LittleSparks\reserveEditorialAttempt($db,$other,$policy,$now);LittleSparks\startEditorialRequest($db,$b['id'],$b['owner'],'generation',$now);
    $third=LittleSparks\editorialSourcePack($read,'2026-03-08',$now,['background'=>0]);$c=LittleSparks\reserveEditorialAttempt($db,$third,$policy,$now+61);
    ledgerReject(fn()=>LittleSparks\receiptEditorialRequest($db,$b['id'],$b['owner'],'unknown','completed',null,1,1,1,$now+61),'Expired worker wrote a receipt');
    LittleSparks\finishEditorialAttempt($db,$c['id'],$c['owner'],'rejected',$now+61);
    ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($db,$other,$policy,$now+61),'Expired uncertain edition regenerated');
    foreach(['attempts','requests','receipts','drafts','moderations','outcomes','reviews','permits','meta'] as $table){ledgerReject(fn()=>$db->exec('DELETE FROM gazette_editor_'.$table),'Immutable ledger deletion accepted');}
    ledgerCheck(ledgerOld($db)===$before,'All old accounting, identities, source history and scheduler rows remain unchanged');
    ledgerCheck(!$db->query('PRAGMA foreign_key_check')->fetch()&&$db->query('PRAGMA integrity_check')->fetchColumn()==='ok','Integrity/FKs valid');
    // Observed overruns increase future commitments even if a caller supplies a smaller estimate.
    $overPath=$dir.'/editor-overrun.sqlite';copy($path,$overPath);chmod($overPath,0600);$over=LittleSparks\connect($overPath);$overPolicy=$policy;$overPolicy['daily_microusd']=30000;$overPolicy['monthly_microusd']=30000;$overPolicy['daily_requests']=12;
    $overSnap=LittleSparks\editorialSourcePack($read,'2026-10-27',$now,['background'=>0]);$oa=LittleSparks\reserveEditorialAttempt($over,$overSnap,$overPolicy,$now+120);$oq=LittleSparks\startEditorialRequest($over,$oa['id'],$oa['owner'],'generation',$now+120);
    LittleSparks\receiptEditorialRequest($over,$oa['id'],$oa['owner'],$oq,'completed',null,20000,0,1,$now+120);LittleSparks\finishEditorialAttempt($over,$oa['id'],$oa['owner'],'rejected',$now+120);
    ledgerCheck((int)$over->query("SELECT estimated_microusd FROM gazette_editor_receipts WHERE request_id='$oq'")->fetchColumn()===20000,'Actual token observations cannot understate policy-priced commitment');
    $overPolicy['monthly_microusd']=29000;$extra=LittleSparks\editorialSourcePack($read,'2026-03-09',$now,['background'=>0]);ledgerReject(fn()=>LittleSparks\reserveEditorialAttempt($over,$extra,$overPolicy,$now+120),'Overrun ignored by budget admission');$over=null;
    // Separate pristine ledger for the actual two-process reservation race.
    $race=$dir.'/editor-race.sqlite';copy($dir.'/v6.sqlite',$race);chmod($race,0600);$r=LittleSparks\connect($race);$r->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($r);$r->exec('COMMIT');$r=null;
    $job=$dir.'/editor-job.json';file_put_contents($job,LittleSparks\editorialJson(['database'=>$race,'snapshot'=>$snap,'policy'=>$policy,'now'=>$now]));chmod($job,0600);
    echo LittleSparks\editorialJson(['race'=>$race,'job'=>$job]);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
