<?php
defined( 'ABSPATH' ) || exit;

/** The internal customer view; the customer's own profile remains separate. */
final class GE_WTP_Customer_Workspace {
    public static function init() {
        add_action( 'admin_post_ge_customer_workspace_save', array( __CLASS__, 'save' ) );
    }

    private static function url( $id, $args = array() ) {
        return GE_WTP_Staff_Portal::portal_url( 'customers', array_merge( array( 'customer_id' => $id ), $args ) );
    }

    private static function field( $label, $name, $value, $type = 'text' ) {
        echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="220"></label>';
    }

    private static function form_start( $id, $section, $extra = array() ) {
        echo '<form class="ge-workspace-form ge-profile-fields" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ge-workspace-form>';
        echo '<input type="hidden" name="action" value="ge_customer_workspace_save"><input type="hidden" name="customer_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
        foreach ( $extra as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
        wp_nonce_field( 'ge_customer_workspace_' . $id . '_' . $section );
    }

    private static function form_end() {
        echo '<div class="ge-workspace-save"><span data-ge-save-state aria-live="polite">Sin cambios</span><button type="submit">Guardar</button></div></form>';
    }

    public static function save() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_operations' ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $id = absint( $_POST['customer_id'] ?? 0 );
        $section = sanitize_key( wp_unslash( $_POST['section'] ?? '' ) );
        check_admin_referer( 'ge_customer_workspace_' . $id . '_' . $section );
        if ( ! $id || ! get_userdata( $id ) ) { wp_die( 'Cliente inválido.', 400 ); }
        $text = function ( $key ) { return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ); };
        $result = 'saved';
        if ( 'identity' === $section ) {
            $first = $text( 'first_name' ); $last = $text( 'last_name' );
            $updated = wp_update_user( array( 'ID' => $id, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ?: get_userdata( $id )->user_email ) );
            if ( is_wp_error( $updated ) ) { $result = 'error'; }
            else {
                update_user_meta( $id, '_ge_whatsapp', $text( 'whatsapp' ) );
                update_user_meta( $id, 'billing_phone', $text( 'whatsapp' ) );
                update_user_meta( $id, '_ge_contact_person', $text( 'contact_person' ) );
            }
        } elseif ( 'billing' === $section ) {
            try {
                GE_WTP_Billing::normalize_profile( array( 'billing_mode' => $text( 'billing_mode' ), 'cuit' => $text( 'cuit' ), 'legal_name' => $text( 'legal_name' ), 'vat_status' => $text( 'vat_status' ), 'billing_email' => $text( 'billing_email' ), 'fiscal_address' => $text( 'fiscal_address' ) ) );
                GE_WTP_Billing::save_profile( $id, array( 'billing_mode' => $text( 'billing_mode' ), 'cuit' => $text( 'cuit' ), 'legal_name' => $text( 'legal_name' ), 'vat_status' => $text( 'vat_status' ), 'billing_email' => $text( 'billing_email' ), 'fiscal_address' => $text( 'fiscal_address' ) ), get_current_user_id(), ! empty( $_POST['billing_verify'] ) );
            } catch ( InvalidArgumentException $e ) { $result = 'billing-error'; }
        } elseif ( 'delivery' === $section ) {
            $addresses = GE_WTP_Customers::addresses( $id );
            $address_id = $text( 'address_id' );
            $index = null;
            foreach ( $addresses as $key => $address ) { if ( (string) ( $address['id'] ?? $key ) === $address_id ) { $index = $key; break; } }
            if ( '' !== $address_id && null === $index ) { $result = 'error'; }
            elseif ( ! empty( $_POST['archive'] ) ) {
                if ( null === $index ) { $result = 'error'; }
                else {
                    $archived = get_user_meta( $id, '_ge_delivery_addresses_archive', true );
                    $archived = is_array( $archived ) ? $archived : array();
                    $archived[] = array_merge( $addresses[ $index ], array( 'archived_at' => gmdate( 'c' ), 'archived_by' => get_current_user_id() ) );
                    update_user_meta( $id, '_ge_delivery_addresses_archive', $archived );
                    array_splice( $addresses, $index, 1 ); update_user_meta( $id, '_ge_delivery_addresses', $addresses );
                }
            } else {
                $row = array( 'id' => '' === $address_id || ctype_digit( $address_id ) ? wp_generate_uuid4() : $address_id );
                foreach ( array( 'label', 'recipient', 'street', 'city', 'province', 'postal_code', 'phone', 'hours', 'notes' ) as $key ) { $row[ $key ] = $text( $key ); }
                if ( ! $row['street'] || ( null === $index && count( $addresses ) >= 4 ) ) { $result = 'error'; }
                else { if ( null === $index ) { $addresses[] = $row; } else { $addresses[ $index ] = $row; } update_user_meta( $id, '_ge_delivery_addresses', $addresses ); }
            }
        } elseif ( 'preferences' === $section ) {
            $preference = $text( 'delivery_preference' );
            update_user_meta( $id, '_ge_delivery_preference', in_array( $preference, array( 'retiro', 'envio', 'coordinar' ), true ) ? $preference : '' );
        } elseif ( 'internal' === $section ) {
            update_user_meta( $id, '_ge_customer_tags', $text( 'internal_tags' ) );
            update_user_meta( $id, '_ge_customer_internal_notes', sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ?? '' ) ) );
        } else { wp_die( 'Sección inválida.', 400 ); }
        wp_safe_redirect( self::url( $id, array( 'workspace_status' => $result, 'workspace_section' => $section ) ) . '#ge-workspace-' . $section );
        exit;
    }

    private static function address_form( $id, $address = array(), $index = 0 ) {
        $existing = isset( $address['id'] ) || isset( $address['street'] );
        $address_id = (string) ( $address['id'] ?? ( $existing ? $index : '' ) );
        echo '<details class="ge-workspace-item"><summary><strong>' . esc_html( $existing ? ( $address['label'] ?: 'Destino ' . ( $index + 1 ) ) : 'Agregar destino' ) . '</strong><span>' . esc_html( $existing ? ( $address['street'] ?? '' ) : 'Nueva dirección de entrega' ) . '</span></summary>';
        self::form_start( $id, 'delivery', array( 'address_id' => $address_id ) );
        foreach ( array( 'label' => 'Sede', 'recipient' => 'Quién recibe', 'street' => 'Dirección', 'city' => 'Localidad', 'province' => 'Provincia', 'postal_code' => 'Código postal', 'phone' => 'Teléfono', 'hours' => 'Días y horarios', 'notes' => 'Instrucciones' ) as $key => $label ) { self::field( $label, $key, $address[ $key ] ?? '' ); }
        if ( $existing ) { echo '<button class="ge-workspace-archive" type="submit" name="archive" value="1" data-ge-confirm="¿Desactivar este destino? Los pedidos anteriores conservan su snapshot.">Desactivar</button>'; }
        self::form_end(); echo '</details>';
    }

    private static function render_documents( $orders ) {
        $groups = array( 'Facturas y documentos emitidos' => array(), 'Arte y documentos comerciales' => array() );
        foreach ( $orders as $order ) {
            foreach ( GE_WTP_Documents::get_documents( $order->get_id() ) as $document ) {
                if ( ! empty( $document['superseded_at'] ) ) { continue; }
                $group = ! empty( $document['issued_by_graphex'] ) ? 'Facturas y documentos emitidos' : 'Arte y documentos comerciales';
                $groups[ $group ][] = array( 'order' => $order, 'document' => $document );
            }
        }
        foreach ( $groups as $heading => $rows ) {
            echo '<h3>' . esc_html( $heading ) . '</h3>';
            if ( ! $rows ) { echo '<p class="ge-workspace-empty">Sin archivos en este grupo.</p>'; continue; }
            foreach ( $rows as $row ) {
                $document = $row['document']; $order = $row['order'];
                $label = GE_WTP_Documents::categories()[ $document['category'] ?? '' ] ?? 'Documento';
                echo '<a class="ge-workspace-row" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ) . '"><div><strong>' . esc_html( $label . ' ' . ( $document['document_number'] ?? $document['name'] ?? '' ) ) . '</strong><small>Pedido #' . esc_html( $order->get_id() ) . ' · ' . esc_html( $document['issue_date'] ?? $document['uploaded_at'] ?? '' ) . '</small></div><span>Ver pedido ↗</span></a>';
            }
        }
    }

    private static function render_timeline( $user, $quotes, $orders, $logs ) {
        $events = array( array( 'at' => $user->user_registered, 'title' => 'Cliente registrado', 'detail' => '' ) );
        foreach ( $quotes as $quote ) {
            $events[] = array( 'at' => $quote['captured_at'] ?? '', 'title' => 'Cotización · ' . ( $quote['title'] ?? $quote['reference'] ?? 'Sin título' ), 'detail' => 'Estado actual: ' . ucfirst( str_replace( '_', ' ', $quote['status'] ?? 'pendiente' ) ) );
            if ( empty( $quote['_quote_id'] ) ) { continue; }
            $labels = array( 'sent' => 'Presupuesto enviado', 'viewed' => 'Presupuesto visto', 'accepted' => 'Presupuesto aceptado', 'accepted_staff' => 'Aceptación comercial registrada', 'file_uploaded' => 'Archivo recibido', 'file_attached' => 'Archivo vinculado', 'receipt_uploaded' => 'Comprobante recibido', 'receipt_attached' => 'Comprobante vinculado', 'payment_started' => 'Pago iniciado', 'payment_confirmed' => 'Pago confirmado', 'converted' => 'Pedido creado' );
            foreach ( (array) get_post_meta( $quote['_quote_id'], '_ge_commercial_events', true ) as $event ) {
                if ( ! isset( $labels[ $event['event'] ?? '' ] ) ) { continue; }
                $events[] = array( 'at' => $event['at'] ?? '', 'title' => $labels[ $event['event'] ], 'detail' => $quote['title'] ?? '' );
            }
        }
        foreach ( $orders as $order ) {
            $events[] = array( 'at' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '', 'title' => 'Pedido #' . $order->get_id(), 'detail' => GE_WTP_Order_Lifecycle::label( $order ) );
            foreach ( GE_WTP_Documents::issued_documents( $order->get_id() ) as $document ) { $events[] = array( 'at' => $document['uploaded_at'] ?? '', 'title' => 'Documento emitido · pedido #' . $order->get_id(), 'detail' => $document['document_number'] ?? '' ); }
        }
        foreach ( $logs as $log ) { $events[] = array( 'at' => get_post_time( 'Y-m-d H:i:s', false, $log ), 'title' => 'Comunicación · ' . $log->post_title, 'detail' => 'sent' === get_post_meta( $log->ID, '_ge_email_result', true ) ? 'Enviado' : 'No enviado' ); }
        usort( $events, function ( $a, $b ) { return strcmp( $b['at'], $a['at'] ); } );
        foreach ( array_slice( $events, 0, 30 ) as $event ) { echo '<div class="ge-workspace-row"><div><strong>' . esc_html( $event['title'] ) . '</strong><small>' . esc_html( $event['at'] . ( $event['detail'] ? ' · ' . $event['detail'] : '' ) ) . '</small></div></div>'; }
    }

    public static function render( $id ) {
        $user = get_userdata( $id );
        if ( ! $user ) { echo '<div class="ge-admin-empty">Cliente inexistente.</div>'; return; }
        $quotes = array();
        foreach ( get_user_meta( $id ) as $key => $values ) {
            if ( 0 !== strpos( $key, 'ge_pending_quote_' ) || empty( $values[0] ) ) { continue; }
            $quote = maybe_unserialize( $values[0] );
            if ( is_array( $quote ) ) {
                $quote['_url'] = GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'legacy_user' => $id, 'legacy_key' => $key ) );
                $quotes[] = $quote;
            }
        }
        foreach ( get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 100, 'meta_key' => GE_WTP_Commercial_Quotes::CUSTOMER_META, 'meta_value' => $id ) ) as $post ) {
            $current = GE_WTP_Commercial_Quotes::get( $post->ID, get_current_user_id() );
            if ( is_wp_error( $current ) ) { continue; }
            $quotes[] = array(
                'title' => $current['number'], 'reference' => 'Presupuesto actual', 'captured_at' => $post->post_date, '_quote_id' => $current['id'],
                'status' => $current['status'], '_url' => GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'quote_id' => $current['id'] ) ),
                '_amount' => isset( $current['snapshot']['total_cents'] ) ? wp_strip_all_tags( wc_price( $current['snapshot']['total_cents'] / 100 ) ) : '',
            );
        }
        usort( $quotes, function ( $a, $b ) { return strcmp( $b['captured_at'] ?? '', $a['captured_at'] ?? '' ); } );
        $orders = wc_get_orders( array( 'customer_id' => $id, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
        $addresses = GE_WTP_Customers::addresses( $id );
        $billing = GE_WTP_Billing::profile( $id );
        $logs = GE_WTP_Notifications::get_logs_by_recipient( $user->user_email, 20 );
        $name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
        $portal = GE_WTP_Portal::preview_url( $id );
        ?>
        <div class="ge-workspace">
            <header class="ge-workspace-header"><div><a class="ge-workspace-back" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'customers' ) ); ?>">Clientes</a><p class="ge-workspace-eyebrow">Workspace de cliente · #<?php echo esc_html( $id ); ?></p><h1><?php echo esc_html( $name ); ?></h1><p><?php echo esc_html( $user->user_email ); ?><?php if ( get_user_meta( $id, '_ge_whatsapp', true ) ) : ?> · <?php echo esc_html( get_user_meta( $id, '_ge_whatsapp', true ) ); ?><?php endif; ?></p></div><div class="ge-workspace-actions"><a class="is-primary" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'new' => 1, 'customer_id' => $id ) ) ); ?>">Nuevo presupuesto</a><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'new', 'customer_id' => $id ) ) ); ?>">Nuevo pedido</a><a href="<?php echo esc_url( $portal ); ?>" target="_blank" rel="noopener">Vista del portal ↗</a></div></header>
            <?php $status = sanitize_key( wp_unslash( $_GET['workspace_status'] ?? '' ) ); if ( $status ) : ?><p class="ge-workspace-notice <?php echo 'saved' === $status ? '' : 'is-error'; ?>" role="status"><?php echo esc_html( 'saved' === $status ? 'Bloque guardado.' : 'No se pudo guardar el bloque. Revisá los datos.' ); ?></p><?php endif; ?>
            <div class="ge-workspace-layout"><aside class="ge-workspace-sidebar">
                <section class="ge-workspace-panel" id="ge-workspace-identity"><div class="ge-workspace-panel-head"><h2>Identidad y contacto</h2><small>Email verificado desde la cuenta</small></div><?php self::form_start( $id, 'identity' ); self::field( 'Nombre', 'first_name', $user->first_name ); self::field( 'Apellido', 'last_name', $user->last_name ); self::field( 'WhatsApp', 'whatsapp', get_user_meta( $id, '_ge_whatsapp', true ) ?: get_user_meta( $id, 'billing_phone', true ), 'tel' ); self::field( 'Contacto principal', 'contact_person', get_user_meta( $id, '_ge_contact_person', true ) ); self::form_end(); ?></section>
                <details class="ge-workspace-panel" id="ge-workspace-preferences"><summary>Preferencias</summary><?php self::form_start( $id, 'preferences' ); ?><label>Entrega habitual<select name="delivery_preference"><?php foreach ( array( '' => 'Sin preferencia', 'retiro' => 'Retiro', 'envio' => 'Envío', 'coordinar' => 'Coordinar' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_user_meta( $id, '_ge_delivery_preference', true ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><?php self::form_end(); ?></details>
                <details class="ge-workspace-panel" id="ge-workspace-internal"><summary>Información interna</summary><p>Solo visible para el equipo.</p><?php self::form_start( $id, 'internal' ); self::field( 'Etiquetas', 'internal_tags', get_user_meta( $id, '_ge_customer_tags', true ) ); ?><label class="ge-field-wide">Notas<textarea name="internal_notes" rows="4"><?php echo esc_textarea( get_user_meta( $id, '_ge_customer_internal_notes', true ) ); ?></textarea></label><?php self::form_end(); ?></details>
            </aside><main class="ge-workspace-main">
                <section class="ge-workspace-panel ge-workspace-activity"><div class="ge-workspace-panel-head"><div><p class="ge-workspace-eyebrow">Relación comercial</p><h2>Actividad comercial</h2></div><div class="ge-workspace-metrics"><span><b><?php echo esc_html( count( $quotes ) ); ?></b> presupuestos</span><span><b><?php echo esc_html( count( $orders ) ); ?></b> pedidos</span></div></div>
                    <nav class="ge-workspace-tabs" aria-label="Actividad comercial"><a href="#ge-workspace-quotes">Presupuestos</a><a href="#ge-workspace-orders">Pedidos</a><a href="#ge-workspace-documents">Documentos</a><a href="#ge-workspace-communications">Comunicaciones</a><a href="#ge-workspace-cart">Carrito</a><a href="#ge-workspace-timeline">Historial</a></nav>
                    <section class="ge-workspace-tab" id="ge-workspace-quotes"><div class="ge-workspace-subhead"><h3>Presupuestos</h3><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'quotes', array( 'new' => 1, 'customer_id' => $id ) ) ); ?>">Nueva cotización ↗</a></div><?php if ( ! $quotes ) : ?><p class="ge-workspace-empty">Todavía no hay presupuestos asociados.</p><?php else : foreach ( $quotes as $quote ) : ?><a class="ge-workspace-row" href="<?php echo esc_url( $quote['_url'] ); ?>"><div><strong><?php echo esc_html( $quote['title'] ?? $quote['reference'] ?? 'Cotización' ); ?></strong><small><?php echo esc_html( $quote['reference'] ?? '' ); ?> · <?php echo esc_html( $quote['captured_at'] ?? '' ); ?><?php if ( ! empty( $quote['_amount'] ) ) : ?> · <?php echo esc_html( $quote['_amount'] ); ?><?php endif; ?></small></div><span><?php echo esc_html( ucfirst( str_replace( '_', ' ', $quote['status'] ?? 'pendiente' ) ) ); ?> ↗</span></a><?php endforeach; endif; ?></section>
                    <section class="ge-workspace-tab" id="ge-workspace-orders"><div class="ge-workspace-subhead"><h3>Pedidos</h3><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'new', 'customer_id' => $id ) ) ); ?>">Nuevo pedido ↗</a></div><?php if ( ! $orders ) : ?><p class="ge-workspace-empty">Todavía no hay pedidos vinculados a esta cuenta.</p><?php else : foreach ( $orders as $order ) : ?><a class="ge-workspace-row" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); ?>"><div><strong><?php echo esc_html( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?></strong><small><?php echo esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) : '' ); ?> · <?php echo esc_html( GE_WTP_Order_Lifecycle::label( $order ) ); ?> · <?php echo esc_html( $order->get_payment_method_title() ?: 'Pago sin definir' ); ?></small></div><b><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></b></a><?php endforeach; endif; ?></section>
                    <section class="ge-workspace-tab" id="ge-workspace-documents"><?php self::render_documents( $orders ); ?></section>
                    <section class="ge-workspace-tab" id="ge-workspace-communications"><h3>Comunicaciones registradas</h3><?php if ( ! $logs ) : ?><p class="ge-workspace-empty">No hay mensajes registrados para este email.</p><?php else : foreach ( $logs as $log ) : ?><div class="ge-workspace-row"><div><strong><?php echo esc_html( $log->post_title ); ?></strong><small><?php echo esc_html( get_the_date( 'd/m/Y H:i', $log ) ); ?></small></div><span><?php echo esc_html( 'sent' === get_post_meta( $log->ID, '_ge_email_result', true ) ? 'Enviado' : 'No enviado' ); ?></span></div><?php endforeach; endif; ?></section>
                    <section class="ge-workspace-tab" id="ge-workspace-cart"><h3>Carrito y checkout</h3><p class="ge-workspace-empty">Aún no hay una fuente de carrito identificado certificada para esta ficha. No se atribuyen sesiones anónimas al cliente.</p></section>
                    <section class="ge-workspace-tab" id="ge-workspace-timeline"><h3>Historial</h3><?php self::render_timeline( $user, $quotes, $orders, $logs ); ?></section>
                </section>
                <section class="ge-workspace-panel" id="ge-workspace-billing"><div class="ge-workspace-panel-head"><div><h2>Perfiles de facturación</h2><p>Opcionales. Cada perfil se guarda por separado.</p></div></div><details class="ge-workspace-item"><summary><strong>Perfil principal</strong><span><?php echo esc_html( $billing['legal_name'] ?: 'Sin datos fiscales' ); ?></span></summary><?php self::form_start( $id, 'billing' ); self::field( 'Razón social', 'legal_name', $billing['legal_name'] ); self::field( 'CUIT', 'cuit', $billing['cuit'] ); self::field( 'Domicilio fiscal', 'fiscal_address', $billing['fiscal_address'] ); self::field( 'Email de facturación', 'billing_email', $billing['billing_email'], 'email' ); ?><label>Condición fiscal<select name="vat_status"><?php foreach ( array( '' => 'Seleccionar', 'registered' => 'Responsable inscripto', 'monotributo' => 'Monotributista', 'exempt' => 'Exento', 'final_consumer' => 'Consumidor final' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $billing['vat_status'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Modalidad<select name="billing_mode"><option value="common" <?php selected( $billing['billing_mode'], 'common' ); ?>>Común</option><option value="invoice_a" <?php selected( $billing['billing_mode'], 'invoice_a' ); ?>>Factura A, si está disponible</option></select></label><label class="ge-field-wide"><input type="checkbox" name="billing_verify" value="1" <?php checked( ! empty( $billing['verified_at'] ) ); ?>> Datos verificados</label><?php self::form_end(); ?></details><?php GE_WTP_Customer_Branches::render_staff( $id ); ?></section>
                <section class="ge-workspace-panel" id="ge-workspace-delivery"><div class="ge-workspace-panel-head"><div><h2>Direcciones de entrega</h2><p>Hasta cuatro destinos. Cada uno se guarda por separado.</p></div><b><?php echo esc_html( count( $addresses ) ); ?></b></div><?php foreach ( $addresses as $index => $address ) { self::address_form( $id, $address, $index ); } if ( count( $addresses ) < 4 ) { self::address_form( $id ); } ?></section>
                <section class="ge-workspace-panel ge-workspace-portal"><h2>Portal del cliente</h2><p>La vista previa administrativa es de solo lectura. Los presupuestos, pedidos y documentos publicados se abren desde el portal.</p><a href="<?php echo esc_url( $portal ); ?>" target="_blank" rel="noopener">Abrir vista previa ↗</a><?php if ( isset( $_GET['invite_status'] ) ) : ?><p role="status"><?php echo 'sent' === sanitize_key( wp_unslash( $_GET['invite_status'] ) ) ? 'Invitación registrada.' : 'No se pudo enviar la invitación.'; ?></p><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_staff_invite_customer"><input type="hidden" name="user_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'ge_staff_invite_customer_' . $id ); ?><button type="submit">Enviar acceso al portal</button></form></section>
            </main></div>
        </div>
        <?php
    }
}
