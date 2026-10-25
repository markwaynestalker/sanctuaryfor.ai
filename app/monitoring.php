<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;

// CLI-only caller holds a query-only read transaction: one consistent snapshot.
function villageMonitoring(PDO $db,string $day):array
{
    $start=$day.'T00:00:00Z';$end=gmdate('Y-m-d\TH:i:s\Z',strtotime($start)+86400);
    $q=$db->prepare("SELECT COUNT(*) FROM conversations c JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed' WHERE c.created_at>=? AND c.created_at<?");$q->execute([$start,$end]);$conversations=(int)$q->fetchColumn();
    $q=$db->prepare("SELECT COUNT(*) FROM events WHERE kind NOT IN ('home_assigned','conversation','quiet_time') AND created_at>=? AND created_at<?");$q->execute([$start,$end]);$actions=(int)$q->fetchColumn();
    $q=$db->prepare("SELECT r.id,r.name,COUNT(o.id) AS considered_turns,
        COALESCE(SUM(o.outcome='conversation'),0) AS conversation_turns,
        COALESCE(SUM(o.outcome IN ('quiet','none')),0) AS quiet_or_none_turns,COALESCE(MAX(a.actions),0) AS world_actions
        FROM residents r LEFT JOIN simulation_opportunities o ON o.day=? AND (o.resident_a=r.id OR o.resident_b=r.id)
        LEFT JOIN (SELECT actor_id,COUNT(*) AS actions FROM events WHERE kind NOT IN ('home_assigned','conversation','quiet_time') AND created_at>=? AND created_at<? GROUP BY actor_id) a ON a.actor_id=r.id
        GROUP BY r.id ORDER BY considered_turns DESC,r.id LIMIT 40");$q->execute([$day,$start,$end]);
    $out=['conversations_today'=>$conversations,'world_actions_today'=>$actions,'resident_activity'=>$q->fetchAll(),'resident_activity_limit'=>40];
    $q=$db->prepare('SELECT COUNT(*) FROM simulation_opportunities WHERE day=?');$q->execute([$day]);$out['opportunities_today']=(int)$q->fetchColumn();
    $out['latest_encounter']=$db->query("SELECT c.id,c.created_at,CASE WHEN a.conversation_id IS NULL THEN 'scripted' ELSE 'openai' END AS provider
        FROM conversations c JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed'
        LEFT JOIN ai_conversations a ON a.conversation_id=c.id ORDER BY c.created_at DESC,c.id DESC LIMIT 1")->fetch()?:null;
    foreach(['openai','scripted'] as $provider){
        $out['latest_'.$provider.'_conversation']=$db->query("SELECT c.id,c.created_at FROM conversations c
            JOIN simulation_runs r ON r.id=c.run_id AND r.status='completed' LEFT JOIN ai_conversations a ON a.conversation_id=c.id
            WHERE a.conversation_id IS ".($provider==='openai'?'NOT ':'')."NULL ORDER BY c.created_at DESC,c.id DESC LIMIT 1")->fetch()?:null;
    }
    if(memoryEnabled($db)){
        foreach(['stories'=>['memory_stories','created_at'],'receipts'=>['memory_receipts','learned_at']] as $name=>[$table,$column]){
            $q=$db->prepare("SELECT COUNT(*) FROM $table WHERE $column>=? AND $column<?");$q->execute([$start,$end]);$out['new_'.$name.'_today']=(int)$q->fetchColumn();
        }
        $out['recent_transmissions']=[];
        foreach($db->query('SELECT * FROM memory_receipts WHERE hops>0 ORDER BY learned_at DESC,id DESC LIMIT 10')->fetchAll() as $receipt){
            $q=$db->prepare('SELECT * FROM memory_stories WHERE id=?');$q->execute([$receipt['story_id']]);$story=$q->fetch();
            if(!$story||!receiptChain($db,$receipt,$story))continue;
            $out['recent_transmissions'][]=['resident_id'=>$receipt['resident_id'],'heard_from_id'=>$receipt['heard_from_id'],
                'conversation_id'=>$receipt['via_conversation_id'],'hops'=>(int)$receipt['hops'],'learned_at'=>$receipt['learned_at']];
        }
    }
    return $out;
}
