<?php
defined('ABSPATH') || exit;

/** Reuses VPS upload claims, the customer's artwork library and the global Analyzer. */
final class GE_WTP_Deco_Uploads {
    public static function init() { add_action('rest_api_init',array(__CLASS__,'routes')); }
    public static function routes() {
        $read=array('methods'=>'GET','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>array(__CLASS__,'permission'));
        $write=array('methods'=>'POST','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>array(__CLASS__,'permission'));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/uploads',array($read,$write));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/uploads/(?P<id>[a-f0-9]{32})',$read);
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/uploads/(?P<id>[a-f0-9]{32})/preview',$read);
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/selection',$write);
    }
    public static function permission($request) {
        if(!is_user_logged_in())return new WP_Error('authentication_required','Ingresá a tu cuenta.',array('status'=>401));
        return $request->get_method()==='POST'?GE_WTP_Deco_Gallery::write_permission($request):true;
    }
    public static function artwork($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_user_logged_in())throw new InvalidArgumentException('authentication_required');
        $posts=get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','numberposts'=>2,'fields'=>'ids','meta_query'=>array(array('key'=>'_ge_deco_upload_id','value'=>$id),array('key'=>'_ge_artwork_customer_id','value'=>get_current_user_id()))));
        if(count($posts)!==1)throw new InvalidArgumentException('unknown_upload');
        return $posts[0];
    }
    public static function source($work_id) {
        if(!is_string($work_id)||0!==strpos($work_id,'tu-imagen-'))throw new InvalidArgumentException('unknown_upload');
        $id=substr($work_id,10);$artwork=self::artwork($id);$original=get_post_meta($artwork,'_ge_artwork_original',true);
        $path=GE_WTP_VPS_Storage::download_path($original['relative_path']??'');
        $analysis=GE_WTP_File_Analysis::from_ref($original['file_analysis_ref']??$original['analysis']['file_analysis_ref']??'');
        if(!$path||!$analysis||!in_array($analysis['status'],array('analyzed','warning'),true)||!empty($analysis['facts']['blockers'])||($analysis['facts']['page_count']??0)!==1||hash_file('sha256',$path)!==($analysis['facts']['checksum_sha256']??''))throw new InvalidArgumentException('analysis_required');
        $pixels=$analysis['facts']['pixel_dimensions']??array();
        if(in_array($analysis['facts']['metadata']['exif_orientation']??1,array(5,6,7,8),true))$pixels=array_reverse($pixels);
        if(count($pixels)!==2||$pixels[0]*$pixels[1]>50000000)throw new InvalidArgumentException('invalid_image');
        return array('artwork_id'=>$artwork,'path'=>$path,'analysis'=>$analysis,'pixels'=>$pixels,'sha256'=>$analysis['facts']['checksum_sha256'],'original'=>$original,'work_id'=>$work_id);
    }
    public static function register($data) {
        if(!is_user_logged_in())throw new InvalidArgumentException('authentication_required');
        $token=$data['token']??'';if(!is_string($token)||strlen($token)>4096)throw new InvalidArgumentException('invalid_upload');
        $claim_hash=hash('sha256',$token);$existing=get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'numberposts'=>1,'fields'=>'ids','meta_query'=>array(array('key'=>'_ge_deco_claim_hash','value'=>$claim_hash),array('key'=>'_ge_artwork_customer_id','value'=>get_current_user_id()))));
        if($existing)return array('id'=>get_post_meta($existing[0],'_ge_deco_upload_id',true));
        $count=0;foreach(GE_WTP_Artwork_Library::get_items(get_current_user_id(),true) as $item){if(get_post_meta($item->ID,'_ge_deco_upload_id',true))$count++;}if($count>=50)throw new InvalidArgumentException('upload_limit');
        $descriptors=GE_WTP_VPS_Storage::validate_uploaded_claims(array($token),get_current_user_id());
        if(is_wp_error($descriptors))return $descriptors;
        $descriptor=$descriptors[0]??array();
        if(!in_array($descriptor['mime']??'',array('image/jpeg','image/png'),true)||($descriptor['size']??0)>20*MB_IN_BYTES)throw new InvalidArgumentException('invalid_image');
        $id=str_replace('-','',wp_generate_uuid4());
        $artwork=wp_insert_post(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','post_author'=>get_current_user_id(),'post_title'=>sanitize_text_field(pathinfo($descriptor['name'],PATHINFO_FILENAME))),true);
        if(is_wp_error($artwork))return $artwork;
        update_post_meta($artwork,'_ge_artwork_customer_id',get_current_user_id());update_post_meta($artwork,'_ge_artwork_status','review');update_post_meta($artwork,'_ge_artwork_version','1');
        // Existing storage consolidates the authenticated claim; order 0 is the library namespace.
        $original=GE_WTP_VPS_Storage::finalize_descriptor($descriptor,0,$artwork);
        if(is_wp_error($original)){wp_trash_post($artwork);return $original;}
        $original=GE_WTP_File_Analysis::record($original,GE_WTP_Documents::private_directory());
        update_post_meta($artwork,'_ge_artwork_original',$original);update_post_meta($artwork,'_ge_artwork_original_name',$original['name']);update_post_meta($artwork,'_ge_artwork_storage_provider','vps');
        update_post_meta($artwork,'_ge_deco_upload_id',$id);update_post_meta($artwork,'_ge_deco_claim_hash',$claim_hash);
        return array('id'=>$id);
    }
    public static function status($id) {
        $artwork=self::artwork($id);$original=get_post_meta($artwork,'_ge_artwork_original',true);$analysis=GE_WTP_File_Analysis::from_ref($original['file_analysis_ref']??$original['analysis']['file_analysis_ref']??'');
        if(!$analysis||in_array($analysis['status'],array('queued','analyzing'),true))return array('status'=>'analyzing','stage'=>'Analizando la calidad de tu imagen');
        if(!in_array($analysis['status'],array('analyzed','warning'),true)||!empty($analysis['facts']['blockers']))return array('status'=>'failed','error'=>'invalid_image');
        $source=self::source('tu-imagen-'.$id);$has_sizes=false;
        foreach(GE_WTP_Deco_Gallery::options() as $option){foreach(array('portrait','landscape') as $orientation){$size=$orientation==='portrait'?$option['size']:array_reverse($option['size']);$quality=GE_Deco_Print_Quality::metrics($source['pixels'],array_map(static function($v){return (int)round($v*10);},$size),'contain');if($quality['effective_ppi']>=200){$has_sizes=true;break 2;}}}
        $preview=add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url(GE_WTP_Deco_Gallery::NS.'/uploads/'.$id.'/preview'));
        return array('status'=>'ready','artwork_library_id'=>$artwork,'has_sizes'=>$has_sizes,'work'=>array('id'=>'tu-imagen-'.$id,'title'=>'Tu imagen','artist'=>'Tu creación','category'=>'Tu imagen','uploaded'=>true,'pixels'=>$source['pixels'],'image'=>$preview));
    }
    public static function selection($data) {
        $work_id=$data['work_id']??'';$source=self::source($work_id);$option=null;
        foreach(GE_WTP_Deco_Gallery::options() as $candidate){if($candidate['group']===($data['group']??null)&&$candidate['model']===($data['model']??null)&&$candidate['finish']===($data['finish']??null)&&implode('x',$candidate['size'])===($data['size']??null)){$option=$candidate;break;}}
        if(!$option||!in_array($data['orientation']??'',array('portrait','landscape'),true)||!in_array($data['fit']??'',array('cover','contain'),true))throw new InvalidArgumentException('invalid_selection');
        $size=$data['orientation']==='portrait'?$option['size']:array_reverse($option['size']);GE_Deco_Print_Quality::enforce($source['pixels'],array_map(static function($v){return (int)round($v*10);},$size),$data['fit']);
        $count=0;foreach(array_keys(get_post_meta($source['artwork_id'])) as $key){if(0===strpos($key,'_ge_deco_selection_'))$count++;}if($count>=50)throw new InvalidArgumentException('selection_limit');
        $id=str_replace('-','',wp_generate_uuid4());$selection=array('selection_id'=>$id,'work_id'=>$work_id,'source_sha256'=>$source['sha256'],'option_id'=>$option['id'],'size_cm'=>$option['size'],'orientation'=>$data['orientation'],'fit'=>$data['fit'],'production_approved'=>false,'artwork_library_id'=>$source['artwork_id']);
        add_post_meta($source['artwork_id'],'_ge_deco_selection_'.$id,$selection,true);return array('selection_id'=>$id);
    }
    public static function dispatch($request) {
        try{
            if(substr($request->get_route(),-10)==='/selection')return self::selection($request->get_json_params());
            if(!$request['id']){
                if($request->get_method()==='POST')return self::register($request->get_json_params());
                $works=array();foreach(GE_WTP_Artwork_Library::get_items(get_current_user_id()) as $artwork){$id=get_post_meta($artwork->ID,'_ge_deco_upload_id',true);if($id){$status=self::status($id);if($status['status']==='ready')$works[]=$status['work'];}}return array('works'=>$works);
            }
            if(substr($request->get_route(),-8)==='/preview'){
                $source=self::source('tu-imagen-'.$request['id']);
                $folder=GE_WTP_Deco_Gallery::root().'/.customer-previews/'.get_current_user_id();wp_mkdir_p($folder);$preview=$folder.'/'.$request['id'].'.png';
                if(!is_file($preview)){$editor=wp_get_image_editor($source['path']);if(is_wp_error($editor))return $editor;$editor->maybe_exif_rotate();$editor->resize(1400,1400,false);$saved=$editor->save($preview,'image/png');if(is_wp_error($saved))return $saved;chmod($preview,0600);}
                nocache_headers();header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');readfile($preview);exit;
            }
            return self::status($request['id']);
        }catch(InvalidArgumentException $error){return new WP_Error($error->getMessage(),'No pudimos verificar esta imagen.',array('status'=>409));}
    }
}
