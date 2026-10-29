<?php
defined('ABSPATH') || exit;
/** Fixed approved media release; existing cost-engine write permission, never arbitrary uploads. */
final class GE_WTP_Catalog_Reviewed_Media {
    const ACTION='ge_catalog_reviewed_media_publish';
    const META='_ge_catalog_reviewed_media_20261028';
    const BACKUP='ge_catalog_reviewed_media_backup_20261028';
    public static function init(){
        add_action('template_redirect',array(__CLASS__,'release_page'),-80);
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'publish'));
        add_action('woocommerce_single_product_summary',array(__CLASS__,'gallery'),26);
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'));
    }
    public static function catalog(){static $c;if(null===$c)$c=json_decode(file_get_contents(__DIR__.'/gx-catalog-media-20261028.json'),true);return $c;}
    public static function can_write(){return is_user_logged_in() && current_user_can('ge_manage_products') && class_exists('GE_Organization_Runtime') && GE_Organization_Runtime::allowed('cost_engine',true);}
    private static function require_write(){if(!self::can_write())wp_die('Se requiere permiso de edición de productos.','',array('response'=>403));}
    private static function product($slug){$p=get_page_by_path($slug,OBJECT,'product');return $p && $p->post_status==='publish'?$p:null;}
    public static function snapshot(){
        $out=array('release'=>'20261028','home'=>home_url('/'),'products'=>array());
        foreach(self::catalog()['products'] as $slug=>$row){$p=self::product($slug);if(!$p)continue;$out['products'][$slug]=array('id'=>$p->ID,'had_meta'=>metadata_exists('post',$p->ID,self::META),'before'=>get_post_meta($p->ID,self::META,true),'thumbnail'=>get_post_thumbnail_id($p->ID),'gallery'=>get_post_meta($p->ID,'_product_image_gallery',true));}
        return $out;
    }
    private static function attachment($name,$asset){
        $ids=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','posts_per_page'=>1,'meta_key'=>'_ge_catalog_reviewed_sha256','meta_value'=>$asset['sha256']));
        if(!$ids)return 0;$id=(int)$ids[0];$file=get_attached_file($id);
        return $file && is_file($file) && hash_equals($asset['sha256'],hash_file('sha256',$file))?$id:0;
    }
    public static function validate_files($files){
        if(empty($files['name'])||!is_array($files['name'])||count($files['name'])>10)return new WP_Error('batch','Elegí entre 1 y 10 imágenes de esta entrega.');
        $ok=array();$assets=self::catalog()['assets'];
        foreach($files['name'] as $i=>$raw){
            $name=basename((string)$raw);$tmp=$files['tmp_name'][$i]??'';
            if($raw!==$name||!isset($assets[$name])||isset($ok[$name])||($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||filesize($tmp)>2000000)return new WP_Error('file','Archivo ajeno a la entrega o carga incompleta.');
            if(!self::validate_asset($name,$tmp))return new WP_Error('hash','La imagen no coincide con la versión aprobada.');
            $ok[$name]=$tmp;
        }
        return $ok;
    }
    public static function validate_asset($name,$tmp){
        $assets=self::catalog()['assets'];
        if(!isset($assets[$name])||!is_file($tmp)||filesize($tmp)>2000000)return false;
        $info=getimagesize($tmp);
        return $info && $info[2]===IMAGETYPE_WEBP && $info[0]<=2000 && $info[1]<=2000 && hash_equals($assets[$name]['sha256'],hash_file('sha256',$tmp));
    }
    public static function publish(){
        self::require_write();check_admin_referer(self::ACTION);
        $files=self::validate_files($_FILES['gx_images']??array());if(is_wp_error($files))wp_die(esc_html($files->get_error_message()),'',array('response'=>400));
        if(!get_option(self::BACKUP)){
            $snapshot=self::snapshot();$encoded=wp_json_encode($snapshot);$backup=array('snapshot'=>$snapshot,'sha256'=>hash('sha256',$encoded),'created_at'=>gmdate('c'),'actor'=>get_current_user_id());
            if(!add_option(self::BACKUP,$backup,'',false)||get_option(self::BACKUP)!==$backup)wp_die('No se pudo verificar el respaldo. No se publicaron imágenes.','',array('response'=>500));
        }
        require_once ABSPATH.'wp-admin/includes/image.php';
        foreach($files as $name=>$tmp){$asset=self::catalog()['assets'][$name];if(self::attachment($name,$asset))continue;
            $upload=wp_upload_bits($name,null,file_get_contents($tmp));if(!empty($upload['error']))wp_die('No se pudo guardar una imagen. Podés reintentar la misma tanda.','',array('response'=>500));
            if(!hash_equals($asset['sha256'],hash_file('sha256',$upload['file'])))wp_die('La verificación de la imagen guardada falló.','',array('response'=>500));
            $id=wp_insert_attachment(array('post_mime_type'=>'image/webp','post_title'=>sanitize_text_field($asset['title'].' — Graphex'),'post_excerpt'=>'Imagen de referencia editada con IA. Configuración según la opción elegida.','post_status'=>'inherit'),$upload['file']);
            if(is_wp_error($id)||!$id)wp_die('No se pudo registrar una imagen.','',array('response'=>500));
            update_post_meta($id,'_ge_catalog_reviewed_sha256',$asset['sha256']);update_post_meta($id,'_ge_catalog_reviewed_source',$asset['source_sha256']);update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($asset['title'].' — imagen de referencia'));
            wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$upload['file']));
        }
        foreach(self::catalog()['products'] as $slug=>$row){$p=self::product($slug);if(!$p)continue;$map=array();
            foreach($row['options'] as $key=>$opt){$ids=array();foreach($opt['assets'] as $name){$id=self::attachment($name,self::catalog()['assets'][$name]);if($id)$ids[]=$id;}$map[$key]=array('label'=>$opt['label'],'ids'=>$ids);}
            update_post_meta($p->ID,self::META,$map);
        }
        wp_safe_redirect(add_query_arg(array('gx_catalog_media_release'=>'20261028','saved'=>'1'),home_url('/')),303);exit;
    }
    public static function release_page(){
        if(!isset($_GET['gx_catalog_media_release']))return;
        self::require_write();if($_GET['gx_catalog_media_release']!=='20261028')wp_die('Entrega inexistente.','',array('response'=>404));
        nocache_headers();
        if(isset($_GET['backup'])){check_admin_referer('gx_catalog_media_backup');$b=get_option(self::BACKUP);$payload=$b?:array('snapshot'=>self::snapshot(),'stage'=>'before_publication');header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="Graphex-media-backup-20261028.json"');echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;}
        $ready=array();foreach(self::catalog()['assets'] as $name=>$asset)if(self::attachment($name,$asset))$ready[]=$name;
        ?><!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Publicar fotos aprobadas · Graphex</title><style>body{font:16px/1.55 system-ui;max-width:900px;padding:32px;margin:auto;color:#111827;background:#f4f5f8}main{background:white;padding:28px;border-radius:18px}button,input{font:inherit;padding:12px;min-height:44px}button{background:#6d45ef;color:white;border:0;border-radius:8px}a{color:#4422b8}li{overflow-wrap:anywhere}small{color:#667085}</style><main><h1>Fotos aprobadas · Graphex</h1><p><strong><?php echo count($ready); ?> de <?php echo count(self::catalog()['assets']); ?> imágenes registradas y verificadas.</strong></p><p>Entrega fija aprobada por Leo. Cargá hasta 10 archivos por tanda. Repetir una tanda no duplica imágenes ya verificadas.</p><p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('gx_catalog_media_release'=>'20261028','backup'=>'1'),home_url('/')),'gx_catalog_media_backup')); ?>">Descargar respaldo de asociaciones</a></p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>"><?php wp_nonce_field(self::ACTION); ?><p><label>Imágenes aprobadas <input type="file" name="gx_images[]" accept="image/webp" multiple required></label></p><button type="submit">Publicar esta tanda</button></form><details><summary>Archivos verificados</summary><ul><?php foreach($ready as $name)echo '<li>'.esc_html($name).'</li>'; ?></ul></details><p><small>El respaldo se conserva antes del primer cambio. Esta entrega agrega asociaciones de imágenes por opción; no altera precios, pedidos ni condiciones de venta.</small></p></main></html><?php exit;
    }
    public static function assets(){if(!function_exists('is_product')||!is_product()||!get_post_meta(get_queried_object_id(),self::META,true))return;wp_enqueue_style('gx-reviewed-media',GE_WTP_PLUGIN_URL.'assets/css/catalog-reviewed-media.css',array(),'20261028.1');wp_enqueue_script('gx-reviewed-media',GE_WTP_PLUGIN_URL.'assets/js/catalog-reviewed-media.js',array(),'20261028.1',true);}
    public static function gallery(){global $product;if(!$product)return;$map=get_post_meta($product->get_id(),self::META,true);if(!$map||!is_array($map))return;
        ?><section class="gx-reviewed-gallery" data-gx-reviewed-gallery><h2>Imágenes de esta opción</h2><p data-gx-reviewed-label>Elegí una opción para ver sus imágenes.</p><noscript><style>.gx-reviewed-gallery figure[hidden]{display:block!important}</style><p>Referencias de todos los modelos; cada imagen indica su opción.</p></noscript><div class="gx-reviewed-grid"><?php foreach($map as $key=>$opt)foreach($opt['ids'] as $id){$url=wp_get_attachment_image_url($id,'full');if(!$url)continue; ?><figure data-gx-media-option="<?php echo esc_attr($key); ?>" hidden><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image($id,'large',false,array('loading'=>'lazy')); ?></a><figcaption><?php echo esc_html($opt['label']); ?></figcaption></figure><?php } ?></div><small>Imágenes de referencia editadas. La configuración corresponde a la opción seleccionada.</small><script type="application/json" data-gx-reviewed-map><?php echo wp_json_encode($map,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script></section><?php
    }
}
GE_WTP_Catalog_Reviewed_Media::init();
