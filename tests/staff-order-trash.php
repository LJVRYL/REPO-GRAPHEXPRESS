<?php
define( 'ABSPATH', __DIR__ );
class WC_Order {
    public $status = 'pending';
    public $paid = false;
    public $refunded = 0;
    public $transaction = '';
    public $meta = array();
    public function get_status() { return $this->status; }
    public function get_date_paid() { return $this->paid; }
    public function get_total_refunded() { return $this->refunded; }
    public function get_transaction_id() { return $this->transaction; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? null; }
}
class GE_WTP_Customer_Quotes { public static function is_quote_order( $order ) { return ! empty( $order->meta['quote'] ); } }
class GE_WTP_Order_Lifecycle { public static function stage( $order ) { return $order->meta['lifecycle'] ?? 'recibido'; } }
class GE_WTP_Production { public static function is_closed( $order ) { return ! empty( $order->meta['closed'] ); } }
require_once __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-staff-portal.php';

function check_trash( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } }
$order = new WC_Order();
check_trash( GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido pendiente sin actividad debería poder ir a papelera.' );
$order->paid = true;
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido pagado debe estar protegido.' );
$order->meta['closed'] = true;
check_trash( GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un duplicado cerrado con fecha automática de pago debe poder ir a papelera.' );
$order->meta = array();
$order->paid = false;
$order->transaction = 'paid-123';
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido con transacción debe estar protegido.' );
$order->transaction = '';
$order->meta['_ge_payment_state'] = 'receipt_uploaded';
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido con comprobante debe estar protegido.' );
$order->meta = array();
$order->meta['_ge_workflow_supplier_history'] = array( array( 'sent' => true ) );
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido enviado a proveedor debe estar protegido.' );
$order->meta['closed'] = true;
check_trash( GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido cerrado sin pago debe poder pasar a papelera aunque tenga un envío histórico.' );
$order->meta = array( 'lifecycle' => 'entregado' );
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido entregado debe estar protegido.' );
$order->meta = array();
$order->status = 'trash';
check_trash( ! GE_WTP_Staff_Portal::can_trash_order( $order ), 'Un pedido en papelera no se debe procesar otra vez.' );
echo "staff-order-trash: OK\n";
