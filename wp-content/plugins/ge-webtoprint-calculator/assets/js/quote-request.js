(function(){
  'use strict';
  var cfg=window.geRequest,form=document.querySelector('.ge-request-form');if(!cfg||!form)return;
  var root=form.closest('.ge-request'),container=form.querySelector('[data-request-items]'),template=root.querySelector('[data-request-template]'),notice=form.querySelector('[data-request-notice]'),step=1,index=0;
  function uuid(){return crypto.randomUUID();}
  function show(n){step=n;form.querySelectorAll('[data-request-step]').forEach(function(s){s.hidden=Number(s.dataset.requestStep)!==n;});root.querySelectorAll('.ge-request-steps li').forEach(function(li,i){if(i+1===n)li.setAttribute('aria-current','step');else li.removeAttribute('aria-current');});form.querySelector('[data-request-back]').hidden=n===1;form.querySelector('[data-request-next]').hidden=n===3;form.querySelector('[data-request-submit]').hidden=n!==3;notice.textContent='';if(n===3)review();root.querySelector('h1').scrollIntoView({block:'start'});}
  function rows(){return Array.from(container.querySelectorAll('.ge-request-item'));}
  function count(){var n=rows().length;form.querySelector('[data-request-count]').textContent=n?n+' ítem'+(n===1?'':'s')+' agregado'+(n===1?'':'s')+'. Continuá para configurar.':'Todavía no agregaste ítems.';}
  function add(product,data){
    if(rows().length>=30){notice.textContent='Podés agregar hasta 30 ítems.';return;}
    var fragment=template.content.cloneNode(true),row=fragment.querySelector('.ge-request-item'),id=index++;
    row.querySelector('[name=line_uuid]').value=data&&data.line_uuid||uuid();row.querySelector('[name=product_id]').value=product?product.id:(data&&data.product_id||0);
    row.querySelector('[name=title]').value=product?product.name:(data&&data.title||'');row.querySelector('[data-request-title]').textContent=product?product.name:(data&&data.title||'Ítem personalizado');
    row.querySelector('[data-ge-artwork]').dataset.field='items['+id+'][artwork_refs][]';
    if(data){Object.keys(data).forEach(function(k){var el=row.querySelector('[name="'+k+'"]');if(el&&typeof data[k]!=='object')el.value=data[k];});(data.artwork_refs||[]).forEach(function(ref){var li=document.createElement('li'),input=document.createElement('input');input.type='hidden';input.name='items['+id+'][artwork_refs][]';input.value=ref;li.textContent='Archivo guardado · '+ref.slice(0,8);li.appendChild(input);var b=document.createElement('button');b.type='button';b.dataset.geArtworkRemove='';b.textContent='Quitar vínculo';li.appendChild(b);row.querySelector('[data-ge-artwork-list]').appendChild(li);});}
    container.appendChild(fragment);document.dispatchEvent(new Event('ge:quote-lines-changed'));count();
  }
  function payload(){return {artwork_session:form.querySelector('[name=artwork_session]').value,billing_profile_id:form.querySelector('[name=billing_profile_id]').value,needed_by:form.querySelector('[name=needed_by]').value,urgency:form.querySelector('[name=urgency]').value,notes:form.querySelector('[name=notes]').value,items:rows().map(function(row){var data={};row.querySelectorAll('input[name],select[name]').forEach(function(el){if(!el.name.includes('artwork_refs'))data[el.name]=el.value;});data.artwork_refs=Array.from(row.querySelectorAll('input[name*="artwork_refs"]')).map(function(el){return el.value;});return data;})};}
  function review(){var el=form.querySelector('[data-request-review]');el.replaceChildren();payload().items.forEach(function(i){var p=document.createElement('p');p.textContent=i.title+' · '+i.quantity+' unidades'+(i.width&&i.height?' · '+i.width+' × '+i.height+' '+i.measure_unit:'');el.appendChild(p);});}
  function validate(){if(!rows().length){notice.textContent='Agregá al menos un ítem.';return false;}for(var row of rows()){for(var el of row.querySelectorAll('[required],input[type=number]')){if(!el.checkValidity()){show(2);el.reportValidity();return false;}}}return true;}
  async function save(draft){
    if(cfg.preview)return;if(!validate())return;
    if(form.querySelector('[data-state="queued"], [data-state="uploading"], [data-state="error"]')){notice.textContent='Terminá o reintentá las cargas antes de guardar.';return;}
    var button=form.querySelector(draft?'[data-request-draft]':'[data-request-submit]');button.disabled=true;notice.textContent=draft?'Guardando borrador…':'Enviando solicitud…';
    var data=new FormData();data.set('action','ge_request_save');data.set('nonce',cfg.nonce);data.set('payload',JSON.stringify(payload()));if(draft)data.set('draft','1');
    try{var response=await fetch(cfg.url,{method:'POST',body:data,credentials:'same-origin'}),json=await response.json();if(!json.success)throw new Error(json.data.message);notice.textContent=draft?'Borrador guardado. Podés retomarlo en Mis solicitudes.':'Solicitud recibida. Te avisaremos cuando esté lista.';if(!draft)window.location.assign(json.data.url);}
    catch(e){notice.textContent=e.message||'No se pudo guardar. Reintentá.';}finally{button.disabled=false;}
  }
  var searchSeq=0;
  async function search(){var seq=++searchSeq,el=form.querySelector('[data-request-products]');el.textContent='Buscando productos…';var params=new URLSearchParams({action:'ge_request_catalog',nonce:cfg.nonce,q:form.querySelector('[data-request-search]').value,category:form.querySelector('[data-request-category]').value});try{var res=await fetch(cfg.url+'?'+params,{credentials:'same-origin'}),json=await res.json();if(seq!==searchSeq)return;if(!json.success)throw new Error(json.data.message);el.replaceChildren();json.data.forEach(function(p){var b=document.createElement('button');b.type='button';b.textContent=p.name+' · Agregar';b.addEventListener('click',function(){add(p);});el.appendChild(b);});if(!json.data.length)el.textContent='No encontramos coincidencias. Podés agregar un ítem personalizado.';}catch(e){el.textContent='No pudimos cargar el catálogo. Podés seguir con un ítem personalizado.';}}
  var timer;form.querySelector('[data-request-search]').addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(search,300);});form.querySelector('[data-request-category]').addEventListener('change',search);
  form.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;if(b.hasAttribute('data-request-custom'))add();if(b.hasAttribute('data-request-more'))show(1);if(b.hasAttribute('data-request-remove')){b.closest('.ge-request-item').remove();count();}if(b.hasAttribute('data-request-next')){if(step===1&&!rows().length){notice.textContent='Elegí un producto o agregá un ítem personalizado.';return;}if(step===2&&!validate())return;show(step+1);}if(b.hasAttribute('data-request-back'))show(step-1);if(b.hasAttribute('data-request-draft'))save(true);});
  form.addEventListener('submit',function(e){e.preventDefault();save(false);});
  if(cfg.draft){form.querySelector('[name=artwork_session]').value=cfg.draft.artwork_session;cfg.draft.items.forEach(function(i){add(null,i);});['billing_profile_id','needed_by','urgency','notes'].forEach(function(k){form.querySelector('[name='+k+']').value=cfg.draft[k]||'';});}
  if(!cfg.preview)search();
})();
