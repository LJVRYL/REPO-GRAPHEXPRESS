<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Production {
    const EVENT_POST_TYPE = 'ge_prod_event';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_event_type' ), 8 );
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'checkout_order' ), 40, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_order' ), 40, 1 );
        add_action( 'admin_post_ge_production_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_ge_production_event', array( __CLASS__, 'handle_event' ) );
        add_action( 'admin_post_ge_production_event_status', array( __CLASS__, 'handle_event_status' ) );
        add_action( 'admin_post_ge_production_sheet', array( __CLASS__, 'handle_sheet' ) );
        add_action( 'admin_post_nopriv_ge_production_sheet', array( __CLASS__, 'handle_sheet' ) );
    }

    public static function register_event_type() {
        register_post_type( self::EVENT_POST_TYPE, array( 'public' => false, 'show_ui' => false, 'supports' => array( 'title', 'editor' ) ) );
    }

    public static function suppliers() {
        $suppliers = array(
            'druck'               => array( 'name' => 'Druck', 'detail' => 'Digital Express y ventana offset de viernes 12:00 a martes 21:00.' ),
            'mardones'            => array( 'name' => 'Mardones / Sur Colors', 'detail' => 'Offset de martes 21:00 a viernes 12:00. Entrega del proveedor: martes por la noche.' ),
            'bandurria'           => array( 'name' => 'Bandurria', 'detail' => 'Gran formato. Banners y lonas: 2 días hábiles; otros trabajos: 5 días hábiles.' ),
            'elementi'            => array( 'name' => 'Elementi', 'detail' => 'Merchandising. Productos y tiempos a configurar.' ),
            'conquer'             => array( 'name' => 'Conquer', 'detail' => 'Merchandising. Productos y tiempos a configurar.' ),
            'msbags'              => array( 'name' => 'MS Bags', 'detail' => 'Bolsas de friselina, lienzo y e-commerce. Producción estimada: 7 a 10 días hábiles.', 'email' => 'ventasmsbags@gmail.com', 'whatsapp' => '5491161835857' ),
            'merch-other'         => array( 'name' => 'Merchandising · tercer proveedor', 'detail' => 'Proveedor adicional de merchandising pendiente de identificar.' ),
            'sublimation-a'       => array( 'name' => 'Sublimación · proveedor 1', 'detail' => 'Banderas y productos sublimados. Nombre y tiempos a configurar.' ),
            'sublimation-b'       => array( 'name' => 'Sublimación · proveedor 2', 'detail' => 'Banderas y productos sublimados. Nombre y tiempos a configurar.' ),
            'merch-pending'       => array( 'name' => 'Merchandising · a definir', 'detail' => 'Pendiente de asignar entre Elementi, Conquer u otro proveedor.' ),
            'sublimation-pending' => array( 'name' => 'Sublimación · a definir', 'detail' => 'Pendiente de cargar los dos proveedores de banderas y sublimados.' ),
            'internal'            => array( 'name' => 'Producción interna', 'detail' => 'Trabajo realizado en Graph Express.' ),
            'multiple'            => array( 'name' => 'Múltiples proveedores', 'detail' => 'El pedido combina productos de más de un circuito.' ),
            'pending'             => array( 'name' => 'Proveedor a definir', 'detail' => 'Requiere asignación manual.' ),
        );
        $saved = get_option( 'ge_wtp_supplier_profiles', array() );
        foreach ( (array) $saved as $key => $profile ) {
            if ( 0 !== strpos( (string) $key, 'custom-' ) || ! is_array( $profile ) ) { continue; }
            $suppliers[ sanitize_key( $key ) ] = array(
                'name'   => sanitize_text_field( $profile['name'] ?? 'Nuevo proveedor' ),
                'detail' => sanitize_textarea_field( $profile['notes'] ?? 'Proveedor agregado manualmente.' ),
            );
        }
        return $suppliers;
    }

    public static function statuses() {
        return array( 'approved' => 'Aprobado', 'production' => 'En producción', 'ready' => 'Listo para entrega' );
    }

    public static function priorities() {
        return array( 'normal' => 'Normal', 'urgent' => 'Urgente', 'critical' => 'Crítica' );
    }

    public static function checkout_order( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) { $order = wc_get_order( $order_id ); }
        if ( $order ) { self::ensure_order( $order ); }
    }

    public static function store_api_order( $order ) {
        if ( $order instanceof WC_Order ) { self::ensure_order( $order ); }
    }

    public static function ensure_order( $order ) {
        if ( ! $order instanceof WC_Order || $order->get_meta( '_ge_production_initialized' ) ) { return; }
        $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : current_time( 'timestamp' );
        $assignments = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $assignment = self::assignment_for_item( $item, $created );
            $assignments[] = $assignment;
            $item->update_meta_data( '_ge_production_supplier', $assignment['supplier'] );
            $item->update_meta_data( '_ge_production_promised_date', $assignment['date'] );
            $item->save();
        }
        if ( ! $assignments ) { $assignments[] = array( 'supplier' => 'pending', 'date' => self::business_date( $created, 5 ), 'reason' => 'Pedido sin productos reconocidos.' ); }
        $supplier_keys = array_values( array_unique( wp_list_pluck( $assignments, 'supplier' ) ) );
        $supplier = 1 === count( $supplier_keys ) ? $supplier_keys[0] : 'multiple';
        $dates = array_filter( wp_list_pluck( $assignments, 'date' ) ); sort( $dates );
        $date = $dates ? end( $dates ) : self::business_date( $created, 5 );
        $order->update_meta_data( '_ge_production_supplier', $supplier );
        $order->update_meta_data( '_ge_production_promised_date', $date );
        $order->update_meta_data( '_ge_estimated_date', $order->get_meta( '_ge_estimated_date' ) ?: $date );
        $order->update_meta_data( '_ge_production_status', 'approved' );
        $order->update_meta_data( '_ge_production_priority', 'normal' );
        $order->update_meta_data( '_ge_production_assignment_reason', implode( ' ', array_unique( wp_list_pluck( $assignments, 'reason' ) ) ) );
        $order->update_meta_data( '_ge_production_processes', self::default_processes( $supplier ) );
        $order->update_meta_data( '_ge_production_initialized', current_time( 'mysql' ) );
        $order->save();
        if ( class_exists( 'GE_WTP_Supplier_Dispatch' ) ) { GE_WTP_Supplier_Dispatch::maybe_auto_dispatch( $order ); }
    }

    private static function assignment_for_item( $item, $created ) {
        $product_id = $item->get_product_id();
        $source = $product_id ? strtolower( (string) get_post_meta( $product_id, '_ge_supplier_source', true ) ) : '';
        $catalog_key = $product_id ? strtolower( (string) get_post_meta( $product_id, '_ge_public_catalog_key', true ) ) : '';
        $name = strtolower( remove_accents( $item->get_name() ) );
        $categories = $product_id ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) ) : array();
        $categories = is_wp_error( $categories ) ? array() : $categories;
        if ( false !== strpos( $source, 'ms bags' ) || 0 === strpos( $catalog_key, 'msbags-' ) || in_array( 'bolsas', $categories, true ) ) {
            return array( 'supplier' => 'msbags', 'date' => self::business_date( $created, 10 ), 'reason' => 'Producto de bolsas asignado a MS Bags con una previsión conservadora de 10 días hábiles.' );
        }
        $is_sublimation = false !== strpos( $source, 'windbanner' )
            || false !== strpos( $source, 'sublim' )
            || 0 === strpos( $catalog_key, 'windbanner' )
            || preg_match( '/windflag|windbanner|bandera|sublim/', $name );
        if ( $is_sublimation ) {
            return array( 'supplier' => 'sublimation-pending', 'date' => self::business_date( $created, 5 ), 'reason' => 'Banderas o sublimación detectadas; proveedor pendiente.' );
        }
        $is_offset = false !== strpos( $source, 'mardones' ) || 0 === strpos( $catalog_key, 'mardones-' ) || in_array( 'imprenta-offset', $categories, true );
        if ( $is_offset ) { return self::offset_assignment( $created ); }
        if ( false !== strpos( $source, 'druck' ) || in_array( 'imprenta-digital', $categories, true ) ) { return array( 'supplier' => 'druck', 'date' => self::business_date( $created, 2 ), 'reason' => 'Producto digital asignado a Druck con 2 días hábiles.' ); }
        if ( false !== strpos( $source, 'bandurria' ) || in_array( 'gran-formato', $categories, true ) ) {
            $fast = preg_match( '/banner|lona/', $name );
            return array( 'supplier' => 'bandurria', 'date' => self::business_date( $created, $fast ? 2 : 5 ), 'reason' => $fast ? 'Banner o lona Bandurria: mínimo 2 días hábiles.' : 'Gran formato Bandurria: 5 días hábiles.' );
        }
        if ( in_array( 'merchandising', $categories, true ) || preg_match( '/bolsa|lapicera|botella|merch/', $name ) ) { return array( 'supplier' => 'merch-pending', 'date' => self::business_date( $created, 5 ), 'reason' => 'Merchandising detectado; proveedor pendiente.' ); }
        if ( preg_match( '/lona|banner|adhesivo|cartel|fachada|totem|caballete|display|isla|tabla/', $name ) ) { return array( 'supplier' => 'bandurria', 'date' => self::business_date( $created, preg_match( '/lona|fachada|banner/', $name ) ? 2 : 5 ), 'reason' => preg_match( '/lona|banner/', $name ) ? 'Banner o lona manual asignado a Bandurria: mínimo 2 días hábiles.' : 'Producto corporativo asignado inicialmente a Bandurria.' ); }
        if ( $order_key = $item->get_meta( '_ge_product_key' ) ) {
            if ( 'windflag' === $order_key ) { return array( 'supplier' => 'sublimation-pending', 'date' => self::business_date( $created, 5 ), 'reason' => 'Windflag: proveedor de sublimación pendiente.' ); }
            return array( 'supplier' => 'bandurria', 'date' => self::business_date( $created, preg_match( '/lona|fachada/', $name ) ? 2 : 5 ), 'reason' => 'Producto corporativo asignado inicialmente a Bandurria.' );
        }
        return array( 'supplier' => 'pending', 'date' => self::business_date( $created, 5 ), 'reason' => 'Producto sin regla automática.' );
    }

    private static function offset_assignment( $created ) {
        $day = (int) wp_date( 'N', $created, wp_timezone() );
        $time = (int) wp_date( 'Gi', $created, wp_timezone() );
        $mardones = ( 2 === $day && $time >= 2100 ) || in_array( $day, array( 3, 4 ), true ) || ( 5 === $day && $time < 1200 );
        $date = ( new DateTimeImmutable( '@' . $created ) )->setTimezone( wp_timezone() );
        if ( $mardones ) { return array( 'supplier' => 'mardones', 'date' => $date->modify( 'next tuesday' )->modify( '+1 day' )->format( 'Y-m-d' ), 'reason' => 'Ventana offset martes 21:00 a viernes 12:00: Mardones, disponible el miércoles posterior.' ); }
        return array( 'supplier' => 'druck', 'date' => $date->modify( 'next friday' )->modify( '+3 days' )->format( 'Y-m-d' ), 'reason' => 'Ventana offset viernes 12:00 a martes 21:00: Druck, disponible el lunes posterior.' );
    }

    private static function business_date( $timestamp, $days ) {
        $date = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
        $added = 0;
        while ( $added < $days ) { $date = $date->modify( '+1 day' ); if ( (int) $date->format( 'N' ) <= 5 ) { $added++; } }
        return $date->format( 'Y-m-d' );
    }

    private static function default_processes( $supplier ) {
        $production = 'bandurria' === $supplier ? array( 'Impresión gran formato', 180 ) : ( 'mardones' === $supplier ? array( 'Impresión offset', 480 ) : ( 'druck' === $supplier ? array( 'Impresión / producción Druck', 180 ) : ( 'msbags' === $supplier ? array( 'Confección e impresión de bolsas', 600 ) : array( 'Producción del trabajo', 240 ) ) ) );
        return array(
            array( 'name' => 'Revisión y aprobación de archivo', 'estimated' => 30, 'actual' => '', 'status' => 'pending' ),
            array( 'name' => 'Preprensa / preparación', 'estimated' => 45, 'actual' => '', 'status' => 'pending' ),
            array( 'name' => $production[0], 'estimated' => $production[1], 'actual' => '', 'status' => 'pending' ),
            array( 'name' => 'Terminaciones', 'estimated' => 90, 'actual' => '', 'status' => 'pending' ),
            array( 'name' => 'Control y recepción', 'estimated' => 30, 'actual' => '', 'status' => 'pending' ),
        );
    }

    public static function render() {
        wp_enqueue_style( 'ge-production', GE_WTP_PLUGIN_URL . 'assets/css/production.css', array( 'ge-staff-portal' ), GE_WTP_VERSION );
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( $order_id ) { $order = wc_get_order( $order_id ); if ( $order ) { self::ensure_order( $order ); self::render_order( $order ); return; } }
        $view = sanitize_key( wp_unslash( $_GET['view'] ?? 'queue' ) );
        self::render_tabs( $view );
        if ( 'new' === $view && class_exists( 'GE_WTP_Manual_Orders' ) ) { GE_WTP_Manual_Orders::render(); return; }
        if ( 'suppliers' === $view && class_exists( 'GE_WTP_Supplier_Dispatch' ) ) { GE_WTP_Supplier_Dispatch::render_settings(); return; }
        if ( 'events' === $view ) { self::render_events(); return; }
        self::render_queue();
    }

    private static function render_tabs( $active ) {
        $tabs = array( 'queue' => 'Cola de trabajos', 'new' => '＋ Nuevo trabajo', 'suppliers' => 'Proveedores', 'events' => 'Incidencias y mantenimiento' );
        ?><nav class="ge-production-tabs" aria-label="Secciones de producción"><?php foreach ( $tabs as $key => $label ) : ?><a class="<?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav><?php
    }

    private static function render_queue() {
        $orders = GE_WTP_Orders::get_all_orders( 250 );
        $active = array(); $delayed = 0; $today = 0; $ready = 0;
        foreach ( $orders as $order ) {
            self::ensure_order( $order );
            if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'completed', 'ge-entregado', 'ge-facturado', 'ge-cobrado' ), true ) ) { continue; }
            $status = $order->get_meta( '_ge_production_status' );
            if ( 'ready' === $status ) { $ready++; continue; }
            $active[] = $order; $alert = self::alert( $order );
            if ( 'delayed' === $alert['key'] ) { $delayed++; } elseif ( 'today' === $alert['key'] ) { $today++; }
        }
        usort( $active, function( $a, $b ) { return strcmp( (string) $a->get_meta( '_ge_production_promised_date' ), (string) $b->get_meta( '_ge_production_promised_date' ) ); } );
        ?>
        <div class="ge-staff-heading"><div><span>Operación</span><h1>Producción</h1><p>Cola de trabajos, proveedores, tiempos y alertas.</p></div></div>
        <div class="ge-production-metrics"><article><span>En cola</span><strong><?php echo esc_html( count( $active ) ); ?></strong></article><article class="is-danger"><span>Demorados</span><strong><?php echo esc_html( $delayed ); ?></strong></article><article class="is-warning"><span>Vencen hoy</span><strong><?php echo esc_html( $today ); ?></strong></article><article class="is-ready"><span>Listos</span><strong><?php echo esc_html( $ready ); ?></strong></article></div>
        <section class="ge-production-board"><div class="ge-production-section-head"><div><span>Cola diaria</span><h2>Trabajos activos</h2></div><b><?php echo esc_html( wp_date( 'd/m/Y' ) ); ?></b></div><?php if ( ! $active ) : ?><div class="ge-admin-empty">No hay trabajos activos.</div><?php else : ?><div class="ge-production-list"><?php foreach ( $active as $order ) : self::queue_row( $order ); endforeach; ?></div><?php endif; ?></section>
        <?php
    }

    private static function queue_row( $order ) {
        $supplier = self::supplier_name( $order->get_meta( '_ge_production_supplier' ) );
        $status = self::statuses()[ $order->get_meta( '_ge_production_status' ) ] ?? 'Aprobado';
        $priority = self::priorities()[ $order->get_meta( '_ge_production_priority' ) ] ?? 'Normal';
        $alert = self::alert( $order ); $reference = self::order_reference( $order );
        ?><article class="ge-production-row is-<?php echo esc_attr( $alert['key'] ); ?>"><div class="ge-production-main"><small><?php echo esc_html( $reference ); ?></small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_billing_email() ); ?></strong><span><?php echo esc_html( implode( ' · ', array_map( function( $item ) { return $item->get_name(); }, $order->get_items( 'line_item' ) ) ) ); ?></span></div><div><small>Proveedor</small><strong><?php echo esc_html( $supplier ); ?></strong></div><div><small>Prometido</small><strong><?php echo esc_html( self::date_label( $order->get_meta( '_ge_production_promised_date' ) ) ); ?></strong><em><?php echo esc_html( $alert['label'] ); ?></em></div><div><small>Estado</small><strong><?php echo esc_html( $status ); ?></strong><span><?php echo esc_html( $priority ); ?></span></div><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ); ?>">Abrir →</a></article><?php
    }

    private static function render_order( $order ) {
        $processes = (array) $order->get_meta( '_ge_production_processes' ); while ( count( $processes ) < 8 ) { $processes[] = array( 'name' => '', 'estimated' => '', 'actual' => '', 'status' => 'pending' ); }
        $alert = self::alert( $order ); $reference = self::order_reference( $order );
        ?>
        <a class="ge-admin-back" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production' ) ); ?>">← Volver a producción</a>
        <?php self::render_notice(); ?>
        <?php if ( class_exists( 'GE_WTP_Manual_Orders' ) ) { GE_WTP_Manual_Orders::render_order_contact( $order ); } ?>
        <div class="ge-production-hero"><div><span>Orden de trabajo</span><h1><?php echo esc_html( $reference ); ?></h1><p><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_billing_email() ); ?></p></div><b class="is-<?php echo esc_attr( $alert['key'] ); ?>"><?php echo esc_html( $alert['label'] ); ?></b></div>
        <form class="ge-production-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_production_save"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_production_save_' . $order->get_id() ); ?>
            <section class="ge-production-card"><div class="ge-production-section-head"><div><span>Planificación</span><h2>Proveedor y tiempos</h2></div></div><div class="ge-production-fields"><label>Proveedor designado<select name="supplier"><?php foreach ( self::suppliers() as $key => $supplier ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_meta( '_ge_production_supplier' ), $key ); ?>><?php echo esc_html( $supplier['name'] ); ?></option><?php endforeach; ?></select></label><label>Fecha prometida<input type="date" required name="promised_date" value="<?php echo esc_attr( $order->get_meta( '_ge_production_promised_date' ) ); ?>"></label><label>Estado<select name="production_status"><?php foreach ( self::statuses() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_meta( '_ge_production_status' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Prioridad<select name="priority"><?php foreach ( self::priorities() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_meta( '_ge_production_priority' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label class="is-wide">Criterio automático<textarea rows="2" readonly><?php echo esc_textarea( $order->get_meta( '_ge_production_assignment_reason' ) ); ?></textarea></label><label class="is-wide">Notas técnicas y terminaciones<textarea name="technical_notes" rows="4" maxlength="3000" placeholder="Material, medidas, tintas, laminado, troquel, empaquetado..."><?php echo esc_textarea( $order->get_meta( '_ge_production_technical_notes' ) ); ?></textarea></label></div></section>
            <section class="ge-production-card"><div class="ge-production-section-head"><div><span>Ficha técnica</span><h2>Procesos y tiempos</h2></div><p>Estimado y real en minutos.</p></div><div class="ge-process-table"><div class="is-head"><span>Proceso</span><span>Estimado</span><span>Real</span><span>Estado</span></div><?php foreach ( $processes as $index => $process ) : ?><div><input type="text" name="processes[<?php echo esc_attr( $index ); ?>][name]" maxlength="160" value="<?php echo esc_attr( $process['name'] ?? '' ); ?>" placeholder="Nuevo proceso"><input type="number" min="0" step="5" name="processes[<?php echo esc_attr( $index ); ?>][estimated]" value="<?php echo esc_attr( $process['estimated'] ?? '' ); ?>"><input type="number" min="0" step="5" name="processes[<?php echo esc_attr( $index ); ?>][actual]" value="<?php echo esc_attr( $process['actual'] ?? '' ); ?>"><select name="processes[<?php echo esc_attr( $index ); ?>][status]"><option value="pending" <?php selected( $process['status'] ?? '', 'pending' ); ?>>Pendiente</option><option value="done" <?php selected( $process['status'] ?? '', 'done' ); ?>>Realizado</option></select></div><?php endforeach; ?></div></section>
            <div class="ge-production-submit"><button class="ge-staff-button" type="submit">Guardar producción</button></div>
        </form>
        <?php if ( class_exists( 'GE_WTP_Supplier_Dispatch' ) ) { GE_WTP_Supplier_Dispatch::render_order( $order ); } ?>
        <form class="ge-sheet-form" method="post" target="_blank" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_production_sheet"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_production_sheet_' . $order->get_id() ); ?><button type="submit">Abrir orden de trabajo imprimible ↗</button></form>
        <?php self::render_history( $order ); ?>
        <?php
    }

    public static function handle_save() {
        self::guard(); $order = self::requested_order(); check_admin_referer( 'ge_production_save_' . $order->get_id() );
        $suppliers = self::suppliers(); $statuses = self::statuses(); $priorities = self::priorities();
        $old_supplier = (string) $order->get_meta( '_ge_production_supplier' ); $old_status = (string) $order->get_meta( '_ge_production_status' ); $old_processes = (array) $order->get_meta( '_ge_production_processes' ); $supplier = sanitize_key( wp_unslash( $_POST['supplier'] ?? '' ) ); $status = sanitize_key( wp_unslash( $_POST['production_status'] ?? '' ) ); $priority = sanitize_key( wp_unslash( $_POST['priority'] ?? '' ) );
        $date = sanitize_text_field( wp_unslash( $_POST['promised_date'] ?? '' ) ); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { $date = ''; }
        $processes = array(); foreach ( (array) ( $_POST['processes'] ?? array() ) as $index => $process ) { $name = sanitize_text_field( wp_unslash( $process['name'] ?? '' ) ); if ( ! $name ) { continue; } $process_status = 'done' === ( $process['status'] ?? '' ) ? 'done' : 'pending'; $old_process = $old_processes[ $index ] ?? array(); $completed_at = $process['completed_at'] ?? ( $old_process['completed_at'] ?? '' ); if ( 'done' === $process_status && 'done' !== ( $old_process['status'] ?? '' ) ) { $completed_at = time(); } elseif ( 'pending' === $process_status ) { $completed_at = ''; } $processes[] = array( 'name' => $name, 'estimated' => absint( $process['estimated'] ?? 0 ), 'actual' => '' === ( $process['actual'] ?? '' ) ? '' : absint( $process['actual'] ), 'status' => $process_status, 'completed_at' => absint( $completed_at ) ); }
        $order->update_meta_data( '_ge_production_supplier', isset( $suppliers[ $supplier ] ) ? $supplier : 'pending' );
        if ( $supplier !== $old_supplier && isset( $suppliers[ $supplier ] ) ) { $order->update_meta_data( '_ge_production_assignment_reason', 'Asignación manual a ' . $suppliers[ $supplier ]['name'] . ' el ' . current_time( 'd/m/Y H:i' ) . '.' ); }
        $order->update_meta_data( '_ge_production_status', isset( $statuses[ $status ] ) ? $status : 'approved' );
        $order->update_meta_data( '_ge_production_priority', isset( $priorities[ $priority ] ) ? $priority : 'normal' );
        $order->update_meta_data( '_ge_production_promised_date', $date ); $order->update_meta_data( '_ge_estimated_date', $date );
        $order->update_meta_data( '_ge_production_technical_notes', sanitize_textarea_field( wp_unslash( $_POST['technical_notes'] ?? '' ) ) );
        $order->update_meta_data( '_ge_production_processes', $processes ); $order->save();
        if ( $status !== $old_status && isset( $statuses[ $status ] ) ) {
            $history = (array) $order->get_meta( '_ge_production_status_history' );
            $history[] = array( 'time' => time(), 'from' => $old_status, 'to' => $status, 'user_id' => get_current_user_id() );
            $order->update_meta_data( '_ge_production_status_history', array_slice( $history, -100 ) );
            if ( 'production' === $status && ! $order->get_meta( '_ge_production_started_at' ) ) { $order->update_meta_data( '_ge_production_started_at', time() ); }
            if ( 'ready' === $status && ! $order->get_meta( '_ge_production_ready_at' ) ) { $order->update_meta_data( '_ge_production_ready_at', time() ); }
            $order->save();
            self::sync_commercial_status( $order, $status );
        }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id(), 'saved' => 1 ) ) ); exit;
    }

    public static function handle_sheet() {
        $order = self::requested_order();
        if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { self::guard(); check_admin_referer( 'ge_production_sheet_' . $order->get_id() ); $token = self::new_token(); $order->update_meta_data( '_ge_production_sheet_token', $token ); $order->update_meta_data( '_ge_production_sheet_expires', time() + DAY_IN_SECONDS ); $order->save(); wp_safe_redirect( add_query_arg( array( 'action' => 'ge_production_sheet', 'order_id' => $order->get_id(), 'token' => $token ), admin_url( 'admin-post.php' ) ) ); exit; }
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); $valid = $token && hash_equals( (string) $order->get_meta( '_ge_production_sheet_token' ), $token ) && absint( $order->get_meta( '_ge_production_sheet_expires' ) ) >= time();
        if ( ! GE_WTP_Staff_Portal::can_access() && ! $valid ) { wp_die( 'Enlace no válido o vencido.', 403 ); }
        self::render_sheet( $order );
    }

    private static function render_sheet( $order ) {
        $reference = self::order_reference( $order ); $processes = (array) $order->get_meta( '_ge_production_processes' ); nocache_headers();
        ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Orden de trabajo <?php echo esc_html( $reference ); ?></title><style>@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;color:#111;font-family:Arial,sans-serif}.tools{padding:12px;text-align:center;background:#17152a;color:#fff}.tools button{padding:10px 16px;border:0;border-radius:7px;font-weight:700}.sheet{width:186mm;margin:10mm auto}.head{display:flex;justify-content:space-between;border-bottom:3px solid #111;padding-bottom:5mm}.head h1{margin:2mm 0 0;font-size:26pt}.head b{font-size:16pt}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin:5mm 0}.box{padding:3mm;border:1px solid #aaa}.box small,.box strong{display:block}.box small{font-size:8pt;text-transform:uppercase}.items,.process{width:100%;border-collapse:collapse;margin-top:5mm}.items th,.items td,.process th,.process td{padding:2.5mm;border:1px solid #bbb;text-align:left}.items th,.process th{background:#eee;font-size:8pt}.notes{min-height:25mm;margin-top:5mm;padding:3mm;border:1px solid #aaa;white-space:pre-wrap}.sign{display:grid;grid-template-columns:1fr 1fr;gap:20mm;margin-top:18mm}.sign div{padding-top:3mm;border-top:1px solid #111;font-size:8pt}@media print{.tools{display:none}.sheet{margin:0}}</style></head><body><div class="tools"><button onclick="window.print()">Imprimir orden de trabajo</button></div><main class="sheet"><header class="head"><div><small>GRAPH EXPRESS · PRODUCCIÓN</small><h1><?php echo esc_html( $reference ); ?></h1></div><b><?php echo esc_html( self::statuses()[ $order->get_meta( '_ge_production_status' ) ] ?? 'Aprobado' ); ?></b></header><div class="grid"><div class="box"><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_billing_email() ); ?></strong></div><div class="box"><small>Proveedor</small><strong><?php echo esc_html( self::supplier_name( $order->get_meta( '_ge_production_supplier' ) ) ); ?></strong></div><div class="box"><small>Fecha prometida</small><strong><?php echo esc_html( self::date_label( $order->get_meta( '_ge_production_promised_date' ) ) ); ?></strong></div></div><table class="items"><thead><tr><th>Producto</th><th>Cantidad</th><th>Especificaciones</th></tr></thead><tbody><?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?><tr><td><?php echo esc_html( $item->get_name() ); ?></td><td><?php echo esc_html( $item->get_quantity() ); ?></td><td><?php echo wp_kses_post( wc_display_item_meta( $item, array( 'echo' => false, 'separator' => ' · ' ) ) ); ?></td></tr><?php endforeach; ?></tbody></table><table class="process"><thead><tr><th>Proceso</th><th>Estimado</th><th>Real</th><th>Estado</th></tr></thead><tbody><?php foreach ( $processes as $process ) : ?><tr><td><?php echo esc_html( $process['name'] ?? '' ); ?></td><td><?php echo esc_html( absint( $process['estimated'] ?? 0 ) ); ?> min</td><td><?php echo '' === ( $process['actual'] ?? '' ) ? '—' : esc_html( absint( $process['actual'] ) ) . ' min'; ?></td><td><?php echo 'done' === ( $process['status'] ?? '' ) ? 'Realizado' : 'Pendiente'; ?></td></tr><?php endforeach; ?></tbody></table><div class="notes"><strong>Notas técnicas</strong><br><?php echo esc_html( $order->get_meta( '_ge_production_technical_notes' ) ?: 'Sin observaciones.' ); ?></div><div class="sign"><div>Control de producción</div><div>Control final</div></div></main></body></html><?php exit;
    }

    private static function render_suppliers() {
        ?><section class="ge-production-board"><div class="ge-production-section-head"><div><span>Red de producción</span><h2>Proveedores configurados</h2></div></div><div class="ge-supplier-grid"><?php foreach ( self::suppliers() as $key => $supplier ) : if ( in_array( $key, array( 'multiple', 'pending', 'internal' ), true ) ) { continue; } ?><article><strong><?php echo esc_html( $supplier['name'] ); ?></strong><p><?php echo esc_html( $supplier['detail'] ); ?></p></article><?php endforeach; ?></div></section><?php
    }

    public static function render_events() {
        $events = get_posts( array( 'post_type' => self::EVENT_POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
        ?><div class="ge-staff-heading"><div><span>Control operativo</span><h1>Incidencias y mantenimiento</h1><p>Registro de problemas, mantenimientos preventivos y resolución.</p></div></div><section class="ge-production-board"><div class="ge-production-section-head"><div><span>Nuevo registro</span><h2>Máquina o proveedor</h2></div></div><form class="ge-event-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_production_event"><?php wp_nonce_field( 'ge_production_event' ); ?><label>Tipo<select name="event_type"><option value="incident">Incidencia</option><option value="maintenance">Mantenimiento preventivo</option></select></label><label>Máquina o proveedor<input type="text" name="subject" required maxlength="160" placeholder="Ej.: Xerox, guillotina, Druck..."></label><label>Fecha<input type="date" name="event_date" required value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label><label>Estado<select name="event_status"><option value="open">Pendiente</option><option value="resolved">Resuelto</option></select></label><label class="is-wide">Detalle<textarea name="event_notes" required rows="3" maxlength="2000"></textarea></label><button class="ge-staff-button" type="submit">Registrar</button></form><?php if ( $events ) : ?><div class="ge-event-list"><?php foreach ( $events as $event ) : $resolved = 'resolved' === get_post_meta( $event->ID, '_ge_event_status', true ); ?><article class="<?php echo $resolved ? 'is-resolved' : 'is-open'; ?>"><b><?php echo 'maintenance' === get_post_meta( $event->ID, '_ge_event_type', true ) ? 'Mantenimiento' : 'Incidencia'; ?></b><strong><?php echo esc_html( $event->post_title ); ?></strong><span><?php echo esc_html( self::date_label( get_post_meta( $event->ID, '_ge_event_date', true ) ) ); ?> · <?php echo $resolved ? 'Resuelto' : 'Pendiente'; ?></span><p><?php echo esc_html( $event->post_content ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_production_event_status"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event->ID ); ?>"><input type="hidden" name="event_status" value="<?php echo $resolved ? 'open' : 'resolved'; ?>"><?php wp_nonce_field( 'ge_production_event_status_' . $event->ID ); ?><button type="submit"><?php echo $resolved ? 'Reabrir' : 'Marcar resuelto'; ?></button></form></article><?php endforeach; ?></div><?php else : ?><div class="ge-admin-empty">Todavía no hay incidencias ni mantenimientos registrados.</div><?php endif; ?></section><?php
    }

    public static function handle_event() {
        self::guard(); check_admin_referer( 'ge_production_event' ); $subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ); $notes = sanitize_textarea_field( wp_unslash( $_POST['event_notes'] ?? '' ) ); if ( ! $subject || ! $notes ) { wp_die( 'Faltan datos.' ); }
        $id = wp_insert_post( array( 'post_type' => self::EVENT_POST_TYPE, 'post_status' => 'private', 'post_title' => $subject, 'post_content' => $notes ) ); if ( $id ) { $event_type = 'maintenance' === ( $_POST['event_type'] ?? '' ) ? 'maintenance' : 'incident'; update_post_meta( $id, '_ge_event_type', $event_type ); update_post_meta( $id, '_ge_event_date', sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ) ); update_post_meta( $id, '_ge_event_status', 'resolved' === ( $_POST['event_status'] ?? '' ) ? 'resolved' : 'open' ); if ( 'incident' === $event_type && class_exists( 'GE_WTP_Notification_Center' ) ) { GE_WTP_Notification_Center::send_internal( 'new_incident', 'Nueva incidencia · ' . $subject, '<p>' . nl2br( esc_html( $notes ) ) . '</p><p><a href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'events' ) ) ) . '">Abrir incidencias</a></p>', $id ); } }
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'events' ) ) ); exit;
    }

    public static function handle_event_status() {
        self::guard(); $event_id = absint( $_POST['event_id'] ?? 0 ); check_admin_referer( 'ge_production_event_status_' . $event_id );
        if ( self::EVENT_POST_TYPE !== get_post_type( $event_id ) ) { wp_die( 'Registro inválido.', 404 ); }
        update_post_meta( $event_id, '_ge_event_status', 'resolved' === ( $_POST['event_status'] ?? '' ) ? 'resolved' : 'open' );
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'events' ) ) ); exit;
    }

    private static function render_notice() {
        $status = sanitize_key( wp_unslash( $_GET['dispatch_status'] ?? '' ) );
        if ( 'supplier-sent' === $status ) { echo '<div class="ge-production-notice">La orden fue enviada al proveedor por email.</div>'; }
        if ( 'supplier-failed' === $status ) { echo '<div class="ge-production-notice is-error">No se pudo enviar. Revisá el contacto del proveedor y la configuración de correo.</div>'; }
        if ( ! empty( $_GET['saved'] ) ) { echo '<div class="ge-production-notice">La producción quedó actualizada.</div>'; }
        if ( ! empty( $_GET['manual_created'] ) ) { echo '<div class="ge-production-notice">El trabajo de mostrador fue creado y ya está en la cola de producción.</div>'; }
        $invite = sanitize_key( wp_unslash( $_GET['manual_invite'] ?? '' ) );
        if ( 'sent' === $invite ) { echo '<div class="ge-production-notice">La invitación para registrarse fue enviada por email.</div>'; }
        if ( 'failed' === $invite ) { echo '<div class="ge-production-notice is-error">No se pudo enviar la invitación por email.</div>'; }
    }

    private static function render_history( $order ) {
        $history = array_values( array_filter( (array) $order->get_meta( '_ge_production_status_history' ), function( $entry ) { return is_array( $entry ) && ! empty( $entry['time'] ) && ! empty( $entry['to'] ); } ) );
        $completed_processes = array_values( array_filter( (array) $order->get_meta( '_ge_production_processes' ), function( $process ) { return is_array( $process ) && 'done' === ( $process['status'] ?? '' ) && ! empty( $process['completed_at'] ); } ) );
        $started = absint( $order->get_meta( '_ge_production_started_at' ) ); $ready = absint( $order->get_meta( '_ge_production_ready_at' ) );
        ?><section class="ge-production-card ge-production-history"><div class="ge-production-section-head"><div><span>Trazabilidad</span><h2>Historial de producción</h2></div></div><div class="ge-history-summary"><div><small>Inicio de producción</small><strong><?php echo $started ? esc_html( wp_date( 'd/m/Y H:i', $started ) ) : 'Todavía no iniciado'; ?></strong></div><div><small>Listo para entrega</small><strong><?php echo $ready ? esc_html( wp_date( 'd/m/Y H:i', $ready ) ) : 'Todavía no finalizado'; ?></strong></div></div><?php if ( $history || $completed_processes ) : ?><ol><?php foreach ( $completed_processes as $process ) : ?><li><time><?php echo esc_html( wp_date( 'd/m/Y H:i', absint( $process['completed_at'] ) ) ); ?></time><span><?php echo esc_html( 'Proceso realizado: ' . $process['name'] ); ?></span><small><?php echo '' === ( $process['actual'] ?? '' ) ? 'Tiempo real pendiente' : esc_html( absint( $process['actual'] ) . ' min reales' ); ?></small></li><?php endforeach; ?><?php foreach ( array_reverse( $history ) as $entry ) : $user = get_userdata( absint( $entry['user_id'] ?? 0 ) ); ?><li><time><?php echo esc_html( wp_date( 'd/m/Y H:i', absint( $entry['time'] ) ) ); ?></time><span><?php echo esc_html( ( self::statuses()[ $entry['from'] ?? '' ] ?? 'Inicio' ) . ' → ' . ( self::statuses()[ $entry['to'] ] ?? 'Estado' ) ); ?></span><small><?php echo esc_html( $user ? $user->display_name : 'Sistema' ); ?></small></li><?php endforeach; ?></ol><?php else : ?><p class="ge-history-empty">El historial comenzará cuando cambies el estado o completes un proceso.</p><?php endif; ?></section><?php
    }

    private static function sync_commercial_status( $order, $production_status ) {
        $mapping = array( 'approved' => 'ge-confirmado', 'production' => 'ge-produccion', 'ready' => 'ge-listo' );
        if ( empty( $mapping[ $production_status ] ) || $order->get_status() === $mapping[ $production_status ] ) { return; }
        $old_order_status = $order->get_status();
        $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' ) && $order->get_meta( '_ge_markcom_reference' );
        $order->update_status( $mapping[ $production_status ], 'Estado actualizado desde el panel de producción.' );
        if ( ! $is_markcom && class_exists( 'GE_WTP_Notifications' ) ) { GE_WTP_Notifications::send_order_status_changed( $order, $old_order_status ); }
    }

    private static function alert( $order ) {
        if ( 'ready' === $order->get_meta( '_ge_production_status' ) ) { return array( 'key' => 'ready', 'label' => 'Listo' ); }
        $date = $order->get_meta( '_ge_production_promised_date' ); if ( ! $date ) { return array( 'key' => 'pending', 'label' => 'Sin fecha' ); }
        $today = wp_date( 'Y-m-d' ); if ( $date < $today ) { return array( 'key' => 'delayed', 'label' => 'Demorado' ); } if ( $date === $today ) { return array( 'key' => 'today', 'label' => 'Vence hoy' ); }
        $tomorrow = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' ); if ( $date === $tomorrow ) { return array( 'key' => 'soon', 'label' => 'Vence mañana' ); }
        return array( 'key' => 'ok', 'label' => 'En término' );
    }

    private static function supplier_name( $key ) { $suppliers = self::suppliers(); return $suppliers[ $key ]['name'] ?? 'Proveedor a definir'; }
    private static function order_reference( $order ) { return class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() ); }
    private static function date_label( $date ) { return $date ? wp_date( 'd/m/Y', strtotime( $date ) ) : 'Sin fecha'; }
    public static function date_label_public( $date ) { return self::date_label( $date ); }
    private static function guard() { if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); } }
    private static function requested_order() { $id = absint( $_REQUEST['order_id'] ?? 0 ); $order = $id ? wc_get_order( $id ) : false; if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); } return $order; }
    private static function new_token() { try { return bin2hex( random_bytes( 24 ) ); } catch ( Exception $error ) { return wp_generate_password( 48, false, false ); } }
}
