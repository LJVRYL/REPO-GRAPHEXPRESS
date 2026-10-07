<?php
define('FS_METHOD','direct');
$load=getenv('GE_WP_LOAD');if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('Isolated QA required');require $load;define('DOING_AJAX', true);
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong database');
$qa=dirname(dirname($load));$context=json_decode(file_get_contents($qa.'/context.json'),true);
$checks=0;function et_check($ok,$label){global $checks;if(!$ok)throw new Exception($label);$checks++;}
class EmailPreviewExit extends Exception {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new EmailPreviewExit();};});
function et_preview($method,$data){$_POST=$data;$_REQUEST=$data;$_POST['nonce']=$_REQUEST['nonce']=wp_create_nonce('ge_email_preview');ob_start();try{call_user_func(array('GE_WTP_Email_Templates',$method));}catch(EmailPreviewExit $e){}$raw=ob_get_clean();$response=json_decode($raw,true);if(!is_array($response))throw new Exception('Preview JSON invalid: '.substr($raw,0,200));return $response;}
$option='ge_email_templates_v1_'.GE_Organization::PRIMARY;$before=get_option($option,null);$mail=array();
add_filter('pre_wp_mail',function($value,$atts)use(&$mail){$mail[]=$atts;return true;},1,2);
wp_set_current_user(1);
try {
 et_check(GE_WTP_Email_Templates::permitted(true),'Admin permitted');
 et_check(GE_Organization_Runtime::action_policy('ge_communication_template_save')===array('communications',true),'Save action routed to communication write policy');
 et_check(GE_Organization_Runtime::action_policy('ge_communication_template_preview')===array('communications',false),'Template preview routed to communication read policy');
 et_check(GE_Organization_Runtime::action_policy('ge_email_quote_preview')===array('quotes',false),'Quote preview routed to quote read policy');
 $all=GE_WTP_Email_Templates::catalog();et_check(count($all)>=35,'Inventory includes legacy and WooCommerce');
 et_check(isset($all['workflow_supplier_portal'])&&!$all['workflow_supplier_portal']['editable'],'Legacy clearly unconnected');
 $defaults=GE_WTP_Email_Templates::defaults()['commercial_quote_sent'];
 et_check(is_wp_error(GE_WTP_Email_Templates::validate('commercial_quote_sent','Title','Subject','<p>{{password}}</p>')),'Unknown variables rejected');
 et_check(is_wp_error(GE_WTP_Email_Templates::validate('commercial_quote_sent','Title','Subject','<p>No portal</p>')),'Required portal retained');
 $safe=GE_WTP_Email_Templates::validate('commercial_quote_sent','Title','Subject','<p onclick="alert(1)">Hola {{customer_name}}</p><img src="https://example.invalid/tracker"><a href="javascript:alert(1)">Bad</a><a href="{{portal_url}}">Portal</a>');
 et_check(!is_wp_error($safe)&&strpos($safe['body'],'onclick')===false&&strpos($safe['body'],'javascript:')===false&&strpos($safe['body'],'<img')===false,'HTML events protocols and trackers stripped');
 $record=GE_WTP_Email_Templates::get('commercial_quote_sent');
 $saved=GE_WTP_Email_Templates::save_record('commercial_quote_sent',array('title'=>'Presupuesto QA','subject'=>'Presupuesto QA {{quote_number}}','body'=>'<p>Hola {{customer_name}}</p><a href="{{portal_url}}">Portal</a>{{summary}}{{first_access}}'),$record['revision']);
 et_check(!is_wp_error($saved)&&count($saved['history'])>0,'Save creates audited revision');
 et_check(is_wp_error(GE_WTP_Email_Templates::save_record('commercial_quote_sent',$defaults,$record['revision'])),'Stale edit rejected');
 $message=GE_WTP_Email_Templates::render('commercial_quote_sent',array('customer_name'=>'<img src=x onerror=alert(1)>','portal_url'=>'https://example.invalid/portal','quote_number'=>'10001','summary'=>'<p>Total: ARS 121</p>'));
 et_check(strpos($message['body'],'<img')===false&&strpos($message['body'],'&lt;img')!==false,'Customer variables escaped');
 $manual=GE_WTP_Email_Templates::save_record('manual_qa',array('title'=>'Manual QA','subject'=>'Hola {{customer_name}}','body'=>'<p>Hola {{customer_name}}</p>'),0,'create');
 et_check(!is_wp_error($manual)&&$manual['trigger']==='Sin envío automático'&&$manual['revision']===1,'Manual template inert and versioned');
 $preview=et_preview('preview_template',array('template_id'=>'commercial_quote_sent'));
 et_check($preview['success']&&$preview['data']['recipient']==='cliente@example.invalid','Synthetic template preview');
 et_check(count($mail)===0,'Preview sends no mail');
 $quote=GE_WTP_Commercial_Quotes::get($context['quote'],1);et_check(!is_wp_error($quote),'Fixture quote available');
 $status=$quote['status'];$snapshot_hash=hash('sha256',wp_json_encode($quote['snapshot']));
 $preview=et_preview('preview_quote',array('quote_id'=>$quote['id']));
 et_check($preview['success']&&strpos($preview['data']['attachment'],'PDF comercial')!==false,'Real quote preview includes PDF metadata');
 et_check(strpos($preview['data']['body'],'Adjuntamos el PDF')!==false,'Attachment notice matches sender');
 $after=GE_WTP_Commercial_Quotes::get($quote['id'],1);et_check($after['status']===$status&&hash('sha256',wp_json_encode($after['snapshot']))===$snapshot_hash,'Preview preserves quote state and content');
 $post_org=get_post_meta($quote['id'],'_ge_organization_id',true);update_post_meta($quote['id'],'_ge_organization_id','another-organization');
 try { $blocked=et_preview('preview_quote',array('quote_id'=>$quote['id']));et_check(!$blocked['success'],'Cross-organization preview denied'); } finally { update_post_meta($quote['id'],'_ge_organization_id',$post_org); }
 wp_set_current_user($context['customer']);et_check(!GE_WTP_Email_Templates::permitted(true),'Customer cannot edit templates');
 et_check(is_wp_error(GE_WTP_Email_Templates::save_record('commercial_quote_sent',$defaults,$saved['revision'])),'Customer save denied');
 $blocked=et_preview('preview_quote',array('quote_id'=>$quote['id']));et_check(!$blocked['success'],'Customer staff preview denied');
 wp_set_current_user(1);
 $customer=get_userdata($context['customer']);
 $data=array('form_preview'=>1,'customer_email'=>$customer->user_email,'customer_name'=>'Cliente QA','issuer_profile_id'=>'mardones-a','billing_profile_id'=>'default','customer_tax_confirm'=>1,'quote_vat_mode'=>'added','lines'=>array(array('source_type'=>'custom','label'=>'Producto QA','quantity'=>1,'unit_price'=>'100.00')));
 $preview=et_preview('preview_quote',$data);et_check($preview['success']&&strpos($preview['data']['subject'],'BORRADOR')!==false,'Unsaved form preview uses current values and provisional number');
 et_check(strpos($preview['data']['body'],number_format_i18n(121,2).' ARS')!==false,'Unsaved amounts included');
 et_check(count($mail)===0,'All preview routes remain mail-free');
 $new=GE_WTP_Commercial_Quotes::create_draft($customer->ID,array(array('source_type'=>'custom','name'=>'Plantilla QA','quantity'=>1,'unit_net'=>'100.00')),array('issuer_profile_id'=>'mardones-a','billing_profile_id'=>'default','customer_tax_confirm'=>true,'quote_vat_mode'=>'added','issuer_change_reason'=>'QA'),1);
 et_check(!is_wp_error($new),'QA quote created');
 $sent=GE_WTP_Commercial_Quotes::send($new['id'],1);et_check(!is_wp_error($sent),'Send uses intercepted QA transport');
 et_check(count($mail)===1&&strpos($mail[0]['subject'],'Presupuesto QA')===0,'Send uses edited subject');
 et_check(!empty($mail[0]['attachments'])&&strpos($mail[0]['message'],'Adjuntamos el PDF')!==false,'Existing PDF attachment preserved');
 $resent=GE_WTP_Commercial_Quotes::resend($new['id'],1);et_check(!is_wp_error($resent)&&count($mail)===2&&strpos($mail[1]['subject'],'Presupuesto QA')===0,'Resend uses same registry');
 $restored=GE_WTP_Email_Templates::save_record('commercial_quote_sent',$defaults,$saved['revision'],'default');et_check(!is_wp_error($restored)&&$restored['body']===$defaults['body'],'Restore default creates revision');
 $bad=get_option($option);$bad['commercial_quote_sent']['body']='<p>Broken</p>';update_option($option,$bad,false);
 $fallback=GE_WTP_Email_Templates::render('commercial_quote_sent',array('customer_name'=>'QA','portal_url'=>'https://example.invalid/portal','quote_number'=>'10001'));
 et_check(strpos($fallback['body'],'href="https://example.invalid/portal"')!==false,'Invalid stored template safely falls back');
 echo 'PASS '.$checks." email template checks\n";
} finally { if($before===null)delete_option($option);else update_option($option,$before,false);wp_set_current_user(1); }
