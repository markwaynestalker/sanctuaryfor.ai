<?php
declare(strict_types=1);
// Disposable identities and mocked providers only; never rewrite the owner's snapshot.
try{
    $fixture=narrativeFixture($itemsBase,$dir.'/personality-context.sqlite');$db=$fixture['db'];
    $descriptions=[str_repeat('Curious about unfamiliar ideas. ',40).'TAIL: stubborn, dryly sarcastic; may decline invitations.',
        str_repeat('Thoughtful and independently minded. ',36).'TAIL: kind but unwilling to agree merely to please.'];
    foreach($fixture['pair'] as $i=>$actor)$db->prepare('UPDATE resident_identities SET description=? WHERE resident_id=?')->execute([$descriptions[$i],$actor['id']]);
    $digest=narrativeDigest($db);
    foreach([1,800,2000] as $oldExcerptLimit){
        putenv('LITTLE_SPARKS_DIRECTOR_DESCRIPTION_CONTEXT='.$oldExcerptLimit);
        putenv('LITTLE_SPARKS_MEMORY_CONTEXT_BYTES=8192');
        $context=LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],LittleSparks\directorConfiguration())['context'];
        foreach($context['participants'] as $i=>$p){
            check($p['owner_description']===['text'=>$descriptions[$i],'is_excerpt'=>false],'Complete owner text, including defining tail traits, survives old excerpt configuration');
            check(!isset($p['legacy_category'],$p['saved_preferences']),'Owner identity is not replaced by a generic category');
        }
        check($context['encounter_guidance']===LittleSparks\directorEncounterGuidance(),'Personality and variety guidance is fresh encounter context');
        check(strlen(json_encode($context,JSON_UNESCAPED_UNICODE))<=8192,'Complete identity and guidance respect total context bytes');
        check((bool)$context['shared_recent_conversations'],'Long descriptions retain supported recent pair dialogue when it fits');
        check(narrativeDigest($db)===$digest,'Preparing full identities preserves every stored row');
    }
    // A 4,000-character stored description remains complete even if an owner later
    // lowers the edit limit. Context admission uses bytes, not an edit-time rewrite.
    foreach($fixture['pair'] as $i=>$actor)$db->prepare('UPDATE resident_identities SET description=? WHERE resident_id=?')->execute([$i===0?str_repeat('x',3995).'TAIL!':'A short independent personality. TAIL!',$actor['id']]);
    putenv('LITTLE_SPARKS_MEMORY_CONTEXT_BYTES=10000');
    $maximum=LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],LittleSparks\directorConfiguration())['context'];
    check(strlen($maximum['participants'][0]['owner_description']['text'])===4000&&str_ends_with($maximum['participants'][1]['owner_description']['text'],'TAIL!'),'Storage-maximum ASCII identities remain full at a compatible byte limit');
    // Multi-byte core cannot fit: refuse, never slice either personality or send.
    foreach($fixture['pair'] as $actor)$db->prepare('UPDATE resident_identities SET description=? WHERE resident_id=?')->execute([str_repeat('界',2000),$actor['id']]);
    $before=narrativeDigest($db);
    try{LittleSparks\prepareDirectorEncounter($db,$fixture['pair'],['none'=>null],$fixture['claim'],LittleSparks\directorConfiguration());throw new LogicException('Oversized full identities accepted');}
    catch(LittleSparks\AiGuard $e){check($e->reason==='configuration_invalid','Oversized mandatory Unicode core fails explicitly');}
    check(narrativeDigest($db)===$before,'Failed context admission never edits identities or history');
    putenv('LITTLE_SPARKS_DIRECTOR_DESCRIPTION_CONTEXT');putenv('LITTLE_SPARKS_MEMORY_CONTEXT_BYTES=8192');
    check(LittleSparks\EVENT_TEXT['conversation']==='had a conversation','Journal event wording does not assert friendliness');
    echo "PASS complete personality tails and maximum stored identities, legacy excerpt compatibility, Unicode byte refusal, fresh variety guidance and neutral journal label (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
