<?php
defined('ABSPATH') || exit;
if(!class_exists('WC_Payment_Gateway'))return;
final class GE_Deco_Marketplace_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id='ge_deco_marketplace';$this->method_title='Graphex · obras de artistas';
        $this->method_description='Checkout Pro con reparto a la cuenta conectada del artista.';
        $this->title='Mercado Pago';$this->description='Pagá con tu saldo de Mercado Pago. El importe de la obra se acredita en la cuenta del artista.';
        $this->has_fields=false;$this->enabled='yes';$this->supports=array('products');
    }
    public function is_available() {
        return GE_WTP_Deco_Marketplace_Checkout::enabled()&&is_user_logged_in()&&!GE_WTP_Portal::is_staff_preview()&&GE_WTP_Deco_Marketplace_Checkout::artist_checkout();
    }
    public function process_payment($order_id) {
        try{$order=wc_get_order($order_id);if(!$order||$order->get_payment_method()!==$this->id)throw new InvalidArgumentException('invalid_payment_method');
            $redirect=GE_WTP_Deco_Marketplace_Checkout::create($order);return array('result'=>'success','redirect'=>$redirect['init_point']);
        }catch(InvalidArgumentException $e){wc_add_notice('No pudimos iniciar el pago de esta obra. Actualizá la configuración o volvé a intentarlo. Si ya pagaste, esperá la confirmación antes de repetirlo.','error');return array('result'=>'failure');}
    }
}
