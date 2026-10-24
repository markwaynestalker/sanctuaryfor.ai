import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,chmod,rm,copyFile,readFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {setTimeout as delay} from 'node:timers/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');const php=process.env.PHP_BINARY||'php';
const directory=await mkdtemp(join(tmpdir(),'little-sparks-v2-test-'));await chmod(directory,0o700);
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0'};
let server;
try{
    for(const file of ['v2-core.php','ai-core.php']){
        const result=spawnSync(php,[join(root,'tests',file),directory],{encoding:'utf8',env});
        assert.equal(result.status,0,result.stderr||result.error?.message);process.stdout.write(result.stdout);
    }
    // Two real PHP workers compete for the same reservation; neither has a network transport/key.
    const database=join(directory,'concurrency.sqlite');await copyFile(join(directory,'world.sqlite'),database);
    const workerCode='require $argv[1]."/app/village.php"; require $argv[1]."/app/ai_simulation.php"; $d=LittleSparks\\connect($argv[2]); $p=gmdate("Y-m-d"); try{$c=LittleSparks\\aiConfiguration(time());$r=LittleSparks\\reserveAi($d,$p,LittleSparks\\selectAiPair($d,$p),$c,time());echo json_encode(["reserved"=>true]);}catch(LittleSparks\\AiGuard $e){echo json_encode(["reserved"=>false,"reason"=>$e->reason]);}';
    const worker=()=>new Promise((resolveWorker,reject)=>{
        const child=spawn(php,['-r',workerCode,root,database],{env:{...env,LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1'}});let out='',err='';
        child.stdout.on('data',chunk=>{out+=chunk;});child.stderr.on('data',chunk=>{err+=chunk;});child.on('error',reject);child.on('exit',code=>code===0?resolveWorker(JSON.parse(out)):reject(new Error(err)));
    });
    const results=await Promise.allSettled([worker(),worker()]);for(const result of results)assert.equal(result.status,'fulfilled',result.reason?.message);
    assert.equal(results.filter(result=>result.value.reserved).length,1);
    console.log('PASS concurrent PHP workers reserve one job/two request slots; no network or API key used');
    const port=await new Promise((resolvePort,reject)=>{const socket=createServer();socket.on('error',reject);socket.listen(0,'127.0.0.1',()=>{const {port}=socket.address();socket.close(()=>resolvePort(port));});});
    const sessions=join(directory,'sessions');await mkdir(sessions,{mode:0o700});
    server=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:join(directory,'http-fixture.sqlite')},stdio:'ignore'});
    const origin=`http://127.0.0.1:${port}`;let page;const residentId=await readFile(join(directory,'http-resident.txt'),'utf8');
    for(let attempt=0;attempt<40;attempt++){try{page=await fetch(origin+'/resident.php?id='+residentId);break;}catch{await delay(100);}}
    assert.ok(page?.ok);const html=await page.text();assert.ok(html.includes('AI-generated dialogue'));assert.ok(html.includes('fondness for'));assert.ok(!html.includes('CANARY_KEY'));
    const profile=await (await fetch(origin+'/api/profile.php?id='+residentId)).json();assert.ok(profile.preferences.voice);assert.ok(profile.conversations.some(c=>c.provider==='openai'));assert.ok(!JSON.stringify(profile).includes('token_hash'));
    const outside=await fetch(origin+'/scripts/ai-status.php');assert.equal(outside.status,404);
    console.log('PASS V3 server-rendered preferences/source labels and public API privacy; operator scripts are outside the web root');
}finally{
    if(server&&server.exitCode===null)await new Promise(resolveExit=>{server.once('exit',resolveExit);server.kill();});
    await rm(directory,{recursive:true,force:true});
}
