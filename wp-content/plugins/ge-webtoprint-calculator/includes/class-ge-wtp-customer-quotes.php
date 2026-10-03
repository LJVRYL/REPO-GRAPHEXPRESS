<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Customer acceptance and artwork hand-off for quotes imported into CRM user meta. */
final class GE_WTP_Customer_Quotes {
    const PREFIX = 'ge_pending_quote_';
    const KEY_META = '_ge_customer_quote_key';
    const STAGE_META = '_ge_customer_quote_stage';

    public static function init() {
        add_action( 'admin_post_ge_customer_quote_approve', array( __CLASS__, 'handle_approve' ) );
        add_action( 'admin_post_ge_customer_quote_submit', array( __CLASS__, 'handle_submit' ) );
    }

    private static function records( $user_id ) {
        $records = array();
        foreach ( get_user_meta( absint( $user_id ) ) as $key => $values ) {
            if ( 0 !== strpos( $key, self::PREFIX ) || empty( $values[0] ) ) { continue; }
            $quote = maybe_unserialize( $values[0] );
            if ( is_array( $quote ) ) { $records[ $key ] = $quote; }
        }
        uasort( $records, function ( $a, $b ) { return strcmp( (string) ( $b['captured_at'] ?? '' ), (string) ( $a['captured_at'] ?? '' ) ); } );
        return $records;
    }

    private static function customer_action_allowed() {
        return is_user_logged_in() && GE_WTP_Portal::is_customer_user() && ! GE_WTP_Portal::is_staff_preview();
    }

    private static function option_label( $option ) {
        return sanitize_text_field( $option['label'] ?? number_format_i18n( absint( $option['quantity'] ?? 0 ) ) . ' unidades' );
    }

    private static function option_details( $quote, $option ) {
        return sanitize_textarea_field( $option['details'] ?? $quote['details'] ?? '' );
    }

    private static function quote_revision( $quote ) {
        return wp_hash( wp_json_encode( array( $quote['title'] ?? '', $quote['details'] ?? '', $quote['options'] ?? array(), $quote['items'] ?? array() ) ) );
    }

    public static function is_quote_order( $order ) {
        return $order instanceof WC_Order && (bool) $order->get_meta( self::KEY_META, true );
    }

    public static function stage_label( $order ) {
        $labels = array( 'files_pending' => 'Esperando archivos', 'submitted' => 'En revisión', 'approved_for_production' => 'Aprobado para producción' );
        return $labels[ $order->get_meta( self::STAGE_META, true ) ] ?? 'Presupuesto';
    }

    private static function order_for_quote( $user_id, $key, $quote ) {
        $order_id = absint( $quote['order_id'] ?? 0 );
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( self::is_quote_order( $order ) && (int) $order->get_customer_id() === (int) $user_id && $key === $order->get_meta( self::KEY_META, true ) ) { return $order; }
        $orders = wc_get_orders( array( 'customer_id' => absint( $user_id ), 'limit' => 1, 'meta_key' => self::KEY_META, 'meta_value' => $key ) );
        return $orders ? $orders[0] : false;
    }

