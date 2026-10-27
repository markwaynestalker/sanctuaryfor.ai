import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,copyFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'sparks-operator-test-'));
await chmod(dir,0o700);const path=join(dir,'village.sqlite');
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_DB:path,LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_OWNER_IDEAS_ENABLED:'0'};
let server;
try{
 for(const script of ['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-identity.php','migrate-director.php']){const r=spawnSync(php,['scripts/'+script,'--database='+path],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);}
 const seeded=spawnSync(php,['tests/operator-ideas-core.php',path],{cwd:root,env,encoding:'utf8'});assert.equal(seeded.status,0,seeded.stderr);const {ids}=JSON.parse(seeded.stdout);
 // Concurrent submissions share the same persisted quotas; no worker can bypass the write lock.
 const racePath=join(dir,'race.sqlite');await copyFile(path,racePath);await chmod(racePath,0o600);
 const code='require "app/village.php";require "app/operator_ideas.php";$d=LittleSparks\\connect($argv[1]);try{$r=LittleSparks\\submitOperatorIdea($d,["resident"=>$argv[2],"request_id"=>$argv[3],"idea"=>"Concurrent optional idea"],time());echo json_encode($r);}catch(LittleSparks\\Problem $e){echo json_encode(["status"=>$e->status]);}';
 const worker=id=>new Promise((ok,no)=>{const child=spawn(php,['-r',code,racePath,ids[0],id],{cwd:root,env:{...env,LITTLE_SPARKS_OWNER_IDEAS_ENABLED:'1',LITTLE_SPARKS_OWNER_IDEAS_PENDING_PER_RESIDENT:'1',LITTLE_SPARKS_OWNER_IDEAS_PER_DAY:'4'}});let out='',err='';child.stdout.on('data',s=>out+=s);child.stderr.on('data',s=>err+=s);child.on('error',no);child.on('exit',c=>c===0?ok(JSON.parse(out)):no(Error(err)));});
 const raced=await Promise.all([worker('5'.repeat(32)),worker('6'.repeat(32))]);assert.equal(raced.filter(r=>r.id).length,1);assert.equal(raced.filter(r=>r.status===429).length,1);
 const winner=raced[0].id?'5'.repeat(32):'6'.repeat(32);assert.equal((await worker(winner)).replayed,true,'Idempotent replay works even after quotas fill');
 console.log('PASS operator add-on rollback/preservation, strict ENV/text, idempotency, quotas, participant-scoped optional context, attribution, rollback receipts, auth expiry/rotation/rate/CSRF and zero paid requests');
 const hashed=spawnSync(php,['-r','echo password_hash("offline-web-password",PASSWORD_DEFAULT);'],{encoding:'utf8'});assert.equal(hashed.status,0,hashed.stderr);
 const reserve=createServer();await new Promise(ok=>reserve.listen(0,'127.0.0.1',ok));const port=reserve.address().port;await new Promise(ok=>reserve.close(ok));const origin='http://127.0.0.1:'+port;
 server=spawn(php,['-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t','public','tests/operator-router.php'],{cwd:root,env:{...env,LITTLE_SPARKS_OWNER_IDEAS_ENABLED:'1',LITTLE_SPARKS_OWNER_PASSWORD_HASH:hashed.stdout},stdio:'ignore'});
 await new Promise((ok,no)=>{let n=0;const ready=()=>fetch(origin+'/owner.php?resident='+ids[0]).then(()=>ok()).catch(e=>++n<60?setTimeout(ready,40):no(e));ready();});
 const jar=new Map();let csrf;
 const request=async(url,form)=>{const r=await fetch(origin+url,{redirect:'manual',headers:{...(jar.size?{Cookie:[...jar].map(([k,v])=>k+'='+v).join('; ')}:{}),...(form?{'Content-Type':'application/x-www-form-urlencoded'}:{})},...(form?{method:'POST',body:new URLSearchParams(form)}:{})});for(const cookie of r.headers.getSetCookie()){const [k,v]=cookie.split(';')[0].split('=');jar.set(k,v);}const html=await r.text();csrf=html.match(/name="csrf" value="([a-f0-9]+)"/)?.[1]??csrf;return {r,html};};
 let page=await request('/owner.php?resident='+ids[0]);assert.equal(page.r.status,200);assert.ok(page.html.includes('type="password"')&&!page.html.includes('OUTSIDER_IDEA_CANARY'),'Anonymous cannot read history');
 let denied=await request('/owner.php',{csrf,action:'idea',resident:ids[0],request_id:'8'.repeat(32),idea:'Unauthorized'});assert.equal(denied.r.status,403);
 denied=await request('/owner.php',{csrf:'wrong',action:'login',password:'offline-web-password'});assert.equal(denied.r.status,403);
 const oldSession=jar.get('sparks_session');let login=await request('/owner.php',{csrf,action:'login',password:'offline-web-password',resident:ids[0]});assert.equal(login.r.status,303);assert.notEqual(jar.get('sparks_session'),oldSession,'Successful login rotates session ID');
 page=await request(login.r.headers.get('location'));assert.ok(page.html.includes('An idea for Operator fixture 0.')&&page.html.includes('OUTSIDER_IDEA_CANARY'),'Only operator sees full attributed history');assert.ok(!page.html.includes(hashed.stdout));
 const requestId=page.html.match(/name="request_id" value="([a-f0-9]+)"/)[1];
 let saved=await request('/owner.php',{csrf,action:'idea',resident:ids[0],request_id:requestId,idea:'WEB_IDEA_CANARY: Could birds enjoy a quiet picnic?'});assert.equal(saved.r.status,303);
 saved=await request('/owner.php',{csrf,action:'idea',resident:ids[0],request_id:requestId,idea:'WEB_IDEA_CANARY: Could birds enjoy a quiet picnic?'});assert.equal(saved.r.status,303,'Back/resubmit keeps one idea');
 page=await request('/owner.php?resident='+ids[0]);assert.equal(page.html.split('WEB_IDEA_CANARY').length-1,1);assert.ok(page.html.includes('site operator')&&page.html.includes('Pending;'));
 const xss=await request('/owner.php',{csrf,action:'idea',resident:ids[0],request_id:'9'.repeat(32),idea:'<script>alert(1)</script>'});assert.equal(xss.r.status,422);assert.ok(!xss.html.includes('<script>alert(1)'));
 for(const url of ['/api/commons.php?ids='+ids.slice(0,2).join(','),'/resident.php?id='+ids[0],'/journal.php']){const r=await fetch(origin+url);assert.ok(r.ok);const text=await r.text();assert.ok(!text.includes('WEB_IDEA_CANARY')&&!text.includes('OUTSIDER_IDEA_CANARY')&&!text.includes(hashed.stdout),'Private suggestions/credentials do not leak into public readers');}
 page=await request('/owner.php');const logout=await request('/owner.php',{csrf,action:'logout'});assert.equal(logout.r.status,303);page=await request('/owner.php');assert.ok(!page.html.includes('WEB_IDEA_CANARY'));
 const inspect=spawnSync(php,['-r','$d=new PDO("sqlite:".$argv[1]);echo json_encode([$d->query("SELECT COUNT(*) FROM ai_attempts")->fetchColumn(),$d->query("SELECT COUNT(*) FROM operator_ideas WHERE body LIKE \'WEB_IDEA_CANARY%\'")->fetchColumn()]);',path],{encoding:'utf8'});assert.equal(inspect.status,0,inspect.stderr);assert.deepEqual(JSON.parse(inspect.stdout).map(Number),[0,1]);
 console.log('PASS HTTPS controller fixture, operator-only login/logout, session rotation, CSRF, escaped errors, idempotent form submission, private paginated history and no provider activity');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
