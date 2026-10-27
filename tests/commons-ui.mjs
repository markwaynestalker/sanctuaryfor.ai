// Offline DOM/clock harness: no browser credentials, network, dependencies or AI calls.
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const helperSource=await readFile(new URL('../public/assets/commons.js',import.meta.url),'utf8');
const avatarSource=await readFile(new URL('../public/assets/avatars.js',import.meta.url),'utf8');
const avatarRegistry=JSON.parse(await readFile(new URL('../public/assets/avatars.json',import.meta.url),'utf8'));avatarRegistry.sprite='/assets/spark-forms.svg?v=123456789abc';
const villageSource=await readFile(new URL('../public/assets/village.js',import.meta.url),'utf8');
const meadowSource=await readFile(new URL('../public/assets/meadow.js',import.meta.url),'utf8');
const atmosphereSource=await readFile(new URL('../public/assets/atmosphere.js',import.meta.url),'utf8');
const atmosphereContext=vm.createContext({});vm.runInContext(atmosphereSource,atmosphereContext);
const a='a'.repeat(32),b='b'.repeat(32),c='c'.repeat(32);
const residents=[{id:a,name:'Sparky',color:'mint',antenna:'orb',personality:'playful',created_at:'2026-10-24T10:00:00Z'},
    {id:b,name:'Skippy Jr',color:'lavender',antenna:'sprout',personality:'curious',created_at:'2026-10-24T10:00:00Z'}];
const exchange={id:'saved-exchange',provider:'openai',created_at:'2026-10-24T13:00:00Z',messages:[
    {resident_id:b,name:'Skippy Jr',text:'Only after sunset—and they’re much too high to trip over!'},
    {resident_id:a,name:'Sparky',text:'Do you think the stars arrange themselves into garden paths?'}]};
const helpers=vm.createContext({});vm.runInContext(helperSource,helpers);const h=helpers.SparksCommons;
assert.equal(h.profileUrl(a),'/resident.php?id='+a);assert.throws(()=>h.profileUrl('javascript:alert(1)'));
const older={...exchange,id:'older',created_at:'2026-10-23T12:00:00Z'};
assert.equal(h.latestExchanges([older,exchange],[a,b]).get(h.pairKey(b,a)).id,exchange.id);
assert.equal(h.latestExchanges([exchange],[a,c]).size,0);
for(const invalid of [{...exchange,messages:[null,null]},{...exchange,provider:'unknown'},{...exchange,messages:[{...exchange.messages[0],text:'x'.repeat(161)},exchange.messages[1]]}])assert.equal(h.latestExchanges([invalid],[a,b]).size,0);
assert.equal(h.playbackTurn(exchange,0).resident_id,b);assert.equal(h.playbackTurn(exchange,h.turnDuration(exchange.messages[0])).resident_id,a);
assert.equal(h.playbackTurn(exchange,24001),null);
assert.equal(h.replayCooldown(undefined),60);
for(const value of [0,15,3600])assert.equal(h.replayCooldown({replay_cooldown_seconds:value}),value);
for(const value of [-1,3601,'60',null,1.5,Infinity])assert.throws(()=>h.replayCooldown({replay_cooldown_seconds:value}));
let stored;const storage={getItem:()=>stored,setItem:(key,value)=>{stored=value;}};let played=h.replayHistory(storage);
assert.ok(played.canPlay('first',1000,60));played.record('first',1000);assert.ok(!played.canPlay('first',100000,0),'Played dialogue never repeats');
assert.ok(!played.canPlay('fresh',60999,60));assert.ok(played.canPlay('fresh',61000,60),'Cooldown gates a different new exchange at its exact boundary');
played=h.replayHistory(storage);assert.ok(!played.canPlay('first',200000,0),'Navigation/reload preserves per-tab public history');
for(let i=0;i<1023;i++)played.record('id-'+i,1000);assert.ok(!played.canPlay('more',200000,0),'Bounded history fills safely, without evicting played IDs');
const denied=h.replayHistory({getItem(){throw new Error('denied');},setItem(){throw new Error('denied');}});denied.record('first',1000);assert.ok(!denied.canPlay('first',200000,0),'Storage-denied fallback remains quiet');

