<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Additive shell. Operational modules keep their existing handlers and permissions. */
final class GE_WTP_Gestion_V3 {
    const META = '_ge_work_number';
    const SCHEMA = 'ge_work_numbers_schema';

    public static function init() {
        add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
        add_action( 'save_post_ge_commercial_quote', array( __CLASS__, 'quote_created' ), 20, 3 );
        add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'order_saving' ), 20, 2 );
        add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'order_saved' ), 20, 2 );
        add_filter( 'woocommerce_order_number', array( __CLASS__, 'order_number' ), 20, 2 );
        add_filter( 'woocommerce_order_query_args', array( __CLASS__, 'production_number_search' ), 20 );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'ge_work_numbers'; }
    public static function numbers_enabled() { return 'yes' === get_option( 'ge_work_numbers_enabled', 'no' ); }
    public static function shell_enabled() { return 'yes' === get_option( 'ge_gestion_v3_enabled', 'no' ); }

    /** Explicit migration only. Reads never perform DDL or renumber historical objects. */
    public static function install_numbers() {
        global $wpdb;
        $table = self::table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $table (
            work_number bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            quote_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (work_number),
            UNIQUE KEY quote_id (quote_id),
            UNIQUE KEY order_id (order_id)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ' AUTO_INCREMENT=10001;' );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $table !== $exists ) { throw new RuntimeException( 'No se pudo preparar la secuencia comercial.' ); }
        $next = $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
        if ( (int) $next < 10001 && false === $wpdb->query( "ALTER TABLE $table AUTO_INCREMENT=10001" ) ) { throw new RuntimeException( 'No se pudo inicializar la secuencia comercial.' ); }
        update_option( self::SCHEMA, 1, false );
    }

    public static function enable_numbers() {
        self::install_numbers();
        if ( self::numbers_enabled() ) { return; }
        global $wpdb;
        // High-water marks protect existing records even when edited or retried later.
        $quote_floor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type=%s", GE_WTP_Commercial_Quotes::POST_TYPE ) );
        $orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids', 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ) ) );
        add_option( 'ge_work_numbers_quote_floor', $quote_floor, '', false );
        add_option( 'ge_work_numbers_order_floor', $orders ? max( $orders ) : 0, '', false );
        add_option( 'ge_work_numbers_activated_at', gmdate( 'Y-m-d H:i:s' ), '', false );
        update_option( 'ge_work_numbers_enabled', 'yes', false );
    }

    public static function lookup( $column, $id ) {
        if ( ! in_array( $column, array( 'quote_id', 'order_id' ), true ) || ! $id || ! get_option( self::SCHEMA ) ) { return 0; }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT work_number FROM ' . self::table() . " WHERE $column=%d", $id ) );
    }

    /** InnoDB AUTO_INCREMENT + UNIQUE ownership. No MAX+1, option counter or reuse. */
    public static function allocate( $column, $id ) {
        if ( ! in_array( $column, array( 'quote_id', 'order_id' ), true ) || absint( $id ) < 1 || ! self::numbers_enabled() ) { throw new InvalidArgumentException( 'Asignación comercial no habilitada.' ); }
        $existing = self::lookup( $column, $id );
        if ( $existing ) { return $existing; }
        global $wpdb;
        $result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . " ($column, created_at) VALUES (%d,%s)", $id, gmdate( 'Y-m-d H:i:s' ) ) );
        $number = self::lookup( $column, $id );
        if ( false === $result || $number < 10001 ) { throw new RuntimeException( 'No se pudo asignar la referencia comercial. Volvé a intentar.' ); }
        return $number;
    }

    public static function quote_created( $id, $post, $update ) {
        if ( ! self::numbers_enabled() || $id <= (int) get_option( 'ge_work_numbers_quote_floor', 0 ) || 'auto-draft' === $post->post_status || wp_is_post_revision( $id ) ) { return; }
        update_post_meta( $id, self::META, self::allocate( 'quote_id', $id ) );
    }

    public static function quote_number( $id ) {
        $number = self::lookup( 'quote_id', $id );
        return $number ? '#' . $number : 'GE-PRE-' . $id;
    }

    public static function order_saving( $order, $store = null ) {
        try { self::assign_order( $order ); }
        catch ( Exception $error ) {
            // WC_Order::save catches Exception and otherwise silently returns its
            // technical ID. Stop explicitly so conversion cannot report success.
            wp_die( esc_html( $error->getMessage() ), 'Referencia comercial', array( 'response'=>409, 'back_link'=>true ) );
        }
    }

    public static function assign_order( $order ) {
        if ( ! $order instanceof WC_Order || ! self::numbers_enabled() || ! $order->get_id() ) { return; }
        // Payment attempts/refunds are transactions, not a second operational job.
        if ( 'yes' === $order->get_meta( '_ge_commercial_payment_order', true ) || 'ge_commercial_quote_payment' === $order->get_created_via() || 'yes' === $order->get_meta( '_ge_work_order', true ) ) { return; }
        $quote_id = absint( $order->get_meta( '_ge_commercial_quote_id', true ) );
        $existing = self::lookup( 'order_id', $order->get_id() );
        if ( $quote_id ) {
            $number = self::lookup( 'quote_id', $quote_id );
            if ( ! $number ) { return; } // Legacy quote conversion remains legacy.
            global $wpdb;
            $result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET order_id=%d WHERE quote_id=%d AND (order_id IS NULL OR order_id=%d)', $order->get_id(), $quote_id, $order->get_id() ) );
            if ( false === $result || self::lookup( 'order_id', $order->get_id() ) !== $number ) { throw new RuntimeException( 'El presupuesto ya tiene otro pedido vinculado.' ); }
        } elseif ( $existing ) { $number = $existing;
        } else {
            if ( $order->get_id() <= (int) get_option( 'ge_work_numbers_order_floor', 0 ) ) { return; }
            // wc_create_order saves an empty object before provenance is set.
            if ( ! $order->get_items( 'line_item' ) || class_exists( 'GE_WTP_Customer_Quotes' ) && GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { return; }
            $number = self::allocate( 'order_id', $order->get_id() );
        }
        $order->update_meta_data( self::META, $number );
    }

    /** A checkout can save its populated WC_Order only once, starting with ID=0. */
    public static function order_saved( $order, $store = null ) {
        $before = (int) $order->get_meta( self::META, true );
        self::order_saving( $order, $store );
        if ( (int) $order->get_meta( self::META, true ) !== $before ) { $order->save_meta_data(); }
    }

    public static function order_number( $original, $order ) {
        $number = self::lookup( 'order_id', $order->get_id() );
        return $number ? (string) $number : $original;
    }

    /** Exact commercial search across production pages without editing its module. */
    public static function production_number_search( $args ) {
        if ( ! self::shell_enabled() || ! GE_WTP_Staff_Portal::can_access() || 'production' !== ( $_GET['section'] ?? '' ) || empty( $args['paginate'] ) || ! empty( $_GET['order_id'] ) ) { return $args; }
        $args['order'] = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'ASC' : 'DESC';
        $term = ltrim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ), '#' );
        if ( ! ctype_digit( $term ) || ! get_option( self::SCHEMA ) ) { return $args; }
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . self::table() . ' WHERE work_number=%d', $term ) );
        if ( $id ) { $args['include'] = array( (int) $id ); $args['page'] = 1; }
        return $args;
    }

    public static function template( $original ) {
        return self::shell_enabled() && is_page( 'gestion' ) && GE_WTP_Staff_Portal::can_access() ? GE_WTP_PLUGIN_DIR . 'templates/gestion-v3.php' : $original;
    }

    public static function nav() { $nav=array('dashboard'=>'Inicio','customers'=>'Clientes','jobs'=>'Trabajos'); if(GE_WTP_Operations::enabled()) { if(GE_WTP_Operations::module_enabled('suppliers')) $nav['suppliers']='Proveedores'; if(GE_WTP_Operations::module_enabled('stock')&&(current_user_can('ge_manage_inventory')||current_user_can('manage_options'))) $nav['stock']='Stock'; if(GE_WTP_Operations::module_enabled('administration')&&(current_user_can('ge_view_finance')||current_user_can('manage_options'))) $nav['administration']='Administración'; } if(GE_WTP_Cost_Engine::enabled()&&(current_user_can('ge_view_costs')||current_user_can('manage_options'))) $nav['costs']='Costos y Productos'; if(class_exists('GE_WTP_Customer_Invoices')&&GE_WTP_Customer_Invoices::staff(get_current_user_id())) $nav['invoice-reviews']='Revisiones'; $nav['communications']='Comunicaciones'; if(!GE_WTP_Work_Panel::accessible())unset($nav['jobs']); return $nav; }
    public static function status_label( $key ) {
        $labels=array('draft'=>'Borrador','sent'=>'Publicado','viewed'=>'Visto','accepted'=>'Aceptado','converted'=>'Convertido','rejected'=>'Rechazado','expired'=>'Vencido','cancelled'=>'Cancelado');
        return $labels[$key]??ucfirst(str_replace('_',' ',$key));
    }
    public static function url( $section = '', $args = array() ) { return GE_WTP_Staff_Portal::portal_url( $section, $args ); }

    public static function icon( $name ) {
        $paths = array(
            'costs'=>'M4 3h16v18H4z M8 7h8 M8 11h2 M14 11h2 M8 15h2 M14 15h2', 'stock'=>'M3 7l9-4 9 4v10l-9 4-9-4z M3 7l9 4 9-4 M12 11v10', 'suppliers'=>'M3 21V7l9-4 9 4v14z M7 10h2 M15 10h2 M10 21v-4h4v4', 'administration'=>'M3 5h18v14H3z M16 12h5 M7 9h3 M7 15h6',
            'dashboard'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
            'customers'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
            'quotes'=>'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h5',
            'jobs'=>'M3 7h18v14H3z M8 7V3h8v4 M3 12h18 M10 12v3h4v-3',
            'orders'=>'M6 6h12l2 15H4z M9 7V5a3 3 0 0 1 6 0v2',
            'production'=>'M3 21V9l6 4V9l6 4V3h5v18z M7 17h1 M12 17h1 M17 17h1',
            'communications'=>'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z',
            'search'=>'M21 21l-4.4-4.4 M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16',
            'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
            'plus'=>'M12 5v14 M5 12h14', 'menu'=>'M3 6h18 M3 12h18 M3 18h18',
            'arrow'=>'M5 12h14 M13 6l6 6-6 6', 'files'=>'M6 3h12v14H6z M3 7v14h12',
            'supplier'=>'M3 21V7l9-4 9 4v14z M7 10h2 M15 10h2 M7 14h2 M15 14h2 M10 21v-4h4v4',
            'eye'=>'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12 M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6'
        );
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . esc_attr( $paths[$name] ?? $paths['arrow'] ) . '"/></svg>';
    }

    public static function domain_tabs( $active = 'queue' ) {
        $tabs = array( 'queue'=>'Trabajos', 'suppliers'=>'Proveedores', 'documents'=>'Documentos de proveedor', 'events'=>'Incidencias' );
        echo '<nav class="ge-v3-tabs" aria-label="Producción">';
        foreach ( $tabs as $key=>$label ) {
            $url = 'documents' === $key ? self::url( 'supplier-invoices' ) : self::url( 'production', array( 'view'=>$key ) );
            $selected = $key === $active || 'supplier' === $active && 'suppliers' === $key;
            echo '<a ' . ( $selected ? 'aria-current="page" class="is-active"' : '' ) . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    public static function secondary_links() { GE_WTP_Operations_UI::dashboard();
        $items = array(
            array('Archivos','Originales y versiones del trabajo','files',self::url('library')),
            array('Proveedores','Fichas, envíos y saldos','supplier',self::url('production',array('view'=>'suppliers'))),
            array('Documentos de proveedor','Facturas y comprobantes recibidos','quotes',self::url('supplier-invoices')),
            array('Candidatos',array_sum((array)wp_count_posts(GE_WTP_Jobs::POST_TYPE)) . ' postulaciones registradas','customers',self::url('candidates'))
        );
        echo '<section class="ge-v3-shortcuts" aria-label="Accesos de gestión">';
        foreach($items as $item) { if(GE_WTP_Operations::module_enabled('suppliers') && $item[0]==='Proveedores') continue; echo '<a href="' . esc_url($item[3]) . '">' . self::icon($item[2]) . '<span><strong>' . esc_html($item[0]) . '</strong><small>' . esc_html($item[1]) . '</small></span>' . self::icon('arrow') . '</a>'; }
        echo '</section>';
    }

    /** Server-side global search, bounded per category. No private storage URLs exposed. */
    public static function search( $query ) {
        global $wpdb;
        $groups = array('Clientes'=>array(), 'Presupuestos'=>array(), 'Pedidos'=>array(), 'Proveedores'=>array(), 'Archivos'=>array());
        $term = trim(mb_substr($query,0,120));
        if ( '' === $term ) { return $groups; }
        $like = '%' . $wpdb->esc_like($term) . '%';
        $user_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT u.ID FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON u.ID=m.user_id AND m.meta_key IN ('billing_phone','billing_company','_ge_whatsapp') WHERE u.display_name LIKE %s OR u.user_email LIKE %s OR m.meta_value LIKE %s ORDER BY u.ID DESC LIMIT 10",$like,$like,$like));
        foreach($user_ids as $id) { $u=get_userdata($id); $groups['Clientes'][]=array('label'=>$u->display_name,'detail'=>$u->user_email,'url'=>self::url('customers',array('customer_id'=>$id))); }
        $number = ctype_digit(ltrim($term,'#')) ? (int)ltrim($term,'#') : 0;
        $work = $number && get_option(self::SCHEMA) ? $wpdb->get_row($wpdb->prepare('SELECT quote_id,order_id FROM ' . self::table() . ' WHERE work_number=%d',$number),ARRAY_A) : array();
        $quote_ids = get_posts(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','posts_per_page'=>10,'s'=>$term,'fields'=>'ids'));
        if($user_ids) { $quote_ids=array_merge($quote_ids,get_posts(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','posts_per_page'=>10,'fields'=>'ids','meta_query'=>array(array('key'=>GE_WTP_Commercial_Quotes::CUSTOMER_META,'value'=>$user_ids,'compare'=>'IN'))))); }
        $technical_quote=absint(preg_replace('/^GE-PRE-/i','',$term));
        if(!$work && $technical_quote && GE_WTP_Commercial_Quotes::POST_TYPE===get_post_type($technical_quote)) { $quote_ids[]=$technical_quote; }
        if(!empty($work['quote_id'])) { array_unshift($quote_ids,$work['quote_id']); }
        foreach(array_slice(array_unique($quote_ids),0,10) as $id) { $q=GE_WTP_Commercial_Quotes::get($id,get_current_user_id()); if(is_wp_error($q))continue; $u=get_userdata($q['customer_id']); $groups['Presupuestos'][]=array('label'=>'Presupuesto ' . $q['number'],'detail'=>$u?$u->display_name:'Cliente','url'=>self::url('quotes',array('quote_id'=>$id))); }
        $order_ids=array();
        if(!empty($work['order_id'])) { $order_ids[]=$work['order_id']; }
        if(!$work && $number && wc_get_order($number)) { $order_ids[]=$number; }
        $order_ids=array_merge($order_ids,wc_get_orders(array('limit'=>10,'return'=>'ids','billing_email'=>$term)));
        if($user_ids) { $order_ids=array_merge($order_ids,wc_get_orders(array('limit'=>10,'return'=>'ids','customer_id'=>$user_ids))); }
        foreach(array_slice(array_unique($order_ids),0,10) as $id) { $o=wc_get_order($id); if(!$o || 'yes'===$o->get_meta('_ge_commercial_payment_order',true))continue; $groups['Pedidos'][]=array('label'=>'Pedido #' . $o->get_order_number(),'detail'=>$o->get_formatted_billing_full_name(),'url'=>self::url('orders',array('order_id'=>$id))); }
        foreach(GE_WTP_Supplier_Workspace::suppliers() as $key=>$s) { $name=$s['name']??$s['label']??$key; if(false!==mb_stripos($name.' '.$key,$term))$groups['Proveedores'][]=array('label'=>$name,'detail'=>'Ficha del proveedor','url'=>GE_WTP_Supplier_Workspace::detail_url($key)); }
        $file_ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key IN ('_ge_artwork_original_name','_ge_artwork_external_reference') WHERE p.post_type=%s AND p.post_status='publish' AND (p.post_title LIKE %s OR m.meta_value LIKE %s) ORDER BY p.ID DESC LIMIT 10",GE_WTP_Artwork_Library::POST_TYPE,$like,$like));
        foreach($file_ids as $id) { $groups['Archivos'][]=array('label'=>get_the_title($id),'detail'=>get_post_meta($id,'_ge_artwork_original_name',true),'url'=>self::url('library',array('artwork_id'=>$id))); }
        $quote_file_ids=get_posts(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','posts_per_page'=>10,'fields'=>'ids','meta_query'=>array(array('key'=>GE_WTP_Commercial_Quote_Files::META,'value'=>$term,'compare'=>'LIKE'))));
        foreach($quote_file_ids as $id) { foreach(GE_WTP_Commercial_Quote_Files::all($id) as $file) { if(false!==mb_stripos($file['name']??'',$term)) { $groups['Archivos'][]=array('label'=>$file['name'],'detail'=>'Presupuesto '.self::quote_number($id),'url'=>self::url('quotes',array('quote_id'=>$id))); } } }
        // Uploaded order documents may exist without an artwork-library record.
        $document_orders=wc_get_orders(array('limit'=>10,'return'=>'objects','meta_query'=>array(array('key'=>GE_WTP_Documents::META_KEY,'value'=>$term,'compare'=>'LIKE'))));
        foreach($document_orders as $o) { foreach(GE_WTP_Documents::get_documents($o->get_id()) as $d) { if(false!==mb_stripos($d['name']??'',$term)) { $groups['Archivos'][]=array('label'=>$d['name'],'detail'=>'Pedido #'.$o->get_order_number(),'url'=>self::url('production',array('order_id'=>$o->get_id()))); } } }
        return $groups;
    }

    public static function render_search( $query ) {
        echo '<div class="ge-staff-heading"><div><span>Gestión</span><h1>Resultados de búsqueda</h1><p>' . esc_html('Para “'.$query.'” · hasta 10 coincidencias por categoría') . '</p></div></div><div class="ge-v3-search-results">';
        $found=0;
        foreach(self::search($query) as $title=>$rows) { if(!$rows)continue; $found+=count($rows); echo '<section class="ge-admin-panel"><h2>'.esc_html($title).'</h2><ul>'; foreach(array_slice($rows,0,10) as $row) { echo '<li><a href="'.esc_url($row['url']).'"><strong>'.esc_html($row['label']).'</strong><small>'.esc_html($row['detail']).'</small>'.self::icon('arrow').'</a></li>'; } echo '</ul></section>'; }
        if(!$found)echo '<div class="ge-admin-empty">No hay coincidencias. Probá con el número, nombre, email o nombre del archivo.</div>';
        echo '</div>';
    }
}
