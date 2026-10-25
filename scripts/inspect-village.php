<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/maintenance.php';
try{
    $o=getopt('',['database:','matches-v5:']);$db=LittleSparks\connect((string)($o['database']??''));$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$db->query('PRAGMA foreign_key_check')->fetch())throw new RuntimeException('Integrity check failed.');
    $manifest=LittleSparks\villageManifest($db);
    if(isset($o['matches-v5'])){
        $baseline=LittleSparks\databasePath((string)$o['matches-v5']);if(filesize($baseline)>1048576)throw new RuntimeException('Oversized baseline.');
        $before=json_decode((string)file_get_contents($baseline),true,16,JSON_THROW_ON_ERROR);
        if(($before['schema_version']??null)!==5||!is_string($before['preserved_v5_sha256']??null)||!hash_equals($before['preserved_v5_sha256'],$manifest['preserved_v5_sha256']??''))throw new RuntimeException('V5 preservation failed.');
        $manifest['matches_v5']=true;
    }
    echo json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";$db->commit();
}catch(Throwable){fwrite(STDERR,"Read-only inspection failed. Check the private database and its integrity.\n");exit(1);}
