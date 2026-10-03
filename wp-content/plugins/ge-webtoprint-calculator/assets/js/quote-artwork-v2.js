(function () {
  'use strict';
  var config = window.geQuoteArtworkV2;
  if(config) document.addEventListener('submit',function(event){
    var target=event.target;
    if(target.matches('form.ge-commercial-quote'))return;
    var inputs=target.querySelectorAll('input[type=file][name]');var total=0;var oversized=false;
    inputs.forEach(function(input){Array.from(input.files).forEach(function(file){total+=file.size;if(file.size>config.postMax-1048576)oversized=true;});});
    if(oversized || total>config.postMax-1048576){event.preventDefault();var notice=target.querySelector('[data-ge-upload-limit]');if(!notice){notice=document.createElement('p');notice.dataset.geUploadLimit='1';notice.setAttribute('role','alert');target.appendChild(notice);}notice.textContent='Esta carga admite hasta '+Math.floor((config.postMax-1048576)/1048576)+' MiB en total. Para artes grandes, usá Editar presupuesto y Archivos / Arte.';}
  },true);
  var form = document.querySelector('form.ge-commercial-quote, form.ge-request-form');
  if (!config || !form) return;
  var session = form.querySelector('[name="artwork_session"]').value;
  var queue = Promise.resolve();
  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var n = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
      return (c === 'x' ? n : (n & 3) | 8).toString(16);
    });
  }
  function request(op, row, extra, blob, progress) {
    return new Promise(function (resolve, reject) {
      var data = new FormData();
      var fields = Object.assign({action:'ge_quote_artwork_v2', nonce:config.nonce, op:op, upload_id:row.uploadId, session_id:form.querySelector('[name="artwork_session"]').value, quote_id:config.quoteId,request_context:form.matches('.ge-request-form')?'1':'',request_nonce:window.geRequest?window.geRequest.nonce:''}, extra || {});
      Object.keys(fields).forEach(function(key){data.append(key,fields[key]);});
      if (blob) data.append('chunk', blob, 'chunk.bin');
      var xhr = new XMLHttpRequest(); row.xhr = xhr;
      xhr.open('POST', config.url); xhr.timeout = 120000;
      xhr.upload.onprogress = function(event){if(progress && event.lengthComputable) progress(event.loaded / event.total);};
      xhr.onload = function () {
        var result;
        try { result = JSON.parse(xhr.responseText); } catch(error){reject(new Error('No se pudo completar la carga. Reintentá; si persiste, contactá al equipo.')); return;}
        if (!result.success) {reject(new Error((result.data && result.data.message) || 'No se pudo cargar el archivo.')); return;}
        resolve(result.data);
      };
      xhr.onerror = xhr.ontimeout = function(){reject(new Error('La conexión se interrumpió. Usá Reintentar para continuar.'));};
      xhr.onabort = function(){reject(new Error('Carga cancelada.'));};
      xhr.send(data);
    });
  }
  function initialize(block) {
    if (block.dataset.connected) return;
    block.dataset.connected = '1';
    var line = block.closest('[data-ge-line]');
    if(line && !line.querySelector('[data-ge-line-uuid]').value) line.querySelector('[data-ge-line-uuid]').value = uuid();
    var input = block.querySelector('[data-ge-artwork-select]');
    var list = block.querySelector('[data-ge-artwork-list]');
    var notice = block.querySelector('[data-ge-artwork-notice]');
    var drop = block.querySelector('.ge-qav2-drop');
    function select(files) {
      Array.from(files).forEach(function(file){
        var item = document.createElement('li'); var name = document.createElement('strong');name.textContent=file.name;
        var state = document.createElement('small');state.setAttribute('aria-live','polite');state.textContent=(file.size/1048576).toFixed(1)+' MiB · En cola';
        var meter = document.createElement('progress');meter.max=100;meter.value=0;meter.setAttribute('aria-label','Progreso de '+file.name);
        var remove = document.createElement('button');remove.type='button';remove.textContent='Quitar';
        var retry = document.createElement('button');retry.type='button';retry.textContent='Reintentar';retry.hidden=true;
        item.append(name,state,meter,remove,retry);list.appendChild(item);
        var row={file:file,uploadId:uuid(),source:block.querySelector('[data-ge-artwork-source]').value,cancelled:false};
        item.dataset.state='queued';
        remove.addEventListener('click',function(){row.cancelled=true;if(row.xhr)row.xhr.abort();item.remove();});
        function run() {
          if(row.cancelled || !block.isConnected) return Promise.resolve();
          item.dataset.state='uploading';retry.hidden=true;
          state.textContent='Subiendo…';
          return request('start',row,{name:file.name,size:file.size,source_type:row.source}).then(async function (start) {
            if(row.cancelled)return;
            var record=start.record;var offset=start.offset;
            if (!record) {
              while(offset<file.size){
                if(row.cancelled || !block.isConnected)return;
                var end=Math.min(offset+config.chunkSize,file.size);
                var result=await request('chunk',row,{offset:offset},file.slice(offset,end),function(fraction){meter.value=100*(offset+(end-offset)*fraction)/file.size;});
                offset=result.offset;meter.value=100*offset/file.size;
              }
              state.textContent='Verificando archivo…';
              record=(await request('finish',row)).record;
            }
            if(row.cancelled || !block.isConnected)return;
            var reference=document.createElement('input');reference.type='hidden';reference.name=block.dataset.field;reference.value=record.id;item.appendChild(reference);
            item.dataset.state='ready';meter.value=100;state.textContent=(file.size/1048576).toFixed(1)+' MiB · Subido'+(record.file_analysis_ref?' · Análisis en cola':'');
          }).catch(function(error){if(row.cancelled)return;item.dataset.state='error';state.textContent=error.message;retry.hidden=false;});
        }
        function schedule(){item.dataset.state='queued';queue=queue.then(run,run);}
        retry.addEventListener('click',schedule);
        if(!file.size || file.size>config.maxFile){item.dataset.state='error';state.textContent=!file.size?'El archivo está vacío. Elegí un archivo con contenido.':'Este archivo supera el límite de 250 MiB por archivo. Podés asociarlo desde Google Drive, Dropbox, WeTransfer u otro enlace.'; var link=document.createElement('button');link.type='button';link.textContent='Agregar link';link.onclick=function(){item.remove();block.querySelector('[data-ge-add-link]').click();};item.appendChild(link);meter.hidden=true;return;}
        if(!/\.(pdf|jpe?g|png|tiff?|ai|eps|psd|zip)$/i.test(file.name)){item.dataset.state='error';state.textContent='Formato no permitido. Quitalo y elegí otro.';meter.hidden=true;return;}
        if(config.chunkSize<1){item.dataset.state='error';state.textContent='La carga no está disponible. Contactá al equipo.';return;}
        if(form.querySelectorAll('[data-ge-artwork-list] li').length>30){item.dataset.state='error';state.textContent='Máximo 30 archivos por presupuesto. Quitá un archivo antes de continuar.';return;}
        schedule();
      });
      var total=Array.from(form.querySelectorAll('[data-ge-artwork-list] li')).length;
      notice.textContent=total+' archivos en el presupuesto. Cada archivo se envía por separado; el total no consume el límite de un POST.';
    }
    input.addEventListener('change',function(){select(input.files);input.value='';});
    drop.addEventListener('dragover',function(event){event.preventDefault();drop.classList.add('is-dragging');});
    drop.addEventListener('dragleave',function(){drop.classList.remove('is-dragging');});
    drop.addEventListener('drop',function(event){event.preventDefault();drop.classList.remove('is-dragging');select(event.dataTransfer.files);});
    block.addEventListener('click',function(event){var button=event.target.closest('[data-ge-artwork-remove]');if(button)button.closest('li').remove();});
  }
  function initializeAll(){form.querySelectorAll('[data-ge-artwork]').forEach(initialize);}
  initializeAll();
  new MutationObserver(initializeAll).observe(form.querySelector('[data-ge-lines], [data-request-items]'),{childList:true,subtree:true});
  form.addEventListener('submit',function(event){
    var pending=form.querySelector('[data-state="queued"],[data-state="uploading"],[data-state="error"]');
    if(pending){event.preventDefault();var block=pending.closest('[data-ge-artwork]');block.querySelector('[data-ge-artwork-notice]').textContent=pending.dataset.state==='error'?'Reintentá o quitá el archivo con error antes de guardar.':'Esperá a que terminen las cargas antes de guardar.';pending.scrollIntoView({block:'center',behavior:'smooth'});return;}
    var total=Array.from(new FormData(form).values()).reduce(function(size,value){return size+(typeof value==='string'?new TextEncoder().encode(value).length: value.size)+256;},0);
    if(config.postMax && total>config.postMax-1048576){event.preventDefault();alert('El formulario supera el límite del servidor. Reducí los datos antes de guardar.');}
  });
}());
