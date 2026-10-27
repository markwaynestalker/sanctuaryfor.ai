'use strict';
// Decorative geometry and bounded local motion. Saved plot coordinates are read
// only to place actual objects within compact yards, never rewritten here.
globalThis.SparksMeadow = (() => {
    const distance = (a,b) => Math.hypot(a.x-b.x,a.y-b.y);
    const clamp = (n,a,b) => Math.max(a,Math.min(b,n));
    const capacity = mobile => mobile ? 6 : 12;
    function layout(width, homes, mobile, population, resting=false) {
        width=Math.max(120,width);
        const compact=width<=360;
        const shown=homes.slice(0,capacity(mobile)), yardWidth=Math.min(width-24,96), yardHeight=100, gap=16;
        const columns=Math.max(1,Math.min(mobile?3:6,Math.floor((width-24+gap)/(yardWidth+gap))));
        const gardens=shown.map((home,i) => {
            const row=Math.floor(i/columns), count=Math.min(columns,shown.length-row*columns), column=i%columns;
            const left=12+(column+.5)*(width-24)/count-yardWidth/2+(row%2?5:-2);
            return {home,left:clamp(left,12,width-yardWidth-12),top:116+row*120+[0,8,16,4,12,6][column],width:yardWidth,height:yardHeight};
        });
        const plots=resting?gardens:[], commonColumns=Math.max(1,Math.floor((width-104)/108)+1);
        const height=Math.max(520,160+Math.ceil(population/commonColumns)*110+155,...plots.map(p=>p.top+p.height+110));
        const obstacles=plots.map(p=>({left:p.left,right:p.left+p.width,top:p.top,bottom:p.top+p.height}));
        // The chicken gets a small local refuge; no full-width exclusion band.
        obstacles.push({left:width*.93-54,right:width*.93+8,top:height-70,bottom:height-8});
        // The noticeboard stands at the edge, with a small local footprint.
        obstacles.push({left:8,right:Math.min(116,width*.42),top:height-104,bottom:height-8});
        return {width,height,compact,gardens,plots,obstacles,bounds:{left:52,right:Math.max(52,width-52),top:150,bottom:height-110}};
    }
    function free(point,scene) {
        const b=scene.bounds;
        return point.x>=b.left&&point.x<=b.right&&point.y>=b.top&&point.y<=b.bottom&&!scene.obstacles.some(o=>point.x>o.left-50&&point.x<o.right+50&&point.y>o.top-50&&point.y<o.bottom+50);
    }
    const overlaps=(a,b)=>Math.abs(a.x-b.x)<104&&Math.abs(a.y-b.y)<106;
    function candidates(scene) {
        const result=[],b=scene.bounds;
        for(let y=b.top;y<=b.bottom;y+=110)for(let x=b.left;x<=b.right;x+=108)if(free({x,y},scene))result.push({x,y});
        return result;
    }
    function target(scene,random,origin) {
        const b=scene.bounds;
        for(let i=0;i<24;i++){
            const p={x:b.left+random()*(b.right-b.left),y:b.top+random()*(b.bottom-b.top)};
            if(free(p,scene)&&(!origin||distance(p,origin)>70))return p;
        }
        return candidates(scene).sort((a,b)=>distance(b,origin||a)-distance(a,origin||b))[0]||origin||{x:b.left,y:b.bottom};
    }
    function seed(walkers,scene,now,random,preserve=false) {
        const points=candidates(scene),used=[];
        // Arrivals start in the shared meadow, without restricting where they
        // may wander afterward or turning the gardens into a collision band.
        const gardenEdge=scene.bounds.top;
        const common=points.filter(p=>p.y>=gardenEdge),arrivals=common.length>=walkers.length?common:points;
        walkers.forEach((w,i) => {
            if(preserve&&Number.isFinite(w.x)&&Number.isFinite(w.y)&&free(w,scene)&&used.every(p=>!overlaps(w,p))){
                used.push({x:w.x,y:w.y});
                if(!w.target||!free(w.target,scene))w.target=target(scene,random,w);
                return;
            }
            const ranked=arrivals.filter(p=>used.every(other=>!overlaps(p,other))).sort((a,b)=>{
                if(!used.length)return distance(b,{x:scene.width/2,y:scene.height*.65})-distance(a,{x:scene.width/2,y:scene.height*.65});
                return Math.min(...used.map(p=>distance(b,p)))-Math.min(...used.map(p=>distance(a,p)));
            });
            const p=ranked[0]||target(scene,random);used.push(p);w.x=p.x;w.y=p.y;
            w.target=target(scene,random,w);w.nextDecision=now+4000+i*700;w.stuck=0;
        });
    }
    function step(walkers,scene,delta,now,random) {
        const prior=walkers.map(w=>({x:w.x,y:w.y}));
        walkers.forEach((w,i) => {
            if(now<w.stop||w.hover)return;
            if(!w.target||distance(w,w.target)<12)w.target=target(scene,random,w);
            if(now>=w.nextDecision){
                w.nextDecision=now+5000+random()*8000;
                if(random()<.2){w.stop=now+800+random()*1800;return;}
                w.target=target(scene,random,w);
            }
            const length=Math.max(1,distance(w,w.target));let dx=(w.target.x-w.x)/length,dy=(w.target.y-w.y)/length;
            prior.forEach((other,j)=>{
                if(i===j)return;const d=distance(w,other);
                if(d<145){const angle=(i+1)*2.4;dx+=(d?(w.x-other.x)/d:Math.cos(angle))*(145-d)/40;dy+=(d?(w.y-other.y)/d:Math.sin(angle))*(145-d)/40;}
            });
            const norm=Math.max(1,Math.hypot(dx,dy)),speed=({playful:24,curious:20,shy:15,philosophical:17}[w.resident.personality]||18)*(1+(i%3)*.08);
            const move=speed*Math.min(delta,.2),p={x:w.x+dx/norm*move,y:w.y+dy/norm*move};
            const clear=q=>free(q,scene)&&walkers.every((other,j)=>i===j||!overlaps(q,other));
            let next=clear(p)?p:null;
            if(!next){const horizontal={x:p.x,y:w.y},vertical={x:w.x,y:p.y};next=clear(horizontal)?horizontal:clear(vertical)?vertical:null;}
            if(next&&distance(w,next)>.05){w.x=next.x;w.y=next.y;w.stuck=0;}
            else{w.stuck=(w.stuck||0)+delta;if(w.stuck>.6){w.target=target(scene,random,w);w.stuck=0;}}
        });
    }
    function separate(a,b,scene,random) {
        const dx=a.x-b.x,dy=a.y-b.y,length=Math.max(1,Math.hypot(dx,dy));
        for(const [w,sign] of [[a,1],[b,-1]]){
            const p={x:clamp(w.x+sign*dx/length*130,scene.bounds.left,scene.bounds.right),y:clamp(w.y+sign*dy/length*130,scene.bounds.top,scene.bounds.bottom)};
            w.target=free(p,scene)&&distance(p,w)>12?p:target(scene,random,w);
        }
    }
    return Object.freeze({capacity,layout,free,seed,step,separate,distance,overlaps});
})();
