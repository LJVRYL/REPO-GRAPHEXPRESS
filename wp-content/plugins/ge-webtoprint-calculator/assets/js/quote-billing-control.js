(() => {
  'use strict';
  let lastTrigger;
  const selection = document.getElementById('ge-qbc-selection');
  function suggestion(form) {
    const receiver = form.querySelector('[data-ge-qbc-receiver]');
    if (!receiver) return;
    const option = receiver.selectedOptions[0];
    const issuer = form.querySelector('[data-ge-qbc-issuer]');
    const override = form.querySelector('[data-ge-qbc-override]')?.checked;
    const refresh = form.querySelector('[data-ge-qbc-refresh]')?.checked;
    const verified = (refresh ? option?.dataset.liveVerified : option?.dataset.verified) === '1';
    receiver.disabled = form.querySelector('[data-ge-qbc-locked]')?.value === '1' && !override;
    if (verified && !override) issuer.value = (refresh ? option.dataset.liveIssuer : option.dataset.issuer) || '';
    issuer.disabled = verified && !override;
    const decision = JSON.parse((refresh ? option?.dataset.liveSuggestion : option?.dataset.suggestion) || '{}');
    form.querySelector('[data-ge-qbc-suggestion]').textContent = `${verified ? 'Datos fiscales verificados' : 'Situación fiscal no verificada'} · Comprobante sugerido: ${decision.suggested_document_class === 'unknown' ? 'Pendiente' : (decision.suggested_document_class || 'Pendiente')}\n${(decision.warnings || []).join('\n')}`;
  }
  document.addEventListener('click', event => {
    const opener = event.target.closest('[data-ge-qbc-open]');
    if (opener) {
      const dialog = document.getElementById(opener.dataset.geQbcOpen);
      if (!dialog) return;
      document.querySelectorAll('.ge-qbc-dialog[open]').forEach(d => d.close());
      lastTrigger = opener; dialog.showModal();
      if (dialog === selection) suggestion(dialog.querySelector('form'));
    }
    if (event.target.closest('[data-ge-qbc-close]')) event.target.closest('dialog').close();
  });
  document.querySelectorAll('.ge-qbc-dialog').forEach(dialog => dialog.addEventListener('close', () => {
    if (lastTrigger?.isConnected) lastTrigger.focus();
  }));
  document.addEventListener('change', event => {
    if (event.target.matches('[data-ge-qbc-receiver], [data-ge-qbc-override], [data-ge-qbc-refresh]')) suggestion(event.target.closest('form'));
  });
  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-ge-qbc-form]');
    if (!form) return;
    event.preventDefault();
    const status = form.querySelector('[data-ge-qbc-status]');
    const button = form.querySelector('[type=submit]');
    const body = new FormData(form);
    const issuer = form.querySelector('[data-ge-qbc-issuer]');
    if (issuer) body.set('issuer_profile_id', issuer.value);
    const receiver = form.querySelector('[data-ge-qbc-receiver]');
    if (receiver) body.set('billing_profile_id', receiver.value);
    body.set('action', 'ge_quote_billing'); body.set('nonce', geQuoteBilling.nonce);
    button.disabled = true; status.textContent = 'Guardando…';
    try {
      const response = await fetch(geQuoteBilling.url, {method: 'POST', credentials: 'same-origin', body});
      const result = await response.json();
      if (!result.success) throw new Error(result.data?.message || 'No se pudo guardar.');
      // Reload only after a confirmed successful write; quote remains untouched by customer writes.
      status.textContent = result.data.message;
      location.reload();
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  });
})();
