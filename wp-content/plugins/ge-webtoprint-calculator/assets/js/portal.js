(function () {
    'use strict';

    document.querySelectorAll('[data-ge-product]').forEach(function (card) {
        var select = card.querySelector('[data-ge-tier]');
        var price = card.querySelector('[data-ge-price]');
        if (!select || !price) return;

        function updatePrice() {
            var option = select.options[select.selectedIndex];
            var usd = Number(option.dataset.usd || 0);
            var ars = Number(option.dataset.ars || 0);
            price.textContent = usd > 0
                ? 'USD ' + usd.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '$ ' + ars.toLocaleString('es-AR') + ' ARS';
        }

        select.addEventListener('change', updatePrice);
        updatePrice();
    });
}());
