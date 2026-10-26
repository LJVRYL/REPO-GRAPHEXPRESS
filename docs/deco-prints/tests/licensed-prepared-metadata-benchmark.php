<?php
// Prepared endpoint metadata loop; intentionally never hashes or opens masters.
if(PHP_SAPI!=='cli')exit(2);
$o=getopt('',array('private-root:','registry-plugin-root:','gallery-source:','expected-active:','expected-hidden:'));
foreach(array('private-root','registry-plugin-root','expected-active','expected-hidden') as $k)if(!isset($o[$k]))throw new InvalidArgumentException('Missing --'.$k);
$repo=dirname(__DIR__,3);define('ABSPATH',rtrim(realpath($o['registry-plugin-root']),'/\\').'/');define('GE_DECO_PRIVATE_ROOT',realpath($o['private-root']));define('GE_WTP_PLUGIN_DIR',rtrim(realpath($o['registry-plugin-root']),'/\\').'/');
function rest_url($p){return 'https://qa.invalid/'.$p;}
require $o['gallery-source']??$repo.'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-deco-gallery.php';
$t=microtime(true);$rows=array();$active=0;$hidden=0;$works=GE_WTP_Deco_Gallery::works();
// Returned arrays must not let a caller mutate the per-request source of truth.
$copy=$works;$first=array_key_first($copy);$copy[$first]['title']='qa-mutation';if((GE_WTP_Deco_Gallery::works()[$first]['title']??'')==='qa-mutation')throw new RuntimeException('Registry map leaked mutation');
foreach($works as $id=>$unused){$r=GE_WTP_Deco_Gallery::record($id);if(!$r)throw new RuntimeException('Real record absent: '.$id);$w=GE_WTP_Deco_Gallery::public_work($r);$active+=($w['sales_enabled']===true);$hidden+=!empty($w['catalog_hidden']);foreach(array('rights_evidence','provenance','local_quality_review','source_sha256','private_asset_ref') as $k)if(isset($w[$k]))throw new RuntimeException('Private metadata exposed');$rows[]=$w;}
$seconds=microtime(true)-$t;if($active!==(int)$o['expected-active']||$hidden!==(int)$o['expected-hidden'])throw new RuntimeException('Curation mismatch');$json=json_encode(array('works'=>$rows));
echo json_encode(array('passed'=>true,'real_records'=>count($rows),'active'=>$active,'hidden'=>$hidden,'prepared_loop_seconds'=>$seconds,'prepared_json_bytes'=>strlen($json),'prepared_json_sha256'=>hash('sha256',$json),'peak_memory_bytes'=>memory_get_peak_usage(true),'masters_opened'=>0,'payload_writes'=>0,'database_writes'=>0,'network_calls'=>0),JSON_PRETTY_PRINT).PHP_EOL;
