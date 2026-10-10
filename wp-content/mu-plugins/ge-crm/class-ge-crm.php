<?php
defined('ABSPATH') || exit;

/** Native overlay: canonical customers/users, quote posts, Woo orders and mail logs stay in place. */
final class GE_CRM {
    const VERSION = 1;
    public static function org() { return GE_Organization::PRIMARY; }
    public static function table($name='records') { global $wpdb; return $wpdb->prefix.'ge_crm_'.$name; }
    public static function init() {
        require_once __DIR__ . '/class-ge-crm-attention.php';
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action( 'ge_crm_attention_tick', array( __CLASS__, 'attention_tick' ) );
        add_action( 'init', function () { if ( get_option( 'ge_crm_attention_enabled', false ) && ! wp_next_scheduled( 'ge_crm_attention_tick' ) ) { wp_schedule_single_event( time() + 300, 'ge_crm_attention_tick' ); } } );
        add_action('admin_post_graphex_crm',array(__CLASS__,'handle'));
        add_action('added_post_meta',array(__CLASS__,'quote_meta'),30,4);
        add_action('updated_post_meta',array(__CLASS__,'quote_meta'),30,4);
        add_action('ge_quote_request_created',array(__CLASS__,'request_created'),30,1);
        add_action('ge_crm_daily',array(__CLASS__,'scheduled'));
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate(); $r=self::table(); $e=self::table('events');
        dbDelta("CREATE TABLE $r (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            organization_id varchar(80) NOT NULL,
            kind varchar(20) NOT NULL,
            title varchar(200) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'new',
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
            opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
            quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            stage varchar(60) NOT NULL DEFAULT '',
            due_date varchar(10) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            cuit varchar(20) NOT NULL DEFAULT '',
            dedupe_key varchar(190) DEFAULT NULL,
            payload longtext NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_event (organization_id,dedupe_key),
            KEY domain_status (organization_id,kind,status),
            KEY customer (organization_id,customer_id),
            KEY quote_link (organization_id,quote_id),
            KEY due (organization_id,kind,due_date),
            KEY contact (organization_id,email)
        ) ENGINE=InnoDB $c;");
        dbDelta("CREATE TABLE $e (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            organization_id varchar(80) NOT NULL,
            record_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(60) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY timeline (organization_id,record_id,id),
            KEY customer (organization_id,customer_id,id)
        ) ENGINE=InnoDB $c;");
        foreach(array($r,$e) as $t) {
            $row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($t)),ARRAY_A);
            if(!$row || $row['Engine']!=='InnoDB')throw new RuntimeException('CRM requiere tablas InnoDB.');
        }
        add_option('ge_crm_config_'.self::org(),array('enabled'=>true,'followup_days'=>3,'inactivity_days'=>14,'stages'=>self::default_stages()),'',false);
        update_option('ge_crm_schema_version',self::VERSION,false);
        if(!wp_next_scheduled('ge_crm_daily'))wp_schedule_event(time()+3600,'daily','ge_crm_daily');
    }
    public static function default_stages() {
        return array('new'=>'Nuevo','contacted'=>'Contactado','qualified'=>'Necesidad relevada','requested'=>'Presupuesto solicitado','sent'=>'Presupuesto enviado','negotiation'=>'Negociación','won'=>'Ganado','lost'=>'Perdido');
    }
    public static function config() { return get_option('ge_crm_config_'.self::org(),array('enabled'=>false,'stages'=>self::default_stages(),'followup_days'=>3,'inactivity_days'=>14)); }
    public static function role($actor) { $o=GE_Organization::get(self::org()); return $o['members'][(string)$actor]??''; }
    public static function can($write=false,$actor=null) {
        $actor=$actor===null?get_current_user_id():(int)$actor; $o=GE_Organization::get(self::org());
        if(!$actor || !$o || empty($o['active']) || empty(self::config()['enabled']))return false;
        if(isset($o['settings']['modules']['crm']) && !$o['settings']['modules']['crm'])return false;
        $role=self::role($actor);
        return in_array($role,$write?array('owner','admin','comercial','staff'):array('owner','admin','comercial','staff','administracion','read-only'),true);
    }
    public static function require_access($write=false) { if(!self::can($write))throw new RuntimeException('Acceso CRM denegado.',403); }
    public static function admin() { if(!self::can(true)||!in_array(self::role(get_current_user_id()),array('owner','admin'),true))throw new RuntimeException('Administrador CRM requerido.',403); }
    public static function locked($fn) {
        global $wpdb; $lock='gecrm:'.substr(hash('sha256',DB_NAME.self::org()),0,40);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)throw new RuntimeException('Otro cambio está en curso.',409);
        $wpdb->query('START TRANSACTION');
        try { $out=$fn(); if($wpdb->query('COMMIT')===false)throw new RuntimeException('No se pudo confirmar el cambio.');return $out; }
        catch(Throwable $ex) { $wpdb->query('ROLLBACK');throw $ex; }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function decode($r) { if(!$r)return null; $p=json_decode($r['payload'],true)?:array();unset($r['payload']);foreach(array('id','owner_id','customer_id','lead_id','opportunity_id','quote_id','order_id','revision') as $k)$r[$k]=(int)$r[$k];$r['last_activity_at']=$r['updated_at'];return array_merge($p,$r); }
    public static function get($id,$kind='') {
        global $wpdb;
        $r=self::decode($wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d AND organization_id=%s',(int)$id,self::org()),ARRAY_A));
        if(!$r || ($kind && $kind!==$r['kind']))throw new RuntimeException('Registro no encontrado.',404);
        return $r;
    }
    public static function records($kind='',$customer=0,$q='',$limit=100,$offset=0) {
        global $wpdb;$where=$wpdb->prepare('organization_id=%s',self::org());
        if($kind)$where.=$wpdb->prepare(' AND kind=%s',$kind);
        if($customer)$where.=$wpdb->prepare(' AND customer_id=%d',$customer);
        if($q){$like='%'.$wpdb->esc_like($q).'%';$where.=$wpdb->prepare(' AND (title LIKE %s OR email LIKE %s OR phone LIKE %s OR cuit LIKE %s OR payload LIKE %s)',$like,$like,$like,$like,$like);}
        return array_map(array(__CLASS__,'decode'),$wpdb->get_results('SELECT * FROM '.self::table()." WHERE $where ORDER BY updated_at DESC,id DESC LIMIT ".max(1,min(500,(int)$limit)).' OFFSET '.max(0,(int)$offset),ARRAY_A));
    }
    public static function scope($type,$id) {
        if(!$id)return true;
        if($type==='customer') {
            $u=get_userdata($id);if(!$u || !in_array('customer',(array)$u->roles,true))return false;
            $scope=get_user_meta($id,'_ge_organization_id',true);
            return $scope===self::org() || (!$scope && self::org()==='graph-express');
        }
        if($type==='quote') {
            $p=get_post($id);if(!$p || $p->post_type!=='ge_commercial_quote')return false;
            $scope=get_post_meta($id,'_ge_organization_id',true);
            $customer=(int)get_post_meta($id,'_ge_commercial_customer_id',true);
            return ($scope===self::org() || (!$scope && self::org()==='graph-express')) && $customer && self::scope('customer',$customer);
        }
        if($type==='order') {
            $o=wc_get_order($id);if(!$o)return false;$scope=$o->get_meta('_ge_organization_id',true);
            return ($scope===self::org() || (!$scope && self::org()==='graph-express')) && (!$o->get_customer_id() || self::scope('customer',$o->get_customer_id()));
        }
        return false;
    }
    public static function customer_options($q='') {
        $users=get_users(array('role'=>'customer','number'=>100,'search'=>$q?'*'.$q.'*':'','search_columns'=>array('display_name','user_email')));
        $out=array();foreach($users as $u)if(self::scope('customer',$u->ID))$out[]=array('id'=>$u->ID,'name'=>$u->display_name,'email'=>$u->user_email);return $out;
    }
    public static function phone($v) { return preg_replace('/[^0-9]/','',(string)$v); }
    public static function match($data) {
        global $wpdb;$email=strtolower(trim($data['email']??''));$phone=self::phone($data['phone']??'');$cuit=self::phone($data['cuit']??'');
        $ids=array();if($email){$u=get_user_by('email',$email);if($u)$ids[]=$u->ID;}
        // Normalize legacy metadata in PHP; no fuzzy/last-digits auto-match.
        if($phone || $cuit) {
            $keys=array('billing_phone','billing_cuit','_ge_customer_cuit','billing_tax_id');
            $rows=$wpdb->get_results("SELECT user_id,meta_key,meta_value FROM $wpdb->usermeta WHERE meta_key IN ('billing_phone','billing_cuit','_ge_customer_cuit','billing_tax_id')",ARRAY_A);
            foreach($rows as $r)if(($r['meta_key']==='billing_phone' && $phone && self::phone($r['meta_value'])===$phone)||($r['meta_key']!=='billing_phone' && $cuit && self::phone($r['meta_value'])===$cuit))$ids[]=(int)$r['user_id'];
        }
        $out=array();foreach(array_unique($ids) as $id)if(self::scope('customer',$id)){$u=get_userdata($id);$out[]=array('id'=>$id,'name'=>$u->display_name,'email'=>$u->user_email);}
        return $out;
    }
    public static function validate($kind,$raw,$old=array()) {
        if(!in_array($kind,array('lead','opportunity','task','thread'),true))throw new RuntimeException('Tipo inválido.',422);
        $d=array();
        foreach(array('title','company','email','phone','cuit','source','status','stage','due_date','expected_close_date','next_action','priority','tags','intent','channel','external_id') as $k)$d[$k]=sanitize_text_field($raw[$k]??$old[$k]??'');
        foreach(array('notes','suggested_reply') as $k)$d[$k]=sanitize_textarea_field($raw[$k]??$old[$k]??'');
        foreach(array('owner_id','customer_id','lead_id','opportunity_id','quote_id','order_id','quote_request_id','communication_id','supplier_id','thread_id') as $k)$d[$k]=absint($raw[$k]??$old[$k]??0);
        if(!$d['title'] || strlen($d['title'])>200)throw new RuntimeException('Título requerido, hasta 200 caracteres.',422);
        if(strlen($d['notes'])>12000 || strlen($d['suggested_reply'])>4000)throw new RuntimeException('Texto demasiado largo.',422);
        if($d['email'] && !is_email($d['email']))throw new RuntimeException('Email inválido.',422);
        $d['email']=strtolower($d['email']);$d['phone']=self::phone($d['phone']);$d['cuit']=self::phone($d['cuit']);
        if(strlen($d['phone'])>40 || strlen($d['cuit'])>20)throw new RuntimeException('Contacto inválido.',422);
        foreach(array('due_date','expected_close_date') as $k)if($d[$k]){ $dt=DateTime::createFromFormat('!Y-m-d',$d[$k]);if(!$dt || $dt->format('Y-m-d')!==$d[$k])throw new RuntimeException('Fecha inválida.',422); }
        $o=GE_Organization::get(self::org());if(!$d['owner_id'])$d['owner_id']=get_current_user_id();
        if(!isset($o['members'][(string)$d['owner_id']]))throw new RuntimeException('Responsable fuera de la organización.',422);
        foreach(array('customer','quote','order') as $k)if(!self::scope($k,$d[$k.'_id']))throw new RuntimeException('Vínculo fuera de organización.',422);
        foreach(array('lead','opportunity') as $k)if($d[$k.'_id'])self::get($d[$k.'_id'],$k);
        if($d['thread_id'])self::get($d['thread_id'],'thread');
        if($d['supplier_id'])throw new RuntimeException('Vínculo a proveedor requiere adaptador scoped.',422);
        if($d['quote_request_id'] && !self::request_scope($d['quote_request_id']))throw new RuntimeException('Solicitud fuera de organización.',422);
        if($d['communication_id'] && !self::mail_scope($d['communication_id']))throw new RuntimeException('Comunicación fuera de organización.',422);
        if($d['lead_id']){$l=self::get($d['lead_id'],'lead');if($l['customer_id']){if($d['customer_id'] && $d['customer_id']!==$l['customer_id'])throw new RuntimeException('Cliente no coincide con lead.',422);$d['customer_id']=$l['customer_id'];}}
        if($d['opportunity_id']){$op=self::get($d['opportunity_id'],'opportunity');foreach(array('customer_id','lead_id','quote_id','order_id') as $k){if($d[$k] && $op[$k] && $d[$k]!==$op[$k])throw new RuntimeException('Relación incompatible.',422);if(!$d[$k])$d[$k]=$op[$k];}}
        if($d['quote_id']){$cid=(int)get_post_meta($d['quote_id'],'_ge_commercial_customer_id',true);if($d['customer_id'] && $d['customer_id']!==$cid)throw new RuntimeException('Presupuesto de otro cliente.',422);$d['customer_id']=$cid;}
        if($d['order_id']){$oid=(int)wc_get_order($d['order_id'])->get_customer_id();if($d['customer_id'] && $oid && $d['customer_id']!==$oid)throw new RuntimeException('Pedido de otro cliente.',422);if($oid)$d['customer_id']=$oid;}
        if($kind==='lead') { $d['status']=$d['status']?:'new';if(!in_array($d['status'],array('new','contacted','qualified','converted','discarded'),true))throw new RuntimeException('Estado inválido.',422); }
        if($kind==='task') { $d['status']=$d['status']?:'open';$d['priority']=$d['priority']?:'normal';if(!in_array($d['status'],array('open','done','cancelled'),true)||!in_array($d['priority'],array('low','normal','high'),true))throw new RuntimeException('Estado o prioridad inválidos.',422); }
        if($kind==='opportunity') {
            if(!$d['customer_id'] && !$d['lead_id'])throw new RuntimeException('Elegí cliente o lead.',422);
            $d['stage']=$d['stage']?:'new';if(!isset(self::config()['stages'][$d['stage']]))throw new RuntimeException('Etapa inválida.',422);
            $d['status']=in_array($d['stage'],array('won','lost'),true)?$d['stage']:'open';
            $v=(string)($raw['estimated_value']??$old['estimated_value']??'0');if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/',$v))throw new RuntimeException('Importe inválido.',422);$d['estimated_value']=$v;
            $prob=$raw['probability']??$old['probability']??'';if($prob!==''&&(!is_numeric($prob)||$prob<0||$prob>100))throw new RuntimeException('Probabilidad entre 0 y 100.',422);$d['probability']=$prob;
        }
        if($kind==='thread') {
            $d['status']=$d['status']?:'needs_review';if(!in_array($d['channel'],array('email','portal','whatsapp'),true) || !in_array($d['status'],array('needs_review','approved_pending_send','closed'),true))throw new RuntimeException('Canal o estado inválido.',422);
            $d['approval']='manual_required';$d['delivery']='not_sent';
            foreach ( array( 'attention_event','attention_hash','attention_state','attention_attempts','attention_queued_at','conversation_key','attention_lease_at','attention_quote_started','attention_classification','attention_processed_at','attention_ack' ) as $key ) { if ( isset( $old[$key] ) ) { $d[$key] = $old[$key]; } }
            if(!$d['suggested_reply']){$suggest=self::suggest($d['intent']);if($suggest)$d['suggested_reply']=$suggest['template'];}
            $d['unresolved_variables']=array();preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',$d['suggested_reply'],$matches);$d['unresolved_variables']=array_values(array_unique($matches[1]));
            if($d['status']==='approved_pending_send' && $d['unresolved_variables'])throw new RuntimeException('Completá las variables antes de aprobar el borrador.',422);
            if($d['status']==='approved_pending_send'){$d['approval']='staff_reviewed';$d['approved_by']=get_current_user_id();$d['approved_at']=gmdate('c');}
        }
        if ( 'task' === $kind && isset( $old['attention_dispatch'] ) ) { $d['attention_dispatch'] = $old['attention_dispatch']; }
        $d['source']=$d['source']?:'manual';return $d;
    }
    private static function persist($kind,$d,$old=null,$dedupe=null) {
        global $wpdb;$now=gmdate('Y-m-d H:i:s');$r=array('organization_id'=>self::org(),'kind'=>$kind,'payload'=>wp_json_encode($d),'updated_at'=>$now);
        foreach(array('title','status','owner_id','customer_id','lead_id','opportunity_id','quote_id','order_id','stage','due_date','email','phone','cuit') as $k)$r[$k]=$d[$k]??'';
        if($old){$r['revision']=$old['revision']+1;$ok=$wpdb->update(self::table(),$r,array('id'=>$old['id'],'organization_id'=>self::org(),'revision'=>$old['revision']));if($ok!==1)throw new RuntimeException('Registro modificado; recargá.',409);$id=$old['id'];}
        else {$r['created_at']=$now;$r['dedupe_key']=$dedupe;if(!$wpdb->insert(self::table(),$r))throw new RuntimeException('No se pudo crear el registro.');$id=(int)$wpdb->insert_id;}
        $after=self::get($id);self::event($id,$after['customer_id'],$old?'updated':'created',array('before'=>$old,'after'=>$after));return $after;
    }
    public static function event($record,$customer,$type,$payload) {
        global $wpdb;if(!$wpdb->insert(self::table('events'),array('organization_id'=>self::org(),'record_id'=>$record,'customer_id'=>$customer,'event_type'=>$type,'actor_id'=>get_current_user_id(),'payload'=>wp_json_encode($payload),'created_at'=>gmdate('Y-m-d H:i:s'))))throw new RuntimeException('No se pudo registrar auditoría.');
    }
    public static function save($kind,$raw,$id=0) {
        self::require_access(true);return self::locked(function()use($kind,$raw,$id){
            global $wpdb;$dedupe=null;
            if(!$id && $kind==='thread' && !empty($raw['external_id'])){$dedupe='message:'.hash('sha256',($raw['channel']??'').':'.$raw['external_id']);$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE organization_id=%s AND dedupe_key=%s',self::org(),$dedupe));if($existing)return self::get($existing,'thread');}
            $old=$id?self::get($id,$kind):null;if($old && (int)($raw['revision']??0)!==$old['revision'])throw new RuntimeException('Conflicto de revisión.',409);$saved = self::persist($kind,self::validate($kind,$raw,$old?:array()),$old,$dedupe);
            if ( 'thread' === $kind && isset( $saved['attention_event'] ) && 'closed' === $saved['status'] ) { self::operations_task( 'attention:' . $saved['id'], 'Atender mensaje: ' . $saved['title'], false, array( 'customer_id' => $saved['customer_id'], 'quote_id' => $saved['quote_id'], 'thread_id' => $saved['id'] ), $saved['owner_id'] ); }
            return $saved;
        });
    }
    public static function convert($id,$revision,$selected=0) {
        self::require_access(true);return self::locked(function()use($id,$revision,$selected){
            $l=self::get($id,'lead');if($l['customer_id'])return $l;if((int)$revision!==$l['revision'])throw new RuntimeException('Conflicto de revisión.',409);
            $matches=self::match($l);$ids=array_column($matches,'id');
            if($selected){if(!in_array((int)$selected,$ids,true))throw new RuntimeException('Revisá coincidencias antes de vincular.',422);$cid=(int)$selected;}
            else {
                if($matches)throw new RuntimeException('Hay coincidencias: elegí el cliente existente.',409);
                if(!$l['email'])throw new RuntimeException('Email requerido para crear ficha; podés crear oportunidad sin convertir.',422);
                if(email_exists($l['email']))throw new RuntimeException('El email ya existe en otra cuenta; revisar identidad.',409);
                $cid=wp_insert_user(array('user_login'=>'crm_'.wp_generate_uuid4(),'user_email'=>$l['email'],'display_name'=>$l['title'],'user_pass'=>wp_generate_password(40),'role'=>'customer'));
                if(is_wp_error($cid))throw new RuntimeException('No se pudo crear la ficha.',422);
                update_user_meta($cid,'_ge_organization_id',self::org());update_user_meta($cid,'billing_phone',$l['phone']);update_user_meta($cid,'billing_company',$l['company']);
            }
            $l['customer_id']=$cid;$l['status']='converted';$out=self::persist('lead',$l,self::get($id));
            global $wpdb;$related=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE organization_id=%s AND lead_id=%d',self::org(),$id),ARRAY_A);
            foreach($related as $raw){$r=self::decode($raw);if($r['customer_id'] && $r['customer_id']!==$cid)throw new RuntimeException('Relación comercial incompatible.',409);$d=$r;$d['customer_id']=$cid;self::persist($r['kind'],$d,$r);}
            self::event($id,$cid,'lead_converted',array('customer_id'=>$cid,'invitation_sent'=>false));return $out;
        });
    }
    public static function configure($raw) {
        self::admin();$cfg=self::config();$stages=$raw['stages']??$cfg['stages'];
        if(is_array($stages)){
            $stages=array_filter($stages,function($label){return trim((string)$label)!=='';});
            if(!empty($raw['new_stage_key'])||!empty($raw['new_stage_label'])){
                $key=sanitize_key($raw['new_stage_key']??'');
                if(isset($stages[$key]))throw new RuntimeException('El identificador ya existe.',409);
                $stages[$key]=$raw['new_stage_label']??'';
            }
        }
        if(!is_array($stages)||count($stages)<3||count($stages)>12)throw new RuntimeException('Entre 3 y 12 etapas.',422);
        $clean=array();foreach($stages as $k=>$label){if(!preg_match('/^[a-z][a-z0-9_-]{0,39}$/',(string)$k)||!trim($label)||strlen($label)>80)throw new RuntimeException('Etapa inválida.',422);$clean[$k]=sanitize_text_field($label);}
        foreach(array('new','won','lost') as $k)if(!isset($clean[$k]))throw new RuntimeException('Conservar etapas de entrada, ganado y perdido.',422);
        global $wpdb;foreach($wpdb->get_col($wpdb->prepare('SELECT DISTINCT stage FROM '.self::table().' WHERE organization_id=%s AND kind=%s',self::org(),'opportunity')) as $stage)if(!isset($clean[$stage]))throw new RuntimeException('No eliminar etapa usada.',409);
        $cfg['stages']=$clean;foreach(array('followup_days','inactivity_days') as $k){$v=absint($raw[$k]??$cfg[$k]);if($v<1||$v>365)throw new RuntimeException('Plazo entre 1 y 365 días.',422);$cfg[$k]=$v;}
        return self::locked(function()use($cfg){$before=self::config();update_option('ge_crm_config_'.self::org(),$cfg,false);self::event(0,0,'configuration',array('before'=>$before,'after'=>$cfg));return $cfg;});
    }
    public static function replies() {
        if(class_exists('GE_WhatsApp_Replies') && GE_WhatsApp_Replies::org()===self::org() && GE_WhatsApp_Replies::allowed())return GE_WhatsApp_Replies::data()['quick_replies']??array();
        if(self::org()!=='graph-express')return array();
        $bundle=json_decode(file_get_contents(__DIR__.'/quick-replies-reference.json'),true);return $bundle['quick_replies']??array();
    }
    public static function suggest($intent) { foreach(self::replies() as $r)if(!empty($r['active']) && $r['intent']===$intent)return $r;return null; }
    public static function mail_scope($id) {
        $p=get_post($id);if(!$p || $p->post_type!=='ge_email_log')return false;$org=get_post_meta($id,'_ge_organization_id',true);
        return $org===self::org() || (!$org && self::org()==='graph-express');
    }
    public static function request_scope($id) {
        $p=get_post($id);if(!$p || $p->post_type!=='ge_quote_request')return false;
        $scope=get_post_meta($id,'_ge_organization_id',true);$d=get_post_meta($id,'_ge_quote_request',true);
        return ($scope===self::org() || (!$scope && self::org()==='graph-express')) && (!is_array($d) || empty($d['customer_id']) || self::scope('customer',(int)$d['customer_id']));
    }
    public static function timeline($customer=0,$record=0) {
        global $wpdb;if($customer && !self::scope('customer',$customer))throw new RuntimeException('Cliente no encontrado.',404);
        $w=$wpdb->prepare('organization_id=%s',self::org());if($customer)$w.=$wpdb->prepare(' AND customer_id=%d',$customer);if($record){self::get($record);$w.=$wpdb->prepare(' AND record_id=%d',$record);}
        $events=$wpdb->get_results('SELECT id,event_type,actor_id,created_at,payload FROM '.self::table('events')." WHERE $w ORDER BY id DESC LIMIT 100",ARRAY_A);
        foreach($events as &$e){$e['details']=json_decode($e['payload'],true);unset($e['payload']);}unset($e);
        if($customer) {
            foreach(get_posts(array('post_type'=>'ge_commercial_quote','post_status'=>'private','numberposts'=>50,'meta_key'=>'_ge_commercial_customer_id','meta_value'=>$customer)) as $p)if(self::scope('quote',$p->ID)){
                $events[]=array('event_type'=>'quote','created_at'=>$p->post_date_gmt,'label'=>$p->post_title,'url'=>GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$p->ID)));
                foreach((array)get_post_meta($p->ID,'_ge_commercial_events',true) as $event)if(is_array($event))$events[]=array('event_type'=>'quote_'.($event['event']??'event'),'created_at'=>gmdate('Y-m-d H:i:s',strtotime($event['at']??$p->post_date_gmt)),'label'=>$p->post_title);
            }
            foreach(wc_get_orders(array('customer_id'=>$customer,'limit'=>50)) as $o)if(self::scope('order',$o->get_id())){
                $events[]=array('event_type'=>'order','created_at'=>$o->get_date_created()?$o->get_date_created()->date('Y-m-d H:i:s'):'','label'=>'Pedido '.$o->get_order_number(),'url'=>GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$o->get_id())));
                if($o->is_paid())$events[]=array('event_type'=>'payment','created_at'=>$o->get_date_paid()?$o->get_date_paid()->date('Y-m-d H:i:s'):'','label'=>'Pago registrado en pedido '.$o->get_order_number());
                if(class_exists('GE_WTP_Documents'))foreach(GE_WTP_Documents::get_documents($o->get_id()) as $doc)$events[]=array('event_type'=>'file','created_at'=>$doc['created_at']??'','label'=>$doc['name']??'Archivo de pedido','url'=>GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$o->get_id())));
            }
            $u=get_userdata($customer);if(class_exists('GE_WTP_Notifications'))foreach(GE_WTP_Notifications::get_logs_by_recipient($u->user_email,30) as $p)if(self::mail_scope($p->ID))$events[]=array('event_type'=>'email','created_at'=>$p->post_date_gmt,'label'=>$p->post_title,'url'=>GE_WTP_Staff_Portal::portal_url('communications'));
        }
        usort($events,function($a,$b){return strcmp($b['created_at'],$a['created_at']);});return array_slice($events,0,100);
    }
    public static function note($raw) {
        self::require_access(true);$customer=absint($raw['customer_id']??0);$record=absint($raw['record_id']??0);if($record){$r=self::get($record);$customer=$r['customer_id'];}if(!$customer && !$record)throw new RuntimeException('Elegí relación.',422);if(!self::scope('customer',$customer))throw new RuntimeException('Cliente inválido.',422);
        $type=sanitize_key($raw['type']??'note');if(!in_array($type,array('note','call','meeting','portal'),true))throw new RuntimeException('Actividad inválida.',422);$text=sanitize_textarea_field($raw['notes']??'');if(!$text||strlen($text)>4000)throw new RuntimeException('Nota requerida, hasta 4000 caracteres.',422);
        return self::locked(function()use($record,$customer,$type,$text){self::event($record,$customer,$type,array('notes'=>$text));if($record){global $wpdb;$wpdb->update(self::table(),array('updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$record,'organization_id'=>self::org()));}return array('saved'=>true);});
    }
    public static function automation_task($key,$raw) {
        global $wpdb;if($wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE organization_id=%s AND dedupe_key=%s',self::org(),$key)))return;
        self::persist('task',self::validate('task',$raw),null,$key);
    }

    /** Transport acknowledges its durable cursor only after this committed receipt. */
    public static function attention_ingest( $raw ) {
        self::require_access( true );
        $event = GE_CRM_Attention::normalize( $raw );
        return self::locked( function () use ( $event ) {
            global $wpdb;
            $key = 'message:' . hash( 'sha256', $event['channel'] . ':' . $event['account_ref'] . ':' . $event['external_id'] );
            $hash = hash( 'sha256', wp_json_encode( $event ) );
            $id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE organization_id=%s AND dedupe_key=%s', self::org(), $key ) );
            if ( $id ) {
                $existing = self::get( $id, 'thread' );
                if ( ( $existing['attention_hash'] ?? '' ) !== $hash ) { throw new RuntimeException( 'El mensaje ya recibido cambió; revisar la fuente sin reemplazarlo.', 409 ); }
                return array( 'record_id' => (int) $id, 'duplicate' => true, 'committed' => true );
            }
            $matches = 'email' === $event['channel'] ? self::match( array( 'email' => $event['from'] ) ) : array();
            $customer = count( $matches ) === 1 ? (int) $matches[0]['id'] : 0;
            $record = self::validate( 'thread', array( 'title' => $event['subject'] ?: 'Mensaje recibido', 'email' => 'email' === $event['channel'] ? $event['from'] : '', 'channel' => $event['channel'], 'external_id' => $event['external_id'], 'customer_id' => $customer, 'owner_id' => self::operations_owner(), 'source' => 'attention' ) );
            $record['attention_event'] = $event;
            $record['attention_hash'] = $hash;
            $record['attention_state'] = 'queued';
            $record['attention_attempts'] = 0;
            $record['attention_queued_at'] = gmdate( 'c' );
            $record['conversation_key'] = hash( 'sha256', $event['channel'] . ':' . $event['account_ref'] . ':' . $event['conversation_id'] );
            $saved = self::persist( 'thread', $record, null, $key );
            self::operations_task( 'attention:' . $saved['id'], 'Atender mensaje: ' . $record['title'], true, array( 'customer_id' => $customer, 'thread_id' => $saved['id'] ), $record['owner_id'], 'Recepción confirmada. Clasificación pendiente; original: ' . $event['source_ref'] );
            return array( 'record_id' => $saved['id'], 'duplicate' => false, 'committed' => true );
        } );
    }

    public static function attention_process( $id ) {
        self::require_access( true );
        $claimed = self::locked( function () use ( $id ) {
            $r = self::get( $id, 'thread' );
            if ( ! isset( $r['attention_event'] ) ) { throw new RuntimeException( 'No es un mensaje de transporte.', 422 ); }
            if ( 'closed' === $r['status'] || in_array( $r['attention_state'], array( 'review', 'ignored', 'prepared', 'failed' ), true ) ) { return $r; }
            if ( 'processing' === $r['attention_state'] && strtotime( $r['attention_lease_at'] ?? '' ) > time() - 300 ) { throw new RuntimeException( 'El mensaje está en proceso.', 409 ); }
            if ( ! empty( $r['attention_quote_started'] ) ) {
                $r['attention_state'] = 'review'; $r['notes'] = 'Resultado de preparación incierto: revisar antes de generar otro presupuesto.';
                return self::persist( 'thread', $r, self::get( $id ) );
            }
            if ( (int) $r['attention_attempts'] >= 5 ) { $r['attention_state'] = 'failed'; $r['notes'] = 'Fallos repetidos de procesamiento; revisar sin descartar el mensaje.'; return self::persist( 'thread', $r, self::get( $id ) ); }
            $r['attention_state'] = 'processing'; $r['attention_lease_at'] = gmdate( 'c' );
            $r['attention_attempts'] = (int) $r['attention_attempts'] + 1;
            return self::persist( 'thread', $r, self::get( $id ) );
        } );
        if ( 'processing' !== $claimed['attention_state'] ) { return $claimed; }
        $classification = GE_CRM_Attention::classify( $claimed['attention_event'] );
        $quote = null; $error = ''; $event = $claimed['attention_event'];
        try {
            if ( 'quote' === $classification['category'] && $event['quote_request'] && empty( $event['body_truncated'] ) && empty( $event['reply_ambiguous'] ) ) {
                $lines = GE_CRM_Attention::quote_lines( $event['quote_request'] );
                if ( is_wp_error( $lines ) ) { $error = $lines->get_error_message(); }
                elseif ( ! $claimed['customer_id'] ) { $error = 'Revisar y vincular la ficha del cliente antes de presupuestar.'; }
                else {
                    $channels = get_option( 'ge_crm_attention_channels', array() );
                    $args = $channels[$event['channel']]['quote_defaults'] ?? array();
                    if ( empty( $args['issuer_profile_id'] ) ) { $error = 'Seleccionar emisor y receptor; no inventar impuestos ni condiciones.'; }
                    else {
                        $claimed = self::locked( function () use ( $id ) { $r = self::get( $id ); $r['attention_quote_started'] = gmdate( 'c' ); return self::persist( 'thread', $r, self::get( $id ) ); } );
                        $args['source'] = 'attention'; $args['notes_internal'] = 'Mensaje CRM #' . $id . '; fuente ' . $event['source_ref'];
                        $quote = GE_WTP_Commercial_Quotes::create_draft( $claimed['customer_id'], $lines, $args, $claimed['owner_id'] );
                        if ( is_wp_error( $quote ) ) { $error = $quote->get_error_message(); $quote = null; }
                    }
                }
            }
        } catch ( Throwable $ex ) { $error = 'Falló la preparación; revisar la fuente y el registro de intento antes de reintentar.'; }
        return self::locked( function () use ( $id, $classification, $quote, $error, $claimed, $event ) {
            $r = self::get( $id, 'thread' );
            if ( $r['revision'] !== $claimed['revision'] ) { throw new RuntimeException( 'Cambió la revisión durante el procesamiento.', 409 ); }
            $r['attention_classification'] = $classification;
            $r['attention_processed_at'] = gmdate( 'c' );
            $r['attention_state'] = in_array( $classification['category'], array( 'automated', 'non_useful' ), true ) ? 'ignored' : ( $quote ? 'prepared' : 'review' );
            $r['status'] = 'ignored' === $r['attention_state'] ? 'closed' : 'needs_review';
            $r['notes'] = $classification['reason'] . ( $error ? "\n" . $error : '' );
            if ( $quote ) { $r['quote_id'] = $quote['id']; $r['notes'] .= "\nPresupuesto en borrador: revisar antes de publicar."; }
            // A prepared request is not a sent message; transport owns outbox retries and evidence.
            $r['attention_ack'] = $classification['ack_eligible'] ? array( 'state' => 'prepared', 'key' => 'ack:' . $r['conversation_key'], 'template' => 'Recibimos tu mensaje en GRAPHEX. Lo revisaremos para continuar con tu consulta.', 'to' => $event['from'], 'source_record_id' => (int) $id ) : array( 'state' => 'suppressed' );
            $saved = self::persist( 'thread', $r, self::get( $id ) );
            self::operations_task( 'attention:' . $id, 'Atender mensaje: ' . $r['title'], 'ignored' !== $r['attention_state'], array( 'customer_id' => $r['customer_id'], 'quote_id' => $r['quote_id'], 'thread_id' => $r['id'] ), $r['owner_id'], $r['notes'] );
            return $saved;
        } );
    }

    public static function attention_tick() {
        global $wpdb;
        if ( empty( get_option( 'ge_crm_attention_enabled', false ) ) ) { return; }
        $actor = self::operations_owner(); if ( ! $actor || ! self::can( true, $actor ) ) { return; }
        $previous = get_current_user_id(); wp_set_current_user( $actor );
        try {
            $cursor = absint( get_option( 'ge_crm_attention_cursor_' . self::org(), 0 ) );
            $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE organization_id=%s AND kind=%s AND id>%d ORDER BY id ASC LIMIT 50', self::org(), 'thread', $cursor ) );
            foreach ( $ids as $id ) {
                $r = self::get( $id );
                if ( isset( $r['attention_event'] ) ) {
                    try { self::attention_process( $id ); self::attention_ack_send( $id ); }
                    catch ( Throwable $ex ) { error_log( 'Graphex CRM attention processing needs review: record #' . (int) $id ); }
                }
                update_option( 'ge_crm_attention_cursor_' . self::org(), (int) $id, false );
            }
            if ( count( $ids ) < 50 ) { update_option( 'ge_crm_attention_cursor_' . self::org(), 0, false ); }
            self::attention_stale();
        } finally { wp_set_current_user( $previous ); }
        if ( ! wp_next_scheduled( 'ge_crm_attention_tick' ) ) { wp_schedule_single_event( time() + 300, 'ge_crm_attention_tick' ); }
    }

    /** Only the approved routine receipt template; commercial quote sending stays separate. */
    public static function attention_ack_send( $id ) {
        self::require_access( true );
        $policy = get_option( 'ge_crm_attention_ack_policy', array() );
        $qa = 'graph_job_flow_20261007' === DB_NAME && false !== strpos( ABSPATH, '/job-flow-qa-' );
        if ( empty( $policy['enabled'] ) || ( $policy['classifier_sha256'] ?? '' ) !== hash_file( 'sha256', __DIR__ . '/class-ge-crm-attention.php' ) || empty( $policy['evidence_ref'] ) ) { return array( 'state' => 'disabled' ); }
        $sender = GE_WTP_Notification_Center::mail_from( 'wordpress@graphex.ar' );
        $smtp = GE_WTP_Notification_Center::smtp_config();
        if ( 'servicio@graphex.ar' !== strtolower( $sender ) || false !== stripos( $smtp['host'], 'gmail' ) || ( ! $qa && empty( $smtp['host'] ) ) ) { return array( 'state' => 'sender_review_required' ); }
        $claim = self::locked( function () use ( $id ) {
            global $wpdb;
            $r = self::get( $id, 'thread' );
            if ( empty( $r['attention_event'] ) || 'closed' === $r['status'] || ! GE_CRM_Attention::classify( $r['attention_event'] )['ack_eligible'] || ! in_array( $r['attention_state'], array( 'review', 'prepared' ), true ) ) { return array( 'state' => 'suppressed' ); }
            $key = 'attention-ack:' . $r['conversation_key'];
            $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE organization_id=%s AND dedupe_key=%s', self::org(), $key ) );
            if ( $existing ) {
                $outbox = self::get( $existing, 'task' );
                if ( ( $r['attention_ack']['dispatch_id'] ?? 0 ) !== (int) $existing ) {
                    $r['attention_ack']['state'] = 'shared_dispatch'; $r['attention_ack']['dispatch_id'] = (int) $existing;
                    self::persist( 'thread', $r, self::get( $id, 'thread' ) );
                }
                return array( 'state' => $outbox['attention_dispatch']['state'] ?? 'unknown', 'dispatch_id' => (int) $existing, 'duplicate' => true );
            }
            $data = self::validate( 'task', array( 'title' => 'Acuse de recepción de correo', 'customer_id' => $r['customer_id'], 'thread_id' => $r['id'], 'owner_id' => $r['owner_id'], 'source' => 'attention', 'status' => 'open', 'priority' => 'normal', 'notes' => 'Intento registrado antes de enviar. Un resultado incierto requiere revisión.' ) );
            $data['attention_dispatch'] = array( 'state' => 'sending', 'to' => $r['attention_event']['from'], 'template' => 'received_ack', 'policy_version' => GE_CRM_Attention::VERSION, 'started_at' => gmdate( 'c' ) );
            $outbox = self::persist( 'task', $data, null, $key );
            $r['attention_ack']['state'] = 'sending'; $r['attention_ack']['dispatch_id'] = $outbox['id'];
            self::persist( 'thread', $r, self::get( $id ) );
            return array( 'state' => 'claimed', 'dispatch_id' => $outbox['id'], 'event' => $r['attention_event'] );
        } );
        if ( 'claimed' !== $claim['state'] ) { return $claim; }
        $actual = array();
        $capture = function ( $mailer ) use ( &$actual ) { $actual = array( 'from' => strtolower( (string) $mailer->From ), 'native' => 'smtp' === $mailer->Mailer && ! empty( $mailer->Host ) && false === stripos( (string) $mailer->Host, 'gmail' ) ); };
        add_action( 'phpmailer_init', $capture, PHP_INT_MAX );
        $state = 'unknown';
        try {
            $headers = array( 'Auto-Submitted: auto-replied', 'X-Auto-Response-Suppress: All' );
            $mid = $claim['event']['headers']['message-id'] ?? '';
            if ( preg_match( '/^<[^<>\s]{1,300}>$/D', $mid ) ) { $headers[] = 'In-Reply-To: ' . $mid; }
            $ok = GE_WTP_Notifications::send( $claim['event']['from'], 'Mensaje recibido · GRAPHEX', '<p>Recibimos tu mensaje en GRAPHEX. Lo revisaremos para continuar con tu consulta.</p>', 'attention_received_ack', $claim['dispatch_id'], $headers );
            $state = $ok ? ( $qa ? 'simulated' : ( 'servicio@graphex.ar' === ( $actual['from'] ?? '' ) && ! empty( $actual['native'] ) ? 'sent' : 'unknown' ) ) : 'failed';
        } catch ( Throwable $ex ) { $state = 'unknown'; }
        finally { remove_action( 'phpmailer_init', $capture, PHP_INT_MAX ); }
        return self::locked( function () use ( $id, $claim, $state, $actual ) {
            $logs = get_posts( array( 'post_type' => 'ge_email_log', 'post_status' => 'private', 'numberposts' => 2, 'meta_query' => array( array( 'key' => '_ge_email_context', 'value' => 'attention_received_ack' ), array( 'key' => '_ge_email_object_id', 'value' => $claim['dispatch_id'] ) ), 'orderby' => 'ID', 'order' => 'DESC' ) );
            $log_id = $logs ? (int) $logs[0]->ID : 0;
            $final = $state;
            if ( 'sent' === $final && ( ! $log_id || ! self::mail_scope( $log_id ) || get_post_meta( $log_id, '_ge_email_to', true ) !== $claim['event']['from'] || 'sent' !== get_post_meta( $log_id, '_ge_email_result', true ) ) ) { $final = 'unknown'; }
            $outbox = self::get( $claim['dispatch_id'], 'task' );
            $outbox['attention_dispatch'] = array_merge( $outbox['attention_dispatch'], array( 'state' => $final, 'finished_at' => gmdate( 'c' ), 'communication_id' => $log_id, 'sender' => $actual['from'] ?? '', 'sender_verified' => 'sent' === $final ) );
            $outbox['status'] = in_array( $final, array( 'sent', 'simulated' ), true ) ? 'done' : 'open';
            $outbox['priority'] = 'open' === $outbox['status'] ? 'high' : 'normal';
            $outbox['notes'] = 'Resultado del acuse: ' . $final . '. Registro nativo #' . $log_id . '. No reintentar a ciegas resultados inciertos.';
            self::persist( 'task', $outbox, self::get( $outbox['id'] ) );
            $r = self::get( $id, 'thread' ); $r['attention_ack']['state'] = $final; $r['attention_ack']['communication_id'] = $log_id;
            self::persist( 'thread', $r, self::get( $id ) );
            return array( 'state' => $final, 'dispatch_id' => $outbox['id'], 'communication_id' => $log_id );
        } );
    }

    public static function attention_stale() {
        self::require_access( true );
        return self::locked( function () {
            $count = 0;
            global $wpdb;
            $cursor = absint( get_option( 'ge_crm_attention_stale_cursor_' . self::org(), 0 ) );
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE organization_id=%s AND kind=%s AND id>%d ORDER BY id ASC LIMIT 100', self::org(), 'thread', $cursor ), ARRAY_A );
            foreach ( $rows as $row ) {
                $r = self::decode( $row );
                update_option( 'ge_crm_attention_stale_cursor_' . self::org(), $r['id'], false );
                if ( ! isset( $r['attention_event'] ) || 'closed' === $r['status'] || 'ignored' === $r['attention_state'] ) { continue; }
                if ( strtotime( $r['attention_queued_at'] ) > time() - HOUR_IN_SECONDS ) { continue; }
                self::operations_task( 'attention:' . $r['id'], 'Mensaje pendiente de atención: ' . $r['title'], true, array( 'customer_id' => $r['customer_id'], 'quote_id' => $r['quote_id'], 'thread_id' => $r['id'] ), $r['owner_id'], 'Antigüedad mayor a una hora. Estado: ' . $r['attention_state'] . '. Fuente: ' . $r['attention_event']['source_ref'] );
                $count++;
            }
            if ( count( $rows ) < 100 ) { update_option( 'ge_crm_attention_stale_cursor_' . self::org(), 0, false ); }
            return array( 'attention_required' => $count );
        } );
    }
    public static function quote_meta($mid,$id,$key,$value) {
        if($key==='_ge_quote_request' && is_array($value) && ($value['status']??'')==='new') { self::request_created($id);return; }
        if(!in_array($key,array('_ge_commercial_order_id','_ge_commercial_status'),true)||!self::scope('quote',$id)||!get_option('ge_crm_schema_version'))return;
        self::system(function()use($id,$key,$value){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE organization_id=%s AND quote_id=%d AND kind=%s',self::org(),$id,'opportunity'),ARRAY_A);foreach($rows as $row){$r=self::decode($row);if($key==='_ge_commercial_order_id' && (int)$value && self::scope('order',$value)){$r['order_id']=(int)$value;$r['stage']='won';$r['status']='won';self::persist('opportunity',$r,self::decode($row));}elseif($key==='_ge_commercial_status' && $value==='sent' && $r['status']==='open'){$r['stage']='sent';if(isset(self::config()['stages']['sent']))self::persist('opportunity',$r,self::decode($row));}}});
    }
    public static function system($fn) {
        $org=GE_Organization::get(self::org());
        if(empty(self::config()['enabled'])||!$org||empty($org['active'])||(isset($org['settings']['modules']['crm'])&&!$org['settings']['modules']['crm']))return;
        try{self::locked($fn);}catch(Throwable $e){error_log('GE CRM internal automation failed: '.(int)$e->getCode());}
    }
    public static function request_created($id) {
        if(!self::request_scope($id))return;$d=get_post_meta($id,'_ge_quote_request',true);if(!is_array($d))return;
        self::system(function()use($id,$d){$o=GE_Organization::get(self::org());$owner=0;foreach($o['members'] as $uid=>$role)if(in_array($role,array('owner','admin','comercial'),true)){$owner=(int)$uid;break;}if(!$owner)return;
            global $wpdb;$key='request:'.$id;$existing=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE organization_id=%s AND dedupe_key=%s',self::org(),$key));
            $customer=!empty($d['customer_id'])?get_userdata($d['customer_id']):null;
            $lead=$existing?self::get($existing):self::persist('lead',self::validate('lead',array('title'=>$d['name']??($customer?$customer->display_name:'Nueva solicitud'),'email'=>$d['email']??($customer?$customer->user_email:''),'phone'=>$d['phone']??($customer?get_user_meta($customer->ID,'billing_phone',true):''),'customer_id'=>$customer?$customer->ID:0,'source'=>'quote_request','quote_request_id'=>$id,'owner_id'=>$owner)),null,$key);
            self::automation_task('request-task:'.$id,array('title'=>'Contactar nueva solicitud','lead_id'=>$lead['id'],'quote_request_id'=>$id,'owner_id'=>$owner,'due_date'=>wp_date('Y-m-d')));
        });
    }
    private static function operations_owner( $roles = array( 'comercial', 'admin', 'owner' ) ) {
        $org = GE_Organization::get( self::org() );
        foreach ( $roles as $role ) { foreach ( (array) ( $org['members'] ?? array() ) as $id => $actual ) { if ( $role === $actual && get_userdata( $id ) ) { return (int) $id; } } }
        return 0;
    }
    /** Only this automation's records are resolved; human tasks and cancelled tasks are preserved. */
    private static function operations_task( $key, $title, $needed, $links, $owner, $notes = '' ) {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE organization_id=%s AND dedupe_key=%s', self::org(), 'operations:' . $key ) );
        $old = $id ? self::get( $id, 'task' ) : null;
        if ( ! $needed && ! $old ) { return; }
        if ( $old && ( 'cancelled' === $old['status'] || ( $needed && 'open' === $old['status'] ) || ( ! $needed && 'done' === $old['status'] ) ) ) { return; }
        $raw = array_merge( $old ?: array(), $links, array( 'title' => $title, 'owner_id' => $old ? $old['owner_id'] : $owner, 'status' => $needed ? 'open' : 'done', 'source' => 'operations', 'priority' => 'high', 'notes' => $notes, 'due_date' => $old ? $old['due_date'] : wp_date( 'Y-m-d' ) ) );
        self::persist( 'task', self::validate( 'task', $raw, $old ?: array() ), $old, 'operations:' . $key );
    }
    public static function scheduled() {
        self::system(function(){
            $cfg=self::config();foreach(self::records('opportunity',0,'',500) as $r) {
                if($r['status']!=='open')continue;
                if($r['quote_id'] && self::scope('quote',$r['quote_id'])) {
                    $order=(int)get_post_meta($r['quote_id'],'_ge_commercial_order_id',true);
                    if($order && self::scope('order',$order)){$d=$r;$d['stage']='won';$d['status']='won';$d['order_id']=$order;self::persist('opportunity',$d,$r);continue;}
                    $status=get_post_meta($r['quote_id'],'_ge_commercial_status',true);
                    if(in_array($status,array('sent','viewed'),true) && strtotime($r['updated_at'].' UTC')<time()-$cfg['followup_days']*DAY_IN_SECONDS)self::automation_task('followup:'.$r['id'].':'.$r['quote_id'],array('title'=>'Seguimiento de presupuesto: '.$r['title'],'opportunity_id'=>$r['id'],'owner_id'=>$r['owner_id'],'due_date'=>wp_date('Y-m-d'),'priority'=>'high'));
                }
                if(strtotime($r['updated_at'].' UTC')<time()-$cfg['inactivity_days']*DAY_IN_SECONDS)self::automation_task('inactive:'.$r['id'].':'.substr($r['updated_at'],0,10),array('title'=>'Retomar contacto: '.$r['title'],'opportunity_id'=>$r['id'],'owner_id'=>$r['owner_id'],'due_date'=>wp_date('Y-m-d')));
            }
        });
    }
    public static function import_csv($text,$commit=false) {
        self::require_access(true);if(strlen($text)>524288)throw new RuntimeException('CSV máximo 512 KiB.',422);
        $f=fopen('php://temp','r+');fwrite($f,$text);rewind($f);$head=fgetcsv($f);$allowed=array('title','company','email','phone','cuit','source','tags','notes');
        if(!$head || !in_array('title',$head,true)||count(array_unique($head))!==count($head)||array_diff($head,$allowed))throw new RuntimeException('Cabecera CSV inválida.',422);
        $rows=array();while(($line=fgetcsv($f))!==false){if(count($rows)>=200||count($line)!==count($head))throw new RuntimeException('Hasta 200 filas y columnas consistentes.',422);$d=self::validate('lead',array_combine($head,$line));$rows[]=array('data'=>$d,'matches'=>self::match($d));}fclose($f);
        if(!$commit)return $rows;
        return self::locked(function()use($rows){$out=array();global $wpdb;foreach($rows as $row){$d=$row['data'];$key='csv:'.hash('sha256',wp_json_encode($d));$id=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE organization_id=%s AND dedupe_key=%s',self::org(),$key));$out[]=$id?self::get($id):self::persist('lead',$d,null,$key);}return $out;});
    }
    public static function export_csv($kind) {
        self::require_access();if(!in_array($kind,array('lead','opportunity'),true))throw new RuntimeException('Export inválido.',422);
        $cols=$kind==='lead'?array('id','title','company','email','phone','cuit','source','status','customer_id','owner_id','tags','notes'):array('id','title','customer_id','lead_id','estimated_value','stage','owner_id','quote_id','order_id','next_action','expected_close_date');
        $f=fopen('php://temp','r+');fputcsv($f,$cols);$offset=0;do{$rows=self::records($kind,0,'',500,$offset);foreach($rows as $r){$line=array();foreach($cols as $c){$v=(string)($r[$c]??'');if(preg_match('/^[\s]*[=+@-]/',$v))$v="'".$v;$line[]=$v;}fputcsv($f,$line);}$offset+=count($rows);}while(count($rows)===500);rewind($f);return stream_get_contents($f);
    }
    public static function command($raw) {
        switch($raw['operation']??'') {
            case 'save':return self::save(sanitize_key($raw['kind']??''),$raw,absint($raw['id']??0));
            case 'convert':return self::convert(absint($raw['id']??0),absint($raw['revision']??0),absint($raw['selected_customer_id']??0));
            case 'configure':return self::configure($raw);
            case 'note':return self::note($raw);
            case 'import':return self::import_csv((string)($raw['csv']??''),!empty($raw['commit']));
            case 'run_automations':self::admin();self::scheduled();return array('done'=>true);
            default:throw new RuntimeException('Operación inválida.',422);
        }
    }
    public static function routes() {
        foreach ( array( 'inbound' => 'attention_ingest', 'process' => 'attention_process' ) as $route => $method ) {
            register_rest_route( 'graphex-crm/v1', '/attention/' . $route, array( 'methods' => 'POST', 'permission_callback' => function () { return self::can( true ) ?: new WP_Error( 'crm_forbidden', 'Acceso denegado.', array( 'status' => 403 ) ); }, 'callback' => function ( $req ) use ( $method ) {
                try { $raw = $req->get_json_params(); return 'attention_ingest' === $method ? self::attention_ingest( $raw ) : self::attention_process( absint( $raw['record_id'] ?? 0 ) ); }
                catch ( Throwable $ex ) { return new WP_Error( 'crm_attention', $ex->getMessage(), array( 'status' => in_array( $ex->getCode(), array( 403,404,409,422 ), true ) ? $ex->getCode() : 500 ) ); }
            } ) );
        }

        register_rest_route('graphex-crm/v1','/records',array('methods'=>'GET','permission_callback'=>function(){return self::can()?:new WP_Error('crm_forbidden','Acceso denegado.',array('status'=>403));},'callback'=>function($req){return self::records(sanitize_key($req['kind']??''),absint($req['customer_id']??0),sanitize_text_field($req['q']??''));}));
        register_rest_route('graphex-crm/v1','/command',array('methods'=>'POST','permission_callback'=>function(){return self::can(true)?:new WP_Error('crm_forbidden','Acceso denegado.',array('status'=>403));},'callback'=>function($req){try{return self::command($req->get_json_params()?:$req->get_params());}catch(Throwable $e){return new WP_Error('crm_error',$e->getMessage(),array('status'=>in_array($e->getCode(),array(403,404,409,422),true)?$e->getCode():500));}}));
    }
    public static function handle() {
        try { self::require_access();check_admin_referer('graphex_crm');$raw=wp_unslash($_POST);
            if(($raw['operation']??'')==='export'){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="crm-'.sanitize_key($raw['kind']).'.csv"');echo self::export_csv(sanitize_key($raw['kind']));exit;}
            self::command($raw);wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('crm',array('view'=>sanitize_key($raw['return_view']??'pipeline'),'saved'=>1)));exit;
        } catch(Throwable $e) { wp_die(esc_html($e->getMessage()),'CRM',array('response'=>in_array($e->getCode(),array(403,404,409,422),true)?$e->getCode():500,'back_link'=>true)); }
    }
}
