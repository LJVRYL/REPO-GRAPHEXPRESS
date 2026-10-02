<?php
define('ABSPATH',__DIR__);
class GE_WTP_Operations { public static function error($code,$message) { throw new RuntimeException($message); } }
require dirname(__DIR__,2).'/wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-operations-equipment.php';
$ratio=new ReflectionMethod('GE_WTP_Operations_Equipment','ratio_ceil'); $ratio->setAccessible(true);
$fixed=new ReflectionMethod('GE_WTP_Operations_Equipment','fixed4'); $fixed->setAccessible(true);
$f=function($v) use($fixed) { return $fixed->invoke(null,$v,'QA'); };
if ($ratio->invoke(null,array(100,$f('1'),$f('1.1')),array(10000,10000))!==110) throw new RuntimeException('Exact sheets failed');
if ($ratio->invoke(null,array(9,10000),array($f('2'),2))!==3) throw new RuntimeException('Duplex sheet ceil failed');
if ($ratio->invoke(null,array(3,$f('1'),$f('1.1')),array(10000,10000))!==4) throw new RuntimeException('Waste sheet ceil failed');
if ($ratio->invoke(null,array(2,$f('0.15'),$f('1.1'),$f('0.8'),10000),array(10000,10000,10000))!==2640) throw new RuntimeException('Meter exact factor failed');
if ($ratio->invoke(null,array(1,$f('0.0001'),$f('1.0001'),10000),array(10000,10000))!==2) throw new RuntimeException('Subprecision consumption ceil failed');
foreach(array('1.00001','1e2','-1','0') as $bad) { $threw=false; try {$f($bad);} catch (Throwable $e) {$threw=true;} if (!$threw) throw new RuntimeException('Invalid fixed4 accepted'); }
echo "9 exact equipment math checks passed.\n";
