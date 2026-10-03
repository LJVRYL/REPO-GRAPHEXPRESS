<?php
$_SERVER['HTTP_HOST']='127.0.0.1:18816';require '/home/leo/graph-org-foundation-v1-minimal/site/wp-load.php';
if(DB_NAME!=='graph_org_foundation_20261002')exit('WRONG_DB');global $wpdb;$checks=array();
function v($x,$n){global $checks;$checks[$n]=(bool)$x;if(!$x)throw new Exception($n);}
wp_set_current_user(1);$wpdb->suppress_errors(true);
$x=$wpdb->get_results('SELECT ID FROM ge_org_empresa_qa.wp_users LIMIT 1');v(!$x && $wpdb->last_error!=='','graph_database_cannot_read_qa');
$x=$wpdb->query("UPDATE ge_org_empresa_qa.wp_users SET display_name='DENIED' WHERE ID=1");v($x===false,'graph_database_cannot_write_qa');$wpdb->suppress_errors(false);
v(!get_users(array('search'=>'*empresa-qa.invalid*','search_columns'=>array('user_email'),'number'=>1)),'graph_user_directory_has_no_qa_users');
$test=json_decode(file_get_contents(dirname(__DIR__).'/outputs/tenant-acceptance-results.json'),true);
$q=GE_WTP_Commercial_Quotes::get($test['quote_id'],1);v(is_wp_error($q)||($q['snapshot']['organization_snapshot']['organization_id']??'')!=='empresa-qa','qa_quote_not_resolved_in_graph');
v(!isset(GE_WTP_Billing_Issuers::all()['qa-emisor']),'graph_no_qa_issuer');
v(!isset(GE_WTP_Production::suppliers()[$test['supplier_key']]),'graph_no_qa_supplier');
v(!is_file(GE_WTP_Documents::private_directory().'/'.$test['document_name']),'graph_no_qa_private_file');
$baseline=dirname(__DIR__,2).'/referenced-chatgpt-conversation-this-is-an-15/work/release/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-pdf.php';
$s=file_get_contents($baseline);$s=str_replace("require_once __DIR__ . '/class-ge-wtp-billing-issuers.php';",'', $s);$s=str_replace('GE_WTP_Commercial_Quote_PDF','GE_WTP_Commercial_Quote_PDF_Baseline',$s);eval(substr($s,5));
$q=GE_WTP_Commercial_Quotes::get(986);v(!is_wp_error($q),'graph_existing_quote986');
v(hash('sha256',GE_WTP_Commercial_Quote_PDF::build($q))===hash('sha256',GE_WTP_Commercial_Quote_PDF_Baseline::build($q)),'graph_historical_pdf_byte_identical');
$out=dirname(__DIR__).'/outputs';file_put_contents($out.'/reciprocal-isolation-results.json',wp_json_encode($checks,JSON_PRETTY_PRINT));echo 'RECIPROCAL_QA_PASS '.count($checks).PHP_EOL;
