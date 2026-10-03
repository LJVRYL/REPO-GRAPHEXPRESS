<?php
/**
 * Plugin Name: Graph Express — WhatsApp accesible
 * Description: Acceso global a la atención comercial por WhatsApp.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Reemplaza visualmente las burbujas "WA" de plantillas anteriores.
 * El CSS se imprime al final del head para prevalecer sobre el child theme.
 */
function ge_whatsapp_float_styles() {
    if (is_admin()) {
        return;
    }
    ?>
    <style id="ge-whatsapp-float-css">
        .gx-whatsapp-float { display: none !important; }
        .ge-whatsapp-float {
            position: fixed;
            z-index: 90;
            right: 18px;
            bottom: 18px;
            bottom: calc(18px + env(safe-area-inset-bottom, 0px));
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border: 2px solid #fff;
            border-radius: 50%;
            background: #128c7e;
            color: #fff !important;
            box-shadow: 0 6px 22px rgba(0, 0, 0, .24);
            text-decoration: none !important;
            line-height: 1;
            transition: transform .16s ease, box-shadow .16s ease;
        }
        .ge-whatsapp-float svg { display: block; width: 29px; height: 29px; fill: currentColor; }
        .ge-whatsapp-float:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0, 0, 0, .3); }
        .ge-whatsapp-float:focus-visible { outline: 3px solid #171328; outline-offset: 3px; }
        .ge-whatsapp-float::before {
            content: attr(data-label);
            position: absolute;
            right: 66px;
            top: 50%;
            width: max-content;
            max-width: calc(100vw - 100px);
            padding: 9px 12px;
            border-radius: 8px;
            background: #171328;
            color: #fff;
            font: 600 13px/1.25 system-ui, sans-serif;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .2);
            opacity: 0;
            pointer-events: none;
            transform: translateY(-50%);
        }
        .ge-whatsapp-float:hover::before, .ge-whatsapp-float:focus-visible::before { opacity: 1; }
        @media (max-width: 780px) {
            .ge-whatsapp-float { right: 12px; bottom: 14px; bottom: calc(14px + env(safe-area-inset-bottom, 0px)); width: 52px; height: 52px; }
            .ge-whatsapp-float::before { display: none; }
            body.woocommerce-checkout .ge-whatsapp-float { bottom: 24px; bottom: calc(24px + env(safe-area-inset-bottom, 0px)); }
        }
        @media (prefers-reduced-motion: reduce) { .ge-whatsapp-float { transition: none; } }
    </style>
    <?php
}
add_action('wp_head', 'ge_whatsapp_float_styles', 99);

function ge_whatsapp_float_render() {
    if (is_admin()) {
        return;
    }

    $url = 'https://wa.me/5491151393899?text=' . rawurlencode('Hola, vengo desde la web de Graph Express y quería hacer una consulta.');
    ?>
    <a class="ge-whatsapp-float" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer" aria-label="Escribinos por WhatsApp (se abre en una pestaña nueva)" data-label="Escribinos por WhatsApp">
        <?php // Ícono de marca WhatsApp: https://simpleicons.org/icons/whatsapp (CC0). ?>
        <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
    </a>
    <?php
}
add_action('wp_footer', 'ge_whatsapp_float_render', 99);
