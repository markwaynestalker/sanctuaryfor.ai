<?php
declare(strict_types=1);
// Included after recorded-item fixtures; no provider requests or real resident data.
try{
    $schema=LittleSparks\directorSchema(['none'=>null]);$textRule=$schema['properties']['messages']['items']['properties']['text'];
    $guidance=$textRule['description'];
    check($textRule['minLength']===1&&$textRule['maxLength']===160,'Short replies remain valid; 160 is still the hard ceiling');
    check(str_contains($guidance,'100–130')&&str_contains($guidance,'complete, natural sentences')&&str_contains($guidance,'not a target'),'Contract encourages complete concise speech without targeting the ceiling');
    foreach(['No, I disagree.','I would rather be left alone.','That plan sounds ridiculous.','Perhaps','Shall we?','“A lovely idea.”','Maybe…','Lovely 🌻',str_repeat('é',159),str_repeat('é',160),str_repeat('x',155).' and'] as $line){
        // Deliberately unfinished wording is not rewritten or judged by a brittle punctuation heuristic.
        $proposal=$recorded;$proposal['notes']=[];$proposal['messages'][0]['text']=$line;
        $parsed=LittleSparks\parseDirectorProposal($proposal,$pair,['none'=>null]);
        check($parsed['messages'][0]['text']===$line,'Natural endings and in-bounds Unicode remain byte-exact');
    }
    $proposal['messages'][0]['text']=str_repeat('é',161);
    try{LittleSparks\parseDirectorProposal($proposal,$pair,['none'=>null]);throw new LogicException('Overlong dialogue accepted');}catch(LittleSparks\AiGuard $e){check($e->reason==='invalid_proposal','161 Unicode characters still fail without truncation');}

    $path=$dir.'/concise-dialogue.sqlite';copy($itemsBase,$path);$d=LittleSparks\connect($path);
    $proposal=$recorded;$proposal['notes']=[];
    $proposal['messages'][0]['text']='Could we put another sign beside the flowers?';
    $proposal['messages'][1]['text']='No. One sign is plenty; I want to see the flowers, not a committee notice.';
    foreach([time()-120,time()-60] as $index=>$when){
        $mock=new RecordedItemTransport($proposal,'mixed');$opportunity=gmdate('Y-m-d\TH:i:00\Z',$when);
        $result=LittleSparks\simulateDirectorOpportunity($d,$opportunity,true,null,$mock,null,null,false,null,true);
        check($result['status']==='completed','Concise dialogue saves on new and reused sessions');
        $creates=array_values(array_filter($mock->inner->calls,fn($c)=>$c[0]==='POST'&&$c[1]==='agents/sessions'));
        if($index===0){
            check(count($creates)===1,'First encounter creates only one session');$body=$creates[0][2];
            check(!isset($body['agent']['instructions']),'Saved Director instructions are not overridden');
            check($body['agent']['text']['format']['schema']['properties']['messages']['items']['properties']['text']===$textRule,'Managed output schema includes the guidance');
            $context=json_decode($body['input'][0]['content'][0]['text'],true);
        }else{
            check(!$creates,'Recent pair session is reused without another create');
            $submits=array_values(array_filter($mock->inner->calls,fn($c)=>str_ends_with($c[1],'/events')&&($c[2]['events'][0]['type']??null)==='agent.session.input.message'));
            check(count($submits)===1,'Reuse submits one encounter, with no regeneration');
            $context=json_decode($submits[0][2]['events'][0]['input'][0]['content'][0]['text'],true);
            check($context['shared_recent_conversations'][0]['messages']===$proposal['messages'],'Reused sessions receive fresh canonical SQLite dialogue from the preceding encounter');
        }
        check($context['dialogue_guidance']===$guidance,'Every encounter gets current contract guidance, including reused sessions');
        check($context['encounter_guidance']===LittleSparks\directorEncounterGuidance(),'New and reused sessions receive the same personality, variety and child-safety guidance');
        check(strlen(json_encode($context,JSON_UNESCAPED_UNICODE))<=LittleSparks\memoryConfiguration()['context_bytes'],'Guidance stays inside the existing bounded context');
        check(count($mock->inner->calls)===6&&count($mock->moderations)===1,'Guidance adds no HTTP calls or moderation requests');
        $moderated=json_decode($mock->moderations[0]['input'],true);
        check($moderated[1]['messages']===$proposal['messages'],'Original concise speech is moderated without rewriting');
        $before=count($mock->inner->calls);LittleSparks\simulateDirectorOpportunity($d,$opportunity,true,null,$mock,null,null,false,null,true);
        check(count($mock->inner->calls)===$before,'Same opportunity never regenerates');
    }
    $lines=$d->query('SELECT body FROM messages ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
    check($lines===array_merge(array_column($proposal['messages'],'text'),array_column($proposal['messages'],'text')),'Both encounters persist original words unchanged');
    $relation=$d->query('SELECT affinity,familiarity FROM relationships')->fetch();
    check((int)$relation['affinity']===4&&(int)$relation['familiarity']===2,'Disagreement preserves existing relationship accounting without sentiment penalties');
    $registered=LittleSparks\registeredDirector($d,LittleSparks\directorConfiguration());
    $request=LittleSparks\directorRequest(LittleSparks\aiConfiguration(time()),$registered,['context'=>$context],['none'=>null]);
    check(json_decode($request['input'],true)['dialogue_guidance']===$guidance&&$request['instructions']===$registered['instructions'],'Responses adapter carries the same guidance without replacing owner instructions');
    check(json_decode($request['input'],true)['encounter_guidance']===LittleSparks\directorEncounterGuidance(),'Responses receives identical encounter guidance');
    echo "PASS concise complete-sentence guidance on new/reused managed sessions and Responses; unchanged Unicode ceiling, natural endings, exact moderation/save and request counts (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
