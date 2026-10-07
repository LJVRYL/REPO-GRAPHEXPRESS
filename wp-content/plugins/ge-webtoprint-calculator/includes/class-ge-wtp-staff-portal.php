<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-ge-wtp-supplier-invoices.php';

final class GE_WTP_Staff_Portal {
    const PAGE_SLUG = 'gestion';
    const ROLE = 'ge_staff_manager';
    const CAPABILITY = 'ge_manage_operations';

    public static function init() {
        GE_WTP_Supplier_Invoices::init();
        add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
        add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_action( 'admin_init', array( __CLASS__, 'protect_wp_admin' ) );
        add_action( 'admin_post_ge_staff_order_update', array( __CLASS__, 'handle_order_update' ) );
        add_action( 'admin_post_ge_staff_order_shipping', array( __CLASS__, 'handle_order_shipping' ) );
        add_action( 'admin_post_ge_staff_order_trash', array( __CLASS__, 'handle_order_trash' ) );
    }

    public static function install() {
        $capabilities = array(
            'read'                   => true,
            self::CAPABILITY         => true,
            'ge_manage_communications' => true,
        );
        $role = get_role( self::ROLE );
        if ( ! $role ) {
            add_role( self::ROLE, 'Gestor Graph Express', $capabilities );
        } else {
            foreach ( $capabilities as $capability => $granted ) {
                $role->add_cap( $capability, $granted );
            }
        }

        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            $administrator->add_cap( self::CAPABILITY );
            $administrator->add_cap( 'ge_manage_communications' );
        }

        $page = get_page_by_path( self::PAGE_SLUG );
        if ( ! $page ) {
            wp_insert_post(
                array(
                    'post_title'   => 'Gestión Graph Express',
                    'post_name'    => self::PAGE_SLUG,
                    'post_content' => '[ge_staff_portal]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                )
            );
        }
    }

    public static function can_access() {
        return is_user_logged_in() && ( current_user_can( self::CAPABILITY ) || current_user_can( 'manage_woocommerce' ) );
    }

    public static function portal_url( $section = '', $args = array() ) {
        if ( class_exists('GE_WTP_Work_Panel') && isset(GE_WTP_Work_Panel::sections()[$section]) && !$args ) { $args=array('tipo'=>$section); $section='jobs'; }
        $page = get_page_by_path( self::PAGE_SLUG );
        $url = $page ? get_permalink( $page ) : home_url( '/' . self::PAGE_SLUG . '/' );
        if ( $section ) {
            $args = array_merge( array( 'section' => sanitize_key( $section ) ), $args );
        }
        return $args ? add_query_arg( $args, $url ) : $url;
    }

    public static function template( $template ) {
        if ( is_page( self::PAGE_SLUG ) ) {
            return GE_WTP_PLUGIN_DIR . 'templates/staff-shell.php';
        }
        return $template;
    }

    public static function show_admin_bar( $show ) {
        return is_page( self::PAGE_SLUG ) || self::is_limited_staff() ? false : $show;
    }

    public static function login_redirect( $redirect_to, $requested, $user ) {
        if ( $user instanceof WP_User && ( user_can( $user, self::CAPABILITY ) || user_can( $user, 'manage_woocommerce' ) ) ) {
            return self::portal_url();
        }
        return $redirect_to;
    }

    public static function protect_wp_admin() {
        global $pagenow;
        if ( self::is_limited_staff() && ! wp_doing_ajax() && 'admin-post.php' !== $pagenow ) {
            wp_safe_redirect( self::portal_url() );
            exit;
        }
    }

    private static function is_limited_staff() {
        $user = wp_get_current_user();
        return $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! current_user_can( 'manage_options' );
    }

    public static function render() {
        if ( ! self::can_access() ) {
            self::render_login();
            return;
        }
        $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard';
        if ( GE_WTP_Work_Panel::contains($section) ) {
            if ( ! GE_WTP_Work_Panel::accessible() || ('jobs' !== $section && ! GE_WTP_Work_Panel::allowed($section)) ) { echo '<p>Acceso no disponible para tu rol.</p>'; return; }
            GE_WTP_Work_Panel::header($section);
            if ( GE_WTP_Work_Panel::overview($section) ) { GE_WTP_Work_Panel::render($section); return; }
        }
        GE_WTP_Job_Flow::staff( $section );
        if($section==='dashboard' && class_exists('GE_Organization_Runtime') && (!in_array(GE_Organization_Runtime::role(get_current_user_id()),array('owner','admin'),true)||GE_Organization::PRIMARY!=='graph-express'||in_array(false,GE_Organization_Runtime::settings()['modules'],true))){GE_Organization_Runtime::dashboard();return;}
        if ( 'costs' === $section && GE_WTP_Cost_Engine::enabled() ) {
            GE_WTP_Cost_UI::render();
        } elseif ( GE_WTP_Operations::enabled() && in_array($section,array('stock','administration','suppliers'),true) ) {
            GE_WTP_Operations_UI::render($section);
        } elseif ( 'requests' === $section ) {
            GE_WTP_Quote_Requests::inbox();
        } elseif ( 'quotes' === $section ) {
            GE_WTP_Commercial_Quote_UI::render_staff();
        } elseif ( 'orders' === $section ) {
            self::render_orders();
        } elseif ( 'production' === $section ) {
            GE_WTP_Production::render();
        } elseif ( 'customers' === $section ) {
            GE_WTP_Customers::render_staff();
        } elseif ( 'library' === $section ) {
            GE_WTP_Artwork_Library::render_staff();
        } elseif ( 'supplier-invoices' === $section ) {
            GE_WTP_Supplier_Invoices::render();
        } elseif ( 'invoice-reviews' === $section ) {
            GE_WTP_Customer_Invoices::render_reviews();
        } elseif ( 'communications' === $section ) {
            GE_WTP_Newsletter::render_portal();
        } elseif ( in_array( $section, array( 'settings', 'notifications' ), true ) ) {
            self::render_settings( 'notifications' === $section ? 'notifications' : '' );
        } elseif ( 'candidates' === $section ) {
            self::render_candidates();
        } else {
            self::render_dashboard();
        }
    }

