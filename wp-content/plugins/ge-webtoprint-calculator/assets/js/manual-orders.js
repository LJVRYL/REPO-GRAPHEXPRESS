(function () {
  'use strict';
  var root = document.querySelector('[data-ge-lines]');
  var template = document.getElementById('ge-manual-line-template');
  var catalogNode = document.getElementById('ge-manual-catalog');
  if (!root || !template || !catalogNode) return;
  var catalog = {};
  try { catalog = JSON.parse(catalogNode.textContent || '{}'); } catch (error) { catalog = {}; }
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
