(() => {
  'use strict';
  document.querySelectorAll('[data-job-request]').forEach(form => {
    const cards = [...form.querySelectorAll('[data-job-card]')];
    const back = form.querySelector('[data-job-back]');
    const next = form.querySelector('[data-job-next]');
    const submit = form.querySelector('[data-job-submit]');
    const progress = form.querySelector('[data-job-progress]');
    const error = form.querySelector('[data-job-error]');
    let index = 0, busy = false;
    try {
      const restored=JSON.parse(form.querySelector('[data-job-restore]').value || '{}');
      ['issuer_profile_id','issuer_change_reason','customer_tax_confirm'].forEach(name=>{ const field=form.elements[name]; if(field && restored[name]!=null) { if(field.type==='checkbox')field.checked=restored[name]==='1';else field.value=restored[name]; } });
    } catch (_) { /* An absent draft must not prevent preparing the request. */ }
    const key = 'request_step_' + form.elements.request_id.value;
    const report = (message, field) => {
      error.textContent = message;
      if (field) { index = cards.findIndex(card => card.contains(field)); show(); field.focus(); }
      else { error.focus(); }
    };
    const review = () => {
      const list = form.querySelector('[data-job-review]'); list.replaceChildren();
      form.querySelectorAll('[data-job-price]').forEach(input => {
        const term = document.createElement('dt'); term.textContent = input.dataset.jobPrice;
        const value = document.createElement('dd'); value.textContent = input.value === '' ? 'Precio pendiente' : Number(input.value).toLocaleString('es-AR', {style:'currency',currency:'ARS'}) + ' netos por unidad';
        list.append(term,value);
      });
      const select = form.elements.billing_profile_id;
      const term = document.createElement('dt'); term.textContent = 'Receptor';
      const value = document.createElement('dd'); value.textContent = select.selectedOptions[0]?.textContent || 'Pendiente'; list.append(term,value);
      const issuer=form.elements.issuer_profile_id;
      if(issuer){const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent='Emisor';dd.textContent=issuer.selectedOptions[0]?.textContent || 'A revisar';list.append(dt,dd);}
    };
    function show(focus = false) {
      index = Math.max(0, Math.min(index,cards.length-1));
      cards.forEach((card,i) => card.hidden = i !== index);
      progress.textContent = `Paso ${index+1} de ${cards.length} · ${cards[index].dataset.jobCard}`;
      back.hidden = index === 0; next.hidden = index === cards.length-1; submit.hidden = index !== cards.length-1;
      if (index === cards.length-1) review();
      if (focus) { const heading = cards[index].querySelector('h2'); heading.tabIndex = -1; heading.focus(); }
    }
    const validate = () => {
      const invalid = [...cards[index].querySelectorAll('input,select,textarea')].find(input => !input.checkValidity());
      if (invalid) { report(invalid.validationMessage, invalid); return false; } return true;
    };
    const navigate = i => { index=i; const url=new URL(location.href); url.searchParams.set(key,index+1); history.pushState({jobStep:index},'',url); show(true); };
    back.addEventListener('click',() => navigate(index-1));
    next.addEventListener('click',() => { if(validate()) navigate(index+1); });
    addEventListener('popstate',() => { index=(Number(new URL(location.href).searchParams.get(key))||1)-1;show(true); });
    index=(Number(new URL(location.href).searchParams.get(key))||1)-1; show();
    form.addEventListener('submit',async event => {
      event.preventDefault(); if(busy) return;
      const draft=event.submitter?.hasAttribute('data-job-draft');
      const invalid=draft ? null : [...form.querySelectorAll('input,select,textarea')].find(input=>!input.checkValidity());
      if(invalid){report(invalid.validationMessage,invalid);return;}
      busy=true; error.textContent=''; const buttons=[...form.querySelectorAll('button')];buttons.forEach(button=>button.disabled=true); form.setAttribute('aria-busy','true');
      const active=event.submitter || submit, original=active.textContent; active.textContent=draft?'Guardando avance…':'Preparando presupuesto…';
      try {
        const data=new FormData(form);data.set('action','ge_flow_quote_request_staff'); if(draft)data.set('job_intent','draft');
        const response=await fetch(form.dataset.ajax,{method:'POST',body:data,credentials:'same-origin'});
        const result=await response.json();
        if(!result.success) { const field=result.data?.code==='price' ? [...form.querySelectorAll('[data-job-price]')].find(input=>input.value==='') : result.data?.code==='profile' ? form.elements.billing_profile_id : null; report(result.data?.message || 'Revisá la solicitud. Tus valores siguen en pantalla.',field); }
        else if(result.data.saved) { form.querySelector('[data-job-saved]').textContent='Avance guardado. Podés volver a esta ficha para continuar.'; }
        else { location.assign(result.data.url); return; }
      } catch (_) { report('No pudimos confirmar la operación. Tus datos siguen aquí. Abrí la ficha de la solicitud para comprobar si el presupuesto ya se creó antes de volver a intentar.'); }
      busy=false;buttons.forEach(button=>button.disabled=false);active.textContent=original;form.removeAttribute('aria-busy');
    });
  });
})();
