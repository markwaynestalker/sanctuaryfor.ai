// Node 20+ and PHP with PDO_SQLITE. Uses only a disposable OS-temp database.
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {mkdtemp, mkdir, chmod, readFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createServer} from 'node:net';
import {setTimeout as delay} from 'node:timers/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BINARY || 'php';
const directory = await mkdtemp(join(tmpdir(), 'little-sparks-test-'));
await chmod(directory, 0o700);
const database = join(directory, 'village.sqlite');
const sessions = join(directory, 'sessions');
await mkdir(sessions, {mode:0o700});
let server;
let serverError = '';
const migrate = (path = database) => spawnSync(php, [join(root, 'scripts/migrate-village.php'), `--database=${path}`], {encoding:'utf8'});
function phpCode(code) {
    const result = spawnSync(php, ['-r', code, database], {encoding:'utf8', cwd:root});
    assert.equal(result.status, 0, result.stderr); return result.stdout;
}
const freePort = await new Promise((resolvePort, reject) => {
    const socket = createServer(); socket.on('error', reject);
    socket.listen(0, '127.0.0.1', () => { const {port} = socket.address(); socket.close(() => resolvePort(port)); });
});
const origin = `http://127.0.0.1:${freePort}`;
async function start(databasePath = database, sessionPath = sessions) {
    server = spawn(php, ['-d', `session.save_path=${sessionPath}`, '-S', `127.0.0.1:${freePort}`, '-t', 'public'], {
        cwd:root, env:{...process.env, LITTLE_SPARKS_DB:databasePath, LITTLE_SPARKS_SECURE_COOKIES:'0'}, stdio:['ignore','ignore','pipe']
    });
    server.on('error', error => { serverError = error.message; });
    server.stderr.on('data', chunk => { serverError = String(chunk).slice(-1500); });
    for (let attempt = 0; attempt < 50; attempt++) {
        try { const response = await fetch(origin + '/village.php'); if (response.ok) return; } catch {}
        await delay(100);
    }
    throw new Error(`Temporary server did not start: ${serverError}`);
}
async function stop() {
    if (!server || server.exitCode !== null) return;
    await new Promise(resolveExit => { server.once('exit', resolveExit); server.kill(); });
}
function client() {
    const cookies = new Map(); let csrf = '';
    return async (path, body, extra = {}) => {
        const response = await fetch(origin + path, {
            ...(body !== undefined ? {method:'POST', body: typeof body === 'string' ? body : JSON.stringify(body)} : {}),
            ...extra,
            headers:{Cookie:[...cookies].map(([key,value]) => `${key}=${value}`).join('; '), ...(body !== undefined ? {'Content-Type':'application/json','X-Sparks-CSRF':csrf} : {}), ...extra.headers}
        });
        for (const header of response.headers.getSetCookie()) {
            const pair = header.split(';')[0], index = pair.indexOf('=');
            cookies.set(pair.slice(0,index), pair.slice(index+1));
        }
        const data = await response.json(); if (data.csrf) csrf = data.csrf;
        return {response, data};
    };
}
let sequence = 0;
const robot = (changes = {}) => ({name:'Sprout',color:'mint',antenna:'sprout',personality:'curious',website:'',requestId:(++sequence).toString(16).padStart(32,'0'),...changes});
const checked = [];
try {
    const firstMigration = migrate(); assert.equal(firstMigration.status, 0, firstMigration.stderr);
    assert.equal(migrate().status, 0, 'Migration is repeatable');
    assert.notEqual(migrate(join(root,'public','must-not-create.sqlite')).status, 0, 'Public database refused');
    const unrelated = join(directory,'unrelated.sqlite');
    const unrelatedSetup = spawnSync(php, ['-r', '$d = new PDO("sqlite:" . $argv[1]); $d->exec("CREATE TABLE important (value TEXT)");', unrelated], {encoding:'utf8'});
    assert.equal(unrelatedSetup.status, 0, unrelatedSetup.stderr);
    if (process.platform !== 'win32') await chmod(unrelated, 0o600);
    assert.notEqual(migrate(unrelated).status, 0, 'Unversioned data must not be overwritten');
    checked.push('explicit, repeatable migration; refuses public paths and unrelated data');
    await start();
    const visitor = client();
    let result = await visitor('/api/residents.php');
    assert.equal(result.response.status, 200); assert.equal(result.data.total,0);
    assert.match(result.data.csrf,/^[a-f0-9]{64}$/);
    assert.match(result.response.headers.get('cache-control'), /no-store/);
    result = await visitor('/api/create.php',robot(),{headers:{'X-Sparks-CSRF':'wrong'}}); assert.equal(result.response.status,403);
    result = await visitor('/api/create.php',robot(),{headers:{'Sec-Fetch-Site':'cross-site'}}); assert.equal(result.response.status,403);
    result = await visitor('/api/create.php'); assert.equal(result.response.status,405);
    result = await visitor('/api/create.php', '{'); assert.equal(result.response.status,400);
    result = await visitor('/api/create.php','x'.repeat(2050)); assert.equal(result.response.status,413);
    result = await visitor('/api/create.php',robot(),{headers:{'Content-Type':'text/plain'}}); assert.equal(result.response.status,415);
    result = await visitor('/api/create.php',robot()); assert.equal(result.response.status,429, 'Minimum dwell time');
    await delay(2200);
    for (const changes of [{name:'<script>alert(1)</script>'},{name:'<img src=x onerror=alert(1)>'},{name:'a'.repeat(25)},{name:'invisible\u202ename'},{color:'url(https://bad.example)'},{antenna:'file'},{personality:'custom prompt'},{website:'spam'}]) {
        result = await visitor('/api/create.php',robot(changes)); assert.equal(result.response.status,422, JSON.stringify(changes));
    }
    // Reset only the test fixture's attempt window to test success and limits independently.
    phpCode('$d=new PDO("sqlite:".$argv[1]); $d->exec("DELETE FROM rate_limits");');
    const payload = robot({name:"Étoile O'Spark"});
    result = await visitor('/api/create.php', payload);
    assert.equal(result.response.status,201, JSON.stringify(result.data));
    const created = result.data;
    assert.match(created.resident.id,/^[a-f0-9]{32}$/); assert.match(created.token,/^[a-f0-9]{64}$/);
    const duplicates = await Promise.all([visitor('/api/create.php',payload),visitor('/api/create.php',payload)]);
    for (const duplicate of duplicates) { assert.equal(duplicate.response.status,200); assert.deepEqual(duplicate.data.resident,created.resident); assert.equal(duplicate.data.token,created.token); }
    result = await visitor('/api/create.php',{...payload,name:'Changed'}); assert.equal(result.response.status,409);
    result = await visitor('/api/residents.php'); assert.equal(result.data.total,1);
    const serialized = JSON.stringify(result.data);
    for (const forbidden of [created.token,'token_hash','request_id','owner_hash','secret']) assert.ok(!serialized.includes(forbidden));
    checked.push('CSRF, method, content-type, payload size, dwell time, Unicode names, invalid inputs and HTML/script injection');
    checked.push('duplicate retries preserve ID and token; changed duplicate returns 409; public listing excludes secrets');
    const fresh = client(); await fresh('/api/residents.php');
    result = await fresh('/api/revisit.php',{token:created.token}); assert.equal(result.response.status,200); assert.equal(result.data.resident.id,created.resident.id);
    result = await fresh('/api/revisit.php',{token:'a'.repeat(64)}); assert.equal(result.response.status,404);
    result = await visitor('/api/create.php',robot({name:'Pebble'})); assert.equal(result.response.status,201);
    result = await visitor('/api/create.php',robot({name:'Moss'})); assert.equal(result.response.status,201);
    result = await visitor('/api/create.php',robot()); assert.equal(result.response.status,429); assert.match(result.data.error,/three/);
    phpCode('$d=new PDO("sqlite:".$argv[1]); $d->exec("DELETE FROM rate_limits");');
    for (let i=0;i<10;i++) { result = await visitor('/api/create.php',robot({name:'<bad>'})); assert.equal(result.response.status,422); }
    result = await visitor('/api/create.php',robot({name:'<bad>'})); assert.equal(result.response.status,429); assert.ok(result.response.headers.get('retry-after'));
    result = await fresh('/api/create.php',robot({name:'<bad>'})); assert.equal(result.response.status,429, 'Network limit spans browsers');
    assert.equal(migrate().status,0); await stop(); await start();
    result = await fresh('/api/residents.php'); assert.equal(result.data.total,3);
    assert.equal(result.data.residents.find(r=>r.id===created.resident.id).name,created.resident.name);
    result = await visitor('/api/revisit.php',{token:created.token}); assert.equal(result.response.status,200);
    const serverRendered = await (await fetch(origin+'/village.php')).text();
    assert.ok(serverRendered.includes('Étoile O&#039;Spark'), 'Server-rendered profile escapes user names');
    assert.ok(!serverRendered.includes(created.token));
    const homepage = await (await fetch(origin+'/index.php')).text();
    assert.match(homepage,/href="village.php">Say hello/);
    result=await visitor('/api/world.php');assert.equal(result.response.status,200);assert.equal(result.data.plots.length,3);assert.equal(result.data.objects.length,9);
    result=await visitor('/api/profile.php?id='+created.resident.id);assert.equal(result.response.status,200);assert.equal(result.data.plot.x,0);
    const worldAction={token:created.token,requestId:'f'.repeat(32),action:'plant_tree',x:0,y:0};
    result=await visitor('/api/action.php',worldAction,{headers:{'X-Sparks-CSRF':'wrong'}});assert.equal(result.response.status,403);
    result=await visitor('/api/action.php',worldAction);assert.equal(result.response.status,200);assert.equal(result.data.replayed,false);
    result=await visitor('/api/action.php',worldAction);assert.equal(result.response.status,200);assert.equal(result.data.replayed,true);
    result=await visitor('/api/journal.php');assert.equal(result.response.status,200);assert.equal(result.data.events[0].kind,'plant_tree');
    assert.ok(!JSON.stringify(result.data).includes(created.token));
    assert.ok((await (await fetch(origin+'/world.php')).text()).includes('oak tree at 0, 0'));
    assert.ok((await (await fetch(origin+'/resident.php?id='+created.resident.id)).text()).includes('Étoile O&#039;Spark'));
    checked.push('V2 world/profile/journal HTTP routes, CSRF-protected idempotent actions, saved map, escaped public profiles');
    checked.push('private revisit works from another browser; three-resident browser cap; network limits include invalid requests');
    checked.push('residents and private revisit survive migration rerun and PHP-server restart');
    // Seed only this disposable fixture to exercise population bounds and pagination.
    phpCode('$d=new PDO("sqlite:".$argv[1]); $d->beginTransaction(); $q=$d->prepare("INSERT INTO residents SELECT ?, name,color,antenna,personality,created_at,?, ?, request_hash,? FROM residents LIMIT 1"); for($i=0;$i<10000;$i++){ $s=str_pad((string)$i,32,"0",STR_PAD_LEFT); $q->execute([$s,"fixture",$s,$s]); } $d->exec("DELETE FROM rate_limits"); $d->commit();');
    result = await fresh('/api/residents.php?page=1'); assert.equal(result.data.residents.length,40); assert.equal(result.data.page,1);
    assert.ok(result.data.total>=10000);
    await delay(2200);
    result = await fresh('/api/create.php',robot()); assert.equal(result.response.status,503); assert.match(result.data.error,/full/);
    checked.push('40-resident pagination and global capacity protection');
    const pageHtml = await (await fetch(origin+'/village.php')).text();
    assert.ok(pageHtml.includes('Étoile O’Spark') || pageHtml.includes('resident-directory'));
    assert.ok(!pageHtml.includes(created.token));
    const code = await readFile(join(root,'public/assets/village.js'),'utf8');
    assert.ok(!/innerHTML|insertAdjacentHTML|setInterval/.test(code), 'No untrusted HTML rendering or polling');
    assert.ok(!code.includes('localStorage') && !code.includes('sessionStorage'));
    await stop(); await start(join(directory,'missing.sqlite'));
    result = await client()('/api/residents.php'); assert.equal(result.response.status,503);
    assert.ok(!JSON.stringify(result.data).includes(directory));
    await assert.rejects(readFile(join(directory,'missing.sqlite')), {code:'ENOENT'});
    await stop(); await start(database,join(directory,'missing-sessions'));
    result = await client()('/api/residents.php'); assert.equal(result.response.status,503);
    assert.ok(!JSON.stringify(result.data).includes(directory));
    checked.push('server-rendered escaped profiles; homepage village link; missing database/session storage fails safely without creating data');
    console.log('PASS\n' + checked.map(item=>'  ✓ '+item).join('\n'));
} finally {
    await stop();
    // The only removed tree is the exact directory returned by mkdtemp above.
    await rm(directory,{recursive:true,force:true});
}
