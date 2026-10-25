import assert from 'node:assert/strict';import {spawnSync} from 'node:child_process';import {mkdtemp,chmod,rm,readFile} from 'node:fs/promises';import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';import {createHash} from 'node:crypto';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),dir=await mkdtemp(join(tmpdir(),'sparks-agent-access-')),php=process.env.PHP_BINARY||'php';await chmod(dir,0o700);
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0'};
try{
 const core=spawnSync(php,['tests/director-access-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(core.status,0,core.stderr||core.error?.message);process.stdout.write(core.stdout);
 const check=spawnSync(php,['scripts/check-director-access.php','--check'],{cwd:root,env:{...env,CREDENTIALS_DIRECTORY:join(dir,'missing')},encoding:'utf8'});assert.equal(check.status,0,check.stderr);assert.deepEqual(JSON.parse(check.stdout),{http_requests:0,generation_requests:0,credential_loaded:false,database_opened:false,only_method:'GET'});
 const receipt=await readFile(join(dir,'good.jsonl'),'utf8');
 const delivery=spawnSync(php,['scripts/check-director-access.php','--check-delivery','--audit='+join(dir,'good.jsonl')],{cwd:root,env:{...env,CREDENTIALS_DIRECTORY:dir},encoding:'utf8'});
 assert.equal(delivery.status,1,'untrusted credential directory must fail closed');assert.ok(!delivery.stdout.includes('SECRET_KEY_CANARY'));assert.ok(!delivery.stderr.includes('SECRET_KEY_CANARY'));
 assert.equal(await readFile(join(dir,'good.jsonl'),'utf8'),receipt,'metadata-only delivery check must not touch receipts');
 const auditDir=join(dir,'reconciliation'),hash=async name=>createHash('sha256').update(await readFile(join(auditDir,name))).digest('hex');
 const original=await hash('agent-access.jsonl'),authorized=await hash('agent-access-after-validator.jsonl');
 const reconcile=spawnSync(php,['scripts/reconcile-director-access.php','--directory='+auditDir,'--original-sha256='+original,'--authorized-sha256='+authorized],{cwd:root,env,encoding:'utf8'});
 assert.equal(reconcile.status,0,reconcile.stderr);const ledger=JSON.parse(reconcile.stdout);assert.deepEqual(ledger.reserved_by_utc_day,{'2026-10-25':2});assert.equal(ledger.new_http_requests,0);assert.equal(ledger.generation_requests,0);assert.equal(ledger.database_opened,false);assert.equal(ledger.credential_loaded,false);
 assert.equal(await hash('agent-access.jsonl'),original);assert.equal(await hash('agent-access-after-validator.jsonl'),authorized);
 const invalid=spawnSync(php,['scripts/reconcile-director-access.php','--directory='+auditDir,'--original-sha256=PRIVATE_CANARY','--authorized-sha256='+authorized],{cwd:root,env,encoding:'utf8'});assert.equal(invalid.status,1);assert.ok(!invalid.stderr.includes('PRIVATE_CANARY'));
 console.log('PASS read-only reconciliation CLI: two retained UTC reservations, hash verification, no key/network/SQLite access or receipt mutation');
 console.log('PASS no-network/no-credential CLI preflight; no database opens or generation endpoints');
}finally{await rm(dir,{recursive:true,force:true});}
