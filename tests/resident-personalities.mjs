import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,chmod,rm} from 'node:fs/promises';
import {join,resolve,dirname} from 'node:path';import {tmpdir} from 'node:os';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {setTimeout as delay} from 'node:timers/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'sparks-icon-colors-'));
await chmod(dir,0o700);const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2',LITTLE_SPARKS_AI_MONTHLY_MICROUSD:'1000000',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'0'};let server;
const run=(args,extra={})=>{const r=spawnSync(php,args,{cwd:root,env:{...env,...extra},encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.error?.message);return r.stdout;};
try{
 run(['tests/icon-colors-fixture.php',dir,'create']);const core=JSON.parse(run(['tests/identity-core.php',dir]));
 const compete=letter=>new Promise((ok,no)=>{const p=spawn(php,['tests/identity-core.php',dir,'concurrent',letter],{cwd:root,env});let out='',err='';p.stdout.on('data',v=>out+=v);p.stderr.on('data',v=>err+=v);p.on('error',no);p.on('exit',code=>{try{assert.equal(code,0,err);ok(JSON.parse(out));}catch(e){no(e);}});});
 const races=await Promise.all(['a','b','c','d'].map(compete));assert.equal(races.filter(r=>r.status===200).length,1);assert.equal(races.filter(r=>r.status===409).length,3);
 const inspection=JSON.parse(run(['scripts/inspect-identity.php','--database='+join(dir,'village.sqlite')]));assert.equal(inspection.identity_edits,1);assert.equal(inspection.integrity,'ok');
 assert.equal(JSON.parse(run(['tests/identity-core.php',dir,'daily'])).daily_checks,'passed');
 const paid={LITTLE_SPARKS_SCHEDULER_ENABLED:'1',LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'1',LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_DB:join(dir,'village.sqlite'),CREDENTIALS_DIRECTORY:join(dir,'nonexistent-credentials')};
 const rejected=spawnSync(php,['scripts/scheduled-village.php'],{cwd:root,env:{...env,...paid},encoding:'utf8'});assert.notEqual(rejected.status,0,'Explicitly paid scheduler now validates configuration/credentials for identity-enabled schemas too');
 const paused=JSON.parse(run(['scripts/scheduled-village.php'],{...paid,LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'0'}));assert.equal(paused.reason,'identity_runtime_pending','Identity-only schema remains paused without a paid invocation');
 const sessions=join(dir,'sessions');await mkdir(sessions,{mode:0o700});
 const port=await new Promise(ok=>{const s=createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>ok(p));});});
 server=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:join(dir,'village.sqlite'),LITTLE_SPARKS_SECURE_COOKIES:'0'},stdio:'ignore'});
 const base=`http://127.0.0.1:${port}`;for(let i=0;i<50;i++){try{if((await fetch(base+'/')).ok)break;}catch{}await delay(100);}
 const first=await fetch(base+'/api/residents.php'),listed=await first.json();const cookies=first.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');const csrf=listed.csrf;
 const post=async(endpoint,body,override={})=>{const response=await fetch(base+'/api/'+endpoint+'.php',{method:'POST',headers:{Cookie:cookies,'Content-Type':'application/json','X-Sparks-CSRF':csrf,...override},body:JSON.stringify(body)});return {status:response.status,body:await response.json(),headers:response.headers};};
 let edit={token:core.legacy_token,description:'A thoughtful gardener who loves lavender.','color':'violet',expectedRevision:1,requestId:'e'.repeat(32)};
 assert.equal((await post('identity',edit,{'X-Sparks-CSRF':'bad'})).status,403);
 assert.equal((await post('identity',{...edit,token:'f'.repeat(64)})).status,404);
 assert.equal((await post('identity',{...edit,expectedRevision:0})).status,409);
 assert.equal((await post('identity',{...edit,expectedRevision:'1'})).status,422);
 const updated=await post('identity',edit);assert.equal(updated.status,200);assert.equal(updated.body.resident.identity_version,2);assert.ok(updated.headers.get('cache-control').includes('no-store'));
 assert.equal((await post('identity',edit)).body.replayed,true);assert.equal((await post('identity',{...edit,description:'Changed request'})).status,409);
 await delay(2100);const input={name:'New Spark',color:'burgundy',antenna:'sprout',description:'Loves quiet gardens & asks “why?” ❤️',website:'',requestId:'1'.repeat(32)};
 const created=await post('create',input);assert.equal(created.status,201,JSON.stringify(created.body));assert.equal(created.body.resident.description,input.description);const id=created.body.resident.id;
 assert.equal((await post('create',input)).body.resident.id,id);assert.equal((await post('create',{...input,description:'Different'})).status,409);
 assert.equal((await post('create',{...input,requestId:'2'.repeat(32),description:'<img src=x onerror=alert(1)>'})).status,422);
 const profile=await (await fetch(base+'/api/profile.php?id='+id)).json();assert.equal(profile.resident.description,input.description);assert.equal(profile.preferences,null,'No PHP-authored character preferences for new identities');
 const html=await (await fetch(base+'/resident.php?id='+id)).text();assert.ok(html.includes('gardens &amp; asks'));assert.ok(html.includes('Owner-written character description'));
 const village=await (await fetch(base+'/village.php')).text();assert.ok(/<textarea[^>]*name="description"/.test(village));assert.ok(!village.includes('name="personality"'));assert.ok(village.includes('identity-editor-template'));
 const map=await (await fetch(base+'/api/world.php?sector='+profile.plot.sector)).json();assert.equal(map.plots.find(p=>p.resident.id===id).resident.color,'burgundy');
 const publicJson=JSON.stringify(await (await fetch(base+'/api/residents.php')).json());for(const secret of [core.legacy_token,created.body.token,'token_hash','owner_hash','request_hash','previous_description'])assert.ok(!publicJson.includes(secret),'Public privacy');
 const revisit=await post('revisit',{token:created.body.token});assert.equal(revisit.body.resident.description,input.description);
 assert.equal((await fetch(base+'/api/identity.php')).status,405);assert.equal((await post('identity',{...edit,description:'x'.repeat(25000)})).status,413);
 // Restarted PHP/inspection observes the same committed identity audit.
 const final=JSON.parse(run(['scripts/inspect-identity.php','--database='+join(dir,'village.sqlite')]));assert.equal(final.identity_edits,4);assert.equal(final.schema_version,5);
 console.log('PASS resident personalities: '+core.checks+'; concurrent revision protection, authenticated/CSRF editing, idempotency, HTTP bounds, public escaping/privacy, no invented preferences, persistent audit and scheduler credential isolation; zero API calls');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
