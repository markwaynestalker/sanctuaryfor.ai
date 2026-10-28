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
        const height=Math.max(520,160+(Math.ceil(population/commonColumns)+1)*110+155,...plots.map(p=>p.top+p.height+110));
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
    function candidates(scene,spacing=108) {
        const result=[],b=scene.bounds;
        for(let y=b.top;y<=b.bottom;y+=spacing+2)for(let x=b.left;x<=b.right;x+=spacing)if(free({x,y},scene))result.push({x,y});
        return result;
    }
    function target(scene,random,origin,walkers=[]) {
        // Fixed safe waypoints, short excursions and no per-frame layout reads.
        const points=candidates(scene,54).filter(p=>(!origin||distance(p,origin)>24&&distance(p,origin)<=165)&&walkers.every(w=>w===origin||!overlaps(p,w)));
        return points[Math.min(points.length-1,Math.floor(random()*points.length))]||origin||{x:scene.bounds.left,y:scene.bounds.bottom};
    }
    function idle(w,now,random) {
        w.motion='idle';w.target=null;w.walkUntil=0;w.idleUntil=now+10000+random()*18000;
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
            idle(w,now,random);w.idleUntil=now+2000+i*1300+random()*5000;w.stuck=0;
        });
    }
    function step(walkers,scene,delta,now,random) {
        const prior=walkers.map(w=>({x:w.x,y:w.y}));
        walkers.forEach((w,i) => {
            if(now<w.stop||w.hover)return;
            if(!w.gatherTarget){
                if(w.motion==='walking'&&(now>=w.walkUntil||!w.target||distance(w,w.target)<8)){idle(w,now,random);return;}
                if(w.motion!=='walking'){
                    if(now<(w.idleUntil??0))return;
                    w.target=target(scene,random,w,walkers);w.walkUntil=now+6000+random()*5000;w.motion='walking';
                    if(w.target===w){idle(w,now,random);return;}
                }
            }else{
                w.target=w.gatherTarget;if(distance(w,w.target)<8)return;
            }
            const length=Math.max(1,distance(w,w.target));let dx=(w.target.x-w.x)/length,dy=(w.target.y-w.y)/length;
            prior.forEach((other,j)=>{
                if(i===j||w.gatherTarget&&walkers[j].gatherTarget)return;const d=distance(w,other);
                if(d<145){const angle=(i+1)*2.4;dx+=(d?(w.x-other.x)/d:Math.cos(angle))*(145-d)/40;dy+=(d?(w.y-other.y)/d:Math.sin(angle))*(145-d)/40;}
            });
            const norm=Math.max(1,Math.hypot(dx,dy)),speed=12+(i%3)*2;
            const move=speed*Math.min(delta,.2),p={x:w.x+dx/norm*move,y:w.y+dy/norm*move};
            const clear=q=>free(q,scene)&&walkers.every((other,j)=>i===j||!overlaps(q,other));
            let next=clear(p)?p:null;
            if(!next){const horizontal={x:p.x,y:w.y},vertical={x:w.x,y:p.y};next=clear(horizontal)?horizontal:clear(vertical)?vertical:null;}
            if(next&&distance(w,next)>.05){w.x=next.x;w.y=next.y;w.stuck=0;}
            else{w.stuck=(w.stuck||0)+delta;if(w.stuck>1.5&&!w.gatherTarget){idle(w,now,random);w.stuck=0;}}
        });
    }
    function gather(a,b,walkers,scene,now) {
        if(!a||!b||a===b||a.hover||b.hover||now<a.stop||now<b.stop)return null;
        const middle={x:(a.x+b.x)/2,y:(a.y+b.y)/2},others=walkers.filter(w=>w!==a&&w!==b);
        const points=candidates(scene,54).filter(p=>distance(p,middle)<180&&others.every(w=>!overlaps(p,w)));
        let best=null,cost=Infinity;
        for(const x of points)for(const y of points){
            if(overlaps(x,y)||distance(x,y)>175)continue;
            const travel=distance(a,x)+distance(b,y);
            if(travel<cost&&travel<=240){best=[x,y];cost=travel;}
        }
        if(!best)return null; // Crowded/narrow scenes keep the visual association in place.
        a.gatherTarget=best[0];b.gatherTarget=best[1];
        return {a,b,until:now+16000};
    }
    function endGather(group,now,random) {
        if(!group)return;
        for(const w of [group.a,group.b])if(w){w.gatherTarget=null;idle(w,now,random);}
    }
    return Object.freeze({capacity,layout,free,seed,step,gather,endGather,distance,overlaps});
})();
