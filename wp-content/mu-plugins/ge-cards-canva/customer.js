(() => {
  const panel = document.querySelector('.gxc-canva-panel');
  const form = document.querySelector('form[data-ge-digital-calculator]');
  if (!panel || !form || !window.geCardsCanva) return;
  const connect = panel.querySelector('[data-canva-connect]');
  const connected = panel.querySelector('[data-canva-connected]');
  const status = panel.querySelector('[data-canva-status]');
  const list = panel.querySelector('[data-canva-designs]');
  const more = panel.querySelector('[data-canva-more]');
  let continuation = '', busy = false;
  const selection = () => Object.fromEntries([...form.querySelectorAll('[data-ge-field]')].map(control => [control.dataset.geField, control.value]));
  const notify = text => { status.textContent = text; };
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
    busy = true; notify('Canva está preparando el PDF…');
    try {
      const job = await request('export', {design_id: id, selection: selection()});
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
      await new Promise(resolve => {
      const observer = new MutationObserver(() => {
        const claims = form.querySelector('[data-ge-digital-claims]');
        if (!upload.disabled) {
          observer.disconnect();
          notify(input.dataset.uploadedFingerprint && claims.value && claims.value !== '[]' ? 'PDF cargado de forma privada. Revisá la vista previa; la revisión técnica sigue pendiente.' : 'El PDF llegó desde Canva, pero la carga privada no terminó. Reintentá subirlo con el botón de archivos.');
          resolve();
        }
      });
      observer.observe(upload, {attributes: true, attributeFilter: ['disabled']}); upload.click();
      if (!upload.disabled) { observer.disconnect(); notify('PDF recibido. Pulsá «Subir archivos de forma segura» para completar la carga.'); resolve(); }
      });
    } catch (error) { notify(error.message); }
    finally { busy = false; }
  };
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
