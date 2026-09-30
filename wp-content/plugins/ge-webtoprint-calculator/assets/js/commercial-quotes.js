(function () {
  'use strict';
  var root = document.querySelector('[data-ge-lines]');
  var template = document.getElementById('ge-manual-line-template');
  var catalogNode = document.getElementById('ge-manual-catalog');
  if (!root || !template || !catalogNode) return;
  var catalog = {};
  try { catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (error) { return; }
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
    var productId = line.querySelector('input[name$="[product_id]"]');
    var quantity = line.querySelector('input[name$="[quantity]"]');
    var price = line.querySelector('input[name$="[unit_price]"]');
    var hint = line.querySelector('[data-ge-price-hint]');
    var panel = line.querySelector('[data-ge-config-fields]');
    var index = line.getAttribute('data-ge-index');
    var selected = {};
    try { selected = JSON.parse(line.getAttribute('data-ge-saved-config') || '{}'); } catch (error) { selected = {}; }
    var ticket = 0;

    function manual(message) {
      price.readOnly = false;
      hint.textContent = message || 'Precio manual antes de IVA.';
      quantity.readOnly = false;
    }

    function refreshPrice() {
      var item = catalog[search.value];
      if (!item || !item.id) return;
      if (item.mode === 'manual') { manual('Sin tarifa en catálogo: completá el precio.'); return; }
      if (item.mode === 'fixed') {
        price.readOnly = Number(item.price) > 0;
        if (price.readOnly) price.value = Number(item.price).toFixed(2);
        hint.textContent = price.readOnly ? 'Precio calculado desde el catálogo.' : 'Completá el precio manualmente.';
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
        hint.textContent = 'Elegí una configuración para calcular el precio.';
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
      hint.textContent = 'Calculando con la tarifa del sistema…';
      var body = new URLSearchParams();
      body.set('action', 'ge_commercial_quote_price');
      body.set('nonce', geCommercialQuotes.nonce);
      body.set('product_id', item.id);
      body.set('quantity', quantity.value);
      Object.keys(configuration).forEach(function (key) { body.set('configuration[' + key + ']', configuration[key]); });
      fetch(geCommercialQuotes.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) { return response.json(); }).then(function (result) {
        if (current !== ticket) return;
        if (!result.success) { price.value = ''; hint.textContent = typeof result.data === 'string' ? result.data : 'Revisá la configuración.'; return; }
        if (result.data.quantity) quantity.value = result.data.quantity;
        if (result.data.manual) { price.readOnly = false; hint.textContent = 'Esta combinación requiere precio manual antes de IVA.'; }
        else { price.value = Number(result.data.price).toFixed(2); price.readOnly = true; hint.textContent = result.data.configuration && result.data.configuration.roll_width_cm ? 'Precio calculado con rollo de ' + result.data.configuration.roll_width_cm + ' cm de ancho.' : 'Precio calculado desde el catálogo.'; }
      }).catch(function () {
        if (current === ticket) { price.value = ''; price.readOnly = true; hint.textContent = 'No se pudo consultar la tarifa. Reintentá al cambiar una opción.'; }
      });
    }

    function renderConfiguration(preserve) {
      ticket++;
      panel.replaceChildren();
      var item = catalog[search.value];
      productId.value = item ? item.id : '';
      panel.hidden = !item || !['option', 'variation', 'digital'].includes(item.mode);
      quantity.readOnly = false;
      quantity.min = 1; quantity.step = 1;
      if (!item) { manual('Servicio o producto especial: completá el precio.'); return; }
      if (!preserve) { price.value = ''; selected = {}; }
      if (item.mode === 'option' || item.mode === 'variation') {
        var choice = field('select', 'lines[' + index + '][configuration][option_key]', item.label || 'Configuración');
        var prompt = document.createElement('option'); prompt.value = ''; prompt.textContent = 'Seleccionar opción'; choice.input.appendChild(prompt);
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

    search.addEventListener('change', function () { renderConfiguration(false); });
    search.addEventListener('input', function () {
      if (catalog[search.value]) { renderConfiguration(false); return; }
      ticket++; productId.value = ''; panel.replaceChildren(); panel.hidden = true; manual('Servicio o producto especial: completá el precio.');
    });
    quantity.addEventListener('change', refreshPrice);
    line.querySelector('[data-ge-remove-line]').addEventListener('click', function () {
      if (root.querySelectorAll('[data-ge-line]').length > 1) line.remove();
      else { search.value = ''; productId.value = ''; quantity.value = 1; price.value = ''; panel.replaceChildren(); panel.hidden = true; line.querySelector('input[name$="[details]"]').value = ''; line.querySelectorAll('[type="checkbox"]').forEach(function (checkbox) { checkbox.checked = false; }); manual(); }
    });
    renderConfiguration(true);
  }

  root.querySelectorAll('[data-ge-line]').forEach(connect);
  document.querySelector('[data-ge-add-line]').addEventListener('click', function () {
    var holder = document.createElement('div'); holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)).trim();
    var line = holder.firstElementChild; root.appendChild(line); connect(line); line.querySelector('input[type="search"]').focus();
  });
}());
