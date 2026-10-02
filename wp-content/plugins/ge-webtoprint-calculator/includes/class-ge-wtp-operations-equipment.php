<?php
defined('ABSPATH') || exit;

/** Equipment registry (shop machines, not infrastructure resources). */
class GE_WTP_Operations_Equipment {
    public static function schema() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $e = GE_WTP_Operations::table('equipment');
        $r = GE_WTP_Operations::table('counter_readings');
        $b = GE_WTP_Operations::table('recipes');
        return array(
            "CREATE TABLE $e (id bigint unsigned NOT NULL AUTO_INCREMENT, code varchar(80) NOT NULL, name varchar(190) NOT NULL, model varchar(190) NULL, model_verified tinyint NOT NULL DEFAULT 0, adapter varchar(80) NULL, state varchar(24) NOT NULL DEFAULT 'active', maintenance_notes text NULL, consumable_refs longtext NULL, PRIMARY KEY (id), UNIQUE KEY code (code)) ENGINE=InnoDB $c;",
            "CREATE TABLE $r (id bigint unsigned NOT NULL AUTO_INCREMENT, equipment_id bigint unsigned NOT NULL, counter_name varchar(80) NOT NULL, epoch bigint unsigned NOT NULL DEFAULT 0, reading decimal(20,4) NOT NULL, observed_at datetime NOT NULL, reset_reason text NULL, idempotency_key varchar(190) NOT NULL, payload_hash char(64) NOT NULL, created_by bigint unsigned NOT NULL, PRIMARY KEY (id), UNIQUE KEY idempotency_key (idempotency_key), KEY equipment_counter (equipment_id,counter_name,epoch)) ENGINE=InnoDB $c;",
            "CREATE TABLE $b (id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(190) NOT NULL, lines_json longtext NOT NULL, parent_id bigint unsigned NULL, updated_at datetime NOT NULL, PRIMARY KEY (id), KEY parent_id (parent_id)) ENGINE=InnoDB $c;"
        );
    }
    public static function seed() {
        global $wpdb;
        $rows = array('xerox-c70'=>array('Xerox C70','C70'), 'badgy200'=>array('Badgy 200','Badgy200'), 'brother-label'=>array('Brother — modelo pendiente Q700/QL-700',null), 'epson'=>array('Epson — modelo pendiente',null), 'anilladora'=>array('Anilladora',null), 'wire-o'=>array('Wire-O',null), 'binder'=>array('Binder',null), 'laminadora'=>array('Laminadora',null), 'pines38'=>array('Máquina de pines 38 mm',null));
        foreach ($rows as $code=>$row) {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.GE_WTP_Operations::table('equipment').' (code,name,model,model_verified) VALUES (%s,%s,%s,0)', $code,$row[0],$row[1]));
        }
    }
    public static function get($id) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.GE_WTP_Operations::table('equipment').' WHERE id=%d', $id), ARRAY_A);
        if (!$row) GE_WTP_Operations::error('equipment_invalid', 'Equipo inexistente.');
        // Adapters are deliberately not invented: no credential or unreachable LAN claim.
        $row['telemetry'] = array('status'=>'unavailable','mode'=>'manual','reason'=>'No hay adaptador real instalado y verificado.');
        return $row;
    }
    public static function readings($id, $limit=100) {
        global $wpdb;
        self::get($id);
        return $wpdb->get_results($wpdb->prepare('SELECT id,equipment_id,counter_name,epoch,reading,observed_at,reset_reason,idempotency_key,created_by FROM '.GE_WTP_Operations::table('counter_readings').' WHERE equipment_id=%d ORDER BY id DESC LIMIT %d',$id,max(1,min(500,(int)$limit))),ARRAY_A);
    }
    public static function equipment_save(array $a) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $id=absint($a['id'] ?? 0); $name=sanitize_text_field($a['name'] ?? ''); $code=sanitize_key($a['code'] ?? ''); $state=$a['state'] ?? 'active';
        if (!$name || strlen($name)>190 || !$code || strlen($code)>80 || !in_array($state,array('active','maintenance','inactive'),true)) GE_WTP_Operations::error('equipment_invalid','Nombre, código y estado válidos obligatorios.');
        $refs=array_map('absint',(array)($a['consumable_refs'] ?? array()));
        foreach ($refs as $ref) { $item=GE_WTP_Operations_Stock::item_get($ref); if (!$item || empty($item['active'])) GE_WTP_Operations::error('equipment_invalid','Consumible inexistente o inactivo.'); }
        return GE_WTP_Operations::transaction(function() use($wpdb,$a,$id,$name,$code,$state,$refs) {
            $data=array('code'=>$code,'name'=>$name,'model'=>sanitize_text_field($a['model'] ?? ''),'model_verified'=>empty($a['model_verified'])?0:1,'state'=>$state,'maintenance_notes'=>sanitize_textarea_field($a['maintenance_notes'] ?? ''),'consumable_refs'=>wp_json_encode($refs));
            if ($data['model_verified'] && !$data['model']) GE_WTP_Operations::error('equipment_invalid','Modelo verificado requiere identificación exacta.');
            $t=GE_WTP_Operations::table('equipment');
            if ($id) { self::get($id); $ok=$wpdb->update($t,$data,array('id'=>$id)); } else { $ok=$wpdb->insert($t,$data); $id=(int)$wpdb->insert_id; }
            if ($ok===false) GE_WTP_Operations::error('equipment_invalid','No se pudo guardar el equipo (código debe ser único).');
            GE_WTP_Operations::audit('equipment_save',$id,$data); return self::get($id);
        });
    }
    public static function all() {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $ids = $wpdb->get_col('SELECT id FROM '.GE_WTP_Operations::table('equipment').' ORDER BY id');
        return array_map(array(__CLASS__,'get'), $ids);
    }
    public static function recipes() {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden','Sin permiso de stock.');
        $rows=$wpdb->get_results('SELECT id,name,parent_id,lines_json,updated_at FROM '.GE_WTP_Operations::table('recipes')." WHERE lines_json<>'[]' ORDER BY id DESC LIMIT 500",ARRAY_A);
        foreach ($rows as &$row) { $row['lines']=json_decode($row['lines_json'],true); unset($row['lines_json']); }
        return $rows;
    }
    /** Observed laminate movements only. Quantities retain their recorded stock unit. */
    public static function lamination_report(array $a=array()) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden','Sin permiso de stock.');
        $month=$a['month']??gmdate('Y-m');
        if (!is_string($month) || !preg_match('/^(\d{4})-(\d{2})$/D',$month,$p) || !checkdate((int)$p[2],1,(int)$p[1])) GE_WTP_Operations::error('equipment_date','Mes inválido: YYYY-MM.');
        $start=new DateTimeImmutable($month.'-01 00:00:00',new DateTimeZone('UTC'));
        $end=$start->modify('first day of next month');
        $rows=$wpdb->get_results($wpdb->prepare('SELECT m.id,m.item_id,m.order_id,m.movement_type,m.quantity,i.name,i.sku,i.unit FROM '.GE_WTP_Operations::table('stock_moves').' m INNER JOIN '.GE_WTP_Operations::table('stock_items')." i ON i.id=m.item_id WHERE i.category=%s AND m.created_at >= %s AND m.created_at < %s AND m.movement_type IN ('receipt','consumption','consume_reserved','waste') ORDER BY m.id",'laminate',$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')),ARRAY_A);
        if ($wpdb->last_error) GE_WTP_Operations::error('equipment_report','No se pudo consultar movimientos de laminado.');
        $items=array(); $orders=array(); $meter_orders=array(); $meters=array('received'=>0,'consumed'=>0,'waste'=>0);
        foreach ($rows as $r) {
            $id=(int)$r['item_id']; $key=$r['movement_type']==='receipt'?'received':($r['movement_type']==='waste'?'waste':'consumed');
            $q=self::fixed4($r['quantity'],'movimiento de laminado');
            if (!isset($items[$id])) $items[$id]=array('item_id'=>$id,'name'=>$r['name'],'sku'=>$r['sku'],'unit'=>$r['unit'],'received'=>0,'consumed'=>0,'waste'=>0,'order_ids'=>array(),'movement_count'=>0);
            if ($items[$id][$key]>PHP_INT_MAX-$q) GE_WTP_Operations::error('equipment_overflow','Total de laminado fuera de rango.');
            $items[$id][$key]+=$q; $items[$id]['movement_count']++;
            if ((int)$r['order_id']>0) { $orders[(int)$r['order_id']]=true; $items[$id]['order_ids'][(int)$r['order_id']]=true; }
            if ($r['unit']==='m') {
                if ($meters[$key]>PHP_INT_MAX-$q) GE_WTP_Operations::error('equipment_overflow','Total de metros fuera de rango.');
                $meters[$key]+=$q;
                if ((int)$r['order_id']>0) $meter_orders[(int)$r['order_id']]=true;
            }
        }
        foreach ($items as &$item) {
            foreach (array('received','consumed','waste') as $key) $item[$key]=self::decimal4($item[$key]);
            $item['order_ids']=array_keys($item['order_ids']); sort($item['order_ids']); $item['linked_order_count']=count($item['order_ids']);
        } unset($item);
        foreach ($meters as &$q) $q=self::decimal4($q); unset($q);
        $ids=array_keys($orders); sort($ids);
        return array('month'=>$month,'from'=>$start->format('Y-m-d'),'to'=>$end->modify('-1 day')->format('Y-m-d'),'timezone'=>'UTC','status'=>$rows?'recorded':'no_recorded_movements','source'=>'actual stock_moves; current stock_items category laminate','meters'=>$meters,'items'=>array_values($items),'order_ids'=>$ids,'linked_order_count'=>count($ids),'meter_linked_order_count'=>count($meter_orders),'movement_count'=>count($rows),'sheet_count'=>null,'roll_count'=>null,'limitation'=>'No se convierten m² ni rollos a metros; faltan cantidades físicas de hojas/rollos y conciliación de movimientos no registrados.');
    }
    private static function number($v, $label, $positive=false) {
        if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < 0 || ($positive && (float)$v <= 0)) GE_WTP_Operations::error('equipment_invalid', 'Valor inválido: '.$label);
        return (float)$v;
    }
    private static function fixed4($v,$label) {
        if (!preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D',(string)$v)) GE_WTP_Operations::error('equipment_precision','Hasta cuatro decimales, sin exponentes: '.$label);
        $p=explode('.',(string)$v); $n=(int)$p[0]*10000+(int)str_pad($p[1]??'',4,'0');
        if ($n<=0) GE_WTP_Operations::error('equipment_invalid','Cantidad positiva obligatoria: '.$label);
        return $n;
    }
    private static function decimal4($n) { return intdiv($n,10000).'.'.str_pad((string)($n%10000),4,'0',STR_PAD_LEFT); }
    /** Exact ceil of positive rational factors; cancel before multiplying to guard overflow. */
    private static function ratio_ceil(array $top,array $bottom) {
        foreach ($top as &$n) foreach ($bottom as &$d) { $a=$n; $b=$d; while ($b) { $r=$a%$b; $a=$b; $b=$r; } $n=intdiv($n,$a); $d=intdiv($d,$a); } unset($n,$d);
        $num=1; $den=1;
        foreach ($top as $n) { if ($n>intdiv(PHP_INT_MAX,$num)) GE_WTP_Operations::error('equipment_overflow','Estimación fuera de rango.'); $num*=$n; }
        foreach ($bottom as $d) { if ($d>intdiv(PHP_INT_MAX,$den)) GE_WTP_Operations::error('equipment_overflow','Estimación fuera de rango.'); $den*=$d; }
        return intdiv($num,$den)+($num%$den?1:0);
    }
    public static function counter_record(array $a) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $id = absint($a['equipment_id'] ?? 0);
        $name = sanitize_key($a['counter_name'] ?? '');
        $key = trim((string)($a['idempotency_key'] ?? ''));
        $value = self::number($a['reading'] ?? null, 'contador');
        $epoch_input = $a['epoch'] ?? 0;
        if (!is_numeric($epoch_input) || (float)$epoch_input<0 || (float)$epoch_input != floor((float)$epoch_input)) GE_WTP_Operations::error('equipment_invalid', 'Época de contador inválida.');
        $epoch = (int)$epoch_input;
        $reason = sanitize_textarea_field($a['reset_reason'] ?? '');
        $date = $a['observed_at'] ?? current_time('mysql', true);
        if (!$name || strlen($name)>80 || !$key || strlen($key)>190 || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$date) || strtotime($date.' UTC') === false) GE_WTP_Operations::error('equipment_invalid', 'Lectura incompleta o fecha inválida (UTC).');
        $data = array('equipment_id'=>$id,'counter_name'=>$name,'epoch'=>$epoch,'reading'=>sprintf('%.4F',$value),'observed_at'=>$date,'reset_reason'=>$reason);
        $hash = hash('sha256',wp_json_encode($data));
        return GE_WTP_Operations::transaction(function() use ($wpdb,$id,$name,$key,$epoch,$reason,$value,$data,$hash) {
            $equipment = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.GE_WTP_Operations::table('equipment').' WHERE id=%d FOR UPDATE',$id));
            if (!$equipment) GE_WTP_Operations::error('equipment_invalid', 'Equipo inexistente.');
            $t = GE_WTP_Operations::table('counter_readings');
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE idempotency_key=%s",$key), ARRAY_A);
            if ($old) { if ($old['payload_hash'] !== $hash) GE_WTP_Operations::error('equipment_invalid', 'Clave de lectura reutilizada con otro contenido.'); return $old; }
            $last = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE equipment_id=%d AND counter_name=%s ORDER BY epoch DESC, id DESC LIMIT 1",$id,$name), ARRAY_A);
            if ($last && $data['observed_at'] < $last['observed_at']) GE_WTP_Operations::error('equipment_invalid', 'La lectura es anterior a la última observación.');
            if ($last && ($epoch < (int)$last['epoch'] || ($epoch === (int)$last['epoch'] && $value < (float)$last['reading']))) GE_WTP_Operations::error('equipment_invalid', 'El contador no puede retroceder dentro de su época.');
            if (($last && $epoch > (int)$last['epoch']) || (!$last && $epoch>0)) { if (!$reason) GE_WTP_Operations::error('equipment_invalid', 'Documentá el reinicio del contador.'); }
            $data['idempotency_key']=$key; $data['payload_hash']=$hash; $data['created_by']=get_current_user_id();
            if ($wpdb->insert($t,$data) === false) GE_WTP_Operations::error('equipment_invalid', 'No se pudo registrar el contador.');
            $data['id']=(int)$wpdb->insert_id;
            GE_WTP_Operations::audit('counter_record',$data['id'],$data);
            return $data;
        });
    }
    public static function recipe_save(array $a) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $name = sanitize_text_field($a['name'] ?? '');
        $lines = $a['lines'] ?? array();
        if (!$name || strlen($name)>190 || !is_array($lines) || !$lines) GE_WTP_Operations::error('equipment_invalid', 'Receta incompleta.');
        $clean=array();
        foreach ($lines as $line) {
            $basis=$line['basis'] ?? 'output';
            if (!in_array($basis,array('output','sheet','meter','area'),true) || empty($line['material_id'])) GE_WTP_Operations::error('equipment_invalid', 'Material o base de receta inválida.');
            $x=array('material_id'=>absint($line['material_id']),'basis'=>$basis,'quantity'=>self::decimal4(self::fixed4($line['quantity'] ?? null,'cantidad')));
            $item=GE_WTP_Operations_Stock::item_get($x['material_id']);
            if (!$item || empty($item['active'])) GE_WTP_Operations::error('equipment_invalid','Material inexistente o inactivo.');
            $units=array('sheet'=>'sheet','meter'=>'m','area'=>'m2');
            if (isset($units[$basis]) && $item['unit']!==$units[$basis]) GE_WTP_Operations::error('equipment_unit','La base de receta no coincide con la unidad del insumo.');
            if (!empty($line['equipment_id'])) { $eq=self::get(absint($line['equipment_id'])); if (($eq['state']??'active')!=='active') GE_WTP_Operations::error('equipment_inactive','Equipo no activo.'); $x['equipment_id']=(int)$eq['id']; }
            if ($basis==='sheet') $x['outputs_per_sheet']=self::decimal4(self::fixed4($line['outputs_per_sheet'] ?? null,'rendimiento por hoja'));
            if ($basis==='meter' || $basis==='area') $x['length_m']=self::decimal4(self::fixed4($line['length_m'] ?? null,'largo observado'));
            if ($basis==='area') $x['width_m']=self::decimal4(self::fixed4($line['width_m'] ?? null,'ancho observado'));
            $clean[]=$x;
        }
        return GE_WTP_Operations::transaction(function() use ($wpdb,$a,$name,$clean) {
            $t=GE_WTP_Operations::table('recipes'); $id=absint($a['id'] ?? 0);
            $data=array('name'=>$name,'lines_json'=>wp_json_encode($clean),'updated_at'=>current_time('mysql',true));
            if ($id) { if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id=%d FOR UPDATE",$id))) GE_WTP_Operations::error('equipment_invalid', 'Receta inexistente.'); $data['parent_id']=$id; }
            $ok=$wpdb->insert($t,$data); $id=(int)$wpdb->insert_id;
            if ($ok===false) GE_WTP_Operations::error('equipment_invalid', 'No se pudo guardar la receta.');
            GE_WTP_Operations::audit('recipe_save',$id,array('name'=>$name,'lines'=>$clean));
            return array('id'=>$id,'name'=>$name,'lines'=>$clean);
        });
    }
    public static function recipe_estimate($id,$outputs,$duplex=1,$waste_factor=null) {
        global $wpdb;
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $units=self::fixed4($outputs,'producción');
        if ($units%10000 || !in_array((string)$duplex,array('1','2'),true) || $waste_factor===null) GE_WTP_Operations::error('equipment_invalid', 'Indicá unidades enteras, caras 1/2 y factor de merma explícito.');
        $outputs=intdiv($units,10000); $waste=self::fixed4($waste_factor,'factor de merma');
        if ($waste<10000) GE_WTP_Operations::error('equipment_invalid', 'El factor de merma debe ser al menos 1.');
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.GE_WTP_Operations::table('recipes').' WHERE id=%d',$id),ARRAY_A);
        if (!$row) GE_WTP_Operations::error('equipment_invalid', 'Receta inexistente.');
        $lines=json_decode($row['lines_json'],true); $result=array();
        if (!is_array($lines) || !$lines) GE_WTP_Operations::error('equipment_invalid', 'Receta dañada.');
        foreach ($lines as $line) {
            $base=$outputs;
            if ($line['basis']==='sheet') $base=self::ratio_ceil(array($outputs,10000),array(self::fixed4($line['outputs_per_sheet'],'rendimiento'),(int)$duplex));
            $top=array($base,self::fixed4($line['quantity'],'cantidad'),$waste); $bottom=array(10000,10000);
            if ($line['basis']==='meter' || $line['basis']==='area') { $top[]=self::fixed4($line['length_m'],'largo'); $bottom[]=10000; }
            if ($line['basis']==='area') { $top[]=self::fixed4($line['width_m'],'ancho'); $bottom[]=10000; }
            if ($line['basis']==='sheet') $qty=(string)self::ratio_ceil($top,$bottom);
            else { $top[]=10000; $qty=self::decimal4(self::ratio_ceil($top,$bottom)); }
            $result[]=array('material_id'=>$line['material_id'],'quantity'=>$qty,'basis'=>$line['basis'],'equipment_id'=>$line['equipment_id']??null);
        }
        return array('recipe_id'=>(int)$id,'outputs'=>$outputs,'duplex'=>(int)$duplex,'waste_factor'=>self::decimal4($waste),'lines'=>$result,'estimate_only'=>true);
    }
    public static function consume_for_order(array $a) {
        if (!GE_WTP_Operations::permission('stock')) GE_WTP_Operations::error('forbidden', 'Sin permiso de stock.');
        $id=absint($a['order_id'] ?? 0); $key=trim((string)($a['idempotency_key'] ?? ''));
        if (!$id || !$key || strlen($key)>140) GE_WTP_Operations::error('equipment_invalid', 'Pedido y clave de consumo obligatorios.');
        return GE_WTP_Operations::transaction(function() use ($a,$id,$key) {
            $source=GE_WTP_Operations::order_source($id);
            if ($source['source']!=='internal' && empty($source['materials_authorized'])) GE_WTP_Operations::error('equipment_invalid', 'El pedido no autoriza consumo de materiales propios.');
            $estimate=self::recipe_estimate($a['recipe_id'] ?? 0,$a['outputs'] ?? null,$a['duplex'] ?? 1,$a['waste_factor'] ?? null);
            $moves=array();
            foreach ($estimate['lines'] as $i=>$line) $moves[]=GE_WTP_Operations_Stock::movement(array('item_id'=>$line['material_id'],'quantity'=>$line['quantity'],'type'=>'consumption','order_id'=>$id,'idempotency_key'=>$key.':'.$i,'currency'=>$a['currency']??wc_get_order($id)->get_currency(),'note'=>'Consumo explícito de receta '.$estimate['recipe_id']));
            GE_WTP_Operations::audit('recipe_consumption',$id,array('estimate'=>$estimate,'movement_count'=>count($moves)));
            return array('order_id'=>$id,'estimate'=>$estimate,'movements'=>$moves);
        });
    }
}
