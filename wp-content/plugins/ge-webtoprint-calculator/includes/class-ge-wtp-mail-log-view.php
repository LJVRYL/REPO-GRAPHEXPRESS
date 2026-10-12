<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Mail_Log_View {
    public static function group( $context ) { return '' === $context ? 'other' : ( 0 === strpos( $context, 'newsletter_' ) ? 'campaigns' : 'operational' ); }
    public static function label( $context ) {
        $labels = array( 'internal_production_digest'=>'Resumen de producción', 'commercial_quote_sent'=>'Presupuesto enviado',
            'order_manual_update'=>'Actualización de presupuesto/pedido', 'order_customer'=>'Pedido recibido: cliente', 'order_admin'=>'Pedido recibido: administración',
            'internal_new_order'=>'Nuevo pedido: aviso interno', 'internal_new_customer'=>'Nuevo cliente: aviso interno',
            'customer_portal_invite'=>'Invitación al portal', 'customer_portal_order_invite'=>'Invitación al portal de un pedido',
            'customer_portal_quote_invite'=>'Invitación al portal de un presupuesto', 'confirmed_order_portal_invitation'=>'Invitación de pedido confirmado',
            'customer_welcome_verification'=>'Bienvenida y verificación', 'customer_email_verification'=>'Verificación de email',
            'woocommerce_customer_reset_password'=>'Restablecer contraseña', 'woocommerce_customer_new_account'=>'Cuenta creada',
            'woocommerce_customer_on_hold_order'=>'Pedido pendiente de pago', 'woocommerce_new_order'=>'Nuevo pedido de la tienda',
            'customer_order_ready'=>'Pedido listo', 'payment_instructions'=>'Instrucciones de pago', 'customer_quote_created'=>'Presupuesto creado',
            'quote_request_internal'=>'Solicitud de presupuesto: aviso interno', 'quote_request_received'=>'Solicitud de presupuesto recibida',
            'external_gmail_quote'=>'Presupuesto por correo', 'internal_whatsapp_reminder'=>'Recordatorio de WhatsApp',
            'internal_new_candidate'=>'Nuevo contacto: aviso interno', 'internal_test'=>'Prueba de avisos internos', 'production_mail_test'=>'Prueba de correo de producción',
            'newsletter_campaign'=>'Campaña de newsletter', 'newsletter_test'=>'Prueba de newsletter' );
        if ( isset( $labels[$context] ) ) { return $labels[$context]; }
        if ( 0 === strpos( $context, 'newsletter_' ) ) { return false !== strpos( $context, 'preview' ) || false !== strpos( $context, 'test' ) ? 'Prueba de newsletter' : 'Campaña de newsletter'; }
        if ( 0 === strpos( $context, 'order_status_' ) ) { return 'Estado del pedido: ' . str_replace( array('ge-confirmado','ge-produccion','ge-listo','completed','on-hold','cancelled'), array('confirmado','en producción','listo','completado','pendiente','cancelado'), substr($context,13) ); }
        return $context ? ucfirst( str_replace( array('_','-'), ' ', $context ) ) : 'Sin tipo registrado';
    }
    public static function contexts() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='ge_email_log' AND p.post_status='private' AND m.meta_key='_ge_email_context' AND m.meta_value<>'' ORDER BY m.meta_value" );
    }
    public static function filters( $input ) {
        $get = function( $key ) use ( $input ) { return isset($input[$key]) && is_scalar($input[$key]) ? wp_unslash((string)$input[$key]) : ''; };
        $group = sanitize_key( $get('mail_group') ); if ( ! in_array($group,array('operational','campaigns','other'),true) ) { $group=''; }
        $type = sanitize_key($get('mail_type')); if ( ! in_array($type,self::contexts(),true) || ($group && self::group($type)!==$group) ) { $type=''; }
        $result = sanitize_key($get('mail_result')); if ( ! in_array($result,array('sent','failed','simulated','unknown'),true) ) { $result=''; }
        $dates=array();foreach(array('mail_from','mail_until') as $key){$v=$get($key);$d=preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)?DateTime::createFromFormat('!Y-m-d',$v):false;$dates[$key]=$d&&$d->format('Y-m-d')===$v?$v:'';}
        return array('group'=>$group,'type'=>$type,'result'=>$result,'recipient'=>sanitize_text_field($get('mail_recipient')),
            'subject'=>sanitize_text_field($get('mail_subject')),'from'=>$dates['mail_from'],'until'=>$dates['mail_until'],'page'=>max(1,absint($get('mail_page'))));
    }
    public static function query( $filters ) {
        if ( ! GE_WTP_Newsletter_Review::allowed() ) { return new WP_Error('denied','Acceso denegado.'); }
        $meta=array('relation'=>'AND');
        if($filters['group']==='campaigns')$meta[]=array('key'=>'_ge_email_context','value'=>'^newsletter_','compare'=>'REGEXP');
        if($filters['group']==='operational'){$meta[]=array('key'=>'_ge_email_context','value'=>'^newsletter_','compare'=>'NOT REGEXP');$meta[]=array('key'=>'_ge_email_context','value'=>'','compare'=>'!=');}
        if($filters['group']==='other')$meta[]=array('relation'=>'OR',array('key'=>'_ge_email_context','compare'=>'NOT EXISTS'),array('key'=>'_ge_email_context','value'=>''));
        if($filters['type'])$meta[]=array('key'=>'_ge_email_context','value'=>$filters['type']);
        if($filters['result'])$meta[]=array('key'=>'_ge_email_result','value'=>$filters['result']);
        if($filters['recipient'])$meta[]=array('key'=>'_ge_email_to','value'=>$filters['recipient'],'compare'=>'LIKE');
        $args=array('post_type'=>GE_WTP_Notifications::LOG_POST_TYPE,'post_status'=>'private','posts_per_page'=>50,'paged'=>$filters['page'],
            'orderby'=>array('date'=>'DESC','ID'=>'DESC'),'meta_query'=>$meta);
        if($filters['subject'])$args['s']=$filters['subject'];
        if($filters['from']||$filters['until']){$range=array('inclusive'=>true);if($filters['from'])$range['after']=$filters['from'].' 00:00:00';if($filters['until'])$range['before']=$filters['until'].' 23:59:59';$args['date_query']=array($range);}
        return new WP_Query($args);
    }
    public static function render() {
        if(!GE_WTP_Newsletter_Review::allowed())return;
        $f=self::filters($_GET);$query=self::query($f);if(is_wp_error($query))return;
        $groups=array(''=>'Todos','operational'=>'Operativos','campaigns'=>'Campañas y pruebas','other'=>'Sin clasificar');
        $results=array(''=>'Todos','sent'=>'Aceptado por el correo','failed'=>'No enviado','simulated'=>'Simulado','unknown'=>'Sin confirmar');
        ?><style>.ge-mail-log-primary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ge-mail-log-primary label{min-width:0}@media(max-width:760px){.ge-mail-log-primary{grid-template-columns:1fr}}</style><section class="ge-admin-panel ge-admin-tab-panel"><div class="ge-admin-panel-head"><div><span>Trazabilidad</span><h2>Correos enviados</h2></div></div>
        <form method="get" class="ge-admin-form"><input type="hidden" name="section" value="communications"><input type="hidden" name="subsection" value="emails">
            <div class="ge-mail-log-primary"><label>Grupo<select name="mail_group"><?php foreach($groups as $key=>$label):?><option value="<?php echo esc_attr($key);?>" <?php selected($f['group'],$key);?>><?php echo esc_html($label);?></option><?php endforeach;?></select></label>
            <label>Tipo de correo<select name="mail_type"><option value="">Todos los tipos</option><?php foreach(self::contexts() as $type):if($f['group']&&self::group($type)!==$f['group'])continue;?><option value="<?php echo esc_attr($type);?>" <?php selected($f['type'],$type);?>><?php echo esc_html(self::label($type));?></option><?php endforeach;?></select></label>
            <label>Resultado<select name="mail_result"><?php foreach($results as $key=>$label):?><option value="<?php echo esc_attr($key);?>" <?php selected($f['result'],$key);?>><?php echo esc_html($label);?></option><?php endforeach;?></select></label>
            </div><details <?php echo $f['recipient']||$f['subject']||$f['from']||$f['until']?'open':'';?>><summary>Fecha y búsqueda</summary>
                <label>Destinatario<input name="mail_recipient" type="search" value="<?php echo esc_attr($f['recipient']);?>"></label>
                <label>Buscar en el correo<input name="mail_subject" type="search" value="<?php echo esc_attr($f['subject']);?>"></label>
                <label>Desde<input name="mail_from" type="date" value="<?php echo esc_attr($f['from']);?>"></label><label>Hasta<input name="mail_until" type="date" value="<?php echo esc_attr($f['until']);?>"></label>
            </details><button type="submit" class="button button-primary">Filtrar correos</button>
            <a href="<?php echo esc_url(remove_query_arg(array('mail_group','mail_type','mail_result','mail_recipient','mail_subject','mail_from','mail_until','mail_page')));?>">Limpiar filtros</a>
        </form><p role="status"><?php echo number_format_i18n($query->found_posts);?> <?php echo 1===(int)$query->found_posts?'correo':'correos';?> · Página <?php echo (int)$f['page'];?></p>
        <?php if(!$query->posts):?><div class="ge-admin-empty">No hay correos que coincidan con estos filtros.</div><?php else:?>
        <div class="ge-admin-table-scroll"><table class="ge-admin-table"><thead><tr><th>Fecha</th><th>Destinatario</th><th>Asunto</th><th>Grupo</th><th>Tipo</th><th>Referencia</th><th>Resultado</th></tr></thead><tbody>
        <?php foreach($query->posts as $log):$context=(string)get_post_meta($log->ID,'_ge_email_context',true);$ref=absint(get_post_meta($log->ID,'_ge_email_object_id',true));$result=(string)get_post_meta($log->ID,'_ge_email_result',true);?>
            <tr><td><?php echo esc_html(get_the_date('d/m/Y H:i',$log));?></td><td><?php echo esc_html(get_post_meta($log->ID,'_ge_email_to',true));?></td><td><strong><?php echo esc_html($log->post_title);?></strong></td><td><?php echo esc_html($groups[self::group($context)]);?></td><td><?php echo esc_html(self::label($context));?></td><td><?php if('newsletter_campaign'===$context&&$ref&&GE_WTP_Newsletter_Review::campaign($ref)):?><a href="<?php echo esc_url(add_query_arg(array('subsection'=>'campaigns','newsletter_campaign'=>$ref),remove_query_arg(array('mail_page','mail_type','mail_group'))));?>">Campaña #<?php echo $ref;?></a><?php else:echo $ref?'Registro #'.$ref:'—';endif;?></td><td><?php echo esc_html($results[$result]??'Sin resultado registrado');?></td></tr>
        <?php endforeach;?></tbody></table></div><?php endif;?>
        <?php if($f['page']>1):?><a class="button" href="<?php echo esc_url(add_query_arg('mail_page',$f['page']-1));?>">Anterior</a><?php endif;?>
        <?php if($f['page']<$query->max_num_pages):?><a class="button" href="<?php echo esc_url(add_query_arg('mail_page',$f['page']+1));?>">Siguiente</a><?php endif;?>
        </section><?php
    }
}
