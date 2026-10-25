import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,chmod,readFile,rm} from 'node:fs/promises';
import {join,resolve,dirname} from 'node:path';import {tmpdir} from 'node:os';import {fileURLToPath} from 'node:url';import {createServer} from 'node:net';import {setTimeout as delay} from 'node:timers/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'sparks-icon-colors-'));
await chmod(dir,0o700);const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'0'};let server;
const fixture=mode=>{const r=spawnSync(php,['tests/icon-colors-fixture.php',dir,mode],{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.error?.message);return JSON.parse(r.stdout);};
try{
 const before=fixture('create');assert.equal(before.version,5);assert.equal(before.count,24);assert.equal(new Set(before.colors).size,24);
 const sessions=join(dir,'sessions');await mkdir(sessions,{mode:0o700});
 const port=await new Promise(ok=>{const s=createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>ok(p));});});
 server=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:join(dir,'village.sqlite'),LITTLE_SPARKS_SECURE_COOKIES:'0'},stdio:'ignore'});
 const base=`http://127.0.0.1:${port}`;let up=false;for(let i=0;i<50;i++){try{if((await fetch(base+'/')).ok){up=true;break;}}catch{}await delay(100);}assert.ok(up);
 const listed=await (await fetch(base+'/api/residents.php')).json();assert.deepEqual(new Set(listed.residents.map(r=>r.color)),new Set(before.colors));
 for(const color of before.colors){const resident=listed.residents.find(r=>r.color===color);const p=await (await fetch(base+'/api/profile.php?id='+resident.id)).json();assert.equal(p.resident.color,color);}
 for(const route of ['/','/village.php','/journal.php','/world.php','/resident.php?id='+listed.residents[0].id]){
  const page=await fetch(base+route);assert.equal(page.status,200);const html=await page.text();assert.ok(html.includes('rel="apple-touch-icon"')&&html.includes('apple-mobile-web-app-title'),route+' icon metadata');
  if(route==='/village.php'){for(const color of before.colors){assert.ok(html.includes(`value="${color}"`));assert.ok(html.includes(`data-color="${color}"`));}assert.equal((html.match(/class="color-swatch color-/g)||[]).length,24);assert.ok(html.includes('name="personality"'));assert.ok(!/<textarea[^>]*name="description"/.test(html),'No unfinished identity form');}
 }
 const found=new Set();for(let sector=0;sector<6;sector++){const world=await (await fetch(base+'/api/world.php?sector='+sector)).json();for(const p of world.plots){found.add(p.resident.color);const html=await (await fetch(base+'/world.php?sector='+sector)).text();assert.ok(html.includes('map-plot color-'+p.resident.color));}}assert.deepEqual(found,new Set(before.colors));
 const response=await fetch(base+'/assets/apple-touch-icon.png?v=1');assert.equal(response.status,200);assert.ok(response.headers.get('content-type').includes('image/png'));const png=Buffer.from(await response.arrayBuffer());assert.equal(png.readUInt32BE(16),180);assert.equal(png.readUInt32BE(20),180);assert.equal(png[25],2,'Opaque RGB PNG');
 const css=await readFile(join(root,'public/assets/village.css'),'utf8');for(const color of before.colors)assert.ok(css.includes('.color-'+color),'Curated CSS for '+color);
 for(const script of ['village.js','world.js']){const source=await readFile(join(root,'public/assets',script),'utf8');for(const color of before.colors)assert.ok(source.includes("'"+color+"'"),'Allowlisted '+color+' in '+script);}
 const after=fixture('inspect');assert.deepEqual(after,before,'Public pages and assets preserve every V5 table row and schema');
 console.log('PASS V5 all 24 colors persist/replay, public profile/map rendering, accessible swatches, unchanged legacy personality form, icon PNG/metadata on every page and identical V5 data/schema after browsing; zero API calls');
}finally{if(server&&server.exitCode===null)await new Promise(ok=>{server.once('exit',ok);server.kill();});await rm(dir,{recursive:true,force:true});}
