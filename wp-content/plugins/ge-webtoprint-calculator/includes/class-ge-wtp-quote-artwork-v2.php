<?php
defined( 'ABSPATH' ) || exit;

/** Small authenticated requests; immutable private bytes; quote saves contain references only. */
final class GE_WTP_Quote_Artwork_V2 {
    const MAX_FILE = 262144000; // 250 MiB; matches the global analyzer.
    const CHUNK = 8388608;
    const PREFIX = 'ge_qav2_';

    public static function init() {
        add_action( 'wp_ajax_ge_quote_artwork_v2', array( __CLASS__, 'ajax' ) );
    }
    public static function uuid( $value ) {
        return is_string( $value ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value );
    }
    public static function line_id( $quote_id, $index, $line ) {
        if ( self::uuid( $line['line_uuid'] ?? '' ) ) { return $line['line_uuid']; }
        // Deterministic read fallback. Persisted when that legacy quote is next saved.
        $h = hash( 'sha256', 'graph-quote-line:' . $quote_id . ':' . $index );
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }
    public static function limits() {
        return array( 'maxFile' => self::MAX_FILE, 'chunkSize' => min( self::CHUNK, max( 0, self::ini_bytes( ini_get('post_max_size') ) - 1048576 ), self::ini_bytes( ini_get('upload_max_filesize') ) ), 'postMax' => self::ini_bytes( ini_get('post_max_size') ) );
    }
    public static function ini_bytes( $value ) {
        $value = trim((string)$value); $n = (int)$value; $unit = strtolower(substr($value,-1));
        return $n * ( 'g' === $unit ? 1073741824 : ( 'm' === $unit ? 1048576 : ( 'k' === $unit ? 1024 : 1 ) ) );
    }
    public static function enqueue( $quote_id = 0 ) {
        wp_enqueue_script('ge-commercial-quotes',GE_WTP_PLUGIN_URL.'assets/js/commercial-quotes.js',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/js/commercial-quotes.js'),true);
        wp_enqueue_script('ge-quote-artwork-v2',GE_WTP_PLUGIN_URL.'assets/js/quote-artwork-v2.js',array('ge-commercial-quotes'),filemtime(GE_WTP_PLUGIN_DIR.'assets/js/quote-artwork-v2.js'),true);
        wp_enqueue_style('ge-quote-artwork-v2',GE_WTP_PLUGIN_URL.'assets/css/quote-artwork-v2.css',array(),filemtime(GE_WTP_PLUGIN_DIR.'assets/css/quote-artwork-v2.css'));
        wp_localize_script('ge-quote-artwork-v2','geQuoteArtworkV2',array_merge(self::limits(),array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ge_quote_artwork_v2'),'quoteId'=>(int)$quote_id)));
    }
    public static function block( $index, $line_id, $files = array(), $quote_id = 0 ) {
        $general = 'general' === $index;
        $field = $general ? 'general_files[]' : 'lines['.$index.'][artwork_refs][]';
        echo '<div class="ge-qav2" data-ge-artwork data-field="'.esc_attr($field).'"><strong>'.($general?'Archivos generales':'Archivos / Arte').'</strong><p class="ge-manual-help">Varios archivos · hasta 250 MiB cada uno. Se suben por separado.</p><label class="ge-qav2-drop">Seleccionar archivos o arrastrarlos aquí<input type="file" multiple data-ge-artwork-select accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.ai,.eps,.psd,.zip"></label><label>Estado de los nuevos archivos<select data-ge-artwork-source><option value="preliminary">Preliminar</option><option value="final">Final entregado</option></select></label><ul data-ge-artwork-list>';
        foreach ($files as $file) {
            echo '<li data-ge-existing-file><a target="_blank" rel="noopener" href="'.esc_url(GE_WTP_Commercial_Quote_Files::download_url($quote_id,$file['id'],true)).'">'.esc_html($file['name']).'</a><small>'.esc_html(size_format($file['size'])).' · Archivo existente</small><input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr($file['id']).'"><button type="button" data-ge-artwork-remove>Quitar vínculo</button></li>';
        }
        echo '</ul><p data-ge-artwork-notice role="status" aria-live="polite"></p></div>';
    }
    private static function fail( $message, $code = 422 ) { wp_send_json_error(array('message'=>$message),$code); }
    private static function key( $id ) { return self::PREFIX . get_current_user_id() . '_' . $id; }
    private static function allowed() {
        return array('pdf'=>array('application/pdf'),'jpg'=>array('image/jpeg'),'jpeg'=>array('image/jpeg'),'png'=>array('image/png'),'tif'=>array('image/tiff'),'tiff'=>array('image/tiff'),'ai'=>array('application/pdf','application/postscript'),'eps'=>array('application/postscript'),'psd'=>array('image/vnd.adobe.photoshop','image/x-photoshop'),'zip'=>array('application/zip','application/x-zip','application/x-zip-compressed'));
    }
    private static function context( $quote_id ) {
        if ( ! is_user_logged_in() || ! GE_WTP_Staff_Portal::can_access() || GE_WTP_Portal::is_staff_preview() ) { self::fail('Acceso denegado.',403); }
        if ($quote_id) {
            $q=GE_WTP_Commercial_Quotes::get($quote_id,get_current_user_id());
            if(is_wp_error($q)||!in_array($q['status'],array('draft','sent','viewed'),true)||$q['converted_order_id']||get_post_meta($quote_id,'_ge_commercial_initial_payment_order',true)){self::fail('Abrí el pedido vinculado o un presupuesto editable.',409);}
        }
    }
    public static function ajax() {
        foreach(array('quote_id','nonce','upload_id','session_id','op','name','size','offset','source_type')as$field){if(isset($_POST[$field])&&!is_scalar($_POST[$field])){self::fail('Solicitud de archivo inválida.');}}
        $quote_id=absint($_POST['quote_id']??0); self::context($quote_id);
        if(!check_ajax_referer('ge_quote_artwork_v2','nonce',false)){self::fail('La sesión venció. Volvé a abrir el presupuesto.',403);}
        $id=sanitize_text_field(wp_unslash($_POST['upload_id']??''));$session=sanitize_text_field(wp_unslash($_POST['session_id']??''));
        if(!self::uuid($id)||!self::uuid($session)){self::fail('Solicitud de archivo inválida.');}
        if(!GE_WTP_Documents::ensure_private_directory()){self::fail('El almacenamiento privado no está disponible.',503);}
        $root=trailingslashit(GE_WTP_Documents::private_directory()); $stem='qav2-'.get_current_user_id().'-'.$id; $lock=fopen($root.$stem.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){self::fail('Este archivo se está procesando. Reintentá en unos segundos.',409);}
        @chmod($root.$stem.'.lock',0600);
        try {
            $key=self::key($id);$row=get_option($key);$op=sanitize_key($_POST['op']??'');
            if($row&&($row['session_id']!==$session||(int)$row['quote_id']!==$quote_id)){self::fail('El archivo pertenece a otra sesión.',403);}
            if('start'===$op){
                $size=absint($_POST['size']??0);$name=sanitize_file_name(wp_basename(wp_unslash($_POST['name']??'')));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
                if(!$size||$size>self::MAX_FILE){self::fail('El archivo supera el límite de 250 MiB o está vacío.');}
                if(!isset(self::allowed()[$ext])){self::fail('Formato de archivo no permitido.');}
                if($row){if($row['name']!==$name||(int)$row['size']!==$size){self::fail('El reintento no coincide con el archivo original.',409);}}
                else {
                    $rate_key=self::PREFIX.'rate_'.get_current_user_id();$rate=(int)get_transient($rate_key);
                    if($rate>=60){self::fail('Llegaste al límite de cargas por hora. Reintentá más tarde.',429);}set_transient($rate_key,$rate+1,HOUR_IN_SECONDS);
                    global $wpdb;
                    $rows=$wpdb->get_col($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(self::PREFIX.get_current_user_id().'_').'%'));
                    $reserved=0;foreach($rows as $value){$r=maybe_unserialize($value);if(is_array($r)&&isset($r['created_at'],$r['size'])&&$r['created_at']>time()-DAY_IN_SECONDS){$reserved+=(int)$r['size'];}}
                    if($reserved+$size>2*1073741824 || disk_free_space($root)<$size+2*1073741824){self::fail('No hay capacidad disponible para esta carga. Contactá al equipo.',507);}
                    $row=array('id'=>$id,'session_id'=>$session,'quote_id'=>$quote_id,'actor_id'=>get_current_user_id(),'name'=>$name,'size'=>$size,'extension'=>$ext,'source_type'=>'final'===($_POST['source_type']??'')?'final':'preliminary','created_at'=>time(),'status'=>'uploading');
                    if(!add_option($key,$row,'',false)){self::fail('No se pudo iniciar la carga.',409);}
                }
                clearstatcache();$offset=is_file($root.$stem.'.part')?filesize($root.$stem.'.part'):0;
                wp_send_json_success(array('offset'=>$offset,'record'=>isset($row['record'])?self::public_record($row['record']):null));
            }
            if(!$row||$row['created_at']<time()-DAY_IN_SECONDS){self::fail('La carga venció. Seleccioná nuevamente el archivo.',410);}
            if('ready'===$row['status']){wp_send_json_success(array('offset'=>$row['size'],'record'=>self::public_record($row['record'])));}
            if('chunk'===$op){
                $f=$_FILES['chunk']??array();$offset=filter_var($_POST['offset']??null,FILTER_VALIDATE_INT);
                $limit=self::limits()['chunkSize'];
                if(false===$offset||$offset<0||empty($f['tmp_name'])||!is_string($f['tmp_name'])||UPLOAD_ERR_OK!==($f['error']??-1)||!is_uploaded_file($f['tmp_name'])||$f['size']<1||$f['size']>$limit||$offset+$f['size']>$row['size']){self::fail('Bloque de archivo inválido. Reintentá la carga.');}
                $part=$root.$stem.'.part';$out=fopen($part,'c+b');@chmod($part,0600);$stat=fstat($out);$current=$stat['size'];
                if($offset!==$current){
                    if($offset+$f['size']<=$current){fseek($out,$offset);$prior=fread($out,$f['size']);fclose($out);if(hash_equals(hash('sha256',$prior),hash_file('sha256',$f['tmp_name']))){wp_send_json_success(array('offset'=>$current));}}
                    else{fclose($out);}self::fail('La posición del archivo cambió. Usá Reintentar.',409);
                }
                fseek($out,0,SEEK_END);$in=fopen($f['tmp_name'],'rb');$written=stream_copy_to_stream($in,$out);fclose($in);fflush($out);fclose($out);
                if($written!==$f['size']){$repair=fopen($part,'c+b');ftruncate($repair,$current);fclose($repair);self::fail('No se pudo guardar el bloque completo. Reintentá.',503);}
                wp_send_json_success(array('offset'=>$current+$written));
            }
            if('finish'!==$op){self::fail('Operación inválida.');}
            $part=$root.$stem.'.part';clearstatcache();
            $stored=$stem.'.'.$row['extension'];$path=$root.$stored;
            if(!is_file($part)&&is_file($path)){$part=$path;} // Recover interrupted finalization.
            if(!is_file($part)||filesize($part)!==$row['size']){self::fail('La carga está incompleta. Usá Reintentar.',409);}
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($part);$allowed=self::allowed()[$row['extension']];
            if(!in_array($mime,$allowed,true)){self::fail('El contenido no coincide con el formato de archivo.');}
            if($part!==$path&&!rename($part,$path)){self::fail('No se pudo finalizar la carga.',503);}@chmod($path,0600);
            $record=array('id'=>$id,'version_id'=>$id,'artifact_id'=>$id,'stored_name'=>$stored,'name'=>$row['name'],'mime'=>$mime,'size'=>$row['size'],'category'=>'arte','role'=>'artwork','source_type'=>$row['source_type'],'uploaded_by'=>$row['actor_id'],'uploaded_at'=>gmdate('c'),'status'=>'uploaded','analysis'=>array('sha256'=>hash_file('sha256',$path)));
            if(class_exists('GE_WTP_File_Analysis')){$record=GE_WTP_File_Analysis::record($record,$root);}
            else{$record['analysis_status']='pending';do_action('ge_quote_artwork_analysis_pending',$record);}
            $record['checksum_sha256']=$record['analysis']['sha256'];$row['record']=$record;$row['status']='ready';update_option($key,$row,false);
            wp_send_json_success(array('offset'=>$row['size'],'record'=>self::public_record($record)));
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function public_record($record){return array_intersect_key($record,array_flip(array('id','name','size','version_id','analysis_status','file_analysis_ref')));}

    /** Validate every reference BEFORE saving any customer or quote. Keep historic bytes. */
    public static function prepare( $existing, $lines, $general, $session, $actor ) {
        $files=$existing?GE_WTP_Commercial_Quote_Files::all($existing['id']):array();$known=array_column($files,null,'id');$assignments=array();$seen=array();
        foreach($lines as $line){
            $uuid=$line['line_uuid']??'';
            if(!self::uuid($uuid)||isset($seen[$uuid])){return new WP_Error('ge_artwork_line','Identificador de ítem inválido o duplicado.');}$seen[$uuid]=true;
            foreach((array)($line['artwork_refs']??array())as $id){if(isset($assignments[$id])){return new WP_Error('ge_artwork_duplicate','El archivo ya está asociado a otro ítem.');}$assignments[$id]=$uuid;}
        }
        foreach((array)$general as $id){if(isset($assignments[$id])){return new WP_Error('ge_artwork_duplicate','Revisá la asociación de archivos.');}$assignments[$id]='';}
        if(count($assignments)>30){return new WP_Error('ge_artwork_limit','Podés asociar hasta 30 archivos por presupuesto.');}
        foreach($assignments as $id=>$uuid){
            if(!self::uuid($id)){return new WP_Error('ge_artwork_reference','Referencia de archivo inválida.');}
            if(isset($known[$id])){continue;}
            $row=get_option(self::PREFIX.$actor.'_'.$id);
            if(!$row||'ready'!==$row['status']||$row['session_id']!==$session||(int)$row['quote_id']!==(int)($existing['id']??0)||(!empty($row['claimed_quote_id'])&&(int)$row['claimed_quote_id']!==(int)($existing['id']??0))){return new WP_Error('ge_artwork_reference','Un archivo no terminó de subir o pertenece a otro presupuesto.');}
            $known[$id]=$row['record'];
        }
        foreach($assignments as $id=>$uuid){
            $record=$known[$id];$path=trailingslashit(GE_WTP_Documents::private_directory()).wp_basename($record['stored_name']??'');
            if(empty($record['provider']) && (!is_file($path)||filesize($path)!==(int)$record['size'])){return new WP_Error('ge_artwork_missing','Un original privado no está disponible. Contactá al equipo.');}
        }
        foreach($known as $id=>&$file){
            if(array_key_exists($id,$assignments)){
                $file['quote_item_id']=$assignments[$id];$file['line_uuid']=$assignments[$id];$file['association_status']='active';
            }else{$file['association_status']='detached';}
            // Legacy globals remain global unless the user explicitly selects an item.
        }unset($file);
        return array_values($known);
    }
    public static function commit( $quote_id, $files, $actor ) {
        foreach($files as &$file){$file['quote_id']=$quote_id;$key=self::PREFIX.$actor.'_'.$file['id'];$row=get_option($key);if($row){$row['claimed_quote_id']=$quote_id;update_option($key,$row,false);}}unset($file);
        update_post_meta($quote_id,GE_WTP_Commercial_Quote_Files::META,$files);
        GE_WTP_Commercial_Quotes::record_event($quote_id,'artwork_associations_saved',$actor,array('file_count'=>count($files)));
    }
    public static function save_guard( $session ) {
        if(!self::uuid($session)){wp_die('Sesión de presupuesto inválida. Volvé a abrirlo.','',array('response'=>422));}
        GE_WTP_Documents::ensure_private_directory();
        $key=self::PREFIX.'saved_'.get_current_user_id().'_'.$session;
        $lock=fopen(trailingslashit(GE_WTP_Documents::private_directory()).$key.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){wp_die('El presupuesto se está guardando. Esperá unos segundos y volvé a abrirlo.','',array('response'=>409));}
        $saved=get_option($key);
        if($saved){wp_safe_redirect(GE_WTP_Staff_Portal::portal_url('quotes',array('quote_id'=>absint($saved))));exit;}
        // Resource retained for the PHP request lifetime, including redirects.
        $GLOBALS['ge_qav2_save_lock']=$lock;
        return $key;
    }
}
