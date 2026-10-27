import assert from 'node:assert/strict';import vm from 'node:vm';import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/meadow.js',import.meta.url),'utf8'),context=vm.createContext({});vm.runInContext(source,context);const m=context.SparksMeadow;
const css=await readFile(new URL('../public/assets/village.css',import.meta.url),'utf8');
assert.ok(!/\.commons-homes\s*\{[^}]*\btop\s*:/s.test(css),'CSS never shifts absolute house geometry');
function homes(count){return Array.from({length:count},(_,i)=>({resident_id:String(i),plot:{x:i*4,y:0},objects:[{kind:'house',x:i*4+1,y:1},{kind:'tree',x:i*4+3,y:3}]}));}
function rng(seed){return ()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296;};}
assert.equal(m.capacity(true),6);assert.equal(m.capacity(false),12);
for(const [width,count,mobile] of [[272,3,true],[320,3,true],[320,4,true],[278,6,true],[333,6,true],[348,6,true],[388,6,true],[600,3,false],[1000,6,false],[600,12,false],[1000,12,false]]){
 const saved=homes(count),before=JSON.stringify(saved),scene=m.layout(width,saved,mobile,count),rest=m.layout(width,saved,mobile,count,true);
 assert.equal(JSON.stringify(saved),before,'Saved coordinates remain immutable');
 assert.equal(scene.plots.length,0,'Awake scenes contain no house collision areas');assert.equal(scene.obstacles.length,2,'Only the board and chicken reserve local space while awake');
 assert.equal(scene.gardens.length,count,'Actual saved gardens remain at every width');
 assert.equal(rest.plots.length,count,'Actual sleeping cottages appear even on compact phones');
 assert.equal(JSON.stringify(rest.gardens),JSON.stringify(scene.gardens),'House/garden positions stay stable through sleep/wake');
 for(const p of rest.plots){
  assert.ok(p.width<=96&&p.height===100&&p.left>=0&&p.left+p.width<=width,'House and full name target fit the scene');
  assert.ok(p.top>=116&&p.top+p.height<rest.height-104,'Homes sit on the hill, clear of board/chicken');
 }
 for(let i=0;i<rest.plots.length;i++)for(let j=0;j<i;j++){
  const a=rest.plots[i],b=rest.plots[j];assert.ok(a.left+a.width<=b.left||b.left+b.width<=a.left||a.top+a.height<=b.top||b.top+b.height<=a.top,'Resting cottages and labels never overlap');
 }
 let available=0,total=0;for(let y=scene.bounds.top;y<scene.bounds.bottom;y+=10)for(let x=scene.bounds.left;x<scene.bounds.right;x+=10){total++;if(m.free({x,y},scene))available++;}
 assert.ok(available/total>.8,'Awake meadow is dominant; gardens never become collision bands');
 const random=rng(width+count),walkers=Array.from({length:count},(_,i)=>({resident:{personality:['curious','playful','shy','philosophical'][i%4]},stop:0,hover:false}));m.seed(walkers,scene,0,random);
 const ranges=walkers.map(w=>({left:w.x,right:w.x,top:w.y,bottom:w.y,length:0}));let encounters=0;
 for(let tick=1;tick<=4000;tick++){
  const previous=walkers.map(w=>({x:w.x,y:w.y}));m.step(walkers,scene,.08,tick*80,random);
  walkers.forEach((w,i)=>{assert.ok(m.free(w,scene));const r=ranges[i];r.left=Math.min(r.left,w.x);r.right=Math.max(r.right,w.x);r.top=Math.min(r.top,w.y);r.bottom=Math.max(r.bottom,w.y);r.length+=m.distance(w,previous[i]);});
  for(let i=0;i<count;i++)for(let j=0;j<i;j++){assert.ok(!m.overlaps(walkers[i],walkers[j]),'Full SVG and two-line name footprints stay separate');if(m.distance(walkers[i],walkers[j])<180)encounters++;}
 }
 assert.ok(encounters>0,'Independent wandering still permits approaches for saved replay');
 // Full-width labels can form narrow horizontal OR vertical lanes on phones.
 // Require real two-axis travel and broad exploration, not impossible overtakes.
 const explored=r=>{const x=r.right-r.left,y=r.bottom-r.top;return r.length>500&&(width<400?x>12&&y>12&&Math.hypot(x,y)>125:x>70&&y>90);};
 assert.ok(ranges.filter(explored).length>=Math.ceil(count*.75),'Most residents explore the available ground at '+width+'/'+count+': '+JSON.stringify(ranges.map(r=>[r.right-r.left,r.bottom-r.top,r.length])));
}
for(const width of [120,158,210]){
 const scene=m.layout(width,homes(6),true,6),walkers=Array.from({length:6},()=>({resident:{personality:'curious'},stop:0}));m.seed(walkers,scene,0,rng(width));
 for(let i=0;i<walkers.length;i++){assert.ok(m.free(walkers[i],scene));for(let j=0;j<i;j++)assert.ok(!m.overlaps(walkers[i],walkers[j]),'Zoomed scenes keep full size names separate');}
 const rest=m.layout(width,homes(6),true,6,true);assert.equal(rest.plots.length,6);assert.ok(rest.plots.every(p=>p.left>=0&&p.left+p.width<=width),'Zoom never hides a resting home or creates overflow');
}
for(const mobile of [true,false]){
 const scene=m.layout(388,homes(40),mobile,40,true);assert.equal(scene.plots.length,m.capacity(mobile),'Neighborhoods remain bounded');
}
const scene=m.layout(1000,[],false,6),walkers=Array.from({length:6},()=>({resident:{personality:'curious'},stop:0}));m.seed(walkers,scene,0,rng(3));const positions=JSON.stringify(walkers.map(w=>({x:w.x,y:w.y})));m.seed(walkers,scene,1000,rng(9),true);assert.equal(JSON.stringify(walkers.map(w=>({x:w.x,y:w.y}))),positions,'Valid positions survive a layout refresh');
walkers[0].stop=walkers[1].stop=Infinity;const pair=JSON.stringify(walkers.slice(0,2).map(w=>({x:w.x,y:w.y})));
for(let tick=1;tick<200;tick++)m.step(walkers,scene,.08,tick*80,rng(tick));
assert.equal(JSON.stringify(walkers.slice(0,2).map(w=>({x:w.x,y:w.y}))),pair,'Selected saved participants remain stationary throughout reading');
console.log('PASS awake open meadows, resting homes at every width, immutable gardens, house/board/chicken separation, full SVG/name geometry, bounded neighborhoods, natural wandering and stable reading participants');
