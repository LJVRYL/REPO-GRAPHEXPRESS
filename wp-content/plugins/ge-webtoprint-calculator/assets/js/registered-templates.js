(function () {
    'use strict';
    document.querySelectorAll('[data-gx-template-picker]').forEach(function (picker) {
        var panel = picker.closest('.gx-template-panel');
        var select = panel.querySelector('[data-gx-template-option]');
        var link = picker.querySelector('[data-gx-template-download]');
        var message = picker.querySelector('[data-gx-template-message]');
        var map;
        try { map = JSON.parse(picker.dataset.templateMap || '{}'); } catch (e) { map = {}; }
        function update() {
            var item = map[select.value];
            link.hidden = true;
            link.removeAttribute('href');
            if (!select.value) { message.textContent = 'Seleccioná una opción para ver su plantilla.'; return; }
            if (!item || !item.url) { message.textContent = 'La plantilla de esta opción está en revisión. Solicitá la versión para tu medida.'; return; }
            link.href = item.url;
            link.hidden = false;
            message.textContent = item.title + ' / referencia de diseño. Confirmá las medidas antes de producir.';
        }
        select.addEventListener('change', update);
        var productSelect = document.querySelector('form[data-ge-storefront] select[data-ge-option]');
        if (productSelect) {
            function followProduct() {
                select.value = Object.prototype.hasOwnProperty.call(map, productSelect.value) ? productSelect.value : '';
                update();
            }
            productSelect.addEventListener('change', followProduct);
            followProduct();
        } else { update(); }
    });
    var search = document.querySelector('[data-gx-template-search]');
    if (search) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-gx-template-card]'));
        var count = document.querySelector('[data-gx-template-count]');
        function normalize(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[×]/g, 'x').replace(/\s/g, ''); }
        function filter() {
            var query = normalize(search.value);
            var visible = 0;
            cards.forEach(function (card) { card.hidden = normalize(card.textContent).indexOf(query) === -1; if (!card.hidden) { visible++; } });
            count.textContent = visible ? visible + ' plantillas disponibles' : 'No encontramos ese modelo. Solicitá su plantilla por medida.';
        }
        search.addEventListener('input', filter);
        filter();
    }
}());
