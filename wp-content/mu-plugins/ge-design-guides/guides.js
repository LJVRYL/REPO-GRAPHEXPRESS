(()=>{'use strict';
const section=document.querySelector('[data-gx-design-options]');if(!section)return;
let rows;try{rows=JSON.parse(section.dataset.gxDesignOptions);}catch(e){return;}
const selector=document.querySelector('.ge-storefront-config select[name="option_key"]');if(!selector)return;
const label=section.querySelector('[data-gx-design-label]'),note=section.querySelector('[data-gx-design-note]'),link=section.querySelector('[data-gx-design-source]');
function refresh(){const row=rows.find(r=>r.option_key===selector.value);if(!row){label.textContent='Confirmá el modelo elegido';note.textContent='No hay una matriz vinculada a esta opción. Consultanos antes de diseñar.';link.hidden=true;return;}
label.textContent=row.label;note.textContent=row.note;link.hidden=row.status==='not_applicable';link.href=row.supplier_template_url||row.source_url;
link.textContent=row.supplier_template_url?(row.status==='supplier_label_conflict'?'PDF original del proveedor · por confirmar':'PDF original Grupo Wind Banners'):'Ver modelo en la fuente';}
selector.addEventListener('change',refresh);refresh();
})();
