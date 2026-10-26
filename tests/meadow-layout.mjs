import assert from 'node:assert/strict';import vm from 'node:vm';import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/meadow.js',import.meta.url),'utf8'),context=vm.createContext({});vm.runInContext(source,context);const m=context.SparksMeadow;
const css=await readFile(new URL('../public/assets/village.css',import.meta.url),'utf8');assert.ok(!/\.commons-homes\s*\{[^}]*\btop\s*:/s.test(css),'Responsive CSS must not shift the absolute geometry or reintroduce a house band');
function homes(count){return Array.from({length:count},(_,i)=>({resident_id:String(i),plot:{x:i*4,y:0},objects:[{kind:'house',x:i*4+1,y:1},{kind:'tree',x:i*4+3,y:3}]}));}
function rng(seed){return ()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296;};}
assert.equal(m.capacity(true),6);assert.equal(m.capacity(false),12);
for(const [width,count,mobile] of [[272,3,true],[320,3,true],[320,4,true],[278,6,true],[333,6,true],[348,6,true],[388,6,true],[600,3,false],[1000,6,false],[600,12,false],[1000,12,false]]){
    const saved=homes(count),before=JSON.stringify(saved),scene=m.layout(width,saved,mobile,count);assert.equal(JSON.stringify(saved),before,'Saved coordinates remain immutable');
    assert.equal(scene.plots.length,width<=360?0:count,'Extra-compact scenes omit homes without changing saved data');
    if(width<=360){assert.equal(scene.obstacles.length,1,'Only the protected chicken remains an obstacle');assert.equal(scene.bounds.top,110,'Hidden houses do not reserve roaming space');}
    assert.equal(JSON.stringify(m.layout(width,saved,mobile,count)),JSON.stringify(scene),'Cottage placement is stable, not rerolled');
    if(count>=6&&width>360)assert.ok(new Set(scene.plots.map(p=>p.top)).size>=3,'Neighborhood staggers naturally rather than a straight house row');
    for(const yard of scene.plots){assert.ok(yard.width<=90&&yard.height<=86,'Compact fixed-size yards never stretch across grid tracks');assert.ok(yard.left>=0&&yard.left+yard.width<=width,'Homes fit the viewport');assert.ok(yard.top>=85,'Cottages sit on the hillside');}
    const coverage=scene.plots.reduce((sum,p)=>sum+p.width*p.height,0)/(width*scene.height);assert.ok(coverage<(count<=4?.2:.32),'Personal gardens leave most of the scene shared');
    let available=0,total=0;for(let y=scene.bounds.top;y<scene.bounds.bottom;y+=10)for(let x=scene.bounds.left;x<scene.bounds.right;x+=10){total++;if(m.free({x,y},scene))available++;}
    assert.ok(available/total>(count<=4?.65:.5),`Local cottage obstacles preserve roaming ground at ${width}/${count}: ${available/total}`);
    if(scene.plots.length)assert.ok(scene.bounds.top<Math.max(...scene.plots.map(p=>p.top+p.height)),'Robots can use gaps beside homes rather than a full-width house exclusion band');
    const random=rng(width+count),walkers=Array.from({length:count},(_,i)=>({resident:{personality:['curious','playful','shy','philosophical'][i%4]},stop:0,hover:false}));m.seed(walkers,scene,0,random);
    const ranges=walkers.map(w=>({left:w.x,right:w.x,top:w.y,bottom:w.y,length:0}));let closeFrames=0,encounters=0;
    for(let tick=1;tick<=4000;tick++){
        const previous=walkers.map(w=>({x:w.x,y:w.y}));m.step(walkers,scene,.08,tick*80,random);
        walkers.forEach((w,i)=>{assert.ok(m.free(w,scene),'Robots avoid the small cottage/chicken footprints and stay in bounds');const r=ranges[i];r.left=Math.min(r.left,w.x);r.right=Math.max(r.right,w.x);r.top=Math.min(r.top,w.y);r.bottom=Math.max(r.bottom,w.y);r.length+=m.distance(w,previous[i]);});
        for(let i=0;i<count;i++)for(let j=i+1;j<count;j++){const d=m.distance(walkers[i],walkers[j]);if(d<58)closeFrames++;if(d<90)encounters++;}
    }
    assert.equal(closeFrames,0,'Robots never remain piled on one another');assert.ok(encounters>0,'Independent wandering still allows natural approaches');
    assert.ok(ranges.filter(r=>r.right-r.left>70&&r.bottom-r.top>90&&r.length>500).length>=Math.ceil(count*.75),'Broad exploration at '+width+'/'+count+': '+JSON.stringify(ranges.map(r=>({x:r.right-r.left,y:r.bottom-r.top,length:r.length}))));
}
for(const width of [120,158,210]){
    const scene=m.layout(width,homes(6),true,6);assert.equal(scene.plots.length,0,'Zoomed/narrow scenes prioritize the six robots over cottages');assert.equal(scene.obstacles.length,1);
    const walkers=Array.from({length:6},()=>({resident:{personality:'curious'},stop:0}));m.seed(walkers,scene,0,rng(width));
    for(let i=0;i<6;i++){assert.ok(m.free(walkers[i],scene));for(let j=0;j<i;j++)assert.ok(m.distance(walkers[i],walkers[j])>=60,'Even zoomed scenes can place all six without overlap');}
}
for(const mobile of [true,false]){
    const scene=m.layout(388,homes(40),mobile,40);assert.equal(scene.plots.length,m.capacity(mobile),'Growing neighborhoods remain bounded');
}
const preserved=m.layout(1000,[],false,6),residents=Array.from({length:6},()=>({resident:{personality:'curious'},stop:0}));m.seed(residents,preserved,0,rng(3));const positions=JSON.stringify(residents.map(w=>({x:w.x,y:w.y})));m.seed(residents,preserved,1000,rng(9),true);assert.equal(JSON.stringify(residents.map(w=>({x:w.x,y:w.y}))),positions,'An unchanged layout does not teleport valid residents');
for(const width of [158,278,348,1000]){
    const scene=m.layout(width,homes(6),width<650,6),size={width:Math.min(180,width-16),height:170};
    for(const point of [{x:34,y:132},{x:width-34,y:132},{x:34,y:scene.height-58},{x:width-34,y:scene.height-58}]){
        const box=m.bubblePosition(point,scene,size.width,size.height);assert.ok(box.left>=8&&box.top>=8&&box.left+size.width<=width-8&&box.top+size.height<=scene.height-8,'Saved speech stays inside every scene edge');
    }
}
const scene=m.layout(320,homes(3),true,3),pair=[{resident:{personality:'curious'},x:70,y:330,stop:10000},{resident:{personality:'shy'},x:145,y:330,stop:10000}];
m.separate(pair[0],pair[1],scene,rng(3));const before=pair.map(w=>({x:w.x,y:w.y}));m.step(pair,scene,.08,5000,rng(3));assert.equal(JSON.stringify(pair.map(w=>({x:w.x,y:w.y}))),JSON.stringify(before),'Conversation pause is stationary');
for(let t=10001;t<13000;t+=80)m.step(pair,scene,.08,t,rng(t));assert.ok(m.distance(pair[0],pair[1])>90,'Neighbors separate after a conversation');
console.log('PASS compact open meadows and restored six-cottage neighborhoods, zoomed/narrow/desktop geometry, immutable plots, stable positions, open roaming, separation, natural encounters and edge-safe speech');
