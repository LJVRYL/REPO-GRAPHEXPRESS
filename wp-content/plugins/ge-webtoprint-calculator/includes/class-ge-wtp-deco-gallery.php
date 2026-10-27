<?php
defined('ABSPATH') || exit;

/** Public previews and private preparation; Woo remains the only commercial cart. */
final class GE_WTP_Deco_Gallery {
    const NS = 'graphex-deco/v1';
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('template_redirect', array(__CLASS__, 'render'));
        add_filter('rest_post_dispatch',array(__CLASS__,'private_cache'),10,3);
        add_filter('woocommerce_cart_item_thumbnail',array(__CLASS__,'cart_thumbnail'),20,3);
        add_filter('woocommerce_store_api_cart_item_images',array(__CLASS__,'cart_images'),20,3);
    }
    private static function cart_preview($line) {
        $id=$line['ge_deco_snapshot']['artwork_id']??'';
        if(!$id)return '';
        try{
            if(preg_match('/^tu-imagen-([a-f0-9]{32})$/D',$id,$match)){
                GE_WTP_Deco_Uploads::artwork($match[1]); // Exact native owner; no public media attachment.
                return add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url(self::NS.'/uploads/'.$match[1].'/preview'));
            }
            $record=self::record($id);return $record?self::public_work($record)['image']:'';
        }catch(InvalidArgumentException $error){return '';}
    }
    public static function cart_thumbnail($html,$line,$key) {
        $url=self::cart_preview($line);return $url?'<img src="'.esc_url($url).'" alt="'.esc_attr('Tu cuadro Graphex').'" width="300" height="300" loading="lazy" style="object-fit:contain" />':$html;
    }
    public static function cart_images($images,$line,$key) {
        $url=self::cart_preview($line);if(!$url)return $images;
        return array((object)array('id'=>0,'src'=>$url,'thumbnail'=>$url,'srcset'=>'','sizes'=>'','name'=>'Tu cuadro Graphex','alt'=>'Tu cuadro Graphex'));
    }
    public static function private_cache($response,$server,$request) {
        if(0===strpos($request->get_route(),'/'.self::NS.'/')){$response->header('Cache-Control','private, no-store');$response->header('Vary','Cookie');}
        return $response;
    }
    public static function assets() { return GE_WTP_PLUGIN_DIR . 'assets/deco/'; }
    public static function registry() {
        static $cached=null;if(null!==$cached)return $cached;
        $file = self::assets() . 'galleries.json';
        $data = is_file($file) ? json_decode(file_get_contents($file), true) : array();
        $cached=is_array($data)?$data:array();return $cached;
    }
    public static function works() {
        // Registry is already immutable for this request; index it only once.
        static $cached=null;if(null!==$cached)return $cached;
        $works = array();
        foreach (self::registry()['works'] ?? array() as $work) { $works[$work['id']] = $work; }
        $cached=$works;return $cached;
    }
    public static function options() {
        return self::catalog()['options']??array();
    }
    public static function catalog() {
        static $cached=null;if(null!==$cached)return $cached;
        $file = self::assets() . 'catalog.js';
        if (!is_file($file)) { return array(); }
        $parts = explode('=', file_get_contents($file), 2);
        $data = json_decode(rtrim($parts[1] ?? '', ";\r\n"), true);
        $cached=is_array($data)?$data:array();return $cached;
    }
    public static function root() {
        if (!defined('GE_DECO_PRIVATE_ROOT') || !is_string(GE_DECO_PRIVATE_ROOT)) { throw new InvalidArgumentException('private_storage_required'); }
        $root = realpath(GE_DECO_PRIVATE_ROOT); $public = realpath(ABSPATH);
        if (!$root || !$public || $root === $public || 0 === strpos($root . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) { throw new InvalidArgumentException('private_storage_required'); }
        return $root;
    }
    public static function is_licensed_pack_id($id) {
        return is_string($id) && preg_match('/^(bauhaus-[0-9]{3}|trendy-[0-9]{4})$/D', $id) === 1;
    }
    public static function folder($id) {
        if (!is_string($id) || !preg_match('/^(nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3}|trendy-[0-9]{4})$/D', $id) || !isset(self::works()[$id])) { throw new InvalidArgumentException('unknown_artwork'); }
        return self::root() . DIRECTORY_SEPARATOR . $id;
    }
    public static function record($id, $verify = false) {
        $folder = self::folder($id); $path = $folder . '/catalog-record.json';
        if (!is_file($path) || is_link($path)) { return null; }
        $record = json_decode(file_get_contents($path), true); $work = self::works()[$id];
        if (!is_array($record) || ($record['work']['id'] ?? '') !== $id || ($record['image_id'] ?? null) !== ($work['imageId'] ?? null)) { throw new InvalidArgumentException('artwork_revision_changed'); }
        if ($verify) {
            $master = $folder . '/print-master.jpg';
            if (!is_file($master) || is_link($master) || hash_file('sha256', $master) !== ($record['source_sha256'] ?? '')) { throw new InvalidArgumentException('original_checksum_mismatch'); }
            $analysis = json_decode(file_get_contents($folder . '/analysis.json'), true);
            if (($analysis['checksum_sha256'] ?? '') !== $record['source_sha256'] || !empty($analysis['blockers']) || !in_array($analysis['status'] ?? '', array('analyzed', 'warning'), true) || ($analysis['page_count'] ?? 0) !== 1 || ($analysis['pixel_dimensions'] ?? null) !== ($record['work']['pixels'] ?? null)) { throw new InvalidArgumentException('analysis_required'); }
        }
        return $record;
    }
    public static function public_work($record) {
        $work = self::works()[$record['work']['id']];
        $work['pixels'] = $record['work']['pixels']; $work['verified'] = true; $work['status'] = 'ready';
        if (self::is_licensed_pack_id($work['id'])) { $work['sales_enabled'] = empty($work['catalog_hidden']) && ($work['sales_enabled']??false)===true && self::licensed_sales($record); }
        $work['image'] = rest_url(self::NS . '/artworks/' . $work['id'] . '/preview');
        return $work;
    }
    /** Withdrawal blocks new transactions while immutable order sources stay resolvable. */
    public static function assert_available($id) {
        if(!self::is_licensed_pack_id($id))return;
        $work=self::works()[$id]??null;
        if(!$work||!empty($work['catalog_hidden'])||($work['sales_enabled']??false)!==true)throw new InvalidArgumentException('artwork_sales_unavailable');
    }
    /** Licensed packs never inherit museum Open Access approval. */
    public static function licensed_sales($record) {
        $id=$record['work']['id']??'';
        if(!self::is_licensed_pack_id($id)||!isset(self::works()[$id]))return false;
        $rights=$record['rights_evidence']??array();$expected=$rights['evidence_sha256']??'';
        if(($record['source']['id']??'')!=='graphex-pack'||($rights['physical_print_sales']??false)!==true||!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||empty($rights['reviewed_at']))return false;
        if(($rights['image_sha256']??'')!==($record['image_id']??null)||($record['image_id']??null)!==(self::works()[$id]['imageId']??null))return false;
        $evidence=self::folder($id).'/license-evidence.txt';
        return is_file($evidence)&&!is_link($evidence)&&hash_file('sha256',$evidence)===$expected;
    }
    public static function routes() {
        $read = array('methods'=>'GET','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>'__return_true');
        $write = array('methods'=>'POST','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>array(__CLASS__,'write_permission'));
        register_rest_route(self::NS, '/session', $read);
        register_rest_route(self::NS, '/options', $read);
        register_rest_route(self::NS, '/artworks/prepared', $read);
        register_rest_route(self::NS, '/artworks/(?P<id>nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3}|trendy-[0-9]{4})', $read);
        register_rest_route(self::NS, '/artworks/(?P<id>nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3}|trendy-[0-9]{4})/preview', $read);
        register_rest_route(self::NS, '/artworks/(?P<id>nga-[0-9]+|obra-[1-8]|bauhaus-[0-9]{3}|trendy-[0-9]{4})/(?P<operation>prepare|selection)', $write);
        register_rest_route(self::NS, '/cart', $write);
    }
    public static function write_permission($request) {
        $origin = get_http_origin();
        if ($origin && untrailingslashit($origin) !== untrailingslashit(home_url())) { return new WP_Error('origin', 'Origen inválido.', array('status'=>403)); }
        if (!wp_verify_nonce($request->get_header('X-CSRF-Token'), 'ge_deco_public')) { return new WP_Error('csrf', 'Volvé a cargar la página.', array('status'=>403)); }
        return true;
    }
    public static function dispatch($request) {
        try {
            $route = $request->get_route();
            if(substr($route,-8)==='/options'){
                try{$policy=GE_WTP_Deco_Release::policy();$ids=array();$prices=array();foreach($policy['options'] as $id=>$option){if(($option['available']??false)===true&&GE_WTP_Deco_Observations::allowed($id,$policy)){$ids[]=$id;$prices[$id]=(int)round(GE_WTP_Deco_Payments::regular_cents($option['unit_base_cents'],$policy)*$policy['tax_context']['multiplier']);}}return array('option_ids'=>$ids,'commerce_available'=>count($ids)>0,'unit_prices_cents'=>$prices,'currency'=>'ARS','handling_cents'=>array('frames'=>$policy['freight_policy']['frames_cents'],'large_format'=>$policy['freight_policy']['large_format_cents']));}
                catch(InvalidArgumentException $error){return array('option_ids'=>array(),'commerce_available'=>false);}
            }
            if (substr($route, -8) === '/session') { return array('authenticated'=>is_user_logged_in(),'csrf'=>wp_create_nonce('ge_deco_public'),'commerce_enabled'=>defined('GE_DECO_PRINTS_ENABLED') && GE_DECO_PRINTS_ENABLED === true,'upload'=>is_user_logged_in()?array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(GE_WTP_VPS_Storage::AJAX_ACTION)):null); }
            if (substr($route, -18) === '/artworks/prepared') {
                $works = array();
                foreach (self::works() as $id=>$unused) { if ($record = self::record($id)) { $works[] = self::public_work($record); } }
                return array('works'=>$works);
            }
            if (substr($route, -5) === '/cart') { return self::cart($request->get_json_params()); }
            $id = $request['id']; $record = self::record($id);
            if (substr($route, -8) === '/preview') {
                if (!$record) { throw new InvalidArgumentException('analysis_required'); }
                $preview = self::folder($id) . '/preview.jpg';
                if (!is_file($preview) || is_link($preview)) { throw new InvalidArgumentException('analysis_required'); }
                nocache_headers(); header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); readfile($preview); exit;
            }
            if ($request['operation'] === 'prepare') { return self::prepare($id); }
            if ($request['operation'] === 'selection') { return self::selection($id, $request->get_json_params()); }
            if ($record) { return array('status'=>'ready','work'=>self::public_work($record)); }
            $job = self::folder($id) . '/job.json';
            return is_file($job) ? json_decode(file_get_contents($job), true) : array('status'=>'pending');
        } catch (InvalidArgumentException $error) {
            return new WP_Error($error->getMessage(), 'Esta configuración necesita revisarse. Volvé a intentarlo.', array('status'=>409));
        }
    }
    public static function prepare($id) {
        self::assert_available($id);
        if ($record = self::record($id, true)) { return array('status'=>'ready','work'=>self::public_work($record)); }
        if (0 !== strpos($id, 'nga-')) { throw new InvalidArgumentException('analysis_required'); }
        $work = self::works()[$id];
        if (($work['rightsEvidence']['openAccess'] ?? false) !== true) { throw new InvalidArgumentException('artwork_rights_required'); }
        $limit = 'ge_deco_rate_' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli', wp_salt());
        $count = (int) get_transient($limit); if ($count >= 6) { return new WP_Error('rate_limit','Esperá un momento.',array('status'=>429)); }
        set_transient($limit,$count+1,60);
        $queue = self::root() . '/.queue'; wp_mkdir_p($queue);
        $lock = fopen($queue . '/queue.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new InvalidArgumentException('busy'); }
        try {
            $target = $queue . '/' . $id . '.json';
            if (!is_file($target) && count(glob($queue . '/nga-*.json')) >= 20) { throw new InvalidArgumentException('busy'); }
            $folder = self::folder($id); wp_mkdir_p($folder);
            if (is_file($folder . '/catalog-record.json')) { throw new InvalidArgumentException('artwork_revision_changed'); }
            if (!is_file($target)) {
                file_put_contents($folder . '/job.json',wp_json_encode(array('status'=>'queued','stage'=>'Preparando tu obra')));
                $handle=fopen($target,'x'); if (!$handle) { throw new InvalidArgumentException('busy'); }
                fwrite($handle,wp_json_encode(array('id'=>$id,'queued_at'=>time())));fclose($handle);chmod($target,0600);
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
        return array('status'=>'queued','stage'=>'Preparando tu obra');
    }
    public static function selection($id, $data) {
        self::assert_available($id);
        $record = self::record($id,true); if (!$record) { throw new InvalidArgumentException('analysis_required'); }
        $option = null;
        foreach (self::options() as $candidate) {
            if ($candidate['group']===($data['group']??null) && $candidate['model']===($data['model']??null) && $candidate['finish']===($data['finish']??null) && implode('x',$candidate['size'])===($data['size']??null)) { $option=$candidate;break; }
        }
        if (!$option || !in_array($data['orientation']??'',array('portrait','landscape'),true) || !in_array($data['fit']??'',array('cover','contain'),true)) { throw new InvalidArgumentException('invalid_selection'); }
        $size = $data['orientation']==='portrait' ? $option['size'] : array_reverse($option['size']);
        GE_Deco_Print_Quality::enforce($record['work']['pixels'],array_map(static function($v){return (int)round($v*10);},$size),$data['fit']);
        $selection = array('selection_id'=>str_replace('-','',wp_generate_uuid4()),'work_id'=>$id,'source_sha256'=>$record['source_sha256'],'option_id'=>$option['id'],'size_cm'=>$option['size'],'orientation'=>$data['orientation'],'fit'=>$data['fit'],'production_approved'=>false);
        $path=self::folder($id).'/selection-'.$selection['selection_id'].'.json';$handle=fopen($path,'x');if(!$handle)throw new InvalidArgumentException('busy');fwrite($handle,wp_json_encode($selection));fclose($handle);chmod($path,0600);
        return array('selection_id'=>$selection['selection_id']);
    }
    public static function cart($data) {
        if (!defined('GE_DECO_PRINTS_ENABLED') || GE_DECO_PRINTS_ENABLED!==true) { throw new InvalidArgumentException('catalog_not_released'); }
        $lines=$data['items']??null; if(!is_array($lines)||!count($lines)||count($lines)>20)throw new InvalidArgumentException('invalid_batch');
        $resolved=array();$total_quantity=0;
        foreach($lines as $line){
            $id=$line['workId']??'';$selection_id=$line['serverSelectionId']??'';
            if(!is_string($id))throw new InvalidArgumentException('invalid_selection_reference');
            self::assert_available($id);
            if(strpos($id,'autor-')===0&&!GE_WTP_Deco_Marketplace_Checkout::enabled())throw new InvalidArgumentException('creator_sales_pending');
            if(!is_string($selection_id)||!preg_match('/^[a-f0-9]{32}$/D',$selection_id))throw new InvalidArgumentException('invalid_selection_reference');
            if(0===strpos($id,'autor-')){$source=GE_WTP_Deco_Artist_Commerce::source($id);$path=GE_WTP_Deco_Creators::folder($id).'/selection-'.$selection_id.'.json';
                if(!is_file($path)||is_link($path))throw new InvalidArgumentException('analysis_required');$selection=json_decode(file_get_contents($path),true);$record=array('source_sha256'=>$source['sha256']);
            }elseif(0===strpos($id,'tu-imagen-')){
                $source=GE_WTP_Deco_Uploads::source($id);$selection=get_post_meta($source['artwork_id'],'_ge_deco_selection_'.$selection_id,true);$record=array('source_sha256'=>$source['sha256']);
            }else{
                $path=self::folder($id).'/selection-'.$selection_id.'.json';if(!is_file($path)||is_link($path))throw new InvalidArgumentException('analysis_required');
                $selection=json_decode(file_get_contents($path),true);$record=self::record($id,true);
            }
            if(!$record||($selection['work_id']??'')!==$id||($selection['source_sha256']??'')!==$record['source_sha256'])throw new InvalidArgumentException('original_checksum_mismatch');
            $quantity=$line['quantity']??1;if(!is_int($quantity)||$quantity<1||$quantity>200)throw new InvalidArgumentException('invalid_quantity');
            $total_quantity+=$quantity;if($total_quantity>200)throw new InvalidArgumentException('batch_page_limit');
            $paper=$line['paper_finish']??'';
            if((get_option('ge_deco_commercial_release',array())['approved']??false)===true)GE_WTP_Deco_Release::ensure_product($id);
            $products=GE_WTP_Deco_Release::find_products($id,true);
            if(count($products)!==1)throw new InvalidArgumentException('catalog_not_released');
            $product_id=$products[0];$manifest=GE_WTP_Deco_Prints::manifest($product_id);$key=null;
            foreach($manifest['options']??array() as $candidate_key=>$candidate){
                if(($candidate['catalog_option_id']??'')===$selection['option_id']&&($candidate['orientation']??'')===$selection['orientation']&&($candidate['fit']??'')===$selection['fit']&&($candidate['paper_finish']??'')===$paper){$key=$candidate_key;break;}
            }
            if(null===$key)throw new InvalidArgumentException('invalid_selection');
            $snapshot=GE_WTP_Deco_Prints::resolve($product_id,$key,$manifest['revision']);
            if($snapshot['production_sha256']!==$selection['source_sha256'])throw new InvalidArgumentException('artwork_revision_changed');
            $resolved[]=array('product_id'=>$product_id,'quantity'=>$quantity,'data'=>array('ge_configuration_key'=>$key,'ge_deco_revision'=>$manifest['revision'],'ge_unique'=>wp_generate_uuid4()));
        }
        if(!WC()->cart)wc_load_cart();$artists=array();$ordinary=false;
        foreach(WC()->cart->get_cart()as $existing){$a=$existing['ge_deco_snapshot']['artist_id']??0;if($a)$artists[$a]=true;else $ordinary=true;}
        foreach($resolved as $line){$a=GE_WTP_Deco_Prints::manifest($line['product_id'])['artist_id']??0;if($a)$artists[$a]=true;else $ordinary=true;}
        if(count($artists)>1||($artists&&$ordinary))throw new InvalidArgumentException('one_artist_per_order');
        if($artists&&!is_user_logged_in())throw new InvalidArgumentException('authentication_required');$added=array();
        try{
            foreach($resolved as $line){$key=WC()->cart->add_to_cart($line['product_id'],$line['quantity'],0,array(),$line['data']);if(!$key)throw new InvalidArgumentException('cart_unavailable');$added[]=$key;}
            WC()->cart->calculate_totals();WC()->cart->set_session();
        }catch(Exception $error){foreach($added as $key){WC()->cart->remove_cart_item($key);}throw new InvalidArgumentException('cart_unavailable');}
        return array('cart_url'=>wc_get_cart_url(),'added'=>count($added));
    }
    public static function render() {
        if(function_exists('is_product_category')&&is_product_category('deco-prints')){
            $page=get_page_by_path('deco-prints');
            if($page&&$page->post_status==='publish'){wp_safe_redirect(get_permalink($page),302);exit;}
        }
        if(function_exists('is_product')&&is_product()){
            $owner=(int)get_post_meta(get_queried_object_id(),'_ge_deco_owner_id',true);
            if($owner&&$owner!==get_current_user_id()&&!current_user_can('manage_woocommerce')){status_header(404);exit;}
        }
        if(!is_page())return;
        $artists=get_post_meta(get_queried_object_id(),'_ge_prints_artists',true)==='yes';
        if(!$artists&&get_post_meta(get_queried_object_id(),'_ge_deco_gallery',true)!=='yes')return;
        $file=self::assets().($artists?'artists.html':'index.html');if(!is_file($file)){status_header(503);exit;}
        $asset_base=GE_WTP_PLUGIN_URL.'assets/deco/';
        $runtime=array('catalogRevision'=>hash_file('sha256',self::assets().'galleries.json'),'apiBase'=>rest_url(self::NS),'assetBase'=>$asset_base,'wpNonce'=>wp_create_nonce('wp_rest'),'commerce'=>defined('GE_DECO_PRINTS_ENABLED')&&GE_DECO_PRINTS_ENABLED===true,'retailTransferDiscount'=>((get_option('ge_deco_commercial_release',array())['checkout_pricing']['rule']??'')===GE_WTP_Deco_Payments::RETAIL_RULE),'accountUrl'=>wc_get_page_permalink('myaccount'),'creatorPortalUrl'=>GE_WTP_Deco_Creators::enabled()?GE_WTP_Portal::portal_url('mi-galeria'):'');
        $nonce=base64_encode(random_bytes(18));
        nocache_headers();header("Content-Security-Policy: default-src 'self'; img-src 'self' https://api.nga.gov https://images.metmuseum.org blob: data:; script-src 'self' 'nonce-".$nonce."'; style-src 'self' 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        $head='<script nonce="'.esc_attr($nonce).'">window.GRAPHEX_RUNTIME='.wp_json_encode($runtime,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script>';
        if($artists){$head.='<link rel="canonical" href="'.esc_url(get_permalink()).'"><meta property="og:type" content="website"><meta property="og:title" content="Tu arte, en más paredes · Graphex Prints"><meta property="og:description" content="Creá tu galería, compartí tus obras y definí su valor. Graphex se ocupa de imprimir y enmarcar."><meta property="og:url" content="'.esc_url(get_permalink()).'"><meta property="og:image" content="'.esc_url($asset_base.'assets/obra-1.jpg').'">';}
        $html=preg_replace_callback('/(src|href)="((?:assets\/)?[^"#:]+\.(?:js|css|svg|webp|jpg|png))"/',static function($match)use($asset_base){$path=self::assets().$match[2];$version=is_file($path)&&preg_match('/\.(?:js|css)$/',$path)?'?v='.filemtime($path):'';return $match[1].'="'.esc_url($asset_base.$match[2].$version).'"';},file_get_contents($file));
        if(!$artists&&function_exists('graphexpress_render_site_header')){
            ob_start();graphexpress_render_site_header(array('active'=>'prints'));$site_header=ob_get_clean();
            $html=preg_replace_callback('~<header class="prints-header">(.*?)</header>~s',static function($match)use($site_header){
                $actions=preg_replace('~<a class="brand".*?</a>~s','',$match[1],1);
                return $site_header.'<div class="prints-header prints-subnav">'.$actions.'</div>';
            },$html,1);
            $html=str_replace('<body>','<body class="gx-brand-active gx-prints-page">',$html);
            $theme_uri=get_stylesheet_directory_uri();$theme_dir=get_stylesheet_directory();
            foreach(array('/style.css','/assets/css/graphex-brand.css')as $relative){
                $head.='<link rel="stylesheet" href="'.esc_url($theme_uri.$relative.'?v='.filemtime($theme_dir.$relative)).'">';
            }
            $head.='<script defer src="'.esc_url($theme_uri.'/assets/js/home.js?v='.filemtime($theme_dir.'/assets/js/home.js')).'"></script>';
        }
        echo str_replace('<head>','<head>'.$head,$html);exit;
    }
}
