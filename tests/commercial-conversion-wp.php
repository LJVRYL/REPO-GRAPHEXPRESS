<?php
$load=getenv('GE_WP_LOAD');if(!$load||strpos($load,'/job-flow-qa-')===false)throw new Exception('Isolated QA required');define('FS_METHOD','direct');require $load;if(DB_NAME!=='graph_job_flow_20261007')throw new Exception('Wrong DB');
wp_set_current_user(1);$checks=0;$emails=0;
$organizations=GE_Organization::all();$organizations[GE_Organization::PRIMARY]['members']['1']='owner';$organizations[GE_Organization::PRIMARY]['revision']=(int)($organizations[GE_Organization::PRIMARY]['revision']??1);$organizations[GE_Organization::PRIMARY]['settings']=array_replace_recursive(GE_Organization::defaults(),$organizations[GE_Organization::PRIMARY]['settings']??[]);$organizations[GE_Organization::PRIMARY]['settings']['general']['display_name']='QA Graphex';update_option(GE_Organization::ROOT,$organizations,false);
function qc($ok,$message){global $checks;if(!$ok)throw new Exception($message);$checks++;}
add_filter('pre_wp_mail',function()use(&$emails){$emails++;return true;},PHP_INT_MAX);
$customer=wp_insert_user(['user_login'=>'completion-'.wp_generate_password(10,false),'user_email'=>'completion-'.wp_generate_password(10,false).'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer','display_name'=>'QA COMPLETION']);
GE_WTP_Billing::save_profile($customer,['legal_name'=>'QA RECEPTOR SRL','vat_status'=>'registered','billing_mode'=>'common','cuit'=>'23336924529','fiscal_address'=>'QA 123'],1);
$issuers=GE_WTP_Billing_Issuers::all();$fixture=['legal_name'=>'QA ISSUER','cuit'=>'23336924529','active'=>true,'fiscal_address'=>'QA 123','common_price_policy'=>'tax_exclusive','invoice_a_price_policy'=>'tax_exclusive'];$issuers['leonardo-c']=GE_WTP_Billing_Issuers::normalize($fixture+['vat_status'=>'','invoice_types_allowed'=>['C'],'tax_rate_basis_points'=>0],'leonardo-c');$issuers['mardones-a']=GE_WTP_Billing_Issuers::normalize($fixture+['vat_status'=>'registered','invoice_types_allowed'=>['A'],'tax_rate_basis_points'=>2100],'mardones-a');qc(!is_wp_error($issuers['leonardo-c'])&&!is_wp_error($issuers['mardones-a']),'Valid synthetic issuer fixtures');update_option(GE_WTP_Billing_Issuers::OPTION,$issuers);
$lines=[['source_type'=>'custom','name'=>'QA Producto','quantity'=>2,'unit'=>'u','unit_net'=>'100','discount_type'=>'percent','discount_value'=>'10','discount_reason'=>'QA descuento']];
$args=['billing_profile_id'=>'default','issuer_profile_id'=>'leonardo-c','deposit_enabled'=>true,'deposit_percent'=>50,'valid_until'=>wp_date('Y-m-d',time()+86400*30)];

