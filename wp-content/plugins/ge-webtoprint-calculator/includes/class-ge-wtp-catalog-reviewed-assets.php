<?php
defined('ABSPATH') || exit;
/** Read-only delivery of fixed, approved public catalog assets. No uploads or business-record writes. */
final class GE_WTP_Catalog_Reviewed_Assets {
    const SOURCE_COMMIT='c903fc449dffdf8add689dae1068d26d04fcd7c7';
    public static function init(){add_action('template_redirect',array(__CLASS__,'serve'),-100);}
    public static function find($name){
        if(!is_string($name)||!preg_match('/\Agx-20261029-(?:render|photo)-[0-9]{3}(?:-thumb)?\.webp\z/D',$name))return null;
        foreach(GE_WTP_Catalog_Reviewed_Media::catalog()['assets'] as $file=>$asset){
            if(!in_array($asset['kind']??'',array('render','photo'),true))continue;
            if($name===$file)return $asset;
            if(isset($asset['thumbnail']['name'])&&$name===$asset['thumbnail']['name'])return $asset['thumbnail'];
        }
        return null;
    }
    public static function url($name){return self::find($name)?add_query_arg('gx_catalog_image',$name,home_url('/')):'';}
    public static function source_url($name){return self::find($name)?'https://raw.githubusercontent.com/LJVRYL/REPO-GRAPHEXPRESS/'.self::SOURCE_COMMIT.'/wp-content/plugins/ge-webtoprint-calculator/assets/catalog-reviewed-20261029/'.$name:'';}
    public static function valid_body($body,$asset){
        if(!is_string($body)||!is_array($asset)||!isset($asset['bytes'],$asset['sha256'])||strlen($body)!==(int)$asset['bytes']||strlen($body)>2000000||!hash_equals($asset['sha256'],hash('sha256',$body)))return false;
        $info=@getimagesizefromstring($body);
        return $info&&$info[2]===IMAGETYPE_WEBP&&$info[0]<=2000&&$info[1]<=2000;
    }
    public static function cache($name){
        $asset=self::find($name);if(!$asset)return new WP_Error('asset_missing','Imagen no disponible.');
        $upload=wp_upload_dir();if(!empty($upload['error']))return new WP_Error('asset_storage','No se pudo preparar la imagen.');
        $dir=$upload['basedir'].'/gx-reviewed-assets/20261029';
        if(!wp_mkdir_p($dir))return new WP_Error('asset_storage','No se pudo preparar la imagen.');
        $path=$dir.'/'.$asset['sha256'].'.webp';
        if(is_file($path))return hash_equals($asset['sha256'],hash_file('sha256',$path))?$path:new WP_Error('asset_corrupt','La imagen requiere verificación.');
        $lock=@fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);return new WP_Error('asset_busy','Reintentá la imagen.');}
        try{
            if(is_file($path))return hash_equals($asset['sha256'],hash_file('sha256',$path))?$path:new WP_Error('asset_corrupt','La imagen requiere verificación.');
            $response=wp_safe_remote_get(self::source_url($name),array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>(int)$asset['bytes']+1,'cookies'=>array()));
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return new WP_Error('asset_source','Reintentá la imagen.');
            $body=wp_remote_retrieve_body($response);if(!self::valid_body($body,$asset))return new WP_Error('asset_hash','La imagen no coincide con la versión aprobada.');
            $tmp=tempnam($dir,'gx-');if(!$tmp)return new WP_Error('asset_storage','No se pudo guardar la imagen.');
            try{
                if(realpath(dirname($tmp))!==realpath($dir)||file_put_contents($tmp,$body,LOCK_EX)!==strlen($body)||!hash_equals($asset['sha256'],hash_file('sha256',$tmp))||!rename($tmp,$path))return new WP_Error('asset_storage','No se pudo guardar la imagen.');
            }finally{if(is_file($tmp))unlink($tmp);}
            return $path;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function serve(){
        if(!isset($_GET['gx_catalog_image']))return;
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if(!in_array($method,array('GET','HEAD'),true)){status_header(405);header('Allow: GET, HEAD');exit;}
        $name=$_GET['gx_catalog_image'];$asset=self::find($name);if(!$asset){status_header(404);exit;}
        $etag='"'.$asset['sha256'].'"';
        if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){status_header(304);header('ETag: '.$etag);exit;}
        $path=null;if($method==='GET'){$path=self::cache($name);if(is_wp_error($path)){status_header(503);header('Cache-Control: no-store');exit;}}
        header('Content-Type: image/webp');header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="'.$name.'"');header('Cache-Control: public, max-age=31536000, immutable');header('ETag: '.$etag);header('Content-Length: '.(int)$asset['bytes']);
        if($method==='GET')readfile($path);exit;
    }
}
GE_WTP_Catalog_Reviewed_Assets::init();
