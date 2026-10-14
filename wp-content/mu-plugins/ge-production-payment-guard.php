<?php
defined('ABSPATH') || exit;

/** An exception permits a production commitment; it never records money received. */
final class GE_Production_Payment_Guard {
    const META = '_ge_production_payment_exception_v1';
    public static function init() {
        add_action('admin_post_ge_finance_production_payment_exception', array(__CLASS__, 'handle'));
    }
    public static function scope($order) {
        $items = array();
        foreach ($order->get_items('line_item') as $id => $item) {
            $finishes = $item->get_meta('_ge_item_finishes', true);
            $items[$id] = array('name'=>$item->get_name(), 'quantity'=>$item->get_quantity(),
                'total'=>(string)$item->get_total(), 'product_id'=>$item->get_product_id(),
                'finishes'=>is_array($finishes) ? $finishes : array(),
                'custom_finish'=>(string)$item->get_meta('_ge_item_custom_finish', true));
        }
        ksort($items);
        return hash('sha256', wp_json_encode(array(
            'order_id' => $order->get_id(), 'customer_id' => $order->get_customer_id(),
            'currency' => $order->get_currency(), 'total' => wc_format_decimal($order->get_total(), 2),
            'quote_id' => $order->get_meta('_ge_commercial_quote_id', true),
            'quote_version' => $order->get_meta('_ge_commercial_quote_version', true),
            'snapshot' => $order->get_meta('_ge_commercial_quote_snapshot', true),
            'terms' => $order->get_meta(GE_WTP_Payment_Policy::META, true), 'items'=>$items
        )));
    }
    public static function supervisor($actor) {
        return $actor && (class_exists('GE_Organization_Runtime')
            ? GE_Organization_Runtime::allowed('finance', true, $actor)
            : user_can($actor, 'manage_woocommerce'));
    }
    public static function valid_exception($order) {
        $e = $order->get_meta(self::META, true);
        return is_array($e) && empty($e['revoked_at']) && !empty($e['actor'])
            && !empty($e['at']) && strlen(trim($e['reason'] ?? '')) >= 10
            && ($e['expires'] ?? 0) > time() && ($e['expires'] ?? 0) <= strtotime($e['at']) + 30 * DAY_IN_SECONDS
            && !empty($e['scope']) && hash_equals(self::scope($order), $e['scope']);
    }
    public static function check($order) {
        if (!$order instanceof WC_Order || in_array($order->get_status(), array('cancelled','refunded','failed'), true))
            return new WP_Error('payment_order', 'El pedido no está activo.');
        $org = $order->get_meta('_ge_organization_id', true);
        if ($org && $org !== GE_Organization::PRIMARY) return new WP_Error('organization', 'Pedido de otra organización.');
        if ((float)$order->get_total() <= 0) return new WP_Error('payment_total', 'Revisá el importe del pedido antes de producir.');
        $paid = GE_WTP_Job_Flow::payment_check($order);
        if (!is_wp_error($paid) || self::valid_exception($order)) return true;
        return new WP_Error('production_payment', 'Para producir o enviar la orden al proveedor, acreditá la seña acordada o registrá una excepción de Administración. Podés seguir preparando los archivos.');
    }
    public static function save($order, $actor, $reason, $expires, $expected_scope, $revoke = false) {
        if (!$order instanceof WC_Order || !self::supervisor($actor)) return new WP_Error('forbidden', 'La excepción requiere Administración.');
        if ((int)$actor !== get_current_user_id()) return new WP_Error('forbidden', 'Actor inválido.');
        $org = $order->get_meta('_ge_organization_id', true);
        if (($org && $org !== GE_Organization::PRIMARY) || !GE_WTP_Staff_Portal::can_access()) return new WP_Error('forbidden', 'Pedido fuera de tu acceso.');
        if (!hash_equals(self::scope($order), (string)$expected_scope)) return new WP_Error('conflict', 'El importe o la versión cambió. Revisá el pedido antes de autorizar.');
        if (in_array($order->get_status(), array('cancelled','refunded','failed'), true) || GE_WTP_Production::is_closed($order)) return new WP_Error('state', 'Este pedido no admite una excepción.');
        if ((float)$order->get_total() <= 0) return new WP_Error('payment_total', 'La excepción requiere un importe positivo.');
        $reason = sanitize_textarea_field($reason);
        if (strlen(trim($reason)) < 10 || strlen($reason) > 2000) return new WP_Error('reason', 'Registrá un motivo de 10 a 2000 caracteres.');
        if (!$revoke && ($expires <= time() || $expires > time() + 30 * DAY_IN_SECONDS)) return new WP_Error('expiry', 'La excepción debe vencer dentro de los próximos 30 días.');
        $previous = $order->get_meta(self::META, true);
        $entry = array('actor'=>(int)$actor, 'at'=>gmdate('c'), 'reason'=>$reason, 'expires'=>(int)$expires, 'scope'=>self::scope($order), 'order_id'=>$order->get_id());
        if ($revoke) $entry['revoked_at'] = gmdate('c');
        $history = $order->get_meta('_ge_production_payment_exception_history_v1', true);
        $history = is_array($history) ? $history : array();
        $history[] = array('previous'=>$previous, 'entry'=>$entry);
        $order->update_meta_data('_ge_production_payment_exception_history_v1', $history);
        $order->update_meta_data(self::META, $entry);
        $order->add_order_note(($revoke ? 'Excepción de producción revocada' : 'Producción sin seña autorizada').' por #'.$actor.'. Motivo: '.$reason.'. No acredita pagos.', false);
        $order->save();
        return $entry;
    }
    public static function render($order) {
        $check = self::check($order);
        echo '<section class="ge-production-card"><h3>Autorización de pago para producir</h3><p role="status">'.esc_html(is_wp_error($check) ? $check->get_error_message() : (self::valid_exception($order) ? 'Excepción vigente de Administración. El pago conserva su estado real.' : 'Condición de pago cumplida.')).'</p>';
        if (self::valid_exception($order)) {
            $e = $order->get_meta(self::META, true);
            echo '<p>'.esc_html('Autorizó #'.$e['actor'].' · '.wp_date('d/m/Y H:i', strtotime($e['at'])).' · Vence '.wp_date('d/m/Y H:i', $e['expires'])).'</p><p>'.esc_html($e['reason']).'</p>';
        }
        if (self::supervisor(get_current_user_id())) {
            echo '<details><summary>Registrar o revocar una excepción</summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_finance_production_payment_exception"><input type="hidden" name="order_id" value="'.$order->get_id().'"><input type="hidden" name="scope" value="'.esc_attr(self::scope($order)).'">';
            wp_nonce_field('ge_production_payment_exception_'.$order->get_id());
            echo '<label>Motivo<textarea name="reason" required minlength="10" maxlength="2000"></textarea></label><label>Vigencia (días)<input type="number" name="days" min="1" max="30" value="7" required></label><label><input type="checkbox" name="confirm" value="1" required> Confirmo esta decisión para el importe y versión actuales</label><div class="ge-prod-actions"><button class="ge-staff-button" name="operation" value="grant">Autorizar excepción</button><button class="ge-staff-button is-secondary" name="operation" value="revoke">Revocar excepción</button></div></form></details>';
        }
        echo '</section>';
    }
    public static function handle() {
        $id = absint($_POST['order_id'] ?? 0);
        check_admin_referer('ge_production_payment_exception_'.$id);
        $order = wc_get_order($id);
        $operation = sanitize_key($_POST['operation'] ?? '');
        if (empty($_POST['confirm']) || !in_array($operation, array('grant','revoke'), true)) wp_die('Confirmación requerida.', 400);
        $result = GE_WTP_Production_Cards::locked($id, function() use ($id, $operation) {
            $order = wc_get_order($id);
            return self::save($order, get_current_user_id(), wp_unslash($_POST['reason'] ?? ''), time() + absint($_POST['days'] ?? 0) * DAY_IN_SECONDS, sanitize_text_field($_POST['scope'] ?? ''), $operation === 'revoke');
        });
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), 409);
        $url = GE_Organization_Runtime::allowed('production') ? GE_WTP_Production_Cards::url($order, 'control') : GE_WTP_Staff_Portal::portal_url('orders', array('order_id'=>$id));
        wp_safe_redirect($url); exit;
    }
}
add_action('plugins_loaded', array('GE_Production_Payment_Guard', 'init'), 41);
