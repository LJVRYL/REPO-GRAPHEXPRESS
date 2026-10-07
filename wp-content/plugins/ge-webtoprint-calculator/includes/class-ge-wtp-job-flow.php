<?php
defined( 'ABSPATH' ) || exit;

/** Navigation over existing records. Reading a step never advances a business state. */
final class GE_WTP_Job_Flow {
    public static function init() {
        add_action( 'wp_ajax_ge_flow_quote_request_staff', array( __CLASS__, 'save_request' ) );
    }

    public static function assets() {
        foreach ( array( 'css', 'js' ) as $extension ) {
            $path = 'assets/' . $extension . '/job-flow.' . $extension;
            if ( 'css' === $extension ) { wp_enqueue_style( 'ge-job-flow', GE_WTP_PLUGIN_URL . $path, array(), (string) filemtime( GE_WTP_PLUGIN_DIR . $path ) ); }
            else { wp_enqueue_script( 'ge-job-flow', GE_WTP_PLUGIN_URL . $path, array(), (string) filemtime( GE_WTP_PLUGIN_DIR . $path ), true ); }
        }
    }

    public static function request_label( $status ) {
        return array( 'draft' => 'Borrador', 'new' => 'Recibida', 'review' => 'En revisión', 'info' => 'Necesitamos información', 'quoted' => 'Presupuesto preparado', 'closed' => 'Cerrada' )[ $status ] ?? 'En revisión';
    }