    private static function render_settings( $legacy_category = '' ) {
        $category = $legacy_category ?: ( isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '' );
        $categories = array(
            'general' => array( 'icon' => 'GE', 'title' => 'General', 'description' => 'Identidad, datos del negocio y preferencias generales.' ),
            'billing' => array( 'icon' => 'FA', 'title' => 'Facturación', 'description' => 'Emisor, comprobantes habilitados y política de precios.' ),
            'notifications' => array( 'icon' => '✉', 'title' => 'Notificaciones', 'description' => 'Destinatarios, eventos, resúmenes y trazabilidad de correos.' ),
            'operations' => array( 'icon' => 'OT', 'title' => 'Pedidos y producción', 'description' => 'Criterios operativos, tiempos, estados y automatizaciones.' ),
            'customers' => array( 'icon' => 'CL', 'title' => 'Clientes y archivos', 'description' => 'Perfiles, direcciones, biblioteca y conservación de originales.' ),
            'integrations' => array( 'icon' => '↗', 'title' => 'Integraciones', 'description' => 'Correo saliente, pagos, almacenamiento y servicios externos.' ),
        );
        if ( $category && ! isset( $categories[ $category ] ) ) {
            $category = '';
        }
        wp_enqueue_style( 'ge-settings-center', GE_WTP_PLUGIN_URL . 'assets/css/settings.css', array( 'ge-staff-portal' ), GE_WTP_VERSION );
        ?>
        <div class="ge-staff-heading"><div><span>Administración</span><h1>Configuración</h1><p>Todos los ajustes del sistema, ordenados por categoría.</p></div></div>
        <?php if ( ! $category ) : ?>
            <section class="ge-settings-index" aria-label="Categorías de configuración">
                <?php foreach ( $categories as $key => $item ) : ?>
                    <a class="ge-settings-card" href="<?php echo esc_url( self::portal_url( 'settings', array( 'category' => $key ) ) ); ?>">
                        <b><?php echo esc_html( $item['icon'] ); ?></b><span><strong><?php echo esc_html( $item['title'] ); ?></strong><small><?php echo esc_html( $item['description'] ); ?></small></span><i><?php echo 'notifications' === $key ? 'Configurar →' : 'Preparado para ampliar →'; ?></i>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php else : ?>
            <div class="ge-settings-layout">
                <aside class="ge-settings-sidebar" aria-label="Categorías de configuración">
                    <a class="ge-settings-back" href="<?php echo esc_url( self::portal_url( 'settings' ) ); ?>">← Todas las categorías</a>
                    <?php foreach ( $categories as $key => $item ) : ?><a class="<?php echo $category === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::portal_url( 'settings', array( 'category' => $key ) ) ); ?>"><b><?php echo esc_html( $item['icon'] ); ?></b><span><?php echo esc_html( $item['title'] ); ?></span></a><?php endforeach; ?>
                </aside>
                <div class="ge-settings-content">
                    <?php if ( 'notifications' === $category ) : GE_WTP_Notification_Center::render( false ); elseif ( 'integrations' === $category ) : GE_WTP_Google_Auth::render_settings(); elseif ( 'billing' === $category ) : GE_WTP_Billing::render_settings(); else : $item = $categories[ $category ]; ?>
                        <section class="ge-settings-placeholder"><b><?php echo esc_html( $item['icon'] ); ?></b><span>Próxima categoría</span><h2><?php echo esc_html( $item['title'] ); ?></h2><p><?php echo esc_html( $item['description'] ); ?> La estructura ya está lista para incorporar estos controles cuando los definamos.</p><a href="<?php echo esc_url( self::portal_url( 'settings' ) ); ?>">Volver a configuración</a></section>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif;
    }

    private static function render_login() {
        $redirect = self::portal_url();
        ?>
        <section class="ge-staff-login">
            <span class="ge-staff-kicker">Acceso interno</span>
            <h1>Gestión Graph Express</h1>
            <p>Ingresá con tu cuenta de trabajo. Los clientes utilizan su portal independiente.</p>
            <?php wp_login_form( array( 'redirect' => $redirect, 'label_username' => 'Usuario o email', 'label_password' => 'Contraseña', 'label_log_in' => 'Ingresar al panel', 'remember' => true ) ); ?>
            <?php if ( class_exists( 'GE_WTP_Google_Auth' ) ) { GE_WTP_Google_Auth::render_portal_button(); } ?>
            <a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>">¿Olvidaste tu contraseña?</a>
        </section>
        <?php
    }

