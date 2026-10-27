<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/identity_schema.php';require __DIR__.'/../app/activity.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $action,int $status):void{try{$action();}catch(LittleSparks\Problem $e){check($e->status===$status,'Wrong rejection');return;}throw new RuntimeException('Expected rejection');}
$dir=$argv[1]??'';if(!str_starts_with(basename($dir),'sparks-icon-colors-'))throw new RuntimeException('Disposable fixtures only.');
$db=LittleSparks\connect($dir.'/village.sqlite');$now=time();
if(($argv[2]??'')==='concurrent'){
    $owner=hash_hmac('sha256','color-browser-0',LittleSparks\secret($db));$token=hash_hmac('sha256',$owner.':'.str_repeat('0',32),LittleSparks\secret($db));
    try{$r=LittleSparks\editIdentity($db,['token'=>$token,'description'=>'A thoughtful gardener who loves lavender.','color'=>'violet','requestId'=>str_repeat($argv[3],32),'expectedRevision'=>0],'concurrent-'.$argv[3],$now);echo json_encode(['status'=>200,'revision'=>$r['resident']['identity_version']]);}catch(LittleSparks\Problem $e){echo json_encode(['status'=>$e->status]);}exit;
}
if(($argv[2]??'')==='inspect'){echo json_encode(['version'=>(int)$db->query('PRAGMA user_version')->fetchColumn(),'edits'=>(int)$db->query('SELECT COUNT(*) FROM identity_edits')->fetchColumn()]);exit;}
if(($argv[2]??'')==='daily'){
    $owner=hash_hmac('sha256','color-browser-1',LittleSparks\secret($db));$request=str_pad('1',32,'0',STR_PAD_LEFT);$token=hash_hmac('sha256',$owner.':'.$request,LittleSparks\secret($db));
    $input=['token'=>$token,'description'=>'Loves quiet clouds.','color'=>'sky','expectedRevision'=>0,'requestId'=>str_repeat('1',32)];
    foreach(['','01','-1','1.0','25','unlimited'] as $bad){putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY='.$bad);reject(fn()=>LittleSparks\editIdentity($db,$input,'daily-settings',$now),503);}
    putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY=24');check(LittleSparks\identityConfiguration()['daily_edits']===24,'Absolute edit ceiling');
    putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY=1');$saved=LittleSparks\editIdentity($db,$input,'daily-check',$now);check($saved['resident']['identity_version']===1,'First allowed edit');
    check(LittleSparks\editIdentity($db,$input,'daily-check',$now)['replayed'],'Retry does not spend edit allowance');
    $second=array_replace($input,['expectedRevision'=>1,'requestId'=>str_repeat('2',32)]);reject(fn()=>LittleSparks\editIdentity($db,$second,'daily-check',$now),429);
    putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY=2');check(LittleSparks\editIdentity($db,$second,'daily-check',$now)['resident']['identity_version']===2,'Environment-only limit change');
    echo json_encode(['daily_checks'=>'passed']);exit;
}
// Seed genuine saved legacy activity; never use a live transport.
putenv('LITTLE_SPARKS_SIMULATION_ENABLED=1');putenv('LITTLE_SPARKS_AI_ENABLED=0');
for($i=0;$i<12;$i++)LittleSparks\simulateOpportunity($db,gmdate('Y-m-d\TH:i:00\Z',$now-3600+$i*60),false,null,$now);
// This fixture tests preservation, not the planner's hash-based choice of
// conversation/action/quiet/none. Seed one known validated legacy exchange so
// twelve legitimate non-conversation outcomes cannot make CI fail at random.
$seed=LittleSparks\claimActivity($db,gmdate('Y-m-d\TH:i:00\Z',$now-1200),$now);$seedChoices=['none'=>null];
check(count($seed['pair'])===2,'Two fixture residents available for saved history');
$seedContext=LittleSparks\prepareMemoryEncounter($db,$seed['pair'],$seedChoices);
$seedProposal=LittleSparks\composeMemoryProposal($db,['outcome'=>'conversation','topic'=>'welcome','first'=>'I enjoy the garden light.','second'=>'Shall we take a quiet walk?','world_action'=>'none','story'=>'none'],$seed['pair'],$seedContext);
$seedResult=LittleSparks\applyActivity($db,$seed,$seedProposal,$seedChoices,$now);check($seedResult['status']==='completed','Deterministic legacy history seed');
check((int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn()>0,'Populated history');
$before=LittleSparks\identityPreservationDigest($db);
// A consistent private backup includes committed WAL history; migrate only a copy.
$db->exec('PRAGMA journal_mode=WAL');$backup=$dir.'/pre-identity.sqlite';$q=$db->prepare('VACUUM INTO ?');$q->execute([$backup]);$copy=LittleSparks\connect($backup);
check($before===LittleSparks\identityPreservationDigest($copy)&&$copy->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&!$copy->query('PRAGMA foreign_key_check')->fetch(),'Private backup restore verification');
$copy->exec('BEGIN IMMEDIATE');LittleSparks\migrateIdentitySchema($copy);$copy->exec('COMMIT');check($before===LittleSparks\identityPreservationDigest($copy),'Backup can rehearse migration without touching source');$copy=null;
$db->exec('PRAGMA user_version=6');try{LittleSparks\migrateIdentitySchema($db);throw new RuntimeException('V6 must be refused');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'requires V5'),'Draft V6 is not a compatible input');}$db->exec('PRAGMA user_version=5');
// Roll back a complete add-on transaction and prove no trace remains.
$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateIdentitySchema($db);$db->exec('ROLLBACK');
check(!LittleSparks\identityEnabled($db)&&$before===LittleSparks\identityPreservationDigest($db),'Migration rollback preserves all V5 data/schema');
// Pending work and unknown configuration fail before adding a table.
$db->exec("UPDATE simulation_opportunities SET outcome='pending' WHERE id=(SELECT id FROM simulation_opportunities LIMIT 1)");
$db->exec('BEGIN IMMEDIATE');reject(fn()=>LittleSparks\migrateIdentitySchema($db),409);$db->exec('ROLLBACK');
$db->exec("UPDATE simulation_opportunities SET outcome='none' WHERE outcome='pending'");$before=LittleSparks\identityPreservationDigest($db);
putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS=unlimited');$db->exec('BEGIN IMMEDIATE');reject(fn()=>LittleSparks\migrateIdentitySchema($db),503);$db->exec('ROLLBACK');putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS');
$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateIdentitySchema($db);$db->exec('COMMIT');
check($before===LittleSparks\identityPreservationDigest($db),'Every preexisting table, schema, credential and ledger preserved');
check((int)$db->query('PRAGMA user_version')->fetchColumn()===5&&LittleSparks\identityEnabled($db),'V5 add-on marker');
$db->exec('BEGIN IMMEDIATE');LittleSparks\migrateIdentitySchema($db);$db->exec('COMMIT');check($before===LittleSparks\identityPreservationDigest($db),'Repeat migration harmless');
$residents=LittleSparks\residents($db,0)['residents'];check(count($residents)===24&&$residents[0]['description']===''&&$residents[0]['personality']==='curious','No invented descriptions');
$owner=hash_hmac('sha256','color-browser-0',LittleSparks\secret($db));$token=hash_hmac('sha256',$owner.':'.str_repeat('0',32),LittleSparks\secret($db));
$resident=LittleSparks\revisit($db,$token,'old-owner',$now);check($resident['name']==='Shade 1'&&$resident['color']==='mint','Original private links preserved');
$old=['name'=>'Shade 1','color'=>'mint','antenna'=>'orb','personality'=>'curious','website'=>'','requestId'=>str_repeat('0',32)];check(LittleSparks\create($db,$old,'color-browser-0','old-owner',$now,true)['replayed'],'Legacy creation retry remains idempotent');
$edits=['token'=>$token,'description'=>'A kind gardener','color'=>'rose','requestId'=>str_repeat('a',32),'expectedRevision'=>0];
foreach(['','<script>alert(1)</script>',"hidden\u{200B}text",str_repeat('x',2001),'sk-testcredential123456',"bad\x01text","bad\xFF"] as $value)reject(fn()=>LittleSparks\editIdentity($db,array_replace($edits,['description'=>$value]),'validation',$now),422);
check(LittleSparks\validateDescription("静かな庭師 ❤️\nLoves lavender.")!=='' ,'Unicode and newlines');
foreach(['-1','01','1.5','2001x','4001',''] as $bad){putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS='.$bad);reject(fn()=>LittleSparks\validateDescription('kind'),503);}putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS=4000');check(strlen(LittleSparks\validateDescription(str_repeat('x',4000)))===4000,'Absolute maximum');putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS=3');reject(fn()=>LittleSparks\validateDescription('kind'),422);putenv('LITTLE_SPARKS_PERSONALITY_MAX_CHARACTERS');
putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY=0');reject(fn()=>LittleSparks\editIdentity($db,$edits,'paused-edits',$now),429);putenv('LITTLE_SPARKS_IDENTITY_EDITS_PER_DAY');
$plain=LittleSparks\identityPreservationDigest($db);$rejectTransport=new class implements LittleSparks\AiTransport{public function post(string $endpoint,array $body,int $deadline):array{throw new RuntimeException('No model request allowed');}};
foreach([LittleSparks\simulateOpportunity($db,gmdate('Y-m-d\TH:i:00\Z',$now-60),true,$rejectTransport),LittleSparks\simulateDay($db,gmdate('Y-m-d')),LittleSparks\simulateOptionalAiDay($db,gmdate('Y-m-d'),true,$rejectTransport)] as $paused)check($paused['reason']==='identity_runtime_pending','All runner entry points paused');
check(LittleSparks\claimActivity($db,gmdate('Y-m-d\TH:i:00\Z',$now-60),$now)['replay']['status']==='paused','Claim pause under transaction');
try{LittleSparks\reserveAi($db,'blocked',[],[],$now);throw new RuntimeException('Reservation must block');}catch(LittleSparks\AiGuard $e){check($e->reason==='migration_required','Direct reservations blocked');}
check($plain===LittleSparks\identityPreservationDigest($db),'No accounting/world mutations while paused');
check(!$db->query("SELECT 1 FROM sqlite_master WHERE name LIKE 'director_%' OR name IN ('resident_states','resident_goals')")->fetchColumn(),'No unfinished Director schema');
echo json_encode(['legacy_id'=>$resident['id'],'legacy_token'=>$token,'checks'=>'V5 preservation, rollback, repeat migration, original links/retries, validation/config bounds, all simulation entry points paused, no reservations or Director schema']);
