'use strict';
(() => {
    const $ = (id) => document.getElementById(id);
    const colors = new Set(['mint','lavender','peach','sky','gold','sage','coral','amber','violet','teal','rose','magenta','emerald','forest','copper','terracotta','silver','slate','cream','warm-white','indigo','deep-blue','berry','burgundy']);
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const narrow = matchMedia('(max-width: 650px)');
    const commons = globalThis.SparksCommons;
    const meadow = globalThis.SparksMeadow;
    const setProfileLink = commons.bindProfileLink($('profile-life-link'), $('resident-profile'));
    $('profile-idea-link')?.addEventListener('click',()=>{if($('resident-profile').open)$('resident-profile').close();});
    let homes = [], exchanges = new Map(), posts = new Map(), activeMeeting = null, gathering = null, commonsVersion = 0, dialogueOrigin = null;
    let replayStorage;
    try { replayStorage = sessionStorage; } catch { /* Storage may be disabled. */ }
    const played = commons.replayHistory(replayStorage);
    let replayCooldown = 60;
    const replayReadyAt=performance.now()+3000;
    let sleepPolicy=null,sleepReceivedAt=0,sleeping=false,commonsReady=false;
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
    let selectedButton = null, selectedResidentId = null, ownerEditor = null, creationAppearance = null;
    // Read bearer credentials only from the fragment; remove before any network request.
    function takePrivateFragment() {
        const token = new URLSearchParams(location.hash.slice(1)).get('revisit');
        if (token !== null) history.replaceState(null, '', location.pathname + location.search);
        return token;
    }
    let revisitToken = takePrivateFragment();
    const status = (message) => { $('form-status').textContent = message; };
    const swatches = [...($('color-swatches')?.querySelectorAll?.('button') || [])];
    const markColor = () => swatches.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.color === (creationAppearance?.value().primary_color || $('robot-color').value))));
    swatches.forEach(button => button.addEventListener('click', () => { $('robot-color').value = button.dataset.color; creationAppearance?.setColor(button.dataset.color); markColor(); previewRobot(); }));
    $('robot-color').addEventListener('change', () => {markColor();creationAppearance?.setColor($('robot-color').value);});
    const avatars=globalThis.SparkAvatars;
    function robot(resident) {return avatars.render(resident,'robot');}
    function returnFocus(source,id) {
        const usable=source?.isConnected&&!source.hidden&&(!source.getClientRects||source.getClientRects().length>0);
        const index=population.findIndex(r=>r.id===id),item=$('resident-directory').children[index];
        const fallback=item&&!item.hidden?item.children[0]:$('resident-search');
        (usable?source:fallback||$('refresh-residents')).focus();
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
        selectedResidentId = resident.id;
        $('profile-name').textContent = resident.name;
        $('profile-robot').replaceChildren(robot(resident));
        $('profile-private').hidden = !isPrivate;
        setProfileLink(resident);
        const ideaLink=$('profile-idea-link');if(ideaLink)ideaLink.href='/owner.php?resident='+resident.id;
        const details = $('profile-details');
        details.replaceChildren();
        for (const [label, value] of Object.entries({Personality: resident.description || resident.personality, Color: avatars.appearance(resident).primary_color, Antenna: avatars.appearance(resident).features.antenna || 'None', Created: resident.created_at, 'Permanent ID': resident.id, Form: avatars.registry.forms.find(f=>f.id===avatars.appearance(resident).form).name, 'Rest status': restLabel()})) {
            const term = document.createElement('dt'), description = document.createElement('dd');
            term.textContent = label;if(label==='Rest status')description.id='profile-rest-status';
            if(label==='Created'){
                const time=document.createElement('time');time.setAttribute('datetime',value);time.textContent=globalThis.SparksAtmosphere?.format(value)??value;description.append(time);
            }else description.textContent = value;
            details.append(term, description);
        }
        const recording=latestFor(resident.id),read=$('profile-conversation');read.hidden=!recording;
        read.onclick=()=>{if(recording){$('resident-profile').close();showExchange(recording,source,true);}};
        $('resident-profile').showModal();
        syncSceneMotion();
    }
    $('close-profile').addEventListener('click', () => $('resident-profile').close());
    $('resident-profile').addEventListener('close', () => { if (!$('resident-profile').open) { ownerEditor?.remove(); ownerEditor = null; } returnFocus(selectedButton,selectedResidentId); syncSceneMotion(); schedule(); });
    function editProfile(resident, token) {
        const template = $('identity-editor-template');
        if (!template || !Number.isInteger(resident.identity_version)) return;
        const form = template.content.firstElementChild.cloneNode(true);
        const description = form.querySelector('textarea'), color = form.querySelector('select');
        const feedback = form.querySelector('[role="status"]'), submit = form.querySelector('button');
        description.value = resident.description; color.value = resident.color;
        let revision = resident.identity_version, attempt = null, saving = false, appearanceSaving = false;
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (saving || appearanceSaving) return;
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
                values[0].textContent = resident.description; values[1].textContent = avatars.appearance(resident).primary_color;
                feedback.textContent = 'Saved. These character notes do not change recorded history.';
            } catch (error) { feedback.textContent = error.message; }
            finally { saving = false; submit.disabled = false; }
        });
        ownerEditor = document.createElement('div'); ownerEditor.append(form);
        if(Number.isInteger(resident.appearance_version)){
            color.parentElement?.querySelector('label[for="edit-color"]')?.setAttribute('hidden','');color.hidden=true;
            submit.textContent='Save personality';
            const picker=avatars.editor(resident),save=document.createElement('button'),message=document.createElement('p');
            save.type='button';save.className='small-button';save.textContent='Save appearance';message.setAttribute('role','status');
            let appearanceRevision=resident.appearance_version,appearanceAttempt=null;
            save.addEventListener('click',async()=>{
                if(save.disabled||saving||!picker.valid())return;
                const fields={appearance:picker.value(),expectedRevision:appearanceRevision},signature=JSON.stringify(fields);
                if(!appearanceAttempt||appearanceAttempt.signature!==signature)appearanceAttempt={signature,requestId:[...crypto.getRandomValues(new Uint8Array(16))].map(b=>b.toString(16).padStart(2,'0')).join('')};
                appearanceSaving=true;save.disabled=true;submit.disabled=true;message.textContent='Saving appearance…';
                try{const data=await api('appearance',{...fields,token,requestId:appearanceAttempt.requestId});resident=data.resident;appearanceRevision=resident.appearance_version;appearanceAttempt=null;population=population.map(r=>r.id===resident.id?resident:r);render();if(!picker.element.isConnected||!$('resident-profile').open)return;$('profile-robot').replaceChildren(robot(resident));const values=$('profile-details').querySelectorAll('dd'),look=avatars.appearance(resident);values[1].textContent=look.primary_color;values[2].textContent=look.features.antenna||'None';values[5].textContent=avatars.registry.forms.find(f=>f.id===look.form).name;message.textContent='Appearance saved. Your Spark’s identity and story stay the same.';}
                catch(error){message.textContent=error.message;}finally{appearanceSaving=false;save.disabled=false;submit.disabled=false;}
            });
            ownerEditor.append(picker.element,save,message);
        }
        $('resident-profile').append(ownerEditor);
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
    function filterDirectory() {
        const query=($('resident-search').value||'').trim().normalize('NFC').toLowerCase();
        let matches=0;
        population.forEach((resident,index)=>{
            const item=$('resident-directory').children[index];
            item.hidden=!resident.name.normalize('NFC').toLowerCase().includes(query);
            if(!item.hidden)matches++;
        });
        $('clear-resident-search').disabled=!$('resident-search').value;
        $('resident-search-status').textContent=query?`${matches} of ${population.length} names match on this page.`:`${population.length} names on this page.`;
    }
    $('resident-search').addEventListener('input',filterDirectory);
    $('clear-resident-search').addEventListener('click',()=>{
        $('resident-search').value='';filterDirectory();$('resident-search').focus();
    });
    async function locateResident(resident,source) {
        const index=population.findIndex(r=>r.id===resident.id);
        if(index<0||loading)return;
        const next=Math.floor(index/meadow.capacity(narrow.matches)),changed=next!==neighborhood;
        if(changed){neighborhood=next;render(true);}
        const origin=changed?$('resident-directory').children[index].children[1]:source;
        origin.focus();
        // A new neighborhood uses only the existing scoped commons read. Never
        // move a resident, replace a selected transcript or fetch on search input.
        if(changed)await refreshCommons();
        if(!origin.isConnected)return;
        $('directory-status').textContent=`${resident.name} is in neighborhood ${neighborhood+1}. `+(commonsReady?(sleeping?'Resting; their saved home is shown when available.':'Their character is in view.'):'Saved village state is unavailable; their profile remains available.');
        if(document.activeElement!==origin)return;
        const walker=walkers.find(w=>w.resident.id===resident.id);
        const home=[...$('home-layer').children].find(el=>el.dataset.resident===resident.id);
        const target=commonsReady?(sleeping?home:walker?.element):null;
        (target&&!target.hidden?target:origin).focus();
    }
    function render(keepDialogue=false) {
        const reading=keepDialogue&&activeMeeting?.exchange;
        if(!reading)stopPlayback();
        const previousWalkers = new Map(walkers.map(w=>[w.resident.id,w]));
        sceneWidth = $('landscape').clientWidth || (narrow.matches ? 320 : 1000);
        sceneHeight = $('landscape').clientHeight || (narrow.matches ? 430 : 460);
        cancelAnimationFrame(frame);
        $('resident-directory').replaceChildren();
        $('robot-layer').replaceChildren();
        $('conversation-layer').replaceChildren();
        walkers = [];
        const limit=meadow.capacity(narrow.matches), groups=Math.max(1,Math.ceil(population.length/limit));
        neighborhood=Math.min(neighborhood,groups-1);
        const first=neighborhood*limit,last=Math.min(first+limit,population.length);
        $('neighborhood-controls').hidden=groups===1;
        $('neighborhood-status').textContent=!population.length?'The commons is waiting for its first neighbor.':groups===1?`All ${population.length} neighbors on this page are shown here.`:`Neighbors ${first+1}–${last} of ${population.length} on this directory page · Neighborhood ${neighborhood+1} of ${groups}`;
        $('scene-empty').hidden = population.length > 0;
        population.forEach((resident, index) => {
            const item = document.createElement('li'), button = document.createElement('button');
            button.type = 'button'; button.className = 'directory-resident';
            button.append(robot(resident));
            const caption = document.createElement('span'), name = document.createElement('strong'), detail = document.createElement('span');
            name.textContent = resident.name; detail.textContent = `${avatars.registry.forms.find(f=>f.id===avatars.appearance(resident).form).name} · ${resident.description ? 'Owner-written personality' : resident.personality} · ${avatars.appearance(resident).primary_color}`;
            const rest=document.createElement('span');rest.className='directory-rest';rest.textContent='Resting';rest.hidden=!sleeping;
            caption.append(name, detail,rest); button.append(caption);
            button.addEventListener('click', () => showProfile(resident, button));
            const locate=document.createElement('button');locate.type='button';locate.className='small-button locate-resident';locate.textContent='Locate';locate.setAttribute('aria-label',`Locate ${resident.name} in the village`);
            locate.addEventListener('click',()=>locateResident(resident,locate));
            item.append(button,locate); $('resident-directory').append(item);
            // The directory keeps the full page. The landscape stays calm on small screens.
            if (index < first || index >= last) return;
            const actor = document.createElement('button');
            actor.type = 'button'; actor.className = 'village-resident';
            actor.setAttribute('aria-label', `Meet ${resident.name}`);
            actor.title=resident.name;actor.dataset.resident=resident.id;
            actor.append(robot(resident));
            const label = document.createElement('span'); label.className = 'robot-name'; label.textContent = resident.name;
            actor.append(label); actor.addEventListener('click', () => showProfile(resident, button));
            $('robot-layer').append(actor);
            const indicator=document.createElement('button');indicator.type='button';indicator.className='conversation-indicator';indicator.textContent='💬';indicator.hidden=true;
            indicator.addEventListener('click',()=>{const saved=latestFor(resident.id);if(saved)showExchange(saved,indicator,true);});$('conversation-layer').append(indicator);
            const old=previousWalkers.get(resident.id);
            const walker = {resident, element: actor, indicator, x: old?.x??0, y: old?.y??0, target:old?.target, motion:old?.motion, idleUntil:old?.idleUntil, walkUntil:old?.walkUntil, stop: Number.isFinite(old?.stop)?old.stop:performance.now()+Math.random()*2500, hover: false};
            actor.addEventListener('pointerenter', () => { walker.hover = true; });
            actor.addEventListener('pointerleave', () => { walker.hover = false; });
            for(const control of [actor,indicator]){control.addEventListener('focus',()=>{walker.hover=true;});control.addEventListener('blur',()=>{walker.hover=false;});}
            walkers.push(walker); place(walker);
        });
        $('resident-discovery').hidden=false;filterDirectory();
        if(reading){activeMeeting={exchange:reading,a:walkers.find(w=>w.resident.id===reading.messages[0].resident_id),b:walkers.find(w=>w.resident.id===reading.messages[1].resident_id)};for(const w of [activeMeeting.a,activeMeeting.b])if(w)w.stop=Infinity;}
        renderHomes();
        frame = 0; lastTick = 0;syncSleep(); schedule();
    }
    function renderHomes() {
        const focusedHome=[...$('home-layer').children].find(el=>el===document.activeElement);
        cancelGathering();
        $('home-layer').replaceChildren();$('garden-layer').replaceChildren();
        const visible = new Map(walkers.map(w => [w.resident.id, w.resident]));
        const actualHomes=homes.filter(home=>visible.has(home.resident_id)&&home.plot&&Array.isArray(home.objects)&&home.objects.some(o=>o.kind==='house'));
        sceneGeometry=meadow.layout(sceneWidth,actualHomes,narrow.matches,walkers.length,sleeping);
        $('home-layer').hidden=!commonsReady||!sleeping||!sceneGeometry.plots.length;
        $('landscape').dataset.layout=sceneGeometry.compact?'compact':'neighborhood';
        const glyphs={tree:'🌳',flowers:'🌼',bush:'🌿',path:'·',decoration:'✧',pet:'🐈'};
        for (const placement of sceneGeometry.gardens) {
            const home=placement.home,resident=visible.get(home.resident_id),garden=document.createElement('span');
            garden.className='saved-garden';garden.setAttribute('aria-hidden','true');
            garden.style.left=placement.left+'px';garden.style.top=placement.top+'px';garden.style.width=placement.width+'px';
            for(const object of home.objects.slice(0,12)){
                if(!Object.hasOwn(glyphs,object.kind)||!Number.isInteger(object.x)||!Number.isInteger(object.y))continue;
                const x=object.x-home.plot.x,y=object.y-home.plot.y;if(x<0||x>3||y<0||y>3)continue;
                const bit=document.createElement('span');bit.className='yard-object yard-'+object.kind;bit.textContent=glyphs[object.kind];
                if(object.kind==='decoration'&&object.variant==='lantern'){bit.classList.add('yard-lantern');bit.textContent='';}
                bit.style.left=((x+.5)*25)+'%';bit.style.top=((y+.5)*25)+'%';garden.append(bit);
            }
            $('garden-layer').append(garden);
            const link=document.createElement('a'),shape=document.createElement('span'),name=document.createElement('span'),note=document.createElement('span');
            link.className='commons-home';link.style.setProperty('--shell',avatars.palette(avatars.appearance(resident)).primary);
            link.style.left=placement.left+'px';link.style.top=placement.top+'px';link.style.width=placement.width+'px';
            link.href=commons.profileUrl(resident.id);link.setAttribute('aria-label',resident.name+' is resting at home. Open resident profile');link.title=resident.name+' · Resting at home';
            link.addEventListener('click',event=>{
                if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||(event.button!==undefined&&event.button!==0))return;
                event.preventDefault();showProfile(resident,link);
            });
            shape.className='cottage-shape';shape.setAttribute('aria-hidden','true');
            name.className='cottage-name';name.textContent=resident.name;note.className='cottage-status';note.textContent='Resting';
            link.dataset.resident=resident.id;link.append(shape,name,note);$('home-layer').append(link);
        }
        sceneHeight=sceneGeometry.height;$('landscape').style.height=sceneHeight+'px';
        $('landscape').style.setProperty('--commons-width',sceneWidth+'px');
        if(!sleeping)meadow.seed(walkers,sceneGeometry,performance.now(),Math.random,true);
        updateIndicators();walkers.forEach(place);
        if(focusedHome&&!focusedHome.isConnected){
            const id=focusedHome.dataset.resident;
            const home=[...$('home-layer').children].find(el=>el.dataset.resident===id);
            const actor=walkers.find(w=>w.resident.id===id)?.element;
            const target=sleeping?home:actor;
            if(commonsReady&&target&&!target.hidden)target.focus();else returnFocus(null,id);
        }
    }
    function latestFor(id) {
        return [...exchanges.values()].filter(c=>c.messages.some(m=>m.resident_id===id)).sort((a,b)=>b.created_at.localeCompare(a.created_at)||b.id.localeCompare(a.id))[0];
    }
    function restLabel() {
        if(!commonsReady)return 'Rest state is unavailable. Refresh saved village life to check again.';
        return sleeping?'Resting · the village’s opt-in rest schedule is active.':sleepPolicy?.enabled?'Awake · outside the village rest hours.':'No scheduled village rest is enabled.';
    }
    function updateIndicators() {
        for(const w of walkers){
            const saved=latestFor(w.resident.id);
            w.element.hidden=!commonsReady||sleeping;w.indicator.hidden=!commonsReady||sleeping||!saved;
            w.element.dataset.conversation=String(Boolean(activeMeeting?.exchange.messages.some(m=>m.resident_id===w.resident.id)));
            updateActivity(w);
            w.element.dataset.notice=String(posts.has(w.resident.id));
            w.element.title=w.resident.name+(posts.has(w.resident.id)?' · Saved public notice (not a live reading)':'');
            w.element.setAttribute('aria-label',w.resident.name+(sleeping?' is resting.':'')+' Open resident profile'+(posts.has(w.resident.id)?'. Has a saved public notice.':''));
            w.indicator.setAttribute('aria-pressed',String(activeMeeting?.exchange.id===saved?.id&&Boolean(saved)));
            if(saved)w.indicator.setAttribute('aria-label','Read saved conversation: '+saved.messages.map(m=>m.name).join(' and '));
        }
    }
    function updateActivity(w) {
        w.element.dataset.activity=sleeping?'resting':activeMeeting&&(activeMeeting.a===w||activeMeeting.b===w)?'saved-conversation':gathering&&(gathering.a===w||gathering.b===w)?'gathering':w.motion==='walking'?'walking':'idle';
    }
    function renderPosts() {
        const panel=$('board-posts');panel.replaceChildren();panel.hidden=!posts.size;
        if(!posts.size)return;
        const label=document.createElement('p');label.className='field-help';label.textContent='📌 Saved public posts by these neighbors · not live reading activity';panel.append(label);
        for(const p of posts.values()){
            const link=document.createElement('a');link.href='bulletin.php';link.className='saved-post';
            link.textContent=p.name+' · Saved post · '+(globalThis.SparksAtmosphere?.format(p.created_at)??p.created_at);panel.append(link);
        }
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
            const read=document.createElement('button');read.type='button';read.className='small-button';read.textContent='Read together';read.setAttribute('aria-label','Read saved conversation: '+c.messages.map(m=>m.name).join(' and '));read.addEventListener('click',()=>showExchange(c,read,true));article.append(read);
            panel.append(article);
        }
        if (!recent.length) { const p = document.createElement('p'); p.className = 'field-help'; p.textContent = 'No recent saved exchange for these neighbors is available. They can enjoy a little quiet; more history is in the village journal.'; panel.append(p); }
    }
    let checkedAt=null;
    function renderFreshness(){
        if(checkedAt)$('commons-freshness').textContent=`Saved life checked ${globalThis.SparksAtmosphere?.format(checkedAt)??checkedAt}. Refresh residents for updates. Automatic replays appear once per tab session; you can read any saved exchange again.`;
    }
    document.addEventListener('sparks:timezone',()=>{
        renderRecordings();renderPosts();renderFreshness();
        if(activeMeeting)$('dialogue-source').textContent=commons.sourceLabel(activeMeeting.exchange);
    });
    async function refreshCommons() {
        const version = ++commonsVersion, ids = walkers.map(w => w.resident.id);
        homes = []; exchanges = new Map();posts=new Map(); renderHomes(); renderRecordings();renderPosts();
        if (!ids.length) return;
        try {
            const response = await fetch(`api/commons.php?ids=${encodeURIComponent(ids.join(','))}`, {credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Saved village life unavailable.');
            const data = await response.json(); if (version !== commonsVersion) return;
            const replay=commons.replayCooldown(data.settings),sleep=commons.sleepConfiguration(data.settings?.sleep);
            globalThis.SparksAtmosphere?.setSharedWeather?.(data.settings?.weather??null);
            replayCooldown=replay;sleepPolicy=sleep;sleepReceivedAt=performance.now();commonsReady=true;syncSleep();
            homes = Array.isArray(data.homes) ? data.homes.slice(0, 24) : [];
            exchanges = commons.latestExchanges(Array.isArray(data.conversations) ? data.conversations : [], ids);
            posts=commons.bulletinPosts(data.bulletin_posts,ids);
            renderHomes(); renderRecordings();renderPosts();
            updateIndicators();
            checkedAt=new Date().toISOString();renderFreshness();
        } catch {
            if (version === commonsVersion) {
                commonsReady=false;$('home-layer').hidden=true;syncSleep();
                $('saved-dialogue').textContent = 'Saved conversations are resting. You can still browse resident profiles and the village journal.';
                $('commons-freshness').textContent='Saved village state is unavailable. Profiles and the journal remain available; use Refresh residents to check again.';
            }
        }
    }
    function stopPlayback() {
        cancelGathering();
        if(activeMeeting)for(const w of [activeMeeting.a,activeMeeting.b])if(w&&!Number.isFinite(w.stop))w.stop=performance.now()+800;
        meadow.endGather(activeMeeting,performance.now(),Math.random);
        activeMeeting=null;$('dialogue-panel').hidden=true;$('dialogue-lines').replaceChildren();updateIndicators();
    }
    function showExchange(exchange,source=null,focus=false) {
        const pair=exchange.messages.map(m=>walkers.find(w=>w.resident.id===m.resident_id));
        if(pair.some(w=>!w))return;
        stopPlayback();activeMeeting={a:pair[0],b:pair[1],exchange};dialogueOrigin=source;
        for(const w of pair)w.stop=Infinity;
        played.record(exchange.id,Date.now());
        $('dialogue-title').textContent=exchange.messages.map(m=>m.name).join(' & ');
        $('dialogue-source').textContent=commons.sourceLabel(exchange);
        const lines=$('dialogue-lines');lines.replaceChildren();
        for(const message of exchange.messages){
            const line=document.createElement('article'),name=document.createElement('strong'),text=document.createElement('p'),resident=pair.find(w=>w.resident.id===message.resident_id).resident;
            line.className='saved-speech';line.style.setProperty('--speech-color',avatars.palette(avatars.appearance(resident)).primary);
            line.append(avatars.render(resident,'avatar-small'));name.textContent=message.name;text.textContent=message.text;line.append(name,text);lines.append(line);
        }
        $('dialogue-panel').hidden=false;updateIndicators();
        if(focus)$('dialogue-title').focus();
    }
    $('close-dialogue').addEventListener('click',()=>{
        const origin=dialogueOrigin,id=activeMeeting?.exchange.messages[0].resident_id;stopPlayback();returnFocus(origin,id);
    });
    $('dialogue-panel').addEventListener('keydown',event=>{
        if(event.key==='Escape'){event.preventDefault();$('close-dialogue').click();}
    });
    function place(walker) {
        walker.element.style.left=walker.x+'px';walker.element.style.top=walker.y+'px';
        walker.indicator.style.left=Math.max(4,Math.min(sceneWidth-48,walker.x+18))+'px';
        walker.indicator.style.top=Math.max(4,Math.min(sceneHeight-48,walker.y-50))+'px';
    }
    function cancelGathering() {
        meadow.endGather(gathering,performance.now(),Math.random);gathering=null;
        walkers.forEach(updateActivity);
    }
    function schedule() {
        if (!frame && commonsReady && !sleeping && !document.hidden && !paused && !$('resident-profile').open && walkers.length) frame = requestAnimationFrame(tick);
    }
    function tick(now) {
        frame = 0;
        if (!commonsReady || sleeping || document.hidden || paused || $('resident-profile').open) { lastTick = now; return; }
        schedule();
        if (now - lastTick < 80) return;
        const delta = lastTick ? Math.min((now - lastTick) / 1000, .2) : 0;
        lastTick = now;
        meadow.step(walkers,sceneGeometry,delta*(globalThis.SparksAtmosphere?.weatherPace?.()??1),now,Math.random);
        for (const walker of walkers) {
            place(walker);
        }
        if(gathering&&(now>=gathering.until||meadow.distance(gathering.a,gathering.b)<=175)){
            const saved=gathering.exchange;cancelGathering();showExchange(saved);
        }
        walkers.forEach(updateActivity);
        for (let i = 0; !activeMeeting && !gathering && i < walkers.length; i++) {
            const a = walkers[i];
            if (now < a.stop || a.hover) continue;
            for (let j = i + 1; j < walkers.length; j++) {
                const b = walkers[j];
                if (now < b.stop || b.hover || now<replayReadyAt) continue;
                const exchange = exchanges.get(commons.pairKey(a.resident.id, b.resident.id));
                const canReplay = exchange && played.canPlay(exchange.id, Date.now(), replayCooldown);
                if (!canReplay) continue; // Ordinary wandering/idle pauses; no invented meeting.
                const group=meadow.gather(a,b,walkers,sceneGeometry,now);
                if(group&&meadow.distance(a,b)>175)gathering={...group,exchange};
                else{meadow.endGather(group,now,Math.random);showExchange(exchange);}
                updateIndicators();
                break;
            }
        }
    }
    function motionLabel() {
        if(paused)cancelGathering();
        $('motion-toggle').textContent = paused ? 'Resume wandering' : 'Pause wandering';
        $('motion-toggle').setAttribute('aria-pressed', String(paused));
        syncSceneMotion();
        schedule();
    }
    function syncSceneMotion(){globalThis.SparksAtmosphere?.setPaused(!commonsReady||paused||document.hidden||$('resident-profile').open);}
    $('motion-toggle').addEventListener('click', () => { paused = !paused; motionLabel(); });
    reduced.addEventListener('change', () => { paused = reduced.matches; motionLabel(); });
    function syncSleep() {
        const next=commons.sleepingAt(sleepPolicy,sleepPolicy?sleepPolicy.server_time*1000+performance.now()-sleepReceivedAt:Date.now());
        const changed=next!==sleeping;
        if(next&&!sleeping){cancelGathering();if(frame)cancelAnimationFrame(frame);frame=0;lastTick=0;}
        sleeping=next;$('landscape').dataset.sleeping=String(sleeping);
        $('sleep-note').hidden=!commonsReady||!sleeping;$('sleep-note').textContent=sleeping?'The village’s opt-in rest hours are active. Lit cottages show saved homes; profiles and recorded conversations remain available.':'';
        for(const w of walkers)w.element.dataset.sleeping=String(sleeping);
        for(const item of $('resident-directory').children){const rest=item.children[0]?.children[1]?.children[2];if(rest)rest.hidden=!commonsReady||!sleeping;}
        if($('resident-profile').open)$('profile-rest-status').textContent=restLabel();
        if(changed)renderHomes();else updateIndicators();syncSceneMotion();
        if(!sleeping)schedule();
    }
    document.addEventListener('sparks:clock',syncSleep);
    document.addEventListener('visibilitychange', () => {if(document.hidden)cancelGathering();syncSleep(); syncSceneMotion(); lastTick = 0; schedule(); });
    narrow.addEventListener('change', () => {
        const before=walkers.map(w=>w.resident.id).join(','),limit=meadow.capacity(narrow.matches),groups=Math.max(1,Math.ceil(population.length/limit));
        neighborhood=Math.min(neighborhood,groups-1);
        const after=population.slice(neighborhood*limit,(neighborhood+1)*limit).map(r=>r.id).join(',');
        // Keep the actual SVGs, focused controls and selected words when the
        // same neighbors fit. Only changed membership needs new nodes/data.
        if(before===after){sceneWidth=$('landscape').clientWidth||sceneWidth;renderHomes();}
        else{render(true);refreshCommons();}
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
        $('robot-preview').replaceChildren(robot(creationAppearance?{appearance:creationAppearance.value()}:{color: $('robot-color').value, antenna: $('robot-antenna').value}));
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
            if(page!==data.page){neighborhood=0;$('resident-search').value='';}
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
        if(creationAppearance&&!creationAppearance.valid())return;
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        for(const key of Object.keys(fields))if(key.startsWith('spark-appearance-'))delete fields[key];
        if(creationAppearance)fields.appearance=creationAppearance.value();
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
            if (!paused&&actor) actor.element.classList.add('new-spark');
            if(actor)actor.stop = performance.now() + 6500;
            $('private-link').focus(); $('private-link').select();
            $('directory-status').textContent = 'Your new neighbor is in view. Refresh to browse the saved population.';
            $('population').textContent = 'Your new neighbor is saved. Refresh residents for the latest village count.';
        } catch (error) { status(error.message + ' You can retry; the same request will not create a duplicate.'); }
        finally { busy = false; $('create-submit').disabled = false; }
    });
    if($('creation-appearance')){creationAppearance=avatars.editor({color:$('robot-color').value,antenna:$('robot-antenna').value},value=>{$('robot-antenna').value=value.features.antenna||'orb';if(colors.has(value.primary_color))$('robot-color').value=value.primary_color;markColor();previewRobot();});$('creation-appearance').append(creationAppearance.element);}
    previewRobot(); motionLabel();
    refresh().then(async () => {
        status('The village is ready. Take a moment to choose your little spark.');
        await openPrivateRevisit();
    }).catch(() => { status('The village is resting. Use Refresh residents to try again. Any existing profiles below are still available.'); $('scene-empty').textContent = 'The village is resting. Please try again soon.'; });
})();
