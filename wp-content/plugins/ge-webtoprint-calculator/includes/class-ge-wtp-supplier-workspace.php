<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Internal supplier ledger. Never uses WooCommerce customer payments. */
final class GE_WTP_Supplier_Workspace {
    const OPTION = 'ge_wtp_supplier_workspace';
    const ENTRY = 'ge_supplier_entry';
    const META = '_ge_supplier_entry';
    const MAX_FILE = 20971520;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_type' ), 8 );
        foreach ( array( 'card', 'entry', 'download', 'payable', 'assign', 'price_list_active' ) as $action ) {
            add_action( 'admin_post_ge_supplier_ws_' . $action, array( __CLASS__, 'handle_' . $action ) );
        }
    }

    public static function register_type() {
        register_post_type( self::ENTRY, array( 'public' => false, 'show_ui' => false, 'supports' => array( 'title' ) ) );
    }

    public static function suppliers() {
        return GE_WTP_Supplier_Dispatch::profiles();
    }

    public static function supplier( $key ) {
        $all = self::suppliers();
        return $all[ $key ] ?? array();
    }

    public static function detail_url( $key, $args = array() ) {
        return GE_WTP_Staff_Portal::portal_url( GE_WTP_Operations::enabled() ? 'suppliers' : 'production', array_merge( array( 'view' => 'supplier', 'supplier_id' => $key ), $args ) );
    }

    private static function data( $key ) {
        $all = get_option( self::OPTION, array() );
        return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
    }

    private static function entries( $key ) {
        $ids = get_posts( array( 'post_type' => self::ENTRY, 'post_status' => 'private', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_ge_supplier_key', 'meta_value' => $key, 'orderby' => 'date', 'order' => 'DESC' ) );
        $rows = array();
        foreach ( $ids as $id ) {
            $row = get_post_meta( $id, self::META, true );
            if ( is_array( $row ) ) { $row['id'] = (int) $id; $rows[] = $row; }
        }
        return $rows;
    }

    private static function order_has_supplier( $order, $key ) {
        if ( $key === $order->get_meta( '_ge_production_supplier', true ) ) { return true; }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $key === $item->get_meta( '_ge_production_supplier', true ) ) { return true; }
        }
        return false;
    }

    /** Read-only scan includes legacy order assignment and current item assignment. */
    private static function orders_by_supplier() {
        $keys = array_keys( self::suppliers() );
        $result = array_fill_keys( $keys, array() );
        if ( ! function_exists( 'wc_get_orders' ) ) { return $result; }
        $page = 1;
        do {
            $orders = wc_get_orders( array( 'limit' => 100, 'page' => $page++, 'return' => 'objects', 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
            foreach ( $orders as $order ) {
                if ( ! $order instanceof WC_Order ) { continue; }
                foreach ( $keys as $key ) {
                    if ( self::order_has_supplier( $order, $key ) ) { $result[ $key ][] = $order; }
                }
            }
        } while ( count( $orders ) === 100 );
        return $result;
    }

    private static function open_order( $order ) {
        return ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true )
            && ! GE_WTP_Production::is_closed( $order )
            && ( ! class_exists( 'GE_WTP_Order_Lifecycle' ) || 'entregado' !== GE_WTP_Order_Lifecycle::stage( $order ) );
    }

    private static function money( $amount, $currency = 'ARS' ) {
        return esc_html( $currency . ' ' . number_format_i18n( (float) $amount, 2 ) );
    }

    private static function finance( $key, $orders, $entries ) {
        if(GE_WTP_Operations::enabled() && (current_user_can('ge_view_finance')||current_user_can('manage_options'))) { $sid=0;foreach(GE_WTP_Operations::suppliers() as $id=>$profile)if($profile['key']===$key)$sid=$id;$out=array('cost'=>0,'paid'=>0,'balance'=>0,'unknown'=>0);$costed=array();foreach(GE_WTP_Operations_Finance::payables(array('supplier_id'=>$sid,'status'=>'all')) as $p) { if($p['currency']!=='ARS') { $out['unknown']++;continue; }$costed[(int)$p['order_id']]=true;$out['cost']+=$p['recognized_minor']/100;$out['paid']+=$p['paid_minor']/100;$out['balance']+=$p['outstanding_minor']/100; }foreach($orders as $order)if(!isset($costed[$order->get_id()]))$out['unknown']++;return $out; }

        if(GE_WTP_Operations::enabled() && !current_user_can('ge_view_finance') && !current_user_can('manage_options')) return array('cost'=>0,'paid'=>0,'balance'=>0,'unknown'=>0);
        $cost = 0; $paid = 0; $unknown = 0;
        foreach ( $orders as $order ) {
            $payables = (array) $order->get_meta( '_ge_supplier_payables', true );
            if ( isset( $payables[ $key ]['amount'] ) && '' !== $payables[ $key ]['amount'] ) { $cost += (float) $payables[ $key ]['amount']; }
            else { $unknown++; }
        }
        foreach ( $entries as $row ) { if ( 'payment' === ( $row['type'] ?? '' ) && 'ARS' === ( $row['currency'] ?? 'ARS' ) ) { $paid += (float) ( $row['amount'] ?? 0 ); } }
        return array( 'cost' => $cost, 'paid' => $paid, 'balance' => $cost - $paid, 'unknown' => $unknown );
    }

    private static function price_lists( $entries ) {
        return array_values( array_filter( $entries, static function ( $row ) { return 'price_list' === ( $row['type'] ?? '' ); } ) );
    }

    public static function compact_operations_metrics() {
        if(!GE_WTP_Staff_Portal::can_access()) return array('open'=>0,'no_list'=>0);
        $all=self::orders_by_supplier();$open=0;$missing=0;foreach(self::suppliers() as $key=>$profile) { foreach($all[$key]??array() as $order) if(self::open_order($order))$open++;if(!self::price_lists(self::entries($key)))$missing++; }return array('open'=>$open,'no_list'=>$missing);
    }
    public static function render( $view ) {
        $css = GE_WTP_PLUGIN_DIR . 'assets/css/supplier-workspace.css';
        wp_enqueue_style( 'ge-supplier-workspace', GE_WTP_PLUGIN_URL . 'assets/css/supplier-workspace.css', array( 'ge-production' ), is_file( $css ) ? (string) filemtime( $css ) : GE_WTP_VERSION );
        $js = GE_WTP_PLUGIN_DIR . 'assets/js/supplier-workspace.js';
        wp_enqueue_script( 'ge-supplier-workspace', GE_WTP_PLUGIN_URL . 'assets/js/supplier-workspace.js', array(), is_file( $js ) ? (string) filemtime( $js ) : GE_WTP_VERSION, true );
        $key = sanitize_key( wp_unslash( $_GET['supplier_id'] ?? '' ) );
        if ( 'supplier' === $view && self::supplier( $key ) ) { self::render_detail( $key ); return; }
        self::render_dashboard();
    }

    public static function render_order_links( $order ) {
        $keys = array(); $profiles = self::suppliers();
        $order_key = sanitize_key( $order->get_meta( '_ge_production_supplier', true ) );
        if ( isset( $profiles[ $order_key ] ) ) { $keys[] = $order_key; }
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $key = sanitize_key( $item->get_meta( '_ge_production_supplier', true ) );
            if ( isset( $profiles[ $key ] ) ) { $keys[] = $key; }
        }
        $keys = array_unique( $keys ); if ( ! $keys ) { return; }
        echo '<div class="ge-production-card"><strong>Proveedores vinculados</strong><p>';
        foreach ( $keys as $key ) { echo '<a style="display:inline-block;margin:7px 12px 0 0" href="' . esc_url( self::detail_url( $key ) ) . '">' . esc_html( $profiles[ $key ]['name'] ) . ' →</a>'; }
        echo '</p></div>';
    }

    private static function render_dashboard() {
        $profiles = self::suppliers(); $all_orders = self::orders_by_supplier();
        $filter = sanitize_key( wp_unslash( $_GET['supplier_filter'] ?? 'all' ) );
        $q = sanitize_text_field( wp_unslash( $_GET['supplier_q'] ?? '' ) );
        $category_filter = sanitize_text_field( wp_unslash( $_GET['supplier_category'] ?? '' ) ); $type_filter=sanitize_key($_GET['supplier_type']??'');
        $active = 0; $open = 0; $late = 0; $known_balance = 0; $unknown = 0; $stale = 0;
        $today = current_time( 'Y-m-d' );
        $summaries = array(); $categories = array();
        foreach ( $profiles as $key => $profile ) {
            $data = self::data( $key ); $orders = $all_orders[ $key ] ?? array(); $entries = self::entries( $key );
            if ( ! empty( $data['category'] ) ) { $categories[ $data['category'] ] = $data['category']; }
            $finance = self::finance( $key, $orders, $entries ); $lists = self::price_lists( $entries );
            $current = null; foreach ( $lists as $list ) { if ( ! empty( $list['active'] ) ) { $current = $list; break; } }
            $is_active = 'active' === ( $data['status'] ?? 'unknown' );
            if ( $is_active ) { $active++; }
            $open_here = 0; $late_here = 0;
            foreach ( $orders as $order ) { if ( self::open_order( $order ) ) { $open_here++; if ( $order->get_meta( '_ge_production_promised_date', true ) && $order->get_meta( '_ge_production_promised_date', true ) < $today ) { $late_here++; } } }
            $open += $open_here; $late += $late_here; $known_balance += $finance['balance']; $unknown += $finance['unknown'];
            if ( ! $current ) { $stale++; }
            $last_activity = $orders && $orders[0]->get_date_created() ? $orders[0]->get_date_created()->getTimestamp() : 0;
            if ( $entries ) { $last_activity = max( $last_activity, absint( $entries[0]['created_ts'] ?? 0 ) ); }
            $summaries[ $key ] = compact( 'profile', 'data', 'orders', 'entries', 'finance', 'current', 'open_here', 'late_here', 'is_active', 'last_activity' );
        }
        uasort( $summaries, static function ( $a, $b ) { return $b['last_activity'] <=> $a['last_activity']; } );
        natcasesort( $categories );
        ?><div class="ge-sw"><header class="ge-sw-head"><div><span>PROVEEDORES GRAPHEX</span><h1>Proveedores</h1><p>Pedidos, condiciones, cuentas y archivos de cada relación de trabajo.</p></div><a class="ge-sw-button" href="<?php echo esc_url( add_query_arg( 'supplier_new', 1, GE_WTP_Staff_Portal::portal_url( 'suppliers' ) ) ); ?>#new-supplier">+ Nuevo proveedor</a></header>
        <div class="ge-sw-metrics"><article><small>Activos confirmados</small><strong><?php echo esc_html( $active ); ?></strong></article><article><small>Pedidos abiertos asignados</small><strong><?php echo esc_html( $open ); ?></strong></article><article><small>Demorados</small><strong><?php echo esc_html( $late ); ?></strong></article><article><small>Saldo conocido · ARS</small><strong><?php echo self::money( $known_balance ); ?></strong><small><?php echo esc_html( $unknown ); ?> pedidos sin costo cargado</small></article><article><small>Sin lista vigente</small><strong><?php echo esc_html( $stale ); ?></strong></article></div>
        <form class="ge-sw-filters" method="get"><input type="hidden" name="section" value="suppliers"><input type="hidden" name="view" value="suppliers"><label>Buscar<input name="supplier_q" type="search" value="<?php echo esc_attr( $q ); ?>" placeholder="Nombre, email, teléfono o rubro"></label><label>Mostrar<select name="supplier_filter"><option value="all">Todos</option><?php foreach ( array( 'active' => 'Activos', 'inactive' => 'Inactivos', 'unknown' => 'Estado sin definir', 'open' => 'Con pedidos abiertos', 'payable' => 'Con saldo conocido', 'late' => 'Con demoras', 'no-list' => 'Sin lista vigente', 'recent' => 'Actividad últimos 90 días', 'quiet' => 'Sin actividad 180 días' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Tipo<select name="supplier_type"><option value="">Todos</option><?php foreach(array('production'=>'Producción','supplies'=>'Insumos','services'=>'Servicios','logistics'=>'Logística','other'=>'Otros') as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($type_filter,$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>Rubro<select name="supplier_category"><option value="">Todos</option><?php foreach ( $categories as $category ) : ?><option value="<?php echo esc_attr( $category ); ?>" <?php selected( $category_filter, $category ); ?>><?php echo esc_html( $category ); ?></option><?php endforeach; ?></select></label><button type="submit">Filtrar</button></form>
        <div class="ge-sw-table" role="table" aria-label="Proveedores"><div class="ge-sw-row ge-sw-row-head" role="row"><span>Proveedor / especialidad</span><span>Contacto</span><span>Pedidos</span><span>Cuenta</span><span>Precios</span><span></span></div><?php foreach ( $summaries as $key => $row ) :
            $p = $row['profile']; $d = $row['data']; $f = $row['finance']; $search = implode( ' ', array( $p['name'], $p['email'], $p['whatsapp'], $d['category'] ?? '', $d['phone'] ?? '' ) );
            if ( $q && false === stripos( remove_accents( $search ), remove_accents( $q ) ) ) { continue; }
            if($type_filter && !in_array($type_filter,$p['types']??array(),true)) continue; if ( $category_filter && $category_filter !== ( $d['category'] ?? '' ) ) { continue; }
            if ( ( 'active' === $filter && 'active' !== ( $d['status'] ?? 'unknown' ) ) || ( 'inactive' === $filter && 'inactive' !== ( $d['status'] ?? 'unknown' ) ) || ( 'unknown' === $filter && 'unknown' !== ( $d['status'] ?? 'unknown' ) ) || ( 'open' === $filter && ! $row['open_here'] ) || ( 'payable' === $filter && $f['balance'] <= 0 ) || ( 'late' === $filter && ! $row['late_here'] ) || ( 'no-list' === $filter && $row['current'] ) || ( 'recent' === $filter && $row['last_activity'] < strtotime( '-90 days' ) ) || ( 'quiet' === $filter && $row['last_activity'] >= strtotime( '-180 days' ) ) ) { continue; }
            ?><div class="ge-sw-row" role="row"><div><strong><?php echo esc_html( $p['name'] ); ?></strong><small><?php echo esc_html(implode(' · ',array_map(function($t){return array('production'=>'Producción','supplies'=>'Insumos','services'=>'Servicios','logistics'=>'Logística','other'=>'Otros')[$t]??$t;},$p['types']??array()))); ?></small><small><?php echo esc_html( $d['category'] ?? 'Rubro sin cargar' ); ?> · <?php echo esc_html( array( 'active' => 'Activo', 'inactive' => 'Inactivo', 'unknown' => 'Estado sin definir' )[ $d['status'] ?? 'unknown' ] ?? 'Estado sin definir' ); ?></small></div><div><?php echo esc_html( $p['email'] ?: ( $p['whatsapp'] ?: 'Sin contacto' ) ); ?></div><div><?php echo esc_html( $row['open_here'] ); ?> abiertos<?php if ( $row['late_here'] ) : ?><small class="ge-sw-warn"><?php echo esc_html( $row['late_here'] ); ?> demorados</small><?php endif; ?><?php if ( $row['orders'] ) : ?><small>Último #<?php echo esc_html( $row['orders'][0]->get_id() ); ?></small><?php endif; ?></div><div><?php echo $f['cost'] ? self::money( $f['balance'] ) : 'Sin costos'; ?><?php if ( $f['unknown'] ) : ?><small><?php echo esc_html( $f['unknown'] ); ?> sin costo</small><?php endif; ?></div><div><?php echo esc_html( $row['current']['title'] ?? 'Sin lista vigente' ); ?><?php if ( $row['current'] ) : ?><small><?php echo esc_html( $row['current']['valid_from'] ?? '' ); ?></small><?php endif; ?></div><a href="<?php echo esc_url( self::detail_url( $key ) ); ?>">Abrir ficha →</a></div><?php endforeach; ?></div>
        <?php self::render_add(); ?></div><?php
    }

    private static function render_add() {
        ?><details class="ge-sw-card" id="new-supplier" <?php echo ! empty( $_GET['supplier_new'] ) ? 'open' : ''; ?>><summary>Agregar proveedor</summary><p>Se crea una clave interna nueva; luego completá su ficha.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_add"><?php wp_nonce_field( 'ge_supplier_add' ); ?><button class="ge-sw-button" type="submit">Crear ficha nueva</button></form></details><?php
    }

    private static function render_detail( $key ) {
        $profile = self::supplier( $key ); $data = self::data( $key ); $all = self::orders_by_supplier(); $orders = $all[ $key ] ?? array(); $entries = self::entries( $key ); $finance = self::finance( $key, $orders, $entries );
        $message = sanitize_key( wp_unslash( $_GET['sw_notice'] ?? '' ) );
        ?><div class="ge-sw"><a class="ge-sw-back" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'suppliers' ) ); ?>">← Todos los proveedores</a><header class="ge-sw-head"><div><span>PROVEEDOR · <?php echo esc_html( strtoupper( $key ) ); ?></span><h1><?php echo esc_html( $profile['name'] ); ?></h1><p><?php echo esc_html( $data['category'] ?? 'Especialidad por cargar' ); ?> · <?php echo esc_html( array( 'active' => 'Activo', 'inactive' => 'Inactivo', 'unknown' => 'Estado sin definir' )[ $data['status'] ?? 'unknown' ] ?? 'Estado sin definir' ); ?></p></div><div class="ge-sw-actions"><a class="ge-sw-button" href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'view' => 'new' ) ) ); ?>">+ Nuevo trabajo</a><a href="#supplier-orders">Pedidos</a><a href="#supplier-account">Cuenta</a><a href="#supplier-files">Archivos</a></div></header>
        <?php if ( $message ) : ?><div class="ge-sw-notice" role="status"><?php echo esc_html( 'error' === $message ? 'No se pudo guardar. Revisá los datos y el archivo.' : 'Cambio guardado en la ficha del proveedor.' ); ?></div><?php endif; ?>
        <div class="ge-sw-detail"><aside><section class="ge-sw-card"><div class="ge-sw-card-head"><h2>Identidad y contacto</h2><small>Guardado independiente</small></div><?php self::render_card_form( $key, 'identity', $profile, $data ); ?></section><section class="ge-sw-card"><div class="ge-sw-card-head"><h2>Cómo trabajamos</h2><small>Guardado independiente</small></div><?php self::render_card_form( $key, 'conditions', $profile, $data ); ?></section></aside><main>
        <nav class="ge-sw-nav" aria-label="Secciones del proveedor"><a href="#supplier-orders">Pedidos</a><a href="#supplier-account">Pagos</a><a href="#supplier-files">Documentos</a><a href="#supplier-prices">Listas de precios</a><a href="#supplier-history">Historial</a></nav>
        <section class="ge-sw-card" id="supplier-orders"><div class="ge-sw-card-head"><div><span>OPERACIÓN</span><h2>Pedidos al proveedor</h2></div><strong><?php echo esc_html( count( $orders ) ); ?> pedidos</strong></div>
        <?php if ( ! $orders ) : ?><p class="ge-sw-empty">Todavía no hay pedidos vinculados a este proveedor.</p><?php else : ?><div class="ge-sw-order-list"><?php foreach ( $orders as $order ) : self::render_order_row( $key, $order, $entries ); endforeach; ?></div><?php endif; ?>
        <details class="ge-sw-subform"><summary>Asignar pedido existente</summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_ws_assign"><input type="hidden" name="supplier_id" value="<?php echo esc_attr( $key ); ?>"><?php wp_nonce_field( 'ge_supplier_ws_assign_' . $key ); ?><label>ID del pedido<input type="number" min="1" name="order_id" required></label><p>La asignación usa el campo de Producción. Revisá los ítems en el pedido antes de enviar la orden.</p><button type="submit">Asignar pedido</button></form></details></section>
        <?php if(GE_WTP_Operations::enabled()) : GE_WTP_Operations_UI::supplier_finance($key); else : ?><section class="ge-sw-card" id="supplier-account"><div class="ge-sw-card-head"><div><span>CUENTAS A PAGAR</span><h2>Pagos a proveedor</h2></div></div><div class="ge-sw-finance"><div><small>Costo registrado · ARS</small><strong><?php echo self::money( $finance['cost'] ); ?></strong></div><div><small>Pagado · ARS</small><strong><?php echo self::money( $finance['paid'] ); ?></strong></div><div><small>Saldo conocido · ARS</small><strong><?php echo self::money( $finance['balance'] ); ?></strong></div></div><?php if ( $finance['unknown'] ) : ?><p class="ge-sw-warning">Hay <?php echo esc_html( $finance['unknown'] ); ?> pedido(s) sin costo cargado. El saldo no representa la deuda total.</p><?php endif; ?><p class="ge-sw-hint">Libro interno separado de los cobros del cliente. Registrar un pago no ejecuta una transferencia.</p>
        <?php self::render_entry_list( $key, $entries, 'payment' ); self::render_entry_form( $key, 'payment', $orders ); ?></section>
        <?php endif; ?><section class="ge-sw-card" id="supplier-files"><div class="ge-sw-card-head"><div><span>ARCHIVO PRIVADO</span><h2>Facturas y documentos</h2></div></div><?php self::render_existing_invoices( $key ); self::render_entry_list( $key, $entries, 'document' ); self::render_entry_form( $key, 'document', $orders ); ?></section>
        <section class="ge-sw-card" id="supplier-prices"><div class="ge-sw-card-head"><div><span>COSTOS</span><h2>Listas de precios</h2></div><?php if(GE_WTP_Cost_Engine::enabled()): ?><a href="<?php echo esc_url(GE_WTP_Cost_UI::url('lists',array('supplier_id'=>$key))); ?>">Abrir costos e impacto</a><?php endif; ?></div><?php self::render_entry_list( $key, $entries, 'price_list' ); self::render_entry_form( $key, 'price_list', $orders ); self::render_catalog_costs( $key ); ?></section>
        <section class="ge-sw-card" id="supplier-history"><div class="ge-sw-card-head"><div><span>RELACIÓN</span><h2>Notas e historial</h2></div></div><?php self::render_entry_list( $key, $entries, 'note' ); self::render_entry_form( $key, 'note', $orders ); self::render_timeline( $orders, $entries ); ?></section>
        </main></div></div><?php
    }

    private static function render_card_form( $key, $section, $profile, $data ) {
        $identity = array( 'name' => array( 'Nombre comercial', $profile['name'], 'text' ), 'legal_name' => array( 'Razón social', $data['legal_name'] ?? '', 'text' ), 'contact_name' => array( 'Contacto principal', $data['contact_name'] ?? '', 'text' ), 'email' => array( 'Email de pedidos', $profile['email'], 'email' ), 'phone' => array( 'Teléfono', $data['phone'] ?? '', 'tel' ), 'whatsapp' => array( 'WhatsApp', $profile['whatsapp'], 'tel' ), 'website' => array( 'Web', $data['website'] ?? '', 'url' ), 'address' => array( 'Dirección', $data['address'] ?? '', 'text' ), 'status' => array( 'Estado', $data['status'] ?? 'unknown', 'status' ) );
        $conditions = array( 'category' => array( 'Rubros / especialidad', $data['category'] ?? '', 'text' ), 'materials' => array( 'Materiales y capacidades', $data['materials'] ?? '', 'textarea' ), 'lead_time' => array( 'Plazo habitual', $data['lead_time'] ?? '', 'text' ), 'minimum' => array( 'Mínimo de compra', $data['minimum'] ?? '', 'text' ), 'payment_terms' => array( 'Condiciones de pago', $data['payment_terms'] ?? '', 'text' ), 'hours' => array( 'Días y horarios', $data['hours'] ?? '', 'text' ), 'delivery' => array( 'Retiro / entrega y zonas', $data['delivery'] ?? '', 'textarea' ), 'notes' => array( 'Instrucciones operativas', $profile['notes'], 'textarea' ), 'channel' => array( 'Canal preferido', $profile['channel'], 'channel' ) );
        $fields = 'identity' === $section ? $identity : $conditions;
        $card_notice = sanitize_key( wp_unslash( $_GET['sw_card'] ?? '' ) ) === $section ? sanitize_key( wp_unslash( $_GET['sw_notice'] ?? '' ) ) : '';
        ?><form class="ge-sw-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-card-form><input type="hidden" name="action" value="ge_supplier_ws_card"><input type="hidden" name="supplier_id" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="card" value="<?php echo esc_attr( $section ); ?>"><?php wp_nonce_field( 'ge_supplier_ws_card_' . $key . '_' . $section ); ?><?php foreach ( $fields as $name => $field ) : ?><label><?php echo esc_html( $field[0] ); ?><?php if ( 'textarea' === $field[2] ) : ?><textarea name="<?php echo esc_attr( $name ); ?>" rows="3" maxlength="1200"><?php echo esc_textarea( $field[1] ); ?></textarea><?php elseif ( 'status' === $field[2] || 'channel' === $field[2] ) : ?><select name="<?php echo esc_attr( $name ); ?>"><?php foreach ( ( 'status' === $field[2] ? array( 'unknown' => 'Sin definir', 'active' => 'Activo', 'inactive' => 'Inactivo' ) : array( 'manual' => 'Manual', 'email' => 'Email', 'whatsapp' => 'WhatsApp' ) ) as $option => $label ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $field[1], $option ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><?php else : ?><input type="<?php echo esc_attr( $field[2] ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $field[1] ); ?>" maxlength="190"><?php endif; ?></label><?php endforeach; ?><div class="ge-sw-save"><small aria-live="polite" data-card-status><?php echo esc_html( 'saved' === $card_notice ? 'Guardado' : ( 'error' === $card_notice ? 'Error al guardar' : 'Sin cambios' ) ); ?></small><button type="submit" disabled>Guardar</button></div></form><?php
    }

    private static function render_order_row( $key, $order, $entries ) {
        if(GE_WTP_Operations::enabled()) { echo '<article class="ge-sw-order"><strong>'.esc_html($order->get_order_number()).'</strong><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('production',array('order_id'=>$order->get_id()))).'">Abrir pedido →</a></article>'; return; }
        $payables = (array) $order->get_meta( '_ge_supplier_payables', true ); $cost = $payables[ $key ]['amount'] ?? '';
        $paid = 0; foreach ( $entries as $entry ) { if ( 'payment' === ( $entry['type'] ?? '' ) && absint( $entry['order_id'] ?? 0 ) === $order->get_id() && 'ARS' === ( $entry['currency'] ?? 'ARS' ) ) { $paid += (float) ( $entry['amount'] ?? 0 ); } }
        $dispatch = (array) $order->get_meta( '_ge_supplier_dispatch_history', true ); $workflow = (array) $order->get_meta( GE_WTP_Workflow_Dispatch::HISTORY_META, true );
        $supplier_history = array_values( array_filter( $workflow, static function ( $entry ) use ( $key ) { return $key === ( $entry['supplier'] ?? '' ); } ) );
        $last_dispatch = $supplier_history ? end( $supplier_history ) : ( $dispatch ? end( $dispatch ) : array() );
        $sent_ok = ! empty( $last_dispatch['sent'] ) || ! empty( $last_dispatch['success'] );
        $sent = ! $last_dispatch ? 'Sin envío registrado' : ( $sent_ok ? 'Orden enviada' : 'Último envío fallido' );
        $grants = (array) $order->get_meta( GE_WTP_Workflow_Dispatch::GRANTS_META, true );
        $file_count = 0; foreach ( $grants as $grant ) { if ( $key === ( $grant['supplier'] ?? '' ) ) { $file_count += count( (array) ( $grant['document_ids'] ?? array() ) ); } }
        $portal_row = GE_WTP_Supplier_Portal::latest( $order, $key, true );
        if ( $portal_row ) {
            $sent = GE_WTP_Supplier_Portal::state_label( $portal_row ) . ' · ETA: ' . ( $portal_row['eta'] ?? 'Sin informar' );
            $file_count = count( $portal_row['snapshot']['files'] );
        }
        $payment_state = '' === $cost ? 'Costo pendiente' : ( $paid <= 0 ? 'Sin pagos' : ( $paid < (float) $cost ? 'Pago parcial' : 'Pagado' ) );
        ?><article class="ge-sw-order"><div><strong>#<?php echo esc_html( $order->get_id() ); ?> · <?php echo esc_html( implode( ', ', array_map( static function ( $item ) { return $item->get_name(); }, $order->get_items( 'line_item' ) ) ) ); ?></strong><small><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : 'Sin fecha' ); ?> · <?php echo esc_html( $order->get_meta( '_ge_production_status', true ) ?: $order->get_status() ); ?> · <?php echo esc_html( $sent ); ?></small><small>Fecha requerida: <?php echo esc_html( $order->get_meta( '_ge_production_promised_date', true ) ?: 'Sin cargar' ); ?> · <?php echo esc_html( $file_count ); ?> archivo(s) vinculados a envíos privados</small></div><div class="ge-sw-order-right"><span><?php echo '' === $cost ? 'Costo pendiente' : self::money( $cost ); ?></span><small><?php echo esc_html( $payment_state ); ?> · <?php echo '' === $cost ? 'Saldo desconocido' : 'Saldo ' . self::money( (float) $cost - $paid ); ?></small><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ); ?>">Abrir pedido →</a></div><details><summary>Registrar costo</summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_ws_payable"><input type="hidden" name="supplier_id" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_supplier_ws_payable_' . $key . '_' . $order->get_id() ); ?><label>Costo acordado · ARS<input type="number" min="0" step="0.01" name="amount" value="<?php echo esc_attr( $cost ); ?>" required></label><label>Vencimiento<input type="date" name="due_date" value="<?php echo esc_attr( $payables[ $key ]['due_date'] ?? '' ); ?>"></label><button type="submit">Guardar costo</button></form></details></article><?php
    }

    private static function render_entry_list( $key, $entries, $type ) {
        $found = false;
        foreach ( $entries as $row ) { if ( $type !== ( $row['type'] ?? '' ) ) { continue; } $found = true; ?><article class="ge-sw-entry"><div><strong><?php echo esc_html( $row['title'] ?? '' ); ?></strong><small><?php echo esc_html( $row['created_at'] ?? '' ); ?><?php if ( ! empty( $row['order_id'] ) ) : ?> · Pedido #<?php echo esc_html( $row['order_id'] ); ?><?php endif; ?><?php if ( 'document' === $type ) : ?> · <?php echo esc_html( $row['document_type'] ?? 'otro' ); ?><?php endif; ?><?php if ( 'price_list' === $type ) : ?> · <?php echo ! empty( $row['active'] ) ? 'Vigente' : 'Histórica'; ?> · <?php echo esc_html( $row['valid_from'] ?? '' ); ?><?php endif; ?></small><?php GE_WTP_File_Analysis::render( $row ); ?><?php if ( ! empty( $row['notes'] ) ) : ?><p><?php echo nl2br( esc_html( $row['notes'] ) ); ?></p><?php endif; ?></div><div><?php if ( 'payment' === $type ) : ?><?php echo self::money( $row['amount'] ?? 0, $row['currency'] ?? 'ARS' ); ?><?php endif; ?><?php if ( ! empty( $row['file_name'] ) ) : ?><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ge_supplier_ws_download', 'entry_id' => $row['id'] ), admin_url( 'admin-post.php' ) ), 'ge_supplier_ws_download_' . $row['id'] ) ); ?>">Descargar archivo</a><?php endif; ?><?php if ( 'price_list' === $type && empty( $row['active'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_ws_price_list_active"><input type="hidden" name="supplier_id" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="entry_id" value="<?php echo esc_attr( $row['id'] ); ?>"><?php wp_nonce_field( 'ge_supplier_ws_price_list_active_' . $row['id'] ); ?><button type="submit">Marcar vigente</button></form><?php endif; ?></div></article><?php }
        if ( ! $found ) { ?><p class="ge-sw-empty">Todavía no hay registros en este bloque.</p><?php }
    }

    private static function render_existing_invoices( $key ) {
        if ( ! class_exists( 'GE_WTP_Supplier_Invoices' ) ) { return; }
        $ids = get_posts( array( 'post_type' => GE_WTP_Supplier_Invoices::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
        $matched = array(); foreach ( $ids as $id ) { $record = GE_WTP_Supplier_Invoices::record( $id ); if ( $key === ( $record['supplier'] ?? '' ) ) { $matched[] = $record; } }
        ?><details class="ge-sw-timeline"><summary>Facturas recibidas en el módulo existente (<?php echo esc_html( count( $matched ) ); ?>)</summary><?php foreach ( $matched as $record ) : ?><article class="ge-sw-entry"><div><strong><?php echo esc_html( $record['supplier_name'] . ' · ' . ( $record['invoice_number'] ?: '#' . $record['record_id'] ) ); ?></strong><small><?php echo esc_html( $record['received_at'] ?: $record['issue_date'] ); ?> · <?php echo esc_html( $record['status'] ); ?></small></div><a href="<?php echo esc_url( GE_WTP_Staff_Portal::portal_url( 'supplier-invoices', array( 'invoice_id' => $record['record_id'] ) ) ); ?>">Abrir expediente →</a></article><?php endforeach; ?></details><?php
    }

    private static function render_catalog_costs( $key ) {
        $sources = array( 'mardones' => 'Mardones / Sur Colors', 'druck' => 'Druck', 'bandurria' => 'Bandurria Deco - Listado de precios gráfica', 'elementi' => 'Elementi', 'msbags' => 'MS Bags', 'tienda-cintas' => 'La Tienda de Cintas' );
        if ( ! isset( $sources[ $key ] ) ) { return; }
        $ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_ge_supplier_source', 'meta_value' => $sources[ $key ] ) );
        if ( ! $ids ) { return; }
        ?><details class="ge-sw-timeline"><summary>Costos estructurados del catálogo (<?php echo esc_html( count( $ids ) ); ?> productos)</summary><p class="ge-sw-hint">Valores del catálogo comercial vinculados por fuente. No sustituyen la lista de precios vigente ni el costo acordado para cada pedido.</p><?php foreach ( array_slice( $ids, 0, 40 ) as $id ) : $costs = get_post_meta( $id, '_ge_supplier_costs', true ); ?><article class="ge-sw-entry"><div><strong><?php echo esc_html( get_the_title( $id ) ); ?></strong><small><?php echo esc_html( get_post_meta( $id, '_ge_supplier_source_date', true ) ?: 'Fecha no cargada' ); ?> · <?php echo esc_html( get_post_meta( $id, '_ge_supplier_cost_unit', true ) ?: 'Unidad no cargada' ); ?></small></div><div><?php echo esc_html( is_array( $costs ) ? count( $costs ) . ' variantes de costo' : 'Costo no estructurado' ); ?></div></article><?php endforeach; ?><?php if ( count( $ids ) > 40 ) : ?><p class="ge-sw-hint">Se muestran los primeros 40 productos.</p><?php endif; ?></details><?php
    }

    private static function render_entry_form( $key, $type, $orders ) {
        $labels = array( 'payment' => 'Registrar pago', 'document' => 'Subir documento', 'price_list' => 'Subir lista de precios', 'note' => 'Agregar nota o incidencia' );
        ?><details class="ge-sw-subform"><summary>+ <?php echo esc_html( $labels[ $type ] ); ?></summary><form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_supplier_ws_entry"><input type="hidden" name="supplier_id" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="entry_type" value="<?php echo esc_attr( $type ); ?>"><?php wp_nonce_field( 'ge_supplier_ws_entry_' . $key . '_' . $type ); ?><label>Título / referencia<input type="text" name="title" maxlength="190" required></label><?php if ( 'note' !== $type && 'price_list' !== $type ) : ?><label>Pedido relacionado<select name="order_id"><option value="0">Sin pedido</option><?php foreach ( $orders as $order ) : ?><option value="<?php echo esc_attr( $order->get_id() ); ?>">#<?php echo esc_html( $order->get_id() ); ?></option><?php endforeach; ?></select></label><?php endif; ?><?php if ( 'payment' === $type ) : ?><label>Importe<input name="amount" type="number" min="0.01" step="0.01" required></label><label>Moneda<select name="currency"><option value="ARS">ARS</option><option value="USD">USD</option></select></label><label>Fecha del pago<input name="effective_date" type="date" required></label><?php endif; ?><?php if ( 'document' === $type ) : ?><label>Tipo<select name="document_type"><?php foreach ( array( 'invoice' => 'Factura', 'credit_note' => 'Nota de crédito', 'delivery_note' => 'Remito', 'receipt' => 'Comprobante', 'quote' => 'Cotización', 'certificate' => 'Certificado', 'agreement' => 'Acuerdo', 'other' => 'Otro' ) as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><?php endif; ?><?php if ( 'price_list' === $type ) : ?><label>Vigente desde<input name="valid_from" type="date" required></label><label>Moneda<select name="currency"><option value="ARS">ARS</option><option value="USD">USD</option></select></label><label>Rubro / categoría<input name="category" type="text" maxlength="120"></label><p>La nueva lista se revisa en Costos y Productos antes de activarse.</p><?php endif; ?><?php if ( 'note' !== $type ) : ?><label>Archivo <?php echo 'payment' === $type ? '(opcional)' : '(obligatorio)'; ?><input name="supplier_file" type="file" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.csv" <?php echo 'payment' === $type ? '' : 'required'; ?>></label><?php endif; ?><label>Notas<textarea name="notes" rows="3" maxlength="2000"></textarea></label><button type="submit">Guardar registro</button></form></details><?php
    }

    private static function render_timeline( $orders, $entries ) {
        $events = array();
        foreach ( $orders as $order ) {
            $time = $order->get_date_created(); if ( $time ) { $events[] = array( 'time' => $time->getTimestamp(), 'label' => 'Pedido #' . $order->get_id() . ' vinculado' ); }
            $supplier_keys = array(); foreach ( $order->get_items() as $item ) { $supplier_keys[] = $item->get_meta( '_ge_production_supplier', true ); }
            foreach ( array_unique( $supplier_keys ) as $supplier_key ) {
                $row = GE_WTP_Supplier_Portal::latest( $order, $supplier_key, true );
                if ( $row ) { foreach ( $row['events'] as $event ) { $events[] = array( 'time' => $event['time'], 'label' => 'Pedido #' . $order->get_id() . ' · ' . $event['action'] . ( ! empty( $event['eta'] ) ? ' · ETA ' . $event['eta'] : '' ) ); } }
            }
        }
        foreach ( $entries as $row ) { $events[] = array( 'time' => absint( $row['created_ts'] ?? 0 ), 'label' => ( $row['type'] ?? 'Registro' ) . ': ' . ( $row['title'] ?? '' ) ); }
        usort( $events, static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
        ?><details class="ge-sw-timeline"><summary>Ver actividad cronológica (<?php echo esc_html( count( $events ) ); ?>)</summary><ol><?php foreach ( array_slice( $events, 0, 100 ) as $event ) : ?><li><time><?php echo esc_html( wp_date( 'd/m/Y H:i', $event['time'] ) ); ?></time> · <?php echo esc_html( $event['label'] ); ?></li><?php endforeach; ?></ol></details><?php
    }

    private static function guard( $key, $nonce ) {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        if ( ! self::supplier( $key ) ) { wp_die( 'Proveedor inválido.', 404 ); }
        check_admin_referer( $nonce );
    }

    private static function redirect( $key, $notice = 'saved', $card = '' ) {
        wp_safe_redirect( self::detail_url( $key, array( 'sw_notice' => $notice, 'sw_card' => $card ) ) ); exit;
    }

    public static function handle_card() {
        $key = sanitize_key( wp_unslash( $_POST['supplier_id'] ?? '' ) ); $card = sanitize_key( wp_unslash( $_POST['card'] ?? '' ) );
        if ( ! in_array( $card, array( 'identity', 'conditions' ), true ) ) { wp_die( 'Bloque inválido.', 400 ); }
        self::guard( $key, 'ge_supplier_ws_card_' . $key . '_' . $card );
        $all = get_option( self::OPTION, array() ); $all = is_array( $all ) ? $all : array(); $data = self::data( $key );
        $profiles = get_option( GE_WTP_Supplier_Dispatch::OPTION, array() ); $profiles = is_array( $profiles ) ? $profiles : array();
        $existing = self::supplier( $key ); $profile = isset( $profiles[ $key ] ) && is_array( $profiles[ $key ] ) ? $profiles[ $key ] : $existing;
        $fields = 'identity' === $card ? array( 'legal_name', 'contact_name', 'phone', 'website', 'address', 'status' ) : array( 'category', 'materials', 'lead_time', 'minimum', 'payment_terms', 'hours', 'delivery' );
        foreach ( $fields as $field ) {
            $value = wp_unslash( $_POST[ $field ] ?? '' );
            $data[ $field ] = in_array( $field, array( 'materials', 'delivery' ), true ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
        }
        if ( 'identity' === $card ) {
            $data['status'] = in_array( $data['status'] ?? '', array( 'active', 'inactive' ), true ) ? $data['status'] : 'unknown';
            $profile['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
            if ( ! $profile['name'] ) { self::redirect( $key, 'error', $card ); }
            $profile['email'] = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
            $profile['whatsapp'] = sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) );
        } else {
            $profile['notes'] = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
            $channel = sanitize_key( wp_unslash( $_POST['channel'] ?? 'manual' ) );
            $profile['channel'] = in_array( $channel, array( 'manual', 'email', 'whatsapp' ), true ) ? $channel : 'manual';
        }
        $all[ $key ] = $data; $profiles[ $key ] = $profile;
        update_option( self::OPTION, $all, false ); update_option( GE_WTP_Supplier_Dispatch::OPTION, $profiles, false );
        self::audit( $key, 'Ficha ' . $card . ' actualizada' ); self::redirect( $key, 'saved', $card );
    }

    private static function valid_order( $id, $key ) {
        $order = wc_get_order( absint( $id ) );
        return $order instanceof WC_Order && self::order_has_supplier( $order, $key ) ? $order : false;
    }

    public static function handle_payable() { if(GE_WTP_Operations::enabled()) wp_die('Usá la cuenta del proveedor en Administración para registrar costos.');
        $key = sanitize_key( wp_unslash( $_POST['supplier_id'] ?? '' ) ); $id = absint( $_POST['order_id'] ?? 0 );
        self::guard( $key, 'ge_supplier_ws_payable_' . $key . '_' . $id ); $order = self::valid_order( $id, $key );
        if ( ! $order ) { wp_die( 'Pedido no vinculado al proveedor.', 400 ); }
        $raw = wp_unslash( $_POST['amount'] ?? '' );
        if ( ! is_numeric( $raw ) || (float) $raw < 0 ) { self::redirect( $key, 'error' ); }
        $date = sanitize_text_field( wp_unslash( $_POST['due_date'] ?? '' ) );
        if ( $date && ! self::valid_date( $date ) ) { self::redirect( $key, 'error' ); }
        $payables = (array) $order->get_meta( '_ge_supplier_payables', true );
        $payables[ $key ] = array( 'amount' => number_format( (float) $raw, 2, '.', '' ), 'currency' => 'ARS', 'due_date' => $date, 'updated_at' => current_time( 'mysql' ), 'updated_by' => get_current_user_id() );
        $order->update_meta_data( '_ge_supplier_payables', $payables ); $order->save();
        self::audit( $key, 'Costo de pedido #' . $id . ' actualizado' ); self::redirect( $key );
    }

    public static function handle_assign() {
        $key = sanitize_key( wp_unslash( $_POST['supplier_id'] ?? '' ) ); self::guard( $key, 'ge_supplier_ws_assign_' . $key );
        $id = absint( $_POST['order_id'] ?? 0 ); $order = wc_get_order( $id );
        if ( ! $order instanceof WC_Order || ! GE_WTP_Production::is_production_order( $order ) ) { self::redirect( $key, 'error' ); }
        if ( class_exists( 'GE_WTP_Workflow' ) && GE_WTP_Workflow::enabled( $order ) ) {
            wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'production', array( 'order_id' => $id ) ) ); exit;
        }
        $old = (string) $order->get_meta( '_ge_production_supplier', true );
        if ( $old && ! in_array( $old, array( 'pending', $key ), true ) ) { self::redirect( $key, 'error' ); }
        $order->update_meta_data( '_ge_production_supplier', $key ); $order->update_meta_data( '_ge_production_assignment_reason', 'Asignado desde ficha del proveedor por usuario #' . get_current_user_id() ); $order->save();
        self::audit( $key, 'Pedido #' . $id . ' asignado' ); self::redirect( $key );
    }

    private static function valid_date( $date ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return false; }
        $parts = explode( '-', $date ); return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    private static function upload( $key ) {
        $file = $_FILES['supplier_file'] ?? null;
        if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) { return array(); }
        if ( UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > self::MAX_FILE ) { return false; }
        $original = sanitize_file_name( $file['name'] ); $ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
        $allowed = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv' );
        if ( ! isset( $allowed[ $ext ] ) ) { return false; }
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $original, $allowed );
        if ( $checked['ext'] !== $ext && ! ( 'jpeg' === $ext && 'jpg' === $checked['ext'] ) ) { return false; }
        $root = WP_CONTENT_DIR . '/ge-private/supplier-workspace'; $base = $root . '/' . $key;
        if ( ! wp_mkdir_p( $base ) ) { return false; }
        foreach ( array( $root, $base ) as $directory ) {
            if ( ! is_file( $directory . '/.htaccess' ) && false === file_put_contents( $directory . '/.htaccess', "Require all denied\n" ) ) { return false; }
            if ( ! is_file( $directory . '/index.php' ) && false === file_put_contents( $directory . '/index.php', "<?php\nhttp_response_code(404);\nexit;\n" ) ) { return false; }
        }
        $name = wp_generate_uuid4() . '.' . $ext; $path = $base . '/' . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) { return false; }
        @chmod( $path, 0640 );
        return array( 'file_name' => $original, 'stored_name' => $name, 'file_hash' => hash_file( 'sha256', $path ), 'file_size' => (int) $file['size'] );
    }

    /** Reuses the workspace document store with a supplier-scoped portal grant. */
    public static function portal_document( $order_id, $token, $type ) {
        $order = wc_get_order( $order_id ); $grant = GE_WTP_Supplier_Portal::authorize( $order, $token );
        if ( ! $grant || ! in_array( $type, array( 'invoice', 'delivery_note', 'other' ), true ) ) { return new WP_Error( 'document', 'Acceso al documento no autorizado.' ); }
        $stored = self::upload( $grant['supplier'] );
        if ( ! $stored || empty( $stored['stored_name'] ) ) { return new WP_Error( 'file', 'Elegí un PDF o imagen válido de hasta 20 MB.' ); }
        $row = array_merge( array( 'type' => 'document', 'document_type' => $type, 'title' => $stored['file_name'], 'order_id' => $order_id, 'notes' => 'Recibido desde el portal del proveedor.', 'created_at' => current_time( 'mysql' ), 'created_ts' => time(), 'created_by' => 0, 'source' => 'supplier_portal', 'dispatch_id' => $grant['id'] ), $stored );
        $id = wp_insert_post( array( 'post_type' => self::ENTRY, 'post_status' => 'private', 'post_title' => $stored['file_name'] ), true );
        if ( is_wp_error( $id ) || ! $id ) { return new WP_Error( 'document', 'No se pudo registrar el documento.' ); }
        update_post_meta( $id, '_ge_supplier_key', $grant['supplier'] ); update_post_meta( $id, self::META, $row );
        GE_WTP_Supplier_Portal::audit( $order, 'graph.supplier.upload_document', $grant['supplier'], array( 'entry_id' => $id, 'dispatch_id' => $grant['id'], 'checksum' => $stored['file_hash'] ) ); $order->save();
        return $grant;
    }

    public static function handle_entry() { if(GE_WTP_Operations::enabled() && 'payment' === ($_POST['entry_type']??'')) wp_die('Usá la cuenta del proveedor en Administración para registrar pagos.');
        $key = sanitize_key( wp_unslash( $_POST['supplier_id'] ?? '' ) ); $type = sanitize_key( wp_unslash( $_POST['entry_type'] ?? '' ) );
        if ( ! in_array( $type, array( 'payment', 'document', 'price_list', 'note' ), true ) ) { wp_die( 'Registro inválido.', 400 ); }
        self::guard( $key, 'ge_supplier_ws_entry_' . $key . '_' . $type );
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ); if ( ! $title ) { self::redirect( $key, 'error' ); }
        $order_id = absint( $_POST['order_id'] ?? 0 ); if ( $order_id && ! self::valid_order( $order_id, $key ) ) { self::redirect( $key, 'error' ); }
        $row = array( 'type' => $type, 'title' => $title, 'order_id' => $order_id, 'notes' => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), 'created_at' => current_time( 'mysql' ), 'created_ts' => time(), 'created_by' => get_current_user_id() );
        if ( 'payment' === $type ) {
            $raw = wp_unslash( $_POST['amount'] ?? '' ); $date = sanitize_text_field( wp_unslash( $_POST['effective_date'] ?? '' ) );
            if ( ! is_numeric( $raw ) || (float) $raw <= 0 || ! self::valid_date( $date ) ) { self::redirect( $key, 'error' ); }
            $row['amount'] = number_format( (float) $raw, 2, '.', '' ); $row['effective_date'] = $date;
        }
        if ( in_array( $type, array( 'payment', 'price_list' ), true ) ) { $currency = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'ARS' ) ) ); $row['currency'] = in_array( $currency, array( 'ARS', 'USD' ), true ) ? $currency : 'ARS'; }
        if ( 'document' === $type ) { $row['document_type'] = sanitize_key( wp_unslash( $_POST['document_type'] ?? 'other' ) ); }
        if ( 'price_list' === $type ) {
            $date = sanitize_text_field( wp_unslash( $_POST['valid_from'] ?? '' ) ); if ( ! self::valid_date( $date ) ) { self::redirect( $key, 'error' ); }
            $row['valid_from'] = $date; $row['category'] = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ); $row['active'] = GE_WTP_Cost_Engine::enabled() ? false : ! empty( $_POST['active'] );
        }
        $file = self::upload( $key ); if ( false === $file || ( in_array( $type, array( 'document', 'price_list' ), true ) && ! $file ) ) { self::redirect( $key, 'error' ); }
        $row = array_merge( $row, $file );
        $id = wp_insert_post( array( 'post_type' => self::ENTRY, 'post_status' => 'private', 'post_title' => $title ), true );
        if ( is_wp_error( $id ) ) { self::redirect( $key, 'error' ); }
        update_post_meta( $id, '_ge_supplier_key', $key ); update_post_meta( $id, self::META, $row );
        if ( 'price_list' === $type && ! empty( $row['active'] ) ) {
            foreach ( self::entries( $key ) as $prior ) { if ( $prior['id'] === $id || 'price_list' !== ( $prior['type'] ?? '' ) || empty( $prior['active'] ) ) { continue; } $prior['active'] = false; $prior_id = $prior['id']; unset( $prior['id'] ); update_post_meta( $prior_id, self::META, $prior ); }
        }
        if('price_list'===$type && GE_WTP_Cost_Engine::enabled()) { GE_WTP_Cost_Engine::put($id,GE_WTP_Cost_Engine::LIST_META,array('status'=>'draft','items'=>array())); GE_WTP_Cost_Engine::audit('list_uploaded',$id,null,array('supplier_id'=>$key,'checksum'=>$row['file_hash'],'title'=>$title)); wp_safe_redirect(GE_WTP_Cost_UI::url('lists',array('list_id'=>$id,'supplier_id'=>$key))); exit; } self::audit( $key, $type . ': ' . $title ); self::redirect( $key );
    }

    public static function handle_price_list_active() {
        $key = sanitize_key( wp_unslash( $_POST['supplier_id'] ?? '' ) ); $id = absint( $_POST['entry_id'] ?? 0 );
        self::guard( $key, 'ge_supplier_ws_price_list_active_' . $id ); if(GE_WTP_Cost_Engine::enabled()){wp_safe_redirect(GE_WTP_Cost_UI::url('lists',array('list_id'=>$id,'supplier_id'=>$key)));exit;}
        if ( get_post_type( $id ) !== self::ENTRY || $key !== get_post_meta( $id, '_ge_supplier_key', true ) ) { self::redirect( $key, 'error' ); }
        $selected = get_post_meta( $id, self::META, true ); if ( ! is_array( $selected ) || 'price_list' !== ( $selected['type'] ?? '' ) ) { self::redirect( $key, 'error' ); }
        foreach ( self::entries( $key ) as $row ) { if ( 'price_list' !== ( $row['type'] ?? '' ) ) { continue; } $row_id = $row['id']; unset( $row['id'] ); $row['active'] = $row_id === $id; update_post_meta( $row_id, self::META, $row ); }
        self::audit( $key, 'Lista de precios #' . $id . ' marcada vigente' ); self::redirect( $key );
    }

    public static function handle_download() {
        if ( ! GE_WTP_Staff_Portal::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $id = absint( $_GET['entry_id'] ?? 0 ); check_admin_referer( 'ge_supplier_ws_download_' . $id );
        $row = get_post_meta( $id, self::META, true ); $key = sanitize_key( get_post_meta( $id, '_ge_supplier_key', true ) );
        if ( get_post_type( $id ) !== self::ENTRY || get_post_status( $id ) !== 'private' || ! self::supplier( $key ) || ! is_array( $row ) || empty( $row['stored_name'] ) ) { wp_die( 'Archivo no disponible.', 404 ); }
        $stored = basename( $row['stored_name'] ); $path = WP_CONTENT_DIR . '/ge-private/supplier-workspace/' . $key . '/' . $stored;
        if ( ! is_file( $path ) ) { wp_die( 'Archivo no disponible.', 404 ); }
        nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $row['file_name'] ) ) . '"' ); header( 'Content-Length: ' . filesize( $path ) ); readfile( $path ); exit;
    }

    private static function audit( $key, $label ) {
        $id = wp_insert_post( array( 'post_type' => self::ENTRY, 'post_status' => 'private', 'post_title' => sanitize_text_field( $label ) ), true );
        if ( is_wp_error( $id ) ) { return; }
        update_post_meta( $id, '_ge_supplier_key', $key );
        update_post_meta( $id, self::META, array( 'type' => 'event', 'title' => sanitize_text_field( $label ), 'created_at' => current_time( 'mysql' ), 'created_ts' => time(), 'created_by' => get_current_user_id() ) );
    }
}
