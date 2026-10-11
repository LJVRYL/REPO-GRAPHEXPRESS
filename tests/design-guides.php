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
$rows=GE_Design_Guides::catalog();$families=GE_WTP_Windbanners_Catalog::products();$seen=array();
foreach($families as $key=>$data){foreach($data['options'] as $option=>$v)$seen[$option]=$key;}
ok(count($rows)===164,'164 stable options');
foreach($rows as $row){
    ok(isset($seen[$row['option_key']]),'existing option');
    ok($seen[$row['option_key']]===$row['family'],'exact family association');
    ok(!isset($row['supplier_template_url'])&&!isset($row['source_url']),'no third-party public source');
    ok(null===$row['graphex_template_1to1'],'no invented geometry');
}
ok(count(array_filter($rows,function($r){return $r['status']==='awaiting_validated_graphex_geometry';}))===158,'158 printed options await validated geometry');
$product=new class {function get_meta($key){return 'windbanners-fly-gota';}};
ok(count(GE_Design_Guides::wind_rows($product))===5,'five stable Gota options');
ob_start();GE_Design_Guides::wind_panel();$panel=ob_get_clean();
ok(strpos($panel,'guia-textiles-graphex.pdf')!==false,'own textile guide');
ok(strpos($panel,'Solicitar plantilla por medida')!==false,'pending geometry request');
ok(stripos($panel,'proveedor')===false&&stripos($panel,'PDF original')===false,'no original document promotion');
$index=GE_Design_Guides::index();
ok(stripos($index,'windbanner')===false&&stripos($index,'grupowind')===false,'own index wording');
ok(strpos($index,'guia-textiles-graphex.pdf')!==false,'own textile download');
ok(strpos($index,'Base limpia')!==false,'clean bases remain available');
echo "PASS $checks own-design guide checks\n";
