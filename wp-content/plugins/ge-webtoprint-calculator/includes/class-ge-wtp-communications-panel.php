<?php
if (!defined('ABSPATH')) { exit; }

/** Reads the native newsletter ledger; counts never imply confirmed delivery. */
final class GE_WTP_Communications_Panel {
    public static function url($view, $args=array()) {
        return GE_WTP_Staff_Portal::portal_url('communications',array_merge(array('subsection'=>$view),$args));
    }
    public static function navigation($view) {
        $main=array('contacts'=>array('Contactos','Ordená y depurá la base.'),'audiences'=>array('Audiencias','Elegí quiénes reciben.'),'templates'=>array('Plantillas','Prepará el contenido.'),'campaigns'=>array('Campañas','Enviá y medí.'));
        echo '<nav class="ge-communications-steps" aria-label="Preparar comunicaciones">'; $step=0;
        foreach($main as $key=>$label) {
            echo '<a '.($view===$key?'aria-current="page"':'').' href="'.esc_url(self::url($key)).'"><small>PASO '.(++$step).'</small><strong>'.esc_html($label[0]).'</strong><span>'.esc_html($label[1]).'</span></a>';
        }
        echo '</nav><nav class="ge-staff-tabs" aria-label="Seguimiento de comunicaciones">';
        foreach(array('engine'=>'Estado del motor','emails'=>'Historial de correos','metrics'=>'Métricas','diagnostics'=>'Diagnóstico') as $key=>$label) {
            echo '<a class="'.($key===$view?'is-active':'').'" '.($key===$view?'aria-current="page"':'').' href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a>';
        }
        echo '</nav><style>.ge-communications-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:20px 0}.ge-communications-steps a{display:flex;flex-direction:column;gap:6px;padding:18px;border:1px solid var(--ge-border,#ddd);border-radius:12px;color:inherit;text-decoration:none;background:var(--ge-surface,#fff)}.ge-communications-steps a[aria-current]{border-color:var(--ge-primary,#6242df);box-shadow:inset 0 0 0 1px var(--ge-primary,#6242df)}.ge-communications-steps strong{font-size:18px}.ge-communications-steps small,.ge-communications-steps span{font-size:13px}.ge-communications-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.ge-communications-stats article{padding:18px;border:1px solid var(--ge-border,#ddd);border-radius:12px}.ge-communications-stats strong{display:block;font-size:28px}.ge-communications-table{width:100%;border-collapse:collapse}.ge-communications-table th,.ge-communications-table td{text-align:left;padding:12px;border-bottom:1px solid var(--ge-border,#ddd)}@media(max-width:760px){.ge-communications-steps{grid-template-columns:repeat(2,minmax(0,1fr))}.ge-communications-steps a{padding:12px}.ge-communications-table-wrap{overflow:auto}}</style>';
    }
    private static function table($suffix){global $wpdb;return $wpdb->prefix.'ge_newsletter_'.$suffix;}
    private static function stats($values){echo '<div class="ge-communications-stats">';foreach($values as $label=>$value){echo '<article><span>'.esc_html($label).'</span><strong>'.esc_html(number_format_i18n($value)).'</strong></article>';}echo '</div>';}
    public static function render($view) {
        global $wpdb;
        if(!GE_WTP_Newsletter_Review::allowed())return;
        if(!GE_WTP_Newsletter_Audiences::ensure_schema()){echo '<p role="alert">No se pudo verificar la base de contactos.</p>';return;}
        if(!GE_WTP_Newsletter_Engine::ensure_schema()){echo '<p role="alert">No se pudo preparar el registro de envíos.</p>';return;}
        if($view==='audiences') {
            echo '<section class="ge-admin-panel"><h2>Audiencias</h2><p>Elegí un grupo completo. Las bajas y los rebotes quedan excluidos al enviar.</p>';
            foreach(GE_WTP_Campaign_Actions::audiences() as $key=>$label){
                $where=$key==='all_subscribed'?"status='subscribed'":$wpdb->prepare('audience=%s',$key);
                $rows=$wpdb->get_results('SELECT status,COUNT(*) AS total FROM '.self::table('contacts').' WHERE '.$where.' GROUP BY status');
                $total=0;$ready=0;$blocked=0;$prospects=0;foreach($rows as $r){$total+=(int)$r->total;if($r->status==='subscribed')$ready+=(int)$r->total;elseif($r->status==='prospect')$prospects+=(int)$r->total;else $blocked+=(int)$r->total;}
                echo '<article class="ge-admin-panel"><h3>'.esc_html($label).'</h3>';self::stats(array('Contactos'=>$total,'Suscriptores'=>$ready,'Posibles suscriptores'=>$prospects,'Excluidos'=>$blocked));
                echo '<p><a class="button" href="'.esc_url(self::url('contacts',array('audience'=>$key==='all_subscribed'?'':$key))).'">Ver contactos</a> <a class="button" href="'.esc_url(self::url('campaigns')).'">Usar en una campaña</a></p><details><summary>Procedencia y selección</summary><p>'.esc_html($key==='first'?'Selección del titular: YONIO 1, YONIO 2 y contactos encontrados en el correo. La correspondencia histórica no confirma la entrega de esta campaña.':($key==='second'?'Otros contactos de la agenda: conservan el estado de posibles suscriptores.':'Suscriptores actuales de la base, incluidas las altas del sitio.')).'</p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('crm',array('view'=>'activity'))).'">Consultar actividad en el CRM</a></details></article>';
            }echo '</section>';return;
        }
        $counts=array();foreach($wpdb->get_results('SELECT status,COUNT(*) total FROM '.self::table('deliveries').' GROUP BY status') as $r)$counts[$r->status]=(int)$r->total;
        if($view==='metrics'){
            $accepted=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table('deliveries').' WHERE sent_at IS NOT NULL');$replies=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table('deliveries').' WHERE reply_record_id>0');
            echo '<section class="ge-admin-panel"><h2>Métricas de campañas</h2>';self::stats(array('Aceptados por el correo'=>$accepted,'Pendientes'=>$counts['pending']??0,'Rebotes'=>$counts['bounced']??0,'Respuestas vinculadas'=>$replies,'Sin confirmar'=>$counts['unknown']??0,'Excluidos'=>$counts['suppressed']??0));
            echo '<p>“Aceptado” indica que el transporte recibió el mensaje. Los rebotes y las respuestas requieren evidencia vinculada a cada envío.</p><div class="ge-communications-table-wrap"><table class="ge-communications-table"><thead><tr><th>Campaña</th><th>Estado</th><th>Destinatarios</th><th>Aceptados</th><th>Fallidos</th></tr></thead><tbody>';
            foreach($wpdb->get_results('SELECT id,subject,status,total_count,sent_count,failed_count FROM '.self::table('campaigns')." WHERE status<>'deleted' ORDER BY id DESC LIMIT 100") as $c){echo '<tr><td><a href="'.esc_url(self::url('campaigns',array('newsletter_campaign'=>$c->id))).'">#'.(int)$c->id.' '.esc_html($c->subject).'</a></td><td>'.esc_html(self::status($c->status)).'</td><td>'.(int)$c->total_count.'</td><td>'.(int)$c->sent_count.'</td><td>'.(int)$c->failed_count.'</td></tr>';}
            echo '</tbody></table></div><details><summary>Visitas y respuestas</summary><p>Las respuestas se vinculan cuando el mensaje recibido conserva la referencia de la campaña y coincide con el destinatario. Podés leerlas en el CRM. Los enlaces de la tienda conservan sus etiquetas de origen; una visita anónima no identifica al destinatario. No se mide la lectura del correo.</p></details></section>';return;
        }
        $enabled=class_exists('GE_WTP_Newsletter_Engine')&&GE_WTP_Newsletter_Engine::enabled();
        $last=(array)get_option('ge_newsletter_engine_heartbeat',array());$next=wp_next_scheduled(GE_WTP_Newsletter::CRON_HOOK);
        echo '<section class="ge-admin-panel"><h2>'.($view==='engine'?'Estado del motor':'Diagnóstico').'</h2><p role="status"><strong>'.($enabled?'Motor habilitado':'Envío de campañas desactivado').'</strong></p>';
        $accepted=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table('deliveries').' WHERE sent_at IS NOT NULL');self::stats(array('Aceptados por el correo'=>$accepted,'Pendientes'=>$counts['pending']??0,'En procesamiento'=>$counts['sending']??0,'Resultados sin confirmar'=>$counts['unknown']??0,'Rebotes'=>$counts['bounced']??0));
        $running=!empty($last['at'])&&strtotime($last['at'])>time()-120;
        echo '<dl><dt>Remitente configurado</dt><dd>'.esc_html(GE_WTP_Email_Branding::profile()['email']??'Consultar Configuración → Notificaciones').'</dd><dt>Última ejecución registrada</dt><dd>'.esc_html(!empty($last['at'])?wp_date('d/m/Y H:i:s',strtotime($last['at'])):'Sin ejecución del nuevo motor').'</dd><dt>Procesamiento en el servidor</dt><dd>'.esc_html($running?'Activo · sigue procesando la cola':'Sin ejecución reciente; consultar diagnóstico').'</dd></dl>';
        echo '<details '.($view==='diagnostics'?'open':'').'><summary>Comprobaciones del lanzamiento</summary><ul><li>Audiencia exacta y contenido revisados antes de crear la cola.</li><li>Baja personalizada y exclusión de contactos suprimidos.</li><li>Sin reenvío automático de resultados inciertos.</li><li>La recepción de rebotes y respuestas debe verificarse en el sistema de correo y en el CRM.</li></ul></details><a href="'.esc_url(self::url('emails',array('mail_group'=>'campaigns'))).'">Ver historial de campañas</a></section>';
        $channels=(array)get_option('ge_crm_attention_channels',array());$health=$channels['email']['consumer_health']??array();$feedback=(array)get_option('ge_newsletter_feedback_heartbeat',array());$policy=(array)get_option('ge_newsletter_engine_policy_v1',array());
        echo '<section class="ge-admin-panel"><h2>Recepción y ritmo de envío</h2><p>Último control del correo: '.esc_html($health['checked_at']??'Sin señal registrada').' · '.(int)($health['pending_events']??0).' mensajes pendientes de registrar en el CRM.</p><p>Último control de fallos del servidor: '.esc_html($feedback['at']??'Sin ejecución registrada').'.</p><p>Ritmo máximo de campañas: '.(int)($policy['hourly_limit']??0).' por hora. Los resultados inciertos quedan detenidos para revisar.</p>';
        foreach($wpdb->get_results('SELECT id,subject,status,total_count,sent_count FROM '.self::table('campaigns')." WHERE status IN ('queued','paused') ORDER BY id DESC LIMIT 20") as $c){echo '<article><h3>#'.(int)$c->id.' '.esc_html($c->subject).'</h3><p>'.esc_html(self::status($c->status)).' · '.(int)$c->sent_count.' aceptados de '.(int)$c->total_count.'.</p>';$action=$c->status==='queued'?'pause':'resume';echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_newsletter_engine_'.$action.'"><input type="hidden" name="campaign_id" value="'.(int)$c->id.'">';wp_nonce_field('ge_newsletter_engine_'.$action.'_'.$c->id);echo '<button class="button" type="submit">'.($action==='pause'?'Pausar campaña':'Reanudar campaña').'</button></form>';if($action==='resume')echo '<p>Los intentos sin confirmar bloquean la reanudación hasta que se revisen.</p>';echo '</article>';}
        echo '</section>';
    }
    public static function status($s){return array('draft'=>'Borrador','deleted'=>'Eliminada','queued'=>'En cola','paused'=>'Pausada','sent'=>'Finalizada','sending'=>'En procesamiento','unknown'=>'Sin confirmar','failed'=>'Fallida')[$s]??$s;}
}
