import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,copyFile,rm,readFile} from 'node:fs/promises';import {createHash} from 'node:crypto';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {setTimeout as delay} from 'node:timers/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),dir=await mkdtemp(join(tmpdir(),'little-sparks-journal-test-gazette-')),php=process.env.PHP_BINARY||'php';await chmod(dir,0o700);let server;
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0'};
const run=args=>{const r=spawnSync(php,args,{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);return r.stdout;};
const digest=async file=>createHash('sha256').update(await readFile(file)).digest('hex');
try{
 const contract=JSON.parse(await readFile(join(root,'contracts/gazette-draft-v1.schema.json'),'utf8'));
 assert.equal(contract.additionalProperties,false);assert.equal(contract.properties.contract.enum[0],'sanctuary.gazette.draft.v1');
 assert.deepEqual(contract.$defs.reference.required,['source','sequence']);
 run(['tests/journal-core.php',dir]);await copyFile(join(dir,'v4.sqlite'),join(dir,'v6.sqlite'));await chmod(join(dir,'v6.sqlite'),0o600);
 for(const s of ['memory','identity','director'])run(['scripts/migrate-'+s+'.php','--database='+join(dir,'v6.sqlite')]);
 const fixtures=JSON.parse(run(['tests/gazette-core.php',dir]));
 console.log(run(['tests/gazette-editor-core.php',dir]).trim());
 console.log('PASS source authorization/privacy, full legacy/16-message history, topic-independent grouping, fair exact quotes, 23/25-hour DST, quiet days, frozen publication/idempotency and unchanged SQLite (offline)');
 for(const fixture of fixtures){
  const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));const origin='http://127.0.0.1:'+port;
  server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:fixture.database},stdio:'ignore'});
  let ready;for(let i=0;i<60;i++){try{ready=await fetch(origin+'/gazette.php?date=2026-10-29');break;}catch{await delay(40);}}assert.ok(ready?.ok);
  const html=await ready.text();assert.ok(html.includes('The Village Gazette')&&html.includes('Published edition'));assert.ok(html.includes('America/New_York')&&html.includes('Village pulse'));assert.ok(!html.includes('PRIVATE_EDITORIAL_CANARY')&&!html.includes('PRIVATECREDENTIALCANARY'));assert.ok(!html.includes('<script> sneaked'));
  assert.equal(ready.headers.get('set-cookie'),null);assert.match(ready.headers.get('cache-control'),/no-store/);assert.match(ready.headers.get('content-security-policy'),/script-src 'self'/);
  const links=[...html.matchAll(/href="(conversations\.php\?conversation=[^"]+)"/g)].map(m=>m[1].replaceAll('&amp;','&'));assert.ok(links.length>0);
  for(const link of [...new Set(links)]){const response=await fetch(origin+'/'+link);assert.ok(response.ok);assert.match(await response.text(),/class="archive-expansion" role="row" id="[^"]+"\s*>/,'Citation opens exactly its source without JS');}
  const empty=await (await fetch(origin+'/gazette.php?date=2026-10-28')).text();assert.ok(empty.includes('A little space for quiet')&&empty.includes('Archive preview'));
  const current=await (await fetch(origin+'/gazette.php')).text();assert.ok(current.includes('Today · In progress'));
  for(const query of ['date[]=2026-10-29','date=2026-02-29','date=9999-01-01','date=../../secret','unknown=x'])assert.equal((await fetch(origin+'/gazette.php?'+query)).status,422);
  assert.equal((await fetch(origin+'/gazette.php',{method:'POST'})).status,405);
  assert.equal((await fetch(origin+'/api/conversations.php?conversation=integrity-good')).status,200);
  assert.equal((await (await fetch(origin+'/api/conversations.php?conversation=integrity-private')).json()).pagination.total,0);
  await new Promise(ok=>{server.once('exit',ok);server.kill();});server=null;
  const publish=()=>new Promise((ok,no)=>{const p=spawn(php,['scripts/publish-gazette.php','--database='+fixture.database,'--date=2026-10-26'],{cwd:root,env});let text='',error='';p.stdout.on('data',v=>text+=v);p.stderr.on('data',v=>error+=v);p.on('error',no);p.on('exit',code=>{try{assert.equal(code,0,error);ok(JSON.parse(text));}catch(e){no(e);}});});
  const race=await Promise.all([publish(),publish()]);assert.equal(race.filter(r=>r.existing===false).length,1,'Concurrent publication creates exactly one immutable edition');
  const frozen=JSON.parse(await readFile(fixture.database+'.gazette/2026-10-26.json','utf8'));assert.equal(frozen.edition.date,'2026-10-26');
  assert.equal(await digest(fixture.database),fixture.sha256);assert.equal(await digest(fixture.database+'.gazette/2026-10-29.json'),fixture.edition_sha256);
 }
 const css=await readFile(join(root,'public/assets/gazette.css'),'utf8');assert.match(css,/@media\(max-width:600px\)/);assert.ok(!css.includes('url('),'No external fonts or images');
 console.log('PASS server-rendered newspaper/citations, exact-conversation authorization, Eastern edition navigation, strict GET input, no cookies/provider calls and unchanged database/edition files');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
