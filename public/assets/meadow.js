'use strict';
// Decorative geometry and bounded local motion. Saved plot coordinates are read
// only to place actual objects within compact yards, never rewritten here.
globalThis.SparksMeadow = (() => {
    const distance = (a,b) => Math.hypot(a.x-b.x,a.y-b.y);
    const clamp = (n,a,b) => Math.max(a,Math.min(b,n));
    function layout(width, homes, mobile, population) {
        width=Math.max(120,width);
        const shown=homes.slice(0,mobile?4:12), yardWidth=mobile?68:90, yardHeight=86;
        const columns=Math.max(1,Math.floor((width-24)/(yardWidth+12))), rows=Math.ceil(shown.length/columns);
        const height=Math.max(520,320+rows*108,300+Math.ceil(population/(mobile?3:6))*110);
        const plots=shown.map((home,i) => {
            const row=Math.floor(i/columns), count=Math.min(columns,shown.length-row*columns), column=i%columns;
            return {home,left:(column+.5)*width/count-yardWidth/2,top:92+row*108+(column%2)*6,width:yardWidth,height:yardHeight};
        });
        const obstacles=plots.map(p => {
            const house=p.home.objects?.find(o=>o.kind==='house'), plot=p.home.plot;
            const x=house&&plot?clamp(house.x-plot.x,0,3):1, y=house&&plot?clamp(house.y-plot.y,0,3):1;
            const cx=p.left+(x+.5)*p.width/4, cy=p.top+(y+.5)*66/4;
            return {left:cx-12,right:cx+12,top:cy-22,bottom:cy+9};
        });
        // The chicken gets a small local refuge; no full-width exclusion band.
        obstacles.push({left:width*.93-54,right:width*.93+8,top:height-70,bottom:height-8});
        return {width,height,plots,obstacles,bounds:{left:34,right:Math.max(34,width-34),top:132,bottom:height-58}};
    }
    function free(point,scene) {
        const b=scene.bounds;
        return point.x>=b.left&&point.x<=b.right&&point.y>=b.top&&point.y<=b.bottom&&!scene.obstacles.some(o=>point.x>o.left-30&&point.x<o.right+30&&point.y>o.top-33&&point.y<o.bottom+33);
    }
    function candidates(scene) {
        const result=[],b=scene.bounds;
        for(let y=b.top;y<=b.bottom;y+=72)for(let x=b.left;x<=b.right;x+=72)if(free({x,y},scene))result.push({x,y});
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
    function seed(walkers,scene,now,random) {
        const points=candidates(scene),used=[];
        walkers.forEach((w,i) => {
            const ranked=points.filter(p=>used.every(other=>distance(p,other)>=64)).sort((a,b)=>{
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
                if(d<78){const angle=(i+1)*2.4;dx+=(d?(w.x-other.x)/d:Math.cos(angle))*(78-d)/30;dy+=(d?(w.y-other.y)/d:Math.sin(angle))*(78-d)/30;}
            });
            const norm=Math.max(1,Math.hypot(dx,dy)),speed=({playful:24,curious:20,shy:15,philosophical:17}[w.resident.personality]||18)*(1+(i%3)*.08);
            const move=speed*Math.min(delta,.2),p={x:w.x+dx/norm*move,y:w.y+dy/norm*move};
            const clear=q=>free(q,scene)&&walkers.every((other,j)=>i===j||distance(q,other)>=60);
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
    return Object.freeze({layout,free,seed,step,separate,distance});
})();
