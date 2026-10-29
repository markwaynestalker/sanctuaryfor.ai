// Optional synthetic browser review; no external requests or live database.
const {spawn,spawnSync}=require('node:child_process'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{createServer}=require('node:net'),{createHash}=require('node:crypto'),{chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'../..'),dir=await fs.mkdtemp(path.join(os.tmpdir(),'multi-message-74-')),shots=path.join(root,'tmp/multi-message-74'),php=process.env.PHP_BINARY||'php',db=path.join(dir,'synthetic.sqlite');
 const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0',LITTLE_SPARKS_SLEEP_ENABLED:'0',LITTLE_SPARKS_SECURE_COOKIES:'0'};
 let browser,server;await fs.mkdir(shots,{recursive:true});
 const run=args=>{const r=spawnSync(php,args,{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);return r.stdout;};
 try{
  for(const s of ['village','ai','activity','memory','identity','director','appearance'])run(['scripts/migrate-'+s+'.php','--database='+db]);
  const fixture=JSON.parse(run(['tests/browser/multimessage-fixture.php',db])),before=createHash('sha256').update(await fs.readFile(db)).digest('hex');
  const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));const origin='http://127.0.0.1:'+port;
  server=spawn(php,['-S','127.0.0.1:'+port,'-t','public'],{cwd:root,env:{...env,LITTLE_SPARKS_DB:db},stdio:'ignore'});
  for(let i=0;i<80;i++){try{const r=await fetch(origin+'/journal.php');assert.ok(r.ok);break;}catch{await new Promise(ok=>setTimeout(ok,50));}}
  browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
  for(const [name,width] of [['desktop',1280],['tablet',820],['phone',390],['compact',320]]){
   const ctx=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'});await ctx.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());const p=await ctx.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
   for(const [label,url] of [['journal','journal.php'],['archive','conversations.php?open='+fixture.conversation],['profile','resident.php?id='+fixture.ids[0]]]){
    await p.goto(origin+'/'+url);assert.equal(await p.locator('[data-speech]').count(),16,label+' includes every original message');
    if(label==='journal')await p.getByText('Read 16 saved messages',{exact:true}).click();
    assert.ok(await p.locator('[data-speech]').last().isVisible(),label+' final turn readable');assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,label+' fits viewport');
    if(label!=='profile')await p.screenshot({path:path.join(shots,label+'-'+name+'.png'),fullPage:true});
   }
   await p.goto(origin+'/village.php');await p.locator('.conversation-indicator:visible').first().click();assert.equal(await p.locator('#dialogue-lines [data-speech]').count(),16);assert.ok((await p.locator('#dialogue-lines').innerText()).includes('Saved turn 16:'));assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await p.screenshot({path:path.join(shots,'commons-'+name+'.png'),fullPage:true});
   assert.deepEqual(errors,[]);await ctx.close();console.log('PASS '+name+': full journal/archive/profile/commons transcript and no horizontal overflow');
  }
  const ctx=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:900}}),p=await ctx.newPage();await ctx.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());await p.goto(origin+'/journal.php');await p.getByText('Read 16 saved messages',{exact:true}).click();assert.ok(await p.locator('[data-speech]').last().isVisible());await p.goto(origin+'/conversations.php?open='+fixture.conversation);assert.ok(await p.locator('[data-speech]').last().isVisible());await ctx.close();
  assert.equal(createHash('sha256').update(await fs.readFile(db)).digest('hex'),before,'Browser reads preserve every database byte');console.log('PASS native no-JS journal/archive and unchanged isolated database; zero external or paid calls');
 }finally{if(browser)await browser.close();if(server){const done=new Promise(ok=>server.once('exit',ok));server.kill();await done;}await fs.rm(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
