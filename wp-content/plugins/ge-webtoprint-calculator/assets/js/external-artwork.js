(function(){
  'use strict';
  var config=window.geExternalArtwork;if(!config)return;
  var largeMessage='Este archivo supera el límite de carga directa. Podés asociarlo desde Google Drive, Dropbox, WeTransfer u otro enlace.';
  function uuid(){return crypto.randomUUID?crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var n=crypto.getRandomValues(new Uint8Array(1))[0]&15;return(c==='x'?n:(n&3)|8).toString(16);});}
  function request(fields){var data=new FormData();Object.keys(fields).forEach(function(k){data.append(k,fields[k]);});if(window.geRequest){data.append('request_context','1');data.append('request_nonce',window.geRequest.nonce);}data.append('action','ge_external_artwork');data.append('nonce',config.nonce);return fetch(config.url,{method:'POST',body:data,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){if(!r.success){var e=new Error(r.data.message||'No se pudo completar la acción.');e.record=r.data.record;throw e;}return r.data;});}
  function providerLabel(p){return({google_drive:'Google Drive',dropbox:'Dropbox',wetransfer:'WeTransfer',onedrive:'OneDrive',generic:'Enlace externo'})[p]||'Enlace externo';}
  function state(r){return r.imported_file_id?'Importado · origen conservado':(r.access_status==='requires_access'?'Requiere acceso':r.access_status==='unavailable'?'No disponible':'Acceso no verificado')+' · No analizado / archivo externo';}
  function button(text,attr){var b=document.createElement('button');b.type='button';b.textContent=text;b.setAttribute(attr,'');return b;}
  function hidden(block,r,item){var input=document.createElement('input');input.type='hidden';input.name=block.dataset.field;input.value=r.id;item.appendChild(input);}
  function render(block,r,local){
    var li=document.createElement('li');li.className='ge-external-entry';li.dataset.geExternalId=r.id;li.dataset.quoteId=(window.geQuoteArtworkV2||{}).quoteId||0;li.dataset.orderId=0;
    var a=document.createElement('a');a.href=r.url;a.target='_blank';a.rel='noopener noreferrer';a.textContent='↗ '+r.name;
    var small=document.createElement('small');small.textContent=providerLabel(r.provider)+' · '+state(r);var actions=document.createElement('div');actions.className='ge-external-actions';
    var open=a.cloneNode(true);open.textContent='Abrir archivo externo';var copy=button('Copiar link','data-ge-copy-link');copy.dataset.geCopyLink=r.url;
    actions.append(open,copy);if(!r.imported_file_id&&!window.geRequest)actions.append(button('Importar a Graphex','data-ge-import-link'));actions.append(button('Quitar vínculo','data-ge-artwork-remove'));
    li.append(a,small);if(r.notes){var note=document.createElement('small');note.textContent=r.notes;li.append(note);}li.append(actions);hidden(block,r,li);
    var notice=document.createElement('small');notice.dataset.geExternalNotice='';notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');li.append(notice);block.querySelector('[data-ge-artwork-list]').append(li);return li;
  }
  function renderLocal(block,r){if(!block||!r||block.querySelector('input[value="'+r.id+'"]'))return;var li=document.createElement('li');var strong=document.createElement('strong');strong.textContent=r.name;var small=document.createElement('small');small.textContent=(r.size/1048576).toFixed(1)+' MiB · Importado · Análisis en cola';li.append(strong,small,button('Quitar vínculo','data-ge-artwork-remove'));hidden(block,r,li);block.querySelector('[data-ge-artwork-list]').append(li);}
  document.addEventListener('click',async function(event){
    var target=event.target.closest('button');if(!target)return;
    var block=target.closest('[data-ge-artwork]');var form=block&&block.querySelector('[data-ge-link-form]');
    if(target.hasAttribute('data-ge-add-link')){form.hidden=false;block.querySelector('[data-ge-link-url]').focus();return;}
    if(target.hasAttribute('data-ge-cancel-link')){form.hidden=true;block.querySelector('[data-ge-add-link]').focus();return;}
    if(target.hasAttribute('data-ge-save-link')){
      var url=block.querySelector('[data-ge-link-url]');var error=block.querySelector('[data-ge-link-error]');var quoteForm=block.closest('form');error.textContent='';
      try{var parsed=new URL(url.value.trim());if(!/^https?:$/.test(parsed.protocol)||parsed.username||parsed.password)throw new Error();}catch(e){error.textContent='Ingresá una URL http o https válida.';url.focus();return;}
      if(quoteForm.querySelectorAll('[data-ge-artwork-list] li').length>=30){error.textContent='Máximo 30 referencias por presupuesto.';return;}
      target.disabled=true;target.textContent='Guardando…';form.dataset.state='uploading';
      var id=form.dataset.refId||(form.dataset.refId=uuid());
      try{var result=await request({op:'add',ref_id:id,quote_id:window.geQuoteArtworkV2.quoteId,session_id:quoteForm.querySelector('[name="artwork_session"]').value,url:url.value.trim(),name:block.querySelector('[data-ge-link-name]').value,notes:block.querySelector('[data-ge-link-notes]').value});render(block,result.record);delete form.dataset.refId;form.hidden=true;url.value='';block.querySelector('[data-ge-link-name]').value='';block.querySelector('[data-ge-link-notes]').value='';block.querySelector('[data-ge-add-link]').focus();}
      catch(e){error.textContent=e.message;}finally{target.disabled=false;target.textContent='Guardar enlace';delete form.dataset.state;}return;
    }
    if(target.hasAttribute('data-ge-copy-link')){var entry=target.closest('[data-ge-external-id]');try{await navigator.clipboard.writeText(target.dataset.geCopyLink);entry.querySelector('[data-ge-external-notice]').textContent='Link copiado.';}catch(e){entry.querySelector('[data-ge-external-notice]').textContent='Abrí el enlace y copialo desde la barra de dirección.';}return;}
    if(target.hasAttribute('data-ge-import-link')){
      var entry=target.closest('[data-ge-external-id]');var notice=entry.querySelector('[data-ge-external-notice]');var quoteForm=block&&block.closest('form');
      target.disabled=true;entry.dataset.state='uploading';notice.textContent='Importando archivo al almacenamiento privado…';
      try{var result=await request({op:'import',ref_id:entry.dataset.geExternalId,quote_id:entry.dataset.quoteId||0,order_id:entry.dataset.orderId||0,session_id:quoteForm?quoteForm.querySelector('[name="artwork_session"]').value:''});entry.querySelector('small').textContent=providerLabel(result.record.provider)+' · '+state(result.record);target.hidden=true;notice.textContent='Importado. File Analyzer revisará el archivo automáticamente.';renderLocal(block,result.local);if(!block)location.reload();}
      catch(e){notice.textContent=e.message;if(e.record)entry.querySelector('small').textContent=providerLabel(e.record.provider)+' · '+state(e.record);}
      finally{target.disabled=false;delete entry.dataset.state;}return;
    }
  });
  // Legacy multipart protection and a concrete escape path to the item editor.
  document.addEventListener('submit',function(event){var form=event.target;if(form.matches('form.ge-commercial-quote'))return;var cfg=window.geQuoteArtworkV2;if(!cfg)return;var size=0;form.querySelectorAll('input[type=file][name]').forEach(function(input){Array.from(input.files).forEach(function(f){size+=f.size;});});if(size>cfg.postMax-1048576){event.preventDefault();setTimeout(function(){var notice=form.querySelector('[data-ge-upload-limit]');if(!notice)return;notice.textContent=largeMessage;var a=document.createElement('a');a.textContent='Agregar link';var id=cfg.quoteId;a.href='/gestion/?section=quotes&quote_id='+id+'&edit=1';notice.append(' ',a);},0);}},true);
}());
