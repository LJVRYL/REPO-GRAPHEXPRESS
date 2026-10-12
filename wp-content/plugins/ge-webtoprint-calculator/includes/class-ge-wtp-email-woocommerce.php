<?php
defined('ABSPATH') || exit;

/** Apply the shared identity without replacing WooCommerce's transactional content. */
final class GE_WTP_Email_WooCommerce {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        add_filter('woocommerce_locate_template', array(__CLASS__, 'locate_template'), 100, 3);
        foreach(array('new_order','cancelled_order','failed_order','customer_cancelled_order','customer_failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note','customer_reset_password','customer_new_account','customer_pos_completed_order','customer_pos_refunded_order') as $id) { add_filter('woocommerce_email_subject_'.$id,array(__CLASS__,'subject'),90,3); add_filter('woocommerce_email_heading_'.$id,array(__CLASS__,'heading'),90,3); }
        add_filter('woocommerce_email_styles', array(__CLASS__, 'styles'), 100, 2);
    }

    public static function locate_template($template, $template_name, $template_path = '') {
        if (!class_exists('GE_WTP_Email_Branding')) return $template;
        $files = array(
            'emails/email-header.php' => 'graphex-email-header.php',
            'emails/email-footer.php' => 'graphex-email-footer.php',
            'emails/customer-new-account.php'=>'graphex-new-account.php',
            'emails/customer-reset-password.php'=>'graphex-reset-password.php',
            'emails/plain/customer-new-account.php'=>'graphex-new-account-plain.php',
            'emails/plain/customer-reset-password.php'=>'graphex-reset-password-plain.php',
            'emails/customer-failed-order.php'=>'graphex-customer-failed-order.php',
            'emails/plain/customer-failed-order.php'=>'graphex-customer-failed-order-plain.php',
            'emails/customer-refunded-order.php'=>'graphex-customer-refunded-order.php',
            'emails/plain/customer-refunded-order.php'=>'graphex-customer-refunded-order-plain.php',
            'emails/customer-invoice.php'=>'graphex-customer-invoice.php',
            'emails/plain/customer-invoice.php'=>'graphex-customer-invoice-plain.php',
            'emails/customer-pos-completed-order.php'=>'graphex-customer-pos-completed-order.php',
            'emails/plain/customer-pos-completed-order.php'=>'graphex-customer-pos-completed-order-plain.php',
            'emails/customer-pos-refunded-order.php'=>'graphex-customer-pos-refunded-order.php',
            'emails/plain/customer-pos-refunded-order.php'=>'graphex-customer-pos-refunded-order-plain.php',
        );
        if (!isset($files[$template_name])) return $template;
        $replacement = dirname(__DIR__) . '/templates/emails/' . $files[$template_name];
        return is_readable($replacement) ? $replacement : $template;
    }

    public static function language($translated,$original,$domain='woocommerce') {
        if ($domain!=='woocommerce'||strpos(get_locale(),'es')!==0||$translated!==$original)return $translated;
        $copy=array(
            "Unfortunately, we couldn't complete your order due to an issue with your payment method." => "No pudimos completar tu pedido porque hubo un problema con el medio de pago.",
            "If you'd like to continue with your purchase, please return to %s and try a different method of payment." => "Para continuar con tu compra, volvé a %s e intentá con otro medio de pago.",
            "Your order details are as follows:" => "Este es el detalle de tu pedido:",
            "Your order from %s has been partially refunded." => "Se realizó un reintegro parcial de tu pedido de %s.",
            "Your order from %s has been refunded." => "Se realizó el reintegro de tu pedido de %s.",
            "Here’s a reminder of what you’ve ordered:" => "Este es el detalle de tu pedido:",
            "Here’s a reminder of what you’ve bought:" => "Este es el detalle de tu compra:",
            "Your order on %s has been partially refunded. There are more details below for your reference:" => "Se realizó un reintegro parcial de tu pedido de %s. Podés consultar el detalle a continuación:",
            "Your order on %s has been refunded. There are more details below for your reference:" => "Se realizó el reintegro de tu pedido de %s. Podés consultar el detalle a continuación:",
            "Thank you for your in-store purchase" => "Gracias por tu compra en el local",
            "Your in-store purchase #%1\$s at %2\$s" => "Tu compra #%1\$s en el local de %2\$s",
            "Your %1\$s order #%2\$s has been partially refunded" => "Reintegro parcial de tu pedido #%2\$s de %1\$s",
            "Your %1\$s order #%2\$s has been refunded" => "Reintegro de tu pedido #%2\$s de %1\$s",
            "Refund & Returns Policy" => "Política de reintegros y devoluciones",
        );
        return $copy[$original]??$translated;
    }

    public static function store_name($value,$show) {
        if ($show!=='name'||!class_exists('GE_Organization_Runtime'))return $value;
        $s=GE_Organization_Runtime::settings(); return ($s['general']['brand_name']??'') ?: (($s['general']['display_name']??'') ?: $value);
    }
    public static function subject($value,$object=null,$email=null) { return self::identity_slot($value,$email,'subject'); }
    public static function heading($value,$object=null,$email=null) { return self::identity_slot($value,$email,'heading'); }
    private static function identity_slot($value,$email,$slot) {
        $method='get_default_'.$slot; if (!$email || !method_exists($email,$method))return $value;
        $partial=!empty($email->partial_refund); $pos=in_array($email->id,array('customer_pos_completed_order','customer_pos_refunded_order'),true);
        if ($pos && $slot==='subject') {
            $key=$email->id==='customer_pos_refunded_order'?($partial?'subject_partial':'subject_full'):'subject';
            $stored=get_option('woocommerce_'.$email->id.'_settings',array());
            if (empty($stored[$key])) {
                $before=$email->id==='customer_pos_refunded_order'?$email->$method($partial):$email->$method();
                if ($value!==$email->format_string($before))return $value;
                $name=function(){return GE_WTP_Email_Branding::profile()['name'];};
                add_filter('pre_option_woocommerce_pos_store_name',$name,90);
                add_filter('gettext_woocommerce',array(__CLASS__,'language'),90,3);
                try { $after=$email->id==='customer_pos_refunded_order'?$email->$method($partial):$email->$method(); }
                finally { remove_filter('pre_option_woocommerce_pos_store_name',$name,90); remove_filter('gettext_woocommerce',array(__CLASS__,'language'),90); }
                return $email->format_string($after);
            }
        }
        $template=$email->get_option($slot,$email->$method()); if (!$template)$template=$email->$method();
        if (strpos($template,'{site_title}')===false || $value!==$email->format_string($template))return $value;
        // Rebuild only the generated site-title slot; custom legal/customer text and other hooks remain intact.
        $parts=explode('{site_title}',$template); foreach($parts as &$part)$part=$email->format_string($part); unset($part);
        return implode(GE_WTP_Email_Branding::profile()['name'],$parts);
    }

    public static function styles($css, $email = null) {
        if (!class_exists('GE_WTP_Email_Branding')) return $css;
        $profile = GE_WTP_Email_Branding::profile();
        $color = sanitize_hex_color(isset($profile['color']) ? $profile['color'] : '');
        if (!$color) $color = '#443acc';
        // WooCommerce still runs its native CSS inliner over the complete document.
        return $css . "\n"
            . '#body_content_inner{font-family:Arial,Helvetica,sans-serif;color:#33333b;font-size:15px;line-height:1.65;}'
            . '#body_content_inner a{color:' . $color . ';}'
            . '#body_content_inner h2,#body_content_inner h3{color:' . $color . ';font-family:Arial,Helvetica,sans-serif;}'
            . '#body_content_inner table.td{border:1px solid #dedde3;border-collapse:collapse;}'
            . '#body_content_inner th.td,#body_content_inner td.td{border-color:#dedde3;padding:12px;}'
            . '#body_content_inner th.td{background-color:#f9f8f5;color:#111116;}'
            . '#body_content_inner .address{border-color:#dedde3;padding:16px;}';
    }

    public static function supports($id) { return in_array($id,array('new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note','customer_reset_password','customer_new_account','customer_cancelled_order','customer_failed_order','customer_pos_completed_order','customer_pos_refunded_order'),true); }

    /** Render native Woo templates with unsaved fixtures, never trigger/send/save. */
    public static function preview($id) {
        $supported = array('new_order', 'cancelled_order', 'failed_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_invoice', 'customer_note', 'customer_reset_password', 'customer_new_account','customer_cancelled_order','customer_failed_order','customer_pos_completed_order','customer_pos_refunded_order');
        if (!in_array($id, $supported, true) || !function_exists('WC') || !class_exists('WC_Order')) {
            return new WP_Error('ge_wc_preview_unavailable', 'Vista previa nativa no disponible para este correo.');
        }
        $mailer = WC()->mailer();
        $source = null;
        foreach ($mailer->get_emails() as $candidate) {
            if ($candidate->id === $id) { $source = $candidate; break; }
        }
        if (!$source) return new WP_Error('ge_wc_preview_missing', 'WooCommerce no registró este correo.');
        $email = clone $source;
        $note = 'Ejemplo sin guardar: pedido #0, importes ilustrativos y enlaces sin validez operativa. No se envía ningún correo ni se generan credenciales. Los adjuntos y las extensiones pueden requerir datos reales.';
        $level=ob_get_level();
        global $wp_filter; $hook_state=array();
        foreach(array('woocommerce_email_header','woocommerce_email_footer','woocommerce_order_item_meta_start','woocommerce_get_order_item_totals','woocommerce_email_footer_text') as $hook) $hook_state[$hook]=isset($wp_filter[$hook])?clone $wp_filter[$hook]:null;
        try {
            if (in_array($id, array('customer_new_account', 'customer_reset_password'), true)) {
                $user = new WP_User();
                $user->data = (object) array('ID'=>0, 'user_login'=>'cliente-ejemplo-invalido', 'user_email'=>'cliente@example.invalid', 'display_name'=>'Cliente de ejemplo', 'user_activation_key'=>'');
                $email->object = $user;
                $email->user_login = 'cliente-ejemplo-invalido';
                $email->user_email = 'cliente@example.invalid';
                $email->recipient = 'cliente@example.invalid';
                if ($id === 'customer_new_account') {
                    $email->user_pass = '';
                    $email->password_generated = true;
                    $email->set_password_url = 'https://example.invalid/activar-cuenta';
                } else {
                    $email->user_id = 0;
                    $email->reset_key = 'EXAMPLE-INVALID-NOT-A-RESET-TOKEN';
                }
            } else {
                $order = new WC_Order();
                // Set the paid date before status setters or POS getters. Native
                // lazy date resolution calls needs_processing(), which caches a
                // transient and would write even for an unsaved order fixture.
                $order->set_date_paid('2026-01-15 12:00:00');
                $order->set_props(array('status'=>'pending', 'currency'=>'ARS', 'date_created'=>'2026-01-15 12:00:00', 'billing_first_name'=>'Cliente', 'billing_last_name'=>'de ejemplo', 'billing_email'=>'cliente@example.invalid', 'billing_address_1'=>'Dirección de ejemplo 123', 'billing_city'=>'Ciudad de ejemplo', 'billing_country'=>'AR', 'payment_method'=>'bacs', 'payment_method_title'=>'Transferencia bancaria', 'total'=>'25000'));
                $order->set_order_key('EXAMPLE-INVALID-NOT-AN-ORDER-TOKEN');
                $item = new WC_Order_Item_Product();
                $item->set_props(array('name'=>'Trabajo gráfico de ejemplo', 'quantity'=>1, 'subtotal'=>'25000', 'total'=>'25000'));
                $order->add_item($item);
                $email->object = $order;
                $email->recipient = 'cliente@example.invalid';
                $email->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());
                $email->placeholders['{order_number}'] = '0';
                if ($id === 'customer_note') $email->customer_note = 'Tu trabajo de ejemplo está listo para coordinar la entrega.';
                if (in_array($id,array('customer_refunded_order','customer_pos_refunded_order'),true)) {
                    if (!class_exists('WC_Order_Refund')) return new WP_Error('ge_wc_preview_refund', 'Vista previa de reintegro no disponible en esta instalación.');
                    $refund = new WC_Order_Refund();
                    $refund->set_amount('25000');
                    $refund->set_reason('Reintegro de ejemplo sin guardar');
                    $email->refund = $refund;
                    $email->partial_refund = false;
                    $order->set_status('refunded');
                    $note .= ' El reintegro de ejemplo no está vinculado a una operación real ni modifica el total del detalle del pedido.';
                }
            }
            ob_start(); $body = $email->get_content_html(); $printed=ob_get_clean();
            $body = is_string($body)&&$body!=='' ? $body : $printed;
            if (!$body) return new WP_Error('ge_wc_preview_empty','El constructor nativo no devolvió contenido.');
            $body = $email->style_inline($body);
            ob_start(); $plain = $email->get_content_plain(); $printed=ob_get_clean();
            $plain = is_string($plain)&&$plain!=='' ? $plain : $printed;
            return array('subject'=>$email->get_subject(), 'body'=>$body, 'plain'=>$plain, 'enabled'=>$source->is_enabled(), 'fixture'=>true, 'note'=>$note,
                'attachments'=>array(), 'attachments_note'=>'La vista no genera adjuntos; el envío conserva los de su sistema de origen.');
        } catch (Throwable $error) {
            while(ob_get_level()>$level)ob_end_clean();
            $diagnostic = defined('GE_EMAIL_IDENTITY_QA') && GE_EMAIL_IDENTITY_QA ? array('type'=>get_class($error),'guard'=>strpos($error->getMessage(),'QA attempted database mutation')===0 ? $error->getMessage() : '', 'file'=>basename($error->getFile()), 'line'=>$error->getLine()) : null;
            return new WP_Error('ge_wc_preview_render', 'La plantilla nativa requiere datos adicionales y no admite esta vista de ejemplo.', $diagnostic);
        } finally {
            foreach($hook_state as $hook=>$state) { if($state===null)unset($wp_filter[$hook]); else $wp_filter[$hook]=$state; }
            // POS renderers temporarily register instance callbacks; native cleanup
            // is skipped when a template throws. Remove only this preview clone.
            remove_action('woocommerce_order_item_meta_start', array($email, 'add_unit_price'), 10);
            remove_filter('woocommerce_get_order_item_totals', array($email, 'order_item_totals'), 10);
            remove_filter('woocommerce_email_footer_text', array($email, 'replace_footer_placeholders'), 1);
            remove_action('woocommerce_pos_email_header', array($email, 'email_header'), 10);
            remove_action('woocommerce_pos_email_footer', array($email, 'email_footer'), 10);
        }
    }
}
