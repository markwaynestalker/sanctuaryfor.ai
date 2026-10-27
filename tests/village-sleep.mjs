import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve} from 'node:path';
const root=resolve(import.meta.dirname),base=resolve(root,'..'),php=process.env.PHP_BINARY||'php',dir=await mkdtemp(join(tmpdir(),'little-sparks-sleep-test-'));
await chmod(dir,0o700);
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SLEEP_ENABLED:'0'};
delete env.LITTLE_SPARKS_SLEEP_START_HOUR;delete env.LITTLE_SPARKS_SLEEP_END_HOUR;
try{
 for(const script of ['migrate-village.php','migrate-ai.php','migrate-activity.php','migrate-memory.php','migrate-identity.php','migrate-director.php']){
  const r=spawnSync(php,['scripts/'+script,'--database='+join(dir,'village.sqlite')],{cwd:base,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);
 }
 const r=spawnSync(php,['tests/sleep-core.php',dir],{cwd:base,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);process.stdout.write(r.stdout);
 const {at}=JSON.parse(await readFile(join(dir,'plan.json'),'utf8')),db=join(dir,'race.sqlite');
 const code='require "app/village.php";require "app/activity.php";$d=LittleSparks\\connect($argv[1]);try{$r=LittleSparks\\simulateOpportunity($d,$argv[2],true);}catch(LittleSparks\\Problem $e){$r=["status"=>"blocked","code"=>$e->status];}echo json_encode($r);';
 const worker=()=>new Promise((ok,no)=>{const p=spawn(php,['-r',code,db,at],{cwd:base,env:{...env,LITTLE_SPARKS_SLEEP_ENABLED:'1',LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_AI_ENABLED:'1',LITTLE_SPARKS_DIRECTOR_ENABLED:'1'}});let out='',err='';p.stdout.on('data',s=>out+=s);p.stderr.on('data',s=>err+=s);p.on('error',no);p.on('exit',c=>c===0?ok(JSON.parse(out)):no(Error(err)));});
 const outcomes=await Promise.all([worker(),worker()]);assert.ok(outcomes.some(x=>x.status==='completed'));
 const replay=await worker();assert.equal(replay.reason,'night_rest');assert.equal(replay.replayed,true);
 const q=spawnSync(php,['-r','$d=new PDO("sqlite:".$argv[1]);echo json_encode([$d->query("SELECT COUNT(*) FROM simulation_runs")->fetchColumn(),$d->query("SELECT COUNT(*) FROM ai_attempts")->fetchColumn(),$d->query("SELECT COUNT(*) FROM messages")->fetchColumn()]);',db],{encoding:'utf8',env});assert.equal(q.status,0,q.stderr);assert.deepEqual(JSON.parse(q.stdout).map(Number),[1,0,0]);
 console.log('PASS concurrent rest claims serialize with one quiet result, zero paid reservations/messages and no scheduler activation');
}finally{await rm(dir,{recursive:true,force:true});}
