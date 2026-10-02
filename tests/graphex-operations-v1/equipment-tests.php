<?php
/** Run only with wp eval-file in the isolated QA WordPress copy. Everything rolls back. */
if (!defined('ABSPATH') || !class_exists('GE_WTP_Operations')) throw new RuntimeException('QA WordPress bootstrap required.');
global $wpdb;
$admins=get_users(array('role'=>'administrator','number'=>1));
if (!$admins) throw new RuntimeException('QA administrator required.');
wp_set_current_user($admins[0]->ID);
$passed=0;
$check=function($condition,$message) use(&$passed) { if (!$condition) throw new RuntimeException($message); $passed++; };
$reject=function($fn,$message) use($check) { $failed=false; try { $fn(); } catch (Throwable $e) { $failed=true; } $check($failed,$message); };
try {
    GE_WTP_Operations::transaction(function() use($wpdb,$check,$reject) {
        $tag='eq-qa-'.wp_generate_uuid4();
        $machine=GE_WTP_Operations_Equipment::equipment_save(array('code'=>$tag,'name'=>'Rollback QA','model'=>'Manual fixture'));
        $a=array('equipment_id'=>$machine['id'],'counter_name'=>'total','reading'=>100,'epoch'=>0,'observed_at'=>'2026-10-01 12:00:00','idempotency_key'=>$tag.':reading');
        $first=GE_WTP_Operations_Equipment::counter_record($a);
        $repeat=GE_WTP_Operations_Equipment::counter_record($a);
        $check((int)$first['id']===(int)$repeat['id'],'Counter replay duplicated');
        $changed=$a; $changed['reading']=101;
        $reject(function() use($changed) { GE_WTP_Operations_Equipment::counter_record($changed); },'Counter key content mismatch accepted');
        $lower=$a; $lower['reading']=99; $lower['idempotency_key']=$tag.':lower';
        $reject(function() use($lower) { GE_WTP_Operations_Equipment::counter_record($lower); },'Counter regression accepted');
        $reset=$lower; $reset['epoch']=1; $reset['reading']=0; $reset['idempotency_key']=$tag.':reset';
        $reject(function() use($reset) { GE_WTP_Operations_Equipment::counter_record($reset); },'Undocumented reset accepted');
        $reset['reset_reason']='Counter replacement fixture'; GE_WTP_Operations_Equipment::counter_record($reset);
        $check(count(GE_WTP_Operations_Equipment::readings($machine['id']))===2,'Counter rollback or reset failed');
        $paper=GE_WTP_Operations_Stock::item_save(array('sku'=>$tag.'-paper','name'=>'QA paper','unit'=>'sheet','category'=>'paper','format'=>'QA A4'));
        $second=GE_WTP_Operations_Stock::item_save(array('sku'=>$tag.'-second','name'=>'QA second','unit'=>'unit','category'=>'other'));
        GE_WTP_Operations_Stock::movement(array('item_id'=>$paper['id'],'type'=>'opening','quantity'=>'100','note'=>'QA physical count','idempotency_key'=>$tag.':opening'));
        $recipe=GE_WTP_Operations_Equipment::recipe_save(array('name'=>'QA duplex','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'sheet','quantity'=>1,'outputs_per_sheet'=>2))));
        $estimate=GE_WTP_Operations_Equipment::recipe_estimate($recipe['id'],9,2,1.1);
        $check($estimate['lines'][0]['quantity']==4,'Sheet rounding duplex/waste incorrect');
        $exact=GE_WTP_Operations_Equipment::recipe_save(array('name'=>'QA exact sheets','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'sheet','quantity'=>'1','outputs_per_sheet'=>'1','equipment_id'=>$machine['id']))));
        $check(GE_WTP_Operations_Equipment::recipe_estimate($exact['id'],200,2,'1.1')['lines'][0]['quantity']==110,'Decimal ceil overconsumed 110 sheets as 111');
        $check((int)$exact['lines'][0]['equipment_id']===(int)$machine['id'],'Recipe dropped equipment association');
        $reject(function() use($exact) { GE_WTP_Operations_Equipment::recipe_estimate($exact['id'],200,2,'1.10001'); },'Waste precision silently rounded');
        $reject(function() use($second) { GE_WTP_Operations_Equipment::recipe_save(array('name'=>'Wrong sheet unit','lines'=>array(array('material_id'=>$second['id'],'basis'=>'sheet','quantity'=>1,'outputs_per_sheet'=>1)))); },'Sheet formula accepted unit stock');
        $reject(function() { GE_WTP_Operations_Equipment::recipe_save(array('name'=>'Missing material','lines'=>array(array('basis'=>'sheet','quantity'=>1,'outputs_per_sheet'=>1)))); },'Formula accepted missing material/unit');
        $reject(function() use($paper) { GE_WTP_Operations_Equipment::recipe_save(array('name'=>'Wrong meter unit','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'meter','quantity'=>1,'length_m'=>1)))); },'Meter formula accepted sheet stock');
        $reject(function() use($paper) { GE_WTP_Operations_Equipment::recipe_save(array('name'=>'Bad precision','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'sheet','quantity'=>'1.00001','outputs_per_sheet'=>1)))); },'Recipe quantity precision silently rounded');
        $reject(function() use($recipe) { GE_WTP_Operations_Equipment::recipe_estimate($recipe['id'],9,2); },'Implicit waste accepted');
        $version=GE_WTP_Operations_Equipment::recipe_save(array('id'=>$recipe['id'],'name'=>'QA revision','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'output','quantity'=>2))));
        $check($version['id']!==$recipe['id'] && GE_WTP_Operations_Equipment::recipe_estimate($recipe['id'],9,2,1.1)['lines'][0]['quantity']==4,'Recipe overwrote historical version');
        $order=wc_create_order(); $order->update_meta_data('_ge_production_source','supplier'); $order->save();
        $request=array('order_id'=>$order->get_id(),'recipe_id'=>$recipe['id'],'outputs'=>9,'duplex'=>2,'waste_factor'=>1.1,'idempotency_key'=>$tag.':consume');
        $before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_WTP_Operations::table('stock_moves'));
        $reject(function() use($request) { GE_WTP_Operations_Equipment::consume_for_order($request); },'External order consumed without authorization');
        $check($before===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_WTP_Operations::table('stock_moves')),'External block wrote stock');
        $order->update_meta_data('_ge_production_source','internal'); $order->save();
        $two=GE_WTP_Operations_Equipment::recipe_save(array('name'=>'QA atomic','lines'=>array(array('material_id'=>$paper['id'],'basis'=>'output','quantity'=>1),array('material_id'=>$second['id'],'basis'=>'output','quantity'=>1))));
        $request['recipe_id']=$two['id']; $request['outputs']=1;
        $reject(function() use($request) { GE_WTP_Operations_Equipment::consume_for_order($request); },'Insufficient second material accepted');
        $check($before===(int)$wpdb->get_var('SELECT COUNT(*) FROM '.GE_WTP_Operations::table('stock_moves')),'Multiline consumption did not roll back first movement');
        $laminate=GE_WTP_Operations_Stock::item_save(array('sku'=>$tag.'-laminate','name'=>'QA film meters','unit'=>'m','category'=>'laminate'));
        $filmarea=GE_WTP_Operations_Stock::item_save(array('sku'=>$tag.'-laminate-area','name'=>'QA film area','unit'=>'m2','category'=>'laminate'));
        GE_WTP_Operations_Stock::movement(array('item_id'=>$laminate['id'],'type'=>'opening','quantity'=>'0','note'=>'QA verified zero starting count','idempotency_key'=>$tag.':film-opening'));
        $film_moves=array();
        foreach (array(array('receipt','12.3456'),array('consumption','2.0001'),array('reservation','3'),array('consume_reserved','1.0002'),array('release','1.9998'),array('waste','0.1234')) as $i=>$spec) {
            $film_moves[]=GE_WTP_Operations_Stock::movement(array('item_id'=>$laminate['id'],'type'=>$spec[0],'quantity'=>$spec[1],'order_id'=>$order->get_id(),'note'=>'QA laminate process','idempotency_key'=>$tag.':film:'.$i));
        }
        $film_moves[]=GE_WTP_Operations_Stock::movement(array('item_id'=>$filmarea['id'],'type'=>'receipt','quantity'=>'7','idempotency_key'=>$tag.':area'));
        $film_moves[]=GE_WTP_Operations_Stock::movement(array('item_id'=>$paper['id'],'type'=>'receipt','quantity'=>'0.1111','idempotency_key'=>$tag.':nonfilm'));
        foreach ($film_moves as $m) $wpdb->update(GE_WTP_Operations::table('stock_moves'),array('created_at'=>'2031-04-15 12:00:00'),array('id'=>$m['id']));
        $older=GE_WTP_Operations_Stock::movement(array('item_id'=>$laminate['id'],'type'=>'receipt','quantity'=>'9','idempotency_key'=>$tag.':film-old'));
        $wpdb->update(GE_WTP_Operations::table('stock_moves'),array('created_at'=>'2031-03-31 23:59:59'),array('id'=>$older['id']));
        $lamination=GE_WTP_Operations_Equipment::lamination_report(array('month'=>'2031-04'));
        $check($lamination['meters']===array('received'=>'12.3456','consumed'=>'3.0003','waste'=>'0.1234'),'Lamination totals lost precision, mixed units, or counted reservations/other months');
        $check($lamination['linked_order_count']===1 && $lamination['order_ids']===array($order->get_id()),'Lamination repeated order counted multiple times');
        $check($lamination['movement_count']===5 && count($lamination['items'])===2,'Lamination included nonfilm/reservation/release');
        $check($lamination['sheet_count']===null && $lamination['roll_count']===null,'Lamination invented sheet or roll counts');
        $area=array_values(array_filter($lamination['items'],function($r) use($filmarea) { return $r['item_id']===(int)$filmarea['id']; }));
        $check(count($area)===1 && $area[0]['received']==='7.0000' && $area[0]['unit']==='m2','Area record missing or converted without physical evidence');
        $reject(function() { GE_WTP_Operations_Equipment::lamination_report(array('month'=>'2031-13')); },'Invalid report month accepted');
        throw new RuntimeException('__QA_ROLLBACK_SUCCESS__');
    });
} catch (Throwable $e) { if ($e->getMessage()!=='__QA_ROLLBACK_SUCCESS__') throw $e; }
echo $passed." equipment integration checks passed; fixtures rolled back.\n";
