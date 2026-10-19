<?php
defined('ABSPATH') || exit;

/** Creator management is an extension of the native customer artwork library. */
final class GE_WTP_Deco_Creators {
    const META='_ge_deco_creator_work';
    const PROFILE='_ge_deco_creator_gallery';
    public static function enabled(){return defined('GE_DECO_CREATOR_GALLERIES_ENABLED')&&GE_DECO_CREATOR_GALLERIES_ENABLED===true&&(!class_exists('GE_Organization_Runtime')||(GE_Organization_Runtime::enabled('orders')&&GE_Organization_Runtime::enabled('customers')&&GE_Organization_Runtime::enabled('file_analyzer')));}
    public static function init() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_filter('woocommerce_account_menu_items',array(__CLASS__,'menu'),26);
        add_action('init',static function(){if(self::enabled())add_rewrite_endpoint('mi-galeria',EP_ROOT|EP_PAGES);});
        add_action('woocommerce_account_mi-galeria_endpoint',array(__CLASS__,'render'));
        add_action('wp_enqueue_scripts',array(__CLASS__,'assets'));
    }
    public static function menu($items){if(self::enabled())$items['mi-galeria']='Mi galería';return $items;}
    public static function require_owner() {
        if(!self::enabled()||!is_user_logged_in()||!GE_WTP_Portal::is_customer_user()||GE_WTP_Portal::is_staff_preview())throw new InvalidArgumentException('authentication_required');
        return get_current_user_id();
    }
    public static function permission($request) {
        try{self::require_owner();if($request->get_method()!=='GET')return GE_WTP_Deco_Gallery::write_permission($request);return true;}
        catch(InvalidArgumentException $e){return new WP_Error('authentication_required','Ingresá con tu cuenta de cliente.',array('status'=>401));}
    }
    public static function routes() {
        if(!self::enabled())return;
        $read=array('methods'=>'GET','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>array(__CLASS__,'permission'));
        $write=array('methods'=>'POST','callback'=>array(__CLASS__,'dispatch'),'permission_callback'=>array(__CLASS__,'permission'));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator',array($read,$write));
        foreach(array('import-drive','from-library','work','disconnect','picker') as $action)register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator/'.$action,$write);
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creators',array('methods'=>'GET','callback'=>array(__CLASS__,'directory'),'permission_callback'=>'__return_true'));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-work/(?P<id>autor-[0-9]+-v[0-9]+)(?:/(?P<action>preview|prepare|selection))?',array('methods'=>array('GET','POST'),'callback'=>array(__CLASS__,'public_request'),'permission_callback'=>'__return_true'));
        register_rest_route(GE_WTP_Deco_Gallery::NS,'/creator-review',array('methods'=>'POST','callback'=>array(__CLASS__,'review_request'),'permission_callback'=>static function($r){return current_user_can('manage_woocommerce')&&GE_WTP_Deco_Gallery::write_permission($r)===true;}));
    }
    public static function profile($user) {
        $saved=get_user_meta($user,self::PROFILE,true);$default=array('title'=>'Mi galería','bio'=>'','slug'=>'artista-'.$user,'public'=>false,'collections'=>array('mis-obras'=>'Mis obras'));
        return is_array($saved)?array_merge($default,$saved):$default;
    }
    public static function save_profile($user,$data) {
        $title=sanitize_text_field($data['title']??'');if(!$title||mb_strlen($title)>80)throw new InvalidArgumentException('invalid_gallery_title');
        $collections=array();foreach((array)($data['collections']??array()) as $label){$label=sanitize_text_field($label);if(!$label||mb_strlen($label)>40)continue;$collections[sanitize_title($label)]=$label;}
        if(count($collections)>12)throw new InvalidArgumentException('collection_limit');if(!$collections)$collections=array('mis-obras'=>'Mis obras');
        $old=self::profile($user);$new=array('title'=>$title,'bio'=>mb_substr(sanitize_textarea_field($data['bio']??''),0,800),'slug'=>$old['slug'],'public'=>($data['public']??false)===true,'collections'=>$collections);
        update_user_meta($user,self::PROFILE,$new);return $new;
    }
    public static function items($user) {
        return get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','posts_per_page'=>200,'orderby'=>'modified','order'=>'DESC','meta_query'=>array(array('key'=>'_ge_artwork_customer_id','value'=>$user),array('key'=>self::META,'compare'=>'EXISTS'))));
    }
    public static function owned($id,$user) {
        $post=get_post($id);if(!$post||$post->post_type!==GE_WTP_Artwork_Library::POST_TYPE||$post->post_status!=='publish'||(int)get_post_meta($id,'_ge_artwork_customer_id',true)!==(int)$user||(int)$post->post_author!==(int)$user)throw new InvalidArgumentException('unknown_artwork');return $post;
    }
    public static function parse_id($id) {
        if(!is_string($id)||!preg_match('/^autor-([1-9][0-9]*)-v([1-9][0-9]*)$/D',$id,$m))throw new InvalidArgumentException('unknown_artwork');return array((int)$m[1],(int)$m[2]);
    }
    public static function folder($id) {
        list($artwork,$revision)=self::parse_id($id);$root=defined('GE_WTP_PRIVATE_UPLOAD_DIR')?GE_WTP_PRIVATE_UPLOAD_DIR:getenv('GE_WTP_PRIVATE_UPLOAD_DIR');$real=realpath($root);$public=realpath(ABSPATH);
        if(!$real||!$public||$real===$public||strpos($real.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)===0)throw new InvalidArgumentException('private_storage_required');
        $folder=$real.'/orders/0/'.$artwork.'/creator-v'.$revision;wp_mkdir_p($folder);
        if(is_link($folder)||strpos(realpath($folder).DIRECTORY_SEPARATOR,$real.DIRECTORY_SEPARATOR)!==0)throw new InvalidArgumentException('private_storage_required');return $folder;
    }
    /** Bounded cache, fail closed on quota. Never evicts a customer's original. */
    public static function storage_budget($user,$incoming) {
        $root=realpath(GE_WTP_PRIVATE_UPLOAD_DIR);if(!$root||$incoming<0)throw new InvalidArgumentException('storage_capacity');
        $global=0;$creator=0;$own=0;$limits=GE_WTP_VPS_Storage::limits();
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if(!$file->isFile()||$file->isLink())continue;$size=$file->getSize();$global+=$size;$name=str_replace('\\','/',$file->getPathname());
            if(preg_match('~/orders/0/([0-9]+)/creator-v[0-9]+/~',$name,$m)){$creator+=$size;if((int)get_post_meta((int)$m[1],'_ge_artwork_customer_id',true)===$user)$own+=$size;}}
        if($global+$incoming>$limits['max_global_bytes']||$creator+$incoming>2*GB_IN_BYTES||$own+$incoming>min(512*MB_IN_BYTES,$limits['max_user_bytes'])||disk_free_space($root)-$incoming<$limits['reserve_bytes']+16*MB_IN_BYTES)throw new InvalidArgumentException('storage_capacity');
    }
    private static function mutation_lock($user) {
        $path=GE_WTP_Deco_Gallery::root().'/.creator-owner-'.absint($user).'.lock';$lock=fopen($path,'c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('busy');return $lock;
    }
    private static function new_work($user,$data) {
        self::storage_budget($user,2*MB_IN_BYTES);if(count(self::items($user))>=200)throw new InvalidArgumentException('work_limit');
        if(($data['rights']??false)!==true)throw new InvalidArgumentException('rights_required');
        $title=mb_substr(sanitize_text_field($data['title']??''),0,100);if(!$title)throw new InvalidArgumentException('invalid_title');
        return array('title'=>$title,'description'=>'','collection'=>array_key_first(self::profile($user)['collections']),'artist_price'=>array('mode'=>'inherit'),'revision'=>1,'status'=>'draft','rights_at'=>time(),'rights_owner'=>$user,'sales_license_version'=>!empty($data['print_rights'])&&$data['print_rights']===true?1:0,'sales_license_at'=>!empty($data['print_rights'])&&$data['print_rights']===true?time():0,'created_at'=>time());
    }
    private static function save_new($user,$row,$existing=0) {
        $id=$existing?:wp_insert_post(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','post_author'=>$user,'post_title'=>$row['title']),true);
        if(is_wp_error($id))throw new InvalidArgumentException('work_save_failed');update_post_meta($id,'_ge_artwork_customer_id',$user);update_post_meta($id,self::META,$row);return $id;
    }
    public static function import_drive($user,$data) {
        $row=self::new_work($user,$data);$meta=GE_WTP_Deco_Drive::metadata($user,$data['file_id']??'');$row['source']=array('provider'=>'drive','file_id'=>$meta['id'],'version'=>(string)$meta['version'],'md5'=>$meta['md5Checksum'],'mime'=>$meta['mimeType'],'size'=>(int)$meta['size']);
        $row['pixels']=array(max(1,(int)($meta['imageMediaMetadata']['width']??1000)),max(1,(int)($meta['imageMediaMetadata']['height']??1000)));
        $existing=get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','meta_query'=>array(array('key'=>'_ge_artwork_customer_id','value'=>$user),array('key'=>'_ge_deco_creator_drive_id','value'=>$meta['id']))));
        if($existing)return (int)$existing[0]; // Idempotent same-account import; new versions are explicit.
        $id=self::save_new($user,$row);update_post_meta($id,'_ge_deco_creator_drive_id',$meta['id']);
        try{GE_WTP_Deco_Drive::thumbnail($user,$meta,self::folder('autor-'.$id.'-v1').'/preview.jpg');}
        catch(InvalidArgumentException $e){$row['status']='needs_preview';update_post_meta($id,self::META,$row);}
        return $id;
    }
    public static function from_library($user,$data) {
        $id=absint($data['artwork_id']??0);self::owned($id,$user);if(get_post_meta($id,self::META,true))return $id;
        $row=self::new_work($user,$data);$original=get_post_meta($id,'_ge_artwork_original',true);$path=GE_WTP_VPS_Storage::download_path($original['relative_path']??'');$analysis=GE_WTP_File_Analysis::from_ref($original['file_analysis_ref']??'');
        if(!$path||!$analysis||!in_array($analysis['status'],array('analyzed','warning'),true)||!empty($analysis['facts']['blockers'])||!in_array($original['mime']??'',array('image/jpeg','image/png'),true)||hash_file('sha256',$path)!==($analysis['facts']['checksum_sha256']??''))throw new InvalidArgumentException('analysis_required');
        $row['source']=array('provider'=>'library','original'=>$original,'sha256'=>$analysis['facts']['checksum_sha256']);$row['pixels']=$analysis['facts']['pixel_dimensions'];$row['analysis_ref']=$original['file_analysis_ref'];
        $folder=self::folder('autor-'.$id.'-v1');self::make_preview($path,$folder.'/preview.jpg');self::save_new($user,$row,$id);return $id;
    }
    public static function make_preview($path,$target) {
        $size=@getimagesize($path);self::preview_memory($size);$editor=wp_get_image_editor($path);if(is_wp_error($editor))throw new InvalidArgumentException('preview_unavailable');$editor->maybe_exif_rotate();$editor->resize(1000,1000,false);$saved=$editor->save($target,'image/jpeg');if(is_wp_error($saved))throw new InvalidArgumentException('preview_unavailable');chmod($target,0640);
    }
    public static function preview_memory($size) {
        if(!$size||$size[0]<1||$size[1]<1||$size[0]*$size[1]>50000000)throw new InvalidArgumentException('preview_unavailable');
        wp_raise_memory_limit('image');$limit=wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if($limit>0&&$size[0]*$size[1]*6+memory_get_usage(true)+16*MB_IN_BYTES>$limit)throw new InvalidArgumentException('preview_unavailable');
    }
    public static function edit_work($user,$data) {
        $id=absint($data['id']??0);self::owned($id,$user);$row=get_post_meta($id,self::META,true);if(!$row)throw new InvalidArgumentException('unknown_artwork');
        self::storage_budget($user,2*MB_IN_BYTES);if(($data['revision']??null)!==$row['revision'])throw new InvalidArgumentException('work_revision_changed');$action=$data['action']??'save';
        if($action==='withdraw'){delete_post_meta($id,'_ge_deco_creator_published');$row['status']='draft';}
        elseif($action==='submit'){
            if(!is_file(self::folder('autor-'.$id.'-v'.$row['revision']).'/preview.jpg'))throw new InvalidArgumentException('preview_required');
            if(($data['rights']??false)!==true)throw new InvalidArgumentException('rights_required');$row['rights_at']=time();$row['status']='review';
        }elseif($action==='refresh-drive'){
            if(($row['source']['provider']??'')!=='drive')throw new InvalidArgumentException('invalid_source');$meta=GE_WTP_Deco_Drive::metadata($user,$row['source']['file_id']);
            if((string)$meta['version']===$row['source']['version']){if(!is_file(self::folder('autor-'.$id.'-v'.$row['revision']).'/preview.jpg')){GE_WTP_Deco_Drive::thumbnail($user,$meta,self::folder('autor-'.$id.'-v'.$row['revision']).'/preview.jpg');$row['status']='draft';update_post_meta($id,self::META,$row);}return $row;}
            $row['revision']++;$row['status']='draft';unset($row['analysis_ref']);$row['source']=array('provider'=>'drive','file_id'=>$meta['id'],'version'=>(string)$meta['version'],'md5'=>$meta['md5Checksum'],'mime'=>$meta['mimeType'],'size'=>(int)$meta['size']);$row['pixels']=array((int)($meta['imageMediaMetadata']['width']??1000),(int)($meta['imageMediaMetadata']['height']??1000));GE_WTP_Deco_Drive::thumbnail($user,$meta,self::folder('autor-'.$id.'-v'.$row['revision']).'/preview.jpg');
        }elseif($action==='save'){
            $artist_price=GE_Deco_Artist_Pricing::rule($data['artist_price']??$row['artist_price']??array('mode'=>'inherit'),true);
            $title=mb_substr(sanitize_text_field($data['title']??''),0,100);$collection=sanitize_title($data['collection']??'');if(!$title||!isset(self::profile($user)['collections'][$collection]))throw new InvalidArgumentException('invalid_work_details');
            // Published history remains immutable; metadata edits require a fresh review.
            $old_id='autor-'.$id.'-v'.$row['revision'];$old=self::folder($old_id);$cache=get_option('ge_deco_creator_cache_'.$old_id);$row['revision']++;$new_id='autor-'.$id.'-v'.$row['revision'];$new=self::folder($new_id);if(is_file($old.'/preview.jpg'))copy($old.'/preview.jpg',$new.'/preview.jpg');if($cache)update_option('ge_deco_creator_cache_'.$new_id,$cache,false);
            $row['title']=$title;$row['description']=mb_substr(sanitize_textarea_field($data['description']??''),0,800);$row['collection']=$collection;$row['artist_price']=$artist_price;if(($data['print_rights']??false)===true){$row['sales_license_version']=1;$row['sales_license_at']=time();}$row['status']='draft';unset($row['analysis_ref']);
        }else throw new InvalidArgumentException('invalid_action');
        update_post_meta($id,self::META,$row);return $row;
    }
    public static function approve($id,$revision,$decision) {
        if(!current_user_can('manage_woocommerce')||GE_WTP_Portal::is_staff_preview()||!GE_WTP_Portal::is_customer_user(get_user_by('id',(int)get_post_meta($id,'_ge_artwork_customer_id',true))))throw new InvalidArgumentException('review_forbidden');$row=get_post_meta($id,self::META,true);
        if(!$row||$row['status']!=='review'||$row['revision']!==$revision||!in_array($decision,array('approve','reject'),true))throw new InvalidArgumentException('work_revision_changed');
        $row['status']=$decision==='approve'?'published':'needs_changes';$row['reviewed_by']=get_current_user_id();$row['reviewed_at']=time();
        if($decision==='approve'){
            $public=$row;$public['work_id']='autor-'.$id.'-v'.$revision;$public['owner_id']=(int)get_post_meta($id,'_ge_artwork_customer_id',true);add_post_meta($id,'_ge_deco_creator_history_'.$revision,$public,true);update_post_meta($id,'_ge_deco_creator_published',$public);
        }update_post_meta($id,self::META,$row);return $row['status'];
    }
    public static function resolve($id,$allow_owner=true) {
        list($post,$revision)=self::parse_id($id);$object=get_post($post);$owner=(int)get_post_meta($post,'_ge_artwork_customer_id',true);if(!$object||$object->post_type!==GE_WTP_Artwork_Library::POST_TYPE||$object->post_status!=='publish'||(int)$object->post_author!==$owner||!$owner||!GE_WTP_Portal::is_customer_user(get_user_by('id',$owner)))throw new InvalidArgumentException('unknown_artwork');
        $published=get_post_meta($post,'_ge_deco_creator_published',true);$profile=self::profile($owner);
        if($profile['public']&&$published&&$published['revision']===$revision)return $published;
        if($allow_owner&&!GE_WTP_Portal::is_staff_preview()&&(get_current_user_id()===$owner||current_user_can('manage_woocommerce'))){$row=get_post_meta($post,self::META,true);if($row&&$row['revision']===$revision)return $row+array('owner_id'=>$owner,'work_id'=>$id);}
        throw new InvalidArgumentException('unknown_artwork');
    }
    public static function public_work($row) {
        $profile=self::profile($row['owner_id']);$source=array('id'=>'creator','name'=>$profile['title'],'url'=>self::gallery_url($profile),'license'=>'Obra compartida por su autor','licenseUrl'=>self::gallery_url($profile));
        return array('id'=>$row['work_id'],'title'=>$row['title'],'artist'=>$profile['title'],'category'=>$profile['collections'][$row['collection']]??'Mis obras','pixels'=>$row['pixels'],'image'=>rest_url(GE_WTP_Deco_Gallery::NS.'/creator-work/'.$row['work_id'].'/preview'),'remote'=>true,'verified'=>false,'creator_showcase'=>true,'source'=>$source,'collections'=>array('autor-'.$profile['slug']));
    }
    public static function gallery_url($profile){return home_url('/deco-prints/').'#galeria/autor-'.$profile['slug'];}
    public static function directory() {
        $works=array();$collections=array();$posts=get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','posts_per_page'=>1000,'meta_key'=>'_ge_deco_creator_published'));
        foreach($posts as $post){$row=get_post_meta($post->ID,'_ge_deco_creator_published',true);if(!$row)continue;try{$row=self::resolve($row['work_id'],false);}catch(InvalidArgumentException $e){continue;}$profile=self::profile($row['owner_id']);$key='autor-'.$profile['slug'];$works[]=self::public_work($row);if(!isset($collections[$key]))$collections[$key]=array('id'=>$key,'name'=>$profile['title'],'cover'=>$row['work_id'],'count'=>0,'bio'=>$profile['bio']);$collections[$key]['count']++;}
        return array('works'=>$works,'collections'=>array_values($collections),'sales_enabled'=>false);
    }
    public static function state($user) {
        $works=array();foreach(self::items($user) as $post){$row=get_post_meta($post->ID,self::META,true);$id='autor-'.$post->ID.'-v'.$row['revision'];$works[]=array('id'=>$post->ID,'work_id'=>$id,'revision'=>$row['revision'],'title'=>$row['title'],'description'=>$row['description'],'collection'=>$row['collection'],'artist_price'=>$row['artist_price']??array('mode'=>'inherit'),'print_rights'=>($row['sales_license_version']??0)===1,'status'=>$row['status'],'published'=>(bool)get_post_meta($post->ID,'_ge_deco_creator_published',true),'source'=>$row['source']['provider'],'preview'=>add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url(GE_WTP_Deco_Gallery::NS.'/creator-work/'.$id.'/preview')));}
        $library=array();foreach(GE_WTP_Artwork_Library::get_items($user) as $post){if(get_post_meta($post->ID,self::META,true))continue;$original=get_post_meta($post->ID,'_ge_artwork_original',true);if(in_array($original['mime']??'',array('image/jpeg','image/png'),true))$library[]=array('id'=>$post->ID,'title'=>$post->post_title);}
        $profile=self::profile($user);return array('profile'=>$profile,'gallery_url'=>self::gallery_url($profile),'works'=>$works,'library'=>$library,'drive_configured'=>GE_WTP_Deco_Drive::enabled(),'drive_connected'=>GE_WTP_Deco_Drive::connected($user),'sales_enabled'=>false);
    }
    public static function dispatch($request) {
        $lock=null;try{$user=self::require_owner();if($request->get_method()!=='GET')$lock=self::mutation_lock($user);$route=$request->get_route();$data=$request->get_json_params()?:array();$limit='ge_deco_creator_rate_'.$user;$count=(int)get_transient($limit);if($request->get_method()!=='GET'){if($count>=30)throw new InvalidArgumentException('rate_limit');set_transient($limit,$count+1,60);}
            if($request->get_method()==='GET')return self::state($user);
            if(substr($route,-7)==='/picker'){$c=GE_WTP_Deco_Drive::config();return array('access_token'=>GE_WTP_Deco_Drive::access_token($user),'developer_key'=>$c['api_key'],'app_id'=>$c['app_id']);}
            if(substr($route,-11)==='/disconnect'){GE_WTP_Deco_Drive::disconnect($user);return array('disconnected'=>true);}
            if(substr($route,-13)==='/import-drive')return array('id'=>self::import_drive($user,$data));
            if(substr($route,-13)==='/from-library')return array('id'=>self::from_library($user,$data));
            if(substr($route,-5)==='/work')return self::edit_work($user,$data);
            return self::save_profile($user,$data);
        }catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'No pudimos completar la acción. Revisá los datos o volvé a conectar Drive.',array('status'=>409));}finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
    }
    public static function review_request($request){$lock=null;try{$d=$request->get_json_params();$lock=self::mutation_lock((int)get_post_meta(absint($d['id']??0),'_ge_artwork_customer_id',true));return array('status'=>self::approve(absint($d['id']??0),$d['revision']??0,$d['decision']??''));}catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'La revisión cambió.',array('status'=>409));}finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}}
    public static function public_request($request) {
        try{
            $row=self::resolve($request['id']);$folder=self::folder($request['id']);
            if($request['action']==='preview'&&$request->get_method()==='GET'){$path=$folder.'/preview.jpg';if(!is_file($path)||is_link($path))throw new InvalidArgumentException('preview_unavailable');nocache_headers();header('Content-Type: image/jpeg');header('X-Content-Type-Options: nosniff');readfile($path);exit;}
            if($request->get_method()==='POST'){
                if(!in_array($request['action'],array('prepare','selection'),true)||GE_WTP_Deco_Gallery::write_permission($request)!==true)throw new InvalidArgumentException('csrf');
                if($request['action']==='selection')return GE_WTP_Deco_Artist_Commerce::selection($request['id'],$request->get_json_params());
                $row=self::resolve($request['id'],false); // Public exploration only; private drafts retain owner-only thumbnails.
                return self::prepare($row);
            }
            return self::status($row);
        }catch(InvalidArgumentException $e){return new WP_Error($e->getMessage(),'Esta obra no está disponible en este momento.',array('status'=>409));}
    }
    public static function prepare($row) {
        $ready=self::status($row);if($ready['status']==='ready')return $ready;if(in_array($ready['status'],array('queued','analyzing'),true))return $ready;
        $key='ge_deco_creator_prepare_'.hash('sha256',$_SERVER['REMOTE_ADDR']??'cli');$count=(int)get_transient($key);if($count>=6)throw new InvalidArgumentException('rate_limit');set_transient($key,$count+1,60);
        $folder=self::folder($row['work_id']);$queue=GE_WTP_Deco_Gallery::root().'/.creator-queue';wp_mkdir_p($queue);$lock=fopen($queue.'/queue.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new InvalidArgumentException('busy');
        try{$path=$queue.'/'.$row['work_id'].'.json';if(!is_file($path)&&count(glob($queue.'/autor-*.json'))>=20)throw new InvalidArgumentException('busy');if(!is_file($path)){$part=$path.'.'.bin2hex(random_bytes(6)).'.part';if(file_put_contents($part,wp_json_encode(array('id'=>$row['work_id'],'owner_id'=>$row['owner_id'],'queued_at'=>time())),LOCK_EX)===false)throw new InvalidArgumentException('busy');chmod($part,0600);if(!rename($part,$path))throw new InvalidArgumentException('busy');}update_option('ge_deco_creator_job_'.$row['work_id'],array('status'=>'queued','queued_at'=>time()),false);}finally{flock($lock,LOCK_UN);fclose($lock);}
        return array('status'=>'queued','stage'=>'Preparando los tamaños disponibles');
    }
    public static function status($row) {
        $id=$row['work_id'];$cached=get_option('ge_deco_creator_cache_'.$id,array());
        if($cached){$analysis=GE_WTP_File_Analysis::from_ref($cached['analysis_ref']);$path=GE_WTP_VPS_Storage::download_path($cached['original']['relative_path']);if($analysis&&$path&&in_array($analysis['status'],array('analyzed','warning'),true)&&empty($analysis['facts']['blockers'])&&hash_file('sha256',$path)===$analysis['facts']['checksum_sha256']){if($row['source']['provider']==='drive'&&($cached['prepared_at']??0)<time()-900)return array('status'=>'pending');$work=self::public_work($row);$pixels=$analysis['facts']['pixel_dimensions'];if(in_array($analysis['facts']['metadata']['exif_orientation']??1,array(5,6,7,8),true))$pixels=array_reverse($pixels);$work['pixels']=$pixels;$work['verified']=true;$work['artist_sales_enabled']=false;try{$work=array_merge($work,GE_WTP_Deco_Artist_Commerce::public_prices($row));}catch(InvalidArgumentException $e){}return array('status'=>'ready','work'=>$work);}}
        if(!empty($analysis)&&(!empty($analysis['facts']['blockers'])||in_array($analysis['status'],array('failed','error'),true)))return array('status'=>'failed');
        $job=get_option('ge_deco_creator_job_'.$id,array());if($job&&($job['queued_at']??0)<time()-600)$job=array('status'=>'failed');return $job?:array('status'=>'pending');
    }
    public static function worker() {
        if(PHP_SAPI!=='cli')throw new InvalidArgumentException('cli_only');$queue=GE_WTP_Deco_Gallery::root().'/.creator-queue';wp_mkdir_p($queue);$lock=fopen($queue.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return 0;$count=0;
        try{foreach(array_slice(glob($queue.'/autor-*.json'),0,2) as $job){$input=json_decode(file_get_contents($job),true);$id=$input['id']??'';try{
            if(is_link($job)||basename($job)!==$id.'.json')throw new InvalidArgumentException('invalid_job');$row=self::resolve($id,false);if($row['owner_id']!==($input['owner_id']??null))throw new InvalidArgumentException('invalid_job');$folder=self::folder($id);list($artwork,$revision)=self::parse_id($id);$source=$row['source'];
            if($source['provider']==='drive'){
                $meta=GE_WTP_Deco_Drive::metadata($row['owner_id'],$source['file_id']);if((string)$meta['version']!==$source['version']||$meta['md5Checksum']!==$source['md5'])throw new InvalidArgumentException('drive_source_changed');
                $name=$source['mime']==='image/png'?'original.png':'original.jpg';$path=$folder.'/'.$name;$cached=get_option('ge_deco_creator_cache_'.$id,array());$reuse=$cached?GE_WTP_VPS_Storage::download_path($cached['original']['relative_path']??''):'';if($reuse&&hash_file('md5',$reuse)===$source['md5'])$path=$reuse;
                if(!is_file($path))self::storage_budget((int)$row['owner_id'],(int)$meta['size']);if(!is_file($path))GE_WTP_Deco_Drive::download($row['owner_id'],$meta,$path);
                if(hash_file('md5',$path)!==$source['md5'])throw new InvalidArgumentException('original_checksum_mismatch');
                $original=($reuse&&$path===$reuse)?$cached['original']:array('provider'=>'vps','relative_path'=>'orders/0/'.$artwork.'/creator-v'.$revision.'/'.$name,'name'=>$row['title'].($source['mime']==='image/png'?'.png':'.jpg'),'size'=>filesize($path),'mime'=>$source['mime']);$analysis=GE_WTP_File_Analysis::ingest($path,$source['mime']);$original['file_analysis_ref']=$analysis['file_analysis_ref'];
            }else{$original=$source['original'];$path=GE_WTP_VPS_Storage::download_path($original['relative_path']);if(!$path||hash_file('sha256',$path)!==$source['sha256'])throw new InvalidArgumentException('original_checksum_mismatch');}
            $current=get_post_meta($artwork,self::META,true);if($source['provider']==='drive'&&($current['revision']??0)===$revision){update_post_meta($artwork,'_ge_artwork_original',$original);update_post_meta($artwork,'_ge_artwork_original_name',$original['name']);update_post_meta($artwork,'_ge_artwork_storage_provider','vps');}
            update_option('ge_deco_creator_cache_'.$id,array('original'=>$original,'analysis_ref'=>$original['file_analysis_ref'],'prepared_at'=>time()),false);update_option('ge_deco_creator_job_'.$id,array('status'=>'analyzing','queued_at'=>time(),'stage'=>'Analizando la calidad de la obra'),false);
        }catch(InvalidArgumentException $e){update_option('ge_deco_creator_job_'.$id,array('status'=>'failed','error'=>$e->getMessage(),'queued_at'=>time()),false);}unlink($job);$count++;}}finally{flock($lock,LOCK_UN);fclose($lock);}return $count;
    }
    public static function assets() {
        if(!self::enabled()||(!is_page('cliente-markcom')&&!is_page('gestion')&&!(function_exists('is_account_page')&&is_account_page())))return;
        wp_enqueue_style('ge-deco-creators',GE_WTP_PLUGIN_URL.'assets/deco/creators.css',array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/deco/creators.css'));
        wp_enqueue_script('ge-deco-creators',GE_WTP_PLUGIN_URL.'assets/deco/creators.js',array(),(string)filemtime(GE_WTP_PLUGIN_DIR.'assets/deco/creators.js'),true);
        wp_localize_script('ge-deco-creators','GE_CREATOR_PORTAL',array('api'=>rest_url(GE_WTP_Deco_Gallery::NS),'nonce'=>wp_create_nonce('wp_rest'),'csrf'=>wp_create_nonce('ge_deco_public'),'preview'=>GE_WTP_Portal::is_staff_preview()));
    }
    public static function render_review() {
        if(!current_user_can('manage_woocommerce')||GE_WTP_Portal::is_staff_preview())return;
        GE_WTP_Deco_Artist_Commerce::render_fee_review();
        $posts=get_posts(array('post_type'=>GE_WTP_Artwork_Library::POST_TYPE,'post_status'=>'publish','posts_per_page'=>200,'meta_key'=>self::META));
        echo '<section class="ge-panel ge-creator" id="gc-review"><h2>Galerías · obras pendientes</h2><p>Revisá la imagen, autoría y presentación antes de hacerla pública. Aprobar la publicación no habilita pagos para artistas.</p><p id="gc-review-status" role="status"></p><div class="gc-grid">';$count=0;
        foreach($posts as $post){$row=get_post_meta($post->ID,self::META,true);if(($row['status']??'')!=='review')continue;$count++;$owner=(int)get_post_meta($post->ID,'_ge_artwork_customer_id',true);$profile=self::profile($owner);$id='autor-'.$post->ID.'-v'.$row['revision'];
            $url=add_query_arg('_wpnonce',wp_create_nonce('wp_rest'),rest_url(GE_WTP_Deco_Gallery::NS.'/creator-work/'.$id.'/preview'));
            echo '<article class="gc-card"><div class="gc-card-image"><img src="'.esc_url($url).'" alt="'.esc_attr($row['title']).'"></div><div class="gc-card-body"><h3>'.esc_html($row['title']).'</h3><p>'.esc_html($profile['title']).' · '.esc_html($row['source']['provider']).'</p><p>'.esc_html($row['description']).'</p><div class="gc-card-actions"><button data-gc-review="approve" data-id="'.esc_attr($post->ID).'" data-revision="'.esc_attr($row['revision']).'">Publicar obra</button><button data-gc-review="reject" data-id="'.esc_attr($post->ID).'" data-revision="'.esc_attr($row['revision']).'">Pedir corrección</button></div></div></article>';
        }echo '</div>'.(!$count?'<p>No hay obras esperando revisión.</p>':'').'</section>';
    }
    public static function render() {
        if(!self::enabled())return;
        if(GE_WTP_Portal::is_staff_preview()){echo '<section class="ge-panel"><h2>Mi galería</h2><p>La gestión personal de archivos está protegida durante la vista previa.</p></section>';return;}
        try{self::require_owner();}catch(InvalidArgumentException $e){echo '<p>Ingresá con tu cuenta de cliente para gestionar tu galería.</p>';return;}
        include GE_WTP_PLUGIN_DIR.'templates/deco-creator-portal.php';
    }
}
