import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,chmod,copyFile,rm,readFile,writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {setTimeout as delay} from 'node:timers/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),dir=await mkdtemp(join(tmpdir(),'little-sparks-memory-test-director-')),php=process.env.PHP_BINARY||'php';
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2',LITTLE_SPARKS_AI_MONTHLY_MICROUSD:'1000000',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0'};let server;
await chmod(dir,0o700);
try{
 const result=spawnSync(php,['tests/director-core.php',dir],{cwd:root,encoding:'utf8',env});
 assert.equal(result.status,0,result.stderr||result.error?.message);process.stdout.write(result.stdout);
 // Concurrent operator invocations consume the same permit once, before any HTTP.
 const permitDb=join(dir,'permit-race.sqlite'),permitFile=join(dir,'offline-permit.json');await copyFile(join(dir,'synthetic-stage-template.sqlite'),permitDb);
 const plan=spawnSync(php,['tests/director-permit-worker.php',permitDb,'plan'],{cwd:root,env,encoding:'utf8'});assert.equal(plan.status,0,plan.stderr);await writeFile(permitFile,plan.stdout,{mode:0o600});
 const consume=()=>new Promise((ok,no)=>{const p=spawn(php,['tests/director-permit-worker.php',permitDb,permitFile],{cwd:root,env});let out='',err='';p.stdout.on('data',v=>out+=v);p.stderr.on('data',v=>err+=v);p.on('error',no);p.on('exit',c=>{try{assert.equal(c,0,err);ok(JSON.parse(out));}catch(e){no(e);}});});
 assert.equal((await Promise.all([consume(),consume()])).filter(r=>r.accepted).length,1);
 console.log('PASS concurrent one-shot staging permits serialize and retain consumption without credentials or network');
 const cli=(script,args=[],settings={})=>spawnSync(php,['scripts/'+script,...args],{cwd:root,env:{...env,...settings},encoding:'utf8'});
 const seedDb=join(dir,'cli-synthetic.sqlite'),seedFile=join(dir,'synthetic-characters.json');
 for(const script of ['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-identity.php','migrate-director.php']){const r=cli(script,['--database='+seedDb]);assert.equal(r.status,0,r.stderr);}
 await writeFile(seedFile,await readFile(join(root,'docs/staging/synthetic-residents.example.json')),{mode:0o600});
 const seeded=cli('seed-director-staging.php',['--database='+seedDb,'--residents-file='+seedFile],{LITTLE_SPARKS_ENVIRONMENT:'staging'});assert.equal(seeded.status,0,seeded.stderr);assert.equal(JSON.parse(seeded.stdout).synthetic_resident_ids.length,2);assert.equal(JSON.parse(seeded.stdout).paid_calls,0);assert.ok(!seeded.stdout.includes('token'));
 assert.notEqual(cli('seed-director-staging.php',['--database='+seedDb,'--residents-file='+seedFile],{LITTLE_SPARKS_ENVIRONMENT:'staging'}).status,0,'Seeding never overwrites existing synthetic identities');
 console.log('PASS explicit private synthetic seeding uses owner text, refuses nonempty databases and exposes no private links');
 // A paid V6 scheduler must load its protected credential despite the identity schema.
 // Director disabled here guarantees the stand-in can never reach a model transport.
 const scheduledSettings={LITTLE_SPARKS_DB:seedDb,LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_SCHEDULER_ENABLED:'1',LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'1',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1',CREDENTIALS_DIRECTORY:join(dir,'missing-scheduled-credential')};
 const safeCheck=cli('scheduled-village.php',['--check'],scheduledSettings);assert.equal(safeCheck.status,0,safeCheck.stderr);assert.equal(JSON.parse(safeCheck.stdout).credential_loaded,false);assert.equal(JSON.parse(safeCheck.stdout).database_opened,false);
 const absent=cli('scheduled-village.php',[],scheduledSettings);assert.notEqual(absent.status,0,'Missing V6 scheduler credential is rejected before claiming an opportunity');
 const scheduledCredentials=join(dir,'scheduled-credentials');await mkdir(scheduledCredentials,{mode:0o700});await writeFile(join(scheduledCredentials,'openai_api_key'),'MOCK_SCHEDULED_CREDENTIAL\n',{mode:0o600});
 const loaded=cli('scheduled-village.php',[],{...scheduledSettings,CREDENTIALS_DIRECTORY:scheduledCredentials});assert.equal(loaded.status,0,loaded.stderr);assert.equal(JSON.parse(loaded.stdout).reason,'director_disabled');assert.ok(![loaded.stdout,loaded.stderr].join('').includes('MOCK_SCHEDULED_CREDENTIAL'));
 console.log('PASS V6 scheduler protected credential loading, missing-key refusal, no secret output and read-only --check (zero model requests)');
 const readiness=cli('stage-director-once.php',['--check'],{CREDENTIALS_DIRECTORY:join(dir,'no-credential-access')});assert.equal(readiness.status,0,readiness.stderr);assert.equal(JSON.parse(readiness.stdout).paid_requests,0);assert.equal(JSON.parse(readiness.stdout).admission.allowed,false);
 const hostCheck=cli('stage-director-once.php',['--check-host'],{CREDENTIALS_DIRECTORY:join(dir,'no-credential-access'),OPENAI_API_KEY:'PRIVATE_CANARY_NOT_A_KEY'});assert.ok(![hostCheck.stdout,hostCheck.stderr].join('').includes('PRIVATE_CANARY'));if(hostCheck.status===0){const host=JSON.parse(hostCheck.stdout);assert.equal(host.paid_requests,0);assert.equal(host.credential_loaded,false);assert.equal(host.database_opened,false);}else assert.notEqual(hostCheck.status,0,'Unsupported/nonisolated host refuses safely');
 const armed=cli('stage-director-once.php',['--allow-paid','--database=/var/lib/sanctuaryfor-ai/village.sqlite','--at='+new Date().toISOString()],{OPENAI_API_KEY:'PRIVATE_CANARY_NOT_A_KEY',LITTLE_SPARKS_ENVIRONMENT:'staging',LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_ENABLED:'1',LITTLE_SPARKS_SIMULATION_ENABLED:'1'});assert.notEqual(armed.status,0);assert.ok(![armed.stdout,armed.stderr].join('').includes('PRIVATE_CANARY'));
 const cliDb=join(dir,'cli-migration.sqlite'),backupFile=join(dir,'cli-backup.sqlite'),baselineFile=join(dir,'v5-manifest.json');await copyFile(join(dir,'memory-template.sqlite'),cliDb);
 const backed=cli('backup-village.php',['--database='+cliDb,'--output='+backupFile]);assert.equal(backed.status,0,backed.stderr);assert.equal(JSON.parse(backed.stdout).schema_version,5);await writeFile(baselineFile,backed.stdout,{mode:0o600});
 const migration=cli('migrate-director.php',['--database='+cliDb]);assert.equal(migration.status,0,migration.stderr);
 const inspected=cli('inspect-village.php',['--database='+cliDb,'--matches-v5='+baselineFile]);assert.equal(inspected.status,0,inspected.stderr);assert.equal(JSON.parse(inspected.stdout).matches_v5,true);assert.equal(JSON.parse(inspected.stdout).schema_version,6);
 console.log('PASS no-egress staging preflight, armed-path refusal, standalone private backup CLI and V5→V6 preservation verification');
 const path=join(dir,'concurrent.sqlite');await copyFile(join(dir,'director-template.sqlite'),path);
 const at=new Date(Date.now()-60000).toISOString().slice(0,16)+':00Z';
 const worker=()=>new Promise((ok,no)=>{const p=spawn(php,['tests/director-worker.php',path,at],{cwd:root,env:{...env,LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_RUNTIME:'responses',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2',LITTLE_SPARKS_AI_MODEL:'gpt-6-luna',LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1'}});let out='',err='';p.stdout.on('data',s=>{out+=s;});p.stderr.on('data',s=>{err+=s;});p.on('error',no);p.on('exit',c=>c===0?ok(JSON.parse(out)):no(new Error(err)));});
 const workers=await Promise.all([worker(),worker()]);assert.ok(workers.some(r=>r.status==='completed'));
 const counts=spawnSync(php,['-r','require "app/village.php";$d=LittleSparks\\connect($argv[1]);echo json_encode([$d->query("SELECT COUNT(*) FROM director_runs")->fetchColumn(),$d->query("SELECT SUM(started_requests) FROM director_attempts")->fetchColumn()]);',path],{cwd:root,env,encoding:'utf8'});assert.equal(counts.status,0,counts.stderr);assert.deepEqual(JSON.parse(counts.stdout).map(Number),[1,2]);
 console.log('PASS concurrent V6 workers reserve two requests and save exactly one canonical encounter');
 const budgetPath=join(dir,'metadata-concurrent.sqlite');await copyFile(join(dir,'director-template.sqlite'),budgetPath);
 const today=new Date().toISOString().slice(0,10),evidence={schema:'sanctuary.access-reconciliation.v1',total_requests_reserved:2,receipts:['1','2'].map(d=>({check_id:d.repeat(32),sha256:d.repeat(64),at:today+'T00:00:00Z',day:today,http_requests_reserved:1}))};
 const imported=spawnSync(php,['-r','require "app/village.php";require "app/activity.php";$db=LittleSparks\\connect($argv[1]);LittleSparks\\recordDirectorAccessReservations($db,json_decode($argv[2],true));',budgetPath,JSON.stringify(evidence)],{cwd:root,env,encoding:'utf8'});assert.equal(imported.status,0,imported.stderr);
 const budgetWorker=offset=>new Promise((ok,no)=>{const op=new Date(Date.now()-offset*60000).toISOString().slice(0,16)+':00Z';const p=spawn(php,['tests/director-worker.php',budgetPath,op],{cwd:root,env:{...env,LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_RUNTIME:'responses',LITTLE_SPARKS_AI_DAILY_REQUESTS:'4',LITTLE_SPARKS_AI_MODEL:'gpt-6-luna',LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1'}});let out='',err='';p.stdout.on('data',s=>out+=s);p.stderr.on('data',s=>err+=s);p.on('error',no);p.on('exit',c=>c===0?ok(JSON.parse(out)):no(new Error(err)));});
 const budgetRace=await Promise.all([budgetWorker(1),budgetWorker(2)]);assert.equal(budgetRace.filter(r=>r.status==='completed').length,1);
 assert.notEqual((await budgetWorker(3)).status,'completed','Two persisted metadata requests plus one two-slot encounter exhaust configured four-slot allowance');
 console.log('PASS concurrent distinct opportunities count persistent metadata reservations under the shared budget lock');
 const sessions=join(dir,'sessions');await mkdir(sessions,{mode:0o700});
 const port=await new Promise(ok=>{const socket=createServer();socket.listen(0,'127.0.0.1',()=>{const p=socket.address().port;socket.close(()=>ok(p));});});
 server=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:path,LITTLE_SPARKS_SECURE_COOKIES:'0'},stdio:'ignore'});
 const origin=`http://127.0.0.1:${port}`;let started=false;
 for(let i=0;i<50;i++){try{if((await fetch(origin+'/village.php')).ok){started=true;break;}}catch{}await delay(100);}assert.ok(started,'Temporary server starts');
 let cookie='',csrf='';
 async function api(endpoint,body,headers={}){const response=await fetch(origin+'/api/'+endpoint+'.php',{method:body?'POST':'GET',headers:{Cookie:cookie,...(body?{'Content-Type':'application/json','X-Sparks-CSRF':csrf}:{}),...headers},...(body?{body:JSON.stringify(body)}:{})});if(response.headers.getSetCookie().length)cookie=response.headers.getSetCookie().map(s=>s.split(';')[0]).join('; ');const data=await response.json();if(data.csrf)csrf=data.csrf;return {response,data};}
 await api('residents');await delay(2100);
 const payload={name:'Lavender Byte',description:'A thoughtful robot who loves lavender and silly riddles.',color:'deep-blue',antenna:'orb',website:'',requestId:'d'.repeat(32)};
 const created=await api('create',payload);assert.equal(created.response.status,201,JSON.stringify(created.data));const token=created.data.token,id=created.data.resident.id;
 assert.equal((await api('create',payload)).data.resident.id,id,'Creation retry preserves identity');
 const edit={token,description:'A lavender enthusiast; asks bright questions.',color:'coral',expectedRevision:0,requestId:'e'.repeat(32)};
 assert.equal((await api('identity',edit,{'X-Sparks-CSRF':'invalid'})).response.status,403);
 assert.equal((await api('identity',{...edit,token:'f'.repeat(64)})).response.status,404);
 assert.equal((await api('identity',{...edit,description:'<script>alert(1)</script>'})).response.status,422);
 assert.equal((await api('identity',{...edit,description:'x'.repeat(2001)})).response.status,422);
 const updated=await api('identity',edit);assert.equal(updated.response.status,200,JSON.stringify(updated.data));assert.equal(updated.data.resident.identity_version,1);
 assert.equal((await api('identity',edit)).data.replayed,true);
 assert.equal((await api('identity',{...edit,description:'Changed duplicate'})).response.status,409);
 assert.equal((await api('identity',{...edit,requestId:'a'.repeat(32)})).response.status,409);
 for(const route of ['/','/village.php','/journal.php','/resident.php?id='+id,'/world.php']){const page=await fetch(origin+route);const html=await page.text();assert.ok(html.includes('rel="apple-touch-icon"')&&html.includes('apple-mobile-web-app-title'),route+' has home-screen metadata');assert.ok(!html.includes(token),'No token in public HTML');}
 const image=await fetch(origin+'/assets/apple-touch-icon.png?v=1');assert.equal(image.status,200);assert.ok(image.headers.get('content-type').includes('image/png'));const png=Buffer.from(await image.arrayBuffer());assert.equal(png.readUInt32BE(16),180);assert.equal(png.readUInt32BE(20),180);
 const village=await (await fetch(origin+'/village.php')).text();assert.ok(village.includes('name="description"')&&village.includes('value="burgundy"'));
 const listing=await api('residents');assert.ok(!JSON.stringify(listing.data).includes(token));
 console.log('PASS V6 HTTP creation/edit/retry, CSRF, private credentials, validation, public descriptions and robot home-screen icon on all pages');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
