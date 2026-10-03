<?php
if (!defined('ABSPATH')) exit;
/** Internal operations. All writers share one transactional service boundary. */
final class GE_WTP_Operations {
    const VERSION='1.0.0';
    private static $depth=0;
    public static function init() {
        add_action('admin_post_ge_operations',array(__CLASS__,'handle'));
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'));
    }
    public static function enabled() { return 'yes'===get_option('ge_operations_enabled'); }
    public static function module_enabled($module) { return self::enabled()&&'yes'===get_option('ge_operations_'.$module.'_enabled','no'); }
    public static function table($key) { global $wpdb; if(!preg_match('/^[a-z_]+$/D',$key)) self::error('table','Invalid table'); return $wpdb->prefix.'ge_op_'.$key; }
    public static function error($code,$message=null) { throw new RuntimeException($message??$code,403===''.$code?403:0); }
    public static function permission($scope) {
        if(class_exists('GE_Organization_Runtime')) { $module=strpos($scope,'finance')===0?'finance':($scope==='supplier'?'suppliers':'stock'); GE_Organization_Runtime::require_permission($module,!in_array($scope,array('finance','stock_read'),true)); }
        $caps=array('stock_read'=>'ge_view_inventory','stock'=>'ge_manage_inventory','finance'=>'ge_view_finance','finance_write'=>'ge_record_finance','supplier'=>'ge_manage_operations');
        if(!is_user_logged_in()||(!current_user_can('manage_options')&&!current_user_can($caps[$scope]??'manage_options'))) self::error('permission','No tenés permiso para esta operación.');
        return true;
    }
    public static function transaction($fn) {
        global $wpdb; $level=self::$depth++; $save='ge_op_'.$level;
        if(false===$wpdb->query($level?'SAVEPOINT '.$save:'START TRANSACTION')) { self::$depth--; self::error('transaction','No se pudo iniciar la operación.'); }
        try { $result=$fn(); if(is_wp_error($result)) self::error($result->get_error_code(),$result->get_error_message()); if(false===$wpdb->query($level?'RELEASE SAVEPOINT '.$save:'COMMIT')) self::error('commit','No se pudo confirmar la operación.'); self::$depth--; return $result; }
        catch(Throwable $e) { $wpdb->query($level?'ROLLBACK TO SAVEPOINT '.$save:'ROLLBACK'); self::$depth--; throw $e; }
    }
    public static function audit($type,$id,$payload) {
        if(class_exists('GE_Organization'))$payload=array_merge((array)$payload,array('organization_id'=>GE_Organization::PRIMARY));
        global $wpdb; if(false===$wpdb->insert(self::table('audit'),array('event_type'=>sanitize_key($type),'object_ref'=>(string)$id,'actor_id'=>get_current_user_id(),'occurred_at'=>current_time('mysql',true),'payload'=>wp_json_encode($payload)))) self::error('audit','No se pudo registrar la auditoría.');
    }
    public static function install() {
        self::permission('finance_write'); global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
        $sql=array('CREATE TABLE '.self::table('audit')." (id bigint unsigned NOT NULL AUTO_INCREMENT,event_type varchar(80) NOT NULL,object_ref varchar(190) NOT NULL,actor_id bigint unsigned NOT NULL,occurred_at datetime NOT NULL,payload longtext NOT NULL,PRIMARY KEY  (id),KEY object_ref (object_ref)) ENGINE=InnoDB $c;",'CREATE TABLE '.self::table('supplier_refs')." (id bigint unsigned NOT NULL AUTO_INCREMENT,supplier_key varchar(96) NOT NULL,PRIMARY KEY  (id),UNIQUE KEY supplier_key (supplier_key)) ENGINE=InnoDB $c;");
        $sql=array_merge($sql,GE_WTP_Operations_Stock::schema(),GE_WTP_Operations_Finance::schema(),GE_WTP_Operations_Equipment::schema());
        foreach($sql as $s) {
            $s=preg_replace('/(CREATE TABLE [^ (]+ \()/','$1'."\n",$s,1);
            $s=preg_replace('/,\s*(?=(?:[a-z_]+ (?:bigint|varchar|datetime|longtext|decimal|tinyint|date|char|text|int)|PRIMARY KEY|UNIQUE KEY|KEY ))/i',",\n",$s);
            $s=preg_replace('/\) (?:ENGINE=InnoDB )?(DEFAULT CHARACTER SET|CHARACTER SET)/i',"\n) ENGINE=InnoDB $1",$s);
            dbDelta($s); if($wpdb->last_error) self::error('migration','Error de migración: '.$wpdb->last_error);
        }
        foreach(array('audit','supplier_refs','stock_items','stock_moves','purchases','purchase_lines','receipts','payables','expenses','finance_payments','finance_entries','finance_idempotency','finance_income','equipment','counter_readings','recipes') as $key) {
            $table=self::table($key); $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
            if('InnoDB'!==$engine) self::error('engine','La tabla '.$key.' necesita InnoDB.');
        }
        foreach(array('administrator',GE_WTP_Staff_Portal::ROLE) as $role_name) { $role=get_role($role_name); if($role) foreach(array('ge_manage_inventory','ge_view_finance','ge_record_finance') as $cap) $role->add_cap($cap); }
        self::supplier_seed(); GE_WTP_Operations_Stock::seed_catalog(); GE_WTP_Operations_Equipment::seed();
        update_option('ge_operations_schema',self::VERSION,false);
    }
    public static function suppliers() {
        global $wpdb; $profiles=GE_WTP_Supplier_Workspace::suppliers(); $refs=$wpdb->get_results('SELECT * FROM '.self::table('supplier_refs'),ARRAY_A); $out=array();
        foreach($refs as $r) if(isset($profiles[$r['supplier_key']])) $out[(int)$r['id']]=array('id'=>(int)$r['id'],'key'=>$r['supplier_key'])+$profiles[$r['supplier_key']];
        return $out;
    }
    public static function supplier_exists($id) { return isset(self::suppliers()[(int)$id]); }
    public static function supplier_seed() {
        global $wpdb; $profiles=get_option(GE_WTP_Supplier_Dispatch::OPTION,array());
        foreach(array('custom-rafer'=>array('name'=>'Rafer','notes'=>'Proveedor habitual de insumos de anillado/binder. Contacto y razón social pendientes de verificar.'),'custom-atawalpa'=>array('name'=>'Atawalpa','notes'=>'Proveedor habitual de papel. Contacto y razón social pendientes de verificar.')) as $key=>$row) {
            $exists=false; foreach(GE_WTP_Supplier_Workspace::suppliers() as $p) if(strtolower($p['name'])===strtolower($row['name'])) $exists=true;
            if(!$exists) $profiles[$key]=$row+array('email'=>'','whatsapp'=>'','channel'=>'manual','auto_email'=>'no','types'=>array('supplies'),'production_eligible'=>false,'supplies_provider'=>true,'verified'=>false);
        }
        foreach(GE_WTP_Supplier_Workspace::suppliers() as $key=>$p) {
            if(!isset($profiles[$key]['types'])) { $profiles[$key]=($profiles[$key]??array())+array('types'=>array('production'),'production_eligible'=>true,'supplies_provider'=>false); }
        }
        update_option(GE_WTP_Supplier_Dispatch::OPTION,$profiles,false);
        foreach(GE_WTP_Supplier_Workspace::suppliers() as $key=>$p) $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table('supplier_refs').' (supplier_key) VALUES (%s)',$key));
    }
    public static function order_source($id) {
        $o=wc_get_order($id); if(!$o||!GE_WTP_Production::is_production_order($o)) self::error('order','Pedido productivo inexistente.');
        $source=(string)$o->get_meta('_ge_production_source',true);
        if(!$source) { $sources=array(); foreach($o->get_items() as $i) { $s=$i->get_meta('_ge_production_source',true); if($s) $sources[$s]=true; } $source=count($sources)===1?key($sources):'unknown'; }
        return array('source'=>$source?:'unknown','materials_authorized'=>'yes'===$o->get_meta('_ge_internal_materials_authorized',true));
    }
    public static function assets() {
        if(!self::enabled()||!is_page('gestion')||!GE_WTP_Staff_Portal::can_access()) return;
        wp_enqueue_style('ge-operations',GE_WTP_PLUGIN_URL.'assets/css/operations.css',array('ge-gestion-v3'),self::VERSION);
        wp_enqueue_script('ge-operations',GE_WTP_PLUGIN_URL.'assets/js/operations.js',array(),self::VERSION,true);
    }
    public static function actions() {
        return array(
          'graph.finance.supplier_work_cost.record'=>array('GE_WTP_Operations_Finance','supplier_work_cost_create'), 'graph.finance.report'=>array('GE_WTP_Operations_Finance','report'), 'graph.finance.summary'=>array('GE_WTP_Operations_Finance','summary'), 'graph.finance.expense.create'=>array('GE_WTP_Operations_Finance','expense_create'), 'graph.finance.expense.list'=>array('GE_WTP_Operations_Finance','expense_list'), 'graph.finance.payable.list'=>array('GE_WTP_Operations_Finance','payables'), 'graph.finance.payment.record'=>array('GE_WTP_Operations_Finance','payment_record'), 'graph.finance.cost.record'=>array('GE_WTP_Operations_Finance','order_cost_save'), 'graph.finance.costs.verify'=>array('GE_WTP_Operations_Finance','costs_complete'), 'graph.finance.reverse'=>array('GE_WTP_Operations_Finance','reverse'),
          'graph.finance.income.record'=>array('GE_WTP_Operations_Finance','income_record'),
          'graph.stock.item.find'=>array('GE_WTP_Operations_Stock','items'), 'graph.stock.item.save'=>array('GE_WTP_Operations_Stock','item_save'), 'graph.stock.movement.record'=>array('GE_WTP_Operations_Stock','movement'), 'graph.stock.low_stock.list'=>array('GE_WTP_Operations_Stock','low_stock'), 'graph.stock.purchase.create'=>array('GE_WTP_Operations_Stock','purchase_create'), 'graph.stock.purchase.receive'=>array('GE_WTP_Operations_Stock','purchase_receive'), 'graph.equipment.counter.record'=>array('GE_WTP_Operations_Equipment','counter_record'), 'graph.equipment.save'=>array('GE_WTP_Operations_Equipment','equipment_save'), 'graph.production.materials.authorize'=>array(__CLASS__,'materials_authorize'), 'graph.production.recipe.save'=>array('GE_WTP_Operations_Equipment','recipe_save'), 'graph.production.materials.consume'=>array('GE_WTP_Operations_Equipment','consume_for_order')
        );
    }
    public static function execute($action,$input) {
        if(class_exists('GE_Organization_Runtime')) { $module=GE_Organization_Runtime::module_for($action); $write=!preg_match('/\.(find|get|list|report|summary)$/',$action); GE_Organization_Runtime::require_permission($module,$write); }
        if(!self::enabled()) self::error('disabled','Operations no está activo.');
        if(in_array($action,array('graph.stock.reserve','graph.stock.consume','graph.stock.release'),true)) { $input['type']=array('graph.stock.reserve'=>'reservation','graph.stock.consume'=>'consume_reserved','graph.stock.release'=>'release')[$action]; return GE_WTP_Operations_Stock::movement($input); }
        if('graph.stock.item.get'===$action) { self::permission('stock_read'); return GE_WTP_Operations_Stock::item_get(absint($input['id']??0)); }
        if('graph.finance.order_margin.get'===$action) return GE_WTP_Operations_Finance::order_margin(absint($input['order_id']??0));
        if('graph.equipment.get'===$action||'graph.equipment.telemetry.get'===$action) return GE_WTP_Operations_Equipment::get(absint($input['id']??0));
        $map=self::actions(); if(!isset($map[$action])) self::error('action','Acción no disponible.'); return call_user_func($map[$action],$input);
    }
    public static function routes() {
        register_rest_route('ge/v1','/operations/(?P<action>[a-z_.]+)',array('methods'=>'POST','permission_callback'=>function(){return self::enabled()&&is_user_logged_in()&&(current_user_can('manage_options')||current_user_can('ge_manage_inventory')||current_user_can('ge_view_finance'));},'callback'=>function($r){ try { return rest_ensure_response(self::execute($r['action'],$r->get_json_params()?:array())); } catch(Throwable $e) { return new WP_Error('operations_error',$e->getMessage(),array('status'=>400)); } }));
    }
    public static function legacy_balances() {
        self::permission('finance'); $balances=array(); $page=1;
        do { $batch=wc_get_orders(array('type'=>'shop_order','limit'=>100,'page'=>$page++,'paginate'=>true));
            foreach($batch->orders as $o) foreach((array)$o->get_meta('_ge_supplier_payables',true) as $key=>$r) { if(!is_array($r)||!isset($r['amount'])||$r['amount']==='') continue; $currency=$r['currency']??'ARS'; $b=$key.'|'.$currency; if(!isset($balances[$b])) $balances[$b]=array('key'=>$key,'currency'=>$currency,'cost_minor'=>0,'paid_minor'=>0); $balances[$b]['cost_minor']+=self::minor($r['amount']); }
        } while($page<=$batch->max_num_pages);
        foreach(get_posts(array('post_type'=>GE_WTP_Supplier_Workspace::ENTRY,'post_status'=>'private','numberposts'=>-1,'fields'=>'ids')) as $id) {
            $r=get_post_meta($id,GE_WTP_Supplier_Workspace::META,true); if(!is_array($r)||($r['type']??'')!=='payment'||!isset($r['amount'])) continue; $key=get_post_meta($id,'_ge_supplier_key',true); $currency=$r['currency']??'ARS'; $b=$key.'|'.$currency; if(!isset($balances[$b])) $balances[$b]=array('key'=>$key,'currency'=>$currency,'cost_minor'=>0,'paid_minor'=>0); $balances[$b]['paid_minor']+=self::minor($r['amount']);
        }
        return array_values($balances);
    }
    public static function handle() {
        check_admin_referer('ge_operations'); $input=wp_unslash($_POST); $action=sanitize_text_field($input['op']??''); $section=sanitize_key($input['return_section']??'stock');
        try {
            if('supplier_types'===$action) self::supplier_types($input);
            else {
                if(isset($input['payload'])) { $input=json_decode($input['payload'],true); if(!is_array($input)) self::error('json','Datos inválidos.'); }
                if(isset($input['amount'])) { $input['amount_minor']=self::minor($input['amount']); }
                if(!empty($input['order_id']) && absint($input['order_id'])>=10001) { global $wpdb; $order=$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.GE_WTP_Gestion_V3::table().' WHERE work_number=%d',absint($input['order_id']))); if($order) $input['order_id']=(int)$order; }
                if(isset($input['recurrence'])&&$input['recurrence']==='') $input['recurrence']=null;
                if(isset($input['due_on'])) $input['due_date']=$input['due_on'];
                if(isset($input['attachment_ref'])&&$input['attachment_ref']!=='') $input['attachments']=array(sanitize_text_field($input['attachment_ref']));
                if(isset($input['complete'])) $input['complete']=$input['complete']==='yes';
                if('graph.stock.item.save'===$action) { $input['metadata']=array_intersect_key($input,array_flip(array('location','notes'))); $input['metadata']['reorder']=$input['reorder_qty']??null; if(!empty($input['preferred_supplier_id'])) $input['metadata']['preferred_supplier_id']=absint($input['preferred_supplier_id']); $input['metadata']['alternate_supplier_ids']=array_map('absint',(array)($input['alternate_supplier_ids']??array())); }
                if(isset($input['observed_at'])) { $dt=new DateTimeImmutable($input['observed_at'],wp_timezone()); $input['observed_at']=$dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
                if('graph.production.recipe.save'===$action&&!isset($input['lines'])) $input['lines']=array(array('material_id'=>absint($input['material_id']),'basis'=>$input['basis'],'quantity'=>$input['quantity'],'outputs_per_sheet'=>$input['outputs_per_sheet']??null,'length_m'=>$input['length_m']??null,'width_m'=>$input['width_m']??null,'equipment_id'=>absint($input['equipment_id']??0)));
                if('graph.stock.purchase.create'===$action&&!isset($input['lines'])) $input['lines']=array(array('item_id'=>$input['item_id'],'quantity'=>$input['quantity'],'unit_cost'=>$input['unit_cost']??null));
                self::execute($action,$input);
            }
            $notice='saved';
        } catch(Throwable $e) { $notice='error'; set_transient('ge_op_error_'.get_current_user_id(),$e->getMessage(),60); }
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url($section,array('op_notice'=>$notice))); exit;
    }
    public static function minor($v) { if(!preg_match('/^([0-9]{1,12})(?:[.,]([0-9]{1,2}))?$/D',(string)$v,$m)) self::error('amount','Ingresá un importe válido, sin separador de miles.'); return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0'); }
    public static function materials_authorize($a) {
        self::permission('stock');$id=absint($a['order_id']??0);$source=self::order_source($id);if($source['source']!=='supplier')self::error('source','Esta autorización corresponde a un trabajo tercerizado válido.');$reason=sanitize_textarea_field($a['reason']??'');if(!$reason)self::error('reason','Indicá qué materiales aporta Graphex y por qué.');$enabled=($a['authorized']??'')==='yes';return self::transaction(function() use($id,$reason,$enabled){$o=wc_get_order($id);$o->update_meta_data('_ge_internal_materials_authorized',$enabled?'yes':'no');$o->save();self::audit('internal_materials_authorized',$id,array('authorized'=>$enabled,'reason'=>$reason));return array('order_id'=>$id,'authorized'=>$enabled);});
    }
    public static function supplier_types($input) {
        self::permission('supplier'); $key=sanitize_key($input['supplier_key']??''); $p=GE_WTP_Supplier_Workspace::supplier($key); if(!$p) self::error('supplier','Proveedor inexistente.');
        $types=array_values(array_intersect((array)($input['types']??array()),array('production','supplies','services','logistics','other')));
        $all=get_option(GE_WTP_Supplier_Dispatch::OPTION,array()); $all[$key]=array_merge($all[$key]??array(),array('types'=>$types,'production_eligible'=>in_array('production',$types,true),'supplies_provider'=>in_array('supplies',$types,true)));
        update_option(GE_WTP_Supplier_Dispatch::OPTION,$all,false); self::audit('supplier_taxonomy',$key,array('types'=>$types)); self::supplier_seed();
    }
}

