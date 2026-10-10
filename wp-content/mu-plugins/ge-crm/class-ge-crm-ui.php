<?php
defined('ABSPATH') || exit;
final class GE_CRM_UI {
    public static function init() {
        add_action('template_redirect',array(__CLASS__,'guard'),-101);
        add_filter('template_include',array(__CLASS__,'template'),200);
        add_action('wp_head',array(__CLASS__,'assets'));
        add_action('wp_footer',array(__CLASS__,'extensions'),80);
    }
    public static function guard() {
        if(!is_page('gestion') || ($_GET['section']??'')!=='crm')return;
        if(!GE_CRM::can())wp_die('No tenés acceso al CRM.','',array('response'=>403));
        // CRM validates its membership/module/roles itself. The legacy mapper has no CRM domain.
        if(class_exists('GE_Organization_Runtime'))remove_action('template_redirect',array('GE_Organization_Runtime','guard_page'),-100);
    }
    public static function template($file) {
        if(!is_page('gestion') || ($_GET['section']??'')!=='crm' || !GE_CRM::can())return $file;
        $GLOBALS['ge_crm_original_template']=$file;return __DIR__.'/shell.php';
    }
    public static function assets() {
        if(!is_page('gestion') || !GE_CRM::can())return;
        echo '<style>'.file_get_contents(__DIR__.'/crm.css').'</style>';
    }
    public static function url($view='pipeline',$extra=array()) { return GE_WTP_Staff_Portal::portal_url('crm',array_merge(array('view'=>$view),$extra)); }
    public static function form($op='save',$kind='',$r=array(),$view='pipeline') {
        echo '<form class="ge-crm-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('graphex_crm');
        foreach(array('action'=>'graphex_crm','operation'=>$op,'kind'=>$kind,'id'=>$r['id']??0,'revision'=>$r['revision']??0,'return_view'=>$view) as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
    }
    public static function input($name,$label,$r,$type='text',$required=false) {
        $limits=$name==='estimated_value'?' min="0" step="0.01"':($name==='probability'?' min="0" max="100" step="1"':'');
        echo '<label><span>'.esc_html($label).'</span><input name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($r[$name]??'').'"'.($required?' required':'').$limits.' maxlength="'.($name==='title'?200:4000).'"></label>';
    }
    public static function select($name,$label,$options,$value='') {
        echo '<label><span>'.esc_html($label).'</span><select name="'.esc_attr($name).'">';foreach($options as $k=>$v)echo '<option value="'.esc_attr($k).'"'.selected((string)$k,(string)$value,false).'>'.esc_html($v).'</option>';echo '</select></label>';
    }
    public static function owners() { $o=GE_Organization::get(GE_CRM::org());$out=array();foreach($o['members'] as $uid=>$role){$u=get_userdata($uid);if($u)$out[$uid]=$u->display_name;}return $out; }
    public static function editor($kind,$r=array()) {
        if(!GE_CRM::can(true))return;
        $defaults=array('customer_id'=>absint($_GET['customer_id']??0),'lead_id'=>absint($_GET['lead_id']??0),'opportunity_id'=>absint($_GET['opportunity_id']??0),'owner_id'=>get_current_user_id());$r=array_merge($defaults,$r);
        $view=$kind==='lead'?'leads':($kind==='task'?'tasks':'pipeline');
        self::form('save',$kind,$r,$view);echo '<h2>'.($r['id']??0?'Editar':'Crear').' '.esc_html(array('lead'=>'lead','opportunity'=>'oportunidad','task'=>'tarea','thread'=>'conversación')[$kind]).'</h2><div class="ge-crm-fields">';
        self::input('title','Título / nombre',$r,'text',true);self::select('owner_id','Responsable',self::owners(),$r['owner_id']);
        if($kind==='lead') {
            foreach(array('company'=>'Empresa','email'=>'Email','phone'=>'Teléfono / WhatsApp','cuit'=>'CUIT','tags'=>'Etiquetas') as $k=>$label)self::input($k,$label,$r,$k==='email'?'email':'text');
            self::select('status','Estado',array('new'=>'Nuevo','contacted'=>'Contactado','qualified'=>'Calificado','discarded'=>'Descartado','converted'=>'Convertido'),$r['status']??'new');
        } else {
            $opts=array(''=>'Sin cliente');foreach(GE_CRM::customer_options() as $c)$opts[$c['id']]=$c['name'].' · '.$c['email'];self::select('customer_id','Cliente existente',$opts,$r['customer_id']);
            $opts=array(''=>'Sin lead');foreach(GE_CRM::records('lead') as $l)$opts[$l['id']]=$l['title'];self::select('lead_id','Lead',$opts,$r['lead_id']);
            $quotes=array(''=>'Sin presupuesto');foreach(get_posts(array('post_type'=>'ge_commercial_quote','post_status'=>'private','numberposts'=>100)) as $p)if(GE_CRM::scope('quote',$p->ID))$quotes[$p->ID]=class_exists('GE_WTP_Gestion_V3')?GE_WTP_Gestion_V3::quote_number($p->ID):$p->post_title;self::select('quote_id','Presupuesto existente',$quotes,$r['quote_id']??0);
            $orders=array(''=>'Sin pedido');foreach(wc_get_orders(array('limit'=>100)) as $o)if(GE_CRM::scope('order',$o->get_id()))$orders[$o->get_id()]=$o->get_order_number();self::select('order_id','Pedido existente',$orders,$r['order_id']??0);
        }
        if($kind==='opportunity') {
            self::input('estimated_value','Valor estimado',$r,'number');self::input('probability','Probabilidad (%)',$r,'number');self::input('expected_close_date','Cierre esperado',$r,'date');self::input('next_action','Próxima acción',$r);
            self::select('stage','Etapa',GE_CRM::config()['stages'],$r['stage']??'new');
        }
        if($kind==='task') {
            self::input('due_date','Vencimiento',$r,'date');self::select('priority','Prioridad',array('low'=>'Baja','normal'=>'Normal','high'=>'Alta'),$r['priority']??'normal');self::select('status','Estado',array('open'=>'Abierta','done'=>'Hecha','cancelled'=>'Cancelada'),$r['status']??'open');
            $opts=array(''=>'Sin oportunidad');foreach(GE_CRM::records('opportunity') as $op)$opts[$op['id']]=$op['title'];self::select('opportunity_id','Oportunidad',$opts,$r['opportunity_id']);
        }
        if($kind==='thread'){self::input('email','Email de contacto',$r,'email');self::input('phone','Teléfono / WhatsApp de contacto',$r,'tel');self::input('external_id','ID del mensaje (opcional, evita duplicados)',$r);}
        if($kind!=='task')self::select('source','Origen',array('manual'=>'Manual','whatsapp'=>'WhatsApp','web'=>'Web / landing','portal'=>'Portal','email'=>'Email','csv'=>'CSV','quote_request'=>'Solicitud de presupuesto'),$r['source']??'manual');
        if($kind==='thread') {self::select('channel','Canal',array('email'=>'Email','portal'=>'Portal','whatsapp'=>'WhatsApp','instagram'=>'Instagram','messenger'=>'Messenger'),$r['channel']??'whatsapp');$intents=array(''=>'Sin sugerencia');foreach(GE_CRM::replies() as $reply)if(!empty($reply['active']))$intents[$reply['intent']]=$reply['trigger'].' · '.$reply['category'];self::select('intent','Intención / respuesta rápida',$intents,$r['intent']??'');self::select('status','Revisión manual',array('needs_review'=>'Pendiente de revisión','approved_pending_send'=>'Borrador revisado, envío pendiente','closed'=>'Cerrada'),$r['status']??'needs_review');self::input('communication_id','ID comunicación existente',$r,'number');echo '<label>Sugerencia de respuesta<textarea name="suggested_reply" rows="4">'.esc_textarea($r['suggested_reply']??'').'</textarea></label><p>Al guardar se sugiere la respuesta seleccionada. Completá sus variables y revisala antes de marcar el borrador. Guardar el borrador no lo envía. Para responder, usá el cuadro de conversación y pulsá Enviar respuesta cuando el canal esté conectado.</p>';}
        echo '</div><label><span>Notas</span><textarea name="notes" rows="3">'.esc_textarea($r['notes']??'').'</textarea></label><button class="ge-crm-primary">Guardar</button></form>';
    }
    public static function render() {
        GE_CRM::require_access();$view=sanitize_key($_GET['view']??'pipeline');$q=sanitize_text_field(wp_unslash($_GET['q']??''));
        echo '<section class="ge-crm"><header class="ge-crm-heading"><div><span>RELACIONES COMERCIALES</span><h1>CRM</h1><p>Del primer contacto al próximo trabajo.</p></div><a class="ge-crm-primary" href="'.esc_url(self::url('leads',array('new'=>'lead'))).'">Nuevo lead</a></header>';
        if(isset($_GET['saved']))echo '<p role="status" class="ge-crm-notice">Cambios guardados.</p>';
        echo '<nav class="ge-crm-tabs" aria-label="Vistas CRM">';foreach(array('dashboard'=>'Resumen','pipeline'=>'Pipeline','leads'=>'Leads','tasks'=>'Tareas','activity'=>'Actividad','inbox'=>'Conversaciones','origin'=>'Origen','settings'=>'Ajustes') as $k=>$label)echo '<a href="'.esc_url(self::url($k)).'"'.($k===$view?' aria-current="page"':'').'>'.esc_html($label).'</a>';echo '</nav>';
        if(isset($_GET['record_id'])){$r=GE_CRM::get(absint($_GET['record_id']));self::detail($r);echo '</section>';return;}
        if(isset($_GET['new']) && in_array($_GET['new'],array('lead','opportunity','task','thread'),true)){self::editor($_GET['new']);echo '</section>';return;}
        if($view==='pipeline')self::pipeline($q);
        elseif($view==='leads'||$view==='tasks'){echo '<div class="ge-crm-toolbar"><form method="get"><input type="hidden" name="section" value="crm"><input type="hidden" name="view" value="'.esc_attr($view).'"><label>Buscar<input name="q" type="search" value="'.esc_attr($q).'" placeholder="Nombre, email o teléfono"></label><button>Buscar</button></form><a href="'.esc_url(self::url($view,array('new'=>$view==='leads'?'lead':'task'))).'">Crear '.($view==='leads'?'lead':'tarea').'</a></div>';self::list_records(GE_CRM::records($view==='leads'?'lead':'task',0,$q));}
        elseif($view==='activity')self::events(GE_CRM::timeline());
        elseif($view==='inbox')self::inbox();
        elseif($view==='origin')GE_CRM_Origin::render();
        elseif($view==='settings')self::settings();
        else self::dashboard();echo '</section>';
    }
    public static function list_records($rows) {
        if(!$rows){echo '<div class="ge-crm-empty">Todavía no hay registros en esta vista.</div>';return;}
        echo '<div class="ge-crm-list">';foreach($rows as $r){$due=$r['kind']==='task'&&$r['status']==='open'&&$r['due_date']&&$r['due_date']<wp_date('Y-m-d');echo '<a class="ge-crm-row" href="'.esc_url(self::url($r['kind']==='lead'?'leads':($r['kind']==='thread'?'inbox':'tasks'),array('record_id'=>$r['id']))).'"><div><strong>'.esc_html($r['title']).'</strong><small>'.esc_html($r['email']?:($r['next_action']??$r['notes']??'')).'</small></div><span class="ge-crm-badge">'.esc_html(isset($r['attention_state']) ? GE_CRM_Attention::label( $r ) : ($due?'Vencida':($r['stage']?GE_CRM::config()['stages'][$r['stage']]:$r['status']))).'</span><small>'.esc_html($r['due_date']).'</small></a>'; }echo '</div>';
    }
    public static function pipeline($q='') {
        echo '<div class="ge-crm-toolbar"><div><strong>Pipeline comercial</strong><small>Mové una tarjeta o elegí su etapa.</small></div><div><a href="'.esc_url(self::url('pipeline',array('new'=>'opportunity'))).'">Nueva oportunidad</a> · <a href="'.esc_url(self::url('pipeline',array('layout'=>($_GET['layout']??'')==='list'?'kanban':'list'))).'">'.(($_GET['layout']??'')==='list'?'Ver Kanban':'Ver lista').'</a></div></div>';
        $rows=GE_CRM::records('opportunity',0,$q,500);if(($_GET['layout']??'')==='list'){self::list_records($rows);return;}
        echo '<div class="ge-crm-board">';foreach(GE_CRM::config()['stages'] as $stage=>$label){$cards=array_values(array_filter($rows,function($r)use($stage){return $r['stage']===$stage;}));echo '<section class="ge-crm-column" data-stage="'.esc_attr($stage).'"><h2>'.esc_html($label).'<span>'.count($cards).'</span></h2><div class="ge-crm-cards">';foreach($cards as $r){echo '<article class="ge-crm-card" draggable="'.(GE_CRM::can(true)?'true':'false').'" data-record="'.esc_attr(wp_json_encode($r)).'"><a href="'.esc_url(self::url('pipeline',array('record_id'=>$r['id']))).'"><strong>'.esc_html($r['title']).'</strong></a><b>'.esc_html(number_format_i18n((float)($r['estimated_value']??0),2)).' <small>'.esc_html(GE_Organization::get(GE_CRM::org())['settings']['general']['currency']??'ARS').'</small></b><p>'.esc_html($r['next_action']??'').'</p><small>'.esc_html(self::owners()[$r['owner_id']]??'').'</small>';
            if(GE_CRM::can(true)){self::form('save','opportunity',$r);foreach(array('title','customer_id','lead_id','quote_id','order_id','owner_id','estimated_value') as $k)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($r[$k]??'').'">';self::select('stage','Mover a',GE_CRM::config()['stages'],$stage);echo '<button>Aplicar</button></form>';}
            echo '</article>'; }if(!$cards)echo '<p class="ge-crm-column-empty">Sin oportunidades</p>';echo '</div></section>'; }echo '</div><p id="ge-crm-status" role="status" aria-live="polite"></p>';
    }
    public static function detail($r) {
        do_action('ge_crm_thread_agent', $r);
        if(GE_CRM_Origin::enabled())GE_CRM_Origin::render_record($r);
        if ( isset( $r['attention_event'] ) ) { GE_CRM_Attention::render_message( $r ); }
        if (class_exists('GE_Meta_Social')) GE_Meta_Social::render_message($r);
        if ( ! empty( $r['attention_automation_notes'] ) ) { echo '<p>' . esc_html( $r['attention_automation_notes'] ) . '</p>'; }
        if ( ! empty( $r['thread_id'] ) ) { echo '<p><a href="' . esc_url( self::url( 'inbox', array( 'record_id' => $r['thread_id'] ) ) ) . '">Abrir conversación de origen →</a></p>'; }
        echo '<div class="ge-crm-detail"><section><h2>'.esc_html($r['title']).'</h2>';
        if($r['customer_id'])echo '<a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('customers',array('customer_id'=>$r['customer_id']))).'">Abrir ficha del cliente →</a>';
        if($r['quote_id'])echo '<p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$r['quote_id']))).'">Abrir presupuesto vinculado</a></p>';
        if($r['order_id'])echo '<p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$r['order_id']))).'">Abrir pedido vinculado</a></p>';
        if($r['kind']==='lead') {
            echo '<p><a href="'.esc_url(self::url('pipeline',array('new'=>'opportunity','lead_id'=>$r['id']))).'">Crear oportunidad desde este lead</a></p>';
            if(!$r['customer_id'] && GE_CRM::can(true)){$matches=GE_CRM::match($r);echo '<details class="ge-crm-panel"><summary>Convertir a cliente</summary><p>Se reutiliza una ficha coincidente. La invitación al portal se envía después desde Clientes.</p>';self::form('convert','lead',$r,'leads');if($matches){$opts=array(''=>'Elegí una coincidencia');foreach($matches as $m)$opts[$m['id']]=$m['name'].' · '.$m['email'];self::select('selected_customer_id','Revisar coincidencias',$opts);}else echo '<p>No se encontraron coincidencias por email, teléfono o CUIT.</p>';echo '<button>Convertir / vincular ficha</button></form></details>';}
        }
        echo '<p><a href="'.esc_url(self::url('tasks',array('new'=>'task','opportunity_id'=>$r['kind']==='opportunity'?$r['id']:0,'lead_id'=>$r['kind']==='lead'?$r['id']:0,'customer_id'=>$r['customer_id']))).'">Crear seguimiento</a></p>';self::editor($r['kind'],$r);echo '</section><section class="ge-crm-panel"><h2>Historial</h2>';self::events(GE_CRM::timeline(0,$r['id']));
        if(GE_CRM::can(true)){self::form('note');echo '<input type="hidden" name="record_id" value="'.$r['id'].'">';self::select('type','Actividad',array('note'=>'Nota','call'=>'Llamada','meeting'=>'Reunión','portal'=>'Evento del portal'));echo '<label>Detalle<textarea name="notes" required rows="3"></textarea></label><button>Registrar actividad</button></form>';}
        echo '</section></div>';
    }
    public static function events($events) {
        if(!$events){echo '<p class="ge-crm-empty">Sin actividad registrada.</p>';return;}
        echo '<ol class="ge-crm-timeline">';foreach($events as $e){$d=$e['details']??array();$label=$e['label']??($d['notes']??($d['after']['title']??$e['event_type']));echo '<li><span class="ge-crm-event-type">'.esc_html($e['event_type']).'</span><strong>'.esc_html($label).'</strong><time>'.esc_html($e['created_at']).' UTC</time>';if(isset($d['before']['stage'],$d['after']['stage'])&&$d['before']['stage']!==$d['after']['stage'])echo '<small>'.esc_html($d['before']['stage'].' → '.$d['after']['stage']).'</small>';if(!empty($e['url']))echo '<a href="'.esc_url($e['url']).'">Abrir</a>';echo '</li>'; }echo '</ol>';
    }
    public static function dashboard() {
        $rows=GE_CRM::records('',0,'',500);$today=wp_date('Y-m-d');$leads=array_filter($rows,function($r){return $r['kind']==='lead'&&$r['status']==='new';});$ops=array_filter($rows,function($r){return $r['kind']==='opportunity'&&$r['status']==='open';});$tasks=array_filter($rows,function($r)use($today){return $r['kind']==='task'&&$r['status']==='open'&&$r['due_date']&&$r['due_date']<=$today;});
        global $wpdb;$totals=array();foreach(array('Leads nuevos'=>array('lead','new'),'Oportunidades abiertas'=>array('opportunity','open')) as $label=>$filter)$totals[$label]=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.GE_CRM::table().' WHERE organization_id=%s AND kind=%s AND status=%s',GE_CRM::org(),$filter[0],$filter[1]));$totals['Tareas hoy / vencidas']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.GE_CRM::table().' WHERE organization_id=%s AND kind=%s AND status=%s AND due_date<>%s AND due_date<=%s',GE_CRM::org(),'task','open','',$today));
        echo '<div class="ge-crm-metrics">';foreach($totals as $label=>$n)echo '<div><span>'.esc_html($label).'</span><strong>'.$n.'</strong></div>';echo '</div><div class="ge-crm-detail"><section class="ge-crm-panel"><h2>Seguimientos prioritarios</h2>';self::list_records(array_values($tasks));echo '</section><section class="ge-crm-panel"><h2>Actividad reciente</h2>';self::events(array_slice(GE_CRM::timeline(),0,10));echo '</section></div>';
        $pending=array_values(array_filter($ops,function($r){return $r['quote_id'] && in_array(get_post_meta($r['quote_id'],'_ge_commercial_status',true),array('sent','viewed'),true);}));$inactive=array_values(array_filter($ops,function($r){return strtotime($r['updated_at'].' UTC')<time()-GE_CRM::config()['inactivity_days']*DAY_IN_SECONDS;}));
        echo '<div class="ge-crm-detail"><section class="ge-crm-panel"><h2>Presupuestos enviados · revisar seguimiento</h2>';self::list_records($pending);echo '</section><section class="ge-crm-panel"><h2>Oportunidades sin actividad reciente</h2>';self::list_records($inactive);echo '</section></div><section class="ge-crm-panel"><h2>Oportunidades por etapa</h2><div class="ge-crm-tabs">';foreach(GE_CRM::config()['stages'] as $key=>$label){$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.GE_CRM::table().' WHERE organization_id=%s AND kind=%s AND stage=%s',GE_CRM::org(),'opportunity',$key));echo '<span>'.esc_html($label).' · '.$count.'</span>'; }echo '</div></section>';
    }
    public static function customer360($id) {
        if(!GE_CRM::scope('customer',$id))return;
        $ops=GE_CRM::records('opportunity',$id);$tasks=array_values(array_filter(GE_CRM::records('task',$id),function($t){return $t['status']==='open';}));$events=GE_CRM::timeline($id);
        echo '<section id="ge-crm-customer360" class="ge-crm ge-crm-panel"><div class="ge-crm-toolbar"><h2>Relación comercial · 360°</h2><a href="'.esc_url(self::url('pipeline',array('new'=>'opportunity','customer_id'=>$id))).'">Nueva oportunidad</a></div><div class="ge-crm-metrics"><div><span>Oportunidades</span><strong>'.count($ops).'</strong></div><div><span>Tareas abiertas</span><strong>'.count($tasks).'</strong></div><div><span>Última actividad</span><b>'.esc_html($events[0]['created_at']??'Sin contacto registrado').'</b></div></div>';self::list_records($ops);self::list_records($tasks);echo '<details><summary>Historial unificado: presupuestos, pedidos, pagos, documentos y comunicaciones</summary>';self::events($events);echo '</details></section>';
    }
    public static function inbox() {
        GE_CRM_Attention::render_status();
        do_action('ge_crm_inbox_channel_details');
        echo '<div class="ge-crm-toolbar"><p>Email y canales Meta · WhatsApp preparado para conexión.</p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('communications')).'">Abrir Comunicaciones →</a></div><a href="'.esc_url(self::url('inbox',array('new'=>'thread'))).'">Registrar conversación para revisión</a>';$page = max( 1, absint( $_GET['inbox_page'] ?? 1 ) );
        $rows = GE_CRM::records( 'thread', 0, '', 51, ( $page - 1 ) * 50 );
        $next = count( $rows ) > 50;
        self::list_records( array_slice( $rows, 0, 50 ) );
        echo '<nav aria-label="Páginas de conversaciones" class="ge-crm-tabs">';
        if ( $page > 1 ) { echo '<a href="' . esc_url( self::url( 'inbox', array( 'inbox_page' => $page - 1 ) ) ) . '">← Anteriores</a>'; }
        echo '<span>Página ' . (int) $page . '</span>';
        if ( $next ) { echo '<a href="' . esc_url( self::url( 'inbox', array( 'inbox_page' => $page + 1 ) ) ) . '">Siguientes →</a>'; }
        echo '</nav>';
        echo '<details class="ge-crm-panel"><summary>Historial de correos enviados</summary>';foreach(GE_WTP_Notifications::get_logs(50) as $p)if(GE_CRM::mail_scope($p->ID)){echo '<div class="ge-crm-row"><div><strong>'.esc_html($p->post_title).'</strong><small>'.esc_html(get_post_meta($p->ID,'_ge_email_to',true)).'</small></div><small>'.esc_html(get_post_meta($p->ID,'_ge_email_result',true)).'</small></div>';}echo '</details><p>Las sugerencias se guardan como borrador. Los envíos y la recepción dependen del transporte verificado y de la política de cada canal.</p>';
    }
    public static function settings() {
        if(!in_array(GE_CRM::role(get_current_user_id()),array('owner','admin'),true)){echo '<p>Los ajustes los administra el owner o admin.</p>';return;}
        $cfg=GE_CRM::config();self::form('configure');echo '<h2>Etapas por organización</h2><p>Se conservan los identificadores de entrada, ganado y perdido. Agregá hasta 12 etapas. Para quitar una etapa sin oportunidades, dejá su nombre vacío.</p><div class="ge-crm-fields">';foreach($cfg['stages'] as $key=>$label)echo '<label>'.esc_html($key).'<input name="stages['.esc_attr($key).']" value="'.esc_attr($label).'"></label>';self::input('new_stage_key','Nueva etapa: identificador (ej. diseno)',array());self::input('new_stage_label','Nueva etapa: nombre visible',array());self::input('followup_days','Seguimiento tras días',$cfg,'number');self::input('inactivity_days','Inactividad tras días',$cfg,'number');echo '</div><button>Guardar ajustes</button></form>';
        self::form('run_automations');echo '<p>Automatizaciones internas e idempotentes; sólo generan tareas.</p><button>Revisar seguimientos ahora</button></form>';
        foreach(array('lead'=>'Leads','opportunity'=>'Oportunidades') as $kind=>$label){self::form('export',$kind);echo '<button>Exportar '.esc_html($label).' CSV</button></form>';}
        self::form('import');echo '<h2>Importar leads CSV</h2><p>Cabecera: title,company,email,phone,cuit,source,tags,notes. Hasta 200 filas. La importación no crea clientes; se revisan coincidencias al convertir.</p><label>CSV<textarea name="csv" rows="5" required></textarea></label><input type="hidden" name="commit" value="1"><button>Importar leads</button></form>';
    }
    public static function extensions() {
        if(!is_page('gestion') || !GE_CRM::can())return;
        $section=$_GET['section']??'';
        echo '<a id="ge-crm-nav" href="'.esc_url(self::url()).'">'.(class_exists('GE_WTP_Gestion_V3')?GE_WTP_Gestion_V3::icon('customers'):'').'<span>CRM</span></a><script>var n=document.querySelector(".ge-v3-nav"),a=document.getElementById("ge-crm-nav");if(n&&a){n.insertBefore(a,n.children[1]);'.($section==='crm'?'n.querySelectorAll(".is-active").forEach(x=>x.classList.remove("is-active"));a.classList.add("is-active");a.setAttribute("aria-current","page");':'').'}</script>';
        if($section==='customers' && !empty($_GET['customer_id'])){self::customer360(absint($_GET['customer_id']));echo '<script>var c=document.getElementById("ge-crm-customer360"),h=document.querySelector(".ge-workspace-header");if(c&&h)h.insertAdjacentElement("afterend",c);</script>';}
        if(!empty($_GET['global_q'])) {$q=sanitize_text_field(wp_unslash($_GET['global_q']));echo '<section id="ge-crm-search" class="ge-crm ge-crm-panel"><h2>CRM · Leads y oportunidades</h2>';self::list_records(array_filter(GE_CRM::records('',0,$q,20),function($r){return in_array($r['kind'],array('lead','opportunity'),true);}));echo '</section><script>var c=document.getElementById("ge-crm-search"),h=document.querySelector(".ge-v3-search-results");if(c&&h)h.appendChild(c);</script>';}
        if($section==='crm')echo '<script>window.geCRM='.wp_json_encode(array('endpoint'=>rest_url('graphex-crm/v1/command'),'nonce'=>wp_create_nonce('wp_rest'))).';</script><script>'.file_get_contents(__DIR__.'/crm.js').'</script>';
    }
}

