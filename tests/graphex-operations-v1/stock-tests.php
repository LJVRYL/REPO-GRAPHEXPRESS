<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__,2).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-operations-stock.php';
$quantity=new ReflectionMethod('GE_WTP_Operations_Stock','qty');
$quantity->setAccessible(true);
$cases=array(array('1.2345',12345),array('0',0),array('0.0001',1),array('1.00001',null),array('-1',null),array('1e3',null),array('0.1',1000),array('999999999999.9999',9999999999999999));
foreach($cases as $case) { if($quantity->invoke(null,$case[0])!==$case[1]) { throw new RuntimeException('Quantity validation failed'); } }
$decimal=new ReflectionMethod('GE_WTP_Operations_Stock','decimal'); $decimal->setAccessible(true);
foreach(array(0,1,1000,12345,9999999999999999) as $q) { if($quantity->invoke(null,$decimal->invoke(null,$q))!==$q) { throw new RuntimeException('Quantity round trip failed'); } }
echo "13 precision checks passed\n";
