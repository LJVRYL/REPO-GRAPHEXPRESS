(function () {
    'use strict';
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) return;
    var settings = window.wc.wcSettings.getSetting('ge_deco_marketplace_data', {});
    var create = window.wp.element.createElement;
    var content = create('p', { 'data-mp-marketplace-checkout': 'checkout-pro' }, settings.description || 'Completá el pago en Mercado Pago.');
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'ge_deco_marketplace',
        label: create('span', null, settings.title || 'Mercado Pago'),
        content: content,
        edit: content,
        canMakePayment: function () { return settings.available === true; },
        ariaLabel: 'Mercado Pago para obras de artistas',
        supports: { features: settings.supports || ['products'] }
    });
}());
