<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GE_WTP_Newsletter {
    const CRON_HOOK = 'ge_newsletter_process_queue';
    private static $portal_context = false;

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
        add_action( 'admin_post_nopriv_ge_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
        add_action( 'admin_post_ge_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
        add_action( 'admin_post_nopriv_ge_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
        add_action( 'admin_post_ge_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
        add_action( 'admin_post_ge_newsletter_save_campaign', array( __CLASS__, 'handle_save_campaign' ) );
        add_action( 'admin_post_ge_newsletter_queue_campaign', array( __CLASS__, 'handle_queue_campaign' ) );
        add_action( 'admin_post_ge_newsletter_send_test', array( __CLASS__, 'handle_send_test' ) );
        add_shortcode( 'ge_newsletter_signup', array( __CLASS__, 'signup_shortcode' ) );
        add_shortcode( 'ge_email_preferences', array( __CLASS__, 'preferences_shortcode' ) );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_field' ) );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_checkout_consent' ), 20, 2 );
        add_action( 'woocommerce_register_form', array( __CLASS__, 'registration_field' ) );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_registration_consent' ) );
    }

    private static function contacts_table() { global $wpdb; return $wpdb->prefix . 'ge_newsletter_contacts'; }
    private static function campaigns_table() { global $wpdb; return $wpdb->prefix . 'ge_newsletter_campaigns'; }
    private static function deliveries_table() { global $wpdb; return $wpdb->prefix . 'ge_newsletter_deliveries'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $contacts = self::contacts_table();
        $campaigns = self::campaigns_table();
        $deliveries = self::deliveries_table();
        dbDelta( "CREATE TABLE {$contacts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'subscribed',
            source varchar(50) NOT NULL DEFAULT 'website',
            token char(64) NOT NULL,
            consent_at datetime NULL,
            unsubscribed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            UNIQUE KEY token (token),
            KEY status (status)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject varchar(240) NOT NULL,
            preview_text varchar(300) NOT NULL DEFAULT '',
            content longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            total_count int unsigned NOT NULL DEFAULT 0,
            sent_count int unsigned NOT NULL DEFAULT 0,
            failed_count int unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            queued_at datetime NULL,
            sent_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$deliveries} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            last_error varchar(500) NOT NULL DEFAULT '',
            sent_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_contact (campaign_id,contact_id),
            KEY campaign_status (campaign_id,status)
        ) {$charset};" );

        $page = get_page_by_path( 'preferencias-email' );
        if ( ! $page ) {
            wp_insert_post( array( 'post_title' => 'Preferencias de email', 'post_name' => 'preferencias-email', 'post_content' => '[ge_email_preferences]', 'post_status' => 'publish', 'post_type' => 'page' ) );
        }
    }

    private static function token() {
        try { return bin2hex( random_bytes( 32 ) ); } catch ( Exception $error ) { return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) ); }
    }

    public static function subscribe( $email, $first_name = '', $last_name = '', $source = 'website' ) {
        global $wpdb;
        $email = strtolower( sanitize_email( $email ) );
        if ( ! is_email( $email ) ) { return new WP_Error( 'invalid_email', 'El email no es válido.' ); }
        $table = self::contacts_table();
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, token FROM {$table} WHERE email = %s LIMIT 1", $email ) );
        $data = array( 'first_name' => sanitize_text_field( $first_name ), 'last_name' => sanitize_text_field( $last_name ), 'status' => 'subscribed', 'source' => sanitize_key( $source ), 'consent_at' => current_time( 'mysql' ), 'unsubscribed_at' => null, 'updated_at' => current_time( 'mysql' ) );
        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing->id ) );
            return (int) $existing->id;
        }
        $data['email'] = $email; $data['token'] = self::token(); $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $data );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : new WP_Error( 'db_error', 'No fue posible guardar la suscripción.' );
    }

    public static function unsubscribe_email( $email ) {
        global $wpdb;
        $email = strtolower( sanitize_email( $email ) );
        if ( ! is_email( $email ) ) { return false; }
        return false !== $wpdb->update( self::contacts_table(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'email' => $email ) );
    }

    public static function signup_block() {
        $status = isset( $_GET['newsletter'] ) ? sanitize_key( wp_unslash( $_GET['newsletter'] ) ) : '';
        ob_start(); ?>
        <section class="gx-newsletter" id="newsletter"><div class="gx-wrap gx-newsletter-card"><div><span class="gx-kicker gx-kicker-light"><i></i> Novedades Graph Express</span><h2>Ideas, materiales y oportunidades para imprimir mejor.</h2><p>Recibí novedades, productos y contenidos útiles. Sin ruido: podés darte de baja cuando quieras.</p></div><div><?php if ( 'ok' === $status ) : ?><p class="gx-newsletter-notice">¡Listo! Ya estás en nuestra lista.</p><?php elseif ( 'error' === $status ) : ?><p class="gx-newsletter-notice is-error">Revisá el email y la aceptación.</p><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_newsletter_subscribe"><?php wp_nonce_field( 'ge_newsletter_subscribe' ); ?><div class="gx-newsletter-fields"><input type="text" name="first_name" placeholder="Nombre" maxlength="100"><input type="email" name="email" placeholder="tu@email.com" required maxlength="190"><button type="submit">Quiero recibir novedades →</button></div><label><input type="checkbox" name="consent" value="1" required><span>Acepto recibir comunicaciones comerciales de Graph Express.</span></label></form></div></div></section>
        <?php return ob_get_clean();
    }

    public static function signup_shortcode() { return self::signup_block(); }

    public static function handle_subscribe() {
        check_admin_referer( 'ge_newsletter_subscribe' );
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
        $result = empty( $_POST['consent'] ) ? new WP_Error( 'consent', 'Falta consentimiento.' ) : self::subscribe( $email, $name, '', 'website' );
        wp_safe_redirect( add_query_arg( 'newsletter', is_wp_error( $result ) ? 'error' : 'ok', home_url( '/' ) ) . '#newsletter' ); exit;
    }

    public static function checkout_field( $fields ) {
        $fields['order']['ge_newsletter_optin'] = array( 'type' => 'checkbox', 'label' => 'Quiero recibir novedades y promociones de Graph Express.', 'required' => false, 'class' => array( 'form-row-wide' ), 'priority' => 120 );
        return $fields;
    }

    public static function save_checkout_consent( $order, $data ) {
        if ( empty( $_POST['ge_newsletter_optin'] ) ) { return; }
        $order->update_meta_data( '_ge_newsletter_optin', 'yes' );
        self::subscribe( $order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name(), 'checkout' );
        if ( $order->get_customer_id() ) { update_user_meta( $order->get_customer_id(), '_ge_newsletter_optin', 'yes' ); }
    }

    public static function registration_field() {
        ?><p class="woocommerce-form-row form-row form-row-wide"><label><input type="checkbox" name="ge_newsletter_optin" value="1"> Quiero recibir novedades y promociones de Graph Express.</label></p><?php
    }

    public static function save_registration_consent( $customer_id ) {
        if ( empty( $_POST['ge_newsletter_optin'] ) ) { return; }
        $user = get_userdata( $customer_id );
        if ( $user ) { self::subscribe( $user->user_email, $user->first_name, $user->last_name, 'registration' ); update_user_meta( $customer_id, '_ge_newsletter_optin', 'yes' ); }
    }

    public static function preferences_shortcode() {
        global $wpdb;
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $contact = $token ? $wpdb->get_row( $wpdb->prepare( 'SELECT email, status FROM ' . self::contacts_table() . ' WHERE token = %s LIMIT 1', $token ) ) : false;
        ob_start(); ?><main style="max-width:620px;margin:80px auto;padding:30px;font-family:Arial,sans-serif"><div style="padding:32px;border:1px solid #e4e1eb;border-radius:18px"><span style="color:#6d45ef;font-weight:700">GRAPH EXPRESS</span><?php if ( ! $contact ) : ?><h1>Enlace no válido</h1><p>No encontramos esta preferencia de email.</p><?php elseif ( isset( $_GET['done'] ) ) : ?><h1>Preferencia actualizada</h1><p>Ya no vas a recibir newsletters. Los correos necesarios para pedidos pueden seguir llegando.</p><?php elseif ( 'unsubscribed' === $contact->status ) : ?><h1>Suscripción desactivada</h1><p>Este correo ya fue dado de baja.</p><?php else : ?><h1>Preferencias de email</h1><p>Vas a dejar de recibir novedades en <?php echo esc_html( self::mask_email( $contact->email ) ); ?>.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_newsletter_unsubscribe"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'ge_newsletter_unsubscribe_' . $token ); ?><button style="padding:13px 18px;border:0;border-radius:9px;background:#17152a;color:#fff;font-weight:700" type="submit">Confirmar baja</button></form><?php endif; ?></div></main><?php return ob_get_clean();
    }

    private static function mask_email( $email ) { $parts = explode( '@', $email ); return substr( $parts[0], 0, 2 ) . '***@' . ( $parts[1] ?? '' ); }

    public static function handle_unsubscribe() {
        global $wpdb; $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; check_admin_referer( 'ge_newsletter_unsubscribe_' . $token ); $wpdb->update( self::contacts_table(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( 'token' => $token ) ); $page = get_page_by_path( 'preferencias-email' ); $url = $page ? get_permalink( $page ) : home_url( '/preferencias-email/' ); wp_safe_redirect( add_query_arg( array( 'token' => $token, 'done' => 1 ), $url ) ); exit;
    }

    public static function render_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'campaigns';
        echo '<div class="wrap ge-admin-wrap">'; GE_WTP_Backoffice::render_communications_header();
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab ' . ( 'campaigns' === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=ge-backoffice-newsletter&view=campaigns' ) ) . '">Campañas</a><a class="nav-tab ' . ( 'contacts' === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=ge-backoffice-newsletter&view=contacts' ) ) . '">Contactos</a><a class="nav-tab ' . ( 'emails' === $view ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=ge-backoffice-newsletter&view=emails' ) ) . '">Correos enviados</a></nav>';
        if ( 'contacts' === $view ) { self::render_contacts(); } elseif ( 'emails' === $view ) { self::render_email_logs(); } else { self::render_campaigns(); }
        echo '</div>';
    }

    public static function render_portal() {
        if ( ! class_exists( 'GE_WTP_Staff_Portal' ) || ! GE_WTP_Staff_Portal::can_access() || ! current_user_can( 'ge_manage_communications' ) ) {
            echo '<div class="ge-admin-empty">No tenés permiso para administrar comunicaciones.</div>';
            return;
        }
        self::$portal_context = true;
        $view = isset( $_GET['subsection'] ) ? sanitize_key( wp_unslash( $_GET['subsection'] ) ) : 'campaigns';
        echo '<div class="ge-staff-heading"><div><span>Comunicaciones</span><h1>Newsletter y correos</h1><p>Contactos, campañas y trazabilidad.</p></div></div>';
        echo '<nav class="ge-staff-tabs"><a class="' . ( 'campaigns' === $view ? 'is-active' : '' ) . '" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'communications', array( 'subsection' => 'campaigns' ) ) ) . '">Campañas</a><a class="' . ( 'contacts' === $view ? 'is-active' : '' ) . '" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'communications', array( 'subsection' => 'contacts' ) ) ) . '">Contactos</a><a class="' . ( 'emails' === $view ? 'is-active' : '' ) . '" href="' . esc_url( GE_WTP_Staff_Portal::portal_url( 'communications', array( 'subsection' => 'emails' ) ) ) . '">Correos enviados</a></nav>';
        if ( 'contacts' === $view ) { self::render_contacts(); } elseif ( 'emails' === $view ) { self::render_email_logs(); } else { self::render_campaigns(); }
        self::$portal_context = false;
    }

    private static function return_field() {
        if ( self::$portal_context ) {
            echo '<input type="hidden" name="return_to" value="staff">';
        }
    }

    private static function render_campaigns() {
        global $wpdb; $campaigns = $wpdb->get_results( 'SELECT * FROM ' . self::campaigns_table() . ' ORDER BY id DESC LIMIT 100' );
        ?><div class="ge-admin-communications"><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Nueva comunicación</span><h2>Crear campaña</h2></div></div><form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_newsletter_save_campaign"><?php self::return_field(); wp_nonce_field( 'ge_newsletter_save_campaign' ); ?><label>Asunto<input type="text" name="subject" required maxlength="240"></label><label>Texto de vista previa<input type="text" name="preview_text" maxlength="300"></label><label>Contenido<textarea name="content" rows="12" required placeholder="Escribí el contenido. Podés usar títulos, párrafos, listas y enlaces."></textarea></label><button class="button button-primary" type="submit">Guardar borrador</button></form></section><section class="ge-admin-panel"><div class="ge-admin-panel-head"><div><span>Historial</span><h2>Campañas</h2></div></div><?php if ( ! $campaigns ) : ?><div class="ge-admin-empty">Todavía no hay campañas.</div><?php else : ?><div class="ge-admin-campaigns"><?php foreach ( $campaigns as $campaign ) : ?><article><div><span class="ge-admin-status"><?php echo esc_html( ucfirst( $campaign->status ) ); ?></span><h3><?php echo esc_html( $campaign->subject ); ?></h3><small><?php echo esc_html( mysql2date( 'd/m/Y H:i', $campaign->created_at ) ); ?> · <?php echo esc_html( $campaign->sent_count ); ?> enviados · <?php echo esc_html( $campaign->failed_count ); ?> fallidos</small></div><div><?php if ( 'draft' === $campaign->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_newsletter_send_test"><input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign->id ); ?>"><?php self::return_field(); wp_nonce_field( 'ge_newsletter_send_test_' . $campaign->id ); ?><button class="button" type="submit">Enviar prueba</button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ge_newsletter_queue_campaign"><input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign->id ); ?>"><?php self::return_field(); wp_nonce_field( 'ge_newsletter_queue_campaign_' . $campaign->id ); ?><button class="button button-primary" type="submit">Programar envío</button></form><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></section></div><?php
    }

    private static function render_contacts() {
        global $wpdb; $contacts = $wpdb->get_results( 'SELECT * FROM ' . self::contacts_table() . ' ORDER BY id DESC LIMIT 500' ); $subscribed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::contacts_table() . " WHERE status='subscribed'" );
        ?><section class="ge-admin-panel ge-admin-tab-panel"><div class="ge-admin-panel-head"><div><span><?php echo esc_html( $subscribed ); ?> suscriptos activos</span><h2>Contactos con consentimiento</h2></div></div><?php if ( ! $contacts ) : ?><div class="ge-admin-empty">Todavía no hay contactos suscriptos.</div><?php else : ?><div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Email</th><th>Nombre</th><th>Origen</th><th>Estado</th><th>Consentimiento</th></tr></thead><tbody><?php foreach ( $contacts as $contact ) : ?><tr><td><strong><?php echo esc_html( $contact->email ); ?></strong></td><td><?php echo esc_html( trim( $contact->first_name . ' ' . $contact->last_name ) ?: '—' ); ?></td><td><?php echo esc_html( $contact->source ); ?></td><td><span class="ge-admin-status"><?php echo esc_html( $contact->status ); ?></span></td><td><?php echo esc_html( $contact->consent_at ? mysql2date( 'd/m/Y H:i', $contact->consent_at ) : '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
    }

    private static function render_email_logs() {
        $logs = GE_WTP_Notifications::get_logs( 200 ); ?><section class="ge-admin-panel ge-admin-tab-panel"><div class="ge-admin-panel-head"><div><span>Trazabilidad</span><h2>Correos transaccionales y campañas</h2></div></div><?php if ( ! $logs ) : ?><div class="ge-admin-empty">Todavía no hay correos registrados.</div><?php else : ?><div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Fecha</th><th>Destinatario</th><th>Asunto</th><th>Contexto</th><th>Resultado</th></tr></thead><tbody><?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( get_the_date( 'd/m/Y H:i', $log ) ); ?></td><td><?php echo esc_html( get_post_meta( $log->ID, '_ge_email_to', true ) ); ?></td><td><strong><?php echo esc_html( $log->post_title ); ?></strong></td><td><?php echo esc_html( get_post_meta( $log->ID, '_ge_email_context', true ) ); ?></td><td><span class="ge-admin-status ge-mail-<?php echo esc_attr( get_post_meta( $log->ID, '_ge_email_result', true ) ); ?>"><?php echo esc_html( get_post_meta( $log->ID, '_ge_email_result', true ) ); ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
    }

    public static function handle_save_campaign() {
        global $wpdb; self::admin_guard(); check_admin_referer( 'ge_newsletter_save_campaign' ); $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : ''; $content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : ''; if ( ! $subject || ! $content ) { wp_die( 'Asunto y contenido son obligatorios.' ); } $wpdb->insert( self::campaigns_table(), array( 'subject' => $subject, 'preview_text' => isset( $_POST['preview_text'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_text'] ) ) : '', 'content' => $content, 'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) ); self::admin_redirect();
    }

    public static function handle_send_test() {
        global $wpdb; self::admin_guard(); $id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; check_admin_referer( 'ge_newsletter_send_test_' . $id ); $campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::campaigns_table() . ' WHERE id=%d', $id ) ); if ( ! $campaign ) { wp_die( 'Campaña inválida.' ); } $admin = wp_get_current_user(); GE_WTP_Notifications::send( $admin->user_email, '[PRUEBA] ' . $campaign->subject, self::campaign_html( $campaign, '', 'Vista previa del administrador' ), 'newsletter_test', $id ); self::admin_redirect();
    }

    public static function handle_queue_campaign() {
        global $wpdb; self::admin_guard(); $id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; check_admin_referer( 'ge_newsletter_queue_campaign_' . $id ); $campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::campaigns_table() . ' WHERE id=%d AND status=%s', $id, 'draft' ) ); if ( ! $campaign ) { wp_die( 'La campaña no está disponible para enviar.' ); } $contacts = $wpdb->get_col( "SELECT id FROM " . self::contacts_table() . " WHERE status='subscribed'" ); foreach ( $contacts as $contact_id ) { $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::deliveries_table() . ' (campaign_id,contact_id,status) VALUES (%d,%d,%s)', $id, $contact_id, 'pending' ) ); } $wpdb->update( self::campaigns_table(), array( 'status' => 'queued', 'total_count' => count( $contacts ), 'queued_at' => current_time( 'mysql' ) ), array( 'id' => $id ) ); wp_schedule_single_event( time() + 10, self::CRON_HOOK ); self::admin_redirect();
    }

    public static function process_queue() {
        global $wpdb; $campaign = $wpdb->get_row( "SELECT * FROM " . self::campaigns_table() . " WHERE status IN ('queued','sending') ORDER BY id ASC LIMIT 1" ); if ( ! $campaign ) { return; } $wpdb->update( self::campaigns_table(), array( 'status' => 'sending' ), array( 'id' => $campaign->id ) ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT d.id AS delivery_id,d.attempts,c.* FROM ' . self::deliveries_table() . ' d INNER JOIN ' . self::contacts_table() . " c ON c.id=d.contact_id WHERE d.campaign_id=%d AND d.status='pending' AND c.status='subscribed' ORDER BY d.id ASC LIMIT 20", $campaign->id ) ); foreach ( $rows as $contact ) { $unsubscribe = self::unsubscribe_url( $contact->token ); $html = self::campaign_html( $campaign, $unsubscribe, $contact->first_name ); $ok = GE_WTP_Notifications::send( $contact->email, $campaign->subject, $html, 'newsletter_campaign', $campaign->id ); $wpdb->update( self::deliveries_table(), array( 'status' => $ok ? 'sent' : 'failed', 'attempts' => (int) $contact->attempts + 1, 'last_error' => $ok ? '' : 'wp_mail devolvió error', 'sent_at' => $ok ? current_time( 'mysql' ) : null ), array( 'id' => $contact->delivery_id ) ); } $sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::deliveries_table() . " WHERE campaign_id=%d AND status='sent'", $campaign->id ) ); $failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::deliveries_table() . " WHERE campaign_id=%d AND status='failed'", $campaign->id ) ); $pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::deliveries_table() . " WHERE campaign_id=%d AND status='pending'", $campaign->id ) ); $data = array( 'sent_count' => $sent, 'failed_count' => $failed ); if ( 0 === $pending ) { $data['status'] = 'sent'; $data['sent_at'] = current_time( 'mysql' ); } else { wp_schedule_single_event( time() + 60, self::CRON_HOOK ); } $wpdb->update( self::campaigns_table(), $data, array( 'id' => $campaign->id ) );
    }

    private static function campaign_html( $campaign, $unsubscribe_url, $first_name ) {
        $hello = $first_name ? '<p style="font-size:16px">Hola ' . esc_html( $first_name ) . ',</p>' : ''; $preview = $campaign->preview_text ? '<div style="display:none;max-height:0;overflow:hidden">' . esc_html( $campaign->preview_text ) . '</div>' : ''; $unsubscribe = $unsubscribe_url ? '<p style="margin-top:30px;font-size:11px;color:#898594">Recibís este email porque aceptaste novedades de Graph Express. <a href="' . esc_url( $unsubscribe_url ) . '">Administrar preferencia o darte de baja</a>.</p>' : ''; return '<!doctype html><html><body style="margin:0;background:#f3f2f6;font-family:Arial,sans-serif;color:#17152a">' . $preview . '<div style="max-width:680px;margin:auto;padding:30px 18px"><div style="padding:20px 26px;border-radius:16px 16px 0 0;background:#111629;color:#fff"><strong style="letter-spacing:.1em">GRAPH EXPRESS</strong></div><div style="padding:34px 28px;border-radius:0 0 16px 16px;background:#fff">' . $hello . '<h1 style="font-size:30px">' . esc_html( $campaign->subject ) . '</h1><div style="font-size:16px;line-height:1.65;color:#4f4b59">' . wp_kses_post( wpautop( $campaign->content ) ) . '</div>' . $unsubscribe . '</div></div></body></html>';
    }

    private static function unsubscribe_url( $token ) { $page = get_page_by_path( 'preferencias-email' ); $url = $page ? get_permalink( $page ) : home_url( '/preferencias-email/' ); return add_query_arg( 'token', rawurlencode( $token ), $url ); }
    private static function admin_guard() { if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ge_manage_communications' ) ) { wp_die( 'Acceso denegado.', 403 ); } }
    private static function admin_redirect() { $staff = isset( $_POST['return_to'] ) && 'staff' === sanitize_key( wp_unslash( $_POST['return_to'] ) ); wp_safe_redirect( $staff && class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'communications' ) : admin_url( 'admin.php?page=ge-backoffice-newsletter&ge_saved=1' ) ); exit; }
}
