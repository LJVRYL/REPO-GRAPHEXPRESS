<?php
defined('ABSPATH') || exit;
/** WooCommerce normally logs save exceptions and returns the ID; a receipt must fail closed. */
final class GE_Work_Finance_Order extends WC_Order {
    protected function handle_exception($error,$message='Error') { throw $error; }
}
