<?php
require __DIR__.'/quote-steps-draft.php';
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quotes.php';
require __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-ui.php';
require_once __DIR__.'/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-pdf.php';
class GE_WTP_Portal { public static function portal_url($section,$args=array()) { return 'https://example.invalid/portal/?presupuesto='.($args['presupuesto']??10); } }
function number_format_i18n($value,$decimals=0){return number_format($value,$decimals,',','.');}
function wp_strip_all_tags($value){return strip_tags($value);}
function remove_query_arg($keys,$url){return $url;}
$base=GE_WTP_Commercial_Quotes::build_snapshot(array(array('source_type'=>'custom','name'=>'QA producto','quantity'=>'3','unit_net'=>'100.00')),array('notes_internal'=>'PRIVATE_INTERNAL_MARKER','notes_customer'=>'QA notas comerciales','deposit_enabled'=>false));
$base['total_cents']=$base['net_cents'];$base['tax_cents']=0;
$base['receiver_snapshot']=array('legal_name'=>'QA cliente sintético','billing_email'=>'qa@example.invalid','cuit'=>'');
$quote=array('id'=>10,'number'=>'QA-10','customer_id'=>2,'version'=>1,'snapshot'=>$base);
$off=GE_WTP_Commercial_Quote_PDF::build($quote);
ck_step(!is_wp_error($off) && strncmp($off,'%PDF',4)===0,'Actual PDF generator works offline');
ck_step(strpos($off,'PRIVATE_INTERNAL_MARKER')===false,'Internal note excluded from PDF');
ck_step(strpos($off,'QA notas comerciales')!==false,'Commercial note included');
ck_step(substr_count($off,'/Type /Page ')===1,'Short quote is one page');
$quote['snapshot']['deposit_enabled']=true;
$on=GE_WTP_Commercial_Quote_PDF::build($quote);
ck_step(!is_wp_error($on) && strlen($on)>strlen($off),'Deposit on adds payment rows');
if(!empty($argv[1])){file_put_contents($argv[1].'/presupuesto-qa-sena-off.pdf',$off);file_put_contents($argv[1].'/presupuesto-qa-sena-on.pdf',$on);}
echo "quote-steps-pdf: 5 checks OK\n";
