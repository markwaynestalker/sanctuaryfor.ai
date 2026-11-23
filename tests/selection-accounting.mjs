import assert from 'node:assert/strict';
import {spawnSync,spawn} from 'node:child_process';
import {mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const php=process.env.PHP_BINARY||'php',r=spawnSync(php,['tests/selection-accounting-core.php'],{encoding:'utf8'});
assert.equal(r.status,0,r.stderr);process.stdout.write(r.stdout);
const dir=await mkdtemp(join(tmpdir(),'selection-ledger-')),db=join(dir,'race.sqlite');let now=Math.floor(Date.now()/1000);if(86400-now%86400<120)now-=180;
try{
 const realdb=join(dir,'village.sqlite'); for(const name of ['migrate-village','migrate-ai','migrate-activity','migrate-memory','migrate-identity','migrate-director','migrate-selection-accounting','migrate-selection-accounting','migrate-selection-capacity','migrate-selection-capacity']){const migration=spawnSync(php,['scripts/'+name+'.php','--database='+realdb],{encoding:'utf8'});assert.equal(migration.status,0,name+': '+migration.stderr);}
 const integration=spawnSync(php,['tests/selection-integration-core.php',realdb],{encoding:'utf8'});assert.equal(integration.status,0,integration.stderr);process.stdout.write(integration.stdout);const handoff=spawnSync(php,['tests/selection-handoff-core.php',realdb],{encoding:'utf8'});assert.equal(handoff.status,0,handoff.stderr);process.stdout.write(handoff.stdout);
 const setup=spawnSync(php,['tests/selection-race-worker.php',db,'setup',String(now)],{encoding:'utf8'});assert.equal(setup.status,0,setup.stderr);
 const worker=id=>new Promise((resolve,reject)=>{const p=spawn(php,['tests/selection-race-worker.php',db,id,String(now)]);let out='',err='';p.stdout.on('data',v=>out+=v);p.stderr.on('data',v=>err+=v);p.on('error',reject);p.on('exit',code=>code===0?resolve(out):reject(new Error(err)));});
 const result=await Promise.all([worker('one'),worker('two')]);assert.deepEqual(result.sort(),['reserved','simulation_busy']);
 console.log('PASS concurrent selection reservations serialize to one active lease');
}finally{await rm(dir,{recursive:true,force:true});}