<?php
require_once __DIR__ . '/fixtures/wordpress-defaults.php';

define( 'ABSPATH', __DIR__ );
function absint( $value ) { return abs( (int) $value ); }
function get_post_meta( $id, $key, $single ) { global $quote_files; return $key === GE_WTP_Commercial_Quote_Files::META ? $quote_files : array(); }

class WC_Order_Item_Product {
    public $meta = array( '_ge_item_artwork_customer_approval' => 1, '_ge_item_artwork_staff_approval' => 1, '_ge_item_artwork_release_hash' => 'old' );
    public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
    public function get_id() { return 701; }
    public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
    public function save() {}
}
class WC_Order {
    public $meta = array();
    public $notes = array();
    public $item;
    public function __construct() { $this->item = new WC_Order_Item_Product(); }
    public function get_id() { return 501; }
    public function get_items( $type ) { return array( $this->item ); }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function add_order_note( $note ) { $this->notes[] = $note; }
    public function save() {}
}
class GE_WTP_Documents {
    const META_KEY = '_ge_markcom_documents';
    public static $order;
    public static function get_documents( $id ) { return self::$order->meta[ self::META_KEY ] ?? array(); }
}

class GE_WTP_Commercial_Quotes { public static function get($id) { return array('id'=>$id,'version'=>1,'snapshot'=>array('items'=>array())); } }
require __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-external-artwork.php';
require __DIR__ . '/../wp-content/plugins/ge-webtoprint-calculator/includes/class-ge-wtp-commercial-quote-files.php';

$quote_files = array( array( 'id' => 'file-a', 'stored_name' => 'private-a.pdf', 'name' => 'Original.pdf', 'category' => 'arte' ) );
$order = new WC_Order();
GE_WTP_Documents::$order = $order;
GE_WTP_Commercial_Quote_Files::inherit( 99, $order );
if ( count( $order->meta[ GE_WTP_Documents::META_KEY ] ) !== 1 || $order->meta[ GE_WTP_Documents::META_KEY ][0]['stored_name'] !== 'private-a.pdf' ) {
    throw new RuntimeException( 'El pedido no heredó la referencia privada exacta.' );
}
if ( isset( $order->item->meta['_ge_item_artwork_release_hash'] ) ) { throw new RuntimeException( 'La aprobación anterior sobrevivió a un archivo nuevo.' ); }
GE_WTP_Commercial_Quote_Files::inherit( 99, $order );
if ( count( $order->meta[ GE_WTP_Documents::META_KEY ] ) !== 1 || count( $order->notes ) !== 1 ) { throw new RuntimeException( 'La herencia duplicó el archivo o la nota.' ); }
$quote_files[] = array( 'id' => 'file-b', 'stored_name' => 'private-b.pdf', 'name' => 'Nueva versión.pdf', 'category' => 'arte' );
$order->item->meta['_ge_item_artwork_release_hash'] = 'old';
GE_WTP_Commercial_Quote_Files::inherit( 99, $order );
if ( count( $order->meta[ GE_WTP_Documents::META_KEY ] ) !== 2 || isset( $order->item->meta['_ge_item_artwork_release_hash'] ) ) {
    throw new RuntimeException( 'Una nueva versión no heredó o no invalidó la aprobación.' );
}
echo "commercial-quote-file-inheritance: OK\n";
