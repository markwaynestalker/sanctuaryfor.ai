<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/commons.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function install(string $path,int $count):void{
    foreach(['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php'] as $script){$p=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script,'--database='.$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($p),'CLI starts');stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($p)===0,$err);}
    $db=LittleSparks\connect($path);for($i=0;$i<$count;$i++)LittleSparks\create($db,['name'=>'Neighbor '.$i,'color'=>'mint','antenna'=>'orb','personality'=>'curious','website'=>'','requestId'=>str_pad((string)$i,32,'0',STR_PAD_LEFT)],'limit-browser-'.$i,'limit-network-'.$i,time(),true);
}
function applyClaim(PDO $db,array $claim,int $now,string $outcome='conversation'):array{
    $hasPair=count($claim['pair'])===2;$p=['outcome'=>$hasPair?$outcome:'none','topic'=>'quiet','first'=>$hasPair?'I feel curious.':'','second'=>$hasPair?'I feel peaceful.':'','world_action'=>'none'];
    return LittleSparks\applyActivity($db,$claim,$p,['none'=>null],$now);
}
try{
    $dir=$argv[1]??'';check(is_dir($dir)&&str_starts_with(basename($dir),'little-sparks-limits-test-'),'Disposable fixtures only');
    putenv('LITTLE_SPARKS_ACTIVITY_CANDIDATES');putenv('LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX');
    $keys=['LITTLE_SPARKS_ACTIVITY_DAILY_MAX','LITTLE_SPARKS_RESIDENT_DAILY_MAX','LITTLE_SPARKS_RESIDENT_CONVERSATION_COOLDOWN_SECONDS','LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS','LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS'];foreach($keys as $key)putenv($key);
    check(LittleSparks\activityConfiguration()===['daily_opportunities'=>100,'resident_daily'=>12,'candidates'=>64,'resident_cooldown'=>0,'pair_cooldown'=>0]&&LittleSparks\replayCooldownSeconds()===60,'Existing defaults preserved');
    $bounds=[PHP_INT_MAX,PHP_INT_MAX,86400,604800,3600];
    foreach($keys as $i=>$key){
        foreach(['0',(string)$bounds[$i]] as $value){putenv($key.'='.$value);$cfg=$i===4?LittleSparks\replayCooldownSeconds():LittleSparks\activityConfiguration();check($cfg!==null,'Boundary accepted for '.$key);}
        foreach(['','-1','+1','1.5','1e3',' 2','2 ','01','999999999999999999999','unlimited',($bounds[$i]===PHP_INT_MAX?(string)PHP_INT_MAX.'0':(string)($bounds[$i]+1))] as $value){putenv($key.'='.$value);try{$i===4?LittleSparks\replayCooldownSeconds():LittleSparks\activityConfiguration();throw new LogicException('Invalid limit accepted');}catch(LittleSparks\Problem $e){check($e->status===503,'Invalid configuration fails closed');}}
        putenv($key);
    }
    foreach(['LITTLE_SPARKS_ACTIVITY_CANDIDATES'=>[2,10000],'LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX'=>[0,PHP_INT_MAX]] as $key=>[$min,$max]){
        foreach([$min,$max] as $v){putenv($key.'='.$v);check(($key==='LITTLE_SPARKS_ACTIVITY_CANDIDATES'?LittleSparks\activityConfiguration()['candidates']:LittleSparks\residentFriendshipLimit())===$v,'Candidate/friendship ENV boundary accepted');}
        putenv($key.'=-1');try{$key==='LITTLE_SPARKS_ACTIVITY_CANDIDATES'?LittleSparks\activityConfiguration():LittleSparks\residentFriendshipLimit();throw new LogicException('Invalid population bound accepted');}catch(LittleSparks\Problem){}putenv($key);
    }
    $m=LittleSparks\memoryConfiguration();check($m['candidates']===32&&$m['select']===4&&$m['recent_conversations']===2&&$m['shared_context']===4&&$m['legacy_context']===3,'Retrieval defaults preserved');
    foreach(['CANDIDATES'=>[1,64],'RECENT_CONVERSATIONS'=>[0,4],'SHARED_CONTEXT'=>[0,8],'LEGACY_CONTEXT'=>[0,4]] as $setting=>[$min,$max]){
        foreach([$min,$max] as $v){putenv('LITTLE_SPARKS_MEMORY_'.$setting.'='.$v);check(LittleSparks\memoryConfiguration()[strtolower($setting)]===$v,'Memory boundary accepted');}
        putenv('LITTLE_SPARKS_MEMORY_'.$setting.'='.($max+1));try{LittleSparks\memoryConfiguration();throw new LogicException('Oversized retrieval accepted');}catch(LittleSparks\Problem){}putenv('LITTLE_SPARKS_MEMORY_'.$setting);
    }
    install($dir.'/three.sqlite',3);install($dir.'/two.sqlite',2);$case=0;
    $fresh=function(string $template='three')use($dir,&$case):PDO{$path=$dir.'/case-'.(++$case).'.sqlite';copy($dir.'/'.$template.'.sqlite',$path);return LittleSparks\connect($path);};
    $now=time();$at=fn(int $i)=>gmdate('Y-m-d\TH:i:00\Z',$now-$i*60);
    $db=$fresh();putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX=unlimited');try{LittleSparks\claimActivity($db,$at(1),$now);throw new LogicException('Invalid ceiling permitted a claim');}catch(LittleSparks\Problem){}check((int)$db->query('SELECT COUNT(*) FROM simulation_opportunities')->fetchColumn()===0,'Invalid setting cannot reserve work');putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX');
    putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX=1');$db=$fresh();$first=LittleSparks\claimActivity($db,$at(1),$now);applyClaim($db,$first,$now);
    try{LittleSparks\claimActivity($db,$at(2),$now);throw new LogicException('Global ceiling ignored');}catch(LittleSparks\Problem $e){check($e->status===429,'Configured daily ceiling enforced');}
    putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX=2');$second=LittleSparks\claimActivity($db,$at(2),$now);applyClaim($db,$second,$now);check((int)$db->query('SELECT COUNT(*) FROM simulation_opportunities')->fetchColumn()===2,'Environment change permits more opportunities without code changes');
    putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX=0');check(isset(LittleSparks\claimActivity($db,$at(1),$now)['replay']),'Saved opportunity replay remains idempotent at a lowered ceiling');putenv('LITTLE_SPARKS_ACTIVITY_DAILY_MAX');
    putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX=1');$db=$fresh('two');$first=LittleSparks\claimActivity($db,$at(1),$now);applyClaim($db,$first,$now);$second=LittleSparks\claimActivity($db,$at(2),$now);check($second['pair']===[],'Resident ceiling excludes already-used residents');applyClaim($db,$second,$now);
    putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX=2');$third=LittleSparks\claimActivity($db,$at(3),$now);check(count($third['pair'])===2,'Changing resident limit takes effect without code changes');applyClaim($db,$third,$now);putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX');
    $db=$fresh();$first=LittleSparks\claimActivity($db,$at(1),$now);applyClaim($db,$first,$now);[$a,$b]=array_column($first['pair'],'id');
    putenv('LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX=0');check(LittleSparks\hasSocialRoom($db,$a,$b),'Lower friendship cap preserves existing pairs');$other=$db->query("SELECT id FROM residents WHERE id NOT IN ('$a','$b')")->fetchColumn();check(!LittleSparks\hasSocialRoom($db,$a,$other),'Zero configured friendship cap prevents a new pair');putenv('LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX=20');check(LittleSparks\hasSocialRoom($db,$a,$other),'ENV change allows a new friendship without code changes');putenv('LITTLE_SPARKS_RESIDENT_FRIENDSHIP_MAX');
    putenv('LITTLE_SPARKS_RESIDENT_CONVERSATION_COOLDOWN_SECONDS=60');check(!LittleSparks\conversationCooldownAllows($db,$a,$b,LittleSparks\activityConfiguration(),$now+59),'Resident cooldown active before boundary');check(LittleSparks\conversationCooldownAllows($db,$a,$b,LittleSparks\activityConfiguration(),$now+60),'Resident cooldown expires exactly at boundary');
    $cooling=LittleSparks\claimActivity($db,$at(2),$now);check(count($cooling['pair'])===1,'Cooling neighbors allow solitary activity, not a new conversation');applyClaim($db,$cooling,$now);putenv('LITTLE_SPARKS_RESIDENT_CONVERSATION_COOLDOWN_SECONDS');
    putenv('LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS=60');check(!LittleSparks\conversationCooldownAllows($db,$a,$b,LittleSparks\activityConfiguration(),$now+59)&&LittleSparks\conversationCooldownAllows($db,$b,$a,LittleSparks\activityConfiguration(),$now+60),'Pair cooldown is symmetric and boundary-safe');
    $newPair=LittleSparks\claimActivity($db,$at(3),$now);check(count($newPair['pair'])===2&&array_diff(array_column($newPair['pair'],'id'),[$a,$b]),'Pair cooldown allows a different neighbor');applyClaim($db,$newPair,$now);putenv('LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS');
    $db=$fresh();$claim=LittleSparks\claimActivity($db,$at(1),$now);putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX=0');try{applyClaim($db,$claim,$now);throw new LogicException('Apply bypassed a lowered limit');}catch(LittleSparks\Problem){}check((int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===0,'Apply transaction rechecks limits and rolls back');putenv('LITTLE_SPARKS_RESIDENT_DAILY_MAX');
    $db=$fresh('two');$claim=LittleSparks\claimActivity($db,$at(1),$now);applyClaim($db,$claim,$now);$claim=LittleSparks\claimActivity($db,$at(2),$now);putenv('LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS=3600');try{applyClaim($db,$claim,$now);throw new LogicException('Apply bypassed a changed pair cooldown');}catch(LittleSparks\Problem){}check((int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()===1,'Apply rechecks a cooldown enabled after claim');putenv('LITTLE_SPARKS_PAIR_CONVERSATION_COOLDOWN_SECONDS');
    putenv('LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS=15');$ids=$db->query('SELECT id FROM residents LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);check(LittleSparks\commons($db,$ids)['settings']['replay_cooldown_seconds']===15,'Public snapshot carries only validated replay setting');check(LittleSparks\activityConfiguration()['daily_opportunities']===100,'Replay frequency never changes opportunity limits');putenv('LITTLE_SPARKS_MEADOW_REPLAY_COOLDOWN_SECONDS');
    echo "PASS default/boundary/invalid activity and replay settings, environment-only changes, transactional rechecks, symmetric resident/pair cooldowns and retrieval bounds\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
