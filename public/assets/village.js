'use strict';
(() => {
    const $ = (id) => document.getElementById(id);
    const colors = new Set(['mint','lavender','peach','sky','gold','sage','coral','amber','violet','teal','rose','magenta','emerald','forest','copper','terracotta','silver','slate','cream','warm-white','indigo','deep-blue','berry','burgundy']);
    const antennas = new Set(['orb', 'sprout', 'bolt']);
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const narrow = matchMedia('(max-width: 650px)');
    const commons = globalThis.SparksCommons;
    const meadow = globalThis.SparksMeadow;
    const setProfileLink = commons.bindProfileLink($('profile-life-link'), $('resident-profile'));
    $('profile-idea-link')?.addEventListener('click',()=>{if($('resident-profile').open)$('resident-profile').close();});
    let homes = [], exchanges = new Map(), activeMeeting = null, commonsVersion = 0;
    let replayStorage;
    try { replayStorage = sessionStorage; } catch { /* Storage may be disabled. */ }
    const played = commons.replayHistory(replayStorage);
    let replayCooldown = 60;
    let sleepPolicy=null,sleepReceivedAt=0,sleeping=false;
    let sceneWidth = 1000, sceneHeight = 520, sceneGeometry;
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(entries => {
        const changed = sceneWidth !== entries[0].contentRect.width;
        sceneWidth = entries[0].contentRect.width; sceneHeight = entries[0].contentRect.height;
        if (changed && sceneWidth > 0 && walkers.length) renderHomes();
    }).observe($('landscape'));
    let csrf = '', page = Number(new URL(location.href).searchParams.get('page')) || 0;
    let lastPage = 0, population = [], walkers = [], paused = reduced.matches;
    let neighborhood = 0;
    let frame = 0, lastTick = 0, pending = null, busy = false, loading = false;
    let selectedButton = null, ownerEditor = null;
    // Read bearer credentials only from the fragment; remove before any network request.
    function takePrivateFragment() {
        const token = new URLSearchParams(location.hash.slice(1)).get('revisit');
        if (token !== null) history.replaceState(null, '', location.pathname + location.search);
        return token;
    }
    let revisitToken = takePrivateFragment();
    const status = (message) => { $('form-status').textContent = message; };
    const swatches = [...($('color-swatches')?.querySelectorAll?.('button') || [])];
    const markColor = () => swatches.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.color === $('robot-color').value)));
    swatches.forEach(button => button.addEventListener('click', () => { $('robot-color').value = button.dataset.color; markColor(); previewRobot(); }));
    $('robot-color').addEventListener('change', markColor);
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
        ownerEditor?.remove(); ownerEditor = null;
        selectedButton = source;
        $('profile-name').textContent = resident.name;
        $('profile-robot').replaceChildren(robot(resident));
        $('profile-private').hidden = !isPrivate;
        setProfileLink(resident);
        const ideaLink=$('profile-idea-link');if(ideaLink)ideaLink.href='/owner.php?resident='+resident.id;
        const details = $('profile-details');
        details.replaceChildren();
        for (const [label, value] of Object.entries({Personality: resident.description || resident.personality, Color: resident.color, Antenna: resident.antenna, Created: resident.created_at, 'Permanent ID': resident.id})) {
            const term = document.createElement('dt'), description = document.createElement('dd');
            term.textContent = label;
            if(label==='Created'){
                const time=document.createElement('time');time.setAttribute('datetime',value);time.textContent=globalThis.SparksAtmosphere?.format(value)??value;description.append(time);
            }else description.textContent = value;
            details.append(term, description);
        }
        $('resident-profile').showModal();
        syncSceneMotion();
    }
    $('close-profile').addEventListener('click', () => $('resident-profile').close());
    $('resident-profile').addEventListener('close', () => { if (!$('resident-profile').open) { ownerEditor?.remove(); ownerEditor = null; } if (selectedButton?.isConnected) selectedButton.focus(); syncSceneMotion(); schedule(); });
    function editProfile(resident, token) {
        const template = $('identity-editor-template');
        if (!template || !Number.isInteger(resident.identity_version)) return;
        const form = template.content.firstElementChild.cloneNode(true);
        const description = form.querySelector('textarea'), color = form.querySelector('select');
        const feedback = form.querySelector('[role="status"]'), submit = form.querySelector('button');
        description.value = resident.description; color.value = resident.color;
        let revision = resident.identity_version, attempt = null, saving = false;
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (saving) return;
            const fields = {description: description.value, color: color.value, expectedRevision: revision};
            const signature = JSON.stringify(fields);
            if (!attempt || attempt.signature !== signature) attempt = {signature, requestId: [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('')};
            saving = true; submit.disabled = true; feedback.textContent = 'Saving your character notes…';
            try {
                const data = await api('identity', {...fields, token, requestId: attempt.requestId});
                resident = data.resident; revision = resident.identity_version; attempt = null;
                population = population.map(r => r.id === resident.id ? resident : r); render();
                if (!form.isConnected) return;
                // The editor owns its token only in this closure, never in DOM/storage.
                $('profile-robot').replaceChildren(robot(resident));
                const values = $('profile-details').querySelectorAll('dd');
                values[0].textContent = resident.description; values[1].textContent = resident.color;
                feedback.textContent = 'Saved. These character notes do not change recorded history.';
            } catch (error) { feedback.textContent = error.message; }
            finally { saving = false; submit.disabled = false; }
        });
        ownerEditor = form; $('resident-profile').append(form);
    }
    async function openPrivateRevisit() {
        if (!csrf || revisitToken === null) return;
        const token = revisitToken; revisitToken = null;
        try {
            const data = await api('revisit', {token});
            if ($('resident-profile').open) $('resident-profile').close();
            showProfile(data.resident, null, true);
            editProfile(data.resident, token);
        } catch (error) { status(error.message); }
    }
    addEventListener('hashchange', () => {
        const token = takePrivateFragment();
        if (token !== null) { revisitToken = token; openPrivateRevisit(); }
    });
    function render() {
        stopPlayback();
        const previousWalkers = new Map(walkers.map(w=>[w.resident.id,w]));
        sceneWidth = $('landscape').clientWidth || (narrow.matches ? 320 : 1000);
        sceneHeight = $('landscape').clientHeight || (narrow.matches ? 430 : 460);
        cancelAnimationFrame(frame);
        $('resident-directory').replaceChildren();
        $('robot-layer').replaceChildren();
        walkers = [];
        const limit=meadow.capacity(narrow.matches), groups=Math.max(1,Math.ceil(population.length/limit));
        neighborhood=Math.min(neighborhood,groups-1);
        const first=neighborhood*limit,last=Math.min(first+limit,population.length);
        $('neighborhood-controls').hidden=groups===1;
        $('neighborhood-status').textContent=!population.length?'The commons is waiting for its first neighbor.':groups===1?`All ${population.length} neighbors on this page are in the commons.`:`Neighbors ${first+1}–${last} of ${population.length} on this directory page · Neighborhood ${neighborhood+1} of ${groups}`;
        $('scene-empty').hidden = population.length > 0;
        population.forEach((resident, index) => {
            const item = document.createElement('li'), button = document.createElement('button');
            button.type = 'button'; button.className = 'directory-resident';
            button.append(robot(resident));
            const caption = document.createElement('span'), name = document.createElement('strong'), detail = document.createElement('span');
            name.textContent = resident.name; detail.textContent = `${resident.description ? 'Owner-written personality' : resident.personality} · ${resident.color}`;
            caption.append(name, detail); button.append(caption);
            button.addEventListener('click', () => showProfile(resident, button));
            item.append(button); $('resident-directory').append(item);
            // The directory keeps the full page. The landscape stays calm on small screens.
            if (index < first || index >= last) return;
            const actor = document.createElement('button');
            actor.type = 'button'; actor.className = 'village-resident'; actor.tabIndex = -1;
            actor.setAttribute('aria-label', `Meet ${resident.name}`);
            actor.append(robot(resident));
            const label = document.createElement('span'); label.className = 'robot-name'; label.textContent = resident.name;
            const bubble = document.createElement('span'); bubble.className = 'robot-bubble'; bubble.setAttribute('aria-hidden', 'true');
            const sleepBadge=document.createElement('span');sleepBadge.className='sleep-badge';sleepBadge.textContent='💤';sleepBadge.setAttribute('aria-hidden','true');
            actor.append(label, sleepBadge, bubble); actor.addEventListener('click', () => showProfile(resident, button));
            $('robot-layer').append(actor);
            const old=previousWalkers.get(resident.id);
            const walker = {resident, element: actor, bubble,sleepBadge, x: old?.x??0, y: old?.y??0, target:old?.target, nextDecision:old?.nextDecision, stop: old?.stop??performance.now()+Math.random()*2500, ambientUntil: 0, hover: false};
            actor.addEventListener('pointerenter', () => { walker.hover = true; });
            actor.addEventListener('pointerleave', () => { walker.hover = false; });
            walkers.push(walker); place(walker);
        });
        renderHomes();
        frame = 0; lastTick = 0;syncSleep(); schedule();
    }
    function renderHomes() {
        $('home-layer').replaceChildren();
        const visible = new Map(walkers.map(w => [w.resident.id, w.resident]));
        sceneGeometry=meadow.layout(sceneWidth,homes.filter(home=>visible.has(home.resident_id)&&home.plot),narrow.matches,walkers.length);
        $('home-layer').hidden=sceneGeometry.compact;
        $('landscape').dataset.layout=sceneGeometry.compact?'compact':'neighborhood';
        if($('compact-meadow-note'))$('compact-meadow-note').hidden=!sceneGeometry.compact;
        for (const placement of sceneGeometry.plots) {
            const home=placement.home;
            const resident = visible.get(home.resident_id);
            if (!resident || !home.plot) continue;
            const link = document.createElement('a'), yard = document.createElement('span'), name = document.createElement('span');
            link.className = `commons-home color-${colors.has(resident.color) ? resident.color : 'mint'}`;
            link.style.left=`${placement.left}px`;link.style.top=`${placement.top}px`;link.style.width=`${placement.width}px`;
            link.href = commons.profileUrl(resident.id); link.setAttribute('aria-label', `Visit ${resident.name}’s saved home and profile`);
            link.title=`${resident.name}’s saved home`;
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
        sceneHeight = sceneGeometry.height;
        $('landscape').style.height = `${sceneHeight}px`;
        $('landscape').style.setProperty('--commons-width',`${sceneWidth}px`);
        meadow.seed(walkers,sceneGeometry,performance.now(),Math.random,true);walkers.forEach(w=>{w.bubbleSize=null;place(w);});
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
    let checkedAt=null;
    function renderFreshness(){
        if(checkedAt)$('commons-freshness').textContent=`Saved life checked ${globalThis.SparksAtmosphere?.format(checkedAt)??checkedAt}. Refresh residents for updates. Each exchange plays once per tab session; quiet wandering is welcome.`;
    }
    document.addEventListener('sparks:timezone',()=>{
        renderRecordings();renderFreshness();
        if(activeMeeting)for(const walker of walkers){
            const source=walker.bubble.children[0];
            if(source){source.textContent=commons.sourceLabel(activeMeeting.exchange);walker.bubbleSize=null;place(walker);}
        }
    });
    async function refreshCommons() {
        const version = ++commonsVersion, ids = walkers.map(w => w.resident.id);
        homes = []; exchanges = new Map(); renderHomes(); renderRecordings();
        if (!ids.length) return;
        try {
            const response = await fetch(`api/commons.php?ids=${encodeURIComponent(ids.join(','))}`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Saved village life unavailable.');
            const data = await response.json(); if (version !== commonsVersion) return;
            replayCooldown = commons.replayCooldown(data.settings);
            globalThis.SparksAtmosphere?.setSharedWeather?.(data.settings?.weather??null);
            sleepPolicy=commons.sleepConfiguration(data.settings?.sleep);sleepReceivedAt=performance.now();syncSleep();
            homes = Array.isArray(data.homes) ? data.homes.slice(0, 24) : [];
            exchanges = commons.latestExchanges(Array.isArray(data.conversations) ? data.conversations : [], ids);
            renderHomes(); renderRecordings();
            checkedAt=new Date().toISOString();renderFreshness();
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
        a.bubbleSize=null; b.bubbleSize=null;
        if (!message) { activeMeeting = null; return; }
        activeMeeting.message = message;
        const speaker = message.resident_id === a.resident.id ? a : b;
        const source = document.createElement('span'), text = document.createElement('span');
        source.className = 'bubble-source'; source.textContent = commons.sourceLabel(exchange); text.textContent = message.text;
        speaker.bubble.replaceChildren(source, text);
    }
    function place(walker) {
        walker.element.style.left = `${walker.x}px`; walker.element.style.top = `${walker.y}px`;
        walker.element.style.zIndex=walker.bubble.textContent?'6':'';
        if(walker.bubble.textContent&&sceneGeometry){
            walker.bubbleSize??={width:walker.bubble.offsetWidth||(narrow.matches?180:210),height:walker.bubble.offsetHeight||160};
            const box=meadow.bubblePosition(walker,sceneGeometry,walker.bubbleSize.width,walker.bubbleSize.height);
            walker.bubble.style.left=`${box.left-walker.x+(walker.element.offsetWidth||64)/2}px`;
            walker.bubble.style.top=`${box.top-walker.y+(walker.element.offsetHeight||67)/2}px`;
        }
    }
    function schedule() {
        if (!frame && !sleeping && !document.hidden && !paused && !$('resident-profile').open && walkers.length) frame = requestAnimationFrame(tick);
    }
    function tick(now) {
        frame = 0;
        if (sleeping || document.hidden || paused || $('resident-profile').open) { lastTick = now; return; }
        schedule();
        if (now - lastTick < 80) return;
        const delta = lastTick ? Math.min((now - lastTick) / 1000, .2) : 0;
        lastTick = now;
        advancePlayback(now);
        meadow.step(walkers,sceneGeometry,delta*(globalThis.SparksAtmosphere?.weatherPace?.()??1),now,Math.random);
        for (const walker of walkers) {
            if (walker.ambientUntil && now > walker.ambientUntil) { walker.bubble.textContent = ''; walker.ambientUntil = 0; }
            place(walker);
        }
        for (let i = 0; !activeMeeting && i < walkers.length; i++) {
            const a = walkers[i];
            if (now < a.stop || a.hover) continue;
            for (let j = i + 1; j < walkers.length; j++) {
                const b = walkers[j];
                if (now < b.stop || b.hover || meadow.distance(a,b) > 90) continue;
                const exchange = exchanges.get(commons.pairKey(a.resident.id, b.resident.id));
                const canReplay = exchange && played.canPlay(exchange.id, Date.now(), replayCooldown);
                if (!canReplay) continue; // Ordinary wandering/idle pauses; no invented meeting.
                const duration = exchange.messages.reduce((sum, m) => sum + commons.turnDuration(m), 0);
                for (const friend of [a, b]) friend.stop = now + duration + 1500;
                meadow.separate(a,b,sceneGeometry,Math.random);
                stopPlayback(); played.record(exchange.id, Date.now());
                activeMeeting = {a, b, exchange, started:now}; advancePlayback(now);
                break;
            }
        }
    }
    function motionLabel() {
        $('motion-toggle').textContent = paused ? 'Resume wandering' : 'Pause wandering';
        $('motion-toggle').setAttribute('aria-pressed', String(paused));
        if (paused) stopPlayback();
        syncSceneMotion();
        schedule();
    }
    function syncSceneMotion(){globalThis.SparksAtmosphere?.setPaused(paused||document.hidden||$('resident-profile').open);}
    $('motion-toggle').addEventListener('click', () => { paused = !paused; motionLabel(); });
    reduced.addEventListener('change', () => { paused = reduced.matches; motionLabel(); });
    function syncSleep() {
        const next=commons.sleepingAt(sleepPolicy,sleepPolicy?sleepPolicy.server_time*1000+performance.now()-sleepReceivedAt:Date.now());
        if(next&&!sleeping){stopPlayback();if(frame)cancelAnimationFrame(frame);frame=0;lastTick=0;}
        sleeping=next;$('landscape').dataset.sleeping=String(sleeping);
        $('sleep-note').hidden=!sleeping;$('sleep-note').textContent=sleeping?'The Sparks are sleeping. Their profiles, homes and saved conversations are still here to explore.':'';
        for(const w of walkers){w.sleepBadge.hidden=!sleeping;w.element.dataset.sleeping=String(sleeping);w.element.setAttribute('aria-label',w.resident.name+(sleeping?' is sleeping.':'')+' Open resident profile');}
        if(!sleeping)schedule();
    }
    document.addEventListener('sparks:clock',syncSleep);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopPlayback();syncSleep(); syncSceneMotion(); lastTick = 0; schedule(); });
    narrow.addEventListener('change', () => {
        const before=walkers.map(w=>w.resident.id).join(',');render();
        // A width-only change does not refetch saved data for unchanged neighbors.
        if(before!==walkers.map(w=>w.resident.id).join(','))refreshCommons();
    });
    for(const [id,direction] of [['previous-neighborhood',-1],['next-neighborhood',1]]){
        $(id).addEventListener('click',()=>{
            const groups=Math.max(1,Math.ceil(population.length/meadow.capacity(narrow.matches)));
            neighborhood=(neighborhood+direction+groups)%groups;
            render();refreshCommons();
        });
    }
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
            if(page!==data.page)neighborhood=0;
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
            neighborhood=0;
            render();
            await refreshCommons();
            const actor = walkers.find(w => w.resident.id === data.resident.id);
            if (!paused) { actor.element.classList.add('new-spark'); if (!('identity_version' in data.resident)) actor.bubble.textContent = 'Ambient welcome · Welcome home! ♡'; }
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
