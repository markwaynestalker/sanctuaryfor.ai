'use strict';
// Optional enhancement: native GET links/forms remain usable without JavaScript.
(() => {
    const table=document.querySelector('.archive-table');
    function toggle(link){
        const target=document.getElementById(link.getAttribute('aria-controls'));if(!target)return;
        target.hidden=!target.hidden;link.setAttribute('aria-expanded',String(!target.hidden));
        link.firstChild.textContent=target.hidden?'Read exchange':'Close exchange';
    }
    table?.addEventListener('click',event=>{
        const row=event.target.closest('.archive-record');if(!row)return;
        const link=event.target.closest('.archive-expand');
        if(link){if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button>0)return;event.preventDefault();toggle(link);}
        else if(!event.target.closest('a,button,input,select,textarea')&&!globalThis.getSelection?.()?.toString())toggle(row.querySelector('.archive-expand'));
    });
    const form=document.getElementById('archive-filters'),status=document.getElementById('archive-status'),initialStatus=status?.textContent;
    form?.addEventListener('submit',()=>{if(status)status.textContent='Loading matching conversations…';form.setAttribute('aria-busy','true');});
    // Safari's back/forward cache can restore the pre-navigation busy state.
    addEventListener('pageshow',()=>{form?.removeAttribute('aria-busy');if(status)status.textContent=initialStatus;});
})();
