(() => {
  const panel = document.querySelector('.gxc-canva-panel');
  const form = document.querySelector('form[data-ge-digital-calculator]');
  if (!panel || !form || !window.geCardsCanva) return;
  const connect = panel.querySelector('[data-canva-connect]');
  const connected = panel.querySelector('[data-canva-connected]');
  const status = panel.querySelector('[data-canva-status]');
  const list = panel.querySelector('[data-canva-designs]');
  const more = panel.querySelector('[data-canva-more]');
  let continuation = '', busy = false, technicalReady = false, lastImport = null, reviewedSelection = '', reviewState = 'pending';
  const jobField = document.createElement('input'); jobField.type = 'hidden'; jobField.name = 'ge_canva_job'; form.append(jobField);
  const fileInput = form.querySelector('[data-ge-digital-files]');
  fileInput.addEventListener('change', () => { jobField.value = ''; technicalReady = false; reviewState = 'pending'; updatePreview(); });
  form.addEventListener('submit', event => {
    if (busy || (jobField.value && (!technicalReady || reviewedSelection !== JSON.stringify(selection())))) { event.preventDefault(); notify('Esperá la revisión técnica o corregí el PDF antes de continuar.'); panel.scrollIntoView({block:'center'}); }
  });
  const selection = () => Object.fromEntries([...form.querySelectorAll('[data-ge-field]')].map(control => [control.dataset.geField, control.value]));
  const notify = text => { status.textContent = text; };
  const previewState = document.querySelector('.gxc-file-preview .gxc-preview-state');
  const updatePreview = () => {
    if (!previewState) return;
    const labels = {pending:'revisión técnica pendiente.',blocked:'corregí las advertencias técnicas.',review_required:'sin bloqueos automáticos; aprobación de impresión pendiente.'};
    const text = previewState.textContent.replace(/revisión técnica pendiente\.|corregí las advertencias técnicas\.|sin bloqueos automáticos; aprobación de impresión pendiente\./, labels[reviewState]);
    if (text !== previewState.textContent) previewState.textContent = text;
  };
  if (previewState) new MutationObserver(updatePreview).observe(previewState,{childList:true,subtree:true,characterData:true});

  form.addEventListener('change', event => {
    if (event.target.matches('[data-ge-field]') && jobField.value && reviewedSelection !== JSON.stringify(selection())) {
      technicalReady = false; reviewState = 'pending'; updatePreview();
      notify('Cambiaste la configuración. Volvé a importar el diseño de Canva para revisar el archivo con estas opciones.');
    }
  });
  const request = async (op, data = {}, binary = false) => {
    const body = new FormData(); body.append('action', `ge_customer_cards_design_${op}`); body.append('nonce', geCardsCanva.nonce);
    for (const [key, value] of Object.entries(data)) body.append(key, typeof value === 'object' ? JSON.stringify(value) : value);
    const response = await fetch(geCardsCanva.ajaxUrl, {method: 'POST', credentials: 'same-origin', body});
    if (binary && response.ok && response.headers.get('content-type')?.startsWith('application/pdf')) return response.blob();
    let result; try { result = await response.json(); } catch (_) { throw new Error('La sesión o la conexión cambiaron. Recargá Graphex; podés subir el PDF manualmente.'); }
    if (!response.ok || !result.success) throw new Error(result.data?.message || 'No pudimos completar la conexión Canva. Podés usar la carga manual.');
    return result.data;
  };
  connect.addEventListener('submit', () => { connect.querySelector('[name=selection]').value = JSON.stringify(selection()); });
  const setConnected = value => { connect.hidden = value; connected.hidden = !value; };
  const importDesign = async id => {
    if (busy || form.querySelector('[data-ge-digital-upload]')?.disabled) return;
    const importSelection = selection();
    const controls = [...form.querySelectorAll('[data-ge-field]')].map(control => [control, control.disabled]);
    for (const [control] of controls) control.disabled = true;
    technicalReady = false; reviewedSelection = ''; reviewState = 'pending'; updatePreview();
    busy = true; fileInput.disabled = true; notify('Canva está preparando el PDF…');
    try {
      const fingerprint = JSON.stringify([id,importSelection]);
      const requestId = lastImport?.fingerprint === fingerprint ? lastImport.requestId : crypto.randomUUID();
      lastImport = {id,fingerprint,requestId};
      panel.querySelector('[data-canva-retry]').hidden = true;
      const job = await request('export', {design_id: id, selection: importSelection, request_id: requestId});
      let ready = false;
      for (let attempt = 0; attempt < 100; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 3000));
        const state = await request('export_status', {job: job.job});
        if (state.status === 'ready') { ready = true; break; }
      }
      if (!ready) throw new Error('La exportación tarda más de lo esperado. Reintentá o subí el PDF manualmente.');
      const blob = await request('pdf', {job: job.job}, true);
      if (blob.size > 20 * 1024 * 1024 || (await blob.slice(0, 5).text()) !== '%PDF-') throw new Error('Canva no devolvió un PDF válido de hasta 20 MB.');
      const input = form.querySelector('[data-ge-digital-files]');
      const transfer = new DataTransfer(); transfer.items.add(new File([blob], `canva-${id}.pdf`, {type: 'application/pdf'}));
      input.files = transfer.files; input.dispatchEvent(new Event('change', {bubbles: true}));
      notify('PDF recibido. Completando la carga privada y preparando la vista previa…');
      const upload = form.querySelector('[data-ge-digital-upload]');
      await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => { observer.disconnect(); reject(new Error('La carga privada tardó demasiado. Reintentá la importación.')); }, 120000);
      const observer = new MutationObserver(() => {
        const claims = form.querySelector('[data-ge-digital-claims]');
        if (!upload.disabled) {
          observer.disconnect(); clearTimeout(timeout);
          notify(input.dataset.uploadedFingerprint && claims.value && claims.value !== '[]' ? 'PDF cargado de forma privada. Revisá la vista previa; la revisión técnica sigue pendiente.' : 'El PDF llegó desde Canva, pero la carga privada no terminó. Reintentá subirlo con el botón de archivos.');
          resolve();
        }
      });
      observer.observe(upload, {attributes: true, attributeFilter: ['disabled']}); upload.click();
      if (!upload.disabled) { observer.disconnect(); clearTimeout(timeout); notify('PDF recibido. Pulsá «Subir archivos de forma segura» para completar la carga.'); resolve(); }
      });
      const claims = form.querySelector('[data-ge-digital-claims]');
      if (!input.dataset.uploadedFingerprint || !claims.value || claims.value === '[]') throw new Error('La carga privada no terminó. Reintentá la importación.');
      jobField.value = job.job;
      const labels = {page_count:'Cantidad de caras incorrecta',page_dimensions:'Cada página debe medir 95 × 65 mm',page_geometry:'Geometría de páginas inválida',page_geometry_unverified:'No pudimos verificar todas las páginas',encryption_unverified:'PDF cifrado o cifrado no verificado',fonts_not_embedded:'Hay fuentes sin incrustar',image_resolution_below_300:'Hay imágenes por debajo de 300 dpi',analysis_failed:'Falló el análisis',analysis_blocker:'El archivo tiene un bloqueo técnico',invalid_context:'Configuración inválida'};
      for (let attempt=0; attempt<60; attempt++) {
        notify('PDF privado recibido. Analizando medidas, caras, fuentes e imágenes…');
        const result=await request('preflight',{job:job.job,claims:JSON.parse(claims.value),selection:importSelection});
        if (result.status==='blocked') { reviewState = 'blocked'; updatePreview(); throw new Error(result.blockers.map(code=>labels[code] || 'Revisar archivo').join('. ') + '. Corregí en Canva e importá de nuevo.'); }
        if (result.status==='review_required') {
          if (JSON.stringify(selection()) !== JSON.stringify(importSelection)) throw new Error('La configuración cambió durante la revisión. Volvé a importar el diseño.');
          technicalReady=true; reviewedSelection = JSON.stringify(importSelection); lastImport = null; reviewState = 'review_required'; updatePreview();
          notify('Archivo recibido: sin bloqueos en las comprobaciones automáticas. Zona segura, corte y color requieren revisión del archivo exacto antes de producción. Podés seguir con la compra.');
          return;
        }
        await new Promise(resolve=>setTimeout(resolve,3000));
      }
      throw new Error('La revisión técnica sigue pendiente. Reintentá la importación antes de continuar.');
    } catch (error) {
      notify(error.message);
      if (error.message.includes('Corregí en Canva')) lastImport = null;
      panel.querySelector('[data-canva-retry]').hidden = !lastImport;
    }
    finally { busy = false; fileInput.disabled = false; for (const [control, disabled] of controls) control.disabled = disabled; }
  };
  panel.querySelector('[data-canva-retry]').addEventListener('click', () => { if(lastImport) importDesign(lastImport.id); });
  const designs = async append => {
    if (busy) return; notify('Buscando tus diseños…');
    try {
      const data = await request('designs', {continuation: append ? continuation : ''});
      if (!append) list.replaceChildren();
      for (const design of data.items) {
        const item = document.createElement('li'); const title = document.createElement('strong'); title.textContent = design.title; item.append(title);
        const edit = document.createElement('button'); edit.type = 'button'; edit.textContent = 'Editar y volver a Graphex';
        edit.addEventListener('click', async () => {
          try { const result = await request('edit', {design_id: design.id, selection: selection()}); location.assign(result.url); }
          catch (error) { notify(error.message); }
        });
        const bring = document.createElement('button'); bring.type = 'button'; bring.textContent = 'Traer PDF de este diseño'; bring.addEventListener('click', () => importDesign(design.id));
        item.append(edit, bring); list.append(item);
      }
      continuation = data.continuation || ''; more.hidden = !continuation;
      notify(data.items.length ? 'Elegí un diseño propio. Si creaste una plantilla en otra pestaña, volvé a buscar tus diseños.' : 'No encontramos diseños propios. Guardá una plantilla del catálogo en tu Canva y volvé a buscar.');
    } catch (error) { notify(error.message); }
  };
  panel.querySelector('[data-canva-refresh]').addEventListener('click', () => designs(false));
  more.addEventListener('click', () => designs(true));
  panel.querySelector('[data-canva-forget]').addEventListener('click', async () => { try { await request('forget'); setConnected(false); list.replaceChildren(); notify('Graphex olvidó esta conexión. Los permisos externos se administran en Canva.'); } catch (error) { notify(error.message); } });
  request('status').then(async state => {
    setConnected(state.connected);
    const code = new URL(location.href).searchParams.get('canva_status');
    const messages = {denied: 'No autorizaste Canva. Podés seguir con la carga manual.', invalid_return: 'No pudimos verificar el regreso. Reintentá desde Graphex o subí el PDF manualmente.', connect: 'No pudo completarse la conexión. Revisá la sesión y la configuración.'};
    if (messages[code]) notify(messages[code]);
    if (state.connected && state.returned && code === 'returned') {
      const values = state.returned.selection.values;
      for (const control of form.querySelectorAll('[data-ge-field]')) {
        const value = values[control.dataset.geField];
        if ([...control.options].some(option => option.value === value && !option.disabled)) { control.value = value; control.dispatchEvent(new Event('change', {bubbles: true})); }
      }
      await importDesign(state.returned.design_id);
    }
  }).catch(error => notify(error.message));
})();
