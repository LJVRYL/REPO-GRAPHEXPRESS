<?php
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/calculator.php';

function check($condition, $label) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

check(abs(GE_Shipping_Calculator::paper_grams(5, 9, 300) - 1.35) < 0.001, 'card grams');
check(abs(GE_Shipping_Calculator::paper_grams(14.8, 21, 150, 5000) - 23310) < 0.001, 'A5 print run');
check(GE_Shipping_Calculator::postcode('c1414abc') === 1414, 'CPA');
check(GE_Shipping_Calculator::postcode('1414') === 1414, 'numeric postcode');
check(GE_Shipping_Calculator::postcode('1414ABC') === 0, 'partial CPA');

$boxes = GE_Shipping_Calculator::paper_parcels(array(5, 9, 300, 1), array(0.3, 20, 0.03, 2, 100, 1000), 750);
check(count($boxes) === 2, 'spillover into second box');
check(abs(array_sum(array_column($boxes, 0)) - 1280) < 0.001, 'lamination and tare');
$rows = array(
    array('C', 1000, 1499, 1000, 60, 50, 40, 100),
    array('*', 1000, 9999, 5000, 60, 50, 40, 200),
);
$caba = array('country' => 'AR', 'state' => 'C', 'postcode' => 'C1414ABC');
$quote = GE_Shipping_Calculator::quote($boxes, $rows, $caba, 5000, 150);
check($quote['cost'] == 200, 'regional tier per parcel');
$volume = GE_Shipping_Calculator::quote(array(array(500, 30, 25, 20, 1)), $rows, $caba, 5000, 150);
check($volume['billable_g'] === 3000 && $volume['cost'] == 200.0, 'volumetric tier');
check(GE_Shipping_Calculator::quote($boxes, $rows, array_replace($caba, array('state' => 'B')), 5000, 150) === null, 'province mismatch');
check(GE_Shipping_Calculator::quote(array(array(500, 60, 50, 40, 1)), $rows, $caba, 0, 149) === null, 'maximum sum');
echo "OK\n";


define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function WC() {
    return (object) array('countries' => new class {
        public function get_states($country) { return array('C' => 'CABA', 'B' => 'Buenos Aires'); }
    });
}
class WC_Shipping_Rate {
    private $method;
    public function __construct($id, $label, $cost, $taxes, $method) { $this->method = $method; }
    public function get_method_id() { return $this->method; }
}
require __DIR__ . '/checkout.php';
$package = array('destination' => $caba, 'ge_delivery_intent' => 'delivery');
$pickup = new WC_Shipping_Rate('local_pickup:1', 'Retiro en tienda', 0, array(), 'local_pickup');
$rated = new WC_Shipping_Rate('ge_shipping:2', 'Entrega a domicilio', 200, array(), 'ge_shipping');
$available = array('local_pickup:1' => $pickup, 'ge_shipping:2' => $rated);
check(array_keys(GE_Shipping_Checkout::rates($available, $package)) === array('ge_shipping:2'), 'priced delivery');
check(array_keys(GE_Shipping_Checkout::rates(array('local_pickup:1' => $pickup), $package)) === array('ge_shipping_quote'), 'manual quote instead of pickup');
check(GE_Shipping_Checkout::rates($available, array_replace($package, array('ge_delivery_intent' => 'pickup'))) === array('local_pickup:1' => $pickup), 'pickup only');
$incorrect = array_replace($caba, array('state' => 'B'));
check(GE_Shipping_Checkout::rates(array(), array_replace($package, array('destination' => $incorrect))) === array(), 'invalid CPA cannot request quote');
echo "Checkout rate cases OK\n";
