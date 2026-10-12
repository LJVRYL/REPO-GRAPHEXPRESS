<?php
defined('ABSPATH') || exit;

/** Pure render fixtures: no handlers, delivery, credential generators or saved entities. */
final class GE_WTP_Email_Preview {
    public static function extra_catalog() {
        $rows=array();
        foreach(array('newsletter_campaign_v3'=>array('Tienda y portal · campaña v3','Novedades','Borrador versionado; envío bloqueado','Destinatarios revisados de la campaña'), 'crm_reply'=>array('Respuesta del CRM','Comunicaciones','Responder una conversación','Cliente'), 'attention_received_ack'=>array('Acuse de recepción','Comunicaciones','Mensaje entrante elegible','Cliente'), 'internal_customer_quote_review'=>array('Aceptación de presupuesto · aviso interno','Internos','Cliente acepta presupuesto · variante de internal_new_order','Personal'), 'internal_test'=>array('Prueba del centro de notificaciones','Internos','Prueba solicitada por administrador','Personal'), 'internal_production_digest'=>array('Resumen diario de producción','Internos','Horario configurado','Personal'), 'internal_new_candidate'=>array('Nueva postulación · aviso interno','Internos','Recepción de postulación','Personal'), 'internal_new_incident'=>array('Nuevo incidente','Internos','Incidente de producción','Personal'), 'internal_supplier_failure'=>array('Fallo de envío al proveedor','Internos','Fallo de correo al proveedor','Personal')) as $id=>$values) {
            $rows[$id]=array('id'=>$id,'title'=>$values[0],'group'=>$values[1],'trigger'=>$values[2],'recipient'=>$values[3],'editable'=>false,'source'=>'Constructor compartido de contenido · '.$id);
        }
        return $rows;
    }
    public static function supports($id) {
        if (strpos($id,'wordpress_')===0) return isset(GE_WTP_Email_Core::catalog()[$id]);
        if (strpos($id,'woocommerce_')===0) return GE_WTP_Email_WooCommerce::supports(substr($id,12));
        if (GE_WTP_Email_Content::preview($id)!==null) return true;
        return in_array($id,array('customer_welcome_verification','customer_email_verification','customer_portal_invite','new_customer_admin','order_customer','order_admin','order_manual_update','order_status_example','internal_new_customer','internal_new_order','review_request','newsletter_campaign','newsletter_test','newsletter_campaign_v3','job_application'),true);
    }
    public static function render($id) {
        $p=GE_WTP_Email_Branding::profile();
        $notice='Datos ficticios. Se usa el mismo constructor de contenido del envío; no se guarda ni se envía nada. Los enlaces del ejemplo no son operativos.';
        $attachment='Sin adjuntos generados en esta vista';
        if (strpos($id,'wordpress_')===0) {
            $core=GE_WTP_Email_Core::preview($id);
            if (is_wp_error($core)) return $core;
            return self::envelope($core['subject'],$core['body'],$core['notice'] ?? $core['note'] ?? 'Referencia de firma común. El contenido y los enlaces de seguridad los genera WordPress; esta vista no ejecuta ese flujo.', $attachment, 'Referencia de sistema');
        }
        if (strpos($id,'woocommerce_')===0) {
            $wc=GE_WTP_Email_WooCommerce::preview(substr($id,12));
            if (is_wp_error($wc)) return $wc;
            return self::envelope($wc['subject'],$wc['body'],$wc['note'],$wc['attachments_note'],'WooCommerce · constructor nativo');
        }
        $sample=GE_WTP_Email_Content::preview($id);
        if ($sample!==null) return self::envelope($sample['heading'],$sample['content'],$notice,$attachment,'Sistema · contenido protegido');
        $name='Cliente de ejemplo'; $url='https://example.invalid/enlace-de-ejemplo';
        switch($id) {
            case 'customer_welcome_verification': case 'customer_email_verification':
                $welcome=$id==='customer_welcome_verification';
                $subject=$welcome?'Tu cuenta está lista':'Verificá tu email';
                $content=GE_WTP_Notifications::basic_email($subject,GE_WTP_Notifications::verification_content($name,GE_WTP_Notifications::verification_intro($welcome),$url)); break;
            case 'customer_portal_invite':
                $subject='Tu acceso a '.$p['name']; $content=GE_WTP_Notifications::basic_email($subject,GE_WTP_Notifications::invitation_content($name,$url)); break;
            case 'new_customer_admin': case 'internal_new_customer':
                $subject='Nuevo cliente'; $user=(object)array('display_name'=>$name,'user_email'=>'cliente@example.invalid');
                $content=GE_WTP_Notifications::basic_email($subject,GE_WTP_Notifications::new_customer_content($user,$url)); break;
            case 'order_customer': case 'order_admin': case 'internal_new_order': case 'order_manual_update': case 'order_status_example':
                $subject=$id==='order_customer'?'Recibimos tu pedido':($id==='order_manual_update'?'Actualizamos tu presupuesto':($id==='order_status_example'?'Actualización de tu pedido':'Nuevo pedido en el portal'));
                $items='<tr><td style="padding:12px 0;border-bottom:1px solid #dddcdf"><strong>Tarjetas personales</strong><br>500 unidades · Pendiente de aprobación</td><td style="text-align:right">ARS 25.000</td></tr>';
                $details='<table width="100%" style="width:100%;background:#f9f8f5;margin-top:20px"><tr><td style="padding:12px">Entrega</td><td style="padding:12px;text-align:right">A coordinar</td></tr></table>';
                $content=GE_WTP_Notifications::order_content($subject,'Pedido de ejemplo <strong>#123</strong>. Los estados, productos y valores se reemplazan por los del pedido real.',$items,$details,'<p><a href="'.$url.'" style="display:inline-block">Ver pedido</a></p>','ARS 25.000');
                $notice.=' El resumen usa datos de muestra; variantes de estado conservan sus condiciones comerciales.'; break;
            case 'review_request':
                $subject='¿Cómo fue tu experiencia con '.$p['name'].'?'; $content=GE_WTP_Review_Requests::email_body($name,$url); break;
            case 'newsletter_campaign_v3':
                $subject=GE_WTP_Newsletter_Review::SUBJECT;
                $raw=file_get_contents(GE_WTP_PLUGIN_DIR.'templates/newsletter-v3-template.html');
                $meta=array('version'=>'example','template_sha256'=>hash('sha256',$raw),'statutory_notice'=>'');
                $content=GE_WTP_Newsletter_Review::render_content($subject,$raw,$meta);
                $notice.=' El aviso legal conserva su estado pendiente de revisión. Esta vista no selecciona destinatarios ni habilita envíos.'; break;
            case 'newsletter_campaign': case 'newsletter_test':
                $subject='Novedades para tus próximos trabajos';
                $campaign=(object)array('id'=>0,'subject'=>$subject,'preview_text'=>'Impresión para tu empresa','content'=>'<p>Este es un contenido de campaña de ejemplo. La campaña real conserva su edición.</p>');
                $content=GE_WTP_Newsletter::campaign_html($campaign,$id==='newsletter_campaign'?$url:'',$name); break;
            default: return new WP_Error('ge_email_preview_unavailable','Este sistema no ofrece un constructor puro de ejemplo. Su envío conserva la firma compartida.');
        }
        return self::envelope($subject,$content,$notice,$attachment,'Sistema · contenido protegido');
    }
    private static function envelope($subject,$body,$notice,$attachment,$revision) {
        $message=GE_WTP_Email_Branding::prepare($subject,$body);
        $p=GE_WTP_Email_Branding::profile();
        return array_merge($message,array('sender'=>array('name'=>apply_filters('wp_mail_from_name',$p['name']),'email'=>apply_filters('wp_mail_from',$p['email'])),'recipient'=>'cliente@example.invalid / personal de ejemplo','notice'=>$notice,'attachment'=>$attachment,'revision'=>$revision));
    }
}
