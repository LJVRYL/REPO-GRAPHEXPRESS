<?php
/** Run with WP, Woo and operations loaded; all fixture writes rolled back. */
$checks=0;
$assert=function($ok,$message) use(&$checks) { if(!$ok) throw new RuntimeException('FAIL: '.$message); $checks++; };
$reject=function($fn,$message) use($assert) { $caught=false; try{$fn();}catch(Throwable $e){$caught=true;} $assert($caught,$message); };
$sentinel='stock-qa-rollback-'.wp_generate_uuid4();
$mail_block=function(){return false;}; add_filter('pre_wp_mail',$mail_block);
$actor=get_current_user_id();
try {
 GE_WTP_Operations::transaction(function() use($assert,$reject,$sentinel,$actor) {
  global $wpdb;
  $key='stock-qa-'.wp_generate_uuid4();
  $item=GE_WTP_Operations_Stock::item_save(array('sku'=>$key,'name'=>'QA stock','category'=>'paper','unit'=>'sheet','format'=>'QA 320 x 450 mm','minimum_qty'=>'100'));
  $id=$item['id'];
  $assert(!(bool)$item['stock_known'],'new stock unknown');
  $move=function($type,$qty,$suffix,$order=0) use($id,$key) { return GE_WTP_Operations_Stock::movement(array('item_id'=>$id,'type'=>$type,'quantity'=>$qty,'order_id'=>$order,'idempotency_key'=>$key.$suffix,'note'=>'QA explicit baseline / reason')); };
  $reject(function()use($move){$move('consumption','1','unknown');},'unknown stock blocks consumption');
  $opening=$move('opening','200','opening'); $assert($opening['id']===$move('opening','200','opening')['id'],'opening replay');
  $reject(function()use($move){$move('opening','201','opening');},'opening conflict');
  $internal=wc_create_order(); $internal->update_meta_data('_ge_production_source','internal'); $internal->save();
  $external=wc_create_order(); $external->update_meta_data('_ge_production_source','supplier'); $external->save();
  $unknown=wc_create_order(); $unknown->update_meta_data('_ge_production_source','unknown'); $unknown->save();
  $move('reservation','200','reserve',$internal->get_id());
  $rows=GE_WTP_Operations_Stock::items(); $find=function($rows)use($id){foreach($rows as $r)if((int)$r['id']===(int)$id)return $r; throw new RuntimeException('Missing fixture');};
  $assert($find($rows)['balance']['available']==='0.0000','full reservation available zero');
  $move('consume_reserved','100','consume',$internal->get_id());
  $move('release','50','release',$internal->get_id());
  $b=$find(GE_WTP_Operations_Stock::items())['balance']; $assert($b['physical']==='100.0000' && $b['reserved']==='50.0000' && $b['available']==='50.0000','own consume and partial release');
  $returned=$move('return_in','10','return-job',$internal->get_id());
  $assert($returned['unit_cost']===null,'job material return does not invent cost');
  $move('return_supplier','5','return-supplier');
  $move('writeoff','5','writeoff',$internal->get_id());
  $b=$find(GE_WTP_Operations_Stock::items())['balance'];
  $assert($b['physical']==='100.0000' && $b['reserved']==='50.0000','return and writeoff preserve reservations');
  foreach(array('return_supplier','writeoff') as $negative_type) {$reject(function()use($move,$negative_type){$move($negative_type,'51','reserved-protection-'.$negative_type);},'cannot remove reserved stock '.$negative_type);}
  $reject(function()use($move,$external){$move('return_in','1','external-return',$external->get_id());},'external job return requires authorization');
  $reject(function()use($move,$internal){$move('release','51','excess-release',$internal->get_id());},'cannot release other stock');
  $reject(function()use($move,$external){$move('consumption','1','external',$external->get_id());},'supplier production no internal deduction');
  $reject(function()use($move,$unknown){$move('consumption','1','source-unknown',$unknown->get_id());},'unknown production source rejected');
  foreach(array('-1','+1','1e3','0','1.00001') as $idx=>$quantity) { $reject(function()use($move,$quantity,$idx){$move('receipt',$quantity,'bad-'.$idx);},'invalid signed/precision quantity '.$quantity); }
  $reject(function()use($key){GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'paper-bad','name'=>'QA paper','category'=>'paper','unit'=>'sheet'));},'paper format required');
  $supplier=(int)$wpdb->get_var('SELECT id FROM '.GE_WTP_Operations::table('supplier_refs').' ORDER BY id LIMIT 1');
  $assert($supplier && GE_WTP_Operations::supplier_exists($supplier),'existing supplier reference');
  $purchase=GE_WTP_Operations_Stock::purchase_create(array('supplier_id'=>$supplier,'idempotency_key'=>$key.'purchase','currency'=>'ARS','lines'=>array(array('item_id'=>$id,'quantity'=>'10','unit_cost'=>'2.5000'))));
  $line=GE_WTP_Operations_Stock::purchase_get($purchase['id'])['lines'][0];
  $receive=array('purchase_line_id'=>$line['id'],'quantity'=>'4','evidence_ref'=>'QA receipt','idempotency_key'=>$key.'receipt-1');
  $receipt=GE_WTP_Operations_Stock::purchase_receive($receive); $again=GE_WTP_Operations_Stock::purchase_receive($receive);
  $assert((int)$receipt['id']===(int)$again['id'],'receipt replay no duplicate');
  $p=GE_WTP_Operations_Stock::purchase_get($purchase['id']); $assert($p['status']==='partially_received' && $p['lines'][0]['received_qty']==='4.0000' && count($p['receipts'])===1,'partial receipt');
  $reject(function()use($receive){$receive['quantity']='7';$receive['idempotency_key'].='over';GE_WTP_Operations_Stock::purchase_receive($receive);},'overreceipt rejected');
  $receive['quantity']='6';$receive['idempotency_key']=$key.'receipt-2'; GE_WTP_Operations_Stock::purchase_receive($receive);
  $p=GE_WTP_Operations_Stock::purchase_get($purchase['id']); $assert($p['status']==='received' && count($p['receipts'])===2,'full receipt');
  $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.GE_WTP_Operations::table('payables').' WHERE source_ref IN (%s,%s)','receipt:'.$receipt['id'],'receipt:'.$p['receipts'][1]['id']));
  $assert($count===2,'one payable per known-cost receipt');
  GE_WTP_Operations_Stock::movement(array('item_id'=>$id,'type'=>'receipt','quantity'=>'1','unit_cost'=>'3','currency'=>'USD','idempotency_key'=>$key.'usd'));
  $costs=$find(GE_WTP_Operations_Stock::items())['costs'];
  $assert($costs['average_unit_cost']===null && isset($costs['by_currency']['ARS'],$costs['by_currency']['USD']),'mixed currency averages never combined');
  $fresh=GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'fresh','name'=>'QA counted after receipts','category'=>'qa','unit'=>'unit'));
  GE_WTP_Operations_Stock::movement(array('item_id'=>$fresh['id'],'type'=>'receipt','quantity'=>'5','idempotency_key'=>$key.'before-count'));
  GE_WTP_Operations_Stock::movement(array('item_id'=>$fresh['id'],'type'=>'opening','quantity'=>'20','note'=>'QA actual physical count including receipt','idempotency_key'=>$key.'physical-count'));
  foreach(GE_WTP_Operations_Stock::items() as $r) {if((int)$r['id']===(int)$fresh['id'])$assert($r['balance']['physical']==='20.0000','opening physical count includes prior receipts once');}
  $costed=GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'costed','name'=>'QA reliable costs','category'=>'qa','unit'=>'unit'));
  $record=function($type,$qty,$suffix,$extra=array())use($costed,$key){return GE_WTP_Operations_Stock::movement(array_merge(array('item_id'=>$costed['id'],'type'=>$type,'quantity'=>$qty,'idempotency_key'=>$key.'cost-'.$suffix,'note'=>'QA cost basis'),$extra));};
  $record('opening','0','zero');
  $record('receipt','10','receipt-a',array('unit_cost'=>'2','currency'=>'ARS'));
  $record('receipt','10','receipt-b',array('unit_cost'=>'4','currency'=>'ARS'));
  $derived=$record('consumption','1','derived');
  $assert($derived['unit_cost']==='3.0000','known same-currency weighted cost derives');
  $audit=$wpdb->get_var($wpdb->prepare('SELECT payload FROM '.GE_WTP_Operations::table('audit')." WHERE event_type='stock_movement' AND object_ref=%s ORDER BY id DESC LIMIT 1",$derived['id']));
  $assert(json_decode($audit,true)['cost_basis']==='confirmed_receipt_weighted_average','derived basis audited');
  $mismatch=$record('waste','1','mismatch',array('currency'=>'USD'));
  $assert($mismatch['unit_cost']===null,'currency mismatch stays unknown');
  $record('receipt','1','unknown');
  $assert($record('consumption','1','unknown-consume')['unit_cost']===null,'unknown receipt prevents invented average');
  $assert($record('consumption','1','derived')['unit_cost']==='3.0000','derived replay retains original cost after projection changes');
  $explicit=$record('waste','1','explicit',array('unit_cost'=>'8'));
  $assert($explicit['unit_cost']==='8.0000','explicit confirmed cost retained');
  $return_costed=GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'returned-cost','name'=>'QA unvalued return','category'=>'qa','unit'=>'unit'));
  foreach(array(array('opening','0',null),array('receipt','10','2'),array('return_in','1',null)) as $index=>$entry) {
      GE_WTP_Operations_Stock::movement(array('item_id'=>$return_costed['id'],'type'=>$entry[0],'quantity'=>$entry[1],'unit_cost'=>$entry[2],'note'=>'QA return cost completeness','idempotency_key'=>$key.'return-cost-'.$index));
  }
  $after_return=GE_WTP_Operations_Stock::movement(array('item_id'=>$return_costed['id'],'type'=>'consumption','quantity'=>'1','idempotency_key'=>$key.'consume-after-return'));
  $assert($after_return['unit_cost']===null,'unvalued returned material blocks automatic receipt average');
  $reorder_item=GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'reorder','name'=>'QA reorder','category'=>'qa','unit'=>'unit','minimum_qty'=>'2','metadata'=>array('reorder'=>'10.2500')));
  $assert(json_decode(GE_WTP_Operations_Stock::item_get($reorder_item['id'])['metadata'],true)['reorder']==='10.2500','stored fixed reorder roundtrip');
  GE_WTP_Operations_Stock::movement(array('item_id'=>$reorder_item['id'],'type'=>'opening','quantity'=>'8','note'=>'QA threshold','idempotency_key'=>$key.'reorder-opening'));
  $reorder_signal=null;foreach(GE_WTP_Operations_Stock::replenishment_suggestions() as $s)if($s['item_id']===(int)$reorder_item['id'])$reorder_signal=$s;
  $assert($reorder_signal && $reorder_signal['reason']==='at_or_below_reorder' && $reorder_signal['available']==='8.0000','reorder threshold above minimum emits suggestion');
  $below=false;foreach(GE_WTP_Operations_Stock::low_stock() as $i)if((int)$i['id']===(int)$reorder_item['id'])$below=true;
  $assert(!$below,'reorder signal does not change low stock minimum semantics');
  GE_WTP_Operations_Stock::item_save(array('id'=>$reorder_item['id'],'sku'=>$key.'reorder','name'=>'QA reorder','category'=>'qa','unit'=>'unit','minimum_qty'=>'2','metadata'=>array('reorder'=>'8')));
  $equal=false;foreach(GE_WTP_Operations_Stock::replenishment_suggestions() as $s)if($s['item_id']===(int)$reorder_item['id'])$equal=$s['reorder']==='8.0000';
  $assert($equal,'reorder equality included');
  GE_WTP_Operations_Stock::movement(array('item_id'=>$reorder_item['id'],'type'=>'consumption','quantity'=>'8','idempotency_key'=>$key.'reorder-zero'));
  $empty=false;foreach(GE_WTP_Operations_Stock::replenishment_suggestions() as $s)if($s['item_id']===(int)$reorder_item['id'])$empty=$s['reason']==='no_stock'&&!isset($s['purchase_qty']);
  $assert($empty,'zero stock signal without invented purchase quantity');
  $reject(function()use($key){GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'bad-reorder','name'=>'QA bad','category'=>'qa','unit'=>'unit','metadata'=>array('reorder'=>'-1')));},'negative reorder rejected');
  $reject(function()use($key){GE_WTP_Operations_Stock::item_save(array('sku'=>$key.'precise-reorder','name'=>'QA bad','category'=>'qa','unit'=>'unit','metadata'=>array('reorder'=>'1.00001')));},'excess precision reorder rejected');
  for($i=0;$i<3;$i++) {
      $old=$record('consumption','1','past-'.$i);
      $wpdb->update(GE_WTP_Operations::table('stock_moves'),array('created_at'=>gmdate('Y-m-d H:i:s',time()-40*DAY_IN_SECONDS)),array('id'=>$old['id']));
      $record('consumption','2','recent-'.$i);
  }
  $signals=GE_WTP_Operations_Stock::consumption_suggestions(array('days'=>30));$signal=false;
  foreach($signals as $s)if($s['item_id']===(int)$costed['id'])$signal=$s;
  $assert($signal && $signal['previous_qty']==='3.0000' && $signal['action']==='review_reorder_level','accelerated consumption read-only review signal '.wp_json_encode($signals));
  wp_set_current_user(0);
  try {$reject(function()use($id){GE_WTP_Operations_Stock::item_get($id);},'read permission'); $reject(function()use($move){$move('receipt','1','anonymous');},'write permission');}finally{wp_set_current_user($actor);}
  throw new RuntimeException($sentinel);
 });
} catch(Throwable $e) {if($e->getMessage()!==$sentinel)throw $e;}
finally {wp_set_current_user($actor); remove_filter('pre_wp_mail',$mail_block);}
echo "$checks stock integration checks passed; all fixture writes rolled back\n";

