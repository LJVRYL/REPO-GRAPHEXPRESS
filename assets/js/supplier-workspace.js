document.querySelectorAll('[data-card-form]').forEach((form) => {
  const button = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-card-status]');
  const serialized = () => [...new FormData(form).entries()].map(([key, value]) => `${key}:${value}`).join('|');
  const baseline = serialized();
  const update = () => {
    const dirty = serialized() !== baseline;
    button.disabled = !dirty;
    status.textContent = dirty ? 'Cambios sin guardar' : 'Sin cambios';
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('submit', () => {
    button.disabled = true;
    status.textContent = 'Guardando…';
  });
});
