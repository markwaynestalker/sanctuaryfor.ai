'use strict';
(() => {
    const $ = id => document.getElementById(id);
    const icons = {house:'⌂',tree:'🌳',flowers:'🌷',bush:'🌿',path:'·',decoration:'🏮',pet:'🐈'};
    const colors = new Set(['mint','lavender','peach','sky','gold','sage','coral','amber','violet','teal','rose','magenta','emerald','forest','copper','terracotta','silver','slate','cream','warm-white','indigo','deep-blue','berry','burgundy']);
    let token = null, csrf = '', resident = null, pending = null, busy = false;
    let sector = Math.max(0,Math.min(624,Number(new URL(location.href).searchParams.get('sector'))||0));
    let fragment = new URLSearchParams(location.hash.slice(1)).get('revisit');
    if (fragment !== null) history.replaceState(null,'',location.pathname+location.search);
    const status = text => { $('garden-status').textContent = text; };
    async function request(endpoint, body) {
        const response = await fetch(`api/${endpoint}`, {credentials:'same-origin',cache:'no-store',
            ...(body ? {method:'POST',headers:{'Content-Type':'application/json','X-Sparks-CSRF':csrf},body:JSON.stringify(body)} : {})});
        let data;
        try { data = await response.json(); } catch { throw new Error('The saved world is resting. Please try again later.'); }
        if (!response.ok) throw new Error(data.error||'The change could not be saved.');
        return data;
    }
    async function loadWorld(nextSector=sector) {
        const data = await request(`world.php?sector=${nextSector}`); csrf=data.csrf;
        if (nextSector!==sector) { location.href=`world.php?sector=${nextSector}`; return; }
        const map=$('saved-map');
        if (!map) return;
        map.replaceChildren();
        if (!data.plots.length) {
            const empty=document.createElement('p');empty.className='map-empty';empty.textContent='A quiet patch of sanctuary. No home plots are assigned here yet.';map.append(empty);
        }
        for (const plot of data.plots) {
            const cell=document.createElement('span'),label=document.createElement('span');
            cell.className=`map-plot color-${colors.has(plot.resident.color)?plot.resident.color:'mint'}`; cell.style.left=`${(plot.x-data.origin.x)/16*100}%`; cell.style.top=`${(plot.y-data.origin.y)/16*100}%`;
            label.textContent=plot.resident.name; cell.append(label); map.append(cell);
        }
        for (const object of data.objects) {
            if (!Object.hasOwn(icons,object.kind)) continue;
            const item=document.createElement('span'); item.className=`map-object kind-${object.kind}`;
            item.style.left=`${(object.x-data.origin.x+.5)/16*100}%`; item.style.top=`${(object.y-data.origin.y+.5)/16*100}%`;
            item.textContent=object.kind==='house'&&object.variant==='stars'?'✫':icons[object.kind];
            item.title=`${object.variant} at ${object.x}, ${object.y}`; map.append(item);
        }
        // Keep the stationary accessible list current as well as the illustration.
        const list=$('plot-list'); list.replaceChildren();
        for (const plot of data.plots) {
            const li=document.createElement('li'),link=document.createElement('a'),bounds=document.createElement('span'),objects=document.createElement('ul');
            link.href=`resident.php?id=${encodeURIComponent(plot.resident.id)}`; link.textContent=`${plot.resident.name}’s plot ↗`;
            bounds.textContent=`Cells ${plot.x}–${plot.x+3}, ${plot.y}–${plot.y+3}`;
            for (const object of data.objects.filter(o=>o.resident_id===plot.resident.id)) {
                const line=document.createElement('li'); line.textContent=`${object.variant} ${object.kind} at ${object.x}, ${object.y}`; objects.append(line);
            }
            li.append(link,bounds,objects); list.append(li);
        }
        if (resident && resident.plot.sector===sector) {
            const occupied=new Set(data.objects.map(o=>`${o.x},${o.y}`));
            for(let y=resident.plot.y;y<resident.plot.y+4;y++) for(let x=resident.plot.x;x<resident.plot.x+4;x++) {
                if(!occupied.has(`${x},${y}`)) { $('garden-x').value=String(x); $('garden-y').value=String(y); return; }
            }
        }
    }
    function parseToken(value) {
        if (/^[a-f0-9]{64}$/.test(value)) return value;
        try { return new URLSearchParams(new URL(value).hash.slice(1)).get('revisit'); } catch { return null; }
    }
    async function unlock(value) {
        const candidate=parseToken(value);
        $('revisit-link').value='';
        if (!candidate || !/^[a-f0-9]{64}$/.test(candidate)) throw new Error('Paste the private revisit link saved when your robot was created.');
        const data=await request('revisit.php',{token:candidate});
        const profile=await request(`profile.php?id=${encodeURIComponent(data.resident.id)}`);
        if (!profile.plot) throw new Error('This resident has no assigned plot yet.');
        token=candidate; resident=profile; pending=null;
        $('garden-resident').textContent=`${profile.resident.name}’s private garden controls`;
        $('garden-bounds').textContent=`Your plot: X ${profile.plot.x}–${profile.plot.x+3}, Y ${profile.plot.y}–${profile.plot.y+3}.`;
        $('my-neighborhood').href=`world.php?sector=${profile.plot.sector}`;
        for(const [axis,start] of [['x',profile.plot.x],['y',profile.plot.y]]) {
            $(`garden-${axis}`).min=String(start); $(`garden-${axis}`).max=String(start+3); $(`garden-${axis}`).value=String(start);
        }
        $('unlock-form').hidden=true; $('garden-controls').hidden=false;
        status('Garden open. Changes are public and saved only when you submit an action.');
        await loadWorld(); $('garden-action').focus();
    }
    $('unlock-form').addEventListener('submit',async event=>{
        event.preventDefault(); if(busy) return; busy=true;
        try { await unlock($('revisit-link').value.trim()); } catch(error) { status(error.message); }
        finally { busy=false; }
    });
    $('lock-garden').addEventListener('click',()=>{
        token=null;resident=null;pending=null;$('garden-controls').hidden=true;$('unlock-form').hidden=false;
        status('Private garden closed. Your changes remain saved.');$('revisit-link').focus();
    });
    function actionFields() {
        const kind=$('garden-action').value, coordinates=!['decorate_house','visit_friend'].includes(kind);
        $('coordinate-fields').hidden=!coordinates; $('neighbor-fields').hidden=kind!=='visit_friend';
        $('garden-x').required=coordinates;$('garden-y').required=coordinates;$('neighbor-id').required=kind==='visit_friend';
    }
    $('garden-action').addEventListener('change',actionFields); actionFields();
    $('action-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!token)return;
        const fields={action:$('garden-action').value};
        if(fields.action==='visit_friend')fields.target=$('neighbor-id').value.trim();
        else if(fields.action!=='decorate_house'){fields.x=Number($('garden-x').value);fields.y=Number($('garden-y').value);}
        const signature=JSON.stringify(fields);
        if(!pending||pending.signature!==signature) {
            const bytes=crypto.getRandomValues(new Uint8Array(16));
            pending={signature,payload:{...fields,requestId:[...bytes].map(b=>b.toString(16).padStart(2,'0')).join('')}};
        }
        busy=true;$('apply-action').disabled=true;
        try {
            const result=await request('action.php',{...pending.payload,token}); pending=null;
            status(result.replayed?'That change was already saved. No duplicate was made.':'A little change, saved. It is now in the public village journal.');
            try { await loadWorld(); } catch { status('Your change was saved. Refresh the saved world to see it.'); }
        } catch(error) { status(error.message+' An unchanged retry will not duplicate a saved action.'); }
        finally {busy=false;$('apply-action').disabled=false;}
    });
    loadWorld().then(async()=>{ if(fragment!==null){const value=fragment;fragment=null;await unlock(value);} }).catch(error=>status(error.message));
})();