    private static function render_dashboard() {
        GE_WTP_Quote_Requests::dashboard();
        $orders = GE_WTP_Orders::get_all_orders( 250 );
        $quotes = get_posts( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
        $open_quotes = new WP_Query( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => GE_WTP_Commercial_Quotes::STATUS_META, 'value' => array( 'draft', 'sent', 'viewed', 'accepted' ), 'compare' => 'IN' ) ) ) );
        $awaiting_quotes = new WP_Query( array( 'post_type' => GE_WTP_Commercial_Quotes::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => GE_WTP_Commercial_Quotes::STATUS_META, 'value' => array( 'sent', 'viewed' ), 'compare' => 'IN' ) ) ) );
        $counts = array( 'orders' => 0, 'production' => 0, 'delayed' => 0, 'ready' => 0, 'blocked' => 0, 'balance' => 0 );
        $attention = array(); $recent_orders = array();
        $today = wp_date( 'Y-m-d' );
        foreach ( $orders as $order ) {
            if ( 'yes' === $order->get_meta( '_ge_commercial_payment_order', true ) || GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { continue; }
            $delivered = 'entregado' === GE_WTP_Order_Lifecycle::stage( $order );
            $closed = GE_WTP_Production::is_closed( $order );
            $cancelled = in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true );
            $ready = 'ready' === $order->get_meta( '_ge_production_status', true );
            if ( ! $delivered && ! $cancelled ) { $counts['orders']++; }
            if ( ! $closed && ! $delivered && ! $cancelled ) {
                if ( $ready ) { $counts['ready']++; }
                else { $counts['production']++; }
                $promised = (string) $order->get_meta( '_ge_production_promised_date', true );
                if ( ! $ready && $promised && $promised < $today ) { $counts['delayed']++; $attention[] = array( 'label' => 'Trabajo demorado', 'order' => $order ); }
                if ( ! $ready && ! GE_WTP_Documents::get_documents( $order->get_id() ) ) { $counts['blocked']++; }
            }
            $due = (int) $order->get_meta( '_ge_amount_due_cents', true );
            if ( $due > 0 && ! $cancelled ) { $counts['balance'] += $due; if ( $ready ) { $attention[] = array( 'label' => 'Listo con saldo pendiente', 'order' => $order ); } }
            if ( count( $recent_orders ) < 6 ) { $recent_orders[] = $order; }
        }
        $activity = array();
        foreach ( $recent_orders as $order ) {
            $date = $order->get_date_created();
            if ( $date ) { $activity[] = array( 'time' => $date->getTimestamp(), 'label' => 'Pedido #' . $order->get_order_number() . ' creado', 'url' => self::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); }
            $history = $order->get_meta( '_ge_production_closure_history', true );
            if ( is_array( $history ) ) { foreach ( array_slice( $history, -2 ) as $event ) { if ( ! empty( $event['time'] ) ) { $activity[] = array( 'time' => absint( $event['time'] ), 'label' => 'closed' === ( $event['event'] ?? '' ) ? 'Trabajo #' . $order->get_order_number() . ' cerrado' : 'Trabajo #' . $order->get_order_number() . ' reabierto', 'url' => self::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ); } } }
        }
        foreach ( array_slice( $quotes, 0, 4 ) as $post ) { $activity[] = array( 'time' => strtotime( $post->post_date ), 'label' => 'Presupuesto ' . ( class_exists('GE_WTP_Gestion_V3') ? GE_WTP_Gestion_V3::quote_number($post->ID) : 'GE-PRE-' . $post->ID ) . ' creado', 'url' => self::portal_url( 'quotes', array( 'quote_id' => $post->ID ) ) ); }
        usort( $activity, function( $a, $b ) { return $b['time'] <=> $a['time']; } );
        $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        ?>
        <div class="ge-control-hero"><div><span>GRAPHEX · CENTRO OPERATIVO</span><h1>Inicio</h1><p>Presupuestos, pedidos y producción en una sola vista.</p></div><div class="ge-control-actions"><a href="<?php echo esc_url( self::portal_url( 'quotes', array( 'new' => 1 ) ) ); ?>">Nuevo presupuesto</a><a href="<?php echo esc_url( self::portal_url( 'production', array( 'view' => 'new' ) ) ); ?>">Nuevo pedido</a></div></div>
        <form class="ge-control-search" method="get" action="<?php echo esc_url( self::portal_url() ); ?>"><label for="ge-global-q">Buscar cliente, presupuesto o pedido</label><div><input id="ge-global-q" type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Número, nombre, email o teléfono"><button type="submit">Buscar</button></div></form>
        <?php if ( $q ) { self::render_dashboard_search( $q, $orders, $quotes ); } ?>
        <div class="ge-control-kpis"><a href="<?php echo esc_url( self::portal_url( 'quotes' ) ); ?>"><span>Presupuestos abiertos</span><strong><?php echo esc_html( $open_quotes->found_posts ); ?></strong></a><a href="<?php echo esc_url( self::portal_url( 'quotes' ) ); ?>"><span>Esperan respuesta</span><strong><?php echo esc_html( $awaiting_quotes->found_posts ); ?></strong></a><a href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>"><span>Pedidos activos</span><strong><?php echo esc_html( $counts['orders'] ); ?></strong></a><a href="<?php echo esc_url( self::portal_url( 'production' ) ); ?>"><span>Trabajos abiertos</span><strong><?php echo esc_html( $counts['production'] ); ?></strong></a><a class="is-alert" href="<?php echo esc_url( self::portal_url( 'production', array( 'filter' => 'delayed' ) ) ); ?>"><span>Demorados</span><strong><?php echo esc_html( $counts['delayed'] ); ?></strong></a><a href="<?php echo esc_url( self::portal_url( 'production', array( 'filter' => 'ready' ) ) ); ?>"><span>Listos para entrega</span><strong><?php echo esc_html( $counts['ready'] ); ?></strong></a></div>
        <?php if ( count( $orders ) >= 250 ) : ?><p class="ge-control-scope">Pedidos y producción: vista de los 250 pedidos más recientes. Abrí cada sección para revisar el historial.</p><?php endif; ?>
        <div class="ge-control-grid"><section class="ge-control-panel ge-control-attention"><header><div><span>PRIORIDAD</span><h2>Atención hoy</h2></div><a href="<?php echo esc_url( self::portal_url( 'production', array( 'filter' => 'delayed' ) ) ); ?>">Ver producción →</a></header><div class="ge-control-highlights"><div><strong><?php echo esc_html( $counts['blocked'] ); ?></strong><span>trabajos sin archivos</span></div><div><strong><?php echo wp_kses_post( wc_price( $counts['balance'] / 100 ) ); ?></strong><span>saldos registrados</span></div></div><?php if ( $attention ) : ?><ul><?php foreach ( array_slice( $attention, 0, 5 ) as $alert ) : $order = $alert['order']; ?><li><span><?php echo esc_html( $alert['label'] ); ?></span><a href="<?php echo esc_url( self::portal_url( 'production', array( 'order_id' => $order->get_id() ) ) ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?> · <?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?> →</a></li><?php endforeach; ?></ul><?php else : ?><p>No hay urgencias detectadas en los pedidos recientes.</p><?php endif; ?></section>
        <section class="ge-control-panel"><header><div><span>COMERCIAL</span><h2>Presupuestos recientes</h2></div><a href="<?php echo esc_url( self::portal_url( 'quotes' ) ); ?>">Ver todos →</a></header><?php if ( $quotes ) : ?><ul><?php foreach ( $quotes as $post ) : $quote = GE_WTP_Commercial_Quotes::get( $post->ID, get_current_user_id() ); if ( is_wp_error( $quote ) ) { continue; } $customer = get_userdata( $quote['customer_id'] ); ?><li><span><?php echo esc_html( $quote['number'] . ' · ' . ( ( class_exists('GE_WTP_Gestion_V3') ? GE_WTP_Gestion_V3::status_label($quote['status']) : $quote['status'] ) ?: 'sin estado' ) . ' · ' . wp_date( 'd/m', strtotime( $post->post_date ) ) . ' · ' . ( isset( $quote['snapshot']['total_cents'] ) ? number_format_i18n( $quote['snapshot']['total_cents'] / 100, 2 ) . ' ARS' : 'total a confirmar' ) ); ?></span><a href="<?php echo esc_url( self::portal_url( 'quotes', array( 'quote_id' => $quote['id'] ) ) ); ?>"><?php echo esc_html( $customer ? $customer->display_name : 'Cliente' ); ?> →</a></li><?php endforeach; ?></ul><?php else : ?><p>Todavía no hay presupuestos comerciales.</p><?php endif; ?></section>
        <section class="ge-control-panel"><header><div><span>VENTAS</span><h2>Pedidos recientes</h2></div><a href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">Ver todos →</a></header><?php if ( $recent_orders ) : ?><ul><?php foreach ( $recent_orders as $order ) : ?><li><span>#<?php echo esc_html( $order->get_order_number() ); ?> · <?php echo esc_html( GE_WTP_Order_Lifecycle::label( $order ) ) . ' · ' . wp_kses_post( $order->get_formatted_order_total() ); ?></span><a href="<?php echo esc_url( self::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); ?>"><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?> →</a></li><?php endforeach; ?></ul><?php else : ?><p>Todavía no hay pedidos.</p><?php endif; ?></section>
        <section class="ge-control-panel"><header><div><span>PRODUCCIÓN</span><h2>Seguimiento</h2></div><a href="<?php echo esc_url( self::portal_url( 'production' ) ); ?>">Ver producción →</a></header><div class="ge-control-stages"><a href="<?php echo esc_url( self::portal_url( 'production' ) ); ?>"><strong><?php echo esc_html( $counts['production'] ); ?></strong><span>En cola</span></a><a href="<?php echo esc_url( self::portal_url( 'production', array( 'filter' => 'ready' ) ) ); ?>"><strong><?php echo esc_html( $counts['ready'] ); ?></strong><span>Listos</span></a><a href="<?php echo esc_url( self::portal_url( 'production', array( 'filter' => 'closed' ) ) ); ?>"><strong>→</strong><span>Cerrados</span></a></div><p>El cierre operativo conserva la etapa, los archivos y el historial.</p></section></div>
        <details class="ge-control-panel ge-control-activity"><summary>Actividad reciente</summary><?php if ( $activity ) : ?><ul><?php foreach ( array_slice( $activity, 0, 6 ) as $event ) : ?><li><span><?php echo esc_html( wp_date( 'd/m H:i', $event['time'] ) ); ?></span><a href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $event['label'] ); ?> →</a></li><?php endforeach; ?></ul><?php else : ?><p>Todavía no hay actividad reciente.</p><?php endif; ?></details>
        <?php
    }

    private static function render_dashboard_search( $query, $orders, $quotes ) {
        $matches = array();
        foreach ( $orders as $order ) {
            $text = implode( ' ', array( $order->get_id(), $order->get_formatted_billing_full_name(), $order->get_billing_company(), $order->get_billing_email(), $order->get_billing_phone() ) );
            if ( false !== mb_stripos( $text, $query ) ) { $matches[] = array( 'label' => 'Pedido #' . $order->get_order_number(), 'url' => self::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ); }
            if ( count( $matches ) >= 8 ) { break; }
        }
        foreach ( $quotes as $post ) {
            if ( false !== mb_stripos( 'GE-PRE-' . $post->ID . ' ' . $post->post_title, $query ) ) { $matches[] = array( 'label' => 'Presupuesto GE-PRE-' . $post->ID, 'url' => self::portal_url( 'quotes', array( 'quote_id' => $post->ID ) ) ); }
        }
        $customers = get_users( array( 'search' => '*' . esc_attr( $query ) . '*', 'search_columns' => array( 'user_email', 'display_name', 'user_login' ), 'number' => 5 ) );
        foreach ( $customers as $customer ) { $matches[] = array( 'label' => 'Cliente: ' . $customer->display_name, 'url' => self::portal_url( 'customers', array( 'customer_id' => $customer->ID ) ) ); }
        echo '<section class="ge-control-panel ge-control-results"><h2>Resultados recientes</h2>';
        if ( ! $matches ) { echo '<p>Sin coincidencias en esta vista. Probá la búsqueda de Pedidos o Clientes para consultar el historial.</p>'; }
        foreach ( array_slice( $matches, 0, 12 ) as $match ) { echo '<a href="' . esc_url( $match['url'] ) . '">' . esc_html( $match['label'] ) . ' →</a>'; }
        echo '</section>';
    }

    private static function render_orders() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order = $order_id ? wc_get_order( $order_id ) : false;
        echo '<div class="ge-staff-heading"><div><span>Operación central</span><h1>Pedidos</h1><p>Tienda online, mostrador y cuentas corporativas en un solo lugar.</p></div><div class="ge-order-heading-actions"><a class="ge-staff-button" href="' . esc_url( self::portal_url( 'quotes', array( 'new' => 1 ) ) ) . '">＋ Nuevo presupuesto</a><a class="ge-staff-button" href="' . esc_url( self::portal_url( 'production', array( 'view' => 'new' ) ) ) . '">＋ Nuevo pedido manual</a>';
        if ( $order && ! GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { echo '<a class="ge-order-secondary-button" href="' . esc_url( self::portal_url( 'production', array( 'view' => 'new', 'same_customer_order' => $order->get_id() ) ) ) . '">Nuevo pedido al mismo cliente</a>'; }
        echo '</div></div>';
        if ( isset( $_GET['order_trashed'] ) ) { echo '<div class="ge-order-notice" role="status">Pedido movido a la papelera de WooCommerce. Sus datos se conservaron y un administrador puede restaurarlo.</div>'; }
        if ( isset( $_GET['trash_blocked'] ) ) { echo '<div class="ge-order-notice is-error" role="alert">No se pudo mover el pedido a la papelera. Revisá si tiene pagos, envíos o producción registrada.</div>'; }
        if ( ! $order ) {
            $orders = GE_WTP_Orders::get_all_orders( 250 );
            $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
            $origin = isset( $_GET['origin'] ) ? sanitize_key( wp_unslash( $_GET['origin'] ) ) : '';
            $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
            $exact_work_id = 0;
            if ( class_exists('GE_WTP_Gestion_V3') && ctype_digit(ltrim($query,'#')) && get_option(GE_WTP_Gestion_V3::SCHEMA) ) {
                global $wpdb;
                $exact_work_id = (int) $wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.GE_WTP_Gestion_V3::table().' WHERE work_number=%d', ltrim($query,'#')));
                if($exact_work_id){ $exact_order=wc_get_order($exact_work_id); if($exact_order)$orders=array($exact_order); }
            }
            $orders = array_values( array_filter( $orders, function( $candidate ) use ( $query, $origin, $status ) {
                if ( $status && GE_WTP_Order_Lifecycle::stage( $candidate ) !== $status ) { return false; }
                $candidate_origin = 'yes' === $candidate->get_meta( '_ge_markcom_order' ) ? 'corporate' : ( 'yes' === $candidate->get_meta( '_ge_manual_order' ) ? 'manual' : 'store' );
                if ( $origin && $candidate_origin !== $origin ) { return false; }
                if ( ! $query ) { return true; }
                $haystack = implode( ' ', array(
                    $candidate->get_id(),
                    class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $candidate ) : '',
                    $candidate->get_formatted_billing_full_name(), $candidate->get_billing_company(),
                    $candidate->get_billing_email(), $candidate->get_billing_phone(),
                ) );
                $profile = $candidate->get_meta( '_ge_billing_profile_snapshot', true );
                $delivery = $candidate->get_meta( '_ge_delivery_snapshot', true );
                if ( is_array( $profile ) ) { $haystack .= ' ' . implode( ' ', array_map( 'strval', array_intersect_key( $profile, array_flip( array( 'label', 'branch', 'legal_name', 'cuit', 'fiscal_address' ) ) ) ) ); }
                if ( is_array( $delivery ) ) { $haystack .= ' ' . implode( ' ', array_map( 'strval', array_intersect_key( $delivery, array_flip( array( 'label', 'street', 'city' ) ) ) ) ); }
                foreach ( $candidate->get_items() as $item ) { $haystack .= ' ' . $item->get_name(); }
                return false !== mb_stripos( $haystack, $query );
            } ) );
            self::orders_filters( $query, $origin, $status, count( $orders ) );
            $sort = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'oldest' : 'newest';
            if ( 'oldest' === $sort ) { $orders = array_reverse( $orders ); }
            $total = count( $orders );
            $pages = max( 1, (int) ceil( $total / 20 ) );
            $page = min( $pages, max( 1, absint( $_GET['order_page'] ?? 1 ) ) );
            echo '<section class="ge-admin-panel">'; self::orders_table( array_slice( $orders, ( $page - 1 ) * 20, 20 ) ); echo '</section>';
            if ( $pages > 1 ) {
                echo '<nav class="ge-v3-pagination" aria-label="Páginas de pedidos">';
                if ( $page > 1 ) { echo '<a href="' . esc_url( self::portal_url( 'orders', array( 'order_page'=>$page-1, 'q'=>$query, 'origin'=>$origin, 'status'=>$status, 'sort'=>$sort ) ) ) . '">← Anterior</a>'; }
                echo '<span>Página ' . esc_html($page) . ' de ' . esc_html($pages) . '</span>';
                if ( $page < $pages ) { echo '<a href="' . esc_url( self::portal_url( 'orders', array( 'order_page'=>$page+1, 'q'=>$query, 'origin'=>$origin, 'status'=>$status, 'sort'=>$sort ) ) ) . '">Siguiente →</a>'; }
                echo '</nav>';
            }
            return;
        }
        self::order_detail( $order );
    }

    private static function orders_filters( $query, $origin, $status, $count ) {
        ?>
        <form class="ge-order-filters" method="get" action="<?php echo esc_url( self::portal_url() ); ?>">
            <input type="hidden" name="section" value="orders">
            <label class="is-search"><span>Buscar</span><input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="Número, cliente, sucursal, CUIT o producto"></label>
            <label><span>Origen</span><select name="origin"><option value="">Todos</option><option value="store" <?php selected( $origin, 'store' ); ?>>Tienda</option><option value="manual" <?php selected( $origin, 'manual' ); ?>>Mostrador</option><option value="corporate" <?php selected( $origin, 'corporate' ); ?>>Corporativo</option></select></label>
            <label><span>Etapa del trabajo</span><select name="status"><option value="">Todas</option><?php foreach ( GE_WTP_Order_Lifecycle::stages() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <button class="ge-staff-button" type="submit">Filtrar</button>
            <?php if ( $query || $origin || $status ) : ?><a href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">Limpiar</a><?php endif; ?>
            <strong><?php echo esc_html( $count ); ?> pedidos</strong>
        </form>
        <?php
    }

    private static function orders_table( $orders ) {
        if ( ! $orders ) { echo '<div class="ge-admin-empty"><strong>Todavía no hay pedidos.</strong><span>Los nuevos aparecerán automáticamente acá.</span></div>'; return; }
        echo '<div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Origen</th><th>Fecha</th><th>Estado</th><th>Total</th><th>Acciones</th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
            $is_manual = 'yes' === $order->get_meta( '_ge_manual_order' ); $is_work_order = 'yes' === $order->get_meta( '_ge_work_order' ); $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id(); $origin = GE_WTP_Customer_Quotes::is_quote_order( $order ) ? 'Presupuesto' : ( $is_markcom ? 'Markcom' : ( $is_work_order ? 'Orden de trabajo' : ( $is_manual ? 'Mostrador' : 'Tienda' ) ) );
            echo '<tr><td><strong title="' . esc_attr( $reference ) . '">' . esc_html( class_exists( 'GE_WTP_Gestion_V3' ) && GE_WTP_Gestion_V3::lookup( 'order_id', $order->get_id() ) ? '#' . $order->get_order_number() : sprintf( '%05d', $order->get_id() ) ) . '</strong><small>' . esc_html( $order->get_item_count() ) . ' ítems</small></td><td>' . esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ?: $order->get_billing_phone() ) . '</td><td><span class="ge-admin-origin ' . ( $is_markcom ? 'is-markcom' : 'is-store' ) . '">' . esc_html( $origin ) . '</span></td><td>' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</td><td><span class="ge-admin-status">' . esc_html( GE_WTP_Customer_Quotes::is_quote_order( $order ) ? GE_WTP_Customer_Quotes::stage_label( $order ) : GE_WTP_Order_Lifecycle::label( $order ) ) . '</span></td><td><strong>' . wp_kses_post( $order->get_formatted_order_total() . ( GE_WTP_Customer_Quotes::is_quote_order( $order ) ? ' + IVA' : '' ) ) . '</strong></td><td><div class="ge-order-row-actions"><a href="' . esc_url( self::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ) . '">Ver</a>';
            if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) ) {
                $workflow_released = class_exists( 'GE_WTP_Workflow' ) && GE_WTP_Workflow::enabled( $order ) && 'production' === $order->get_meta( GE_WTP_Workflow::STAGE_META, true );
                $already_handled = GE_WTP_Production::is_closed( $order ) || in_array( GE_WTP_Order_Lifecycle::stage( $order ), array( 'produccion', 'listo', 'entregado' ), true ) || in_array( $order->get_meta( '_ge_production_status', true ), array( 'production', 'ready' ), true );
                echo '<a href="' . esc_url( self::portal_url( 'production', array( 'order_id' => $order->get_id(), 'step' => $workflow_released ? 'production' : 'review' ) ) ) . '">' . esc_html( $already_handled ? 'Ver producción' : 'Enviar a producción' ) . '</a>';
            }
            if ( self::can_trash_order( $order ) && current_user_can( 'manage_woocommerce' ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Confirmás que el pedido #' . esc_js( $order->get_id() ) . ' es un duplicado o prueba sin venta real? Pasará a la papelera recuperable de WooCommerce.\');"><input type="hidden" name="action" value="ge_staff_order_trash"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
                wp_nonce_field( 'ge_staff_order_trash_' . $order->get_id() );
                echo '<button class="ge-order-trash" type="submit" aria-label="Mover pedido #' . esc_attr( $order->get_id() ) . ' a la papelera" title="Mover a la papelera"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v6m4-6v6"/></svg></button></form>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function can_trash_order( $order ) {
        if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) { return false; }
        if ( ! $order instanceof WC_Order || 'trash' === $order->get_status() || GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { return false; }
        $closed = class_exists( 'GE_WTP_Production' ) && GE_WTP_Production::is_closed( $order );
        if ( ( $order->get_date_paid() && ! $closed ) || $order->get_transaction_id() || $order->get_total_refunded() > 0 || in_array( $order->get_status(), array( 'completed', 'refunded' ), true ) ) { return false; }
        if ( $order->get_meta( '_ge_payment_state', true ) || $order->get_meta( '_ge_commercial_quote_id', true ) ) { return false; }
        if ( 'entregado' === GE_WTP_Order_Lifecycle::stage( $order ) || ( 'production' === $order->get_meta( '_ge_production_status', true ) && ! $closed ) ) { return false; }
        if ( $order->get_meta( '_ge_supplier_auto_dispatch_at', true ) && ! $closed ) { return false; }
        foreach ( (array) $order->get_meta( '_ge_supplier_dispatch_history', true ) as $entry ) { if ( ! empty( $entry['success'] ) && ! $closed ) { return false; } }
        foreach ( (array) $order->get_meta( '_ge_workflow_supplier_history', true ) as $entry ) { if ( ! empty( $entry['sent'] ) && ! $closed ) { return false; } }
        return true;
    }

    public static function handle_order_trash() {
        if ( ! self::can_access() || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_staff_order_trash_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! self::can_trash_order( $order ) ) { wp_safe_redirect( self::portal_url( 'orders', array( 'trash_blocked' => 1 ) ) ); exit; }
        $order->update_meta_data( '_ge_staff_trashed_at', time() );
        $order->update_meta_data( '_ge_staff_trashed_by', get_current_user_id() );
        $order->add_order_note( 'Pedido movido a la papelera desde Gestión por el usuario #' . get_current_user_id() . '. Se conserva para restauración.', false, true );
        $order->save();
        $moved = $order->delete( false );
        wp_safe_redirect( self::portal_url( 'orders', array( $moved ? 'order_trashed' : 'trash_blocked' => 1 ) ) ); exit;
    }

    private static function order_detail( $order ) {
        $documents = array_values( array_filter( GE_WTP_Documents::get_documents( $order->get_id() ), function( $document ) { return 'comprobante' !== ( $document['category'] ?? '' ); } ) );
        $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
        $is_manual = 'yes' === $order->get_meta( '_ge_manual_order' );
        $is_work_order = 'yes' === $order->get_meta( '_ge_work_order' );
        $statuses = GE_WTP_Order_Lifecycle::stages();
        $tracking_stage_locked = class_exists( 'GE_WTP_Workflow' ) && GE_WTP_Workflow::tracking_stage_locked( $order );
        ?>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="ge-order-notice">Pedido actualizado. Los cambios ya están guardados en la ficha del cliente y en Gestión.</div><?php endif; ?>
        <?php if ( isset( $_GET['item_order_created'] ) ) : ?><div class="ge-order-notice">La orden de trabajo del ítem quedó creada. Los demás renglones del presupuesto no fueron modificados.</div><?php endif; ?>
        <?php if ( isset( $_GET['customer_notified'] ) ) : ?><div class="ge-order-notice<?php echo 'sent' === $_GET['customer_notified'] ? '' : ' is-error'; ?>"><?php echo 'sent' === $_GET['customer_notified'] ? 'La actualización fue enviada al cliente y quedó registrada.' : 'No se pudo enviar la actualización. Revisá la configuración del correo antes de reintentar.'; ?></div><?php endif; ?>
        <a class="ge-admin-back" href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">← Volver a pedidos</a>
        <?php if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) ) : ?><p><a class="ge-staff-button" href="<?php echo esc_url( GE_WTP_Quotes::order_url( $order->get_id() ) ); ?>">Descargar presupuesto PDF</a></p><?php endif; ?>
        <div class="ge-admin-order-hero"><div><span><?php echo esc_html( GE_WTP_Customer_Quotes::is_quote_order( $order ) ? 'Presupuesto de cliente' : ( $is_markcom ? 'Portal Markcom' : ( $is_work_order ? 'Orden de trabajo' : ( $is_manual ? 'Pedido de mostrador' : 'Tienda online' ) ) ) ); ?></span><h2 title="<?php echo esc_attr( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?>"><?php echo esc_html( class_exists( 'GE_WTP_Gestion_V3' ) && GE_WTP_Gestion_V3::lookup( 'order_id', $order->get_id() ) ? '#' . $order->get_order_number() : sprintf( '%05d', $order->get_id() ) ); ?></h2><p><?php echo esc_html( $order->get_billing_email() ?: $order->get_billing_phone() ); ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><strong><?php echo wp_kses_post( $order->get_formatted_order_total() . ( GE_WTP_Customer_Quotes::is_quote_order( $order ) ? ' + IVA' : '' ) ); ?></strong></div>
        <?php GE_WTP_Customer_Quotes::render_staff_review( $order ); ?>
        <div class="ge-admin-order-grid"><section class="ge-admin-panel ge-admin-panel-wide"><div class="ge-admin-panel-head"><div><span>Contenido</span><h2>Productos solicitados</h2></div><?php if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) && self::can_edit_order_lines( $order ) ) : ?><a href="#editar-pedido">Editar productos ↓</a><?php endif; ?></div><div class="ge-admin-items"><?php foreach ( $order->get_items() as $item ) : $item_status = class_exists( 'GE_WTP_Production' ) ? GE_WTP_Production::item_status( $item, $order ) : 'pending'; ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) . ' ' . ( $item->get_meta( '_ge_quote_unit', true ) ?: 'unidades' ) ); ?><?php $specification = $item->get_meta( 'Especificaciones' ); echo $specification ? ' · ' . esc_html( $specification ) : ''; ?></small><em class="ge-item-status is-<?php echo esc_attr( $item_status ); ?>"><?php echo esc_html( class_exists( 'GE_WTP_Production' ) ? GE_WTP_Production::item_status_label( $item, $order ) : 'Pendiente de aprobación' ); ?></em><?php if ( class_exists( 'GE_WTP_Production' ) ) { GE_WTP_Production::render_item_work_order_control( $order, $item ); } ?></span><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div><?php endforeach; ?><?php foreach ( $order->get_items( 'fee' ) as $fee ) : ?><div class="ge-admin-fee"><span><strong><?php echo esc_html( $fee->get_name() ); ?></strong><small>Cargo del pedido</small></span><b><?php echo wp_kses_post( wc_price( $fee->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></b></div><?php endforeach; ?></div><div class="ge-admin-meta"><div><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></strong></div><div><small>Pago</small><strong><?php echo esc_html( $order->get_payment_method_title() ?: ( $is_markcom ? 'Cuenta corriente a 30 días' : 'Sin definir' ) ); ?></strong></div><div><small>Entrega</small><strong><?php echo esc_html( $order->get_shipping_method() ?: $order->get_meta( '_ge_manual_delivery_method' ) ?: 'A coordinar' ); ?></strong></div></div><?php GE_WTP_Artwork_Library::render_order_links( $order, 'staff' ); ?></section>
        <?php if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) ) : ?>
        <aside class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Seguimiento</span><h2>Etapa del trabajo</h2></div></div>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_backoffice_order_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_status_' . $order->get_id() ); ?>
                <?php if ( $tracking_stage_locked ) : ?>
                    <input type="hidden" name="stage" value="<?php echo esc_attr( GE_WTP_Order_Lifecycle::stage( $order ) ); ?>">
                    <p><strong>Etapa: <?php echo esc_html( GE_WTP_Order_Lifecycle::label( $order ) ) . ' · ' . wp_kses_post( $order->get_formatted_order_total() ); ?></strong></p>
                    <small>La etapa avanza desde Revisión y planificación, al aprobar y avisar al cliente. Acá podés guardar la fecha y una nota.</small>
                <?php else : ?>
                    <label>Etapa<select name="stage"><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( GE_WTP_Order_Lifecycle::stage( $order ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <small>Estado interno de WooCommerce: <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small>
                <label>Fecha estimada<input type="date" name="estimated_date" value="<?php echo esc_attr( $order->get_meta( '_ge_estimated_date' ) ); ?>"></label>
                <label>Nota interna<textarea name="status_note" rows="3"></textarea></label>
                <button class="ge-staff-button" type="submit"><?php echo $tracking_stage_locked ? 'Guardar fecha y nota' : 'Actualizar pedido'; ?></button>
            </form>
        </aside><?php endif; ?></div>
        <?php GE_WTP_Delivery_Labels::label_form( $order ); ?>
        <?php GE_WTP_Customer_Branches::render_order_summary( $order, true ); ?>
        <?php GE_WTP_Payments::render_staff_order_payment( $order ); ?>
        <?php GE_WTP_Issued_Documents::render_staff( $order ); ?>
        <?php GE_WTP_Review_Requests::render_for_order( $order ); ?>
        <?php if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) ) { self::render_order_shipping( $order ); } ?>
        <?php if ( ! GE_WTP_Customer_Quotes::is_quote_order( $order ) && self::can_edit_order_lines( $order ) ) { self::order_editor( $order ); } ?>
        <?php if ( is_email( $order->get_billing_email() ) ) : ?><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Comunicación</span><h2>Avisar cambios al cliente</h2></div></div><p>Envía el detalle, el total actualizado y un acceso directo al pedido. El resultado queda registrado en Notificaciones.</p><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_send_order_update"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_send_order_update_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit">Enviar actualización a <?php echo esc_html( $order->get_billing_email() ); ?></button></form></section><?php endif; ?>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Archivos</span><h2>Documentos del pedido</h2></div><strong><?php echo esc_html( count( $documents ) ); ?></strong></div><div class="ge-admin-document-grid"><div><?php if ( ! $documents ) : ?><div class="ge-admin-empty">No hay documentos cargados.</div><?php else : foreach ( $documents as $document ) : ?><a class="ge-admin-document" href="<?php echo esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ); ?>"><b>↓</b><span><strong><?php echo esc_html( $document['name'] ); ?></strong><small><?php echo esc_html( size_format( $document['size'] ) ); ?></small></span></a><?php GE_WTP_File_Analysis::render( $document, true ); endforeach; endif; ?></div><form class="ge-admin-form ge-admin-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_document"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_document_' . $order->get_id() ); ?><label>Tipo<select name="category"><?php foreach ( GE_WTP_Documents::upload_categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Archivo<input type="file" name="ge_documents[]" accept=".pdf,.jpg,.jpeg,.png,.zip" multiple required></label><button class="ge-staff-button" type="submit">Cargar</button></form></div></section>
        <?php
    }

    private static function render_order_shipping( $order ) {
        $method = $order->get_meta( '_ge_manual_delivery_method', true ) ?: 'coordinate';
        $recipient = $order->get_meta( '_ge_delivery_recipient', true ) ?: trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        ?>
        <section class="ge-admin-panel ge-order-shipping"><div class="ge-admin-panel-head"><div><span>Logística</span><h2>Envío o retiro</h2></div></div>
            <?php if ( isset( $_GET['shipping_saved'] ) ) : ?><div class="ge-order-notice">Datos de entrega guardados para este pedido.</div><?php endif; ?>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_staff_order_shipping"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_staff_order_shipping_' . $order->get_id() ); ?>
                <div class="ge-order-edit-grid"><label>Modalidad<select name="delivery_method"><option value="coordinate" <?php selected( $method, 'coordinate' ); ?>>A coordinar</option><option value="pickup" <?php selected( $method, 'pickup' ); ?>>Retira en Graph Express</option><option value="delivery" <?php selected( $method, 'delivery' ); ?>>Envío a domicilio</option></select></label><label>Dirección a usar<select name="address_source"><option value="current">Dirección de este pedido (editable abajo)</option><option value="billing">Dirección de facturación del pedido</option></select></label><label>Recibe<input type="text" name="recipient" maxlength="160" value="<?php echo esc_attr( $recipient ); ?>" placeholder="Nombre de quien recibe"></label><label class="is-wide">Dirección de entrega<input type="text" name="shipping_address_1" maxlength="190" value="<?php echo esc_attr( $order->get_shipping_address_1() ); ?>" placeholder="Calle, número, piso y departamento"></label><label>Ciudad<input type="text" name="shipping_city" maxlength="100" value="<?php echo esc_attr( $order->get_shipping_city() ); ?>"></label><label>Código postal<input type="text" name="shipping_postcode" maxlength="30" value="<?php echo esc_attr( $order->get_shipping_postcode() ); ?>"></label><label>Horario o franja de entrega<input type="text" name="delivery_window" maxlength="120" value="<?php echo esc_attr( $order->get_meta( '_ge_delivery_window', true ) ); ?>" placeholder="Ej.: lun. a vie. de 9 a 17"></label></div>
                <?php if ( $order->get_billing_address_1() ) : ?><p class="ge-order-address-hint">Facturación: <?php echo esc_html( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_city(), $order->get_billing_postcode() ) ) ) ); ?></p><?php endif; ?>
                <button class="ge-staff-button" type="submit">Guardar envío</button>
            </form>
        </section>
        <?php
    }

    public static function handle_order_shipping() {
        if ( ! self::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 ); check_admin_referer( 'ge_staff_order_shipping_' . $order_id );
        $order = wc_get_order( $order_id ); if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        $method = sanitize_key( wp_unslash( $_POST['delivery_method'] ?? 'coordinate' ) );
        if ( ! in_array( $method, array( 'coordinate', 'pickup', 'delivery' ), true ) ) { wp_die( 'Modalidad inválida.', 400 ); }
        $source = sanitize_key( wp_unslash( $_POST['address_source'] ?? 'current' ) );
        if ( ! in_array( $source, array( 'current', 'billing' ), true ) ) { wp_die( 'Dirección inválida.', 400 ); }
        $address = 'billing' === $source ? $order->get_billing_address_1() : sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ?? '' ) );
        $city = 'billing' === $source ? $order->get_billing_city() : sanitize_text_field( wp_unslash( $_POST['shipping_city'] ?? '' ) );
        $postcode = 'billing' === $source ? $order->get_billing_postcode() : sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ?? '' ) );
        if ( 'delivery' === $method && ! $address ) { wp_die( 'Para enviar el pedido, elegí o completá una dirección.', 400 ); }
        $recipient = sanitize_text_field( wp_unslash( $_POST['recipient'] ?? '' ) );
        if ( 'delivery' === $method && ! $recipient ) { wp_die( 'Indicá quién recibe el pedido.', 400 ); }
        $order->set_shipping_address_1( $address ); $order->set_shipping_city( $city ); $order->set_shipping_postcode( $postcode );
        $order->set_shipping_first_name( $recipient ); $order->set_shipping_last_name( '' );
        $order->update_meta_data( '_ge_delivery_recipient', $recipient );
        $order->update_meta_data( '_ge_delivery_window', sanitize_text_field( wp_unslash( $_POST['delivery_window'] ?? '' ) ) );
        $order->update_meta_data( '_ge_manual_delivery_method', $method );
        $order->add_order_note( 'Datos de entrega actualizados desde Gestión.' ); $order->save();
        wp_safe_redirect( self::portal_url( 'orders', array( 'order_id' => $order_id, 'shipping_saved' => 1 ) ) ); exit;
    }

    private static function can_edit_order_lines( $order ) {
        if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'completed' ), true ) || $order->is_paid() || 'paid' === $order->get_meta( '_ge_payment_state', true ) || $order->get_meta( '_ge_supplier_auto_dispatch_at', true ) ) { return false; }
        foreach ( (array) $order->get_meta( '_ge_supplier_dispatch_history', true ) as $entry ) { if ( ! empty( $entry['success'] ) ) { return false; } }
        foreach ( (array) $order->get_meta( '_ge_workflow_supplier_history', true ) as $entry ) { if ( ! empty( $entry['sent'] ) ) { return false; } }
        foreach ( $order->get_items( 'line_item' ) as $item ) { if ( in_array( GE_WTP_Production::item_status( $item, $order ), array( 'production', 'ready', 'delivered' ), true ) ) { return false; } }
        return true;
    }

    private static function order_editor( $order ) {
        $products = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) : array();
        $editor_js = GE_WTP_PLUGIN_DIR . 'assets/js/staff-order-editor.js';
        wp_enqueue_script( 'ge-staff-order-editor', GE_WTP_PLUGIN_URL . 'assets/js/staff-order-editor.js', array(), is_file( $editor_js ) ? (string) filemtime( $editor_js ) : GE_WTP_VERSION, true );
        ?>
        <details class="ge-admin-panel ge-order-editor" id="editar-pedido"><summary>Editar productos y precios</summary>
            <p>Modificá los renglones sólo si el pedido cambió. Los datos del cliente se administran en Clientes; el envío tiene su propia sección.</p>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ge-order-editor>
                <input type="hidden" name="action" value="ge_staff_order_update"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_staff_order_update_' . $order->get_id() ); ?>
                <fieldset><div class="ge-order-editor-heading"><legend>Productos y precios en <?php echo esc_html( $order->get_currency() ); ?></legend><button type="button" class="button" data-ge-order-add>＋ Agregar producto</button></div><div class="ge-order-edit-lines" data-ge-order-lines>
                    <?php foreach ( $order->get_items() as $item_id => $item ) : self::order_line_editor( $order, $item, $item_id ); endforeach; ?>
                </div><datalist id="ge-order-products"><?php foreach ( $products as $product ) : ?><option value="<?php echo esc_attr( $product->get_name() . ' (#' . $product->get_id() . ')' ); ?>"></option><?php endforeach; ?></datalist>
                <template data-ge-order-template><?php self::order_line_editor( $order, false, '__INDEX__' ); ?></template></fieldset>
                <div class="ge-order-editor-save"><p><strong>Total actual: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong><span>Al guardar se recalcularán los renglones. Cargos, descuentos y envío existentes se conservan.</span></p><button class="ge-staff-button" type="submit">Guardar cambios del pedido</button></div>
            </form>
        </details>
        <?php
    }

    private static function order_line_editor( $order, $item, $index ) {
        $quantity = $item ? max( 1, (float) $item->get_quantity() ) : 1;
        $unit_price = $item ? (float) $item->get_total() / $quantity : 0;
        $product_id = $item ? $item->get_product_id() : 0;
        $label = $item ? $item->get_name() : '';
        ?>
        <div class="ge-order-edit-line" data-ge-order-line>
            <input type="hidden" name="lines[<?php echo esc_attr( $index ); ?>][item_id]" value="<?php echo $item ? esc_attr( $index ) : '0'; ?>">
            <label class="is-product">Producto / trabajo<input type="search" name="lines[<?php echo esc_attr( $index ); ?>][label]" list="ge-order-products" value="<?php echo esc_attr( $label ); ?>" maxlength="200" required><input type="hidden" name="lines[<?php echo esc_attr( $index ); ?>][product_id]" value="<?php echo esc_attr( $product_id ); ?>"></label>
            <label>Cantidad<input type="number" name="lines[<?php echo esc_attr( $index ); ?>][quantity]" min="0.01" step="0.01" value="<?php echo esc_attr( $quantity ); ?>" required></label>
            <label>Precio unitario<input type="number" name="lines[<?php echo esc_attr( $index ); ?>][unit_price]" min="0" step="0.01" value="<?php echo esc_attr( wc_format_decimal( $unit_price, 2 ) ); ?>" required></label>
            <label class="is-detail">Especificaciones<input type="text" name="lines[<?php echo esc_attr( $index ); ?>][details]" value="<?php echo esc_attr( $item ? $item->get_meta( 'Especificaciones' ) : '' ); ?>" maxlength="800" placeholder="Medida, papel, impresión y terminaciones"></label>
            <label class="ge-order-remove"><input type="checkbox" name="lines[<?php echo esc_attr( $index ); ?>][remove]" value="1"><span>Quitar</span></label>
        </div>
        <?php
    }

    public static function handle_order_update() {
        if ( ! self::can_access() ) { wp_die( 'Acceso denegado.', 403 ); }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_staff_order_update_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! $order ) { wp_die( 'Pedido inválido.', 404 ); }
        if ( ! self::can_edit_order_lines( $order ) ) { wp_die( 'Este pedido ya está en producción o fue enviado. No se pueden editar sus productos desde aquí.', 409 ); }

        $posted_lines = array_slice( (array) ( $_POST['lines'] ?? array() ), 0, 50, true );
        $valid_lines = array_filter( $posted_lines, function( $posted ) {
            return empty( $posted['remove'] ) && ! empty( trim( (string) ( $posted['label'] ?? '' ) ) ) && (float) ( $posted['quantity'] ?? 0 ) > 0;
        } );
        if ( ! $valid_lines ) { wp_die( 'El pedido debe conservar al menos un producto.', 400 ); }
        foreach ( $posted_lines as $posted ) {
            $item_id = absint( $posted['item_id'] ?? 0 );
            $item = $item_id ? $order->get_item( $item_id ) : false;
            if ( $item_id && ! $item ) { continue; }
            if ( ! empty( $posted['remove'] ) ) { if ( $item ) { $order->remove_item( $item_id ); } continue; }
            $label = sanitize_text_field( wp_unslash( $posted['label'] ?? '' ) );
            $quantity = max( 0, (float) wc_format_decimal( wp_unslash( $posted['quantity'] ?? 0 ) ) );
            $unit_price = max( 0, (float) wc_format_decimal( wp_unslash( $posted['unit_price'] ?? 0 ) ) );
            if ( ! $label || ! $quantity ) { continue; }
            if ( ! $item ) { $item = new WC_Order_Item_Product(); $order->add_item( $item ); }
            $product_id = absint( $posted['product_id'] ?? 0 );
            $product = $product_id ? wc_get_product( $product_id ) : false;
            if ( $product ) { $item->set_product( $product ); }
            $item->set_name( preg_replace( '/\s*\(#\d+\)$/', '', $label ) );
            $item->set_quantity( $quantity );
            $line_total = round( $quantity * $unit_price, wc_get_price_decimals() );
            $item->set_subtotal( $line_total ); $item->set_total( $line_total );
            $details = sanitize_text_field( wp_unslash( $posted['details'] ?? '' ) );
            if ( $details ) { $item->update_meta_data( 'Especificaciones', $details ); } else { $item->delete_meta_data( 'Especificaciones' ); }
            if ( ! $item->get_meta( '_ge_item_status', true ) ) { $item->update_meta_data( '_ge_item_status', 'pending' ); }
            $item->save();
        }
        $order->calculate_totals( false );
        if ( class_exists( 'GE_WTP_Production' ) ) { GE_WTP_Production::sync_order_status_from_items( $order ); }
        $order->add_order_note( 'Pedido editado desde el Centro de Gestión por ' . wp_get_current_user()->display_name . '.' );
        $order->save();
        wp_safe_redirect( self::portal_url( 'orders', array( 'order_id' => $order_id, 'updated' => 1 ) ) ); exit;
    }

    private static function render_candidates() {
        $candidates = GE_WTP_Jobs::get_candidates();
        ?><div class="ge-staff-heading"><div><span>Personas</span><h1>Candidatos</h1><p>Perfiles recibidos desde “Trabajá con nosotros”.</p></div></div><section class="ge-admin-panel"><?php if ( ! $candidates ) : ?><div class="ge-admin-empty">Todavía no hay postulaciones.</div><?php else : ?><div class="ge-admin-candidates"><?php foreach ( $candidates as $candidate ) : $status = get_post_meta( $candidate->ID, '_ge_candidate_status', true ) ?: 'nuevo'; ?><article><div class="ge-candidate-main"><span class="ge-admin-status"><?php echo esc_html( ucfirst( $status ) ); ?></span><h3><?php echo esc_html( $candidate->post_title ); ?></h3><p><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_area', true ) ); ?> · <?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_city', true ) ); ?></p><div class="ge-candidate-links"><a href="mailto:<?php echo esc_attr( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?>"><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?></a><a target="_blank" rel="noopener" href="<?php echo esc_url( get_post_meta( $candidate->ID, '_ge_candidate_linkedin', true ) ); ?>">LinkedIn ↗</a></div></div><form class="ge-admin-form ge-candidate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_candidate_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="candidate_id" value="<?php echo esc_attr( $candidate->ID ); ?>"><?php wp_nonce_field( 'ge_backoffice_candidate_status_' . $candidate->ID ); ?><label>Seguimiento<select name="candidate_status"><option value="nuevo" <?php selected( $status, 'nuevo' ); ?>>Nuevo</option><option value="contactado" <?php selected( $status, 'contactado' ); ?>>Contactado</option><option value="entrevista" <?php selected( $status, 'entrevista' ); ?>>Entrevista</option><option value="archivado" <?php selected( $status, 'archivado' ); ?>>Archivado</option></select></label><button class="ge-staff-button" type="submit">Guardar</button></form></article><?php endforeach; ?></div><?php endif; ?></section><?php
    }
}
