<?php
$qa_site=getenv('GRAPH_ORG_QA_SITE');if(!$qa_site)exit("Set GRAPH_ORG_QA_SITE to the isolated fixture.\n");
require $qa_site.'/wp-load.php';
if(DB_NAME!=='graph_org_foundation_20261002')exit('WRONG_DB');
wp_set_current_user(1);
add_filter('pre_wp_mail','__return_true',100);
$checks=array();function verify($ok,$name){global $checks;$checks[$name]=(bool)$ok;if(!$ok)throw new Exception($name);}
$first=GE_Organization::seed(1);verify(!is_wp_error($first),'seed_primary');
verify(GE_Organization::seed(1)===$first,'seed_idempotent');
$before=GE_WTP_Billing_Issuers::all();
$org=GE_Organization::save(GE_Organization::PRIMARY,1,$first['revision'],'general',array('display_name'=>'Graph Express QA','brand_name'=>'Marca de prueba'));
verify(!is_wp_error($org),'save_settings');verify($org['revision']===$first['revision']+1,'revision_increment');
verify(is_wp_error(GE_Organization::save(GE_Organization::PRIMARY,1,$first['revision'],'general',array('display_name'=>'stale'))),'optimistic_lock');
foreach(array('currency'=>'bad','country'=>'ARG','timezone'=>'madeup','email'=>'bad','website'=>'javascript:alert(1)') as $k=>$v)verify(is_wp_error(GE_Organization::validate('general',array($k=>$v),$org['settings']['general'])),'reject_'.$k);
verify(is_wp_error(GE_Organization::validate('branding',array('primary_color'=>'red'),$org['settings']['branding'])),'reject_invalid_color');
verify(is_wp_error(GE_Organization::validate('commercial',array('default_discount_percent'=>101),$org['settings']['commercial'])),'reject_discount');
verify(is_wp_error(GE_Organization::save(GE_Organization::PRIMARY,0,$org['revision'],'general',array('display_name'=>'forbidden'))),'unauth_write_denied');
$export=GE_Organization::export_config(GE_Organization::PRIMARY,1);verify(!is_wp_error($export),'export_success');
verify(!isset($export['settings']['integration_refs']),'export_no_credential_refs');
foreach($export['issuer_profiles'] as $i=>$p)verify(!isset($p['credentials_ref'],$p['cert_ref'],$p['verified_at']),'issuer_export_safe_'.$i);
$export['settings']['unknown']=array('token'=>'FAKE_DO_NOT_EXPORT');$export['settings']['integration_refs']=array('email'=>'fake-ref');
foreach($export['issuer_profiles'] as &$p){$p['credentials_ref']='fake-secret-ref';$p['cert_ref']='fake-cert-ref';$p['verification_status']='verified';$p['relationship_confirmed']=true;}unset($p);
$qa=GE_Organization::import_config($export,1);verify(!is_wp_error($qa),'import_fixture');
verify($qa['organization_id']!==GE_Organization::PRIMARY && $qa['mode']==='qa-config-only','new_isolated_organization');
verify(empty($qa['settings']['integration_refs']),'import_resets_integrations');verify(!isset($qa['settings']['unknown']),'import_ignores_unknown');
foreach(GE_Organization::issuers($qa['organization_id']) as $i=>$p){verify($p['verification_status']==='pending'&&!$p['relationship_confirmed']&&!$p['credentials_ref']&&!$p['cert_ref'],'issuer_import_reverify_'.$i);}
verify(GE_WTP_Billing_Issuers::all()===$before,'primary_issuers_unchanged');
verify(is_wp_error(GE_Organization::import_config(array('manifest'=>array('format'=>'graphex-organization-config','schema_version'=>2)),1)),'reject_future_version');
verify(is_wp_error(GE_Organization::import_config($export,0)),'unauth_import_denied');
$fixture_id=wp_generate_uuid4();
$uid=wp_insert_user(array('user_login'=>'org_fixture_'.$fixture_id,'user_pass'=>wp_generate_password(40),'user_email'=>$fixture_id.'@example.invalid','role'=>'subscriber'));
verify(!is_wp_error($uid),'qa_user_created');
$qa=GE_Organization::save($qa['organization_id'],1,$qa['revision'],'users',array('user_id'=>$uid,'role'=>'read-only'));
verify(!is_wp_error($qa),'assign_readonly');verify(GE_Organization::can($qa['organization_id'],$uid),'member_read');verify(!GE_Organization::can($qa['organization_id'],$uid,true),'readonly_write_denied');verify(!GE_Organization::can(GE_Organization::PRIMARY,$uid),'cross_org_config_denied');
verify(GE_Organization::qa_only($uid),'qa_only_detected');
$fixture_user=new WP_User($uid);$fixture_user->set_role('shop_manager');
verify(get_role('shop_manager')->has_cap('manage_woocommerce'),'fixture_has_inherited_woocommerce_cap');
wp_set_current_user(0);wp_set_current_user($uid);
verify(!current_user_can('ge_manage_operations')&&!current_user_can('manage_woocommerce'),'qa_operational_caps_denied');verify(is_wp_error(apply_filters('rest_authentication_errors',null)),'qa_rest_blocked');
verify(is_wp_error(GE_Organization::export_config(GE_Organization::PRIMARY,$uid)),'cross_org_export_denied');
$die_filter=function(){return function($message,$title,$args){throw new RuntimeException('guarded',(int)($args['response']??0));};};
add_filter('wp_die_handler',$die_filter);
foreach(array('guard_admin'=>'qa_admin_ajax_blocked','guard'=>'qa_frontend_blocked') as $method=>$name){$denied=false;try{GE_Organization::$method();}catch(RuntimeException $e){$denied=$e->getCode()===403;}verify($denied,$name);}
remove_filter('wp_die_handler',$die_filter);
wp_set_current_user(1);
verify(GE_Organization::storage_key($qa['organization_id'],'exports','config.json')==='organizations/'.$qa['organization_id'].'/exports/config.json','storage_namespace');
verify(is_wp_error(GE_Organization::storage_key($qa['organization_id'],'exports','../x')),'storage_traversal_denied');
verify(is_wp_error(GE_Organization::storage_key('unknown','exports','config.json')),'storage_unknown_org_denied');
$mod=GE_Organization::save($qa['organization_id'],1,$qa['revision'],'modules',array('quotes'=>1));verify(!is_wp_error($mod)&&$mod['settings']['modules']['quotes']&&!$mod['settings']['modules']['stock'],'module_preferences_saved');
$mail=GE_Organization::mail(array('subject'=>'Pedido · Graph Express','message'=>'<strong style="letter-spacing:.1em">GRAPH EXPRESS</strong><p>Receptor: Graph Express SA</p>Graph Express · Oruro 1253 · CABA','headers'=>array()));verify(strpos($mail['subject'],'Marca de prueba')!==false,'email_branding');
verify(strpos($mail['message'],'Receptor: Graph Express SA')!==false,'receiver_name_preserved');
verify(GE_Organization::mail(array('subject'=>'Aviso Graph Express','message'=>'Otro sistema: Graph Express'))===array('subject'=>'Aviso Graph Express','message'=>'Otro sistema: Graph Express'),'unrelated_mail_unchanged');
$audit=get_posts(array('post_type'=>GE_Organization::AUDIT,'post_status'=>'private','numberposts'=>-1));verify(count($audit)>=5,'sensitive_actions_audited');
foreach($audit as $a){$event=get_post_meta($a->ID,'_ge_org_event',true);verify(isset($event['organization_id'],$event['actor'],$event['timestamp']),'audit_shape_'.$a->ID);}
file_put_contents(getenv('GRAPH_ORG_QA_CONTEXT') ?: sys_get_temp_dir().'/graphex-org-qa-context.json',wp_json_encode(array('primary'=>GE_Organization::PRIMARY,'qa'=>$qa['organization_id'],'user'=>$uid)));
file_put_contents(getenv('GRAPH_ORG_QA_RESULTS') ?: sys_get_temp_dir().'/graphex-org-qa-results.json',wp_json_encode(array('checks'=>count($checks),'passed'=>count(array_filter($checks)),'results'=>$checks),JSON_PRETTY_PRINT));
echo 'PASS '.count($checks)." checks\n";
