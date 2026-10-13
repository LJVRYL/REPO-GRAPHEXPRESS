<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Owner-selected and prospective audiences on the existing native contact table. */
final class GE_WTP_Newsletter_Audiences {
    const SCHEMA = 'ge_newsletter_audiences_schema_v1';
    const AUDIT = 'ge_newsletter_audiences_audit_v1';
    public static function table() { global $wpdb; return $wpdb->prefix . 'ge_newsletter_contacts'; }

    public static function ensure_schema() {
        global $wpdb;
        if ( 1 === (int) get_option( self::SCHEMA ) ) { return true; }
        $columns = array( 'authorization_kind' => "varchar(30) NOT NULL DEFAULT ''", 'authorization_at' => 'datetime NULL',
            'audience' => "varchar(20) NOT NULL DEFAULT ''", 'selection_meta' => 'longtext NULL' );
        foreach ( $columns as $name => $definition ) {
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . self::table() . ' LIKE %s', $name ) ) ) {
                if ( false === $wpdb->query( 'ALTER TABLE ' . self::table() . " ADD COLUMN {$name} {$definition}" ) ) { return false; }
            }
        }
        update_option( self::SCHEMA, 1, false ); return true;
    }

    public static function owner_authorized( $contact ) {
        if ( ! $contact || 'owner_selected' !== ( $contact->authorization_kind ?? '' ) || empty( $contact->authorization_at ) ) { return false; }
        $meta = json_decode( $contact->selection_meta ?? '', true );
        return is_array( $meta ) && ! empty( $meta['authority_ref'] ) && ! empty( $meta['actor'] )
            && isset( $meta['recipient_optin_claimed'] ) && false === $meta['recipient_optin_claimed'];
    }

    /** No messages, customer accounts or recipient consent dates are created by this import. */
    public static function import_batch( $rows, $audience, $authority_ref, $batch_ref ) {
        global $wpdb;
        if ( ! GE_WTP_Newsletter_Review::allowed() ) { return new WP_Error( 'denied', 'Acceso denegado.' ); }
        if ( ! is_array( $rows ) || count( $rows ) > 500 || ! in_array( $audience, array( 'first', 'second' ), true )
            || ! is_string( $authority_ref ) || ! preg_match( '/^[a-z0-9_-]{8,120}$/D', $authority_ref )
            || ! is_string( $batch_ref ) || ! preg_match( '/^[a-f0-9]{64}$/D', $batch_ref ) ) { return new WP_Error( 'input', 'Lote inválido.' ); }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['email'] ) || ! is_string( $row['email'] ) || ! is_email( $row['email'] )
                || strlen( $row['email'] ) > 190 || empty( $row['email_sha256'] )
                || ! hash_equals( $row['email_sha256'], hash( 'sha256', strtolower( trim( $row['email'] ) ) ) ) ) {
                return new WP_Error( 'email', 'El lote contiene una dirección inválida.' );
            }
        }
        if ( ! self::ensure_schema() ) { return new WP_Error( 'schema', 'No se pudo preparar la lista.' ); }
        $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', self::table() ) );
        if ( 'InnoDB' !== $engine ) { return new WP_Error( 'storage', 'La lista requiere un almacenamiento transaccional verificado.' ); }
        $lock = 'ge_newsletter_audiences_v1';
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'busy', 'Otra actualización está en curso.' ); }
        $counts = array( 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'suppressed' => 0, 'overlap_preserved' => 0 );
        try {
            $wpdb->query( 'START TRANSACTION' );
            foreach ( $rows as $row ) {
                $email = strtolower( trim( $row['email'] ) );
                $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email=%s FOR UPDATE', $email ) );
                if ( $existing && ( in_array( $existing->status, array( 'unsubscribed', 'bounced', 'paused', 'complained' ), true ) || ! empty( $existing->unsubscribed_at ) ) ) { ++$counts['suppressed']; continue; }
                if ( $existing && 'first' === $existing->audience && 'second' === $audience ) { ++$counts['overlap_preserved']; continue; }
                $meta = array( 'authority_ref' => $authority_ref, 'batch_ref' => $batch_ref, 'actor' => get_current_user_id(),
                    'reasons' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['selection_reasons'] ?? array() ) ) ) ),
                    'recipient_optin_claimed' => false );
                if ( $existing && $existing->audience === $audience ) {
                    $previous = json_decode( $existing->selection_meta ?? '', true );
                    if ( ( $previous['authority_ref'] ?? '' ) === $authority_ref && ( $previous['batch_ref'] ?? '' ) === $batch_ref ) { ++$counts['unchanged']; continue; }
                }
                $values = array( 'audience' => $audience, 'selection_meta' => wp_json_encode( $meta ), 'updated_at' => current_time( 'mysql' ) );
                if ( 'first' === $audience ) {
                    $values['status'] = 'subscribed'; $values['authorization_kind'] = 'owner_selected';
                    $values['authorization_at'] = current_time( 'mysql' );
                    if ( ! $existing ) { $values['source'] = 'owner_selection'; }
                } else {
                    $values['authorization_kind'] = 'prospect'; $values['authorization_at'] = null;
                    if ( ! $existing || 'subscribed' !== $existing->status ) { $values['status'] = 'prospect'; }
                    if ( ! $existing ) { $values['source'] = 'google_contacts_prospect'; }
                }
                if ( $existing ) {
                    if ( false === $wpdb->update( self::table(), $values, array( 'id' => $existing->id ) ) ) { throw new RuntimeException( 'Update failed' ); }
                    ++$counts['updated'];
                } else {
                    $values += array( 'email' => $email, 'token' => bin2hex( random_bytes( 32 ) ), 'created_at' => current_time( 'mysql' ), 'consent_at' => null );
                    if ( false === $wpdb->insert( self::table(), $values ) ) { throw new RuntimeException( 'Insert failed' ); }
                    ++$counts['inserted'];
                }
            }
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Commit failed' ); }
            $audit = (array) get_option( self::AUDIT, array() );
            $audit[] = array( 'actor' => get_current_user_id(), 'at' => gmdate( 'c' ), 'authority_ref' => $authority_ref,
                'batch_ref' => $batch_ref, 'audience' => $audience, 'counts' => $counts, 'sent' => 0 );
            update_option( self::AUDIT, array_slice( $audit, -200 ), false );
            return $counts;
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'database', 'No se pudo guardar este lote. Revisá la trazabilidad antes de repetir.' );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    /** Trusted staff/service call only; an inbound email alone is never suppression authority. */
    public static function suppress_delivery( $delivery_id, $kind, $reason, $evidence_ref ) {
        global $wpdb;
        if ( ! GE_WTP_Newsletter_Review::allowed() || ! in_array( $kind, array( 'hard_bounce', 'temporary_bounce', 'policy_bounce', 'review_bounce', 'complaint' ), true )
            || ! is_string( $reason ) || ! is_string( $evidence_ref ) || ! preg_match( '/^[a-zA-Z0-9:_-]{8,160}$/D', $evidence_ref ) ) { return new WP_Error( 'feedback', 'Evidencia de rebote inválida.' ); }
        $delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'ge_newsletter_deliveries WHERE id=%d', absint( $delivery_id ) ) );
        if ( ! $delivery || ! in_array( $delivery->status, array( 'sent', 'unknown', 'sending', 'bounced' ), true ) ) { return new WP_Error( 'delivery', 'No existe un intento de envío correlacionado.' ); }
        $lock = 'ge_newsletter_audiences_v1';
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'busy', 'Otra actualización está en curso.' ); }
        try {
            $wpdb->query('START TRANSACTION');
            $contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d FOR UPDATE', $delivery->contact_id ) );
            if ( ! $contact ) { return new WP_Error( 'contact', 'Contacto inexistente.' ); }
            $meta = (array) json_decode( $contact->selection_meta ?? '', true );
            $rank = array( 'review_bounce' => 0, 'temporary_bounce' => 1, 'policy_bounce' => 2, 'hard_bounce' => 3, 'complaint' => 4 );
            if ( ( $meta['suppression']['taxonomy_version'] ?? 0 ) === 2 && ( $rank[$meta['suppression']['kind'] ?? 'review_bounce'] ?? 0 ) > $rank[$kind] ) { $wpdb->query('ROLLBACK'); return true; }
            if ( ( $meta['suppression']['taxonomy_version'] ?? 0 ) === 2 && ( $meta['suppression']['kind'] ?? '' ) === $kind ) { $wpdb->query('ROLLBACK'); return true; }
            if ( ! empty( $meta['suppression'] ) ) { $meta['suppression_previous'] = $meta['suppression']; }
            $meta['suppression'] = array( 'taxonomy_version' => 2, 'delivery_id' => (int) $delivery->id, 'kind' => $kind, 'reason' => sanitize_text_field( $reason ),
                'evidence_ref' => $evidence_ref, 'at' => gmdate( 'c' ), 'actor' => get_current_user_id(), 'auto_retry' => false );
            // Never overwrite a voluntary unsubscribe with a transport failure.
            $status = 'unsubscribed' === $contact->status || $contact->unsubscribed_at ? 'unsubscribed' : ( in_array( $kind, array( 'hard_bounce', 'complaint' ), true ) ? 'bounced' : 'paused' );
            if ( false === $wpdb->update( self::table(), array( 'status' => $status, 'selection_meta' => wp_json_encode( $meta ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $contact->id ) ) ) { return new WP_Error( 'database', 'No se pudo suspender la dirección.' ); }
            if ( false === $wpdb->update( $wpdb->prefix . 'ge_newsletter_deliveries', array( 'status' => 'bounced', 'last_error' => substr( sanitize_text_field( $reason ), 0, 500 ) ), array( 'id' => $delivery->id ) ) ) { return new WP_Error( 'database', 'Dirección suspendida; falta conciliar el intento de envío.' ); }
            if(class_exists('GE_CRM'))GE_CRM::event(0,0,'newsletter_delivery_suppressed',array('campaign_id'=>(int)$delivery->campaign_id,'delivery_id'=>(int)$delivery->id,'kind'=>$kind,'evidence_ref'=>$evidence_ref,'notes'=>'Dirección suspendida por '.($kind==='temporary_bounce'?'fallo temporal':($kind==='complaint'?'reclamo':($kind==='policy_bounce'?'bloqueo del proveedor':($kind==='review_bounce'?'causa pendiente de revisión':'dirección inválida')))).'; sin reactivación automática.'));
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar la supresión.');
            return true;
        } catch(Throwable $e) { $wpdb->query('ROLLBACK');return new WP_Error('database','No se pudo registrar la supresión completa.'); }
        finally { $wpdb->query('ROLLBACK');$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function render_contacts() {
        global $wpdb;
        if ( ! GE_WTP_Newsletter_Review::allowed() || ! self::ensure_schema() ) { return; }
        $states = array( '' => 'Todos', 'subscribed' => 'Suscriptores', 'prospect' => 'Posibles suscriptores', 'unsubscribed' => 'Bajas voluntarias',
            'bounced' => 'Rebotes', 'paused' => 'Suspendidos' );
        $state = sanitize_key( wp_unslash( $_GET['contact_status'] ?? '' ) ); if ( ! isset( $states[ $state ] ) ) { $state = ''; }
        $audience = sanitize_key( wp_unslash( $_GET['audience'] ?? '' ) ); if ( ! in_array( $audience, array( '', 'first', 'second' ), true ) ) { $audience = ''; }
        $search = sanitize_text_field( wp_unslash( $_GET['contact_search'] ?? '' ) ); $page = max( 1, absint( $_GET['contact_page'] ?? 1 ) );
        $where = '1=1';
        if ( $state ) { $where .= $wpdb->prepare( ' AND status=%s', $state ); }
        if ( $audience ) { $where .= $wpdb->prepare( ' AND audience=%s', $audience ); }
        if ( $search ) { $where .= $wpdb->prepare( ' AND email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ); }
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where );
        $contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 50 OFFSET %d', ( $page - 1 ) * 50 ) );
        ?><section class="ge-admin-panel"><h2>Suscriptores y posibles suscriptores</h2>
        <p>Primera selección: contactos autorizados por vos. Segunda selección: posibles suscriptores. Las bajas y los rebotes conservan su historial.</p>
        <form method="get" class="ge-admin-form"><input type="hidden" name="section" value="communications"><input type="hidden" name="subsection" value="contacts">
            <label>Mostrar<select name="contact_status"><?php foreach ( $states as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <label>Selección<select name="audience"><option value="">Todas</option><option value="first" <?php selected( $audience, 'first' ); ?>>Primera campaña</option><option value="second" <?php selected( $audience, 'second' ); ?>>Segunda campaña</option></select></label>
            <label>Buscar email<input type="search" name="contact_search" value="<?php echo esc_attr( $search ); ?>"></label><button class="button" type="submit">Filtrar</button>
        </form><p><?php echo number_format_i18n( $total ); ?> contactos · Página <?php echo (int) $page; ?></p>
        <div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Email</th><th>Estado</th><th>Selección</th><th>Autorización registrada</th></tr></thead><tbody>
        <?php foreach ( $contacts as $contact ) : ?><tr><td><?php echo esc_html( $contact->email ); ?></td><td><?php echo esc_html( $states[ $contact->status ] ?? $contact->status ); ?></td><td><?php echo 'first' === $contact->audience ? 'Primera campaña' : ( 'second' === $contact->audience ? 'Segunda campaña' : 'Sin selección' ); ?></td><td><?php echo self::owner_authorized( $contact ) ? 'Selección del titular · ' . esc_html( mysql2date( 'd/m/Y', $contact->authorization_at ) ) : ( $contact->consent_at ? 'Aceptación registrada · ' . esc_html( mysql2date( 'd/m/Y', $contact->consent_at ) ) : 'Pendiente' ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'contact_page', $page - 1 ) ); ?>">Anterior</a><?php endif; ?>
        <?php if ( $page * 50 < $total ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'contact_page', $page + 1 ) ); ?>">Siguiente</a><?php endif; ?>
        </section><?php
    }
}
