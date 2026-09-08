<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Staff_Portal {
    const PAGE_SLUG = 'gestion';
    const ROLE = 'ge_staff_manager';
    const CAPABILITY = 'ge_manage_operations';

    public static function init() {
        add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
        add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_action( 'admin_init', array( __CLASS__, 'protect_wp_admin' ) );
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
        if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) ) {
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
        if ( 'orders' === $section ) {
            self::render_orders();
        } elseif ( 'production' === $section ) {
            GE_WTP_Production::render();
        } elseif ( 'customers' === $section ) {
            GE_WTP_Customers::render_staff();
        } elseif ( 'library' === $section ) {
            GE_WTP_Artwork_Library::render_staff();
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
                    <?php if ( 'notifications' === $category ) : GE_WTP_Notification_Center::render( false ); elseif ( 'integrations' === $category ) : GE_WTP_Google_Auth::render_settings(); else : $item = $categories[ $category ]; ?>
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
            <a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>">¿Olvidaste tu contraseña?</a>
        </section>
        <?php
    }

    private static function render_dashboard() {
        $orders = GE_WTP_Orders::get_all_orders( 100 );
        $active = 0; $documents = 0; $markcom = 0;
        foreach ( $orders as $order ) {
            if ( ! in_array( $order->get_status(), array( 'completed', 'ge-entregado', 'ge-cobrado', 'cancelled', 'refunded' ), true ) ) { $active++; }
            if ( 'yes' === $order->get_meta( '_ge_markcom_order' ) ) { $markcom++; }
            $documents += count( GE_WTP_Documents::get_documents( $order->get_id() ) );
        }
        ?>
        <div class="ge-staff-heading"><div><span>Operación</span><h1>Buen día, <?php echo esc_html( wp_get_current_user()->display_name ); ?></h1><p>Lo importante para trabajar hoy, sin entrar al administrador de WordPress.</p></div><a class="ge-staff-button" href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">Ver pedidos</a></div>
        <div class="ge-admin-metrics"><article><span>Pedidos activos</span><strong><?php echo esc_html( $active ); ?></strong><small>requieren seguimiento</small></article><article><span>Pedidos totales</span><strong><?php echo esc_html( count( $orders ) ); ?></strong><small>tienda y portal</small></article><article><span>Pedidos Markcom</span><strong><?php echo esc_html( $markcom ); ?></strong><small>cuenta corporativa</small></article><article><span>Documentos</span><strong><?php echo esc_html( $documents ); ?></strong><small>archivos privados</small></article></div>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Actividad</span><h2>Últimos pedidos</h2></div><a href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">Ver todos →</a></div><?php self::orders_table( array_slice( $orders, 0, 8 ) ); ?></section>
        <?php
    }

    private static function render_orders() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $order = $order_id ? wc_get_order( $order_id ) : false;
        echo '<div class="ge-staff-heading"><div><span>Producción</span><h1>Pedidos</h1><p>Tienda online y cuentas corporativas.</p></div></div>';
        if ( ! $order ) {
            echo '<section class="ge-admin-panel">'; self::orders_table( GE_WTP_Orders::get_all_orders( 250 ) ); echo '</section>'; return;
        }
        self::order_detail( $order );
    }

    private static function orders_table( $orders ) {
        if ( ! $orders ) { echo '<div class="ge-admin-empty"><strong>Todavía no hay pedidos.</strong><span>Los nuevos aparecerán automáticamente acá.</span></div>'; return; }
        echo '<div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Orden</th><th>Cliente</th><th>Origen</th><th>Fecha</th><th>Estado</th><th>Total</th><th></th></tr></thead><tbody>';
        foreach ( $orders as $order ) {
            $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
            $is_manual = 'yes' === $order->get_meta( '_ge_manual_order' ); $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id(); $origin = $is_markcom ? 'Markcom' : ( $is_manual ? 'Mostrador' : 'Tienda' );
            echo '<tr><td><strong>' . esc_html( $reference ) . '</strong><small>' . esc_html( $order->get_item_count() ) . ' ítems</small></td><td>' . esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ?: $order->get_billing_phone() ) . '</td><td><span class="ge-admin-origin ' . ( $is_markcom ? 'is-markcom' : 'is-store' ) . '">' . esc_html( $origin ) . '</span></td><td>' . esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ) . '</td><td><span class="ge-admin-status">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td><td><strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></td><td><a href="' . esc_url( self::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) ) . '">Ver →</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function order_detail( $order ) {
        $documents = GE_WTP_Documents::get_documents( $order->get_id() );
        $is_markcom = 'yes' === $order->get_meta( '_ge_markcom_order' );
        $is_manual = 'yes' === $order->get_meta( '_ge_manual_order' );
        $statuses = $is_markcom ? GE_WTP_Plugin::order_status_labels() : array_combine( array_map( function( $key ) { return str_replace( 'wc-', '', $key ); }, array_keys( wc_get_order_statuses() ) ), array_values( wc_get_order_statuses() ) );
        ?>
        <a class="ge-admin-back" href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">← Volver a pedidos</a>
        <div class="ge-admin-order-hero"><div><span><?php echo esc_html( $is_markcom ? 'Portal Markcom' : ( $is_manual ? 'Pedido de mostrador' : 'Tienda online' ) ); ?></span><h2><?php echo esc_html( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?></h2><p><?php echo esc_html( $order->get_billing_email() ?: $order->get_billing_phone() ); ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
        <div class="ge-admin-order-grid"><section class="ge-admin-panel ge-admin-panel-wide"><div class="ge-admin-panel-head"><div><span>Contenido</span><h2>Productos solicitados</h2></div></div><div class="ge-admin-items"><?php foreach ( $order->get_items() as $item ) : ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?> unidades</small></span><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div><?php endforeach; ?></div><div class="ge-admin-meta"><div><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></strong></div><div><small>Pago</small><strong><?php echo esc_html( $order->get_payment_method_title() ?: ( $is_markcom ? 'Cuenta corriente a 30 días' : 'Sin definir' ) ); ?></strong></div><div><small>Entrega</small><strong><?php echo esc_html( $order->get_shipping_method() ?: 'A coordinar' ); ?></strong></div></div><?php GE_WTP_Artwork_Library::render_order_links( $order, 'staff' ); ?></section>
        <aside class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Seguimiento</span><h2>Estado</h2></div></div><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_status_' . $order->get_id() ); ?><label>Etapa<select name="status"><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_status(), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Fecha estimada<input type="date" name="estimated_date" value="<?php echo esc_attr( $order->get_meta( '_ge_estimated_date' ) ); ?>"></label><label>Nota interna<textarea name="status_note" rows="3"></textarea></label><button class="ge-staff-button" type="submit">Actualizar pedido</button></form></aside></div>
        <?php GE_WTP_Delivery_Labels::label_form( $order ); ?>
        <?php GE_WTP_Review_Requests::render_for_order( $order ); ?>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Archivos</span><h2>Documentos del pedido</h2></div><strong><?php echo esc_html( count( $documents ) ); ?></strong></div><div class="ge-admin-document-grid"><div><?php if ( ! $documents ) : ?><div class="ge-admin-empty">No hay documentos cargados.</div><?php else : foreach ( $documents as $document ) : ?><a class="ge-admin-document" href="<?php echo esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ); ?>"><b>↓</b><span><strong><?php echo esc_html( $document['name'] ); ?></strong><small><?php echo esc_html( size_format( $document['size'] ) ); ?></small></span></a><?php endforeach; endif; ?></div><form class="ge-admin-form ge-admin-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_document"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_document_' . $order->get_id() ); ?><label>Tipo<select name="category"><?php foreach ( GE_WTP_Documents::categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Archivo<input type="file" name="ge_documents[]" accept=".pdf,.jpg,.jpeg,.png,.zip" multiple required></label><button class="ge-staff-button" type="submit">Cargar</button></form></div></section>
        <?php
    }

    private static function render_candidates() {
        $candidates = GE_WTP_Jobs::get_candidates();
        ?><div class="ge-staff-heading"><div><span>Personas</span><h1>Candidatos</h1><p>Perfiles recibidos desde “Trabajá con nosotros”.</p></div></div><section class="ge-admin-panel"><?php if ( ! $candidates ) : ?><div class="ge-admin-empty">Todavía no hay postulaciones.</div><?php else : ?><div class="ge-admin-candidates"><?php foreach ( $candidates as $candidate ) : $status = get_post_meta( $candidate->ID, '_ge_candidate_status', true ) ?: 'nuevo'; ?><article><div class="ge-candidate-main"><span class="ge-admin-status"><?php echo esc_html( ucfirst( $status ) ); ?></span><h3><?php echo esc_html( $candidate->post_title ); ?></h3><p><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_area', true ) ); ?> · <?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_city', true ) ); ?></p><div class="ge-candidate-links"><a href="mailto:<?php echo esc_attr( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?>"><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?></a><a target="_blank" rel="noopener" href="<?php echo esc_url( get_post_meta( $candidate->ID, '_ge_candidate_linkedin', true ) ); ?>">LinkedIn ↗</a></div></div><form class="ge-admin-form ge-candidate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_candidate_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="candidate_id" value="<?php echo esc_attr( $candidate->ID ); ?>"><?php wp_nonce_field( 'ge_backoffice_candidate_status_' . $candidate->ID ); ?><label>Seguimiento<select name="candidate_status"><option value="nuevo" <?php selected( $status, 'nuevo' ); ?>>Nuevo</option><option value="contactado" <?php selected( $status, 'contactado' ); ?>>Contactado</option><option value="entrevista" <?php selected( $status, 'entrevista' ); ?>>Entrevista</option><option value="archivado" <?php selected( $status, 'archivado' ); ?>>Archivado</option></select></label><button class="ge-staff-button" type="submit">Guardar</button></form></article><?php endforeach; ?></div><?php endif; ?></section><?php
    }
}
