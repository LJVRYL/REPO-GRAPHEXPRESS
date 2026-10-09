(() => {
  const panel = document.querySelector('#canva');
  const form = document.querySelector('form[data-ge-digital-calculator]');
  if (!panel || !form) return;
  const key = 'graphex.canva.cards.selection.v1';
  const controls = [...form.querySelectorAll('[data-ge-field]')];
  panel.querySelector('[data-canva-login]')?.addEventListener('click', () => {
    try { sessionStorage.setItem(key, JSON.stringify({expires: Date.now() + 30 * 60 * 1000, values: Object.fromEntries(controls.map(control => [control.dataset.geField, control.value]))})); } catch (_) {}
  });
  if (location.hash !== '#canva') return;
  try {
    const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
    sessionStorage.removeItem(key);
    if (!saved || saved.expires < Date.now() || !saved.values || typeof saved.values !== 'object') return;
    for (const control of controls) {
      const value = saved.values[control.dataset.geField];
      if ([...control.options].some(option => option.value === value && !option.disabled)) {
        control.value = value; control.dispatchEvent(new Event('change', {bubbles: true}));
      }
    }
  } catch (_) {}
})();
