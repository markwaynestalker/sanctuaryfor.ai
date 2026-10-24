import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_BINARY||'php';
const directory=await mkdtemp(join(tmpdir(),'little-sparks-v2-test-'));
await chmod(directory,0o700);
try{
    const core=spawnSync(php,[join(root,'tests/v2-core.php'),directory],{encoding:'utf8',env:{...process.env,LITTLE_SPARKS_AI_ENABLED:'0'}});
    assert.equal(core.status,0,core.stderr||core.error?.message);process.stdout.write(core.stdout);
    const database=join(directory,'world.sqlite'),period=new Date().toISOString().slice(0,10);
    const run=()=>new Promise((resolveRun,reject)=>{
        const child=spawn(php,[join(root,'scripts/simulate-village.php'),`--database=${database}`,`--period=${period}`],{env:{...process.env,LITTLE_SPARKS_AI_ENABLED:'0'}});
        let out='',err='';child.stdout.on('data',chunk=>{out+=chunk;});child.stderr.on('data',chunk=>{err+=chunk;});
        child.on('error',reject);child.on('exit',code=>code===0?resolveRun(JSON.parse(out)):reject(new Error(err)));
    });
    const results=await Promise.allSettled([run(),run()]);
    for(const result of results)assert.equal(result.status,'fulfilled',result.reason?.message);
    const pair=results.map(result=>result.value);
    assert.equal(pair[0].id,pair[1].id);assert.equal(pair.filter(r=>r.replayed).length,1);
    assert.equal(pair[0].status,'completed');
    const check=spawnSync(php,['-r','$d=new PDO("sqlite:".$argv[1]);$q=$d->prepare("SELECT COUNT(*) FROM simulation_runs WHERE period=?");$q->execute([$argv[2]]);echo $q->fetchColumn();',database,period],{encoding:'utf8'});
    assert.equal(check.status,0,check.stderr);assert.equal(check.stdout,'1');
    console.log('PASS concurrent CLI workers serialize and apply one daily period exactly once (no paid requests)');
}finally{await rm(directory,{recursive:true,force:true});}
