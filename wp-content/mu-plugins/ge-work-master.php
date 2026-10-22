<?php
/** One read-only work context over the existing commercial and production controllers. */
defined('ABSPATH') || exit;
final class GE_Work_Master {
    private static $active = false;
    public static function active() { return self::$active; }
    private static function source($child) {
        if (!$child || 'yes' !== $child->get_meta('_ge_work_order',true)) return false;
        $source=wc_get_order(absint($child->get_meta('_ge_source_quote_order_id',true)));
        $item=$source ? $source->get_item(absint($child->get_meta('_ge_source_quote_item_id',true))) : false;
        if (!$source || !$item || (int)$item->get_meta('_ge_work_order_id',true)!==$child->get_id() || $source->get_customer_id()!==$child->get_customer_id() || $source->get_currency()!==$child->get_currency()) return false;
        foreach(array($source,$child) as $order) { $org=$order->get_meta('_ge_organization_id',true); if ($org && class_exists('GE_Organization') && $org!==GE_Organization::PRIMARY) return false; }
        return $source;
    }
    public static function context($section,$id) {
        $context=GE_WTP_Job_Flow::context($section,$id,get_current_user_id());
        if ($section==='quotes' && !$id && GE_WTP_Work_Panel::allowed('quotes')) {
            $legacy=GE_WTP_Commercial_Quote_UI::legacy_record(absint($_GET['legacy_user'] ?? 0),sanitize_key(wp_unslash($_GET['legacy_key'] ?? '')));
            if (!$legacy) return null;
            $mapped=GE_WTP_Commercial_Quote_UI::legacy_mapping($legacy['user_id'],$legacy['key']);
            if ($mapped) $context=GE_WTP_Job_Flow::context('quotes',$mapped['id'],get_current_user_id());
            else {
                $order=wc_get_order(absint($legacy['order_id'] ?? 0));
                if ($order && ((int)$order->get_customer_id()!==(int)$legacy['user_id'] || $order->get_meta(GE_WTP_Customer_Quotes::KEY_META,true)!==$legacy['key'])) $order=false;
                $context=array('request'=>null,'quote'=>null,'order'=>$order ?: null,'legacy'=>$legacy);
            }
        }
        if (!$context) return null;
        $context['commercial_order']=$context['order'];
        if (GE_WTP_Work_Panel::allowed('orders')) {
            $child=!empty($_GET['work_order_id']) ? wc_get_order(absint($_GET['work_order_id'])) : $context['order'];
            $source=self::source($child);
            if ($source && ($child===$context['order'] || ($context['order'] && $source->get_id()===$context['order']->get_id()))) {
                $context['order']=$child; $context['commercial_order']=$source;
                $commercial=GE_WTP_Job_Flow::context('orders',$source->get_id(),get_current_user_id());
                if ($commercial) { $context['quote']=$commercial['quote']; $context['request']=$commercial['request']; }
            }
        }
        if (empty($context['quote']) && empty($context['legacy']) && $context['commercial_order'] && GE_WTP_Work_Panel::allowed('quotes')) {
            $order=$context['commercial_order'];$key=$order->get_meta(GE_WTP_Customer_Quotes::KEY_META,true);
            if ($key) { $legacy=GE_WTP_Commercial_Quote_UI::legacy_record($order->get_customer_id(),$key); if ($legacy && (int)($legacy['order_id'] ?? 0)===$order->get_id()) $context['legacy']=$legacy; }
        }
        return $context;
    }
    public static function assets() {
        if (!is_page('gestion') || !GE_WTP_Staff_Portal::can_access()) return;
        $section=sanitize_key($_GET['section'] ?? '');
        if (!in_array($section,array('requests','quotes','orders','production'),true)) return;
        foreach (array('production','commercial-quotes') as $asset) wp_enqueue_style('ge-master-native-'.$asset, GE_WTP_PLUGIN_URL.'assets/css/'.$asset.'.css', array('ge-staff-portal'), filemtime(GE_WTP_PLUGIN_DIR.'assets/css/'.$asset.'.css'));
        wp_enqueue_style('ge-work-master', plugins_url('ge-work-master/master.css', __FILE__), array('ge-master-native-commercial-quotes'), filemtime(__DIR__.'/ge-work-master/master.css'));
        wp_enqueue_script('ge-work-master', plugins_url('ge-work-master/master.js', __FILE__), array(), filemtime(__DIR__.'/ge-work-master/master.js'), true);
    }
    public static function begin($section) {
        if (!in_array($section, array('requests','quotes','orders','production'), true)) return false;
        foreach (array('new','edit') as $key) if (!empty($_GET[$key])) return false;
        $key = $section === 'requests' ? 'request_id' : ($section === 'quotes' ? 'quote_id' : 'order_id');
        $id = absint($_GET[$key] ?? 0);
        if ((!$id && !($section==='quotes' && !empty($_GET['legacy_user']) && !empty($_GET['legacy_key']))) || !GE_WTP_Work_Panel::allowed($section)) return false;
        $context = self::context($section, $id);
        if (!$context) return false;
        self::$active = true;
        $request = $context['request']; $quote = $context['quote']; $order = $context['order'];
        $stage = $section === 'quotes' ? 'budget' : ($section === 'requests' ? 'request' : ($section === 'production' ? 'production' : 'order'));
        if ($section === 'orders' && ($_GET['work_stage'] ?? '') === 'delivery') $stage = 'delivery';
        if ($section === 'orders' && ($_GET['work_stage'] ?? '') === 'budget') $stage = 'budget';
        echo '<div class="ge-work-master" data-ge-work-master><a class="ge-work-back" href="'.esc_url(GE_WTP_Staff_Portal::portal_url('jobs')).'">← Todos los trabajos</a>';
        echo '<nav class="ge-work-stages" aria-label="Etapas del trabajo">';
        $stages = array('request'=>array('Solicitud','requests',$request ? $request['id'] : 0,'request_id'), 'budget'=>array('Presupuesto','quotes',$quote ? $quote['id'] : 0,'quote_id'), 'order'=>array('Pedido','orders',$order ? $order->get_id() : 0,'order_id'), 'production'=>array('Producción','production',$order ? $order->get_id() : 0,'order_id'), 'delivery'=>array('Entrega','orders',$order ? $order->get_id() : 0,'order_id'));
        if (!empty($context['legacy'])) $stages['budget']=array('Presupuesto','quotes',$context['legacy']['user_id'],'legacy_user');
        $commercial=$context['commercial_order'];
        if ($commercial && $order && $commercial->get_id()!==$order->get_id()) {
            $stages['order'][2]=$commercial->get_id();
            if (!$quote && empty($context['legacy'])) $stages['budget']=array('Presupuesto','orders',$commercial->get_id(),'order_id');
        }
        $index = 0;
        foreach ($stages as $name=>$entry) {
            ++$index;
            if ($entry[2] && GE_WTP_Work_Panel::allowed($entry[1])) {
                $args = array($entry[3]=>$entry[2]); if ($name === 'delivery') $args['work_stage'] = 'delivery';
                if ($name==='budget' && !empty($context['legacy'])) $args['legacy_key']=$context['legacy']['key'];
                if ($commercial && $order && $commercial->get_id()!==$order->get_id()) $args['work_order_id']=$order->get_id();
                if ($name==='budget' && !$quote) $args['work_stage']='budget';
                echo '<a data-ge-work-nav href="'.esc_url(GE_WTP_Staff_Portal::portal_url($entry[1],$args)).'"'.($name===$stage?' aria-current="page"':'').'><small>'.$index.'</small>'.esc_html($entry[0]).'</a>';
            } else echo '<span aria-disabled="true"><small>'.$index.'</small>'.esc_html($entry[0]).'</span>';
        }
        echo '</nav>';
        if ($section !== 'quotes') self::summary($context);
        echo '<div class="ge-work-content" data-work-stage="'.esc_attr($stage).'">';
        if ($stage === 'delivery') echo '<section class="ge-production-card"><h2>Entrega</h2><p>Revisá el destino, los bultos, la documentación y el estado del pedido. La entrega conserva las validaciones de producción y saldo acreditado.</p></section>';
        return true;
    }
    private static function summary($context) {
        $order=$context['order']; $quote=$context['quote']; $request=$context['request'];
        $customer_id=$order ? $order->get_customer_id() : ($quote ? $quote['customer_id'] : ($request['customer_id'] ?? 0));
        $customer=get_userdata($customer_id);
        $reference=$order ? GE_WTP_Manual_Orders::reference($order) : ($quote ? 'Presupuesto '.$quote['number'] : 'Solicitud #'.$request['id']);
        if ($quote && GE_WTP_Work_Panel::allowed('quotes')) GE_WTP_Commercial_Quote_UI::render_master_summary($quote);
        echo '<section class="ge-production-card ge-quote-view ge-work-summary"><header class="ge-quote-view-head"><div><span class="ge-quote-kicker">'.esc_html($quote ? 'Pedido y producción' : $reference).'</span><h2>';
        if ($quote) echo esc_html($reference);
        elseif ($customer) echo '<a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('customers',array('customer_id'=>$customer_id))).'">'.esc_html($customer->display_name).'</a>';
        else echo esc_html($order ? ($order->get_formatted_billing_full_name() ?: $order->get_billing_company() ?: $order->get_meta('_ge_manual_contact_name',true) ?: 'Cliente') : 'Cliente');
        $status=$order ? (GE_WTP_Production::is_closed($order) ? 'Cerrado' : GE_WTP_Order_Lifecycle::label($order)) : ($quote ? $quote['status'] : $request['status']);
        echo '</h2><small>'.esc_html($customer ? $customer->user_email : ($order ? $order->get_billing_email() : '')).'</small></div><div><strong>'.esc_html($status).'</strong></div></header>';
        if ($order) {
            echo '<div class="ge-work-products"><h3>Productos del trabajo</h3><ul>';
            foreach ($order->get_items('line_item') as $item) echo '<li><div><strong>'.esc_html($item->get_name()).'</strong>'.($item->get_meta('Especificaciones',true) ? '<small>'.esc_html($item->get_meta('Especificaciones',true)).'</small>' : '').'</div><span>'.esc_html($item->get_quantity()).' unidades</span></li>';
            echo '</ul></div>';
            if (GE_WTP_Work_Panel::allowed('orders')) echo '<a class="ge-staff-button is-secondary" data-ge-work-nav href="'.esc_url(GE_WTP_Staff_Portal::portal_url('orders',array('order_id'=>$order->get_id()))).'#ge-work-documents">Archivos compartidos y cargar documentos</a> ';
            if (GE_WTP_Work_Panel::allowed('production')) echo '<a class="ge-staff-button is-secondary" data-ge-work-nav href="'.esc_url(GE_WTP_Staff_Portal::portal_url('production',array('order_id'=>$order->get_id(),'step'=>'files'))).'">Archivos de producción y aprobación</a>';
        }
        echo '</section>';
        if ($order) self::render_finance($context['commercial_order'] ?: $order,$quote,$order->get_id());
    }
    public static function render_finance($financial,$quote=null,$work_id=0) {
        if (!GE_Organization_Runtime::allowed('finance') && !GE_Organization_Runtime::allowed('quotes',true)) return;
        echo '<section id="ge-work-finance" class="ge-production-card ge-work-finance"><h2>Pagos del pedido</h2><p class="ge-work-total">Total del pedido comercial <strong>'.esc_html(self::money((int)round((float)$financial->get_total()*100),$financial->get_currency())).'</strong></p>';
        if($work_id && $work_id!==$financial->get_id()){$piece=wc_get_order($work_id);$source=self::source($piece);if($source && $source->get_id()===$financial->get_id())echo '<p>'.(is_array($financial->get_meta('_ge_historical_component_sources',true))?'Referencia anterior del trabajo (incluido en el saldo único): ':'Esta orden de producción: ').esc_html(self::money((int)round((float)$piece->get_total()*100),$piece->get_currency())).' · Pedido comercial #'.absint($financial->get_id()).'</p>';}
        $balance=GE_Work_Finance::balance($financial);
        if(!is_wp_error($balance)) {
            $labels=array('unpaid'=>'Pendiente de acreditación','deposit_paid'=>'Pago parcial acreditado','paid'=>'Pagado completo');
            echo '<div class="ge-quote-context"><div><span>Acreditado</span><strong>'.esc_html(self::money($balance['amount_paid_cents'],$financial->get_currency())).'</strong></div><div><span>Saldo pendiente</span><strong>'.esc_html(self::money($balance['amount_due_cents'],$financial->get_currency())).'</strong></div><div><span>Estado del pago</span><strong>'.esc_html($labels[$balance['payment_status']]).'</strong></div></div>';
        }
        GE_Work_Finance_UI::render($financial);
        echo '<details><summary>Comprobantes del portal y condiciones de pago</summary>';
        GE_WTP_Payment_Policy::render_order($financial);GE_WTP_Payments::render_staff_order_payment($financial);
        if($quote)GE_WTP_Commercial_Checkout::render_staff_payment($quote);
        echo '</details></section>';
        if(GE_WTP_Customer_Invoices::staff(get_current_user_id())) {
            echo '<section id="ge-work-invoices" class="ge-production-card ge-work-invoices"><h2>Facturación del pedido</h2><p>Cargá la factura ya emitida, revisá su emisor y enviá esta versión al cliente. Cargarla la deja disponible en el portal; el correo se envía con la acción correspondiente.</p>';
            GE_WTP_Issued_Documents::render_staff($financial,false);GE_Work_Invoice_Mail::render($financial);
            echo '<details><summary>Emitir una nueva factura mediante ARCA</summary>';GE_WTP_ARCA_Emission::render_staff($financial);echo '</details></section>';
        }
    }
    private static function money($cents,$currency) { return $currency==='ARS' ? GE_WTP_Commercial_Quote_UI::money($cents) : html_entity_decode(wp_strip_all_tags(wc_price($cents/100,array('currency'=>$currency))),ENT_QUOTES,'UTF-8'); }
    public static function end() { if (self::$active) { echo '</div></div>'; self::$active=false; } }
}
add_action('wp_enqueue_scripts',array('GE_Work_Master','assets'),90);
