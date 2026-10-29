'use strict';
// Explicit playback of saved text with an on-device browser voice only.
(() => {
    const synth=globalThis.speechSynthesis,Utterance=globalThis.SpeechSynthesisUtterance;
    if(!synth||!Utterance)return;
    let current=null,generation=0,currentUtterance=null;
    const voice=()=>synth.getVoices().find(v=>v.localService&&/^en(?:-|$)/i.test(v.lang));
    function stop(){generation++;synth.cancel();currentUtterance=null;if(current){current.textContent='Read aloud';current.setAttribute('aria-pressed','false');current=null;}}
    function available(){for(const button of document.querySelectorAll('[data-read-aloud]'))button.hidden=!voice();}
    document.addEventListener('click',event=>{
        const button=event.target.closest('[data-read-aloud]');if(!button)return;
        const active=current===button;stop();if(active)return;
        const chosen=voice(),container=button.closest('[data-transcript]');if(!chosen||!container)return;
        const lines=[...container.querySelectorAll('[data-speech]')].map(el=>el.textContent);
        if(!lines.length||lines.length>64||lines.some(line=>!line||Array.from(line).length>160))return;
        current=button;button.textContent='Stop reading';button.setAttribute('aria-pressed','true');
        const token=generation;let index=0;
        function next(){if(token!==generation)return;if(index===lines.length){stop();return;}const utterance=new Utterance(lines[index++]);currentUtterance=utterance;utterance.voice=chosen;utterance.lang=chosen.lang;utterance.onend=next;utterance.onerror=()=>{if(token===generation)stop();};synth.speak(utterance);}
        next();
    });
    document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
    addEventListener('pagehide',stop);synth.addEventListener?.('voiceschanged',available);
    globalThis.SparksReadAloud=Object.freeze({stop});available();
})();
