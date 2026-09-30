<?php

defined( 'ABSPATH' ) || exit;

/** Staff and customer controls for the new commercial quote lifecycle. */
final class GE_WTP_Commercial_Quote_UI {
    public static function init() {
        add_action( 'admin_post_ge_commercial_quote_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_commercial_quote_send', array( __CLASS__, 'handle_send' ) );
        add_action( 'admin_post_ge_commercial_quote_accept', array( __CLASS__, 'handle_accept' ) );
    }

    public static function render_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $quote_id = absint( $_GET['quote_id'] ?? 0 );
        $quote = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) : null;
        if ( is_wp_error( $quote ) ) { echo '<section class="ge-panel"><p>' . esc_html( $quote->get_error_message() ) . '</p></section>'; return; }
        $error = sanitize_key( wp_unslash( $_GET['quote_error'] ?? '' ) );
        $messages = array( 'save' => 'No pudimos guardar el presupuesto. Revisá cliente, ítems e importes.', 'send' => 'No pudimos enviar el presupuesto. Revisá la configuración fiscal y el registro de Notificaciones.' );
        echo '<div class="ge-staff-heading"><div><span>Comercial</span><h1>' . esc_html( $quote ? $quote['number'] : 'Nuevo presupuesto' ) . '</h1><p>Prepará una propuesta sin abrir producción ni pedir archivos.</p></div><a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes' ) ) . '">Ver presupuestos</a></div>';
        if ( $error ) { echo '<div class="ge-production-notice is-error">' . esc_html( $messages[ $error ] ?? 'Revisá el presupuesto.' ) . '</div>'; }
        if ( $quote ) { self::render_staff_detail( $quote ); }
        else { self::render_staff_form(); }
        self::render_staff_list();
    }

    private static function render_staff_form() {
        wp_enqueue_script( 'ge-manual-orders', GE_WTP_PLUGIN_URL . 'assets/js/manual-orders.js', array(), GE_WTP_VERSION, true );
        $catalog = array();
        foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) as $product ) {
            $label = $product->get_name() . ' (#' . $product->get_id() . ')';
            $catalog[ $label ] = array( 'id' => $product->get_id(), 'price' => (float) $product->get_price() );
        }
        echo '<form class="ge-manual-order" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_save">';
        wp_nonce_field( 'ge_commercial_quote_save' );
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>01 · Cliente</span><h2>Datos de contacto</h2></div></div><div class="ge-manual-contact-grid"><label>Nombre o razón social<input name="customer_name" required maxlength="160"></label><label>Email<input type="email" name="customer_email" required maxlength="190"></label></div><p class="ge-manual-help">Si el email ya existe, se usa su ficha. Si es nuevo, se crea una ficha y se prepara el acceso al portal.</p></section>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>02 · Ítems</span><h2>Productos y servicios</h2></div><button class="ge-manual-add-line" type="button" data-ge-add-line>＋ Agregar ítem</button></div><p>Precios unitarios antes de IVA.</p><div class="ge-manual-lines" data-ge-lines>';
        self::line_markup( 0 );
        echo '</div><datalist id="ge-manual-products">';
        foreach ( array_keys( $catalog ) as $label ) { echo '<option value="' . esc_attr( $label ) . '"></option>'; }
        echo '</datalist><script type="application/json" id="ge-manual-catalog">' . wp_json_encode( $catalog ) . '</script><template id="ge-manual-line-template">';
        self::line_markup( '__INDEX__' );
        echo '</template></section>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>03 · Condiciones</span><h2>Validez y pago</h2></div></div><div class="ge-manual-plan-grid"><label>Válido hasta<input type="date" name="valid_until" min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"></label><label>Seña disponible (%)<input type="number" name="deposit_percent" min="1" max="100" value="' . esc_attr( get_option( 'ge_commercial_deposit_percent', 50 ) ) . '"></label><label class="is-wide">Notas para el cliente<textarea name="notes_customer" rows="3"></textarea></label><label class="is-wide">Notas internas<textarea name="notes_internal" rows="3"></textarea></label></div></section>';
        echo '<div class="ge-manual-summary"><div><strong>Se guardará un borrador</strong><span>No se crearán pedido, archivos ni producción.</span></div><button class="ge-staff-button" type="submit">Guardar presupuesto</button></div></form>';
    }

    private static function line_markup( $index ) {
        echo '<div class="ge-manual-line" data-ge-line><label class="is-product">Producto o servicio<input type="search" name="lines[' . esc_attr( $index ) . '][label]" list="ge-manual-products" required maxlength="200"><input type="hidden" name="lines[' . esc_attr( $index ) . '][product_id]" value=""></label><label>Cantidad<input type="number" name="lines[' . esc_attr( $index ) . '][quantity]" required min="1" step="1" value="1"></label><label>Precio unitario neto ARS<input type="number" name="lines[' . esc_attr( $index ) . '][unit_price]" min="0.01" step="0.01" value="0"></label><label class="is-detail">Medidas, configuración y descripción<input type="text" name="lines[' . esc_attr( $index ) . '][details]" maxlength="500"></label><button type="button" data-ge-remove-line aria-label="Quitar ítem">×</button></div>';
    }

    private static function render_staff_detail( $quote ) {
        $customer = get_userdata( $quote['customer_id'] );
        echo '<section class="ge-production-card"><h2>' . esc_html( $quote['number'] ) . ' · versión ' . esc_html( $quote['version'] ) . '</h2><p>Cliente: ' . esc_html( $customer ? $customer->display_name . ' · ' . $customer->user_email : 'Ficha no disponible' ) . ' · Estado: ' . esc_html( $quote['status'] ) . '</p>';
        self::render_snapshot( $quote['snapshot'] );
        if ( 'draft' === $quote['status'] ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_send"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">';
            wp_nonce_field( 'ge_commercial_quote_send_' . $quote['id'] );
            echo '<button class="ge-staff-button" type="submit">Enviar al portal y avisar</button></form>';
        }
        echo '</section>';
        if ( class_exists( 'GE_WTP_Commercial_Checkout' ) ) { GE_WTP_Commercial_Checkout::render_staff_payment( $quote ); }
    }

    private static function render_staff_list() {
        $posts = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 30, 'orderby' => 'date', 'order' => 'DESC' ) );
        echo '<section class="ge-production-card"><h2>Presupuestos recientes</h2>';
        if ( ! $posts ) { echo '<p>Todavía no hay presupuestos comerciales.</p>'; }
        foreach ( $posts as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, get_current_user_id() );
            if ( is_wp_error( $quote ) ) { continue; }
            echo '<p><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'] ) ) ) . '">' . esc_html( $quote['number'] ) . '</a> · ' . esc_html( $quote['status'] ) . ' · v' . esc_html( $quote['version'] ) . '</p>';
        }
        echo '</section>';
    }

    public static function render_customer() {
        $customer_id = GE_WTP_Portal::portal_customer_id();
        $preview = GE_WTP_Portal::is_staff_preview();
        $selected = absint( $_GET['presupuesto'] ?? 0 );
        echo '<section class="ge-page-heading"><div><span class="ge-eyebrow">Propuestas</span><h1>Presupuestos</h1><p>Revisá las condiciones antes de aceptar y pagar.</p></div></section>';
        $posts = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 50, 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $customer_id ) );
        if ( ! $posts ) { echo '<section class="ge-panel"><p>Todavía no tenés presupuestos.</p></section>'; return; }
        foreach ( $posts as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, $customer_id );
            if ( is_wp_error( $quote ) || 'draft' === $quote['status'] || ( $selected && $selected !== $quote['id'] ) ) { continue; }
            echo '<article class="ge-panel"><span class="ge-eyebrow">' . esc_html( $quote['number'] ) . ' · v' . esc_html( $quote['version'] ) . '</span><h2>Presupuesto ' . esc_html( $quote['status'] ) . '</h2>';
            self::render_snapshot( $quote['snapshot'], true );
            if ( 'sent' === $quote['status'] && ! $preview ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_accept"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="version" value="' . esc_attr( $quote['version'] ) . '">';
                wp_nonce_field( 'ge_commercial_quote_accept_' . $quote['id'] . '_' . $quote['version'] );
                echo '<button class="ge-button ge-button-primary" type="submit">Aceptar presupuesto</button></form>';
            }
            if ( class_exists( 'GE_WTP_Commercial_Checkout' ) ) { GE_WTP_Commercial_Checkout::render_quote_checkout( $quote ); }
            echo '</article>';
        }
    }

    private static function render_snapshot( $snapshot, $customer = false ) {
        if ( empty( $snapshot['items'] ) ) { return; }
        echo '<ul class="ge-customer-quote-options">';
        foreach ( $snapshot['items'] as $line ) {
            echo '<li><span><strong>' . esc_html( $line['name'] ) . '</strong><br>' . esc_html( $line['quantity'] . ' × ' . GE_WTP_Quote_Balance::decimal( $line['unit_net_cents'] ) . ' ARS' ) . '<br>' . esc_html( $line['details'] ) . '</span><strong>' . esc_html( GE_WTP_Quote_Balance::decimal( $line['net_cents'] ) . ' ARS netos' ) . '</strong></li>';
        }
        echo '</ul><p>Subtotal neto: <strong>' . esc_html( GE_WTP_Quote_Balance::decimal( $snapshot['net_cents'] ) . ' ARS' ) . '</strong></p>';
        if ( isset( $snapshot['total_cents'] ) ) {
            echo '<p>Impuestos: ' . esc_html( GE_WTP_Quote_Balance::decimal( $snapshot['tax_cents'] ) . ' ARS' ) . ' · Total: <strong>' . esc_html( GE_WTP_Quote_Balance::decimal( $snapshot['total_cents'] ) . ' ARS' ) . '</strong></p>';
        } elseif ( ! $customer ) { echo '<p>Impuestos y total final pendientes de resolver al enviar.</p>'; }
        if ( ! empty( $snapshot['valid_until'] ) ) { echo '<p>Válido hasta: ' . esc_html( $snapshot['valid_until'] ) . '</p>'; }
        if ( ! empty( $snapshot['notes_customer'] ) ) { echo '<p>' . nl2br( esc_html( $snapshot['notes_customer'] ) ) . '</p>'; }
        if ( ! $customer && ! empty( $snapshot['notes_internal'] ) ) { echo '<p>Notas internas: ' . esc_html( $snapshot['notes_internal'] ) . '</p>'; }
    }

    public static function handle_save() {
        self::require_staff(); check_admin_referer( 'ge_commercial_quote_save' );
        $email = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        if ( ! is_email( $email ) || ! $name ) { self::staff_error( 'save' ); }
        $customer_id = absint( email_exists( $email ) );
        if ( ! $customer_id ) {
            $parts = preg_split( '/\s+/', trim( $name ), 2 );
            $customer_id = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer', 'first_name' => $parts[0] ?? $name, 'last_name' => $parts[1] ?? '', 'display_name' => $name ) );
            if ( is_wp_error( $customer_id ) ) { self::staff_error( 'save' ); }
            update_user_meta( $customer_id, '_ge_commercial_needs_invite', 'yes' );
        }
        $lines = array();
        foreach ( (array) ( $_POST['lines'] ?? array() ) as $line ) {
            $label = sanitize_text_field( wp_unslash( $line['label'] ?? '' ) );
            if ( ! $label ) { continue; }
            $lines[] = array( 'name' => preg_replace( '/\s*\(#\d+\)$/', '', $label ), 'product_id' => absint( $line['product_id'] ?? 0 ), 'quantity' => absint( $line['quantity'] ?? 0 ), 'unit_net' => sanitize_text_field( wp_unslash( $line['unit_price'] ?? '' ) ), 'details' => sanitize_text_field( wp_unslash( $line['details'] ?? '' ) ) );
        }
        $quote = GE_WTP_Commercial_Quotes::create_draft( $customer_id, $lines, array( 'valid_until' => wp_unslash( $_POST['valid_until'] ?? '' ), 'deposit_percent' => wp_unslash( $_POST['deposit_percent'] ?? 50 ), 'notes_customer' => wp_unslash( $_POST['notes_customer'] ?? '' ), 'notes_internal' => wp_unslash( $_POST['notes_internal'] ?? '' ) ) );
        if ( is_wp_error( $quote ) ) { self::staff_error( 'save' ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'] ) ) ); exit;
    }

    public static function handle_send() {
        self::require_staff(); $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_send_' . $quote_id );
        $quote = GE_WTP_Commercial_Quotes::send( $quote_id );
        if ( is_wp_error( $quote ) ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id, 'quote_error' => 'send' ) ) ); exit; }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id ) ) ); exit;
    }

    public static function handle_accept() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', 403 ); }
        $quote_id = absint( $_POST['quote_id'] ?? 0 ); $version = absint( $_POST['version'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_accept_' . $quote_id . '_' . $version );
        $quote = GE_WTP_Commercial_Quotes::accept( $quote_id, $version );
        if ( is_wp_error( $quote ) ) { wp_die( esc_html( $quote->get_error_message() ), '', array( 'response' => 409 ) ); }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $quote_id ) ) ); exit;
    }

    private static function require_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
    }
    private static function staff_error( $code ) {
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_error' => sanitize_key( $code ) ) ) ); exit;
    }
}
