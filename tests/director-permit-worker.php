<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/director.php';
require_once __DIR__.'/../app/activity.php';
if(!str_starts_with(basename(dirname($argv[1]??'')),'little-sparks-memory-test-director-'))throw new RuntimeException('Disposable fixture only.');
putenv('OPENAI_API_KEY');$db=LittleSparks\connect($argv[1]);$r=$db->query('SELECT * FROM director_registry LIMIT 1')->fetch();
putenv('LITTLE_SPARKS_DIRECTOR_RUNTIME=agents');putenv('LITTLE_SPARKS_DIRECTOR_AGENT_ID='.$r['agent_id']);putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$r['fingerprint']);
if(($argv[2]??'')==='plan'){$p=LittleSparks\stagingPlan($db,gmdate('Y-m-d\TH:i:00\Z',time()-60),time()+300);$p['approved']=$p['production_isolation_attested']=true;$p['approval_reference']='OFFLINE-CONCURRENCY-ONLY';echo json_encode($p,JSON_THROW_ON_ERROR);exit;}
try{LittleSparks\StagingPermit::consume($db,json_decode((string)file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR));echo '{"accepted":true}';}catch(LittleSparks\AiGuard){echo '{"accepted":false}';}
