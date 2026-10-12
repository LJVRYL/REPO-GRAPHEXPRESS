<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GE_WTP_Campaign_Actions {
    public static function init() {
        add_action('admin_post_ge_newsletter_campaign_delete',array(__CLASS__,'handle_delete'));
        add_action('admin_post_ge_newsletter_campaign_restore',array(__CLASS__,'handle_restore'));
    }
    public static function audiences() { return array('first'=>'Primera campaña','second'=>'Segunda campaña','all_subscribed'=>'Todos los suscriptores'); }
    private static function table($name) { global $wpdb;return $wpdb->prefix.'ge_newsletter_'.$name; }
    public static function snapshot($key) {
        global $wpdb;
        if(!GE_WTP_Newsletter_Review::allowed())return new WP_Error('denied','Acceso denegado.');
        if(!is_string($key)||!isset(self::audiences()[$key]))return new WP_Error('audience','Audiencia inválida.');
        $where=$key==='all_subscribed'?"status='subscribed'":$wpdb->prepare('audience=%s',$key);
        $cursor=0;$fingerprints=array();$eligible=0;$suppressed=0;$pending=0;
        do {
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('contacts').' WHERE '.$where.' AND id>%d ORDER BY id ASC LIMIT 500',$cursor));
            foreach($rows as $c){$cursor=(int)$c->id;$fingerprints[$cursor]=GE_WTP_Newsletter_Review::contact_fingerprint($c);
                if(GE_WTP_Newsletter_Review::eligible($c))$eligible++;
                elseif(in_array($c->status,array('unsubscribed','bounced','paused','complained'),true)||$c->unsubscribed_at)$suppressed++;
                else $pending++;
            }
        }while(count($rows)===500);
        return array('audience_key'=>$key,'contacts'=>$fingerprints,'total'=>count($fingerprints),'eligible'=>$eligible,'suppressed'=>$suppressed,
            'pending_authorization'=>$pending,'membership_sha256'=>hash('sha256',wp_json_encode($fingerprints)));
    }
    private static function event($type,$id,$payload) {
        if(!class_exists('GE_CRM'))throw new RuntimeException('El registro CRM no está disponible.');
        GE_CRM::event(0,0,$type,array_merge(array('campaign_id'=>(int)$id,'source'=>'native_newsletter','send_authority'=>false),$payload));
    }
    private static function transaction_ready() {
        global $wpdb;
        if(!class_exists('GE_CRM'))return false;
        $names=array(self::table('contacts'),self::table('campaigns'),$wpdb->options,GE_CRM::table('events'));
        $sql=$wpdb->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s,%s,%s)",$names);
        return 4===(int)$wpdb->get_var($sql);
    }
    public static function select_audience($id,$key) {
        global $wpdb;
        if(!GE_WTP_Newsletter_Review::allowed())return new WP_Error('denied','Acceso denegado.');
        if(!self::transaction_ready())return new WP_Error('storage','No se pudo verificar el almacenamiento de campaña y CRM.');
        if(!is_string($key)||!isset(self::audiences()[$key]))return new WP_Error('audience','Elegí una audiencia.');
        $lock='ge_newsletter_review_'.absint($id);$audience_lock='ge_newsletter_audiences_v1';
        if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new WP_Error('busy','Otra modificación está en curso.');
        $locked=false;
        try{
            if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$audience_lock)))return new WP_Error('busy','La audiencia se está actualizando.');$locked=true;
            $c=GE_WTP_Newsletter_Review::campaign($id);$meta=GE_WTP_Newsletter_Review::metadata($id);
            if(!$c||$c->status!=='draft'||!GE_WTP_Newsletter_Review::render_html($c))return new WP_Error('draft','El borrador no está disponible.');
            $wpdb->query('START TRANSACTION');$snapshot=self::snapshot($key);if(is_wp_error($snapshot)||!$snapshot['total'])throw new RuntimeException('La audiencia está vacía.');
            $meta['audience_key']=$key;$review=$snapshot+array('content_sha256'=>GE_WTP_Newsletter_Review::content_fingerprint($c,$meta),
                'reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c'),'send_authority'=>false,'selection_mode'=>'whole_audience');
            $review['batch_sha256']=hash('sha256',wp_json_encode(array($key,$review['content_sha256'],$review['membership_sha256'])));
            if(($meta['review']['batch_sha256']??'')===$review['batch_sha256']){$wpdb->query('ROLLBACK');return $meta['review'];}
            $meta['review']=$review;if(!update_option(GE_WTP_Newsletter_Review::META.absint($id),$meta,false))throw new RuntimeException('No se pudo guardar la selección.');
            self::event('newsletter_audience_selected',$id,array('audience_key'=>$key,'audience_label'=>self::audiences()[$key],
                'membership_sha256'=>$review['membership_sha256'],'batch_sha256'=>$review['batch_sha256'],'total'=>$review['total'],
                'eligible'=>$review['eligible'],'suppressed'=>$review['suppressed'],'pending_authorization'=>$review['pending_authorization'],
                'notes'=>'Campaña #'.absint($id).': audiencia completa «'.self::audiences()[$key].'» seleccionada ('.$review['total'].' contactos). No se autorizó un envío.'));
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar la selección.');return $review;
        }catch(Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete(GE_WTP_Newsletter_Review::META.absint($id),'options');return new WP_Error('save',$e->getMessage());}
        finally{if($locked)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$audience_lock));$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function set_deleted($id,$deleted=true) {
        global $wpdb;
        if(!GE_WTP_Newsletter_Review::allowed())return new WP_Error('denied','Acceso denegado.');
        if(!self::transaction_ready())return new WP_Error('storage','No se pudo verificar el almacenamiento de campaña y CRM.');
        $id=absint($id);$lock='ge_newsletter_review_'.$id;if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new WP_Error('busy','Otra modificación está en curso.');
        try{
            $c=GE_WTP_Newsletter_Review::campaign($id);if(!$c)return new WP_Error('campaign','Campaña inexistente.');
            if($deleted&&$c->status==='deleted'||!$deleted&&$c->status==='draft')return true;
            if($c->status!==($deleted?'draft':'deleted'))return new WP_Error('status','Solo se pueden eliminar o restaurar borradores.');
            if((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('deliveries').' WHERE campaign_id=%d',$id)))return new WP_Error('history','La campaña tiene intentos de envío; se conserva su trazabilidad.');
            $wpdb->query('START TRANSACTION');$meta=GE_WTP_Newsletter_Review::metadata($id);unset($meta['review']);
            $meta[$deleted?'deleted_at':'restored_at']=gmdate('c');$meta[$deleted?'deleted_by':'restored_by']=get_current_user_id();
            if(1!==$wpdb->update(self::table('campaigns'),array('status'=>$deleted?'deleted':'draft'),array('id'=>$id,'status'=>$c->status)))throw new RuntimeException('No se pudo modificar el borrador.');
            update_option(GE_WTP_Newsletter_Review::META.$id,$meta,false);
            self::event($deleted?'newsletter_campaign_deleted':'newsletter_campaign_restored',$id,array('notes'=>'Campaña #'.$id.($deleted?' eliminada de la lista (recuperable).':' restaurada; requiere revisar su audiencia.')));
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar el cambio.');return true;
        }catch(Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete(GE_WTP_Newsletter_Review::META.$id,'options');return new WP_Error('save',$e->getMessage());}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function handle($deleted) {
        if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);
        check_admin_referer('ge_newsletter_campaign_'.($deleted?'delete':'restore').'_'.$id);$r=self::set_deleted($id,$deleted);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_message()));wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('communications',array('subsection'=>'campaigns')));exit;
    }
    public static function handle_delete(){self::handle(true);}public static function handle_restore(){self::handle(false);}
}
