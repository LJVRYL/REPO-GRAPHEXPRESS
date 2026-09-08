<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Customers {
    const ENDPOINT = 'datos-del-cliente';
    const ADDRESSES_META = '_ge_delivery_addresses';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ) );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_content' ) );
        add_action( 'admin_post_ge_customer_save_profile', array( __CLASS__, 'handle_save_profile' ) );
        add_action( 'admin_post_ge_staff_save_customer', array( __CLASS__, 'handle_staff_save_customer' ) );
        add_action( 'admin_post_ge_customer_avatar', array( __CLASS__, 'handle_avatar' ) );
        add_action( 'admin_post_nopriv_ge_verify_customer_email', array( __CLASS__, 'handle_verify_email' ) );
        add_action( 'admin_post_ge_verify_customer_email', array( __CLASS__, 'handle_verify_email' ) );
        add_action( 'admin_post_ge_resend_customer_verification', array( __CLASS__, 'handle_resend_verification' ) );
        add_action( 'woocommerce_register_form_start', array( __CLASS__, 'registration_fields' ) );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_registration_fields' ), 10 );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'start_customer_verification' ), 30 );
        add_shortcode( 'ge_customer_profile', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function install() {
        self::register_endpoint();
        $page = get_page_by_path( 'mi-perfil' );
        if ( ! $page ) {
            wp_insert_post( array( 'post_title' => 'Mi perfil', 'post_name' => 'mi-perfil', 'post_content' => '[ge_customer_profile]', 'post_status' => 'publish', 'post_type' => 'page' ) );
        }
        flush_rewrite_rules( false );
    }

    public static function register_endpoint() {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public static function query_vars( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public static function account_menu_items( $items ) {
        $logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
        unset( $items['customer-logout'] );
        $items[ self::ENDPOINT ] = 'Mis datos y entregas';
        if ( null !== $logout ) { $items['customer-logout'] = $logout; }
        return $items;
    }

    public static function enqueue_assets() {
        if ( is_page( 'cliente-markcom' ) || is_page( 'mi-perfil' ) || is_page( 'gestion' ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
            wp_enqueue_style( 'ge-customer-profile', GE_WTP_PLUGIN_URL . 'assets/css/customer-profile.css', array(), GE_WTP_VERSION );
            wp_enqueue_script( 'ge-customer-profile', GE_WTP_PLUGIN_URL . 'assets/js/customer-profile.js', array(), GE_WTP_VERSION, true );
        }
    }

    public static function account_content() {
        echo self::profile_form( get_current_user_id(), 'account' );
    }

    public static function registration_fields() {
        ?><p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="reg_ge_first_name">Nombre&nbsp;<span class="optional">(opcional)</span></label><input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="ge_first_name" id="reg_ge_first_name" maxlength="100" value="<?php echo isset( $_POST['ge_first_name'] ) ? esc_attr( wp_unslash( $_POST['ge_first_name'] ) ) : ''; ?>"></p><p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="reg_ge_whatsapp">WhatsApp&nbsp;<span class="optional">(opcional)</span></label><input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="ge_whatsapp" id="reg_ge_whatsapp" maxlength="40" value="<?php echo isset( $_POST['ge_whatsapp'] ) ? esc_attr( wp_unslash( $_POST['ge_whatsapp'] ) ) : ''; ?>"></p><?php
    }

    public static function save_registration_fields( $customer_id ) {
        $name = isset( $_POST['ge_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ge_first_name'] ) ) : '';
        $whatsapp = isset( $_POST['ge_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['ge_whatsapp'] ) ) : '';
        if ( $name ) { wp_update_user( array( 'ID' => $customer_id, 'first_name' => $name, 'display_name' => $name ) ); }
        if ( $whatsapp ) { update_user_meta( $customer_id, '_ge_whatsapp', $whatsapp ); update_user_meta( $customer_id, 'billing_phone', $whatsapp ); }
    }

    public static function start_customer_verification( $customer_id ) {
        $user = get_userdata( $customer_id );
        if ( ! $user ) { return; }
        update_user_meta( $customer_id, '_ge_email_verified', 'no' );
        self::request_email_verification( $customer_id, $user->user_email, true );
        if ( class_exists( 'GE_WTP_Notifications' ) ) { GE_WTP_Notifications::send_new_customer_admin( $user ); }
    }

    public static function shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<div class="ge-customer-login"><h2>Ingresá para administrar tus datos</h2>' . wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) ) . '</div>';
        }
        return self::profile_form( get_current_user_id(), 'page' );
    }

    public static function render_for_portal() {
        echo self::profile_form( get_current_user_id(), 'markcom' );
    }

    public static function profile_form( $user_id, $context = 'account', $staff = false ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) { return '<div class="ge-profile-notice is-error">No encontramos el perfil.</div>'; }
        $addresses = self::addresses( $user_id );
        if ( ! $addresses ) { $addresses[] = self::empty_address(); }
        $newsletter = 'yes' === get_user_meta( $user_id, '_ge_newsletter_optin', true );
        $verified = self::email_verified( $user_id );
        $pending_email = get_user_meta( $user_id, '_ge_pending_email', true );
        $status = isset( $_GET['profile_status'] ) ? sanitize_key( wp_unslash( $_GET['profile_status'] ) ) : '';
        ob_start();
        ?>
        <section class="ge-customer-profile">
            <div class="ge-profile-heading"><div><span>Ficha del cliente</span><h1><?php echo $staff ? 'Datos y entregas' : 'Mis datos'; ?></h1><p><?php echo $staff ? 'Información compartida por el cliente y datos operativos internos.' : 'Completá solamente los datos que quieras usar para pedidos, facturación y entregas.'; ?></p></div><?php if ( $staff ) : ?><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'customers' ) ); ?>">← Volver a clientes</a><?php endif; ?></div>
            <?php if ( 'saved' === $status ) : ?><div class="ge-profile-notice">Los datos se guardaron correctamente.</div><?php elseif ( 'verified' === $status ) : ?><div class="ge-profile-notice">Email verificado correctamente.</div><?php elseif ( 'email-pending' === $status ) : ?><div class="ge-profile-notice is-pending">Te enviamos un enlace al nuevo email. El cambio se aplicará cuando lo verifiques.</div><?php elseif ( 'error' === $status || 'verify-error' === $status ) : ?><div class="ge-profile-notice is-error">No pudimos completar la operación. Revisá el email y volvé a intentar.</div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo $staff ? 'ge_staff_save_customer' : 'ge_customer_save_profile'; ?>">
                <input type="hidden" name="profile_context" value="<?php echo esc_attr( $context ); ?>">
                <?php if ( $staff ) : ?><input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>"><?php wp_nonce_field( 'ge_staff_save_customer_' . $user_id ); else : wp_nonce_field( 'ge_customer_save_profile' ); endif; ?>
                <div class="ge-profile-grid">
                    <section class="ge-profile-card"><div class="ge-profile-card-title"><span>01</span><div><h2>Identidad y contacto</h2><p>Datos principales para comunicarnos y emitir pedidos.</p></div></div><div class="ge-profile-photo"><div class="ge-profile-avatar"><?php echo self::avatar_markup( $user_id, 76 ); ?></div><label><strong>Foto de perfil</strong><input type="file" name="profile_avatar" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WebP · máximo 2 MB</small></label><?php if ( self::avatar_data( $user_id ) ) : ?><label class="ge-profile-remove"><input type="checkbox" name="remove_avatar" value="1"> Quitar foto</label><?php endif; ?></div><div class="ge-profile-fields">
                        <label>Nombre<input type="text" name="first_name" value="<?php echo esc_attr( get_user_meta( $user_id, 'first_name', true ) ); ?>" maxlength="100"></label>
                        <label>Apellido<input type="text" name="last_name" value="<?php echo esc_attr( get_user_meta( $user_id, 'last_name', true ) ); ?>" maxlength="100"></label>
                        <label class="ge-field-wide">Email obligatorio<input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" required maxlength="190" <?php disabled( $staff ); ?>></label>
                        <label>WhatsApp<input type="tel" name="whatsapp" value="<?php echo esc_attr( get_user_meta( $user_id, '_ge_whatsapp', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) ); ?>" maxlength="40" placeholder="+54 9 11..."></label>
                        <label>Persona de contacto<input type="text" name="contact_person" value="<?php echo esc_attr( get_user_meta( $user_id, '_ge_contact_person', true ) ); ?>" maxlength="140"></label>
                        <label>Razón social / empresa<input type="text" name="company" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_company', true ) ); ?>" maxlength="160"></label>
                        <label>CUIT<input type="text" name="cuit" value="<?php echo esc_attr( get_user_meta( $user_id, '_ge_cuit', true ) ); ?>" maxlength="20"></label>
                    </div></section>
                    <section class="ge-profile-card"><div class="ge-profile-card-title"><span>02</span><div><h2>Preferencias</h2><p>Cómo suele trabajar y recibir sus pedidos.</p></div></div><div class="ge-email-state <?php echo $verified ? 'is-verified' : 'is-pending'; ?>"><strong><?php echo $verified ? '✓ Email verificado' : 'Email pendiente de verificación'; ?></strong><?php if ( $pending_email ) : ?><small>Cambio pendiente: <?php echo esc_html( self::mask_email( $pending_email ) ); ?></small><?php endif; ?><?php if ( ! $verified && ! $staff ) : ?><button type="submit" form="ge-resend-verification">Reenviar enlace</button><?php endif; ?></div><div class="ge-profile-fields"><label class="ge-field-wide">Modalidad habitual<select name="delivery_preference"><option value="">Sin preferencia</option><option value="retiro" <?php selected( get_user_meta( $user_id, '_ge_delivery_preference', true ), 'retiro' ); ?>>Retiro por Graph Express</option><option value="envio" <?php selected( get_user_meta( $user_id, '_ge_delivery_preference', true ), 'envio' ); ?>>Envío a domicilio</option><option value="coordinar" <?php selected( get_user_meta( $user_id, '_ge_delivery_preference', true ), 'coordinar' ); ?>>Coordinar en cada pedido</option></select></label><?php if ( ! $staff ) : ?><label class="ge-profile-consent ge-field-wide"><input type="checkbox" name="newsletter_optin" value="1" <?php checked( $newsletter ); ?>><span><strong>Quiero recibir novedades</strong><small>Promociones y contenidos de Graph Express. Los emails necesarios para pedidos se envían igualmente.</small></span></label><?php endif; ?></div></section>
                </div>
                <section class="ge-profile-card ge-profile-addresses" data-ge-addresses><div class="ge-profile-card-title"><span>03</span><div><h2>Direcciones y horarios de entrega</h2><p>Guardá un destino y agregá otros solamente cuando los necesites.</p></div></div><div class="ge-address-grid" data-ge-address-list><?php foreach ( array_slice( $addresses, 0, 4 ) as $index => $address ) : self::render_address_fields( $address, $index ); endforeach; ?></div><template data-ge-address-template><?php self::render_address_fields( self::empty_address(), '__INDEX__' ); ?></template><button class="ge-add-address" type="button" data-ge-add-address>＋ Agregar otro destino</button><small class="ge-address-limit">Podés guardar hasta cuatro destinos.</small></section>
                <?php if ( $staff ) : ?><section class="ge-profile-card ge-profile-internal"><div class="ge-profile-card-title"><span>04</span><div><h2>Información interna</h2><p>El cliente no puede ver estos datos.</p></div></div><div class="ge-profile-fields"><label class="ge-field-wide">Etiquetas<input type="text" name="internal_tags" value="<?php echo esc_attr( get_user_meta( $user_id, '_ge_customer_tags', true ) ); ?>" maxlength="300" placeholder="Frecuente, corporativo, revendedor..."></label><label class="ge-field-wide">Notas internas<textarea name="internal_notes" rows="5" maxlength="3000"><?php echo esc_textarea( get_user_meta( $user_id, '_ge_customer_internal_notes', true ) ); ?></textarea></label></div></section><?php self::render_customer_commercial_activity( $user ); self::render_customer_email_history( $user ); endif; ?>
                <div class="ge-profile-actions"><button type="submit"><?php echo $staff ? 'Guardar ficha del cliente' : 'Guardar mis datos'; ?></button><span>Los campos sin la indicación “obligatorio” son opcionales.</span></div>
            </form>
            <?php if ( ! $verified && ! $staff ) : ?><form id="ge-resend-verification" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_resend_customer_verification"><input type="hidden" name="profile_context" value="<?php echo esc_attr( $context ); ?>"><?php wp_nonce_field( 'ge_resend_customer_verification' ); ?></form><?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function render_address_fields( $address, $index ) {
        ?>
        <fieldset data-ge-address-item><legend>Destino <span data-ge-address-number><?php echo '__INDEX__' === (string) $index ? '' : esc_html( (int) $index + 1 ); ?></span></legend><?php if ( '0' !== (string) $index ) : ?><button class="ge-remove-address" type="button" data-ge-remove-address>Quitar</button><?php endif; ?><div class="ge-profile-fields"><label>Nombre del lugar<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $address['label'] ); ?>" maxlength="100" placeholder="Oficina, depósito..."></label><label>Quién recibe<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][recipient]" value="<?php echo esc_attr( $address['recipient'] ); ?>" maxlength="140"></label><label class="ge-field-wide">Dirección<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][street]" value="<?php echo esc_attr( $address['street'] ); ?>" maxlength="220" placeholder="Calle, número, piso y departamento"></label><label>Ciudad<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][city]" value="<?php echo esc_attr( $address['city'] ); ?>" maxlength="100"></label><label>Provincia<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][province]" value="<?php echo esc_attr( $address['province'] ); ?>" maxlength="100"></label><label>Código postal<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][postal_code]" value="<?php echo esc_attr( $address['postal_code'] ); ?>" maxlength="20"></label><label>WhatsApp de recepción<input type="tel" name="addresses[<?php echo esc_attr( $index ); ?>][phone]" value="<?php echo esc_attr( $address['phone'] ); ?>" maxlength="40"></label><label class="ge-field-wide">Días y horarios<input type="text" name="addresses[<?php echo esc_attr( $index ); ?>][hours]" value="<?php echo esc_attr( $address['hours'] ); ?>" maxlength="180" placeholder="Lunes a viernes de 9 a 17 h"></label><label class="ge-field-wide">Indicaciones<textarea name="addresses[<?php echo esc_attr( $index ); ?>][notes]" rows="2" maxlength="500" placeholder="Acceso, recepción, llamar antes..."><?php echo esc_textarea( $address['notes'] ); ?></textarea></label></div></fieldset>
        <?php
    }

    private static function render_customer_email_history( $user ) {
        $logs = GE_WTP_Notifications::get_logs_by_recipient( $user->user_email, 20 );
        ?><section class="ge-profile-card ge-profile-emails"><div class="ge-profile-card-title"><span>06</span><div><h2>Historial de emails</h2><p>Mensajes operativos y comerciales registrados para este cliente.</p></div></div><?php if ( ! $logs ) : ?><div class="ge-admin-empty">Todavía no hay emails registrados.</div><?php else : ?><div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Fecha</th><th>Asunto</th><th>Tipo</th><th>Resultado</th></tr></thead><tbody><?php foreach ( $logs as $log ) : $result = get_post_meta( $log->ID, '_ge_email_result', true ); ?><tr><td><?php echo esc_html( get_the_date( 'd/m/Y H:i', $log ) ); ?></td><td><strong><?php echo esc_html( $log->post_title ); ?></strong></td><td><?php echo esc_html( get_post_meta( $log->ID, '_ge_email_context', true ) ); ?></td><td><span class="ge-admin-status ge-mail-<?php echo esc_attr( $result ); ?>"><?php echo esc_html( 'sent' === $result ? 'Enviado' : 'Fallido' ); ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
    }

    private static function customer_quotes( $user_id ) {
        $quotes = array();
        foreach ( get_user_meta( $user_id ) as $key => $values ) {
            if ( 0 !== strpos( $key, 'ge_pending_quote_' ) || empty( $values[0] ) ) { continue; }
            $quote = maybe_unserialize( $values[0] );
            if ( is_array( $quote ) ) { $quotes[] = $quote; }
        }
        usort( $quotes, function( $a, $b ) { return strcmp( (string) ( $b['captured_at'] ?? '' ), (string) ( $a['captured_at'] ?? '' ) ); } );
        return $quotes;
    }

    private static function customer_orders( $user ) {
        $orders = wc_get_orders( array( 'customer_id' => $user->ID, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        $guest = wc_get_orders( array( 'customer_id' => 0, 'billing_email' => $user->user_email, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        $indexed = array();
        foreach ( array_merge( $orders, $guest ) as $order ) { $indexed[ $order->get_id() ] = $order; }
        return array_values( $indexed );
    }

    private static function render_customer_commercial_activity( $user ) {
        $quotes = self::customer_quotes( $user->ID );
        $orders = self::customer_orders( $user );
        ?>
        <section class="ge-profile-card ge-customer-activity"><div class="ge-profile-card-title"><span>05</span><div><h2>Actividad comercial</h2><p>Cotizaciones y pedidos relacionados con este cliente.</p></div></div>
            <div class="ge-customer-activity-summary"><article><small>Cotizaciones</small><strong><?php echo esc_html( count( $quotes ) ); ?></strong></article><article><small>Pedidos</small><strong><?php echo esc_html( count( $orders ) ); ?></strong></article></div>
            <div class="ge-customer-activity-grid"><div><h3>Cotizaciones</h3><?php if ( ! $quotes ) : ?><div class="ge-admin-empty">No hay cotizaciones registradas.</div><?php else : foreach ( $quotes as $quote ) : ?><article class="ge-customer-quote"><div><span><?php echo esc_html( $quote['reference'] ?? 'Cotización pendiente' ); ?></span><h4><?php echo esc_html( $quote['title'] ?? 'Trabajo a cotizar' ); ?></h4></div><p><?php echo esc_html( $quote['details'] ?? '' ); ?></p><?php if ( ! empty( $quote['options'] ) && is_array( $quote['options'] ) ) : ?><ul><?php foreach ( $quote['options'] as $option ) : ?><li><strong><?php echo esc_html( number_format_i18n( absint( $option['quantity'] ?? 0 ) ) ); ?> unidades</strong><span><?php echo wp_kses_post( wc_price( (float) ( $option['total'] ?? 0 ), array( 'currency' => 'ARS' ) ) ); ?></span></li><?php endforeach; ?></ul><?php else : ?><b class="ge-quote-pending">Precio pendiente</b><?php endif; ?><footer><?php echo esc_html( ucfirst( str_replace( '_', ' ', $quote['status'] ?? 'pendiente' ) ) ); ?><?php if ( ! empty( $quote['payment'] ) ) : ?> · <?php echo esc_html( $quote['payment'] ); ?><?php endif; ?></footer></article><?php endforeach; endif; ?></div>
            <div><h3>Pedidos</h3><?php if ( ! $orders ) : ?><div class="ge-admin-empty">No hay pedidos asociados.</div><?php else : ?><div class="ge-customer-order-list"><?php foreach ( $orders as $order ) : ?><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); ?>"><span><strong><?php echo esc_html( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?></strong><small><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) ); ?> · <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small></span><b><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></b></a><?php endforeach; ?></div><?php endif; ?></div></div>
        </section>
        <?php
    }

    private static function avatar_directory() {
        return WP_CONTENT_DIR . '/ge-private/avatars';
    }

    public static function email_verified( $user_id ) {
        $value = get_user_meta( $user_id, '_ge_email_verified', true );
        return '' === $value || 'yes' === $value;
    }

    public static function request_email_verification( $user_id, $email, $welcome = false ) {
        $user = get_userdata( $user_id );
        $email = strtolower( sanitize_email( $email ) );
        if ( ! $user || ! is_email( $email ) || ( email_exists( $email ) && (int) email_exists( $email ) !== (int) $user_id ) ) { return new WP_Error( 'invalid_email', 'El email no está disponible.' ); }
        try { $token = bin2hex( random_bytes( 32 ) ); } catch ( Exception $error ) { $token = wp_generate_password( 64, false, false ); }
        update_user_meta( $user_id, '_ge_pending_email', $email );
        update_user_meta( $user_id, '_ge_email_verification_hash', hash( 'sha256', $token ) );
        update_user_meta( $user_id, '_ge_email_verification_expires', time() + DAY_IN_SECONDS );
        $url = add_query_arg( array( 'action' => 'ge_verify_customer_email', 'user_id' => $user_id, 'token' => rawurlencode( $token ) ), admin_url( 'admin-post.php' ) );
        return GE_WTP_Notifications::send_customer_welcome_verification( $user, $email, $url, $welcome );
    }

    public static function handle_verify_email() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $expected = (string) get_user_meta( $user_id, '_ge_email_verification_hash', true );
        $expires = (int) get_user_meta( $user_id, '_ge_email_verification_expires', true );
        $pending = sanitize_email( get_user_meta( $user_id, '_ge_pending_email', true ) );
        if ( ! $user_id || ! $token || ! $expected || ! hash_equals( $expected, hash( 'sha256', $token ) ) || $expires < time() || ! is_email( $pending ) ) { self::verification_redirect( 'verify-error' ); }
        $user = get_userdata( $user_id );
        if ( ! $user || ( email_exists( $pending ) && (int) email_exists( $pending ) !== $user_id ) ) { self::verification_redirect( 'verify-error' ); }
        $old_email = $user->user_email;
        if ( strtolower( $old_email ) !== strtolower( $pending ) ) {
            $updated = wp_update_user( array( 'ID' => $user_id, 'user_email' => $pending ) );
            if ( is_wp_error( $updated ) ) { self::verification_redirect( 'verify-error' ); }
            GE_WTP_Newsletter::unsubscribe_email( $old_email );
            if ( 'yes' === get_user_meta( $user_id, '_ge_newsletter_optin', true ) ) { GE_WTP_Newsletter::subscribe( $pending, $user->first_name, $user->last_name, 'verified_profile' ); }
        }
        update_user_meta( $user_id, '_ge_email_verified', 'yes' );
        delete_user_meta( $user_id, '_ge_pending_email' ); delete_user_meta( $user_id, '_ge_email_verification_hash' ); delete_user_meta( $user_id, '_ge_email_verification_expires' );
        self::verification_redirect( 'verified' );
    }

    public static function handle_resend_verification() {
        if ( ! is_user_logged_in() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_resend_customer_verification' );
        $user_id = get_current_user_id();
        $email = get_user_meta( $user_id, '_ge_pending_email', true ) ?: wp_get_current_user()->user_email;
        $result = self::request_email_verification( $user_id, $email );
        self::redirect_profile( is_wp_error( $result ) || false === $result ? 'error' : 'email-pending' );
    }

    private static function verification_redirect( $status ) {
        $page = get_page_by_path( 'mi-perfil' );
        $url = add_query_arg( 'profile_status', $status, $page ? get_permalink( $page ) : home_url( '/mi-perfil/' ) );
        wp_safe_redirect( is_user_logged_in() ? $url : wp_login_url( $url ) ); exit;
    }

    private static function mask_email( $email ) {
        $parts = explode( '@', $email );
        return substr( $parts[0], 0, 2 ) . '***@' . ( $parts[1] ?? '' );
    }

    private static function ensure_avatar_directory() {
        $directory = self::avatar_directory();
        if ( ! is_dir( $directory ) ) { wp_mkdir_p( $directory ); }
        if ( is_dir( $directory ) ) {
            if ( ! file_exists( $directory . '/.htaccess' ) ) { file_put_contents( $directory . '/.htaccess', "Require all denied\nDeny from all\n" ); }
            if ( ! file_exists( $directory . '/index.php' ) ) { file_put_contents( $directory . '/index.php', "<?php\nhttp_response_code( 404 );\nexit;\n" ); }
        }
        return is_dir( $directory ) && is_writable( $directory );
    }

    private static function save_avatar( $user_id ) {
        if ( ! empty( $_POST['remove_avatar'] ) ) { delete_user_meta( $user_id, '_ge_profile_avatar' ); }
        if ( empty( $_FILES['profile_avatar']['name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['profile_avatar']['error'] ) { return true; }
        $file = $_FILES['profile_avatar'];
        if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 2 * MB_IN_BYTES ) { return new WP_Error( 'avatar_size', 'La imagen supera el límite permitido.' ); }
        $allowed = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
        $original = sanitize_file_name( wp_basename( $file['name'] ) );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $original, $allowed );
        $extension = ! empty( $check['ext'] ) ? strtolower( $check['ext'] ) : '';
        if ( ! isset( $allowed[ $extension ] ) || ! self::ensure_avatar_directory() ) { return new WP_Error( 'avatar_type', 'La imagen no es válida.' ); }
        $stored_name = wp_generate_uuid4() . '.' . $extension;
        if ( ! move_uploaded_file( $file['tmp_name'], trailingslashit( self::avatar_directory() ) . $stored_name ) ) { return new WP_Error( 'avatar_upload', 'No fue posible guardar la imagen.' ); }
        update_user_meta( $user_id, '_ge_profile_avatar', array( 'stored_name' => $stored_name, 'mime' => $allowed[ $extension ], 'uploaded_at' => current_time( 'mysql' ) ) );
        return true;
    }

    public static function avatar_data( $user_id ) {
        $data = get_user_meta( $user_id, '_ge_profile_avatar', true );
        return is_array( $data ) && ! empty( $data['stored_name'] ) ? $data : array();
    }

    public static function avatar_url( $user_id ) {
        return wp_nonce_url( admin_url( 'admin-post.php?action=ge_customer_avatar&user_id=' . absint( $user_id ) ), 'ge_customer_avatar_' . absint( $user_id ) );
    }

    public static function avatar_markup( $user_id, $size = 48 ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) { return ''; }
        if ( self::avatar_data( $user_id ) ) { return '<img src="' . esc_url( self::avatar_url( $user_id ) ) . '" width="' . absint( $size ) . '" height="' . absint( $size ) . '" alt="' . esc_attr( $user->display_name ) . '">'; }
        return '<span aria-hidden="true">' . esc_html( strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $user->display_name, 0, 1 ) : substr( $user->display_name, 0, 1 ) ) ) . '</span>';
    }

    public static function handle_avatar() {
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        check_admin_referer( 'ge_customer_avatar_' . $user_id );
        if ( ! is_user_logged_in() || ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $data = self::avatar_data( $user_id );
        $path = $data ? trailingslashit( self::avatar_directory() ) . wp_basename( $data['stored_name'] ) : '';
        if ( ! $path || ! is_file( $path ) ) { wp_die( 'La imagen no existe.', 404 ); }
        nocache_headers(); header( 'Content-Type: ' . $data['mime'] ); header( 'Content-Length: ' . filesize( $path ) ); header( 'Content-Disposition: inline' ); readfile( $path ); exit;
    }

    public static function handle_save_profile() {
        if ( ! is_user_logged_in() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( 'ge_customer_save_profile' );
        $user_id = get_current_user_id();
        $old_email = wp_get_current_user()->user_email;
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) || ( email_exists( $email ) && (int) email_exists( $email ) !== $user_id ) ) { self::redirect_profile( 'error' ); }
        $email_changed = strtolower( $old_email ) !== strtolower( $email );
        $updated = wp_update_user( array( 'ID' => $user_id, 'first_name' => self::post_text( 'first_name' ), 'last_name' => self::post_text( 'last_name' ), 'display_name' => trim( self::post_text( 'first_name' ) . ' ' . self::post_text( 'last_name' ) ) ?: $old_email ) );
        if ( is_wp_error( $updated ) ) { self::redirect_profile( 'error' ); }
        self::save_shared_fields( $user_id );
        $avatar_result = self::save_avatar( $user_id );
        $optin = ! empty( $_POST['newsletter_optin'] );
        update_user_meta( $user_id, '_ge_newsletter_optin', $optin ? 'yes' : 'no' );
        if ( $optin ) { GE_WTP_Newsletter::subscribe( $old_email, self::post_text( 'first_name' ), self::post_text( 'last_name' ), 'profile' ); } else { GE_WTP_Newsletter::unsubscribe_email( $old_email ); }
        if ( $email_changed ) {
            $verification = self::request_email_verification( $user_id, $email );
            self::redirect_profile( is_wp_error( $avatar_result ) || is_wp_error( $verification ) || false === $verification ? 'error' : 'email-pending' );
        }
        self::redirect_profile( is_wp_error( $avatar_result ) ? 'error' : 'saved' );
    }

    public static function handle_staff_save_customer() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        check_admin_referer( 'ge_staff_save_customer_' . $user_id );
        if ( ! get_userdata( $user_id ) ) { wp_die( 'Cliente inválido.' ); }
        wp_update_user( array( 'ID' => $user_id, 'first_name' => self::post_text( 'first_name' ), 'last_name' => self::post_text( 'last_name' ) ) );
        self::save_shared_fields( $user_id );
        $avatar_result = self::save_avatar( $user_id );
        update_user_meta( $user_id, '_ge_customer_tags', self::post_text( 'internal_tags' ) );
        update_user_meta( $user_id, '_ge_customer_internal_notes', isset( $_POST['internal_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ) ) : '' );
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $user_id, 'profile_status' => is_wp_error( $avatar_result ) ? 'error' : 'saved' ) ) ); exit;
    }

    private static function save_shared_fields( $user_id ) {
        $whatsapp = self::post_text( 'whatsapp' );
        update_user_meta( $user_id, '_ge_whatsapp', $whatsapp );
        update_user_meta( $user_id, 'billing_phone', $whatsapp );
        update_user_meta( $user_id, '_ge_contact_person', self::post_text( 'contact_person' ) );
        update_user_meta( $user_id, 'billing_company', self::post_text( 'company' ) );
        update_user_meta( $user_id, '_ge_cuit', self::post_text( 'cuit' ) );
        $preference = isset( $_POST['delivery_preference'] ) ? sanitize_key( wp_unslash( $_POST['delivery_preference'] ) ) : '';
        update_user_meta( $user_id, '_ge_delivery_preference', in_array( $preference, array( 'retiro', 'envio', 'coordinar' ), true ) ? $preference : '' );
        update_user_meta( $user_id, self::ADDRESSES_META, self::sanitize_addresses( isset( $_POST['addresses'] ) ? wp_unslash( $_POST['addresses'] ) : array() ) );
    }

    private static function sanitize_addresses( $addresses ) {
        if ( ! is_array( $addresses ) ) { return array(); }
        $clean = array();
        foreach ( array_slice( $addresses, 0, 4 ) as $address ) {
            if ( ! is_array( $address ) ) { continue; }
            $row = self::empty_address();
            foreach ( array_keys( $row ) as $key ) { $row[ $key ] = isset( $address[ $key ] ) ? sanitize_text_field( $address[ $key ] ) : ''; }
            if ( ! $row['street'] ) { continue; }
            $clean[] = $row;
        }
        return $clean;
    }

    public static function addresses( $user_id ) {
        $addresses = get_user_meta( $user_id, self::ADDRESSES_META, true );
        return is_array( $addresses ) ? $addresses : array();
    }

    private static function empty_address() {
        return array( 'label' => '', 'recipient' => '', 'street' => '', 'city' => '', 'province' => '', 'postal_code' => '', 'phone' => '', 'hours' => '', 'notes' => '' );
    }

    private static function post_text( $key ) {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    }

    private static function redirect_profile( $status ) {
        $context = isset( $_POST['profile_context'] ) ? sanitize_key( wp_unslash( $_POST['profile_context'] ) ) : 'account';
        if ( 'markcom' === $context ) { $url = GE_WTP_Portal::portal_url( 'perfil' ); }
        elseif ( 'page' === $context ) { $page = get_page_by_path( 'mi-perfil' ); $url = $page ? get_permalink( $page ) : home_url( '/mi-perfil/' ); }
        else { $url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/mi-cuenta/' ); }
        wp_safe_redirect( add_query_arg( 'profile_status', $status, $url ) ); exit;
    }

    public static function render_staff() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { return; }
        $customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
        if ( $customer_id ) { echo self::profile_form( $customer_id, 'staff', true ); return; }
        $users = get_users( array( 'role__in' => array( 'customer', 'ge_markcom_client' ), 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500 ) );
        ?><div class="ge-staff-heading"><div><span>Clientes</span><h1>Perfiles de clientes</h1><p>Contactos, cotizaciones, pedidos y contexto comercial en un solo lugar.</p></div></div><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span><?php echo esc_html( count( $users ) ); ?> perfiles</span><h2>Clientes registrados</h2></div></div><?php if ( ! $users ) : ?><div class="ge-admin-empty">Todavía no hay clientes registrados.</div><?php else : ?><div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Cliente</th><th>Email</th><th>WhatsApp</th><th>Empresa</th><th>Cotiz.</th><th>Pedidos</th><th></th></tr></thead><tbody><?php foreach ( $users as $user ) : $quotes = self::customer_quotes( $user->ID ); $orders = self::customer_orders( $user ); ?><tr><td><div class="ge-customer-cell"><span class="ge-customer-thumb"><?php echo self::avatar_markup( $user->ID, 38 ); ?></span><span><strong><?php echo esc_html( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ); ?></strong><small><?php echo esc_html( implode( ', ', $user->roles ) ); ?></small></span></div></td><td><?php echo esc_html( $user->user_email ); ?></td><td><?php echo esc_html( get_user_meta( $user->ID, '_ge_whatsapp', true ) ?: get_user_meta( $user->ID, 'billing_phone', true ) ?: '—' ); ?></td><td><?php echo esc_html( get_user_meta( $user->ID, 'billing_company', true ) ?: '—' ); ?></td><td><strong><?php echo esc_html( count( $quotes ) ); ?></strong></td><td><strong><?php echo esc_html( count( $orders ) ); ?></strong></td><td><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $user->ID ) ) ); ?>">Ver ficha →</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
    }
}