$q=GE_WTP_Commercial_Quotes::create_draft($customer,$lines,$args,1);qc(!is_wp_error($q),'Complete commercial C');
qc($q['snapshot']['fiscal_status']==='pending','Fiscal review remains pending');
update_post_meta($q['id'],GE_WTP_Commercial_Quotes::STATUS_META,'sent');$_GET=['section'=>'quotes','quote_id'=>$q['id']];ob_start();GE_WTP_Commercial_Quote_UI::render_staff();$html=ob_get_clean();$_GET=[];qc(strpos($html,'Emisor elegido:')!==false&&strpos($html,'Receptor elegido:')!==false&&strpos($html,'Presupuesto sin IVA')!==false&&strpos($html,'Emisor C')!==false,'UI separates saved choices from pending fiscal review');
$before=get_post_meta($q['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,true);
qc(!is_wp_error(GE_WTP_Commercial_Quotes::prepare_for_conversion($q['id'],1)),'Pending fiscal C can prepare commercial conversion');
qc(is_wp_error(GE_WTP_Commercial_Quotes::check_billing_snapshot($q)),'Fiscal payment guard still blocks');
$o=GE_WTP_Commercial_Checkout::convert_staff($q['id'],['expected_version'=>1,'confirmation_method'=>'staff','payment_state'=>'unregistered','reason'=>'QA commercial conversion'],1);if(is_wp_error($o))throw new Exception($o->get_error_code().': '.$o->get_error_message());
qc($o instanceof WC_Order,'Commercial order created');qc($o->get_total()==='180.00','Exact saved discounted total');
qc($o->get_meta('_ge_payment_state')==='pending'&&(int)$o->get_meta('_ge_amount_paid_cents')===0,'Payment pending, no fictitious receipt');
qc($o->get_meta('_ge_commercial_fiscal_status')==='pending','Order retains fiscal review');
qc($o->get_meta('_ge_production_status')==='pending'&&$o->get_meta(GE_WTP_Workflow::STAGE_META)==='review','Production awaits review');
qc($o->get_meta('_ge_billing_profile_snapshot')['legal_name']==='QA RECEPTOR SRL','Frozen receiver retained');
qc($o->get_meta('_ge_commercial_quote_snapshot')['issuer_snapshot']===$q['snapshot']['issuer_snapshot'],'Explicit issuer retained despite receiver suggestion');
qc($before===get_post_meta($q['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,true),'Conversion never modifies saved version');
$again=GE_WTP_Commercial_Checkout::convert_staff($q['id'],['expected_version'=>1],1);qc(!is_wp_error($again)&&$again->get_id()===$o->get_id(),'Retry returns same order');
qc(count(wc_get_orders(['limit'=>20,'meta_key'=>'_ge_commercial_quote_id','meta_value'=>$q['id']]))===1,'No duplicate order');
qc($emails===0,'No customer notification from manual conversion');
qc(is_wp_error(GE_WTP_Commercial_Quotes::check_billing_snapshot(GE_WTP_Commercial_Quotes::get($q['id'],1))),'Conversion cannot clear payment guard');
$incomplete=GE_WTP_Commercial_Quotes::create_draft($customer,[],['allow_empty_draft'=>true],1);qc(is_wp_error(GE_WTP_Commercial_Checkout::convert_staff($incomplete['id'],[],1)),'Missing products block order');
$alternatives=$lines;$alternatives[0]['selection_type']='alternative';$alternatives[0]['selection_group']='format';$alternatives[]=$alternatives[0];$alternatives[1]['name']='QA alternative';$alt=GE_WTP_Commercial_Quotes::create_draft($customer,$alternatives,$args,1);qc(is_wp_error(GE_WTP_Commercial_Checkout::convert_staff($alt['id'],[],1)),'Full Power style alternatives require selection');
$bad=GE_WTP_Commercial_Quotes::create_draft($customer,$lines,$args,1);$snapshot=$bad['snapshot'];$snapshot['total_cents']++;update_post_meta($bad['id'],GE_WTP_Commercial_Quotes::VERSIONS_META,[1=>$snapshot]);qc(is_wp_error(GE_WTP_Commercial_Checkout::convert_staff($bad['id'],[],1)),'Inconsistent money blocks before order creation');qc(!wc_get_orders(['limit'=>1,'meta_key'=>'_ge_commercial_quote_id','meta_value'=>$bad['id']]),'Invalid money cannot leave incomplete order');
$stale=GE_WTP_Commercial_Quotes::create_draft($customer,$lines,$args,1);qc(is_wp_error(GE_WTP_Commercial_Checkout::convert_staff($stale['id'],['expected_version'=>999],1)),'Stale version blocks');qc(is_wp_error(GE_WTP_Commercial_Checkout::convert_staff($stale['id'],[],$customer)),'Customer cannot execute staff conversion');
$rate=WC_Tax::get_rates();$rate=$rate?key($rate):WC_Tax::_insert_tax_rate(['tax_rate_country'=>'AR','tax_rate'=>'21.0000','tax_rate_name'=>'QA IVA','tax_rate_priority'=>1]);update_option('ge_commercial_tax_rate_id',(int)$rate);
$a=GE_WTP_Commercial_Quotes::create_draft($customer,$lines,array_merge($args,['issuer_profile_id'=>'mardones-a']),1);$aorder=GE_WTP_Commercial_Checkout::convert_staff($a['id'],[],1);qc(!is_wp_error($aorder)&&$aorder->get_total()==='217.80'&&(float)$aorder->get_total_tax()===37.8,'A preserves exact commercial IVA'.(is_wp_error($aorder)?': '.$aorder->get_error_message():': '.$aorder->get_total().' / '.$aorder->get_total_tax()));qc($aorder->get_meta('_ge_commercial_fiscal_status')==='pending','A fiscal review stays pending');qc($emails===0,'All manual conversions remain silent');
echo 'PASS '.$checks." commercial conversion checks\n";
