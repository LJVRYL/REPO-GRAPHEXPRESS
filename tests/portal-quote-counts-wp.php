<?php
$load=getenv('GE_WP_LOAD');
if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('Isolated QA required');
define('FS_METHOD','direct');require $load;
if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong DB');
$checks=array();
function verify_portal($ok,$label){global $checks;if(!$ok)throw new Exception($label);$checks[]=$label;}
function preview_portal($customer){wp_set_current_user(1);$_GET=array('ge_preview_customer'=>$customer,'ge_preview_token'=>wp_create_nonce('ge_preview_customer_'.$customer));}
$ctx=json_decode(file_get_contents(dirname(dirname($load)).'/context.json'),true);
$snapshot=GE_WTP_Commercial_Quotes::get($ctx['quote'])['snapshot'];
$customer=wp_insert_user(array('user_login'=>'portal_counts_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'user_email'=>'portal-counts-'.wp_generate_uuid4().'@example.invalid','role'=>'customer','display_name'=>'QA Portal Counts'));
$other=wp_insert_user(array('user_login'=>'portal_other_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'user_email'=>'portal-other-'.wp_generate_uuid4().'@example.invalid','role'=>'customer'));
verify_portal(!is_wp_error($customer)&&!is_wp_error($other),'Synthetic profiles created in isolated database');
function make_portal_quote($customer,$state,$scope=''){
 global $snapshot;
 $id=wp_insert_post(array('post_type'=>GE_WTP_Commercial_Quotes::POST_TYPE,'post_status'=>'private','post_title'=>'QA PORTAL COUNT'));
 update_post_meta($id,GE_WTP_Commercial_Quotes::CUSTOMER_META,$customer);
 update_post_meta($id,GE_WTP_Commercial_Quotes::STATUS_META,$state);
 update_post_meta($id,GE_WTP_Commercial_Quotes::CURRENT_META,1);
 update_post_meta($id,GE_WTP_Commercial_Quotes::VERSIONS_META,array(1=>$snapshot));
 if($scope)update_post_meta($id,'_ge_organization_id',$scope);
 return $id;
}
wp_set_current_user($customer);$_GET=array();verify_portal(count(GE_WTP_Portal_Quotes::customer_quotes())===0,'Zero count matches empty list');
$draft=make_portal_quote($customer,'draft');
verify_portal(!GE_WTP_Portal_Quotes::customer_quotes(),'Internal draft stays hidden from customer');
verify_portal(is_wp_error(GE_WTP_Commercial_Quotes::get($draft,$customer)),'Direct quote PDF and file access reject customer draft ownership');
preview_portal($customer);verify_portal(!GE_WTP_Portal_Quotes::customer_quotes(),'Preview published count does not include internal drafts');$quotes=GE_WTP_Portal_Quotes::customer_quotes(true);verify_portal(count($quotes)===1&&$quotes[0]['id']===$draft,'Authorized preview can inspect its draft separately');
ob_start();GE_WTP_Portal_Quotes::card(GE_WTP_Portal_Quotes::customer_quotes());$card=ob_get_clean();verify_portal(strpos($card,'<strong>0</strong>')!==false&&strpos($card,'1 en preparación · solo vista interna')!==false,'Summary separates zero published and one internal draft');
ob_start();GE_WTP_Job_Flow::customer_list();$list=ob_get_clean();verify_portal(substr_count($list,'Ver detalle')===0&&substr_count($list,'Ver borrador')===1&&strpos($list,'solo vista interna')!==false,'Preview list separates internal draft from published proposals');
ob_start();GE_WTP_Portal_Quotes::activity($quotes,array());$activity=ob_get_clean();verify_portal(strpos($activity,'en preparación (vista previa)')!==false&&strpos($activity,'pendiente de aprobación')===false,'Draft activity does not imply publication or approval');
$before=get_post_meta($draft);$_GET['presupuesto']=$draft;ob_start();GE_WTP_Commercial_Quote_UI::render_customer();$detail=ob_get_clean();verify_portal(strpos($detail,'borrador en vista previa')!==false,'Preview detail identifies draft');verify_portal(get_post_meta($draft)===$before,'Preview detail changes no status snapshots or viewed events');
wp_set_current_user($customer);$_GET=array('ge_preview_customer'=>$customer,'ge_preview_token'=>wp_create_nonce('ge_preview_customer_'.$customer));verify_portal(!GE_WTP_Portal::is_staff_preview()&&!GE_WTP_Portal_Quotes::customer_quotes(),'Customer cannot forge staff preview with a nonce');
$_GET=array();
$states=array('sent','viewed','accepted','converted','rejected','expired','cancelled');
foreach($states as $state)make_portal_quote($customer,$state);
verify_portal(count(GE_WTP_Portal_Quotes::customer_quotes())===7,'All published history states count consistently');
make_portal_quote($other,'sent');make_portal_quote($customer,'unknown');make_portal_quote($customer,'sent','other-organization');
verify_portal(count(GE_WTP_Portal_Quotes::customer_quotes())===7,'Other customer unknown state and other organization excluded');
update_user_meta($customer,'_ge_organization_id','other-organization');verify_portal(!GE_WTP_Portal_Quotes::customer_quotes(),'Foreign customer profile excluded');update_user_meta($customer,'_ge_organization_id',GE_Organization::PRIMARY);
for($i=0;$i<46;$i++)make_portal_quote($customer,'sent');
$quotes=GE_WTP_Portal_Quotes::customer_quotes();verify_portal(count($quotes)===53,'Count covers complete history beyond 50 rows');
ob_start();GE_WTP_Job_Flow::customer_list();$list=ob_get_clean();verify_portal(substr_count($list,'Ver detalle')===53,'List covers same complete history as count');
preview_portal($customer);$quotes=GE_WTP_Portal_Quotes::customer_quotes();verify_portal(count($quotes)===53&&count(GE_WTP_Portal_Quotes::customer_quotes(true))===54,'Preview separates published history and its internal draft');
ob_start();GE_WTP_Portal_Quotes::card($quotes);$card=ob_get_clean();verify_portal(strpos($card,'48 pendientes de aprobación')!==false&&strpos($card,'1 en preparación')!==false,'Plural pending and draft counts are separate');
$original=get_option(GE_Organization::ROOT);$limited=$original;$limited[GE_Organization::PRIMARY]['members']['1']='produccion';$filter=function()use(&$limited){return $limited;};add_filter('pre_option_'.GE_Organization::ROOT,$filter);
verify_portal(!GE_WTP_Portal_Quotes::customer_quotes(),'Staff preview respects quote module role');
$limited[GE_Organization::PRIMARY]['members']['1']='owner';$limited[GE_Organization::PRIMARY]['settings']['modules']['quotes']=false;verify_portal(!GE_WTP_Portal_Quotes::customer_quotes(),'Disabled quote module hides projection');remove_filter('pre_option_'.GE_Organization::ROOT,$filter);
verify_portal(get_option(GE_Organization::ROOT)===$original,'Permission tests never persist role changes');
wp_set_current_user($other);$_GET=array();verify_portal(count(GE_WTP_Portal_Quotes::customer_quotes())===1,'Second customer sees only its one proposal');
wp_set_current_user($customer);$_GET=array('presupuesto'=>$draft);ob_start();GE_WTP_Commercial_Quote_UI::render_customer();$hidden=ob_get_clean();verify_portal(strpos($hidden,'ge-quote-customer')===false,'Direct detail cannot expose internal draft');
echo 'PASS '.count($checks)." portal quote count checks\n";
file_put_contents(dirname(dirname($load)).'/portal-counts-results.json',wp_json_encode(array('checks'=>count($checks),'passed'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
