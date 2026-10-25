import assert from 'node:assert/strict';import vm from 'node:vm';import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/meadow.js',import.meta.url),'utf8'),context=vm.createContext({});vm.runInContext(source,context);const m=context.SparksMeadow;
const css=await readFile(new URL('../public/assets/village.css',import.meta.url),'utf8');assert.ok(!/\.commons-homes\s*\{[^}]*\btop\s*:/s.test(css),'Responsive CSS must not shift the absolute geometry or reintroduce a house band');
function homes(count){return Array.from({length:count},(_,i)=>({resident_id:String(i),plot:{x:i*4,y:0},objects:[{kind:'house',x:i*4+1,y:1},{kind:'tree',x:i*4+3,y:3}]}));}
function rng(seed){return ()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296;};}
for(const [width,count,mobile] of [[272,3,true],[320,3,true],[320,4,true],[600,3,false],[1000,3,false],[600,12,false],[1000,12,false]]){
    const saved=homes(count),before=JSON.stringify(saved),scene=m.layout(width,saved,mobile,count);assert.equal(JSON.stringify(saved),before,'Saved coordinates remain immutable');
    for(const yard of scene.plots){assert.ok(yard.width<=90&&yard.height<=86,'Compact fixed-size yards never stretch across grid tracks');assert.ok(yard.left>=0&&yard.left+yard.width<=width,'Homes fit the viewport');assert.ok(yard.top>=85,'Cottages sit on the hillside');}
    const coverage=scene.plots.reduce((sum,p)=>sum+p.width*p.height,0)/(width*scene.height);assert.ok(coverage<(count<=4?.2:.32),'Personal gardens leave most of the scene shared');
    let available=0,total=0;for(let y=scene.bounds.top;y<scene.bounds.bottom;y+=10)for(let x=scene.bounds.left;x<scene.bounds.right;x+=10){total++;if(m.free({x,y},scene))available++;}
    assert.ok(available/total>(count<=4?.65:.5),`Local cottage obstacles preserve roaming ground at ${width}/${count}: ${available/total}`);
    assert.ok(scene.bounds.top<Math.max(...scene.plots.map(p=>p.top+p.height)),'Robots can use gaps beside homes rather than a full-width house exclusion band');
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
const scene=m.layout(320,homes(3),true,3),pair=[{resident:{personality:'curious'},x:70,y:330,stop:10000},{resident:{personality:'shy'},x:145,y:330,stop:10000}];
m.separate(pair[0],pair[1],scene,rng(3));const before=pair.map(w=>({x:w.x,y:w.y}));m.step(pair,scene,.08,5000,rng(3));assert.equal(JSON.stringify(pair.map(w=>({x:w.x,y:w.y}))),JSON.stringify(before),'Conversation pause is stationary');
for(let t=10001;t<13000;t+=80)m.step(pair,scene,.08,t,rng(t));assert.ok(m.distance(pair[0],pair[1])>90,'Neighbors separate after a conversation');
console.log('PASS compact three-resident yards, growing populations, desktop/mobile geometry, dominant shared ground, immutable plots, local obstacles, broad varied wandering, separation and natural encounters');
