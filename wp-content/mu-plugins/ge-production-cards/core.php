<?php
defined('ABSPATH') || exit;

/** Persistent preparation, using native artwork, supplier and notification models. */
final class GE_WTP_Production_Cards {
 const META='_ge_production_cards_v2';
 const NOTICES='_ge_production_cards_notices_v2';
 private static $locks=array();
 public static function init(){
  add_action('ge_supplier_business_confirmed',array(__CLASS__,'supplier_confirmed'),10,2);
  add_action('ge_supplier_business_issue',array(__CLASS__,'supplier_issue'),10,2);
  foreach(array('save'=>'handle_save','finish'=>'handle_finish','approval'=>'handle_approval','supplier_confirm'=>'handle_supplier_confirm','manual_dispatch'=>'handle_manual_dispatch','notice_retry'=>'handle_notice_retry','supplier_retry'=>'handle_supplier_retry','reopen'=>'handle_reopen') as $key=>$method)add_action('admin_post_ge_prod_cards_'.$key,array(__CLASS__,$method));
 }
 public static function enabled($order){
  if(get_option('ge_production_cards_v2_enabled','no')!=='yes')return false;
  if(!$order instanceof WC_Order || !class_exists('GE_WTP_Workflow') || !GE_WTP_Workflow::enabled($order) || GE_WTP_Production::is_closed($order) || in_array($order->get_status(),array('cancelled','refunded','failed'),true) || 'yes'===$order->get_meta('_ge_commercial_payment_order',true))return false;
  if(!$order->get_meta(self::META,true) && in_array($order->get_meta('_ge_production_status',true),array('production','ready','delivered'),true))return false;
  return true;
 }
 public static function access($order,$write=false){
  if(!$order instanceof WC_Order)return new WP_Error('order','Pedido no encontrado.');
  $org=(string)$order->get_meta('_ge_organization_id',true);
  if($org && $org!==GE_Organization::PRIMARY)return new WP_Error('organization','El pedido no pertenece a esta organización.');
  if(class_exists('GE_Organization_Runtime')&&!GE_Organization_Runtime::allowed('production',$write))return new WP_Error('forbidden','No tenés permiso para esta operación de producción.');
  if(!GE_WTP_Staff_Portal::can_access() || ($write && !current_user_can('ge_manage_operations') && !current_user_can('manage_woocommerce')))return new WP_Error('forbidden','No tenés permiso para modificar producción.');
  return true;
 }
 public static function locked($id,$callback){
  if(!empty(self::$locks[$id]))return $callback();
  global $wpdb;$key='ge_prod_cards_'.$wpdb->prefix.absint($id);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$key))!==1)return new WP_Error('busy','Otra operación está guardando este pedido. Volvé a intentar.');
  self::$locks[$id]=true;
  try{return $callback();}finally{unset(self::$locks[$id]);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
 }
 public static function data($order){
  $raw=$order->get_meta(self::META,true);$raw=is_array($raw)?$raw:array();
  return array_merge(array('schema'=>2,'revision'=>0,'cursor'=>'planning','state'=>'preparing','routes'=>array(),'channel'=>'email','dispatches'=>array(),'authorized'=>array()),$raw);
 }
 private static function persist($order,$data,$event){
  $data['schema']=2;$data['revision']=(int)$data['revision']+1;$data['updated_at']=gmdate('c');$data['updated_by']=get_current_user_id();
  $order->update_meta_data(self::META,$data);$order->save();
  GE_WTP_Supplier_Portal::audit($order,'production.cards.'.$event,'',array('revision'=>$data['revision'],'state'=>$data['state']));$order->save();
  return $data;
 }
 public static function items($order){return array_filter($order->get_items('line_item'),function($i){return $i->get_meta('_ge_item_status',true)!=='cancelled';});}
 public static function profiles(){
  $out=GE_WTP_Supplier_Portal::profiles();
  foreach($out as $key=>$profile){$registered=GE_WTP_Supplier_Workspace::supplier($key);if(in_array($key,array('internal','pending','multiple'),true)||(GE_WTP_Operations::enabled() && empty($registered['production_eligible'])))unset($out[$key]);}
  return $out;
 }
 public static function source_assigned($item,$doc_id){return in_array('document:'.$doc_id,(array)$item->get_meta('_ge_item_artwork_sources',true),true);}
 public static function authorized_for_supplier($order,$item){
  $d=self::data($order);$proof=$d['authorized'][$item->get_id()]??array();
  return in_array($d['state'],array('awaiting_supplier','awaiting_manual_dispatch','production'),true) && !empty($proof['signature']) && hash_equals((string)$proof['signature'],(string)$item->get_meta('_ge_item_artwork_release_hash',true)) && GE_WTP_Artwork_Library::item_ready_for_production($item,$order);
 }
 public static function may_preview($order,$item){return self::enabled($order) && !is_wp_error(self::access($order,true)) && self::data($order)['state']==='preparing' && GE_WTP_Artwork_Library::item_ready_for_production($item,$order);}
 public static function customer_label($order){
  if(!self::enabled($order))return null;
  $stage=GE_WTP_Order_Lifecycle::stage($order);
  if($stage==='entregado')return 'Entregado';if($stage==='listo')return 'Trabajo listo';
  if($stage==='produccion'||self::data($order)['state']==='production')return 'Trabajo en producción';
  return 'Archivos en preparación';
 }
 public static function valid_date($value){$date=DateTime::createFromFormat('!Y-m-d',$value);return $date && $date->format('Y-m-d')===$value;}
 public static function check($order){
  $access=self::access($order,true);if(is_wp_error($access))return $access;
  $commercial=GE_WTP_Job_Flow::commercial_check($order);if(is_wp_error($commercial))return $commercial;
  $date=(string)$order->get_meta('_ge_production_promised_date',true);
  if(!self::valid_date($date))return new WP_Error('planning','Elegí la fecha prometida de entrega en Planificación.');
  $items=self::items($order);if(!$items)return new WP_Error('files','No hay productos para producir.');
  foreach($items as $item){if(!GE_WTP_Artwork_Library::item_ready_for_production($item,$order))return new WP_Error('files','Revisá el archivo y su aprobación: '.$item->get_name());$integrity=GE_WTP_Artwork_Library::source_integrity($order,(array)$item->get_meta('_ge_item_artwork_sources',true));if(is_wp_error($integrity))return $integrity;}
  return true;
 }
 public static function save($id,$revision,$step,$input,$next=false){
  return self::locked($id,function()use($id,$revision,$step,$input,$next){
   $order=wc_get_order($id);$access=self::access($order,true);if(is_wp_error($access))return $access;
   if(!self::enabled($order))return new WP_Error('order','Este trabajo no admite cambios de preparación.');
   $d=self::data($order);if((int)$revision!==(int)$d['revision'])return new WP_Error('conflict','El pedido cambió en otra ventana. Tus datos se conservaron para revisarlos.');
   if($d['state']!=='preparing')return new WP_Error('state','El control ya terminó. Revisá el envío o registrá una incidencia para cambiarlo.');
   $error=null;$cursor=$step;
   if($step==='planning'){
    $date=sanitize_text_field($input['promised_date']??'');$priority=sanitize_key($input['priority']??'normal');
    if($date && !self::valid_date($date))return new WP_Error('planning','La fecha de entrega no es válida.');
    $order->update_meta_data('_ge_production_promised_date',$date);$order->update_meta_data('_ge_estimated_date',$date);
    $order->update_meta_data('_ge_production_priority',isset(GE_WTP_Production::priorities()[$priority])?$priority:'normal');
    $order->update_meta_data('_ge_production_general_notes',sanitize_textarea_field($input['general_notes']??''));
    if($next && !$date)$error=new WP_Error('planning','Elegí una fecha prometida de entrega para continuar.');else if($next)$cursor='files';
   }elseif($step==='files'){
    $rows=(array)($input['artwork_items']??array());$prepared=array();
    foreach(self::items($order) as $item_id=>$item){
     if(!isset($rows[$item_id]))continue;$row=(array)$rows[$item_id];$old=GE_WTP_Artwork_Library::release_data($item);
     $row['client_required']=($row['approval_mode']??'internal')==='client';
     // Customer approval is preserved from the verified native record, never supplied by this form.
     $row['customer_approved']=!empty($old['customer']['approved']);$row['customer_method']=$old['customer']['method']??'';$row['customer_evidence']=$old['customer']['evidence']??'';
     $prepared[$item_id]=$row;
    }
    foreach(array_keys($rows) as $item_id)if(!isset(self::items($order)[(int)$item_id]))return new WP_Error('files','El producto no pertenece a este pedido.');
    $saved=GE_WTP_Artwork_Library::save_artwork_control($order,$prepared);if(is_wp_error($saved))return $saved;
    if($next){foreach(self::items($order) as $item)if(!GE_WTP_Artwork_Library::item_ready_for_production($item,$order)){$error=new WP_Error('files','Falta revisar o aprobar el archivo de '.$item->get_name().'.');break;}if(!$error)$cursor='instructions';}
   }elseif($step==='instructions'){
    $catalog=GE_WTP_Workflow::finishing_catalog();
    foreach(self::items($order) as $item_id=>$item){
     $selected=array();foreach((array)($input['finishes'][$item_id]??array()) as $key)if(isset($catalog[$key]))$selected[]=$key;
     $item->update_meta_data(GE_WTP_Workflow::FINISHES_META,array_values(array_unique($selected)));
     $item->update_meta_data('_ge_item_custom_finish',sanitize_text_field($input['custom'][$item_id]??''));$item->save();
    }
    $order->update_meta_data('_ge_production_technical_notes',sanitize_textarea_field($input['technical_notes']??''));if($next)$cursor='route';
   }elseif($step==='route'){
    $source=sanitize_key($input['source']??'');$supplier=sanitize_key($input['supplier']??'');$profiles=self::profiles();
    if(!in_array($source,array('internal','supplier'),true))return new WP_Error('route','Elegí quién hará el trabajo.');
    if($source==='supplier' && !isset($profiles[$supplier]))return new WP_Error('route','Seleccioná un proveedor habilitado para producción.');
    $channel=sanitize_key($input['channel']??'email');if(!in_array($channel,array('email','manual'),true))return new WP_Error('route','Seleccioná un canal disponible.');
    $d['routes']=array();foreach(self::items($order) as $item_id=>$item){$key=$source==='internal'?'internal':$supplier;$d['routes'][$item_id]=$key;$item->update_meta_data('_ge_production_supplier',$key);$item->save();}
    $d['channel']=$channel;$order->update_meta_data('_ge_production_source',$source);$order->update_meta_data('_ge_production_supplier',$source==='internal'?'internal':$supplier);
    if($source==='supplier' && $channel==='email' && !is_email($profiles[$supplier]['email']??''))$error=new WP_Error('route','Completá el correo del proveedor o elegí envío manual.');
    if($next && !$error){$valid=self::check($order);if(is_wp_error($valid))$error=$valid;elseif($source==='supplier'){$order->save();$preview=GE_WTP_Supplier_Portal::prepare($id,$supplier,(string)$order->get_meta('_ge_production_technical_notes',true));if(is_wp_error($preview))$error=$preview;else $d['dispatches'][$supplier]=$preview['id'];}}
    if($next && !$error)$cursor='control';
   }elseif($step==='control'){$cursor='control';}
   else return new WP_Error('step','Paso no válido.');
   $d['cursor']=$cursor;self::persist($order,$d,'saved_'.$step);
   return array('saved'=>true,'cursor'=>$cursor,'revision'=>$d['revision']+1,'error'=>$error?$error->get_error_message():null);
  });
 }
 public static function finish($id,$revision){
  return self::locked($id,function()use($id,$revision){
   $order=wc_get_order($id);$access=self::access($order,true);if(is_wp_error($access))return $access;if(!self::enabled($order))return new WP_Error('state','Este trabajo no admite cambios de preparación.');$d=self::data($order);
   if($d['state']!=='preparing')return array('saved'=>true,'duplicate'=>true,'state'=>$d['state']);
   if((int)$revision!==(int)$d['revision'])return new WP_Error('conflict','El control cambió. Revisá el resumen vigente antes de terminar.');
   $check=self::check($order);if(is_wp_error($check))return $check;
   if(count($d['routes'])!==count(self::items($order)))return new WP_Error('route','Elegí el destino de cada producto en Salida.');
   $suppliers=array();$d['authorized']=array();
   foreach(self::items($order) as $item_id=>$item){
    $route=$d['routes'][$item_id]??'';if($route!=='internal' && !isset(self::profiles()[$route]))return new WP_Error('route','El proveedor ya no está habilitado.');
    $d['authorized'][$item_id]=array('signature'=>(string)$item->get_meta('_ge_item_artwork_release_hash',true),'sources'=>(array)$item->get_meta('_ge_item_artwork_sources',true),'at'=>gmdate('c'),'actor'=>get_current_user_id());
    if($route!=='internal')$suppliers[$route]=true;
   }
   // Validate every exact preview before authorizing any actual dispatch.
   foreach(array_keys($suppliers) as $supplier){$row=GE_WTP_Supplier_Portal::latest($order,$supplier);$current=GE_WTP_Supplier_Portal::snapshot($order,$supplier,(string)$order->get_meta('_ge_production_technical_notes',true),true);
    if(!$row||is_wp_error($current)||($d['dispatches'][$supplier]??'')!==$row['id']||!GE_WTP_Supplier_Portal::same_snapshot($row,$current))return new WP_Error('control','Los archivos o las indicaciones cambiaron. Volvé a Salida para revisar una vista previa nueva.');
   }
   $d['generation']=hash('sha256',wp_json_encode(array($d['authorized'],$d['routes'],$order->get_meta('_ge_production_promised_date',true))));
   $d['state']=$suppliers?($d['channel']==='manual'?'awaiting_manual_dispatch':'awaiting_supplier'):'production';$d['cursor']='control';
   $order->update_meta_data(GE_WTP_Workflow::STAGE_META,$suppliers?'supplier_waiting':'production');$order->update_meta_data('_ge_production_status',$suppliers?'approved':'production');
   foreach(self::items($order) as $item_id=>$item){$item->update_meta_data('_ge_item_status',$d['routes'][$item_id]==='internal'?'production':'approved');$item->update_meta_data('_ge_item_workflow_released_by',get_current_user_id());$item->update_meta_data('_ge_item_workflow_released_at',time());$item->save();}
   self::persist($order,$d,'authorized');
   if(!$suppliers){self::start_production($order);return array('saved'=>true,'state'=>'production');}
   foreach(array_keys($suppliers) as $supplier){
    $row=GE_WTP_Supplier_Portal::latest($order,$supplier);
    if($d['channel']==='email'){try{$sent=GE_WTP_Supplier_Portal::notify($id,$row['id']);}catch(Throwable $e){$sent=new WP_Error('dispatch','El envío quedó pendiente de verificación.');}if(is_wp_error($sent)||($sent['email_status']??'')!=='sent')self::task($order,'dispatch:'.$row['id'],'Revisar envío al proveedor',true,'El control quedó guardado. Revisá Notificaciones antes de reintentar.');}
    else self::task($order,'manual:'.$row['id'],'Enviar orden manualmente al proveedor',true,'Abrí la vista previa y registrá el envío real. Copiar el mensaje no confirma que se haya enviado.');
    self::task($order,'confirm:'.$row['id'],'Revisar confirmación del proveedor',true,'El cliente sigue con archivos en preparación hasta que el proveedor acepte la orden exacta.');
   }
   return array('saved'=>true,'state'=>$d['state']);
  });
 }
 private static function task($order,$key,$title,$open,$notes){
  $actor=get_current_user_id();if(!$actor)wp_set_current_user(1);
  try{global $wpdb;$dedupe='production-cards:'.$order->get_id().':'.$key;$existing=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.GE_CRM::table().' WHERE organization_id=%s AND dedupe_key=%s',GE_Organization::PRIMARY,$dedupe));if($existing&&!$open){$wpdb->query('START TRANSACTION');try{$raw=$wpdb->get_row($wpdb->prepare('SELECT revision,payload,status,customer_id FROM '.GE_CRM::table().' WHERE id=%d AND organization_id=%s AND kind=%s FOR UPDATE',$existing,GE_Organization::PRIMARY,'task'),ARRAY_A);if($raw&&$raw['status']==='open'){$payload=json_decode($raw['payload'],true);$payload['status']='done';$ok=$wpdb->update(GE_CRM::table(),array('status'=>'done','payload'=>wp_json_encode($payload),'revision'=>(int)$raw['revision']+1,'updated_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$existing,'organization_id'=>GE_Organization::PRIMARY,'revision'=>$raw['revision']));if($ok!==1)throw new RuntimeException('Conflicto de revisión de tarea.');GE_CRM::event($existing,(int)$raw['customer_id'],'production_task_resolved',array('order_id'=>$order->get_id(),'dedupe_key'=>$dedupe,'previous_status'=>'open','status'=>'done'));}$wpdb->query('COMMIT');}catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}return;}if(!$open)return;GE_CRM::automation_task($dedupe,array('title'=>$title.' · #'.$order->get_id(),'order_id'=>$order->get_id(),'customer_id'=>$order->get_customer_id(),'owner_id'=>1,'status'=>$open?'open':'done','priority'=>'high','due_date'=>wp_date('Y-m-d',time()+DAY_IN_SECONDS),'notes'=>$notes));}finally{if(!$actor)wp_set_current_user($actor);}
 }
 private static function start_production($order){
  $d=self::data($order);$d['state']='production';$order->update_meta_data(GE_WTP_Workflow::STAGE_META,'production');$order->update_meta_data('_ge_production_status','production');
  if(!$order->get_meta('_ge_production_started_at',true))$order->update_meta_data('_ge_production_started_at',time());
  foreach(self::items($order) as $item){$item->update_meta_data('_ge_item_status','production');$item->save();}
  self::persist($order,$d,'production_started');foreach($d['dispatches'] as $dispatch){self::task($order,'issue:'.$dispatch,'Incidencia revisada',false,'');self::task($order,'confirm:'.$dispatch,'Confirmación verificada',false,'');self::task($order,'dispatch:'.$dispatch,'Envío confirmado',false,'');self::task($order,'manual:'.$dispatch,'Envío manual registrado',false,'');}GE_WTP_Order_Lifecycle::set_stage($order,'produccion','Trabajo autorizado; confirmación del proveedor verificada cuando corresponde.');self::customer_notice($order);
 }
 public static function supplier_confirmed($id,$row){
  return self::locked($id,function()use($id,$row){
   $order=wc_get_order($id);if(!self::enabled($order))return true;$d=self::data($order);
   if($d['state']==='production')return true;
   if(!in_array($d['state'],array('awaiting_supplier','awaiting_manual_dispatch'),true)||($d['dispatches'][$row['supplier']]??'')!==$row['id'])return new WP_Error('version','Esta confirmación no corresponde al control vigente.');
   foreach(self::items($order) as $item)if(!self::authorized_for_supplier($order,$item))return new WP_Error('files','El arte cambió después de enviar la orden. Revisá el trabajo antes de continuar.');
   foreach(array_unique($d['routes']) as $supplier){if($supplier==='internal')continue;$latest=GE_WTP_Supplier_Portal::latest($order,$supplier);
    if(!$latest||($d['dispatches'][$supplier]??'')!==$latest['id']||empty($latest['business_confirmed_at'])||!in_array($latest['state'],array('acknowledged','in_production','ready','received'),true))return true;
   }
   $actor=get_current_user_id();if(!$actor)wp_set_current_user(1);try{self::start_production($order);}finally{if(!$actor)wp_set_current_user($actor);}return true;
  });
 }
 public static function customer_notice($order,$retry=false){
  $d=self::data($order);if($d['state']!=='production')return new WP_Error('state','El trabajo todavía no está en producción.');
  $key=$d['generation']??'';if(!$key)return new WP_Error('notice','Falta la autorización del trabajo.');
  $all=$order->get_meta(self::NOTICES,true);$all=is_array($all)?$all:array();$row=$all[$key]??array('state'=>'prepared','attempts'=>0);
  if($row['state']==='sent')return $row;
  if(in_array($row['state'],array('sending','unknown'),true)||($row['state']==='failed'&&!$retry))return $row;
  if($row['attempts']>=3)return new WP_Error('notice','El aviso necesita revisión manual; se alcanzó el límite de intentos.');
  $email=sanitize_email($order->get_billing_email());if(!is_email($email)){self::task($order,'notice:'.$key,'Completar correo para el aviso al cliente',true,'El trabajo está en producción; el aviso sigue pendiente.');return new WP_Error('email','El aviso está pendiente: revisá el correo del cliente.');}
  $row['state']='sending';$row['attempts']++;$row['started_at']=gmdate('c');$all[$key]=$row;$order->update_meta_data(self::NOTICES,$all);$order->save();
  $state='unknown';try{$html=GE_WTP_Email_Content::render('workflow_customer_production',array('reference'=>esc_html(GE_WTP_Manual_Orders::reference($order)),'portal'=>esc_url(GE_WTP_Portal::portal_url('pedidos',array('pedido'=>$order->get_id())))));$state=GE_WTP_Notifications::send($email,'Tu pedido está en producción · '.GE_WTP_Manual_Orders::reference($order),$html,'production_cards_customer',$order->get_id())?'sent':'failed';}catch(Throwable $e){$state='unknown';}
  $row['state']=$state;$row['finished_at']=gmdate('c');$all[$key]=$row;$order->update_meta_data(self::NOTICES,$all);
  if($state==='sent'){$history=(array)$order->get_meta('_ge_workflow_customer_notices',true);$history[]=array('time'=>time(),'user_id'=>get_current_user_id(),'sent'=>true,'email'=>$email,'generation'=>$key);$order->update_meta_data('_ge_workflow_customer_notices',$history);}
  $order->save();if($state==='sent')self::task($order,'notice:'.$key,'Aviso enviado',false,'');if($state!=='sent')self::task($order,'notice:'.$key,'Revisar aviso al cliente',true,'El trabajo está en producción. El aviso no se confirmó; no se repite automáticamente.');return $row;
 }
 private static function posted($action){
  $id=absint($_POST['order_id']??0);$order=wc_get_order($id);$access=self::access($order,true);if(is_wp_error($access))wp_die(esc_html($access->get_error_message()),'',array('response'=>403));check_admin_referer('ge_prod_cards_'.$action.'_'.$id);return $order;
 }
 public static function url($order,$step='planning',$args=array()){return GE_WTP_Staff_Portal::portal_url('production',array_merge(array('order_id'=>$order->get_id(),'step'=>$step),$args));}
 private static function back($order,$step,$result){
  if(is_wp_error($result)){set_transient('ge_prod_cards_error_'.get_current_user_id().'_'.$order->get_id(),$result->get_error_message(),15*MINUTE_IN_SECONDS);set_transient('ge_prod_cards_input_'.get_current_user_id().'_'.$order->get_id(),array('step'=>$step,'input'=>array_intersect_key(wp_unslash($_POST),array_flip(array('promised_date','priority','general_notes','artwork_items','finishes','custom','technical_notes','source','supplier','channel')))),15*MINUTE_IN_SECONDS);}
  elseif(!empty($result['error']))set_transient('ge_prod_cards_error_'.get_current_user_id().'_'.$order->get_id(),$result['error'],15*MINUTE_IN_SECONDS);
  if(!is_wp_error($result)&&empty($result['error']))delete_transient('ge_prod_cards_input_'.get_current_user_id().'_'.$order->get_id());
  $target=$result['cursor']??$step;if(is_wp_error($result)&&in_array($result->get_error_code(),array_keys(GE_WTP_Production_Cards_UI::labels()),true))$target=$result->get_error_code();wp_safe_redirect(self::url($order,$target));exit;
 }
 public static function handle_save(){
  $o=self::posted('save');$step=sanitize_key($_POST['card_step']??'planning');$result=self::save($o->get_id(),absint($_POST['revision']??0),$step,wp_unslash($_POST),($_POST['navigation']??'')==='next');
  if(!is_wp_error($result)&&empty($result['error'])&&($_POST['navigation']??'')==='back'){$keys=array_keys(GE_WTP_Production_Cards_UI::labels());$index=array_search($step,$keys,true);$result['cursor']=$keys[max(0,$index-1)];self::locked($o->get_id(),function()use($o,$result){$current=wc_get_order($o->get_id());$d=self::data($current);if($d['revision']===$result['revision']){$d['cursor']=$result['cursor'];self::persist($current,$d,'back');}});}
  self::back($o,$step,$result);
 }
 public static function handle_finish(){$o=self::posted('finish');self::back($o,'control',self::finish($o->get_id(),absint($_POST['revision']??0)));}
 public static function handle_approval(){$o=self::posted('approval');$result=GE_WTP_Workflow::send_artwork_approval($o,absint($_POST['item_id']??0));self::back($o,'files',$result);}
 public static function handle_supplier_confirm(){$o=self::posted('supplier_confirm');self::back($o,'control',GE_WTP_Supplier_Portal::confirm_manually($o->get_id(),sanitize_text_field($_POST['dispatch_id']??''),sanitize_key($_POST['method']??''),sanitize_textarea_field($_POST['evidence']??'')));}
 public static function handle_manual_dispatch(){$o=self::posted('manual_dispatch');$proof=sanitize_textarea_field($_POST['evidence']??'');if(strlen($proof)<10)self::back($o,'control',new WP_Error('evidence','Indicá cómo y cuándo se envió realmente la orden.'));self::back($o,'control',GE_WTP_Supplier_Portal::record_manual_dispatch($o->get_id(),sanitize_text_field($_POST['dispatch_id']??''),$proof));}
 public static function handle_notice_retry(){$o=self::posted('notice_retry');if(empty($_POST['verified_not_sent']))self::back($o,'control',new WP_Error('notice','Verificá Notificaciones antes de reintentar.'));self::back($o,'control',self::locked($o->get_id(),function()use($o){return self::customer_notice($o,true);}));}

 public static function supplier_issue($id,$row){$order=wc_get_order($id);if(!self::enabled($order))return;$d=self::data($order);if(($d['dispatches'][$row['supplier']]??'')!==$row['id'])return;self::task($order,'issue:'.$row['id'],'Revisar incidencia del proveedor',true,'El proveedor informó '.$row['state'].'. Revisá su orden y la fecha de entrega.');}
 public static function retry_supplier($id,$dispatch){return self::locked($id,function()use($id,$dispatch){$o=wc_get_order($id);$access=self::access($o,true);if(is_wp_error($access))return $access;$d=self::data($o);if($d['state']!=='awaiting_supplier'||!in_array($dispatch,$d['dispatches'],true))return new WP_Error('dispatch','El envío no corresponde al control vigente.');try{$r=GE_WTP_Supplier_Portal::notify($id,$dispatch,false,true);}catch(Throwable $e){$r=new WP_Error('dispatch','El intento quedó pendiente de verificación.');}if(!is_wp_error($r)&&!empty($r['sent_at']))self::task($o,'dispatch:'.$dispatch,'Envío registrado',false,'');return $r;});}
 public static function reopen($id,$revision){return self::locked($id,function()use($id,$revision){$o=wc_get_order($id);$access=self::access($o,true);if(is_wp_error($access))return $access;$d=self::data($o);if($d['revision']!==(int)$revision)return new WP_Error('conflict','El control cambió; revisá el estado vigente.');if(!in_array($d['state'],array('awaiting_supplier','awaiting_manual_dispatch'),true))return new WP_Error('state','Este trabajo no admite volver a preparación.');$rows=(array)$o->get_meta(GE_WTP_Supplier_Portal::META,true);foreach($rows as &$row)if(in_array($row['id'],$d['dispatches'],true)){$row['revoked']=true;$row['events'][]=array('time'=>time(),'action'=>'preparation_reopened','actor'=>get_current_user_id());}unset($row);$o->update_meta_data(GE_WTP_Supplier_Portal::META,$rows);foreach($d['dispatches'] as $dispatch)foreach(array('confirm:','dispatch:','manual:','issue:') as $prefix)self::task($o,$prefix.$dispatch,'Control reemplazado',false,'');$d['state']='preparing';$d['cursor']='files';$d['dispatches']=array();$d['authorized']=array();unset($d['generation']);$o->update_meta_data(GE_WTP_Workflow::STAGE_META,'review');$o->update_meta_data('_ge_production_status','pending');foreach(self::items($o) as $item){$item->update_meta_data('_ge_item_status','pending');$item->save();}self::persist($o,$d,'reopened');return array('saved'=>true,'cursor'=>'files');});}
 public static function handle_supplier_retry(){$o=self::posted('supplier_retry');if(empty($_POST['verified_not_sent']))self::back($o,'control',new WP_Error('dispatch','Revisá Notificaciones antes de reintentar.'));self::back($o,'control',self::retry_supplier($o->get_id(),sanitize_text_field($_POST['dispatch_id']??'')));}
 public static function handle_reopen(){$o=self::posted('reopen');self::back($o,'control',self::reopen($o->get_id(),absint($_POST['revision']??0)));}
 public static function render($order){GE_WTP_Production_Cards_UI::render($order);}
}
