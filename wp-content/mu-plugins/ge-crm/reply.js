(()=>{
  document.querySelectorAll('[data-ge-crm-reply]').forEach(form=>{
    const text=form.querySelector('textarea'), button=form.querySelector('button'), feedback=form.querySelector('[data-ge-reply-feedback]');
    const storage='ge-crm-reply:'+form.dataset.storage;
    let pending=null,busy=false;
    try { const saved=JSON.parse(sessionStorage.getItem(storage)||'null'); if(saved){text.value=saved.text;pending=saved.pending||null;} } catch(_error){}
    const save=()=>{try{sessionStorage.setItem(storage,JSON.stringify({text:text.value,pending}));}catch(_error){feedback.textContent='No se pudo conservar el borrador en este navegador.';}};
    text.addEventListener('input',save);
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(busy)return;
      if(pending&&pending.text!==text.value){feedback.textContent='Hay un envío sin confirmar con otro texto. Recuperá ese texto para comprobarlo antes de enviar una respuesta nueva.';return;}
      if(!pending)pending={request_id:form.dataset.request,text:text.value};
      save();busy=true;button.disabled=true;form.setAttribute('aria-busy','true');feedback.textContent='Enviando y registrando el resultado…';
      try {
        const response=await fetch(window.geCRMReply.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':window.geCRMReply.nonce},body:JSON.stringify({record_id:Number(form.dataset.record),request_id:pending.request_id,text:pending.text})});
        const data=await response.json();
        if(!response.ok){
          // Validation errors happen before the transport is called; retain the draft.
          if([403,404,409,422].includes(response.status)){pending=null;save();}
          throw Error(data.message||'No se pudo confirmar el envío.');
        }
        if(['accepted','delivered','read','sent','simulated'].includes(data.state)){
          sessionStorage.removeItem(storage);feedback.textContent='Respuesta registrada.';location.reload();return;
        }
        if(data.state==='failed'){
          pending=null;form.dataset.request=crypto.randomUUID();save();button.textContent='Volver a intentar';feedback.textContent=data.error||'El canal rechazó el envío. Revisá el motivo antes de intentar nuevamente.';
        }else{button.textContent='Comprobar resultado';feedback.textContent=data.error||'El resultado sigue pendiente. Comprobalo antes de volver a enviar.';}
      }catch(error){feedback.textContent=error.message; if(pending)button.textContent='Comprobar resultado';}
      finally{busy=false;button.disabled=false;form.removeAttribute('aria-busy');}
    });
  });
})();
