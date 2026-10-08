<?php

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';

/** Staff and customer controls for the new commercial quote lifecycle. */
final class GE_WTP_Commercial_Quote_UI {
    public static function init() {
        add_action( 'admin_post_ge_commercial_quote_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_commercial_quote_send', array( __CLASS__, 'handle_send' ) );
        add_action( 'admin_post_ge_commercial_quote_accept', array( __CLASS__, 'handle_accept' ) );
        add_action( 'admin_post_ge_quote_approve', array( __CLASS__, 'handle_accept' ) );
        add_action( 'admin_post_ge_quote_retry_approval_notice', array( __CLASS__, 'handle_retry_approval_notice' ) );
        add_action( 'admin_post_ge_quote_save_selection', array( __CLASS__, 'handle_save_selection' ) );
        add_action( 'admin_post_ge_commercial_quote_convert', array( __CLASS__, 'handle_convert' ) );
        add_action( 'wp_ajax_ge_commercial_quote_totals', array( __CLASS__, 'handle_totals' ) );
        add_action( 'wp_ajax_ge_quote_customer_search', array( __CLASS__, 'customer_search' ) );
        add_action( 'wp_ajax_ge_commercial_quote_price', array( __CLASS__, 'handle_price' ) );
    }

    public static function render_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $quote_id = absint( $_GET['quote_id'] ?? 0 );
        $quote = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) : null;
        $legacy = self::selected_legacy_quote();
        if ( ! $quote && $legacy ) {
            $mapped_id = absint( get_user_meta( $legacy['user_id'], '_ge_commercial_legacy_' . hash( 'sha256', $legacy['key'] ), true ) );
            if ( $mapped_id ) {
                $mapped = GE_WTP_Commercial_Quotes::get( $mapped_id, get_current_user_id() );
                if ( ! is_wp_error( $mapped ) && (int) $mapped['customer_id'] === (int) $legacy['user_id'] ) { $quote = $mapped; $legacy = null; }
            }
        }
        $new = ! empty( $_GET['new'] );
        if ( is_wp_error( $quote ) ) { echo '<section class="ge-panel"><p>' . esc_html( $quote->get_error_message() ) . '</p></section>'; return; }
        $error = sanitize_key( wp_unslash( $_GET['quote_error'] ?? '' ) );
        $messages = array( 'ge_quote_busy' => 'El presupuesto se está procesando. Esperá unos segundos y volvé a abrirlo; si el pedido ya existe se mostrará su enlace.', 'convert' => 'No se pudo convertir el presupuesto. Revisá su estado antes de volver a intentar.', 'save' => 'No pudimos guardar el presupuesto. Revisá cliente, ítems e importes.', 'send' => 'No pudimos completar la publicación o el aviso. Revisá el mensaje de esta operación.', 'file' => 'El presupuesto se guardó, pero no se pudo adjuntar el archivo. Revisá el formato y tamaño.' );
        echo '<div class="ge-staff-heading"><div><span>Comercial</span><h1>' . esc_html( $quote ? $quote['number'] : ( $legacy ? ( $legacy['reference'] ?: 'Presupuesto anterior' ) : ( $new ? 'Nuevo presupuesto' : 'Presupuestos' ) ) ) . '</h1><p>' . esc_html( $legacy ? 'Registro histórico conservado en la ficha del cliente.' : ( $quote ? 'Propuesta comercial y actividad.' : ( $new ? 'Prepará la propuesta y agregá archivos si ya están disponibles.' : 'Consultá las propuestas actuales y los registros anteriores en un mismo lugar.' ) ) ) . '</p></div>';
        if ( ! $quote && ! $legacy ) { echo '<a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', $new ? array() : array( 'new' => 1 ) ) ) . '">' . esc_html( $new ? 'Ver presupuestos' : '＋ Nuevo presupuesto' ) . '</a>'; }
        echo '</div>';
        if ( 'convert' === $error && $quote_id ) { $detail = get_transient( 'ge_quote_convert_error_' . get_current_user_id() . '_' . $quote_id ); if ( $detail ) { $messages['convert'] = $detail; } }
        if ( 'send' === $error && $quote_id ) { $detail = get_transient( 'ge_quote_send_error_' . get_current_user_id() . '_' . $quote_id ); if ( $detail ) { $messages['send'] = $detail; } }
        if ( ! empty( $_GET['quote_saved'] ) ) { echo '<div class="ge-production-notice" role="status">Presupuesto guardado. La ficha del cliente está vinculada.</div>'; }
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
        wp_enqueue_style( 'ge-quote-steps', GE_WTP_PLUGIN_URL . 'assets/css/quote-steps.css', array(), (string) filemtime( GE_WTP_PLUGIN_DIR . 'assets/css/quote-steps.css' ) );
        wp_enqueue_script( 'ge-quote-steps', GE_WTP_PLUGIN_URL . 'assets/js/quote-steps.js', array( 'ge-commercial-quotes' ), (string) filemtime( GE_WTP_PLUGIN_DIR . 'assets/js/quote-steps.js' ), true );
        $script = GE_WTP_PLUGIN_DIR . 'assets/js/commercial-quotes.js';
        wp_enqueue_script( 'ge-commercial-quotes', GE_WTP_PLUGIN_URL . 'assets/js/commercial-quotes.js', array(), is_file( $script ) ? (string) filemtime( $script ) : GE_WTP_VERSION, true );
        wp_enqueue_script( 'ge-quote-tax-totals', GE_WTP_PLUGIN_URL . 'assets/js/quote-tax-totals.js', array( 'ge-commercial-quotes' ), (string) filemtime( GE_WTP_PLUGIN_DIR . 'assets/js/quote-tax-totals.js' ), true );
        wp_localize_script( 'ge-commercial-quotes', 'geCommercialQuotes', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ge_commercial_quote_price' ), 'customerNonce' => wp_create_nonce( 'ge_quote_customer_search' ) ) );
        GE_WTP_Quote_Artwork_V2::enqueue($quote['id'] ?? 0);
        $catalog = array();
        foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) as $product ) {
            $label = $product->get_name() . ' (#' . $product->get_id() . ')';
            $catalog[ $label ] = array_merge( array( 'id' => $product->get_id() ), GE_WTP_Commercial_Quote_Catalog::describe( $product ) );
        }
        echo '<form class="ge-manual-order ge-commercial-quote ge-quote-steps" method="post" novalidate enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_commercial_quote_save">';
        wp_nonce_field( 'ge_commercial_quote_save' );
        echo '<input type="hidden" name="artwork_session" value="'.esc_attr(wp_generate_uuid4()).'"><input type="hidden" name="artwork_v2" value="1">';
        if ( $customer && ! $editing ) { echo '<input type="hidden" name="source_customer_id" value="' . esc_attr( $customer->ID ) . '">'; }
        if ( $editing ) { echo '<input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( $quote['version'] ) . '">'; }
        if ( $editing ) { echo '<input type="hidden" name="expected_hash" value="' . esc_attr( GE_WTP_Quote_Billing_Control::hash( $snapshot ) ) . '">'; }
        echo '<div class="ge-quote-progress" data-ge-progress aria-label="Progreso del presupuesto"></div><div class="ge-quote-errors" data-ge-form-errors role="alert" tabindex="-1" hidden></div><p class="ge-quote-save-state" data-ge-save-state role="status">Completá nombre y email para guardar un borrador. El resto puede quedar para después.</p>';
        $phone = $customer ? ( get_user_meta( $customer->ID, '_ge_whatsapp', true ) ?: get_user_meta( $customer->ID, 'billing_phone', true ) ) : '';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>01 · Cliente</span><h2>Datos de contacto</h2></div></div><div class="ge-quote-customer-search"><label>Buscar un cliente registrado<input type="search" data-ge-customer-search autocomplete="off" placeholder="Nombre o email" aria-describedby="ge-customer-search-help"></label><p id="ge-customer-search-help">Elegí una ficha existente o completá nombre y email para crearla al guardar el borrador.</p><div data-ge-customer-results role="group" aria-label="Clientes encontrados"></div></div><div class="ge-manual-contact-grid"><label>Nombre o razón social<input name="customer_name" required maxlength="160" value="' . esc_attr( $customer ? $customer->display_name : '' ) . '"' . ( $editing ? ' readonly' : '' ) . '></label><label>Email<input type="email" name="customer_email" required maxlength="190" value="' . esc_attr( $customer ? $customer->user_email : '' ) . '"' . ( $editing ? ' readonly' : '' ) . '></label><label>WhatsApp / teléfono<input type="tel" name="customer_phone" maxlength="50" autocomplete="tel" value="' . esc_attr( $phone ) . '" placeholder="+54 9 11..."></label></div><p class="ge-manual-help">' . esc_html( $editing ? 'Para cambiar de cliente, creá otro presupuesto.' : 'Si el email ya existe, se usa su ficha. Si es nuevo, se crea una ficha y se prepara el acceso al portal.' ) . '</p></section>';
        self::manual_tax_card( $customer ? $customer->ID : 0, $snapshot );
        if ( $editing ) { echo '<details class="ge-quote-advanced"><summary>Actualizar datos fiscales de esta versión</summary><section class="ge-production-card"><p>La identidad fiscal guardada se conserva al editar ítems. Usá una actualización explícita para tomar datos nuevos.</p><label><input type="checkbox" name="billing_refresh" value="1">Actualizar este presupuesto con los datos nuevos</label>'; if ( GE_WTP_Billing_Issuers::can_manage( get_current_user_id() ) ) { echo '<label><input type="checkbox" name="billing_override" value="1">Cambiar igualmente (datos verificados)</label>'; } echo '<label>Motivo de cambio fiscal<input name="billing_reason" maxlength="500"></label></section></details>'; }
        GE_WTP_Billing_Issuers::render_picker( $snapshot );
        $selected_profile = $snapshot['billing_profile_id'] ?? ( $customer && count( GE_WTP_Customer_Branches::profiles( $customer->ID ) ) === 1 ? GE_WTP_Customer_Branches::profiles( $customer->ID )[0]['id'] : '' );
        $selected_delivery = $snapshot['delivery_address_id'] ?? '';
        echo '<section class="ge-production-card" data-ge-branch-picker data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'ge_customer_branch_options' ) ) . '"><div class="ge-production-section-head"><div><span>Facturación y entrega</span><h2>Receptor fiscal y dirección de entrega</h2></div></div><div class="ge-manual-contact-grid"><label>Facturar a<select name="billing_profile_id" data-ge-billing-profile><option value="">Elegí receptor</option>';
        $profiles = $customer ? GE_WTP_Customer_Branches::profiles( $customer->ID ) : array( array( 'id' => 'default', 'label' => 'Perfil principal', 'cuit' => '' ) );
        foreach ( $profiles as $profile ) { echo '<option value="' . esc_attr( $profile['id'] ) . '"' . selected( $selected_profile, $profile['id'], false ) . '>' . esc_html( $profile['label'] . ( ! empty( $profile['cuit'] ) ? ' · CUIT ' . $profile['cuit'] : '' ) ) . '</option>'; }
        echo '</select></label><label>Entregar en<select name="delivery_address_id" data-ge-delivery-address><option value="">A coordinar</option>';
        if ( $customer ) { foreach ( GE_WTP_Customers::addresses( $customer->ID ) as $index => $address ) { $id = (string) ( $address['id'] ?? $index ); echo '<option value="' . esc_attr( $id ) . '"' . selected( $selected_delivery, $id, false ) . '>' . esc_html( ( $address['label'] ?: 'Destino' ) . ' · ' . $address['street'] ) . '</option>'; } }
        echo '</select></label></div><p class="ge-manual-help">Los datos elegidos quedarán fijados en la versión enviada y en el pedido.</p></section>';
        echo '<script type="application/json" data-ge-branch-selected>' . wp_json_encode( array( 'profile' => $selected_profile, 'delivery' => $selected_delivery ) ) . '</script>';
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>02 · Ítems</span><h2>Productos y servicios</h2></div></div><p>Podés combinar productos del catálogo con trabajos personalizados en un mismo presupuesto.</p><div class="ge-quote-add-actions"><button class="ge-manual-add-line" type="button" data-ge-add-line="catalog_product">＋ Agregar producto del catálogo</button><button class="ge-manual-add-line" type="button" data-ge-add-line="custom">＋ Agregar ítem personalizado</button></div><div class="ge-manual-lines" data-ge-lines>';
        if ( $editing && ! empty( $snapshot['items'] ) ) { foreach ( $snapshot['items'] as $index => $line ) { $line['line_uuid'] = GE_WTP_Quote_Artwork_V2::line_id($quote['id'],$index,$line); $line['_artwork_files'] = array_values(array_filter(GE_WTP_Commercial_Quote_Files::all($quote['id']),function($file)use($line){return ($file['quote_item_id']??'')===$line['line_uuid'] && 'detached'!==($file['association_status']??'');})); $line['_quote_id']=$quote['id']; self::line_markup( $index, $line ); } }
        elseif ( empty( $snapshot['draft_lines'] ) ) { self::line_markup( 0 ); }
        foreach ( (array) ( $snapshot['draft_lines'] ?? array() ) as $index => $line ) { self::line_markup( count( $snapshot['items'] ?? array() ) + $index, $line ); }
        echo '</div><datalist id="ge-manual-products">';
        foreach ( array_keys( $catalog ) as $label ) { echo '<option value="' . esc_attr( $label ) . '"></option>'; }
        echo '</datalist><script type="application/json" id="ge-manual-catalog">' . wp_json_encode( $catalog ) . '</script><template id="ge-manual-line-template">';
        self::line_markup( '__INDEX__' );
        echo '</template><p class="ge-manual-help">El IVA se calcula según el emisor elegido en Emisor / Facturación. Los precios de nuevos presupuestos se ingresan antes de IVA, cuando corresponde.</p><div class="ge-live-tax-summary" data-ge-live-totals role="status" aria-live="polite"><p>Seleccioná cliente e ítems para calcular.</p></div></section>';
        // Keep existing commercial values when revising; new quotes add tax only when the issuer requires it.
        $quote_discount = array();
        foreach ( (array) ( $snapshot['discounts'] ?? array() ) as $discount ) { if ( 'quote' === $discount['discount_scope'] ) { $quote_discount = $discount; } }
        echo '<input type="hidden" name="quote_vat_mode" value="' . esc_attr( ( $snapshot['quote_vat_mode'] ?? '' ) ?: 'added' ) . '">';
        foreach ( array( 'discount_type' => 'percent', 'discount_value' => '0', 'discount_reason' => '' ) as $key => $default ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $quote_discount[$key] ?? $default ) . '">'; }
        echo '<section class="ge-production-card"><div class="ge-production-section-head"><div><span>03 · Condiciones</span><h2>Validez y pago</h2></div></div><div class="ge-manual-plan-grid"><label>Válido hasta<input type="date" name="valid_until" min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $snapshot['valid_until'] ?? wp_date( 'Y-m-d', strtotime( '+30 days' ) ) ) . '"><small>30 días desde hoy por defecto; podés cambiarlo.</small></label><label class="ge-quote-switch"><input type="checkbox" name="deposit_enabled" value="1" data-ge-deposit-toggle' . checked( $editing ? ( ! array_key_exists( 'deposit_enabled', $snapshot ) || ! empty( $snapshot['deposit_enabled'] ) ) : false, true, false ) . '>Ofrecer pago con seña</label><label data-ge-deposit-field>Seña (%)<input type="number" name="deposit_percent" min="1" max="100" value="' . esc_attr( $snapshot['deposit_percent'] ?? get_option( 'ge_commercial_deposit_percent', 50 ) ) . '"></label><label class="is-wide">Notas para el cliente<textarea name="notes_customer" rows="3">' . esc_textarea( $snapshot['notes_customer'] ?? '' ) . '</textarea></label><label class="is-wide">Notas internas · privadas, no se incluyen en el PDF<textarea name="notes_internal" rows="3">' . esc_textarea( $snapshot['notes_internal'] ?? '' ) . '</textarea></label></div></section>';
        echo '<section class="ge-production-card"><h2>Archivos generales (opcionales)</h2><p>Los archivos de cada producto se agregan en su ítem. Aquí quedan los archivos sin asignación, incluidos los anteriores.</p>';
        $general = $editing ? array_values(array_filter(GE_WTP_Commercial_Quote_Files::all($quote['id']),function($file){return empty($file['quote_item_id']) && 'detached'!==($file['association_status']??'');})) : array();
        GE_WTP_Quote_Artwork_V2::block('general','',$general,$quote['id']??0);
        echo '</section>';
        echo '<div class="ge-manual-summary"><div class="ge-quote-wizard-navigation"><button class="ge-staff-button is-secondary" type="button" data-ge-step-back hidden>Atrás</button><button class="ge-staff-button is-secondary" type="button" data-ge-step-skip hidden>Omitir por ahora</button><button class="ge-staff-button" type="button" data-ge-step-next hidden>Continuar</button></div><div class="ge-quote-actions"><button class="ge-staff-button is-secondary" type="submit" name="quote_intent" value="draft">Guardar borrador</button><button class="ge-staff-button" type="submit" name="quote_intent" value="send">Publicar y enviar al cliente</button></div><p>Guardar borrador conserva lo que completaste y no envía avisos. Publicar y enviar confirma tu aprobación comercial; la aceptación del cliente y del diseño se revisan por separado.</p></div></form>';
    }

    private static function line_markup( $index, $line = array() ) {
        $product_id = absint( $line['product_id'] ?? 0 );
        $source_type = $line['source_type'] ?? ( $product_id || ! $line ? 'catalog_product' : 'custom' );
        $label = (string) ( $line['name'] ?? '' ) . ( $product_id ? ' (#' . $product_id . ')' : '' );
        $price = isset( $line['unit_net_cents'] ) ? GE_WTP_Quote_Balance::decimal( (int) ( $line['entered_unit_cents'] ?? $line['unit_net_cents'] ) ) : ( $line['unit_net'] ?? '' );
        ob_start();
        echo '<details class="ge-product-fold"><summary>Opciones para el cliente (opcional)</summary><p>Usá estas opciones si el cliente debe elegir entre productos o sumar un adicional.</p><div class="ge-quote-line-choice"><label>Tipo de ítem<select name="lines[' . esc_attr( $index ) . '][selection_type]">';
        foreach ( array( 'required' => 'Incluido / acumulable', 'optional' => 'Adicional opcional', 'alternative' => 'Alternativa: elegir una del grupo' ) as $value => $text ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $line['selection_type'] ?? 'required', $value, false ) . '>' . esc_html( $text ) . '</option>'; }
        echo '</select></label><label>Grupo de alternativas<input name="lines[' . esc_attr( $index ) . '][selection_group]" value="' . esc_attr( $line['selection_group'] ?? '' ) . '" maxlength="60" pattern="[a-zA-Z0-9_-]+" placeholder="Ej.: carpeta"><small>Mismo grupo = se elige una sola variante. Usá letras, números o guiones.</small></label><label><input type="checkbox" name="lines[' . esc_attr( $index ) . '][selection_recommended]" value="1"' . checked( ! empty( $line['selection_recommended'] ), true, false ) . '> Opción recomendada</label></div>';
        echo '<details class="ge-choice-editor"><summary>Agrupar variantes por modelo (avanzado)</summary><p>Estos datos vinculan variantes en el selector del cliente. La imagen se referencia por su archivo privado; no reemplaza la carga de archivos de abajo.</p>';
        foreach ( array( 'model_key' => 'Identificador del modelo', 'model_label' => 'Nombre del modelo', 'finish_key' => 'Identificador de terminación', 'finish_label' => 'Terminación', 'preview_file_id' => 'ID de la miniatura privada' ) as $key => $field_label ) { echo '<label>' . esc_html( $field_label ) . '<input name="lines[' . esc_attr( $index ) . '][choice_facets][' . esc_attr( $key ) . ']" value="' . esc_attr( $line['choice_facets'][$key] ?? '' ) . '"></label>'; }
        echo '<label>Papeles disponibles (uno por línea)<textarea name="lines[' . esc_attr( $index ) . '][choice_papers]">' . esc_textarea( implode( "\n", $line['choice_facets']['papers'] ?? array() ) ) . '</textarea></label></details></details>';
        $choice_markup = ob_get_clean();
        $finishes = GE_WTP_Workflow::finishing_catalog();
        echo '<div class="ge-manual-line" data-ge-line data-ge-index="' . esc_attr( $index ) . '" data-ge-source="' . esc_attr( $source_type ) . '" data-ge-saved-config="' . esc_attr( wp_json_encode( $line['configuration'] ?? array() ) ) . '"><input type="hidden" name="lines[' . esc_attr( $index ) . '][source_type]" value="' . esc_attr( $source_type ) . '"><label class="is-product"><span data-ge-title-label>Producto del catálogo</span><input type="search" name="lines[' . esc_attr( $index ) . '][label]" list="ge-manual-products" required maxlength="200" value="' . esc_attr( $label ) . '" placeholder="Buscar producto"><input type="hidden" name="lines[' . esc_attr( $index ) . '][product_id]" value="' . esc_attr( $product_id ) . '"></label><label>Cantidad<input type="number" name="lines[' . esc_attr( $index ) . '][quantity]" required min="1" step="1" value="' . esc_attr( $line['quantity'] ?? 1 ) . '"></label><label class="ge-quote-unit">Unidad<select name="lines[' . esc_attr( $index ) . '][unit]">';
        foreach ( array( 'u' => 'u', 'm²' => 'm²', 'ml' => 'ml', 'lote' => 'lote', 'servicio' => 'servicio' ) as $value => $unit_label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $line['unit'] ?? 'u', $value, false ) . '>' . esc_html( $unit_label ) . '</option>'; }
        echo '</select></label><label>Precio unitario · ARS<input type="number" name="lines[' . esc_attr( $index ) . '][unit_price]" min="0" step="0.01" value="' . esc_attr( $price ) . '" required><small data-ge-price-hint></small></label><label class="is-detail">Descripción<input type="text" name="lines[' . esc_attr( $index ) . '][details]" maxlength="500" value="' . esc_attr( $line['details'] ?? '' ) . '" placeholder="Material, medidas o alcance"></label><details class="ge-product-fold"><summary>Notas de este producto (opcional)</summary><label>Notas<input type="text" name="lines[' . esc_attr( $index ) . '][notes]" maxlength="500" value="' . esc_attr( $line['notes'] ?? '' ) . '"></label></details><details class="ge-product-fold ge-product-tools"><summary>Más acciones para este producto</summary><p>Copiar agrega otro producto igual para cambiar sus datos. Quitar lo saca de este presupuesto.</p><div class="ge-quote-line-actions"><button type="button" data-ge-duplicate-line>Copiar producto</button><button type="button" data-ge-remove-line>Quitar del presupuesto</button></div><p class="ge-product-cost-help">El cálculo de costo permite estimar un precio para productos personalizados. Su costo interno no se muestra al cliente.</p></details><output data-ge-line-subtotal>Subtotal ARS 0</output><div class="ge-quote-config" data-ge-config-fields hidden></div><details class="ge-product-fold"><summary>Terminaciones (opcional)' . ( ! empty( $line['finishes'] ) ? ' · ' . count( (array) $line['finishes'] ) . ' seleccionadas' : '' ) . '</summary><fieldset class="ge-quote-finishes"><legend>Elegí una o varias terminaciones</legend>';
        foreach ( $finishes as $key => $finish ) { echo '<label><input type="checkbox" name="lines[' . esc_attr( $index ) . '][finishes][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, (array) ( $line['finishes'] ?? array() ), true ), true, false ) . '><span>' . esc_html( $finish ) . '</span></label>'; }
        $discount = $line['discount'] ?? array();
        echo '</fieldset></details><details class="ge-product-fold"><summary>Descuento de este producto (opcional)' . ( ! empty( $discount['discount_value'] ) ? ' · aplicado' : '' ) . '</summary><label>Tipo<select name="lines[' . esc_attr( $index ) . '][discount_type]">';
        foreach ( array( 'percent' => 'Porcentaje', 'fixed' => 'Importe ARS' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $discount['discount_type'] ?? 'percent', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label><label>Valor<input type="number" min="0" step="0.01" name="lines[' . esc_attr( $index ) . '][discount_value]" value="' . esc_attr( $discount['discount_value'] ?? '0' ) . '"></label><label>Motivo<input name="lines[' . esc_attr( $index ) . '][discount_reason]" value="' . esc_attr( $discount['discount_reason'] ?? '' ) . '"></label></details>';

        echo $choice_markup;
        echo '<input type="hidden" data-ge-line-uuid name="lines['.esc_attr($index).'][line_uuid]" value="'.esc_attr($line['line_uuid']??'').'">';
        GE_WTP_Quote_Artwork_V2::block($index,$line['line_uuid']??'', $line['_artwork_files']??array(), $line['_quote_id']??0);
        echo '</div>';
    }

    private static function render_staff_detail( $quote ) {
        $proposal = $quote; $selection_ready = ! GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] );
        if ( ! $selection_ready && empty( $quote['snapshot']['customer_selection'] ) ) {
            $preview = GE_WTP_Quote_Selection::preview_request( $quote );
            if ( is_wp_error( $preview ) ) { echo '<p role="alert">' . esc_html( $preview->get_error_message() ) . '</p>'; }
            elseif ( ! empty( $preview['snapshot']['customer_selection'] ) ) { $quote = $preview; $selection_ready = true; }
        } else { $selection_ready = true; }
        $pdf_url = GE_WTP_Commercial_Quote_PDF::url( $quote['id'] );
        if ( ! empty( $quote['snapshot']['customer_selection'] ) ) { $pdf_url = add_query_arg( GE_WTP_Quote_Selection::selection_args( $quote ), $pdf_url ); }

        $customer = get_userdata( $quote['customer_id'] );
        $customer_heading = $customer ? '<a class="ge-qbc-name" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $customer->ID ) ) ) . '">' . esc_html( $customer->display_name ) . '</a>' : '<span>Cliente no disponible</span>';
        $status_labels = array( 'draft' => 'Borrador', 'sent' => 'Publicado', 'viewed' => 'Visto', 'accepted' => 'Aprobado', 'rejected' => 'Rechazado', 'expired' => 'Vencido', 'converted' => 'Convertido en pedido', 'cancelled' => 'Cancelado' );
        echo '<section class="ge-production-card ge-quote-view"><header class="ge-quote-view-head"><div><span class="ge-quote-kicker">Presupuesto ' . esc_html( $quote['number'] ) . ' · Versión ' . esc_html( $quote['version'] ) . '</span><h2>' . $customer_heading . '</h2><small>' . esc_html( get_the_date( 'd/m/Y', $quote['id'] ) ) . '</small></div><div class="ge-quote-header-total"><span>Total de la propuesta</span><strong>' . esc_html( GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] ) ? 'A definir según elección' : ( isset( $quote['snapshot']['total_cents'] ) ? self::money( $quote['snapshot']['total_cents'] ) : 'A confirmar' ) ) . '</strong><span class="ge-quote-status is-' . esc_attr( $quote['status'] ) . '">' . esc_html( $status_labels[ $quote['status'] ] ?? ucfirst( $quote['status'] ) ) . '</span></div></header>';
        echo '<div class="ge-quote-context"><div><span>Datos del contacto</span><strong>' . esc_html( $customer ? $customer->display_name : 'Ficha no disponible' ) . '</strong>' . ( $customer ? '<small>' . esc_html( $customer->user_email ) . '</small>' : '' ) . '</div><div><span>Validez</span><strong>' . esc_html( ! empty( $quote['snapshot']['valid_until'] ) ? wp_date( 'd/m/Y', ( new DateTimeImmutable( $quote['snapshot']['valid_until'], wp_timezone() ) )->getTimestamp() ) : 'Sin fecha' ) . '</strong><small>Fecha límite para aceptar</small></div><div><span>Pago</span><strong>' . esc_html( array_key_exists( 'deposit_enabled', $quote['snapshot'] ) && ! $quote['snapshot']['deposit_enabled'] ? 'Pago total' : 'Seña ' . ( $quote['snapshot']['deposit_percent'] ?? 50 ) . '% o total' ) . '</strong><small>El cliente elige al aceptar</small></div></div>';
        echo '<nav id="ge-commercial-next" class="ge-quote-view-actions" aria-label="Acciones del presupuesto">';
        echo '<button type="button" class="ge-staff-button is-secondary" data-ge-quote-preview="' . absint($quote['id']) . '">Vista previa del correo</button>';
        if ( $selection_ready ) { echo '<a class="ge-staff-button is-secondary ge-quote-pdf-download" href="' . esc_url( $pdf_url ) . '">Descargar PDF</a>'; }
        else { echo '<a class="ge-staff-button is-secondary ge-quote-pdf-download" href="' . esc_url( GE_WTP_Commercial_Quote_PDF::url( $proposal['id'] ) ) . '">Descargar PDF comparativo</a><a class="ge-staff-button is-secondary" href="#ge-quote-configurator">Elegir configuración</a>'; }
        if ( ! $quote['converted_order_id'] && ( ! GE_WTP_Quote_Selection::has_choices( $proposal['snapshot'] ) || in_array( $proposal['status'], array( 'accepted', 'converted' ), true ) ) ) { echo '<button class="ge-staff-button" type="button" onclick="document.getElementById(\'ge-quote-convert\').showModal()">Crear pedido</button>'; }
        elseif ( $quote['converted_order_id'] ) { echo '<a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $quote['converted_order_id'] ) ) ) . '">Abrir pedido #' . esc_html( wc_get_order( $quote['converted_order_id'] ) ? wc_get_order( $quote['converted_order_id'] )->get_order_number() : $quote['converted_order_id'] ) . '</a>'; }
        if ( in_array( $quote['status'], array( 'draft', 'sent', 'viewed' ), true ) && ! get_post_meta( $quote['id'], '_ge_commercial_initial_payment_order', true ) ) { echo '<a class="ge-staff-button is-secondary" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'edit' => 1 ) ) ) . '">Editar</a>'; }
        $notice = (array)get_post_meta($quote['id'],'_ge_commercial_notice',true);
        $notice_status = (int)($notice['version'] ?? 0) === (int)$quote['version'] ? ($notice['status'] ?? '') : '';
        if ( in_array($quote['status'],array('sent','viewed'),true) ) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_commercial_quote_send"><input type="hidden" name="quote_id" value="'.absint($quote['id']).'"><input type="hidden" name="notice_intent" value="resend"><input type="hidden" name="expected_version" value="'.absint($quote['version']).'"><input type="hidden" name="notice_request_id" value="'.esc_attr(wp_generate_uuid4()).'">';
            wp_nonce_field('ge_commercial_quote_send_'.$quote['id']);
            echo '<button class="ge-staff-button is-secondary" type="submit">'.esc_html('failed' === $notice_status ? 'Reintentar aviso' : 'Volver a enviar aviso').'</button></form>';
        }
        echo '<a class="ge-staff-button is-secondary" href="'.esc_url(GE_WTP_Portal::preview_url($quote['customer_id'],'presupuestos',array('presupuesto'=>$quote['id']))).'">Abrir portal cliente</a>';
        if ( 'draft' === $quote['status'] ) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_commercial_quote_send"><input type="hidden" name="quote_id" value="'.absint($quote['id']).'"><input type="hidden" name="notice_intent" value="publish"><input type="hidden" name="expected_version" value="'.absint($quote['version']).'">';
            wp_nonce_field('ge_commercial_quote_send_'.$quote['id']);
            echo '<button class="ge-staff-button" type="submit">Publicar y enviar al cliente</button></form>';
        }
        echo '</nav>';
        if ( 'pending' === ( $quote['snapshot']['fiscal_status'] ?? '' ) || ! empty( $quote['snapshot']['fiscal_blockers'] ) ) { echo '<p class="ge-quote-fiscal-alert" role="note">Facturación pendiente de revisión. Esto no bloquea publicar el presupuesto. Antes de facturar o cobrar, revisá <a href="#ge-quote-fiscal">receptor, emisor e importes</a>.</p>'; }
        if ( in_array($quote['status'],array('sent','viewed'),true) ) {
            $notice_label = array('sent'=>'Publicado · aviso enviado al sistema de correo.','failed'=>'Publicado; aviso no enviado. El cliente ya puede verlo en el portal.','unknown'=>'Publicado · resultado del aviso sin confirmar. Revisá Notificaciones antes de reintentar.','delivering'=>'Publicado · aviso en procesamiento.');
            if (isset($notice_label[$notice_status])) { echo '<p class="ge-production-notice" role="status">'.esc_html($notice_label[$notice_status]).'</p>'; }
        }
        if ( GE_WTP_Quote_Selection::has_choices( $proposal['snapshot'] ) && empty( $proposal['snapshot']['customer_selection'] ) ) { GE_WTP_Quote_Selection::render_choices( $proposal, $quote['snapshot']['customer_selection']['line_ids'] ?? array(), $quote['snapshot']['customer_selection']['configurations'] ?? array(), true ); }
        if ( $selection_ready ) { self::render_snapshot( $quote['snapshot'], false, true ); }
        echo '<details class="ge-quote-disclosure" id="ge-quote-fiscal"><summary>Facturación y revisión fiscal</summary>';
        GE_WTP_Quote_Billing_Control::render( $quote );
        $tax_label = GE_WTP_Customer_Tax_UI::decision_label( $quote['snapshot'] );
        if ( $tax_label ) { echo '<p class="ge-quote-fiscal-decision">' . esc_html( $tax_label ) . '</p>'; }
        GE_WTP_Customer_Tax_UI::render_warnings( $quote['snapshot']['customer_tax_decision'] ?? array() );
        echo '</details>';
        echo '<details class="ge-quote-disclosure ge-quote-payments"><summary>Pagos y comprobantes</summary>';
        $payment = GE_WTP_Commercial_Checkout::payment_status( $quote['id'], get_current_user_id() );
        if ( ! is_wp_error( $payment ) ) {
            $total = $selection_ready ? (int) ( $quote['snapshot']['total_cents'] ?? $payment['final_total_cents'] ?? 0 ) : 0;
            $paid = (int) $payment['amount_paid_cents'];
            echo '<div class="ge-quote-context ge-quote-payment-summary"><div><span>Seña disponible</span><strong>' . esc_html( array_key_exists( 'deposit_enabled', $quote['snapshot'] ) && ! $quote['snapshot']['deposit_enabled'] ? 'Desactivada' : ( $quote['snapshot']['deposit_percent'] ?? 50 ) . '%' ) . '</strong></div><div><span>Estado de pago</span><strong>' . esc_html( array( 'pending' => 'Pendiente', 'partial' => 'Seña / parcial', 'paid' => 'Pagado', 'failed' => 'Fallido' )[ $payment['payment_status'] ] ?? 'Pendiente' ) . '</strong></div><div><span>Abonado</span><strong>' . esc_html( self::money( $paid ) ) . '</strong></div><div><span>Saldo</span><strong>' . esc_html( $selection_ready ? self::money( max( 0, $total - $paid ) ) : 'A definir según elección' ) . '</strong></div></div>';
        }
        self::render_receipts( $quote, false );
        echo '</details>';
        $axes = GE_WTP_Commercial_Quotes::state_axes( $quote );
        $axis_labels = array( 'pending' => 'Pendiente', 'partial' => 'Seña / parcial', 'paid' => 'Pagado', 'failed' => 'Fallido', 'none' => 'Sin archivo', 'received' => 'Recibido', 'analyzed' => 'Analizado', 'final' => 'Archivo final', 'approval_pending' => 'Aprobación pendiente', 'approved' => 'Aprobado', 'not_created' => 'Sin pedido', 'created' => 'Pedido creado', 'ready' => 'Listo', 'in_production' => 'En producción', 'ready_for_delivery' => 'Listo para entregar', 'delivered' => 'Entregado' );
        echo '<div class="ge-quote-axes" aria-label="Estados separados"><span>Comercial: ' . esc_html( $status_labels[ $axes['commercial'] ] ?? $axes['commercial'] ) . '</span><span>Pago: ' . esc_html( $axis_labels[ $axes['payment'] ] ?? $axes['payment'] ) . '</span><span>Archivos y arte: ' . esc_html( $axis_labels[ $axes['artwork'] ] ?? $axes['artwork'] ) . '</span><span>Producción: ' . esc_html( $axis_labels[ $axes['production'] ] ?? $axes['production'] ) . '</span></div>';
        self::render_files( $quote, false );
        self::render_save_selection( $proposal, GE_WTP_Quote_Selection::has_choices( $proposal['snapshot'] ) && empty( $proposal['snapshot']['customer_selection'] ), true, false );
        echo '</section>';
        if ( ! $quote['converted_order_id'] && ( ! GE_WTP_Quote_Selection::has_choices( $proposal['snapshot'] ) || in_array( $proposal['status'], array( 'accepted', 'converted' ), true ) ) ) { self::render_conversion_dialog( $quote, $customer ); }
        self::render_events( $quote );
        if ( class_exists( 'GE_WTP_Commercial_Checkout' ) && ( ! GE_WTP_Quote_Selection::has_choices( $proposal['snapshot'] ) || in_array( $proposal['status'], array( 'accepted', 'converted' ), true ) ) ) { GE_WTP_Commercial_Checkout::render_staff_payment( $quote ); }
    }

    private static function render_files( $quote, $customer_view ) {
        GE_WTP_Quote_Artwork_V2::enqueue($quote['id']);
        $files = array_values( array_filter( GE_WTP_Commercial_Quote_Files::all( $quote['id'] ), function( $file ) { return empty( $file['choice_preview'] ); } ) );
        echo '<section class="' . esc_attr( $customer_view ? 'ge-panel' : 'ge-production-card' ) . ' ge-quote-files" id="ge-quote-files-' . esc_attr( $quote['id'] ) . '"><h3>Archivos / Arte</h3>';
        if ( ! $files ) { echo '<p>Sin archivos. Podés continuar con la cotización o el pedido.</p>'; }
        else {
            echo '<div class="ge-quote-file-list">';
            foreach ( $files as $file ) {
                if(GE_WTP_External_Artwork::is_link($file)){GE_WTP_External_Artwork::render($file,$quote['id'],0,'',!$customer_view);continue;}
                echo '<div class="ge-quote-file-group">';
                GE_WTP_File_Analysis::render( $file, ! $customer_view );
                $analysis = is_array( $file['analysis'] ?? null ) ? $file['analysis'] : array();
                $association_label = 'Archivo general';
                foreach ($quote['snapshot']['items'] as $line_index=>$line) { if (($file['quote_item_id']??'')===GE_WTP_Quote_Artwork_V2::line_id($quote['id'],$line_index,$line)) { $association_label=$line['name']; break; } }
                if ('detached'===($file['association_status']??'')) { $association_label='Histórico · sin vínculo vigente'; }
                $details = array( $association_label, size_format( (int) ( $file['size'] ?? 0 ) ), 'final' === ( $file['source_type'] ?? '' ) ? 'Archivo final' : 'Preliminar' );
                if ( ! empty( $analysis['pages'] ) ) { $details[] = absint( $analysis['pages'] ) . ' pág.'; }
                if ( ! empty( $analysis['width'] ) && ! empty( $analysis['height'] ) ) { $details[] = $analysis['width'] . ' × ' . $analysis['height'] . ' ' . ( $analysis['unit'] ?? '' ); }
                $details[] = 'Color y resolución: sin verificar';
                echo '<article class="ge-quote-file">';
                if ( in_array( $file['mime'] ?? '', array( 'image/jpeg', 'image/png' ), true ) ) { echo '<img class="ge-quote-file-thumb" loading="lazy" src="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'], true ) ) . '" alt="Vista previa de ' . esc_attr( $file['name'] ) . '">'; }
                echo '<div><strong>' . esc_html( $file['name'] ) . '</strong><small>' . esc_html( implode( ' · ', $details ) ) . '</small>';
                if ( ! empty( $analysis['warning'] ) ) { echo '<small class="ge-quote-file-warning">' . esc_html( $analysis['warning'] ) . '</small>'; }
                echo '</div><div class="ge-quote-file-links"><a href="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'], true ) ) . '" target="_blank" rel="noopener">Vista previa</a><a href="' . esc_url( GE_WTP_Commercial_Quote_Files::download_url( $quote['id'], $file['id'] ) ) . '">Descargar</a></div></article></div>';
            }
            echo '</div>';
            $local_files=array_values(array_filter($files,function($f){return !GE_WTP_External_Artwork::is_link($f) && 'detached'!==($f['association_status']??'');}));
            if ( ! $customer_view && ! $quote['converted_order_id'] && $local_files ) {
                $latest = end( $local_files );
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h3>Aprobación de arte</h3><input type="hidden" name="action" value="ge_commercial_quote_approve_file"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="file_id" value="' . esc_attr( $latest['id'] ) . '"><input type="hidden" name="checksum" value="' . esc_attr( $latest['analysis']['sha256'] ?? '' ) . '">';
                wp_nonce_field( 'ge_commercial_quote_approve_file_' . $quote['id'] );
                echo '<p>Versión exacta: ' . esc_html( $latest['name'] ) . ' · ' . esc_html( ! empty( $latest['staff_approval']['approved'] ) ? 'Aprobado por staff' : 'Pendiente de revisión' ) . '</p><label>Producto<select name="item_index">';
                foreach ( $quote['snapshot']['items'] as $index => $line ) { echo '<option value="' . esc_attr( $index ) . '">' . esc_html( $line['name'] ) . '</option>'; }
                echo '</select></label><label><input type="checkbox" name="client_required" value="1" ' . checked( ! empty( $latest['client_approval_required'] ), true, false ) . '> Requiere aprobación del cliente</label><button type="submit" class="ge-staff-button">Aprobar internamente</button></form>';
            }
        }
        if (!$customer_view && !$quote['converted_order_id'] && in_array($quote['status'],array('draft','sent','viewed'),true)) { echo '<p><a class="ge-staff-button is-secondary" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$quote['id'],'edit'=>1))).'">Agregar o asignar varios artes por ítem</a></p>'; }
        if ( $customer_view && ( ! GE_WTP_Portal::is_staff_preview() && ! in_array( $quote['status'], array( 'draft', 'rejected', 'cancelled' ), true ) ) ) {
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
        $labels = array( 'created' => 'Creado', 'revised' => 'Editado', 'sent' => 'Publicado', 'viewed' => 'Visto por el cliente', 'accepted' => 'Aceptado en portal', 'accepted_staff' => 'Aceptación registrada por staff', 'file_uploaded' => 'Archivo cargado', 'file_attached' => 'Archivo vinculado', 'receipt_uploaded' => 'Comprobante cargado', 'receipt_attached' => 'Comprobante vinculado', 'payment_started' => 'Pago iniciado', 'payment_confirmed' => 'Pago confirmado', 'converted' => 'Convertido a pedido' );
        $labels['selection_saved'] = 'Selección guardada'; $labels['artwork_associations_saved'] = 'Asignación de artes actualizada'; $labels['pdf_attached'] = 'PDF comercial adjunto'; $labels['billing_selection'] = 'Receptor / emisor actualizados';
        echo '<details class="ge-production-card ge-quote-disclosure ge-quote-events"><summary>Actividad del presupuesto · ' . esc_html( count( $events ) ) . ' eventos</summary><ol>';
        foreach ( array_reverse( $events ) as $event ) {
            echo '<li><strong>' . esc_html( $labels[ $event['event'] ?? '' ] ?? ucfirst( $event['event'] ?? 'Actividad' ) ) . '</strong><time>' . esc_html( ! empty( $event['at'] ) ? wp_date( 'd/m/Y H:i', strtotime( $event['at'] ) ) : '' ) . '</time></li>';
        }
        echo '</ol></details>';
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
        if ( class_exists( 'GE_Organization_Runtime' ) && ! GE_Organization_Runtime::allowed( 'quotes', false ) ) { return null; }
        $organization = get_user_meta( $user_id, '_ge_organization_id', true );
        if ( $organization && class_exists( 'GE_Organization' ) && $organization !== GE_Organization::PRIMARY ) { return null; }
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
        $labels = array( 'draft' => 'Borrador', 'sent' => 'Publicado', 'viewed' => 'Visto', 'accepted' => 'Aprobado', 'rejected' => 'Rechazado', 'expired' => 'Vencido', 'converted' => 'Convertido en pedido', 'cancelled' => 'Cancelado' );
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
        GE_WTP_Job_Flow::assets();
        $customer_id = GE_WTP_Portal::portal_customer_id();
        $preview = GE_WTP_Portal::is_staff_preview();
        $selected = absint( $_GET['presupuesto'] ?? 0 );
        if ( ! $selected ) { GE_WTP_Job_Flow::customer_list(); return; }
        echo '<section class="ge-page-heading"><div><span class="ge-eyebrow">Propuestas</span><h1>Presupuestos</h1><p>Revisá las condiciones antes de aceptar y pagar.</p></div></section>';
        $posts = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 50, 'include' => $selected ? array( $selected ) : array(), 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $customer_id ) );
        if ( ! $posts ) { echo '<section class="ge-panel"><p>Todavía no tenés presupuestos.</p></section>'; return; }
        foreach ( $posts as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, get_current_user_id() );
            if ( ! GE_WTP_Portal_Quotes::visible( $quote ) || ( $selected && $selected !== $quote['id'] ) ) { continue; }
            if ( ! $preview ) { GE_WTP_Commercial_Quotes::mark_viewed( $quote['id'], $customer_id ); $quote = GE_WTP_Commercial_Quotes::get( $quote['id'], $customer_id ); }
            $customer_status = array( 'draft' => 'Borrador en vista previa', 'sent' => 'Publicado', 'viewed' => 'Visto', 'accepted' => 'Aprobado', 'converted' => 'En proceso', 'rejected' => 'Rechazado', 'expired' => 'Vencido' );
            echo '<article class="ge-panel ge-quote-customer"><span class="ge-eyebrow">' . esc_html( $quote['number'] ) . ' · versión ' . esc_html( $quote['version'] ) . '</span><h2>Presupuesto ' . esc_html( strtolower( $customer_status[ $quote['status'] ] ?? $quote['status'] ) ) . '</h2>';
            $proposal = $quote;
            $choices = GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] );
            $selection_ready = ! $choices;
            if ( $choices ) {
                $selection_preview = GE_WTP_Quote_Selection::preview_request( $quote );
                $chosen = array();
                if ( is_wp_error( $selection_preview ) ) { echo '<p role="alert">' . esc_html( $selection_preview->get_error_message() ) . '</p>'; }
                elseif ( ! empty( $selection_preview['snapshot']['customer_selection'] ) ) { $quote = $selection_preview; $chosen = $quote['snapshot']['customer_selection']['line_ids']; $selection_ready = true; }
                GE_WTP_Quote_Selection::render_choices( $proposal, $chosen, $quote['snapshot']['customer_selection']['configurations'] ?? array() );
            }
            if ( $selection_ready ) { self::render_snapshot( $quote['snapshot'], true ); }
            else { echo '<p>Elegí las opciones para ver el total y descargar tu PDF.</p>'; }
            $pdf_url = GE_WTP_Commercial_Quote_PDF::url( $quote['id'] );
            if ( $choices && $selection_ready ) { $pdf_url = add_query_arg( GE_WTP_Quote_Selection::selection_args( $quote ), $pdf_url ); }
            if ( $selection_ready ) {
            echo '<a class="ge-button ge-button-secondary ge-quote-pdf-download" href="' . esc_url( $pdf_url ) . '"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg> Descargar PDF</a>';
            }
            echo '<nav class="ge-quote-customer-links" aria-label="Acciones del presupuesto"><a href="#ge-quote-files-' . esc_attr( $quote['id'] ) . '">Subir archivos para producción</a><a href="#ge-quote-receipts-' . esc_attr( $quote['id'] ) . '">Subir comprobante</a><a href="#ge-quote-payment-' . esc_attr( $quote['id'] ) . '">Ir a pagar</a></nav>';
            self::render_files( $quote, true );
            self::render_receipts( $quote, true );
            if ( class_exists( 'GE_WTP_Commercial_Checkout' ) ) { GE_WTP_Commercial_Checkout::render_quote_checkout( $quote ); }
            self::render_save_selection( $proposal, $choices, false, $preview );
            self::render_approval( $proposal, $quote, $selection_ready, $preview );
            echo '</article>';
        }
    }

    private static function render_snapshot( $snapshot, $customer = false, $fiscal_separate = false ) {
        if ( ! $customer && ! $fiscal_separate ) { GE_WTP_Quote_Billing_Control::summary( $snapshot ); }
        if ( empty( $snapshot['items'] ) ) { return; }
        $tax_label = GE_WTP_Customer_Tax_UI::decision_label( $snapshot );
        if ( $tax_label && ! $customer && ! $fiscal_separate ) { echo '<p class="ge-quote-fiscal-decision">' . esc_html( $tax_label ) . '</p>'; }
        if ( ! $customer && ! $fiscal_separate ) { GE_WTP_Customer_Tax_UI::render_warnings( $snapshot['customer_tax_decision'] ?? array() ); }
        $profile = GE_WTP_Quote_Billing_Control::receiver( $snapshot );
        $delivery = $snapshot['delivery'] ?? array();
        if ( $profile ) {
            echo '<div class="ge-quote-context ge-quote-billing-delivery"><div><span>Facturación</span><strong>' . esc_html( ( $profile['label'] ?? 'Perfil principal' ) . ' · ' . ( ! empty( $profile['legal_name'] ) ? $profile['legal_name'] : 'Cliente' ) ) . '</strong></div><div><span>Entrega</span><strong>' . esc_html( $delivery ? ( ( $delivery['label'] ?? 'Destino' ) . ' · ' . ( $delivery['street'] ?? '' ) ) : 'A coordinar' ) . '</strong></div></div>';
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
            echo '</div><div class="ge-quote-item-price"><span>' . esc_html( $line['quantity'] ) . ' ' . esc_html( $line['unit'] ?? 'u' ) . ' × ' . esc_html( self::money( $line['entered_unit_cents'] ?? $line['unit_net_cents'] ) ) . ( 'final' === ( $snapshot['quote_vat_mode'] ?? '' ) ? ' · precio final' : ( ! empty( $snapshot['tax_cents'] ) ? ' + IVA' : ' · precio final' ) ) . '</span><strong>' . esc_html( self::money( $line['entered_line_cents'] ?? $line['net_cents'] ) ) . '</strong></div></article>';
        }
        echo '</div>'; self::render_totals( $snapshot );
        if ( $customer && ! empty( $snapshot['valid_until'] ) ) { echo '<p class="ge-quote-validity">Válido hasta el ' . esc_html( wp_date( 'd/m/Y', ( new DateTimeImmutable( $snapshot['valid_until'], wp_timezone() ) )->getTimestamp() ) ) . '</p>'; }
        if ( ! empty( $snapshot['notes_customer'] ) ) { echo '<div class="ge-quote-note"><strong>Nota para el cliente</strong><p>' . nl2br( esc_html( $snapshot['notes_customer'] ) ) . '</p></div>'; }
        if ( ! $customer && ! empty( $snapshot['notes_internal'] ) ) { echo '<div class="ge-quote-note is-internal"><strong>Nota interna</strong><p>' . nl2br( esc_html( $snapshot['notes_internal'] ) ) . '</p></div>'; }
        echo '</section>';
    }

    public static function render_totals( $snapshot ) {
        if ( GE_WTP_Quote_Selection::has_choices( $snapshot ) && empty( $snapshot['customer_selection'] ) ) { echo '<p>Alternativas disponibles: el total se define con la elección del cliente.</p>'; return; }
        echo '<div class="ge-quote-totals"><div><span>Subtotal / Neto sin IVA</span><strong>' . esc_html( self::money( $snapshot['subtotal_cents'] ?? $snapshot['net_cents'] ) ) . '</strong></div>';
        if ( ! empty( $snapshot['discount_cents'] ) ) { echo '<div><span>Descuento comercial</span><strong>−' . esc_html( self::money( $snapshot['discount_cents'] ) ) . '</strong></div><div><span>Neto imponible</span><strong>' . esc_html( self::money( $snapshot['net_cents'] ) ) . '</strong></div>'; }
        if ( isset( $snapshot['total_cents'] ) ) {
            $rate = $snapshot['tax_rates'][0] ?? $snapshot['billing']['resolution']['tax_rate_basis_points'] ?? $snapshot['billing']['entity']['tax_rate_basis_points'] ?? null;
            echo '<div><span>IVA' . ( 'final' === ( $snapshot['quote_vat_mode'] ?? '' ) ? ' incluido' : '' ) . ( $rate ? ' ' . esc_html( number_format_i18n( $rate / 100, 2 ) ) . '%' : '' ) . '</span><strong>' . esc_html( self::money( $snapshot['tax_cents'] ?? 0 ) ) . '</strong></div><div class="is-total"><span>TOTAL' . ( ! empty( $snapshot['tax_cents'] ) ? ' con IVA' : ' final' ) . '</span><strong>' . esc_html( self::money( $snapshot['total_cents'] ) ) . '</strong></div>';
            // Fiscal reconciliation messages belong to the staff-only summary.
            if ( 'C' === ( $snapshot['billing']['resolution']['document_type'] ?? '' ) ) { echo '<p>IVA no discriminado.</p>'; }
        } else { echo '<div><span>IVA</span><strong>A confirmar</strong></div><div class="is-total"><span>TOTAL</span><strong>A confirmar</strong></div><p>Datos fiscales pendientes. Se puede guardar el borrador; revisá el perfil y la configuración antes de enviar.</p>'; }
        echo '</div>';
    }

    public static function handle_totals() {
        self::require_staff(); check_ajax_referer( 'ge_commercial_quote_price', 'nonce' );
        $preview = self::form_preview(wp_unslash($_POST),get_current_user_id());
        if (is_wp_error($preview)) wp_send_json_error($preview->get_error_message(),422);
        $snapshot = $preview['snapshot'];
        ob_start(); self::render_totals( $snapshot );
        if ( ! empty( $_POST['deposit_enabled'] ) && isset( $snapshot['total_cents'] ) ) { echo '<p>Seña ' . esc_html( $snapshot['deposit_percent'] ) . '%: ' . esc_html( self::money( (int) round( $snapshot['total_cents'] * $snapshot['deposit_percent'] / 100 ) ) ) . '</p>'; }
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function form_preview($data, $actor) {
        $email = sanitize_email( $data['customer_email'] ?? '' );
        $customer = get_user_by( 'email', $email );
        if ($customer && class_exists('GE_Organization') && get_user_meta($customer->ID, '_ge_organization_id', true) && get_user_meta($customer->ID, '_ge_organization_id', true) !== GE_Organization::PRIMARY) return new WP_Error('ge_quote_customer_scope','Cliente no disponible.');
        $lines = array();
        foreach ( (array) ( $data['lines'] ?? array() ) as $line ) {
            if ( ! is_array( $line ) ) { return new WP_Error('ge_quote_item','Ítem inválido.'); }
            $line['name'] = $line['label'] ?? ''; $line['unit_net'] = $line['unit_price'] ?? '';
            if ( ! trim( $line['name'] ) ) { continue; }
            if ( empty( $line['choice_facets']['model_key'] ) && empty( $line['choice_facets']['finish_key'] ) ) { $line['choice_facets'] = array(); }
            elseif ( isset( $line['choice_papers'] ) ) { $line['choice_facets']['papers'] = preg_split( '/\r?\n/', trim( $line['choice_papers'] ) ); }
            $lines[] = $line;
        }
        $args = $data; $args['applied_by'] = $actor;
        $snapshot = GE_WTP_Commercial_Quotes::build_snapshot( $lines, $args );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        $previous = null; $q = null;
        if ( ! empty( $args['quote_id'] ) ) { $q = GE_WTP_Commercial_Quotes::get( absint( $args['quote_id'] ), $actor ); if ( is_wp_error( $q ) ) { return $q; } $previous = $q['snapshot']; }
        if ($q && class_exists('GE_WTP_Email_Templates') && (!GE_WTP_Email_Templates::quote_scope($q) || !$customer || (int)$q['customer_id'] !== (int)$customer->ID)) return new WP_Error('ge_quote_preview_scope','Cliente o presupuesto no disponible.');
        $profile = $customer ? GE_WTP_Customer_Branches::find( $customer->ID, $snapshot['billing_profile_id'] ) : array();
        $chosen = GE_WTP_Billing_Issuers::choose( (array) $profile, $args, $actor, $previous, false );
        if ( is_wp_error( $chosen ) ) { return $chosen; }
        $snapshot['issuer_snapshot'] = $chosen['issuer']; $snapshot['issuer_profile_id'] = $chosen['issuer']['id']; $snapshot['issuer_suggestion'] = $chosen['suggestion'];
        $snapshot = GE_WTP_Commercial_Quotes::preview_billing( $customer ? $customer->ID : 0, $snapshot );
        return array('snapshot'=>$snapshot,'customer'=>$customer,'quote'=>$q);
    }

    public static function customer_configuration_label( $line ) {
        $configuration = $line['configuration'] ?? array();
        if ( preg_match( '/^GF-VIN-00[1-4]$/', (string) ( $line['sku'] ?? '' ) ) && isset( $configuration['width'], $configuration['height'] ) ) {
            return $configuration['width'] . ' × ' . $configuration['height'] . ' cm';
        }
        return (string) ( $line['configuration_label'] ?? '' );
    }

    public static function money( $cents ) {
        $cents = (int) $cents;
        return 'ARS ' . number_format( $cents / 100, $cents % 100 ? 2 : 0, ',', '.' );
    }

    public static function handle_save() {
        self::require_staff(); check_admin_referer( 'ge_commercial_quote_save' );
        $artwork_save_key = !empty($_POST['artwork_v2']) ? GE_WTP_Quote_Artwork_V2::save_guard(sanitize_text_field($_POST['artwork_session']??'')) : '';
        $quote_id = absint( $_POST['quote_id'] ?? 0 );
        $existing = $quote_id ? GE_WTP_Commercial_Quotes::get( $quote_id, get_current_user_id() ) : null;
        if ( $quote_id && is_wp_error( $existing ) ) { self::staff_error( 'save' ); }
        $email = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
        if ( ! is_email( $email ) ) { self::save_error( new WP_Error( 'ge_quote_email_field', 'Ingresá un email válido.' ), 'customer_email' ); }
        if ( ! $name ) { self::save_error( new WP_Error( 'ge_quote_name_field', 'Ingresá el nombre del cliente.' ), 'customer_name' ); }
        $lines = array();
        foreach ( (array) ( $_POST['lines'] ?? array() ) as $line ) {
            $label = sanitize_text_field( wp_unslash( $line['label'] ?? '' ) );
            if ( ! $label ) { continue; }
            $facets = isset( $line['choice_facets'] ) && is_array( $line['choice_facets'] ) ? wp_unslash( $line['choice_facets'] ) : array();
            if ( empty( $facets['model_key'] ) && empty( $facets['finish_key'] ) ) { $facets = array(); }
            else { $facets['papers'] = preg_split( '/\r?\n/', trim( wp_unslash( $line['choice_papers'] ?? '' ) ) ); }
            $lines[] = array( 'choice_facets' => $facets, 'selection_type' => sanitize_key( $line['selection_type'] ?? 'required' ), 'selection_group' => sanitize_text_field( $line['selection_group'] ?? '' ), 'selection_recommended' => ! empty( $line['selection_recommended'] ), 'line_uuid' => sanitize_text_field($line['line_uuid'] ?? wp_generate_uuid4()), 'artwork_refs' => array_map('sanitize_text_field', (array)($line['artwork_refs']??array())), 'source_type' => sanitize_key( $line['source_type'] ?? '' ), 'name' => preg_replace( '/\s*\(#\d+\)$/', '', $label ), 'product_id' => absint( $line['product_id'] ?? 0 ), 'quantity' => sanitize_text_field( wp_unslash( $line['quantity'] ?? '' ) ), 'unit' => sanitize_text_field( wp_unslash( $line['unit'] ?? 'u' ) ), 'unit_net' => sanitize_text_field( wp_unslash( $line['unit_price'] ?? '' ) ), 'discount_type' => sanitize_key( $line['discount_type'] ?? 'percent' ), 'discount_value' => sanitize_text_field( wp_unslash( $line['discount_value'] ?? '0' ) ), 'discount_reason' => sanitize_textarea_field( wp_unslash( $line['discount_reason'] ?? '' ) ), 'configuration' => isset( $line['configuration'] ) && is_array( $line['configuration'] ) ? wp_unslash( $line['configuration'] ) : array(), 'finishes' => isset( $line['finishes'] ) && is_array( $line['finishes'] ) ? wp_unslash( $line['finishes'] ) : array(), 'details' => sanitize_text_field( wp_unslash( $line['details'] ?? '' ) ), 'notes' => sanitize_text_field( wp_unslash( $line['notes'] ?? '' ) ) );
        }
        $args = array( 'quote_vat_mode' => sanitize_key( $_POST['quote_vat_mode'] ?? '' ), 'billing_override' => ! empty( $_POST['billing_override'] ), 'billing_refresh' => ! empty( $_POST['billing_refresh'] ), 'billing_reason' => sanitize_textarea_field( wp_unslash( $_POST['billing_reason'] ?? $_POST['issuer_change_reason'] ?? '' ) ), 'customer_tax_confirm' => ! empty( $_POST['customer_tax_confirm'] ) || 'send' === sanitize_key( $_POST['quote_intent'] ?? '' ), 'issuer_profile_id' => sanitize_key( $_POST['issuer_profile_id'] ?? '' ), 'issuer_refresh' => ! empty( $_POST['issuer_refresh'] ), 'issuer_change_reason' => sanitize_textarea_field( wp_unslash( $_POST['issuer_change_reason'] ?? '' ) ), 'discount_type' => sanitize_key( $_POST['discount_type'] ?? 'percent' ), 'discount_value' => sanitize_text_field( wp_unslash( $_POST['discount_value'] ?? '0' ) ), 'discount_reason' => sanitize_textarea_field( wp_unslash( $_POST['discount_reason'] ?? '' ) ), 'valid_until' => wp_unslash( $_POST['valid_until'] ?? '' ), 'deposit_enabled' => ! empty( $_POST['deposit_enabled'] ), 'allow_empty_draft' => true, 'deposit_percent' => wp_unslash( $_POST['deposit_percent'] ?? 50 ), 'notes_customer' => wp_unslash( $_POST['notes_customer'] ?? '' ), 'notes_internal' => wp_unslash( $_POST['notes_internal'] ?? '' ), 'expected_version' => absint( $_POST['expected_version'] ?? 0 ), 'billing_profile_id' => sanitize_text_field( wp_unslash( $_POST['billing_profile_id'] ?? '' ) ), 'delivery_address_id' => sanitize_text_field( wp_unslash( $_POST['delivery_address_id'] ?? '' ) ) );
        $args['expected_hash'] = sanitize_text_field( $_POST['expected_hash'] ?? '' );
        if ( empty( $existing['snapshot']['issuer_snapshot'] ) && ! empty( $args['issuer_profile_id'] ) && ! trim( $args['issuer_change_reason'] ) ) { $args['issuer_change_reason'] = 'Selección manual del emisor al preparar el presupuesto'; }
        $args['allow_partial_draft'] = 'send' !== sanitize_key( $_POST['quote_intent'] ?? 'draft' );
        $validation = GE_WTP_Commercial_Quotes::build_snapshot( $lines, $args );
        if ( is_wp_error( $validation ) ) {
            $line_codes = array( 'ge_quote_line', 'ge_quote_source', 'ge_quote_product', 'ge_quote_unit', 'ge_quote_configuration', 'ge_quote_price', 'ge_quote_selection' );
            if ( in_array( $validation->get_error_code(), $line_codes, true ) ) {
                $position = 0;
                foreach ( (array) ( $_POST['lines'] ?? array() ) as $index => $raw ) {
                    if ( empty( $raw['label'] ) ) { continue; }
                    $single_args = $args; $single_args['discount_value'] = 0;
                    $one = GE_WTP_Commercial_Quotes::build_snapshot( array( $lines[$position++] ), $single_args );
                    if ( is_wp_error( $one ) ) {
                        $key = $one->get_error_code();
                        $field = 'ge_quote_price' === $key ? 'unit_price' : ( 'ge_quote_line' === $key ? 'quantity' : 'label' );
                        self::save_error( $one, 'lines[' . sanitize_text_field( $index ) . '][' . $field . ']' );
                    }
                }
            }
            self::save_error( $validation );
        }
        $artwork_files = null;
        if (!empty($_POST['artwork_v2'])) {
            $artwork_files = GE_WTP_Quote_Artwork_V2::prepare($existing,$lines,(array)($_POST['general_files']??array()),sanitize_text_field($_POST['artwork_session']??''),get_current_user_id());
            if (is_wp_error($artwork_files)) { self::save_error( $artwork_files, 'general_files' ); }
        }
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
            if ( is_wp_error( $customer_id ) ) { self::save_error( $customer_id, 'customer_email' ); }
            update_user_meta( $customer_id, '_ge_commercial_needs_invite', 'yes' );
        }
        $phone = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
        if ( strlen( $phone ) > 50 ) { self::save_error( new WP_Error( 'ge_quote_phone', 'El teléfono admite hasta 50 caracteres.' ), 'customer_phone' ); }
        if ( ! $existing && empty( $args['billing_profile_id'] ) && count( GE_WTP_Customer_Branches::profiles( $customer_id ) ) === 1 ) { $args['billing_profile_id'] = GE_WTP_Customer_Branches::profiles( $customer_id )[0]['id']; $_POST['billing_profile_id'] = $args['billing_profile_id']; }
        $tax_profile = GE_WTP_Customer_Tax_UI::quick_profile( $customer_id, wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $tax_profile ) ) { self::save_error( $tax_profile, 'customer_cuit' ); }
        $quote = $existing ? GE_WTP_Commercial_Quotes::revise( $quote_id, $lines, $args ) : GE_WTP_Commercial_Quotes::create_draft( $customer_id, $lines, $args );
        if ( is_wp_error( $quote ) ) { self::save_error( $quote ); }
        if (null !== $artwork_files) { GE_WTP_Quote_Artwork_V2::commit($quote['id'],$artwork_files,get_current_user_id()); }
        if ( $phone ) { update_user_meta( $customer_id, '_ge_whatsapp', $phone ); update_user_meta( $customer_id, 'billing_phone', $phone ); }
        if ( ! empty( $_FILES['ge_quote_file']['name'] ) ) {
            $file = GE_WTP_Commercial_Quote_Files::upload( $quote['id'], $_FILES['ge_quote_file'], sanitize_key( wp_unslash( $_POST['ge_quote_file_source'] ?? 'preliminary' ) ), get_current_user_id() );
            if ( is_wp_error( $file ) ) { wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'quote_error' => 'file' ) ) ); exit; }
        }
        if ($artwork_save_key) { add_option($artwork_save_key,$quote['id'],'',false); }
        if ( 'send' === sanitize_key( wp_unslash( $_POST['quote_intent'] ?? 'draft' ) ) ) {
            $sent = GE_WTP_Commercial_Quotes::send( $quote['id'] );
            if ( is_wp_error( $sent ) ) {
                if ( in_array($sent->get_error_code(),array('ge_quote_notice_failed','ge_quote_notice_unknown'),true) ) {
                    set_transient('ge_quote_send_error_'.get_current_user_id().'_'.$quote['id'],$sent->get_error_message(),300);
                    $published_url=GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$quote['id'],'quote_error'=>'send'));
                    if (!empty($_POST['ge_quote_async'])) { wp_send_json_success(array('url'=>$published_url,'quote_id'=>$quote['id'],'published'=>true,'notice_sent'=>false)); }
                    wp_safe_redirect($published_url);exit;
                }

                if ( ! empty( $_POST['ge_quote_async'] ) ) { wp_send_json_error( array( 'message' => $sent->get_error_message(), 'field' => 'issuer_profile_id', 'code' => $sent->get_error_code(), 'saved_quote' => array( 'id' => $quote['id'], 'version' => $quote['version'], 'hash' => GE_WTP_Quote_Billing_Control::hash( $quote['snapshot'] ), 'session' => wp_generate_uuid4() ) ), 422 ); }
                self::save_error( $sent );
            }
        }
        $url = GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote['id'], 'quote_saved' => 1 ) );
        if ( ! empty( $_POST['ge_quote_async'] ) ) { wp_send_json_success( array( 'url' => $url, 'quote_id' => $quote['id'] ) ); }
        wp_safe_redirect( $url ); exit;
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
        $resend = 'resend' === sanitize_key($_POST['notice_intent'] ?? 'publish');
        $version = absint($_POST['expected_version'] ?? 0);
        $request_id = sanitize_key(wp_unslash($_POST['notice_request_id'] ?? ''));
        $quote = $resend ? GE_WTP_Commercial_Quotes::resend($quote_id,0,$version,$request_id) : GE_WTP_Commercial_Quotes::send($quote_id,0,$version,$request_id);
        if (is_wp_error($quote)) { set_transient('ge_quote_send_error_'.get_current_user_id().'_'.$quote_id,$quote->get_error_message(),300);wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$quote_id,'quote_error'=>'send')));exit; }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $quote_id ) ) ); exit;
    }


    private static function render_save_selection( $quote, $choices, $staff, $preview ) {
        $allowed = in_array( $quote['status'], $staff ? array( 'draft', 'sent', 'viewed' ) : array( 'sent', 'viewed' ), true ) && ( empty( $quote['snapshot']['valid_until'] ) || $quote['snapshot']['valid_until'] >= wp_date( 'Y-m-d' ) );
        if ( isset( $_GET['selection_saved'] ) ) {
            $saved = GE_WTP_Quote_Selection::preview_request( $quote );
            if ( ! is_wp_error( $saved ) && ( ! empty( $saved['snapshot']['customer_selection'] ) || ! GE_WTP_Quote_Selection::has_choices( $saved['snapshot'] ) ) ) {
                $url = wp_nonce_url( admin_url( 'admin-post.php?action=ge_commercial_quote_pdf&quote_id=' . $quote['id'] ), 'ge_commercial_quote_pdf_' . $quote['id'] );
                $url = add_query_arg( GE_WTP_Quote_Selection::selection_args( $saved ), $url );
                echo '<div role="status" class="ge-selection-saved"><strong>Selección guardada.</strong><p>' . esc_html( 'Guardamos tus opciones. Esto no aprueba el presupuesto ni envía avisos al equipo.' ) . '</p><a class="ge-button" href="' . esc_url( $url ) . '" data-ge-saved-pdf>Descargar PDF de mi selección</a></div>';
            }
        }
        if ( ! $allowed && ( ! $preview || ! in_array( $quote['status'], array( 'draft', 'sent', 'viewed' ), true ) ) ) { return; }
        if ( ! $choices ) {
            echo '<form id="ge-save-selection" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="quote_id" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="selection_version" value="' . esc_attr( $quote['version'] ) . '"><input type="hidden" name="save_nonce" value="' . esc_attr( wp_create_nonce( 'ge_quote_save_' . $quote['id'] . '_' . $quote['version'] ) ) . '"><input type="hidden" name="presupuesto" value="' . esc_attr( $quote['id'] ) . '"><input type="hidden" name="seccion" value="presupuestos"></form>';
        }
        echo '<footer class="ge-selection-save"><p>' . esc_html( 'Guardar selección conserva tus opciones sin aprobar ni avisar. Aprobar presupuesto confirma el precio y avisa al equipo. La aprobación del diseño es independiente.' ) . '</p><button class="ge-button ge-button-primary ge-staff-button" type="submit" form="' . ( $choices ? 'ge-quote-configurator' : 'ge-save-selection' ) . '" formmethod="post" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" name="action" value="ge_quote_save_selection" data-ge-save-selection' . ( $preview ? ' disabled' : '' ) . '>Guardar selección</button>' . ( ! $staff && $allowed ? '<button class="ge-button ge-button-primary" type="submit" form="' . ( $choices ? 'ge-quote-configurator' : 'ge-save-selection' ) . '" formmethod="get" formaction="' . esc_url( GE_WTP_Portal::portal_url( 'presupuestos' ) ) . '" name="approval_review" value="1"' . ( $preview ? ' disabled' : '' ) . '>Aprobar presupuesto</button>' : '' ) . ( $preview ? '<small>Vista previa: guardar y aceptar están deshabilitados.</small>' : '' ) . '</footer>';
    }

    public static function handle_save_selection() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['quote_id'] ?? 0 ); $version = absint( $_POST['selection_version'] ?? 0 );
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['save_nonce'] ?? '' ) ), 'ge_quote_save_' . $id . '_' . $version ) ) { wp_die( 'Recargá el presupuesto para guardar la selección.', '', array( 'response' => 403 ) ); }
        $quote = GE_WTP_Commercial_Quotes::save_selection( $id, $version, wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $quote ) ) { self::selection_error_redirect( $id, $quote ); }
        $staff = GE_WTP_Staff_Portal::can_access();
        $args = array( $staff ? 'quote_id' : 'presupuesto' => $id, 'selection_saved' => 1 );
        wp_safe_redirect( $staff ? GE_WTP_Staff_Portal::portal_url( 'quotes', $args ) : GE_WTP_Portal::portal_url( 'presupuestos', $args ) ); exit;
    }

    public static function handle_accept() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['quote_id'] ?? 0 ); $version = absint( $_POST['selection_version'] ?? $_POST['version'] ?? 0 );
        check_admin_referer( 'ge_commercial_quote_accept_' . $id . '_' . $version );
        $quote = GE_WTP_Commercial_Quotes::approve_selection( $id, $version, wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $quote ) ) { self::selection_error_redirect( $id, $quote ); }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $id, 'approved' => 1 ) ) ); exit;
    }

    private static function selection_error_redirect( $id, $error ) {
        set_transient( 'ge_selection_error_' . get_current_user_id() . '_' . $id, $error->get_error_message(), 300 );
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $id, 'selection_error' => 1, 'approval_review' => 1 ) ) ); exit;
    }

    public static function handle_retry_approval_notice() {
        if ( ! is_user_logged_in() || GE_WTP_Portal::is_staff_preview() ) { wp_die( 'Acceso denegado.', '', array( 'response' => 403 ) ); }
        $id = absint( $_POST['quote_id'] ?? 0 ); $version = absint( $_POST['version'] ?? 0 );
        check_admin_referer( 'ge_approval_notice_' . $id . '_' . $version );
        $quote = GE_WTP_Commercial_Quotes::get( $id, get_current_user_id() );
        if ( is_wp_error( $quote ) || $quote['customer_id'] !== get_current_user_id() || $quote['version'] !== $version || ! in_array( $quote['status'], array( 'accepted', 'converted' ), true ) ) { wp_die( 'Recargá tu presupuesto aprobado antes de reintentar el aviso.', '', array( 'response' => 409 ) ); }
        GE_WTP_Commercial_Quotes::refresh_selection_notice( $id, get_current_user_id() );
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'presupuestos', array( 'presupuesto' => $id ) ) ); exit;
    }

    private static function hidden_selection( $values, $prefix = '' ) {
        foreach ( $values as $key => $value ) {
            $name = $prefix ? $prefix . '[' . $key . ']' : $key;
            if ( is_array( $value ) ) { self::hidden_selection( $value, $name ); }
            else { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">'; }
        }
    }

    private static function render_approval( $proposal, $selected, $ready, $preview ) {
        $id = $proposal['id'];
        if ( isset( $_GET['selection_error'] ) ) {
            $error = get_transient( 'ge_selection_error_' . get_current_user_id() . '_' . $id );
            if ( $error ) { echo '<p role="alert">' . esc_html( $error ) . ' Tus opciones guardadas se conservan.</p>'; }
        }
        if ( in_array( $proposal['status'], array( 'accepted', 'converted' ), true ) ) {
            $at = get_post_meta( $id, '_ge_commercial_accepted_at', true );
            echo '<section class="ge-approval-confirmation" role="status"><h3>Aprobado</h3>' . ( $at ? '<p>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $at ) ) ) . '</p>' : '' ) . '<p>La aceptación del precio está registrada. La aprobación del diseño se realiza por separado.</p>';
            if ( 'failed' === get_post_meta( $id, '_ge_commercial_accept_notice_status', true ) ) {
                echo '<p role="alert">Tu aprobación quedó registrada; el aviso al equipo está pendiente.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                self::hidden_selection( array( 'action' => 'ge_quote_retry_approval_notice', 'quote_id' => $id, 'version' => $proposal['version'] ) );
                wp_nonce_field( 'ge_approval_notice_' . $id . '_' . $proposal['version'] );
                echo '<button class="ge-button"' . ( $preview ? ' disabled' : '' ) . '>Reintentar aviso al equipo</button></form>';
            }
            echo '</section>'; return;
        }
        if ( ! isset( $_GET['approval_review'] ) || ! in_array( $proposal['status'], array( 'sent', 'viewed' ), true ) ) { return; }
        if ( ! $ready ) { echo '<p role="status">Elegí las variantes y pulsá “Aprobar presupuesto” para revisar el total antes de confirmar.</p>'; return; }
        if ( empty( $selected['snapshot']['customer_selection'] ) ) {
            $selected = GE_WTP_Quote_Selection::request_selection( $proposal, array( 'selection_version' => $proposal['version'] ) );
            if ( is_wp_error( $selected ) ) { echo '<p role="alert">' . esc_html( $selected->get_error_message() ) . '</p>'; return; }
        }
        $s = $selected['snapshot']; $receiver = $s['receiver_snapshot'] ?? $s['billing']['profile'] ?? array();
        $name = trim( $receiver['legal_name'] ?? $receiver['name'] ?? '' );
        echo '<section class="ge-approval-confirmation" id="ge-approval-review"><h3>Revisá antes de aprobar</h3><p>Presupuesto ' . esc_html( $proposal['number'] ) . ' · versión ' . esc_html( $proposal['version'] ) . '</p><p><strong>Receptor:</strong> ' . esc_html( $name ?: 'Sin receptor definido' ) . '</p>';
        foreach ( $s['items'] as $item ) { echo '<p>' . esc_html( $item['name'] . ' · ' . $item['quantity'] . ' ' . ( $item['unit'] ?? 'u' ) ) . '</p>'; }
        echo '<p><strong>Total final: ' . esc_html( self::money( $s['total_cents'] ) ) . '</strong></p><p>Al confirmar, aceptás esta selección y avisamos al equipo. Esto no realiza pagos ni aprueba el diseño.</p>';
        if ( ! $name ) { echo '<p role="alert">Pedí a Graph Express que complete el receptor antes de aprobar.</p></section>'; return; }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ge-approve-form>';
        self::hidden_selection( array_merge( GE_WTP_Quote_Selection::selection_args( $selected ), array( 'action' => 'ge_quote_approve', 'quote_id' => $id, 'selection_version' => $proposal['version'], 'proposal_hash' => GE_WTP_Quote_Billing_Control::hash( $proposal['snapshot'] ), 'selection_hash' => GE_WTP_Quote_Billing_Control::hash( $s ) ) ) );
        wp_nonce_field( 'ge_commercial_quote_accept_' . $id . '_' . $proposal['version'] );
        echo '<label><input type="checkbox" name="receiver_confirmed" value="1" required' . ( $preview ? ' disabled' : '' ) . '> Confirmo el receptor, los productos y el total.</label><button type="submit" class="ge-button ge-button-primary"' . ( $preview ? ' disabled' : '' ) . '>Confirmar aprobación</button></form></section>';
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

    private static function manual_tax_card( $customer_id, $snapshot ) {
        $profile = $snapshot['receiver_snapshot'] ?? ( $customer_id ? GE_WTP_Customer_Branches::find( $customer_id, $snapshot['billing_profile_id'] ?? 'default' ) : array() );
        $profile = (array) $profile;
        echo '<section class="ge-production-card"><h2>Datos fiscales del cliente</h2><p>Opcionales para guardar. Carga manual; estos datos no acreditan una verificación de ARCA.</p><div class="ge-manual-contact-grid">';
        foreach ( array( 'customer_cuit' => array( 'CUIT', 'cuit' ), 'tax_legal_name' => array( 'Razón social', 'legal_name' ), 'tax_fiscal_address' => array( 'Domicilio fiscal', 'fiscal_address' ) ) as $name => $field ) { echo '<label>' . esc_html( $field[0] ) . '<input name="' . esc_attr( $name ) . '" maxlength="220" value="' . esc_attr( $profile[$field[1]] ?? '' ) . '"></label>'; }
        echo '<label>Condición fiscal<select name="tax_vat_status">';
        foreach ( array( '' => 'Completar después', 'registered' => 'Responsable inscripto', 'monotributo' => 'Monotributista', 'exempt' => 'Exento', 'final_consumer' => 'Consumidor final' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $profile['vat_status'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label></div><p>Se conserva el perfil existente si dejás el CUIT vacío. Elegí el receptor que querés actualizar.</p></section>';
    }

    public static function customer_search() {
        self::require_staff(); check_ajax_referer( 'ge_quote_customer_search', 'nonce' );
        $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( strlen( $query ) < 2 ) { wp_send_json_success( array() ); }
        $users = get_users( array( 'role' => 'customer', 'number' => 12, 'search' => '*' . $query . '*', 'search_columns' => array( 'display_name', 'user_email' ), 'orderby' => 'display_name' ) );
        wp_send_json_success( array_map( function( $user ) { return array( 'id' => $user->ID, 'name' => $user->display_name, 'email' => $user->user_email ); }, $users ) );
    }

    private static function save_error( $error, $field = '' ) {
        $code = $error->get_error_code();
        if ( ! $field ) {
            if ( strpos( $code, 'issuer' ) !== false || strpos( $code, 'review' ) !== false ) { $field = 'issuer_profile_id'; }
            elseif ( strpos( $code, 'profile' ) !== false || strpos( $code, 'billing' ) !== false ) { $field = 'billing_profile_id'; }
            elseif ( strpos( $code, 'deposit' ) !== false ) { $field = 'deposit_percent'; }
            elseif ( strpos( $code, 'validity' ) !== false ) { $field = 'valid_until'; }
            elseif ( strpos( $code, 'discount' ) !== false ) { $field = 'discount_value'; }
            else { $field = 'lines'; }
        }
        if ( ! empty( $_POST['ge_quote_async'] ) ) { wp_send_json_error( array( 'message' => $error->get_error_message(), 'field' => $field, 'code' => $code ), 422 ); }
        wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => 422, 'back_link' => true ) );
    }

    private static function require_staff() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
    }
    private static function staff_error( $code ) {
        if ( ! empty( $_POST['ge_quote_async'] ) ) { self::save_error( new WP_Error( 'ge_quote_save', 'No se pudo guardar. Revisá la ficha elegida y los permisos de acceso.' ), 'customer_email' ); }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'new' => 1, 'quote_error' => sanitize_key( $code ) ) ) ); exit;
    }
}