    public static function render_dashboard() {
        $records = self::records( GE_WTP_Portal::portal_customer_id() );
        if ( ! $records ) { return; }
        $preview = GE_WTP_Portal::is_staff_preview();
        echo '<section class="ge-customer-quotes" aria-labelledby="ge-quotes-title"><div class="ge-panel-heading"><div><span class="ge-eyebrow">Propuestas para vos</span><h2 id="ge-quotes-title">Tus presupuestos</h2></div></div>';
        foreach ( $records as $key => $quote ) {
            $options = array_values( array_filter( (array) ( $quote['options'] ?? array() ), 'is_array' ) );
            $items = array_values( array_filter( (array) ( $quote['items'] ?? array() ), 'is_array' ) );
            $order = self::order_for_quote( GE_WTP_Portal::portal_customer_id(), $key, $quote );
            $stage = $order ? $order->get_meta( self::STAGE_META, true ) : 'sent';
            $labels = array( 'sent' => 'Esperando tu respuesta', 'files_pending' => 'Presupuesto aprobado · faltan archivos', 'submitted' => 'En revisión por Graph Express', 'approved_for_production' => 'Aprobado para producción' );
            echo '<article class="ge-customer-quote ge-panel"><div class="ge-customer-quote-top"><span class="ge-eyebrow">' . esc_html( $quote['reference'] ?? 'Presupuesto' ) . '</span><strong class="ge-customer-quote-state">' . esc_html( $labels[ $stage ] ?? 'En seguimiento' ) . '</strong></div>';
            echo '<h3>' . esc_html( $quote['title'] ?? 'Trabajo a cotizar' ) . '</h3><p>' . esc_html( $quote['details'] ?? '' ) . '</p>';
            if ( $items ) {
                echo '<ul class="ge-customer-quote-options">';
                foreach ( $items as $item ) { echo '<li><span><b>' . esc_html( $item['title'] ?? 'Trabajo gráfico' ) . '</b><br>' . esc_html( $item['details'] ?? '' ) . '</span><strong>' . wp_kses_post( wc_price( (float) ( $item['total'] ?? 0 ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</strong></li>'; }
                echo '</ul><p><strong>Total: ' . wp_kses_post( wc_price( array_sum( array_map( function ( $item ) { return (float) ( $item['total'] ?? 0 ); }, $items ) ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</strong></p>';
            } elseif ( $options ) {
                echo '<ul class="ge-customer-quote-options">';
                foreach ( $options as $option ) { echo '<li><span><b>' . esc_html( self::option_label( $option ) ) . '</b><br>' . esc_html( self::option_details( $quote, $option ) ) . '</span><strong>' . wp_kses_post( wc_price( (float) ( $option['total'] ?? 0 ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</strong></li>'; }
                echo '</ul>';
            }
            if ( $order ) {
                echo '<a class="ge-button ge-button-primary" href="' . esc_url( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ) ) . '">' . esc_html( 'approved_for_production' === $stage ? 'Ver trabajo' : ( 'submitted' === $stage ? 'Ver estado y archivos' : 'Cargar archivos y continuar' ) ) . '</a>';
            } elseif ( $preview ) {
                echo '<p class="ge-customer-quote-help">En la vista previa no se puede aceptar el presupuesto. Jorge verá esta acción al ingresar con su cuenta.</p>';
            } elseif ( ( $items || $options ) && 'enviada' === ( $quote['status'] ?? '' ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_customer_quote_approve"><input type="hidden" name="quote_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="quote_revision" value="' . esc_attr( self::quote_revision( $quote ) ) . '">';
                wp_nonce_field( 'ge_customer_quote_approve_' . $key );
                if ( ! $items && count( $options ) > 1 ) { echo '<label>Elegí una alternativa<select name="option_index">'; foreach ( $options as $index => $option ) { echo '<option value="' . esc_attr( $index ) . '">' . esc_html( self::option_label( $option ) ) . ' · ' . esc_html( wp_strip_all_tags( wc_price( (float) ( $option['total'] ?? 0 ), array( 'currency' => 'ARS' ) ) ) ) . ' + IVA</option>'; } echo '</select></label>'; }
                else { echo '<input type="hidden" name="option_index" value="0">'; }
                echo '<label class="ge-customer-quote-consent"><input type="checkbox" name="quote_confirm" value="1" required><span>Revisé las especificaciones y acepto este presupuesto más IVA. La producción comenzará solo después de enviar mis archivos y de la aprobación de Graph Express.</span></label><button class="ge-button ge-button-primary" type="submit">Aprobar presupuesto</button></form>';
            }
            echo '</article>';
        }
        echo '</section>';
    }

    public static function handle_approve() {
        if ( ! self::customer_action_allowed() ) { wp_die( 'Acceso denegado.', 403 ); }
        $user_id = get_current_user_id();
        $key = sanitize_key( wp_unslash( $_POST['quote_key'] ?? '' ) );
        if ( 0 !== strpos( $key, self::PREFIX ) ) { wp_die( 'Presupuesto inválido.', 400 ); }
        check_admin_referer( 'ge_customer_quote_approve_' . $key );
        if ( empty( $_POST['quote_confirm'] ) ) { wp_die( 'Confirmá el presupuesto para continuar.', 400 ); }
        $quote = get_user_meta( $user_id, $key, true );
        if ( ! is_array( $quote ) ) { wp_die( 'Presupuesto no disponible.', 404 ); }
        $revision = sanitize_text_field( wp_unslash( $_POST['quote_revision'] ?? '' ) );
        if ( ! $revision || ! hash_equals( self::quote_revision( $quote ), $revision ) ) { wp_die( 'El presupuesto cambió. Volvé a revisarlo antes de aprobarlo.', 409 ); }
        $existing = self::order_for_quote( $user_id, $key, $quote );
        if ( $existing ) { wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $existing->get_id() ) ) ); exit; }
        if ( 'enviada' !== ( $quote['status'] ?? '' ) ) { wp_die( 'Este presupuesto ya no está disponible para aprobación.', 409 ); }
        $options = array_values( array_filter( (array) ( $quote['options'] ?? array() ), 'is_array' ) );
        $index = absint( $_POST['option_index'] ?? 0 );
        $option = $options[ $index ] ?? array();
        $quantity = absint( $option['quantity'] ?? 0 );
        $total = (float) ( $option['total'] ?? 0 );
        if ( ! $quantity || $total <= 0 ) { wp_die( 'La opción elegida no tiene precio válido.', 400 ); }
        $item_title = sanitize_text_field( $option['title'] ?? $quote['title'] ?? 'Trabajo gráfico' );
        $item_details = self::option_details( $quote, $option );
        $quote_items = array_values( array_filter( (array) ( $quote['items'] ?? array() ), 'is_array' ) );
        $lines = array();
        if ( $quote_items ) {
            foreach ( $quote_items as $quote_item ) {
                $line = array( 'title' => sanitize_text_field( $quote_item['title'] ?? '' ), 'details' => sanitize_textarea_field( $quote_item['details'] ?? '' ), 'quantity' => absint( $quote_item['quantity'] ?? 0 ), 'total' => (float) ( $quote_item['total'] ?? 0 ) );
                if ( ! $line['title'] || ! $line['quantity'] || $line['total'] <= 0 ) { wp_die( 'El presupuesto contiene un ítem inválido.', 400 ); }
                $lines[] = $line;
            }
            if ( abs( array_sum( array_column( $lines, 'total' ) ) - $total ) > 0.01 ) { wp_die( 'El total del presupuesto no coincide con sus ítems.', 400 ); }
        } else {
            $lines[] = array( 'title' => $item_title, 'details' => $item_details, 'quantity' => $quantity, 'total' => $total );
        }
        $lock = 'ge_customer_quote_lock_' . $user_id . '_' . md5( $key );
        if ( ! add_option( $lock, time(), '', 'no' ) ) { wp_die( 'Estamos procesando este presupuesto. Volvé a cargar la página.', 409 ); }
        try {
            $existing = self::order_for_quote( $user_id, $key, $quote );
            if ( $existing ) { $order = $existing; }
            else {
                $user = get_userdata( $user_id );
                $order = wc_create_order( array( 'customer_id' => $user_id ) );
                if ( is_wp_error( $order ) ) { throw new RuntimeException( $order->get_error_message() ); }
                $order->set_created_via( 'ge_customer_quote' );
                $order->set_currency( 'ARS' );
                $order->set_billing_first_name( $user->first_name );
                $order->set_billing_last_name( $user->last_name );
                $order->set_billing_email( $user->user_email );
                $order->set_billing_company( get_user_meta( $user_id, 'billing_company', true ) );
                $order->set_billing_phone( get_user_meta( $user_id, '_ge_whatsapp', true ) ?: get_user_meta( $user_id, 'billing_phone', true ) );
                $accepted_items = array();
                foreach ( $lines as $line ) {
                    $item = new WC_Order_Item_Product();
                    $item->set_name( $line['title'] );
                    $item->set_quantity( $line['quantity'] );
                    $item->set_subtotal( $line['total'] );
                    $item->set_total( $line['total'] );
                    $item->add_meta_data( 'Especificaciones', $line['details'], true );
                    $item->update_meta_data( '_ge_item_status', 'pending' );
                    $order->add_item( $item );
                    $accepted_items[] = hash( 'sha256', wp_json_encode( array( $line['title'], $line['details'], $line['quantity'], $line['total'] ) ) );
                }
                $order->update_meta_data( '_ge_quote', 'yes' );
                $order->update_meta_data( self::KEY_META, $key );
                $order->update_meta_data( self::STAGE_META, 'files_pending' );
                $order->update_meta_data( '_ge_customer_quote_accepted_base_total', $total );
                $order->update_meta_data( '_ge_customer_quote_accepted_item', hash( 'sha256', wp_json_encode( array( $item_title, $item_details, $quantity, $total ) ) ) );
                if ( $quote_items ) { $order->update_meta_data( '_ge_customer_quote_accepted_items', $accepted_items ); }
                $order->update_meta_data( '_ge_customer_quote_option_label', self::option_label( $option ) );
                $order->update_meta_data( '_ge_customer_quote_tax_note', '+ IVA, según presupuesto enviado' );
                $order->update_meta_data( '_ge_customer_quote_approved_at', current_time( 'mysql' ) );
                $order->calculate_totals( false );
                $order->add_order_note( 'El cliente aceptó el presupuesto original más IVA. Falta recibir archivos y aprobación técnica interna.' );
                $order->save();
            }
            $quote['status'] = 'aprobada_cliente';
            $quote['order_id'] = $order->get_id();
            $quote['approved_at'] = current_time( 'mysql' );
            update_user_meta( $user_id, $key, $quote );
        } catch ( Throwable $error ) {
            error_log( 'Graph Express quote approval failed: ' . $error->getMessage() );
            wp_die( 'No pudimos registrar la aprobación. Contactá a Graph Express.', 500 );
        } finally {
            delete_option( $lock );
        }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id(), 'ge_notice' => 'quote-approved' ) ) );
        exit;
    }

    private static function has_item_artwork( $order ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $found = false;
            foreach ( $documents as $document ) {
                if ( 'arte' === ( $document['category'] ?? '' ) && (int) ( $document['order_item_id'] ?? 0 ) === (int) $item_id ) { $found = true; break; }
            }
            if ( ! $found ) { return false; }
        }
        return (bool) $order->get_items( 'line_item' );
    }

    public static function render_order_step( $order ) {
        if ( ! self::is_quote_order( $order ) ) { return; }
        $stage = $order->get_meta( self::STAGE_META, true );
        echo '<section class="ge-customer-quote-step ge-panel"><span class="ge-eyebrow">Presupuesto aceptado</span><h3>Archivos y revisión</h3><p>Importe aceptado: <strong>' . wp_kses_post( wc_price( (float) $order->get_meta( '_ge_customer_quote_accepted_base_total' ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</strong>. El importe y las especificaciones corresponden al correo original; Graph Express confirmará impuestos, archivo apto y fecha antes de producir.</p>';
        if ( 'files_pending' === $stage ) {
            if ( self::has_item_artwork( $order ) ) {
                if ( ! GE_WTP_Portal::is_staff_preview() ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_customer_quote_submit"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
                    wp_nonce_field( 'ge_customer_quote_submit_' . $order->get_id() );
                    echo '<label class="ge-customer-quote-consent"><input type="checkbox" name="files_confirm" value="1" required><span>Confirmo que estos son los archivos que quiero enviar para revisión de Graph Express.</span></label><button class="ge-button ge-button-primary" type="submit">Enviar presupuesto y archivos para revisión</button></form>';
                }
            } else { echo '<p class="ge-customer-quote-help">Cargá el archivo para imprimir en el producto de este presupuesto. Después podrás enviarlo para revisión.</p>'; }
        } elseif ( 'submitted' === $stage ) { echo '<p class="ge-customer-quote-help">Tu presupuesto y tus archivos ya están en revisión. Todavía no se inició la producción.</p>'; }
        elseif ( 'approved_for_production' === $stage ) { echo '<p class="ge-customer-quote-help">Graph Express aprobó el trabajo para producción. Podés seguir su avance desde tus pedidos.</p>'; }
        echo '</section>';
    }

    public static function handle_submit() {
        if ( ! self::customer_action_allowed() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_customer_quote_submit_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! self::is_quote_order( $order ) || (int) $order->get_customer_id() !== get_current_user_id() ) { wp_die( 'Presupuesto no disponible.', 403 ); }
        if ( 'submitted' === $order->get_meta( self::STAGE_META, true ) ) { wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order_id ) ) ); exit; }
        if ( 'files_pending' !== $order->get_meta( self::STAGE_META, true ) || ! self::has_item_artwork( $order ) || empty( $_POST['files_confirm'] ) ) { wp_die( 'Confirmá y cargá los archivos antes de enviarlos.', 400 ); }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $sources = (array) $item->get_meta( '_ge_item_artwork_sources', true );
            if ( ! $sources ) { wp_die( 'El archivo debe estar asociado al producto.', 400 ); }
            $item->update_meta_data( '_ge_item_artwork_customer_approval', array( 'approved' => true, 'time' => time(), 'user_id' => get_current_user_id(), 'method' => 'customer-portal' ) );
            $item->save();
        }
        $order->update_meta_data( self::STAGE_META, 'submitted' );
        $order->update_meta_data( '_ge_customer_quote_submitted_at', current_time( 'mysql' ) );
        $order->set_status( 'ge-enviado', 'El cliente envió el presupuesto aprobado y los archivos para revisión.' );
        $order->save();
        $key = $order->get_meta( self::KEY_META, true );
        $quote = get_user_meta( get_current_user_id(), $key, true );
        if ( is_array( $quote ) ) { $quote['status'] = 'en_revision'; update_user_meta( get_current_user_id(), $key, $quote ); }
        if ( class_exists( 'GE_WTP_Notification_Center' ) ) {
            GE_WTP_Notification_Center::send_internal( 'new_order', 'Presupuesto y archivos para revisar · ' . $order->get_billing_email(), '<p>El cliente aceptó el presupuesto y cargó los archivos. Revisá el precio, la versión y el arte antes de aprobar producción.</p><p><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order_id ) ) ) . '">Abrir presupuesto</a></p>', $order_id );
        }
        wp_safe_redirect( GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order_id, 'ge_notice' => 'quote-submitted' ) ) );
        exit;
    }

    public static function can_staff_release( $order ) {
        if ( ! self::is_quote_order( $order ) || 'submitted' !== $order->get_meta( self::STAGE_META, true ) ) { return false; }
        if ( abs( (float) $order->get_total() - (float) $order->get_meta( '_ge_customer_quote_accepted_base_total' ) ) > 0.01 ) { return false; }
        if ( ! self::has_item_artwork( $order ) ) { return false; }
        $accepted_items = $order->get_meta( '_ge_customer_quote_accepted_items', true );
        $order_items = array_values( $order->get_items( 'line_item' ) );
        if ( is_array( $accepted_items ) && count( $accepted_items ) !== count( $order_items ) ) { return false; }
        foreach ( $order_items as $index => $item ) {
            $accepted = is_array( $accepted_items ) ? ( $accepted_items[ $index ] ?? '' ) : $order->get_meta( '_ge_customer_quote_accepted_item', true );
            $current = hash( 'sha256', wp_json_encode( array( $item->get_name(), $item->get_meta( 'Especificaciones', true ), (int) $item->get_quantity(), (float) $item->get_total() ) ) );
            if ( ! $accepted || ! hash_equals( $accepted, $current ) ) { return false; }
            if ( ! GE_WTP_Artwork_Library::item_ready_for_production( $item, $order ) ) { return false; }
        }
        return true;
    }

    public static function render_staff_review( $order ) {
        if ( ! self::is_quote_order( $order ) ) { return; }
        $stage = $order->get_meta( self::STAGE_META, true );
        $labels = array( 'files_pending' => 'El cliente aceptó el presupuesto y está cargando archivos.', 'submitted' => 'El cliente envió sus archivos. Falta el control técnico de Graph Express.', 'approved_for_production' => 'La orden de trabajo ya fue aprobada para producción.' );
        echo '<section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Presupuesto del cliente</span><h2>Revisión antes de producir</h2></div></div><p>' . esc_html( $labels[ $stage ] ?? 'En seguimiento.' ) . '</p><p>Importe aceptado: <strong>' . wp_kses_post( wc_price( (float) $order->get_meta( '_ge_customer_quote_accepted_base_total' ), array( 'currency' => 'ARS' ) ) ) . ' + IVA</strong>. Si cambiás precio o especificaciones, obtené una nueva aprobación del cliente.</p>';
        if ( 'submitted' === $stage ) {
            echo '<p><a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ) . '">Revisar archivos y control técnico</a></p>';
            if ( self::can_staff_release( $order ) ) {
                echo '<p>El arte tiene ambas aprobaciones. Podés generar la orden de trabajo.</p>';
                foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ge_production_create_item_order"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '"><input type="hidden" name="item_id" value="' . esc_attr( $item_id ) . '">';
                    wp_nonce_field( 'ge_production_create_item_order_' . $order->get_id() . '_' . $item_id );
                    echo '<button class="ge-staff-button" type="submit">Aprobar y generar orden de trabajo</button></form>';
                }
            }
            else { echo '<p>La producción sigue bloqueada hasta asignar el archivo exacto y completar el control técnico.</p>'; }
        }
        if ( 'approved_for_production' === $stage ) {
            $work_order_id = absint( $order->get_meta( '_ge_customer_quote_work_order_id', true ) );
            if ( $work_order_id ) { echo '<p><a class="ge-staff-button" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $work_order_id ) ) ) . '">Abrir orden de trabajo</a></p>'; }
        }
        echo '</section>';
    }

    public static function mark_production_approved( $source_order, $work_order ) {
        if ( ! self::is_quote_order( $source_order ) || ! $work_order instanceof WC_Order ) { return; }
        $source_order->update_meta_data( self::STAGE_META, 'approved_for_production' );
        $source_order->update_meta_data( '_ge_customer_quote_work_order_id', $work_order->get_id() );
        $source_order->save();
        $key = $source_order->get_meta( self::KEY_META, true );
        $quote = get_user_meta( $source_order->get_customer_id(), $key, true );
        if ( is_array( $quote ) ) { $quote['status'] = 'aprobada_produccion'; $quote['work_order_id'] = $work_order->get_id(); update_user_meta( $source_order->get_customer_id(), $key, $quote ); }
    }
}
