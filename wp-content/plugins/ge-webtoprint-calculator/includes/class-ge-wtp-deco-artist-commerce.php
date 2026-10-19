<?php
defined('ABSPATH') || exit;

/** Artist originals and prices are resolved from approved native records, never browser fields. */
final class GE_WTP_Deco_Artist_Commerce {
    const FEE_META='_ge_deco_mp_fee_policy_v1';
    public static function init() {
        add_action('woocommerce_checkout_order_created',array(__CLASS__,'freeze_order'),90,1);
        add_action('woocommerce_store_api_checkout_order_processed',array(__CLASS__,'freeze_order'),90,1);
        add_action('woocommerce_check_cart_items',array(__CLASS__,'check_cart'),90);
        add_action('rest_api_init',array(__CLASS__,'review_route'));
    }
    public static function source($id,$fulfillment=false) {
        list($post,$revision)=GE_WTP_Deco_Creators::parse_id($id);
        if(!$fulfillment)$row=GE_WTP_Deco_Creators::resolve($id,false);
        else{$row=get_post_meta($post,'_ge_deco_creator_history_'.$revision,true);if(!$row||($row['work_id']??'')!==$id)throw new InvalidArgumentException('unknown_artwork');}
        $cache=get_option('ge_deco_creator_cache_'.$id,array());$original=$cache['original']??array();
        $path=GE_WTP_VPS_Storage::download_path($original['relative_path']??'');$analysis=GE_WTP_File_Analysis::from_ref($cache['analysis_ref']??'');
        if(!$path||is_link($path)||!$analysis||!in_array($analysis['status']??'',array('analyzed','warning'),true)
            ||!empty($analysis['facts']['blockers'])||($analysis['facts']['page_count']??0)!==1
            ||hash_file('sha256',$path)!==($analysis['facts']['checksum_sha256']??''))throw new InvalidArgumentException('analysis_required');
        if(!$fulfillment&&($row['source']['provider']??'')==='drive'&&($cache['prepared_at']??0)<time()-900)throw new InvalidArgumentException('source_revalidation_required');
        $pixels=$analysis['facts']['pixel_dimensions']??array();if(in_array($analysis['facts']['metadata']['exif_orientation']??1,array(5,6,7,8),true))$pixels=array_reverse($pixels);
        if(count($pixels)!==2||min($pixels)<=0)throw new InvalidArgumentException('invalid_image');
        return array('row'=>$row,'path'=>$path,'sha256'=>$analysis['facts']['checksum_sha256'],'pixels'=>$pixels,'analysis'=>$analysis);
    }
    public static function context($row) {
        if(!GE_WTP_Deco_Marketplace_Checkout::enabled())throw new InvalidArgumentException('creator_sales_pending');
        if(($row['sales_license_version']??0)!==1||empty($row['sales_license_at'])||($row['rights_owner']??0)!==(int)$row['owner_id'])throw new InvalidArgumentException('artist_print_permission_required');
        $artist=(int)$row['owner_id'];$account=GE_WTP_Deco_Marketplace::status($artist);$fee=get_user_meta($artist,self::FEE_META,true);
        if(!$account['connected']||!is_array($fee)||($fee['collector_id']??'')!==$account['account_id']
            ||($fee['application_id']??'')!==GE_WTP_Deco_Marketplace::config()['client_id']||($fee['mode']??'')!==GE_WTP_Deco_Marketplace::config()['mode'])throw new InvalidArgumentException('artist_fee_review_required');
        // Validate expiry/shape with a harmless arithmetic quote, without making a payment.
        GE_Deco_Marketplace_Settlement::quote(1,1,0,$fee,time());
        return array('artist_id'=>$artist,'collector_id'=>$account['account_id'],'pricing'=>GE_WTP_Deco_Marketplace::pricing($artist),
            'work_rule'=>$row['artist_price']??array('mode'=>'inherit'),'fee'=>$fee);
    }
    public static function finance_permission($request=null) {
        if(!GE_WTP_Deco_Creators::enabled()||GE_WTP_Portal::is_staff_preview()||!current_user_can('manage_woocommerce')
            ||(class_exists('GE_Organization_Runtime')&&!GE_Organization_Runtime::allowed('finance',true)))return new WP_Error('forbidden','Acceso de Administración requerido.',array('status'=>403));
        if($request&&$request->get_method()==='POST')return GE_WTP_Deco_Gallery::write_permission($request);return true;
    }
    public static function review_route() {
        if(!GE_WTP_Deco_Creators::enabled())return;
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-marketplace-review',array('methods'=>'POST','permission_callback'=>array(__CLASS__,'finance_permission'),
            'callback'=>static function($r){try{return self::approve_fee($r->get_json_params());}catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'Revisá la cuenta y la tarifa documentada.',array('status'=>409));}}));
    }
    public static function approve_fee($data) {
        if(self::finance_permission()!==true)throw new InvalidArgumentException('finance_permission_required');
        $artist=$data['artist_id']??0;if(!is_int($artist)||!GE_WTP_Portal::is_customer_user(get_user_by('id',$artist)))throw new InvalidArgumentException('invalid_artist');
        $account=GE_WTP_Deco_Marketplace::status($artist);if(!$account['connected'])throw new InvalidArgumentException('artist_not_connected');
        $evidence=sanitize_text_field($data['evidence_ref']??'');if(strlen($evidence)<8||strlen($evidence)>200)throw new InvalidArgumentException('fee_evidence_required');
        $fee=array('rate_bps'=>$data['rate_bps']??null,'fixed_cents'=>$data['fixed_cents']??null,'verified_at'=>time(),'valid_until'=>time()+30*DAY_IN_SECONDS,
            'evidence_ref'=>$evidence,'revision'=>wp_generate_uuid4(),'collector_id'=>$account['account_id'],'payment_method_id'=>'account_money',
            'application_id'=>GE_WTP_Deco_Marketplace::config()['client_id'],'mode'=>GE_WTP_Deco_Marketplace::config()['mode'],'approved_by'=>get_current_user_id());
        GE_Deco_Marketplace_Settlement::quote(1,1,0,$fee,time());update_user_meta($artist,self::FEE_META,$fee);
        return array('saved'=>true,'valid_until'=>$fee['valid_until']);
    }
    public static function render_fee_review() {
        if(self::finance_permission()!==true)return;
        echo '<section class="ge-panel ge-creator" id="gc-fee-review"><h2>Artistas · tarifas de Mercado Pago</h2><p>Registrá la comisión total y el cargo fijo verificados para pagos con saldo de cada cuenta. Incluí los impuestos del procesador. No se descuenta este costo del importe elegido por el artista. La aprobación vence a los 30 días.</p><p id="gc-fee-review-status" role="status"></p>';
        $count=0;
        foreach(get_users(array('number'=>100,'meta_key'=>GE_WTP_Deco_Marketplace::META))as $user){
            if(!GE_WTP_Portal::is_customer_user($user))continue;$account=GE_WTP_Deco_Marketplace::status($user->ID);if(!$account['connected'])continue;$count++;
            $profile=GE_WTP_Deco_Creators::profile($user->ID);$fee=get_user_meta($user->ID,self::FEE_META,true);
            echo '<form class="gc-panel" data-gc-fee-artist="'.esc_attr($user->ID).'"><h3>'.esc_html($profile['title']).'</h3><p>Cuenta '.esc_html($account['account_id']).' · '.esc_html($account['mode']).'</p><label>Comisión total verificada (%)<input name="rate" inputmode="decimal" required value="'.esc_attr(isset($fee['rate_bps'])?number_format($fee['rate_bps']/100,2,'.',''):'').'"></label><label>Cargo fijo verificado (ARS)<input name="fixed" inputmode="decimal" required value="'.esc_attr(isset($fee['fixed_cents'])?number_format($fee['fixed_cents']/100,2,'.',''):'').'"></label><label>Referencia de la tarifa revisada<input name="evidence" maxlength="200" minlength="8" required value="'.esc_attr($fee['evidence_ref']??'').'"></label><button type="submit">Guardar tarifa verificada</button></form>';
        }
        if(!$count)echo '<p>No hay artistas con Mercado Pago conectado.</p>';echo '</section>';
    }
    public static function public_prices($row) {
        $id=GE_WTP_Deco_Release::ensure_product($row['work_id']);$manifest=GE_WTP_Deco_Prints::manifest($id);$prices=array();
        foreach($manifest['options']as $entry)$prices[$entry['catalog_option_id']]=(int)round($entry['approved_base_price']*100);
        $fee=$manifest['artist_context']['fee'];$freight=$manifest['freight_policy'];
        $frames=GE_Deco_Marketplace_Settlement::quote($freight['frames_cents'],0,0,$fee,time())['total_cents'];
        $large=GE_Deco_Marketplace_Settlement::quote($freight['frames_cents']+$freight['large_format_cents'],0,0,$fee,time())['total_cents']-$frames;
        $per_option=array();
        foreach($manifest['options']as $entry)$per_option[$entry['catalog_option_id']]=($entry['artist_quote']['artist_cents']??0)>0
            ?array('frames'=>$frames,'large_format'=>$large):array('frames'=>$freight['frames_cents'],'large_format'=>$freight['large_format_cents']);
        return array('artist_sales_enabled'=>true,'unit_prices_cents'=>$prices,'handling_cents'=>array('frames'=>$frames,'large_format'=>$large),'handling_by_option_cents'=>$per_option);
    }
    public static function selection($id,$data) {
        $source=self::source($id);self::context($source['row']);$option=null;
        foreach(GE_WTP_Deco_Gallery::options()as $candidate)if($candidate['group']===($data['group']??null)&&$candidate['model']===($data['model']??null)&&$candidate['finish']===($data['finish']??null)&&implode('x',$candidate['size'])===($data['size']??null)){$option=$candidate;break;}
        if(!$option||!in_array($data['orientation']??'',array('portrait','landscape'),true)||!in_array($data['fit']??'',array('cover','contain'),true))throw new InvalidArgumentException('invalid_selection');
        $size=$data['orientation']==='portrait'?$option['size']:array_reverse($option['size']);GE_Deco_Print_Quality::enforce($source['pixels'],array_map(static function($v){return(int)round($v*10);},$size),$data['fit']);
        $key=bin2hex(random_bytes(16));$selection=array('selection_id'=>$key,'work_id'=>$id,'source_sha256'=>$source['sha256'],'option_id'=>$option['id'],'orientation'=>$data['orientation'],'fit'=>$data['fit'],'production_approved'=>false);
        $path=GE_WTP_Deco_Creators::folder($id).'/selection-'.$key.'.json';$handle=fopen($path,'x');if(!$handle)throw new InvalidArgumentException('busy');fwrite($handle,wp_json_encode($selection));fclose($handle);chmod($path,0600);return array('selection_id'=>$key);
    }
    public static function cart_context($lines) {
        $artist=0;$physical=0;$royalty=0;$large=false;$policy=null;$fee=null;$other=false;
        foreach($lines as $line){$s=$line['ge_deco_snapshot']??array();if(empty($s['artist_id'])){$other=true;continue;}
            if($artist&&$artist!==$s['artist_id'])throw new InvalidArgumentException('one_artist_per_order');
            GE_WTP_Deco_Prints::assert_cart_line($line);$artist=$s['artist_id'];$quantity=(int)$line['quantity'];
            $physical+=$s['artist_quote']['physical_floor_cents']*$quantity;$royalty+=$s['artist_quote']['artist_cents']*$quantity;
            if($policy!==null&&$policy!==$s['freight_policy'])throw new InvalidArgumentException('freight_context_changed');$policy=$s['freight_policy'];
            $large=$large||$s['route']==='gran-formato';$fee=$s['artist_fee'];
        }
        if(!$artist)return null;if($other)throw new InvalidArgumentException('one_artist_per_order');
        if(!is_user_logged_in()||GE_WTP_Portal::is_staff_preview())throw new InvalidArgumentException('authentication_required');
        return array('artist_id'=>$artist,'physical_cents'=>$physical,'artist_cents'=>$royalty,
            'freight_cents'=>$policy['frames_cents']+($large?$policy['large_format_cents']:0),'fee'=>$fee);
    }
    public static function check_cart() {
        if(!function_exists('WC')||!WC()->cart)return;
        try{self::cart_context(WC()->cart->get_cart());}catch(InvalidArgumentException $e){wc_add_notice('Las obras de cada artista se compran en un pedido separado. Ingresá a tu cuenta y actualizá la selección antes de pagar.','error');}
    }
    public static function freeze_order($order,$request=null) {
        if(!function_exists('WC')||!WC()->cart)return;$context=self::cart_context(WC()->cart->get_cart());if(!$context)return;
        if($context['artist_cents']===0)return; // A free artwork uses Graphex's existing first-party gateways.
        if(!$order instanceof WC_Order||!$order->get_id()||$order->get_currency()!=='ARS'||(int)$order->get_customer_id()!==get_current_user_id())throw new InvalidArgumentException('invalid_marketplace_order');
        if($order->get_total_tax()>0||$order->get_discount_total()>0)throw new InvalidArgumentException('artist_delivery_review_required');
        $context['freight_cents']+=GE_WTP_Deco_Marketplace_Checkout::cents($order->get_shipping_total());
        $previous=$order->get_meta(GE_WTP_Deco_Marketplace_Checkout::SALE,true);
        $split=GE_Deco_Marketplace_Settlement::quote($context['physical_cents'],$context['artist_cents'],$context['freight_cents'],$context['fee'],time(),GE_WTP_Deco_Marketplace_Checkout::cents($order->get_total()));
        $split['valid_until']=$previous['settlement']['valid_until']??min($split['valid_until'],time()+600);
        $sale=array('order_id'=>$order->get_id(),'buyer_id'=>(int)$order->get_customer_id(),'artist_id'=>$context['artist_id'],
            'reference'=>$previous['reference']??bin2hex(random_bytes(32)),'application_id'=>GE_WTP_Deco_Marketplace::config()['client_id'],
            'mode'=>GE_WTP_Deco_Marketplace::config()['mode'],'settlement'=>$split);
        if($previous&&$previous!==$sale)throw new InvalidArgumentException('marketplace_order_changed');
        $order->update_meta_data(GE_WTP_Deco_Marketplace_Checkout::SALE,$sale);
        $order->update_meta_data('_ge_deco_artist_id',$context['artist_id']);
        if(!$previous)$order->update_meta_data('_ge_deco_marketplace_settlement_verified','no');$order->save();
    }
}
