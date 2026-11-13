<?php
if(!defined('ABSPATH')) exit;
final class GE_WTP_Operations_UI {
    private static function e($v) { return esc_html((string)$v); }
    private static function url($section,$args=array()) { return GE_WTP_Staff_Portal::portal_url($section,$args); }
    private static function table($headers,$rows) {
        echo '<div class="ge-op-table-wrap"><table class="ge-op-table"><thead><tr>'; foreach($headers as $h) echo '<th scope="col">'.self::e($h).'</th>'; echo '</tr></thead><tbody>';
        foreach($rows as $row) { echo '<tr>'; foreach($row as $cell) echo '<td>'.$cell.'</td>'; echo '</tr>'; }
        if(!$rows) echo '<tr><td colspan="'.count($headers).'">Todavía no hay registros para esta selección.</td></tr>'; echo '</tbody></table></div>';
    }
    private static function field($name,$label,$type='text',$value='',$required=true) { if($type==='textarea') { echo '<label>'.self::e($label).'<textarea name="'.esc_attr($name).'" '.($required?'required':'').'>'.self::e($value).'</textarea></label>';return; } echo '<label>'.self::e($label).'<input name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($value).'" '.($required?'required':'').' '.($type==='number'?'min="0" step="0.0001"':'').'></label>'; }
    private static function select($name,$label,$options,$current='') { echo '<label>'.self::e($label).'<select name="'.esc_attr($name).'">'; foreach($options as $v=>$text) echo '<option value="'.esc_attr($v).'" '.selected((string)$current,(string)$v,false).'>'.self::e($text).'</option>'; echo '</select></label>'; }
    private static function start_form($action,$section,$title,$id=null) {
        echo '<details class="ge-op-form-panel" id="op-'.esc_attr(str_replace('.','-',$action)).'"><summary>'.self::e($title).'</summary><form class="ge-op-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ge_operations');
        foreach(array('action'=>'ge_operations','op'=>$action,'return_section'=>$section,'idempotency_key'=>wp_generate_uuid4()) as $k=>$v) echo '<input type="hidden" name="'.$k.'" value="'.esc_attr($v).'">';
        if($id) echo '<input type="hidden" name="id" value="'.(int)$id.'">';
    }
    private static function end_form($label='Guardar') { echo '<div class="ge-op-submit"><button type="submit" class="ge-staff-button">'.self::e($label).'</button></div></form></details>'; }
    private static function heading($title,$desc) { echo '<div class="ge-staff-heading"><div><span>OPERACIÓN GRAPHEX</span><h1>'.self::e($title).'</h1><p>'.self::e($desc).'</p></div></div>'; }
    private static function money($minor,$currency='ARS') { return self::e($currency.' '.number_format_i18n((int)$minor/100,2)); }
    private static function unit($unit) { return array('sheet'=>'Hoja','unit'=>'Unidad','m'=>'Metro','m2'=>'m²','roll'=>'Rollo','box'=>'Caja','kit'=>'Kit','ml'=>'ml','l'=>'Litro','kg'=>'kg','cartridge'=>'Cartucho','ribbon'=>'Ribbon')[$unit]??$unit; }
    private static function categories() { return array('paper'=>'Papel','cards'=>'Tarjetas PVC','ribbons'=>'Ribbons','labels'=>'Etiquetas','ink'=>'Tintas','toner'=>'Tóner','binding'=>'Anillado / binder','laminate'=>'Laminado','pins'=>'Pines y llaveros','accessories'=>'Accesorios','finishing'=>'Terminaciones','rent'=>'Alquiler','utilities'=>'Servicios','software'=>'Software','taxes'=>'Impuestos / tasas','maintenance'=>'Mantenimiento','logistics'=>'Logística','marketing'=>'Publicidad','fees'=>'Honorarios','other'=>'Otros'); }
    private static function notice() {
        if('saved'===($_GET['op_notice']??'')) echo '<p class="ge-production-notice" role="status">Registro guardado con auditoría.</p>';
        if('error'===($_GET['op_notice']??'')) { $error=get_transient('ge_op_error_'.get_current_user_id()); delete_transient('ge_op_error_'.get_current_user_id()); echo '<p class="ge-sw-warning" role="alert">'.self::e($error?:'No se pudo guardar. Revisá los datos.').'</p>'; }
    }
    public static function render($section) {
        if(!GE_WTP_Operations::module_enabled($section)) { echo '<p>Módulo pendiente de activación.</p>'; return; }
        try { GE_WTP_Operations::permission($section==='administration'?'finance':($section==='suppliers'?'supplier':'stock')); self::notice(); call_user_func(array(__CLASS__,$section)); }
        catch(Throwable $e) { echo '<p role="alert">'.self::e($e->getMessage()).'</p>'; }
    }
    private static function supplier_options() { $out=array(); foreach(GE_WTP_Operations::suppliers() as $id=>$s) $out[$id]=$s['name']; return $out; }
    private static function item_options() { $out=array(); foreach(GE_WTP_Operations_Stock::items() as $r) $out[$r['id']]=$r['name'].' · '.self::unit($r['unit']).' · '.$r['sku'].' · '.($r['format']?:'variante pendiente'); return $out; }
    public static function stock() {
        self::heading('Stock','Insumos, compras, consumos y equipos. El inventario inicial se confirma mediante un conteo físico.'); self::laminate_report(); $signals=GE_WTP_Operations_Stock::consumption_suggestions(); if($signals) { echo '<p class="ge-op-hint">Consumo acelerado · revisar reposición: ';foreach($signals as $signal)echo self::e($signal['name']).' · ';echo '</p>'; }
        echo '<div class="ge-op-actions"><a href="#op-graph-stock-item-save">Nuevo / editar insumo</a><a href="'.esc_url(self::url('stock',array('view'=>'movements'))).'">Registrar movimiento</a><a href="'.esc_url(self::url('stock',array('view'=>'purchases'))).'">Nueva compra</a></div>';
        $view=sanitize_key($_GET['view']??'items'); echo '<nav class="ge-v3-tabs" aria-label="Stock">'; foreach(array('items'=>'Insumos','movements'=>'Movimientos','purchases'=>'Compras','equipment'=>'Equipos','recipes'=>'Recetas') as $key=>$text) echo '<a '.($key===$view?'aria-current="page" class="is-active"':'').' href="'.esc_url(self::url('stock',array('view'=>$key))).'">'.self::e($text).'</a>'; echo '</nav>';
        if($view==='equipment') { self::equipment(); return; } if($view==='recipes') { self::recipes(); return; }
        $items=GE_WTP_Operations_Stock::items(); $low=GE_WTP_Operations_Stock::low_stock(); $unknown=count(array_filter($items,function($i){return !$i['stock_known'];})); $replenish=GE_WTP_Operations_Stock::replenishment_suggestions(); if($replenish) echo '<p class="ge-op-hint">'.count($replenish).' insumos requieren revisar reposición según su punto configurado.</p>';
        echo '<section class="ge-admin-stats"><article><span>Insumos activos</span><strong>'.count($items).'</strong></article><article><span>Bajo mínimo</span><strong>'.count($low).'</strong></article><article><span>Pendientes de inventario</span><strong>'.$unknown.'</strong><small>Desconocido no equivale a agotado</small></article></section>';
        if($view==='purchases') { self::purchases(); return; }
        if($view==='movements') { $rows=array(); foreach(GE_WTP_Operations_Stock::movements(array()) as $m) $rows[]=array(self::e($m['created_at']),self::e($m['item_id']),self::e(self::movement_labels()[$m['movement_type']]??$m['movement_type']),self::e($m['quantity']),self::e($m['order_id']?:'—'),self::e($m['note'])); self::table(array('Fecha UTC','Insumo','Movimiento','Cantidad','Pedido','Motivo'),$rows); self::movement_form(); return; }
        $q=sanitize_text_field(wp_unslash($_GET['q']??'')); $category=sanitize_key($_GET['category']??''); $state=sanitize_key($_GET['state']??'');
        echo '<form class="ge-v3-toolbar ge-op-filter" method="get"><input type="hidden" name="section" value="stock">'; self::field('q','Buscar insumo','search',$q,false); $cats=array(''=>'Todas'); foreach($items as $i) $cats[$i['category']]=self::categories()[$i['category']]??$i['category']; self::select('category','Categoría',$cats,$category); self::select('state','Estado',array(''=>'Todos','unknown'=>'Sin contar','low'=>'Bajo mínimo','empty'=>'Agotados'),$state); echo '<button type="submit">Filtrar</button><a href="'.esc_url(self::url('stock')).'">Limpiar</a></form>';
        $rows=array(); foreach($items as $i) {
            if($q&&stripos($i['name'].' '.$i['sku'].' '.$i['format'],$q)===false||$category&&$category!==$i['category']) continue;
            $known=(bool)$i['stock_known']; $available=$i['balance']['available']; $isLow=$known&&$i['minimum_qty']!==null&&(float)$available<(float)$i['minimum_qty']; $empty=$known&&(float)$available===0.0;
            if($state==='unknown'&&$known||$state==='low'&&!$isLow||$state==='empty'&&!$empty) continue;
            $meta=json_decode($i['metadata']??'',true)?:array();
            $rows[]=array('<a href="'.esc_url(self::url('stock',array('item_id'=>$i['id']))).'">'.self::e($i['name']).'</a><small>'.self::e($i['sku'].' · '.($i['format']?:'Variante requerida')).'</small>',self::e(self::unit($i['unit'])),$known?self::e($i['balance']['physical']):'<span class="ge-op-badge">Sin contar</span>',self::e($i['balance']['reserved']),$known?self::e($available):'—',self::e($i['minimum_qty']??'Sin definir'),self::e($meta['location']??''));
        } self::table(array('Insumo / variante','Unidad','Físico','Reservado','Disponible','Mínimo','Ubicación'),$rows);
        $edit=!empty($_GET['item_id'])?GE_WTP_Operations_Stock::item_get(absint($_GET['item_id'])):array(); self::item_form($edit?:array()); self::movement_form($edit['id']??null);
        echo '<p class="ge-op-hint">Reposición sugerida: '.count($low).' insumo(s). Las compras se confirman manualmente. Los formatos pendientes deben completarse antes de registrar cantidades.</p>';
    }
    private static function item_form($item) {
        $item=array_merge($item,json_decode($item['metadata']??'',true)?:array());
        self::start_form('graph.stock.item.save','stock',$item?'Editar insumo':'Nuevo insumo',$item['id']??null);
        self::field('sku','Código interno','text',$item['sku']??''); self::field('name','Nombre','text',$item['name']??''); self::select('category','Categoría',array_intersect_key(self::categories(),array_flip(array('paper','cards','ribbons','labels','ink','toner','binding','laminate','pins','accessories','finishing','other'))),$item['category']??'other');
        self::select('unit','Unidad',array('sheet'=>'Hoja','unit'=>'Unidad','m'=>'Metro lineal','m2'=>'Metro cuadrado','roll'=>'Rollo','box'=>'Caja','kit'=>'Kit','ml'=>'ml','l'=>'Litro','kg'=>'kg','cartridge'=>'Cartucho','ribbon'=>'Ribbon'),$item['unit']??'unit');
        self::field('format','Variante / formato','text',$item['format']??'',false); self::field('minimum_qty','Stock mínimo','number',$item['minimum_qty']??'',false); self::field('reorder_qty','Punto de reposición','number',$item['reorder']??'',false); self::field('location','Ubicación','text',$item['location']??'',false); self::select('preferred_supplier_id','Proveedor preferido',array(''=>'Sin definir')+self::supplier_options(),$item['preferred_supplier_id']??''); echo '<label>Proveedores alternativos<select name="alternate_supplier_ids[]" multiple>'; foreach(self::supplier_options() as $sid=>$name) echo '<option value="'.(int)$sid.'" '.selected(in_array((int)$sid,(array)($item['alternate_supplier_ids']??array()),true),true,false).'>'.self::e($name).'</option>'; echo '</select></label>'; self::field('notes','Notas','text',$item['notes']??'',false); self::select('active','Estado',array('1'=>'Activo','0'=>'Inactivo'),$item['active']??1); self::end_form();
    }
    private static function movement_labels() { return array('opening'=>'Conteo físico verificado','receipt'=>'Entrada','reservation'=>'Reserva','release'=>'Liberar reserva','consumption'=>'Consumo directo','consume_reserved'=>'Consumir reserva','waste'=>'Merma','return_in'=>'Devolución de producción','return_supplier'=>'Devolución a proveedor','writeoff'=>'Baja','adjustment_in'=>'Ajuste positivo','adjustment_out'=>'Ajuste negativo'); }
    private static function movement_form($id=null) {
        self::start_form('graph.stock.movement.record','stock','Registrar movimiento'); self::select('item_id','Insumo',self::item_options(),$id); self::select('type','Movimiento',self::movement_labels()); self::field('quantity','Cantidad','number'); self::field('order_id','Pedido / trabajo · opcional','number','',false); self::select('currency','Moneda del costo',array('ARS'=>'ARS','USD'=>'USD')); self::field('unit_cost','Costo unitario confirmado · opcional','number','',false); self::field('note','Motivo / referencia de conteo');echo '<p class="ge-op-hint">Una devolución a proveedor cambia el inventario. El crédito o reverso financiero se registra por separado con su comprobante.</p>'; self::end_form('Registrar movimiento');
    }
    private static function purchases() {
        $rows=array(); $lines=array(); foreach(GE_WTP_Operations_Stock::purchases(!empty($_GET['supplier_id'])?array('supplier_id'=>absint($_GET['supplier_id'])):array()) as $p) { $s=GE_WTP_Operations::suppliers()[$p['supplier_id']]??array(); $rows[]=array(self::e('#'.$p['id']),self::e($s['name']??'Proveedor'),self::e(array('ordered'=>'Pendiente','partially_received'=>'Recepción parcial','received'=>'Recibida')[$p['status']]??$p['status']),self::e($p['currency']),self::e($p['due_date']??'')); $detail=GE_WTP_Operations_Stock::purchase_get($p['id']); foreach($detail['lines']??array() as $l) { $item=GE_WTP_Operations_Stock::item_get($l['item_id']); $lines[$l['id']]='#'.$p['id'].' · '.$item['name'].' · pendiente '.((float)$l['quantity']-(float)$l['received_qty']).' '.$l['unit']; } }
        self::table(array('Compra','Proveedor','Recepción','Moneda','Vencimiento'),$rows);
        self::start_form('graph.stock.purchase.create','stock','Nueva compra a proveedor'); self::select('supplier_id','Proveedor',self::supplier_options()); self::select('item_id','Insumo',self::item_options()); self::field('quantity','Cantidad','number'); self::field('unit_cost','Costo unitario · dejar vacío si falta','number','',false); self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD')); self::field('due_date','Vencimiento acordado','date','',false); self::end_form('Registrar compra');
        self::start_form('graph.stock.purchase.receive','stock','Recibir compra total o parcial'); self::select('purchase_line_id','Línea de compra',$lines); self::field('quantity','Cantidad recibida','number'); self::field('evidence_ref','Factura / remito / comprobante'); self::end_form('Confirmar recepción');
        echo '<p class="ge-op-hint">La compra es un compromiso. La recepción ingresa stock y registra una cuenta a pagar sólo con costo confirmado; el costo pendiente permanece visible.</p>';
    }
    private static function laminate_report() { $r=GE_WTP_Operations_Equipment::lamination_report(array('month'=>current_time('Y-m')));echo '<section class="ge-admin-panel"><h2>Laminado este mes</h2><p>'.self::e($r['meters']['received']).' m ingresados · '.self::e($r['meters']['consumed']).' m consumidos · '.self::e($r['meters']['waste']).' m de merma registrada.</p><p class="ge-op-hint">Sólo movimientos reales en metros. No se convierten rollos ni se infieren hojas sin medidas verificadas.</p></section>'; }
    public static function equipment() {
        $rows=array(); $opts=array(); foreach(GE_WTP_Operations_Equipment::all() as $e) { $opts[$e['id']]=$e['name']; $readings=GE_WTP_Operations_Equipment::readings($e['id']); $latest=$readings[0]??array(); $rows[]=array('<a href="'.esc_url(self::url('stock',array('view'=>'equipment','edit_equipment'=>$e['id']))).'">'.self::e($e['name']).'</a>',self::e($e['model']?:'Modelo pendiente'),self::e(!empty($e['model_verified'])?'Confirmado':'Por verificar'),'Lectura manual',self::e(isset($latest['reading'])?$latest['counter_name'].': '.$latest['reading'].' · '.$latest['observed_at']:'Sin lecturas')); } self::table(array('Equipo','Modelo','Identificación','Conexión','Última lectura UTC'),$rows);
        self::start_form('graph.equipment.counter.record','stock','Registrar lectura de contador'); self::select('equipment_id','Equipo',$opts); self::field('counter_name','Contador · total/color/BN','text','total'); self::field('reading','Lectura acumulada','number'); self::field('observed_at','Fecha y hora de lectura','datetime-local',current_time('Y-m-d\TH:i')); self::field('epoch','Época · 0 salvo reinicio','number','0'); self::field('reset_reason','Motivo de reinicio · si aplica','text','',false); self::end_form('Guardar lectura');
        $edit=absint($_GET['edit_equipment']??0); if($edit) { $e=GE_WTP_Operations_Equipment::get($edit); self::start_form('graph.equipment.save','stock','Editar equipo'); echo '<input type="hidden" name="id" value="'.self::e($edit).'"><input type="hidden" name="code" value="'.self::e($e['code']).'">'; self::field('name','Nombre','text',$e['name']); self::field('model','Modelo exacto','text',$e['model'],false); self::select('model_verified','Modelo verificado',array('0'=>'Pendiente','1'=>'Verificado'),(string)$e['model_verified']); self::select('state','Estado',array('active'=>'Activo','maintenance'=>'Mantenimiento','inactive'=>'Inactivo'),$e['state']); self::field('maintenance_notes','Mantenimiento y notas','textarea',$e['maintenance_notes'],false); $refs=(array)json_decode($e['consumable_refs']??'[]',true); echo '<label>Consumibles asociados<select multiple size="6" name="consumable_refs[]">';foreach(self::item_options() as $ref=>$label)echo '<option value="'.self::e($ref).'" '.selected(in_array((int)$ref,array_map('intval',$refs),true),true,false).'>'.self::e($label).'</option>';echo '</select></label>'; self::end_form('Guardar equipo'); }
        echo '<p class="ge-op-hint">Telemetría automática pendiente de conexión real. Las lecturas manuales conservan su fecha y autor.</p>';
    }
    public static function recipes() {
        global $wpdb; $rows=$wpdb->get_results('SELECT * FROM '.GE_WTP_Operations::table('recipes').' ORDER BY name',ARRAY_A); $out=array(); foreach($rows as $r) $out[]=array(self::e($r['id']),self::e($r['name']),self::e($r['updated_at'])); self::table(array('ID','Proceso','Actualización'),$out);
        echo '<p>Las recetas relacionan material, cantidad por unidad y equipo. La merma y el dúplex se confirman antes de consumir; una producción tercerizada no descuenta materiales propios.</p>';
        self::start_form('graph.production.recipe.save','stock','Configurar receta'); self::field('name','Nombre del proceso'); $equipment_opts=array('0'=>'Sin equipo asociado'); foreach(GE_WTP_Operations_Equipment::all() as $machine) $equipment_opts[$machine['id']]=$machine['name']; self::select('equipment_id','Equipo', $equipment_opts); self::select('material_id','Material',self::item_options()); self::select('basis','Base de consumo',array('output'=>'Por unidad producida','sheet'=>'Por hoja impresa','meter'=>'Por metro de proceso','area'=>'Por metro cuadrado')); self::field('quantity','Cantidad de material por unidad','number'); self::field('outputs_per_sheet','Unidades por hoja · base hoja','number','',false); self::field('length_m','Largo por unidad · metros','number','',false); self::field('width_m','Ancho · base metro cuadrado','number','',false); self::end_form();
        $opts=array(); foreach($rows as $r) $opts[$r['id']]=$r['name']; self::start_form('graph.production.materials.consume','stock','Confirmar consumo de producción interna'); self::select('recipe_id','Receta',$opts); self::field('order_id','Pedido / trabajo','number'); self::field('outputs','Unidades producidas','number'); self::select('duplex','Caras impresas por hoja',array('1'=>'Simple faz','2'=>'Dúplex')); self::field('waste_factor','Factor de consumo · 1 sin merma','number','1'); self::select('currency','Moneda del costo de materiales',array('ARS'=>'ARS','USD'=>'USD')); self::end_form('Confirmar consumo'); self::start_form('graph.production.materials.authorize','stock','Materiales propios aportados a un trabajo tercerizado');self::field('order_id','Pedido tercerizado','number');self::select('authorized','Autorización explícita',array('no'=>'No aportar / revocar','yes'=>'Graphex aporta materiales'));self::field('reason','Materiales aportados y motivo');self::end_form('Registrar autorización');
    }
    public static function administration() {

        self::heading('Administración','Finanzas operativas, gastos y cuentas a pagar. Cada concepto conserva su fuente y su moneda.');
        $period = sanitize_key($_GET['period'] ?? 'custom');
        $year = absint($_GET['year'] ?? current_time('Y'));
        $month = absint($_GET['month'] ?? current_time('m'));
        $from = sanitize_text_field($_GET['from'] ?? current_time('Y-m').'-01');
        $to = sanitize_text_field($_GET['to'] ?? current_time('Y-m-d'));
        $f = array('from'=>$from, 'to'=>$to);
        if ($period === 'month' || $period === 'year') {
            $f = array('year'=>$year);
            if ($period === 'month') $f['month'] = $month;
        }

        $month_input = sanitize_text_field($_GET['cash_month'] ?? '');
        if (preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D', $month_input)) {
            $year = (int)substr($month_input,0,4);
            $month = (int)substr($month_input,5,2);
            $f = array('year'=>$year, 'month'=>$month);
            $period = 'month';
        }
        $sum = GE_WTP_Operations_Finance::summary($f);
        $from = $sum['from'];
        $to = $sum['to'];
        self::administration_overview($sum, array('monthly'=>$period==='month'));
        echo '<details class="ge-admin-panel" id="ge-finance-details"><summary>Movimientos, filtros y reportes</summary>';
        echo '<form class="ge-v3-toolbar ge-op-filter" method="get"><input type="hidden" name="section" value="administration">'; self::select('period','Período',array('custom'=>'Rango de fechas','month'=>'Mes completo','year'=>'Año completo'),$period);self::field('year','Año','number',$year);self::field('month','Mes · 1 a 12','number',$month);self::field('from','Desde','date',$from); self::field('to','Hasta','date',$to); echo '<button type="submit">Aplicar período</button></form>';
        echo '<div class="ge-op-actions"><a href="#op-graph-finance-expense-create">Cargar gasto</a><a href="#op-graph-finance-payment-record">Registrar pago</a><a href="'.esc_url(self::url('supplier-invoices')).'">Facturas y documentos</a></div><section class="ge-admin-stats">'; foreach($sum['currencies'] as $c) { echo '<article><span>Costos y gastos devengados</span><strong>'.self::money($c['costs_minor'],$c['currency']).'</strong></article><article><span>Pagos registrados</span><strong>'.self::money($c['cash_out_minor'],$c['currency']).'</strong><small>Salida de dinero, separada del gasto</small></article>'; }
        foreach($sum['sales']['currencies']??array() as $c) echo '<article><span>Vendido · '.self::e($c['currency']).'</span><strong>'.self::money($c['sold_minor'],$c['currency']).'</strong><small>Pedidos netos de devoluciones, por fecha de creación</small></article>';
        foreach($sum['collections']['currencies']??array() as $c) echo '<article><span>Cobros confirmados · registro parcial</span><strong>'.self::money($c['confirmed_minor'],$c['currency']).'</strong><small>Última confirmación por pedido; no es caja conciliada</small></article>';
        if(empty($sum['collections']['currencies'])) echo '<article><span>Cobros confirmados</span><strong>Sin registros del período</strong><small>Caja total sin conciliar</small></article>'; echo '</section>';
        echo '<section class="ge-admin-panel"><h2>Movimiento de caja registrado</h2>'; $report=array(); $previous=array_column($sum['previous']['currencies'],'costs_minor','currency'); foreach($sum['currencies'] as $c) $report[]=array(self::e($c['currency']),self::money($c['cash_in_minor']??0,$c['currency']),self::money($c['cash_out_minor'],$c['currency']),self::money($c['costs_minor'],$c['currency']),self::money($previous[$c['currency']]??0,$c['currency'])); self::table(array('Moneda','Ingresos manuales netos','Pagos registrados','Gastos / costos actuales','Gastos período anterior'),$report); echo '<p class="ge-op-hint">Los ingresos manuales se muestran separados de las confirmaciones del pedido para evitar sumarlos dos veces. Un presupuesto o un estado de pago no representa caja conciliada.</p></section>';
        $suppliers=GE_WTP_Operations::suppliers(); $rows=array(); $total=array(); $due=array(); $today=current_time('Y-m-d'); $week=(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        foreach(GE_WTP_Operations_Finance::payables(!empty($_GET['supplier_id'])?array('supplier_id'=>absint($_GET['supplier_id'])):array()) as $p) { $s=$suppliers[$p['supplier_id']]??array(); $total[$p['currency']]=($total[$p['currency']]??0)+$p['outstanding_minor']; $rows[]=array('<a href="'.esc_url(self::url('suppliers',array('supplier_id'=>$s['key']??''))).'">'.self::e($s['name']??'Proveedor').'</a>',self::payable_order_detail($p),self::money($p['amount_minor'],$p['currency']),self::money($p['paid_minor'],$p['currency']),self::money($p['outstanding_minor'],$p['currency']),self::e($p['due_on']?:'Sin fecha').($p['due_on']&&$p['due_on']<$today?'<small>Vencido</small>':''),self::e('#'.$p['id'])); if($p['due_on']&&$p['due_on']<=$week) $due[]=$p; }
        echo '<section class="ge-admin-panel" id="ge-cash-payables"><h2>Cuentas a pagar</h2><p>'.count($due).' vencimiento(s) próximos o vencidos. '; foreach($total as $currency=>$amount) echo 'Saldo: '.self::money($amount,$currency).' '; echo '</p>'; self::table(array('Proveedor','Documento / compra','Importe','Pagado','Saldo','Vence','Referencia'),$rows); echo '</section>';
        $rows=array(); foreach(GE_WTP_Operations::legacy_balances() as $b) { $p=GE_WTP_Supplier_Workspace::supplier($b['key']); $rows[]=array('<a href="'.esc_url(self::url('suppliers',array('supplier_id'=>$b['key']))).'">'.self::e($p['name']??$b['key']).'</a>',self::money($b['cost_minor'],$b['currency']),self::money($b['paid_minor'],$b['currency']),self::money($b['cost_minor']-$b['paid_minor'],$b['currency'])); } if($rows) { echo '<section class="ge-admin-panel"><h2>Registros anteriores de proveedores</h2>'; self::table(array('Proveedor','Costos registrados','Pagos registrados','Saldo conocido'),$rows); echo '<p class="ge-op-hint">Fuente: Supplier Workspace. No se importó ni duplicó deuda en el ledger nuevo. Los costos ausentes y pagos sin asignación requieren conciliación.</p></section>'; }
        if(current_user_can('ge_record_finance')||current_user_can('manage_options')) { self::start_form('graph.finance.payment.record','administration','Registrar pago parcial o total'); $opts=array(); foreach(GE_WTP_Operations_Finance::payables(array()) as $p) $opts[$p['id']]='#'.$p['id'].' · '.($suppliers[$p['supplier_id']]['name']??'Proveedor').' · '.$p['currency']; self::select('payable_id','Cuenta a pagar',$opts); self::field('amount','Importe pagado'); self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD')); self::field('paid_on','Fecha de pago','date',$today); self::field('method','Método de pago'); self::field('reference','Comprobante / referencia'); self::end_form('Registrar pago');
        self::start_form('graph.finance.expense.create','administration','Cargar gasto'); self::field('description','Descripción'); self::select('category','Categoría',array('rent'=>'Alquiler','utilities'=>'Servicios','software'=>'Software','taxes'=>'Impuestos / tasas','maintenance'=>'Mantenimiento','logistics'=>'Logística','marketing'=>'Publicidad','fees'=>'Honorarios','other'=>'Otros')); self::field('amount','Importe'); self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD')); self::field('incurred_on','Fecha del gasto','date',$today); self::field('due_on','Vencimiento','date','',false); self::select('supplier_id','Proveedor · opcional',array('0'=>'Sin proveedor')+self::supplier_options()); self::select('recurrence','Repetición',array(''=>'Una vez','weekly'=>'Semanal','monthly'=>'Mensual','yearly'=>'Anual')); self::select('payment_state','Estado inicial',array('unpaid'=>'Pendiente','paid'=>'Ya pagado'));self::field('paid_on','Fecha de pago · si ya está pagado','date',$today);self::field('method','Método · si ya está pagado','text','',false); self::field('attachment_ref','Comprobante privado · referencia','text','',false); echo '<p class="ge-op-hint">La frecuencia queda registrada para seguimiento; no genera automáticamente futuros gastos ni pagos.</p>'; self::end_form('Registrar gasto'); }
        $rows=array(); foreach(GE_WTP_Operations_Finance::expense_list($f) as $e) $rows[]=array(self::e($e['incurred_on']),self::e($e['description']),self::e(self::categories()[$e['category']??'']??$e['category']??''),self::money($e['amount_minor'],$e['currency']),self::e(array('weekly'=>'Semanal','monthly'=>'Mensual','yearly'=>'Anual')[$e['recurrence']]??'Una vez'),self::e(array('unpaid'=>'Pendiente','partial'=>'Pago parcial','paid'=>'Pagado','reversed'=>'Reversado')[$e['status']]??$e['status']),self::money($e['outstanding_minor'],$e['currency'])); echo '<section class="ge-admin-panel"><h2>Gastos del período</h2>'; self::table(array('Fecha','Descripción','Categoría','Importe','Repetición','Estado','Saldo'),$rows); echo '</section>';
        echo '<section class="ge-admin-panel"><h2>Rentabilidad por pedido</h2><form class="ge-op-filter" method="get"><input type="hidden" name="section" value="administration">'; self::field('margin_order_id','Pedido / trabajo','number',$_GET['margin_order_id']??''); echo '<button>Consultar</button></form>';
        if(!empty($_GET['margin_order_id'])) { $requested=absint($_GET['margin_order_id']); global $wpdb; $linked=$requested>=10001?$wpdb->get_var($wpdb->prepare('SELECT order_id FROM '.GE_WTP_Gestion_V3::table().' WHERE work_number=%d',$requested)):0; $m=GE_WTP_Operations_Finance::order_margin($linked?:$requested); echo '<p>Pedido #'.self::e($m['order_id']).' · vendido '.self::e($m['currency'].' '.$m['sold_total_decimal']).' · margen '.($m['margin_minor']===null?'Costo incompleto':self::money($m['margin_minor'],$m['currency'])).'</p>'; if(($m['legacy_supplier_costs']['status']??'none')!=='none') echo '<p class="ge-op-hint">Costos históricos de proveedor: '.self::e(array('known_readonly'=>'fuente anterior consultada sin duplicación','ambiguous_overlap'=>'hay superposición con un costo nuevo; conciliar antes de calcular','unknown_legacy_cost'=>'importe o moneda pendientes de confirmar')[$m['legacy_supplier_costs']['status']]??$m['legacy_supplier_costs']['status']).'.</p>'; } echo '</section>';
        if(current_user_can('ge_record_finance')||current_user_can('manage_options')) {
            self::start_form('graph.finance.income.record','administration','Registrar ingreso o devolución'); self::select('type','Movimiento',array('income'=>'Ingreso cobrado','refund'=>'Devolución de dinero')); self::field('amount','Importe'); self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD')); self::field('received_on','Fecha','date',$today); self::field('order_id','Pedido / trabajo · opcional','number','',false); self::field('method','Método'); self::field('reference','Referencia / comprobante'); self::end_form('Registrar movimiento');
            self::start_form('graph.finance.cost.record','administration','Registrar costo directo de pedido'); self::field('order_id','Pedido / trabajo','number'); self::field('description','Descripción del costo'); self::field('amount','Costo total confirmado'); self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD')); self::field('incurred_on','Fecha','date',$today); self::select('category','Tipo de costo',array('job_cost'=>'Otro costo directo','labor'=>'Mano de obra','machine'=>'Máquina','logistics'=>'Logística')); self::end_form();
            self::start_form('graph.finance.costs.verify','administration','Verificar costos completos'); self::field('order_id','Pedido / trabajo','number'); self::select('complete','Resultado',array('no'=>'Costos incompletos','yes'=>'Todos los costos verificados')); self::field('reason','Detalle de verificación'); self::end_form('Guardar verificación');
            self::start_form('graph.finance.reverse','administration','Corregir movimiento financiero'); self::field('entry_id','Referencia del ledger','number'); self::field('date','Fecha de reverso','date',$today); self::field('reason','Motivo de corrección'); self::end_form('Registrar reverso');
        }
        global $wpdb; $entries=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.GE_WTP_Operations::table('finance_entries').' WHERE entry_date BETWEEN %s AND %s ORDER BY id DESC LIMIT 200',$from,$to),ARRAY_A); $ledger=array(); foreach($entries as $e) $ledger[]=array(self::e('#'.$e['id']),self::e($e['entry_date']),self::e($e['entry_type']),self::money($e['amount_minor'],$e['currency']),self::e($e['order_id']?:'—'),self::e($e['reverses_id']?'Reverso #'.$e['reverses_id']:'Original')); echo '<section class="ge-admin-panel" id="ge-cash-ledger"><h2>Ledger operativo</h2>'; self::table(array('Referencia','Fecha','Tipo','Importe','Pedido','Auditoría'),$ledger); echo '<p class="ge-op-hint">Últimos 200 movimientos del período. Correcciones por reverso; se conserva el original.</p></section>';
        self::finance_reports($from,$to);
        if(class_exists('GE_WTP_Cost_Reconciliation_R82')) GE_WTP_Cost_Reconciliation_R82::render();
        echo '</details><script>function geOpenFinanceDetails(){var id=location.hash.slice(1);var target=document.getElementById(id);var details=document.getElementById("ge-finance-details");if(target && details && details.contains(target)){details.open=true;target.scrollIntoView();}}window.addEventListener("hashchange",geOpenFinanceDetails);geOpenFinanceDetails();</script>';
        echo '<p class="ge-op-hint">Los presupuestos no cuentan como ventas. Rentabilidad incompleta hasta confirmar todos los costos. Los pagos históricos del Supplier Workspace mantienen su fuente; no se duplican en este ledger.</p>';
    }
    private static function finance_reports($from,$to) {
        $filters=array('from'=>$from,'to'=>$to); foreach(array('supplier_id','customer_id','category') as $k) if(!empty($_GET[$k])) $filters[$k]=sanitize_text_field($_GET[$k]);
        echo '<section class="ge-admin-panel"><h2>Reportes de costos y margen</h2><form class="ge-op-filter" method="get"><input type="hidden" name="section" value="administration">'; self::field('from','Desde','date',$from);self::field('to','Hasta','date',$to);self::select('supplier_id','Proveedor',array(''=>'Todos')+self::supplier_options(),$_GET['supplier_id']??''); self::select('category','Categoría',array(''=>'Todas')+self::categories(),$_GET['category']??'');self::field('customer_id','Cliente · referencia opcional','number',$_GET['customer_id']??'',false);echo '<button>Filtrar reporte</button></form>';
        $r=GE_WTP_Operations_Finance::report($filters); foreach(array('category'=>'Gastos por categoría','supplier'=>'Gastos por proveedor','month'=>'Gastos por mes') as $dimension=>$title) { $rows=array();foreach($r['expense_groups'][$dimension] as $g) { $label=$dimension==='supplier'?(self::supplier_options()[$g['group']]??'Sin proveedor'):($dimension==='category'?(self::categories()[$g['group']]??$g['group']):$g['group']);$rows[]=array(self::e($label),self::money($g['amount_minor'],$g['currency']),self::e($g['entry_count'])); } echo '<h3>'.self::e($title).'</h3>';self::table(array('Grupo','Gasto neto registrado','Movimientos'),$rows); }
        $spend=array();foreach($r['supplier_spend']??array() as $g)$spend[]=array(self::e(self::supplier_options()[$g['supplier_id']]??'Sin proveedor'),self::money($g['purchases_minor'],$g['currency']),self::money($g['expenses_minor'],$g['currency']),self::money($g['total_minor'],$g['currency']));echo '<h3>Compras y gastos por proveedor</h3>';self::table(array('Proveedor','Compras reconocidas','Gastos registrados','Total por moneda'),$spend);
        $rows=array();foreach($r['margins']['currencies'] as $g) $rows[]=array(self::e($g['currency']),self::e($g['complete_count']),self::e($g['incomplete_count']),$g['complete_margin_minor']===null?'Costo incompleto':self::money($g['complete_margin_minor'],$g['currency']),$g['operating_estimate_minor']===null?'Sin cobertura suficiente':self::money($g['operating_estimate_minor'],$g['currency']));echo '<h3>Margen y resultado estimado</h3>';self::table(array('Moneda','Pedidos con costos completos','Con costos pendientes','Margen del subconjunto completo','Resultado operativo parcial'),$rows);echo '<p class="ge-op-hint">El margen incluye solamente pedidos con todos sus costos verificados. Resultado operativo parcial: no representa la ganancia total del negocio mientras falten costos. Cada moneda se informa por separado.</p><h3>Cuentas a cobrar</h3><p>Saldo sin conciliar: la fuente de pagos conserva la última confirmación, pero no permite reconstruir todos los cobros históricos. Se mantiene como desconocido hasta contar con esa evidencia.</p></section>';
    }
    private static function administration_overview($summary, $context) {
        $payables = GE_WTP_Operations_Finance::payables(array('status' => 'all'));
        $suppliers = GE_WTP_Operations::suppliers();
        $due = $accounts = $receivable = array();
        foreach ($payables as $entry) {
            $balance = (int) $entry['outstanding_minor'];
            $currency = $entry['currency'];
            $id = (int) $entry['supplier_id'];
            $due[$currency] = ($due[$currency] ?? 0) + $balance;
            $account = $accounts[$id][$currency] ?? array('pending' => 0, 'paid' => 0);
            $account['pending'] += $balance;
            $account['paid'] += (int) $entry['paid_minor'];
            $accounts[$id][$currency] = $account;
        }
        $unverified = 0;
        $cash = $previous_cash = $receipts_for_period = array();
        $from = $summary['from'];
        $to = $summary['to'];
        $page = 1;
        do {
            $batch = wc_get_orders(array('type'=>'shop_order','limit'=>100,'paginate'=>true,'page'=>$page,'orderby'=>'ID','order'=>'ASC'));
            foreach ($batch->orders as $order) {
                if (in_array($order->get_status(),array('cancelled','failed','refunded','checkout-draft','draft','pending','ge-quote','quote'),true)
                    || GE_WTP_Customer_Quotes::is_quote_order($order)
                    || $order->get_meta('_ge_commercial_payment_order') === 'yes'
                    || $order->get_meta('_ge_financial_parent_order_id')) {
                    continue;
                }
                $currency = $order->get_currency();
                $receipts = (array)$order->get_meta('_ge_work_manual_payments',true);
                $dated = array();
                foreach ($receipts as $receipt) {
                    if (is_array($receipt) && !empty($receipt['received_date']) && !empty($receipt['amount_cents'])) {
                        $dated[] = array($receipt['received_date'], (int)$receipt['amount_cents']);
                    }
                }
                if (!$dated) {
                    $confirmed = $order->get_meta('_ge_payment_confirmed_at',true);
                    $total = $order->get_meta('_ge_payment_confirmed_total',true);
                    if ($confirmed && is_numeric($total) && (float)$total > 0) {
                        $dated[] = array(substr($confirmed,0,10), (int)round((float)$total * 100));
                    }
                }
                foreach ($dated as [$date,$amount]) {
                    if ($date >= $from && $date <= $to) {
                        $cash[$currency] = ($cash[$currency] ?? 0) + $amount;
                        $receipts_for_period[] = array($date, $currency, $amount, $order->get_id(), $order->get_order_number());
                    } elseif ($date >= $summary['previous']['from'] && $date <= $summary['previous']['to']) {
                        $previous_cash[$currency] = ($previous_cash[$currency] ?? 0) + $amount;
                    }
                }
                $balance = $order->get_meta('_ge_amount_due_cents',true);
                if ($balance === '') {
                    if ($order->get_meta('_ge_payment_state',true) !== 'paid') {
                        $unverified++;
                    }
                    continue;
                }
                $currency = $order->get_currency();
                $receivable[$currency] = ($receivable[$currency] ?? 0) + max(0,(int)$balance);
            }
        } while (++$page <= $batch->max_num_pages);

        $metrics = array(
            array('receivable','Por cobrar',$receivable,$unverified.' pedidos sin saldo registrado.','work'),
            array('payable','Por pagar',$due,'Saldo de las obligaciones registradas.','costs'),
        );
        echo '<style>
        .ge-money-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:14px;margin:12px 0 24px}
        .ge-money-card{display:grid;align-content:start;gap:8px;padding:18px;background:#fff;border:1px solid #dbe3ef;border-top:4px solid #3262ad;border-radius:12px;color:#182742}
        .ge-money-card.cash{border-top-color:#17835e;background:#effaf5}
        .ge-money-card.receivable{border-top-color:#2b61b2;background:#f0f6ff}
        .ge-money-card.payable{border-top-color:#ab5d13;background:#fff6e9}
        .ge-money-card h3,.ge-money-card p{margin:0}
        .ge-money-card strong{display:block;font-size:clamp(1.2rem,2vw,1.6rem)}
        .ge-money-card small{color:#41516b}
        .ge-money-card a{width:fit-content}
        .ge-cash-period{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin:0 0 14px}
        .ge-cash-period label{display:grid;gap:4px;font-weight:600}
        .ge-cash-period a{padding:.5rem .65rem}
        .ge-cash-section{margin-top:18px;padding:clamp(12px,2.5vw,24px);background:#f8fafc;border:1px solid #dbe3ef;border-radius:16px}
        .ge-cash-section h2{margin-top:0}
        .ge-cash-comparison{display:grid;gap:12px;margin:18px 0}
        .ge-cash-bar{display:grid;grid-template-columns:minmax(100px,1fr) minmax(70px,3fr) minmax(95px,auto);align-items:center;gap:10px}
        .ge-cash-bar b{text-align:right;font-variant-numeric:tabular-nums}
        .ge-cash-track{height:10px;border-radius:6px;background:#e2e8f0;overflow:hidden}
        .ge-cash-track span{display:block;height:100%;background:#17835e;border-radius:inherit}
        .ge-cash-bar.is-out .ge-cash-track span{background:#ab5d13}
        .ge-cash-bar.is-previous .ge-cash-track span{opacity:.55}
        .ge-cash-section details{margin-top:16px}
        </style>';
        self::administration_cash($summary, $context['monthly'], $cash, $previous_cash, $receipts_for_period);
        echo '<h2>Saldos actuales</h2><div class="ge-money-grid">';
        foreach ($metrics as [$class,$label,$totals,$note,$view]) {
            echo '<article class="ge-money-card '.esc_attr($class).'"><h3>'.self::e($label).'</h3>';
            if (!$totals) {
                echo '<strong>Sin registros</strong>';
            }
            foreach ($totals as $currency => $amount) {
                echo '<strong>'.self::money($amount,$currency).'</strong>';
            }
            echo '<small>'.self::e($note).'</small><a href="'.esc_url($view === 'work' ? self::url('jobs') : '#ge-cash-payables').'">Ver detalle</a></article>';
        }
        echo '</div>';
        echo '<h2>Cuentas por proveedor</h2><div class="ge-money-grid">';
        $reconciliation = self::administration_reconciliation();
        uasort($suppliers,static function($a,$b) {
            $rank=static function($profile) {
                $name=strtolower($profile['name']);
                foreach (array('prointec','mardones','bandurria','windbanners') as $index=>$preferred) {
                    if (strpos($name,$preferred)!==false) return $index;
                }
                return 4;
            };
            return $rank($a)<=>$rank($b) ?: strcasecmp($a['name'],$b['name']);
        });
        foreach ($suppliers as $id=>$profile) {
            if (empty($accounts[$id])) { continue; }

            echo '<article class="ge-money-card"><h3>'.self::e($profile['name']).'</h3>';
            foreach ($accounts[$id] ?? array() as $currency=>$amounts) {
                echo '<p>Por pagar <strong>'.self::money($amounts['pending'],$currency).'</strong></p><small>Pagado '.self::money($amounts['paid'],$currency).'</small>';
            }

            echo '<a href="'.esc_url(self::url('suppliers',array('view'=>'supplier','supplier_id'=>$profile['key']))).'#supplier-account">Ver cuenta</a></article>';
        }
        echo '</div>'.$reconciliation;
    }

    private static function administration_cash($summary, $monthly, $cash, $previous_cash, $receipts) {
        $from = new DateTimeImmutable($summary['from']);
        $to = new DateTimeImmutable($summary['to']);
        $month = $from->modify('first day of this month');
        $previous_month = $month->modify('-1 month')->format('Y-m');
        $next_month = $month->modify('+1 month')->format('Y-m');
        $current_month = current_time('Y-m');
        $out = array_column($summary['currencies'], 'cash_out_minor', 'currency');
        $previous_out = array_column($summary['previous']['currencies'], 'cash_out_minor', 'currency');
        $sales = array_column($summary['sales']['currencies'] ?? array(), 'sold_minor', 'currency');
        $currencies = array_keys($cash + $out + $sales + $previous_cash + $previous_out);
        sort($currencies);

        echo '<section class="ge-cash-section" aria-labelledby="ge-cash-heading"><h2 id="ge-cash-heading">Caja registrada</h2>';
        echo '<form class="ge-cash-period" method="get"><input type="hidden" name="section" value="administration">';
        echo '<a href="'.esc_url(self::url('administration',array('cash_month'=>$previous_month))).'">Mes anterior</a>';
        echo '<label>Mes<input type="month" name="cash_month" required min="2000-01" max="2100-12" value="'.esc_attr($month->format('Y-m')).'"></label>';
        echo '<button class="ge-staff-button" type="submit">Ver mes</button>';
        if ($monthly && $next_month <= $current_month) {
            echo '<a href="'.esc_url(self::url('administration',array('cash_month'=>$next_month))).'">Mes siguiente</a>';
        }
        echo '<a href="'.esc_url(self::url('administration')).'">Mes en curso</a></form>';
        echo '<p>Del '.self::e($from->format('d/m/Y')).' al '.self::e($to->format('d/m/Y')).'</p>';
        if (!$currencies) echo '<p>Sin movimientos registrados en el período.</p>';
        foreach ($currencies as $currency) {
            $received = (int)($cash[$currency] ?? 0);
            $paid = (int)($out[$currency] ?? 0);
            $previous_received = (int)($previous_cash[$currency] ?? 0);
            $previous_paid = (int)($previous_out[$currency] ?? 0);
            $scale = max(1,abs($received),abs($paid),abs($previous_received),abs($previous_paid));
            $metrics = array(
                array('cash','Cobros de pedidos',$received),
                array('payable','Pagos registrados',$paid),
                array('','Cobros menos pagos',$received-$paid),
                array('receivable','Ventas del período',$sales[$currency] ?? 0),
            );
            echo '<h3>'.self::e($currency).'</h3><div class="ge-money-grid">';
            foreach ($metrics as [$class,$label,$amount]) {
                echo '<article class="ge-money-card '.esc_attr($class).'"><h3>'.self::e($label).'</h3><strong>'.self::money($amount,$currency).'</strong></article>';
            }
            echo '</div><h3>Comparación de movimientos</h3><p>Período anterior del '.self::e((new DateTimeImmutable($summary['previous']['from']))->format('d/m/Y')).' al '.self::e((new DateTimeImmutable($summary['previous']['to']))->format('d/m/Y')).'</p>';
            echo '<div class="ge-cash-comparison">';
            foreach (array(
                array('','Cobros del período',$received),
                array('is-previous','Cobros anteriores',$previous_received),
                array('is-out','Pagos del período',$paid),
                array('is-out is-previous','Pagos anteriores',$previous_paid),
            ) as [$class,$label,$amount]) {
                $width = (int)round(100 * abs($amount) / $scale);
                echo '<div class="ge-cash-bar '.esc_attr($class).'"><span>'.self::e($label).'</span><span class="ge-cash-track" aria-hidden="true"><span style="width:'.$width.'%"></span></span><b>'.self::money($amount,$currency).'</b></div>';
            }
            echo '</div>';
        }
        echo '<p class="ge-op-hint">Las ventas se agrupan por fecha del pedido. Los cobros se agrupan por fecha registrada. Los ingresos manuales se consultan por separado para evitar duplicaciones.</p>';
        usort($receipts, static function($a,$b) { return strcmp($b[0],$a[0]) ?: $b[3] <=> $a[3]; });
        echo '<details><summary>Ver cobros de pedidos ('.count($receipts).')</summary>';
        $rows = array();
        foreach ($receipts as [$date,$currency,$amount,$order_id,$number]) {
            $url = self::url('orders',array('order_id'=>$order_id));
            $rows[] = array(self::e((new DateTimeImmutable($date))->format('d/m/Y')), '<a href="'.esc_url($url).'">#'.self::e($number).'</a>', self::money($amount,$currency));
        }
        self::table(array('Fecha','Pedido','Importe'),$rows);
        echo '</details><p><a href="#ge-cash-ledger">Ver movimientos del registro de caja</a></p></section>';
    }

    private static function administration_reconciliation() {
        $mardones = array(
            array('Saldo a favor al 27/10/2026', 84979200),
            array('Belgrano Multiplex · factura 1241', 47190000),
            array('Transferencia a Mardones · factura 1242', 1694000),
            array('Transferencia a Mardones · factura 1274', 17907698),
            array('Pago por nuestra cuenta a Candia / Windbanners', -21296000),
        );
        $prointec = array(
            array('02/11/2026 · transferencia a Prointec', 25710000),
            array('05/11/2026 · operación 186866770177', 61290000),
            array('12/11/2026 · operación 188150554457', 6940000),
        );
        $paid = array_sum(array_column($prointec, 1));
        $mardones_due = array_sum(array_column($mardones, 1)) - $paid;
        $october = 131950000 - $paid;
        $november = 131950000;
        echo '<article class="ge-money-card receivable"><h3>Mardones</h3>';
        echo '<p>Saldo a favor de GRAPHEX</p><strong>'.self::money($mardones_due).'</strong>';
        echo '<small>Compensado con pagos a Prointec por '.self::money($paid).'</small></article>';
        echo '<article class="ge-money-card payable"><h3>Prointec</h3><p>Cuotas pendientes</p><strong>'.self::money($october+$november).'</strong>';
        echo '<small>Octubre pendiente '.self::money($october).' · Noviembre '.self::money($november).'</small>';
        echo '<small>Pagado de octubre '.self::money($paid).'</small></article>';
        ob_start();
        echo '<details class="ge-admin-panel"><summary>Ver movimientos de la conciliación</summary>';
        $rows = array();
        foreach ($mardones as [$label,$amount]) {
            $rows[] = array(self::e($label),self::money($amount));
        }
        foreach ($prointec as [$label,$amount]) {
            $rows[] = array(self::e('Prointec · '.$label),self::money(-$amount));
        }
        self::table(array('Movimiento','Impacto en saldo a favor con Mardones'),$rows);
        echo '<p>Corte al 12/11/2026. Estos saldos se informan por separado de los cobros de pedidos y de caja.</p></details>';
        return ob_get_clean();
    }

    private static function payable_order_detail($payable) {
        $detail = self::e($payable['description']);
        $order_id = (int)($payable['order_id'] ?? 0);
        if ($order_id > 0) {
            $detail .= '<small><a href="'.esc_url(self::url('orders',array('order_id'=>$order_id))).'">Ver pedido #'.self::e($order_id).'</a></small>';
        } else {
            $detail .= '<small>Sin pedido interno vinculado</small>';
        }
        return $detail;
    }

    public static function supplier_finance($key) {
        if(!current_user_can('ge_view_finance')&&!current_user_can('manage_options')) return;
        $sid=0;foreach(GE_WTP_Operations::suppliers() as $id=>$profile)if($profile['key']===$key)$sid=$id;
        echo '<section class="ge-sw-card" id="supplier-account"><h2>Cuenta del proveedor</h2><p>Costos, compras y pagos comparten el registro de Administración.</p>';
        $payables=GE_WTP_Operations_Finance::payables(array('supplier_id'=>$sid,'status'=>'all'));$rows=array();$opts=array();foreach($payables as $p) { $rows[]=array(self::e('#'.$p['id']),self::payable_order_detail($p),self::e($p['due_on']?:'Sin vencimiento'),self::money($p['recognized_minor'],$p['currency']),self::money($p['paid_minor'],$p['currency']),self::money($p['outstanding_minor'],$p['currency']));if($p['outstanding_minor']>0)$opts[$p['id']]='#'.$p['id'].' · '.$p['description'].' · '.$p['currency']; } self::table(array('Cuenta','Concepto','Vencimiento','Costo','Pagado','Saldo'),$rows);
        if(current_user_can('ge_record_finance')||current_user_can('manage_options')) {
            self::start_form('graph.finance.supplier_work_cost.record','suppliers','Registrar costo de trabajo');echo '<input type="hidden" name="supplier_id" value="'.(int)$sid.'">';self::field('order_id','Pedido / trabajo','number');self::field('amount','Importe total');self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD'));self::field('incurred_on','Fecha','date',current_time('Y-m-d'));self::field('due_on','Vencimiento','date','',false);self::field('description','Concepto');self::field('attachment_ref','ID del comprobante','number','',false);self::end_form('Registrar cuenta a pagar');
            if($opts) { self::start_form('graph.finance.payment.record','suppliers','Registrar pago al proveedor');self::select('payable_id','Cuenta a pagar',$opts);self::field('amount','Importe pagado');self::select('currency','Moneda',array('ARS'=>'ARS','USD'=>'USD'));self::field('paid_on','Fecha de pago','date',current_time('Y-m-d'));self::field('method','Método');self::field('reference','Comprobante / referencia');self::end_form('Registrar pago'); }
        }
        foreach(GE_WTP_Operations::legacy_balances() as $b) if($b['key']===$key) echo '<p class="ge-op-hint">Registro histórico separado: costo '.self::money($b['cost_minor'],$b['currency']).' · pagos '.self::money($b['paid_minor'],$b['currency']).'. Requiere conciliación antes de incorporar su saldo.</p>';
        echo '<a href="'.esc_url(self::url('administration',array('supplier_id'=>$sid))).'">Abrir Administración →</a></section>';
    }
    public static function suppliers() {
        $key=sanitize_key($_GET['supplier_id']??''); if($key) {
            GE_WTP_Supplier_Workspace::render('supplier'); $p=GE_WTP_Supplier_Workspace::supplier($key); if(!$p) return;
            self::start_form('supplier_types','suppliers','Tipos del proveedor'); echo '<input type="hidden" name="supplier_key" value="'.esc_attr($key).'">'; foreach(array('production'=>'Producción','supplies'=>'Insumos','services'=>'Servicios','logistics'=>'Logística','other'=>'Otros') as $type=>$label) echo '<label class="ge-op-check"><input type="checkbox" name="types[]" value="'.$type.'" '.checked(in_array($type,$p['types']??array(),true),true,false).'>'.$label.'</label>'; self::end_form();
            if(current_user_can('ge_view_finance')||current_user_can('manage_options')) { $id=0; foreach(GE_WTP_Operations::suppliers() as $sid=>$s) if($s['key']===$key) $id=$sid; echo '<p><a href="'.esc_url(self::url('administration',array('supplier_id'=>$id))).'">Abrir cuentas a pagar y compras de este proveedor →</a></p>'; } if(current_user_can('ge_manage_inventory')||current_user_can('manage_options')) { $sid=0;foreach(GE_WTP_Operations::suppliers() as $ref=>$profile)if($profile['key']===$key)$sid=$ref;$rows=array();foreach(GE_WTP_Operations_Stock::purchases(array('supplier_id'=>$sid)) as $purchase)$rows[]=array(self::e('#'.$purchase['id']),self::e(array('ordered'=>'Pendiente','partially_received'=>'Recepción parcial','received'=>'Recibida')[$purchase['status']]??$purchase['status']),self::e($purchase['due_date']?:'Sin vencimiento'),'<a href="'.esc_url(self::url('stock',array('view'=>'purchases','supplier_id'=>$sid))).'">Abrir compra</a>');echo '<section class="ge-admin-panel"><h2>Compras de insumos</h2>';self::table(array('Compra','Recepción','Vencimiento','Acción'),$rows);echo '</section>'; } return;
        }
        GE_WTP_Supplier_Workspace::render('suppliers');
    }
    public static function dashboard() {
        if(!GE_WTP_Operations::enabled()) return;
        global $wpdb;
        echo '<section class="ge-v3-shortcuts" aria-label="Administración y stock">';
        if(GE_WTP_Operations::module_enabled('stock')&&(current_user_can('ge_manage_inventory')||current_user_can('manage_options'))) { $items=GE_WTP_Operations_Stock::items(); $low=count(GE_WTP_Operations_Stock::low_stock()); $empty=count(array_filter($items,function($i){return $i['stock_known']&&(float)$i['balance']['available']===0.0;})); $unknown=count(array_filter($items,function($i){return !$i['stock_known'];})); $open=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_WTP_Operations::table('purchases')." WHERE status IN ('ordered','partially_received')"); echo '<a href="'.esc_url(self::url('stock')).'">'.GE_WTP_Gestion_V3::icon('stock').'<span><strong>Stock</strong><small>'.$low.' bajo mínimo · '.$empty.' agotados · '.$unknown.' sin contar · '.$open.' compras pendientes</small></span></a>'; }
        if(GE_WTP_Operations::module_enabled('administration')&&(current_user_can('ge_view_finance')||current_user_can('manage_options'))) { $due=GE_WTP_Operations_Finance::payables(array('due_to'=>(new DateTimeImmutable(current_time('Y-m-d')))->modify('+7 days')->format('Y-m-d'))); $month=GE_WTP_Operations_Finance::summary(array());$cash='Sin movimientos nuevos · caja sin conciliar';if($month['currencies']) { $parts=array();foreach($month['currencies'] as $c)$parts[]='Gastos/costos registrados '.self::money($c['costs_minor'],$c['currency']);$cash=implode(' · ',$parts).' · caja sin conciliar'; }echo '<a href="'.esc_url(self::url('administration')).'">'.GE_WTP_Gestion_V3::icon('administration').'<span><strong>Administración</strong><small>'.count($due).' pagos próximos o vencidos · '.$cash.'</small></span></a>'; }
        if(GE_WTP_Operations::module_enabled('suppliers')&&current_user_can('ge_manage_operations')) { $metrics=GE_WTP_Supplier_Workspace::compact_operations_metrics();echo '<a href="'.esc_url(self::url('suppliers')).'">'.GE_WTP_Gestion_V3::icon('suppliers').'<span><strong>Proveedores</strong><small>'.$metrics['open'].' trabajos abiertos · '.$metrics['no_list'].' sin lista cargada</small></span></a>'; }
        echo '</section>';
    }
}
