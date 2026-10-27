// Frozen clocks and minimal DOM; no network, provider key or database.
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/atmosphere.js',import.meta.url),'utf8');
const pure=vm.createContext({});vm.runInContext(source,pure);const a=pure.SparksAtmosphere;
assert.equal(a.villageZone,'America/New_York');
for(const zone of ['UTC','America/New_York','Europe/London','Asia/Tokyo','Australia/Sydney'])assert.ok(a.validZone(zone));
for(const zone of ['',null,{},'javascript:alert(1)','Bad/Zone','x'.repeat(10000)])assert.equal(a.safeZone(zone),'UTC');
for(const weather of ['clear','rain','fog','snow'])assert.equal(a.safeWeather(weather),weather);
for(const weather of ['',null,{},'thunderstorm','<img>'])assert.equal(a.safeWeather(weather),'clear');
const denied={getItem(){throw Error('denied');},setItem(){throw Error('denied');}};
assert.equal(a.preferences(denied).zone,'UTC');assert.equal(a.preferences(denied).weather,'clear');
assert.equal(a.dayKey('2026-10-26T00:30:00Z','America/New_York'),'2026-10-25');
assert.equal(a.dayKey('2026-10-26T00:30:00Z','Asia/Tokyo'),'2026-10-26');
assert.equal(a.format('invalid'),'');assert.equal(a.dayKey('invalid'),'');
assert.equal(a.atmosphereAt(Date.parse('2026-03-08T06:59:00Z')).minutes,119);
assert.equal(a.atmosphereAt(Date.parse('2026-03-08T07:00:00Z')).minutes,180,'Spring DST skips the missing hour');
for(const instant of ['2026-11-01T05:30:00Z','2026-11-01T06:30:00Z'])assert.equal(a.atmosphereAt(Date.parse(instant)).minutes,90,'Both repeated fall hours remain valid');
assert.notEqual(a.format('2026-11-01T05:30:00Z','America/New_York'),a.format('2026-11-01T06:30:00Z','America/New_York'),'Repeated local times identify EDT versus EST');
for(const [instant,phase] of [['2026-10-26T10:30:00Z','dawn'],['2026-10-26T16:00:00Z','day'],['2026-10-26T22:00:00Z','dusk'],['2026-10-26T02:00:00Z','night']]){
    const state=a.atmosphereAt(Date.parse(instant));assert.equal(state.phase,phase);
    assert.ok(state.stars>=0&&state.stars<=1&&state.sunOpacity>=0&&state.sunOpacity<=1&&state.sunX>=10&&state.sunX<=85&&state.sunY>=18&&state.sunY<=70);
}
const noIntl=vm.createContext({Intl:undefined});vm.runInContext(source,noIntl);assert.ok(noIntl.SparksAtmosphere.format('2026-10-26T00:30:00Z').endsWith('UTC'),'Unsupported Intl degrades to UTC');