class Element{
    constructor(tag='div',doc){this.tagName=tag.toUpperCase();this.doc=doc;this.children=[];this.listeners={};this.attributes={};this.dataset={};this.style={setProperty:(name,value)=>{this.style[name]=value;}};this.hidden=false;this.open=false;this.isConnected=true;this._text='';this.className='';this.classList={add:name=>{this.className+=' '+name;}};}
    set textContent(value){this._text=String(value);this.children=[];}get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
    set innerHTML(value){throw new Error('Untrusted HTML must never be rendered: '+value);}
    append(...children){this.children.push(...children);}replaceChildren(...children){this.children=[];this._text='';this.append(...children);}
    remove(){this.isConnected=false;}
    setAttribute(name,value){this.attributes[name]=String(value);if(name==='href')this.href=String(value);}
    addEventListener(type,listener){(this.listeners[type]??=[]).push(listener);}
    focus(){this.doc.activeElement=this;}showModal(){this.open=true;}close(){this.open=false;for(const f of this.listeners.close??[])f({});}
    click(){const event={defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};for(const f of this.listeners.click??[])f(event);if(this.href&&!event.defaultPrevented)this.doc.location.href=new URL(this.href,this.doc.location.href).href;}
}
async function meadow({mobile=false,reduced=false,third=false,conversation=exchange,replay=60,sleep,weather,people=residents,width=mobile?388:1000}={}){
    const elements=new Map(),requests=[],frames=new Map(),media=new Map(),motionStates=[];let clock=1000,sequence=0;
    const location={href:'http://fixture.test/village.php',pathname:'/village.php',search:'',hash:''};
    const document={location,hidden:false,listeners:{},getElementById(id){if(id==='creation-appearance')return null;if(!elements.has(id))elements.set(id,new Element(id.includes('link')?'a':'div',this));return elements.get(id);},createElement(tag){return new Element(tag,this);},createElementNS(ns,tag){return new Element(tag,this);},addEventListener(type,f){this.listeners[type]=f;}};
    const $=id=>document.getElementById(id);$('spark-avatar-registry').textContent=JSON.stringify(avatarRegistry);$('robot-color').value='mint';$('robot-antenna').value='orb';$('private-link-panel').hidden=true;
    $('landscape').clientWidth=width;$('landscape').clientHeight=520;
    const population=third?[...people,{...residents[0],id:c,name:'No saved home'}]:people;
    let resize;
    const math=Object.create(Math);math.random=()=>.5;
    const context=vm.createContext({document,location,history:{replaceState(){}},URL,URLSearchParams,Date,Math:math,performance:{now:()=>clock},
        SparksAtmosphere:{...atmosphereContext.SparksAtmosphere,setPaused:paused=>motionStates.push(paused)},
        matchMedia(query){const value={matches:query.includes('reduced')?reduced:mobile,addEventListener(type,f){this.listener=f;}};media.set(query,value);return value;},
        addEventListener(){},requestAnimationFrame(f){frames.set(++sequence,f);return sequence;},cancelAnimationFrame(id){frames.delete(id);},
        ResizeObserver:class{constructor(f){resize=f;}observe(){}},
        fetch:async(url,options)=>{requests.push({url,options});const ids=new URL(url,'http://fixture.test/').searchParams.get('ids')?.split(',')||[];return {ok:true,json:async()=>url.includes('residents.php')?{residents:population,total:population.length,page:0,lastPage:0,csrf:'fixture'}:{homes:people.map((r,i)=>({resident_id:r.id,plot:{x:i*4,y:0,width:4,height:4},objects:[{kind:'house',x:i*4+1,y:1},...(i===0?[{kind:'tree',x:3,y:3},{kind:'flowers',x:0,y:2},{kind:'bush',x:3,y:0},{kind:'path',x:1,y:2},{kind:'decoration',x:2,y:0}]:[])]})).filter(h=>ids.includes(h.resident_id)),conversations:conversation?[conversation]:[]}};}});
    const originalFetch=context.fetch;context.fetch=async(...args)=>{const result=await originalFetch(...args);if(args[0].includes('commons.php')){const json=result.json;result.json=async()=>({...await json(),settings:{replay_cooldown_seconds:replay,...(sleep?{sleep}:{}),...(weather?{weather}:{})}});}return result;};
    vm.runInContext(helperSource,context);vm.runInContext(meadowSource,context);vm.runInContext(avatarSource,context);vm.runInContext(villageSource,context);await new Promise(setImmediate);
    return {$,document,requests,frames,media,motionStates,resize(width){$('landscape').clientWidth=width;resize([{contentRect:{width,height:520}}]);},setConversation(value){conversation=value;},advanceTime(ms){clock+=ms;document.listeners['sparks:clock']?.();},step(){clock+=80;const callbacks=[...frames.values()];frames.clear();for(const f of callbacks)f(clock);},clock:()=>clock};
}
for(const mobile of [false,true]){
    const m=await meadow({mobile});assert.equal(m.requests.length,2,'one listing and one read-only saved-life request');assert.equal(m.$('home-layer').children.length,2);
    for(let i=0;i<2;i++)assert.equal(m.$('home-layer').children[i].href,'/resident.php?id='+residents[i].id);
    assert.ok(m.$('home-layer').children[1].className.includes('color-lavender'),'cottage matches owner color');
    const yard=m.$('home-layer').children[0].children[0];assert.equal(yard.children.length,6,'yard contains only supplied saved objects');
    const tree=yard.children.find(el=>el.className.includes('yard-tree'));assert.equal(tree.style.left,'87.5%');assert.equal(tree.style.top,'87.5%','yard preserves the actual within-plot placement');
    const actors=m.$('robot-layer').children;
    let seenFirst=false,seenSecond=false;
    for(let tick=0;tick<1100&&!seenSecond;tick++){
        m.step();const bubbles=actors.map(el=>el.children.at(-1).textContent);assert.ok(bubbles.filter(Boolean).length<=1,'only one speech bubble at a time');
        if(bubbles[1].includes(exchange.messages[0].text)){seenFirst=true;assert.ok(bubbles[1].includes('Saved AI-generated dialogue'));}
        if(bubbles[0].includes(exchange.messages[1].text)){assert.ok(seenFirst,'recorded order preserved, regardless of actor ordering');seenSecond=true;}
    }
    assert.ok(seenFirst&&seenSecond,'both actual saved messages replay on their correct residents');assert.equal(m.requests.length,2,'animation never polls or generates dialogue');
    m.$('motion-toggle').click();assert.ok(m.motionStates.at(-1),'Weather shares the wandering pause control');assert.ok(actors.every(el=>!el.children.at(-1).textContent),'pause clears bubbles');assert.ok(m.$('saved-dialogue').textContent.includes(exchange.messages[0].text),'stationary transcript remains available');
    for(let i=0;i<2;i++){
        m.$('resident-directory').children[i].children[0].click();assert.equal(m.$('resident-profile').open,true);assert.equal(m.$('profile-life-link').href,'/resident.php?id='+residents[i].id);
        assert.equal(m.$('profile-idea-link').href,'/owner.php?resident='+residents[i].id,'Operator link targets the selected immutable resident');
        m.$('profile-life-link').click();assert.equal(m.$('resident-profile').open,false);assert.equal(m.document.location.href,'http://fixture.test/resident.php?id='+residents[i].id,'native modal link navigates to selected resident');
    }
}
const stationary=await meadow({reduced:true,third:true});assert.equal(stationary.frames.size,0);assert.equal(stationary.$('home-layer').children.length,2,'no invented house for resident without saved home');assert.ok(stationary.$('saved-dialogue').textContent.includes(exchange.messages[0].text));
const six=[...residents,...Array.from({length:4},(_,i)=>({...residents[0],id:(i+1).toString(16).padStart(32,'0'),name:i===0?'A'.repeat(24):'Neighbor '+i}))];
for(const width of [278,333,348,388]){
    const m=await meadow({mobile:true,reduced:true,people:six,width});assert.equal(m.$('robot-layer').children.length,6,'Six phone residents are rendered');assert.equal(m.$('home-layer').children.length,width<=360?0:6,'Only extra-compact scenes omit cottages');assert.equal(m.$('home-layer').hidden,width<=360);assert.equal(m.$('compact-meadow-note').hidden,width>360);assert.equal(m.$('resident-directory').children.length,6);assert.ok(m.$('neighborhood-controls').hidden);assert.equal(m.frames.size,0,'All six are accessible without animation');
    for(const home of m.$('home-layer').children){assert.ok(parseFloat(home.style.left)>=0&&parseFloat(home.style.left)+parseFloat(home.style.width)<=width);assert.ok(six.some(r=>home.href==='/resident.php?id='+r.id));assert.ok(home.attributes['aria-label']);}
    assert.equal(m.requests.length,2,'Six residents need no polling or generation');
}
const growingPeople=Array.from({length:40},(_,i)=>({...residents[0],id:(i+1).toString(16).padStart(32,'0'),name:'Neighbor '+i}));
const growing=await meadow({mobile:true,reduced:true,people:growingPeople});assert.equal(growing.$('resident-directory').children.length,40);assert.equal(growing.$('robot-layer').children.length,6);assert.ok(!growing.$('neighborhood-controls').hidden);
growing.$('next-neighborhood').click();await new Promise(setImmediate);assert.equal(growing.$('home-layer').children.length,6);assert.equal(growing.$('home-layer').children[0].href,'/resident.php?id='+growingPeople[6].id);assert.ok(growing.$('neighborhood-status').textContent.includes('7–12'));assert.equal(growing.requests.length,3,'Explicit neighborhood change reads only its saved life');
growing.$('previous-neighborhood').click();await new Promise(setImmediate);assert.equal(growing.$('home-layer').children[0].href,'/resident.php?id='+growingPeople[0].id);
const seenHomes=new Set();for(let group=0;group<7;group++){for(const home of growing.$('home-layer').children)seenHomes.add(home.href);growing.$('next-neighborhood').click();await new Promise(setImmediate);assert.ok(growing.$('robot-layer').children.length<=6);}assert.equal(seenHomes.size,40,'Every resident on a growing directory page can reach the commons, including the final partial group');assert.ok(growing.$('neighborhood-status').textContent.includes('Neighborhood 1 of 7'),'Neighborhood navigation wraps without losing residents');
const resized=await meadow({people:six,reduced:true});const beforePositions=resized.$('robot-layer').children.map(el=>el.style.left+','+el.style.top);resized.resize(1100);assert.equal(resized.$('home-layer').children.length,6);assert.ok(resized.$('robot-layer').children.filter((el,i)=>el.style.left+','+el.style.top===beforePositions[i]).length>=3,'Resize preserves valid local positions');assert.equal(resized.requests.length,2,'Resizing never polls');
resized.resize(320);assert.equal(resized.$('home-layer').children.length,0);assert.ok(resized.$('home-layer').hidden);assert.equal(resized.$('robot-layer').children.length,6);
const compactPositions=resized.$('robot-layer').children.map(el=>el.style.left+','+el.style.top);resized.resize(330);assert.deepEqual(resized.$('robot-layer').children.map(el=>el.style.left+','+el.style.top),compactPositions,'Valid compact positions survive a small width change');
resized.resize(388);assert.equal(resized.$('home-layer').children.length,6,'Saved gardens return when scene width grows');assert.equal(resized.requests.length,2,'Fold/unfold-style layout changes reuse saved state without requests');
const folded=await meadow({people:six,mobile:true,width:320});folded.resize(1000);const foldMedia=folded.media.get('(max-width: 650px)');foldMedia.matches=false;foldMedia.listener();assert.equal(folded.requests.length,2,'Changing the mobile media query with unchanged neighbors does not refetch');assert.equal(folded.$('home-layer').children.length,6);
const modal=await meadow();modal.$('resident-directory').children[0].children[0].click();assert.ok(modal.motionStates.at(-1),'Weather pauses with the profile dialog');modal.$('resident-profile').close();assert.equal(modal.motionStates.at(-1),false);
modal.document.hidden=true;modal.document.listeners.visibilitychange();assert.ok(modal.motionStates.at(-1));modal.document.hidden=false;modal.document.listeners.visibilitychange();assert.equal(modal.motionStates.at(-1),false);
const beforeZone=modal.requests.length;modal.document.listeners['sparks:timezone']();assert.equal(modal.requests.length,beforeZone,'Display-zone changes do not refetch conversations');
const idle=await meadow({conversation:null});for(let i=0;i<700;i++)idle.step();assert.ok(idle.$('robot-layer').children.every(el=>!el.children.at(-1).textContent),'no invented conversation when pair has no saved exchange');
const hostile={...exchange,messages:exchange.messages.map(m=>({...m,text:'<img src=x onerror=alert(1)>'}))};const escaped=await meadow({reduced:true,conversation:hostile});assert.ok(escaped.$('saved-dialogue').textContent.includes('<img src=x onerror=alert(1)>'),'dialogue is literal text, never innerHTML');
for(const replay of [0,3600]){
    const m=await meadow({replay});let episodes=0,wasSpeaking=false;
    for(let tick=0;tick<2500;tick++){m.step();const speaking=m.$('robot-layer').children[1].children.at(-1).textContent.includes(exchange.messages[0].text);if(speaking&&!wasSpeaking)episodes++;wasSpeaking=speaking;}
    assert.equal(episodes,1,'Previously shown dialogue stays silent even when the cooldown expires');assert.equal(m.requests.length,2,'Replay never creates server activity');
}
const refreshed=await meadow({replay:0});for(let tick=0;tick<2000;tick++)refreshed.step();
refreshed.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);
for(let tick=0;tick<2000;tick++){refreshed.step();assert.ok(refreshed.$('robot-layer').children.every(el=>!el.children.at(-1).textContent),'Explicit refresh never resets played public IDs');}
const freshExchange={...exchange,id:'newly-saved',messages:exchange.messages.map(m=>({...m,text:'A new saved thought about moonflowers.'}))};refreshed.setConversation(freshExchange);refreshed.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);
let foundFresh=false;for(let tick=0;tick<1500;tick++){refreshed.step();if(refreshed.$('robot-layer').children.some(el=>el.children.at(-1).textContent.includes(freshExchange.messages[0].text)))foundFresh=true;}
assert.ok(foundFresh,'Explicit read-only refresh discovers and replays a new saved ID');assert.equal(refreshed.requests.length,6,'Two requests per explicit refresh, no polling');
for(const mobile of [false,true]){
    const text='é'.repeat(159)+'🌻',boundary={...exchange,id:'boundary-'+mobile,messages:exchange.messages.map(m=>({...m,text}))};
    assert.equal(Array.from(text).length,160);
    assert.equal(h.latestExchanges([boundary],[a,b]).values().next().value.messages[0].text,text);
    const m=await meadow({mobile,conversation:boundary});let seen=false;
    for(let tick=0;tick<1500;tick++){m.step();if(m.$('robot-layer').children.some(el=>el.children.at(-1).textContent.includes(text)))seen=true;}
    assert.ok(seen,'Desktop/mobile playback renders all 160 Unicode characters, including the final emoji');
    const still=await meadow({mobile,reduced:true,conversation:boundary});assert.ok(still.$('saved-dialogue').textContent.includes(text),'Reduced-motion transcript keeps full boundary speech');
}
console.log('PASS six compact phone residents, restored wider cottages, all 40 neighbors reachable, stable fold/unfold without refetch, desktop/mobile profile links, shared atmosphere pause, full Unicode boundary speech, literal text and reduced motion');

