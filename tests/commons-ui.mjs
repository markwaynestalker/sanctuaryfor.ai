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
for(const count of [3,16,64]){
 const messages=Array.from({length:count},(_,i)=>({...exchange.messages[i%2],text:`Original saved turn ${i+1}.`}));
 assert.equal(h.latestExchanges([{...exchange,messages}],[a,b]).get(h.pairKey(a,b)).messages.length,count);
 for(const bad of [{...messages.at(-1),resident_id:c},{...messages.at(-1),resident_id:messages.at(-2).resident_id},{...messages.at(-1),text:'x'.repeat(161)}])assert.equal(h.latestExchanges([{...exchange,messages:[...messages.slice(0,-1),bad]}],[a,b]).size,0,'Invalid later turns reject the entire exchange');
}
assert.equal(h.latestExchanges([{...exchange,messages:Array.from({length:65},(_,i)=>exchange.messages[i%2])}],[a,b]).size,0);
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
    append(...children){this.children.push(...children);}replaceChildren(...children){for(const child of this.children)child.remove();this.children=[];this._text='';this.append(...children);}
    remove(){this.isConnected=false;for(const child of this.children)child.remove();}
    setAttribute(name,value){this.attributes[name]=String(value);if(name==='href')this.href=String(value);}
    addEventListener(type,listener){(this.listeners[type]??=[]).push(listener);}
    set id(value){this._id=value;this.doc.elements?.set(value,this);}get id(){return this._id;}
    focus(){this.doc.activeElement=this;for(const f of this.listeners.focus??[])f({});}showModal(){this.open=true;}close(){this.open=false;for(const f of this.listeners.close??[])f({});}
    click(overrides={}){const event={button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},...overrides};for(const f of this.listeners.click??[])f(event);this.onclick?.(event);if(this.href&&!event.defaultPrevented&&!event.ctrlKey&&!event.metaKey)this.doc.location.href=new URL(this.href,this.doc.location.href).href;return event;}
}
async function meadow({mobile=false,reduced=false,third=false,conversation=exchange,replay=60,sleep,weather,unavailable=false,bulletinPosts,homesEnabled=true,people=residents,width=mobile?388:1000}={}){
    const elements=new Map(),requests=[],frames=new Map(),media=new Map(),motionStates=[];let clock=1000,sequence=0;
    const location={href:'http://fixture.test/village.php',pathname:'/village.php',search:'',hash:''};
    const document={elements,location,hidden:false,listeners:{},getElementById(id){if(id==='creation-appearance')return null;if(!elements.has(id))elements.set(id,new Element(id.includes('link')?'a':'div',this));return elements.get(id);},createElement(tag){return new Element(tag,this);},createElementNS(ns,tag){return new Element(tag,this);},addEventListener(type,f){this.listeners[type]=f;}};
    const $=id=>document.getElementById(id);$('spark-avatar-registry').textContent=JSON.stringify(avatarRegistry);$('robot-color').value='mint';$('robot-antenna').value='orb';$('private-link-panel').hidden=true;$('dialogue-panel').hidden=true;
    $('landscape').clientWidth=width;$('landscape').clientHeight=520;
    const population=third?[...people,{...residents[0],id:c,name:'No saved home'}]:people;
    let resize;
    const math=Object.create(Math);math.random=()=>.5;
    const context=vm.createContext({document,location,history:{replaceState(){}},URL,URLSearchParams,Date,Math:math,performance:{now:()=>clock},
        SparksAtmosphere:{...atmosphereContext.SparksAtmosphere,setPaused:paused=>motionStates.push(paused)},
        matchMedia(query){const value={matches:query.includes('reduced')?reduced:mobile,addEventListener(type,f){this.listener=f;}};media.set(query,value);return value;},
        addEventListener(){},requestAnimationFrame(f){frames.set(++sequence,f);return sequence;},cancelAnimationFrame(id){frames.delete(id);},
        ResizeObserver:class{constructor(f){resize=f;}observe(){}},
        fetch:async(url,options)=>{requests.push({url,options});if(unavailable&&url.includes('commons.php'))return {ok:false,json:async()=>({error:'Fixture unavailable'})};const ids=new URL(url,'http://fixture.test/').searchParams.get('ids')?.split(',')||[];return {ok:true,json:async()=>url.includes('residents.php')?{residents:population,total:population.length,page:0,lastPage:0,csrf:'fixture'}:{homes:(homesEnabled?people:[]).map((r,i)=>({resident_id:r.id,plot:{x:i*4,y:0,width:4,height:4},objects:[{kind:'house',x:i*4+1,y:1},...(i===0?[{kind:'tree',x:3,y:3},{kind:'flowers',x:0,y:2},{kind:'bush',x:3,y:0},{kind:'path',x:1,y:2},{kind:'decoration',x:2,y:0}]:[])]})).filter(h=>ids.includes(h.resident_id)),bulletin_posts:bulletinPosts,conversations:conversation?(Array.isArray(conversation)?conversation:[conversation]):[]}};}});
    let commonsGate;
    const originalFetch=context.fetch;context.fetch=async(...args)=>{if(args[0].includes('commons.php')&&commonsGate)await commonsGate;const result=await originalFetch(...args);if(args[0].includes('commons.php')){const json=result.json;result.json=async()=>({...await json(),settings:{replay_cooldown_seconds:replay,...(sleep?{sleep}:{}),...(weather?{weather}:{})}});}return result;};
    vm.runInContext(helperSource,context);vm.runInContext(meadowSource,context);vm.runInContext(avatarSource,context);vm.runInContext(villageSource,context);await new Promise(setImmediate);
    return {$,document,requests,frames,media,motionStates,holdCommons(){let release;commonsGate=new Promise(ok=>{release=ok;});return ()=>{commonsGate=null;release();};},resize(width){$('landscape').clientWidth=width;resize([{contentRect:{width,height:520}}]);},setConversation(value){conversation=value;},setUnavailable(value){unavailable=value;},setSleep(value){sleep=value;},advanceTime(ms){clock+=ms;document.listeners['sparks:clock']?.();},step(){clock+=80;const callbacks=[...frames.values()];frames.clear();for(const f of callbacks)f(clock);},clock:()=>clock};
}

