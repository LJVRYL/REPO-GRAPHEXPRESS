<?php
$_SERVER['HTTP_HOST']='127.0.0.1';$_SERVER['HTTPS']='on';$site=getenv('GE_QA_SITE') ?: '/home/graphexpress/releases/integral-guard-qa-20261014/site';require $site.'/wp-load.php';
if(DB_NAME!=='graph_integral_guard_20261014')exit(2);
wp_set_current_user(1);
$orders=wc_get_orders(array('limit'=>120,'orderby'=>'ID','order'=>'DESC'));
$order=null;foreach($orders as $o)foreach($o->get_items() as $item)if($item->get_name()==='QA · Sin seña'){$order=$o;break 2;}
if(!$order)exit(3);
$quote=GE_WTP_Commercial_Quotes::create_draft(37,array(),array(),1);
if(is_wp_error($quote))exit($quote->get_error_message());
$qid=is_array($quote)?$quote['id']:$quote;
$quote=GE_WTP_Commercial_Quotes::get($qid,1);
if(is_wp_error($quote))exit($quote->get_error_message());
$versions=(array)get_post_meta($qid,GE_WTP_Commercial_Quotes::VERSIONS_META,true);$versions[$quote['version']]['total_cents']=47190000;update_post_meta($qid,GE_WTP_Commercial_Quotes::VERSIONS_META,$versions);
$org=get_option(GE_Organization::ROOT);$results=array();
function check_master($condition,$message){if(!$condition)throw new RuntimeException($message);}
try {
$source=wc_create_order(array('customer_id'=>37));$source->set_currency('ARS');$source->set_total(471900);$source->update_meta_data('_ge_commercial_quote_id',$qid);$source->update_meta_data('_ge_final_total_cents',47190000);$source->update_meta_data('_ge_amount_paid_cents',0);$source->update_meta_data('_ge_amount_due_cents',47190000);$source->update_meta_data('_ge_payment_state','pending');$source->save();
$item=new WC_Order_Item_Product();$item->set_name('QA · Producto de origen');$item->set_quantity(1);$item->set_total(471900);$source->add_item($item);$source->save();
update_post_meta($qid,GE_WTP_Commercial_Quotes::ORDER_META,$source->get_id());update_post_meta($qid,GE_WTP_Commercial_Quotes::STATUS_META,'converted');
$child=wc_create_order(array('customer_id'=>37));$child->set_currency('ARS');$child->set_total(330000);$child->update_meta_data('_ge_work_order','yes');$child->update_meta_data('_ge_source_quote_order_id',$source->get_id());$child->update_meta_data('_ge_source_quote_item_id',$item->get_id());$child->save();$item->update_meta_data('_ge_work_order_id',$child->get_id());$item->save();
$child_item=new WC_Order_Item_Product();$child_item->set_name('QA · Cartelería');$child_item->set_quantity(100);$child_item->set_total(330000);$child_item->update_meta_data('Especificaciones','PVC · impresión frente y dorso');$child->add_item($child_item);$child->save();
$_GET=array();$context=GE_Work_Master::context('production',$child->get_id());check_master($context['quote']['id']===$qid && $context['commercial_order']->get_id()===$source->get_id(),'Reciprocal production source not joined');
$_GET=array('section'=>'production','order_id'=>$child->get_id());ob_start();GE_Work_Master::begin('production');GE_Work_Master::end();$linked_summary=ob_get_clean();check_master(strpos($linked_summary,'data-ge-quote-preview')!==false && strpos($linked_summary,'Abrir portal cliente')!==false,'Persistent native quote actions missing');check_master(strpos($linked_summary,'Saldo pendiente')!==false,'Commercial balance not visible');
$_GET=array('work_order_id'=>$child->get_id());$context=GE_Work_Master::context('quotes',$qid);check_master($context['order']->get_id()===$child->get_id(),'Production context lost from quote navigation');
$child->set_customer_id(0);$child->save();$context=GE_Work_Master::context('production',$child->get_id());check_master(!$context['quote'] && $context['commercial_order']->get_id()===$child->get_id(),'Foreign customer source exposed');
$child->set_customer_id(37);$child->save();$item->delete_meta_data('_ge_work_order_id');$item->save();$context=GE_Work_Master::context('production',$child->get_id());check_master(!$context['quote'],'Nonreciprocal source exposed');
$results[]=array('reciprocal_source'=>true,'quote_navigation_preserves_child'=>true,'foreign_customer_rejected'=>true,'broken_backlink_rejected'=>true);
$legacy_key=GE_WTP_Customer_Quotes::PREFIX.'master_qa_'.$qid;
update_user_meta(37,$legacy_key,array('title'=>'QA histórico','order_id'=>$source->get_id()));
$source->update_meta_data(GE_WTP_Customer_Quotes::KEY_META,$legacy_key);$source->delete_meta_data('_ge_commercial_quote_id');$source->save();
$_GET=array('section'=>'quotes','legacy_user'=>37,'legacy_key'=>$legacy_key);
$before=serialize(get_user_meta(37,$legacy_key,true));ob_start();$active=GE_Work_Master::begin('quotes');GE_Work_Master::end();$legacy_html=ob_get_clean();
check_master($active && strpos($legacy_html,'legacy_key=')!==false,'Historic quote missing common navigation');check_master($before===serialize(get_user_meta(37,$legacy_key,true)),'Historic quote mutated');
$_GET=array();$context=GE_Work_Master::context('orders',$source->get_id());check_master(!empty($context['legacy']),'Historic order lost quote backlink');
$results[]=array('historic_quote_wrapped'=>true,'historic_record_unchanged'=>true,'historic_backlink'=>true);
$guest=wc_create_order();$guest->set_billing_first_name('QA');$guest->set_billing_last_name('Invitado');$guest->set_billing_email('guest-qa@example.invalid');$guest->set_total(100);$guest->update_meta_data('_ge_production_operational_status','closed');$guest->save();
$_GET=array('section'=>'orders','order_id'=>$guest->get_id());ob_start();GE_Work_Master::begin('orders');GE_Work_Master::end();$guest_html=ob_get_clean();check_master(strpos($guest_html,'QA Invitado')!==false && strpos($guest_html,'guest-qa@example.invalid')!==false && strpos($guest_html,'Cerrado')!==false,'Guest identity or closed state missing');
$results[]=array('guest_customer_preserved'=>true,'closed_state_visible'=>true);
foreach(array('admin','produccion') as $role){
 $copy=$org;$copy[GE_Organization::PRIMARY]['members']['1']=$role;update_option(GE_Organization::ROOT,$copy);
 foreach(array('production','orders','quotes','delivery') as $kind){
  $section=$kind==='delivery'?'orders':$kind;
  $_GET=array('section'=>$section,($section==='quotes'?'quote_id':'order_id')=>($section==='quotes'?$qid:$order->get_id()),'step'=>'files');
  if($kind==='delivery')$_GET['work_stage']='delivery';
  if(!GE_WTP_Work_Panel::allowed($section))continue;
  $before=hash('sha256',serialize(get_post_meta($order->get_id())));
  ob_start();$active=GE_Work_Master::begin($section);GE_Work_Master::end();$summary=ob_get_clean();
  check_master($active,'Master context missing');check_master(substr_count($summary,'aria-label="Etapas del trabajo"')===1,'Duplicate navigation');
  check_master($before===hash('sha256',serialize(get_post_meta($order->get_id()))),'Read wrapper mutated order');
  if($section!=='quotes')check_master((strpos($summary,'ge-work-finance')!==false)===($role==='admin'),'Financial role exposure');
  ob_start();GE_WTP_Staff_Portal::render();$body=ob_get_clean();
  check_master(substr_count($body,'data-ge-work-master')===1,'Native integration missing');
  if($section==='production') {check_master(strpos($body,'ge_production_document')!==false,'Production upload lost');check_master(strpos($body,'ge-prod-header')===false,'Standalone header retained');}
  if($kind==='orders')check_master(strpos($body,'ge_backoffice_order_document')!==false,'Shared upload lost');
  if($kind==='delivery')check_master(strpos($body,'ge_delivery_label')!==false && strpos($body,'ge_backoffice_order_document')===false,'Delivery contents not separated');
  if($section==='quotes')check_master(strpos($body,'data-ge-quote-preview')!==false,'Quote action lost');
  $styles='';foreach(array('admin','staff','production','commercial-quotes') as $name)$styles.=file_get_contents(GE_WTP_PLUGIN_DIR.'assets/css/'.$name.'.css');
  $styles.=file_get_contents(WPMU_PLUGIN_DIR.'/ge-production-cards/assets/cards.css').file_get_contents(WPMU_PLUGIN_DIR.'/ge-work-master/master.css');
  $html='<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#f5f5fa;font:16px/1.5 Arial,sans-serif}.ge-staff-main{max-width:1160px;margin:auto;padding:20px}input,textarea,select{max-width:100%;box-sizing:border-box}</style><style>'.$styles.'</style><main class="ge-staff-main">'.$body.'</main><script>'.file_get_contents(WPMU_PLUGIN_DIR.'/ge-work-master/master.js').'</script></html>';
  file_put_contents(dirname(ABSPATH).'/master-'.$role.'-'.$kind.'.html',$html);
  $results[]=array('role'=>$role,'section'=>$kind,'passed'=>true);
 }
}
file_put_contents(dirname(ABSPATH).'/master-admin-linked.html','<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#f5f5fa;font:16px/1.5 Arial,sans-serif}.ge-staff-main{max-width:1160px;margin:auto;padding:20px}</style><style>'.$styles.'</style><main class="ge-staff-main">'.$linked_summary.'</main></html>');
echo wp_json_encode(array('passed'=>true,'results'=>$results,'qa_only'=>true,'quote_id'=>$qid,'order_id'=>$order->get_id()));

} finally { update_option(GE_Organization::ROOT,$org); }
