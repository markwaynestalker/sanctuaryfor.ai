import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const dir=await mkdtemp(join(tmpdir(),'scheduled-selection-'));await chmod(dir,0o700);
const php=process.env.PHP_BINARY||'php',db=join(dir,'base.sqlite');
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:''};
try{
 for(const name of ['migrate-village','migrate-ai','migrate-activity','migrate-memory','migrate-identity','migrate-director','migrate-selection-accounting','migrate-selection-capacity','migrate-managed-selection']){
  const r=spawnSync(php,['scripts/'+name+'.php','--database='+db],{env,encoding:'utf8'});assert.equal(r.status,0,name+': '+r.stderr);
 }
 const r=spawnSync(php,['tests/scheduled-director-selection-core.php',db,dir],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);process.stdout.write(r.stdout);
}finally{await rm(dir,{recursive:true,force:true});}
