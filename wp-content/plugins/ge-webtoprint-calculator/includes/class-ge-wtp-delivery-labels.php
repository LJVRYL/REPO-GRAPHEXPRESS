<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Delivery_Labels {
    const TOKEN_META = '_ge_delivery_token';

    public static function init() {
        add_action( 'admin_post_ge_delivery_label', array( __CLASS__, 'handle_label' ) );
        add_action( 'admin_post_nopriv_ge_delivery_label', array( __CLASS__, 'handle_label' ) );
        add_action( 'admin_post_ge_delivery_verify', array( __CLASS__, 'render_verification' ) );
        add_action( 'admin_post_nopriv_ge_delivery_verify', array( __CLASS__, 'render_verification' ) );
        add_action( 'admin_post_ge_confirm_delivery', array( __CLASS__, 'handle_confirmation' ) );
        add_action( 'admin_post_nopriv_ge_confirm_delivery', array( __CLASS__, 'handle_confirmation' ) );
    }

    public static function label_form( $order ) {
        if ( ! $order || ! GE_WTP_Staff_Portal::can_access() ) { return; }
        $packages = max( 1, absint( $order->get_meta( '_ge_label_packages' ) ) );
        $notes = (string) $order->get_meta( '_ge_label_notes' );
        $include_qr = 'yes' === $order->get_meta( '_ge_label_include_qr' );
        $confirmed = $order->get_meta( '_ge_delivery_confirmed_at' );
        ?>
        <section class="ge-label-panel">
            <div class="ge-label-panel-head"><div><span>Brother QL-700</span><h2>Etiqueta de entrega</h2><p>Formato fijo de 62 × 100 mm.</p></div><b>62 × 100</b></div>
            <?php if ( $confirmed ) : ?><div class="ge-delivery-confirmed"><strong>✓ Entrega confirmada</strong><span><?php echo esc_html( mysql2date( 'd/m/Y H:i', $confirmed ) ); ?> · <?php echo esc_html( $order->get_meta( '_ge_delivery_received_by' ) ); ?></span></div><?php endif; ?>
            <form class="ge-label-form" method="post" target="_blank" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_delivery_label"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_delivery_label_' . $order->get_id() ); ?>
                <label>Bultos<input type="number" name="packages" min="1" max="50" value="<?php echo esc_attr( $packages ); ?>"><small>Se imprime una etiqueta por cada bulto.</small></label>
                <label>Indicaciones para la etiqueta<textarea name="label_notes" rows="3" maxlength="300" placeholder="Frágil, entregar en recepción, no apilar..."><?php echo esc_textarea( $notes ); ?></textarea></label>
                <label class="ge-label-check"><input type="checkbox" name="include_qr" value="1" <?php checked( $include_qr ); ?>><span><strong>Incluir confirmación por QR</strong><small>Activala cuando una persona pueda confirmar la recepción. Dejala apagada para entregas en recepción o seguridad.</small></span></label>
                <button class="ge-staff-button" type="submit">Guardar y abrir etiquetas</button>
            </form>
        </section>
        <?php
    }

    public static function handle_label() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }

        if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
            if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
            check_admin_referer( 'ge_delivery_label_' . $order_id );
            $packages = isset( $_POST['packages'] ) ? absint( $_POST['packages'] ) : 1;
            $packages = max( 1, min( 50, $packages ) );
            $notes = isset( $_POST['label_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['label_notes'] ) ) : '';
            $order->update_meta_data( '_ge_label_packages', $packages );
            $order->update_meta_data( '_ge_label_notes', $notes );
            $order->update_meta_data( '_ge_label_include_qr', ! empty( $_POST['include_qr'] ) ? 'yes' : 'no' );
            $print_token = self::new_token();
            $order->update_meta_data( '_ge_label_print_token', $print_token );
            $order->update_meta_data( '_ge_label_print_expires', time() + DAY_IN_SECONDS );
            $order->save();
            wp_safe_redirect( add_query_arg( array( 'action' => 'ge_delivery_label', 'order_id' => $order_id, 'print_token' => $print_token ), admin_url( 'admin-post.php' ) ) );
            exit;
        }

        $print_token = isset( $_GET['print_token'] ) ? sanitize_text_field( wp_unslash( $_GET['print_token'] ) ) : '';
        $stored_token = (string) $order->get_meta( '_ge_label_print_token' );
        $expires = absint( $order->get_meta( '_ge_label_print_expires' ) );
        $token_is_valid = $print_token && $stored_token && hash_equals( $stored_token, $print_token ) && $expires >= time();
        if ( ! GE_WTP_Staff_Portal::can_access() && ! $token_is_valid ) { wp_die( 'El enlace de impresión no es válido o venció.', 403 ); }

        self::render_label_document( $order );
    }

    private static function render_label_document( $order ) {
        $packages = max( 1, absint( $order->get_meta( '_ge_label_packages' ) ) );
        $reference = $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id();
        $company = $order->get_billing_company();
        $customer = trim( $order->get_formatted_billing_full_name() );
        $shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '';
        $phone = $shipping_phone ?: ( $order->get_meta( '_ge_delivery_phone' ) ?: $order->get_billing_phone() );
        $address = $order->get_formatted_shipping_address();
        $hours = $order->get_meta( '_ge_delivery_hours' );
        $delivery_notes = $order->get_meta( '_ge_delivery_notes' );
        $label_notes = $order->get_meta( '_ge_label_notes' );
        $po = $order->get_meta( '_ge_markcom_po_reference' );
        $include_qr = 'yes' === $order->get_meta( '_ge_label_include_qr' );
        $verify_url = $include_qr ? self::verification_url( $order ) : '';
        nocache_headers();
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Etiquetas <?php echo esc_html( $reference ); ?></title><style>
        @page{size:62mm 100mm;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#ececec;font-family:Arial,Helvetica,sans-serif;color:#000}.ge-label-tools{position:sticky;z-index:10;top:0;display:flex;align-items:center;justify-content:center;gap:12px;padding:12px;background:#171717;color:#fff}.ge-label-tools button{padding:9px 15px;border:0;border-radius:7px;background:#fff;font-weight:700;cursor:pointer}.ge-label-tools span{font-size:12px}.ge-label-sheet{width:62mm;margin:12px auto;background:#fff}.ge-label{width:62mm;height:100mm;min-height:100mm;padding:2.2mm;overflow:hidden;break-after:page;page-break-after:always;background:#fff}.ge-label:last-child{break-after:auto;page-break-after:auto}.ge-label-brand{display:flex;align-items:center;justify-content:space-between;padding-bottom:1.2mm;border-bottom:.6mm solid #000}.ge-label-brand b{font-size:13pt;letter-spacing:-.5px}.ge-label-brand span{font-size:6.5pt;font-weight:700;text-align:right}.ge-label-order{padding:1.3mm 0;border-bottom:.35mm solid #000}.ge-label-order small{display:block;font-size:6.3pt;font-weight:700;text-transform:uppercase}.ge-label-order strong{display:block;margin-top:.4mm;font-size:15pt;line-height:1}.ge-label-customer{padding:1.2mm 0;border-bottom:.35mm solid #000}.ge-label-customer strong{display:block;font-size:10pt}.ge-label-customer span{display:block;margin-top:.3mm;font-size:7.2pt}.ge-label-address{padding:1.2mm 0;border-bottom:.35mm solid #000;font-size:7.1pt;line-height:1.25}.ge-label-address b,.ge-label-address span{display:block}.ge-label-items{padding:.8mm 0;border-bottom:.35mm solid #000}.ge-label-items div{display:grid;grid-template-columns:1fr auto;gap:2mm;padding:.35mm 0;font-size:6.8pt}.ge-label-items span{font-weight:700}.ge-label-notes{margin-top:.8mm;padding:1mm;border:.35mm solid #000;font-size:6.8pt;font-weight:700;line-height:1.2}.ge-label-bottom{display:grid;grid-template-columns:24mm 1fr;align-items:center;gap:2mm;padding-top:1.2mm}.ge-label-qr{width:24mm;height:24mm}.ge-label-qr img,.ge-label-qr canvas{width:24mm!important;height:24mm!important}.ge-label-verify strong{display:block;font-size:7.3pt;line-height:1.1}.ge-label-verify span{display:block;margin-top:.7mm;font-size:6pt;line-height:1.2}.ge-label-package{margin-top:1mm;padding:1mm;border:.5mm solid #000;text-align:center;font-size:10.5pt;font-weight:800}.ge-label-code{margin-top:.7mm;text-align:center;font-size:5.5pt;letter-spacing:.4px}@media print{html,body{width:62mm;background:#fff}.ge-label-tools{display:none}.ge-label-sheet{margin:0}.ge-label{margin:0}}
        </style><?php if ( $include_qr ) : ?><script src="<?php echo esc_url( GE_WTP_PLUGIN_URL . 'assets/vendor/qrcodejs/qrcode.min.js' ); ?>"></script><?php endif; ?></head><body><div class="ge-label-tools"><button type="button" onclick="window.print()">Imprimir en Brother QL-700</button><span>62 × 100 mm · orientación vertical · escala 100% · sin márgenes.</span></div><main class="ge-label-sheet">
        <?php for ( $package = 1; $package <= $packages; $package++ ) : ?><section class="ge-label"><header class="ge-label-brand"><b>GRAPH EXPRESS</b><span>CONTROL DE ENTREGA</span></header><div class="ge-label-order"><small>Pedido</small><strong><?php echo esc_html( $reference ); ?></strong><?php if ( $po ) : ?><small>PO <?php echo esc_html( $po ); ?></small><?php endif; ?></div><div class="ge-label-customer"><strong><?php echo esc_html( $company ?: ( $customer ?: 'Cliente' ) ); ?></strong><?php if ( $company && $customer ) : ?><span><?php echo esc_html( $customer ); ?></span><?php endif; ?><?php if ( $phone ) : ?><span>Tel. <?php echo esc_html( $phone ); ?></span><?php endif; ?></div><div class="ge-label-address"><b><?php echo $address ? 'ENTREGAR EN' : 'RETIRO / ENTREGA A COORDINAR'; ?></b><?php if ( $address ) : ?><span><?php echo wp_kses_post( $address ); ?></span><?php endif; ?><?php if ( $hours ) : ?><span>Horario: <?php echo esc_html( $hours ); ?></span><?php endif; ?><?php if ( $delivery_notes ) : ?><span><?php echo esc_html( $delivery_notes ); ?></span><?php endif; ?></div><div class="ge-label-items"><?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?><div><span><?php echo esc_html( $item->get_name() ); ?></span><b>x<?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?></b></div><?php endforeach; ?></div><?php if ( $label_notes ) : ?><div class="ge-label-notes"><?php echo nl2br( esc_html( $label_notes ) ); ?></div><?php endif; ?><?php if ( $include_qr ) : ?><div class="ge-label-bottom"><div class="ge-label-qr" id="ge-qr-<?php echo esc_attr( $package ); ?>"></div><div class="ge-label-verify"><strong>ESCANEAR AL ENTREGAR</strong><span>El receptor confirma su nombre antes de cerrar la entrega.</span></div></div><?php endif; ?><div class="ge-label-package">BULTO <?php echo esc_html( $package ); ?> DE <?php echo esc_html( $packages ); ?></div><?php if ( $include_qr ) : ?><div class="ge-label-code"><?php echo esc_html( self::short_code( $order ) ); ?></div><?php endif; ?></section><?php endfor; ?></main><?php if ( $include_qr ) : ?><script>document.addEventListener('DOMContentLoaded',function(){var value=<?php echo wp_json_encode( $verify_url ); ?>;for(var i=1;i<=<?php echo absint( $packages ); ?>;i++){new QRCode(document.getElementById('ge-qr-'+i),{text:value,width:220,height:220,colorDark:'#000000',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});}});</script><?php endif; ?></body></html><?php
        exit;
    }

    public static function verification_url( $order ) {
        return add_query_arg( array( 'action' => 'ge_delivery_verify', 'order_id' => $order->get_id(), 'token' => self::token( $order ) ), admin_url( 'admin-post.php' ) );
    }

    private static function token( $order ) {
        $token = (string) $order->get_meta( self::TOKEN_META );
        if ( strlen( $token ) < 40 ) { $token = self::new_token(); $order->update_meta_data( self::TOKEN_META, $token ); $order->save(); }
        return $token;
    }

    private static function new_token() {
        try { return bin2hex( random_bytes( 24 ) ); } catch ( Exception $error ) { return wp_generate_password( 48, false, false ); }
    }

    private static function order_from_request() {
        $id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
        $order = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
        return $order && $token && hash_equals( (string) $order->get_meta( self::TOKEN_META ), $token ) ? $order : false;
    }

    public static function render_verification() {
        $order = self::order_from_request();
        if ( ! $order ) { self::verification_page( false, 'Enlace no válido', 'No pudimos identificar esta entrega.' ); }
        $delivered = $order->get_meta( '_ge_delivery_confirmed_at' );
        if ( $delivered ) { self::verification_page( $order, 'Entrega ya confirmada', 'Fue recibida por ' . $order->get_meta( '_ge_delivery_received_by' ) . ' el ' . mysql2date( 'd/m/Y H:i', $delivered ) . '.' ); }
        self::verification_page( $order, 'Confirmar recepción', 'Revisá el número de pedido e indicá quién recibe.' );
    }

    public static function handle_confirmation() {
        $order = self::order_from_request();
        if ( ! $order ) { self::verification_page( false, 'Enlace no válido', 'No pudimos identificar esta entrega.' ); }
        $token = sanitize_text_field( wp_unslash( $_POST['token'] ) );
        check_admin_referer( 'ge_confirm_delivery_' . $order->get_id() . '_' . $token );
        if ( $order->get_meta( '_ge_delivery_confirmed_at' ) ) { self::verification_page( $order, 'Entrega ya confirmada', 'Esta entrega ya había sido registrada.' ); }
        if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { self::verification_page( $order, 'No se puede confirmar', 'El pedido no se encuentra habilitado para entrega.' ); }
        $receiver = isset( $_POST['receiver_name'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_name'] ) ) : '';
        $accepted = ! empty( $_POST['delivery_accept'] );
        if ( ! $receiver || ! $accepted ) { self::verification_page( $order, 'Faltan datos', 'Escribí el nombre de quien recibe y marcá la confirmación.', true ); }
        $note = isset( $_POST['delivery_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['delivery_note'] ) ) : '';
        $order->update_meta_data( '_ge_delivery_confirmed_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_ge_delivery_received_by', $receiver );
        $order->update_meta_data( '_ge_delivery_confirmation_note', $note );
        $order->update_meta_data( '_ge_delivery_confirmation_method', 'qr-label' );
        $order->add_order_note( 'Entrega confirmada mediante QR por ' . $receiver . ( $note ? '. Observación: ' . $note : '.' ) );
        $order->set_status( 'yes' === $order->get_meta( '_ge_markcom_order' ) ? 'ge-entregado' : 'completed', 'Entrega confirmada mediante la etiqueta QR.' );
        $order->save();
        self::verification_page( $order, '¡Entrega confirmada!', 'Gracias, ' . $receiver . '. La recepción quedó registrada correctamente.', false, true );
    }

    private static function verification_page( $order, $title, $message, $error = false, $success = false ) {
        $reference = $order ? ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() ) : '';
        $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
        status_header( $order ? 200 : 404 ); nocache_headers();
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?> · Graph Express</title><style>*{box-sizing:border-box}body{min-height:100vh;margin:0;padding:22px;display:grid;place-items:center;color:#17152a;background:radial-gradient(circle at 85% 10%,rgba(109,69,239,.3),transparent 30%),#f1f0f5;font-family:Arial,sans-serif}.card{width:min(100%,520px);padding:34px;border-radius:22px;background:#fff;box-shadow:0 25px 80px rgba(25,20,50,.13)}.brand{color:#6d45ef;font-size:12px;font-weight:800;letter-spacing:.12em}.icon{width:54px;height:54px;margin:25px 0 16px;display:grid;place-items:center;border-radius:50%;color:#fff;background:<?php echo $error ? '#c84255' : ( $success ? '#16855f' : '#6d45ef' ); ?>;font-size:25px;font-weight:800}h1{margin:0;font-size:34px;letter-spacing:-.04em}p{color:#6e6977;line-height:1.55}.order{margin:22px 0;padding:16px;border-radius:12px;background:#f4f2fa}.order small,.order strong{display:block}.order small{color:#77717f;font-size:10px;text-transform:uppercase}.order strong{margin-top:4px;font-size:21px}label{display:block;margin-top:14px;font-size:12px;font-weight:700}input[type=text],textarea{width:100%;margin-top:6px;padding:12px;border:1px solid #d5d1dd;border-radius:9px;font:inherit}.check{display:flex;gap:9px;align-items:flex-start;font-weight:600;line-height:1.4}.check input{margin-top:2px}button{width:100%;margin-top:20px;padding:14px;border:0;border-radius:10px;color:#fff;background:#17152a;font:inherit;font-weight:800}</style></head><body><main class="card"><span class="brand">GRAPH EXPRESS · ENTREGA</span><div class="icon"><?php echo $success ? '✓' : ( $error ? '!' : '→' ); ?></div><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $message ); ?></p><?php if ( $order ) : ?><div class="order"><small>Pedido</small><strong><?php echo esc_html( $reference ); ?></strong></div><?php endif; ?><?php if ( $order && ! $success && ! $order->get_meta( '_ge_delivery_confirmed_at' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_confirm_delivery"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'ge_confirm_delivery_' . $order->get_id() . '_' . $token ); ?><label>Nombre de quien recibe<input type="text" name="receiver_name" maxlength="140" required autocomplete="name"></label><label>Observación opcional<textarea name="delivery_note" rows="3" maxlength="500" placeholder="Estado de los bultos o comentario"></textarea></label><label class="check"><input type="checkbox" name="delivery_accept" value="1" required><span>Confirmo que recibí este pedido.</span></label><button type="submit">Confirmar entrega</button></form><?php endif; ?></main></body></html><?php
        exit;
    }

    private static function short_code( $order ) { return strtoupper( substr( hash( 'sha256', self::token( $order ) ), 0, 12 ) ); }
}
