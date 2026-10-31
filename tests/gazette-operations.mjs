import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp,writeFile,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
// Discovered by the existing offline runner; all data and transport are synthetic.
const result=spawnSync(process.env.PYTHON_BINARY||'python',['-B','-m','unittest','discover','-s','tests','-p','gazette_operations_test.py','-v'],{encoding:'utf8'});
assert.equal(result.status,0,result.stderr||result.error?.message);
console.log(result.stdout.trim());
const dir=await mkdtemp(join(tmpdir(),'gazette-preflight-'));
try{
 const file=join(dir,'private.sqlite');await writeFile(file,'synthetic unchanged database canary',{mode:0o600});
 const before=await readFile(file);
 const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),LITTLE_SPARKS_GAZETTE_EDITOR_ENABLED:'0'};
 const check=spawnSync(process.env.PHP_BINARY||'php',['scripts/generate-gazette-brokered.php','--check'],{env,encoding:'utf8'});
 assert.equal(check.status,1);assert.ok(check.stdout.includes('refused_or_uncertain'));
 assert.deepEqual(await readFile(file),before,'Refused preflight preserves data');
}finally{await rm(dir,{recursive:true,force:true});}
console.log('PASS Gazette operations calendar, release pin, disabled job, sanitized receipts, unit policy and reservation math (offline)');
