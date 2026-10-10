<?php
defined('ABSPATH') || exit;

final class GE_CRM_Outbound {
    const TYPE='crm_outbound';
    const PRIVATE_DIR='/home/graphexpress/crm-outbound';
    public static function init() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('ge_crm_thread_agent',array(__CLASS__,'render'),1);
        add_action('wp_footer',array(__CLASS__,'assets'),90);
        add_action('ge_crm_inbox_channel_details',array(__CLASS__,'render_connections'));
        add_action('admin_post_ge_crm_connect_reply',array(__CLASS__,'connect'));
    }
    public static function config() {
        $path=self::PRIVATE_DIR.'/config.json';
        if (PHP_SAPI==='cli') { $qa=getenv('GE_CRM_OUTBOUND_CONFIG');if ($qa && strpos($qa,'/job-flow-qa-')!==false && strpos($qa,'/site/')===false) $path=$qa; }
        $c=is_readable($path) ? json_decode(file_get_contents($path),true) : array();
        return ($c['organization_id'] ?? '')==='graph-express' ? ($c['outbound'] ?? array()) : array();
    }
    public static function connect() {
        GE_CRM::admin(); check_admin_referer('ge_crm_connect_reply');
        try {
            if (GE_CRM::org()!=='graph-express' || !is_writable(self::PRIVATE_DIR)) throw new RuntimeException('La configuración privada no está preparada.');
            $channel=sanitize_key($_POST['channel'] ?? '');
            $assets=array('instagram'=>'17841407285480956','messenger'=>'1377222212141366','whatsapp'=>'1354138734939171');
            if ($channel==='email') {
                $s=GE_WTP_Notification_Center::settings(); $sender=strtolower($s['sender_email'] ?? '');
                $channels=get_option('ge_crm_attention_channels',array());
                if (!is_email($sender) || !in_array($sender,array_map('strtolower',$channels['email']['recipients'] ?? array()),true)) throw new RuntimeException('El remitente debe coincidir con una cuenta de recepción configurada.');
                $c=array('enabled'=>true,'recipients'=>array($sender),'verified_at'=>gmdate('c'));
            } else {
                if (!isset($assets[$channel])) throw new RuntimeException('Canal inválido.');
                $token=wp_unslash($_POST['access_token'] ?? '');
                if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{40,4096}$/D',$token)) throw new RuntimeException('Pegá un identificador de acceso válido.');
                $fields=$channel==='whatsapp' ? 'id,platform_type,is_on_biz_app' : 'id';
                $res=wp_remote_get('https://graph.facebook.com/v26.0/'.$assets[$channel].'?fields='.$fields,array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Bearer '.$token)));
                $data=is_wp_error($res) ? array() : json_decode(wp_remote_retrieve_body($res),true);
                if (is_wp_error($res) || wp_remote_retrieve_response_code($res)!==200 || ($data['id'] ?? '')!==$assets[$channel]) throw new RuntimeException('Meta no confirmó acceso al activo Graphex seleccionado.');
                if ($channel==='whatsapp' && (empty($data['is_on_biz_app']) || ($data['platform_type'] ?? '')!=='CLOUD_API')) throw new RuntimeException('Todavía falta habilitar la coexistencia del número real en Meta. No se modificó el número.');
                $c=array('enabled'=>true,'asset_id'=>$assets[$channel],'access_token'=>$token,'verified_at'=>gmdate('c'));
                if ($channel==='whatsapp') $c['waba_id']='735912107316791';
            }
            $lock=fopen(self::PRIVATE_DIR.'/config.lock','c'); if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('No se pudo proteger la configuración.');
            try {
                $all=self::config();$all[$channel]=$c;
                $tmp=tempnam(self::PRIVATE_DIR,'config-');if (!$tmp) throw new RuntimeException('No se pudo guardar la configuración.');
                chmod($tmp,0600);
                if (file_put_contents($tmp,wp_json_encode(array('organization_id'=>'graph-express','outbound'=>$all)))===false || !rename($tmp,self::PRIVATE_DIR.'/config.json')) throw new RuntimeException('No se pudo guardar la configuración.');
            } finally { flock($lock,LOCK_UN);fclose($lock); }
            GE_CRM::event(0,0,'crm_channel_connected',array('channel'=>$channel,'asset_id'=>$assets[$channel] ?? $sender,'verified_at'=>gmdate('c')));
            set_transient('ge_crm_connect_feedback_'.get_current_user_id(),'Conexión guardada. El envío se prueba desde una conversación.',60);
        } catch(Throwable $e) { set_transient('ge_crm_connect_feedback_'.get_current_user_id(),$e->getMessage(),60); }
        wp_safe_redirect(GE_CRM_UI::url('inbox'));exit;
    }
    public static function render_connections() {
        if (GE_CRM::org()!=='graph-express') return;
        $cfg=self::config();echo '<section class="ge-crm-panel"><h2>Respuestas desde el CRM</h2><p>Los mensajes se envían sólo cuando un operador pulsa Enviar respuesta.</p><ul>';
        foreach(array('email'=>'Correo','instagram'=>'Instagram','messenger'=>'Messenger','whatsapp'=>'WhatsApp') as $k=>$label) echo '<li>'.esc_html($label).' · '.(!empty($cfg[$k]['enabled'])?'Configurado · falta comprobar cada envío':'Pendiente de conexión').'</li>';
        echo '</ul>';
        $feedback=get_transient('ge_crm_connect_feedback_'.get_current_user_id());if ($feedback) {echo '<p role="status">'.esc_html($feedback).'</p>';delete_transient('ge_crm_connect_feedback_'.get_current_user_id());}
        if (in_array(GE_CRM::role(get_current_user_id()),array('owner','admin'),true)) {
            echo '<details><summary>Conectar un canal de Graphex</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('ge_crm_connect_reply');
            echo '<input type="hidden" name="action" value="ge_crm_connect_reply"><label>Canal<select name="channel"><option value="instagram">Instagram @graphex.ar</option><option value="messenger">Messenger · página Graphex</option><option value="email">Correo · remitente configurado</option><option value="whatsapp">WhatsApp · número real, requiere coexistencia</option></select></label><label>Identificador de acceso de Meta<input name="access_token" type="password" autocomplete="new-password" maxlength="4096"></label><p>Para correo no se necesita este identificador. Meta se verifica antes de guardar; la clave queda en configuración privada del servidor y no se muestra en el historial.</p><button>Verificar y guardar conexión</button></form></details>';
        }
        echo '</section>';
    }
    private static function conversation($r) { return $r['conversation_key'] ?? 'record:'.$r['id']; }
    public static function incoming($r) {
        GE_CRM::require_access(); global $wpdb;
        if (empty($r['conversation_key'])) return array($r);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.GE_CRM::table().' WHERE organization_id=%s AND kind=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,%s))=%s ORDER BY id DESC LIMIT 50',GE_CRM::org(),'thread','$.conversation_key',$r['conversation_key']),ARRAY_A);
        return array_map(array('GE_CRM','decode'),array_reverse($rows));
    }
    private static function latest($r) {
        $all=self::incoming($r); $latest=$r;
        foreach ($all as $other) {
            $e=$other['meta_event'] ?? $other['attention_event'] ?? array(); $old=$latest['meta_event'] ?? $latest['attention_event'] ?? array();
            if (strtotime($e['timestamp'] ?? $e['received_at'] ?? '')>strtotime($old['timestamp'] ?? $old['received_at'] ?? '')) $latest=$other;
        }
        // Reopening/closing the viewed record remains authoritative for the action.
        $latest['status']=$r['status']; return $latest;
    }
    public static function history($r) {
        GE_CRM::require_access(); global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.GE_CRM::table('events').' WHERE organization_id=%s AND event_type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,%s))=%s ORDER BY id DESC LIMIT 50',GE_CRM::org(),self::TYPE,'$.conversation_key',self::conversation($r)),ARRAY_A);
        foreach ($rows as &$row) $row['details']=self::delivery(json_decode($row['payload'],true) ?: array()); unset($row);
        return array_reverse($rows);
    }
    private static function delivery($d) {
        global $wpdb;if (empty($d['provider_id'])) return $d;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT payload FROM '.GE_CRM::table('events').' WHERE organization_id=%s AND event_type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,%s))=%s ORDER BY id ASC LIMIT 50',GE_CRM::org(),'meta_transport','$.event.message_id',$d['provider_id']),ARRAY_A);
        $rank=array('accepted'=>1,'sent'=>1,'delivered'=>2,'read'=>3);
        foreach ($rows as $row) {
            $event=json_decode($row['payload'],true);$e=$event['event'] ?? array();
            if (($e['account_ref'] ?? '')!==($d['account_ref'] ?? '') || ($e['channel'] ?? 'whatsapp')!==$d['channel']) continue;
            $next=$e['status'] ?? '';
            if ($next==='failed' && ($rank[$d['state']] ?? 0)<2) {$d['state']='failed';$d['error']='El canal informó un fallo de entrega.';}
            elseif (isset($rank[$next]) && $rank[$next]>($rank[$d['state']] ?? 0)) $d['state']=$next;
        }
        return $d;
    }
    private static function update($id,$data) {
        global $wpdb;
        if ($wpdb->update(GE_CRM::table('events'),array('payload'=>wp_json_encode($data)),array('id'=>$id,'organization_id'=>GE_CRM::org(),'event_type'=>self::TYPE))===false) throw new RuntimeException('No se pudo registrar el resultado.',500);
    }
    public static function send($raw) {
        GE_CRM::require_access(true);
        $id=absint($raw['record_id'] ?? 0); $key=$raw['request_id'] ?? ''; $text=$raw['text'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9-]{36}$/D',$key) || !is_string($text)) throw new RuntimeException('Solicitud de envío inválida.',422);
        $r=GE_CRM::get($id,'thread'); $hash=hash('sha256',$id.':'.$text); $config=self::config();
        $claim=GE_CRM::locked(function() use($id,$key,$text,$hash,$config) {
            global $wpdb;
            $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.GE_CRM::table('events').' WHERE organization_id=%s AND event_type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,%s))=%s LIMIT 1',GE_CRM::org(),self::TYPE,'$.request_id',$key),ARRAY_A);
            if ($old) {
                $d=json_decode($old['payload'],true);
                if (($d['fingerprint'] ?? '')!==$hash) throw new RuntimeException('Esta solicitud ya corresponde a otro texto.',409);
                if (($d['state'] ?? '')==='sending' && strtotime($old['created_at'].' UTC')<time()-60) { $d['state']='unknown';$d['error']='Resultado sin confirmar; revisá el canal antes de reenviar.';self::update($old['id'],$d); }
                return array('cached'=>true,'data'=>self::delivery($d));
            }
            $r=GE_CRM::get($id,'thread'); $source=self::latest($r);
            $plan=GE_CRM_Send_Policy::plan($source,$config,$text,time());
            if ($plan['channel']==='email') {
                if (!class_exists('GE_WTP_Notifications') || !class_exists('GE_WTP_Notification_Center')) throw new RuntimeException('Transporte de correo no disponible.',422);
                $settings=GE_WTP_Notification_Center::settings();
                if (strtolower($settings['sender_email'] ?? '')!==strtolower($plan['asset_id'])) throw new RuntimeException('El remitente configurado no coincide con el correo recibido.',422);
            }
            $data=array('request_id'=>$key,'fingerprint'=>$hash,'conversation_key'=>self::conversation($r),'channel'=>$plan['channel'],'account_ref'=>$plan['account_ref'],'recipient'=>$plan['recipient'],'text'=>$text,'state'=>'sending','provider_id'=>'','error'=>'','updated_at'=>gmdate('c'));
            GE_CRM::event($id,$r['customer_id'],self::TYPE,$data); $eid=(int)$wpdb->insert_id;
            return array('cached'=>false,'event_id'=>$eid,'data'=>$data,'plan'=>$plan);
        });
        if ($claim['cached']) return self::public_result($claim['data']);
        $plan=$claim['plan'];
        try {
            if ($plan['channel']==='email') {
                if (!class_exists('GE_WTP_Notifications') || !class_exists('GE_WTP_Notification_Center')) throw new RuntimeException('Transporte de correo no disponible.');
                $settings=GE_WTP_Notification_Center::settings();
                if (strtolower($settings['sender_email'] ?? '')!==strtolower($plan['asset_id'])) throw new RuntimeException('El remitente configurado no coincide con el correo recibido.');
                $headers=array(); $mid=$plan['headers']['message-id'] ?? '';
                if (preg_match('/^<[^<>\r\n]{1,180}>$/D',$mid)) $headers=array('In-Reply-To: '.$mid,'References: '.$mid);
                $ok=GE_WTP_Notifications::send($plan['recipient'],$plan['subject'],'<p>'.nl2br(esc_html($text)).'</p>','crm_reply',$id,$headers);
                $result=array('state'=>$ok ? (GE_WTP_Notifications::is_local_environment() ? 'simulated' : 'accepted') : 'failed','provider_id'=>'','error'=>$ok ? '' : 'Falló el transporte de correo.');
            } else {
                $token=$config[$plan['channel']]['access_token'];
                $response=wp_remote_post($plan['url'],array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'),'body'=>wp_json_encode($plan['payload'])));
                $result=is_wp_error($response) ? array('state'=>'unknown','provider_id'=>'','error'=>'No se pudo confirmar el envío. Revisá el canal antes de reenviar.') : GE_CRM_Send_Policy::result(wp_remote_retrieve_response_code($response),wp_remote_retrieve_body($response));
            }
        } catch(Throwable $error) { $result=array('state'=>'unknown','provider_id'=>'','error'=>'No se pudo confirmar el envío. Revisá el canal antes de reenviar.'); }
        $data=GE_CRM::locked(function() use($claim,$result) {
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare('SELECT payload FROM '.GE_CRM::table('events').' WHERE id=%d AND organization_id=%s',$claim['event_id'],GE_CRM::org()),ARRAY_A);
            $d=json_decode($row['payload'],true); $state=$d['state'] ?? '';
            // A webhook may confirm delivery before the HTTP request returns.
            $d=array_merge($d,$result,array('updated_at'=>gmdate('c')));
            if (in_array($state,array('delivered','read'),true)) $d['state']=$state;
            self::update($claim['event_id'],$d);return $d;
        });
        return self::public_result($data);
    }
    private static function public_result($d) { return array_intersect_key($d,array_flip(array('request_id','state','provider_id','error','updated_at'))); }
    /** Called inside the existing CRM lock, after signed/source-scoped verification. */
    public static function transport_event($record,$e) {
        global $wpdb;
        $mid=$e['message_id'] ?? ''; if (!$mid) return;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,payload FROM '.GE_CRM::table('events').' WHERE organization_id=%s AND event_type=%s AND JSON_UNQUOTE(JSON_EXTRACT(payload,%s))=%s',GE_CRM::org(),self::TYPE,'$.provider_id',$mid),ARRAY_A);
        foreach ($rows as $row) {
            $d=json_decode($row['payload'],true);
            if (($d['account_ref'] ?? '')!==($e['account_ref'] ?? '') || ($d['channel'] ?? '')!==($e['channel'] ?? 'whatsapp')) continue;
            $next=$e['status'] ?? ''; $rank=array('accepted'=>1,'sent'=>1,'delivered'=>2,'read'=>3);
            if ($next==='failed') { if (($rank[$d['state']] ?? 0)>=2) continue; $d['state']='failed';$d['error']='El canal informó un fallo de entrega.'; }
            elseif (isset($rank[$next]) && $rank[$next]>($rank[$d['state']] ?? 0)) $d['state']=$next;
            else continue;
            $d['updated_at']=gmdate('c');self::update($row['id'],$d);
        }
    }
    public static function routes() {
        register_rest_route('graphex-crm/v1','/reply',array('methods'=>'POST','permission_callback'=>function(){return GE_CRM::can(true) ?: new WP_Error('crm_forbidden','Acceso denegado.',array('status'=>403));},'callback'=>function($req){
            try { return self::send($req->get_json_params() ?: array()); }
            catch(Throwable $e) { return new WP_Error('crm_reply',$e->getMessage(),array('status'=>in_array($e->getCode(),array(403,404,409,422),true) ? $e->getCode() : 500)); }
        }));
    }
    public static function render($r) {
        if (($r['kind'] ?? '')!=='thread' || GE_CRM::org()!=='graph-express') return;
        GE_CRM::require_access(); $messages=array();
        foreach (self::incoming($r) as $in) { $e=$in['meta_event'] ?? $in['attention_event'] ?? array();if (!$e) continue;$messages[]=array('time'=>$e['timestamp'] ?? $e['received_at'] ?? $in['created_at'],'label'=>'Recibido','body'=>$e['body'] ?? '','state'=>'','out'=>false); }
        foreach (self::history($r) as $out) { $d=$out['details'];$messages[]=array('time'=>$out['created_at'].'Z','label'=>'Graphex','body'=>$d['text'] ?? '','state'=>$d['state'] ?? 'unknown','out'=>true); }
        usort($messages,function($a,$b){return strtotime($a['time'])<=>strtotime($b['time']);});
        $labels=array('sending'=>'Enviando / pendiente de confirmar','accepted'=>'Aceptado por el canal · entrega pendiente','sent'=>'Aceptado por el canal · entrega pendiente','delivered'=>'Entregado','read'=>'Leído','failed'=>'Falló el envío','unknown'=>'Resultado sin confirmar · revisar antes de reenviar','simulated'=>'Simulado en pruebas');
        echo '<section class="ge-crm-panel ge-crm-chat"><h2>Conversación · '.esc_html(ucfirst($r['channel'])).'</h2><ol class="ge-crm-chat-messages">';
        foreach ($messages as $m) echo '<li class="'.($m['out']?'is-outgoing':'is-incoming').'"><strong>'.esc_html($m['label']).'</strong><p>'.nl2br(esc_html($m['body'])).'</p><small>'.esc_html($m['time']).($m['state']?' · '.esc_html($labels[$m['state']] ?? $m['state']):'').'</small></li>';
        echo '</ol>';
        if (!GE_CRM::can(true)) {echo '</section>';return;}
        $reason='';try { GE_CRM_Send_Policy::plan(self::latest($r),self::config(),'Verificación de disponibilidad',time()); } catch(Throwable $e){$reason=$e->getMessage();}
        $draft=$r['suggested_reply'] ?? '';
        try { if (class_exists('GE_CRM_Agent')) {$call=GE_CRM_Agent::budget()->latest(GE_CRM::org(),self::conversation($r));if (!empty($call['draft']['respuesta'])) $draft=$call['draft']['respuesta'];} } catch(Throwable $ignore) {}
        $limit=$r['channel']==='instagram'?1000:($r['channel']==='messenger'?2000:4096);
        echo '<form data-ge-crm-reply data-record="'.(int)$r['id'].'" data-request="'.esc_attr(wp_generate_uuid4()).'" data-storage="'.esc_attr(hash('sha256',get_current_user_id().':'.self::conversation($r))).'"><label for="crm-reply-text">Tu respuesta<textarea id="crm-reply-text" name="text" rows="5" maxlength="'.$limit.'" required>'.esc_textarea($draft).'</textarea></label><p>Revisá el texto antes de enviar. Se responde al contacto original por '.esc_html(ucfirst($r['channel'])).'.</p>';
        if ($reason) echo '<p class="ge-crm-reply-blocker" role="status">'.esc_html($reason).'</p>';
        echo '<button type="submit" class="ge-crm-primary" '.($reason?'disabled':'').'>Enviar respuesta</button><p data-ge-reply-feedback role="status" aria-live="polite"></p></form></section>';
    }
    public static function assets() {
        if (!is_page('gestion') || ($_GET['section'] ?? '')!=='crm' || !GE_CRM::can()) return;
        echo '<script>window.geCRMReply='.wp_json_encode(array('endpoint'=>rest_url('graphex-crm/v1/reply'),'nonce'=>wp_create_nonce('wp_rest'))).';</script><script>'.file_get_contents(__DIR__.'/reply.js').'</script><style>'.file_get_contents(__DIR__.'/reply.css').'</style>';
    }
}
