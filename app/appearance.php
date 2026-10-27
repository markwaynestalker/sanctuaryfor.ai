<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

function avatarRegistry():array{static $r;return $r??=json_decode((string)file_get_contents(__DIR__.'/../public/assets/avatars.json'),true,16,JSON_THROW_ON_ERROR);}
function validateAppearance(mixed $value):array
{
    if(!is_array($value)||count($value)!==3||array_diff(array_keys($value),['form','primary_color','features']))throw new Problem(422,'Choose a supported appearance.');
    $form=$value['form']??null;$color=$value['primary_color']??null;$features=$value['features']??null;$registry=avatarRegistry();
    if(!is_string($form)||!in_array($form,array_column($registry['forms'],'id'),true)||!is_string($color)
        ||(!isset($registry['colors'][$color])&&!preg_match('/\A#[a-fA-F0-9]{6}\z/',$color))
        ||!is_array($features)||array_diff(array_keys($features),['antenna']))throw new Problem(422,'Choose a supported form, preset or six-digit RGB color.');
    $definition=$registry['forms'][array_search($form,array_column($registry['forms'],'id'),true)];
    if(in_array('antenna',$definition['features'],true)){
        $antenna=$features['antenna']??'orb';if(!is_string($antenna)||!in_array($antenna,ANTENNAS,true))throw new Problem(422,'Choose an available antenna.');$features=['antenna'=>$antenna];
    }elseif($features!==[])throw new Problem(422,'This form does not use those features.');
    return ['form'=>$form,'primary_color'=>str_starts_with($color,'#')?strtoupper($color):$color,'features'=>$features];
}
function legacyAppearance(array $r):array{return ['form'=>'classic-robot','primary_color'=>in_array($r['color']??null,COLORS,true)?$r['color']:'mint','features'=>['antenna'=>in_array($r['antenna']??null,ANTENNAS,true)?$r['antenna']:'orb']];}
function effectiveAppearance(array $resident):array{try{return isset($resident['appearance'])?validateAppearance($resident['appearance']):legacyAppearance($resident);}catch(\Throwable){return legacyAppearance($resident);}}
function appearanceInstalled(PDO $db):bool
{
    $names=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('appearance_meta','resident_appearances','appearance_edits')")->fetchAll(PDO::FETCH_COLUMN);
    if(!$names)return false;if(count($names)!==3||(int)$db->query('SELECT revision FROM appearance_meta WHERE singleton=1')->fetchColumn()!==1)throw new Problem(503,'Appearance schema is unavailable.');return true;
}
/** One bounded query for a public page; never an N+1 renderer lookup. */
function withAppearances(PDO $db,array $rows):array
{
    if(!$rows||!appearanceInstalled($db))return $rows;$ids=array_values(array_unique(array_column($rows,'id')));if(count($ids)>200)throw new Problem(422,'Choose a bounded resident page.');
    $q=$db->prepare('SELECT resident_id,configuration,revision FROM resident_appearances WHERE resident_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$stored=array_column($q->fetchAll(),null,'resident_id');
    foreach($rows as &$r){$s=$stored[$r['id']]??null;$r['appearance']=$s?effectiveAppearance($r+['appearance'=>json_decode($s['configuration'],true)]):legacyAppearance($r);$r['appearance_version']=$s?(int)$s['revision']:0;}unset($r);return $rows;
}
function appearanceBadges(PDO $db,array $ids):array
{
    $ids=array_values(array_unique(array_filter($ids,fn($id)=>is_string($id)&&preg_match('/\A[a-f0-9]{32}\z/',$id))));if(!$ids)return [];if(count($ids)>200)throw new Problem(422,'Choose a bounded resident page.');
    $identity=identityEnabled($db);$q=$db->prepare('SELECT r.id,r.antenna,'.($identity?'i.color':'r.color').' AS color FROM residents r '.($identity?'JOIN resident_identities i ON i.resident_id=r.id ':'').'WHERE r.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
    return array_column(withAppearances($db,$q->fetchAll()),null,'id');
}
function appearancePalette(array $appearance):array
{
    $hex=avatarRegistry()['colors'][$appearance['primary_color']]??$appearance['primary_color'];$rgb=array_map('hexdec',str_split(substr($hex,1),2));
    $mix=static fn(int $target):string=>'#'.implode('',array_map(fn($v)=>strtoupper(str_pad(dechex((int)floor($v*.75+$target*.25+.5)),2,'0',STR_PAD_LEFT)),$rgb));
    $ink=($rgb[0]*299+$rgb[1]*587+$rgb[2]*114)>=140000?'#12242A':'#FFFFFF';return ['primary'=>$hex,'highlight'=>$mix(255),'shadow'=>$mix(0),'ink'=>$ink,'outline'=>$ink];
}
function avatarSvg(array $resident,string $class=''):string
{
    $a=effectiveAppearance($resident);$palette=appearancePalette($a);$style='';foreach($palette as $k=>$v)$style.='--spark-'.$k.':'.$v.';';
    $extra=htmlspecialchars($class,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$asset='/assets/spark-forms.svg?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/spark-forms.svg'),0,12);
    $svg='<svg class="spark-avatar '.$extra.'" viewBox="0 0 48 70" aria-hidden="true" focusable="false" style="'.$style.'"><use href="'.$asset.'#'.$a['form'].'"/>';
    if(isset($a['features']['antenna']))$svg.='<use href="'.$asset.'#antenna-'.$a['features']['antenna'].'"/>';return $svg.'</svg>';
}
function avatarAssets():void
{
    $r=avatarRegistry();$r['sprite']='/assets/spark-forms.svg?v='.substr(hash_file('sha256',__DIR__.'/../public/assets/spark-forms.svg'),0,12);
    echo '<script id="spark-avatar-registry" type="application/json">'.json_encode($r,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script><script src="'.htmlspecialchars(assetUrl('avatars.js'),ENT_QUOTES,'UTF-8').'" defer></script>';
}
function appearanceConfiguration():array{return ['daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_APPEARANCE_EDITS_PER_DAY',24),'hourly'=>unsignedEnvironmentInteger('LITTLE_SPARKS_APPEARANCE_REQUESTS_PER_HOUR',60)];}
/** Caller owns write transaction; appearance revisions never alter identity revisions. */
function writeAppearance(PDO $db,string $id,array $appearance,int $expected,string $request,int $now):void
{
    $q=$db->prepare('SELECT configuration,revision FROM resident_appearances WHERE resident_id=?');$q->execute([$id]);$old=$q->fetch();$revision=$old?(int)$old['revision']:0;
    if($revision!==$expected||$revision>=PHP_INT_MAX)throw new Problem(409,'Appearance changed. Reopen the private link before saving.');
    $q=$db->prepare('SELECT COUNT(*) FROM appearance_edits WHERE resident_id=? AND created_at>=? AND created_at<?');$q->execute([$id,gmdate('Y-m-d',$now).'T00:00:00Z',gmdate('Y-m-d',$now+86400).'T00:00:00Z']);if((int)$q->fetchColumn()>=appearanceConfiguration()['daily'])throw new Problem(429,'This Spark has reached the configured appearance edit limit today.');
    $json=json_encode($appearance,JSON_THROW_ON_ERROR);$time=gmdate('Y-m-d\TH:i:s\Z',$now);$hash=hash('sha256',json_encode([$appearance,$expected],JSON_THROW_ON_ERROR));
    $db->prepare('INSERT INTO appearance_edits(resident_id,request_id,request_hash,previous_configuration,configuration,revision,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$id,$request,$hash,$old['configuration']??null,$json,$revision+1,$time]);
    $db->prepare('INSERT INTO resident_appearances VALUES (?,?,?,?) ON CONFLICT(resident_id) DO UPDATE SET configuration=excluded.configuration,revision=excluded.revision,updated_at=excluded.updated_at')->execute([$id,$json,$revision+1,$time]);
}
function editAppearance(PDO $db,array $input,string $network,int $now):array
{
    if(!appearanceInstalled($db))throw new Problem(503,'Explicit appearance migration required.');
    $db->exec('BEGIN IMMEDIATE');$savepoint=false;
    try{
        rateLimit($db,'appearance:'.hash_hmac('sha256',$network,secret($db)),appearanceConfiguration()['hourly'],$now);
        if(array_diff(array_keys($input),['token','appearance','expectedRevision','requestId']))throw new Problem(422,'Unexpected appearance field.');
        $token=$input['token']??null;if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new Problem(404,'That private link is unavailable.');
        $q=$db->prepare('SELECT * FROM residents WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$r=$q->fetch();if(!$r)throw new Problem(404,'That private link is unavailable.');
        $a=validateAppearance($input['appearance']??null);$revision=$input['expectedRevision']??null;$request=$input['requestId']??null;
        if(!is_int($revision)||$revision<0||!is_string($request)||!preg_match('/\A[a-f0-9]{32}\z/',$request))throw new Problem(422,'Use a valid edit revision and request ID.');
        $hash=hash('sha256',json_encode([$a,$revision],JSON_THROW_ON_ERROR));$q=$db->prepare('SELECT request_hash FROM appearance_edits WHERE resident_id=? AND request_id=?');$q->execute([$r['id'],$request]);$prior=$q->fetchColumn();
        if($prior!==false){if(!hash_equals($prior,$hash))throw new Problem(409,'This edit request was already used.');$db->exec('COMMIT');return ['resident'=>withIdentity($db,publicResident($r)),'replayed'=>true];}
        $db->exec('SAVEPOINT appearance_edit');$savepoint=true;writeAppearance($db,$r['id'],$a,$revision,$request,$now);$db->exec('RELEASE appearance_edit');$savepoint=false;$db->exec('COMMIT');return ['resident'=>withIdentity($db,publicResident($r)),'replayed'=>false];
    }catch(Problem $e){if($savepoint){$db->exec('ROLLBACK TO appearance_edit');$db->exec('RELEASE appearance_edit');}$db->exec('COMMIT');throw $e;}catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
