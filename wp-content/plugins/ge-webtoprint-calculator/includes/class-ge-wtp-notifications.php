<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Notifications {
    const LOG_POST_TYPE = 'ge_email_log';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_log_type' ), 7 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'handle_order_status_changed' ), 30, 4 );
        add_action( 'woocommerce_email_sent', array( __CLASS__, 'log_woocommerce_email' ), 10, 3 );
        add_action( 'admin_post_ge_send_order_update', array( __CLASS__, 'handle_order_update_notice' ) );
        add_filter( 'pre_wp_mail', array( __CLASS__, 'capture_local_mail' ), 99, 2 );
    }

    /**
     * Localhost is an isolated test environment: report mail as accepted without
     * contacting real customers or suppliers. WooCommerce and this class still
     * record their normal delivery logs, so the complete workflow can be audited.
     */
    public static function capture_local_mail( $return, $atts ) {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
        if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
            return true;
        }
        return $return;
    }

    public static function send_customer_welcome_verification( $user, $email, $verification_url, $welcome = false ) {
        if ( ! $user instanceof WP_User ) { return false; }
        $name = $user->first_name ?: $user->display_name;
        $intro = $welcome ? 'Tu cuenta de Graph Express ya está creada. Desde tu perfil vas a poder guardar datos de contacto, direcciones y preferencias para agilizar los próximos pedidos.' : 'Recibimos una solicitud para verificar este email en tu cuenta de Graph Express.';
        $content = '<p>Hola ' . esc_html( $name ?: '¿cómo estás?' ) . ',</p><p>' . esc_html( $intro ) . '</p><p style="margin:26px 0"><a href="' . esc_url( $verification_url ) . '" style="display:inline-block;padding:14px 20px;border-radius:10px;background:#6d45ef;color:#fff;text-decoration:none;font-weight:700">Verificar mi email</a></p><p style="color:#777382;font-size:13px">El enlace vence en 24 horas. Si no pediste esta verificación, podés ignorar el mensaje.</p>';
        return self::send( $email, ( $welcome ? 'Bienvenido a Graph Express' : 'Verificá tu email' ) . ' · confirmación requerida', self::basic_email( $welcome ? 'Tu cuenta está lista' : 'Verificá tu email', $content ), $welcome ? 'customer_welcome_verification' : 'customer_email_verification', $user->ID );
    }

    public static function send_new_customer_admin( $user ) {
        if ( ! $user instanceof WP_User ) { return false; }
        $url = class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'customers', array( 'customer_id' => $user->ID ) ) : admin_url( 'user-edit.php?user_id=' . $user->ID );
        $content = '<p>Se registró un nuevo cliente en la web.</p><table style="width:100%;border-collapse:collapse"><tr><td style="padding:9px 0;border-bottom:1px solid #eceaf0">Nombre</td><td style="padding:9px 0;border-bottom:1px solid #eceaf0;text-align:right"><strong>' . esc_html( $user->display_name ) . '</strong></td></tr><tr><td style="padding:9px 0;border-bottom:1px solid #eceaf0">Email</td><td style="padding:9px 0;border-bottom:1px solid #eceaf0;text-align:right"><strong>' . esc_html( $user->user_email ) . '</strong></td></tr></table><p style="margin:26px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 20px;border-radius:10px;background:#17152a;color:#fff;text-decoration:none;font-weight:700">Ver ficha del cliente</a></p>';
        if ( class_exists( 'GE_WTP_Notification_Center' ) ) { $result = GE_WTP_Notification_Center::send_internal( 'new_customer', 'Nuevo cliente registrado · Graph Express', self::basic_email( 'Nuevo cliente', $content ), $user->ID ); return null === $result ? true : $result; }
        return self::send( get_option( 'admin_email' ), 'Nuevo cliente registrado · Graph Express', self::basic_email( 'Nuevo cliente', $content ), 'new_customer_admin', $user->ID );
    }

    public static function handle_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( '_ge_markcom_order' ) || ! $order->get_meta( '_ge_markcom_reference' ) || $old_status === $new_status ) { return; }
        self::send_order_status_changed( $order, $old_status );
    }

    public static function log_woocommerce_email( $sent, $email_id, $email ) {
        if ( ! is_object( $email ) || ! method_exists( $email, 'get_recipient' ) ) { return; }
        $recipients = preg_split( '/[,;]/', (string) $email->get_recipient() );
        $recipient = sanitize_email( trim( $recipients[0] ?? '' ) );
        if ( ! $recipient ) { return; }
        $subject = method_exists( $email, 'get_subject' ) ? $email->get_subject() : 'Correo WooCommerce';
        $object_id = 0;
        if ( method_exists( $email, 'get_object' ) ) { $object = $email->get_object(); if ( is_object( $object ) && method_exists( $object, 'get_id' ) ) { $object_id = $object->get_id(); } }
        self::log( $recipient, $subject, '<p>Correo transaccional generado por WooCommerce.</p>', 'woocommerce_' . sanitize_key( $email_id ), $object_id, (bool) $sent );
    }

    public static function register_log_type() {
        register_post_type(
            self::LOG_POST_TYPE,
            array(
                'labels'              => array( 'name' => 'Correos', 'singular_name' => 'Correo' ),
                'public'              => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'exclude_from_search' => true,
                'supports'            => array( 'title', 'editor' ),
            )
        );
    }

    public static function send_order_created( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        if ( $order->get_meta( '_ge_order_created_notification_at' ) ) { return true; }

        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() );
        $customer_email = sanitize_email( $order->get_billing_email() );
        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $guest_invitation = '';
        if ( ! $order->get_customer_id() && function_exists( 'wc_get_page_permalink' ) ) {
            $guest_invitation = '<p style="margin-top:22px;padding:15px;border-radius:10px;background:#f5f2ff"><strong>¿Querés guardar tus pedidos?</strong><br>Podés <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">crear una cuenta con este mismo email</a> para consultar el historial y pedir nuevamente más rápido.</p>';
        }
        $customer_body = self::order_email_body(
            $order,
            'Recibimos tu pedido',
            'Hola ' . ( $customer_name ?: '¿cómo estás?' ) . ', la orden <strong>' . esc_html( $reference ) . '</strong> ya ingresó a Graph Express. Te avisaremos cada vez que avance de etapa.' . $guest_invitation
        );
        $customer_ok = $customer_email ? self::send( $customer_email, 'Pedido recibido · ' . $reference, $customer_body, 'order_customer', $order->get_id() ) : false;

        $admin_body = self::order_email_body(
            $order,
            'Nuevo pedido en el portal',
            'Se generó la orden <strong>' . esc_html( $reference ) . '</strong>. Ingresá al back office para revisar el PO, los archivos y confirmar producción.',
            class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order->get_id() ) ) : admin_url( 'admin.php?page=ge-backoffice-orders&order_id=' . $order->get_id() ),
            'Revisar pedido'
        );
        if ( class_exists( 'GE_WTP_Notification_Center' ) ) { $admin_result = GE_WTP_Notification_Center::send_internal( 'new_order', 'Nuevo pedido Graph Express · ' . $reference, $admin_body, $order->get_id() ); $admin_ok = null === $admin_result ? true : $admin_result; $admin_state = null === $admin_result ? 'disabled' : ( $admin_ok ? 'sent' : 'failed' ); }
        else { $admin_email = sanitize_email( get_option( 'admin_email' ) ); $admin_ok = $admin_email ? self::send( $admin_email, 'Nuevo pedido Graph Express · ' . $reference, $admin_body, 'order_admin', $order->get_id() ) : false; $admin_state = $admin_ok ? 'sent' : 'failed'; }

        $order->update_meta_data( '_ge_customer_order_email', $customer_ok ? 'sent' : 'failed' );
        $order->update_meta_data( '_ge_admin_order_email', $admin_state );
        $order->update_meta_data( '_ge_order_created_notification_at', current_time( 'mysql' ) );
        $order->save();

        return $customer_ok && $admin_ok;
    }

    public static function send_order_status_changed( $order, $old_status = '' ) {
        if ( ! $order instanceof WC_Order || ! $order->get_billing_email() ) {
            return false;
        }
        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : ( $order->get_meta( '_ge_markcom_reference' ) ?: '#' . $order->get_id() );
        $status = wc_get_order_status_name( $order->get_status() );
        $message = 'Tu pedido <strong>' . esc_html( $reference ) . '</strong> ahora está en la etapa <strong>' . esc_html( $status ) . '</strong>.';
        if ( 'ge-listo' === $order->get_status() ) {
            $message .= ' Nos comunicaremos para coordinar la entrega o el retiro.';
        }
        $body = self::order_email_body( $order, 'Actualización de tu pedido', $message, GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ), 'Ver pedido' );
        $ok = self::send( $order->get_billing_email(), 'Tu pedido avanzó · ' . $reference, $body, 'order_status_' . $order->get_status(), $order->get_id() );
        $order->update_meta_data( '_ge_last_status_email', $ok ? 'sent' : 'failed' );
        $order->update_meta_data( '_ge_last_status_email_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_ge_last_status_email_from', sanitize_key( $old_status ) );
        $order->save();
        return $ok;
    }

    public static function send_order_updated( $order ) {
        if ( ! $order instanceof WC_Order || ! is_email( $order->get_billing_email() ) ) {
            return false;
        }

        $reference = class_exists( 'GE_WTP_Manual_Orders' ) ? GE_WTP_Manual_Orders::reference( $order ) : '#' . $order->get_id();
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $body = self::order_email_body(
            $order,
            'Actualizamos tu presupuesto',
            'Hola ' . esc_html( $name ?: '¿cómo estás?' ) . ', agregamos nueva información y productos a tu presupuesto <strong>' . esc_html( $reference ) . '</strong>. Podés revisar debajo el detalle actualizado.',
            GE_WTP_Portal::portal_url( 'pedidos', array( 'pedido' => $order->get_id() ) ),
            'Ver presupuesto actualizado'
        );
        $ok = self::send( $order->get_billing_email(), 'Presupuesto actualizado · ' . $reference, $body, 'order_manual_update', $order->get_id() );
        $order->update_meta_data( '_ge_last_manual_update_email', $ok ? 'sent' : 'failed' );
        $order->update_meta_data( '_ge_last_manual_update_email_at', current_time( 'mysql' ) );
        $order->save();
        return $ok;
    }

    public static function handle_order_update_notice() {
        if ( ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() ) {
            wp_die( 'Acceso denegado.', 403 );
        }
        $order_id = absint( $_POST['order_id'] ?? 0 );
        check_admin_referer( 'ge_send_order_update_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Pedido inválido.', 404 );
        }
        $result = self::send_order_updated( $order ) ? 'sent' : 'failed';
        wp_safe_redirect( GE_WTP_Staff_Portal::portal_url( 'orders', array( 'order_id' => $order_id, 'customer_notified' => $result ) ) );
        exit;
    }

    public static function send( $to, $subject, $html, $context = 'general', $object_id = 0 ) {
        $to = sanitize_email( $to );
        if ( ! $to || ! is_email( $to ) ) {
            return false;
        }
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $ok = (bool) wp_mail( $to, wp_strip_all_tags( $subject ), $html, $headers );
        self::log( $to, $subject, $html, $context, $object_id, $ok );
        return $ok;
    }

    private static function log( $to, $subject, $html, $context, $object_id, $ok ) {
        $post_id = wp_insert_post(
            array(
                'post_type'    => self::LOG_POST_TYPE,
                'post_status'  => 'private',
                'post_title'   => wp_strip_all_tags( $subject ),
                'post_content' => wp_kses_post( $html ),
            )
        );
        if ( $post_id ) {
            update_post_meta( $post_id, '_ge_email_to', sanitize_email( $to ) );
            update_post_meta( $post_id, '_ge_email_context', sanitize_key( $context ) );
            update_post_meta( $post_id, '_ge_email_object_id', absint( $object_id ) );
            update_post_meta( $post_id, '_ge_email_result', $ok ? 'sent' : 'failed' );
        }
    }

    public static function get_logs( $limit = 100 ) {
        return get_posts(
            array(
                'post_type'      => self::LOG_POST_TYPE,
                'post_status'    => 'private',
                'posts_per_page' => absint( $limit ),
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
    }

    public static function get_logs_by_recipient( $email, $limit = 30 ) {
        return get_posts( array( 'post_type' => self::LOG_POST_TYPE, 'post_status' => 'private', 'posts_per_page' => absint( $limit ), 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_ge_email_to', 'meta_value' => sanitize_email( $email ) ) );
    }

    private static function order_email_body( $order, $heading, $intro, $button_url = '', $button_label = '' ) {
        $items = '';
        foreach ( $order->get_items() as $item ) {
            $items .= '<tr><td style="padding:12px 0;border-bottom:1px solid #eceaf0"><strong>' . esc_html( $item->get_name() ) . '</strong><br><span style="color:#777382;font-size:13px">' . esc_html( number_format_i18n( $item->get_quantity() ) ) . ' unidades</span></td><td style="padding:12px 0;border-bottom:1px solid #eceaf0;text-align:right"><strong>' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</strong></td></tr>';
        }
        $button = $button_url ? '<p style="margin:28px 0 6px"><a href="' . esc_url( $button_url ) . '" style="display:inline-block;padding:14px 20px;border-radius:10px;background:#6d45ef;color:#fff;text-decoration:none;font-weight:700">' . esc_html( $button_label ) . '</a></p>' : '';
        $estimated = $order->get_meta( '_ge_estimated_date' );
        $delivery = $order->get_formatted_shipping_address() ?: ( $order->get_shipping_method() ?: 'A coordinar' );
        $documents = class_exists( 'GE_WTP_Documents' ) ? GE_WTP_Documents::get_documents( $order->get_id() ) : array();
        $po = $order->get_meta( '_ge_markcom_po_reference' );
        $details = '<table style="width:100%;margin-top:22px;border-collapse:collapse;background:#f8f7fa"><tr><td style="padding:11px 13px">Pago</td><td style="padding:11px 13px;text-align:right"><strong>' . esc_html( $order->get_payment_method_title() ?: 'A coordinar' ) . '</strong></td></tr><tr><td style="padding:11px 13px">Entrega</td><td style="padding:11px 13px;text-align:right"><strong>' . wp_kses_post( $delivery ) . '</strong></td></tr>' . ( $po ? '<tr><td style="padding:11px 13px">Referencia PO</td><td style="padding:11px 13px;text-align:right"><strong>' . esc_html( $po ) . '</strong></td></tr>' : '' ) . ( $documents ? '<tr><td style="padding:11px 13px">Archivos asociados</td><td style="padding:11px 13px;text-align:right"><strong>' . esc_html( count( $documents ) ) . '</strong></td></tr>' : '' ) . ( $estimated ? '<tr><td style="padding:11px 13px">Fecha estimada</td><td style="padding:11px 13px;text-align:right"><strong>' . esc_html( wp_date( 'd/m/Y', strtotime( $estimated ) ) ) . '</strong></td></tr>' : '' ) . '</table>';
        return '<!doctype html><html><body style="margin:0;background:#f3f2f6;font-family:Arial,sans-serif;color:#17152a"><div style="max-width:680px;margin:0 auto;padding:30px 18px"><div style="padding:18px 24px;background:#111629;color:#fff;border-radius:16px 16px 0 0"><strong style="letter-spacing:.1em">GRAPH EXPRESS</strong></div><div style="padding:34px 28px;background:#fff;border-radius:0 0 16px 16px"><span style="color:#6d45ef;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em">Pedido online</span><h1 style="font-size:30px;margin:10px 0 16px">' . esc_html( $heading ) . '</h1><p style="font-size:16px;line-height:1.6;color:#5f5b69">' . wp_kses_post( $intro ) . '</p><table style="width:100%;border-collapse:collapse;margin-top:24px">' . $items . '<tr><td style="padding-top:18px;font-size:16px"><strong>Total</strong></td><td style="padding-top:18px;text-align:right;font-size:20px"><strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></td></tr></table>' . $details . $button . '<p style="margin-top:30px;color:#85818e;font-size:12px;line-height:1.5">Este es un correo operativo relacionado con tu pedido. Graph Express · Oruro 1253 · CABA.</p></div></div></body></html>';
    }

    private static function basic_email( $heading, $content ) {
        return '<!doctype html><html><body style="margin:0;background:#f3f2f6;font-family:Arial,sans-serif;color:#17152a"><div style="max-width:680px;margin:auto;padding:30px 18px"><div style="padding:20px 26px;border-radius:16px 16px 0 0;background:#111629;color:#fff"><strong style="letter-spacing:.1em">GRAPH EXPRESS</strong></div><div style="padding:34px 28px;border-radius:0 0 16px 16px;background:#fff"><h1 style="font-size:30px;margin-top:0">' . esc_html( $heading ) . '</h1><div style="font-size:16px;line-height:1.65;color:#4f4b59">' . wp_kses_post( $content ) . '</div><p style="margin-top:30px;color:#898594;font-size:12px">Graph Express · Oruro 1253 · CABA</p></div></div></body></html>';
    }
}
