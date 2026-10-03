<?php
$_SERVER['HTTP_HOST']='localhost:19050';require '/home/leo/ge-organizations/empresa-qa/site/wp-load.php';if(DB_NAME!=='ge_org_empresa_qa')exit('WRONG_QA');
$out=dirname(__DIR__).'/outputs';$f=get_option('ge_qa_acceptance_fixture');$checks=array();
function chk($x,$n){global $checks;$checks[$n]=(bool)$x;if(!$x)throw new Exception('FAIL '.$n);}
function denied($fn){try{$fn();return false;}catch(Throwable $e){return (int)$e->getCode()===403;}}
add_filter('wp_die_handler',function(){return function($message,$title='',$args=array()){throw new RuntimeException(wp_strip_all_tags($message),(int)($args['response']??403));};});
try {
 foreach(array('comercial'=>array('quotes'=>true,'production'=>false,'finance'=>false),'produccion'=>array('quotes'=>false,'production'=>true,'finance'=>false),'administracion'=>array('quotes'=>false,'production'=>false,'finance'=>true),'read-only'=>array('quotes'=>false,'production'=>false,'finance'=>false)) as $role=>$permissions){
  wp_set_current_user($f['roles'][$role]);foreach($permissions as $module=>$expected)chk(GE_Organization_Runtime::allowed($module,true)===$expected,'independent_policy_'.$role.'_'.$module);
 }
 $readonly=new WP_User($f['roles']['read-only']);$readonly->add_role('administrator');wp_set_current_user($readonly->ID);
 foreach(array('manage_options','manage_woocommerce','edit_users','edit_posts','delete_posts','upload_files') as $cap)chk(!current_user_can($cap),'readonly_inherited_'.$cap.'_denied');
 $_REQUEST=array('action'=>'ge_staff_save_customer');chk(denied(function(){GE_Organization_Runtime::guard_action();}),'readonly_customer_http_action_denied');
 $_REQUEST=array('action'=>'ge_manual_order_create');chk(denied(function(){GE_Organization_Runtime::guard_action();}),'readonly_order_http_action_denied');
 $_REQUEST=array('action'=>'ge_production_save');chk(denied(function(){GE_Organization_Runtime::guard_action();}),'readonly_production_http_action_denied');
 $_REQUEST=array('organization_id'=>'graph-express','section'=>'orders');chk(denied(function(){GE_Organization_Runtime::verify_binding();}),'foreign_organization_query_denied');
 $_REQUEST=array();$_SERVER['HTTP_X_GE_ORGANIZATION']='graph-express';chk(denied(function(){GE_Organization_Runtime::verify_binding();}),'foreign_organization_header_denied');unset($_SERVER['HTTP_X_GE_ORGANIZATION']);
 $readonly->remove_role('administrator');wp_set_current_user(1);
 $actions=array('customers'=>'ge_staff_save_customer','quotes'=>'ge_commercial_quote_pdf','orders'=>'ge_manual_order_create','production'=>'ge_production_save','suppliers'=>'ge_supplier_add','stock'=>'ge_operations','finance'=>'ge_operations','cost_engine'=>'ge_cost_save_product','communications'=>'ge_newsletter_save_campaign','file_analyzer'=>'ge_ai_artwork','ecommerce'=>'woocommerce_save_variations');
 $flags=array_fill_keys(GE_Organization::modules(),true);
 foreach($actions as $module=>$action){$off=$flags;$off[$module]=false;$o=GE_Organization::get(GE_Organization::PRIMARY);$r=GE_Organization::save(GE_Organization::PRIMARY,1,$o['revision'],'modules',$off);chk(!is_wp_error($r),'disable_'.$module);
  $_REQUEST=array('action'=>$action,'op'=>$module==='stock'?'graph.stock.item.save':'graph.finance.expense.create');$_POST=$_REQUEST;
  chk(denied(function(){GE_Organization_Runtime::guard_action();}),'disabled_'.$module.'_admin_ajax_action_denied');
  $_REQUEST=$_POST=array();$o=GE_Organization::get(GE_Organization::PRIMARY);GE_Organization::save(GE_Organization::PRIMARY,1,$o['revision'],'modules',$flags);
 }
 // Build the endpoint inventory from actual registered callbacks, including authenticated and anonymous routes.
 wp_set_current_user($f['roles']['produccion']);chk(!GE_WTP_Commercial_Quotes::can_access($f['quote_id'],get_current_user_id()),'production_cannot_read_quote_service');
 foreach(array('owner','admin','read-only') as $role){wp_set_current_user($f['roles'][$role]);chk(!current_user_can('install_plugins')&&!current_user_can('edit_plugins')&&!current_user_can('unfiltered_upload'),'role_cannot_execute_platform_code_'.$role);}
 wp_set_current_user(1);
 $unassigned=wp_insert_user(array('user_login'=>'qa_native_admin_'.substr(wp_generate_uuid4(),0,8),'user_email'=>'native-admin-'.substr(wp_generate_uuid4(),0,8).'@empresa-qa.invalid','user_pass'=>wp_generate_password(48),'role'=>'administrator'));chk(!is_wp_error($unassigned),'native_admin_fixture_created');wp_set_current_user($unassigned);chk(!current_user_can('install_plugins')&&!current_user_can('edit_plugins')&&!current_user_can('promote_users'),'unassigned_wp_admin_cannot_bypass_platform_guard');wp_set_current_user(1);chk(!current_user_can('promote_users'),'owner_cannot_promote_native_wp_roles');
 $query_before=$GLOBALS['wp_query']??null;$GLOBALS['wp_query']=new WP_Query(array('pagename'=>'gestion'));$_GET['section']='crm';wp_set_current_user($f['roles']['produccion']);chk(denied(function(){GE_Organization_Runtime::guard_extension_page();}),'crm_extension_cannot_bypass_role_guard');wp_set_current_user(1);$root_before=get_option(GE_Organization::ROOT);$root_off=$root_before;$root_off['empresa-qa']['settings']['modules']['communications']=false;update_option(GE_Organization::ROOT,$root_off,false);chk(denied(function(){GE_Organization_Runtime::guard_extension_page();}),'crm_extension_cannot_bypass_module_guard');update_option(GE_Organization::ROOT,$root_before,false);$GLOBALS['wp_query']=$query_before;$_GET=array();
 wp_set_current_user($f['roles']['read-only']);$request=new WP_REST_Request('POST','/ge/v1/operations/graph.stock.item.find');chk(!is_wp_error(GE_Organization_Runtime::guard_rest(null,null,$request)),'readonly_post_stock_query_allowed');$request=new WP_REST_Request('POST','/ge/v1/operations/graph.stock.item.save');chk(is_wp_error(GE_Organization_Runtime::guard_rest(null,null,$request)),'readonly_post_stock_mutation_denied');wp_set_current_user(1);
 $export=GE_Organization_Export::build(1);chk(!is_wp_error($export)&&$export['manifest']['organization_id']==='empresa-qa','operational_export_own_organization');$encoded=wp_json_encode($export);chk(!preg_match('/"(?:password|user_pass|token|api_key|private_key|credentials_ref|cert_ref|order_key)"\s*:/i',$encoded),'operational_export_secret_scan');chk(count($export['domains']['orders'])>0&&count($export['domains']['customers'])>0,'operational_export_includes_flow');file_put_contents($out.'/empresa-qa-data.json',wp_json_encode($export,JSON_PRETTY_PRINT));wp_set_current_user($f['roles']['read-only']);chk(is_wp_error(GE_Organization_Export::build(get_current_user_id())),'readonly_export_data_denied');wp_set_current_user(1);
 chk(GE_WTP_Catalog::products()===array(),'qa_has_no_graph_legacy_products');chk(is_wp_error(GE_WTP_Billing_Issuers::seed(1)),'qa_cannot_seed_graph_issuers');
 $order_pdf=GE_WTP_Quotes::build_organization_order_pdf(wc_get_order($f['order_id']));chk(!is_wp_error($order_pdf)&&strpos($order_pdf,'Taller QA')!==false&&strpos($order_pdf,'/Subtype /Image')!==false,'portal_order_pdf_uses_frozen_organization');file_put_contents($out.'/empresa-qa-pedido.pdf',$order_pdf);
 global $wp_filter;$inventory=array();foreach($wp_filter as $hook=>$callbacks)if(preg_match('/^(?:admin_post_(?:nopriv_)?|wp_ajax_(?:nopriv_)?)(ge_[a-z0-9_]+)$/',$hook,$m)) {
  $action=$m[1];$_REQUEST=array();$_POST=array();list($module,$write)=GE_Organization_Runtime::action_policy($action);$inventory[$hook]=array('module'=>$module?:'denied-unclassified','write'=>$write,'organization_source'=>'instance binding, never request');
 }
 file_put_contents($out.'/endpoint-scope-matrix.json',wp_json_encode($inventory,JSON_PRETTY_PRINT));chk(count($inventory)>70,'actual_endpoint_inventory');
 $pdf=GE_WTP_Commercial_Quote_PDF::build(GE_WTP_Commercial_Quotes::get($f['quote_id'],1));chk(strpos($pdf,'/Subtype /Image')!==false,'pdf_embeds_own_logo');
 $hash=hash('sha256',$pdf);$q=GE_WTP_Commercial_Quotes::get($f['quote_id'],1);chk($q['snapshot']['organization_snapshot']['branding']['primary_color']==='#087f8c','pdf_accent_snapshot');
 file_put_contents($out.'/tenant-hardening-results.json',wp_json_encode(array('checks'=>$checks,'endpoint_count'=>count($inventory),'pdf_hash'=>$hash),JSON_PRETTY_PRINT));echo 'HARDENING_QA_PASS '.count($checks).PHP_EOL;
}catch(Throwable $e){file_put_contents($out.'/tenant-hardening-failure.json',wp_json_encode(array('error'=>$e->getMessage(),'checks'=>$checks),JSON_PRETTY_PRINT));echo $e->getMessage().PHP_EOL;exit(1);}
