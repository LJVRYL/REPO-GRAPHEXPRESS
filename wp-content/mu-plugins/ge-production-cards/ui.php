<?php
defined('ABSPATH') || exit;
final class GE_WTP_Production_Cards_UI {
 public static function labels(){return array('planning'=>'Planificación','files'=>'Archivo y preprensa','instructions'=>'Indicaciones de producción','route'=>'Salida','control'=>'Control final');}
 public static function step($order){
  $d=GE_WTP_Production_Cards::data($order);$step=sanitize_key($_GET['step']??$d['cursor']);$legacy=array('review'=>'planning','prepress'=>'files','production'=>'instructions','supplier'=>'route','customer'=>'control');$step=$legacy[$step]??$step;
  return isset(self::labels()[$step])?$step:'planning';
 }
 private static function form($order,$action='save',$step=''){
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-ge-prod-form><input type="hidden" name="action" value="ge_prod_cards_'.esc_attr($action).'"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="revision" value="'.absint(GE_WTP_Production_Cards::data($order)['revision']).'">';
  if($step)echo '<input type="hidden" name="card_step" value="'.esc_attr($step).'">';wp_nonce_field('ge_prod_cards_'.$action.'_'.$order->get_id());
 }
 private static function footer($order,$step){
  $keys=array_keys(self::labels());$index=array_search($step,$keys,true);
  echo '<div class="ge-prod-actions">';if($index>0)echo '<button class="ge-staff-button is-secondary" name="navigation" value="back" formnovalidate>Atrás</button>';
  echo '<button class="ge-staff-button is-secondary" name="navigation" value="save" formnovalidate>Guardar y retomar</button><button class="ge-staff-button" name="navigation" value="next">Continuar</button></div><p class="ge-prod-save-state" role="status" data-ge-prod-save-state>Los cambios se guardan al continuar.</p></form>';
 }
 public static function render($order){
  $access=GE_WTP_Production_Cards::access($order);if(is_wp_error($access)){echo '<p>'.esc_html($access->get_error_message()).'</p>';return;}
  wp_enqueue_style('ge-production-cards',plugins_url('assets/cards.css',__FILE__),array(),filemtime(__DIR__.'/assets/cards.css'));
  wp_enqueue_script('ge-production-cards',plugins_url('assets/cards.js',__FILE__),array(),filemtime(__DIR__.'/assets/cards.js'),true);
  $d=GE_WTP_Production_Cards::data($order);$step=self::step($order);if($d['state']!=='preparing')$step='control';$labels=self::labels();$index=array_search($step,array_keys($labels),true)+1;
  $pending=get_transient('ge_prod_cards_input_'.get_current_user_id().'_'.$order->get_id());$pending=is_array($pending)&&($pending['step']??'')===$step?$pending['input']:array();if($pending)delete_transient('ge_prod_cards_input_'.get_current_user_id().'_'.$order->get_id());
  echo '<div class="ge-prod-wizard" data-ge-prod-wizard data-order="'.$order->get_id().'" data-user="'.get_current_user_id().'" data-revision="'.absint($d['revision']).'" data-step="'.esc_attr($step).'" data-readonly="'.(is_wp_error(GE_WTP_Production_Cards::access($order,true))?'1':'0').'" data-pending="'.esc_attr(wp_json_encode($pending)).'">';
  echo '<header class="ge-prod-header"><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('jobs')).'">← Volver a trabajos</a><div><h1>'.esc_html(GE_WTP_Manual_Orders::reference($order)).'</h1><span>'.esc_html(GE_WTP_Production_Cards::customer_label($order)).'</span></div><p>'.esc_html($order->get_formatted_billing_full_name()?:$order->get_billing_company()).'</p></header>';
  echo '<nav class="ge-prod-steps" aria-label="Preparar producción">';foreach($labels as $key=>$label)echo '<a href="'.esc_url(GE_WTP_Production_Cards::url($order,$key)).'"'.($key===$step?' aria-current="step"':'').'>'.esc_html($label).'</a>';echo '</nav>';
  $error=get_transient('ge_prod_cards_error_'.get_current_user_id().'_'.$order->get_id());if($error){echo '<p class="ge-production-notice is-error" role="alert">'.esc_html($error).'</p>';delete_transient('ge_prod_cards_error_'.get_current_user_id().'_'.$order->get_id());}
  echo '<section class="ge-production-card ge-prod-card" data-ge-prod-card><p class="ge-prod-eyebrow">Paso '.$index.' de 5</p><h2>'.esc_html($labels[$step]).'</h2>';
  if($d['state']!=='preparing')self::result($order,$d);
  elseif($step==='planning')self::planning($order);
  elseif($step==='files')self::files($order);
  elseif($step==='instructions')self::instructions($order);
  elseif($step==='route')self::route($order);
  else self::control($order);
  echo '</section>';if(!class_exists('GE_Organization_Runtime')||GE_Organization_Runtime::allowed('finance')||GE_Organization_Runtime::allowed('quotes',true)){echo '<details class="ge-prod-payment"><summary>Pago y facturación</summary><p>La seña acreditada o una excepción vigente permite comprometer producción. Un comprobante cargado sigue pendiente hasta que se verifique su acreditación.</p>';
  GE_WTP_Payments::render_staff_order_payment($order);
  $quote_id=absint($order->get_meta('_ge_commercial_quote_id',true));if($quote_id){$quote=GE_WTP_Commercial_Quotes::get($quote_id);if(!is_wp_error($quote))GE_WTP_Commercial_Checkout::render_staff_payment($quote);}
  echo '</details>';}echo '</div>';
 }
 private static function planning($order){
  echo '<p>Definí el compromiso de entrega y las indicaciones generales.</p>';self::form($order,'save','planning');
  echo '<div class="ge-prod-fields"><label>Fecha prometida de entrega<input type="date" name="promised_date" value="'.esc_attr($order->get_meta('_ge_production_promised_date',true)).'"></label><label>Prioridad<select name="priority">';
  foreach(GE_WTP_Production::priorities() as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($order->get_meta('_ge_production_priority',true),$key,false).'>'.esc_html($label).'</option>';
  echo '</select></label><label class="is-wide">Indicaciones generales<textarea name="general_notes" rows="4" maxlength="3000">'.esc_textarea($order->get_meta('_ge_production_general_notes',true)).'</textarea><small>Uso interno. Las indicaciones que recibirá el proveedor se completan más adelante.</small></label></div>';self::footer($order,'planning');
 }
 private static function files($order){
  if(class_exists('GE_WTP_AI_Artwork'))GE_WTP_AI_Artwork::assets();
  $available=GE_WTP_Artwork_Library::order_sources($order);$docs=GE_WTP_Documents::get_documents_with_analysis($order->get_id());$by_id=array();foreach($docs as $doc)$by_id[$doc['id']]=$doc;
  echo '<p>Elegí el archivo exacto para cada producto y quién debe aprobarlo.</p>';self::form($order,'save','files');
  foreach(GE_WTP_Production_Cards::items($order) as $id=>$item){
   $data=GE_WTP_Artwork_Library::release_data($item);$signature=GE_WTP_Artwork_Library::release_fingerprint($item,$data['sources'],$data['version'],$data['expected'],$available);
   $staff=!empty($data['staff']['approved']) && (GE_WTP_Artwork_Library::item_ready_for_production($item,$order)||hash_equals((string)($data['staff']['checksum']??''),$signature));
   echo '<article class="ge-prod-item"><h3>'.esc_html($item->get_name()).'</h3><p>'.esc_html(count($data['sources'])).' archivo(s) seleccionados</p><div class="ge-prod-files">';
   if(!$available)echo '<p>Todavía no hay un archivo final. Cargalo debajo para continuar.</p>';
   foreach($available as $token=>$source){$doc=0===strpos($token,'document:')?($by_id[substr($token,9)]??array()):array();
    echo '<label class="ge-prod-file"><input type="checkbox" name="artwork_items['.$id.'][sources][]" value="'.esc_attr($token).'" '.checked(in_array($token,$data['sources'],true),true,false).'><span><strong>'.esc_html($source['name']).'</strong>';
    if($doc)echo '<small>'.esc_html(size_format((int)($doc['size']??0))).'</small>';
    echo '<a href="'.esc_url($source['url']).'" target="_blank" rel="noopener">Ver archivo</a></span></label>';
    if($doc && !empty($doc['analysis'])){echo '<details class="ge-prod-analysis"><summary>Ver análisis y avisos de preprensa</summary>';if(!empty($doc['file_analysis_ref'])||!empty($doc['analysis']['file_analysis_ref']))GE_WTP_File_Analysis::render($doc,true);else self::analysis($doc['analysis']);echo '</details>';}
   }echo '</div><fieldset class="ge-prod-choices"><legend>Aprobación del archivo</legend><label><input type="radio" name="artwork_items['.$id.'][approval_mode]" value="internal" '.checked(!$data['client_required'],true,false).'> Aprobado internamente</label><label><input type="radio" name="artwork_items['.$id.'][approval_mode]" value="client" '.checked($data['client_required'],true,false).'> Requiere aprobación del cliente</label></fieldset>';
   echo '<label class="ge-prod-check"><input type="checkbox" name="artwork_items['.$id.'][staff_approved]" value="1" '.checked($staff,true,false).'> Revisé este archivo: medidas, páginas, orientación y avisos de preprensa</label>';
   if($data['client_required'])echo '<p class="ge-prod-state">'.(!empty($data['customer']['approved'])?'El cliente aprobó esta versión.':'Esperando aprobación del cliente. Podés guardar o volver atrás.').'</p>';
   echo '<details><summary>Comentarios y detalles del archivo</summary><div class="ge-prod-fields"><label>Comentario<textarea name="artwork_items['.$id.'][notes]" rows="3">'.esc_textarea($data['expected']['notes']??'').'</textarea></label><label>Medida esperada<input name="artwork_items['.$id.'][dimensions]" value="'.esc_attr($data['expected']['dimensions']??'').'"></label><label>Páginas o diseños esperados<input name="artwork_items['.$id.'][pages]" value="'.esc_attr($data['expected']['pages']??'').'"></label><label>Orientación<input name="artwork_items['.$id.'][orientation]" value="'.esc_attr($data['expected']['orientation']??'').'"></label></div><input type="hidden" name="artwork_items['.$id.'][version]" value="'.esc_attr($data['version']).'"></details></article>';
  }self::footer($order,'files');
  foreach(GE_WTP_Production_Cards::items($order) as $id=>$item){
   $release=GE_WTP_Artwork_Library::release_data($item);
   if($release['client_required'] && !empty($release['staff']['approved']) && empty($release['customer']['approved'])){self::form($order,'approval');echo '<input type="hidden" name="item_id" value="'.$id.'"><button class="ge-staff-button">Enviar archivo al cliente para aprobar · '.esc_html($item->get_name()).'</button></form>';}
   echo '<details class="ge-prod-upload"><summary>Cargar archivo o nueva versión · '.esc_html($item->get_name()).'</summary><p>Para editar manualmente, descargá el original y cargá una versión nueva. La aprobación se revisa cuando elegís otro archivo.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_production_document"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="order_item_id" value="'.$id.'"><input type="hidden" name="return_step" value="prepress"><input type="hidden" name="category" value="arte">';wp_nonce_field('ge_production_document_'.$order->get_id().'_'.$id);
   echo '<label>Contenido<select name="artwork_side"><option value="front">Frente</option><option value="back">Dorso</option><option value="both">Frente y dorso</option><option value="reference">Referencia</option></select></label><label>Archivo<input type="file" name="ge_documents[]" multiple required></label><button class="ge-staff-button">Cargar versión</button></form></details>';
  }
  if(class_exists('GE_WTP_AI_Artwork')){echo '<details class="ge-prod-ai"><summary>Mejorar con IA</summary><p>Las herramientas disponibles indican si hay una conexión operativa. Una edición genera una candidata nueva que debés revisar y aprobar.</p>';foreach($docs as $doc)GE_WTP_AI_Artwork::render_button($order,$doc);echo '</details>';}
  else echo '<p class="ge-prod-helper">Mejoras con IA: conexión pendiente. Podés descargar el original y cargar una versión corregida.</p>';
 }
 private static function analysis($analysis){
  foreach(array('pages'=>'Páginas','page_count'=>'Páginas','color_space'=>'Color','width_mm'=>'Ancho en mm','height_mm'=>'Alto en mm') as $key=>$label)if(isset($analysis[$key])&&is_scalar($analysis[$key]))echo '<p>'.esc_html($label).': '.esc_html($analysis[$key]).'</p>';
  foreach((array)($analysis['warnings']??array()) as $warning)if(is_scalar($warning))echo '<p>'.esc_html($warning).'</p>';echo '<p>La revisión interna confirma este archivo exacto; no convierte automáticamente su color ni su resolución.</p>';
 }
 private static function instructions($order){
  echo '<p>La ficha técnica conserva los datos del producto y del archivo aprobado.</p>';self::form($order,'save','instructions');$catalog=GE_WTP_Workflow::finishing_catalog();
  foreach(GE_WTP_Production_Cards::items($order) as $id=>$item){echo '<article class="ge-prod-item"><h3>'.esc_html($item->get_quantity().' × '.$item->get_name()).'</h3><details><summary>Terminaciones</summary><div class="ge-prod-finishes">';foreach($catalog as $key=>$label)echo '<label><input type="checkbox" name="finishes['.$id.'][]" value="'.esc_attr($key).'" '.checked(in_array($key,(array)$item->get_meta(GE_WTP_Workflow::FINISHES_META,true),true),true,false).'> '.esc_html($label).'</label>';echo '</div><label>Otra terminación<input name="custom['.$id.']" value="'.esc_attr($item->get_meta('_ge_item_custom_finish',true)).'"></label></details></article>';}
  echo '<label>Indicaciones específicas de producción<textarea name="technical_notes" rows="5" maxlength="3000">'.esc_textarea($order->get_meta('_ge_production_technical_notes',true)).'</textarea><small>Estas indicaciones se incluirán en la orden al proveedor, si corresponde.</small></label>';self::footer($order,'instructions');
 }
 private static function route($order){
  $d=GE_WTP_Production_Cards::data($order);$source=$order->get_meta('_ge_production_source',true);$supplier=$order->get_meta('_ge_production_supplier',true);
  echo '<p>Elegí dónde se hace el trabajo. El proveedor verá sólo la ficha técnica y los archivos elegidos.</p>';self::form($order,'save','route');
  echo '<fieldset class="ge-prod-choices"><legend>¿Quién lo produce?</legend><label><input type="radio" name="source" value="internal" '.checked($source,'internal',false).'> Lo hacemos nosotros</label><label><input type="radio" name="source" value="supplier" '.checked($source,'supplier',false).'> Enviar a un proveedor</label></fieldset><div data-ge-prod-supplier><label>Proveedor<select name="supplier"><option value="">Seleccionar proveedor</option>';
  foreach(GE_WTP_Production_Cards::profiles() as $key=>$profile)echo '<option value="'.esc_attr($key).'" '.selected($supplier,$key,false).'>'.esc_html($profile['name']).'</option>';
  echo '</select></label><label>Canal<select name="channel"><option value="email" '.selected($d['channel'],'email',false).'>Correo de Graphex</option><option value="manual" '.selected($d['channel'],'manual',false).'>Envío manual · correo o WhatsApp</option></select></label><p>El envío manual requiere registrar cómo se envió. Abrir WhatsApp o copiar el mensaje no lo marca como enviado.</p></div>';self::footer($order,'route');
 }
 private static function summary($order){
  echo '<dl class="ge-prod-summary"><div><dt>Entrega prometida</dt><dd>'.esc_html(GE_WTP_Production::date_label_public($order->get_meta('_ge_production_promised_date',true))).'</dd></div><div><dt>Aprobación</dt><dd>';foreach(GE_WTP_Production_Cards::items($order) as $item){$r=GE_WTP_Artwork_Library::release_data($item);echo esc_html($item->get_name()).' · '.($r['client_required']?'Cliente':'Interna').'<br>';}echo '</dd></div><div><dt>Indicaciones</dt><dd>'.esc_html($order->get_meta('_ge_production_technical_notes',true)?:'Sin indicaciones adicionales').'</dd></div></dl>';
 }
 private static function control($order){
  GE_Production_Payment_Guard::render($order);
  echo '<p>Revisá el resumen y terminá el control.</p>';self::summary($order);$d=GE_WTP_Production_Cards::data($order);
  foreach(array_unique($d['routes']) as $supplier){if($supplier==='internal'){echo '<p><strong>Destino:</strong> producción interna.</p>';continue;}$profile=GE_WTP_Production_Cards::profiles()[$supplier]??array();$row=GE_WTP_Supplier_Portal::latest($order,$supplier);if(!$row)continue;
   echo '<section class="ge-prod-preview"><h3>Orden a '.esc_html($profile['name']??$supplier).'</h3><p>Correo: '.esc_html($row['email']?:'Envío manual').'</p><p>Se comparten estos archivos y sus especificaciones. No se incluye el precio del cliente ni sus datos de facturación.</p><ul>';foreach($row['snapshot']['files'] as $file)echo '<li>'.esc_html($file['record']['name']).'</li>';echo '</ul><details><summary>Ver mensaje exacto</summary><pre>'.esc_html(GE_WTP_Supplier_Portal::message($row,GE_WTP_Supplier_Portal::url($order->get_id(),$row))).'</pre></details><p>El cliente continuará con archivos en preparación hasta que el proveedor confirme este trabajo.</p></section>';
  }
  self::form($order,'finish');echo '<div class="ge-prod-actions"><a class="ge-staff-button is-secondary" href="'.esc_url(GE_WTP_Production_Cards::url($order,'route')).'">Atrás</a><button class="ge-staff-button" '.disabled(is_wp_error(GE_Production_Payment_Guard::check($order)),true,false).'>Guardar control y terminar</button></div></form>';
 }
 private static function result($order,$d){
  $labels=array('production'=>'Trabajo en producción','awaiting_supplier'=>'Esperando confirmación del proveedor','awaiting_manual_dispatch'=>'Pendiente de envío manual');echo '<p class="ge-prod-state">'.esc_html($labels[$d['state']]??'Revisar trabajo').'</p>';self::summary($order);
  foreach($d['dispatches'] as $supplier=>$dispatch){$row=GE_WTP_Supplier_Portal::latest($order,$supplier);if(!$row)continue;echo '<article class="ge-prod-item"><h3>'.esc_html(GE_WTP_Supplier_Portal::profiles()[$supplier]['name']??$supplier).'</h3><p>'.esc_html(GE_WTP_Supplier_Portal::state_label($row)).'</p>';
   if(empty($row['sent_at'])&&$d['channel']==='manual'){echo '<details><summary>Mensaje y registro del envío manual</summary><pre>'.esc_html(GE_WTP_Supplier_Portal::message($row,GE_WTP_Supplier_Portal::url($order->get_id(),$row))).'</pre>';self::form($order,'manual_dispatch');echo '<input type="hidden" name="dispatch_id" value="'.esc_attr($row['id']).'"><label>Cómo y cuándo se envió realmente<textarea name="evidence" rows="3" minlength="10" required></textarea></label><button class="ge-staff-button">Registrar envío manual realizado</button></form></details>';}
   if(!empty($row['sent_at'])&&!in_array($row['state'],array('acknowledged','in_production','ready','received'),true)){echo '<details><summary>Registrar confirmación verificada del proveedor</summary>';self::form($order,'supplier_confirm');echo '<input type="hidden" name="dispatch_id" value="'.esc_attr($row['id']).'"><label>Medio de confirmación<select name="method"><option value="email">Correo</option><option value="whatsapp">WhatsApp</option><option value="phone">Teléfono</option></select></label><label>Evidencia: referencia de la conversación o confirmación recibida<textarea name="evidence" rows="3" minlength="10" required></textarea></label><p>Registrá sólo una aceptación real de esta orden y de estos archivos.</p><button class="ge-staff-button">Guardar confirmación del proveedor</button></form></details>';}
   if(!empty($row['issue_reason']))echo '<p role="alert">'.esc_html($row['issue_reason']).'</p>';
   if(empty($row['sent_at'])&&$d['channel']==='email'&&($row['attempts']??0)<3){self::form($order,'supplier_retry');echo '<input type="hidden" name="dispatch_id" value="'.esc_attr($row['id']).'"><label><input type="checkbox" name="verified_not_sent" value="1" required> Revisé Notificaciones: este envío no llegó a enviarse</label><button class="ge-staff-button">Reintentar envío al proveedor</button></form>';}
   echo '</article>';
  }
  if(in_array($d['state'],array('awaiting_supplier','awaiting_manual_dispatch'),true)){echo '<details><summary>Corregir el control o cambiar de proveedor</summary><p>Se revocan los enlaces anteriores. Revisá los archivos y prepará una orden nueva antes de enviarla.</p>';self::form($order,'reopen');echo '<button class="ge-staff-button is-secondary">Volver a preparación</button></form></details>';}
  if($d['state']==='production')echo '<p><a class="ge-staff-button" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$order->get_id()))).'">Continuar seguimiento y entrega</a></p>';
  $notices=(array)$order->get_meta(GE_WTP_Production_Cards::NOTICES,true);$notice=$notices[$d['generation']??'']??array();if($d['state']==='production'){echo '<p>Aviso al cliente: '.esc_html(($notice['state']??'')==='sent'?'enviado y registrado':'pendiente de verificación').'.</p>';if(($notice['state']??'')==='failed'){self::form($order,'notice_retry');echo '<label><input type="checkbox" name="verified_not_sent" value="1" required> Revisé Notificaciones: el aviso no fue enviado</label><button class="ge-staff-button">Reintentar aviso</button></form>';}}
 }
}
