(function () {
  'use strict';
  var fiscalLink = document.querySelector('.ge-quote-fiscal-alert a');
  if (fiscalLink) fiscalLink.addEventListener('click', function () {
    var section = document.getElementById('ge-quote-fiscal');
    if (section) { section.open = true; section.querySelector('summary').focus(); }
  });
  var convertForm = document.querySelector('#ge-quote-convert form');
  if (convertForm) convertForm.addEventListener('submit', function (event) {
    if (convertForm.dataset.submitting) { event.preventDefault(); return; }
    convertForm.dataset.submitting = '1';
    var button = convertForm.querySelector('button[type="submit"]');
    button.disabled = true; button.textContent = 'Procesandoâ€¦';
    button.setAttribute('aria-busy', 'true');
  });

  document.querySelectorAll('[data-ge-choice-group]').forEach(function (group) {
    var prices;
    try { prices = JSON.parse(group.querySelector('[data-ge-choice-prices]').textContent); } catch (e) { return; }
    var selects = group.querySelectorAll('select'), finish = selects[0], paper = selects[1];
    var output = group.querySelector('[data-ge-choice-total]');
    function update() {
      var radio = group.querySelector('input[type="radio"]:checked');
      if (!radio) return;
      var choices = prices.filter(function (p) { return p.model === radio.value; });
      Array.from(finish.options).forEach(function (o) { o.disabled = !choices.some(function (p) { return p.finish === o.value; }); });
      if (finish.selectedOptions[0].disabled) finish.value = choices[0].finish;
      var price = choices.find(function (p) { return p.finish === finish.value; });
      Array.from(paper.options).forEach(function (o) { o.disabled = !price.papers.includes(o.value); });
      if (paper.selectedOptions[0].disabled) paper.value = price.papers[0];
      output.textContent = 'Total de esta opciÃ³n: ' + new Intl.NumberFormat('es-AR', {style:'currency',currency:'ARS',maximumFractionDigits:2}).format(price.price / 100);
      group.querySelectorAll('.ge-choice-model').forEach(function (card) { card.classList.toggle('is-selected', card.querySelector('input').checked); });
    }
    group.addEventListener('change', update); update();
  });


  document.querySelectorAll('[data-ge-save-selection]').forEach(function (button) {
    var form = document.getElementById(button.getAttribute('form'));
    if (!form) return;
    form.addEventListener('submit', function (event) {
      if (event.submitter !== button) return;
      if (form.dataset.savingSelection) { event.preventDefault(); return; }
      form.dataset.savingSelection = '1';
      button.setAttribute('aria-busy', 'true'); button.textContent = 'Guardando selecciÃ³nâ€¦';
      // Do not disable the submitter: its action value must be included in the POST.
    });
  });
  var savedPdf = document.querySelector('[data-ge-saved-pdf]');
  if (savedPdf) {
    var downloadFrame = document.createElement('iframe');
    downloadFrame.hidden = true; downloadFrame.title = 'Descarga del presupuesto guardado';
    downloadFrame.src = savedPdf.href; document.body.appendChild(downloadFrame);
  }

  var root = document.querySelector('[data-ge-lines]');
  var template = document.getElementById('ge-manual-line-template');
  var catalogNode = document.getElementById('ge-manual-catalog');
  if (!root || !template || !catalogNode) return;
  var catalog = {};
  try { catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (error) { return; }
  var branchPicker = document.querySelector('[data-ge-branch-picker]');
  if (branchPicker) {
    var emailInput = document.querySelector('input[name="customer_email"]');
    var billingSelect = branchPicker.querySelector('[data-ge-billing-profile]');
    var deliverySelect = branchPicker.querySelector('[data-ge-delivery-address]');
    var selected = {};
    try { selected = JSON.parse(document.querySelector('[data-ge-branch-selected]').textContent || '{}'); } catch (error) { selected = {}; }
    var requestNumber = 0;
    function addOption(select, value, label) {
      var option = document.createElement('option'); option.value = value; option.textContent = label; select.appendChild(option);
    }
    function loadBranches() {
      var number = ++requestNumber;
      var query = new URLSearchParams({ action: 'ge_customer_branch_options', _ajax_nonce: branchPicker.dataset.nonce, email: emailInput.value });
      fetch(branchPicker.dataset.ajax + '?' + query.toString(), { credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (result) {
        if (number !== requestNumber || !result.success) return;
        var previousProfile = billingSelect.value || selected.profile || '';
        var previousDelivery = deliverySelect.value || selected.delivery || '';
        billingSelect.replaceChildren(); deliverySelect.replaceChildren();
        addOption(deliverySelect, '', 'A coordinar');
        addOption(billingSelect, '', 'ElegÃ­ receptor');
        (result.data.profiles || []).forEach(function (profile) { addOption(billingSelect, profile.id, profile.label + (profile.cuit ? ' Â· CUIT ' + profile.cuit : '')); });
        if (!(result.data.profiles || []).length) addOption(billingSelect, 'default', 'Perfil principal');
        (result.data.addresses || []).forEach(function (address) { addOption(deliverySelect, address.id, address.label + ' Â· ' + address.street); });
        billingSelect.value = previousProfile;
        if (billingSelect.selectedIndex < 0) billingSelect.value = '';
        if (!previousProfile && (result.data.profiles || []).length === 1) billingSelect.value = result.data.profiles[0].id;
        deliverySelect.value = previousDelivery;
        if (deliverySelect.selectedIndex < 0) deliverySelect.value = '';
        if (!previousDelivery && deliverySelect.options.length === 2) deliverySelect.selectedIndex = 1;
        selected = {};
      }).catch(function () { /* Keep the existing selections when the lookup is unavailable. */ });
    }
    emailInput.addEventListener('change', loadBranches);
    if (emailInput.value) loadBranches();
  }
  var nextIndex = root.querySelectorAll('[data-ge-line]').length;

  function field(tag, name, label, type) {
    var wrapper = document.createElement('label');
    wrapper.textContent = label;
    var input = document.createElement(tag);
    input.name = name;
    if (type) input.type = type;
    wrapper.appendChild(input);
    return { wrapper: wrapper, input: input };
  }

  function connect(line) {
    var search = line.querySelector('input[type="search"]');
    var source = line.querySelector('input[name$="[source_type]"]');
    var productId = line.querySelector('input[name$="[product_id]"]');
    var quantity = line.querySelector('input[name$="[quantity]"]');
    var price = line.querySelector('input[name$="[unit_price]"]');
    var hint = line.querySelector('[data-ge-price-hint]');
    var panel = line.querySelector('[data-ge-config-fields]');
    var subtotal = line.querySelector('[data-ge-line-subtotal]');
    var index = line.getAttribute('data-ge-index');
    var selected = {};
    try { selected = JSON.parse(line.getAttribute('data-ge-saved-config') || '{}'); } catch (error) { selected = {}; }
    var ticket = 0;

    function showSubtotal() {
      var cents = Math.round(Number(price.value || 0) * 100) * Number(quantity.value || 0);
      subtotal.textContent = 'Subtotal ' + (Number.isFinite(cents) ? new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(cents / 100) : 'ARS 0');
    }

    function setSource(type) {
      source.value = type;
      line.dataset.geSource = type;
      line.querySelector('[data-ge-title-label]').textContent = type === 'custom' ? 'Nombre del trabajo' : 'Producto del catÃ¡logo';
      search.placeholder = type === 'custom' ? 'Ej.: Almohada bamboo 45Ã—30 cm' : 'Buscar producto';
      if (type === 'custom') {
        search.removeAttribute('list'); productId.value = ''; panel.replaceChildren(); panel.hidden = true;
        manual('IngresÃ¡ el precio acordado antes de IVA.');
      } else {
        search.setAttribute('list', 'ge-manual-products');
      }
    }
    line.geSetSource = setSource;

    function manual(message) {
      price.readOnly = false;
      hint.textContent = message || 'Precio manual antes de IVA.';
      quantity.readOnly = false;
      showSubtotal();
    }

    function refreshPrice() {
      if (source.value === 'custom') { showSubtotal(); return; }
      var item = catalog[search.value];
      if (!item || !item.id) return;
      if (item.mode === 'manual') { manual('Sin tarifa en catÃ¡logo: completÃ¡ el precio.'); return; }
      if (item.mode === 'fixed') {
        price.readOnly = Number(item.price) > 0;
        if (price.readOnly) price.value = Number(item.price).toFixed(2);
        hint.textContent = price.readOnly ? 'Precio calculado desde el catÃ¡logo.' : 'CompletÃ¡ el precio manualmente.';
        showSubtotal();
        return;
      }
      var fields = panel.querySelectorAll('input, select');
      var configuration = {};
      fields.forEach(function (input) {
        var key = input.name.match(/\[configuration\]\[([^\]]+)\]/);
        if (key) configuration[key[1]] = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
      });
      if (item.mode !== 'digital' && !configuration.option_key) {
        price.value = '';
        price.readOnly = true;
        hint.textContent = 'ElegÃ­ una configuraciÃ³n para calcular el precio.';
        return;
      }
      if (item.mode === 'option' || item.mode === 'variation') {
        var selectedOption = item.options[configuration.option_key];
        if (selectedOption) {
          quantity.min = selectedOption.min_qty || 1;
          quantity.step = selectedOption.step || 1;
          if (selectedOption.fixed_qty) quantity.value = selectedOption.fixed_qty;
          else if (Number(quantity.value) < Number(quantity.min)) quantity.value = quantity.min;
          quantity.readOnly = Boolean(selectedOption.fixed_qty);
        }
      }
      var current = ++ticket;
      price.readOnly = true;
      hint.textContent = 'Calculando con la tarifa del sistemaâ€¦';
      var body = new URLSearchParams();
      body.set('action', 'ge_commercial_quote_price');
      body.set('nonce', geCommercialQuotes.nonce);
      body.set('product_id', item.id);
      body.set('quantity', quantity.value);
      Object.keys(configuration).forEach(function (key) { body.set('configuration[' + key + ']', configuration[key]); });
      fetch(geCommercialQuotes.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) { return response.json(); }).then(function (result) {
        if (current !== ticket) return;
        if (!result.success) { price.value = ''; hint.textContent = typeof result.data === 'string' ? result.data : 'RevisÃ¡ la configuraciÃ³n.'; return; }
        if (result.data.quantity) quantity.value = result.data.quantity;
        if (result.data.manual) { price.readOnly = false; hint.textContent = 'Esta combinaciÃ³n requiere precio manual antes de IVA.'; }
        else { price.value = Number(result.data.price).toFixed(2); price.readOnly = true; hint.textContent = result.data.configuration && result.data.configuration.roll_width_cm ? 'Precio calculado con rollo de ' + result.data.configuration.roll_width_cm + ' cm de ancho.' : 'Precio calculado desde el catÃ¡logo.'; }
        showSubtotal();
      }).catch(function () {
        if (current === ticket) { price.value = ''; price.readOnly = true; hint.textContent = 'No se pudo consultar la tarifa. ReintentÃ¡ al cambiar una opciÃ³n.'; }
      });
    }

    function renderConfiguration(preserve) {
      ticket++;
      panel.replaceChildren();
      if (source.value === 'custom') { setSource('custom'); return; }
      var item = catalog[search.value];
      productId.value = item ? item.id : '';
      panel.hidden = !item || !['option', 'variation', 'digital'].includes(item.mode);
      quantity.readOnly = false;
      quantity.min = 1; quantity.step = 1;
      if (!item) { price.value = ''; price.readOnly = true; hint.textContent = 'SeleccionÃ¡ un producto del catÃ¡logo.'; showSubtotal(); return; }
      if (!preserve) { price.value = ''; selected = {}; }
      if (item.mode === 'option' || item.mode === 'variation') {
        var choice = field('select', 'lines[' + index + '][configuration][option_key]', item.label || 'ConfiguraciÃ³n');
        var prompt = document.createElement('option'); prompt.value = ''; prompt.textContent = 'Seleccionar opciÃ³n'; choice.input.appendChild(prompt);
        Object.keys(item.options).forEach(function (key) {
          var option = document.createElement('option'); option.value = key; option.textContent = item.options[key].label; choice.input.appendChild(option);
        });
        choice.input.value = selected.option_key || '';
        panel.appendChild(choice.wrapper);
        if (item.measure === 'm2' || item.measure === 'ml') {
          (item.measure === 'm2' ? [['width', item.roll_widths_cm && item.roll_widths_cm.length ? 'Ancho final (cm)' : 'Ancho (cm)'], ['height', item.roll_widths_cm && item.roll_widths_cm.length ? 'Largo final (cm)' : 'Alto (cm)']] : [['length', 'Largo (cm)']]).forEach(function (dimension) {
            var measure = field('input', 'lines[' + index + '][configuration][' + dimension[0] + ']', dimension[1], 'number');
            measure.input.min = '1'; measure.input.step = '0.1'; measure.input.value = selected[dimension[0]] || '100'; panel.appendChild(measure.wrapper);
          });
          if (item.roll_widths_cm && item.roll_widths_cm.length) {
            var rollNote = document.createElement('p');
            rollNote.textContent = 'Medida final libre; el precio se calcula por el ancho completo del rollo disponible (desde ' + item.roll_widths_cm[0] + ' cm) y el largo solicitado.';
            panel.appendChild(rollNote);
          }
        }
      } else if (item.mode === 'digital') {
        item.fields.forEach(function (config) {
          var name = 'lines[' + index + '][configuration][' + config.key + ']';
          var control = field(config.type === 'select' ? 'select' : 'input', name, config.label, config.type === 'select' ? null : (config.type === 'checkbox' ? 'checkbox' : 'number'));
          if (config.type === 'select') {
            config.options.forEach(function (choice) { var option = document.createElement('option'); option.value = choice.value; option.textContent = choice.label; control.input.appendChild(option); });
            control.input.value = selected[config.key] !== undefined ? selected[config.key] : (config.default || (config.options[0] && config.options[0].value) || '');
          } else if (config.type === 'checkbox') control.input.checked = selected[config.key] === true || selected[config.key] === '1';
          else { control.input.min = config.min; control.input.max = config.max; control.input.step = config.step; control.input.value = selected[config.key] || config.default || config.min; }
          panel.appendChild(control.wrapper);
        });
        quantity.value = 1; quantity.readOnly = true;
      }
      panel.querySelectorAll('input, select').forEach(function (input) { input.addEventListener('change', refreshPrice); input.addEventListener('input', refreshPrice); });
      refreshPrice();
    }

    search.addEventListener('change', function () { if (source.value === 'catalog_product') renderConfiguration(false); });
    search.addEventListener('input', function () {
      if (source.value === 'custom') return;
      if (catalog[search.value]) { renderConfiguration(false); return; }
      ticket++; productId.value = ''; panel.replaceChildren(); panel.hidden = true; price.value = ''; price.readOnly = true; hint.textContent = 'SeleccionÃ¡ un producto del catÃ¡logo.'; showSubtotal();
    });
    quantity.addEventListener('change', refreshPrice);
    quantity.addEventListener('input', showSubtotal);
    price.addEventListener('input', showSubtotal);
    line.querySelector('[data-ge-duplicate-line]').addEventListener('click', function () {
      var copy = line.cloneNode(true);
      var oldIndex = line.getAttribute('data-ge-index');
      var newIndex = String(nextIndex++);
      copy.setAttribute('data-ge-index', newIndex);
      copy.querySelector('[data-ge-line-uuid]').value = '';
      copy.querySelector('[data-ge-artwork-list]').replaceChildren();
      copy.querySelector('[data-ge-artwork]').dataset.field = 'lines[' + newIndex + '][artwork_refs][]';
      copy.querySelector('[data-ge-artwork-select]').value = '';
      copy.querySelector('[data-ge-artwork-notice]').textContent = '';
      delete copy.querySelector('[data-ge-artwork]').dataset.connected;
      copy.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace('lines[' + oldIndex + ']', 'lines[' + newIndex + ']'); });
      copy.setAttribute('data-ge-saved-config', '{}');
      line.after(copy); connect(copy); copy.querySelector('input[type="search"]').focus();
    });
    line.querySelector('[data-ge-remove-line]').addEventListener('click', function () {
      if (root.querySelectorAll('[data-ge-line]').length > 1) line.remove();
      else { search.value = ''; productId.value = ''; quantity.value = 1; price.value = ''; panel.replaceChildren(); panel.hidden = true; line.querySelectorAll('input[name$="[details]"], input[name$="[notes]"]').forEach(function (input) { input.value = ''; }); line.querySelectorAll('[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = false; }); setSource('catalog_product'); renderConfiguration(false); }
    });
    setSource(source.value === 'custom' ? 'custom' : 'catalog_product');
    renderConfiguration(true);
    showSubtotal();
  }

  root.querySelectorAll('[data-ge-line]').forEach(connect);
  document.querySelectorAll('[data-ge-add-line]').forEach(function (button) { button.addEventListener('click', function () {
    var type = button.dataset.geAddLine;
    var blank = root.querySelector('[data-ge-line]');
    if (root.querySelectorAll('[data-ge-line]').length === 1 && blank && !blank.querySelector('input[type="search"]').value) {
      blank.geSetSource(type);
      if (type === 'catalog_product') blank.querySelector('input[type="search"]').dispatchEvent(new Event('change'));
      blank.querySelector('input[type="search"]').focus(); return;
    }
    var holder = document.createElement('div'); holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)).trim();
    var line = holder.firstElementChild; line.querySelector('input[name$="[source_type]"]').value = type;
    root.appendChild(line); connect(line); line.querySelector('input[type="search"]').focus();
  }); });
}());
