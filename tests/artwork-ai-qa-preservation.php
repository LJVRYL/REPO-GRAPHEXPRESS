<?php
$site=getenv('GE_QA_WP_ROOT'); if (!$site) { throw new Exception('Set GE_QA_WP_ROOT to an isolated restore'); } require $site.'/wp-load.php';
if(DB_NAME!=='graph_restore_v2')throw new Exception('isolation');
$c=json_decode(file_get_contents(__DIR__.'/qa-context.json'),true);wp_set_current_user(0);$before=GE_WTP_Documents::get_documents($c['order_id']);$after=GE_WTP_Documents::get_documents_with_analysis($c['order_id']);if(count($before)!==3||count($after)!==3||count(wc_get_order($c['order_id'])->get_meta(GE_WTP_Documents::META_KEY,true))!==3)throw new Exception('History dropped');echo "PASS customer context keeps raw history for mutations\n";
ob_start();GE_WTP_Documents::render_customer_order_files(wc_get_order($c['order_id']));$html=ob_get_clean();if(strpos($html,$c['candidate_version_id'])!==false)throw new Exception('Discarded version leaked');echo "PASS customer render excludes discarded candidate\n";
$d=GE_WTP_Documents::find_version($c['order_id'],$c['candidate_version_id']);if(GE_WTP_Documents::customer_visible($d))throw new Exception('visible discarded');echo "PASS exact visibility rule\n";
if(hash_file('sha256',$c['source_path'])!==$c['checksum'])throw new Exception('Original changed');echo "PASS original intact\n";
