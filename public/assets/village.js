'use strict';
(() => {
    const $ = (id) => document.getElementById(id);
    const colors = new Set(['mint', 'lavender', 'peach', 'sky', 'gold']);
    const antennas = new Set(['orb', 'sprout', 'bolt']);
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const narrow = matchMedia('(max-width: 650px)');
    const commons = globalThis.SparksCommons;
    const setProfileLink = commons.bindProfileLink($('profile-life-link'), $('resident-profile'));
    let homes = [], exchanges = new Map(), activeMeeting = null, commonsVersion = 0;
    const lastPlayed = new Map();
    let replayCooldown = 60;
    let sceneWidth = 1000, sceneHeight = 460, walkingMin = 68, walkingMax = 84;
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(entries => {
        const changed = sceneWidth !== entries[0].contentRect.width;
        sceneWidth = entries[0].contentRect.width; sceneHeight = entries[0].contentRect.height;
        if (changed && sceneWidth > 0 && walkers.length) renderHomes();
    }).observe($('landscape'));
    let csrf = '', page = Number(new URL(location.href).searchParams.get('page')) || 0;
    let lastPage = 0, population = [], walkers = [], paused = reduced.matches;
    let frame = 0, lastTick = 0, pending = null, busy = false, loading = false;
    let selectedButton = null;
    // Read bearer credentials only from the fragment; remove before any network request.
    function takePrivateFragment() {
        const token = new URLSearchParams(location.hash.slice(1)).get('revisit');
        if (token !== null) history.replaceState(null, '', location.pathname + location.search);
        return token;
    }
    let revisitToken = takePrivateFragment();
    const status = (message) => { $('form-status').textContent = message; };
    function robot(resident) {
        const element = document.createElement('span');
        element.className = `robot color-${colors.has(resident.color) ? resident.color : 'mint'} antenna-${antennas.has(resident.antenna) ? resident.antenna : 'orb'}`;
        element.setAttribute('aria-hidden', 'true');
        for (const part of ['antenna', 'ears', 'face', 'body', 'feet']) {
            const bit = document.createElement('span');
            bit.className = `robot-${part}`;
            element.append(bit);
        }
        return element;
    }
    async function api(endpoint, body) {
        const response = await fetch(`api/${endpoint}.php`, {
            credentials: 'same-origin', cache: 'no-store',
            ...(body ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Sparks-CSRF': csrf}, body: JSON.stringify(body)} : {})
        });
        let data;
        try { data = await response.json(); } catch { throw new Error('The village is resting. Please try again later.'); }
        if (!response.ok) throw new Error(data.error || 'The village could not complete that request.');
        return data;
    }
    function showProfile(resident, source = null, isPrivate = false) {
        stopPlayback();
        selectedButton = source;
        $('profile-name').textContent = resident.name;
        $('profile-robot').replaceChildren(robot(resident));
        $('profile-private').hidden = !isPrivate;
        setProfileLink(resident);
        const details = $('profile-details');
        details.replaceChildren();
        const date = new Date(resident.created_at);
        for (const [label, value] of Object.entries({Personality: resident.personality, Color: resident.color, Antenna: resident.antenna, 'Created (UTC)': Number.isNaN(date.valueOf()) ? resident.created_at : date.toLocaleString(undefined, {timeZone: 'UTC'}), 'Permanent ID': resident.id})) {
            const term = document.createElement('dt'), description = document.createElement('dd');
            term.textContent = label; description.textContent = value; details.append(term, description);
        }
        $('resident-profile').showModal();
    }
    $('close-profile').addEventListener('click', () => $('resident-profile').close());
    $('resident-profile').addEventListener('close', () => { if (selectedButton?.isConnected) selectedButton.focus(); schedule(); });
    async function openPrivateRevisit() {
        if (!csrf || revisitToken === null) return;
        const token = revisitToken; revisitToken = null;
        try {
            const data = await api('revisit', {token});
            if ($('resident-profile').open) $('resident-profile').close();
            showProfile(data.resident, null, true);
        } catch (error) { status(error.message); }
    }
    addEventListener('hashchange', () => {
        const token = takePrivateFragment();
        if (token !== null) { revisitToken = token; openPrivateRevisit(); }
    });
    function render() {
        stopPlayback();
        sceneWidth = $('landscape').clientWidth || (narrow.matches ? 320 : 1000);
        sceneHeight = $('landscape').clientHeight || (narrow.matches ? 430 : 460);
        cancelAnimationFrame(frame);
        $('resident-directory').replaceChildren();
        $('robot-layer').replaceChildren();
        walkers = [];
        $('scene-empty').hidden = population.length > 0;
        population.forEach((resident, index) => {
            const item = document.createElement('li'), button = document.createElement('button');
            button.type = 'button'; button.className = 'directory-resident';
            button.append(robot(resident));
            const caption = document.createElement('span'), name = document.createElement('strong'), detail = document.createElement('span');
            name.textContent = resident.name; detail.textContent = `${resident.personality} · ${resident.color}`;
            caption.append(name, detail); button.append(caption);
            button.addEventListener('click', () => showProfile(resident, button));
            item.append(button); $('resident-directory').append(item);
            // The directory keeps the full page. The landscape stays calm on small screens.
            if (index >= (narrow.matches ? 4 : 12)) return;
            const actor = document.createElement('button');
            actor.type = 'button'; actor.className = 'village-resident'; actor.tabIndex = -1;
            actor.setAttribute('aria-label', `Meet ${resident.name}`);
            actor.append(robot(resident));
            const label = document.createElement('span'); label.className = 'robot-name'; label.textContent = resident.name;
            const bubble = document.createElement('span'); bubble.className = 'robot-bubble'; bubble.setAttribute('aria-hidden', 'true');
            actor.append(label, bubble); actor.addEventListener('click', () => showProfile(resident, button));
            $('robot-layer').append(actor);
            const columns = narrow.matches ? 4 : 6;
            const walker = {resident, element: actor, bubble, x: 13 + (index % columns) * (narrow.matches ? 23 : 14.5), y: 70 + Math.floor(index / columns) * 7, angle: Math.random() * Math.PI * 2, stop: performance.now() + Math.random() * 2500, ambientUntil: 0, hover: false};
            actor.addEventListener('pointerenter', () => { walker.hover = true; });
            actor.addEventListener('pointerleave', () => { walker.hover = false; });
            walkers.push(walker); place(walker);
        });
        renderHomes();
        frame = 0; lastTick = 0; schedule();
    }
    function renderHomes() {
        $('home-layer').replaceChildren();
        const visible = new Map(walkers.map(w => [w.resident.id, w.resident]));
        for (const home of homes) {
            const resident = visible.get(home.resident_id);
            if (!resident || !home.plot) continue;
            const link = document.createElement('a'), yard = document.createElement('span'), name = document.createElement('span');
            link.className = `commons-home color-${colors.has(resident.color) ? resident.color : 'mint'}`;
            link.href = commons.profileUrl(resident.id); link.setAttribute('aria-label', `Visit ${resident.name}’s saved home and profile`);
            yard.className = 'saved-yard'; yard.setAttribute('aria-hidden', 'true');
            const glyphs = {tree:'🌳',flowers:'🌼',bush:'🌿',path:'·',decoration:'✧',pet:'🐈',house:''};
            const objects = Array.isArray(home.objects) ? home.objects.slice(0, 12) : [{kind:'house',x:home.plot.x+1,y:home.plot.y+1}];
            for (const object of objects) {
                if (!Object.hasOwn(glyphs, object.kind) || !Number.isInteger(object.x) || !Number.isInteger(object.y)) continue;
                const x = object.x-home.plot.x, y = object.y-home.plot.y;
                if (x<0||x>3||y<0||y>3) continue;
                const bit = document.createElement('span'); bit.className = `yard-object yard-${object.kind}`;
                bit.style.left = `${(x+.5)*25}%`; bit.style.top = `${(y+.5)*25}%`;
                if (object.kind==='house') { const shape=document.createElement('span'); shape.className='cottage-shape'; bit.append(shape); }
                else bit.textContent = glyphs[object.kind];
                yard.append(bit);
            }
            name.className = 'cottage-name'; name.textContent = resident.name;
            link.append(yard, name); $('home-layer').append(link);
        }
        const count = $('home-layer').children.length;
        const columns = Math.max(1, Math.min(count || 1, narrow.matches ? 2 : Math.max(2, Math.min(6, Math.floor(sceneWidth/160)))));
        $('home-layer').style.gridTemplateColumns = `repeat(${columns},minmax(0,1fr))`;
        const rows = Math.ceil(count/columns), bottom = rows ? 110+rows*136+Math.max(0,rows-1)*14 : 180;
        const walkerRows = Math.ceil(walkers.length/(narrow.matches?4:6));
        sceneHeight = Math.max(narrow.matches?430:460,bottom+105+Math.max(0,walkerRows-1)*90);
        $('landscape').style.height = `${sceneHeight}px`;
        walkingMin = Math.max(60,(bottom+45)/sceneHeight*100); walkingMax = (sceneHeight-55)/sceneHeight*100;
        walkers.forEach((walker,index) => { walker.y=walkingMin+Math.floor(index/(narrow.matches?4:6))*90/sceneHeight*100; place(walker); });
    }
    function renderRecordings() {
        const panel = $('saved-dialogue'); panel.replaceChildren();
        const recent = [...exchanges.values()].sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 6);
        for (const c of recent) {
            const article = document.createElement('article'), source = document.createElement('p');
            source.className = 'field-help'; source.textContent = commons.sourceLabel(c); article.append(source);
            for (const message of c.messages) {
                const line = document.createElement('p'), name = document.createElement('strong'), text = document.createElement('span');
                name.textContent = `${message.name}: `; text.textContent = message.text; line.append(name, text); article.append(line);
            }
            panel.append(article);
        }
        if (!recent.length) { const p = document.createElement('p'); p.className = 'field-help'; p.textContent = 'No recent saved exchange for these neighbors is available. They can enjoy a little quiet; more history is in the village journal.'; panel.append(p); }
    }
    async function refreshCommons() {
        const version = ++commonsVersion, ids = walkers.map(w => w.resident.id);
        homes = []; exchanges = new Map(); renderHomes(); renderRecordings();
        if (!ids.length) return;
        try {
            const response = await fetch(`api/commons.php?ids=${encodeURIComponent(ids.join(','))}`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Saved village life unavailable.');
            const data = await response.json(); if (version !== commonsVersion) return;
            replayCooldown = commons.replayCooldown(data.settings);
            homes = Array.isArray(data.homes) ? data.homes.slice(0, 24) : [];
            exchanges = commons.latestExchanges(Array.isArray(data.conversations) ? data.conversations : [], ids);
            lastPlayed.clear(); renderHomes(); renderRecordings();
        } catch {
            if (version === commonsVersion) $('saved-dialogue').textContent = 'Saved conversations are resting. You can still browse resident profiles and the village journal.';
        }
    }
    function stopPlayback() {
        activeMeeting = null;
        walkers.forEach(w => { w.bubble.textContent = ''; });
    }
    function advancePlayback(now) {
        if (!activeMeeting) return;
        const {a, b, exchange, started} = activeMeeting, message = commons.playbackTurn(exchange, now - started);
        if (message && activeMeeting.message === message) return;
        a.bubble.textContent = ''; b.bubble.textContent = '';
        if (!message) { activeMeeting = null; return; }
        activeMeeting.message = message;
        const speaker = message.resident_id === a.resident.id ? a : b;
        const source = document.createElement('span'), text = document.createElement('span');
        source.className = 'bubble-source'; source.textContent = commons.sourceLabel(exchange); text.textContent = message.text;
        speaker.bubble.replaceChildren(source, text);
    }
    function place(walker) {
        walker.element.style.left = `${walker.x}%`; walker.element.style.top = `${walker.y}%`;
        walker.bubble.style.transform = walker.x < 25 ? 'translateX(-10%)' : walker.x > 75 ? 'translateX(-90%)' : 'translateX(-50%)';
    }
    function schedule() {
        if (!frame && !document.hidden && !paused && !$('resident-profile').open && walkers.length) frame = requestAnimationFrame(tick);
    }
    function tick(now) {
        frame = 0;
        if (document.hidden || paused || $('resident-profile').open) { lastTick = now; return; }
        schedule();
        if (now - lastTick < 80) return;
        const delta = lastTick ? Math.min((now - lastTick) / 1000, .2) : 0;
        lastTick = now;
        advancePlayback(now);
        for (const walker of walkers) {
            if (walker.ambientUntil && now > walker.ambientUntil) { walker.bubble.textContent = ''; walker.ambientUntil = 0; }
            if (now < walker.stop || walker.hover) continue;
            if (Math.random() < .014) { walker.stop = now + 900 + Math.random() * 1700; walker.angle += Math.random() * 2 - 1; continue; }
            const speed = walker.resident.personality === 'playful' ? 1.5 : .9;
            walker.x += Math.cos(walker.angle) * delta * speed;
            walker.y += Math.sin(walker.angle) * delta * speed;
            if (walker.x < 8 || walker.x > 90) walker.angle = Math.PI - walker.angle;
            if (walker.y < walkingMin || walker.y > walkingMax) walker.angle = -walker.angle;
            walker.x = Math.max(8, Math.min(90, walker.x)); walker.y = Math.max(walkingMin, Math.min(walkingMax, walker.y));
            place(walker);
        }
        for (let i = 0; !activeMeeting && i < walkers.length; i++) {
            const a = walkers[i];
            if (now < a.stop || a.hover) continue;
            for (let j = i + 1; j < walkers.length; j++) {
                const b = walkers[j];
                if (now < b.stop || b.hover || Math.hypot((a.x - b.x) * sceneWidth / 100, (a.y - b.y) * sceneHeight / 100) > 80) continue;
                const exchange = exchanges.get(commons.pairKey(a.resident.id, b.resident.id));
                const canReplay = exchange && (!lastPlayed.has(exchange.id) || now - lastPlayed.get(exchange.id) >= replayCooldown*1000);
                if (!canReplay) continue; // Ordinary wandering/idle pauses; no invented meeting.
                const duration = exchange.messages.reduce((sum, m) => sum + commons.turnDuration(m), 0);
                for (const friend of [a, b]) { friend.stop = now + duration + 1500; friend.angle += Math.PI / 2; }
                stopPlayback(); lastPlayed.set(exchange.id, now);
                activeMeeting = {a, b, exchange, started:now}; advancePlayback(now);
                break;
            }
        }
    }
    function motionLabel() {
        $('motion-toggle').textContent = paused ? 'Resume wandering' : 'Pause wandering';
        $('motion-toggle').setAttribute('aria-pressed', String(paused));
        if (paused) stopPlayback();
        schedule();
    }
    $('motion-toggle').addEventListener('click', () => { paused = !paused; motionLabel(); });
    reduced.addEventListener('change', () => { paused = reduced.matches; motionLabel(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPlayback(); lastTick = 0; schedule(); });
    narrow.addEventListener('change', () => { render(); refreshCommons(); });
    $('chicken').addEventListener('click', () => { $('chicken-message').textContent = 'Protection protocol active. The chicken is safe, mildly suspicious, and expecting snacks. 🐓'; });
    function previewRobot() {
        $('robot-preview').replaceChildren(robot({color: $('robot-color').value, antenna: $('robot-antenna').value}));
    }
    $('robot-color').addEventListener('change', previewRobot);
    $('robot-antenna').addEventListener('change', previewRobot);
    function setPrivateLink(token) {
        const link = new URL('village.php', location.href);
        link.hash = new URLSearchParams({revisit: token}).toString();
        $('private-link').value = link.href; $('private-link-panel').hidden = false;
    }
    $('copy-link').addEventListener('click', async () => {
        try { await navigator.clipboard.writeText($('private-link').value); status('Private link copied. Keep it somewhere safe.'); }
        catch { $('private-link').focus(); $('private-link').select(); status('Select and copy the private link above.'); }
    });
    $('hide-link').addEventListener('click', () => { $('private-link').value = ''; $('private-link-panel').hidden = true; pending = null; $('create-submit').focus(); });
    // Listing is explicit: once on arrival, after creation, or on a visitor's request.
    async function refresh(requestedPage = page) {
        if (loading) return;
        loading = true; $('refresh-residents').disabled = true;
        try {
            const response = await fetch(`api/residents.php?page=${requestedPage}`, {credentials:'same-origin', cache:'no-store'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'The village is resting.');
            csrf = data.csrf; population = data.residents; page = data.page; lastPage = data.lastPage;
            $('population').textContent = `${data.total} residents call this village home. Showing ${population.length} at a time.`;
            $('page-status').textContent = `Page ${page + 1} of ${lastPage + 1}`;
            $('previous-page').href = `?page=${Math.max(0, page - 1)}#residents`;
            $('next-page').href = `?page=${Math.min(lastPage, page + 1)}#residents`;
            $('creation-fields').disabled = false; $('motion-toggle').disabled = false;
            $('directory-status').textContent = population.length ? 'Choose a resident to open their profile.' : 'No residents yet. Welcome the first little spark.';
            render(); motionLabel();
            await refreshCommons();
            return data;
        } finally { loading = false; $('refresh-residents').disabled = false; }
    }
    $('refresh-residents').addEventListener('click', () => refresh().catch(error => { $('directory-status').textContent = error.message; }));
    for (const [id, direction] of [['previous-page', -1], ['next-page', 1]]) {
        $(id).addEventListener('click', event => {
            event.preventDefault(); refresh(Math.max(0, Math.min(lastPage, page + direction))).catch(error => { $('directory-status').textContent = error.message; });
        });
    }
    $('create-form').addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        if (!$('private-link-panel').hidden) { status('Save and hide the previous private link before welcoming another robot.'); $('private-link').focus(); return; }
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        const signature = JSON.stringify(fields);
        if (!pending || pending.signature !== signature) {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            pending = {signature, payload: {...fields, requestId: [...bytes].map(b => b.toString(16).padStart(2, '0')).join('')}};
        }
        busy = true; $('create-submit').disabled = true; status('Making a little room in the village…');
        try {
            const data = await api('create', pending.payload);
            setPrivateLink(data.token);
            status(`${data.resident.name} has a permanent home here. Save the private revisit link below.`);
            // Put the newcomer in view, even when the current public page is full.
            population = [data.resident, ...population.filter(r => r.id !== data.resident.id)].slice(0, 40);
            render();
            await refreshCommons();
            const actor = walkers.find(w => w.resident.id === data.resident.id);
            if (!paused) { actor.element.classList.add('new-spark'); actor.bubble.textContent = 'Ambient welcome · Welcome home! ♡'; }
            actor.ambientUntil = performance.now() + 3200; actor.stop = performance.now() + 6500;
            $('private-link').focus(); $('private-link').select();
            $('directory-status').textContent = 'Your new neighbor is in view. Refresh to browse the saved population.';
            $('population').textContent = 'Your new neighbor is saved. Refresh residents for the latest village count.';
        } catch (error) { status(error.message + ' You can retry; the same request will not create a duplicate.'); }
        finally { busy = false; $('create-submit').disabled = false; }
    });
    previewRobot(); motionLabel();
    refresh().then(async () => {
        status('The village is ready. Take a moment to choose your little spark.');
        await openPrivateRevisit();
    }).catch(() => { status('The village is resting. Use Refresh residents to try again. Any existing profiles below are still available.'); $('scene-empty').textContent = 'The village is resting. Please try again soon.'; });
})();
