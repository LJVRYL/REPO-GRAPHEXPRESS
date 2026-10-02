(() => {
  'use strict';
  let modal, current, last, poll, busy = false, returnFocus;
  const labels = {sending:'Preparando',queued:'En cola',running:'Procesando',completed:'Completado',blocked:'No disponible',failed:'Error'};
  const el = (tag, text, cls) => { const n=document.createElement(tag); if(text)n.textContent=text; if(cls)n.className=cls; return n; };
  function close() { clearTimeout(poll); modal?.remove(); modal=null; returnFocus?.focus(); }
  function preview(container, file) {
    container.replaceChildren(); container.append(el('strong',file.name));
    if (/^image\/(png|jpeg|webp)$/.test(file.mime)) { const img=el('img'); img.src=file.url; img.alt=file.name; container.append(img); }
    const a=el('a','Abrir archivo exacto'); a.href=file.url; a.target='_blank'; a.rel='noopener'; container.append(a);
    if(file.version_id) container.append(el('small','Versión: '+file.version_id));
    if(file.checksum_sha256) container.append(el('small','SHA-256: '+file.checksum_sha256));
  }
  async function api(op, extra={}) {
    const body=new URLSearchParams({action:'ge_ai_artwork',op,order_id:String(current.order_id),nonce:current.nonce,version_id:current.version_id,...extra});
    const res=await fetch(current.endpoint,{method:'POST',body,credentials:'same-origin'});
    const data=await res.json(); if(!res.ok||!data.success)throw new Error(data.data?.message||'No se pudo confirmar la operación.'); return data.data;
  }
  function show(data) {
    if(!modal)return; last=data;
    const r=data.request, status=modal.querySelector('[data-status]');
    status.textContent=r ? `${labels[r.status]||r.status}. ${r.error||''}` : 'Beta · '+data.runtime.reason;
    const detail=modal.querySelector('[data-request]'); detail.textContent=r ? 'Solicitud: '+r.request_id+(r.task_id?' · Tarea: '+r.task_id:'') : '';
    modal.querySelector('[data-results]').hidden=!data.candidate;
    modal.querySelector('.ge-ai-decisions').hidden=!data.candidate;
    if(data.candidate) {
      preview(modal.querySelector('[data-candidate]'),data.candidate);
      modal.querySelector('[data-analysis]').textContent='Analyzer: '+(data.candidate.analysis?.confidence||'pendiente')+' · Preflight: '+(data.candidate.preflight_status==='pending_human_review'?'pendiente de revisión humana':data.candidate.preflight_status||'pendiente de revisión humana');
      modal.querySelector('[data-select]').disabled=data.candidate.status!=='candidate';
      modal.querySelector('[data-discard]').disabled=data.candidate.status!=='candidate';
    }
    if(r && ['sending','queued','running'].includes(r.status)) poll=setTimeout(async()=>{try{show(await api('status',{request_id:r.request_id}));}catch(e){status.textContent=e.message;}},3000);
  }
  function uuid() { if(crypto.randomUUID)return crypto.randomUUID(); const bytes=crypto.getRandomValues(new Uint8Array(16));bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;const h=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`; }
  async function open(button) {
    close(); returnFocus=button; current=JSON.parse(button.dataset.geAi); last=null;
    modal=el('dialog',null,'ge-ai-modal');
    modal.innerHTML='<header><div><small>AI-GRUPO · Beta</small><h2>Mejorar con IA</h2></div><button type="button" data-close aria-label="Cerrar">×</button></header><p>El original se conserva. Cada salida será una versión candidata que requiere revisión y aprobación.</p><div class="ge-ai-comparison"><section data-original></section><section data-results hidden><h3>Candidata</h3><div data-candidate></div><p data-analysis></p></section></div><form><label for="ge-ai-instruction">¿Qué querés mejorar?</label><textarea id="ge-ai-instruction" rows="4" minlength="8" maxlength="2000" required placeholder="Describí la corrección y las medidas o márgenes exactos."></textarea><div class="ge-ai-suggestions"></div><button type="submit" data-submit>Solicitar mejora</button></form><p role="status" aria-live="polite" data-status></p><small data-request></small><div class="ge-ai-decisions"><button type="button" data-select>Usar esta versión</button><button type="button" data-again>Pedir otra corrección</button><button type="button" data-discard>Descartar</button></div><p class="ge-ai-note">Usar una candidata no aprueba el diseño ni libera producción. No se envían avisos al cliente automáticamente.</p>';
    document.body.append(modal); preview(modal.querySelector('[data-original]'),current);
    modal.querySelector('h2').id='ge-ai-title';modal.setAttribute('aria-labelledby','ge-ai-title');modal.querySelector('.ge-ai-decisions').hidden=true;
    const a=current.analysis||{}; modal.querySelector('[data-original]').append(el('small','Analyzer: '+(a.confidence||'pendiente')+(a.warning?' · '+a.warning:'')));
    const textarea=modal.querySelector('textarea');
    for(const text of ['Agregar sangrado','Ajustar tamaño','Centrar diseño','Mejorar resolución','Quitar fondo','Preparar para impresión','Corregir márgenes','Otro']) {
      const b=el('button',text);b.type='button'; b.addEventListener('click',()=>{textarea.value=text==='Otro'?'':text+': ';textarea.focus();});modal.querySelector('.ge-ai-suggestions').append(b);
    }
    modal.querySelector('[data-close]').onclick=close;modal.addEventListener('cancel',e=>{e.preventDefault();close();});
    modal.addEventListener('click',e=>{if(e.target===modal){const r=modal.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();}});
    modal.querySelector('form').onsubmit=async(e)=>{
      e.preventDefault();if(busy)return;busy=true;clearTimeout(poll);const b=modal.querySelector('[data-submit]');b.disabled=true;modal.querySelector('[data-status]').textContent='Preparando solicitud…';
      const instruction=textarea.value;const kind=/tamaño|\d+\s*[x×]\s*\d+/i.test(instruction)?'resize':/impresión|sangrado|márgenes/i.test(instruction)?'file_prepare':'design_edit';
      try { show(await api('request',{request_id:uuid(),instruction,kind})); }catch(err){if(modal)modal.querySelector('[data-status]').textContent='Error: '+err.message;}finally{busy=false;if(modal)b.disabled=false;}
    };
    for(const decision of ['select','discard'])modal.querySelector('[data-'+decision+']').onclick=async()=>{
      if(busy||!last?.candidate)return;busy=true;try{await api('decision',{version_id:last.candidate.version_id,decision});location.reload();}catch(e){modal.querySelector('[data-status]').textContent=e.message;busy=false;}
    };
    modal.querySelector('[data-again]').onclick=()=>{if(last?.candidate){current.version_id=last.candidate.version_id;preview(modal.querySelector('[data-original]'),last.candidate);}textarea.focus();textarea.select();};
    modal.showModal();textarea.focus();try{show(await api('status'));}catch(e){if(modal)modal.querySelector('[data-status]').textContent=e.message;}
  }
  document.addEventListener('click',e=>{const button=e.target.closest('[data-ge-ai]');if(button)open(button);});
})();
