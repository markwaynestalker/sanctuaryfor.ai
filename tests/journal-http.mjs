import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,chmod,rm} from 'node:fs/promises';
import {createServer} from 'node:net';
import {setTimeout as delay} from 'node:timers/promises';
import {tmpdir} from 'node:os';
import {join,resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {readFile} from 'node:fs/promises';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),php=process.env.PHP_BINARY||'php';
const directory=await mkdtemp(join(tmpdir(),'little-sparks-journal-test-'));await chmod(directory,0o700);
const env={...process.env,OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0'};
let server;
try{
    const core=spawnSync(php,['tests/journal-core.php',directory],{cwd:root,env,encoding:'utf8'});assert.equal(core.status,0,core.stderr||core.error?.message);const fixtures=JSON.parse(core.stdout);
    console.log('PASS V2/V3/V4: multi-pair and same-pair runs, exact provenance, private/missing dialogue, stable pagination, constant query count and unchanged data/schema');
    for(const fixture of fixtures){
        const port=await new Promise((resolvePort,reject)=>{const s=createServer();s.on('error',reject);s.listen(0,'127.0.0.1',()=>{const {port}=s.address();s.close(()=>resolvePort(port));});});
        server=spawn(php,['-S',`127.0.0.1:${port}`,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:fixture.database},stdio:'ignore'});
        const origin=`http://127.0.0.1:${port}`;let response;
        for(let i=0;i<40;i++){try{response=await fetch(origin+'/journal.php');break;}catch{await delay(100);}}
        assert.ok(response?.ok);const html=await response.text();assert.ok(html.includes('journal-bubble'));assert.ok(html.includes('Scripted dialogue'));
        assert.equal(html.includes('AI-generated dialogue · moderated before saving'),fixture.version>=3);
        assert.ok(html.includes('Sparky &lt;img src=x onerror=alert(1)&gt;'));assert.ok(!html.includes('<img src=x'));
        assert.ok(html.includes('Hello &lt;script&gt;alert(&quot;hello&quot;)&lt;/script&gt; &amp; &quot;friends&quot;.'));assert.ok(!html.includes('<script>'));
        assert.ok(html.includes('OTHER_PAIR_ONLY')&&html.includes('SAME_PAIR_SECOND_EXCHANGE')&&html.includes('A saved quiet exchange.'));
        assert.ok(!html.includes('PRIVATE_')&&!html.includes('OLDER_PAGE_ONLY'));assert.ok(html.includes(`?before=${fixture.cursor}`));
        assert.ok(!html.includes('Daily activities')&&!html.includes('V2A'));assert.ok(html.includes('Reading the journal never starts a simulation or an AI request.'));
        const older=await (await fetch(origin+`/journal.php?before=${fixture.cursor}`)).text();assert.ok(older.includes('OLDER_PAGE_ONLY')&&!older.includes('OTHER_PAIR_ONLY'));
        const empty=await (await fetch(origin+'/journal.php?before=1')).text();assert.ok(empty.includes('No changes have been recorded here yet.'));
        const css=await fetch(origin+'/assets/world.css');assert.ok(css.ok);assert.ok((await css.text()).includes('.journal-bubble'));
        const saved=await fetch(origin+'/api/commons.php?ids='+fixture.residents.join(','));assert.ok(saved.ok);assert.equal(saved.headers.get('set-cookie'),null);const commons=await saved.json();assert.equal(commons.homes.length,4);assert.equal(commons.conversations.length,2);assert.equal(commons.conversations[0].id,'first');assert.equal(commons.conversations[0].provider,fixture.version>=3?'openai':'scripted');assert.ok(!JSON.stringify(commons).includes('PRIVATE_'));
        for(const ids of ['',"' OR 1=1",Array(25).fill(fixture.residents[0]).join(',')])assert.equal((await fetch(origin+'/api/commons.php?ids='+encodeURIComponent(ids))).status,422);
        assert.equal((await fetch(origin+'/api/commons.php?ids='+fixture.residents[0],{method:'POST'})).status,405);
        assert.deepEqual((await (await fetch(origin+'/api/commons.php?ids='+Array(32).fill('a').join(''))).json()),{homes:[],conversations:[]});
        const meadow=await (await fetch(origin+'/village.php')).text();assert.ok(!meadow.includes('class="scene-house"'));assert.ok(meadow.includes('id="home-layer"')&&meadow.includes('assets/commons.js'));assert.ok(!meadow.includes('Its interactions are scripted, not AI conversations.'));
        assert.ok(meadow.includes('href="/resident.php?id='+fixture.residents[0]+'"'),'server-rendered profile link survives disabled JavaScript');
        for(const asset of ['village.js','commons.js','village.css','world.css']){
            const hash=createHash('sha256').update(await readFile(join(root,'public/assets',asset))).digest('hex').slice(0,12);assert.ok(meadow.includes(`assets/${asset}?v=${hash}`),'updated assets have content-versioned URLs');
        }
        await new Promise(resolveExit=>{server.once('exit',resolveExit);server.kill();});server=null;
    }
    const verify=spawnSync(php,['tests/journal-core.php',directory,'verify'],{cwd:root,env,encoding:'utf8'});assert.equal(verify.status,0,verify.stderr);process.stdout.write(verify.stdout);
    console.log('PASS rendered speech bubbles, escaped dialogue/names, source labels, pagination, missing conversations and updated footer on V2/V3/V4; no external requests');
}finally{
    if(server&&server.exitCode===null)await new Promise(resolveExit=>{server.once('exit',resolveExit);server.kill();});
    await rm(directory,{recursive:true,force:true});
}
