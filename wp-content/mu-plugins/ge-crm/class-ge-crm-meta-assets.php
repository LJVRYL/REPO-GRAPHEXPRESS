<?php
defined('ABSPATH') || exit;

/** Owner-only, read-only verification prevents connecting the CRM to an old business. */
final class GE_CRM_Meta_Assets {
    const BUSINESS='1811159106704793';
    const PAGE='1377222212141366';
    const INSTAGRAM='17841407285480956';
    const APP='2684180385332773';
    const OPTION='ge_crm_meta_assets_graph-express';
    public static function init() {
        add_action('ge_crm_meta_assets',array(__CLASS__,'render'));
        add_action('admin_post_ge_crm_verify_meta_assets',array(__CLASS__,'verify'));
    }
    private static function get($path,$token,$usable_until=0) {
        if ($usable_until && time()>=$usable_until) throw new RuntimeException('Venció el acceso de prueba. Renovalo antes de consultar Meta.',409);
        $response=wp_remote_get('https://graph.facebook.com/v26.0/'.$path,array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>array('Authorization'=>'Bearer '.$token)));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) throw new RuntimeException('Meta no pudo confirmar los activos. Revisá la conexión o los permisos; no se modificó ningún activo.',422);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($data)) throw new RuntimeException('Meta devolvió una respuesta sin datos verificables.',422);
        return $data;
    }
    private static function find($data,$id) {
        if (!is_array($data['data'] ?? null)) throw new RuntimeException('Meta no devolvió una lista verificable de activos.',422);
        foreach (($data['data'] ?? array()) as $row) if (is_array($row) && ($row['id'] ?? '')===$id) return $row;
        throw new RuntimeException('El activo Graphex esperado no está confirmado. Revisá la pertenencia de la conexión.',422);
    }
    public static function inspect() {
        GE_CRM::admin();
        if (GE_CRM::org()!=='graph-express') throw new RuntimeException('Organización incorrecta.',403);
        $cfg=GE_CRM_Outbound::config();$test=$cfg['whatsapp_test'] ?? array();$page=$cfg['messenger'] ?? array();
        if (empty($test['enabled']) || !GE_Meta_Test_Assets::outbound($test) || empty($page['enabled']) || ($page['asset_id'] ?? '')!==self::PAGE
            || !is_string($test['access_token'] ?? null) || !$test['access_token'] || !is_string($page['access_token'] ?? null) || !$page['access_token']
            || !is_int($test['usable_until'] ?? null) || time()>=$test['usable_until']) throw new RuntimeException('Primero verificá las credenciales de Graphex; el acceso de prueba debe estar vigente.',409);
        $user=$test['access_token'];$pageToken=$page['access_token'];
        $business=self::get(self::BUSINESS.'?fields=id,name',$user,$test['usable_until']);
        if (($business['id'] ?? '')!==self::BUSINESS) throw new RuntimeException('El portfolio no corresponde a Graphex.',422);
        // Enumerate identifiers only, then retain solely the authorized Graphex Page.
        self::find(self::get('me/accounts?fields=id&limit=100',$user,$test['usable_until']),self::PAGE);
        $p=self::get(self::PAGE.'?fields=id,name,instagram_business_account',$pageToken);
        if (($p['id'] ?? '')!==self::PAGE || ($p['instagram_business_account']['id'] ?? '')!==self::INSTAGRAM) throw new RuntimeException('La página y el Instagram vinculados no corresponden a Graphex.',422);
        $ig=self::get(self::INSTAGRAM.'?fields=id,username',$pageToken);
        if (($ig['id'] ?? '')!==self::INSTAGRAM || ($ig['username'] ?? '')!=='graphex.ar') throw new RuntimeException('Instagram no confirmó @graphex.ar.',422);
        $subscription=self::find(self::get(self::PAGE.'/subscribed_apps?fields=id,subscribed_fields&limit=100',$pageToken),self::APP);
        $required=array('messages','message_deliveries','message_reads','message_echoes');
        if (!is_array($subscription['subscribed_fields'] ?? null) || array_diff($required,$subscription['subscribed_fields'])) throw new RuntimeException('Falta confirmar una suscripción de mensajes de la página.',422);
        $phone=self::find(self::get(GE_Meta_Test_Assets::WABA.'/phone_numbers?fields=id,display_phone_number&limit=25',$user,$test['usable_until']),GE_Meta_Test_Assets::PHONE);
        if (preg_replace('/\D/','',$phone['display_phone_number'] ?? '')!==GE_Meta_Test_Assets::NUMBER) throw new RuntimeException('Meta no confirmó el número oficial de prueba.',422);
        $templates=self::get(GE_Meta_Test_Assets::WABA.'/message_templates?fields=id,name,status,category&limit=25',$user,$test['usable_until']);$safe=array();
        if (!is_array($templates['data'] ?? null)) throw new RuntimeException('Meta no devolvió una lista verificable de plantillas.',422);
        foreach ($templates['data'] as $row) {
            if (!is_array($row)) continue;
            $name=$row['name'] ?? '';
            if (!is_string($name) || ($name!=='hello_world' && !preg_match('/^graphex_prueba_[a-z0-9_]{1,80}$/D',$name))) continue;
            $safe[]=array('name'=>$name,'status'=>sanitize_text_field($row['status'] ?? ''),'category'=>sanitize_text_field($row['category'] ?? ''));
        }
        // Never persist tokens, foreign Page metadata, template bodies or provider errors.
        return array('verified'=>true,'verified_at'=>gmdate('c'),'business_id'=>self::BUSINESS,'business_name'=>sanitize_text_field($business['name'] ?? ''),
            'page_id'=>self::PAGE,'page_name'=>sanitize_text_field($p['name'] ?? ''),'instagram_id'=>self::INSTAGRAM,'instagram_username'=>'graphex.ar',
            'app_id'=>self::APP,'subscribed_fields'=>$required,'waba_id'=>GE_Meta_Test_Assets::WABA,'phone_id'=>GE_Meta_Test_Assets::PHONE,
            'phone_number'=>'+1 555 650-5576','templates'=>$safe,'read_only'=>true,'commercial_whatsapp_verified_in_this_check'=>false);
    }
    public static function verify() {
        try { GE_CRM::admin(); } catch (RuntimeException $e) { wp_die('Acceso denegado.','',array('response'=>403)); }
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') wp_die('Se requiere una solicitud POST.','',array('response'=>405));
        check_admin_referer('ge_crm_verify_meta_assets');
        try { $result=self::inspect(); }
        catch (RuntimeException $e) { $result=array('verified'=>false,'checked_at'=>gmdate('c'),'error'=>$e->getMessage()); }
        update_option(self::OPTION,$result,false);
        if (!empty($result['verified'])) GE_CRM::event(0,0,'crm_meta_assets_verified',array('business_id'=>self::BUSINESS,'page_id'=>self::PAGE,'instagram_id'=>self::INSTAGRAM,'test_waba_id'=>GE_Meta_Test_Assets::WABA,'verified_at'=>$result['verified_at'],'read_only'=>true));
        wp_safe_redirect(GE_CRM_UI::url('meta_assets'));exit;
    }
    public static function render() {
        try { GE_CRM::admin(); } catch (RuntimeException $e) { wp_die('Acceso denegado.','',array('response'=>403)); }
        echo '<section class="ge-crm-panel"><h2>Activos Meta de Graphex</h2><p>Comprobá que el portfolio, la página y el Instagram corresponden a Graphex antes de atender desde el CRM. La verificación consulta Meta y conserva sólo los datos de estos activos propios.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('ge_crm_verify_meta_assets');echo '<input type="hidden" name="action" value="ge_crm_verify_meta_assets"><button>Verificar activos con Meta</button></form><p>Esta consulta no modifica cuentas, números, plantillas ni suscripciones. WhatsApp comercial requiere su conexión de producción; aquí se comprueba únicamente el número oficial de prueba.</p></section>';
        self::render_summary();
    }
    public static function render_summary($review=false) {
        $channels=array('instagram','messenger','whatsapp');
        if ($review) {
            $cfg=GE_Meta_Review_Policy::authorize(GE_Meta_Review::config(),(int)get_current_user_id(),time());
            $channels=array_column($cfg['bindings'],'channel');
        } else GE_CRM::admin();
        $result=get_option(self::OPTION,array());
        if (empty($result['verified']) || ($result['business_id'] ?? '')!==self::BUSINESS || ($result['page_id'] ?? '')!==self::PAGE
            || ($result['instagram_id'] ?? '')!==self::INSTAGRAM || ($result['waba_id'] ?? '')!==GE_Meta_Test_Assets::WABA
            || ($result['phone_id'] ?? '')!==GE_Meta_Test_Assets::PHONE || ($result['app_id'] ?? '')!==self::APP) {
            echo '<p role="status" class="ge-crm-panel">'.esc_html($review?'No verified asset snapshot is available.':($result['error'] ?? 'Todavía no hay una verificación de los activos guardada.')).'</p>';return;
        }
        echo '<p role="status" class="ge-crm-notice">'.($review?'Last verified with Meta · ':'Última comprobación con Meta · ').esc_html($result['verified_at']).'</p><div class="ge-crm-fields">';
        $cards=array(($review?'Business portfolio':'Portfolio')=>array($result['business_name'],$result['business_id']));
        if (array_intersect($channels,array('instagram','messenger'))) $cards[$review?'Facebook Page':'Página de Facebook']=array($result['page_name'],$result['page_id']);
        if (in_array('instagram',$channels,true)) $cards[$review?'Linked Instagram':'Instagram vinculado']=array('@'.$result['instagram_username'],$result['instagram_id']);
        if (in_array('whatsapp',$channels,true)) $cards[$review?'Official WhatsApp test':'WhatsApp oficial de prueba']=array($result['phone_number'],'Phone '.$result['phone_id'].' · WABA '.$result['waba_id']);
        foreach($cards as $label=>$values) echo '<section class="ge-crm-panel"><h2>'.esc_html($label).'</h2><strong>'.esc_html($values[0]).'</strong><p>'.esc_html($values[1]).'</p></section>';
        echo '</div>';
        if (in_array('messenger',$channels,true)) {
            echo '<section class="ge-crm-panel"><h2>'.($review?'Messenger subscriptions':'Recepción de Messenger').'</h2><p>Graphex CRM · '.esc_html($result['app_id']).'</p><ul>';
            foreach(array('messages','message_deliveries','message_reads','message_echoes') as $field) echo '<li>'.esc_html($field).' · '.($review?'verified':'confirmada').'</li>';
            echo '</ul></section>';
        }
        if (in_array('whatsapp',$channels,true)) {
            echo '<section class="ge-crm-panel"><h2>'.($review?'Test-account templates':'Plantillas de la cuenta de prueba').'</h2><p>'.($review?'Read-only metadata for Graphex test templates and hello_world. No templates are created or sent.':'Sólo metadatos de plantillas de prueba de Graphex y hello_world. No crea ni envía plantillas.').'</p><ul>';
            foreach($result['templates'] as $row) echo '<li><strong>'.esc_html($row['name']).'</strong> · '.esc_html($row['status']).' · '.esc_html($row['category']).'</li>';
            echo '</ul></section>';
        }
    }
}
