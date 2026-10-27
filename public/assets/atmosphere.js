'use strict';
// Renders saved shared weather or a clearly local preview; no network or world writes.
globalThis.SparksAtmosphere = (() => {
    const villageZone = 'America/New_York', zoneKey = 'sparks_display_timezone_v1', weatherKey = 'sparks_scene_weather_v1';
    const clamp = (n,a,b) => Math.max(a,Math.min(b,n));
    function validZone(zone) {
        if (typeof zone !== 'string' || zone.length > 64 || !/^(?:UTC|[A-Za-z_]+(?:\/[A-Za-z0-9_+\-]+){1,2})$/.test(zone)) return false;
        try { new Intl.DateTimeFormat('en', {timeZone:zone}).format(0); return true; } catch { return false; }
    }
    const safeZone = zone => validZone(zone) ? zone : 'UTC';
    const safeWeather = value => ['clear','rain','fog','snow'].includes(value) ? value : 'clear';
    let zone = 'UTC', weather = 'clear', scene = null, motionPaused = false,shared=null;
    const elapsedClock=()=>globalThis.performance?.now?.()??Date.now();
    function setSharedWeather(value){
        if(value===null||value===undefined){shared=null;renderWeather();return;}
        if(value.available===false&&value.classification==='reported_farm_weather'){shared={unavailable:true};renderWeather();return;}
        if(value.classification!=='reported_farm_weather'||value.version!==1||!['sunny','rainy','foggy','snowy','neutral'].includes(value.kind)
            ||!['observed_at','valid_until','server_time'].every(k=>Number.isSafeInteger(value[k])&&value[k]>=0)||value.valid_until<=value.observed_at||!Number.isFinite(value.temperature_f)||value.temperature_f< -150||value.temperature_f>160||!['Fair skies','Rain reported','Fog reported','Snow reported','Cloud cover reported','Conditions unavailable'].includes(value.description)
            ||typeof value.id!=='string'||!/^[a-f0-9]{64}$/.test(value.id))throw new Error('Shared weather is invalid.');
        shared={...value,received:elapsedClock()};renderWeather();
    }
    function sharedCurrent(){return shared&&!shared.unavailable&&shared.server_time+(elapsedClock()-shared.received)/1000>=shared.observed_at&&shared.server_time+(elapsedClock()-shared.received)/1000<shared.valid_until;}
    function weatherPace(){return sharedCurrent()?({sunny:1,rainy:.85,foggy:.7,snowy:.75,neutral:1}[shared.kind]):1;}
    function renderWeather(){
        if(!scene)return;
        const picker=document.getElementById('scene-weather'),label=document.getElementById('scene-weather-label'),note=document.getElementById('weather-note');
        if(picker)picker.hidden=!!shared;if(label)label.hidden=!!shared;
        scene.dataset.weatherSource=shared?'shared':'preview';
        scene.dataset.weather=shared?(sharedCurrent()?({sunny:'clear',rainy:'rain',foggy:'fog',snowy:'snow',neutral:'clear'}[shared.kind]):'clear'):weather;
        if(note){
            note.textContent=shared?(shared.unavailable?'Farm weather is unavailable. The shared scene is neutral; weather is omitted from Director context.':`${sharedCurrent()?'Shared farm weather':'Last observed farm weather'}: ${{sunny:'Clear skies',rainy:'Rainy',foggy:'Foggy',snowy:'Snowy',neutral:shared.description}[shared.kind]} · ${shared.temperature_f}°F. Observed ${format(shared.observed_at*1000)}.${sharedCurrent()?' Optional context for normal village encounters.':' Stale: the shared scene is neutral. Refresh residents to check for a newer update.'}`):'Local scene preview only. It does not change the Sparks or create village weather.';
        }
    }
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
            renderWeather();weatherSelect.value=weather;
            weatherSelect.addEventListener('change',()=>{if(shared){renderWeather();return;}weather=safeWeather(weatherSelect.value);weatherSelect.value=weather;scene.dataset.weather=weather;write(storage,weatherKey,weather);});
            document.getElementById('atmosphere-controls').hidden=false;
        }
        if(scene?.dataset.sharedWeather){try{setSharedWeather(JSON.parse(scene.dataset.sharedWeather));}catch{const note=document.getElementById('weather-note');if(note)note.textContent='Shared weather is unavailable. Refresh the village to check again.';}}
        let timer=null,lastDay=dayKey(Date.now());
        function refresh() {
            clearTimeout(timer);timer=null;setPaused(motionPaused);
            if(document.hidden)return;
            updateTimes();
            document.dispatchEvent(new Event('sparks:clock'));
            if(scene&&validZone(villageZone)){
                const state=atmosphereAt(Date.now());scene.dataset.phase=state.phase;
                for(const [name,value] of Object.entries({stars:state.stars,'sun-x':state.sunX+'%','sun-y':state.sunY+'px','sun-opacity':state.sunOpacity,'moon-x':state.moonX+'%','moon-y':state.moonY+'px'}))scene.style.setProperty('--'+name,String(value));
                document.getElementById('village-clock').textContent=`Village ${state.phase} · ${format(Date.now(),villageZone)}`;
            }
            renderWeather();
            const today=dayKey(Date.now());if(today!==lastDay){lastDay=today;document.dispatchEvent(new Event('sparks:timezone'));}
            // One coarse clock timer while visible, never a network poll or animation loop.
            timer=setTimeout(refresh,60000-Date.now()%60000);
        }
        document.addEventListener('visibilitychange',refresh);refresh();
    }
    return Object.freeze({villageZone,validZone,safeZone,safeWeather,setSharedWeather,weatherPace,preferences,format,dayKey,savedLabel,atmosphereAt,setPaused,updateTimes,start});
})();
if(typeof document!=='undefined')SparksAtmosphere.start();
