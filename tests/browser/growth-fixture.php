<?php
// Disposable synthetic fixture for the optional #63 browser review. Never uses live data.
declare(strict_types=1);
require __DIR__.'/../../app/village.php';
require __DIR__.'/../../app/activity.php';
$db=LittleSparks\connect($argv[1]);$count=(int)$argv[2];
if($count<1||$count>41||(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()!==0)throw new RuntimeException('Use an empty disposable fixture.');
$forms=LittleSparks\avatarRegistry()['forms'];$now=time();
for($i=0;$i<$count;$i++){
    $form=$forms[($i*7)%count($forms)];
    LittleSparks\create($db,['name'=>$i===39?'Élodie':($i===0?'WWWWWWWWWWWWWWWWWWWWWWWW':'Synthetic Neighbor '.($i+1)),
        'color'=>'mint','antenna'=>'orb','description'=>'Synthetic browser-review resident; no live AI.',
        'requestId'=>substr(hash('sha256','growth:'.$i),0,32),'website'=>'',
        'appearance'=>['form'=>$form['id'],'primary_color'=>['#111111','#FFFFFF','#F294B8','#12ABCD'][$i%4],'features'=>in_array('antenna',$form['features'],true)?['antenna'=>'sprout']:[]]],
        'growth-browser-'.$i,'growth-network-'.$i,$now-$count*2+$i*2,true);

// Save the first pair before growing the fixture, so their exact exchange is
// always visible in the first neighborhood; this does not change selection.
// Original scripted fixture speech; no Director registration, credential or provider.
if($i===1){
    $claim=LittleSparks\claimActivity($db,gmdate('Y-m-d\TH:i:00\Z',$now-600),$now);
    LittleSparks\applyActivity($db,$claim,['outcome'=>'conversation','topic'=>'garden','first'=>'A saved thought for this synthetic review.','second'=>'A second saved thought, kept in its original words.','world_action'=>'none'],['none'=>null],$now);
}

}
echo json_encode(['residents'=>$count]);
