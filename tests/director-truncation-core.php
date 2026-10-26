<?php
declare(strict_types=1);
require_once __DIR__.'/../app/commons.php';
// Included after the recorded-item/context fixtures; every provider is mocked.
try{
    $natural='We could keep a quiet corner for moonflowers, a bench for tired feet, and a little space for anyone who wants to sit together and watch the stars come out soon.';
    check(preg_match_all('/./us',$natural)===160,'Natural complete boundary fixture is exactly 160 code points');
    $cases=['short'=>'Shall we watch the moonflowers open?', 'boundary'=>$natural,
        'unicode'=>str_repeat('é',158).'🌻.', 'combining'=>'Café sounds lovely. 🌻',
        'unfinished'=>'Maybe an apple tree', 'mixed-script'=>'What sort of garden do你',
        'reported_luna'=>'A small fruit tree sounds lovely, Sparky. I’d leave a clear patch around it so the branches have room—and so you can still see the lanterns. Maybe an apple tree',
        'reported_max'=>'Hey, Skippy Jr—if we had a playground, what would you want on it first? A really tall slide, maybe? I could help make the place look festive when the time comes',
        'reported_penelope'=>'Apple trees, of course. There’s something satisfying about turning their fruit into a pie with muddy hands—after I wash them, naturally. What sort of garden do你',
        'overlong'=>$natural.'!', 'invalid_utf8'=>"A\xc3\x28", 'markup'=>'<script>alert(1)</script>'];
    $boundaryProposal=$recorded;$boundaryProposal['notes']=[];
    $boundaryPair=[['id'=>$recorded['messages'][0]['resident_id'],'name'=>'Max'],['id'=>$recorded['messages'][1]['resident_id'],'name'=>'Princess']];
    foreach($cases as $mode=>$line){
        if(str_starts_with($mode,'reported_'))check(preg_match_all('/./us',$line)===160,'Issue-provided wording is exactly at the boundary; not a raw provider trace');
        $p=$boundaryProposal;$p['messages'][0]['text']=$line;
        $valid=!in_array($mode,['overlong','invalid_utf8','markup'],true);
        try{$parsed=LittleSparks\parseDirectorProposal($p,$boundaryPair,['none'=>null]);check($valid&&$parsed['first']===$line,'Parser preserves original Unicode/in-bounds wording: '.$mode);}
        catch(LittleSparks\AiGuard $e){check(!$valid&&$e->reason==='invalid_proposal','Malformed or excessive text rejected, not repaired: '.$mode);}
    }
    $response=['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($boundaryProposal,JSON_THROW_ON_ERROR)]]]]];
    check(LittleSparks\decodedAiProposal($response)===$boundaryProposal,'Complete Responses item remains valid');
    foreach(['incomplete','in_progress',null] as $status){$r=$response;$r['output'][0]['status']=$status;try{LittleSparks\decodedAiProposal($r);throw new LogicException('Incomplete item accepted');}catch(LittleSparks\AiGuard){}}
    $r=$response;$r['incomplete_details']=['reason'=>'max_output_tokens'];
    try{LittleSparks\decodedAiProposal($r);throw new LogicException('Contradictory truncation evidence accepted');}catch(LittleSparks\AiGuard){}
    $page=recordedItemPage($boundaryProposal,'turn_boundary','envelope');
    try{LittleSparks\stagingItems($page,'turn_boundary');throw new LogicException('Incomplete managed message accepted');}catch(LittleSparks\AiGuard){}

    $boundaryBase=$dir.'/boundary-history.sqlite';copy($itemsBase,$boundaryBase);$d=LittleSparks\connect($boundaryBase);
    $baseline=$boundaryProposal;$baseline['messages'][0]['text']='Shall we leave a little room for the flowers?';
    $baseAt=gmdate('Y-m-d\TH:i:00\Z',time()-180);$nextAt=gmdate('Y-m-d\TH:i:00\Z',time()-120);
    $r=LittleSparks\simulateDirectorOpportunity($d,$baseAt,true,null,new RecordedItemTransport($baseline,'mixed'),null,null,false,null,true);
    check($r['status']==='completed','Historical fixture saves once');$history=$d->query('SELECT * FROM messages ORDER BY rowid')->fetchAll();
    $d=null;
    foreach(array_merge(array_diff(array_keys($cases),['invalid_utf8']),['flagged','envelope','storage_mutation']) as $mode){
        $path=$dir.'/boundary-'.$mode.'.sqlite';copy($boundaryBase,$path);$d=LittleSparks\connect($path);
        if($mode==='storage_mutation')$d->exec("CREATE TRIGGER fixture_clip AFTER INSERT ON messages BEGIN UPDATE messages SET body=substr(NEW.body,1,159) WHERE conversation_id=NEW.conversation_id AND sequence=NEW.sequence; END");
        $schema=$d->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll();
        $p=$boundaryProposal;$p['messages'][0]['text']=$cases[$mode]??$natural;
        $mock=new RecordedItemTransport($p,in_array($mode,['flagged','envelope'],true)?$mode:'mixed');
        $r=LittleSparks\simulateDirectorOpportunity($d,$nextAt,true,null,$mock,null,null,false,null,true);
        $success=!in_array($mode,['overlong','markup','flagged','envelope','storage_mutation'],true);
        check(($r['status']==='completed')===$success,'Truthful end-to-end boundary outcome: '.$mode);
        $all=$d->query('SELECT * FROM messages ORDER BY rowid')->fetchAll();
        check(array_slice($all,0,count($history))===$history,'Historical bytes and identities are unchanged: '.$mode);
        $new=array_slice($all,count($history));check(array_column($new,'body')===($success?array_column($p['messages'],'text'):[]),'Original accepted text, never a clipped substitute, reaches SQLite: '.$mode);
        $q=$d->prepare('SELECT value FROM settings WHERE name=?');$q->execute(['director_dialogue_integrity:'.$r['id']]);$raw=$q->fetchColumn();
        if($success){
            $receipt=json_decode($raw,true,12,JSON_THROW_ON_ERROR);check($receipt['unchanged']&&$receipt['maximum_characters']===160,'Successful atomic integrity receipt');
            foreach($p['messages'] as $i=>$m){$proof=$receipt['messages'][$i];check($proof['provider_text_sha256']===hash('sha256',$m['text'])&&$proof['saved_text_sha256']===$proof['provider_text_sha256']&&$proof['characters']===preg_match_all('/./us',$m['text'])&&$proof['utf8_bytes']===strlen($m['text'])&&$proof['at_ceiling']===($proof['characters']===160),'Receipt proves Unicode length and byte identity without logging speech');}
            $moderated=json_decode($mock->moderations[0]['input'],true);check($moderated[1]['messages']===$p['messages'],'Moderation sees exact provider wording');
            $ids=array_column($p['messages'],'resident_id');$public=LittleSparks\commons($d,$ids);check($public['conversations'][0]['messages'][0]['text']===$p['messages'][0]['text'],'Public commons retains the last character: '.$mode);
        }else check($raw===false,'Rejected/rolled-back dialogue has no accepted integrity receipt: '.$mode);
        check($d->query('SELECT name,sql FROM sqlite_master ORDER BY name')->fetchAll()===$schema,'No migration/schema mutation: '.$mode);
        check((int)$d->query('SELECT request_slots FROM director_attempts ORDER BY rowid DESC LIMIT 1')->fetchColumn()===8,'All original request reservations remain counted');
        $calls=count($mock->inner->calls);LittleSparks\simulateDirectorOpportunity($d,$nextAt,true,null,$mock,null,null,false,null,true);check(count($mock->inner->calls)===$calls,'No paid regeneration or replay: '.$mode);
    }
    echo "PASS Director boundary investigation: complete/exact/overlong Unicode, combining marks/emoji, untouched unfinished text, incomplete envelopes, moderation, original storage/public text, atomic integrity receipts, simulated mutation rollback and preserved history (offline)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
