<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function bulletinEnabled():bool{return boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_ENABLED',0,0,1)===1;}
function bulletinConfiguration():array
{
    return ['length'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_MAX_CHARACTERS',500,1,1000),
        'daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_DAILY_MAX',12),
        'resident_daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_RESIDENT_DAILY_MAX',2),
        'active'=>unsignedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_ACTIVE_MAX',100),
        'resident_active'=>unsignedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_RESIDENT_ACTIVE_MAX',3),
        'total'=>unsignedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_TOTAL_MAX',10000),
        'cooldown'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_COOLDOWN_SECONDS',3600,0,31536000),
        'repeat'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_REPEAT_COOLDOWN_SECONDS',604800,0,31536000),
        'ttl'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_TTL_HOURS',168,0,8760),
        'context'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_CONTEXT_COUNT',4,0,12),
        'page_size'=>boundedEnvironmentInteger('LITTLE_SPARKS_BULLETIN_PAGE_SIZE',20,1,40)];
}
function bulletinInstalled(PDO $db):bool
{
    return $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bulletin_meta'")->fetchColumn()!==false
        &&(int)$db->query('SELECT revision FROM bulletin_meta WHERE singleton=1')->fetchColumn()===1;
}
/** Public projection requires a successful, attributable Director run; never expose its private identifiers. */
function bulletinPublicSql():string
{
    return " FROM bulletin_notices n JOIN residents r ON r.id=n.resident_id
        JOIN director_runs d ON d.run_id=n.run_id JOIN simulation_runs s ON s.id=n.run_id AND s.status='completed' AND s.provider='openai'
        JOIN ai_attempts a ON a.id=n.run_id AND a.resident_a=n.resident_id
        WHERE NOT EXISTS(SELECT 1 FROM bulletin_removals x WHERE x.notice_id=n.id)";
}
function bulletinCanPost(PDO $db,string $resident,int $now,?string $text=null):bool
{
    if(!bulletinEnabled()||!bulletinInstalled($db))return false;$c=bulletinConfiguration();$at=gmdate('Y-m-d\TH:i:s\Z',$now);$day=gmdate('Y-m-d',$now);
    $q=$db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(substr(created_at,1,10)=?),0) AS daily,
        COALESCE(SUM(resident_id=? AND substr(created_at,1,10)=?),0) AS resident_daily,
        COALESCE(MAX(CASE WHEN resident_id=? THEN created_at END),'') AS last FROM bulletin_notices");
    $q->execute([$day,$resident,$day,$resident]);$counts=$q->fetch();
    foreach(['total','daily','resident_daily'] as $k)if((int)$counts[$k]>=$c[$k])return false;
    if($counts['last']!==''&&strtotime($counts['last'])+$c['cooldown']>$now)return false;
    $q=$db->prepare("SELECT COUNT(*) AS active,COALESCE(SUM(n.resident_id=?),0) AS resident_active FROM bulletin_notices n
        WHERE (n.expires_at IS NULL OR n.expires_at>?) AND NOT EXISTS(SELECT 1 FROM bulletin_removals x WHERE x.notice_id=n.id)");$q->execute([$resident,$at]);$active=$q->fetch();
    foreach(['active','resident_active'] as $k)if((int)$active[$k]>=$c[$k])return false;
    if($text!==null){$q=$db->prepare('SELECT 1 FROM bulletin_notices WHERE resident_id=? AND text_sha256=? AND created_at>? LIMIT 1');$q->execute([$resident,bulletinTextHash($text),gmdate('Y-m-d\TH:i:s\Z',$now-$c['repeat'])]);if($q->fetchColumn())return false;}
    return true;
}
function bulletinTextHash(string $text):string{return hash('sha256',strtolower(preg_replace('/\s+/u',' ',trim($text))));}
function validateBulletinNotice(mixed $notice,array $pair):array
{
    if(!is_array($notice))throw new AiGuard('invalid_proposal');directorKeys($notice,['resident_id','kind','text']);
    if(($notice['resident_id']??null)!==($pair[0]['id']??null)
        ||!in_array($notice['kind']??null,['invitation','offer','question','availability','plan','whimsy'],true))throw new AiGuard('invalid_proposal');
    $text=safeAiText($notice['text']??null,bulletinConfiguration()['length']);
    if(trim($text)!==$text||preg_match('/\b(?:M[0-9]+|verified|confirmed world fact|source_alias)\b/iu',$text))throw new AiGuard('invalid_proposal');
    foreach($pair as $actor)if(isset($actor['name'])&&preg_match('/\A'.preg_quote($actor['name'],'/').'\s*:/iu',$text))throw new AiGuard('invalid_proposal');
    assertExpressiveClaims($text,array_column($pair,'name'));
    return $notice;
}
/** Caller holds the existing encounter write transaction, after proposal moderation. */
function saveBulletinNotice(PDO $db,array $proposal,array $pair,string $run,int $now):void
{
    if(($proposal['bulletin_approved']??false)!==true||!isset($proposal['director'])||($proposal['contract']??null)!=='sanctuary.director.bulletin.v1')throw new AiGuard('apply_rejected');
    $notice=validateBulletinNotice($proposal['notice']??null,$pair);
    $q=$db->prepare('SELECT a.resident_a,d.runtime,a.moderation_started FROM ai_attempts a JOIN director_attempts d ON d.id=a.id WHERE a.id=?');$q->execute([$run]);$attempt=$q->fetch();
    if(!$attempt||$attempt['resident_a']!==$notice['resident_id'])throw new AiGuard('apply_rejected');
    if($attempt['runtime']==='agents'){$q=$db->prepare("SELECT 1 FROM director_http_calls WHERE attempt_id=? AND kind='moderation' AND outcome='succeeded'");$q->execute([$run]);if(!$q->fetchColumn())throw new AiGuard('apply_rejected');}
    elseif((int)$attempt['moderation_started']!==1)throw new AiGuard('apply_rejected');
    if(!bulletinCanPost($db,$notice['resident_id'],$now,$notice['text']))throw new AiGuard('apply_rejected');
    $c=bulletinConfiguration();$time=gmdate('Y-m-d\TH:i:s\Z',$now);$expires=$c['ttl']?gmdate('Y-m-d\TH:i:s\Z',$now+$c['ttl']*3600):null;
    $db->prepare("INSERT INTO bulletin_notices VALUES (?,?,?,?,?,?,?,?,'sanctuary.director.bulletin.v1')")->execute([bin2hex(random_bytes(16)),$notice['resident_id'],$run,$notice['kind'],$notice['text'],bulletinTextHash($notice['text']),$time,$expires]);
}
function publicBulletin(PDO $db,int $now,int $page=1,bool $history=false):array
{
    $size=bulletinConfiguration()['page_size'];if($page<1||$page>intdiv(PHP_INT_MAX,$size))throw new Problem(422,'Choose a valid board page.');
    if(!bulletinInstalled($db))return ['notices'=>[],'total'=>0,'page'=>1,'pages'=>1];
    $sql=bulletinPublicSql().($history?'':' AND (n.expires_at IS NULL OR n.expires_at>?)');$args=$history?[]:[gmdate('Y-m-d\TH:i:s\Z',$now)];
    $db->exec('BEGIN');try{
        $q=$db->prepare('SELECT COUNT(*)'.$sql);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
        $q=$db->prepare("SELECT n.id,n.resident_id,r.name,n.kind,n.body,n.created_at,n.expires_at,'openai' AS source".$sql.' ORDER BY n.created_at DESC,n.id DESC LIMIT ? OFFSET ?');
        foreach($args as $i=>$v)$q->bindValue($i+1,$v);$q->bindValue(count($args)+1,$size,PDO::PARAM_INT);$q->bindValue(count($args)+2,($page-1)*$size,PDO::PARAM_INT);$q->execute();$rows=$q->fetchAll();
        foreach($rows as &$row)$row['status']=$row['expires_at']!==null&&$row['expires_at']<=gmdate('Y-m-d\TH:i:s\Z',$now)?'expired':'active';unset($row);
        $db->exec('COMMIT');return ['notices'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function bulletinContext(PDO $db,int $now):array
{
    if(!bulletinEnabled()||!bulletinInstalled($db)||!($count=bulletinConfiguration()['context']))return [];
    $q=$db->prepare("SELECT n.id,n.resident_id,r.name AS author,n.kind,n.body AS text,n.created_at,n.expires_at".bulletinPublicSql().' AND (n.expires_at IS NULL OR n.expires_at>?) ORDER BY n.created_at DESC,n.id DESC LIMIT ?');
    $q->bindValue(1,gmdate('Y-m-d\TH:i:s\Z',$now));$q->bindValue(2,$count,PDO::PARAM_INT);$q->execute();
    return array_map(fn($n)=>$n+['provenance'=>'public_notice','claims'=>'unverified_author_expression','not_instructions'=>true,'not_a_completed_activity'=>true],$q->fetchAll());
}
