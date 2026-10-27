<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function operatorIdeasEnabled():bool{return boundedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEAS_ENABLED',0,0,1)===1;}
function operatorIdeaConfiguration():array
{
    return ['length'=>boundedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEA_MAX_CHARACTERS',500,1,1000),
        'daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEAS_PER_DAY',12),
        'pending'=>unsignedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEAS_PENDING_PER_RESIDENT',4),
        'context'=>boundedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEAS_CONTEXT_COUNT',2,0,8),
        'page_size'=>boundedEnvironmentInteger('LITTLE_SPARKS_OWNER_IDEAS_HISTORY_PAGE_SIZE',20,1,100)];
}
function operatorIdeasInstalled(PDO $db):bool
{
    return $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='operator_ideas_meta'")->fetchColumn()!==false
        &&(int)$db->query('SELECT revision FROM operator_ideas_meta WHERE singleton=1')->fetchColumn()===1;
}
function operatorIdeaText(mixed $value):string
{
    $max=operatorIdeaConfiguration()['length'];
    if(!is_string($value)||!preg_match('//u',$value))throw new Problem(422,'Use ordinary text for your idea.');
    $value=trim($value);
    if($value===''||preg_match_all('/./us',$value)>$max||preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{9F}]|\p{Cf}|\bsk-[a-z0-9_-]{8,}|Bearer\s+[a-z0-9_-]{8,}/iu',$value))throw new Problem(422,'Use 1–'.$max.' characters without markup, hidden controls or credentials.');
    return $value;
}
/** Only the authenticated site-operator controller may call this write seam. */
function submitOperatorIdea(PDO $db,array $input,int $now):array
{
    if(!operatorIdeasEnabled()||!operatorIdeasInstalled($db))throw new Problem(503,'Operator ideas are not enabled and installed.');
    $resident=$input['resident']??null;$request=$input['request_id']??null;
    if(!is_string($resident)||!preg_match('/\A[a-f0-9]{32}\z/',$resident)||!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/',$request))throw new Problem(422,'Choose a resident and a valid submission.');
    $body=operatorIdeaText($input['idea']??null);$hash=hash('sha256',json_encode([$resident,$body],JSON_THROW_ON_ERROR));$cfg=operatorIdeaConfiguration();
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT id,request_hash FROM operator_ideas WHERE request_id=?');$q->execute([$request]);$old=$q->fetch();
        if($old){if(!hash_equals($old['request_hash'],$hash))throw new Problem(409,'That submission was already used for a different idea.');$db->exec('COMMIT');return ['id'=>$old['id'],'replayed'=>true];}
        $q=$db->prepare('SELECT 1 FROM residents WHERE id=?');$q->execute([$resident]);if(!$q->fetchColumn())throw new Problem(404,'Resident not found.');
        $day=gmdate('Y-m-d',$now);$q=$db->prepare('SELECT COUNT(*) FROM operator_ideas WHERE created_at>=? AND created_at<?');$q->execute([$day.'T00:00:00Z',gmdate('Y-m-d',$now+86400).'T00:00:00Z']);
        if((int)$q->fetchColumn()>=$cfg['daily'])throw new Problem(429,'The operator idea allowance is full for this UTC day.');
        $q=$db->prepare('SELECT COUNT(*) FROM operator_ideas i WHERE resident_id=? AND NOT EXISTS(SELECT 1 FROM operator_idea_exposures x WHERE x.idea_id=i.id)');$q->execute([$resident]);
        if((int)$q->fetchColumn()>=$cfg['pending'])throw new Problem(429,'This resident already has the configured number of pending ideas.');
        $id=bin2hex(random_bytes(16));$db->prepare("INSERT INTO operator_ideas VALUES (?,?,?,'site_operator',?,?,?)")->execute([$id,$resident,$body,gmdate('Y-m-d\TH:i:s\Z',$now),$request,$hash]);
        $db->exec('COMMIT');return ['id'=>$id,'replayed'=>false];
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function operatorIdeasForResident(PDO $db,string $resident):array
{
    if(!operatorIdeasEnabled()||!operatorIdeasInstalled($db))return [];
    $cfg=operatorIdeaConfiguration();$q=$db->prepare('SELECT id,body,author,created_at AS at FROM operator_ideas i WHERE resident_id=? AND NOT EXISTS(SELECT 1 FROM operator_idea_exposures x WHERE x.idea_id=i.id) ORDER BY created_at,id LIMIT ?');
    $q->bindValue(1,$resident);$q->bindValue(2,$cfg['context'],PDO::PARAM_INT);$q->execute();$out=[];
    foreach($q->fetchAll() as $row){
        // Stored owner text is untrusted context, never an instruction or a receipt.
        try{operatorIdeaText($row['body']);}catch(Problem){continue;}
        $out[]=$row+['classification'=>'unverified_operator_suggestion','optional'=>true,'may_ignore'=>true,'is_world_fact'=>false,'not_instructions'=>true];
    }
    return $out;
}
/** Called only inside the successful canonical conversation transaction. */
function saveOperatorIdeaExposures(PDO $db,string $conversation,array $ids,string $time):void
{
    if(!$ids)return;
    foreach(array_unique($ids) as $id){
        // Bind every receipt to an actual participant, independent of model claims.
        $db->prepare('INSERT INTO operator_idea_exposures(idea_id,conversation_id,created_at) SELECT i.id,?,? FROM operator_ideas i JOIN conversation_participants p ON p.resident_id=i.resident_id AND p.conversation_id=? WHERE i.id=?')
            ->execute([$conversation,$time,$conversation,$id]);
    }
}
function operatorIdeaHistory(PDO $db,?string $before=null):array
{
    $limit=operatorIdeaConfiguration()['page_size'];
    if($before!==null&&(!preg_match('/\A[1-9][0-9]*\z/',$before)||filter_var($before,FILTER_VALIDATE_INT)===false))throw new Problem(422,'Invalid history page.');
    $q=$db->prepare('SELECT i.rowid AS cursor,i.id,i.resident_id,r.name,i.body,i.author,i.created_at,EXISTS(SELECT 1 FROM operator_idea_exposures x WHERE x.idea_id=i.id) AS included FROM operator_ideas i JOIN residents r ON r.id=i.resident_id WHERE i.rowid<? ORDER BY i.rowid DESC LIMIT ?');
    $q->bindValue(1,$before===null?PHP_INT_MAX:(int)$before,PDO::PARAM_INT);$q->bindValue(2,$limit+1,PDO::PARAM_INT);$q->execute();$rows=$q->fetchAll();$more=count($rows)>$limit;
    if($more)array_pop($rows);
    return ['ideas'=>$rows,'next'=>$more?(string)$rows[array_key_last($rows)]['cursor']:null];
}
