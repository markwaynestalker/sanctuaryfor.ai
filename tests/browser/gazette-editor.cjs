// Synthetic approved newspaper, no JavaScript, credentials or external requests.
const {spawn,spawnSync}=require('node:child_process'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{createServer}=require('node:net'),{createHash}=require('node:crypto'),{chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'../..'),dir=await fs.mkdtemp(path.join(os.tmpdir(),'little-sparks-journal-test-editor-browser-')),shots=path.join(root,'tmp/gazette-editor-78'),php=process.env.PHP_BINARY||'php';
 const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0'};
 const run=args=>{const r=spawnSync(php,args,{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);return r.stdout;};let browser,server;
 try{
  await fs.mkdir(shots,{recursive:true});run(['tests/journal-core.php',dir]);const base=path.join(dir,'v6.sqlite');await fs.copyFile(path.join(dir,'v4.sqlite'),base);await fs.chmod(base,0o600);
  for(const s of ['memory','identity','director','appearance'])run(['scripts/migrate-'+s+'.php','--database='+base]);run(['tests/gazette-core.php',dir]);
  const fixture=JSON.parse(run(['tests/gazette-integration-core.php',dir])),db=fixture.database,before=createHash('sha256').update(await fs.readFile(db)).digest('hex');
  const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));const origin='http://127.0.0.1:'+port;
  server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:db},stdio:'ignore'});for(let i=0;i<80;i++){try{assert.ok((await fetch(origin+'/gazette.php?date=2026-10-29')).ok);break;}catch{await new Promise(ok=>setTimeout(ok,50));}}
  browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
  for(const [label,width] of [['desktop',1280],['tablet',820],['phone',390],['compact',320]]){
   const ctx=await browser.newContext({viewport:{width,height:900},javaScriptEnabled:false,reducedMotion:'reduce'}),p=await ctx.newPage(),api=[],errors=[];
   await ctx.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.url().includes('/api/'))api.push(r.url());});
   await p.goto(origin+'/gazette.php?date=2026-10-29');assert.ok(await p.getByRole('heading',{name:'A lost-and-found remains an idea',exact:true}).isVisible());
   assert.ok((await p.locator('.gazette-editor-note').innerText()).includes('reviewed and approved'));assert.equal(await p.locator('.gazette-story > p').count()>=4,true);
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await p.screenshot({path:path.join(shots,'editor-'+label+'.png'),fullPage:true});
   await p.getByRole('link',{name:'Read revision 1',exact:true}).click();assert.ok((await p.locator('[aria-label="Published revisions"]').innerText()).includes('Revision 1'));
   await p.getByRole('link',{name:'Read the original exchange',exact:true}).first().click();await p.waitForURL('**/conversations.php?**');assert.ok(await p.locator('.archive-expansion:not([hidden])').count());
   assert.deepEqual(api,[]);assert.deepEqual(errors,[]);await ctx.close();console.log('PASS editor '+label+': narrative, owner attribution, original revision/source, no overflow, no JS/API requests');
  }
  assert.equal(createHash('sha256').update(await fs.readFile(db)).digest('hex'),before);
 }finally{if(browser)await browser.close();if(server){const done=new Promise(ok=>server.once('exit',ok));server.kill();await done;}await fs.rm(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
