<?php

defined( 'ABSPATH' ) || exit;

/** Staff and customer controls for the new commercial quote lifecycle. */
final class GE_WTP_Commercial_Quote_UI {
    public static function init() {
        add_action( 'admin_post_ge_commercial_quote_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_commercial_quote_send', array( __CLASS__, 'handle_send' ) );
        add_action( 'admin_post_ge_commercial_quote_accept', array( __CLASS__, 'handle_accept' ) );
        add_action( 'admin_post_ge_commercial_quote_convert', array( __CLASS__, 'handle_convert' ) );
        add_action( 'wp_ajax_ge_commercial_quote_price', array( __CLASS__, 'handle_price' ) );
    }

    public static function render_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $quote_id = absint( $_GET['quote_id'] ?? 0 );
        $quote = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) : null;
        $legacy = self::selected_legacy_quote();
        $new = ! empty( $_GET['new'] );
        if ( is_wp_error( $quote ) ) { echo '<section class="ge-panel"><p>' . esc_html( $quote->get_error_message() ) . '</p></section>'; return; }
        $error = sanitize_key( wp_unslash( $_GET['quote_error'] ?? '' ) );
        $messages = array( 'ge_quote_busy' => 'El presupuesto se está procesando. Esperá unos segundos y volvé a abrirlo; si el pedido ya existe se mostrará su enlace.', 'convert' => 'No se pudo convertir el presupuesto. Revisá su estado antes de volver a intentar.', 'save' => 'No pudimos guardar el presupuesto. Revisá cliente, ítems e importes.', 'send' => 'No pudimos enviar el presupuesto. Revisá la configuración fiscal y el registro de Notificaciones.', 'file' => 'El presupuesto se guardó, pero no se pudo adjuntar el archivo. Revisá el formato y tamaño.' );
        echo '<div class="ge-staff-heading"><div><span>Comercial</span><h1>' . esc_html( $quote ? $quote['number'] : ( $legacy ? ( $legacy['reference'] ?: 'Presupuesto anterior' ) : ( $new ? 'Nuevo presupuesto' : 'Presupuestos' ) ) ) . '</h1><p>' . esc_html( $legacy ? 'Registro histórico conservado en la ficha del cliente.' : ( $quote ? 'Propuesta comercial y actividad.' : ( $new ? 'Prepará la propuesta y agregá archivos si ya están disponibles.' : 'Consultá las propuestas actuales y los registros anteriores en un mismo lugar.' ) ) ) . '</p></div>';
        if ( ! $quote && ! $legacy ) { echo '<a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', $new ? array() : array( 'new' => 1 ) ) ) . '">' . esc_html( $new ? 'Ver presupuestos' : '＋ Nuevo presupuesto' ) . '</a>'; }
        echo '</div>';
        if ( 'convert' === $error && $quote_id ) { $detail = get_transient( 'ge_quote_convert_error_' . get_current_user_id() . '_' . $quote_id ); if ( $detail ) { $messages['convert'] = $detail; } }
        if ( $error ) { echo '<div class="ge-production-notice is-error">' . esc_html( $messages[ $error ] ?? 'Revisá el presupuesto.' ) . '</div>'; }
        if ( $quote && ! empty( $_GET['edit'] ) && in_array( $quote['status'], array( 'draft', 'sent', 'viewed' ), true ) && ! get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) ) { self::render_staff_form( $quote ); }
        elseif ( $quote ) { self::render_staff_detail( $quote ); }
        elseif ( $legacy ) { self::render_legacy_detail( $legacy ); }
        elseif ( $new ) { self::render_staff_form(); }
        if ( ! $quote && ! $legacy && ! $new ) { self::render_staff_list(); }
        else { echo '<p class="ge-quote-back"><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes' ) ) . '">← Volver a presupuestos</a></p>'; }
    }

    private static function render_staff_form( $quote = null ) {
        $editing = is_array( $quote );
        $snapshot = $editing ? $quote['snapshot'] : array();
        $customer = $editing ? get_userdata( $quote['customer_id'] ) : get_userdata( absint( $_GET['customer_id'] ?? 0 ) );
        $script = GE_WTP_PLUGIN_DIR . 'assets/js/commercial-quotes.js';
        wp_enqueue_script( 'ge-commercial-quotes', GE_WTP_PLUGIN_URL . 'assets/js/commercial-quotes.js', array(), is_file( $script ) ? (string) filemtime( $script ) : GE_WTP_VERSION, true );
        wp_localize_script( 'ge-commercial-quotes', 'geCommercialQuotes', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ge_commercial_quote_price' ) ) );
        $catalog = array();
        foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) as $product ) {
            $label = $product->get_name() . ' (#' . $product->get_id() . ')';
            $catalog[ $label ] = array_merge( array( 'id' => $product->get_id() ), GE_WTP_Commercial_Quote_Catalog::describe( $product ) );
        }
        echo '<form class="ge-manual-order ge-commercial-quote" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_save">';
        wp_nonce_field( 'ge_commercial_quote_save' );
        if ( $customer && ! $editing ) { echo '<input type="hidden" name="source_customer_id" value="' . esc_attr( $customer->ID ) . '">'; }
        if ( $editing ) { echo '<input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( $quote['version'] ) . '">'; }
        $phone = $customer ? ( get_user_meta( $customer->ID, '_ge_whatsapp', true ) ?: get_user_meta( $customer->ID, 'billing_phone', true ) ) : '';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>01 · Cliente</span><h2>Datos de contacto</h2></div></div><div class="ge-manual-contact-grid"><label>Nombre o razón social<input name="customer_name" required maxlength="160" value="' . esc_attr( $customer ? $customer->display_name : '' ) . '"' . ( $editing ? ' readonly' : '' ) . '></label><label>Email<input type="email" name="customer_email" required maxlength="190" value="' . esc_attr( $customer ? $customer->user_email : '' ) . '"' . ( $editing ? ' readonly' : '' ) . '></label><label>WhatsApp / teléfono<input type="tel" name="customer_phone" maxlength="50" autocomplete="tel" value="' . esc_attr( $phone ) . '" placeholder="+54 9 11..."></label></div><p class="ge-manual-help">' . esc_html( $editing ? 'Para cambiar de cliente, creá otro presupuesto.' : 'Si el email ya existe, se usa su ficha. Si es nuevo, se crea una ficha y se prepara el acceso al portal.' ) . '</p></section>';
        $selected_profile = $snapshot['billing_profile_id'] ?? ( $customer ? GE_WTP_Customer_Branches::default_profile_id( $customer->ID ) : 'default' );
        $selected_delivery = $snapshot['delivery_address_id'] ?? '';
        echo '<section class="ge-production-card" data-ge-branch-picker data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'ge_customer_branch_options' ) ) . '"><div class="ge-production-section-head"><div><span>Facturación y entrega</span><h2>Elegí la sucursal</h2></div></div><div class="ge-manual-contact-grid"><label>Facturar a<select name="billing_profile_id" data-ge-billing-profile>';
        $profiles = $customer ? GE_WTP_Customer_Branches::profiles( $customer->ID ) : array( array( 'id' => 'default', 'label' => 'Perfil principal', 'cuit' => '' ) );
        foreach ( $profiles as $profile ) { echo '<option value="' . esc_attr( $profile['id'] ) . '"' . selected( $selected_profile, $profile['id'], false ) . '>' . esc_html( $profile['label'] . ( ! empty( $profile['cuit'] ) ? ' · CUIT ' . $profile['cuit'] : '' ) ) . '</option>'; }
        echo '</select></label><label>Entregar en<select name="delivery_address_id" data-ge-delivery-address><option value="">A coordinar</option>';
        if ( $customer ) { foreach ( GE_WTP_Customers::addresses( $customer->ID ) as $index => $address ) { $id = (string) ( $address['id'] ?? $index ); echo '<option value="' . esc_attr( $id ) . '"' . selected( $selected_delivery, $id, false ) . '>' . esc_html( ( $address['label'] ?: 'Destino' ) . ' · ' . $address['street'] ) . '</option>'; } }
        echo '</select></label></div><p class="ge-manual-help">Los datos elegidos quedarán fijados en la versión enviada y en el pedido.</p></section>';
        echo '<script type="application/json" data-ge-branch-selected>' . wp_json_encode( array( 'profile' => $selected_profile, 'delivery' => $selected_delivery ) ) . '</script>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>02 · Ítems</span><h2>Productos y servicios</h2></div></div><p>Podés combinar productos del catálogo con trabajos personalizados en un mismo presupuesto.</p><div class="ge-quote-add-actions"><button class="ge-manual-add-line" type="button" data-ge-add-line="catalog_product">＋ Agregar producto del catálogo</button><button class="ge-manual-add-line" type="button" data-ge-add-line="custom">＋ Agregar ítem personalizado</button></div><div class="ge-manual-lines" data-ge-lines>';
        if ( $editing && ! empty( $snapshot['items'] ) ) { foreach ( $snapshot['items'] as $index => $line ) { self::line_markup( $index, $line ); } }
        else { self::line_markup( 0 ); }
        echo '</div><datalist id="ge-manual-products">';
        foreach ( array_keys( $catalog ) as $label ) { echo '<option value="' . esc_attr( $label ) . '"></option>'; }
        echo '</datalist><script type="application/json" id="ge-manual-catalog">' . wp_json_encode( $catalog ) . '</script><template id="ge-manual-line-template">';
        self::line_markup( '__INDEX__' );
        echo '</template></section>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>03 · Condiciones</span><h2>Validez y pago</h2></div></div><div class="ge-manual-plan-grid"><label>Válido hasta<input type="date" name="valid_until" min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $snapshot['valid_until'] ?? wp_date( 'Y-m-d', strtotime( '+30 days' ) ) ) . '"><small>30 días desde hoy por defecto; podés cambiarlo.</small></label><label>Seña disponible (%)<input type="number" name="deposit_percent" min="1" max="100" value="' . esc_attr( $snapshot['deposit_percent'] ?? get_option( 'ge_commercial_deposit_percent', 50 ) ) . '"></label><label class="is-wide">Notas para el cliente<textarea name="notes_customer" rows="3">' . esc_textarea( $snapshot['notes_customer'] ?? '' ) . '</textarea></label><label class="is-wide">Notas internas<textarea name="notes_internal" rows="3">' . esc_textarea( $snapshot['notes_internal'] ?? '' ) . '</textarea></label></div></section>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>04 · Archivos</span><h2>Arte opcional</h2></div></div><p>Podés cotizar sin archivo. Si ya tenés uno, quedará disponible en el pedido sin volver a subirlo.</p><label>Archivo preliminar o final<input type="file" name="ge_quote_file" accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.ai,.eps,.psd,.zip"></label><label>Estado del archivo<select name="ge_quote_file_source"><option value="preliminary">Preliminar</option><option value="final">Final entregado</option></select></label></section>';
        echo '<div class="ge-manual-summary"><div><strong>' . esc_html( $editing ? 'Guardar una nueva versión' : 'Preparar presupuesto' ) . '</strong><span>El archivo es opcional. Enviar avisará al cliente por email y le dará acceso desde su portal.</span></div><div class="ge-quote-actions"><button class="ge-staff-button is-secondary" type="submit" name="quote_intent" value="draft">Guardar borrador</button><button class="ge-staff-button" type="submit" name="quote_intent" value="send">Enviar al cliente</button></div></div></form>';
    }

    private static function line_markup( $index, $line = array() ) {
        $product_id = absint( $line['product_id'] ?? 0 );
        $source_type = $line['source_type'] ?? ( $product_id || ! $line ? 'catalog_product' : 'custom' );
        $label = (string) ( $line['name'] ?? '' ) . ( $product_id ? ' (#' . $product_id . ')' : '' );
        $price = isset( $line['unit_net_cents'] ) ? GE_WTP_Quote_Balance::decimal( (int) $line['unit_net_cents'] ) : '';
        $finishes = GE_WTP_Workflow::finishing_catalog();
        echo '<div class="ge-manual-line" data-ge-line data-ge-index="' . esc_attr( $index ) . '" data-ge-source="' . esc_attr( $source_type ) . '" data-ge-saved-config="' . esc_attr( wp_json_encode( $line['configuration'] ?? array() ) ) . '"><input type="hidden" name="lines[' . esc_attr( $index ) . '][source_type]" value="' . esc_attr( $source_type ) . '"><label class="is-product"><span data-ge-title-label>Producto del catálogo</span><input type="search" name="lines[' . esc_attr( $index ) . '][label]" list="ge-manual-products" required maxlength="200" value="' . esc_attr( $label ) . '" placeholder="Buscar producto"><input type="hidden" name="lines[' . esc_attr( $index ) . '][product_id]" value="' . esc_attr( $product_id ) . '"></label><label>Cantidad<input type="number" name="lines[' . esc_attr( $index ) . '][quantity]" required min="1" step="1" value="' . esc_attr( $line['quantity'] ?? 1 ) . '"></label><label class="ge-quote-unit">Unidad<select name="lines[' . esc_attr( $index ) . '][unit]">';
        foreach ( array( 'u' => 'u', 'm²' => 'm²', 'ml' => 'ml', 'lote' => 'lote', 'servicio' => 'servicio' ) as $value => $unit_label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $line['unit'] ?? 'u', $value, false ) . '>' . esc_html( $unit_label ) . '</option>'; }
        echo '</select></label><label>Precio unitario antes de IVA · ARS<input type="number" name="lines[' . esc_attr( $index ) . '][unit_price]" min="0" step="0.01" value="' . esc_attr( $price ) . '" required><small data-ge-price-hint></small></label><label class="is-detail">Descripción<input type="text" name="lines[' . esc_attr( $index ) . '][details]" maxlength="500" value="' . esc_attr( $line['details'] ?? '' ) . '" placeholder="Material, medidas o alcance"></label><label class="is-detail">Notas del ítem (opcionales)<input type="text" name="lines[' . esc_attr( $index ) . '][notes]" maxlength="500" value="' . esc_attr( $line['notes'] ?? '' ) . '"></label><div class="ge-quote-line-actions"><button type="button" data-ge-duplicate-line>Duplicar</button><button type="button" data-ge-remove-line>Quitar</button></div><output data-ge-line-subtotal>Subtotal ARS 0</output><div class="ge-quote-config" data-ge-config-fields hidden></div><fieldset class="ge-quote-finishes"><legend>Terminaciones disponibles <small>(opcionales; podés elegir varias)</small></legend>';
        foreach ( $finishes as $key => $finish ) { echo '<label><input type="checkbox" name="lines[' . esc_attr( $index ) . '][finishes][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) ( $line['finishes'] ?? array() ), true ), true, false ) . '><span>' . esc_html( $finish ) . '</span></label>'; }
        echo '</fieldset></div>';
    }

    private static function render_staff_detail( $quote ) {
        $customer = get_userdata( $quote['customer_id'] );
        $status_labels = array( 'draft' => 'Borrador', 'sent' => 'Enviado', 'viewed' => 'Visto', 'accepted' => 'Aceptado', 'rejected' => 'Rechazado', 'expired' => 'Vencido', 'converted' => 'Convertido en pedido', 'cancelled' => 'Cancelado' );
        echo '<section class="ge-production-card ge-quote-view"><header class="ge-quote-view-head"><div><span class="ge-quote-kicker">Presupuesto ' . esc_html( $quote['number'] ) . ' · Versión ' . esc_html( $quote['version'] ) . '</span><h2>' . esc_html( $customer ? $customer->display_name : 'Cliente no disponible' ) . '</h2><small>' . esc_html( get_the_date( 'd/m/Y', $quote['id'] ) ) . '</small></div><span class="ge-quote-status is-' . esc_attr( $quote['status'] ) . '">' . esc_html( $status_labels[ $quote['status'] ] ?? ucfirst( $quote['status'] ) ) . '</span></header>';
        echo '<div class="ge-quote-context"><div><span>Cliente</span><strong>' . esc_html( $customer ? $customer->display_name : 'Ficha no disponible' ) . '</strong>' . ( $customer ? '<small>' . esc_html( $customer->user_email ) . '</small>' : '' ) . '</div><div><span>Validez</span><strong>' . esc_html( ! empty( $quote['snapshot']['valid_until'] ) ? wp_date( 'd/m/Y', strtotime( $quote['snapshot']['valid_until'] ) ) : 'Sin fecha' ) . '</strong><small>Fecha límite para aceptar</small></div><div><span>Pago</span><strong>Seña ' . esc_html( $quote['snapshot']['deposit_percent'] ?? 50 ) . '% o total</strong><small>El cliente elige al aceptar</small></div></div>';
        self::render_snapshot( $quote['snapshot'] );
        $payment = GE_WTP_Commercial_Checkout::payment_status( $quote['id'], get_current_user_id() );
        if ( ! is_wp_error( $payment ) ) {
            $total = (int) ( $payment['final_total_cents'] ?? ( $quote['snapshot']['total_cents'] ?? 0 ) );
            $paid = (int) $payment['amount_paid_cents'];
            echo '<div class="ge-quote-context ge-quote-payment-summary"><div><span>Seña disponible</span><strong>' . esc_html( $quote['snapshot']['deposit_percent'] ?? 50 ) . '%</strong></div><div><span>Estado de pago</span><strong>' . esc_html( $payment['payment_status'] ?: 'Pendiente' ) . '</strong></div><div><span>Abonado</span><strong>' . esc_html( self::money( $paid ) ) . '</strong></div><div><span>Saldo</span><strong>' . esc_html( self::money( max( 0, $total - $paid ) ) ) . '</strong></div></div>';
        }
        $axes = GE_WTP_Commercial_Quotes::state_axes( $quote );
        $axis_labels = array( 'pending' => 'Pendiente', 'partial' => 'Seña / parcial', 'paid' => 'Pagado', 'failed' => 'Fallido', 'none' => 'Sin archivo', 'received' => 'Recibido', 'analyzed' => 'Analizado', 'final' => 'Archivo final', 'approval_pending' => 'Aprobación pendiente', 'approved' => 'Aprobado', 'not_created' => 'Sin pedido', 'created' => 'Pedido creado', 'ready' => 'Listo', 'in_production' => 'En producción', 'ready_for_delivery' => 'Listo para entregar', 'delivered' => 'Entregado' );
        echo '<div class="ge-quote-axes" aria-label="Estados separados"><span>Comercial: ' . esc_html( $status_labels[ $axes['commercial'] ] ?? $axes['commercial'] ) . '</span><span>Pago: ' . esc_html( $axis_labels[ $axes['payment'] ] ?? $axes['payment'] ) . '</span><span>Archivos y arte: ' . esc_html( $axis_labels[ $axes['artwork'] ] ?? $axes['artwork'] ) . '</span><span>Producción: ' . esc_html( $axis_labels[ $axes['production'] ] ?? $axes['production'] ) . '</span></div>';
        self::render_files( $quote, false );
        self::render_receipts( $quote, false );
        echo '<div class="ge-quote-view-actions">';
        if ( ! $quote['converted_order_id'] ) { echo '<button class="ge-staff-button" type="button" onclick="document.getElementById(\'ge-quote-convert\').showModal()">Pasar a producción</button>'; }
        else { echo '<a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $quote['converted_order_id'] ) ) ) . '">Abrir pedido #' . esc_html( wc_get_order( $quote['converted_order_id'] ) ? wc_get_order( $quote['converted_order_id'] )->get_order_number() : $quote['converted_order_id'] ) . '</a>'; }
        if ( in_array( $quote['status'], array( 'draft', 'sent', 'viewed' ), true ) && ! get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) ) { echo '<a class="ge-staff-button is-secondary" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'edit' => 1 ) ) ) . '">Editar</a>'; }
        if ( in_array( $quote['status'], array( 'sent', 'viewed' ), true ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_send"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">'; wp_nonce_field( 'ge_commercial_quote_send_' . $quote['id'] ); echo '<button class="ge-staff-button is-secondary" type="submit">Reenviar presupuesto</button></form>'; }
        echo '<a class="ge-staff-button is-secondary" href="' . esc_url( GE_WTP_Portal::preview_url( $quote['customer_id'], 'presupuestos', array( 'presupuesto' => $quote['id'] ) ) ) . '">Abrir portal cliente</a>';
        if ( 'draft' === $quote['status'] ) {
            $needs_roll_reprice = GE_WTP_Commercial_Quotes::needs_roll_reprice( $quote['snapshot'] );
            if ( $needs_roll_reprice ) { echo '<p class="ge-quote-review-note">Este borrador necesita una revisión de precio. Editalo y guardalo antes de enviarlo.</p>'; }
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_send"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">';
            wp_nonce_field( 'ge_commercial_quote_send_' . $quote['id'] );
            echo '<button class="ge-staff-button" type="submit"' . disabled( $needs_roll_reprice, true, false ) . '>Enviar al cliente</button></form>';
        }
        echo '</div></section>';
        if ( ! $quote['converted_order_id'] ) { self::render_conversion_dialog( $quote, $customer ); }
        self::render_events( $quote );
        if ( class_exists( 'GE_WTP_Commercial_Checkout' ) ) { GE_WTP_Commercial_Checkout::render_staff_payment( $quote ); }
    }

    private static function render_files( $quote, $customer_view ) {
        $files = GE_WTP_Commercial_Quote_Files::all( $quote['id'] );
        echo '<section class="' . esc_attr( $customer_view ? 'ge-panel' : 'ge-production-card' ) . ' ge-quote-files" id="ge-quote-files-' . esc_attr( $quote['id'] ) . '"><h3>Archivos del presupuesto</h3>';
        if ( ! $files ) { echo '<p>Sin archivos. Podés continuar con la cotización o el pedido.</p>'; }
        else {
            echo '<div class="ge-quote-file-list">';
            foreach ( $files as $file ) {
                GE_WTP_File_Analysis::render( $file, ! $customer_view );
                $analysis = is_array( $file['analysis'] ?? null ) ? $file['analysis'] : array();
                $details = array( size_format( (int) ( $file['size'] ?? 0 ) ), 'final' === ( $file['source_type'] ?? '' ) ? 'Archivo final' : 'Preliminar' );
                if ( ! empty( $analysis['pages'] ) ) { $details[] = absint( $analysis['pages'] ) . ' pág.'; }
                if ( ! empty( $analysis['width'] ) && ! empty( $analysis['height'] ) ) { $details[] = $analysis['width'] . ' × ' . $analysis['height'] . ' ' . ( $analysis['unit'] ?? '' ); }
                $details[] = 'Color y resolución: sin verificar';
                echo '<article class="ge-quote-file">';
                if ( in_array( $file['mime'] ?? '', array( 'image/jpeg', 'image/png' ), true ) ) { echo '<img class="ge-quote-file-thumb" loading="lazy" src="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'], true ) ) . '" alt="Vista previa de ' . esc_attr( $file['name'] ) . '">'; }
                echo '<div><strong>' . esc_html( $file['name'] ) . '</strong><small>' . esc_html( implode( ' · ', $details ) ) . '</small>';
                if ( ! empty( $analysis['warning'] ) ) { echo '<small class="ge-quote-file-warning">' . esc_html( $analysis['warning'] ) . '</small>'; }
                echo '</div><div class="ge-quote-file-links"><a href="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'], true ) ) . '" target="_blank" rel="noopener">Vista previa</a><a href="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'] ) ) . '">Descargar</a></div></article>';
            }
            echo '</div>';
            if ( ! $customer_view && ! $quote['converted_order_id'] ) {
                $latest = end( $files );
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h3>Aprobación de arte</h3><input type="hidden" name="action" value="ge_commercial_quote_approve_file"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="file_id" value="' . esc_attr( $latest['id'] ) . '"><input type="hidden" name="checksum" value="' . esc_attr( $latest['analysis']['sha256'] ?? '' ) . '">';
                wp_nonce_field( 'ge_commercial_quote_approve_file_' . $quote['id'] );
                echo '<p>Versión exacta: ' . esc_html( $latest['name'] ) . ' · ' . esc_html( ! empty( $latest['staff_approval']['approved'] ) ? 'Aprobado por staff' : 'Pendiente de revisión' ) . '</p><label>Producto<select name="item_index">';
                foreach ( $quote['snapshot']['items'] as $index => $line ) { echo '<option value="' . esc_attr( $index ) . '">' . esc_html( $line['name'] ) . '</option>'; }
                echo '</select></label><label><input type="checkbox" name="client_required" value="1" ' . checked( ! empty( $latest['client_approval_required'] ), true, false ) . '> Requiere aprobación del cliente</label><button type="submit" class="ge-staff-button">Aprobar internamente</button></form>';
            }
        }
        if ( ! $customer_view || ( ! GE_WTP_Portal::is_staff_preview() && ! in_array( $quote['status'], array( 'draft', 'rejected', 'cancelled' ), true ) ) ) {
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_file"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">';
            wp_nonce_field( 'ge_commercial_quote_file_' . $quote['id'] );
            echo '<label>Agregar arte<input type="file" name="ge_quote_file" accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.ai,.eps,.psd,.zip" required></label><label>Estado<select name="source_type"><option value="preliminary">Preliminar</option><option value="final">Final entregado</option></select></label><button class="' . esc_attr( $customer_view ? 'ge-button ge-button-secondary' : 'ge-staff-button is-secondary' ) . '" type="submit">Subir archivo</button></form>';
        }
        echo '</section>';
    }

    private static function render_receipts( $quote, $customer_view ) {
        $receipts = GE_WTP_Commercial_Quote_Files::all( $quote['id'], 'comprobante' );
        echo '<section class="' . esc_attr( $customer_view ? 'ge-panel' : 'ge-production-card' ) . ' ge-quote-files" id="ge-quote-receipts-' . esc_attr( $quote['id'] ) . '"><h3>Comprobantes de pago</h3><p>Este bloque es independiente de los archivos para producción. La carga no confirma la acreditación.</p>';
        foreach ( $receipts as $receipt ) { echo '<p><a href="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $receipt['id'], false, 'comprobante' ) ) . '">' . esc_html( $receipt['name'] ) . '</a> · pendiente de verificación bancaria</p>'; }
        if ( ! $customer_view || ( ! GE_WTP_Portal::is_staff_preview() && ! in_array( $quote['status'], array( 'draft', 'rejected', 'cancelled' ), true ) ) ) {
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_receipt"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '">';
            wp_nonce_field( 'ge_commercial_quote_receipt_' . $quote['id'] );
            echo '<label>Comprobante PDF o imagen<input type="file" name="ge_quote_receipt" accept=".pdf,.jpg,.jpeg,.png" required></label><button class="' . esc_attr( $customer_view ? 'ge-button ge-button-secondary' : 'ge-staff-button is-secondary' ) . '" type="submit">Subir comprobante</button></form>';
        }
        echo '</section>';
    }

    private static function render_conversion_dialog( $quote, $customer ) {
        $snapshot = $quote['snapshot'];
        echo '<dialog id="ge-quote-convert" class="ge-quote-convert"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_convert"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( $quote['version'] ) . '">';
        wp_nonce_field( 'ge_commercial_quote_convert_' . $quote['id'] );
        echo '<h2>Crear pedido desde presupuesto ' . esc_html( $quote['number'] ) . '</h2><p>' . esc_html( $customer ? $customer->display_name : 'Cliente' ) . ' · ' . esc_html( count( $snapshot['items'] ?? array() ) ) . ' ítems · ' . esc_html( isset( $snapshot['total_cents'] ) ? self::money( $snapshot['total_cents'] ) : 'Total fiscal a resolver' ) . '</p>';
        echo '<div class="ge-quote-convert-summary"><strong>Datos que recibirá el pedido</strong><ul>';
        foreach ( (array) ( $snapshot['items'] ?? array() ) as $line ) { echo '<li>' . esc_html( $line['name'] ?? 'Ítem' ) . ' · ' . esc_html( $line['quantity'] ?? 1 ) . ' ' . esc_html( $line['unit'] ?? 'u' ) . '</li>'; }
        $billing = $snapshot['billing']['profile'] ?? array();
        echo '<li>Facturación: ' . esc_html( $billing['legal_name'] ?? ( $snapshot['billing_profile_id'] ?? 'Perfil principal' ) ) . '</li>';
        $delivery = $snapshot['delivery'] ?? array();
        echo '<li>Entrega: ' . esc_html( $delivery['street'] ?? 'A coordinar' ) . '</li>';
        foreach ( GE_WTP_Commercial_Quote_Files::all( $quote['id'] ) as $file ) { echo '<li>Archivo: ' . esc_html( $file['name'] ?? 'Sin nombre' ) . '</li>'; }
        echo '</ul></div>';
        echo '<p>Se conservarán los ítems, facturación, dirección y ' . esc_html( count( GE_WTP_Commercial_Quote_Files::all( $quote['id'] ) ) ) . ' archivo(s). Podés continuar sin pago o archivo; el arte final seguirá requiriendo aprobación.</p>';
        echo '<label>Confirmación comercial<select name="confirmation_method"><option value="staff">Aprobación interna / staff</option>';
        if ( 'portal' === get_post_meta( $quote['id'], '_ge_commercial_accept_source', true ) ) { echo '<option value="portal">Portal · aceptación registrada</option>'; }
        echo '<option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="phone">Teléfono</option><option value="in_person">Presencial</option><option value="other">Otro</option></select></label>';
        echo '<label>Pago informado<select name="payment_state"><option value="unregistered">Sin registrar</option><option value="deposit">Seña informada</option><option value="paid">Pago total informado</option></select></label><label>Importe recibido informado (opcional)<input type="number" name="amount_received" min="0" step="0.01" inputmode="decimal"></label><p>El pago informado requiere conciliación; no se marcará acreditado automáticamente.</p>';
        echo '<label>Proveedor (opcional)<select name="supplier_key"><option value="">Asignar después</option>';
        foreach ( GE_WTP_Production::suppliers() as $key => $supplier ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $supplier['name'] ) . '</option>'; }
        echo '</select></label><label>Notas de producción<textarea name="production_notes" rows="3"></textarea></label><label>Referencia de la confirmación (opcional)<textarea name="reason" rows="2" placeholder="Ej.: aceptación comercial manual por WhatsApp"></textarea></label>';
        echo '<div class="ge-quote-dialog-actions"><button type="button" class="ge-staff-button is-secondary" onclick="this.closest(\'dialog\').close()">Cancelar</button><button type="submit" class="ge-staff-button">Crear pedido y continuar</button></div></form></dialog>';
    }

    private static function render_events( $quote ) {
        $events = get_post_meta( $quote['id'], '_ge_commercial_events', true );
        if ( ! is_array( $events ) || ! $events ) { return; }
        $labels = array( 'created' => 'Creado', 'revised' => 'Editado', 'sent' => 'Enviado', 'viewed' => 'Visto por el cliente', 'accepted' => 'Aceptado en portal', 'accepted_staff' => 'Aceptación registrada por staff', 'file_uploaded' => 'Archivo cargado', 'file_attached' => 'Archivo vinculado', 'receipt_uploaded' => 'Comprobante cargado', 'receipt_attached' => 'Comprobante vinculado', 'payment_started' => 'Pago iniciado', 'payment_confirmed' => 'Pago confirmado', 'converted' => 'Convertido a pedido' );
        echo '<section class="ge-production-card ge-quote-events"><h3>Actividad del presupuesto</h3><ol>';
        foreach ( array_reverse( $events ) as $event ) {
            echo '<li><strong>' . esc_html( $labels[ $event['event'] ?? '' ] ?? ucfirst( $event['event'] ?? 'Actividad' ) ) . '</strong><time>' . esc_html( ! empty( $event['at'] ) ? wp_date( 'd/m/Y H:i', strtotime( $event['at'] ) ) : '' ) . '</time></li>';
        }
        echo '</ol></section>';
    }

    private static function render_staff_list() {
        $rows = self::history_rows();
        $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        $sort = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'oldest' : 'newest';
        if ( 'oldest' === $sort ) { $rows = array_reverse( $rows ); }
        $status = sanitize_key( wp_unslash( $_GET['quote_status'] ?? '' ) );
        $labels = array( 'draft'=>'Borrador', 'sent'=>'Enviado', 'viewed'=>'Visto', 'accepted'=>'Aceptado', 'converted'=>'Convertido', 'rejected'=>'Rechazado', 'expired'=>'Vencido' );
        if ( $status && ! isset( $labels[$status] ) ) { $status = ''; }
        $rows = array_values( array_filter( $rows, static function( $row ) use ( $query, $status, $labels ) {
            if ( $status && 0 !== mb_stripos( $row['status'], $labels[$status] ) ) { return false; }
            return ! $query || false !== mb_stripos( implode( ' ', array( $row['reference'], $row['customer'], $row['status'], $row['search'] ?? '' ) ), $query );
        } ) );
        echo '<form class="ge-v3-toolbar" role="search" method="get" action="' . esc_url( GE_WTP_Staff_Portal::portal_url() ) . '"><input type="hidden" name="section" value="quotes"><input type="hidden" name="quote_status" value="' . esc_attr($status) . '"><label class="is-search"><span>Buscar</span><input type="search" name="q" value="' . esc_attr($query) . '" placeholder="Número, cliente o email"></label><button type="submit">Buscar</button></form>';
        echo '<nav class="ge-v3-chips" aria-label="Filtrar presupuestos">';
        foreach( array_merge(array(''=>'Todos'),$labels) as $key=>$label ) { echo '<a ' . ( $status===$key?'class="is-active" aria-current="page"':'' ) . ' href="' . esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_status'=>$key,'q'=>$query,'sort'=>$sort))) . '">' . esc_html($label) . '</a>'; }
        echo '</nav>';
        echo '<section class="ge-production-card ge-quote-history" id="presupuestos"><div class="ge-production-section-head"><div><span>Comercial · historial unificado</span><h2>Todos los presupuestos</h2></div><strong>' . esc_html( count( $rows ) ) . ' registros</strong></div>';
        echo '<p class="ge-v3-history-note">Propuestas actuales y referencias históricas. Las referencias anteriores se conservan.</p>';
        if ( ! $rows ) { echo '<div class="ge-admin-empty">No hay presupuestos con esta búsqueda o filtro.</div></section>'; return; }
        $page = max( 1, absint( $_GET['quote_page'] ?? 1 ) );
        $pages = (int) ceil( count( $rows ) / 20 );
        $page = min( $page, $pages );
        echo '<div class="ge-quote-history-list">';
        foreach ( array_slice( $rows, ( $page - 1 ) * 20, 20 ) as $row ) {
            echo '<article class="ge-quote-history-row"><div><small>' . esc_html( $row['source'] . ' · ' . $row['date'] ) . '</small><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['reference'] ) . '</a><span>' . esc_html( $row['customer'] ) . '</span></div><div><strong>' . esc_html( $row['status'] ) . '</strong><small>' . esc_html( $row['amount'] ) . '</small></div><a class="ge-quote-history-open" href="' . esc_url( $row['url'] ) . '">Ver detalle →</a></article>';
        }
        echo '</div>';
        if ( $pages > 1 ) { echo '<nav class="ge-quote-history-pages" aria-label="Páginas de presupuestos">'; if ( $page > 1 ) { echo '<a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_page' => $page - 1, 'q' => $query, 'quote_status' => $status, 'sort' => $sort ) ) ) . '">← Anterior</a>'; } echo '<span>Página ' . esc_html( $page ) . ' de ' . esc_html( $pages ) . '</span>'; if ( $page < $pages ) { echo '<a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_page' => $page + 1, 'q' => $query, 'quote_status' => $status, 'sort' => $sort ) ) ) . '">Siguiente →</a>'; } echo '</nav>'; }
        echo '</section>';
    }

    private static function selected_legacy_quote() {
        $user_id = absint( $_GET['legacy_user'] ?? 0 );
        $key = sanitize_key( wp_unslash( $_GET['legacy_key'] ?? '' ) );
        if ( ! $user_id || 0 !== strpos( $key, GE_WTP_Customer_Quotes::PREFIX ) ) { return null; }
        $quote = get_user_meta( $user_id, $key, true );
        if ( ! is_array( $quote ) ) { return null; }
        $quote['user_id'] = $user_id;
        $quote['key'] = $key;
        return $quote;
    }

    private static function render_legacy_detail( $quote ) {
        $customer = get_userdata( $quote['user_id'] );
        echo '<section class="ge-production-card ge-quote-view"><div class="ge-production-section-head"><div><span>Presupuesto anterior · ficha del cliente</span><h2>' . esc_html( $quote['title'] ?? $quote['reference'] ?? 'Propuesta' ) . '</h2></div><strong>' . esc_html( self::legacy_status( $quote ) ) . '</strong></div>';
        echo '<p><strong>Cliente:</strong> ' . esc_html( $customer ? $customer->display_name : 'Ficha no disponible' ) . '</p>';
        if ( ! empty( $quote['details'] ) ) { echo '<p>' . nl2br( esc_html( $quote['details'] ) ) . '</p>'; }
        $items = array_values( array_filter( (array) ( $quote['items'] ?? array() ), 'is_array' ) );
        $options = array_values( array_filter( (array) ( $quote['options'] ?? array() ), 'is_array' ) );
        $lines = $items ?: $options;
        if ( $lines ) {
            echo '<div class="ge-quote-history-lines">';
            foreach ( $lines as $line ) { echo '<div><span><strong>' . esc_html( $line['title'] ?? $line['label'] ?? 'Trabajo gráfico' ) . '</strong><small>' . esc_html( $line['details'] ?? '' ) . '</small></span><b>' . wp_kses_post( wc_price( (float) ( $line['total'] ?? 0 ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</b></div>'; }
            echo '</div>';
        }
        if ( ! empty( $quote['order_id'] ) && wc_get_order( absint( $quote['order_id'] ) ) ) { echo '<p><a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => absint( $quote['order_id'] ) ) ) ) . '">Ver pedido vinculado</a></p>'; }
        echo '<p class="ge-quote-review-note">Este registro conserva las condiciones anteriores; no crea un pedido nuevo ni envía mensajes.</p></section>';
    }

    private static function legacy_status( $quote ) {
        if ( ! empty( $quote['order_id'] ) && wc_get_order( absint( $quote['order_id'] ) ) ) { return 'Aceptado · pedido creado'; }
        $labels = array( 'enviada' => 'Enviado', 'quoted' => 'Cotizado · envío sin verificar', 'borrador' => 'Borrador', 'pending_price' => 'Precio pendiente' );
        return $labels[ sanitize_key( $quote['status'] ?? '' ) ] ?? 'En seguimiento';
    }

    private static function history_rows() {
        global $wpdb;
        $rows = array();
        $labels = array( 'draft' => 'Borrador', 'sent' => 'Enviado', 'viewed' => 'Visto', 'accepted' => 'Aceptado', 'rejected' => 'Rechazado', 'expired' => 'Vencido', 'converted' => 'Convertido en pedido', 'cancelled' => 'Cancelado' );
        foreach ( get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) ) as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, get_current_user_id() );
            if ( is_wp_error( $quote ) ) { continue; }
            $customer = get_userdata( $quote['customer_id'] );
            $rows[] = array( 'time' => strtotime( $post->post_date ), 'date' => wp_date( 'd/m/Y', strtotime( $post->post_date ) ), 'source' => 'Presupuesto actual', 'reference' => $quote['number'], 'search' => $customer ? $customer->user_email : '', 'customer' => $customer ? $customer->display_name : 'Cliente no disponible', 'status' => $labels[ $quote['status'] ] ?? 'En seguimiento', 'amount' => isset( $quote['snapshot']['total_cents'] ) ? wp_strip_all_tags( wc_price( $quote['snapshot']['total_cents'] / 100 ) ) : 'Ver detalle', 'url' => GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'] ) ) );
        }
        $like = $wpdb->esc_like( GE_WTP_Customer_Quotes::PREFIX ) . '%';
        $legacy = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s ORDER BY umeta_id DESC", $like ), ARRAY_A );
        foreach ( (array) $legacy as $record ) {
            $user_id = absint( $record['user_id'] ); $key = (string) $record['meta_key'];
            $quote = get_user_meta( $user_id, $key, true );
            if ( ! is_array( $quote ) ) { continue; }
            $customer = get_userdata( $user_id );
            $time = strtotime( (string) ( $quote['captured_at'] ?? '' ) ) ?: 0;
            $items = array_values( array_filter( (array) ( $quote['items'] ?? array() ), 'is_array' ) );
            $options = array_values( array_filter( (array) ( $quote['options'] ?? array() ), 'is_array' ) );
            $total = $items ? array_sum( array_map( function( $item ) { return (float) ( $item['total'] ?? 0 ); }, $items ) ) : ( 1 === count( $options ) ? (float) ( $options[0]['total'] ?? 0 ) : 0 );
            $rows[] = array( 'time' => $time, 'date' => $time ? wp_date( 'd/m/Y', $time ) : 'Sin fecha', 'source' => 'Ficha anterior', 'reference' => (string) ( $quote['reference'] ?? $quote['title'] ?? 'Presupuesto anterior' ), 'customer' => $customer ? $customer->display_name : 'Cliente no disponible', 'status' => self::legacy_status( $quote ), 'amount' => $total > 0 ? wp_strip_all_tags( wc_price( $total ) ) . ' + IVA' : ( count( $options ) > 1 ? 'Varias alternativas' : 'Ver detalle' ), 'url' => GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'legacy_user' => $user_id, 'legacy_key' => $key ) ) );
        }
        foreach ( self::historical_quote_orders() as $order ) {
            $note = (string) $order->get_customer_note();
            $is_quote = GE_WTP_Customer_Quotes::is_quote_order( $order );
            $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order', true );
            $sent = 'ge-enviado' === $order->get_status();
            if ( ! $is_quote && ! $is_markcom && ! $sent && false === stripos( $note, 'presupuesto' ) ) { continue; }
            $created = $order->get_date_created();
            $rows[] = array( 'time' => $created ? $created->getTimestamp() : 0, 'date' => $created ? wc_format_datetime( $created, 'd/m/Y' ) : 'Sin fecha', 'source' => $is_markcom ? 'Markcom · pedido anterior' : 'Pedido anterior', 'reference' => 'Pedido #' . $order->get_id(), 'customer' => $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_billing_email(), 'status' => $is_quote ? 'Aceptado · pedido creado' : ( $is_markcom ? 'Convertido en pedido' : ( $sent ? 'Enviado' : 'Referencia de presupuesto · revisar' ) ), 'amount' => wp_strip_all_tags( $order->get_formatted_order_total() ), 'url' => GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) );
        }
        usort( $rows, function( $a, $b ) { return $b['time'] <=> $a['time']; } );
        return $rows;
    }

    private static function historical_quote_orders() {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table = $wpdb->prefix . 'wc_orders_meta';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_table ) ) ) !== $orders_table ) {
            return wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
        }
        $ids = $wpdb->get_col( "SELECT DISTINCT o.id FROM {$orders_table} o LEFT JOIN {$meta_table} m ON m.order_id = o.id WHERE o.type = 'shop_order' AND (o.status = 'wc-ge-enviado' OR o.customer_note LIKE '%presupuesto%' OR (m.meta_key IN ('_ge_quote','_ge_markcom_order') AND m.meta_value = 'yes') OR m.meta_key = '_ge_customer_quote_key') ORDER BY o.id DESC" );
        $orders = array();
        foreach ( (array) $ids as $id ) { $order = wc_get_order( absint( $id ) ); if ( $order instanceof WC_Order ) { $orders[] = $order; } }
        return $orders;
    }

    public static function render_customer() {
        $customer_id = GE_WTP_Portal::portal_customer_id();
        $preview = GE_WTP_Portal::is_staff_preview();
        $selected = absint( $_GET['presupuesto'] ?? 0 );
        echo '<section class="ge-page-heading"><div><span class="ge-eyebrow">Propuestas</span><h1>Presupuestos</h1><p>Revisá las condiciones antes de aceptar y pagar.</p></div></section>';
        $posts = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 50, 'include' => $selected ? array( $selected ) : array(), 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $customer_id ) );
        if ( ! $posts ) { echo '<section class="ge-panel"><p>Todavía no tenés presupuestos.</p></section>'; return; }
        foreach ( $posts as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, $customer_id );
            if ( is_wp_error( $quote ) || 'draft' === $quote['status'] || ( $selected && $selected !== $quote['id'] ) ) { continue; }
            if ( ! $preview ) { GE_WTP_Commercial_Quotes::mark_viewed( $quote['id'], $customer_id ); $quote = GE_WTP_Commercial_Quotes::get( $quote['id'], $customer_id ); }
            $customer_status = array( 'sent' => 'Enviado', 'viewed' => 'Visto', 'accepted' => 'Aceptado', 'converted' => 'En proceso', 'rejected' => 'Rechazado', 'expired' => 'Vencido' );
            echo '<article class="ge-panel ge-quote-customer"><span class="ge-eyebrow">' . esc_html( $quote['number'] ) . ' · versión ' . esc_html( $quote['version'] ) . '</span><h2>Presupuesto ' . esc_html( strtolower( $customer_status[ $quote['status'] ] ?? $quote['status'] ) ) . '</h2>';
            self::render_snapshot( $quote['snapshot'], true );
            echo '<a class="ge-button ge-button-secondary ge-quote-pdf-download" href="' . esc_url( GE_WTP_Commercial_Quote_PDF::url( $quote['id'] ) ) . '"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg> Descargar PDF</a>';
            if ( in_array( $quote['status'], array( 'sent', 'viewed' ), true ) && ! $preview && ( empty( $quote['snapshot']['valid_until'] ) || $quote['snapshot']['valid_until'] >= wp_date( 'Y-m-d' ) ) ) {
                echo '<form class="ge-quote-customer-action" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_accept"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="version" value="' . esc_attr( $quote['version'] ) . '">';
                wp_nonce_field( 'ge_commercial_quote_accept_' . $quote['id'] . '_' . $quote['version'] );
                echo '<button class="ge-button ge-button-primary" type="submit">Aceptar presupuesto</button></form>';
            }
            echo '<nav class="ge-quote-customer-links" aria-label="Acciones del presupuesto"><a href="#ge-quote-files-' . esc_attr( $quote['id'] ) . '">Subir archivos para producción</a><a href="#ge-quote-receipts-' . esc_attr( $quote['id'] ) . '">Subir comprobante</a><a href="#ge-quote-payment-' . esc_attr( $quote['id'] ) . '">Ir a pagar</a></nav>';
            self::render_files( $quote, true );
            self::render_receipts( $quote, true );
            if ( class_exists( 'GE_WTP_Commercial_Checkout' ) ) { GE_WTP_Commercial_Checkout::render_quote_checkout( $quote ); }
            echo '</article>';
        }
    }

    private static function render_snapshot( $snapshot, $customer = false ) {
        if ( empty( $snapshot['items'] ) ) { return; }
        $profile = $snapshot['billing']['profile'] ?? array();
        $delivery = $snapshot['delivery'] ?? array();
        if ( $profile ) {
            echo '<div class="ge-quote-context"><div><span>Facturar a</span><strong>' . esc_html( ( $profile['label'] ?? 'Perfil principal' ) . ' · ' . ( $profile['legal_name'] ?: 'Nombre fiscal pendiente' ) ) . '</strong></div><div><span>Entregar en</span><strong>' . esc_html( $delivery ? ( ( $delivery['label'] ?? 'Destino' ) . ' · ' . ( $delivery['street'] ?? '' ) ) : 'A coordinar' ) . '</strong></div></div>';
        }
        $is_invoice_c = 'C' === ( $snapshot['billing']['resolution']['document_type'] ?? '' );
        echo '<section class="ge-quote-summary" aria-label="Detalle de productos e importes"><div class="ge-quote-summary-heading"><div><span class="ge-quote-kicker">Productos y servicios</span><h3>Detalle de la propuesta</h3></div><span>' . esc_html( count( $snapshot['items'] ) ) . ' ítems</span></div><div class="ge-quote-items">';
        foreach ( $snapshot['items'] as $line ) {
            $finish_labels = array_intersect_key( GE_WTP_Workflow::finishing_catalog(), array_flip( $line['finishes'] ?? array() ) );
            echo '<article class="ge-quote-item"><div class="ge-quote-item-copy"><h4>' . esc_html( $line['name'] ) . '</h4>';
            $configuration = self::customer_configuration_label( $line );
            if ( $configuration ) { echo '<p class="ge-quote-spec">' . esc_html( $configuration ) . '</p>'; }
            if ( $finish_labels ) { echo '<p class="ge-quote-spec"><span>Terminaciones</span> ' . esc_html( implode( ', ', $finish_labels ) ) . '</p>'; }
            if ( ! empty( $line['details'] ) ) { echo '<p class="ge-quote-spec"><span>Observaciones</span> ' . esc_html( $line['details'] ) . '</p>'; }
            if ( ! empty( $line['notes'] ) ) { echo '<p class="ge-quote-spec"><span>Notas</span> ' . esc_html( $line['notes'] ) . '</p>'; }
            if ( ! $customer && ! empty( $line['configuration']['roll_width_cm'] ) ) { echo '<details class="ge-quote-internal"><summary>Detalle interno de cálculo</summary><p>Ancho considerado: ' . esc_html( $line['configuration']['roll_width_cm'] ) . ' cm.</p></details>'; }
            echo '</div><div class="ge-quote-item-price"><span>' . esc_html( $line['quantity'] ) . ' ' . esc_html( $line['unit'] ?? 'u' ) . ' × ' . esc_html( self::money( $line['unit_net_cents'] ) ) . '</span><strong>' . esc_html( self::money( $line['net_cents'] ) ) . '</strong></div></article>';
        }
        echo '</div><div class="ge-quote-totals"><div><span>' . esc_html( $is_invoice_c ? 'Subtotal' : 'Subtotal antes de impuestos' ) . '</span><strong>' . esc_html( self::money( $snapshot['net_cents'] ) ) . '</strong></div>';
        if ( isset( $snapshot['total_cents'] ) ) {
            if ( ! $is_invoice_c && ! empty( $snapshot['tax_cents'] ) ) { echo '<div><span>Impuestos</span><strong>' . esc_html( self::money( $snapshot['tax_cents'] ) ) . '</strong></div>'; }
            echo '<div class="is-total"><span>Total del presupuesto</span><strong>' . esc_html( self::money( $snapshot['total_cents'] ) ) . '</strong></div>';
        } elseif ( ! $customer ) { echo '<p class="ge-quote-tax-note">El total definitivo se confirmará al enviar el presupuesto.</p>'; }
        echo '</div>';
        if ( $customer && ! empty( $snapshot['valid_until'] ) ) { echo '<p class="ge-quote-validity">Válido hasta el ' . esc_html( wp_date( 'd/m/Y', strtotime( $snapshot['valid_until'] ) ) ) . '</p>'; }
        if ( ! empty( $snapshot['notes_customer'] ) ) { echo '<div class="ge-quote-note"><strong>Nota para el cliente</strong><p>' . nl2br( esc_html( $snapshot['notes_customer'] ) ) . '</p></div>'; }
        if ( ! $customer && ! empty( $snapshot['notes_internal'] ) ) { echo '<div class="ge-quote-note is-internal"><strong>Nota interna</strong><p>' . nl2br( esc_html( $snapshot['notes_internal'] ) ) . '</p></div>'; }
        echo '</section>';
    }

    public static function customer_configuration_label( $line ) {
        $configuration = $line['configuration'] ?? array();
        if ( preg_match( '/^GF-VIN-00[1-4]$/', (string) ( $line['sku'] ?? '' ) ) && isset( $configuration['width'], $configuration['height'] ) ) {
            return $configuration['width'] . ' × ' . $configuration['height'] . ' cm';
        }
        return (string) ( $line['configuration_label'] ?? '' );
    }

    private static function money( $cents ) {
        $cents = (int) $cents;
        return 'ARS ' . number_format( $cents / 100, $cents % 100 ? 2 : 0, ',', '.' );
    }

    public static function handle_save() {
        self::require_staff(); check_admin_referer( 'ge_commercial_quote_save' );
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        $existing = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) : null;
        if ( $quote_id && is_wp_error( $existing ) ) { self::staff_error( 'save' ); }
        $email = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        if ( ! is_email( $email ) || ! $name ) { self::staff_error( 'save' ); }
        $lines = array();
        foreach ( (array) ( $_POST['lines'] ?? array() ) as $line ) {
            $label = sanitize_text_field( wp_unslash( $line['label'] ?? '' ) );
            if ( ! $label ) { continue; }
            $lines[] = array( 'source_type' => sanitize_key( $line['source_type'] ?? '' ), 'name' => preg_replace( '/\s*\(#\d+\)$/', '', $label ), 'product_id' => absint( $line['product_id'] ?? 0 ), 'quantity' => sanitize_text_field( wp_unslash( $line['quantity'] ?? '' ) ), 'unit' => sanitize_text_field( wp_unslash( $line['unit'] ?? 'u' ) ), 'unit_net' => sanitize_text_field( wp_unslash( $line['unit_price'] ?? '' ) ), 'configuration' => isset( $line['configuration'] ) && is_array( $line['configuration'] ) ? wp_unslash( $line['configuration'] ) : array(), 'finishes' => isset( $line['finishes'] ) && is_array( $line['finishes'] ) ? wp_unslash( $line['finishes'] ) : array(), 'details' => sanitize_text_field( wp_unslash( $line['details'] ?? '' ) ), 'notes' => sanitize_text_field( wp_unslash( $line['notes'] ?? '' ) ) );
        }
        $args = array( 'valid_until' => wp_unslash( $_POST['valid_until'] ?? '' ), 'deposit_percent' => wp_unslash( $_POST['deposit_percent'] ?? 50 ), 'notes_customer' => wp_unslash( $_POST['notes_customer'] ?? '' ), 'notes_internal' => wp_unslash( $_POST['notes_internal'] ?? '' ), 'expected_version' => absint( $_POST['expected_version'] ?? 0 ), 'billing_profile_id' => sanitize_text_field( wp_unslash( $_POST['billing_profile_id'] ?? 'default' ) ), 'delivery_address_id' => sanitize_text_field( wp_unslash( $_POST['delivery_address_id'] ?? '' ) ) );
        if ( is_wp_error( GE_WTP_Commercial_Quotes::build_snapshot( $lines, $args ) ) ) { self::staff_error( 'save' ); }
        $source_customer_id = absint( $_POST['source_customer_id'] ?? 0 );
        $source_customer = $source_customer_id ? get_userdata( $source_customer_id ) : false;
        if ( $source_customer_id && ( ! $source_customer || 0 !== strcasecmp( $source_customer->user_email, $email ) ) ) { self::staff_error( 'save' ); }
        $customer_id = $existing ? $existing['customer_id'] : ( $source_customer_id ?: absint( email_exists( $email ) ) );
        if ( $existing ) {
            $customer = get_userdata( $customer_id );
            if ( ! $customer || 0 !== strcasecmp( $customer->user_email, $email ) ) { self::staff_error( 'save' ); }
        }
        if ( ! $customer_id ) {
            $parts = preg_split( '/\s+/', trim( $name ), 2 );
            $customer_id = wp_insert_user( array( 'user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password( 32 ), 'role' => 'customer', 'first_name' => $parts[0] ?? $name, 'last_name' => $parts[1] ?? '', 'display_name' => $name ) );
            if ( is_wp_error( $customer_id ) ) { self::staff_error( 'save' ); }
            update_user_meta( $customer_id, '_ge_commercial_needs_invite', 'yes' );
        }
        $phone = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
        if ( strlen( $phone ) > 50 ) { self::staff_error( 'save' ); }
        $quote = $existing ? GE_WTP_Commercial_Quotes::revise( $quote_id, $lines, $args ) : GE_WTP_Commercial_Quotes::create_draft( $customer_id, $lines, $args );
        if ( is_wp_error( $quote ) ) { self::staff_error( 'save' ); }
        if ( $phone ) { update_user_meta( $customer_id, '_ge_whatsapp', $phone ); update_user_meta( $customer_id, 'billing_phone', $phone ); }
        if ( ! empty( $_FILES['ge_quote_file']['name'] ) ) {
            $file = GE_WTP_Commercial_Quote_Files::upload( $quote['id'], $_FILES['ge_quote_file'], sanitize_key( wp_unslash( $_POST['ge_quote_file_source'] ?? 'preliminary' ) ), get_current_user_id() );
            if ( is_wp_error( $file ) ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'quote_error' => 'file' ) ) ); exit; }
        }
        if ( 'send' === sanitize_key( wp_unslash( $_POST['quote_intent'] ?? 'draft' ) ) ) {
            $sent = GE_WTP_Commercial_Quotes::send( $quote['id'] );
            if ( is_wp_error( $sent ) ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'quote_error' => 'send' ) ) ); exit; }
        }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'] ) ) ); exit;
    }

    public static function handle_price() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_send_json_error( 'Acceso denegado.', 403 ); }
        check_ajax_referer( 'ge_commercial_quote_price', 'nonce' );
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $configuration = isset( $_POST['configuration'] ) && is_array( $_POST['configuration'] ) ? wp_unslash( $_POST['configuration'] ) : array();
        $quantity = absint( $_POST['quantity'] ?? 1 );
        $priced = GE_WTP_Commercial_Quote_Catalog::price( $product_id, $configuration, $quantity );
        if ( is_wp_error( $priced ) ) { wp_send_json_error( $priced->get_error_message(), 422 ); }
        wp_send_json_success( $priced );
    }

    public static function handle_send() {
        self::require_staff(); $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_send_' . $quote_id );
        $current = GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() );
        $quote = ! is_wp_error( $current ) && in_array( $current['status'], array( 'sent', 'viewed' ), true ) ? GE_WTP_Commercial_Quotes::resend( $quote_id ) : GE_WTP_Commercial_Quotes::send( $quote_id );
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

    public static function handle_convert() {
        self::require_staff();
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_convert_' . $quote_id );
        $args = array(
            'expected_version' => absint( $_POST['expected_version'] ?? 0 ),
            'confirmation_method' => sanitize_key( wp_unslash( $_POST['confirmation_method'] ?? 'staff' ) ),
            'payment_state' => sanitize_key( wp_unslash( $_POST['payment_state'] ?? 'unregistered' ) ),
            'amount_received' => sanitize_text_field( wp_unslash( $_POST['amount_received'] ?? '' ) ),
            'supplier_key' => sanitize_key( wp_unslash( $_POST['supplier_key'] ?? '' ) ),
            'production_notes' => sanitize_textarea_field( wp_unslash( $_POST['production_notes'] ?? '' ) ),
            'reason' => sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ),
        );
        $order = GE_WTP_Commercial_Checkout::convert_staff( $quote_id, $args, get_current_user_id() );
        if ( is_wp_error( $order ) ) {
            set_transient( 'ge_quote_convert_error_' . get_current_user_id() . '_' . $quote_id, $order->get_error_message(), 120 );
            wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id, 'quote_error' => 'convert' ) ) ); exit;
        }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ); exit;
    }

    private static function require_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
    }
    private static function staff_error( $code ) {
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'new' => 1, 'quote_error' => sanitize_key( $code ) ) ) ); exit;
    }
}
