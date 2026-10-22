<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/history-storage.php';
require_once __DIR__.'/history-scope.php';
require_once __DIR__.'/history-commercial.php';
require_once __DIR__.'/history-invoices.php';
require_once __DIR__.'/history-components.php';

/** Applies verified history to real order fields and the real credited balance. No report overlay. */
final class GE_Work_Historical_Reconciliation {
    const ACTION='ge_finance_work_history_reconcile';
    const AUDIT='_ge_native_work_reconciliation_audit';
    const EXECUTED='_ge_historical_execution_confirmed';
    public static function init() {
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'handle'));
        add_action('admin_post_nopriv_'.self::ACTION,array(__CLASS__,'deny'));
        add_action('admin_post_ge_finance_work_history_scope',array(__CLASS__,'handle_scope'));
        add_action('admin_post_nopriv_ge_finance_work_history_scope',array(__CLASS__,'deny'));
        add_action('admin_post_ge_finance_work_history_convert',array(__CLASS__,'handle_conversion'));
        add_action('admin_post_nopriv_ge_finance_work_history_convert',array(__CLASS__,'deny'));
    }
    public static function deny(){wp_die('Acceso denegado.','',array('response'=>403));}
    public static function allowed() {
        if(!class_exists('GE_Organization_Runtime') || !class_exists('GE_Organization') || !GE_Work_Finance::can_write(get_current_user_id()))return false;
        $binding=get_option(GE_Organization_Runtime::BINDING);
        if(!$binding || $binding!==GE_Organization_Runtime::binding() || !GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),true))return false;
        foreach(array('finance','orders','production','quotes') as $module)if(!GE_Organization_Runtime::allowed($module,true))return false;
        return true;
    }
    private static function fields() {
        return array('_ge_final_total_cents','_ge_amount_paid_cents','_ge_amount_due_cents','_ge_payment_state','_ge_commercial_credited_attempts',GE_Work_Finance::HISTORICAL_META,GE_WTP_Order_Lifecycle::STAGE_META,'_ge_production_status','_ge_production_operational_status',self::EXECUTED,'_ge_historical_delivery_confirmation','_ge_financial_parent_order_id','_ge_quote_only','_ge_is_quote','_ge_work_label','_ge_work_payment_promise','_ge_work_order','_ge_source_quote_order_id','_ge_source_quote_item_id');
    }
    private static function children($order) {
        $out=array();
         $candidates=wc_get_orders(array('limit'=>101,'meta_key'=>'_ge_source_quote_order_id','meta_value'=>$order->get_id()));self::db_ok();
        if(count($candidates)>100)throw new DomainException('Demasiados vínculos para conciliar automáticamente; no se omitirá ningún pedido.');
        foreach($candidates as $child) {
            if($child->get_meta('_ge_work_order',true)!=='yes')continue;
            $source_item=$order->get_item(absint($child->get_meta('_ge_source_quote_item_id',true)));self::db_ok();
            if(!$source_item || (int)$source_item->get_meta('_ge_work_order_id',true)!==$child->get_id() || $child->get_customer_id()!==$order->get_customer_id() || $child->get_currency()!==$order->get_currency())throw new DomainException('Una orden de producción vinculada requiere revisión antes de conciliar.');
            if(in_array($child->get_status(),array('cancelled','refunded','failed'),true))throw new DomainException('Una orden vinculada tiene un estado comercial incompatible.');
            $out[$child->get_id()]=$child;
        }
        ksort($out);return $out;
    }
    private static function stored_meta_value($value){return is_array($value) || is_object($value)?$value:(string)$value;}
    public static function snapshot($order,$with_children=true) {
        $meta=array();foreach(self::fields() as $key)$meta[$key]=array('exists'=>$order->meta_exists($key),'value'=>self::stored_meta_value($order->get_meta($key,true)));
        $items=array();foreach($order->get_items('line_item') as $id=>$item)$items[(string)$id]=array('quantity'=>(string)$item->get_quantity(),'total'=>(string)$item->get_total(),'status_exists'=>$item->meta_exists('_ge_item_status'),'status'=>$item->get_meta('_ge_item_status',true));
        ksort($items);
        $snapshot=array('order_id'=>$order->get_id(),'customer_id'=>$order->get_customer_id(),'currency'=>$order->get_currency(),'total'=>(string)$order->get_total(),'status'=>$order->get_status(),'quote_id'=>(string)$order->get_meta('_ge_commercial_quote_id',true),'quote_version'=>(string)$order->get_meta('_ge_commercial_quote_version',true),'meta'=>$meta,'items'=>$items);
        $date_paid=$order->get_date_paid();$snapshot['observed']=array('date_paid'=>$date_paid?$date_paid->getTimestamp():null,'transaction_id'=>$order->get_transaction_id(),'delivery_confirmed_at'=>$order->get_meta('_ge_delivery_confirmed_at',true),'delivered_at'=>$order->get_meta(GE_WTP_Order_Lifecycle::DELIVERED_META,true),'commercial_scope'=>class_exists('GE_Production_Payment_Guard')?GE_Production_Payment_Guard::scope($order,true):'','production_control_hash'=>hash('sha256',wp_json_encode($order->get_meta('_ge_production_cards_v2',true))));
        if($with_children){$snapshot['children']=array();foreach(self::children($order) as $id=>$child)$snapshot['children'][(string)$id]=self::snapshot(self::fresh_order($id),false);}
        return $snapshot;
    }
    public static function hash($snapshot){return hash('sha256',wp_json_encode($snapshot));}
    public static function source_fingerprint($field,$id) {
        if($field==='order_id') {
            $o=wc_get_order($id);if(!$o)throw new DomainException('El pedido de origen ya no está disponible.');$modified=$o->get_date_modified();
            $f=array($id,$o->get_customer_id(),$o->get_meta('_ge_organization_id',true),$o->get_status(),$o->get_total(),$modified?$modified->getTimestamp():0,$o->get_meta('_ge_payment_state',true),$o->get_meta('_ge_production_status',true));
        } else {
            $q=GE_WTP_Commercial_Quotes::get($id,get_current_user_id());if(is_wp_error($q))throw new DomainException('El presupuesto de origen requiere revisión.');
            $f=array($id,(int)$q['customer_id'],get_post_meta($id,'_ge_organization_id',true),$q['version'],$q['status'],hash('sha256',wp_json_encode($q['snapshot'])));
        }
        return hash('sha256',wp_json_encode($f));
    }
    public static function payment_promise($row){
        if(!in_array($row['payment'],array('promised','expected_deposit'),true) || !preg_match('/(?:esperada el|para el jueves|para el|para)\s+(\d{2})\/(\d{2})\/(\d{4})/u',$row['note'],$m))return null;
        if(!checkdate((int)$m[2],(int)$m[1],(int)$m[3]))throw new DomainException('Revisá la fecha prometida del pago.');$amount=null;$percent=null;
        if($row['payment']==='expected_deposit' && preg_match('/(\d{1,3})\s*%/u',$row['note'],$p)){ $percent=(int)$p[1];if($percent<=0 || $percent>100 || !is_int($row['reference_cents']))throw new DomainException('Revisá el importe de la seña prometida.');$amount=GE_WTP_Quote_Balance::deposit($row['reference_cents'],$percent*100)['deposit_cents'];}
        return array('due_on'=>$m[3].'-'.$m[2].'-'.$m[1],'amount_cents'=>$amount,'percent'=>$percent,'declarant'=>$row['declarant'],'recorded_at'=>gmdate('c'),'actor'=>get_current_user_id());
    }
    public static function render_promise($promise,$currency='ARS'){
        if(!is_array($promise) || empty($promise['due_on']))return;echo '<p><strong>Promesa de pago registrada:</strong> '.esc_html($promise['due_on']).' · '.esc_html($promise['amount_cents']===null?'Importe sin confirmar':GE_Work_Finance::money($promise['amount_cents'],$currency)).'. Esta promesa no acredita un cobro; el saldo actual se informa por separado.</p>';
    }
    public static function quote_source($quote_id,$actual_quote_id=0) {
        $s=get_option('ge_work_reconciliation_v1',array());self::db_ok();if(($s['organization_id']??'')!==GE_Organization::PRIMARY || empty($s['revisions']))return null;
        $latest=end($s['revisions']);$matches=array();foreach($latest['rows'] as $r)if((int)$r['quote_id']===(int)$quote_id && !$r['order_id'])$matches[]=$r;
        if(count($matches)!==1)return null;$r=$matches[0];if(($r['commercial']==='partial' && (!$actual_quote_id || (int)$actual_quote_id===(int)$quote_id)) || !in_array($r['production'],array('completed','in_progress'),true))return null;
        $key='quote_id:'.$quote_id;$expected=$latest['source_fingerprints'][$key]??'';if(!$expected || !hash_equals($expected,self::source_fingerprint('quote_id',(int)$quote_id)))throw new DomainException('El presupuesto cambió desde la confirmación histórica.');
        return array('revision'=>$latest['id'],'row'=>$r,'expected_fingerprints'=>array($key=>$expected));
    }
    public static function quote_scope_valid($order,$source){
        $p=$order->get_meta('_ge_historical_quote_conversion',true);if(!is_array($p) || empty($p['actor']) || ($p['source_revision']??'')!==$source['revision'] || ($p['source_key']??'')!==$source['row']['key'] || (int)($p['source_quote_id']??0)!==(int)$source['row']['quote_id'] || (int)($p['quote_id']??0)===(int)$source['row']['quote_id'])return false;
        $actual=absint($order->get_meta('_ge_commercial_quote_id',true));if($actual!==(int)$p['quote_id'] || ($p['after_fingerprint']??'')!==self::source_fingerprint('quote_id',$actual))return false;
        return (int)get_post_meta($p['source_quote_id'],'_ge_historical_actual_order_id',true)===$order->get_id();
    }
    private static function converted_fingerprint_matches($order,$source,$field,$expected,$current) {
        if($field!=='quote_id')return false;$proof=$order->get_meta('_ge_historical_quote_conversion',true);self::db_ok();
        return is_array($proof) && !empty($proof['actor']) && ($proof['source_revision']??'')===$source['revision'] && ($proof['source_key']??'')===$source['row']['key'] && (int)($proof['quote_id']??0)===(int)$source['row']['quote_id'] && ($proof['before_fingerprint']??'')===$expected && ($proof['after_fingerprint']??'')===$current;
    }
    public static function render_quote($quote) {
        GE_Work_History_Commercial::render('quote',(int)$quote['id']);
        if(!self::allowed() || !empty($quote['converted_order_id']))return;
        try{$source=self::quote_source((int)$quote['id']);}catch(Throwable $e){echo '<p>Confirmación histórica conservada; requiere revisar cambios del presupuesto.</p>';return;}
        if(!$source){self::render_partial_quote($quote);return;}
        echo '<form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_finance_work_history_convert"><input type="hidden" name="quote_id" value="'.absint($quote['id']).'">';wp_nonce_field('ge_finance_work_history_convert_'.$quote['id']);
        GE_Work_History_Components::render($quote,$source);
        echo '<p>Existe una confirmación histórica de trabajo realizado. Se creará su pedido vinculado usando este presupuesto, sin emitir facturas, registrar una fecha de cobro ni enviar avisos.</p><button class="ge-staff-button">Crear ficha del trabajo realizado</button></form>';
    }
    private static function render_partial_quote($quote){
        $linked=absint(get_post_meta($quote['id'],'_ge_historical_actual_order_id',true));if($linked){echo '<p>Trabajo parcial vinculado a <a href="'.esc_url(add_query_arg(array('section'=>'orders','order_id'=>$linked),home_url('/gestion/'))).'">su pedido real</a>. Se conserva este presupuesto completo como antecedente.</p>';return;}
        $s=get_option('ge_work_reconciliation_v1',array());if(empty($s['revisions']))return;$latest=end($s['revisions']);$r=null;foreach($latest['rows'] as $row)if((int)$row['quote_id']===(int)$quote['id'])$r=$row;if(!$r || $r['commercial']!=='partial' || $r['production']!=='in_progress' || !$r['reference_cents'])return;
        $options=array();foreach(get_posts(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids','meta_key'=>GE_WTP_Commercial_Quotes::CUSTOMER_META,'meta_value'=>$quote['customer_id'])) as $id){if((int)$id===(int)$quote['id'])continue;$q=GE_WTP_Commercial_Quotes::get($id,get_current_user_id());if(is_wp_error($q)||$q['converted_order_id']||($q['snapshot']['total_cents']??0)!==$r['reference_cents']||($q['snapshot']['currency']??'ARS')!==$r['currency'])continue;$options[$id]=$q['number'];}if(!$options)return;
        echo '<form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_finance_work_history_convert"><input type="hidden" name="quote_id" value="'.absint($quote['id']).'">';wp_nonce_field('ge_finance_work_history_convert_'.$quote['id']);echo '<label>Presupuesto del alcance realmente realizado<select name="actual_quote_id" required>';foreach($options as $id=>$label)echo '<option value="'.absint($id).'">'.esc_html($label.' · '.GE_Work_Finance::money($r['reference_cents'],$r['currency'])).'</option>';echo '</select></label><p>Se vinculará únicamente el trabajo menor; este presupuesto completo no se acreditará como venta.</p><button class="ge-staff-button">Vincular y crear el pedido parcial</button></form>';
    }
    private static function render_scope($order,$source){
        $linked=absint($order->get_meta('_ge_historical_scope_order_id',true));if($linked){echo '<p>Alcance realizado vinculado a <a href="'.esc_url(add_query_arg(array('section'=>'orders','order_id'=>$linked),home_url('/gestion/'))).'">su pedido real</a>. El presupuesto completo se conserva como antecedente.</p>';return;}
        if($source['row']['payment']!=='paid_scope')return;
        echo '<details><summary>Conciliar únicamente el alcance realizado</summary><form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_finance_work_history_scope"><input type="hidden" name="source_id" value="'.$order->get_id().'">';wp_nonce_field('ge_finance_work_history_scope_'.$order->get_id());
        echo '<p>Seleccioná solo los renglones realizados. Importe final confirmado: '.esc_html(GE_Work_Finance::money($source['row']['performed_cents'],$order->get_currency())).'. Se creará una venta vinculada; no se acreditará el presupuesto completo.</p>';
        foreach($order->get_items() as $id=>$item)echo '<label><input type="checkbox" name="selected[]" value="'.absint($id).'"> '.esc_html($item->get_name().' × '.$item->get_quantity()).'</label>';
        echo '<button class="ge-staff-button">Crear ficha del alcance realizado</button></form></details>';
    }
    public static function handle_scope(){
        if(!self::allowed())self::deny();$id=absint($_POST['source_id']??0);check_admin_referer('ge_finance_work_history_scope_'.$id);
        try{$sale=GE_Work_History_Scope::create($id,(array)($_POST['selected']??array()));}catch(Throwable $e){wp_die(esc_html($e->getMessage()),'',array('response'=>409));}
        wp_safe_redirect(add_query_arg(array('section'=>'orders','order_id'=>$sale->get_id()),home_url('/gestion/')));exit;
    }
    public static function handle_conversion() {
        if(!self::allowed())self::deny();$id=absint($_POST['quote_id']??0);check_admin_referer('ge_finance_work_history_convert_'.$id);
        $actual=absint($_POST['actual_quote_id']??0);$components=array_map('absint',(array)($_POST['component_ids']??array()));$order=GE_WTP_Commercial_Checkout::convert_historical($actual?:$id,$actual?$id:0,$components);
        if(is_wp_error($order))wp_die(esc_html($order->get_error_message()),'',array('response'=>409));
        wp_safe_redirect(add_query_arg(array('section'=>'orders','order_id'=>$order->get_id()),home_url('/gestion/')));exit;
    }
    public static function source($order) {
        $s=get_option('ge_work_reconciliation_v1',array());self::db_ok();
        if(($s['organization_id']??'')!==GE_Organization::PRIMARY || empty($s['revisions']))return null;
        $latest=end($s['revisions']);$found=array();$quote=absint($order->get_meta('_ge_commercial_quote_id',true));$conversion=$order->get_meta('_ge_historical_quote_conversion',true);$origin_quote=is_array($conversion)?absint($conversion['source_quote_id']??0):0;$invoice_proof=$order->get_meta(GE_Work_History_Invoices::META,true);$invoice_key=is_array($invoice_proof)?($invoice_proof['source_key']??''):'';$scope=$order->get_meta(GE_Work_History_Scope::META,true);$origin=is_array($scope)?absint($scope['source_order_id']??0):0;
        foreach($latest['rows'] as $r)if((int)$r['order_id']===$order->get_id() || ($origin && (int)$r['order_id']===$origin) || ($quote && (int)$r['quote_id']===$quote) || ($origin_quote && (int)$r['quote_id']===$origin_quote) || ($invoice_key && !$r['order_id'] && !$r['quote_id'] && $r['key']===$invoice_key))$found[]=$r;
        if(count($found)!==1)return null;
        $fingerprints=array();foreach(array('order_id','quote_id') as $field)if($found[0][$field]){$key=$field.':'.$found[0][$field];$fingerprints[$key]=$latest['source_fingerprints'][$key]??'';}
        return array('revision'=>$latest['id'],'row'=>$found[0],'expected_fingerprints'=>$fingerprints);
    }
    public static function plan($order,$source) {
        $r=$source['row'];$valid=GE_Work_Finance::order($order->get_id());if(is_wp_error($valid))throw new DomainException($valid->get_error_message());
        if($order->get_meta('_ge_payment_order',true)==='yes')throw new DomainException('Este registro es un intento de cobro; conciliá el pedido comercial.');
        if(in_array($order->get_status(),array('cancelled','refunded','failed'),true))throw new DomainException('Revisá primero el estado comercial del pedido.');
        foreach(array('order_id','quote_id') as $field)if($r[$field]){$key=$field.':'.$r[$field];$expected=$source['expected_fingerprints'][$key]??'';$current=self::source_fingerprint($field,(int)$r[$field]);if(!$expected || (!hash_equals($expected,$current) && !self::converted_fingerprint_matches($order,$source,$field,$expected,$current)))throw new DomainException('El registro cambió desde la confirmación de Leo. Revisá sus movimientos antes de conciliar.');}
        $quote_id=absint($order->get_meta('_ge_commercial_quote_id',true));
        if($quote_id){$q=GE_WTP_Commercial_Quotes::get($quote_id,get_current_user_id());if(is_wp_error($q) || (int)$q['customer_id']!==$order->get_customer_id() || (int)$q['converted_order_id']!==$order->get_id())throw new DomainException('Revisá el vínculo comercial entre presupuesto y pedido.');}
        if(!$r['order_id'] && !$r['quote_id'] && !GE_Work_History_Invoices::valid($order,$source))throw new DomainException('Revisá el comprobante que vincula este trabajo histórico.');
        $performed_scope=GE_Work_History_Scope::valid($order,$source) || self::quote_scope_valid($order,$source);
        if(!$performed_scope && ($r['commercial']==='partial' || $r['production']==='partial_completed' || $r['payment']==='paid_scope'))throw new DomainException('El alcance parcial necesita una venta vinculada por lo realmente realizado. No se acreditará el presupuesto completo.');
        if(!$performed_scope && !in_array($r['production'],array('completed','in_progress'),true))throw new DomainException('Esta ficha requiere conciliar su etapa comercial, no registrar una venta realizada.');
        if(($r['currency']??'ARS')!==$order->get_currency())throw new DomainException('La moneda no coincide con el pedido.');
        $total=GE_WTP_Quote_Balance::cents(wc_format_decimal($order->get_total(),2));
        if($total<=0 || (!$performed_scope && $r['reference_cents']!==null && (int)$r['reference_cents']!==$total))throw new DomainException('El precio del pedido no coincide con la confirmación. Revisá el vínculo o alcance antes de acreditar.');
        if(isset($r['performed_cents']) && (int)$r['performed_cents']!==$total)throw new DomainException('El importe realizado difiere del pedido completo; requiere conciliar el alcance real.');
        $attempts=$order->get_meta('_ge_commercial_credited_attempts',true);if($attempts && !is_array($attempts))throw new DomainException('El historial de acreditaciones requiere revisión.');$attempts=is_array($attempts)?$attempts:array();
        $manual=$order->get_meta(GE_Work_Finance::META,true);$historical=$order->get_meta(GE_Work_Finance::HISTORICAL_META,true);
        $balance=GE_Work_Finance::balance($order);self::db_ok();
        if(!is_wp_error($balance))$attempts=$balance['attempts'];
        elseif($attempts || $manual || $historical || $order->get_meta('_ge_commercial_quote_id',true))throw new DomainException($balance->get_error_message());
        $state=GE_WTP_Quote_Balance::reconcile($total,$attempts);$target=null;
        if($r['payment']==='fully_paid' || ($performed_scope && $r['payment']==='paid_scope'))$target=$total;
        elseif($r['payment']==='unpaid')$target=0;
        elseif($r['payment']==='partially_paid' && is_int($r['collected_cents']))$target=$r['collected_cents'];
        if($target!==null && ($target<$state['amount_paid_cents'] || $target>$total))throw new DomainException('El cobro confirmado contradice acreditaciones existentes o supera el total. No se descontará ni duplicará dinero.');
        if($target!==null && $target>$state['amount_paid_cents'] && GE_Work_Finance::pending_attempt($order))throw new DomainException('Hay un intento de cobro del portal pendiente. Revisalo antes de acreditar esta confirmación para no duplicar dinero.');
        $stage=$r['delivery']==='completed'?'entregado':($r['production']==='completed' || ($performed_scope && $r['production']==='partial_completed')?'listo':'produccion');
        return array('order_id'=>$order->get_id(),'source'=>$source,'total_cents'=>$total,'attempts'=>$attempts,'paid_before'=>$state['amount_paid_cents'],'paid_target'=>$target,'delta_cents'=>$target===null?0:$target-$state['amount_paid_cents'],'stage'=>$stage,'item_status'=>$stage==='produccion'?'production':'ready','before'=>self::snapshot($order));
    }
    private static function fresh_order($id){$order=GE_Work_Finance::strict_order($id);self::db_ok();$order->read_meta_data(true);self::db_ok();foreach($order->get_items('line_item') as $item){self::db_ok();$item->read_meta_data(true);self::db_ok();}self::db_ok();return $order;}
    private static function db_ok(){global $wpdb;if($wpdb->last_error)throw new RuntimeException('No se confirmó la escritura. Revisá la ficha antes de repetir.');}
    private static function set_stage($order,$plan,$evidence) {
        $cards=$order->get_meta('_ge_production_cards_v2',true);$evidence['production_generation']=is_array($cards)?($cards['generation']??null):null;
        foreach($order->get_items('line_item') as $item){if($item->get_meta('_ge_item_status',true)==='cancelled')continue;$item->update_meta_data('_ge_item_status',$plan['item_status']);$item->save_meta_data();self::db_ok();}
        $order->update_meta_data(GE_WTP_Order_Lifecycle::STAGE_META,$plan['stage']);$order->update_meta_data('_ge_production_status',$plan['item_status']);
        $order->update_meta_data('_ge_production_operational_status',$plan['stage']==='entregado'?'closed':'active');
        $order->update_meta_data(self::EXECUTED,$evidence);
        foreach(array('_ge_quote_only','_ge_is_quote') as $flag)if($order->get_meta($flag,true)==='yes')$order->update_meta_data($flag,'no');
        if($plan['stage']==='entregado')$order->update_meta_data('_ge_historical_delivery_confirmation',array('actual_date'=>null,'confirmed_at'=>$evidence['recorded_at'],'actor'=>$evidence['actor'],'declarant'=>$evidence['declarant']));
    }
    public static function apply($id,$preview) {
        if(!self::allowed())throw new RuntimeException('Sin permiso para conciliar este trabajo.');
        $order=GE_Work_Finance::order($id);if(is_wp_error($order))throw new DomainException($order->get_error_message());
        $quote=absint($order->get_meta('_ge_commercial_quote_id',true));$lock='ge_commercial_reconcile_'.($quote?:'order_'.$id);
        if(!GE_Work_Finance::lock($lock))throw new RuntimeException('Hay otra operación en curso para este pedido.');
        global $wpdb;$transaction=false;
        try {
            $row=$preview['source']['row'];GE_Work_History_Storage::begin($id,array_filter(array((int)$row['order_id'])),array_filter(array((int)$row['quote_id'],$quote,absint(($order->get_meta(GE_Work_History_Invoices::META,true)['invoice_id']??0)))));$transaction=true;
            GE_Work_Finance::clear_order_cache($id);$order=self::fresh_order($id);$source=self::source($order);
            if(!$source || $source!==$preview['source'])throw new DomainException('Cambió la confirmación de origen. Volvé a revisar.');
            $audit=$order->get_meta(self::AUDIT,true);$audit=is_array($audit)?$audit:array();$digest=hash('sha256',wp_json_encode($source));
            $now=self::snapshot($order);
            foreach($audit as $entry)if(($entry['operation']??'')==='apply' && ($entry['source_digest']??'')===$digest && ($entry['after_hash']??'')===self::hash($now)){GE_Work_History_Storage::commit();$transaction=false;return array('duplicate'=>true,'order_id'=>$id);}
            if(self::hash($now)!==self::hash($preview['before']))throw new DomainException('El pedido cambió desde la revisión. No se aplicó la conciliación.');
            $plan=self::plan($order,$source);$before=$plan['before'];$recorded=gmdate('c');$actor=get_current_user_id();$r=$source['row'];$entry_id=wp_generate_uuid4();
            if($plan['paid_target']!==null) {
                $entries=$order->get_meta(GE_Work_Finance::HISTORICAL_META,true);$entries=is_array($entries)?$entries:array();
                $entry=array('id'=>$entry_id,'amount_cents'=>$plan['delta_cents'],'balance_confirmed_cents'=>$plan['paid_target'],'received_date'=>null,'method'=>null,'confirmed_at'=>$recorded,'actor'=>$actor,'declarant'=>$r['declarant'],'source_revision'=>$source['revision'],'source_key'=>$r['key']);
                $entries[]=$entry;$attempts=$plan['attempts'];if($entry['amount_cents'])$attempts[]=array('key'=>'historical:'.$entry_id,'amount_cents'=>$entry['amount_cents']);
                $balance=GE_WTP_Quote_Balance::reconcile($plan['total_cents'],$attempts);
                $order->update_meta_data(GE_Work_Finance::HISTORICAL_META,$entries);$order->update_meta_data('_ge_commercial_credited_attempts',$attempts);
                $order->update_meta_data('_ge_final_total_cents',$plan['total_cents']);$order->update_meta_data('_ge_amount_paid_cents',$balance['amount_paid_cents']);$order->update_meta_data('_ge_amount_due_cents',$balance['amount_due_cents']);$order->update_meta_data('_ge_payment_state',$balance['payment_status']);
            }
            $evidence=array('source_revision'=>$source['revision'],'source_key'=>$r['key'],'full_scope'=>true,'recorded_at'=>$recorded,'actor'=>$actor,'declarant'=>$r['declarant']);
            $order->update_meta_data('_ge_work_label',$r['customer']);$promise=self::payment_promise($r);if($promise)$order->update_meta_data('_ge_work_payment_promise',$promise);
            self::set_stage($order,$plan,$evidence);
            $children=self::children($order);
            foreach($children as $child_id=>$unused){$child=self::fresh_order($child_id);self::set_stage($child,$plan,$evidence);$child->update_meta_data('_ge_financial_parent_order_id',$id);GE_Work_History_Storage::save_order_meta($child);self::db_ok();GE_Work_Finance::clear_order_cache($child_id);}
            $after=self::snapshot($order);$audit[]=array('id'=>$entry_id,'operation'=>'apply','source_digest'=>$digest,'source_revision'=>$source['revision'],'source_key'=>$r['key'],'actor'=>$actor,'at'=>$recorded,'before'=>$before,'after'=>$after,'after_hash'=>self::hash($after));
            $order->update_meta_data(self::AUDIT,$audit);GE_Work_History_Storage::save_order_meta($order);self::db_ok();
            GE_Work_History_Storage::commit();$transaction=false;
            GE_Work_Finance::clear_order_cache($id);$saved=wc_get_order($id);
            if(self::hash(self::snapshot($saved))!==self::hash($after))throw new RuntimeException('La lectura posterior requiere revisión. No repitas el registro.');
            return array('saved'=>true,'order_id'=>$id,'stage'=>$plan['stage'],'paid_cents'=>$plan['paid_target']);
        } catch(Throwable $e){if($transaction){GE_Work_History_Storage::abort();GE_Work_Finance::clear_item_cache(array_keys($preview['before']['items']??array()));GE_Work_Finance::clear_order_cache($id);foreach($preview['before']['children']??array() as $child_id=>$child_before){GE_Work_Finance::clear_item_cache(array_keys($child_before['items']));GE_Work_Finance::clear_order_cache((int)$child_id);}}throw $e;}
        finally{if($transaction)GE_Work_History_Storage::abort();GE_Work_Finance::unlock($lock);}
    }
    public static function render($order) {
        if(!self::allowed())return;GE_Work_History_Commercial::render('order',$order->get_id());$source=self::source($order);
        if($source && $source['row']['commercial']==='partial' && !GE_Work_History_Scope::valid($order,$source) && !self::quote_scope_valid($order,$source)){self::render_scope($order,$source);return;}
        $last=$order->get_meta(self::AUDIT,true);
        if(is_array($last) && $last) {
            $latest=end($last);
            $same_source=!$source || ($latest['source_digest']??'')===hash('sha256',wp_json_encode($source));
            if(($latest['operation']??'')==='apply' && $same_source && ($latest['after_hash']??'')!==self::hash(self::snapshot($order))){echo '<p>La confirmación histórica ya fue conciliada. La ficha tiene movimientos posteriores; se conserva el registro de la conciliación anterior.</p>';return;}
            if(($latest['operation']??'')==='apply' && $same_source && ($latest['after_hash']??'')===self::hash(self::snapshot($order))) {
                echo '<details><summary>Conciliación aplicada a la ficha</summary><p>Estado y saldo conciliados el '.esc_html($latest['at']).'. Se conserva el respaldo previo de este pedido y sus órdenes vinculadas.</p><form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.self::ACTION.'"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="operation" value="rollback"><input type="hidden" name="entry_id" value="'.esc_attr($latest['id']).'"><input type="hidden" name="expected_hash" value="'.esc_attr($latest['after_hash']).'">';wp_nonce_field(self::ACTION.'_'.$order->get_id());
                echo '<label><input type="checkbox" name="confirmed" value="1" required> Restaurar el estado anterior de esta conciliación, conservando el historial.</label><button class="ge-staff-button is-secondary">Restaurar estado previo</button></form></details>';return;
            }
        }
        if(!$source)return;
        try {$plan=self::plan($order,$source);}catch(Throwable $e){echo '<p class="ge-production-notice">Confirmación guardada, pendiente de conciliación: '.esc_html($e->getMessage()).'</p>';return;}
        $token=sanitize_text_field(wp_unslash($_GET['native_history_review']??''));if(!preg_match('/^[a-f0-9-]{36}$/D',$token))$token='';$preview=$token?get_transient('ge_native_history_'.get_current_user_id().'_'.$token):null;
        echo '<details class="ge-production-card"><summary>Conciliar confirmación histórica del trabajo</summary><p>'.esc_html($source['row']['note']).'</p>';
        if($preview && (int)$preview['order_id']===$order->get_id()) {
            echo '<p><strong>Se actualizarán los campos reales de esta ficha.</strong> Etapa: '.esc_html(GE_WTP_Order_Lifecycle::stages()[$preview['stage']]).'. Cobrado: '.esc_html($preview['paid_target']===null?'Sin nuevo importe confirmado':GE_Work_Finance::money($preview['paid_target'],$order->get_currency())).'. No se genera un recibo con fecha de hoy ni se envían avisos.</p>';
        }
        echo '<form class="ge-admin-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.self::ACTION.'"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="operation" value="'.($preview?'apply':'preview').'"><input type="hidden" name="review" value="'.esc_attr($token).'">';wp_nonce_field(self::ACTION.'_'.$order->get_id());
        echo '<button class="ge-staff-button">'.($preview?'Aplicar conciliación en la ficha':'Revisar conciliación de esta ficha').'</button></form></details>';
    }
    private static function restore_snapshot($order,$snapshot) {
        foreach(self::fields() as $key){$v=$snapshot['meta'][$key];if($v['exists'])$order->update_meta_data($key,$v['value']);else $order->delete_meta_data($key);}
        foreach($snapshot['items'] as $id=>$v){$item=$order->get_item((int)$id);if(!$item)throw new DomainException('Cambió el contenido del pedido; no se puede restaurar.');if($v['status_exists'])$item->update_meta_data('_ge_item_status',$v['status']);else $item->delete_meta_data('_ge_item_status');$item->save_meta_data();self::db_ok();}
        GE_Work_History_Storage::save_order_meta($order);self::db_ok();
    }
    public static function rollback($id,$entry_id,$expected_hash) {
        if(!self::allowed())throw new RuntimeException('Sin permiso para restaurar esta conciliación.');
        $order=GE_Work_Finance::order($id);if(is_wp_error($order))throw new DomainException($order->get_error_message());
        $quote=absint($order->get_meta('_ge_commercial_quote_id',true));$lock='ge_commercial_reconcile_'.($quote?:'order_'.$id);
        if(!GE_Work_Finance::lock($lock))throw new RuntimeException('Hay otra operación en curso.');
        global $wpdb;$transaction=false;
        try {
            GE_Work_History_Storage::begin($id,array(),array_filter(array($quote)));$transaction=true;
            GE_Work_Finance::clear_order_cache($id);$order=self::fresh_order($id);$audit=$order->get_meta(self::AUDIT,true);$audit=is_array($audit)?$audit:array();$target=null;
            foreach($audit as $entry)if(($entry['id']??'')===$entry_id && ($entry['operation']??'')==='apply')$target=$entry;
            if(!$target || !hash_equals($target['after_hash'],(string)$expected_hash) || !hash_equals($target['after_hash'],self::hash(self::snapshot($order))))throw new DomainException('La ficha cambió después de conciliar. No se sobrescribirá ese cambio.');
            self::restore_snapshot($order,$target['before']);
            foreach($target['before']['children']??array() as $child_id=>$snapshot){$child=self::fresh_order((int)$child_id);self::restore_snapshot($child,$snapshot);GE_Work_Finance::clear_order_cache((int)$child_id);}
            $audit[]=array('id'=>wp_generate_uuid4(),'operation'=>'rollback','reverses'=>$entry_id,'source_digest'=>$target['source_digest'],'actor'=>get_current_user_id(),'at'=>gmdate('c'),'after_hash'=>self::hash(self::snapshot($order)));
            $order->update_meta_data(self::AUDIT,$audit);GE_Work_History_Storage::save_order_meta($order);self::db_ok();
            GE_Work_History_Storage::commit();$transaction=false;
            GE_Work_Finance::clear_order_cache($id);if(self::hash(self::snapshot(wc_get_order($id)))!==self::hash($target['before']))throw new RuntimeException('La verificación de la restauración requiere revisión.');return array('restored'=>true,'order_id'=>$id);
        }catch(Throwable $e){if($transaction){GE_Work_History_Storage::abort();GE_Work_Finance::clear_item_cache(array_keys($target['before']['items']??array()));GE_Work_Finance::clear_order_cache($id);foreach($target['before']['children']??array() as $child_id=>$child_before){GE_Work_Finance::clear_item_cache(array_keys($child_before['items']));GE_Work_Finance::clear_order_cache((int)$child_id);}}throw $e;}
        finally{if($transaction)GE_Work_History_Storage::abort();GE_Work_Finance::unlock($lock);}
    }
    public static function handle() {
        if(!self::allowed())self::deny();$id=absint($_POST['order_id']??0);check_admin_referer(self::ACTION.'_'.$id);
        try {
            $op=sanitize_key($_POST['operation']??'');$args=array('order_id'=>$id);
            if($op==='preview') {
                $order=GE_Work_Finance::order($id);if(is_wp_error($order))throw new DomainException($order->get_error_message());$source=self::source($order);if(!$source)throw new DomainException('No hay una confirmación única vinculada a esta ficha.');
                $plan=self::plan($order,$source);$token=wp_generate_uuid4();$key='ge_native_history_'.get_current_user_id().'_'.$token;
                if(!set_transient($key,$plan,15*MINUTE_IN_SECONDS) || get_transient($key)!==$plan)throw new RuntimeException('No se confirmó el respaldo previo. No se modificó la ficha.');$args['native_history_review']=$token;
            } elseif($op==='apply') {
                $token=sanitize_text_field(wp_unslash($_POST['review']??''));if(!preg_match('/^[a-f0-9-]{36}$/D',$token))throw new DomainException('Revisión inválida.');
                $key='ge_native_history_'.get_current_user_id().'_'.$token;$preview=get_transient($key);if(!$preview || (int)$preview['order_id']!==$id)throw new DomainException('La revisión venció o corresponde a otro pedido.');self::apply($id,$preview);delete_transient($key);$args['native_history_saved']='1';
            } elseif($op==='rollback') {
                if(empty($_POST['confirmed']))throw new DomainException('Confirmá la restauración de esta conciliación.');
                self::rollback($id,sanitize_text_field(wp_unslash($_POST['entry_id']??'')),sanitize_text_field(wp_unslash($_POST['expected_hash']??'')));$args['native_history_restored']='1';
            } else throw new DomainException('Acción inválida.');
            wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('orders',$args).'#ge-work-finance');exit;
        }catch(Throwable $e){wp_die(esc_html($e->getMessage()),'Conciliación del trabajo',array('response'=>409,'back_link'=>true));}
    }
}
GE_Work_Historical_Reconciliation::init();
