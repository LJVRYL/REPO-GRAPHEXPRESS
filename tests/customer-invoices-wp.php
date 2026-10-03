<?php
$site=getenv('GE_INVOICE_QA_SITE');
if(!$site || !is_file($site.'/wp-load.php')) throw new Exception('GE_INVOICE_QA_SITE required');
require $site.'/wp-load.php';
if (DB_NAME !== 'graphex_invoices_qa_20261003') throw new Exception('WRONG_DB');
add_filter('pre_wp_mail','__return_true',999);
WC_Install::install();
$admin=get_user_by('login','invoice_qa_admin')->ID;wp_set_current_user($admin);
GE_Organization::seed($admin);
function account($name,$role='customer'){$name.='_'.substr(wp_generate_uuid4(),0,8);$u=get_user_by('login',$name);if(!$u){$id=wp_insert_user(array('user_login'=>$name,'user_pass'=>wp_generate_password(32),'user_email'=>$name.'@example.invalid','display_name'=>$name,'role'=>$role));if(is_wp_error($id))throw new Exception($id->get_error_message());return $id;}return $u->ID;}
$customer=account('invoice_customer_one');$other=account('invoice_customer_other');$production=account('invoice_production','subscriber');$reader=account('invoice_reader','subscriber');$finance=account('invoice_finance','subscriber');
$registry=GE_Organization::all();$org=GE_Organization::PRIMARY;$registry[$org]['members'][(string)$production]='produccion';$registry[$org]['members'][(string)$reader]='read-only';$registry[$org]['members'][(string)$finance]='administracion';update_option(GE_Organization::ROOT,$registry,false);
GE_WTP_Billing::save_profile($customer,array('legal_name'=>'Synthetic Receiver One','vat_status'=>'final_consumer','billing_mode'=>'common','cuit'=>'','fiscal_address'=>'Synthetic address'),$admin,false);
update_user_meta($customer,GE_WTP_Customer_Branches::META,array(array('id'=>'test-second-profile','label'=>'Second synthetic receiver','legal_name'=>'Synthetic Receiver Two','cuit'=>'','vat_status'=>'final_consumer','active'=>true)));
$order=wc_create_order(array('customer_id'=>$customer));$order->save();
$wrong=wc_create_order(array('customer_id'=>$other));$wrong->save();
update_option('permalink_structure','/%postname%/');flush_rewrite_rules();
update_option('ge_gestion_v3_enabled','yes');
update_option(GE_WTP_Notification_Center::OPTION,array('recipients'=>'staff@example.invalid','sender_email'=>'servicio@graphex.ar','sender_name'=>'Graph Express'));
$checks=array();function ok($label,$value){global $checks;if(!$value)throw new Exception('FAIL '.$label);$checks[]=$label;}
$base=array('customer_id'=>$customer,'profile_id'=>'default','type'=>'factura','number'=>'SYNTHETIC-TEST-001','date'=>'2026-10-03','issuer_name'=>'Synthetic QA issuer','currency'=>'ARS','amount'=>'100.25','confirmed'=>1,'public_note'=>'Public clarification test','internal_note'=>'PRIVATE QA NOTE');
$row=GE_WTP_Customer_Invoices::normalize($base,$admin);ok('Staff metadata valid',!is_wp_error($row));
ok('Financial administration write allowed',GE_WTP_Customer_Invoices::staff($finance,true));ok('Production cannot read fiscal register',!GE_WTP_Customer_Invoices::staff($production));ok('Read-only cannot upload',!GE_WTP_Customer_Invoices::staff($reader,true));
ok('Customer cannot upload',is_wp_error(GE_WTP_Customer_Invoices::normalize($base,$customer)));
foreach(array('profile_id'=>'foreign-profile','date'=>'2026-02-31','amount'=>'1,000.20','type'=>'untrusted','currency'=>'BAD','confirmed'=>0,'order_id'=>$wrong->get_id()) as $key=>$value){ok('Reject invalid '.$key,is_wp_error(GE_WTP_Customer_Invoices::normalize(array_merge($base,array($key=>$value)),$admin)));}
$path=GE_WTP_Documents::private_directory().'/invoice-test-original.pdf';GE_WTP_Documents::ensure_private_directory();
file_put_contents($path,"%PDF-1.4\n% Synthetic test file. No fiscal document.\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
$file=array('name'=>'synthetic-original.pdf','tmp_name'=>$path,'error'=>0,'size'=>filesize($path));$descriptor=GE_WTP_Customer_Invoices::validate_file($file,false);ok('PDF MIME detected',!is_wp_error($descriptor));ok('CLI file rejected as upload',is_wp_error(GE_WTP_Customer_Invoices::validate_file($file)));
$bad=dirname($path).'/spoof.pdf';file_put_contents($bad,'<?php echo "bad";');ok('Spoof MIME rejected',is_wp_error(GE_WTP_Customer_Invoices::validate_file(array_merge($file,array('tmp_name'=>$bad)),false)));
$row['file']=array_merge($descriptor,array('stored_name'=>basename($path),'id'=>wp_generate_uuid4(),'category'=>'factura'));
$id=wp_insert_post(array('post_type'=>GE_WTP_Customer_Invoices::TYPE,'post_status'=>'private','post_title'=>'Synthetic QA document'));update_post_meta($id,'_ge_organization_id',$org);update_post_meta($id,GE_WTP_Customer_Invoices::META,$row);update_post_meta($id,'_ge_invoice_customer',$customer);$ref='invoice:'.$id;
wp_set_current_user($customer);$resolved=GE_WTP_Customer_Invoices::resolve($ref,$customer);ok('Own document detail',!is_wp_error($resolved));ok('Other customer denied',is_wp_error(GE_WTP_Customer_Invoices::resolve($ref,$other)));ok('Other customer list empty',!GE_WTP_Customer_Invoices::rows($other,$other));ok('Wrong customer preview denied',is_wp_error(GE_WTP_Customer_Invoices::resolve($ref,$admin,$other)));
ok('Original checksum unchanged',hash_file('sha256',GE_WTP_Customer_Invoices::file_path($resolved))===$descriptor['sha256']);ok('Duplicate original detected',GE_WTP_Customer_Invoices::duplicate($row,$descriptor['sha256'],$admin)===$ref);
ok('Required meaningful comment',is_wp_error(GE_WTP_Customer_Invoices::comment($ref,'',wp_generate_uuid4(),$customer)));
$token=wp_generate_uuid4();$case=GE_WTP_Customer_Invoices::comment($ref,'Please review this synthetic amount',$token,$customer);ok('Review received persisted',!is_wp_error($case)&&$case['status']==='recibido');ok('Notification customer recorded',isset($case['events'][0]['notice']['customer']));ok('Staff alert recorded',!empty($case['events'][0]['notice']['alert_id']));
$repeat=GE_WTP_Customer_Invoices::comment($ref,'Please review this synthetic amount',$token,$customer);ok('Retry produces one event',count($repeat['events'])===1);
$logs=get_posts(array('post_type'=>'ge_email_log','post_status'=>'private','numberposts'=>-1,'meta_key'=>'_ge_email_object_id','meta_value'=>$case['id']));ok('Exactly customer/staff emails',count($logs)===2);foreach($logs as $log){ok('Transport simulated '.get_post_meta($log->ID,'_ge_email_context',true),get_post_meta($log->ID,'_ge_email_result',true)==='simulated');}
$alert=get_post_meta($case['events'][0]['notice']['alert_id'],'_ge_alert',true);ok('Alert links correct staff customer/ref',strpos(GE_WTP_Internal_Alerts::url($alert),'customer_id='.$customer)!==false);
ok('Customer cannot staff respond',is_wp_error(GE_WTP_Customer_Invoices::comment($ref,'Responded from customer',wp_generate_uuid4(),$customer,true,'resuelto')));
wp_set_current_user($admin);$case=GE_WTP_Customer_Invoices::comment($ref,'We are reviewing the original',wp_generate_uuid4(),$admin,true,'en_revision');ok('Staff status reviewing',$case['status']==='en_revision');$case=GE_WTP_Customer_Invoices::comment($ref,'We reviewed the synthetic file',wp_generate_uuid4(),$admin,true,'respondido');ok('Staff responded',$case['status']==='respondido');$case=GE_WTP_Customer_Invoices::comment($ref,'The synthetic issue is resolved',wp_generate_uuid4(),$admin,true,'resuelto');ok('Staff resolved',$case['status']==='resuelto');
wp_set_current_user($customer);$case=GE_WTP_Customer_Invoices::comment($ref,'Another detail requires review',wp_generate_uuid4(),$customer);ok('Followup reopens received',$case['status']==='recibido'&&count($case['events'])===5);
update_post_meta($id,'_ge_organization_id','foreign-organization');ok('Other organization record denied',is_wp_error(GE_WTP_Customer_Invoices::resolve($ref,$customer)));update_post_meta($id,'_ge_organization_id',$org);
update_user_meta($customer,'_ge_organization_id','foreign-organization');ok('Other organization customer denied',is_wp_error(GE_WTP_Customer_Invoices::resolve($ref,$admin)));update_user_meta($customer,'_ge_organization_id',$org);
$legacy=$row['file'];$legacy['id']='legacy-test-uuid';$legacy['document_number']='LEGACY-QA';$legacy['issue_date']='2026-10-02';$legacy['billing_profile_snapshot']=$row['receiver'];$order->update_meta_data(GE_WTP_Documents::META_KEY,array($legacy));$order->save();ok('Legacy order original visible',count(GE_WTP_Customer_Invoices::rows($customer,$customer))===2);ok('Legacy cross customer denied',is_wp_error(GE_WTP_Customer_Invoices::resolve('order:'.$order->get_id().':legacy-test-uuid',$other)));
$_GET=array('seccion'=>'facturas','invoice'=>$ref);ob_start();GE_WTP_Customer_Invoices::render_portal();$html=ob_get_clean();ok('Public clarification visible',strpos($html,'Public clarification test')!==false);ok('Internal note hidden',strpos($html,'PRIVATE QA NOTE')===false);ok('Internal issuer hidden',strpos($html,'Synthetic QA issuer')===false);ok('History and download visible',strpos($html,'Descargar original')!==false&&strpos($html,'Another detail')!==false);
$_GET=array('seccion'=>'facturas','invoice_type'=>'nota_credito');ob_start();GE_WTP_Customer_Invoices::render_portal();$html=ob_get_clean();ok('Empty filter state',strpos($html,'No hay coincidencias')!==false);
wp_set_current_user($admin);$_GET=array('ge_preview_customer'=>$customer,'ge_preview_token'=>wp_create_nonce('ge_preview_customer_'.$customer),'invoice'=>$ref);ob_start();GE_WTP_Customer_Invoices::render_portal();$html=ob_get_clean();ok('Staff preview read only',strpos($html,'ge_invoice_review')===false);$_GET=array();

$c=array('admin'=>$admin,'customer'=>$customer,'other'=>$other,'ref'=>$ref,'order'=>$order->get_id(),'sha256'=>$descriptor['sha256']);
$original=GE_WTP_Customer_Invoices::resolve($c['ref'],$c['admin']);
$input=$original;$input['confirmed']=1;$input['replaces']=$c['ref'];
$v2=GE_WTP_Customer_Invoices::normalize($input,$c['admin']);ok('Same-document new version allowed',!is_wp_error($v2));
$v2['file']=array_merge($original['file'],array('sha256'=>str_repeat('a',64)));
$id=wp_insert_post(array('post_type'=>GE_WTP_Customer_Invoices::TYPE,'post_status'=>'private','post_title'=>'Synthetic version2'));update_post_meta($id,'_ge_organization_id',GE_Organization::PRIMARY);update_post_meta($id,GE_WTP_Customer_Invoices::META,$v2);update_post_meta($id,'_ge_invoice_customer',$c['customer']);
$v3=$input;$v3['replaces']='invoice:'.$id;$v3=GE_WTP_Customer_Invoices::normalize($v3,$c['admin']);ok('Third version chain allowed',!is_wp_error($v3)&&GE_WTP_Customer_Invoices::duplicate($v3,str_repeat('b',64),$c['admin'])==='');
ok('Cross-type version denied',is_wp_error(GE_WTP_Customer_Invoices::normalize(array_merge($input,array('type'=>'nota_credito')),$c['admin'])));
ok('Cross-profile version denied',is_wp_error(GE_WTP_Customer_Invoices::normalize(array_merge($input,array('profile_id'=>'test-second-profile')),$c['admin'])));
$order=wc_get_order($c['order']);$order->update_meta_data('_ge_billing_profile_id','test-second-profile');$order->save();$input['order_id']=$order->get_id();ok('Order receiver mismatch denied',is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));$order->delete_meta_data('_ge_billing_profile_id');$order->save();
$bad=$original;$bad['file']=array('stored_name'=>'../../wp-config.php');ok('File traversal rejected',GE_WTP_Customer_Invoices::file_path($bad)==='');
update_user_meta($c['customer'],GE_WTP_Customer_Branches::META,array());$bad=GE_WTP_Customer_Invoices::resolve('invoice:'.$id,$c['customer']);ok('Profile register owns default original',!is_wp_error($bad));
// Restore synthetic second receiver used by browser/HTTP fixtures.
update_user_meta($c['customer'],GE_WTP_Customer_Branches::META,array(array('id'=>'test-second-profile','label'=>'Second synthetic receiver','legal_name'=>'Synthetic Receiver Two','cuit'=>'','vat_status'=>'final_consumer','active'=>true)));
ok('Private original remains byte-identical',hash_file('sha256',GE_WTP_Customer_Invoices::file_path($original))===$c['sha256']);
$quote=wp_insert_post(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','post_title'=>'Synthetic linkage test'));
update_post_meta($quote,GE_WTP_Commercial_Quotes::CUSTOMER_META,$c['customer']);update_post_meta($quote,GE_WTP_Commercial_Quotes::CURRENT_META,1);update_post_meta($quote,GE_WTP_Commercial_Quotes::VERSIONS_META,array(1=>array('billing_profile_id'=>'test-second-profile')));
$input['order_id']=0;$input['quote_id']=$quote;ok('Quote receiver mismatch denied',is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));
update_post_meta($quote,GE_WTP_Commercial_Quotes::VERSIONS_META,array(1=>array('billing_profile_id'=>'default')));ok('Matching quote receiver allowed',!is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));
delete_post_meta($quote,'_ge_organization_id');ok('Legacy owned quote without org metadata allowed',!is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));
update_post_meta($quote,'_ge_organization_id','foreign-organization');ok('Quote from another organization rejected',is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));update_post_meta($quote,'_ge_organization_id',GE_Organization::PRIMARY);
update_post_meta($quote,GE_WTP_Commercial_Quotes::CUSTOMER_META,$c['other']);ok('Cross-customer quote linkage denied',is_wp_error(GE_WTP_Customer_Invoices::normalize($input,$c['admin'])));

$report=getenv('GE_INVOICE_QA_REPORT');if($report)file_put_contents($report,json_encode($checks,JSON_PRETTY_PRINT));echo count($checks)." model checks passed; synthetic data, simulated transport\n";
