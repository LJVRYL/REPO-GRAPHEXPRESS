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
  form.addEventListener('input', update);
  update();
})();
