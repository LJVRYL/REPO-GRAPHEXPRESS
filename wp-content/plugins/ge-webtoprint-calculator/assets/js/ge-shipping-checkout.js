jQuery(function ($) {
    const form = $('form.checkout');
    const choice = form.find('.ge-delivery-choice');
    const heading = form.find('#ship-to-different-address');
    const checkbox = heading.find('input');
    const address = form.find('.shipping_address');
    if (!choice.length || !checkbox.length || !address.length) return;

    heading.prop('hidden', true);
    address.attr('id', 'ge-shipping-address');
    choice.find('[value="delivery"]').attr('aria-controls', 'ge-shipping-address');

    function sync(refresh) {
        const delivery = choice.find(':checked').val() === 'delivery';
        const changed = checkbox.prop('checked') !== delivery;
        checkbox.prop('checked', delivery);
        if (changed) {
            checkbox.trigger('change');
        } else {
            address.toggle(delivery);
        }
        if (refresh) $(document.body).trigger('update_checkout');
    }

    choice.on('change', 'input', () => sync(true));
    sync(false);
});

