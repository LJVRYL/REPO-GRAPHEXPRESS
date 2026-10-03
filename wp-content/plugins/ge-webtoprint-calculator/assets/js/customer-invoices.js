/* Server idempotency is authoritative; this feedback prevents accidental double clicks. */
document.addEventListener('submit', function (event) {
  const form = event.target;
  if (!form.matches('.ge-invoice-form')) return;
  if (form.dataset.submitting) { event.preventDefault(); return; }
  const file = form.querySelector('input[type=file]');
  if (file && file.files[0] && file.files[0].size > 20 * 1024 * 1024) {
    event.preventDefault();
    file.setCustomValidity('Elegí un archivo de hasta 20 MB.');
    file.reportValidity();
    file.addEventListener('change', () => file.setCustomValidity(''), {once:true});
    return;
  }
  form.dataset.submitting = '1';
  form.setAttribute('aria-busy', 'true');
  const button = form.querySelector('button[type=submit]');
  if (button) { button.setAttribute('aria-disabled', 'true'); button.textContent = 'Guardando…'; }
  const status = document.createElement('p');
  status.setAttribute('role', 'status');
  status.textContent = 'Estamos guardando. Esperá la confirmación antes de volver a enviar.';
  form.append(status);
});
