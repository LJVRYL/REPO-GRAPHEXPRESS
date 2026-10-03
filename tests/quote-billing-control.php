<?php
if ( empty($argv[1]) || empty($argv[2]) || strpos($argv[1], '/work/qa/') === false ) { throw new Exception('Usage: isolated QA wp-load.php and work directory'); }
$qaWork=$argv[2];require $argv[1];
if(DB_NAME!=='graph_quote_billing_20261002')throw new Exception('WRONG_DB');
add_filter('pre_wp_mail','__return_true',100);wp_set_current_user(1);update_option('ge_customer_tax_stage_v1',3);
$checks=[];function ck($ok,$label){global $checks;if(!$ok)throw new Exception($label);$checks[]=$label;}
$customer=wp_insert_user(['user_login'=>'qbc-'.wp_generate_password(8,false),'user_email'=>'qbc-'.wp_generate_password(8,false).'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer','display_name'=>'QA MULTIPLEX · NO PRODUCIR']);
$base=['legal_name'=>'QA VANGUARDIA','cuit'=>'23336924529','vat_status'=>'registered','billing_mode'=>'common','billing_email'=>'qa@example.invalid','fiscal_address'=>'CALLE QA 123'];
GE_WTP_Billing::save_profile($customer,$base,1);
$profiles=[];foreach(['leonardo-c'=>'23336924529','mardones-a'=>'20948548934'] as $key=>$cuit){$profiles[$key]=GE_WTP_Billing_Issuers::normalize(['legal_name'=>'QA EMISOR','display_name'=>'QA EMISOR','cuit'=>$cuit,'fiscal_address'=>'QA 123','active'=>true,'verification_status'=>'pending','default_for_scenarios'=>[$key==='mardones-a'?'invoice_a':'common']],$key);if(is_wp_error($profiles[$key]))throw new Exception('QA issuer fixture');$profiles[$key]['revision']=1;}
foreach($profiles as &$issuer){$issuer['display_name']=$issuer['id']==='mardones-a'?'QA Mardones':'QA Ayala';$issuer['legal_name']='QA '.$issuer['display_name'];$issuer['vat_status']=$issuer['id']==='mardones-a'?'registered':'monotributo';$issuer['verification_status']='verified';$issuer['verified_at']=gmdate('c');$issuer['relationship_confirmed']=true;$issuer['active']=true;$issuer['common_price_policy']='tax_exclusive';$issuer['invoice_a_price_policy']='tax_exclusive';$issuer['tax_rate_basis_points']=$issuer['id']==='mardones-a'?2100:0;$issuer['invoice_types_allowed']=$issuer['id']==='mardones-a'?['A','B']:['C'];$issuer['point_of_sale']='1';}unset($issuer);update_option(GE_WTP_Billing_Issuers::OPTION,$profiles);
$line=[['source_type'=>'custom','name'=>'QA stickers','quantity'=>10,'unit'=>'u','unit_net'=>'1000']];
$args=['billing_profile_id'=>'default','issuer_profile_id'=>'mardones-a','customer_tax_confirm'=>true,'issuer_change_reason'=>'QA elección explícita','valid_until'=>'2026-11-01'];
$q=GE_WTP_Commercial_Quotes::create_draft($customer,$line,$args,1);if(is_wp_error($q)){throw new Exception($q->get_error_code().': '.$q->get_error_message());}ck(true,'Single profile draft');
ck(isset($q['snapshot']['receiver_snapshot']),'Creation captures receiver');
$branch=GE_WTP_Customer_Branches::save($customer,array_merge($base,['label'=>'QA Belgrano','legal_name'=>'QA BELGRANO']),1);ck(!is_wp_error($branch),'Multiple profiles use existing model');
$missing=GE_WTP_Commercial_Quotes::create_draft($customer,$line,['customer_tax_confirm'=>true],1);ck(is_wp_error($missing)&&$missing->get_error_code()==='ge_quote_profile_required','No rigid default for multiple profiles');
$before=$q['snapshot'];
$selection=['expected_hash'=>GE_WTP_Quote_Billing_Control::hash($before),'billing_profile_id'=>$branch['id'],'issuer_profile_id'=>'mardones-a','billing_reason'=>'QA selección Belgrano'];
$q=GE_WTP_Quote_Billing_Control::select($q['id'],$selection,1);ck(!is_wp_error($q),'Draft changes recipient');
foreach(['items','net_cents','tax_cents','total_cents','valid_until','notes_customer'] as $key){ck(($before[$key]??null)===($q['snapshot'][$key]??null),'Billing selection preserves '.$key);}
ck($q['snapshot']['receiver_snapshot']['legal_name']==='QA BELGRANO','Selected receiver captured');
ck(in_array($q['snapshot']['fiscal_status'],['pending','resolved'],true),'Fiscal resolution preserves amounts or requires reconciliation');
ck(!GE_WTP_Quote_Billing_Control::state($q['snapshot'])['locked'],'Pending allows manual selection');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],$selection,1)),'Stale quote rejected');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],$selection,$customer)),'Customer cannot alter fiscal quote');
$p=GE_WTP_Customer_Branches::find($customer,$branch['id']);
$input=array_merge($p,['profile_hash'=>GE_WTP_Quote_Billing_Control::hash($p),'verification_action'=>'verify','verification_reason'=>'QA datos confirmados']);
$result=GE_WTP_Quote_Billing_Control::save_customer($q,$input,1);ck(!is_wp_error($result),'Quick profile manual verification');
$still=GE_WTP_Commercial_Quotes::get($q['id']);ck($still['snapshot']===$q['snapshot'],'Customer edit never refreshes snapshot silently');
$p=GE_WTP_Customer_Branches::find($customer,$branch['id']);ck($p['verification_status']==='verified'&&$p['source']==='manual','Source manual distinct from ARCA');
$q=GE_WTP_Quote_Billing_Control::select($q['id'],array_merge($selection,['expected_hash'=>GE_WTP_Quote_Billing_Control::hash($q['snapshot']),'billing_refresh'=>1]),1);ck(!is_wp_error($q),'Explicit draft refresh');
ck(GE_WTP_Quote_Billing_Control::state($q['snapshot'])['locked'],'Verified receiver locks');
$change=['expected_hash'=>GE_WTP_Quote_Billing_Control::hash($q['snapshot']),'billing_profile_id'=>'default','issuer_profile_id'=>'leonardo-c','billing_reason'=>'QA override'];
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],$change,1)),'Verified normal change denied');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],array_merge($change,['billing_override'=>1,'billing_reason'=>'']),1)),'Override needs reason');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],array_merge($change,['billing_override'=>1]),$customer)),'Unauthorized override denied');
$q=GE_WTP_Quote_Billing_Control::select($q['id'],array_merge($change,['billing_override'=>1]),1);ck(!is_wp_error($q),'Authorized override accepted');
$events=get_post_meta($q['id'],'_ge_commercial_events',true);$last=end($events);ck($last['data']['override']&&$last['data']['reason']==='QA override','Override audited');
ck($q['snapshot']['issuer_snapshot']['id']==='leonardo-c','Manual issuer choice captured');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::guard(['receiver_snapshot'=>$p,'billing_profile_id'=>$p['id'],'issuer_snapshot'=>['id'=>'mardones-a']],['billing_profile_id'=>'default'],1)),'Full revision cannot bypass lock');
$before=$q['snapshot'];update_post_meta($q['id'],GE_WTP_Commercial_Quotes::STATUS_META,'sent');
$p=GE_WTP_Customer_Branches::find($customer,'default');$result=GE_WTP_Quote_Billing_Control::save_customer($q,array_merge($p,['legal_name'=>'QA DATOS NUEVOS','profile_hash'=>GE_WTP_Quote_Billing_Control::hash($p)]),1);ck(!is_wp_error($result),'Quick customer edit on sent quote');
ck(GE_WTP_Commercial_Quotes::get($q['id'])['snapshot']===$before,'Sent snapshot unchanged after customer edit');
ck(is_wp_error(GE_WTP_Quote_Billing_Control::select($q['id'],array_merge($change,['expected_hash'=>GE_WTP_Quote_Billing_Control::hash($before)]),1)),'Sent quote selection blocked');
$mail=GE_WTP_Commercial_Quotes::email_summary($before);ck(strpos($mail,'QA VANGUARDIA')!==false&&strpos($mail,'QA Ayala')!==false,'Email uses both snapshots');
ob_start();GE_WTP_Quote_Billing_Control::summary($before);$html=ob_get_clean();ck(strpos($html,'QA VANGUARDIA')!==false&&strpos($html,'QA Ayala')!==false,'Portal summary uses both snapshots');
$pdf=GE_WTP_Commercial_Quote_PDF::build($q);ck(is_string($pdf)&&strpos($pdf,'%PDF')===0,'PDF generated');file_put_contents($qaWork.'/../outputs/quote-billing-proof.pdf',$pdf);
$order=wc_create_order(['customer_id'=>$customer]);GE_WTP_Billing_Issuers::inherit($order,$before);$order->save();$order=wc_get_order($order->get_id());
ck($order->get_meta('_ge_billing_profile_snapshot',true)===$before['receiver_snapshot'],'Order receiver snapshot exact');ck($order->get_meta('_ge_billing_issuer_snapshot',true)===$before['issuer_snapshot'],'Order issuer snapshot exact');ck($order->get_meta('_ge_customer_tax_decision',true)===$before['customer_tax_decision'],'Order resolver snapshot exact');ck($order->get_meta('_ge_quote_billing_resolution',true)===$before['billing_resolution'],'Order verification snapshot exact');
ck(GE_WTP_Quote_Billing_Control::state([])['status']==='pending','Legacy unknown stays pending');
update_post_meta($q['id'],GE_WTP_Commercial_Quotes::STATUS_META,'draft');
file_put_contents($qaWork.'/qa/context.json',json_encode(['quote'=>$q['id'],'customer'=>$customer,'order'=>$order->get_id()]));
file_put_contents($qaWork.'/../outputs/qa-results.json',json_encode(['checks'=>count($checks),'passed'=>$checks,'quote'=>$q['id'],'order'=>$order->get_id()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo 'PASS '.count($checks)."\n";
