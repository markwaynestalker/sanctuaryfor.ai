<?php
declare(strict_types=1);
// Included by reconciliation-core: all databases and transports are disposable.
foreach(['DELETE','WAL'] as $journal){
    [$lockDb,$lockId]=$uncertain();$lockPath=$lockDb->query('PRAGMA database_list')->fetch()['file'];
    check(strtoupper((string)$lockDb->query('PRAGMA journal_mode='.$journal)->fetchColumn())===$journal,'Explicit contention journal mode');
    $peer=LittleSparks\connect($lockPath);$peer->exec('PRAGMA busy_timeout=50');
    $snapshot=LittleSparks\directorAttemptInspection($lockDb,$lockId);
    $interleaved=new ReconciliationMock($snapshot,'good',static function(int $call)use($peer):void{
        // A real competing writer commits while inspection is outside its short
        // reservation transaction. An unclosed scalar cursor prevents DELETE
        // commit, or leaves a stale WAL snapshot on the inspecting connection.
        $peer->exec('BEGIN IMMEDIATE');
        try{$peer->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['contention_peer:'.$call,'committed']);$peer->exec('COMMIT');}
        catch(Throwable $e){$peer->exec('ROLLBACK');throw $e;}
    });
    $inspected=LittleSparks\inspectDirectorProvider($lockDb,$lockId,str_repeat('b',32),$interleaved);
    check($inspected['ready_for_billing_review']&&count($interleaved->calls)===3,'No read cursor or transaction blocks a competing writer between metadata GETs: '.$journal);
    check(LittleSparks\directorAttemptInspection($lockDb,$lockId)===$snapshot&&LittleSparks\directorAccessReservedRequests($lockDb,gmdate('Y-m-d'))===3,'Interleaving retains original uncertain holds and all three inspection slots');
    $peer=null;$lockDb=null;

    foreach([1,2] as $begin)foreach([false,true] as $refuse){
        [$lockDb,$lockId]=$uncertain();$lockPath=$lockDb->query('PRAGMA database_list')->fetch()['file'];
        $lockDb->query('PRAGMA journal_mode='.$journal)->fetchAll();
        $snapshot=LittleSparks\directorAttemptInspection($lockDb,$lockId);$oldWorld=$world($lockDb);$inspection=str_repeat('c',32);
        $process=proc_open([PHP_BINARY,__DIR__.'/director-reconciliation-worker.php',$lockPath,$lockId,$inspection,(string)$begin],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        check(is_resource($process),'Contention worker starts');$held=false;
        try{
            stream_set_timeout($pipes[1],10);$barrier=json_decode((string)fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR);
            check($barrier===['phase'=>'before_begin','number'=>$begin],'Worker reached requested real BEGIN barrier');
            $lockDb->exec('BEGIN IMMEDIATE');$held=true;
            $lockDb->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute(['contention_lock','held']);
            // For acquisition, release before allowing BEGIN. For refusal keep
            // the real writer lock until the child returns its bounded result.
            // Pipes establish ordering; no scheduler timing/sleep assumption.
            if(!$refuse){$lockDb->exec('COMMIT');$held=false;}
            fwrite($pipes[0],"continue\n");fclose($pipes[0]);
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            check(proc_close($process)===0&&$errors==='','Contention has no uncaught PHP fatal: '.$errors);$process=null;
            $result=json_decode($output,true,16,JSON_THROW_ON_ERROR);
        }finally{
            if($held)$lockDb->exec('ROLLBACK');
            if(is_resource($process)){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
        }
        check($result['accepted']===!$refuse&&$result['calls']===($refuse&&$begin===1?0:3),'Expected acquisition/refusal and exact GET count: '.$journal.' begin '.$begin);
        if($refuse)check($result['reason']==='simulation_busy','Native busy lock refusal is a safe guard, not provider response corruption');
        $reserved=$refuse&&$begin===1?0:3;
        check(LittleSparks\directorAccessReservedRequests($lockDb,gmdate('Y-m-d'))===$reserved,'Only committed admissions consume three daily slots');
        check(LittleSparks\directorAttemptInspection($lockDb,$lockId)===$snapshot&&$world($lockDb)===$oldWorld,'Lock result never alters uncertain/rejected accounting, original HTTP audit, or world');
        $report=LittleSparks\reconciliationValue($lockDb,'director_inspection:'.$inspection.':report');
        check(($report!==null)===!$refuse,'Only a committed report can authorize subsequent separate review');
        if($reserved===3){
            $retry=new ReconciliationMock($snapshot);
            try{LittleSparks\inspectDirectorProvider($lockDb,$lockId,$inspection,$retry);throw new LogicException('Reserved inspection replayed');}
            catch(LittleSparks\AiGuard $e){check($e->reason==='request_already_started'&&$retry->calls===[],'Final-lock refusal retains consumed ID without replaying metadata requests');}
            check(LittleSparks\directorAccessReservedRequests($lockDb,gmdate('Y-m-d'))===3,'A retry attempt cannot refund or add slots');
        }
        $lockDb=null;
    }
}
echo "PASS deterministic DELETE/WAL reconciliation cursor release, both BEGIN acquisition/refusal barriers, safe busy classification, exact GET counts, retained uncertain holds/slots and no replay\n";
