<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Draft review on the native newsletter tables. This release has no send authority. */
final class GE_WTP_Newsletter_Review {
    const TEMPLATE = 'newsletter-v3-template.html';
    const SUBJECT = 'PUBLICIDAD · Visitá nuestra tienda online y tu portal de clientes';
    const META = 'ge_newsletter_review_';

    public static function init() {
        require_once __DIR__ . '/class-ge-wtp-campaign-actions.php';
        GE_WTP_Campaign_Actions::init();
        add_action( 'admin_post_ge_newsletter_review_draft', array( __CLASS__, 'handle_draft' ) );
        add_action( 'admin_post_ge_newsletter_review_batch', array( __CLASS__, 'handle_batch' ) );
    }

    public static function allowed() {
        return is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'ge_manage_communications' ) )
            && class_exists( 'GE_Organization' ) && GE_Organization::can( GE_Organization::PRIMARY, get_current_user_id() );
    }

    public static function sending_allowed() { return false; }

    private static function table( $suffix ) { global $wpdb; return $wpdb->prefix . 'ge_newsletter_' . $suffix; }
    private static function template() { return file_get_contents( dirname( __DIR__ ) . '/templates/' . self::TEMPLATE ); }
    public static function metadata( $id ) { return (array) get_option( self::META . absint( $id ), array() ); }
    public static function campaign( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d', absint( $id ) ) );
    }

    public static function eligible( $contact ) {
        return $contact && 'subscribed' === $contact->status && ( ! empty( $contact->consent_at ) || GE_WTP_Newsletter_Audiences::owner_authorized( $contact ) )
            && empty( $contact->unsubscribed_at ) && is_email( $contact->email )
            && preg_match( '/^[a-f0-9]{64}$/D', (string) $contact->token );
    }

    /** No recipient PII is duplicated into review metadata. */
    public static function contact_fingerprint( $contact ) {
        return hash( 'sha256', wp_json_encode( array( (int) $contact->id, strtolower( $contact->email ), $contact->status,
            $contact->consent_at, $contact->unsubscribed_at, $contact->token, $contact->source, $contact->authorization_kind ?? '', $contact->authorization_at ?? '', $contact->audience ?? '', $contact->selection_meta ?? '' ) ) );
    }

    public static function create_draft( $notice = '', $source = '' ) {
        global $wpdb;
        if ( ! self::allowed() ) { return new WP_Error( 'denied', 'Acceso denegado.' ); }
        if ( ! is_string( $notice ) || ! is_string( $source ) || strlen( $notice ) > 16000 || strlen( $source ) > 2000 ) { return new WP_Error( 'input', 'Revisá el aviso y su fuente.' ); }
        $html = self::template();
        if ( ! $html || false === strpos( $html, '{{unsubscribe_url}}' ) || false === strpos( $html, '{{statutory_notice}}' ) ) {
            return new WP_Error( 'template', 'La plantilla no está disponible.' );
        }
        $ok = $wpdb->insert( self::table( 'campaigns' ), array( 'subject' => self::SUBJECT,
            'preview_text' => 'Descubrí nuestros productos y accedé a tu portal de clientes en graphex.ar.',
            'content' => $html, 'status' => 'draft', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) );
        if ( ! $ok ) { return new WP_Error( 'database', 'No se pudo guardar el borrador.' ); }
        $id = (int) $wpdb->insert_id;
        $meta = array( 'version' => 'tienda-v3', 'template_sha256' => hash( 'sha256', $html ),
            'statutory_notice' => sanitize_textarea_field( $notice ), 'notice_source' => sanitize_text_field( $source ),
            'created_by' => get_current_user_id(), 'created_at' => gmdate( 'c' ), 'send_authority' => false );
        if ( ! add_option( self::META . $id, $meta, '', false ) ) {
            $wpdb->delete( self::table( 'campaigns' ), array( 'id' => $id, 'status' => 'draft' ) );
            return new WP_Error( 'metadata', 'No se pudo conservar la versión. No se creó una campaña para enviar.' );
        }
        return $id;
    }

    public static function content_fingerprint( $campaign, $meta ) {
        $organization = GE_Organization::get( GE_Organization::PRIMARY );
        return hash( 'sha256', wp_json_encode( array( $campaign->subject, $campaign->preview_text, $campaign->content,
            $meta['statutory_notice'] ?? '', $meta['notice_source'] ?? '', $meta['template_sha256'] ?? '',
            $meta['audience_key'] ?? '', $organization['settings']['email'] ?? array(), $organization['settings']['general']['email'] ?? '' ) ) );
    }

    public static function render_html( $campaign, $unsubscribe_url = '' ) {
        $meta = self::metadata( $campaign->id );
        if ( empty( $meta['version'] ) || ! hash_equals( (string) $meta['template_sha256'], hash( 'sha256', $campaign->content ) ) ) { return ''; }
        $notice = empty( $meta['statutory_notice'] ) ? 'Vista previa: falta revisar el aviso legal y su fuente.' : $meta['statutory_notice'];
        return str_replace( array( '{{unsubscribe_url}}', '{{statutory_notice}}' ), array(
            $unsubscribe_url ? esc_url( $unsubscribe_url ) : '#baja-personalizada-al-enviar',
            '<p style="font-size:12px;line-height:1.6">' . nl2br( esc_html( $notice ) ) . '</p>' ), $campaign->content );
    }

    /** Exact selected native contacts, never all contacts or candidates from a mailbox. */
    public static function review_batch( $id, $selected ) {
        global $wpdb;
        if ( ! self::allowed() ) { return new WP_Error( 'denied', 'Acceso denegado.' ); }
        $campaign = self::campaign( $id ); $meta = self::metadata( $id );
        if ( ! $campaign || 'draft' !== $campaign->status || empty( $meta['version'] ) || ! self::render_html( $campaign ) ) {
            return new WP_Error( 'draft', 'Elegí una versión de borrador válida.' );
        }
        if ( ! is_array( $selected ) || count( $selected ) > 500 ) { return new WP_Error( 'selection', 'Revisá la selección de destinatarios.' ); }
        foreach ( $selected as $value ) {
            if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) { return new WP_Error( 'selection', 'La selección no es válida.' ); }
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $selected ) ) ) ); sort( $ids, SORT_NUMERIC );
        if ( ! $ids ) { return new WP_Error( 'selection', 'Seleccioná al menos un contacto con consentimiento.' ); }
        $lock = 'ge_newsletter_review_' . (int) $id;
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'busy', 'Otra revisión está en curso. Volvé a intentar.' ); }
        try {
            $campaign = self::campaign( $id ); $meta = self::metadata( $id );
            if ( ! $campaign || 'draft' !== $campaign->status || ! self::render_html( $campaign ) ) { return new WP_Error( 'draft', 'El borrador cambió. Revisalo nuevamente.' ); }
            $fingerprints = array();
            foreach ( $ids as $contact_id ) {
                $contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'contacts' ) . ' WHERE id=%d', $contact_id ) );
                if ( ! self::eligible( $contact ) ) { return new WP_Error( 'consent', 'La selección contiene un contacto sin consentimiento activo. Revisá el lote.' ); }
                $fingerprints[ $contact_id ] = self::contact_fingerprint( $contact );
            }
            $review = array( 'content_sha256' => self::content_fingerprint( $campaign, $meta ), 'contacts' => $fingerprints,
                'reviewed_by' => get_current_user_id(), 'reviewed_at' => gmdate( 'c' ), 'send_authority' => false );
            $review['batch_sha256'] = hash( 'sha256', wp_json_encode( array( $review['content_sha256'], $fingerprints ) ) );
            if ( isset( $meta['review']['batch_sha256'] ) && hash_equals( $meta['review']['batch_sha256'], $review['batch_sha256'] ) ) { return $meta['review']; }
            $meta['review'] = $review;
            if ( ! update_option( self::META . (int) $id, $meta, false ) ) { return new WP_Error( 'save', 'No se pudo guardar la revisión.' ); }
            return $review;
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function review_valid( $campaign ) {
        global $wpdb;
        if ( ! $campaign || 'draft' !== $campaign->status || ! self::render_html( $campaign ) ) { return false; }
        $meta = self::metadata( $campaign->id ); $review = $meta['review'] ?? array();
        if ( empty( $review['contacts'] ) || empty( $review['content_sha256'] )
            || ! hash_equals( $review['content_sha256'], self::content_fingerprint( $campaign, $meta ) ) ) { return false; }
        if ( ($review['selection_mode'] ?? '') === 'whole_audience' ) {
            $snapshot = GE_WTP_Campaign_Actions::snapshot($review['audience_key']);
            return !is_wp_error($snapshot) && hash_equals($review['membership_sha256'], $snapshot['membership_sha256']);
        }
        foreach ( $review['contacts'] as $id => $fingerprint ) {
            $contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'contacts' ) . ' WHERE id=%d', $id ) );
            if ( ! self::eligible( $contact ) || ! hash_equals( $fingerprint, self::contact_fingerprint( $contact ) ) ) { return false; }
        }
        return true;
    }

    private static function guard( $nonce ) {
        if ( ! self::allowed() ) { wp_die( 'Acceso denegado.', 403 ); }
        check_admin_referer( $nonce );
    }
    private static function redirect( $id, $error = '' ) {
        $args = array( 'newsletter_campaign' => absint( $id ) );
        if ( $error ) { $args['newsletter_error'] = sanitize_key( $error ); }
        $url = class_exists( 'GE_WTP_Staff_Portal' ) ? GE_WTP_Staff_Portal::portal_url( 'communications', $args )
            : add_query_arg( $args, admin_url( 'admin.php?page=ge-backoffice-newsletter' ) );
        wp_safe_redirect( $url ); exit;
    }
    public static function handle_draft() {
        self::guard( 'ge_newsletter_review_draft' );
        $id = self::create_draft( wp_unslash( $_POST['statutory_notice'] ?? '' ), wp_unslash( $_POST['notice_source'] ?? '' ) );
        self::redirect( is_wp_error( $id ) ? 0 : $id, is_wp_error( $id ) ? $id->get_error_code() : '' );
    }
    public static function handle_batch() {
        $id = absint( $_POST['campaign_id'] ?? 0 ); self::guard( 'ge_newsletter_review_batch_' . $id );
        $review = GE_WTP_Campaign_Actions::select_audience( $id, $_POST['audience_key'] ?? '' );
        self::redirect( $id, is_wp_error( $review ) ? $review->get_error_code() : '' );
    }

    public static function render() {
        global $wpdb;
        if ( ! self::allowed() ) { return; }
        if ( ! GE_WTP_Newsletter_Audiences::ensure_schema() ) { echo '<p role="alert">No se pudo preparar la lista de contactos.</p>'; return; }
        $show_deleted = isset($_GET['show_deleted']) && '1' === (string)$_GET['show_deleted'];
        $campaigns = $wpdb->get_results( 'SELECT * FROM ' . self::table( 'campaigns' ) . ($show_deleted ? " WHERE status='deleted'" : " WHERE status<>'deleted'") . ' ORDER BY id DESC LIMIT 100' );
        $id = absint( $_GET['newsletter_campaign'] ?? 0 ); $campaign = $id ? self::campaign( $id ) : null;
        $meta = $campaign ? self::metadata( $id ) : array();
        ?>
        <div class="ge-admin-communications">
        <section class="ge-admin-panel">
            <div class="ge-admin-panel-head"><div><span>Marketing por email</span><h2>Preparar una campaña</h2></div></div>
            <p>Guardá una versión, revisá cómo se verá y seleccioná sus destinatarios. Los envíos están desactivados durante esta preparación.</p>
            <?php if ( ! empty( $_GET['newsletter_error'] ) ) : ?><p role="alert">No se pudo completar la revisión. Verificá el borrador y el consentimiento de los contactos seleccionados.</p><?php endif; ?>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ge_newsletter_review_draft"><?php wp_nonce_field( 'ge_newsletter_review_draft' ); ?>
                <p><strong>Tienda y portal de clientes · versión 3</strong><br><?php echo esc_html( self::SUBJECT ); ?></p>
                <details><summary>Aviso legal y fuente</summary>
                    <label>Aviso legal revisado<textarea name="statutory_notice" rows="4" maxlength="4000"></textarea></label>
                    <label>Fuente del aviso y de los datos del responsable<input type="text" name="notice_source" maxlength="500"></label>
                    <p>Si todavía falta verificarlo, podés guardar el borrador. Esa versión no estará lista para enviar.</p>
                </details>
                <button class="button button-primary" type="submit">Crear versión de borrador</button>
            </form>
        </section>
        <?php if ( $campaign && 'draft' === $campaign->status && ! empty( $meta['version'] ) ) : $html = self::render_html( $campaign ); ?>
        <section class="ge-admin-panel">
            <h2>Revisar contenido y destinatarios</h2>
            <p><strong><?php echo esc_html( $campaign->subject ); ?></strong><br>Versión <?php echo esc_html( $meta['version'] ); ?> · Borrador #<?php echo (int) $id; ?></p>
            <?php if ( ! $html ) : ?><p role="alert">El contenido cambió. Creá una nueva versión antes de revisar el lote.</p><?php else : ?>
            <details open><summary>Vista previa del correo</summary>
                <iframe title="Vista previa de la campaña" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr( $html ); ?>" style="display:block;width:100%;height:780px;border:1px solid #ddd;margin-top:16px"></iframe>
            </details>
            <p>El enlace de baja se personaliza para cada destinatario al enviar. Esta vista previa no envía correos.</p>
            <form class="ge-admin-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ge_newsletter_review_batch"><input type="hidden" name="campaign_id" value="<?php echo (int)$id; ?>">
                <?php wp_nonce_field('ge_newsletter_review_batch_'.$id); ?>
                <label>Audiencia completa<select name="audience_key" required><option value="">Elegí una audiencia</option>
                    <?php foreach(GE_WTP_Campaign_Actions::audiences() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($meta['audience_key']??'',$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select></label>
                <p>Al elegir una audiencia se selecciona todo el grupo. Las bajas y los rebotes se conservan como excluidos; los posibles suscriptores mantienen su autorización pendiente.</p>
                <button class="button button-primary" type="submit">Guardar audiencia completa</button>
            </form>
            <?php if(!empty($meta['review'])): $review=$meta['review']; ?><p role="status"><?php echo self::review_valid($campaign)?'Audiencia registrada: '.count($review['contacts']).' contactos.':'La audiencia o el contenido cambió. Volvé a guardar la selección.'; ?> No se autorizó un envío.</p>
                <?php if(($review['selection_mode']??'')==='whole_audience'): ?><p><?php echo (int)$review['eligible']; ?> autorizados · <?php echo (int)$review['suppressed']; ?> excluidos por baja o rebote · <?php echo (int)$review['pending_authorization']; ?> pendientes de autorización.</p><?php endif; ?>
                <a href="<?php echo esc_url(GE_WTP_Staff_Portal::portal_url('crm',array('view'=>'activity'))); ?>">Ver registro de actividad en el CRM</a>
            <?php endif; ?>
            <details><summary>Qué falta para enviar</summary><ul><li>Revisión final del contenido, aviso legal y lote exacto.</li><li>Cuota del proveedor, frecuencia y tratamiento de rebotes verificados.</li><li>Activación del envío con control de concurrencia y conciliación de resultados inciertos.</li></ul></details>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <section class="ge-admin-panel"><h2><?php echo $show_deleted?'Campañas eliminadas':'Versiones y campañas'; ?></h2>
        <a href="<?php echo esc_url(add_query_arg('show_deleted',$show_deleted?'0':'1',remove_query_arg('newsletter_campaign'))); ?>"><?php echo $show_deleted?'Ver campañas activas':'Ver eliminadas'; ?></a>
        <?php if ( ! $campaigns ) : ?><p>Todavía no hay campañas.</p><?php else : ?><div class="ge-admin-campaigns">
        <?php foreach ( $campaigns as $row ) : $row_meta = self::metadata( $row->id ); ?>
            <article><div><h3><?php echo esc_html( $row->subject ); ?></h3><p><?php echo esc_html(array('draft'=>'Borrador','deleted'=>'Eliminada','queued'=>'En cola','sent'=>'Enviada')[$row->status]??$row->status); ?> · <?php echo esc_html( mysql2date( 'd/m/Y H:i', $row->created_at ) ); ?> · <?php echo (int) $row->sent_count; ?> aceptados por correo</p></div>
                <?php if ( 'draft' === $row->status && ! empty( $row_meta['version'] ) ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'newsletter_campaign', $row->id ) ); ?>">Revisar versión</a><?php else : ?><span><?php echo $row->status==='deleted'?'Eliminada; podés restaurar este borrador.':'Versión anterior: requiere preparar una nueva revisión.'; ?></span><?php endif; ?>
                <?php if(in_array($row->status,array('draft','deleted'),true)): $delete=$row->status==='draft'; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ge_newsletter_campaign_<?php echo $delete?'delete':'restore'; ?>"><input type="hidden" name="campaign_id" value="<?php echo (int)$row->id; ?>">
                    <?php wp_nonce_field('ge_newsletter_campaign_'.($delete?'delete':'restore').'_'.$row->id); ?>
                    <button class="button" type="submit"><?php echo $delete?'Eliminar borrador':'Restaurar borrador'; ?></button>
                </form><?php endif; ?>
            </article>
        <?php endforeach; ?></div><?php endif; ?></section>
        </div><?php
    }
}
