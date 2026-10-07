(function () {
  'use strict';
  var form = document.querySelector('form.ge-quote-steps');
  if (!form) return;
  var summary = form.querySelector('[data-ge-form-errors]');
  var state = form.querySelector('[data-ge-save-state]');
  var progress = form.querySelector('[data-ge-progress]');
  var cards = Array.from(form.querySelectorAll(':scope > section.ge-production-card'));
  var busy = false, dirty = false;
  cards.forEach(function (card, index) {
    card.id = 'ge-quote-step-' + (index + 1);
    card.tabIndex = -1;
    var heading = card.querySelector('h2');
    var number = document.createElement('span');
    number.className = 'ge-quote-step-number'; number.textContent = String(index + 1).padStart(2, '0');
    heading.prepend(number);
    var link = document.createElement('a'); link.href = '#' + card.id;
    var fullLabel = (index + 1) + '. ' + heading.textContent.substring(2);
    link.setAttribute('aria-label', fullLabel);
    var full = document.createElement('span'), short = document.createElement('span');
    full.className = 'ge-quote-nav-full'; full.textContent = fullLabel;
    short.className = 'ge-quote-nav-short'; short.textContent = (index + 1) + '. ' + (['Cliente', 'Fiscales', 'Emisor', 'Entrega', 'Productos', 'IVA', 'Descuentos', 'Condiciones', 'Archivos'][index] || heading.textContent.substring(2));
    link.append(full, short);
    link.addEventListener('click', function (event) { event.preventDefault(); go(card); });
    progress.appendChild(link);
    var old = card.querySelector('.ge-production-section-head > div > span');
    if (old) old.hidden = true;
  });
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      var current = entries.find(function (entry) { return entry.isIntersecting; });
      if (!current) return;
      progress.querySelectorAll('a').forEach(function (link) {
        if (link.getAttribute('href') === '#' + current.target.id) link.setAttribute('aria-current', 'step');
        else link.removeAttribute('aria-current');
      });
    }, { rootMargin: '-10% 0px -60% 0px' });
    cards.forEach(function (card) { observer.observe(card); });
  }
  function go(field) {
    for (var parent = field.parentElement; parent && parent !== form; parent = parent.parentElement) {
      if (parent.tagName === 'DETAILS') parent.open = true;
    }
    field.scrollIntoView({ block: 'center', behavior: 'auto' });
    field.focus({ preventScroll: true });
  }
  function clearErrors() {
    summary.replaceChildren(); summary.hidden = true;
    form.querySelectorAll('[data-ge-field-error]').forEach(function (error) { error.remove(); });
    form.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
      input.removeAttribute('aria-invalid');
      var ids = (input.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return !id.startsWith('ge-error-'); });
      if (ids.length) input.setAttribute('aria-describedby', ids.join(' ')); else input.removeAttribute('aria-describedby');
    });
  }
  function showErrors(errors) {
    clearErrors();
    var title = document.createElement('h2'); title.textContent = 'Revisá estos datos'; summary.appendChild(title);
    var list = document.createElement('ul'); summary.appendChild(list);
    errors.forEach(function (error, index) {
      var field = typeof error.field === 'string' ? form.elements.namedItem(error.field) : error.field;
      if (!field || !field.tagName) field = form.querySelector(error.field === 'general_files' ? '[data-ge-artwork]' : '[data-ge-line] input[type="search"]');
      if (!field) field = cards[0];
      if (!field.id) field.id = 'ge-field-' + index + '-' + Date.now();
      var message = document.createElement('p'); message.id = 'ge-error-' + index; message.dataset.geFieldError = '1';
      message.className = 'ge-quote-field-error'; message.textContent = error.message;
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + message.id).trim());
      field.insertAdjacentElement('afterend', message);
      var item = document.createElement('li'), link = document.createElement('a');
      link.href = '#' + field.id; link.textContent = error.message;
      link.addEventListener('click', function (event) { event.preventDefault(); go(field); });
      item.appendChild(link); list.appendChild(item);
    });
    summary.hidden = false; go(summary);
  }
  form.addEventListener('input', function () { dirty = true; if (!busy) state.textContent = 'Cambios sin guardar. Podés guardar el borrador en cualquier momento.'; });
  window.addEventListener('beforeunload', function (event) { if (dirty && !busy) { event.preventDefault(); event.returnValue = ''; } });
  var deposit = form.querySelector('[data-ge-deposit-toggle]'), depositField = form.querySelector('[data-ge-deposit-field]');
  function updateDeposit() { depositField.hidden = !deposit.checked; depositField.querySelector('input').disabled = !deposit.checked; }
  deposit.addEventListener('change', updateDeposit); updateDeposit();
  // Keep audit controls available, but out of ordinary data entry.
  var issuer = form.querySelector('.ge-billing-issuer-picker');
  if (issuer) {
    var reason = issuer.querySelector('[name="issuer_change_reason"]');
    var issuerSelect = issuer.querySelector('[name="issuer_profile_id"]');
    if (!form.elements.namedItem('quote_id') && issuerSelect.options[0].value === '') issuerSelect.options[0].textContent = 'Completar después';
    if (reason) {
      var advanced = document.createElement('details'), caption = document.createElement('summary');
      advanced.className = 'ge-quote-advanced'; caption.textContent = 'Cambiar el emisor de una versión existente';
      advanced.appendChild(caption); issuer.appendChild(advanced); advanced.appendChild(reason.closest('label'));
      if (!form.elements.namedItem('quote_id')) advanced.hidden = true;
      var refresh = issuer.querySelector('[name="issuer_refresh"]'); if (refresh) advanced.appendChild(refresh.closest('label'));
    }
    var review = issuer.querySelector('[name="customer_tax_confirm"]');
    if (review) review.closest('label').lastChild.textContent = ' Confirmo el receptor y el emisor para enviar esta propuesta';
  }
  var cuit = form.elements.namedItem('customer_cuit'), billing = form.elements.namedItem('billing_profile_id');
  function showCuit() {
    var option = Array.from(billing.options).find(function (item) { return item.value === 'default'; });
    if (option && cuit.value.trim()) option.textContent = 'Perfil principal · CUIT ' + cuit.value.trim();
  }
  cuit.addEventListener('input', showCuit);
  var profileData = [], taxTouched = false;
  var taxNames = {customer_cuit: 'cuit', tax_legal_name: 'legal_name', tax_vat_status: 'vat_status', tax_fiscal_address: 'fiscal_address'};
  Object.keys(taxNames).forEach(function (name) { form.elements.namedItem(name).addEventListener('input', function () { taxTouched = true; }); });
  function reuseProfile(force) {
    if (!force && (taxTouched || form.elements.namedItem('quote_id'))) return;
    var profile = profileData.find(function (item) { return item.id === billing.value; });
    if (!profile) return;
    Object.keys(taxNames).forEach(function (name) { form.elements.namedItem(name).value = profile[taxNames[name]] || ''; });
    taxTouched = false;
  }
  form.addEventListener('ge:quote-profiles', function (event) { profileData = event.detail; reuseProfile(false); showCuit(); });
  billing.addEventListener('change', function () { reuseProfile(true); });
  form.elements.namedItem('customer_email').addEventListener('change', function () {
    if (form.elements.namedItem('quote_id')) return;
    taxTouched = false; Object.keys(taxNames).forEach(function (name) { form.elements.namedItem(name).value = ''; });
  });
  var search = form.querySelector('[data-ge-customer-search]'), results = form.querySelector('[data-ge-customer-results]');
  if (form.elements.namedItem('quote_id')) search.closest('.ge-quote-customer-search').hidden = true;
  var timer, sequence = 0;
  search.addEventListener('input', function () {
    clearTimeout(timer); var ticket = ++sequence; results.replaceChildren();
    if (search.value.trim().length < 2) return;
    timer = setTimeout(function () {
      var query = new URLSearchParams({ action: 'ge_quote_customer_search', nonce: geCommercialQuotes.customerNonce, q: search.value.trim() });
      fetch(geCommercialQuotes.ajaxUrl + '?' + query, { credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (response) {
        if (ticket !== sequence) return;
        if (!response.success) throw new Error('search');
        if (!response.data.length) { results.textContent = 'No encontramos una ficha. Completá nombre y email para registrarla al guardar.'; return; }
        response.data.forEach(function (customer) {
          var button = document.createElement('button'); button.type = 'button';
          button.textContent = customer.name + ' · ' + customer.email;
          button.addEventListener('click', function () {
            form.elements.namedItem('customer_name').value = customer.name;
            var email = form.elements.namedItem('customer_email'); email.value = customer.email;
            email.dispatchEvent(new Event('change', { bubbles: true }));
            results.textContent = 'Ficha seleccionada: ' + customer.name + '. Se vinculará al guardar el presupuesto.';
            dirty = true; state.textContent = 'Cliente seleccionado. Guardá el borrador para vincularlo.';
          }); results.appendChild(button);
        });
      }).catch(function () { if (ticket === sequence) results.textContent = 'No se pudo buscar. Podés ingresar el email; al guardar se reutiliza la ficha existente.'; });
    }, 250);
  });
  form.addEventListener('submit', function (event) {
    event.preventDefault(); if (busy) return;
    var intent = event.submitter ? event.submitter.value : 'draft';
    var errors = [];
    ['customer_name', 'customer_email'].forEach(function (name) {
      var input = form.elements.namedItem(name);
      if (!input.value.trim() || !input.validity.valid) errors.push({ field: input, message: name === 'customer_name' ? 'Ingresá el nombre del cliente.' : 'Ingresá un email válido.' });
    });
    form.querySelectorAll('input,select,textarea').forEach(function (input) {
      if (input.disabled || input.type === 'file' || input.name === 'customer_name' || input.name === 'customer_email') return;
      var line = input.closest('[data-ge-line]');
      if (line && !line.querySelector('input[type="search"]').value.trim()) return;
      if (intent === 'draft' && !input.value) return;
      if (input.value && (input.validity.rangeUnderflow || input.validity.rangeOverflow || input.validity.stepMismatch || input.validity.badInput)) errors.push({ field: input, message: (input.closest('label') ? input.closest('label').childNodes[0].textContent.trim() : 'Dato') + ': ingresá un valor válido.' });
    });
    if (intent === 'send') {
      var named = [['billing_profile_id', 'Elegí el receptor fiscal antes de enviar.'], ['issuer_profile_id', 'Elegí el emisor antes de enviar.']];
      named.forEach(function (pair) { var input = form.elements.namedItem(pair[0]); if (!input.value) errors.push({ field: input, message: pair[1] }); });
      var review = form.elements.namedItem('customer_tax_confirm'); if (review && !review.checked) errors.push({ field: review, message: 'Confirmá receptor y emisor antes de enviar.' });
      var lines = Array.from(form.querySelectorAll('[data-ge-line]'));
      if (!lines.some(function (line) { return line.querySelector('input[type="search"]').value.trim(); })) errors.push({ field: 'lines', message: 'Agregá un producto o servicio antes de enviar.' });
      lines.forEach(function (line) { if (line.querySelector('input[type="search"]').value.trim()) line.querySelectorAll('[required]').forEach(function (input) { if (!input.value || !input.validity.valid) errors.push({ field: input, message: 'Completá los datos de este producto antes de enviar.' }); }); });
    }
    if (errors.length) { showErrors(errors); return; }
    clearErrors();
    var data = new FormData(form); data.set('quote_intent', intent); data.set('ge_quote_async', '1');
    busy = true; form.setAttribute('aria-busy', 'true');
    var buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
    buttons.forEach(function (button) { button.disabled = true; });
    state.textContent = intent === 'send' ? 'Guardando y enviando…' : 'Guardando borrador…';
    fetch(form.getAttribute('action'), { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
      if (response.redirected && response.url.includes('quote_id=')) { dirty = false; window.location.assign(response.url); return null; }
      return response.json();
    }).then(function (response) {
      if (!response) return;
      if (!response.success) {
        if (response.data.saved_quote) {
          var saved = response.data.saved_quote;
          [['quote_id', saved.id], ['expected_version', saved.version], ['expected_hash', saved.hash], ['artwork_session', saved.session]].forEach(function (pair) {
            var input = form.elements.namedItem(pair[0]);
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = pair[0]; form.appendChild(input); }
            input.value = pair[1];
          });
        }
        showErrors([response.data]); state.textContent = 'Revisá el dato indicado. Los valores y archivos siguen en el formulario.'; return;
      }
      dirty = false; state.textContent = 'Presupuesto guardado.'; window.location.assign(response.data.url);
    }).catch(function (error) {
      console.warn('No se pudo confirmar el guardado del presupuesto:', error.name);
      showErrors([{ field: 'customer_email', message: 'No pudimos confirmar el guardado. Revisá la conexión y reintentá: se conserva la sesión para evitar duplicados.' }]);
      state.textContent = 'Guardado sin confirmar. No cierres el formulario.';
    }).finally(function () {
      busy = false; form.removeAttribute('aria-busy'); buttons.forEach(function (button) { button.disabled = false; });
    });
  });
}());
