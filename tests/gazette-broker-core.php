<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_broker_client.php';
require_once __DIR__.'/gazette-contract-fixtures.php';
function brokerCheck(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function brokerReject(callable $f,string $why):void{try{$f();}catch(Throwable){return;}throw new RuntimeException($why);}
final class FixtureBroker implements LittleSparks\GazetteBrokerChannel
{
    public int $calls=0;public int $now;
    public function __construct(public PDO $db,public PDO $read,public array $policy,public string $release,public int $bytes=90000){$this->now=time();}
    public function call(array $message):array{$this->calls++;return LittleSparks\gazetteBrokerHandle($this->db,$this->read,$message,$this->policy,$this->bytes,$this->release,64011,$this->now,true,'2026-10-29');}
}
final class BrokerProvider implements LittleSparks\EditorialTransport
{
    public array $calls=[];
    public function __construct(public string $mode,public ?PDO $db=null){}
    public function post(string $path,array $body,int $deadline):array
    {
        $this->calls[]=$path;if($this->mode==='timeout')throw new RuntimeException('Mock timeout');
        if($path==='moderations')return ['body'=>['results'=>[['flagged'=>$this->mode==='flagged']]],'request_id'=>'req_fixture_mod'];
        $snapshot=json_decode($body['input'][0]['content'][0]['text'],true);$s=array_values(array_filter($snapshot['pack']['sources'],fn($s)=>$s['id']==='integrity-good'))[0];
        $ref=['source'=>$s['alias'],'sequence'=>0];$claim=['text'=>'A lost-and-found was proposed','claim_type'=>'proposal','references'=>[$ref]];
        $draft=['contract'=>'sanctuary.gazette.draft.v1','date'=>$snapshot['pack']['date'],'source_sha256'=>$snapshot['sha256'],
            'stories'=>[['kind'=>'lead','connection'=>'standalone','headline'=>$claim,'paragraphs'=>[$claim,$claim],'quotes'=>[$ref]]]];
        if($this->mode==='badquote')$draft['stories'][0]['quotes'][0]['text']='invented';
        $r=['status'=>'completed','id'=>'resp_fixture','model'=>$body['model'],'service_tier'=>'default','error'=>null,'incomplete_details'=>null,
            'usage'=>['input_tokens'=>1000,'output_tokens'=>500],
            'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($draft)]]]]];
        if($this->mode==='partial')$r['status']='in_progress';if($this->mode==='missingusage')unset($r['usage']);if($this->mode==='overrun')$r['usage']['input_tokens']=200000;
        return ['body'=>fixtureGazetteMutation($r,$this->mode,$body['text']['format']['schema'],$this->db),'request_id'=>'req_fixture_gen'];
    }
}
try{
    $dir=$argv[1];$release=str_repeat('a',40);
    $policy=['model'=>'gpt-6-luna','pricing_version'=>'fixture','input_tokens'=>96000,'output_tokens'=>12000,'input_rate'=>250000,'output_rate'=>500000,'moderation_microusd'=>0,'daily_microusd'=>50000,'monthly_microusd'=>1000000,'daily_requests'=>2,'lease_seconds'=>120];
    foreach([...['good','flagged','missingusage','partial','badquote','overrun','timeout','wal'],...array_keys(GAZETTE_CONTRACT_CASES)] as $mode){
        $path=$dir.'/broker-'.$mode.'.sqlite';copy($dir.'/v6.sqlite',$path);chmod($path,0600);$db=LittleSparks\connect($path);
        if($mode==='wal')brokerCheck($db->query('PRAGMA journal_mode=WAL')->fetchColumn()==='wal','Disposable WAL fixture');
        $db->exec('BEGIN IMMEDIATE');LittleSparks\migrateEditorialLedger($db);$db->exec('COMMIT');
        $old=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'gazette_editor_%' ORDER BY name")->fetchAll() as $row){$table=$row['name'];$old[$table]=$db->query('SELECT * FROM "'.$table.'"')->fetchAll();}
        $read=LittleSparks\connect($path);$read->exec('PRAGMA query_only=ON');$channel=new FixtureBroker($db,$read,$policy,$release);$base=['version'=>1,'release'=>$release,'policy_sha256'=>LittleSparks\gazetteBrokerPolicyHash($policy,90000,$release)];
        foreach(['sql','review','publish','permit','refund'] as $op)brokerReject(fn()=>$channel->call($base+['op'=>$op]),'Forbidden operation accepted');
        brokerReject(fn()=>$channel->call($base+['op'=>'begin_generation','database'=>'/tmp/other']),'Client path accepted');
        brokerCheck((int)$db->query('SELECT COUNT(*) FROM gazette_editor_attempts')->fetchColumn()===0,'Rejected requests write nothing');
        $provider=new BrokerProvider($mode==='wal'?'good':$mode,$db);
        if($mode==='timeout')brokerReject(fn()=>LittleSparks\runBrokeredEditorialJob($channel,$provider,$policy,90000,$release),'Timeout not propagated');
        else{
            $result=LittleSparks\runBrokeredEditorialJob($channel,$provider,$policy,90000,$release);
            $expected=in_array($mode,['good','missingusage','wal'],true)?'awaiting_owner_review':($mode==='flagged'?'moderation_rejected':'rejected_or_uncertain');
            brokerCheck($result['status']===$expected,'Broker lifecycle '.$mode);
            if(isset(GAZETTE_CONTRACT_CASES[$mode])){
                brokerCheck(($result['rejection_code']??null)===GAZETTE_CONTRACT_CASES[$mode],'Static rejection code '.$mode);
                brokerCheck($provider->calls===['responses'],'Rejected draft never reaches moderation');
                brokerCheck((int)$db->query('SELECT COUNT(*) FROM gazette_editor_drafts')->fetchColumn()===0,'Rejected candidate never stored');
                brokerCheck($db->query('SELECT outcome FROM gazette_editor_outcomes')->fetchColumn()==='rejected','Completed generation is rejected, not refunded');
                brokerCheck($db->query('SELECT outcome FROM gazette_editor_receipts')->fetchColumn()==='completed'&&(int)$db->query('SELECT estimated_microusd FROM gazette_editor_receipts')->fetchColumn()===6000,'Completed observed receipt retained before validation rejection');
                brokerCheck((int)$db->query('SELECT SUM(request_slots) FROM gazette_editor_attempts')->fetchColumn()===2,'Both slots retained');
                brokerCheck(!str_contains(json_encode($result),'PRIVATE_SYNTHETIC_REJECTION_CANARY'),'No exception/provider text in receipt');
                if($mode==='source_withdrawn')$db->exec("UPDATE simulation_runs SET status='completed' WHERE id=(SELECT run_id FROM conversations WHERE id='integrity-good')");
            }
        }
        brokerCheck(count($provider->calls)<=2,'No extra provider calls');
        $calls=$provider->calls;brokerReject(fn()=>LittleSparks\runBrokeredEditorialJob($channel,$provider,$policy,90000,$release),'Duplicate edition admitted');brokerCheck($provider->calls===$calls,'Rejected edition cannot redispatch');
        $attempt=$db->query('SELECT * FROM gazette_editor_attempts')->fetch();$request=$db->query("SELECT id FROM gazette_editor_requests WHERE kind='generation'")->fetchColumn();
        $cap=LittleSparks\gazetteBrokerCapability($attempt,$request,'generation',64011,$release);
        brokerCheck($cap!==$attempt['owner']&&strlen($cap)===64,'Opaque derived capability');
        brokerReject(fn()=>$channel->call($base+['op'=>'complete_generation','attempt_id'=>$attempt['id'],'request_id'=>$request,'capability'=>str_repeat('0',64),'wire'=>[]]),'Forged capability');
        brokerReject(fn()=>LittleSparks\gazetteBrokerHandle($db,$db,$base+['op'=>'complete_generation','attempt_id'=>$attempt['id'],'request_id'=>$request,'capability'=>$cap,'wire'=>[]],$policy,90000,$release,64012,time(),true,'2026-10-29'),'Cross UID capability');
        $channel->now=(int)$attempt['expires_at']+1;
        brokerReject(fn()=>$channel->call($base+['op'=>'complete_generation','attempt_id'=>$attempt['id'],'request_id'=>$request,'capability'=>$cap,'wire'=>[]]),'Expired/replayed capability');
        brokerCheck((int)$db->query('SELECT SUM(reserved_microusd) FROM gazette_editor_attempts')->fetchColumn()===30000,'Full hold retained');
        brokerCheck((int)$db->query('SELECT COUNT(*) FROM gazette_editor_reviews')->fetchColumn()===0,'Never approves');
        foreach($old as $table=>$rows)brokerCheck($db->query('SELECT * FROM "'.$table.'"')->fetchAll()===$rows,'Non-editorial data changed: '.$table);
        brokerCheck($db->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&!$db->query('PRAGMA foreign_key_check')->fetch(),'Integrity preserved');
        $db=null;
    }
    echo "PASS keyless broker lifecycle and synthetic completed-receipt/no-draft/no-moderation rejection matrix, static codes, retained holds/slots and no retry (mock provider)\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