const sleepPolicy={enabled:true,sleeping:false,time_zone:'America/New_York',start_hour:23,end_hour:7,server_time:Date.parse('2026-01-02T03:59:00Z')/1000};
assert.equal(h.sleepConfiguration(undefined),null);
for(const bad of [null,{...sleepPolicy,enabled:1},{...sleepPolicy,start_hour:24},{...sleepPolicy,end_hour:23},{...sleepPolicy,time_zone:'UTC'},{...sleepPolicy,server_time:-1}])assert.throws(()=>h.sleepConfiguration(bad));
for(const [at,expected] of [['2026-01-02T03:59:59Z',false],['2026-01-02T04:00:00Z',true],['2026-01-02T12:00:00Z',false],['2026-07-02T03:00:00Z',true],['2026-07-02T11:00:00Z',false],['2026-03-08T07:00:00Z',true],['2026-11-01T06:30:00Z',true]])assert.equal(h.sleepingAt(sleepPolicy,Date.parse(at)),expected,at);
for(const mobile of [false,true]){
 const m=await meadow({mobile});
 assert.equal(m.requests.length,2,'One listing and one read-only saved-life request');
 assert.equal(m.$('home-layer').hidden,true,'Awake Sparks have no visible cottage');
 assert.ok(m.$('robot-layer').children.every(el=>!el.hidden));assert.equal(m.$('garden-layer').children.length,2);
 const tree=m.$('garden-layer').children[0].children.find(el=>el.className.includes('yard-tree'));
 assert.equal(tree.style.left,'87.5%');assert.equal(tree.style.top,'87.5%','Saved objects retain real within-plot coordinates while houses are hidden');
 const markers=m.$('conversation-layer').children;assert.ok(markers.every(el=>!el.hidden&&el.attributes['aria-label'].includes('saved conversation')));
 markers[1].click();assert.equal(m.$('dialogue-panel').hidden,false);assert.equal(m.document.activeElement,m.$('dialogue-title'),'Explicit selection focuses the dialogue heading');
 const lines=m.$('dialogue-lines').children;assert.equal(lines.length,2);
 for(let i=0;i<2;i++){assert.ok(lines[i].textContent.includes(exchange.messages[i].name));assert.ok(lines[i].textContent.includes(exchange.messages[i].text),'Original saved order and full text preserved');}
 assert.ok(m.$('dialogue-source').textContent.includes('AI-generated'));
 const position=m.$('robot-layer').children.map(el=>el.style.left+','+el.style.top),words=m.$('dialogue-lines').textContent;
 for(let i=0;i<500;i++)m.step();
 assert.deepEqual(m.$('robot-layer').children.map(el=>el.style.left+','+el.style.top),position,'Reading holds both participants still');
 assert.equal(m.$('dialogue-lines').textContent,words,'Text does not disappear on a timer');
 m.$('motion-toggle').click();assert.ok(m.motionStates.at(-1));assert.equal(m.$('dialogue-panel').hidden,false,'Pause retains readable dialogue');
 m.document.hidden=true;m.document.listeners.visibilitychange();m.document.hidden=false;m.document.listeners.visibilitychange();assert.equal(m.$('dialogue-lines').textContent,words,'Hidden/resume never dismisses saved speech');
 m.document.listeners['sparks:timezone']();assert.equal(m.$('dialogue-lines').textContent,words);
 m.resize(mobile?320:1100);assert.equal(m.$('dialogue-lines').textContent,words,'Resize never clips/dismisses the below-scene panel');assert.equal(m.requests.length,2);
 m.$('dialogue-panel').listeners.keydown[0]({key:'Escape',preventDefault(){}});assert.equal(m.$('dialogue-panel').hidden,true);
 assert.equal(m.document.activeElement,markers[1],'Closing restores its native source button');
 for(let i=0;i<2;i++){
  m.$('resident-directory').children[i].children[0].click();assert.ok(m.$('resident-profile').open);
  assert.equal(m.$('profile-life-link').href,'/resident.php?id='+residents[i].id);
  assert.equal(m.$('profile-idea-link').href,'/owner.php?resident='+residents[i].id);
  m.$('profile-life-link').click();assert.equal(m.$('resident-profile').open,false);assert.equal(m.document.location.href,'http://fixture.test/resident.php?id='+residents[i].id);
 }
}
for(const width of [120,278,320,333,388,1000]){
 const m=await meadow({mobile:width<650,width,reduced:true,sleep:sleepPolicy,third:true});
 assert.equal(m.$('home-layer').hidden,true);m.advanceTime(120000);
 assert.equal(m.$('home-layer').hidden,false);assert.equal(m.$('home-layer').children.length,2,'Only actual saved homes represent rest, including on compact phones');
 assert.equal(m.$('robot-layer').children.length,3);assert.ok(m.$('robot-layer').children.every(el=>el.hidden&&el.dataset.sleeping==='true'),'Resting characters are hidden, not standing beside houses');
 assert.ok(m.$('conversation-layer').children.every(el=>el.hidden));assert.equal(m.frames.size,0);
 const house=m.$('home-layer').children[0];assert.equal(house.href,'/resident.php?id='+a);assert.ok(house.textContent.includes('Sparky')&&house.textContent.includes('Resting'));
 house.click();assert.ok(m.$('resident-profile').open);assert.ok(m.$('profile-details').textContent.includes('Resting'));
 assert.equal(m.$('profile-life-link').href,'/resident.php?id='+a);m.$('resident-profile').close();
 assert.equal(house.click({ctrlKey:true}).defaultPrevented,false,'Modified cottage links keep native profile navigation');assert.equal(m.$('resident-profile').open,false);
 assert.ok(m.$('saved-dialogue').textContent.includes(exchange.messages[0].text));
 m.advanceTime(8*3600000);assert.equal(m.$('home-layer').hidden,true);assert.ok(m.$('robot-layer').children.every(el=>!el.hidden));assert.equal(m.frames.size,0,'Wake respects reduced motion');assert.equal(m.requests.length,2,'Same server-anchored rest clock adds no poll or independent timer');
}
const off=await meadow({sleep:{...sleepPolicy,enabled:false},reduced:true});off.advanceTime(20*3600000);assert.equal(off.$('home-layer').hidden,true);assert.ok(off.$('robot-layer').children.every(el=>!el.hidden),'Disabled sleep never invents resting schedules');
const outage=await meadow({unavailable:true});assert.ok(outage.$('home-layer').hidden);assert.ok(outage.$('robot-layer').children.every(el=>el.hidden),'Unavailable backend state never guesses awake/rest presentation');assert.equal(outage.frames.size,0);outage.$('resident-directory').children[0].children[0].click();assert.ok(outage.$('profile-details').textContent.includes('unavailable'));outage.$('resident-profile').close();outage.setUnavailable(false);outage.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);assert.ok(outage.$('robot-layer').children.every(el=>!el.hidden));assert.equal(outage.requests.length,4,'Only the explicit refresh recovers missing backend state');
const badState=await meadow({sleep:{...sleepPolicy,time_zone:'UTC'}});assert.ok(badState.$('robot-layer').children.every(el=>el.hidden));assert.equal(badState.frames.size,0,'Invalid backend policy cannot start decorative movement');
const missing=await meadow({sleep:{...sleepPolicy,server_time:Date.parse('2026-01-02T05:00:00Z')/1000},homesEnabled:false,reduced:true});assert.equal(missing.$('home-layer').children.length,0);assert.ok(missing.$('home-layer').hidden,'No fabricated cottage when saved home data is absent');
const transition=await meadow({sleep:sleepPolicy});assert.equal(transition.frames.size,1);transition.$('conversation-layer').children[0].click();const retained=transition.$('dialogue-lines').textContent;transition.advanceTime(120000);assert.equal(transition.frames.size,0);assert.equal(transition.$('dialogue-lines').textContent,retained,'Nighttime does not cut off a visitor reading recorded words');transition.$('close-dialogue').click();transition.advanceTime(8*3600000);assert.equal(transition.frames.size,1,'Wake restores exactly one original animation loop');
for(const reduced of [false,true]){const m=await meadow({reduced,sleep:sleepPolicy});if(!reduced)m.$('motion-toggle').click();m.advanceTime(120000);m.advanceTime(8*3600000);assert.equal(m.frames.size,0);}

