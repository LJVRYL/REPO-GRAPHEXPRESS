<?php
defined( 'ABSPATH' ) || exit;

final class GE_Logistics_UI {
    public static function init() {
        add_action( 'admin_post_ge_order_logistics', array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_ge_quote_logistics', array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_ge_settings_logistics', array( __CLASS__, 'save_settings' ) );
        add_action( 'ge_logistics_order', array( __CLASS__, 'render_order' ), 10, 2 );
        add_action( 'ge_logistics_quote', array( __CLASS__, 'render_quote' ), 10, 2 );
        add_action( 'ge_logistics_inherit', array( __CLASS__, 'inherit' ), 10, 2 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'ge_logistics_product_specs', array( __CLASS__, 'product_specs' ), 5, 3 );
        add_action( 'woocommerce_checkout_create_order', function( $order ) { $order->update_meta_data( '_ge_logistics_requested', 'coordinate' ); } );
    }
    public static function assets() {
        if ( ! is_user_logged_in() ) { return; }
        wp_enqueue_style( 'ge-logistics', plugins_url( 'ge-logistics/style.css', dirname(__DIR__) . '/ge-logistics.php' ), array(), '1.0.0' );
        wp_enqueue_script( 'ge-logistics', plugins_url( 'ge-logistics/forms.js', dirname(__DIR__) . '/ge-logistics.php' ), array(), '1.0.0', true );
        // An explicitly enabled, existing browser-restricted key only. No new billing setup.
        $c = self::config();
        if ( ! empty( $c['maps_enabled'] ) && defined( 'GE_LOGISTICS_MAPS_BROWSER_KEY' ) && GE_LOGISTICS_MAPS_BROWSER_KEY ) {
            wp_add_inline_script( 'ge-logistics', 'window.geLogisticsMaps=' . wp_json_encode( array( 'key'=>GE_LOGISTICS_MAPS_BROWSER_KEY ) ) . ';', 'before' );
        }
    }
    public static function config() { return (array) get_option( GE_Logistics::CONFIG, array() ); }
    public static function product_specs($specs,$object,$kind){
        if($specs)return $specs;
        if('quote'===$kind){$out=array();foreach($object['snapshot']['items']??array() as $line){$spec=GE_Logistics::product_contract($line['_ge_logistics_physical_v1']??array(),$line['quantity']??0);if(is_wp_error($spec))return array();$out[]=$spec;}return $out;}
        $out=array();foreach($object->get_items('line_item') as $item){
            $physical=$item->get_meta('_ge_logistics_physical_v1');
            // Only exact item snapshots are consumed; generic catalog variants are not inferred.
            $spec=GE_Logistics::product_contract($physical,$item->get_quantity());
            if(is_wp_error($spec))return array();$out[]=$spec;
        }return $out;
    }
    private static function staff( $kind, $write = false ) {
        return class_exists( 'GE_WTP_Staff_Portal' ) && GE_WTP_Staff_Portal::can_access() && ( ! class_exists( 'GE_Organization_Runtime' ) || GE_Organization_Runtime::allowed( 'quote' === $kind ? 'quotes' : 'orders', $write ) );
    }
    public static function context( $kind, $id, $write = false ) {
        if ( 'quote' === $kind ) {
            $q = GE_WTP_Commercial_Quotes::get( $id, get_current_user_id() );
            if ( is_wp_error( $q ) || ! $q ) { return new WP_Error( 'access', 'Presupuesto no disponible.' ); }
            if ( ! empty( $q['converted_order_id'] ) ) { return new WP_Error( 'converted', 'La logística se gestiona en el pedido vinculado.' ); }
            $owner = (int) $q['customer_id']; $items = $q['snapshot']['items'] ?? array(); $address = $q['snapshot']['delivery'] ?? array(); $state = (array) get_post_meta( $id, GE_Logistics::META, true ); $object = $q;
        } elseif ( 'order' === $kind ) {
            $object = wc_get_order( $id );
            if ( ! $object || 'yes' === $object->get_meta( '_ge_commercial_payment_order' ) ) { return new WP_Error( 'order', 'Pedido no disponible.' ); }
            $owner = (int) $object->get_customer_id(); $address = (array) $object->get_meta( '_ge_delivery_snapshot' ); $items = array();
            foreach ( $object->get_items( 'line_item' ) as $item ) { $items[] = array( 'id'=>$item->get_id(), 'product'=>$item->get_product_id(), 'quantity'=>$item->get_quantity(), 'specification'=>$item->get_meta( 'Especificaciones' ), 'data'=>$item->get_meta( 'Configuración' ), 'name'=>$item->get_name(), 'tier'=>$item->get_meta('_ge_tier') ); }
            $state = (array) $object->get_meta( GE_Logistics::META );
            if ( ! $address ) { $address = array( 'street'=>$object->get_shipping_address_1(), 'city'=>$object->get_shipping_city(), 'province'=>$object->get_shipping_state(), 'postal_code'=>$object->get_shipping_postcode(), 'recipient'=>trim( $object->get_shipping_first_name() . ' ' . $object->get_shipping_last_name() ), 'phone'=>$object->get_meta('_ge_delivery_phone') ?: $object->get_billing_phone(), 'country'=>$object->get_shipping_country() ?: 'AR' ); }
        } else { return new WP_Error( 'kind', 'Registro inválido.' ); }
        if ( ! self::staff( $kind, $write ) && ( ! $owner || $owner !== get_current_user_id() || ( class_exists('GE_Organization_Runtime') && GE_Organization_Runtime::role(get_current_user_id()) ) ) ) { return new WP_Error( 'access', 'Acceso denegado.' ); }
        if ( 'order' === $kind && class_exists('GE_Organization') && $object->get_meta('_ge_organization_id') && GE_Organization::PRIMARY !== $object->get_meta('_ge_organization_id') ) { return new WP_Error( 'access', 'Organización inválida.' ); }
        $items[] = array('source_destination'=>GE_Logistics::address($address), 'quote_version'=>'quote'===$kind?$q['version']:null);
        $address = GE_Logistics::address( $state['address'] ?? $address );
        $packages = $state['packages'] ?? array(); $c = self::config();
        return array( 'kind'=>$kind, 'id'=>$id, 'owner'=>$owner, 'object'=>$object, 'state'=>$state, 'address'=>$address, 'packages'=>$packages, 'items'=>$items, 'config'=>$c, 'fingerprint'=>GE_Logistics::fingerprint( $address, $packages, $items, $c['origin'] ?? array() ) );
    }
    private static function save( $ctx, $state ) {
        $state['revision'] = (int) ( $ctx['state']['revision'] ?? 0 ) + 1;
        $state['updated_at'] = gmdate( 'c' ); $state['updated_by'] = get_current_user_id();
        if ( 'order' === $ctx['kind'] ) {
            if ( isset($state['address']) && $state['address'] !== ($ctx['state']['address'] ?? null) ) {
                $a=$state['address'];
                if(!isset($state['original_delivery_snapshot']))$state['original_delivery_snapshot']=$ctx['object']->get_meta('_ge_delivery_snapshot');
                $ctx['object']->update_meta_data('_ge_delivery_snapshot',$a);
                foreach(array('street'=>'address_1','city'=>'city','province'=>'state','postal_code'=>'postcode','country'=>'country','recipient'=>'first_name') as $key=>$prop){$setter='set_shipping_'.$prop;$ctx['object']->$setter($a[$key]??'');}
                $ctx['object']->set_shipping_last_name('');
                $ctx['object']->update_meta_data('_ge_delivery_recipient',$a['recipient']??'');
                $ctx['object']->update_meta_data('_ge_delivery_phone',$a['phone']??'');
                $ctx['object']->update_meta_data('_ge_delivery_window',$a['hours']??'');
            }
            $ctx['object']->update_meta_data( GE_Logistics::META, $state );
            $ctx['object']->add_order_note( 'Logística actualizada · revisión ' . $state['revision'] . ' · ' . ( $state['status'] ?? 'coordinación' ) . '. Sin contratación automática.' );
            $ctx['object']->save();
        } else { update_post_meta( $ctx['id'], GE_Logistics::META, $state ); }
    }
    private static function require_ok( $result ) { if ( is_wp_error( $result ) ) { throw new InvalidArgumentException( $result->get_error_message() ); } return $result; }
    private static function input( $key ) { return sanitize_text_field( wp_unslash( $_POST[$key] ?? '' ) ); }
    public static function handle() {
        $kind = 'ge_quote_logistics' === ( $_POST['action'] ?? '' ) ? 'quote' : 'order'; $id = absint( $_POST['record_id'] ?? 0 );
        check_admin_referer( 'ge_logistics_' . $kind . '_' . $id );
        $ctx = self::context( $kind, $id, true ); if ( is_wp_error( $ctx ) ) { wp_die( esc_html( $ctx->get_error_message() ), '', array( 'response'=>403 ) ); }
        $lock = 'ge_logistics_lock_' . $kind . '_' . $id;
        if ( ! add_option( $lock, gmdate('c'), '', false ) ) { wp_die( 'Hay una actualización en curso. Revisá el estado antes de reintentar; si persiste, requiere revisión administrativa.', '', array( 'response'=>409 ) ); }
        $error = '';
        try {
            $ctx = self::require_ok( self::context( $kind, $id, true ) ); $s = $ctx['state']; $op = self::input( 'op' );
            $key = self::input( 'request_key' );
            if ( strlen($key) < 20 ) { throw new InvalidArgumentException('Solicitud inválida. Recargá la página.'); }
            if ( hash_equals( (string) ( $s['last_request'] ?? '' ), $key ) ) { /* Durable replay: no second mutation. */ }
            else {
                if ( (int) ( $s['revision'] ?? 0 ) !== absint( $_POST['revision'] ?? 0 ) ) { throw new InvalidArgumentException( 'Otro usuario cambió la logística. Recargá y revisá antes de guardar.' ); }
                if ( in_array( $s['status'] ?? '', array('dispatched','delivered'), true ) && 'incident' !== $op ) { throw new InvalidArgumentException( 'El despacho ya está registrado. Usá una incidencia para cambios posteriores.' ); }
                if ( 'order' === $kind && ( $ctx['object']->get_meta('_ge_delivery_confirmed_at') || in_array($ctx['object']->get_status(), array('cancelled','refunded','failed','completed','ge-entregado'),true) ) ) { throw new InvalidArgumentException('El pedido está cerrado o inactivo. Conservamos la logística registrada.'); }
                $staff = self::staff( $kind, true );
                if ( 'request' === $op ) {
                    $method = $staff ? self::input('method') : 'coordinate'; if('coordinate'!==$method&&!isset(GE_Logistics::carriers()[$method]))throw new InvalidArgumentException('Elegí una modalidad válida.');
                    $a = GE_Logistics::address( wp_unslash( $_POST['address'] ?? array() ) );
                    if ( 'pickup' !== $method ) { self::require_ok( GE_Logistics::address_check($a) ); }
                    $s = array_merge( $s, array( 'address'=>$a, 'requested'=>$method, 'status'=>'quote_requested', 'offers'=>array(), 'selected'=>'', 'packages'=>array(), 'package_status'=>'pending', 'payment_reference'=>'' ) );
                } elseif ( ! $staff && ! in_array( $op, array( 'select' ), true ) ) { throw new InvalidArgumentException('Acción reservada al equipo de Graphex.'); }
                elseif ( 'plan' === $op ) {
                    $p = self::require_ok( GE_Logistics::packages( wp_unslash( $_POST['packages'] ?? array() ) ) );
                    $a = GE_Logistics::address( wp_unslash( $_POST['address'] ?? array() ) );
                    $s = array_merge( $s, array( 'address'=>$a, 'packages'=>$p, 'package_status'=>!empty($_POST['measured'])?'measured':'estimated', 'package_basis'=>self::input('package_basis'), 'address_reviewed'=>!empty($_POST['address_reviewed']), 'status'=>'planned', 'offers'=>array(), 'selected'=>'', 'payment_reference'=>'' ) );
                } elseif ( 'estimate' === $op ) {
                    $specs = apply_filters( 'ge_logistics_product_specs', array(), $ctx['object'], $kind ); $p=array(); $bases=array();
                    if ( ! is_array($specs) || count($specs)!==count($ctx['items'])-1 ) { throw new InvalidArgumentException('Falta la ficha física de uno o más productos. Completá bultos medidos o pedí la ficha al área de productos.'); }
                    foreach($specs as $spec){$e=self::require_ok(GE_Logistics::estimate($spec));$p=array_merge($p,$e['packages']);$bases[]=$e['basis'];}
                    $p=self::require_ok(GE_Logistics::packages($p));
                    $s=array_merge($s,array('packages'=>$p,'package_status'=>'estimated','package_basis'=>implode(' · ',$bases),'offers'=>array(),'selected'=>'','status'=>'planned','payment_reference'=>''));
                } elseif ( 'offer' === $op ) {
                    if ( empty($ctx['config']['origin_confirmed']) ) { throw new InvalidArgumentException('Confirmá el origen privado de despacho en Configuración → Logística.'); }
                    $carrier=self::input('carrier');self::require_ok(GE_Logistics::carrier_check($carrier,$ctx['packages']));
                    if('pickup'!==$carrier){self::require_ok(GE_Logistics::address_check($ctx['address']));if(empty($s['address_reviewed']))throw new InvalidArgumentException('Revisá el destino antes de ofrecer un envío.');}
                    $cents=GE_Logistics::cents(self::input('price')); $expires=strtotime(self::input('expires').' 23:59:59 UTC'); $scope=self::input('scope'); $eta=self::input('eta'); $basis=self::input('basis');
                    if(null===$cents || !$expires || $expires<=time() || $expires>time()+31*86400 || !$scope || !$eta || !$basis || empty($_POST['coverage_verified']))throw new InvalidArgumentException('Completá costo final ARS, alcance, plazo desde despacho, referencia y vencimiento (máximo 31 días); verificá cobertura.');
                    if(count($s['offers']??array())>=8)throw new InvalidArgumentException('Máximo ocho opciones por cotización. Replanificá para renovarlas.');
                    $s['offers'][]=array('id'=>wp_generate_uuid4(),'carrier'=>$carrier,'price_cents'=>$cents,'scope'=>$scope,'eta'=>$eta,'basis'=>$basis,'expires_at'=>$expires,'coverage_verified'=>true,'fingerprint'=>$ctx['fingerprint'],'source'=>'manual','package_status'=>$s['package_status']??'pending');$s['status']='offered';
                } elseif ( 'fixed' === $op ) {
                    $offers=self::fixed_offers($ctx);if(!$offers)throw new InvalidArgumentException('No hay tarifa local vigente que cubra este CP, provincia y estos bultos. Cotizá manualmente.');
                    $s['offers']=$offers;$s['selected']='';$s['status']='offered';$s['payment_reference']='';
                } elseif ( 'select' === $op ) {
                    $chosen=null;foreach($s['offers']??array() as $offer){if($offer['id']===self::input('offer_id'))$chosen=$offer;}
                    if(!$chosen)throw new InvalidArgumentException('Elegí una cotización disponible.');self::require_ok(GE_Logistics::offer_check($chosen,$ctx['fingerprint']));
                    $s['selected']=$chosen['id'];$s['accepted_by']=get_current_user_id();$s['accepted_at']=gmdate('c');$s['status']='selected';$s['payment_reference']='';
                } elseif ( 'dispatch' === $op ) {
                    if('order'!==$kind)throw new InvalidArgumentException('Primero vinculá el pedido.');
                    self::require_ok(GE_WTP_Job_Flow::delivery_check($ctx['object']));
                    $chosen=self::chosen($ctx);if(!$chosen)throw new InvalidArgumentException('Falta la elección del transporte.');self::require_ok(GE_Logistics::offer_check($chosen,$ctx['fingerprint']));
                    if('pickup'!==$chosen['carrier'] && (empty($s['address_reviewed']) || 'measured'!==($s['package_status']??'')))throw new InvalidArgumentException('Para despachar revisá el destino y confirmá los bultos realmente pesados y medidos.');
                    $payment=self::input('payment_reference');if($chosen['price_cents']>0 && (!$payment || empty($_POST['transport_payment_checked'])))throw new InvalidArgumentException('Registrá el comprobante de transporte o la condición confirmada de pago en destino.');
                    $tracking=self::input('tracking');$tracking_url=self::require_ok(GE_Logistics::tracking_url(self::input('tracking_url'),$chosen['carrier']));
                    if(in_array($chosen['carrier'],array('via_cargo','correo','andreani'),true) && !$tracking)throw new InvalidArgumentException('Registrá el número de guía real del transportista.');
                    if(empty($_POST['dispatch_confirmed']))throw new InvalidArgumentException('Confirmá la entrega física al transportista o el retiro real.');
                    $s=array_merge($s,array('status'=>'dispatched','dispatched_at'=>gmdate('c'),'dispatched_by'=>get_current_user_id(),'tracking'=>$tracking,'tracking_url'=>$tracking_url,'payment_reference'=>$payment));
                } elseif ( 'incident' === $op ) {
                    $note=self::input('incident');if(!$note)throw new InvalidArgumentException('Describí la incidencia.');$s['incidents'][]=array('at'=>gmdate('c'),'actor'=>get_current_user_id(),'note'=>$note);$s['incidents']=array_slice($s['incidents'],-30);
                } else { throw new InvalidArgumentException('Acción inválida.'); }
                $s['last_request']=$key;self::save($ctx,$s);
            }
        } catch ( InvalidArgumentException $e ) { $error=$e->getMessage(); }
        finally { delete_option($lock); }
        if($error)wp_die(esc_html($error).' Volvé y revisá los datos.','',array('response'=>409));
        $url=self::staff($kind)?GE_WTP_Staff_Portal::portal_url('quote'===$kind?'quotes':'orders',array('quote'===$kind?'quote_id':'order_id'=>$id)):GE_WTP_Portal::portal_url('quote'===$kind?'presupuestos':'pedidos',array('quote'===$kind?'presupuesto':'order_id'=>$id));
        wp_safe_redirect(add_query_arg('logistics_saved',1,$url).'#ge-logistics');exit;
    }
    public static function chosen($ctx){foreach($ctx['state']['offers']??array() as $offer){if($offer['id']===($ctx['state']['selected']??''))return $offer;}return null;}
    public static function fixed_offers($ctx){
        $c=$ctx['config'];if(empty($c['origin_confirmed'])||empty($ctx['state']['address_reviewed'])||is_wp_error(GE_Logistics::address_check($ctx['address']))||!$ctx['packages'])return array();
        $w=GE_Logistics::weight($ctx['packages']);$cp=preg_replace('/\D/','',$ctx['address']['postal_code']);$out=array();
        foreach($c['rates']??array() as $rate){if(empty($rate['enabled'])||$rate['expires_at']<=time()||$rate['province']!==$ctx['address']['province']||!in_array($cp,$rate['postcodes'],true)||$w['real_kg']>$rate['max_kg']||$w['count']>$rate['max_packages'])continue;
            $fit=true;foreach($ctx['packages'] as $p){if(max($p['length_cm'],$p['width_cm'],$p['height_cm'])>$rate['max_side_cm'])$fit=false;}if(!$fit)continue;
            $out[]=array('id'=>wp_generate_uuid4(),'carrier'=>'local','price_cents'=>$rate['price_cents'],'scope'=>$rate['scope'],'eta'=>$rate['eta'],'basis'=>$rate['basis'],'expires_at'=>$rate['expires_at'],'coverage_verified'=>true,'fingerprint'=>$ctx['fingerprint'],'source'=>'fixed','package_status'=>$ctx['state']['package_status']??'pending');}
        return $out;
    }
    public static function inherit($quote,$order){
        $s=(array)get_post_meta($quote['id'],GE_Logistics::META,true);if(!$s||$order->get_meta(GE_Logistics::META))return;
        // Order item identities differ: all prices need confirmation in the new order context.
        $s['offers']=array();$s['selected']='';$s['status']='quote_requested';$s['source_quote']=$quote['id'];$s['revision']=1;$s['last_request']='';$s['payment_reference']='';$order->update_meta_data(GE_Logistics::META,$s);$order->save();
    }
    private static function form($ctx,$op){echo '<form class="ge-logistics-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_'.$ctx['kind'].'_logistics"><input type="hidden" name="record_id" value="'.(int)$ctx['id'].'"><input type="hidden" name="op" value="'.esc_attr($op).'"><input type="hidden" name="revision" value="'.(int)($ctx['state']['revision']??0).'"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'">';wp_nonce_field('ge_logistics_'.$ctx['kind'].'_'.$ctx['id']);}
    private static function field($label,$name,$value='',$type='text',$attrs=''){echo '<label>'.esc_html($label).'<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.$attrs.'></label>';}
    private static function address_fields($a){echo '<div class="ge-logistics-grid" data-ge-logistics-address>';
        foreach(array('recipient'=>'Quién recibe','street'=>'Calle, número, piso / departamento','city'=>'Localidad','postal_code'=>'Código postal / CPA','phone'=>'Teléfono de recepción','hours'=>'Días y horario de recepción','notes'=>'Indicaciones') as $key=>$label)self::field($label,'address['.$key.']',$a[$key]??'','phone'===$key?'tel':'text','maxlength="220" data-address-field="'.$key.'"');
        echo '<label>Provincia<select name="address[province]" data-address-field="province"><option value="">Elegí provincia</option>';foreach(GE_Logistics::provinces() as $code=>$name)echo '<option value="'.$code.'"'.selected($a['province']??'',$code,false).'>'.esc_html($name).'</option>';echo '</select></label><label>País<select name="address[country]" data-address-field="country"><option value="AR">Argentina</option></select></label><input type="hidden" name="address[place_id]" data-address-field="place_id" value="'.esc_attr($a['place_id']??'').'"><p class="ge-logistics-help">La dirección debe revisarse para el servicio elegido. Una sugerencia del mapa no confirma cobertura ni entregabilidad.</p><button type="button" data-ge-maps-search hidden>Buscar dirección con Google Maps</button><div data-ge-maps-slot></div></div>';}
    public static function render_order($order,$staff=false){self::render('order',$order->get_id(),$staff);}
    public static function render_quote($quote,$customer=false){
        if(!empty($quote['converted_order_id'])){echo '<p class="ge-logistics-help">El envío se coordina en el pedido vinculado. El transporte tiene una cotización separada.</p>';return;}self::render('quote',$quote['id'],!$customer);
    }
    public static function render($kind,$id,$staff=false){
        $ctx=self::context($kind,$id);if(is_wp_error($ctx))return;$staff=$staff&&self::staff($kind,true);$s=$ctx['state'];$status=$s['status']??'pending';$chosen=self::chosen($ctx);$closed='order'===$kind&&($ctx['object']->get_meta('_ge_delivery_confirmed_at')||in_array($ctx['object']->get_status(),array('completed','ge-entregado','cancelled','refunded','failed'),true));
        $labels=array('pending'=>'A coordinar','quote_requested'=>'Cotización solicitada','planned'=>'Bultos preparados para cotizar','offered'=>'Opciones disponibles','selected'=>'Opción elegida','dispatched'=>'Despacho registrado');
        echo '<section class="ge-logistics ge-panel ge-admin-panel" id="ge-logistics"><h2>Envío y retiro</h2><p><strong>'.esc_html($closed?'Pedido cerrado':($labels[$status]??'A coordinar')).'</strong></p><p>El transporte se cotiza por separado en ARS y requiere coordinación. El plazo informado comienza cuando se despacha; no incluye la producción. Retiro únicamente con cita y ubicación coordinada de forma privada.</p>';
        if(isset($_GET['logistics_saved']))echo '<p role="status">Logística guardada. No se contrató ni cobró un envío.</p>';
        if($chosen){$valid=GE_Logistics::offer_check($chosen,$ctx['fingerprint']);echo '<p><strong>'.esc_html(GE_Logistics::carriers()[$chosen['carrier']]).' · '.esc_html(number_format_i18n($chosen['price_cents']/100,2)).' ARS</strong><br>'.esc_html($chosen['scope'].' · '.$chosen['eta']).'</p>';if(is_wp_error($valid)&&'dispatched'!==$status&&!$closed)echo '<p role="alert">La cotización requiere renovación antes de despachar.</p>';}
        if(!empty($s['tracking']))echo '<p>Guía: '.esc_html($s['tracking']).'</p>';if(!empty($s['tracking_url'])){ $url=GE_Logistics::tracking_url($s['tracking_url'],$chosen['carrier']??'');if(!is_wp_error($url))echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Seguimiento oficial</a>'; }
        if(!empty($s['dispatched_at']))echo '<p>Despacho registrado: '.esc_html($s['dispatched_at']).'. La recepción se confirma mediante el flujo de entrega del pedido.</p>';
        if($closed||'dispatched'===$status){if($staff&&!$closed)self::incident_form($ctx);echo '</section>';return;}
        echo '<details'.(!$s?' open':'').'><summary>Coordinar entrega · dirección y horario de recepción</summary>';self::form($ctx,'request');if($staff){echo '<label>Modalidad preferida<select name="method"><option value="coordinate"'.selected($s['requested']??'coordinate','coordinate',false).'>Coordinar entrega · a cotizar</option>';foreach(GE_Logistics::carriers() as $key=>$label)echo '<option value="'.$key.'"'.selected($s['requested']??'coordinate',$key,false).'>'.esc_html($label.('pickup'===$key?' · a coordinar':' · sujeto a cotización y cobertura')).'</option>';echo '</select></label>';}else{echo '<input type="hidden" name="method" value="coordinate"><p><strong>Coordinar entrega · costo a cotizar</strong></p>';}self::address_fields($ctx['address']);echo '<button type="submit">Guardar dirección y solicitar cotización de entrega</button><p class="ge-logistics-help">Guardamos tus datos para coordinar y cotizar. Cambiar destino renueva los bultos y las cotizaciones anteriores.</p></form></details>';
        if($staff){
            echo '<details><summary>Revisar destino y bultos</summary>';self::form($ctx,'plan');self::address_fields($ctx['address']);echo '<label class="ge-logistics-check"><input type="checkbox" name="address_reviewed" value="1"'.checked(!empty($s['address_reviewed']),true,false).'>Revisé destino, CP, contacto e indicaciones</label><div data-ge-packages>';
            $rows=$ctx['packages']?:array(array());foreach($rows as $i=>$p)self::package_row($i,$p);echo '</div><button type="button" data-ge-add-package>Agregar tipo de bulto</button><template data-ge-package-template>';self::package_row('__INDEX__',array());echo '</template>';self::field('Base de cálculo / embalaje','package_basis',$s['package_basis']??'');echo '<label class="ge-logistics-check"><input type="checkbox" name="measured" value="1"'.checked('measured'===($s['package_status']??''),true,false).'>Pesé y medí todos los bultos embalados</label><button type="submit">Guardar bultos y renovar cotización</button></form>';self::form($ctx,'estimate');echo '<button type="submit">Estimar desde fichas productivas disponibles</button><p class="ge-logistics-help">La estimación depende de cantidad, medida, papel, espesor, terminación y packaging declarados. No reemplaza pesar y medir.</p></form></details>';
            if($ctx['packages']){$w=GE_Logistics::weight($ctx['packages']);echo '<p>'.(int)$w['count'].' bultos · '.esc_html($w['real_kg']).' kg reales · '.esc_html('measured'===($s['package_status']??'')?'medidos':'estimados').'. Peso tarifable: lo confirma cada servicio con su aforo y redondeo.</p>';}
            echo '<details><summary>Cotizar transporte</summary>';self::form($ctx,'offer');echo '<label>Servicio<select name="carrier">';foreach(GE_Logistics::carriers() as $key=>$label)echo '<option value="'.$key.'">'.esc_html($label).'</option>';echo '</select></label><div class="ge-logistics-grid">';self::field('Costo final transporte (ARS)','price','','number','min="0" step="0.01" required');self::field('Vence al finalizar (UTC)','expires',gmdate('Y-m-d',time()+7*86400),'date','required');self::field('Alcance: domicilio / sucursal y nombre','scope','','text','required maxlength="220"');self::field('Plazo estimado desde despacho','eta','','text','required maxlength="220"');self::field('Referencia cotización / tarifa y condiciones','basis','','text','required maxlength="220"');echo '</div><label class="ge-logistics-check"><input type="checkbox" name="coverage_verified" value="1" required>Verifiqué cobertura, servicio, límites, costo final y sucursal si corresponde</label><button type="submit">Guardar opción para el cliente</button></form>';self::form($ctx,'fixed');echo '<button type="submit">Aplicar tarifas locales vigentes</button></form><a href="'.esc_url(GE_WTP_Staff_Portal::portal_url('settings',array('category'=>'logistics'))).'">Configurar logística y tarifas locales</a></details>';
        }
        $live=array_filter($s['offers']??array(),function($o)use($ctx){return !is_wp_error(GE_Logistics::offer_check($o,$ctx['fingerprint']));});
        if($live){self::form($ctx,'select');echo '<fieldset><legend>Elegí la opción cotizada</legend>';foreach($live as $o)echo '<label class="ge-logistics-choice"><input type="radio" name="offer_id" required value="'.esc_attr($o['id']).'"'.checked($s['selected']??'',$o['id'],false).'><span><strong>'.esc_html(GE_Logistics::carriers()[$o['carrier']]).' · '.esc_html(number_format_i18n($o['price_cents']/100,2)).' ARS</strong><br>'.esc_html($o['scope'].' · '.$o['eta']).'<br><small>Vigente hasta '.esc_html(gmdate('d/m/Y H:i',$o['expires_at'])).' UTC. '.('measured'!==($o['package_status']??'')?'Bultos estimados; confirmar antes del despacho.':'Bultos medidos.').'</small></span></label>';echo '</fieldset><button type="submit">Confirmar opción de transporte</button><p>Esta elección acepta la cotización de transporte separada. Su pago se coordina y verifica antes del despacho.</p></form>';}
        else echo '<p>Sin opciones de transporte confirmadas para este destino. El equipo verificará cobertura, bultos y precio antes de ofrecerlas.</p>';
        if($staff&&'order'===$kind&&$chosen){echo '<details><summary>Registrar despacho real</summary>';self::form($ctx,'dispatch');self::field('Número de guía / referencia real','tracking');self::field('Enlace HTTPS oficial de seguimiento','tracking_url','','url');self::field('Comprobante transporte / pago confirmado en destino','payment_reference');echo '<label class="ge-logistics-check"><input type="checkbox" name="transport_payment_checked" value="1">Verifiqué el pago del transporte o su condición acordada</label><label class="ge-logistics-check"><input type="checkbox" name="dispatch_confirmed" value="1" required>Se entregó físicamente al transportista o se realizó el retiro</label><button type="submit">Registrar despacho</button><p>No compra etiquetas ni pide retiros. El cierre de recepción conserva la validación actual de producto listo y pago.</p></form></details>';self::incident_form($ctx);}
        echo '</section>';
    }
    private static function package_row($i,$p){echo '<fieldset class="ge-logistics-package"><legend>Tipo de bulto</legend><div class="ge-logistics-grid">';foreach(array('count'=>'Cantidad de cajas iguales','weight_kg'=>'Peso por caja (kg)','length_cm'=>'Largo (cm)','width_cm'=>'Ancho (cm)','height_cm'=>'Alto (cm)') as $key=>$label)self::field($label,'packages['.$i.']['.$key.']',$p[$key]??'','number','min="0.001" step="'.('count'===$key?'1':'0.001').'"');echo '</div><button type="button" data-ge-remove-package>Quitar tipo de bulto</button></fieldset>';}
    private static function incident_form($ctx){self::form($ctx,'incident');self::field('Incidencia operativa (uso interno)','incident');echo '<button type="submit">Registrar incidencia</button></form>';}
    public static function settings(){
        if(!current_user_can('manage_options') && !(class_exists('GE_Organization')&&GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),true))){echo '<p>La configuración requiere administrador.</p>';return;}
        $c=self::config();echo '<section class="ge-logistics"><h2>Logística y tarifas locales</h2><p>Origen privado: sólo el equipo autorizado lo ve. El cliente ve retiro con cita, sin publicar domicilio.</p><form class="ge-logistics-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ge_settings_logistics"><input type="hidden" name="config_revision" value="'.(int)($c['revision']??0).'">';wp_nonce_field('ge_settings_logistics');echo '<div class="ge-logistics-grid">';foreach(array('street'=>'Origen privado de despacho','city'=>'Localidad de origen','province'=>'Provincia (código AR)','postal_code'=>'CP de origen','hours'=>'Horario de despacho') as $key=>$label)self::field($label,'origin['.$key.']',$c['origin'][$key]??'');echo '</div><label class="ge-logistics-check"><input type="checkbox" name="origin_confirmed" value="1"'.checked(!empty($c['origin_confirmed']),true,false).'>Confirmé este origen y sus horarios</label><p>Google Maps está desactivado salvo acceso existente y autorización de uso. La clave restringida se configura por el mecanismo seguro, nunca en este formulario.</p><label class="ge-logistics-check"><input type="checkbox" name="maps_enabled" value="1"'.checked(!empty($c['maps_enabled']),true,false).'>Habilitar Places con clave existente, términos y facturación previamente revisados</label><h3>Tarifas de entrega propia</h3><p>Sin valores predeterminados. Para activar una tarifa, definí CP exactos, provincia, límites, costo final, plazo, respaldo y vigencia. No aplica a carriers nacionales.</p>';
        $rates=$c['rates']??array();for($i=0;$i<max(3,count($rates));$i++){$r=$rates[$i]??array();echo '<fieldset><legend>Tarifa local '.($i+1).'</legend><label class="ge-logistics-check"><input type="checkbox" name="rates['.$i.'][enabled]" value="1"'.checked(!empty($r['enabled']),true,false).'>Activa</label><div class="ge-logistics-grid">';foreach(array('scope'=>'Nombre / alcance','province'=>'Provincia (código AR)','postcodes'=>'CP de 4 números, separados por coma','price'=>'Costo final ARS','eta'=>'Plazo desde despacho','max_kg'=>'Peso real total máximo kg','max_packages'=>'Bultos máximos','max_side_cm'=>'Lado máximo por bulto cm','expires'=>'Vencimiento UTC','basis'=>'Respaldo del precio y condiciones') as $key=>$label){$v=$r[$key]??'';if('postcodes'===$key)$v=implode(',',$r['postcodes']??array());if('price'===$key&&isset($r['price_cents']))$v=number_format($r['price_cents']/100,2,'.','');if('expires'===$key&&isset($r['expires_at']))$v=gmdate('Y-m-d',$r['expires_at']);self::field($label,'rates['.$i.']['.$key.']',$v);}echo '</div></fieldset>';}
        echo '<button type="submit">Guardar configuración</button></form></section>';
    }
    public static function save_settings(){
        check_admin_referer('ge_settings_logistics');if(!current_user_can('manage_options')&&!(class_exists('GE_Organization')&&GE_Organization::can(GE_Organization::PRIMARY,get_current_user_id(),true)))wp_die('Acceso denegado.','',array('response'=>403));
        $lock='ge_logistics_config_lock';if(!add_option($lock,gmdate('c'),'',false))wp_die('Configuración en actualización.','',array('response'=>409));$error='';
        try{$old=self::config();if(absint($_POST['config_revision']??0)!==(int)($old['revision']??0))throw new InvalidArgumentException('Configuración modificada por otro usuario. Recargá.');
            $origin=GE_Logistics::address(wp_unslash($_POST['origin']??array()));$confirmed=!empty($_POST['origin_confirmed']);
            if($confirmed&&(!$origin['street']||!$origin['city']||!$origin['province']||!preg_match('/^(?:\d{4}|[A-Z]\d{4}[A-Z]{3})$/',$origin['postal_code'])||!$origin['hours']))throw new InvalidArgumentException('Completá origen privado, CP, provincia y horario para confirmarlo.');
            $rates=array();foreach(array_slice((array)wp_unslash($_POST['rates']??array()),0,10) as $r){if(empty($r['enabled']))continue;$price=GE_Logistics::cents($r['price']??'');$cp=array_map('trim',explode(',',$r['postcodes']??''));$province=GE_Logistics::province($r['province']??'');$expires=strtotime(($r['expires']??'').' 23:59:59 UTC');
                if(null===$price||!$province||!$expires||$expires<=time()||$expires>time()+92*86400||empty($r['scope'])||empty($r['eta'])||empty($r['basis'])||!$cp)throw new InvalidArgumentException('Tarifa activa incompleta: revisá respaldo, precio y vencimiento (máximo 92 días).');foreach($cp as $v){if(!preg_match('/^\d{4}$/',$v))throw new InvalidArgumentException('Cada CP de tarifa debe contener 4 números.');}
                foreach(array('max_kg','max_packages','max_side_cm') as $key){if(!is_numeric($r[$key]??null)||$r[$key]<=0||!is_finite((float)$r[$key]))throw new InvalidArgumentException('La tarifa requiere límites de peso, bultos y lado máximos.');}if(floor($r['max_packages'])!=$r['max_packages'])throw new InvalidArgumentException('Bultos máximos debe ser entero.');
                $rates[]=array('enabled'=>true,'scope'=>sanitize_text_field($r['scope']),'eta'=>sanitize_text_field($r['eta']),'basis'=>sanitize_text_field($r['basis']),'province'=>$province,'postcodes'=>array_values(array_unique($cp)),'price_cents'=>$price,'expires_at'=>$expires,'max_kg'=>(float)$r['max_kg'],'max_packages'=>(int)$r['max_packages'],'max_side_cm'=>(float)$r['max_side_cm']);}
            $maps=!empty($_POST['maps_enabled']);if($maps&&(!defined('GE_LOGISTICS_MAPS_BROWSER_KEY')||!GE_LOGISTICS_MAPS_BROWSER_KEY))throw new InvalidArgumentException('Falta la clave restringida de Maps en el mecanismo seguro. No se activó Google.');
            update_option(GE_Logistics::CONFIG,array('revision'=>(int)($old['revision']??0)+1,'origin'=>$origin,'origin_confirmed'=>$confirmed,'maps_enabled'=>$maps,'rates'=>$rates,'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id()),false);
        }catch(InvalidArgumentException $e){$error=$e->getMessage();}finally{delete_option($lock);}if($error)wp_die(esc_html($error),'',array('response'=>409));wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('settings',array('category'=>'logistics','logistics_saved'=>1)));exit;
    }
}
