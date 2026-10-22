<?php
// Compare the optional font change against a verified pre-change public-code snapshot.
define('ABSPATH',dirname(__DIR__));
function wp_strip_all_tags($v){return strip_tags($v);}
$current=dirname(__DIR__).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-quotes.php';require $current;
$source=file_get_contents($argv[1]);$position=strpos($source,'final class GE_WTP_Simple_PDF {');if($position===false)throw new Exception('Invalid baseline');
$source=substr($source,$position);$source=str_replace(['final class GE_WTP_Simple_PDF {','__DIR__'],['final class GE_WTP_Simple_PDF_Baseline {',var_export(dirname($current),true)],$source);
eval($source);
function sample($class){$p=new $class();$pages=[];foreach([1,2] as $i){$p->begin_page();$p->brand_symbol(40,25,48);$p->text(98,49,22,'GRAPHEX',true,27,27,32);$p->text(40,105,10,'Impresión de prueba · A/B y presupuestos');$p->text_right(555,130,14,'$ 123.456,78',true);$p->line(40,140,555,140,220,220,225);$p->fill_rect(40,150,515,20,30,30,35);$p->stroke_rect(40,180,515,50,100,100,100);$pages[]=$p->end_page();}return $p->output($pages);}
$a=sample('GE_WTP_Simple_PDF');$b=sample('GE_WTP_Simple_PDF_Baseline');if($a!==$b)throw new Exception('Default renderer changed');echo 'PASS existing unbranded-font renderer byte-identical'.PHP_EOL;
