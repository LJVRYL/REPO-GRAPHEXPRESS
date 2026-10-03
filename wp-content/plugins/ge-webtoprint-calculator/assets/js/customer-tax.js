(() => {
  'use strict';
  const post = async data => {
    const response = await fetch(geCustomerTax.url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({...data, nonce:geCustomerTax.nonce})});
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || 'No pudimos consultar. Reintentá.');
    return result.data;
  };
  document.querySelectorAll('[data-ge-tax]').forEach(box => {
    const form=box.closest('form'), state=box.querySelector('[data-ge-tax-state]'), preview=box.querySelector('[data-ge-tax-preview]'), search=box.querySelector('[data-ge-tax-search]');
    const quick=box.dataset.quick==='1';
    const names=quick ? {cuit:'customer_cuit',legal_name:'tax_legal_name',vat_status:'tax_vat_status',fiscal_address:'tax_fiscal_address'} : {cuit:'cuit',legal_name:form.elements.company?'company':'legal_name',vat_status:'vat_status',fiscal_address:'fiscal_address'};
    const token=box.querySelector('[name="tax_preview_token"]');
    let found=null;
    Object.values(names).forEach(name => form.elements[name]?.addEventListener('input',()=> {token.value=''; state.textContent='Datos modificados manualmente. Volvé a buscar si querés verificarlos.';}));
    search.addEventListener('click',async()=> {
      token.value=''; found=null; preview.hidden=true; search.disabled=true; box.setAttribute('aria-busy','true'); state.textContent='Consultando datos fiscales…';
      try {
        const selected=form.elements.billing_profile_id?.value || box.dataset.profileId;
        const result=await post({action:'ge_customer_tax_lookup',customer_id:box.dataset.customerId,email:form.elements.customer_email?.value || '',profile_id:selected,cuit:form.elements[names.cuit]?.value || ''});
        if (!result.preview_token) {
          const messages={not_configured:'La consulta oficial todavía no está configurada. Cargá los datos manualmente; quedarán pendientes de verificación.',invalid_cuit:'El CUIT no es válido. Revisá sus 11 dígitos.',not_found:'No encontramos datos oficiales para este CUIT.',partial:'La respuesta oficial está incompleta. Revisá y cargá los datos manualmente.'};
          state.textContent=messages[result.status] || 'No pudimos verificar los datos. Conservamos los datos actuales; podés reintentar o cargarlos manualmente.'; return;
        }
        found=result;
        const dl=box.querySelector('[data-ge-tax-data]'); dl.replaceChildren();
        for (const [key,label] of Object.entries({cuit:'CUIT',legal_name:'Razón social',vat_status:'Condición fiscal',fiscal_address:'Domicilio fiscal'})) {
          const dt=document.createElement('dt'),dd=document.createElement('dd'); dt.textContent=label;
          const vat={registered:'Responsable inscripto',monotributo:'Monotributista',exempt:'Exento',final_consumer:'Consumidor final',unknown:'Pendiente de verificación'};
          dd.textContent=key==='vat_status' ? (vat[result.profile[key]] || 'Pendiente de verificación') : (result.profile[key] || 'Sin información'); dl.append(dt,dd);
        }
        state.textContent=result.verified ? 'Consulta completada. Revisá la vista previa antes de confirmar.' : 'Encontramos datos de identidad. La condición fiscal sigue pendiente. Revisá antes de confirmar.'; preview.hidden=false; box.querySelector('[data-ge-tax-confirm]').focus();
      } catch(error) {state.textContent=error.message;} finally {search.disabled=false; box.removeAttribute('aria-busy');}
    });
    box.querySelector('[data-ge-tax-confirm]').addEventListener('click',()=> {
      if (!found) return;
      Object.entries(names).forEach(([key,name])=> {if(form.elements[name]) {form.elements[name].value=found.profile[key] || ''; form.elements[name].dispatchEvent(new Event('change',{bubbles:true}));}});
      token.value=found.preview_token; preview.hidden=true; state.textContent='Datos confirmados. Guardá el formulario para aplicarlos al perfil.'; search.focus();
    });
    box.querySelector('[data-ge-tax-cancel]').addEventListener('click',()=> {found=null; preview.hidden=true; token.value='';state.textContent='Consulta cancelada. Conservamos los datos actuales.'; search.focus();});
  });
  document.querySelectorAll('.ge-billing-issuer-picker').forEach(box=> {
    const form=box.closest('form'); if (!form) return;
    const status=document.createElement('p'); status.setAttribute('aria-live','polite'); box.append(status);
    let request=0;
    const update=async()=> {if(Number(geCustomerTax.stage)<2)return; const seq=++request; try {const result=await post({action:'ge_customer_tax_suggestion',email:form.elements.customer_email?.value || '',profile_id:form.elements.billing_profile_id?.value || 'default',issuer_id:form.elements.issuer_profile_id?.value || '',customer_cuit:form.elements.customer_cuit?.value || '',vat_status:form.elements.tax_vat_status?.value || ''}); if(seq!==request)return; status.textContent='Comprobante sugerido: '+((result.suggested_document_class==='unknown'?'Pendiente':result.suggested_document_class) || 'Pendiente')+'. Requiere revisión del personal. '+(result.warnings || []).join(' ');} catch(error){status.textContent=error.message;}};
    ['customer_email','billing_profile_id','issuer_profile_id','customer_cuit','tax_vat_status'].forEach(name=>form.elements[name]?.addEventListener('change',update));
  });
})();
