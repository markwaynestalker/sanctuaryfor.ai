import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../public/assets/read-aloud.js',import.meta.url),'utf8');
for(const count of [2,3,16,64]){
 const listeners={},events={},spoken=[],buttons=[],lines=Array.from({length:count},(_,i)=>({textContent:`Original message ${i+1}: I disagree.`}));
 const container={querySelectorAll:()=>lines};
 const button={hidden:true,textContent:'Read aloud',setAttribute(k,v){this[k]=v;},closest:()=>container};buttons.push(button);
 const synth={getVoices:()=>[{localService:true,lang:'en-US'}],cancel(){},speak(u){spoken.push(u);},addEventListener(k,f){events[k]=f;}};
 const document={hidden:false,querySelectorAll:()=>buttons,addEventListener(k,f){listeners[k]=f;}};
 const context=vm.createContext({document,speechSynthesis:synth,SpeechSynthesisUtterance:class{constructor(text){this.text=text;}},addEventListener(k,f){events[k]=f;}});vm.runInContext(source,context);
 assert.equal(button.hidden,false);listeners.click({target:{closest:()=>button}});
 for(let i=0;i<count;i++){assert.equal(spoken[i].text,lines[i].textContent);spoken[i].onend();}
 assert.equal(spoken.length,count);assert.equal(button['aria-pressed'],'false');
 listeners.click({target:{closest:()=>button}});const last=spoken.at(-1),length=spoken.length;document.hidden=true;listeners.visibilitychange();last.onend();assert.equal(spoken.length,length,'Cancelled callback cannot resume speech');
 assert.equal(button['aria-pressed'],'false');
}
const buttons=[{hidden:true}],context=vm.createContext({document:{querySelectorAll:()=>buttons,addEventListener(){}},speechSynthesis:{getVoices:()=>[{localService:false,lang:'en-US'}],addEventListener(){}},SpeechSynthesisUtterance:class{},addEventListener(){}});vm.runInContext(source,context);assert.equal(buttons[0].hidden,true,'No remote-only voice is selected');
console.log('PASS original saved speech order at 2/3/16/64 messages, explicit start/stop, stale callback cancellation, hidden tabs and on-device voice requirement');
