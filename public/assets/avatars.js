'use strict';
globalThis.SparkAvatars=(()=>{
    const registry=JSON.parse(document.getElementById('spark-avatar-registry').textContent),forms=new Map(registry.forms.map(f=>[f.id,f])),antennas=['orb','sprout','bolt'];
    const sprite=/^\/assets\/spark-forms\.svg\?v=[a-f0-9]{12}$/.test(registry.sprite)?registry.sprite:'/assets/spark-forms.svg';
    const validColor=v=>typeof v==='string'&&(Object.hasOwn(registry.colors,v)||/^#[a-f0-9]{6}$/i.test(v));
    function appearance(resident={}){
        const a=resident.appearance;
        if(a&&forms.has(a.form)&&validColor(a.primary_color)&&a.features&&Object.keys(a.features).every(k=>forms.get(a.form).features.includes(k))&&(!a.features.antenna||antennas.includes(a.features.antenna)))return {form:a.form,primary_color:a.primary_color.startsWith('#')?a.primary_color.toUpperCase():a.primary_color,features:forms.get(a.form).features.includes('antenna')?{antenna:a.features.antenna||'orb'}:{}};
        return {form:'classic-robot',primary_color:Object.hasOwn(registry.colors,resident.color)?resident.color:'mint',features:{antenna:antennas.includes(resident.antenna)?resident.antenna:'orb'}};
    }
    function palette(a){const primary=registry.colors[a.primary_color]||a.primary_color,rgb=primary.slice(1).match(/../g).map(v=>parseInt(v,16));const mix=target=>'#'+rgb.map(v=>Math.floor(v*.75+target*.25+.5).toString(16).padStart(2,'0')).join('').toUpperCase();const ink=rgb[0]*299+rgb[1]*587+rgb[2]*114>=140000?'#12242A':'#FFFFFF';return {primary,highlight:mix(255),shadow:mix(0),ink,outline:ink};}
    function render(resident={},className=''){
        const a=appearance(resident),svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('class','spark-avatar '+className);svg.setAttribute('viewBox','0 0 48 70');svg.setAttribute('aria-hidden','true');svg.setAttribute('focusable','false');
        for(const [key,color] of Object.entries(palette(a)))svg.style.setProperty('--spark-'+key,color);
        const use=id=>{const el=document.createElementNS('http://www.w3.org/2000/svg','use');el.setAttribute('href',sprite+'#'+id);svg.append(el);};use(a.form);if(forms.get(a.form).features.includes('antenna'))use('antenna-'+a.features.antenna);return svg;
    }
    let next=0;
    function editor(resident={},onChange=()=>{}){
        let value=appearance(resident),valid=true,antennaMemory=antennas.includes(resident.appearance?.features?.antenna)?resident.appearance.features.antenna:(antennas.includes(resident.antenna)?resident.antenna:'orb');const id='spark-appearance-'+(++next),root=document.createElement('fieldset');root.className='appearance-picker';
        const el=(tag,text)=>{const n=document.createElement(tag);if(text)n.textContent=text;return n;};root.append(el('legend','Choose an appearance'));
        const control=(text,input)=>{const label=el('label',text);label.append(input);root.append(label);return input;};
        const search=control('Find a form',el('input'));search.type='search';search.autocomplete='off';
        const category=control('Collection',el('select'));for(const name of ['All collections',...new Set(registry.forms.map(f=>f.category))]){const o=el('option',name);o.value=name;category.append(o);}
        const grid=el('div');grid.className='avatar-grid';grid.setAttribute('role','group');grid.setAttribute('aria-label','Available avatar forms');root.append(grid);
        const feedback=el('p');feedback.className='field-help';feedback.setAttribute('role','status');const large=el('div');large.className='appearance-preview';large.setAttribute('aria-label','Appearance preview');root.append(large);
        const presets=control('Preset color',el('select'));const custom=el('option','Custom RGB');custom.value='custom';presets.append(custom);for(const name of Object.keys(registry.colors)){const o=el('option',name.replaceAll('-',' '));o.value=name;presets.append(o);}
        const wheel=control('Full-spectrum color',el('input'));wheel.type='color';const hex=control('Hex color (#RRGGBB)',el('input'));hex.type='text';hex.maxLength=7;hex.autocomplete='off';hex.spellcheck=false;
        const antenna=control('Antenna',el('select'));for(const name of antennas){const o=el('option',name);o.value=name;antenna.append(o);}root.append(feedback);
        function draw(){
            grid.replaceChildren();let shown=0;
            for(const f of registry.forms){if(category.value!=='All collections'&&category.value!==f.category||!f.name.toLowerCase().includes(search.value.toLowerCase().trim()))continue;
                const label=el('label'),radio=el('input');radio.type='radio';radio.name=id;radio.value=f.id;radio.checked=value.form===f.id;label.className='avatar-option';
                radio.addEventListener('change',()=>{if(radio.checked){if(value.features.antenna)antennaMemory=value.features.antenna;value={...value,form:f.id,features:f.features.includes('antenna')?{antenna:antennaMemory}:{}};sync();grid.querySelector('input[value="'+f.id+'"]')?.focus();onChange(value);}});label.append(radio,render({appearance:{...value,form:f.id,features:f.features.includes('antenna')?{antenna:value.features.antenna||antennaMemory}:{}}}),el('span',f.name));grid.append(label);shown++;
            }
            if(!shown)grid.append(el('p','No matching forms. Try another search.'));large.replaceChildren(render({appearance:value}));antenna.parentElement.hidden=!forms.get(value.form).features.includes('antenna');
        }
        function sync(){const colors=palette(value);presets.value=Object.hasOwn(registry.colors,value.primary_color)?value.primary_color:'custom';wheel.value=colors.primary;hex.value=colors.primary;antenna.value=value.features.antenna||antennaMemory;valid=true;hex.setCustomValidity('');feedback.textContent='';draw();}
        const changed=()=>{valid=true;hex.setCustomValidity('');feedback.textContent='';draw();onChange(value);};
        search.addEventListener('input',draw);category.addEventListener('change',draw);presets.addEventListener('change',()=>{if(presets.value!=='custom'){value={...value,primary_color:presets.value};sync();onChange(value);}});
        wheel.addEventListener('input',()=>{value={...value,primary_color:wheel.value.toUpperCase()};sync();onChange(value);});
        hex.addEventListener('input',()=>{if(!/^#[a-f0-9]{6}$/i.test(hex.value)){valid=false;hex.setCustomValidity('Use # followed by six hexadecimal digits.');feedback.textContent='Use a complete color such as #A855F7.';return;}value={...value,primary_color:hex.value.toUpperCase()};wheel.value=value.primary_color;presets.value='custom';changed();});
        antenna.addEventListener('change',()=>{antennaMemory=antenna.value;value={...value,features:{antenna:antenna.value}};changed();});sync();
        return {element:root,value:()=>structuredClone(value),valid:()=>{if(!valid)hex.reportValidity();return valid;},setColor(color){if(validColor(color)){value={...value,primary_color:color};sync();onChange(value);}}};
    }
    return Object.freeze({registry,appearance,palette,render,editor});
})();