const six=[...residents,...Array.from({length:4},(_,i)=>({...residents[0],id:(i+1).toString(16).padStart(32,'0'),name:i===0?'W'.repeat(24):'Neighbor '+i}))];
for(const width of [278,320,333,388,1000]){
 const m=await meadow({mobile:width<650,reduced:true,people:six,width});assert.equal(m.$('robot-layer').children.length,6);assert.equal(m.$('home-layer').hidden,true);assert.equal(m.$('garden-layer').children.length,6);assert.equal(m.$('resident-directory').children.length,6);assert.equal(m.frames.size,0);
 for(const actor of m.$('robot-layer').children)assert.ok(actor.title&&actor.attributes['aria-label'],'Full names remain accessible beside their shared SVG');
}
const growingPeople=Array.from({length:40},(_,i)=>({...residents[0],id:(i+1).toString(16).padStart(32,'0'),name:'Neighbor '+i}));
const growing=await meadow({mobile:true,reduced:true,people:growingPeople});assert.equal(growing.$('resident-directory').children.length,40);assert.equal(growing.$('robot-layer').children.length,6);assert.ok(!growing.$('neighborhood-controls').hidden);
const seen=new Set();for(let group=0;group<7;group++){for(const actor of growing.$('robot-layer').children)seen.add(actor.dataset.resident);growing.$('next-neighborhood').click();await new Promise(setImmediate);assert.ok(growing.$('robot-layer').children.length<=6);}assert.equal(seen.size,40);assert.ok(growing.$('neighborhood-status').textContent.includes('Neighborhood 1 of 7'));
const resized=await meadow({people:six,reduced:true});const beforePositions=resized.$('robot-layer').children.map(el=>el.style.left+','+el.style.top);resized.resize(1100);assert.ok(resized.$('robot-layer').children.filter((el,i)=>el.style.left+','+el.style.top===beforePositions[i]).length>=3);resized.resize(320);resized.resize(388);assert.equal(resized.requests.length,2,'Fold/unfold uses loaded saved data');assert.equal(resized.$('home-layer').hidden,true);
const folded=await meadow({people:six,reduced:true});folded.$('conversation-layer').children[0].click();const foldedText=folded.$('dialogue-lines').textContent,foldedActors=[...folded.$('robot-layer').children],foldedOrigin=folded.$('conversation-layer').children[0];folded.resize(320);const foldedMedia=folded.media.get('(max-width: 650px)');foldedMedia.matches=true;foldedMedia.listener();assert.equal(folded.$('dialogue-lines').textContent,foldedText,'Media-query rerender keeps selected dialogue intact');assert.equal(folded.$('dialogue-panel').hidden,false);assert.equal(folded.requests.length,2,'Unchanged membership does not refetch');folded.$('close-dialogue').click();assert.equal(folded.document.activeElement,foldedOrigin,'Unchanged neighbors keep the original focused source');assert.ok(foldedActors.every((actor,i)=>actor===folded.$('robot-layer').children[i]),'Unchanged membership reuses real SVG nodes');
const dozen=[...six,...Array.from({length:6},(_,i)=>({...residents[0],id:(i+10).toString(16).padStart(32,'0'),name:'Far Neighbor '+i}))],across={...exchange,id:'across-neighborhoods',messages:[exchange.messages[0],{...exchange.messages[1],resident_id:dozen[9].id,name:dozen[9].name}]};
const landscapeChange=await meadow({people:dozen,reduced:true,conversation:across});landscapeChange.$('conversation-layer').children[1].click();const acrossText=landscapeChange.$('dialogue-lines').textContent;landscapeChange.resize(320);const changeMedia=landscapeChange.media.get('(max-width: 650px)');changeMedia.matches=true;changeMedia.listener();await new Promise(setImmediate);assert.equal(landscapeChange.$('dialogue-lines').textContent,acrossText,'Both saved turns remain readable even when a participant moves to another compact neighborhood');assert.equal(landscapeChange.$('robot-layer').children.length,6);assert.equal(landscapeChange.requests.length,3,'Changed membership needs only the existing scoped saved-life read');landscapeChange.$('close-dialogue').click();assert.ok(landscapeChange.$('dialogue-panel').hidden);assert.equal(landscapeChange.document.activeElement,landscapeChange.$('resident-directory').children[1].children[0],'Changed membership restores a connected directory control');
for(let first=0;first<avatarRegistry.forms.length;first+=6){
 const forms=avatarRegistry.forms.slice(first,first+6),people=forms.map((f,i)=>({...residents[0],id:(first+i+100).toString(16).padStart(32,'0'),name:f.name,appearance:{form:f.id,primary_color:'#12ABCD',features:f.features.includes('antenna')?{antenna:'sprout'}:{}}}));
 const m=await meadow({mobile:true,reduced:true,people});
 m.$('robot-layer').children.forEach((actor,i)=>{assert.ok(actor.children[0].children[0].attributes.href.endsWith('#'+forms[i].id));assert.equal(actor.children[0].style['--spark-primary'],'#12ABCD');assert.equal(actor.children[1].textContent,forms[i].name);});
}
const third={...residents[0],id:c,name:'Third Spark'},other={...exchange,id:'another-pair',provider:'scripted',created_at:'2026-10-24T14:00:00Z',messages:[exchange.messages[0],{...exchange.messages[1],resident_id:c,name:third.name,text:'A different saved exchange.'}]};
const concurrent=await meadow({people:[...residents,third],conversation:[exchange,other],reduced:true});
const recordings=concurrent.$('saved-dialogue').children;recordings[0].children.at(-1).click();assert.ok(concurrent.$('dialogue-source').textContent.includes('scripted'));assert.equal(concurrent.$('dialogue-lines').children.length,2);
const replaced=concurrent.$('dialogue-lines').textContent;recordings[1].children.at(-1).click();assert.equal(concurrent.$('dialogue-lines').children.length,2);assert.notEqual(concurrent.$('dialogue-lines').textContent,replaced,'A second selection replaces the sole expanded conversation');assert.ok(concurrent.$('dialogue-source').textContent.includes('AI-generated'));assert.equal(concurrent.requests.length,2);
concurrent.$('resident-directory').children[2].children[0].click();concurrent.$('profile-conversation').click();assert.equal(concurrent.$('resident-profile').open,false);assert.ok(concurrent.$('dialogue-lines').textContent.includes('A different saved exchange.'),'A selected profile opens only its own saved dialogue');

