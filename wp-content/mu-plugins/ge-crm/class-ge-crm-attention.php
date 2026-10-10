<?php
defined( 'ABSPATH' ) || exit;

/** Bounded message data and current catalog rules; no mailbox or outbound transport. */
final class GE_CRM_Attention {
    const VERSION = 'attention-v2';

    public static function normalize( $raw ) {
        if ( ! is_array( $raw ) || ( $raw['organization_id'] ?? '' ) !== GE_CRM::org() ) { throw new RuntimeException( 'Organización inválida.', 422 ); }
        $channel = $raw['channel'] ?? '';
        if ( ! in_array( $channel, array( 'email', 'whatsapp' ), true ) ) { throw new RuntimeException( 'Canal inválido.', 422 ); }
        $out = array( 'organization_id' => GE_CRM::org(), 'channel' => $channel );
        foreach ( array( 'external_id', 'conversation_id', 'account_ref', 'source_ref', 'received_at', 'from', 'to', 'subject', 'body' ) as $key ) {
            if ( ! isset( $raw[$key] ) || ! is_string( $raw[$key] ) ) { throw new RuntimeException( 'Mensaje incompleto: ' . $key, 422 ); }
            $out[$key] = trim( $raw[$key] );
        }
        foreach ( array( 'external_id', 'conversation_id', 'account_ref', 'source_ref' ) as $key ) {
            if ( '' === $out[$key] || strlen( $out[$key] ) > 190 || preg_match( '/[\x00-\x1f]/', $out[$key] ) ) { throw new RuntimeException( 'Identificador inválido.', 422 ); }
        }
        if ( strlen( $out['body'] ) > 12000 || strlen( $out['subject'] ) > 180 || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $out['received_at'] ) || false === strtotime( $out['received_at'] ) || strtotime( $out['received_at'] ) > time() + 300 ) { throw new RuntimeException( 'Contenido o fecha inválidos; conservar original y revisar en transporte.', 422 ); }
        $channels = get_option( 'ge_crm_attention_channels', array() );
        $cfg = $channels[$channel] ?? array();
        if ( empty( $cfg['accounts'] ) || ! in_array( $out['account_ref'], $cfg['accounts'], true ) ) { throw new RuntimeException( 'Cuenta de transporte no registrada para Graphex.', 422 ); }
        if ( 'email' === $channel && ( ! is_email( $out['from'] ) || ! is_email( $out['to'] ) || ! in_array( strtolower( $out['to'] ), array_map( 'strtolower', $cfg['recipients'] ?? array() ), true ) ) ) { throw new RuntimeException( 'Destinatario o remitente inválido.', 422 ); }
        $out['sender_is_internal'] = 'email' === $channel && in_array( strtolower( $out['from'] ), array_map( 'strtolower', array_merge( $cfg['recipients'] ?? array(), $cfg['senders'] ?? array() ) ), true );
        $headers = is_array( $raw['headers'] ?? null ) ? array_change_key_case( $raw['headers'], CASE_LOWER ) : array();
        $out['headers'] = array();
        foreach ( array( 'auto-submitted', 'precedence', 'list-id', 'return-path', 'content-type', 'x-auto-response-suppress', 'message-id', 'references', 'in-reply-to' ) as $key ) { $out['headers'][$key] = substr( preg_replace( '/[\x00-\x1f]/', '', is_string( $headers[$key] ?? null ) ? $headers[$key] : '' ), 0, 250 ); }
        $out['attachment_refs'] = array();
        foreach ( (array) ( $raw['attachment_refs'] ?? array() ) as $ref ) {
            if ( ! is_string( $ref ) || strlen( $ref ) > 190 || preg_match( '/[\x00-\x1f]/', $ref ) ) { throw new RuntimeException( 'Referencia de adjunto inválida.', 422 ); }
            $out['attachment_refs'][] = $ref;
        }
        if ( count( $out['attachment_refs'] ) > 20 ) { throw new RuntimeException( 'Revisar adjuntos en transporte.', 422 ); }
        foreach ( array( 'historical_backfill', 'body_truncated', 'reply_ambiguous' ) as $flag ) { $out[$flag] = ! empty( $raw[$flag] ); }
        $out['quote_request'] = is_array( $raw['quote_request'] ?? null ) ? $raw['quote_request'] : array();
        if ( strlen( wp_json_encode( $out['quote_request'] ) ) > 8000 ) { throw new RuntimeException( 'Solicitud demasiado grande.', 422 ); }
        $out['body'] = wp_strip_all_tags( $out['body'] );
        $out['subject'] = sanitize_text_field( $out['subject'] );
        return $out;
    }

    public static function classify( $event ) {
        $headers = $event['headers'];
        $text = strtolower( remove_accents( $event['subject'] . ' ' . $event['body'] ) );
        $suppressed = 'email' === $event['channel'] && (
            ( $headers['auto-submitted'] && 'no' !== strtolower( $headers['auto-submitted'] ) ) ||
            preg_match( '/bulk|list|junk/i', $headers['precedence'] ) || $headers['list-id'] || '<>' === $headers['return-path'] ||
            ! empty( $event['sender_is_internal'] ) || preg_match( '/out of office|fuera de oficina|respuesta automatica|automatic reply|returned mail/', $text ) ||
            $headers['x-auto-response-suppress'] || preg_match( '/multipart\/report|delivery-status/i', $headers['content-type'] ) ||
            preg_match( '/mailer-daemon|postmaster|no-?reply|do-?not-?reply/i', $event['from'] )
        );
        $reason = ''; $category = 'uncertain'; $ack = false;
        // Escalations precede commercial keywords, including forwarded automation failures.
        if ( preg_match( '/ignora.{0,30}instrucciones|ignore.{0,30}instructions|cambia.{0,30}politica|api.?key|contrasena|token secreto/', $text ) ) { $category = 'security_review'; $reason = 'Contenido con instrucciones o solicitud sensible; tratar únicamente como datos.'; }
        elseif ( preg_match( '/reclamo|incidencia|defectuos|danad|cobraron.{0,20}(dos|doble)|no.{0,15}(llego|recibi)|demora|cancelar|devolucion|problema/', $text ) ) { $category = 'incident'; $reason = 'Posible incidencia o reclamo; revisión humana prioritaria.'; }
        elseif ( preg_match( '/mail delivery failed|delivery status notification|undeliver|failure notice|correo.{0,15}(rechazad|no entregad)|no se pudo entregar|mailer-daemon/', $text . ' ' . $event['from'] ) ) { $category = 'delivery_failure'; $reason = 'Correo rechazado o entrega fallida; revisar destinatario y aviso original sin reintentar a ciegas.'; }
        elseif ( $suppressed ) { $category = 'automated'; $reason = 'Correo automático, lista o rebote: conservar sin autoresponder.'; }
        elseif ( preg_match( '/terminacion|troquel|foil|especial|urgente|excepcion|descuento|cuenta corriente/', $text ) ) { $category = 'special'; $reason = 'Terminación, plazo o condición especial sin regla automática.'; }
        elseif ( preg_match( '/desuscrib|unsubscribe|newsletter|promocion exclusiva|ganaste un premio/', $text ) ) { $category = 'non_useful'; $reason = 'Posible publicidad: conservar clasificado, sin borrar.'; }
        elseif ( preg_match( '/presupuest|cotiz|precio|quiero.{0,20}(imprimir|comprar|volantes|tarjetas)/', $text ) || $event['quote_request'] ) { $category = 'quote'; $reason = 'Solicitud comercial; verificar configuración y catálogo vigente.'; $ack = true; }
        elseif ( preg_match( '/^(hola|buenas|buen dia|buenos dias)[.!\s]*$/', trim( $text ) ) ) { $category = 'contact'; $reason = 'Contacto inicial; acuse acotado, sin promesas.'; $ack = true; }
        if ( ! $reason ) { $reason = 'Intención incierta; conservar y elevar para revisión.'; }
        if ( $suppressed || ! empty( $event['historical_backfill'] ) || ! empty( $event['body_truncated'] ) || ! empty( $event['reply_ambiguous'] ) || 'email' !== $event['channel'] ) { $ack = false; }
        return array( 'category' => $category, 'reason' => $reason, 'ack_eligible' => $ack, 'policy_version' => self::VERSION );
    }

    public static function quote_lines( $request ) {
        $lines = $request['lines'] ?? array();
        if ( ! is_array( $lines ) || ! $lines || count( $lines ) > 30 ) { return new WP_Error( 'attention_configuration', 'Falta una configuración completa y comprobable.' ); }
        $out = array();
        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) || ! empty( $line['finishes'] ) || ! empty( $line['special'] ) || isset( $line['unit_net'] ) || isset( $line['discount'] ) ) { return new WP_Error( 'attention_special', 'Ítem especial o precio/condición aportados por el mensaje: revisar.' ); }
            $id = absint( $line['product_id'] ?? 0 ); $product = wc_get_product( $id );
            $quantity = (string) ( $line['quantity'] ?? '' );
            if ( ! $product || ! preg_match( '/^[1-9][0-9]{0,5}$/D', $quantity ) || (int) $quantity > 100000 ) { return new WP_Error( 'attention_product', 'Producto o cantidad no comprobables.' ); }
            $description = GE_WTP_Commercial_Quote_Catalog::describe( $product );
            $configuration = is_array( $line['configuration'] ?? null ) ? $line['configuration'] : array();
            // Missing inputs must not silently take calculator defaults.
            if ( 'digital' === $description['mode'] ) {
                foreach ( $description['fields'] as $field ) { if ( ! array_key_exists( $field['key'], $configuration ) || '' === (string) $configuration[$field['key']] ) { return new WP_Error( 'attention_configuration', 'Falta un dato de la configuración del producto.' ); } }
            }
            $priced = GE_WTP_Commercial_Quote_Catalog::price( $id, $configuration, (int) $quantity );
            if ( is_wp_error( $priced ) || ! empty( $priced['manual'] ) ) { return new WP_Error( 'attention_price', 'El catálogo requiere revisión o configuración adicional.' ); }
            $out[] = array( 'source_type' => 'catalog_product', 'product_id' => $id, 'quantity' => (int) $quantity, 'configuration' => $priced['configuration'], 'unit' => 'u' );
        }
        return $out;
    }

    public static function label( $record ) {
        $categories = array( 'quote' => 'Presupuesto', 'contact' => 'Contacto', 'incident' => 'Incidencia', 'delivery_failure' => 'Correo rechazado', 'special' => 'Trabajo especial', 'security_review' => 'Revisión de seguridad', 'automated' => 'Automático', 'non_useful' => 'Sin utilidad comercial', 'uncertain' => 'Necesita aclaración' );
        $states = array( 'queued' => 'Pendiente', 'processing' => 'En proceso', 'review' => 'Requiere revisión', 'ignored' => 'Clasificado, sin acción', 'prepared' => 'Presupuesto en borrador', 'failed' => 'Fallo: revisar' );
        return ( $categories[$record['attention_classification']['category'] ?? ''] ?? 'Mensaje' ) . ' · ' . ( $states[$record['attention_state']] ?? 'Revisar estado' );
    }

    public static function render_message( $record ) {
        $event = $record['attention_event'];
        echo '<section class="ge-crm-panel"><h2>Mensaje recibido</h2><p>' . esc_html( self::label( $record ) ) . '</p><p>' . esc_html( $record['notes'] ?? '' ) . '</p><p>Canal: ' . esc_html( $event['channel'] ) . ' · Recibido: ' . esc_html( $event['received_at'] ) . '</p><details><summary>Ver texto y referencias del mensaje</summary><p>' . nl2br( esc_html( $event['body'] ) ) . '</p><p>Original: ' . esc_html( $event['source_ref'] ) . '</p>';
        foreach ( $event['attachment_refs'] as $ref ) { echo '<p>Adjunto: ' . esc_html( $ref ) . '</p>'; }
        $ack_state = $record['attention_ack']['state'] ?? '';
        $ack_labels = array( 'shared_dispatch' => 'Aviso compartido por esta conversación; consultar su registro', 'prepared' => 'Preparada; todavía sin enviar', 'sending' => 'Envío en curso; pendiente de confirmar', 'sent' => 'Aceptada por el transporte de correo; entrega no confirmada', 'simulated' => 'Simulada en pruebas', 'failed' => 'Falló el envío; requiere revisión', 'unknown' => 'Resultado incierto; requiere revisión', 'suppressed' => 'Sin respuesta automática' );
        echo '</details><p>Respuesta rutinaria: ' . esc_html( $ack_labels[$ack_state] ?? 'Sin preparar' ) . '.</p>';
        if ( ! empty( $record['attention_ack']['dispatch_id'] ) ) { echo '<p><a href="' . esc_url( GE_CRM_UI::url( 'tasks', array( 'record_id' => $record['attention_ack']['dispatch_id'] ) ) ) . '">Ver registro del aviso →</a></p>'; }
        echo '</section>';
    }

    public static function render_status() {
        $channels = get_option( 'ge_crm_attention_channels', array() );
        echo '<section class="ge-crm-panel"><h2>Atención por canal</h2>';
        foreach ( array( 'email' => 'Correo', 'whatsapp' => 'WhatsApp' ) as $channel => $label ) {
            $cfg = $channels[$channel] ?? array();
            $health = $cfg['consumer_health'] ?? array();
            $current = ! empty( $health['checked_at'] ) && strtotime( $health['checked_at'] ) > time() - 300;
            $state = 'Integración preparada; transporte pendiente de verificar.';
            if ( ! empty( $cfg['transport_verified_at'] ) ) {
                $state = $current && ! empty( $health['receiver_running'] ) && empty( $health['poll_failed'] ) ? 'Recepción conectada al panel.' : 'Recepción sin señal reciente o con error; revisar conexión.';
            }
            echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $state ) . '</p>';
            if ( 'email' === $channel && $health ) { echo '<p>Último control: ' . esc_html( $health['checked_at'] ) . ' · Pendientes de importar: ' . (int) $health['pending_events'] . '. El receptor depende de que esta computadora esté encendida y conectada.</p>'; }
        }
        echo '<p>Los mensajes, adjuntos y sugerencias no cambian las reglas del negocio. Las incidencias y condiciones especiales requieren revisión.</p></section>';
    }
}
