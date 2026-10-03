(function (wp) {
    'use strict';
    if (!wp || !wp.hooks) { return; }
    var words = {
        'Products in cart': 'Productos en el carrito',
        'Product': 'Producto',
        'Total': 'Total',
        'Add coupons': 'Agregar cupón',
        'Estimated total': 'Total estimado',
        'Free': 'Sin cargo ahora',
        'Contact information': 'Datos de contacto',
        "We'll use this email to send you details and updates about your order.": 'Te enviaremos los datos y novedades de tu pedido a este correo.',
        'Email address': 'Correo electrónico',
        'Shipping address': 'Dirección de entrega',
        'Enter the address where you want your order delivered.': 'Ingresá la dirección donde querés recibir tu pedido.',
        'Edit': 'Editar',
        'Use same address for billing': 'Usar esta dirección para facturación',
        'Shipping options': 'Opciones de entrega',
        'Payment options': 'Medios de pago',
        'Add a note to your order': 'Agregar una nota al pedido',
        'Return to Cart': 'Volver al carrito',
        'Place Order': 'Confirmar pedido',
        'Remove %s from cart': 'Quitar %s del carrito',
        'Increase quantity of %s': 'Aumentar cantidad de %s',
        'Reduce quantity of %s': 'Reducir cantidad de %s',
        'Discover how practical Mercado Pago is': 'Pagá de forma simple con Mercado Pago',
        "We'll take you to Mercado Pago": 'Te llevaremos a Mercado Pago para completar el pago'
    };
    wp.hooks.addFilter('i18n.gettext', 'graphexpress/store-es-ar', function (translated, original) {
        return Object.prototype.hasOwnProperty.call(words, original) ? words[original] : translated;
    });
}(window.wp));
