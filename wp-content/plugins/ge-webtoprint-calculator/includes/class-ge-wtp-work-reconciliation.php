<?php
defined('ABSPATH') || exit;

/** Historical declarations are evidence, never payment transactions or production commands. */
final class GE_WTP_Work_Reconciliation {
    const OPTION = 'ge_work_reconciliation_v1';
    const ACTION = 'ge_organization_reconciliation_save';
    const DOWNLOAD = 'ge_organization_reconciliation_download';
    const LIMIT = 200;

    public static function init() {
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'handle'));
        add_action('admin_post_'.self::DOWNLOAD,array(__CLASS__,'download'));
        add_action('admin_post_nopriv_'.self::ACTION,array(__CLASS__,'deny'));
        add_action('admin_post_nopriv_'.self::DOWNLOAD,array(__CLASS__,'deny'));
        add_action('template_redirect',array(__CLASS__,'front'),2);
        add_action('wp_footer',array(__CLASS__,'shortcut'));
        add_filter('ge_work_panel_rows',array(__CLASS__,'decorate'));
    }
    public static function allowed($write=false) {
        if(!class_exists('GE_WTP_Business_Export') || !GE_WTP_Business_Export::allowed())return false;
        foreach(array('orders','quotes','finance') as $module)if(!GE_Organization_Runtime::allowed($module,$write))return false;
        return true;
    }
    public static function deny() { wp_die('Acceso denegado.','',array('response'=>403)); }
    public static function url() {return add_query_arg('ge_work_reconciliation','1',home_url('/gestion/'));}
    public static function state() {
        $s=get_option(self::OPTION,array('schema_version'=>1,'organization_id'=>GE_Organization::PRIMARY,'revisions'=>array()));
        if(!is_array($s) || ($s['organization_id']??'')!==GE_Organization::PRIMARY || !isset($s['revisions']) || !is_array($s['revisions']))throw new RuntimeException('El registro de conciliación requiere revisión.');
        return $s;
    }
    public static function current($state=null) {$s=$state===null?self::state():$state;return $s['revisions']?end($s['revisions']):array('id'=>'','rows'=>array());}
    public static function enums() {
        return array(
            'commercial'=>array('unconfirmed'=>'Sin confirmar','presented'=>'Solo presupuestado','discarded'=>'Descartado por ahora','consultation'=>'En consulta','confirmed'=>'Presupuesto confirmado','performed'=>'Trabajo realizado','partial'=>'Alcance parcial'),
            'production'=>array('unconfirmed'=>'Sin confirmar','not_performed'=>'No realizado','in_progress'=>'En producción','completed'=>'Terminado','partial_completed'=>'Alcance realizado terminado'),
            'delivery'=>array('unconfirmed'=>'Entrega sin confirmar','not_applicable'=>'No corresponde entrega','not_delivered'=>'Sin entregar','ready'=>'Listo para entregar','planned'=>'Instalación prevista','completed'=>'Entregado'),
            'payment'=>array('unconfirmed'=>'Cobro sin confirmar','unpaid'=>'Sin cobrar','fully_paid'=>'Cobrado completo','paid_scope'=>'Cobrado el alcance realizado','partially_paid'=>'Cobrado parcial','promised'=>'Promesa de pago','expected_deposit'=>'Seña esperada')
        );
    }
    private static function text($value,$limit) {
        if(!is_string($value) || mb_strlen($value)>$limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))throw new InvalidArgumentException('Texto inválido o demasiado largo.');
        return sanitize_text_field($value);
    }
    private static function money($value) {
        if($value===null)return null;
        if(!is_int($value) || $value<0 || $value>100000000000)throw new InvalidArgumentException('El importe debe estar expresado en centavos enteros.');
        return $value;
    }
    public static function validate($input) {
        if(!is_array($input) || ($input['schema_version']??null)!==1 || ($input['organization_id']??'')!==GE_Organization::PRIMARY || !preg_match('/^[a-f0-9]{64}$/D',$input['source_sha256']??'') || !isset($input['rows']) || !is_array($input['rows']) || count($input['rows'])<1 || count($input['rows'])>self::LIMIT)throw new InvalidArgumentException('Archivo de conciliación inválido.');
        $rows=array();$seen=array();$refs=array();$fingerprints=array();$enums=self::enums();
        $allowed=array('key','customer','reference','order_id','quote_id','commercial','production','delivery','payment','currency','reference_cents','performed_cents','collected_cents','note','declarant');
        foreach($input['rows'] as $r) {
            if(!is_array($r) || array_diff(array_keys($r),$allowed))throw new InvalidArgumentException('Campos de conciliación no admitidos.');
            $key=self::text($r['key']??'',100);
            if(!preg_match('/^[a-z0-9-]+$/D',$key) || isset($seen[$key]))throw new InvalidArgumentException('Identificador vacío o duplicado.');
            $seen[$key]=true;$row=array('key'=>$key);
            $row['currency']=$r['currency']??'ARS';
            if(!in_array($row['currency'],array('ARS','USD'),true))throw new InvalidArgumentException('Moneda de conciliación inválida.');
            foreach(array('customer'=>180,'reference'=>240,'note'=>1200,'declarant'=>100) as $field=>$limit)$row[$field]=self::text($r[$field]??'',$limit);
            if(!$row['customer'] || !$row['declarant'])throw new InvalidArgumentException('Falta cliente o persona que confirmó el estado.');
            foreach($enums as $field=>$values) {if(!isset($values[$r[$field]??'']))throw new InvalidArgumentException('Estado de conciliación inválido.');$row[$field]=$r[$field];}
            foreach(array('reference_cents','performed_cents','collected_cents') as $field)$row[$field]=self::money($r[$field]??null);
            foreach(array('order_id','quote_id') as $field) {
                $id=$r[$field]??0;if(!is_int($id) || $id<0)throw new InvalidArgumentException('Referencia de origen inválida.');
                if($id) {
                    if(isset($refs[$field.':'.$id]))throw new InvalidArgumentException('Un registro de origen está asignado a dos trabajos.');
                    $refs[$field.':'.$id]=true;$fingerprints[$field.':'.$id]=self::validate_ref($field,$id);
                }
                $row[$field]=$id;
            }
            if($row['order_id'] && $row['quote_id']) {
                $order=wc_get_order($row['order_id']);$quote=GE_WTP_Commercial_Quotes::get($row['quote_id'],get_current_user_id());
                if((int)$order->get_customer_id()!==(int)$quote['customer_id'])throw new InvalidArgumentException('El pedido y presupuesto pertenecen a clientes distintos.');
            }
            if(!$row['order_id'] && !$row['quote_id'] && !$row['reference'])throw new InvalidArgumentException('Falta referencia documental.');
            if($row['production']==='not_performed' && ($row['delivery']==='completed' || ($row['performed_cents']??0)>0))throw new InvalidArgumentException('Un trabajo no realizado no puede estar entregado ni tener venta realizada.');
            if($row['payment']==='unpaid' && ($row['collected_cents']??0)>0)throw new InvalidArgumentException('El importe cobrado contradice el estado sin cobrar.');
            $rows[]=$row;
        }
        usort($rows,static function($a,$b){return strcmp($a['key'],$b['key']);});
        ksort($fingerprints);
        return array('schema_version'=>1,'organization_id'=>GE_Organization::PRIMARY,'source_sha256'=>$input['source_sha256'],'rows'=>$rows,'source_fingerprints'=>$fingerprints);
    }
    private static function validate_ref($field,$id) {
        if($field==='order_id') {
            $o=wc_get_order($id);
            if(!$o || $o->get_meta('_ge_commercial_payment_order',true)==='yes')throw new InvalidArgumentException('Pedido inexistente o intento de pago.');
            $org=$o->get_meta('_ge_organization_id',true);$customer=$o->get_customer_id();
            $modified=$o->get_date_modified();$fingerprint=array($id,$customer,$org,$o->get_status(),$o->get_total(),$modified?$modified->getTimestamp():0,$o->get_meta('_ge_payment_state',true),$o->get_meta('_ge_production_status',true));
        } else {
            $q=GE_WTP_Commercial_Quotes::get($id,get_current_user_id());
            if(is_wp_error($q))throw new InvalidArgumentException('Presupuesto inexistente o no permitido.');
            $org=get_post_meta($id,'_ge_organization_id',true);$customer=(int)$q['customer_id'];
            $snap=$q['snapshot']['organization_snapshot']['organization_id']??$q['snapshot']['organization_snapshot']['id']??'';
            if($snap && $snap!==GE_Organization::PRIMARY)throw new InvalidArgumentException('Presupuesto de otra organización.');
            $fingerprint=array($id,$customer,$org,$q['version'],$q['status'],hash('sha256',wp_json_encode($q['snapshot'])));
        }
        $customer_org=$customer?get_user_meta($customer,'_ge_organization_id',true):'';
        if(($org && $org!==GE_Organization::PRIMARY) || ($customer_org && $customer_org!==GE_Organization::PRIMARY))throw new InvalidArgumentException('Referencia de otra organización.');
        global $wpdb;if($wpdb->last_error)throw new RuntimeException('No se confirmó la lectura del origen.');
        return hash('sha256',wp_json_encode($fingerprint));
    }
    public static function append($document,$expected,$rollback=false) {
        if(!self::allowed(true))throw new RuntimeException('No tenés permiso para publicar conciliaciones.');
        global $wpdb;$lock='ge:reconciliation:'.substr(hash('sha256',$wpdb->prefix.GE_Organization::PRIMARY),0,36);
        if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$lock)))throw new RuntimeException('Hay otra publicación en curso.');
        try {
            wp_cache_delete(self::OPTION,'options');$state=self::state();$current=self::current($state);
            if(count($state['revisions'])>=100)throw new RuntimeException('El historial alcanzó su límite; requiere archivo administrado.');
            if($rollback) {
                $target=null;foreach($state['revisions'] as $rev)if($rev['id']===$document)$target=$rev;
                if($document==='empty')$target=array('rows'=>array(),'source_sha256'=>str_repeat('0',64));
                if(!$target)throw new InvalidArgumentException('Versión de recuperación inexistente.');
                $validated=array('rows'=>$target['rows'],'source_sha256'=>$target['source_sha256'],'source_fingerprints'=>$target['source_fingerprints']??array());
            } else {
                $validated=self::validate($document);
                if(!isset($document['source_fingerprints']) || $document['source_fingerprints']!==$validated['source_fingerprints'])throw new RuntimeException('Cambió un registro de origen. Volvé a revisar la importación.');
            }
            $digest=hash('sha256',wp_json_encode(array($validated['source_sha256'],$validated['rows'])));
            if(!$rollback && ($current['digest']??'')===$digest)return $current;
            if(!hash_equals($current['id'],(string)$expected))throw new RuntimeException('La conciliación cambió. Volvé a revisar antes de publicar.');
            $revision=array('id'=>wp_generate_uuid4(),'digest'=>$digest,'source_sha256'=>$validated['source_sha256'],'actor_id'=>get_current_user_id(),'recorded_at'=>gmdate('c'),'operation'=>$rollback?'rollback':'publish','previous_id'=>$current['id'],'rows'=>$validated['rows'],'source_fingerprints'=>$validated['source_fingerprints']??array());
            $state['revisions'][]=$revision;
            if(!update_option(self::OPTION,$state,false))throw new RuntimeException('No se confirmó la publicación. Revisá el historial antes de repetir.');
            wp_cache_delete(self::OPTION,'options');$saved=self::current();
            if($wpdb->last_error || ($saved['id']??'')!==$revision['id'])throw new RuntimeException('Resultado incierto. Revisá el historial antes de repetir.');
            return $saved;
        } finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public static function handle() {
        if(!self::allowed(true))self::deny();
        check_admin_referer(self::ACTION);
        try {
            $op=sanitize_key($_POST['operation']??'');
            if($op==='preview') {
                $raw=wp_unslash($_POST['document']??'');if(!is_string($raw) || strlen($raw)>524288)throw new InvalidArgumentException('El archivo supera el límite.');
                $document=self::validate(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
                $token=wp_generate_uuid4();$preview=array('document'=>$document,'expected'=>self::current()['id']);
                if(!set_transient('ge_reconciliation_'.get_current_user_id().'_'.$token,$preview,15*MINUTE_IN_SECONDS))throw new RuntimeException('No se pudo guardar la revisión temporal.');
                wp_safe_redirect(add_query_arg('review',$token,self::url()));exit;
            }
            if($op==='publish') {
                $token=sanitize_text_field(wp_unslash($_POST['review']??''));
                if(!preg_match('/^[a-f0-9-]{36}$/D',$token))throw new InvalidArgumentException('Revisión inválida.');
                $preview=get_transient('ge_reconciliation_'.get_current_user_id().'_'.$token);
                if(!$preview || empty($_POST['confirmed']))throw new InvalidArgumentException('Revisá y confirmá la publicación.');
                self::append($preview['document'],$preview['expected']);
                delete_transient('ge_reconciliation_'.get_current_user_id().'_'.$token);
            } elseif($op==='rollback') {
                self::append(sanitize_text_field(wp_unslash($_POST['target']??'')),sanitize_text_field(wp_unslash($_POST['expected']??'')),true);
            } else throw new InvalidArgumentException('Acción inválida.');
            wp_safe_redirect(add_query_arg('saved','1',self::url()));exit;
        } catch(Throwable $e) {wp_die(esc_html($e->getMessage()),'No se publicó la conciliación',array('response'=>409,'back_link'=>true));}
    }
    public static function download() {
        if(!self::allowed())self::deny();check_admin_referer(self::DOWNLOAD);
        nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="graphex-conciliacion-historial.json"');echo wp_json_encode(self::state(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;
    }
    public static function front() {
        if(!is_page('gestion') || !isset($_GET['ge_work_reconciliation']))return;
        if(!self::allowed())self::deny();nocache_headers();header('Cache-Control: private, no-store');get_header();self::page();get_footer();exit;
    }
    public static function shortcut() {
        if(is_page('gestion') && self::allowed())echo '<p class="ge-admin-panel"><a class="ge-staff-button" href="'.esc_url(self::url()).'">Ver conciliación de trabajos</a></p>';
    }
    private static function form_start($operation) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="operation" value="'.esc_attr($operation).'">';wp_nonce_field(self::ACTION);
    }
    public static function page() {
        $state=self::state();$current=self::current($state);$preview=null;$token=sanitize_text_field(wp_unslash($_GET['review']??''));
        if($token && self::allowed(true) && preg_match('/^[a-f0-9-]{36}$/D',$token))$preview=get_transient('ge_reconciliation_'.get_current_user_id().'_'.$token);
        echo '<main class="wrap ge-admin-panel"><h1>Conciliación de trabajos</h1><p>Estados históricos confirmados. Los cobros declarados no equivalen a conciliación bancaria. Se conservan los presupuestos, facturas y transacciones originales.</p>';
        if(isset($_GET['saved']))echo '<p role="status"><strong>Conciliación publicada y verificada.</strong></p>';
        if($preview) {
            echo '<h2>Revisar antes de publicar</h2><p>'.esc_html(count($preview['document']['rows'])).' grupos. Se registra la fecha de esta confirmación; no se inventa la fecha del trabajo ni del pago.</p>';
            self::table($preview['document']['rows']);self::form_start('publish');
            echo '<input type="hidden" name="review" value="'.esc_attr($token).'"><p><label><input type="checkbox" name="confirmed" value="1" required> Confirmo estos estados históricos, sin generar avisos, facturas, cobros nuevos ni despachos.</label></p><button class="ge-staff-button">Publicar conciliación</button></form>';
        } else {
            echo '<p><strong>'.esc_html(count($current['rows'])).' grupos registrados</strong>'.(!empty($current['recorded_at'])?' · Registro: '.esc_html($current['recorded_at']):'').'</p>';
            self::table($current['rows']);
            if(self::allowed(true)) {echo '<details><summary>Importar confirmaciones</summary>';self::form_start('preview');echo '<p><label>Archivo JSON de declaraciones<textarea name="document" rows="8" style="width:100%" required maxlength="524288"></textarea></label></p><button class="ge-staff-button">Revisar importación</button></form></details>';}
        }
        echo '<details><summary>Historial y respaldo</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::DOWNLOAD).'">';wp_nonce_field(self::DOWNLOAD);echo '<button>Descargar historial y respaldo</button></form>';
        foreach(array_reverse($state['revisions']) as $rev)echo '<p>'.esc_html($rev['recorded_at'].' · '.$rev['operation'].' · usuario #'.$rev['actor_id'].' · '.count($rev['rows']).' grupos · '.$rev['id']).'</p>';
        if(self::allowed(true) && $current['id']) {
            self::form_start('rollback');echo '<input type="hidden" name="expected" value="'.esc_attr($current['id']).'"><label>Restaurar versión<select name="target"><option value="empty">Sin declaraciones (conservar historial)</option>';
            foreach(array_reverse($state['revisions']) as $rev)if($rev['id']!==$current['id'])echo '<option value="'.esc_attr($rev['id']).'">'.esc_html($rev['recorded_at'].' · '.$rev['id']).'</option>';
            echo '</select></label><button>Restaurar declaración anterior</button></form>';
        }
        echo '</details></main>';
    }
    private static function amount($cents,$currency='ARS') {return $cents===null?'Sin importe confirmado':($currency==='ARS'?GE_WTP_Commercial_Quote_UI::money($cents):$currency.' '.number_format($cents/100,2,',','.'));}
    private static function table($rows) {
        $enums=self::enums();echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Trabajo</th><th>Situación</th><th>Producción</th><th>Entrega</th><th>Cobro declarado</th><th>Alcance e importes</th></tr></thead><tbody>';
        foreach($rows as $r) {
            echo '<tr id="'.esc_attr($r['key']).'"><td><strong>'.esc_html($r['customer']).'</strong><br>'.esc_html($r['reference']);
            if($r['order_id'])echo '<br><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$r['order_id']))).'">Pedido original</a>';
            if($r['quote_id'])echo '<br><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>$r['quote_id']))).'">Presupuesto original</a>';
            echo '</td>';foreach(array('commercial','production','delivery','payment') as $field)echo '<td>'.esc_html($enums[$field][$r[$field]]).'</td>';
            $currency=$r['currency']??'ARS';
            echo '<td>'.esc_html($r['note']).'<br>Referencia: '.esc_html(self::amount($r['reference_cents'],$currency)).'<br>Realizado: '.esc_html(self::amount($r['performed_cents'],$currency)).'<br>Cobrado: '.esc_html(self::amount($r['collected_cents'],$currency)).'<br><small>Confirmado por '.esc_html($r['declarant']).'</small></td></tr>';
        }
        echo '</tbody></table></div><p>Los importes no se suman: puede haber presupuestos parciales o varios trabajos incluidos en una misma factura.</p>';
    }
    public static function decorate($rows) {
        if(!self::allowed())return $rows;
        $current=self::current();$index=array();
        foreach($current['rows'] as $r)foreach(array('order_id','quote_id') as $field)if($r[$field])$index[$field.':'.$r[$field]]=$r;
        foreach($rows as &$row) {
            $r=$index['order_id:'.($row['order_id']??0)]??$index['quote_id:'.($row['quote_id']??0)]??null;
            if(!$r)continue;
            $fresh=true;
            foreach(array('order_id','quote_id') as $field)if($r[$field]) {
                try {$fingerprint=self::validate_ref($field,$r[$field]);} catch(Throwable $e){$fresh=false;break;}
                if(($current['source_fingerprints'][$field.':'.$r[$field]]??'')!==$fingerprint){$fresh=false;break;}
            }
            if(!$fresh) {
                $row['next']='El origen cambió después de la conciliación; revisar';continue;
            }
            $enums=self::enums();$row['status']='Conciliado: '.($r['commercial']==='partial'?'Alcance parcial · ':'').$enums['production'][$r['production']].' · '.$enums['delivery'][$r['delivery']];
            $row['owner']='Confirmado por '.$r['declarant'].' · '.$enums['payment'][$r['payment']];
            $row['next']='Ver conciliación y documentos originales';
            $row['url']=self::url().'#'.$r['key'];
            $row['closed']=$r['delivery']==='completed' || $r['commercial']==='discarded';
            if(in_array($r['production'],array('in_progress','completed','partial_completed'),true))$row['kind']='production';
        }
        unset($row);return $rows;
    }
}
