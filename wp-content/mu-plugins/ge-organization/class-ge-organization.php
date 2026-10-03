<?php
defined('ABSPATH') || exit;

/** Organization registry. Operational isolation uses a dedicated database per instance. */
final class GE_Organization {
    const ROOT = 'ge_organizations_v1';
    const PRIMARY = GE_ORGANIZATION_INSTANCE_ID;
    const AUDIT = 'ge_org_audit';
    const VERSION = 1;
    public static function init() {
        add_action('init', function () { register_post_type(self::AUDIT, array('public'=>false,'show_ui'=>false,'show_in_rest'=>false)); });
        add_action('admin_post_ge_org_action', array(__CLASS__, 'handle'));
        add_filter('wp_mail_from_name', function ($name) { $o=self::get(self::PRIMARY); return $o ? $o['settings']['email']['sender_name'] ?: $name : $name; });
        add_filter('wp_mail', array(__CLASS__, 'mail'));
        add_action('template_redirect', array(__CLASS__, 'guard'), 1);
        add_action('admin_init', array(__CLASS__, 'guard_admin'), 1);
        add_filter('rest_authentication_errors', function($result) { return self::qa_only(get_current_user_id()) ? new WP_Error('ge_org_qa_isolation','Acceso operativo QA no habilitado.',array('status'=>403)) : $result; }, 99);
        add_filter('user_has_cap', function($caps,$requested,$args,$user) {
            if(empty($caps['manage_options']) && self::qa_only($user->ID, false)) { foreach($caps as $key=>$value) { if($key!=='read')$caps[$key]=false; } }
            return $caps;
        },99,4);
    }
    public static function all() { return (array)get_option(self::ROOT, array()); }
    public static function get($id) { $all=self::all(); return $all[$id] ?? null; }
    public static function roles() { return array('owner','admin','comercial','produccion','administracion','staff','read-only'); }
    public static function modules() { return array('customers','quotes','orders','production','suppliers','stock','finance','cost_engine','ecommerce','communications','file_analyzer'); }
    public static function fields() {
        return array(
            'general'=>array('display_name','legal_name','brand_name','website','email','phone','whatsapp','address','timezone','locale','currency','country','tax_jurisdiction'),
            'branding'=>array('logo_url','favicon_url','primary_color'),
            'numbering'=>array('quote_prefix','order_prefix','work_prefix'),
            'documents'=>array('footer','terms','payment_terms','quote_valid_days','email_text','pdf_text'),
            'commercial'=>array('default_discount_percent','price_display'),
            'email'=>array('sender_name','reply_to'),
            'portal'=>array('domain','portal_domain','support_email','welcome_text'),
            'operations'=>array('internal_production','waste_percent'),
            'integrations'=>array('commerce_provider')
        );
    }
    public static function defaults() {
        $s=array(); foreach(self::fields() as $tab=>$fields) { $s[$tab]=array_fill_keys($fields,''); }
        $s['general']=array_merge($s['general'], array('timezone'=>'America/Argentina/Buenos_Aires','locale'=>'es_AR','currency'=>'ARS','country'=>'AR'));
        $s['branding']['primary_color']='#6d45ef'; $s['documents']['quote_valid_days']=15;
        $s['commercial']=array('default_discount_percent'=>0,'price_display'=>'existing');
        $s['operations']=array('internal_production'=>true,'waste_percent'=>0);
        $s['integrations']['commerce_provider']='none';
        $s['modules']=array_fill_keys(self::modules(),true); $s['integration_refs']=array();
        return $s;
    }
    public static function can($id,$actor,$write=false) {
        if (!self::get($id) || !$actor) { return false; }
        if (user_can($actor,'manage_options')) { return true; }
        $role=self::get($id)['members'][(string)$actor] ?? '';
        return $write ? in_array($role,array('owner','admin'),true) : in_array($role,self::roles(),true);
    }
    public static function audit($id,$actor,$event,$before,$after) {
        $p=wp_insert_post(array('post_type'=>self::AUDIT,'post_status'=>'private','post_title'=>$event,'post_author'=>(int)$actor),true);
        if(is_wp_error($p) || !$p) { return new WP_Error('ge_org_audit','No se pudo registrar la auditoría.'); }
        update_post_meta($p,'_ge_org_event',array('organization_id'=>$id,'actor'=>(int)$actor,'timestamp'=>gmdate('c'),'before'=>$before,'after'=>$after));
        return $p;
    }
    public static function locked($callback) {
        if(!add_option('ge_org_write_lock',gmdate('c'),'',false)) { return new WP_Error('ge_org_busy','Otro cambio está en curso. Reintentá.'); }
        try { return $callback(); } finally { delete_option('ge_org_write_lock'); }
    }
    public static function seed($actor) {
        if(!user_can($actor,'manage_options')) { return new WP_Error('forbidden','Sólo administrador de plataforma.'); }
        return self::locked(function() use($actor) {
            if(self::get(self::PRIMARY)) { return self::get(self::PRIMARY); }
            $s=self::defaults();
            $s['general']=array_merge($s['general'],array('display_name'=>get_bloginfo('name'),'brand_name'=>'Graph Express','website'=>home_url('/'),'email'=>get_option('admin_email'),'address'=>'Oruro 1253 · CABA'));
            $s['branding']['logo_url']=GE_WTP_PLUGIN_URL.'assets/images/graphex-simbolo.svg';
            $s['documents']['footer']='Graph Express · Oruro 1253 · CABA';
            $s['email']['sender_name']='Graph Express'; $s['integrations']['commerce_provider']='woocommerce';
            $mail=GE_WTP_Notification_Center::settings();
            $s['general']['email']=$mail['sender_email']??'';
            $s['email']['sender_name']=$mail['sender_name']??'Graph Express';
            $s['portal']['domain']=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
            $s['branding']['favicon_url']=get_site_icon_url();
            if(self::PRIMARY!=='graph-express') {
                $s=self::defaults();$s['general']['display_name']=get_bloginfo('name');
                $s['general']['brand_name']=get_bloginfo('name');$s['general']['website']=home_url('/');
                $s['general']['email']=get_option('admin_email');$s['email']['sender_name']=get_bloginfo('name');
                $s['portal']['domain']=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
                if(strpos($s['portal']['domain'],'.')===false)$s['portal']['domain']='';
            }
            $o=array('organization_id'=>self::PRIMARY,'active'=>true,'mode'=>'legacy-primary','created_at'=>gmdate('c'),'revision'=>1,'settings'=>$s,'members'=>array((string)$actor=>'owner'),'onboarding'=>array());
            foreach(get_users(array('fields'=>'ID')) as $uid) {
                if((int)$uid===(int)$actor)continue;
                if(user_can($uid,'manage_options'))$o['members'][(string)$uid]='admin';
                elseif(user_can($uid,'manage_woocommerce')||user_can($uid,'ge_manage_operations'))$o['members'][(string)$uid]='staff';
            }
            $a=self::audit(self::PRIMARY,$actor,'organization_seeded',null,$o); if(is_wp_error($a))return $a;
            $all=self::all(); $all[self::PRIMARY]=$o; update_option(self::ROOT,$all,false); return $o;
        });
    }
    public static function validate($tab,$raw,$old) {
        if(!isset(self::fields()[$tab]))return new WP_Error('tab','Sección inválida.');
        $out=$old;
        foreach(self::fields()[$tab] as $key) {
            if(!array_key_exists($key,$raw))continue;
            if(is_array($raw[$key]))return new WP_Error('shape','Valor inválido.');
            $v=in_array($key,array('footer','terms','payment_terms','email_text','pdf_text','welcome_text'),true) ? sanitize_textarea_field((string)$raw[$key]) : sanitize_text_field((string)$raw[$key]);
            if(strlen($v)>4000)return new WP_Error('length','El texto excede 4000 caracteres.');
            if(in_array($key,array('website','logo_url','favicon_url'),true)) {
                if($v && (!filter_var($v,FILTER_VALIDATE_URL) || !in_array(strtolower(wp_parse_url($v,PHP_URL_SCHEME)),array('https','http'),true)))return new WP_Error('url','Usá una URL HTTP/HTTPS válida.');
                $v=esc_url_raw($v);
            }
            if(in_array($key,array('email','support_email','reply_to'),true) && $v && !is_email($v))return new WP_Error('email','Email inválido.');
            if($key==='primary_color' && !preg_match('/^#[a-fA-F0-9]{6}$/D',$v))return new WP_Error('color','Color hexadecimal inválido.');
            if($key==='timezone' && !in_array($v,DateTimeZone::listIdentifiers(),true))return new WP_Error('timezone','Zona horaria inválida.');
            if($key==='currency' && !preg_match('/^[A-Z]{3}$/D',$v))return new WP_Error('currency','Moneda ISO de tres letras.');
            if($key==='country' && !preg_match('/^[A-Z]{2}$/D',$v))return new WP_Error('country','País ISO de dos letras.');
            if($key==='locale' && !preg_match('/^[a-z]{2}_[A-Z]{2}$/D',$v))return new WP_Error('locale','Formato de idioma: es_AR.');
            if(in_array($key,array('domain','portal_domain'),true) && $v && !preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z]{2,}$/D',$v))return new WP_Error('domain','Ingresá un dominio sin protocolo ni ruta.');
            if(in_array($key,array('default_discount_percent','waste_percent'),true)) { if(!is_numeric($v)||$v<0||$v>100)return new WP_Error('percent','Porcentaje entre 0 y 100.'); $v=(float)$v; }
            if($key==='quote_valid_days') { if(!ctype_digit($v)||$v<1||$v>365)return new WP_Error('days','Validez entre 1 y 365 días.'); $v=(int)$v; }
            if($key==='price_display' && !in_array($v,array('existing','tax_inclusive','tax_exclusive'),true))return new WP_Error('prices','Política inválida.');
            if($key==='commerce_provider' && !in_array($v,array('none','woocommerce'),true))return new WP_Error('provider','Proveedor inválido.');
            if($key==='internal_production')$v=!empty($raw[$key]);
            $out[$key]=$v;
        }
        if($tab==='general' && empty($out['display_name']))return new WP_Error('name','El nombre de la empresa es obligatorio.');
        return $out;
    }
    public static function save($id,$actor,$revision,$tab,$raw) {
        if(!self::can($id,$actor,true))return new WP_Error('forbidden','Sin permiso para esta organización.');
        return self::locked(function()use($id,$actor,$revision,$tab,$raw){
            $all=self::all();$o=$all[$id];
            if((int)$revision!==$o['revision'])return new WP_Error('conflict','Los datos cambiaron. Volvé a abrir la sección.');
            $next=$o;
            if($tab==='modules') { foreach(self::modules() as $k)$next['settings']['modules'][$k]=!empty($raw[$k]); }
            elseif($tab==='users') {
                if(!empty($raw['new_email'])) {
                    if($id!==self::PRIMARY)return new WP_Error('instance','Creá usuarios operativos en su instancia propia.');
                    if(!in_array($raw['role']??'',self::roles(),true))return new WP_Error('role','Rol inválido.');
                    $email=sanitize_email($raw['new_email']);$login=sanitize_user($raw['new_login']??'',true);
                    if(!is_email($email)||!$login||email_exists($email)||username_exists($login))return new WP_Error('user','Login y email nuevos válidos requeridos.');
                    $new_uid=wp_insert_user(array('user_login'=>$login,'user_email'=>$email,'display_name'=>sanitize_text_field($raw['new_name']??$login),'user_pass'=>wp_generate_password(48),'role'=>GE_WTP_Staff_Portal::ROLE));
                    if(is_wp_error($new_uid))return $new_uid;$raw['user_id']=$new_uid;
                }
                $uid=(int)($raw['user_id']??0);$role=$raw['role']??'';
                if(!get_userdata($uid)||!in_array($role,self::roles(),true))return new WP_Error('user','Usuario o rol inválido.');
                if(!user_can($actor,'manage_options') && $uid===$actor)return new WP_Error('self','No podés cambiar tu propio rol.');
                $next['members'][(string)$uid]=$role;
                if(!in_array('owner',$next['members'],true))return new WP_Error('owner','Debe existir un owner.');
            } elseif($tab==='integrations' && isset($raw['integration'])) {
                $provider=sanitize_key($raw['integration']); $ref=(string)($raw['credential_ref']??'');
                if(!in_array($provider,array('arca','mercado-pago','email','woocommerce','google-drive'),true)||($ref&&!preg_match('/^[a-z][a-z0-9-]{2,79}$/D',$ref)))return new WP_Error('ref','Referencia simbólica inválida.');
                foreach($all as $other_id=>$other) { if($other_id!==$id && $ref && in_array($ref,$other['settings']['integration_refs'],true))return new WP_Error('shared_ref','La referencia ya está vinculada a otra empresa.'); }
                $next['settings']['integration_refs'][$provider]=$ref;
            } else { $v=self::validate($tab,$raw,$o['settings'][$tab]??array());if(is_wp_error($v))return $v;$next['settings'][$tab]=$v; }
            $next['revision']++;$next['updated_at']=gmdate('c');
            $a=self::audit($id,$actor,'settings_'.$tab,$o,$next);if(is_wp_error($a))return $a;
            $all[$id]=$next;update_option(self::ROOT,$all,false);return $next;
        });
    }
    public static function issuers($id) { return $id===self::PRIMARY ? GE_WTP_Billing_Issuers::all() : (array)get_option('ge_org_'.$id.'_issuers',array()); }
    public static function export_config($id,$actor) {
        if(!self::can($id,$actor,true))return new WP_Error('forbidden','Sin permiso para exportar.');
        $o=self::get($id);$s=array();foreach(self::fields() as $tab=>$keys)$s[$tab]=array_intersect_key($o['settings'][$tab],array_flip($keys));
        $s['modules']=$o['settings']['modules'];$issuers=array();
        $keys=array('id','display_name','legal_name','cuit','iibb','vat_status','fiscal_address','locality','province','postal_code','country','contact_email','contact_phone','commercial_brand','point_of_sale','invoice_types_allowed','default_for_scenarios','active','tax_rate_basis_points','common_price_policy','invoice_a_price_policy');
        foreach(self::issuers($id) as $p)$issuers[]=array_intersect_key($p,array_flip($keys));
        return array('manifest'=>array('format'=>'graphex-organization-config','schema_version'=>1,'exported_at'=>gmdate('c'),'source_organization'=>$id,'contains_secrets'=>false,'operational_data'=>false,'files'=>false,'integrations_require_reconfiguration'=>true),'organization'=>array('display_name'=>$s['general']['display_name']),'settings'=>$s,'issuer_profiles'=>$issuers,'users_roles'=>array('available_roles'=>self::roles(),'assignments_included'=>false));
    }
    public static function import_config($bundle,$actor) {
        if(!user_can($actor,'manage_options'))return new WP_Error('forbidden','Importación QA sólo para administrador de plataforma.');
        if(!is_array($bundle)||($bundle['manifest']['format']??'')!=='graphex-organization-config'||($bundle['manifest']['schema_version']??null)!==1)return new WP_Error('manifest','Formato o versión incompatible.');
        $settings=self::defaults();
        foreach(self::fields() as $tab=>$keys) { $raw=$bundle['settings'][$tab]??array();if(!is_array($raw))return new WP_Error('shape','Sección inválida.');$v=self::validate($tab,$raw,$settings[$tab]);if(is_wp_error($v))return $v;$settings[$tab]=$v; }
        foreach(self::modules() as $k)$settings['modules'][$k]=!empty($bundle['settings']['modules'][$k]);
        $issuers=array();$list=$bundle['issuer_profiles']??array();if(!is_array($list)||count($list)>50)return new WP_Error('issuers','Catálogo inválido.');
        foreach($list as $p) {
            if(!is_array($p))return new WP_Error('issuer','Emisor inválido.');
            $p['credentials_ref']='';$p['cert_ref']='';$p['verification_status']='pending';$p['relationship_confirmed']=false;
            $n=GE_WTP_Billing_Issuers::normalize($p,$p['id']??'');if(is_wp_error($n))return $n;
            if(isset($issuers[$n['id']]))return new WP_Error('duplicate','Emisor duplicado.');
            $n['revision']=1;$n['created_at']=gmdate('c');$n['source']='organization_config_import';$issuers[$n['id']]=$n;
        }
        return self::locked(function()use($settings,$issuers,$actor){
            $id='qa-'.str_replace('-','',wp_generate_uuid4());
            $o=array('organization_id'=>$id,'active'=>true,'mode'=>'qa-config-only','created_at'=>gmdate('c'),'revision'=>1,'settings'=>$settings,'members'=>array((string)$actor=>'owner'),'onboarding'=>array('company'=>true,'branding'=>true,'fiscal'=>!empty($issuers),'operations'=>false,'users'=>true,'integrations'=>false));
            $a=self::audit($id,$actor,'config_imported',null,$o);if(is_wp_error($a))return $a;
            if(!add_option('ge_org_'.$id.'_issuers',$issuers,'',false))return new WP_Error('store','No se pudo crear catálogo QA.');
            $all=self::all();$all[$id]=$o;update_option(self::ROOT,$all,false);return $o;
        });
    }
    public static function storage_key($id,$domain,$name) {
        if(!self::get($id)||!in_array($domain,array('private','artwork','invoices','suppliers','exports'),true)||!preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9_.-]{0,180}$/D',$name)||strpos($name,'..')!==false)return new WP_Error('path','Ruta inválida.');
        return 'organizations/'.$id.'/'.$domain.'/'.$name;
    }
    public static function qa_only($user,$check_admin=true) {
        $primary=self::get(self::PRIMARY); if(!$primary||!$user||isset($primary['members'][(string)$user]))return false;
        if($check_admin && user_can($user,'manage_options'))return false;
        foreach(self::all() as $id=>$o)if($id!==self::PRIMARY && isset($o['members'][(string)$user]))return true;
        return false;
    }
    public static function guard_admin() {
        if(self::qa_only(get_current_user_id()) && (($_POST['action']??'')!=='ge_org_action'))wp_die('Acceso operativo QA no habilitado.','',array('response'=>403));
    }
    public static function guard() {
        if(self::qa_only(get_current_user_id()) && !(is_page('gestion') && in_array($_GET['section']??'',array('company','profile'),true)))wp_die('Organización QA: acceso operativo todavía no habilitado.', '', array('response'=>403));
    }
    public static function brand($key,$fallback='') { $o=self::get(self::PRIMARY); return $o['settings']['general'][$key]??$fallback; }
    /** Apply a portable configuration only to this explicitly provisioned non-production instance. */
    public static function import_current($bundle,$actor) {
        if(self::PRIMARY==='graph-express' || !user_can($actor,'manage_options'))return new WP_Error('forbidden','Importación operativa sólo en instancia destino provisionada.');
        $binding=GE_Organization_Runtime::bind($actor);if(is_wp_error($binding))return $binding;
        $imported=self::import_config($bundle,$actor);if(is_wp_error($imported))return $imported;
        $id=$imported['organization_id'];$issuers=self::issuers($id);
        return self::locked(function()use($imported,$id,$issuers,$actor){
            $all=self::all();$before=$all[self::PRIMARY]??null;if(!$before)return new WP_Error('missing','Completá la identidad inicial.');
            $next=$before;$next['settings']=$imported['settings'];$next['mode']='isolated-instance';$next['revision']++;$next['onboarding']=$imported['onboarding'];$next['onboarding']['ready']=false;
            $a=self::audit(self::PRIMARY,$actor,'instance_config_imported',$before,$next);if(is_wp_error($a))return $a;
            update_option(GE_WTP_Billing_Issuers::OPTION,$issuers,false);
            $all[self::PRIMARY]=$next;unset($all[$id]);update_option(self::ROOT,$all,false);delete_option('ge_org_'.$id.'_issuers');return $next;
        });
    }
    public static function complete_onboarding($actor) {
        if(!self::can(self::PRIMARY,$actor,true))return new WP_Error('forbidden','Owner o admin requerido.');
        $o=self::get(self::PRIMARY);$s=$o['settings'];
        if(!$s['general']['display_name']||!$s['general']['country']||!$s['general']['currency']||!self::issuers(self::PRIMARY)||!in_array('owner',$o['members'],true))return new WP_Error('incomplete','Empresa, país, moneda, owner y un emisor son obligatorios.');
        $b=GE_Organization_Runtime::bind($actor);if(is_wp_error($b))return $b;
        return self::locked(function()use($actor){$all=self::all();$before=$all[self::PRIMARY];$next=$before;$next['mode']='isolated-instance';$next['onboarding']=array('company'=>true,'branding'=>true,'fiscal'=>true,'users'=>true,'modules'=>true,'integrations'=>true,'ready'=>true);$next['revision']++;$a=self::audit(self::PRIMARY,$actor,'onboarding_completed',$before,$next);if(is_wp_error($a))return $a;$all[self::PRIMARY]=$next;update_option(self::ROOT,$all,false);return $next;});
    }
    public static function mail($args) {
        $o=self::get(self::PRIMARY);if(!$o)return $args;
        $s=$o['settings'];
        $header='<strong style="letter-spacing:.1em">GRAPH EXPRESS</strong>';
        if(!is_string($args['message']??null) || strpos($args['message'],$header)===false)return $args;
        $brand=$s['general']['brand_name']?:$s['general']['display_name'];
        // Replace owned template slots, never fiscal/receiver names inside the message.
        $args['message']=str_replace(array($header,'Graph Express · Oruro 1253 · CABA'),array('<strong style="letter-spacing:.1em">'.esc_html(strtoupper($brand)).'</strong>',esc_html($s['documents']['footer'])),$args['message']);
        $args['subject']=str_replace(' · Graph Express',' · '.$brand,$args['subject']??'');
        // SMTP identity is intentionally retained; Reply-To only applies to organization operational mail.
        if($s['email']['reply_to'] && strpos($args['message']??'','correo operativo')!==false) { $headers=$args['headers']??array();if(is_string($headers))$headers=explode("\n",$headers);$headers[]='Reply-To: '.$s['email']['reply_to'];$args['headers']=$headers; }
        return $args;
    }
    public static function handle() {
        $actor=get_current_user_id();$id=sanitize_key($_POST['organization_id']??'');
        check_admin_referer('ge_org_'.$id);
        $action=sanitize_key($_POST['operation']??'save');
        if($action==='export') { $result=self::export_config($id,$actor);if(!is_wp_error($result)){self::audit($id,$actor,'config_exported',null,array('schema_version'=>1));nocache_headers();header('Content-Type: application/json');header('Content-Disposition: attachment; filename="organization-config.json"');echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;} }
        elseif($action==='complete_onboarding') { $result=$id===self::PRIMARY?self::complete_onboarding($actor):new WP_Error('instance','La operación corresponde a esta instancia.'); }
        elseif($action==='import'||$action==='import_current') { $file=$_FILES['bundle']??array();if(($file['error']??1)!==UPLOAD_ERR_OK||($file['size']??0)>1048576||!is_uploaded_file($file['tmp_name']??''))$result=new WP_Error('upload','Archivo JSON válido de hasta 1 MB requerido.');else{$bundle=json_decode(file_get_contents($file['tmp_name']),true);$result=$action==='import_current'?self::import_current($bundle,$actor):self::import_config($bundle,$actor);if(!is_wp_error($result))$id=$result['organization_id'];} }
        else $result=self::save($id,$actor,(int)($_POST['revision']??0),sanitize_key($_POST['tab']??''),wp_unslash($_POST['values']??array()));
        $tab=sanitize_key($_POST['tab']??'general');
        if(is_wp_error($result)) { wp_die(esc_html($result->get_error_message()),'Mi empresa',array('response'=>400,'back_link'=>true)); }
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('company',array('organization_id'=>$id,'tab'=>$tab,'saved'=>1)));exit;
    }
}
