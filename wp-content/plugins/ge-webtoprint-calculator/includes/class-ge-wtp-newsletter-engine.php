<?php
if(!defined('ABSPATH'))exit;

/** One persisted attempt per native campaign/contact. Ambiguous attempts are never retried. */
final class GE_WTP_Newsletter_Engine {
    const POLICY='ge_newsletter_engine_policy_v1';
    const LOCK='ge_newsletter_dispatch_v1';
    public static function lock_name(){global $wpdb;return DB_NAME==='graphexpress_wp'&&GE_Organization::PRIMARY==='graph-express'?self::LOCK:self::LOCK.'_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix.'|'.GE_Organization::PRIMARY),0,16);}
    public static function init(){add_action('admin_post_ge_newsletter_engine_launch',array(__CLASS__,'handle_launch'));add_action('admin_post_ge_newsletter_engine_pause',array(__CLASS__,'handle_pause'));add_action('admin_post_ge_newsletter_engine_resume',array(__CLASS__,'handle_resume'));add_action('admin_post_ge_newsletter_engine_notice',array(__CLASS__,'handle_notice'));add_action('admin_post_ge_newsletter_engine_test',array(__CLASS__,'handle_test'));}
    private static function table($s){global $wpdb;return $wpdb->prefix.'ge_newsletter_'.$s;}
    public static function enabled(){
        $p=(array)get_option(self::POLICY,array());
        $channels=(array)get_option('ge_crm_attention_channels',array());$h=$channels['email']['consumer_health']??array();
        $reception=!empty($h['checked_at'])&&strtotime($h['checked_at'])>time()-300&&!empty($h['receiver_running'])&&empty($h['poll_failed'])&&empty($h['pending_events'])&&($h['oldest_pending_age_seconds']??0)<300;
        // Delivery-feedback health does not prove commercial queries reached the CRM.
        return $reception&&!empty($p['enabled'])&&!empty($p['provider_evidence_ref'])&&!empty($p['reception_evidence_ref'])&&!empty($p['verified_by'])&&($p['expires_at']??0)>time()&&($p['hourly_limit']??0)>0&&($p['daily_limit']??0)>0;
    }
    public static function ensure_schema(){
        global $wpdb;$t=self::table('deliveries');
        if(get_option('ge_newsletter_engine_schema_v2'))return true;
        $columns=$wpdb->get_col('SHOW COLUMNS FROM '.$t);
        foreach(array('attempt_key'=>"char(64) NOT NULL DEFAULT ''",'started_at'=>'datetime NULL','message_id'=>"varchar(190) NOT NULL DEFAULT ''",'log_id'=>'bigint unsigned NOT NULL DEFAULT 0','reply_record_id'=>'bigint unsigned NOT NULL DEFAULT 0') as $name=>$definition){if(!in_array($name,$columns,true)&&false===$wpdb->query('ALTER TABLE '.$t.' ADD COLUMN '.$name.' '.$definition))return false;}
        update_option('ge_newsletter_engine_schema_v2',1,false);return true;
    }
    private static function event($id,$type,$payload){GE_CRM::event(0,0,$type,array_merge(array('campaign_id'=>(int)$id,'source'=>'native_newsletter_engine'),$payload));}
    public static function queue($id){
        global $wpdb;$id=absint($id);
        if(!GE_WTP_Newsletter_Review::allowed())return new WP_Error('denied','Acceso denegado.');
        if(!self::enabled()||!self::ensure_schema())return new WP_Error('engine','Falta verificar el proveedor y la recepción antes de habilitar el motor.');
        $policy=(array)get_option(self::POLICY,array());
        if(!in_array($id,array_map('intval',$policy['authorized_campaign_ids']??array()),true))return new WP_Error('authority','Esta campaña no está autorizada para enviar.');
        $lock='ge_newsletter_review_'.$id;
        if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new WP_Error('busy','La campaña está siendo modificada.');
        $audience=false;
        try{
            if('1'!==(string)$wpdb->get_var("SELECT GET_LOCK('ge_newsletter_audiences_v1',0)"))return new WP_Error('busy','La audiencia está siendo modificada.');$audience=true;
            $c=GE_WTP_Newsletter_Review::campaign($id);$m=GE_WTP_Newsletter_Review::metadata($id);
            if($c&&in_array($c->status,array('queued','paused','sent'),true))return new WP_Error('queued','La campaña ya tiene una cola; no se crea otra.');
            if(!$c||!GE_WTP_Newsletter_Review::review_valid($c)||empty($m['statutory_notice'])||empty($m['notice_source']))return new WP_Error('review','Revisá el contenido, el aviso legal y la audiencia completa.');
            if(empty($m['test']['content_sha256'])||!hash_equals($m['test']['content_sha256'],GE_WTP_Newsletter_Review::content_fingerprint($c,$m))||($m['test']['result']??'')!=='sent')return new WP_Error('test','Falta una prueba aceptada por el correo para esta versión exacta.');
            if(($m['audience_key']??'')!==($policy['audience_key']??'')||($m['review']['pending_authorization']??1)!==0)return new WP_Error('audience','La audiencia requiere autorización.');
            $storage=array(self::table('contacts'),self::table('campaigns'),self::table('deliveries'),$wpdb->options,GE_CRM::table('events'));
            foreach($storage as $t)if('InnoDB'!==$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t)))return new WP_Error('storage','Almacenamiento transaccional no verificado.');
            $wpdb->query('START TRANSACTION');$total=0;
            foreach($m['review']['contacts'] as $contact_id=>$hash){$contact=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('contacts').' WHERE id=%d',$contact_id));if(!GE_WTP_Newsletter_Review::eligible($contact))continue;
                if(false===$wpdb->insert(self::table('deliveries'),array('campaign_id'=>$id,'contact_id'=>(int)$contact_id,'status'=>'pending')))throw new RuntimeException('No se pudo registrar la cola exacta.');$total++;
            }
            if(!$total)throw new RuntimeException('No hay destinatarios elegibles.');
            $m['launch']=array('actor_id'=>get_current_user_id(),'at'=>gmdate('c'),'content_sha256'=>GE_WTP_Newsletter_Review::content_fingerprint($c,$m),'batch_sha256'=>$m['review']['batch_sha256'],'total'=>$total,'authority_ref'=>$policy['authority_ref']??'');
            if(!update_option(GE_WTP_Newsletter_Review::META.$id,$m,false))throw new RuntimeException('No se pudo guardar la autorización.');
            if(1!==$wpdb->update(self::table('campaigns'),array('status'=>'queued','total_count'=>$total,'queued_at'=>current_time('mysql')),array('id'=>$id,'status'=>'draft')))throw new RuntimeException('El estado de la campaña cambió.');
            self::event($id,'newsletter_campaign_queued',array('total'=>$total,'batch_sha256'=>$m['review']['batch_sha256'],'actor_id'=>get_current_user_id(),'notes'=>'Campaña #'.$id.': cola exacta de '.$total.' destinatarios autorizada.'));
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar la cola.');
            if(!wp_next_scheduled(GE_WTP_Newsletter::CRON_HOOK))wp_schedule_single_event(time()+10,GE_WTP_Newsletter::CRON_HOOK);
            return $total;
        }catch(Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete(GE_WTP_Newsletter_Review::META.$id,'options');return new WP_Error('queue',$e->getMessage());}
        finally{if($audience)$wpdb->get_var("SELECT RELEASE_LOCK('ge_newsletter_audiences_v1')");$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function process($limit=5){
        global $wpdb;
        if(!self::enabled()||!self::ensure_schema())return array('blocked'=>true);
        if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',self::lock_name())))return array('busy'=>true);
        $result=array('attempted'=>0,'accepted'=>0,'suppressed'=>0,'uncertain'=>0,'rate_limited'=>false);
        try{
            // A persisted sending row from a terminated worker cannot be safely resent.
            $stale=$wpdb->get_results('SELECT id,campaign_id FROM '.self::table('deliveries')." WHERE status='sending'");
            foreach($stale as $d){$wpdb->update(self::table('deliveries'),array('status'=>'unknown','last_error'=>'Ejecución interrumpida: revisar evidencia antes de cualquier reenvío.'),array('id'=>$d->id,'status'=>'sending'));self::pause($d->campaign_id,'Resultado de un envío interrumpido sin confirmar.');}
            $p=(array)get_option(self::POLICY,array());$spacing=max(1,(int)ceil(3600/(int)$p['hourly_limit']));
            for($i=0;$i<min(20,max(1,(int)$limit));$i++){
                $last=(int)get_option('ge_newsletter_last_attempt_at',0);
                $hour=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".self::table('deliveries')." WHERE started_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR");
                $day=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".self::table('deliveries')." WHERE started_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY");
                if(time()-$last<$spacing||$hour>=(int)$p['hourly_limit']||$day>=(int)$p['daily_limit']){$result['rate_limited']=true;break;}
                $d=$wpdb->get_row('SELECT d.* FROM '.self::table('deliveries').' d JOIN '.self::table('campaigns')." c ON c.id=d.campaign_id WHERE d.status='pending' AND c.status='queued' ORDER BY d.id LIMIT 1");if(!$d)break;
                $c=GE_WTP_Newsletter_Review::campaign($d->campaign_id);$m=GE_WTP_Newsletter_Review::metadata($c->id);
                if(!in_array((int)$c->id,array_map('intval',$p['authorized_campaign_ids']??array()),true)){self::pause($c->id,'La campaña no está incluida en la autorización vigente.');break;}
                if(empty($m['launch']['content_sha256'])||!hash_equals($m['launch']['content_sha256'],GE_WTP_Newsletter_Review::content_fingerprint($c,$m))){self::pause($c->id,'El contenido o el remitente cambió después de autorizar el lote.');break;}
                $contact=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('contacts').' WHERE id=%d',$d->contact_id));
                if(!$contact||!GE_WTP_Newsletter_Review::eligible($contact)||empty($m['review']['contacts'][$d->contact_id])||!hash_equals($m['review']['contacts'][$d->contact_id],GE_WTP_Newsletter_Review::contact_fingerprint($contact))){$wpdb->update(self::table('deliveries'),array('status'=>'suppressed','last_error'=>'Contacto excluido: baja, rebote o cambio de autorización.'),array('id'=>$d->id,'status'=>'pending'));$result['suppressed']++;continue;}
                $key=hash('sha256','ge-newsletter|'.$c->id.'|'.$d->id.'|'.$m['launch']['batch_sha256']);$message='<ge-nl-'.$key.'@graphex.ar>';
                if(1!==$wpdb->update(self::table('deliveries'),array('status'=>'sending','attempts'=>1,'attempt_key'=>$key,'message_id'=>$message,'started_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$d->id,'status'=>'pending','attempts'=>0)))break;
                update_option('ge_newsletter_last_attempt_at',time(),false);$result['attempted']++;
                $hook=function($mailer)use($message){$mailer->MessageID=$message;};add_action('phpmailer_init',$hook,100);
                $url=add_query_arg('token',$contact->token,home_url('/preferencias-email/'));
                $one=add_query_arg(array('action'=>'ge_newsletter_one_click','token'=>$contact->token),admin_url('admin-post.php'));
                try{
                    $html=GE_WTP_Newsletter_Review::render_html($c,$url);if(!$html)throw new RuntimeException('Contenido inválido.');
                    $ok=GE_WTP_Notifications::send($contact->email,$c->subject,$html,'newsletter_campaign',$c->id,array('List-Unsubscribe: <'.$one.'>','List-Unsubscribe-Post: List-Unsubscribe=One-Click','X-Graphex-Delivery: '.(int)$d->id));
                    $state=$ok?'sent':'unknown';$error=$ok?'':'El transporte no confirmó aceptación. No se reintentará automáticamente.';
                }catch(Throwable $e){$state='unknown';$error='Resultado del transporte sin confirmar; revisar el historial.';}
                finally{remove_action('phpmailer_init',$hook,100);}
                $log=$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} a ON a.post_id=p.ID AND a.meta_key='_ge_email_context' AND a.meta_value='newsletter_campaign' JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_ge_email_object_id' AND b.meta_value=%s JOIN {$wpdb->postmeta} e ON e.post_id=p.ID AND e.meta_key='_ge_email_to' AND e.meta_value=%s WHERE p.post_type='ge_email_log' ORDER BY p.ID DESC LIMIT 1",(string)$c->id,$contact->email));
                if($log){update_post_meta($log,'_ge_newsletter_delivery_id',(int)$d->id);update_post_meta($log,'_ge_email_message_id',$message);if($state==='sent'&&get_post_meta($log,'_ge_email_result',true)==='simulated')$state='simulated';}else{$state='unknown';$error='Falta el registro del sitio; no reenviar sin conciliar.';}
                if(1!==$wpdb->update(self::table('deliveries'),array('status'=>$state,'last_error'=>$error,'log_id'=>(int)$log,'sent_at'=>$state==='sent'?gmdate('Y-m-d H:i:s'):null),array('id'=>$d->id,'status'=>'sending'))){self::pause($c->id,'No se pudo guardar el resultado del envío.');break;}
                if($state==='sent')$result['accepted']++;elseif($state==='unknown'){$result['uncertain']++;self::pause($c->id,$error);break;}
                self::refresh($c->id);
            }
            foreach($wpdb->get_col('SELECT id FROM '.self::table('campaigns')." WHERE status='queued'") as $campaign_id)self::refresh($campaign_id);
            update_option('ge_newsletter_engine_heartbeat',array('at'=>gmdate('c'),'result'=>$result),false);
            if((int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table('deliveries').' d JOIN '.self::table('campaigns')." c ON c.id=d.campaign_id WHERE d.status='pending' AND c.status='queued'")&&!wp_next_scheduled(GE_WTP_Newsletter::CRON_HOOK))wp_schedule_single_event(time()+max(10,$spacing),GE_WTP_Newsletter::CRON_HOOK);
            return $result;
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::lock_name()));}
    }
    public static function refresh($id){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT status,COUNT(*) total FROM '.self::table('deliveries').' WHERE campaign_id=%d GROUP BY status',$id));$n=array();foreach($rows as $r)$n[$r->status]=(int)$r->total;
        $accepted=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('deliveries').' WHERE campaign_id=%d AND sent_at IS NOT NULL',$id));
        $data=array('sent_count'=>$accepted,'failed_count'=>($n['unknown']??0)+($n['failed']??0)+($n['bounced']??0));
        $c=GE_WTP_Newsletter_Review::campaign($id);
        if($c&&$c->status==='queued'&&empty($n['pending'])&&empty($n['sending'])&&empty($n['unknown'])){$data['status']='sent';$data['sent_at']=current_time('mysql');}
        if(($data['status']??'')==='sent'){$wpdb->query('START TRANSACTION');try{if(1===$wpdb->update(self::table('campaigns'),$data,array('id'=>(int)$id,'status'=>'queued')))self::event($id,'newsletter_campaign_completed',array('accepted'=>$accepted,'bounced'=>$n['bounced']??0,'suppressed'=>$n['suppressed']??0,'notes'=>'Campaña #'.(int)$id.' finalizada; '.$accepted.' mensajes aceptados por el correo. La aceptación no confirma entrega ni lectura.'));if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo registrar el cierre de campaña.');}catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}}
        else $wpdb->update(self::table('campaigns'),$data,array('id'=>(int)$id));
    }
    public static function pause($id,$reason){global $wpdb;$changed=$wpdb->update(self::table('campaigns'),array('status'=>'paused'),array('id'=>absint($id),'status'=>'queued'));if($changed)self::event($id,'newsletter_campaign_paused',array('notes'=>sanitize_text_field($reason)));}
    public static function handle_launch(){if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);check_admin_referer('ge_newsletter_engine_launch_'.$id);$r=self::queue($id);if(is_wp_error($r))wp_die(esc_html($r->get_error_message()));wp_safe_redirect(GE_WTP_Communications_Panel::url('engine'));exit;}
    public static function handle_pause(){global $wpdb;if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);check_admin_referer('ge_newsletter_engine_pause_'.$id);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',self::lock_name())))wp_die('Un envío está terminando. Volvé a intentar la pausa.');try{self::pause($id,'Pausada por el operador.');}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::lock_name()));}wp_safe_redirect(GE_WTP_Communications_Panel::url('engine'));exit;}
    public static function resume($id){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed()||!self::enabled())return new WP_Error('denied','Falta una autorización vigente y recepción conectada.');$id=absint($id);$p=get_option(self::POLICY,array());
        if(!in_array($id,array_map('intval',$p['authorized_campaign_ids']??array()),true))return new WP_Error('authority','La campaña no está autorizada.');
        if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',self::lock_name())))return new WP_Error('busy','El motor está procesando un envío.');
        try{$c=GE_WTP_Newsletter_Review::campaign($id);$m=GE_WTP_Newsletter_Review::metadata($id);if(!$c||$c->status!=='paused'||empty($m['launch']['content_sha256'])||!hash_equals($m['launch']['content_sha256'],GE_WTP_Newsletter_Review::content_fingerprint($c,$m)))return new WP_Error('content','Revisá el estado y el contenido de esta campaña.');
            if((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('deliveries')." WHERE campaign_id=%d AND status IN ('unknown','sending')",$id)))return new WP_Error('uncertain','Hay resultados sin confirmar. Conciliá esos intentos antes de reanudar.');
            $wpdb->query('START TRANSACTION');try{if(1!==$wpdb->update(self::table('campaigns'),array('status'=>'queued'),array('id'=>$id,'status'=>'paused')))throw new RuntimeException('La campaña cambió de estado.');self::event($id,'newsletter_campaign_resumed',array('notes'=>'Campaña reanudada por el operador; no se repiten intentos previos.'));if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar la reanudación.');return true;}catch(Throwable $e){$wpdb->query('ROLLBACK');return new WP_Error('save','No se pudo registrar la reanudación.');}
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::lock_name()));}
    }
    public static function handle_resume(){if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);check_admin_referer('ge_newsletter_engine_resume_'.$id);$r=self::resume($id);if(is_wp_error($r))wp_die(esc_html($r->get_error_message()));wp_safe_redirect(GE_WTP_Communications_Panel::url('engine'));exit;}
    public static function notice($id,$notice,$source){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed()||!is_string($notice)||!is_string($source)||strlen($notice)>16000||strlen($source)>2000)return new WP_Error('denied','Revisá el aviso y su fuente.');
        $lock='ge_newsletter_review_'.absint($id);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new WP_Error('busy','Otra modificación está en curso.');
        try{$c=GE_WTP_Newsletter_Review::campaign($id);if(!$c||$c->status!=='draft')return new WP_Error('draft','Solo se puede editar un borrador.');$m=GE_WTP_Newsletter_Review::metadata($id);$m['statutory_notice']=sanitize_textarea_field($notice);$m['notice_source']=sanitize_text_field($source);unset($m['review'],$m['test']);update_option(GE_WTP_Newsletter_Review::META.absint($id),$m,false);return true;}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function test($id,$email){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed()||!is_string($email)||!is_email($email))return new WP_Error('denied','Elegí un correo de revisión válido.');
        $lock='ge_newsletter_review_'.absint($id);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))return new WP_Error('busy','Otra modificación está en curso.');
        try{$c=GE_WTP_Newsletter_Review::campaign($id);$m=GE_WTP_Newsletter_Review::metadata($id);if(!$c||$c->status!=='draft'||empty($m['statutory_notice'])||!GE_WTP_Newsletter_Review::review_valid($c))return new WP_Error('review','Revisá primero el aviso y la audiencia.');
            $hash=GE_WTP_Newsletter_Review::content_fingerprint($c,$m);$ok=GE_WTP_Notifications::send($email,'[PRUEBA] '.$c->subject,GE_WTP_Newsletter_Review::render_html($c,home_url('/preferencias-email/')),'newsletter_campaign_test',$c->id,array('X-Graphex-Test: 1'));
            $m['test']=array('at'=>gmdate('c'),'actor_id'=>get_current_user_id(),'content_sha256'=>$hash,'result'=>$ok?(GE_WTP_Notifications::is_local_environment()?'simulated':'sent'):'unknown','recipient_sha256'=>hash('sha256',strtolower($email)));
            update_option(GE_WTP_Newsletter_Review::META.absint($id),$m,false);self::event($id,'newsletter_campaign_test',array('notes'=>'Prueba de la versión exacta: '.$m['test']['result'].'.','content_sha256'=>$hash));return $m['test'];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function finish_action($id,$r){if(is_wp_error($r))wp_die(esc_html($r->get_error_message()));wp_safe_redirect(GE_WTP_Communications_Panel::url('campaigns',array('newsletter_campaign'=>absint($id))));exit;}
    public static function handle_notice(){if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);check_admin_referer('ge_newsletter_engine_notice_'.$id);self::finish_action($id,self::notice($id,wp_unslash($_POST['statutory_notice']??''),wp_unslash($_POST['notice_source']??'')));}
    public static function handle_test(){if(!GE_WTP_Newsletter_Review::allowed())wp_die('Acceso denegado.',403);$id=absint($_POST['campaign_id']??0);check_admin_referer('ge_newsletter_engine_test_'.$id);self::finish_action($id,self::test($id,wp_unslash($_POST['review_email']??'')));}
    public static function controls($c,$m){
        if(!GE_WTP_Newsletter_Review::allowed()||$c->status!=='draft')return;
        echo '<details><summary>Completar o corregir el pie del correo</summary><form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_newsletter_engine_notice"><input type="hidden" name="campaign_id" value="'.(int)$c->id.'">';wp_nonce_field('ge_newsletter_engine_notice_'.$c->id);
        echo '<label>Aviso y datos del responsable<textarea name="statutory_notice" rows="6" maxlength="4000">'.esc_textarea($m['statutory_notice']??'').'</textarea></label><label>Fuente<input name="notice_source" value="'.esc_attr($m['notice_source']??'').'" maxlength="500"></label><button class="button" type="submit">Guardar pie y volver a revisar audiencia</button></form></details>';
        echo '<form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_newsletter_engine_test"><input type="hidden" name="campaign_id" value="'.(int)$c->id.'">';wp_nonce_field('ge_newsletter_engine_test_'.$c->id);
        echo '<label>Enviar una prueba a<input type="email" name="review_email" required placeholder="Correo de revisión"></label><button class="button" type="submit">Enviar prueba</button></form><p>La prueba se registra por separado y no se envía a la audiencia.</p>';
        if(!empty($m['test']))echo '<p>Última prueba: '.esc_html($m['test']['at']).' · '.esc_html($m['test']['result']==='sent'?'Aceptada por el correo':'Requiere revisar el resultado').'.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_newsletter_engine_launch"><input type="hidden" name="campaign_id" value="'.(int)$c->id.'">';wp_nonce_field('ge_newsletter_engine_launch_'.$c->id);
        echo '<button class="button button-primary" type="submit" '.(!self::enabled()?'disabled':'').'>Lanzar campaña a la audiencia revisada</button></form>';
    }
    /** Private worker supplies evidence parsed from the local MTA log, never from mail body instructions. */
    public static function reconcile_mta(array $events){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed())return new WP_Error('denied','Acceso denegado.');$changed=0;
        foreach(array_slice($events,0,500) as $e){
            if(!is_array($e)||!in_array($e['kind']??'',array('hard_bounce','temporary_bounce','policy_bounce','review_bounce'),true)||!preg_match('/^(?:exim|dsn):[a-f0-9]{64}$/D',$e['evidence_ref']??''))continue;
            $d=$wpdb->get_row($wpdb->prepare('SELECT d.*,c.email FROM '.self::table('deliveries').' d JOIN '.self::table('contacts').' c ON c.id=d.contact_id WHERE d.id=%d',absint($e['delivery_id']??0)));
            if(!$d||!hash_equals($d->message_id,(string)($e['message_id']??''))||!hash_equals(hash('sha256',strtolower($d->email)),(string)($e['recipient_sha256']??'')))continue;
            $r=GE_WTP_Newsletter_Audiences::suppress_delivery($d->id,$e['kind'],'Fallo confirmado por el servidor de correo: '.sanitize_text_field($e['diagnostic']??''),$e['evidence_ref']);
            if(!is_wp_error($r)){$changed++;self::refresh($d->campaign_id);}
        }
        update_option('ge_newsletter_feedback_heartbeat',array('at'=>gmdate('c'),'matched'=>$changed),false);return $changed;
    }
    public static function correlate_replies(){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed())return 0;$cursor=(int)get_option('ge_newsletter_reply_cursor',0);$matched=0;
        foreach($wpdb->get_results($wpdb->prepare('SELECT id,payload FROM '.GE_CRM::table()." WHERE organization_id=%s AND id>%d AND kind='thread' ORDER BY id LIMIT 100",GE_Organization::PRIMARY,$cursor)) as $r){
            $cursor=(int)$r->id;$payload=json_decode($r->payload,true);$e=$payload['attention_event']??array();if(($e['channel']??'')!=='email'||($e['account_ref']??'')!=='graphex'||!empty($e['historical_backfill']))continue;
            $headers=$e['headers']??array();if(!empty($headers['auto-submitted'])&&strtolower($headers['auto-submitted'])!=='no')continue;
            $references=($headers['in-reply-to']??'').' '.($headers['references']??'');preg_match_all('/<ge-nl-[a-f0-9]{64}@graphex\.ar>/',$references,$matches);
            foreach(array_unique($matches[0]) as $mid){$d=$wpdb->get_row($wpdb->prepare('SELECT d.*,c.email FROM '.self::table('deliveries').' d JOIN '.self::table('contacts').' c ON c.id=d.contact_id WHERE d.message_id=%s LIMIT 1',$mid));
                if(!$d||strtolower($d->email)!==strtolower($e['from']??'')||$d->reply_record_id)continue;
                $wpdb->query('START TRANSACTION');try{if(1===$wpdb->update(self::table('deliveries'),array('reply_record_id'=>(int)$r->id),array('id'=>$d->id,'reply_record_id'=>0))){GE_CRM::event($r->id,0,'newsletter_campaign_reply',array('campaign_id'=>(int)$d->campaign_id,'delivery_id'=>(int)$d->id,'source_ref'=>$e['source_ref']??'','notes'=>'Respuesta vinculada a la campaña #'.(int)$d->campaign_id.'.'));$matched++;}if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo registrar la respuesta.');}catch(Throwable $err){$wpdb->query('ROLLBACK');throw $err;}
            }
        }update_option('ge_newsletter_reply_cursor',$cursor,false);return $matched;
    }
    public static function record_unsubscribes(){
        global $wpdb;if(!GE_WTP_Newsletter_Review::allowed())return 0;$recorded=0;
        $cursor=(int)get_option('ge_newsletter_unsubscribe_cursor',0);$rows=$wpdb->get_results($wpdb->prepare('SELECT d.id,d.campaign_id,d.contact_id,c.unsubscribed_at FROM '.self::table('deliveries').' d JOIN '.self::table('contacts')." c ON c.id=d.contact_id WHERE c.status='unsubscribed' AND c.unsubscribed_at IS NOT NULL AND d.id>%d ORDER BY d.id LIMIT 500",$cursor));
        foreach($rows as $row){$lock='ge_newsletter_review_'.(int)$row->campaign_id;if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)))continue;
            try{$m=GE_WTP_Newsletter_Review::metadata($row->campaign_id);if(empty($m['launch'])||($m['unsubscribe_receipts'][$row->contact_id]??'')===$row->unsubscribed_at)continue;
                $wpdb->query('START TRANSACTION');$m['unsubscribe_receipts'][$row->contact_id]=$row->unsubscribed_at;if(!update_option(GE_WTP_Newsletter_Review::META.(int)$row->campaign_id,$m,false))throw new RuntimeException('No se pudo registrar la baja.');
                self::event($row->campaign_id,'newsletter_contact_unsubscribed',array('contact_id'=>(int)$row->contact_id,'delivery_id'=>(int)$row->id,'source'=>'native_contact_preferences','unsubscribed_at'=>$row->unsubscribed_at,'recorded_by_service'=>true,'notes'=>'Baja voluntaria registrada en la base de contactos. La dirección queda excluida de próximos envíos.'));
                if(false===$wpdb->query('COMMIT'))throw new RuntimeException('No se pudo confirmar la baja.');$recorded++;
            }catch(Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete(GE_WTP_Newsletter_Review::META.(int)$row->campaign_id,'options');throw $e;}
            finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }update_option('ge_newsletter_unsubscribe_cursor',count($rows)===500?(int)end($rows)->id:0,false);return $recorded;
    }
}
