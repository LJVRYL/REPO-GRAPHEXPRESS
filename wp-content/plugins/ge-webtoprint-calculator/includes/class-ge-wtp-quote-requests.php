<?php
defined('ABSPATH') || exit;

/** Requests carry customer intent and immutable receiver/artwork references, never prices or issuers from customers. */
final class GE_WTP_Quote_Requests {
    const TYPE='ge_quote_request';
    const META='_ge_quote_request';
    public static function init() {
        add_action('init',function(){register_post_type(self::TYPE,array('public'=>false,'show_ui'=>false,'capability_type'=>'post','map_meta_cap'=>true));});
        add_action('wp_enqueue_scripts',array(__CLASS__,'enqueue'));
        add_action('wp_ajax_ge_request_save',array(__CLASS__,'ajax'));
        add_action('wp_ajax_ge_request_catalog',array(__CLASS__,'catalog'));
        add_action('admin_post_ge_request_staff',array(__CLASS__,'staff_action'));
        add_action('admin_post_ge_request_file',array(__CLASS__,'download'));
    }
    public static function customer_can_stage() {
        return is_user_logged_in() && !GE_WTP_Staff_Portal::can_access() && GE_WTP_Portal::can_access() && !GE_WTP_Portal::is_staff_preview()
            && !empty($_POST['request_context']) && wp_verify_nonce($_POST['request_nonce']??'','ge_quote_request')
            && empty($_POST['quote_id']) && empty($_POST['order_id']);
    }
    public static function get($id,$actor) {
        $p=get_post($id);$d=get_post_meta($id,self::META,true);
        if(!$p||self::TYPE!==$p->post_type||!is_array($d)||((int)$d['customer_id']!==(int)$actor&&!user_can($actor,'ge_manage_operations')&&!user_can($actor,'manage_woocommerce'))){return new WP_Error('forbidden','Solicitud no disponible.');}
        $d['id']=(int)$id;return $d;
    }
    public static function profiles($customer) {
        return array_values(array_filter(GE_WTP_Customer_Branches::profiles($customer),function($p){return !empty($p['cuit'])||!empty($p['legal_name']);}));
    }
    public static function product_allowed($id,$actor) {
        $p=wc_get_product($id);if(!$p||'publish'!==$p->get_status())return false;
        return $p->is_visible()||GE_WTP_Portal::is_markcom_user(get_userdata($actor));
    }
    public static function normalize($input,$actor,$submit=true) {
        $raw=$input['items']??array();if(!is_array($raw)||!$raw||count($raw)>30)return new WP_Error('items','Agregá entre 1 y 30 ítems.');
        $items=array();$seen=array();
        foreach($raw as $row){
            if(!is_array($row))return new WP_Error('item','Ítem inválido.');
            $uuid=$row['line_uuid']??'';if(!GE_WTP_Quote_Artwork_V2::uuid($uuid)||isset($seen[$uuid]))return new WP_Error('item','Identificador de ítem inválido.');$seen[$uuid]=true;
            $product=absint($row['product_id']??0);if($product&&!self::product_allowed($product,$actor))return new WP_Error('product','Un producto ya no está disponible.');
            $title=sanitize_text_field($row['title']??'');$qty=filter_var($row['quantity']??null,FILTER_VALIDATE_INT);
            if(!$title||!$qty||$qty<1||$qty>100000)return new WP_Error('item','Indicá qué necesitás y cuántas unidades.');
            $i=array('line_uuid'=>$uuid,'product_id'=>$product,'source_type'=>$product?'catalog_product':'custom','title'=>$product?wc_get_product($product)->get_name():mb_substr($title,0,160),'quantity'=>$qty);
            foreach(array('description','category','material','finishing','usage','notes','options') as $k){$i[$k]=mb_substr(sanitize_textarea_field($row[$k]??''),0,2000);}
            foreach(array('width','height') as $k){$value=trim((string)($row[$k]??''));if($value!==''&&(!is_numeric($value)||$value<=0||$value>100000))return new WP_Error('measure','Revisá las medidas.');$i[$k]=$value;}
            $i['measure_unit']=in_array($row['measure_unit']??'',array('mm','cm','m'),true)?$row['measure_unit']:'cm';
            $i['artwork_refs']=array_values(array_filter((array)($row['artwork_refs']??array()),'is_string'));
            $items[]=$i;
        }
        $date=sanitize_text_field($input['needed_by']??'');if($date){$dt=DateTime::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)return new WP_Error('date','Revisá la fecha necesaria.');}
        $profile_id=sanitize_text_field($input['billing_profile_id']??'');$profiles=self::profiles($actor);
        if($submit&&count($profiles)>1&&!$profile_id)return new WP_Error('profile','Elegí a nombre de quién preparar el presupuesto.');
        if(!$profile_id&&count($profiles)===1)$profile_id=$profiles[0]['id'];
        $profile=$profile_id?GE_WTP_Customer_Branches::find($actor,$profile_id):null;if($profile_id&&!$profile)return new WP_Error('profile','Elegí uno de tus perfiles activos.');
        return array('customer_id'=>(int)$actor,'items'=>$items,'billing_profile_id'=>$profile_id,'billing_snapshot'=>$profile?(array)$profile:array(),'needed_by'=>$date,'urgency'=>in_array($input['urgency']??'',array('normal','urgent'),true)?$input['urgency']:'normal','notes'=>mb_substr(sanitize_textarea_field($input['notes']??''),0,4000));
    }
    public static function save($input,$actor,$submit=true) {
        if(!user_can($actor,'read')||user_can($actor,'ge_manage_operations')||user_can($actor,'manage_woocommerce'))return new WP_Error('forbidden','Acceso denegado.');
        $session=$input['artwork_session']??'';if(!GE_WTP_Quote_Artwork_V2::uuid($session))return new WP_Error('session','Volvé a abrir el formulario.');
        $key='ge_request_session_'.$actor.'_'.$session;
        GE_WTP_Documents::ensure_private_directory();$lock=fopen(trailingslashit(GE_WTP_Documents::private_directory()).$key.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return new WP_Error('busy','La solicitud se está guardando. Reintentá.');
        try{
            $id=absint(get_option($key));$old=$id?self::get($id,$actor):null;if(is_wp_error($old))return $old;
            if($old&&'draft'!==$old['status'])return $old; // Submission retries cannot create duplicates or send twice.
            $data=self::normalize($input,$actor,$submit);if(is_wp_error($data))return $data;
            $lines=array_map(function($i){return array('line_uuid'=>$i['line_uuid'],'artwork_refs'=>$i['artwork_refs']);},$data['items']);
            $files=GE_WTP_Quote_Artwork_V2::prepare($id?array('id'=>$id,'staging_scope_id'=>0):null,$lines,array(),$session,$actor);if(is_wp_error($files))return $files;
            if(!$id){$id=wp_insert_post(array('post_type'=>self::TYPE,'post_status'=>'private','post_title'=>'Solicitud · '.get_userdata($actor)->display_name,'post_author'=>$actor),true);if(is_wp_error($id))return $id;update_option($key,$id,false);}
            $data['funnel_source']='landing-v1'===($input['funnel_source']??'')?'landing-v1':($old['funnel_source']??'portal');
            $data['status']=$submit?'new':'draft';$data['created_at']=$old['created_at']??gmdate('c');$data['updated_at']=gmdate('c');$data['quote_id']=0;$data['artwork_session']=$session;$data['staff_message']='';
            update_post_meta($id,self::META,$data);GE_WTP_Quote_Artwork_V2::commit($id,$files,$actor);
            if($submit){$u=get_userdata($actor);GE_WTP_Internal_Alerts::create('quote_request',$u->display_name.' solicitó un presupuesto personalizado',$id,$actor);
                $url=GE_WTP_Staff_Portal::portal_url('requests',array('request_id'=>$id));$body='<p>Solicitud #'.$id.' · '.count($data['items']).' ítems</p><p><a href="'.esc_url($url).'">Ver solicitud en Gestión</a></p>';
                $recipients=GE_WTP_Notification_Center::recipients();$sent=!empty($recipients);foreach($recipients as $recipient){$sent=GE_WTP_Notifications::send($recipient,'Nueva solicitud de presupuesto · #'.$id,$body,'quote_request_internal',$id)&&$sent;}
                update_post_meta($id,'_ge_internal_email_result',$sent?'sent':'failed');
                GE_WTP_Notifications::send($u->user_email,'Recibimos tu solicitud de presupuesto · #'.$id,'<p>Recibimos tu solicitud con '.count($data['items']).' ítems. Nuestro equipo la revisará.</p><p><a href="'.esc_url(GE_WTP_Portal::portal_url('solicitudes')).'">Mis solicitudes</a></p>','quote_request_received',$id);
            }
            return self::get($id,$actor);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function ajax() {
        check_ajax_referer('ge_quote_request','nonce');
        if(!GE_WTP_Portal::can_access()||GE_WTP_Portal::is_staff_preview()||GE_WTP_Staff_Portal::can_access())wp_send_json_error(array('message'=>'Vista protegida.'),403);
        $input=json_decode(wp_unslash($_POST['payload']??''),true);if(!is_array($input))wp_send_json_error(array('message'=>'Solicitud inválida.'),422);
        $result=self::save($input,get_current_user_id(),empty($_POST['draft']));
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),422);
        wp_send_json_success(array('id'=>$result['id'],'status'=>$result['status'],'url'=>GE_WTP_Portal::portal_url('solicitudes')));
    }
    public static function catalog() {
        check_ajax_referer('ge_quote_request','nonce');if(!GE_WTP_Portal::can_access())wp_send_json_error(array('message'=>'Acceso denegado.'),403);
        $args=array('status'=>'publish','limit'=>24,'orderby'=>'title','order'=>'ASC');$q=sanitize_text_field(wp_unslash($_GET['q']??''));if($q)$args['s']=$q;
        $category=sanitize_title($_GET['category']??'');if($category)$args['category']=array($category);
        $products=wc_get_products($args);$out=array();foreach($products as $p){if(!self::product_allowed($p->get_id(),GE_WTP_Portal::portal_customer_id()))continue;$out[]=array('id'=>$p->get_id(),'name'=>$p->get_name(),'type'=>$p->get_type(),'options'=>implode(', ',array_keys($p->get_attributes())),'url'=>$p->is_visible()?get_permalink($p->get_id()):'');}
        wp_send_json_success($out);
    }
    public static function enqueue() {
        if(!is_page('cliente-markcom')&&!is_page('gestion'))return;
        wp_enqueue_style('ge-portal-v3',GE_WTP_PLUGIN_URL.'assets/css/portal-v3.css',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/css/portal-v3.css'));
        wp_enqueue_style('ge-quick-quote',GE_WTP_PLUGIN_URL.'assets/css/quick-quote.css',array('ge-portal-v3'),filemtime(GE_WTP_PLUGIN_DIR.'assets/css/quick-quote.css'));
        wp_enqueue_script('ge-quick-auth',GE_WTP_PLUGIN_URL.'assets/js/quick-auth.js',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/js/quick-auth.js'),true);
        if('personalizado'!==($_GET['seccion']??''))return;
        GE_WTP_Quote_Artwork_V2::enqueue();
        $request_js='1'===($_GET['rapida']??'')?'quick-quote.js':'quote-request.js';
        wp_enqueue_script('ge-request',GE_WTP_PLUGIN_URL.'assets/js/'.$request_js,array('ge-quote-artwork-v2'),filemtime(GE_WTP_PLUGIN_DIR.'assets/js/'.$request_js),true);
        wp_localize_script('ge-request','geRequest',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ge_quote_request'),'preview'=>GE_WTP_Portal::is_staff_preview(),'draft'=>self::draft_data(),'customerId'=>GE_WTP_Portal::portal_customer_id()));
    }
    public static function draft_data() {
        $id=absint($_GET['request_id']??0);if(!$id)return null;$d=self::get($id,GE_WTP_Portal::portal_customer_id());if(is_wp_error($d)||'draft'!==$d['status'])return null;
        $product=count($d['items'])===1?absint($d['items'][0]['product_id']):0;$p=$product?wc_get_product($product):null;$d['shop_url']=$p&&$p->is_visible()&&self::product_allowed($product,GE_WTP_Portal::portal_customer_id())?get_permalink($product):'';return $d;
    }
    public static function form() {
        if('1'===($_GET['rapida']??'')){GE_WTP_Quick_Quote::form();return;}
        $customer=GE_WTP_Portal::portal_customer_id();$profiles=self::profiles($customer);$session=wp_generate_uuid4();
        echo '<section class="ge-panel ge-request"><span class="ge-eyebrow">Hecho a tu medida</span><h1>Presupuesto personalizado</h1><p>Combiná productos de la tienda con trabajos propios. Revisaremos los detalles antes de cotizar.</p><ol class="ge-request-steps"><li aria-current="step">1. Qué necesitás</li><li>2. Detalles y archivos</li><li>3. Revisar y enviar</li></ol><form class="ge-request-form"><input type="hidden" name="artwork_session" value="'.esc_attr($session).'"><fieldset'.(GE_WTP_Portal::is_staff_preview()?' disabled':'').'>';
        echo '<section data-request-step="1"><h2>¿Qué querés hacer?</h2><label class="ge-field-wide">¿A nombre de quién querés que preparemos este presupuesto?<select name="billing_profile_id"><option value="">'.(count($profiles)>1?'Elegí un perfil':'Sin datos por ahora').'</option>';
        foreach($profiles as $p)echo '<option value="'.esc_attr($p['id']).'"'.selected(count($profiles)===1,true,false).'>'.esc_html($p['label'].' · '.$p['legal_name']).'</option>';
        echo '</select></label><div class="ge-request-search"><label>Buscar productos<input type="search" data-request-search placeholder="Por ejemplo: stickers, carteles…"></label><label>Categoría<select data-request-category><option value="">Todas las categorías</option>';
        $terms=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>true));if(!is_wp_error($terms))foreach($terms as $t)echo '<option value="'.esc_attr($t->slug).'">'.esc_html($t->name).'</option>';
        echo '</select></label></div><div data-request-products class="ge-request-products" aria-live="polite"></div><button type="button" data-request-custom>Agregar ítem personalizado</button><p data-request-count role="status">Todavía no agregaste ítems.</p></section><section data-request-step="2" hidden><h2>Contanos los detalles</h2><div data-request-items></div><button type="button" data-request-more>Agregar otro ítem</button></section><section data-request-step="3" hidden><h2>Revisar tu solicitud</h2><div data-request-review></div><div class="ge-profile-fields"><label>¿Para cuándo lo necesitás?<input type="date" name="needed_by"></label><label>Urgencia<select name="urgency"><option value="normal">Fecha flexible</option><option value="urgent">Lo necesito con urgencia</option></select></label><label class="ge-field-wide">Contanos cualquier detalle útil<textarea name="notes" rows="3" maxlength="4000"></textarea></label></div><p><a href="'.esc_url(GE_WTP_Portal::portal_url('perfil')).'">Agregar datos de facturación</a> · Podés enviar sin datos si todavía no tenés un perfil.</p><p>Esto es una solicitud. La fecha y los importes se confirmarán en el presupuesto.</p></section><p data-request-notice role="status" aria-live="polite"></p><div class="ge-request-actions"><button type="button" data-request-back hidden>Volver</button><button type="button" data-request-draft>Guardar borrador</button><button type="button" data-request-next>Continuar</button><button type="submit" data-request-submit hidden>Enviar solicitud</button></div></fieldset></form><template data-request-template><article class="ge-request-item" data-ge-line><header><h3 data-request-title>Ítem personalizado</h3><button type="button" data-request-remove>Quitar ítem</button></header><input type="hidden" data-ge-line-uuid name="line_uuid"><input type="hidden" name="product_id" value="0"><div class="ge-profile-fields">';
        foreach(array('title'=>'¿Qué querés hacer?','quantity'=>'¿Cuántas unidades?','description'=>'Descripción del trabajo','width'=>'Ancho aproximado','height'=>'Alto aproximado','material'=>'Material preferido (opcional)','finishing'=>'Terminación (opcional)','usage'=>'Uso / destino (opcional)','options'=>'Variante u opciones (opcional)','notes'=>'Detalles útiles (opcional)') as $k=>$label){if('material'===$k)echo '</div><details class="ge-request-options"><summary>Material, terminación y otros detalles (opcional)</summary><div class="ge-profile-fields">';$numeric=in_array($k,array('quantity','width','height'),true);echo '<label>'.esc_html($label).'<input name="'.$k.'" type="'.($numeric?'number':'text').'"'.($numeric?' min="1" max="100000" step="'.('quantity'===$k?'1':'any').'"':' maxlength="2000"').(in_array($k,array('title','quantity'),true)?' required':'').('quantity'===$k?' value="1"':'').'></label>';}
        echo '</div></details><div class="ge-profile-fields"><label>Unidad de medida<select name="measure_unit"><option value="cm">cm</option><option value="mm">mm</option><option value="m">m</option></select></label></div>';GE_WTP_Quote_Artwork_V2::block(0,'',array(),0);echo '</article></template></section>';
    }
    public static function history() {
        $customer=GE_WTP_Portal::portal_customer_id();echo '<section class="ge-panel"><h1>Mis solicitudes</h1><p><a class="ge-button" href="'.esc_url(GE_WTP_Portal::portal_url('personalizado')).'">Presupuesto personalizado</a></p>';
        $rows=get_posts(array('post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>50,'meta_key'=>self::META));$found=false;
        foreach($rows as $p){$d=self::get($p->ID,$customer);if(is_wp_error($d)||(int)$d['customer_id']!==$customer)continue;$found=true;$labels=array('draft'=>'Borrador','new'=>'Recibida','review'=>'En revisión','info'=>'Necesitamos información','quoted'=>'Presupuesto en preparación','closed'=>'Cerrada / cancelada');
            $q=$d['quote_id']?GE_WTP_Commercial_Quotes::get($d['quote_id'],$customer):null;$visible=$q&&!is_wp_error($q)&&in_array($q['status'],array('sent','viewed','accepted','converted'),true);
            echo '<article class="ge-request-history"><h2>Solicitud #'.$p->ID.'</h2><p>'.esc_html($visible?'Presupuesto disponible':($labels[$d['status']]??'Recibida')).' · '.count($d['items']).' ítems · '.esc_html(wp_date('d/m/Y',strtotime($d['created_at']))).'</p>';
            foreach($d['items'] as $i)echo '<p>'.esc_html($i['title']).' · '.$i['quantity'].' unidades</p>';
            foreach(GE_WTP_Commercial_Quote_Files::all($p->ID) as $f){if('detached'===($f['association_status']??''))continue;$url=GE_WTP_External_Artwork::is_link($f)?$f['url']:wp_nonce_url(add_query_arg(array('action'=>'ge_request_file','request_id'=>$p->ID,'file_id'=>$f['id']),admin_url('admin-post.php')),'ge_request_file_'.$p->ID);echo '<p><a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html($f['name']).'</a></p>';if(!GE_WTP_External_Artwork::is_link($f))GE_WTP_File_Analysis::render($f,false);}
            if($d['staff_message'])echo '<p>'.esc_html($d['staff_message']).'</p>';
            if('draft'===$d['status'])echo '<a href="'.esc_url(GE_WTP_Portal::portal_url('personalizado',array('request_id'=>$p->ID,'rapida'=>count($d['items'])===1?'1':''))).'">Retomar borrador</a>';
            if($visible)echo '<a href="'.esc_url(GE_WTP_Portal::portal_url('presupuestos',array('quote_id'=>$d['quote_id']))).'">Ver presupuesto</a>';
            echo '</article>';
        }if(!$found)echo '<p>Todavía no tenés solicitudes. Contanos qué necesitás y lo preparamos.</p>';echo '</section>';
    }
    public static function download() {
        $id=absint($_GET['request_id']??0);check_admin_referer('ge_request_file_'.$id);$d=self::get($id,get_current_user_id());if(is_wp_error($d))wp_die('Acceso denegado.','',array('response'=>403));
        $ref=sanitize_text_field($_GET['file_id']??'');foreach(GE_WTP_Commercial_Quote_Files::all($id) as $f){if($f['id']===$ref&&'detached'!==($f['association_status']??'')&&!GE_WTP_External_Artwork::is_link($f)){GE_WTP_Documents::stream_record($f,true);exit;}}wp_die('Archivo no disponible.');
    }
    public static function convert($id,$prices,$args,$actor) {
        if(!user_can($actor,'ge_manage_operations')&&!user_can($actor,'manage_woocommerce'))return new WP_Error('forbidden','Acceso denegado.');
        $d=self::get($id,$actor);if(is_wp_error($d))return $d;if($d['quote_id'])return GE_WTP_Commercial_Quotes::get($d['quote_id'],$actor);
        if(!in_array($d['status'],array('new','review','info'),true))return new WP_Error('state','Solicitud no convertible.');
        $key='ge_request_convert_'.$id;if(!add_option($key,gmdate('c'),'',false))return new WP_Error('busy','Conversión en curso.');
        try{
            // Re-read under the conversion lock: another tab may have finished after our first read.
            $d=self::get($id,$actor);if(is_wp_error($d))return $d;if($d['quote_id'])return GE_WTP_Commercial_Quotes::get($d['quote_id'],$actor);
            if(!in_array($d['status'],array('new','review','info'),true))return new WP_Error('state','Solicitud no convertible.');
            if(!empty($args['expected_request_hash'])&&!hash_equals(hash('sha256',wp_json_encode($d)),(string)$args['expected_request_hash']))return new WP_Error('ge_request_stale','La solicitud cambió. Revisá su ficha; tus valores siguen en pantalla.');
            $lines=array();foreach($d['items'] as $i){$price=$prices[$i['line_uuid']]??'';if($price===''||!is_numeric($price)||$price<0)return new WP_Error('price','Ingresá el precio neto por unidad de cada ítem.');
                // Request intent remains available in provenance; custom commercial lines avoid silently changing quantities/configuration with live catalog rules.
                $detail=implode(' · ',array_filter(array($i['description'],$i['width']&&$i['height']?$i['width'].' × '.$i['height'].' '.$i['measure_unit']:'',$i['material'],$i['finishing'],$i['options'],$i['usage'],$i['notes'])));
                $lines[]=array('line_uuid'=>$i['line_uuid'],'source_type'=>'custom','name'=>$i['title'],'quantity'=>$i['quantity'],'unit'=>'u','unit_net'=>$price,'notes'=>$detail);
            }
            $profile_id=sanitize_text_field($args['billing_profile_id']??$d['billing_profile_id']);$active=GE_WTP_Customer_Branches::find($d['customer_id'],$profile_id);$profile=$profile_id===$d['billing_profile_id']&&$d['billing_snapshot']?$d['billing_snapshot']:$active;
            if(!$profile||!GE_WTP_Customer_Branches::find($d['customer_id'],$profile_id))return new WP_Error('profile','Revisá el perfil fiscal de la solicitud. Si fue desactivado, seleccioná uno activo antes de cotizar.');
            $args['billing_profile_id']=$profile_id;$args['source']='quote_request';$args['notes_customer']=$d['notes'];$args['notes_internal']='Origen: solicitud #'.$id.' · Fecha necesaria '.$d['needed_by'].' · '.$d['urgency'];
            $q=GE_WTP_Commercial_Quotes::create_draft($d['customer_id'],$lines,$args,$actor);if(is_wp_error($q))return $q;
            $d['quote_id']=$q['id'];$d['status']='quoted';$d['updated_at']=gmdate('c');update_post_meta($id,self::META,$d);update_post_meta($q['id'],'_ge_quote_request_source',$id);update_post_meta($q['id'],'_ge_quote_request_snapshot',$d);
            $s=$q['snapshot'];foreach($s['items'] as $k=>&$line){$line['request_product_id']=$d['items'][$k]['product_id'];$line['request_configuration']=$d['items'][$k];}unset($line);$s['receiver_snapshot']=$profile;$s['customer_billing_profile']=$profile;$s=GE_WTP_Commercial_Quotes::preview_billing($d['customer_id'],$s);$s=GE_WTP_Quote_Billing_Control::capture($s,$actor,'Receptor preservado desde solicitud #'.$id);
            $versions=get_post_meta($q['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,true);$versions[$q['version']]=$s;update_post_meta($q['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,$versions);
            GE_WTP_Quote_Artwork_V2::commit($q['id'],GE_WTP_Commercial_Quote_Files::all($id),$d['customer_id']);
            return GE_WTP_Commercial_Quotes::get($q['id'],$actor);
        }finally{delete_option($key);}
    }
    public static function staff_action() {
        if(!GE_WTP_Staff_Portal::can_access())wp_die('Acceso denegado.','',array('response'=>403));$id=absint($_POST['request_id']??0);check_admin_referer('ge_request_staff_'.$id);$d=self::get($id,get_current_user_id());if(is_wp_error($d))wp_die('Solicitud no disponible.');
        if('draft'===($_POST['job_intent']??'')){GE_WTP_Job_Flow::save_request();return;}
        if('convert'===($_POST['op']??'')){$q=self::convert($id,wp_unslash($_POST['prices']??array()),wp_unslash($_POST),get_current_user_id());if(is_wp_error($q))wp_die(esc_html($q->get_error_message()));wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$q['id'])));exit;}
        $status=sanitize_key($_POST['status']??'');if(!in_array($status,array('review','info','closed'),true))wp_die('Estado inválido.');$d['status']=$status;$d['staff_message']=sanitize_textarea_field(wp_unslash($_POST['message']??''));$d['updated_at']=gmdate('c');update_post_meta($id,self::META,$d);
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('requests',array('request_id'=>$id)));exit;
    }
    public static function inbox() {
        if(!GE_WTP_Staff_Portal::can_access())return;
        echo '<section class="ge-panel ge-request-inbox"><h1>Solicitudes de presupuesto</h1><p><a href="'.esc_url(wp_nonce_url(add_query_arg('action','ge_landing_funnel_report',admin_url('admin-post.php')),'ge_landing_funnel_report')).'">Descargar métricas del funnel</a></p>';$id=absint($_GET['request_id']??0);
        if(!$id){$rows=get_posts(array('post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>50));foreach($rows as $p){$d=self::get($p->ID,get_current_user_id());if(is_wp_error($d)||'draft'===$d['status'])continue;$u=get_userdata($d['customer_id']);echo '<article class="ge-request-history"><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('requests',array('request_id'=>$p->ID,'rapida'=>count($d['items'])===1?'1':''))).'"><strong>'.esc_html($u?$u->display_name:'Cliente').' · #'.$p->ID.'</strong></a><p>'.count($d['items']).' ítems · '.esc_html(GE_WTP_Job_Flow::request_label($d['status'])).' · para '.esc_html($d['needed_by']?wp_date('d/m/Y',strtotime($d['needed_by'])):'coordinar').'</p></article>';}echo '</section>';return;}
        $d=self::get($id,get_current_user_id());if(is_wp_error($d)){echo '<p>Solicitud no disponible.</p></section>';return;}$u=get_userdata($d['customer_id']);echo '<h2>'.esc_html($u->display_name).' · #'.$id.'</h2><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('customers',array('customer_id'=>$d['customer_id']))).'">Abrir cliente</a><p>Receptor: '.esc_html($d['billing_snapshot']['legal_name']??'Pendiente').' · '.esc_html($d['billing_snapshot']['cuit']??'').'</p><p>Para '.esc_html($d['needed_by']?wp_date('d/m/Y',strtotime($d['needed_by'])):'coordinar').' · '.esc_html('urgent'===$d['urgency']?'Urgente':'Fecha flexible').'</p><p>'.esc_html($d['notes']).'</p>';
        if($d['quote_id']){echo '<a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$d['quote_id']))).'">Abrir presupuesto vinculado</a></section>';return;}
        GE_WTP_Job_Flow::request_form($d);echo '</section>';
    }
    public static function dashboard() {
        $count=0;foreach(get_posts(array('post_type'=>self::TYPE,'post_status'=>'private','posts_per_page'=>-1)) as $p){$d=get_post_meta($p->ID,self::META,true);if('new'===($d['status']??''))$count++;}
        echo '<section class="ge-panel"><h2>Solicitudes nuevas · '.$count.'</h2><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('requests')).'">Revisar solicitudes de presupuesto</a></section>';
    }
}
