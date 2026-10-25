<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/village.php';require __DIR__.'/../app/identity_schema.php';
try{
    $options=getopt('',['database:']);$db=LittleSparks\connect((string)($options['database']??''));$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    if($db->query('PRAGMA foreign_key_check')->fetch()||$db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Integrity check failed.');
    $identity=LittleSparks\identityEnabled($db);$count=(int)$db->query('SELECT COUNT(*) FROM residents')->fetchColumn();
    if($identity&&(int)$db->query('SELECT COUNT(*) FROM resident_identities')->fetchColumn()!==$count)throw new RuntimeException('Incomplete identity data.');
    $report=['schema_version'=>(int)$db->query('PRAGMA user_version')->fetchColumn(),'identity_revision'=>$identity?1:null,'residents'=>$count,
        'preserved_v5_sha256'=>LittleSparks\identityPreservationDigest($db),'identity_edits'=>$identity?(int)$db->query('SELECT COUNT(*) FROM identity_edits')->fetchColumn():0,
        'simulation_status'=>$identity?'paused_identity_runtime_pending':'legacy_configuration','integrity'=>'ok'];
    $db->commit();echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Identity inspection failed. Check the private database and integrity. No changes applied.\n");exit(1);}
