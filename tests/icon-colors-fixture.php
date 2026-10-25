<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/../app/village.php';
$dir=$argv[1]??'';$mode=$argv[2]??'create';
if(!is_dir($dir)||!str_starts_with(basename($dir),'sparks-icon-colors-'))throw new RuntimeException('Disposable fixtures only.');
$path=$dir.'/village.sqlite';
if($mode==='create'){
    foreach(['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php'] as $script){
        $process=proc_open([PHP_BINARY,__DIR__.'/../scripts/'.$script,'--database='.$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Fixture migration failed: '.$error);
    }
    $db=LittleSparks\connect($path);
    foreach(LittleSparks\COLORS as $i=>$color){
        $input=['name'=>'Shade '.($i+1),'color'=>$color,'antenna'=>'orb','personality'=>'curious','website'=>'','requestId'=>str_pad((string)$i,32,'0',STR_PAD_LEFT)];
        $saved=LittleSparks\create($db,$input,'color-browser-'.$i,'color-network-'.$i,time()-100,true);
        if($saved['resident']['color']!==$color||LittleSparks\create($db,$input,'color-browser-'.$i,'color-network-'.$i,time(),true)['resident']['id']!==$saved['resident']['id'])throw new RuntimeException('Color persistence/replay failed.');
    }
}else{$db=LittleSparks\connect($path);}
$hash=hash_init('sha256');
hash_update($hash,json_encode($db->query("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(),JSON_THROW_ON_ERROR));
foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table){foreach($db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll() as $row)hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));}
echo json_encode(['version'=>(int)$db->query('PRAGMA user_version')->fetchColumn(),'digest'=>hash_final($hash),'colors'=>LittleSparks\COLORS,'count'=>(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";
