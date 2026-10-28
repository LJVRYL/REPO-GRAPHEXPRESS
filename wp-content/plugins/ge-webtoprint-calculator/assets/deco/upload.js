'use strict';
(()=>{
 // Bound both headers and body parsing: a stalled response must release the upload UI.
 async function uploadFetch(url,options={},timeoutMs=20000){
  const controller=new AbortController();let timer;
  try{return await Promise.race([
   fetch(url,{...options,signal:controller.signal}).then(async response=>{let data;try{data=await response.json()}catch{throw Error('No pudimos leer la respuesta del servidor. Volvé a intentar.')}return {ok:response.ok,status:response.status,json:async()=>data}}),
   new Promise((resolve,reject)=>{timer=setTimeout(()=>{reject(Error('El servidor está demorando más de lo habitual. Podés volver a intentar sin perder esta carga.'));controller.abort()},timeoutMs)})
  ])}finally{clearTimeout(timer)}
 }

 const runtime=window.GRAPHEX_RUNTIME;
 function request(url,options={}){if(runtime){url=runtime.apiBase+url.slice(4);options.headers={...options.headers,'X-WP-Nonce':runtime.wpNonce}}return uploadFetch(url,{credentials:'same-origin',...options})}
 const $=id=>document.getElementById(id),MAX=20*1024*1024;
 let sessionPromise=null,busy=false,previewUrl=null,authenticated=false,pendingUpload=null;
 const errors={authentication_required:'Ingresá a tu cuenta de Graphex para subir tu imagen.',size_limit:'La imagen supera los 20 MB. Elegí una versión más liviana.',unsupported_type:'Elegí una imagen JPG o PNG.',invalid_upload:'No pudimos recibir el archivo completo. Probá nuevamente.',rate_limit:'Esperá un momento antes de volver a subir una imagen.',busy:'Estamos terminando otro análisis. Probá nuevamente en un momento.',insufficient_resolution:'Esta combinación no alcanza la definición necesaria. Elegí un tamaño menor.',analysis_required:'Volvé a subir la imagen para verificar esta combinación.',session_required:'La sesión de tu imagen terminó. Volvé a subirla.',invalid_selection:'Revisá la combinación elegida e intentá nuevamente.'};
 async function responseJSON(response){let data;try{data=await response.json()}catch{throw Error('El análisis no está disponible en este momento. Probá nuevamente más tarde.')}if(!response.ok)throw Error(errors[data.error||data.code]||'No pudimos completar la operación. Probá nuevamente.');return data}
 function session(){if(!sessionPromise)sessionPromise=request('/api/session',{credentials:'same-origin',cache:'no-store'}).then(responseJSON).catch(e=>{sessionPromise=null;throw e});return sessionPromise}
 function ready(w){document.dispatchEvent(new CustomEvent('graphex:upload-ready',{detail:w}))}
 function status(text,isBusy){$('uploadStatus').textContent=text;$('uploadStatus').classList.toggle('busy',isBusy)}
 function resetError(){$('uploadError').textContent='';$('retryUpload').hidden=true}
 function localPreview(file){if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl=URL.createObjectURL(file);$('uploadPreview').src=previewUrl;$('uploadPreview').hidden=false;$('uploadPlaceholder').hidden=true}
 async function nativeUpload(file,s){
  if(!s.upload)throw Error(errors.authentication_required);
  if(pendingUpload.uploaded)return request('/api/uploads',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrf},body:JSON.stringify({token:pendingUpload.descriptor.token})}).then(responseJSON);
  const form=new URLSearchParams({action:'ge_vps_prepare_upload',nonce:s.upload.nonce,files:JSON.stringify([{name:file.name,size:file.size,type:file.type}])});
  const preparation=await uploadFetch(s.upload.ajaxUrl,{method:'POST',credentials:'same-origin',body:form}).then(response=>response.json());
  if(!preparation.success)throw Error(preparation.data?.message||'No pudimos preparar la carga.');
  const descriptor=preparation.data.uploads[0];pendingUpload.descriptor=descriptor;
  const put=await uploadFetch(descriptor.url,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':file.type},body:file},90000);
  const uploaded=await put.json();if(!put.ok||!uploaded.success)throw Error(uploaded.data?.message||'No pudimos guardar la imagen.');
  pendingUpload.uploaded=true;return request('/api/uploads',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrf},body:JSON.stringify({token:descriptor.token})}).then(responseJSON);
 }
 async function upload(file){if(busy||!file)return;if(!authenticated){$('uploadError').textContent=errors.authentication_required;return}resetError();if(!file.size){$('uploadError').textContent='El archivo está vacío. Elegí otra imagen.';return}if(file.size>MAX){$('uploadError').textContent=errors.size_limit;return}if(!['image/jpeg','image/png'].includes(file.type)&&!/[.](jpe?g|png)$/i.test(file.name)){$('uploadError').textContent=errors.unsupported_type;return}if(pendingUpload?.file!==file)pendingUpload={file,job:null,uploaded:false};busy=true;$('uploadFile').disabled=true;localPreview(file);status('Preparando tu imagen…',true);try{
  const s=await session();status('Subiendo tu imagen…',true);const job=pendingUpload.job||(runtime?await nativeUpload(file,s):await request('/api/uploads',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':s.csrf,'X-File-Name':encodeURIComponent(file.name),'Content-Type':file.type||'application/octet-stream'},body:file}).then(responseJSON));pendingUpload.job=job;
  const deadline=Date.now()+150000;let result=null;while(Date.now()<deadline){const r=await request('/api/uploads/'+job.id,{credentials:'same-origin',cache:'no-store'}).then(responseJSON);status(r.stage||'Analizando tu imagen…',r.status!=='ready'&&r.status!=='failed');if(r.status==='failed'){pendingUpload=null;throw Error(r.error==='invalid_image'?'No pudimos leer esta imagen. Elegí un JPG o PNG válido y estático.':'No pudimos completar el análisis. Probá nuevamente.');}if(r.status==='ready'){result=r;break}await new Promise(resolve=>setTimeout(resolve,1200))}
  if(!result)throw Error('El análisis está demorando más de lo habitual. Probá nuevamente en un momento.');ready(result.work);pendingUpload=null;if(!result.has_sizes){status('Imagen analizada.',false);throw Error('La imagen es demasiado pequeña para los tamaños disponibles. Probá una imagen de mayor resolución.')}status('Tu imagen está lista. Elegí cómo querés verla.',false);location.hash='#cuadro/'+result.work.id;
 }catch(e){status('',false);$('uploadError').textContent=e.message||'No pudimos analizar esta imagen. Probá nuevamente.';$('retryUpload').hidden=false}finally{busy=false;$('uploadFile').disabled=!authenticated;$('uploadFile').value=''}}
 $('uploadFile').onchange=()=>upload($('uploadFile').files[0]);$('retryUpload').onclick=()=>pendingUpload?upload(pendingUpload.file):$('uploadFile').click();
 const zone=$('dropZone');for(const type of ['dragenter','dragover'])zone.addEventListener(type,event=>{event.preventDefault();if(!busy)zone.classList.add('dragging')});for(const type of ['dragleave','drop'])zone.addEventListener(type,event=>{event.preventDefault();zone.classList.remove('dragging')});zone.addEventListener('drop',event=>{if(event.dataTransfer?.files?.length)upload(event.dataTransfer.files[0])});
 window.GraphexUpload={verifySelection:async(workId,selection)=>{const s=await session();return request('/api/selection',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':s.csrf},body:JSON.stringify({work_id:workId,...selection})}).then(responseJSON)}};
 if(runtime){for(const link of $('uploadAccount').querySelectorAll('a'))link.href=runtime.accountUrl;}
 session().then(s=>{authenticated=s.authenticated===true;$('uploadAccount').hidden=authenticated;$('dropZone').hidden=!authenticated;$('uploadFile').disabled=!authenticated;if(!authenticated)return {ok:true,json:async()=>({works:[]})};return request('/api/uploads',{credentials:'same-origin',cache:'no-store'})}).then(responseJSON).then(data=>data.works.forEach(ready)).catch(()=>{});
})();