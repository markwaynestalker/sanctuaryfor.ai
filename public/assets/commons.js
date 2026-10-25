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
            if (!c || typeof c.id !== 'string' || !c.id || c.id.length > 64 || typeof c.created_at !== 'string' || !['openai', 'scripted'].includes(c.provider) || !Array.isArray(c.messages) || c.messages.length !== 2 || !Number.isFinite(Date.parse(c.created_at))) continue;
            const [a, b] = c.messages;
            if (!a || !b || a.resident_id === b.resident_id || ![a, b].every(m => visible.has(m.resident_id) && validId(m.resident_id) && typeof m.text === 'string' && Array.from(m.text).length > 0 && Array.from(m.text).length <= 160 && typeof m.name === 'string' && Array.from(m.name).length > 0 && Array.from(m.name).length <= 64)) continue;
            const key = pairKey(a.resident_id, b.resident_id), previous = result.get(key);
            if (!previous || c.created_at > previous.created_at || (c.created_at === previous.created_at && c.id > previous.id)) result.set(key, c);
        }
        return result;
    }
    const sourceLabel = c => `${c.created_at.slice(0,10) === new Date().toISOString().slice(0,10) ? 'Saved today' : 'From the village journal'} · ${c.provider === 'openai' ? 'Saved AI-generated dialogue' : 'Saved scripted dialogue'} · ${c.created_at.replace('T', ' ').replace('Z', ' UTC')}`;
    const turnDuration = message => Math.max(4500, Math.min(12000, Array.from(message.text).length * 70));
    function replayCooldown(settings) {
        if (settings === undefined) return 60; // Older read-only endpoint compatibility.
        const value = settings?.replay_cooldown_seconds;
        if (!Number.isInteger(value) || value < 0 || value > 3600) throw new Error('Replay configuration is invalid.');
        return value;
    }
    function playbackTurn(c, elapsed) {
        const first = turnDuration(c.messages[0]);
        return elapsed < 0 ? null : elapsed < first ? c.messages[0] : elapsed < first + turnDuration(c.messages[1]) ? c.messages[1] : null;
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
    return Object.freeze({validId, pairKey, profileUrl, bindProfileLink, latestExchanges, sourceLabel, turnDuration, playbackTurn, replayCooldown, replayHistory});
})();
