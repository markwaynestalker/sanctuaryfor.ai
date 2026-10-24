import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm,copyFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php';
const directory=await mkdtemp(join(tmpdir(),'little-sparks-v2-test-'));await chmod(directory,0o700);
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SIMULATION_INTERVAL_MINUTES:'15',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2',LITTLE_SPARKS_AI_MONTHLY_MICROUSD:'1000000',LITTLE_SPARKS_AI_MODEL:'gpt-6-luna',LITTLE_SPARKS_AI_MAX_OUTPUT_TOKENS:'512',LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1'};
const run=(args,workerEnv=env)=>new Promise((resolveRun,reject)=>{
    const p=spawn(php,args,{cwd:root,env:workerEnv});let out='',err='';p.stdout.on('data',c=>{out+=c;});p.stderr.on('data',c=>{err+=c;});p.on('error',reject);p.on('exit',code=>code===0?resolveRun(JSON.parse(out)):reject(new Error(err)));
});
try{
    for(const file of ['v2-core.php','ai-core.php','activity-core.php']){
        const r=spawnSync(php,[join(root,'tests',file),directory],{encoding:'utf8',env});assert.equal(r.status,0,r.stderr||r.error?.message);process.stdout.write(r.stdout);
    }
    const database=join(directory,'activity-concurrency.sqlite');
    const workerEnv={...env,LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_AI_DAILY_REQUESTS:'4'};
    const worker='require $argv[1]."/app/village.php";require $argv[1]."/app/activity.php";require $argv[1]."/tests/activity-mock.php";$d=LittleSparks\\connect($argv[2]);$m=new ActivityMock("conversation",$d);$r=LittleSparks\\simulateOpportunity($d,LittleSparks\\dueOpportunity(time()),true,$m);echo json_encode($r+["calls"=>count($m->calls)]);';
    const results=await Promise.allSettled([run(['-r',worker,root,database],workerEnv),run(['-r',worker,root,database],workerEnv)]);
    for(const r of results)assert.equal(r.status,'fulfilled',r.reason?.message);
    assert.equal(results.reduce((sum,r)=>sum+r.value.calls,0),2);assert.equal(results.filter(r=>r.value.replayed).length,1);
    const verify=spawnSync(php,['-r','$d=new PDO("sqlite:".$argv[1]);echo $d->query("SELECT COUNT(*) FROM ai_attempts WHERE period LIKE \'op:%\'")->fetchColumn();',database],{encoding:'utf8',env});assert.equal(verify.status,0,verify.stderr);assert.equal(verify.stdout,'1');
    console.log('PASS concurrent mocked AI invocations produce one opportunity and one generation/moderation pair');
    const different=join(directory,'different-concurrency.sqlite');await copyFile(join(directory,'activity.sqlite'),different);
    const otherWorker='require $argv[1]."/app/village.php";require $argv[1]."/app/activity.php";require $argv[1]."/tests/activity-mock.php";$d=LittleSparks\\connect($argv[2]);$m=new ActivityMock("conversation",$d);try{$r=LittleSparks\\simulateOpportunity($d,$argv[3],true,$m);}catch(LittleSparks\\Problem $e){$r=["status"=>"blocked","code"=>$e->status];}echo json_encode($r+["calls"=>count($m->calls)]);';
    const at=minutes=>new Date(Date.now()-minutes*60000).toISOString().slice(0,16)+':00Z';
    const differentResults=await Promise.allSettled([run(['-r',otherWorker,root,different,at(1)],workerEnv),run(['-r',otherWorker,root,different,at(2)],workerEnv)]);
    for(const r of differentResults)assert.equal(r.status,'fulfilled',r.reason?.message);
    assert.equal(differentResults.reduce((sum,r)=>sum+r.value.calls,0),2);
    console.log('PASS distinct concurrent opportunities share atomic HTTP limits and cannot dispatch an extra paid pair');
    const paused=await run(['scripts/run-village.php','--database='+join(directory,'does-not-exist.sqlite'),'--due']);assert.equal(paused.status,'paused');
    const scripted=join(directory,'scripted-cli.sqlite');await copyFile(join(directory,'activity.sqlite'),scripted);
    const cliEnv={...env,LITTLE_SPARKS_SIMULATION_ENABLED:'1'};
    const first=await run(['scripts/run-village.php','--database='+scripted,'--due'],cliEnv),second=await run(['scripts/run-village.php','--database='+scripted,'--due'],cliEnv);assert.equal(first.status,'completed');assert.equal(second.replayed,true);assert.equal(first.id,second.id);
    const status=await run(['scripts/ai-status.php','--database='+scripted],cliEnv);assert.equal(status.activity.interval_minutes,15);assert.equal(status.configured_daily_requests,2);assert.equal(status.activity.enabled,true);
    console.log('PASS actual CLI default pause, scripted due-slot replay and safe V4 monitoring; no scheduled services or external calls');
}finally{await rm(directory,{recursive:true,force:true});}
