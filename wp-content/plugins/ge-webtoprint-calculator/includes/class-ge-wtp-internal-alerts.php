<?php
defined('ABSPATH') || exit;
/** Private event inbox; reading is per staff member. */
final class GE_WTP_Internal_Alerts {
    const TYPE='ge_internal_alert';
    public static function init() {
        add_action('init',function(){register_post_type(self::TYPE,array('public'=>false,'show_ui'=>false,'capability_type'=>'post','map_meta_cap'=>true));});
        add_action('admin_post_ge_alert_read',array(__CLASS__,'read'));
        add_action('woocommerce_payment_complete',function($id){$o=wc_get_order($id);if($o)self::create('payment','Pago recibido · '.$o->get_order_number(),$id,$o->get_customer_id());});
        add_action('ge_file_analysis_complete',array(__CLASS__,'analysis_complete'),10,2);
    }
    public static function analysis_complete($ref,$status) {
        if(!in_array($status,array('blocker','warning','failed'),true))return;$row=GE_WTP_File_Analysis::from_ref($ref);if(!$row)return;
        foreach((array)get_option(GE_WTP_File_Analysis::PREFIX.'owners_'.$row['analysis_id'],array()) as $owner){$id=$owner['id'];if(!in_array(get_post_type($id),array(GE_WTP_Quote_Requests::TYPE,GE_WTP_Commercial_Quotes::POST_TYPE,'shop_order'),true))continue;self::create('file_'.$status,'Hay un archivo para revisar · #'.$id,$id,0,$status==='warning'?'warning':'critical');}
    }
    public static function create($type,$title,$entity,$customer=0,$severity='info') {
        $key='ge_alert_once_'.hash('sha256',$type.':'.$entity);
        if(!add_option($key,'pending','',false)) { return get_option($key); }
        $id=wp_insert_post(array('post_type'=>self::TYPE,'post_status'=>'private','post_title'=>sanitize_text_field($title),'post_author'=>get_current_user_id()),true);
        if(is_wp_error($id)){delete_option($key);return $id;}
        update_post_meta($id,'_ge_alert',array('notification_id'=>$id,'type'=>sanitize_key($type),'severity'=>sanitize_key($severity),'entity_ref'=>absint($entity),'customer_ref'=>absint($customer),'created_at'=>gmdate('c'),'actor'=>get_current_user_id()?:'system'));
        update_option($key,$id,false);return $id;
    }
    public static function url($data) {
        $kind=get_post_type($data['entity_ref']);$section=GE_WTP_Quote_Requests::TYPE===$kind?'requests':(GE_WTP_Commercial_Quotes::POST_TYPE===$kind?'quotes':'orders');
        $arg='requests'===$section?'request_id':('quotes'===$section?'quote_id':'order_id');
        return GE_WTP_Staff_Portal::portal_url($section,array($arg=>$data['entity_ref']));
    }
    public static function unread($actor) {
        return get_posts(array('post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>30,'meta_query'=>array(array('key'=>'_ge_read_'.$actor,'compare'=>'NOT EXISTS'))));
    }
    public static function read() {
        if(!GE_WTP_Staff_Portal::can_access()) { wp_die('Acceso denegado.','',array('response'=>403)); }
        $id=absint($_POST['notification_id']??0);check_admin_referer('ge_alert_read_'.$id);
        if(self::TYPE!==get_post_type($id)){wp_die('Aviso inexistente.');}
        update_post_meta($id,'_ge_read_'.get_current_user_id(),gmdate('c'));
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('requests'));exit;
    }
    public static function bell() {
        if(!GE_WTP_Staff_Portal::can_access())return;$rows=self::unread(get_current_user_id());
        echo '<details class="ge-alert-bell"><summary aria-label="Notificaciones: '.count($rows).' sin leer"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>'.count($rows).'</span></summary><div class="ge-alert-dropdown"><h2>Notificaciones</h2>';
        if(!$rows)echo '<p>No tenés avisos pendientes.</p>';
        foreach($rows as $p){$d=get_post_meta($p->ID,'_ge_alert',true);echo '<article><a href="'.esc_url(self::url($d)).'">'.esc_html($p->post_title).'</a><small>'.esc_html(wp_date('d/m H:i',strtotime($d['created_at']))).'</small><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_alert_read"><input type="hidden" name="notification_id" value="'.$p->ID.'">';wp_nonce_field('ge_alert_read_'.$p->ID);echo '<button>Marcar leída</button></form></article>';}
        echo '<a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('requests')).'">Ver solicitudes</a></div></details>';
    }
}
