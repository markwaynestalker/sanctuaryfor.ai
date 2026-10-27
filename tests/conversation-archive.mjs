import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,copyFile,rm,readFile} from 'node:fs/promises';import {createHash} from 'node:crypto';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {setTimeout as delay} from 'node:timers/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'little-sparks-journal-test-archive-'));
await chmod(dir,0o700);let server;
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0'};
try{
 const legacy=spawnSync(php,['tests/journal-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(legacy.status,0,legacy.stderr);
 await copyFile(join(dir,'v4.sqlite'),join(dir,'v6.sqlite'));await chmod(join(dir,'v6.sqlite'),0o600);
 for(const script of ['migrate-memory.php','migrate-identity.php','migrate-director.php']){const r=spawnSync(php,['scripts/'+script,'--database='+join(dir,'v6.sqlite')],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);}
 const core=spawnSync(php,['tests/archive-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(core.status,0,core.stderr);const fixtures=JSON.parse(core.stdout);
 console.log('PASS V2/V3/V4/V6 full historical search, Unicode/punctuation, exact 160-character speech, participant/source/topic/date filters, stable paging, strict input, public provenance and constant queries with unchanged database bytes');
 for(const fixture of fixtures){
  const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));const origin='http://127.0.0.1:'+port;
  server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:fixture.database},stdio:'ignore'});
  let ready;for(let i=0;i<50;i++){try{ready=await fetch(origin+'/conversations.php');break;}catch{await delay(60);}}assert.ok(ready?.ok);
  const html=await ready.text();assert.ok(html.includes('archive-table')&&html.includes('Page 1 of 9'));assert.ok(html.includes('Sparky &lt;img')&&!html.includes('<img src=x'));assert.ok(!html.includes('PRIVATE_'));
  const headers=ready.headers;assert.equal(headers.get('set-cookie'),null);assert.match(headers.get('cache-control'),/no-store/);assert.match(headers.get('content-security-policy'),/script-src 'self'/);
  const response=await fetch(origin+'/api/conversations.php?q=HISTORICAL_199');assert.ok(response.ok);assert.equal(response.headers.get('set-cookie'),null);const record=await response.json();assert.equal(record.pagination.total,1);assert.equal(record.conversations[0].id,'archive-199');assert.ok(!JSON.stringify(record).includes('PRIVATE_'));
  assert.deepEqual(Object.keys(record.conversations[0]).sort(),['id','created_at','provider','topic','messages'].sort());assert.deepEqual(Object.keys(record.conversations[0].messages[0]).sort(),['resident_id','name','text'].sort());
  const second=await (await fetch(origin+'/api/conversations.php?page=2&size=100')).json();assert.equal(second.conversations.length,100);assert.equal(second.pagination.from,101);
  const filtered=await (await fetch(origin+'/api/conversations.php?resident='+fixture.ids[1])).json();assert.equal(filtered.pagination.total,104);
  const empty=await (await fetch(origin+'/conversations.php?q=NO_MATCH_ANYWHERE')).text();assert.ok(empty.includes('No public conversations match'));
  const long=await (await fetch(origin+'/conversations.php?order=oldest&open=archive-000')).text();assert.ok(long.includes('é'.repeat(159)+'🌳'));assert.ok(/class="archive-expansion" role="row" id="[^"]+"\s*>/.test(long),'Native no-JS link expands the full exchange');
  const injected=await (await fetch(origin+'/conversations.php?q='+encodeURIComponent('<script>alert(1)</script>'))).text();assert.ok(injected.includes('&lt;script&gt;')&&!injected.includes('<script>alert(1)'));
  for(const query of ['size=101','page=0','q[]=bad','order=drop','from=2026-02-29','unknown=x']){const invalid=await fetch(origin+'/api/conversations.php?'+query);assert.equal(invalid.status,422);assert.ok(!(await invalid.text()).includes('SQLSTATE'));}
  assert.equal((await fetch(origin+'/api/conversations.php',{method:'POST'})).status,405);assert.equal((await fetch(origin+'/conversations.php',{method:'POST'})).status,405);
  const village=await (await fetch(origin+'/village.php')).text();assert.ok(village.includes('Browse all conversations')&&village.includes('Read recent saved conversations'));
  const commons=await (await fetch(origin+'/api/commons.php?ids='+fixture.ids.join(','))).json();assert.equal(commons.conversations.length,2,'Commons projection stays unchanged');
  await new Promise(ok=>{server.once('exit',ok);server.kill();});server=null;
  assert.equal(createHash('sha256').update(await readFile(fixture.database)).digest('hex'),fixture.sha256,'HTTP browsing leaves every byte of data/schema unchanged');
 }
 console.log('PASS read-only HTTP/HTML/API projection, complete native expansion, escaped input/names/dialogue, bounded pagination, no cookies, safe failures and unchanged commons/data');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
