<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_publication.php';
try{
    if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0)throw new RuntimeException();
    $o=getopt('',['database:','date:','job:']);$path=LittleSparks\databasePath($o['database']??'');
    $uid=fileowner($path);$gid=filegroup($path);$account=posix_getpwuid($uid);
    if(!$uid||!$account||!posix_initgroups($account['name'],$gid)||!posix_setgid($gid)||!posix_setuid($uid))throw new RuntimeException();
    $db=LittleSparks\connect($path);$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    $revision=(int)$db->query('SELECT revision FROM gazette_editor_meta')->fetchColumn();
    $preview=$revision===2?'a.is_preview':'0';
    $where='';$params=[];
    if(isset($o['date'])){$where=' WHERE a.edition=?';$params[]=$o['date'];}
    elseif(isset($o['job'])){$where=' WHERE a.id=(SELECT attempt_id FROM gazette_editor_jobs WHERE id=?)';$params[]=$o['job'];}
    $q=$db->prepare("SELECT a.id AS attempt_id,a.edition,a.ordinal,$preview AS preview,a.day,a.month,a.reserved_microusd,a.request_slots,o.outcome,v.decision,d.sha256 AS draft_sha256,
        (SELECT SUM(r.input_tokens) FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id) AS observed_input_tokens,
        (SELECT SUM(r.output_tokens) FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id) AS observed_output_tokens,
        (SELECT SUM(r.estimated_microusd) FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id) AS observed_estimated_microusd
        FROM gazette_editor_attempts a LEFT JOIN gazette_editor_outcomes o ON o.attempt_id=a.id LEFT JOIN gazette_editor_reviews v ON v.attempt_id=a.id LEFT JOIN gazette_editor_drafts d ON d.attempt_id=a.id $where ORDER BY a.created_at DESC,a.ordinal DESC LIMIT 100");$q->execute($params);$rows=$q->fetchAll();
    $cost="MAX(a.reserved_microusd,COALESCE((SELECT SUM(r.estimated_microusd) FROM gazette_editor_requests q JOIN gazette_editor_receipts r ON r.request_id=q.id WHERE q.attempt_id=a.id),0))";
    foreach($rows as &$row){$row['preview']=(bool)$row['preview'];$row['coverage_label']=$row['preview']?'In-progress preview — partial coverage, not a completed daily edition':'Completed-date edition candidate; publication requires separate approval';}unset($row);
    $budgets=[];foreach(['day'=>gmdate('Y-m-d'),'month'=>gmdate('Y-m')] as $field=>$window){
        $q=$db->prepare("SELECT COALESCE(SUM($cost),0) AS committed_microusd,COALESCE(SUM(request_slots),0) AS reserved_requests FROM gazette_editor_attempts a WHERE $field=?");$q->execute([$window]);$budgets[$field]=['window'=>$window]+$q->fetch();
    }
    echo json_encode(['ledger_revision'=>$revision,'budgets'=>$budgets,'attempts'=>$rows],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Gazette metadata unavailable. No requests made.\n");exit(1);}
