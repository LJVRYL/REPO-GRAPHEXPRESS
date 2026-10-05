<?php
/** Plugin Name: Graphex · Tarjetas y contacto digital */
defined( 'ABSPATH' ) || exit;

final class GE_Cards_Experience {
    const TYPE = 'ge_contact_card';
    const INTENT = 'ge_cards_intent';
    const OPTION = 'ge_cards_experience_enabled_v1';
    const FIELDS = array( 'first_name', 'last_name', 'company', 'role', 'email', 'phone', 'website' );
    const MARKETING_TEXT = 'Quiero recibir por email novedades, promociones y descuentos de Graphex. Puedo darme de baja cuando quiera.';
    private static $registration_marketing = null;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register' ) );
        add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
        add_action( 'admin_post_ge_customer_contact_card_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_nopriv_ge_customer_contact_card_save', array( __CLASS__, 'deny' ) );
        add_action( 'admin_post_ge_customer_contact_card_download_qr', array( __CLASS__, 'owner_qr' ) );
        add_action( 'admin_post_nopriv_ge_customer_contact_card_download_qr', array( __CLASS__, 'deny' ) );
        add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'product_notice' ), 8 );
        add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
        add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
        add_filter( 'do_shortcode_tag', array( __CLASS__, 'portal_link' ), 30, 2 );
        add_action( 'admin_post_nopriv_ge_customer_register', array( __CLASS__, 'registration_marketing_guard' ), 1 );
        add_action( 'wp_login', array( __CLASS__, 'registration_marketing_audit' ), 30, 2 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
    }

    public static function enabled() { return 'yes' === get_option( self::OPTION, 'no' ); }
    public static function register() {
        register_post_type( self::TYPE, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'rewrite' => false, 'supports' => array( 'title', 'author' ), 'label' => 'Tarjetas de contacto' ) );
    }
    public static function path() { return '/' . trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' ) . '/'; }
    public static function url( $path = '' ) { return home_url( '/tarjetas/' . ltrim( $path, '/' ) ); }
    public static function organization() { return class_exists( 'GE_Organization' ) ? GE_Organization::PRIMARY : ''; }
    public static function title( $parts ) {
        if ( self::enabled() && 0 === strpos( self::path(), '/tarjetas/' ) ) { $parts['title'] = 'Tarjetas personales y contacto digital'; }
        return $parts;
    }
    public static function assets() {
        if ( ! self::enabled() ) { return; }
        $path = self::path();
        $product_page = self::product_context();
        if ( ! $product_page && 0 !== strpos( $path, '/tarjetas/' ) && 0 !== strpos( $path, '/contacto/' ) ) { return; }
        wp_enqueue_style( 'ge-cards-experience', content_url( '/mu-plugins/ge-cards-experience/cards.css' ), array(), (string) filemtime( __DIR__ . '/ge-cards-experience/cards.css' ) );
        if ( '/tarjetas/mi-vcard/' === $path ) { wp_enqueue_script( 'ge-cards-preview', content_url( '/mu-plugins/ge-cards-experience/preview.js' ), array(), (string) filemtime( __DIR__ . '/ge-cards-experience/preview.js' ), true ); }
        if ( $product_page ) {
            wp_enqueue_script( 'ge-cards-product-preview', content_url( '/mu-plugins/ge-cards-experience/product-preview.js' ), array(), (string) filemtime( __DIR__ . '/ge-cards-experience/product-preview.js' ), true );
            wp_localize_script( 'ge-cards-product-preview', 'geCardsProduct', array( 'selectionScope' => substr( wp_hash( (string) get_current_user_id() ), 0, 16 ), 'productId' => get_queried_object_id() ) );
        }
    }
    public static function body_class( $classes ) {
        if ( self::enabled() && self::product_context() ) { $classes[] = 'ge-cards-product'; }
        return $classes;
    }
    public static function product_context() { return function_exists( 'is_product' ) && is_product() && in_array( (int) get_queried_object_id(), array( 78, 83 ), true ); }
    public static function deny() { wp_die( 'Necesitás iniciar sesión en tu cuenta de Graphex.', 'Acceso privado', array( 'response' => 403 ) ); }
    public static function actor_allowed() {
        if ( ! is_user_logged_in() || ! class_exists( 'GE_WTP_Portal' ) || GE_WTP_Portal::is_staff_preview() ) { return false; }
        $user = wp_get_current_user();
        if ( ! GE_WTP_Portal::is_customer_user( $user ) && ! GE_WTP_Portal::is_staff_user( $user ) ) { return false; }
        $tenant = get_user_meta( $user->ID, '_ge_organization_id', true );
        return self::organization() && ( ! $tenant || self::organization() === $tenant );
    }
    public static function owned( $card, $user_id ) {
        return $card instanceof WP_Post && self::TYPE === $card->post_type && (int) $card->post_author === (int) $user_id && self::organization() === get_post_meta( $card->ID, '_ge_card_organization', true );
    }
    public static function mine( $user_id ) {
        $rows = get_posts( array( 'post_type' => self::TYPE, 'author' => absint( $user_id ), 'post_status' => array( 'draft', 'publish' ), 'numberposts' => 1, 'meta_key' => '_ge_card_organization', 'meta_value' => self::organization() ) );
        return $rows ? $rows[0] : null;
    }
    public static function data( $card ) {
        $data = array();
        foreach ( self::FIELDS as $key ) { $data[$key] = $card ? (string) get_post_meta( $card->ID, '_ge_card_' . $key, true ) : ''; }
        return $data;
    }
    public static function clean( $input ) {
        $out = array();
        foreach ( self::FIELDS as $key ) {
            if ( isset( $input[$key] ) && ! is_scalar( $input[$key] ) ) { return new WP_Error( 'invalid_field', 'Revisá los datos de contacto.' ); }
            $raw = trim( (string) ( $input[$key] ?? '' ) );
            if ( strlen( $raw ) > 600 || preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) { return new WP_Error( 'invalid_field', 'Cada dato debe ocupar una sola línea.' ); }
            $out[$key] = sanitize_text_field( $raw );
        }
        if ( ! $out['first_name'] || mb_strlen( $out['first_name'] ) > 80 || mb_strlen( $out['last_name'] ) > 80 || mb_strlen( $out['company'] ) > 120 || mb_strlen( $out['role'] ) > 120 ) { return new WP_Error( 'invalid_name', 'Completá el nombre y revisá la extensión de los datos.' ); }
        if ( $out['email'] && ! is_email( $out['email'] ) ) { return new WP_Error( 'invalid_email', 'Revisá el email que querés publicar.' ); }
        if ( $out['phone'] && ! preg_match( '/^\+?[0-9 ()-]{6,32}$/D', $out['phone'] ) ) { return new WP_Error( 'invalid_phone', 'Revisá el teléfono de contacto.' ); }
        if ( $out['website'] ) {
            $url = wp_parse_url( $out['website'] );
            if ( ! is_array( $url ) || ( $url['scheme'] ?? '' ) !== 'https' || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || strlen( $out['website'] ) > 300 ) { return new WP_Error( 'invalid_url', 'La web debe ser una dirección HTTPS válida.' ); }
            $out['website'] = esc_url_raw( $out['website'], array( 'https' ) );
            if ( ! $out['website'] ) { return new WP_Error( 'invalid_url', 'Revisá la dirección de tu web.' ); }
        }
        return $out;
    }
    public static function qualifying_order( $order, $user_id ) {
        if ( ! $user_id || ! $order instanceof WC_Order || (int) $order->get_customer_id() !== (int) $user_id || ! $order->is_paid() || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { return false; }
        $tenant = $order->get_meta( '_ge_organization_id' );
        if ( $tenant && self::organization() !== $tenant ) { return false; }
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( (float) $item->get_total() > 0 && (float) $order->get_total_refunded_for_item( $item_id ) >= (float) $item->get_total() ) { continue; }
            if ( in_array( (int) $item->get_product_id(), array( 78, 83 ), true ) && (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) > 0 ) { return true; }
        }
        return false;
    }
    public static function entitlement( $user_id, $card = null ) {
        if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) { return 0; }
        $previous = $card ? absint( get_post_meta( $card->ID, '_ge_card_purchase', true ) ) : 0;
        if ( $previous && self::qualifying_order( wc_get_order( $previous ), $user_id ) ) { return $previous; }
        for ( $page = 1; $page <= 40; $page++ ) {
            $orders = wc_get_orders( array( 'customer_id' => absint( $user_id ), 'status' => array( 'processing', 'completed' ), 'limit' => 25, 'page' => $page, 'orderby' => 'date', 'order' => 'DESC' ) );
            foreach ( $orders as $order ) { if ( self::qualifying_order( $order, $user_id ) ) { return $order->get_id(); } }
            if ( count( $orders ) < 25 ) { break; }
        }
        return 0;
    }
    public static function save() {
        if ( ! self::enabled() || ! self::actor_allowed() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { self::deny(); }
        check_admin_referer( 'ge_customer_contact_card_save' );
        $user_id = get_current_user_id();
        $lock = 'ge_card_lock_' . $user_id;
        $guard = wp_generate_uuid4();
        if ( ! add_option( $lock, array( 'token' => $guard, 'expires' => time() + 90 ), '', false ) ) {
            $old = get_option( $lock );
            if ( is_array( $old ) && (int) $old['expires'] < time() ) { delete_option( $lock ); }
            wp_die( 'Hay otra actualización en curso. Volvé a intentar en unos segundos.', '', array( 'response' => 409 ) );
        }
        try {
            $card = self::mine( $user_id );
            $requested = absint( $_POST['card_id'] ?? 0 );
            if ( $requested && ( ! self::owned( get_post( $requested ), $user_id ) || ! $card || $requested !== $card->ID ) ) { throw new RuntimeException( 'No tenés permiso para modificar este perfil.', 403 ); }
            $revision = $card ? absint( get_post_meta( $card->ID, '_ge_card_revision', true ) ) : 0;
            if ( absint( $_POST['revision'] ?? 0 ) !== $revision ) { throw new RuntimeException( 'El perfil cambió en otra ventana. Recargá antes de guardar.', 409 ); }
            $data = self::clean( wp_unslash( $_POST ) );
            if ( is_wp_error( $data ) ) { throw new RuntimeException( $data->get_error_message(), 422 ); }
            $choice = sanitize_key( $_POST['visibility'] ?? 'draft' );
            if ( ! in_array( $choice, array( 'draft', 'publish' ), true ) ) { throw new RuntimeException( 'Visibilidad inválida.', 422 ); }
            $purchase = self::entitlement( $user_id, $card );
            $consent = 'publish' === $choice && ! empty( $_POST['public_consent'] );
            if ( 'publish' === $choice && ! $consent ) { throw new RuntimeException( 'Para publicar, marcá «Autorizo que estos datos se vean públicamente». Si querés mantenerlos privados, volvé al formulario y elegí «Guardar borrador privado».', 422 ); }
            // Hide before changing fields; a partially saved contact never becomes public.
            $post = array( 'post_type' => self::TYPE, 'post_author' => $user_id, 'post_status' => 'draft', 'post_title' => trim( $data['first_name'] . ' ' . $data['last_name'] ) );
            if ( $card ) { $post['ID'] = $card->ID; } else { $post['post_name'] = bin2hex( random_bytes( 16 ) ); }
            $id = wp_insert_post( $post, true );
            if ( is_wp_error( $id ) ) { $id = 0; throw new RuntimeException( 'No se pudo guardar tu perfil.' ); }
            foreach ( $data as $key => $value ) {
                update_post_meta( $id, '_ge_card_' . $key, $value );
                if ( (string) get_post_meta( $id, '_ge_card_' . $key, true ) !== $value ) { throw new RuntimeException( 'No se pudo verificar el contacto guardado.' ); }
            }
            update_post_meta( $id, '_ge_card_organization', self::organization() );
            update_post_meta( $id, '_ge_card_revision', $revision + 1 );
            update_post_meta( $id, '_ge_card_updated_at', gmdate( 'c' ) );
            update_post_meta( $id, '_ge_card_consent', $consent && $purchase ? 'yes' : 'no' );
            update_post_meta( $id, '_ge_card_purchase', $purchase );
            if ( $consent && $purchase ) {
                $result = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
                if ( is_wp_error( $result ) ) { throw new RuntimeException( 'No se pudo publicar el contacto.' ); }
            }
            $notice = $consent && ! $purchase ? 'purchase' : ( $consent ? 'published' : 'draft' );
        } catch ( Throwable $e ) {
            $failure_status = in_array( $e->getCode(), array( 403, 409, 422 ), true ) ? $e->getCode() : 500;
            $failure = 500 === $failure_status ? 'No se pudo completar la actualización. El perfil queda privado; volvé a intentar.' : $e->getMessage();
            if ( ! empty( $id ) ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) ); }
        } finally {
            $current = get_option( $lock );
            if ( is_array( $current ) && ( $current['token'] ?? '' ) === $guard ) { delete_option( $lock ); }
        }
        if ( ! empty( $failure ) ) { wp_die( esc_html( $failure ), '', array( 'response' => $failure_status ) ); }
        $marketing = self::marketing_save( $user_id, 'vcard-email-v1', ! empty( $_POST['card_email_marketing'] ) );
        wp_safe_redirect( add_query_arg( array( 'saved' => $notice, 'marketing' => $marketing ), self::url( 'mi-vcard/' ) ) ); exit;
    }
    public static function public_card( $token ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) { return null; }
        $rows = get_posts( array( 'post_type' => self::TYPE, 'name' => $token, 'post_status' => 'publish', 'numberposts' => 1, 'meta_query' => array( array( 'key' => '_ge_card_organization', 'value' => self::organization() ), array( 'key' => '_ge_card_consent', 'value' => 'yes' ) ) ) );
        $card = $rows ? $rows[0] : null;
        $owner_tenant = $card ? get_user_meta( $card->post_author, '_ge_organization_id', true ) : '';
        return $card && ( ! $owner_tenant || self::organization() === $owner_tenant ) && get_user_by( 'id', $card->post_author ) && self::entitlement( $card->post_author, $card ) ? $card : null;
    }
    public static function public_url( $card ) { return home_url( '/contacto/' . $card->post_name . '/' ); }
    public static function qr_download_url( $card, $format ) {
        return wp_nonce_url( add_query_arg( array( 'action' => 'ge_customer_contact_card_download_qr', 'card_id' => $card->ID, 'format' => $format ), admin_url( 'admin-post.php' ) ), 'ge_customer_contact_card_download_qr_' . $card->ID );
    }
    public static function final_qr_allowed( $card, $actor ) {
        if ( ! self::owned( $card, $actor ) ) { return false; }
        $public = self::public_card( $card->post_name );
        return $public && (int) $public->ID === (int) $card->ID;
    }
    public static function owner_qr() {
        if ( ! self::enabled() || ! self::actor_allowed() ) { self::deny(); }
        $id = absint( $_GET['card_id'] ?? 0 );
        check_admin_referer( 'ge_customer_contact_card_download_qr_' . $id );
        $card = get_post( $id );
        if ( ! self::owned( $card, get_current_user_id() ) ) { self::deny(); }
        if ( ! self::final_qr_allowed( $card, get_current_user_id() ) ) { wp_die( 'Publicá primero un perfil válido para descargar el QR final.', '', array( 'response' => 409 ) ); }
        $format = sanitize_text_field( $_GET['format'] ?? '' );
        if ( ! in_array( $format, array( 'qr.svg', 'qr.png' ), true ) ) { wp_die( 'Formato inválido.', '', array( 'response' => 422 ) ); }
        header( 'Content-Disposition: attachment; filename="contacto-' . $format . '"' );
        self::output( array(), self::public_url( $card ), $format );
    }
    public static function product_notice() {
        if ( ! self::enabled() ) { return; }
        global $product;
        if ( ! $product instanceof WC_Product || ! in_array( $product->get_slug(), array( 'tarjetas-personales', 'tarjetas-express' ), true ) ) { return; }
        $card = self::actor_allowed() ? self::mine( get_current_user_id() ) : null;
        $published = $card && self::final_qr_allowed( $card, get_current_user_id() );
        $data = $card ? self::data( $card ) : array( 'first_name' => 'Alex', 'last_name' => 'Ejemplo', 'company' => 'Estudio Ejemplo', 'role' => 'Diseño y comunicación', 'email' => 'alex@example.com', 'phone' => '', 'website' => 'https://example.com' );
        include __DIR__ . '/ge-cards-experience/product.php';
    }
    public static function portal_link( $output, $tag ) {
        if ( ! self::enabled() || 'ge_markcom_portal' !== $tag ) { return $output; }
        $output = str_replace( 'Quiero recibir novedades y guías de impresión.', esc_html( self::MARKETING_TEXT ), $output );
        if ( ! self::actor_allowed() ) { return $output; }
        $link = '<a href="' . esc_url( self::url( 'mi-vcard/' ) ) . '">Mi tarjeta digital</a>';
        return preg_replace( '~(<nav\b[^>]*class="ge-portal-nav"[^>]*>)~', '$1' . $link, $output, 1 );
    }
    public static function marketing_status( $email ) {
        global $wpdb;
        if ( ! class_exists( 'GE_WTP_Newsletter' ) || ! $wpdb ) { return 'unavailable'; }
        $table = $wpdb->prefix . 'ge_newsletter_contacts';
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE email = %s LIMIT 1", strtolower( sanitize_email( $email ) ) ) );
        return $wpdb->last_error ? 'unavailable' : ( $status ?: 'none' );
    }
    public static function marketing_audit( $actor, $source, $choice, $before, $after ) {
        if ( ! is_callable( array( 'GE_Organization', 'audit' ) ) ) { return false; }
        $result = GE_Organization::audit( self::organization(), $actor, 'email_marketing_consent', array( 'status' => $before ), array( 'source' => $source, 'channel' => 'email', 'text_version' => '2026-10-05-v1', 'text' => self::MARKETING_TEXT, 'checked' => (bool) $choice, 'status' => $after ) );
        return ! is_wp_error( $result ) && $result;
    }
    public static function marketing_save( $actor, $source, $choice ) {
        $user = get_userdata( $actor );
        if ( ! $user ) { return 'unavailable'; }
        $before = self::marketing_status( $user->user_email );
        // Saving contact fields never withdraws or restores a previous subscription.
        $after = ! $choice ? $before : ( 'none' === $before ? 'subscribed' : $before );
        if ( ! self::marketing_audit( $actor, $source, $choice, $before, $before ) ) { return 'unavailable'; }
        if ( ! $choice ) { return 'unchanged'; }
        if ( ! in_array( $before, array( 'none', 'subscribed' ), true ) ) { return 'suppressed'; }
        if ( 'none' === $before ) {
            $result = GE_WTP_Newsletter::subscribe( $user->user_email, $user->first_name, $user->last_name, $source );
            if ( is_wp_error( $result ) || 'subscribed' !== self::marketing_status( $user->user_email ) ) { return 'unavailable'; }
        }
        update_user_meta( $actor, '_ge_newsletter_optin', 'yes' );
        return 'subscribed';
    }
    public static function registration_marketing_guard() {
        if ( ! self::enabled() || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'ge_customer_register' ) ) { return; }
        self::$registration_marketing = ! empty( $_POST['newsletter_optin'] );
        if ( self::$registration_marketing && ! in_array( self::marketing_status( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ), array( 'none', 'subscribed' ), true ) ) { unset( $_POST['newsletter_optin'] ); }
    }
    public static function registration_marketing_audit( $login, $user ) {
        if ( ! self::enabled() || null === self::$registration_marketing || ! $user || strtolower( $user->user_email ) !== strtolower( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) ) { return; }
        self::marketing_audit( $user->ID, 'registration-email-v1', self::$registration_marketing, 'registration', self::marketing_status( $user->user_email ) );
    }
    private static function escape_vcf( $value ) { return str_replace( array( '\\', ';', ',', "\r", "\n" ), array( '\\\\', '\\;', '\\,', '', '\\n' ), $value ); }
    public static function vcf( $data, $url ) {
        $e = array(); foreach ( self::FIELDS as $key ) { $e[$key] = self::escape_vcf( $data[$key] ?? '' ); }
        $lines = array( 'BEGIN:VCARD', 'VERSION:3.0', 'N:' . $e['last_name'] . ';' . $e['first_name'] . ';;;', 'FN:' . trim( $e['first_name'] . ' ' . $e['last_name'] ) );
        foreach ( array( 'company' => 'ORG:', 'role' => 'TITLE:', 'email' => 'EMAIL;TYPE=INTERNET:', 'phone' => 'TEL;TYPE=CELL:', 'website' => 'URL:' ) as $key => $prefix ) { if ( $e[$key] ) { $lines[] = $prefix . $e[$key]; } }
        $lines[] = 'URL:' . self::escape_vcf( $url ); $lines[] = 'END:VCARD';
        $output = array();
        foreach ( $lines as $line ) {
            $part = '';
            foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
                if ( strlen( $part . $char ) > 75 ) { $output[] = $part; $part = ' '; }
                $part .= $char;
            }
            $output[] = $part;
        }
        return implode( "\r\n", $output ) . "\r\n";
    }
    public static function qr( $url ) {
        require_once GE_WTP_PLUGIN_DIR . 'includes/vendor/qrcode-generator/qrcode.php';
        $qr = new \Graphex\QR\QRCode();
        $qr->setErrorCorrectLevel( \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M );
        $qr->addData( $url, \Graphex\QR\QR_MODE_8BIT_BYTE );
        for ( $version = 1; $version <= 20; $version++ ) {
            $capacity = 0; foreach ( \Graphex\QR\QRRSBlock::getRSBlocks( $version, \Graphex\QR\QR_ERROR_CORRECT_LEVEL_M ) as $block ) { $capacity += $block->getDataCount() * 8; }
            if ( 4 + ( $version < 10 ? 8 : 16 ) + strlen( $url ) * 8 + 4 <= $capacity ) { $qr->setTypeNumber( $version ); $qr->make(); return $qr; }
        }
        return new WP_Error( 'qr_capacity', 'El enlace es demasiado largo.' );
    }
    public static function svg( $qr ) {
        $n = $qr->getModuleCount(); $size = $n + 8; $path = '';
        for ( $y = 0; $y < $n; $y++ ) { for ( $x = 0; $x < $n; $x++ ) { if ( $qr->isDark( $y, $x ) ) { $path .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z'; } } }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="25mm" height="25mm" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }
    private static function output( $data, $url, $file ) {
        status_header( 200 ); nocache_headers();
        header( 'X-Content-Type-Options: nosniff' ); header( 'X-Robots-Tag: noindex, nofollow' );
        if ( 'contacto.vcf' === $file ) { header( 'Content-Type: text/vcard; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="contacto.vcf"' ); echo self::vcf( $data, $url ); exit; }
        $qr = self::qr( $url ); if ( is_wp_error( $qr ) ) { wp_die( 'No se pudo generar el QR.', '', array( 'response' => 500 ) ); }
        if ( 'qr.svg' === $file ) { header( 'Content-Type: image/svg+xml' ); echo self::svg( $qr ); exit; }
        if ( 'qr.png' === $file && function_exists( 'imagecreatetruecolor' ) ) {
            $n = $qr->getModuleCount(); $scale = 16; $size = ( $n + 8 ) * $scale;
            $im = imagecreatetruecolor( $size, $size ); imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) ); $black = imagecolorallocate( $im, 0, 0, 0 );
            for ( $y = 0; $y < $n; $y++ ) { for ( $x = 0; $x < $n; $x++ ) { if ( $qr->isDark( $y, $x ) ) { imagefilledrectangle( $im, ( $x + 4 ) * $scale, ( $y + 4 ) * $scale, ( $x + 5 ) * $scale - 1, ( $y + 5 ) * $scale - 1, $black ); } } }
            header( 'Content-Type: image/png' ); imagepng( $im ); imagedestroy( $im ); exit;
        }
        wp_die( 'Formato no disponible.', '', array( 'response' => 404 ) );
    }
    public static function route() {
        if ( ! self::enabled() ) { return; }
        if ( self::product_context() && is_user_logged_in() ) { nocache_headers(); header( 'Referrer-Policy: no-referrer' ); }
        $path = self::path();
        if ( '/cliente-markcom/' === $path && self::actor_allowed() && 'vcard' === ( $_COOKIE[self::INTENT] ?? '' ) ) {
            setcookie( self::INTENT, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
            wp_safe_redirect( self::url( 'mi-vcard/' ) ); exit;
        }
        if ( '/tarjetas/' === $path ) { self::page( 'landing' ); }
        if ( '/tarjetas/mi-vcard/' === $path ) {
            nocache_headers();
            if ( ! is_user_logged_in() ) {
                setcookie( self::INTENT, 'vcard', array( 'expires' => time() + 1800, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
                $register = 'registro' === sanitize_key( $_GET['acceso'] ?? '' );
                wp_safe_redirect( GE_WTP_Portal::portal_url( '', $register ? array( 'modo' => 'registro' ) : array() ) ); exit;
            }
            if ( ! self::actor_allowed() ) { self::deny(); }
            self::page( 'editor', self::mine( get_current_user_id() ) );
        }
        if ( preg_match( '#^/tarjetas/demo/(contacto\.vcf|qr\.svg|qr\.png)?/?$#D', $path, $m ) ) {
            $data = array( 'first_name' => 'Alex', 'last_name' => 'Ejemplo', 'company' => 'Estudio Ejemplo', 'role' => 'Diseño y comunicación', 'email' => 'alex@example.com', 'phone' => '', 'website' => 'https://example.com' );
            if ( ! empty( $m[1] ) ) { self::output( $data, self::url( 'demo/' ), $m[1] ); }
            self::page( 'profile', null, $data, self::url( 'demo/' ), true );
        }
        if ( preg_match( '#^/contacto/([a-f0-9]{32})/(contacto\.vcf|qr\.svg|qr\.png)?/?$#D', $path, $m ) ) {
            nocache_headers(); header( 'Referrer-Policy: no-referrer' );
            $card = self::public_card( $m[1] );
            if ( ! $card ) { wp_die( 'Este perfil no está publicado.', 'Perfil no disponible', array( 'response' => 404 ) ); }
            $data = self::data( $card ); $url = self::public_url( $card );
            if ( ! empty( $m[2] ) ) {
                if ( 'contacto.vcf' !== $m[2] ) { if ( ! self::actor_allowed() || ! self::owned( $card, get_current_user_id() ) ) { self::deny(); } }
                self::output( $data, $url, $m[2] );
            }
            self::page( 'profile', $card, $data, $url );
        }
    }
    private static function page( $view, $card = null, $data = array(), $url = '', $demo = false ) {
        global $wp_query; status_header( 200 ); if ( $wp_query ) { $wp_query->is_404 = false; }
        if ( 'landing' !== $view ) { header( 'X-Robots-Tag: noindex, nofollow' ); }
        if ( 'profile' === $view ) { add_filter( 'document_title_parts', function( $parts ) use ( $data ) { $parts['title'] = trim( $data['first_name'] . ' ' . $data['last_name'] ) . ' · Contacto'; return $parts; }, 50 ); }
        $view_file = __DIR__ . '/ge-cards-experience/' . $view . '.php';
        ob_start(); get_header(); $head = ob_get_clean();
        // The legacy alternate-host shell emits its own canonical. Keep this feature's
        // canonical on the actual Graphex route without changing the shared shell.
        $head = preg_replace( "~<link\\b[^>]*\\brel=([\"'])canonical\\1[^>]*>\\s*~i", '', $head );
        $canonical = 'landing' === $view ? self::url() : ( 'profile' === $view ? $url : self::url( 'mi-vcard/' ) );
        $tags = '<link rel="canonical" href="' . esc_url( $canonical ) . '">';
        if ( 'landing' !== $view ) { $tags .= '<meta name="robots" content="noindex,nofollow">'; }
        echo str_replace( '</head>', $tags . '</head>', $head );
        include $view_file; get_footer(); exit;
    }
}
GE_Cards_Experience::init();
