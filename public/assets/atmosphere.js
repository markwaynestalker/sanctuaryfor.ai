'use strict';
// Display only: no network, resident state, provider context or simulation clock.
globalThis.SparksAtmosphere = (() => {
    const villageZone = 'America/New_York', zoneKey = 'sparks_display_timezone_v1', weatherKey = 'sparks_scene_weather_v1';
    const clamp = (n,a,b) => Math.max(a,Math.min(b,n));
    function validZone(zone) {
        if (typeof zone !== 'string' || zone.length > 64 || !/^(?:UTC|[A-Za-z_]+(?:\/[A-Za-z0-9_+\-]+){1,2})$/.test(zone)) return false;
        try { new Intl.DateTimeFormat('en', {timeZone:zone}).format(0); return true; } catch { return false; }
    }
    const safeZone = zone => validZone(zone) ? zone : 'UTC';
    const safeWeather = value => ['clear','rain','snow'].includes(value) ? value : 'clear';
    let zone = 'UTC', weather = 'clear', scene = null, motionPaused = false;
    function read(storage, key) { try { return storage?.getItem(key); } catch { return null; } }
    function write(storage, key, value) { try { storage?.setItem(key,value); } catch { /* In-memory preference still works. */ } }
    function preferences(storage) { return {zone:safeZone(read(storage,zoneKey)),weather:safeWeather(read(storage,weatherKey))}; }
    function format(value, timeZone=zone) {
        const date = new Date(value); if (!Number.isFinite(date.valueOf())) return '';
        timeZone=safeZone(timeZone);
        try { return `${new Intl.DateTimeFormat('en-US',{timeZone,year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',timeZoneName:'short'}).format(date)} · ${timeZone}`; }
        catch { return date.toISOString().replace('T',' ').replace(/\.\d+Z$/, ' UTC'); }
    }
    function dayKey(value, timeZone=zone) {
        const date=new Date(value); if (!Number.isFinite(date.valueOf())) return '';
        try { const p=new Intl.DateTimeFormat('en-US',{timeZone:safeZone(timeZone),year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date);return ['year','month','day'].map(k=>p.find(v=>v.type===k).value).join('-'); }
        catch { return date.toISOString().slice(0,10); }
    }
    function savedLabel(conversation, now=Date.now()) {
        return `${dayKey(conversation.created_at)===dayKey(now)?'Saved today':'From the village journal'} · ${conversation.provider==='openai'?'Saved AI-generated dialogue':'Saved scripted dialogue'} · ${format(conversation.created_at)}`;
    }
    function atmosphereAt(now) {
        // Decorative clock bands, not astronomical sunrise or recorded weather.
        const parts=new Intl.DateTimeFormat('en-US',{timeZone:villageZone,hourCycle:'h23',hour:'2-digit',minute:'2-digit'}).formatToParts(new Date(now));
        const minutes=Number(parts.find(p=>p.type==='hour').value)*60+Number(parts.find(p=>p.type==='minute').value), h=minutes/60;
        const phase=h<6||h>=19?'night':h<8?'dawn':h<17?'day':'dusk';
        const sun=clamp((h-6)/13,0,1), moon=clamp((h>=19?h-19:h+5)/11,0,1);
        const stars=phase==='night'?1:phase==='dawn'?1-(h-6)/2:phase==='dusk'?(h-17)/2:0;
        return {phase,minutes,stars,sunX:10+75*sun,sunY:70-52*Math.sin(sun*Math.PI),sunOpacity:clamp(Math.min(h-6,19-h),0,1),moonX:10+75*moon,moonY:70-52*Math.sin(moon*Math.PI)};
    }
    function setPaused(paused) { motionPaused=Boolean(paused); if(scene)scene.dataset.motion=motionPaused||document.hidden?'paused':'running'; }
    function updateTimes(root=document) {
        for(const time of root.querySelectorAll('time[datetime]')){
            const value=time.getAttribute('datetime'), label=format(value);
            if(label){time.textContent=label;time.title=`Saved UTC: ${value}`;}
        }
    }
    function start() {
        let storage;try{storage=localStorage;}catch{ /* Optional cosmetic storage. */ }
        ({zone,weather}=preferences(storage));scene=document.getElementById('landscape');
        const select=document.getElementById('display-timezone');
        if(select){
            let browserZone;try{browserZone=Intl.DateTimeFormat().resolvedOptions().timeZone;}catch{ /* UTC fallback. */ }
            for(const value of [browserZone,zone])if(validZone(value)&&![...select.options].some(o=>o.value===value)){
                const option=document.createElement('option');option.value=value;option.textContent=value;select.append(option);
            }
            select.value=zone;select.closest('.time-preferences').hidden=false;
            select.addEventListener('change',()=>{zone=safeZone(select.value);select.value=zone;write(storage,zoneKey,zone);refresh();document.dispatchEvent(new Event('sparks:timezone'));});
        }
        const weatherSelect=document.getElementById('scene-weather');
        if(scene&&weatherSelect){
            scene.dataset.weather=weather;weatherSelect.value=weather;
            weatherSelect.addEventListener('change',()=>{weather=safeWeather(weatherSelect.value);weatherSelect.value=weather;scene.dataset.weather=weather;write(storage,weatherKey,weather);});
            document.getElementById('atmosphere-controls').hidden=false;
        }
        let timer=null,lastDay=dayKey(Date.now());
        function refresh() {
            clearTimeout(timer);timer=null;setPaused(motionPaused);
            if(document.hidden)return;
            updateTimes();
            if(scene&&validZone(villageZone)){
                const state=atmosphereAt(Date.now());scene.dataset.phase=state.phase;
                for(const [name,value] of Object.entries({stars:state.stars,'sun-x':state.sunX+'%','sun-y':state.sunY+'px','sun-opacity':state.sunOpacity,'moon-x':state.moonX+'%','moon-y':state.moonY+'px'}))scene.style.setProperty('--'+name,String(value));
                document.getElementById('village-clock').textContent=`Village ${state.phase} · ${format(Date.now(),villageZone)}`;
            }
            const today=dayKey(Date.now());if(today!==lastDay){lastDay=today;document.dispatchEvent(new Event('sparks:timezone'));}
            // One coarse clock timer while visible, never a network poll or animation loop.
            timer=setTimeout(refresh,60000-Date.now()%60000);
        }
        document.addEventListener('visibilitychange',refresh);refresh();
    }
    return Object.freeze({villageZone,validZone,safeZone,safeWeather,preferences,format,dayKey,savedLabel,atmosphereAt,setPaused,updateTimes,start});
})();
if(typeof document!=='undefined')SparksAtmosphere.start();
