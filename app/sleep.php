<?php
declare(strict_types=1);
namespace LittleSparks;

function villageSleepConfiguration():array
{
    $start=boundedEnvironmentInteger('LITTLE_SPARKS_SLEEP_START_HOUR',23,0,23);
    $end=boundedEnvironmentInteger('LITTLE_SPARKS_SLEEP_END_HOUR',7,0,23);
    if($start===$end)throw new Problem(503,'Village sleep hours must differ.');
    return ['enabled'=>boundedEnvironmentInteger('LITTLE_SPARKS_SLEEP_ENABLED',0,0,1)===1,
        'start_hour'=>$start,'end_hour'=>$end,'time_zone'=>'America/New_York'];
}
function villageSleepingAt(int $now,?array $configuration=null):bool
{
    $c=$configuration??villageSleepConfiguration();
    if(!$c['enabled'])return false;
    $hour=(int)(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($c['time_zone']))->format('G');
    return $c['start_hour']<$c['end_hour']?($hour>=$c['start_hour']&&$hour<$c['end_hour']):($hour>=$c['start_hour']||$hour<$c['end_hour']);
}
function villageSleepState(int $now,?array $configuration=null):array
{
    $c=$configuration??villageSleepConfiguration();$sleeping=villageSleepingAt($now,$c);$next=null;
    // Scan real UTC hour boundaries: New York's skipped/repeated DST hours must
    // follow the actual clock rather than a guessed wall-time duration.
    if($c['enabled'])for($hour=intdiv($now,3600)*3600+3600;$hour<=$now+48*3600;$hour+=3600){
        if(villageSleepingAt($hour,$c)!==$sleeping){$next=$hour;break;}
    }
    return $c+['sleeping'=>$sleeping,'server_time'=>$now,'next_transition_at'=>$next];
}
function villagePublicSleepState(\PDO $db,int $now,?array $tick=null):array
{
    // FPM need not read the private CLI environment/credential channel. The
    // scheduler publishes only this harmless policy alongside its existing tick.
    $tick??=publishedVillageTick($db);
    $c=$tick['sleep_policy']??null;
    if($c===null)return villageSleepState($now);
    if(!is_array($c)||!is_bool($c['enabled']??null)||($c['time_zone']??null)!=='America/New_York'
        ||!is_int($c['start_hour']??null)||!is_int($c['end_hour']??null)||$c['start_hour']<0||$c['start_hour']>23
        ||$c['end_hour']<0||$c['end_hour']>23||$c['start_hour']===$c['end_hour'])throw new Problem(503,'Village sleep policy is invalid.');
    return villageSleepState($now,array_intersect_key($c,array_flip(['enabled','start_hour','end_hour','time_zone'])));
}
