// Offline DOM/clock harness: no browser credentials, network, dependencies or AI calls.
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const helperSource=await readFile(new URL('../public/assets/commons.js',import.meta.url),'utf8');
const villageSource=await readFile(new URL('../public/assets/village.js',import.meta.url),'utf8');
const meadowSource=await readFile(new URL('../public/assets/meadow.js',import.meta.url),'utf8');
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
    constructor(tag='div',doc){this.tagName=tag.toUpperCase();this.doc=doc;this.children=[];this.listeners={};this.attributes={};this.style={};this.hidden=false;this.open=false;this.isConnected=true;this._text='';this.className='';this.classList={add:name=>{this.className+=' '+name;}};}
    set textContent(value){this._text=String(value);this.children=[];}get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
    set innerHTML(value){throw new Error('Untrusted HTML must never be rendered: '+value);}
    append(...children){this.children.push(...children);}replaceChildren(...children){this.children=[];this._text='';this.append(...children);}
    setAttribute(name,value){this.attributes[name]=String(value);if(name==='href')this.href=String(value);}
    addEventListener(type,listener){(this.listeners[type]??=[]).push(listener);}
    focus(){this.doc.activeElement=this;}showModal(){this.open=true;}close(){this.open=false;for(const f of this.listeners.close??[])f({});}
    click(){const event={defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};for(const f of this.listeners.click??[])f(event);if(this.href&&!event.defaultPrevented)this.doc.location.href=new URL(this.href,this.doc.location.href).href;}
}
async function meadow({mobile=false,reduced=false,third=false,conversation=exchange,replay=60}={}){
    const elements=new Map(),requests=[],frames=new Map(),media=new Map();let clock=1000,sequence=0;
    const location={href:'http://fixture.test/village.php',pathname:'/village.php',search:'',hash:''};
    const document={location,hidden:false,listeners:{},getElementById(id){if(!elements.has(id))elements.set(id,new Element(id.includes('link')?'a':'div',this));return elements.get(id);},createElement(tag){return new Element(tag,this);},addEventListener(type,f){this.listeners[type]=f;}};
    const $=id=>document.getElementById(id);$('robot-color').value='mint';$('robot-antenna').value='orb';$('private-link-panel').hidden=true;
    const population=third?[...residents,{...residents[0],id:c,name:'No saved home'}]:residents;
    const math=Object.create(Math);math.random=()=>.5;
    const context=vm.createContext({document,location,history:{replaceState(){}},URL,URLSearchParams,Date,Math:math,performance:{now:()=>clock},
        matchMedia(query){const value={matches:query.includes('reduced')?reduced:mobile,addEventListener(type,f){this.listener=f;}};media.set(query,value);return value;},
        addEventListener(){},requestAnimationFrame(f){frames.set(++sequence,f);return sequence;},cancelAnimationFrame(id){frames.delete(id);},
        fetch:async(url,options)=>{requests.push({url,options});return {ok:true,json:async()=>url.includes('residents.php')?{residents:population,total:population.length,page:0,lastPage:0,csrf:'fixture'}:{homes:residents.map((r,i)=>({resident_id:r.id,plot:{x:i*4,y:0,width:4,height:4},objects:[{kind:'house',x:i*4+1,y:1},...(i===0?[{kind:'tree',x:3,y:3},{kind:'flowers',x:0,y:2},{kind:'bush',x:3,y:0},{kind:'path',x:1,y:2},{kind:'decoration',x:2,y:0}]:[])]})),conversations:conversation?[conversation]:[]}};}});
    const originalFetch=context.fetch;context.fetch=async(...args)=>{const result=await originalFetch(...args);if(args[0].includes('commons.php')){const json=result.json;result.json=async()=>({...await json(),settings:{replay_cooldown_seconds:replay}});}return result;};
    vm.runInContext(helperSource,context);vm.runInContext(meadowSource,context);vm.runInContext(villageSource,context);await new Promise(setImmediate);
    return {$,document,requests,frames,media,setConversation(value){conversation=value;},step(){clock+=80;const callbacks=[...frames.values()];frames.clear();for(const f of callbacks)f(clock);},clock:()=>clock};
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
    m.$('motion-toggle').click();assert.ok(actors.every(el=>!el.children.at(-1).textContent),'pause clears bubbles');assert.ok(m.$('saved-dialogue').textContent.includes(exchange.messages[0].text),'stationary transcript remains available');
    for(let i=0;i<2;i++){
        m.$('resident-directory').children[i].children[0].click();assert.equal(m.$('resident-profile').open,true);assert.equal(m.$('profile-life-link').href,'/resident.php?id='+residents[i].id);
        m.$('profile-life-link').click();assert.equal(m.$('resident-profile').open,false);assert.equal(m.document.location.href,'http://fixture.test/resident.php?id='+residents[i].id,'native modal link navigates to selected resident');
    }
}
const stationary=await meadow({reduced:true,third:true});assert.equal(stationary.frames.size,0);assert.equal(stationary.$('home-layer').children.length,2,'no invented house for resident without saved home');assert.ok(stationary.$('saved-dialogue').textContent.includes(exchange.messages[0].text));
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
console.log('PASS desktop/mobile modal navigation, real cottages, latest pair selection, sequential saved speech, literal text, idle fallback, reduced motion and zero background polling');
