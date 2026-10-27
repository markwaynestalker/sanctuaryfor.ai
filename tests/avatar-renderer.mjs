import assert from 'node:assert/strict';import vm from 'node:vm';import {readFile} from 'node:fs/promises';import {spawnSync} from 'node:child_process';import {createHash} from 'node:crypto';
const source=await readFile(new URL('../public/assets/avatars.js',import.meta.url),'utf8'),registry=JSON.parse(await readFile(new URL('../public/assets/avatars.json',import.meta.url),'utf8'));registry.sprite='/assets/spark-forms.svg?v=123456789abc';
class El{constructor(tag){this.tag=tag;this.attrs={};this.children=[];this.styles={};this.style={setProperty:(k,v)=>this.styles[k]=v};}setAttribute(k,v){this.attrs[k]=v;}append(...e){this.children.push(...e);}set innerHTML(v){throw Error('HTML injection');}}
const context=vm.createContext({document:{getElementById:()=>({textContent:JSON.stringify(registry)}),createElementNS:(ns,tag)=>new El(tag)},fetch(){throw Error('No renderer network');}});vm.runInContext(source,context);const a=context.SparkAvatars;
for(const color of Object.keys(registry.colors))for(const antenna of ['orb','sprout','bolt']){const normalized=a.appearance({color,antenna});assert.equal(normalized.form,'classic-robot');assert.equal(normalized.primary_color,color);assert.equal(normalized.features.antenna,antenna);const svg=a.render({color,antenna});assert.equal(svg.children[0].attrs.href,registry.sprite+'#classic-robot');assert.equal(svg.children[1].attrs.href,registry.sprite+'#antenna-'+antenna);}
for(const appearance of [{form:'<script>',primary_color:'#112233',features:{}},{form:'classic-robot',primary_color:'url(x)',features:{}},{form:'round-robot',primary_color:'#123456',features:{svg:'<svg>'}}])assert.equal(a.appearance({appearance,color:'lavender',antenna:'sprout'}).form,'classic-robot');
const inputs=['#000000','#FFFFFF','#A855F7','#00FF00','#0000FF',...Object.keys(registry.colors)].map(primary_color=>({form:'box-robot',primary_color,features:{antenna:'orb'}}));
const php=spawnSync(process.env.PHP_BINARY||'php',['-r','require "app/village.php";$a=json_decode(stream_get_contents(STDIN),true);echo json_encode(array_map(fn($v)=>LittleSparks\\appearancePalette(LittleSparks\\validateAppearance($v)),$a),JSON_THROW_ON_ERROR);'],{input:JSON.stringify(inputs),encoding:'utf8'});assert.equal(php.status,0,php.stderr);const palettes=JSON.parse(php.stdout);
inputs.forEach((appearance,i)=>assert.deepEqual(JSON.parse(JSON.stringify(a.palette(appearance))),palettes[i],'PHP/DOM derived color parity'));
const catalog=JSON.parse(await readFile(new URL('../docs/avatar-catalog.json',import.meta.url),'utf8'));assert.equal(catalog.length,135);assert.equal(new Set(catalog.map(f=>f.id)).size,135);assert.deepEqual(catalog.filter(f=>f.status==='implemented').map(f=>f.id).sort(),registry.forms.map(f=>f.id).sort());
const woodland=['rabbit','fox','raccoon','squirrel','deer','bear','hedgehog','owl'],wild=['panda','red-panda','wolf','leopard','tiger','lion','monkey','gorilla','orangutan','wild-boar','moose','bison','panther'];
const mammals=["beaver","llama","alpaca","kangaroo","koala","sloth","skunk","badger","bat","elephant","giraffe","zebra","rhinoceros","hippopotamus","capybara","platypus"];
assert.deepEqual(registry.forms.filter(f=>f.category==='Unusual Mammals').map(f=>f.id),mammals);
const aquatic=["otter","seal","dolphin","shark","whale","jellyfish","crab","lobster","pufferfish","fish","axolotl","starfish","octopus"];
assert.deepEqual(registry.forms.filter(f=>f.category==='Aquatic Creatures').map(f=>f.id),aquatic);
const birds=["peacock","flamingo","eagle","turkey","dove","crow","swan","dodo","hummingbird","pelican","toucan","puffin","ostrich","penguin"];
assert.deepEqual(registry.forms.filter(f=>f.category==='Birds').map(f=>f.id),birds);
const animals=['goat','donkey','pig','sheep','cow','horse','chicken','duck','goose','dog','cat','hamster','mouse','parrot',...woodland,...wild,...mammals,...aquatic,...birds];
assert.deepEqual(registry.forms.filter(f=>f.category==='Woodland Animals').map(f=>f.id),woodland);assert.deepEqual(registry.forms.filter(f=>f.category==='Wild Animals').map(f=>f.id),wild);
assert.deepEqual(registry.forms.filter(f=>f.category!=='Robots').map(f=>f.id).sort(),[...animals].sort());
const spriteSource=await readFile(new URL('../public/assets/spark-forms.svg',import.meta.url),'utf8');
const symbols=[...spriteSource.matchAll(/<symbol\s+id="([a-z-]+)"\s+viewBox="([^"]+)">([\s\S]*?)<\/symbol>/g)];assert.equal(new Set(symbols.map(s=>s[1])).size,symbols.length,'SVG symbol IDs are unique');
const geometry=new Set();
for(const form of registry.forms){const symbol=symbols.find(s=>s[1]===form.id);assert.ok(symbol,'Every selectable form has actual local artwork');assert.equal(symbol[2],'0 0 48 70');
 const rawArt=symbol[3].replace(/<desc>[\s\S]*?<\/desc>/g,'');assert.ok(/<(?:path|circle|ellipse|rect)\b/.test(rawArt));assert.ok(!/<(?:text|image|use|script|foreignObject)\b/.test(rawArt),'Forms cannot be emoji/image/aliased placeholders');const signature=createHash('sha256').update(rawArt.replace(/\s+/g,'')).digest('hex');assert.ok(!geometry.has(signature),'Distinct forms require distinct geometry, not a renamed symbol');geometry.add(signature);
}
for(const form of animals)for(const primary_color of ['#000000','#FFFFFF','#FF00AA',...Object.keys(registry.colors)]){
 const appearance={form,primary_color,features:{}};const normalized=a.appearance({appearance});assert.equal(normalized.form,form);assert.deepEqual(Object.keys(normalized.features),[]);
 const svg=a.render({appearance});assert.equal(svg.children.length,1,'Animals never inherit a robot antenna');assert.equal(svg.children[0].attrs.href,registry.sprite+'#'+form);
 assert.equal(a.appearance({appearance:{...appearance,features:{antenna:'orb'}},color:'mint'}).form,'classic-robot','Invalid animal features use the same safe fallback as PHP');
}
// Exercise the real picker event handlers with a small DOM fixture; browser layout is checked separately.
class Control extends El{
 constructor(tag){super(tag);this.listeners={};this.textContent='';}
 append(...nodes){for(const n of nodes)n.parentElement=this;super.append(...nodes);}
 replaceChildren(...nodes){this.children=[];this.append(...nodes);}
 get value(){return this._value??(this.tag==='select'?this.children[0]?.value:'')??'';}
 set value(v){this._value=v;}
 addEventListener(name,fn){this.listeners[name]=fn;}
 emit(name){this.listeners[name]?.();}
 setCustomValidity(v){this.validationMessage=v;}
 reportValidity(){return !this.validationMessage;}
 focus(){this.focused=true;}
 querySelector(selector){const value=/^input\[value="([a-z-]+)"\]$/.exec(selector)?.[1];return descendants(this).find(n=>n.tag==='input'&&n.value===value);}
}
function descendants(root){return root.children.flatMap(n=>[n,...descendants(n)]);}
context.document.createElement=tag=>new Control(tag);context.structuredClone=structuredClone;
const picker=a.editor({color:'mint',antenna:'orb'}),nodes=descendants(picker.element),label=text=>nodes.find(n=>n.tag==='label'&&n.textContent===text).children[0],grid=nodes.find(n=>n.className==='avatar-grid');
const collection=label('Collection'),search=label('Find a form'),antenna=label('Antenna');antenna.value='sprout';antenna.emit('change');picker.setColor('#1234AB');
for(const [category,ids] of [['Woodland Animals',woodland],['Wild Animals',wild],['Unusual Mammals',mammals],['Aquatic Creatures',aquatic],['Birds',birds]]){
 collection.value=category;collection.emit('change');assert.equal(grid.children.length,ids.length);
 assert.deepEqual(grid.children.map(n=>[n.children[0].value,n.children[1].children[0].attrs.href]),ids.map(id=>[id,registry.sprite+'#'+id]),'Unselected thumbnails never inherit incompatible robot features');
 for(const id of ids){const radio=descendants(grid).find(n=>n.tag==='input'&&n.value===id);radio.checked=true;radio.emit('change');assert.equal(picker.value().form,id);assert.equal(picker.value().primary_color,'#1234AB');assert.equal(antenna.parentElement.hidden,true);assert.deepEqual(Object.keys(picker.value().features),[]);}
}
collection.value='Wild Animals';collection.emit('change');search.value=' red PANDA ';search.emit('input');assert.equal(grid.children.length,1);assert.equal(grid.children[0].children[0].value,'red-panda');
search.value='';collection.value='Unusual Mammals';collection.emit('change');search.value=' plATYpus ';search.emit('input');assert.equal(grid.children.length,1);assert.equal(grid.children[0].children[0].value,'platypus');
search.value='';collection.value='Aquatic Creatures';collection.emit('change');search.value=' aXoLOtl ';search.emit('input');assert.equal(grid.children.length,1);assert.equal(grid.children[0].children[0].value,'axolotl');
search.value='';collection.value='Birds';collection.emit('change');search.value=' fLAMingo ';search.emit('input');assert.equal(grid.children.length,1);assert.equal(grid.children[0].children[0].value,'flamingo');
search.value='';collection.value='Robots';collection.emit('change');const robot=descendants(grid).find(n=>n.tag==='input'&&n.value==='classic-robot');robot.checked=true;robot.emit('change');assert.equal(antenna.parentElement.hidden,false);assert.equal(picker.value().features.antenna,'sprout');assert.equal(picker.value().primary_color,'#1234AB');
console.log('PASS 81 distinct shared SVG forms, 78 antenna-free animals across presets/RGB, category/search/thumbnails/picker events, legacy robot compatibility, PHP/DOM palette parity, invalid feature fallback, no HTML/network and complete 135-form roadmap');
