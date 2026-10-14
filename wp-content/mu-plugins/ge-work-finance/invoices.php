<?php
defined('ABSPATH') || exit;
/** Send the exact immutable issued document through the site's traced mail service. */
final class GE_Work_Invoice_Mail {
    const META='_ge_work_invoice_notices';
    public static function recipient($order) {$user=get_userdata($order->get_customer_id());return sanitize_email($user?$user->user_email:$order->get_billing_email());}
    public static function has_portal($order) {$user=get_userdata($order->get_customer_id());return $user && GE_WTP_Portal::is_customer_user($user);}
    public static function document($order,$id) {
        foreach(GE_WTP_Documents::issued_documents($order->get_id(),true) as $doc)if(($doc['id']??'')===$id && empty($doc['superseded_at']) && GE_WTP_Documents::customer_visible($doc) && ($doc['mime']??'')==='application/pdf')return $doc;
        return new WP_Error('document','Elegí una factura/documento vigente del mismo pedido.');
    }
    public static function scope($doc) {
        $identity=array();foreach(array('id','version_id','category','name','mime','stored_name','provider','relative_path','object_key','size','sha256','document_number','issue_date','issuer_snapshot','billing_profile_snapshot','superseded_at') as $key)$identity[$key]=$doc[$key]??null;
        return hash('sha256',wp_json_encode($identity));
    }
    private static function attachment($doc) {
        $temporary=false;$provider=$doc['provider']??'local';
        if($provider==='vps')$path=GE_WTP_VPS_Storage::download_path($doc['relative_path']??'');
        elseif($provider==='r2') {
            $url=GE_WTP_R2_Storage::download_url($doc['object_key']??'',$doc['name']??'factura.pdf','application/pdf',300);
            if(is_wp_error($url))return $url;
            $response=wp_remote_get($url,array('timeout'=>20,'limit_response_size'=>20*MB_IN_BYTES+1));
            if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)return new WP_Error('storage','No se pudo recuperar el PDF privado; no se envió la factura.');
            $body=wp_remote_retrieve_body($response);if(strlen($body)>20*MB_IN_BYTES || strpos($body,'%PDF-')!==0)return new WP_Error('pdf','El archivo debe ser un PDF de hasta 20 MB.');
            $path=wp_tempnam('ge-invoice.pdf');if(!$path || file_put_contents($path,$body)!==strlen($body))return new WP_Error('storage','No se pudo preparar el adjunto.');$temporary=true;
        } else {
            $base=realpath(GE_WTP_Documents::private_directory());$path=realpath(trailingslashit(GE_WTP_Documents::private_directory()).wp_basename($doc['stored_name']??''));
            if(!$base || !$path || strpos($path,$base.DIRECTORY_SEPARATOR)!==0)return new WP_Error('storage','Archivo privado no disponible.');
        }
        if(is_wp_error($path))return $path;
        if(!$path || !is_file($path) || filesize($path)>20*MB_IN_BYTES || file_get_contents($path,false,null,0,5)!=='%PDF-') {if($temporary && is_file($path))unlink($path);return new WP_Error('pdf','El PDF no está disponible o supera 20 MB.');}
        $sha=hash_file('sha256',$path);
        if(!empty($doc['sha256']) && !hash_equals($doc['sha256'],$sha)){if($temporary)unlink($path);return new WP_Error('version','El archivo cambió. Revisá su versión antes de enviarlo.');}
        return array('path'=>$path,'name'=>sanitize_file_name($doc['name']??'factura.pdf'),'sha256'=>$sha,'temporary'=>$temporary);
    }
    public static function send($id,$doc_id,$input,$actor) {
        if(!GE_Work_Finance::can_write($actor))return new WP_Error('forbidden','Sólo Administración/Finanzas puede enviar una factura.');
        $order=GE_Work_Finance::order($id);if(is_wp_error($order))return $order;
        $lock='ge_work_invoice_'.$id.'_'.$doc_id;if(!GE_Work_Finance::lock($lock))return new WP_Error('busy','El envío se está procesando; no lo repitas.');
        $attachment=null;$started=false;
        try {
            clean_post_cache($id);
            $order=GE_Work_Finance::order($id);$doc=self::document($order,$doc_id);if(is_wp_error($doc))return $doc;
            $order=GE_Work_Finance::strict_order($id);
            $to=self::recipient($order);$with_pdf=!empty($input['attach_pdf']);
            if(!is_email($to) || !hash_equals(hash('sha256',$to),(string)($input['recipient_scope']??'')))return new WP_Error('recipient','El destinatario cambió. Recargá y revisá el cliente antes de enviar.');
            if(!hash_equals(self::scope($doc),(string)($input['document_scope']??'')))return new WP_Error('version','La factura cambió. Recargá y revisá el archivo exacto antes de enviar.');
            if(!$with_pdf && !self::has_portal($order))return new WP_Error('customer','Este pedido no tiene cuenta de cliente para acceder al portal. Adjuntá el PDF o vinculá la ficha.');
            $notices=$order->get_meta(self::META,true);$notices=is_array($notices)?$notices:array();$previous=$notices[$doc_id]??array();
            if(in_array($previous['status']??'',array('accepted','simulated'),true))return array('accepted'=>true,'duplicate'=>true,'notice'=>$previous);
            if(in_array($previous['status']??'',array('attempting','unknown'),true))return new WP_Error('unknown','El resultado anterior es incierto. Revisá Notificaciones antes de volver a enviar.');
            if(($previous['status']??'')==='failed' && empty($input['retry_verified']))return new WP_Error('retry','Confirmá que revisaste Notificaciones y que el envío anterior falló.');
            if($with_pdf){$attachment=self::attachment($doc);if(is_wp_error($attachment))return $attachment;}
            $notice=array('status'=>'attempting','actor'=>$actor,'at'=>current_time('mysql',true),'to'=>$to,'document_scope'=>self::scope($doc),'pdf_sha256'=>$attachment?$attachment['sha256']:null,'attachment'=>$with_pdf);
            $notices[$doc_id]=$notice;$order->update_meta_data(self::META,$notices);$order->save();
            $reference='order:'.$order->get_id().':'.$doc_id;$url=self::has_portal($order)?GE_WTP_Customer_Invoices::url($reference):'';
            $body='<h2>Factura / documento del pedido #'.esc_html($order->get_order_number()).'</h2><p>'.esc_html((($doc['document_number']??'')?:$doc['name'])).'</p>'.($with_pdf?'<p>Adjuntamos el PDF emitido de esta versión.</p>':'').($url?'<p><a href="'.esc_url($url).'">Ver documento en el portal</a></p>':'');
            $started=true;$ok=GE_WTP_Notifications::send($to,'Factura del pedido #'.$order->get_order_number(),$body,'work_invoice_sent',$order->get_id(),array(),$attachment?array($attachment['name']=>$attachment['path']):array());
            $notice['status']=$ok?(wp_get_environment_type()==='local'?'simulated':'accepted'):'failed';$notices[$doc_id]=$notice;$order->update_meta_data(self::META,$notices);$order->save();
            $order->add_order_note('Envío de documento #'.$doc_id.' por usuario #'.$actor.': '.$notice['status'].'. Destinatario: '.$to.'.'.($attachment?' SHA-256 del PDF: '.$attachment['sha256']:''));
            return $ok?array('accepted'=>true,'duplicate'=>false,'notice'=>$notice):new WP_Error('mail','El servicio de correo no confirmó el envío. Revisá Notificaciones.');
        } catch(Throwable $error) {
            if($started){try{$notices[$doc_id]['status']='unknown';$order->update_meta_data(self::META,$notices);$order->save();}catch(Throwable $ignored){/* Persisted attempting marker blocks automatic duplicates. */}}
            return new WP_Error('mail','No se confirmó el envío. Revisá Notificaciones antes de repetirlo.');
        } finally {if(is_array($attachment) && !empty($attachment['temporary']) && is_file($attachment['path']))unlink($attachment['path']);GE_Work_Finance::unlock($lock);}
    }
    public static function render($order) {
        if(!GE_Work_Finance::can_write(get_current_user_id()))return;
        $to=self::recipient($order);$notices=(array)$order->get_meta(self::META,true);
        foreach(GE_WTP_Documents::issued_documents($order->get_id()) as $doc){
            if(($doc['mime']??'')!=='application/pdf' || !GE_WTP_Documents::customer_visible($doc))continue;
            $notice=$notices[$doc['id']]??array();$done=in_array($notice['status']??'',array('accepted','simulated'),true);
            echo '<div class="ge-work-invoice-send"><h3>Enviar '.esc_html((($doc['document_number']??'')?:$doc['name'])).'</h3><p>Destinatario: <strong>'.esc_html($to?:'Falta email del cliente').'</strong></p>';
            if($done)echo '<p>El aviso de esta versión ya fue aceptado por el sistema de correo. Consultá Notificaciones para su trazabilidad.</p>';
            elseif(in_array($notice['status']??'',array('attempting','unknown'),true))echo '<p>Resultado pendiente de verificar. Revisá Notificaciones antes de repetir.</p>';
            elseif(is_email($to)){
                echo '<form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_work_invoice_send"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="document_id" value="'.esc_attr($doc['id']).'"><input type="hidden" name="document_scope" value="'.esc_attr(self::scope($doc)).'"><input type="hidden" name="recipient_scope" value="'.esc_attr(hash('sha256',$to)).'">';wp_nonce_field('ge_work_invoice_send_'.$order->get_id().'_'.$doc['id']);
                echo '<label><input type="checkbox" name="attach_pdf" value="1" checked'.(!self::has_portal($order)?' required':'').'> Adjuntar el PDF exacto'.(self::has_portal($order)?', además del acceso al portal':' (este cliente aún no tiene acceso al portal)').'</label>';
                if(($notice['status']??'')==='failed')echo '<label><input type="checkbox" name="retry_verified" value="1" required> Revisé Notificaciones: el envío anterior falló y corresponde reintentar.</label>';
                echo '<button class="ge-staff-button" type="submit">Enviar factura / documento al cliente</button></form>';
            }echo '</div>';
        }
    }
    public static function handle() {
        $id=absint($_POST['order_id']??0);$doc=sanitize_text_field(wp_unslash($_POST['document_id']??''));check_admin_referer('ge_work_invoice_send_'.$id.'_'.$doc);
        $result=self::send($id,$doc,wp_unslash($_POST),get_current_user_id());if(is_wp_error($result))set_transient('ge_work_finance_error_'.get_current_user_id().'_'.$id,$result->get_error_message(),120);
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$id,'work_invoice'=>is_wp_error($result)?'error':'accepted')).'#ge-work-invoices');exit;
    }
}
add_action('admin_post_ge_work_invoice_send',array('GE_Work_Invoice_Mail','handle'));
