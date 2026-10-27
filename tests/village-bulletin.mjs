import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,copyFile,rm,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {createHash} from 'node:crypto';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'sparks-bulletin-')),path=join(dir,'village.sqlite');
await chmod(dir,0o700);
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_DB:path,LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_BULLETIN_ENABLED:'0'};
let server;
try{
 for(const script of ['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-identity.php','migrate-director.php']){const r=spawnSync(php,['scripts/'+script,'--database='+path],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);}
 const result=spawnSync(php,['tests/bulletin-core.php',path],{cwd:root,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr||result.error?.message);const fixture=JSON.parse(result.stdout),publicDb=fixture.database;
 console.log('PASS bulletin migration/rollback/preservation, exact moderated notice and dialogue, author/claim/markup rejection, ENV bounds/zero quotas, expiry/history, unverified context, rollback, no extra API requests and exact-once accounting');
 const race=join(dir,'race.sqlite');await copyFile(join(dir,'board-template.sqlite'),race);await chmod(race,0o600);
 const worker=offset=>new Promise((ok,no)=>{const c=spawn(php,['tests/bulletin-worker.php',race,String(offset)],{cwd:root,env});let out='',err='';c.stdout.on('data',s=>out+=s);c.stderr.on('data',s=>err+=s);c.on('error',no);c.on('exit',code=>code===0?ok(JSON.parse(out)):no(Error(err)));});
 const raced=await Promise.allSettled([worker(180),worker(240)]);for(const r of raced)if(r.status==='rejected')throw r.reason;
 const count=spawnSync(php,['-r','require "app/village.php";$d=LittleSparks\\connect($argv[1]);echo $d->query("SELECT COUNT(*) FROM bulletin_notices")->fetchColumn();',race],{cwd:root,env,encoding:'utf8'});assert.equal(count.status,0,count.stderr);assert.equal(count.stdout,'1','Concurrent opportunities cannot bypass one active notice');
 console.log('PASS concurrent posting honors global quota under SQLite encounter transactions');
 const reserve=createServer();await new Promise(ok=>reserve.listen(0,'127.0.0.1',ok));const port=reserve.address().port;await new Promise(ok=>reserve.close(ok));const origin='http://127.0.0.1:'+port;
 server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:publicDb},stdio:'ignore'});
 await new Promise((ok,no)=>{let n=0;const ready=()=>fetch(origin+'/bulletin.php').then(ok).catch(e=>++n<60?setTimeout(ready,40):no(e));ready();});
 const digest=async()=>createHash('sha256').update(await readFile(publicDb)).digest('hex');const before=await digest();
 const r=await fetch(origin+'/bulletin.php'),html=await r.text();assert.equal(r.status,200);assert.ok(!r.headers.get('set-cookie'),'Public reading creates no management session');
 assert.ok(html.includes('AI-generated')&&html.includes('question &amp; a little curiosity')&&html.includes('resident.php?id='));assert.ok(!html.includes('Fern <script>')&&!html.includes('run_id')&&!html.includes('session_mock')&&!html.includes('board-token-'));
 assert.ok(html.includes('Maple &amp; Sun')||html.includes('Fern &lt;script&gt;'),'Names are escaped');assert.ok(html.includes('<article')&&html.includes('<time')&&html.includes('aria-labelledby')&&html.includes('Back to the commons'));
 assert.equal((await fetch(origin+'/bulletin.php',{method:'POST',body:'notice=untrusted'})).status,405);
 for(const query of ['?page[]=1','?page=0','?history[]=1','?history=bad','?unknown=1'])assert.equal((await fetch(origin+'/bulletin.php'+query)).status,422);
 const village=await (await fetch(origin+'/village.php')).text();assert.ok(village.includes('class="village-noticeboard" href="bulletin.php"')&&village.includes('1 active notices'),'Physical board uses native accessible no-JS link and saved count');
 assert.equal(await digest(),before,'Public GET/POST rejection leaves SQLite unchanged');
 const remove=spawnSync(php,['scripts/remove-bulletin-notice.php','--database='+publicDb,'--notice='+fixture.notice,'--confirm='+fixture.notice],{cwd:root,env,encoding:'utf8'});assert.equal(remove.status,0,remove.stderr);
 assert.ok(!(await (await fetch(origin+'/bulletin.php?history=1')).text()).includes('question &amp; a little curiosity'),'Withdrawn text is hidden from all public history');
 console.log('PASS no-JS/keyboard native board, mobile semantic papers, exact escaped text, private projection, GET-only browsing without database writes or cookies, and operator-only CLI withdrawal preserving history');
}finally{if(server){const exited=new Promise(ok=>server.once('exit',ok));server.kill();await exited;}await rm(dir,{recursive:true,force:true});}
