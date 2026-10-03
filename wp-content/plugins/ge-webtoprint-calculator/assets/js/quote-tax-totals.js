(function () {
  'use strict';
  var form = document.querySelector('form.ge-commercial-quote');
  if (!form || !window.geCommercialQuotes) return;
  var output = form.querySelector('[data-ge-live-totals]'), timer, serial = 0;
  function update() {
    var current = ++serial;
    // Omit file inputs: calculating totals must never upload artwork.
    var data = new FormData();
    form.querySelectorAll('input, select, textarea').forEach(function (input) {
      if (!input.name || input.disabled || input.type === 'file' || ((input.type === 'checkbox' || input.type === 'radio') && !input.checked)) return;
      data.append(input.name, input.value);
    });
    data.set('action', 'ge_commercial_quote_totals'); data.set('nonce', geCommercialQuotes.nonce);
    output.setAttribute('aria-busy', 'true');
    fetch(geCommercialQuotes.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: data})
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (current !== serial) return;
        if (result.success) output.innerHTML = result.data.html;
        else output.textContent = typeof result.data === 'string' ? result.data : 'Revisá cliente, ítems e importes.';
      }).catch(function () { if (current === serial) output.textContent = 'No se pudo calcular. Revisá la conexión antes de enviar.'; })
      .finally(function () { if (current === serial) output.removeAttribute('aria-busy'); });
  }
  function schedule() { serial++; clearTimeout(timer); timer = setTimeout(update, 350); }
  form.addEventListener('input', schedule); form.addEventListener('change', schedule);
  var alternative = form.querySelector('[data-ge-discount-alternative]');
  if (alternative) alternative.addEventListener('click', function () {
    form.elements.discount_type.value = 'percent'; form.elements.discount_value.value = '20';
    if (!form.elements.discount_reason.value) form.elements.discount_reason.value = 'Condición comercial alternativa';
    schedule();
  });
  new MutationObserver(schedule).observe(form.querySelector('[data-ge-lines]'), {childList: true, subtree: true, characterData: true});
  update();
}());
