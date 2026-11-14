import assert from 'node:assert/strict';
import {spawnSync,spawn} from 'node:child_process';
import {mkdtemp,chmod,rm,readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {createServer} from 'node:net';
import {setTimeout as delay} from 'node:timers/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),dir=await mkdtemp(join(tmpdir(),'sparks-personal-memory-')),db=join(dir,'base.sqlite'),php=process.env.PHP_BINARY||'php';
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:''};
await chmod(dir,0o700);let server;
try{
 for(const name of ['migrate-village','migrate-ai','migrate-activity','migrate-memory','migrate-identity','migrate-director','migrate-conversation-messages']){
  const r=spawnSync(php,['scripts/'+name+'.php','--database='+db],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);
 }
 const r=spawnSync(php,['tests/spark-memory-core.php',dir,db],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);process.stdout.write(r.stdout);
 // Same persistent dispatch fence under real processes, without a provider or key.
 const worker=()=>new Promise((ok,no)=>{const p=spawn(php,['tests/spark-dispatch-worker.php',join(dir,'race.sqlite')],{cwd:root,env,encoding:'utf8'});let out='',err='';p.stdout.on('data',v=>out+=v);p.stderr.on('data',v=>err+=v);p.on('error',no);p.on('exit',code=>{try{assert.equal(code,0,err);ok(JSON.parse(out));}catch(e){no(e);}});});
 const claims=await Promise.all([worker(),worker()]);assert.equal(claims.filter(v=>v.dispatched).length,1);
 console.log('PASS real concurrent Spark dispatch: one persisted request intent and no duplicate provider work');
 const probe=createServer();await new Promise(ok=>probe.listen(0,'127.0.0.1',ok));const port=probe.address().port;await new Promise(ok=>probe.close(ok));
 const reader=join(dir,'reader.sqlite');await chmod(reader,0o600);const digest=async()=>createHash('sha256').update(await readFile(reader)).digest('hex'),before=await digest();
 server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:reader},stdio:'ignore'});let ready;
 for(let i=0;i<80;i++){try{ready=await fetch('http://127.0.0.1:'+port+'/journal.php');if(ready.ok)break;}catch{}await delay(40);}assert.ok(ready?.ok);
 const journal=await ready.text();assert.ok(journal.includes('Read the preceding exchange')&&journal.includes('Conversation continued'));assert.equal(ready.headers.get('set-cookie'),null);
 const response=await fetch('http://127.0.0.1:'+port+'/conversations.php');const html=await response.text();assert.ok(response.ok&&html.includes('Read the preceding exchange')&&html.includes('conversations.php?conversation='));
 assert.ok(!html.includes('spark_memory_context:')&&!html.includes('SELF_ONLY_')&&!html.includes('resident_id='));
 await new Promise(ok=>{server.once('exit',ok);server.kill();});server=null;assert.equal(await digest(),before,'Public lineage links never write the private database');
 console.log('PASS native journal/archive predecessor links, full saved speech and no private memory exposure or database writes');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
