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
        add_action( 'admin_post_ge_staff_order_update', array( __CLASS__, 'handle_order_update' ) );
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
            <?php if ( class_exists( 'GE_WTP_Google_Auth' ) ) { GE_WTP_Google_Auth::render_portal_button(); } ?>
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
        echo '<div class="ge-staff-heading"><div><span>Operación central</span><h1>Pedidos</h1><p>Tienda online, mostrador y cuentas corporativas en un solo lugar.</p></div><a class="ge-staff-button" href="' . esc_url( self::portal_url( 'production', array( 'view' => 'new' ) ) ) . '">＋ Nuevo pedido manual</a></div>';
        if ( ! $order ) {
            $orders = GE_WTP_Orders::get_all_orders( 250 );
            $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
            $origin = isset( $_GET['origin'] ) ? sanitize_key( wp_unslash( $_GET['origin'] ) ) : '';
            $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
            $orders = array_values( array_filter( $orders, function( $candidate ) use ( $query, $origin, $status ) {
                if ( $status && $candidate->get_status() !== $status ) { return false; }
                $candidate_origin = 'yes' === $candidate->get_meta( '_ge_markcom_order' ) ? 'corporate' : ( 'yes' === $candidate->get_meta( '_ge_manual_order' ) ? 'manual' : 'store' );
                if ( $origin && $candidate_origin !== $origin ) { return false; }
                if ( ! $query ) { return true; }
                $haystack = implode( ' ', array(
                    $candidate->get_id(),
                    class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $candidate ) : '',
                    $candidate->get_formatted_billing_full_name(), $candidate->get_billing_company(),
                    $candidate->get_billing_email(), $candidate->get_billing_phone(),
                ) );
                foreach ( $candidate->get_items() as $item ) { $haystack .= ' ' . $item->get_name(); }
                return false !== mb_stripos( $haystack, $query );
            } ) );
            self::orders_filters( $query, $origin, $status, count( $orders ) );
            echo '<section class="ge-admin-panel">'; self::orders_table( $orders ); echo '</section>'; return;
        }
        self::order_detail( $order );
    }

    private static function orders_filters( $query, $origin, $status, $count ) {
        ?>
        <form class="ge-order-filters" method="get" action="<?php echo esc_url( self::portal_url() ); ?>">
            <input type="hidden" name="section" value="orders">
            <label class="is-search"><span>Buscar</span><input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="Número, cliente, email, teléfono o producto"></label>
            <label><span>Origen</span><select name="origin"><option value="">Todos</option><option value="store" <?php selected( $origin, 'store' ); ?>>Tienda</option><option value="manual" <?php selected( $origin, 'manual' ); ?>>Mostrador</option><option value="corporate" <?php selected( $origin, 'corporate' ); ?>>Corporativo</option></select></label>
            <label><span>Estado</span><select name="status"><option value="">Todos</option><?php foreach ( wc_get_order_statuses() as $key => $label ) : $key = str_replace( 'wc-', '', $key ); ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <button class="ge-staff-button" type="submit">Filtrar</button>
            <?php if ( $query || $origin || $status ) : ?><a href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">Limpiar</a><?php endif; ?>
            <strong><?php echo esc_html( $count ); ?> pedidos</strong>
        </form>
        <?php
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
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="ge-order-notice">Pedido actualizado. Los cambios ya están guardados en la ficha del cliente y en Gestión.</div><?php endif; ?>
        <?php if ( isset( $_GET['customer_notified'] ) ) : ?><div class="ge-order-notice<?php echo 'sent' === $_GET['customer_notified'] ? '' : ' is-error'; ?>"><?php echo 'sent' === $_GET['customer_notified'] ? 'La actualización fue enviada al cliente y quedó registrada.' : 'No se pudo enviar la actualización. Revisá la configuración del correo antes de reintentar.'; ?></div><?php endif; ?>
        <a class="ge-admin-back" href="<?php echo esc_url( self::portal_url( 'orders' ) ); ?>">← Volver a pedidos</a>
        <div class="ge-admin-order-hero"><div><span><?php echo esc_html( $is_markcom ? 'Portal Markcom' : ( $is_manual ? 'Pedido de mostrador' : 'Tienda online' ) ); ?></span><h2><?php echo esc_html( class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id() ); ?></h2><p><?php echo esc_html( $order->get_billing_email() ?: $order->get_billing_phone() ); ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></p></div><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></div>
        <div class="ge-admin-order-grid"><section class="ge-admin-panel ge-admin-panel-wide"><div class="ge-admin-panel-head"><div><span>Contenido</span><h2>Productos solicitados</h2></div><a href="#editar-pedido">Editar pedido ↓</a></div><div class="ge-admin-items"><?php foreach ( $order->get_items() as $item ) : ?><div><span><strong><?php echo esc_html( $item->get_name() ); ?></strong><small><?php echo esc_html( number_format_i18n( $item->get_quantity() ) ); ?> unidades<?php $specification = $item->get_meta( 'Especificaciones' ); echo $specification ? ' · ' . esc_html( $specification ) : ''; ?></small></span><b><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></b></div><?php endforeach; ?><?php foreach ( $order->get_items( 'fee' ) as $fee ) : ?><div class="ge-admin-fee"><span><strong><?php echo esc_html( $fee->get_name() ); ?></strong><small>Cargo del pedido</small></span><b><?php echo wp_kses_post( wc_price( $fee->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></b></div><?php endforeach; ?></div><div class="ge-admin-meta"><div><small>Cliente</small><strong><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></strong></div><div><small>Pago</small><strong><?php echo esc_html( $order->get_payment_method_title() ?: ( $is_markcom ? 'Cuenta corriente a 30 días' : 'Sin definir' ) ); ?></strong></div><div><small>Entrega</small><strong><?php echo esc_html( $order->get_shipping_method() ?: $order->get_meta( '_ge_manual_delivery_method' ) ?: 'A coordinar' ); ?></strong></div></div><?php GE_WTP_Artwork_Library::render_order_links( $order, 'staff' ); ?></section>
        <aside class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Seguimiento</span><h2>Estado</h2></div></div><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_status_' . $order->get_id() ); ?><label>Etapa<select name="status"><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->get_status(), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Fecha estimada<input type="date" name="estimated_date" value="<?php echo esc_attr( $order->get_meta( '_ge_estimated_date' ) ); ?>"></label><label>Nota interna<textarea name="status_note" rows="3"></textarea></label><button class="ge-staff-button" type="submit">Actualizar pedido</button></form></aside></div>
        <?php GE_WTP_Delivery_Labels::label_form( $order ); ?>
        <?php GE_WTP_Review_Requests::render_for_order( $order ); ?>
        <?php self::order_editor( $order ); ?>
        <?php if ( is_email( $order->get_billing_email() ) ) : ?><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Comunicación</span><h2>Avisar cambios al cliente</h2></div></div><p>Envía el detalle, el total actualizado y un acceso directo al pedido. El resultado queda registrado en Notificaciones.</p><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_send_order_update"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_send_order_update_' . $order->get_id() ); ?><button class="ge-staff-button" type="submit">Enviar actualización a <?php echo esc_html( $order->get_billing_email() ); ?></button></form></section><?php endif; ?>
        <section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Archivos</span><h2>Documentos del pedido</h2></div><strong><?php echo esc_html( count( $documents ) ); ?></strong></div><div class="ge-admin-document-grid"><div><?php if ( ! $documents ) : ?><div class="ge-admin-empty">No hay documentos cargados.</div><?php else : foreach ( $documents as $document ) : ?><a class="ge-admin-document" href="<?php echo esc_url( GE_WTP_Documents::download_url( $order->get_id(), $document['id'] ) ); ?>"><b>↓</b><span><strong><?php echo esc_html( $document['name'] ); ?></strong><small><?php echo esc_html( size_format( $document['size'] ) ); ?></small></span></a><?php endforeach; endif; ?></div><form class="ge-admin-form ge-admin-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_order_document"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_backoffice_order_document_' . $order->get_id() ); ?><label>Tipo<select name="category"><?php foreach ( GE_WTP_Documents::categories() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label>Archivo<input type="file" name="ge_documents[]" accept=".pdf,.jpg,.jpeg,.png,.zip" multiple required></label><button class="ge-staff-button" type="submit">Cargar</button></form></div></section>
        <?php
    }

    private static function order_editor( $order ) {
        $products = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'status' => 'publish', 'limit' => 500, 'orderby' => 'name', 'order' => 'ASC' ) ) : array();
        wp_enqueue_script( 'ge-staff-order-editor', GE_WTP_PLUGIN_URL . 'assets/js/staff-order-editor.js', array(), GE_WTP_VERSION, true );
        ?>
        <section class="ge-admin-panel ge-order-editor" id="editar-pedido">
            <div class="ge-admin-panel-head"><div><span>Edición central</span><h2>Editar cliente y contenido del pedido</h2></div><small>Los avisos al cliente o proveedor se envían por separado.</small></div>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ge-order-editor>
                <input type="hidden" name="action" value="ge_staff_order_update"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><?php wp_nonce_field( 'ge_staff_order_update_' . $order->get_id() ); ?>
                <fieldset><legend>Cliente y contacto</legend><div class="ge-order-edit-grid">
                    <label>Nombre<input type="text" name="billing_first_name" value="<?php echo esc_attr( $order->get_billing_first_name() ); ?>" maxlength="100"></label>
                    <label>Apellido<input type="text" name="billing_last_name" value="<?php echo esc_attr( $order->get_billing_last_name() ); ?>" maxlength="100"></label>
                    <label>Empresa<input type="text" name="billing_company" value="<?php echo esc_attr( $order->get_billing_company() ); ?>" maxlength="160"></label>
                    <label>Email<input type="email" name="billing_email" value="<?php echo esc_attr( $order->get_billing_email() ); ?>" maxlength="190"></label>
                    <label>WhatsApp / teléfono<input type="tel" name="billing_phone" value="<?php echo esc_attr( $order->get_billing_phone() ); ?>" maxlength="60"></label>
                    <label>Cuenta vinculada<select name="customer_id"><option value="0">Invitado / sin cuenta</option><?php foreach ( get_users( array( 'number' => 500, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $order->get_customer_id(), $user->ID ); ?>><?php echo esc_html( $user->display_name . ' · ' . $user->user_email ); ?></option><?php endforeach; ?></select></label>
                    <label class="is-wide">Dirección de entrega<input type="text" name="shipping_address_1" value="<?php echo esc_attr( $order->get_shipping_address_1() ); ?>" maxlength="190" placeholder="Calle, número, piso y departamento"></label>
                    <label>Ciudad<input type="text" name="shipping_city" value="<?php echo esc_attr( $order->get_shipping_city() ); ?>" maxlength="100"></label>
                    <label>Código postal<input type="text" name="shipping_postcode" value="<?php echo esc_attr( $order->get_shipping_postcode() ); ?>" maxlength="30"></label>
                </div></fieldset>
                <fieldset><div class="ge-order-editor-heading"><legend>Productos y precios en <?php echo esc_html( $order->get_currency() ); ?></legend><button type="button" class="button" data-ge-order-add>＋ Agregar producto</button></div><div class="ge-order-edit-lines" data-ge-order-lines>
                    <?php foreach ( $order->get_items() as $item_id => $item ) : self::order_line_editor( $order, $item, $item_id ); endforeach; ?>
                </div><datalist id="ge-order-products"><?php foreach ( $products as $product ) : ?><option value="<?php echo esc_attr( $product->get_name() . ' (#' . $product->get_id() . ')' ); ?>"></option><?php endforeach; ?></datalist>
                <template data-ge-order-template><?php self::order_line_editor( $order, false, '__INDEX__' ); ?></template></fieldset>
                <fieldset><legend>Notas y modalidad</legend><div class="ge-order-edit-grid">
                    <label>Entrega<select name="delivery_method"><option value="coordinate" <?php selected( $order->get_meta( '_ge_manual_delivery_method' ), 'coordinate' ); ?>>A coordinar</option><option value="pickup" <?php selected( $order->get_meta( '_ge_manual_delivery_method' ), 'pickup' ); ?>>Retira por Graph Express</option><option value="delivery" <?php selected( $order->get_meta( '_ge_manual_delivery_method' ), 'delivery' ); ?>>Requiere envío</option></select></label>
                    <label class="is-wide">Nota visible para el cliente<textarea name="customer_note" rows="3" maxlength="4000"><?php echo esc_textarea( $order->get_customer_note() ); ?></textarea></label>
                    <label class="is-wide">Nota técnica interna<textarea name="internal_note" rows="3" maxlength="4000"><?php echo esc_textarea( $order->get_meta( '_ge_internal_order_note' ) ); ?></textarea></label>
                </div></fieldset>
                <div class="ge-order-editor-save"><p><strong>Total actual: <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong><span>Al guardar se recalcularán los renglones. Cargos, descuentos y envío existentes se conservan.</span></p><button class="ge-staff-button" type="submit">Guardar cambios del pedido</button></div>
            </form>
        </section>
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

        $email = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
        if ( ! empty( $_POST['billing_email'] ) && ! is_email( $email ) ) { wp_die( 'El email del cliente no es válido.', 400 ); }
        $order->set_billing_first_name( sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ?? '' ) ) );
        $order->set_billing_last_name( sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ?? '' ) ) );
        $order->set_billing_company( sanitize_text_field( wp_unslash( $_POST['billing_company'] ?? '' ) ) );
        $order->set_billing_email( $email );
        $order->set_billing_phone( sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) ) );
        $order->set_shipping_address_1( sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ?? '' ) ) );
        $order->set_shipping_city( sanitize_text_field( wp_unslash( $_POST['shipping_city'] ?? '' ) ) );
        $order->set_shipping_postcode( sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ?? '' ) ) );
        $customer_id = absint( $_POST['customer_id'] ?? 0 );
        if ( $customer_id && ! get_user_by( 'id', $customer_id ) ) { $customer_id = 0; }
        if ( ! $customer_id && $email ) { $customer_id = absint( email_exists( $email ) ); }
        $order->set_customer_id( $customer_id );
        $order->set_customer_note( sanitize_textarea_field( wp_unslash( $_POST['customer_note'] ?? '' ) ) );
        $order->update_meta_data( '_ge_internal_order_note', sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ?? '' ) ) );
        $delivery = sanitize_key( wp_unslash( $_POST['delivery_method'] ?? 'coordinate' ) );
        $order->update_meta_data( '_ge_manual_delivery_method', in_array( $delivery, array( 'coordinate', 'pickup', 'delivery' ), true ) ? $delivery : 'coordinate' );

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
            $item->save();
        }
        $order->calculate_totals( false );
        $order->add_order_note( 'Pedido editado desde el Centro de Gestión por ' . wp_get_current_user()->display_name . '.' );
        $order->save();
        wp_safe_redirect( self::portal_url( 'orders', array( 'order_id' => $order_id, 'updated' => 1 ) ) ); exit;
    }

    private static function render_candidates() {
        $candidates = GE_WTP_Jobs::get_candidates();
        ?><div class="ge-staff-heading"><div><span>Personas</span><h1>Candidatos</h1><p>Perfiles recibidos desde “Trabajá con nosotros”.</p></div></div><section class="ge-admin-panel"><?php if ( ! $candidates ) : ?><div class="ge-admin-empty">Todavía no hay postulaciones.</div><?php else : ?><div class="ge-admin-candidates"><?php foreach ( $candidates as $candidate ) : $status = get_post_meta( $candidate->ID, '_ge_candidate_status', true ) ?: 'nuevo'; ?><article><div class="ge-candidate-main"><span class="ge-admin-status"><?php echo esc_html( ucfirst( $status ) ); ?></span><h3><?php echo esc_html( $candidate->post_title ); ?></h3><p><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_area', true ) ); ?> · <?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_city', true ) ); ?></p><div class="ge-candidate-links"><a href="mailto:<?php echo esc_attr( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?>"><?php echo esc_html( get_post_meta( $candidate->ID, '_ge_candidate_email', true ) ); ?></a><a target="_blank" rel="noopener" href="<?php echo esc_url( get_post_meta( $candidate->ID, '_ge_candidate_linkedin', true ) ); ?>">LinkedIn ↗</a></div></div><form class="ge-admin-form ge-candidate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_backoffice_candidate_status"><input type="hidden" name="return_to" value="staff"><input type="hidden" name="candidate_id" value="<?php echo esc_attr( $candidate->ID ); ?>"><?php wp_nonce_field( 'ge_backoffice_candidate_status_' . $candidate->ID ); ?><label>Seguimiento<select name="candidate_status"><option value="nuevo" <?php selected( $status, 'nuevo' ); ?>>Nuevo</option><option value="contactado" <?php selected( $status, 'contactado' ); ?>>Contactado</option><option value="entrevista" <?php selected( $status, 'entrevista' ); ?>>Entrevista</option><option value="archivado" <?php selected( $status, 'archivado' ); ?>>Archivado</option></select></label><button class="ge-staff-button" type="submit">Guardar</button></form></article><?php endforeach; ?></div><?php endif; ?></section><?php
    }
}