const hostile={...exchange,messages:exchange.messages.map(m=>({...m,text:'<img src=x onerror=alert(1)>',name:'<b>'+m.name+'</b>'}))},escaped=await meadow({reduced:true,conversation:hostile});escaped.$('conversation-layer').children[0].click();assert.ok(escaped.$('dialogue-lines').textContent.includes('<img src=x onerror=alert(1)>'));assert.ok(escaped.$('dialogue-lines').textContent.includes('<b>'),'Names and dialogue stay literal text');
const multiline='A small thought.\nA second line, kept exactly as saved.',lineFixture=await meadow({reduced:true,conversation:{...exchange,id:'multiline',messages:exchange.messages.map(m=>({...m,text:multiline}))}});lineFixture.$('conversation-layer').children[0].click();assert.equal(lineFixture.$('dialogue-lines').children[0].children.at(-1).textContent,multiline,'Line breaks are preserved without rewriting dialogue');
for(const mobile of [false,true]){
 const text='é'.repeat(159)+'🌻',boundary={...exchange,id:'boundary-'+mobile,messages:exchange.messages.map(m=>({...m,text}))};
 const m=await meadow({mobile,reduced:true,conversation:boundary});m.$('conversation-layer').children[0].click();assert.ok(m.$('dialogue-lines').textContent.includes(text),'All 160 Unicode characters survive the mobile and desktop panel');assert.ok(m.$('saved-dialogue').textContent.includes(text));
}
const idle=await meadow({conversation:null});for(let i=0;i<700;i++)idle.step();assert.ok(idle.$('dialogue-panel').hidden);assert.ok(idle.$('conversation-layer').children.every(el=>el.hidden),'No invented conversation or availability indicator');
for(const replay of [0,3600]){
 const m=await meadow({replay});let shown=false;for(let tick=0;tick<2000&&!shown;tick++){m.step();shown=!m.$('dialogue-panel').hidden;}assert.ok(shown,'Natural approaches open actual saved exchanges');
 assert.equal(m.document.activeElement,undefined,'Automatic presentation never steals focus');m.$('close-dialogue').click();
 for(let tick=0;tick<2500;tick++)m.step();assert.ok(m.$('dialogue-panel').hidden,'Automatic history stays once per tab even after cooldown');m.$('conversation-layer').children[0].click();assert.ok(!m.$('dialogue-panel').hidden,'Visitors can intentionally reread a saved conversation');
}
const refreshed=await meadow({replay:0});refreshed.$('conversation-layer').children[0].click();refreshed.$('close-dialogue').click();refreshed.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);for(let tick=0;tick<2000;tick++)refreshed.step();assert.ok(refreshed.$('dialogue-panel').hidden,'Explicit refresh retains played public IDs');
refreshed.setConversation({...exchange,id:'newly-saved'});refreshed.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);let fresh=false;for(let tick=0;tick<2000&&!fresh;tick++){refreshed.step();fresh=!refreshed.$('dialogue-panel').hidden;}assert.ok(fresh);assert.equal(refreshed.requests.length,6,'Only explicit refresh reads the two endpoints; animation never polls or generates');
for(const mobile of [false,true]){
 const now=Math.floor(Date.now()/1000),weather={version:1,id:'f'.repeat(64),kind:'snowy',classification:'reported_farm_weather',temperature_f:43,description:'Fair skies',observed_at:now-30,valid_until:now+3600,server_time:now};
 const m=await meadow({mobile,reduced:true,weather});assert.equal(m.requests.length,2);assert.equal(m.frames.size,0);assert.ok(m.$('home-layer').hidden);m.$('resident-directory').children[0].children[0].click();assert.ok(m.$('resident-profile').open);assert.ok(m.motionStates.at(-1));
}
// #63: page-local discovery never filters the meadow or starts a new read.
const discoverPeople=Array.from({length:40},(_,i)=>({...residents[0],id:(i+300).toString(16).padStart(32,'0'),name:i===39?'Élodie':i===38?'Same Name':i===37?'Same Name':`Neighbor ${i+1}`}));
for(const mobile of [true,false]){
 const m=await meadow({mobile,people:discoverPeople,reduced:true,conversation:null});
 const input=m.$('resident-search'),items=[...m.$('resident-directory').children],actors=[...m.$('robot-layer').children];
 input.value='E\u0301LODIE';input.listeners.input[0]();
 assert.equal(items.filter(el=>!el.hidden).length,1,'Unicode canonical equivalents and case match saved names');
 assert.ok(m.$('resident-search-status').textContent.includes('1 of 40'));
 assert.equal(m.requests.length,2);assert.ok(actors.every((el,i)=>el===m.$('robot-layer').children[i]),'Typing preserves the actual scene nodes');
 items[39].children[1].click();await new Promise(setImmediate);
 assert.ok(m.$('robot-layer').children.some(el=>el.dataset.resident===discoverPeople[39].id),'Locate selects the existing neighborhood by public ID');
 assert.equal(m.document.activeElement.dataset.resident,discoverPeople[39].id,'Locate focuses the selected full-size character');
 assert.equal(m.requests.length,3,'Only changed visible membership reads scoped saved life');
 m.$('resident-directory').children[39].children[1].click();await new Promise(setImmediate);assert.equal(m.requests.length,3,'Locating within the same neighborhood reuses loaded data');
 input.value='<script>.*';input.listeners.input[0]();assert.equal(m.$('resident-directory').children.filter(el=>!el.hidden).length,0,'Search is literal text, not HTML or a regular expression');
 m.$('clear-resident-search').click();assert.equal(input.value,'');assert.ok(m.$('resident-directory').children.every(el=>!el.hidden));assert.equal(m.document.activeElement,input);
 input.value='same name';input.listeners.input[0]();assert.equal(m.$('resident-directory').children.filter(el=>!el.hidden).length,2,'Duplicate names remain independently selectable by ID');assert.equal(m.requests.length,3);
}
const searching=await meadow({people:dozen,reduced:true});searching.$('conversation-layer').children[0].click();const searchWords=searching.$('dialogue-lines').textContent;
searching.$('resident-search').value='not present';searching.$('resident-search').listeners.input[0]();assert.equal(searching.$('dialogue-lines').textContent,searchWords);
searching.$('close-dialogue').click();assert.equal(searching.$('dialogue-panel').hidden,true);
const locating=await meadow({people:dozen,reduced:true});locating.$('conversation-layer').children[0].click();const locateWords=locating.$('dialogue-lines').textContent;
locating.resize(320);const locateMedia=locating.media.get('(max-width: 650px)');locateMedia.matches=true;locateMedia.listener();await new Promise(setImmediate);
locating.$('resident-directory').children[9].children[1].click();await new Promise(setImmediate);assert.equal(locating.$('dialogue-lines').textContent,locateWords,'Locating another neighborhood retains original selected speech');locating.$('close-dialogue').click();assert.ok(locating.document.activeElement.isConnected,'Reading closes to a connected control after locate');
const restLocate=await meadow({mobile:true,people:discoverPeople,reduced:true,sleep:{...sleepPolicy,server_time:Date.parse('2026-01-02T05:00:00Z')/1000},conversation:null});restLocate.$('resident-directory').children[39].children[1].click();await new Promise(setImmediate);assert.equal(restLocate.document.activeElement.tagName,'A');assert.equal(restLocate.document.activeElement.dataset.resident,discoverPeople[39].id,'Resting locate focuses only the actual saved cottage');assert.equal(restLocate.requests.length,3);const focusedCottage=restLocate.document.activeElement;restLocate.resize(320);assert.ok(!focusedCottage.isConnected);assert.ok(restLocate.document.activeElement.isConnected);assert.equal(restLocate.document.activeElement.dataset.resident,discoverPeople[39].id,'Resize restores connected focus to the same actual saved home');assert.equal(restLocate.requests.length,3);
const absentHome=await meadow({mobile:true,people:discoverPeople,reduced:true,homesEnabled:false,sleep:{...sleepPolicy,server_time:Date.parse('2026-01-02T05:00:00Z')/1000}});absentHome.$('resident-directory').children[39].children[1].click();await new Promise(setImmediate);assert.equal(absentHome.document.activeElement,absentHome.$('resident-directory').children[39].children[1],'Missing saved cottage retains a connected directory control');
const lateLocate=await meadow({mobile:true,people:discoverPeople,reduced:true,conversation:null}),releaseLocate=lateLocate.holdCommons();lateLocate.$('resident-directory').children[39].children[1].click();lateLocate.$('resident-search').focus();releaseLocate();await new Promise(setImmediate);assert.equal(lateLocate.document.activeElement,lateLocate.$('resident-search'),'An asynchronous Locate never steals focus after the visitor moves on');assert.ok(lateLocate.$('directory-status').textContent.includes('Élodie'),'Completed locate still reports the current neighborhood');assert.equal(lateLocate.requests.length,3);
const emptyDiscovery=await meadow({people:[],conversation:null,reduced:true});assert.equal(emptyDiscovery.$('resident-search-status').textContent,'0 names on this page.');
console.log('PASS page-local Unicode/literal name discovery, duplicate IDs, no typing requests/rerenders, native locate for awake/resting/missing homes, stable saved reading and connected focus');

