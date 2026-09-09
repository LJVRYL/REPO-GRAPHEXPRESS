(function () {
    'use strict';
    var money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
    document.querySelectorAll('[data-ge-storefront]').forEach(function (form) {
        var select = form.querySelector('[data-ge-option]');
        var quantity = form.querySelector('[data-ge-quantity]');
        var price = form.querySelector('[data-ge-price]');
        var base = form.querySelector('[data-ge-base]');
        var width = form.querySelector('[data-ge-width]');
        var height = form.querySelector('[data-ge-height]');
        var length = form.querySelector('[data-ge-length]');
        function update() {
            var option = select.options[select.selectedIndex];
            var factor = 1;
            if (width && height) { factor = Math.max(0.01, Number(width.value || 0) / 100) * Math.max(0.01, Number(height.value || 0) / 100); }
            if (length) { factor = Math.max(0.01, Number(length.value || 0) / 100); }
            var total = Number(option.dataset.price || 0) * factor * Math.max(1, Number(quantity.value || 1));
            price.textContent = money.format(Math.round(total * 1.21));
            if (base) { base.textContent = 'Base sin IVA: ' + money.format(Math.round(total)); }
        }
        select.addEventListener('change', update);
        quantity.addEventListener('input', update);
        [width, height, length].forEach(function (field) { if (field) { field.addEventListener('input', update); } });
        update();
    });
}());
