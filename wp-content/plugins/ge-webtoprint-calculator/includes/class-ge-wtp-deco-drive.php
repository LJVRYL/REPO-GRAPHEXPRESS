<?php
defined('ABSPATH') || exit;

/** Offline, per-file Google access. No account-wide Drive scope or plaintext tokens. */
final class GE_WTP_Deco_Drive {
    const SCOPE='https://www.googleapis.com/auth/drive.file';
    const META='_ge_deco_drive_credentials';
    public static function init() {
        add_action('admin_post_ge_deco_drive_connect',array(__CLASS__,'connect'));
        add_action('admin_post_ge_deco_drive_callback',array(__CLASS__,'callback'));
        add_action('ge_deco_drive_clear_state',array(__CLASS__,'clear_state'));
    }
    public static function config() {
        $native=class_exists('GE_WTP_Google_Auth')?GE_WTP_Google_Auth::settings():array();
        return array('client_id'=>defined('GE_DECO_DRIVE_CLIENT_ID')?GE_DECO_DRIVE_CLIENT_ID:($native['client_id']??''),'secret'=>defined('GE_DECO_DRIVE_CLIENT_SECRET')?GE_DECO_DRIVE_CLIENT_SECRET:getenv('GE_DECO_DRIVE_CLIENT_SECRET'),'api_key'=>$native['drive_api_key']??'','app_id'=>$native['drive_app_id']??'');
    }
    public static function enabled() {
        $c=self::config();return defined('GE_DECO_CREATOR_GALLERIES_ENABLED')&&GE_DECO_CREATOR_GALLERIES_ENABLED===true&&defined('GE_DECO_DRIVE_ENABLED')&&GE_DECO_DRIVE_ENABLED===true&&$c['client_id']&&$c['secret']&&$c['api_key']&&$c['app_id']&&function_exists('openssl_encrypt');
    }
    public static function callback_url(){return admin_url('admin-post.php?action=ge_deco_drive_callback');}
    public static function seal($user,$value) {
        $iv=random_bytes(12);$tag='';$key=hash('sha256',wp_salt('auth').':graphex-deco-drive-v1',true);
        $cipher=openssl_encrypt(wp_json_encode($value),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'owner:'.$user);
        if($cipher===false)throw new InvalidArgumentException('credential_storage_unavailable');
        return base64_encode($iv.$tag.$cipher);
    }
    public static function unseal($user,$value) {
        $bytes=base64_decode((string)$value,true);if($bytes===false||strlen($bytes)<29)return array();
        $clear=openssl_decrypt(substr($bytes,28),'aes-256-gcm',hash('sha256',wp_salt('auth').':graphex-deco-drive-v1',true),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),'owner:'.$user);
        $data=$clear?json_decode($clear,true):null;return is_array($data)?$data:array();
    }
    public static function connected($user){$data=self::unseal($user,get_user_meta($user,self::META,true));return !empty($data['refresh_token']);}
    private static function session(){return hash('sha256',wp_get_session_token());}
    public static function connect() {
        try{GE_WTP_Deco_Creators::require_owner();}catch(InvalidArgumentException $e){wp_die('Ingresá con tu cuenta de cliente.','',array('response'=>403));}check_admin_referer('ge_deco_drive_connect');
        if(!self::enabled())wp_die('Google Drive todavía no está disponible.','',array('response'=>503));
        $state=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));$hash=hash('sha256',$state);
        set_transient('ge_deco_drive_'.$hash,array('user'=>get_current_user_id(),'session'=>self::session(),'verifier'=>$verifier,'expires'=>time()+600),600);
        $c=self::config();$url=add_query_arg(array('client_id'=>$c['client_id'],'redirect_uri'=>self::callback_url(),'response_type'=>'code','scope'=>self::SCOPE,'state'=>$state,'access_type'=>'offline','prompt'=>'consent select_account','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'),'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url,302,'Graphex Drive');exit;
    }
    public static function clear_state($hash){delete_option('ge_deco_drive_used_'.$hash);}
    public static function consume_state($state) {
        if(!is_string($state)||!preg_match('/^[a-f0-9]{64}$/D',$state))throw new InvalidArgumentException('oauth_state_invalid');
        $hash=hash('sha256',$state);$saved=get_transient('ge_deco_drive_'.$hash);
        if(!$saved||$saved['user']!==get_current_user_id()||!hash_equals($saved['session'],self::session())||$saved['expires']<time())throw new InvalidArgumentException('oauth_state_invalid');
        if(!add_option('ge_deco_drive_used_'.$hash,time(),'','no'))throw new InvalidArgumentException('oauth_state_used');
        wp_schedule_single_event(time()+900,'ge_deco_drive_clear_state',array($hash));delete_transient('ge_deco_drive_'.$hash);return $saved;
    }
    private static function token_request($body) {
        $response=wp_remote_post('https://oauth2.googleapis.com/token',array('timeout'=>20,'redirection'=>0,'body'=>$body,'limit_response_size'=>32768));
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new InvalidArgumentException('drive_reconnect_required');
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data)||empty($data['access_token'])||strtolower($data['token_type']??'')!=='bearer'||!is_numeric($data['expires_in']??null)||$data['expires_in']<=0)throw new InvalidArgumentException('drive_reconnect_required');
        return $data;
    }
    public static function callback() {
        try{
            GE_WTP_Deco_Creators::require_owner();if(!self::enabled())throw new InvalidArgumentException('drive_not_configured');
            $saved=self::consume_state(wp_unslash($_GET['state']??''));if(isset($_GET['error']))throw new InvalidArgumentException('drive_consent_cancelled');
            $code=wp_unslash($_GET['code']??'');if(!is_string($code)||!$code||strlen($code)>4096)throw new InvalidArgumentException('oauth_code_invalid');
            $c=self::config();$data=self::token_request(array('client_id'=>$c['client_id'],'client_secret'=>$c['secret'],'code'=>$code,'code_verifier'=>$saved['verifier'],'grant_type'=>'authorization_code','redirect_uri'=>self::callback_url()));
            if(!in_array(self::SCOPE,explode(' ',$data['scope']??''),true))throw new InvalidArgumentException('drive_scope_missing');
            // A newly selected Google account must supply its own offline credential.
            $data['refresh_token']=$data['refresh_token']??'';
            if(!$data['refresh_token'])throw new InvalidArgumentException('drive_offline_access_required');
            $data['expires_at']=time()+(int)$data['expires_in'];update_user_meta(get_current_user_id(),self::META,self::seal(get_current_user_id(),$data));$notice='connected';
        }catch(InvalidArgumentException $e){$notice='reconnect';}
        wp_safe_redirect(add_query_arg('drive',$notice,GE_WTP_Portal::portal_url('mi-galeria')));exit;
    }
    public static function access_token($user) {
        if(!self::enabled())throw new InvalidArgumentException('drive_not_configured');
        $data=self::unseal($user,get_user_meta($user,self::META,true));if(empty($data['refresh_token']))throw new InvalidArgumentException('drive_reconnect_required');
        if(!empty($data['access_token'])&&($data['expires_at']??0)>time()+60)return $data['access_token'];
        // Single refresh per account; a revoked/disconnected account cannot be resurrected.
        $lock='ge_deco_drive_refresh_'.$user;$old_lock=get_option($lock);if($old_lock&&is_numeric($old_lock)&&(int)$old_lock<time()-120){global $wpdb;$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$lock,(string)$old_lock));wp_cache_delete($lock,'options');}if(!add_option($lock,time(),'','no'))throw new InvalidArgumentException('drive_busy');
        try{
            $before=get_user_meta($user,self::META,true);$c=self::config();$renew=self::token_request(array('client_id'=>$c['client_id'],'client_secret'=>$c['secret'],'refresh_token'=>$data['refresh_token'],'grant_type'=>'refresh_token'));
            if(get_user_meta($user,self::META,true)!==$before)throw new InvalidArgumentException('drive_reconnect_required');
            $data['access_token']=$renew['access_token'];$data['expires_at']=time()+(int)$renew['expires_in'];if(!empty($renew['refresh_token']))$data['refresh_token']=$renew['refresh_token'];update_user_meta($user,self::META,self::seal($user,$data));return $data['access_token'];
        }finally{delete_option($lock);}
    }
    public static function disconnect($user) {
        // Local disconnection first. Never discard order originals or other Google integrations.
        $data=self::unseal($user,get_user_meta($user,self::META,true));delete_user_meta($user,self::META);
        if(!empty($data['refresh_token']))wp_remote_post('https://oauth2.googleapis.com/revoke',array('timeout'=>10,'redirection'=>0,'body'=>array('token'=>$data['refresh_token'])));
    }
    public static function file_id($id) {
        if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$id))throw new InvalidArgumentException('invalid_drive_file');return $id;
    }
    public static function metadata($user,$id) {
        self::file_id($id);$url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,name,mimeType,size,version,md5Checksum,trashed,capabilities(canDownload),imageMediaMetadata(width,height,rotation),thumbnailLink';
        $response=wp_safe_remote_get($url,array('timeout'=>20,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.self::access_token($user)),'limit_response_size'=>32768));
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new InvalidArgumentException('drive_file_unavailable');
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if(($data['id']??'')!==$id||!empty($data['trashed'])||($data['capabilities']['canDownload']??false)!==true||!in_array($data['mimeType']??'',array('image/jpeg','image/png'),true)||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)($data['size']??''))||(int)$data['size']>64*MB_IN_BYTES||empty($data['version'])||!preg_match('/^[a-f0-9]{32}$/D',$data['md5Checksum']??''))throw new InvalidArgumentException('invalid_drive_image');
        return $data;
    }
    public static function thumbnail($user,$meta,$target) {
        $url=$meta['thumbnailLink']??'';$host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(parse_url($url,PHP_URL_SCHEME)!=='https'||!preg_match('/(^|\.)googleusercontent\.com$/D',$host))throw new InvalidArgumentException('preview_unavailable');
        $response=wp_safe_remote_get($url,array('timeout'=>20,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.self::access_token($user)),'limit_response_size'=>2*MB_IN_BYTES));
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)throw new InvalidArgumentException('preview_unavailable');
        $body=wp_remote_retrieve_body($response);$image=@getimagesizefromstring($body);if(strlen($body)>2*MB_IN_BYTES||!$image||$image[0]*$image[1]>50000000)throw new InvalidArgumentException('preview_unavailable');
        GE_WTP_Deco_Creators::preview_memory($image);$part=$target.'.part';if(is_link($part)||file_exists($target)||is_link($target))throw new InvalidArgumentException('immutable_preview_exists');file_put_contents($part,$body);$editor=wp_get_image_editor($part);if(is_wp_error($editor)){unlink($part);throw new InvalidArgumentException('preview_unavailable');}$editor->resize(1000,1000,false);$saved=$editor->save($target,'image/jpeg');unlink($part);if(is_wp_error($saved))throw new InvalidArgumentException('preview_unavailable');chmod($target,0640);
    }
    public static function download($user,$meta,$target) {
        $limits=GE_WTP_VPS_Storage::limits();$reserve=$limits['reserve_bytes'];if(disk_free_space(dirname($target))<$reserve+(int)$meta['size']+16*MB_IN_BYTES)throw new InvalidArgumentException('storage_capacity');
        $part=$target.'.part';if(file_exists($target)||is_link($target)||is_link($part))throw new InvalidArgumentException('immutable_original_exists');$response=wp_safe_remote_get('https://www.googleapis.com/drive/v3/files/'.rawurlencode(self::file_id($meta['id'])).'?alt=media',array('timeout'=>90,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.self::access_token($user)),'stream'=>true,'filename'=>$part,'limit_response_size'=>(int)$meta['size']+1));
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200||!is_file($part)||filesize($part)!==(int)$meta['size']||hash_file('md5',$part)!==$meta['md5Checksum']){if(is_file($part))unlink($part);throw new InvalidArgumentException('drive_download_failed');}
        $image=@getimagesize($part);if(!$image||($image['mime']??'')!==$meta['mimeType']||$image[0]*$image[1]>50000000){unlink($part);throw new InvalidArgumentException('invalid_drive_image');}
        if(file_exists($target)||is_link($target)){unlink($part);throw new InvalidArgumentException('immutable_original_exists');}if(!rename($part,$target))throw new InvalidArgumentException('drive_download_failed');chmod($target,0640);
    }
}