    public static function customer_list() {
        self::assets();
        $customer = GE_WTP_Portal::portal_customer_id();
        echo '<section class="ge-page-heading"><div><span class="ge-eyebrow">Propuestas</span><h1>Presupuestos</h1><p>Tus solicitudes y propuestas, desde la idea hasta el pedido.</p></div><a class="ge-button ge-button-primary" href="' . esc_url( GE_WTP_Portal::portal_url( 'personalizado', array( 'rapida'=>1 ) ) ) . '">Solicitar presupuesto</a></section>';
        echo '<section class="ge-panel"><h2>Propuestas recibidas</h2>';
        $visible = 0;
        foreach ( get_posts( array( 'post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status'=>'private', 'posts_per_page'=>50, 'meta_key'=>GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value'=>$customer ) ) as $post ) {
            $quote = GE_WTP_Commercial_Quotes::get( $post->ID, $customer );
            if ( is_wp_error( $quote ) || 'draft' === $quote['status'] && ! GE_WTP_Portal::is_staff_preview() ) { continue; }
            $visible++;
            $labels = array( 'draft'=>'En preparación', 'sent'=>'Para revisar', 'viewed'=>'Para revisar', 'accepted'=>'Aceptado', 'converted'=>'Pedido creado', 'rejected'=>'Rechazado', 'expired'=>'Vencido', 'cancelled'=>'Cancelado' );
            $choices = GE_WTP_Quote_Selection::has_choices( $quote['snapshot'] ) && empty( $quote['snapshot']['customer_selection'] );
            echo '<article class="ge-request-history"><div><strong>' . esc_html( $quote['number'] ) . '</strong><p>' . esc_html( $labels[ $quote['status'] ] ?? 'En revisión' ) . ' · versión ' . esc_html( $quote['version'] ) . '</p><p>' . esc_html( $choices ? 'Importe según tu elección' : ( isset( $quote['snapshot']['total_cents'] ) ? number_format_i18n( $quote['snapshot']['total_cents'] / 100, 2 ) . ' ' . ( $quote['snapshot']['currency'] ?? 'ARS' ) : 'Importe a confirmar' ) ) . '</p></div><a class="ge-button ge-button-secondary" href="' . esc_url( self::url( 'quotes', $quote['id'], true ) ) . '">Ver detalle</a></article>';
        }
        if ( ! $visible ) { echo '<p>Todavía no hay propuestas para revisar. Podés solicitar un presupuesto y seguirlo aquí.</p>'; }
        echo '</section><section class="ge-panel"><h2>Solicitudes en curso</h2>';
        $visible = 0;
        foreach ( get_posts( array( 'post_type'=>GE_WTP_Quote_Requests::TYPE, 'post_status'=>'private', 'posts_per_page'=>50, 'meta_key'=>GE_WTP_Quote_Requests::META ) ) as $post ) {
            $request = GE_WTP_Quote_Requests::get( $post->ID, $customer );
            if ( is_wp_error( $request ) || (int) $request['customer_id'] !== (int) $customer ) { continue; }
            $linked = ! empty( $request['quote_id'] ) ? GE_WTP_Commercial_Quotes::get( $request['quote_id'], $customer ) : null;
            if ( $linked && ! is_wp_error( $linked ) && (int) $linked['customer_id'] === (int) $customer && 'draft' !== $linked['status'] ) { continue; }
            $visible++;
            echo '<article class="ge-request-history"><strong>Solicitud #' . esc_html( $post->ID ) . '</strong><p>' . esc_html( self::request_label( $request['status'] ) ) . ' · ' . esc_html( count( $request['items'] ) ) . ' producto(s)</p>';
            if ( ! empty( $request['staff_message'] ) ) { echo '<p>' . esc_html( $request['staff_message'] ) . '</p>'; }
            if ( 'draft' === $request['status'] ) { echo '<a class="ge-button ge-button-secondary" href="' . esc_url( GE_WTP_Portal::portal_url( 'personalizado', array( 'request_id'=>$post->ID, 'rapida'=>count( $request['items'] ) === 1 ? 1 : '' ) ) ) . '">Retomar borrador</a>'; }
            echo '</article>';
        }
        if ( ! $visible ) { echo '<p>No tenés solicitudes pendientes de presupuesto.</p>'; }
        echo '</section>';
    }

    public static function request_form( $request ) {
        self::assets(); $id = $request['id'];
        $draft = (array) get_post_meta( $id, '_ge_request_staff_draft_' . get_current_user_id(), true );
        echo '<a class="ge-staff-button is-secondary" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'requests' ) ) . '">← Volver a solicitudes</a>';
        if ( in_array( $request['status'], array( 'new', 'review', 'info' ), true ) ) {
            echo '<form class="ge-job-request ge-production-card" data-job-request data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate><input type="hidden" name="action" value="ge_request_staff"><input type="hidden" name="op" value="convert"><input type="hidden" name="request_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( $request['updated_at'] ?? '' ) . '"><input type="hidden" name="expected_request_hash" value="' . esc_attr( hash( 'sha256', wp_json_encode( $request ) ) ) . '">';
            wp_nonce_field( 'ge_request_staff_' . $id );
            echo '<input type="hidden" disabled data-job-restore value="' . esc_attr( wp_json_encode( $draft ) ) . '">';
            echo '<p class="ge-job-request-progress" data-job-progress role="status"></p><div class="ge-job-request-error" data-job-error role="alert" tabindex="-1"></div><section data-job-card="Receptor"><h2>Confirmar el receptor</h2><p>La ficha y los productos se conservan desde esta solicitud.</p><label>Presupuesto a nombre de<select name="billing_profile_id" required><option value="">Elegí un perfil activo del cliente</option>';
            foreach ( GE_WTP_Quote_Requests::profiles( $request['customer_id'] ) as $profile ) {
                echo '<option value="' . esc_attr( $profile['id'] ) . '"' . selected( $draft['billing_profile_id'] ?? $request['billing_profile_id'], $profile['id'], false ) . '>' . esc_html( $profile['label'] . ' · ' . $profile['legal_name'] ) . '</option>';
            }
            echo '</select></label><p>Si falta un perfil, completalo en la ficha del cliente antes de cotizar.</p></section>';
            foreach ( $request['items'] as $line ) {
                echo '<section data-job-card="' . esc_attr( $line['title'] ) . '"><h2>' . esc_html( $line['title'] ) . '</h2><p>' . esc_html( $line['quantity'] . ' unidades · ' . implode( ' · ', array_filter( array( $line['description'], ! empty( $line['width'] ) && ! empty( $line['height'] ) ? $line['width'] . ' × ' . $line['height'] . ' ' . $line['measure_unit'] : '', $line['material'], $line['finishing'], $line['options'], $line['notes'] ) ) ) ) . '</p><label>Precio neto por unidad<input data-job-price="' . esc_attr( $line['title'] ) . '" name="prices[' . esc_attr( $line['line_uuid'] ) . ']" value="' . esc_attr( $draft['prices'][ $line['line_uuid'] ] ?? '' ) . '" type="number" required min="0" step="0.01" inputmode="decimal"></label>';
                foreach ( GE_WTP_Commercial_Quote_Files::all( $id ) as $file ) {
                    if ( ( $file['line_uuid'] ?? '' ) !== $line['line_uuid'] || 'detached' === ( $file['association_status'] ?? '' ) ) { continue; }
                    $url = GE_WTP_External_Artwork::is_link( $file ) ? $file['url'] : wp_nonce_url( add_query_arg( array( 'action'=>'ge_request_file', 'request_id'=>$id, 'file_id'=>$file['id'] ), admin_url( 'admin-post.php' ) ), 'ge_request_file_' . $id );
                    echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $file['name'] ) . '</a></p>';
                    GE_WTP_File_Analysis::render( $file, true );
                }
                echo '</section>';
            }
            echo '<section data-job-card="Facturación"><h2>Revisar la facturación</h2>'; GE_WTP_Billing_Issuers::render_picker(); echo '</section><section class="ge-job-request-review" data-job-card="Revisión final"><h2>Revisar y preparar el presupuesto</h2><dl data-job-review></dl><p>Se crea un borrador con los productos, cantidades, receptor y archivos vinculados. Revisá importes e IVA en el presupuesto antes de enviarlo.</p><p>Esta acción no envía avisos, no cobra ni libera producción.</p></section><p data-job-saved role="status">El avance es interno y no envía una propuesta al cliente.</p><div class="ge-job-request-actions"><button class="ge-staff-button is-secondary" type="button" data-job-back>Atrás</button><button class="ge-staff-button is-secondary" type="submit" name="job_intent" value="draft" data-job-draft>Guardar avance</button><button class="ge-staff-button" type="button" data-job-next>Continuar</button><button class="ge-staff-button" type="submit" data-job-submit>Crear presupuesto borrador</button></div></form>';
        }
        echo '<details class="ge-production-card"><summary>Seguimiento de la solicitud</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_request_staff"><input type="hidden" name="request_id" value="' . esc_attr( $id ) . '">';
        wp_nonce_field( 'ge_request_staff_' . $id );
        echo '<label>Estado<select name="status">';
        foreach ( array( 'review'=>'En revisión', 'info'=>'Necesitamos información', 'closed'=>'Cerrar solicitud' ) as $value=>$label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $request['status'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label><label>Mensaje visible para el cliente<textarea name="message" maxlength="2000">' . esc_textarea( $request['staff_message'] ?? '' ) . '</textarea></label><button class="ge-staff-button" name="op" value="status">Guardar seguimiento</button></form></details>';
    }

    /** Reject even staff-visible links when they do not belong to this same job. */
    public static function context( $section, $id, $actor ) {
        $staff = GE_WTP_Staff_Portal::can_access();
        $module = in_array( $section, array( 'requests', 'quotes' ), true ) ? 'quotes' : ( 'production' === $section ? 'production' : 'orders' );
        if ( $staff && class_exists( 'GE_Organization_Runtime' ) && ! GE_Organization_Runtime::allowed( $module, false, $actor ) ) { return null; }
        $request = $quote = $order = null;
        if ( 'requests' === $section ) {
            $request = GE_WTP_Quote_Requests::get( $id, $actor );
            if ( is_wp_error( $request ) ) { return null; }
            if ( ! empty( $request['quote_id'] ) ) { $quote = GE_WTP_Commercial_Quotes::get( $request['quote_id'], $actor ); }
        } elseif ( 'quotes' === $section ) {
            $quote = GE_WTP_Commercial_Quotes::get( $id, $actor );
            if ( is_wp_error( $quote ) ) { return null; }
        } else {
            $order = wc_get_order( $id );
            if ( ! $order || ! GE_WTP_Staff_Portal::can_access() && (int) $order->get_customer_id() !== (int) $actor ) { return null; }
            if ( 'yes' === $order->get_meta( '_ge_commercial_payment_order', true ) ) { return null; }
            $quote_id = absint( $order->get_meta( '_ge_commercial_quote_id', true ) );
            if ( $quote_id ) { $quote = GE_WTP_Commercial_Quotes::get( $quote_id, $actor ); }
        }
        if ( is_wp_error( $quote ) ) { $quote = null; }
        if ( $quote && 'draft' === $quote['status'] && ! $staff && ! GE_WTP_Portal::is_staff_preview() ) { return null; }
        if ( $request && $quote && (int) $request['customer_id'] !== (int) $quote['customer_id'] ) { $quote = null; }
        if ( $quote && ! $request ) {
            $request_id = absint( get_post_meta( $quote['id'], '_ge_quote_request_source', true ) );
            if ( $request_id ) {
                $candidate = GE_WTP_Quote_Requests::get( $request_id, $actor );
                if ( ! is_wp_error( $candidate ) && (int) $candidate['quote_id'] === (int) $quote['id'] && (int) $candidate['customer_id'] === (int) $quote['customer_id'] ) { $request = $candidate; }
            }
        }
        if ( $quote && ! $order && ! empty( $quote['converted_order_id'] ) ) {
            $candidate = wc_get_order( $quote['converted_order_id'] );
            if ( $candidate && (int) $candidate->get_customer_id() === (int) $quote['customer_id'] && (int) $candidate->get_meta( '_ge_commercial_quote_id', true ) === (int) $quote['id'] ) { $order = $candidate; }
        }
        if ( $order && $quote && ( (int) $quote['converted_order_id'] !== (int) $order->get_id() || (int) $quote['customer_id'] !== (int) $order->get_customer_id() ) ) { $quote = $request = null; }
        return array( 'request' => $request, 'quote' => $quote, 'order' => $order );
    }

    /** Existing commercial validation, evaluated against the preserved order version. */
    public static function commercial_check( $order ) {
        if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { return new WP_Error( 'ge_job_inactive', 'El pedido no está activo. Revisá su estado comercial.' ); }
        $id = absint( $order->get_meta( '_ge_commercial_quote_id', true ) );
        if ( ! $id ) { return true; }
        // Internal validation exposes no quote contents; production roles can validate an order they own.
        $quote = GE_WTP_Commercial_Quotes::get( $id );
        if ( is_wp_error( $quote ) ) { return $quote; }
        if ( (int) $quote['converted_order_id'] !== (int) $order->get_id() || (int) $quote['customer_id'] !== (int) $order->get_customer_id() || 'converted' !== $quote['status'] ) { return new WP_Error( 'ge_job_link', 'Revisá la vinculación y confirmación del presupuesto de origen.' ); }
        if ( (int) $order->get_meta( '_ge_commercial_quote_version', true ) !== (int) $quote['version'] ) { return new WP_Error( 'ge_job_version', 'La versión del presupuesto cambió. Revisá el pedido antes de liberar el arte.' ); }
        $snapshot = $order->get_meta( '_ge_commercial_quote_snapshot', true );
        if ( ! is_array( $snapshot ) || ! $snapshot ) { return new WP_Error( 'ge_job_snapshot', 'Falta la propuesta conservada en el pedido. Revisá el origen comercial.' ); }
        $quote['snapshot'] = $snapshot;
        return GE_WTP_Commercial_Quotes::check_billing_snapshot( $quote );
    }

    public static function staff( $section ) {
        if ( ! GE_WTP_Staff_Portal::can_access() || ! in_array( $section, array( 'requests', 'quotes', 'orders', 'production' ), true ) ) { return; }
        self::assets();
        $id = absint( $_GET[ 'requests' === $section ? 'request_id' : ( 'quotes' === $section ? 'quote_id' : 'order_id' ) ] ?? 0 );
        if ( ! $id || ! empty( $_GET['edit'] ) ) { return; }
        $context = self::context( $section, $id, get_current_user_id() );
        if ( $context ) { self::render( $context, $section, false ); }
    }

    public static function customer( $section ) {
        $id = absint( 'presupuestos' === $section ? ( $_GET['presupuesto'] ?? $_GET['quote_id'] ?? 0 ) : ( $_GET['pedido'] ?? $_GET['order_id'] ?? 0 ) );
        if ( ! $id ) { return; }
        $context = self::context( 'presupuestos' === $section ? 'quotes' : 'orders', $id, GE_WTP_Portal::portal_customer_id() );
        if ( $context ) { self::assets(); self::render( $context, 'presupuestos' === $section ? 'quotes' : 'orders', true ); }
    }

    public static function render( $context, $section, $customer ) {
        $request = $context['request']; $quote = $context['quote']; $order = $context['order'];
        $stage = $order ? GE_WTP_Order_Lifecycle::stage( $order ) : '';
        $steps = array( 'requests' => array( 'Solicitud', $request ? $request['id'] : 0 ), 'quotes' => array( 'Presupuesto', $quote ? $quote['id'] : 0 ), 'orders' => array( 'Pedido', $order ? $order->get_id() : 0 ), 'production' => array( 'Producción y entrega', $order ? $order->get_id() : 0 ) );
        $reference = $quote ? $quote['number'] : ( $order ? '#' . $order->get_order_number() : 'Solicitud #' . $request['id'] );
        $actual = $order ? GE_WTP_Order_Lifecycle::label( $order ) : ( $quote ? ( array( 'draft'=>'Presupuesto en borrador', 'sent'=>'Esperando respuesta', 'viewed'=>'Esperando respuesta', 'accepted'=>'Presupuesto aceptado', 'converted'=>'Pedido creado', 'rejected'=>'Presupuesto rechazado', 'expired'=>'Presupuesto vencido', 'cancelled'=>'Presupuesto cancelado' )[ $quote['status'] ] ?? 'Presupuesto en revisión' ) : self::request_label( $request['status'] ) );
        $next = ''; $owner = ''; $target = ''; $action = '';
        if ( $order ) {
            $target = self::url( 'production', $order->get_id(), $customer );
            if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { $next = 'Revisar el estado del pedido antes de continuar.'; $owner = 'Graph Express'; $action = 'Ver pedido'; $target = self::url( 'orders', $order->get_id(), $customer ); }
            elseif ( 'entregado' === $stage ) { $next = 'Entrega registrada. Podés consultar los archivos y documentos del trabajo.'; $owner = 'Trabajo entregado'; $action = 'Ver pedido'; $target = self::url( 'orders', $order->get_id(), $customer ); }
            elseif ( 'listo' === $stage ) { $next = 'Coordinar la entrega y registrar su confirmación.'; $owner = 'Graph Express y cliente'; $action = $customer ? 'Ver entrega' : 'Coordinar entrega'; }
            elseif ( 'produccion' === $stage || 'production' === $order->get_meta( '_ge_production_status', true ) ) { $next = 'Completar la producción y preparar la entrega.'; $owner = 'Graph Express'; $action = 'Ver producción'; }
            else {
                $blocked = GE_WTP_Artwork_Library::blocked_item_names( $order );
                $check = $customer ? true : self::commercial_check( $order );
                $next = $blocked ? 'Revisar el archivo exacto y su aprobación en ' . count( $blocked ) . ' producto(s).' : 'Revisar las condiciones y la fecha prometida antes de liberar producción.';
                if ( is_wp_error( $check ) ) { $next = $check->get_error_message(); }
                $owner = 'Graph Express' . ( $blocked ? ' y cliente' : '' ); $action = $customer ? 'Ver archivos del pedido' : 'Revisar antes de producir';
            }
        } elseif ( $quote ) {
            $target = self::url( 'quotes', $quote['id'], $customer );
            if ( 'draft' === $quote['status'] ) { $next = $customer ? 'Graph Express está preparando tu propuesta.' : 'Completar y revisar la propuesta antes de enviarla.'; $owner = 'Graph Express'; $action = $customer ? '' : 'Completar presupuesto'; if ( ! $customer ) { $target = GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id'=>$quote['id'], 'edit'=>1 ) ); } }
            elseif ( in_array( $quote['status'], array( 'sent', 'viewed' ), true ) ) { $next = 'Elegir las opciones y confirmar la propuesta vigente.'; $owner = 'Cliente'; $action = $customer ? 'Revisar propuesta' : 'Ver propuesta enviada'; }
            elseif ( 'accepted' === $quote['status'] ) { $next = $customer ? 'Graph Express prepara tu pedido con la versión aceptada.' : 'Crear el pedido conservando la versión y la elección aceptadas.'; $owner = 'Graph Express'; $action = $customer ? '' : 'Continuar al pedido'; $target .= '#ge-commercial-next'; }
            else { $next = 'Revisar el estado de la propuesta antes de continuar.'; $owner = 'Graph Express'; $action = 'Ver presupuesto'; }
        } elseif ( $request ) {
            $next = 'info' === $request['status'] ? 'Completar la información solicitada antes de cotizar.' : ( 'closed' === $request['status'] ? 'Solicitud cerrada. Consultá su historial.' : 'Revisar la necesidad y preparar un presupuesto en borrador.' );
            $owner = 'info' === $request['status'] ? 'Cliente y Graph Express' : 'Graph Express';
        }
        echo '<section class="ge-job-flow" aria-label="Recorrido del trabajo"><header><div><span>Trabajo ' . esc_html( $reference ) . '</span><strong>' . esc_html( $actual ) . '</strong></div>';
        if ( $quote ) { echo '<span class="ge-job-version">Versión ' . esc_html( $order ? $order->get_meta( '_ge_commercial_quote_version', true ) : $quote['version'] ) . ( $order ? ' conservada en el pedido' : ' vigente' ) . '</span>'; }
        echo '</header><nav aria-label="Etapas de este trabajo"><ol>';
        foreach ( $steps as $key => $step ) {
            $url = $step[1] && ( ! $customer || 'requests' !== $key ) ? self::url( $key, $step[1], $customer ) : '';
            echo '<li>' . ( $url ? '<a' . ( $key === $section ? ' aria-current="page"' : '' ) . ' href="' . esc_url( $url ) . '">' : '<span aria-disabled="true">' ) . esc_html( $step[0] ) . ( $url ? '</a>' : '</span>' ) . '</li>';
        }
        echo '</ol></nav><div class="ge-job-next"><div><span>Siguiente acción · ' . esc_html( $owner ) . '</span><p>' . esc_html( $next ) . '</p></div>';
        if ( $action ) { echo '<a class="' . esc_attr( $customer ? 'ge-button ge-button-secondary' : 'ge-staff-button is-secondary' ) . '" href="' . esc_url( $target ) . '">' . esc_html( $action ) . '</a>'; }
        echo '</div></section>';
    }

    private static function url( $section, $id, $customer ) {
        if ( $customer ) { return GE_WTP_Portal::portal_url( 'quotes' === $section ? 'presupuestos' : 'pedidos', array( 'quotes' === $section ? 'presupuesto' : 'pedido' => $id ) ); }
        return GE_WTP_Staff_Portal::portal_url( $section, array( 'requests' === $section ? 'request_id' : ( 'quotes' === $section ? 'quote_id' : 'order_id' ) => $id ) );
    }

    /** AJAX keeps entered prices and receiver intact on validation/conflict errors. */
    public static function save_request() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_send_json_error( array( 'message'=>'Acceso denegado.' ), 403 ); }
        if ( class_exists( 'GE_Organization_Runtime' ) && ! GE_Organization_Runtime::allowed( 'quotes', true ) ) { wp_send_json_error( array( 'message'=>'Tu rol no puede preparar presupuestos.' ), 403 ); }
        $id = absint( $_POST['request_id'] ?? 0 );
        check_ajax_referer( 'ge_request_staff_' . $id, '_wpnonce' );
        $request = GE_WTP_Quote_Requests::get( $id, get_current_user_id() );
        if ( is_wp_error( $request ) ) { wp_send_json_error( array( 'message'=>$request->get_error_message() ), 403 ); }
        if ( empty( $request['quote_id'] ) && ! hash_equals( hash( 'sha256', wp_json_encode( $request ) ), (string) ( $_POST['expected_request_hash'] ?? '' ) ) ) { wp_send_json_error( array( 'message'=>'La solicitud cambió. Abrí su ficha para revisar la actualización. Tus valores siguen en pantalla.', 'code'=>'ge_request_stale' ), 409 ); }
        if ( (string) ( $_POST['expected_updated_at'] ?? '' ) !== (string) ( $request['updated_at'] ?? '' ) && empty( $request['quote_id'] ) ) { wp_send_json_error( array( 'message'=>'La solicitud cambió en otra sesión. Abrí su ficha para revisar la actualización. Tus valores siguen en pantalla.' ), 409 ); }
        if ( 'draft' === ( $_POST['job_intent'] ?? '' ) ) {
            if ( ! in_array( $request['status'], array( 'new', 'review', 'info' ), true ) || ! empty( $request['quote_id'] ) ) { wp_send_json_error( array( 'message'=>'Esta solicitud ya avanzó. Abrí el presupuesto vinculado.' ), 409 ); }
            $draft = array( 'prices'=>array(), 'billing_profile_id'=>sanitize_text_field( wp_unslash( $_POST['billing_profile_id'] ?? '' ) ) );
            $draft['issuer_profile_id'] = sanitize_key( $_POST['issuer_profile_id'] ?? '' );
            $draft['issuer_change_reason'] = sanitize_text_field( wp_unslash( $_POST['issuer_change_reason'] ?? '' ) );
            $draft['customer_tax_confirm'] = empty( $_POST['customer_tax_confirm'] ) ? '' : '1';
            foreach ( $request['items'] as $line ) {
                $price = trim( (string) ( $_POST['prices'][ $line['line_uuid'] ] ?? '' ) );
                if ( $price !== '' && ( ! is_numeric( $price ) || (float) $price < 0 ) ) { wp_send_json_error( array( 'message'=>'Revisá los precios antes de guardar el avance.', 'code'=>'price' ), 422 ); }
                $draft['prices'][ $line['line_uuid'] ] = $price;
            }
            update_post_meta( $id, '_ge_request_staff_draft_' . get_current_user_id(), $draft );
            wp_send_json_success( array( 'saved'=>true ) );
        }
        $quote = GE_WTP_Quote_Requests::convert( $id, wp_unslash( $_POST['prices'] ?? array() ), wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $quote ) ) { wp_send_json_error( array( 'message'=>$quote->get_error_message(), 'code'=>$quote->get_error_code() ), 422 ); }
        wp_send_json_success( array( 'url'=>GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id'=>$quote['id'] ) ) ) );
    }
}
