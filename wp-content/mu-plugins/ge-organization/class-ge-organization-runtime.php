<?php
defined('ABSPATH') || exit;

/** V1 deployment model: one organization per database, document root and credential namespace. */
final class GE_Organization_Runtime {
    const BINDING = 'ge_organization_instance_binding_v1';
    private static $meta_before=array();
    private static $auditing=false;
    public static function init() {
        add_action('init', array(__CLASS__, 'verify_binding'), -100);
        add_action('admin_init', array(__CLASS__, 'guard_action'), -100);
        add_action('template_redirect', array(__CLASS__, 'guard_page'), -100);
        add_action('template_redirect', array(__CLASS__, 'guard_extension_page'), -99);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'guard_rest'), -100, 3);
        add_filter('user_has_cap', array(__CLASS__, 'caps'), 110, 4);
        add_filter('woocommerce_currency', function($v){$s=self::settings();return $s['general']['currency']??$v;});
        add_filter('woocommerce_order_number',function($number,$order){$s=$order->get_meta('_ge_organization_snapshot');if(!empty($s['numbering']['order_prefix']))return $s['numbering']['order_prefix'].($order->get_meta('_ge_work_number')?:$order->get_id());return $number;},40,2);
        foreach(array('ge_operations_stock_enabled'=>'stock','ge_operations_finance_enabled'=>'finance','ge_operations_suppliers_enabled'=>'suppliers','ge_cost_engine_enabled'=>'cost_engine') as $option=>$module) {
            add_filter('pre_option_'.$option, function($v)use($module){return self::enabled($module)?$v:'no';});
        }
        add_action('wp_head', array(__CLASS__, 'portal_brand'));
        add_action('wp_insert_post', function($id,$post,$update){if(!$update && strpos($post->post_type,'ge_')===0)update_post_meta($id,'_ge_organization_id',GE_Organization::PRIMARY);},10,3);
        add_action('woocommerce_new_order', function($id,$order=null){$order=$order?:wc_get_order($id);if($order){$order->update_meta_data('_ge_organization_id',GE_Organization::PRIMARY);$order->update_meta_data('_ge_organization_snapshot',self::snapshot());$order->save();}},10,2);
        add_action('user_register', function($id){update_user_meta($id,'_ge_organization_id',GE_Organization::PRIMARY);});
        foreach(array('post','user') as $type) {
            add_filter('update_'.$type.'_metadata',function($check,$id,$key,$value)use($type){
                if(self::$auditing || !get_current_user_id() || strpos($key,'_ge_')!==0 || $key==='_ge_org_event')return $check;
                self::$meta_before[$type.'|'.$id.'|'.$key]=get_metadata($type,$id,$key,true);return $check;
            },10,4);
            add_action('updated_'.$type.'_meta',function($meta_id,$id,$key,$value)use($type){
                $index=$type.'|'.$id.'|'.$key;if(self::$auditing || !array_key_exists($index,self::$meta_before))return;
                $before=self::$meta_before[$index];unset(self::$meta_before[$index]);self::$auditing=true;
                try{$r=GE_Organization::audit(GE_Organization::PRIMARY,get_current_user_id(),'operational_meta_updated',array('type'=>$type,'record_id'=>(int)$id,'field'=>$key,'value'=>$before),array('value'=>$value));if(is_wp_error($r))wp_die('Cambio aplicado; falló el registro de auditoría. Requiere revisión.','',array('response'=>409));}finally{self::$auditing=false;}
            },10,4);
        }
    }
    public static function settings() { $o=GE_Organization::get(GE_Organization::PRIMARY);return $o['settings']??array(); }
    public static function binding() {
        global $wpdb;
        return array('organization_id'=>GE_Organization::PRIMARY,'database'=>DB_NAME,'table_prefix'=>$wpdb->prefix,'document_root'=>realpath(ABSPATH),'home'=>untrailingslashit(home_url()),'storage_root'=>realpath(WP_CONTENT_DIR));
    }
    public static function bind($actor) {
        if(!user_can($actor,'manage_options') && !GE_Organization::can(GE_Organization::PRIMARY,$actor,true))return new WP_Error('forbidden','Administrador de instancia requerido.');
        $old=get_option(self::BINDING);$b=self::binding();
        if($old && $old!==$b)return new WP_Error('instance_mismatch','La base ya pertenece a otra instancia.');
        if(!$old)add_option(self::BINDING,$b,'',false);
        return $b;
    }
    public static function verify_binding() {
        $b=get_option(self::BINDING);
        if($b && $b!==self::binding())wp_die('La identidad de organización no coincide con su recurso.', '', array('response'=>503));
        // Never allow the URL to select a database, root, or operational organization.
        if(isset($_REQUEST['operational_organization_id']) && $_REQUEST['operational_organization_id']!==GE_Organization::PRIMARY)self::deny();
        if(isset($_REQUEST['organization_id']) && ($_REQUEST['section']??'')!=='company' && ($_REQUEST['action']??'')!=='ge_org_action' && $_REQUEST['organization_id']!==GE_Organization::PRIMARY)self::deny();
        if(!empty($_SERVER['HTTP_X_GE_ORGANIZATION']) && $_SERVER['HTTP_X_GE_ORGANIZATION']!==GE_Organization::PRIMARY)self::deny();
    }
    public static function enabled($module) {
        $s=self::settings();return !isset($s['modules']) || !empty($s['modules'][$module]);
    }
    public static function role($actor) { $o=GE_Organization::get(GE_Organization::PRIMARY);return $o['members'][(string)$actor]??''; }
    public static function permissions() {
        $all=GE_Organization::modules();
        return array(
            'owner'=>array('read'=>$all,'write'=>$all), 'admin'=>array('read'=>$all,'write'=>$all),
            'comercial'=>array('read'=>array('customers','quotes','orders','production','cost_engine','communications','file_analyzer'),'write'=>array('customers','quotes','orders','communications','file_analyzer')),
            'produccion'=>array('read'=>array('orders','production','suppliers','stock','file_analyzer'),'write'=>array('production','file_analyzer')),
            'administracion'=>array('read'=>array('customers','quotes','orders','suppliers','stock','finance','communications'),'write'=>array('finance','suppliers','communications')),
            'staff'=>array('read'=>array('customers','quotes','orders','production','suppliers','stock','cost_engine','file_analyzer'),'write'=>array('customers','quotes','orders','production','file_analyzer')),
            'read-only'=>array('read'=>$all,'write'=>array())
        );
    }
    public static function allowed($module,$write=false,$actor=null) {
        $actor=$actor===null?get_current_user_id():(int)$actor;
        if(!self::enabled($module))return false;
        $role=self::role($actor);
        if($role)return in_array($module,self::permissions()[$role][$write?'write':'read']??array(),true);
        // Preserve platform administrators; ordinary customer/supplier ownership remains enforced by existing handlers.
        return $actor && user_can($actor,'manage_options');
    }
    public static function require_permission($module,$write=false,$actor=null) {
        if(!self::allowed($module,$write,$actor))throw new RuntimeException('Acceso denegado por organización, módulo o rol.',403);
    }
    public static function caps($caps,$requested,$args,$user) {
        $role=self::role($user->ID);
        // Organization owners administer their business, never executable platform code.
        if(GE_Organization::get(GE_Organization::PRIMARY))foreach(array('edit_plugins','edit_themes','edit_files','install_plugins','install_themes','update_plugins','update_themes','delete_plugins','delete_themes','update_core','activate_plugins','switch_themes','unfiltered_upload','unfiltered_html','promote_users','create_users','edit_users','delete_users','manage_network_users','manage_network_plugins','manage_network_themes') as $platform_cap)$caps[$platform_cap]=false;
        if(!$role)return $caps;
        $p=self::permissions()[$role]??array('read'=>array(),'write'=>array());
        $caps['ge_manage_operations']=!empty($p['read']);
        foreach(array('ge_view_inventory'=>array('stock',false),'ge_manage_inventory'=>array('stock',true),'ge_view_finance'=>array('finance',false),'ge_record_finance'=>array('finance',true),'ge_view_costs'=>array('cost_engine',false),'ge_manage_costs'=>array('cost_engine',true),'ge_manage_products'=>array('cost_engine',true),'ge_manage_communications'=>array('communications',true),'ge_manage_billing_issuers'=>array('company',true)) as $cap=>$policy) {
            $caps[$cap]=$policy[0]==='company'?in_array($role,array('owner','admin'),true):self::enabled($policy[0])&&in_array($policy[0],$p[$policy[1]?'write':'read'],true);
        }
        if(!in_array($role,array('owner','admin'),true)) {
            // Do not inherit administrator/shop-manager privileges through a WordPress role.
            $org_caps=array('read','ge_manage_operations','ge_view_inventory','ge_manage_inventory','ge_view_finance','ge_record_finance','ge_view_costs','ge_manage_costs','ge_manage_products','ge_manage_communications','ge_manage_billing_issuers');
            foreach($caps as $cap=>$value)if(!in_array($cap,$org_caps,true))$caps[$cap]=false;
        }
        return $caps;
    }
    public static function module_for($name) {
        $n=strtolower($name);
        if(preg_match('/ge_invoice|customer_invoice/',$n))return 'finance';
        if(preg_match('/organization|billing_issuer|tax_resolver|credential|notification_center|settings|canva_(connect|callback|disconnect)|save_canva|google_auth/',$n))return 'company';
        if(preg_match('/internal_alert/',$n))return 'quotes';
        if(preg_match('/finance|administration|payable|expense|record_payment/',$n))return 'finance';
        if(preg_match('/stock|inventory|equipment|counter|recipe|purchase/',$n))return 'stock';
        if(preg_match('/supplier/',$n))return 'suppliers';
        if(preg_match('/file_analysis|file_analyzer|ai[-_]artwork|analy[sz]/',$n))return 'file_analyzer';
        if(preg_match('/production/',$n))return 'production';
        if(preg_match('/commercial_quote|quote_billing|quote_artwork|quote_balance|commercial_checkout|portal_quote|quote_api|customer_quote|cost_quote|quote/',$n))return 'quotes';
        if(preg_match('/artwork|document|reorder|order|checkout/',$n))return 'orders';
        if(preg_match('/customer|branch|profile|verification/',$n))return 'customers';
        if(preg_match('/notification|newsletter|communication|campaign|crm|quick_repl|pipeline|opportunity|lead/',$n))return 'communications';
        if(preg_match('/cost|catalog|product|bna/',$n))return 'cost_engine';
        if(preg_match('/woo|commerce|payment|mercado/',$n))return 'ecommerce';
        return '';
    }
    public static function action_policy($action) {
        global $wp_filter;
        $name=$action;$hook=(wp_doing_ajax()?'wp_ajax_':'admin_post_').$action;
        if(isset($wp_filter[$hook]))foreach($wp_filter[$hook]->callbacks as $callbacks)foreach($callbacks as $c) {
            $f=$c['function'];if(is_array($f))$name.=' '.(is_object($f[0])?get_class($f[0]):$f[0]).' '.$f[1];
        }
        if($action==='ge_operations')$name=(string)($_REQUEST['operation']??$_REQUEST['op']??$_REQUEST['operation_action']??''). ' '.wp_json_encode($_POST);
        $module=self::module_for($action) ?: self::module_for($name);
        if($action==='ge_operations')$module=self::module_for((string)($_REQUEST['op']??$_REQUEST['operation']??''));
        if(in_array($action,array('ge_markcom_add_cart','ge_markcom_remove_cart'),true))$module='ecommerce';
        if(strpos($action,'ge_vps_')===0)$module='orders';
        if(preg_match('/^ge_canva_(open_design|create_design|export_start|export_status)$/',$action))$module='file_analyzer';
        if(preg_match('/^ge_(workflow_|send_review_email|open_review_whatsapp|save_review_url|confirm_delivery)/',$action))$module='production';
        if($action==='ge_delivery_label')$module='orders';
        if($action==='ge_request_save')$module='quotes';
        if($action==='ge_save_billing_entity')$module='company';
        if($action==='ge_google_auth')$module='customers';
        if(self::role(get_current_user_id())==='administracion' && ($action==='ge_order_payment_policy' || ($action==='ge_customer_workspace_save' && ($_POST['section']??'')==='payment_policy')))$module='finance';
        if(preg_match('/^ge_(customer_tax|tax_profile|customer_profile|customer_register|markcom_login|portal_login)/',$action))$module='customers';
        if(preg_match('/candidate|career|job|knowledge|incident|favorite/',$action))$module='communications';
        $read=preg_match('/download|preview|original|release_sheet|production_sheet|_pdf|document_file|avatar|verify_customer_email|delivery_label|export_status|open_design|^ge_alert_read$/',$action);
        return array($module,!$read);
    }
    public static function deny() { wp_die('Acceso denegado por organización, módulo o rol.','',array('response'=>403)); }
    public static function guard_action() {
        $post_type=sanitize_key($_REQUEST['post_type']??'');
        if(!$post_type && !empty($_REQUEST['post']))$post_type=get_post_type((int)$_REQUEST['post']);
        $native=array('product'=>'cost_engine','product_variation'=>'cost_engine','shop_order'=>'orders','shop_order_refund'=>'finance','ge_commercial_quote'=>'quotes');
        if(isset($native[$post_type]) && !self::allowed($native[$post_type],($_SERVER['REQUEST_METHOD']??'GET')!=='GET'))self::deny();
        $action=sanitize_key($_REQUEST['action']??'');if(!$action)return;
        if(strpos($action,'ge_')!==0 && strpos($action,'graphex_')!==0 && strpos($action,'woocommerce')!==0)return;
        list($module,$write)=self::action_policy($action);
        if($module==='company') { if($action==='ge_org_action')return;if(!GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),$write))self::deny();return; }
        if(!$module)self::deny();
        if(!self::enabled($module))self::deny();
        if(self::role(get_current_user_id()) && !self::allowed($module,$write)) {
            $production_file=self::role(get_current_user_id())==='produccion' && $module==='orders' && preg_match('/artwork|upload|document/',$action) && self::allowed('production',$write);
            if(!$production_file)self::deny();
        }
        if($action==='ge_commercial_quote_convert' && !self::allowed('orders',true))self::deny();
    }
    public static function guard_page() {
        if((function_exists('is_shop') && (is_shop()||is_product()||is_cart()||is_checkout())) && !self::enabled('ecommerce'))self::deny();
        if(!is_page('gestion') && isset($_GET['seccion'])) {
            $portal=array('presupuestos'=>'quotes','pedidos'=>'orders','archivos'=>'orders','documentos'=>'orders','perfil'=>'customers','catalogo'=>'ecommerce');
            $pm=$portal[sanitize_key($_GET['seccion'])]??'';if($pm && !self::enabled($pm))self::deny();
        }
        if(!is_page('gestion'))return;
        $section=sanitize_key($_GET['section']??'dashboard');
        $o=GE_Organization::get(GE_Organization::PRIMARY);
        if(GE_Organization::PRIMARY!=='graph-express' && empty($o['onboarding']['ready']) && !in_array($section,array('company','profile'),true)){wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('company',array('tab'=>'onboarding')));exit;}
        if(in_array($section,array('company','profile','dashboard'),true))return;
        if($section==='jobs'){if(class_exists('GE_WTP_Work_Panel') && GE_WTP_Work_Panel::accessible())return;self::deny();}
        $map=array('invoice-reviews'=>'finance','requests'=>'quotes','administration'=>'finance','costs'=>'cost_engine','library'=>'orders','supplier-invoices'=>'suppliers','notifications'=>'company','settings'=>'company');
        $m=$map[$section]??self::module_for($section);
        if($m==='company'){if(!GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),true))self::deny();return;}
        if(!$m || !self::allowed($m,false))self::deny();
    }
    public static function guard_rest($result,$server,$request) {
        $route=$request->get_route();if(strpos($route,'/ge/')!==0 && strpos($route,'/ge-')!==0 && strpos($route,'/wc/')!==0 && strpos($route,'/wc-')!==0)return $result;
        $m=strpos($route,'/wc')===0?'ecommerce':self::module_for($route);
        if(strpos($route,'/ge/v1/costs/')===0)$m='cost_engine';
        $write=!in_array($request->get_method(),array('GET','HEAD','OPTIONS'),true);
        if(preg_match('#^/ge/v1/(?:operations|costs)/.*\.(?:get|find|list|summary|report|quote)$#',$route))$write=false;
        if($m==='company') { if(!GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),$request->get_method()!=='GET'))return new WP_Error('ge_org_role','Rol sin permiso.',array('status'=>403));return $result; }
        if(!$m || !self::enabled($m))return new WP_Error('ge_org_module','Módulo deshabilitado.',array('status'=>403));
        if(self::role(get_current_user_id()) && !self::allowed($m,$write))return new WP_Error('ge_org_role','Rol sin permiso.',array('status'=>403));
        return $result;
    }
    public static function guard_extension_page() {
        // CRM's legacy bridge removes guard_page because older Foundation could not map CRM.
        // Preserve its own authentication/feature gate while enforcing the reconciled organization policy.
        if(is_page('gestion') && ($_GET['section']??'')==='crm' && !self::allowed('communications',false))self::deny();
    }
    public static function snapshot() {
        $o=GE_Organization::get(GE_Organization::PRIMARY);if(!$o)return array();
        $s=$o['settings'];$out=array('organization_id'=>GE_Organization::PRIMARY,'revision'=>$o['revision'],'general'=>$s['general'],'branding'=>$s['branding'],'documents'=>$s['documents'],'commercial'=>$s['commercial'],'numbering'=>$s['numbering'],'captured_at'=>gmdate('c'));
        $url=$s['branding']['logo_url'];
        if(GE_Organization::PRIMARY==='graph-express' && substr($url,-19)==='graphex-simbolo.svg')$out['branding']['logo_kind']='legacy-symbol';
        else {
            $aid=$url?attachment_url_to_postid($url):0;$path=$aid?get_attached_file($aid):false;$uploads=wp_upload_dir();
            if($path && realpath($path) && realpath($uploads['basedir']) && strpos(realpath($path),realpath($uploads['basedir']).DIRECTORY_SEPARATOR)===0 && filesize($path)<=1048576) {
                $data=file_get_contents($path);$info=getimagesizefromstring($data);
                if($info && $info[0]<=4096 && $info[1]<=4096) {
                    if($info[2]!==IMAGETYPE_JPEG && function_exists('imagecreatefromstring')) { $im=imagecreatefromstring($data);if($im){ob_start();imagejpeg($im,null,90);$data=ob_get_clean();imagedestroy($im);} }
                    $info=getimagesizefromstring($data);if($info && $info[2]===IMAGETYPE_JPEG)$out['branding']['logo_jpeg']=base64_encode($data);
                }
            }
        }
        $out['hash']=hash('sha256',wp_json_encode($out));return $out;
    }
    public static function document_directory() {
        // Existing Graph document names continue resolving inside its exclusively bound resource.
        if(GE_Organization::PRIMARY==='graph-express')return WP_CONTENT_DIR.'/ge-private/markcom';
        return WP_CONTENT_DIR.'/ge-private/organizations/'.GE_Organization::PRIMARY.'/documents';
    }
    public static function quote_number($id,$snapshot) {
        $prefix=$snapshot['organization_snapshot']['numbering']['quote_prefix']??'';
        if($prefix)return $prefix.(class_exists('GE_WTP_Gestion_V3')?(GE_WTP_Gestion_V3::lookup('quote_id',$id)?:$id):$id);
        return class_exists('GE_WTP_Gestion_V3')?GE_WTP_Gestion_V3::quote_number($id):'PRE-'.$id;
    }
    public static function next_work_number() {
        if(!class_exists('GE_WTP_Gestion_V3') || !GE_WTP_Gestion_V3::numbers_enabled())return null;
        global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',GE_WTP_Gestion_V3::table()));
    }
    public static function email_summary($snapshot) {
        $o=$snapshot['organization_snapshot']??array();if(!$o)return '';
        $g=$o['general'];$d=$o['documents'];$html='<div class="ge-org-document">';
        if(!empty($o['branding']['logo_url']))$html.='<img src="'.esc_url($o['branding']['logo_url']).'" width="48" alt="">';
        $html.='<p><strong>'.esc_html($g['brand_name']?:$g['display_name']).'</strong><br>'.esc_html($g['legal_name']).'<br>'.esc_html(implode(' · ',array_filter(array($g['email'],$g['phone'],$g['address'])))).'</p>';
        $html.='<p>'.nl2br(esc_html($d['email_text'])).'<br>'.nl2br(esc_html($d['terms'])).'<br>'.nl2br(esc_html($d['payment_terms'])).'</p></div>';
        return $html;
    }
    public static function portal_brand() {
        $s=self::settings();if(!$s)return;
        $color=$s['branding']['primary_color'];
        echo '<style>:root{--ge-accent:'.esc_html($color).';--ge-v3-accent:'.esc_html($color).'}</style>';
        $sections=array('customers'=>'customers','quotes'=>'quotes','requests'=>'quotes','orders'=>'orders','production'=>'production','suppliers'=>'suppliers','stock'=>'stock','invoice-reviews'=>'finance','administration'=>'finance','costs'=>'cost_engine','communications'=>'communications','library'=>'orders');
        echo '<style>';foreach($sections as $section=>$module)if(!self::enabled($module) || (self::role(get_current_user_id())&&!self::allowed($module)))echo 'a[href*="section='.esc_attr($section).'"]{display:none!important}';echo '</style>';
        if(function_exists('is_account_page')&&is_account_page()) {
            echo '<meta name="application-name" content="'.esc_attr($s['general']['display_name']).'">';
        }
    }
    public static function dashboard() {
        echo '<div class="ge-staff-heading"><div><h1>'.esc_html(GE_Organization::brand('display_name')).'</h1><p>Organización: '.esc_html(GE_Organization::PRIMARY).'</p></div></div><section class="ge-company-panel"><h2>Tu operación</h2><p>Usá los módulos habilitados para tu rol.</p><div class="ge-company-organizations">';
        $sections=array('customers'=>'Clientes','quotes'=>'Presupuestos','orders'=>'Pedidos','production'=>'Producción','suppliers'=>'Proveedores','stock'=>'Stock','administration'=>'Administración','costs'=>'Costos y Productos','communications'=>'Comunicaciones');
        foreach($sections as $section=>$label){$m=self::module_for($section);if(self::allowed($m))echo '<a href="'.esc_url(GE_WTP_Staff_Portal::portal_url($section)).'">'.esc_html($label).'</a>';}
        echo '</div></section>';
    }
    public static function search($text) {
        echo '<section class="ge-company-panel"><h1>Buscar</h1>';
        if(self::allowed('customers'))foreach(get_users(array('search'=>'*'.$text.'*','search_columns'=>array('user_login','user_email','display_name'),'number'=>20)) as $u) {
            if(self::role($u->ID))continue;
            echo '<p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('customers',array('customer_id'=>$u->ID))).'">'.esc_html($u->display_name).'</a></p>';
        }
        foreach(array('quotes'=>'ge_commercial_quote','orders'=>'shop_order') as $module=>$type)if(self::allowed($module))foreach(get_posts(array('post_type'=>$type,'post_status'=>array('private','wc-processing','wc-pending','wc-on-hold'),'s'=>$text,'numberposts'=>20)) as $p)echo '<p><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url($module,array($module==='quotes'?'quote_id':'order_id'=>$p->ID))).'">'.esc_html($p->post_title).'</a></p>';
        echo '</section>';
    }
}
