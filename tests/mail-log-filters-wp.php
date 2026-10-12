<?php
$load=getenv('GE_WP_LOAD');if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('QA only');define('DISABLE_WP_CRON',true);$_SERVER['HTTP_HOST']='graphex.ar';$_SERVER['HTTPS']='on';require $load;if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong QA database');
$ids=array();$checks=0;$mail=0;$tag=str_replace('-','',wp_generate_uuid4());$campaign='newsletter_qa_'.$tag;$op='qa_operational_'.$tag;
add_filter('pre_wp_mail',function()use(&$mail){$mail++;return false;},PHP_INT_MAX);
function lq($v,$m){global $checks;if(!$v)throw new Exception($m);$checks++;}
function lf($input){return GE_WTP_Mail_Log_View::filters($input);}
function lquery($input){return GE_WTP_Mail_Log_View::query(lf($input));}
function fixture_log($context,$result,$date='2026-10-12 01:00:00'){global $ids,$tag;$id=wp_insert_post(array('post_type'=>'ge_email_log','post_status'=>'private','post_title'=>'Synthetic '.$tag,'post_date'=>$date,'post_date_gmt'=>$date));$ids[]=$id;if($context!==null)update_post_meta($id,'_ge_email_context',$context);update_post_meta($id,'_ge_email_result',$result);update_post_meta($id,'_ge_email_to','filters-'.$tag.'@example.invalid');return $id;}
try{
 wp_set_current_user(1);for($i=0;$i<54;$i++)fixture_log($campaign,$i%2?'failed':'sent');$oid=fixture_log($op,'sent');$unknown=fixture_log(null,'unknown');$old=fixture_log($op,'simulated','2001-04-03 12:00:00');
 lq(GE_WTP_Mail_Log_View::group('newsletter_launch_preview_20261012')==='campaigns','Historical newsletter preview correctly grouped');
 lq(GE_WTP_Mail_Log_View::label('newsletter_launch_preview_20261012')==='Prueba de newsletter','Historical preview explicitly labeled test');
 lq(GE_WTP_Mail_Log_View::group('commercial_quote_sent')==='operational','Quote operative');lq(GE_WTP_Mail_Log_View::group('')==='other','Missing context not guessed');
 $q=lquery(array('mail_group'=>'campaigns','mail_type'=>$campaign));lq((int)$q->found_posts===54,'Full matching history counted');lq(count($q->posts)===50,'Bounded first page');
 $q=lquery(array('mail_group'=>'campaigns','mail_type'=>$campaign,'mail_page'=>2));lq(count($q->posts)===4,'Second page remains reachable');
 $q=lquery(array('mail_group'=>'campaigns','mail_type'=>$campaign,'mail_result'=>'failed'));lq((int)$q->found_posts===27,'Result combines with group and type');
 $q=lquery(array('mail_group'=>'operational','mail_type'=>$op));lq((int)$q->found_posts===2,'Operative query excludes campaign rows');
 $q=lquery(array('mail_group'=>'other','mail_recipient'=>'filters-'.$tag));lq((int)$q->found_posts===1&&$q->posts[0]->ID===$unknown,'Missing metadata preserved in unclassified filter');
 $q=lquery(array('mail_type'=>$op,'mail_from'=>'2001-04-03','mail_until'=>'2001-04-03'));lq((int)$q->found_posts===1&&$q->posts[0]->ID===$old,'Inclusive date finds old history');
 $q=lquery(array('mail_type'=>$campaign,'mail_recipient'=>'not-this-recipient'));lq((int)$q->found_posts===0,'Recipient search intersects type');
 $q=lquery(array('mail_type'=>$op,'mail_subject'=>$tag));lq((int)$q->found_posts===2,'Text search intersects type');
 $f=lf(array('mail_group'=>'operational','mail_type'=>$campaign));lq($f['type']==='','Type from another group resets safely');
 $f=lf(array('mail_type'=>array('x'),'mail_result'=>'arbitrary','mail_from'=>"2026-10-12\0",'mail_page'=>-2));lq($f['type']===''&&$f['result']===''&&$f['from']==='','Malformed filters normalized');
 $_GET=array('mail_group'=>'campaigns','mail_type'=>$campaign);ob_start();GE_WTP_Mail_Log_View::render();$html=ob_get_clean();lq(strpos($html,'Grupo')!==false&&strpos($html,'Tipo de correo')!==false,'Group/type labels rendered');lq(strpos($html,'Aceptado por el correo')!==false,'Acceptance not claimed delivered');
 wp_set_current_user(0);lq(is_wp_error(lquery(array())),'Unauthorized query denied');ob_start();GE_WTP_Mail_Log_View::render();lq(ob_get_clean()==='','Unauthorized render exposes nothing');
 lq($mail===0,'No outgoing mail');echo wp_json_encode(array('status'=>'passed','checks'=>$checks,'mail_calls'=>$mail,'php'=>PHP_VERSION))."\n";
}finally{foreach($ids as $id)wp_delete_post($id,true);}
