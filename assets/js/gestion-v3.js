(() => {
 'use strict';
 const init=()=>{
  const menu=document.querySelector('.ge-v3-menu'), sidebar=document.querySelector('.ge-v3-sidebar');
  const closeNav=()=>{if(!menu)return;if(sidebar.contains(document.activeElement))menu.focus();menu.setAttribute('aria-expanded','false');sidebar.classList.remove('is-open');};
  menu?.addEventListener('click',()=>{const open=menu.getAttribute('aria-expanded')!=='true';menu.setAttribute('aria-expanded',String(open));sidebar.classList.toggle('is-open',open);});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeNav();document.querySelectorAll('.ge-v3-topbar details[open],.ge-v3-advanced[open]').forEach(d=>{d.open=false;d.querySelector('summary')?.focus();});}});
  document.addEventListener('click',e=>{if(menu&&!sidebar.contains(e.target)&&!menu.contains(e.target))closeNav();});
  document.querySelectorAll('.ge-order-filters,.ge-queue-search,.ge-v3-toolbar').forEach(form=>{
   const section=form.querySelector('input[name=section]')?.value;
   if(['orders','quotes','production'].includes(section)){
    const label=document.createElement('label');label.className='ge-v3-sort';const text=document.createElement('span');text.textContent='Orden';const select=document.createElement('select');select.name='sort';[['newest','Más recientes'],['oldest','Más antiguos']].forEach(([value,title])=>{const option=document.createElement('option');option.value=value;option.textContent=title;select.append(option);});select.value=new URL(location.href).searchParams.get('sort')==='oldest'?'oldest':'newest';label.append(text,select);form.querySelector('button[type=submit]')?.before(label);
   }
  });
  document.querySelectorAll('.ge-order-filters').forEach(form=>{
   const labels=[...form.querySelectorAll(':scope > label')].filter(l=>l.querySelector('select'));
   if(labels.length){const advanced=document.createElement('details');advanced.className='ge-v3-advanced';const summary=document.createElement('summary');const count=labels.filter(l=>l.querySelector('select').value!==''&&l.querySelector('select').name!=='sort').length;summary.textContent=`Filtros${count?' · '+count:''}`;advanced.append(summary);const panel=document.createElement('div');labels.forEach(l=>panel.append(l));advanced.append(panel);form.querySelector('button[type=submit]')?.before(advanced);}
   if(!form.querySelector('.is-search'))form.querySelector('label')?.classList.add('is-search');
  });
  document.querySelectorAll('.ge-order-filters,.ge-queue-search,.ge-v3-toolbar').forEach((form,index)=>{
   const search=form.querySelector('input[type=search]');
   if(search){search.id||=`ge-local-search-${index}`;const label=form.querySelector(`label[for="${search.id}"]`)||search.closest('label');if(label){if(!label.contains(search))label.setAttribute('for',search.id);}else search.setAttribute('aria-label','Buscar en esta sección');}
   const fields=[...form.querySelectorAll('input:not([type=hidden]),select,input[name=quote_status],input[name=filter]')].filter(i=>i.value&&i.name!=='paged'&&i.name!=='sort'&&!(i.name==='filter'&&i.value==='active'));
   if(fields.length){const badge=document.createElement('span');badge.className='ge-v3-filter-count';badge.textContent=`${fields.length} activo${fields.length===1?'':'s'}`;form.append(badge);}
   if(fields.length&&!form.querySelector('a')){const reset=document.createElement('a');reset.className='ge-v3-filter-reset';reset.textContent='Limpiar';const url=new URL(form.action,location.href);const section=form.querySelector('input[name=section]')?.value;if(section)url.searchParams.set('section',section);reset.href=url.href;form.append(reset);}
  });
  document.querySelectorAll('.ge-admin-table').forEach(table=>{
   table.classList.add('ge-v3-responsive-table');
   const headers=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());
   table.querySelectorAll('tbody tr').forEach(row=>[...row.children].forEach((cell,i)=>{if(cell.tagName==='TD'&&headers[i])cell.dataset.label=headers[i];}));
  });
  const eye='<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  document.querySelectorAll('.ge-admin-table-actions a,.ge-order-row-actions a,.ge-quote-history-open').forEach(a=>{
   if(/^(Ver|Ver detalle|Abrir|Ver pedido|Ver ficha)\s*[→↗]?$/.test(a.textContent.trim())){const text=a.textContent.trim().replace(/[→↗]/g,'').trim();a.setAttribute('aria-label',text);a.title=text;a.innerHTML=eye;a.classList.add('ge-v3-row-open');}
  });
  const q=new URL(location.href).searchParams.get('q');
  document.querySelectorAll('.ge-queue-filters a,.ge-queue-pages a').forEach(a=>{const url=new URL(a.href);if(q)url.searchParams.set('q',q);const sort=new URL(location.href).searchParams.get('sort');if(sort)url.searchParams.set('sort',sort);a.href=url.href;});
  document.querySelectorAll('.ge-admin-status,.ge-item-status,.ge-quote-status,.ge-library-status,.ge-quote-history-row>div:nth-child(2)>strong').forEach(badge=>{
   const text=badge.textContent.toLowerCase();
   const state=/cancel|rechaz|error|demor|bloque/.test(text)?'danger':/listo|entregado|aceptado|aprobado|pagado|convertido/.test(text)?'success':/pendiente|borrador|revisión|sin archivo|vencido/.test(text)?'warning':'info';
   badge.classList.add('ge-v3-badge');badge.dataset.geStatus=state;
  });
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
