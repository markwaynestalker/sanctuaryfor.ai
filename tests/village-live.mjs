import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php';
const dir=await mkdtemp(join(tmpdir(),'little-sparks-memory-test-live-'));await chmod(dir,0o700);
try{
    const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0'};
    const result=spawnSync(php,['tests/live-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr||result.error?.message);process.stdout.write(result.stdout);
}finally{await rm(dir,{recursive:true,force:true});}
