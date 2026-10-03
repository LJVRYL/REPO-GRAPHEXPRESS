<?php
require __DIR__.'/qa/site/wp-load.php';if(strpos(DB_NAME,'graphex_crm_v1_qa_')!==0)exit;
wp_set_current_user(1);$f=json_decode(file_get_contents(__DIR__.'/qa/fixtures.json'),true);$checks=array();
function check2($n,$v){global $checks;$checks[]=array('name'=>$n,'passed'=>(bool)$v);if(!$v)throw new Exception($n);}
$msg=array('title'=>'Mensaje idempotente QA','channel'=>'whatsapp','external_id'=>'fixture-message-1','intent'=>'hola','customer_id'=>$f['customer_id']);$a=GE_CRM::save('thread',$msg);$b=GE_CRM::save('thread',$msg);check2('inbound message idempotent',$a['id']===$b['id']);
$cfg=GE_CRM::config();$cfg['stages']['proof']='Prueba comercial';GE_CRM::configure($cfg);check2('stage config persists',GE_CRM::config()['stages']['proof']==='Prueba comercial');
$cfg=GE_CRM::config();$cfg['new_stage_key']='design';$cfg['new_stage_label']='Diseño';GE_CRM::configure($cfg);check2('UI custom stage addition persists',GE_CRM::config()['stages']['design']==='Diseño');
$cfg=GE_CRM::config();$cfg['stages']['design']='';GE_CRM::configure($cfg);check2('unused stage removed by empty label',!isset(GE_CRM::config()['stages']['design']));
$orgs=GE_Organization::all();$disabled=$orgs;$disabled[GE_CRM::org()]['settings']['modules']['crm']=false;update_option(GE_Organization::ROOT,$disabled);$called=false;GE_CRM::system(function()use(&$called){$called=true;});check2('module flag disables internal automation',!$called);update_option(GE_Organization::ROOT,$orgs);
$req=wp_insert_post(array('post_type'=>'ge_quote_request','post_status'=>'private','post_title'=>'Solicitud landing fixture'));
update_post_meta($req,'_ge_organization_id',GE_CRM::org());update_post_meta($req,'_ge_quote_request',array('customer_id'=>$f['customer_id'],'status'=>'new','funnel_source'=>'landing-v1','items'=>array()));
check2('current landing request contract ingested',count(array_filter(GE_CRM::records('lead'),function($r)use($req){return ($r['quote_request_id']??0)===$req;}))===1);
// Audit rollback must be atomic with record mutation.
$before=count(GE_CRM::records('lead'));try{GE_CRM::locked(function(){global $wpdb;$wpdb->insert(GE_CRM::table(),array('organization_id'=>GE_CRM::org(),'kind'=>'lead','title'=>'Rollback fixture','payload'=>'{}','created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s')));throw new RuntimeException('fixture rollback');});}catch(RuntimeException $e){}
check2('transaction rollback preserves records',count(GE_CRM::records('lead'))===$before);
$_GET=array('section'=>'crm','view'=>'dashboard');ob_start();GE_CRM_UI::render();$html=ob_get_clean();check2('dashboard aggregate and followup sections',strpos($html,'Oportunidades por etapa')!==false&&strpos($html,'revisar seguimiento')!==false);
// Store no mail body or customer secrets in evidence.
file_put_contents(__DIR__.'/../outputs/qa-advanced.json',wp_json_encode(array('checks'=>$checks,'passed'=>count($checks),'failed'=>0),JSON_PRETTY_PRINT));echo 'PASS '.count($checks).' advanced checks'.PHP_EOL;
