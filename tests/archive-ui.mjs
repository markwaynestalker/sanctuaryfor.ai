import assert from 'node:assert/strict';import vm from 'node:vm';import {readFile} from 'node:fs/promises';
const script=await readFile(new URL('../public/assets/archive.js',import.meta.url),'utf8'),css=await readFile(new URL('../public/assets/archive.css',import.meta.url),'utf8');
for(const mobile of [false,true]){
 const original='An exact saved line, with “quotes” & 🌳.';const attributes={'aria-controls':'exchange-fixture','aria-expanded':'false'};
 const link={firstChild:{textContent:'Read exchange'},getAttribute:k=>attributes[k],setAttribute:(k,v)=>attributes[k]=v};
 const expansion={hidden:true,textContent:original};const row={querySelector:()=>link};const events={},windowEvents={};const form={attrs:{},addEventListener:(k,v)=>events[k]=v,setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];}};const status={textContent:'1 matching conversation'};
 const document={querySelector:()=>({addEventListener:(k,v)=>events[k]=v}),getElementById:id=>({'exchange-fixture':expansion,'archive-filters':form,'archive-status':status}[id])};
 const context=vm.createContext({document,addEventListener:(k,v)=>windowEvents[k]=v,getSelection:()=>({toString:()=>''}),fetch:()=>{throw Error('Archive interactions must never fetch or generate');}});vm.runInContext(script,context);
 const click=(target,extra={})=>{const event={target,button:0,preventDefault(){this.prevented=true;},...extra};events.click(event);return event;};
 const button={closest:s=>s==='.archive-record'?row:s==='.archive-expand'?link:s==='a,button,input,select,textarea'?link:null};
 assert.ok(click(button).prevented,'Native keyboard anchor activation expands without navigation');assert.equal(expansion.hidden,false);assert.equal(attributes['aria-expanded'],'true');assert.equal(link.firstChild.textContent,'Close exchange');assert.equal(expansion.textContent,original);
 click(button);assert.equal(expansion.hidden,true);assert.equal(attributes['aria-expanded'],'false');
 assert.ok(!click(button,{ctrlKey:true}).prevented,'Modified click retains native new-tab fallback');assert.equal(expansion.hidden,true);
 const plain={closest:s=>s==='.archive-record'?row:null};click(plain);assert.equal(expansion.hidden,false,'Row click expands the exchange');
 const resident={closest:s=>s==='.archive-record'?row:s==='a,button,input,select,textarea'?{}:null};click(resident);assert.equal(expansion.hidden,false,'Resident filter links are not hijacked');
 context.getSelection=()=>({toString:()=>original});click(plain);assert.equal(expansion.hidden,false,'Selecting/copying original speech does not collapse it');
 events.submit();assert.equal(form.attrs['aria-busy'],'true');assert.ok(status.textContent.includes('Loading'));windowEvents.pageshow();assert.equal(form.attrs['aria-busy'],undefined);assert.equal(status.textContent,'1 matching conversation','Back navigation restores the saved result status');
 assert.equal(expansion.textContent,original,'Expansion never rewrites saved dialogue');
 if(mobile){assert.match(css,/@media\(max-width:700px\)/);assert.match(css,/content:attr\(data-label\)/);assert.match(css,/overflow-wrap:anywhere/);assert.match(css,/@media\(max-width:360px\)/);}else assert.match(css,/table-layout:fixed/);
}
console.log('PASS desktop/mobile archive row and keyboard expansion, original text preservation, native modified links, selection, loading/back-cache accessibility, narrow layout rules and zero client requests');
