<?php
define('FS_METHOD','direct');
$wpLoad=getenv('GE_WP_LOAD');
if(!$wpLoad||strpos($wpLoad,'/job-flow-qa-')===false)throw new Exception('Isolated QA bootstrap required');
$qaRoot=dirname(dirname($wpLoad));require $wpLoad;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('WRONG_DB');
$checks=[];function ck($ok,$message){global $checks;if(!$ok)throw new Exception($message);$checks[]=$message;}
function good($result,$label){ck(!is_wp_error($result),$label.(is_wp_error($result)?': '.$result->get_error_message():''));return $result;}
class FlowRedirect extends Exception {}
add_filter('wp_redirect',function($url){throw new FlowRedirect($url);});
function act($callable,$post,$actor,$nonce){wp_set_current_user($actor);$_POST=$post;$_REQUEST=$post;$_REQUEST['_wpnonce']=$_POST['_wpnonce']=wp_create_nonce($nonce);try{call_user_func($callable);}catch(FlowRedirect $r){return $r->getMessage();}throw new Exception('Expected redirect');}
ck(DISABLE_WP_CRON&&has_filter('pre_wp_mail','__return_true')!==false,'QA mail and cron intercepted');
$network=wp_remote_get('https://example.invalid/');ck(is_wp_error($network)&&$network->get_error_code()==='qa_network_disabled','External HTTP blocked');
wp_set_current_user(1);update_option('ge_customer_tax_stage_v1',3);
if(!get_option('ge_commercial_tax_rate_id')){update_option('ge_commercial_tax_rate_id',WC_Tax::_insert_tax_rate(['tax_rate_country'=>'AR','tax_rate'=>'21.0000','tax_rate_name'=>'IVA QA','tax_rate_priority'=>1,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']));}
$customer=wp_insert_user(['user_login'=>'flow-'.wp_generate_password(8,false),'user_email'=>'flow-'.wp_generate_password(8,false).'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer','display_name'=>'Cliente QA · Flujo integral']);
$other=wp_insert_user(['user_login'=>'other-'.wp_generate_password(8,false),'user_email'=>'other-'.wp_generate_password(8,false).'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer','display_name'=>'Otro cliente QA']);
$base=['legal_name'=>'RECEPTOR QA','cuit'=>'23336924529','vat_status'=>'registered','billing_mode'=>'common','billing_email'=>'qa@example.invalid','fiscal_address'=>'CALLE QA 123'];
GE_WTP_Billing::save_profile($customer,$base,1);
$issuer=GE_WTP_Billing_Issuers::normalize(['legal_name'=>'EMISOR QA','display_name'=>'EMISOR QA','cuit'=>'20948548934','fiscal_address'=>'QA 123','active'=>true,'verification_status'=>'pending','default_for_scenarios'=>['invoice_a','common']],'mardones-a');
good($issuer,'Issuer normalized');
$issuer=array_merge($issuer,['revision'=>1,'vat_status'=>'registered','verification_status'=>'verified','verified_at'=>gmdate('c'),'relationship_confirmed'=>true,'active'=>true,'common_price_policy'=>'tax_exclusive','invoice_a_price_policy'=>'tax_exclusive','tax_rate_basis_points'=>2100,'invoice_types_allowed'=>['A','B'],'point_of_sale'=>'1']);
update_option(GE_WTP_Billing_Issuers::OPTION,['mardones-a'=>$issuer]);
$uuid=wp_generate_uuid4();$input=['artwork_session'=>wp_generate_uuid4(),'billing_profile_id'=>'default','needed_by'=>'2026-11-01','items'=>[['line_uuid'=>$uuid,'product_id'=>0,'title'=>'Stickers QA','quantity'=>100,'description'=>'Uso interior','width'=>5,'height'=>5,'measure_unit'=>'cm']]];
wp_set_current_user($customer);$draft=good(GE_WTP_Quote_Requests::save($input,$customer,false),'Request draft saved');
ck($draft['status']==='draft','Request starts draft');
$request=good(GE_WTP_Quote_Requests::save($input,$customer,true),'Request submitted');
ck($request['id']===$draft['id']&&$request['status']==='new','Same request advances explicitly');
$repeat=GE_WTP_Quote_Requests::save($input,$customer,true);ck($repeat['id']===$request['id'],'Request submission idempotent');
ck(is_wp_error(GE_WTP_Quote_Requests::get($request['id'],$other)),'Other customer cannot read request');
wp_set_current_user(1);
$args=['billing_profile_id'=>'default','issuer_profile_id'=>'mardones-a','customer_tax_confirm'=>true,'issuer_change_reason'=>'QA decisión explícita','valid_until'=>'2026-11-01','expected_request_hash'=>hash('sha256',wp_json_encode($request))];
ck(is_wp_error(GE_WTP_Quote_Requests::convert($request['id'],[],$args,1)),'Missing price rejected without losing request');
ck(is_wp_error(GE_WTP_Quote_Requests::convert($request['id'],[$uuid=>12],array_merge($args,['expected_request_hash'=>'stale']),1)),'Stale request rejected under lock');
$quote=good(GE_WTP_Quote_Requests::convert($request['id'],[$uuid=>12],$args,1),'Request converted to quote draft');
ck($quote['status']==='draft'&&!$quote['converted_order_id'],'Quote preparation never starts production');
ck($quote['snapshot']['items'][0]['line_uuid']===$uuid,'Line identity retained');
ck($quote['snapshot']['receiver_snapshot']['legal_name']===$request['billing_snapshot']['legal_name'],'Receiver snapshot retained');
ck((int)get_post_meta($quote['id'],'_ge_quote_request_source',true)===$request['id'],'Origin linked');
ck(GE_WTP_Quote_Requests::convert($request['id'],[$uuid=>20],$args,1)['id']===$quote['id'],'Conversion retry uses same quote');
$sent=good(GE_WTP_Commercial_Quotes::send($quote['id'],1),'Quote sent through intercepted mail');
wp_set_current_user($customer);
ck(is_wp_error(GE_WTP_Commercial_Quotes::accept($quote['id'],$quote['version']+1,$customer)),'Stale version cannot be accepted');
good(GE_WTP_Commercial_Quotes::accept($quote['id'],$quote['version'],$customer),'Exact quote version accepted');
wp_set_current_user(1);$order=good(GE_WTP_Commercial_Checkout::convert_staff($quote['id'],['expected_version'=>$quote['version'],'confirmation_method'=>'portal'],1),'Accepted quote converted to operational order');
ck($order instanceof WC_Order,'Operational order exists');
ck(GE_WTP_Commercial_Checkout::convert_staff($quote['id'],['expected_version'=>$quote['version'],'confirmation_method'=>'portal'],1)->get_id()===$order->get_id(),'Order conversion idempotent');
ck((int)$order->get_meta('_ge_commercial_quote_version',true)===$quote['version'],'Accepted version retained in order');
ck($order->get_customer_id()===$customer,'Customer retained without reentry');
ck($order->get_meta('_ge_commercial_quote_snapshot',true)['items'][0]['line_uuid']===$uuid,'Order snapshot preserves line identity');
ck(GE_WTP_Order_Lifecycle::stage($order)==='recibido','Acceptance/payment never auto-release production');
ck(is_wp_error(GE_WTP_Job_Flow::commercial_check($order)),'Unpaid accepted order blocks production');
$total=(int)$order->get_meta('_ge_final_total_cents',true);$deposit=GE_WTP_Quote_Balance::deposit($total,5000)['deposit_cents'];
$order->update_meta_data('_ge_commercial_credited_attempts',[['key'=>'wc:payment:isolated-qa','amount_cents'=>$deposit]]);$order->update_meta_data('_ge_amount_paid_cents',$deposit);$order->update_meta_data('_ge_amount_due_cents',$total-$deposit);$order->save();
good(GE_WTP_Job_Flow::commercial_check($order),'Credited agreed deposit and commercial conditions valid');
ck(GE_WTP_Job_Flow::context('orders',$order->get_id(),$other)===null,'Cross-customer order denied');
$context=GE_WTP_Job_Flow::context('orders',$order->get_id(),1);ck($context['request']['id']===$request['id']&&$context['quote']['id']===$quote['id'],'Full chain reconstructed');
GE_WTP_Workflow::enable($order);$order->update_meta_data('_ge_production_promised_date','2026-11-01');$order->save();
$url=act(['GE_WTP_Workflow','release'],['order_id'=>$order->get_id()],1,'ge_workflow_release_'.$order->get_id());ck(strpos($url,'blocked')!==false,'Release without exact artwork blocked');
// A real private document fixture, attached to the exact item; no public uploads.
$pdf='%PDF-1.4 QA SYNTHETIC ARTWORK';ck(GE_WTP_Documents::ensure_private_directory(),'Private fixture directory exists');$path=GE_WTP_Documents::private_directory().'/job-flow-'.wp_generate_uuid4().'.pdf';file_put_contents($path,$pdf);ck(is_file($path)&&hash_file('sha256',$path)===hash('sha256',$pdf),'Fixture exists with exact private artwork hash');
$items=$order->get_items('line_item');$item=reset($items);$itemid=$item->get_id();$docid=wp_generate_uuid4();
$document=['id'=>$docid,'name'=>'Stickers-QA-v1.pdf','category'=>'arte','path'=>$path,'mime'=>'application/pdf','size'=>strlen($pdf),'order_item_id'=>$itemid,'artwork_side'=>'general','sha256'=>hash_file('sha256',$path),'analysis'=>['sha256'=>hash_file('sha256',$path)],'version'=>1,'created_at'=>gmdate('c')];
$order->update_meta_data(GE_WTP_Documents::META_KEY,[$document]);$order->save();
$row=['sources'=>['document:'.$docid],'version'=>'v1','dimensions'=>'5 x 5 cm','staff_approved'=>1,'client_required'=>1];
act(['GE_WTP_Artwork_Library','handle_artwork_control_save'],['order_id'=>$order->get_id(),'artwork_items'=>[$itemid=>$row]],1,'ge_artwork_control_'.$order->get_id());
$order=wc_get_order($order->get_id());$item=$order->get_item($itemid);ck(!GE_WTP_Artwork_Library::item_ready_for_production($item,$order),'Technical control alone cannot approve client-required artwork');
$sources=GE_WTP_Artwork_Library::order_sources($order);$fingerprint=new ReflectionMethod('GE_WTP_Artwork_Library','release_fingerprint');$fingerprint->setAccessible(true);
$expected=$item->get_meta('_ge_item_artwork_expected',true);$hash=$fingerprint->invoke(null,$item,['document:'.$docid],'v1',$expected,$sources);
act(['GE_WTP_Artwork_Library','handle_customer_artwork_approval'],['order_id'=>$order->get_id(),'approve_items'=>[$itemid],'approval_hash'=>[$itemid=>'stale']],$customer,'ge_customer_artwork_approval_'.$order->get_id());
$order=wc_get_order($order->get_id());ck(!GE_WTP_Artwork_Library::item_ready_for_production($order->get_item($itemid),$order),'Stale artwork fingerprint rejected');
act(['GE_WTP_Artwork_Library','handle_customer_artwork_approval'],['order_id'=>$order->get_id(),'approve_items'=>[$itemid],'approval_hash'=>[$itemid=>$hash]],$customer,'ge_customer_artwork_approval_'.$order->get_id());
$order=wc_get_order($order->get_id());ck(GE_WTP_Artwork_Library::item_ready_for_production($order->get_item($itemid),$order),'Client approves exact version after technical review');
wp_set_current_user(1);$original=GE_WTP_Billing::profile($customer);GE_WTP_Billing::save_profile($customer,array_merge($original,['legal_name'=>'CAMBIO QA']),1);
ck(is_wp_error(GE_WTP_Job_Flow::commercial_check($order)),'Changed receiver blocks release');
$url=act(['GE_WTP_Workflow','release'],['order_id'=>$order->get_id()],1,'ge_workflow_release_'.$order->get_id());ck(strpos($url,'commercial-blocked')!==false,'Server enforces commercial gate');
GE_WTP_Billing::save_profile($customer,$original,1);good(GE_WTP_Job_Flow::commercial_check($order),'Restored conditions pass');
$url=act(['GE_WTP_Workflow','release'],['order_id'=>$order->get_id()],1,'ge_workflow_release_'.$order->get_id());ck(strpos($url,'released')!==false,'Explicit release succeeds');
$order=wc_get_order($order->get_id());ck(GE_WTP_Order_Lifecycle::stage($order)==='produccion'&&$order->get_meta('_ge_production_status',true)==='production','Staff and customer agree on production state');
ck(GE_WTP_Order_Lifecycle::set_stage($order,'listo','QA production completed'),'Ready for delivery');
ck(GE_WTP_Order_Lifecycle::set_stage($order,'entregado','QA delivery confirmed'),'Delivered explicitly');
ck(GE_WTP_Order_Lifecycle::delivered_at_utc($order)!==null,'Delivery traced');
// A second submitted request remains available for browser validation and conversion.
wp_set_current_user($customer);$input['artwork_session']=wp_generate_uuid4();$input['items'][0]['line_uuid']=wp_generate_uuid4();$pending=good(GE_WTP_Quote_Requests::save($input,$customer,true),'Browser fixture request');
wp_set_current_user(1);$_GET=['section'=>'requests','request_id'=>$pending['id']];ob_start();GE_WTP_Staff_Portal::render();$html=ob_get_clean();ck(strpos($html,'data-job-card')!==false&&strpos($html,'Guardar avance')!==false,'Actual staff renderer includes sequential preparation');
if(class_exists('GE_Organization_Runtime')){ck(GE_Organization_Runtime::action_policy('ge_flow_quote_request_staff')[0]==='quotes','AJAX endpoint routed through quote role policy');}
$branch=good(GE_WTP_Customer_Branches::save($customer,array_merge($base,['label'=>'Sucursal QA','legal_name'=>'RECEPTOR ALTERNATIVO QA']),1),'Second receiver profile');
wp_set_current_user($customer);$input['artwork_session']=wp_generate_uuid4();$input['items'][0]['line_uuid']=wp_generate_uuid4();$another=good(GE_WTP_Quote_Requests::save($input,$customer,true),'Multi-profile request fixture');
wp_set_current_user(1);$selected=good(GE_WTP_Quote_Requests::convert($another['id'],[$input['items'][0]['line_uuid']=>8],array_merge($args,['expected_request_hash'=>hash('sha256',wp_json_encode($another)),'billing_profile_id'=>$branch['id']]),1),'Explicit receiver selection during preparation');
ck($selected['snapshot']['receiver_snapshot']['legal_name']==='RECEPTOR ALTERNATIVO QA','Selected receiver used rather than silently overridden');
ck(GE_WTP_Quote_Requests::get($another['id'],1)['billing_snapshot']['legal_name']===$another['billing_snapshot']['legal_name'],'Original request receiver remains in provenance');
$foreign=good(GE_WTP_Commercial_Quotes::create_draft($other,[],[],1),'Foreign draft fixture');$tampered=$pending;$tampered['quote_id']=$foreign['id'];unset($tampered['id']);update_post_meta($pending['id'],GE_WTP_Quote_Requests::META,$tampered);
ck(GE_WTP_Job_Flow::context('requests',$pending['id'],1)['quote']===null,'Mismatched client link suppressed even for staff');$untampered=$pending;unset($untampered['id']);update_post_meta($pending['id'],GE_WTP_Quote_Requests::META,$untampered);
wp_set_current_user($customer);$_GET=[];ob_start();GE_WTP_Job_Flow::customer_list();$list=ob_get_clean();ck(strpos($list,'Mis solicitudes')===false&&strpos($list,'Solicitar presupuesto')!==false,'Unified customer entry');ck(strpos($list,'Ver detalle')!==false&&strpos($list,'ge-quote-convert')===false,'Closed proposal list');
file_put_contents($qaRoot.'/context.json',json_encode(['customer'=>$customer,'other'=>$other,'request'=>$request['id'],'quote'=>$quote['id'],'order'=>$order->get_id(),'pending'=>$pending['id']]));
file_put_contents($qaRoot.'/qa-results.json',json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'PASS '.count($checks)." integral WordPress checks\n";
