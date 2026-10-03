<?php
$_SERVER['HTTP_HOST']='localhost:18820';$_SERVER['SERVER_NAME']='localhost';
require __DIR__.'/qa/site/wp-load.php';
if(strpos(DB_NAME,'graphex_crm_v1_qa_')!==0)throw new Exception('Wrong isolated database');
wp_set_current_user(1);$checks=array();
function check($name,$v){global $checks;$checks[]=array('name'=>$name,'passed'=>(bool)$v);if(!$v)throw new Exception('FAIL '.$name);}
function denied($name,$fn,$code=0){try{$fn();check($name,false);}catch(RuntimeException $e){check($name,!$code||$e->getCode()===$code);}}
function customer($name,$email,$org='graph-express'){$id=wp_insert_user(array('user_login'=>'qa_'.wp_generate_uuid4(),'user_email'=>$email,'display_name'=>$name,'user_pass'=>wp_generate_password(40),'role'=>'customer'));if(is_wp_error($id))throw new Exception($id->get_error_message());update_user_meta($id,'_ge_organization_id',$org);return $id;}
check('schema installed',get_option('ge_crm_schema_version')===1 || (int)get_option('ge_crm_schema_version')===1);
check('owner access',GE_CRM::can(true));check('anonymous denied',!GE_CRM::can(false,0));
$tag=substr(wp_generate_uuid4(),0,8);
$lead=GE_CRM::save('lead',array('title'=>'Imprenta Aurora '.$tag,'company'=>'Aurora QA','email'=>'aurora-'.$tag.'@example.test','phone'=>'+54 11 5555 0101','source'=>'manual','tags'=>'stickers, recurrente'));
check('manual lead scoped',$lead['organization_id']==='graph-express'&&$lead['status']==='new');check('phone normalized',$lead['phone']==='541155550101');
$op=GE_CRM::save('opportunity',array('title'=>'Stickers · campaña Aurora '.$tag,'lead_id'=>$lead['id'],'estimated_value'=>'125000.50','next_action'=>'Confirmar medida y tirada','stage'=>'new'));
check('lead to opportunity',$op['lead_id']===$lead['id']);
$before=(int)get_option('ge_crm_qa_mail_attempts',0);$converted=GE_CRM::convert($lead['id'],$lead['revision']);$cid=$converted['customer_id'];
check('customer canonical user',$cid&&in_array('customer',get_userdata($cid)->roles,true));check('convert updates related opportunity',GE_CRM::get($op['id'])['customer_id']===$cid);check('convert no external invitation',(int)get_option('ge_crm_qa_mail_attempts',0)===$before);
check('repeat conversion idempotent',GE_CRM::convert($lead['id'],$lead['revision'])['customer_id']===$cid);
$dup=GE_CRM::save('lead',array('title'=>'Aurora contacto duplicado','email'=>get_userdata($cid)->user_email));
check('email match found',GE_CRM::match($dup)[0]['id']===$cid);
denied('match requires review',function()use($dup){GE_CRM::convert($dup['id'],$dup['revision']);},409);
check('review reuses customer',GE_CRM::convert($dup['id'],$dup['revision'],$cid)['customer_id']===$cid);
check('phone match',GE_CRM::match(array('phone'=>'+54 (11) 5555-0101'))[0]['id']===$cid);
update_user_meta($cid,'billing_cuit','20-12345678-6');check('CUIT match',GE_CRM::match(array('cuit'=>'20123456786'))[0]['id']===$cid);
$other=customer('Empresa B','other-'.$tag.'@example.test','tenant-b');check('foreign contact not matched',!GE_CRM::match(array('email'=>get_userdata($other)->user_email)));
denied('foreign customer denied',function()use($other){GE_CRM::save('opportunity',array('title'=>'Bad link','customer_id'=>$other));},422);
denied('owner membership required',function(){GE_CRM::save('lead',array('title'=>'Bad owner','owner_id'=>999999));},422);
denied('invalid email',function(){GE_CRM::save('lead',array('title'=>'Bad mail','email'=>'not-email'));},422);
denied('invalid date',function()use($cid){GE_CRM::save('task',array('title'=>'Bad date','customer_id'=>$cid,'due_date'=>'2026-02-30'));},422);
denied('invalid money',function()use($cid){GE_CRM::save('opportunity',array('title'=>'Bad value','customer_id'=>$cid,'estimated_value'=>'-1'));},422);
denied('invalid stage',function()use($cid){GE_CRM::save('opportunity',array('title'=>'Bad stage','customer_id'=>$cid,'stage'=>'not-a-stage'));},422);
denied('unscoped supplier rejected',function()use($cid){GE_CRM::save('task',array('title'=>'Bad supplier','customer_id'=>$cid,'supplier_id'=>1));},422);
$op=GE_CRM::get($op['id']);$op=GE_CRM::save('opportunity',array('revision'=>$op['revision'],'stage'=>'qualified'),$op['id']);
check('stage persisted',$op['stage']==='qualified');denied('stale revision rejected',function()use($op){GE_CRM::save('opportunity',array('revision'=>$op['revision']-1,'stage'=>'sent'),$op['id']);},409);
$events=GE_CRM::timeline(0,$op['id']);check('stage audit before after',$events[0]['details']['before']['stage']==='new'&&$events[0]['details']['after']['stage']==='qualified');
$task=GE_CRM::save('task',array('title'=>'Llamar a Aurora','opportunity_id'=>$op['id'],'due_date'=>wp_date('Y-m-d'),'priority'=>'high'));
check('task inherits customer',$task['customer_id']===$cid);$task=GE_CRM::save('task',array('revision'=>$task['revision'],'status'=>'done'),$task['id']);check('task completion',$task['status']==='done');
GE_CRM::note(array('record_id'=>$op['id'],'type'=>'call','notes'=>'Confirmamos 500 stickers de 8 cm.'));check('call timeline',GE_CRM::timeline(0,$op['id'])[0]['event_type']==='call');
global $wpdb;$foreign=array('organization_id'=>'tenant-b','kind'=>'lead','title'=>'PRIVATE B','payload'=>'{}','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'));$wpdb->insert(GE_CRM::table(),$foreign);$foreignid=(int)$wpdb->insert_id;
denied('cross tenant record get',function()use($foreignid){GE_CRM::get($foreignid);},404);check('cross tenant search empty',!GE_CRM::records('',0,'PRIVATE B'));
denied('cross tenant timeline customer',function()use($other){GE_CRM::timeline($other);},404);
// Real canonical quote -> staff conversion in isolated QA, no CRM copy of quote/order data.
$issuer=GE_WTP_Billing_Issuers::save('qa-c',array('legal_name'=>'EMISOR SIMULADO QA','display_name'=>'QA C','cuit'=>'20123456786','active'=>true,'vat_status'=>'monotributo','relationship_confirmed'=>true,'verification_status'=>'verified','fiscal_address'=>'Domicilio ficticio QA','point_of_sale'=>'1','invoice_types_allowed'=>array('C'),'default_for_scenarios'=>array('common'),'common_price_policy'=>'tax_exclusive','invoice_a_price_policy'=>'tax_exclusive','tax_rate_basis_points'=>0),1,'Fixture QA sintético',0);
check('synthetic issuer',!is_wp_error($issuer));
$quote=GE_WTP_Commercial_Quotes::create_draft($cid,array(array('source_type'=>'custom','name'=>'500 stickers campaña QA','quantity'=>'500','unit'=>'u','unit_net'=>'250','line_uuid'=>wp_generate_uuid4())),array('billing_profile_id'=>'default','issuer_profile_id'=>'qa-c','source'=>'qa'),1);
if(is_wp_error($quote))throw new Exception('quote fixture '.$quote->get_error_message());check('real quote created',$quote['id']>0);
$op=GE_CRM::get($op['id']);$op=GE_CRM::save('opportunity',array('revision'=>$op['revision'],'quote_id'=>$quote['id'],'stage'=>'sent'),$op['id']);check('quote linked canonical',$op['quote_id']===$quote['id']);
$foreignquote=wp_insert_post(array('post_type'=>'ge_commercial_quote','post_status'=>'private','post_title'=>'Quote B'));update_post_meta($foreignquote,'_ge_organization_id','tenant-b');update_post_meta($foreignquote,'_ge_commercial_customer_id',$other);
denied('foreign quote denied',function()use($op,$foreignquote){GE_CRM::save('opportunity',array('revision'=>$op['revision'],'quote_id'=>$foreignquote),$op['id']);},422);
$order=GE_WTP_Commercial_Checkout::convert_staff($quote['id'],array('confirmation_method'=>'staff','reason'=>'POC en fixture QA sin pago','payment_state'=>'unregistered','expected_version'=>$quote['version']),1);
if(is_wp_error($order))throw new Exception('conversion '.$order->get_error_message());check('real canonical quote order conversion',$order instanceof WC_Order&&$order->get_customer_id()===$cid);
$op=GE_CRM::get($op['id']);check('conversion automatically wins opportunity',$op['stage']==='won'&&$op['order_id']===$order->get_id());
check('canonical quote order relation',(int)get_post_meta($quote['id'],'_ge_commercial_order_id',true)===$order->get_id());
$req=wp_insert_post(array('post_type'=>'ge_quote_request','post_status'=>'private','post_title'=>'Solicitud QA'));update_post_meta($req,'_ge_organization_id','graph-express');
update_post_meta($req,'_ge_quote_request',array('customer_id'=>$cid,'status'=>'new','items'=>array(),'notes'=>'Fixture solicitud CRM'));
$requestleads=array_filter(GE_CRM::records('lead'),function($l)use($req){return ($l['quote_request_id']??0)===$req;});check('request meta observer creates lead',count($requestleads)===1);
GE_CRM::request_created($req);check('request ingestion idempotent',count(array_filter(GE_CRM::records('lead'),function($l)use($req){return ($l['quote_request_id']??0)===$req;}))===1);
check('request creates internal task',count(array_filter(GE_CRM::records('task'),function($l)use($req){return ($l['quote_request_id']??0)===$req;}))===1);
$thread=GE_CRM::save('thread',array('title'=>'Consulta WhatsApp QA','customer_id'=>$cid,'channel'=>'whatsapp','intent'=>'hola','notes'=>'Mensaje entrante simulado'));
check('quick reply suggestion',$thread['suggested_reply']&&$thread['delivery']==='not_sent');check('manual approval required',$thread['approval']==='manual_required');
$thread=GE_CRM::save('thread',array('revision'=>$thread['revision'],'status'=>'approved_pending_send'),$thread['id']);check('review never sends',$thread['approval']==='staff_reviewed'&&$thread['delivery']==='not_sent');
denied('unresolved placeholders block approval',function()use($cid){GE_CRM::save('thread',array('title'=>'Variables QA','customer_id'=>$cid,'channel'=>'whatsapp','intent'=>'datos','status'=>'approved_pending_send'));},422);
$cfg=GE_CRM::config();$cfg['stages']['proof']='Prueba comercial';GE_CRM::configure($cfg);check('configurable stage',isset(GE_CRM::config()['stages']['proof']));
$bad=$cfg;unset($bad['stages']['won']);denied('terminal stage cannot disappear',function()use($bad){GE_CRM::configure($bad);},422);
$csv="title,email,phone\nCSV QA,import-".$tag."@example.test,541100000000\n";$preview=GE_CRM::import_csv($csv);check('CSV preview',$preview[0]['data']['title']==='CSV QA');$import=GE_CRM::import_csv($csv,true);$again=GE_CRM::import_csv($csv,true);check('CSV import idempotent',$again[0]['id']===$import[0]['id']);
GE_CRM::save('lead',array('title'=>'=HYPERLINK("example")','email'=>'formula-'.$tag.'@example.test'));$export=GE_CRM::export_csv('lead');check('CSV formula neutralized',strpos($export,"'=HYPERLINK")!==false);check('export no secret fields',strpos($export,'user_pass')===false&&strpos($export,'token')===false);check('export excludes foreign data',strpos($export,'PRIVATE B')===false);
denied('CSV invalid header',function(){GE_CRM::import_csv("title,password\nX,secret\n");},422);
// Roles, module flag, admin nonmember; server-side checks do not trust WordPress capability alone.
$ro=wp_insert_user(array('user_login'=>'ro_'.$tag,'user_email'=>'ro-'.$tag.'@example.test','user_pass'=>wp_generate_password(40),'role'=>'subscriber'));$prod=wp_insert_user(array('user_login'=>'prod_'.$tag,'user_email'=>'prod-'.$tag.'@example.test','user_pass'=>wp_generate_password(40),'role'=>'subscriber'));$admin=wp_insert_user(array('user_login'=>'external_admin_'.$tag,'user_email'=>'admin-'.$tag.'@example.test','user_pass'=>wp_generate_password(40),'role'=>'administrator'));
$all=GE_Organization::all();$all['graph-express']['members'][(string)$ro]='read-only';$all['graph-express']['members'][(string)$prod]='produccion';update_option(GE_Organization::ROOT,$all,false);
check('read only can view',GE_CRM::can(false,$ro));check('read only cannot write',!GE_CRM::can(true,$ro));check('production commercial data restricted',!GE_CRM::can(false,$prod));check('admin outside org denied',!GE_CRM::can(false,$admin));
wp_set_current_user($ro);denied('read only save blocked',function(){GE_CRM::save('lead',array('title'=>'Blocked'));},403);wp_set_current_user(1);
$all['graph-express']['settings']['modules']['crm']=false;update_option(GE_Organization::ROOT,$all,false);check('module flag enforced',!GE_CRM::can());$all['graph-express']['settings']['modules']['crm']=true;update_option(GE_Organization::ROOT,$all,false);
// Followup is internal and source-key idempotent.
$follow=GE_CRM::save('opportunity',array('title'=>'Seguimiento pendiente QA','customer_id'=>$cid,'quote_id'=>$quote['id'],'stage'=>'sent'));
remove_action('updated_post_meta',array('GE_CRM','quote_meta'),30);update_post_meta($quote['id'],'_ge_commercial_status','sent');update_post_meta($quote['id'],'_ge_commercial_order_id',0);add_action('updated_post_meta',array('GE_CRM','quote_meta'),30,4);
$wpdb->update(GE_CRM::table(),array('updated_at'=>gmdate('Y-m-d H:i:s',time()-20*DAY_IN_SECONDS)),array('id'=>$follow['id']));GE_CRM::scheduled();$n=count(GE_CRM::records('task'));GE_CRM::scheduled();check('automations idempotent',count(GE_CRM::records('task'))===$n);check('followup tasks created',$n>=4);
// Restore the canonical converted relationship after automation fixture.
update_post_meta($quote['id'],'_ge_commercial_order_id',$order->get_id());update_post_meta($quote['id'],'_ge_commercial_status','converted');
$timeline=GE_CRM::timeline($cid);check('customer360 has quote',in_array('quote',array_column($timeline,'event_type'),true));check('customer360 has order',in_array('order',array_column($timeline,'event_type'),true));
$_GET=array('section'=>'crm','view'=>'pipeline');ob_start();GE_CRM_UI::render();$html=ob_get_clean();check('pipeline rendered',strpos($html,'ge-crm-board')!==false);check('keyboard move alternative',strpos($html,'Mover a')!==false);check('HTML escaped',strpos($html,'<script>alert')===false);
ob_start();GE_CRM_UI::customer360($cid);$html=ob_get_clean();check('customer360 extension rendered',strpos($html,'360°')!==false);
do_action('rest_api_init');wp_set_current_user(0);$request=new WP_REST_Request('GET','/graphex-crm/v1/records');$response=rest_get_server()->dispatch($request);check('REST anonymous denied',$response->get_status()===403);
wp_set_current_user($ro);$request=new WP_REST_Request('POST','/graphex-crm/v1/command');$request->set_body_params(array('operation'=>'save','kind'=>'lead','title'=>'No'));$response=rest_get_server()->dispatch($request);check('REST readonly write denied',$response->get_status()===403);wp_set_current_user(1);
$out=array('checks'=>$checks,'passed'=>count($checks),'failed'=>0,'database'=>DB_NAME,'fixtures'=>array('customer_id'=>$cid,'lead_id'=>$lead['id'],'opportunity_id'=>$op['id'],'quote_id'=>$quote['id'],'order_id'=>$order->get_id(),'thread_id'=>$thread['id']));file_put_contents(__DIR__.'/../outputs/qa-backend.json',wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));file_put_contents(__DIR__.'/qa/fixtures.json',wp_json_encode($out['fixtures']));echo 'PASS '.count($checks).' backend checks'.PHP_EOL;
