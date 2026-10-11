<?php
define('ABSPATH',__DIR__.'/');
define('GE_WTP_PLUGIN_DIR',dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/');
function add_shortcode(){}function add_action(){}function add_filter(){}
function content_url($p){return 'https://graphex.ar/wp-content'.$p;}function home_url($p){return 'https://graphex.ar'.$p;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_attr($s);}function wp_json_encode($s){return json_encode($s);}
function sanitize_title($s){return strtolower($s);}
require GE_WTP_PLUGIN_DIR.'includes/class-ge-wtp-windbanners-catalog.php';
require dirname(__DIR__).'/wp-content/mu-plugins/ge-design-guides.php';
$checks=0;function ok($v,$m){global $checks;if(!$v)throw new Exception($m);$checks++;}
$rows=GE_Design_Guides::catalog();$families=array_filter(GE_WTP_Windbanners_Catalog::products(),function($v){return !empty($v['options']);});
ok(count($rows)===164,'164 active options');ok(count($families)===25,'25 families');$seen=array();
foreach($families as $key=>$data){foreach($data['options'] as $option=>$v)$seen[$option]=$key;}
foreach($rows as $row){
    ok(isset($seen[$row['option_key']]),'existing stable key');ok($seen[$row['option_key']]===$row['family'],'exact family association');
    ok(is_file(dirname(__DIR__).'/wp-content/mu-plugins/ge-design-guides/guia-'.$row['family'].'.pdf'),'family PDF exists');
    if($row['supplier_template_url']){ok(strpos($row['supplier_template_url'],'https://grupowindbanners.com.ar/plantillas_productos/')===0,'supplier original URL');ok(strlen($row['source_sha256'])===64,'source hash');}
    ok(null===$row['graphex_template_1to1'],'no unverified textile geometry claimed');
}
$product=new class {function get_meta($key){return 'windbanners-fly-gota';}};
$selected=GE_Design_Guides::wind_rows($product);ok(count($selected)===5,'five exact Gota configurations');
ok(count(array_filter($selected,function($r){return $r['status']==='supplier_label_conflict';}))===1,'one label conflict');
ob_start();GE_Design_Guides::wind_panel();$panel=ob_get_clean();ok(strpos($panel,'data-gx-design-options')!==false,'selected-option contract');
$index=GE_Design_Guides::index();ok(substr_count($index,'class="gx-design-family"')===25,'25 collapsed groups');
ok(strpos($index,'Base limpia')!==false,'clean bases visible');ok(strpos($index,'precio')===false,'index no pricing logic');
file_put_contents(dirname(__DIR__).'/../index-fragment.html',$index);
echo "PASS $checks design guide checks\n";
