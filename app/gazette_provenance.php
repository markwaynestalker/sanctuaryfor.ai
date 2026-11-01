<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

/** Bounded public provenance metadata only, never article text or memory authority. */
function gazetteConversationExposureBatch(PDO $db,array $ids):array
{
    if(!$ids)return [];
    $ids=array_values(array_unique($ids));if(count($ids)>384)throw new \RuntimeException('Gazette provenance bound.');
    $q=$db->prepare('SELECT c.id,s.value FROM conversations c JOIN settings s ON s.name=\'director_gazette_context:\'||c.run_id WHERE c.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$out=[];
    foreach($q->fetchAll() as $row){
        $value=strlen($row['value'])<=8192?json_decode($row['value'],true,16):null;
        $public=['status'=>'provenance_unavailable','influence_verified'=>false];
        if(strlen($row['value'])<=8192&&is_array($value)&&($value['version']??null)===1&&($value['conversation_id']??null)===$row['id']
            &&is_string($value['edition_date']??null)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$value['edition_date'])&&is_string($value['publication_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/',$value['publication_sha256'])){
            $public=['status'=>'optional_context_supplied','edition_date'=>$value['edition_date'],'publication_sha256'=>$value['publication_sha256'],'influence_verified'=>false];
        }
        $out[$row['id']]=$public;
    }
    return $out;
}
