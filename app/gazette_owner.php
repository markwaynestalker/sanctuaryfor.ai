<?php
declare(strict_types=1);
namespace LittleSparks;

/** The authenticated adapter supplies this root-owned one-shot authorization. */
function validateGazetteAuthorization(array $job,string $date,bool $preview,int $cost,int $now):void
{
    editorialKeys($job,['id','date','preview','expires_at','max_microusd','release']);
    if(!is_string($job['id'])||preg_match('/\A[a-f0-9]{32}\z/',$job['id'])!==1||$job['date']!==$date||$job['preview']!==$preview
        ||!is_int($job['expires_at'])||$job['expires_at']<=$now||$job['expires_at']>$now+600
        ||!is_int($job['max_microusd'])||$cost>$job['max_microusd']||$job['max_microusd']<1
        ||!is_string($job['release'])||preg_match('/\A[a-f0-9]{40}\z/',$job['release'])!==1)throw new \RuntimeException('Invalid owner generation authorization.');
}
