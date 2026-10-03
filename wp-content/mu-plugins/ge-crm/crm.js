(()=>{
  let dragging=null;
  const status=document.querySelector('#ge-crm-status');
  document.querySelectorAll('.ge-crm-card[draggable="true"]').forEach(card=>{
    card.addEventListener('dragstart',e=>{dragging=card;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',JSON.parse(card.dataset.record).id);});
    card.addEventListener('dragend',()=>{dragging=null;document.querySelectorAll('.is-drop-target').forEach(x=>x.classList.remove('is-drop-target'));});
  });
  document.querySelectorAll('.ge-crm-column').forEach(col=>{
    col.addEventListener('dragover',e=>{if(dragging){e.preventDefault();col.classList.add('is-drop-target');}});
    col.addEventListener('dragleave',()=>col.classList.remove('is-drop-target'));
    col.addEventListener('drop',async e=>{
      e.preventDefault();col.classList.remove('is-drop-target');if(!dragging)return;
      const card=dragging,r=JSON.parse(card.dataset.record);if(r.stage===col.dataset.stage)return;
      card.setAttribute('aria-busy','true');if(status)status.textContent='Guardando etapa…';
      try {
        const response=await fetch(window.geCRM.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':window.geCRM.nonce},body:JSON.stringify({...r,operation:'save',stage:col.dataset.stage})});
        const data=await response.json();if(!response.ok)throw Error(data.message||'No se pudo guardar');
        location.reload();
      }catch(err){card.removeAttribute('aria-busy');if(status)status.textContent=err.message;}
    });
  });
})();
