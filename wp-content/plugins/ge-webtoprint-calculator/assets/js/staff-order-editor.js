(function () {
  'use strict';
  var form = document.querySelector('[data-ge-order-editor]');
  if (!form) return;
  var lines = form.querySelector('[data-ge-order-lines]');
  var template = form.querySelector('[data-ge-order-template]');
  var add = form.querySelector('[data-ge-order-add]');
  var nextIndex = lines.querySelectorAll('[data-ge-order-line]').length;

  function connect(line) {
    var product = line.querySelector('input[type="search"]');
    var productId = line.querySelector('input[name$="[product_id]"]');
    var remove = line.querySelector('input[name$="[remove]"]');
    if (product && productId) product.addEventListener('input', function () {
      var match = product.value.match(/\(#(\d+)\)$/);
      productId.value = match ? match[1] : '';
    });
    if (remove) remove.addEventListener('change', function () {
      line.classList.toggle('is-removed', remove.checked);
    });
  }

  lines.querySelectorAll('[data-ge-order-line]').forEach(connect);
  add.addEventListener('click', function () {
    var html = template.innerHTML.replaceAll('__INDEX__', 'new_' + String(nextIndex++));
    var holder = document.createElement('div');
    holder.innerHTML = html.trim();
    var line = holder.firstElementChild;
    lines.appendChild(line);
    connect(line);
    line.querySelector('input[type="search"]').focus();
  });
}());
