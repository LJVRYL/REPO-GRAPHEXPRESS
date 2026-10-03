<?php
$_SERVER['HTTP_HOST']='localhost:19050';$_SERVER['SERVER_NAME']='localhost';
require '/home/leo/ge-organizations/empresa-qa/site/wp-load.php';
if(DB_NAME!=='ge_org_empresa_qa'||GE_Organization::PRIMARY!=='empresa-qa')exit('WRONG_QA');
$out=dirname(__DIR__).'/outputs';$checks=array();
function ok($value,$name){global $checks;$checks[$name]=(bool)$value;if(!$value)throw new RuntimeException('FAIL '.$name);}
function result($value,$name){ok(!is_wp_error($value),$name.':'.(is_wp_error($value)?$value->get_error_message():'ok'));return $value;}
function setting($tab,$raw){$o=GE_Organization::get(GE_Organization::PRIMARY);return result(GE_Organization::save(GE_Organization::PRIMARY,1,$o['revision'],$tab,$raw),'save_'.$tab);}
function fake_cuit($base){$w=array(5,4,3,2,7,6,5,4,3,2);$sum=0;for($i=0;$i<10;$i++)$sum+=(int)$base[$i]*$w[$i];return $base.((11-$sum%11)%11);}
try {
 wp_set_current_user(1);global $wpdb;
 ok(GE_Organization_Runtime::binding()===get_option(GE_Organization_Runtime::BINDING),'instance_bound');
 ok(!get_userdata(29)||get_user_meta(29,'_ge_organization_id',true)==='empresa-qa','numeric_id29_never_resolves_to_graph_customer');
 ok(is_wp_error(GE_WTP_Commercial_Quotes::get(986,1)),'graph_quote986_absent');
 ok(!wc_get_order(978),'graph_order978_absent');
 ok(!isset(GE_WTP_Production::suppliers()['bandurria']),'graph_supplier_absent');
 $wpdb->suppress_errors(true);$x=$wpdb->get_results('SELECT ID FROM graph_org_foundation_20261002.wp_users LIMIT 1');
 ok($wpdb->last_error!=='' && !$x,'database_principal_cannot_read_graph_fixture');
 $x=$wpdb->query("UPDATE graph_org_foundation_20261002.wp_users SET display_name='DENIED' WHERE ID=1");ok($x===false,'database_principal_cannot_write_graph_fixture');$wpdb->suppress_errors(false);
 $o=setting('general',array('display_name'=>'Empresa QA Independiente','brand_name'=>'Taller QA','legal_name'=>'Empresa QA ficticia','email'=>'contacto@empresa-qa.invalid','phone'=>'+54 11 0000 0000','address'=>'Domicilio de prueba','website'=>'http://localhost:19050','currency'=>'ARS','country'=>'AR'));
 $o=setting('documents',array('terms'=>'Condiciones propias de QA','payment_terms'=>'Pago acordado en QA','footer'=>'Taller QA · contacto@empresa-qa.invalid','quote_valid_days'=>12,'email_text'=>'Gracias por elegir Taller QA.'));
 setting('portal',array('domain'=>'empresa-qa.invalid','support_email'=>'contacto@empresa-qa.invalid','welcome_text'=>'Portal propio de Taller QA.'));
 // Import an actual portable export into the provisioned operative destination.
 $bundle=GE_Organization::export_config(GE_Organization::PRIMARY,1);$bundle['settings']['branding']['primary_color']='#087f8c';
 $bundle['settings']['integration_refs']=array('email'=>'FAKE_SECRET_REF');
 $o=result(GE_Organization::import_current($bundle,1),'portable_import_current');
 ok($o['organization_id']==='empresa-qa' && empty($o['settings']['integration_refs']),'import_keeps_instance_and_drops_refs');
 // Local, synthetic logo is embedded in the immutable PDF snapshot, without remote fetches.
 if(function_exists('imagecreatetruecolor')) {
   $im=imagecreatetruecolor(128,128);$teal=imagecolorallocate($im,8,127,140);$white=imagecolorallocate($im,255,255,255);imagefill($im,0,0,$teal);imagestring($im,5,52,56,'QA',$white);ob_start();imagejpeg($im,null,90);$logo=ob_get_clean();imagedestroy($im);
   $upload=wp_upload_bits('qa-logo.jpg',null,$logo);ok(empty($upload['error']),'own_logo_uploaded');
   $attachment=wp_insert_attachment(array('post_title'=>'Logo QA','post_mime_type'=>'image/jpeg','post_status'=>'inherit'),$upload['file']);update_attached_file($attachment,$upload['file']);
   setting('branding',array('logo_url'=>$upload['url'],'primary_color'=>'#087f8c'));
 }
 // A synthetic QA issuer, never an ARCA verification or fiscal invoice.
 $cuit=fake_cuit('2099999998');
 $raw=array('display_name'=>'Emisor QA ficticio','legal_name'=>'Empresa QA ficticia','cuit'=>$cuit,'vat_status'=>'monotributo','fiscal_address'=>'Domicilio de prueba','locality'=>'QA','province'=>'QA','country'=>'AR','contact_email'=>'contacto@empresa-qa.invalid','commercial_brand'=>'Taller QA','point_of_sale'=>'0001','invoice_types_allowed'=>array('C'),'default_for_scenarios'=>array('common'),'active'=>true,'relationship_confirmed'=>true,'verification_status'=>'verified','tax_rate_basis_points'=>0,'common_price_policy'=>'tax_exclusive','invoice_a_price_policy'=>'tax_exclusive');
 $issuer=result(GE_WTP_Billing_Issuers::save('qa-emisor',$raw,1,'Fixture sintética QA, no verificación ARCA',null),'issuer_own');
 ok(count(GE_WTP_Billing_Issuers::all())===1,'only_own_issuer');
 update_option('ge_customer_tax_stage_v1',3);
 $o=result(GE_Organization::complete_onboarding(1),'onboarding_complete');ok(!empty($o['onboarding']['ready']),'organization_operational');
 $suffix=substr(str_replace('-','',wp_generate_uuid4()),0,12);
 $customer=result(wp_insert_user(array('user_login'=>'qa_customer_'.$suffix,'user_email'=>'cliente-'.$suffix.'@empresa-qa.invalid','display_name'=>'Cliente exclusivo de Taller QA','user_pass'=>wp_generate_password(48),'role'=>'customer')),'customer_created');
 update_user_meta($customer,'_ge_email_verified','yes');
 $profile=GE_WTP_Billing::normalize_profile(array('billing_mode'=>'common','legal_name'=>'Cliente exclusivo de Taller QA','vat_status'=>'final_consumer','billing_email'=>'cliente-'.$suffix.'@empresa-qa.invalid','fiscal_address'=>'Domicilio cliente QA'));
 update_user_meta($customer,GE_WTP_Billing::PROFILE_META,$profile);
 $line=array('source_type'=>'custom','name'=>'Servicio propio de Taller QA','quantity'=>'2','unit'=>'servicio','unit_net'=>'1250.00','details'=>'Trabajo QA, no enviar a producción real');
 $quote=result(GE_WTP_Commercial_Quotes::create_draft($customer,array($line),array('billing_profile_id'=>'default','issuer_profile_id'=>'qa-emisor','issuer_change_reason'=>'Emisor propio de la empresa QA','customer_tax_confirm'=>1),1),'quote_created');
 ok(($quote['snapshot']['organization_snapshot']['organization_id']??'')==='empresa-qa','quote_organization_snapshot');
 ok($quote['snapshot']['issuer_profile_id']==='qa-emisor','quote_own_issuer');
 $quote=result(GE_WTP_Commercial_Quotes::send($quote['id'],1),'quote_send_simulated');
 $order=result(GE_WTP_Commercial_Checkout::convert_staff($quote['id'],array('expected_version'=>$quote['version'],'confirmation_method'=>'staff','reason'=>'Flujo de aceptación QA','payment_state'=>'unregistered','production_mode'=>'internal'),1),'quote_to_order');
 ok($order->get_customer_id()===$customer,'order_own_customer');
 ok($order->get_meta('_ge_organization_id')==='empresa-qa','order_bound_organization');
 // Document is stored in this instance's private organization namespace.
 $quote=GE_WTP_Commercial_Quotes::get($quote['id'],1);$pdf=result(GE_WTP_Commercial_Quote_PDF::build($quote),'pdf_generated');
 ok(strpos($pdf,'Taller QA')!==false && strpos($pdf,'GRAPHEX')===false,'pdf_brand_own');
 file_put_contents($out.'/empresa-qa-presupuesto.pdf',$pdf);
 GE_WTP_Documents::ensure_private_directory();$directory=GE_WTP_Documents::private_directory();
 ok(strpos($directory,'organizations/empresa-qa/documents')!==false,'private_storage_namespace');
 $name='artwork-'.$suffix.'.pdf';file_put_contents($directory.'/'.$name,$pdf);
 $doc=array('id'=>wp_generate_uuid4(),'name'=>'Archivo propio QA.pdf','stored_name'=>$name,'mime'=>'application/pdf','category'=>'arte','created_at'=>gmdate('c'),'organization_id'=>'empresa-qa','size'=>strlen($pdf),'uploaded_by'=>1);
 $order->update_meta_data(GE_WTP_Documents::META_KEY,array($doc));$order->save();
 wp_set_current_user($customer);ok(GE_WTP_Documents::can_access_order($order),'own_customer_can_access_file');
 $stranger=result(wp_insert_user(array('user_login'=>'qa_other_'.$suffix,'user_email'=>'otro-'.$suffix.'@empresa-qa.invalid','user_pass'=>wp_generate_password(48),'role'=>'customer')),'second_customer');
 wp_set_current_user($stranger);ok(!GE_WTP_Documents::can_access_order($order),'other_customer_file_denied');ok(is_wp_error(GE_WTP_Commercial_Quotes::get($quote['id'],$stranger)),'other_customer_quote_denied');
 wp_set_current_user(1);
 $supplier='custom-qa-'.$suffix;update_option(GE_WTP_Supplier_Dispatch::OPTION,array($supplier=>array('name'=>'Proveedor exclusivo QA','email'=>'proveedor@empresa-qa.invalid','notes'=>'Proveedor QA sin despacho automático','types'=>array('production','supplies'),'production_eligible'=>true,'supplies_provider'=>true,'auto_email'=>'no','channel'=>'manual')));
 GE_WTP_Operations::supplier_seed();ok(isset(GE_WTP_Production::suppliers()[$supplier]),'own_supplier_created');
 $stock=result(GE_WTP_Operations_Stock::item_save(array('sku'=>'QA-'.$suffix,'name'=>'Insumo propio QA','unit'=>'unit','category'=>'supplies','active'=>1)),'own_stock_item');
 $stock_id=is_array($stock)?$stock['id']:$stock;
 $move=result(GE_WTP_Operations_Stock::movement(array('item_id'=>$stock_id,'quantity'=>'10','type'=>'opening','idempotency_key'=>'qa-'.$suffix,'note'=>'Conteo ficticio de QA','unit_cost'=>'2','currency'=>'ARS')),'own_stock_balance');
 ok(GE_WTP_Operations_Stock::item_get($stock_id)['name']==='Insumo propio QA','stock_own_read');
 // Every role is exercised against its actual effective capabilities and service guards.
 $roles=array();foreach(GE_Organization::roles() as $role){
   $o=GE_Organization::get(GE_Organization::PRIMARY);$o=result(GE_Organization::save(GE_Organization::PRIMARY,1,$o['revision'],'users',array('new_login'=>'qa_'.$role.'_'.$suffix,'new_email'=>$role.'-'.$suffix.'@empresa-qa.invalid','new_name'=>'QA '.$role,'role'=>$role)),'role_user_'.$role);
   end($o['members']);$uid=(int)key($o['members']);$roles[$role]=$uid;
   wp_set_current_user($uid);
   foreach(GE_Organization::modules() as $module)foreach(array(false,true) as $write){$expected=in_array($module,GE_Organization_Runtime::permissions()[$role][$write?'write':'read'],true);ok(GE_Organization_Runtime::allowed($module,$write)===$expected,'role_'.$role.'_'.$module.'_'.($write?'write':'read'));}
 }
 wp_set_current_user($roles['read-only']);
 ok(is_wp_error(GE_WTP_Commercial_Quotes::revise($quote['id'],array($line),array(),$roles['read-only'])),'readonly_direct_quote_write_denied');
 ok(is_wp_error(GE_WTP_Customer_Branches::save($customer,array('label'=>'Denied'),$roles['read-only'])),'readonly_direct_customer_write_denied');
 $denied=false;try{GE_WTP_Operations_Stock::movement(array());}catch(Throwable $e){$denied=true;}ok($denied,'readonly_direct_stock_write_denied');
 // Server-side module checks include administrator routes, REST and direct services.
 wp_set_current_user(1);$flags=array_fill_keys(GE_Organization::modules(),true);
 foreach(GE_Organization::modules() as $module){
   $off=$flags;$off[$module]=false;setting('modules',$off);ok(!GE_Organization_Runtime::allowed($module,true,1),'module_'.$module.'_owner_denied');
   $request=new WP_REST_Request('POST','/ge/v1/'.$module.'/create');$r=GE_Organization_Runtime::guard_rest(null,null,$request);ok(is_wp_error($r),'module_'.$module.'_rest_denied');
   setting('modules',$flags);
 }
 $before_pdf=hash('sha256',$pdf);setting('general',array('brand_name'=>'Taller QA actualizado'));$oldpdf=GE_WTP_Commercial_Quote_PDF::build(GE_WTP_Commercial_Quotes::get($quote['id'],1));ok(hash('sha256',$oldpdf)===$before_pdf,'pdf_snapshot_immutable_after_settings_change');
 setting('general',array('brand_name'=>'Taller QA'));
 $email=GE_WTP_Commercial_Quotes::email_summary($quote['snapshot']);ok(strpos($email,'Taller QA')!==false && strpos($email,'Condiciones propias de QA')!==false,'email_reads_snapshot');file_put_contents($out.'/empresa-qa-email.html',$email);
 wp_set_current_user($customer);$_GET['seccion']='pedidos';$_GET['pedido']=$order->get_id();$portal=GE_WTP_Portal::render();ok(strpos($portal,'Taller QA')!==false,'portal_own_brand');ok(strpos($portal,'Servicio propio de Taller QA')!==false,'portal_own_order');file_put_contents($out.'/empresa-qa-portal.html',$portal);
 wp_set_current_user(1);$_GET=array();$export=GE_Organization::export_config(GE_Organization::PRIMARY,1);$json=wp_json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
 ok(!preg_match('/"(?:password|token|api_key|private_key|credentials_ref|cert_ref|integration_refs)"\s*:/i',$json),'export_no_secret_fields');file_put_contents($out.'/empresa-qa-config.json',$json);
 $state=array('organization_id'=>'empresa-qa','database'=>DB_NAME,'customer_id'=>$customer,'quote_id'=>$quote['id'],'order_id'=>$order->get_id(),'document_id'=>$doc['id'],'document_name'=>$name,'stock_item_id'=>$stock_id,'supplier_key'=>$supplier,'roles'=>$roles,'onboarding_ready'=>true,'email_transport'=>'simulated_no_external_delivery','fiscal_issuer'=>'synthetic_fixture_no_arca','checks'=>$checks);
 update_option('ge_qa_acceptance_fixture',$state,false);file_put_contents($out.'/tenant-acceptance-results.json',wp_json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
 echo 'OPERATIONAL_QA_PASS '.count($checks).PHP_EOL;
}catch(Throwable $e){file_put_contents($out.'/tenant-acceptance-failure.json',wp_json_encode(array('error'=>$e->getMessage(),'checks'=>$checks),JSON_PRETTY_PRINT));echo $e->getMessage().PHP_EOL;exit(1);}
