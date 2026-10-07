<?php
defined('ABSPATH') || exit;

/** Organization-scoped templates. Preview never generates credentials, PDF files or mail. */
final class GE_WTP_Email_Templates {
    public static function init() {
        add_action('admin_post_ge_communication_template_save', array(__CLASS__, 'handle_save'));
        add_action('wp_ajax_ge_email_quote_preview', array(__CLASS__, 'preview_quote'));
        add_action('wp_ajax_ge_communication_template_preview', array(__CLASS__, 'preview_template'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }
    private static function org() { return class_exists('GE_Organization') ? GE_Organization::PRIMARY : 'graph-express'; }
    private static function option() { return 'ge_email_templates_v1_' . sanitize_key(self::org()); }
    public static function permitted($write = false) {
        if (!is_user_logged_in()) return false;
        if (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('communications', $write, get_current_user_id())) return false;
        return $write ? (current_user_can('manage_options') || (class_exists('GE_Organization') && GE_Organization::can(self::org(),get_current_user_id(),true))) : (current_user_can('ge_manage_communications') || current_user_can('manage_options'));
    }
    public static function defaults() {
        return array('commercial_quote_sent' => array(
            'title' => 'Presupuesto: enviar y reenviar al cliente', 'group' => 'Presupuestos',
            'trigger' => 'Publicar y enviar al cliente / Volver a enviar aviso', 'recipient' => 'Cliente del presupuesto',
            'subject' => 'Tu presupuesto · {{quote_number}}',
            'body' => '<p>Hola {{customer_name}},</p><p>{{intro}}</p><p><a href="{{portal_url}}">Ver presupuesto {{quote_number}}</a></p>{{summary}}{{first_access}}',
            'placeholders' => array('customer_name','quote_number','intro','portal_url','summary','first_access','brand_name'),
            'revision' => 1, 'editable' => true,
        ));
    }
    private static function stored() { $rows = get_option(self::option(), array()); return is_array($rows) ? $rows : array(); }
    public static function get($id) {
        $defaults = self::defaults(); $stored = self::stored();
        if (isset($defaults[$id])) return array_merge($defaults[$id], $stored[$id] ?? array());
        return isset($stored[$id]) && strpos($id, 'manual_') === 0 ? $stored[$id] : null;
    }
    public static function clean_html($html) {
        $tags = array();
        foreach (array('p','strong','em','br','ul','ol','li','h2','h3','thead','tbody','tr') as $tag) $tags[$tag] = array();
        $tags['a'] = array('href'=>true, 'title'=>true);
        $tags['table'] = array(); $tags['td'] = $tags['th'] = array('colspan'=>true, 'rowspan'=>true);
        return wp_kses((string)$html, $tags, array('https','http'));
    }
    public static function validate($id, $title, $subject, $body, $candidate = null) {
        $record = $candidate ?: self::get($id); if (!$record || empty($record['editable'])) return new WP_Error('template_unknown', 'Plantilla no editable.');
        $title = sanitize_text_field($title); $subject = sanitize_text_field($subject); $body = self::clean_html($body);
        if (!$title || !$subject || !trim(wp_strip_all_tags($body)) || strlen($subject)>500 || strlen($title)>200 || strlen($body)>50000) return new WP_Error('template_invalid', 'Completá título, asunto y contenido dentro de los límites.');
        preg_match_all('/\{\{\s*([^{}]+)\s*\}\}/', $subject . $body, $matches);
        foreach ($matches[1] as $key) if (!in_array(trim($key), $record['placeholders'], true)) return new WP_Error('template_variable', 'Variable no permitida: ' . sanitize_text_field($key));
        $remaining = preg_replace('/\{\{\s*[^{}]+\s*\}\}/', '', $subject . $body);
        if (strpos($remaining, '{{') !== false || strpos($remaining, '}}') !== false) return new WP_Error('template_syntax', 'Revisá las variables entre dobles llaves.');
        if ($id === 'commercial_quote_sent' && strpos($body, '{{portal_url}}') === false) return new WP_Error('template_portal', 'Conservá {{portal_url}} para que el cliente pueda revisar y aceptar el presupuesto.');
        return array('title'=>$title, 'subject'=>$subject, 'body'=>$body);
    }
    public static function render($id, $values, $candidate = null) {
        $record = $candidate ?: self::get($id); if (!$record) return null;
        if ($id === 'commercial_quote_sent' && is_wp_error(self::validate($id,$record['title'],$record['subject'],$record['body'],$record))) $record = self::defaults()[$id];
        $html = $text = array();
        foreach ($record['placeholders'] as $key) {
            $value = (string)($values[$key] ?? '');
            $html[$key] = in_array($key, array('summary','first_access'), true) ? self::clean_html($value) : ($key === 'portal_url' ? esc_url($value) : esc_html($value));
            $text[$key] = sanitize_text_field(wp_strip_all_tags($value));
        }
        $replace = function($content, $map) { return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function($m) use ($map) { return $map[$m[1]] ?? ''; }, $content); };
        return array('subject'=>sanitize_text_field($replace($record['subject'], $text)), 'body'=>self::clean_html($replace($record['body'], $html)), 'revision'=>(int)$record['revision']);
    }
    public static function quote_message($quote, $snapshot, $customer, $resend = false) {
        $invite = !$resend && (empty($customer->ID) || 'yes' === get_user_meta($customer->ID, '_ge_commercial_needs_invite', true));
        $url = !empty($quote['id']) ? GE_WTP_Portal::portal_url('presupuestos', array('presupuesto'=>$quote['id'])) : GE_WTP_Portal::portal_url('presupuestos');
        return self::render('commercial_quote_sent', array(
            'customer_name'=>$customer->first_name ?: $customer->display_name,
            'quote_number'=>$quote['number'] ?? 'BORRADOR', 'portal_url'=>$url,
            'intro'=>$resend ? 'Podés volver a revisar tu presupuesto de Graph Express.' : 'Tenés un nuevo presupuesto de Graph Express para revisar.',
            'summary'=>GE_WTP_Commercial_Quotes::email_summary($snapshot),
            'first_access'=>$invite ? '<p>Si es tu primer acceso, usá “¿Olvidaste tu contraseña?” en el portal para definirla con este email.</p>' : '',
            'brand_name'=>class_exists('GE_Organization') ? GE_Organization::brand('brand_name','Graph Express') : 'Graph Express',
        ));
    }
    public static function attachment_notice($version) { return '<p>Adjuntamos el PDF comercial de tu presupuesto (versión ' . absint($version) . '). Podés revisarlo y aceptarlo desde el portal.</p>'; }
    public static function envelope($message, $to, $version = 0) {
        $message['recipient'] = sanitize_email($to);
        $message['sender'] = array('email'=>apply_filters('wp_mail_from','wordpress@' . wp_parse_url(home_url(),PHP_URL_HOST)), 'name'=>apply_filters('wp_mail_from_name','WordPress'));
        $message['attachment'] = $version ? 'PDF comercial · versión ' . absint($version) : 'PDF comercial de la versión que se guarde';
        if ($version) $message['body'] .= self::attachment_notice($version);
        $message['body'] = self::clean_html($message['body']);
        return $message;
    }
    public static function preview_quote() {
        check_ajax_referer('ge_email_preview','nonce');
        if (!GE_WTP_Staff_Portal::can_access() || (class_exists('GE_Organization_Runtime') && !GE_Organization_Runtime::allowed('quotes',false,get_current_user_id()))) wp_send_json_error('Acceso denegado.',403);
        if (!empty($_POST['form_preview'])) {
            $result = GE_WTP_Commercial_Quote_UI::form_preview(wp_unslash($_POST),get_current_user_id());
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message(),422);
            $quote = $result['quote'] ?: array('id'=>0,'number'=>'BORRADOR');
            $customer = $result['customer'] ?: (object)array('ID'=>0,'first_name'=>'','display_name'=>sanitize_text_field(wp_unslash($_POST['customer_name']??'')));
            $to = $result['customer'] ? $result['customer']->user_email : sanitize_email(wp_unslash($_POST['customer_email']??''));
            $message = self::envelope(self::quote_message($quote,$result['snapshot'],$customer),$to);
            $message['notice'] = 'Vista previa de los datos del formulario. Guardar asigna número y versión; el envío se realiza al confirmar Enviar al cliente.';
        } else {
            $quote = GE_WTP_Commercial_Quotes::get(absint($_POST['quote_id']??0),get_current_user_id());
            if (is_wp_error($quote) || !self::quote_scope($quote)) wp_send_json_error('Presupuesto no disponible.',403);
            $customer = get_userdata($quote['customer_id']); if (!$customer) wp_send_json_error('Cliente no disponible.',422);
            $message = self::envelope(self::quote_message($quote,$quote['snapshot'],$customer,in_array($quote['status'],array('sent','viewed'),true)),$customer->user_email,$quote['version']);
            $message['notice'] = 'Vista previa de la versión guardada. Abrir esta vista no envía avisos ni cambia su estado.';
        }
        wp_send_json_success($message);
    }
    public static function quote_scope($quote) {
        if (!class_exists('GE_Organization')) return true;
        $post_org=get_post_meta($quote['id'],'_ge_organization_id',true);
        $snapshot_org=$quote['snapshot']['organization_snapshot']['organization_id'] ?? $quote['snapshot']['organization_snapshot']['id'] ?? '';
        $customer_org=get_user_meta($quote['customer_id'],'_ge_organization_id',true);
        foreach(array($post_org,$snapshot_org,$customer_org) as $org) if($org && $org!==self::org())return false;
        return true;
    }
    public static function preview_template() {
        check_ajax_referer('ge_email_preview','nonce'); if (!self::permitted()) wp_send_json_error('Acceso denegado.',403);
        $id=sanitize_key($_POST['template_id']??''); $record=self::get($id); if (!$record) wp_send_json_error('Plantilla no disponible.',404);
        if (isset($_POST['body'])) {
            if (!self::permitted(true)) wp_send_json_error('Edición reservada al administrador.',403);
            $clean=self::validate($id,wp_unslash($_POST['title']??''),wp_unslash($_POST['subject']??''),wp_unslash($_POST['body']));
            if (is_wp_error($clean)) wp_send_json_error($clean->get_error_message(),422); $record=array_merge($record,$clean);
        }
        $message=self::render($id,array('customer_name'=>'Cliente de ejemplo','quote_number'=>'10001','intro'=>'Tenés un nuevo presupuesto de Graph Express para revisar.','portal_url'=>home_url('/portal-de-ejemplo/'),'summary'=>'<p>Producto de ejemplo · 100 unidades</p><p>Total: ARS 12.100</p>','first_access'=>'<p>Si es tu primer acceso, usá “¿Olvidaste tu contraseña?” en el portal.</p>','brand_name'=>'Graph Express'),$record);
        $message=self::envelope($message,'cliente@example.invalid',$id==='commercial_quote_sent'?1:0);
        if ($id!=='commercial_quote_sent') $message['attachment']='Sin adjuntos; plantilla manual sin envío automático';
        $message['notice']='Ejemplo con datos ficticios. No se envía correo y los enlaces están desactivados en la vista previa.';
        wp_send_json_success($message);
    }
    public static function save_record($id, $fields, $expected, $action = 'save') {
        if (!self::permitted(true)) return new WP_Error('template_permission','Administrador de la organización requerido.');
        $lock=self::option().'_lock'; if (!add_option($lock,time(),'',false)) return new WP_Error('template_busy','Otra edición está en curso. Reintentá.');
        try {
            $stored=self::stored();$record=self::get($id);
            if ($action==='create' && !$record && strpos($id,'manual_')===0) $record=array('title'=>'Nueva plantilla','group'=>'Manuales','trigger'=>'Sin envío automático','recipient'=>'A definir al usarla','subject'=>'Mensaje','body'=>'<p>Hola {{customer_name}},</p>','placeholders'=>array('customer_name','brand_name'),'editable'=>true,'revision'=>0);
            if (!$record || (int)$record['revision']!==(int)$expected) return new WP_Error('template_stale','La plantilla cambió. Recargá antes de guardar.');
            $new=self::validate($id,$fields['title']??'',$fields['subject']??'',$fields['body']??'',$record); if (is_wp_error($new)) return $new;
            $history=(array)($record['history']??array());if($record['revision']>0)$history[]=array('revision'=>$record['revision'],'title'=>$record['title'],'subject'=>$record['subject'],'body'=>$record['body'],'actor'=>get_current_user_id(),'at'=>gmdate('c'),'action'=>$action);
            $new=array_merge($record,$new,array('revision'=>$record['revision']+1,'history'=>array_slice($history,-20),'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id()));
            if (class_exists('GE_Organization')) { $audit=GE_Organization::audit(self::org(),get_current_user_id(),'email_template_'.$action,array('id'=>$id,'revision'=>$record['revision']),array('id'=>$id,'revision'=>$new['revision'],'subject'=>$new['subject'])); if (is_wp_error($audit)) return $audit; }
            $stored[$id]=$new; if (!update_option(self::option(),$stored,false)) return new WP_Error('template_storage','No se pudo guardar.');
            return $new;
        } finally { delete_option($lock); }
    }
    public static function handle_save() {
        if (!self::permitted(true)) wp_die('Acceso denegado.', '', array('response'=>403));
        check_admin_referer('ge_communication_template_save');
        $id=sanitize_key($_POST['template_id']??'');$mode=sanitize_key($_POST['template_mode']??'save');
        if ($mode==='create') {
            $id='manual_'.str_replace('-','',wp_generate_uuid4());
        }
        $fields=array('title'=>wp_unslash($_POST['title']??''),'subject'=>wp_unslash($_POST['subject']??''),'body'=>wp_unslash($_POST['body']??''));
        if ($mode==='default') { $defaults=self::defaults(); if (!isset($defaults[$id])) wp_die('Sin plantilla predeterminada.'); $fields=$defaults[$id]; }
        if ($mode==='restore') { $record=self::get($id);$revision=absint($_POST['restore_revision']??0);$fields=null;foreach((array)($record['history']??array()) as $old) if((int)$old['revision']===$revision)$fields=$old;if(!$fields)wp_die('Versión no disponible.'); }
        $result=self::save_record($id,$fields,$mode==='create'?0:absint($_POST['revision']??0),$mode);
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',array('response'=>409));
        wp_safe_redirect(self::url(array('template_id'=>$id,'saved'=>1)));exit;
    }
    public static function url($args=array()) { return GE_WTP_Staff_Portal::portal_url('communications',array_merge(array('subsection'=>'templates'),$args)); }
    public static function enqueue() {
        if (!is_user_logged_in() || !in_array(sanitize_key($_GET['section']??''),array('quotes','communications'),true)) return;
        wp_enqueue_style('ge-email-templates',GE_WTP_PLUGIN_URL.'assets/css/email-templates.css',array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/css/email-templates.css'));
        wp_enqueue_script('ge-email-templates',GE_WTP_PLUGIN_URL.'assets/js/email-templates.js',array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/js/email-templates.js'),true);
        wp_localize_script('ge-email-templates','geEmailTemplates',array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ge_email_preview')));
    }
    public static function catalog() {
        $rows=self::defaults();foreach(self::stored() as $id=>$record) if(strpos($id,'manual_')===0)$rows[$id]=$record;
        $inventory=GE_WTP_PLUGIN_DIR.'includes/email-template-inventory.json';
        $legacy=is_readable($inventory)?json_decode(file_get_contents($inventory),true):array();
        foreach((array)$legacy as $row) if(!isset($rows[$row['id']]))$rows[$row['id']]=$row;
        if (function_exists('WC') && WC()->mailer()) foreach(WC()->mailer()->get_emails() as $email) {
            $id='woocommerce_'.$email->id;$rows[$id]=array('title'=>$email->get_title(),'group'=>'WooCommerce','trigger'=>wp_strip_all_tags($email->get_description()),'recipient'=>$email->is_customer_email()?'Cliente':'Personal configurado en WooCommerce','editable'=>false,'source'=>'WooCommerce · '.$email->id,'enabled'=>$email->is_enabled());
        }
        return $rows;
    }
    private static function form_head($id,$record,$mode='save') {
        echo '<form class="ge-template-editor" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_communication_template_save"><input type="hidden" name="template_id" value="'.esc_attr($id).'"><input type="hidden" name="revision" value="'.absint($record['revision']??1).'"><input type="hidden" name="template_mode" value="'.esc_attr($mode).'">';wp_nonce_field('ge_communication_template_save');
    }
    private static function content_editor($body, $id, $editable) {
        echo '<label for="'.esc_attr($id).'">Contenido del correo</label>';
        if ($editable && function_exists('wp_editor')) wp_editor($body,$id,array('textarea_name'=>'body','media_buttons'=>false,'teeny'=>true,'textarea_rows'=>10));
        else echo '<textarea id="'.esc_attr($id).'" name="body" rows="12" readonly>'.esc_textarea($body).'</textarea>';
    }
    public static function render_portal() {
        if(!self::permitted()){echo '<p>Acceso denegado a plantillas.</p>';return;}
        $id=sanitize_key($_GET['template_id']??'');$catalog=self::catalog();$record=$catalog[$id]??null;
        echo '<section class="ge-admin-panel ge-template-panel"><h2>Plantillas de correo</h2><p>Revisá qué mensaje se usa, cuándo se envía y quién lo recibe. Las plantillas manuales no activan envíos.</p>';
        if(isset($_GET['saved']))echo '<p role="status">Plantilla guardada. No se envió ningún correo.</p>';
        if($record) {
            echo '<a href="'.esc_url(self::url()).'">← Todas las plantillas</a><h3>'.esc_html($record['title']).'</h3><p><strong>Disparador:</strong> '.esc_html($record['trigger']).'<br><strong>Destinatario:</strong> '.esc_html($record['recipient']).'</p>';
            if(!empty($record['editable'])) {
                $record=self::get($id);$edit=self::permitted(true);
                if($edit)self::form_head($id,$record);
                echo '<label>Título<input name="title" value="'.esc_attr($record['title']).'" maxlength="200"'.($edit?' required':' readonly').'></label><label>Asunto<input name="subject" value="'.esc_attr($record['subject']).'" maxlength="500"'.($edit?' required':' readonly').'></label>';
                self::content_editor($record['body'],'ge_template_body',$edit);
                echo '<p>Variables permitidas: '.esc_html(implode(', ',array_map(function($v){return '{{'.$v.'}}';},$record['placeholders']))).'. Se reemplazan por los datos del presupuesto al enviar.</p><div class="ge-template-actions"><button type="button" data-ge-template-preview="'.esc_attr($id).'">Vista previa sin enviar</button>';
                if($edit)echo '<button type="submit">Guardar plantilla</button>';
                echo '</div>';if($edit)echo '</form>';
                echo '<p>Versión '.absint($record['revision']).' · '.esc_html($record['updated_at']??'Plantilla predeterminada').'</p>';
                if($edit && isset(self::defaults()[$id])) { self::form_head($id,$record,'default');echo '<button type="submit">Restaurar plantilla predeterminada</button></form>'; }
                if($edit && !empty($record['history'])) { echo '<details><summary>Versiones anteriores</summary>';foreach(array_reverse($record['history']) as $old){self::form_head($id,$record,'restore');echo '<input type="hidden" name="restore_revision" value="'.absint($old['revision']).'"><p>Versión '.absint($old['revision']).' · '.esc_html($old['at']).' · '.esc_html($old['subject']).'</p><button type="submit">Restaurar esta versión</button></form>';}echo '</details>'; }
            } else {
                echo '<p><strong>Heredada · administrada en su sistema de origen.</strong> El editor central todavía no modifica este envío.</p><p>Fuente: '.esc_html($record['source']).'</p>';
                if(!empty($record['excerpt']))echo '<details open><summary>Referencia de contenido en código</summary><pre>'.esc_html($record['excerpt']).'</pre></details>';
                if(strpos($id,'woocommerce_')===0 && current_user_can('manage_woocommerce'))echo '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=email')).'">Configuración de correos de WooCommerce</a>';
            }
        } else {
            echo '<div class="ge-template-filters"><label>Buscar plantilla<input type="search" data-ge-template-search placeholder="Presupuesto, aprobación, pago…"></label><label>Grupo<select data-ge-template-group><option value="">Todos los grupos</option>';
            foreach(array_unique(array_column($catalog,'group')) as $group)echo '<option value="'.esc_attr($group).'">'.esc_html($group).'</option>';
            echo '</select></label></div><p data-ge-template-count role="status">'.count($catalog).' plantillas y familias de correo</p><div class="ge-template-list">';
            foreach($catalog as $key=>$row)echo '<article data-template-group="'.esc_attr($row['group']).'"><div><small>'.esc_html($row['group']).' · '.(strpos($key,'manual_')===0?'Manual · sin envío automático':(!empty($row['editable'])?'Editable y conectada':(isset($row['enabled'])&&!$row['enabled']?'Heredada · desactivada':'Heredada / código'))).'</small><h3>'.esc_html($row['title']).'</h3><p>'.esc_html($row['trigger']).'</p><p>Para: '.esc_html($row['recipient']).'</p></div><a href="'.esc_url(self::url(array('template_id'=>$key))).'">'.(!empty($row['editable'])?'Ver y previsualizar':'Ver referencia').'</a></article>';
            echo '</div>';
            if(self::permitted(true)){echo '<details><summary>Crear plantilla manual</summary>';self::form_head('',array(),'create');echo '<label>Título<input name="title" required maxlength="200"></label><label>Asunto<input name="subject" required maxlength="500"></label>';self::content_editor('<p>Hola {{customer_name}},</p>','ge_template_new_body',true);echo '<p>Variables: {{customer_name}}, {{brand_name}}. Guardarla no activa un envío automático.</p><button type="submit">Crear plantilla</button></form></details>';}
        }
        echo '<div data-ge-email-preview-output></div></section>';
    }
}
