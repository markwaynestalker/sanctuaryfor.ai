import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm,readFile,copyFile,mkdir} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';import {join} from 'node:path';
const dir=await mkdtemp(join(tmpdir(),'narrative-story-'));await chmod(dir,0o700);const php=process.env.PHP_BINARY||'php',db=join(dir,'base.sqlite');
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:''};let server;
try{
 for(const name of ['migrate-village','migrate-ai','migrate-activity','migrate-memory','migrate-identity','migrate-director','migrate-selection-accounting','migrate-selection-capacity','migrate-managed-selection']){const r=spawnSync(php,['scripts/'+name+'.php','--database='+db],{env,encoding:'utf8'});assert.equal(r.status,0,name+': '+r.stderr);}
 const migration=spawnSync(php,['tests/narrative-migration-core.php',db],{env,encoding:'utf8'});assert.equal(migration.status,0,migration.stderr);process.stdout.write(migration.stdout);
 for(let i=0;i<2;i++){const m=spawnSync(php,['scripts/migrate-narrative-stories.php','--database='+db],{env,encoding:'utf8'});assert.equal(m.status,0,m.stderr);}
 const v2dir=join(dir,'multi');await mkdir(v2dir);const v2db=join(v2dir,'base.sqlite');await copyFile(db,v2db);const multi=spawnSync(php,['scripts/migrate-conversation-messages.php','--database='+v2db],{env,encoding:'utf8'});assert.equal(multi.status,0,multi.stderr);const rollback=spawnSync(php,['tests/flexible-speaker-migration-core.php',v2db],{env,encoding:'utf8'});assert.equal(rollback.status,0,rollback.stderr);for(let i=0;i<2;i++){const migration=spawnSync(php,['scripts/migrate-flexible-speaker-order.php','--database='+v2db],{env,encoding:'utf8'});assert.equal(migration.status,0,migration.stderr);}
 const r=spawnSync(php,['tests/narrative-story-core.php',db,dir],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);process.stdout.write(r.stdout);
 const story=/STORY_ID=([a-f0-9]{32})/.exec(r.stdout)?.[1];assert.ok(story);
 const digest=async()=>createHash('sha256').update(await readFile(db)).digest('hex'),before=await digest();
 const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));
 server=spawn(php,['-S',`127.0.0.1:${port}`,'-t','public'],{env:{...env,LITTLE_SPARKS_DB:db,LITTLE_SPARKS_SECURE_COOKIES:'0'},stdio:'ignore'});const origin=`http://127.0.0.1:${port}`;
 let response;for(let i=0;i<80;i++){try{response=await fetch(`${origin}/conversations.php?story=${story}`);break;}catch{await new Promise(ok=>setTimeout(ok,50));}}assert.ok(response?.ok,'story page loads');const first=await response.text();assert.match(first,/Saved story/);assert.match(first,/Close story/);assert.match(first,/12 exchanges/);assert.match(first,/Original exchange/);assert.match(first,/Later exchanges/);assert.match(first,/Saved start line/);
 const second=await (await fetch(`${origin}/conversations.php?story=${story}&story_page=2`)).text();assert.match(second,/Earlier exchanges/);assert.match(second,/Exchange 12/);
 assert.equal(await digest(),before,'archive HTTP reads do not alter SQLite');console.log('PASS native archive story pagination, complete original speech and unchanged database (offline)');
 const v2=spawnSync(php,['tests/narrative-story-core.php',v2db,v2dir,'v2'],{env,encoding:'utf8'});assert.equal(v2.status,0,v2.stderr);console.log('PASS same narrative lifecycle with the extended conversation contract (offline)');
}finally{if(server){const done=new Promise(ok=>server.once('exit',ok));server.kill();await done;}await rm(dir,{recursive:true,force:true});}
