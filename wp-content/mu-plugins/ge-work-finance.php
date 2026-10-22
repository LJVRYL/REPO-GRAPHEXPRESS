<?php
/** Audited manual receipts reuse the credited-attempt ledger and its native lock. */
defined('ABSPATH') || exit;
final class GE_Work_Finance {
    const META='_ge_work_manual_payments';
    const HISTORICAL_META='_ge_work_historical_payments';
    public static function dependent($order){return (int)$order->get_meta('_ge_financial_parent_order_id',true)>0 || ($order->get_meta('_ge_work_order',true)==='yes' && (int)$order->get_meta('_ge_source_quote_order_id',true)>0);}
    public static function financial_parent($child){
        if(!self::dependent($child))return null;$id=absint($child->get_meta('_ge_financial_parent_order_id',true)?:$child->get_meta('_ge_source_quote_order_id',true));$parent=wc_get_order($id);$item=$parent?$parent->get_item(absint($child->get_meta('_ge_source_quote_item_id',true))):false;
        if(!$parent || !$item || self::dependent($parent) || $child->get_meta('_ge_work_order',true)!=='yes' || (int)$child->get_meta('_ge_source_quote_order_id',true)!==$id || (int)$item->get_meta('_ge_work_order_id',true)!==$child->get_id() || $parent->get_customer_id()!==$child->get_customer_id() || $parent->get_currency()!==$child->get_currency())return new WP_Error('source','Revisá el vínculo con el pedido comercial antes de registrar dinero.');return $parent;
    }
    public static function historical($order){return $order->get_meta(self::HISTORICAL_META,true) || $order->get_meta('_ge_historical_execution_confirmed',true) || in_array($order->get_created_via(),array('ge_historical_invoice','ge_historical_performed_scope'),true) || $order->get_meta('_ge_commercial_conversion_source',true)==='historical';}
    public static function money($cents,$currency='ARS') {return $currency==='ARS'?GE_WTP_Commercial_Quote_UI::money($cents):$currency.' '.number_format($cents/100,$cents%100?2:0,',','.');}
    public static function can_write($actor) { return GE_WTP_Staff_Portal::can_access() && $actor===get_current_user_id() && GE_WTP_Customer_Invoices::staff($actor,true); }
    public static function strict_order($id) { require_once __DIR__.'/ge-work-finance/order.php';return new GE_Work_Finance_Order($id); }
    public static function clear_item_cache($ids){
        foreach(array_unique(array_map('absint',$ids)) as $id){
            wp_cache_delete('item-'.$id,'order-items');wp_cache_delete($id,'order_item_meta');
            if(class_exists('WC_Data'))wp_cache_delete(WC_Data::generate_meta_cache_key($id,'order-items'),'order-items');
        }
    }
    public static function clear_order_cache($id) {
        clean_post_cache($id);wp_cache_delete('order-items-'.$id,'orders');
        if(class_exists('Automattic\WooCommerce\Caches\OrderCache')) {
            $cache=wc_get_container()->get('Automattic\WooCommerce\Caches\OrderCache');
            if(is_callable(array($cache,'remove')))$cache->remove($id);
        }
        if(class_exists('WC_Cache_Helper'))WC_Cache_Helper::invalidate_cache_group('orders');
    }
    public static function order($id) {
        $order=wc_get_order(absint($id));
        if (!$order || 'yes'===$order->get_meta('_ge_commercial_payment_order',true)) return new WP_Error('order','Seleccioná el pedido comercial, no un intento de cobro.');
        $org=$order->get_meta('_ge_organization_id',true);
        if ($org && $org!==GE_Organization::PRIMARY) return new WP_Error('scope','Pedido de otra organización.');
        $customer_org=get_user_meta($order->get_customer_id(),'_ge_organization_id',true);
        if($customer_org && $customer_org!==GE_Organization::PRIMARY)return new WP_Error('scope','Cliente de otra organización.');
        if(self::dependent($order)){$parent=self::financial_parent($order);if(is_wp_error($parent))return $parent;return new WP_Error('source','Registrá el pago en el pedido comercial de origen #'.$parent->get_id().'.');}
        return $order;
    }
    public static function balance($order) {
        try {
            $total=GE_WTP_Quote_Balance::cents(wc_format_decimal($order->get_total(),2));
            if ($total<=0) throw new DomainException('Revisá el total del pedido antes de registrar cobros.');
            $attempts=$order->get_meta('_ge_commercial_credited_attempts',true);$attempts=is_array($attempts)?$attempts:array();
            $ledger=$order->get_meta(self::META,true);$ledger=is_array($ledger)?$ledger:array();
            $historical=$order->get_meta(self::HISTORICAL_META,true);$historical=is_array($historical)?$historical:array();
            $tracked=(bool)$order->get_meta('_ge_commercial_quote_id',true) || !empty($ledger) || !empty($historical);
            if ($tracked) {
                if ((int)$order->get_meta('_ge_final_total_cents',true)!==$total) throw new DomainException('El total cambió. Revisá la conciliación antes de registrar un pago.');
                $state=GE_WTP_Quote_Balance::reconcile($total,$attempts);
                if ($state['amount_paid_cents']!==(int)$order->get_meta('_ge_amount_paid_cents',true) || $state['amount_due_cents']!==(int)$order->get_meta('_ge_amount_due_cents',true)) throw new DomainException('Los cobros y el saldo guardado no coinciden. Revisá la conciliación.');
                foreach ($ledger as $entry) {
                    $found=false;foreach($attempts as $attempt) if (($attempt['key']??'')==='manual:'.$entry['id'] && ($attempt['amount_cents']??null)===$entry['amount_cents']) $found=true;
                    if (!$found) throw new DomainException('Un cobro manual no coincide con el registro acreditado.');
                }
                foreach($historical as $entry) {
                    if(empty($entry['actor']) || empty($entry['confirmed_at']) || empty($entry['declarant']) || empty($entry['source_revision']) || empty($entry['source_key']) || !array_key_exists('received_date',$entry) || $entry['received_date']!==null || !array_key_exists('method',$entry) || $entry['method']!==null || !isset($entry['amount_cents'],$entry['balance_confirmed_cents']) || !is_int($entry['amount_cents']) || !is_int($entry['balance_confirmed_cents']) || $entry['amount_cents']<0 || $entry['balance_confirmed_cents']<$entry['amount_cents'] || $entry['balance_confirmed_cents']>$total)throw new DomainException('Una confirmación histórica requiere revisión.');
                    if(!$entry['amount_cents'])continue;
                    $found=false;foreach($attempts as $attempt)if(($attempt['key']??'')==='historical:'.$entry['id'] && ($attempt['amount_cents']??null)===$entry['amount_cents'])$found=true;
                    if(!$found)throw new DomainException('La confirmación histórica no coincide con el saldo acreditado.');
                }
            } else {
                $confirmed=false;
                if ($order->get_meta('_ge_payment_confirmed_by',true) && $order->get_meta('_ge_payment_confirmed_at',true) && 'paid'===$order->get_meta('_ge_payment_state',true)) $confirmed=GE_WTP_Quote_Balance::cents((string)$order->get_meta('_ge_payment_confirmed_total',true))===$total;
                if ($order->is_paid() && $order->get_transaction_id() && $order->get_date_paid()) $confirmed=true;
                if (!$confirmed && ('paid'===$order->get_meta('_ge_payment_state',true) || $order->is_paid() || $attempts)) throw new DomainException('Hay un registro anterior de pago que requiere conciliación; no lo dupliques.');
                $attempts=$confirmed?array(array('key'=>'legacy:paid:order:'.$order->get_id(),'amount_cents'=>$total)):array();
                $state=GE_WTP_Quote_Balance::reconcile($total,$attempts);
            }
            return array_merge($state,array('total_cents'=>$total,'attempts'=>$attempts,'ledger'=>$ledger,'historical'=>$historical));
        } catch (Throwable $error) { return new WP_Error('balance',$error->getMessage()); }
    }
    public static function scope($order,$balance) { return hash('sha256',wp_json_encode(array($order->get_id(),$order->get_customer_id(),$order->get_currency(),$order->get_status(),$order->get_meta('_ge_commercial_quote_id',true),$order->get_meta('_ge_commercial_quote_version',true),$balance['total_cents'],$balance['attempts'],$balance['ledger']))); }
    public static function collection_lock($order){$q=absint($order->get_meta('_ge_commercial_quote_id',true));return 'ge_commercial_reconcile_'.($q?:'order_'.$order->get_id());}
    public static function lock($name) { global $wpdb;$key='ge:'.substr(hash('sha256',$wpdb->prefix.$name),0,55);return '1'===(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$key)); }
    public static function unlock($name) { global $wpdb;$key='ge:'.substr(hash('sha256',$wpdb->prefix.$name),0,55);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    public static function pending_attempt($order) {
        $quote=absint($order->get_meta('_ge_commercial_quote_id',true));
        $ids=array(absint($order->get_meta('_ge_commercial_balance_payment_order',true)),$quote?absint(get_post_meta($quote,'_ge_commercial_initial_payment_order',true)):0);
        foreach(array_filter($ids) as $id){$p=wc_get_order($id);if($p && !in_array($p->get_status(),array('cancelled','failed','refunded'),true) && (!$p->is_paid() || 'yes'!==$p->get_meta('_ge_commercial_reconciled',true))) return $id;}
        return 0;
    }
    public static function register($id,$input,$actor) {
        if (!self::can_write($actor)) return new WP_Error('forbidden','Sólo Administración/Finanzas puede registrar un cobro acreditado.');
        $order=self::order($id);if(is_wp_error($order))return $order;
        $quote=absint($order->get_meta('_ge_commercial_quote_id',true));$lock=self::collection_lock($order);
        if(!self::lock($lock))return new WP_Error('busy','El cobro se está procesando. No repitas el pago.');
        global $wpdb;$transaction=false;
        try {
            self::clear_order_cache($id);
            $order=self::order($id);if(is_wp_error($order))return $order;
            $order=self::strict_order($id);
            if(in_array($order->get_status(),array('cancelled','failed','refunded'),true))return new WP_Error('inactive','El pedido no está activo.');
            $balance=self::balance($order);if(is_wp_error($balance))return $balance;
            $key=sanitize_text_field($input['request_id']??'');$kind=sanitize_key($input['kind']??'');$method=sanitize_key($input['method']??'');$date=sanitize_text_field($input['received_date']??'');$reference=sanitize_text_field($input['reference']??'');$note=sanitize_textarea_field($input['note']??'');
            $parts=explode('-',$date);
            if(!preg_match('/^[a-f0-9-]{36}$/D',$key) || !in_array($kind,array('partial','full'),true) || !in_array($method,array('cash','transfer','other'),true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)($parts[1]??0),(int)($parts[2]??0),(int)($parts[0]??0)) || $date>wp_date('Y-m-d') || empty($input['verified']) || strlen($reference)>160 || strlen($note)>1000 || ($method!=='cash' && strlen($reference)<4)) return new WP_Error('input','Completá fecha, medio y referencia, y confirmá la recepción del dinero.');
            $amount=$kind==='partial'?GE_WTP_Quote_Balance::cents(str_replace(',','.',trim((string)($input['amount']??'')))):null;
            $payload=hash('sha256',wp_json_encode(array($kind,$amount,$method,$date,$reference,$note)));
            foreach($balance['ledger'] as $old)if($old['id']===$key)return hash_equals($old['payload'],$payload)?array('saved'=>true,'duplicate'=>true,'entry'=>$old):new WP_Error('conflict','La misma solicitud ya se registró con otros datos.');
            if($reference!=='')foreach($balance['ledger'] as $old)if($old['method']===$method && mb_strtolower(trim($old['reference']))===mb_strtolower(trim($reference)))return new WP_Error('reference','Esa operación ya está registrada en este pedido. Revisá el historial; no la acredites dos veces.');
            if(!hash_equals(self::scope($order,$balance),(string)($input['scope']??'')))return new WP_Error('stale','El pedido o sus pagos cambiaron. Recargá la ficha antes de registrar.');
            if(self::pending_attempt($order))return new WP_Error('attempt','Hay un intento de pago del portal pendiente. Confirmalo o revisalo antes de registrar otro cobro.');
            if($quote){
                $q=GE_WTP_Commercial_Quotes::get($quote);
                if(is_wp_error($q) || $q['status']!=='converted' || (int)$q['customer_id']!==$order->get_customer_id() || (int)$q['converted_order_id']!==$order->get_id() || (int)$q['version']!==(int)$order->get_meta('_ge_commercial_quote_version',true))return new WP_Error('link','Revisá el vínculo y la versión del presupuesto de origen.');
                foreach(array(get_post_meta($quote,'_ge_organization_id',true),$q['snapshot']['organization_snapshot']['organization_id']??$q['snapshot']['organization_snapshot']['id']??'') as $organization)if($organization && $organization!==GE_Organization::PRIMARY)return new WP_Error('scope','Presupuesto de otra organización.');
            }
            if($kind==='full')$amount=$balance['amount_due_cents'];
            if($amount<=0 || $amount>$balance['amount_due_cents'])return new WP_Error('amount','El importe debe ser mayor que cero y no superar el saldo pendiente.');
            $entry=array('id'=>$key,'payload'=>$payload,'amount_cents'=>$amount,'currency'=>$order->get_currency(),'received_date'=>$date,'method'=>$method,'reference'=>$reference,'note'=>$note,'actor'=>$actor,'at'=>current_time('mysql',true));
            $attempts=$balance['attempts'];$attempts[]=array('key'=>'manual:'.$key,'amount_cents'=>$amount);$state=GE_WTP_Quote_Balance::reconcile($balance['total_cents'],$attempts);$ledger=$balance['ledger'];$ledger[]=$entry;
            $wpdb->query('START TRANSACTION');$transaction=true;
            $order->update_meta_data(self::META,$ledger);$order->update_meta_data('_ge_commercial_credited_attempts',$attempts);$order->update_meta_data('_ge_final_total_cents',$balance['total_cents']);
            $order->update_meta_data('_ge_amount_paid_cents',$state['amount_paid_cents']);$order->update_meta_data('_ge_amount_due_cents',$state['amount_due_cents']);$order->update_meta_data('_ge_payment_state',$state['payment_status']);
            if(!$state['amount_due_cents'])$order->set_date_paid((new DateTimeImmutable($date.' 00:00:00',wp_timezone()))->getTimestamp());
            $order->save();$order->add_order_note('Cobro manual acreditado por usuario #'.$actor.': '.GE_WTP_Quote_Balance::decimal($amount).' '.$order->get_currency().' · '.$method.' · '.$reference.' · recibido '.$date.'. Saldo: '.GE_WTP_Quote_Balance::decimal($state['amount_due_cents']).'.');
            if($wpdb->last_error)throw new RuntimeException('No se confirmó el registro. Recargá la ficha y revisá el historial antes de repetir.');
            if(false===$wpdb->query('COMMIT'))throw new RuntimeException('El resultado del registro es incierto. Revisá el historial antes de repetir.');$transaction=false;
            return array('saved'=>true,'duplicate'=>false,'entry'=>$entry,'balance'=>$state);
        } catch(Throwable $error) { if($transaction){$wpdb->query('ROLLBACK');self::clear_order_cache($id);}return new WP_Error('payment',($error instanceof InvalidArgumentException || $error instanceof DomainException)?$error->getMessage():'No se confirmó el registro. Recargá la ficha y revisá el historial antes de repetir.'); }
        finally {self::unlock($lock);}
    }
    public static function handle() {
        $id=absint($_POST['order_id']??0);check_admin_referer('ge_work_payment_'.$id);$result=self::register($id,wp_unslash($_POST),get_current_user_id());
        if(is_wp_error($result))set_transient('ge_work_finance_error_'.get_current_user_id().'_'.$id,$result->get_error_message(),120);
        wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$id,'work_payment'=>is_wp_error($result)?'error':'saved')).'#ge-work-finance');exit;
    }
}
add_action('admin_post_ge_work_manual_payment',array('GE_Work_Finance','handle'));
add_filter('woocommerce_order_needs_payment',function($needs,$order){if($order->get_meta(GE_Work_Finance::META,true))return false;if(GE_Work_Finance::historical($order)||GE_Work_Finance::dependent($order))return false;return $needs;},99,2);
require_once __DIR__.'/ge-work-finance/ui.php';
require_once __DIR__.'/ge-work-finance/invoices.php';
require_once __DIR__.'/ge-work-finance/history.php';
