<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/class-ge-deco-artist-pricing.php';
require_once __DIR__.'/class-ge-deco-marketplace-settlement.php';

/** Independent Graphex marketplace. Account onboarding does not authorize sales. */
final class GE_WTP_Deco_Marketplace {
    const NAME = 'graphex';
    const META = '_ge_deco_mp_account_v1';
    const PRICING = '_ge_deco_artist_pricing_v1';

    public static function init() {
        add_action('admin_post_ge_deco_mp_connect', array(__CLASS__, 'connect'));
        add_action('admin_post_ge_deco_mp_callback', array(__CLASS__, 'callback'));
        add_action('ge_deco_mp_clear_state', array(__CLASS__, 'clear_state'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
    }
    private static function setting($key) {
        $name = 'GE_DECO_MP_' . $key;
        return defined($name) ? constant($name) : getenv($name);
    }
    public static function config() {
        return array('name'=>self::NAME, 'client_id'=>(string)self::setting('CLIENT_ID'),
            'client_secret'=>(string)self::setting('CLIENT_SECRET'), 'mode'=>(string)self::setting('MODE'),
            'platform_id'=>(string)self::setting('PLATFORM_COLLECTOR_ID'), 'redirect_uri'=>self::callback_url());
    }
    public static function callback_url() { return admin_url('admin-post.php?action=ge_deco_mp_callback'); }
    public static function enabled() {
        $c=self::config();$scheme=parse_url($c['redirect_uri'],PHP_URL_SCHEME);$host=parse_url($c['redirect_uri'],PHP_URL_HOST);
        $local=$c['mode']==='test' && wp_get_environment_type()==='local' && in_array($host,array('127.0.0.1','localhost'),true);
        return GE_WTP_Deco_Creators::enabled() && self::setting('CONNECT_ENABLED')===true
            && in_array($c['mode'],array('test','live'),true) && preg_match('/^[1-9][0-9]{2,30}$/D',$c['client_id'])
            && preg_match('/^[1-9][0-9]{2,30}$/D',$c['platform_id']) && $c['client_secret']!==''
            && ($scheme==='https'||$local) && function_exists('openssl_encrypt');
    }
    public static function routes() {
        if(!GE_WTP_Deco_Creators::enabled())return;
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-marketplace',array(
            'methods'=>'GET','permission_callback'=>array('GE_WTP_Deco_Creators','permission'),
            'callback'=>static function(){return self::status(GE_WTP_Deco_Creators::require_owner());}));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-marketplace/disconnect',array(
            'methods'=>'POST','permission_callback'=>array('GE_WTP_Deco_Creators','permission'),
            'callback'=>static function(){try{self::disconnect(GE_WTP_Deco_Creators::require_owner());return array('disconnected'=>true);}
                catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'La cuenta está terminando otra operación. Volvé a intentarlo.',array('status'=>409));}}));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-marketplace/orders',array('methods'=>'GET',
            'permission_callback'=>array('GE_WTP_Deco_Creators','permission'),
            'callback'=>static function(){return self::orders(GE_WTP_Deco_Creators::require_owner());}));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-marketplace/pricing',array(
            'methods'=>'POST','permission_callback'=>array('GE_WTP_Deco_Creators','permission'),
            'callback'=>static function($r){try{return self::save_pricing(GE_WTP_Deco_Creators::require_owner(),$r->get_json_params());}
                catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'Revisá los valores o actualizá la página.',array('status'=>409));}}));
    }
    private static function account_lock($user) {
        $path=GE_WTP_Deco_Gallery::root().'/.mp-account-'.absint($user).'.lock';$handle=fopen($path,'c');
        if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('marketplace_busy');
        return $handle;
    }
    private static function unlock($lock) { if($lock){flock($lock,LOCK_UN);fclose($lock);} }
    private static function aad($user) {
        $c=self::config();return 'graphex.ar:'.$user.':'.$c['client_id'].':'.$c['mode'];
    }
    public static function seal($user,$value) {
        $iv=random_bytes(12);$tag='';$key=hash('sha256',wp_salt('auth').':ge-deco-mp-v1',true);
        $cipher=openssl_encrypt(wp_json_encode($value),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,self::aad($user));
        if($cipher===false)throw new InvalidArgumentException('credential_storage_unavailable');
        return base64_encode($iv.$tag.$cipher);
    }
    public static function unseal($user,$value) {
        $bytes=base64_decode((string)$value,true);if($bytes===false||strlen($bytes)<29)return array();
        $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',hash('sha256',wp_salt('auth').':ge-deco-mp-v1',true),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),self::aad($user));
        $data=$plain?json_decode($plain,true):null;return is_array($data)?$data:array();
    }
    public static function status($user) {
        $account=self::unseal($user,get_user_meta($user,self::META,true));$connected=!empty($account['refresh_token']);
        $fee_ready=false;$fee=get_user_meta($user,'_ge_deco_mp_fee_policy_v1',true);$config=self::config();
        if($connected&&is_array($fee)&&($fee['collector_id']??'')===($account['collector_id']??'')&&($fee['application_id']??'')===$config['client_id']&&($fee['mode']??'')===$config['mode']){
            try{GE_Deco_Marketplace_Settlement::quote(1,1,0,$fee,time());$fee_ready=true;}catch(InvalidArgumentException $e){}}
        return array('marketplace'=>self::NAME,'configured'=>(bool)self::enabled(),'connected'=>$connected,
            'account_id'=>$connected?(string)$account['collector_id']:'','mode'=>self::config()['mode']==='live'?'live':'test',
            'expires_at'=>$connected?(int)$account['expires_at']:null,'needs_reconnect'=>$connected&&$account['expires_at']<=time(),
            'sales_enabled'=>class_exists('GE_WTP_Deco_Marketplace_Checkout')&&GE_WTP_Deco_Marketplace_Checkout::enabled()&&$fee_ready,
            'fee_review_ready'=>$fee_ready,'settlement_policy'=>'graphex_absorbs_processing_preserving_floor','pricing'=>self::pricing($user),
            'frame_models'=>array_map(static function($m){return array('id'=>$m['id'],'name'=>$m['name']);},GE_WTP_Deco_Gallery::catalog()['models']??array()));
    }
    public static function pricing($user) {
        $saved=get_user_meta($user,self::PRICING,true);return is_array($saved)?$saved:array('revision'=>0,'default'=>array('mode'=>'unset'),'models'=>array());
    }
    public static function orders($user) {
        if($user!==GE_WTP_Deco_Creators::require_owner())throw new InvalidArgumentException('authentication_required');
        $rows=array();
        foreach(wc_get_orders(array('limit'=>20,'orderby'=>'date','order'=>'DESC','meta_query'=>array(array('key'=>'_ge_deco_artist_id','value'=>$user))))as $order){
            $sale=$order->get_meta('_ge_deco_marketplace_sale_v1',true);
            if(!is_array($sale)||($sale['artist_id']??0)!==$user||($sale['order_id']??0)!==$order->get_id())continue;
            $payment=$order->get_meta('_ge_deco_marketplace_payment_v1',true);$items=array();
            foreach($order->get_items()as $item){$snapshot=$item->get_meta('_ge_deco_snapshot',true);if(($snapshot['artist_id']??0)!==$user)continue;
                $items[]=array('title'=>$item->get_name(),'configuration'=>$snapshot['configuration'],'quantity'=>(int)$item->get_quantity());}
            $rows[]=array('reference'=>(string)$order->get_order_number(),'created_at'=>$order->get_date_created()?$order->get_date_created()->date('c'):null,
                'currency'=>'ARS','artist_cents'=>(int)$sale['settlement']['artist_cents'],
                'state'=>$payment['state']??'pending','settlement_verified'=>$order->get_meta('_ge_deco_marketplace_settlement_verified',true)==='yes','items'=>$items);
        }
        return array('orders'=>$rows,'limit'=>20);
    }
    public static function save_pricing($user,$data) {
        if($user!==GE_WTP_Deco_Creators::require_owner())throw new InvalidArgumentException('authentication_required');$lock=self::account_lock($user);
        try{$old=self::pricing($user);if(($data['revision']??null)!==$old['revision'])throw new InvalidArgumentException('artist_price_changed');
            $models=array_column(GE_WTP_Deco_Gallery::catalog()['models']??array(),'id');$price=GE_Deco_Artist_Pricing::profile($data,$models);$price['revision']=$old['revision']+1;
            update_user_meta($user,self::PRICING,$price);return $price;
        }finally{self::unlock($lock);}
    }
    public static function begin($user) {
        if(!self::enabled()||$user!==GE_WTP_Deco_Creators::require_owner())throw new InvalidArgumentException('marketplace_unavailable');
        $lock=self::account_lock($user);
        try{
            $previous=get_user_meta($user,'_ge_deco_mp_pending_state',true);
            if($previous)delete_transient('ge_deco_mp_state_'.$previous);
            $state=bin2hex(random_bytes(32));$hash=hash('sha256',$state);$verifier=bin2hex(random_bytes(32));$c=self::config();
            set_transient('ge_deco_mp_state_'.$hash,array('user'=>$user,'session'=>hash('sha256',wp_get_session_token()),
                'expires'=>time()+600,'verifier'=>$verifier,'app'=>$c['client_id'],'mode'=>$c['mode']),600);
            update_user_meta($user,'_ge_deco_mp_pending_state',$hash);
            return add_query_arg(array('client_id'=>$c['client_id'],'response_type'=>'code','platform_id'=>'mp',
                'redirect_uri'=>$c['redirect_uri'],'state'=>$state,'code_challenge_method'=>'S256',
                'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')),
                'https://auth.mercadopago.com.ar/authorization');
        }finally{self::unlock($lock);}
    }
    public static function clear_state($hash) { delete_option('ge_deco_mp_used_'.$hash); }
    public static function consume_state($state) {
        $user=GE_WTP_Deco_Creators::require_owner();
        if(!is_string($state)||!preg_match('/^[a-f0-9]{64}$/D',$state))throw new InvalidArgumentException('oauth_state_invalid');
        $hash=hash('sha256',$state);$saved=get_transient('ge_deco_mp_state_'.$hash);$c=self::config();
        if(!$saved||$saved['user']!==$user||$saved['expires']<time()||$saved['app']!==$c['client_id']||$saved['mode']!==$c['mode']
            ||get_user_meta($user,'_ge_deco_mp_pending_state',true)!==$hash||!hash_equals($saved['session'],hash('sha256',wp_get_session_token())))throw new InvalidArgumentException('oauth_state_invalid');
        if(!add_option('ge_deco_mp_used_'.$hash,time(),'','no'))throw new InvalidArgumentException('oauth_state_used');
        delete_transient('ge_deco_mp_state_'.$hash);wp_schedule_single_event(time()+900,'ge_deco_mp_clear_state',array($hash));
        return $saved;
    }
    private static function request($method,$path,$body=null,$token='') {
        $allowed=array('/oauth/token','/users/me');if(!in_array($path,$allowed,true))throw new InvalidArgumentException('invalid_mp_endpoint');
        $headers=array('Accept'=>'application/json');if($token!=='')$headers['Authorization']='Bearer '.$token;
        $args=array('method'=>$method,'timeout'=>20,'redirection'=>0,'limit_response_size'=>32768,'headers'=>$headers);
        if($body!==null)$args['body']=$body;
        $response=wp_remote_request('https://api.mercadopago.com'.$path,$args);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new InvalidArgumentException('mercadopago_unavailable');
        $data=json_decode(wp_remote_retrieve_body($response),true);if(!is_array($data))throw new InvalidArgumentException('invalid_mp_response');return $data;
    }
    private static function validate_tokens($data,$previous=null) {
        $c=self::config();$expected_live=$c['mode']==='live';
        if(empty($data['access_token'])||!is_string($data['access_token'])||empty($data['refresh_token'])||!is_string($data['refresh_token'])
            ||!is_string($data['token_type']??null)||strtolower($data['token_type'])!=='bearer'||!is_string($data['scope']??null)||!is_int($data['expires_in']??null)||$data['expires_in']<60||$data['expires_in']>366*DAY_IN_SECONDS
            ||($data['live_mode']??null)!==$expected_live||!preg_match('/^[1-9][0-9]{2,30}$/D',(string)($data['user_id']??'')))throw new InvalidArgumentException('invalid_mp_tokens');
        $scopes=preg_split('/\s+/',trim($data['scope']??''));foreach(array('offline_access','write') as $scope)if(!in_array($scope,$scopes,true))throw new InvalidArgumentException('mp_scope_missing');
        $id=(string)$data['user_id'];if($id===$c['platform_id']||($previous!==null&&$id!==(string)$previous))throw new InvalidArgumentException('mp_account_mismatch');
        $me=self::request('GET','/users/me',null,$data['access_token']);
        if((string)($me['id']??'')!==$id||($me['site_id']??'')!=='MLA'||($me['status']['site_status']??'')!=='active')throw new InvalidArgumentException('mp_account_not_eligible');
        return array('access_token'=>$data['access_token'],'refresh_token'=>$data['refresh_token'],'collector_id'=>$id,
            'expires_at'=>time()+$data['expires_in'],'connected_at'=>time(),'live_mode'=>$expected_live,'application_id'=>$c['client_id']);
    }
    public static function exchange($user,$code,$state) {
        if(!self::enabled()||!is_string($code)||$code===''||strlen($code)>4096)throw new InvalidArgumentException('invalid_oauth_code');
        $lock=self::account_lock($user);
        try{
            $saved=self::consume_state($state);if($saved['user']!==$user)throw new InvalidArgumentException('oauth_state_invalid');$c=self::config();
            $data=self::request('POST','/oauth/token',array('client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],
                'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$c['redirect_uri'],'state'=>$state,'code_verifier'=>$saved['verifier'],'test_token'=>$c['mode']==='test'?'true':'false'));
            $account=self::validate_tokens($data);update_user_meta($user,self::META,self::seal($user,$account));
            delete_user_meta($user,'_ge_deco_mp_pending_state');return self::status($user);
        }finally{self::unlock($lock);}
    }
    public static function access_token($user) {
        if(!self::enabled())throw new InvalidArgumentException('marketplace_unavailable');$lock=self::account_lock($user);
        try{
            $before=get_user_meta($user,self::META,true);$old=self::unseal($user,$before);
            if(empty($old['refresh_token']))throw new InvalidArgumentException('mp_reconnect_required');
            if($old['expires_at']>time()+300)return $old['access_token'];$c=self::config();
            $data=self::request('POST','/oauth/token',array('client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],
                'grant_type'=>'refresh_token','refresh_token'=>$old['refresh_token']));
            $account=self::validate_tokens($data,$old['collector_id']);
            if(get_user_meta($user,self::META,true)!==$before)throw new InvalidArgumentException('mp_reconnect_required');
            $account['connected_at']=$old['connected_at'];update_user_meta($user,self::META,self::seal($user,$account));return $account['access_token'];
        }finally{self::unlock($lock);}
    }
    public static function disconnect($user) {
        if($user!==GE_WTP_Deco_Creators::require_owner())throw new InvalidArgumentException('authentication_required');$lock=self::account_lock($user);
        try{delete_user_meta($user,self::META);$state=get_user_meta($user,'_ge_deco_mp_pending_state',true);if($state)delete_transient('ge_deco_mp_state_'.$state);delete_user_meta($user,'_ge_deco_mp_pending_state');}
        finally{self::unlock($lock);}
        // Local unlink only. Account owner may revoke app permissions in Mercado Pago.
    }
    public static function connect() {
        try{
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new InvalidArgumentException('method_not_allowed');
            $user=GE_WTP_Deco_Creators::require_owner();check_admin_referer('ge_deco_mp_connect');$url=self::begin($user);
            nocache_headers();header('Referrer-Policy: no-referrer');wp_redirect($url,303,'Graphex Marketplace');exit;
        }catch(InvalidArgumentException $e){wp_die('No pudimos iniciar la conexión. Ingresá con tu cuenta o volvé a intentarlo.','',array('response'=>403));}
    }
    public static function callback() {
        $notice='reconnect';
        try{
            $user=GE_WTP_Deco_Creators::require_owner();$state=wp_unslash($_GET['state']??'');
            if(isset($_GET['error'])){self::consume_state($state);$notice='cancelled';}
            else{self::exchange($user,wp_unslash($_GET['code']??''),$state);$notice='connected';}
        }catch(InvalidArgumentException $e){/* No tokens, remote errors or authorization codes in notices/logs. */}
        nocache_headers();header('Referrer-Policy: no-referrer');wp_safe_redirect(add_query_arg('mp',$notice,GE_WTP_Portal::portal_url('mi-galeria')));exit;
    }
}
