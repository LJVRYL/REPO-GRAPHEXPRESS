<?php
defined('ABSPATH') || exit;
final class GE_Deco_Marketplace_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name='ge_deco_marketplace';
    public function initialize() { $this->settings=array(); }
    public function is_active() { return GE_WTP_Deco_Marketplace_Checkout::enabled(); }
    public function get_payment_method_script_handles() {
        $path=GE_WTP_PLUGIN_DIR.'assets/deco/marketplace-checkout.js';
        wp_register_script('ge-deco-marketplace-checkout',plugins_url('assets/deco/marketplace-checkout.js',GE_WTP_PLUGIN_DIR.'ge-webtoprint-calculator.php'),array('wc-blocks-registry','wc-settings','wp-element'),is_file($path)?filemtime($path):'1',true);
        return array('ge-deco-marketplace-checkout');
    }
    public function get_payment_method_data() {
        return array('title'=>'Mercado Pago','description'=>'Pagá con saldo de Mercado Pago. La obra se acredita al artista y Graphex recibe el importe del cuadro.',
            'available'=>GE_WTP_Deco_Marketplace_Checkout::artist_checkout(),'supports'=>array('products'));
    }
}
