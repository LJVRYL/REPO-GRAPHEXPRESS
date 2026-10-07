(function () {
  'use strict';
  var form = document.querySelector('form.ge-quote-steps');
  if (!form) return;
  var summary = form.querySelector('[data-ge-form-errors]');
  var state = form.querySelector('[data-ge-save-state]');
  var progress = form.querySelector('[data-ge-progress]');
  var cards = Array.from(form.querySelectorAll(':scope > section.ge-production-card'));
  var busy = false, dirty = false;
  // Reuse the original controls and file widgets; moving between steps never rebuilds them.
  var vatCard = cards[5], discountCard = cards[6];
  var vatHeading = vatCard.querySelector('h2');
  var vatTitle = document.createElement('h3'); vatTitle.textContent = 'IVA de este presupuesto';
  vatHeading.replaceWith(vatTitle);
  discountCard.querySelector('h2').textContent = 'Descuentos e IVA';
  discountCard.appendChild(vatCard);
  vatCard.classList.remove('ge-production-card'); vatCard.classList.add('ge-quote-vat-section');
  cards.splice(5, 1);
  var reviewCard = document.createElement('section'); reviewCard.className = 'ge-production-card ge-quote-review';
  var reviewHeading = document.createElement('h2'); reviewHeading.textContent = 'Revisar y enviar'; reviewCard.appendChild(reviewHeading);
  var reviewBody = document.createElement('div'); reviewBody.dataset.geReview = '1'; reviewCard.appendChild(reviewBody);
  form.querySelector('.ge-manual-summary').before(reviewCard); cards.push(reviewCard);
  var currentStep = 0, reviewEnteredAt = 0, optionalSteps = [1, 3, 7];
  var back = form.querySelector('[data-ge-step-back]'), next = form.querySelector('[data-ge-step-next]');
  var skip = form.querySelector('[data-ge-step-skip]'), sendButton = form.querySelector('[value="send"]');
  var draftButton = form.querySelector('[value="draft"]');
  var counter = document.createElement('p'), meter = document.createElement('progress');
  counter.className = 'ge-quote-step-status'; counter.setAttribute('aria-live', 'polite');
  meter.max = cards.length; meter.setAttribute('aria-label', 'Progreso del presupuesto');
  progress.replaceChildren(counter, meter);
  cards.forEach(function (card, index) {
    card.id = 'ge-quote-step-' + (index + 1); card.tabIndex = -1;
    card.setAttribute('role', 'region');
    var heading = card.querySelector('h2'); heading.id = card.id + '-title'; heading.tabIndex = -1;
    card.setAttribute('aria-labelledby', heading.id);
    var old = card.querySelector('.ge-production-section-head > div > span'); if (old) old.hidden = true;
  });
  function selectedText(name, fallback) {
    var input = form.elements.namedItem(name);
    if (!input || !input.value) return fallback || 'Completar después';
    return input.tagName === 'SELECT' ? input.selectedOptions[0].textContent : input.value;
  }
  function reviewSection(title, step, rows) {
    var section = document.createElement('section'), heading = document.createElement('h3');
    var edit = document.createElement('button'); edit.type = 'button'; edit.className = 'ge-staff-button is-secondary';
    edit.textContent = 'Editar'; edit.setAttribute('aria-label', 'Editar ' + title);
    edit.addEventListener('click', function () { showStep(step, true); });
    heading.textContent = title; section.append(heading, edit);
    rows.forEach(function (row) { var p = document.createElement('p'), label = document.createElement('strong'); label.textContent = row[0] + ': '; p.append(label, document.createTextNode(row[1] || 'Completar después')); section.appendChild(p); });
    reviewBody.appendChild(section); return section;
  }
  var liveTotals = form.querySelector('[data-ge-live-totals]'), reviewTotals;
  function syncTotals() {
    if (!reviewTotals || !liveTotals) return;
    reviewTotals.replaceChildren();
    if (liveTotals.hasAttribute('aria-busy')) { reviewTotals.textContent = 'Actualizando importes…'; return; }
    var copy = liveTotals.cloneNode(true); copy.removeAttribute('data-ge-live-totals'); copy.removeAttribute('role'); copy.removeAttribute('aria-live');
    copy.querySelectorAll('[id]').forEach(function (node) { node.removeAttribute('id'); });
    reviewTotals.appendChild(copy);
  }
  function buildReview() {
    reviewBody.replaceChildren();
    reviewSection('Cliente', 0, [['Nombre', selectedText('customer_name')], ['Email', selectedText('customer_email')], ['Teléfono', selectedText('customer_phone', 'Sin especificar')]]);
    reviewSection('Datos fiscales', 1, [['CUIT', selectedText('customer_cuit')], ['Razón social', selectedText('tax_legal_name')], ['Condición fiscal', selectedText('tax_vat_status')], ['Domicilio fiscal', selectedText('tax_fiscal_address')]]);
    reviewSection('Emisor', 2, [['Facturación', selectedText('issuer_profile_id')]]);
    reviewSection('Receptor y entrega', 3, [['Facturar a', selectedText('billing_profile_id')], ['Entregar en', selectedText('delivery_address_id', 'A coordinar')]]);
    var rows = [];
    form.querySelectorAll('[data-ge-line]').forEach(function (line) {
      var title = line.querySelector('input[type="search"]'); if (!title.value.trim()) return;
      var quantity = line.querySelector('[name$="[quantity]"]'), unit = line.querySelector('[name$="[unit]"]'), subtotal = line.querySelector('[data-ge-line-subtotal]');
      var selection = line.querySelector('[name$="[selection_type]"]');
      rows.push([title.value, (quantity.value || 'Cantidad pendiente') + ' ' + unit.value + ' · ' + subtotal.textContent + (selection ? ' · ' + selection.selectedOptions[0].textContent : '')]);
    });
    reviewSection('Productos y servicios', 4, rows.length ? rows : [['Ítems', 'Sin productos; podés guardar el borrador y completarlos después.']]);
    var amountSection = reviewSection('Descuentos e IVA', 5, [['Descuento', selectedText('discount_value') + (selectedText('discount_type') === 'Porcentaje' ? '%' : ' ARS')], ['Tratamiento', selectedText('quote_vat_mode', 'Configuración actual del emisor')]]);
    reviewTotals = document.createElement('div'); reviewTotals.className = 'ge-quote-review-totals'; reviewTotals.setAttribute('role', 'status'); reviewTotals.setAttribute('aria-live', 'polite'); amountSection.appendChild(reviewTotals); syncTotals();
    reviewSection('Validez, pago y notas', 6, [['Válido hasta', selectedText('valid_until')], ['Seña', deposit.checked ? selectedText('deposit_percent') + '%' : 'Sin seña'], ['Notas para el cliente', selectedText('notes_customer', 'Sin notas')], ['Notas internas (privadas)', selectedText('notes_internal', 'Sin notas')]]);
    var files = [];
    form.querySelectorAll('[data-ge-artwork-list]').forEach(function (list) { if (list.textContent.trim()) files.push(list.textContent.trim()); });
    form.querySelectorAll('input[type="file"]').forEach(function (input) { Array.from(input.files || []).forEach(function (file) { files.push(file.name); }); });
    reviewSection('Archivos', 7, [['Adjuntos', files.length ? files.join(' · ') : 'Sin archivos; son opcionales.']]);
  }
  if (liveTotals) new MutationObserver(syncTotals).observe(liveTotals, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['aria-busy'] });
  function showStep(index, focus) {
    currentStep = Math.max(0, Math.min(index, cards.length - 1));
    cards.forEach(function (card, i) { card.hidden = i !== currentStep; if (i === currentStep) card.setAttribute('aria-current', 'step'); else card.removeAttribute('aria-current'); });
    counter.textContent = 'Paso ' + (currentStep + 1) + ' de ' + cards.length;
    meter.value = currentStep + 1;
    back.hidden = currentStep === 0; next.hidden = currentStep === cards.length - 1;
    next.textContent = currentStep === cards.length - 2 ? 'Revisar presupuesto' : 'Continuar';
    skip.hidden = !optionalSteps.includes(currentStep); sendButton.hidden = currentStep !== cards.length - 1;
    if (currentStep === cards.length - 1) { reviewEnteredAt = Date.now(); buildReview(); }
    if (focus) { var heading = cards[currentStep].querySelector('h2'); heading.scrollIntoView({ block: 'start', behavior: 'auto' }); heading.focus({ preventScroll: true }); }
  }
  function contactErrors() {
    var errors = [];
    ['customer_name', 'customer_email'].forEach(function (name) { var input = form.elements.namedItem(name); if (!input.value.trim() || !input.validity.valid) errors.push({ field: input, message: name === 'customer_name' ? 'Ingresá el nombre del cliente.' : 'Ingresá un email válido.' }); });
    return errors;
  }
  function updateDraftButton() { if (!busy) draftButton.disabled = contactErrors().length > 0; }
  function discountErrors(scope) {
    var errors = [];
    scope.querySelectorAll('[name="discount_value"], [name$="[discount_value]"]').forEach(function (input) {
      var prefix = input.name === 'discount_value' ? '' : input.name.slice(0, -'[discount_value]'.length);
      var type = form.elements.namedItem(prefix ? prefix + '[discount_type]' : 'discount_type');
      var reason = form.elements.namedItem(prefix ? prefix + '[discount_reason]' : 'discount_reason');
      var value = Number(input.value);
      if (type && type.value === 'percent' && value > 100) errors.push({ field: input, message: 'El descuento porcentual no puede superar el 100%.' });
      if (value > 0 && reason && !reason.value.trim()) errors.push({ field: reason, message: 'Indicá el motivo comercial del descuento.' });
    });
    return errors;
  }
  function stepErrors(card) {
    var errors = card === cards[0] ? contactErrors() : [];
    card.querySelectorAll('input,select,textarea').forEach(function (input) {
      if (input.disabled || input.type === 'hidden' || input.type === 'file' || ['customer_name', 'customer_email'].includes(input.name)) return;
      var line = input.closest('[data-ge-line]'); if (line && !line.querySelector('input[type="search"]').value.trim()) return;
      if ((input.value || (line && input.required)) && !input.validity.valid) {
        var label = input.closest('label'), caption = label && label.firstChild ? label.firstChild.textContent.trim() : 'Este dato';
        errors.push({ field: input, message: caption + ': ' + (input.value ? 'ingresá un valor válido.' : 'completá este campo para continuar.') });
      }
    });
    return errors.concat(discountErrors(card));
  }
  back.addEventListener('click', function (event) { if (!busy && event.detail < 2) { clearErrors(); showStep(currentStep - 1, true); } });
  next.addEventListener('click', function (event) { if (busy || event.detail > 1) return; var errors = stepErrors(cards[currentStep]); if (errors.length) { showErrors(errors); return; } clearErrors(); showStep(currentStep + 1, true); });
  skip.addEventListener('click', function (event) { if (!busy && event.detail < 2) { clearErrors(); showStep(currentStep + 1, true); } });
  sendButton.addEventListener('click', function (event) { if (event.detail > 1 || Date.now() - reviewEnteredAt < 400) event.preventDefault(); });
  form.addEventListener('keydown', function (event) {
    // Enter in a field must never submit or send; Enter on an explicit button still works.
    if (event.key === 'Enter' && event.target.tagName === 'INPUT' && ['text', 'search', 'email', 'tel', 'url', 'number', 'password'].includes(event.target.type)) event.preventDefault();
    if (event.key === 'Enter' && event.repeat) event.preventDefault();
  });
  function go(field) {
    var step = cards.findIndex(function (card) { return card === field || card.contains(field); });
    if (step !== -1 && step !== currentStep) showStep(step, false);
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
    var firstField;
    errors.forEach(function (error, index) {
      var field = typeof error.field === 'string' ? form.elements.namedItem(error.field) : error.field;
      if (!field || !field.tagName) field = form.querySelector(error.field === 'general_files' ? '[data-ge-artwork]' : '[data-ge-line] input[type="search"]');
      if (!field) field = cards[0];
      if (!firstField) firstField = field;
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
    var errorStep = cards.findIndex(function (card) { return card === firstField || card.contains(firstField); });
    if (errorStep !== -1) showStep(errorStep, false);
    summary.hidden = false; go(summary);
  }
  function invalidateReview(event) { var confirmation = form.elements.namedItem('customer_tax_confirm'); if (confirmation && event.target !== confirmation) confirmation.checked = false; }
  form.addEventListener('input', function (event) { dirty = true; invalidateReview(event); updateDraftButton(); if (!busy) state.textContent = 'Cambios sin guardar. Podés guardar el borrador en cualquier momento.'; });
  form.addEventListener('change', updateDraftButton);
  form.addEventListener('change', invalidateReview);
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
    if (review) { review.closest('label').lastChild.textContent = ' Revisé la propuesta y confirmo el receptor y el emisor para enviarla'; reviewCard.appendChild(review.closest('label')); }
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
            updateDraftButton();
          }); results.appendChild(button);
        });
      }).catch(function () { if (ticket === sequence) results.textContent = 'No se pudo buscar. Podés ingresar el email; al guardar se reutiliza la ficha existente.'; });
    }, 250);
  });
  form.addEventListener('submit', function (event) {
    event.preventDefault(); if (busy) return;
    if (!event.submitter) return;
    var intent = event.submitter.value;
    if (intent !== 'draft' && intent !== 'send') return;
    if (intent === 'send' && currentStep !== cards.length - 1) { showStep(cards.length - 1, true); return; }
    var errors = contactErrors();
    form.querySelectorAll('input,select,textarea').forEach(function (input) {
      if (input.disabled || input.type === 'file' || input.name === 'customer_name' || input.name === 'customer_email') return;
      var line = input.closest('[data-ge-line]');
      if (line && !line.querySelector('input[type="search"]').value.trim()) return;
      if (intent === 'draft' && !input.value) return;
      if (input.value && (input.validity.rangeUnderflow || input.validity.rangeOverflow || input.validity.stepMismatch || input.validity.badInput)) errors.push({ field: input, message: (input.closest('label') ? input.closest('label').childNodes[0].textContent.trim() : 'Dato') + ': ingresá un valor válido.' });
    });
    if (intent === 'send') {
      errors = errors.concat(discountErrors(form));
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
    [back, next, skip].forEach(function (button) { button.disabled = true; });
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
      busy = false; form.removeAttribute('aria-busy'); buttons.concat([back, next, skip]).forEach(function (button) { button.disabled = false; }); updateDraftButton();
    });
  });
  form.classList.add('ge-quote-wizard'); showStep(0, false); updateDraftButton();
}());
