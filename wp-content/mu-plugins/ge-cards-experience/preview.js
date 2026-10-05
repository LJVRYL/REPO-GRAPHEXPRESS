(() => {
  const form = document.querySelector('.gxc-contact-form');
  const preview = document.querySelector('[data-contact-preview]');
  if (!form || !preview) return;
  const value = key => form.elements.namedItem(key)?.value.trim() || '';
  const update = () => {
    preview.querySelector('[data-preview-name]').textContent = [value('first_name'), value('last_name')].filter(Boolean).join(' ') || 'Tu nombre';
    for (const key of ['company', 'role', 'email', 'phone', 'website']) {
      const node = preview.querySelector(`[data-preview="${key}"]`);
      node.textContent = value(key);
      node.hidden = !value(key);
    }
  };
  const consent = form.elements.namedItem('public_consent');
  const error = document.querySelector('#gxc-publish-error');
  form.addEventListener('submit', event => {
    if (event.submitter?.value === 'publish' && !consent.checked) {
      event.preventDefault();
      error.textContent = 'Para publicar y obtener el QR, marcá la autorización de datos públicos. También podés guardar un borrador privado.';
      error.hidden = false;
      consent.focus();
    }
  });
  consent.addEventListener('change', () => { if (consent.checked) error.hidden = true; });
  form.addEventListener('input', update);
  update();
})();
