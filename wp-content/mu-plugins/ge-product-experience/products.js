(() => {
  let panel = document.querySelector('#canva.gxc-canva-panel, #canva.gxc-canva-entry');
  // Offset cards keep their existing catalog/manual-upload path, without enabling the pilot.
  if (!panel) {
    const catalog = document.querySelector('.ge-business-cards a[href^="https://www.canva.com/"]');
    if (catalog) {
      panel = document.createElement('section'); panel.id = 'canva'; panel.className = 'gxc-canva-entry';
      catalog.parentElement.before(panel); panel.append(catalog);
      const description = document.createElement('p');
      description.textContent = 'Elegí una plantilla, ajustá las medidas y descargá PDF para impresión. Volvé al producto para cargar el archivo de forma privada. La conexión y el regreso automático no están habilitados para este producto.';
      panel.append(description);
    }
  }
  if (!panel || typeof HTMLDialogElement === 'undefined') return;
  const dialog = document.createElement('dialog');
  dialog.className = 'gx-canva-dialog'; dialog.id = 'gx-canva-dialog';
  dialog.setAttribute('aria-labelledby', 'gx-canva-title');
  const logo = () => {
    const img = document.createElement('img'); img.src = geProductExperience.canvaLogo;
    img.alt = ''; img.width = 32; img.height = 32; img.className = 'gx-canva-logo'; return img;
  };
  const launch = document.createElement('button'); launch.type = 'button'; launch.className = 'gx-canva-launch';
  launch.setAttribute('aria-haspopup', 'dialog'); launch.setAttribute('aria-controls', dialog.id);
  launch.setAttribute('aria-expanded', 'false');
  const caption = document.createElement('span');
  const title = document.createElement('strong'); title.textContent = 'Diseñá con Canva';
  const sub = document.createElement('small'); sub.textContent = 'Plantillas, tus diseños y opciones para traer el PDF';
  caption.append(title, sub);
  const arrow = document.createElement('span'); arrow.textContent = '↗'; arrow.setAttribute('aria-hidden','true');
  launch.append(logo(), caption, arrow);
  panel.before(launch);
  const head = document.createElement('header'); head.className = 'gx-canva-dialog-head';
  const heading = document.createElement('h2'); heading.id = 'gx-canva-title'; heading.textContent = 'Diseñá con Canva';
  const close = document.createElement('button'); close.type = 'button'; close.className = 'gx-canva-close';
  close.setAttribute('aria-label','Cerrar Canva y volver al producto'); close.textContent = '×';
  head.append(logo(), heading, close);
  const body = document.createElement('div'); body.className = 'gx-canva-dialog-body'; body.append(panel);
  const note = document.createElement('p'); note.className = 'gx-canva-preview-note';
  note.textContent = 'La vista previa del archivo importado y sus páginas se revisan en el producto. Cerrar este panel conserva la importación en curso.';
  const back = document.createElement('button'); back.type = 'button'; back.className = 'gx-canva-return';
  back.textContent = 'Volver al producto y revisar el archivo';
  back.addEventListener('click', () => {
    dialog.close();
    const preview = document.querySelector('.gxc-file-preview:not([hidden])');
    (preview || launch).scrollIntoView({block:'center',behavior:'instant'});
  });
  body.append(note, back);
  dialog.append(head, body); document.body.append(dialog);
  // Remove the redundant same-page button; keep preparation guidance and downloadable templates.
  for (const link of document.querySelectorAll('.ge-business-cards a[href="#canva"]')) {
    const paragraph = link.parentElement;
    if (paragraph?.tagName === 'P' && paragraph.children.length === 1) paragraph.remove();
    else link.remove();
  }
  let previousOverflow = '', previousFocus = null;
  const open = () => {
    if (dialog.open) return;
    previousFocus = document.activeElement; previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden'; dialog.showModal(); launch.setAttribute('aria-expanded','true'); close.focus();
  };
  const finishClose = () => {
    document.body.style.overflow = previousOverflow; launch.setAttribute('aria-expanded','false');
    (previousFocus?.isConnected ? previousFocus : launch).focus({preventScroll:true});
  };
  launch.addEventListener('click', open);
  document.querySelector('form[data-ge-digital-calculator]')?.addEventListener('submit', event => {
    // The integration owns validation. Expose its explanation if it blocks a cart action.
    if (event.defaultPrevented && status?.textContent.trim()) open();
  });
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', finishClose);
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const r = dialog.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close();
  });
  // Existing status changes (including import failures) stay discoverable after closing the panel.
  const status = panel.querySelector('[data-canva-status]');
  if (status) new MutationObserver(() => {
    const text = status.textContent.trim();
    sub.textContent = text || 'Plantillas, tus diseños y opciones para traer el PDF';
    sub.title = text;
  }).observe(status,{childList:true,subtree:true,characterData:true});
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href="#canva"]');
    if (link) { event.preventDefault(); open(); }
  });
  window.addEventListener('hashchange', () => { if(location.hash === '#canva') open(); });
  const state = new URL(location.href).searchParams.get('canva_status');
  if (location.hash === '#canva' || ['returned','denied','invalid_return','connect'].includes(state)) open();
})();
