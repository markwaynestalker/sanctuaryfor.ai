<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/director_schema.php';

function villageManifest(PDO $db):array
{
    $hash=hash_init('sha256');$counts=[];
    foreach($db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll() as $schema)hash_update($hash,json_encode($schema,JSON_THROW_ON_ERROR));
    foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as $table){
        $name=$table['name'];if(!preg_match('/\A[a-z_][a-z0-9_]*\z/',$name))throw new \RuntimeException('Unknown table name.');
        hash_update($hash,$table['sql']);$count=0;
        foreach($db->query('SELECT * FROM "'.$name.'" ORDER BY rowid',PDO::FETCH_NUM) as $row){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));$count++;}
        $counts[$name]=$count;
    }
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    return ['schema_version'=>$version,'tables'=>$counts,'data_sha256'=>hash_final($hash),
        'preserved_v5_sha256'=>$version>=5?v5DirectorDigest($db):null,
        'identity_sha256'=>identityEnabled($db)?identityAdoptionDigest($db):null];
}
function backupVillage(PDO $db,string $output):array
{
    $output=databasePath($output,true);if(file_exists($output)||is_link($output))throw new \RuntimeException('Backup target must not exist.');
    if((int)$db->query('PRAGMA synchronous')->fetchColumn()<1)throw new \RuntimeException('Backup requires NORMAL or stronger synchronization.');
    $version=(int)$db->query('PRAGMA user_version')->fetchColumn();
    if($version>=3&&$db->query("SELECT 1 FROM ai_lease WHERE owner IS NOT NULL AND expires_at>=CAST(strftime('%s','now') AS INTEGER)")->fetchColumn())throw new \RuntimeException('Drain active lease before backup.');
    if($version>=4&&$db->query("SELECT 1 FROM simulation_opportunities WHERE outcome='pending' LIMIT 1")->fetchColumn())throw new \RuntimeException('Drain pending opportunities before backup.');
    // VACUUM INTO takes a consistent SQLite snapshot including WAL contents;
    // never copy a possibly live main file while omitting its sidecars.
    $before=villageManifest($db);$q=$db->prepare('VACUUM INTO ?');$q->execute([$output]);
    $copy=connect($output);$copy->exec('PRAGMA query_only=ON');$copy->beginTransaction();
    if($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$copy->query('PRAGMA foreign_key_check')->fetch())throw new \RuntimeException('Backup integrity verification failed.');
    $after=villageManifest($copy);$copy->commit();
    if($before!==$after)throw new \RuntimeException('Source changed during backup or preservation check failed. Stop all writers and try a new destination.');
    return $after+['backup_sha256'=>hash_file('sha256',$output)];
}
