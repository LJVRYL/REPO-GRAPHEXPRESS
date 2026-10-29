<?php
ob_start();require __DIR__.'/windbanners-reconciliation.php';ob_end_clean();
$out=array();foreach($products as $family=>$p){if(!$p['options'])continue;$out[$family]=array_merge(array('options'=>$p['options']),GE_WTP_Windbanners_Catalog::selector_config($family,$p['options']));}
echo json_encode($out,JSON_UNESCAPED_UNICODE);
