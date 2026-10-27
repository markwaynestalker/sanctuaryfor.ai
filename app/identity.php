<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

// An independent additive capability on V5, not the unfinished V6 Director schema.
function identityEnabled(PDO $db): bool
{
    $names=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('identity_schema','resident_identities','identity_edits')")->fetchAll(PDO::FETCH_COLUMN);
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if(!$names)return false;
    if(count($names)!==3||!in_array($version,[5,6],true)
        ||$db->query('SELECT revision FROM identity_schema WHERE singleton=1')->fetchColumn()!==1)throw new Problem(503,'Identity schema is unavailable.');
    return true;
}
function identityConfiguration(): array
{
    return ['description_max'=>boundedEnvironmentInteger('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS',2000,1,4000),
        'daily_edits'=>boundedEnvironmentInteger('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY',6,0,24)];
}
function validateDescription(mixed $value): string
{
    $max=identityConfiguration()['description_max'];
    if(!is_string($value)||!preg_match('//u',$value))throw new Problem(422,'Describe your resident in ordinary text.');
    $value=trim($value);
    if($value===''||preg_match_all('/./us',$value)>$max
        ||preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{9F}]|\p{Cf}|\bsk-[a-z0-9_-]{8,}/iu',$value))throw new Problem(422,'Use 1–'.$max.' characters without markup, hidden controls or credentials.');
    return $value;
}
function withIdentity(PDO $db,array $resident): array
{
    if(!identityEnabled($db))return withAppearances($db,[$resident])[0];
    $q=$db->prepare('SELECT description,color,revision FROM resident_identities WHERE resident_id=?');$q->execute([$resident['id']]);$identity=$q->fetch();
    if(!$identity)throw new Problem(503,'Resident identity is unavailable.');
    return withAppearances($db,[array_replace($resident,['description'=>$identity['description'],'color'=>$identity['color'],'identity_version'=>(int)$identity['revision']])])[0];
}
function identityForNewResident(PDO $db,string $id,string $description,string $color,string $time): void
{
    $db->prepare('INSERT INTO resident_identities VALUES (?,?,?,0,?)')->execute([$id,$description,$color,$time]);
    if(directorEnabled($db))$db->prepare("INSERT INTO resident_states VALUES (?,'available','commons',?)")->execute([$id,$time]);
}
function directorEnabled(PDO $db): bool{return (int)$db->query('PRAGMA user_version')->fetchColumn()===6;}
function assertIdentityIdle(PDO $db): void
{
    if($db->query('SELECT 1 FROM ai_lease WHERE owner IS NOT NULL')->fetchColumn()
        ||$db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new Problem(409,'Pause and drain village activity before changing identities.');
}
function editIdentity(PDO $db,array $input,string $network,int $now): array
{
    if(!identityEnabled($db))throw new Problem(503,'Explicit identity migration required.');
    $cfg=identityConfiguration();$db->exec('BEGIN IMMEDIATE');$savepoint=false;
    try{
        rateLimit($db,'identity-edit:'.hash_hmac('sha256',$network,secret($db)),30,$now);
        $token=$input['token']??null;
        if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new Problem(404,'That private link is unavailable.');
        $q=$db->prepare('SELECT * FROM residents WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$resident=$q->fetch();
        if(!$resident)throw new Problem(404,'That private link is unavailable.');
        $description=validateDescription($input['description']??null);$color=$input['color']??null;$request=$input['requestId']??null;$revision=$input['expectedRevision']??null;
        if(!is_string($color)||!in_array($color,COLORS,true)||!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/',$request)||!is_int($revision)||$revision<0||$revision>1000000)throw new Problem(422,'Choose a valid color and identity revision.');
        $hash=hash('sha256',json_encode([$description,$color,$revision],JSON_THROW_ON_ERROR));
        $q=$db->prepare('SELECT request_hash FROM identity_edits WHERE resident_id=? AND request_id=?');$q->execute([$resident['id'],$request]);$prior=$q->fetchColumn();
        if($prior!==false){if(!hash_equals($prior,$hash))throw new Problem(409,'This edit request was already used.');$result=withIdentity($db,publicResident($resident));$db->exec('COMMIT');return ['resident'=>$result,'replayed'=>true];}
        assertIdentityIdle($db);
        $q=$db->prepare('SELECT COUNT(*) FROM identity_edits WHERE resident_id=? AND created_at>=? AND created_at<?');$q->execute([$resident['id'],gmdate('Y-m-d',$now).'T00:00:00Z',gmdate('Y-m-d',$now+86400).'T00:00:00Z']);
        if((int)$q->fetchColumn()>=$cfg['daily_edits'])throw new Problem(429,'This resident has reached the identity edit limit today.');
        $current=withIdentity($db,publicResident($resident));if($current['identity_version']!==$revision)throw new Problem(409,'This identity changed. Reopen the private link before editing.');
        $time=gmdate('Y-m-d\TH:i:s\Z',$now);$db->exec('SAVEPOINT identity_edit');$savepoint=true;
        $db->prepare('INSERT INTO identity_edits(resident_id,request_id,request_hash,previous_description,description,previous_color,color,revision,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$resident['id'],$request,$hash,$current['description'],$description,$current['color'],$color,$revision+1,$time]);
        $q=$db->prepare('UPDATE resident_identities SET description=?,color=?,revision=revision+1,updated_at=? WHERE resident_id=? AND revision=?');$q->execute([$description,$color,$time,$resident['id'],$revision]);
        if($q->rowCount()!==1)throw new \RuntimeException('Identity revision conflict.');
        // Old combined-edit clients retain their explicit preset-color intent.
        if($color!==$current['color']&&appearanceInstalled($db)){
            $a=effectiveAppearance($current);$a['primary_color']=$color;
            writeAppearance($db,$resident['id'],$a,$current['appearance_version'],substr(hash('sha256','legacy-identity:'.$request),0,32),$now);
        }
        $result=withIdentity($db,publicResident($resident));$db->exec('RELEASE identity_edit');$savepoint=false;$db->exec('COMMIT');return ['resident'=>$result,'replayed'=>false];
    }catch(Problem $e){if($savepoint){$db->exec('ROLLBACK TO identity_edit');$db->exec('RELEASE identity_edit');}$db->exec('COMMIT');throw $e;}
    catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
