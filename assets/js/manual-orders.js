(function () {
  'use strict';
  var root = document.querySelector('[data-ge-lines]');
  var template = document.getElementById('ge-manual-line-template');
  var catalogNode = document.getElementById('ge-manual-catalog');
  if (!root || !template || !catalogNode) return;
  var catalog = {};
  try { catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (error) { catalog = {}; }
  var picker = document.querySelector('[data-ge-branch-picker]');
  if (picker) {
    var email = document.querySelector('input[name="customer_email"]');
    var billing = picker.querySelector('[data-ge-billing-profile]');
    var delivery = picker.querySelector('[data-ge-delivery-address]');
    var requestNumber = 0;
    function addOption(select, value, label) {
      var option = document.createElement('option'); option.value = value; option.textContent = label; select.appendChild(option);
    }
    function loadBranches() {
      var number = ++requestNumber;
      var query = new URLSearchParams({ action: 'ge_customer_branch_options', _ajax_nonce: picker.dataset.nonce, email: email.value });
      fetch(picker.dataset.ajax + '?' + query.toString(), { credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (result) {
        if (number !== requestNumber || !result.success) return;
        billing.replaceChildren(); delivery.replaceChildren();
        addOption(delivery, '', 'A coordinar');
        (result.data.profiles || []).forEach(function (profile) { addOption(billing, profile.id, profile.label + (profile.cuit ? ' · CUIT ' + profile.cuit : '')); });
        if (!billing.options.length) addOption(billing, 'default', 'Perfil principal');
        var preferred = (result.data.profiles || []).find(function (profile) { return profile.is_default; });
        if (preferred) billing.value = preferred.id;
        (result.data.addresses || []).forEach(function (address) { addOption(delivery, address.id, address.label + ' · ' + address.street); });
        if (delivery.options.length === 2) delivery.selectedIndex = 1;
      }).catch(function () { /* The server validates both selected IDs at save time. */ });
    }
    email.addEventListener('change', loadBranches);
    if (email.value) loadBranches();
  }
  var nextIndex = root.querySelectorAll('[data-ge-line]').length;

  function connect(line) {
    var search = line.querySelector('input[type="search"]');
    var productId = line.querySelector('input[type="hidden"]');
    var price = line.querySelector('input[name$="[unit_price]"]');
    var remove = line.querySelector('[data-ge-remove-line]');
    search.addEventListener('change', function () {
      var selected = catalog[search.value];
      productId.value = selected ? selected.id : '';
      if (selected && Number(price.value || 0) === 0) price.value = selected.price || 0;
    });
    remove.addEventListener('click', function () {
      if (root.querySelectorAll('[data-ge-line]').length > 1) line.remove();
      else { search.value = ''; productId.value = ''; price.value = 0; line.querySelector('input[name$="[quantity]"]').value = 1; line.querySelector('input[name$="[details]"]').value = ''; }
    });
  }
  root.querySelectorAll('[data-ge-line]').forEach(connect);
  document.querySelector('[data-ge-add-line]').addEventListener('click', function () {
    var html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
    var holder = document.createElement('div'); holder.innerHTML = html.trim();
    var line = holder.firstElementChild; root.appendChild(line); connect(line); line.querySelector('input[type="search"]').focus();
  });
}());
