<?php
$admin=get_current_user_id();$checks=0;
$check=function($ok,$msg)use(&$checks){if(!$ok)throw new RuntimeException($msg);$checks++;};
$reject=function($fn,$msg)use($check){$failed=false;try{$fn();}catch(Throwable $e){$failed=true;}$check($failed,$msg);};
wp_set_current_user(0);
$reject(function(){GE_WTP_Operations_Finance::summary(array());},'Anonymous finance blocked');
$reject(function(){GE_WTP_Operations_Stock::items();},'Anonymous stock blocked');
$reject(function(){GE_WTP_Operations_Equipment::all();},'Anonymous equipment blocked');
wp_set_current_user($admin);
$filter=function($caps){$caps['manage_options']=false;$caps['ge_view_finance']=false;$caps['ge_record_finance']=false;$caps['ge_manage_inventory']=true;return $caps;};
add_filter('user_has_cap',$filter);
$check(count(GE_WTP_Operations_Stock::items())>=24,'Inventory scope can view stock');
$reject(function(){GE_WTP_Operations_Finance::summary(array());},'Inventory scope cannot read finance');
$reject(function(){GE_WTP_Operations_Finance::expense_create(array());},'Inventory scope cannot write finance');
remove_filter('user_has_cap',$filter);
$check(isset(GE_WTP_Operations::actions()['graph.finance.summary']),'Finance action exists');
$eligible=GE_WTP_Production::eligible_suppliers();$check(isset($eligible['mardones']),'Known production supplier retained');$check(!isset($eligible['custom-rafer'])&&!isset($eligible['custom-atawalpa']),'Supplies suppliers excluded from production selector');$blocked=GE_WTP_Supplier_Portal::set_source(0,'supplier','custom-rafer');$check(is_wp_error($blocked)&&$blocked->get_error_code()==='supplier_type','Supplies assignment blocked by actual production writer');
echo "Security QA: $checks checks passed\n";
