<?php
declare(strict_types=1);
// Reproducible synthetic history. No provider, credentials or production rows.
function narrativeFixture(string $base,string $path):array
{
    copy($base,$path);$db=LittleSparks\connect($path);$ids=$db->query('SELECT id FROM residents ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $descriptions=['OWNER_GEOLOGY: I enjoy rocks, gentle humour and discovering new interests with friends.','OWNER_MUSIC: I enjoy melodies and thoughtful questions, and may change my mind.'];
    foreach($ids as $i=>$id){
        $db->prepare('UPDATE resident_identities SET description=? WHERE resident_id=?')->execute([$descriptions[$i],$id]);
        $db->prepare("INSERT INTO resident_preferences VALUES (?,'stargazing','thoughtful')")->execute([$id]);
        $q=$db->prepare('SELECT slot FROM home_plots WHERE resident_id=?');$q->execute([$id]);$plot=LittleSparks\plotCoordinates((int)$q->fetchColumn());
        $db->exec('BEGIN IMMEDIATE');
        foreach(['plant_tree','plant_flowers'] as $j=>$action)LittleSparks\performResidentAction($db,$id,['action'=>$action,'x'=>$plot['x']+$j,'y'=>$plot['y']+3,'target'=>null],'2026-10-20T12:00:0'.$j.'Z','narrative-action:'.$i.':'.$j,'visitor');
        $db->exec('COMMIT');
        foreach(['active','proposed'] as $j=>$status)$db->prepare('INSERT INTO resident_goals VALUES (?,?,?,?,NULL,?)')->execute([str_pad(dechex(100+$i*2+$j),32,'0',STR_PAD_LEFT),$id,$status==='active'?'ACTIVE_GOAL: Explore an unfamiliar idea with a friend.':'PROPOSED_GOAL: Consider a new hobby; this has not happened.',$status,'2026-10-21T12:00:00Z']);
    }
    $conversations=[];
    foreach([
        ['2026-10-22T12:00:00Z','I enjoy stargazing, but I might try listening to a new melody.','Perhaps the stars could inspire a garden song.'],
        ['2026-10-23T12:00:00Z','I think gardening leaves room for surprises beyond our usual interests.','A new question might lead us somewhere unexpected.']
    ] as [$at,$first,$second])$conversations[]=savedEncounter($db,$ids[0],$ids[1],$at,$first,$second);
    foreach($ids as $i=>$id)$db->prepare("INSERT INTO director_notes VALUES (?,?,?,?,0,?,'interpretation',?)")->execute([str_pad(dechex(200+$i),32,'0',STR_PAD_LEFT),$id,$ids[1-$i],$conversations[1],'I think gardening leaves room for surprises beyond our usual interests.','2026-10-23T12:00:00Z']);
    savedEncounter($db,$ids[0],$ids[1],'2026-10-24T12:00:00Z','PRIVATE_CONTEXT_CANARY','Private reply.',null,'private');
    $failed=savedEncounter($db,$ids[0],$ids[1],'2026-10-25T12:00:00Z','FAILED_CONTEXT_CANARY','Failed reply.');
    $db->prepare("UPDATE simulation_runs SET status='failed' WHERE id=?")->execute([$failed]);
    $pair=array_map(fn($id)=>['id'=>$id,'name'=>'Synthetic resident'],$ids);
    return ['db'=>$db,'pair'=>$pair,'descriptions'=>$descriptions,'conversations'=>$conversations,
        'claim'=>['id'=>'narrative-context-fixture','created_at'=>'2026-10-26T12:00:00Z']];
}
function narrativeContextStats(array $context):array
{
    return ['bytes'=>strlen(json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
        'shared'=>count($context['shared_recent_conversations']??[]),
        'participant_recent'=>array_map(fn($p)=>count($p['recent_conversations']),$context['participants']),
        'goals'=>array_map(fn($p)=>count($p['goals']),$context['participants']),
        'memories'=>array_map(fn($p)=>count($p['memories']),$context['participants'])];
}
