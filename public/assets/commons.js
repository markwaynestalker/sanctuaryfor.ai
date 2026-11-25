'use strict';
// Pure selection/playback helpers, also exercised by offline Node tests.
globalThis.SparksCommons = (() => {
    const validId = id => typeof id === 'string' && /^[a-f0-9]{32}$/.test(id);
    const pairKey = (a, b) => [a, b].sort().join(':');
    function profileUrl(id) {
        if (!validId(id)) throw new Error('Invalid resident identity.');
        return `/resident.php?id=${encodeURIComponent(id)}`;
    }
    function bindProfileLink(link, dialog) {
        // Close the top-layer modal without cancelling the anchor's native navigation.
        link.addEventListener('click', () => { if (dialog.open) dialog.close(); });
        return resident => { link.setAttribute('href', profileUrl(resident.id)); link.hidden = false; };
    }
    function latestExchanges(conversations, ids) {
        const visible = new Set(ids), result = new Map();
        for (const c of conversations.slice(0, 64)) {
            if (!c || typeof c.id !== 'string' || !c.id || c.id.length > 64 || typeof c.created_at !== 'string' || !['openai', 'scripted'].includes(c.provider) || !Array.isArray(c.messages) || c.messages.length < 2 || c.messages.length > 64 || !Number.isFinite(Date.parse(c.created_at))) continue;
            const [a, b] = c.messages;
            if (!c.messages.every((m,i)=>m && m.resident_id===c.messages[i%2]?.resident_id && visible.has(m.resident_id) && validId(m.resident_id) && typeof m.text==='string' && Array.from(m.text).length>0 && Array.from(m.text).length<=160 && typeof m.name==='string' && Array.from(m.name).length>0 && Array.from(m.name).length<=64)) continue;
            if (!a || !b || a.resident_id === b.resident_id || ![a, b].every(m => visible.has(m.resident_id) && validId(m.resident_id) && typeof m.text === 'string' && Array.from(m.text).length > 0 && Array.from(m.text).length <= 160 && typeof m.name === 'string' && Array.from(m.name).length > 0 && Array.from(m.name).length <= 64)) continue;
            const key = pairKey(a.resident_id, b.resident_id), previous = result.get(key);
            if (!previous || c.created_at > previous.created_at || (c.created_at === previous.created_at && c.id > previous.id)) result.set(key, c);
        }
        return result;
    }
    function storyThreads(records, ids) {
        if (!Array.isArray(records)) return [];
        const stories=[];
        for (const thread of records.slice(0,64)) {
            if (!thread || !Array.isArray(thread.segments) || thread.segments.length<1 || thread.segments.length>3) continue;
            const segments=thread.segments, validated=segments.map(s=>[...latestExchanges([s],ids).values()][0]);
            if (validated.some(s=>!s)) continue;
            const first=validated[0], key=pairKey(first.messages[0].resident_id,first.messages[1].resident_id);
            if (validated.some((s,i)=>pairKey(s.messages[0].resident_id,s.messages[1].resident_id)!==key || (i && s.created_at<validated[i-1].created_at))) continue;
            const last=validated[validated.length-1];
            if (thread.id!==first.id || thread.created_at!==last.created_at || thread.provider!==last.provider) continue;
            stories.push({...thread,segments:validated,messages:validated.flatMap(s=>s.messages)});
        }
        return stories;
    }
    const sourceLabel = c => globalThis.SparksAtmosphere?.savedLabel(c) ?? `${c.created_at.slice(0,10) === new Date().toISOString().slice(0,10) ? 'Saved today' : 'From the village journal'} · ${c.provider === 'openai' ? 'Saved AI-generated dialogue' : 'Saved scripted dialogue'} · ${c.created_at.replace('T', ' ').replace('Z', ' UTC')}`;
    function bulletinPosts(records,ids) {
        const visible=new Set(ids),posts=new Map();
        if(!Array.isArray(records))return posts;
        for(const p of records.slice(0,24)){
            if(!p||!validId(p.id)||!validId(p.resident_id)||!visible.has(p.resident_id)||typeof p.name!=='string'||!p.name||Array.from(p.name).length>64||typeof p.created_at!=='string'||!Number.isFinite(Date.parse(p.created_at)))continue;
            const previous=posts.get(p.resident_id);
            if(!previous||p.created_at>previous.created_at||p.created_at===previous.created_at&&p.id>previous.id)posts.set(p.resident_id,p);
        }
        return posts;
    }
    function replayCooldown(settings) {
        if (settings === undefined) return 60; // Older read-only endpoint compatibility.
        const value = settings?.replay_cooldown_seconds;
        if (!Number.isInteger(value) || value < 0 || value > 3600) throw new Error('Replay configuration is invalid.');
        return value;
    }
    function sleepConfiguration(value) {
        if(value===undefined)return null;
        if(!value||typeof value.enabled!=='boolean'||typeof value.sleeping!=='boolean'||value.time_zone!=='America/New_York'
            ||![value.start_hour,value.end_hour].every(h=>Number.isInteger(h)&&h>=0&&h<=23)
            ||value.start_hour===value.end_hour||!Number.isSafeInteger(value.server_time)||value.server_time<0)throw new Error('Sleep configuration is invalid.');
        return Object.freeze({...value});
    }
    function sleepingAt(config,now) {
        if(!config?.enabled)return false;
        try {
            const hour=Number(new Intl.DateTimeFormat('en-US',{timeZone:config.time_zone,hourCycle:'h23',hour:'2-digit'}).formatToParts(new Date(now)).find(p=>p.type==='hour').value);
            return config.start_hour<config.end_hour?hour>=config.start_hour&&hour<config.end_hour:hour>=config.start_hour||hour<config.end_hour;
        }catch{return config.sleeping;}
    }
    function replayHistory(storage) {
        // Public conversation IDs only. Credentials never enter browser storage.
        const key = 'sparks_public_replays_v1', maximum = 1024;
        const valid = id => typeof id === 'string' && /^[a-zA-Z0-9:._-]{1,64}$/.test(id);
        let ids = new Set(), lastAt = 0;
        try {
            const raw = storage?.getItem(key);
            if (raw && raw.length <= 70000) {
                const saved = JSON.parse(raw);
                if (Array.isArray(saved.ids) && saved.ids.length <= maximum && saved.ids.every(valid) && Number.isSafeInteger(saved.lastAt) && saved.lastAt >= 0) { ids = new Set(saved.ids); lastAt = saved.lastAt; }
            }
        } catch { /* Storage denied: retain the same bounded in-memory policy. */ }
        return Object.freeze({
            canPlay(id, now, cooldown) { return valid(id) && !ids.has(id) && ids.size < maximum && (!lastAt || now - lastAt >= cooldown * 1000); },
            record(id, now) {
                if (!valid(id) || ids.size >= maximum) return;
                ids.add(id); lastAt = now;
                try { storage?.setItem(key, JSON.stringify({ids:[...ids], lastAt})); } catch { /* Local fallback survives refresh actions in this page. */ }
            }
        });
    }
    return Object.freeze({validId, pairKey, profileUrl, bindProfileLink, latestExchanges, storyThreads, bulletinPosts, sourceLabel, replayCooldown, replayHistory,sleepConfiguration,sleepingAt});
})();
