<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-ge-deco-selection-contract.php';

/** Additive Woo adapter. Catalog activation and approved server manifests are explicit. */
final class GE_WTP_Deco_Prints {
    // Weekly observation plus one day to review/retry a failed weekly run.
    const PRICE_MAX_AGE = 691200;
    public static function init() {
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate'), PHP_INT_MAX, 6);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_data'), PHP_INT_MAX, 4);
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), PHP_INT_MAX, 2);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item'), 30, 4);
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'freight'), 30);
    }
    public static function manifest($product_id) {
        $value = get_post_meta($product_id, '_ge_deco_print', true);
        return is_array($value) ? $value : array();
    }
    public static function purchasable($value, $product) {
        $manifest = self::manifest($product->get_id());
        if (!$manifest) { return $value; }
        if (!$value) { return false; }
        foreach (array_keys($manifest['options'] ?? array()) as $key) {
            try { self::resolve($product->get_id(), $key, $manifest['revision'] ?? ''); return true; }
            catch (InvalidArgumentException $error) { continue; }
        }
        return false;
    }
    public static function resolve($product_id, $key, $revision) {
        if (!defined('GE_DECO_PRINTS_ENABLED') || true !== GE_DECO_PRINTS_ENABLED) { throw new InvalidArgumentException('catalog_not_released'); }
        if(get_woocommerce_currency()!=='ARS')throw new InvalidArgumentException('currency_mismatch');
        $manifest = self::manifest($product_id);
        if(!empty($manifest['owner_id']) && (int)$manifest['owner_id']!==get_current_user_id())throw new InvalidArgumentException('artwork_owner_mismatch');
        $config = GE_WTP_Storefront::config($product_id);
        $artist_context=null;
        if(!empty($manifest['artist_id'])){$source=GE_WTP_Deco_Artist_Commerce::source($manifest['private_asset_ref']);$artist_context=GE_WTP_Deco_Artist_Commerce::context($source['row']);
            if(($manifest['artist_context']??null)!==$artist_context)throw new InvalidArgumentException('artist_price_changed');}
        $issuer_id=$config['tax_context']['issuer_profile_id']??'';
        if($issuer_id){
            $issuer=GE_WTP_Billing_Issuers::get($issuer_id);
            if(!$issuer||(int)$issuer['revision']!==(int)($config['tax_context']['issuer_revision']??0)||is_wp_error(GE_WTP_Billing_Issuers::validate_current(GE_WTP_Billing_Issuers::capture($issuer))))throw new InvalidArgumentException('issuer_changed');
        }
        $snapshot = GE_Deco_Selection_Contract::resolve($manifest, $config, $key, $revision);
        $entry = $manifest['options'][$key];
        $observed=class_exists('GE_WTP_Deco_Observations')?GE_WTP_Deco_Observations::verify($entry['catalog_option_id']??'',$entry):array($entry['frame_observed_at']??null,$entry['print_observed_at']??null);
        if (!preg_match('/^opcion-[0-9]+$/D', $entry['catalog_option_id'] ?? '') || !in_array($entry['orientation'] ?? '', array('portrait', 'landscape'), true) || ($manifest['artwork_id'] ?? '') !== ($manifest['private_asset_ref'] ?? null)) { throw new InvalidArgumentException('invalid_catalog_reference'); }
        $now = time();
        foreach ($observed as $date) {
            if (!is_int($date) || $date > $now + 300 || $date < $now - self::PRICE_MAX_AGE) { throw new InvalidArgumentException('stale_price_snapshot'); }
        }
        $deadline=$entry['valid_until']??null;
        if(is_int($deadline)&&in_array($deadline,array(min($entry['frame_observed_at'],$entry['print_observed_at'])+86400,min($entry['frame_observed_at'],$entry['print_observed_at'])+self::PRICE_MAX_AGE),true))$deadline=min($observed)+self::PRICE_MAX_AGE;
        if (($entry['available'] ?? false) !== true || !is_int($deadline) || $deadline <= $now) { throw new InvalidArgumentException('option_unavailable'); }
        if (empty($manifest['private_asset_ref']) || empty($manifest['analyzer_ref']) || (empty($manifest['frame_asset_use_ref']) && empty($manifest['frame_image_permission_ref']))) { throw new InvalidArgumentException('missing_release_evidence'); }
        self::verify_asset($manifest);
        if (!in_array($entry['paper_finish'] ?? '', array('mate', 'brillo', 'catalogo-fotografico'), true)) { throw new InvalidArgumentException('paper_finish_required'); }
        $large_format = min($entry['print_mm']) > 300 || max($entry['print_mm']) > 450;
        if ($large_format !== ('catalogo-fotografico' === $entry['paper_finish'])) { throw new InvalidArgumentException('paper_finish_required'); }
        if (($config['mode'] ?? '') !== 'fixed' || ($config['options'][$key]['price'] ?? null) !== ($entry['approved_base_price'] ?? null)) { throw new InvalidArgumentException('price_changed'); }
        $freight = $manifest['freight_policy'] ?? array();
        foreach (array('frames_cents', 'large_format_cents') as $field) {
            if (!isset($freight[$field]) || !is_int($freight[$field]) || $freight[$field] < 0) { throw new InvalidArgumentException('freight_context_required'); }
        }
        if (!isset($freight['taxable']) || !is_bool($freight['taxable']) || !isset($freight['tax_class']) || !is_string($freight['tax_class']) || empty($freight['approval_ref'])) { throw new InvalidArgumentException('freight_context_required'); }
        $snapshot['private_asset_ref'] = $manifest['private_asset_ref'];
        $snapshot['production_pixels'] = $manifest['production_pixels'];
        $snapshot['catalog_option_id'] = $entry['catalog_option_id'];
        $snapshot['orientation'] = $entry['orientation'];
        $snapshot['owner_id'] = $manifest['owner_id'] ?? 0;
        $snapshot['currency'] = 'ARS';
        $snapshot['analyzer_ref'] = $manifest['analyzer_ref'];
        $snapshot['price_base'] = $config['options'][$key]['price'];
        if(!empty($entry['retail_pricing']))$snapshot['retail_pricing']=$entry['retail_pricing'];
        $snapshot['tax_context'] = $config['tax_context'];
        $snapshot['freight_policy'] = $freight;
        $snapshot['route'] = min($entry['print_mm']) <= 300 && max($entry['print_mm']) <= 450 ? 'digital' : 'gran-formato';
        if($artist_context){$snapshot['artist_id']=$artist_context['artist_id'];$snapshot['artist_fee']=$artist_context['fee'];$snapshot['artist_quote']=$entry['artist_quote'];}
        $snapshot['production_approved'] = false;
        return $snapshot;
    }
    public static function verify_asset($manifest) {
        if(0===strpos($manifest['private_asset_ref']??'','autor-')){$source=GE_WTP_Deco_Artist_Commerce::source($manifest['private_asset_ref'],true);
            if($source['sha256']!==$manifest['production_sha256']||$source['pixels']!==$manifest['production_pixels'])throw new InvalidArgumentException('original_checksum_mismatch');return;}
        if(0===strpos($manifest['private_asset_ref']??'','tu-imagen-')){
            $source=GE_WTP_Deco_Uploads::source($manifest['private_asset_ref']);
            if($source['sha256']!==$manifest['production_sha256']||$source['pixels']!==$manifest['production_pixels'])throw new InvalidArgumentException('original_checksum_mismatch');
            return;
        }
        // Provisioned private museum cache, never a path submitted by the browser.
        if (!defined('GE_DECO_PRIVATE_ROOT') || !is_string(GE_DECO_PRIVATE_ROOT)) { throw new InvalidArgumentException('private_storage_required'); }
        $root = realpath(GE_DECO_PRIVATE_ROOT); $public = realpath(ABSPATH);
        if (!$root || !$public || $root === $public || 0 === strpos($root . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) { throw new InvalidArgumentException('private_storage_required'); }
        $ref = $manifest['private_asset_ref'];
        if (!is_string($ref) || !preg_match('/^(nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3})$/D', $ref)) { throw new InvalidArgumentException('invalid_private_asset'); }
        $folder = realpath($root . DIRECTORY_SEPARATOR . $ref);
        if (!$folder || 0 !== strpos($folder . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) { throw new InvalidArgumentException('invalid_private_asset'); }
        $read = static function($name) use ($folder) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path)) { throw new InvalidArgumentException('invalid_private_asset'); }
            $value = json_decode(file_get_contents($path), true);
            if (!is_array($value)) { throw new InvalidArgumentException('invalid_private_asset'); }
            return $value;
        };
        $record = $read('catalog-record.json'); $analysis = $read('analysis.json');
        $master = $folder . DIRECTORY_SEPARATOR . 'print-master.jpg';
        $sha = $manifest['production_sha256'];
        $rights = $record['rights_evidence']['openAccess'] ?? array();
        $licenses = $record['rights_evidence']['imageInfo']['license'] ?? array();
        foreach ((array) $licenses as $license) { if (false !== strpos((string) $license, '/licenses/')) { throw new InvalidArgumentException('unapproved_artwork'); } }
        if (($record['work']['id'] ?? '') !== $ref || ($record['source_sha256'] ?? '') !== $sha || ($analysis['checksum_sha256'] ?? '') !== $sha || !is_file($master) || is_link($master) || hash_file('sha256', $master) !== $sha) { throw new InvalidArgumentException('original_checksum_mismatch'); }
        if (($record['work']['pixels'] ?? null) !== $manifest['production_pixels'] || ($analysis['pixel_dimensions'] ?? null) !== $manifest['production_pixels'] || !in_array($analysis['status'] ?? '', array('analyzed', 'warning'), true) || !empty($analysis['blockers']) || ($analysis['page_count'] ?? 0) !== 1) { throw new InvalidArgumentException('invalid_private_analysis'); }
        $nga=($record['source']['id']??'')==='nga'&&($rights['openAccess']??false)===true&&($rights['imageUuid']??'')===($record['image_id']??null);
        $met=($record['source']['id']??'')==='met'&&($record['rights_evidence']['isPublicDomain']??false)===true;
        $licensed=0===strpos($ref,'bauhaus-')&&GE_WTP_Deco_Gallery::licensed_sales($record);
        if(!$nga&&!$met&&!$licensed)throw new InvalidArgumentException('unapproved_artwork');
    }
    private static function requested($data) {
        return array($data['ge_configuration_key'] ?? '', $data['ge_deco_revision'] ?? '');
    }
    public static function validate($valid, $product_id, $quantity, $variation_id = 0, $variations = array(), $data = array()) {
        if (!self::manifest($product_id)) { return $valid; }
        if (!$valid) { return false; }
        try {
            list($key, $revision) = self::requested($data);
            if (!is_numeric($quantity) || (int) $quantity != $quantity || $quantity < 1 || $quantity > 200 || $variation_id) { throw new InvalidArgumentException('invalid_quantity_or_variation'); }
            self::resolve($product_id, $key, $revision);
        } catch (InvalidArgumentException $error) {
            wc_add_notice('Este cuadro necesita actualizarse. Volvé a elegir su configuración.', 'error');
            return false;
        }
        return true;
    }
    public static function cart_data($data, $product_id, $variation_id = 0, $quantity = 1) {
        if (!self::manifest($product_id)) { return $data; }
        if (!is_numeric($quantity) || (int) $quantity != $quantity || $quantity < 1 || $quantity > 200 || $variation_id) { throw new InvalidArgumentException('invalid_quantity_or_variation'); }
        list($key, $revision) = self::requested($data);
        $snapshot = self::resolve($product_id, $key, $revision);
        // Replace any submitted projection or price with the server's current approved data.
        $data['ge_deco_snapshot'] = $snapshot;
        $data['ge_configuration'] = $snapshot['configuration'];
        $data['ge_base_price'] = $snapshot['price_base'];
        $data['ge_calculated_price'] = round($snapshot['price_base'] * $snapshot['tax_context']['multiplier'], 4);
        return $data;
    }
    public static function assert_cart_line($line) {
        $snapshot = $line['ge_deco_snapshot'] ?? array();
        $current = self::resolve($line['product_id'], $snapshot['option_key'] ?? '', $snapshot['manifest_revision'] ?? '');
        if ($snapshot !== $current || ($line['ge_configuration_key'] ?? '') !== $current['option_key'] || ($line['ge_base_price'] ?? null) !== $current['price_base'] || ($line['ge_calculated_price'] ?? null) !== round($current['price_base'] * $current['tax_context']['multiplier'], 4)) { throw new InvalidArgumentException('cart_snapshot_changed'); }
        if (!isset($line['quantity']) || !is_numeric($line['quantity']) || (int) $line['quantity'] != $line['quantity'] || $line['quantity'] < 1 || $line['quantity'] > 200) { throw new InvalidArgumentException('invalid_quantity'); }
        return $current;
    }
    public static function check_cart() {
        foreach (WC()->cart->get_cart() as $line) {
            if (!self::manifest($line['product_id']) && empty($line['ge_deco_snapshot'])) { continue; }
            try { self::assert_cart_line($line); }
            catch (InvalidArgumentException $error) { wc_add_notice('Hay un cuadro que necesita actualizarse antes de continuar.', 'error'); }
        }
    }
    public static function order_item($item, $cart_key, $values, $order) {
        if (!self::manifest($values['product_id']) && empty($values['ge_deco_snapshot'])) { return; }
        $snapshot = self::assert_cart_line($values);
        if(!empty($snapshot['tax_context']['issuer_profile_id'])){
            $id=$snapshot['tax_context']['issuer_profile_id'];$existing=$order->get_meta('_ge_billing_issuer_profile_id',true);
            if($existing&&$existing!=='unknown'&&$existing!==$id)throw new InvalidArgumentException('issuer_review_required');
            $profile=GE_WTP_Billing_Issuers::get($id);$order->update_meta_data(GE_WTP_Billing_Issuers::ORDER_META,GE_WTP_Billing_Issuers::capture($profile));$order->update_meta_data('_ge_billing_issuer_profile_id',$id);
        }
        $item->add_meta_data('_ge_deco_snapshot', $snapshot, true);
        if(class_exists('GE_WTP_Deco_Observations'))$item->add_meta_data('_ge_deco_price_observation',GE_WTP_Deco_Observations::evidence(),true);
        // Existing portal approval, payment and production guards remain authoritative.
    }
    public static function freight($cart) {
        $policy = null; $large = false;
        foreach ($cart->get_cart() as $line) {
            if (!self::manifest($line['product_id']) && empty($line['ge_deco_snapshot'])) { continue; }
            try { $snapshot = self::assert_cart_line($line); }
            catch (InvalidArgumentException $error) { return; }
            if (null !== $policy && $policy !== $snapshot['freight_policy']) { wc_add_notice('El traslado de los cuadros necesita revisión.', 'error'); return; }
            $policy = $snapshot['freight_policy'];
            $large = $large || 'gran-formato' === $snapshot['route'];
        }
        if (null === $policy) { return; }
        $amount = $policy['frames_cents'] + ($large ? $policy['large_format_cents'] : 0);
        $physical=0;$royalty=0;$line_cents=0;$artist_fee=null;
        foreach($cart->get_cart()as $line){$s=$line['ge_deco_snapshot']??array();if(empty($s['artist_id']))continue;
            $physical+=$s['artist_quote']['physical_floor_cents']*(int)$line['quantity'];$royalty+=$s['artist_quote']['artist_cents']*(int)$line['quantity'];
            $line_cents+=GE_WTP_Deco_Marketplace_Checkout::cents($line['line_total']);$artist_fee=$s['artist_fee'];}
        if($royalty>0){$shipping=GE_WTP_Deco_Marketplace_Checkout::cents($cart->get_shipping_total());
            // Mixed free/paid works use one seller checkout; cover processing once for the complete order.
            $total=GE_Deco_Marketplace_Settlement::quote($physical,$royalty,$amount+$shipping,$artist_fee,time());
            $amount=max($amount,$total['total_cents']-$line_cents-$shipping);}
        if ($amount) { $cart->add_fee('Preparación y traslado de cuadros', $amount / 100, $policy['taxable'], $policy['tax_class']); }
    }
}
