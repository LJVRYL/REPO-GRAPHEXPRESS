(function () {
  'use strict';
  var cfg = window.geRequest, form = document.querySelector('.ge-quick-quote .ge-request-form');
  if (!cfg || !form) return;
  var root = form.closest('.ge-quick-quote'), notice = form.querySelector('[data-request-notice]'), line = form.querySelector('[data-ge-line]');
  var step = 'intent', history = [], selectedUrl = '', busy = false, touched = false, searchSeq = 0;
  var storageKey = 'ge.quick.request.v1.' + cfg.customerId;
  function field(name) { return line.querySelector('[name="' + name + '"]') || form.querySelector('[name="' + name + '"]'); }
  function event(name, extra) { document.dispatchEvent(new CustomEvent('graphex:funnel', {detail:Object.assign({event:name}, extra || {})})); }
  function payload() {
    var item = {};
    ['line_uuid','product_id','title','quantity','description','category','width','height','measure_unit','material','finishing','options','usage','notes'].forEach(function(k) { item[k] = field(k).value; });
    item.artwork_refs = Array.from(line.querySelectorAll('input[name*="artwork_refs"]')).map(function(el) {return el.value;});
    return {funnel_source:'landing-v1',artwork_session:form.elements.artwork_session.value, items:[item], billing_profile_id:form.elements.billing_profile_id.value, needed_by:form.elements.needed_by.value, urgency:form.elements.urgency.value, notes:''};
  }
  function localSave() {
    if (cfg.preview || !touched) return;
    try { localStorage.setItem(storageKey, JSON.stringify({at:Date.now(), step:step, history:history, selectedUrl:selectedUrl, data:payload()})); root.querySelector('[data-quick-autosave]').textContent = 'Tu avance se guarda en este dispositivo.'; }
    catch(e) { root.querySelector('[data-quick-autosave]').textContent = 'Para retomar después, usá Guardar borrador.'; }
  }
  function show(next, back) {
    if (!back && next !== step) history.push(step);
    step = next;
    form.querySelectorAll('[data-quick-step]').forEach(function(el) {el.hidden = el.dataset.quickStep !== step;});
    var flow = ['intent','description','quantity','measure','date','artwork','profile','review'], pos = flow.indexOf(step);
    if (step === 'catalog') pos = 1;
    root.querySelector('[data-quick-bar]').value = Math.max(1,pos);
    root.querySelector('[data-quick-progress]').textContent = step === 'review' ? 'Revisar y enviar' : 'Una pregunta a la vez';
    form.querySelector('[data-quick-back]').hidden = !history.length;
    form.querySelector('[data-quick-next]').hidden = ['intent','catalog','review'].includes(step);
    form.querySelector('[data-request-submit]').hidden = step !== 'review';
    form.querySelector('[data-request-draft]').hidden = !field('title').value || !field('quantity').value;
    notice.textContent = '';
    if (step === 'review') review();
    var heading = form.querySelector('[data-quick-step="'+step+'"] h2');
    if (heading) {heading.tabIndex = -1; heading.focus({preventScroll:true}); heading.scrollIntoView({block:'nearest'});}
    localSave();
  }
  function infer(text) {
    // Only extract explicit numbers. No prices, inferred material or promised dates.
    var q = text.match(/(?:necesito|quiero|hacer|imprimir)?\s*(\d{1,6})\s+(?:stickers?|etiquetas?|folletos?|volantes?|tarjetas?|unidades?|bolsas?|banners?|vinilos?)/i);
    if (q && !field('quantity').value) field('quantity').value = q[1];
    var m = text.match(/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|m)\b/i);
    if (m && !field('width').value && !field('height').value) {field('width').value=m[1].replace(',','.'); field('height').value=m[2].replace(',','.'); field('measure_unit').value=m[3].toLowerCase();}
  }
  function validateStep() {
    if(step === 'description') {
      var text = field('description').value.trim();
      if (!text) {notice.textContent='Contanos qué necesitás para poder ayudarte.'; field('description').focus(); return false;}
      if (!Number(field('product_id').value)) field('title').value=text.slice(0,160);
      infer(text);
    }
    var current = form.querySelector('[data-quick-step="'+step+'"]');
    for (var el of current.querySelectorAll('input,select,textarea')) {if (!el.checkValidity()) {el.reportValidity(); return false;}}
    if(step === 'measure' && Boolean(field('width').value)!==Boolean(field('height').value)) {notice.textContent='Completá ambas medidas o dejalas vacías. También podés describir la medida en tu idea.'; return false;}
    return true;
  }
  function next() {
    if(!validateStep())return;
    var route={description:'quantity',quantity:'measure',measure:'date',date:'artwork',artwork:form.querySelector('[data-quick-step="profile"]')?'profile':'review',profile:'review'};
    show(route[step] || 'review');
  }
  function review() {
    var data=payload(), i=data.items[0], el=form.querySelector('[data-request-review]'); el.replaceChildren();
    var rows=[['Trabajo',i.title],['Cantidad',i.quantity+' unidades'],['Medidas',i.width&&i.height?i.width+' × '+i.height+' '+i.measure_unit:'A coordinar'],['Material / terminación',[i.material,i.finishing].filter(Boolean).join(' · ')||'A asesorar'],['Fecha',data.needed_by||'A coordinar'],['Plazo',data.urgency==='urgent'?'Urgente, sujeto a confirmación':'Flexible'],['Descripción',i.description],['Archivos',i.artwork_refs.length?i.artwork_refs.length+' referencia(s)':'Sin archivo por ahora']];
    rows.forEach(function(row){if(!row[1])return;var p=document.createElement('p'),b=document.createElement('strong');b.textContent=row[0]+': ';p.append(b,document.createTextNode(row[1]));el.appendChild(p);});
    var link=form.querySelector('[data-quick-shop]');link.hidden=!selectedUrl;
    if(selectedUrl)link.href=selectedUrl;
  }
  function restore(data) {
    if(!data||!Array.isArray(data.items)||data.items.length!==1)return false;
    var i=data.items[0];
    Object.keys(i).forEach(function(k){var el=line.querySelector('[name="'+k+'"]');if(el&&typeof i[k]!=='object')el.value=i[k];});
    ['artwork_session','billing_profile_id','needed_by','urgency'].forEach(function(k){if(data[k]!=null)form.elements[k].value=data[k];});
    (i.artwork_refs||[]).forEach(function(ref){var li=document.createElement('li'),input=document.createElement('input'),b=document.createElement('button');input.type='hidden';input.name='items[0][artwork_refs][]';input.value=ref;li.textContent='Referencia guardada · '+ref.slice(0,8)+' ';b.type='button';b.dataset.geArtworkRemove='';b.textContent='Quitar vínculo';li.append(input,b);line.querySelector('[data-ge-artwork-list]').appendChild(li);});
    touched=true;return true;
  }
  async function save(draft) {
    if(cfg.preview||busy)return;
    if(!field('title').value||!field('quantity').checkValidity()) {show('quantity');notice.textContent='Indicá una cantidad para guardar.';return;}
    if(form.querySelector('[data-state="queued"],[data-state="uploading"],[data-state="error"]')) {show('artwork');notice.textContent='Terminá o reintentá las cargas antes de enviar.';return;}
    if(!draft&&step!=='review')return;
    busy=true;form.querySelector('[data-request-submit]').disabled=true;form.querySelector('[data-request-draft]').disabled=true;
    notice.textContent=draft?'Guardando borrador…':'Enviando solicitud…';
    var body=new FormData();body.set('action','ge_request_save');body.set('nonce',cfg.nonce);body.set('payload',JSON.stringify(payload()));if(draft)body.set('draft','1');
    try {var res=await fetch(cfg.url,{method:'POST',body:body,credentials:'same-origin'}),json=await res.json();if(!json.success)throw new Error(json.data.message);
      notice.textContent=draft?'Borrador guardado en Mis solicitudes.':'Solicitud recibida.';
      if(!draft){event('quote_request_submitted',{request_id:json.data.id});touched=false;try{localStorage.removeItem(storageKey);}catch(e){} window.location.assign(json.data.url);}
    } catch(e) {notice.textContent=e.message||'No se pudo enviar. Tu avance está guardado; reintentá.';}
    finally {busy=false;form.querySelector('[data-request-submit]').disabled=false;form.querySelector('[data-request-draft]').disabled=false;}
  }
  async function search() {
    var seq=++searchSeq,el=form.querySelector('[data-request-products]'),q=form.querySelector('[data-request-search]').value,cat=form.querySelector('[data-request-category]').value;
    el.textContent='Buscando productos…';event('product_search');
    var params=new URLSearchParams({action:'ge_request_catalog',nonce:cfg.nonce,q:q,category:cat});
    try {var res=await fetch(cfg.url+'?'+params,{credentials:'same-origin'}),json=await res.json();if(seq!==searchSeq)return;if(!json.success)throw new Error();el.replaceChildren();
      json.data.forEach(function(p){var card=document.createElement('article'),title=document.createElement('strong'),b=document.createElement('button');title.textContent=p.name;b.type='button';b.textContent='Pedir presupuesto';b.addEventListener('click',function(){field('product_id').value=p.id;field('title').value=p.name;field('category').value=cat;selectedUrl=p.url||'';form.querySelector('[data-quick-product]').textContent=p.name;touched=true;show('quantity');});card.append(title,b);
        if(p.url){var a=document.createElement('a');a.href=p.url;a.target='_blank';a.rel='noopener';a.textContent='Ver en tienda';a.addEventListener('click',function(){event('shop_product_clicked_from_quote',{product_id:p.id});});card.appendChild(a);}el.appendChild(card);});
      if(!json.data.length)el.textContent='No encontramos coincidencias. Podés contarnos tu idea.';
    } catch(e) {if(seq===searchSeq)el.textContent='No pudimos cargar el catálogo. Podés seguir describiendo tu idea.';}
  }
  form.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;
    if(b.dataset.quickMode){touched=true;if(b.dataset.quickMode==='custom'){field('product_id').value=0;selectedUrl='';show('description');}else{show('catalog');search();}}
    if(b.hasAttribute('data-quick-next'))next();
    if(b.hasAttribute('data-quick-back')&&history.length)show(history.pop(),true);
    if(b.hasAttribute('data-quick-edit'))show(Number(field('product_id').value)?'quantity':'description');
    if(b.hasAttribute('data-request-draft'))save(true);
  });
  form.addEventListener('submit',function(e){e.preventDefault();if(step==='review')save(false);else if(!['intent','catalog'].includes(step))next();});
  form.addEventListener('input',function(){touched=true;localSave();});form.addEventListener('change',localSave);
  document.addEventListener('ge:quote-lines-changed',function(){setTimeout(localSave,0);});
  var searchTimer;form.querySelector('[data-request-search]').addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(search,350);});
  form.querySelector('[data-request-category]').addEventListener('change',function(){event('category_selected',{category:this.value});search();});
  form.querySelector('[data-quick-shop]').addEventListener('click',function(){event('shop_product_clicked_from_quote',{product_id:Number(field('product_id').value)});});
  line.querySelector('[data-ge-artwork]').dataset.field='items[0][artwork_refs][]';
  new MutationObserver(localSave).observe(line.querySelector('[data-ge-artwork-list]'),{childList:true,subtree:true});
  if(cfg.draft&&cfg.draft.items.length===1){restore(cfg.draft);selectedUrl=cfg.draft.shop_url||'';show('review');}
  else if(!cfg.preview){try{var cached=JSON.parse(localStorage.getItem(storageKey));if(cached&&Date.now()-cached.at<86400000*7&&restore(cached.data)){selectedUrl=cached.selectedUrl||'';history=Array.isArray(cached.history)?cached.history:[];show(form.querySelector('[data-quick-step="'+cached.step+'"]')?cached.step:'description',true);notice.textContent='Retomamos tu solicitud guardada.';}}catch(e){}}
  if(!cfg.preview)event('quote_request_started');
  window.addEventListener('pagehide',localSave);
})();
