<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/weather_source.php';
const VILLAGE_WEATHER_KINDS=['sunny','rainy','foggy','snowy','neutral'];
function villageWeatherConfiguration():array
{
    $enabled=boundedEnvironmentInteger('LITTLE_SPARKS_WEATHER_ENABLED',0,0,1)===1;
    $url=getenv('LITTLE_SPARKS_WEATHER_SOURCE_URL');$url=$url===false?'':$url;
    $station=getenv('LITTLE_SPARKS_WEATHER_STATION');$station=$station===false?'thestalkerfarm':$station;
    if(!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/',$station)||strlen($url)>256)throw new Problem(503,'Village weather configuration is invalid.');
    if($url!==''||$enabled){
        $parts=parse_url($url);$ip=$parts['host']??'';$octets=explode('.',$ip);
        if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
            ||(int)($octets[0]??0)!==100||(int)($octets[1]??0)<64||(int)($octets[1]??0)>127||($parts['path']??'')!=='/weather/'
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
            ||(isset($parts['port'])&&$parts['port']!==($parts['scheme']==='http'?80:443)))throw new Problem(503,'Village weather source is invalid.');
    }
    return ['enabled'=>$enabled,'url'=>$url,'station'=>$station,'source_id'=>hash('sha256',$url.'|'.$station),
        'refresh'=>boundedEnvironmentInteger('LITTLE_SPARKS_WEATHER_REFRESH_MINUTES',30,1,1440)*60,
        'max_age'=>boundedEnvironmentInteger('LITTLE_SPARKS_WEATHER_MAX_AGE_MINUTES',120,1,1440)*60,
        'timeout'=>boundedEnvironmentInteger('LITTLE_SPARKS_WEATHER_HTTP_TIMEOUT_SECONDS',3,1,10),
        'daily'=>unsignedEnvironmentInteger('LITTLE_SPARKS_WEATHER_DAILY_REQUESTS',48)];
}
function validatedVillageWeather(mixed $v):array
{
    if(!is_array($v)||($v['version']??null)!==1||($v['classification']??null)!=='reported_farm_weather'
        ||!is_string($v['id']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$v['id'])||!in_array($v['kind']??null,VILLAGE_WEATHER_KINDS,true)
        ||!is_string($v['description']??null)||!in_array($v['description'],['Fair skies','Rain reported','Fog reported','Snow reported','Cloud cover reported','Conditions unavailable'],true)
        ||(!is_int($v['temperature_f']??null)&&!is_float($v['temperature_f']??null))||$v['temperature_f']< -150||$v['temperature_f']>160)throw new Problem(503,'Saved village weather is invalid.');
    foreach(['observed_at','valid_until','sunrise_at','sunset_at'] as $key)if(!is_int($v[$key]??null)||$v[$key]<0)throw new Problem(503,'Saved village weather is invalid.');
    if($v['valid_until']<=$v['observed_at']||$v['sunrise_at']>=$v['sunset_at'])throw new Problem(503,'Saved village weather is invalid.');
    return array_intersect_key($v,array_flip(['version','id','kind','classification','description','temperature_f','observed_at','valid_until','sunrise_at','sunset_at']));
}
/** A separate, bounded internal-source ledger. Never uses/refunds AI request slots. */
function refreshVillageWeather(PDO $db,int $now,?WeatherTransport $transport=null,?callable $clock=null):void
{
    $cfg=villageWeatherConfiguration();if(!$cfg['enabled'])return;
    $id=hash('sha256',$cfg['source_id'].'|'.intdiv($now,$cfg['refresh']));$day=gmdate('Y-m-d',$now);$key='village_weather:request:'.$day.':'.$id;
    $db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->prepare('SELECT 1 FROM settings WHERE name=?');$q->execute([$key]);if($q->fetchColumn()){$db->exec('COMMIT');return;}
        $q=$db->prepare("SELECT COUNT(*) FROM settings WHERE name LIKE ?");$q->execute(['village_weather:request:'.$day.':%']);
        if((int)$q->fetchColumn()>=$cfg['daily']){$db->exec('COMMIT');return;}
        $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['village_weather:lease']);$raw=$q->fetchColumn();$lease=$raw===false?null:json_decode($raw,true);
        if(($lease['expires']??0)>$now){$db->exec('COMMIT');return;}
        $db->prepare('INSERT INTO settings(name,value) VALUES (?,?)')->execute([$key,json_encode(['at'=>$now,'source_id'=>$cfg['source_id']],JSON_THROW_ON_ERROR)]);
        $db->prepare("INSERT INTO settings VALUES ('village_weather:lease',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value")
            ->execute([json_encode(['owner'=>$id,'expires'=>$now+$cfg['timeout']+5],JSON_THROW_ON_ERROR)]);
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
    $clean=null;$outcome='unavailable';
    try{$payload=($transport??new FarmWeatherTransport())->get($cfg,$now+$cfg['timeout']);$readAt=$clock?$clock():time();$clean=sanitizeFarmWeather($payload,$cfg,$readAt);$outcome='recorded';}catch(\Throwable $error){$outcome=match($error->getMessage()){'weather_stale'=>'stale','weather_invalid','weather_payload_invalid','weather_timestamp_invalid','weather_temperature_invalid'=>'invalid',default=>'unavailable'};/* Never retain arbitrary errors or response content. */}
    $finished=$clock?$clock():time();$db->exec('BEGIN IMMEDIATE');
    try{
        $q=$db->query("SELECT value FROM settings WHERE name='village_weather:lease'");$lease=json_decode((string)$q->fetchColumn(),true);
        if(($lease['owner']??null)!==$id||($lease['expires']??0)<=$finished)$outcome='expired';
        if($outcome==='recorded'){
            $db->prepare('INSERT OR IGNORE INTO settings VALUES (?,?)')->execute(['village_weather:observation:'.$clean['id'],json_encode($clean,JSON_THROW_ON_ERROR)]);
            $q=$db->query("SELECT value FROM settings WHERE name='village_weather:latest'");$raw=$q->fetchColumn();$previous=$raw===false?null:json_decode($raw,true);
            if(($previous['source_id']??null)!==$cfg['source_id']||($previous['observation']['observed_at']??0)<=$clean['observed_at'])
                $db->prepare("INSERT INTO settings VALUES ('village_weather:latest',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value")->execute([json_encode(['source_id'=>$cfg['source_id'],'observation'=>$clean],JSON_THROW_ON_ERROR)]);
        }
        if($outcome!=='expired'){
            // Publish the cache in the same commit, before a Director can receive it.
            // Preserve unrelated scheduler/opportunity status while importing weather.
            try{$tick=publishedVillageTick($db);}catch(\Throwable){$tick=[];}
            $tick+=['at'=>$finished,'status'=>'weather_ready','sleep_policy'=>villageSleepConfiguration()];$tick['weather_enabled']=true;
            $published=cachedVillageWeather($db);if($published)$tick['weather']=$published;else unset($tick['weather']);
            $db->prepare("INSERT INTO settings VALUES ('scheduler:last_tick',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value")->execute([json_encode($tick,JSON_THROW_ON_ERROR)]);
        }
        $db->prepare('INSERT OR IGNORE INTO settings VALUES (?,?)')->execute(['village_weather:result:'.$id,json_encode(['at'=>$finished,'outcome'=>$outcome,'observation_id'=>$outcome==='recorded'?$clean['id']:null],JSON_THROW_ON_ERROR)]);
        if(($lease['owner']??null)===$id)$db->prepare("UPDATE settings SET value=? WHERE name='village_weather:lease'")->execute([json_encode(['owner'=>$id,'expires'=>0],JSON_THROW_ON_ERROR)]);
        $db->exec('COMMIT');
    }catch(\Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function cachedVillageWeather(PDO $db):?array
{
    $cfg=villageWeatherConfiguration();if(!$cfg['enabled'])return null;
    try{$raw=$db->query("SELECT value FROM settings WHERE name='village_weather:latest'")->fetchColumn();if($raw===false)return null;$v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(($v['source_id']??null)!==$cfg['source_id'])return null;$clean=validatedVillageWeather($v['observation']??null);$clean['valid_until']=min($clean['valid_until'],$clean['observed_at']+$cfg['max_age']);return $clean;
    }catch(\Throwable){return null;}
}
function captureVillageWeather(PDO $db,int $now):?array
{
    $v=cachedVillageWeather($db);return $v&&$now>=$v['observed_at']&&$now<$v['valid_until']?$v:null;
}
function publishedVillageTick(PDO $db):array
{
    $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['scheduler:last_tick']);$raw=$q->fetchColumn();if($raw===false)return [];
    $v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new Problem(503,'Village update is invalid.');return $v;
}
function weatherDaylightAt(array $v,int $now):?bool
{
    $zone=new \DateTimeZone('America/New_York');
    $day=(new \DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
    if((new \DateTimeImmutable('@'.$v['sunrise_at']))->setTimezone($zone)->format('Y-m-d')!==$day)return null;
    return $now>=$v['sunrise_at']&&$now<$v['sunset_at'];
}
function publicVillageWeather(array $tick,int $now):?array
{
    if(!isset($tick['weather']))return ($tick['weather_enabled']??false)===true?['available'=>false,'classification'=>'reported_farm_weather']:null;try{$v=validatedVillageWeather($tick['weather']);}catch(\Throwable){return ['available'=>false,'classification'=>'reported_farm_weather'];}
    return $v+['available'=>true,'server_time'=>$now,'current'=>$now>=$v['observed_at']&&$now<$v['valid_until'],'stale'=>$now>=$v['valid_until'],
        'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',$v['observed_at']),'is_daytime'=>weatherDaylightAt($v,$now)];
}
function encounterVillageWeather(PDO $db,array $claim):?array
{
    $q=$db->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['village_weather:opportunity:'.$claim['id']]);$raw=$q->fetchColumn();if($raw===false)return null;
    try{$v=validatedVillageWeather(json_decode($raw,true,16,JSON_THROW_ON_ERROR));}catch(\Throwable){return null;}$at=strtotime($claim['created_at']);if($at===false||$at<$v['observed_at']||$at>=$v['valid_until'])return null;
    return $v+['is_daytime'=>weatherDaylightAt($v,$at),'untrusted_environmental_data'=>true,'optional'=>true,
        'snow_accumulation'=>'unknown','forecast'=>'unknown','not_resident_memory'=>true,'not_a_world_action_authority'=>true];
}
