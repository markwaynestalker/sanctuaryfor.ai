<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

const CONVERSATION_MESSAGE_CEILING=64;
function conversationConfiguration():array
{
    $min=boundedEnvironmentInteger('LITTLE_SPARKS_CONVERSATION_MIN_MESSAGES',2,2,CONVERSATION_MESSAGE_CEILING);
    $max=boundedEnvironmentInteger('LITTLE_SPARKS_CONVERSATION_MAX_MESSAGES',16,2,CONVERSATION_MESSAGE_CEILING);
    if($min>$max)throw new AiGuard('configuration_invalid');
    return ['min'=>$min,'max'=>$max];
}
function multiMessageInstalled(PDO $db):bool
{
    $q=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='conversation_message_meta'");
    if(!$q->fetchColumn())return false;
    if((int)$db->query('SELECT revision FROM conversation_message_meta WHERE singleton=1')->fetchColumn()!==1)throw new \RuntimeException('Unsupported conversation message capability.');
    return true;
}
function messageRelation(PDO $db):string{return multiMessageInstalled($db)?'all_conversation_messages':'messages';}
function noteRelation(PDO $db):string{return multiMessageInstalled($db)?'all_director_notes':'director_notes';}
function conversationMessageBatch(PDO $db,array $ids):array
{
    if(!$ids)return [];$table=messageRelation($db);$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT m.conversation_id,m.sequence,m.resident_id,substr(r.name,1,64) AS name,m.body AS text FROM $table m JOIN residents r ON r.id=m.resident_id WHERE m.conversation_id IN ($marks) ORDER BY m.conversation_id,m.sequence");$q->execute($ids);$out=[];
    foreach($q->fetchAll() as $m){$id=$m['conversation_id'];unset($m['conversation_id']);$out[$id][]=$m;}
    return $out;
}
