import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm,copyFile,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';import {join,resolve,dirname} from 'node:path';import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php';
const dir=await mkdtemp(join(tmpdir(),'little-sparks-memory-test-live-'));await chmod(dir,0o700);
try{
    const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0'};
    const result=spawnSync(php,['tests/live-core.php',dir],{cwd:root,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr||result.error?.message);process.stdout.write(result.stdout);
    const at=new Date(Date.now()-60000).toISOString().slice(0,16)+':00Z';
    const run=(args,settings)=>new Promise((ok,no)=>{const p=spawn(php,args,{cwd:root,env:{...env,...settings}});let out='',err='';p.stdout.on('data',s=>{out+=s;});p.stderr.on('data',s=>{err+=s;});p.on('error',no);p.on('exit',code=>code===0?ok(JSON.parse(out)):no(new Error(err)));});
    for(const mode of ['scripted-scheduler','mocked-paid']){
        const path=join(dir,mode+'.sqlite');await copyFile(join(dir,'memory-template.sqlite'),path);
        const settings={LITTLE_SPARKS_DB:path,LITTLE_SPARKS_SIMULATION_ENABLED:'1',LITTLE_SPARKS_SCHEDULER_ENABLED:'1',LITTLE_SPARKS_SCHEDULER_ALLOW_PAID:'0',
            LITTLE_SPARKS_AI_ENABLED:mode==='mocked-paid'?'1':'0',LITTLE_SPARKS_AI_DAILY_REQUESTS:'2',LITTLE_SPARKS_AI_MONTHLY_MICROUSD:'1000000',LITTLE_SPARKS_AI_MODEL:'gpt-6-luna',LITTLE_SPARKS_AI_PRICING_CONFIRMED:'2026-10-24-standard-v1'};
        const args=mode==='mocked-paid'?['tests/live-worker.php',path,at]:['scripts/scheduled-village.php'];
        const results=await Promise.allSettled([run(args,settings),run(args,settings)]);for(const r of results)assert.equal(r.status,'fulfilled',r.reason?.message);
        const inspect=spawnSync(php,['-r','$d=new PDO("sqlite:".$argv[1]);echo json_encode(["opportunities"=>$d->query("SELECT COUNT(*) FROM simulation_opportunities")->fetchColumn(),"started"=>$d->query("SELECT COALESCE(SUM(responses_started+moderation_started),0) FROM ai_attempts WHERE day=date(\'now\')")->fetchColumn(),"paid"=>$d->query("SELECT COUNT(*) FROM ai_attempts WHERE day=date(\'now\')")->fetchColumn()]);',path],{env,encoding:'utf8'});assert.equal(inspect.status,0,inspect.stderr);const counts=JSON.parse(inspect.stdout);
        assert.equal(Number(counts.opportunities),1);assert.equal(Number(counts.started),mode==='mocked-paid'?2:0);assert.equal(Number(counts.paid),mode==='mocked-paid'?1:0);
    }
    console.log('PASS overlapping actual scheduled CLI and mocked natural-dialogue workers preserve one V5 opportunity and exactly one generation/moderation pair');
    const service=await readFile(join(root,'ops/systemd/sanctuary-village.service'),'utf8'),timer=await readFile(join(root,'ops/systemd/sanctuary-village.timer'),'utf8'),paid=await readFile(join(root,'ops/systemd/paid.conf.example'),'utf8');
    for(const setting of ['User=www-data','EnvironmentFile=/etc/sanctuaryfor-ai/simulation.env','TimeoutStartSec=50s','NoNewPrivileges=true','ProtectSystem=strict','Restart=no'])assert.ok(service.includes(setting));
    assert.ok(!service.includes('LoadCredential=')&&!service.includes('OPENAI_API_KEY=')&&!service.includes('--allow-paid'));assert.ok(timer.includes('Persistent=false')&&timer.includes(' UTC'));assert.ok(paid.includes('LoadCredential=openai_api_key:'));
    console.log('PASS disabled-by-configuration systemd template structure, bounded timeout/hardening, separate optional credentials and no backlog (Linux runtime verification deferred)');
}finally{await rm(dir,{recursive:true,force:true});}
