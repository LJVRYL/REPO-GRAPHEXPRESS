'use strict';
(()=>{
 const root=document.getElementById('ge-creator-app'),cfg=window.GE_CREATOR_PORTAL;if(!cfg||cfg.preview)return;
 document.querySelectorAll('[data-gc-fee-artist]').forEach(form=>form.onsubmit=async event=>{
  event.preventDefault();const status=document.getElementById('gc-fee-review-status'),button=form.querySelector('button');
  function cents(value){const raw=String(value).trim().replace(',','.');if(!/^\d+(?:\.\d{1,2})?$/.test(raw))throw Error('Usá hasta dos decimales.');const parts=raw.split('.');const amount=Number(parts[0])*100+Number((parts[1]||'').padEnd(2,'0'));if(!Number.isSafeInteger(amount))throw Error('Importe inválido.');return amount;}
  button.disabled=true;status.textContent='Guardando tarifa…';try{const response=await fetch(cfg.api+'/creator-marketplace-review',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf},body:JSON.stringify({artist_id:Number(form.dataset.gcFeeArtist),rate_bps:cents(form.elements.rate.value),fixed_cents:cents(form.elements.fixed.value),evidence_ref:form.elements.evidence.value})});if(!response.ok)throw Error('No pudimos guardar la tarifa. Revisá los valores y tu permiso de Administración.');status.textContent='Tarifa guardada. La configuración de cada obra se volverá a validar antes de vender.';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}
 });
 const review=document.getElementById('gc-review');if(review){review.querySelectorAll('[data-gc-review]').forEach(button=>button.onclick=async()=>{button.disabled=true;const status=document.getElementById('gc-review-status');status.textContent='Guardando revisión…';try{const response=await fetch(cfg.api+'/creator-review',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf},body:JSON.stringify({id:Number(button.dataset.id),revision:Number(button.dataset.revision),decision:button.dataset.gcReview})});if(!response.ok)throw Error('No pudimos guardar la revisión. Actualizá la página e intentá nuevamente.');button.closest('article').remove();status.textContent=button.dataset.gcReview==='approve'?'La obra está publicada.':'La obra volvió al autor para revisión.'}catch(error){status.textContent=error.message;button.disabled=false}});return;}if(!root)return;
 const $=id=>document.getElementById(id),labels={draft:'Privada',review:'En revisión',published:'Publicada',needs_changes:'Revisar detalles',needs_preview:'Falta vista previa'};
 const errors={storage_capacity:'El espacio disponible para tu galería llegó a su límite. Contactanos para ampliarlo.',busy:'Hay otra operación en curso. Probá nuevamente.',authentication_required:'Ingresá nuevamente para continuar.',rights_required:'Confirmá que podés compartir esta imagen.',invalid_title:'Escribí el título de tu obra.',invalid_gallery_title:'Escribí el nombre de tu galería.',invalid_work_details:'Revisá el título y la colección.',work_revision_changed:'La obra cambió en otra ventana. Actualizamos la lista; volvé a intentarlo.',analysis_required:'Primero completá el análisis de la imagen en tu biblioteca.',preview_required:'Todavía falta una vista previa para publicar esta obra.',drive_reconnect_required:'Volvé a conectar tu Drive para acceder a las imágenes.',drive_file_unavailable:'No podemos acceder a ese archivo. Revisá su acceso en Drive.',invalid_drive_image:'Elegí un JPG o PNG de hasta 64 MB.',preview_unavailable:'No pudimos preparar la miniatura. Probá con otra imagen.',rate_limit:'Esperá un momento antes de volver a intentarlo.',work_limit:'Llegaste al límite de 200 obras de esta galería.',collection_limit:'Podés organizar hasta 12 colecciones.',drive_busy:'Drive está terminando otra operación. Probá nuevamente.'};
 let current=null,busy=false,pickerLoading=null;
 async function api(path='',body){const response=await fetch(cfg.api+'/creator'+path,{credentials:'same-origin',cache:'no-store',method:body===undefined?'GET':'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf},...(body===undefined?{}:{body:JSON.stringify(body)})});const data=await response.json();if(!response.ok)throw Error(errors[data.code]||'No pudimos completar la acción. Probá nuevamente.');return data}
 function node(tag,text,className){const n=document.createElement(tag);if(text)n.textContent=text;if(className)n.className=className;return n}
 function button(text,handler){const b=node('button',text);b.type='button';b.onclick=handler;return b}
 async function act(action,target='gc-error'){if(busy)return;busy=true;$(target).textContent='';try{await action();await load();$('gc-status').textContent='Listo, guardamos tus cambios.'}catch(error){$(target).textContent=error.message}finally{busy=false}}
 function card(work){const a=node('article',null,'gc-card'),box=node('div',null,'gc-card-image'),image=node('img');image.src=work.preview;image.alt=work.title;image.loading='lazy';image.width=1000;image.height=1000;image.onerror=()=>{image.hidden=true;box.append(node('span','Vista previa pendiente'))};box.append(image);const body=node('div',null,'gc-card-body'),actions=node('div',null,'gc-card-actions');body.append(node('span',labels[work.status]||'Privada','gc-badge '+work.status),node('h3',work.title),node('p',(current.profile.collections[work.collection]||'Mis obras')+' · '+(work.source==='drive'?'Google Drive':'Mi biblioteca')));actions.append(button('Editar',()=>edit(work)));
  if(work.status!=='review'&&work.status!=='published')actions.append(button('Enviar a revisión',()=>act(()=>api('/work',{id:work.id,revision:work.revision,action:'submit',rights:true}))));
  if(work.published)actions.append(button('Retirar de la galería',()=>act(()=>api('/work',{id:work.id,revision:work.revision,action:'withdraw'}))));
  if(work.source==='drive')actions.append(button('Buscar nueva versión',()=>act(()=>api('/work',{id:work.id,revision:work.revision,action:'refresh-drive'}))));body.append(actions);a.append(box,body);return a}
 async function load(){current=await api();$('gc-count').textContent=current.works.length;$('gc-grid').replaceChildren(...current.works.map(card));$('gc-empty').hidden=!!current.works.length;$('gc-name').value=current.profile.title;$('gc-bio').value=current.profile.bio;$('gc-public').checked=current.profile.public;$('gc-collections').value=Object.values(current.profile.collections).join('\n');$('gc-library').replaceChildren(...current.library.map(item=>{const option=node('option',item.title);option.value=item.id;return option}));if(!current.library.length){const option=node('option','Todavía no tenés imágenes disponibles');option.value='';$('gc-library').append(option)}$('gc-library-add').disabled=!current.library.length;$('gc-public-link').href=current.gallery_url;$('gc-public-link').hidden=!current.profile.public||!current.works.some(w=>w.published);$('gc-drive-state').textContent=!current.drive_configured?'Drive estará disponible cuando terminemos de conectar el servicio. Podés usar tu biblioteca o subir una imagen.':current.drive_connected?'Tu Drive está conectado. Sólo accedemos a los archivos que elegís.':'Conectá tu Drive para elegir las imágenes que querés compartir.';$('gc-connect').hidden=!current.drive_configured||current.drive_connected;$('gc-drive-pick').hidden=!current.drive_configured||!current.drive_connected;$('gc-disconnect').hidden=!current.drive_configured||!current.drive_connected;}
 function importData(){const title=$('gc-import-title-input').value.trim(),rights=$('gc-rights').checked;if(!title)throw Error(errors.invalid_title);if(!rights)throw Error(errors.rights_required);return {title,rights,print_rights:$('gc-print-rights').checked}}
 function edit(work){$('gc-edit-print-rights').checked=work.print_rights===true;$('gc-edit-id').value=work.id;$('gc-edit-revision').value=work.revision;$('gc-edit-name').value=work.title;const rule=work.artist_price||{mode:'inherit'};$('gc-work-price-mode').value=rule.mode==='fixed'&&rule.value===0?'free':rule.mode;$('gc-work-price-value').value=Number.isInteger(rule.value)?(rule.value/100).toFixed(2):'';$('gc-edit-description').value=work.description;$('gc-edit-collection').replaceChildren(...Object.entries(current.profile.collections).map(([key,label])=>{const option=node('option',label);option.value=key;option.selected=work.collection===key;return option}));$('gc-edit-error').textContent='';$('gc-edit').showModal()}
 document.querySelectorAll('[data-gc-tab]').forEach(tab=>tab.onclick=()=>{document.querySelectorAll('[data-gc-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b===tab)));$('gc-works-panel').hidden=tab.dataset.gcTab!=='works';$('gc-settings').hidden=tab.dataset.gcTab!=='settings';$('gc-payments').hidden=tab.dataset.gcTab!=='payments';$('gc-artist-pricing').hidden=tab.dataset.gcTab!=='payments';if(tab.dataset.gcTab==='payments')loadPayments()});

 let paymentBusy=false,artistPricing=null;
 async function marketplace(path='',method='GET'){
  const response=await fetch(cfg.api+'/creator-marketplace'+path,{method,credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf}});
  const result=await response.json();if(!response.ok)throw Error('No pudimos consultar tu conexión. Actualizá la página y volvé a intentarlo.');return result;
 }
 async function loadPayments(){
  try{const data=await marketplace();renderPrices(data);loadSales();$('gc-mp-state').textContent=data.connected?(data.needs_reconnect?'Tu autorización necesita renovarse. Volvé a conectar tu cuenta.':'Cuenta '+data.account_id+' conectada'+(data.mode==='test'?' en modo de prueba.':'.')):data.configured?'Conectá tu Mercado Pago de Argentina. Vas a autorizar el acceso en su sitio.':'Estamos preparando la conexión de graphex.ar con Mercado Pago. Tu galería ya puede funcionar como exposición.';
   $('gc-mp-connect').hidden=!data.configured||(data.connected&&!data.needs_reconnect);$('gc-mp-disconnect').hidden=!data.connected;$('gc-mp-disconnect-note').hidden=!data.connected;
  }catch(error){$('gc-mp-error').textContent=error.message}
 }

 async function loadSales(){
  try{const data=await marketplace('/orders');$('gc-sales').replaceChildren();$('gc-sales-state').textContent=data.orders.length?'Tus últimas ventas. El importe corresponde a tu obra, antes de tus impuestos personales.':'Todavía no tenés ventas en tu galería.';
   const labels={paid:'Pago confirmado',pending:'Esperando pago',creating:'Preparando el pago',reversed:'Pago revertido',unpaid:'Pago no acreditado'};
   for(const sale of data.orders){const card=node('article',null,'gc-sale'),head=node('h4','Pedido '+sale.reference),amount=node('p',new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS'}).format(sale.artist_cents/100)),status=node('p',labels[sale.state]||'En revisión');
    card.append(head,amount,status);for(const item of sale.items)card.append(node('p',item.title+' · '+item.configuration+' · '+item.quantity+' unidad(es)'));$('gc-sales').append(card);}
  }catch{$('gc-sales-state').textContent='No pudimos consultar tus ventas. Actualizá la página para volver a intentar.';}
 }

 function readPrice(mode,text){
  if(mode==='inherit'||mode==='unset')return {mode};if(mode==='free')return {mode:'fixed',value:0};
  const raw=String(text).trim().replace(',','.');if(!/^\d+(?:\.\d{1,2})?$/.test(raw))throw Error('Escribí un valor positivo o cero, con hasta dos decimales.');
  const parts=raw.split('.'),value=Number(parts[0])*100+Number((parts[1]||'').padEnd(2,'0'));if(!Number.isSafeInteger(value)||value>(mode==='percent'?100000:100000000))throw Error('El valor supera el límite permitido.');return {mode,value};
 }
 function renderPrices(data){
  artistPricing=data.pricing;$('gc-price-mode').value=artistPricing.default.mode==='fixed'&&artistPricing.default.value===0?'free':artistPricing.default.mode;$('gc-price-value').value=Number.isInteger(artistPricing.default.value)?(artistPricing.default.value/100).toFixed(2):'';
  $('gc-model-prices').replaceChildren(...data.frame_models.map(model=>{const row=node('div',null,'gc-model-price'),label=node('label',model.name),select=node('select'),input=node('input');select.id='gc-model-price-'+model.id;label.htmlFor=select.id;select.dataset.model=model.id;select.setAttribute('aria-label','Modalidad para '+model.name);for(const [value,text] of [['inherit','Usar precio general'],['fixed','Importe fijo · pesos'],['percent','Porcentaje'],['free','Sin cargo por la obra']]){const option=node('option',text);option.value=value;select.append(option)}const rule=artistPricing.models[model.id]||{mode:'inherit'};select.value=rule.mode==='fixed'&&rule.value===0?'free':rule.mode;input.inputMode='decimal';input.setAttribute('aria-label','Importe o porcentaje para '+model.name);input.value=Number.isInteger(rule.value)?(rule.value/100).toFixed(2):'';row.append(label,select,input);return row}));
 }
 $('gc-artist-pricing').onsubmit=async event=>{event.preventDefault();if(paymentBusy||!artistPricing)return;paymentBusy=true;$('gc-price-save').disabled=true;$('gc-price-error').textContent='';try{
  const models={};for(const row of $('gc-model-prices').children){const select=row.querySelector('select');if(select.value!=='inherit')models[select.dataset.model]=readPrice(select.value,row.querySelector('input').value)}
  const response=await fetch(cfg.api+'/creator-marketplace/pricing',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf},body:JSON.stringify({revision:artistPricing.revision,default:readPrice($('gc-price-mode').value,$('gc-price-value').value),models})});const data=await response.json();if(!response.ok)throw Error(data.code==='artist_price_changed'?'Los precios cambiaron en otra ventana. Actualizá antes de guardar.':'Revisá los importes y las modalidades.');artistPricing=data;$('gc-price-status').textContent='Guardamos tus precios. Las ventas siguen pendientes de habilitación.';
  }catch(error){$('gc-price-error').textContent=error.message}finally{paymentBusy=false;$('gc-price-save').disabled=false}
 };
 $('gc-mp-disconnect').onclick=async()=>{if(paymentBusy)return;paymentBusy=true;$('gc-mp-disconnect').disabled=true;$('gc-mp-error').textContent='';try{await marketplace('/disconnect','POST');await loadPayments()}catch(error){$('gc-mp-error').textContent=error.message}finally{paymentBusy=false;$('gc-mp-disconnect').disabled=false}};
 const mpNotice=new URLSearchParams(location.search).get('mp');if(mpNotice){$('gc-mp-state').textContent=mpNotice==='connected'?'Cuenta conectada correctamente.':mpNotice==='cancelled'?'La autorización fue cancelada. Podés volver a intentarlo.':'No pudimos conectar tu cuenta. Volvé a intentarlo.';document.querySelector('[data-gc-tab="payments"]').click()}
 document.querySelectorAll('[data-gc-close]').forEach(button=>button.onclick=()=>$(button.dataset.gcClose).close());$('gc-add').onclick=()=>{$('gc-import-error').textContent='';$('gc-import').showModal()};
 $('gc-settings').onsubmit=event=>{event.preventDefault();act(()=>api('',{title:$('gc-name').value,bio:$('gc-bio').value,public:$('gc-public').checked,collections:$('gc-collections').value.split('\n')}))};
 $('gc-edit-form').onsubmit=event=>{event.preventDefault();act(async()=>{await api('/work',{id:Number($('gc-edit-id').value),revision:Number($('gc-edit-revision').value),action:'save',title:$('gc-edit-name').value,description:$('gc-edit-description').value,collection:$('gc-edit-collection').value,artist_price:readPrice($('gc-work-price-mode').value,$('gc-work-price-value').value),print_rights:$('gc-edit-print-rights').checked});$('gc-edit').close()},'gc-edit-error')};
 $('gc-library-add').onclick=()=>act(async()=>{const data=importData();await api('/from-library',{...data,artwork_id:Number($('gc-library').value)});$('gc-import').close()},'gc-import-error');
 $('gc-disconnect').onclick=()=>act(()=>api('/disconnect',{}),'gc-import-error');
 async function picker(){const data=await api('/picker',{});if(!pickerLoading)pickerLoading=new Promise((resolve,reject)=>{const script=document.createElement('script');script.src='https://apis.google.com/js/api.js';script.onload=()=>window.gapi.load('picker',{callback:resolve,onerror:reject});script.onerror=reject;document.head.append(script)});try{await pickerLoading}catch(error){pickerLoading=null;throw Error('No pudimos abrir Drive. Probá nuevamente.')}new google.picker.PickerBuilder().setOAuthToken(data.access_token).setDeveloperKey(data.developer_key).setAppId(data.app_id).setOrigin(location.origin).addView(new google.picker.DocsView().setMimeTypes('image/jpeg,image/png')).setCallback(result=>{if(result.action===google.picker.Action.PICKED){const doc=result.docs[0];act(async()=>{const details=importData();await api('/import-drive',{...details,file_id:doc.id});$('gc-import').close()},'gc-import-error')}}).build().setVisible(true)}
 $('gc-drive-pick').onclick=()=>{try{importData();picker().catch(error=>{$('gc-import-error').textContent=error.message||'No pudimos abrir Drive. Probá nuevamente.'})}catch(error){$('gc-import-error').textContent=error.message}};
 async function response(response){const data=await response.json();if(!response.ok)throw Error(errors[data.code]||data.data?.message||'No pudimos subir la imagen.');return data}
 // Selecting a file never starts a transfer before the artist confirms the details.
 // Old HTML can still be in flight while the new script is published.
 const fileInput=$('gc-file');
 if(!$('gc-file-status')){const hint=node('p','Elegí un archivo y completá los datos antes de subirlo.');hint.id='gc-file-status';hint.setAttribute('role','status');fileInput.insertAdjacentElement('afterend',hint);}
 if(!$('gc-upload-submit')){const submit=node('button','Subir a mi galería','gc-primary');submit.id='gc-upload-submit';submit.type='button';submit.disabled=!fileInput.files.length;$('gc-file-status').insertAdjacentElement('afterend',submit);}
 let pendingUpload=null;
 $('gc-file').onchange=()=>{
  const file=$('gc-file').files[0];pendingUpload=null;$('gc-import-error').textContent='';$('gc-upload-progress').textContent='';
  $('gc-file-status').textContent=file?'Archivo elegido: '+file.name+'. Completá los datos y pulsá Subir a mi galería.':'Elegí un JPG o PNG de hasta 20 MB.';
  $('gc-upload-submit').disabled=!file;
 };
 $('gc-upload-submit').onclick=async()=>{
  if(busy)return;const submit=$('gc-upload-submit'),input=$('gc-file');submit.disabled=true;input.disabled=true;submit.textContent='Preparando carga…';
  try{await act(async()=>{
   const details=importData(),file=input.files[0];if(!file)throw Error('Elegí una imagen para subir.');
   if(!['image/jpeg','image/png'].includes(file.type)||file.size>20*1024*1024||file.size===0)throw Error('Elegí un JPG o PNG de hasta 20 MB.');
   if(!pendingUpload||pendingUpload.file!==file)pendingUpload={file,item:null,uploaded:false,job:null};
   const state=pendingUpload;$('gc-upload-progress').textContent=state.job?'Retomando el análisis de tu imagen…':'Subiendo tu imagen…';
   const headers={'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'X-CSRF-Token':cfg.csrf};
   if(!state.item){
    const session=await fetch(cfg.api+'/session',{credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce}}).then(response);
    if(!session.upload)throw Error(errors.authentication_required);
    const prep=await fetch(session.upload.ajaxUrl,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'ge_vps_prepare_upload',nonce:session.upload.nonce,files:JSON.stringify([{name:file.name,size:file.size,type:file.type}])})}).then(response);
    if(!prep.success)throw Error(prep.data?.message||'No pudimos preparar la carga.');state.item=prep.data.uploads[0];
   }
   if(!state.uploaded){
    try{const uploaded=await fetch(state.item.url,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':file.type},body:file}).then(response);if(!uploaded.success)throw Error('No pudimos guardar la imagen.');state.uploaded=true;}
    catch(error){state.item=null;throw error;}
   }
   if(!state.job)state.job=await fetch(cfg.api+'/uploads',{method:'POST',credentials:'same-origin',headers,body:JSON.stringify({token:state.item.token})}).then(response);
   $('gc-upload-progress').textContent='Analizando tu imagen…';submit.textContent='Analizando imagen…';let result;
   for(let i=0;i<100;i++){
    result=await fetch(cfg.api+'/uploads/'+state.job.id,{credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce}}).then(response);
    if(result.status==='failed')throw Error('No pudimos analizar la imagen. Elegí otro archivo.');if(result.status==='ready')break;
    await new Promise(resolve=>setTimeout(resolve,1500));
   }
   if(result?.status!=='ready')throw Error('El análisis sigue en curso. Pulsá Subir a mi galería para consultar el mismo archivo, o volvé más tarde a tu biblioteca.');
   await api('/from-library',{...details,artwork_id:result.artwork_library_id});
   pendingUpload=null;input.value='';$('gc-file-status').textContent='Elegí un archivo para agregar otra obra.';$('gc-import').close();$('gc-upload-progress').textContent='';
   $('gc-import-title-input').value='';$('gc-rights').checked=false;$('gc-print-rights').checked=false;
  },'gc-import-error');}
  finally{input.disabled=false;submit.disabled=!input.files.length;submit.textContent='Subir a mi galería';if($('gc-import-error').textContent)$('gc-import-error').focus();}
 };
 load().then(()=>{$('gc-status').textContent='Tus imágenes y tu presentación, en un solo lugar.'}).catch(error=>{$('gc-error').textContent=error.message;$('gc-status').textContent=''});
})();
