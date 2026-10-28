// Optional browser review: PHP_BINARY and Playwright on NODE_PATH; never run against production.
const {spawn,spawnSync}=require('node:child_process'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict'),{createServer}=require('node:net'),{createHash}=require('node:crypto'),{chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'../..'),dir=await fs.mkdtemp(path.join(os.tmpdir(),'village-growth-63-')),shots=path.resolve(process.argv[2]||path.join(root,'docs/screenshots/village-growth-63')),php=process.env.PHP_BINARY||'php';
 const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>!k.startsWith('LITTLE_SPARKS_')&&!k.startsWith('OPENAI_'))),OPENAI_API_KEY:'',LITTLE_SPARKS_AI_ENABLED:'0',LITTLE_SPARKS_DIRECTOR_ENABLED:'0',LITTLE_SPARKS_SIMULATION_ENABLED:'0',LITTLE_SPARKS_SCHEDULER_ENABLED:'0',LITTLE_SPARKS_SLEEP_ENABLED:'0',LITTLE_SPARKS_SECURE_COOKIES:'0'};
 let browser;const servers=[];await fs.mkdir(shots,{recursive:true});
 const run=args=>{const r=spawnSync(php,args,{cwd:root,env,encoding:'utf8'});assert.equal(r.status,0,r.stderr);return r.stdout;};
 const hash=async db=>createHash('sha256').update(await fs.readFile(db)).digest('hex');
 async function server(db,rest=false){
  const socket=createServer();await new Promise(ok=>socket.listen(0,'127.0.0.1',ok));const port=socket.address().port;await new Promise(ok=>socket.close(ok));
  const hour=Number(new Intl.DateTimeFormat('en-US',{timeZone:'America/New_York',hourCycle:'h23',hour:'2-digit'}).format(new Date()));
  const p=spawn(php,['-d','session.save_path='+dir,'-S','127.0.0.1:'+port,'-t',path.join(root,'public')],{cwd:root,env:{...env,LITTLE_SPARKS_DB:db,LITTLE_SPARKS_SLEEP_ENABLED:rest?'1':'0',LITTLE_SPARKS_SLEEP_START_HOUR:String(hour),LITTLE_SPARKS_SLEEP_END_HOUR:String((hour+1)%24)},stdio:'ignore'});servers.push(p);
  const origin='http://127.0.0.1:'+port;for(let i=0;i<80;i++){try{await fetch(origin+'/village.php');return origin;}catch{await new Promise(ok=>setTimeout(ok,40));}}throw Error('Fixture server unavailable');
 }
 async function capture(p,selector,file){
  await p.waitForLoadState('networkidle');
  const box=await p.locator(selector).boundingBox(),scroll=await p.evaluate(()=>({x:scrollX,y:scrollY}));
  await p.screenshot({path:path.join(shots,file),fullPage:true,clip:{x:box.x+scroll.x,y:box.y+scroll.y,width:box.width,height:box.height}});
 }
 async function context(origin,width,javaScriptEnabled=true){const ctx=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',javaScriptEnabled});await ctx.route('**/*',r=>r.request().url().startsWith(origin+'/')?r.continue():r.abort());return ctx;}
 try{
  browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
  for(const count of (process.env.GROWTH_REVIEW_COUNTS?process.env.GROWTH_REVIEW_COUNTS.split(',').map(Number):[1,3,6,12,40,41])){
   const db=path.join(dir,'village-'+count+'.sqlite');for(const s of ['village','ai','activity','memory','identity','director','appearance'])run(['scripts/migrate-'+s+'.php','--database='+db]);
   assert.equal(JSON.parse(run(['tests/browser/growth-fixture.php',db,String(count)])).residents,count);const before=await hash(db),origin=await server(db);
   for(const [label,width] of (count===41?[['phone',390]]:[['desktop',1280],['tablet',820],['phone',390],['compact',320]])){
    const ctx=await context(origin,width),p=await ctx.newPage(),errors=[],requests=[];p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.url().includes('/api/'))requests.push(r.url());});
    await p.goto(origin+'/village.php');await p.locator('#robot-layer .village-resident:visible').first().waitFor();await p.locator('#resident-discovery:visible').waitFor();
    const visible=Math.min(count,width<=650?6:12);assert.equal(await p.locator('#robot-layer .village-resident:visible').count(),visible);assert.equal(requests.length,2,requests.join("\n"));
    assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'No horizontal overflow');
    const boxes=await p.locator('.village-resident:visible').evaluateAll(nodes=>nodes.map(e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom};}));
    for(let i=0;i<boxes.length;i++)for(let j=0;j<i;j++)assert.ok(boxes[i].right<=boxes[j].x||boxes[j].right<=boxes[i].x||boxes[i].bottom<=boxes[j].y||boxes[j].bottom<=boxes[i].y,'Full name/avatar bounds never overlap');
    if(count===1)await capture(p,'.landscape-section',`${count}-awake-${label}.png`);
    if(count===40){
     const entry=p.locator('#resident-directory li').filter({hasText:'Élodie'}),target=await entry.locator('button').first().innerText();assert.ok(target.includes('Élodie'));
     const marker=p.locator('.conversation-indicator:visible');assert.ok(await marker.count(),'Synthetic first-pair exchange must be visible');await marker.first().click();const words=await p.locator('#dialogue-lines').innerText();
     await p.locator('#resident-search').fill('éLODIE');assert.equal(await p.locator('#resident-directory li:visible').count(),1);assert.equal(requests.length,2,requests.join("\n"));
     await entry.getByRole('button',{name:'Locate Élodie in the village',exact:true}).focus();await p.keyboard.press('Enter');
     await p.locator('#robot-layer .village-resident:focus').filter({hasText:'Élodie'}).waitFor();
     assert.equal(await p.locator('#robot-layer .village-resident:focus').filter({hasText:'Élodie'}).count(),1,'Keyboard locate focuses actual resident');assert.equal(requests.length,3);
     if(words)assert.equal(await p.locator('#dialogue-lines').innerText(),words,'Locate preserves exact saved speech');
     await p.locator('#clear-resident-search').click();assert.equal(await p.locator('#resident-directory li:visible').count(),40);assert.equal(requests.length,3);
     // Delayed neighborhood read cannot steal focus from new user input.
     let release;await p.route('**/api/commons.php*',async route=>{await new Promise(ok=>{release=ok;});await route.continue();});
     await p.locator('#resident-directory li').first().locator('.locate-resident').click();await p.locator('#resident-search').focus();for(let i=0;i<500&&!release;i++)await new Promise(ok=>setTimeout(ok,10));assert.ok(release,'A changed neighborhood requests its scoped commons');const response=p.waitForResponse(r=>r.url().includes('commons.php'));release();await response;assert.equal(await p.locator('#resident-search').evaluate(el=>el===document.activeElement),true);await p.unroute('**/api/commons.php*');
     // Capture stable full-page clips after the real input/request assertions.
     await p.locator('#resident-search').fill('Élodie');
     await capture(p,'#residents',`discovery-${label}.png`);
     await capture(p,'.landscape-section',`${count}-awake-${label}.png`);
    }
    if(count===41){await p.locator('#resident-search').fill('not a name');await p.locator('#next-page').click();await p.locator('#page-status').filter({hasText:'Page 2 of 2'}).waitFor();assert.equal(await p.locator('#resident-search').inputValue(),'');assert.equal(await p.locator('#resident-directory li:visible').count(),1,'New directory page clears its local query');}
    assert.deepEqual(errors,[]);console.log(`PASS browser ${count} residents / ${label}: full footprints, bounded scene and discovery`);await ctx.close();
   }
   if(count===40){
    const plain=await context(origin,390,false),page=await plain.newPage();await page.goto(origin+'/village.php');assert.equal(await page.locator('#resident-discovery').isVisible(),false);assert.equal(await page.locator('#resident-directory details').count(),40);assert.ok(await page.locator('#resident-directory a[href*="resident.php"]').count());await plain.close();
    const restOrigin=await server(db,true);for(const width of [1280,320]){const ctx=await context(restOrigin,width),p=await ctx.newPage();await p.goto(restOrigin+'/village.php');await p.locator('.commons-home:visible').first().waitFor();await p.locator('#resident-search').fill('Élodie');await p.getByRole('button',{name:'Locate Élodie in the village',exact:true}).click();await p.locator('.commons-home:focus').filter({hasText:'Élodie'}).waitFor();assert.equal(await p.evaluate(()=>document.activeElement.classList.contains('commons-home')),true);await capture(p,'.landscape-section',`40-resting-${width}.png`);await ctx.close();}
   }
   assert.equal(await hash(db),before,'Browser GET/filter/locate leave synthetic SQLite bytes unchanged');
  }
  console.log('PASS browser matrix, Unicode keyboard discovery, delayed-focus safety, native no-JS profiles, page-local pagination, shared rest and unchanged synthetic SQLite; zero external/paid calls');
 }finally{if(browser)await browser.close();for(const p of servers){const end=new Promise(ok=>p.once('exit',ok));p.kill();await end;}await fs.rm(dir,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
