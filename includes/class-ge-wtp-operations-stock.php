<?php
if (!defined('ABSPATH')) { exit; }

/** Operational stock. Quantities are integer ten-thousandths internally. */
final class GE_WTP_Operations_Stock {
    private static function t($key) { return GE_WTP_Operations::table($key); }
    private static function fail($code, $message) { return GE_WTP_Operations::error($code, $message); }
    private static function qty($value) {
        $s = (string) $value;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $s)) { return null; }
        $p = explode('.', $s);
        return ((int) $p[0]) * 10000 + (int) str_pad(isset($p[1]) ? $p[1] : '', 4, '0');
    }
    private static function decimal($q) { return intdiv($q, 10000) . '.' . str_pad((string) ($q % 10000), 4, '0', STR_PAD_LEFT); }
    private static function now() { return current_time('mysql', true); }
    private static function insert($table, $data) {
        global $wpdb;
        if (false === $wpdb->insert(self::t($table), $data)) { throw new RuntimeException('Stock database write failed'); }
        return (int) $wpdb->insert_id;
    }
    public static function schema() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $schema=array(
            'CREATE TABLE '.self::t('stock_items')." (id bigint unsigned NOT NULL AUTO_INCREMENT, sku varchar(96) NOT NULL, name varchar(190) NOT NULL, unit varchar(24) NOT NULL, category varchar(64) NOT NULL, format varchar(190) DEFAULT NULL, metadata longtext DEFAULT NULL, minimum_qty decimal(18,4) DEFAULT NULL, stock_known tinyint NOT NULL DEFAULT 0, active tinyint NOT NULL DEFAULT 1, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY sku (sku)) ENGINE=InnoDB $c;",
            'CREATE TABLE '.self::t('stock_moves')." (id bigint unsigned NOT NULL AUTO_INCREMENT, item_id bigint unsigned NOT NULL, order_id bigint unsigned DEFAULT NULL, movement_type varchar(24) NOT NULL, quantity decimal(18,4) NOT NULL, unit_cost decimal(18,4) DEFAULT NULL, currency char(3) NOT NULL DEFAULT 'ARS', source_ref varchar(190) DEFAULT NULL, idempotency_key varchar(190) NOT NULL, fingerprint char(64) NOT NULL, note text DEFAULT NULL, actor_id bigint unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY item_order (item_id,order_id)) ENGINE=InnoDB $c;",
            'CREATE TABLE '.self::t('purchases')." (id bigint unsigned NOT NULL AUTO_INCREMENT, supplier_id bigint unsigned NOT NULL, status varchar(24) NOT NULL DEFAULT 'ordered', currency char(3) NOT NULL DEFAULT 'ARS', due_date date DEFAULT NULL, payable_id bigint unsigned DEFAULT NULL, idempotency_key varchar(190) NOT NULL, fingerprint char(64) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key)) ENGINE=InnoDB $c;",
            'CREATE TABLE '.self::t('purchase_lines')." (id bigint unsigned NOT NULL AUTO_INCREMENT, purchase_id bigint unsigned NOT NULL, item_id bigint unsigned NOT NULL, quantity decimal(18,4) NOT NULL, unit varchar(24) NOT NULL, unit_cost decimal(18,4) DEFAULT NULL, received_qty decimal(18,4) NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY purchase_id (purchase_id)) ENGINE=InnoDB $c;",
            'CREATE TABLE '.self::t('receipts')." (id bigint unsigned NOT NULL AUTO_INCREMENT, purchase_id bigint unsigned NOT NULL, purchase_line_id bigint unsigned NOT NULL, quantity decimal(18,4) NOT NULL, evidence_ref varchar(190) NOT NULL, idempotency_key varchar(190) NOT NULL, fingerprint char(64) NOT NULL, movement_id bigint unsigned NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY idempotency_key (idempotency_key), KEY purchase_id (purchase_id)) ENGINE=InnoDB $c;"
        );
        return array_map(function($sql){return str_replace(', ', ",\n ", $sql);},$schema);
    }
    public static function item_get($id) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('stock_items').' WHERE id=%d', $id), ARRAY_A);
        if($row) { $row['balance']=self::balance($id); $row['costs']=self::costs($id); }
        return $row;
    }
    public static function items($filters = array()) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        global $wpdb;
        $where = ' WHERE active=1';
        if (!empty($filters['category'])) { $where .= $wpdb->prepare(' AND category=%s', $filters['category']); }
        $query=trim((string)($filters['query']??$filters['q']??'')); if($query!=='') { $like='%'.$wpdb->esc_like($query).'%'; $where.=$wpdb->prepare(' AND (name LIKE %s OR sku LIKE %s OR format LIKE %s)',$like,$like,$like); }
        $rows = $wpdb->get_results('SELECT * FROM '.self::t('stock_items').$where.' ORDER BY name LIMIT 500', ARRAY_A);
        foreach ($rows as &$row) { $row['balance'] = self::balance($row['id']); $row['costs']=self::costs($row['id']); }
        return $rows;
    }
    private static function costs($id) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT quantity,unit_cost,currency FROM '.self::t('stock_moves')." WHERE item_id=%d AND movement_type='receipt' ORDER BY id",$id),ARRAY_A);
        $groups=array(); foreach($rows as $r) { $groups[$r['currency']][]=$r; }
        $by_currency=array();
        foreach($groups as $currency=>$currency_rows) {
        $weighted=0; $total=0; $unknown=false; $last=null;
        foreach($currency_rows as $r) {
            $last=$r['unit_cost'];
            if($last===null) {$unknown=true;continue;}
            $q=self::qty($r['quantity']); $cost=self::qty($last);
            if(($cost && $q>intdiv(PHP_INT_MAX-$weighted,$cost)) || $total>PHP_INT_MAX-$q) {$unknown=true;continue;}
            $weighted+=$q*$cost; $total+=$q;
        }
        $by_currency[$currency]=array('last_unit_cost'=>$last,'average_unit_cost'=>$unknown||!$total?null:self::decimal(intdiv($weighted,$total)));
        }
        $single=count($by_currency)===1?reset($by_currency):array('last_unit_cost'=>null,'average_unit_cost'=>null);
        return $single+array('by_currency'=>$by_currency,'currency'=>count($by_currency)===1?key($by_currency):null,'basis'=>'receipt_history','opening_cost_unknown'=>true);
    }
    private static function balance($id, $order = null) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT movement_type,quantity,order_id FROM '.self::t('stock_moves').' WHERE item_id=%d ORDER BY id', $id), ARRAY_A);
        $physical = 0; $reserved = 0; $own = 0;
        foreach ($rows as $r) {
            $q = self::qty($r['quantity']); $type = $r['movement_type'];
            if ($type === 'opening') { $physical = $q; }
            if (in_array($type, array('receipt','adjustment_in','return_in'), true)) { $physical += $q; }
            if (in_array($type, array('consumption','consume_reserved','waste','adjustment_out','return_supplier','writeoff'), true)) { $physical -= $q; }
            $delta = $type === 'reservation' ? $q : (in_array($type, array('release','consume_reserved'), true) ? -$q : 0);
            $reserved += $delta;
            if ($order && (int)$r['order_id'] === (int)$order) { $own += $delta; }
        }
        return array('physical'=>self::decimal($physical), 'reserved'=>self::decimal($reserved), 'available'=>self::decimal($physical-$reserved), 'own'=>self::decimal($own));
    }
    public static function item_save($d) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        $unit = isset($d['unit']) ? sanitize_key($d['unit']) : '';
        $category = isset($d['category']) ? sanitize_key($d['category']) : '';
        if (empty($d['sku']) || empty($d['name']) || !in_array($unit,array('sheet','unit','m','m2','kg','l','roll','box','kit','ml','cartridge','ribbon'),true)) { return self::fail('invalid_item','SKU, nombre y unidad válida requeridos.'); }
        if ($category === 'paper' && empty($d['format'])) { return self::fail('paper_format','El papel requiere formato explícito.'); }
        $min = isset($d['minimum_qty']) && $d['minimum_qty'] !== '' ? self::qty($d['minimum_qty']) : null;
        if (isset($d['minimum_qty']) && $d['minimum_qty'] !== '' && $min === null) { return self::fail('invalid_quantity','Mínimo inválido.'); }
        return GE_WTP_Operations::transaction(function() use ($d,$unit,$category,$min) {
            global $wpdb;
            $data = array('sku'=>sanitize_text_field($d['sku']),'name'=>sanitize_text_field($d['name']),'unit'=>$unit,'category'=>$category,'format'=>empty($d['format'])?null:sanitize_text_field($d['format']),'minimum_qty'=>$min===null?null:self::decimal($min));
            if(isset($d['active'])) { $data['active']=empty($d['active'])?0:1; }
            if(isset($d['metadata'])) {
                $meta=array();
                foreach(array('location','reorder','notes') as $key) { if(isset($d['metadata'][$key])) { $meta[$key]=sanitize_textarea_field($d['metadata'][$key]); } }
                if(array_key_exists('reorder',$d['metadata'])) {
                    $reorder=$d['metadata']['reorder'];
                    if($reorder===null || $reorder==='') { $meta['reorder']=null; }
                    else { $parsed=self::qty($reorder); if($parsed===null) { return self::fail('invalid_reorder','Punto de reposición inválido.'); } $meta['reorder']=self::decimal($parsed); }
                }
                foreach(array('preferred_supplier_id','alternate_supplier_ids') as $key) {
                    if(isset($d['metadata'][$key])) {
                        $ids=array_map('intval',(array)$d['metadata'][$key]);
                        foreach($ids as $supplier) { if(!GE_WTP_Operations::supplier_exists($supplier)) { return self::fail('invalid_supplier','Proveedor inexistente.'); } }
                        $meta[$key]=$key==='preferred_supplier_id'?reset($ids):$ids;
                    }
                }
                $data['metadata']=wp_json_encode($meta);
            }
            if (!empty($d['id'])) {
                $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('stock_items').' WHERE id=%d FOR UPDATE',$d['id']),ARRAY_A);
                if (!$old) { return self::fail('missing_item','Insumo inexistente.'); }
                if ($old['unit'] !== $unit && $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::t('stock_moves').' WHERE item_id=%d',$old['id']))) { return self::fail('immutable_unit','La unidad base no puede cambiar después de registrar movimientos.'); }
                if (false === $wpdb->update(self::t('stock_items'),$data,array('id'=>$d['id']))) { throw new RuntimeException('Item update failed'); }
                $id = (int)$d['id'];
            } else { $data['created_at']=self::now(); $id=self::insert('stock_items',$data); }
            GE_WTP_Operations::audit('stock_item',$id,$data);
            return self::item_get($id);
        });
    }
    public static function movement($d) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        $q = isset($d['quantity']) ? self::qty($d['quantity']) : null;
        $type = isset($d['type']) ? $d['type'] : '';
        if ($q === null || ($q === 0 && $type !== 'opening') || empty($d['item_id']) || empty($d['idempotency_key']) || !in_array($type,array('opening','receipt','reservation','release','consumption','consume_reserved','waste','adjustment_in','adjustment_out','return_in','return_supplier','writeoff'),true)) { return self::fail('invalid_movement','Movimiento o cantidad inválidos.'); }
        if (in_array($type,array('opening','adjustment_in','adjustment_out','waste','return_in','return_supplier','writeoff'),true) && empty($d['note'])) { return self::fail('reason_required','Se requiere motivo o evidencia del conteo.'); }
        $cost = isset($d['unit_cost']) && $d['unit_cost'] !== '' ? self::qty($d['unit_cost']) : null;
        $currency=isset($d['currency'])?strtoupper($d['currency']):'ARS';
        if(!preg_match('/^[A-Z]{3}$/D',$currency)) { return self::fail('currency','Moneda inválida.'); }
        if (isset($d['unit_cost']) && $d['unit_cost'] !== '' && $cost === null) { return self::fail('invalid_cost','Costo inválido.'); }
        $payload = array('item_id'=>(int)$d['item_id'],'order_id'=>empty($d['order_id'])?null:(int)$d['order_id'],'movement_type'=>$type,'quantity'=>self::decimal($q),'unit_cost'=>$cost===null?null:self::decimal($cost),'source_ref'=>isset($d['source_ref'])?sanitize_text_field($d['source_ref']):null,'note'=>isset($d['note'])?sanitize_textarea_field($d['note']):null);
        $hash = hash('sha256',wp_json_encode($payload));
        $payload['currency']=$currency;
        $hash = hash('sha256',wp_json_encode($payload));
        return GE_WTP_Operations::transaction(function() use($d,$q,$type,$payload,$hash) {
            global $wpdb;
            $item=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('stock_items').' WHERE id=%d FOR UPDATE',$payload['item_id']),ARRAY_A);
            if (!$item || !$item['active']) { return self::fail('missing_item','Insumo inexistente o inactivo.'); }
            if ($item['category']==='paper' && empty($item['format'])) { return self::fail('paper_format','Completar formato de papel antes de registrar movimientos.'); }
            $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('stock_moves').' WHERE idempotency_key=%s',$d['idempotency_key']),ARRAY_A);
            if ($existing) { $existing['id']=(int)$existing['id']; return hash_equals($existing['fingerprint'],$hash)?$existing:self::fail('idempotency_conflict','La clave ya corresponde a otro movimiento.'); }
            if (in_array($type,array('reservation','release','consume_reserved'),true) && !$payload['order_id']) { return self::fail('order_required','Reserva y consumo reservado requieren pedido.'); }
            if ($payload['order_id']) {
                $source=GE_WTP_Operations::order_source($payload['order_id']);
                if (is_wp_error($source)) { return $source; }
                if (!$source || (is_array($source) && (!isset($source['source']) || !in_array($source['source'],array('internal','supplier','external','outsourced'),true)))) { return self::fail('unknown_source','Origen de producción desconocido.'); }
                $external=is_array($source)?in_array(isset($source['source'])?$source['source']:'',array('supplier','external','outsourced'),true):in_array($source,array('supplier','external','outsourced'),true);
                if ($external && in_array($type,array('reservation','consumption','consume_reserved','waste','return_in','return_supplier','writeoff'),true) && (!is_array($source)||empty($source['materials_authorized']))) { return self::fail('external_materials','Producción externa requiere autorización persistida para insumos propios.'); }
            }
            if ($type==='opening' && $item['stock_known']) { return self::fail('opening_exists','Apertura ya verificada.'); }
            if (!$item['stock_known'] && $type!=='opening' && $type!=='receipt') { return self::fail('stock_unknown','Verificá el stock inicial antes de reservar o consumir.'); }
            $b=self::balance($item['id'],$payload['order_id']);
            if (in_array($type,array('reservation','consumption','waste','adjustment_out','return_supplier','writeoff'),true) && self::qty($b['available'])<$q) { return self::fail('insufficient_stock','Stock disponible insuficiente.'); }
            if (in_array($type,array('release','consume_reserved'),true) && self::qty($b['own'])<$q) { return self::fail('insufficient_reservation','La reserva de este pedido es insuficiente.'); }
            // Fingerprint covers the request, not a changing projection: retries retain the first recorded cost.
            $cost_basis=$payload['unit_cost']!==null?'explicit':'unknown';
            if($payload['unit_cost']===null && in_array($type,array('consumption','consume_reserved','waste','writeoff'),true)) {
                $projection=self::costs($item['id']);
                $uncosted_baseline=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::t('stock_moves')." WHERE item_id=%d AND ((movement_type='opening' AND quantity>0) OR movement_type IN ('adjustment_in','return_in'))",$item['id']));
                if(!$uncosted_baseline && count($projection['by_currency'])===1 && $projection['currency']===$payload['currency'] && $projection['average_unit_cost']!==null) {
                    $payload['unit_cost']=$projection['average_unit_cost'];
                    $cost_basis='confirmed_receipt_weighted_average';
                }
            }
            $row=$payload+array('idempotency_key'=>$d['idempotency_key'],'fingerprint'=>$hash,'actor_id'=>get_current_user_id(),'created_at'=>self::now());
            $id=self::insert('stock_moves',$row);
            if($payload['order_id']&&in_array($type,array('consumption','consume_reserved','waste','writeoff','return_in'),true)) { $order=wc_get_order($payload['order_id']); $order->update_meta_data('_ge_operations_costs_complete','no'); $order->save(); }
            if ($type==='opening' && false===$wpdb->update(self::t('stock_items'),array('stock_known'=>1),array('id'=>$item['id']))) { throw new RuntimeException('Opening verification failed'); }
            GE_WTP_Operations::audit('stock_movement',$id,$row+array('cost_basis'=>$cost_basis,'cost_currency'=>$payload['currency']));
            return array('id'=>$id)+$row;
        });
    }
    public static function low_stock() {
        return array_values(array_filter(self::items(),function($i) { return $i['stock_known'] && $i['minimum_qty']!==null && self::qty($i['balance']['available'])<self::qty($i['minimum_qty']); }));
    }
    public static function replenishment_suggestions($filters=array()) {
        $suggestions=array();
        foreach(self::items($filters) as $item) {
            if(!$item['stock_known'])continue;
            $meta=json_decode((string)$item['metadata'],true);
            $reorder=is_array($meta)&&isset($meta['reorder'])?self::qty($meta['reorder']):null;
            $minimum=$item['minimum_qty']!==null?self::qty($item['minimum_qty']):null;
            $threshold=$reorder!==null?$reorder:$minimum;
            $available=self::qty($item['balance']['available']);
            if($available===null || ($available>0 && ($threshold===null || $available>$threshold)))continue;
            $suggestions[]=array('item_id'=>(int)$item['id'],'name'=>$item['name'],'unit'=>$item['unit'],'available'=>$item['balance']['available'],'reorder'=>$reorder===null?null:self::decimal($reorder),'minimum'=>$item['minimum_qty'],'reason'=>$available===0?'no_stock':($reorder!==null?'at_or_below_reorder':'at_or_below_minimum'));
        }
        return $suggestions;
    }
    /** Read-only signals; never changes reorder levels or places a purchase. */
    public static function consumption_suggestions($filters=array()) {
        GE_WTP_Operations::permission('stock'); global $wpdb;
        $days=isset($filters['days'])?(int)$filters['days']:30;
        if($days<7 || $days>90) { return self::fail('window','La ventana debe ser de 7 a 90 días.'); }
        $now=time(); $boundary=gmdate('Y-m-d H:i:s',$now-$days*DAY_IN_SECONDS); $start=gmdate('Y-m-d H:i:s',$now-2*$days*DAY_IN_SECONDS); $end=gmdate('Y-m-d H:i:s',$now);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT item_id, SUM(CASE WHEN created_at>=%s THEN quantity ELSE 0 END) recent_qty, SUM(CASE WHEN created_at<%s THEN quantity ELSE 0 END) previous_qty, SUM(created_at>=%s) recent_events, SUM(created_at<%s) previous_events FROM '.self::t('stock_moves')." WHERE movement_type IN ('consumption','consume_reserved') AND created_at>=%s AND created_at<=%s GROUP BY item_id",$boundary,$boundary,$boundary,$boundary,$start,$end),ARRAY_A);
        $signals=array();
        foreach($rows as $r) {
            $recent=self::qty($r['recent_qty']); $previous=self::qty($r['previous_qty']);
            if($recent===null||$previous===null||!$previous||$r['recent_events']<3||$r['previous_events']<3||$recent<intdiv($previous*3+1,2))continue;
            $item=self::item_get($r['item_id']); if(!$item||!$item['active'])continue;
            $signals[]=array('item_id'=>(int)$r['item_id'],'name'=>$item['name'],'unit'=>$item['unit'],'signal'=>'accelerated_consumption','days'=>$days,'recent_qty'=>$r['recent_qty'],'previous_qty'=>$r['previous_qty'],'threshold'=>'at_least_50_percent_more','minimum_events_per_window'=>3,'stock_known'=>(bool)$item['stock_known'],'available_qty'=>$item['stock_known']?$item['balance']['available']:null,'minimum_qty'=>$item['minimum_qty'],'action'=>'review_reorder_level');
        }
        return $signals;
    }
    public static function purchase_create($d) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        if (empty($d['supplier_id']) || !GE_WTP_Operations::supplier_exists((int)$d['supplier_id']) || empty($d['lines']) || empty($d['idempotency_key'])) { return self::fail('invalid_purchase','Proveedor existente, líneas y clave requeridos.'); }
        $lines=array();
        foreach($d['lines'] as $line) {
            $item=self::item_get(isset($line['item_id'])?$line['item_id']:0); $q=isset($line['quantity'])?self::qty($line['quantity']):null;
            $cost=isset($line['unit_cost']) && $line['unit_cost']!==''?self::qty($line['unit_cost']):null;
            if (!$item || !$item['active'] || !$q || (isset($line['unit']) && $line['unit']!==$item['unit']) || (isset($line['unit_cost']) && $line['unit_cost']!=='' && $cost===null)) { return self::fail('invalid_line','Insumo, unidad, cantidad o costo inválidos.'); }
            $lines[]=array('item_id'=>(int)$item['id'],'quantity'=>self::decimal($q),'unit'=>$item['unit'],'unit_cost'=>$cost===null?null:self::decimal($cost));
        }
        $currency=isset($d['currency'])?strtoupper($d['currency']):'ARS';
        if (!preg_match('/^[A-Z]{3}$/D',$currency)) { return self::fail('currency','Moneda inválida.'); }
        $due=isset($d['due_date']) && $d['due_date']!==''?$d['due_date']:null;
        if($due!==null && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$due,$date) || !checkdate((int)$date[2],(int)$date[3],(int)$date[1]))) { return self::fail('invalid_date','Vencimiento inválido.'); }
        $d['due_date']=$due;
        $hash=hash('sha256',wp_json_encode(array((int)$d['supplier_id'],$currency,$due,$lines)));
        return GE_WTP_Operations::transaction(function() use($d,$lines,$currency,$hash) {
            global $wpdb;
            $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('purchases').' WHERE idempotency_key=%s FOR UPDATE',$d['idempotency_key']),ARRAY_A);
            if($old) { $old['id']=(int)$old['id']; return hash_equals($old['fingerprint'],$hash)?self::purchase_get($old['id']):self::fail('idempotency_conflict','La clave corresponde a otra compra.'); }
            $id=self::insert('purchases',array('supplier_id'=>(int)$d['supplier_id'],'currency'=>$currency,'due_date'=>isset($d['due_date'])?$d['due_date']:null,'idempotency_key'=>$d['idempotency_key'],'fingerprint'=>$hash,'created_at'=>self::now()));
            foreach($lines as $line) { self::insert('purchase_lines',array('purchase_id'=>$id)+$line); }
            GE_WTP_Operations::audit('purchase',$id,array('supplier_id'=>$d['supplier_id'],'lines'=>$lines,'financial_status'=>'commitment'));
            return self::purchase_get($id);
        });
    }
    public static function purchase_receive($d) {
        if (!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        $q=isset($d['quantity'])?self::qty($d['quantity']):null;
        if(!$q || empty($d['purchase_line_id']) || empty($d['idempotency_key']) || empty($d['evidence_ref'])) { return self::fail('invalid_receipt','Línea, cantidad, comprobante y clave requeridos.'); }
        $hash=hash('sha256',wp_json_encode(array((int)$d['purchase_line_id'],self::decimal($q),sanitize_text_field($d['evidence_ref']))));
        return GE_WTP_Operations::transaction(function() use($d,$q,$hash) {
            global $wpdb;
            $line=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('purchase_lines').' WHERE id=%d FOR UPDATE',$d['purchase_line_id']),ARRAY_A);
            if(!$line) { return self::fail('missing_line','Línea de compra inexistente.'); }
            $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('receipts').' WHERE idempotency_key=%s',$d['idempotency_key']),ARRAY_A);
            if($old) { $old['id']=(int)$old['id']; return hash_equals($old['fingerprint'],$hash)?$old:self::fail('idempotency_conflict','La clave corresponde a otra recepción.'); }
            $received=self::qty($line['received_qty'])+$q;
            if($received>self::qty($line['quantity'])) { return self::fail('overreceipt','La recepción supera la cantidad pendiente.'); }
            $purchase=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('purchases').' WHERE id=%d FOR UPDATE',$line['purchase_id']),ARRAY_A);
            $move=self::movement(array('item_id'=>$line['item_id'],'type'=>'receipt','quantity'=>self::decimal($q),'unit_cost'=>$line['unit_cost'],'currency'=>$purchase['currency'],'source_ref'=>'purchase:'.$line['purchase_id'].':line:'.$line['id'],'note'=>$d['evidence_ref'],'idempotency_key'=>'receipt:'.$d['idempotency_key']));
            if(is_wp_error($move)) { return $move; }
            $row=array('purchase_id'=>$line['purchase_id'],'purchase_line_id'=>$line['id'],'quantity'=>self::decimal($q),'evidence_ref'=>sanitize_text_field($d['evidence_ref']),'idempotency_key'=>$d['idempotency_key'],'fingerprint'=>$hash,'movement_id'=>$move['id'],'created_at'=>self::now());
            $id=self::insert('receipts',$row);
            $purchase=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('purchases').' WHERE id=%d FOR UPDATE',$line['purchase_id']),ARRAY_A);
            if($line['unit_cost']!==null) {
                // Integer fixed-point multiplication with explicit overflow guard and cent rounding.
                $cost=self::qty($line['unit_cost']);
                if($cost && $q>intdiv(PHP_INT_MAX-500000,$cost)) { return self::fail('cost_overflow','Costo fuera de rango.'); }
                $minor=intdiv($q*$cost+500000,1000000);
                if($minor>0) {
                    $payable=GE_WTP_Operations_Finance::payable_create(array('supplier_id'=>(int)$purchase['supplier_id'],'total_minor'=>$minor,'currency'=>$purchase['currency'],'due_date'=>$purchase['due_date'],'incurred_on'=>current_time('Y-m-d'),'received_on'=>current_time('Y-m-d'),'source_type'=>'purchase_receipt','source_id'=>'receipt:'.$id,'description'=>'Compra #'.$purchase['id'].' · recepción #'.$id.' · '.$d['evidence_ref'],'attachment_ref'=>$d['evidence_ref']));
                    if(is_wp_error($payable)) { return $payable; }
                }
            }
            if(false===$wpdb->update(self::t('purchase_lines'),array('received_qty'=>self::decimal($received)),array('id'=>$line['id']))) { throw new RuntimeException('Receipt balance failed'); }
            $pending=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::t('purchase_lines').' WHERE purchase_id=%d AND received_qty<quantity',$line['purchase_id']));
            if(false===$wpdb->update(self::t('purchases'),array('status'=>$pending?'partially_received':'received'),array('id'=>$line['purchase_id']))) { throw new RuntimeException('Purchase status failed'); }
            GE_WTP_Operations::audit('purchase_receipt',$id,$row);
            return array('id'=>$id)+$row;
        });
    }
    public static function seed_catalog() {
        // No invented formats, opening balances or suppliers. Paper variants await confirmation.
        global $wpdb;
        $seeds=array();
        foreach(array('obra-80'=>'Obra 80 g','ilustracion-115'=>'Ilustración 115 g','ilustracion-150'=>'Ilustración 150 g','ilustracion-200'=>'Ilustración 200 g','ilustracion-250'=>'Ilustración 250 g','ilustracion-300'=>'Ilustración 300 g') as $sku=>$name) { $seeds[]=array('sku'=>$sku,'name'=>$name,'unit'=>'sheet','category'=>'paper','metadata'=>wp_json_encode(array('notes'=>'Formato obligatorio pendiente de confirmar; stock inicial desconocido.'))); }
        foreach(array(
            array('badgy-pvc','Badgy PVC','unit','cards','Variante pendiente.'),
            array('badgy-ribbon-color','Badgy cinta color','ribbon','ribbons','CMYK / CMYKO pendiente de verificación.'),
            array('badgy-ribbon-k','Badgy cinta K','ribbon','ribbons','Compatibilidad pendiente.'),
            array('thermal-label-roll','Etiquetas térmicas','roll','labels','Medida y presentación pendientes.'),
            array('epson-sub-a4','Papel sublimación Epson A4 · confirmar uso','sheet','paper','Uso condicionado al equipo y proceso; formato A4 confirmado por catálogo solicitado.'),
            array('epson-sub-cmyk','Tintas sublimación Epson · colores pendientes','kit','ink','Modelo pendiente; separar colores al verificar.'),
            array('xerox-toner','Tóner Xerox','cartridge','toner','Modelo y alcance de uso pendientes.'),
            array('anillos','Anillos','unit','binding','Medida y variante pendientes.'),
            array('wireo','Wire-O','unit','binding','Paso y diámetro pendientes.'),
            array('binder-glue','Adhesivo binder','kg','binding','Presentación y compatibilidad pendientes.'),
            array('laminate-roll','Laminado','m','laminate','Rollo típico 150 m como presentación informativa; NO stock. Ancho, micrones y acabado pendientes.'),
            array('bifaz','Bifaz','roll','finishing','Variante pendiente.'),
            array('ganchitos','Ganchitos','unit','finishing','Variante pendiente.'),
            array('solapas','Solapas','unit','finishing','Variante pendiente.'),
            array('tapas','Tapas','unit','binding','Material y medida pendientes.'),
            array('contratapas','Contratapas','unit','binding','Material y medida pendientes.'),
            array('pin38-components','Componentes pin 38 mm','kit','pins','Componentes y unidad de kit pendientes.'),
            array('llaveros','Llaveros','unit','accessories','Componentes y variante pendientes.')
        ) as $r) { $seeds[]=array('sku'=>$r[0],'name'=>$r[1],'unit'=>$r[2],'category'=>$r[3],'format'=>$r[0]==='epson-sub-a4'?'210 x 297 mm':null,'metadata'=>wp_json_encode(array('notes'=>$r[4]))); }
        foreach($seeds as $seed) {
            $preferred=$seed['category']==='paper'?'atawalpa':($seed['category']==='binding'?'rafer':null);
            if($preferred) {
                foreach(GE_WTP_Operations::suppliers() as $supplier_id=>$supplier) {
                    if(isset($supplier['name']) && strtolower($supplier['name'])===$preferred) {
                        $meta=json_decode($seed['metadata'],true); $meta['preferred_supplier_id']=(int)$supplier_id; $seed['metadata']=wp_json_encode($meta); break;
                    }
                }
            }
            if(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::t('stock_items').' WHERE sku=%s',$seed['sku']))) { $seed['active']=1; $seed['stock_known']=0; $seed['created_at']=self::now(); self::insert('stock_items',$seed); }
        }
    }
    public static function movements($filters=array()) {
        if(!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        global $wpdb; $where=' WHERE 1=1';
        foreach(array('item_id','order_id') as $key) { if(!empty($filters[$key])) { $where.=$wpdb->prepare(" AND $key=%d",$filters[$key]); } }
        return $wpdb->get_results('SELECT * FROM '.self::t('stock_moves').$where.' ORDER BY id DESC LIMIT 200',ARRAY_A);
    }
    public static function purchases($filters=array()) {
        if(!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        global $wpdb;
        $where='1=1';if(!empty($filters['supplier_id']))$where.=$wpdb->prepare(' AND supplier_id=%d',absint($filters['supplier_id']));$limit=max(1,min(200,absint($filters['limit']??200)));$page=max(1,absint($filters['page']??1));return $wpdb->get_results('SELECT * FROM '.self::t('purchases').' WHERE '.$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit),ARRAY_A);
    }
    public static function purchase_get($id) {
        if(!GE_WTP_Operations::permission('stock')) { return self::fail('forbidden','Sin permiso de stock.'); }
        global $wpdb;
        $p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::t('purchases').' WHERE id=%d',$id),ARRAY_A);
        if($p) { $p['lines']=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::t('purchase_lines').' WHERE purchase_id=%d',$id),ARRAY_A); $p['receipts']=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::t('receipts').' WHERE purchase_id=%d',$id),ARRAY_A); }
        return $p;
    }
}








