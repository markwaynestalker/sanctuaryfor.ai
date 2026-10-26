<?php
declare(strict_types=1);
require __DIR__.'/director-context-fixture.php';
function narrativeDigest(PDO $db):string
{
    $all=[];foreach($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll() as $table){
        $all[]=$table;$all[]=$db->query('SELECT * FROM "'.str_replace('"','""',$table['name']).'" ORDER BY rowid')->fetchAll();
    }return hash('sha256',json_encode($all,JSON_THROW_ON_ERROR));
}
try{
    $fixture=narrativeFixture($itemsBase,$dir.'/narrative-context.sqlite');$db=$fixture['db'];$cfg=LittleSparks\directorConfiguration();$digest=narrativeDigest($db);
    $baseline=json_decode(file_get_contents(__DIR__.'/fixtures/director-context-baseline.json'),true,16,JSON_THROW_ON_ERROR);$measurements=[];
    foreach([10000,8192,4096] as $limit){
        putenv('LITTLE_SPARKS_MEMORY_CONTEXT_BYTES='.$limit);
        $prepared=LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],$cfg);$context=$prepared['context'];$raw=json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $measurements[$limit]=narrativeContextStats($context);
        check(strlen($raw)<$baseline[(string)$limit]['bytes'],'Synthetic context is smaller than the frozen pre-change baseline at '.$limit.' bytes');
        check(strlen($raw)<=$limit,'Configured context ceiling is respected');
        check(!str_contains($raw,'PRIVATE_CONTEXT_CANARY')&&!str_contains($raw,'FAILED_CONTEXT_CANARY'),'Private and unsuccessful conversations never enter context');
        check($context['context_version']==='sqlite-v6-2','New input projection has an explicit version');
        check((bool)$context['shared_recent_conversations'],'Recent shared dialogue survives payload pressure');
        $latest=$context['shared_recent_conversations'][0];check($latest['id']===$fixture['conversations'][1],'Latest actual shared encounter is retained');
        check($latest['messages'][0]['text']==='I think gardening leaves room for surprises beyond our usual interests.','Retained dialogue is verbatim, not summarized or rewritten');
        foreach($context['participants'] as $i=>$p){
            check($p['id']===$fixture['pair'][$i]['id']&&$p['owner_description']['text']===$fixture['descriptions'][$i],'Owner description and immutable identity survive trimming');
            check(!isset($p['legacy_category'])&&!isset($p['saved_preferences']),'Legacy categories/preferences cannot override owner descriptions');
            check((bool)array_filter($p['goals'],fn($g)=>$g['status']==='active'),'An active goal survives the fixture context limit');
            check(!$p['recent_conversations'],'Pair exchanges appear once, not duplicated under both participants');
            foreach($p['home'] as $object)check(isset($object['count'])&&!isset($object['x'])&&!isset($object['y']),'Home context contains actual inventory, not unnecessary map coordinates');
            foreach($p['memories'] as $m){
                check(isset($m['source']['status'],$m['source']['at'],$m['knowledge']['hops']),'Compact memories retain confidence, time and knowledge attribution');
                check(!isset($m['knowledge']['holder_id'])&&!isset($m['source']['event_id']),'Implied holder and administrative provenance stay server-side');
                if(isset($m['source_alias'])){$source=$prepared['sources'][$m['source_alias']];check(LittleSparks\memorySource($db,$source['story'])===$source['source']&&(bool)LittleSparks\receiptChain($db,$source['receipt'],$source['story']),'Every retained alias maps to full independently validated SQLite provenance');}
            }
        }
        foreach($prepared['sources'] as $alias=>$source)check(str_contains($raw,'"source_alias":"'.$alias.'"'),'Trimmed aliases cannot remain offered in the source map');
        check($digest===narrativeDigest($db),'Context preparation does not mutate any table, credential, history or ledger');
    }
    echo 'MEASURE synthetic narrative context bytes: '.json_encode($measurements,JSON_THROW_ON_ERROR)." (no token/billing claim)\n";
    putenv('LITTLE_SPARKS_MEMORY_CONTEXT_BYTES=8192');
    $neutral=LittleSparks\recallMemories($db,$fixture['pair'][1]['id'],$fixture['pair'][0]['id'],false);
    $legacyRecall=LittleSparks\recallMemories($db,$fixture['pair'][1]['id'],$fixture['pair'][0]['id']);
    check($neutral[0]['story']['topic']==='garden'&&$legacyRecall[0]['story']['topic']==='stars','Owner-described residents are not biased toward a stale legacy interest; V5 default recall is unchanged');
    $db->prepare("UPDATE resident_identities SET description='' WHERE resident_id=?")->execute([$fixture['pair'][0]['id']]);
    $legacy=LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],$cfg)['context'];
    check(isset($legacy['participants'][0]['legacy_category'],$legacy['participants'][0]['saved_preferences'])&&!isset($legacy['participants'][1]['saved_preferences']),'Only residents without owner descriptions retain legacy characterization');
    check(LittleSparks\preferences($db,$fixture['pair'][1]['id'])['interest']==='stargazing','Suppressed legacy preferences remain preserved in SQLite');
    // Retrieval remains resident-specific: a third resident's encounter is not common knowledge.
    $third=str_repeat('c',32);$db->prepare("INSERT INTO residents VALUES (?,'Third fixture','mint','orb','curious','2026-10-20T00:00:00Z',?,?,?,?)")->execute([$third,hash('sha256','third-owner'),str_repeat('c',32),str_repeat('c',64),hash('sha256','third-token')]);
    LittleSparks\identityForNewResident($db,$third,'A third independent resident.','mint','2026-10-20T00:00:00Z');
    $thirdConversation=savedEncounter($db,$fixture['pair'][0]['id'],$third,'2026-10-26T00:00:00Z','THIRD_PARTY_CONTEXT_CANARY: a thought about music.','An independent reply.');
    $specific=LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],$cfg)['context'];
    check($specific['participants'][0]['recent_conversations'][0]['id']===$thirdConversation&&!$specific['participants'][1]['recent_conversations'],'Other encounters stay attached to their legitimate participant');
    check(!in_array($thirdConversation,array_column($specific['shared_recent_conversations'],'id'),true),'Third-party history is never mislabeled as shared firsthand history');
    // A data-only projection does not reset the current pair session or fingerprint.
    $registered=LittleSparks\registeredDirector($db,$cfg);$session=LittleSparks\directorSession($db,$registered,$fixture['pair'],'agents',time());
    LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],$cfg);
    check(LittleSparks\directorSession($db,$registered,$fixture['pair'],'agents',time())['id']===$session['id'],'Fresh context does not reset or replace the bounded pair session');
    $tooLarge=$specific;foreach($tooLarge['participants'] as &$p)$p['owner_description']['text']=str_repeat('é',800);unset($p);
    try{LittleSparks\trimDirectorContext($tooLarge,2048);throw new LogicException('Unbounded core context accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Mandatory context that cannot fit still fails closed, without truncating owner text');}
    // A reviewed full instruction export changes the fingerprint normally, never by editing hashes or sessions.
    $amended=$export;$amended['instructions']='OWNER_REVIEWED_NARRATIVE_UPDATE_CANARY';$registration=LittleSparks\registerDirector($db,$amended);
    check($registration['fingerprint']!==$registered['fingerprint'],'Reviewed instruction changes generate their own registration fingerprint');
    putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$registration['fingerprint']);$newRegistered=LittleSparks\registeredDirector($db,LittleSparks\directorConfiguration());
    check($newRegistered['instructions']===$amended['instructions']&&LittleSparks\directorSession($db,$newRegistered,$fixture['pair'],'agents',time())['id']!==$session['id'],'Registered instruction revision scopes new continuity without rewriting old sessions');
    putenv('LITTLE_SPARKS_DIRECTOR_CONFIG_SHA256='.$cfg['fingerprint']);
    echo "PASS narrative context: canonical shared history, latest supported encounters after failed/private rows, owner authority, compact provenance/inventory, active goals and recent dialogue under pressure, legacy fallback, resident knowledge boundaries, read-only preservation and unchanged session scope\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