let now=Date.parse('2026-10-26T00:30:00Z'),sequence=0;
class Clock extends Date{static now(){return now;}}
class Element{
    constructor(){this.listeners={};this.dataset={};this.hidden=true;this.options=[];this.textContent='';this.properties={};this.style={setProperty:(k,v)=>this.properties[k]=v};}
    addEventListener(type,f){(this.listeners[type]??=[]).push(f);}fire(type){for(const f of this.listeners[type]??[])f();}
    append(option){this.options.push(option);}closest(){return this.container;}getAttribute(key){return this.attributes[key];}
}
const ids=Object.fromEntries(['display-timezone','landscape','scene-weather','scene-weather-label','weather-note','atmosphere-controls','village-clock'].map(id=>[id,new Element()]));
ids['display-timezone'].container=new Element();ids['display-timezone'].options=['UTC','America/New_York'].map(value=>({value}));
const time=new Element();time.attributes={datetime:'2026-10-25T23:30:00Z'};
const timers=new Map(),storage=new Map(),writes=[];
const doc={hidden:false,listeners:{},getElementById:id=>ids[id]??null,createElement:()=>new Element(),querySelectorAll:()=>[time],addEventListener(type,f){(this.listeners[type]??=[]).push(f);},dispatchEvent(e){for(const f of this.listeners[e.type]??[])f(e);}};
const context=vm.createContext({document:doc,Date:Clock,Event:class{constructor(type){this.type=type;}},localStorage:{getItem:k=>storage.get(k),setItem(k,v){storage.set(k,v);writes.push(k);}},setTimeout(f,ms){assert.ok(ms>0&&ms<=60000);timers.set(++sequence,f);return sequence;},clearTimeout:id=>timers.delete(id),fetch(){throw Error('No requests permitted');}});
vm.runInContext(source,context);const runtime=context.SparksAtmosphere;
assert.equal(timers.size,1,'Exactly one coarse display clock timer');assert.equal(ids.landscape.dataset.weather,'clear');assert.equal(ids['atmosphere-controls'].hidden,false);
assert.equal(time.attributes.datetime,'2026-10-25T23:30:00Z','Machine-readable UTC timestamp is immutable');
const phase=ids.landscape.dataset.phase,conversation={created_at:time.attributes.datetime,provider:'openai'};
assert.ok(runtime.savedLabel(conversation,now).startsWith('From the village journal'));
ids['display-timezone'].value='America/New_York';ids['display-timezone'].fire('change');
assert.ok(runtime.savedLabel(conversation,now).startsWith('Saved today'),'Today is evaluated in the same zone as visible timestamps');
assert.equal(ids.landscape.dataset.phase,phase,'Visitor timezone never changes shared village atmosphere');assert.ok(time.textContent.includes('America/New_York'));
for(const value of ['rain','snow','clear','<script>']){ids['scene-weather'].value=value;ids['scene-weather'].fire('change');assert.equal(ids.landscape.dataset.weather,runtime.safeWeather(value));assert.equal(timers.size,1,'Weather does not start an animation scheduler');}
runtime.setPaused(true);assert.equal(ids.landscape.dataset.motion,'paused');runtime.setPaused(false);assert.equal(ids.landscape.dataset.motion,'running');
doc.hidden=true;doc.dispatchEvent({type:'visibilitychange'});assert.equal(timers.size,0,'Hidden tabs stop the clock timer');assert.equal(ids.landscape.dataset.motion,'paused');
now=Date.parse('2026-10-26T17:00:00Z');doc.hidden=false;doc.dispatchEvent({type:'visibilitychange'});assert.equal(timers.size,1);assert.equal(ids.landscape.dataset.phase,'day','Resume catches up without replaying clock ticks');
assert.ok(writes.every(k=>['sparks_display_timezone_v1','sparks_scene_weather_v1'].includes(k)),'Only cosmetic preferences enter storage');
const css=await readFile(new URL('../public/assets/atmosphere.css',import.meta.url),'utf8');
assert.ok(css.includes('prefers-reduced-motion:reduce')&&css.includes('animation:none !important'));
assert.ok(css.includes('forced-colors:active')&&css.includes('pointer-events:none')&&css.includes('animation-play-state:paused'));
assert.ok(!/requestAnimationFrame|setInterval|fetch\(/.test(source),'No extra render loop, polling or HTTP');
console.log('PASS shared New York atmosphere, visitor timestamps/today, UTC and both DST boundaries, invalid/denied preferences, no-Intl fallback, all weather presets, one visible clock timer, hidden/resume, pause, static reduced motion and zero network');

const shared={version:1,id:'a'.repeat(64),kind:'foggy',classification:'reported_farm_weather',temperature_f:43,description:'Fair skies',observed_at:Math.floor(now/1000)-30,valid_until:Math.floor(now/1000)+3600,server_time:Math.floor(now/1000)};
const oldWrites=writes.length;runtime.setSharedWeather(shared);assert.equal(ids.landscape.dataset.weather,'fog');assert.equal(ids.landscape.dataset.weatherSource,'shared');assert.equal(runtime.weatherPace(),.7);assert.equal(ids['scene-weather'].hidden,true);assert.equal(ids['scene-weather-label'].hidden,true);assert.ok(ids['weather-note'].textContent.includes('Shared farm weather: Foggy'));
ids['scene-weather'].value='snow';ids['scene-weather'].fire('change');assert.equal(ids.landscape.dataset.weather,'fog','A visitor cannot change shared conditions');assert.equal(writes.length,oldWrites,'Shared weather never enters cosmetic storage');
for(const kind of ['sunny','rainy','foggy','snowy']){runtime.setSharedWeather({...shared,kind});assert.ok(runtime.weatherPace()>0&&runtime.weatherPace()<=1);assert.equal(timers.size,1,'Shared weather adds no timer or network request');}
assert.throws(()=>runtime.setSharedWeather({...shared,kind:'storm'}));assert.throws(()=>runtime.setSharedWeather({...shared,id:'private-key'}));
now+=3600001;doc.dispatchEvent({type:'visibilitychange'});assert.ok(ids['weather-note'].textContent.includes('Last observed'));assert.equal(runtime.weatherPace(),1,'Expired weather cannot claim fresh behavioral effects');
assert.equal(ids.landscape.dataset.weather,'clear','Stale precipitation clears to the neutral atmosphere');runtime.setSharedWeather({available:false,classification:'reported_farm_weather'});assert.ok(ids['weather-note'].textContent.includes('unavailable'));assert.equal(runtime.weatherPace(),1);assert.equal(ids.landscape.dataset.weather,'clear');runtime.setSharedWeather(null);assert.equal(ids['scene-weather'].hidden,false);assert.equal(ids.landscape.dataset.weatherSource,'preview');assert.ok(ids['weather-note'].textContent.includes('Local scene preview only'));assert.equal(timers.size,1);
assert.ok(css.includes('scene-fog')&&css.includes('data-weather="fog"'));
console.log('PASS saved shared sun/rain/fog/snow, visitor override denial, weather-aware pace, source/staleness labels, preview restoration and zero extra timers/requests');
