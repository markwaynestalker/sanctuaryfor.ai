import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,chmod,rm,readFile,copyFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {setTimeout as delay} from 'node:timers/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php';
const dir=await mkdtemp(join(tmpdir(),'little-sparks-memory-test-'));await chmod(dir,0o700);const sessions=join(dir,'sessions');await mkdir(sessions,{mode:0o700});
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2'};let server,serverError='';
try{
    const core=spawnSync(php,['tests/memory-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(core.status,0,core.stderr||core.error?.message);process.stdout.write(core.stdout);
    const database=join(dir,'memory-template.sqlite'),ids=JSON.parse(await readFile(join(dir,'memory-ids.json'),'utf8'));
    const port=await new Promise((ok,no)=>{const s=createServer();s.on('error',no);s.listen(0,'127.0.0.1',()=>{const {port}=s.address();s.close(()=>ok(port));});});
    server=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:database},stdio:['ignore','ignore','pipe']});server.stderr.on('data',chunk=>{serverError=(serverError+String(chunk)).slice(-4000);});const origin=`http://127.0.0.1:${port}`;let response;
    for(let i=0;i<40;i++){try{response=await fetch(origin+'/resident.php?id='+ids.luna);break;}catch{await delay(100);}}
    assert.ok(response?.ok);const html=await response.text();assert.ok(html.includes('How a story finds its way.')&&html.includes('Secondhand')&&html.includes('Skippy Jr'));assert.ok(!html.includes('PRIVATE_CANARY'));
    const profileResponse=await fetch(origin+'/api/profile.php?id='+ids.luna),profile=await profileResponse.json();assert.equal(profileResponse.status,200,`Profile API: HTTP ${profileResponse.status}; ${typeof profile.error==='string'?profile.error.slice(0,160):'no public error'}; fixture PHP log: ${serverError}`);assert.ok(Array.isArray(profile.memory_trail),'Successful V5 profile must include a memory_trail array');assert.ok(profile.memory_trail.some(s=>s.status==='confirmed'));assert.ok(!JSON.stringify(profile).includes('PRIVATE_CANARY'));
    const homes=await (await fetch(origin+'/api/commons.php?ids='+[ids.luna,ids.sparky].join(','))).json();assert.ok(homes.homes.find(h=>h.resident_id===ids.sparky).objects.some(o=>o.kind==='tree'));assert.ok(homes.homes.every(h=>h.objects.every(o=>o.x>=h.plot.x&&o.x<h.plot.x+4)));
    const digest='require $argv[1]."/app/village.php";require $argv[1]."/app/memory_schema.php";$d=LittleSparks\\connect($argv[2]);echo LittleSparks\\v4MemoryDigest($d).":".$d->query("SELECT COUNT(*) FROM memory_receipts")->fetchColumn();';
    const snapshot=()=>{const r=spawnSync(php,['-r',digest,root,database],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);return r.stdout;};const before=snapshot();
    await fetch(origin+'/resident.php?id='+ids.sparky);await fetch(origin+'/journal.php');await fetch(origin+'/api/commons.php?ids='+[ids.luna,ids.sparky].join(','));assert.equal(snapshot(),before,'Browsing does not process memories or mutate history/accounting');
    // Real concurrent CLI invocations still serialize a single V5 opportunity, no paid flag.
    const concurrent=join(dir,'concurrent.sqlite');await copyFile(database,concurrent);
    const run=()=>new Promise((ok,no)=>{const p=spawn(php,['scripts/run-village.php','--database='+concurrent,'--due'],{cwd:root,env:{...env,LITTLE_SPARKS_SIMULATION_ENABLED:'1'}});let out='',err='';p.stdout.on('data',c=>{out+=c;});p.stderr.on('data',c=>{err+=c;});p.on('error',no);p.on('exit',c=>c===0?ok(JSON.parse(out)):no(new Error(err)));});
    const results=await Promise.allSettled([run(),run()]);for(const r of results)assert.equal(r.status,'fulfilled',r.reason?.message);assert.equal(results.filter(r=>r.value.replayed).length,1);
    console.log('PASS public V5 memory trail, source labels, actual saved yards, read-only browsing and concurrent opportunity idempotency; no paid API calls');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
