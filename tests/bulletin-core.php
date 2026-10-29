<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';require __DIR__.'/../app/bulletin_schema.php';require __DIR__.'/bulletin-test-support.php';
require __DIR__.'/../app/commons.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
final class BulletinCountingPdo extends PDO{
    public int $reads=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{
        $this->reads++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false{$this->reads++;return parent::prepare($query,$options);}
}
try{
    $db=LittleSparks\connect($argv[1]);$now=time();$at=gmdate('Y-m-d\TH:i:s\Z',$now-3600);$ids=[str_repeat('a',32),str_repeat('b',32)];
    foreach($ids as $i=>$id){$db->prepare("INSERT INTO residents VALUES (?,?,'mint','orb','curious',?,?,?,?,?)")->execute([$id,$i?'Fern <script>':'Maple & Sun',$at,hash('sha256','board-owner-'.$i),str_repeat((string)($i+1),32),str_repeat('d',64),hash('sha256','board-token-'.$i)]);LittleSparks\assignHome($db,$id,$at);LittleSparks\identityForNewResident($db,$id,'An independent fictional neighbor with a love of questions.','mint',$at);}
    $before=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t)$before[$t]=$db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll();
    check(!LittleSparks\bulletinEnabled()&&LittleSparks\publicBulletin($db,$now)['total']===0,'Default off and absent schema are safe');
    check(LittleSparks\commons($db,$ids)['bulletin_posts']===[],'Absent bulletin add-on projects no activity without migration');
    $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateBulletin($db);$db->exec('ROLLBACK');check(!LittleSparks\bulletinInstalled($db),'Transactional migration rollback');
    foreach([1,2] as $_){$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateBulletin($db);$db->exec('COMMIT');}
    foreach($before as $t=>$rows)check($db->query('SELECT * FROM '.$t.' ORDER BY rowid')->fetchAll()===$rows,'Preserve existing '.$t);
    check((int)$db->query('PRAGMA user_version')->fetchColumn()===6,'V6 unchanged');
    foreach(['LITTLE_SPARKS_BULLETIN_MAX_CHARACTERS'=>['0','1001','01','1.2'],'LITTLE_SPARKS_BULLETIN_DAILY_MAX'=>['-1','1e2',''],'LITTLE_SPARKS_BULLETIN_TTL_HOURS'=>['8761'],'LITTLE_SPARKS_BULLETIN_CONTEXT_COUNT'=>['13'],'LITTLE_SPARKS_BULLETIN_ENABLED'=>['2']] as $key=>$values){foreach($values as $value){putenv($key.'='.$value);try{str_ends_with($key,'ENABLED')?LittleSparks\bulletinEnabled():LittleSparks\bulletinConfiguration();throw new LogicException('Invalid ENV accepted');}catch(LittleSparks\Problem $e){check($e->status===503,'Invalid ENV fails safely');}}putenv($key);}
    putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=responses');$registered=LittleSparks\registerDirector($db,['id'=>'agent_bulletin_mock','model'=>'gpt-6-luna','instructions'=>'OFFLINE_OWNER_INSTRUCTIONS','tools'=>[],'multi_agent'=>['enabled'=>false],'service_tier'=>'default','reasoning'=>['effort'=>'none']]);
    foreach(['DIRECTOR_AGENT_ID'=>$registered['agent_id'],'DIRECTOR_CONFIG_SHA256'=>$registered['fingerprint'],'AI_ENABLED'=>'1','DIRECTOR_ENABLED'=>'1','SIMULATION_ENABLED'=>'1','AI_PRICING_CONFIRMED'=>'2026-10-24-standard-v1','AI_DAILY_REQUESTS'=>'200','AI_MONTHLY_MICROUSD'=>'1000000','BULLETIN_ENABLED'=>'1'] as $key=>$v)putenv('LITTLE_SPARKS_'.$key.'='.$v);
    $template=dirname($argv[1]).'/board-template.sqlite';copy($argv[1],$template);chmod($template,0600);$op=gmdate('Y-m-d\TH:i:00\Z',$now-60);
    foreach(['good','action','quiet','no_notice','wrong_author','markup','claim','impersonation','alias','flagged','quota_changed','legacy'] as $mode){
        $file=dirname($argv[1]).'/board-'.$mode.'.sqlite';copy($template,$file);chmod($file,0600);$fixture=LittleSparks\connect($file);$mock=new BulletinResponses($mode);
        $result=LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);$success=in_array($mode,['good','action','quiet','no_notice','legacy'],true);$posted=in_array($mode,['good','action'],true);
        check(($result['status']==='completed')===$success,'Truthful encounter outcome '.$mode.' '.json_encode($result).' '.json_encode($mock->calls));check((int)$fixture->query('SELECT COUNT(*) FROM bulletin_notices')->fetchColumn()===($posted?1:0),'Only accepted moderated notices persist '.$mode);
        check(count($mock->calls)<=2&&$mock->calls[0]==='responses','No additional request for notices');
        if($posted){check(count($mock->calls)===2&&($mock->moderated[1]['notice']['text']??null)===bulletinProposal($mock->context,$mode)['notice']['text'],'Exact notice covered by existing moderation');
            $view=LittleSparks\publicBulletin($fixture,$now);check($view['notices'][0]['body']===bulletinProposal($mock->context,$mode)['notice']['text'],'Exact original words preserved');
            check(!str_contains(json_encode($view),'run_id')&&!str_contains(json_encode($view),'session')&&!str_contains(json_encode($view),'token'),'Public projection excludes internal provenance and secrets');
            check(LittleSparks\publicBulletin($fixture,$now+169*3600)['total']===0&&LittleSparks\publicBulletin($fixture,$now+169*3600,1,true)['total']===1,'Expiration is derived without deletion');
            $contexts=LittleSparks\bulletinContext($fixture,$now);check(count($contexts)===1&&$contexts[0]['not_a_completed_activity']&&$contexts[0]['claims']==='unverified_author_expression','Notice context cannot become a verified fact');
            check(!LittleSparks\bulletinCanPost($fixture,$view['notices'][0]['resident_id'],$now),'Resident posting cooldown');
            putenv('LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS=0');check(!LittleSparks\bulletinCanPost($fixture,$view['notices'][0]['resident_id'],$now,$view['notices'][0]['body']),'Repeat notice suppression');putenv('LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS');
        }
        $holds=$fixture->query('SELECT * FROM ai_attempts')->fetchAll();$n=count($mock->calls);$replay=LittleSparks\simulateDirectorOpportunity($fixture,$op,true,$mock);check($replay['replayed']&&count($mock->calls)===$n&&$holds===$fixture->query('SELECT * FROM ai_attempts')->fetchAll(),'Exact-once replay preserves accounting '.$mode);
        check((int)$fixture->query('SELECT COUNT(*) FROM memory_stories')->fetchColumn()===0,'Notices never establish world-memory facts');
        $activity=LittleSparks\commons($fixture,$ids)['bulletin_posts'];check(count($activity)===($posted?1:0),'Only actual successful posts have a saved indicator '.$mode);
        if($posted){$notice=LittleSparks\publicBulletin($fixture,$now)['notices'][0];$stored=$fixture->query('SELECT * FROM bulletin_notices')->fetchAll();
            check(LittleSparks\commons($fixture,$ids,$now+169*3600)['bulletin_posts']===[],'Expired posts have no activity indicator');
            $fixture->exec("BEGIN IMMEDIATE;UPDATE simulation_runs SET status='failed' WHERE id IN (SELECT run_id FROM bulletin_notices)");check(LittleSparks\commons($fixture,$ids)['bulletin_posts']===[],'Incomplete/failed opportunities do not project posts');$fixture->exec('ROLLBACK');
            $fixture->exec('BEGIN IMMEDIATE');$fixture->prepare('INSERT INTO bulletin_removals VALUES (?,?,?)')->execute([$notice['id'],gmdate('Y-m-d\TH:i:s\Z',$now),'operator_removed']);
            check(LittleSparks\commons($fixture,$ids)['bulletin_posts']===[],'Removed notices never project activity');$fixture->exec('ROLLBACK');check($stored===$fixture->query('SELECT * FROM bulletin_notices')->fetchAll(),'Read-only projection preserves history');
        }
        putenv('LITTLE_SPARKS_BULLETIN_ACTIVE_MAX');
    }
    $fixture=LittleSparks\connect(dirname($argv[1]).'/board-good.sqlite');$entry=LittleSparks\publicBulletin($fixture,$now)['notices'][0];
    foreach(['DAILY_MAX','RESIDENT_DAILY_MAX','ACTIVE_MAX','RESIDENT_ACTIVE_MAX','TOTAL_MAX'] as $key){putenv('LITTLE_SPARKS_BULLETIN_'.$key.'=0');check(!LittleSparks\bulletinCanPost($fixture,$entry['resident_id'],$now+3601),'Zero quota disables posting '.$key);putenv('LITTLE_SPARKS_BULLETIN_'.$key);}
    $one=LittleSparks\publicBulletin($fixture,$now);check($one['total']===1&&$one['pages']===1,'Bounded public retrieval');
    foreach([$ids,[$entry['resident_id']]] as $visible){$counting=new BulletinCountingPdo('sqlite:'.dirname($argv[1]).'/board-good.sqlite',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        LittleSparks\commons($counting,$visible);check($counting->reads===(count($visible)===2?10:8),'Installed bulletin projection uses a bounded transcript batch, never a query per exchange/message');$counting=null;}
    $orderedFile=dirname($argv[1]).'/board-ordered.sqlite';copy($template,$orderedFile);chmod($orderedFile,0600);$ordered=LittleSparks\connect($orderedFile);
    putenv('LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS=0');putenv('LITTLE_SPARKS_BULLETIN_REPEAT_COOLDOWN_SECONDS=0');
    foreach([120,180] as $offset){$r=LittleSparks\simulateDirectorOpportunity($ordered,gmdate('Y-m-d\TH:i:00\Z',$now-$offset),true,new BulletinResponses());check($r['status']==='completed','Two independent notices in the same day');}
    putenv('LITTLE_SPARKS_BULLETIN_PAGE_SIZE=1');$p1=LittleSparks\publicBulletin($ordered,$now);$p2=LittleSparks\publicBulletin($ordered,$now,2);$expected=$ordered->query('SELECT id FROM bulletin_notices ORDER BY created_at DESC,id DESC')->fetchAll(PDO::FETCH_COLUMN);
    check($p1['pages']===2&&$p1['total']===2&&count($p1['notices'])===1&&[$p1['notices'][0]['id'],$p2['notices'][0]['id']]===$expected,'Stable timestamp/ID ordering and bounded page retrieval');
    putenv('LITTLE_SPARKS_BULLETIN_CONTEXT_COUNT=0');check(LittleSparks\bulletinContext($ordered,$now)===[],'Context disabled independently without affecting public notices');putenv('LITTLE_SPARKS_BULLETIN_CONTEXT_COUNT');
    foreach(['LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS','LITTLE_SPARKS_BULLETIN_REPEAT_COOLDOWN_SECONDS','LITTLE_SPARKS_BULLETIN_PAGE_SIZE'] as $key)putenv($key);
    putenv('LITTLE_SPARKS_BULLETIN_MAX_CHARACTERS=1000');$boundary=['resident_id'=>$ids[0],'kind'=>'whimsy','text'=>str_repeat('x',1000)];check(LittleSparks\validateBulletinNotice($boundary,[['id'=>$ids[0],'name'=>'Maple']])===$boundary,'Configured structural text boundary accepted unchanged');putenv('LITTLE_SPARKS_BULLETIN_MAX_CHARACTERS');
    try{LittleSparks\validateBulletinNotice(['resident_id'=>$ids[0],'kind'=>'plan','text'=>'Maple & Sun: A little question.'],[['id'=>$ids[0],'name'=>'Maple & Sun']]);throw new LogicException('Multiword speaker label accepted');}catch(LittleSparks\AiGuard){}
    try{$fixture->exec("UPDATE bulletin_notices SET body='rewritten'");throw new LogicException('History changed');}catch(PDOException){}
    putenv('LITTLE_SPARKS_BULLETIN_ENABLED=0');$mock=new BulletinResponses('legacy');$recovery=LittleSparks\simulateDirectorOpportunity($fixture,gmdate('Y-m-d\TH:i:00\Z',$now-120),true,$mock);check($recovery['status']==='completed'&&$mock->context['contract']==='sanctuary.director.v1'&&!isset($mock->context['public_notices']),'Disabling capability restores ordinary conversation without destroying notices');
    echo json_encode(['ids'=>$ids,'op'=>$op,'notice'=>$entry['id'],'database'=>dirname($argv[1]).'/board-good.sqlite'],JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
