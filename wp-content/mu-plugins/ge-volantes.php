<?php
/** Plugin Name: Graphex · Volantes Full Color
 * Description: Matriz Druck verificada, calendario y preparación de arte sobre el configurador existente.
 */
defined('ABSPATH') || exit;
final class GE_Volantes {
    const SLUG = 'volantes-full-color';
    const CALENDAR_META = '_ge_production_calendar';
    public static function init() {
        add_action('wp',array(__CLASS__,'layout'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 110);
        add_action('woocommerce_after_single_product_summary', array(__CLASS__, 'render'), 7);
        // Run after the staff-preview convenience filter; manual quotes must never be purchasable.
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_cart'), 10001, 6);
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'validate_update'), 10, 4);
        foreach(array('minimum','maximum','multiple_of') as $limit) add_filter('woocommerce_store_api_product_quantity_'.$limit,array(__CLASS__,'store_api_quantity'),10,3);
        add_filter('woocommerce_store_api_product_quantity_editable',array(__CLASS__,'store_api_editable'),10,3);
        add_action('woocommerce_store_api_validate_cart_item',array(__CLASS__,'store_api_validate'),10,2);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'cart_totals'), 40);
        add_action('woocommerce_checkout_create_order_line_item',array(__CLASS__,'item_spec'),30,4);
        add_filter('ge_wtp_file_analysis_item_context',array(__CLASS__,'analysis_context'),10,3);
        add_filter('ge_wtp_file_preflight_result',array(__CLASS__,'preflight_result'),10,3);
        add_filter('ge_wtp_production_item_assignment',array(__CLASS__,'production_assignment'),10,3);
        add_action('ge_wtp_production_product_calendar',array(__CLASS__,'production_calendar'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 30);
        add_action('admin_post_ge_volantes_calendar', array(__CLASS__, 'save_calendar'));
    }
    public static function quote_url() { return function_exists('graphexpress_quote_url') ? graphexpress_quote_url() : GE_WTP_Portal::portal_url('personalizado',array('rapida'=>'1')); }
    public static function layout() {
        if(!is_product()||get_post_field('post_name',get_queried_object_id())!==self::SLUG)return;
        remove_action('woocommerce_single_product_summary','woocommerce_template_single_title',5);
        remove_action('woocommerce_single_product_summary','woocommerce_template_single_excerpt',20);
        remove_action('woocommerce_single_product_summary',array('GE_WTP_Knowledge_Base','quick_help'),22);
        add_action('woocommerce_before_single_product',array(__CLASS__,'intro'),20);
    }
    public static function intro() {
        echo '<header class="ge-volantes-intro">';
        woocommerce_template_single_title();
        woocommerce_template_single_excerpt();
        if(class_exists('GE_WTP_Knowledge_Base'))GE_WTP_Knowledge_Base::quick_help();
        echo '</header>';
    }
    public static function product_id() { $p=get_page_by_path(self::SLUG, OBJECT, 'product'); return $p ? (int)$p->ID : 0; }
    public static function matrix() { static $d; if (null === $d) { $d=json_decode(file_get_contents(__DIR__.'/ge-volantes/druck-prices.json'),true); } return $d; }
    public static function config() {
        $d=self::matrix(); $sizes=array(); $quantities=array();
        foreach($d['rows'] as $r) { $sizes[$r['size']]=str_replace('x',' × ',$r['size']).' cm'; $quantities[(string)$r['quantity']]=number_format($r['quantity'],0,',','.'); }
        $fields=array('papel'=>array('label'=>'Papel','options'=>array('115'=>'Ilustración 115 g','80'=>'Obra 80 g','150'=>'Ilustración 150 g','otro'=>'Otro papel · cotización manual')), 'formato'=>array('label'=>'Medida final','options'=>$sizes), 'orientacion'=>array('label'=>'Orientación','options'=>array('vertical'=>'Vertical','horizontal'=>'Horizontal')), 'impresion'=>array('label'=>'Impresión','options'=>array('double'=>'Frente y dorso full color','single'=>'Frente full color')), 'cantidad'=>array('label'=>'Cantidad de volantes','options'=>$quantities));
        $options=array();$map=array();
        foreach($d['rows'] as $r) { foreach(array('vertical','horizontal') as $orientation) {
            $values=array($r['paper'],$r['size'],$orientation,$r['faces'],(string)$r['quantity']);
            $k='volantes-'.implode('-',$values); $q=(int)$r['quantity'];$total=(int)ceil($r['supplier_net']*1.5);
            $parts=explode('x',$r['size']); sort($parts,SORT_NUMERIC); $w=(int)$parts['horizontal'===$orientation?1:0]*10;$h=(int)$parts['horizontal'===$orientation?0:1]*10;
            $options[$k]=array('label'=>$fields['papel']['options'][$r['paper']].' · '.($w/10).' × '.($h/10).' cm · '.$fields['impresion']['options'][$r['faces']].' · '.number_format($q,0,',','.').' unidades','price'=>$total/$q,'fixed_qty'=>$q,'min_qty'=>$q,'step'=>$q,'total_net'=>$total,'paper'=>$r['paper'],'width_mm'=>$w,'height_mm'=>$h,'faces'=>$r['faces']);
            $map[implode('|',$values)]=$k;
        } }
        foreach($sizes as $size=>$label) { foreach(array('vertical','horizontal') as $orientation) { foreach(array('single','double') as $face) { foreach($quantities as $q=>$ql) {
            $values=array('otro',$size,$orientation,$face,(string)$q);$k='volantes-manual-'.implode('-',$values);$parts=explode('x',$size);sort($parts,SORT_NUMERIC);
            $options[$k]=array('label'=>'Otro papel · cotización manual','price'=>0,'fixed_qty'=>(int)$q,'min_qty'=>(int)$q,'step'=>(int)$q,'manual_quote'=>true,'paper'=>'otro','width_mm'=>(int)$parts['horizontal'===$orientation?1:0]*10,'height_mm'=>(int)$parts['horizontal'===$orientation?0:1]*10,'faces'=>$face);$map[implode('|',$values)]=$k;
        } } } }
        // Keep the default coherent: 115 g, 10x15, vertical, double, 1,000.
        $default='volantes-115-10x15-vertical-double-1000'; if(isset($options[$default])) { $options=array($default=>$options[$default])+$options; }
        $selectors=array();foreach($fields as $key=>$f){$selectors[]=array('key'=>$key,'label'=>$f['label'],'options'=>$f['options']);}
        return array('label'=>'Configuración','options'=>$options,'selectors'=>$selectors,'option_map'=>$map,'fixed_quantity_selector'=>true,'upload_title'=>'Archivos de producción','upload_description'=>'Subí el PDF final o las imágenes de frente y dorso. La carga es privada y se vincula a tu pedido.','upload_hint'=>'Archivo final CMYK, imágenes a 300 dpi a tamaño real, 5 mm de sangrado por lado y 5 mm de margen seguro. Doble faz: dos páginas, frente y dorso.','comments_label'=>'Aclaraciones del trabajo','comments_placeholder'=>'Indicá referencias o el papel que necesitás si elegiste cotización manual.');
    }
    public static function catalog_product() {
        $rows=array();$minimum=null;$sizes=array();$quantities=array();
        foreach(self::matrix()['rows'] as $r){$total=(int)ceil($r['supplier_net']*1.5);$minimum=null===$minimum?$total:min($minimum,$total);$sizes[$r['size']]=str_replace('x',' × ',$r['size']).' cm';$quantities[$r['quantity']]=number_format($r['quantity'],0,',','.');$rows[]=array($r['material'],str_replace('x',' × ',$r['size']).' cm',number_format($r['quantity'],0,',','.'),'double'===$r['faces']?'Frente y dorso':'Frente','$ '.number_format($total,0,',','.'));}
        return array('sku'=>'IO-VOL-002','name'=>'Volantes full color','category'=>'imprenta-offset','group'=>'volantes','description'=>'Volantes full color en Obra 80 g o Ilustración 115 g y 150 g. Elegí medida, orientación, caras y cantidad.','attributes'=>array('Papel'=>array('Obra 80 g','Ilustración 115 g','Ilustración 150 g'),'Medidas'=>array_values($sizes),'Cantidades'=>array_values($quantities),'Impresión'=>array('Frente full color','Frente y dorso full color')),'sections'=>array(array('title'=>'Volantes full color · precios netos por lote','columns'=>array('Papel','Medida','Cantidad','Impresión','Sin IVA'),'rows'=>$rows)),'notes'=>array('Precios netos + IVA 21%; diseño y envío se cotizan aparte.','115 g: salidas miércoles y viernes según corte y pedido completo.','80 g y 150 g: demora aproximada de 15 días.','Otros papeles pueden requerir más tiempo de producción; cotización manual.'),'minimum'=>$minimum,'source_name'=>'Druck · recargo Graphex 50%','source_date'=>'2026-10-08','source_files'=>array('https://www.tienda.graficadruck.com.ar/folletos-economicos-170/','https://www.tienda.graficadruck.com.ar/folletos-promocionales/','https://www.tienda.graficadruck.com.ar/folletos-premium-169/'));
    }
    public static function defaults() { return array('timezone'=>'America/Argentina/Buenos_Aires','friday_cutoff'=>'12:00','tuesday_start'=>'00:00','weekly_confirmed'=>true,'ready_requirement'=>'Pago confirmado, arte exacto aprobado y documentación lista','paper_80_days'=>15,'paper_150_days'=>15,'prepress_days'=>0,'exceptions'=>array(),'holiday_policy'=>'coordinate'); }
    public static function calendar() { return array_merge(self::defaults(),(array)get_post_meta(self::product_id(),self::CALENDAR_META,true)); }
    public static function dispatch($ready, $paper, $c=null) {
        $c=$c?:self::calendar();$tz=new DateTimeZone($c['timezone']);$at=new DateTimeImmutable($ready,$tz);$at=$at->setTimezone($tz);
        if(in_array((string)$paper,array('80','150'),true))return array('date'=>null,'label'=>'Demora aproximada de '.(int)$c['paper_'.$paper.'_days'].' días. Fecha de salida a coordinar.','status'=>'approximate');
        if('115'!==(string)$paper || empty($c['weekly_confirmed']))return array('date'=>null,'label'=>'Salidas miércoles y viernes. Confirmá el próximo lote con Graphex.','status'=>'coordinate');
        $at=$at->modify('+'.max(0,(int)$c['prepress_days']).' days');$day=(int)$at->format('N');$time=$at->format('H:i');$wed=($day>2||($day===2 && $time>=$c['tuesday_start']))&&($day<5||($day===5 && $time<$c['friday_cutoff']));
        if($wed){$friday=$at->modify('friday this week')->setTime(0,0);$target=$friday->modify('+5 days');}else{$target=$at->modify('next friday')->setTime(0,0);}
        $date=$target->format('Y-m-d');$blocked=in_array($date,$c['exceptions'],true);return array('date'=>$blocked?null:$date,'label'=>$blocked?'Salida afectada por feriado o excepción: coordinar.':'Salida orientativa: '.$target->format('d/m/Y').'. Confirmar feriados y disponibilidad. No es fecha de entrega.','status'=>$blocked?'coordinate':'conditional');
    }
    public static function validate_cart($passed,$product_id,$quantity,$variation_id=0,$variations=array(),$data=array()) {
        if((int)$product_id!==self::product_id())return $passed;
        $key=$data['ge_configuration_key']??($_POST['option_key']??'');$opts=self::config()['options'];
        if(!isset($opts[$key])||!empty($opts[$key]['manual_quote'])){wc_add_notice('Otros papeles requieren cotización manual. Consultanos antes de comprar.','error');return false;}
        if((int)$quantity!==(int)$opts[$key]['fixed_qty']){wc_add_notice('La cantidad debe coincidir con el lote cotizado.','error');return false;}return $passed;
    }
    public static function validate_update($passed,$cart_key,$item,$quantity){if((int)($item['product_id']??0)!==self::product_id()||strpos($item['ge_configuration_key']??'','volantes-')!==0)return $passed;return self::validate_cart($passed,$item['product_id'],$quantity,0,array(),$item);}
    public static function store_api_quantity($value,$product,$item){if(!$product||(int)$product->get_id()!==self::product_id()||!is_array($item))return $value;$o=self::config()['options'][$item['ge_configuration_key']??'']??array();return !empty($o['fixed_qty'])&&empty($o['manual_quote'])?(int)$o['fixed_qty']:$value;}
    public static function store_api_editable($value,$product,$item){return self::store_api_quantity(0,$product,$item)>0?false:$value;}
    public static function store_api_validate($product,$item){if(!$product||(int)$product->get_id()!==self::product_id()||strpos($item['ge_configuration_key']??'','volantes-')!==0)return;$o=self::config()['options'][$item['ge_configuration_key']]??array();if(!$o||!empty($o['manual_quote'])||(int)$item['quantity']!==(int)$o['fixed_qty'])throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('ge_volantes_lot','La cantidad debe coincidir con el lote cotizado. Volvé al producto para cambiar la configuración.',400);}
    public static function cart_totals($cart){foreach($cart->get_cart() as $item){if((int)($item['product_id']??0)!==self::product_id()||strpos($item['ge_configuration_key']??'','volantes-')!==0)continue;$net=$item['ge_base_price']??null;if(is_numeric($net)&&$net>0)$item['data']->set_price((float)$net*1.21);}}
    public static function item_spec($item,$cart_key,$values,$order){if((int)($values['product_id']??0)!==self::product_id())return;$k=$values['ge_configuration_key']??'';$o=self::config()['options'][$k]??array();if(!$o||!empty($o['manual_quote']))return;$item->add_meta_data('_ge_volantes_prepress',array('scope'=>self::SLUG,'paper'=>$o['paper'],'cut_dimensions_mm'=>array($o['width_mm'],$o['height_mm']),'target_dimensions_mm'=>array($o['width_mm']+10,$o['height_mm']+10),'minimum_dpi'=>300,'expected_color_mode'=>'CMYK','bleed_mm'=>5,'safe_mm'=>5,'minimum_font_pt'=>6,'expected_pages'=>'double'===$o['faces']?2:1),true);}
    public static function production_assignment($assignment,$item,$created){$spec=$item->get_meta('_ge_volantes_prepress',true);if(!is_array($spec)||($spec['scope']??'')!==self::SLUG)return $assignment;return array('supplier'=>'druck','date'=>'','requires_confirmation'=>true,'reason'=>'Volantes: confirmar el lote al completar pago, arte aprobado y documentación. La fecha de creación no es la toma del pedido.');}
    public static function production_calendar($order){foreach($order->get_items('line_item') as $item){$spec=$item->get_meta('_ge_volantes_prepress',true);if(!is_array($spec)||($spec['scope']??'')!==self::SLUG)continue;$dispatch=self::dispatch(gmdate('c'),$spec['paper']);echo '<section class="ge-production-card"><h2>Volantes · lote de producción</h2><p>'.esc_html($dispatch['label']).'</p><p>Esta previsión parte de completar el pedido ahora. Confirmá pago, arte exacto aprobado y documentación antes de ingresar una fecha prometida. Salida no equivale a entrega; los feriados se coordinan.</p><p><a href="'.esc_url(admin_url('admin.php?page=ge-volantes-calendar')).'">Editar calendario de volantes</a></p></section>';}}
    public static function analysis_context($context,$item,$product){if(!$item||!$product||$product->get_slug()!==self::SLUG)return $context;$spec=$item->get_meta('_ge_volantes_prepress',true);return is_array($spec)&&($spec['scope']??'')===self::SLUG?array_merge($context,$spec):$context;}
    public static function preflight_result($result,$facts,$context) {
        if (($context['scope']??'')!==self::SLUG) return $result;
        $target=$context['target_dimensions_mm']??array(); $actual=$facts['page_size_mm']??array();
        $exact='UNVERIFIED';
        if(count($actual)===2&&count($target)===2) $exact=abs($actual[0]-$target[0])<=0.5&&abs($actual[1]-$target[1])<=0.5?'PASS':'WARNING';
        if(!empty($facts['multiple_page_sizes'])) $exact='WARNING';
        $result['checks'][]=array('code'=>'volantes_exact_file_dimensions','status'=>$exact);
        // A separate JPG/PNG represents one face. Its page count cannot certify the complete lot.
        $is_image=strpos($facts['mime_type']??'','image/')===0;
        $faces=$is_image||!isset($facts['page_count'])?'UNVERIFIED':((int)$facts['page_count']===(int)$context['expected_pages']?'PASS':'WARNING');
        $result['checks'][]=array('code'=>'volantes_faces','status'=>$faces);
        foreach(array('font_size_6pt','font_stroke_weight','actual_background_bleed','safe_content_margin') as $code) $result['checks'][]=array('code'=>$code,'status'=>'UNVERIFIED');
        $states=array_column($result['checks'],'status');
        foreach(array('BLOCKER','WARNING','UNVERIFIED','PASS') as $state) if(in_array($state,$states,true)) {$result['status']=$state;break;}
        return $result;
    }
    public static function assets(){if(!is_product()||get_post_field('post_name',get_queried_object_id())!==self::SLUG)return;$b=content_url('/mu-plugins/ge-volantes/');wp_enqueue_style('ge-volantes',$b.'volantes.css',array(),filemtime(__DIR__.'/ge-volantes/volantes.css'));wp_enqueue_script('ge-volantes',$b.'volantes.js',array(),filemtime(__DIR__.'/ge-volantes/volantes.js'),true);wp_localize_script('ge-volantes','geVolantes',array('calendar'=>self::calendar(),'quoteUrl'=>self::quote_url(),'spec'=>array('bleed_mm'=>5,'safe_mm'=>5,'minimum_font_pt'=>6,'dpi'=>300)));$preview=__DIR__.'/ge-cards-experience/product-preview.js';wp_enqueue_script('ge-volantes-preview',content_url('/mu-plugins/ge-cards-experience/product-preview.js'),array(),filemtime($preview),true);wp_localize_script('ge-volantes-preview','geCardsProduct',array('productSelector'=>'#product-'.get_queried_object_id(),'selectionScope'=>substr(wp_hash((string)get_current_user_id()),0,16),'productId'=>get_queried_object_id()));}
    public static function render(){global $product;if(!$product||$product->get_slug()!==self::SLUG)return; ?>
        <section class="ge-volantes-guide" aria-labelledby="ge-volantes-title"><h3 id="ge-volantes-title">Tu volante, bien preparado</h3><p data-gv-format>Elegí la medida y la orientación para ver las dimensiones del archivo.</p><p data-gv-schedule aria-live="polite">115 g: salidas miércoles y viernes. 80 g y 150 g: aproximadamente 15 días.</p><p>El pedido se toma con pago confirmado, arte exacto aprobado y documentación lista. La salida de producción no es la entrega ni el envío. Feriados y excepciones se coordinan.</p><p data-gv-manual hidden><strong>Otros papeles pueden requerir más tiempo de producción.</strong> <a href="<?php echo esc_url(self::quote_url()); ?>">Solicitá una cotización manual</a>.</p><details><summary>Si vas a diseñar con IA · Ver prompt</summary><h4>Agregá esto a tu prompt</h4><p>Estas son las especificaciones de recepción de Graphex para volantes. Copialas después de elegir la configuración.</p><textarea data-gv-prompt readonly rows="14" aria-label="Prompt para diseñar tu volante"></textarea><button type="button" data-gv-copy>Copiar prompt</button><span data-gv-copy-status role="status"></span></details><details><summary>Exportación y revisión del archivo</summary><p>Exportá PDF con fuentes incrustadas o convertidas a curvas. Archivo final CMYK, imágenes a 300 dpi al tamaño real. No agregues marcas de corte dentro del arte. Si la IA genera RGB o un tamaño incorrecto, ajustá el documento y convertí o exportá desde un editor; escribir «CMYK» o «300 dpi» en un prompt no acredita el archivo.</p><p>Extendé fondos e imágenes al sangrado real; no agrandes todo el diseño para simularlo. Texto y logos importantes dentro del área segura. Tipografía mínima de 6 pt, con trazos medios o gruesos; títulos y textos principales más grandes.</p><p>Subí el archivo para su análisis y prueba de la versión exacta. El análisis disponible informa dimensiones y resolución cuando puede detectarlas. La fidelidad del color, las fuentes de 6 pt, el grosor de los trazos y la extensión real del fondo requieren revisión de arte. La carga no equivale a aprobación: se produce después de la prueba y aprobación del diseño.</p></details></section>
        <?php }
    public static function admin_menu(){add_submenu_page('ge-backoffice','Calendario de volantes','Calendario de volantes','manage_woocommerce','ge-volantes-calendar',array(__CLASS__,'admin'));}
    public static function admin(){if(!current_user_can('manage_woocommerce'))return;$c=self::calendar(); ?><div class="wrap"><h1>Volantes · Calendario de producción</h1><p>Configuración del producto, separada de los pedidos históricos. Zona horaria: Buenos Aires. Salida ≠ entrega. Los feriados se coordinan.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ge_volantes_calendar"><?php wp_nonce_field('ge_volantes_calendar'); ?><table class="form-table"><tr><th>Corte del viernes</th><td><input type="time" name="friday_cutoff" required value="<?php echo esc_attr($c['friday_cutoff']); ?>"></td></tr><tr><th>Inicio del martes</th><td><input type="time" name="tuesday_start" required value="<?php echo esc_attr($c['tuesday_start']); ?>"></td></tr><tr><th>Calendario semanal confirmado</th><td><label><input type="checkbox" name="weekly_confirmed" value="1" <?php checked(!empty($c['weekly_confirmed'])); ?>> Martes–viernes antes del corte → miércoles después de ese viernes; viernes desde el corte–lunes → viernes siguiente.</label></td></tr><?php foreach(array('paper_80_days'=>'Demora aproximada 80 g','paper_150_days'=>'Demora aproximada 150 g','prepress_days'=>'Días adicionales de preparación del arte') as $k=>$label): ?><tr><th><?php echo esc_html($label); ?></th><td><input type="number" min="0" max="90" required name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($c[$k]); ?>"> días</td></tr><?php endforeach; ?><tr><th>Feriados / excepciones de salida</th><td><textarea name="exceptions" rows="6" cols="30" placeholder="AAAA-MM-DD, una fecha por línea"><?php echo esc_textarea(implode("\n",$c['exceptions'])); ?></textarea><p>Una salida que coincide con estas fechas queda a coordinar; no se mueve automáticamente.</p></td></tr><tr><th>Pedido completo</th><td><?php echo esc_html($c['ready_requirement']); ?></td></tr></table><?php submit_button('Guardar calendario'); ?></form></div><?php }
    public static function save_calendar(){if(!current_user_can('manage_woocommerce'))wp_die('Sin permiso',403);check_admin_referer('ge_volantes_calendar');$c=self::calendar();foreach(array('friday_cutoff','tuesday_start') as $k){$v=sanitize_text_field(wp_unslash($_POST[$k]??''));if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$v))wp_die('Hora inválida');$c[$k]=$v;}foreach(array('paper_80_days','paper_150_days','prepress_days') as $k)$c[$k]=max(0,min(90,(int)($_POST[$k]??0)));$c['weekly_confirmed']=!empty($_POST['weekly_confirmed']);$c['exceptions']=array();foreach(preg_split('/\s+/',trim(sanitize_textarea_field(wp_unslash($_POST['exceptions']??'')))) as $date){$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if($dt&&$dt->format('Y-m-d')===$date)$c['exceptions'][]=$date;}update_post_meta(self::product_id(),self::CALENDAR_META,$c);wp_safe_redirect(admin_url('admin.php?page=ge-volantes-calendar&saved=1'));exit;}
}
GE_Volantes::init();