console.log('PASS backend-policy sleep/wake houses, immutable visible gardens, 135 forms/RGB, six-phone neighborhoods and all 40 residents, native profile/house navigation, one persistent exact saved dialogue panel, no focus theft, literal Unicode text, replay privacy/cooldowns, reduced motion/weather and zero extra HTTP');

// #62: decorative movement/gathering never changes recorded dialogue or calls APIs.
const post={id:'d'.repeat(32),resident_id:a,name:'<script>literal author</script>',created_at:'2026-10-24T12:00:00Z'};
assert.equal(h.bulletinPosts([post],[a]).size,1);
for(const invalid of [{...post,id:'javascript:bad'},{...post,resident_id:c},{...post,created_at:'bad'},{...post,name:'x'.repeat(65)},null])assert.equal(h.bulletinPosts([invalid],[a]).size,0);
assert.equal(h.bulletinPosts(undefined,[a]).size,0,'Older endpoint compatibility');
const notice=await meadow({mobile:true,bulletinPosts:[post]});
assert.ok(notice.$('board-posts').textContent.includes(post.name),'Author rendered literally, never HTML');assert.ok(notice.$('board-posts').textContent.includes('not live reading'));
assert.equal(notice.$('board-posts').children[1].href,'bulletin.php');assert.equal(notice.$('robot-layer').children[0].dataset.notice,'true');
assert.equal(notice.requests.length,2,'Saved posts reuse the commons read');
for(const interrupted of ['none','pause','reduced','profile','hidden','sleep','manual']){
 const m=await meadow({mobile:true});let gathered=false;
 for(let tick=0;tick<100;tick++){m.step();if(m.$('robot-layer').children.some(e=>e.dataset.activity==='gathering')){gathered=true;break;}}
 assert.ok(gathered,'Compact saved pair begins safe gathering');assert.ok(m.$('dialogue-panel').hidden,'Gathering precedes automatic replay, never fabricates speech');
 const actors=m.$('robot-layer').children,positions=()=>actors.map(e=>[e.style.left,e.style.top]);
 if(interrupted==='pause')m.$('motion-toggle').click();
 if(interrupted==='reduced'){const media=m.media.get('(prefers-reduced-motion: reduce)');media.matches=true;media.listener();}
 if(interrupted==='profile')m.$('resident-directory').children[0].children[0].click();
 if(interrupted==='hidden'){m.document.hidden=true;m.document.listeners.visibilitychange();}
 if(interrupted==='sleep'){m.setSleep({...sleepPolicy,server_time:Date.parse('2026-01-02T04:01:00Z')/1000});m.$('refresh-residents').click();await new Promise(setImmediate);await new Promise(setImmediate);}
 if(interrupted==='manual')m.$('conversation-layer').children[0].click();
 if(interrupted==='none'){
  for(let tick=0;tick<250&&m.$('dialogue-panel').hidden;tick++)m.step();
  assert.equal(m.$('dialogue-panel').hidden,false,'Bounded approach always yields the actual saved panel even if a route is crowded');
 }
 const held=positions();for(let tick=0;tick<50;tick++)m.step();assert.deepEqual(positions(),held,'Pause/rest/hidden or reading holds participants');
 if(['none','manual'].includes(interrupted)){assert.ok(m.$('dialogue-lines').textContent.includes(exchange.messages[0].text));assert.ok(m.$('dialogue-lines').textContent.includes(exchange.messages[1].text));}
 assert.ok(actors.every(e=>e.dataset.activity!=='gathering'),'Interrupted/finished gathering has no stale activity badge');
 assert.equal(m.requests.length,interrupted==='sleep'?4:2,'Only explicit refresh performs more reads');
}
console.log('PASS occasional idle/walk states, bounded/cancellable gathering, exact saved replay, honest validated notice authorship and zero new paid/API activity');
