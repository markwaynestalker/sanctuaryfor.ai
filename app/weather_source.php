<?php
declare(strict_types=1);
namespace LittleSparks;
interface WeatherTransport{public function get(array $configuration,int $deadline):array;}
final class FarmWeatherTransport implements WeatherTransport
{
    public function get(array $configuration,int $deadline):array
    {
        // Fixed operator-configured tailnet GET only. No redirects, automation URLs,
        // cookies, verbose diagnostics, model tools or response-body logging.
        $expected=villageWeatherConfiguration();if(!$expected['enabled']||$configuration['url']!==$expected['url'])throw new \RuntimeException('weather_source_invalid');
        $headers=['Accept: application/json'];$token=getenv('LITTLE_SPARKS_WEATHER_BEARER_TOKEN');
        if($token!==false&&$token!==''){
            if(strlen($token)>512||preg_match('/[\x00-\x20\x7f]/',$token))throw new \RuntimeException('weather_credentials_invalid');
            $headers[]='Authorization: Bearer '.$token;
        }
        $timeout=min($expected['timeout'],$deadline-time());if($timeout<1)throw new \RuntimeException('weather_deadline');
        $body='';$curl=curl_init($configuration['url']);
        if($curl===false)throw new \RuntimeException('weather_unavailable');
        try{
            curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
            if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)throw new \RuntimeException('weather_unavailable');
        }finally{curl_close($curl);}
        return decodeFarmWeatherPayload($body);
    }
}
function decodeFarmWeatherPayload(string $body):array
{
    if(strlen($body)>65536)throw new \RuntimeException('weather_payload_invalid');
    $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($data)||array_is_list($data))throw new \RuntimeException('weather_payload_invalid');return $data;
}
function farmCondition(mixed $text):array
{
    if(!is_string($text)||strlen($text)>64||preg_match('/[\x00-\x1F\x7F]/',$text))throw new \RuntimeException('weather_invalid');
    $key=strtolower(trim($text));
    $groups=['sunny'=>['sunny','clear','fair','mostly clear','mostly sunny'],
        'rainy'=>['rain','rainy','light rain','heavy rain','showers','rain showers','light rain showers','drizzle','light drizzle','rain with thunder'],
        'foggy'=>['fog','foggy','light fog','mist'],
        'snowy'=>['snow','snowy','light snow','heavy snow','snow showers','flurries','light snow showers'],
        'neutral'=>['cloudy','mostly cloudy','partly cloudy','overcast']];
    foreach($groups as $condition=>$known)if(in_array($key,$known,true))return ['kind'=>$condition,'description'=>['sunny'=>'Fair skies','rainy'=>'Rain reported','foggy'=>'Fog reported','snowy'=>'Snow reported','neutral'=>'Cloud cover reported'][$condition]];
    // No upstream free text becomes a model instruction, even if it contains a
    // familiar word. Unknown phrases use the explicitly neutral state.
    return ['kind'=>'neutral','description'=>'Conditions unavailable'];
}
function farmLocalTime(mixed $text):\DateTimeImmutable
{
    if(!is_string($text)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$text))throw new \RuntimeException('weather_timestamp_invalid');
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text,new \DateTimeZone('America/New_York'));
    if(!$date||$date->format('Y-m-d H:i:s')!==$text)throw new \RuntimeException('weather_timestamp_invalid');return $date;
}
function sanitizeFarmWeather(array $payload,array $cfg,int $now):array
{
    $data=$payload[$cfg['station']]??null;if(!is_array($data))throw new \RuntimeException('weather_invalid');
    $local=farmLocalTime($data['TimeLocal']??null);$raw=$data['currentObservationTime']??null;
    if(!is_string($raw)||!preg_match('/\A([0-9]{1,2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (0?[1-9]|1[0-2]):([0-5][0-9]) (am|pm) (EDT|EST)\z/i',$raw,$parts))throw new \RuntimeException('weather_timestamp_invalid');
    $year=(int)$local->format('Y');$observed=null;
    foreach([$year,$year-1] as $candidate){
        $date=\DateTimeImmutable::createFromFormat('!j M g:i a T Y',$raw.' '.$candidate);
        if(!$date)continue;$ny=$date->setTimezone(new \DateTimeZone('America/New_York'));
        if($ny->format('j M g:i a T')!==((string)(int)$parts[1].' '.ucfirst(strtolower($parts[2])).' '.(int)$parts[3].':'.$parts[4].' '.strtolower($parts[5]).' '.strtoupper($parts[6])))continue;
        if($date->getTimestamp()<=$now&&$date->getTimestamp()>=$now-$cfg['max_age']){$observed=$date;break;}
    }
    if(!$observed)throw new \RuntimeException('weather_stale');
    $sunrise=farmLocalTime($data['sunriseTimeLocal']??null);$sunset=farmLocalTime($data['sunsetTimeLocal']??null);
    if($sunrise->format('Y-m-d')!==$local->format('Y-m-d')||$sunset->format('Y-m-d')!==$local->format('Y-m-d')||$sunrise>=$sunset)throw new \RuntimeException('weather_timestamp_invalid');
    $temp=$data['currentTemp']??null;
    if((!is_string($temp)&&!is_int($temp)&&!is_float($temp))||!preg_match('/\A-?(?:0|[1-9][0-9]{0,2})(?:\.[0-9])?\z/',(string)$temp)||(float)$temp< -150||(float)$temp>160)throw new \RuntimeException('weather_temperature_invalid');
    $clean=farmCondition($data['currentWeather']??null)+['version'=>1,'classification'=>'reported_farm_weather','temperature_f'=>(float)$temp,
        'observed_at'=>$observed->getTimestamp(),'valid_until'=>$observed->getTimestamp()+$cfg['max_age'],
        'sunrise_at'=>$sunrise->getTimestamp(),'sunset_at'=>$sunset->getTimestamp()];
    $clean['id']=hash('sha256',json_encode($clean,JSON_THROW_ON_ERROR));
    return $clean;
}
