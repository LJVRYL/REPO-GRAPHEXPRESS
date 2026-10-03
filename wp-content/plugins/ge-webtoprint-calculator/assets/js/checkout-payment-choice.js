(function () {
    'use strict';

    if (!window.wp || !wp.data || !window.wc || !wc.wcBlocksData || !wc.blocksCheckout || !wc.blocksCheckout.extensionCartUpdate) {
        return;
    }

    var paymentStore = wc.wcBlocksData.paymentStore;
    var lastSynced = '';
    var updating = false;

    function syncPaymentMethod() {
        var method = wp.data.select(paymentStore).getActivePaymentMethod();
        if (!method || method === lastSynced || updating) {
            return;
        }
        if (method !== 'bacs' && method.indexOf('woo-mercado-pago-') !== 0) {
            return;
        }
        updating = true;
        wc.blocksCheckout.extensionCartUpdate({
            namespace: 'ge-payment-choice',
            data: {payment_method: method}
        }).then(function () {
            lastSynced = method;
            updating = false;
            syncPaymentMethod();
        }).catch(function () {
            updating = false;
        });
    }

    wp.data.subscribe(syncPaymentMethod);
    syncPaymentMethod();
}());
