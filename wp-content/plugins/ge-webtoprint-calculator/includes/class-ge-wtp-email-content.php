<?php
defined( 'ABSPATH' ) || exit;

/** Shared bodies for real sends and inert previews. Values are escaped by callers.
 * HTML values are source-owned fragments (price, registration, supplier rows),
 * never arbitrary template edits. This class does not read orders or generate URLs.
 */
final class GE_WTP_Email_Content {
    private static function brand() {
        return GE_WTP_Email_Branding::profile()['name'];
    }
    public static function render( $context, array $v ) {
        if ( 'invoice_review_response' === $context ) { $context = 'invoice_review_received'; }
        switch ( $context ) {
            case 'internal_new_incident': return '<p>' . $v['notes'] . '</p><p><a href="' . $v['url'] . '">Abrir incidencias</a></p>';
            case 'internal_supplier_failure': return '<p>Falló el envío de la orden <strong>' . $v['reference'] . '</strong> a ' . $v['supplier'] . '.</p><p><a href="' . $v['url'] . '">Revisar orden</a></p>';
            case 'internal_customer_quote_review': return '<p>El cliente aceptó el presupuesto y cargó los archivos. Revisá el precio, la versión y el arte antes de aprobar producción.</p><p><a href="' . $v['url'] . '">Abrir presupuesto</a></p>';
            case 'internal_test': return '<p>El centro de notificaciones está conectado correctamente.</p><p>Fecha: ' . $v['date'] . '</p>';
            case 'internal_production_digest': return '<h2>Resumen de producción</h2><ul><li>Trabajos activos: <strong>' . $v['active'] . '</strong></li><li>Vencen hoy: <strong>' . $v['due'] . '</strong></li><li>Demorados: <strong>' . $v['delayed'] . '</strong></li><li>Proveedor pendiente: <strong>' . $v['pending_supplier'] . '</strong></li></ul><p><a href="' . $v['url'] . '">Abrir Producción</a></p>';
            case 'job_application': return '<p>Se recibió una nueva postulación para <strong>' . $v['area'] . '</strong>.</p><p><strong>' . $v['name'] . '</strong> · ' . $v['email'] . '</p><p><a href="' . $v['url'] . '">Revisar candidatos</a></p>';
            case 'attention_received_ack': return '<p>Recibimos tu mensaje en ' . esc_html( self::brand() ) . '. Lo revisaremos para continuar con tu consulta.</p>';
            case 'crm_reply': return '<p>'.$v['text'].'</p>';
            case 'internal_new_candidate': return self::render( 'job_application', $v );
            case 'workflow_supplier_portal':
                $html = nl2br( esc_html( self::supplier_message( $v['row'], $v['url'], $v['profile'] ) ) );
                return str_replace( esc_html( $v['url'] ), '<a href="' . esc_url( $v['url'] ) . '">Abrir portal y descargar archivos</a>', $html );
            case 'workflow_artwork_approval': return '<p>Preparamos el archivo de <strong>' . $v['item_name'] . '</strong>, versión <strong>' . $v['version'] . '</strong>.</p><p><a href="' . $v['portal'] . '">Revisar y aprobar la versión final en tu portal</a></p>' . $v['registration'] . '<p>Si necesitás cambios, respondé este mensaje antes de aprobar. No enviaremos este trabajo a producción sin tu confirmación.</p>';
            case 'workflow_customer_production': return '<p>Tu pedido <strong>' . $v['reference'] . '</strong> fue aprobado y pasó a producción.</p><p>Podés consultar su avance desde <a href="' . $v['portal'] . '">tu portal de cliente</a>.</p>';
            case 'payment_instructions': return '<p>Hola ' . $v['name'] . ',</p><p>Tu pedido ya tiene disponibles las opciones de pago.</p><p><strong>Transferencia bancaria:</strong> ' . $v['base'] . '<br>CBU: ' . $v['cbu'] . '<br>Alias: ' . $v['alias'] . '</p><p><strong>Mercado Pago:</strong> ' . $v['mp_total'] . ' (incluye recargo del ' . $v['surcharge'] . '%).</p><p style="margin:24px 0"><a href="' . $v['portal'] . '" style="display:inline-block;padding:13px 18px;border-radius:9px;background:#6d45ef;color:#fff;text-decoration:none;font-weight:700">Ver pedido y pagar</a></p><p>Si transferís, cargá el comprobante dentro del mismo pedido.</p>';
            case 'payment_confirmed': return '<p>Hola ' . $v['name'] . ',</p><p>Confirmamos la acreditación del pago correspondiente al pedido <strong>' . $v['reference'] . '</strong>.</p><p><strong>Total acreditado:</strong> ' . $v['total'] . '</p><p><a href="' . $v['portal'] . '">Consultar el pedido</a></p>';
            case 'manual_customer_invite': return '<p>Hola ' . $v['name'] . ',</p><p>Podés crear tu cuenta de ' . esc_html( self::brand() ) . ' para consultar pedidos y repetir trabajos más rápido.</p><p><a href="' . $v['url'] . '">Crear mi cuenta</a></p><p>Registrate con el mismo email utilizado en tu pedido.</p>';
            case 'invoice_review_received': return '<p>' . $v['subject'] . '.</p><p><a href="' . $v['url'] . '">Ver comprobante y conversación</a></p><p>Pedir una revisión no anula el comprobante ni cambia el estado de pago.</p>';
            case 'invoice_review_staff': return '<p>Hay una consulta para revisar.</p><p><a href="' . $v['url'] . '">Abrir revisión en Gestión</a></p>';
            case 'invoice_available': return '<p>Tenés un comprobante disponible en tu portal.</p><p><a href="' . $v['url'] . '">Ver en Mis facturas</a></p>';
            case 'quote_request_internal': return '<p>Solicitud #'.$v['id'].' · '.$v['count'].' ítems</p><p><a href="'.$v['url'].'">Ver solicitud en Gestión</a></p>';
            case 'quote_request_received': return '<p>Recibimos tu solicitud con '.$v['count'].' ítems. Nuestro equipo la revisará.</p><p><a href="'.$v['url'].'">Mis solicitudes</a></p>';
            case 'supplier_order': return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#17152a"><div style="max-width:680px;margin:auto"><div style="padding:18px 22px;background:#17152a;color:#fff"><strong>' . esc_html( strtoupper( self::brand() ) ) . ' · ORDEN A PROVEEDOR</strong></div><div style="padding:25px;border:1px solid #ddd"><h1>' . $v['reference'] . '</h1><p>Solicitamos producir los siguientes trabajos para la fecha <strong>' . $v['date'] . '</strong>.</p><table style="width:100%;border-collapse:collapse"><tr><th style="padding:10px;text-align:left;background:#eee">Producto</th><th style="padding:10px;text-align:right;background:#eee">Cantidad</th></tr>' . $v['rows'] . '</table><h3>Indicaciones</h3><p style="white-space:pre-wrap">' . $v['notes'] . '</p><p style="color:#777;font-size:12px">Confirmar recepción y fecha posible de entrega respondiendo este correo.</p></div></div></body></html>';
        }
        return null;
    }
    public static function supplier_message( $row, $url, array $profile ) {
        $s = $row['snapshot'];
        $text = 'Hola ' . $profile['name'] . ", te envío este trabajo para producir.\n\nOrden: " . $s['reference'] . "\n";
        foreach ( $s['items'] as $item ) {
            $text .= "Trabajo: " . $item['name'] . "\nCantidad: " . $item['quantity'] . "\n";
            foreach ( $item['specs'] as $key => $value ) { $text .= $key . ': ' . $value . "\n"; }
            $text .= 'Terminación: ' . ( implode( ', ', $item['finishes'] ) ?: 'Sin terminación adicional' ) . "\n";
        }
        $text .= 'Fecha requerida: ' . ( $s['required_date'] ?: 'A coordinar' ) . "\nNotas técnicas: " . ( $s['notes'] ?: 'Sin observaciones adicionales' ) . "\n";
        foreach ( $s['files'] as $file ) { $text .= 'Archivo: ' . $file['record']['name'] . "\n"; }
        return $text . "\nPortal y descarga segura: " . $url . "\n\nPor favor confirmame recepción y, si podés, fecha estimada de entrega.\n\nGracias, " . self::brand() . ".\nContacto: " . $s['contact'];
    }
    /** No sends, credentials, attachment generation, database writes or real URLs. */
    public static function preview( $context ) {
        $fixtures = array('workflow_artwork_approval' => array('version' => '2', 'item_name' => 'Tarjetas personales', 'portal' => 'https://example.invalid/portal/pedidos', 'registration' => '<p>Si todavía no tenés cuenta, <a href="https://example.invalid/registro">registrate con este mismo email</a> para ver el pedido.</p>'), 'workflow_customer_production' => array('reference' => 'PED-000123', 'portal' => 'https://example.invalid/portal/pedidos'), 'payment_instructions' => array('mp_total' => '$ 27.500,00', 'base' => '$ 25.000,00', 'name' => 'María', 'alias' => 'ALIAS.DE.EJEMPLO', 'cbu' => 'CBU de ejemplo', 'portal' => 'https://example.invalid/portal/pedidos', 'surcharge' => '10'), 'payment_confirmed' => array('total' => '$ 25.000,00', 'name' => 'María', 'reference' => 'PED-000123', 'portal' => 'https://example.invalid/portal/pedidos'), 'manual_customer_invite' => array('name' => 'María', 'url' => 'https://example.invalid/registro'), 'invoice_review_received' => array('url' => 'https://example.invalid/portal/facturas', 'subject' => 'Recibimos tu consulta sobre un comprobante'), 'invoice_review_staff' => array('url' => 'https://example.invalid/gestion/facturas'), 'invoice_available' => array('url' => 'https://example.invalid/portal/facturas'), 'quote_request_internal' => array('count' => '2', 'url' => 'https://example.invalid/gestion/solicitudes', 'id' => '123'), 'quote_request_received' => array('url' => 'https://example.invalid/portal/solicitudes', 'count' => '2'), 'supplier_order' => array('date' => '20/10/2026', 'notes' => 'Corte final y entrega a coordinar.', 'reference' => 'PED-000123', 'rows' => '<tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Tarjetas personales</strong><br>Papel ilustración 300 g</td><td style="padding:10px;border-bottom:1px solid #ddd;text-align:right">500</td></tr>'));
        $headings = array('workflow_artwork_approval' => 'Aprobación final del archivo', 'workflow_customer_production' => 'Pedido en producción', 'payment_instructions' => 'Opciones de pago', 'payment_confirmed' => 'Pago confirmado', 'manual_customer_invite' => 'Guardá tus pedidos', 'invoice_review_received' => 'Recibimos tu consulta sobre un comprobante', 'invoice_review_staff' => 'Solicitud de revisión de comprobante', 'invoice_available' => 'Comprobante disponible', 'quote_request_internal' => 'Nueva solicitud de presupuesto', 'quote_request_received' => 'Recibimos tu solicitud de presupuesto', 'supplier_order' => 'Orden de producción para proveedor');
        $fixtures['internal_test'] = array( 'date' => '12/10/2026 10:30' );
        $fixtures['internal_production_digest'] = array( 'pending_supplier' => '2', 'delayed' => '1', 'active' => '12', 'url' => 'https://example.invalid/gestion/produccion', 'due' => '3' );
        $fixtures['job_application'] = array( 'url' => 'https://example.invalid/gestion/candidatos', 'email' => 'persona@example.invalid', 'area' => 'Diseño gráfico', 'name' => 'Persona de ejemplo' );
        $fixtures['attention_received_ack'] = array(  );
        $fixtures['crm_reply'] = array( 'text' => 'Hola, recibimos tu consulta.<br />Te responderemos con los próximos pasos.' );
        $fixtures['internal_new_candidate'] = $fixtures['job_application'];
        $fixtures['workflow_supplier_portal'] = array( 'url' => 'https://example.invalid/proveedor', 'profile' => array( 'name' => 'Proveedor de ejemplo' ), 'row' => array( 'snapshot' => array( 'reference' => 'PED-000123', 'items' => array( array( 'name' => 'Tarjetas personales', 'quantity' => 500, 'specs' => array( 'Papel' => 'Ilustración 300 g' ), 'finishes' => array( 'Corte final' ) ) ), 'required_date' => '20/10/2026', 'notes' => 'Entrega a coordinar.', 'files' => array( array( 'record' => array( 'name' => 'arte-final.pdf' ) ) ), 'contact' => 'operaciones@example.invalid' ) ) );
        $headings['internal_test'] = 'Prueba de notificaciones';
        $headings['internal_production_digest'] = 'Resumen diario de producción';
        $headings['job_application'] = 'Nueva postulación';
        $headings['attention_received_ack'] = 'Mensaje recibido';
        $headings['crm_reply'] = 'Respuesta a tu consulta';
        $headings['internal_new_candidate'] = 'Nueva postulación';
        $headings['workflow_supplier_portal'] = 'Orden de producción para proveedor';
        $fixtures['internal_new_incident'] = array( 'url' => 'https://example.invalid/gestion/incidencias', 'notes' => 'Se requiere revisar una máquina antes de continuar.' );
        $fixtures['internal_supplier_failure'] = array( 'url' => 'https://example.invalid/gestion/produccion', 'supplier' => 'Proveedor de ejemplo', 'reference' => 'PED-000123' );
        $fixtures['internal_customer_quote_review'] = array( 'url' => 'https://example.invalid/gestion/presupuestos' );
        $headings['internal_new_incident'] = 'Nueva incidencia';
        $headings['internal_supplier_failure'] = 'No se pudo enviar al proveedor';
        $headings['internal_customer_quote_review'] = 'Presupuesto y archivos para revisar';
        if ( 'invoice_review_response' === $context ) {
            $fixtures[$context] = $fixtures['invoice_review_received'];
            $fixtures[$context]['subject'] = 'Hay una respuesta sobre tu comprobante';
            $headings[$context] = 'Hay una respuesta sobre tu comprobante';
        }
        if ( ! isset( $fixtures[$context] ) ) { return null; }
        return array( 'heading' => $headings[$context], 'content' => self::render( $context, $fixtures[$context] ) );
    }
}