const sleepPolicy={enabled:true,sleeping:false,time_zone:'America/New_York',start_hour:23,end_hour:7,server_time:Date.parse('2026-01-02T03:59:00Z')/1000};
assert.equal(h.sleepConfiguration(undefined),null);
for(const bad of [null,{...sleepPolicy,enabled:1},{...sleepPolicy,start_hour:24},{...sleepPolicy,end_hour:23},{...sleepPolicy,time_zone:'UTC'},{...sleepPolicy,server_time:-1}])assert.throws(()=>h.sleepConfiguration(bad));
for(const [at,expected] of [['2026-01-02T03:59:59Z',false],['2026-01-02T04:00:00Z',true],['2026-01-02T12:00:00Z',false],['2026-07-02T03:00:00Z',true],['2026-07-02T11:00:00Z',false],['2026-03-08T07:00:00Z',true],['2026-11-01T06:30:00Z',true]])assert.equal(h.sleepingAt(sleepPolicy,Date.parse(at)),expected,at);
for(const mobile of [false,true]){
 const m=await meadow({mobile,sleep:sleepPolicy});assert.equal(m.frames.size,1,'Awake has exactly one animation loop');
 m.advanceTime(120000);assert.equal(m.frames.size,0,'Server-anchored shared clock stops nighttime wandering despite visitor date');
 assert.equal(m.$('sleep-note').hidden,false);assert.ok(m.$('robot-layer').children.every(el=>el.dataset.sleeping==='true'&&el.attributes['aria-label'].includes('sleeping')));
 assert.equal(m.$('home-layer').children.length,2);assert.ok(m.$('saved-dialogue').textContent.includes(exchange.messages[0].text),'History remains readable during rest');
 m.$('resident-directory').children[0].children[0].click();assert.equal(m.$('profile-life-link').href,'/resident.php?id='+a);m.$('resident-profile').close();
 m.advanceTime(8*3600000);assert.equal(m.$('sleep-note').hidden,true);assert.equal(m.frames.size,1,'Wake restores one animation loop');assert.equal(m.requests.length,2,'Clock transitions never poll or generate');
}
for(const reduced of [false,true]){
 const m=await meadow({reduced,sleep:sleepPolicy});if(!reduced)m.$('motion-toggle').click();m.advanceTime(120000);m.advanceTime(8*3600000);assert.equal(m.frames.size,0,'Rest/wake respects existing pause/reduced motion');
}
console.log('PASS shared rest/wake clock, DST, strict public policy, desktop/mobile profiles/history, reduced motion and no extra HTTP');

const operatorPopup=await meadow({mobile:true,reduced:true});operatorPopup.$('resident-directory').children[1].children[0].click();operatorPopup.$('profile-idea-link').click();assert.equal(operatorPopup.$('resident-profile').open,false);assert.equal(operatorPopup.document.location.href,'http://fixture.test/owner.php?resident='+b,'Operator link navigates natively out of modal without triggering AI');

for(const mobile of [false,true]){
 const weather={version:1,id:'f'.repeat(64),kind:'snowy',classification:'reported_farm_weather',temperature_f:43,description:'Fair skies',observed_at:Math.floor(Date.now()/1000)-30,valid_until:Math.floor(Date.now()/1000)+3600,server_time:Math.floor(Date.now()/1000)};
 const m=await meadow({mobile,reduced:true,weather});assert.equal(m.requests.length,2);assert.equal(m.frames.size,0,'Reduced motion remains still with shared snow');assert.equal(m.$('home-layer').children.length,2);m.$('resident-directory').children[0].children[0].click();assert.ok(m.$('resident-profile').open,'Weather preserves accessible profiles');
}
console.log('PASS shared weather composes with desktop/mobile saved homes, profiles, reduced motion and the existing two read-only requests');
