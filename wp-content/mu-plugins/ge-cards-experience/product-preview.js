(() => {
  const scriptUrl = document.currentScript.src;
  const product = document.querySelector('#product-78, #product-83');
  const form = product?.querySelector('form[data-ge-digital-calculator], form[data-ge-storefront]');
  if (form && window.geCardsProduct) {
    const key = `ge-cards-selection:${geCardsProduct.selectionScope}:${geCardsProduct.productId}`;
    const controls = [...form.querySelectorAll('[data-ge-field]')];
    const save = () => {
      const values = Object.fromEntries(controls.map(control => [control.dataset.geField, control.value]));
      try { sessionStorage.setItem(key, JSON.stringify({expires: Date.now() + 3600000, values})); } catch (_) {}
    };
    try {
      const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
      if (saved && saved.expires > Date.now() && controls.every(control => Object.hasOwn(saved.values, control.dataset.geField) && [...control.options].some(option => option.value === saved.values[control.dataset.geField]))) {
        controls.forEach(control => {
          const choice = saved.values[control.dataset.geField];
          if ([...control.options].some(option => option.value === choice && !option.disabled)) {
            control.value = choice; control.dispatchEvent(new Event('change', {bubbles: true}));
          }
        });
      }
    } catch (_) {}
    controls.forEach(control => control.addEventListener('change', save));
    save();
  }
  const input = form?.querySelector('[data-ge-digital-files], [data-ge-r2-files]');
  const gallery = product?.querySelector('.woocommerce-product-gallery');
  if (!input || !gallery) return;
  const claims = form.querySelector('[data-ge-digital-claims], [data-ge-r2-claims]');
  const uploadStatus = form.querySelector('[data-ge-digital-status], [data-ge-r2-status]');
  const upload = form.querySelector('[data-ge-digital-upload], [data-ge-r2-upload-button]');
  const panel = document.createElement('section');
  panel.className = 'gxc-file-preview';
  panel.hidden = true;
  panel.setAttribute('aria-label', 'Vista previa de tu archivo');
  panel.innerHTML = '<h3>Tu archivo</h3><label>Archivo a revisar<select data-preview-file></select></label><label data-preview-page-label hidden>Página<select data-preview-page></select></label><p class="gxc-preview-state" role="status"></p><canvas hidden aria-label="Vista previa de la página seleccionada"></canvas><img hidden alt="Vista previa del archivo seleccionado"><p class="gxc-note">Vista previa para revisar el contenido. No equivale a aprobación de impresión; el original se conserva y la revisión técnica continúa por separado.</p><button type="button">Reemplazar archivo</button>';
  gallery.append(panel);
  const filesSelect = panel.querySelector('[data-preview-file]');
  const pagesSelect = panel.querySelector('[data-preview-page]');
  const pageLabel = panel.querySelector('[data-preview-page-label]');
  const canvas = panel.querySelector('canvas');
  const image = panel.querySelector('img');
  const state = panel.querySelector('[role=status]');
  let generation = 0, pageGeneration = 0, task = null, pdf = null, objectUrl = null, pdfjs = null;
  let uploadFailed = false, rendering = false, previewMessage = '';
  const message = (text, error = false) => { state.textContent = text; state.classList.toggle('is-error', error); };
  const clear = () => {
    generation++; canvas.hidden = true; image.hidden = true; image.removeAttribute('src');
    pageLabel.hidden = true; pagesSelect.replaceChildren();
    if (task) { task.destroy(); task = null; } pdf = null;
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
  };
  const status = () => {
    if (uploadFailed) { message('La carga no se completó. Reintentá subir el archivo o reemplazalo.', true); return; }
    const fingerprint = [...input.files].map(file => [file.name, file.size, file.lastModified].join(':')).join('|');
    const stored = fingerprint && input.dataset.uploadedFingerprint === fingerprint && claims?.value && claims.value !== '[]';
    const uploading = upload?.disabled;
    message((uploading ? 'Subiendo al almacenamiento privado…' : stored ? 'Archivo cargado · revisión técnica pendiente.' : 'Archivo seleccionado · falta subirlo.') + (previewMessage ? ' ' + previewMessage : ''));
  };
  const renderPage = async () => {
    if (!pdf || uploadFailed) return;
    const revision = generation;
    const pageRevision = ++pageGeneration;
    const page = await pdf.getPage(Number(pagesSelect.value));
    if (revision !== generation) return;
    const natural = page.getViewport({scale: 1});
    const viewport = page.getViewport({scale: Math.min(900 / natural.width, 1400 / natural.height)});
    const target = document.createElement('canvas');
    target.width = Math.ceil(viewport.width); target.height = Math.ceil(viewport.height);
    await page.render({canvasContext: target.getContext('2d'), viewport}).promise;
    if (revision !== generation || pageRevision !== pageGeneration || uploadFailed) return;
    canvas.width = target.width; canvas.height = target.height;
    canvas.getContext('2d').drawImage(target, 0, 0); canvas.hidden = false;
    page.cleanup();
  };
  const renderFile = async () => {
    clear(); uploadFailed = false; rendering = true; previewMessage = '';
    const revision = generation;
    const file = input.files[Number(filesSelect.value)];
    if (!file) { rendering = false; return; }
    status();
    try {
      if (file.size > 25 * 1024 * 1024) throw new Error('La vista previa admite hasta 25 MB; podés subir el original igualmente.');
      if (/\.pdf$/i.test(file.name)) {
        if (!pdfjs) pdfjs = await import(new URL('pdfjs/pdf.min.mjs', scriptUrl));
        if (revision !== generation) return;
        pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs/pdf.worker.min.mjs', scriptUrl).href;
        const data = new Uint8Array(await file.arrayBuffer());
        if (revision !== generation) return;
        task = pdfjs.getDocument({data, isEvalSupported: false, enableXfa: false, disableAutoFetch: true, maxImageSize: 16000000});
        pdf = await task.promise;
        if (revision !== generation) return;
        const count = Math.min(pdf.numPages, 100);
        for (let page = 1; page <= count; page++) {
          const option = document.createElement('option'); option.value = String(page);
          option.textContent = pdf.numPages === 2 ? (page === 1 ? 'Frente · página 1 de 2' : 'Dorso · página 2 de 2') : `Página ${page} de ${pdf.numPages}`;
          pagesSelect.append(option);
        }
        pageLabel.hidden = pdf.numPages === 1;
        previewMessage = `PDF de ${pdf.numPages} página(s).`;
        if (pdf.numPages > 100) previewMessage += ' Vista previa limitada a las primeras 100.';
        await renderPage();
      } else if (/\.(png|jpe?g)$/i.test(file.name)) {
        objectUrl = URL.createObjectURL(file);
        image.src = objectUrl; await image.decode();
        if (revision !== generation || uploadFailed) return;
        image.hidden = false; previewMessage = 'Imagen del archivo seleccionado.';
      } else { throw new Error('Este formato requiere revisión del original; la vista previa admite PDF, JPG y PNG.'); }
    } catch (error) {
      if (revision !== generation) return;
      canvas.hidden = true; image.hidden = true;
      previewMessage = error.name === 'PasswordException' ? 'El PDF está protegido; enviá una copia sin contraseña.' : error.message?.startsWith('La vista previa') || error.message?.startsWith('Este formato') ? error.message : 'No pudimos mostrar este archivo. Revisalo o reemplazalo; no se muestra una miniatura anterior.';
    } finally { if (revision === generation) { rendering = false; status(); } }
  };
  input.addEventListener('change', () => {
    clear(); filesSelect.replaceChildren(); uploadFailed = false;
    const files = [...input.files];
    files.forEach((file, index) => { const option = document.createElement('option'); option.value = String(index); option.textContent = file.name; filesSelect.append(option); });
    panel.hidden = !files.length; gallery.classList.toggle('gxc-show-preview', !!files.length);
    if (files.length) renderFile();
  });
  filesSelect.addEventListener('change', renderFile);
  pagesSelect.addEventListener('change', () => renderPage().catch(() => { canvas.hidden = true; message('No pudimos mostrar esta página. Elegí otra o reemplazá el archivo.', true); }));
  panel.querySelector('button').addEventListener('click', () => input.click());
  const mobile = window.matchMedia('(max-width: 767px)');
  const placePreview = () => {
    if (mobile.matches) { (input.closest('.ge-digital-upload, .ge-storefront-upload') || input.parentElement).append(panel); }
    else { gallery.append(panel); }
  };
  mobile.addEventListener('change', placePreview); placePreview();
  if (uploadStatus) new MutationObserver(() => {
    const text = uploadStatus.textContent || '';
    if (/No se pudo|Se interrumpió|rechazó|no pudo|no llegó/i.test(text)) {
      uploadFailed = true; canvas.hidden = true; image.hidden = true;
    } else if (/Preparando|Subiendo|archivo\(s\) cargado/i.test(text)) {
      if (uploadFailed && !rendering) { uploadFailed = false; renderFile(); }
    }
    status();
  }).observe(uploadStatus, {childList: true, subtree: true, characterData: true});
  if (upload) new MutationObserver(status).observe(upload, {attributes: true, attributeFilter: ['disabled']});
  window.addEventListener('pagehide', clear, {once: true});
})();
